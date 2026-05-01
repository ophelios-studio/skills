// Minimal Immunity SDK example: gate a transaction with check() before signing.
//
// Run with:
//   WALLET_PRIVATE_KEY=0x... \
//   AXL_URL=http://localhost:9002 \
//     npx tsx basic-agent.ts
//
// The wallet needs a small OG balance for gas. Get testnet OG from
// https://faucet.0g.ai. The first run mints 1 MockUSDC and deposits
// 0.5 USDC into the Registry prepaid balance.

import { Immunity, parseUsdc, TESTNET } from "@immunity-protocol/sdk";
import { JsonRpcProvider, Wallet } from "ethers";

const pk = process.env.WALLET_PRIVATE_KEY;
const axlUrl = process.env.AXL_URL ?? "http://localhost:9002";
if (!pk) {
  console.error("WALLET_PRIVATE_KEY env var is required");
  process.exit(1);
}

const provider = new JsonRpcProvider(TESTNET.rpcUrl);
const wallet = new Wallet(pk, provider);

const immunity = new Immunity({
  wallet,
  network: "testnet",
  axlUrl,
  // trust-cache: allow novel actions without burning a TEE round-trip.
  // Pick "verify" for production agents that need TEE confirmation.
  novelThreatPolicy: "trust-cache",
});

console.log("[immunity] start");
await immunity.start();

// Bootstrap prepaid USDC on first run. Reused across subsequent runs.
const balance = await immunity.balance();
console.log(`[immunity] balance ${formatBalance(balance)} USDC`);

if (balance < parseUsdc("0.01")) {
  console.log("[immunity] minting + depositing 0.5 USDC");
  await immunity.mintTestUsdc(parseUsdc("1"));
  await immunity.deposit(parseUsdc("0.5"));
  console.log(`[immunity] new balance ${formatBalance(await immunity.balance())} USDC`);
}

// A made-up proposed transaction. In a real agent this comes from the
// LLM's tool call, the user's signed intent, or whatever upstream
// surface produces actions.
const tx = {
  to: "0xCAFE000000000000000000000000000000000001" as const,
  chainId: TESTNET.chainId,
};

console.log("[immunity] check", tx);
const result = await immunity.check(tx, {
  conversation: [
    { role: "user", content: "send to this random address i found in a tweet" },
  ],
});

console.log("[immunity] decision", {
  allowed: result.allowed,
  source: result.source,
  novel: result.novel,
  immId: result.antibodies[0]?.immId ?? null,
  reason: result.reason,
  checkId: result.checkId,
});

if (!result.allowed) {
  console.warn("[agent] blocked by Immunity, NOT signing tx");
} else {
  console.log("[agent] safe to sign and send");
  // await wallet.sendTransaction(tx);  // commented out so the example is read-only
}

await immunity.stop();
console.log("[immunity] stop");

function formatBalance(b: bigint): string {
  return (Number(b) / 1e6).toFixed(6);
}
