# bredala-template — gotchas

Things the method names don't tell you. Grouped by class. Every item below is pinned by a test in `tests/`.

## View

- **An exception thrown inside a template propagates, and its partial output is discarded.** The `include` runs inside `try`/`finally`, so the buffer is always closed and `ob_get_level()` is back where it was — the caller only has to handle the exception.
- **Paths must be absolute and complete.** There is no view root, no configured extension, no resolution logic — `load()` does `is_file($this->file)` and `include`. A relative path resolves against the *current working directory*, which in a web request is wherever the front controller lives. Always build paths from `__DIR__`.
- **A missing file is only detected at `load()`.** The constructor accepts any string; `InvalidArgumentException("File not found …")` surfaces on render. A view object is therefore never a guarantee that the template exists.
- **A directory path fails the same way as a missing file**, via `is_file()`.
- **There is no `$this` inside a template.** `load()` includes it from a static closure, so `static::xss(...)` fails with `Error: Using $this when not in object context`. The class scope survives: call helpers as `static::xss(...)`. Prefer `static::` over `self::`, which would bypass a `View` subclass's overrides. Static members stay reachable, private ones included — `static::create()` is how a template renders a partial.
- **`current()` is the only way to the view from a template, and it throws outside a render.** `View::current()` returns the innermost view being rendered, and raises `LogicException` when no `load()` is running — e.g. when called from a controller.
- **`static::current()->set()` does not change the running template's variables.** The bag is `extract()`ed before the include, so a write only shows up in a later `export()`, `include()` or `load()`.
- **A template must never suspend while rendering.** The render stack and the output buffer are both process-wide. A template that yields a Fiber (an `await` in Revolt/AMPHP/ReactPHP) lets another render push onto both, so `current()` returns the wrong view and the markup of the two renders interleaves. Load the data before `load()`. Threads and workers are unaffected: each has its own statics.
- **A partial does not inherit the template's data by itself.** `$this->include()` is gone from templates; pass the data explicitly with `static::create($file, get_defined_vars())`. `get_defined_vars()` also picks up variables the template defined itself (loop variables, locals), which is usually what you want for a partial inside a loop.
- **A missing data key is a PHP warning, not an error.** `extract()` only defines the keys present, so an undefined variable in the template emits `Warning: Undefined variable` and renders as empty. In production, with warnings off, a typo'd variable name is invisible. Supply every key the template reads, or use `$x ?? ''` in the template.
- **A data key named `this` is silently ignored.** `load()` calls `extract($this->data, EXTR_SKIP)`, so the key cannot clobber `$this`, but it is not available in the template either — no error, no warning.
- **`include()` (PHP side) does not render.** It builds and returns a **new** `View`; call `->load()` (or cast it to string) to get the markup.
- **`include()`'s merge is `$data + $this->export()`.** PHP's `+` keeps the left operand's keys, so the partial's own data wins and the parent's is the fallback — the opposite order from `array_merge($parent, $child)`. It also does not mutate the parent.
- **PHP eats one newline directly after `?>`.** Not a library behavior, but it dominates byte-exact output comparisons: a template ending in `<?= $x ?>` plus a newline produces no trailing newline, while one ending in literal text keeps it.

## HelperTrait

- **Tag content is not escaped, attribute values are.** `tag('p', ['title' => $t], $body)` escapes `$t` through `xss()` but injects `$body` verbatim. This is the easiest XSS to introduce with this library — wrap content in `xss()` at every call site that takes user input.
- **Attribute *names* are never escaped.** `attrToString(['on"x' => 1])` emits `on"x="1"`, breaking out of the attribute. Never build attribute names from user input.
- **Void elements have no trailing newline; everything else does.** `tag('br')` is `'<br />'` while `tag('p')` is `"<p></p>\n"`, and `closeTag('br')` is `''` while `closeTag('div')` is `"</div>\n"`. Byte-exact template tests must account for this asymmetry.
- **Void elements silently discard `$content`.** `tag('br', null, 'text')` returns `'<br />'` — the text is gone with no warning.
- **The void-element list is fixed and does not include every HTML5 void tag.** An unknown or newer tag is rendered as a pair, so a custom element or a tag missing from the list comes out as `<my-tag></my-tag>`.
- **`false` omits an attribute entirely; `true` renders it bare.** Only real booleans take that path — the string `'false'` renders as `attr="false"`, which for `disabled`/`checked` means *enabled* in HTML. Pass booleans, never strings.
- **`null` renders as an empty attribute**, `attr=""`, not as an omitted one. Use `false` to drop an attribute.
- **`xss()` short-circuits on `is_numeric()` before escaping.** Numeric strings — including exponent forms like `'1e3'` — are cast, not encoded, and never reach `htmlspecialchars`. Harmless in practice (there's nothing to escape in a number), but it means the type checks happen in a specific order.
- **`xss()` json-encodes arrays and objects, then escapes the JSON.** So `['a' => 1]` becomes `{&quot;a&quot;:1}` — fine inside an attribute, wrong if you expected a readable list. `ENT_HTML5` also means single quotes come out as `&apos;`, not `&#039;`.
- **`xss()` on a bool returns `'1'`/`'0'`**, so it can't be used to decide whether to emit a boolean attribute — that logic lives in `attrToString()`.
- **`meta()` switches on the presence of a `rel` key**, nothing else. An entry intended as a `<meta>` that happens to carry `rel` silently becomes a `<link>`, and `<link>` has no `name`/`content` semantics.
- **`meta()`, `scripts()`, `styles()` concatenate with no separator** beyond the per-tag newline rules — `styles()` and `meta()` produce runs of void elements with no newlines at all.
- **`jsVars()` is safe against an early `</script>`** because `json_encode` escapes `/` as `\/` by default, on top of the explicit `JSON_HEX_APOS`. Don't "fix" this by pre-escaping the value.
- **`jsVars()` emits `const`**, so calling it twice with the same name is a JS redeclaration error in the same scope.
- **`HelperTrait` declares a private static `$autoclose`**, so a class that uses the trait cannot extend the list. Wrap or reimplement `tag()` if you need another void element.

## PlainText

- **It prints directly to stdout.** No buffering, no return of the text — `add()` returns the singleton, not the string. To capture output (tests, or composing a string) wrap the calls in `ob_start()`/`ob_get_clean()`.
- **`add()` switches between `vprintf` and `print` on whether you passed arguments.** `add('100% done')` is safe, `add('100% done', $x)` interprets `% d` as a conversion and mangles the output. Double every `%` as soon as you pass arguments.
- **It is a static singleton with no reset.** Chaining works because every method returns `getInstance()`, and the methods being static means `PlainText::add(...)` and `$instance->add(...)` are the same call — but there's no per-run state to clear, and no way to redirect output to a stream.
- **`repeat()`/`eol()`/`tab()`/`space()` print nothing for `$nb <= 0`** rather than erroring, so a computed count that goes negative silently produces no output.
