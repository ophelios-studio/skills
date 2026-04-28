// 0G Compute inference + TEE attestation.
//
// Discovers available providers, acknowledges the chosen provider's on-chain
// TEE signer, verifies the service's TEE quote, sends a real prompt, verifies
// the per-response signature via broker.inference.processResponse.
//
// Docs: https://docs.0g.ai/developer-hub/building-on-0g/compute-network/inference
// SDK:  @0glabs/0g-serving-broker v0.7.5
//
// Funding requirements (HARD minimums enforced by the ledger contract):
//   - Ledger:             3 0G minimum initial deposit
//   - Per-provider:       1 0G minimum locked in sub-account
// Total minimum:          4 0G on the signer wallet before this will work.
// Testnet faucet:         0.1 0G/day per wallet. Request Discord bump if needed.

import "dotenv/config";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { ethers } from "ethers";
import { createZGComputeNetworkBroker } from "@0glabs/0g-serving-broker";
import OpenAI from "openai";

const __dirname = path.dirname(fileURLToPath(import.meta.url));

const EVM_RPC = process.env.ZEROG_RPC_URL || "https://evmrpc-testnet.0g.ai";
const PRIV = process.env.DEPLOYER_PRIVATE_KEY;
if (!PRIV) throw new Error("DEPLOYER_PRIVATE_KEY not set");

const provider = new ethers.JsonRpcProvider(EVM_RPC);
const signer = new ethers.Wallet(PRIV, provider);

console.log(`signer: ${signer.address}`);
console.log(`rpc:    ${EVM_RPC}`);

const broker = await createZGComputeNetworkBroker(signer);

// --- 1. Ledger: ensure we have an account ---
let ledger;
try {
  ledger = await broker.ledger.getLedger();
  console.log(`ledger: existing account — balance ${ethers.formatEther(ledger.totalBalance)} 0G`);
} catch (err) {
  console.log("ledger: no account — creating with 3 0G initial deposit");
  try {
    await broker.ledger.addLedger(3);
    ledger = await broker.ledger.getLedger();
    console.log(`ledger: created — balance ${ethers.formatEther(ledger.totalBalance)} 0G`);
  } catch (e) {
    console.error("ledger creation failed (need >= 3 0G on wallet):", e?.message ?? e);
    process.exit(1);
  }
}

// --- 2. Discover providers / models ---
const services = await broker.inference.listService();
console.log(`\ndiscovered ${services.length} service(s):`);
for (const s of services) {
  console.log(
    `  provider=${s.provider}  type=${s.serviceType}  model=${s.model}  url=${s.url}`
  );
}

// Prefer a chatbot-type service. Testnet today has qwen-2.5-7b-instruct.
const svc = services.find((s) => s.serviceType === "chatbot") ?? services[0];
if (!svc) {
  console.error("no providers available on this network");
  process.exit(1);
}
const providerAddr = svc.provider;
console.log(`\nusing provider: ${providerAddr} (${svc.model})`);

// --- 3. Acknowledge TEE signer on-chain (one-time per provider per wallet) ---
try {
  await broker.inference.acknowledgeProviderSigner(providerAddr);
  console.log("acknowledged TEE signer");
} catch (err) {
  // Idempotent; may throw if already acknowledged
  console.log("acknowledge: ", err?.message ?? "(already acknowledged)");
}

// --- 4. Fund sub-account (1 0G minimum) ---
try {
  await broker.ledger.transferFund(providerAddr, "inference", ethers.parseEther("1"));
  console.log("funded sub-account with 1 0G");
} catch (err) {
  console.log("fund transfer:", err?.message ?? "(may already be funded)");
}

// --- 5. Verify service attestation (TEE quote -> signer match) ---
const reportDir = path.join(__dirname, ".tee-reports");
fs.mkdirSync(reportDir, { recursive: true });
console.log(`\nverifying TEE attestation -> ${reportDir}`);
let attestation;
try {
  attestation = await broker.inference.verifyService(
    providerAddr,
    reportDir,
    (step) => console.log(`  attest: ${step.message ?? JSON.stringify(step)}`)
  );
  console.log("signer verified:  ", attestation?.signerVerification?.allMatch);
  console.log("compose verified: ", attestation?.composeVerification?.passed);
} catch (err) {
  console.error("verifyService failed:", err?.message ?? err);
  // Still proceed — some providers don't expose full compose verification
  // on testnet. The per-response signature check is the harder gate.
}

// --- 6. Send a real prompt ---
const { endpoint, model } = await broker.inference.getServiceMetadata(providerAddr);
const prompt = "Is this transaction suspicious? Transfer of 100 ETH to 0xBADBAD0000000000000000000000000000BADBAD within 2 minutes of wallet creation. Answer in one sentence.";

// Per-request headers are SINGLE-USE — regenerate each call.
const headers = await broker.inference.getRequestHeaders(providerAddr, prompt);

const openai = new OpenAI({ baseURL: endpoint, apiKey: "" });
const inferStart = Date.now();
const completion = await openai.chat.completions.create(
  {
    model,
    messages: [{ role: "user", content: prompt }],
  },
  { headers }
);
const inferMs = Date.now() - inferStart;

const answer = completion.choices[0]?.message?.content ?? "";
const chatID = completion.id;
console.log(`\nprompt:  ${prompt}`);
console.log(`answer:  ${answer}`);
console.log(`chatID:  ${chatID}`);
console.log(`latency: ${inferMs}ms`);

// --- 7. Verify the per-response TEE signature + trigger settlement ---
const isValid = await broker.inference.processResponse(providerAddr, chatID, answer);
console.log(`response TEE-signed & valid: ${isValid}`);

// Persist the full artifact for FINDINGS write-up and future attestation audits.
const artifact = {
  provider: providerAddr,
  model,
  endpoint,
  prompt,
  answer,
  chatID,
  latencyMs: inferMs,
  isValid,
  attestation: attestation
    ? {
        signerAllMatch: attestation.signerVerification?.allMatch,
        composePassed: attestation.composeVerification?.passed,
        reportDir,
      }
    : null,
  timestamp: new Date().toISOString(),
};
const outPath = path.join(__dirname, ".inference-result.json");
fs.writeFileSync(outPath, JSON.stringify(artifact, null, 2));
console.log(`wrote ${outPath}`);
