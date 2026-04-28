// Minimal publisher using axl-pubsub. Publishes to "news.test" every 2s.
//
// Run against the 3-node mesh in ../axl/. Requires the alice node.
//   AXL_URL=http://localhost:9002 \
//     PRIVATE_KEY_PATH=../axl/keys/alice.pem \
//     node publish.js

import { Gossip } from "axl-pubsub";

const axlUrl = process.env.AXL_URL ?? "http://localhost:9002";
const privateKeyPath = process.env.PRIVATE_KEY_PATH;
if (!privateKeyPath) {
  console.error("PRIVATE_KEY_PATH env var is required");
  process.exit(1);
}

const ps = new Gossip({ axlUrl, privateKeyPath });
ps.on("error", (err) => console.warn("axl-pubsub error:", err.message));

await ps.start();
console.log(`publisher started; axlUrl=${axlUrl}`);

let seq = 0;
const timer = setInterval(async () => {
  const payload = new TextEncoder().encode(
    JSON.stringify({ seq: seq++, ts: Date.now() }),
  );
  const result = await ps.publish("news.test", payload);
  console.log(
    `publish seq=${seq - 1} sentTo=${result.sentTo.length} failed=${result.failed.length}`,
  );
  // sentTo: [] is ambiguous — could mean "no subscribers" OR "no sub_ad yet".
  // Expect first non-empty sentTo about 1–3 s after a subscriber starts.
}, 2000);

const stop = async () => {
  clearInterval(timer);
  await ps.stop();
  process.exit(0);
};
process.on("SIGINT", stop);
process.on("SIGTERM", stop);
