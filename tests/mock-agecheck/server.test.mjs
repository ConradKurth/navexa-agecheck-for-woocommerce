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
    scopes: ["agecheck:clearance", "agecheck:verify"],
    originAllowed: true,
    minimumAge: 21,
    displayName: "Mock Store",
    organizationId: "org_mock000001",
  })
})

test("GetIntegrationInfo names each key's organization (E10a)", async () => {
  await control("config", { keys: { liveStart: { ...FIXTURE_KEYS.liveStart, organizationId: "org_other" } } })
  const start = await (await call("GetIntegrationInfo", {}, { key: FIXTURE_KEYS.liveStart.key })).json()
  const fulfilment = await (await call("GetIntegrationInfo", {})).json()
  assert.equal(start.organizationId, "org_other")
  assert.equal(fulfilment.organizationId, "org_mock000001")
})

test("proto3 JSON omits false livemode and a disallowed origin", async () => {
  const res = await call("GetIntegrationInfo", { origin: "https://elsewhere.example" }, { key: FIXTURE_KEYS.testStart.key })
  assert.deepEqual(await res.json(), { scopes: ["agecheck:verify"], minimumAge: 21, displayName: "Mock Store", organizationId: "org_mock000001" })
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
    scopes: ["agecheck:clearance", "agecheck:verify"],
    originAllowed: true,
    minimumAge: 18,
    organizationId: "org_mock000001",
  })
})

// Pre-order verifications (W2) and the hosted surface.

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
  assert.deepEqual(await fr.json(), { code: "invalid_argument", message: "intended_ship_to.country: AgeCheck verifies US addresses only" })
  const fm = await call("CreateVerification", createBody({ idempotencyKey: "k-fm", intendedShipTo: { ...shipTo, country: "FM" } }))
  assert.equal(fm.status, 400)
  const fmState = await call("CreateVerification", createBody({ idempotencyKey: "k-fm-region", intendedShipTo: { ...shipTo, region: "FM" } }))
  assert.match((await fmState.json()).message, /^intended_ship_to\.region: AgeCheck verifies US addresses only$/, "a freely associated state as the region")
  const palau = await call("CreateVerification", createBody({ idempotencyKey: "k-pw-zip", intendedShipTo: { ...shipTo, region: "", postalCode: "96940" } }))
  assert.equal(palau.status, 400, "region is required")
  const palauZip = await call("CreateVerification", createBody({ idempotencyKey: "k-pw-zip2", intendedShipTo: { ...shipTo, postalCode: "96940-1234" } }))
  assert.match((await palauZip.json()).message, /postal_code: AgeCheck verifies US addresses only$/, "a freely associated state by ZIP")
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
  const noShipTo = await call("CreateVerification", createBody({ idempotencyKey: "k4", intendedShipTo: undefined }))
  assert.equal((await noShipTo.json()).message, "intended_ship_to: is required with document_name_check NONE")
  const unsetNameless = await call("CreateVerification", createBody({ idempotencyKey: "k5", documentNameCheck: undefined, intendedShipTo: undefined }))
  assert.equal(unsetNameless.status, 400, "unset without a name is NONE, which needs intended_ship_to")
  const unsetNamed = await call("CreateVerification", createBody({ idempotencyKey: "k6", documentNameCheck: undefined, intendedShipTo: undefined, identity: { name: "Pat Doe" } }))
  assert.equal(unsetNamed.status, 200, "unset with a name is REQUIRED")
  const undefinedEnum = await call("CreateVerification", createBody({ idempotencyKey: "k7", documentNameCheck: "DOCUMENT_NAME_CHECK_MAYBE" }))
  assert.equal(undefinedEnum.status, 400)
  const httpElsewhere = await call("CreateVerification", createBody({ idempotencyKey: "k8", returnUrl: "http://shop.example/back" }))
  assert.equal(httpElsewhere.status, 400, "http only for localhost")
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
  await control("decide", { id: verification.id, status: "VERIFICATION_STATUS_PASSED", passClass: "PASS_CLASS_DB_UNATTESTED" })
  assert.equal((await (await call("GetVerification", { id: verification.id })).json()).verification.passClass, "PASS_CLASS_DB_UNATTESTED", "either name form")
  const unknown = await fetch(`${base}/__mock/decide`, { method: "POST", body: JSON.stringify({ id: verification.id, status: "awaiting_identity" }) })
  assert.equal(unknown.status, 400, "only the proto's statuses")
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

// Order-bound verifications and the order RPCs (W1).

const SHIP_TO = { line1: "1 Main St", city: "Springfield", region: "IL", postalCode: "62701", country: "US" }

async function createOrderBound(overrides = {}) {
  const res = await call("CreateVerification", {
    idempotencyKey: `cv:${Math.random()}`,
    identity: { name: "Ada Lovelace", email: "ada@example.com" },
    orderExternalId: "wc:abc:1:100",
    shipTo: SHIP_TO,
    documentNameCheck: "DOCUMENT_NAME_CHECK_REQUIRED",
    ...overrides,
  })
  return { res, body: await res.json() }
}

async function clearance(key = `clr:${Math.random()}`, shipTo = SHIP_TO, orderExternalId = "wc:abc:1:100") {
  const res = await call("GetClearance", { idempotencyKey: key, orderExternalId, shipTo })
  return res.json()
}

test("order-bound create needs a document, then clears once and replays", async () => {
  const { res, body } = await createOrderBound()
  assert.equal(res.status, 200)
  const id = body.verification.id
  assert.match(id, /^acv_mock\d{6}$/)
  assert.equal(body.verification.status, "VERIFICATION_STATUS_NEEDS_DOC")
  assert.equal(body.verification.orderExternalId, "wc:abc:1:100")
  assert.equal(body.verification.livemode, true)
  assert.match(body.verification.hostedUrl, /\/v\/[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/)

  assert.deepEqual(await clearance(), { decision: "CLEARANCE_DECISION_HOLD", verificationId: id, reason: "pending", livemode: true })
  await control("verifications", { id, status: "passed" })
  assert.equal((await clearance("k1")).decision, "CLEARANCE_DECISION_CLEARED")
  assert.equal((await clearance("k1")).decision, "CLEARANCE_DECISION_CLEARED", "the same key replays")
  assert.deepEqual(await clearance("k2"), { decision: "CLEARANCE_DECISION_DENIED", verificationId: id, reason: "replayed", livemode: true })
  const moved = { ...SHIP_TO, line1: "2 Main St" }
  assert.equal((await clearance("k3", moved)).reason, "ship_to_mismatch")
  assert.equal((await clearance("k4", SHIP_TO, "wc:abc:1:999")).reason, "no_verification")
})

test("magic names decide at creation, and a failed check denies", async () => {
  const pass = await createOrderBound({ identity: { name: "Ada Docpass" } })
  assert.equal(pass.body.verification.passClass, "PASS_CLASS_DOC_ONLY")
  const fail = await createOrderBound({ identity: { name: "Ada Docdecline" }, orderExternalId: "wc:abc:1:101" })
  assert.equal(fail.body.verification.status, "VERIFICATION_STATUS_FAILED")
  assert.equal((await clearance("f", SHIP_TO, "wc:abc:1:101")).reason, "not_verified")
  const unattested = await createOrderBound({ identity: { name: "Ada Dataunattested" }, orderExternalId: "wc:abc:1:102" })
  assert.equal((await clearance("u", SHIP_TO, "wc:abc:1:102")).reason, "unattested")
})

test("create refusals: US only, one verification per order, name check needs a name, key reuse", async () => {
  const abroad = await createOrderBound({ shipTo: { ...SHIP_TO, country: "CA" } })
  assert.equal(abroad.res.status, 400)
  assert.equal(abroad.body.message, "ship_to.country: AgeCheck verifies US addresses only")
  const territory = await createOrderBound({ shipTo: { ...SHIP_TO, region: "PR", country: "PR" }, orderExternalId: "wc:abc:1:200" })
  assert.equal(territory.res.status, 200)
  await createOrderBound({ idempotencyKey: "same" })
  const again = await createOrderBound({ idempotencyKey: "same" })
  assert.equal(again.res.status, 200, "a retry replays")
  const other = await createOrderBound({ idempotencyKey: "same", identity: { name: "Someone Else" } })
  assert.equal(other.body.code, "invalid_argument")
  const second = await createOrderBound()
  assert.equal(second.body.code, "already_exists")
  const nameless = await createOrderBound({ identity: { email: "a@b.c" }, orderExternalId: "wc:abc:1:300" })
  assert.equal(nameless.body.code, "invalid_argument")
  const none = await createOrderBound({ documentNameCheck: "DOCUMENT_NAME_CHECK_NONE", orderExternalId: "wc:abc:1:301" })
  assert.equal(none.body.message, "document_name_check: NONE is not allowed with order_external_id")
  const unset = await createOrderBound({ documentNameCheck: undefined, orderExternalId: "wc:abc:1:302" })
  assert.equal(unset.res.status, 200, "unset with an order is REQUIRED")
})

test("a pre-order verification attaches only at its intended ship-to, then clears", async () => {
  const created = await (await call("CreateVerification", createBodyForAttach())).json()
  const id = created.verification.id
  await control("verifications", { id, status: "passed" })
  const bound = await call("AttachOrder", { idempotencyKey: "ao:wc:abc:1:600", verificationId: id, orderExternalId: "wc:abc:1:600", shipTo: SHIP_TO })
  assert.equal(bound.status, 200)
  assert.equal((await clearance("c600", SHIP_TO, "wc:abc:1:600")).decision, "CLEARANCE_DECISION_CLEARED")
})

function createBodyForAttach() {
  return { idempotencyKey: `pre:${Math.random()}`, intendedShipTo: SHIP_TO, documentNameCheck: "DOCUMENT_NAME_CHECK_NONE" }
}

test("AttachOrder binds once, refuses a changed ship-to and another order", async () => {
  const created = await call("CreateVerification", { idempotencyKey: "pre", identity: { name: "Ada" }, intendedShipTo: SHIP_TO, documentNameCheck: "DOCUMENT_NAME_CHECK_NONE" })
  const id = (await created.json()).verification.id
  const attach = (body) => call("AttachOrder", { idempotencyKey: `ao:${Math.random()}`, verificationId: id, orderExternalId: "wc:abc:1:500", shipTo: SHIP_TO, ...body })

  const changed = await attach({ shipTo: { ...SHIP_TO, line1: "9 Elsewhere" } })
  assert.deepEqual(await changed.json(), { code: "failed_precondition", message: "ship_to_changed" })
  const ok = await attach({ shipTo: { ...SHIP_TO, line1: " 1  MAIN st ", postalCode: "62701" } })
  assert.equal(ok.status, 200, "folded like ShipToCanonical")
  assert.equal((await ok.json()).verification.orderExternalId, "wc:abc:1:500")
  assert.equal((await (await attach({})).json()).code, "failed_precondition", "bound, even to the same order")
  assert.equal((await (await attach({ verificationId: "acv_nope" })).json()).code, "not_found")
  assert.equal((await (await attach({ shipTo: { ...SHIP_TO, country: "MX" } })).json()).code, "invalid_argument")

  const list = await (await call("ListVerifications", { orderExternalId: "wc:abc:1:500", pageSize: 1 })).json()
  assert.equal(list.verifications[0].verification.id, id)
  const getRes = await call("GetVerification", { id }, { key: FIXTURE_KEYS.testFulfilment.key })
  assert.equal(getRes.status, 404, "test keys never see live verifications")
})

test("scopes: the start key cannot ask for clearances or report shipments", async () => {
  for (const rpc of ["GetClearance", "ReportShipment"]) {
    const res = await call(rpc, { idempotencyKey: "x", orderExternalId: "o", shipTo: SHIP_TO, shippedAt: "2026-11-14T00:00:00Z" }, { key: FIXTURE_KEYS.liveStart.key })
    assert.equal(res.status, 403, rpc)
  }
})

test("ReportShipment records the first report and flags uncleared ones", async () => {
  await createOrderBound()
  for (let i = 0; i < 2; i++) {
    const res = await call("ReportShipment", { orderExternalId: "wc:abc:1:100", shippedAt: "2026-11-14T00:00:00Z" })
    assert.deepEqual(await res.json(), {})
  }
  const state = await (await fetch(`${base}/__mock/verifications`)).json()
  assert.deepEqual(state.shipments, [{ orderExternalId: "wc:abc:1:100", shippedAt: "2026-11-14T00:00:00Z", livemode: true, flagged: true }])
})

test("SendVerificationLink is served only when configured (E8c)", async () => {
  const { body } = await createOrderBound()
  const id = body.verification.id
  const absent = await call("SendVerificationLink", { idempotencyKey: `vl:${id}`, verificationId: id })
  assert.equal(absent.status, 404)
  await control("config", { links: "served" })
  const sent = await (await call("SendVerificationLink", { idempotencyKey: `vl:${id}`, verificationId: id })).json()
  assert.equal(sent.emailQueued, true)
  assert.match(sent.linkId, /^acl_mock/)
  assert.equal(sent.url, undefined, "never the URL")
  await control("verifications", { id, status: "passed" })
  const late = await call("SendVerificationLink", { idempotencyKey: "vl:other", verificationId: id })
  assert.equal((await late.json()).code, "failed_precondition")
})
