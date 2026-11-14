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

// --- W2: pre-order verifications and the hosted surface ---

const shipTo = { line1: "1 Main St", city: "Springfield", region: "IL", postalCode: "62701", country: "US" }

function createBody(overrides = {}) {
  return {
    idempotencyKey: "install:sid:mac:0",
    intendedShipTo: shipTo,
    documentNameCheck: "DOCUMENT_NAME_CHECK_NONE",
    returnUrl: "http://localhost:8888/?wc-api=navexa_agecheck_return&popup=1",
    ...overrides,
  }
}

test("CreateVerification without identity answers NEEDS_DOC with a hosted link", async () => {
  const res = await call("CreateVerification", createBody(), { key: FIXTURE_KEYS.liveStart.key })
  assert.equal(res.status, 200)
  const { verification } = await res.json()
  assert.match(verification.id, /^acv_/)
  assert.equal(verification.status, "VERIFICATION_STATUS_NEEDS_DOC")
  assert.equal(verification.livemode, true)
  assert.match(verification.hostedUrl, /\/v\/[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/)
  assert.equal(verification.passClass, undefined, "proto3 JSON omits zero values")
})

test("CreateVerification replays its key and refuses a reused key with another body", async () => {
  const first = await (await call("CreateVerification", createBody())).json()
  const again = await (await call("CreateVerification", createBody())).json()
  assert.equal(again.verification.id, first.verification.id)
  const other = await call("CreateVerification", createBody({ intendedShipTo: { ...shipTo, line1: "2 Main St" } }))
  assert.equal(other.status, 400)
  assert.equal((await other.json()).code, "invalid_argument")
})

test("CreateVerification refuses non-US ship-to, accepts territories", async () => {
  const fr = await call("CreateVerification", createBody({ idempotencyKey: "k-fr", intendedShipTo: { ...shipTo, country: "FR" } }))
  assert.equal(fr.status, 400)
  assert.deepEqual(await fr.json(), { code: "invalid_argument", message: "AgeCheck verifies US addresses only" })
  const fm = await call("CreateVerification", createBody({ idempotencyKey: "k-fm", intendedShipTo: { ...shipTo, country: "FM" } }))
  assert.equal(fm.status, 400)
  const pr = await call("CreateVerification", createBody({ idempotencyKey: "k-pr", intendedShipTo: { ...shipTo, region: "PR", postalCode: "00901", country: "PR" } }))
  assert.equal(pr.status, 200)
})

test("CreateVerification validates return_url, intended_ship_to with an order, and the name check", async () => {
  const bad = await call("CreateVerification", createBody({ idempotencyKey: "k1", returnUrl: "https://elsewhere.example/back" }))
  assert.equal(bad.status, 400)
  const both = await call("CreateVerification", createBody({ idempotencyKey: "k2", orderExternalId: "wc:1:1:1", shipTo }))
  assert.equal(both.status, 400)
  const required = await call("CreateVerification", createBody({ idempotencyKey: "k3", documentNameCheck: "DOCUMENT_NAME_CHECK_REQUIRED" }))
  assert.equal(required.status, 400)
})

test("GetVerification reads one mode only; decide moves the state", async () => {
  const { verification } = await (await call("CreateVerification", createBody())).json()
  const other = await call("GetVerification", { id: verification.id }, { key: FIXTURE_KEYS.testStart.key })
  assert.equal(other.status, 404)
  await control("decide", { id: verification.id, status: "passed" })
  const read = await (await call("GetVerification", { id: verification.id })).json()
  assert.equal(read.verification.status, "VERIFICATION_STATUS_PASSED")
  assert.equal(read.verification.passClass, "PASS_CLASS_DOC_ONLY")
  assert.equal(read.verification.hostedUrl, undefined)
})

test("CreateHostedSession mints a link only while the shopper has a step to take", async () => {
  const { verification } = await (await call("CreateVerification", createBody())).json()
  const minted = await call("CreateHostedSession", { verificationId: verification.id, returnUrl: "http://localhost:8888/back" })
  assert.equal(minted.status, 200)
  const body = await minted.json()
  assert.match(body.hostedUrl, /\/v\//)
  assert.ok(Date.parse(body.expiresAt) > Date.now())
  await control("decide", { id: verification.id, status: "failed" })
  const refused = await call("CreateHostedSession", { verificationId: verification.id })
  assert.equal(refused.status, 400)
  assert.equal((await refused.json()).code, "failed_precondition")
})

test("status poll: preflight for any origin, CORS only for allowed origins, 401 without ACAO", async () => {
  const { verification } = await (await call("CreateVerification", createBody())).json()
  const token = new URL(verification.hostedUrl).pathname.slice(3)

  const pre = await fetch(`${base}/hosted/v1/status`, { method: "OPTIONS", headers: { Origin: "https://any.example" } })
  assert.equal(pre.status, 204)
  assert.equal(pre.headers.get("access-control-allow-headers"), "Authorization")

  const allowed = await fetch(`${base}/hosted/v1/status`, { headers: { Authorization: `Bearer ${token}`, Origin: "http://localhost:8888" } })
  assert.equal(allowed.status, 200)
  assert.equal(allowed.headers.get("access-control-allow-origin"), "http://localhost:8888")
  assert.deepEqual(await allowed.json(), { status: "needs_doc" })

  const other = await fetch(`${base}/hosted/v1/status`, { headers: { Authorization: `Bearer ${token}`, Origin: "https://evil.example" } })
  assert.equal(other.status, 200)
  assert.equal(other.headers.get("access-control-allow-origin"), null)

  const bad = await fetch(`${base}/hosted/v1/status`, { headers: { Authorization: "Bearer nope.nope", Origin: "http://localhost:8888" } })
  assert.equal(bad.status, 401)
  assert.equal(bad.headers.get("access-control-allow-origin"), null)
})

test("the stand-in hosted page decides and returns to the signed return URL", async () => {
  const { verification } = await (await call("CreateVerification", createBody())).json()
  const page = await fetch(verification.hostedUrl.replace(/^http:\/\/[^/]+/, base))
  assert.equal(page.status, 200)
  assert.equal(page.headers.get("cross-origin-opener-policy"), "same-origin")
  const post = await fetch(verification.hostedUrl.replace(/^http:\/\/[^/]+/, base), {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: "decision=pass",
    redirect: "manual",
  })
  assert.equal(post.status, 303)
  assert.equal(post.headers.get("location"), "http://localhost:8888/?wc-api=navexa_agecheck_return&popup=1")
  const read = await (await call("GetVerification", { id: verification.id })).json()
  assert.equal(read.verification.status, "VERIFICATION_STATUS_PASSED")
})

test("createOutcome and hostedBase config", async () => {
  await control("config", { createOutcome: "passed", hostedBase: "https://verify.example/" })
  const { verification } = await (await call("CreateVerification", createBody())).json()
  assert.equal(verification.status, "VERIFICATION_STATUS_PASSED")
  await control("decide", { id: verification.id, status: "needs_doc" })
  const minted = await (await call("CreateHostedSession", { verificationId: verification.id })).json()
  assert.ok(minted.hostedUrl.startsWith("https://verify.example/v/"))
})
