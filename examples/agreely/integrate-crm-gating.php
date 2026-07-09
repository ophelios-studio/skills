<?php

// Memento-style CRM integration: gate every personal-data VALUE behind a check,
// then filter the view-model before the template sees it.
//
// The pattern: for a client LIST (or a detail view), issue ONE batch consent call
// (checkFields), then rebuild the rows so a REFUSED value never renders as the
// real value. A refused ESSENTIAL field shows a bilingual placeholder
// ("Refusé par Agreely" / "Refused by Agreely"); a refused OPTIONAL consent field
// (e.g. a marketing email) is omitted. FAIL CLOSED: any error hides every gated
// value across the whole view.
//
// FAITHFUL to the real SDK: it uses Agreely::checkFields (Agreely.php:256) and
// reads BatchDecision->status for the reason (BatchDecision.php). To keep the
// example RUNNABLE with no API key and no network, it injects a stub HttpClient
// (a public SDK seam) that returns scripted /v1/check/batch decisions. In a real
// app you pass a real apiKey and NO httpClient, and the call hits the live
// https://api.agreely.ca. The live wiring is shown (commented) at the bottom.
//
// HONEST Law 25: gating + the access log is how you DEMONSTRATE accountability
// (art. 3.1); Agreely records the basis the enterprise declared in its catalog.
// It does NOT certify compliance. Have privacy counsel confirm each field's basis.
//
// Run:
//   AGREELY_SDK_PHP=~/www/agreely-sdk-php php integrate-crm-gating.php
//   # or: composer require agreely/sdk && php integrate-crm-gating.php

declare(strict_types=1);

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Errors\AgreelyError;
use Agreely\Sdk\Http\HttpClient;
use Agreely\Sdk\Http\RawResponse;
use Agreely\Sdk\Types\BatchDecision;

$sdkRoot = getenv('AGREELY_SDK_PHP')
    ?: (is_file(__DIR__ . '/vendor/autoload.php') ? __DIR__ : (getenv('HOME') . '/www/agreely-sdk-php'));
$autoload = rtrim($sdkRoot, '/') . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Could not find the Agreely PHP SDK autoloader.\n"
        . "Run `composer require agreely/sdk`, or set AGREELY_SDK_PHP to a checkout.\n");
    exit(2);
}
require $autoload;

// ---------------------------------------------------------------------------
// A stub HttpClient so the example runs offline with no key. It answers
// POST /v1/check/batch from a fixed table of (customerRef, category, purpose)
// -> decision. This is the ONLY illustrative seam; everything else is the real
// SDK. In production you do NOT do this; you pass a real apiKey instead.
// ---------------------------------------------------------------------------
final class ScriptedConsent implements HttpClient
{
    /** @param array<string,array{decision:string,status:string}> $table keyed "ref|category|purpose" */
    public function __construct(private readonly array $table) {}

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): RawResponse
    {
        $payload = $body !== null ? json_decode($body, true) : [];
        $decisions = [];
        foreach (($payload['items'] ?? []) as $item) {
            $key = "{$item['customerRef']}|{$item['category']}|{$item['purpose']}";
            $hit = $this->table[$key] ?? ['decision' => 'deny', 'status' => 'none']; // fail closed default
            $decisions[] = [
                'customerRef' => $item['customerRef'],
                'category'    => $item['category'],
                'purpose'     => $item['purpose'],
                'decision'    => $hit['decision'],
                'status'      => $hit['status'],
                'consentRef'  => $hit['decision'] === 'allow' ? '0x' . str_repeat('ab', 32) : null,
                'checkedAt'   => '2026-07-09T00:00:00Z',
            ];
        }
        return new RawResponse(200, (string) json_encode(['decisions' => $decisions]));
    }
}

// ---------------------------------------------------------------------------
// Derive a client-level summary from a client's cells. No rollup endpoint exists
// in the SDK today, so the app computes this itself. Only consent-based cells
// count (essential cells are always-allow and would mask a real refusal).
// ---------------------------------------------------------------------------
/**
 * @param list<BatchDecision> $decisions all cells for ONE client
 * @param list<string> $consentPurposes the purposes whose basis is consent
 */
function clientConsentSummary(array $decisions, array $consentPurposes): string
{
    $consentCells = array_filter($decisions, fn ($d) => in_array($d->purpose, $consentPurposes, true));
    if ($consentCells === []) {
        return 'ok';
    }
    $refused = array_filter($consentCells, fn ($d) => !$d->isAllow());
    if (count($refused) === count($consentCells)) {
        return 'none';    // "Ce client n'a pas consenti" / "This client did not consent"
    }
    return $refused === [] ? 'ok' : 'partial';
}

// ---------------------------------------------------------------------------
// The ConsentGate helper. Gate a list of records: one batch call, filtered rows.
// ---------------------------------------------------------------------------
final class ConsentGate
{
    public const PLACEHOLDER_FR = 'Refusé par Agreely';
    public const PLACEHOLDER_EN = 'Refused by Agreely';

    public function __construct(
        private readonly Agreely $agreely,
        private readonly string $locale = 'fr',
    ) {}

    /**
     * @param list<array{id:string,subjectRef:string,values:array<string,string>}> $rows
     * @param list<array{key:string,category:string,purpose:string,basis:string}> $fields
     * @return list<array<string,mixed>>
     */
    public function gateList(array $rows, array $fields): array
    {
        $refs  = array_values(array_unique(array_map(fn ($r) => $r['subjectRef'], $rows)));
        $cells = array_map(fn ($f) => ['category' => $f['category'], 'purpose' => $f['purpose']], $fields);

        try {
            $matrix = $this->agreely->checkFields($refs, $cells); // ONE round-trip for the table
        } catch (AgreelyError) {
            // FAIL CLOSED: hide every gated value across every row.
            return array_map(fn ($row) => $this->blankRow($row, $fields), $rows);
        }

        $consentPurposes = array_values(array_map(
            fn ($f) => $f['purpose'],
            array_filter($fields, fn ($f) => $f['basis'] === 'consent'),
        ));

        $out = [];
        foreach ($rows as $row) {
            $decisions = [];
            foreach ($fields as $f) {
                $allowed = $matrix->isAllowed($row['subjectRef'], $f['category'], $f['purpose']);
                $row['values'][$f['key']] = $this->present($row['values'][$f['key']] ?? null, $allowed, $f['basis']);
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

    private function present(?string $value, bool $allowed, string $basis): ?string
    {
        if ($allowed) {
            return $value; // consent active OR essential (always-allow) -> the real value
        }
        return $basis === 'consent'
            ? null // an optional consent field is OMITTED on refusal
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

// ---------------------------------------------------------------------------
// DEMO: three CRM clients, two gated fields (one consent, one essential).
// ---------------------------------------------------------------------------
$fields = [
    ['key' => 'newsletter_email', 'category' => 'Adresse courriel', 'purpose' => 'Infolettre',   'basis' => 'consent'],
    ['key' => 'billing_name',     'category' => 'Nom',              'purpose' => 'Facturation',  'basis' => 'essential'],
];

$rows = [
    ['id' => '1', 'subjectRef' => 'cli_1', 'values' => ['newsletter_email' => 'ana@example.com',  'billing_name' => 'Ana Roy']],
    ['id' => '2', 'subjectRef' => 'cli_2', 'values' => ['newsletter_email' => 'ben@example.com',  'billing_name' => 'Ben Cote']],
    ['id' => '3', 'subjectRef' => 'cli_3', 'values' => ['newsletter_email' => 'cara@example.com', 'billing_name' => 'Cara Lam']],
];

// Scripted consent state (illustrative): cli_1 consented to newsletter; cli_2 and
// cli_3 did not. Billing name is essential -> always allowed. Essential is still
// gated so the read is logged (art. 3.1 accountability), it just always renders.
$table = [
    'cli_1|Adresse courriel|Infolettre' => ['decision' => 'allow', 'status' => 'active'],
    'cli_2|Adresse courriel|Infolettre' => ['decision' => 'deny',  'status' => 'revoked'], // withdrew
    'cli_3|Adresse courriel|Infolettre' => ['decision' => 'deny',  'status' => 'none'],    // never consented
    'cli_1|Nom|Facturation'             => ['decision' => 'allow', 'status' => 'active'],
    'cli_2|Nom|Facturation'             => ['decision' => 'allow', 'status' => 'active'],
    'cli_3|Nom|Facturation'             => ['decision' => 'allow', 'status' => 'active'],
];

$agreely = new Agreely([
    'apiKey'     => 'agr_live_demo',       // any non-empty value; the stub ignores it
    'httpClient' => new ScriptedConsent($table),
]);

$gate  = new ConsentGate($agreely, 'fr');
$gated = $gate->gateList($rows, $fields);

echo "Client list (view-model after gating):\n";
printf("  %-6s %-28s %-18s %s\n", 'id', 'newsletter_email', 'billing_name', 'summary');
foreach ($gated as $r) {
    printf(
        "  %-6s %-28s %-18s %s\n",
        $r['id'],
        $r['values']['newsletter_email'] ?? '(omitted)',   // refused optional consent field -> omitted
        $r['values']['billing_name'],                        // essential -> value OR placeholder
        $r['consentSummary'],
    );
}

// ---------------------------------------------------------------------------
// Assert the filtering is correct (doubles as a smoke test; exit 0 on pass).
// ---------------------------------------------------------------------------
$ok =
    // cli_1 consented -> real newsletter email shows
    $gated[0]['values']['newsletter_email'] === 'ana@example.com'
    && $gated[0]['consentSummary'] === 'ok'
    // cli_2 withdrew -> newsletter omitted, essential name still shows, summary "none"
    && $gated[1]['values']['newsletter_email'] === null
    && $gated[1]['values']['billing_name'] === 'Ben Cote'
    && $gated[1]['consentSummary'] === 'none'
    // cli_3 never consented -> same
    && $gated[2]['values']['newsletter_email'] === null
    && $gated[2]['consentSummary'] === 'none';

if (!$ok) {
    fwrite(STDERR, "UNEXPECTED gating result; the SDK contract may have drifted.\n");
    exit(1);
}

// Fail-closed proof: a throwing transport hides every value.
final class AlwaysDown implements HttpClient
{
    public function send(string $m, string $u, array $h, ?string $b, int $t): RawResponse
    {
        throw new \Agreely\Sdk\Http\TransportException('down', true);
    }
}
$down  = new Agreely(['apiKey' => 'agr_live_demo', 'httpClient' => new AlwaysDown()]);
$blank = (new ConsentGate($down, 'fr'))->gateList($rows, $fields);
$failClosed = $blank[0]['values']['newsletter_email'] === null                          // consent field omitted
    && $blank[0]['values']['billing_name'] === ConsentGate::PLACEHOLDER_FR              // essential -> placeholder
    && $blank[0]['consentSummary'] === 'none';

if (!$failClosed) {
    fwrite(STDERR, "FAIL-CLOSED path leaked a value.\n");
    exit(1);
}

echo "\nOK: consent gating filters the view-model, and an outage fails CLOSED (no value leaks).\n";

// ---------------------------------------------------------------------------
// LIVE wiring (do NOT run without a real key). No stub httpClient; a real key
// with the `check` scope; the call hits https://api.agreely.ca.
//
//   $agreely = new Agreely(['apiKey' => getenv('AGREELY_API_KEY')]);
//   $gated   = (new ConsentGate($agreely, 'fr'))->gateList($rows, $fields);
//
// For a DETAIL view, use checkBatch to read each cell's ->status for a richer
// per-field message (revoked vs expired vs none) instead of a boolean.
// ---------------------------------------------------------------------------
