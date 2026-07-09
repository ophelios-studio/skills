# Agreely example

Minimal runnable examples for the **Agreely PHP SDK** (`agreely/sdk`) and the
**Agreely CLI** (`@agreely/cli`). Agreely is a Law 25 (Loi 25 / Quebec) consent
layer: it **records and verifies consent and produces signed receipts**. It does
**not** certify that your organization is compliant.

## Layout

```
verify-receipt-offline.php   # PHP: offline receipt verification, ZERO network, ZERO key. RUNS as-is.
check-consent.php            # PHP: gate an action on a LIVE /v1 consent check (needs a real API key)
cli-agent-scriptable.sh      # CLI: --json + the exit-code contract, the agent path
composer.json                # pulls `agreely/sdk`
receipt.json                 # a bundled golden-vector company-attested receipt (for the CLI verify demo)
issuer-did.json              # the receipt's issuer DID document (for --did-doc air-gapped verify)
```

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
  companySignature  : pass
  cellLabelBinding  : pass
  ...
== tampered receipt (item category mutated) ==
  overall           : failed
  companySignature  : fail
  cellLabelBinding  : fail
OK: genuine receipt verified, tampered receipt rejected (all offline, no key).
```

**Honesty is the point.** A company-attested receipt can reach `verified`; a
citizen receipt is at most `partial` offline (the company half signed the
original offer, omitted for unlinkability). `unavailable` (a DID could not be
resolved) is inconclusive, NOT a `failed` tamper. A verified company signature
proves the company ATTESTED to a signed PDF; it never proves a human signed.

## The live consent gate (needs a real key)

`check-consent.php` makes a REAL `POST /v1/check` call, so it needs an API key
with the `check` scope. It is source-derived from the SDK (`Agreely.php:202`,
`:276`) and its tests; with no key it stops cleanly (exit 2) rather than faking a
response.

```bash
export AGREELY_API_KEY=agr_live_xxx
php check-consent.php cust_8812 "Phone number" "Billing"
# ALLOW / DENY line + the reasoned form (decision, status, consentRef, assurance)
```

Send `category`/`purpose` **raw** (as declared in your catalog); the server
normalizes (case, whitespace, accents, FR|EN). ALLOW is the only `true`; a deny
is a normal result; an outage fails **closed**.

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

Exit-code contract (from the CLI's `errors.ts`): `0` allow / verified·partial,
`10` deny, `2` usage·validation, `3` auth, `4` outage or unresolvable-DID, `5`
rate-limited, `6` verify-failed (tamper), `1` uncategorized. In agent mode stdout
is pure JSON and every error is a `{"error":{"code","message"}}` envelope on
stderr.

## Reference

- Skill: `skills/agreely/SKILL.md` (the full empirical reference)
- PHP SDK: `agreely/sdk` on Packagist; source `~/www/agreely-sdk-php`
- CLI: `@agreely/cli` on npm; source `~/www/agreely-cli`
- TS SDK: `@agreely/sdk` on npm; source `~/www/agreely-sdk`
- Live API `https://api.agreely.ca` (paths under `/v1`) · Verifier
  `https://verify.agreely.ca` · Owner app `https://app.agreely.ca`
- On-chain: Base mainnet (chainId 8453), AgreelyRegistry
  `0x1E3121CFB5dfE1ac0b0265790D2bdA709725cF8B`
