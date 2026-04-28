---
name: 0g
description: Use when building on 0G Labs infrastructure — 0G Chain (EVM L1, Galileo testnet chainId 16602), 0G Storage (blob storage with 32-byte Merkle-root content IDs), or 0G Compute (TEE-attested LLM inference via dstack/Intel TDX). Trigger on references to `@0gfoundation/0g-ts-sdk`, `@0glabs/0g-serving-broker`, evmrpc-testnet.0g.ai, indexer-storage-testnet-turbo.0g.ai, rootHash or Merkle root identifiers, `ZgFile`/`MemData`/`Indexer`/`createZGComputeNetworkBroker`, TeeML/TeeTLS verification, or 0G-specific addresses (Flow/Mine/Reward contracts). Covers live-verified package pins, network gotchas (outbound port 5678), ledger funding minimums, the two-signer attestation model, the Centralized-vs-Separated TEE architecture distinction, and 24+ doc/reality mismatches found during production integration.
---

# 0G Labs (Chain + Storage + Compute)

Empirical guidance for building on the 0G stack. Every gotcha here was hit
during real integration work; many are **not** in upstream docs.

## What works, end-to-end

- **Chain** (Galileo testnet, chainId **16602**): standard EVM L1. Deploy
  with Hardhat, interact with ethers — identical to Ethereum.
- **Storage**: pure bytes → 32-byte keccak Merkle root. Upload lands an
  on-chain Flow tx (~6 s), download anywhere by the root hash (~1 s).
  Deterministic; no state needed on the download side.
- **Compute**: TEE-attested LLM inference on dstack/Intel TDX. ~2.9 s per
  prompt; per-response signature verification via `processResponse()` —
  with an important caveat about **Centralized vs Separated** providers
  (see Compute section).

## Package pins (use these exactly)

```json
{
  "@0gfoundation/0g-ts-sdk":     "1.2.2",
  "@0glabs/0g-serving-broker":   "0.7.5",
  "ethers":                      "^6.14.0"
}
```

**Install with `--legacy-peer-deps`** — `@0gfoundation/0g-ts-sdk` pins
`ethers@6.13.1` exact; hardhat-ethers wants `^6.14.0`. 6.16 works fine at
runtime with both. Node 20+. Npm scopes are split: Storage =
`@0gfoundation`, Compute = `@0glabs` (not interchangeable — the `0glabs`
GitHub org migrated to `0gfoundation` but the Compute package stayed on
the old scope).

---

## Cheatsheet — endpoints, addresses, prices

| Kind | Value |
|---|---|
| Chain name | `0G-Galileo-Testnet` |
| Chain ID | **`16602`** (NOT 16601 — ThirdWeb is stale) |
| Gas token | `0G` (digit zero), 18 decimals |
| EVM RPC | `https://evmrpc-testnet.0g.ai` |
| Block explorer | `https://chainscan-galileo.0g.ai` |
| Storage explorer | `https://storagescan-galileo.0g.ai` |
| Faucet (X login, flaky) | `https://faucet.0g.ai` |
| Faucet (Google login, works) | `https://cloud.google.com/application/web3/faucet/0g/galileo` |
| Faucet drip | 0.1 0G / day / wallet |
| EVM hardfork | **Shanghai** (no Cancun blobs — pin `evmVersion: "shanghai"`) |
| Storage Indexer (testnet turbo) | `https://indexer-storage-testnet-turbo.0g.ai` |
| Storage Indexer (testnet standard) | returns 503 — "under maintenance" — avoid |
| Storage: Flow contract (testnet) | `0x22E03a6A89B950F1c82ec5e74F8eCa321a105296` (SDK handles; don't pass manually) |
| Compute: ledger minimum | **3 0G** to create |
| Compute: per-provider minimum | **1 0G** locked |
| Compute: total to run inference | **4 0G** |

Live testnet chatbot models (via `broker.inference.listService()`):

| Model ID (literal) | Provider | Type | Verification |
|---|---|---|---|
| `qwen/qwen-2.5-7b-instruct` | `0xa48f01287233509FD694a22Bf840225062E67836` | chatbot | TeeML (dstack/TDX), **Centralized arch** |
| `qwen/qwen-image-edit-2511` | `0x4b2a941929E39Adbea5316dDF2B9Bd8Ff3134389` | image-editing | TeeML (dstack/TDX) |

Mainnet-only (per docs, not verified live): `qwen3.6-plus` (TeeTLS),
`GLM-5-FP8` (TeeML), `deepseek-chat-v3-0324`, `gpt-oss-120b`,
`qwen3-vl-30b-a3b-instruct`, `whisper-large-v3`, `z-image`.

---

## Chain (Hardhat)

```javascript
// hardhat.config.cjs — must be CJS if package.json has "type": "module"
require("@nomicfoundation/hardhat-ethers");
require("dotenv").config({ path: "../.env" });

module.exports = {
  solidity: {
    version: "0.8.24",
    settings: {
      evmVersion: "shanghai",  // NOT cancun — blob opcodes unavailable
      optimizer: { enabled: true, runs: 200 },
    },
  },
  networks: {
    ogGalileo: {
      url: "https://evmrpc-testnet.0g.ai",
      chainId: 16602,
      accounts: [process.env.DEPLOYER_PRIVATE_KEY],
    },
  },
};
```

Typical fees: ~0.002 0G per deploy. Block time ~2 s; full receipt ~13 s.

See `examples/0g/chain-deploy.cjs` for a working deploy script.

---

## Storage

### Identifier scheme

Content ID is **`0x` + 64 hex chars**, a keccak Merkle root over 256-byte
chunks grouped into 1 MB segments. **Not an IPFS CID.** No multicodec, no
base32, no CIDv1 prefix.

### Working upload + download

```javascript
import { Indexer, MemData } from "@0gfoundation/0g-ts-sdk";
import { ethers } from "ethers";

const EVM_RPC = "https://evmrpc-testnet.0g.ai";
const INDEXER = "https://indexer-storage-testnet-turbo.0g.ai";

const provider = new ethers.JsonRpcProvider(EVM_RPC);
const signer = new ethers.Wallet(process.env.DEPLOYER_PRIVATE_KEY, provider);
const indexer = new Indexer(INDEXER);

// Upload bytes — note the type-cast on signer (see gotcha below)
const [tx, err] = await indexer.upload(
  new MemData(new TextEncoder().encode(JSON.stringify({ hi: "world" }))),
  EVM_RPC,
  signer as unknown as never,           // ESM/CJS typing mismatch — see gotcha
  undefined,
  { Retries: 3, Interval: 5, MaxGasPrice: 0 },  // PASCALCASE — see gotcha
);
if (err) throw err;

// upload() returns a TAGGED UNION — must branch:
const rootHash = "rootHash" in tx ? tx.rootHash : tx.rootHashes[0];
const txHash   = "rootHash" in tx ? tx.txHash   : tx.txHashes[0];

// Download anywhere — no signer needed for read
const dlErr = await indexer.download(rootHash, "./out.json", /* withProof */ true);
```

Observed latency: ~6 s upload, ~1 s download with proof verification.

### Network gotcha — outbound port 5678 must be open (CRITICAL)

The SDK POSTs segment data directly to storage-node IPs on **plain HTTP
port 5678**. Many networks (corporate, some residential, some hotel WiFi)
block non-standard outbound ports. Symptom: SDK hangs 30 s at
`Starting upload for file of size: X bytes` then throws
`AxiosError: timeout of 30000ms exceeded`.

Workarounds: switch to a permissive network (mobile hotspot works), or
proxy through a server that has port 5678 outbound.

To list current storage nodes:
```bash
curl -X POST https://indexer-storage-testnet-turbo.0g.ai \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"indexer_getShardedNodes","id":1}'
```

### Encrypted-blob wire format (production pattern)

When storing sensitive payloads, the proven pattern is AES-256-GCM with
**IV prepended to ciphertext** (which already has the 16-byte auth tag
appended):

```javascript
// Pack: [12-byte IV][ciphertext + 16-byte auth tag]
function packEncrypted({ iv, ciphertext }) {
  const out = new Uint8Array(iv.byteLength + ciphertext.byteLength);
  out.set(iv, 0);
  out.set(ciphertext, iv.byteLength);
  return out;
}

function unpackEncrypted(blob) {
  if (blob.byteLength < 12) throw new Error("blob shorter than 12-byte IV");
  return { iv: blob.slice(0, 12), ciphertext: blob.slice(12) };
}
```

Symmetric pack/unpack means a single download yields both fields with no
separate metadata. Source: `immunity-sdk/src/storage/upload.ts:41–51`.

### Bigint JSON serialization (production pattern)

Bigints don't survive `JSON.stringify`. For struct data destined for
Storage (or any JSON pipe), whitelist the bigint fields:

```javascript
const BIGINT_FIELDS = new Set(["stakeAmount", "expiresAt", "createdAt"]);

const serialize = (obj) => Object.fromEntries(
  Object.entries(obj).map(([k, v]) =>
    [k, typeof v === "bigint" ? v.toString() : v])
);

const deserialize = (obj) => Object.fromEntries(
  Object.entries(obj).map(([k, v]) =>
    [k, BIGINT_FIELDS.has(k) && (typeof v === "string" || typeof v === "number")
      ? BigInt(v) : v])
);
```

Source: `immunity-sdk/src/gossip/envelope.ts:37–58`.

See `examples/0g/storage-upload.js` for a working round-trip.

---

## Compute (TEE-attested inference)

### Full working flow

```javascript
import { createZGComputeNetworkBroker } from "@0glabs/0g-serving-broker";
import { ethers } from "ethers";
import OpenAI from "openai";

const provider = new ethers.JsonRpcProvider("https://evmrpc-testnet.0g.ai");
const signer = new ethers.Wallet(process.env.DEPLOYER_PRIVATE_KEY, provider);
const broker = await createZGComputeNetworkBroker(signer);

// 1. One-time: create ledger with >=3 0G
try { await broker.ledger.getLedger(); }
catch { await broker.ledger.addLedger(3); }

// 2. Discover live providers
const services = await broker.inference.listService();
const svc = services.find(s => s.serviceType === "chatbot");
const providerAddr = svc.provider;

// 3. One-time per provider: ack signer + fund.
//    CRITICAL: wrap in try/catch — sends 2 txs internally; second is
//    idempotent but may throw. Without the catch, fresh wallets fail
//    later when processResponse rejects signatures.
try {
  await broker.inference.acknowledgeProviderSigner(providerAddr);
} catch (err) {
  console.warn("ackProviderSigner: already-acknowledged or non-fatal", err.message);
}
await broker.ledger.transferFund(providerAddr, "inference", ethers.parseEther("1"));

// 4. Verify TEE attestation (one-shot per provider; cache result)
const att = await broker.inference.verifyService(providerAddr, "./tee-reports");
if (!att.signerVerification.allMatch || !att.composeVerification.passed) {
  throw new Error("TEE attestation failed");
}
// SDK ALSO prints "manual steps 6-8" after this — they are OPTIONAL
// hardening, not required for the booleans above to be valid.

// 5. Per-call: fresh headers + OpenAI client (headers are SINGLE-USE)
const { endpoint, model } = await broker.inference.getServiceMetadata(providerAddr);
const headers = await broker.inference.getRequestHeaders(providerAddr, prompt);
const openai = new OpenAI({ baseURL: endpoint, apiKey: "" });
const completion = await openai.chat.completions.create(
  { model, messages: [{ role: "user", content: prompt }] },
  { headers },
);

// 6. Per-call: process response. NOTE: false is NON-FATAL on Centralized
//    providers — see Architecture caveat below.
let signedAndValid = false;
try {
  const result = await broker.inference.processResponse(
    providerAddr,
    completion.id,
    completion.choices[0].message.content,
  );
  signedAndValid = result === true;
} catch (err) {
  console.warn("processResponse threw — continuing", err.message);
}
```

Observed: ~2.9 s inference latency on testnet qwen-2.5-7b-instruct.

### Architecture: Centralized vs Separated providers (CRITICAL)

The TEE provider can run in one of two architectures, and `processResponse`
behavior differs:

| Architecture | What runs in TEE | `processResponse()` returns |
|---|---|---|
| **Separated** | Both broker AND LLM inside attested TEE | `true` on signature verification |
| **Centralized** | Broker in TEE; LLM via centralized provider over HTTPS | `false` legitimately — no per-response LLM-TEE signature exists |

**Galileo testnet's qwen-2.5-7b-instruct provider is Centralized.**
`processResponse() === false` is NOT a security failure there; it's the
correct response.

**The authoritative attestation gate is the broker:** if
`verifyService(provider).signerVerification.allMatch === true`, you have
a verified attested broker, regardless of `processResponse` per-call
result. Treat `signedAndValid = false` as a warning the policy layer can
choose to act on, not a hard abort.

Source: `immunity-sdk/src/tee/inference.ts:60–70`.

### `acknowledgeProviderSigner` sends 2 txs (production pattern)

The SDK's `acknowledgeProviderSigner` internally submits two on-chain
transactions in sequence. The second is idempotent — if the signer is
already acknowledged from a prior call, it throws. **Wrap in try/catch
unconditionally**, or the next `processResponse()` will fail with an
opaque signature-verification error.

Source: `immunity-sdk/src/tee/broker.ts:115–127`.

### TEE attestation — how it actually works

For Galileo's chatbot provider, `verifyService` performs 5 automated
checks:

1. Retrieve service metadata from the serving contract.
2. Parse TEE config (dstack verifier, Intel TDX).
3. Download broker + LLM attestation reports.
4. Signer address check: contract signer == report `signing_address`.
5. Docker compose hash check: calculated hash == event-log hash, both
   embedded in the TDX quote.

Then it prints "Steps 6–8" (manual image verification via sigstore, run
dstack verifier in a Docker container locally) — those are **optional
hardening**, not required for the returned booleans to be truthful.

For Galileo specifically, **two TEE signers are pinned per provider**:
- Broker: `0x83df4B8EbA7c0B3B740019b8c9a77ffF77D508cF`
- LLM: `0xc4045be3413B0B30ad0295985fe5e037Dc0EeB0c`

`signerVerification.allMatch` covers both.

### On-chain challenge game pattern

For verifiable AI verdicts:
1. Call inference, get `chatID` + `content`.
2. Call `processResponse()` (don't skip — it triggers payment settlement).
3. Store `{chatID, prompt, content, provider, timestamp}` on-chain.
4. Any challenger can later re-run `processResponse` off-chain against the
   pinned signer to independently verify.

See `examples/0g/compute-discover.js` and `examples/0g/compute-inference.js`.

---

## Gotchas — full list (24, all empirical)

### Chain
1. **Chain ID is 16602**, not 16601. ThirdWeb shows 16601 + "deprecated" — ignore, verify via RPC.
2. **Official faucet's X-login is unreliable.** Use Google Cloud Web3 faucet.
3. **Use `evmVersion: "shanghai"`** — Cancun opcodes (BLOBHASH, TLOAD, TSTORE) unavailable.
4. **Gas token is `"0G"` (digit zero), not `"OG"` (letter).**
5. **No public contract verification API.** Verify via Chainscan UI manually.
6. **Hardhat scripts must be `.cjs`** if root `package.json` has `"type": "module"`.

### Storage
7. **Outbound port 5678 must be open** — biggest single operational footgun.
8. **`upload()` returns a tagged union** — branch on `"rootHash" in tx`.
9. **`RetryOpts` is PascalCase** — `{ Retries, Interval, MaxGasPrice }`. camelCase silently no-ops. Source: `immunity-sdk/src/storage/indexer.ts:42`.
10. **Ethers pin conflict** — SDK pins `ethers@6.13.1` exact; use `--legacy-peer-deps`.
11. **Standard indexer is down** (503). Use Turbo only.
12. **Content-type is NOT stored.** Bundle a manifest if you need MIME.
13. **Not an IPFS CID.** Root hash is `0x` + 64 hex (keccak Merkle).
14. **ESM/CJS signer typing mismatch** — cast `signer as unknown as ...` because 0G SDK ships CJS typings on an ESM module. Source: `immunity-sdk/src/storage/indexer.ts:40`.

### Compute
15. **Funding minimums: 3 0G ledger + 1 0G sub-account = 4 0G total** before first inference. Faucet is 0.1/day — need Discord bump or mainnet tokens.
16. **`acknowledgeProviderSigner` sends 2 txs.** Second is idempotent but may throw. **Wrap in try/catch unconditionally.** Source: `immunity-sdk/src/tee/broker.ts:115–127`.
17. **`getServiceMetadata().endpoint` already includes `/v1/proxy`.** Pass directly to OpenAI client as `baseURL`.
18. **Model ID prefix is live, not stale** — `qwen/qwen-2.5-7b-instruct` literally (with `qwen/` prefix).
19. **Two TEE signers per provider** (broker + LLM). `signerVerification.allMatch` covers both.
20. **Manual verification Steps 6–8 are OPTIONAL.** The booleans from Step 5 are sufficient.
21. **Request headers are single-use.** Regenerate via `getRequestHeaders()` for every call.
22. **`processResponse() === false` is non-fatal on Centralized providers.** Galileo's qwen is Centralized; `false` is legitimate. Authoritative gate is `verifyService.signerVerification.allMatch`. Source: `immunity-sdk/src/tee/inference.ts:60–70`.
23. **Hackathon-prize models (`qwen3.6-plus`, `GLM-5-FP8`) are mainnet-only.** Testnet only has the 2 qwen models.
24. **Two different npm scopes** — Storage = `@0gfoundation`, Compute = `@0glabs`. Not interchangeable.

---

## Confirmed live artifacts (testnet)

| Artifact | Value |
|---|---|
| Sample Registry deployment | `0xfa5d12e8BC27F5aa5daB253E5BD8e6EeEAc159ee` |
| Sample rootHash | `0x450e544cbb15a6f7877fb7489cf84c5ed50d9f662f7f411c17f5ff02dc812504` |
| Sample compute chat | `chatcmpl-b1fc762221e74f38bfea90ea28988607` (qwen-2.5-7b, 2.9 s) |
| Broker TEE signer | `0x83df4B8EbA7c0B3B740019b8c9a77ffF77D508cF` |
| LLM TEE signer | `0xc4045be3413B0B30ad0295985fe5e037Dc0EeB0c` |

---

## When NOT to use 0G

- Your chain assumes Cancun blob opcodes (BLOBHASH, blob-carrying txs).
- You need a public-chain contract verifier API.
- Your deployment environment blocks non-standard outbound ports and you
  can't switch.
- You need the prize models on testnet — they're mainnet-only.

## References

- Docs: https://docs.0g.ai
- GitHub org: https://github.com/0gfoundation (NOT `0glabs` — migrated)
- In-repo: `examples/0g/{chain-deploy.cjs, storage-upload.js, compute-discover.js, compute-inference.js}` — runnable scripts.
