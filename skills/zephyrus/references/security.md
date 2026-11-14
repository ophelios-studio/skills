# Security extras

Read this when adding a Content-Security-Policy, encrypting data, hashing
passwords or generating tokens. The middleware wiring (headers, CSRF, body
size, hosts, HTTPS) is in `SKILL.md`.

## Content-Security-Policy with a nonce

```php
use Zephyrus\Security\ContentSecurityPolicy;
use Zephyrus\Security\ContentSecurityPolicyMiddleware;

$policy = ContentSecurityPolicy::create()
    ->withDirective('default-src', "'self'")
    ->withDirective('script-src', "'self'");

$builder = ApplicationBuilder::create()
    ->withConfiguration($config)
    ->withRouter($router)
    ->withMiddleware(new SecureHeadersMiddleware($config->security->headers)) // its csp left empty
    ->withMiddleware(new ContentSecurityPolicyMiddleware($policy, nonceDirectives: ['script-src']));
```

```latte
<script nonce="{nonce()}">...</script>
```

- Each request gets a fresh nonce: the header reads
  `default-src 'self'; script-src 'self' 'nonce-...'` and `nonce()` returns the
  same value for every tag of that request.
- Several nonced CSP middlewares (an enforced and a report-only policy) share that
  one nonce: the first mints it, the others reuse it.
- Mount it after `SecureHeadersMiddleware`, or leave `security.headers.csp`
  empty. With a `csp` set and the CSP middleware first, `build()` refuses:
  `ContentSecurityPolicyMiddleware is registered before SecureHeadersMiddleware,
  whose csp is set, so the policy registered first is never sent. ...`
- A nonce makes browsers ignore `'unsafe-inline'` in the same directive.
- `reportOnly: true` sends `Content-Security-Policy-Report-Only`. A route that
  sets its own CSP header keeps it, with no warning: the route chose.
- *(dev)* A `SecureHeadersMiddleware` mounted on a route, inside a nonced
  middleware, writes its `csp` first and replaces the nonce policy of that
  request (`build()` cannot see per-route stacks). In debug mode that middleware
  warns: `Content-Security-Policy: the csp of this SecureHeadersMiddleware
  replaces the nonce policy of this request, because it is registered after
  (inside) ContentSecurityPolicyMiddleware. Leave its csp empty, or, when both
  are registered at the same level (global, group or route), register it before
  ContentSecurityPolicyMiddleware.` A nonced report-only policy is never
  replaced, so it raises nothing.
- *(dev)* `nonce()` only means something under a nonced middleware. Without one
  the value is in no header, and in a persistent worker (unsupported) it would
  be the previous request's.

## Encryption

`security.encryption.key` holds a base64url key. Generate one:

```bash
php -r 'require "vendor/autoload.php"; echo Zephyrus\Security\Cryptography::encodeKey(Zephyrus\Security\Cryptography::generateEncryptionKey()), PHP_EOL;'
```

```php
use Zephyrus\Security\Cryptography;

$key = Cryptography::decodeKey($config->security->encryptionKey); // 32 raw bytes
$sealed = Cryptography::encrypt('4111 1111 1111 1111', $key, context: 'client:42|card');
$plain = Cryptography::decrypt($sealed, $key, context: 'client:42|card');

$hash = Cryptography::hashPassword('correct horse');
Cryptography::verifyPassword('correct horse', $hash); // true
```

- XChaCha20-Poly1305. The `context` is not secret and not stored, but
  `decrypt()` must receive it verbatim: a value copied to another row or
  tenant fails with `CryptographyException: Decryption failed. The ciphertext
  may be corrupted or the key is incorrect.`
- Passing the encoded key straight to `encrypt()` fails (`Key must be exactly
  32 bytes`); an all-zero key is refused *(0.14)*.
- `hashPassword()` produces argon2id; `needsRehash()` tells when to re-hash at
  the next sign-in.
- Tokens: `Cryptography::randomHex(32)` (32 characters), `randomString()`,
  `randomBytes()`, `randomInt()`.

## Guards for named middleware

`RequestAttributeGuard('role', ['admin'])` passes when a request attribute
(set by your auth middleware) holds an allowed value. Combine guards with
`AllAuthGuard`, `AnyAuthGuard` and `NotAuthGuard`; wrap the result in
`AuthGuardMiddleware`, which answers 401 `{"error":"Unauthorized"}` by default
(`new AuthGuardMiddleware($guard, 403, 'Forbidden')` to change it).
