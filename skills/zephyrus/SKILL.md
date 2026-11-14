---
name: zephyrus
description: Use when building or changing an app on the Zephyrus PHP 8.4+ framework (Composer package `zephyrus-framework/core`). Triggers on `Zephyrus\` imports, `ApplicationBuilder`, `Configuration::fromYamlFile`, a `config.yml` with `application:`, `session:`, `security:` or `database:` sections, attribute routes (`#[Get]`, `#[Post]`, `#[Middleware]`, `#[RequiresEnv]`), `discoverControllers`, Latte views with `RenderResponses`, `SessionMiddleware`, `CsrfMiddleware`, `MaxBodySizeMiddleware`, `FormValidator`, `Database`/`Broker`/`Entity`, `Mailer`, `Uploader`, `Cryptography`, `ContentSecurityPolicy`, and boot errors such as "is not a known section", "is an unknown key" or "Configuration declares security settings that this application does not enforce as declared". Covers the bootstrap, strict configuration, the boot-time security wiring check, sessions and CSRF, routing rules, error reporting, the PostgreSQL layer and the Broker/Service/Entity convention, with a repro for every gotcha.
---

# Zephyrus

Zephyrus is a PHP 8.4+ framework: attribute routing, immutable `Request` and
`Response`, typed YAML configuration that refuses unknown keys, security
middleware whose wiring is checked at boot, Latte views, and a thin PDO layer
for PostgreSQL. No ORM, no migrations.

Everything below was run against `v0.16.0`, the release a bare `composer
require` installs. An API marked *(0.14)* is new in `v0.14.0`: `v0.13.0`
lacks it, predates many breaking changes, and does not run the bootstrap below.
Behaviour marked *(0.14.1)* arrived in `v0.14.1`; the same passage says what
`v0.14.0` does instead. Behaviour marked *(0.15)* arrived in `v0.15.0`; the same
passage says what `v0.14.1` does instead. Behaviour marked *(0.16)* arrived in
`v0.16.0`; the same passage says what `v0.15.0` does instead when it changed
behaviour (the `router:` section and `cache:warm` are new API).
Behaviour marked *(dev)* is on the `dev` branch, unreleased since `v0.16.0`; the
same passage says what `v0.16.0` does instead.
The framework's `CHANGELOG.md` lists each change with the fix to make. The
boot-time error messages name their own fix: when one disagrees with this file,
follow the message.

## Install

```json
{
    "require": {
        "php": "^8.4",
        "zephyrus-framework/core": "^0.16"
    },
    "autoload": { "psr-4": { "App\\": "app/" } }
}
```

Packagist serves the release: no `repositories` entry. `^0.16` stays on
0.16.x, since a minor version may break the API before 1.0. The core requires
`ext-intl`, `ext-mbstring`, `ext-pdo`, `ext-sodium` and `ext-fileinfo`, and
brings `latte/latte`, `tracy/tracy`, `symfony/yaml`, `vlucas/phpdotenv` and
`phpmailer/phpmailer`. PostgreSQL needs `pdo_pgsql`.

Where each class lives (the PSR-4 root is `Zephyrus\` under `src/Zephyrus/`):

| Namespace | Classes |
| --- | --- |
| `Zephyrus\Core` | `ApplicationBuilder`, `Application`, `App`, `ExceptionEvent` *(0.14)* |
| `Zephyrus\Core\Config` | `Configuration`, `ConfigSection`, `ConfigurationException`, `DatabaseConfig`, `Environment` |
| `Zephyrus\Http` | `Request`, `Response`, `MiddlewareInterface` |
| `Zephyrus\Routing` | `Router` |
| `Zephyrus\Routing\Attribute` | `Get`, `Post`, `Put`, `Patch`, `Delete`, `Head`, `Options`, `Route`, `Root`, `Middleware`, `MiddlewareGroup`, `WithoutMiddleware` *(0.14)*, `RequiresEnv` |
| `Zephyrus\Routing\Exception` | `RouteNotFoundException`, `RouteMiddlewareException`, `RouteSignatureException` |
| `Zephyrus\Controller` | `Controller` |
| `Zephyrus\Rendering` | `LatteEngine`, `PhpEngine`, `RenderResponses`, `RenderConfig`, `Asset`, `RenderException` |
| `Zephyrus\Security` | `SecureHeadersMiddleware`, `SecureHeadersConfig`, `MaxBodySizeMiddleware` *(0.14)*, `BodyTooLarge` *(0.14)*, `CsrfMiddleware`, `CsrfConfig`, `CsrfFailure` *(0.14)*, `CsrfTokenManagerInterface`, `ForceHttpsMiddleware`, `AllowedHostsMiddleware`, `ContentSecurityPolicy`, `ContentSecurityPolicyMiddleware`, `Cryptography`, `AuthGuardMiddleware`, `HeaderTokenGuard`, `IpAllowlistGuard`, `RequestAttributeGuard`, `PredicateAuthGuard`, `AllAuthGuard`, `AnyAuthGuard`, `NotAuthGuard` |
| `Zephyrus\Session` | `SessionManager`, `SessionMiddleware`, `SessionCsrfTokenManager`, `DatabaseSessionHandler`, `Flash`, `SessionException` |
| `Zephyrus\Validation` | `FormValidator`, `Rules`, `Rule`, `ErrorBag`, `ValidationException` |
| `Zephyrus\Event` | `EventDispatcher` |
| `Zephyrus\Data` | `Database`, `Broker`, `Entity`, `JsonIgnore`, `Binary` *(0.14)*, `DatabaseException`, `PaginationRequest`, `SortRequest`, `FilterRequest`, `PaginatedResult` |
| `Zephyrus\Upload` | `Uploader`, `FileUpload`, `UploadException` |
| `Zephyrus\Localization` | `Translator`, `JsonLocaleLoader` |
| `Zephyrus\Mailer` | `Mailer`, `MailerConfig`, `MailerException`, `MailerFailure` *(0.14)* |
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

*(0.16)* The recommended way to get the router is the `router:` section of
`config.yml` (see Configuration): drop the `Router` line and `withRouter()`, and
`build()` builds the router from the section, reading the routes cache that
`vendor/bin/zephyrus cache:warm` wrote in production and staging (see
Deployment). `basePath` is required with it.

```yaml
router:
  controllers:
    App\Controllers: app/Controllers
```

```php
return ApplicationBuilder::create()
    ->withConfiguration($config, basePath: $root)   // no withRouter()
    ->withRenderEngine($views)
    // ...the same withMiddleware() calls
    ->build();
```

Without a `router:` section nothing changes: keep `discoverControllers()` and
`withRouter()` as in the example above, which runs the discovery on every
request. An app that needs the router before `build()` calls
`Router::fromConfiguration($config, basePath: $root)` and passes the result to
`withRouter()`.

What the builder does and does not do:

- Every `with*()` returns a new builder. A call whose result is dropped mounts
  nothing (gotcha 4): chain, or reassign `$builder = $builder->with...()`.
- `withMiddleware()` mounts a global middleware: it runs on every request,
  including 404 and 405 answers, outermost first. `registerMiddleware('name', $m)`
  only names one; it runs on routes that reference the name.
- `withRenderEngine()` *(0.14)* injects the engine into every controller using
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

Five built-in sections, plus `router:` *(0.16)* (below). Keys are accepted in snake_case or camelCase, one
spelling per key. A key these sections do not know stops the boot with the
closest accepted key (gotcha 1). Besides the keys below, they accept session
`cookie_path`, security `trusted_headers`, localization `date_format`,
`time_format`, `datetime_format` and `grouping_separator`, and database
`charset`, `ssl_mode`, `ssl_root_cert` and `column_cache_version`.

```yaml
application:
  environment: !env APP_ENV, production  # prod/production, stage/staging, test/testing, dev/development/local
  debug: !env APP_DEBUG, false           # forced off in production and staging

session:
  name: APP_SESSION
  lifetime: 0                      # seconds; 0 ends with the browser
  idle_timeout: 1800
  http_only: true
  secure: auto                     # true, false or auto (secure when the request is https)
  same_site: Lax                   # Strict, Lax or None (None requires secure)

security:
  force_https: false
  allowed_hosts: []                # host names, no scheme; host:port pins the port (0.14.1)
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
- Default `environment` to `production`: with `dev` as the default, a server
  where `APP_ENV` is unset runs in development and the production debug
  force-off never fires. A developer sets `APP_ENV=dev` locally
  (`APP_ENV=dev php -S 127.0.0.1:8000 -t public`).
- PHP's built-in `display_errors` default is on when no `php.ini` is loaded.
  Unless debug is on for the boot and errors may reach its output (the command
  line, or a client Tracy shows itself to, see Errors), `build()` turns
  `display_errors` and `display_startup_errors` off before any check that can
  refuse *(0.14.1)*; on `v0.14.0` it leaves them as they are. An error raised before `build()` (a
  refused `!env` or YAML value) still prints, so production needs
  `display_errors = Off`, `display_startup_errors = Off` and `log_errors = On`
  in the `php.ini` that php-fpm or Apache loads.
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

A section of your own does not refuse unknown keys: a misspelled key there
silently takes the default. `render:` and `mailer:` do refuse them *(0.15)*
(`v0.14.1` ignored them: `render: { engin: php }` kept `latte`).

A section of your own names itself by its class in a type refusal (`Configuration
section 'App\BillingConfig' field 'trialDays' has invalid value "abc": is not an
integer.`) *(dev)*, since it does not know its key. Override `protected function
sectionName(): string` to return `'billing'`: `mailer:` and `render:` already name
themselves `'mailer'` and `'render'`, where `v0.16.0` named their classes.

A YAML syntax error stops the boot with `Failed to parse configuration file
[config.yml]: invalid YAML near line 3 (the parser's detail is withheld, since it
can quote a secret from the file).` *(dev)*; `v0.16.0` appended the parser's
message, which could quote a value of the file, and chained its exception.
`getPrevious()` is null and no exception or file text is left in the trace's
arguments *(dev)*: the line is in the message. An unreadable file says
`Configuration file is not readable: config.yml` *(dev)*.

### `router:` section *(0.16)*

```yaml
router:
  controllers:                       # required: PSR-4 namespace => directory, discovered in this order
    App\Controllers: app/Controllers
  middleware_groups:                 # optional, default none: name => middleware or group names
    admin: [auth, audit]
  cache: var/zephyrus/routes.php     # optional, this is the default
```

- Relative paths resolve against the `basePath` given to
  `withConfiguration()`, never the current directory;
  the file entries of `ApplicationBuilder` (`buildFromConfigurationFile($path)`
  since 0.16, the other file entries after it) take the directory of the file
  (of the first one for several) as `basePath` (`v0.15.0` set none, so a
  relative `localization.locale_path` resolved against the current directory). `cache` must be a `.php` file under that root:
  `cache: config.yml` is refused (`the routes cache is PHP, so its file name must
  end in .php`).
- No value may carry a YAML tag, `!env` included: `cache:warm` reads the section
  without the environment (`Configuration section 'router' field 'controllers'
  holds a value under the !env tag, ...`). A directory that is missing, or holds
  no class of its namespace, is refused (unlike `discoverControllers()`,
  gotcha 15).
- Write the namespace bare or in single quotes: in double quotes,
  `"App\Controllers"` is a YAML escape error (`unknown escape character "\C"`).
- `router` is a reserved section name: a factory registered under it is
  refused. A section of your own named `routes` is refused as a typo of
  `router` (gotcha 36); `routing` passes.

### Configuration cache *(0.16)*

`vendor/bin/zephyrus cache:warm` also writes the parsed `config.yml` to
`var/zephyrus/config.yml.php`, beside the file, and `Configuration::fromYamlFile()`
reads it with no call from you. It holds the YAML before any tag is resolved, so
`!env` is still read from the environment at every load and no environment value
is written to disk; literal values are written as they are in `config.yml`, with
its mode minus every write bit. The cache is a PHP file that is included, so
never read a YAML file from a directory someone else can write. An absent cache
is silent. A cache whose
`config.yml` changed, that another framework version wrote, or that the owner
and mode checks refuse (see Deployment), is ignored and the YAML is parsed
instead, with one `error_log` line:

```text
Zephyrus: ignored the boot cache /var/www/html/var/zephyrus/config.yml.php: it is stale, its source changed after it was written. Run vendor/bin/zephyrus cache:warm to rebuild it, or vendor/bin/zephyrus cache:clear to remove it.
```

## Security wiring *(0.14)*

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

- Order: mount `SecureHeadersMiddleware` first, then `AllowedHostsMiddleware`,
  then `ForceHttpsMiddleware`. The other way round, plain HTTP with any `Host`
  gets a cacheable 308 to that host before the host is checked.
  `ForceHttpsMiddleware` redirects every plain request, so leave it and
  `force_https` out together in local development.
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
- Behind a proxy, `x-forwarded-host`, `-proto` and `-port` are read from their
  LAST entry *(0.14.1)*, the one the nearest proxy wrote; an empty last entry
  counts as no header (`X-Forwarded-Proto: "https, "` reads as `http` on a
  plain-HTTP hop). That proxy must write each header you trust on every
  request, by overwriting or appending (nginx: `proxy_set_header
  X-Forwarded-Host $host;`, `X-Forwarded-Proto $scheme`, `X-Forwarded-Port
  $server_port`). One that writes it only sometimes lets the client choose:
  Apache's `mod_proxy` appends `X-Forwarded-Host` only when the request has a
  `Host` (an HTTP/1.0 request without one keeps the client's value) and never
  writes `-Proto` or `-Port`, so add `RequestHeader unset X-Forwarded-Host
  early`, `RequestHeader set X-Forwarded-Proto expr=%{REQUEST_SCHEME}`, and in
  each vhost `RequestHeader set X-Forwarded-Port` with the port it listens on
  (`443` for TLS, `80` otherwise). Otherwise list only the headers your proxy
  writes in `trusted_headers`. `v0.14.0` reads their FIRST value, which the
  client supplies even through an appending proxy, so there the proxy must
  overwrite them. `X-Forwarded-For` is read from the right: the proxy must
  append to it (`$proxy_add_x_forwarded_for`) or overwrite it. Behind two
  appending proxies, the value read is the nearest one's *(0.14.1)*: if the outer
  proxy rewrote `Host`, that is the internal name, so `allowed_hosts` refuses
  it; let the outer proxy overwrite instead.
- *(dev)* An absolute-form request target (`GET https://example.com/x
  HTTP/1.1`) gives only its path and query, from any peer, a trusted proxy
  included. The scheme, host and port come from `HTTPS`, `Host` and
  `SERVER_PORT`, or from the trusted `Forwarded` and `X-Forwarded-*` headers, as
  for `/x`. Before this fix the target won, so a plain-HTTP request could read
  as `https`. A trusted proxy that relayed an absolute target and sent no
  `X-Forwarded-Proto` now reads as `http`: have it send `X-Forwarded-Proto`
  (or `Forwarded`).
- List `allowed_hosts` in production as the backstop. An entry without a port
  admits every port. When the app builds absolute URLs from the request (reset
  links, redirects), pin the port *(0.14.1)*: `app.example.com:443`,
  `[::1]:8080`, `*.example.com:443`. A pinned entry admits that port only, and
  a URL without a port counts as 80 for http, 443 for https. With
  `force_https`, also list the host on port 80, which then only reaches the
  redirect: `[app.example.com:443, app.example.com:80]` answers
  `http://app.example.com/` with a 308, `[app.example.com:443]` alone with a
  400. Bracket an IPv6 literal to pin it: `::1:8080` is an address, admitted on
  any port. `v0.14.0` refuses an entry with a port at boot. `*.example.com`
  matches subdomains at any depth, never `example.com` itself.
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
- Action parameters resolve in order: a `Request` type; a route placeholder of
  the same name (never replaced by a request attribute); a request attribute of
  the same name, cast to the declared type (here `$userId`, set by the `auth`
  middleware); the next unclaimed route parameter, by position; the declared
  default; otherwise `HandlerResolverException`. The query string never
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
- `route('projects.show', ['id' => 12], ['tab' => 'files'])` *(0.14)* builds
  `/api/projects/12?tab=files` (in PHP and in Latte).
- Two routes may not share a name *(0.15)*: when they sit in two classes, or are
  named with `Router::get()->name()`, `build()` throws
  `DuplicateRouteNameException` (`Duplicate route names detected: projects.show`).
  Two attribute routes of one class stop earlier, when the class is registered,
  with `RouteAttributeException`. `v0.14.1` accepted a duplicate between classes
  (`route()` picked the first) until `$router->assertNoDuplicateRouteNames()`
  was called, which threw `RouteSignatureException`.
- `#[Root]`, `#[Middleware]` and `#[RequiresEnv]` on a parent class apply to its
  subclasses. Route attributes on a parent's methods do not (gotcha 14).
- `discoverControllers()` registers every concrete class under the directory
  whose namespace matches; a wrong directory or namespace registers nothing,
  silently (gotcha 15).
- *(0.16)* `Router::fromConfiguration($config, basePath: $root)` builds the router
  of the `router:` section (`build()` calls it when you pass no `withRouter()`).
  In production and staging it reads the routes cache; in development and
  testing it always discovers and never opens the cache. A missing, stale or
  refused cache falls back to discovery, never to a failed request (gotcha 34).
  `basePath` must be absolute.
- `#[WithoutMiddleware(SessionMiddleware::class)]` *(0.14)* on a class or method
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

*(0.16)* With the routes cache, `#[RequiresEnv]` is still evaluated on every
request: the warm keeps each pair and writes no environment value, so one cache
serves every `MODE` and `APP_ENV`, and a `MODE=api` process never serves a route
of another mode. Two routes of one name that can be registered together (no
`#[RequiresEnv]` pair tells them apart) are refused, by the warm and by the
discovery in development, with `Route name "dup" is declared by App\Controllers\AController@a and by App\Controllers\BController@b, which can be registered together: rename one, or require one environment variable at a different value on each with #[RequiresEnv].`

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
`noContent()`, `respond()`, `redirect()`, `localRedirect()` *(0.14)*, `abort()`,
`abortJson()` and `validate()`, plus `before()`/`after()` hooks; an override of
`before()` must call `parent::before($request)` or `$this->request` stays
unset. Plain classes work as controllers too. `withHeader()` refuses control
characters; check untrusted values with `Response::isValidHeaderValue()` *(0.14)*, and
redirect to user-supplied targets with `localRedirect()`. Uploads:
`$request->file('avatar')` returns a `FileUpload` or `null`; store it with
`Uploader` (see `references/uploads.md`).

In 0.14.0, `Response` has no cookie API and holds one value per header name. A
`Set-Cookie` header on a `Response` replaces every cookie PHP already queued
(the emitter calls `header()`), the session cookie included: keep the value in
the session, or set it on a response that sets no other cookie.

From `v0.14.1` *(0.14.1)*, the emitter adds each `Set-Cookie`
line instead, so the session cookie and `setcookie()` calls survive, and
`withCookie()` sets several cookies. It defaults to `Path=/; Secure; HttpOnly;
SameSite=Lax`, percent-encodes the value as `setcookie()` does, replaces an
earlier cookie of the same name, domain and path (the domain compared without
case or a leading dot; the same name on another domain or path is kept as its
own line, and so is a constructor line without `Path=`, which browsers scope to
the request's directory, or one marked `Partitioned`, which browsers store
apart), and refuses what browsers would drop.
`Secure` is on by default even where `session.secure` is `auto`: pass it from the
request, as below. `hasHeader('Set-Cookie')` is true when only cookies exist,
and `withoutHeader('Set-Cookie')` also clears the cookies of `withCookie()` and
the constructor, not the session cookie or `setcookie()` calls, which PHP still
sends (`getHeader()` still reads only the header set by `withHeader()`).
`new Response(cookies: [...])` takes a list of lines, never a name-to-value map:

```php
$response = Response::noContent()
    ->withCookie('theme', 'dark', maxAge: 31_536_000) // maxAge: 0 expires it
    ->withCookie('lang', 'fr', secure: $request->uri()->isSecure()); // false on plain-HTTP development
$response->cookies; // ['theme=dark; Max-Age=31536000; Path=/; Secure; HttpOnly; SameSite=Lax',
                    //  'lang=fr; Path=/; HttpOnly; SameSite=Lax']
$response->withCookie('sid', 'x', sameSite: 'None', secure: false);
// InvalidArgumentException: Cookie "sid" cannot use SameSite=None without Secure: browsers discard it.
$response->withCookie('__Host-sid', 'x', path: '/app');
// InvalidArgumentException: Cookie "__Host-sid" uses the __Host- prefix, which requires Secure, the path "/" and no domain.
```

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
when routes are added: declare groups before `discoverControllers()`. *(0.16)* With
the `router:` section, declare them under `router.middleware_groups` instead
(the router is built before your code can call `middlewareGroup()`); a
`#[MiddlewareGroup('nope')]` that section does not declare fails the warm
(`Middleware group "nope", named by a #[MiddlewareGroup] of App\Controllers\BController@b, is not declared under router.middleware_groups: declare it there, or the route would run without its middlewares.`). `build()`
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
`new SessionManager()` and before `build()`, with an accessor the app shares, so a
request opens one `Database`:

```php
$shared = null;
$database = function () use (&$shared, $config): Database {
    return $shared ??= Database::fromConfig($config->database);
};
$session->setHandler(new DatabaseSessionHandler($database)); // the app calls $database() too
```

A handler given its own `fn (): Database => Database::fromConfig($config->database)`
opens a second connection, which `persistent: true` cannot reuse while the app's is held:
a new connection on every request (about 18 ms), without a sign. It writes to
`public.session` by default (the class docblock gives the `CREATE TABLE`):
`$session->set('user_id', 7)` stores one row with `data` `user_id|i:7;`. Behind PgBouncer transaction pooling, pass `lockSessions: false`.
Requests of one session wait for each other (PostgreSQL only, at most five
seconds). When two still change it concurrently, the first write wins *(0.15)*:
the second request's changes are dropped and a flash it consumed shows again,
with a PHP warning (`Failed to write session data`) and a `Zephyrus:` line in
the error log. On `v0.14.1` the second write overwrote the first, silently.
To lower privileges (a role or password change), call `$session->regenerate()`: it deletes the old row, so no request still holding
it can write it back, in either order, unless that request regenerates its id
too.

There is no automatic token injection: render the field in every form.
`csrfToken()` is not a core function: the bootstrap above registers it with
`addFunction('csrfToken', $tokens->getToken(...))`.

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
`Internal Server Error` (JSON when asked). The message never reaches the client.
An exception that ends in that 500 is written to the error log with its trace,
the exception of a failing handler together with the one it was answering
*(0.14.1)*; on `v0.14.0` it reaches only an `ExceptionEvent` listener (gotcha 13).
Report and customize:

```php
$events = new EventDispatcher();
$events->addListener(ExceptionEvent::class, function (ExceptionEvent $event) use ($tracker): void {
    if (!$event->isRoutingFailure()) { // skip 404 and 405
        $tracker->report($event->getException()); // the error log already has every unhandled one (0.14.1)
    }
});

$builder = $builder
    ->withEventDispatcher($events)
    ->withExceptionHandler(
        RouteNotFoundException::class,
        fn (\Throwable $e, Request $request): Response => Response::html('<h1>Page not found</h1>', 404),
    );
```

A handler may return `null` to keep the built-in answer. With debug on, an
exception no handler answers reaches Tracy *(0.14.1)*. A catch-all handler keeps it
from Tracy, so rethrow the same object in debug:

```php
$debug = $builder->isDebugEnabledForBoot();

$builder = $builder->withExceptionHandler(\Throwable::class, function (\Throwable $e, Request $request) use ($debug): ?Response {
    if ($debug) {
        throw $e; // the same object: Tracy shows it to a client it admits; anyone else gets the logged 500
    }
    error_log($e::class . ': ' . $e->getMessage());
    return Response::html('<h1>Something went wrong</h1>', 500);
});
```

Take `$debug` from `$builder->isDebugEnabledForBoot()` *(0.14)* on the fully configured
builder, never from `$config->application->debug`: in production and staging
`build()` turns Tracy off but leaves that value `true`. A rethrow Tracy does not
render, there or for a client it does not show itself to, is logged and answered
with the generic 500 instead of your page *(0.14.1)*; on `v0.14.0` it reaches PHP's
own handler, which prints the message, server paths and trace whenever
`display_errors` is on. "Fully configured" means after
`withConfiguration()` (it returns `false` before) and after
`withProductionDebugAcknowledged()` *(0.14)* if you use it (in a production-like
environment it is `true` only then).

After `build()`, `App::isDebug()` returns that same effective flag, anywhere in the
application (`false` before `build()` and after `App::reset()`). The framework's own
debug warnings (session idle timeout and strict mode, CSP) read it, so a production
boot that refused `debug: true` writes none. A bootstrap that sets the configuration
without `build()` (a CLI script, a test) calls `App::setDebug(true)` to get them. A
`SecureHeadersMiddleware` `csp` that replaces the policy of an outer
`ContentSecurityPolicyMiddleware`, static or nonced, raises an `E_USER_WARNING`
in debug. Debug output under a long-running HTTP server on the `cli` SAPI (RoadRunner,
Swoole, ReactPHP) is not supported: the `cli` SAPI is treated as the command line, so
errors reach every client.

With `debug: true` and no handler, a controller exception shows the Bluescreen
to a client Tracy renders for; any other client gets the generic 500 and the
exception goes to the error log *(0.14.1)*. On `v0.14.0`, `debug: true` alone shows
nothing: the answer is a bare 500, logged only by an `ExceptionEvent` listener.
In the command line with debug on (tests, scripts), an unhandled exception
leaves `handle()` *(0.14.1)*. `KernelBuilder::withUnhandledExceptionsRethrown()`
forces that whatever the debug flag, through
`new ApplicationBuilder(KernelBuilder::create()->withUnhandledExceptionsRethrown())`;
outside a test, the exception then reaches PHP's own handler.

A handler that throws anything else yields the fixed text 500, and both
exceptions go to the error log *(0.14.1)*; where an unhandled exception would leave
`handle()`, the handler's exception does instead. On `v0.14.0` only an
`ExceptionEvent` listener sees them.

With `application.debug: true` and no allowlist, Tracy only shows itself to a
loopback client under PHP's built-in server (`php -S`) *(0.15)*, and only when the
request carries no `X-Forwarded-For`, `Forwarded` or *(0.14.1)* `X-Real-IP`
header. Under FPM, CGI or Apache it shows to no one without an allowlist: list
`127.0.0.1` for a browser on the same machine. On `v0.14.1` loopback was admitted
under every SAPI. Behind Docker the browser arrives from the bridge gateway, so name it
with a secret and send that secret in a `tracy-debug` cookie:

```php
// DEBUG_CLIENTS holds "<secret>@172.17.0.1", <secret> from bin2hex(random_bytes(16));
// unset or empty leaves loopback only, under php -S.
$builder = $builder->withDebugClientAllowlist(env('DEBUG_CLIENTS'));
```

A declared allowlist admits only its entries, so list loopback too if you want
it *(0.14.1)*. A bare loopback entry (`127.0.0.1`, `::1`) admits no request that
carries `X-Forwarded-For`, `Forwarded` or `X-Real-IP`: behind a proxy on the same
host, list `<secret>@127.0.0.1` and send the cookie *(0.14.1)*. A proxy on the same
host that adds none of those headers (a bare nginx `proxy_pass`, socat, a TLS
tunnel) shows the Bluescreen to every client with a bare loopback entry, and
with no allowlist under `php -S`; list `<secret>@127.0.0.1` and send the cookie
there too. On `v0.14.1` the no-allowlist case held under every SAPI, and on
`v0.14.0` any allowlist also admitted loopback.

A developer the debugger does not admit gets the production 500 page. With debug
on, the error log line of that 500 says why: `Zephyrus: unhandled exception,
answered with a 500 (the debugger did not admit <addr>; add <secret>@<addr> to
withDebugClientAllowlist() and send <secret> as the tracy-debug cookie, or add <addr>
alone if it is your own machine): ...`, or, when the request carries
`X-Forwarded-For`, `Forwarded` or `X-Real-IP`, `(the debugger did not admit <addr>,
which forwarded the request; add <secret>@<addr> to withDebugClientAllowlist() and send
<secret> as the tracy-debug cookie)`, or, when `REMOTE_ADDR` is missing or not an IP
address, `(the debugger did not admit this client: REMOTE_ADDR is missing or not an IP
address)`, or, with no allowlist, `(the debugger admits no client without an allowlist,
except a direct loopback client under php -S; ...)`. `<secret>` is written literally:
never paste it. The line is unchanged when debug is off or the client
is admitted.

The secret is a credential: keep it in the environment or an uncommitted
`.env`, never in git. Each entry is an exact address or `<secret>@address`; the secret
is a random string of at least 16 characters (`bin2hex(random_bytes(16))`), and an
empty or shorter secret, an empty address or a range is refused (gotcha 30). In
`production` and `staging`, debug is forced off (gotcha 9) unless you call
`withProductionDebugAcknowledged()`.

Once `build()` enables Tracy, `dump()` and the debug bar mask the exact names in
`DebugIntegration::SENSITIVE_KEYS`, the session cookie name, and the secrets of config
sections. The Bluescreen masks them too, plus every value except null, bool, int and float
under a name matching `SENSITIVE_KEY_PATTERN`, which `isSensitiveKey()` also tests. To hide
any other name, add it to `Debugger::$keysToHide` (dump and debug bar) and to
`Debugger::getBlueScreen()->keysToHide` (Bluescreen). The returning form
`Debugger::dump($value, true)` masks no name; only the exporters apply there. For the same
masking without enabling Tracy (a test, a controlled `dump()`), call
`DebugIntegration::installSecretMasking($sessionName)` *(0.17)*, then
`Dumper::toText($value, [Dumper::KEYS_TO_HIDE => Debugger::$keysToHide])`. Register your
own exporter for a config section, closure, array iterator, array object or PHPMailer
after that call, or it is replaced.

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
JSON/JSONB decoded. Set `column_cache_version` to something that changes with every
migration, or a column that changes type under the same name and width keeps its old
decoding: for up to an hour under PHP-FPM with APCu, and in a long-running process (CLI,
RoadRunner) until it restarts or calls `Database::flushSharedColumnMetadata()`. Under
PHP-FPM without APCu it lasts only the current request.
Only a statement starting with `SELECT`, `WITH`, `VALUES`, `TABLE`, `INSERT`, `UPDATE`,
`DELETE`, `MERGE` or a parenthesis is served from the column type cache *(0.15)*; any
other (`FETCH`, `EXECUTE`, `SHOW`, `CALL`) has its columns described on every call, one to two
round trips per column depending on the types, so fetch cursors in large batches. On `v0.14.1`
a second cursor of the same width inherited the first one's converters. Queries of a `Database`
built by `fromConfig()` go out as unnamed statements, bound by the server *(0.15)* (on
`v0.14.1` each was a named statement, prepared and then closed (a `DEALLOCATE`, or a
protocol close under libpq 17)); a `new Database($pdo)` keeps named statements. See
`references/database.md`.

Persistent connections *(dev)*: `persistent: true` in the `database` section (a
boolean, `false` by default; `persistent: !env DB_PERSISTENT, false` works) keeps one
connection per PHP process and reuses it on the next `fromConfig()`: about 0.3 ms instead
of 18 ms for a new connection, measured locally with SCRAM. Each time `fromConfig()`
takes it from the pool, it sends `ROLLBACK` when a transaction is open, then
`DISCARD ALL`, drops the notifications the client already received, and sets the PDO
attributes back (exceptions, natural case and nulls, no stringified fetches, objects),
all before it returns. Settings (`set_config(..., false)`, `SET`, `search_path`,
`SET ROLE`), session advisory locks, temporary tables, `LISTEN` and the notifications it
brought, prepared statements, held cursors and `currval()` never reach the next request;
the app resets nothing itself. One trace stays:
a custom variable the previous holder set reads `''`, not `NULL`, so a row level
security policy reads `nullif(current_setting('app.actor', true), '')`.

- While a `Database` from a persistent `fromConfig()` is alive, another `fromConfig()`
  with the same connection settings and user opens a non-persistent connection (another
  backend, the full connection cost), so a live session is never reset under its
  holder: share one `Database` per request, the session handler's included (see the
  accessor under Sessions).
- The `$pdoFactory` sets no session state: with `persistent: true` it runs before the
  reset, which erases it. A `SET ROLE` in the factory is lost without a sign
  (`current_user` is the login role again), while it holds with `persistent: false`.
  Set session state after `fromConfig()` returns.
- A session advisory lock left held stays held until the next `fromConfig()` of the same
  process, not until the request ends; meanwhile another process asking for it waits (the
  session handler gives up after five seconds). Release a session lock in a `finally`.
- Never call `Pdo\Pgsql::setNoticeCallback()` on a persistent connection: the callback
  outlives the request, and on PHP 8.4.27 the next request's notice crashed the process.
- Do not fork once a persistent `fromConfig()` has run in the process: the child inherits the
  parent's socket and closes the parent's connection when it exits. With the default factory
  the child's own `fromConfig()` opens a connection of its own (the pool is keyed by process
  id); with a factory that still forces `PDO::ATTR_PERSISTENT => true` it draws from PDO's
  default pool, so parent and child share one backend and the parent's reset erases the
  child's session state.
- On PHP 8.5 an app factory used with `persistent: true` must build with `PDO::connect()` (or
  `new \Pdo\Pgsql(...)`): the reset reads notifications through `Pdo\Pgsql::getNotify()`, and a
  plain `new PDO(...)` makes every acquisition emit a deprecation (`PDO::pgsqlGetNotify()`).
  The default factory already does.
- A backend the server ended shows up at the reset: `fromConfig()` drops it and retries
  once on a new connection. A second failure throws a `DatabaseException`,
  `Persistent connection reset failed after one retry [SQLSTATE ...]: driver detail
  withheld, read driverMessage() to inspect it`, instead of returning a `Database`.
- Each process holds its connection as long as it lives: `max_connections` must cover
  the Apache or PHP-FPM processes of every machine, plus CLI processes and other
  clients. The CLI honours the setting as is.
- A pooler in transaction mode (PgBouncer `pool_mode = transaction`) cannot carry an
  actor set for the session with `set_config(..., false)`, persistent or not: the next
  transaction may run on another server session.
- Persistence goes through this setting only: a `$pdoFactory` that forces
  `PDO::ATTR_PERSISTENT`, and `new Database($pdo)` with a persistent PDO, throw a
  `DatabaseException` (gotcha 39). On `v0.16.0` both were accepted and the next request
  inherited the session as it was.

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
with `from()`, ISO date strings (`YYYY-MM-DD`, optional time, fraction and
offset, as PostgreSQL renders them under the default ISO `DateStyle`; relative
formats, `infinity`, BC dates, years over 4 digits and named time zones are
refused) into `DateTimeImmutable`/`DateTime` and nested `Entity` properties
(from a JSON object column), throws on a value it cannot hydrate *(0.15)*, and
`json_encode()` skips unassigned properties and those marked `#[JsonIgnore]`.
On `v0.14.1` a date property stayed unset and most values it could not hydrate
were skipped without an error. A nullable column needs a nullable property
(gotcha 31). Pagination, sorting
and filtering from request input: `references/database.md`. Keep money exact
before it reaches an entity (gotcha 23):

```php
$db->registerTypeConversion('NUMERIC', static fn (string $value): string => $value); // keep money exact
```

## Mail

Register `'mailer' => MailerConfig::class` (keys `smtp.host`, `smtp.port`,
`smtp.username`, `smtp.password`, `smtp.encryption`, `from.address`,
`from.name`), the complete list the section accepts *(0.15)*: any other key
stops the boot (`mailer: { smtp: { prot: 25 } }` gives `Configuration section
'mailer' field 'smtp.prot' is an unknown key: did you mean "smtp.port"?`),
where `v0.14.1` kept the default. A dotted key written flat (`mailer: {
smtp.host: h }`) gets `... field 'smtp.host' is written flat: nest it under
'smtp', such as smtp: { host: ... }.` *(dev)* (`smtp.prot` gets `smtp: { port:
... }`, the closest key). `smtp.port` is read as an
integer, so `toArray()` returns `1025`, not `'1025'`, for `!env MAIL_PORT, 1025`;
a null port (an empty `!env`) exports `587` *(dev)*, where `v0.16.0` exported null.
`smtp.encryption` takes `tls` (the default), `ssl`, or `''` for none;
`encryption: none` stops the boot with `Configuration section 'mailer' field
'smtp.encryption' has invalid value "none": must be one of 'tls', 'ssl' or ''
(empty, meaning no encryption at all); ...`.

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
*(0.14)* complete the builder.

## Testing

The application handles a `Request` in-process, so tests need no server. The
test below runs on `examples/zephyrus` plus one controller that signs in
(`App\Controllers\AccountController`, the sign-in step of the sessions
section). Install PHPUnit with `composer require --dev phpunit/phpunit`, add
`"Tests\\": "tests/"` under `autoload-dev.psr-4` in `composer.json`, then run
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
    private Application $app;

    protected function setUp(): void
    {
        $root = dirname(__DIR__);
        $this->app = Kernel::application($root, Configuration::fromYamlFile($root . '/config.yml'));
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        App::reset();
    }

    public function testAJsonRouteAnswersWithItsPayload(): void
    {
        $response = $this->app->handle(Request::fromArray('GET', '/api/hello/ada'));

        self::assertSame(200, $response->status);
        self::assertSame(['message' => 'Hello, ada'], json_decode($response->body, true));
    }

    public function testAPostWithoutACsrfTokenIsRefused(): void
    {
        $response = $this->app->handle(Request::fromArray('POST', '/greet', body: ['name' => 'Ada']));

        self::assertSame(403, $response->status);
    }

    public function testAPostCarryingTheTokenFromAPreviousGetIsAccepted(): void
    {
        $page = $this->app->handle(Request::fromArray('GET', '/'));
        preg_match('/name="_csrf_token" value="([0-9a-f]+)"/', $page->body, $found);

        $response = $this->app->handle(Request::fromArray('POST', '/greet', body: ['name' => 'Ada', '_csrf_token' => $found[1]]));

        self::assertSame(200, $response->status);
    }

    public function testTheSessionCarriesAcrossTwoRequests(): void
    {
        $page = $this->app->handle(Request::fromArray('GET', '/'));
        preg_match('/name="_csrf_token" value="([0-9a-f]+)"/', $page->body, $found);

        $signedIn = $this->app->handle(Request::fromArray('POST', '/sign-in', body: ['_csrf_token' => $found[1]]));
        $me = $this->app->handle(Request::fromArray('GET', '/me'));

        self::assertSame(302, $signedIn->status);
        self::assertSame(['user' => 42], json_decode($me->body, true));
    }

    public function testAnAnonymousRequestSeesNoUser(): void
    {
        $me = $this->app->handle(Request::fromArray('GET', '/me'));

        self::assertSame(['user' => null], json_decode($me->body, true));
    }
}
```

Build the application in `setUp()`, once per test. `App::reset()` clears the
statics `App` holds (configuration, session, formatter, asset, URL generator,
translator, nonce), and only `build()` installs the URL generator again: an
application built once per class makes `route()` throw `LogicException: route()
needs the application routes` from the second test on. `$_SESSION = []` is
a separate step: in-process there is no cookie to carry (`handle()` never
returns the session cookie), the PHP session lives for the whole process, and `reset()` does
not touch it, so without that line a test signed in earlier leaks into the next
one. The same process-wide session is why a GET followed by a POST shares one
CSRF token.

`Request::fromArray()` takes the decoded body in `body:`; it does not decode a
JSON `rawBody:` (gotcha 21).

With `application.debug: true`, an exception nothing answers leaves `handle()`
in a test, so PHPUnit reports it whole *(0.14.1)*; with debug off the test gets the
logged 500 (see Errors).

## Deployment *(0.16)*

Warm the boot caches once, when the image is built:

```dockerfile
COPY . /var/www/html
RUN composer install --no-dev --optimize-autoloader && composer dump-autoload --optimize
RUN php vendor/bin/zephyrus cache:warm
USER www-data
```

- Run it after `COPY` and `composer dump-autoload` (the controllers must be
  autoloadable), before any `USER www-data`, and on a path no later `chown`
  touches. The caches are PHP files the application includes, protected by
  their owner and file modes: the owner must be root or the owner of
  `config.yml`, and neither the file nor any directory between it and the
  application root (the root itself excluded) may be writable by group or
  others. A cache that fails that check is not
  included (an `error_log` line, then the YAML and the discovery serve).
- They live in `var/zephyrus/`, outside `cache/` on purpose: an application that
  gives `cache/` to `www-data` lets it rename a root-owned directory inside it
  and plant its own file. The warm writes `var/zephyrus/.gitignore` (`*`) unless
  one is there; add `var/zephyrus/` to `.dockerignore`, so each image warms its
  own.
- Run it as root or as the owner of `config.yml`; any other user is refused,
  because the application would ignore what it writes: `this process is neither
  root nor the owner of <root>/config.yml, so the application would ignore the
  cache: run vendor/bin/zephyrus cache:warm as root (before USER in a
  Dockerfile) or as that owner.`
- `cache:warm` writes `var/zephyrus/config.yml.php` and, when `config.yml`
  declares `router:`, `var/zephyrus/routes.php`. It reads no `.env` and
  resolves no `!env`, so it succeeds with an empty environment. It prints one
  `Wrote <path>` line per file (the routes line ends with `(N routes)`), and
  `No router: section in <file>, so no routes cache was written` when there is
  none. Exit codes: 0 written, 1 refused (message on stderr), 2 usage error.
  Before anything is written it checks every configuration and its `router:`
  section, the process owner, the directory guards (mode, owner, link), the
  regular-file check, and that a file already at a cache path carries the warm
  header, so a refusal in a later `--config` leaves the earlier caches unwritten.
  A file at a cache path that the warm did not write is never replaced or
  deleted: `Cannot write the boot cache <path>: the file there was not written
  by vendor/bin/zephyrus cache:warm, so it is left in place.`
- Two caches at one path are refused before anything is written, as when
  `router.cache` is `var/zephyrus/config.yml.php`: `Two caches would be written
  to <path>: give router.cache another path.` (exit 1). So is a cache under
  or above another, or under the `.gitignore` written beside the configuration
  cache (`router.cache: var/zephyrus/config.yml.php/routes.php`): `The cache
  <inner> would be written under the cache <outer>: give router.cache another
  path.` The same `--config` path given twice, in any spelling, is processed
  once.
- `vendor/bin/zephyrus cache:clear` deletes both files and exits 0 when they
  are already gone: run it on a local staging that was warmed. It checks every
  file for the warm header before it deletes the first, so a foreign file in a
  later `--config` keeps the earlier caches.
- The root is the real path of the directory of the file given, so a
  `config.yml` that is a symbolic link is cached under the directory of the
  link (`var/zephyrus/config.yml.php` and `routes.php` beside the link), where
  the application reads them, as `fromConfigurationFile()` takes the directory
  of the link for its `basePath`. `router:` is cached only from a `config.yml`
  at that root: from a file in a sub-directory (`--config=config/config.yml`)
  the relative paths resolve against that sub-directory, and a refusal such as
  `... is not a directory.` ends with `Note that router: is cached only from
  config.yml at the application root, and its paths were resolved against
  <root>/config.` Every file entry of `ApplicationBuilder` (`withConfigurationFile()`,
  `withConfigurationFiles()`, `fromConfigurationFiles()`, the optional and
  `buildFrom...` variants) takes the real path of the directory of the file as
  `basePath`, the first file that exists when there are several (each path
  trimmed as the loader does); when none exists it stays unset.
- `--config=PATH` (repeatable) warms another file; a relative path is read from
  the current directory, and the default is the `config.yml` at the project
  root, from any directory. The routes cache is checked against
  `<basePath>/config.yml` (gotcha 35).
- Without the warm, production and staging still work: the YAML is parsed and
  the controllers discovered on every request, with one `error_log` line per
  request: `Zephyrus: no routes cache at /var/www/html/var/zephyrus/routes.php, so the controllers were discovered for this request. Run vendor/bin/zephyrus cache:warm to write it.`
  The warm saved about 7 ms (routes) and 2 ms (configuration) per request on an
  app of 68 controllers.

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

`FileSystem`, `Exceptions` and `Http\SseEmitter` are framework internals or
niche helpers that most apps never call. *(0.16)* `RouteCache` is `@internal`:
use the `router:` section and `vendor/bin/zephyrus` (see Deployment). `v0.15.0`
had a public `RouteCache` (`new RouteCache($file)`, `isFresh()`, `warmIfStale()`...): it
is gone, along with `RouteCacheException::refused()`.

## Gotchas, each with its repro

Every repro below shows the input, then the observed result on `v0.14.0`. The
PostgreSQL repros (23 to 28) were last run on `0f65185`; the database code has
not changed since. Repros marked *(upgrading from 0.13)* only concern an app
written for `v0.13.0`; a new app can skip them.

**1. A misspelled section or key stops the boot.** Fix the spelling; keep one
spelling per key. Only a name close to a built-in one is caught: an unrelated
top-level key (`foo:`) passes silently (gotcha 2). `render:` and `mailer:`
refuse an unknown key *(0.15)*; on `v0.14.1` they ignored it.

```text
in:  sesion:
       name: APP
out: ConfigurationException: Configuration key 'sesion' is not a known section: did you mean "session"? A section of your own needs a factory registered under its name in the $sectionFactories argument of the Configuration factories.
in:  session:
       samesite: Lax
out: ConfigurationException: Configuration section 'session' field 'samesite' is an unknown key: did you mean "sameSite"?
in:  render: { engin: php }, with ['render' => RenderConfig::class] (0.15)
out: ConfigurationException: Configuration section 'render' field 'engin' is an unknown key: did you mean "engine"?
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
in:  allowed_hosts: [localhost:8080]  (v0.14.0)
out: ConfigurationException: Configuration section 'security' field 'allowedHosts[0]' has invalid value "localhost:8080": ports are not matched: list "localhost" only.
in:  allowed_hosts: [localhost:8080]  (0.14.1)
out: accepted: http://localhost:8080/ passes, http://localhost/ gets a 400
in:  allowed_hosts: [localhost:65536]  (0.14.1)
out: ConfigurationException: Configuration section 'security' field 'allowedHosts[0]' has invalid value "localhost:65536": the port must be a number from 1 to 65535 without a leading zero.
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
out: error_log: Zephyrus: application.debug is true while application.environment is production-like. Debug output has been FORCED OFF for this boot: ... If this is deliberate, call ApplicationBuilder::withProductionDebugAcknowledged(); even then, only clients named by withDebugClientAllowlist() (or loopback, without one) can see it.   (dev; v0.14.0 ends "(or loopback) can see it.")
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
process environment (container env, php-fpm `env[NAME]`) or `.env`. The
message of a refused name gives the tag's key and the reason, never the name,
and the loader's trace arguments are `SensitiveParameterValue` *(dev)*.

```text
in:  $_SERVER['APP_ENV'] = 'dev' only; environment: !env APP_ENV, prod
out: environment = Production
in:  environment: !env HTTP_APP_ENV, dev
out: ConfigurationException: !env tag at "application.environment": the variable name is refused: names starting with HTTP_ can be set per request by the client request headers, so they are never read as configuration; rename the variable.
```

**12. Built-in sections are not read through `section()`.**

```text
in:  $config->section('database')   (also config('Database'))
out: InvalidArgumentException: Section "database" is a built-in typed property; use $configuration->database or config('database') instead.
in:  Configuration::fromYamlFile($path, ['database' => RenderConfig::class])   (upgrading from 0.13)
out: InvalidArgumentException: Section factory "database" collides with the built-in section "database"; read it with $configuration->database instead.
```

**13. The reason for a 500 never reaches the client.** Each of these answers a
bare 500 `Internal Server Error`; the reason shows in the error log *(0.14.1)* and
in an `ExceptionEvent` listener. On `v0.14.0` only the listener sees it, so
without an event dispatcher it reaches nobody. With debug on, Tracy shows it to
a client it admits instead, and in the command line it leaves `handle()` (see
Errors).

```text
in:  a RenderResponses controller with no engine mounted, GET /view
out: 500 "Internal Server Error"; error_log: Zephyrus: unhandled exception, answered with a 500: Zephyrus\Rendering\RenderException: No render engine has been configured. Call withRenderEngine() on the builder, or setRenderEngine() from your own controller factory. in ...
in:  throw new RuntimeException('database password leaked in this message'), Accept: application/json
out: 500 {"error":{"status":500,"message":"Internal Server Error"}} (the message stays server-side)
in:  withExceptionHandler(RuntimeException::class, fn () => throw new LogicException('the handler broke')), a controller throws RuntimeException('connection refused')
out: 500 "Internal Server Error"; error_log: Zephyrus: exception handler failed, answered with a 500: LogicException: the handler broke in ..., then Zephyrus: exception the failed handler was answering: RuntimeException: connection refused in ...   (dev; v0.14.0 logs nothing)
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

*(0.16)* The `router:` section refuses both: `<root>/app/Nope, the directory of App\Controllers, is not a directory`,
or `<root>/app/Empty holds no class of the namespace App\Controllers`.
`discoverControllers()` is unchanged.

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
in:  the same, plus X-Forwarded-Host "evil.example, app.example.com" (appended by the proxy)
out: host = app.example.com (0.14.1); evil.example on v0.14.0
in:  the same, plus X-Forwarded-Host "evil.example, " and Host app.example.com
out: host = app.example.com, the Host header (0.14.1); evil.example on v0.14.0
```

**21. A malformed JSON body reads as empty.** Check `isMalformed()` *(0.14)* before
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
Branch on `sqlState()`; log `driverMessage()` and `sql()` (all three *(0.14)*).

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
messages name the entry's position, never its secret. `$secret` stands for 16 or more random characters,
e.g. `bin2hex(random_bytes(16))`.

```text
in:  withDebugClientAllowlist('@172.17.0.1')   (also DebugIntegration::initialize())
out: InvalidArgumentException: Debug client allowlist entry 1 has an empty secret before "@": with no tracy-debug cookie it would admit every client at that address. Set a secret, or list the bare address if that is intended.
in:  withDebugClientAllowlist('short@10.0.0.5')
out: InvalidArgumentException: Debug client allowlist entry 1 has a secret shorter than 16 characters before "@": the tracy-debug cookie is all that guards the debugger at that address. Use a longer random secret.
in:  withDebugClientAllowlist($secret . '@')
out: InvalidArgumentException: Debug client allowlist entry 1 has no address after "@".
in:  withDebugClientAllowlist('10.0.0.0/8')
out: InvalidArgumentException: Debug client allowlist entry 1 is a range: Tracy matches exact addresses only, so list each address.
in:  withDebugClientAllowlist(["$secret@10.0.0.5 $secret@10.0.0.6"])
out: InvalidArgumentException: Debug client allowlist entry 1 holds whitespace or a comma: pass each address as its own array entry.
in:  withDebugClientAllowlist('') or withDebugClientAllowlist(null)
out: accepted: Tracy shows to loopback clients only, under php -S (0.15); on v0.14.1 under every SAPI
in:  no allowlist, GET / from 127.0.0.1 with no forwarded header, under FPM, CGI or Apache
out: no Tracy bar: list 127.0.0.1 (0.15)   (v0.14.1: the bar)
in:  DEBUG_CLIENTS=<secret>@10.0.0.169, GET / from 10.0.0.169
out: no Tracy bar without the cookie or with tracy-debug=wrong; the bar with tracy-debug=<secret>
in:  DEBUG_CLIENTS=<secret>@10.0.0.169, GET / from 127.0.0.1 with no forwarded header
out: no Tracy bar: loopback is not on the list (0.14.1)   (v0.14.0: the bar)
in:  DEBUG_CLIENTS=127.0.0.1, GET / from 127.0.0.1 with X-Forwarded-For or X-Real-IP (a proxy on the same host)
out: no Tracy bar: a bare loopback entry admits no forwarded request; list <secret>@127.0.0.1 and send tracy-debug=<secret> (0.14.1)   (v0.14.0: the bar)
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

**34. *(0.16)* A stale routes cache is ignored, but only to the second.** The
cache is checked against the mtime and size of every file under the controller
directories, the files of the parent classes, traits and interfaces of each
controller, and the content of `config.yml`. A change it sees falls back to
discovery with one log line. One it cannot see is served stale: a same-size
edit that keeps the mtime (`cp -p`, `rsync -t`) or lands in the same second as
the warm, and a constant of another class read by an attribute.

```text
in:  cache:warm, then add `// x` to a controller, boot in production
out: error_log: Zephyrus: ignored the boot cache <root>/var/zephyrus/routes.php: it is stale, its source changed after it was written. Run vendor/bin/zephyrus cache:warm to rebuild it, or vendor/bin/zephyrus cache:clear to remove it.   (the discovery serves)
in:  cache:warm, then rename '/health' to '/hxalth' (same size) and restore the file's mtime with touch -r
out: GET /health => 200, no log line (the cache serves the old route)
in:  cache:warm, then add a comment to config.yml
out: both caches are ignored, one log line each
```

Warm after every deploy, as the last build step, and never rewrite files after it.

**35. *(0.16)* The routes cache is checked against `<basePath>/config.yml`.**
`Configuration` does not know its source file, so `router:` in another file is
refused by the warm, and an app with no `config.yml` at its root discovers on
every request with a log line.

```text
in:  cache:warm --config=tenant.yml, tenant.yml declaring router:
out: cache:warm failed for <root>/tenant.yml: Configuration file tenant.yml declares router:, but router: is cached only from config.yml at the application root: move the router: section to that file.
in:  production, basePath holding no config.yml
out: error_log: Zephyrus: the controllers were discovered for this request: router: is cached only from config.yml at the application root, and <root>/config.yml does not exist.
```

**36. *(0.16)* A custom `routes:` section is refused as a typo of `router`.** Name
it `routing` or anything else further from `router`. `router:` itself is
reserved: a factory registered under it throws. `v0.15.0` ignored an
unregistered `router:` or `routes:` key.

```text
in:  routes: { controllers: { App\Controllers: app/Controllers } }
out: ConfigurationException: Configuration key 'routes' is not a known section: did you mean "router"? A section of your own needs a factory registered under its name in the $sectionFactories argument of the Configuration factories.
in:  Configuration::fromYamlFile($path, ['router' => MyRouterConfig::class])
out: InvalidArgumentException: Section factory "router" collides with the built-in section "router"; read it with $configuration->router instead.
```

**37. *(0.16)* Never build a route from a secret.** The cache writes the routes,
their paths and their `#[RequiresEnv]` pairs in clear into
`var/zephyrus/routes.php`. An attribute argument that reads a constant defined
from the environment (a path prefix, a variable value) is written as it was at
the warm, in the image. `config.yml` literals go into `config.yml.php` the same
way; `!env` never does.

**38. *(0.16)* The warm refuses a directory writable by group or others.** A
`umask 002` build, or a `var/` made `775`, stops it.

```text
in:  chmod 775 var; cache:warm
out: cache:warm failed for <root>/config.yml: Cannot write the boot cache <root>/var/zephyrus/config.yml.php: <root>/var is writable by its group or by others: run chmod g-w,o-w <root>/var.
```

**39. A persistent PDO built outside `persistent: true` is refused *(dev)*.**
Remove `PDO::ATTR_PERSISTENT` from the factory or the PDO and set `persistent: true`
in the `database` section, in the same change. A factory that still forces it beside
`persistent: true` is accepted, but draws from PDO's default pool rather than this
process's `zephyrus:<pid>` one (so a forked child shares the parent's session), and is
refused as soon as a second `fromConfig()` runs while the first `Database` is held (tests,
scripts), since that one asks for a non-persistent connection. Remove the option in the
same change. On `v0.16.0` both were accepted, without any reset.

```text
in:  Database::fromConfig($config->database, fn ($dsn, $user, $password, $options) => new PDO($dsn, $user, $password, [PDO::ATTR_PERSISTENT => true] + $options))
out: DatabaseException: The PDO factory given to Database::fromConfig() returned a persistent connection it was not asked for, whose session would carry over from an earlier request: remove PDO::ATTR_PERSISTENT from the factory and set database.persistent: true in the configuration to reuse connections.
in:  new Database(new PDO($dsn, $user, $password, [PDO::ATTR_PERSISTENT => true]))
out: DatabaseException: Database refuses a persistent PDO, whose session would carry over from an earlier request: remove PDO::ATTR_PERSISTENT from the PDO and set database.persistent: true in the configuration, so Database::fromConfig() resets the session each time it reuses the connection.
```

## Cheat sheet

| Need | Write |
| --- | --- |
| Boot | `ApplicationBuilder::create()->withConfiguration($config, basePath: $root)->withRouter($router)->...->build()` |
| Boot with the routes cache *(0.16)* | `router: { controllers: { App\Controllers: app/Controllers } }` in `config.yml`, then the same chain without `withRouter()` |
| Warm / clear the caches *(0.16)* | `RUN php vendor/bin/zephyrus cache:warm` in the image build, before `USER www-data`; `vendor/bin/zephyrus cache:clear` |
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
| Debug behind Docker | `withDebugClientAllowlist(env('DEBUG_CLIENTS'))` *(0.14)* with `DEBUG_CLIENTS=<secret>@<gateway-ip>` (`<secret>` from `bin2hex(random_bytes(16))`, 16 characters or more) + the same `tracy-debug` cookie |
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
| Reuse connections | `persistent: true` in `database:` *(dev)*; RLS reads `nullif(current_setting('app.actor', true), '')` |
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
