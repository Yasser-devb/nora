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
const websiteRoot = path.join(repositoryRoot, 'examples', 'nora-website');

test('Nora-built website serves its pages and securely updates the experimental playground', async () => {
    installWebsiteDependencies();

    const port = await availablePort();
    const baseUrl = `http://127.0.0.1:${port}`;
    let serverOutput = '';
    const server = spawn(
        process.env.PHP_BINARY || 'php',
        ['-S', `127.0.0.1:${port}`, '-t', 'public', 'public/router.php'],
        {
            cwd: websiteRoot,
            env: {
                ...process.env,
                APP_BASE_URL: baseUrl,
                APP_DEBUG: 'false',
            },
            stdio: ['ignore', 'pipe', 'pipe'],
        },
    );
    server.stdout.setEncoding('utf8').on('data', chunk => { serverOutput += chunk; });
    server.stderr.setEncoding('utf8').on('data', chunk => { serverOutput += chunk; });

    let browser;
    try {
        await waitForServer(`${baseUrl}/`, server, () => serverOutput);

        const routes = [
            ['/', 'PHP first.'],
            ['/features', 'A framework you can understand.'],
            ['/docs/getting-started', 'Make your first Nora request.'],
            ['/docs', 'Learn Nora by following the code.'],
            ['/architecture', 'Follow the request.'],
            ['/examples', 'Small examples, real boundaries.'],
            ['/roadmap', 'Earn stability one milestone at a time.'],
            ['/changelog', 'Progress you can inspect.'],
            ['/community', 'Open to proposals.'],
            ['/contact', 'Start with the right channel.'],
        ];
        for (const [route, heading] of routes) {
            const response = await fetch(`${baseUrl}${route}`);
            assert.equal(response.status, 200, `${route} should be served`);
            assert.match(await response.text(), new RegExp(escapeRegExp(heading)));
        }
        const testedRoutes = new Set([...routes.map(([route]) => route), '/playground']);
        assert.equal((await fetch(`${baseUrl}/robots.txt`)).status, 200);

        browser = await chromium.launch({ headless: true });
        const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
        const browserErrors = [];
        const externalRequests = [];
        const actionResponses = [];
        const runtimeResponses = [];
        let mainFrameNavigations = 0;
        page.on('pageerror', error => browserErrors.push(error.message));
        page.on('requestfailed', request => browserErrors.push(
            `${request.method()} ${request.url()}: ${request.failure()?.errorText || 'request failed'}`,
        ));
        page.on('framenavigated', frame => {
            if (frame === page.mainFrame()) {
                mainFrameNavigations++;
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
        page.on('request', request => {
            if (new URL(request.url()).origin !== baseUrl) {
                externalRequests.push(request.url());
            }
        });

        const homeResponse = await page.goto(baseUrl, { waitUntil: 'networkidle' });
        assert.equal(homeResponse.headers()['x-frame-options'], 'DENY');
        assert.match(homeResponse.headers()['content-security-policy'], /default-src 'self'/);
        const homeLinks = await page.locator('a[href]').evaluateAll(links => links
            .map(link => {
                const target = new URL(link.href);
                return target.origin === location.origin
                    ? { path: target.pathname, hash: decodeURIComponent(target.hash.slice(1)) }
                    : null;
            })
            .filter(Boolean));
        for (const { path: targetPath, hash } of homeLinks) {
            assert.equal(testedRoutes.has(targetPath), true, `${targetPath} should be a tested page route`);
            if (hash) {
                assert.equal(
                    await page.evaluate(id => document.getElementById(id) !== null, hash),
                    true,
                    `#${hash} should target an element on the homepage`,
                );
            }
        }
        assert.equal(await page.evaluate(() =>
            document.documentElement.scrollWidth <= document.documentElement.clientWidth
        ), true, 'the mobile home page should not overflow horizontally');
        assert.equal(await page.locator('title').textContent(), 'Nora Framework — PHP-first, reactive by design');
        assert.equal(await page.locator('[data-nora-root][data-nora-page]').count(), 1);
        assert.equal(await page.locator('[data-nora-root] #main-content').count(), 1);
        assert.equal(await page.locator('[data-nora-root] main').count(), 0);
        assert.equal(await page.locator('script[src="/_nora/runtime.js"]').count(), 1);
        assert.deepEqual(runtimeResponses, [200], 'the home page must load Nora runtime automatically');
        assert.match(await page.locator('.hero h1').innerText(), /PHP first\.\s+Reactive by design\./);
        assert.equal(await page.locator('.hero-demo').isVisible(), true);
        assert.equal(await page.locator('[data-nora-home-count]').innerText(), '0');
        const homeNavigations = mainFrameNavigations;
        await page.getByRole('button', { name: 'Increment live counter' }).click();
        await page.waitForFunction(() =>
            document.querySelector('[data-nora-home-count]').textContent === '1'
        );
        assert.equal(mainFrameNavigations, homeNavigations, 'the homepage counter must update without navigation');
        await page.getByRole('button', { name: 'Reset' }).click();
        await page.waitForFunction(() =>
            document.querySelector('[data-nora-home-count]').textContent === '0'
        );
        assert.equal(mainFrameNavigations, homeNavigations, 'reset must not reload the homepage');

        const menu = page.locator('details.mobile-menu');
        await menu.locator('summary').click();
        await menu.getByRole('link', { name: 'Features' }).click();
        await page.waitForURL(`${baseUrl}/features`);
        assert.equal(await page.locator('h1').innerText(), 'A framework you can understand.');
        assert.equal(await page.locator('.site-footer').count(), 1);
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto(baseUrl, { waitUntil: 'networkidle' });
        assert.equal(await page.locator('.desktop-nav a[href="/playground"]').isVisible(), true);
        assert.equal(await page.locator('.workflow-grid .workflow-step').count(), 3);
        assert.equal(await page.locator('.capability-row').count(), 6);
        assert.equal(await page.locator('.hero-demo').isVisible(), true);
        for (const width of [960, 900, 768, 621, 620, 390]) {
            await page.setViewportSize({ width, height: 844 });
            assert.equal(
                await page.evaluate(() =>
                    document.documentElement.scrollWidth <= document.documentElement.clientWidth
                ),
                true,
                `the ${width}px homepage should not overflow horizontally`,
            );
            assert.equal(
                await page.locator('.mobile-menu summary').isVisible(),
                true,
                `the ${width}px navigation should use its compact menu`,
            );
        }

        const notFound = await page.goto(`${baseUrl}/not-found/%3Cscript%3Ealert%281%29%3C%2Fscript%3E`);
        assert.equal(notFound.status(), 404);
        assert.equal(await page.locator('h1').innerText(), "We couldn't find that page.");
        assert.equal(await page.locator('.requested-path script').count(), 0);
        assert.doesNotMatch(await page.locator('.error-page').innerHTML(), /<script>alert/);

        const playgroundResponse = await page.goto(`${baseUrl}/playground`, { waitUntil: 'networkidle' });
        const playgroundNavigations = mainFrameNavigations;
        assert.equal(playgroundResponse.status(), 200);
        assert.equal(playgroundResponse.headers()['x-content-type-options'], 'nosniff');
        assert.match(playgroundResponse.headers()['content-security-policy'], /frame-ancestors 'none'/);
        assert.equal(await page.locator('[data-nora-root][data-nora-page]').count(), 1);
        assert.equal(await page.locator('script[src="/_nora/runtime.js"]').count(), 1);
        assert.equal(await page.locator('meta[name="nora-csrf"]').count(), 1);
        assert.deepEqual(await page.locator('output').allTextContents(), ['0', '0']);
        assert.equal(await page.locator('input, textarea, select').count(), 0);

        const increment = page.getByRole('button', { name: 'Increment counter' });
        await increment.click();
        await page.waitForFunction(() =>
            Array.from(document.querySelectorAll('output')).every(output => output.textContent === '1')
        );
        assert.equal(
            await increment.evaluate(element => element === document.activeElement),
            true,
            'the focused control should remain focused after DOM reconciliation',
        );
        assert.equal(mainFrameNavigations, playgroundNavigations, 'reactive actions must not reload the document');

        await page.getByRole('tab', { name: 'Validation & permissions' }).click();
        await page.waitForFunction(() =>
            document.querySelector('#validation-panel').getAttribute('aria-hidden') === 'false'
        );
        await page.getByRole('button', { name: 'Send an invalid amount' }).click();
        await page.waitForFunction(() =>
            document.querySelector('[data-nora-status]').textContent
                === 'The reactive action arguments are invalid.'
        );
        assert.deepEqual(await page.locator('output').allTextContents(), ['1', '1']);
        assert.equal(actionResponses.at(-1), 422);

        await page.getByRole('button', { name: 'Try protected action' }).click();
        await page.waitForFunction(() =>
            document.querySelector('[data-nora-status]').textContent
                === 'The action could not be completed.'
        );
        assert.deepEqual(await page.locator('output').allTextContents(), ['1', '1']);
        assert.equal(actionResponses.at(-1), 403);

        const forgedStatus = await page.evaluate(async () => {
            const root = document.querySelector('[data-nora-root]');
            const token = document.querySelector('meta[name="nora-csrf"]').content;
            const response = await fetch('/_nora/action', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': token,
                },
                body: JSON.stringify({
                    page: root.dataset.noraPage,
                    action: 'unregistered-action',
                    revision: Number(root.dataset.noraRevision),
                    arguments: {},
                }),
            });
            return response.status;
        });
        assert.equal(forgedStatus, 404);
        assert.deepEqual(await page.locator('output').allTextContents(), ['1', '1']);
        assert.equal(mainFrameNavigations, playgroundNavigations);
        assert.deepEqual(actionResponses, [200, 200, 200, 200, 422, 403, 404]);
        assert.deepEqual(browserErrors, [], `browser errors: ${browserErrors.join('; ')}\n${serverOutput}`);
        assert.deepEqual(externalRequests, [], 'the website should not depend on third-party assets');
    } finally {
        if (browser) {
            await browser.close();
        }
        if (server.exitCode === null) {
            server.kill();
            await Promise.race([
                new Promise(resolve => server.once('exit', resolve)),
                delay(3000),
            ]);
        }
    }
});

function installWebsiteDependencies() {
    const composer = process.env.COMPOSER_BINARY || 'composer';
    const args = ['install', '--no-interaction', '--prefer-dist'];
    if (process.platform === 'win32') {
        execFileSync(process.env.ComSpec || 'cmd.exe', ['/d', '/c', `${composer} ${args.join(' ')}`], {
            cwd: websiteRoot,
            stdio: 'pipe',
        });
        return;
    }

    execFileSync(composer, args, { cwd: websiteRoot, stdio: 'pipe' });
}

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

function escapeRegExp(value) {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}
