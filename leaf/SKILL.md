---
name: leaf
description: Zephyrus Leaf static site generator guidance. Triggers on projects with a leaf: section in config.yml, or any of: zephyrus-framework/leaf-core, BuildCommand, StaticSiteBuilder, Leaf\Kernel, LeafConfig, ContentLoader, `leaf init|build|dev|eject` commands, or a project scaffolded by the leaf CLI (bare content/ + templates/ + public/ + config.yml, no app/ or vendor/).
---

# Zephyrus Leaf

You are working in a project that uses **Zephyrus Leaf**, a static site generator built on the Zephyrus Framework. Leaf handles multi-locale builds, SEO, content parsing, and live-reload dev.

## Two distributions (know which one you're in)

Leaf ships two tiers that share the same `zephyrus-framework/leaf-core` library:

| Tier | Project shape | Build command | Dev command |
|------|--------------|---------------|-------------|
| **Binary CLI** (recommended for most users) | `content/`, `templates/` (optional overrides), `public/`, `config.yml` — no `app/`, `bin/`, `vendor/`, `composer.json` | `leaf build` | `leaf dev` |
| **Composer template** (for PHP devs) | Full Zephyrus project: `app/Controllers`, `app/Models/Core/Application.php` (extends `Leaf\Kernel`), `bin/build.php`, `vendor/`, `composer.json` | `composer build` | `composer dev` |

**How to tell which one you're in:** if the project has `app/Controllers/` and `composer.json`, it's Composer tier. If it has only `content/`, `templates/`, `public/`, and `config.yml`, it's Binary tier.

**Migration path:** a Binary-tier user runs `leaf eject` to convert to Composer-tier. One-way. `content/`, `templates/`, `public/`, `config.yml` survive; `app/`, `bin/`, `composer.json` are written fresh.

## Binary CLI commands

```bash
leaf init <name>      # Scaffold a new site (content/, public/, config.yml, locale/)
leaf dev [--addr]     # Serve with live reload (default :8080, watches content/templates/public/config)
leaf build [--dir]    # Render to dist/
leaf eject            # Convert to Composer tier (writes framework files into cwd)
leaf version
leaf help
```

The binary embeds the framework and scaffolds. No PHP or Composer required on the user's machine at install time. At **build time** the binary shells out to system `php` (>= 8.4 with `intl`, `mbstring`, `sodium`, `pdo`) until FrankenPHP static link lands.

Install the binary:

```bash
curl -fsSL https://leaf.ophelios.com/install.sh | sh
```

## Application Bootstrap (Composer tier only)

Binary-tier users don't see this — it lives inside the binary. Composer-tier projects extend `Leaf\Kernel`:

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

### Kernel Overridable Methods

- `createController(string $class): object` — Dependency injection for controllers
- `registerControllers(Router $router): Router` — Customize controller discovery (default: scans `App\Controllers`)

### Kernel Protected Properties (available in createController)

- `$this->config` — Zephyrus Configuration
- `$this->leafConfig` — LeafConfig (`leaf:` section from config.yml)
- `$this->renderEngine` — LatteEngine
- `$this->contentLoader` — ContentLoader
- `$this->searchIndexBuilder` — SearchIndexBuilder
- `$this->markdownParser` — MarkdownParser
- `$this->translator` — Translator (null if no localization)
- `$this->translationExtension` — TranslationLatteExtension (null if no localization)

## Template override pattern

Both tiers support user overrides via a `templates/` directory at the project root. Drop a file at `templates/layouts/docs.latte` or `templates/partials/nav.latte` and the build merges it on top of the bundled theme. Binary-tier users rely on this for customization (they can't edit `app/Views/` directly). File formats: `.latte`, `.php`, or `.html` (HTML is copy-through, no variable interpolation).

## Building Static Sites

### leaf build (Binary tier)

Default behaviour. No PHP code to write. The binary merges embedded defaults + user project into a tempdir, runs the standard pipeline, copies `dist/` back out.

### BuildCommand (Composer tier)

The standard build pipeline. `bin/build.php` is a file the user owns:

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

### BuildCommand Pipeline

1. Create StaticSiteBuilder from Kernel's Application + Router
2. Configure multi-locale if `count(supportedLocales) > 1`
3. Discover doc content paths from `{contentPath}/*/*.md`
4. Add caller-provided paths (`addPaths()`)
5. Exclude `/search.json` always. Exclude `/` only if no `GET /` route exists.
6. Build all pages (render each route through the full app stack). **3xx responses are serialized as meta-refresh HTML** (redirects work in static output).
7. Move `/404/index.html` to `/404.html`
8. Generate search index JSON
9. Generate root redirect (single-locale only, when no custom `GET /` route)
10. Generate `sitemap.xml` and `robots.txt` (if `production_url` is set)
11. Run `onPostBuild` callbacks

### BuildCommand API

- `addPaths(array $paths): void` — Add paths for parameterized routes
- `excludePatterns(array $patterns): void` — Exclude paths by regex
- `onPostBuild(callable $callback): void` — Hook after pipeline, receives `(StaticBuildResult $result, string $outputDir)`
- `run(): int` — Execute, returns 0 on success, 1 on error

### StaticSiteBuilder

Lower-level class if you need full control (usually `BuildCommand` is enough):

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

## Multi-Locale Builds

**Scope:** Leaf's multi-locale is **string-level** (JSON translation files consumed via `localize()` in templates). Markdown pages under `content/` are built once per locale using the same source; translation happens through `localize()` calls in templates. Per-locale Markdown content is not built in; patterns to work around it: template branching with `{if $currentLocale === 'fr'}...{/if}`, or sibling projects per language.

### How It Works

- The **default locale** builds to root: `dist/index.html`, `dist/blog/`, etc.
- Other locales build to subdirectories: `dist/fr/`, `dist/ar/`
- No JS redirect page at root (leaf-core writes a meta-refresh if no custom `/` route)

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

### Template Variables

The `TranslationLatteExtension` injects into every template:

- `{$currentLocale}` — Active locale code ("en", "fr", "ar")
- `{$defaultLocale}` — Default locale from config ("en")
- `{$supportedLocales}` — Array of all supported locales

### Locale-Aware URLs in Templates

The default locale has no URL prefix, other locales get `/{locale}/`:

```latte
{var $dl = $defaultLocale ?? 'en'}
{var $localePrefix = ($currentLocale ?? $dl) === $dl ? '' : '/' . $currentLocale}

<a href="{$localePrefix}/">Home</a>
<a href="{$localePrefix}/blog">Blog</a>
```

### Language Switcher Pattern

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

### Translation Files

JSON files in `locale/{lang}/*.json` (every file in the directory is merged into one namespace):

```json
{
    "nav": {
        "home": "Home",
        "about": "About"
    },
    "hero": {
        "title": "Welcome"
    }
}
```

Usage in templates: `{localize('nav.home')}`, `{localize('hero.title')}`. `i18n()` is an alias for `localize()`. Missing keys fall back to the key itself.

## SEO

### Sitemap Generation

Automatic when `production_url` is set in config. For multi-locale sites, generates `xhtml:link` hreflang alternates. Default locale URLs are at root (no prefix).

### Robots.txt

Generated alongside sitemap with a `Sitemap:` reference.

### Hreflang Tags in Templates

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

Controllers must pass `requestPath` for this to work (Composer tier):

```php
return $this->render('page', [
    'requestPath' => '/blog/' . $slug,
]);
```

### Dynamic Canonical URLs

Never hardcode canonical URLs. Use `$leafProductionUrl` (from `production_url` config) combined with `$currentLocale` and `$requestPath`.

## Content System

### Markdown with Front Matter

Content files in `content/{section}/{slug}.md`:

```markdown
---
title: "Introduction"
order: 1
description: "Optional, used for meta description"
---

Your content here...
```

### ContentLoader API

- `getPage(section, slug): ?ParsedMarkdown` — Get a parsed page
- `getSidebar(): array` — Navigation structure grouped by section
- `getAllPages(): array` — Flat list of all pages
- `getPreviousPage(section, slug): ?array` — `{title, url}` or null
- `getNextPage(section, slug): ?array` — `{title, url}` or null
- `getFirstPageUrl(): string` — URL of first page

### ParsedMarkdown

- `$parsed->html` — Rendered HTML
- `$parsed->frontMatter` — YAML data as array
- `$parsed->toc` — Table of contents `[{id, text, level}, ...]`
- `$parsed->meta('key', default)` — Get front matter value

### SearchIndexBuilder

Generates `search.json` with `{title, section, url, excerpt, headings}` entries.

## Configuration (config.yml)

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

### LeafLatteExtension Globals

Available in every template:
- `{$leafName}`, `{$leafVersion}`, `{$leafDescription}`
- `{$leafGithubUrl}`, `{$leafAuthor}`, `{$leafAuthorUrl}`, `{$leafLicense}`
- `{$leafBaseUrl}` — from `base_url` (for asset/link prefixing)
- `{$leafProductionUrl}` — from `production_url` (for canonical/hreflang)

## Development Server

### Binary tier: `leaf dev`

Go HTTP server serving `dist/`, rebuilds on file change, pushes SSE `reload` events to open tabs. Press Ctrl+C to stop. Default addr `:8080`; override with `--addr :3000`.

### Composer tier: `composer dev`

Runs `php -S localhost:8080 -t public bin/router.php`. `DevRouter` handles:
- Static file serving from `public/`
- Locale prefix stripping (`/fr/blog` → sets `LEAF_LOCALE=fr`, routes to `/blog`)
- Live-reload endpoint (`/__dev/reload` returns file change hash)
- Defines `DEV_SERVER` constant for templates

## File Structure Convention

### Binary tier

```
my-site/
  content/               Markdown content (section/slug.md)
  templates/             Optional Latte/PHP/HTML overrides of bundled defaults
  public/                Static assets (CSS, JS, images) copied verbatim
  locale/                Translation JSON files (lang/*.json) — optional
  config.yml             Site configuration
  dist/                  Build output (deploy this)
```

### Composer tier

```
my-site/
  app/
    Controllers/         Route handlers
    Models/Core/
      Application.php    Extends Leaf\Kernel
    Views/               Latte templates (.latte)
      layouts/           HTML wrappers
      partials/          Reusable components
  bin/
    build.php            Static build script (uses BuildCommand)
    router.php           Dev server entry (uses DevRouter)
  content/               Same as binary tier
  templates/             Optional overrides (same pattern works here too)
  public/
    index.php            Web entry point
    assets/              CSS, JS, images
  locale/                Same as binary tier
  config.yml             Same as binary tier
  vendor/                Composer dependencies
  composer.json
  dist/                  Static build output
```

## Key Rules

- **Identify the tier before suggesting commands.** If the user is on the Binary tier (no `app/`, no `composer.json`), suggest `leaf init|dev|build|eject`. If Composer tier, suggest `composer dev|build` and code that goes in `bin/build.php`, `app/Controllers/`, `app/Views/`.
- **Templates/ is the override surface for both tiers.** Recommend `templates/path/to/file.latte` before suggesting edits to `app/Views/`. It works for both and is the only way Binary-tier users can customize rendering.
- **The default locale builds to root, other locales to `/{locale}/`.**
- **Always pass `requestPath` from Composer-tier controllers** for correct canonical/hreflang.
- **Use `$localePrefix` for internal links** (empty for default locale, `/{locale}` for others).
- **Set `production_url` in config.yml** to enable sitemap/robots generation.
- **Use `BuildCommand.onPostBuild()` for project-specific build steps** (Composer tier). Binary-tier users who need this must `leaf eject` first.
- **Content sections order comes from `leaf.sections` config**, or alphabetical if not set.
- **`dist/` is the deployment artifact**, can be served by any static host.
- **Leaf's multi-locale is string-level, not content-level.** If the user asks about per-locale Markdown content, explain the workarounds (template branching, sibling sites) rather than inventing a feature that doesn't exist.
- **Redirects in routes are serialized as meta-refresh HTML** in static builds (since StaticSiteBuilder v0.1.2+); users don't need to worry about Response::redirect breaking their build.
