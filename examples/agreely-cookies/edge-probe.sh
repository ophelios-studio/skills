#!/usr/bin/env bash
# Probe the Agreely cookie EDGE for one site key and report the HONEST state.
#
#   ./edge-probe.sh agr_pub_xxxxxxxx [https://yourdomain.com] [https://cdn.agreely.ca]
#
# Read-only. It does exactly one GET of the public config plus one deliberately
# refused consent POST (a never-published config hash), so it records nothing.
# Everything it prints is from the wire, not from documentation.
set -uo pipefail

KEY="${1:-}"
ORIGIN="${2:-}"
BASE="${3:-https://cdn.agreely.ca}"
BASE="${BASE%/}"

if [ -z "$KEY" ]; then
  echo "usage: $0 <agr_pub_sitekey> [https://your-registered-origin] [edge base url]" >&2
  exit 2
fi

hr() { printf '%s\n' '--------------------------------------------------------------'; }

# ---------------------------------------------------------------- 1. the config
hr; echo "GET $BASE/e/v1/config/$KEY"; hr
HEADERS=$(curl -sS -D - -o /tmp/agr-config.json --max-time 15 "$BASE/e/v1/config/$KEY")
printf '%s\n' "$HEADERS" | grep -iE '^(HTTP/|cache-control|access-control-allow-origin|etag)' | tr -d '\r'

python3 - <<'PY'
import json
try:
    c = json.load(open('/tmp/agr-config.json'))
except Exception as e:
    print('  could not parse the config:', e); raise SystemExit(1)

active = c.get('active') is True
cats = [x for x in c.get('categories', []) if not x.get('essential')]
print()
print('  active            :', active, '(false = the client renders NOTHING; this is the serving gate)')
print('  configVersion     :', c.get('configVersion'))
print('  configHash        :', c.get('configHash'))
print('  branding          :', c.get('branding'), '("agreely" = free tier, the mark is unremovable)')
print('  defaultLocale     :', c.get('defaultLocale'))
print('  consentTtlDays    :', c.get('consentTtlDays'))
print('  position          :', c.get('position'))
print('  non-essential     :', [x.get('key') for x in cats] or '(none: no consent is solicited)')
print('  cookieTable rows  :', len(c.get('cookieTable', [])))
print('  policyLinks       :', len(c.get('policyLinks', [])), '(art. 8.2: >= 1 is required to publish once consent is solicited)')
d = c.get('disclosures') or {}
print('  crosses_border    :', d.get('crosses_border'))
print('  profiling         :', d.get('profiling'), d.get('profiling_vendors') or '')
print('  audience_minors   :', d.get('audience_minors'), '(art. 4.1: suppresses the one-click accept-all)')
print('  collects_ip       :', d.get('collects_ip'))
print('  trackerHosts      :', c.get('trackerHosts'))
if not active:
    print()
    print('  active:false means ONE of: domain not verified, site removed/suspended,')
    print('  every site key revoked, entitlement lapsed, or NOTHING PUBLISHED YET.')
    print('  The most common cause by far is the last one: a verified site with an')
    print('  installed tag and no published banner version still serves active:false.')
    print('  Open the dashboard Publishing page; it names the exact reason.')
PY

# ---------------------------------------------------- 2. the origin binding
if [ -n "$ORIGIN" ]; then
  hr; echo "POST $BASE/e/v1/consent   Origin: $ORIGIN   (deliberately refused)"; hr
  NEVER='0xdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef'
  BODY="{\"siteKey\":\"$KEY\",\"action\":\"grant\",\"decisions\":{},\"configHash\":\"$NEVER\",\"configVersion\":1,\"locale\":\"fr\"}"
  OUT=$(curl -sS -o /tmp/agr-consent.json -D /tmp/agr-consent.head -w '%{http_code}' --max-time 15 \
        -X POST "$BASE/e/v1/consent" -H 'Content-Type: text/plain' -H "Origin: $ORIGIN" --data-binary "$BODY")
  ACAO=$(grep -i '^access-control-allow-origin' /tmp/agr-consent.head | tr -d '\r')
  echo "  status : $OUT   body: $(cat /tmp/agr-consent.json)"
  echo "  ${ACAO:-(no Access-Control-Allow-Origin: this origin is NOT bound to the key)}"
  echo
  case "$OUT" in
    409) echo "  409 stale_config is the GOOD answer here: the origin passed and the"
         echo "  key resolved, and the endpoint refused only the fake config hash." ;;
    403) echo "  403 with NO ACAO header. Either the origin is not the registered"
         echo "  domain (nor one of its subdomains, nor a declared extra origin), or"
         echo "  the site is not verified, or the key is revoked. Read the code:"
         echo "    origin_mismatch     -> add the origin, or fix data-agreely"
         echo "    website_not_active  -> the domain is not verified / was removed"
         echo "    unknown_site        -> the key does not resolve or was revoked" ;;
    429) echo "  429 rate_limited. Per truncated IP: 60/min. Per site key: 600/min." ;;
  esac
fi

hr
echo "Agreely helps DOCUMENT and DEMONSTRATE a Law 25 consent practice."
echo "It does not certify that your organization is compliant."
hr
