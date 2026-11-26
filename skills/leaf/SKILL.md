---
name: leaf
description: 'Use when working in a Zephyrus Leaf project, the static-site generator built on the Zephyrus PHP framework (leaf.ophelios.com). Triggers on a config.yml with a `leaf:` section, `zephyrus-framework/leaf-core` or `zephyrus-framework/leaf`, `Leaf\Kernel`, `BuildCommand`, `StaticSiteBuilder`, `PageDiscovery`, `LeafConfig`, `ContentLoader`, the `leaf init|dev|build|eject` CLI, or either project shape, Binary CLI (content/, templates/, public/, locale/, config.yml, no composer.json) or Composer (app/Controllers, an Application extending Leaf\Kernel, bin/build.php). Covers both tiers, template overrides, custom pages, the build pipeline and post_build hooks, multi-locale output (default locale at root, per-locale content and search index), sitemap, robots, canonical and hreflang, both dev servers and deployment, with verified traps (CLI v0.1.12, which embeds leaf-core v0.1.12 and zephyrus-framework/core v3.18.0): canonical URLs that all point home, a head with no hreflang or og:image, search URLs that already carry the locale prefix, a stale `dist/` that keeps an excluded page in the search index, hooks that run without a shell, and what to move by hand after eject (`templates/` into `app/Views/`).'
---

# Zephyrus Leaf

A static site generator for documentation, landings and blogs: Markdown
plus Latte templates in, plain HTML in `dist/` out. Every page is rendered
through a real Zephyrus application, then written to disk.

Verified against the leaf CLI **v0.1.12** (latest release), which embeds
leaf-core v0.1.12 (`3ac0ba1`), `zephyrus-framework/core` v3.18.0 and the
`zephyrus-framework/leaf` template v0.1.12 (`8b2fc2f`). Traps below are
checked with that binary and with a Composer project from `composer
create-project zephyrus-framework/leaf` (stable v0.1.12 of the template,
leaf-core v0.1.12 and core v3.18.0 resolved from Packagist). leaf-core and
the template are tagged since v0.1.9; there is no CLI v0.1.9. `composer show
zephyrus-framework/leaf-core` gives the version a Composer project runs.

**A site built on CLI v0.1.11 or older** (or a Composer project locked on
leaf-core v0.1.11) differs in these places: `search.json` entries carry no
`content`; a page excluded from the build stays in the index; there is no
`leaf.logo`; a multilingual site has no `/{locale}/404.html`; `composer dev`
answers a refused config with an empty 500. The theme changes (skip link,
search dialog, table frames, locale keys, Markdown links keeping the locale)
come with the CLI binary; a Composer project owns its theme copy, and
`composer update` brings leaf-core only. CLI v0.1.6 and older differ further;
the notes below name them.

**A site built on CLI v0.1.8 or older** (or a Composer project locked on
leaf-core before v0.1.10) behaves differently in places, and the notes
below say where: `supported_locales` is not validated; `$localePrefix`
is undefined in a template that does not set it, and so is
`$supportedLocales` without `locale_path`; the project's `locale/` is read
only through `locale_path`, and is not merged with the theme's catalogs;
the language switcher drops the base URL; `leaf eject` overwrites `locale/`.
leaf-core v0.1.9 builds the 404 of a site with no `localization` section with
a `TypeError` (HTTP 500, the build stops).

## Two tiers (know which one you're in)

Both tiers run leaf-core and the same bundled theme; the Composer tier's
leaf-core is whatever `composer update` resolved, so it can be ahead of
the binary's.

| Tier | Project shape | Build | Dev |
|------|---------------|-------|-----|
| **Binary CLI** (default) | `config.yml`, `content/`, `public/`, `locale/`, optional `templates/`; no `composer.json`, no `app/` | `leaf build` | `leaf dev` |
| **Composer** (PHP devs) | Full Zephyrus app: `app/Controllers/`, `app/Models/Core/Application.php` (extends `Leaf\Kernel`), `app/Views/`, `bin/build.php`, `bin/router.php`, `public/index.php`, `composer.json` | `composer build` | `composer dev` |

`composer.json` plus `app/` means Composer tier. Otherwise Binary tier.

**Composer tier details** (starting a project, `leaf eject` and what it
leaves to do by hand, `Application`, `bin/build.php`, `BuildCommand`,
`StaticSiteBuilder`, `ContentLoader`, `composer dev`): read
[`references/composer-tier.md`](references/composer-tier.md) when the
project has `composer.json`, or before running `leaf eject`.

## Install and CLI

```bash
curl -fsSL https://leaf.ophelios.com/install.sh | sh
```

The script installs the latest release to `/usr/local/bin`. Override with
`LEAF_VERSION=v0.1.12` (pin a tag) and `LEAF_PREFIX=<dir>`, set on `sh`:
`curl -fsSL https://leaf.ophelios.com/install.sh | LEAF_PREFIX="$HOME/bin" sh`.

On macOS take v0.1.7 or later: the darwin binaries of v0.1.6 carry no
`LC_UUID` and recent macOS refuses them (`missing LC_UUID load command`).

Installing needs no PHP. **Building does**: the binary shells out to the
`php` on `PATH` (8.4, with `intl`, `mbstring` and `tokenizer`; without
`tokenizer` every page fails with HTTP 500). FrankenPHP embedding is not
shipped.

```bash
leaf init [--force] <dir>        # scaffold a site into <dir> (use . for the current dir)
leaf dev [--dir .] [--addr :8080]
leaf build [--dir .] [--keep-tmp]
leaf eject [--dir .] [--force]   # convert to the Composer tier (one-way)
leaf version                     # also -v, --version
leaf help                        # also -h, --help
```

**Flags go before the positional argument.** Go's flag parser stops at the
first non-flag, so `leaf init --force .` and `leaf init -force .` work,
while `leaf init . --force` refuses with
`target <dir> is not empty (use --force to overwrite)`.

`leaf init` writes `config.yml`, three placeholder pages in
`content/getting-started/` (`introduction.md`, `writing-content.md`,
`deployment.md`), `locale/en/general.json` and `locale/fr/general.json`,
`public/assets/css/app.css`
(the design tokens, yours to edit) and six scripts in `public/assets/js/`
(`theme`, `sidebar`, `search`, `copy-code`, `lang-switcher`, `tables`; CLI
v0.1.11 wrote five, without `tables`). With
`--force` it overwrites only those files: `.git/`, `README.md` and
`LICENSE` survive. **Delete the placeholder pages** or they ship.

`leaf build` copies the bundled framework and your project into a temp dir
(`/tmp/leaf-build-*`), runs the PHP build there, then **replaces your
`dist/` wholesale**. A failed build leaves the previous `dist/` untouched.
`--keep-tmp` keeps the merged tree; the `Built ... -> /tmp/leaf-build-.../dist`
line names it, and its `app/Views/` holds the bundled templates to copy
from.

## config.yml

Same file on both tiers. The CLI refuses to build without `leaf.name`
(`leaf build: leaf.name is required`).

```yaml
application:
  environment: !env APP_ENV, dev
  debug: !env APP_DEBUG, true        # see "PHP warnings in built pages"

localization:
  locale: "en"                       # default locale, built at the root
  supported_locales:
    - "en"
  locale_path: "locale"              # empty: locale/ at the project root is read if it exists (CLI v0.1.11)
  timezone: "UTC"

render:                              # leave as scaffolded: templates/ maps onto app/Views
  engine: "latte"
  directory: "app/Views"
  cache: "cache/latte"
  mode: "always"

leaf:
  name: "My Project"                 # required by the CLI
  version: "1.0.0"                   # quote it: an unquoted 1.0 renders as "1"
  description: "A documentation site"
  logo: ""                           # "" or a path under public/, see below
  github_url: ""
  content_path: "content"
  sections:                          # sidebar order AND filter, see Content
    getting-started: "Getting Started"
  output_path: "dist"
  base_url: ""                       # path prefix when served from a subdirectory
  production_url: "https://example.com"   # no trailing slash; enables sitemap and robots
  author: ""
  author_url: ""
  license: "MIT"
  post_build:                        # optional, see Post-build hooks
    - "./scripts/after-build.sh"
```

The `leaf init` scaffold has no `production_url`: add it, or the build writes no `sitemap.xml` and no `robots.txt`.

Defaults when a key is missing: `name` "My Project", `version` "0.1.0",
`content_path` "content", `output_path` "dist", `license` "MIT", the
rest empty.

**`leaf.logo`** (v0.1.12) is `""` or a path under `public/` that starts with
a single `/` (`/assets/images/logo.png`); the bundled nav then shows that
image, behind `base_url`, instead of its SVG mark. A relative path, `//host`,
a URL, `..`, a space or a quote stops the build (exit 255, `dist/`
untouched): `Configuration section 'leaf' field 'logo' has invalid
value "assets/logo.png": an empty string, or a path under public/ that starts
with a single "/" and holds no "..", backslash, space, quote or control
character (such as "/assets/images/logo.png").`

Every template receives `$leafName`, `$leafVersion`, `$leafDescription`,
`$leafLogo`, `$leafGithubUrl`, `$leafAuthor`, `$leafAuthorUrl`, `$leafLicense`,
`$leafBaseUrl` and `$leafProductionUrl` (both without trailing slash). A
variable the controller passes under the same name wins.

`base_url: "/docs"` prefixes the bundled theme's links and assets, sidebar
and search URLs, and root-relative `href`/`src` in Markdown. The layout of
`dist/` does not change: deploy its content at `/docs`. Your own template
links need `{$leafBaseUrl}` by hand.

## Content

Pages live at `content/{section}/{slug}.md` and build to
`/{section}/{slug}/index.html`. Only that depth is scanned.

```markdown
---
title: "Introduction"
order: 1
description: "Used for the meta description"
---

Your content here.
```

- `order` sorts pages inside a section (default 99, ties by filename).
- Without `description`, the meta description is the first 160 characters
  of the page text, heading permalink `#` included. Set it.
- Markdown is CommonMark plus GFM (tables, strikethrough, autolinks, task
  lists). Raw HTML passes through, except the tags GFM disallows
  (`<script>`, `<style>`, `<iframe>`, `<textarea>`, `<title>`, ...), which
  come out escaped. `##` to `####` get an `id` from the heading text and a
  `#` permalink, and feed the page's table of contents.
- The sidebar and the previous/next links come from a scan of `content/`,
  not from any sidebar file.
- **`leaf.sections` orders and labels.** A section folder it does not list
  still builds, and shows in the sidebar, the previous/next chain, the
  search index and the sitemap, after the declared sections, alphabetically
  (labelled from the slug; CLI v0.1.6 hid it from the sidebar and the chain).
  Without `leaf.sections`, every section shows, alphabetically, labelled from
  the slug (`getting-started` becomes "Getting Started").
- **Underscores in section folder names work** (CLI v0.1.7 and later):
  `my_notes:` in `leaf.sections` matches `content/my_notes/`, with the
  configured label and position. Without `leaf.sections`, the label is
  "My Notes".
- **A page title taken from a file name reads `_` as a space**, search index
  included: without a `title` in the front matter, `Second_note.md` is
  "Second Note". The URL keeps the file name.
- Each heading permalink (`.heading-permalink`) has `tabindex="-1"`, so
  keyboard users do not tab through every heading anchor.

## Templates: overriding the bundled theme (Binary tier)

`templates/<path>` replaces `app/Views/<path>` of the bundled theme, file
by file. Only the CLI does this merge: on the Composer tier, edit
`app/Views/` directly (`composer build` ignores `templates/`).

- **The path must match exactly.** `templates/partial/nav.latte` (typo) is
  silently ignored and the bundled file ships. Change something visible and
  rebuild to prove an override took.
- **Latte only.** The engine renders `.latte`; a `templates/partials/nav.php`
  or `.html` is copied and never used.
- **An override replaces the whole file.** Start from the bundled copy
  (`leaf build --keep-tmp`, then copy from `<tmp>/app/Views/`).
- **`{include}` and `{layout}` paths resolve from the including file.**
  From `layouts/docs.latte`, write `{include '../partials/head.latte'}`;
  `{include 'partials/head.latte'}` looks in `layouts/partials/` and the
  page fails with HTTP 500.
- **`public/` layers the same way**: a file at a bundled path
  (`public/assets/css/app.css`, `public/assets/js/search.js`) replaces the
  bundled one, and deleting your copy brings the bundled one back.

Bundled files and what they receive:

| File | Used for | Variables (besides the globals) |
|------|----------|---------------------------------|
| `docs/page.latte` (layout `layouts/docs.latte`) | every Markdown page | `title`, `pageDescription`, `section`, `slug`, `content`, `sidebar`, `toc`, `prevPage`, `nextPage` |
| `landing.latte` (layout `layouts/landing.latte`) | `/` | `title` (= `leaf.name`), `firstPage` (the first page entry, or null; since v0.1.8) |
| `404.latte` | `/404`, written to `dist/404.html` (and `dist/{locale}/404.html`) | `title`, `firstPage` |
| `pages/{name}.latte` | custom pages | `title`, `pageTitle`, `pagePath` |
| `partials/head.latte`, `partials/nav.latte` | both layouts | inherited |
| `partials/sidebar.latte`, `toc.latte`, `search-modal.latte` | docs layout | inherited |
| `partials/footer.latte` | landing layout only | inherited |

The bundled theme loads Tailwind from its CDN (`cdn.tailwindcss.com`), Prism
from cdnjs and its fonts from Google Fonts, with an inline config mapped
to CSS variables (`--bg-deep`, `--bg-surface`, `--text`, `--accent`, ...)
defined in `public/assets/css/app.css`; `theme.js` toggles `data-theme`
on `<html>`. No bundler, no asset hashing.

**Document titles come from `partials/head.latte`** (v0.1.12):
`<title>`, `og:title` and `twitter:title` read `{$title} · {$leafName}`
with a middle dot, the site name alone on the landing page, and the 404
carries `noindex`. A custom page passes its bare name as `title`. CLI
v0.1.11 and older put an em-dash (U+2014) there, in `layouts/docs.latte` and
`head.latte`, and the 404 `<title>` had no site name: a project that bans
em-dashes must override both files on those versions.

**Skip link** (v0.1.12): both layouts start `<body>` with a `.skip-link` to
`#main-content` and render `<main id="main-content" tabindex="-1">`. A custom
page with its own `<main>` must give it `id="main-content"`: without it the
stylesheet (`body:not(:has(#main-content)) > .skip-link`) hides the link.

## Custom pages

`templates/pages/{name}.latte` builds to `/{name}/` (Composer tier:
`app/Views/pages/`).

```latte
{layout '../layouts/landing.latte'}
{block content}
<main id="main-content">About {$leafName}, served at {$pagePath}</main>
{/block}
```

- `.latte` only, no subdirectories.
- The name must match `[a-z0-9][a-z0-9-]*` (`2fa` is fine). Any other name
  (`About_Us.latte`) **fails the whole build**, single or multi-locale,
  with `Build failed: Page "About_Us.latte" cannot be served: ... Rename it
  to "about-us.latte".` on STDERR, exit 1.
- `templates/pages/404.latte` is shadowed by the `/404` route: override
  `templates/404.latte` instead.
- On a multi-locale site custom pages are built in every locale:
  `/extra/` and `/fr/extra/` (CLI v0.1.6 skipped them).

## Build pipeline

What `leaf build` and `composer build` run (`Leaf\BuildCommand::run()`),
in order:

1. Collect paths: every `GET` route without a `{parameter}`, every
   `content/*/*.md`, plus `addPaths()` (custom pages arrive this way).
   `/search.json` is always excluded; `/` too when no `GET /` route exists
   (the bundled theme has one, the landing page).
2. Render each path through the full application (middleware, controllers,
   templates). A 3xx becomes a meta-refresh page pointing at its
   `Location`, unchanged (not locale-prefixed). Any other non-200 is an
   error. Multi-locale: once per locale, default locale at the root.
3. Copy `public/` into the output (`index.php` and `.htaccess` skipped).
4. Any error: print `<path> returned HTTP <code>` (no exception detail),
   exit 1, stop.
5. Move each `404/index.html` to `404.html`: `/404.html`, and on a
   multi-locale site `/{locale}/404.html` too (v0.1.11 moved the default
   locale's only).
6. Write `search.json` (multi-locale: also `/{locale}/search.json`). Each
   entry has `title`, `section`, `url`, `excerpt`, `headings` and, since
   v0.1.12, `content`: the page's full text (entities decoded, heading
   anchors and the leading `<h1>` left out). A page excluded from the build
   is indexed only if its `<section>/<slug>/index.html` still sits in the
   output (see the stale `dist/` note below).
7. Single-locale without a `GET /` route: write `index.html` redirecting to
   the first page.
8. If `production_url` is set: `sitemap.xml` and `robots.txt`.
9. `leaf.post_build` hooks (Composer tier here; the CLI runs them later).
10. `onPostBuild()` callbacks (Composer tier).

**`excludePatterns()` and a stale `dist/`** (Composer tier; the CLI has no
exclusion option and replaces `dist/` wholesale). A page excluded from the
build is not rebuilt, but `composer build` never empties `dist/`: a page built
before the exclusion stays on disk **and in `search.json`** (3 entries after
excluding one of 3 pages on an existing `dist/`, 2 after `rm -rf dist`). Run
`rm -rf dist` before a build that must drop pages.

### PHP warnings in built pages

CLI v0.1.6 with `debug: true` (the scaffold default) wrote template warnings
into the built page (`Warning: ... in /tmp/leaf-build-.../cache/latte/...`)
while the build reported OK; v0.1.7 and later do not. Rebuild a v0.1.6 site
and check once: `grep -rl 'Warning:' dist/`. Build production output with
`APP_DEBUG=false` anyway.

## Post-build hooks

```yaml
leaf:
  post_build:
    - "./scripts/one.sh"                  # executable, no arguments
    - ["php", "scripts/two.php", "--fr"]  # argv form, no shell
```

- Both tiers. Each entry runs in order with the project root as working
  directory and the build's stdio; the first non-zero exit fails the build.
- **A hook runs without a shell, so on Windows a `.sh` script is not
  launchable as is**: name the interpreter first, `["bash", "./scripts/one.sh"]`
  (Git Bash), and likewise `["php", "scripts/two.php"]`. The CLI (v0.1.7 and
  later) says so: `leaf build: hook [./scripts/h.sh]: fork/exec ./scripts/h.sh:
  exec format error; hooks run without a shell, so name the interpreter first,
  for example ["bash", "./scripts/h.sh"]` (Windows: `not a valid Win32
  application`, not reverified). leaf-core does not add the hint.
- **Binary tier: hooks run after the new `dist/` is published**, from your
  real project root, so they can edit `dist/` (sitemap included). A
  failing hook exits non-zero but `dist/` is already replaced. `leaf dev`
  runs them on every rebuild.
- **A hook that writes into `public/` only reaches `dist/` on the next
  build**: `public/` was copied before hooks run. Write to `dist/` directly
  when the file must ship now.
- `LEAF_SKIP_HOOKS=1` makes leaf-core skip them (the CLI sets it, then runs
  them itself).

## Multi-locale

```yaml
localization:
  locale: "en"
  supported_locales: ["en", "fr"]
  locale_path: "locale"
```

- The default locale builds to the root (`dist/guide/intro/`), every other
  one under `/{locale}/` (`dist/fr/guide/intro/`). The default locale never
  has a prefix.
- **Every `supported_locales` entry becomes a path and a link**, so since CLI
  v0.1.10 it must be a code such as `fr` or `fr-ca` (2 or 3 letters, optional
  hyphenated subtags of 2 to 8 letters or digits); the core lowercases it first
  (`FR-CA` becomes `fr-ca` and builds to `/fr-ca/`). Anything else (`../x`,
  `/x.example`, `fr_CA`) is refused with a `ConfigurationException` that names
  the value (`fr_CA` shows as `"fr_ca"`, with the hint to use a hyphen, such as
  `fr-ca`): `leaf build` exits 255 and leaves `dist/` as it was. The default
  `locale` follows the same rule and, when `supported_locales` is not empty,
  must be one of them (`locale: "de"` with `["en", "fr"]` is refused too; CLI
  v0.1.8 built it, without a root `index.html`). On a refusal `leaf dev` exits
  at start, or, after a config edit, keeps serving the previous `dist/` and
  prints the refusal in the terminal; `composer dev` (`php -S`) answers every
  request 500 `text/plain` with the message, and prints it in the terminal
  (leaf-core v0.1.12; v0.1.11 sent an empty body). CLI v0.1.8 and older accept
  any value.
- **Content falls back per page.** For `fr`, `content/fr/{section}/{slug}.md`
  wins over `content/{section}/{slug}.md`; untranslated pages build from
  the default file. A section that exists only under `content/fr/` builds
  and shows in the French sidebar only. On a multi-locale site, folders
  named after a supported locale are never treated as sections.
- Templates get `$currentLocale`, `$defaultLocale`, `$supportedLocales` and
  `$localePrefix`, and the `localize()` function, alias `i18n()`, in the
  bundled templates and in overridden ones. Since CLI v0.1.10 they are always
  defined: `$supportedLocales` is the configured list (`["en"]` for
  `supported_locales: ["en"]`), `[]` when none is set, as with no
  `localization` section, and `$localePrefix` is `''` for the default locale or
  a single-locale site, `'/fr'` otherwise. A `{var $localePrefix}` in a template
  still wins. On CLI v0.1.8 and older `$localePrefix` is undefined in a template
  that does not set it, and `$supportedLocales` too when `locale_path` is empty:
  compute them first.

  ```latte
  {var $localePrefix = ($currentLocale ?? 'en') === ($defaultLocale ?? 'en') ? '' : '/' . $currentLocale}
  ```
- Strings: every `locale/{locale}/*.json` is merged into one tree (files
  read alphabetically, later wins). On the CLI since v0.1.10, your catalogs
  are merged key by key over the theme's, yours winning: define only the keys
  you change (`nav.theme`), the theme's other keys stay. With `locale_path`
  empty, CLI v0.1.11 reads `locale/` at the project root when it exists; v0.1.8
  did not, and `localize()` returned the key. `{localize('nav.home')}` reads
  `{"nav": {"home": "Home"}}`. Placeholders use braces:
  `"greet": "Hi {name}"` with `{localize('greet', ['name' => 'Ana'])}`.
  A key missing in `fr` falls back to the default locale's value, then to
  the key itself. The keys the theme added in v0.1.12 (`nav.label`, `nav.skip`,
  `pagination.label`, `table.scroll`, `table.scroll_caption`, `not_found.docs`,
  `search.close_text`) reach a project catalogue that lacks them through that
  merge: a French `general.json` holding only `nav.skip` still built every
  other label.
- The bundled nav already renders a language switcher when there is more
  than one locale: a button and a menu of links (`EN`, `FR`, each with
  `hreflang`), to the locale homes, which work without JavaScript.
  `lang-switcher.js` rewrites them to keep the current page, except on the
  404. Since v0.1.10 it keeps the base URL (v0.1.8 dropped it with
  `base_url: "/docs"`; read from the script, not run in a browser). Write your
  own only in an overridden nav.
- **A copy in your `public/` shadows the bundled script and stylesheet.** A
  project scaffolded by an older `leaf init` keeps its old `lang-switcher.js`,
  `app.css`, `search.js` and the others (all but `theme.js` differ between v0.1.11 and
  v0.1.12, and `tables.js` is new): diff them against `<tmp>/public/assets/` of `leaf build
  --keep-tmp`, or delete the copy you never edited.

Locale-aware links in your own templates:

```latte
<a href="{$localePrefix}/">Home</a>
<a href="{$localePrefix}/guide/intro">Guide</a>
```

Search and custom pages on multi-locale sites (CLI v0.1.12):

- **Custom pages and every `addPaths()` path are built in every locale**
  (see Custom pages).
- **Search is localized.** `/{locale}/search.json` lists that locale's pages
  with their full URLs, prefix included (`/fr/guide/intro`). The bundled
  `search.js` reads `<meta name="leaf-search-url">` first, then
  `leaf-base-url` plus `/search.json`; the head writes the page's own
  locale index there. Since v0.1.12 the search is a modal dialog with
  plain-text results, and a match found only in a page body opens the page at
  the word (`.../introduction/#:~:text=static`). **From v0.1.6:** its
  non-default `search.json` had no prefix, so a search script that adds one
  now yields `/fr/fr/guide/intro`, and a hook resolving
  `dirname(<search.json>) . url` misses the file: use `<dist> . url`.
- **Since CLI v0.1.8 the bundled theme keeps the locale**: on a non-default
  locale, the sidebar, previous/next, logo, landing and 404 links carry the
  prefix (`/fr/guide/intro`, `/docs/fr/guide/intro` with a base URL), and a
  page that exists only in `content/fr/` is linked. On v0.1.7 and earlier
  they had no prefix and left the locale.
- **An item's `url` (sidebar, `prevPage`, `nextPage`) has no locale
  prefix**: it is the base URL plus `/section/slug` (`/docs/guide/intro`).
  Putting the prefix in front of it works only with an empty base URL. An overridden partial builds its
  links like the bundled ones, with `$localePrefix`:
  `{$leafBaseUrl}{$localePrefix}/{$item['section']}/{$item['slug']}`.
  Overrides need no change on upgrade: a full copy of the v0.1.8 theme in
  `templates/` (its five `{var $localePrefix = ...}` lines included, or
  removed) still builds with v0.1.12, and the output differs from v0.1.11's
  only by the core's changes: `search.json` `content`, `/{locale}/404.html`
  and the `div.table-scroll` frame round Markdown tables. It keeps the v0.1.8
  markup, so it gets none of the theme's later changes (language switcher
  attributes, French labels, contrast, keyboard menus, skip link, search
  dialog).
- **A root-relative Markdown link to a doc page keeps the locale** (v0.1.12):
  on `content/fr/...` with `base_url: "/docs"`,
  `[x](/getting-started/deployment)` becomes
  `/docs/fr/getting-started/deployment`, `[home](/)` becomes `/docs/fr/`, and
  an explicit `/fr/...` is not doubled. Only existing doc pages and `/` get
  the prefix: assets, other paths and links to a missing page get the base URL
  alone. CLI v0.1.11 gave every one the base URL only (write `/fr/...` in the
  French file there). The rule lives in the template's `DocsController`, so a
  Composer project keeps its own copy until it takes the new one.
- `composer dev` serves `/{locale}/...` and `content/{locale}/` for a declared
  non-default locale (`/fr-ca/...` too), like the build (leaf-core v0.1.9 and
  later; older dev servers did not, not reverified). Any other first segment (`/ab/x`,
  or the default locale's own `/en/x`) is not stripped: it is a 404, as in
  `dist/`. Since v0.1.12 its `/fr/search.json` lists the French pages with
  `/fr/` URLs, like the build and `leaf dev` (v0.1.11 listed the default
  locale's, unprefixed), and a miss under `/fr/` renders the French 404.
- **A miss under a locale: each locale has its own `404.html`** (v0.1.12:
  `dist/fr/404.html`; v0.1.11 wrote `dist/fr/404/index.html`, which no host
  picks). A host that serves only the root `404.html` (GitHub Pages) sends
  `/fr/nope/` the English page, so the 404 template carries a script that
  redirects a URL under a declared locale to `/{locale}/404.html`. Behind an
  nginx set up like Pages (`error_page 404 /404.html`), a headless Chrome on
  `/fr/nope/` and `/fr/a/b/c` ended on the French `/fr/404.html`; `/nope/` and
  `/xx/nope/` stayed on the English page. A project that overrides `404.latte`
  does not get the script: copy it from the bundled file. `leaf dev` serves
  the root `404.html` for `/fr/nope` (English, no redirect).

## SEO

**`sitemap.xml` and `robots.txt` exist only when `production_url` is set.**

- `robots.txt` is `User-agent: *`, `Allow: /` and
  `Sitemap: <production_url>/sitemap.xml`, with a single slash even when
  `production_url` ends with one (CLI v0.1.6 wrote `//sitemap.xml`). Keep
  the value without a trailing slash anyway.
- The sitemap lists every built page with a trailing slash, multi-locale
  with `xhtml:link` hreflang alternates and `x-default`. It lists no 404
  page, and each locale's own pages (a page that exists only in
  `content/fr/` is listed under `/fr/`). v0.1.6 listed `/404/` and repeated
  the first locale's pages for every locale: remove a `post_build` hook that
  patched the sitemap for either.
- **The bundled head has no `<link rel="canonical">`, no hreflang, no
  `og:image`.** Add them in an overridden `templates/partials/head.latte`.
  **Nothing sets `$requestPath` on the Binary tier**: a canonical built
  from `$requestPath ?? '/'` points every page at the home URL. Build the
  path from what the bundled controllers do pass, `$section` and `$slug`
  on Markdown pages, `$pagePath` on custom pages:

```latte
{var $dl = $defaultLocale ?? 'en'}
{var $prefix = ($currentLocale ?? $dl) === $dl ? '' : '/' . $currentLocale}
{var $path = rtrim($requestPath ?? (isset($section, $slug) ? '/' . $section . '/' . $slug : ($pagePath ?? '')), '/')}
<link n:if="$leafProductionUrl !== ''" rel="canonical" href="{$leafProductionUrl}{$prefix}{$path}/">
{if $leafProductionUrl !== ''}
{foreach $supportedLocales ?? [] as $loc}
<link rel="alternate" hreflang="{$loc}" href="{$leafProductionUrl}{$loc === $dl ? '' : '/' . $loc}{$path}/">
{/foreach}
<link rel="alternate" hreflang="x-default" href="{$leafProductionUrl}{$path}/">
<meta property="og:image" content="{$leafProductionUrl}/assets/og/og-default.png">
{/if}
```

  It yields `https://example.com/fr/guide/intro/` on the French guide page
  and `https://example.com/` on the landing. Put the 1200x630 image at
  `public/assets/og/og-default.png`. The 404 page canonicalises to the
  home URL and a locale-only page lists alternates that do not exist: guard
  those if it matters. A Composer-tier controller can pass `requestPath`,
  which the snippet prefers.

## Dev servers

**`leaf dev`** builds once, then serves `dist/` from a Go server
(`--addr`, default `:8080`) and injects a live-reload script (Server-Sent
Events on `/__leaf/reload`) into every HTML response; the files on disk
stay clean. It rebuilds, debounced 250 ms, on changes under `content/`,
`templates/`, `public/`, `app/` and `config.yml`.

- **Not `locale/`**: after editing translations, touch `config.yml` or
  restart (CLI v0.1.11: `touch config.yml` rebuilds and the new strings show).
- It builds once at start: a refused config makes it exit. A rebuild that
  fails later keeps serving the previous `dist/` and prints
  `leaf dev: build exited 255` with the error.
- Only directories that exist when it starts are watched: create
  `templates/` before starting, or restart.
- It writes the real `dist/` and runs `post_build` hooks on every rebuild.
- `DEV_SERVER` is not defined here (that constant belongs to `composer dev`).

**`composer dev`** (Composer tier) is PHP's built-in server with
`Leaf\DevRouter`, rendering pages live; see the reference file.

## Deploying

`dist/` is plain static files for any static host. `404.html` sits at the
root, where most hosts look for it (a multi-locale site also has
`/{locale}/404.html`; a host that serves only the root one gets the redirect
script of the bundled 404, see Multi-locale). Two proven patterns:

- **Commit `dist/`** and let the host deploy the branch. Rebuild before
  every commit.
- **Build in CI with a pinned release** (the public
  [zephyrus-site](https://github.com/zephyrus-framework/site) builds
  this way for GitHub Pages):

```bash
LEAF_VERSION=v0.1.12
curl -fsSL "https://github.com/zephyrus-framework/leaf-cli/releases/download/${LEAF_VERSION}/leaf-linux-amd64" -o "$RUNNER_TEMP/leaf"
echo "27e403aa8f5e9ab18c50e68d377e56e13dbd39f6c9f2105b3ab630ec9f16b5ec  $RUNNER_TEMP/leaf" | sha256sum --check --strict
chmod +x "$RUNNER_TEMP/leaf"
APP_DEBUG=false "$RUNNER_TEMP/leaf" build
```

  The checksum is the v0.1.12 `leaf-linux-amd64` line of the release's
  `checksums.txt`; take the new one when you bump the version.

## Key rules

- **Identify the tier first.** Binary: `leaf init|dev|build|eject`, edit
  `content/`, `templates/`, `public/`, `locale/`, `config.yml`. Composer:
  `composer dev|build`, edit `app/` and `bin/build.php`.
- **Flags before the positional argument** (`leaf init --force .`).
- **Overrides: exact path, `.latte` only, includes relative to the
  including file**; prove each one by a visible change.
- **Never build canonical URLs from `$requestPath` alone**: use the
  `$section`/`$slug`/`$pagePath` snippet above.
- **`production_url` without trailing slash** turns on sitemap and robots.
- **Search URLs in a non-default locale already carry the prefix** (since CLI
  v0.1.7): never prefix them again.
- **Build production with `APP_DEBUG=false`.**
- **Post-build steps go in `leaf.post_build`** on either tier; write to
  `dist/` when the output must ship in the same build.
- **`dist/` is the artifact.** `leaf build` replaces it; `composer build`
  never cleans it.

## References

- Docs: https://leaf.ophelios.com
- CLI: https://github.com/zephyrus-framework/leaf-cli (releases carry
  the binaries and `checksums.txt`)
- Core library: https://github.com/zephyrus-framework/leaf-core
- Composer template: https://github.com/zephyrus-framework/leaf
- A real multi-locale Binary-tier site with SEO overrides and a sitemap
  hook: https://github.com/zephyrus-framework/site
- In this repository: `examples/leaf/`, a minimal Binary-tier site with
  the layout and head overrides above (no em-dash, canonical, hreflang).
