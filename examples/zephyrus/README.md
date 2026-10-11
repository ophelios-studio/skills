# Zephyrus example

The smallest app that exercises the patterns in the `zephyrus` skill: a
strict `config.yml` that passes the boot-time security wiring check, an
app-local bootstrap, attribute routes, a Latte page with a CSRF-protected form,
a JSON route and a health probe.

## Layout

```
composer.json                       # zephyrus-framework/core dev-dev from Packagist
config.yml                          # application, session, security, localization
public/index.php                    # front controller: App\Kernel::run()
app/Kernel.php                      # loads config, mounts middleware, builds the app
app/Controllers/HomeController.php  # GET /, POST /greet, GET /api/hello/{name}, GET /health
app/Views/home.latte                # the page and its form
```

## Run

Requires PHP 8.4+ with intl, mbstring, pdo, sodium and fileinfo.

```bash
composer install
php -S localhost:8000 -t public
```

In another terminal (bash or zsh):

```bash
curl -i http://localhost:8000/health
# HTTP/1.1 200 OK ... ok

curl -i http://localhost:8000/api/hello/world
# HTTP/1.1 200 OK ... {"message":"Hello, world"}

curl -i http://localhost:8000/api/hello/World
# HTTP/1.1 404 Not Found (the route constrains {name} to [a-z]+)

curl -i -X POST -d name=Ada http://localhost:8000/greet
# HTTP/1.1 403 Forbidden ... {"error":"Invalid or missing CSRF token."}

# The form flow: keep the session cookie and send the token back.
TOKEN=$(curl -s -c jar.txt http://localhost:8000/ | sed -n 's/.*name="_csrf_token" value="\([^"]*\)".*/\1/p')
curl -i -b jar.txt -d "_csrf_token=$TOKEN&name=Ada" http://localhost:8000/greet
# HTTP/1.1 200 OK ... <h1>Hello, Ada</h1>

curl -i -b jar.txt -d "_csrf_token=$TOKEN&name=" http://localhost:8000/greet
# HTTP/1.1 422 Unprocessable Content ... This field is required.

head -c 2000000 /dev/zero | curl -i -b jar.txt -H "X-CSRF-Token: $TOKEN" --data-binary @- http://localhost:8000/greet
# HTTP/1.1 413 Content Too Large ... {"error":"Request body too large."}
```

Every response carries the headers declared under `security.headers`
(`x-frame-options: DENY`, `referrer-policy: same-origin`).

## Things to try

- Delete the `MaxBodySizeMiddleware` line in `app/Kernel.php`: the next
  request fails to boot with `security.maxBodySize is not enforced as declared`.
- Misspell `same_site` in `config.yml`: the boot names the closest key.
- `APP_DEBUG=true php -S localhost:8000 -t public` turns on the Tracy debugger
  for loopback clients.
