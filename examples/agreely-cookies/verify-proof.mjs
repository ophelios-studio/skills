#!/usr/bin/env node
// Verify an Agreely cookie-consent proof bundle WITHOUT trusting Agreely.
//
//   node verify-proof.mjs <consentId> [--base https://cdn.agreely.ca]
//
// It fetches GET /e/v1/proof/{consentId}?recompute=1 and then INDEPENDENTLY
// recomputes, with its own keccak-256 (keccak256.mjs, no dependencies):
//
//   1. commitment = keccak256( JCS(boundClaim) || salt )
//   2. rowHash    = keccak256( prevHash || jsonSorted({chain_seq, action,
//                                config_version, occurred_at, commitment}) )
//   3. root       = commutative-keccak fold of the leaf up the inclusion path
//
// Then it prints, honestly, what that does and does NOT establish. The one
// thing it CANNOT do offline is confirm the epoch root really landed on Base:
// it prints the Basescan URL so a human checks that leg themselves.
//
// Exit codes: 0 verified, 3 recorded-but-not-yet-anchored, 4 unknown id,
//             6 a recomputation MISMATCHED (tamper or a contract change), 2 usage.

import { keccak256, toHex, fromHex, utf8, concat } from './keccak256.mjs';

const args = process.argv.slice(2);
const consentId = args.find((a) => !a.startsWith('--'));
const baseIdx = args.indexOf('--base');
const BASE = (baseIdx >= 0 ? args[baseIdx + 1] : 'https://cdn.agreely.ca').replace(/\/$/, '');

if (!consentId) {
  console.error('usage: node verify-proof.mjs <consentId> [--base http://localhost:8096]');
  process.exit(2);
}

/**
 * JCS-ish canonical JSON (RFC 8785, restricted to the number-free shapes Agreely hashes):
 * keys sorted by byte order, no whitespace, no escaped slashes, raw UTF-8. Every value in a
 * hashed Agreely claim is a string, a bool or null on purpose, so no number formatting can
 * diverge between languages.
 */
function canonical(value) {
  if (value === null) return 'null';
  if (typeof value === 'string') return JSON.stringify(value);
  if (typeof value === 'boolean') return value ? 'true' : 'false';
  if (typeof value === 'number') return String(value); // only the chain_seq int, see below
  if (Array.isArray(value)) return '[' + value.map(canonical).join(',') + ']';
  const keys = Object.keys(value).sort();
  return '{' + keys.map((k) => JSON.stringify(k) + ':' + canonical(value[k])).join(',') + '}';
}

const ok = (b) => (b ? 'OK  ' : 'FAIL');
const line = (label, value) => console.log('  ' + label.padEnd(26) + value);

const url = `${BASE}/e/v1/proof/${encodeURIComponent(consentId)}?recompute=1`;
const res = await fetch(url);
const bundle = await res.json();

console.log(`\nAgreely cookie-consent proof: ${consentId}`);
console.log(`  source: ${url}\n`);

if (bundle.status === 'unknown') {
  console.log('  status                    unknown: no decision matches this identifier.');
  process.exit(4);
}

line('status', bundle.status);
line('recorded decision', JSON.stringify(bundle.claim?.decisions ?? {}));
line('action', String(bundle.claim?.action));
line('recorded at (UTC)', String(bundle.claim?.occurredAt));
line('banner shown (hash)', String(bundle.claim?.configHash));
line('banner version', String(bundle.claim?.configVersion));

let failed = false;

// ---- 1. commitment = keccak256( JCS(boundClaim) || salt ) --------------------
const rc = bundle.recompute;
if (rc?.boundClaim && rc?.salt) {
  const preimage = concat(utf8(canonical(rc.boundClaim)), fromHex(rc.salt));
  const got = toHex(keccak256(preimage));
  const match = got === bundle.commitment;
  failed ||= !match;
  console.log('\n  1. commitment = keccak256( JCS(claim) || salt )');
  line('   recomputed', got);
  line('   published', bundle.commitment);
  line('   verdict', ok(match));
} else {
  console.log('\n  1. commitment                 (not opened: no salt in this bundle)');
  console.log('     A COARSENED row destroys its salt on purpose, so the commitment');
  console.log('     is a deliberate one-way orphan and this leg cannot be replayed.');
}

// ---- 2. rowHash = keccak256( prevHash || jsonSorted(chainPayload) ) ---------
// NOTE: the chain payload is key-sorted JSON where chain_seq is a NUMBER while
// config_version is a STRING. That asymmetry is the real on-disk contract; do not
// "tidy" it or the hash stops matching.
if (rc?.prevHash && typeof rc.chainSeq === 'number') {
  const payload = {
    chain_seq: rc.chainSeq,
    action: bundle.claim.action,
    config_version: String(bundle.claim.configVersion),
    occurred_at: bundle.claim.occurredAt,
    commitment: bundle.commitment,
  };
  const got = toHex(keccak256(concat(fromHex(rc.prevHash), utf8(canonical(payload)))));
  const match = got === bundle.rowHash;
  failed ||= !match;
  console.log('\n  2. rowHash = keccak256( prevHash || canonical(chainPayload) )');
  line('   recomputed', got);
  line('   published', bundle.rowHash);
  line('   verdict', ok(match));
}

// ---- 3. Merkle inclusion ----------------------------------------------------
const commutative = (a, b) => {
  const ah = toHex(a), bh = toHex(b);
  return keccak256(ah <= bh ? concat(a, b) : concat(b, a));
};
if (bundle.inclusion) {
  let node = fromHex(bundle.inclusion.leaf);
  for (const sib of bundle.inclusion.siblings ?? []) node = commutative(node, fromHex(sib));
  const got = toHex(node);
  const match = got === bundle.inclusion.root && bundle.inclusion.leaf === bundle.rowHash;
  failed ||= !match;
  console.log('\n  3. Merkle fold of the leaf up the inclusion path');
  line('   leaf == rowHash', ok(bundle.inclusion.leaf === bundle.rowHash));
  line('   folded root', got);
  line('   epoch root', bundle.inclusion.root);
  line('   verdict', ok(match));
}

// ---- 4. The on-chain leg (NOT verifiable offline) ---------------------------
console.log('\n  4. On-chain anchor (verify this leg yourself)');
if (bundle.onChain?.txHash) {
  line('   chainId', String(bundle.onChain.chainId));
  line('   tx', bundle.onChain.txHash);
  line('   explorer', String(bundle.onChain.explorerUrl));
  console.log('     This script does NOT read the chain. Open the explorer and confirm the');
  console.log('     transaction carries the epoch root printed above.');
} else {
  console.log('     Not anchored yet. The decision IS recorded and hash-chained; anchoring');
  console.log('     runs in batches. Re-run in a while.');
}

// ---- Honest verdict ---------------------------------------------------------
console.log('\n  What this proves');
console.log('    This decision was recorded as shown and has not been modified since.');
console.log('  What it does NOT prove');
console.log('    Not that the banner shown to the visitor was Law 25 compliant.');
console.log('    Not that every cookie the site sets honoured this decision.');
console.log('    Not the legal validity of the consent (that is the controller\'s duty).');
console.log('    Not operator-proof: anchoring proves non-modification SINCE recording,');
console.log('    not fidelity of the original capture.');
console.log('    It is NOT a certificate of compliance.\n');

if (failed) process.exit(6);
process.exit(bundle.status === 'anchored' ? 0 : 3);
