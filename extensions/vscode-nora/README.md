# Nora Template Support

Nora Template Support recognizes `.nora` files as `nora-template` and adds
HTML-based syntax highlighting, two snippets, and an optional file icon theme.
This page is retained in the Nora Framework repository as public-facing
extension information. The extension implementation is maintained separately
from the framework release.

## Publication status

Version **0.1.2** is prepared in the separate extension project's local
release metadata; Marketplace publication has not been verified. There is no
verified Marketplace listing or installation link. The intended extension ID
is `morave-technologies.nora-template`; this ID does not establish publisher
registration or ownership. Do not treat this extension version as a Nora
Framework version.

When publication is verified, this page can link to the Marketplace listing
and installation instructions. Until then, no Marketplace install path is
claimed.

## Supported syntax

The extension provides syntax highlighting for the constructs implemented by
Nora's `Nora\View\TemplateRenderer`:

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

The TextMate grammar does not provide a language server, IntelliSense,
semantic validation, template execution, or PHP evaluation. Loops and
conditionals are not supported template constructs. Indentation behavior uses
basic HTML tag heuristics. The optional **Nora Template Icons** file icon theme
must be selected by the user in VS Code; language icons and the file icon
theme are separate contributions.

For framework installation and project information, see the
[Nora Framework README](../../README.md).
