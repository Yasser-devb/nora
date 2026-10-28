'use strict';

const assert = require('node:assert/strict');
const { spawn, execFileSync } = require('node:child_process');
const fs = require('node:fs');
const http = require('node:http');
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
        const stylesheetResponses = [];
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
            if (response.url().endsWith('/_nora/ui.css')) {
                stylesheetResponses.push(response.status());
            }
        });

        const response = await page.goto(url, { waitUntil: 'networkidle' });
        assert.equal(response.status(), 200);
        assert.equal(await page.locator('[data-nora-root][data-nora-page]').count(), 1);
        assert.deepEqual(await page.locator('h1').allTextContents(), ['Nora reactive counter']);
        assert.deepEqual(await page.locator('h2').allTextContents(), [
            'Primary counter',
            'Coordinated counter',
            'About Nora',
        ]);
        assert.equal(await page.locator('output').count(), 2);
        assert.deepEqual(await page.locator('output').allTextContents(), ['0', '0']);
        assert.equal(await page.locator('script[src="/_nora/runtime.js"]').count(), 1);
        assert.deepEqual(runtimeResponses, [200], 'the framework runtime must load automatically');
        assert.deepEqual(stylesheetResponses, [200], 'the Nora UI stylesheet must load automatically');
        assert.equal(await page.locator('.nora-button--primary').count(), 2);
        assert.equal(await page.locator('input').inputValue(), 'Keep my input');
        const dialog = page.locator('dialog#counter-help');
        await page.getByRole('button', { name: 'About' }).click();
        assert.equal(await dialog.evaluate(element => element.open), true);
        await page.keyboard.press('Escape');
        assert.equal(await dialog.evaluate(element => element.open), false);
        assert.equal(
            await page.getByRole('button', { name: 'About' }).evaluate(element => element === document.activeElement),
            true,
        );

        const overviewTab = page.getByRole('tab', { name: 'Overview' });
        const detailsTab = page.getByRole('tab', { name: 'Details' });
        assert.equal(await overviewTab.getAttribute('aria-selected'), 'true');
        await overviewTab.press('ArrowRight');
        assert.equal(await detailsTab.getAttribute('aria-selected'), 'true');
        assert.equal(await page.getByRole('tabpanel', { name: 'Details' }).isVisible(), true);

        const dropdown = page.locator('[data-nora-dropdown]');
        await dropdown.locator('summary').click();
        assert.equal(await dropdown.getAttribute('open'), '');
        await dropdown.locator('summary').press('Escape');
        assert.equal(await dropdown.getAttribute('open'), null);

        await page.addScriptTag({ url: `${url}_nora/runtime.js` });
        assert.equal(
            await page.evaluate(() => Boolean(document[Symbol.for('nora.reactive.runtime.initialized')])),
            true,
            'loading the runtime again must preserve its single initialization',
        );

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
                if (/status of (403|409|422)\b/.test(message.text())) {
                    return;
                }
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

test('avatar fallback recovers when the image error predates deferred runtime startup', async () => {
    const browser = await chromium.launch({ headless: true });

    try {
        const page = await browser.newPage();
        await page.setContent(
            '<img data-nora-avatar-image src="data:image/png;base64,AAAA">'
            + '<span data-nora-avatar-fallback hidden>AB</span>',
        );
        await page.waitForFunction(() => {
            const image = document.querySelector('[data-nora-avatar-image]');
            return image.complete && image.naturalWidth === 0;
        });
        await page.addScriptTag({ path: path.join(repositoryRoot, 'resources', 'reactive', 'runtime.js') });
        assert.equal(await page.locator('[data-nora-avatar-image]').isHidden(), true);
        assert.equal(await page.locator('[data-nora-avatar-fallback]').isVisible(), true);
    } finally {
        await browser.close();
    }
});

test('public UI demo composes components and handles validation, authorization, and keyboard access', async () => {
    const port = await availablePort();
    const demoPublic = path.join(repositoryRoot, 'examples', 'nora-ui-demo', 'public');
    let browser;
    let server;
    let serverOutput = '';

    try {
        server = spawn(
            process.env.PHP_BINARY || 'php',
            ['-S', `127.0.0.1:${port}`, '-t', demoPublic, path.join(demoPublic, 'router.php')],
            { cwd: repositoryRoot, stdio: ['ignore', 'pipe', 'pipe'] }
        );
        server.stdout.setEncoding('utf8').on('data', chunk => { serverOutput += chunk; });
        server.stderr.setEncoding('utf8').on('data', chunk => { serverOutput += chunk; });
        const url = `http://127.0.0.1:${port}/`;
        await waitForServer(url, server, () => serverOutput);

        browser = await chromium.launch({ headless: true });
        const page = await browser.newPage();
        const actionResponses = [];
        const actionRequests = [];
        const failedAssets = [];
        page.on('request', request => {
            if (request.url().endsWith('/_nora/action')) {
                actionRequests.push(request.postData());
            }
        });
        page.on('response', response => {
            if (response.url().endsWith('/_nora/action')) {
                actionResponses.push(response.status());
            }
            if (
                (
                    response.url().endsWith('/_nora/runtime.js')
                    || response.url().endsWith('/_nora/ui.css')
                    || response.url().endsWith('/avatar.svg')
                )
                && response.status() >= 400
            ) {
                failedAssets.push([response.url(), response.status()]);
            }
        });
        page.on('console', message => {
            if (message.type() === 'error') {
                if (/status of (403|409|422)\b/.test(message.text())) {
                    return;
                }
                serverOutput += `\nBrowser console: ${message.text()}`;
            }
        });

        const response = await page.goto(url, { waitUntil: 'networkidle' });
        assert.equal(response.status(), 200, serverOutput);
        assert.doesNotMatch(serverOutput, /Trying to access array offset on value of type null/);
        assert.equal(await page.title(), 'Nora UI Integration Demo');
        assert.equal(await page.locator('main[data-nora-root]').count(), 1);
        assert.equal(await page.locator('h1').allTextContents().then(values => values.length), 1);
        assert.equal(await page.locator('.nora-card').count(), 5);
        assert.equal(await page.getByRole('table', { name: 'People in the workspace' }).count(), 1);
        assert.equal(await page.locator('script[src="/_nora/runtime.js"]').count(), 1);
        assert.equal(await page.locator('link[href="/_nora/ui.css"]').count(), 1);
        assert.equal(await page.locator('form[data-nora-form]').count(), 4);
        assert.equal(await page.getByLabel('Full name').count(), 1);
        assert.equal(await page.getByLabel('Email address').count(), 1);
        assert.equal(await page.locator('.nora-data-table tbody tr[data-nora-key]').count(), 3);
        assert.deepEqual(failedAssets, []);

        const accessibilityRelationships = await page.evaluate(() => {
            const ids = new Set(Array.from(document.querySelectorAll('[id]'), node => node.id));
            const navigation = document.querySelector('nav[aria-label="Main navigation"]');
            const dialog = document.querySelector('dialog[aria-labelledby][aria-describedby]');
            const tablist = document.querySelector('[role="tablist"][aria-label]');
            const tabs = Array.from(document.querySelectorAll('[role="tab"]'));
            const panels = Array.from(document.querySelectorAll('[role="tabpanel"]'));
            const controls = Array.from(document.querySelectorAll('input:not([type="hidden"]), select, textarea'));
            return {
                navigationLinksResolve: Array.from(navigation.querySelectorAll('a[href^="#"]'))
                    .every(link => ids.has(link.getAttribute('href').slice(1))),
                dialogLabelResolves: ids.has(dialog.getAttribute('aria-labelledby')),
                dialogDescriptionResolves: ids.has(dialog.getAttribute('aria-describedby')),
                tabsHavePanels: tabs.every(tab => ids.has(tab.getAttribute('aria-controls'))),
                panelsHaveTabs: panels.every(panel => ids.has(panel.getAttribute('aria-labelledby'))),
                controlsHaveLabels: controls.every(control => control.labels.length > 0),
                tablistIsNamed: tablist.getAttribute('aria-label') !== ''
            };
        });
        assert.deepEqual(accessibilityRelationships, {
            navigationLinksResolve: true,
            dialogLabelResolves: true,
            dialogDescriptionResolves: true,
            tabsHavePanels: true,
            panelsHaveTabs: true,
            controlsHaveLabels: true,
            tablistIsNamed: true
        });
        const avatar = page.locator('[data-nora-avatar-image]');
        assert.equal(await avatar.evaluate(image => image.complete && image.naturalWidth > 0), true);
        await avatar.evaluate(image => image.dispatchEvent(new Event('error')));
        await page.waitForFunction(() => {
            const image = document.querySelector('[data-nora-avatar-image]');
            const fallback = image?.parentElement.querySelector('[data-nora-avatar-fallback]');
            return image?.hidden === true && fallback?.hidden === false;
        });
        assert.equal(await page.locator('[data-nora-tab-target="disabled"]').isDisabled(), true);
        assert.equal(await page.locator('.nora-progress').getAttribute('max'), '100');
        assert.equal(await page.locator('.nora-demo-section').evaluate(element =>
            element.scrollWidth <= document.documentElement.clientWidth), true);

        const viewportWidths = [];
        await page.setViewportSize({ width: 375, height: 812 });
        viewportWidths.push(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
        await page.setViewportSize({ width: 1280, height: 900 });
        viewportWidths.push(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
        assert.deepEqual(viewportWidths, [true, true]);

        const alert = page.locator('.nora-alert[aria-label="Demo information"]');
        await alert.locator('[data-nora-dismiss]').click();
        assert.equal(await alert.count(), 0);
        const toastPreview = page.locator('#toast-preview');
        await toastPreview.locator('[data-nora-toast-dismiss]').click();
        assert.equal(await toastPreview.count(), 0);
        const accordion = page.locator('.nora-accordion details').first();
        await accordion.locator('summary').click();
        assert.equal(await accordion.evaluate(element => element.open), false);

        const modalTrigger = page.getByRole('button', { name: 'Open help' });
        const dialog = page.locator('dialog#demo-help');
        await modalTrigger.focus();
        await page.keyboard.press('Enter');
        assert.equal(await dialog.evaluate(element => element.open), true);
        assert.equal(await page.getByRole('dialog', { name: 'About this demo' }).count(), 1);
        await page.keyboard.press('Escape');
        assert.equal(await dialog.evaluate(element => element.open), false);
        assert.equal(await modalTrigger.evaluate(element => element === document.activeElement), true);

        const overview = page.getByRole('tab', { name: 'Overview' });
        const security = page.getByRole('tab', { name: 'Security' });
        await overview.focus();
        await page.keyboard.press('ArrowRight');
        assert.equal(await security.getAttribute('aria-selected'), 'true');
        assert.equal(await security.evaluate(element => element === document.activeElement), true);
        await page.keyboard.press('Home');
        assert.equal(await overview.getAttribute('aria-selected'), 'true');
        await page.keyboard.press('End');
        assert.equal(await security.getAttribute('aria-selected'), 'true');
        assert.equal(await page.locator('[data-nora-tab-target="disabled"]').isDisabled(), true);

        const dropdown = page.locator('[data-nora-dropdown]');
        const summary = dropdown.locator('summary');
        await summary.focus();
        await page.keyboard.press('Enter');
        assert.equal(await dropdown.getAttribute('open'), '');
        await page.keyboard.press('Escape');
        assert.equal(await dropdown.getAttribute('open'), null);
        assert.equal(await summary.evaluate(element => element === document.activeElement), true);
        await summary.click();
        await page.getByRole('heading', { name: 'People directory' }).click();
        assert.equal(await dropdown.getAttribute('open'), null);

        const table = page.locator('.nora-data-table');
        const filters = page.locator('#directory-filters');
        const query = filters.locator('[name="query"]');
        await query.fill('Youssef');
        let responsePromise = waitForAction(page);
        await filters.getByRole('button', { name: 'Apply filters' }).click();
        const searchResponse = await responsePromise;
        assert.equal(
            searchResponse.status(),
            200,
            `${serverOutput}\n${JSON.stringify(actionRequests)}\n${await searchResponse.text()}`,
        );
        assert.equal(await table.getAttribute('data-nora-result-count'), '1');
        assert.match(await table.textContent(), /Youssef/);

        await filters.locator('[data-nora-search-clear]').click();
        assert.equal(await query.inputValue(), '');
        responsePromise = waitForAction(page);
        await filters.getByRole('button', { name: 'Apply filters' }).click();
        assert.equal((await responsePromise).status(), 200);
        assert.equal(await table.getAttribute('data-nora-result-count'), '7');

        responsePromise = waitForAction(page);
        await table.getByRole('button', { name: 'Name' }).click();
        assert.equal((await responsePromise).status(), 200);
        assert.equal(await table.locator('th[aria-sort]').getAttribute('aria-sort'), 'descending');
        assert.match(await table.locator('tbody').textContent(), /Youssef/);

        responsePromise = waitForAction(page);
        await page.getByRole('button', { name: 'Next page' }).click();
        assert.equal((await responsePromise).status(), 200);
        assert.equal(await page.locator('.nora-pagination__status').textContent(), 'Page 2 of 3');
        responsePromise = waitForAction(page);
        await page.getByRole('button', { name: 'Previous page' }).click();
        assert.equal((await responsePromise).status(), 200);
        assert.equal(await page.locator('.nora-pagination__status').textContent(), 'Page 1 of 3');

        await filters.locator('[name="status"]').selectOption('Invited');
        responsePromise = waitForAction(page);
        await filters.getByRole('button', { name: 'Apply filters' }).click();
        assert.equal((await responsePromise).status(), 200);
        assert.equal(await table.getAttribute('data-nora-result-count'), '2');
        assert.match(await table.textContent(), /Invited/);
        await filters.locator('[name="status"]').selectOption('All');
        responsePromise = waitForAction(page);
        await filters.getByRole('button', { name: 'Apply filters' }).click();
        assert.equal((await responsePromise).status(), 200);
        assert.equal(await table.getAttribute('data-nora-result-count'), '7');

        const recordModal = page.locator('#record-editor');
        await page.getByRole('button', { name: 'Add a person' }).click();
        assert.equal(await recordModal.evaluate(element => element.open), true);
        const recordForm = recordModal.locator('form');
        await recordForm.locator('[name="record_name"]').fill('Zuri Demo');
        await recordForm.locator('[name="record_email"]').fill('invalid');
        await recordForm.locator('[name="record_role"]').selectOption('Admin');
        responsePromise = waitForAction(page);
        await recordForm.getByRole('button', { name: 'Save person' }).click();
        assert.equal((await responsePromise).status(), 422);
        assert.equal(await recordForm.locator('[name="record_email"]').getAttribute('aria-invalid'), 'true');
        assert.equal(await recordModal.evaluate(element => element.open), true);

        await recordForm.locator('[name="record_email"]').fill('zuri@example.test');
        responsePromise = waitForAction(page);
        await recordForm.getByRole('button', { name: 'Save person' }).click();
        assert.equal((await responsePromise).status(), 200);
        await page.waitForFunction(() => document.querySelector('#record-editor')?.open === false);
        await page.waitForFunction(() => document.body.textContent.includes('Zuri Demo'));
        assert.equal(
            await page.getByRole('button', { name: 'Add a person' }).evaluate(element => element === document.activeElement),
            true,
            'closing the controlled modal should return focus to its opener',
        );
        assert.equal(await page.locator('#demo-toast').count(), 1);
        responsePromise = waitForAction(page);
        await page.locator('#demo-toast [data-nora-toast-dismiss]').click();
        assert.equal((await responsePromise).status(), 200);
        await page.waitForFunction(() => document.querySelector('#demo-toast') === null);

        responsePromise = waitForAction(page);
        const editButton = table.getByRole('button', { name: 'Edit' }).first();
        await editButton.click();
        assert.equal((await responsePromise).status(), 200);
        await page.waitForFunction(() => document.querySelector('#record-editor')?.open === true);
        assert.equal(await recordModal.evaluate(dialog => dialog.contains(document.activeElement)), true);
        await page.keyboard.press('Escape');
        responsePromise = waitForAction(page);
        assert.equal((await responsePromise).status(), 200);
        await page.waitForFunction(() => document.querySelector('#record-editor')?.open === false);
        assert.equal(await editButton.evaluate(element => element === document.activeElement), true);

        responsePromise = waitForAction(page);
        await table.getByRole('button', { name: 'Edit' }).first().click();
        assert.equal((await responsePromise).status(), 200);
        const editForm = recordModal.locator('form');
        await editForm.locator('[name="record_name"]').fill('Updated Person');
        await editForm.locator('[name="record_email"]').fill('updated@example.test');
        await editForm.locator('[name="record_role"]').selectOption('Editor');
        responsePromise = waitForAction(page);
        await editForm.getByRole('button', { name: 'Save person' }).click();
        assert.equal((await responsePromise).status(), 200);
        await page.waitForFunction(() => document.body.textContent.includes('Updated Person'));

        const preferences = page.locator('#preferences-form');
        await preferences.locator('[name="digest"]').check();
        await preferences.locator('[name="notifications"]').uncheck();
        await preferences.locator('[name="density"][value="Compact"]').check();
        await preferences.locator('[name="timezone"]').selectOption('UTC');
        await preferences.locator('[name="note"]').fill('Saved in this session');
        responsePromise = waitForAction(page);
        await preferences.getByRole('button', { name: 'Save preferences' }).click();
        assert.equal((await responsePromise).status(), 200);
        await page.waitForFunction(() => document.querySelector('#demo-toast')?.textContent.includes('Preferences saved'));

        const form = page.locator('#profile-form');
        await form.locator('[name="name"]').fill('Amina');
        await form.locator('[name="email"]').fill('not-an-email');
        const invalidResponsePromise = waitForAction(page);
        await form.getByRole('button', { name: 'Save profile' }).click();
        const invalidResponse = await invalidResponsePromise;
        assert.equal(invalidResponse.status(), 422);
        await page.waitForFunction(() => document.querySelector('#profile-form')?.dataset.noraState === 'invalid');
        const email = form.locator('[name="email"]');
        assert.equal(await email.getAttribute('aria-invalid'), 'true');
        assert.match(await email.getAttribute('aria-describedby'), /nora-field-error-/);
        assert.equal(await page.locator('[data-nora-form-summary][role="alert"]').count(), 1);
        assert.equal(await email.evaluate(element => element === document.activeElement), true);

        await email.fill('amina@example.test');
        const validResponsePromise = waitForAction(page);
        await form.getByRole('button', { name: 'Save profile' }).click();
        assert.equal((await validResponsePromise).status(), 200);
        await page.waitForFunction(() => document.body.textContent.includes('Profile saved for Amina'));
        assert.doesNotMatch(serverOutput, /Trying to access array offset on value of type null/);
        assert.equal(await email.getAttribute('aria-invalid'), null);
        assert.equal(await page.locator('[data-nora-status]').textContent(), 'Profile saved');

        const increment = page.getByRole('button', { name: 'Increment' });
        const incrementResponsePromise = waitForAction(page);
        await increment.click();
        assert.equal((await incrementResponsePromise).status(), 200);
        await page.waitForFunction(() => document.querySelector('#visit-count').textContent === '1');

        const deniedResponsePromise = waitForAction(page);
        await page.getByRole('button', { name: 'Try a denied action' }).click();
        assert.equal((await deniedResponsePromise).status(), 403);
        await page.waitForFunction(() => document.querySelector('[data-nora-status]').textContent !== '');
        assert.equal(await page.locator('#visit-count').textContent(), '1');

        const staleStatus = await page.evaluate(async () => {
            const root = document.querySelector('[data-nora-root]');
            const csrf = document.querySelector('meta[name="nora-csrf"]').content;
            const response = await fetch('/_nora/action', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({
                    page: root.dataset.noraPage,
                    action: 'increment',
                    revision: 0,
                    arguments: { amount: 1 }
                })
            });
            return response.status;
        });
        assert.equal(staleStatus, 409);
        assert.deepEqual(actionResponses, [
            200, 200, 200, 200, 200, 200, 200,
            422, 200, 200, 200, 200, 200, 200, 200,
            422, 200, 200, 403, 409
        ]);
        assert.deepEqual(failedAssets, []);
        assert.doesNotMatch(serverOutput, /Browser console:|Warning:|Fatal error|Uncaught/);
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

function waitForAction(page) {
    return page.waitForResponse(
        response => response.url().endsWith('/_nora/action'),
        { timeout: 10000 },
    ).then(async response => {
            await page.waitForFunction(() =>
                document.querySelector('[data-nora-root]')?.dataset.noraBusy !== 'true');
            return response;
        });
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
    let lastResponse = 'No HTTP response was received.';
    for (let attempt = 0; attempt < 50; attempt++) {
        if (server.exitCode !== null) {
            throw new Error(`PHP development server exited early:\n${output()}`);
        }
        try {
            const status = await new Promise((resolve, reject) => {
                const request = http.get(url, response => {
                    response.resume();
                    response.once('end', () => resolve(response.statusCode));
                });
                request.setTimeout(1000, () => request.destroy(new Error('HTTP readiness check timed out.')));
                request.once('error', reject);
            });
            if (status === 200) {
                return;
            }
            lastResponse = `HTTP ${status}`;
        } catch (error) {
            lastResponse = error instanceof Error ? error.message : String(error);
        }
        await delay(100);
    }
    throw new Error(`PHP development server did not become ready (${lastResponse}):\n${output()}`);
}
