---
name: axl-pubsub
description: Use when integrating the `axl-pubsub` npm package (github.com/ophelios-studio/axl-pubsub) — a topic-based gossip pub/sub library on top of Gensyn AXL. Trigger on `import { Gossip } from "axl-pubsub"`, references to `axp:1` envelopes, `pub` / `sub_ad` message kinds, `peer-joined` / `peer-left` / `peer-topics-changed` events, `PublishResult { sentTo, failed }`, topic patterns like `news.*`, or any axl-pubsub configuration option (`pollIntervalMs`, `advertiseIntervalMs`, `subscriptionTtlMs`, `dedupWindowMs`). Covers the library API surface, eventual-consistency edge cases, the X-From-Peer-Id sidecar split, reserved-key envelope guards, canonical signing format, PEM key persistence requirements, no-backpressure pattern, and a decision matrix for raw AXL vs this library.
---

# axl-pubsub — gossip pub/sub on top of Gensyn AXL

This skill covers the **`axl-pubsub` library** specifically. It assumes
you've already decided to use this library; if you haven't, see
`When to use this library vs raw AXL` below.

For raw AXL HTTP API patterns (the protocol primitives this library is
built on), install the `axl` skill — it covers `/topology`, `/send`,
`/recv`, `X-From-Peer-Id`, MCP/A2A hijacking, and other AXL-level
gotchas.

## What it is

A small TypeScript library (~900 LOC, ~90% test coverage) that adds
topic-based pub/sub to AXL. It runs an **event loop on top of `/recv`**
that:

- **Polls** AXL's `/recv` queue at 25 ms (configurable) and decodes each
  envelope.
- **Verifies** ed25519 signatures on every received envelope before
  dispatch.
- **Discovers** peers automatically by exchanging `sub_ad` (subscription
  advertisement) messages on a 30-second interval.
- **Fans out** publishes by enumerating the local subscription table and
  calling `/send` per subscriber.
- **Dedupes** `(from, message_id)` pairs within a 60-second window.
- **Surfaces failures** via per-peer error reporting in `PublishResult`
  and via an `error` EventEmitter event (errors are never thrown).

```
your code  ──Gossip class──►  AXL HTTP API  ──Yggdrasil mesh──►  other axl-pubsub clients
            (subscribe,        (/topology,                          (decode, verify,
             publish, events)   /send, /recv)                        dispatch to handlers)
```

## Package basics

| Field | Value |
|---|---|
| npm | `axl-pubsub` |
| Version | 0.1.1 |
| License | Apache-2.0 |
| Repo | github.com/ophelios-studio/axl-pubsub |
| Module formats | Dual ESM + CJS (tsup) |
| Node | `>=18` |
| Deps | `@noble/ed25519`, `ulid` (no native, no peer deps) |
| AXL pinned commit | `9cba555` (see README) |

## Minimum viable example

```ts
import { Gossip } from "axl-pubsub";

// Create
const ps = new Gossip({
  axlUrl: "http://localhost:9002",
  privateKeyPath: "./alice.pem",      // OR `keyPair: { ... }` for in-memory
});

// Lifecycle
await ps.start();   // begins polling + advertising
await ps.stop();    // drains in-flight polls

// Subscribe (wildcard patterns supported)
const sub = await ps.subscribe("news.*", (msg) => {
  // msg = { topic, from (full ed25519 pubkey hex), id (ULID), ts, payload (Uint8Array) }
  console.log(msg.topic, new TextDecoder().decode(msg.payload));
});
await sub.unsubscribe();

// Publish (concrete topics only — no wildcards)
const result = await ps.publish("news.test", new Uint8Array([1, 2, 3]));
// result.sentTo: pubkey[]; result.failed: { pubkey, error }[]

// Events
ps.on("peer-joined", ({ pubkey, topics }) => { /* ... */ });
ps.on("peer-left",   ({ pubkey }) => { /* ... */ });
ps.on("peer-topics-changed", ({ pubkey, topics }) => { /* ... */ });
ps.on("error",       (err) => { /* errors are emitted, not thrown */ });

// Inspection
ps.knownPeers();             // PeerEntry[] currently in the local table
ps.subscribersFor("news.test"); // pubkey[] whose patterns match this concrete topic
```

## Configuration cheatsheet

| Option | Default | What it controls |
|---|---|---|
| `axlUrl` | _(required)_ | Base URL of the local AXL daemon HTTP API |
| `privateKeyPath` OR `keyPair` | _(one is required)_ | Persistent identity (PEM path) or in-memory keypair |
| `pollIntervalMs` | `25` | How often to poll `/recv` when empty |
| `advertiseIntervalMs` | `30_000` | How often to broadcast `sub_ad` to peers |
| `subscriptionTtlMs` | `90_000` | TTL of a peer's entry in your local table |
| `dedupWindowMs` | `60_000` | Window for `(from, id)` duplicate suppression |
| `peerSweepIntervalMs` | `5_000` | Cadence of expired-peer sweep |
| `maxPayloadBytes` | `16_775_168` | Hard cap (16 MB - 1 KB headroom under AXL's 16 MB) |
| `fetchImpl` | `globalThis.fetch` | Inject a mock for unit tests |

For unit tests, inject `fetchImpl`. For integration tests against a
running mesh, use **fast overrides** to keep iterations short:

```ts
const FAST = {
  pollIntervalMs: 25,
  advertiseIntervalMs: 200,
  subscriptionTtlMs: 1500,
  peerSweepIntervalMs: 200,
};
```

## Topic grammar

- **Concrete topics** (publish targets): `news.test`, `immunity.antibody.address`
  - Segments: `[A-Za-z0-9_-]+`, separated by `.`
  - **No wildcards.** A `*` in a concrete topic fails validation.
- **Patterns** (subscribe targets): `news.*`, `immunity.antibody.*`
  - Same grammar, plus `*` as a single-segment wildcard.
  - **No multi-segment wildcard** (`#` is reserved for v0.2; rejected today).
- Matching is case-sensitive.

## Critical empirical patterns

### 1. Subscription discovery is eventually consistent

`subscribe()` triggers an immediate `sub_ad` broadcast to all known
peers — but they might not poll it for up to `pollIntervalMs`, and
peers that join the mesh after you subscribe won't know about your
subscription until the next `advertiseIntervalMs` cycle (default 30 s).

**Empirical timing**: typical first message after ~1–3 s, worst case
~30 s if a publisher just-joined. Don't assume synchronous
"subscribe → next publish reaches me" semantics.

**Test pattern**: integration tests use a 3-second settle delay between
`subscribe` and the first `publish` call.

### 2. X-From-Peer-Id is intentionally NOT cross-validated

The library **does not** enforce that AXL's `X-From-Peer-Id` header
matches the envelope's `from` (ed25519 pubkey). Source:
`src/gossip.ts:180–188`. Reasoning:

- `X-From-Peer-Id` is the AXL **daemon's** Yggdrasil-derived pubkey.
- Envelope `from` is the **gossip client's** ed25519 pubkey, used to sign.
- In a sidecar topology (gossip client running out-of-process from the
  AXL daemon — e.g., a Node app + an AXL Docker container), these are
  **different identities by design**. Forcing equality breaks valid
  topologies.

**Practical implication**: trust the envelope signature (which the
library always verifies), not the AXL header. The header is a routing
hint, not an authentication primitive.

### 3. Reserved JSON top-level keys

The library refuses to encode OR decode envelopes that contain top-level
`"service"` or `"a2a": true` keys. Source: `src/envelope.ts:103–104`.

Reason: AXL's multiplexer hijacks those payloads to its MCP and A2A
routers before they reach `/recv`. Any envelope with those keys would
silently disappear. The library guards against this on both ends so a
buggy producer can't poison the stream.

### 4. Canonical signing byte order

Envelopes are signed over a **fixed byte layout**, not over a JSON
canonicalization. Source: `src/envelope.ts:146–170`.

```
pub:    "axp:1|pub|" + id + "|" + topic + "|" + from + "|" + ts + "|" + rawPayloadBytes
sub_ad: "axp:1|sub_ad|" + from + "|" + topics.join(",") + "|" + seq + "|" + ts + "|" + ttlMs
```

If you re-implement decode in another language, match this layout
exactly. JSON canonicalization is fragile (key ordering, whitespace,
number representation); the fixed format is byte-stable.

### 5. PEM key persistence is mandatory for production

If the AXL daemon restarts without `PrivateKeyPath` set in its config,
its pubkey changes. **Your old `sub_ad`s remain in peers' tables until
their TTL** (`subscriptionTtlMs`, default 90 s), routed to a now-dead
identity. New publishes get filtered by deduplication that doesn't
expire fast enough.

Always set `PrivateKeyPath` on the AXL daemon AND `privateKeyPath` on
the `Gossip` client — otherwise restart latency = TTL = 90 s.

### 6. PublishResult semantics — `sentTo: []` is a black box

`PublishResult { sentTo: pubkey[], failed: { pubkey, error }[] }` tells
you per-peer success/failure. **But `sentTo: []` is ambiguous** — it
doesn't distinguish:
- "No subscribers exist for this topic right now"
- "Subscribers exist but their `sub_ad` hasn't propagated to me yet"
- "I'm a fresh node and my peer table is empty"

If you need delivery confirmation, layer it in your application:
include a request-id in the payload and have subscribers ack via a
reply publish on a different topic.

### 7. No backpressure on `/recv`

AXL's `/recv` queue grows in memory if your handler stalls. The library
doesn't throttle the poll loop. Source: `docs/known-limitations.md:28–30`.

If a handler can be slow (DB write, external HTTP call, blockchain tx),
**buffer between `subscribe()` and the slow processor**:

```ts
const queue: Msg[] = [];
let working = false;

await ps.subscribe("work.*", async (msg) => {
  queue.push(msg);
  if (!working) drain();
});

async function drain() {
  working = true;
  while (queue.length) {
    const msg = queue.shift()!;
    try { await slowProcessor(msg); }
    catch (err) { /* log + decide */ }
  }
  working = false;
}
```

Without this, a stalled handler builds an unbounded message backlog
inside the AXL daemon process — eventually OOM.

### 8. Errors are emitted, never thrown

Internal errors from the poller, advertiser, signature verification, or
decode failures are surfaced via the `error` EventEmitter event. They
do NOT throw. Listen to it explicitly:

```ts
ps.on("error", (err) => console.warn("axl-pubsub:", err.message));
```

If you don't, errors are silent.

### 9. `stop()` does NOT abort in-flight `/recv`

`stop()` stops scheduling new polls but waits for the current `fetch` to
the AXL daemon to complete. If the daemon is unreachable, that fetch
sits at the timeout. Plan for a `stop()` to take up to one HTTP timeout
(default `~30 s`).

## Events

| Event | Payload | When |
|---|---|---|
| `peer-joined` | `{ pubkey, topics }` | First `sub_ad` from a new peer |
| `peer-left` | `{ pubkey }` | Peer entry expired (TTL) |
| `peer-topics-changed` | `{ pubkey, topics }` | Peer's `sub_ad` lists different topics |
| `error` | `Error` | Internal error (poll, advertise, decode, verify) |

## When to use this library vs raw AXL

| Use case | Pick |
|---|---|
| One-off MCP/A2A integration | Raw AXL (`axl` skill) |
| Peer-to-peer file delivery to known recipient | Raw AXL |
| Simple request/reply (you control both ends) | Raw AXL |
| Pub/sub fan-out to N subscribers | **`axl-pubsub`** |
| Topic-based routing (e.g. `events.user.*`) | **`axl-pubsub`** |
| Multi-subscriber notifications with auto-discovery | **`axl-pubsub`** |
| Signed, deduplicated, eventually-consistent gossip | **`axl-pubsub`** |
| Mesh size > 150 nodes | Build your own (this library scales to ~150) |
| Need replay / late-subscriber history | Layer over your own log; this library is fire-and-forget |

## Known limitations (codified)

| Limitation | File reference |
|---|---|
| No store-and-forward (offline subscribers miss messages) | `docs/known-limitations.md:5` |
| Late subscribers see no history | `docs/known-limitations.md:11–14` |
| No per-topic message ordering | `docs/known-limitations.md:16–18` |
| No multi-segment wildcards (`#`) | `src/topic-matcher.ts` |
| O(N²) discovery traffic on each advertise cycle (~150 nodes max) | `src/advertiser.ts:69–78` |
| No backpressure (see #7 above) | `docs/known-limitations.md:28–30` |
| No encryption beyond TLS — JSON+base64 readable to mesh | `docs/known-limitations.md:31–33` |
| Sigs prove origin, not honesty (no anti-spam) | `docs/known-limitations.md:35–37` |
| Identity ephemeral without PEM (see #5 above) | `docs/known-limitations.md:39–41` |
| `stop()` waits for in-flight poll (see #9) | `docs/known-limitations.md:43–45` |
| `maxPayloadBytes` defaults to 16 MB - 1 KB | `src/gossip.ts:52` |

## Test patterns

Inject `fetchImpl` for unit tests against a mocked AXL:

```ts
const calls: any[] = [];
const ps = new Gossip({
  axlUrl: "http://mock",
  keyPair: testKey,
  fetchImpl: async (url, init) => {
    calls.push({ url, init });
    return new Response(null, { status: 204 });   // empty /recv
  },
});
```

For integration against a live mesh, use the `FAST` overrides above and
expect a 1–3 s settle window between `subscribe` and the first delivered
publish.

## References

- Library: https://github.com/ophelios-studio/axl-pubsub
- README walk-through: https://github.com/ophelios-studio/axl-pubsub/blob/main/README.md
- Known limitations: `docs/known-limitations.md` in the repo
- Wire format spec: `docs/wire-format.md` in the repo
- Companion `axl` skill — covers the underlying AXL HTTP API gotchas
- In-repo: `examples/axl-pubsub/{publish.js, subscribe.js}` — runnable demos
  against the 3-node mesh in `examples/axl/`.
