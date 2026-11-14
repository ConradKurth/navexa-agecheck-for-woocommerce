#!/usr/bin/env node
// Mock AgeCheck engine for plugin tests (wp-env integration and Playwright E2E).
//
// Speaks Connect unary JSON the way the engine does:
//   POST /agecheck.v1.VerificationService/<Rpc>, Authorization: Bearer txk_...
// Success is 200 with a proto3 JSON body (zero values omitted); errors are
// {"code","message"} with Connect's HTTP status; an RPC the engine does not
// serve is connect-go's plain-text 404. Zero dependencies (Node 20+).
//
// Served RPCs: GetIntegrationInfo (engine PR E10a; not in prod yet);
// CreateVerification (with E4a's optional identity, intended_ship_to,
// return_url and document_name_check), GetVerification and
// CreateHostedSession (W2). Later milestones add AttachOrder, GetClearance, ...
//
// Hosted surface (W2; the CORS part is engine PR E9, not in prod yet):
//   OPTIONS /hosted/v1/status   preflight: 204 for any http(s) Origin
//   GET     /hosted/v1/status   Authorization: Bearer <session token> -> {status}; CORS only for allowed origins
//   GET     /v/<token>          a stand-in hosted page with Pass and Fail buttons
//   POST    /v/<token>          decision=pass|fail: decides, then 303 to the signed return URL
//   GET     /v/<token>/status   the page's own poll (no CORS)
//
// Control endpoints for tests (never part of the engine):
//   GET  /__mock/health    {ok: true}
//   POST /__mock/reset     restore the default state (any body)
//   POST /__mock/config    merge {org?, integrationInfo?: "served"|"absent", keys?, createOutcome?, hostedBase?}
//   POST /__mock/faults    queue {rpc?: "*"|Rpc, status?, code?, message?, retryAfter?, delayMs?, times?}
//   GET  /__mock/requests  recorded calls: {rpc, key (fixture name or null), headers, body}; never the key itself
//   POST /__mock/decide    {id, status: "passed"|"failed"|"doc_pending"|"needs_doc", passClass?}
//   GET  /__mock/verifications  every verification the mock holds
//
// Run: node tests/mock-agecheck/server.mjs [--port 8787] [--host 127.0.0.1]
// (or MOCK_AGECHECK_PORT / MOCK_AGECHECK_HOST). Point the plugin at it with
// define( 'NAVEXA_AGECHECK_API_URL', 'http://<host>:<port>' ) in wp-config.php.

import http from "node:http"
import { createHmac, randomBytes, timingSafeEqual } from "node:crypto"
import { readFileSync } from "node:fs"
import { fileURLToPath } from "node:url"
import path from "node:path"

export const SERVICE = "agecheck.v1.VerificationService"

const here = path.dirname(fileURLToPath(import.meta.url))

/** Fixture keys: {name: {key, livemode, scopes}}. Shared with the PHP and E2E suites. */
export const FIXTURE_KEYS = JSON.parse(readFileSync(path.join(here, "keys.json"), "utf8"))

const CONNECT_STATUS = {
  canceled: 499,
  unknown: 500,
  invalid_argument: 400,
  deadline_exceeded: 504,
  not_found: 404,
  already_exists: 409,
  permission_denied: 403,
  resource_exhausted: 429,
  failed_precondition: 400,
  aborted: 409,
  out_of_range: 400,
  unimplemented: 501,
  internal: 500,
  unavailable: 503,
  data_loss: 500,
  unauthenticated: 401,
}

const MAX_BODY_BYTES = 64 * 1024

/** Ship-to countries the engine accepts (E3 with the owner's territory decision). */
export const ACCEPTED_COUNTRIES = ["US", "PR", "GU", "VI", "AS", "MP", "UM"]

/** Hosted session lifetime, as the engine's (30 minutes). */
const HOSTED_TTL_SECONDS = 30 * 60

/** Signs mock hosted session tokens; random per process. */
const TOKEN_SECRET = randomBytes(32)

const STATUS_NAMES = {
  created: "VERIFICATION_STATUS_CREATED",
  db_checking: "VERIFICATION_STATUS_DB_CHECKING",
  passed: "VERIFICATION_STATUS_PASSED",
  needs_doc: "VERIFICATION_STATUS_NEEDS_DOC",
  doc_pending: "VERIFICATION_STATUS_DOC_PENDING",
  held: "VERIFICATION_STATUS_HELD",
  failed: "VERIFICATION_STATUS_FAILED",
}

function defaultState() {
  return {
    org: {
      minimumAge: 21,
      displayName: "Mock Store",
      // wp-env's development and tests sites.
      allowedOrigins: ["http://localhost:8888", "http://localhost:8889"],
    },
    integrationInfo: "served",
    keys: structuredClone(FIXTURE_KEYS),
    // What CreateVerification decides at creation: "needs_doc" (no DOB was
    // sent, so the shopper must show an ID), "passed" or "failed".
    createOutcome: "needs_doc",
    // Base of hosted URLs; "" = the Host the request came in on.
    hostedBase: "",
    faults: [],
    requests: [],
    verifications: new Map(),
    idempotency: new Map(),
    seq: 0,
  }
}

const b64url = (buf) => Buffer.from(buf).toString("base64url")

function mintToken(vid, ru) {
  const payload = b64url(JSON.stringify({ v: 1, vid, exp: Math.floor(Date.now() / 1000) + HOSTED_TTL_SECONDS, jti: b64url(randomBytes(16)), ru: ru || undefined }))
  return `${payload}.${b64url(createHmac("sha256", TOKEN_SECRET).update(payload).digest())}`
}

/** The claims of a valid, unexpired mock session token; null otherwise. */
export function readToken(token) {
  if (typeof token !== "string" || token.length > 2048) return null
  const [payload, sig, extra] = token.split(".")
  if (!payload || !sig || extra !== undefined) return null
  const want = createHmac("sha256", TOKEN_SECRET).update(payload).digest()
  const got = Buffer.from(sig, "base64url")
  if (got.length !== want.length || !timingSafeEqual(got, want)) return null
  try {
    const claims = JSON.parse(Buffer.from(payload, "base64url").toString("utf8"))
    return claims.exp >= Math.floor(Date.now() / 1000) ? claims : null
  } catch {
    return null
  }
}

/** The engine's ship-to comparison form (Address.ShipToCanonical, without the length prefixes). */
function shipToKey(a) {
  const fold = (s) => String(s ?? "").toLowerCase().split(/\s+/).filter(Boolean).join(" ")
  return [fold(a.line1), fold(a.line2), String(a.postalCode ?? "").replace(/\s+/g, "").toUpperCase(), String(a.country ?? "").trim().toUpperCase()].join("\n")
}

/** An Address message's first problem, or null. */
function addressProblem(a, field) {
  if (!a || typeof a !== "object") return `${field} is required`
  for (const name of ["line1", "city", "region", "postalCode"]) {
    if (typeof a[name] !== "string" || a[name].trim() === "") return `${field}.${name} is required`
  }
  if (typeof a.country !== "string" || !/^[A-Za-z]{2}$/.test(a.country)) return `${field}.country must be an ISO 3166-1 alpha-2 code`
  return null
}

/** Whether returnUrl is absolute http(s) on one of the org's allowed origins. */
function returnUrlAllowed(state, returnUrl) {
  if (typeof returnUrl !== "string" || returnUrl.length > 2048) return false
  try {
    const url = new URL(returnUrl)
    if (url.protocol !== "https:" && url.protocol !== "http:") return false
    return state.org.allowedOrigins.some((o) => o.toLowerCase() === url.origin.toLowerCase())
  } catch {
    return false
  }
}

function originAllowed(state, origin) {
  return typeof origin === "string" && state.org.allowedOrigins.some((o) => o.toLowerCase() === origin.toLowerCase())
}

/** The Verification message for v (proto3 JSON, zero values omitted). */
function verificationMessage(v, hostedUrl) {
  return omitZero({
    id: v.id,
    status: STATUS_NAMES[v.status],
    passClass: v.status === "passed" ? v.passClass : "",
    orderExternalId: v.orderExternalId,
    createdAt: v.createdAt,
    decidedAt: v.decidedAt,
    hostedUrl: hostedUrl ?? "",
    livemode: v.livemode,
  })
}

function hostedUrlFor(state, v, returnUrl, host) {
  const base = state.hostedBase || `http://${host}`
  return `${base}/v/${mintToken(v.id, returnUrl)}`
}

/** Finds the verification an API key of this mode may read. */
function ownVerification(state, key, id) {
  const v = typeof id === "string" ? state.verifications.get(id) : undefined
  return v && v.livemode === key.livemode ? v : null
}

/** Proto3 JSON leaves out fields holding their zero value. */
function omitZero(message) {
  const out = {}
  for (const [name, value] of Object.entries(message)) {
    if (value === false || value === 0 || value === "" || value == null) continue
    if (Array.isArray(value) && value.length === 0) continue
    out[name] = value
  }
  return out
}

function sendJSON(res, status, body, headers = {}) {
  const text = JSON.stringify(body)
  res.writeHead(status, { "Content-Type": "application/json", "Content-Length": Buffer.byteLength(text), ...headers })
  res.end(text)
}

function sendConnectError(res, code, message, headers = {}) {
  sendJSON(res, CONNECT_STATUS[code] ?? 500, { code, message }, headers)
}

function sendNotFound(res) {
  res.writeHead(404, { "Content-Type": "text/plain; charset=utf-8" })
  res.end("404 page not found\n")
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = []
    let size = 0
    req.on("data", (chunk) => {
      size += chunk.length
      if (size > MAX_BODY_BYTES) {
        reject(Object.assign(new Error("body too large"), { tooLarge: true }))
        req.destroy()
        return
      }
      chunks.push(chunk)
    })
    req.on("end", () => resolve(Buffer.concat(chunks).toString("utf8")))
    req.on("error", reject)
  })
}

function parseJSONObject(text) {
  if (text === "") return {}
  try {
    const value = JSON.parse(text)
    return value !== null && typeof value === "object" && !Array.isArray(value) ? value : undefined
  } catch {
    return undefined
  }
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

/** RPC handlers: (state, keyEntry, request, ctx) => [code?, responseOrMessage]. */
const RPCS = {
  CreateVerification(state, key, request, ctx) {
    if (!key.scopes.includes("agecheck:verify")) return ["permission_denied", "the API key lacks the agecheck:verify scope"]
    const idem = request.idempotencyKey
    if (typeof idem !== "string" || idem.length < 1 || idem.length > 255) return ["invalid_argument", "idempotency_key: must be 1 to 255 characters"]
    const hasOrder = typeof request.orderExternalId === "string" && request.orderExternalId !== ""
    if (hasOrder !== (request.shipTo != null)) return ["invalid_argument", "ship_to is required with order_external_id, and allowed only with it"]
    if (hasOrder && request.intendedShipTo != null) return ["invalid_argument", "intended_ship_to is allowed only without order_external_id"]
    for (const [field, address] of [["ship_to", request.shipTo], ["intended_ship_to", request.intendedShipTo]]) {
      if (address == null) continue
      const problem = addressProblem(address, field)
      if (problem) return ["invalid_argument", problem]
      if (!ACCEPTED_COUNTRIES.includes(address.country.toUpperCase())) return ["invalid_argument", "AgeCheck verifies US addresses only"]
    }
    if (request.identity != null) {
      if (typeof request.identity !== "object") return ["invalid_argument", "identity: must be a message"]
      if (request.identity.residentialAddress != null) {
        const problem = addressProblem(request.identity.residentialAddress, "identity.residential_address")
        if (problem) return ["invalid_argument", problem]
      }
      if (request.identity.dob != null && !/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/.test(request.identity.dob)) return ["invalid_argument", "identity.dob: must be YYYY-MM-DD"]
    }
    const nameCheck = request.documentNameCheck ?? "DOCUMENT_NAME_CHECK_UNSPECIFIED"
    if (!["DOCUMENT_NAME_CHECK_UNSPECIFIED", "DOCUMENT_NAME_CHECK_REQUIRED", "DOCUMENT_NAME_CHECK_NONE"].includes(nameCheck)) return ["invalid_argument", "document_name_check: unknown value"]
    if (nameCheck === "DOCUMENT_NAME_CHECK_REQUIRED" && !request.identity?.name) return ["invalid_argument", "identity.name is required with document_name_check REQUIRED"]
    if (request.returnUrl != null && !returnUrlAllowed(state, request.returnUrl)) return ["invalid_argument", "return_url: must be an absolute URL on one of the organization's allowed origins"]

    const scoped = `${key.livemode ? "live" : "test"}:${idem}`
    const { idempotencyKey: _ignored, ...rest } = request
    const fingerprint = JSON.stringify(rest)
    const seen = state.idempotency.get(scoped)
    if (seen) {
      if (seen.fingerprint !== fingerprint) return ["invalid_argument", "idempotency key reused with a different request"]
      const v = state.verifications.get(seen.id)
      const link = v.status === "needs_doc" ? hostedUrlFor(state, v, v.returnUrl, ctx.host) : undefined
      return [null, { verification: verificationMessage(v, link) }]
    }

    state.seq += 1
    const now = new Date().toISOString()
    const outcome = state.createOutcome
    const v = {
      id: `acv_mock${String(state.seq).padStart(6, "0")}`,
      status: outcome,
      passClass: outcome === "passed" ? "PASS_CLASS_DOC_ONLY" : "",
      orderExternalId: hasOrder ? request.orderExternalId : "",
      createdAt: now,
      decidedAt: outcome === "passed" || outcome === "failed" ? now : "",
      livemode: key.livemode,
      returnUrl: request.returnUrl ?? "",
      intendedShipTo: request.intendedShipTo ? shipToKey(request.intendedShipTo) : "",
      documentNameCheck: nameCheck,
    }
    state.verifications.set(v.id, v)
    state.idempotency.set(scoped, { fingerprint, id: v.id })
    const link = v.status === "needs_doc" ? hostedUrlFor(state, v, v.returnUrl, ctx.host) : undefined
    return [null, { verification: verificationMessage(v, link) }]
  },

  GetVerification(state, key, request) {
    if (!key.scopes.includes("agecheck:verify")) return ["permission_denied", "the API key lacks the agecheck:verify scope"]
    if (typeof request.id !== "string" || !request.id.startsWith("acv_")) return ["invalid_argument", "id: must start with acv_"]
    const v = ownVerification(state, key, request.id)
    if (!v) return ["not_found", "verification not found"]
    return [null, { verification: verificationMessage(v) }]
  },

  CreateHostedSession(state, key, request, ctx) {
    if (!key.scopes.includes("agecheck:verify")) return ["permission_denied", "the API key lacks the agecheck:verify scope"]
    const v = ownVerification(state, key, request.verificationId)
    if (!v) return ["not_found", "verification not found"]
    if (v.status !== "needs_doc" && v.status !== "doc_pending") return ["failed_precondition", "the verification is not waiting on the shopper"]
    const returnUrl = request.returnUrl ?? ""
    if (returnUrl !== "" && !returnUrlAllowed(state, returnUrl)) return ["invalid_argument", "return_url: must be an absolute URL on one of the organization's allowed origins"]
    return [
      null,
      {
        hostedUrl: hostedUrlFor(state, v, returnUrl, ctx.host),
        expiresAt: new Date(Date.now() + HOSTED_TTL_SECONDS * 1000).toISOString(),
      },
    ]
  },

  GetIntegrationInfo(state, key, request) {
    if (state.integrationInfo !== "served") return null
    if (!key.scopes.includes("agecheck:verify")) return ["permission_denied", "the API key lacks the agecheck:verify scope"]
    const origin = typeof request.origin === "string" ? request.origin.toLowerCase() : ""
    const originAllowed = origin !== "" && state.org.allowedOrigins.some((o) => o.toLowerCase() === origin)
    return [
      null,
      omitZero({
        livemode: key.livemode,
        scopes: key.scopes,
        originAllowed,
        minimumAge: state.org.minimumAge,
        displayName: state.org.displayName,
      }),
    ]
  },
}

function decide(v, status, passClass) {
  v.status = status
  v.passClass = status === "passed" ? passClass || "PASS_CLASS_DOC_ONLY" : ""
  v.decidedAt = status === "passed" || status === "failed" ? new Date().toISOString() : ""
}

function escapeHTML(text) {
  return String(text).replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`)
}

/** The hosted surface: status poll (with E9's CORS) and a stand-in page. */
async function handleHosted(state, req, res, url) {
  const origin = req.headers.origin
  if (url.pathname === "/hosted/v1/status") {
    if (req.method === "OPTIONS") {
      // E9: answered before any token check, for any well-formed origin, without credentials.
      const headers = { "Access-Control-Allow-Methods": "GET", "Access-Control-Allow-Headers": "Authorization", "Access-Control-Max-Age": "600", Vary: "Origin" }
      if (typeof origin === "string" && /^https?:\/\/[^/]+$/i.test(origin)) headers["Access-Control-Allow-Origin"] = origin
      res.writeHead(204, headers)
      return res.end()
    }
    if (req.method !== "GET") return sendNotFound(res)
    const match = /^Bearer (\S+)$/.exec(req.headers.authorization ?? "")
    const claims = match ? readToken(match[1]) : null
    const v = claims ? state.verifications.get(claims.vid) : null
    if (!v) return sendJSON(res, 401, { error: "unauthorized" })
    const headers = { Vary: "Origin", "Cache-Control": "no-store" }
    if (originAllowed(state, origin)) {
      headers["Access-Control-Allow-Origin"] = origin
      headers["Access-Control-Expose-Headers"] = "Retry-After"
    }
    return sendJSON(res, 200, { status: v.status }, headers)
  }

  const page = /^\/v\/([^/]+)(\/status)?$/.exec(url.pathname)
  const claims = page ? readToken(page[1]) : null
  const v = claims ? state.verifications.get(claims.vid) : null
  if (!page || !v) return sendNotFound(res)
  if (page[2]) return req.method === "GET" ? sendJSON(res, 200, { status: v.status }) : sendNotFound(res)
  if (req.method === "POST") {
    const form = new URLSearchParams(await readBody(req))
    if (form.get("decision") === "pass") decide(v, "passed")
    else if (form.get("decision") === "fail") decide(v, "failed")
    if (claims.ru) {
      res.writeHead(303, { Location: claims.ru })
      return res.end()
    }
  }
  const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Mock AgeCheck</title></head><body>
<h1>Mock AgeCheck (TEST)</h1><p>Status: <strong id="status">${escapeHTML(v.status)}</strong></p>
<form method="post"><button name="decision" value="pass">Pass</button> <button name="decision" value="fail">Fail</button></form>
</body></html>`
  res.writeHead(200, { "Content-Type": "text/html; charset=utf-8", "Cache-Control": "no-store", "Referrer-Policy": "no-referrer", "Cross-Origin-Opener-Policy": "same-origin" })
  return res.end(html)
}

/**
 * Creates the mock. Call listen(port, host) to start it; it resolves to the base URL.
 */
export function createMockAgeCheck() {
  let state = defaultState()

  function keyFor(req) {
    const header = req.headers.authorization ?? ""
    const match = /^Bearer (\S+)$/.exec(header)
    if (!match) return null
    for (const [name, entry] of Object.entries(state.keys)) {
      if (entry.key === match[1]) return { name, ...entry }
    }
    return null
  }

  async function handleControl(req, res, url) {
    if (req.method === "GET" && url.pathname === "/__mock/health") return sendJSON(res, 200, { ok: true })
    if (req.method === "GET" && url.pathname === "/__mock/requests") return sendJSON(res, 200, state.requests)
    if (req.method === "GET" && url.pathname === "/__mock/verifications") return sendJSON(res, 200, [...state.verifications.values()])
    if (req.method !== "POST") return sendNotFound(res)
    const text = await readBody(req)
    if (url.pathname === "/__mock/reset") {
      state = defaultState()
      return sendJSON(res, 200, { ok: true })
    }
    const body = parseJSONObject(text)
    if (body === undefined) return sendJSON(res, 400, { error: "body must be a JSON object" })
    switch (url.pathname) {
      case "/__mock/config":
        if (body.org) state.org = { ...state.org, ...body.org }
        if (body.integrationInfo) state.integrationInfo = body.integrationInfo
        if (body.keys) state.keys = { ...state.keys, ...body.keys }
        if (body.createOutcome) state.createOutcome = body.createOutcome
        if (typeof body.hostedBase === "string") state.hostedBase = body.hostedBase.replace(/\/+$/, "")
        return sendJSON(res, 200, { ok: true })
      case "/__mock/decide": {
        const v = state.verifications.get(body.id)
        if (!v || !STATUS_NAMES[body.status]) return sendJSON(res, 400, { error: "unknown verification or status" })
        decide(v, body.status, body.passClass)
        return sendJSON(res, 200, { ok: true })
      }
      case "/__mock/faults":
        state.faults.push({ rpc: "*", times: 1, ...body })
        return sendJSON(res, 200, { ok: true, queued: state.faults.length })
      default:
        return sendNotFound(res)
    }
  }

  async function handleRPC(req, res, rpc) {
    const fault = state.faults.find((f) => f.rpc === "*" || f.rpc === rpc)
    if (fault) {
      fault.times -= 1
      if (fault.times <= 0) state.faults.splice(state.faults.indexOf(fault), 1)
    }
    if (fault?.delayMs) await sleep(fault.delayMs)

    const text = await readBody(req)
    const key = keyFor(req)
    const request = parseJSONObject(text)
    state.requests.push({
      rpc,
      key: key ? key.name : null,
      headers: {
        "content-type": req.headers["content-type"] ?? null,
        "connect-protocol-version": req.headers["connect-protocol-version"] ?? null,
        "connect-timeout-ms": req.headers["connect-timeout-ms"] ?? null,
        "user-agent": req.headers["user-agent"] ?? null,
      },
      body: request ?? null,
    })

    if (fault && (fault.code || fault.status)) {
      const headers = fault.retryAfter != null ? { "Retry-After": String(fault.retryAfter) } : {}
      if (!fault.code) {
        res.writeHead(fault.status, { "Content-Type": "text/plain", ...headers })
        return res.end("fault\n")
      }
      return sendJSON(res, fault.status ?? CONNECT_STATUS[fault.code] ?? 500, { code: fault.code, message: fault.message ?? "injected fault" }, headers)
    }

    const contentType = (req.headers["content-type"] ?? "").split(";")[0].trim().toLowerCase()
    if (contentType !== "application/json") {
      res.writeHead(415, { "Accept-Post": "application/json, application/proto" })
      return res.end()
    }
    if (!key) return sendConnectError(res, "unauthenticated", "missing or unknown API key")
    if (request === undefined) return sendConnectError(res, "invalid_argument", "request body is not a JSON object")

    const handler = Object.hasOwn(RPCS, rpc) ? RPCS[rpc] : undefined
    const result = handler ? handler(state, key, request, { host: req.headers.host ?? "127.0.0.1" }) : null
    if (!result) return sendNotFound(res)
    const [code, payload] = result
    if (code) return sendConnectError(res, code, payload)
    return sendJSON(res, 200, payload)
  }

  const server = http.createServer(async (req, res) => {
    try {
      const url = new URL(req.url ?? "/", "http://mock.invalid")
      if (url.pathname.startsWith("/__mock/")) return await handleControl(req, res, url)
      if (url.pathname === "/hosted/v1/status" || url.pathname.startsWith("/v/")) return await handleHosted(state, req, res, url)
      const prefix = `/${SERVICE}/`
      if (req.method === "POST" && url.pathname.startsWith(prefix)) {
        const rpc = url.pathname.slice(prefix.length)
        if (/^[A-Z][A-Za-z0-9]*$/.test(rpc)) return await handleRPC(req, res, rpc)
      }
      return sendNotFound(res)
    } catch (err) {
      if (err?.tooLarge) return sendConnectError(res, "resource_exhausted", "request body too large")
      if (!res.headersSent) sendConnectError(res, "internal", "mock error")
      else res.destroy()
    }
  })

  return {
    server,
    get state() {
      return state
    },
    listen(port = 8787, host = "127.0.0.1") {
      return new Promise((resolve, reject) => {
        server.once("error", reject)
        server.listen(port, host, () => {
          const address = server.address()
          resolve(`http://${host.includes(":") ? `[${host}]` : host}:${address.port}`)
        })
      })
    },
    close() {
      server.closeAllConnections?.()
      return new Promise((resolve) => server.close(() => resolve()))
    },
  }
}

function argValue(name) {
  const index = process.argv.indexOf(name)
  return index >= 0 ? process.argv[index + 1] : undefined
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const port = Number(argValue("--port") ?? process.env.MOCK_AGECHECK_PORT ?? 8787)
  const host = argValue("--host") ?? process.env.MOCK_AGECHECK_HOST ?? "127.0.0.1"
  const mock = createMockAgeCheck()
  const url = await mock.listen(port, host)
  console.log(`mock-agecheck listening on ${url}`)
  const stop = () => mock.close().then(() => process.exit(0))
  process.on("SIGINT", stop)
  process.on("SIGTERM", stop)
}
