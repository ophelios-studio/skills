# Kintsugi example

A runnable demo of the developer surface in `@kintsugi/core`: composing a custom-call rescue batch when the auto-discovery doesn't cover your assets.

The script in this directory unstakes NFTs from a staking contract and transfers them to a safe wallet, all atomically inside one EIP-7702 Type-4 transaction. Same pattern applies to vesting `release()`, LP `burn()`, lending `withdraw()`, governance `unlock()`, approval `approve(spender, 0)`, and any other prerequisite-then-transfer flow.

## What this demonstrates

- **Three-wallet pattern.** Victim signs, rescuer pays gas, safe receives. The victim never holds ETH.
- **Custom-call composition.** `customCall({ to, abi, functionName, args })` builds an `Op`. Place it in `ops` array where the execution order requires.
- **Ordering matters.** `unstake` comes before the `transferErc721` ops so the NFTs are at the victim address by the time the transfers fire. Reverse order would revert.
- **One atomic transaction.** Either every op succeeds or the whole batch reverts; the NonceTracker increment rolls back too.
- **Reading the tracker nonce.** `NonceTracker.nonceOf(victim)` is the replay nonce, separate from the victim's account nonce.

## Layout

```
custom-rescue.ts    The script itself. ~120 lines, end-to-end.
package.json        @kintsugi/core + viem + tsx. Node >=20.
README.md           This file.
```

## Run

The script targets **Sepolia**. Mainnet deployment is pending audit; swap the chain + contract addresses at the top of the file when ready.

You need:

- A victim private key (whose NFTs are currently staked at `STAKING_CONTRACT`)
- A rescuer private key with about 0.005 ETH on Sepolia for gas
- A safe destination address (a fresh wallet generated from a brand-new seed)
- A Sepolia RPC URL — free Alchemy works, see `dashboard.alchemy.com`

Edit `STAKING_CONTRACT`, `NFT_CONTRACT`, and `STAKED_TOKEN_IDS` near the top of `custom-rescue.ts`, then:

```bash
npm install
VICTIM_PK=0x... \
RESCUER_PK=0x... \
SAFE_ADDRESS=0x... \
SEPOLIA_RPC=https://eth-sepolia.g.alchemy.com/v2/YOUR_KEY \
  npm run rescue
```

The script reads the tracker nonce, builds the batch, signs the EIP-712 batch and the EIP-7702 authorization with the victim key, has the rescuer submit one Type-4 transaction, and waits for inclusion. Output is one tx hash plus the block number on success.

## What this does NOT cover

- The CLI / UI surface. For interactive end-to-end rescues, run `kintsugi rescue` or `kintsugi ui` instead.
- ENS rescues, ERC-20 / ERC-721 / ERC-1155 transfers without a setup call. Those are auto-discovered by the CLI; you don't need a script.
- Hardware wallet signing. Not yet supported (roadmap).

For any of those, study the project source at `~/www/kintsugi/` directly — the canonical real-world flow.

## Reference

- Skill: `skills/kintsugi/SKILL.md`
- Live docs: kintsugi.ophelios.com
- Source: github.com/ophelios-studio/kintsugi
