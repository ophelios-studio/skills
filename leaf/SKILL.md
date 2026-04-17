---
name: leaf
description: Zephyrus Leaf static site generator guidance. Triggers on projects using zephyrus-framework/leaf-core, BuildCommand, StaticSiteBuilder, Leaf\Kernel, LeafConfig, ContentLoader, or config.yml with a leaf: section.
---

# Zephyrus Leaf

You are working in a project that uses **Zephyrus Leaf** (`zephyrus-framework/leaf-core`), a static site generator built on the Zephyrus Framework. It handles multi-locale builds, SEO generation, content parsing, and live-reload development.

## Architecture

Leaf has two packages:

- **`zephyrus-framework/leaf-core`** (library) - Reusable classes, updated via `composer update`
- **`zephyrus-framework/leaf`** (template) - Project scaffold, copied on `composer create-project`

All core logic is in `leaf-core`. Projects extend it via `Leaf\Kernel`.

## Application Bootstrap

Projects extend `Leaf\Kernel` with an `Application` class:

```php
use Leaf\Kernel;

final class Application extends Kernel
{
    protected function createController(string $class): object
    {
        // Inject services into controllers that need them
        if ($class === MyController::class) {
            return new MyController($this->contentLoader, $this->leafConfig);
        }
        return new $class();
    }
}
```

### Kernel Overridable Methods

- `createController(string $class): object` - Dependency injection for controllers
- `registerControllers(Router $router): Router` - Customize controller discovery (default: scans `App\Controllers`)

### Kernel Protected Properties (available in createController)

- `$this->config` - Zephyrus Configuration
- `$this->leafConfig` - LeafConfig (leaf: section from config.yml)
- `$this->renderEngine` - LatteEngine
- `$this->contentLoader` - ContentLoader
- `$this->searchIndexBuilder` - SearchIndexBuilder
- `$this->markdownParser` - MarkdownParser
- `$this->translator` - Translator (null if no localization)
- `$this->translationExtension` - TranslationLatteExtension (null if no localization)

## Building Static Sites

### BuildCommand

The standard build pipeline. Projects use a thin `bin/build.php`:

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
5. Exclude `/search.json` and `/` by default
6. Build all pages (render each route through the full app stack)
7. Move `/404/index.html` to `/404.html`
8. Generate search index JSON
9. Generate root redirect (single-locale only)
10. Generate `sitemap.xml` and `robots.txt` (if `production_url` is set)
11. Run `onPostBuild` callbacks

### BuildCommand API

- `addPaths(array $paths): void` - Add paths for parameterized routes
- `excludePatterns(array $patterns): void` - Exclude paths by regex
- `onPostBuild(callable $callback): void` - Hook after pipeline, receives `(StaticBuildResult $result, string $outputDir)`
- `run(): int` - Execute, returns 0 on success, 1 on error

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

### How It Works

- The **default locale** builds to the root: `dist/index.html`, `dist/blog/`, etc.
- Other locales build to subdirectories: `dist/fr/`, `dist/ar/`
- No JS redirect page at root

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

- `{$currentLocale}` - Active locale code ("en", "fr", "ar")
- `{$defaultLocale}` - Default locale from config ("en")
- `{$supportedLocales}` - Array of all supported locales

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

JSON files in `locale/{lang}/strings.json`:

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

Usage in templates: `{localize('nav.home')}`, `{localize('hero.title')}`

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

Controllers must pass `requestPath` for this to work:

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
---

Your content here...
```

### ContentLoader API

- `getPage(section, slug): ?ParsedMarkdown` - Get a parsed page
- `getSidebar(): array` - Navigation structure grouped by section
- `getAllPages(): array` - Flat list of all pages
- `getPreviousPage(section, slug): ?array` - `{title, url}` or null
- `getNextPage(section, slug): ?array` - `{title, url}` or null
- `getFirstPageUrl(): string` - URL of first page

### ParsedMarkdown

- `$parsed->html` - Rendered HTML
- `$parsed->frontMatter` - YAML data as array
- `$parsed->toc` - Table of contents `[{id, text, level}, ...]`
- `$parsed->meta('key', default)` - Get front matter value

### SearchIndexBuilder

Generates `search.json` with `{title, section, url, excerpt, headings}` entries.

## Configuration (config.yml)

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
- `{$leafBaseUrl}` - from `base_url` (for asset/link prefixing)
- `{$leafProductionUrl}` - from `production_url` (for canonical/hreflang)

## Development Server

```bash
composer dev
# or
php -S localhost:8080 -t public bin/router.php
```

`DevRouter` handles:
- Static file serving from `public/`
- Locale prefix stripping (`/fr/blog` -> sets `LEAF_LOCALE=fr`, routes to `/blog`)
- Live-reload endpoint (`/__dev/reload` returns file change hash)
- Defines `DEV_SERVER` constant for templates

## File Structure Convention

```
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
content/               Markdown content (section/slug.md)
locale/                Translation JSON files (lang/strings.json)
public/
  index.php            Web entry point
  assets/              CSS, JS, images
config.yml             App configuration
dist/                  Static build output
```

## Key Rules

- The default locale builds to root, other locales to `/{locale}/`
- Always pass `requestPath` from controllers for correct canonical/hreflang
- Use `$localePrefix` for internal links (empty for default locale, `/{locale}` for others)
- Set `production_url` in config.yml to enable sitemap/robots generation
- Use `BuildCommand.onPostBuild()` for project-specific build steps
- Content sections order comes from `leaf.sections` config, or alphabetical if not set
- `dist/` is the deployment artifact, can be served by any static host
