// Minimal AXL send/recv round-trip — proves the raw HTTP API works.
//
// Prereq: `docker compose up -d` from this directory (mesh of alice/bob/charlie).
// Then: `node send-recv.js`.
//
// Demonstrates:
//   - GET /topology to discover the full pubkey of each node
//   - POST /send (alice -> bob) with a custom envelope (avoids MCP/A2A hijack)
//   - GET /recv on bob, sender resolved via peerIdMatches against the directory

const NODES = {
  alice:   "http://localhost:9002",
  bob:     "http://localhost:9012",
  charlie: "http://localhost:9022",
};

// /recv X-From-Peer-Id is a Yggdrasil-IPv6-derived prefix of the pubkey,
// padded with 0xff. Strip ff bytes, drop last (mixed-bit) byte, prefix-match.
function peerIdMatches(fromHeader, fullPubkeyHex) {
  if (!fromHeader || !fullPubkeyHex) return false;
  let trimmed = fromHeader.toLowerCase();
  while (trimmed.length >= 2 && trimmed.slice(-2) === "ff") trimmed = trimmed.slice(0, -2);
  if (trimmed.length < 4) return false;
  return fullPubkeyHex.toLowerCase().startsWith(trimmed.slice(0, -2));
}

async function topology(url) {
  const r = await fetch(`${url}/topology`);
  if (!r.ok) throw new Error(`topology ${r.status}`);
  return r.json();
}

async function send(fromUrl, destPubkey, body) {
  const r = await fetch(`${fromUrl}/send`, {
    method: "POST",
    headers: { "X-Destination-Peer-Id": destPubkey, "Content-Type": "application/octet-stream" },
    body,
  });
  if (!r.ok) throw new Error(`send ${r.status}`);
  return Number(r.headers.get("x-sent-bytes") ?? 0);
}

async function recvOne(url, timeoutMs = 5000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const r = await fetch(`${url}/recv`);
    if (r.status === 204) { await new Promise((res) => setTimeout(res, 50)); continue; }
    if (!r.ok) throw new Error(`recv ${r.status}`);
    return {
      from: r.headers.get("x-from-peer-id") ?? "",
      body: new Uint8Array(await r.arrayBuffer()),
    };
  }
  throw new Error(`timeout waiting for /recv on ${url}`);
}

async function main() {
  const [aT, bT, cT] = await Promise.all([
    topology(NODES.alice), topology(NODES.bob), topology(NODES.charlie),
  ]);
  const directory = {
    alice: aT.our_public_key, bob: bT.our_public_key, charlie: cT.our_public_key,
  };
  console.log("directory:", directory);

  // Custom envelope — no top-level `service` or `a2a` (would be hijacked).
  const envelope = {
    app: "demo", v: 1, kind: "hello",
    payload: { msg: "alice -> bob", ts: Date.now() },
  };
  const bytes = new TextEncoder().encode(JSON.stringify(envelope));

  console.log("sending alice -> bob ...");
  const sent = await send(NODES.alice, directory.bob, bytes);
  console.log(`  sent ${sent} bytes`);

  console.log("polling bob for the message ...");
  const got = await recvOne(NODES.bob, 5000);
  const decoded = JSON.parse(new TextDecoder().decode(got.body));
  console.log(`  received from header: ${got.from}`);
  console.log(`  envelope:`, decoded);

  // Sender identification
  const senderName = Object.entries(directory)
    .find(([, pk]) => peerIdMatches(got.from, pk))?.[0];
  console.log(`  sender resolved: ${senderName ?? "(unknown)"}`);
}

main().catch((err) => { console.error(err); process.exit(1); });
