# 0g examples

Minimal runnable scripts that exercise each 0G component live on Galileo
testnet. All four worked end-to-end during integration; tx hashes in the
parent skill confirm the round trips.

## Setup

```bash
# from a project root
npm install --legacy-peer-deps \
  @0gfoundation/0g-ts-sdk@1.2.2 \
  @0glabs/0g-serving-broker@0.7.5 \
  ethers@^6.14.0 \
  openai@^4.0.0 \
  dotenv

cat > .env <<EOF
DEPLOYER_PRIVATE_KEY=0x...
EOF
```

The wallet needs:
- ~0.005 0G to deploy a small contract
- ~0.001 0G + Flow contract fee to upload to Storage
- ~4 0G to bootstrap Compute (3 ledger + 1 per-provider, both refundable)

Get tokens from https://cloud.google.com/application/web3/faucet/0g/galileo
(Google login — works; the X-login faucet at faucet.0g.ai is flaky).

## Scripts

| Script | What it does |
|---|---|
| `chain-deploy.cjs` | Hardhat-style deploy of the EvidenceRegistry contract from zerog-exploration. Run via `forge script` or copy into a Hardhat project. |
| `storage-upload.js` | Upload a JSON blob → 32-byte Merkle root → download it back. Verifies port 5678 is reachable. |
| `compute-discover.js` | Lists live `chatbot` providers without spending any 0G. Use this first to confirm the SDK is wired correctly. |
| `compute-inference.js` | Full TEE flow: ledger create, ack signer (try/catch), fund sub-account, verifyService, inference, processResponse. Costs ~3 0G locked + 3 mOG gas. |

Run `node compute-discover.js` first to verify your wallet can talk to
the broker. Then `compute-inference.js` for the full end-to-end (needs
funds). See the parent `SKILL.md` for the gotchas these scripts navigate.
