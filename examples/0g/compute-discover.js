// 0G Compute service discovery — no funds required.
//
// Calls broker.inference.listService() to get the live catalog of providers,
// their models, endpoints, and pricing. This exercises the SDK without
// touching the ledger, so it works even with 0 balance.
//
// Useful because the starter-kit README's model list is known stale; this
// script is the authoritative way to see what's actually live.

import "dotenv/config";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { ethers } from "ethers";
import { createZGComputeNetworkBroker } from "@0glabs/0g-serving-broker";

const __dirname = path.dirname(fileURLToPath(import.meta.url));

const EVM_RPC = process.env.ZEROG_RPC_URL || "https://evmrpc-testnet.0g.ai";
const PRIV = process.env.DEPLOYER_PRIVATE_KEY;
if (!PRIV) throw new Error("DEPLOYER_PRIVATE_KEY not set");

const provider = new ethers.JsonRpcProvider(EVM_RPC);
const signer = new ethers.Wallet(PRIV, provider);

console.log(`signer: ${signer.address}`);
console.log(`rpc:    ${EVM_RPC}`);

const broker = await createZGComputeNetworkBroker(signer);
const services = await broker.inference.listService();

console.log(`\ndiscovered ${services.length} service(s):`);
for (const s of services) {
  console.log(`  provider:    ${s.provider}`);
  console.log(`    model:     ${s.model}`);
  console.log(`    type:      ${s.serviceType}`);
  console.log(`    url:       ${s.url}`);
  console.log(`    input $:   ${s.inputPrice}`);
  console.log(`    output $:  ${s.outputPrice}`);
  console.log(`    verif:     ${s.verifiability ?? "(none)"}`);
  console.log("");
}

const outPath = path.join(__dirname, ".services.json");
fs.writeFileSync(
  outPath,
  JSON.stringify(services, (_, v) => (typeof v === "bigint" ? v.toString() : v), 2)
);
console.log(`wrote ${outPath}`);
