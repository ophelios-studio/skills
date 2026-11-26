# Leaf, Composer tier

A full Zephyrus application built by leaf-core: you own the controllers,
the views in `app/Views/`, `bin/build.php` and `bin/router.php`.
Everything in `SKILL.md` about content, config, multi-locale and SEO
applies, except `templates/` (not read here: edit `app/Views/`).

## Starting a project

From Packagist (the template is `zephyrus-framework/leaf`, tagged since
v0.1.9):

```bash
composer create-project zephyrus-framework/leaf my-site
composer build --working-dir=my-site
```

It works as is, with no version argument: Composer installs the latest
stable template (v0.1.11), and resolves `zephyrus-framework/leaf-core`
(v0.1.11) and `zephyrus-framework/core` (v3.18.0) from Packagist. The
template's `composer.json` has no `path` repositories, so `composer
update` and `composer require` work in the new project.

From a Binary-tier site, `leaf eject` (one-way; refuses when
`composer.json` exists unless `--force`). CLI v0.1.11 writes `app/`, `bin/`,
`vendor/`, `composer.json` and `composer.lock`, and merges the bundled
`public/` into yours without overwriting: your `public/assets/css/app.css`,
extra files and `README.md` are kept, and `public/index.php` is added. It
also writes two stray files, `.gitkeep` and `.staged`, to delete. It leaves
`content/`, `config.yml`, `templates/` and `dist/` alone, and keeps your
`locale/` translations: the theme's keys are added to
`locale/{locale}/general.json` next to yours (CLI v0.1.8 overwrote the
file with the theme's). The
`composer.json` has no `path` repositories (it requires leaf-core `@dev`
from Packagist), so `composer install`, `composer update` and
`composer build` work as shipped, and `composer dev` serves the pages.

One thing to do by hand: **`templates/` stops applying** to `composer
build`, which renders `app/Views/` only. Move each override to the same
relative path under `app/Views/`. Until then `leaf build` (which still
works on an ejected project and still reads `templates/`) and `composer
build` produce different sites.

**A project ejected with CLI v0.1.6** differs: its `composer.json` keeps
the maintainers' `path` repositories (`../zephyrus2`,
`../zephyrus-leaf-core`), `README.md` was overwritten, and `public/index.php`
was never written. Fix it by hand:

1. Create `public/index.php`, or `composer dev` answers HTTP 500:

   ```php
   <?php

   declare(strict_types=1);

   define('ROOT_DIR', dirname(__DIR__));
   require ROOT_DIR . '/vendor/autoload.php';

   (new \App\Models\Core\Application())->run();
   ```

2. Run `composer config --unset repositories` before `composer update` or
   `composer require`, which otherwise fail with `In PathRepository.php line
   163` (`composer install` works as shipped).
3. Restore your `README.md` from version control.

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
- strips a first segment shaped like a locale code (`/fr/x`, `/fr-ca/x`,
  `/ab/x`) before routing, and the kernel keeps it only for a declared
  non-default locale: the strings switch and `content/{locale}/` overrides
  apply (leaf-core v0.1.9 and later), `/ab/x` is a 404, and a section named
  `ui` or `api` is still served at `/ui/x`. `/{locale}/search.json` lists the
  default locale's titles with unprefixed URLs here, unlike the build;
- a refused `localization` setting (an invalid `supported_locales` entry)
  answers with HTTP 500 and an empty body; the error is in the terminal
  running `composer dev`;
- answers `/__dev/reload` with a hash of file times under `content/`,
  `app/Views/`, `public/assets/css/`, `public/assets/js/` and `src/` (not
  `locale/`);
- defines `DEV_SERVER`; the bundled `partials/head.latte` polls
  `/__dev/reload` every 1.5 s inside `{if defined('DEV_SERVER')}`, so the
  script never reaches a build.
