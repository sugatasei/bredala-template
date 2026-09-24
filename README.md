# Bredala/Template

Moteur de templates minimaliste pour PHP : les templates sont des fichiers PHP ordinaires, rendus par temporisation de sortie.

Pas de syntaxe de template, pas de compilation, pas de cache, pas d'héritage de blocs, pas d'échappement automatique : on écrit du PHP dans des fichiers `.phtml` et on échappe explicitement.

## Installation

```bash
composer require sugatasei/bredala-template
```

## Claude Code

Ce package fournit un skill Claude Code dans [`skills/bredala-template/`](skills/bredala-template/) qui documente les patterns d'usage et les pièges de la librairie (le contenu des balises n'est pas échappé alors que les attributs le sont, une variable manquante n'est qu'un avertissement, un template peut réécrire le sac de données, etc.).

Dans un projet qui dépend de `sugatasei/bredala-template`, copie-le une fois dans `.claude/skills/` après `composer install` pour que Claude Code le charge automatiquement (le nom du dossier doit correspondre au `name` déclaré dans `SKILL.md`) :

```bash
cp -r vendor/sugatasei/bredala-template/skills/bredala-template .claude/skills/bredala-template
```

## View

`Bredala\Template\View` Un fichier de template et ses données. Implémente `Stringable`, et utilise `BagTrait` et `HelperTrait`.

### Construction

- `__construct(string $file, array $data = [])`
- `create(string $file, array $data = []): static` Équivalent statique.
- `include(string $file, array $data = []): static` Construit une nouvelle vue héritant des données de la vue courante.

### Rendu

- `load(): string` Rend le template et retourne le résultat.
- `__toString(): string` Appelle `load()`.
- `current(): static` Statique. Retourne la vue dont le template est en cours d'exécution, la plus interne en cas de rendus imbriqués. Lève `LogicException` en dehors d'un rendu.

`load()` exige un chemin **absolu** : il n'y a pas de racine de vues, pas d'extension implicite, aucune logique de résolution. Un chemin relatif est résolu depuis le répertoire de travail courant. Construire les chemins depuis `__DIR__`.

Un fichier absent n'est détecté qu'au moment du rendu : `load()` lève alors `InvalidArgumentException("File not found …")`. Un objet `View` ne garantit donc jamais l'existence du template.

`load()` peut être appelé plusieurs fois.

`load()` empile la vue avant d'inclure le template et la dépile dans un `finally`, ce qui garantit une pile vide après un rendu, même quand le template lève une exception. Cette pile est globale au processus, comme le tampon de sortie : **un template ne doit jamais se suspendre pendant son rendu** (Fiber, `await` d'un runtime asynchrone). Une autre vue rendue pendant la suspension mélangerait sa pile et sa sortie avec celles du template suspendu. Charger les données avant `load()`, le template ne fait que les afficher. Les threads (ext-parallel) et les workers (FPM, FrankenPHP, RoadRunner) ne sont pas concernés : chacun a ses propres statiques.

### Dans le template

Chaque clé de données devient une variable locale. Le template est inclus depuis une closure statique : **`$this` n'existe pas**, mais la portée de classe est conservée, donc les helpers statiques de `HelperTrait` s'appellent par `static::…`.

```php
<h1><?= static::xss($name) ?></h1>
<ul>
<?php foreach ($posts as $post): ?>
    <li><?= static::xss($post->title) ?></li>
<?php endforeach ?>
</ul>
```

```php
echo View::create(__DIR__ . '/views/user.phtml', [
    'name' => 'Tom',
    'posts' => $posts,
])->load();
```

Quelques points à connaître :

- Une variable manquante est un **avertissement** PHP, pas une erreur, et se rend vide. En production, avertissements coupés, une faute de frappe dans un nom de variable est invisible. Fournir toutes les clés lues, ou utiliser `$x ?? ''` dans le template.
- Une clé de données nommée `this` est **ignorée silencieusement** (`EXTR_SKIP`) : elle ne devient pas une variable.
- Une exception levée dans un template remonte à l'appelant, mais le tampon de sortie est toujours refermé et la sortie partielle jetée.
- Sans `$this`, la vue n'est accessible dans le template qu'au travers de `static::current()`. Les variables sont extraites avant l'inclusion : un `static::current()->set()` ne change pas les variables du template en cours, seulement les données de la vue pour un `export()` ou un rendu ultérieur.
- Préférer `static::` à `self::` : une sous-classe de `View` qui redéfinit un helper verra sa version appelée.

### Composition

Il n'y a pas d'héritage de blocs. La façon habituelle d'obtenir un gabarit est de rendre la vue interne d'abord et de la passer en donnée :

```php
$content = View::create(__DIR__ . '/views/user.phtml', ['name' => 'Tom'])->load();

echo View::create(__DIR__ . '/views/layout.phtml', [
    'content' => $content,
    'meta' => [['name' => 'description', 'content' => 'Une page']],
    'css' => ['/app.css'],
])->load();
```

`include()` construit, côté PHP, une vue qui hérite des données d'une autre. La fusion est `$data + $this->export()` : les données du partiel gagnent, celles du parent servent de repli — l'ordre inverse d'un `array_merge($parent, $child)`. Le parent n'est pas modifié. `include()` ne rend pas : il faut appeler `->load()` dessus.

```php
$footer = $page->include(__DIR__ . '/partials/footer.phtml', ['year' => 2026])->load();
```

Dans un template, `$this->include()` n'existe plus. Un partiel se rend avec `static::create()`, en lui passant explicitement ses données. `get_defined_vars()` rend toutes les variables du template, y compris celles qu'il a définies lui-même :

```php
<?= static::create(__DIR__ . '/partials/footer.phtml', get_defined_vars())->load() ?>
<?= static::create(__DIR__ . '/partials/card.phtml', ['post' => $post] + get_defined_vars())->load() ?>
```

## BagTrait

`Bredala\Template\BagTrait` Le sac de données utilisé par `View`. Toutes les méthodes de modification sont fluides.

- `import(array $data): static` **Remplace** tout le sac. Ce n'est pas une fusion.
- `set(string $name, mixed $value): static` Affecte une clé.
- `add(string $name, mixed $value): static` Empile une valeur dans la liste d'une clé.
- `export(): array` Retourne une copie du sac.

`add()` empile quand la clé contient un tableau, ou qu'elle est absente ou `null` (le garde-fou est `$this->data[$name] ?? []`, et `??` traite un `null` stocké comme absent). Sur un scalaire non tableau, c'est un **no-op silencieux** : ni exception, ni conversion. Les clés d'un tableau associatif existant sont préservées, la valeur empilée reçoit la clé entière suivante.

## HelperTrait

`Bredala\Template\HelperTrait` Constructeurs HTML statiques. Accessibles via `static::…` depuis un template, `View::…` ailleurs, ou par `use HelperTrait` dans une classe.

### Balises

- `tag(string $tag, ?array $attrs = null, string $content = ""): string` Élément complet.
- `openTag(string $tag, array $attrs = [])` Balise ouvrante seule.
- `closeTag(string $tag)` Balise fermante seule.
- `attrToString($attributes): string` Rend un tableau d'attributs.

Les éléments vides (`area`, `base`, `br`, `col`, `embed`, `hr`, `img`, `input`, `keygen`, `link`, `meta`, `param`, `source`, `track`, `wbr`) sont rendus `<tag … />`, **sans retour à la ligne final**, et leur `$content` est ignoré silencieusement ; `closeTag()` retourne `''` pour eux. Toute autre balise est rendue `<tag …>contenu</tag>` **suivi d'un `\n`**, et `closeTag()` retourne `</tag>\n`.

La liste des éléments vides est figée (privée dans le trait) : une balise absente de la liste, y compris un élément personnalisé, est rendue comme une paire.

`attrToString()` préfixe chaque attribut d'une espace. Une valeur `true` rend un attribut nu, une valeur `false` omet complètement l'attribut, `null` rend `attr=""`. Toute autre valeur est convertie en chaîne puis échappée par `xss()`.

Passer de vrais booléens : la chaîne `'false'` est rendue `attr="false"`, ce qui pour `disabled` ou `checked` **active** l'attribut en HTML.

**Les noms d'attributs ne sont pas échappés** — ne jamais les construire depuis une saisie utilisateur.

### Échappement

- `xss(mixed $value): string` Échappe une valeur.

`null` donne `''` ; une valeur numérique (y compris une chaîne numérique) est convertie sans échappement ; une chaîne passe par `htmlspecialchars(ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8')` ; un booléen donne `'1'`/`'0'` ; le reste est encodé en JSON puis échappé.

`ENT_HTML5` implique que `'` devient `&apos;`, pas `&#039;`.

**Le contenu des balises n'est pas échappé, les valeurs d'attributs le sont.** `tag('p', ['title' => $t], $body)` échappe `$t` mais injecte `$body` tel quel. C'est la faille la plus facile à introduire avec cette librairie : envelopper le contenu dans `xss()` à chaque appel recevant une saisie utilisateur.

### En-tête de page

- `meta(array $meta): string` Émet un `<link>` quand l'entrée possède une clé `rel`, sinon un `<meta>`.
- `scripts(array $urls, bool $module = false): string` `$module` ajoute `type="module"`.
- `styles(array $urls): string`
- `jsVars(string $name, $value): string` Émet `<script>const NOM = <json>;</script>`.

Les quatre retournent `''` pour une liste vide.

```php
static::meta([
    ['name' => 'description', 'content' => 'Une page'],
    ['rel' => 'canonical', 'href' => '/x'],
]);

static::styles(['/app.css']);
static::scripts(['/app.js']);
static::scripts(['/app.mjs'], true);
static::jsVars('APP', ['locale' => 'fr']);
```

`meta()` ne teste que la présence de `rel` : une entrée destinée à un `<meta>` qui porte un `rel` devient silencieusement un `<link>`.

`jsVars()` encode avec `JSON_HEX_APOS`, et `json_encode` échappe `/` par défaut : un `</script>` présent dans une valeur ne peut donc pas fermer l'élément prématurément. Comme la déclaration émise est un `const`, appeler `jsVars()` deux fois avec le même nom produit une redéclaration JavaScript.

## PlainText

`Bredala\Template\PlainText` Écriture de texte brut, pour la sortie CLI. Toutes les méthodes **écrivent directement sur stdout** et retournent le singleton, ce qui permet de chaîner.

- `getInstance(): static`
- `add(string $text, mixed ...$items): static` Écrit le texte.
- `eol(int $nb = 1): static`
- `tab(int $nb = 1): static`
- `space(int $nb = 1): static`
- `repeat(string $str, int $nb = 1): static`

```php
PlainText::add('Import de %d lignes', $count)->eol();
PlainText::tab()->add('- terminé')->eol(2);
```

`add()` utilise `vprintf()` quand des arguments sont fournis, et un simple `print()` sinon. Un `%` littéral n'est donc sûr que dans la forme sans argument : `add('100% fait', $x)` détruit la sortie. Doubler les `%` dès qu'on passe des arguments.

`repeat()`, `eol()`, `tab()` et `space()` n'écrivent rien pour `$nb <= 0`, sans erreur.

Il n'y a pas de temporisation interne : pour capturer la sortie, encadrer les appels d'un `ob_start()`/`ob_get_clean()`.

## Utilisation

```php
use Bredala\Template\View;

// views/layout.phtml
// <!doctype html>
// <html>
// <head>
// <title><?= static::xss($title) ?></title>
// <?= static::meta($meta) ?>
// <?= static::styles($css) ?>
// </head>
// <body><?= $content ?></body>
// </html>

// views/user.phtml
// <h1><?= static::xss($name) ?></h1>
// <?= static::tag('a', ['href' => $url, 'class' => 'btn'], static::xss($label)) ?>

$content = View::create(__DIR__ . '/views/user.phtml', [
    'name' => 'Tom',
    'url' => '/profil',
    'label' => 'Voir le profil',
])->load();

echo View::create(__DIR__ . '/views/layout.phtml', [
    'title' => 'Profil',
    'content' => $content,
    'meta' => [['name' => 'description', 'content' => 'Profil utilisateur']],
    'css' => ['/app.css'],
])->load();
```

## Tests

```bash
composer install
vendor/bin/phpunit
```
