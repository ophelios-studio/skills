# Leaf, Composer tier

A full Zephyrus application built by leaf-core: you own the controllers,
the views in `app/Views/`, `bin/build.php` and `bin/router.php`.
Everything in `SKILL.md` about content, config, multi-locale and SEO
applies, except `templates/` (not read here: edit `app/Views/`).

## Starting a project

From Packagist (the template is `zephyrus-framework/leaf`, `dev-main` only):

```bash
composer create-project zephyrus-framework/leaf my-site dev-main --no-install
composer config --working-dir=my-site --unset repositories
composer update --working-dir=my-site
composer build --working-dir=my-site
```

The template's `composer.json` declares two `path` repositories
(`../zephyrus2`, `../zephyrus-leaf-core`) that only exist on the
maintainers' machines; a plain `create-project` stops on
``The `url` supplied for the path (../zephyrus2) repository does not exist``.
Without them Composer resolves `zephyrus-framework/core` (v0.13.0 at the
time of writing) and `zephyrus-framework/leaf-core` `dev-main`.

From a Binary-tier site, `leaf eject` (one-way; refuses when
`composer.json` exists unless `--force`). It writes `app/`, `bin/`,
`vendor/`, `composer.json`, `composer.lock` and `README.md` (overwriting
yours), plus two stray files, `.gitkeep` and `.staged`, to delete. It
leaves `content/`, `config.yml`, `templates/`, `public/` and `dist/` alone.
Then fix three things:

1. **`public/index.php` is missing** (eject never writes into `public/`),
   so `composer dev` answers HTTP 500. Create it:

   ```php
   <?php

   declare(strict_types=1);

   define('ROOT_DIR', dirname(__DIR__));
   require ROOT_DIR . '/vendor/autoload.php';

   (new \App\Models\Core\Application())->run();
   ```

2. **`templates/` stops applying.** `composer build` renders `app/Views/`
   only; move each override to the same relative path under `app/Views/`.
   Until then `leaf build` (which still works on an ejected project) and
   `composer build` produce different sites.
3. **`composer update` and `composer require` fail** on the same path
   repositories: run `composer config --unset repositories` first.
   `composer install` works as shipped (the lock and `vendor/` match).

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
            DocsController::class => new DocsController($this->contentLoader, $this->searchIndexBuilder, $this->leafConfig),
            PagesController::class => new PagesController($this->leafConfig),
            MyController::class => new MyController($this->contentLoader, $this->leafConfig),
            default => new $class(),
        };
    }
}
```

- Overridable: `createController(string $class): object`,
  `registerControllers(Router $router): Router` (default: discover
  `App\Controllers` in `app/Controllers`).
- Protected properties: `$config`, `$leafConfig`, `$renderEngine`,
  `$markdownParser`, `$contentLoader`, `$searchIndexBuilder`,
  `$translator` and `$translationExtension` (both null when
  `localization.locale_path` is empty).
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
  concrete paths with `addPaths()`. On a multi-locale site `addPaths()`
  paths are dropped (leaf-core `main`); declare a concrete route per page
  instead, those build in every locale.
- A redirect becomes a meta-refresh page (`/old-page/index.html` pointing
  at `/blog`).

## `bin/build.php`

The shipped file plus the two extension points:

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

// Custom pages: keep this line, or app/Views/pages/*.latte never build.
$command->addPaths(PageDiscovery::discover(ROOT_DIR . '/app/Views'));

// Concrete paths for parameterized routes.
$command->addPaths(['/blog/my-post']);

// Runs after the leaf.post_build hooks.
$command->onPostBuild(function (StaticBuildResult $result, string $outputDir): void {
    echo count($result->builtPages) . " pages in {$outputDir}" . PHP_EOL;
});

exit($command->run());
```

`BuildCommand`: `addPaths(list<string>)`, `excludePatterns(list<string>)`
(regexes such as `'#^/drafts/#'`, appended to the defaults),
`onPostBuild(callable(StaticBuildResult, string): void)`, `run(): int`
(0 or 1). `StaticBuildResult` is read-only: `pagesBuilt`, `totalPaths`,
`elapsedMs`, `errors`, `outputDirectory`, `builtPages`, `isSuccessful()`,
`summary()`.

**`composer build` never cleans `dist/`**: a deleted page stays there.
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
  (`list<{title, section, url, excerpt, headings}>`).

## `composer dev`

`php -S localhost:8080 -t public bin/router.php`, with `Leaf\DevRouter`:

- serves real files from `public/`, renders everything else live through
  `public/index.php`;
- **treats any two-letter first segment as a locale** (`/fr/x`, but also
  `/ui/x` or `/zz/x`) and strips it; the strings switch, but
  `content/{locale}/` overrides are not applied (the build applies them);
- answers `/__dev/reload` with a hash of file times under `content/`,
  `app/Views/`, `public/assets/css/`, `public/assets/js/` and `src/` (not
  `locale/`);
- defines `DEV_SERVER`; the bundled `partials/head.latte` polls
  `/__dev/reload` every 1.5 s inside `{if defined('DEV_SERVER')}`, so the
  script never reaches a build.
