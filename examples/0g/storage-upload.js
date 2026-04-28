// 0G Storage upload + retrieve round-trip.
//
// Uploads a JSON blob (threat-intel sample), receives a 32-byte Merkle root
// hash, downloads by that root hash, verifies the bytes are identical.
//
// Docs: https://docs.0g.ai/developer-hub/building-on-0g/storage/sdk
// SDK:  @0gfoundation/0g-ts-sdk v1.2.2
//
// Requires testnet 0G on the signer wallet — the upload lands an on-chain tx
// on the Flow contract. Download is free (no signer needed, but we pass one).

import "dotenv/config";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { ethers } from "ethers";
import { Indexer, MemData } from "@0gfoundation/0g-ts-sdk";

const __dirname = path.dirname(fileURLToPath(import.meta.url));

const EVM_RPC = process.env.ZEROG_RPC_URL || "https://evmrpc-testnet.0g.ai";
const INDEXER_URL =
  process.env.ZEROG_INDEXER_URL || "https://indexer-storage-testnet-turbo.0g.ai";
const PRIV = process.env.DEPLOYER_PRIVATE_KEY;
if (!PRIV) throw new Error("DEPLOYER_PRIVATE_KEY not set");

const provider = new ethers.JsonRpcProvider(EVM_RPC);
const signer = new ethers.Wallet(PRIV, provider);

console.log(`signer:  ${signer.address}`);
console.log(`rpc:     ${EVM_RPC}`);
console.log(`indexer: ${INDEXER_URL}`);

// 1. Build a representative threat-intel payload (~1KB JSON).
const blob = {
  schema: "threat-intel/v1",
  submitter: signer.address,
  submittedAt: new Date().toISOString(),
  indicators: [
    { kind: "ipv4", value: "192.0.2.17", confidence: 0.92, first_seen: "2026-04-20T11:32:00Z" },
    { kind: "domain", value: "badupdates.example.org", confidence: 0.86 },
    { kind: "sha256", value: "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855" },
  ],
  context: {
    campaign: "example-campaign-2026-q2",
    ttp: ["T1566.001", "T1059.003"],
    evidence: "Observed beaconing from compromised host X to C2 every 180s. 3-day capture attached.",
  },
};
const payloadBytes = new TextEncoder().encode(JSON.stringify(blob, null, 2));
console.log(`payload: ${payloadBytes.length} bytes JSON`);

// 2. Upload.
const indexer = new Indexer(INDEXER_URL);
const mem = new MemData(payloadBytes);

// MemData-on-Indexer: the SDK's upload() internally builds a Merkle tree.
// We time the whole thing end-to-end (Merkle + on-chain Flow tx + indexer segment publish).
console.log(`\nuploading...`);
const uploadStart = Date.now();
const [tx, uploadErr] = await indexer.upload(
  mem,
  EVM_RPC,
  signer,
  undefined, // uploadOpts
  { Retries: 3, Interval: 5, MaxGasPrice: 0 } // RetryOpts — PascalCase mandatory
);
if (uploadErr) {
  console.error("upload failed:", uploadErr);
  process.exit(1);
}
const uploadMs = Date.now() - uploadStart;

// tx is either { rootHash, txHash } OR { rootHashes[], txHashes[] } for large files.
const rootHash = "rootHash" in tx ? tx.rootHash : tx.rootHashes[0];
const txHash = "rootHash" in tx ? tx.txHash : tx.txHashes[0];
console.log(`rootHash: ${rootHash}`);
console.log(`txHash:   ${txHash}`);
console.log(`upload:   ${uploadMs}ms`);
console.log(`explorer: https://storagescan-galileo.0g.ai/tx/${txHash}`);
console.log(`chain tx: https://chainscan-galileo.0g.ai/tx/${txHash}`);

// 3. Download by rootHash (no signer needed for read, but Indexer class instance suffices).
const outPath = path.join(__dirname, ".restored.json");
try {
  fs.unlinkSync(outPath);
} catch {}

console.log(`\ndownloading ${rootHash} -> ${outPath}`);
const dlStart = Date.now();
const dlErr = await indexer.download(rootHash, outPath, /* withProof */ true);
const dlMs = Date.now() - dlStart;
if (dlErr) {
  console.error("download failed:", dlErr);
  process.exit(1);
}
console.log(`download: ${dlMs}ms`);

// 4. Verify byte-identical.
const restored = fs.readFileSync(outPath);
const originalBuf = Buffer.from(payloadBytes);
const ok = restored.equals(originalBuf);
console.log(`bytes equal: ${ok} (${restored.length}/${originalBuf.length})`);

if (!ok) {
  console.error("MISMATCH — round-trip broken");
  process.exit(2);
}
console.log("OK");
