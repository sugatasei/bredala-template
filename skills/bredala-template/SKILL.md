---
name: bredala-template
description: How to correctly render views, pass data to templates, build HTML tags/attributes/meta/script/style markup, escape output, and print CLI text using the sugatasei/bredala-template PHP library (namespace Bredala\Template — View, PlainText, BagTrait, HelperTrait). Use this whenever the project's composer.json requires sugatasei/bredala-template, code imports from Bredala\Template\*, or you're asked to add/change a view, a template, a layout, a partial, HTML output, meta/script/style tags, output escaping, or CLI console output in a PHP project that has this library available — even if the request is phrased generically like "render this page" or "add a partial" or "escape this value" without naming the library. Also check this before writing raw include/ob_start template code, hand-built HTML strings, or bare htmlspecialchars calls in such a project, since this library replaces those and has non-obvious behavior (tag content is NOT escaped while attributes are, a missing variable is only a warning, a template can rewrite the data bag) that plain PHP code would miss.
---

# bredala-template

`sugatasei/bredala-template` is a minimal, framework-agnostic view layer for PHP 8.5+: templates are plain PHP files rendered through output buffering, with a small data bag and a set of static HTML helpers. There is **no** template syntax, no compilation, no caching, no inheritance/blocks, no auto-escaping — you write PHP in `.phtml` files and escape explicitly.

Namespace: `Bredala\Template\*`. Source lives in `vendor/sugatasei/bredala-template/src/`; read it directly when you need an exact signature — this skill focuses on *how the pieces fit together* and the behavior that isn't obvious from the method names.

## Orientation

- `View` — one template file plus its data. Build it with `View::create($file, $data)`, render with `load()` or by casting to string.
- `BagTrait` — the data bag `View` uses: `import()`, `set()`, `add()`, `export()`.
- `HelperTrait` — static HTML builders (`tag`, `openTag`, `closeTag`, `attrToString`, `meta`, `scripts`, `styles`, `jsVars`) plus `xss()` for escaping. `View` uses it, so **all of it is available as `static::…` inside a template** (there is no `$this` there).
- `PlainText` — static helpers that print directly to stdout, for CLI output.

For a full method cheat-sheet see `references/api-reference.md`. For the complete list of easy-to-miss behaviors, see `references/gotchas.md` — read it before debugging a view that "renders nothing."

## Core recipes

### Rendering a view

```php
use Bredala\Template\View;

echo View::create(__DIR__ . '/views/user.phtml', [
    'name' => 'Tom',
    'posts' => $posts,
])->load();
```

Inside `views/user.phtml`, each data key is a local variable. The template is included from a **static closure**: `$this` does not exist, but the class scope is kept, so helpers are called with `static::`:

```php
<h1><?= static::xss($name) ?></h1>
<ul>
<?php foreach ($posts as $post): ?>
    <li><?= static::xss($post->title) ?></li>
<?php endforeach ?>
</ul>
```

Pass the **absolute** path — `load()` does `is_file($file)` then `include`, with no view directory or resolution logic, so a relative path is resolved against the CWD.

### Escaping

Nothing is escaped automatically. `xss()` handles the cases you meet in a template:

```php
<?= static::xss($userInput) ?>              <!-- htmlspecialchars, ENT_QUOTES|ENT_HTML5 -->
<?= static::tag('p', ['title' => $t], static::xss($body)) ?>
```

Attribute values passed to `tag()`/`attrToString()` **are** escaped for you. Tag **content is not** — escape it yourself, as above. Attribute *names* aren't escaped either, so never build them from user input.

### Layout and partials

There's no block inheritance, and `$this->include()` is not available inside a template. Render a partial with `static::create()`, passing its data explicitly — `get_defined_vars()` hands it every variable of the current template:

```php
// layout.phtml
<!doctype html>
<html>
<head><?= static::meta($meta) ?><?= static::styles($css) ?></head>
<body>
<?= $content ?>
<?= static::create(__DIR__ . '/partials/footer.phtml', get_defined_vars())->load() ?>
</body>
</html>
```

Render the inner view first and pass it as data, which is the usual way to get a layout:

```php
$content = View::create(__DIR__ . '/views/user.phtml', ['name' => 'Tom'])->load();

echo View::create(__DIR__ . '/views/layout.phtml', [
    'content' => $content,
    'meta' => [['name' => 'description', 'content' => 'A page']],
    'css' => ['/app.css'],
])->load();
```

From PHP code, `$view->include($file, $data)` builds a partial whose data merges as `$data + $view->export()` — the partial's own data wins, the parent's is the fallback. Call `->load()` on the result; `include()` only builds the view.

### Head markup

```php
static::meta([
    ['name' => 'description', 'content' => 'A page'],   // -> <meta …>
    ['rel' => 'canonical', 'href' => '/x'],             // -> <link …>
]);

static::styles(['/app.css']);
static::scripts(['/app.js']);
static::scripts(['/app.mjs'], true);       // type="module"
static::jsVars('APP', ['locale' => 'fr']); // <script>const APP = {"locale":"fr"};</script>
```

`meta()` picks `<link>` over `<meta>` when the entry has a `rel` key. `jsVars()` json-encodes the value with `JSON_HEX_APOS`, and `json_encode` escapes `/` by default, so a `</script>` inside a value can't close the element early.

### Boolean attributes

```php
static::tag('input', ['type' => 'checkbox', 'checked' => $isChecked]);
```

A `true` renders a bare attribute (`checked`), a `false` omits it entirely. Pass real booleans, not `'checked'`/`''`.

### CLI output

```php
use Bredala\Template\PlainText;

PlainText::add('Importing %d rows', $count)->eol();
PlainText::tab()->add('- done')->eol(2);
```

`add()` uses `vprintf` when given extra arguments and a plain `print` otherwise — so a literal `%` is safe only in the no-argument form. Every method returns the singleton, so calls chain.

## Behavior to keep in mind while writing code

- **Tag content is not escaped; attribute values are.** `tag('p', null, $userInput)` injects raw HTML. Wrap content in `xss()` yourself.
- **An exception thrown inside a template propagates, but the buffer is always closed** (`try`/`finally`) and the partial output discarded — no manual `ob_end_clean()` needed.
- **Templates need absolute paths.** No view root, no extension guessing; `load()` throws `InvalidArgumentException("File not found …")`, and only at `load()` time, not at construction.
- **There is no `$this` inside a template.** Use `static::xss()`, `static::tag()`, etc. (prefer `static::` over `self::` so a `View` subclass's overrides apply). Non-static methods — `set()`, `import()`, `export()`, `include()`, `load()` — are unreachable, so a template cannot touch the bag. Writing `$this->…` fails with `Error: Using $this when not in object context`.
- **A data key named `this` is silently ignored** (`EXTR_SKIP`).
- **`import()` replaces the bag wholesale**, it does not merge. Use `set()` to add one key.
- **`add()` on a key holding a non-array scalar is a silent no-op** (no exception, no conversion) — except for `null`, which the `??` guard treats as absent, so `add()` replaces it with a list.
- **A missing template variable is a PHP warning, not an error**, and renders as empty. Always supply every key a template reads, or use `??` in the template.
- **`xss()` short-circuits on numerics**, so numeric strings pass through unescaped (harmless, but it means the check order matters), and non-scalars are json-encoded then escaped.
- **`PlainText` writes straight to stdout** with no internal buffering — wrap it in `ob_start()` when you need to capture it, and remember it's a static singleton with no reset.

Read `references/gotchas.md` for the rest (the trailing-newline rules per helper, autoclose tags ignoring content, `closeTag()` returning `''`, `add()`'s key preservation) before matching output byte-for-byte.
