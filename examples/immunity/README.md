# immunity examples

Three minimal scripts demonstrating the SDK against 0G Galileo testnet
plus a local AXL mesh. Each script is self-contained and runnable.

## What each script demonstrates

- `basic-agent.ts`, the quickstart. Construct an `Immunity`, gate a
  proposed transaction with `check()`, branch on `result.allowed`.
- `publisher.ts`, mint a fresh `ADDRESS` antibody as an operator.
  Locks 1 USDC on chain for 72 hours.
- `escalation.ts`, wire an `onEscalate` handler so SUSPICIOUS verdicts
  prompt a human (or another agent) before auto-blocking.

## What is NOT included

Refer to the production `immunity-demo` repo for the full fleet:
- 45 agents (traders, wolves, publishers, scenarios) running
  continuously against testnet.
- Auto-funding via the `ensureFundedWallet` helper.
- The TEE shim (using Anthropic Claude as a stand-in for 0G Compute
  during testnet flakes).
- Heartbeat logging into a Postgres ledger.

These examples deliberately strip all of that out so the SDK surface
stays visible.

## Setup

### 1. Bring up an AXL mesh

The SDK requires an external Gensyn AXL daemon. Use the 2-node mesh
template in the SDK's `infra/axl-mesh/`:

```bash
git clone https://github.com/ophelios-studio/immunity-sdk.git
cd immunity-sdk/infra/axl-mesh
make keys && make up
# alice listens at :9002, bob at :9012
```

### 2. Install dependencies

```bash
cd path/to/this/example
npm install
```

If your npm registry rejects with a peer-dep conflict, retry with
`npm install --legacy-peer-deps`. Not required by default.

### 3. Fund a testnet wallet

You need a wallet with a small OG balance for gas. The Galileo testnet
faucet is at https://faucet.0g.ai. Drop the resulting private key into
`WALLET_PRIVATE_KEY` (no `0x` prefix is fine, ethers normalizes).

The first run mints 1 MockUSDC and deposits 0.5 USDC into the Registry
prepaid balance. Subsequent runs reuse the balance.

## Running

```bash
# 1. Gate a tx
WALLET_PRIVATE_KEY=0x... \
AXL_URL=http://localhost:9002 \
  npx tsx basic-agent.ts

# 2. Publish an antibody (locks 1 USDC for 72h)
WALLET_PRIVATE_KEY=0x... \
AXL_URL=http://localhost:9002 \
TARGET_ADDRESS=0xCAFE... \
  npx tsx publisher.ts

# 3. Operator-in-the-loop on SUSPICIOUS
WALLET_PRIVATE_KEY=0x... \
AXL_URL=http://localhost:9002 \
  npx tsx escalation.ts
```

Expected output for `basic-agent.ts` against a fresh address:

```
[immunity] start
[immunity] balance ok (0.500000 USDC)
[immunity] check { to: 0xCAFE..., chainId: 16602 }
[immunity] decision { allowed: true, source: "policy", novel: true }
[immunity] stop
```

## Env vars

| Var | Required | Notes |
|---|---|---|
| `WALLET_PRIVATE_KEY` | yes | 0x-prefixed hex, holds OG for gas + USDC for fees |
| `AXL_URL` | yes | local AXL daemon HTTP endpoint, default `http://localhost:9002` |
| `TARGET_ADDRESS` | publisher.ts only | the address to flag as malicious |

## Troubleshooting

| Symptom | Fix |
|---|---|
| `MissingConfigError: axlUrl` | bring up `infra/axl-mesh` first |
| `ERR_INSUFFICIENT_BALANCE` | run `basic-agent.ts` once to mint + deposit USDC |
| storage upload hangs | port 5678 outbound is blocked; switch network |
| `processResponse rejected` | TEE provider rotated keys; rerun the script |
