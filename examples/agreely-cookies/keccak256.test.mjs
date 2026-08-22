#!/usr/bin/env node
// Standard Keccak-256 vectors for the dependency-free implementation.
//   node keccak256.test.mjs
import { keccak256, toHex, utf8 } from './keccak256.mjs';

const vectors = [
  ['', '0xc5d2460186f7233c927e7db2dcc703c0e500b653ca82273b7bfad8045d85a470'],
  ['abc', '0x4e03657aea45a94fc7d47ba826c8d667c0d1e6e33a64a036ec44f58fa12d6c45'],
  ['hello', '0x1c8aff950685c2ed4bc3174f3472287b56d9517b9c948127319a09a7a36deac8'],
  // The rate is 136 bytes, so these three straddle the padding edge (one short,
  // exactly one block, one over). Cross-checked against the PHP implementation the
  // Agreely chain itself uses (App\Models\Crypto\Keccak), not just against docs.
  ['a'.repeat(135), '0x34367dc248bbd832f4e3e69dfaac2f92638bd0bbd18f2912ba4ef454919cf446'],
  ['a'.repeat(136), '0xa6c4d403279fe3e0af03729caada8374b5ca54d8065329a3ebcaeb4b60aa386e'],
  ['a'.repeat(137), '0xd869f639c7046b4929fc92a4d988a8b22c55fbadb802c0c66ebcd484f1915f39'],
];

let bad = 0;
for (const [input, want] of vectors) {
  const got = toHex(keccak256(utf8(input)));
  const label = input.length > 20 ? `"${input.slice(0, 8)}..."(${input.length})` : JSON.stringify(input);
  if (got === want) {
    console.log(`  OK   keccak256(${label})`);
  } else {
    bad++;
    console.log(`  FAIL keccak256(${label})\n       got  ${got}\n       want ${want}`);
  }
}
console.log(bad ? `\n${bad} vector(s) failed.` : '\nAll vectors pass.');
process.exit(bad ? 1 : 0);
