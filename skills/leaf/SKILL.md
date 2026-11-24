---
name: leaf
description: Use when working in a Zephyrus Leaf project, the static-site generator built on the Zephyrus PHP framework (leaf.ophelios.com). Triggers on a config.yml with a `leaf:` section, `zephyrus-framework/leaf-core` or `zephyrus-framework/leaf`, `Leaf\Kernel`, `BuildCommand`, `StaticSiteBuilder`, `PageDiscovery`, `LeafConfig`, `ContentLoader`, the `leaf init|dev|build|eject` CLI, or either project shape, Binary CLI (content/, templates/, public/, locale/, config.yml, no composer.json) or Composer (app/Controllers, an Application extending Leaf\Kernel, bin/build.php). Covers both tiers, template overrides, custom pages, the build pipeline and post_build hooks, multi-locale output (default locale at root, per-locale content and search index), sitemap, robots, canonical and hreflang, both dev servers and deployment, with verified traps (CLI v0.1.8): canonical URLs that all point home, a head with no hreflang or og:image, search URLs that already carry the locale prefix, hooks that run without a shell, and what to move by hand after eject (`templates/` into `app/Views/`).
---

# Zephyrus Leaf

A static site generator for documentation, landings and blogs: Markdown
plus Latte templates in, plain HTML in `dist/` out. Every page is rendered
through a real Zephyrus application, then written to disk.

Verified against the leaf CLI **v0.1.8** (latest release), which embeds
leaf-core `6caeb37` (`main`), `zephyrus-framework/core` v3.18.0 and the
`zephyrus-framework/leaf` template `2367a24` (`main`). Traps below are
checked with that binary; the Composer tier runs the same leaf-core and
template when `composer update` resolves those commits. leaf-core has no
tags; Packagist serves only `dev-main`. `composer show
zephyrus-framework/leaf-core` gives the commit a Composer project runs.

**A site built on CLI v0.1.6 or older** (or a Composer project locked
before `1a2c175`) behaves differently in places, and the migration notes
below say where: a non-default locale's `search.json` now carries the
locale prefix in its URLs; custom pages and `addPaths()` routes are built
on multi-locale sites; undeclared sections show in the sidebar; the
sitemap drops `/404/`; `robots.txt` no longer doubles a trailing slash.

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
`LEAF_VERSION=v0.1.8` (pin a tag) and `LEAF_PREFIX=<dir>`, set on `sh`:
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
`deployment.md`), `locale/en/general.json`, `public/assets/css/app.css`
(the design tokens, yours to edit) and five scripts in `public/assets/js/`
(`theme`, `sidebar`, `search`, `copy-code`, `lang-switcher`). With
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
  locale_path: "locale"              # empty disables localize() and $currentLocale
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

Every template receives `$leafName`, `$leafVersion`, `$leafDescription`,
`$leafGithubUrl`, `$leafAuthor`, `$leafAuthorUrl`, `$leafLicense`,
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
  still builds, and since CLI v0.1.7 shows in the sidebar, the
  previous/next chain, the search index and the sitemap, after the declared
  sections, alphabetically (labelled from the slug). CLI v0.1.6 hid it from
  the sidebar and the chain. Without `leaf.sections`, every section shows,
  alphabetically, labelled from the slug (`getting-started` becomes
  "Getting Started").
- **Underscores in section folder names work since CLI v0.1.7.** `my_notes:`
  in `leaf.sections` matches `content/my_notes/`, with the configured label
  and position (CLI v0.1.6 ignored the key, because config keys are
  camelCased on load: hyphenate the folder on that version). Without
  `leaf.sections`, the label is "My Notes".
- **A page title taken from a file name reads `_` as a space**, search index
  included: without a `title` in the front matter, `Second_note.md` is
  "Second Note" (CLI v0.1.6: "Second_note" in the index). The URL keeps the
  file name.
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
| `404.latte` | `/404`, written to `dist/404.html` | `title` |
| `pages/{name}.latte` | custom pages | `title`, `pageTitle`, `pagePath` |
| `partials/head.latte`, `partials/nav.latte` | both layouts | inherited |
| `partials/sidebar.latte`, `toc.latte`, `search-modal.latte` | docs layout | inherited |
| `partials/footer.latte` | landing layout only | inherited |

The bundled theme loads Tailwind from its CDN with an inline config mapped
to CSS variables (`--bg-deep`, `--bg-surface`, `--text`, `--accent`, ...)
defined in `public/assets/css/app.css`; `theme.js` toggles `data-theme`
on `<html>`. No bundler, no asset hashing.

**The bundled titles use an em-dash** (U+2014) between `{$title}` and
`{$leafName}`: in `<title>` in `layouts/docs.latte`, and in `og:title` and
`twitter:title` in `partials/head.latte`. A project that bans em-dashes
must override both files and swap the separator (a middle dot `·` or a
colon).

## Custom pages

`templates/pages/{name}.latte` builds to `/{name}/` (Composer tier:
`app/Views/pages/`).

```latte
{layout '../layouts/landing.latte'}
{block content}
<main>About {$leafName}, served at {$pagePath}</main>
{/block}
```

- `.latte` only, no subdirectories.
- The name must match `[a-z0-9][a-z0-9-]*` (`2fa` is fine). Any other name
  (`About_Us.latte`) **fails the whole build**, single or multi-locale,
  with `Build failed: Page "About_Us.latte" cannot be served: ... Rename it
  to "about-us.latte".` on STDERR, exit 1 (CLI v0.1.6: `/About_Us returned
  HTTP 404`, and nothing at all on a multi-locale site).
- `templates/pages/404.latte` is shadowed by the `/404` route: override
  `templates/404.latte` instead.
- On a multi-locale site custom pages are built in every locale:
  `/extra/` and `/fr/extra/`. CLI v0.1.6 skipped them silently (and did not
  report a badly named one): a site built on it must rebuild to get them.

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
5. Move `/404/index.html` to `/404.html` (default locale only; other locales
   keep `/{locale}/404/index.html`).
6. Write `search.json` (multi-locale: also `/{locale}/search.json`).
7. Single-locale without a `GET /` route: write `index.html` redirecting to
   the first page.
8. If `production_url` is set: `sitemap.xml` and `robots.txt`.
9. `leaf.post_build` hooks (Composer tier here; the CLI runs them later).
10. `onPostBuild()` callbacks (Composer tier).

### PHP warnings in built pages

CLI v0.1.6 with `debug: true` (the scaffold default) wrote a template
warning (an undefined variable, a property read on null) **into the built
page** as `Warning: ... in /tmp/leaf-build-.../cache/latte/...` while the
build reported OK. CLI v0.1.7 builds the same templates with no warning in
the page, in either debug mode. A site built on v0.1.6 should be rebuilt
and checked once: `grep -rl 'Warning:' dist/`. Keep building production
output with `APP_DEBUG=false leaf build` anyway.

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
  (Git Bash), and likewise `["php", "scripts/two.php"]`. CLI v0.1.7 says so
  when the system cannot execute the hook: `leaf build: hook [./scripts/h.sh]:
  fork/exec ./scripts/h.sh: exec format error; hooks run without a shell, so
  name the interpreter first, for example ["bash", "./scripts/h.sh"]` (on
  Windows the cause reads `not a valid Win32 application`; not reverified
  there). leaf-core does not add the hint.
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
- **Every `supported_locales` entry becomes a path and a link**, so it must be a
  lowercase code such as `fr` or `fr-ca` (2 or 3 letters, optional hyphenated
  subtags). Anything else (`../x`, `/x.example`, `FR`) stops `leaf build` and
  the dev server at startup with a `ConfigurationException`; nothing is written.
- **Content falls back per page.** For `fr`, `content/fr/{section}/{slug}.md`
  wins over `content/{section}/{slug}.md`; untranslated pages build from
  the default file. A section that exists only under `content/fr/` builds
  and shows in the French sidebar only. On a multi-locale site, folders
  named after a supported locale are never treated as sections.
- Templates get `$currentLocale`, `$defaultLocale` and `$supportedLocales`
  (only when `locale_path` is set) and the `localize()` function, alias
  `i18n()`. `$localePrefix` is always defined: `''` for the default locale, a
  single-locale site or no `locale_path`, `'/fr'` otherwise; a variable the
  render passes, or a `{var $localePrefix}` in the template, wins.
- Strings: every `locale/{locale}/*.json` is merged into one tree (files
  read alphabetically, later wins). `{localize('nav.home')}` reads
  `{"nav": {"home": "Home"}}`. Placeholders use braces:
  `"greet": "Hi {name}"` with `{localize('greet', ['name' => 'Ana'])}`.
  A key missing in `fr` falls back to the default locale's value, then to
  the key itself.
- The bundled nav already renders a language switcher when there is more
  than one locale, and `lang-switcher.js` rewrites its links to keep the
  current page. Write your own only in an overridden nav.

Locale-aware links in your own templates:

```latte
<a href="{$localePrefix}/">Home</a>
<a href="{$localePrefix}/guide/intro">Guide</a>
```

Search and custom pages on multi-locale sites (CLI v0.1.8):

- **Custom pages and every `addPaths()` path are built in every locale**
  (see Custom pages). CLI v0.1.6 built none of them.
- **Search is localized.** `/{locale}/search.json` lists that locale's pages
  with their full URLs, prefix included (`/fr/guide/intro`). The bundled
  `search.js` reads `<meta name="leaf-search-url">` first, then
  `leaf-base-url` plus `/search.json`; the head writes the page's own
  locale index there. **Migration from v0.1.6:** its non-default
  `search.json` wrote `/guide/intro` with no prefix. A site search script
  that adds the locale prefix now produces `/fr/fr/guide/intro`, and a hook
  that resolves `dirname(<search.json>) . url` points at a file that does
  not exist: use `<dist> . url`, and `url + '/'` as the link.
- **Since CLI v0.1.8 the bundled theme keeps the locale**: on a non-default
  locale, the sidebar, previous/next, logo, landing and 404 links carry the
  prefix (`/fr/guide/intro`, `/docs/fr/guide/intro` with a base URL), and a
  page that exists only in `content/fr/` is linked. On v0.1.7 and earlier
  they had no prefix and left the locale.
- **An item's `url` (sidebar, `prevPage`, `nextPage`) has no locale
  prefix**: it is the base URL plus `/section/slug` (`/docs/guide/intro`).
  Putting the prefix in front of it works only with an empty base URL
  (`/fr/docs/guide/intro` otherwise). An overridden partial builds its
  links like the bundled ones, with `$localePrefix`:
  `{$leafBaseUrl}{$localePrefix}/{$item['section']}/{$item['slug']}`.
  Overrides need no change on upgrade: they replace the bundled partial
  whole, and `url`, `section` and `slug` did not change.
- `composer dev` serves `/{locale}/...` and `content/{locale}/` for a
  declared non-default locale, like the build. Any other first segment
  (`/ab/x`, or the default locale's own `/en/x`) is not stripped: it is a 404,
  as in `dist/`.

## SEO

**`sitemap.xml` and `robots.txt` exist only when `production_url` is set.**

- `robots.txt` is `User-agent: *`, `Allow: /` and
  `Sitemap: <production_url>/sitemap.xml`, with a single slash even when
  `production_url` ends with one (CLI v0.1.6 wrote `//sitemap.xml`). Keep
  the value without a trailing slash anyway.
- The sitemap lists every built page with a trailing slash, multi-locale
  with `xhtml:link` hreflang alternates and `x-default`. It lists no 404
  page, and each locale's own pages (a page that exists only in
  `content/fr/` is listed under `/fr/`). **Migration from v0.1.6:** that
  version listed `/404/` and `/{locale}/404/` (dead URLs once moved to
  `/404.html`) and repeated the first locale's page set for every locale;
  a `post_build` hook that patched the sitemap for either is now a no-op
  to remove.
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
  restart.
- Only directories that exist when it starts are watched: create
  `templates/` before starting, or restart.
- It writes the real `dist/` and runs `post_build` hooks on every rebuild.
- `DEV_SERVER` is not defined here (that constant belongs to `composer dev`).

**`composer dev`** (Composer tier) is PHP's built-in server with
`Leaf\DevRouter`, rendering pages live; see the reference file.

## Deploying

`dist/` is plain static files for any static host. `404.html` sits at the
root, where most hosts look for it. Two proven patterns:

- **Commit `dist/`** and let the host deploy the branch (leaf.ophelios.com
  runs this way on DigitalOcean App Platform). Rebuild before every commit.
- **Build in CI with a pinned release** (the public
  [zephyrus-site](https://github.com/zephyrus-framework/site) builds
  this way for GitHub Pages):

```bash
LEAF_VERSION=v0.1.8
curl -fsSL "https://github.com/zephyrus-framework/leaf-cli/releases/download/${LEAF_VERSION}/leaf-linux-amd64" -o "$RUNNER_TEMP/leaf"
echo "3d47d23c1a1d8327be130170dc63b1adfdd4d2e5461c7fa4cbce8c8768d47972  $RUNNER_TEMP/leaf" | sha256sum --check --strict
chmod +x "$RUNNER_TEMP/leaf"
APP_DEBUG=false "$RUNNER_TEMP/leaf" build
```

  The checksum is the v0.1.8 `leaf-linux-amd64` line of the release's
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
