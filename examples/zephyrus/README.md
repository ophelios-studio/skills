# Zephyrus example

Minimal Hello-World Zephyrus app. Pared down from the production
`immunity-app` to the smallest set of files that demonstrates the
empirical patterns in the parent skill.

## Layout

```
composer.json                         # Pulls zephyrus-framework/core dev-dev
config.yml                            # application/render/localization/security sections
public/index.php                      # Front controller — boots Application
app/Controllers/HomeController.php    # #[Get('/')] returning JSON + #[Get('/health')] probe
app/Models/Core/Application.php       # Minimal Application class with discoverControllers
app/Views/home.latte                  # Stub Latte template (not exercised by JSON-only handler)
```

## Run

```bash
# Install
composer install

# Serve (matches the framework template's recommended command)
php -S localhost:8080 -t public

# Hit it
curl http://localhost:8080/
# → {"message":"Hello from Zephyrus","docs":"...","mode":"WEB"}

curl http://localhost:8080/health
# → ok
```

## What this demonstrates

- **Front controller pattern** — `public/index.php` boots
  `App\Models\Core\Application`.
- **Default `MODE=WEB`** for single-process dev. Set `MODE=API` to
  exercise multi-mode controller scoping.
- **Attribute routing** — `#[Get('/')]` on a method, no manual route
  registration.
- **Health-check pattern** — `#[Get('/health')]` with no `#[RequiresEnv]`
  registers in every mode; useful as a container probe across
  WEB / API / INDEXER deployments.
- **`Configuration::fromYamlFile`** + `!env` for environment-driven
  config.
- **Auto-discovery** via `Router::discoverControllers(namespace, dir)`.

## What's NOT here (study `immunity-app` for the rest)

- Database wiring (Db singleton, `DATABASE_URL` parser, broker base
  class with bytea sanitization)
- Broker / Service / Entity layering with raw SQL
- Named middleware registration + `#[Middleware('name')]` application
- Custom controller factory for render-engine injection
- `#[RequiresEnv('MODE', 'X')]` controller-class scoping for multiple
  entry points
- Latte template rendering with `RenderResponses` trait
- Test bootstrap that rebuilds the test DB and integration tests
  wrapped in transactions
- Localization, validation, sessions

For all of those, see `www/immunity-app` directly — it's the canonical
production reference for Zephyrus.
