# Nora Template Support

Nora Template Support recognizes `.nora` files as `nora-template` and adds
HTML-based syntax highlighting, two snippets, and an optional file icon theme.
It is a small TextMate grammar; it does not add a language server,
IntelliSense, semantic validation, or template execution.

## Install from the Visual Studio Marketplace

Marketplace publication is not configured yet. Once the maintainer verifies
control of the `morave-technologies` publisher ID and publishes an approved
release, install the extension using the Marketplace's **Install** button or
the VS Code Extensions view. The configured extension identifier is
`morave-technologies.nora-template`; configuring this ID does not establish
that the publisher is registered or controlled by the maintainer. The
intended public-facing publisher brand is **Morave Technologies**; publisher
registration and ownership still require verification.

## Try from the Nora repository

```powershell
code --new-window --extensionDevelopmentPath="$PWD\extensions\vscode-nora" .
```

This opens an Extension Development Host with the Nora repository. Open a
`.nora` file and confirm the status bar reports **Nora Template**. Merely
cloning Nora does not install or activate the extension in the normal VS Code
profile.

## Build and install a VSIX

From the repository root, package the extension and install the resulting
file into the current VS Code profile:

```powershell
Push-Location .\extensions\vscode-nora
npx --yes @vscode/vsce package
$vsix = Get-ChildItem .\*.vsix | Select-Object -First 1
if (-not $vsix) { throw "VSIX package was not created." }
code --install-extension $vsix.FullName
Pop-Location
```

This requires Node.js/npm and registry access if `@vscode/vsce` is not cached.
The configured publisher is `morave-technologies` for the intended **Morave
Technologies** brand, but its registration and maintainer control have not
been verified. A VSIX installed locally is not a Marketplace release.

## Nora file icons

The extension contributes the **Nora Template Icons** file icon theme, which
uses the Nora-branded icon for `.nora` files. It is not enabled automatically.
An active File Icon Theme can take precedence over language icons. In VS Code,
run **Preferences: File Icon Theme** from the Command Palette and select
**Nora Template Icons** to use Nora's icon for `.nora` files. Choose another
theme there to revert. Installing the extension does not change the user's
active theme or settings.

Marketplace publication would require an approved extension license and
publisher account, confirmation that the publisher ID is available and
controlled by the authorized maintainer, and an authorized release. The
publisher ID and extension license remain unresolved; this extension has not
been published.

The `.nora` language contribution declares the same Nora logo for light and
dark editor language icons. These language icons are separate from the
selectable file icon theme.

## Supported syntax

The grammar follows `Nora\View\TemplateRenderer`:

```html
<p>Hello, {{ user.name }}</p>
{% include "shared/header" %}
```

- HTML tags, attributes, and attribute strings use VS Code's built-in
  `text.html.basic` grammar.
- `{{ variable }}` highlights an identifier and supports the renderer's
  dotted array path form, such as `{{ user.name }}`.
- `{% include "component/name" %}` and the single-quoted equivalent highlight
  the `include` keyword and template path.
- Snippets: `nora-value` and `nora-include`.

The renderer escapes interpolated values and does not evaluate PHP. Only
variable interpolation and include directives are template constructs in the
current renderer. Loops, conditionals, arbitrary directives, PHP execution,
IntelliSense, semantic validation, and runtime behavior are not provided by
this extension. Indentation rules are basic HTML tag heuristics.

## Validate

From the repository root:

```powershell
npm --prefix .\extensions\vscode-nora run validate
```

The validation script checks the extension manifest, grammar and snippet
configuration against real `.nora` templates in the website example. Those
templates currently contain no include directives, so double- and
single-quoted include examples are checked separately against the renderer's
accepted syntax. Visual token-color choices still depend on the active VS
Code theme. See [CHANGELOG.md](CHANGELOG.md) for extension changes.
