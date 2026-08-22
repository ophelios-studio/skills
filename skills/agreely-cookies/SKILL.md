---
name: agreely-cookies
description: Use when installing, operating, debugging or auditing the Agreely COOKIE BANNER (the Quebec Law 25 / Loi 25 cookie-consent product) on a customer website. This is the PUBLIC browser tier on `cdn.agreely.ca` under `/e/v1/*`, driven by a PUBLIC `agr_pub_` site key pasted into a `<script>` tag; it is NOT the Agreely consent-gate SDK/CLI (secret `agr_live_` Bearer key, `api.agreely.ca/v1`, skill `agreely`) and no SDK or CLI can touch it. Triggers on `cdn.agreely.ca`, `/e/v1/loader.js`, `/e/v1/banner.js`, `data-agreely="agr_pub_..."`, `data-agreely-category`, `data-agreely-src`, `data-agreely-open`, `data-agreely-widget`, `data-nonce`, `window.agreelyCookies` (`open`/`acceptAll`/`refuseAll`/`withdraw`), the `agreely_consent` first-party cookie, `GET /e/v1/config/{siteKey}`, `POST /e/v1/consent`, `GET /e/v1/proof/{consentId}`, `GET /e/v1/logo/{siteKey}`, `GET /e/v1/public-scan`, the public `GET /verify` page, the refusal codes `origin_mismatch`/`website_not_active`/`unknown_site`/`stale_config`/`decisions_mismatch`/`rate_limited`/`invalid_request`, the `active` serving gate, the serving states (`unverified`/`unpublished`/`published_empty`/`behind`/`displaying`/`key_revoked`/`suspended`/`no_categories`/`none_needed`/`not_entitled`), banner publishing and its art. 3.1 review + art. 8.2 policy-link gates, the cookie inventory and its four categories (`strictly_necessary`/`functional`/`analytics`/`marketing`), zero-click enforcement, Google Consent Mode v2 default-deny, CSP without `unsafe-inline`, SRI, site-key rotation, snippet (never DNS) domain verification, staging `extra_origins`, per-site billing and the never-dark-for-billing rule, epoch/Merkle/Base anchoring and retention coarsening, or any task framed as "add a Quebec cookie banner", "why does the banner not show", "cookies still set after refuse", "my consent POST returns 403". Covers the one non-negotiable install rule (the loader must NOT be `async`, measured), the fail-CLOSED consent ingest versus the fail-OPEN public config, and the honest framing: Agreely DOCUMENTS and DEMONSTRATES a consent practice, it does not certify compliance.
---

# Agreely cookie banner (the `/e/v1` edge + the browser client)

Agreely's cookie banner is a **one-tag** consent banner for Quebec **Law 25**
sites. A visitor's per-purpose decision is recorded server-side, hash-chained per
website, folded into a Merkle epoch and anchored on Base, so any decision can be
shown later to have been recorded as claimed and not modified since.

**Loud framing, up front.** Agreely helps an organization **document and
demonstrate** its consent practice. It does **not** certify that the organization
is compliant, it is **not** legal advice, and an anchored proof is **not** a
certificate of compliance. The exact honest sentence the product itself ships is
*"cette décision a été enregistrée et n'a pas été modifiée"* ("this decision was
recorded and has not been modified"), never *"preuve de conformité"*.

Article numbers below are **P-39.1** (the Quebec private-sector act). *Loi 25*
(L.Q. 2021, c. 25) is the amending statute, not a code you cite articles of.
Public bodies are governed by **A-2.1**, where the numbering differs; see the twin
table in `skills/agreely/SKILL.md`. Health information (R-22.1, "Loi 5") is a
**separate product and out of scope here**; do not cite it.

## Which Agreely am I looking at? (read this first)

Two Agreely products share a company, a login and a proof spine, and are otherwise
opposites. Confusing them is the single most expensive mistake available here.

| | **Cookie banner** (this skill) | **Consent gate** (skill `agreely`) |
|---|---|---|
| Host | `https://cdn.agreely.ca` | `https://api.agreely.ca` |
| Namespace | `/e/v1/*` (+ `/verify`) | `/v1/*` |
| Credential | **PUBLIC** `agr_pub_` site key, in the page HTML | **SECRET** `agr_live_` Bearer key, server only |
| CORS | `Access-Control-Allow-Origin: *` on the config | none; server-to-server |
| Caller | the visitor's **browser** | your **backend** |
| Failure posture | config **fails OPEN** (it always serves; abuse only stops metering) | check **fails CLOSED** (an outage denies) |
| SDK / CLI | **none. Zero surface.** | PHP + TS SDK, `@agreely/cli` |
| Question answered | "may this tracker run in this browser now?" | "may I use this customer's data for this purpose now?" |
| Failure to install right | trackers leak on zero click | personal data used without a basis |

Grep `~/www/agreely-sdk-php`, `~/www/agreely-sdk` and `~/www/agreely-cli` for
`siteKey`, `/e/v1`, `agr_pub_` or `cookie-banner` and every one returns **zero
files** (verified). There is no programmatic client for this tier and
you should not write one for a customer: the browser client *is* the client, and
the two public read endpoints (`/e/v1/config`, `/e/v1/proof`) are plain CORS-open
JSON that anything can `fetch`.

**Two similarly named hosts, and they are different apps.** `cookies.agreely.ca`
is the *management* app (MODE=TEMOINS, where an operator logs in). `cdn.agreely.ca`
is the *edge* (MODE=COOKIES, what browsers hit). Measured with a live public GET:

```
GET https://cdn.agreely.ca/e/v1/config/ak_test_bogus      -> 200, the inactive config
GET https://cookies.agreely.ca/e/v1/config/ak_test_bogus  -> 404 (an HTML error page)
```

If a snippet points at `cookies.agreely.ca`, nothing works and the browser console
shows a 404 for `loader.js`, not a consent error.

## The install: one tag, and it must not be `async`

```html
<script src="https://cdn.agreely.ca/e/v1/loader.js"
        data-agreely="agr_pub_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"></script>
```

That is the whole install, and it is exactly what the app's own onboarding step and
website-detail page generate (`OnboardingController.php:523`,
`WebsiteDetailController.php:277`). **First in `<head>`, before any tag manager, and
with no `async` and no `defer`.**

### Why `async` silently breaks it

The loader is deliberately synchronous because, before it returns, it does three
things in order (`agreely-banner/src/loader.ts`):

1. installs the Google Consent Mode v2 **default-deny** signal;
2. arms the **dynamic-injection guard** against a built-in known-tracker denylist,
   *before any network await*, so a tracker injected during the config-fetch
   round-trip is neutralized;
3. exposes the `window.agreelyCookies` command queue, then injects the versioned
   banner module asynchronously.

Add `async` and all of that happens *after* your other tags have booted. Measured
with a probe script placed immediately after the loader tag, in a headless browser:

| loader tag | probe saw `__agreelyConsentDefault` | `gtag` defined | dataLayer consent entries | injected `google-analytics.com` script |
|---|---|---|---|---|
| `<script src=...>` | `true` | `true` | 1 | `type="text/plain"` (blocked) |
| `<script async src=...>` | `false` | `false` | 0 | **executable, it runs** |

The failure is invisible after the fact: once the page settles, the async install
*also* shows a default-deny signal and an armed guard, because the loader eventually
ran. The leak happened in the window before it. That is why an audit must read the
**tag attributes**, not only sample state at rest (`examples/agreely-cookies/zero-click-check.mjs`
does both).

The cost of "synchronous" is small: the loader is **2 528 bytes gzipped on the wire**
(6 140 raw), and the banner module it injects (21 KB gzipped) loads asynchronously
and never blocks render.

### Optional attributes on that tag

| Attribute | Effect |
|---|---|
| `data-nonce="abc123"` | passes your CSP nonce through to the injected module script |
| `data-base="https://..."` | overrides the edge origin (self-hosting, fixtures). Defaults to the loader's own origin |
| `data-banner-integrity="sha384-..."` | overrides the built-in default module SRI (only when self-hosting a re-hashed build) |
| `data-agreely-widget="off"` | forces the post-choice floating "Gérer mes témoins" button OFF regardless of the published config |

**SRI is on by default.** The build stamps the banner module's `sha384` into the
loader (`scripts/build.mjs`), so the injected `<script>` carries `integrity` +
`crossorigin="anonymous"` with no extra attribute. Verified in a browser: the
injected module element carried `integrity="sha384-tGURIBO0SzS8C0ZyK..."`, matching
`dist/manifest.json`.

### Two asset channels

| Path | `Cache-Control` (measured) | Use |
|---|---|---|
| `/e/v1/loader.js`, `/e/v1/banner.js` | `no-cache` + strong `ETag` (a repeat request with `If-None-Match` returns **304**) | the default. A publish propagates on the next load, no hard refresh |
| `/e/v1.3.16/loader.js`, `/e/v1.3.16/banner.js` | `public, max-age=31536000, immutable` | pin an exact version |

An unknown version (`/e/v9.9.9/banner.js`) and any traversal attempt both return
**404**; the channel and filename are strictly validated. `no-cache` is deliberate:
an earlier `stale-while-revalidate=86400` let a browser serve the previous
`banner.js` for up to 24 h after a publish.

## CSP and CORS

Three directives, and **no `unsafe-inline`** for scripts or styles:

```
script-src  https://cdn.agreely.ca ;
connect-src https://cdn.agreely.ca ;
img-src     https://cdn.agreely.ca ;
```

`connect-src` is not optional: SRI forces a CORS fetch of the module, and the client
`fetch`es the config and POSTs the decision to the same origin. `img-src` covers the
logo endpoint.

Verified in a real headless browser under
`default-src 'self'; script-src 'nonce-abc123' <edge>; connect-src <edge>; img-src 'self' data: <edge>; style-src 'self' 'nonce-abc123'`:

```
hostPresent      true          adoptedSheets    2
shadowOpen       true          inlineStyleTags  0
consentDefault   true          moduleNonce      abc123
CSP violations   (none)        moduleIntegrity  sha384-tGURIBO0SzS8C0ZyK
```

`style-src` needs no Agreely entry at all: the banner styles a shadow root through
a **constructable `CSSStyleSheet`** (`replaceSync` + `adoptedStyleSheets`), so there
is no `<style>` element and no inline `style` attribute to allow.

There is nothing to configure on your side for CORS. The config is `ACAO: *`; the
consent POST is a **simple** CORS request (body `text/plain`, no custom header), so
no preflight is issued in normal operation.

## The site key

Format `agr_pub_` + 32 base62 characters (`SiteKeyGenerator.php`). It is **public
and capability-less**: it lets a caller read the site's public config (which carries
no personal information) and append an origin-checked, schema-validated,
rate-limited consent record. Nothing else. It is stored in plaintext, safe to log,
safe in page HTML, and deliberately prefixed differently from the secret
`agr_live_` API key so the two can never be pasted into the wrong slot.

What protects it is **server-enforced origin binding**, not secrecy. Rotation mints
a new key with a 24 h grace window during which both work; then revoke the old one.

## Domain verification is by SNIPPET, never by DNS

Verifying a website means the scanner loads the page and confirms a
`data-agreely` tag carrying **that** site key is present. There is **no TXT record,
no uploaded file**. The owner-triggerable methods are exactly `snippet` (the
default) and `inherited` (a subdomain of an already-verified zone); a forged
`method=dns_txt` POST falls back to the snippet check rather than doing a DNS lookup
(`WebsiteDetailController::normalizeMethod`).

The `cookie.verify_method` enum still contains `dns_txt` for legacy rows, and the
**dev seed writes `dns_txt`**, which is misleading. Do not tell a customer to publish
a TXT record for a banner.

> ⚠️ **Confusable.** Agreely *does* use a DNS TXT record elsewhere, for the company
> **DID** and epoch-root signing. That is a different feature on a different part of
> the platform and it has nothing to do with installing a banner.

## The serving gate: `active`

`GET /e/v1/config/{siteKey}` **never 404s in a way that breaks a page.** It always
returns `200` with a valid `BannerConfig`. The top-level boolean `active` is the
gate:

- `active: true` -> the real banner renders.
- `active: false` (with `configVersion: 0` and an all-zero `configHash`) -> the
  client renders **nothing**. Not a hollow banner. Nothing.

`active` is true only when **all** of: the company is entitled, the website status is
`verified`, the key is not revoked, and **a banner version has been published**
(`ConfigEndpointService::forSiteKey`).

That last term is the one that surprises everyone. Verifying the domain and seeing
the tag detected is **not enough**; a human must publish.

### The ten serving states

`BannerServingStatusService` decides one state per website and every operator surface
reads it (the sites list, the dashboard, the install page, the designer, Publishing).
Know these, because "the banner does not show" is always one of them.

| State | Meaning | Edge serves |
|---|---|---|
| `not_entitled` | the company's banner entitlement is 0 (lapsed / cancelled) | `active:false`, essential-only |
| `suspended` | the site was **removed** by the customer (`status='suspended'`) | `active:false` |
| `unverified` | the domain is not verified | `active:false` |
| `key_revoked` | no usable site key remains | `active:false` (no key resolves) |
| `no_categories` | a publish would carry no non-essential purpose **and** the scan picture is not clean | `active:false` |
| `none_needed` | the site genuinely has no non-essential tracker. A success, not a fault | nothing to serve |
| `unpublished` | consent IS solicited but nothing was ever published | **`active:false`** |
| `published_empty` | something IS published but its non-essential set is empty while the registry has one | serves a banner that renders nothing |
| `behind` | displaying, but a publish now would change the served content | serves the older version |
| `displaying` | published, current, non-essential present | `active:true` |

`published_empty` exists because it happened to a paying customer: verified domain,
detected tag, published config, edge answering `active:true`, and no banner, because
the published snapshot contained only `strictly_necessary`. Scan findings reach the
**published** snapshot only when a human publishes again.

## `GET /e/v1/config/{siteKey}`

Public, cacheable, `ACAO: *`, **no personal information**. Cache TTL by state
(measured):

| State | `Cache-Control` |
|---|---|
| `active:true` | `public, max-age=60, stale-while-revalidate=600` |
| `active:false` | `public, max-age=30` (short, so a newly published site goes live fast) |

Payload (the contract of record is `agreely-banner/src/types.ts`):

| Field | Notes |
|---|---|
| `siteKey` | echoed back |
| `active` | **the serving gate** |
| `categories[]` | `{key, essential, label_fr, label_en, purpose_fr, purpose_en}`. `strictly_necessary` is always present and essential; the non-essential set is **derived from the registry** so `decisions` keys match exactly |
| `cookieTable[]` | `{name, category, provider, purpose_fr/en, retention_fr/en, crosses_border, profiling}` |
| `colors` | `{surface, accent}`. Legacy `{primary, secondary}` still renders. On a free site the stored colours are **ignored** and Agreely's palette is forced |
| `logoUrl` | points at `/e/v1/logo/{siteKey}` on this same origin |
| `position` | `bottom` \| `bottom-left` \| `bottom-right` \| `top` \| `center` |
| `policyLinks[]` | `{label_fr, label_en, href}`, `http(s)` only |
| `configVersion` | monotonic per website |
| `configHash` | `0x` + 64 hex, `keccak256(JCS(config))`. The client echoes it on every decision |
| `consentTtlDays` | default **183** (6 months), symmetric for accept and refuse |
| `defaultLocale` | `fr` \| `en` |
| `showManageButton` | whether the floating reopen button renders |
| `branding` | **tier-set**: `agreely` (free, unremovable mark) or `none` (paid). Never customer-set |
| `disclosures` | `crosses_border`, `profiling`, `profiling_vendors[]`, `collects_ip`, `audience_minors`, plus `rightsNotice_fr` / `rightsNotice_en` |
| `trackerHosts[]` | hosts the injection guard gates, merged onto the client's built-in denylist |
| `trackerHostCategories[]` | `{host, category}`, per-purpose release. A host under several categories collapses to its **strictest** one (marketing > analytics > functional) |
| `purge` | `{cookies[], localStorage[], sessionStorage[]}` to clear on refuse/withdraw |

`disclosures.rightsNotice_fr` / `_en` is a **fixed, non-editable** bilingual sentence
covering the standing rights of access and rectification (art. 8 al. 1 (3°)) and the
right to withdraw consent (art. 8 al. 1 (4°)); the persistent reopen control is what
gives that right effect (art. 29). The tenant cannot alter or suppress it. The
banner README still documents a single `rightsNotice` string; the server actually
emits the **per-locale pair**, and the client falls back single -> built-in.

> **Shape wart, verified.** A *structurally invalid* site key (say `not-a-key`) is
> answered by a controller-level fallback that omits `showManageButton`,
> `trackerHostCategories` and `purge`, while a *well-formed but unknown* key goes
> through the service and includes all three. Both are `active:false`, so the client
> short-circuits before it would read them, but do not write a consumer that assumes
> every key present.

### Metering (fails open, never gates serving)

Each served config increments the usage meter off the hot path. Three properties
that matter, all measured:

- **Billing never darkens the banner.** The monthly-loads quota is *never consulted*
  on the serve path. The banner is the customer's legal consent mechanism and must
  not go dark for a billing reason. Only the cosmetic branding perk is withheld for
  non-payment, and a free Agreely-branded banner is itself fully usable. Do not add a
  quota check there; the source says so in as many words.
  Distinguish **quota** (soft, never gates) from **entitlement** (allowed websites).
  A lapsed or cancelled tenant whose entitlement drops to 0 falls back to the neutral
  essential-only `active:false` payload, which is the protective direction: it never
  silently allows non-essential trackers. Every free tier is entitled.
- **A "load" is deduped.** One billed load per `{website, truncated IP, minute}`.
  Measured: 5 config fetches from one IP in one minute increased the meter by **1**.
- **Abuse stops the meter, not the service.** Over 600 counted fetches/min per key
  or 60/min per truncated IP, the config is **still served** and simply not counted,
  so a fetch flood cannot inflate a victim's bill.

## The inventory: what the banner is allowed to say

Four consent categories plus a holding value (`cookie.category` enum):
`strictly_necessary`, `functional`, `analytics`, `marketing`, `unclassified`.
`unclassified` is **not** a consentable purpose; it blocks rather than appears.

The pipeline is: **scan -> deterministic reference match -> LLM proposal -> human
review -> publish.**

1. **Scan.** A headless Chromium visits the site and records cookies, storage keys
   and third-party hosts contacted, before and after consent (`cookie.scan_mode` is
   `discovery`, `enforcement_check` or `verification`).
2. **Reference pass.** Known cookies are matched against a deterministic library,
   free and offline.
3. **LLM proposal.** Unknown or ambiguous entries go to Claude with a **confidence
   score**. It receives metadata only: name, cookie domain, attributes, co-present
   third-party hosts, page title and language. It **never** receives a cookie
   **value** and never any visitor data. Anthropic is a **processor located outside
   Quebec** and that communication is documented (art. 17).
4. **Human review.** A row "needs review" when the pipeline was unsure and no human
   resolved it. The exact predicate (`CookieInventoryBroker::NEEDS_REVIEW`):
   `confidence IS NOT NULL AND confidence < 0.85 AND NOT overridden AND (reviewed_at IS NULL OR reviewed_category IS DISTINCT FROM category)`.
   Two distinct human acts, never conflated: **rewriting** a row sets `overridden`
   (the pipeline never touches it again), **confirming** stamps
   `reviewed_at/by/category` and leaves the pipeline free to maintain it.
5. **Publish.** The registry becomes a versioned, hashed snapshot.

"Strictly necessary" is always a human determination, never the model's. And art. 9
al. 2 ("en cas de doute, réputé non nécessaire") anchors the conservative direction
throughout: an uncategorized finding keeps the "no banner needed" predicate false.

### The publish gate ladder

`POST /cookies/{websiteId}/publication/publish` runs these in order, server-side,
reading **no** request parameter (so a forged POST can only publish what the page
showed):

1. entitlement;
2. write role + the site still accepts writes;
3. **would this publish serve anything** (verified + a usable key + a non-essential
   candidate, unless it retires purposes from a live banner);
4. go-live passkey delegation, **first publish only**, grandfathered and fail-open;
5. **art. 3.1 review gate**: refuse while any registry row still needs a human;
6. **art. 8.2 policy-link gate**: a site that solicits consent must expose at least
   one valid `http(s)` policy link, checked through the same builder that would strip
   an invalid one;
7. free-tier brand pin (never a refusal: the colours are pinned to Agreely's palette
   for the published config, and the customer's own draft colours are preserved);
8. WCAG contrast >= 4.5:1 on the effective colours.

Publishing a new version **re-prompts every returning visitor**, because the client
compares `configVersion` strictly. Measured: a visitor cookie carrying
`configVersion: 1` re-shows layer 1 once version 2 is published; a cookie carrying
`configVersion: 2` shows only the reopen control. Do not republish casually.

## Blocking trackers: three layers

**1. Declarative.** Change the type and tag the category:

```html
<script type="text/plain" data-agreely-category="analytics"
        src="https://www.googletagmanager.com/gtag/js?id=G-XXXXXXXXXX"></script>
```

The client swaps the type back when that category is granted, preserving any nonce.
`strictly_necessary` scripts need no attribute.

**2. Consent Mode v2 default-deny**, installed synchronously by the loader (below).

**3. The dynamic-injection guard**, for tags that inject scripts at runtime. It
intercepts `appendChild`, `insertBefore`, `replaceChild`, `append`, `prepend`,
`after`, `before`, `replaceWith`, `replaceChildren` and `insertAdjacentElement`; a
`<script>` whose `src` host matches (exact or suffix) is neutralized to `text/plain`
and queued until **its own category** is granted. Measured pre-consent:

```
injected https://www.google-analytics.com/analytics.js -> type="text/plain"   (blocked)
injected https://cdn.jsdelivr.net/npm/x.js             -> (executable script) (passes)
```

`document.write` is out of scope; Consent Mode default-deny is the backstop.
Consent-gated iframes use `data-agreely-src` + `data-agreely-category` and render a
bilingual placeholder at the iframe's own size.

> ⚠️ **`trackerHosts` can contain the site's OWN domain, and then the guard blocks
> the site's own scripts.** `trackerHosts` is derived from each non-essential
> registry row's `cookie_domain` (`BannerConfigBuilder::trackerHosts`), which comes
> straight from the scanned cookie's domain attribute. GA4's `_ga` and Meta's `_fbp`
> are set **first-party**, under the site's own domain, so a real scan puts
> `customer.com` on the tracker denylist. Reproduced in a browser against a published
> config whose `trackerHosts` was `["assurance-boreale.ca"]`:
>
> ```
> injected https://assurance-boreale.ca/app.js -> type="text/plain"   <-- the site's OWN script, blocked
> ```
>
> Symptom on a real site: first-party scripts injected at runtime silently stop
> running until the relevant category is granted. Workaround today: correct the
> offending inventory row's cookie domain (a human override is permanent), or move
> the affected script to the declarative `type="text/plain"` path so its gating is
> explicit. The real fix belongs in the builder (exclude the website's own
> registrable domain) and is not shipped.

## Google Consent Mode v2

The loader pushes, synchronously, before any tag manager:

```js
gtag('consent', 'default', {
  ad_storage: 'denied', analytics_storage: 'denied',
  ad_user_data: 'denied', ad_personalization: 'denied',
  functionality_storage: 'denied', security_storage: 'granted',
});
```

`security_storage: 'granted'` covers the `agreely_consent` cookie itself, which is
strictly necessary (it stores only the person's own choice).

On each decision the client pushes an update:

| Agreely category | Consent Mode v2 signals |
|---|---|
| Marketing | `ad_storage`, `ad_user_data`, `ad_personalization` |
| Analytics | `analytics_storage` |
| Functional | `functionality_storage` |
| Strictly necessary | none (always granted) |

Measured after "Tout accepter" on a three-purpose site: exactly one `default`
(all denied but `security_storage`) followed by one `update` (all granted). After
`refuseAll()`: an `update` with everything denied except `security_storage`.

**There is no custom DOM event.** The client does not dispatch `agrConsent` or
anything like it. To react to a change, hang off Consent Mode, call the
`window.agreelyCookies` API, or read the cookie.

## `POST /e/v1/consent`

Sent by the client as a **simple** CORS request (`Content-Type: text/plain`, no
custom headers) via `navigator.sendBeacon` **and** a `keepalive` fetch, so the record
survives an accept-and-navigate while the fetch captures the response body. Both
carry the **same per-submission `nonce`**, so the twin collapses to one row.

Body:

```json
{
  "siteKey": "agr_pub_...",
  "action": "grant | update | withdraw",
  "decisions": { "functional": true, "analytics": true, "marketing": true },
  "configHash": "0x<64 hex>",
  "configVersion": 1,
  "locale": "fr | en",
  "nonce": "3dcd4088bac129c3042ae8d2b6e9a2f0"
}
```

Success is `200 {"consentId": "<uuid v4>", "receipt": "0x<64 hex>"}`. Real capture:

```
> POST  text/plain;charset=UTF-8  Origin: http://localhost:8134
  {"siteKey":"agr_pub_IaNt...","action":"grant","decisions":{"functional":true,"analytics":true,"marketing":true},
   "configHash":"0xc28c6feffb2ad546822b373ef9ab568e8e3810f15f7fad524a363109dd1cce38","configVersion":1,
   "locale":"fr","nonce":"3dcd4088bac129c3042ae8d2b6e9a2f0"}
< 200  ACAO: http://localhost:8134
  {"consentId":"cec50c1a-7def-42e9-be18-d422c1b1d0b0","receipt":"0x152b994282813fabb09b7c05ca9a0e894fa78a69c96b7b4882631e5ca4e87a40"}
```

The `consentId` is a **server-minted random UUID v4**, never derived from any
visitor attribute. The `receipt` is deterministic from the `consentId`, so the
beacon and the keepalive fetch return the same one.

### The gate ladder (every gate FAILS CLOSED)

`ConsentIngestService::ingest`, in this order. Only the metering fails open.

| # | Gate | Refusal | Status |
|---|---|---|---|
| 1 | strict body schema (types, enum values, <= 8192 bytes, optional but well-formed `nonce`) | `invalid_request` | 400 |
| 2 | per-truncated-IP pre-throttle (**60/min**, env-tunable) | `rate_limited` + `Retry-After` | 429 |
| 3 | site key resolves and is not revoked | `unknown_site` | 403 |
| 4 | website is `verified` (not pending, not removed) | `website_not_active` | 403 |
| 5 | **Origin** (or `Referer` fallback) matches the registered domain, a subdomain of it, or a declared extra origin | `origin_mismatch` | 403 |
| 6 | per-site-key limit (**600/min**), deliberately **after** the origin gate | `rate_limited` | 429 |
| 7 | the echoed `configHash` matches a config this website actually published | `stale_config` | 409 |
| 8 | `decisions` keys equal **exactly** that config's non-essential purposes | `decisions_mismatch` | 422 |
| 9 | dedup on `{website_id, nonce}` (or, with no nonce, on `{website_id, config_hash, decisions, action}`) | (replays the first `consentId`) | 200 |

The per-key limit sits *after* the origin gate on purpose: an origin-mismatched
forged flood is refused at step 5 and can never exhaust a victim's public key budget.

Every refusal below was produced against a running edge, not read off a doc:

| What was sent | Response |
|---|---|
| body `hello` (not JSON) | `400 {"error":{"code":"invalid_request"}}` |
| `"action":"nope"` | `400 invalid_request` |
| `"locale":"es"` | `400 invalid_request` |
| `"siteKey":"nope"` (fails the shape gate before any DB read) | `400 invalid_request` |
| a well-formed key that resolves to nothing | `403 unknown_site` |
| a key belonging to a site still `pending` | `403 website_not_active` |
| a `configHash` never published for this site | `409 stale_config` |
| `decisions` missing a purpose the config offered | `422 decisions_mismatch` |
| `decisions` with an extra key, or with `strictly_necessary` smuggled in | `422 decisions_mismatch` |
| the same `nonce` replayed | `200`, the **same** `consentId` and receipt (deduped, one row) |
| `"action":"withdraw"` with all purposes false | `200`, a new `consentId` |

Measured, against a verified site whose registered domain is `assurance-boreale.ca`:

```
apex Origin      https://assurance-boreale.ca      409 stale_config   ACAO: https://assurance-boreale.ca
www subdomain    https://www.assurance-boreale.ca  409 stale_config   ACAO: https://www.assurance-boreale.ca
http scheme      http://assurance-boreale.ca       409 stale_config   ACAO: http://assurance-boreale.ca
hostile Origin   https://evil.example              403 origin_mismatch   NO ACAO
no Origin at all (none)                            403 origin_mismatch   NO ACAO
```

On a refusal the endpoint sets **no** `Access-Control-Allow-Origin` at all, so a
hostile cross-origin page cannot even read the refusal body. On a match it echoes
**that origin only**, never `*`, with no credentials, and `Vary: Origin`.

> **The OPTIONS preflight is permissive on purpose.** `OPTIONS /e/v1/consent` echoes
> *any* `Origin` with `204` and `Access-Control-Max-Age: 600`. It is a defensive
> backstop for a future non-simple client; the real gates run on the POST. Do not
> read a successful preflight as "my origin is allowed".

### Stale configs are accepted, and the version is re-derived

A visitor on a cached page may echo an **older but once-published** `configHash`.
That is accepted (breaking a cached page would be worse), and the stored
`config_version` is re-derived from the matched row. The client's `configVersion` is
advisory and **discarded**. Measured: a POST echoing v1's hash while claiming
`configVersion: 99` was stored as `config_version = 1`. A hash that was **never**
published is refused `409 stale_config`.

### IP and user agent

Off by default (art. 5; art. 9 al. 2). `ip_trunc` and `user_agent` are stored
**only** when the website opts in, and the IP is truncated *before* storage (IPv4 to
a /24, IPv6 to a /48). The raw address never reaches a table or a log. Throttling
uses the truncated value regardless, which is not new collection.

## The visitor cookie

`agreely_consent`, first-party, `SameSite=Lax`, `Path=/`, `Secure` on https,
`Max-Age` = `consentTtlDays` (183 by default, **symmetric** for accept and refuse).
URL-encoded JSON, captured verbatim:

```json
{"v":1,"consentId":"cec50c1a-7def-42e9-be18-d422c1b1d0b0",
 "decisions":{"functional":true,"analytics":true,"marketing":true},
 "configVersion":1,"ts":1787418698,"locale":"fr","receipt":"0x152b9942..."}
```

Classified strictly necessary: it holds only the person's own choice plus an opaque
server-minted id.

**Read it server-side before you emit any `Set-Cookie`.** Client JavaScript cannot
read or delete an `httpOnly` cookie and cannot stop your server setting one; the
banner never claims it can. See `examples/agreely-cookies/server-side-gate.php`.
Absent cookie == refusal. Re-read on every relevant request rather than caching the
decision in a session, because the visitor can withdraw in another tab.

> ⚠️ **The cookie is written even when the server refused the record.** Measured on
> an unregistered origin: the POST came back `403 origin_mismatch`, and the client
> still wrote `agreely_consent` with `"consentId":""`. Enforcement is honoured
> locally (everything stays denied) but **no evidence exists server-side**. An empty
> `consentId` in the cookie is the tell.

## The browser API

```js
window.agreelyCookies.open();       // open the preferences layer
window.agreelyCookies.acceptAll();
window.agreelyCookies.refuseAll();
window.agreelyCookies.withdraw();
window.agreelyCookies.version;      // "1.3.16"
window.agreelyCookies.ready;        // false while the loader stub is queueing
```

Measured surface on v1.3.16: exactly `version, ready, open, acceptAll, refuseAll,
withdraw`. Calls made before the module lands are queued by the loader and replayed.
Declaratively, any element with `data-agreely-open` reopens the panel:

```html
<a href="#" data-agreely-open>Gérer mes témoins (cookies)</a>
```

Keep a reopen affordance visible and keyboard reachable on every page: withdrawal
must be as easy as consent (art. 8 al. 1 (4°), given effect per art. 29).

### What the visitor actually sees

Layer 1 carries three **structurally identical, equal-weight** actions, plus a live
FR|EN toggle and the policy link. Measured on a free-tier site:

```
["FR", "EN", "Politique de confidentialité", "Tout accepter", "Tout refuser",
 "Personnaliser", "Propulsé par Agreely"]
```

Layer 2 is a real `role="dialog" aria-modal="true"` with a focus trap; Escape closes
it (verified). Every non-essential toggle defaults **off** (verified: three
checkboxes, all unchecked, none disabled), the fixed rights notice renders at the
bottom, and each category expands to the bilingual cookie table.

On a **minor-directed** site (`disclosures.audience_minors`) the one-click accept-all
shortcut is suppressed on both layers so a minor chooses each purpose individually,
with a notice that a banner does not replace parental consent (art. 4.1).

Free tier serves `branding: "agreely"`: a small bilingual "Propulsé par Agreely"
link **inside the shadow root**, so page CSS cannot hide it and no customization
value suppresses it. The flag is set by the tier, never by the customer. Note the
shadow root is `mode: 'open'`, so the isolation claim is **against page CSS**, not
against page JavaScript.

## Proof and verification

### `GET /e/v1/proof/{consentId}`

Public, `ACAO: *`, rate-limited on the truncated IP. Three honest statuses:

| `status` | HTTP | Meaning | `Cache-Control` |
|---|---|---|---|
| `anchored` | 200 | full bundle: claim, commitment, row hash, Merkle path, epoch root, on-chain tx | `public, max-age=300` |
| `pending` | **200** | recorded and hash-chained, not yet anchored. Never an error | `no-store` |
| `unknown` | 404 | no decision matches this id | `no-store` |

Add `?recompute=1` for the salt-opened recompute leg (it carries operator UUIDs, so
it is opt-in and not in the default public bundle).

Measured lifecycle on a local stack: a decision recorded at `17:11:38` read
`pending` immediately and `anchored` by `17:12:19`, about **40 seconds**. In
production the epoch closer runs hourly, so budget an hour, not a minute.

The anchored bundle carries an `epoch.attestation` block signed with the company's
registered DID key, and it labels itself honestly:

> *"Signé avec la clé DID enregistrée de votre entreprise (clé hébergée par Agreely;
> ce n'est pas une non-répudiation)."*

That is the correct framing: the DID key is **Agreely-hosted defense in depth**, not
non-repudiation. Do not upgrade that claim.

### `GET /verify`

A bilingual, server-rendered page (`?c=<consentId>`, `?lang=en`) on the same edge,
for a visitor, an auditor or a regulator with no Agreely account. It reads the same
service as the JSON endpoint, so the two can never diverge. It shows the decision,
the banner hash and version, the commitment, the epoch root, the inclusion path, the
transaction with a Basescan link, and the full recompute recipe including the
canonical claim bytes and the salt.

Its badges are the honest ones: *"Vérifié : enregistrée et non modifiée"*,
*"Enregistrée, ancrage en cours"*, *"Introuvable"*. Never "compliant".

### What the anchor does and does not prove

The three cryptographic legs, all independently recomputed with a dependency-free
Keccak-256 in `examples/agreely-cookies/verify-proof.mjs` and all matching real data:

```
commitment = keccak256( JCS(boundClaim) || salt32 )
rowHash    = keccak256( prevHash || keySortedJson({chain_seq, action, config_version,
                                                   occurred_at, commitment}) )
root       = commutative-keccak fold of the leaf up the inclusion path
```

Two contract details that bite anyone re-implementing this:

- The claim is **number-free** on purpose (`config_version` is the string `"1"`,
  `decisions` is a JSON *string*), because number formatting is the classic
  cross-language hashing divergence. The chain payload in leg 2 is the deliberate
  exception: `chain_seq` is a real JSON number there.
- Pairing is **commutative** (smallest-first concat, OpenZeppelin
  `commutativeKeccak256`), so an OpenZeppelin `MerkleProof` verifies these paths
  unchanged.

**Proves:** this decision was recorded as shown and has not been modified since.

**Does not prove:** that the banner shown was Law 25 compliant; that every cookie
the site set honoured the decision; the legal validity of the consent (the
controller's duty); and it is **not operator-proof** (anchoring proves
non-modification *since recording*, not fidelity of the original capture). That is
exactly why independent verification by the visitor, using their own `consentId`
from their own cookie, is the complementary trust layer.

### Retention: coarsened, never deleted

After a configurable window (**36 months** by default, the controller's call), the
readable columns (`decisions`, `banner_locale`, `ip_trunc`, `user_agent`) are NULLed
and the salt is destroyed, making the commitment a one-way orphan. **Rows are never
deleted**: `commitment`, `config_hash`, `config_version`, `prev_hash`, `row_hash`,
`chain_seq` and `epoch_id` survive, so the per-site chain, the epoch root and the
anchor stay verifiable forever. A coarsened row still passes fast verification; deep
verification treats its commitment as an intentional orphan.

Honest boundary, stated by the code itself: with the shipped DEK vault backend a
DEK-encrypted salt residual persists in WAL and base backups until they age out. Do
not say "anonymized forever" on that backend.

## The failure modes you will actually hit

**1. "The banner does not show."** Almost always `active:false`. Run
`examples/agreely-cookies/edge-probe.sh` and read the state. In order of frequency:
nothing has been **published** (verifying the domain is not enough); the domain is
not verified; the site was removed; the key was rotated and the page still carries
the old one; an ad blocker ate the loader; or the visitor already holds a valid
`agreely_consent` cookie for the current `configVersion` (delete it to re-test).

**2. `403` on the consent POST, and the console error is not the one the docs
promise.** The client contains a bespoke French warning for this case, and it is
**unreachable**, because the fail-closed refusal sends **no** `Access-Control-Allow-Origin`,
so the browser blocks the response, `fetch` rejects with an opaque CORS TypeError,
and the handler that would log the warning never runs. What an integrator really
sees is Chrome's generic message:

```
Access to fetch at 'https://cdn.agreely.ca/e/v1/consent' from origin 'https://wrong.example'
has been blocked by CORS policy: No 'Access-Control-Allow-Origin' header is present
on the requested resource.
```

The public documentation (`/cookie-banner/security/`) quotes that French warning as
the signal to look for. **Do not send an integrator hunting for it.** Debug with
`curl` instead, where the server body is perfectly informative
(`{"error":{"code":"origin_mismatch"}}`), or read the 403 straight out of the
Network panel. Fix: add the origin under the site's **staging origins**, or correct
`data-agreely` to the key that belongs to that domain. Related dead code in the same
handler: it tests `json.error === 'origin_mismatch'` while the server returns
`{"error":{"code":"origin_mismatch"}}` (nested), so that comparison is always false.

**3. Cookies still present after "Tout refuser".** They are `httpOnly` or
server-set. JavaScript cannot touch those. Gate them server-side. If a
non-`httpOnly` third-party cookie survives, its script tag was never converted to
`type="text/plain" data-agreely-category="..."`.

**4. Trackers fire on zero click.** Check for `async`/`defer` on the loader tag and
check it is the first script in `<head>`. See the measured table above.

**5. `CSP` errors.** Missing `connect-src`, usually. SRI forces a CORS fetch of the
module, and that fetch is governed by `connect-src`, not `script-src`.

**6. Publishing is refused.** Read the flash: unresolved review rows (art. 3.1), a
missing policy link (art. 8.2), a contrast failure, or "nothing to publish" because
the candidate carries no non-essential purpose.

**7. A first-party script stopped running.** See the `trackerHosts` warning above.

**8. The banner reappeared for everyone.** Someone published a new version.
`configVersion` changed, so every returning visitor is re-prompted by design.

**9. `409 stale_config` from your own origin.** In a live install this means the
page is serving a `configHash` that was never published for this website: nearly
always a hand-edited or fixture config. In a probe it is the *expected* good answer.

**10. The banner behaves, but the consent register is empty.** The client writes
`agreely_consent` even when the server refused the record, with `"consentId":""`.
Enforcement is honoured locally so nothing looks broken, and no evidence exists.
Check the register in the dashboard, and check the Network panel for a 403 or 409 on
`/e/v1/consent`. An empty `consentId` in the cookie is the tell.

## Local development and reproduction

The app is one MODE-scoped codebase. There is **no MODE=COOKIES container in
`docker-compose.yml`**, so bring the edge up yourself:

```bash
docker compose run --rm -d -e MODE=COOKIES -e COOKIES_BASE_URL=http://localhost:8096 \
  -p 8096:80 --name agreely_cookies_edge web
curl -s http://localhost:8096/e/v1/config/agr_pub_00000000000000000000000000000000
```

`http://localhost:8095` is the management app (MODE=TEMOINS), `:8080` the main web
app, `:8025` Mailpit. The dev database role is `dev`:
`docker compose exec -T database psql -U dev -d agreely`.

> ⚠️ **The showcase seed is incoherent for this tier, and it will mislead you.**
> `make seed-showcase` creates 6 cookie websites and dozens of `cookie.consent_event`
> rows but **zero `cookie.banner_config` rows** and **zero `cookie.cookie_entry`
> rows**. So every seeded site reads `active:false` / "no banner published" while
> still showing consent history, and every seeded consent event carries an all-zero
> `config_hash` that was never published. Consequence: a consent POST against any
> seeded site is refused `409 stale_config`. It also stamps `verify_method='dns_txt'`,
> a method the UI no longer offers. This is seed data, not app behaviour.

To get a real published banner locally, do it through the UI: add inventory rows,
save a policy link in the designer, then publish from the Publishing page. Note the
manual "add a cookie" form only renders inside a *discovered-but-unclassified* row,
so with no scan (there is no local scanner service) you post to
`POST /cookies/{websiteId}/inventory` directly with the page's CSRF token.

**Never write to production.** `cdn.agreely.ca` and `cookies.agreely.ca` are live.
Harmless public GETs (`/e/v1/config/<bogus>`, `/verify`, `/e/v1/public-scan-challenge`)
are fine; a consent POST is not.

## The management surface (where an operator does things)

Served by MODE=TEMOINS at `https://cookies.agreely.ca`, session + CSRF, tenant-scoped.

| Route | Purpose |
|---|---|
| `GET /cookies` | the sites list, each with its serving state |
| `GET /cookies/websites/add`, `POST /cookies/websites` | add a site (always created on the free tier; creating never charges) |
| `GET /cookies/{id}` | per-site dashboard |
| `GET /cookies/{id}/install` | the snippet, the gated-tag examples, the verification button |
| `POST /cookies/{id}/verify` | snippet-presence verification (enqueues a scan) |
| `POST /cookies/{id}/key/rotate` | mint a new site key (24 h grace) |
| `GET /cookies/{id}/inventory` + `POST .../inventory[/{entryId}][/confirm|/confirm-all|/promote]` | the registry and its human review |
| `POST /cookies/{id}/scan`, `GET /cookies/{id}/scan/status` | run and poll a discovery scan |
| `GET /cookies/{id}/banner`, `POST .../banner/save`, `POST .../banner/logo[/delete]` | the designer (colours, position, policy links, logo, manage button) |
| `GET /cookies/{id}/publication`, `POST .../publication/publish` | what a publish would contain, the diff against what is served, and the publish act |
| `GET /cookies/{id}/publication/versions/{version}[/config]` | a published version and its exact config |
| `GET /cookies/{id}/consents[/export]`, `GET .../consents/{consentId}[/proof]` | the consent register and per-decision evidence |
| `GET /cookies/{id}/enforcement`, `POST .../enforcement/check`, `GET .../enforcement/status` | the zero-click verification and its report |
| `POST /cookies/{id}/origins/add`, `POST .../origins/remove` | staging / localhost origins (max 20) |
| `GET /cookies/{id}/integrity`, `POST .../integrity/verify` | replay the site's hash chain |
| `GET /cookies/{id}/settings`, `POST .../settings` | default locale, `audience_minors`, `collect_ip` |
| `POST /cookies/{id}/plan/upgrade|downgrade`, `POST /cookies/{id}/domain`, `POST /cookies/{id}/suspend` | slot assignment, domain change, remove the site |
| `GET /billing`, `POST /billing/places` | buy paid slots (the only screen that charges) |
| `GET /onboarding/*` | the guided first-site flow |

**Staging origins** (`extra_origins`) accept loopback (`localhost`, `127.0.0.1`,
`::1`, `*.localhost`, any port) and normal public `http(s)` origins. They refuse a
non-`http(s)` scheme, a non-loopback private or reserved IP literal (an SSRF-shaped
target) and anything carrying a path or userinfo. They are **never** exposed in the
public config.

Billing has three separate gestures on three screens so creating a site can never
surprise anyone with a charge: **buy** slots in Billing, **create** sites in the
dashboard (always free tier), **assign** a slot in the site's settings. Free tier
`decouverte` is 1 site per account at 5 000 loads/month, Agreely-branded; a paid
`site` slot is CAD $15/month per site (billed annually) at 100 000 loads/month with
your own branding. Load caps are **soft**: exceeding one prompts an upgrade and
never darkens the banner.

## The other public edge endpoints

| Route | Notes |
|---|---|
| `GET /e/v1/logo/{siteKey}` | the company logo, or Agreely's own SVG emblem when the site is free or has no upload. `ACAO: *`, `public, max-age=600`. An unknown key still returns **200** with the emblem, never a broken image |
| `GET /e/v1/public-scan-challenge` | a proof-of-work + honeypot challenge for the marketing "scan your site" tool |
| `POST /e/v1/public-scan`, `GET /e/v1/public-scan/{token}` | submit and poll a public scan |
| `OPTIONS /e/v1/consent`, `OPTIONS /e/v1/public-scan[-challenge]` | preflight backstops |

The public-scan endpoints use a **static landing-origin allowlist**
(`agreely.ca`, `www.agreely.ca`, `vous.agreely.ca`, `testez.agreely.ca`, overridable
via `PUBLIC_SCAN_ALLOWED_ORIGINS`), not the per-tenant origin matcher, and echo only
a matched origin. Live check:

```
GET https://cdn.agreely.ca/e/v1/public-scan-challenge   Origin: https://agreely.ca
200  access-control-allow-origin: https://agreely.ca
{"ok":true,"challenge":{"type":"pow","difficulty":16,"honeypot":"website",
                        "algorithm":"sha256-leading-zero-bits", ...}}
```

Agreely's scanner is **honestly identified**; it never spoofs a user agent.

## Law 25 framing: say this, not that

| Say | Never say |
|---|---|
| Agreely helps you **document and demonstrate** your consent practice | "Agreely makes you compliant / certifies compliance" |
| A proof shows this decision was **recorded and not modified since** | "a verified proof proves you are compliant" |
| The zero-click scan is **verified at a point in time** | "guaranteed zero-click enforcement" |
| The company DID key is **Agreely-hosted defense in depth** | "non-repudiation" |
| The inventory is a **documented starting point** | "an exhaustive certified cookie inventory" |
| Consent is asked **for each purpose** and the record is per-purpose | "one consent covers the site" |
| The **controller** remains accountable for the legal validity of consent | "Agreely takes on the accountability" |
| Anthropic is a **processor outside Quebec** receiving value-free metadata (art. 17) | "no third party is involved" |
| The categorizer **proposes**, a person **decides** | "AI classifies your cookies for you" |

Article hooks the product actually leans on, and what each does here:

| Article (P-39.1) | In this product |
|---|---|
| art. 8 | inform the person at collection: the purposes, per purpose, on layer 1 |
| art. 8 al. 1 (3°) and (4°) | the fixed rights notice: access + rectification, and withdrawal |
| art. 8 al. 2 (3°) | the cross-border communication disclosure |
| **art. 8.1** | technology that identifies, locates or profiles is **inactive by default**, and the person is told of the means to deactivate it. This is the banner's whole reason to exist: defaults off, per-purpose toggles, a named profiling-vendor list |
| art. 8.2 | a confidentiality policy must be published; the policy-link publish gate |
| art. 3.1 | accountability: the human review gate on the registry before anything serves |
| art. 4.1 | minors: the accept-all shortcut is suppressed on a minor-directed site |
| art. 5, art. 9 al. 2 | collection limited to what is necessary; in doubt, deemed unnecessary. Hence no IP by default and the conservative "no banner needed" predicate |
| art. 14 | consent must be manifest, free, informed and **given for each purpose**; refusal is as easy as acceptance (three equal-weight actions) |
| art. 17 | Anthropic, outside Quebec: a **communication**, never a "transfer" |
| art. 29 | the enterprise must enable and publicize the means to exercise rights: the persistent reopen control |

A banner is **not** required for a site with only strictly necessary cookies. The
product says so (`none_needed`) rather than manufacturing an obligation.

## Repos and sources

| Thing | Where |
|---|---|
| The tier (edge + management) | `~/www/agreely`, `app/Controllers/Cookies/` (MODE=COOKIES), `app/Controllers/Temoins/` (MODE=TEMOINS), `app/Models/Cookie/` |
| The browser client | `~/www/agreely-banner` (TypeScript, its own repo, 221 unit tests + Playwright e2e). Re-vendored into the app via `make vendor-banner` into `public/assets/cookies/` |
| The contract of record | `agreely-banner/src/types.ts` |
| Public documentation | `~/www/agreely-site/content/cookie-banner/` (14 pages, French) -> <https://agreely.ca/cookie-banner/> |
| Live edge | `https://cdn.agreely.ca` |
| Live management app | `https://cookies.agreely.ca` |

Current client version **1.3.16**; module SRI
`sha384-tGURIBO0SzS8C0ZyK/bmVebW/8nZx8WIiK3zx3sTeDaa4Orb4VrNbwdH9PvIlVn0`.

Two places where the shipped docs are behind the code, so trust the code:

- `agreely-banner/README.md` still says the server tier "is not built yet" (it is
  live), documents a single `rightsNotice` (the server emits `rightsNotice_fr` and
  `rightsNotice_en`), and quotes a 20 KB banner budget (it is 24 KB, current gzip
  21 082 bytes).
- The landing install page says the loader is "environ 0,7 Ko"; measured on the wire
  it is **2 528 bytes gzipped**. Still tiny, but say the true number.

## Runnable examples

`examples/agreely-cookies/`:

- `install-snippet.html` the canonical annotated install
- `zero-click-check.mjs` headless audit of a live page (loader sync? default-deny?
  what is set at rest? does the guard bite? is it even serving?)
- `edge-probe.sh` read-only `curl` probe of the config plus a deliberately refused
  consent POST that decodes the refusal
- `verify-proof.mjs` + `keccak256.mjs` recompute all three crypto legs of a proof
  yourself, zero dependencies
- `server-side-gate.php` read `agreely_consent` before you emit a `Set-Cookie`
