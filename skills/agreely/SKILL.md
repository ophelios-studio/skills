---
name: agreely
description: Use when integrating Agreely, the Law 25 (Loi 25 / Quebec, P-39.1) consent-accountability layer, via the PHP SDK (composer package `agreely/sdk`) or the CLI (npm `@agreely/cli`, bin `agreely`). Both are thin, typed clients over the live `/v1` consent API (default `https://api.agreely.ca`), with a matching TypeScript SDK (`@agreely/sdk`). Triggers on `use Agreely\Sdk\Agreely`, `new Agreely([...])`, `$agreely->check()/checkDetailed()/checkBatch()/checkFields()`, `$agreely->consentRequests()/manualConsents()/relationships()/catalog()/identity()`, `Agreely::verifyReceipt()`, the typed errors `AgreelyAuthError`/`AgreelyValidationError`/`AgreelyNotFoundError`/`AgreelyRateLimitError`/`AgreelyUnavailableError`/`AgreelyConfigError`/`AgreelyTimeoutError`, the `degradeOnOutage` fail-open policy, the `agreely {check,catalog,whoami,requests,request create|show|cancel|wait,verify,manual-consent create|claim-link|revoke|erase,relationship end|revert,login,config set}` commands, the env vars `AGREELY_API_KEY`/`AGREELY_BASE_URL`/`AGREELY_RPC_URL`/`AGREELY_SILENCE_WARNINGS`, the offline receipt-verifier (DID `did:web`/`did:agreely` resolution, JCS canonicalization, Ed25519 company signature, WebAuthn citizen assertion, IPFS disclosure copy, Base-mainnet on-chain document anchor), the golden `vectors/vectors.json`, or any task framed as "gate this data use on a live consent check", "issue a Law 25 consent request", "record an offline company-attested consent", "end a customer relationship (art. 23)", "verify a consent receipt". Covers the one-call boolean gate (ALLOW is the only true), the fail-closed-by-default outage model plus the two-gate audited fail-open, the never-cache-an-allow rule (spec §16), the protocol `requestId`/`consentRef` (0x + 64 hex, never a uuid) identifiers, offline-first receipt verification with its honest pass/trust matrix (a citizen receipt is at most `partial` offline), the CLI exit-code contract (0 allow, 10 deny, 2 usage, 3 auth, 4 outage, 5 rate-limited, 6 verify-failed), the agent-scriptable `--json` mode with a stderr `{error:{code,message}}` envelope, and the loud honest framing: Agreely RECORDS and verifies consent and produces signed receipts; it does NOT certify that an organization is compliant.
---

# Agreely: PHP SDK + CLI

Agreely is a **Law 25 (Loi 25 / Quebec, P-39.1) consent-accountability layer**.
The SDK and CLI are thin, typed clients over the live `/v1` consent API. The one
thing they do: turn "may I use this customer's data for this purpose right now?"
into a single authoritative call, and let you verify the signed consent receipts
that back it.

**Loud disclaimer up front (honest Law 25 framing):** Agreely **records and
verifies consent and produces signed receipts**. It does **not** certify that
your organization is compliant, does not make anyone "conforme", and is not an
attestation of compliance. Both READMEs say this verbatim: *"Agreely records and
structures consent; it does not certify that your organization is compliant."* A
consent **check** authorizes one (customer, category, purpose) cell at one
instant; it is not legal advice and not a compliance verdict. Do not oversell it.

Live hosts: API `https://api.agreely.ca` (paths under `/v1`), verifier
`https://verify.agreely.ca`, owner app `https://app.agreely.ca`. On-chain
anchoring is **Base mainnet** (chainId 8453). The canonical sources are
`~/www/agreely-sdk-php` (PHP), `~/www/agreely-cli` (CLI), `~/www/agreely-sdk`
(the TypeScript reference).

## Package basics

| | PHP SDK | CLI | TS SDK |
|---|---|---|---|
| Package | `agreely/sdk` (Packagist) | `@agreely/cli` (npm) | `@agreely/sdk` (npm) |
| Install | `composer require agreely/sdk` | `npm i -g @agreely/cli` | `npm i @agreely/sdk` |
| Binary | (none) | `agreely` (`bin` to `dist/bin.js`) | (none) |
| Version | `0.1.0` (`v0.1.0` git tag) | `0.1.1` (`package.json`) | `0.1.0` |
| Runtime | PHP `^8.2 \|\| ^8.3 \|\| ^8.4 \|\| ^8.5`, `ext-curl` + `ext-json` | Node `>= 18` | Node `>= 18` |
| License | MIT | MIT | MIT |
| Entry | `Agreely\Sdk\Agreely` | (none) | `import { Agreely } from "@agreely/sdk"` |

The three clients share `vectors/vectors.json`, the cross-SDK golden vectors, so
PHP, TS, and the wire contract cannot silently drift
(`agreely-sdk-php/README.md:8-11`). The CLI is a thin shell over the TS SDK and
reimplements no HTTP/decision/normalization logic (`agreely-cli/README.md:8-12`).

**Empirically verified for this skill** (PHP 8.5.6, Node 26): the PHP unit suite
is green, `composer test` returns *OK (114 tests, 278 assertions)*; PHPStan level
max on `src/` returns *No errors*; `node dist/bin.js verify <company-vector>
--did-doc <did>` returns `overall: verified`, exit 0; a mutated item category
returns `overall: failed`, exit 6.

## Honest Law 25 framing: say this, not that

| Say | Never say |
|---|---|
| Agreely **records / verifies consent** and produces **signed receipts** | "Agreely certifies compliance" / "makes you conforme" |
| A `check` **authorizes one cell** (customer + category + purpose) now | "Agreely proves you are Law 25 compliant" |
| A receipt `verify` reports **what is proved vs merely trusted** | "a verified receipt = legal compliance" |
| A company-attested receipt proves the company **attested** to a signed PDF | "...proves a human signed" (it does not; see the verifier's own note) |
| `relationship end` is the company **attesting** art. 23 purposes are done | "Agreely decides the relationship is over" |

The `verifyReceipt` result is deliberately honest per-field: a citizen receipt is
at most `overall: "partial"` offline, and it explicitly refuses to bless the
displayed cell labels (`ReceiptVerifier.php:127-130`).

## The consent gate: the one thing to get right

```php
use Agreely\Sdk\Agreely;

$agreely = new Agreely(['apiKey' => getenv('AGREELY_API_KEY')]); // baseUrl optional

// ALLOW is the only true. A 200 deny -> false. Send RAW human labels; the
// server normalizes (never normalize category/purpose yourself).
if ($agreely->check('cust_8812', 'Phone number', 'Billing')) {
    // ...you may use the phone number for billing
}
```

Three invariants the SDK enforces, straight from the source:

1. **ALLOW is the only `true`.** `check()` returns `true` iff the server decision
   is `"allow"`; a 200 `deny` returns `false`, not an error (`Agreely.php:202-210`,
   `CheckResult::isAllow()` at `CheckResult.php:49-53`).
2. **No cache, ever.** The client holds no database, no ref tables, and **no
   allow-cache**: caching an allow while a revoke lands mid-window is a stale-allow
   correctness failure (spec §16). Every `check()` is a fresh authoritative call
   (`Agreely.php:27-37`).
3. **Send labels RAW.** `category`/`purpose` go to the server verbatim; the server
   does case/whitespace/accent/bilingual (FR|EN) normalization. The SDK never
   normalizes (`Agreely.php:195-199`).

### The reasoned form

```php
$d = $agreely->checkDetailed('cust_8812', 'Phone number', 'Billing');
// $d->decision   "allow" | "deny"
// $d->status     "active" | "none" | "revoked" | "expired" | "erased" | "relationship_ended"
// $d->consentRef "0x..." (null when status is "none")
// $d->assurance  "citizen_signed" | "company_attested" | null
// $d->checkedAt  "2026-...Z"
```

`active` allows; **every other status denies**. `relationship_ended` is a
relationship-level stop (art. 23): the per-cell consent stays truthfully active,
it was never withdrawn (`CheckResult.php:14-23`). A 200 deny is returned, never
thrown; only auth/validation/rate-limit/outage throw.

## PHP SDK API reference

All method signatures below are copied from `src/`. Nothing here is invented.

### Client construction (`Agreely.php:58-99`)

```php
new Agreely([
    'apiKey'            => 'agr_live_...',            // REQUIRED (blank/missing -> AgreelyConfigError)
    'baseUrl'           => 'https://api.agreely.ca',  // default; overridable
    'timeout'           => 800,                        // ms, TOTAL budget incl. retries (default 800)
    'httpClient'        => $psr18Adapter,              // optional; default is the bundled CurlHttpClient
    'maxDegradeWindow'  => '24h',                      // cap on any fail-open window (default 24h)
    'maxRetries'        => 0,                           // 429 opt-in retries for idempotent calls
    'respectRetryAfter' => true,                        // honor Retry-After on a 429
    'degradeOnOutage'   => [ /* see Outage behavior */ ],
]);
```

### Top-level methods on `Agreely`

| Method | Endpoint | Returns / notes |
|---|---|---|
| `check(string $customerId, string $category, string $purpose, array $opts = []): bool` | `POST /v1/check` | boolean gate; NEVER throws on an outage (fail-closed `false`). `$opts['onOutage'] => 'allow'\|'deny'`. `Agreely.php:202` |
| `checkDetailed(...): CheckResult` | `POST /v1/check` | the reasoned form; a 200 deny returns, an outage throws `AgreelyUnavailableError` (unless degraded). `Agreely.php:276` |
| `checkBatch(array $items): list<BatchDecision>` | `POST /v1/check/batch` | one round-trip for many cells; decisions ALIGNED to input; fail-closed; throws on outage. Items are `BatchCheckItem` or `['customerRef','category','purpose']`. `Agreely.php:222` |
| `checkFields(array $customerRefs, array $fields): CheckFieldsResult` | `POST /v1/check/batch` | cartesian product to a `isAllowed($ref,$cat,$pur)` lookup. `Agreely.php:256` |
| `identity(): Identity` | `GET /v1/whoami` | least-disclosure: the key's real server-verified `scopes` only (no company id, no PII). `Agreely.php:138` |
| `consentRequests(): ConsentRequests` | (resource) | issuance (scope `issue`) |
| `manualConsents(): ManualConsents` | (resource) | offline company-attested consents (scope `attest`) |
| `relationships(): Relationships` | (resource) | relationship lifecycle (scope `relationship`) |
| `catalog(): Catalog` | (resource) | declared active catalog (scope `check` OR `issue`) |
| `baseUrl(): string` | (local) | the configured endpoint in use |
| `static verifyReceipt(mixed $receipt, array $opts = []): ReceiptVerification` | offline | no API key, no network by default beyond DID resolution. See *Offline receipt verification*. `Agreely.php:165` |
| `static hashPdf(string $bytes): string` | offline | `"0x" + sha256(bytes)`, the exact `evidence.pdfSha256` form. `Agreely.php:174` |
| `static hashPdfFile(string $path): string` | offline | reads then hashes; missing file throws `AgreelyConfigError`. `Agreely.php:180` |

### `consentRequests()`: issuance, scope `issue` (`ConsentRequests.php`)

Keyed on the protocol `requestId` (`0x` + 64 hex), never a uuid.

```php
$r = $agreely->consentRequests()->create([
    'customerId'        => 'cust_8812',
    'recipientEmail'    => 'person@example.com',
    'consentDocumentId' => '<documentVersionId>',  // OR 'documentCode' => 'conditions-marketing' (exactly one)
    'validUntil'        => '2031-01-01',
], ['idempotencyKey' => 'order-4471']);            // optional; makes a retry replay the 201
// IssuedRequest: $r->requestId, $r->status ("pending"), $r->deepLink, $r->emailDelivered, $r->items, $r->document
```

| Method | Endpoint | Notes |
|---|---|---|
| `create(array $input, array $options = []): IssuedRequest` | `POST /v1/consent-requests` | **never auto-retried** (it emails); auto `Idempotency-Key` per call, override via `$options['idempotencyKey']`. Requires exactly one of `consentDocumentId`/`documentCode`; the items derive from the document server-side. `ConsentRequests.php:46` |
| `list(array $input = []): ConsentRequestPage` | `GET /v1/consent-requests` | `$input['status']`, `$input['cursor']`; `$page->items`, `$page->nextCursor`. `ConsentRequests.php:96` |
| `get(string $requestId): ConsentRequestRecord` | `GET /v1/consent-requests/{id}` | `ConsentRequests.php:111` |
| `cancel(string $requestId): CancelledRequest` | `POST /v1/consent-requests/{id}/cancel` | company-side "revoke before action"; idempotent server-side (`$c->cancelled` false on a no-op); 404 on unknown id. `ConsentRequests.php:131` |
| `iterate(array $input = []): Generator` | (paginates `list`) | auto-paginate; bounded by `maxPages` (default 1000). `ConsentRequests.php:152` |
| `collect(array $input = []): array` | (paginates `list`) | `iterate` into an array. `ConsentRequests.php:183` |
| `waitForSettlement(string $requestId, array $opts = []): ConsentRequestRecord` | (polls `get`) | until `approved\|refused\|expired\|revoked_before_action`, else `AgreelyTimeoutError`. `intervalMs` (2000), `timeoutMs` (120000). `ConsentRequests.php:195` |

### `manualConsents()`: offline company-attested, scope `attest` (`ManualConsents.php`)

The company records a consent it gathered out of band (a signed PDF) and attests
under its own name; the enforcement records carry `assurance: "company_attested"`.

```php
$res = $agreely->manualConsents()->record([
    'customerId'        => 'cust_8812',
    'documentVersionId' => '4b08...',
    'effectiveDate'     => '2026-06-01',
    'validUntil'        => '2031-01-01',
    'items'             => ['Email Address:Marketing', '4b082452-...'], // raw pairs and/or catalog ids
    'evidence'          => ['pdfSha256' => Agreely::hashPdfFile('./signed.pdf')], // add 'pdf' => base64 to upload bytes
]);
// ManualConsentResult: $res->consentId, $res->merkleRoot, $res->consentRefs (0x-hex each), $res->assurance, $res->anchored
```

| Method | Endpoint | Notes |
|---|---|---|
| `record(array $input, array $options = []): ManualConsentResult` | `POST /v1/manual-consents` | **not** auto-retried. IDEMPOTENCY CAVEAT: the key is sent but the server does **not yet honor it** here, so a retry can create a DUPLICATE; guard yourself. `ManualConsents.php:37-48` |
| `createClaimLink(array $input): ClaimLink` | `POST /v1/manual-consents/claim-links` | mints a self-claim token for the subject. `ManualConsents.php:92` |
| `revoke(string $consentRef, array $input = []): ManualConsentRevocation` | `POST /v1/manual-consents/{ref}/revoke` | idempotent server-side; optional `reason`. `ManualConsents.php:119` |
| `erase(string $consentRef, array $input = []): ManualConsentErasure` | `POST /v1/manual-consents/{ref}/erase` | idempotent server-side; optional `reason`. `ManualConsents.php:142` |

### `relationships()`: lifecycle, scope `relationship` (`Relationships.php`)

Keyed on the company's **own** `customerRef` (the check ref), never a DID.

```php
$ended = $agreely->relationships()->end([
    'customerRef' => 'cust_8812',
    'reason'      => 'account closed; purposes accomplished', // REQUIRED
]);
// RelationshipEnded: $ended->status ("ended"), $ended->endedAt, $ended->endedBy ("company"|"citizen_request")

$agreely->relationships()->revert(['customerRef' => 'cust_8812', 'reason' => 'offboarded the wrong account']);
```

`reason` is REQUIRED on both and a blank one **fails closed client-side**
(`AgreelyConfigError`) before any wire call (`Relationships.php:43-48`, `80-85`).
`end` is `POST /v1/customers/{ref}/relationship/end`; `revert` is
`.../relationship/revert`. A non-undo-eligible revert is a clean 404 with nothing
written. Ending never revokes, erases, or hides any per-cell consent.

### `catalog()`: discovery, scope `check` OR `issue`

```php
$entries = $agreely->catalog()->list(); // list<CatalogEntry>: id, category, purpose, description
```

`GET /v1/catalog` (`Catalog.php:27`).

### Typed result objects (real shapes)

- **`CheckResult`** `decision, status, consentRef?, checkedAt, degraded, mode?, assurance?` plus `isAllow()`.
- **`BatchDecision`** `customerRef, category, purpose, decision, status, consentRef?, assurance?, checkedAt` plus `isAllow()`.
- **`CheckFieldsResult`** `->isAllowed($ref,$cat,$pur): bool`, `->decisions`.
- **`Identity`** `scopes: list<string>, baseUrl?`.
- **`IssuedRequest`** `requestId, status, deepLink, emailDelivered, items, document?`.
- **`ConsentRequestRecord`** `requestId, status, validUntil, expiresAt, createdAt, settledAt?, items, document?`.
- **`ManualConsentResult`** `consentId, merkleRoot, consentRefs: list<string>, assurance, anchored`.
- **`RelationshipEnded`** `customerRef, status, endedAt, endedBy`.
- **`CancelledRequest`** `requestId, status, cancelled`.

## Offline receipt verification (the headline, no key)

`Agreely::verifyReceipt($receipt, $opts)` is **offline-first** and static, no API
key. It returns a `ReceiptVerification` whose whole design is HONESTY: each field
reports what was **proved** vs merely trusted, and `overall` never overstates it.

```php
$receipt = json_decode(file_get_contents('receipt.json'), true);

// Air-gapped: inject a resolver so there is ZERO network. For untrusted receipts
// you SHOULD inject your own resolver: the default did:web resolver fetches a
// host taken FROM the receipt (HTTPS-only; it can never yield a false "verified").
$didDocs = ['did:web:api.agreely.ca:c:acme' => json_decode(file_get_contents('did.json'), true)];
$v = Agreely::verifyReceipt($receipt, [
    'resolver' => fn (string $did): ?array => $didDocs[$did] ?? null,
]);

$v->overall;          // "verified" | "partial" | "failed" | "unavailable"
$v->companySignature; // "pass" | "fail" | "unavailable" | "skipped" | "unsupported"
$v->citizenAssertion;
$v->disclosureCopy;
$v->documentAnchor;
$v->cellLabelBinding;
$v->notes;            // list<string>, human-readable
```

Result fields and statuses are defined in `ReceiptVerification.php:14-31`.

**Two receipt types, two honest ceilings** (`ReceiptVerifier.php:70-109`):

- **company-attested** (`CompanyAttestedConsentReceipt`) is fully offline-sound:
  an Ed25519 signature over the JCS-canonicalized body. `overall` can reach
  `"verified"`. A verified signature proves the company **attested to a
  hand-signed PDF**; the verifier's own note says it does **not** prove a human
  signed (`ReceiptVerifier.php:184-185`).
- **citizen** is honestly **partial** offline: the company half signed the
  *original offer* (omitted from the receipt for unlinkability), so
  `companySignature` is `"unsupported"` and `overall` is at most `"partial"`. The
  WebAuthn passkey assertion IS checkable. Cell labels are `"unsupported"`: the
  receipt omits the salted commitment + Merkle root, so a mutated label cannot be
  caught offline; use the server `receipts/verify` endpoint
  (`ReceiptVerifier.php:81-85`, `127-130`).

**`unavailable` is NOT `fail`.** When a DID cannot be resolved (or a resolver
throws), the check is `"unavailable"` (inconclusive), never `"fail"` (a real
tamper). A real negative never gets masked by an inconclusive one
(`ReceiptVerifier.php:532-558`).

Opt-in extra network checks via `$opts`: `verifyDisclosure` (fetch + hash the IPFS
disclosure copy; default IPFS gateway `gateway.lighthouse.storage`), and `rpcUrl`
plus optional `chainId`/`registryAddress` for the on-chain document anchor. The
default chain is **Base mainnet 8453**, registry
`0x1E3121CFB5dfE1ac0b0265790D2bdA709725cF8B` (`ReceiptVerifier.php:52-59`); pass
`chainId => 84532` to test against Base Sepolia. The anchor proves the *document
existed*, NOT that any consent was given (`ReceiptVerifier.php:338-339`).

## CLI reference

The `agreely` binary: interactive for humans (a TTY, colors, a wizard),
**scriptable JSON for agents** (a pipe or `--json`). Mode is auto-detected:
`--json` OR a non-TTY stdout means agent mode: no prompts EVER, pure JSON to
stdout, logs/errors to stderr (`context.ts:25-33`, `output.ts:1-3`).

### The agent path: one env var + `--json`

```bash
export AGREELY_API_KEY=agr_live_xxx    # the only setup an agent needs
agreely check cust-42 "Email Address" "Marketing Outreach" --json
# -> {"decision":"allow","status":"active","consentRef":"0x..."}   exit 0
```

Auth precedence (`auth.ts:1-11`): `--api-key` flag > `AGREELY_API_KEY` env > OS
keychain (`keytar`, optional) > `~/.config/agreely/config.json` (`0600`). Base
URL: `--base-url` > `AGREELY_BASE_URL` > stored config > SDK default. Resolution
**never prompts**; a missing key is a `UsageError` (exit 2), never a hang.

### Exit codes: THE agent contract (`errors.ts:14-56`)

| code | meaning |
|---|---|
| `0` | success / check **ALLOW** / receipt `verified` or `partial` |
| `2` | usage or validation (bad/missing args, invalid input, no credentials, 400/422, not-found) |
| `3` | auth: key missing/invalid/revoked or lacks the scope (401/403) |
| `4` | **unavailable**: an Agreely outage, OR a receipt `verify` that could not complete (DID unresolvable) |
| `5` | rate-limited (429) |
| `6` | **verify-failed**: a receipt was checked and did NOT verify (a real tamper), a verdict, not an error |
| `10` | check **DENY**: a clean, expected negative, **not** an error |
| `1` | uncategorized failure |

A DENY's JSON still goes to stdout; a real error keeps stdout clean and writes a
`{"error":{"code","message"}}` envelope to stderr (empirically confirmed:
`agreely check ... --json` with no key returns stderr `{"error":{"code":"usage",...}}`,
exit 2). NOTE: exit `6` is real and central (`verify`) but the CLI README's
exit-code table omits it; trust `errors.ts`.

### Commands (all `--json`-capable; global flags: `--json --api-key --base-url`)

```bash
agreely check <customerId> <category> <purpose> [--json]      # 0 allow, 10 deny, 4 outage
agreely check --batch <file.json> [--json]                    # array of {customerRef,category,purpose}; 0 if ALL allow, 10 if ANY deny
agreely catalog [--json]
agreely whoami [--json]                                       # server-verified: the key's real scopes
agreely requests [--status <s>] [--cursor <id>] [--json]      # cursor pagination
agreely request create --customer <id> --to <email> (--document <versionId> | --document-code <code>) --valid-until <YYYY-MM-DD> [--idempotency-key <k>] [--json]
agreely request show <requestId> [--json]                     # requestId is 0x + 64 hex
agreely request cancel <requestId> [--json]                   # idempotent
agreely request wait <requestId> [--interval <ms>] [--timeout <ms>] [--json]  # exit 4 on timeout
agreely verify <receipt.json> [--ipfs] [--onchain --rpc-url <url>] [--did-doc <file>...] [--json]
agreely manual-consent create --customer <id> --document-version <id> --effective-date <YYYY-MM-DD> --valid-until <YYYY-MM-DD> --item <catalogId|category:purpose>... --pdf <path> [--upload] [--json]
agreely manual-consent claim-link --customer <id> [--reference <ref>] [--json]
agreely manual-consent revoke <consentRef> [--reason <text>] [--json]
agreely manual-consent erase  <consentRef> [--reason <text>] [--json]         # Law 25 art. 28.1
agreely relationship end    <customerRef> --reason <text> [--json]            # art. 23 (idempotent)
agreely relationship revert <customerRef> --reason <text> [--json]            # art. 11 / art. 28 correction
agreely login                                                                 # interactive: store a key in the OS keychain
agreely config set --api-key <k> [--base-url <url>]                           # non-interactive store (for scripts)
```

### `agreely verify`: offline-first, the honesty matrix

```bash
# fully AIR-GAPPED (no network): supply the DID document(s) locally
agreely verify receipt.json --did-doc issuer-did.json --json
# -> {"receiptType":"company_attested","companySignature":"pass",...,"overall":"verified"}   exit 0
```

`--ipfs` opts into fetching + comparing the IPFS disclosure copy; `--onchain`
(needs `--rpc-url` or `AGREELY_RPC_URL`) checks the on-chain anchor. Exit map from
`verify.ts:66-73`: `verified`/`partial` to 0, `failed` to 6, `unavailable` to 4.
Empirically run for this skill against the golden company-attested vector:
`overall: "verified"`, exit 0; after mutating an item category: `overall:
"failed"`, exit 6.

## Integrate the PHP SDK into an existing project (the key walkthrough)

A real, copy-pasteable flow an agent can follow to gate an action on consent and
handle every failure branch. Nothing here is speculative; each error type maps to
a real transport branch (`Transport.php:161-177`).

```bash
composer require agreely/sdk
```

```php
<?php
declare(strict_types=1);

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Errors\AgreelyAuthError;
use Agreely\Sdk\Errors\AgreelyRateLimitError;
use Agreely\Sdk\Errors\AgreelyValidationError;
use Agreely\Sdk\Errors\AgreelyUnavailableError;

// 1) Instantiate once. apiKey is REQUIRED (blank -> AgreelyConfigError at construction).
$agreely = new Agreely([
    'apiKey'  => getenv('AGREELY_API_KEY') ?: '',
    'timeout' => 800,                    // ms, total budget (the default)
    // 'baseUrl' => 'https://api.agreely.ca',   // default; override only for a private stack
]);

// 2) Gate the action on a consent check. ALLOW is the only true. Send RAW labels.
function maySendBillingSms(Agreely $agreely, string $customerRef): bool
{
    try {
        // check() is fail-closed on an outage: it returns false, it does NOT throw.
        return $agreely->check($customerRef, 'Phone number', 'Billing');
    } catch (AgreelyAuthError $e) {
        // 401/403: key missing/invalid/revoked, or lacks the 'check' scope.
        error_log("[agreely] auth: {$e->getMessage()} (code {$e->code})");
        return false; // fail closed
    } catch (AgreelyValidationError $e) {
        // 400/422: bad input. $e->field names the offending input.
        error_log("[agreely] invalid: {$e->getMessage()} field={$e->field}");
        return false;
    } catch (AgreelyRateLimitError $e) {
        // 429: back off $e->retryAfter seconds, then retry your own way.
        error_log("[agreely] rate-limited; retry after {$e->retryAfter}s");
        return false;
    }
    // Note: an OUTAGE never reaches here, check() already returned false.
}

if (maySendBillingSms($agreely, 'cust_8812')) {
    // ...send the SMS
}
```

If you need the *reason* for a deny (to log status/consentRef), use
`checkDetailed()`, but then an outage **throws** `AgreelyUnavailableError`, so
wrap it:

```php
try {
    $d = $agreely->checkDetailed('cust_8812', 'Phone number', 'Billing');
    // $d->decision, $d->status, $d->consentRef, $d->assurance
} catch (AgreelyUnavailableError $e) {
    // Agreely was unreachable. Fail closed (deny) unless you have an explicit,
    // scoped, audited fail-open policy (see below).
}
```

### On "the 402 unpaid / billing-inactive" case: read this

The task brief asked to handle an HTTP **402 `billing_inactive`** state.
**Empirically, there is no 402/`billing_inactive` handling anywhere** in the PHP
SDK, the CLI, or the TS SDK; grepping all three `src/` trees returns nothing. The
transport maps 401/403 to auth, 400/422 to validation, 404 to not-found, 429 to
rate-limit, and **everything else (including 402 and any 5xx) falls into the
`default` branch to `AgreelyUnavailableError`**, with `retryable` true only for
503 (`Transport.php:161-177`). So if the API ever returns 402 for an unpaid
company, this SDK surfaces it as `AgreelyUnavailableError` (status 402,
non-retryable), and `check()` fails **closed** (returns `false`). Do not write a
`catch (...402...)` branch expecting a distinct billing error class; there is not
one. If you need to distinguish it, inspect `$e->status === 402` on the caught
`AgreelyUnavailableError`.

### Verify a receipt you were handed

```php
use Agreely\Sdk\Agreely;

$v = Agreely::verifyReceipt(json_decode($receiptJson, true)); // default HTTPS DID resolver
if ($v->overall === 'verified') {
    // a company-attested receipt, fully offline-sound
} elseif ($v->overall === 'partial') {
    // a citizen receipt: honest, NOT a failure; do not treat as a hard pass
} elseif ($v->overall === 'unavailable') {
    // a DID could not be resolved: INCONCLUSIVE; retry with connectivity or --did-doc
} else { // 'failed'
    // a real tamper / wrong key / bad disclosure
}
```

## Field-gating a PHP app (the integration model)

This is the model to teach an agent wiring Agreely into a CRM (or any app that
renders a person's data). The whole pattern is one sentence: **gate every
personal-data VALUE behind a check, and filter the view-model by the result
before the template ever sees it.** A refused value must never reach the view as
its real content.

### The core loop

```
load record(s) from your DB
     |
     v
one BATCH consent call for all (subject, category, purpose) cells you intend to show
     |
     v
build the VIEW-MODEL: for each personal field, keep the real value ONLY if allowed;
   otherwise -> placeholder ("Refusé par Agreely" / "Refused by Agreely"),
                or OMIT entirely for an optional consent-gated field (e.g. marketing email)
     |
     v
render the template from the filtered view-model (the template never holds the raw value)
```

Two hard rules:

1. **Fail closed.** On ANY error or unavailability, show the placeholder, never
   the ungated value. That includes a hypothetical 402 for an unpaid company:
   there is **no 402 handler today**, so it surfaces as `AgreelyUnavailableError`
   (status 402) and `check()` returns `false` / batch calls throw. Catch the
   throw at the gate and render placeholders. (A dedicated billing error is not
   in the shipping code; do not assume one exists.)
2. **Gate per VALUE, batched per VIEW.** Never issue one `check()` per field per
   row in a loop. Use the batch APIs so a whole table or a whole detail view is
   one round-trip (see below).

### Which SDK methods surface consent STATE (the UI state layer)

The `check` family **is** your read-side state layer. There is **no dedicated
"consent status" or per-client rollup endpoint** in the SDK today (verified
against `src/` and the openapi shape in the types). You derive any client-level
summary yourself from the per-cell decisions. Call this out to the integrator as
a current gap; it is a plausible future addition, not shipping today.

| Method | Use it for | Shape |
|---|---|---|
| `checkFields(array $customerRefs, array $fields): CheckFieldsResult` | a LIST / table: N customers x M fields in ONE call | `$r->isAllowed($ref, $category, $purpose): bool`; `$r->decisions` is the underlying `list<BatchDecision>`. `Agreely.php:256`, `CheckFieldsResult.php:32` |
| `checkBatch(array $items): list<BatchDecision>` | a DETAIL view, or when you need the per-cell REASON, not just a boolean | each `BatchDecision`: `customerRef, category, purpose, decision, status, consentRef?, assurance?, checkedAt` + `isAllow()`. `Agreely.php:222`, `BatchDecision.php` |
| `check()` / `checkDetailed()` | a single field / a one-off | bool / `CheckResult`. `Agreely.php:202`, `:276` |
| `identity()` | startup sanity: does this key carry the `check` scope? | `Identity{scopes}`. `Agreely.php:138` |
| `relationships()->end()/revert()` | offboarding a client (art. 23), not a per-field read | `RelationshipEnded` / `RelationshipReverted`. `Relationships.php` |

**`checkFields` is the method for a listing.** Give it the client IDs and the
fields you want to display; it builds the cartesian product, issues ONE
`POST /v1/check/batch`, and hands back a lookup:

```php
$fields = [
    ['category' => 'Adresse courriel', 'purpose' => 'Infolettre'],   // consent field
    ['category' => 'Nom',              'purpose' => 'Facturation'],   // essential field
];
$matrix = $agreely->checkFields(['cli_1', 'cli_2', 'cli_3'], $fields); // ONE round-trip
$showNewsletter = $matrix->isAllowed('cli_1', 'Adresse courriel', 'Infolettre'); // bool
```

**Surface the reason, not just the boolean.** The per-cell reason lives in
`BatchDecision->status` (there is no separate `reason` field). The status
vocabulary from `CheckResult.php:14-23` lets the UI distinguish the cases:

| `status` | what to tell the user |
|---|---|
| `active` | consent on file, value shows |
| `none` | no consent on file ("aucun consentement au dossier") |
| `revoked` | the person withdrew consent ("consentement retiré") |
| `expired` | the consent lapsed ("consentement expiré") |
| `erased` | the record was erased (art. 28.1) |
| `relationship_ended` | the relationship was closed (art. 23); the per-cell consent was never withdrawn |

Only `active` allows; every other status denies (`isAllow()` is `decision ===
'allow'`). An `assurance` of `citizen_signed` vs `company_attested` tells you how
the record was established.

### Composing a client-level "did not consent" warning

No rollup endpoint exists, so derive it from the batch. Rule: over a client's
**consent-based** cells, if ALL are refused, the client "did not consent"; if
SOME are refused, it is partial; if all allow, fine. Essential cells (which can
never be refused) are excluded from this summary so they do not mask a real
refusal.

```php
/** @param list<BatchDecision> $decisions all cells for ONE client */
function clientConsentSummary(array $decisions, array $consentPurposes): string
{
    // Look only at consent-based cells (by purpose); essential cells are always-allow.
    $consentCells = array_filter(
        $decisions,
        fn ($d) => in_array($d->purpose, $consentPurposes, true),
    );
    if ($consentCells === []) {
        return 'ok'; // nothing consent-gated to summarize
    }
    $refused = array_filter($consentCells, fn ($d) => !$d->isAllow());
    if (count($refused) === count($consentCells)) {
        return 'none';    // FR "ce client n'a pas consenti" / EN "did not consent"
    }
    return $refused === [] ? 'ok' : 'partial'; // partial = some consent fields refused
}
```

Render it as a per-row badge in the listing: `none` -> "Ce client n'a pas
consenti" / "This client did not consent"; `partial` -> "Consentement partiel" /
"Partial consent".

## Law 25 field-classification guide (which fields to gate)

Baked in for the integrating agent. **Honest scope:** this is a practical
starting taxonomy, not legal advice. Agreely **records** the basis the
enterprise **declares in its catalog**; it does not invent or certify it. Have
the enterprise's privacy counsel confirm each field's classification and basis.

### What is personal data (art. 2)

Personal data is information about an **identifiable natural person**. NOT
personal: a company's legal name, its NEQ / registration number, aggregate
counts. But in B2B, a **named human contact's** name, business email, and
business phone **ARE** personal, Quebec's Loi 25 has **no business-card
exception** (unlike some other regimes). Say this honestly to the integrator.

### The essential-vs-consent rule the agent must encode

- **CONSENT field** (marketing email, newsletter): consent is **withdrawable**,
  so a `check` can return **REFUSED** -> do NOT render it (honors art. 9 live).
  For an optional field, omit it entirely rather than showing a placeholder.
- **ESSENTIAL / contract-necessity field** (billing contact name, art. 12 al. 2
  / art. 18): **not withdrawable** -> a `check` returns **ALLOWED** -> it always
  renders. You STILL gate it, so the access is LOGGED (see the box below).
- **Rule:** gate EVERY personal-data field uniformly; leave non-personal fields
  ungated. The value rendering or not is decided by the check result, not by
  whether you called it.

### Practical field taxonomy (from the cookbook scenarios)

| Field (scenario) | Personal? | Typical basis | Gate? | On refusal |
|---|---|---|---|---|
| Client display name, billing contact name (CRM/billing) | yes | contract-necessity / essential | yes (for the log) | placeholder (rarely refused) |
| Business email used for the contract/invoices | yes | contract-necessity / essential | yes | placeholder |
| Marketing / newsletter email (subscriber) | yes | **consent** | yes | OMIT (optional field) |
| Phone number for billing/support | yes | contract-necessity / essential | yes | placeholder |
| Phone number for marketing calls | yes | **consent** | yes | OMIT / placeholder |
| Postal address for shipping (e-shop) | yes | contract-necessity / essential | yes | placeholder |
| Browsing / usage analytics | yes | **consent** | yes | OMIT |
| Contact-form lead message + email | yes | **consent** (they reached out for one purpose) | yes | placeholder / OMIT |
| Employee / HR data | yes | employment relationship / law | yes | placeholder |
| Data about a MINOR | yes (heightened) | consent regime is stricter (art. 4.1) | yes | placeholder; treat sensitively |
| Company legal name, NEQ, registration number | **no** | not personal | no | renders as-is |
| Aggregate counts / anonymized totals | **no** | not personal | no | renders as-is |

The basis column is the enterprise's to declare and its counsel's to confirm;
the table is a default, not a ruling.

### Why gate essential fields too? (the access-log question)

> **Loi 25 has no literal "log every read" rule.** What it requires is
> **accountability / reddition de comptes** (art. 3.1: you must be able to
> *demonstrate* lawful handling), **purpose limitation** (art. 12), **honoring
> consent where consent is the basis** (art. 9), **retention limits** (art. 23),
> and **access / rectification** (art. 27-28). Agreely's `check` + access log is
> the **mechanism** to enforce and prove those: for **consent** fields the check
> is *enforcement* (refused = hidden); for **essential** fields the value can
> never be refused, but the `check` call **records the access**, which is your
> art. 3.1 accountability evidence. So: still call `check` for essential-category
> personal data, the value always renders, but the call is what produces the log.
> **No overclaim:** the access log is how you *demonstrate* accountability, it is
> not a named statutory line-item, and it does not "certify" anything.
> Non-personal data needs no gating at all.

## A concrete PHP recipe: a `ConsentGate` for a CRM

A small helper an agent can drop into a Memento-style CRM. It takes records plus
the personal fields to show (each: subject id + category + purpose + how to
handle a refusal), issues ONE batch call per view, and returns a filtered
view-model. Faithful to the real SDK: it uses `checkFields` for a list and reads
`BatchDecision->status` for reasons. See the runnable
`examples/agreely/integrate-crm-gating.php` (it verifies the filtering logic
offline; the live-call path is source-illustrative and clearly marked).

```php
final class ConsentGate
{
    // Bilingual placeholder for a refused ESSENTIAL field (never the real value).
    public const PLACEHOLDER_FR = 'Refusé par Agreely';
    public const PLACEHOLDER_EN = 'Refused by Agreely';

    public function __construct(
        private readonly \Agreely\Sdk\Agreely $agreely,
        private readonly string $locale = 'fr',
    ) {}

    /**
     * Gate a LIST view. $rows is your own records; $fields declares each personal
     * cell to display. Returns the same rows with refused values replaced/omitted,
     * plus a per-row consent summary badge.
     *
     * @param list<array{id:string, subjectRef:string, values:array<string,string>}> $rows
     * @param list<array{key:string, category:string, purpose:string, basis:'consent'|'essential'}> $fields
     */
    public function gateList(array $rows, array $fields): array
    {
        $refs   = array_values(array_unique(array_map(fn ($r) => $r['subjectRef'], $rows)));
        $cells  = array_map(fn ($f) => ['category' => $f['category'], 'purpose' => $f['purpose']], $fields);

        try {
            $matrix = $this->agreely->checkFields($refs, $cells); // ONE round-trip for the whole table
        } catch (\Agreely\Sdk\Errors\AgreelyError $e) {
            // FAIL CLOSED: hide every gated value across the table.
            return array_map(fn ($row) => $this->blankRow($row, $fields), $rows);
        }

        $consentPurposes = array_map(
            fn ($f) => $f['purpose'],
            array_filter($fields, fn ($f) => $f['basis'] === 'consent'),
        );

        $out = [];
        foreach ($rows as $row) {
            $decisions = [];
            foreach ($fields as $f) {
                $allowed = $matrix->isAllowed($row['subjectRef'], $f['category'], $f['purpose']);
                $row['values'][$f['key']] = $this->present($row['values'][$f['key']] ?? null, $allowed, $f['basis']);
                // Rebuild the row's decisions from the matrix for the summary badge.
                foreach ($matrix->decisions as $d) {
                    if ($d->customerRef === $row['subjectRef']
                        && $d->category === $f['category']
                        && $d->purpose === $f['purpose']) {
                        $decisions[] = $d;
                    }
                }
            }
            $row['consentSummary'] = clientConsentSummary($decisions, $consentPurposes);
            $out[] = $row;
        }
        return $out;
    }

    /** Render one value per its allow result + basis. */
    private function present(?string $value, bool $allowed, string $basis): ?string
    {
        if ($allowed) {
            return $value; // consent active OR essential (always-allow) -> the real value
        }
        // Refused. An optional consent field is OMITTED; an essential field shows the placeholder.
        return $basis === 'consent'
            ? null
            : ($this->locale === 'en' ? self::PLACEHOLDER_EN : self::PLACEHOLDER_FR);
    }

    /** @param array{id:string,subjectRef:string,values:array<string,string>} $row */
    private function blankRow(array $row, array $fields): array
    {
        foreach ($fields as $f) {
            $row['values'][$f['key']] = $f['basis'] === 'consent'
                ? null
                : ($this->locale === 'en' ? self::PLACEHOLDER_EN : self::PLACEHOLDER_FR);
        }
        $row['consentSummary'] = 'none';
        return $row;
    }
}
```

A **detail** view is the same call for one subject (or `checkBatch` when you want
each cell's `status` to drive a richer per-field message):

```php
$decisions = $agreely->checkBatch([
    ['customerRef' => 'cli_1', 'category' => 'Adresse courriel', 'purpose' => 'Infolettre'],
    ['customerRef' => 'cli_1', 'category' => 'Nom',              'purpose' => 'Facturation'],
]);
foreach ($decisions as $d) {
    $label = $d->isAllow()
        ? $realValue[$d->category]
        : match ($d->status) {                       // reason -> human copy
            'revoked' => 'Consentement retiré',       // withdrew
            'expired' => 'Consentement expiré',
            'none'    => 'Aucun consentement au dossier',
            default   => ConsentGate::PLACEHOLDER_FR,
        };
}
```

In the template, render only the filtered view-model, so the raw value is never
in scope:

```latte
{* Latte / any PHP template: the view-model already hid refused values *}
<td>{$row['values']['newsletter_email'] ?? '(non fourni)'}</td>   {* omitted consent field -> fallback *}
<td>{$row['values']['billing_name']}</td>              {* essential -> value OR "Refusé par Agreely" *}
{if $row['consentSummary'] === 'none'}
  <span class="badge">Ce client n'a pas consenti</span>
{elseif $row['consentSummary'] === 'partial'}
  <span class="badge">Consentement partiel</span>
{/if}
```

**Never** pass the raw record to the template and gate inside the view: a missed
branch leaks the value. Filter in the view-model; the template only prints what
survived the gate.

## Outage behavior: fail-closed by default, explicit audited fail-open

When Agreely is unreachable (503 / timeout / network), `check()` **denies**
(`false`) and `checkDetailed()` **throws** `AgreelyUnavailableError`. A real 200
deny is never affected (`Agreely.php:304-323`). You can opt **specific
categories** into fail-open, but only via **two independent gates plus a mandatory
audit sink**:

```php
$agreely = new Agreely([
    'apiKey' => $key,
    'degradeOnOutage' => [
        'mode'            => 'fail-open',                    // the literal required word
        'categories'     => ['Browsing/usage'],             // GATE 1: only these may degrade
        'maxOutageWindow' => '5m',                           // refuse to degrade past this
        'onDegrade'      => fn ($ctx) => $audit->log($ctx),  // MANDATORY: absent, the constructor THROWS
    ],
]);

// GATE 2: the call must ALSO opt in. Effective only because the category is allow-listed.
$agreely->check('cust_8812', 'Browsing/usage', 'Analytics', ['onOutage' => 'allow']);
```

Construction validates it: `mode` must be `"fail-open"`, `onDegrade` is mandatory,
`maxOutageWindow` is mandatory and capped (default 24h; over-cap throws)
(`DegradePolicy.php:44-84`). A per-call `['onOutage' => 'allow']` **not** backed by
an allow-listed category has **no effect** (still denies) and logs a one-time
`error_log` dev warning; silence it with `AGREELY_SILENCE_WARNINGS`
(`DegradePolicy.php:129-157`). **Break-glass (the TS SDK's third gate) is omitted
in PHP v1 by design**: PHP is request-scoped, so an in-process engaged flag would
not survive across workers (`Agreely.php:34-36`, README).

The CLI is fail-closed only; the whole degrade policy is intentionally omitted
from CLI v1 (a one-shot invocation cannot persist/audit a window). Configure
degrade where the SDK is embedded (`agreely-cli/README.md:188-198`).

## Errors + gotchas (each with a source cite)

- **A deny is not an error.** Only auth/validation/not-found/rate-limit/outage
  throw; a 200 `deny` is a normal result. Base class `AgreelyError`; a deny never
  reaches it (`AgreelyError.php:7-16`).
- **`$e->code` is a magic getter.** `AgreelyError` cannot redeclare
  `Exception::$code` (a non-readonly int), so the wire code is exposed via
  `__get('code')` and `errorCode()`. `$e->code` works; `$e->status` and `$e->field`
  (validation) are real readonly props (`AgreelyError.php:52-65`).
- **Error to class map** (`Transport.php:161-177`): 401/403 to `AgreelyAuthError`;
  400/422 to `AgreelyValidationError` (`->field`); 404 to `AgreelyNotFoundError`;
  429 to `AgreelyRateLimitError` (`->retryAfter`); 503/network/timeout **and any
  other status** to `AgreelyUnavailableError` (`retryable` only when 503). **There
  is no billing/402 error class.**
- **`consentRequests()->create` is never auto-retried** (it emails). It attaches a
  unique `Idempotency-Key`; pass your own to make a retry replay the original 201
  instead of double-issuing (`ConsentRequests.php:37-48`).
- **`manualConsents()->record` idempotency is NOT server-honored yet.** The key is
  sent but the server ignores it here, so a retry can create a DUPLICATE
  company-attested consent. Guard against double-submits yourself
  (`ManualConsents.php:32-42`).
- **`relationships()->end`/`revert` require a non-blank `reason`**, enforced
  client-side (`AgreelyConfigError`) before any wire call (`Relationships.php:43-48`,
  `80-85`). In the CLI a missing `--reason` is a clean exit 2 (empirically
  confirmed), never a silent write.
- **Never normalize labels; never cache an allow.** The SDK sends category/purpose
  raw and holds no allow-cache; both are correctness requirements, not
  conveniences (`Agreely.php:27-37`, `195-199`).
- **`whoami` is least-disclosure.** `GET /v1/whoami` returns *scopes only*: no
  company id, no key name, no PII (`Identity.php:7-13`).
- **The tight default timeout is a TOTAL budget** (800ms across up to 2
  idempotent-read retries with jittered backoff), not per-attempt
  (`Transport.php:82-113`). Raise it via `['timeout' => 1200]` if you see spurious
  outages under latency.
- **`verify` `unavailable` is not `failed`.** Treat an unresolvable DID as
  inconclusive (retry / air-gap with local DID docs), never as a forgery
  (`ReceiptVerifier.php:532-558`, `verify.ts:66-73`).
- **A citizen receipt is at most `partial` offline** and its displayed cell labels
  are `unsupported`: do NOT trust them off an offline pass; use the server
  `receipts/verify` (`ReceiptVerifier.php:127-130`).
- **The default `did:web` resolver fetches a host taken FROM the receipt.** For
  UNTRUSTED receipts, inject your own `resolver` (or `--did-doc`) to control the
  request surface. It is HTTPS-only and can never yield a false "verified"
  (`ReceiptVerifier.php:30-33`).
- **CLI `--version` prints `0.1.0`** (the `VERSION` constant in `cli.ts:31`) even
  though `package.json` is `0.1.1`. The bin is authoritative for behavior; the
  number is cosmetic drift.
- **PHPStan: `src/` is clean at level max; two TEST files complain.**
  `test/Unit/CheckBatchTest.php` and `test/Unit/IssuanceVectorTest.php` trip
  offset-on-mixed errors because they read decoded-JSON vectors loosely. The
  shipping library (`src/`) passes; this is a dev-test-only nit, not a library
  defect.
- **PHPStan default 128M OOMs.** Run `vendor/bin/phpstan analyse
  --memory-limit=512M` (the bare `composer stan` crashed at 128M in this env).

## The TypeScript SDK (short: the CLI wraps it)

The CLI is a thin shell over `@agreely/sdk`; if you are in the JS/TS ecosystem,
use it directly: same contract, same golden vectors, one extra gate.

```ts
import { Agreely } from "@agreely/sdk";
const agreely = new Agreely({ apiKey: process.env.AGREELY_API_KEY! });
if (await agreely.check("cust_8812", "Phone number", "Billing")) { /* ... */ }
const v = await Agreely.verifyReceipt(receipt);   // same offline-first verifier
```

Exports (`agreely-sdk/src/index.ts`): `Agreely`, `ReceiptVerifier`, the typed
errors, and, unlike PHP v1, `BreakGlass` (the third degrade gate, viable in a
long-lived Node process). Everything else keeps parity with the PHP SDK. Prefer
PHP for a PHP app; prefer the CLI for a shell/agent; prefer the TS SDK for Node.

## References

- Live API: `https://api.agreely.ca` (paths under `/v1`); Verifier:
  `https://verify.agreely.ca`; Owner app: `https://app.agreely.ca`; Product:
  `https://agreely.ca`
- On-chain: Base **mainnet** (chainId 8453), AgreelyRegistry
  `0x1E3121CFB5dfE1ac0b0265790D2bdA709725cF8B`; Base Sepolia (84532) opt-in.
- PHP SDK source (canonical for the empirical patterns above):
  `~/www/agreely-sdk-php` (`agreely/sdk`, Packagist; repo
  `github.com/agreely-protocol/sdk-php`).
- CLI source: `~/www/agreely-cli` (`@agreely/cli`).
- TS SDK: `~/www/agreely-sdk` (`@agreely/sdk`;
  `https://github.com/agreely-protocol/sdk`), same contract, same golden vectors
  (`vectors/vectors.json`).
- In-repo examples: `examples/agreely/`, a zero-network PHP offline receipt-verify
  (runs against the bundled vector), a source-derived PHP consent-gate, and a CLI
  `--json` agent-scriptable shell example.
