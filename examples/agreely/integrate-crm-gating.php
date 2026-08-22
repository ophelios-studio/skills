<?php

// CRM integration: gate every personal-data VALUE behind a check, then filter the
// view-model before the template sees it.
//
// The pattern: for a client LIST (or a detail view), issue ONE batch consent call
// (checkFields), then rebuild the rows so a REFUSED value never renders as the real
// value. A refused OPTIONAL consent field (a marketing email) is omitted; a refused
// field that must still occupy its slot shows a bilingual placeholder
// ("Refusé par Agreely" / "Refused by Agreely").
//
// THE MODEL THIS ENCODES (the part integrators get wrong):
//
//   A catalog cell carries a DECLARED legal_basis. With no per-subject record:
//     - basis `consent`                       -> deny  / status "none"
//     - basis contract | necessary_for_service | security_fraud | legal_obligation
//       | professional_contact                -> ALLOW / status "necessity" + `basis`,
//                                                no consentRef, no assurance, and
//                                                NOTHING is created
//     - any of the above flagged sensitive    -> deny  / "sensitive_requires_consent"
//
//   So a brand-new client Agreely has never heard of does NOT read deny everywhere.
//   Its necessity cells allow immediately. There is no "provision every client
//   first" step, and you must NEVER call manualConsents()->record() on a
//   non-consent cell: that would fabricate a company-attested consent, asserting a
//   signed PDF that does not exist. The only write paths are consentRequests()
//   ->create() for a consent cell, and manualConsents()->record() for a consent you
//   genuinely collected offline on paper you hold.
//
// FAITHFUL to the real SDK: it uses Agreely::checkFields and reads
// BatchDecision->status for the reason. To keep the example RUNNABLE with no API key
// and no network, it injects a stub HttpClient (a public SDK seam) that reproduces
// the SERVER's own no-record decision rule (app/Models/Api/CheckService.php,
// decideNoRecord). In a real app you pass a real apiKey and NO httpClient.
//
// HONEST framing: gating plus the access log is EVIDENCE supporting the art. 3.1
// responsibility and art. 3.2 governance duties; it is not a compliance
// certification and not legal advice. Agreely records the basis the enterprise
// DECLARED in its catalog; it does not validate it. Have privacy counsel confirm
// each field's classification. Article numbers here are P-39.1 (private sector);
// public bodies are governed by A-2.1, where every number differs.
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
// POST /v1/check/batch the way the SERVER does:
//   1. a per-subject enforcement RECORD wins (active / revoked / expired / ...);
//   2. otherwise fall to the catalog cell's DECLARED basis (the no-record branch).
// This is the ONLY illustrative seam; everything else is the real SDK. In
// production you do NOT do this; you pass a real apiKey instead.
// ---------------------------------------------------------------------------
final class ScriptedConsent implements HttpClient
{
    /**
     * @param array<string,array{basis:string,sensitive?:bool}> $catalog keyed "category|purpose"
     * @param array<string,array{status:string,assurance?:string}> $records keyed "ref|category|purpose"
     */
    public function __construct(
        private readonly array $catalog,
        private readonly array $records,
    ) {}

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): RawResponse
    {
        $payload = $body !== null ? json_decode($body, true) : [];
        $decisions = [];
        foreach (($payload['items'] ?? []) as $item) {
            $decisions[] = $this->decide(
                (string) $item['customerRef'],
                (string) $item['category'],
                (string) $item['purpose'],
            );
        }
        return new RawResponse(200, (string) json_encode(['decisions' => $decisions]));
    }

    /** @return array<string,mixed> */
    private function decide(string $ref, string $category, string $purpose): array
    {
        $base = [
            'customerRef' => $ref,
            'category'    => $category,
            'purpose'     => $purpose,
            'checkedAt'   => '2026-08-22T00:00:00Z',
        ];

        // 1) A per-subject RECORD, when one exists, wins.
        $record = $this->records["{$ref}|{$category}|{$purpose}"] ?? null;
        if ($record !== null) {
            $allow = $record['status'] === 'active';
            return $base + [
                'decision'   => $allow ? 'allow' : 'deny',
                'status'     => $record['status'],
                'consentRef' => '0x' . str_repeat('ab', 32),
                'assurance'  => $record['assurance'] ?? 'citizen_signed',
            ];
        }

        // 2) NO record: read the ACTIVE catalog cell's declared basis.
        //    Mirrors CheckService::decideNoRecord exactly.
        $cell = $this->catalog["{$category}|{$purpose}"] ?? null;
        if ($cell === null || $cell['basis'] === 'consent') {
            // No active cell, or a consent-basis cell: consent MUST refuse with no record.
            return $base + ['decision' => 'deny', 'status' => 'none'];
        }
        if (($cell['sensitive'] ?? false) === true) {
            // A declared-sensitive cell never rides a necessity basis.
            return $base + ['decision' => 'deny', 'status' => 'sensitive_requires_consent'];
        }
        // Allowed on the declared non-consent basis. No consentRef, no assurance,
        // nothing created.
        return $base + ['decision' => 'allow', 'status' => 'necessity', 'basis' => $cell['basis']];
    }
}

// ---------------------------------------------------------------------------
// Derive a client-level summary from a client's cells. No rollup endpoint exists
// in the SDK or the API today, so the app computes this itself. Only CONSENT-based
// cells count: necessity cells can never be refused and would mask a real refusal.
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
        return 'none';    // "cette personne n'a pas consenti" / "did not consent"
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

    /** Bases whose lawful ground is NOT consent: they can never be refused. */
    private const ALWAYS_RENDERED = ['necessary', 'security', 'professional'];

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

        // checkFields builds a CARTESIAN product (refs x fields) and the server caps a
        // batch at 500 items, so page the refs in a real listing.
        $matrix = null;
        try {
            $matrix = $this->agreely->checkFields($refs, $cells); // ONE round-trip for the table
        } catch (AgreelyError) {
            // The check FAILED (outage / billing / auth). See present() below: consent
            // cells fail CLOSED, non-consent cells still render (their basis is not
            // consent, so a failed check tells us nothing about them).
            $matrix = null;
        }

        $consentPurposes = array_values(array_map(
            fn ($f) => $f['purpose'],
            array_filter($fields, fn ($f) => !in_array($f['basis'], self::ALWAYS_RENDERED, true)),
        ));

        $out = [];
        foreach ($rows as $row) {
            $decisions = [];
            $statuses  = [];
            foreach ($fields as $f) {
                $decision = null;
                if ($matrix !== null) {
                    foreach ($matrix->decisions as $d) {
                        if ($d->customerRef === $row['subjectRef']
                            && $d->category === $f['category']
                            && $d->purpose === $f['purpose']) {
                            $decision = $d;
                            $decisions[] = $d;
                            break;
                        }
                    }
                }
                $statuses[$f['key']] = $decision?->status ?? 'unavailable';
                $row['values'][$f['key']] = $this->present(
                    $row['values'][$f['key']] ?? null,
                    $decision,
                    $f['basis'],
                );
            }
            $row['statuses']       = $statuses;
            $row['consentSummary'] = clientConsentSummary($decisions, $consentPurposes);
            $out[] = $row;
        }
        return $out;
    }

    /** Render one value from its decision + the field's basis. */
    private function present(?string $value, ?BatchDecision $decision, string $basis): ?string
    {
        $alwaysRendered = in_array($basis, self::ALWAYS_RENDERED, true);

        if ($decision === null) {
            // The check could not complete. A non-consent field's basis is not consent,
            // so a failed check carries NO information about it: render it from your own
            // store (you do lose the access-log entry, which is an honest gap).
            // Every consent-gated field fails CLOSED.
            return $alwaysRendered
                ? $value
                : null;
        }

        // decision === "allow" is the only true. Both "active" and "necessity" allow.
        if ($decision->isAllow()) {
            return $value;
        }

        // Refused. An optional consent field is OMITTED; anything that must keep its
        // slot shows the placeholder.
        return $alwaysRendered
            ? ($this->locale === 'en' ? self::PLACEHOLDER_EN : self::PLACEHOLDER_FR)
            : null;
    }
}

// ---------------------------------------------------------------------------
// DEMO: four CRM clients, three gated fields, one per basis family.
// ---------------------------------------------------------------------------
$fields = [
    // A consent cell: withdrawable, so it can come back refused.
    ['key' => 'newsletter_email', 'category' => 'Adresse courriel', 'purpose' => 'Infolettre',  'basis' => 'consent'],
    // A necessity cell (necessary_for_service, art. 12 al. 2 (4)): never refusable.
    ['key' => 'billing_name',     'category' => 'Nom',              'purpose' => 'Facturation', 'basis' => 'necessary'],
    // A professional-contact cell (art. 1, dernier alinéa): a scope carve-out from
    // Sections II and III. Still personal information (art. 2), still gated so the
    // access is logged, but NEVER issue a consent request for it.
    ['key' => 'rep_work_email',   'category' => 'Coordonnées professionnelles', 'purpose' => 'Contact du représentant', 'basis' => 'professional'],
];

// The company's DECLARED catalog. This is what the server reads on the no-record path.
$catalog = [
    'Adresse courriel|Infolettre'                        => ['basis' => 'consent'],
    'Nom|Facturation'                                    => ['basis' => 'necessary_for_service'],
    'Coordonnées professionnelles|Contact du représentant' => ['basis' => 'professional_contact'],
];

// Per-subject enforcement RECORDS. Only consent cells ever have one here: nothing is
// ever written for a necessity cell.
$records = [
    'cli_1|Adresse courriel|Infolettre' => ['status' => 'active',  'assurance' => 'citizen_signed'],
    'cli_2|Adresse courriel|Infolettre' => ['status' => 'revoked', 'assurance' => 'citizen_signed'],
    // cli_3 was offered the newsletter and never signed  -> no record -> "none"
    // cli_4 is BRAND NEW: Agreely has never heard of it  -> no records at all
];

$rows = [
    ['id' => '1', 'subjectRef' => 'cli_1', 'values' => ['newsletter_email' => 'ana@example.com',  'billing_name' => 'Ana Roy',  'rep_work_email' => 'a.roy@acme.example']],
    ['id' => '2', 'subjectRef' => 'cli_2', 'values' => ['newsletter_email' => 'ben@example.com',  'billing_name' => 'Ben Côté', 'rep_work_email' => 'b.cote@acme.example']],
    ['id' => '3', 'subjectRef' => 'cli_3', 'values' => ['newsletter_email' => 'cara@example.com', 'billing_name' => 'Cara Lam', 'rep_work_email' => 'c.lam@acme.example']],
    // cli_4 is a brand-new client with NO records in Agreely. Its necessity and
    // professional cells still ALLOW (status "necessity"); only the consent cell
    // denies. Nothing to provision.
    ['id' => '4', 'subjectRef' => 'cli_4', 'values' => ['newsletter_email' => 'dan@example.com',  'billing_name' => 'Dan Kim',  'rep_work_email' => 'd.kim@acme.example']],
];

$agreely = new Agreely([
    'apiKey'     => 'agr_live_demo',       // any non-empty value; the stub ignores it
    'httpClient' => new ScriptedConsent($catalog, $records),
]);

$gated = (new ConsentGate($agreely, 'fr'))->gateList($rows, $fields);

/** printf pads by BYTES, so accented French would misalign. Pad by characters. */
function pad(?string $v, int $width): string
{
    $v = $v ?? '(omitted)';
    return $v . str_repeat(' ', max(1, $width - mb_strlen($v)));
}

echo "Client list (view-model after gating):\n";
echo '  ' . pad('id', 5) . pad('newsletter_email', 26) . pad('billing_name', 14)
    . pad('rep_work_email', 23) . "summary\n";
foreach ($gated as $row) {
    echo '  ' . pad($row['id'], 5)
        . pad($row['values']['newsletter_email'] ?? null, 26)
        . pad($row['values']['billing_name'] ?? null, 14)
        . pad($row['values']['rep_work_email'] ?? null, 23)
        . $row['consentSummary'] . "\n";
}
echo "\nPer-cell status (why each value rendered or not):\n";
foreach ($gated as $row) {
    $parts = [];
    foreach ($row['statuses'] as $key => $status) {
        $parts[] = "{$key}={$status}";
    }
    echo "  cli_{$row['id']}: " . implode('  ', $parts) . "\n";
}
echo "\n  cli_4 is BRAND NEW to Agreely and still renders its billing name and its\n";
echo "  representative's work email: those cells read allow/\"necessity\" off the\n";
echo "  declared catalog basis, with no record and nothing written. Only the consent\n";
echo "  cell denies. There is no \"provision the client first\" step.\n";

// ---------------------------------------------------------------------------
// Assert the gating is correct (doubles as a smoke test; exit 0 on pass).
// ---------------------------------------------------------------------------
$ok =
    // cli_1 consented -> the real newsletter email shows
    $gated[0]['values']['newsletter_email'] === 'ana@example.com'
    && $gated[0]['statuses']['newsletter_email'] === 'active'
    && $gated[0]['consentSummary'] === 'ok'
    // cli_2 withdrew -> newsletter omitted; the necessity + professional cells still show
    && $gated[1]['values']['newsletter_email'] === null
    && $gated[1]['statuses']['newsletter_email'] === 'revoked'
    && $gated[1]['values']['billing_name'] === 'Ben Côté'
    && $gated[1]['consentSummary'] === 'none'
    // cli_3 never signed -> "none", not "revoked"
    && $gated[2]['values']['newsletter_email'] === null
    && $gated[2]['statuses']['newsletter_email'] === 'none'
    && $gated[2]['consentSummary'] === 'none'
    // cli_4 is brand new: the NECESSITY cells allow anyway, with no record.
    && $gated[3]['values']['newsletter_email'] === null
    && $gated[3]['statuses']['newsletter_email'] === 'none'
    && $gated[3]['values']['billing_name'] === 'Dan Kim'
    && $gated[3]['statuses']['billing_name'] === 'necessity'
    && $gated[3]['values']['rep_work_email'] === 'd.kim@acme.example'
    && $gated[3]['statuses']['rep_work_email'] === 'necessity'
    && $gated[3]['consentSummary'] === 'none';

if (!$ok) {
    fwrite(STDERR, "UNEXPECTED gating result; the SDK contract may have drifted.\n");
    exit(1);
}

// A sensitive cell with no record fails closed on its own status, never on necessity.
$sensitiveAgreely = new Agreely([
    'apiKey'     => 'agr_live_demo',
    'httpClient' => new ScriptedConsent(
        ['Renseignements médicaux|Évaluation des réclamations' => ['basis' => 'necessary_for_service', 'sensitive' => true]],
        [],
    ),
]);
$sensitive = $sensitiveAgreely->checkBatch([
    ['customerRef' => 'cli_4', 'category' => 'Renseignements médicaux', 'purpose' => 'Évaluation des réclamations'],
]);
if ($sensitive[0]->isAllow() || $sensitive[0]->status !== 'sensitive_requires_consent') {
    fwrite(STDERR, "A sensitive cell must fail closed on sensitive_requires_consent.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Fail-closed proof: a throwing transport hides every CONSENT value. The
// necessity and professional cells still render, because their lawful basis is
// not consent and a failed check carries no information about them (this is what
// the deployed reference integration does, and it is deliberate).
// ---------------------------------------------------------------------------
final class AlwaysDown implements HttpClient
{
    public function send(string $m, string $u, array $h, ?string $b, int $t): RawResponse
    {
        throw new \Agreely\Sdk\Http\TransportException('down', true);
    }
}
$down  = new Agreely(['apiKey' => 'agr_live_demo', 'httpClient' => new AlwaysDown()]);
$blank = (new ConsentGate($down, 'fr'))->gateList($rows, $fields);
$failClosed = $blank[0]['values']['newsletter_email'] === null            // consent -> hidden
    && $blank[0]['statuses']['newsletter_email'] === 'unavailable'
    && $blank[0]['values']['billing_name'] === 'Ana Roy'                  // necessity -> still renders
    && $blank[0]['values']['rep_work_email'] === 'a.roy@acme.example';    // professional -> still renders

if (!$failClosed) {
    fwrite(STDERR, "FAIL-CLOSED path is wrong: a consent value leaked, or a necessity value was hidden.\n");
    exit(1);
}

echo "\nOK: allow is the only true, necessity allows with no record, sensitive fails\n";
echo "    closed on its own status, and an outage hides every CONSENT value while\n";
echo "    non-consent values keep rendering (you lose the access-log entry, though).\n";

// ---------------------------------------------------------------------------
// LIVE wiring (do NOT run without a real key). No stub httpClient; a real key
// with the `check` scope; the call hits https://api.agreely.ca (or AGREELY_BASE_URL).
//
//   $agreely = new Agreely([
//       'apiKey'     => getenv('AGREELY_API_KEY'),
//       'timeout'    => 8000,   // the 800ms default is too tight over the open internet
//       'maxRetries' => 1,
//   ]);
//   $gated = (new ConsentGate($agreely, 'fr'))->gateList($rows, $fields);
//
// For a DETAIL view, use checkBatch to read each cell's ->status for a richer
// per-field message (revoked vs expired vs none vs sensitive_requires_consent).
// ---------------------------------------------------------------------------
