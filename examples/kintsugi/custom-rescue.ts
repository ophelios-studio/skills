// Minimal custom-call rescue: unstake NFTs from a staking contract and
// transfer them to the safe wallet, all inside one atomic Type-4
// transaction. The auto-discovery in the CLI/UI does NOT surface
// staked positions, so for anything held outside the victim address
// you compose the batch yourself.
//
// Run with:
//   VICTIM_PK=0x...                                     \
//   RESCUER_PK=0x...                                    \
//   SAFE_ADDRESS=0x...                                  \
//   SEPOLIA_RPC=https://eth-sepolia.g.alchemy.com/v2/...\
//     npx tsx custom-rescue.ts
//
// All values stay in process memory. Nothing is logged or written to disk.
//
// This script targets Sepolia. Mainnet deployment pending audit; swap
// the chain + contract addresses when ready.

import {
  buildBatch,
  customCall,
  signBatch,
  signRescueAuthorization,
  submitRescue,
  transferErc721,
} from '@kintsugi/core'
import {
  createPublicClient,
  createWalletClient,
  http,
  type Address,
} from 'viem'
import { privateKeyToAccount } from 'viem/accounts'
import { sepolia } from 'viem/chains'

// ---------- Sepolia deployment ----------
const RESCUE_ADDRESS = '0x53c1f40ca0a58942f9eb89d7fd445457a8521fd5' as const
const TRACKER_ADDRESS = '0x717883abfa58fa2bf0f9c2d5a132227253c47963' as const

// ---------- Replace with the actual contract + token IDs you are rescuing
const STAKING_CONTRACT: Address = '0xYourStakingContractHere'
const NFT_CONTRACT: Address = '0xYourNftContractHere'
const STAKED_TOKEN_IDS = [1n, 2n, 3n]

// ---------- Inputs from env ----------
const victimPk = required('VICTIM_PK') as `0x${string}`
const rescuerPk = required('RESCUER_PK') as `0x${string}`
const safeAddress = required('SAFE_ADDRESS') as Address
const rpcUrl = required('SEPOLIA_RPC')

const victim = privateKeyToAccount(victimPk)
const rescuer = privateKeyToAccount(rescuerPk)

const transport = http(rpcUrl)
const publicClient = createPublicClient({ chain: sepolia, transport })
const rescuerClient = createWalletClient({ account: rescuer, chain: sepolia, transport })

// ---------- Read state we need to sign over ----------
const trackerNonce = (await publicClient.readContract({
  address: TRACKER_ADDRESS,
  abi: [{
    type: 'function',
    name: 'nonceOf',
    inputs: [{ name: '', type: 'address' }],
    outputs: [{ name: '', type: 'uint256' }],
    stateMutability: 'view',
  }],
  functionName: 'nonceOf',
  args: [victim.address],
})) as bigint

const victimAccountNonce = await publicClient.getTransactionCount({
  address: victim.address,
})

// ---------- Compose the batch ----------
// 1) Unstake first so the NFTs return to the victim address.
const unstakeOp = customCall({
  to: STAKING_CONTRACT,
  abi: [{
    type: 'function',
    name: 'unstake',
    inputs: [{ name: 'tokenIds', type: 'uint256[]' }],
    outputs: [],
    stateMutability: 'nonpayable',
  }],
  functionName: 'unstake',
  args: [STAKED_TOKEN_IDS],
})

// 2) Then transfer each NFT out to the safe wallet.
const transferOps = STAKED_TOKEN_IDS.map((id) =>
  transferErc721(NFT_CONTRACT, victim.address, safeAddress, id),
)

const batch = buildBatch({
  safe: safeAddress,
  ops: [unstakeOp, ...transferOps],
  nonce: trackerNonce,
  chainId: BigInt(sepolia.id),
})

// ---------- Sign + submit ----------
const signature = await signBatch({
  victim,
  batch,
  rescueAddress: RESCUE_ADDRESS,
  chainId: sepolia.id,
})

const authorization = await signRescueAuthorization({
  victim,
  rescueAddress: RESCUE_ADDRESS,
  chainId: sepolia.id,
  nonce: victimAccountNonce,
})

const txHash = await submitRescue({
  rescuer: rescuerClient,
  victimAddress: victim.address,
  batch,
  signature,
  authorization,
})

console.log('submitted Type-4 tx:', txHash)
console.log('https://sepolia.etherscan.io/tx/' + txHash)

const receipt = await publicClient.waitForTransactionReceipt({ hash: txHash })
console.log(receipt.status === 'success'
  ? `✓ included in block ${receipt.blockNumber}, ${STAKED_TOKEN_IDS.length} NFTs at ${safeAddress}`
  : `✗ reverted in block ${receipt.blockNumber}`)

// ---------- helpers ----------
function required(name: string): string {
  const v = process.env[name]
  if (!v) {
    console.error(`Missing required env var: ${name}`)
    process.exit(1)
  }
  return v
}
