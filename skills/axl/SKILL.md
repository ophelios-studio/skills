---
name: axl
description: Use when building apps on top of Gensyn AXL (github.com/gensyn-ai/axl), the Go P2P node that exposes a local HTTP API on 127.0.0.1:9002 for Yggdrasil-mesh messaging. Trigger on references to the AXL HTTP API (/topology, /send, /recv, /mcp/, /a2a/), node-config.json, X-Destination-Peer-Id / X-From-Peer-Id headers, the AXL binary, or containerfiles/Dockerfile from the AXL repo. Covers the three core endpoints, the X-From-Peer-Id pubkey-prefix gotcha, MCP/A2A envelope hijacking, the 0.0.0.0 bind requirement, polling /recv, no-native-pubsub, config templates, and Docker compose patterns.
---

# Gensyn AXL — raw HTTP API

## What AXL is

A single Go binary (`node`) that runs as a local daemon. It:

- Joins a Yggdrasil peer-to-peer mesh over TLS/TCP.
- Derives an ed25519 identity (public key = peer address, hex-encoded, 64 chars).
- Exposes a **local HTTP API on `127.0.0.1:9002`** for your app.
- Runs in userspace (gVisor TCP stack). No TUN device, no root.

```
    your app  ──HTTP──►  axl node  ──TLS/TCP──►  other axl nodes
              (:9002)    (localhost)               (Yggdrasil mesh)
```

Your app never opens sockets to remote peers. The node does encryption,
routing, peering. Payloads are opaque bytes (with two exceptions — see
MCP/A2A hijacking below).

## Three core endpoints — the only ones most apps need

### `GET /topology` — identity + peer state
Returns `{ our_ipv6, our_public_key, peers[], tree[] }`. **`our_public_key`
is your node's full identity.** Share it out-of-band with anyone who needs
to `/send` to you.

```bash
curl -s http://127.0.0.1:9002/topology | jq .our_public_key
```

### `POST /send` — fire-and-forget to one peer
Header `X-Destination-Peer-Id: <64-char-hex-pubkey>`, body = raw bytes.
Response: `200 OK` + `X-Sent-Bytes` header, empty body.

- No response from the remote peer is read back.
- **Fails with a dial error if the peer is offline.** No store-and-forward.
- Takes **exactly one** destination. There is no native broadcast.

### `GET /recv` — poll the inbound queue
`204 No Content` if empty. `200 OK` with raw body and `X-From-Peer-Id`
header otherwise. Each call dequeues one message.

- Poll-only. No SSE / WebSocket / long-poll.
- **Single shared FIFO queue per node** — slow consumer on one type delays
  unrelated traffic.
- If nobody polls, messages accumulate in memory.

## Gotchas — every one of these will bite you

### 1. `X-From-Peer-Id` is NOT the sender's public key (critical)

The header on `/recv` responses is derived from the sender's Yggdrasil
IPv6 address. The IPv6 only encodes a **prefix** of the sender's public
key hash; `yggAddr.GetKey()` reconstructs that prefix and pads the
remainder with `0xff`.

Example — sender pubkey vs. received header:
```
sender pubkey:   eeb76d51d746d3aa7a20fcfbf2b507bb37c461ca8e9c2519058fc6f993204764
X-From-Peer-Id:  eeb76d51d746d3aa7a20fcfbf2b57fffffffffffffffffffffffffffffffffff
```

First ~14 bytes match exactly; byte 14 has mixed bits; remainder is `ff`
padding.

**Implications:**
- You **cannot** round-trip — the ID from `/recv` will not work as
  `X-Destination-Peer-Id` on `/send`.
- Every peer must advertise its own full `our_public_key` (from its own
  `/topology`) out-of-band to anyone who needs to send to it.
- To identify an inbound sender among a known set, prefix-match.

**Matcher (use verbatim):**
```ts
// /recv's X-From-Peer-Id is a prefix of the sender's pubkey, padded with
// 0xff. Strip trailing ff bytes, drop the last (mixed-bit) byte, then
// prefix-match against the full pubkey from /topology.
export function peerIdMatches(fromHeader: string, fullPubkeyHex: string): boolean {
  if (!fromHeader || !fullPubkeyHex) return false;
  let trimmed = fromHeader.toLowerCase();
  while (trimmed.length >= 2 && trimmed.slice(-2) === "ff") {
    trimmed = trimmed.slice(0, -2);
  }
  if (trimmed.length < 4) return false;
  const prefix = trimmed.slice(0, -2); // drop mixed-bit last byte
  return fullPubkeyHex.toLowerCase().startsWith(prefix);
}
```

`/mcp/` and `/a2a/` pass the full pubkey through their envelope; this
gotcha is specific to raw `/send` + `/recv`.

### 2. API binds to 127.0.0.1 by default

Inside a container that's unreachable from the host even with port
mappings. **Always set `"bridge_addr": "0.0.0.0"` in containerized
configs.**

### 3. MCP/A2A envelope hijacking

The node multiplexer inspects inbound JSON and routes on these envelope
keys **before** anything reaches the `/recv` queue:

| Envelope shape                              | Routed to        |
|---------------------------------------------|------------------|
| `{"service": "...", "request": {...}}`      | MCP router       |
| `{"a2a": true, "request": {...}}`           | A2A server       |
| anything else                               | `/recv` queue    |

If your app payload happens to contain a top-level `"service"` key or
`"a2a": true`, it will be silently swallowed and never reach `/recv`.

**Always wrap application payloads in a custom envelope:**

```json
{ "app": "<your-app-id>", "v": 1, "kind": "<msg-type>", "payload": { ... } }
```

Alternatives: MessagePack / Protobuf / raw bytes — the multiplexer only
matches JSON with the specific keys above.

### 4. No native pub/sub

`/send` takes exactly one destination. To reach N subscribers, the
publisher loops. No topic system, no subscription registry at the node.

If you need pub/sub: build it in application code over `/send` + `/recv`.
A well-known "registry" peer that tracks `topic → [subscriber_pubkey, ...]`
works for simple cases. Subscribers register by sending to the registry;
publishers fetch the list then fan out. The eventual-consistency edge
cases (sub announcement timing, dedup, peer table TTL) are easy to get
wrong — design for them up front.

### 5. `/recv` is poll-only, single queue

- Loop `GET /recv`. No push, no SSE.
- Single shared FIFO per node — one slow consumer blocks all traffic.
- Multiplex in your app: put a type discriminator inside your envelope
  and dispatch to handlers based on it. Or register a custom `Stream`
  like MCP/A2A do (see `internal/tcp/listen/stream.go` upstream).

Typical latency: ~10 ms best, ~60 ms p50 on a local Docker bridge. The
distribution is bimodal — dominated by the poll interval, not the mesh
transport.

### 6. No peer discovery / bootstrap / DHT

Every node must be given at least one peer URI in
`Peers: ["tls://host:port"]`. Someone has to be a **public node** that
listens (`"Listen": ["tls://0.0.0.0:9001"]`) on a reachable address.

For bootstrap: run your own on a cloud host, or use Gensyn-operated
peers (`tls://34.46.48.224:9001`, `tls://136.111.135.206:9001` per the
upstream default `node-config.json`).

### 7. Outbound port 5678 must be reachable

Storage upload goes to plain HTTP `:5678` (no TLS). Restrictive networks
(corporate WiFi, hotel WiFi) often block non-standard outbound ports.
Symptom: SDK hangs ~30 s then `AxiosError: timeout`. Workaround: switch
network or proxy through a permissive host.

### 8. Ephemeral identity without `PrivateKeyPath`

If `PrivateKeyPath` is omitted, the node generates a fresh ed25519
keypair on every startup. **Public key changes each restart.** For
anything stateful, always pre-generate a persistent key.

```bash
# macOS note: LibreSSL (/usr/bin/openssl) does NOT support ed25519.
# Use Homebrew's:
/opt/homebrew/opt/openssl@3/bin/openssl genpkey -algorithm ed25519 -out key.pem
chmod 600 key.pem
```

### 9. No prebuilt binaries / images

Zero releases on GitHub. Build from source with Go 1.25.5+
(`make build` — Makefile pins `GOTOOLCHAIN`, so any recent Go works), or
use `containerfiles/Dockerfile`. Build takes ~10 s warm.

### 10. Message size cap: 16 MB

`max_message_size` defaults to 16,777,216 bytes. Configurable, but app
chunking is cleaner for large payloads. Wire format: 4-byte big-endian
`uint32` length prefix + payload.

## Config templates

### Hub (public) node
```json
{
  "PrivateKeyPath": "/keys/hub.pem",
  "Listen": ["tls://0.0.0.0:9001"],
  "Peers": [],
  "bridge_addr": "0.0.0.0",
  "api_port": 9002
}
```

### Spoke node
```json
{
  "PrivateKeyPath": "/keys/spoke.pem",
  "Listen": [],
  "Peers": ["tls://hub-hostname:9001"],
  "bridge_addr": "0.0.0.0",
  "api_port": 9002
}
```

### Full config reference

| Field                    | Default       | Notes                                         |
|--------------------------|---------------|-----------------------------------------------|
| `PrivateKeyPath`         | _(none)_      | Persistent identity. Omit → ephemeral key.    |
| `Peers`                  | `[]`          | Yggdrasil peer URIs to dial.                  |
| `Listen`                 | `[]`          | Addresses to accept inbound peers.            |
| `api_port`               | `9002`        | HTTP API port.                                |
| `bridge_addr`            | `127.0.0.1`   | **Set `0.0.0.0` in Docker.**                  |
| `tcp_port`               | `7000`        | Internal gVisor TCP listener.                 |
| `router_addr`            | _(empty)_     | MCP router host. Empty = MCP disabled.        |
| `a2a_addr`               | _(empty)_     | A2A server host. Empty = A2A disabled.        |
| `max_message_size`       | `16777216`    | 16 MB per TCP message.                        |
| `max_concurrent_conns`   | `128`         | Inbound TCP cap.                              |
| `conn_read_timeout_secs` | `60`          |                                               |
| `conn_idle_timeout_secs` | `300`         |                                               |

## Docker Compose — multi-node template

One public "hub" + N "spoke" nodes. Containers talk over the compose
network via the hub's `9001` TLS listener — no host exposure needed for
peer traffic.

See `examples/axl/docker-compose.yml` and the per-node configs.

Quick sanity check after `docker compose up -d`:

```bash
for p in 9002 9012 9022; do
  echo "=== :$p ==="
  curl -s http://localhost:$p/topology | jq '{our_public_key, peers: [.peers[].public_key]}'
done
```

## Node.js client patterns

All patterns assume Node 18+ (native `fetch`).

### Safe envelope

```ts
export interface AxlEnvelope<T = unknown> {
  app: string;   // your app identifier — avoids collision with MCP/A2A
  v: number;     // schema version
  kind: string;  // message type discriminator for app-side dispatch
  payload: T;
}

const encode = (env: AxlEnvelope): Uint8Array =>
  new TextEncoder().encode(JSON.stringify(env));

const decode = <T>(bytes: Uint8Array): AxlEnvelope<T> =>
  JSON.parse(new TextDecoder().decode(bytes));
```

### Minimal client

```ts
export class AxlClient {
  constructor(private baseUrl: string) {}

  async topology() {
    const r = await fetch(`${this.baseUrl}/topology`);
    if (!r.ok) throw new Error(`topology ${r.status}`);
    return r.json() as Promise<{
      our_ipv6: string;
      our_public_key: string;
      peers: Array<{ public_key: string; up: boolean; inbound: boolean; uri: string }>;
      tree: Array<{ public_key: string; parent: string; sequence: number }>;
    }>;
  }

  async send(destPubkey: string, body: Uint8Array | string): Promise<number> {
    const r = await fetch(`${this.baseUrl}/send`, {
      method: "POST",
      headers: {
        "X-Destination-Peer-Id": destPubkey,
        "Content-Type": "application/octet-stream",
      },
      body,
    });
    if (!r.ok) throw new Error(`send ${r.status} ${await r.text()}`);
    return Number(r.headers.get("x-sent-bytes") ?? 0);
  }

  async recv(): Promise<{ from: string; body: Uint8Array } | null> {
    const r = await fetch(`${this.baseUrl}/recv`);
    if (r.status === 204) return null;
    if (!r.ok) throw new Error(`recv ${r.status}`);
    return {
      from: r.headers.get("x-from-peer-id") ?? "",
      body: new Uint8Array(await r.arrayBuffer()),
    };
  }
}
```

### Recv loop with dispatch by `kind`

```ts
type Handler = (from: string, payload: unknown) => void | Promise<void>;

export async function runRecvLoop(
  client: AxlClient,
  handlers: Record<string, Handler>,
  opts: { intervalMs?: number; signal?: AbortSignal } = {},
): Promise<void> {
  const interval = opts.intervalMs ?? 50;
  while (!opts.signal?.aborted) {
    try {
      const msg = await client.recv();
      if (!msg) {
        await new Promise((res) => setTimeout(res, interval));
        continue;
      }
      let env: AxlEnvelope;
      try { env = decode(msg.body); } catch { continue; }
      const h = handlers[env.kind];
      if (h) await h(msg.from, env.payload);
    } catch (err) {
      console.error("recv loop error", err);
      await new Promise((res) => setTimeout(res, 500));
    }
  }
}
```

### Identifying the sender

```ts
function senderNameFor(
  fromHeader: string,
  directory: Record<string, string>,  // name -> full pubkey
): string | null {
  for (const [name, pk] of Object.entries(directory)) {
    if (peerIdMatches(fromHeader, pk)) return name;
  }
  return null;
}
```

## Common pitfalls checklist

Before shipping AXL code:

- [ ] Every containerized config sets `"bridge_addr": "0.0.0.0"`.
- [ ] All payloads are wrapped in a custom envelope (no top-level `"service"` or `"a2a": true`).
- [ ] Sender identity is resolved via `peerIdMatches()` against a known directory of pubkeys, never used directly as a destination.
- [ ] Destination pubkeys are the full 64-char hex from `/topology.our_public_key`, obtained out-of-band.
- [ ] Every node has `PrivateKeyPath` set if identity needs to survive restart.
- [ ] At least one node has `"Listen": ["tls://0.0.0.0:9001"]` and the others' `Peers` point to it.
- [ ] A `/recv` poll loop is running somewhere (messages queue indefinitely otherwise).
- [ ] Messages stay under 16 MB, or `max_message_size` is raised.
- [ ] Keys generated with real OpenSSL (`openssl@3` on macOS, not LibreSSL).

## References

- Upstream repo: https://github.com/gensyn-ai/axl
- In-repo: `examples/axl/{docker-compose.yml, alice.json, bob.json, charlie.json, send-recv.js}`.
