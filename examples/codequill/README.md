# CodeQuill example

A minimal end-to-end driver for the `codequill` CLI: snapshot → publish →
wait for confirmation → (optionally) preserve. Same shell-out pattern a
CI job would use.

## Not an SDK

There is no `@codequill/sdk` npm package. The right way to script CodeQuill
from Node is to invoke the CLI with `--json --no-confirm --no-wait` and
parse the output. That is exactly what the
`codequill-claim/actions-snapshot@v1` GitHub Action does internally; this
script mirrors that flow.

## What this demonstrates

- **Shell out, parse JSON.** Every command supports `--json` for
  machine-readable output. The script reads stdout and `JSON.parse`s it.
- **The `--no-confirm --no-wait` CI pattern.** `publish` returns the
  `tx_hash` immediately; `codequill wait <tx>` blocks separately. This
  splits "submit" from "confirm" so different pipeline steps can own
  each.
- **The `preserve` fallback.** Calling `codequill preserve` without a
  `snapshot_id` falls back to the latest published snapshot in the local
  index (commit `c9ef22a` in the CLI). The script passes the explicit
  ID anyway, which is the safer pattern for non-interactive use.
- **Where the response fields come from.** Each step's parsed fields are
  cited inline by source file so a reader can trace the JSON shape when
  the CLI version drifts.

## Prerequisites

- Node >= 18.
- `npm i -g codequill` (or run from a local checkout with `npm link`).
- A CodeQuill workspace with a registered passkey and a connected wallet.
- A git repo whose `origin` remote points at a GitHub repository that has
  been claimed via `codequill claim`. The script will refuse if the repo
  is not claimed.

## Run

```bash
codequill login                  # one-time, in this shell
cd <a-claimed-repo>              # `codequill claim` must have run
node demo.mjs                    # snapshot + publish + wait
node demo.mjs --preserve         # also create an encrypted preservation
```

The `--preserve` step triggers a passkey-approval prompt in the browser
for the **second** device-code flow (`/v1/cli/decrypt/init` →
`/result`), so it's gated behind a flag — you don't want to be confirming
in the browser every time you run a demo.

## What it doesn't cover

- `codequill claim` — run once per repo; not in the loop.
- `codequill prove <file> <snapshot_id>` — also triggers the passkey
  flow. Easy to add: a single `runJson('prove', ['prove', file, sid,
  '--out', './proof.json'])` after the wait step.
- `codequill attest <build> <release_id>` — requires a release in
  **ACCEPTED** state, which is created and approved entirely in the web
  app at `app.codequill.xyz`. Without an accepted release the API
  returns "Release not found or not ready" and the CLI exits non-zero.

For the full empirical reference, read `skills/codequill/SKILL.md` in
this repo. For the canonical source, read `~/www/codequill-cli/`.

## Reference

- Skill: `skills/codequill/SKILL.md`
- Live docs: `https://docs.codequill.xyz` (hit specific pages like
  `/concepts/snapshots/`; section roots return 404 — the docs use the
  Leaf static-site generator which doesn't emit section indexes)
- CLI source: `~/www/codequill-cli/`
- GitHub Action: `codequill-claim/actions-snapshot@v1`
