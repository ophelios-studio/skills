# axl-pubsub examples

Two minimal scripts demonstrating the library against the 3-node AXL
mesh in `../axl/`.

## Setup

```bash
# 1. Bring up the AXL mesh first (see ../axl/README.md)
cd ../axl && docker compose up -d && cd ../axl-pubsub

# 2. Install the library
npm init -y
npm install axl-pubsub

# 3. Subscribe (in one terminal) — connects to bob (port 9012)
AXL_URL=http://localhost:9012 \
  PRIVATE_KEY_PATH=../axl/keys/bob.pem \
  node subscribe.js

# 4. Publish (in another terminal) — connects to alice (port 9002)
AXL_URL=http://localhost:9002 \
  PRIVATE_KEY_PATH=../axl/keys/alice.pem \
  node publish.js
```

Expected output on the subscriber (after 1–3 s settle):
```
peer-joined: eeb76d51d746… topics=news.test
[news.test] from=eeb76d51d746… {"seq":0,"ts":1730938572310}
[news.test] from=eeb76d51d746… {"seq":1,"ts":1730938574311}
...
```

## What this demonstrates

- `Gossip` lifecycle: construct → `start()` → `subscribe()` → emit → `stop()`.
- Peer discovery via `peer-joined` event.
- Wildcard pattern matching (`news.*` catches `news.test`).
- `PublishResult.sentTo` populates after the subscription advertisement
  reaches the publisher (typical 1–3 s).
- Error events are logged, not thrown.
