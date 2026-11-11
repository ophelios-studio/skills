---
name: zephyrus
description: Use when building or changing an app on the Zephyrus PHP 8.4+ framework (Composer package `zephyrus-framework/core`). Triggers on `Zephyrus\` imports, `ApplicationBuilder`, `Configuration::fromYamlFile`, a `config.yml` with `application:`, `session:`, `security:` or `database:` sections, attribute routes (`#[Get]`, `#[Post]`, `#[Middleware]`, `#[RequiresEnv]`), `discoverControllers`, Latte views with `RenderResponses`, `SessionMiddleware`, `CsrfMiddleware`, `MaxBodySizeMiddleware`, `FormValidator`, `Database`/`Broker`/`Entity`, `Mailer`, `Uploader`, `Cryptography`, `ContentSecurityPolicy`, and boot errors such as "is not a known section", "is an unknown key" or "Configuration declares security settings that this application does not enforce as declared". Covers the bootstrap, strict configuration, the boot-time security wiring check, sessions and CSRF, routing rules, error reporting, the PostgreSQL layer and the Broker/Service/Entity convention, with a repro for every gotcha.
---

# Zephyrus

Zephyrus is a PHP 8.4+ framework: attribute routing, immutable `Request` and
`Response`, typed YAML configuration that refuses unknown keys, security
middleware whose wiring is checked at boot, Latte views, and a thin PDO layer
for PostgreSQL. No ORM, no migrations.

Everything below was run against the `dev` branch at commit `2842a2b`
(Packagist `dev-dev`, what the apps pin). The last tag, `v0.13.0` (what a bare
`composer require` installs), predates many breaking changes and lacks every
API marked *(dev)*, which means on dev, unreleased since `v0.13.0`; the
bootstrap below does not run on it. The framework's `CHANGELOG.md` lists each
change with the fix to make. Before 1.0 a minor version may break the API. The
boot-time error messages name their own fix: when one disagrees with this file,
follow the message.

## Install

```json
{
    "minimum-stability": "dev",
    "prefer-stable": true,
    "require": {
        "php": "^8.4",
        "zephyrus-framework/core": "dev-dev"
    },
    "autoload": { "psr-4": { "App\\": "app/" } }
}
```

Packagist serves `dev-dev`: no `repositories` entry. The core requires
`ext-intl`, `ext-mbstring`, `ext-pdo`, `ext-sodium` and `ext-fileinfo`, and
brings `latte/latte`, `tracy/tracy`, `symfony/yaml`, `vlucas/phpdotenv` and
`phpmailer/phpmailer`. PostgreSQL needs `pdo_pgsql`.

Where each class lives (the PSR-4 root is `Zephyrus\` under `src/Zephyrus/`):

| Namespace | Classes |
| --- | --- |
| `Zephyrus\Core` | `ApplicationBuilder`, `Application`, `App`, `ExceptionEvent` *(dev)* |
| `Zephyrus\Core\Config` | `Configuration`, `ConfigSection`, `ConfigurationException`, `DatabaseConfig`, `Environment` |
| `Zephyrus\Http` | `Request`, `Response`, `MiddlewareInterface` |
| `Zephyrus\Routing` | `Router` |
| `Zephyrus\Routing\Attribute` | `Get`, `Post`, `Put`, `Patch`, `Delete`, `Head`, `Options`, `Route`, `Root`, `Middleware`, `MiddlewareGroup`, `WithoutMiddleware` *(dev)*, `RequiresEnv` |
| `Zephyrus\Routing\Exception` | `RouteNotFoundException`, `RouteMiddlewareException`, `RouteSignatureException` |
| `Zephyrus\Controller` | `Controller` |
| `Zephyrus\Rendering` | `LatteEngine`, `PhpEngine`, `RenderResponses`, `RenderConfig`, `Asset`, `RenderException` |
| `Zephyrus\Security` | `SecureHeadersMiddleware`, `SecureHeadersConfig`, `MaxBodySizeMiddleware` *(dev)*, `BodyTooLarge` *(dev)*, `CsrfMiddleware`, `CsrfConfig`, `CsrfFailure` *(dev)*, `CsrfTokenManagerInterface`, `ForceHttpsMiddleware`, `AllowedHostsMiddleware`, `ContentSecurityPolicy`, `ContentSecurityPolicyMiddleware`, `Cryptography`, `AuthGuardMiddleware`, `HeaderTokenGuard`, `IpAllowlistGuard`, `RequestAttributeGuard`, `PredicateAuthGuard`, `AllAuthGuard`, `AnyAuthGuard`, `NotAuthGuard` |
| `Zephyrus\Session` | `SessionManager`, `SessionMiddleware`, `SessionCsrfTokenManager`, `DatabaseSessionHandler`, `Flash`, `SessionException` |
| `Zephyrus\Validation` | `FormValidator`, `Rules`, `Rule`, `ErrorBag`, `ValidationException` |
| `Zephyrus\Event` | `EventDispatcher` |
| `Zephyrus\Data` | `Database`, `Broker`, `Entity`, `JsonIgnore`, `Binary` *(dev)*, `DatabaseException`, `PaginationRequest`, `SortRequest`, `FilterRequest`, `PaginatedResult` |
| `Zephyrus\Upload` | `Uploader`, `FileUpload`, `UploadException` |
| `Zephyrus\Localization` | `Translator`, `JsonLocaleLoader` |
| `Zephyrus\Mailer` | `Mailer`, `MailerConfig`, `MailerException`, `MailerFailure` *(dev)* |
| `Zephyrus\Formatting` | `Formatter`, `FormatterException` |
| `Zephyrus\Container` | `Container` |

## Bootstrap

There is no framework `Kernel` base class and no `Application::run()`. The app
writes a small bootstrap class of its own (a convention) that loads the
configuration, builds the router, mounts middleware and calls `build()`.
`Zephyrus\Core\Application` only turns a `Request` into a `Response`. This is
`examples/zephyrus/app/Kernel.php`, which boots and serves as is:

```php
namespace App;

use Zephyrus\Core\Application;
use Zephyrus\Core\ApplicationBuilder;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Http\Request;
use Zephyrus\Rendering\LatteEngine;
use Zephyrus\Routing\Router;
use Zephyrus\Security\CsrfConfig;
use Zephyrus\Security\CsrfMiddleware;
use Zephyrus\Security\MaxBodySizeMiddleware;
use Zephyrus\Security\SecureHeadersMiddleware;
use Zephyrus\Session\SessionCsrfTokenManager;
use Zephyrus\Session\SessionManager;
use Zephyrus\Session\SessionMiddleware;

final class Kernel
{
    public static function run(string $root): void
    {
        $config = Configuration::fromYamlFile($root . '/config.yml');

        // fromGlobals() does not read config.yml: pass the proxy settings yourself.
        $request = Request::fromGlobals(
            trustedProxies: $config->security->trustedProxies,
            trustedHeaders: $config->security->trustedHeaders,
        );

        self::application($root, $config)->handle($request)->send();
    }

    public static function application(string $root, Configuration $config): Application
    {
        $session = new SessionManager();
        $tokens = new SessionCsrfTokenManager($session);

        $views = new LatteEngine($root . '/app/Views', $root . '/cache/latte');
        $views->getLatteEngine()->addFunction('csrfToken', $tokens->getToken(...));

        $router = (new Router())->discoverControllers('App\\Controllers', $root . '/app/Controllers');

        // The first middleware added runs outermost. Each declared security key
        // (headers, max_body_size, csrf) must be enforced here or build() throws.
        return ApplicationBuilder::create()
            ->withConfiguration($config, basePath: $root)
            ->withRouter($router)
            ->withRenderEngine($views)
            ->withMiddleware(new SecureHeadersMiddleware($config->security->headers))
            ->withMiddleware(new MaxBodySizeMiddleware($config->security->maxBodySize))
            ->withMiddleware(new SessionMiddleware($config->session, $session))
            ->withMiddleware(new CsrfMiddleware($tokens, CsrfConfig::fromSecurityConfig($config->security)))
            ->build();
    }
}
```

`public/index.php`:

```php
require dirname(__DIR__) . '/vendor/autoload.php';

App\Kernel::run(dirname(__DIR__));
```

What the builder does and does not do:

- Every `with*()` returns a new builder. A call whose result is dropped mounts
  nothing (gotcha 4): chain, or reassign `$builder = $builder->with...()`.
- `withMiddleware()` mounts a global middleware: it runs on every request,
  including 404 and 405 answers, outermost first. `registerMiddleware('name', $m)`
  only names one; it runs on routes that reference the name.
- `withRenderEngine()` *(dev)* injects the engine into every controller using
  `RenderResponses`, including controllers built by `withControllerFactory()`
  or `withContainer(new Container())` (auto-wires constructor dependencies;
  `bind()`, `singleton()`, `instance()` register your own).
- `withConfiguration($config, basePath: $root)` wires localization (a relative
  `locale_path` resolves against `basePath`). `build()` applies
  `application.debug` (Tracy) and `localization.timezone`. Nothing under
  `security:` or `session:` is applied for you: you mount the middleware, and
  `build()` refuses to boot when a declared security key is not enforced.
- `build()` also sets the `Formatter` behind `format()`. A command-line script
  that never calls `build()` must call `App::setFormatter(new Formatter('en_CA'))`
  first, or `format('decimal', 1234.5)` throws `FormatterException: format('decimal')
  needs a Formatter: call App::setFormatter() first.`
- The framework never reads `.env`. Load it yourself before the configuration:

```php
Dotenv\Dotenv::createImmutable($root)->safeLoad(); // the framework never reads .env itself
$config = Configuration::fromYamlFile($root . '/config.yml');
```

One request per process. `App` keeps its services for the whole process; in
the framework README's words: "This assumes one request per process (php-fpm,
mod_php), as does the PHP session. Persistent workers (RoadRunner, Swoole,
FrankenPHP worker mode) are not supported: `App::reset()` is a testing tool, not
a between-requests hook."

## Configuration

Five built-in sections. Keys are accepted in snake_case or camelCase, one
spelling per key. A key these sections do not know stops the boot with the
closest accepted key (gotcha 1). Besides the keys below, they accept session
`cookie_path`, security `trusted_headers`, localization `date_format`,
`time_format`, `datetime_format` and `grouping_separator`, and database
`charset`, `ssl_mode`, `ssl_root_cert` and `column_cache_version`.

```yaml
application:
  environment: !env APP_ENV, dev   # prod/production, stage/staging, test/testing, dev/development/local
  debug: !env APP_DEBUG, false     # forced off in production and staging

session:
  name: APP_SESSION
  lifetime: 0                      # seconds; 0 ends with the browser
  idle_timeout: 1800
  http_only: true
  secure: auto                     # true, false or auto (secure when the request is https)
  same_site: Lax                   # Strict, Lax or None (None requires secure)

security:
  force_https: false
  allowed_hosts: []                # host names only: no port, no scheme
  max_body_size: 2097152           # bytes, no unit suffix
  trusted_proxies: []              # addresses or CIDR network addresses
  csrf:
    enabled: true
    exceptions: ['#^/webhooks/#']  # quoted, anchored
  encryption:
    key: !env APP_ENCRYPTION_KEY
  headers:
    x_frame_options: DENY
    hsts_max_age: 31536000

localization:
  locale: en
  supported_locales: [en, fr]
  locale_path: locale              # relative to the basePath given to withConfiguration()
  timezone: America/Toronto
  currency: CAD

database:
  host: !env DB_HOST, localhost
  port: !env DB_PORT, 5432
  database: !env DB_NAME, app
  username: !env DB_USERNAME, app
  password: !env DB_PASSWORD
```

- `!env NAME, default` reads `$_ENV` and the process environment, never
  `$_SERVER`. Unset without a default, it reads as `null`.
- Booleans accept only `true`/`false`, `1`/`0`, `on`/`off`, `yes`/`no`.
- Read values through typed properties: `$config->security->maxBodySize`,
  `$config->session->name`, `$config->database` (null when the section is
  absent). After `build()`, the `config('security', 'maxBodySize')` helper
  reads the same values.
- `security.headers` keys: `xFrameOptions`, `xContentTypeOptions`,
  `referrerPolicy`, `xssProtection`, `hstsMaxAge`, `hstsIncludeSubdomains`,
  `csp`, `permissionsPolicy` (or snake_case).
- Localization: `locale_path: locale` reads every `locale/en/*.json` file into
  one catalog; `localize('greeting', ['name' => 'Ada'])` fills `{name}`, and a
  missing key returns the key itself.

Any other top-level key is ignored unless you register a section for it
(gotcha 2). Your own section:

```php
final class BillingConfig extends ConfigSection
{
    public readonly string $provider;
    public readonly int $trialDays;

    public static function fromArray(array $values): static
    {
        $section = new static($values);
        $section->provider = $section->getString('provider', 'none');
        $section->trialDays = $section->getInt('trialDays', 30); // trial_days in YAML
        return $section;
    }
}

$config = Configuration::fromYamlFile($root . '/config.yml', ['billing' => BillingConfig::class]);
$billing = $config->section('billing'); // a BillingConfig
```

A custom section does not refuse unknown keys: a misspelled key there silently
takes the default.

## Security wiring *(dev)*

Declaring a key under `security:` is a promise. `build()` refuses to boot
unless a middleware passed to `withMiddleware()` enforces it with the same
value (gotcha 3):

| Declared | Mount globally |
| --- | --- |
| `force_https: true` | `new ForceHttpsMiddleware()` |
| non-empty `allowed_hosts` | `new AllowedHostsMiddleware($config->security->allowedHosts)` |
| `max_body_size` above 0 | `new MaxBodySizeMiddleware($config->security->maxBodySize)` |
| `headers` | `new SecureHeadersMiddleware($config->security->headers)` |
| `csrf` (enabled) | `new CsrfMiddleware($tokens, CsrfConfig::fromSecurityConfig($config->security))` |

- Only declared keys are checked. `max_body_size` still defaults to `2097152`
  when you leave it out, but nothing enforces it unless `MaxBodySizeMiddleware`
  is mounted: with no middleware, requests of any size reach your routes.
- A middleware registered under a route name, or wrapped inside another
  middleware, does not count. When a protection really lives elsewhere (a
  reverse proxy, a wrapping middleware), say so:
  `withAcknowledgedSecurityKeys(['csrf'])` (names: `forceHttps`,
  `allowedHosts`, `csrf`, `maxBodySize`, `headers`, bare or `security.`-prefixed).
- Not checked: `trusted_proxies`, `session`, `encryption`. Mount
  `SessionMiddleware` yourself, and pass the proxies to `Request::fromGlobals()`.
- `withoutSecurityWiringCheck()` turns the whole check off; prefer naming the
  one key with `withAcknowledgedSecurityKeys()`.

A body over `max_body_size` (the declared `Content-Length`, else the raw body)
is a 413: `text/plain` when `Accept` lists `text/html`, JSON
`{"error":"Request body too large."}` otherwise. It is a budget, not a
defence: also set the web server's limit (`client_max_body_size`,
`LimitRequestBody`) and `post_max_size`. To answer yourself, pass `onFailure`
(a `BodyTooLarge` carries `bytes` and `maxBytes`); returning `null` keeps the
default:

```php
$builder = $builder->withMiddleware(new MaxBodySizeMiddleware(
    $config->security->maxBodySize,
    onFailure: fn (Request $request, BodyTooLarge $refusal): ?Response
        => Response::html('<h1>That file is too large (limit ' . $refusal->maxBytes . ' bytes).</h1>', 413),
));
```

## Routing

```php
#[Root('/api/projects')]
#[Middleware('auth')]
final class ProjectController extends Controller
{
    #[Get('/')]
    public function index(Request $request): Response
    {
        $page = (int) $request->query('page', 1);
        return $this->json(['page' => $page]);
    }

    #[Get('/{id}', constraints: ['id' => '\d+'], name: 'projects.show')]
    public function show(int $id, int $userId): Response
    {
        return $this->json(['id' => $id, 'viewer' => $userId]);
    }

    #[Post('/')]
    public function store(Request $request): Response
    {
        return $this->created(['name' => $request->body()->get('name')]);
    }

    #[Delete('/{id}', constraints: ['id' => '\d+'])]
    public function destroy(int $id): Response
    {
        return $this->noContent();
    }
}
```

- Attributes live in `Zephyrus\Routing\Attribute\` (singular): `Get`, `Post`,
  `Put`, `Patch`, `Delete`, `Head`, `Options`, `Route`, `Root`, `Middleware`,
  `MiddlewareGroup`, `RequiresEnv`, `WithoutMiddleware`.
- Action parameters resolve in order: a `Request` type, a route placeholder of
  the same name, a request attribute of the same name (here `$userId`, set by
  the `auth` middleware), then the declared default. The query string never
  binds (`?n=3` leaves `int $n = 5` at 5): read it with `$request->query()`. A
  value that does not cast to the declared type answers 404. A constraint is a
  regex fragment.
- A missing record answers 404 like an unknown route: `return $this->abort(404)`,
  or `throw new RouteNotFoundException('project')` from deeper code.
- A POST with a `_method` field or an `X-HTTP-Method-Override` header of `PUT`,
  `PATCH` or `DELETE` is routed as that method (`fromGlobals()` only).
- A static route beats a parameterized one whatever the order (`/users/me`
  wins over `/users/{id}`). A GET route also answers HEAD; a wrong method
  answers 405 with `Allow`. A trailing slash is tolerated.
- `route('projects.show', ['id' => 12], ['tab' => 'files'])` builds
  `/api/projects/12?tab=files` (in PHP and in Latte).
- `#[Root]`, `#[Middleware]` and `#[RequiresEnv]` on a parent class apply to its
  subclasses. Route attributes on a parent's methods do not (gotcha 14).
- `discoverControllers()` registers every concrete class under the directory
  whose namespace matches; a wrong directory or namespace registers nothing,
  silently (gotcha 15).
- `#[WithoutMiddleware(SessionMiddleware::class)]` *(dev)* on a class or method
  skips a global middleware for matched routes (a stateless webhook, a health
  probe). The framework's security middleware (`ForceHttps`, `AllowedHosts`,
  `Csrf`, `MaxBodySize`, `SecureHeaders`, `ContentSecurityPolicy`) cannot be
  skipped: registration throws (gotcha 16).

One codebase, several processes: `#[RequiresEnv]` registers a class's routes
only when a variable matches (exactly, case-sensitive):

```php
#[Root('/v1')]
#[RequiresEnv('MODE', 'api')]
abstract class ApiController extends Controller
{
}

final class StatusController extends ApiController
{
    #[Get('/status')]
    public function status(): Response
    {
        return $this->json(['ok' => true]);
    }
}
```

With `MODE` unset, `GET /v1/status` is not registered (404). A controller
without `#[RequiresEnv]`, such as a `/health` probe, registers in every process.

## Request and response

```php
$request = Request::fromArray(
    'POST',
    '/api/projects?page=2',
    body: ['name' => 'Atlas'],
    headers: ['Authorization' => 'Bearer abc123', 'Content-Type' => 'application/x-www-form-urlencoded'],
    cookies: ['theme' => 'dark'],
);
$request->method;                   // 'POST' (a property)
$request->path();                   // '/api/projects'
$request->query('page', 1);         // '2'; $request->query is the whole array
$request->body()->get('name');      // 'Atlas' (form or JSON body)
$request->body()->all();            // ['name' => 'Atlas']
$request->headers()->bearerToken(); // 'abc123'
$request->cookies()->get('theme');  // 'dark'
$request->attribute('userId');      // null: set by a middleware through withAttribute()
```

```php
$response = Response::json(['ok' => true], 201)
    ->withHeader('Cache-Control', 'no-store');
$response->getHeader('cache-control');   // 'no-store' (any case)
$response->headers['Cache-Control'];     // null: names are stored lowercased
$back = Response::localRedirect('//evil.example/phish', '/'); // off-site target: redirects to '/'
```

`Controller` (optional base class) adds `json()`, `created()`, `text()`,
`noContent()`, `respond()`, `redirect()`, `localRedirect()` *(dev)*, `abort()`,
`abortJson()` and `validate()`, plus `before()`/`after()` hooks; an override of
`before()` must call `parent::before($request)` or `$this->request` stays
unset. Plain classes work as controllers too. `withHeader()` refuses control
characters; check untrusted values with `Response::isValidHeaderValue()`, and
redirect to user-supplied targets with `localRedirect()`. Uploads:
`$request->file('avatar')` returns a `FileUpload` or `null`; store it with
`Uploader` (see `references/uploads.md`).

## Middleware

```php
final class RequireUserMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        $userId = session('user_id');
        if ($userId === null) {
            return Response::json(['error' => 'Sign in first.'], 401);
        }
        return $next($request->withAttribute('userId', (int) $userId));
    }
}

$builder = $builder->registerMiddleware('auth', new RequireUserMiddleware());
```

`#[Middleware('auth')]` on a class or method applies it. Ready-made guards:
`new AuthGuardMiddleware(new PredicateAuthGuard(fn (Request $r): bool => ...))`,
`HeaderTokenGuard`, `IpAllowlistGuard`, composable with `AllAuthGuard`,
`AnyAuthGuard` and `NotAuthGuard` (refusal: 401 JSON `{"error":"Unauthorized"}`).
`$router->middlewareGroup('admin', ['auth', 'audit'])` names a list, expanded
when routes are added: declare groups before `discoverControllers()`. `build()`
refuses a route that names a middleware or group it cannot resolve (gotcha 17).

## Sessions, CSRF and flash messages

`SessionMiddleware` starts the session (and registers it for `session()` and
`Flash`). Mount it before `CsrfMiddleware`, as in the bootstrap above. Without
it, `session([...])` writes and flash messages are dropped silently (gotcha 19).
Tests and CLI scripts use array storage: `new SessionManager([])`. After a
sign-in, call `$session->regenerate()` and the token manager's `regenerate()`
before storing the user id: a new session id defeats fixation, a new token
stops one read from the fixed session from staying valid. Take the session
from `$request->attribute('session')` (set by `SessionMiddleware`) and wrap it
in `new SessionCsrfTokenManager($session)`, which shares the stored token with
the one in your bootstrap (the same session key as the CSRF middleware: pass
yours as the second argument if your bootstrap set a custom one). On sign-out,
`$session->destroy()`, which throws `SessionException` when the store refuses.

To keep sessions in PostgreSQL, register `DatabaseSessionHandler` after
`new SessionManager()` and before `build()`:
`$session->setHandler(new DatabaseSessionHandler(fn (): Database => Database::fromConfig($config->database)))`.
It writes to `public.session` by default (the class docblock gives the
`CREATE TABLE`): `$session->set('user_id', 7)` stores one row with `data`
`user_id|i:7;`. Behind PgBouncer transaction pooling, pass `lockSessions: false`.

There is no automatic token injection: render the field in every form.

```latte
<input type="hidden" name="_csrf_token" value="{csrfToken()}">
```

JavaScript sends the same token in the `X-CSRF-Token` header. A refusal is a
403: `text/plain` when `Accept` lists `text/html`, JSON otherwise. To render
your own page:

```php
$builder = $builder->withMiddleware(new CsrfMiddleware(
    $tokens,
    CsrfConfig::fromSecurityConfig($config->security),
    onFailure: fn (Request $request, CsrfFailure $failure): ?Response
        => Response::html('<h1>This page expired. Reload it and try again.</h1>', 403),
));
```

Exempt a webhook path with `security.csrf.exceptions` (anchored and quoted,
gotcha 5). `#[WithoutMiddleware(CsrfMiddleware::class)]` is refused.
`Flash::success('Saved.')` before a redirect, `Flash::readAll()` after it
(`->success`, `->error`, `->warning`, `->info`).

## Views

Latte escapes output; the `PhpEngine` does not (use `e()`). Mount an engine with
`withRenderEngine()` and render from a controller that uses `RenderResponses`:
`$this->render('home', ['name' => $name], 422)` renders
`app/Views/home.latte`. Without an engine, every render is a 500 (gotcha 13).
Instead of building the engine by hand, `render:` can configure it once
registered:

```yaml
render:
  engine: latte          # or php
  directory: app/Views
  cache: cache/latte
```

```php
$config = Configuration::fromYamlFile($root . '/config.yml', ['render' => RenderConfig::class]);
$views = $config->section('render')->createEngine($root); // LatteEngine or PhpEngine
App::setAsset(new Asset($root . '/public'));
```

With an `Asset` set, `{asset('/css/app.css')}` renders a cache-busting URL
such as `/css/app.css?v=145ccd37` (the first 8 hex characters of the file's
md5); a missing file is returned unchanged. Latte templates can call `route()`
and other PHP functions directly; add your own through
`$latteEngine->getLatteEngine()->addFunction()`.

## Validation

```php
$form = new FormValidator([
    'email' => [Rules::required(), Rules::email()],
    'age' => [Rules::required(), Rules::integer(), Rules::min(18)],
]);
$errors = $form->validate($request->body()->all());
if ($errors->hasErrors()) {
    return $this->json(['errors' => $errors->toArray()], 422);
}
```

`ErrorBag` also has `hasErrorsFor()`, `errorsFor()`, `firstFor()`,
`failingFields()` and `allMessages()`. For an API, `validateOrFail()` throws and
the kernel answers 422:

```php
(new FormValidator(['email' => [Rules::required(), Rules::email()]]))
    ->validateOrFail($request->body()->all()); // throws ValidationException: the kernel answers 422
```

That 422 is `{"errors":{...}}` only when `Accept` lists JSON; anything else (a
browser, a `fetch()` without that header) gets the bare text `Unprocessable
Entity`, so HTML forms use `validate()` and re-render. `Rules::integer()`
accepts the string `'20'` and refuses `true`. Every failing rule adds its
message. `Rules::required()` passes `'   '`: add `Rules::notBlank()`. A field
without `required()` skips its rules when empty. Custom rule:
`Rule::of(fn (mixed $value): bool => $value === 'ok', 'Wrong code.')`.

## Errors and debugging

A thrown exception becomes a fixed answer: 404 for no route or a parameter that
does not cast, 405, 422 for `ValidationException`, otherwise 500
`Internal Server Error` (JSON when asked). The message never reaches the client,
and without an event dispatcher it reaches nobody (gotcha 13). Report and
customize:

```php
$events = new EventDispatcher();
$events->addListener(ExceptionEvent::class, function (ExceptionEvent $event): void {
    if (!$event->isRoutingFailure()) { // skip 404 and 405
        error_log((string) $event->getException()); // or your error tracker
    }
});

$builder = $builder
    ->withEventDispatcher($events)
    ->withExceptionHandler(
        RouteNotFoundException::class,
        fn (\Throwable $e, Request $request): Response => Response::html('<h1>Page not found</h1>', 404),
    );
```

A handler may return `null` to keep the built-in answer. To let Tracy show an
error in development, rethrow the same object:

```php
$debug = $builder->isDebugEnabledForBoot();

$builder = $builder->withExceptionHandler(\Throwable::class, function (\Throwable $e, Request $request) use ($debug): ?Response {
    if ($debug) {
        throw $e; // the same object: handle() rethrows it, so Tracy can show it
    }
    error_log($e::class . ': ' . $e->getMessage());
    return Response::html('<h1>Something went wrong</h1>', 500);
});
```

Take `$debug` from `$builder->isDebugEnabledForBoot()` on the fully configured
builder, never from `$config->application->debug`: in production and staging
`build()` turns Tracy off but leaves that value `true`, and a rethrow without
Tracy reaches PHP's own handler, which prints the message, server paths and
trace whenever `display_errors` is on. "Fully configured" means after
`withConfiguration()` (it returns `false` before) and after
`withProductionDebugAcknowledged()` if you use it (in a production-like
environment it is `true` only then).

A handler that throws anything else yields the fixed 500. With
`application.debug: true`, Tracy only shows itself to loopback clients. Behind
Docker the browser arrives from the bridge gateway, so name it with a secret
and send that secret in a `tracy-debug` cookie:

```php
// DEBUG_CLIENTS holds "secret@172.17.0.1"; unset or empty names no client beyond loopback.
$builder = $builder->withDebugClientAllowlist(env('DEBUG_CLIENTS'));
```

The secret is a credential: keep it in the environment or an uncommitted
`.env`, never in git. Each entry is an exact address or `secret@address`; an
empty secret, an empty address or a range is refused (gotcha 30). In
`production` and `staging`, debug is forced off (gotcha 9) unless you call
`withProductionDebugAcknowledged()`.

## Database

```php
$config = Configuration::fromYamlFile($root . '/config.yml');
$db = Database::fromConfig($config->database);
```

PostgreSQL only. `Database` has `select`, `selectOne`, `selectValue`,
`selectInt`, `selectString`, `selectBool`, `selectFloat`, `count`, `exists`,
`paginate`, `execute`, `insert`, `insertGetId`, `update`, `delete`,
`transaction` (savepoints when nested; the callback receives the `Database`)
and `pdo()`. Parameters are bound by type (`null`, booleans, `DateTimeInterface`,
`BackedEnum`, `Binary` for bytea); arrays are refused. Rows come back as
`stdClass` with integers, floats, booleans, PostgreSQL arrays as PHP arrays and
JSON/JSONB decoded.

One SQL statement per `execute()`: `"INSERT ...; INSERT ..."` inserts nothing
and throws a `DatabaseException` whose `getMessage()` is `Query failed [SQLSTATE
42601]: statement and driver detail withheld, read sql() and driverMessage() to
inspect them` and whose `driverMessage()` ends in `cannot insert multiple
commands into a prepared statement`.

The Broker/Service/Entity split is a convention of the apps built on Zephyrus
(typically `app/Models/{Domain}/{Brokers,Services,Entities}`), not something
the framework enforces. `Broker` and `Entity` are framework base
classes; the Service is a plain class that calls the broker and hydrates
entities. Raw SQL lives in brokers:

```php
final class ProjectBroker extends Broker
{
    public function findById(int $id): ?stdClass
    {
        return $this->selectOne('SELECT * FROM project WHERE id = ?', [$id]);
    }

    public function insert(string $name, string $budget): int
    {
        return (int) $this->insertRowGetId(
            'INSERT INTO project (name, budget) VALUES (?, ?) RETURNING id',
            [$name, $budget],
        );
    }
}

final class Project extends Entity
{
    public int $id;
    public string $name;
    public string $budget;
    public array $tags;
    public bool $archived;
}
```

`new ProjectBroker($db)`, then `Project::build($row)` (`buildArray($rows)` for
lists) casts each column to the property's declared type, maps backed enums
with `from()` and nested `Entity` properties (from a JSON object column), and
`json_encode()` skips unassigned properties and those marked `#[JsonIgnore]`.
A nullable column needs a nullable property (gotcha 31). Pagination, sorting
and filtering from request input: `references/database.md`. Keep money exact
before it reaches an entity (gotcha 23):

```php
$db->registerTypeConversion('NUMERIC', static fn (string $value): string => $value); // keep money exact
```

## Mail

Register `'mailer' => MailerConfig::class` (keys `smtp.host`, `smtp.port`,
`smtp.username`, `smtp.password`, `smtp.encryption`, `from.address`,
`from.name`). `smtp.encryption` takes `tls` (the default), `ssl`, or `''` for
none; `encryption: none` stops the boot with `Configuration section 'mailer'
field 'smtp.encryption' has invalid value "none": must be one of 'tls', 'ssl' or
'' (empty, meaning no encryption at all); ...`.

```php
try {
    (new Mailer($config->section('mailer')))
        ->to('ada@example.com')
        ->subject('Welcome')
        ->text('Hello Ada')
        ->send();
} catch (MailerException $e) {
    error_log($e->failure->value . ': ' . $e->transportMessage()); // the message itself carries no address or SMTP text
}
```

Build one `Mailer` per message: `send()` keeps the recipients, so a reused
instance mails the next message to the previous addresses too (gotcha 32).
`html()`, `template('emails/welcome', [...])` (with a render engine as second
constructor argument), `attach($path)` and `attachContent($bytes, 'report.pdf')`
*(dev)* complete the builder.

## Testing

The application handles a `Request` in-process, so tests need no server. The
test below runs on `examples/zephyrus` plus one controller that signs in
(`App\Controllers\AccountController`, the sign-in step of the sessions
section):

Install PHPUnit with `composer require --dev phpunit/phpunit`, map `Tests\\` to
`tests/` under `autoload-dev` in `composer.json`, then run
`vendor/bin/phpunit tests/AppTest.php`.

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use Zephyrus\Controller\Controller;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;
use Zephyrus\Routing\Attribute\Post;
use Zephyrus\Session\SessionCsrfTokenManager;

final class AccountController extends Controller
{
    #[Post('/sign-in')]
    public function signIn(Request $request): Response
    {
        // Test stand-in: a real sign-in checks the credentials before this point.
        $session = $request->attribute('session');
        $session->regenerate();
        (new SessionCsrfTokenManager($session))->regenerate();
        $session->set('user_id', 42);

        return $this->redirect('/me');
    }

    #[Get('/me')]
    public function me(): Response
    {
        return $this->json(['user' => session('user_id')]);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Tests;

use App\Kernel;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\App;
use Zephyrus\Core\Application;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Http\Request;

final class AppTest extends TestCase
{
    private static Application $app;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__);
        self::$app = Kernel::application($root, Configuration::fromYamlFile($root . '/config.yml'));
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        App::reset();
    }

    public function testAJsonRouteAnswersWithItsPayload(): void
    {
        $response = self::$app->handle(Request::fromArray('GET', '/api/hello/ada'));

        self::assertSame(200, $response->status);
        self::assertSame(['message' => 'Hello, ada'], json_decode($response->body, true));
    }

    public function testAPostWithoutACsrfTokenIsRefused(): void
    {
        $response = self::$app->handle(Request::fromArray('POST', '/greet', body: ['name' => 'Ada']));

        self::assertSame(403, $response->status);
    }

    public function testAPostCarryingTheTokenFromAPreviousGetIsAccepted(): void
    {
        $page = self::$app->handle(Request::fromArray('GET', '/'));
        preg_match('/name="_csrf_token" value="([0-9a-f]+)"/', $page->body, $found);

        $response = self::$app->handle(Request::fromArray('POST', '/greet', body: ['name' => 'Ada', '_csrf_token' => $found[1]]));

        self::assertSame(200, $response->status);
    }

    public function testTheSessionCarriesAcrossTwoRequests(): void
    {
        $page = self::$app->handle(Request::fromArray('GET', '/'));
        preg_match('/name="_csrf_token" value="([0-9a-f]+)"/', $page->body, $found);

        $signedIn = self::$app->handle(Request::fromArray('POST', '/sign-in', body: ['_csrf_token' => $found[1]]));
        $me = self::$app->handle(Request::fromArray('GET', '/me'));

        self::assertSame(302, $signedIn->status);
        self::assertSame(['user' => 42], json_decode($me->body, true));
    }

    public function testAnAnonymousRequestSeesNoUser(): void
    {
        $me = self::$app->handle(Request::fromArray('GET', '/me'));

        self::assertSame(['user' => null], json_decode($me->body, true));
    }
}
```

The application is built once per class. `App::reset()` clears the statics
`App` holds (configuration, session, formatter, asset, URL generator,
translator, nonce), which the next request registers again. `$_SESSION = []` is
a separate step: in-process there is no cookie to carry (`handle()` returns no
`Set-Cookie`), the PHP session lives for the whole process, and `reset()` does
not touch it, so without that line a test signed in earlier leaks into the next
one. The same process-wide session is why a GET followed by a POST shares one
CSRF token.

`Request::fromArray()` takes the decoded body in `body:`; it does not decode a
JSON `rawBody:` (gotcha 21).

## Other modules

Read the reference file when the task touches its subject:

| Module | For | Read |
| --- | --- | --- |
| `Upload` | storing uploads, checked against their real bytes | `references/uploads.md` |
| `Security` | Content-Security-Policy with nonces; encryption, password hashing, random tokens | `references/security.md` |
| `Localization`, `Formatting` | translation catalogs and pipes, the request locale, money and dates, Latte filters | `references/localization.md` |
| `Data` | pagination, sorting and filtering from request input; hydration rules | `references/database.md` |
| `Container` | auto-wired controllers through `withContainer()` (see Bootstrap) | |
| `Event` | `EventDispatcher`; listen to `ExceptionEvent` (see Errors) | |

`FileSystem`, `Exceptions`, the route cache and `Http\SseEmitter` are framework
internals or niche helpers that most apps never call.

## Gotchas, each with its repro

Every repro below shows the input, then the observed result on `2842a2b`. The
PostgreSQL repros (23 to 28) were last run on `0f65185`; the database code has
not changed since. Repros marked *(upgrading from 0.13)* only concern an app
written for `v0.13.0`; a new app can skip them.

**1. A misspelled section or key stops the boot.** Fix the spelling; keep one
spelling per key.

```text
in:  sesion:
       name: APP
out: ConfigurationException: Configuration key 'sesion' is not a known section: did you mean "session"? A section of your own needs a factory registered under its name in the $sectionFactories argument of the Configuration factories.
in:  session:
       samesite: Lax
out: ConfigurationException: Configuration section 'session' field 'samesite' is an unknown key: did you mean "sameSite"?
in:  security: { max_body_size: 10, maxBodySize: 10 }
out: ConfigurationException: Configuration section 'security' sets both 'maxBodySize' and 'max_body_size': keep one.
```

**2. An unregistered section is ignored silently.** `render:`, `mailer:` or
your own `billing:` do nothing until registered through the second argument of
`Configuration::fromYamlFile()`.

```text
in:  render: { engine: latte } and project: { name: x }, loaded without factories
out: hasSection('render') === false, hasSection('project') === false
in:  billing: { trial_dayz: 14 } with ['billing' => BillingConfig::class]
out: trialDays = 30 (the typo takes the default, no error)
```

**3. A declared security key without its middleware stops the boot.**

```text
in:  security: { max_body_size: 1048576, csrf: { enabled: true } }, build() with no middleware
out: ConfigurationException: Configuration declares security settings that this application does not enforce as declared:
       - security.csrf is not enforced as declared: mount new Zephyrus\Security\CsrfMiddleware(...) globally with withMiddleware(), built from the configuration's security through CsrfConfig::fromSecurityConfig()
       - security.maxBodySize is not enforced as declared: mount new Zephyrus\Security\MaxBodySizeMiddleware(...) globally with withMiddleware(), built from the configuration's security->maxBodySize
in:  max_body_size: 1000 with new MaxBodySizeMiddleware(5000)
out: ... carries a looser limit (5000 bytes) than security.maxBodySize (1000 bytes); give it a positive limit no larger than security.maxBodySize
in:  csrf.exceptions declared, CsrfMiddleware built with new CsrfConfig()
out: ... the global Zephyrus\Security\CsrfMiddleware does not exclude "#^/webhooks/#", which security.csrf.exceptions lists; remove it from security.csrf.exceptions, or build the middleware with CsrfConfig::fromSecurityConfig()
in:  headers declared, SecureHeadersMiddleware(SecureHeadersConfig::defaults())
out: ... the global Zephyrus\Security\SecureHeadersMiddleware carries another configuration; build the middleware from the configuration's security->headers
in:  withAcknowledgedSecurityKeys(['max_body_size'])
out: InvalidArgumentException: Unknown security key "max_body_size". Accepted names: forceHttps, allowedHosts, csrf, maxBodySize, headers, each short or prefixed with "security.". Did you mean "maxBodySize"?
```

**4. A dropped builder clone mounts nothing.** The first line below gets the
same `security.maxBodySize is not enforced as declared` refusal as gotcha 3:

```php
$builder->withMiddleware(new MaxBodySizeMiddleware($config->security->maxBodySize)); // result dropped
$builder = $builder->withMiddleware(new MaxBodySizeMiddleware($config->security->maxBodySize)); // mounted
```

**5. CSRF exemptions must be quoted and anchored.** An unquoted `#` starts a
YAML comment.

```text
in:  exceptions:
       - #^/webhooks/#
out: ConfigurationException: Configuration section 'security' field 'csrfExceptions[0]' has invalid value null: each entry must be a non-empty string; quote a pattern that starts with "#" in YAML.
in:  exceptions: ['#/webhooks#']  (then CsrfConfig::fromSecurityConfig())
out: InvalidArgumentException: security.csrf.exceptions[0] must start with "^" so it cannot match in the middle of a path: #/webhooks# (expected a shape such as #^/webhooks/#).
```

**6. Security values are strict.**

```text
in:  max_body_size: 2M
out: ConfigurationException: Configuration section 'security' field 'max_body_size' has invalid value "2M": must be a number of bytes, for example 2097152 (no unit suffix).
in:  allowed_hosts: [localhost:8080]
out: ConfigurationException: Configuration section 'security' field 'allowedHosts[0]' has invalid value "localhost:8080": ports are not matched: list "localhost" only.
in:  allowed_hosts: ~
out: ConfigurationException: Configuration section 'security' field 'allowedHosts' has invalid value null: an empty list is written [].
in:  csrf: true
out: ConfigurationException: Configuration section 'security' field 'csrf' must be a mapping, such as csrf: { enabled: false }.
```

**7. Automatic CSRF token injection is gone** *(upgrading from 0.13)*. Delete
`auto_html` and render the hidden field (see Sessions).

```text
in:  csrf: { auto_html: true }
out: ConfigurationException: Configuration section 'security' field 'csrf.auto_html' has invalid value true: remove this line. Automatic token injection is not supported because it cannot follow the browser's HTML parsing and could send the token to another site. Add a hidden "_csrf_token" field to your form templates, filled with the escaped value of CsrfTokenManagerInterface::getToken().
```

**8. Database settings refuse what they used to tolerate.** Split a URL into
`host`, `port`, `database`, `username`, `password`; delete `emulate_prepares`.

```text
in:  database: postgres://u:p@h/db
out: ConfigurationException: Configuration section 'database' field 'database' has invalid value "postgres://***@h/db": must be a database name, not a connection URL.
in:  emulate_prepares: false   (upgrading from 0.13)
out: ConfigurationException: Configuration section 'database' field 'emulate_prepares' has been REMOVED from Zephyrus and is no longer honoured. ... To fix: delete this line from the database section.
```

**9. An unrecognized environment is production.** Debug then goes off.

```text
in:  application: { environment: prodution, debug: true }
out: environment = Production, isDebugEnabledForBoot() = false
in:  no environment key
out: environment = Production, debug = false
in:  application: { environment: production, debug: true }, build()
out: error_log: Zephyrus: application.debug is true while application.environment is production-like. Debug output has been FORCED OFF for this boot: ... If this is deliberate, call ApplicationBuilder::withProductionDebugAcknowledged(); even then, only clients named by withDebugClientAllowlist() (or loopback) can see it.
```

**10. Booleans are strict, and an unset `!env` is null.** Give boolean tags a
default.

```text
in:  debug: maybe
out: ConfigurationException: Configuration section 'application' field 'debug' has invalid value "maybe": is not a boolean; use true/false, 1/0, on/off or yes/no.
in:  debug: !env APP_DEBUG   (APP_DEBUG unset)
out: ConfigurationException: Configuration section 'application' field 'debug' has invalid value null: is not a boolean; use true/false, 1/0, on/off or yes/no; an unset !env without a default reads as null; add a default, such as !env NAME, false.
in:  debug: 'false'
out: false
```

**11. `!env` never reads `$_SERVER`, and refuses request-controlled names.** A
value set with Apache `SetEnv` or `fastcgi_param` is invisible: set it in the
process environment (container env, php-fpm `env[NAME]`) or `.env`.

```text
in:  $_SERVER['APP_ENV'] = 'dev' only; environment: !env APP_ENV, prod
out: environment = Production
in:  environment: !env HTTP_APP_ENV, dev
out: ConfigurationException: !env tag at "application.environment": "HTTP_APP_ENV": names starting with HTTP_ can be set per request by the client request headers, so they are never read as configuration; rename the variable.
```

**12. Built-in sections are not read through `section()`.**

```text
in:  $config->section('database')   (also config('Database'))
out: InvalidArgumentException: Section "database" is a built-in typed property; use $configuration->database or config('database') instead.
in:  Configuration::fromYamlFile($path, ['database' => RenderConfig::class])   (upgrading from 0.13)
out: InvalidArgumentException: Section factory "database" collides with the built-in section "database"; read it with $configuration->database instead.
```

**13. Errors are invisible without an event dispatcher.** Each of these answers
a bare 500 `Internal Server Error`; the reason only shows in an
`ExceptionEvent` listener (see Errors).

```text
in:  a RenderResponses controller with no engine mounted, GET /view
out: 500 "Internal Server Error"; ExceptionEvent: Zephyrus\Rendering\RenderException: No render engine has been configured. Call withRenderEngine() on the builder, or setRenderEngine() from your own controller factory.
in:  throw new RuntimeException('database password leaked in this message'), Accept: application/json
out: 500 {"error":{"status":500,"message":"Internal Server Error"}} (the message stays server-side)
```

**14. Routes on a parent's methods are not registered for its subclasses.**
Only methods a class declares itself are read.

```text
in:  abstract BaseController { #[Get('/inherited')] public function inherited() }, ChildController extends it
out: GET /inherited => 404; ExceptionEvent: No route matched "GET" "/inherited"
```

**15. `discoverControllers()` fails silently.**

```text
in:  discoverControllers('App\\Controllers', '/no/such/dir')
out: 0 routes, no error
in:  discoverControllers('Wrong\\Namespace', <the real controllers directory>)
out: 0 routes, no error
```

**16. Route paths the router refuses at registration.**

```text
in:  #[Get('/files/{name}.pdf')]
out: RouteSignatureException: Invalid route path "/files/{name}.pdf": segment "{name}.pdf" mixes a placeholder with text; capture the whole segment with a constraint or give the placeholder its own segment
in:  #[Get('/posts/{id?}')]
out: RouteSignatureException: Invalid route parameter name "id?" on route "/posts/{id?}": optional placeholders are not supported; declare a second route without that segment
in:  #[Get('/search?q={q}')]
out: RouteSignatureException: Invalid route path "/search?q={q}": the request path ends at "?", so this route would only match "/search"; declare "/search" and read the query string from the request
in:  #[WithoutMiddleware(CsrfMiddleware::class)]
out: RouteMiddlewareException: Route "GET /hook" cannot skip "Zephyrus\Security\CsrfMiddleware": it is a framework security middleware. Exempt the path under security.csrf.exceptions instead
in:  #[WithoutMiddleware(MaxBodySizeMiddleware::class)] on #[Post('/hook')]
out: RouteMiddlewareException: Route "POST /hook" cannot skip "Zephyrus\Security\MaxBodySizeMiddleware": it is a framework security middleware
```

**17. `build()` refuses a route middleware it cannot resolve.** Every name in
`#[Middleware]` must be registered with `registerMiddleware()` before `build()`,
and a group must be declared before the routes that use it.

```text
in:  #[Middleware('auht')] on GET /typo, registerMiddleware('auth', ...)
out: RouteMiddlewareException: Route middleware is not registered: "auht" on GET /typo; registered names: "auth". Register it with registerMiddleware() before build(), or, for a group, define it with Router::middlewareGroup() before the routes that name it.
in:  (new Router())->controller(AdminController::class)->middlewareGroup('admin', ['token'])
out: RouteMiddlewareException: Route middleware is not registered: "admin" on GET /admin; registered names: "token". Register it with registerMiddleware() before build(), or, for a group, define it with Router::middlewareGroup() before the routes that name it.
in:  (new Router())->middlewareGroup('admin', ['token'])->controller(AdminController::class)
out: GET /admin => 401 without the token, 200 with it
```

**18. `#[RequiresEnv]` matches exactly, from the real environment.**

```text
in:  #[RequiresEnv('MODE', 'api')] with $_SERVER['MODE'] = 'api' only, or MODE=API
out: route not registered (404)
in:  MODE=api in the process environment (putenv, container env, .env loaded by phpdotenv)
out: GET /v1/status registered
```

**19. Without `SessionMiddleware`, session writes vanish.** With a session
manager registered but no session started, they throw instead.

```text
in:  session(['user_id' => 7]); session('user_id')   (no SessionMiddleware)
out: null, no error
in:  Flash::success('Saved.'); Flash::readAll()     (no SessionMiddleware)
out: {"success":[],"error":[],"warning":[],"info":[]}
in:  (new SessionManager())->set('user_id', 7) before start()
out: SessionException: Cannot write "user_id" to the session: no session is active. Run SessionMiddleware on this route, or call start(); in a test, pass an array to new SessionManager([]).
```

**20. Behind a proxy, `fromGlobals()` needs the proxy list.** It never reads
`security.trusted_proxies` on its own.

```text
in:  REMOTE_ADDR 10.0.0.5, X-Forwarded-For 203.0.113.9, X-Forwarded-Proto https; Request::fromGlobals(...)
out: clientIp() = 10.0.0.5, scheme = http
in:  the same with trustedProxies: ['10.0.0.0/8']
out: clientIp() = 203.0.113.9, scheme = https
```

**21. A malformed JSON body reads as empty.** Check `isMalformed()` before
validating, or a broken payload fails as "required" fields.

```text
in:  Content-Type: application/json, body {"name": "Atlas"   (fromGlobals)
out: body()->isMalformed() = true, body()->all() = []
in:  Request::fromArray('POST', '/x', headers: [JSON], rawBody: '{"name": "Atlas"}')
out: body()->get('name') = null (fromArray only takes the decoded body: body: ['name' => 'Atlas'])
```

**22. Response header names are stored lowercased.**

```text
in:  Response::json([...])->withHeader('Cache-Control', 'no-store'); $response->headers['Cache-Control']
out: Warning: Undefined array key "Cache-Control" (null); getHeader('Cache-Control') = 'no-store'
in:  Response::text('x')->withHeader('X-Name', "a\r\nSet-Cookie: x=1")
out: InvalidArgumentException: Invalid value for HTTP header "X-Name": it contains a control character other than HTAB.
```

**23. NUMERIC comes back as a float.** Money loses precision, and a `string`
entity property gets scientific notation. Register the passthrough shown in
Database.

```text
in:  budget NUMERIC(20,2) = 12345678901234567.89; selectOne(...)->budget
out: 12345678901234568.0 (float)
in:  Project::build($row) with public string $budget
out: '1.2345678901235E+16'
in:  after registerTypeConversion('NUMERIC', fn (string $v): string => $v)
out: '12345678901234567.89'
```

**24. bytea comes back as a stream.** A `string` entity property gets the
resource name, not the bytes. Read it with `stream_get_contents()` before
hydrating; write bytes with `new Binary($bytes)`.

```text
in:  digest BYTEA = \x0001ff; selectOne(...)->digest
out: resource (stream)
in:  Blob::build($row) with public string $digest
out: 'Resource id #44'
```

**25. PostgreSQL inserts return ids through RETURNING only.**

```text
in:  $db->insertGetId('INSERT INTO project (name, budget) VALUES (?, ?)', [...])
out: DatabaseException: insertGetId() needs INSERT ... RETURNING id on PostgreSQL and SQLite: add RETURNING id to the statement.
in:  $db->lastInsertId()
out: DatabaseException: lastInsertId() is refused on PostgreSQL: use insertGetId() (insertRowGetId() in a Broker) with INSERT ... RETURNING id.
```

**26. Array parameters are refused.** Pass a PostgreSQL array literal with a
cast (`id = ANY(?::bigint[])` with `'{1,2}'`) or JSON (`json_encode()`).

```text
in:  $db->select('SELECT * FROM project WHERE id = ANY(?)', [[1, 2]])
out: InvalidArgumentException: Query parameter #1 cannot be bound: array has no SQL form. Bind a scalar, null, a DateTimeInterface, a BackedEnum, a Stringable, a Binary or a stream.
```

**27. `DatabaseException` messages withhold the SQL and the driver text.**
Branch on `sqlState()`; log `driverMessage()` and `sql()`.

```text
in:  a duplicate value on a UNIQUE column
out: getMessage() = "Query failed [SQLSTATE 23505]: statement and driver detail withheld, read sql() and driverMessage() to inspect them"
     sqlState() = "23505", driverMessage() = "SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"project_name_key\" ..."
```

**28. Catching a failed statement inside `transaction()` poisons the commit.**

```text
in:  $db->transaction(function () use ($db) { try { <duplicate insert> } catch (DatabaseException) {} return 'committed'; })
out: DatabaseException: Transaction failed at commit [SQLSTATE 25P02]: a statement inside it failed and its exception was caught, so its writes were rolled back; let the exception propagate, or catch it around a nested transaction()
```

**29. Mail errors withhold the transport text.**

```text
in:  send() to an SMTP port that refuses connections
out: MailerException, failure = send_failed, getMessage() = "The mail transport did not accept the message; the reason is withheld, read transportMessage() (it may name recipients)."
     transportMessage() = "SMTP Error: Could not connect to SMTP host. Failed to connect to server"
```

**30. The debug allowlist refuses entries that would need no cookie.** The
messages name the entry's position, never its secret.

```text
in:  withDebugClientAllowlist('@172.17.0.1')   (also DebugIntegration::initialize())
out: InvalidArgumentException: Debug client allowlist entry 1 has an empty secret before "@": with no tracy-debug cookie it would admit every client at that address. Set a secret, or list the bare address if that is intended.
in:  withDebugClientAllowlist('secret@')
out: InvalidArgumentException: Debug client allowlist entry 1 has no address after "@".
in:  withDebugClientAllowlist('10.0.0.0/8')
out: InvalidArgumentException: Debug client allowlist entry 1 is a range: Tracy matches exact addresses only, so list each address.
in:  withDebugClientAllowlist(['secret@10.0.0.5 other@10.0.0.6'])
out: InvalidArgumentException: Debug client allowlist entry 1 holds whitespace or a comma: pass each address as its own array entry.
in:  withDebugClientAllowlist('') or withDebugClientAllowlist(null)
out: accepted: Tracy shows to loopback clients only
in:  DEBUG_CLIENTS=secret@10.0.0.169, GET / from 10.0.0.169
out: no Tracy bar without the cookie or with tracy-debug=wrong; the bar with tracy-debug=secret
```

**31. A NULL column into a non-nullable property is a `TypeError`.** Declare
every nullable column as `?type` on the entity.

```text
in:  Project::build($row) with $row->tags = null and public array $tags
out: TypeError: Cannot assign null to property Project::$tags of type array
```

**32. A reused `Mailer` keeps its recipients.**

```text
in:  $m->to('ada@example.com')->subject('A')->text('x'); $m->send(); $m->to('grace@example.com')
out: getPhpMailer()->getToAddresses() lists ada@example.com and grace@example.com
```

**33. Formatter pipes in a translation use the app's `Formatter`, not the
translation's locale.** For a per-request locale, set both (see
`references/localization.md`).

```text
in:  localization.locale: en_CA; locale/fr/m.json {"total": "Total : {amount|money}"}; localize('total', ['amount' => 1234.5], 'fr')
out: "Total : $1,234.50"
in:  the same after App::setFormatter(new Formatter('fr_CA', 'CAD'))
out: "Total : 1 234,50 $" (the two spaces the formatter writes are U+00A0)
```

## Cheat sheet

| Need | Write |
| --- | --- |
| Boot | `ApplicationBuilder::create()->withConfiguration($config, basePath: $root)->withRouter($router)->...->build()` |
| Serve | `$app->handle(Request::fromGlobals(trustedProxies: $config->security->trustedProxies, trustedHeaders: $config->security->trustedHeaders))->send()` |
| Load config | `Configuration::fromYamlFile($path, ['render' => RenderConfig::class])` |
| Read config | `$config->security->maxBodySize`, `$config->database`, `$config->section('render')`, `config('render', 'directory')` |
| Global middleware | `$builder = $builder->withMiddleware($m)` (first added runs outermost) |
| Named middleware | `registerMiddleware('auth', $m)` + `#[Middleware('auth')]` |
| Security gap enforced elsewhere | `withAcknowledgedSecurityKeys(['csrf'])` |
| Views | `withRenderEngine($engine)` + `use RenderResponses;` + `$this->render('page', [...])` |
| Controller factory | `withControllerFactory(fn (string $class): object => new $class($deps))` |
| Report errors | `withEventDispatcher($events)` + `ExceptionEvent` listener |
| Custom error page | `withExceptionHandler(RouteNotFoundException::class, fn ($e, Request $r) => ...)` |
| Debug behind Docker | `withDebugClientAllowlist(env('DEBUG_CLIENTS'))` with `DEBUG_CLIENTS=secret@<gateway-ip>` + `tracy-debug=secret` cookie |
| Route | `#[Get('/x/{id}', constraints: ['id' => '\d+'], middlewares: ['auth'], name: 'x.show')]` |
| URL for a route | `route('x.show', ['id' => 1])` |
| Per-process routes | `#[RequiresEnv('MODE', 'api')]` on a base controller |
| Query / body / header | `$request->query('page', 1)`, `$request->body()->get('name')`, `$request->headers()->bearerToken()` |
| Responses | `Response::json()`, `html()`, `text()`, `noContent()`, `redirect()`, `localRedirect()` |
| CSRF field | `<input type="hidden" name="_csrf_token" value="...">` or `X-CSRF-Token` header |
| Session in tests | `new SessionManager([])` |
| Validate | `(new FormValidator([...]))->validate($data)->hasErrors()` |
| Insert with id | `insertRowGetId('INSERT ... RETURNING id', [...])` |
| Exact money | `$db->registerTypeConversion('NUMERIC', fn (string $v): string => $v)` |
| Escape outside Latte | `e($value)` |
| Own 413 page | `new MaxBodySizeMiddleware($max, onFailure: fn (Request $r, BodyTooLarge $b): ?Response => ...)` |
| 404 for a missing record | `return $this->abort(404)` or `throw new RouteNotFoundException('project')` |
| Sign-in | `$session->regenerate()` and `(new SessionCsrfTokenManager($session))->regenerate()`, then store the user id |
| Upload | `$uploader->store($request->file('avatar'), 'avatars')` (`references/uploads.md`) |
| Encrypt a column | `Cryptography::encrypt($value, Cryptography::decodeKey($config->security->encryptionKey), context: ...)` |
| CSP nonce | `ContentSecurityPolicyMiddleware($policy, nonceDirectives: ['script-src'])` + `<script nonce="{nonce()}">` |

## References

- Framework: https://github.com/ophelios-studio/zephyrus-core (`CHANGELOG.md`
  lists every breaking change since `v0.13.0` with its fix)
- Packagist: https://packagist.org/packages/zephyrus-framework/core
- Latte: https://latte.nette.org/
- Runnable example: `examples/zephyrus/` in this repo (config, bootstrap,
  CSRF-protected form, JSON route, health probe)
- In this skill: `references/uploads.md`, `references/security.md`,
  `references/localization.md`, `references/database.md` (see Other modules)
