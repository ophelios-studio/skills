# Leaf, Composer tier

A full Zephyrus application built by leaf-core: you own the controllers,
the views in `app/Views/`, `bin/build.php` and `bin/router.php`.
Everything in `SKILL.md` about content, config, multi-locale and SEO
applies, except `templates/` (not read here: edit `app/Views/`).

## Starting a project

From Packagist (the template is `zephyrus-framework/leaf`):

```bash
composer create-project zephyrus-framework/leaf my-site
composer build --working-dir=my-site
```

It works as is, with no version argument: Composer installs the latest
stable template (v1.0.0), and resolves `zephyrus-framework/leaf-core`
(v1.0.0) and `zephyrus-framework/core` (v3.18.0) from Packagist. The
template's `composer.json` requires leaf-core `^1.0` with the default
stability, and has no `path` repositories. `composer build` first compiles
`public/assets/css/tailwind.css` (`@css`, then `php bin/build.php`), which
downloads the Tailwind CLI on the first run (needs network; see `SKILL.md`).

**A project from the 0.1.x template or a 0.1.x eject** requires leaf-core
`@dev` with `"minimum-stability": "dev"`: set `^1.0`, drop `minimum-stability`,
`composer update zephyrus-framework/leaf-core`. Its theme copy keeps the CDN
until you take the compiled sheet (theme CHANGELOG, "Upgrading a Composer
project"); see leaf-core's
[UPGRADE.md](https://github.com/zephyrus-framework/leaf-core/blob/v1.0.0/UPGRADE.md).

From a Binary-tier site, `leaf eject` (one-way; refuses when
`composer.json` exists unless `--force`). CLI v1.0.0 writes `app/`, `bin/`
(with `tailwind.sh`), `resources/`, `tailwind.config.js`, `vendor/`,
`composer.json` (leaf-core `^1.0`) and `composer.lock`, and merges the bundled
`public/` into yours without overwriting: your `public/assets/css/app.css`,
extra files and `README.md` are kept, and `public/index.php` is added. It
also writes two stray files, `.gitkeep` and `.staged`, to delete. It leaves
`content/`, `config.yml`, `templates/` and `dist/` alone, and keeps your
`locale/` translations: the theme's keys are added to
`locale/{locale}/general.json` next to yours. The
`composer.json` has no `path` repositories, so `composer install`, `composer update` and
`composer build` work as shipped (`composer build` ran on a fresh eject with CLI
v1.0.0), and `composer dev` serves the pages.

One thing to do by hand: **`templates/` stops applying** to `composer
build`, which renders `app/Views/` only. Move each override to the same
relative path under `app/Views/`. Until then `leaf build` (which still
works on an ejected project and still reads `templates/`) and `composer
build` produce different sites.

**A project ejected with CLI v0.1.6** keeps the maintainers' `path`
repositories in `composer.json` (`composer config --unset repositories` before
`composer update`), has no `public/index.php` (`composer dev` answers 500: copy
the template's) and had its `README.md` overwritten (restore it from version
control).

## Application (`Leaf\Kernel`)

`app/Models/Core/Application.php` extends the abstract `Leaf\Kernel`. Its
`createController()` wires the bundled controllers: **keep those branches**
when adding yours, or every docs page and custom page fails with HTTP 500.

```php
<?php

declare(strict_types=1);

namespace App\Models\Core;

use App\Controllers\DocsController;
use App\Controllers\MyController;
use App\Controllers\PagesController;
use Leaf\Kernel;

final class Application extends Kernel
{
    protected function createController(string $class): object
    {
        return match ($class) {
            DocsController::class => new DocsController(
                $this->contentLoader,
                $this->searchIndexBuilder,
                $this->leafConfig,
                $this->config->localization->locale,
                array_values($this->config->localization->supportedLocales),
            ),
            PagesController::class => new PagesController($this->contentLoader),
            MyController::class => new MyController($this->contentLoader, $this->leafConfig),
            default => new $class(),
        };
    }
}
```

- The template's constructors changed in v0.1.12: `DocsController` takes the
  default locale and the supported locales after `$leafConfig`, and
  `PagesController` takes the `ContentLoader` instead of `LeafConfig`. Both
  controllers and `Application` are your copies, so a project on its v0.1.11
  files keeps building after `composer update` to leaf-core v0.1.12. Taking the
  new controllers without the new `Application` stops the build: `Too few
  arguments to function App\Controllers\DocsController::__construct(), 3
  passed` on every page.
- Overridable: `createController(string $class): object`,
  `registerControllers(Router $router): Router` (default: discover
  `App\Controllers` in `app/Controllers`).
- Protected properties: `$config`, `$leafConfig`, `$renderEngine`,
  `$markdownParser`, `$contentLoader`, `$searchIndexBuilder`,
  `$translator` and `$translationExtension` (both null when
  `localization.locale_path` is empty and there is no `locale/` at the
  project root).
- Public: `run()`, `buildForStaticGeneration(): array{Application, Router}`,
  `getConfig()`, `getLeafConfig()`, `getRenderEngine()`,
  `getContentLoader()`, `getTranslator()`, `getTranslationExtension()`.
- The constructor defines `ROOT_DIR` if needed, loads `.env`, reads
  `config.yml` and registers the Leaf and translation Latte extensions.
  A route that throws `RouteNotFoundException` renders `404.latte`.

## Controllers

The bundled ones: `DocsController` (`GET /`, `GET /{section}/{slug}`,
`GET /search.json`, `GET /404`) and `PagesController`
(`GET /{page}` for `app/Views/pages/{page}.latte`). Yours use the
Zephyrus `Controller` base and the `RenderResponses` trait:

```php
#[Get('/blog/{slug}')]
public function show(string $slug): Response
{
    return $this->render('blog', ['requestPath' => '/blog/' . $slug, 'slug' => $slug]);
}

#[Get('/old-page')]
public function old(): Response
{
    return Response::redirect('/blog');
}
```

- **Pass `requestPath`** so the canonical snippet in `SKILL.md` gets the
  right URL.
- **A route with a `{parameter}` is never built on its own**: list the
  concrete paths with `addPaths()`. leaf-core `1a2c175` and later (CLI
  v0.1.7) builds `addPaths()` paths in every locale of a multi-locale
  site. An older leaf-core (CLI v0.1.6) drops them: run `composer update`,
  or declare a concrete route per page instead, those build in every
  locale.
- A redirect becomes a meta-refresh page (`/old-page/index.html` pointing
  at `/blog`).

## `bin/build.php`

The shipped file (a refused page name is reported on STDERR as
`Build failed: <message>`, exit 1) plus the extension points:

```php
<?php

declare(strict_types=1);

define('ROOT_DIR', dirname(__DIR__));
require ROOT_DIR . '/vendor/autoload.php';

use App\Models\Core\Application;
use Leaf\BuildCommand;
use Leaf\PageDiscovery;
use Leaf\StaticBuildResult;

$app = new Application();
$command = new BuildCommand($app);

// Custom pages: keep this call, or app/Views/pages/*.latte never build.
try {
    $command->addPaths(PageDiscovery::discover(ROOT_DIR . '/app/Views'));
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, 'Build failed: ' . $e->getMessage() . "\n");
    exit(1);
}

// Concrete paths for parameterized routes.
$command->addPaths(['/blog/my-post']);

// Runs after the leaf.post_build hooks.
$command->onPostBuild(function (StaticBuildResult $result, string $outputDir): void {
    echo "{$result->pagesBuilt} pages in {$outputDir}" . PHP_EOL;
});

exit($command->run());
```

`BuildCommand`: `addPaths(list<string>)`, `excludePatterns(list<string>)`
(regexes such as `'#^/drafts/#'`, appended to the defaults),
`onPostBuild(callable(StaticBuildResult, string): void)`, `run(): int`
(0 or 1). `StaticBuildResult` is read-only: `pagesBuilt`, `totalPaths`,
`elapsedMs`, `errors`, `outputDirectory`, `builtPages`, `isSuccessful()`,
`summary()`.

**`composer build` never cleans `dist/`**: a deleted page stays there, and
so does a page you just added to `excludePatterns()`, in `search.json` too
(leaf-core v0.1.12 indexes only pages whose `index.html` is in the output).
`rm -rf dist && composer build` for a clean artifact.

## `StaticSiteBuilder` (lower level)

```php
[$application, $router] = (new Application())->buildForStaticGeneration();

$builder = new StaticSiteBuilder($application, $router);
$builder->setOutputDirectory(ROOT_DIR . '/dist');
$builder->setPublicDirectory(ROOT_DIR . '/public');
$builder->setBaseUrl('http://localhost');
$builder->setLocales(['en', 'fr'], 'en');
$builder->addPaths(['/blog/post-1']);
$builder->excludePatterns(['#^/api/#']);
$result = $builder->build();
```

It renders and copies `public/`, nothing more: no `404.html` move, no
`search.json`, sitemap, robots or hooks (that is `BuildCommand`). Its
`excludePatterns()` replaces the previous list. For real per-locale output
also call `setTranslationExtension($app->getTranslationExtension())` and
`setContentLoader($app->getContentLoader())`, or every locale renders the
default strings and content.

## Content API

- `ContentLoader`: `getPage(section, slug): ?ParsedMarkdown`,
  `getSidebar(): list<{title, items: list<{title, url, section, slug}>}>`,
  `getAllPages()`, `getPreviousPage(section, slug)` and
  `getNextPage(section, slug)` (`{title, url}` or null),
  `getFirstPageUrl()`, `setLocale(current, default, supported)`.
- `ParsedMarkdown`: `$html`, `$frontMatter`, `$toc` (`list<{id, text, level}>`),
  `meta('key', $default)`.
- `MarkdownParser::parse(string)`, `SearchIndexBuilder::build()`
  (`list<{title, section, url, excerpt, headings, content}>`, `content` being
  the page's full text since v0.1.12).

## `composer dev`

`php -S localhost:8080 -t public bin/router.php`, with `Leaf\DevRouter`:

- serves real files from `public/`, renders everything else live through
  `public/index.php`;
- strips a first segment shaped like a locale code (`/fr/x`, `/fr-ca/x`,
  `/ab/x`) before routing, and the kernel keeps it only for a declared
  non-default locale: the strings switch and `content/{locale}/` overrides
  apply (leaf-core v0.1.9 and later), `/ab/x` is a 404, and a section named
  `ui` or `api` is still served at `/ui/x`. `/{locale}/search.json` lists that
  locale's pages with `/{locale}/` URLs, like the build (leaf-core v0.1.12;
  v0.1.11 listed the default locale's), and a miss renders that locale's 404;
- a refused configuration (an invalid `supported_locales` entry) answers
  every request with HTTP 500 `text/plain` carrying the message, which is
  also printed in the terminal running `composer dev` (leaf-core v0.1.12;
  v0.1.11 answered with an empty body);
- answers `/__dev/reload` with a hash of file times under `content/`,
  `app/Views/`, `public/assets/css/`, `public/assets/js/` and `src/` (not
  `locale/`);
- defines `DEV_SERVER`; the bundled `partials/head.latte` polls
  `/__dev/reload` every 1.5 s inside `{if defined('DEV_SERVER')}`, so the
  script never reaches a build.
