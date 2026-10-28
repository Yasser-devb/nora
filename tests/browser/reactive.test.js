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

test('nested reactive children keep independent state and reject stale, forged, and unauthorized actions', async () => {
    const workspace = fs.mkdtempSync(path.join(os.tmpdir(), 'nora-nested-reactive-browser-'));
    let browser;
    let server;
    let serverOutput = '';

    try {
        const autoload = path.join(repositoryRoot, 'vendor', 'autoload.php').replace(/\\/g, '/');
        const source = `<?php
require ${JSON.stringify(autoload)};

$child = new class implements \\Nora\\Reactive\\ReactiveNestedComponentInterface {
    public function propsRules(): array {
        return ['label' => 'required|string|max:16', 'allowed' => 'required|boolean'];
    }
    public function initialState(array $props): array {
        return ['count' => 0, 'allowed' => $props['allowed']];
    }
    public function render(array $state, array $props): string {
        $label = htmlspecialchars($props['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<output data-label="' . $label . '">' . $state['count'] . '</output>'
            . '<button data-nora-action="increment" data-nora-args=\\'{"amount":1}\\'>Increment</button>';
    }
    public function actions(): array {
        return ['increment' => new \\Nora\\Reactive\\ReactiveAction(
            ['amount' => 'required|integer|min:1|max:1'],
            static fn (array $state): array => [...$state, 'count' => $state['count'] + 1],
            static fn (array $state): bool => $state['allowed'],
        )];
    }
    public function children(array $state, array $props): array { return []; }
};

$parent = new class implements \\Nora\\Reactive\\ReactiveComponent, \\Nora\\Reactive\\ReactiveParentComponentInterface {
    public function initialState(): array { return []; }
    public function render(array $state): string {
        $name = htmlspecialchars($state['name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<form data-nora-form data-nora-action="save" data-nora-success="Profile saved" method="post" action="/profile">'
            . '<label>Name <input name="name" data-nora-field="name"></label>'
            . '<label>Email <input name="email" data-nora-field="email"></label>'
            . '<label>Password <input type="password" name="password" data-nora-field="password"></label>'
            . '<button type="submit">Save</button></form>'
            . '<output data-saved-name>' . $name . '</output>'
            . \\Nora\\Reactive\\ReactiveChildDefinition::placeholder('alpha')
            . \\Nora\\Reactive\\ReactiveChildDefinition::placeholder('beta');
    }
    public function actions(): array {
        return ['save' => \\Nora\\Reactive\\ReactiveAction::publicAction(
            [
                'name' => 'required|string|max:40',
                'email' => 'required|email',
                'password' => 'required|string|min:8',
            ],
            static fn (array $state, array $arguments): array => [
                ...$state,
                'name' => $arguments['name'],
                'email' => $arguments['email'],
                'password_set' => true,
            ],
        )];
    }
    public function children(array $state): array {
        return [
            new \\Nora\\Reactive\\ReactiveChildDefinition('alpha', 'child', ['label' => 'alpha', 'allowed' => true]),
            new \\Nora\\Reactive\\ReactiveChildDefinition('beta', 'child', ['label' => 'beta', 'allowed' => false]),
        ];
    }
};

$session = new \\Nora\\Security\\SessionManager(false);
$engine = new \\Nora\\Reactive\\ReactiveEngine($session);
$engine->register('page', $parent)->register('child', $child);
$app = new \\Nora\\Core\\Application();
$engine->mount($app, 'page', '/');
$app->run();
`;
        fs.writeFileSync(path.join(workspace, 'index.php'), source);
        fs.writeFileSync(
            path.join(workspace, 'router.php'),
            "<?php\nrequire __DIR__ . '/index.php';\n",
        );
        const port = await availablePort();
        server = spawn(
            process.env.PHP_BINARY || 'php',
            ['-S', `127.0.0.1:${port}`, '-t', workspace, 'router.php'],
            { cwd: workspace, stdio: ['ignore', 'pipe', 'pipe'] }
        );
        server.stdout.setEncoding('utf8').on('data', chunk => { serverOutput += chunk; });
        server.stderr.setEncoding('utf8').on('data', chunk => { serverOutput += chunk; });
        const url = `http://127.0.0.1:${port}/`;
        await waitForServer(url, server, () => serverOutput);

        browser = await chromium.launch({ headless: true });
        const page = await browser.newPage();
        const observedRequests = [];
        const actionStatuses = [];
        page.on('request', request => observedRequests.push(request.url()));
        page.on('response', resource => {
            if (resource.url().endsWith('/_nora/action')) {
                actionStatuses.push(resource.status());
            }
        });
        const runtimeResponses = [];
        page.on('response', resource => {
            if (resource.url().endsWith('/_nora/runtime.js')) {
                runtimeResponses.push(resource.status());
            }
        });
        page.on('console', message => {
            if (message.type() === 'error') {
                serverOutput += `\nBrowser console: ${message.text()}`;
            }
        });
        const response = await page.goto(url, { waitUntil: 'networkidle' });
        assert.equal(response.status(), 200);
        assert.ok(observedRequests.some(request => request.endsWith('/_nora/runtime.js')), serverOutput);
        assert.deepEqual(runtimeResponses, [200]);
        const alpha = page.locator('[data-nora-child-key="alpha"]');
        const beta = page.locator('[data-nora-child-key="beta"]');
        const alphaIdentity = await alpha.getAttribute('data-nora-child-id');
        const betaIdentity = await beta.getAttribute('data-nora-child-id');
        assert.match(alphaIdentity, /^[a-f0-9]{48}$/);
        assert.match(betaIdentity, /^[a-f0-9]{48}$/);
        assert.notEqual(alphaIdentity, betaIdentity);

        const firstAction = page.waitForResponse(
            response => response.url().endsWith('/_nora/action'),
            { timeout: 5000 },
        ).catch(async error => {
            throw new Error(
                `${error.message}; observed requests=${JSON.stringify(observedRequests)}; `
                + `button=${await alpha.locator('button').getAttribute('data-nora-action')}; `
                + `status=${await page.locator('[data-nora-status]').textContent()}; `
                + `page=${await page.content()}; logs=${serverOutput}`,
            );
        });
        await alpha.getByRole('button', { name: 'Increment' }).click();
        const firstActionResponse = await firstAction;
        assert.equal(firstActionResponse.status(), 200, await firstActionResponse.text());
        await page.waitForFunction(() =>
            document.querySelector('[data-label="alpha"]').textContent === '1'
        );
        assert.equal(await page.locator('[data-label="beta"]').textContent(), '0');
        assert.equal(await alpha.getAttribute('data-nora-child-id'), alphaIdentity);

        const pageId = await page.locator('[data-nora-root]').getAttribute('data-nora-page');
        const csrf = await page.locator('meta[name="nora-csrf"]').getAttribute('content');
        const staleStatus = await page.evaluate(async ({ pageId, csrf, alphaIdentity }) => {
            const response = await fetch('/_nora/action', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({
                    page: pageId,
                    action: 'increment',
                    revision: 0,
                    arguments: { amount: 1 },
                    component: alphaIdentity
                })
            });
            return response.status;
        }, { pageId, csrf, alphaIdentity });
        assert.equal(staleStatus, 409);

        const forgedResponse = page.waitForResponse(resource => resource.url().endsWith('/_nora/action'));
        await alpha.evaluate(element => {
            element.dataset.noraChildId = 'f'.repeat(48);
            element.querySelector('button').click();
        });
        assert.equal((await forgedResponse).status(), 404);
        assert.equal(await page.locator('[data-label="alpha"]').textContent(), '1');
        assert.equal(await page.locator('[data-label="beta"]').textContent(), '0');

        await page.locator('[data-nora-status]').evaluate(status => { status.textContent = ''; });
        const unauthorizedResponse = page.waitForResponse(resource => resource.url().endsWith('/_nora/action'));
        await beta.getByRole('button', { name: 'Increment' }).click();
        assert.equal((await unauthorizedResponse).status(), 403);
        assert.equal(await page.locator('[data-label="beta"]').textContent(), '0');
        assert.deepEqual(actionStatuses, [200, 409, 404, 403]);
        assert.equal(await beta.getAttribute('data-nora-child-id'), betaIdentity);

        let holdNextAction = false;
        let failNextAction = false;
        await page.route('**/_nora/action', async route => {
            if (failNextAction) {
                failNextAction = false;
                await route.abort();
                return;
            }
            if (holdNextAction) {
                holdNextAction = false;
                await delay(150);
            }
            await route.continue();
        });
        const form = page.locator('form[data-nora-form]');
        await form.locator('[name="name"]').fill('Amina');
        await form.locator('[name="email"]').fill('not-an-email');
        await form.locator('[name="password"]').fill('private-passphrase');
        holdNextAction = true;
        const invalidFormResponse = page.waitForResponse(resource => resource.url().endsWith('/_nora/action'));
        await form.locator('[name="password"]').press('Enter');
        await page.waitForFunction(() => {
            const form = document.querySelector('form[data-nora-form]');
            return form?.dataset.noraState === 'pending' && form.hasAttribute('aria-busy');
        });
        const invalidResponse = await invalidFormResponse;
        assert.equal(invalidResponse.status(), 422);
        const invalidBody = await invalidResponse.text();
        assert.equal(invalidBody.includes('private-passphrase'), false);
        assert.equal(await form.locator('[name="email"]').getAttribute('aria-invalid'), 'true', invalidBody);
        assert.notEqual(await form.locator('[data-nora-form-summary]').count(), 0);
        assert.equal(await form.evaluate(element => element.dataset.noraState), 'invalid');
        assert.equal(await page.evaluate(() => document.activeElement?.getAttribute('name')), 'email');

        await form.locator('[name="email"]').fill('amina@example.test');
        const validFormResponse = page.waitForResponse(resource => resource.url().endsWith('/_nora/action'));
        await form.locator('[name="email"]').press('Enter');
        const validResponse = await validFormResponse;
        assert.equal(validResponse.status(), 200);
        const validBody = await validResponse.text();
        assert.equal(validBody.includes('private-passphrase'), false);
        await page.waitForFunction(() => document.querySelector('[data-saved-name]').textContent === 'Amina');
        assert.equal(await page.locator('[name="password"]').inputValue(), 'private-passphrase');
        assert.equal(await page.locator('[name="email"]').getAttribute('aria-invalid'), null);
        assert.equal(await page.locator('form[data-nora-form]').evaluate(element => element.dataset.noraState), 'success');
        assert.equal(await page.locator('[data-nora-status]').textContent(), 'Profile saved');
        assert.equal(await page.evaluate(() => document.activeElement?.getAttribute('name')), 'email');

        await form.evaluate(element => {
            const forged = document.createElement('input');
            forged.type = 'hidden';
            forged.name = 'is_admin';
            forged.dataset.noraField = 'is_admin';
            element.append(forged);
        });
        const unknownFieldResponse = page.waitForResponse(resource => resource.url().endsWith('/_nora/action'));
        await form.locator('[name="email"]').press('Enter');
        const unknownResponse = await unknownFieldResponse;
        assert.equal(unknownResponse.status(), 422);
        assert.equal((await unknownResponse.text()).includes('is_admin'), false);
        assert.equal(await page.locator('[data-saved-name]').textContent(), 'Amina');
        await form.locator('[name="is_admin"]').evaluate(field => field.remove());

        failNextAction = true;
        const failedFormRequest = page.waitForRequest(resource => resource.url().endsWith('/_nora/action'));
        await form.locator('[name="email"]').press('Enter');
        await failedFormRequest;
        await page.waitForFunction(() => document.querySelector('form[data-nora-form]')?.dataset.noraState === 'failed');
        assert.match(await page.locator('[data-nora-status]').textContent(), /connection failed/i);
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
