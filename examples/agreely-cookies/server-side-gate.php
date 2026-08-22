<?php
declare(strict_types=1);

/**
 * Read the visitor's cookie decision SERVER-SIDE, before you emit anything.
 *
 *   php -S localhost:8000 server-side-gate.php
 *
 * WHY THIS FILE EXISTS. The banner is client JavaScript. It cannot read or delete
 * an httpOnly cookie, and it cannot stop your own server from sending Set-Cookie.
 * Agreely never claims otherwise. Every non-essential cookie YOUR server sets is
 * yours to gate, and this is how.
 *
 * The banner writes a first-party cookie named `agreely_consent`, URL-encoded JSON:
 *
 *   {"v":1,
 *    "consentId":"cec50c1a-7def-42e9-be18-d422c1b1d0b0",
 *    "decisions":{"functional":true,"analytics":true,"marketing":true},
 *    "configVersion":1,
 *    "ts":1787418698,
 *    "locale":"fr",
 *    "receipt":"0x152b9942..."}
 *
 * (captured verbatim from a real accept-all in a headless browser).
 * SameSite=Lax, Path=/, Secure on https, Max-Age = consentTtlDays (183 by default,
 * symmetric for accept and refuse). It is classified strictly necessary: it stores
 * only the person's own choice plus an opaque server-minted id.
 */

/** The visitor's decision for one purpose. NO cookie == NO consent == false. */
function agreelyGranted(string $purpose): bool
{
    $raw = $_COOKIE['agreely_consent'] ?? null;
    if (!is_string($raw) || $raw === '') {
        return false;                          // absent, expired or blocked: refuse.
    }
    $data = json_decode(urldecode($raw), true);
    if (!is_array($data) || ($data['v'] ?? null) !== 1) {
        return false;                          // unknown shape: refuse, never guess.
    }
    return ($data['decisions'][$purpose] ?? false) === true;
}

/** The opaque consentId, for your own audit trail. Never derived from the visitor. */
function agreelyConsentId(): ?string
{
    $raw = $_COOKIE['agreely_consent'] ?? null;
    $data = is_string($raw) ? json_decode(urldecode($raw), true) : null;
    $id = is_array($data) ? ($data['consentId'] ?? null) : null;
    return is_string($id) && $id !== '' ? $id : null;
}

// ---------------------------------------------------------------------------
// Use it BEFORE any output, because Set-Cookie is a header.
// ---------------------------------------------------------------------------

// Strictly necessary: never gated. Your session, your CSRF token, security.
setcookie('app_session', bin2hex(random_bytes(8)), [
    'path' => '/', 'httponly' => true, 'secure' => true, 'samesite' => 'Lax',
]);

// Analytics: gated. This is the cookie the banner CANNOT delete for you.
if (agreelyGranted('analytics')) {
    setcookie('app_analytics_id', bin2hex(random_bytes(8)), [
        'expires' => time() + 63072000,
        'path' => '/', 'httponly' => true, 'secure' => true, 'samesite' => 'Lax',
    ]);
}

// Marketing: gated, and so is anything you SEND to a third party server-side
// (a conversions API call is a communication of personal information; a refused
// visitor must not be in it).
$mayRetarget = agreelyGranted('marketing');

header('Content-Type: text/plain; charset=utf-8');
echo "analytics granted : " . var_export(agreelyGranted('analytics'), true) . "\n";
echo "marketing granted : " . var_export($mayRetarget, true) . "\n";
echo "consentId         : " . (agreelyConsentId() ?? '(no decision yet)') . "\n";

/**
 * FOUR RULES, each one a real mistake somebody has made:
 *
 *  1. Absent cookie == refusal. Not "unknown", not "assume yes".
 *  2. Re-read the cookie on EVERY relevant request. Do NOT copy the decision into
 *     the session at login: the visitor can withdraw in another tab, and a cached
 *     decision then keeps a refused tracker alive for the rest of the session.
 *  3. Do not gate on `configVersion` yourself. When you publish a new banner
 *     version the client re-prompts the visitor automatically (measured: a cookie
 *     carrying configVersion 1 re-shows layer 1 once version 2 is published), and
 *     you get a fresh decision.
 *  4. httpOnly is fine for a strictly necessary cookie (a login session). An
 *     httpOnly ANALYTICS or MARKETING cookie set before the decision is the
 *     problem, and only this server-side gate can prevent it.
 *
 * Law 25: the banner and this gate help you DOCUMENT and DEMONSTRATE that you
 * asked per purpose and honoured the answer. They do not certify compliance, and
 * this file is not legal advice.
 */
