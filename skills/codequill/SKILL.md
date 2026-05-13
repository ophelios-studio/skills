---
name: codequill
description: Use when working with CodeQuill — on-chain source-evidence infrastructure for software, anchored on Ethereum, stored on IPFS, encrypted with passkey-bound workspace keys. CLI is `codequill` (npm package `codequill`, v0.11.0+, Node >= 18). Triggers on the `codequill {login,who,quota,status,log,claim,snapshot,publish,pull,attest,prove,verify-proof,verify-attestation,preserve,wait,why}` commands; the `codequill-claim/actions-snapshot@v1` and `codequill-claim/actions-attest@v1` GitHub Actions; the smart contracts `CodeQuillDelegation`, `CodeQuillWorkspaceRegistry`, `CodeQuillRepositoryRegistry`, `CodeQuillSnapshotRegistry`, `CodeQuillReleaseRegistry`, `CodeQuillAttestationRegistry`, `CodeQuillPreservationRegistry`; the seven primitives (claims, snapshots, releases, attestations, preservations, proofs, trust index); the `.codequill/` workspace directory (`snapshots/`, `proofs/`, `config.json`, `.index.json`); the env vars `CODEQUILL_TOKEN`, `CODEQUILL_API_BASE_URL`, `CODEQUILL_BASE_URL`, `CODEQUILL_GITHUB_ID`, `CODEQUILL_CONFIG_DIR`; the manifest schemas `codequill-snapshot:v1`, `codequill-attestation:v1`, `codequill-proof:v1`, `codequill-backup:v1`, `codequill-envelope:v1`, the gitlink label `codequill-gitlink:v1:<oid>`; the `app.codequill.xyz/badges/{claim,snapshot,trust}/<repo-uuid>` README badges; or any task framed as "claim authorship of this repo on-chain", "snapshot the source", "publish the snapshot to IPFS + Ethereum", "attest this build against a release", "preserve an encrypted source archive", "prove a file was in this snapshot". Covers the seven-primitive evidence chain, the two device-code flows (login and prove), the on-disk token-refresh lock pattern, the `merkle_root` (path-salted, on-chain) vs `content_root` (public, salt-free) split, the `codequill-envelope:v1` envelope (AES-256-GCM + libsodium `crypto_box_seal` X25519 DEK wrap), the `preserve` fallback to latest published snapshot, the launcher's `node_tls_reject_unauthorized` → `NODE_TLS_REJECT_UNAUTHORIZED=0` env-bridge for dev, the `.codequill/config.json` repo-local override that beats global config, the `codequill-gitlink:v1:<oid>` submodule synthesis, the deliberate removal of the on-disk file-read fallback in `prove` (Windows `core.autocrlf` footgun), the server-enforced "release must be ACCEPTED before attest", the docs-site shape quirk (section-level URLs 404), and the loud non-guarantees (CodeQuill records evidence; it does **not** prove build causality).
---

# CodeQuill CLI

On-chain source-evidence platform. The `codequill` CLI claims repositories,
snapshots commits, publishes Merkle roots to Ethereum + IPFS, attests build
artifacts against governance-accepted releases, preserves encrypted source
archives, and generates offline-verifiable proofs of file inclusion. The
canonical reference implementation lives at `~/www/codequill-cli/`.

**Loud disclaimer up front:** CodeQuill records *that* a workspace authority
published a given source state at a given time — it does **not** prove the
artifact was actually built from that source, does **not** verify correctness,
and does **not** replace reproducible builds. Merkle proofs prove inclusion
only, never exclusion. If a passkey is lost, encrypted preservations become
permanently unreadable. The CLI's own `codequill why` command repeats these
caveats per topic; do not oversell what CodeQuill does.

## Package basics

| Field | Value |
|---|---|
| npm package | `codequill` |
| CLI binary | `codequill` (bin → `dist/launcher.js`) |
| Version | 0.11.0 (per `package.json`) |
| Node | `>= 18.0.0` |
| License | MIT |
| CLI repo | github.com/codequill-claim/cli (the actual checkout is `~/www/codequill-cli/`) |
| API | `https://api.codequill.xyz` (override via `CODEQUILL_API_BASE_URL`) |
| Web app | `https://app.codequill.xyz` (override via `CODEQUILL_BASE_URL`) |
| Docs | `https://docs.codequill.xyz` (see the URL-shape quirk in *Empirical gotchas*) |
| Chain | Ethereum + Sepolia testnet |
| IPFS pin | Lighthouse |
| Runtime deps | `commander`, `ethers`, `kleur`, `libsodium-wrappers`, `ora`, `p-limit`, `tar`, `zod` |

## The seven primitives

| Primitive | Where it lives | Created by | Notes |
|-----------|----------------|------------|-------|
| **Claim** | On-chain (`CodeQuillRepositoryRegistry`) | CLI (`codequill claim`) | One-time, gasless via relayer, binds repo → workspace authority. |
| **Snapshot** | Local only until published; manifest goes to IPFS, Merkle root to chain (`CodeQuillSnapshotRegistry`) | CLI (`codequill snapshot` then `publish`) | `snapshot` produces nothing on the network; `publish` is the upload step. |
| **Release** | Web-app draft → on-chain anchored → governance accepts/rejects (`CodeQuillReleaseRegistry`) | **Web app only** | The CLI never creates releases. Mistake #1 to flag. |
| **Attestation** | On-chain digest + IPFS manifest (`CodeQuillAttestationRegistry`) | CLI (`codequill attest`) | API rejects unless release is ACCEPTED and all release snapshots are anchored. |
| **Preservation** | Encrypted blob on IPFS + on-chain anchor (`CodeQuillPreservationRegistry`) | CLI (`codequill preserve`) | Zero-custody encryption; passkey loss = permanent loss. |
| **Proof** | Self-contained JSON file; verification is offline | CLI (`codequill prove`) | Requires passkey approval via a *second* device-code flow. |
| **Trust Index** | Derived score (0-100), recalculated daily from on-chain signals | Backend computation | Self-attestation caps ~70/100; the rest requires external attestors. |

The supporting contracts not in the table: `CodeQuillWorkspaceRegistry`
(workspace + membership) and `CodeQuillDelegation` (scoped/expiring
delegations from authority to platform). Solidity ^0.8.24, compiled 0.8.28,
Hardhat Ignition, OpenZeppelin 5.4.0.

## The full CLI surface

All 16 registered commands. The launcher (`dist/launcher.js`) reads config
+ env, then spawns the real entry (`dist/index.js`) with adjusted env.

### Auth & account

| Command | What it does |
|---|---|
| `codequill login` | Device-code flow: `POST /v1/cli/auth/init` returns a session_id + approval URL + phrase; CLI polls `/v1/cli/auth/token` every ~5s up to 3 min. On approval saves `access_token` + `refresh_token` to `~/.config/codequill/tokens.json` with `0600`. |
| `codequill who` | `GET /v1/cli/who`. Prints authenticated user. |
| `codequill quota [--json]` | `GET /v1/cli/quota?repo_name=<derived>`. Prints plan + usage. |

### Repo state

| Command | What it does |
|---|---|
| `codequill claim` | Auto-detects repo from `git remote get-url origin`, calls `/v1/cli/claim/prepare` then `/v1/cli/claim`. Gasless (relayed). Flags: `--no-confirm`, `--confirmations <n>` (default 1), `--timeout <ms>`, `--no-wait`, `--json`. |
| `codequill status` | `GET /v1/cli/status?repo_name=…`. Shows claim state + recent snapshots + local-vs-chain sync. |
| `codequill log [--limit <n>]` | Walks the local snapshot index, enriches with backend metadata. Newest first, de-duplicated by merkle_root. |
| `codequill pull` | Downloads all published snapshot manifests for this repo into `.codequill/snapshots/`. Requires auth. |

### Evidence production

| Command | What it does |
|---|---|
| `codequill snapshot` | Builds a `codequill-snapshot:v1` manifest **locally**, no upload. Flags: `--commit <hash>` (default HEAD), `--concurrency <n>` (default 8), `--salt <hex>` (64 hex chars; random if omitted), `--print-salt`. Writes `.codequill/snapshots/snapshot-<shortCommit>.json` and updates `.codequill/snapshots/.index.json`. |
| `codequill publish [commit]` | Gzips the local manifest, `POST /v1/cli/publish` as multipart. Anchors `merkle_root + commit + manifest_cid` on chain. Returns `{snapshot_id, tx_hash, manifest_cid, explorer_url, …}`. Default commit = HEAD. Same wait flags as `claim`. |
| `codequill attest <build> <releaseId>` | `<build>` may be a file or directory (auto-tarred to deterministic `tar.gz`). Computes sha256, builds `codequill-attestation:v1`, `POST /v1/cli/attest` as multipart. Flags: `--subject-name`, `--subject-version`, `--upstream <purl>` (repeatable), plus wait flags + `--json` + `--no-confirm`. |
| `codequill preserve [snapshotId]` | Encrypted source backup. `snapshotId` is **optional**: when omitted, falls back to the most-recently-published snapshot in the local index. AES-256-GCM with random DEK; DEK wrapped to workspace X25519 pubkey via libsodium `crypto_box_seal`. `POST /v1/cli/backup` as multipart. Same wait flags. |
| `codequill prove <file> <snapshotId>` | Generates a `codequill-proof:v1` Merkle inclusion proof. **Requires a second device-code flow** to obtain the salted `path_hash` from the workspace passkey (`POST /v1/cli/decrypt/init` → poll `/v1/cli/decrypt/result`, 3-min timeout). Flags: `--disclose` (include plaintext path), `--out <file>`. |

### Verification (offline; no auth)

| Command | What it does |
|---|---|
| `codequill verify-proof <proofFile>` | Re-derives the Merkle root from the leaf + proof chain, compares against the proof's `merkle_root`. Zero network calls. Nonzero exit on mismatch. |
| `codequill verify-attestation <digest>` | Verifies an attestation manifest by artifact digest. |

### Utility

| Command | What it does |
|---|---|
| `codequill wait <tx_hash>` | Polls `GET /v1/cli/tx/<hash>` (default 3s, respects server `next_poll_ms`). Flags: `--confirmations <n>` (default 1), `--timeout <ms>` (default 5 min). No auth required. |
| `codequill why [topic]` | Educational. Topics: `claim`, `snapshot`, `publish`, `prove`, `attest`, `preserve` (see `WhyTopic` in `src/commands/why.ts:4`). Flags: `--short`, `--ci`. |

### `preserve` vs `backup` — naming trap

The implementation file is `src/commands/backup.ts`, the handler is
`handleBackup`, the API endpoint is `POST /v1/cli/backup`, the multipart
field is `backup`, the response keys are `backup_id` / `backup_cid`, and
the metadata format is `codequill-backup:v1`. The **user-visible CLI
command is only `preserve`** (registered at `src/commands/backup.ts:400`).
There is no `codequill backup` alias, and the `why` topic is also spelled
`preserve` (see `WhyTopic` in `src/commands/why.ts:4`). If a user types
`codequill backup`, correct them to `codequill preserve`.

## The lifecycle (do this in order)

```
Web app (one-time):
  GitHub auth → connect wallet (SIWE) → register passkey → install GitHub App
       │
       ▼
codequill login                       ← device-code, 3-min timeout
       │
       ▼
codequill claim                       ← gasless, one-time, on-chain repo↔workspace binding
       │
       ▼
codequill snapshot                    ← LOCAL only; nothing uploaded yet
       │
       ▼
codequill publish [commit]            ← gzip manifest → IPFS + chain anchor
       ├──→ codequill prove <file> <snapshot_id>       ← passkey approval (device-code #2)
       └──→ codequill preserve [snapshot_id]           ← AES-256-GCM, falls back to latest published

Web app:
  Create release → propose → governance accepts → release.status = ACCEPTED
       │
       ▼
codequill attest <artifact> <release_id>   ← API enforces release ACCEPTED + all release
                                             snapshots anchored. CLI surfaces "Release not
                                             found or not ready" on failure
                                             (src/commands/attest.ts:115).
```

## Authentication & token storage

**Token storage** (`src/services/authStore.ts`):

- Path: `~/.config/codequill/tokens.json` on POSIX, `%APPDATA%\codequill\tokens.json`
  on Windows, or wherever `CODEQUILL_CONFIG_DIR` points.
- File mode: `0600`. Don't grant group/world read.
- Fields: `access_token`, `refresh_token`, plus epoch-second expiry
  variants. The CLI resolves expiry from any of `access_token_expires_in`,
  `access_expires_in`, `expires_in`, absolute `access_token_expires_at`,
  with a **30-second safety margin** subtracted (`authStore.ts:87-104`).
  Refresh-token expiry defaults to 30 days if absent.

**Refresh** is serialized across processes by an on-disk lock at
`tokens.json.lock`, 30-second TTL, with process-liveness check
(`process.kill(pid, 0)`). Concurrent CLI invocations from a CI matrix
will not stampede the refresh endpoint.

**CI / GitHub Actions:** Set `CODEQUILL_TOKEN` and the CLI short-circuits
the entire device-code + refresh dance (`apiClient.ts:87-89`). Pair with
`CODEQUILL_GITHUB_ID` (numeric GitHub repo ID) — when present, it's
forwarded as a header on every request (`apiClient.ts:90`).

**Every API request** carries an `X-Nonce: <crypto.randomUUID()>` header
for idempotency (`apiClient.ts:160`).

## Configuration files

Two optional JSON config files. **Repo-local wins** over global —
`src/launcher.ts:32-44`:

1. `<cwd>/.codequill/config.json` — repo-local override (per-project dev).
2. `<platform-config-dir>/config.json` — global (XDG on POSIX, `%APPDATA%`
   on Windows, or `CODEQUILL_CONFIG_DIR` wholesale).

Known fields (all optional; bridged to env by the launcher):

| Field | Effect |
|---|---|
| `codequill_api_base_url` | Sets `CODEQUILL_API_BASE_URL` if env not already set. |
| `codequill_base_url` | Sets `CODEQUILL_BASE_URL` if env not already set. |
| `node_tls_reject_unauthorized` (=`0`, `"0"`, or `false`) | Sets `NODE_TLS_REJECT_UNAUTHORIZED=0` in the spawned child (`launcher.ts:69-71`). **Dev only** — needed when pointing at a local API with a self-signed cert. Node's `fetch()` only honors this when it's set in the env at process boot, which is exactly why the launcher exists. |
| `node_no_deprecation` | Appends `--no-deprecation` to `NODE_OPTIONS` before spawn. |

Env-only:

| Env var | Effect |
|---|---|
| `CODEQUILL_TOKEN` | Bypass login + refresh; bearer header forced. |
| `CODEQUILL_API_BASE_URL` | API base URL. Default `https://api.codequill.xyz` (`apiClient.ts:80`). |
| `CODEQUILL_BASE_URL` | Web app base URL (used for certificate links in output). |
| `CODEQUILL_GITHUB_ID` | Forwarded as a request header (`apiClient.ts:90`). |
| `CODEQUILL_CONFIG_DIR` | Override token + config directory. |

## Snapshot internals — two roots, two purposes

The snapshot manifest is `codequill-snapshot:v1`. Built locally at
`src/services/manifests/snapshotManifest.ts`. Two distinct Merkle roots,
both `keccak256`-based with the OpenZeppelin
duplicate-last-on-odd convention.

**`merkle_root`** — path-salted, this is what goes on-chain.

```
leaf = keccak256( 0x00 || path_hash || file_hash )
path_hash = HMAC-SHA256( salt, utf8(normalized_path) )      // 32 bytes hex
file_hash = keccak256( file_bytes )                          // 32 bytes hex
```

The salt is 32 random bytes per snapshot unless `--salt <hex>` is supplied
(`merkle.ts:10-18` for normalization rules). Anyone with the salt can
reconstruct the directory layout from the manifest's `path_hash` list. The
salt is **encrypted to the workspace's X25519 public key** and embedded in
the manifest as `path_map_enc` (an inline `codequill-envelope:v1`), so the
plaintext salt never touches the network unless the user exports it via
`--print-salt`.

**`content_root`** — salt-free, public.

```
leaf = keccak256( 0x00 || file_hash )       // no path_hash, no salt
```

Anyone holding the same set of file hashes (in any order) can rebuild
`content_root` and compare. This is what `preserve` uses to verify a
working tree matches the published snapshot **without** asking for the
salt or path-map decryption (`backup.ts:168-202`).

**Submodules.** Gitlink entries (mode `160000`, type `commit`) have no
blob to read, so the CLI synthesizes a deterministic, version-tagged
buffer:

```
codequill-gitlink:v1:<40-or-64-hex-oid>
```

— per `git.ts:179-181`. The `codequill-gitlink:v1:` prefix is reserved
and cannot collide with any real file (it's a label, not blob bytes).
The hash changes when the submodule pin moves; snapshots intentionally
do not recurse into submodules.

**Reading file bytes for hashing and proofs.** Both `snapshot` and
`prove` read via `git cat-file -p <oid>` (`git.ts:188`), never from
disk. The on-disk fallback was removed deliberately: on Windows with
`git.autocrlf` enabled, the working-tree bytes differ from the blob
bytes, which produces wrong proofs. Either run inside a git repo or
fail — `src/commands/prove.ts:170-180`.

**Local layout:**

```
.codequill/
  config.json             # repo-local config override (optional)
  snapshots/
    .index.json           # cache of all local snapshots, enriched with
                          # publish metadata. Auto-rebuilds from disk if
                          # corrupted (warns since commit 91f517f).
    snapshot-<short>.json # one per commit
  proofs/
    proof-<short>.json    # one per `codequill prove` invocation (unless --out)
```

## `preserve` — the encrypted-backup flow

Source: `src/commands/backup.ts`. Steps, with their pitfalls:

1. **Resolve `snapshotId`.** Missing? Walks the local index for the most
   recent item with `published?.snapshot_id` (`backup.ts:67-94`). Mirrors
   how `publish` defaults to HEAD. Footgun: if you've never published from
   this checkout, this fails — the fallback only sees snapshots in the
   *local* index.
2. **Fetch snapshot meta + manifest** (`/v1/cli/snapshots/<id>/bundle`).
3. **Verify the snapshot has `content_root`** — `backup.ts:132-143`. If the
   snapshot was published before the `content_root` rollout and the
   backend can't surface one, `preserve` refuses. The error tip is
   `update backend + publish pipeline to store/return content_root`.
4. **Fetch the workspace's X25519 public key** —
   `GET /v1/cli/encryption?repo_name=<derived>`. Response shape:
   `{scheme: 'x25519-sealedbox', public_key_b64, key_id?}`.
5. **Verify local content matches** by rebuilding `content_root` from
   `git ls-tree` at the snapshot commit and comparing lowercase hex
   (`backup.ts:168-202`). No salt needed.
6. **`git archive --format=tar <commit> | gzip -9`** → temp `.tar.gz` in
   `os.tmpdir()` (`backup.ts:418-438`).
7. **Encrypt as `codequill-envelope:v1`** (`src/services/envelope.ts`):

   ```
   envelope.scheme  = 'codequill-envelope:v1'
   envelope.cipher  = 'aes-256-gcm'
   envelope.iv_b64  = 12 random bytes
   envelope.tag_b64 = GCM tag
   envelope.dek_wrap        = 'x25519-sealedbox'
   envelope.wrapped_dek_b64 = sodium.crypto_box_seal(DEK, workspace_pubkey)
   envelope.recipient       = { workspace_id, key_id? }
   ```

   The DEK is a fresh 32 random bytes per preservation; only the wrapped
   form ever leaves the local process.
8. **`POST /v1/cli/backup`** as multipart with the encrypted bytes,
   `archive_sha256`, and a `codequill-backup:v1` metadata JSON.
9. **Wait for tx confirmation** (default 1, configurable).

**Loss model:** the wrapped DEK is unsealed only by the X25519 private
key, which is derived from the user's passkey via WebAuthn PRF and never
leaves the device. Losing the passkey = permanent loss. CodeQuill cannot
recover preserved source. Say this loudly when users plan retention.

## `prove` — Merkle inclusion proof with a *second* device-code flow

Source: `src/commands/prove.ts`. Why this is non-obvious: building a
proof requires the salted `path_hash` for the target file. The plaintext
path-map is in the manifest's `path_map_enc` envelope, which only the
passkey can unseal. The CLI does not have the passkey, so it asks the
backend to release just one `path_hash` after the user approves via
passkey in the browser:

```
POST /v1/cli/decrypt/init   { snapshot_id, purpose: "prove", path_norm }
        →   { session_id, approval_url, approval_phrase }
poll /v1/cli/decrypt/result { session_id }   until approved or 3-min timeout
        →   { path_hash: <64 hex chars> }
```

Same UX shape as `login` — a URL and a phrase to confirm in the
browser — but a separate code path. Both flows time out at 3 minutes.

`--disclose` includes the plaintext path in the proof JSON. By default
proofs ship only the salted `path_hash` + the proof chain, so an auditor
verifying the proof learns only that *some* path with the bound salt
hashed to the leaf — not what the path is. Turning this on trades
privacy for human-readability.

`verify-proof` is fully offline (`verifyProof.ts`) — it reads the JSON,
recomputes the leaf hash, folds the sibling chain, and compares against
the embedded `merkle_root`. Nothing else. Use it in pipelines without
worrying about creds.

## `attest` — the release-ACCEPTED constraint

Source: `src/commands/attest.ts`. The flow:

1. Resolve build artifact. Directories are auto-tarred to a **deterministic**
   `tar.gz` via `createDeterministicTarGz` (`attest.ts:80-91`).
2. Compute sha256 of the artifact.
3. `GET /v1/cli/releases/<id>/bundle`. Failure surfaces as
   `"Release not found or not ready"` (`attest.ts:115`) — there is no
   specific CLI-side enum check; the **API rejects** unless the release
   is in `ACCEPTED` state and every snapshot in the release is anchored
   on-chain.
4. Optionally resolve `--upstream <purl>` flags via
   `POST /v1/cli/upstreams/resolve`. **Manual only** — no lockfile
   introspection. PURLs that fail to resolve are silently dropped from
   the final attestation (no error).
5. Build `codequill-attestation:v1` manifest, gzip, `POST /v1/cli/attest`
   as multipart, wait for confirmation.

**Subject PURL convention:** the CLI builds
`{repoName.replace('/', ':')}%2F{encodeURIComponent(name)}@{encodeURIComponent(version)}`.
If `--subject-name` / `--subject-version` are absent in TTY mode, the CLI
prompts; in `--json` mode it falls back to artifact basename + release
name / commit hash / sha256.

## CI/CD — the two GitHub Actions

Both actions live under `codequill-claim/`, run on Node 20, and read
`secrets.CODEQUILL_TOKEN`.

### `codequill-claim/actions-snapshot@v1` — snapshot + publish on push

```yaml
on:
  push:
    branches: [main]

jobs:
  snapshot:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: codequill-claim/actions-snapshot@v1
        with:
          token: ${{ secrets.CODEQUILL_TOKEN }}
          github_id: ${{ github.repository_id }}
          preserve: "true"      # optional; runs `codequill preserve` after publish
```

Optional inputs: `cli_version` (pin), `working_directory`, `api_base_url`,
`extra_args`. The action runs `login → snapshot → publish [→ preserve]`,
all with `--no-confirm --json --no-wait` and a follow-up `wait`.

### `codequill-claim/actions-attest@v1` — two-phase release pipeline

Driven by `codequill-authorship[bot]` issue events with the label
`codequill:release`. Two phases, exposed via the `event_type` output:

- `release_anchored` — release submitted on-chain. Your workflow should
  build + deploy to staging.
- `release_approved` — governance accepted. Your workflow should call the
  action again with `build_path: ./dist` (or wherever) and the action
  runs `codequill attest`.

Optional inputs include `hmac_secret` for issue authentication.

```yaml
on:
  issues:
    types: [labeled]

jobs:
  release-pipeline:
    if: github.event.issue.user.login == 'codequill-authorship[bot]'
        && github.event.label.name == 'codequill:release'
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - id: cq
        uses: codequill-claim/actions-attest@v1
        env:
          GITHUB_TOKEN: ${{ github.token }}
        with:
          token: ${{ secrets.CODEQUILL_TOKEN }}
          hmac_secret: ${{ secrets.CODEQUILL_HMAC_SECRET }}
          github_id: ${{ github.repository_id }}
          build_path: ./dist
      - if: steps.cq.outputs.event_type == 'release_anchored'
        run: npm run deploy:staging
      - if: steps.cq.outputs.event_type == 'release_approved'
        run: npm run deploy:production
```

## README badges

Three badge endpoints under `app.codequill.xyz/badges/`, all keyed by
the **workspace's UUID for the repo** (not the GitHub numeric repo ID):

- `/badges/claim/<uuid>`     — green tick if claimed
- `/badges/snapshot/<uuid>`  — latest snapshot short hash
- `/badges/trust/<uuid>`     — Trust Index 0-100

Pattern lifted from `~/www/codequill-cli/README.md:4-6`. The UUID is
visible in the workspace's explore URL on `app.codequill.xyz`.

## Trust Index — what the score actually measures

Six on-chain signals, weighted, recalculated daily, deterministic only
(no manual overrides):

| Signal | Weight |
|---|---|
| Snapshot activity (log-scaled) | 25% |
| Continuity (active months, gap penalty) | 20% |
| Release governance maturity | 20% |
| Attestations (internal + external; external weighted higher) | 15% |
| Preservation coverage | 10% |
| Dependency graph (upstreams/downstreams via attestations) | 10% |

Self-attestation caps a repo at ~70/100. The remaining headroom requires
*external* attestors — that's the design lever for not letting a single
workspace score itself to 100.

## Empirical gotchas

- **Releases are web-app-only.** Single most common wrong assumption when
  a user new to CodeQuill asks "how do I `codequill release`?" — they
  don't; they open `app.codequill.xyz`. The CLI does claims, snapshots,
  attestations, preservations, proofs, never releases.
- **`backup` is not a CLI command and not a `why` topic.** It's the
  implementation filename, the API endpoint, the multipart field, and
  the manifest version (`codequill-backup:v1`). The CLI command, the
  `why` topic, and anything user-facing is `preserve`.
- **`attest` against a non-accepted release fails server-side** with
  `"Release not found or not ready"`. The CLI does not pre-check the
  state; let the API be the source of truth.
- **`preserve` requires `content_root` in the snapshot.** Old snapshots
  from before that field shipped will refuse to preserve until the
  backend backfills (`backup.ts:138-142`).
- **`docs.codequill.xyz/<section>/` 404s.** The docs site is built with
  Leaf (the in-house static-site generator), and Leaf only emits
  leaf-page routes — not section indexes. Fetch
  `/concepts/snapshots/`, not `/concepts/`. To get a section index,
  you'd need `content/concepts/index.md` per section.
- **`snapshot` re-run without `--salt` gives a new `merkle_root`.** No
  rollback; if you intend to re-publish the same commit and want the
  same root, pass the original salt via `--salt`.
- **Working tree state is ignored.** `snapshot` and `publish` read from
  `git ls-tree` at HEAD (or `--commit`), so uncommitted edits never
  affect the manifest. This is intentional but surprises users who
  expected `snapshot` to act like `tar` over the working directory.
- **Repo-local `.codequill/config.json` wins over global.** When the CLI
  seems to be talking to the wrong API, check the local file before
  the global one (`launcher.ts:32-44`).
- **`NODE_TLS_REJECT_UNAUTHORIZED=0` must be set before Node boots.**
  The launcher exists precisely because Node's `fetch()` only honors
  this env var at process start — setting it in `~/.bashrc` works,
  setting it via the launcher config works, setting it from inside the
  CLI code does not.
- **Two device-code flows, both 3-minute timeout.** `login` and `prove`
  both pop a URL + approval phrase. The `prove` one is
  `/v1/cli/decrypt/init` + `/result`, not the auth endpoints.
- **On-disk file fallback in `prove` was removed deliberately.** The
  error message in `prove.ts:174-179` explains why: Windows
  `core.autocrlf` would silently produce wrong proofs. If a user is not
  in a git repo, `prove` will refuse.
- **`test/fog.mp4` exists on purpose.** It's a binary fixture used by
  determinism tests in `test/services/manifests/`. Don't delete it.

## Key rules

- **`login` → `claim` → `snapshot` → `publish` → [`prove` | `preserve` | `attest`].**
  Skip a step, the next one fails.
- **Releases happen in the web app.** Never tell a user to "create a
  release with the CLI" — direct them to `app.codequill.xyz`.
- **Attest only against ACCEPTED releases.** Pending / anchored / rejected
  releases will be rejected by the API.
- **Pass `--no-confirm --json --no-wait` in CI**, then `codequill wait`
  separately. `--no-wait` returns the tx_hash so a follow-up step can
  block on confirmation independently — which is exactly what the
  `actions-snapshot` action does.
- **`verify-proof` and `verify-attestation` are offline and unauthenticated.**
  Use them in any pipeline; no secrets needed.
- **Treat the path-salt as sensitive.** Sharing it lets anyone with the
  manifest reconstruct your directory tree. The CLI keeps the salt
  encrypted in the manifest's `path_map_enc`; do not export it with
  `--print-salt` and then publish that output.
- **Tell users they lose preservations forever if they lose the passkey.**
  No exceptions; CodeQuill cannot decrypt for them.
- **CodeQuill does not prove build causality.** Repeat the disclaimer when
  designing supply-chain narratives around its output.

## References

- Live docs: `https://docs.codequill.xyz` (hit specific pages like
  `/concepts/snapshots/` or `/cli-reference/source-commands/`; section
  roots return 404).
- Web app: `https://app.codequill.xyz`
- Main site: `https://codequill.xyz`
- npm package: `https://www.npmjs.com/package/codequill`
- CLI source (canonical for empirical patterns above): `~/www/codequill-cli/`
- GitHub Actions:
  - `https://github.com/codequill-claim/actions-snapshot`
  - `https://github.com/codequill-claim/actions-attest`
- In-repo example: `examples/codequill/` — minimal end-to-end script
  driving the CLI from a Node process the way a CI job would.
