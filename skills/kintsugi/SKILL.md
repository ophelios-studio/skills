---
name: kintsugi
description: Use when working with Kintsugi, the EIP-7702 wallet rescue tool that moves assets out of compromised EVM wallets atomically without the victim ever holding ETH. Triggers on the `kintsugi` CLI command, the npm packages `@ophelios/kintsugi-cli` and `@ophelios/kintsugi-core`, imports of `transferErc20` / `transferErc721` / `transferErc1155` / `transferUnwrappedEth2ld` / `transferWrappedName` / `transferUnwrappedSubdomain` / `customCall` / `buildBatch` / `signBatch` / `signRescueAuthorization` / `submitRescue`, references to the deployed `Rescue.sol` and `NonceTracker.sol` contracts (Sepolia 0x53c1f40c... and 0x717883ab...), the three-wallet pattern (victim, rescuer, safe), Type-4 / SetCode transactions in the rescue context, EIP-712 batch signing with deadlines and tracker nonces, sweeper-bot recovery flows, drainer-blocked wallets, ENS/NFT/ERC-20 rescue planning, the `kintsugi rescue|ui|status|revoke` subcommands, the localhost UI launched from the CLI, or any task framed as "my wallet keys leaked, move my assets to a fresh wallet without a sweeper grabbing the gas." Covers the three-wallet pattern, the atomic-batch contract, ordering rules (unstake-before-transfer, ENS reclaim-before-registrant), discovery scope (Alchemy primary, Etherscan + on-chain getLogs fallback), the NonceTracker singleton (replay protection that survives delegated execution), the EIP-712 domain pinning trick, RPC requirements (free public RPCs reject the wide block ranges; Alchemy free-tier is the recommended path), the `--private-mempool` Flashbots Protect option, custom calls for non-standard assets (vesting, LPs, staked NFTs), the localhost token-fragment auth model, and empirical patterns from the production rescue-wallet incident the project was built on.
---

# Kintsugi - EIP-7702 wallet rescue

Atomic, sweeper-proof wallet rescue. When a wallet's private key leaks, a "sweeper bot" usually drains any incoming ETH the moment it lands, breaking every normal recovery path. Kintsugi sidesteps the problem entirely: a separate rescuer wallet pays gas and submits one EIP-7702 Type-4 transaction that delegates the victim address to an audited Rescue contract and atomically transfers every selected asset to a fresh safe wallet. The victim's balance never rises above zero, so the sweeper has no opening.

The reference implementation is `~/www/kintsugi/`. Both Sepolia and Mainnet are deployed and verified. Mainnet is self-audited (see `AUDIT.md` in the repo); no third-party audit yet.

## Package basics

| Field | Value |
|---|---|
| CLI binary | `kintsugi` |
| npm packages | `@ophelios/kintsugi-cli` (user-installed CLI) and `@ophelios/kintsugi-core` (its TypeScript library dep). The web UI is bundled inside the CLI; not a separate npm package. |
| Version | `0.9.0` (mainnet-ready) |
| License | MIT |
| Repo | github.com/ophelios-studio/kintsugi |
| Site | kintsugi.ophelios.com |
| Node | `>=20` |
| Chains | Sepolia (verified), Mainnet (verified, self-audited) |
| Hardfork required | Pectra (EIP-7702, May 2025) |
| Deps (core) | `viem` |

## Install

```bash
npm install -g @ophelios/kintsugi-cli
kintsugi --help
```

Or work against the source tree (`~/www/kintsugi/`):

```bash
cd ~/www/kintsugi
npm install
npm run build:all
npm --workspace @ophelios/kintsugi-cli link
```

## The four CLI commands

| Command | Purpose |
|---|---|
| `kintsugi rescue` | Interactive end-to-end rescue in the terminal. The headless surface. |
| `kintsugi ui` | Launches a localhost-bound web UI (default `:38080`) and opens the browser to a token-protected URL. |
| `kintsugi status <addr>` | Read-only inventory of any wallet (ETH, code, ERC tokens, NFTs, ENS). |
| `kintsugi revoke` | Submit a Type-4 transaction that clears a victim's 7702 delegation back to a pure EOA. Optional, post-rescue. |

`rescue` flags worth knowing: `-p, --private-mempool` (route through Flashbots Protect), `--etherscan-api-key <key>`, `--rescue-address <addr>`, `--tracker-address <addr>`.

## The three-wallet pattern (load this, it explains the trust model)

| Wallet | Holds | Signs | Pays gas | Receives assets |
|--------|-------|-------|----------|-----------------|
| Victim | nothing during rescue | EIP-7702 auth + EIP-712 batch | no | no |
| Rescuer | ~0.005 ETH for gas | the outer Type-4 transaction | yes | no |
| Safe | nothing before, everything after | nothing | no | yes |

The victim never receives ETH at any point. There is nothing for a sweeper to take. The rescuer cannot substitute its own batch (signature verification on the inner EIP-712 batch is checked against the victim's address) and never holds rescued assets. The safe must be generated from a brand-new seed, not the compromised one.

## Minimum viable end-to-end (CLI)

```bash
$ kintsugi rescue
? Network: › Sepolia
? Rescue contract: 0x53c1f40c…21fd5
? NonceTracker contract: 0x717883ab…47963
? Victim private key: ****************
? Safe destination address: 0x906709Db5C107981c106490902b505836092f26A
? Rescuer wallet: › Generate a fresh wallet
  → fund 0xEcfe...D6B6 with ~0.005 ETH, press Enter
✓ rescuer balance: 0.01 ETH

Discovering assets...  ✓ MOCK 100, 3 NFTs

Batch (4 ops)
  1. transfer MOCK 100 → safe
  2-4. transfer MockNFT #N → safe

Estimated gas: 412 000  Cost: 0.000000494 ETH
? Sign and submit? (y/N) y

  tx: 0xd91f44ce... (block 10822054)
Done. All 4 assets transferred.
```

The UI (`kintsugi ui`) walks through the same five phases (Network → Wallets → Discover → Plan → Submit) in a localhost browser tab. The browser only sees session-bound addresses and signatures. Private keys live in the local Node process memory only.

## The library surface (`@ophelios/kintsugi-core`)

When the auto-discovery covers your assets, you don't write any code. When it doesn't, drop down to the library and compose your own batch.

### Operations (one `Op` per asset transfer or setup call)

```ts
import {
  transferErc20,            // ERC-20 transfer(to, amount)
  transferErc721,           // ERC-721 transferFrom(from, to, id)
  transferErc1155,          // ERC-1155 safeTransferFrom(from, to, id, amount, data)
  transferUnwrappedEth2ld,  // ENS .eth 2LD: returns [reclaim, safeTransferFrom]
  transferWrappedName,      // NameWrapper safeTransferFrom (2LD or subdomain)
  transferUnwrappedSubdomain, // ENS registry setOwner
  customCall,               // arbitrary contract call by ABI fragment
  setResolver,              // ENS post-rescue cleanup
  clearReverseRecord,       // ENS post-rescue cleanup
} from '@ophelios/kintsugi-core'
```

Each returns an `Op` (or a 2-tuple for unwrapped 2LDs):

```ts
type Op = { to: Address; value: bigint; data: Hex }
```

### Composing a batch and submitting

```ts
import {
  buildBatch,
  signBatch,
  signRescueAuthorization,
  submitRescue,
} from '@ophelios/kintsugi-core'

const batch = buildBatch({ safe, ops, nonce: trackerNonce, chainId: 1n })
const signature = await signBatch({ victim, batch, rescueAddress, chainId: 1 })
const authorization = await signRescueAuthorization({
  victim,
  rescueAddress,
  chainId: 1,
  nonce: victimAccountNonce, // viem getTransactionCount on victim
})
const txHash = await submitRescue({
  rescuer,
  victimAddress: victim.address,
  batch,
  signature,
  authorization,
})
```

`victim` and `rescuer` are viem `PrivateKeyAccount` / `WalletClient` respectively. `trackerNonce` comes from `NonceTracker.nonceOf(victimAddress)`. `victimAccountNonce` comes from `publicClient.getTransactionCount({ address: victimAddress })`.

### Custom calls (the developer surface)

```ts
const unstakeOp = customCall({
  to: stakingContract,
  abi: [{
    type: 'function',
    name: 'unstake',
    inputs: [{ name: 'tokenIds', type: 'uint256[]' }],
    outputs: [],
    stateMutability: 'nonpayable',
  }],
  functionName: 'unstake',
  args: [[1n, 2n, 3n]],
})

const ops = [
  unstakeOp,                                                  // returns NFTs to victim
  transferErc721(nftContract, victim.address, safe, 1n),       // then transfer
  transferErc721(nftContract, victim.address, safe, 2n),
  transferErc721(nftContract, victim.address, safe, 3n),
]
```

This is the pattern for any non-standard asset: vesting `release()`, LP `burn()`, lending `withdraw()`, governance `unlock()`, approval `approve(spender, 0)`, etc. Place the prerequisite call before the transfer that depends on it.

## Atomic batch + ordering rules (LOAD-BEARING)

The Rescue contract loops through `batch.ops` in array order. Any failure reverts the entire transaction (and the NonceTracker increment). There is no partial state.

Two concrete ordering rules to remember:

- **Unstake before transfer.** NFTs locked in a staking contract are at the staking contract, not the victim. Unstake first, then transfer.
- **ENS reclaim before registrant transfer.** For an unwrapped `.eth` 2LD, `baseRegistrar.reclaim(tokenId, safe)` must come before `baseRegistrar.safeTransferFrom(victim, safe, tokenId)`. Reverse order strands the controller. `transferUnwrappedEth2ld()` already returns the two ops in the correct sequence.

## Discovery scope and providers

| Asset type | Auto-discovered | Source |
|---|---|---|
| ETH balance | yes | `publicClient.getBalance` |
| ERC-20 | yes | Alchemy `alchemy_getTokenBalances` (preferred), Etherscan v2 fallback |
| ERC-721 | yes | Alchemy NFT API v3 (preferred), per-collection on-chain `Transfer` log scan in 45k-block chunks (fallback) |
| ERC-1155 | yes | Same as ERC-721 |
| ENS unwrapped 2LD | yes | ENS subgraph |
| ENS wrapped | yes | ENS subgraph |
| ENS unwrapped subdomain | yes | ENS subgraph |
| Vesting / staking / LP / custom | NO | Use `customCall` |

## Critical gotcha: free public RPCs do not work

Free public endpoints (publicnode, etc.) are rate-limited and reject the wide block ranges Kintsugi scans. The CLI and UI both refuse to proceed without an authenticated RPC.

The recommended path is a free Alchemy account: sign up at `dashboard.alchemy.com`, copy the API key (just the bare key, not the full URL, the server tolerates either), paste it on the Network step. Alchemy's same key drives both chain reads and asset discovery.

Custom RPC URLs (Infura, QuickNode, your own node) work too. When set, they override the Alchemy-derived URL for chain reads; discovery still uses Alchemy if a key is set.

## The NonceTracker pattern (why it exists, briefly)

Storing a per-victim replay nonce inside the Rescue contract would write to the victim's storage during delegated execution. Any other 7702 delegate the victim later sets could read or overwrite the slot, breaking replay protection.

`NonceTracker` is a separate singleton. Its `increment()` uses `msg.sender` as the key, so when the Rescue contract code (running delegated at the victim's address) calls `NonceTracker.increment()`, the increment lands at `nonceOf[victim]` inside the NonceTracker's storage. The victim's storage is never touched. Read this pattern; it's an EIP-7702 design tax that any 7702-delegate contract has to deal with.

## Replay protection layers

A signed batch carries `(safe, ops, nonce, deadline, chainId)`. The contract enforces all five:

- `block.chainid == batch.chainId` (cross-chain replay)
- `block.timestamp <= batch.deadline` (liveness; CLI defaults to ~30 min from sign time)
- `batch.nonce == NonceTracker.nonceOf(victim)` (replay)
- EIP-712 signature recovers to `address(this)` which under delegation equals the victim
- The EIP-712 domain separator pins `verifyingContract` to the deployed Rescue address (immutable, set in constructor), signatures on Sepolia don't replay on mainnet even if the same delegation exists on both

## Empirical gotchas

- **Generated rescuer pauses for funding**, then polls. After "Generate a fresh wallet", the CLI/UI pauses while you fund the address. The UI live-polls the rescuer balance every 4 seconds and unlocks "Sign and submit" automatically when it's funded. The CLI waits for Enter.
- **Type-4 transaction inclusion**, usually one block. The CLI/UI poll the receipt every 4s. The UI used to cap at 60 iterations (4 minutes) and freeze on slow blocks; the current bin polls indefinitely until cancelled.
- **The victim wallet ends up with a 7702 delegation pointer baked into its code slot.** That's normal. Run `kintsugi revoke` to clear it back to a pure EOA, or leave it (the Rescue contract requires a fresh victim signature for any further call, so the delegation does no harm sitting there).
- **Approvals are NOT auto-revoked.** If the victim previously granted token allowances, the attacker can still spend them after the rescue (the relevant assets are gone, but be aware). Add `customCall(token, 'approve', [spender, 0n])` ops to the batch if you want to revoke approvals atomically with the rescue.
- **Browser autofill on the PK field is suppressed via a custom `SecretInput`** that uses `type="text"` plus an overlay of `●` characters. Do not switch it back to `type="password"`, Safari, 1Password, Bitwarden, and friends all detect and offer to save password fields, and a victim's PK in a password manager is a security hole. The `-webkit-text-security: disc` CSS trick is also a Safari heuristic; don't use it either.

## Localhost UI auth model

`kintsugi ui` binds Hono to `127.0.0.1` only (other LAN machines cannot reach it). It generates a session token (UUID) at startup, opens the browser to `http://127.0.0.1:38080/#t=<token>`, token in the URL fragment, never in the request line. The UI reads it and attaches `X-Kintsugi-Token` on every API call. Without the token all routes return 401. Sessions are in-memory, wiped on `Ctrl+C`.

## Three-wallet provisioning script

For testing on Sepolia, `~/www/kintsugi/scripts/provision-test-victim.mjs` generates a fresh victim, mints 100 MOCK + 3 MockNFTs to it, generates a fresh rescuer, funds the rescuer with 0.01 ETH from `DEPLOYER_PK`, and prints all three private keys plus the contract addresses for paste-into-UI testing. Run it with `SEPOLIA_RPC_URL=...your alchemy url... node scripts/provision-test-victim.mjs` from the kintsugi project root.

## Key rules

- **The victim never holds ETH at any point** during a rescue. Don't suggest "fund the victim then transfer"; that's the failure mode this tool exists to bypass.
- **Three wallets, not two.** Victim signs, rescuer pays gas, safe receives. Don't co-mingle.
- **The safe must be from a brand-new seed.** Reusing the compromised seed re-exposes recovered assets.
- **Op order is execution order.** Setup ops (unstake, claim, reclaim) come before the transfers that depend on them.
- **Free public RPCs do not work.** Default the user to a free Alchemy account.
- **Use `customCall` for anything not in the auto-discovery surface.** Vesting, staking, LP, governance, approvals, all custom calls. Place them in the batch where the order requires.
- **Never log private keys.** The CLI deliberately holds them in memory, masks input, and never includes them in error output. Maintain that discipline in any code that consumes the library.
- **Always include a `deadline`.** `buildBatch` does this for you (~30 min default). Don't pass a far-future deadline; if a flow takes longer than expected, re-sign rather than risk a stale signature being scooped.
- **Mainnet is not yet audited.** Recommend Sepolia for any test or demo. For real mainnet rescues today, link the user to the security page so they decide with full information.
- **`kintsugi revoke` is optional.** The 7702 delegation does no harm sitting there post-rescue. Only suggest revoke if the user has a specific reason to clean up the code slot.

## References

- Live docs: **kintsugi.ophelios.com**
- Source: github.com/ophelios-studio/kintsugi
- The reference rescue project (where the empirical patterns came from): `~/www/kintsugi/`
- EIP-7702 spec: eips.ethereum.org/EIPS/eip-7702
- EIP-712 spec: eips.ethereum.org/EIPS/eip-712
- Sepolia Rescue contract: 0x53c1f40ca0a58942f9eb89d7fd445457a8521fd5
- Sepolia NonceTracker: 0x717883abfa58fa2bf0f9c2d5a132227253c47963
- In-repo example: `examples/kintsugi/`, a runnable custom-call rescue script
