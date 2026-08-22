# Agreely example

Minimal runnable examples for the **Agreely PHP SDK** (`agreely/sdk`) and the
**Agreely CLI** (`@agreely/cli`). Agreely is a Quebec consent-accountability
layer: it **documents and verifies consent and produces signed receipts**. It
does **not** certify that your organization is compliant, and it gives no legal
advice.

Article numbers in these examples are **P-39.1** (the private-sector act). Loi 25
(L.Q. 2021, c. 25) is the *amending* statute; public bodies are governed by
**A-2.1**, where every article number differs. See `skills/agreely/SKILL.md` for
the twin table.

## Layout

```
verify-receipt-offline.php   # PHP: offline receipt verification, ZERO network, ZERO key. RUNS as-is.
integrate-crm-gating.php     # PHP: a CRM ConsentGate (checkFields) filtering a client list. RUNS as-is (stub transport).
check-consent.php            # PHP: gate an action on a LIVE /v1 consent check (needs a real API key)
cli-agent-scriptable.sh      # CLI: --json + the exit-code contract, the agent path
composer.json                # pulls `agreely/sdk` ^0.2 (0.1 lacks hasPending + the customerId filter)
receipt.json                 # a bundled golden-vector company-attested receipt (for the CLI verify demo)
issuer-did.json              # the receipt's issuer DID document (for --did-doc air-gapped verify)
```

## The CRM gating recipe (runs with nothing but PHP)

`integrate-crm-gating.php` is the integration model an app uses: it gates every
personal-data VALUE behind a check and filters the view-model before the template
sees it. A `ConsentGate` helper issues ONE batch call (`checkFields`) for a client
list, keeps a value only when `decision === "allow"`, and otherwise omits an
optional consent field (the marketing email) or shows a bilingual placeholder
("Refusé par Agreely" / "Refused by Agreely"). It derives a per-client "did not
consent" summary itself (there is no rollup endpoint in the SDK or the API).

To run offline with no key it injects a stub `HttpClient` (a public SDK seam) that
**reproduces the server's own no-record decision rule** (`CheckService::decideNoRecord`),
so what you see is the real model, not a hand-scripted fiction. The live wiring (a
real key, no stub) is shown commented at the bottom.

```bash
AGREELY_SDK_PHP=~/www/agreely-sdk-php php integrate-crm-gating.php
```

Real output (captured 2026-08-22, PHP 8.5.6):

```
Client list (view-model after gating):
  id   newsletter_email          billing_name  rep_work_email         summary
  1    ana@example.com           Ana Roy       a.roy@acme.example     ok
  2    (omitted)                 Ben Côté      b.cote@acme.example    none
  3    (omitted)                 Cara Lam      c.lam@acme.example     none
  4    (omitted)                 Dan Kim       d.kim@acme.example     none

Per-cell status (why each value rendered or not):
  cli_1: newsletter_email=active  billing_name=necessity  rep_work_email=necessity
  cli_2: newsletter_email=revoked  billing_name=necessity  rep_work_email=necessity
  cli_3: newsletter_email=none  billing_name=necessity  rep_work_email=necessity
  cli_4: newsletter_email=none  billing_name=necessity  rep_work_email=necessity

OK: allow is the only true, necessity allows with no record, sensitive fails
    closed on its own status, and an outage hides every CONSENT value while
    non-consent values keep rendering (you lose the access-log entry, though).
```

**Read the `status` column, it is the whole lesson.** `cli_1` consented, so the
newsletter address renders (`active`). `cli_2` withdrew (`revoked`) and `cli_3`
never signed (`none`), so it is omitted. `cli_4` is **brand new: Agreely has never
heard of it**, and its billing name and its representative's work email still
render, on `status: "necessity"`, because those catalog cells declare a
non-consent lawful basis. **No record exists for them, none is needed, and nothing
is written.** There is no "provision every client first" step, and calling
`manualConsents()->record()` on such a cell would fabricate a company-attested
consent asserting a signed PDF that does not exist.

The script also proves two more things: a **sensitive** cell with no record fails
closed on its own `sensitive_requires_consent` status rather than riding a
necessity basis; and on a **transport failure** every consent value is hidden
while the non-consent values keep rendering, which is what the deployed reference
integration does, because a failed check carries no information about a basis that
is not consent. (You do lose the access-log entry for that render, which is an
honest gap, not something to paper over.)

**Honest framing:** gating plus the access log is EVIDENCE supporting the art. 3.1
responsibility and art. 3.2 governance duties. It is not a certification of
compliance and not legal advice; have privacy counsel confirm each field's basis.

## The offline verifier (runs with nothing but PHP)

`verify-receipt-offline.php` needs **no API key and no network**. It drives
`Agreely::verifyReceipt` against the SDK's own shared golden vectors (the very
file the PHP + TS unit suites assert), so its output is the real contract output.
It prints the honest per-field verdict, then proves a tamper is caught.

```bash
# Option A: a local SDK checkout (no install)
AGREELY_SDK_PHP=~/www/agreely-sdk-php php verify-receipt-offline.php

# Option B: composer install here, then run
composer require agreely/sdk
php verify-receipt-offline.php
```

Real output (captured, PHP 8.5.6):

```
== genuine company-attested receipt ==
  overall           : verified
  receiptType       : company_attested
  companySignature  : pass
  citizenAssertion  : unsupported
  disclosureCopy    : skipped
  documentAnchor    : skipped
  cellLabelBinding  : pass

== tampered receipt (item category mutated) ==
  overall           : failed
  receiptType       : company_attested
  companySignature  : fail
  citizenAssertion  : unsupported
  disclosureCopy    : skipped
  documentAnchor    : skipped
  cellLabelBinding  : fail

OK: genuine receipt verified, tampered receipt rejected (all offline, no key).
```

**Honesty is the point.** A company-attested receipt can reach `verified`; a
citizen receipt is at most `partial` offline (the company half signed the
original offer, omitted for unlinkability). `unavailable` (a DID could not be
resolved) is inconclusive, NOT a `failed` tamper. A verified company signature
proves the company ATTESTED to a signed PDF; it never proves a human signed.

**There is no server verify endpoint.** A citizen receipt's company signature and
its cell labels are `unsupported` offline, and there is **no** `receipts/verify`
route on any Agreely surface today that closes either gap. Do not trust the
displayed category/purpose labels off a `partial` citizen receipt, and do not
promise anyone a server call that would.

## The live consent gate (needs a real key)

`check-consent.php` makes a REAL `POST /v1/check` call, so it needs an API key
with the `check` scope. With no key it stops cleanly (exit 2) rather than faking a
response.

```bash
export AGREELY_API_KEY=agr_live_xxx
php check-consent.php cust_8812 "Phone number" "Billing"
```

Point it at a **non-production** stack with `AGREELY_BASE_URL` (the SDK default is
`https://api.agreely.ca`), so a doc run never touches production:

```bash
AGREELY_BASE_URL=http://localhost:8081 \
  php check-consent.php cli_1 "Coordonnées" "Gestion du dossier client"
```

Real output (captured 2026-08-22 against a local stack, on a customer reference
with no records at all):

```
endpoint   : http://localhost:8081
ALLOW  skill-doc-fresh-001 | Coordonnées / Gestion du dossier client
  decision   : allow
  status     : necessity
  consentRef : (none)
  assurance  : (none)
  checkedAt  : 2026-08-22T17:23:35Z
  basis      : necessary_for_service
# exit 0

endpoint   : http://localhost:8081
DENY   skill-doc-fresh-001 | Coordonnées / Communications marketing
  decision   : deny
  status     : none
  consentRef : (none)
  assurance  : (none)
  checkedAt  : 2026-08-22T17:23:35Z
# exit 10
```

Send `category`/`purpose` **raw** (as declared in your catalog); the server
normalizes (case, whitespace, accents, FR|EN). `decision === "allow"` is the only
true; **never gate on `status`**, because `active` and `necessity` both allow and
a locally degraded fail-open reports `active` without having checked anything. A
deny is a normal result; an outage fails **closed**.

The `basis` line prints only when your installed SDK carries it: the published
0.2.0 drops it from `CheckResult` even though the wire has it.

## The CLI, agent-scriptable

`cli-agent-scriptable.sh` shows the agent path: `--json` output and branching on
the **exit code** (never scraping prose). It gates on a check, runs a batch
check, and verifies the bundled receipt air-gapped.

```bash
export AGREELY_API_KEY=agr_live_xxx        # the only setup an agent needs
./cli-agent-scriptable.sh

# run without a global install, against a built bin:
AGREELY_BIN="node ~/www/agreely-cli/dist/bin.js" ./cli-agent-scriptable.sh
```

The `verify` block runs with **zero setup and no key** because `receipt.json` +
`issuer-did.json` are bundled here (extracted from the SDK golden vectors).
Captured output of that block:

```
{"receiptType":"company_attested","companySignature":"pass",...,"overall":"verified"}
verify exit: 0
```

Exit-code contract (from the CLI's `errors.ts`):

| code | meaning |
|---|---|
| `0` | success / check **ALLOW** (`active` OR `necessity`) / receipt `verified` or `partial` |
| `2` | usage or validation (bad args, no credentials, 400/422, not-found) |
| `3` | auth (401/403) |
| `4` | outage, or a `verify` that could not complete (unresolvable DID) |
| `5` | rate-limited (429) |
| `6` | verify-failed: a real tamper. A verdict, not an error |
| `7` | **billing-inactive** (HTTP 402): the company's Agreely subscription lapsed. NOT transient, NOT retryable; every gate is fail-closed until it is paid. Page a human |
| `10` | check **DENY**: a clean, expected negative |
| `1` | uncategorized failure |

The script branches on all of them, including **7**, which is a real production
condition rather than a surprise. In agent mode stdout is pure JSON and every
error is a `{"error":{"code","message"}}` envelope on stderr.

## Reference

- Skill: `skills/agreely/SKILL.md` (the full empirical reference). For a **cookie
  banner** instead, see the separate `agreely-cookies` skill.
- Deployed reference integration: `~/www/agreely-boreale-demo` (`app/Models/Boreale/`)
- PHP SDK: `agreely/sdk` on Packagist; source `~/www/agreely-sdk-php`
- CLI: `@agreely/cli` on npm; source `~/www/agreely-cli`
- TS SDK: `@agreely/sdk` on npm; source `~/www/agreely-sdk`
- Live API `https://api.agreely.ca` (paths under `/v1`) · Verifier
  `https://verify.agreely.ca` · Owner app `https://app.agreely.ca`
- On-chain: Base mainnet (chainId 8453), AgreelyRegistry
  `0x1E3121CFB5dfE1ac0b0265790D2bdA709725cF8B`
