# Agreely cookie banner example

Runnable examples for the **Agreely cookie-banner tier**: the public `/e/v1/*` edge
on `cdn.agreely.ca` plus the browser client that a customer installs with one tag.

This is **not** the Agreely consent-gate SDK. That is a different product with a
different host (`api.agreely.ca/v1`), a different credential (a secret
`agr_live_` Bearer key, not a public `agr_pub_` site key) and the opposite failure
posture. See `examples/agreely/` and `skills/agreely/SKILL.md` for that one. No SDK
and no CLI can touch the cookie tier.

Agreely helps you **document and demonstrate** a Law 25 (Loi 25 / Quebec, P-39.1)
consent practice. It does **not** certify that your organization is compliant.

## Layout

```
install-snippet.html    The canonical one-tag install, annotated. Copy and adapt.
zero-click-check.mjs    Headless audit of a live page: is the loader synchronous,
                        did Consent Mode default-deny land, what exists at rest,
                        does the injection guard bite, is the banner even serving.
edge-probe.sh           curl the public edge for one site key. Read-only. Prints
                        the honest serving state and decodes a refusal code.
verify-proof.mjs        Fetch a proof bundle and RECOMPUTE its three crypto legs
                        yourself (commitment, row hash, Merkle fold). Zero deps.
keccak256.mjs           Dependency-free Keccak-256 (Node's sha3-256 is NOT it).
keccak256.test.mjs      Vectors, cross-checked against Agreely's own PHP Keccak.
server-side-gate.php    Read `agreely_consent` before you emit any Set-Cookie.
package.json            Declares the one devDependency (playwright).
```

## Probe the edge (read-only, no install)

```bash
./edge-probe.sh agr_pub_yoursitekey https://yourdomain.com
```

Real output against a verified, published local site:

```
GET http://localhost:8096/e/v1/config/agr_pub_IaNtg2AP5VttBoQ29iaYJXMJrwl10uvS
HTTP/1.1 200 OK
access-control-allow-origin: *
cache-control: public, max-age=60, stale-while-revalidate=600

  active            : True (false = the client renders NOTHING; this is the serving gate)
  configVersion     : 2
  branding          : agreely ("agreely" = free tier, the mark is unremovable)
  non-essential     : ['functional', 'analytics', 'marketing']
  policyLinks       : 1 (art. 8.2: >= 1 is required to publish once consent is solicited)
  crosses_border    : True
  profiling         : True ['Meta']
  trackerHosts      : ['assurance-boreale.ca']

POST .../e/v1/consent   Origin: https://assurance-boreale.ca   (deliberately refused)
  status : 409   body: {"error":{"code":"stale_config"}}
  access-control-allow-origin: https://assurance-boreale.ca
```

A `409 stale_config` from your own origin is the **good** answer: it means the key
resolved, the site is verified and the origin is bound, and only the fake config
hash was refused. A `403` with **no** `Access-Control-Allow-Origin` at all is the
fail-closed refusal; read the code inside the body.

Against an unverified site, same script, same key shape:

```
  active            : False
  status : 403   body: {"error":{"code":"website_not_active"}}
  (no Access-Control-Allow-Origin: this origin is NOT bound to the key)
```

## Audit a live page for zero-click leaks

```bash
npm install                      # playwright
npx playwright install chromium
node zero-click-check.mjs https://your-site.example
```

It always drives Chromium `headless: true`, reuses one browser and one context, and
closes it including on failure.

Real output on a correct install:

```
  loader tag              http://localhost:8096/e/v1/loader.js
  site key                agr_pub_IaNtg2AP5VttBoQ29iaYJXMJrwl10uvS
  first <script src>      yes
  async / defer           no (correct)
  consent-default flag    true
  consent calls in queue  [["default",{"ad_storage":"denied",...,"security_storage":"granted"}]]
  injected GA tag becomes text/plain  (want: text/plain)
  injected non-tracker    (executable)  (want: executable)
  config active           true
```

Add `async` to that one tag and the same script reports:

```
  async / defer           YES  <-- BUG
   1. The loader carries async/defer. It then runs AFTER other tags, so the Consent
      Mode default-deny and the injection guard are installed too late and trackers
      can fire on zero click. Remove the attribute.
```

**This is the misinstall to fear.** It was measured, not assumed: a script placed
right after the loader recorded what it could see at the moment it ran.

| loader tag | next tag saw `__agreelyConsentDefault` | `gtag` defined | injected `google-analytics.com` becomes |
|---|---|---|---|
| `<script src=...>` | `true` | `true` | `text/plain` (blocked) |
| `<script async src=...>` | `false` | `false` | **an executable script (it runs)** |

Note what that table implies for auditing: by the time the page settles, the async
install *also* shows a default-deny signal and an armed guard. The leak happened in
the window before the loader ran. A snapshot taken after load will not see it, which
is precisely why the checker reads the **tag attributes** and does not only sample
state at rest.

## Verify a proof without trusting Agreely

```bash
node verify-proof.mjs cec50c1a-7def-42e9-be18-d422c1b1d0b0
node verify-proof.mjs <consentId> --base http://localhost:8096   # local edge
```

It fetches `GET /e/v1/proof/{consentId}?recompute=1` and recomputes all three
cryptographic legs with its own Keccak-256. Real captured output:

```
  status                    anchored
  recorded decision         {"analytics":true,"functional":true,"marketing":true}
  banner shown (hash)       0xc28c6feffb2ad546822b373ef9ab568e8e3810f15f7fad524a363109dd1cce38

  1. commitment = keccak256( JCS(claim) || salt )
     recomputed             0xc7bfe8b0b4f15df0e6afb0218cc1c9ede87df4fe48fa44e4e6fb115129c40ae4
     published              0xc7bfe8b0b4f15df0e6afb0218cc1c9ede87df4fe48fa44e4e6fb115129c40ae4
     verdict                OK

  2. rowHash = keccak256( prevHash || canonical(chainPayload) )
     verdict                OK

  3. Merkle fold of the leaf up the inclusion path
     leaf == rowHash        OK
     folded root            0x90c7b48d19f8855e155ee40ad8a8575aaee7ec746c396a0810f6abc6e68833b8
     epoch root             0x90c7b48d19f8855e155ee40ad8a8575aaee7ec746c396a0810f6abc6e68833b8
     verdict                OK

  4. On-chain anchor (verify this leg yourself)
     tx                     0x8bc29fa3d9cd4040b26ecc4dcfd069d0eba97aa8b53cf9616a5fe75742077f17
     explorer               https://sepolia.basescan.org/tx/0x8bc2...
```

Flipping one boolean inside `decisions` changes the recomputed commitment, so a
tamper is caught:

```
tampered commitment : 0xd489aa3d766d38f302e3f39177624aacd9864c4375f013ba45e19cf83279405b
published commitment: 0xc7bfe8b0b4f15df0e6afb0218cc1c9ede87df4fe48fa44e4e6fb115129c40ae4
detected tamper     : true
```

Two contract details the script has to honour exactly, and so will yours:

- The claim is **number-free**. `config_version` travels as the string `"1"` and
  `decisions` as a JSON *string*, because a number is the classic cross-language
  hashing footgun. The chain payload in leg 2 is the one exception: `chain_seq` is
  a real number there. Do not "tidy" either, or the hash stops matching.
- Leg 4 is not verifiable offline. The script prints the explorer URL instead of
  pretending. A coarsened row has had its salt destroyed on purpose, so leg 1 is a
  deliberate one-way orphan and the script says so rather than reporting a failure.

Exit codes: `0` anchored and all legs matched, `3` recorded but not yet anchored,
`4` unknown id, `6` a recomputation mismatched, `2` usage.

```bash
node keccak256.test.mjs     # the hash itself, against known vectors
```

## Gate your own server-set cookies

```bash
php -S localhost:8000 server-side-gate.php
```

Client JavaScript cannot read or delete an `httpOnly` cookie and cannot stop your
server sending `Set-Cookie`. The banner never claims it can. Read the first-party
`agreely_consent` cookie yourself before you emit anything, treat an absent cookie
as a refusal, and re-read it on every relevant request rather than caching the
decision in a session (the visitor can withdraw in another tab).

## Reference

- Skill: `skills/agreely-cookies/SKILL.md`
- Public docs: <https://agreely.ca/cookie-banner/>
- Edge: `https://cdn.agreely.ca` (`/e/v1/*`, `/verify`) - management app:
  `https://cookies.agreely.ca` (a different host; `/e/v1/*` 404s there)
