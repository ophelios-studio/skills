# axl examples

Minimal 3-node AXL mesh + a Node.js script that exercises the raw HTTP
API. Mirrors the canonical patterns in the parent `SKILL.md`.

## Setup

```bash
# 1. AXL doesn't ship prebuilt binaries. Either:
#    - clone github.com/gensyn-ai/axl, run `make build`, build the Docker image
#      from containerfiles/Dockerfile and tag it `gensyn-axl:local`
#    - OR adjust docker-compose.yml's `image:` field to point at your image

# 2. Generate ed25519 keys for each node (use brew openssl on macOS — LibreSSL
#    doesn't support ed25519)
mkdir -p keys
for name in alice bob charlie; do
  /opt/homebrew/opt/openssl@3/bin/openssl genpkey -algorithm ed25519 -out keys/${name}.pem
  chmod 600 keys/${name}.pem
done

# 3. Start the mesh
docker compose up -d

# 4. Verify all three nodes see each other
for p in 9002 9012 9022; do
  echo "=== :$p ==="
  curl -s http://localhost:$p/topology | jq '{our_public_key, peers: [.peers[].public_key]}'
done

# 5. Run the round-trip
node send-recv.js

# 6. Tear down
docker compose down
```

## What this demonstrates

- 3 nodes peering via Yggdrasil over a Docker bridge network.
- `bridge_addr: "0.0.0.0"` in each config — required for host access.
- A custom envelope `{ app, v, kind, payload }` that avoids the MCP/A2A
  hijack on top-level `service` / `a2a` keys.
- `peerIdMatches()` to resolve the truncated `X-From-Peer-Id` against
  the full pubkey directory.
- Polling `/recv` with a short interval (50 ms) — typical 10–60 ms p50.
