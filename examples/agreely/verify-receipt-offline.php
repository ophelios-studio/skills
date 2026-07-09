<?php

// Zero-network, zero-key offline receipt verification with Agreely::verifyReceipt.
//
// This is the headline capability: verify a signed consent receipt OFFLINE. It
// needs no API key and (with an injected resolver) no network at all. We drive it
// against the SHARED golden vectors bundled in the PHP SDK
// (agreely-sdk-php/vectors/vectors.json), the very file the PHP and TS unit
// suites assert byte-for-byte, so the output here is the real contract output.
//
// It prints the honest per-field verdict for two receipts, then proves a tamper
// is caught: mutating an item's category breaks the company Ed25519 signature.
//
// Run:
//   composer require agreely/sdk        # or point AGREELY_SDK_PHP at a local checkout
//   php verify-receipt-offline.php
//
// Resolution order for the SDK autoloader + the vectors file:
//   1. AGREELY_SDK_PHP env var (a local agreely-sdk-php checkout), or
//   2. ./vendor/autoload.php next to this script (composer require agreely/sdk),
//   3. ~/www/agreely-sdk-php (the canonical local checkout).

declare(strict_types=1);

use Agreely\Sdk\Agreely;

$sdkRoot = getenv('AGREELY_SDK_PHP')
    ?: (is_file(__DIR__ . '/vendor/autoload.php') ? __DIR__ : (getenv('HOME') . '/www/agreely-sdk-php'));

$autoload = rtrim($sdkRoot, '/') . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Could not find the Agreely PHP SDK autoloader.\n"
        . "Run `composer require agreely/sdk` in this directory, or set\n"
        . "AGREELY_SDK_PHP to an agreely-sdk-php checkout (tried: {$autoload}).\n");
    exit(2);
}
require $autoload;

// The bundled golden vectors ship with the SDK; they are self-contained (DID
// documents + IPFS body included) so this whole example is offline.
$vectorsPath = rtrim($sdkRoot, '/') . '/vectors/vectors.json';
$vectors = json_decode((string) file_get_contents($vectorsPath), true);
$rv = $vectors['receiptVerification'];
$didDocuments = $rv['fixtures']['didDocuments'];

// A resolver that returns the bundled DID documents and nothing else => ZERO
// network. For an UNTRUSTED receipt you always want to control this seam rather
// than let the default did:web resolver reach out to a host named in the receipt.
$resolver = static fn (string $did): ?array => $didDocuments[$did] ?? null;

function printVerdict(string $title, \Agreely\Sdk\Verify\ReceiptVerification $v): void
{
    echo "== {$title} ==\n";
    echo "  overall           : {$v->overall}\n";
    echo "  receiptType       : {$v->receiptType}\n";
    echo "  companySignature  : {$v->companySignature}\n";
    echo "  citizenAssertion  : {$v->citizenAssertion}\n";
    echo "  disclosureCopy    : {$v->disclosureCopy}\n";
    echo "  documentAnchor    : {$v->documentAnchor}\n";
    echo "  cellLabelBinding  : {$v->cellLabelBinding}\n\n";
}

// 1) A genuine company-attested receipt: fully offline-sound, overall "verified".
$company = $rv['cases'][0]['receipt'];
$genuine = Agreely::verifyReceipt($company, ['resolver' => $resolver]);
printVerdict('genuine company-attested receipt', $genuine);

// 2) The same receipt, tampered: mutate an item category. The Ed25519 signature
//    over the JCS-canonicalized body now fails => overall "failed". A verified
//    company receipt proves the COMPANY ATTESTED to a signed PDF; it never proves
//    a human signed. Agreely records/verifies consent; it does not certify
//    compliance.
$tampered = $company;
$tampered['credentialSubject']['consent']['items'][0]['category'] = 'HACKED';
$bad = Agreely::verifyReceipt($tampered, ['resolver' => $resolver]);
printVerdict('tampered receipt (item category mutated)', $bad);

// Assert the expected outcomes so this doubles as a smoke test (exit 0 on pass).
$ok = $genuine->overall === 'verified'
    && $genuine->companySignature === 'pass'
    && $bad->overall === 'failed'
    && $bad->companySignature === 'fail';

if (!$ok) {
    fwrite(STDERR, "UNEXPECTED verdicts; the SDK contract may have drifted.\n");
    exit(1);
}

echo "OK: genuine receipt verified, tampered receipt rejected (all offline, no key).\n";
