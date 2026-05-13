// Minimal end-to-end driver for the codequill CLI.
//
//   codequill snapshot       (LOCAL only, nothing uploaded)
//   codequill publish        (anchors merkle_root + manifest CID on chain)
//   codequill wait           (blocks on confirmation)
//   codequill preserve       (optional, --preserve flag — triggers passkey prompt)
//
// This is NOT an SDK. There is no `@codequill/sdk` npm package. The right
// pattern when scripting CodeQuill from Node is to shell out to the CLI with
// `--json --no-confirm --no-wait` and parse the result — which is exactly
// what the `codequill-claim/actions-snapshot@v1` GitHub Action does
// internally. This script mirrors that flow.
//
// Run with:
//   codequill login                     # one-time, in this shell
//   cd <a-claimed-repo>                 # `codequill claim` must have run
//   node demo.mjs                       # snapshot + publish + wait
//   node demo.mjs --preserve            # ... and an encrypted preservation
//
// Requires Node >= 18 and the `codequill` CLI on PATH (`npm i -g codequill`).

import { spawnSync } from 'node:child_process'
import { argv, exit } from 'node:process'

const FLAGS = new Set(argv.slice(2))
const WANT_PRESERVE = FLAGS.has('--preserve')

// ---------- helpers ----------

function runJson(label, args) {
    const res = spawnSync('codequill', args, { encoding: 'utf8' })
    if (res.status !== 0) {
        process.stderr.write(`✗ ${label} failed (exit ${res.status})\n`)
        if (res.stderr) process.stderr.write(res.stderr)
        exit(res.status ?? 1)
    }
    const stdout = (res.stdout || '').trim()
    try {
        return JSON.parse(stdout)
    } catch {
        process.stderr.write(`✗ ${label}: stdout was not JSON:\n${stdout}\n`)
        exit(1)
    }
}

function runText(label, args) {
    const res = spawnSync('codequill', args, { encoding: 'utf8', stdio: 'inherit' })
    if (res.status !== 0) {
        process.stderr.write(`✗ ${label} failed (exit ${res.status})\n`)
        exit(res.status ?? 1)
    }
}

// ---------- 1) verify the operator is logged in ----------
// `codequill who --json` returns the authenticated user. If not logged in,
// the CLI exits non-zero with a clear "Run codequill login" message — see
// src/services/utilities.ts:assertAuthenticated.
const who = runJson('who', ['who', '--json'])
console.log(`✓ logged in as ${who.email ?? who.user_id ?? '(unknown)'}`)

// ---------- 2) verify the repo is claimed ----------
// `codequill status --json` includes claim state for the current repo
// derived from `git remote get-url origin` (src/services/git.ts:deriveRepoName).
// We don't fail-hard if status shape evolves; we just print and continue.
const status = runJson('status', ['status', '--json'])
if (status.claim_state && status.claim_state !== 'claimed') {
    process.stderr.write(`✗ repo is not claimed (state=${status.claim_state}). Run: codequill claim\n`)
    exit(1)
}
console.log(`✓ repo claimed: ${status.repo_name ?? '(unknown)'}`)

// ---------- 3) snapshot (local only) ----------
// `snapshot` writes `.codequill/snapshots/snapshot-<shortCommit>.json` and
// updates `.codequill/snapshots/.index.json`. Nothing leaves the machine.
// See src/commands/snapshot.ts. We don't need `--json` here — just run it.
console.log('→ creating snapshot ...')
runText('snapshot', ['snapshot'])

// ---------- 4) publish ----------
// `publish --no-confirm --no-wait --json` returns
//   { snapshot_id, tx_hash, manifest_cid, explorer_url, ... }
// See src/commands/publish.ts. `--no-wait` returns immediately with the
// tx_hash so we can poll separately in the next step.
console.log('→ publishing snapshot ...')
const pub = runJson('publish', ['publish', '--no-confirm', '--no-wait', '--json'])
console.log(`  snapshot_id : ${pub.snapshot_id}`)
console.log(`  tx_hash     : ${pub.tx_hash}`)
console.log(`  manifest CID: ${pub.manifest_cid}`)
if (pub.explorer_url) console.log(`  explorer    : ${pub.explorer_url}`)

// ---------- 5) wait for confirmation ----------
// `wait <tx_hash> --json` polls `/v1/cli/tx/<hash>` until confirmed or the
// timeout fires (default 5 min). See src/services/txWaiter.ts.
console.log('→ waiting for on-chain confirmation ...')
runText('wait', ['wait', pub.tx_hash, '--json'])
console.log('✓ confirmed')

// ---------- 6) optional: preserve ----------
// `preserve [snapshot_id] --no-confirm --no-wait --json` encrypts a git
// archive of the commit and uploads it. snapshot_id is optional — when
// omitted the CLI falls back to the latest published snapshot for this
// repo (commit c9ef22a in src/commands/backup.ts). Triggers a passkey
// prompt in the browser, so we gate this behind --preserve to avoid
// surprising the user on every demo run.
if (WANT_PRESERVE) {
    console.log('→ creating encrypted preservation ...')
    const back = runJson('preserve', ['preserve', pub.snapshot_id, '--no-confirm', '--no-wait', '--json'])
    console.log(`  backup_id : ${back.backup_id}`)
    console.log(`  archive CID: ${back.backup_cid}`)
    console.log(`  tx_hash    : ${back.tx_hash}`)
    if (back.explorer_url) console.log(`  explorer   : ${back.explorer_url}`)

    console.log('→ waiting for preservation tx ...')
    runText('wait', ['wait', back.tx_hash, '--json'])
    console.log('✓ preservation confirmed')
    console.log('  (reminder: losing the workspace passkey makes this preservation permanently unreadable.)')
}

console.log('done.')
