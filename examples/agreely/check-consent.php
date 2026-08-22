<?php

// Gate an action on a LIVE Agreely consent check.
//
// This shows the one-call gate an app uses in production: instantiate the client
// with a key from the environment, then branch on check(). ALLOW is the only
// `true`; a 200 deny is `false` (not an error); an outage fails CLOSED (also
// `false`, never a throw). checkDetailed() is shown too, for when you want the
// reason (status / consentRef) behind a deny.
//
// This example makes a REAL /v1/check call, so it needs a real API key with the
// `check` scope. It is SOURCE-DERIVED from the SDK (Agreely.php:202, :276) and
// its unit tests; without a key it stops cleanly with exit 2 rather than
// pretending. It does NOT invent a request/response shape.
//
// Run:
//   composer require agreely/sdk        # or set AGREELY_SDK_PHP to a local checkout
//   export AGREELY_API_KEY=agr_live_xxx
//   php check-consent.php cust_8812 "Phone number" "Billing"
//
// Point it at a NON-production stack with AGREELY_BASE_URL (the SDK default is
// https://api.agreely.ca). Use this for a local or staging API so a doc run never
// touches production:
//   AGREELY_BASE_URL=http://localhost:8081 php check-consent.php cli_1 "Coordonnées" "Gestion du dossier client"
//
// Send category/purpose RAW (as declared in your catalog); the SERVER normalizes
// (case/whitespace/accents, FR|EN). Never normalize them yourself.

declare(strict_types=1);

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Errors\AgreelyAuthError;
use Agreely\Sdk\Errors\AgreelyRateLimitError;
use Agreely\Sdk\Errors\AgreelyValidationError;

$sdkRoot = getenv('AGREELY_SDK_PHP')
    ?: (is_file(__DIR__ . '/vendor/autoload.php') ? __DIR__ : (getenv('HOME') . '/www/agreely-sdk-php'));
$autoload = rtrim($sdkRoot, '/') . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Could not find the Agreely PHP SDK autoloader.\n"
        . "Run `composer require agreely/sdk`, or set AGREELY_SDK_PHP to a checkout.\n");
    exit(2);
}
require $autoload;

$apiKey = getenv('AGREELY_API_KEY') ?: '';
if ($apiKey === '') {
    fwrite(STDERR, "Set AGREELY_API_KEY to a key with the `check` scope, then re-run.\n");
    exit(2);
}

$customerId = $argv[1] ?? 'cust_8812';
$category   = $argv[2] ?? 'Phone number';
$purpose    = $argv[3] ?? 'Billing';

// baseUrl defaults to https://api.agreely.ca; AGREELY_BASE_URL overrides it so the
// same script can be pointed at a local/staging stack. timeout is a TOTAL budget
// in ms across retries: 800 (the SDK default) is too tight over the open internet,
// so this uses the same 8000 + one retry the deployed reference integration uses.
$options = [
    'apiKey'     => $apiKey,
    'timeout'    => 8000,
    'maxRetries' => 1,
];
$baseUrl = getenv('AGREELY_BASE_URL') ?: '';
if ($baseUrl !== '') {
    $options['baseUrl'] = $baseUrl;
}
$agreely = new Agreely($options);
echo "endpoint   : {$agreely->baseUrl()}\n";

// 1) The boolean gate. NEVER throws on an outage: it returns false (fail-closed).
try {
    $allowed = $agreely->check($customerId, $category, $purpose);
} catch (AgreelyAuthError $e) {
    fwrite(STDERR, "auth error ({$e->code}, HTTP {$e->status}): {$e->getMessage()}\n");
    exit(3);
} catch (AgreelyValidationError $e) {
    fwrite(STDERR, "invalid input (field={$e->field}): {$e->getMessage()}\n");
    exit(2);
} catch (AgreelyRateLimitError $e) {
    fwrite(STDERR, "rate limited; retry after {$e->retryAfter}s\n");
    exit(5);
}

echo ($allowed ? "ALLOW" : "DENY ") . "  {$customerId} | {$category} / {$purpose}\n";

// 2) The reasoned form, for the WHY behind a deny. On an outage this THROWS
//    AgreelyUnavailableError (unlike check()); catch it if you call this path.
//
//    status is an EIGHT-value enum and TWO of them ALLOW:
//      allow -> active     a per-subject consent record exists and is current
//      allow -> necessity  NO record exists and none is needed: the catalog cell
//                          declares a non-consent lawful basis. Carries `basis`,
//                          carries no consentRef and no assurance, creates nothing.
//      deny  -> none | revoked | expired | erased | relationship_ended
//               | sensitive_requires_consent
//    NEVER gate on status. `decision === "allow"` is the only true.
$d = $agreely->checkDetailed($customerId, $category, $purpose);
echo "  decision   : {$d->decision}\n";
echo "  status     : {$d->status}\n";
echo "  consentRef : " . ($d->consentRef ?? '(none)') . "\n";
echo "  assurance  : " . ($d->assurance ?? '(none)') . "\n";
echo "  checkedAt  : {$d->checkedAt}\n";
// `basis` (the declared ground behind a necessity allow) is on the wire but the
// published SDK 0.2.0 does not carry it on CheckResult. Read it off a raw call if
// you need it, and check your installed version: an SDK update adding it is in flight.
if (property_exists($d, 'basis') && $d->basis !== null) {
    echo "  basis      : {$d->basis}\n";
}

// Exit 0 on allow, 10 on deny (mirrors the CLI's allow/deny exit contract).
exit($allowed ? 0 : 10);
