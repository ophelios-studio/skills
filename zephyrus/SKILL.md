---
name: zephyrus
description: Zephyrus Framework guidance. Triggers on PHP projects using zephyrus-framework/core, attribute routing (#[Get], #[Post]), ApplicationBuilder, Controller, RenderResponses, or config.yml with Zephyrus sections.
---

# Zephyrus Framework

You are working in a project that uses the **Zephyrus Framework** (`zephyrus-framework/core`), a PHP 8.4+ framework with attribute routing, immutable HTTP objects, typed config, and security middleware.

## Core Architecture

### Bootstrap

Applications are built with `ApplicationBuilder`:

```php
$app = ApplicationBuilder::create()
    ->withConfiguration($config, basePath: ROOT_DIR)
    ->withRouter($router)
    ->withControllerFactory(fn(string $class) => /* DI */)
    ->withExceptionHandler(RouteNotFoundException::class, fn($e, $r) => Response::html('404', 404))
    ->build();
```

Configuration is loaded from YAML with typed sections:

```php
$config = Configuration::fromYamlFile(ROOT_DIR . '/config.yml', [
    'render' => RenderConfig::class,
    'leaf' => LeafConfig::class, // custom sections
]);
```

YAML supports `!env` tags for environment variables: `database_host: !env DB_HOST`

### Routing

Routes are declared as attributes on controller methods:

```php
#[Get('/users')]
public function index(): Response { }

#[Get('/users/{id}', constraints: ['id' => '\d+'])]
public function show(int $id): Response { }

#[Post('/users', middlewares: ['auth'])]
public function store(Request $request): Response { }
```

Available: `#[Get]`, `#[Post]`, `#[Put]`, `#[Patch]`, `#[Delete]`, `#[Head]`, `#[Options]`

Class-level attributes: `#[Root('/prefix')]`, `#[Middleware('name')]`

Controller discovery:

```php
$router = (new Router())->discoverControllers(
    namespace: 'App\\Controllers',
    directory: ROOT_DIR . '/app/Controllers',
);
```

Path parameters are auto-injected by name and type-coerced. Type mismatch returns 404.

### Controllers

Extend `Controller` for JSON APIs or use `RenderResponses` trait for HTML:

```php
final class PageController extends Controller
{
    use RenderResponses;

    #[Get('/')]
    public function index(): Response
    {
        return $this->render('home', ['title' => 'Home']);
    }
}
```

Controller convenience methods: `json()`, `created()`, `text()`, `noContent()`, `redirect()`, `abort()`, `validate()`

Lifecycle hooks: override `before(Request): ?Response` (guard/short-circuit) and `after(Request, Response): Response` (decorate).

### Request & Response

Both are immutable value objects:

```php
// Request
$request->method()           // GET, POST, etc.
$request->uri()->path()      // /users/42
$request->body()->get('name') // form/JSON body
$request->query()->get('page') // query string
$request->headers()->bearerToken() // Authorization header
$request->cookies()->get('session')
Request::fromGlobals()       // create from PHP globals

// Response
Response::json(['data' => $items], 200)
Response::html($html, 200)
Response::redirect('/login', 302)
$response->withHeader('X-Custom', 'value')
$response->withStatus(201)
```

### Middleware

Implement `MiddlewareInterface`:

```php
class AuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        if (!$this->isAuthenticated($request)) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }
        return $next($request);
    }
}
```

Register globally or per-route via `middlewares: ['name']` on route attributes.

### Configuration (ConfigSection)

Create typed config sections:

```php
class MyConfig extends ConfigSection
{
    public readonly string $apiKey;
    public readonly bool $debugMode;

    public static function fromArray(array $values): static
    {
        $instance = new static($values);
        $instance->apiKey = $instance->getString('apiKey', '');
        $instance->debugMode = $instance->getBool('debugMode', false);
        return $instance;
    }
}
```

Register when loading config: `Configuration::fromYamlFile($path, ['my_section' => MyConfig::class])`

YAML keys are normalized: `snake_case` becomes `camelCase` in `getString()` calls.

### Localization

```php
// Setup
$loader = new JsonLocaleLoader(ROOT_DIR . '/locale');
$translator = new Translator($loader, 'en');

// Usage
$translator->trans('welcome', ['name' => 'World'], 'fr');

// Global function (requires App::setTranslator)
localize('welcome', ['name' => 'World']);
```

JSON locale files: `locale/{lang}/strings.json` with nested keys accessed via dots: `localize('nav.home')`

### Rendering (Latte)

Templates use the [Latte](https://latte.nette.org/) engine (`.latte` files):

```latte
{layout 'layouts/main.latte'}
{block content}
<h1>{$title}</h1>
{/block}
```

Config:

```yaml
render:
  engine: latte
  directory: app/Views
  cache: cache/latte
  mode: always
```

Extensions can inject global template variables via `beforeRender()`.

### Validation

```php
$form = FormValidator::create()
    ->field('email', Rules::required(), Rules::email())
    ->field('age', Rules::required(), Rules::integer(), Rules::min(18));

$errors = $form->validate($request->body()->all());
if (!$errors->isEmpty()) {
    return $this->json(['errors' => $errors->toArray()], 422);
}
```

## Conventions

- Controllers in `app/Controllers/` with `App\Controllers` namespace
- Views in `app/Views/` as `.latte` files
- Config in `config.yml` at project root
- Locale files in `locale/{lang}/*.json`
- Use `ROOT_DIR` constant for absolute paths
- All HTTP objects are immutable; use `with*()` methods to create modified copies
- Route parameters are type-coerced automatically
