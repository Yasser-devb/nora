'use strict';

const assert = require('node:assert/strict');
const { spawn, execFileSync } = require('node:child_process');
const fs = require('node:fs');
const net = require('node:net');
const os = require('node:os');
const path = require('node:path');
const { setTimeout: delay } = require('node:timers/promises');
const test = require('node:test');
const { chromium } = require('playwright');

const repositoryRoot = path.resolve(__dirname, '../..');

test('generated app renders and securely updates its reactive counter without navigation', async () => {
    const workspace = fs.mkdtempSync(path.join(os.tmpdir(), 'nora-reactive-browser-'));
    let browser;
    let server;
    let serverOutput = '';

    try {
        execFileSync(
            process.env.PHP_BINARY || 'php',
            [path.join(repositoryRoot, 'bin', 'nora'), 'new', 'reactive-browser-app'],
            { cwd: workspace, stdio: 'pipe' }
        );
        const projectRoot = path.join(workspace, 'reactive-browser-app');
        fs.copyFileSync(path.join(projectRoot, '.env.example'), path.join(projectRoot, '.env'));
        installGeneratedDependencies(projectRoot);

        const port = await availablePort();
        server = spawn(
            process.env.PHP_BINARY || 'php',
            ['-S', `127.0.0.1:${port}`, '-t', 'public', 'public/router.php'],
            { cwd: projectRoot, stdio: ['ignore', 'pipe', 'pipe'] }
        );
        server.stdout.setEncoding('utf8').on('data', chunk => { serverOutput += chunk; });
        server.stderr.setEncoding('utf8').on('data', chunk => { serverOutput += chunk; });
        const url = `http://127.0.0.1:${port}/`;
        await waitForServer(url, server, () => serverOutput);

        browser = await chromium.launch({ headless: true });
        const page = await browser.newPage();
        let mainFrameNavigations = 0;
        const actionRequests = [];
        const actionResponses = [];
        const runtimeResponses = [];
        page.on('framenavigated', frame => {
            if (frame === page.mainFrame()) {
                mainFrameNavigations++;
            }
        });
        page.on('request', request => {
            if (request.url().endsWith('/_nora/action')) {
                actionRequests.push(request.method());
            }
        });
        page.on('response', response => {
            if (response.url().endsWith('/_nora/action')) {
                actionResponses.push(response.status());
            }
            if (response.url().endsWith('/_nora/runtime.js')) {
                runtimeResponses.push(response.status());
            }
        });

        const response = await page.goto(url, { waitUntil: 'networkidle' });
        assert.equal(response.status(), 200);
        assert.equal(await page.locator('[data-nora-root][data-nora-page]').count(), 1);
        assert.deepEqual(await page.locator('h1').allTextContents(), ['Nora reactive counter']);
        assert.deepEqual(await page.locator('h2').allTextContents(), ['Primary counter', 'Coordinated counter']);
        assert.equal(await page.locator('output').count(), 2);
        assert.deepEqual(await page.locator('output').allTextContents(), ['0', '0']);
        assert.equal(await page.locator('script[src="/_nora/runtime.js"]').count(), 1);
        assert.deepEqual(runtimeResponses, [200], 'the framework runtime must load automatically');
        assert.equal(await page.locator('input').inputValue(), 'Keep my input');

        const input = page.locator('input');
        await input.fill('draft survives');
        const primary = page.locator('[data-nora-key="primary"]');
        const primaryHandle = await primary.elementHandle();
        await primary.getByRole('button', { name: 'Increment' }).click();

        await page.waitForFunction(() =>
            Array.from(document.querySelectorAll('output')).every(output => output.textContent === '1')
        );
        assert.equal(mainFrameNavigations, 1, 'the reactive action must not navigate or reload the document');
        assert.deepEqual(actionRequests, ['POST']);
        assert.equal(await input.inputValue(), 'draft survives');
        assert.equal(
            await primary.evaluate((element, original) => element === original, primaryHandle),
            true,
            'keyed component identity must be preserved',
        );
        assert.equal(
            await primary.getByRole('button', { name: 'Increment' }).evaluate(element => element === document.activeElement),
            true,
            'focus should remain on the activated control after its update',
        );

        await input.focus();
        await input.evaluate(element => element.setSelectionRange(2, 8, 'forward'));
        await primary.getByRole('button', { name: 'Increment' }).evaluate(button => button.click());
        await page.waitForFunction(() =>
            Array.from(document.querySelectorAll('output')).every(output => output.textContent === '2')
        );
        assert.equal(await input.evaluate(element => element === document.activeElement), true);
        assert.deepEqual(
            await input.evaluate(element => [
                element.selectionStart,
                element.selectionEnd,
                element.selectionDirection
            ]),
            [2, 8, 'forward'],
            'focused input selection should survive a server-rendered update',
        );

        const button = primary.getByRole('button', { name: 'Increment' });
        await button.evaluate(element => element.setAttribute('data-nora-args', '{"amount":8}'));
        await button.click();
        await page.waitForFunction(() =>
            Array.from(document.querySelectorAll('output')).every(output => output.textContent === '10')
        );

        await button.evaluate(element => element.setAttribute('data-nora-args', '{"amount":1}'));
        await button.click();
        await page.waitForFunction(() => document.querySelector('[data-nora-status]').textContent !== '');
        assert.equal(actionResponses.at(-1), 403, 'the server must reject an unauthorized transition');
        assert.deepEqual(await page.locator('output').allTextContents(), ['10', '10']);
        assert.equal(await page.locator('[data-nora-status]').textContent(), 'The action could not be completed.');

        await button.evaluate(element => element.setAttribute('data-nora-args', '{"amount":11}'));
        await button.click();
        await page.waitForFunction(() =>
            document.querySelector('[data-nora-status]').textContent === 'The reactive action arguments are invalid.'
        );
        assert.equal(actionResponses.at(-1), 422, 'the server must validate action arguments');
        assert.deepEqual(await page.locator('output').allTextContents(), ['10', '10']);

        await button.evaluate(element => {
            element.setAttribute('data-nora-action', 'unregistered');
            element.setAttribute('data-nora-args', '{"amount":1}');
        });
        await button.click();
        await page.waitForFunction(() =>
            document.querySelector('[data-nora-status]').textContent === 'The action could not be completed.'
        );
        assert.equal(actionResponses.at(-1), 404, 'the server must reject an unregistered action name');
        assert.deepEqual(await page.locator('output').allTextContents(), ['10', '10']);
        assert.doesNotMatch(
            await page.locator('[data-nora-root]').innerText(),
            /(?:Fatal error|Stack trace|\/src\/Reactive\/|#\d+\s)/i,
            'server errors must not expose internal diagnostics in the rendered page',
        );
        assert.equal(mainFrameNavigations, 1);
        assert.deepEqual(actionRequests, ['POST', 'POST', 'POST', 'POST', 'POST', 'POST']);
        assert.deepEqual(actionResponses, [200, 200, 200, 403, 422, 404]);
    } finally {
        if (browser) {
            await browser.close();
        }
        if (server && server.exitCode === null) {
            server.kill();
            await Promise.race([
                new Promise(resolve => server.once('exit', resolve)),
                delay(3000)
            ]);
        }
        fs.rmSync(workspace, { recursive: true, force: true });
    }
});

async function availablePort() {
    const listener = net.createServer();
    await new Promise((resolve, reject) => {
        listener.once('error', reject);
        listener.listen(0, '127.0.0.1', resolve);
    });
    const port = listener.address().port;
    await new Promise((resolve, reject) => listener.close(error => error ? reject(error) : resolve()));

    return port;
}

function installGeneratedDependencies(projectRoot) {
    const composer = process.env.COMPOSER_BINARY || 'composer';
    const args = ['install', '--no-interaction', '--prefer-dist'];
    if (process.platform === 'win32') {
        execFileSync(process.env.ComSpec || 'cmd.exe', ['/d', '/c', `${composer} ${args.join(' ')}`], {
            cwd: projectRoot,
            stdio: 'pipe'
        });
        return;
    }

    execFileSync(composer, args, { cwd: projectRoot, stdio: 'pipe' });
}

async function waitForServer(url, server, output) {
    for (let attempt = 0; attempt < 50; attempt++) {
        if (server.exitCode !== null) {
            throw new Error(`PHP development server exited early:\n${output()}`);
        }
        try {
            const response = await fetch(url);
            if (response.status === 200) {
                return;
            }
        } catch (_) {
            await delay(100);
        }
    }
    throw new Error(`PHP development server did not become ready:\n${output()}`);
}
