// Operator example: publish a fresh ADDRESS antibody.
//
// Locks 1 USDC on chain for 72 hours. Match rewards split 80/20
// publisher/treasury. The Registry rejects duplicate publishes for the
// same primary matcher hash, so flagging an already-flagged address
// throws DuplicateAntibodyError.
//
// Run with:
//   WALLET_PRIVATE_KEY=0x... \
//   AXL_URL=http://localhost:9002 \
//   TARGET_ADDRESS=0xCAFE...000099 \
//     npx tsx publisher.ts

import { Immunity, parseUsdc, TESTNET } from "@immunity-protocol/sdk";
import { JsonRpcProvider, Wallet, isAddress } from "ethers";

const pk = process.env.WALLET_PRIVATE_KEY;
const axlUrl = process.env.AXL_URL ?? "http://localhost:9002";
const target = process.env.TARGET_ADDRESS;
if (!pk) throw new Error("WALLET_PRIVATE_KEY env var is required");
if (!target || !isAddress(target)) {
  throw new Error("TARGET_ADDRESS env var must be a 0x-prefixed 20-byte hex");
}

const provider = new JsonRpcProvider(TESTNET.rpcUrl);
const wallet = new Wallet(pk, provider);

const immunity = new Immunity({
  wallet,
  network: "testnet",
  axlUrl,
  novelThreatPolicy: "trust-cache",
});

await immunity.start();

// Publish needs at least the 1 USDC stake plus a tiny gas budget.
// The example assumes the wallet is already prefunded; if not, run
// basic-agent.ts once to seed the balance.
const balance = await immunity.balance();
if (balance < parseUsdc("1.05")) {
  console.error(`prepaid balance too low: ${(Number(balance) / 1e6).toFixed(6)} USDC`);
  console.error("publish needs ~1.05 USDC (1 stake + buffer for fees)");
  await immunity.stop();
  process.exit(1);
}

console.log(`[publisher] flagging ${target} as MALICIOUS`);

const result = await immunity.publish({
  seed: {
    abType: "ADDRESS",
    chainId: TESTNET.chainId,
    target: target as `0x${string}`,
  },
  verdict: "MALICIOUS",
  confidence: 95,
  severity: 90,
});

console.log("[publisher] minted", {
  immId: result.immId,
  immSeq: result.immSeq,
  keccakId: result.keccakId,
  txHash: result.txHash,
});

console.log(
  `\n[publisher] view at https://immunity-protocol.com/antibody/${result.immId}`,
);
console.log("[publisher] stake unlocks 72h from publication, or earns rewards on matches");

await immunity.stop();
