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

// baseUrl defaults to https://api.agreely.ca. timeout is a TOTAL budget in ms.
$agreely = new Agreely([
    'apiKey'  => $apiKey,
    'timeout' => 1200,
]);

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
$d = $agreely->checkDetailed($customerId, $category, $purpose);
echo "  decision   : {$d->decision}\n";
echo "  status     : {$d->status}\n";              // active | none | revoked | expired | erased | relationship_ended
echo "  consentRef : " . ($d->consentRef ?? '(none)') . "\n";
echo "  assurance  : " . ($d->assurance ?? '(none)') . "\n";
echo "  checkedAt  : {$d->checkedAt}\n";

// Exit 0 on allow, 10 on deny (mirrors the CLI's allow/deny exit contract).
exit($allowed ? 0 : 10);
