# bredala-template — API cheat sheet

Quick lookup by intent. This is not exhaustive — read the source in `vendor/sugatasei/bredala-template/src/` for exact signatures/edge cases not covered here.

## View (`Bredala\Template\View`)

`class View implements \Stringable`, using `BagTrait` and `HelperTrait`. One instance = one template file + its data.

| Intent | Method |
| ------ | ------ |
| Build | `__construct(string $file, array $data = [])` / `static create(string $file, array $data = []): static` |
| Render to a string | `load(): string` |
| Same, implicitly | `__toString(): string` |
| Build a partial inheriting this view's data | `include(string $file, array $data = []): static` |
| The view whose template is executing | `static current(): static` |

`load()` requires an **absolute** path (there is no view root), throws `InvalidArgumentException("File not found {$file}")` when `is_file()` fails, then includes the template from a **static closure**: the data bag is `extract()`ed with `EXTR_SKIP` (a `this` key is ignored), and the `include` is buffered inside `try`/`finally` so the buffer is closed even when the template throws. It can be called repeatedly.

`include()` builds a new `View` with `$data + $this->export()` — the partial's own data wins, the parent's is the fallback. It does **not** render; call `->load()` on the result. From inside a template, reach it through `static::current()->include(...)`.

`current()` returns the innermost view being rendered: `load()` pushes the view before the include and pops it in a `finally`. Outside a render it throws `LogicException`. The stack is process-wide, like the output buffer, so a template must never suspend (Fiber, `await`) while rendering.

Inside the template: every data key is a local variable and nothing else is defined. There is no `$this`; the class scope is kept, so `HelperTrait` is reachable as `static::…` and a partial is rendered with `static::create($file, get_defined_vars())->load()`. The view itself, and so `BagTrait`, is reachable only through `static::current()`.

## BagTrait (`Bredala\Template\BagTrait`)

The data bag. Private `array $data`; every mutator is fluent.

| Intent | Method |
| ------ | ------ |
| Replace the whole bag | `import(array $data): static` |
| Set one key | `set(string $name, mixed $value): static` |
| Append to a list under one key | `add(string $name, mixed $value): static` |
| Read the bag (a copy) | `export(): array` |

`add()` appends when the slot holds an array **or is unset/null**; on a non-array scalar it is a silent no-op. Existing keys of an associative array are preserved, and the appended value gets the next integer key.

## HelperTrait (`Bredala\Template\HelperTrait`)

All static. Available as `static::…` from inside a `View` template, `View::…` elsewhere, or `use HelperTrait` in your own class.

### Tags

| Intent | Method |
| ------ | ------ |
| A full element | `static tag(string $tag, ?array $attrs = null, string $content = ""): string` |
| Just the opening tag | `static openTag(string $tag, array $attrs = [])` |
| Just the closing tag | `static closeTag(string $tag)` |
| Render an attribute array | `static attrToString($attributes): string` |

Void elements (`area, base, br, col, embed, hr, img, input, keygen, link, meta, param, source, track, wbr`) render as `<tag … />` with **no trailing newline** and their `$content` ignored; `closeTag()` returns `''` for them. Every other tag renders as `<tag …>content</tag>` **followed by `\n`**, and `closeTag()` returns `</tag>\n`.

`attrToString()` prefixes each attribute with a space. A `true` value renders a bare attribute, a `false` omits it. Any other value is cast to string and escaped through `xss()`. **Attribute names are not escaped.**

### Escaping

`static xss(mixed $value): string` — `null` → `''`; numeric (including numeric strings) → cast to string, **unescaped**; string → `htmlspecialchars(ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8')`; bool → `'1'`/`'0'`; anything else → json-encoded then escaped.

Note `ENT_HTML5` means `'` becomes `&apos;` (not `&#039;`).

### Head markup

| Intent | Method |
| ------ | ------ |
| `<meta>`/`<link>` list | `static meta(array $meta): string` — emits `<link>` when the entry has a `rel` key, else `<meta>` |
| `<script src>` list | `static scripts(array $urls, bool $module = false): string` — `$module` adds `type="module"` |
| `<link rel=stylesheet>` list | `static styles(array $urls): string` |
| An inline JS constant | `static jsVars(string $name, $value): string` — `<script>const NAME = <json>;</script>` |

All four return `''` for an empty input list. `jsVars()` encodes with `JSON_HEX_APOS`; `json_encode` also escapes `/` by default, so a `</script>` in a value cannot close the element early.

## PlainText (`Bredala\Template\PlainText`)

Static helpers that **print straight to stdout** (no internal buffering). Every method returns the singleton, so calls chain.

| Intent | Method |
| ------ | ------ |
| The singleton | `static getInstance(): static` |
| Print text, optionally formatted | `static add(string $text, mixed ...$items): static` |
| Newlines / tabs / spaces | `static eol(int $nb = 1)` / `static tab(int $nb = 1)` / `static space(int $nb = 1)` |
| Repeat an arbitrary string | `static repeat(string $str, int $nb = 1): static` |

`add()` uses `vprintf($text, $items)` when `$items` is non-empty and `print($text)` otherwise, so a literal `%` is only safe in the no-argument form. `repeat()` with `$nb <= 0` prints nothing.
