#!/usr/bin/env bash
#
# Agent-scriptable Agreely CLI: --json output + the exit-code contract.
#
# The CLI's whole value for an agent is machine output plus STABLE exit codes.
# In agent mode (--json, or any non-TTY stdout) it prints PURE JSON to stdout,
# writes a {"error":{"code","message"}} envelope to stderr, and never prompts.
# Branch on the EXIT CODE; do not scrape prose.
#
# Exit-code contract (from the CLI's errors.ts):
#   0  success / check ALLOW / receipt verified|partial
#   2  usage or validation (bad args, no credentials, 400/422, not-found)
#   3  auth (401/403: key missing/invalid/revoked or wrong scope)
#   4  unavailable (an Agreely outage, OR a verify that could not complete)
#   5  rate-limited (429)
#   6  verify-failed (a receipt did NOT verify: a real tamper)
#  10  check DENY (a clean, expected negative: NOT an error)
#   1  uncategorized failure
#
# Usage:
#   export AGREELY_API_KEY=agr_live_xxx        # the ONLY setup an agent needs
#   ./cli-agent-scriptable.sh
#
# The `agreely` binary is `@agreely/cli` (npm i -g @agreely/cli). To run without
# installing, set AGREELY_BIN to a built bin.js, e.g.:
#   AGREELY_BIN="node /path/to/agreely-cli/dist/bin.js" ./cli-agent-scriptable.sh
set -u

AGREELY="${AGREELY_BIN:-agreely}"

# ---------------------------------------------------------------------------
# 1) Gate an action on a consent check. --json => pure JSON on stdout.
#    ALLOW -> exit 0, DENY -> exit 10 (a clean negative), outage -> exit 4.
# ---------------------------------------------------------------------------
gate_on_consent() {
  local customer="$1" category="$2" purpose="$3"
  local out rc
  out="$($AGREELY check "$customer" "$category" "$purpose" --json)"
  rc=$?
  case "$rc" in
    0)  echo "ALLOW: $out";  return 0 ;;   # proceed with the data use
    10) echo "DENY:  $out";  return 10 ;;  # a real refusal; do NOT proceed
    4)  echo "OUTAGE (fail-closed): treat as deny" >&2; return 4 ;;
    3)  echo "AUTH: check AGREELY_API_KEY / scope" >&2; return 3 ;;
    5)  echo "RATE-LIMITED: back off and retry" >&2;    return 5 ;;
    *)  echo "unexpected exit $rc: $out" >&2;           return "$rc" ;;
  esac
}

# The category/purpose are sent RAW; the server normalizes (FR|EN, case, space).
gate_on_consent "cust-42" "Email Address" "Marketing Outreach"
echo "check exit: $?"
echo

# ---------------------------------------------------------------------------
# 2) Batch-check many cells in one call. Feed a JSON array on --batch. Exit 0
#    only if ALL allow; exit 10 if ANY deny. Great for a fan-out gate.
# ---------------------------------------------------------------------------
cat > /tmp/agreely-batch.json <<'JSON'
[
  { "customerRef": "cust-42", "category": "Email Address",  "purpose": "Marketing Outreach" },
  { "customerRef": "cust-42", "category": "Postal Address", "purpose": "Shipping" }
]
JSON
$AGREELY check --batch /tmp/agreely-batch.json --json
echo "batch exit: $? (0 = all allow, 10 = any deny)"
echo

# ---------------------------------------------------------------------------
# 3) Verify a consent receipt OFFLINE-FIRST. No key needed. Supply the DID
#    document locally with --did-doc for a fully AIR-GAPPED verify (no network).
#      overall verified|partial -> exit 0
#      overall failed (tamper)   -> exit 6
#      overall unavailable (DID) -> exit 4
#    This block runs only if a receipt + did file are present next to the script.
# ---------------------------------------------------------------------------
here="$(cd "$(dirname "$0")" && pwd)"
if [ -f "$here/receipt.json" ] && [ -f "$here/issuer-did.json" ]; then
  $AGREELY verify "$here/receipt.json" --did-doc "$here/issuer-did.json" --json
  echo "verify exit: $? (0 verified/partial, 6 tamper, 4 unresolvable)"
else
  echo "verify demo skipped: drop receipt.json + issuer-did.json next to this script."
  echo "(the PHP example verify-receipt-offline.php runs a bundled vector with zero setup.)"
fi

# ---------------------------------------------------------------------------
# Honest framing: Agreely RECORDS and verifies consent and produces signed
# receipts. It does NOT certify that your organization is compliant.
# ---------------------------------------------------------------------------
