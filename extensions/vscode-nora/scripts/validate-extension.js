'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const extensionRoot = path.resolve(__dirname, '..');
const repositoryRoot = path.resolve(extensionRoot, '..', '..');

function readJson(relativePath) {
    return JSON.parse(fs.readFileSync(path.join(extensionRoot, relativePath), 'utf8'));
}

const manifest = readJson('package.json');
const grammar = readJson('syntaxes/nora.tmLanguage.json');
const languageConfiguration = readJson('language-configuration.json');
const snippets = readJson('snippets/nora-template.json');
const iconTheme = readJson('fileicons/nora-icon-theme.json');

assert.equal(manifest.name, 'nora-template');
assert.equal(manifest.publisher, 'morave-technologies');
assert.equal(manifest.displayName, 'Nora Template Support');
assert.equal(manifest.version, '0.1.1');
assert.equal(manifest.repository.url, 'https://github.com/Yasser-devb/nora');
assert.equal(manifest.engines.vscode, '^1.80.0');
assert.equal(manifest.icon, 'images/nora-extension-icon.png');
assert.equal(manifest.contributes.languages.length, 1);
const language = manifest.contributes.languages[0];
assert.equal(language.id, 'nora-template');
assert.equal(language.aliases.includes('Nora Template'), true);
assert.deepEqual(language.extensions, ['.nora']);
assert.deepEqual(language.icon, {
    light: './images/nora-extension-icon.png',
    dark: './images/nora-extension-icon.png',
});
assert.equal(manifest.contributes.grammars[0].language, 'nora-template');
assert.equal(manifest.contributes.grammars[0].scopeName, grammar.scopeName);
assert.equal(manifest.contributes.grammars[0].path, './syntaxes/nora.tmLanguage.json');
assert.equal(manifest.contributes.iconThemes[0].id, 'nora-template-icons');
assert.equal(manifest.contributes.iconThemes[0].path, './fileicons/nora-icon-theme.json');
assert.equal(iconTheme.fileExtensions.nora, 'nora-template-file');
assert.equal(iconTheme.iconDefinitions['nora-template-file'].iconPath, '../images/nora-extension-icon.png');
assert.equal(grammar.patterns.some(pattern => pattern.include === 'text.html.basic'), true);
assert.ok(grammar.patterns.findIndex(pattern => pattern.include === '#interpolation')
    < grammar.patterns.findIndex(pattern => pattern.include === 'text.html.basic'));
assert.ok(grammar.patterns.findIndex(pattern => pattern.include === '#include-directive')
    < grammar.patterns.findIndex(pattern => pattern.include === 'text.html.basic'));
assert.equal(grammar.repository.interpolation.name, 'meta.interpolation.nora');
assert.equal(grammar.repository['include-directive'].name, 'meta.directive.include.nora');
assert.match(grammar.repository['include-directive'].begin, /include/);
assert.equal(grammar.repository.interpolation.patterns[0].match,
    '[A-Za-z_][A-Za-z0-9_]*(?:\\.[A-Za-z_][A-Za-z0-9_]*)*');
assert.equal(grammar.repository['include-directive'].patterns[0].match, '\\binclude\\b');
assert.deepEqual(
    grammar.repository['include-directive'].patterns
        .filter(pattern => pattern.name.startsWith('string.quoted'))
        .map(pattern => pattern.name),
    ['string.quoted.double.nora', 'string.quoted.single.nora'],
);
assert.equal(languageConfiguration.indentationRules.increaseIndentPattern.length > 0, true);
assert.deepEqual(Object.keys(snippets), ['Escaped Nora value', 'Nora template include']);
assert.equal(snippets['Escaped Nora value'].prefix, 'nora-value');
assert.deepEqual(snippets['Escaped Nora value'].body, ['{{ ${1:variable} }}']);
assert.equal(snippets['Nora template include'].prefix, 'nora-include');
assert.deepEqual(
    snippets['Nora template include'].body,
    ['{% include "${1:shared/component}" %}'],
);
const requiredFiles = [
    manifest.icon,
    language.icon.light,
    language.icon.dark,
    language.configuration,
    manifest.contributes.grammars[0].path.replace(/^\.\//, ''),
    manifest.contributes.snippets[0].path.replace(/^\.\//, ''),
    manifest.contributes.iconThemes[0].path.replace(/^\.\//, ''),
    path.join('fileicons', iconTheme.iconDefinitions['nora-template-file'].iconPath),
];
for (const relativePath of requiredFiles) {
    assert.equal(
        fs.existsSync(path.join(extensionRoot, relativePath)),
        true,
        `Missing referenced extension file: ${relativePath}`,
    );
}
const iconBuffer = fs.readFileSync(path.join(extensionRoot, manifest.icon));
assert.equal(iconBuffer.readUInt32BE(0), 0x89504e47, 'The extension icon must be a PNG.');
assert.equal(iconBuffer.readUInt32BE(16), iconBuffer.readUInt32BE(20), 'The extension icon must be square.');
assert.ok(iconBuffer.readUInt32BE(16) >= 128, 'The extension icon should be at least 128x128.');
for (const pattern of [
    grammar.repository.interpolation.begin,
    grammar.repository.interpolation.end,
    grammar.repository.interpolation.patterns[0].match,
    grammar.repository['include-directive'].begin,
    grammar.repository['include-directive'].end,
    grammar.repository['include-directive'].patterns[0].match,
    languageConfiguration.indentationRules.increaseIndentPattern,
    languageConfiguration.indentationRules.decreaseIndentPattern,
]) {
    assert.doesNotThrow(() => new RegExp(pattern), `Invalid regular expression: ${pattern}`);
}
const increaseIndent = new RegExp(languageConfiguration.indentationRules.increaseIndentPattern);
const decreaseIndent = new RegExp(languageConfiguration.indentationRules.decreaseIndentPattern);
assert.equal(increaseIndent.test('<section>'), true);
assert.equal(decreaseIndent.test('</section>'), true);
assert.equal(increaseIndent.test('<custom-element />'), false);
assert.equal(increaseIndent.test('<img>'), false);

const renderer = fs.readFileSync(
    path.join(repositoryRoot, 'src', 'View', 'TemplateRenderer.php'),
    'utf8',
);
assert.ok(renderer.includes('include\\\\s+'), 'Expected the renderer include syntax.');
assert.ok(renderer.includes('[A-Za-z_][A-Za-z0-9_.]*'), 'Expected the renderer interpolation syntax.');
assert.ok(renderer.includes('[A-Za-z0-9_\\/-]+'), 'Expected the renderer include path syntax.');
assert.match(renderer, /PHP is never evaluated/);

function findNoraFiles(directory) {
    return fs.readdirSync(directory, { withFileTypes: true }).flatMap(entry => {
        const absolutePath = path.join(directory, entry.name);
        if (entry.isDirectory()) {
            return findNoraFiles(absolutePath);
        }
        return entry.isFile() && entry.name.endsWith('.nora') ? [absolutePath] : [];
    });
}

const templatesRoot = path.join(
    repositoryRoot,
    'examples',
    'nora-website',
    'resources',
    'views',
);
const templates = findNoraFiles(templatesRoot);
assert.ok(templates.length > 0, 'Expected real website templates to validate.');
const homeTemplate = path.join(templatesRoot, 'pages', 'home.nora');
assert.equal(templates.includes(homeTemplate), true, 'Expected the real homepage template.');
assert.equal(path.extname(homeTemplate), language.extensions[0]);

const interpolation = /\{\{\s*([A-Za-z_][A-Za-z0-9_.]*)\s*\}\}/g;
const includeDirective = /\{%\s*include\s+["']([A-Za-z0-9_/-]+)["']\s*%\}/g;
assert.equal([...'{% include "shared/header" %}'.matchAll(includeDirective)][0]?.[1], 'shared/header');
assert.equal([..."{% include 'shared/footer' %}".matchAll(includeDirective)][0]?.[1], 'shared/footer');
assert.equal([... '{{ user.name }}'.matchAll(interpolation)][0]?.[1], 'user.name');
let interpolationCount = 0;
let includeCount = 0;
for (const template of templates) {
    const source = fs.readFileSync(template, 'utf8');
    interpolationCount += [...source.matchAll(interpolation)].length;
    includeCount += [...source.matchAll(includeDirective)].length;
}

assert.ok(interpolationCount > 0, 'Expected interpolations in real templates.');

console.log(`Validated Nora language contribution, grammar, configuration, and ${Object.keys(snippets).length} snippets.`);
console.log(`Scanned ${templates.length} real templates: ${interpolationCount} interpolations, ${includeCount} include directives.`);
console.log('Validated double- and single-quoted includes against the renderer-supported syntax.');
