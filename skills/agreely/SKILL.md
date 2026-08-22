---
name: agreely
description: Use when integrating Agreely, the Quebec consent-accountability layer (Loi 25, L.Q. 2021 c. 25, is the amending statute; the obligations live in P-39.1 for private enterprises and A-2.1 for public bodies), via the PHP SDK (composer package `agreely/sdk`) or the CLI (npm `@agreely/cli`, bin `agreely`). Both are thin, typed clients over the live `/v1` consent API (default `https://api.agreely.ca`), with a matching TypeScript SDK (`@agreely/sdk`). Triggers on `use Agreely\Sdk\Agreely`, `new Agreely([...])`, `$agreely->check()/checkDetailed()/checkBatch()/checkFields()`, `$agreely->consentRequests()/manualConsents()/relationships()/catalog()/identity()`, `Agreely::verifyReceipt()`, the typed errors `AgreelyAuthError`/`AgreelyValidationError`/`AgreelyNotFoundError`/`AgreelyRateLimitError`/`AgreelyUnavailableError`/`AgreelyBillingInactiveError`/`AgreelyConfigError`/`AgreelyTimeoutError`/`AgreelyAbortError`, the `degradeOnOutage` fail-open policy, the `agreely {check,catalog,whoami,requests list,request create|show|cancel|wait,verify,manual-consent create|claim-link|revoke|erase,relationship end|revert,login,config set}` commands, the env vars `AGREELY_API_KEY`/`AGREELY_BASE_URL`/`AGREELY_RPC_URL`/`AGREELY_SILENCE_WARNINGS`, the offline receipt-verifier (DID `did:web`/`did:agreely` resolution, JCS canonicalization, Ed25519 company signature, WebAuthn citizen assertion, IPFS disclosure copy, Base-mainnet on-chain document anchor), the golden `vectors/vectors.json`, or any task framed as "gate this data use on a live consent check", "issue a Loi 25 consent request", "record an offline company-attested consent", "end a customer relationship", "verify a consent receipt". Covers the one-call gate (`decision === "allow"` is the only true, and `necessity` is an allow with NO consent record), the eight-value status enum, the fail-closed-by-default outage model plus the two-gate audited fail-open, the never-cache-an-allow rule (spec 16) and the allow-stripping blip-cache pattern, the protocol `requestId`/`consentRef` (0x + 64 hex, never a uuid) identifiers, offline-first receipt verification with its honest pass/trust matrix (a citizen receipt is at most `partial` offline and there is NO server verify endpoint today), the CLI exit-code contract (0 allow, 10 deny, 2 usage, 3 auth, 4 outage, 5 rate-limited, 6 verify-failed, 7 billing-inactive), the agent-scriptable `--json` mode, and the loud honest framing: Agreely DOCUMENTS and verifies consent and produces signed receipts; it does NOT certify that an organization is compliant and gives no legal advice. NOT for the Agreely cookie banner: a "Quebec cookie banner", `cookies.agreely.ca`, the banner loader snippet or `agreely-banner` belong to the separate `agreely-cookies` skill.
---

# Agreely: PHP SDK + CLI

Agreely is a **Quebec consent-accountability layer**. The SDK and CLI are thin,
typed clients over the live `/v1` consent API. The one thing they do: turn "may I
use this customer's data for this purpose right now?" into a single authoritative
call, and let you verify the signed consent receipts that back it.

## Read this first: which law, and which skill

**"Loi 25" is not a statute you can cite an article of.** Loi 25 is *Loi
modernisant des dispositions législatives en matière de protection des
renseignements personnels*, L.Q. 2021, c. 25: an **amending** act. The
obligations it created live in two host statutes:

- **P-39.1**, *Loi sur la protection des renseignements personnels dans le
  secteur privé*, for private enterprises. **Every article number in this guide
  is a P-39.1 number.**
- **A-2.1**, *Loi sur l'accès aux documents des organismes publics et sur la
  protection des renseignements personnels*, for public bodies.

> **SCOPE BANNER, do not skip.** This guide cites the **private-sector act
> (P-39.1)**. Public bodies are governed by **A-2.1**, where **every single
> article number is different**, and several numbers collide with a completely
> unrelated P-39.1 article. Two live traps: P-39.1 **art. 9** is the
> no-service-refusal rule, while A-2.1 **art. 9** is the general right of access
> to public documents; P-39.1 **art. 23** is the destruction duty, while A-2.1
> **art. 23** is about a third party's confidential industrial information. A
> public-body reader who takes a number here at face value lands on the wrong
> law **every time**. Agreely is sector-aware and stores each company's sector;
> when you write copy for a public-body tenant, use the twin table near the end
> of this guide.

Agreely's scope is **P-39.1 + A-2.1 only**. Quebec health-sector law (R-22.1,
"Loi 5") is a separate product and is out of scope here; never fold it in.

**Wrong skill?** If the task is a **cookie banner / consent banner for a
website** (`cookies.agreely.ca`, the `agreely-banner` loader snippet, tracker
scanning, per-domain banner billing), that is the separate **`agreely-cookies`**
skill, not this one. This skill is the **application-side** consent API: gating
your own app's use of a person's data.

**Loud disclaimer up front (honest framing):** Agreely **documents and verifies
consent and produces signed receipts**. It does **not** certify that your
organization is compliant, does not make anyone "conforme", and is not an
attestation of compliance. Both READMEs say this verbatim: *"Agreely records and
structures consent; it does not certify that your organization is compliant."* A
consent **check** authorizes one (customer, category, purpose) cell at one
instant; it is not legal advice and not a compliance verdict. Do not oversell it.

Live hosts: API `https://api.agreely.ca` (paths under `/v1`), verifier
`https://verify.agreely.ca`, owner app `https://app.agreely.ca`. On-chain
anchoring is **Base mainnet** (chainId 8453). The canonical sources are
`~/www/agreely-sdk-php` (PHP), `~/www/agreely-cli` (CLI), `~/www/agreely-sdk`
(the TypeScript reference). **The wire contract of record is the app's
`openapi.yaml`**; where an SDK's local type list disagrees with it, the
openapi wins (both SDKs have drifted, see *Known SDK drift* below).

## Package basics

| | PHP SDK | CLI | TS SDK |
|---|---|---|---|
| Package | `agreely/sdk` (Packagist) | `@agreely/cli` (npm) | `@agreely/sdk` (npm) |
| Install | `composer require agreely/sdk` | `npm i -g @agreely/cli` | `npm i @agreely/sdk` |
| Binary | (none) | `agreely` (`bin` to `dist/bin.js`) | (none) |
| Version | `0.2.0` (Packagist) | `0.2.0` (`package.json`) | `0.2.0` |
| Runtime | PHP `^8.2 \|\| ^8.3 \|\| ^8.4 \|\| ^8.5`, `ext-curl` + `ext-json` | Node `>= 18` | Node `>= 18` |
| License | MIT | MIT | MIT |
| Entry | `Agreely\Sdk\Agreely` | (none) | `import { Agreely } from "@agreely/sdk"` |

The three clients share `vectors/vectors.json`, the cross-SDK golden vectors, so
PHP, TS, and the wire contract cannot silently drift on the crypto
(`agreely-sdk-php/README.md:8-11`). The CLI is a thin shell over the TS SDK and
reimplements no HTTP/decision/normalization logic (`agreely-cli/README.md:8-12`).

**Empirically verified for this skill** (PHP 8.5.6, Node 26): the PHP unit suite
is green, `composer test` returns *OK (122 tests, 294 assertions)*; PHPStan level
max on `src/` returns *No errors* at the default 128M memory limit; `node
dist/bin.js verify <company-vector> --did-doc <did>` returns `overall: verified`,
exit 0; a mutated item category returns `overall: failed`, exit 6.

## Honest framing: say this, not that

| Say | Never say |
|---|---|
| Agreely **documents / verifies consent** and produces **signed receipts** | "Agreely certifies compliance" / "makes you conforme" |
| A `check` **authorizes one cell** (customer + category + purpose) now | "Agreely proves you are compliant" |
| A receipt `verify` reports **what is proved vs merely trusted** | "a verified receipt = legal compliance" |
| A company-attested receipt proves the company **attested** to a signed PDF | "...proves a human signed" (it does not; see the verifier's own note) |
| `relationship end` is the company **attesting** that the art. 23 purposes are accomplished | "Agreely decides the relationship is over" |
| A `necessity` allow rests on the basis the company **declared** in its catalog | "Agreely validated that the basis is lawful" |

The `verifyReceipt` result is deliberately honest per-field: a citizen receipt is
at most `overall: "partial"` offline, and it explicitly refuses to bless the
displayed cell labels (`ReceiptVerifier.php:127-130`).

## The consent gate: the one thing to get right

```php
use Agreely\Sdk\Agreely;

$agreely = new Agreely(['apiKey' => getenv('AGREELY_API_KEY')]); // baseUrl optional

// decision === "allow" is the only true. A 200 deny -> false. Send RAW human
// labels; the server normalizes (never normalize category/purpose yourself).
if ($agreely->check('cust_8812', 'Phone number', 'Billing')) {
    // ...you may use the phone number for billing
}
```

Three invariants the SDK enforces, straight from the source:

1. **Only `decision === 'allow'` allows.** `check()` returns `true` iff the server
   decision is `"allow"`; a 200 `deny` returns `false`, not an error
   (`Agreely.php:203-211`, `CheckResult::isAllow()` at `CheckResult.php:50-53`).
   **Never gate on `status`.** Two different statuses allow, and one of them is
   not a consent record at all.
2. **No cache, ever.** The client holds no database, no ref tables, and **no
   allow-cache**: caching an allow while a revoke lands mid-window is a
   stale-allow correctness failure (spec 16). Every `check()` is a fresh
   authoritative call (`Agreely.php:27-37`). See *The blip-cache pattern* for
   what to do about real-world latency without breaking this.
3. **Send labels RAW.** `category`/`purpose` go to the server verbatim; the server
   does case/whitespace/accent/bilingual (FR|EN) normalization. The SDK never
   normalizes (`Agreely.php:195-199`). Verified live: `{"category":"coordonnees",
   "purpose":"GESTION DU DOSSIER CLIENT"}` resolves the same cell as
   `Coordonnées / Gestion du dossier client`.

### The reasoned form

```php
$d = $agreely->checkDetailed('cust_8812', 'Phone number', 'Billing');
// $d->decision   "allow" | "deny"
// $d->status     one of the EIGHT values below
// $d->consentRef "0x..." (null for "none" AND for "necessity")
// $d->assurance  "citizen_signed" | "company_attested" | null
// $d->checkedAt  "2026-...Z"
// $d->degraded   true ONLY on a locally synthesised fail-open (see the warning)
```

### The status enum: EIGHT values, TWO of which allow

Read from `openapi.yaml` (`CheckDecision.status`) and confirmed live against
`POST /v1/check`.

| `status` | `decision` | what it actually means |
|---|---|---|
| `active` | **allow** | a per-subject consent record exists and is current |
| `necessity` | **allow** | **no consent record exists and none is needed.** The active catalog cell declares a non-consent lawful basis. Carries `basis`, carries **no** `consentRef` and **no** `assurance`, and **creates nothing** |
| `none` | deny | no record, on a **consent-basis** cell. Also what you get when the (category, purpose) you sent matches **no active catalog cell at all** |
| `revoked` | deny | the person withdrew consent (art. 8 al. 1 (4°)) |
| `expired` | deny | the consent lapsed |
| `erased` | deny | the record was erased. **Currently unreachable through `/v1/check`**: see the note below |
| `relationship_ended` | deny | the company attested the relationship is over (art. 23). The per-cell consent stays truthfully active, it was never withdrawn |
| `sensitive_requires_consent` | deny | a cell whose declared basis is **non-consent** but which is flagged sensitive, with no express-consent record: it fails closed rather than riding a necessity basis (art. 12 al. 1 in fine, art. 13 al. 2). Nothing is created. Note a **consent**-basis sensitive cell reads plain `none`, because the consent branch is evaluated first |

Live evidence, one API key, one unknown customer reference, one catalog:

```
POST /v1/check  Coordonnées / Gestion du dossier client   (necessary_for_service)
  {"decision":"allow","status":"necessity","basis":"necessary_for_service","checkedAt":"..."}
POST /v1/check  Renseignements financiers / Prévention de la fraude  (security_fraud)
  {"decision":"allow","status":"necessity","basis":"security_fraud","checkedAt":"..."}
POST /v1/check  Renseignements financiers / Conservation des registres fiscaux (legal_obligation)
  {"decision":"allow","status":"necessity","basis":"legal_obligation","checkedAt":"..."}
POST /v1/check  Coordonnées / Communications marketing    (consent)
  {"decision":"deny","status":"none","checkedAt":"..."}
POST /v1/check  <sensitive cell on a necessity basis>
  {"decision":"deny","status":"sensitive_requires_consent","checkedAt":"..."}
POST /v1/check  Totally Undeclared / Nothing              (no active cell)
  {"decision":"deny","status":"none","checkedAt":"..."}
```

> **DANGER: `degraded === true` reports `status: "active"` and it is NOT
> consent.** When you configure `degradeOnOutage` fail-open and an outage
> triggers it, the PHP SDK **synthesises** a result locally:
> `decision: "allow"`, `status: "active"`, `consentRef: null`, `degraded: true`,
> `mode: "fail-open"` (`Agreely.php:319-326`). Nothing was checked. Anyone
> branching on `status === 'active'` silently converts an outage into a consent
> record in their logs and their UI. Branch on `decision`, and if you log the
> status, log `degraded` beside it.

### `basis`: the declared non-consent ground behind a `necessity` allow

Present **only** when `status === 'necessity'`. Enum (`openapi.yaml`):

| `basis` | means | P-39.1 anchor |
|---|---|---|
| `contract` | the information is necessary to conclude or perform the contract | art. 9 al. 1 (1°) for the collection limb; the secondary-**use** limb is art. 12 al. 2 (4°) |
| `necessary_for_service` | necessary to supply/deliver a product or perform a service the person asked for | art. 12 al. 2 (4°) |
| `security_fraud` | fraud prevention and detection, or evaluating and improving security measures | art. 12 al. 2 (3°) |
| `legal_obligation` | the company declares the handling is required by a law applicable in Quebec | **P-39.1 has no general "legal obligation" basis for USE.** The nearest express anchors are art. 9 al. 1 (2°) (« la collecte est autorisée par la loi »), art. 18 al. 1 (4°) for a communication necessary under a law, and the « délai de conservation prévu par une loi » reserve in art. 23. Treat this one as counsel-verified, always |
| `professional_contact` | information concerning the person's exercise of a function within an enterprise | art. 1, dernier alinéa. **This is a scope carve-out, not a necessity ground.** See *Professional contact* below |

Agreely records the basis the enterprise **declared in its catalog**. It does not
invent it, does not validate it, and does not certify it is correct. Have the
enterprise's privacy counsel confirm each classification.

> **`erased` is currently unreachable via `/v1/check`.** The erase path
> crypto-shreds the cell and **deletes the enforcement record**
> (`app/Models/Consent/Services/ManualConsentService.php:490`, `$enforcement->deleteByRef(...)` with the
> comment "default-deny IMMEDIATELY"). With no record, the next check falls to
> the no-record branch and reads back `deny`/`none` on a consent-basis cell (or,
> if the catalog cell carries a non-consent basis, `allow`/`necessity`). The
> value stays in the openapi enum and does surface on other operator surfaces
> such as the access log. Do not build UI copy that expects to see it at the
> gate.

## What you provision, and what you must NOT (read before writing anything)

This is the section integrators get wrong, and getting it wrong **manufactures a
false consent record**.

There is **no "provision every client into Agreely first" step.** What exists is
a per-cell rule driven by the catalog cell's declared `legal_basis`:

| catalog cell basis | what a check returns with NO record | what you write |
|---|---|---|
| `consent` | `deny` / `none` | issue `consentRequests()->create()`, the citizen signs. Or, if you genuinely hold a signed paper consent, `manualConsents()->record()` |
| `contract`, `necessary_for_service`, `security_fraud`, `legal_obligation`, `professional_contact` | **`allow` / `necessity`** + `basis` | **nothing. Write nothing. Ever.** |
| a **non-consent** basis, flagged `is_sensitive` | `deny` / `sensitive_requires_consent` | express consent only, through a consent document |
| `consent`, flagged `is_sensitive` | `deny` / `none` (the consent branch is evaluated first) | express consent only, through a consent document |

A necessity cell is already answered correctly for a customer Agreely has never
heard of. The gate still **asks** Agreely, because that is what produces the
access-log entry, but the answer needs no stored artifact.

> **NEVER call `manualConsents()->record()` on a non-consent cell.** It is not a
> "provisioning" call, it is a sworn statement that a human signed a paper
> consent document whose SHA-256 you supply. Run on a `necessary_for_service`
> cell it turns an honest, self-explaining allow into a fabricated one. Measured
> live, on one real cell:
>
> ```
> BEFORE  decision=allow  status=necessity  consentRef=null        assurance=NULL
> AFTER   decision=allow  status=active     consentRef=0x108bac...  assurance='company_attested'
> ```
>
> The `after` row asserts a signed PDF that does not exist, attaches the
> company's name to that assertion, and puts a `company_attested` artifact into
> the evidence chain and any receipt derived from it. It also **loses** the
> honest `basis`, so nothing downstream can tell you why the cell was allowed.
> `manualConsents()->record()` is for one thing only: a consent you actually
> collected offline, on paper you actually hold.

**The deployed reference does exactly this and never writes.** The production
Assurance Boréale demo (`~/www/agreely-boreale-demo/app/Models/Boreale/`) gates a
whole CRM against the live API. Its only write path is
`consentRequests()->create()`; it calls `manualConsents()->record()` **nowhere**.
Its `ConsentField` docblock states the model in one line:

> `necessary_for_service (art. 12 al. 2 (4)) -> auto-allow, always renders. The
> gate still ASKS Agreely; the API returns allow/necessity for a client with no
> record.`

### "Why is this cell denied?" (the real decision tree)

```
deny / none
  |- did you send a (category, purpose) that matches an ACTIVE catalog cell?
  |     No  -> this is a CONFIG error, not a consent state. Call catalog()->list()
  |            and compare. An undeclared or ambiguous label fails closed.
  |     Yes -> the cell's basis is `consent` and there is no record.
  |            Issue a consent request. Do NOT attest it.
deny / sensitive_requires_consent
  -> the company flagged this cell sensitive AND declared a non-consent basis for
     it, so it refuses to ride that basis. Only EXPRESS consent through a consent
     document opens it (art. 12 al. 1 in fine, art. 13 al. 2). A CONSENT-basis
     sensitive cell reads plain `none` instead: same remedy, different status.
deny / revoked | expired
  -> a real, recorded negative. Respect it. Re-consent is a new request, not a write.
deny / relationship_ended
  -> the company attested art. 23. Even necessity cells stop authorizing.
     `relationships()->revert()` undoes a mistaken end (art. 11 al. 1).
allow / necessity
  -> normal, expected, nothing to provision. Show the value; the check logged it.
```

The old advice "a brand-new client reads deny for EVERY field, so provision
first" was simply false: on a real catalog, a brand-new client reads
`allow`/`necessity` on every non-consent cell and `deny`/`none` only on the
consent cells, which is the correct and intended shape.

### Professional contact: the carve-out the API models, and its real edges

P-39.1 **art. 1, dernier alinéa**:

> « Les sections II et III de la présente loi ne s'appliquent pas à un
> renseignement personnel qui a un caractère public en vertu de la Loi. Elles ne
> s'appliquent pas non plus aux renseignements personnels qui concernent
> l'exercice par la personne concernée d'une fonction au sein d'une entreprise,
> tels que son nom, et sa fonction, de même que l'adresse, l'adresse de courrier
> électronique et le numéro de téléphone de son lieu de travail. »

**There IS a professional-contact carve-out in Quebec private-sector law.** Any
guidance claiming otherwise is wrong. What it does and does not do:

- **Disapplied:** Section II (collection, arts. 4 to 9.1) and Section III
  (confidentiality, consent, security, retention, destruction, arts. 10 to 26).
  So: **no consent to collect, no consent to use, no withdrawal, no retention
  schedule, no destruction duty under art. 23.**
- **Still applies:** **art. 2** (it is still *un renseignement personnel*, so a
  named human contact's name, business email and business phone **are** personal
  information); **Section I.1** (responsable art. 3.1, policies art. 3.2, EFVP
  art. 3.3, and the entire confidentiality-incident regime arts. 3.5 to 3.8);
  and **Section IV** (access art. 27, rectification art. 28, the 30-day clock
  art. 32, de-indexation art. 28.1).
- **The boundary is the head of the clause, not the field name.** It covers
  information *concerning the exercise of a function within an enterprise*. The
  same work email used to **profile the individual personally**, the **contents**
  of communications, HR evaluations, or a personal cell number are outside it.
  Where the line falls in a given system is a question for counsel, not for a
  field-name allow-list.

**What this means for the integration.** Agreely still gates these cells, so the
access is logged and the vocabulary stays uniform across the app. But they are
**non-revocable, carry no consent artifact, and need no retention schedule**, and
you must **never issue a consent request for them**. A `professional_contact`
cell reads `allow` / `necessity` / `basis: "professional_contact"` forever.

Verified live on a `professional_contact` catalog cell:
`{"decision":"allow","status":"necessity","basis":"professional_contact","checkedAt":"..."}`.

Honest caveat: `professional_contact` shows up in the `basis` enum because it
also produces a necessity allow, but legally it is a **scope carve-out from
Sections II and III**, not a necessity ground under art. 12 or art. 9. The
`openapi.yaml` description says exactly this; keep the distinction in any copy
you write.

**A-2.1 differs materially, and in two limbs.** Do not reuse the P-39.1 sentence
for a public body:
- **art. 55, deuxième phrase**: the same function-within-an-enterprise carve-out,
  but it disapplies the **whole of Chapitre III, including the Section IV
  rights**. And **art. 55 al. 2** lets the body refuse access to such a file, in
  whole or in part, or allow on-site consultation only, where the responsable has
  reasonable grounds to believe the information will be used « à des fins
  illégitimes ».
- **art. 57**: an enumerated list of public-body personnel information that has
  **caractère public** outright. Note **art. 57, dernier alinéa**: the
  paragraph 2° items must not have the effect of revealing an individual staff
  member's actual *traitement* (salary); only the classification and the salary
  scale attached to it are public.

## PHP SDK API reference

All method signatures below are copied from `src/`. Nothing here is invented.
Line cites re-verified mechanically against `~/www/agreely-sdk-php` on
2026-08-22.

### Client construction (`Agreely.php:59-100`)

```php
new Agreely([
    'apiKey'            => 'agr_live_...',            // REQUIRED (blank/missing -> AgreelyConfigError)
    'baseUrl'           => 'https://api.agreely.ca',  // default; overridable
    'timeout'           => 8000,                       // ms, TOTAL budget incl. retries (SDK default 800)
    'maxRetries'        => 1,                          // 429/503 opt-in retries for idempotent calls
    'httpClient'        => $psr18Adapter,              // optional; default is the bundled CurlHttpClient
    'maxDegradeWindow'  => '24h',                      // cap on any fail-open window (default 24h)
    'respectRetryAfter' => true,                       // honor Retry-After on a 429
    'degradeOnOutage'   => [ /* see Outage behavior */ ],
]);
```

> **Raise the timeout. The 800ms default is not survivable over the open
> internet.** The SDK default is a tight 800ms TOTAL budget across up to two
> retries (`Transport.php:82-113`), which is fine inside a datacentre and
> produces spurious fail-closed denies from a laptop, a CI runner, or any app
> calling `api.agreely.ca` across the public internet, especially on the first
> (cold-connection) request or a multi-cell batch. The deployed reference
> integration uses **`'timeout' => 8000, 'maxRetries' => 1`** with the comment
> that one retry smooths a transient blip and that it still fails closed if it
> does not. Start there and tune down with real numbers, not up from a guess.
> A denied render because of a 900ms round-trip is not "secure", it is a bug that
> looks like a refusal.

### Top-level methods on `Agreely`

| Method | Endpoint | Returns / notes |
|---|---|---|
| `check(string $customerId, string $category, string $purpose, array $opts = []): bool` | `POST /v1/check` | boolean gate; NEVER throws on an outage (fail-closed `false`). `$opts['onOutage'] => 'allow'\|'deny'`. `Agreely.php:203` |
| `checkDetailed(...): CheckResult` | `POST /v1/check` | the reasoned form; a 200 deny returns, an outage throws `AgreelyUnavailableError` (unless degraded). `Agreely.php:280` |
| `checkBatch(array $items): list<BatchDecision>` | `POST /v1/check/batch` | one round-trip for many cells; decisions ALIGNED to input; fail-closed; throws on outage. Items are `BatchCheckItem` or `['customerRef','category','purpose']`. **Server cap: 500 items.** `Agreely.php:226` |
| `checkFields(array $customerRefs, array $fields): CheckFieldsResult` | `POST /v1/check/batch` | cartesian product to an `isAllowed($ref,$cat,$pur)` lookup. **Watch the 500 cap**, see below. `Agreely.php:260` |
| `identity(): Identity` | `GET /v1/whoami` | least-disclosure: the key's real server-verified `scopes` only (no company id, no PII). `Agreely.php:139` |
| `consentRequests(): ConsentRequests` | (resource) | issuance (scope `issue`) |
| `manualConsents(): ManualConsents` | (resource) | offline company-attested consents (scope `attest`) |
| `relationships(): Relationships` | (resource) | relationship lifecycle (scope `relationship`) |
| `catalog(): Catalog` | (resource) | declared active catalog (scope `check` OR `issue`) |
| `baseUrl(): string` | (local) | the configured endpoint in use |
| `static verifyReceipt(mixed $receipt, array $opts = []): ReceiptVerification` | offline | no API key, no network by default beyond DID resolution. `Agreely.php:166` |
| `static hashPdf(string $bytes): string` | offline | `"0x" + sha256(bytes)`, the exact `evidence.pdfSha256` form. `Agreely.php:175` |
| `static hashPdfFile(string $path): string` | offline | reads then hashes; missing file throws `AgreelyConfigError`. `Agreely.php:181` |

### The `/v1` surface: 14 routes, no more

```
POST   /v1/check                                       scope check
POST   /v1/check/batch                                 scope check      (500-item cap)
GET    /v1/catalog                                     scope check OR issue
GET    /v1/whoami                                      any key
POST   /v1/consent-requests                            scope issue
GET    /v1/consent-requests                            scope issue
GET    /v1/consent-requests/{id}                       scope issue
POST   /v1/consent-requests/{id}/cancel                scope issue
POST   /v1/manual-consents                             scope attest
POST   /v1/manual-consents/claim-links                 scope attest
POST   /v1/manual-consents/{consentRef}/revoke         scope attest
POST   /v1/manual-consents/{consentRef}/erase          scope attest
POST   /v1/customers/{customerRef}/relationship/end    scope relationship
POST   /v1/customers/{customerRef}/relationship/revert scope relationship
```

There is **no** per-client rollup route, **no** receipts namespace, and **no**
server receipt-verify route. If you need something that is not on this list, it
does not exist yet; say so rather than inventing a call.

### Server limits you will hit

- **Batch cap: 500 resolved items per call.** Over it, the API returns HTTP 422
  `{"error":{"code":"invalid_request","message":"Batch exceeds the 500-item cap.
  Split into smaller batches.","field":"items"}}`, which the SDK surfaces as
  `AgreelyValidationError` with `->field === 'items'`. An empty batch is also a
  422. Confirmed live with a 501-item body.
- **`checkFields` builds a CARTESIAN PRODUCT** (`refs x fields`), so it reaches
  the cap far sooner than it looks: **100 rows x 6 fields = 600 items** and the
  whole page fails closed. In the **published 0.2.0** there is no client-side
  guard, so the first sign is a 422 on a page that used to work. Chunk the refs
  (`array_chunk($refs, (int) floor(500 / count($fields)))`) and merge the
  decisions, or paginate the listing to a page size that keeps
  `refs * fields <= 500`.
- **Rate limit: 120 requests per minute per company**, a coarse fixed window
  (`API_RATE_LIMIT_PER_MINUTE`, default 120). Over it: HTTP 429 with a
  `Retry-After` header, surfaced as `AgreelyRateLimitError` with `->retryAfter`.
  One batch call counts as **one** request, which is the main reason to batch a
  listing rather than loop `check()`.

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
| `list(array $input = []): ConsentRequestPage` | `GET /v1/consent-requests` | all filters optional: `$input['customerId']` (the company's own subject ref), `$input['status']`, `$input['limit']` (default 50, max 100), `$input['cursor']`. `$page->items`, `$page->nextCursor`. Metadata only, newest first, tenant-scoped by the key. `ConsentRequests.php:99` |
| `hasPending(string $customerId, ?string $documentCode = null): bool` | (paginates `list`) | dedup helper: is a still-`pending` request already outstanding for this customer (optionally for a given `documentCode`)? Call it **before** `create` to avoid re-issuing (a second email). Blank `$customerId` throws `AgreelyConfigError`. Metadata convenience, **not** a consent decision. **Needs `agreely/sdk` `^0.2`**: `hasPending` and the `customerId` filter do not exist in 0.1.x. `ConsentRequests.php:127` |
| `get(string $requestId): ConsentRequestRecord` | `GET /v1/consent-requests/{id}` | `ConsentRequests.php:143` |
| `cancel(string $requestId): CancelledRequest` | `POST /v1/consent-requests/{id}/cancel` | company-side "revoke before action"; idempotent server-side (`$c->cancelled` false on a no-op); 404 on unknown id. `ConsentRequests.php:163` |
| `iterate(array $input = []): Generator` | (paginates `list`) | auto-paginate; bounded by `maxPages` (default 1000). `ConsentRequests.php:184` |
| `collect(array $input = []): array` | (paginates `list`) | `iterate` into an array. `ConsentRequests.php:221` |
| `waitForSettlement(string $requestId, array $opts = []): ConsentRequestRecord` | (polls `get`) | until `approved\|refused\|expired\|revoked_before_action`, else `AgreelyTimeoutError`. `intervalMs` (2000), `timeoutMs` (120000). `ConsentRequests.php:233` |

#### Looking up requests by `customerId` (list + dedup)

`list` takes a `customerId` filter (the company's own subject ref, the SAME ref
used by `check`), plus `status`, `limit` (default 50, max 100), and a keyset
`cursor` (newest-first). The page is **metadata only** and tenant-scoped by the
API key; each `ConsentRequestRecord` carries `->customerId` and `->documentCode`.

```php
// One page of a customer's pending requests (newest first).
$page = $agreely->consentRequests()->list([
    'customerId' => 'cli_1',
    'status'     => 'pending',
    'limit'      => 100,
]);
foreach ($page->items as $r) {
    // $r->requestId, $r->status, $r->customerId, $r->documentCode, $r->createdAt, $r->validUntil
}
// $page->nextCursor: pass it back as ['cursor' => $page->nextCursor] for the next page,
// or use ->iterate(['customerId' => 'cli_1', 'status' => 'pending']) to auto-paginate.

// The dedup shortcut: DON'T re-issue if a pending one already exists.
if ($agreely->consentRequests()->hasPending('cli_1', 'infolettre')) {
    // a pending 'infolettre' request is already outstanding, skip create()
}
```

**Honest framing:** a request lookup returns *metadata* about what was issued
(requestId, status, customerId, documentCode, timestamps). It is **not** a
consent decision and not a compliance claim: `hasPending` reporting `true` means
an email/offer is outstanding, not that consent was granted. To know whether a
cell is actually consented, use `check` / `checkDetailed` (a `pending` request is
`status: none` at the gate until the citizen signs).

### `manualConsents()`: offline company-attested, scope `attest` (`ManualConsents.php`)

**Use this only for a consent you genuinely collected offline on paper you hold.**
It is not a provisioning primitive; see the danger box above. The company records
the consent and attests under its own name; the enforcement records carry
`assurance: "company_attested"`.

```php
$res = $agreely->manualConsents()->record([
    'customerId'        => 'cust_8812',
    'documentVersionId' => '4b08...',              // a PUBLISHED consent-document version
    'effectiveDate'     => '2026-06-01',
    'validUntil'        => '2031-01-01',
    'items'             => [
        '4b082452-0000-0000-0000-000000000000',    // a catalog-entry UUID, OR
        ['category' => 'Adresse courriel', 'purpose' => 'Infolettre'], // a {category, purpose} object
    ],
    'evidence'          => ['pdfSha256' => Agreely::hashPdfFile('./signed.pdf')], // add 'pdf' => base64 to upload bytes
]);
// ManualConsentResult: $res->consentId, $res->merkleRoot, $res->consentRefs (0x-hex each), $res->assurance, $res->anchored
```

> **`items` has exactly TWO accepted forms**, both of which must resolve to an
> **active cell of the chosen document's grid** (`app/Models/Consent/Services/ManualConsentService.php:663-676`,
> `openapi.yaml` `ManualConsentRequest.items`):
> 1. a **catalog-entry UUID string**, or
> 2. a **`{category, purpose}` object** (labels sent raw, server-normalized).
>
> A `"Category:Purpose"` **colon string is NOT valid** and never was. Confirmed
> live: it returns HTTP 422
> `{"error":{"code":"invalid_request","message":"One or more items are not active
> cells of the chosen document."}}`, surfaced as `AgreelyValidationError`.
> The **CLI**'s `--item "category:purpose"` flag is a different thing: it is a
> CLI-side shorthand that `parseItem` splits on the first colon and converts into
> a `{category, purpose}` object before it ever reaches the wire
> (`agreely-cli/src/create-input.ts:30-43`). Do not carry that syntax into an
> SDK `items` array.

| Method | Endpoint | Notes |
|---|---|---|
| `record(array $input, array $options = []): ManualConsentResult` | `POST /v1/manual-consents` | not auto-retried. **`Idempotency-Key` IS server-honoured**, see below. `ManualConsents.php:49` |
| `createClaimLink(array $input): ClaimLink` | `POST /v1/manual-consents/claim-links` | mints a self-claim token for the subject. `ManualConsents.php:92` |
| `revoke(string $consentRef, array $input = []): ManualConsentRevocation` | `POST /v1/manual-consents/{ref}/revoke` | idempotent server-side; optional `reason`. `ManualConsents.php:119` |
| `erase(string $consentRef, array $input = []): ManualConsentErasure` | `POST /v1/manual-consents/{ref}/erase` | idempotent server-side; optional `reason`. Crypto-shreds the cell and deletes the enforcement record. `ManualConsents.php:142` |

**Manual-consent idempotency IS honoured by the server.** Older guidance claimed
the key was accepted but ignored and that a retry could create a duplicate. That
has been false since 2026-07-01. `POST /v1/manual-consents` looks the key up in
the per-tenant `IdempotencyStore` and replays the prior 201 verbatim
(`app/Controllers/Api/ManualConsentController.php:86-96` and `:122-128`). Verified live: the same
`Idempotency-Key` sent twice returned the **identical** `consentId`,
`merkleRoot` and `consentRefs`, and the database held **exactly one**
`consent.enforcement_record` row afterwards. Passing a stable key is still the
right thing to do; you just do not need to build your own duplicate guard.

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
written. Ending never revokes, erases, or hides any per-cell consent: it is a
prospective, relationship-level stop, and it **does** stop necessity allows too.

> **`end` 404s on a customer Agreely has no record for.** A customer whose cells
> are all `necessity` has no stored row, so there is nothing to end. Verified
> live: `POST /v1/customers/<unknown-ref>/relationship/end` returns HTTP 404
> `{"error":{"code":"not_found","message":"No customer with that reference."}}`,
> surfaced as `AgreelyNotFoundError`. Treat that 404 as "nothing to do", not as
> a failure: there was never a stored relationship to close. Do not paper over it
> by attesting a consent first.

### `catalog()`: discovery, scope `check` OR `issue`

```php
$entries = $agreely->catalog()->list(); // list<CatalogEntry>: id, category, purpose, description
```

`GET /v1/catalog` (`Catalog.php:27`). This is the ground truth for the labels
`check` will resolve. When a check returns an unexpected `deny`/`none`, diff your
labels against this list **before** you assume a consent problem.

### Typed result objects (real shapes)

- **`CheckResult`** `decision, status, consentRef?, checkedAt, degraded, mode?, assurance?` plus `isAllow()`.
- **`BatchDecision`** `customerRef, category, purpose, decision, status, consentRef?, assurance?, checkedAt` plus `isAllow()`.
- **`CheckFieldsResult`** `->isAllowed($ref,$cat,$pur): bool`, `->decisions`.
- **`Identity`** `scopes: list<string>`.
- **`IssuedRequest`** `requestId, status, deepLink, emailDelivered, items, document?`.
- **`ConsentRequestRecord`** `requestId, status, validUntil, expiresAt, createdAt, settledAt?, customerId, documentCode, items, document?`.
- **`ManualConsentResult`** `consentId, merkleRoot, consentRefs: list<string>, assurance, anchored`.
- **`RelationshipEnded`** `customerRef, status, endedAt, endedBy`.
- **`CancelledRequest`** `requestId, status, cancelled`.

### Known SDK drift (in the PUBLISHED `agreely/sdk` 0.2.0 / `@agreely/sdk` 0.2.0)

Both published SDKs predate the necessity model and have not caught up. The wire
is correct; the typed wrappers are not. Verify against `openapi.yaml`, not
against the SDK's type list. **Check your installed version before trusting this
section**: an SDK update that adds the full eight-value status vocabulary and the
`basis` field is in flight in `~/www/agreely-sdk-php`, so a checkout newer than
0.2.0 may already carry both.

- **The status union is stale in both.** `CheckResult.php:12-15` and
  the TS `Status` union in `agreely-sdk/src/types.ts` both list six statuses and omit **`necessity`**
  and **`sensitive_requires_consent`**. This is a documentation defect only: the
  PHP `CheckResult::fromWire` copies `status` through as a plain string, so the
  real value **does** reach you at runtime. Do not write a `match` that assumes
  six arms.
- **`basis` is dropped by the typed objects.** Neither `CheckResult::fromWire`
  (`CheckResult.php:38-47`) nor `BatchDecision::fromWire`
  (`BatchDecision.php:23-35`) reads `basis`, so a `necessity` allow arrives with
  no way to see WHICH ground allowed it. If you need it (for an operator screen
  or an evidence export), read it off the wire yourself with a custom
  `HttpClient`, or call the endpoint directly. Flag it as an SDK gap; do not
  invent a client-side mapping from your own field table, which would be
  guessing at the company's declared basis.

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
  caught offline (`ReceiptVerifier.php:81-85`, `127-130`).

> **There is NO server `receipts/verify` endpoint. Do not point anyone at one.**
> Both SDK verifiers and older versions of this guide told relying parties to
> "use the server `receipts/verify` endpoint" for the two checks that are
> unsupported offline (a citizen receipt's company signature, and the cell-label
> binding). **That route does not exist.** The verify tier
> (`https://verify.agreely.ca`) exposes exactly two routes, `GET /` and
> `GET /batch` (`app/Controllers/Verify/VerifyController.php`), and the `/v1` API
> has no receipts namespace at all. So, plainly:
> - a **sound company-signature check on a citizen receipt** has **no path
>   today**, on any surface;
> - a **sound cell-label check on a citizen receipt** has **no path today**.
>
> Treat both as genuinely unavailable. Do not trust the displayed
> category/purpose labels off a `partial` citizen receipt, and do not promise an
> integrator that a server call will close the gap. (The SDKs still carry the
> false pointer in their `notes` strings; a fix is in flight in the SDK repos.)

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
# a non-consent cell instead answers:
# -> {"decision":"allow","status":"necessity","basis":"necessary_for_service"}  exit 0
```

Auth precedence (`auth.ts:1-11`): `--api-key` flag > `AGREELY_API_KEY` env > OS
keychain (`keytar`, optional) > `~/.config/agreely/config.json` (`0600`). Base
URL: `--base-url` > `AGREELY_BASE_URL` > stored config > SDK default. Resolution
**never prompts**; a missing key is a `UsageError` (exit 2), never a hang.

### Exit codes: THE agent contract (`errors.ts:14-56`)

| code | meaning |
|---|---|
| `0` | success / check **ALLOW** (`active` OR `necessity`) / receipt `verified` or `partial` |
| `2` | usage or validation (bad/missing args, invalid input, no credentials, 400/422, not-found) |
| `3` | auth: key missing/invalid/revoked or lacks the scope (401/403) |
| `4` | **unavailable**: an Agreely outage, OR a receipt `verify` that could not complete (DID unresolvable) |
| `5` | rate-limited (429) |
| `6` | **verify-failed**: a receipt was checked and did NOT verify (a real tamper), a verdict, not an error |
| `7` | **billing-inactive**: the company's Agreely subscription is lapsed/unpaid (HTTP 402). Actionable, not transient. Distinct from exit 4 (outage). |
| `10` | check **DENY**: a clean, expected negative, **not** an error |
| `1` | uncategorized failure |

A DENY's JSON still goes to stdout; a real error keeps stdout clean and writes a
`{"error":{"code","message"}}` envelope to stderr (empirically confirmed:
`agreely check ... --json` with no key returns stderr `{"error":{"code":"usage",...}}`,
exit 2). NOTE: exit `6` is real and central (`verify`) but the CLI README's
exit-code table omits it; trust `errors.ts`.

**Script exit 7 explicitly.** Any shell wrapper that treats 7 as "unexpected" is
mishandling a normal production condition: the company's subscription lapsed and
every gate is now fail-closed until someone pays. It is actionable by a human,
not by a retry.

### Commands (all `--json`-capable; global flags: `--json --api-key --base-url`)

```bash
agreely check <customerId> <category> <purpose> [--json]      # 0 allow, 10 deny, 4 outage, 7 billing-inactive
agreely check --batch <file.json> [--json]                    # array of {customerRef,category,purpose}; 0 if ALL allow, 10 if ANY deny; 500-item cap
agreely catalog [--json]
agreely whoami [--json]                                       # server-verified: the key's real scopes
agreely requests list [--customer <ref>] [--status <s>] [--limit <n>] [--cursor <id>] [--json]  # metadata only; bare `agreely requests ...` is a kept alias
agreely request create --customer <id> --to <email> (--document <versionId> | --document-code <code>) --valid-until <YYYY-MM-DD> [--idempotency-key <k>] [--json]
agreely request show <requestId> [--json]                     # requestId is 0x + 64 hex
agreely request cancel <requestId> [--json]                   # idempotent
agreely request wait <requestId> [--interval <ms>] [--timeout <ms>] [--json]  # exit 4 on timeout
agreely verify <receipt.json> [--ipfs] [--onchain --rpc-url <url>] [--did-doc <file>...] [--json]
agreely manual-consent create --customer <id> --document-version <id> --effective-date <YYYY-MM-DD> --valid-until <YYYY-MM-DD> --item <catalogId|category:purpose>... --pdf <path> [--upload] [--json]
agreely manual-consent claim-link --customer <id> [--reference <ref>] [--json]
agreely manual-consent revoke <consentRef> [--reason <text>] [--json]
agreely manual-consent erase  <consentRef> [--reason <text>] [--json]         # crypto-shred (art. 23 destruction)
agreely relationship end    <customerRef> --reason <text> [--json]            # art. 23 (idempotent; 404 if no stored customer)
agreely relationship revert <customerRef> --reason <text> [--json]            # art. 11 al. 1 correction of a mistaken end
agreely login                                                                 # interactive: store a key in the OS keychain
agreely config set --api-key <k> [--base-url <url>]                           # non-interactive store (for scripts)
```

`--item` accepts a catalog UUID or the CLI-only `category:purpose` shorthand,
which the CLI converts to a `{category, purpose}` object before the wire. The
SDK's `items` array does **not** accept the colon form.

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
a real transport branch (`Transport.php:162-183`).

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
    'apiKey'     => getenv('AGREELY_API_KEY') ?: '',
    'timeout'    => 8000,   // ms, TOTAL budget. The 800ms default is too tight for the open internet.
    'maxRetries' => 1,      // smooths one transient blip; still fails closed after that
    // 'baseUrl' => 'https://api.agreely.ca',   // default; override only for a private stack
]);

// 2) Gate the action on a consent check. decision === "allow" is the only true.
//    Send RAW labels.
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
`checkDetailed()`, but then an outage **throws** `AgreelyUnavailableError` and a
lapsed billing subscription **throws** `AgreelyBillingInactiveError`, so wrap
both:

```php
use Agreely\Sdk\Errors\AgreelyBillingInactiveError;
use Agreely\Sdk\Errors\AgreelyUnavailableError;

try {
    $d = $agreely->checkDetailed('cust_8812', 'Phone number', 'Billing');
    // Branch on $d->decision. Log $d->status BESIDE $d->degraded, never instead of it.
} catch (AgreelyBillingInactiveError $e) {
    // HTTP 402: the company's subscription is lapsed/unpaid. NOT transient and
    // NOT an outage. The company must pay to restore service. Fail closed.
    error_log("[agreely] billing inactive: {$e->getMessage()}");
} catch (AgreelyUnavailableError $e) {
    // Agreely was unreachable. Fail closed (deny) unless you have an explicit,
    // scoped, audited fail-open policy (see below).
}
```

### On the 402 billing-inactive case: typed error ships in v0.1.1+

As of **`agreely/sdk` v0.1.1** (PHP), **`@agreely/sdk` v0.1.1** (TS), and
**`@agreely/cli` v0.1.2** (CLI), HTTP **402** is mapped to a distinct typed
error class: **`AgreelyBillingInactiveError`** (code `billing_inactive`, status
402). The API returns `{"error":{"code":"billing_inactive","message":"..."}}` with
HTTP 402 when the company's Agreely trial or subscription is lapsed or unpaid
(trial ended, `past_due`, or `canceled`).

**Key distinctions from `AgreelyUnavailableError` (outage):**
- A 402 is **not transient and not retryable.** The company must pay to restore
  service; backing off and retrying will never resolve it.
- `check()` still **fails closed** (`false`) on a 402, same as on an outage.
- `checkDetailed()` and `resolve()` **throw** `AgreelyBillingInactiveError`,
  distinct from `AgreelyUnavailableError`, so a caller can surface the billing
  situation explicitly.
- The CLI exits with code **7** on a 402 (see the exit-code table above).

**Before v0.1.1:** a 402 fell through to the `default` branch and surfaced as
`AgreelyUnavailableError` (status 402). If you are on an older pin, inspect
`$e->status === 402` on the caught `AgreelyUnavailableError` to detect it.

```php
use Agreely\Sdk\Errors\AgreelyBillingInactiveError;
use Agreely\Sdk\Errors\AgreelyUnavailableError;

try {
    $d = $agreely->checkDetailed('cust_8812', 'Phone number', 'Billing');
} catch (AgreelyBillingInactiveError $e) {
    // 402: company subscription lapsed. NOT transient. Actionable.
    // $e->code === 'billing_inactive', $e->status === 402
    notifyOps('Agreely subscription lapsed: gating is fail-closed until renewed.');
} catch (AgreelyUnavailableError $e) {
    // 503/network/timeout: a transient outage. Retry later.
}
```

`check()` (the boolean gate) catches BOTH and returns `false` in both cases, so
code that uses only `check()` needs no change; the distinction matters only when
using `checkDetailed()` or `checkBatch()` and wanting to surface the cause.

### Verify a receipt you were handed

```php
use Agreely\Sdk\Agreely;

$v = Agreely::verifyReceipt(json_decode($receiptJson, true)); // default HTTPS DID resolver
if ($v->overall === 'verified') {
    // a company-attested receipt, fully offline-sound
} elseif ($v->overall === 'partial') {
    // a citizen receipt: honest, NOT a failure; do not treat as a hard pass,
    // and do not trust its displayed cell labels (no server check exists)
} elseif ($v->overall === 'unavailable') {
    // a DID could not be resolved: INCONCLUSIVE; retry with connectivity or --did-doc
} else { // 'failed'
    // a real tamper / wrong key / bad disclosure
}
```

## Field-gating a PHP app (the integration model)

This is the model to teach an agent wiring Agreely into a CRM (or any app that
renders a person's data). One sentence: **gate every personal-data VALUE behind a
check, and filter the view-model by the result before the template ever sees it.**
A refused value must never reach the view as its real content.

The recipe below is **derived from a real, deployed integration**:
`~/www/agreely-boreale-demo/app/Models/Boreale/{ConsentField,GatedField,ConsentGate}.php`,
which gates a full insurance CRM against the live API. Prefer it over anything
you would invent.

### The core loop

```
load record(s) from your DB
     |
     v
one BATCH consent call for all (subject, category, purpose) cells you intend to show
     |
     v
build the VIEW-MODEL: for each personal field, keep the real value ONLY if the
   decision is "allow"; otherwise -> a placeholder, or OMIT entirely for an
   optional consent-gated field (e.g. marketing email)
     |
     v
render the template from the filtered view-model (the template never holds the raw value)
```

Two hard rules:

1. **Fail closed on consent cells.** On ANY error or unavailability, a
   consent-gated value shows the veil, never the ungated value. A 402
   billing-inactive surfaces as the typed `AgreelyBillingInactiveError`; catch it
   at the gate alongside `AgreelyUnavailableError`. Both are fail-closed; the
   distinction matters only if you want to surface "billing lapsed" vs "outage"
   in operator tooling.
2. **Gate per VALUE, batched per VIEW.** Never issue one `check()` per field per
   row in a loop. One `checkBatch`/`checkFields` per view. It is one request
   against the 120/min budget instead of dozens.

### A field is not just consent-or-not: the six-way basis taxonomy

The deployed `ConsentField` carries a richer basis tag than a boolean, because
the render behaviour genuinely differs:

| `basis` tag | Agreely cell basis | render behaviour |
|---|---|---|
| `necessary` | `necessary_for_service` / `contract` | auto-allow, **always renders**, never revocable |
| `security` | `security_fraud` | auto-allow, always renders |
| `professional` | `professional_contact` | auto-allow, always renders; **no consent artifact, no retention schedule, never issue a request for it** |
| `consent` | `consent` | renders only if the person consented |
| `sensitive` | any basis + `is_sensitive` | renders only on **express** consent. With no record it denies on `sensitive_requires_consent` (non-consent basis) or on `none` (consent basis), so drive the masked veil off YOUR field flag, not off one status |
| `automated` | `consent`, on a cell flagged automated-decision | a consent cell whose value renders **with a right-to-human-review notice** (art. 12.1 is a transparency duty, not a basis) |

```php
final class ConsentField
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $category,   // EXACTLY as declared in the Agreely catalog
        public readonly string $purpose,    // idem
        public readonly string $basis,      // necessary|security|professional|consent|sensitive|automated
        public readonly string $column,     // where YOUR app holds the PII value
        public readonly string $note = '',
    ) {}

    /** These never depend on a consent verdict, so they render even if the check fails. */
    public function isAlwaysRendered(): bool
    {
        return in_array($this->basis, ['necessary', 'security', 'professional'], true);
    }

    public function isSensitive(): bool { return $this->basis === 'sensitive'; }
    public function isAutomated(): bool { return $this->basis === 'automated'; }
}
```

### Seven render states, not two

A boolean `allowed` cannot carry the difference between "this person withdrew",
"we could not reach Agreely", and "this person is not in Agreely at all". The
deployed `GatedField::renderState()` resolves seven, in this precedence order:

```php
public function renderState(): string
{
    if ($this->field->isAutomated() && $this->allowed) {
        return 'automated';   // shows the value PLUS the human-review notice
    }
    if ($this->allowed) {
        return $this->field->isAlwaysRendered() ? 'necessary' : 'consented';
    }
    // --- refused variants (value withheld) ------------------------------
    if ($this->clientNotLinked && $this->status === 'none') {
        return 'unknown';     // not in Agreely yet: absence of a link, NOT a refusal
    }
    if ($this->status === 'revoked' || $this->status === 'expired') {
        return 'withdrawn';   // an active, recorded withdrawal
    }
    if ($this->field->isSensitive() || $this->status === 'sensitive_requires_consent') {
        return 'masked';      // express consent required
    }
    if (in_array($this->status, ['unavailable', 'billing_inactive', 'error'], true)) {
        return 'unknown';     // fail-closed, honest: we do not know
    }
    return 'refused';         // a linked person who did not consent to THIS purpose
}
```

And a status label that knows about `necessity`, so an operator screen never
shows a raw enum:

```php
public function statusLabel(): string
{
    return match ($this->status) {
        'active'                     => 'Consenti',
        'necessity'                  => 'Nécessaire',
        'revoked'                    => 'Retiré',
        'expired'                    => 'Expiré',
        'erased'                     => 'Effacé',
        'none'                       => 'Aucun consentement',
        'relationship_ended'         => 'Relation terminée',
        'sensitive_requires_consent' => 'Consentement exprès requis',
        'unavailable'                => 'Indisponible',
        'billing_inactive'           => 'Abonnement inactif',
        'error'                      => 'Erreur',
        default                      => $this->status,
    };
}
```

Honest refusal copy, per status (never invent a reason the API did not give):

| status | FR | EN |
|---|---|---|
| `revoked` | Consentement retiré par la personne (art. 8 al. 1 (4°)). | Consent withdrawn by the person. |
| `expired` | Consentement expiré. | Consent expired. |
| `none` | Consentement non accordé. | Consent not granted. |
| `sensitive_requires_consent` | Consentement exprès non accordé (art. 12 al. 1 in fine, art. 13 al. 2). | Express consent not granted. |
| `relationship_ended` | Relation client terminée (art. 23). | Customer relationship ended. |
| `unavailable` | Vérification Agreely indisponible, refus par défaut. | Agreely check unavailable, refused by default. |
| `billing_inactive` | Abonnement Agreely inactif, refus par défaut. | Agreely subscription inactive, refused by default. |

### On a check FAILURE, still render necessity/security/professional fields

This is where the deployed integration deliberately diverges from a naive "fail
closed on everything", and it is **right** to. Those fields' lawful basis is not
consent. They can never be refused by the person, so a **failed check carries no
information about them**. Blanking them out on an outage does not protect anyone;
it just breaks the screen and teaches the operator that the veil is noise.

```php
private function toGatedField(Client $c, ConsentField $f, ?BatchDecision $d): GatedField
{
    if ($d === null) {                       // the check FAILED for this cell
        if ($f->isAlwaysRendered()) {
            // Basis is not consent -> a failed check tells us nothing. Render from
            // your own store, and label it honestly as a necessity render.
            return new GatedField($f, true, $c->value($f), 'necessity');
        }
        return new GatedField($f, false, null, 'unavailable');   // consent cells fail CLOSED
    }
    $allowed = $d->isAllow();
    return new GatedField($f, $allowed, $allowed ? $c->value($f) : null, $d->status, $d->assurance);
}
```

Caveat to state out loud: you lose the **access-log entry** for that render,
because the call did not reach Agreely. That is a real, honest gap in your
accountability evidence during an outage, not something to paper over.

### The blip-cache pattern: latency relief that cannot replay an allow

"Never cache an allow" is an absolute correctness invariant (spec 16): a
withdrawal that landed one second ago must be honoured on the very next render.
But a flat "no cache, ever" gives you nothing for the real problem, which is a
single slow or dropped round-trip flickering a whole screen.

The pattern the deployed gate uses: cache the last **successful** batch result
for a short TTL, and on a failure **replay only the non-allow decisions**.

```php
try {
    $decisions = $this->agreely->checkBatch($items);
} catch (AgreelyError) {
    $cached = $this->readBlip($cacheKey);
    if ($cached !== null && (time() - $cached['at']) <= 120) {
        // STRIP EVERY ALLOW. A previously-allowed cell has no cached decision and
        // therefore falls to the fail-closed path; only stable negatives replay.
        return array_filter($cached['decisions'], fn (BatchDecision $d) => !$d->isAllow());
    }
    return [];   // sustained outage -> every consent cell fails closed
}
```

Why this is sound:

- **No allow can ever be served from cache.** The filter removes them before the
  data leaves the cache layer, so the invariant holds by construction, not by
  discipline.
- **What replays is stable and safe**: `none`, `revoked`, `expired`. Those are
  the verdicts whose flicker produces the worst UX (a "not linked yet" banner
  blinking on and off), and replaying a negative can never leak a value.
- **A sustained outage still fails closed.** Nothing cached, or a stale cache,
  means an empty decision map and the fail-closed path.
- Keep the TTL short (120s in the deployed version) and treat it as a UX
  concession, not an architectural cache.

### Which SDK methods surface consent STATE (the UI state layer)

The `check` family **is** your read-side state layer. There is **no dedicated
"consent status" or per-client rollup endpoint** in the SDK or the `/v1` API
today (the API has 14 routes; none of them is a rollup). Derive any client-level
summary yourself from the per-cell decisions. Call this out to the integrator as
a current gap, not as something shipping today.

| Method | Use it for | Shape |
|---|---|---|
| `checkFields(array $customerRefs, array $fields): CheckFieldsResult` | a LIST / table: N customers x M fields in ONE call (mind the 500 cap) | `$r->isAllowed($ref, $category, $purpose): bool`; `$r->decisions` is the underlying `list<BatchDecision>`. `Agreely.php:260`, `CheckFieldsResult.php:32` |
| `checkBatch(array $items): list<BatchDecision>` | a DETAIL view, or when you need the per-cell REASON, not just a boolean | each `BatchDecision`: `customerRef, category, purpose, decision, status, consentRef?, assurance?, checkedAt` + `isAllow()`. `Agreely.php:226` |
| `check()` / `checkDetailed()` | a single field / a one-off | bool / `CheckResult`. `Agreely.php:203`, `:280` |
| `identity()` | startup sanity: does this key carry the `check` scope? | `Identity{scopes}`. `Agreely.php:139` |
| `catalog()->list()` | reconciling your field table against the declared cells | `list<CatalogEntry>`. `Catalog.php:27` |
| `relationships()->end()/revert()` | offboarding a client (art. 23), not a per-field read | `RelationshipEnded` / `RelationshipReverted`. `Relationships.php:37`, `:74` |

### Composing a client-level "did not consent" warning

No rollup endpoint exists, so derive it from the batch. Rule: over a client's
**consent-based** cells only, if ALL are refused the client "did not consent"; if
SOME are refused it is partial. Necessity cells are excluded, because they can
never be refused and would mask a real refusal.

```php
/** @param list<BatchDecision> $decisions all cells for ONE client */
function clientConsentSummary(array $decisions, array $consentPurposes): string
{
    $consentCells = array_filter(
        $decisions,
        fn ($d) => in_array($d->purpose, $consentPurposes, true),
    );
    if ($consentCells === []) {
        return 'ok'; // nothing consent-gated to summarize
    }
    $refused = array_filter($consentCells, fn ($d) => !$d->isAllow());
    if (count($refused) === count($consentCells)) {
        return 'none';    // FR "cette personne n'a pas consenti" / EN "did not consent"
    }
    return $refused === [] ? 'ok' : 'partial';
}
```

One more distinction the deployed gate makes, and it matters for honesty: a
client whose **every consent cell** came back with a real `none` is **not
linked** to Agreely yet, which is different from "refused". Only treat it as
not-linked when the API actually **answered** `none` for every gated cell; a
missing decision means the check failed, which is `unavailable`, not `unknown
person`.

### The template only prints what survived the gate

```latte
{* the view-model already hid refused values *}
<td>{$row['values']['newsletter_email'] ?? '(non fourni)'}</td>   {* omitted consent field *}
<td>{$row['values']['billing_name']}</td>                          {* necessity -> always the value *}
{if $row['consentSummary'] === 'none'}
  <span class="badge">Cette personne n'a pas consenti</span>
{elseif $row['consentSummary'] === 'partial'}
  <span class="badge">Consentement partiel</span>
{/if}
```

**Never** pass the raw record to the template and gate inside the view: a missed
branch leaks the value. Filter in the view-model; the template only prints what
survived the gate.

## Field-classification guide (which fields to gate)

Baked in for the integrating agent. **Honest scope:** this is a practical
starting taxonomy, not legal advice. Agreely **records** the basis the enterprise
**declares in its catalog**; it does not invent, validate or certify it. Have the
enterprise's privacy counsel confirm each field's classification and basis.

### What is personal information (art. 2)

Personal information is information about an **identifiable natural person**. NOT
personal: a company's legal name, its NEQ / registration number, aggregate
counts. In B2B, a **named human contact's** name, business email and business
phone **ARE** personal information (art. 2 keeps applying).

**But their treatment is different.** Under **art. 1, dernier alinéa**,
information concerning that person's *exercise of a function within an
enterprise* falls outside Sections II and III: no consent, no withdrawal, no
retention schedule, no art. 23 destruction duty. It stays inside art. 2,
Section I.1 (responsable, policies, EFVP, incidents) and Section IV (access,
rectification, the 30-day clock, de-indexation). See *Professional contact*
above for the boundary, which is the **function**, not the field name.

### The rule the agent must encode

- **CONSENT field** (marketing email, newsletter): consent is **withdrawable**,
  so a `check` can return **DENY** -> do NOT render it. For an optional field,
  omit it entirely rather than showing a placeholder.
- **NECESSITY field** (billing contact name, fraud signals, professional
  coordinates): not withdrawable -> a `check` returns **`allow` / `necessity`**
  -> it always renders. You STILL gate it, so the access is LOGGED, and you
  provision nothing for it.
- **SENSITIVE field**: express consent only, and it never rides a necessity
  basis. With no record it denies on `sensitive_requires_consent` when the cell's
  declared basis is non-consent, and on `none` when the basis is already consent.
  Drive your masked veil off your own sensitivity flag so both land the same way.
- **Rule:** gate EVERY personal-information field uniformly; leave non-personal
  fields ungated. Whether the value renders is decided by the **decision**, not
  by whether you called.

### Practical field taxonomy

| Field (scenario) | Personal? | Typical Agreely basis | Gate? | On deny |
|---|---|---|---|---|
| Client display name, billing contact name (CRM/billing) | yes | `contract` / `necessary_for_service` | yes (for the log) | veil (never denied in practice) |
| Business email used for the contract/invoices | yes | `contract` / `necessary_for_service` | yes | veil |
| A named business contact's name, work email, work phone, job title | yes (art. 2) | `professional_contact` (art. 1, dernier alinéa carve-out) | yes (for the log) | never denied; **never issue a consent request for it** |
| Marketing / newsletter email (subscriber) | yes | `consent` | yes | OMIT (optional field) |
| Phone number for billing/support | yes | `necessary_for_service` | yes | veil |
| Phone number for marketing calls | yes | `consent` | yes | OMIT / veil |
| Postal address for shipping (e-shop) | yes | `necessary_for_service` | yes | veil |
| Fraud / anti-abuse signals on a payment | yes | `security_fraud` (art. 12 al. 2 (3°)) | yes | never denied |
| Records kept because a fiscal law requires it | yes | `legal_obligation` (declared) | yes | never denied |
| Browsing / usage analytics | yes | `consent` | yes | OMIT |
| Contact-form lead message + email | yes | `consent` (they reached out for one purpose) | yes | veil / OMIT |
| **Employee work coordinates** (work email, work phone, title) | yes | `professional_contact` (art. 1, dernier alinéa) | yes | never denied |
| **Substantive HR data** (pay, evaluations, SIN, discipline, medical notes) | yes, and often **sensitive** | **counsel-classified.** Fully protected; rests on an art. 12 al. 2 necessity ground or on consent, and often on express consent | yes | veil / masked |
| Data about a MINOR under 14 | yes (heightened) | `consent`, with the art. 4.1 / art. 14 al. 2 parental regime | yes | veil; treat sensitively |
| Company legal name, NEQ, registration number | **no** | not personal | no | renders as-is |
| Aggregate counts / anonymized totals | **no** | not personal | no | renders as-is |

Two rows deserve a warning, because a previous version of this guide got them
wrong:

- **There is no "employment relationship" lawful basis in P-39.1.** Do not write
  one into a catalog. An employee's **work coordinates** ride art. 1, dernier
  alinéa. Everything substantive about the employment (compensation,
  evaluations, SIN, health) is fully protected and rests on an art. 12 al. 2
  necessity ground or on consent. If you cannot map a field to Agreely's actual
  basis vocabulary, mark it **counsel-classified** and stop; do not invent one.
- The basis column is the enterprise's to declare and its counsel's to confirm.
  This table is a default, not a ruling.

### Why gate necessity fields too? (the access-log question)

> **There is no literal "log every read" rule in P-39.1.** What the Act does
> impose, and what the access log is EVIDENCE for:
> - **art. 3.1**: the enterprise is *responsible* for the personal information it
>   holds, and the person with the highest authority acts as *responsable de la
>   protection des renseignements personnels*.
> - **art. 3.2**: it must establish and implement **governance policies and
>   practices** covering, among other things, retention and destruction, roles
>   and responsibilities, and complaint handling.
> - **art. 18 al. 2**: it **must record** every communication made under
>   paragraphs 6° to 9.1° of art. 18 al. 1. This is the one express **logging**
>   duty in the private-sector Act, and it is narrow.
> - **arts. 12 to 14**: purpose limitation, the art. 12.1 automated-decision
>   duties, and the art. 14 consent-quality bar where consent is the basis.
> - **art. 23**: destruction or anonymization once the purposes are accomplished.
> - **arts. 27, 28, 32**: access, rectification, and the 30-day answer clock.
>
> **Be precise about what art. 3.1 says.** It is a **responsibility** provision.
> No P-39.1 article contains an express, GDPR-style duty to *demonstrate*
> compliance. So the honest claim is: the `check` call plus its access log is
> **evidence that supports** the art. 3.1 responsibility and art. 3.2 governance
> duties, and it operationalizes the art. 18 al. 2 recording duty for the
> communications that one covers. For **consent** cells the check is
> *enforcement* (denied = hidden); for **necessity** cells the value can never be
> denied, but the call is what produces the record of the access.
>
> **No overclaim:** the access log is how you *evidence* accountability. It is
> not a named statutory line-item, it does not "certify" anything, and it does
> not make anyone compliant. Non-personal data needs no gating at all.

## P-39.1 to A-2.1: the twin table

**All 26 citation keys Agreely uses differ between the two acts. Every one.**
Never reuse a P-39.1 number for a public body. Concepts, not numbers, are what
carry over.

| Concept | P-39.1 (private) | A-2.1 (public) |
|---|---|---|
| Purpose-limited use (what `check` enforces) | art. 12 al. 1 | art. 65.1 al. 1 |
| Consent quality (issuance) | art. 14 | art. 53.1 |
| Collection notice | art. 8 | art. 65 |
| Withdrawal (`revoked`) | art. 8 al. 1 (4°) | art. 65 al. 1 (5°) |
| Erasure / destruction (`erased`, `erase`) | art. 23 | art. 73 |
| Relationship end (`relationship_ended`) | art. 23 | art. 73 |
| Sensitive (express consent) | art. 12 al. 1 et al. final; art. 13 al. 2 | art. 59 al. 1 et al. final; art. 65.1 al. 1 |
| Automated decision | art. 12.1 | art. 65.2 |
| Minors under 14 | art. 4.1; art. 14 al. 2 | art. 64.1; art. 53.1 al. 2 |
| Access / rectification + clock | arts. 27, 28, 32 (**30 days**) | arts. 83, 84, 89, 98 (**20 days**, one 10-day extension, notice given before day 20) |
| De-indexation | art. 28.1 | **none. Never show the row to a public body.** |
| Contract / essential basis | art. 9 al. 1 (1°); art. 12 al. 2 (4°) | art. 64 al. 1 (attributions / programme) |
| Professional contact | art. 1, dernier alinéa | art. 55 (2e phrase); art. 57 |

### Number collisions that will bite

| number | P-39.1 says | A-2.1 says |
|---|---|---|
| **art. 9** | you may not refuse a good/service over a refusal to give information, except (1°) where the collection is necessary to conclude or perform the contract | the general **right of access to the documents of a public body** |
| **art. 23** | once the purposes are accomplished, **destroy or anonymize** | a public body may not communicate a **third party's** confidential industrial/financial information without consent |

Public self-serve signup is live on Agreely, so a public-body reader can be
looking at this guide today. Lead any public-body-facing text with the A-2.1
column.

### The four citation corrections (older versions of this guide had these wrong)

**Erasure is art. 23, NOT art. 28.1.** P-39.1 contains **no GDPR-style citizen
right to erasure**. Destruction is the enterprise's own duty:

> Art. 23: « Lorsque les fins auxquelles un renseignement personnel a été
> recueilli ou utilisé sont accomplies, la personne qui exploite une entreprise
> doit le détruire ou l'anonymiser pour l'utiliser à des fins sérieuses et
> légitimes, sous réserve d'un délai de conservation prévu par une loi. »

**Art. 28.1 destroys nothing.** It is exclusively the right to require that
dissemination **cease** and that a hyperlink attached to the person's name be
**de-indexed** (or re-indexed). Reserve it for that alone. The A-2.1 twin for
destruction is **art. 73** (the anonymization purpose there is « intérêt
public », and the reserve is the *Loi sur les archives*). **A-2.1 has no
de-indexation twin at all.**

**Withdrawal is art. 8 al. 1 (4°), NOT art. 9.** Art. 9 is the no-service-refusal
rule and says nothing about withdrawal.

> Art. 8 al. 1: « [...] 4° de son droit de retirer son consentement à la
> communication ou à l'utilisation des renseignements recueillis. »

Where the purpose is commercial or philanthropic prospecting, **art. 22** may be
cited alongside: al. 1 is the duty to identify oneself and inform of the right to
withdraw, and **al. 2** is the operative rule that on withdrawal the information
« doi[t] cesser d'être ainsi utilisé[e] ». The A-2.1 twin is **art. 65 al. 1
(5°)**, and it must be presented as tied to a **facultative** collection, not as
a free-standing right: **A-2.1 grants no general withdrawal right.**

**Contract necessity is art. 9 al. 1 (1°), and the USE limb is art. 12 al. 2
(4°). Art. 18 does not belong in that row.**

> Art. 9 al. 1 (1°): « la collecte est nécessaire à la conclusion ou à
> l'exécution du contrat »

Nuance worth preserving: **art. 9 is drafted as an exception to a prohibition**
(when an enterprise MAY condition a good, a service or a job on getting the
information), not as a GDPR-style lawful-basis catalogue. **P-39.1 has no basis
catalogue.** Art. 12 al. 2 is a **closed list of five** consent-free secondary
USES and it **contains no contract item**. Art. 18 is the consent-free
**COMMUNICATIONS** list; it is neither a contract ground nor a generic
legal-obligation basis, though its al. 1 (4°) does cover a communication
necessary under a law applicable in Quebec, and its al. 2 carries the recording
duty. **A-2.1 has no contract limb at all**: the public ground is **art. 64
al. 1** (necessary to the body's *attributions* or to a *programme* it manages).

**And the professional-contact claim.** Older text asserted "Quebec has **no**
business-card exception". That is **false**: art. 1, dernier alinéa is exactly
that carve-out. An integrator told otherwise would wrongly issue consent requests
for a business contact's work coordinates. See *Professional contact* above for
what it does and does not disapply.

## Outage behavior: fail-closed by default, explicit audited fail-open

When Agreely is unreachable (503 / timeout / network), `check()` **denies**
(`false`) and `checkDetailed()` **throws** `AgreelyUnavailableError`. A real 200
deny is never affected. You can opt **specific categories** into fail-open, but
only via **two independent gates plus a mandatory audit sink**:

```php
$agreely = new Agreely([
    'apiKey' => $key,
    'degradeOnOutage' => [
        'mode'            => 'fail-open',                    // the literal required word
        'categories'      => ['Browsing/usage'],             // GATE 1: only these may degrade
        'maxOutageWindow' => '5m',                           // refuse to degrade past this
        'onDegrade'       => fn ($ctx) => $audit->log($ctx), // MANDATORY: absent, the constructor THROWS
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

**The degraded result lies about status, on purpose.** It synthesises
`status: "active"` with `degraded: true` (`Agreely.php:319-326`). If you enable
fail-open, you MUST propagate `degraded` into whatever you log or render, or your
audit trail will record consent that was never checked. Consider it a strong
argument for leaving fail-open off entirely except for genuinely low-stakes
analytics categories.

The CLI is fail-closed only; the whole degrade policy is intentionally omitted
from CLI v1 (a one-shot invocation cannot persist/audit a window). Configure
degrade where the SDK is embedded (`agreely-cli/README.md:188-198`).

## Errors + gotchas (each with a source cite)

- **A deny is not an error.** Only auth/validation/not-found/rate-limit/outage
  throw; a 200 `deny` is a normal result. Base class `AgreelyError`; a deny never
  reaches it (`AgreelyError.php:7-16`).
- **Gate on `decision`, never on `status`.** `necessity` allows and `active`
  allows; a degraded fail-open reports `active` without having checked anything.
  `isAllow()` is `decision === 'allow'` and is the only correct predicate.
- **`$e->code` is a magic getter.** `AgreelyError` cannot redeclare
  `Exception::$code` (a non-readonly int), so the wire code is exposed via
  `__get('code')` and `errorCode()`. `$e->code` works; `$e->status` and `$e->field`
  (validation) are real readonly props (`AgreelyError.php:52-65`).
- **Error to class map** (`Transport.php:162-183`): 401/403 to `AgreelyAuthError`;
  400/422 to `AgreelyValidationError` (`->field`); 404 to `AgreelyNotFoundError`;
  402 to `AgreelyBillingInactiveError` (code `billing_inactive`, status 402,
  non-retryable; as of v0.1.1); 429 to `AgreelyRateLimitError` (`->retryAfter`);
  503/network/timeout **and any other 5xx** to `AgreelyUnavailableError`
  (`retryable` only when 503).
- **`consentRequests()->create` is never auto-retried** (it emails). It attaches a
  unique `Idempotency-Key`; pass your own to make a retry replay the original 201
  instead of double-issuing (`ConsentRequests.php:37-48`).
- **`manualConsents()->record` idempotency IS server-honoured.** Same key, same
  201, exactly one record. Verified live 2026-08-22. Any note claiming otherwise
  is out of date.
- **`relationships()->end`/`revert` require a non-blank `reason`**, enforced
  client-side (`AgreelyConfigError`) before any wire call (`Relationships.php:43-48`,
  `80-85`). In the CLI a missing `--reason` is a clean exit 2, never a silent
  write. Note: the SDK's own `revert` error string cites "art. 11 / art. 28".
  **Ignore the art. 28 half.** Art. 28 is the *person's* rectification right; a
  company correcting its own mistaken attestation is art. 11 al. 1, not art. 28.
- **`relationships()->end` 404s when there is no stored customer.** A subject
  whose cells are all `necessity` has no row to end. `AgreelyNotFoundError`,
  message `No customer with that reference.` Treat it as a no-op.
- **Never normalize labels; never cache an allow.** The SDK sends category/purpose
  raw and holds no allow-cache; both are correctness requirements, not
  conveniences (`Agreely.php:27-37`, `195-199`). For the latency problem, use the
  allow-stripping blip pattern above.
- **`whoami` is least-disclosure.** `GET /v1/whoami` returns *scopes only*: no
  company id, no key name, no PII. Verified live:
  `{"scopes":["check","issue","attest","relationship"]}`.
- **The default 800ms timeout is a TOTAL budget** across up to 2 idempotent-read
  retries with jittered backoff, not per-attempt (`Transport.php:82-113`). It is
  too tight for the open internet; the deployed reference uses 8000ms with
  `maxRetries => 1`.
- **The 500-item batch cap and the 120/min rate limit are real**; see *Server
  limits* above. `checkFields` multiplies refs by fields.
- **`verify` `unavailable` is not `failed`.** Treat an unresolvable DID as
  inconclusive (retry / air-gap with local DID docs), never as a forgery
  (`ReceiptVerifier.php:532-558`, `verify.ts:66-73`).
- **A citizen receipt is at most `partial` offline** and its displayed cell labels
  are `unsupported`. There is **no server endpoint** that closes either gap
  today; do not promise one (`ReceiptVerifier.php:127-130`).
- **The default `did:web` resolver fetches a host taken FROM the receipt.** For
  UNTRUSTED receipts, inject your own `resolver` (or `--did-doc`) to control the
  request surface. It is HTTPS-only and can never yield a false "verified"
  (`ReceiptVerifier.php:30-33`).
- **CLI `--version` prints `0.2.0`** (the `VERSION` constant in `cli.ts:31`),
  matching `package.json`.
- **PHPStan: `src/` is clean at level max**, at the default 128M memory limit, no
  flag needed. Two TEST files (`test/Unit/CheckBatchTest.php`,
  `test/Unit/IssuanceVectorTest.php`) trip offset-on-mixed errors because they
  read decoded-JSON vectors loosely. The shipping library passes; that is a
  dev-test nit, not a library defect.

## The TypeScript SDK (short: the CLI wraps it)

The CLI is a thin shell over `@agreely/sdk`; if you are in the JS/TS ecosystem,
use it directly: same contract, same golden vectors, one extra gate.

```ts
import { Agreely } from "@agreely/sdk";
const agreely = new Agreely({ apiKey: process.env.AGREELY_API_KEY! });
if (await agreely.check("cust_8812", "Phone number", "Billing")) { /* ... */ }
const v = await Agreely.verifyReceipt(receipt);   // same offline-first verifier
```

Exports (`agreely-sdk/src/index.ts`): `Agreely`, `ReceiptVerifier`, `BreakGlass`
(the third degrade gate, viable in a long-lived Node process and absent from PHP
v1), and the typed errors: `AgreelyError`, `AgreelyAuthError`,
`AgreelyValidationError`, `AgreelyNotFoundError`, `AgreelyBillingInactiveError`,
`AgreelyRateLimitError`, `AgreelyUnavailableError`, `AgreelyTimeoutError`,
**`AgreelyAbortError`** (no PHP twin; it surfaces an aborted fetch), and
`AgreelyConfigError`. Everything else keeps parity with the PHP SDK. Prefer PHP
for a PHP app; prefer the CLI for a shell/agent; prefer the TS SDK for Node.

The TS `Status` union carries the same drift as the PHP one; see *Known SDK
drift*.

## References

- Live API: `https://api.agreely.ca` (paths under `/v1`, **14 routes**);
  Verifier: `https://verify.agreely.ca` (`GET /` and `GET /batch` only); Owner
  app: `https://app.agreely.ca`; Product: `https://agreely.ca`
- **Wire contract of record**: the app's `openapi.yaml`. It has carried all eight
  statuses and the `basis` field since 2026-07-30.
- On-chain: Base **mainnet** (chainId 8453), AgreelyRegistry
  `0x1E3121CFB5dfE1ac0b0265790D2bdA709725cF8B`; Base Sepolia (84532) opt-in.
- PHP SDK source: `~/www/agreely-sdk-php` (`agreely/sdk`, Packagist; repo
  `github.com/agreely-protocol/sdk-php`).
- CLI source: `~/www/agreely-cli` (`@agreely/cli`).
- TS SDK: `~/www/agreely-sdk` (`@agreely/sdk`;
  `https://github.com/agreely-protocol/sdk`), same contract, same golden vectors
  (`vectors/vectors.json`).
- **The deployed reference integration**: `~/www/agreely-boreale-demo`
  (`app/Models/Boreale/`). A full CRM gated against the live API, with the
  six-way basis taxonomy, the seven render states and the allow-stripping blip
  cache. Read it before inventing a gating layer.
- Statutes: P-39.1 (private) and A-2.1 (public). Quebec health-sector law
  (R-22.1) is **out of scope** for Agreely and for this skill.
- Cookie banner work belongs to the **`agreely-cookies`** skill, not this one.
- In-repo examples: `examples/agreely/`, a zero-network PHP offline
  receipt-verify (runs against the bundled vector), a source-derived PHP consent
  gate, a live `check` script, and a CLI `--json` agent-scriptable shell example.
