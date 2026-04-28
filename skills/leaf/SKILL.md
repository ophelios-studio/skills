---
name: leaf
description: Use when working in a Zephyrus Leaf project — the static-site generator built on the Zephyrus PHP framework that powers leaf.ophelios.com itself. Triggers on projects with a `leaf:` section in `config.yml`, references to `zephyrus-framework/leaf-core`, `Leaf\Kernel`, `BuildCommand`, `StaticSiteBuilder`, `LeafConfig`, `ContentLoader`, the `leaf init|dev|build|eject` CLI commands, or a project shape matching either tier (Binary CLI: bare `content/` + `templates/` + `public/` + `config.yml` with no `app/` or `vendor/`; Composer: full Zephyrus app extending `Leaf\Kernel`). Covers both tiers, the BuildCommand pipeline, multi-locale URL semantics with default-locale-no-prefix, per-locale search indexes, automatic sitemap/robots/hreflang generation, the `DEV_SERVER` constant for live-reload templates, and empirical patterns from the production `zephyrus-leaf-site` (post-build hook timing quirk, exact-match template overrides, dist-committed-to-git deploy pattern, Tailwind-CDN-with-CSS-vars theming).
---

# Zephyrus Leaf

A static site generator for documentation, landings, and blogs.
Authored Markdown + optional Latte/PHP/HTML templates → deployable static
HTML in one command. The framework's own docs site at
**[leaf.ophelios.com](https://leaf.ophelios.com)** is itself built with
Leaf — so empirical patterns here are drawn directly from the production
project at `zephyrus-leaf-site`.

## Two distributions (know which one you're in)

Leaf ships two tiers that share the same `zephyrus-framework/leaf-core`
library:

| Tier | Project shape | Build command | Dev command |
|------|--------------|---------------|-------------|
| **Binary CLI** (recommended for most) | `content/`, `templates/` (optional overrides), `public/`, `config.yml` — no `app/`, `bin/`, `vendor/`, `composer.json` | `leaf build` | `leaf dev` |
| **Composer template** (PHP devs) | Full Zephyrus project: `app/Controllers`, `app/Models/Core/Application.php` (extends `Leaf\Kernel`), `bin/build.php`, `vendor/`, `composer.json` | `composer build` | `composer dev` |

**How to tell which one you're in:** if the project has `app/Controllers/`
and `composer.json`, it's Composer tier. If it has only `content/`,
`templates/`, `public/`, and `config.yml`, it's Binary tier.

**Migration path:** Binary-tier user runs `leaf eject` to convert to
Composer-tier. One-way. `content/`, `templates/`, `public/`, `config.yml`
survive; `app/`, `bin/`, `composer.json` are written fresh.

## Binary CLI commands

```bash
leaf init <name>      # Scaffold a new site (content/, public/, config.yml, locale/)
leaf dev [--addr]     # Serve with live reload (default :8080, watches content/templates/public/config)
leaf build [--dir]    # Render to dist/
leaf eject            # Convert to Composer tier (writes framework files into cwd)
leaf version
leaf help
```

Install:

```bash
curl -fsSL https://leaf.ophelios.com/install.sh | sh
```

The binary embeds the framework and scaffolds. **No PHP or Composer
required at install time.** At **build time** the binary shells out to
system `php` (≥ 8.4 with `intl`, `mbstring`, `sodium`, `pdo`) — the
"zero-dependency" claim refers to install only, not build. FrankenPHP
static link is on the roadmap; not shipped yet.

## Application Bootstrap (Composer tier only)

Binary-tier users don't see this — it lives inside the binary.
Composer-tier projects extend `Leaf\Kernel`:

```php
use Leaf\Kernel;

final class Application extends Kernel
{
    protected function createController(string $class): object
    {
        if ($class === MyController::class) {
            return new MyController($this->contentLoader, $this->leafConfig);
        }
        return new $class();
    }
}
```

### Kernel overridable methods

- `createController(string $class): object` — DI for controllers
- `registerControllers(Router $router): Router` — customize discovery (default: scans `App\Controllers`)

### Kernel protected properties (available in `createController`)

`$this->config`, `$this->leafConfig`, `$this->renderEngine`,
`$this->contentLoader`, `$this->searchIndexBuilder`,
`$this->markdownParser`, `$this->translator` (null if no localization),
`$this->translationExtension` (null if no localization).

## Template override pattern (the override surface for both tiers)

Both tiers support user overrides via a `templates/` directory at the
project root. Drop a file at `templates/layouts/docs.latte` or
`templates/partials/nav.latte` and the build merges it on top of the
bundled theme. Binary-tier users rely on this for customization — they
can't edit `app/Views/` directly. File formats: `.latte`, `.php`, `.html`
(HTML is copy-through, no variable interpolation).

### Critical empirical gotcha — exact path match required

Bundle has `app/Views/partials/nav.latte` → override is
`templates/partials/nav.latte`.
Bundle has `app/Views/layouts/docs.latte` → override is
`templates/layouts/docs.latte`.

**Mismatch (typo, wrong subdir, dropped slash) means the override is
silently ignored** and the bundled default ships. No warning, no log.
Verify your override took effect by changing something visible in the
file and rebuilding. If `dist/` doesn't reflect the change, your path is
wrong.

## Building static sites

### `leaf build` (Binary tier)

Default behavior. No PHP code to write. The binary merges embedded
defaults + user project into a tempdir, runs the standard pipeline,
copies `dist/` back out.

### `BuildCommand` (Composer tier)

`bin/build.php` is the file the user owns:

```php
define('ROOT_DIR', dirname(__DIR__));
require ROOT_DIR . '/vendor/autoload.php';

use App\Models\Core\Application;
use Leaf\BuildCommand;

$app = new Application();
$command = new BuildCommand($app);

// Add parameterized routes not discoverable from the router
$command->addPaths(['/blog', '/blog/my-post']);

// Custom post-build steps
$command->onPostBuild(function ($result, $outputDir) {
    passthru('node bin/generate-og-images.js');
});

exit($command->run());
```

### BuildCommand pipeline (10 steps)

1. Create `StaticSiteBuilder` from Kernel's Application + Router.
2. Configure multi-locale if `count(supportedLocales) > 1`.
3. Discover doc content paths from `{contentPath}/*/*.md`.
4. Add caller-provided paths (`addPaths()`).
5. Exclude `/search.json` always. Exclude `/` only if no `GET /` route exists.
6. Build all pages (render each route through the full app stack). **3xx responses are serialized as meta-refresh HTML** — redirects work in static output.
7. Move `/404/index.html` to `/404.html`.
8. Generate search index JSON.
9. Generate root redirect (single-locale only, when no custom `GET /` route).
10. Generate `sitemap.xml` and `robots.txt` (only if `production_url` is set).
11. Run `onPostBuild` callbacks.

### Empirical post-build hook timing quirk

If your post-build hook writes to `public/` (e.g., a generated OG image),
**the file lands in `dist/` only on the NEXT build.** The public→dist
copy already happened during the pipeline; the hook fires after that
copy. Production workaround in `zephyrus-leaf-site/scripts/generate-og-image.sh`:
write directly to `dist/assets/...` if you need it visible in the
current build, or accept the off-by-one if it's a low-churn asset and
let the next build pick it up.

### BuildCommand API

- `addPaths(array $paths): void` — Add paths for parameterized routes
- `excludePatterns(array $patterns): void` — Exclude paths by regex
- `onPostBuild(callable $callback): void` — Hook after pipeline, receives `(StaticBuildResult $result, string $outputDir)`
- `run(): int` — Execute, returns 0 on success, 1 on error

### `StaticSiteBuilder` (lower-level)

```php
$builder = new StaticSiteBuilder($application, $router);
$builder->setOutputDirectory($outputDir);
$builder->setPublicDirectory(ROOT_DIR . '/public');
$builder->setBaseUrl('http://localhost');
$builder->setLocales(['en', 'fr'], 'en');
$builder->addPaths(['/blog/post-1']);
$builder->excludePatterns(['#^/api/#']);
$result = $builder->build();
```

## Multi-locale builds

**Scope:** Leaf's multi-locale is **string-level** (JSON translation
files consumed via `localize()` in templates). Per-locale Markdown
content can be added via `content/{locale}/...` directories — the
non-default locale sees the union of `content/{locale}/...` + fallback
to `content/...`.

### URL semantics

- **Default locale builds to root**: `dist/index.html`, `dist/blog/`, …
- **Other locales build to subdirectories**: `dist/fr/`, `dist/ar/`, …
- The default locale has **no URL prefix**. This affects internal-link
  patterns and the language-switcher JS.

### Configuration

```yaml
localization:
  locale: "en"
  supported_locales:
    - "en"
    - "fr"
    - "ar"
  locale_path: "locale"
```

### Template variables

The `TranslationLatteExtension` injects into every template:

- `{$currentLocale}` — Active locale code
- `{$defaultLocale}` — Default locale from config
- `{$supportedLocales}` — Array of all supported locales

### Locale-aware internal links

```latte
{var $dl = $defaultLocale ?? 'en'}
{var $localePrefix = ($currentLocale ?? $dl) === $dl ? '' : '/' . $currentLocale}

<a href="{$localePrefix}/">Home</a>
<a href="{$localePrefix}/blog">Blog</a>
```

### Language switcher

```latte
{foreach $supportedLocales as $loc}
    {if $loc === $currentLocale}
        <span class="current">{$loc|upper}</span>
    {else}
        <a href="{$loc === $dl ? '/' : '/' . $loc . '/'}" class="lang-switch" data-locale="{$loc}" data-default="{$dl}">{$loc|upper}</a>
    {/if}
{/foreach}
```

JS to preserve current path when switching:

```javascript
var langSwitches = document.querySelectorAll('.lang-switch[data-locale]');
if (langSwitches.length) {
    var path = window.location.pathname;
    var defaultLang = langSwitches[0].getAttribute('data-default') || 'en';
    var stripped = path.replace(/^\/(en|fr|ar)(\/|$)/, '/');
    langSwitches.forEach(function (link) {
        var loc = link.getAttribute('data-locale');
        link.href = loc === defaultLang
            ? (stripped === '/' ? '/' : stripped)
            : '/' + loc + (stripped === '/' ? '/' : stripped);
    });
}
```

### Translation files

JSON in `locale/{lang}/*.json` (every file in the directory is merged
into one namespace):

```json
{
    "nav":  { "home": "Home", "about": "About" },
    "hero": { "title": "Welcome" }
}
```

Usage: `{localize('nav.home')}`, `{localize('hero.title')}`. `i18n()`
is an alias for `localize()`. Missing keys fall back to the key itself.

### Per-locale search index (empirical)

The build generates one `search.json` per locale:

- Default: `/search.json` (default-locale pages)
- Non-default: `/{locale}/search.json` (locale-specific + fallback pages)

The frontend loads the locale-specific one based on the current URL
prefix. Don't try to merge them into a single index — the per-locale
fallback semantics belong on the build side.

## SEO

### Sitemap & robots — automatic, conditional on `production_url`

Setting `production_url` in config.yml is the trigger. Without it,
neither `sitemap.xml` nor `robots.txt` is generated. The sitemap
includes `xhtml:link` hreflang alternates for multi-locale sites
automatically.

### Hreflang tags in templates

```latte
{var $canonicalBase = $leafProductionUrl ?: 'https://example.com'}
{var $dl = $defaultLocale ?? 'en'}
{var $pagePath = $requestPath ?? '/'}
{var $pageSuffix = $pagePath === '/' ? '/' : rtrim($pagePath, '/') . '/'}
{var $curLocale = $currentLocale ?? $dl}

<link rel="canonical" href="{$canonicalBase}{$curLocale === $dl ? '' : '/' . $curLocale}{$pageSuffix}">
{foreach $supportedLocales as $loc}
<link rel="alternate" hreflang="{$loc}" href="{$canonicalBase}{$loc === $dl ? '' : '/' . $loc}{$pageSuffix}">
{/foreach}
<link rel="alternate" hreflang="x-default" href="{$canonicalBase}{$pageSuffix}">
```

Composer-tier controllers must pass `requestPath` for this to work:

```php
return $this->render('page', ['requestPath' => '/blog/' . $slug]);
```

**Never hardcode canonical URLs.** Use `$leafProductionUrl` (from
`production_url` config) combined with `$currentLocale` and
`$requestPath`.

## Content system

### Markdown with front matter

Content files in `content/{section}/{slug}.md`:

```markdown
---
title: "Introduction"
order: 1
description: "Optional, used for meta description"
---

Your content here…
```

### ContentLoader API

- `getPage(section, slug): ?ParsedMarkdown` — Get a parsed page
- `getSidebar(): array` — Navigation structure grouped by section
- `getAllPages(): array` — Flat list of all pages
- `getPreviousPage(section, slug): ?array` — `{title, url}` or null
- `getNextPage(section, slug): ?array` — `{title, url}` or null
- `getFirstPageUrl(): string` — URL of first page

### `ParsedMarkdown`

- `$parsed->html` — Rendered HTML
- `$parsed->frontMatter` — YAML data as array
- `$parsed->toc` — `[{id, text, level}, ...]` table of contents
- `$parsed->meta('key', default)` — Get front matter value

### Sidebar generation is automatic, not declared

The sidebar is rendered from the search index that the BuildCommand
pipeline produces — there is **no separate sidebar config file or
template** to populate. Section order comes from `leaf.sections` in
config.yml; alphabetical otherwise. For multi-locale, the sidebar
differs per-locale based on which content files exist for that locale.

### Custom pages

Files in `templates/pages/{slug}.latte` (or `.php`, `.html`) become
top-level routes at `/{slug}/`. Filename rules:

- Lowercase, dashes OK, no leading numbers (`[a-z0-9][a-z0-9-]*`)
- Subdirectories inside `pages/` are **not** routed (no nesting)
- Drop a file, restart `leaf dev` (or rebuild), it appears

## Configuration (`config.yml`)

Identical shape on both tiers:

```yaml
application:
  environment: !env APP_ENV, dev
  debug: !env APP_DEBUG, true

render:
  engine: latte
  directory: app/Views
  cache: cache/latte
  mode: always

localization:
  locale: "en"
  supported_locales:
    - "en"
    - "fr"
  locale_path: "locale"
  timezone: "America/Montreal"

leaf:
  name: "My Project"
  version: "1.0.0"
  description: "A static site"
  github_url: ""
  content_path: "content"
  output_path: "dist"
  base_url: ""
  production_url: "https://example.com"
  author: "Name"
  author_url: ""
  license: "MIT"
  sections:
    getting-started: "Getting Started"
    guides: "Guides"
```

### `LeafLatteExtension` globals (every template)

`$leafName`, `$leafVersion`, `$leafDescription`, `$leafGithubUrl`,
`$leafAuthor`, `$leafAuthorUrl`, `$leafLicense`, `$leafBaseUrl`
(from `base_url` — for asset/link prefixing), `$leafProductionUrl`
(from `production_url` — for canonical/hreflang).

## Development server

### Binary tier: `leaf dev`

Go HTTP server serving `dist/`, rebuilds on file change, pushes
SSE events to open tabs. Default `:8080`; override with `--addr :3000`.
Debounced ~250ms.

### Composer tier: `composer dev`

Runs `php -S localhost:8080 -t public bin/router.php`. `DevRouter`
handles:
- Static file serving from `public/`
- Locale prefix stripping (`/fr/blog` → sets `LEAF_LOCALE=fr`, routes to `/blog`)
- Live-reload endpoint (`/__dev/reload` returns file change hash)
- Defines `DEV_SERVER` constant for templates

### `DEV_SERVER` constant

The dev server defines the global `DEV_SERVER` constant. Use it in
templates to gate live-reload polling so it never ships in production:

```latte
{if defined('DEV_SERVER')}
<script>
let lastHash = null;
async function check() {
    const r = await fetch('/__dev/reload');
    const d = await r.json();
    if (lastHash && d.hash !== lastHash) location.reload();
    lastHash = d.hash;
}
check();
setInterval(check, 1500);
</script>
{/if}
```

## Production patterns from `zephyrus-leaf-site`

The site at **leaf.ophelios.com** is the canonical real-world Leaf
project. Patterns codified there (drawn from `www/zephyrus-leaf-site/`):

### `dist/` is committed to git

The production site commits its `dist/` folder. Deployment is
DigitalOcean's App Platform pulling from `main` — no CI build step. The
dev rebuilds locally before each commit. Pros: zero-CI deploys, atomic
rollback via `git revert`. Cons: PRs must include the rebuilt `dist/`.

### Asset pipeline: passthrough copy, no bundler

`public/` is copied verbatim to `dist/`. Tailwind loaded via CDN with an
inline config for custom color tokens; CSS variables for the actual
theme tokens (`--bg-deep`, `--bg-surface`, `--text`, `--accent`).
Toggling theme = setting `data-theme` on `<html>`. No Vite, Webpack, or
preprocessor; no hash-based cache busting on CSS/JS — production
deploys behind Cloudflare/CDN handle that layer.

### OG image generated by post-build hook

`scripts/generate-og-image.sh` uses headless Chrome + Python PIL to
render `resources/og-templates/site.html` (1200×630), writes to
`public/assets/images/og-image.png`. Because of the timing quirk above,
this lands in `dist/` on the *next* build — that's tolerable for
infrequent OG-template updates.

### No em-dashes (project style rule)

The site explicitly bans U+2014 em-dashes in any content or template.
Likely past trouble with encoding/rendering across font fallbacks.
Stick to en-dashes (`–`) or commas. CLAUDE.md in the project enforces it.

## Key rules

- **Identify the tier before suggesting commands.** Binary tier (no
  `app/`, no `composer.json`) → `leaf init|dev|build|eject`. Composer
  tier → `composer dev|build` plus code in `bin/build.php`,
  `app/Controllers/`, `app/Views/`.
- **`templates/` is the override surface for both tiers.** Recommend
  `templates/path/to/file.latte` before suggesting edits to
  `app/Views/`. Path must match the bundled path EXACTLY.
- **The default locale builds to root, other locales to `/{locale}/`.**
- **Always pass `requestPath`** from Composer-tier controllers for
  correct canonical/hreflang.
- **Use `$localePrefix` for internal links** (empty for default locale,
  `/{locale}` for others).
- **Set `production_url`** in config.yml to enable sitemap/robots
  generation.
- **Use `BuildCommand.onPostBuild()` for project-specific build steps**
  (Composer tier). Binary-tier users who need this must `leaf eject`
  first.
- **Content sections order comes from `leaf.sections` config**, or
  alphabetical if not set.
- **`dist/` is the deployment artifact**, can be served by any static
  host. Committing it to git for App-Platform-style auto-deploy is a
  legitimate pattern.
- **Leaf's multi-locale is string-level by default;** per-locale
  Markdown content lives in `content/{locale}/...` and falls back to
  `content/...`.
- **Redirects in routes are serialized as meta-refresh HTML** in static
  builds (since v0.1.2+); `Response::redirect` works.
- **Post-build hook output to `public/` lands in `dist/` on the NEXT
  build.** Write directly to `dist/` if you need it in the current
  build.
- **No em-dashes** if you're working in `zephyrus-leaf-site`.

## References

- Live docs: **https://leaf.ophelios.com**
- Framework repo: **https://github.com/ophelios-studio/zephyrus-leaf-core**
- Production reference site (and source for empirical patterns):
  `zephyrus-leaf-site` (private; ask Ophelios for access)
- In-repo: `examples/leaf/` — minimal Binary-tier scaffold to verify
  the basic flow.
