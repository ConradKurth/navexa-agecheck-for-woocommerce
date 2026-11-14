// Tests for the mock AgeCheck engine: node --test tests/mock-agecheck/
import { test, before, after, beforeEach } from "node:test"
import assert from "node:assert/strict"
import { createMockAgeCheck, FIXTURE_KEYS, SERVICE } from "./server.mjs"

let mock
let base

before(async () => {
  mock = createMockAgeCheck()
  base = await mock.listen(0, "127.0.0.1")
})

after(() => mock.close())

beforeEach(async () => {
  await fetch(`${base}/__mock/reset`, { method: "POST" })
})

function call(rpc, body, { key = FIXTURE_KEYS.liveFulfilment.key, contentType = "application/json" } = {}) {
  const headers = { "Content-Type": contentType, "Connect-Protocol-Version": "1" }
  if (key) headers.Authorization = `Bearer ${key}`
  return fetch(`${base}/${SERVICE}/${rpc}`, { method: "POST", headers, body: JSON.stringify(body) })
}

async function control(path, body) {
  const res = await fetch(`${base}/__mock/${path}`, { method: "POST", body: JSON.stringify(body) })
  assert.equal(res.status, 200)
}

test("GetIntegrationInfo answers for a live fulfilment key and an allowed origin", async () => {
  const res = await call("GetIntegrationInfo", { origin: "http://LOCALHOST:8888" })
  assert.equal(res.status, 200)
  assert.deepEqual(await res.json(), {
    livemode: true,
    scopes: ["agecheck:verify", "agecheck:clearance"],
    originAllowed: true,
    minimumAge: 21,
    displayName: "Mock Store",
  })
})

test("proto3 JSON omits false livemode and a disallowed origin", async () => {
  const res = await call("GetIntegrationInfo", { origin: "https://elsewhere.example" }, { key: FIXTURE_KEYS.testStart.key })
  assert.deepEqual(await res.json(), { scopes: ["agecheck:verify"], minimumAge: 21, displayName: "Mock Store" })
})

test("a key without agecheck:verify is permission_denied", async () => {
  const res = await call("GetIntegrationInfo", {}, { key: FIXTURE_KEYS.noScopes.key })
  assert.equal(res.status, 403)
  assert.equal((await res.json()).code, "permission_denied")
})

test("missing or unknown keys are unauthenticated", async () => {
  for (const key of [null, "txk_unknown"]) {
    const res = await call("GetIntegrationInfo", {}, { key })
    assert.equal(res.status, 401)
    assert.deepEqual(await res.json(), { code: "unauthenticated", message: "missing or unknown API key" })
  }
})

test("an engine without GetIntegrationInfo answers connect-go's plain 404", async () => {
  await control("config", { integrationInfo: "absent" })
  const res = await call("GetIntegrationInfo", {})
  assert.equal(res.status, 404)
  assert.match(res.headers.get("content-type"), /^text\/plain/)
})

test("unknown RPCs and paths are 404", async () => {
  assert.equal((await call("NoSuchRpc", {})).status, 404)
  assert.equal((await fetch(`${base}/other`, { method: "POST" })).status, 404)
  assert.equal((await fetch(`${base}/${SERVICE}/GetIntegrationInfo`)).status, 404, "GET is not served")
})

test("non-JSON content types are 415 and non-object bodies invalid_argument", async () => {
  assert.equal((await call("GetIntegrationInfo", {}, { contentType: "text/plain" })).status, 415)
  const res = await fetch(`${base}/${SERVICE}/GetIntegrationInfo`, {
    method: "POST",
    headers: { "Content-Type": "application/json", Authorization: `Bearer ${FIXTURE_KEYS.liveStart.key}` },
    body: "[1]",
  })
  assert.equal(res.status, 400)
  assert.equal((await res.json()).code, "invalid_argument")
})

test("faults are injected once, with Retry-After, then the RPC recovers", async () => {
  await control("faults", { rpc: "GetIntegrationInfo", code: "unavailable", retryAfter: 7 })
  const failed = await call("GetIntegrationInfo", {})
  assert.equal(failed.status, 503)
  assert.equal(failed.headers.get("retry-after"), "7")
  assert.equal((await failed.json()).code, "unavailable")
  assert.equal((await call("GetIntegrationInfo", {})).status, 200)
})

test("a status-only fault looks like a proxy error without a Connect body", async () => {
  await control("faults", { status: 502 })
  const res = await call("GetIntegrationInfo", {})
  assert.equal(res.status, 502)
  assert.equal(await res.text(), "fault\n")
})

test("a delay fault slows the answer without failing it", async () => {
  await control("faults", { delayMs: 120 })
  const started = Date.now()
  const res = await call("GetIntegrationInfo", {})
  assert.equal(res.status, 200)
  assert.ok(Date.now() - started >= 100)
})

test("requests are recorded by fixture name, never by key", async () => {
  await call("GetIntegrationInfo", { origin: "http://localhost:8888" }, { key: FIXTURE_KEYS.liveStart.key })
  const recorded = await (await fetch(`${base}/__mock/requests`)).json()
  assert.equal(recorded.length, 1)
  assert.equal(recorded[0].rpc, "GetIntegrationInfo")
  assert.equal(recorded[0].key, "liveStart")
  assert.equal(recorded[0].headers["connect-protocol-version"], "1")
  assert.deepEqual(recorded[0].body, { origin: "http://localhost:8888" })
  assert.ok(!JSON.stringify(recorded).includes(FIXTURE_KEYS.liveStart.key))
})

test("reset restores the default state whatever the body", async () => {
  await control("config", { integrationInfo: "absent" })
  const res = await fetch(`${base}/__mock/reset`, { method: "POST", body: "[]" })
  assert.equal(res.status, 200)
  assert.equal((await call("GetIntegrationInfo", {})).status, 200)
  const recorded = await (await fetch(`${base}/__mock/requests`)).json()
  assert.equal(recorded.length, 1)
})

test("control bodies must be JSON objects", async () => {
  const res = await fetch(`${base}/__mock/config`, { method: "POST", body: "[]" })
  assert.equal(res.status, 400)
})

test("org config changes the answer", async () => {
  await control("config", { org: { minimumAge: 18, displayName: "", allowedOrigins: ["https://shop.example"] } })
  const res = await call("GetIntegrationInfo", { origin: "https://shop.example" })
  assert.deepEqual(await res.json(), {
    livemode: true,
    scopes: ["agecheck:verify", "agecheck:clearance"],
    originAllowed: true,
    minimumAge: 18,
  })
})
