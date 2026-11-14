#!/usr/bin/env node
// Mock AgeCheck engine for plugin tests (wp-env integration and Playwright E2E).
//
// Speaks Connect unary JSON the way the engine does:
//   POST /agecheck.v1.VerificationService/<Rpc>, Authorization: Bearer txk_...
// Success is 200 with a proto3 JSON body (zero values omitted); errors are
// {"code","message"} with Connect's HTTP status; an RPC the engine does not
// serve is connect-go's plain-text 404. Zero dependencies (Node 20+).
//
// Served RPCs:
//   GetIntegrationInfo (engine PR E10a; not in prod yet)
//   CreateVerification (with E4a's optional identity, document_name_check,
//     intended_ship_to and return_url; E3's US-only ship-to), GetVerification,
//     AttachOrder, GetClearance, ReportShipment, ListVerifications
//   SendVerificationLink (E8c; only with config links: "served")
// Mutating RPCs replay their first answer per idempotency key (a different
// request under the same key is invalid_argument), like the engine.
// A new verification is needs_doc; identity.name ending in Docpass passes
// (doc_only), Docdecline fails, Dataunattested passes as db_unattested.
//
// Control endpoints for tests (never part of the engine):
//   GET  /__mock/health    {ok: true}
//   POST /__mock/reset     restore the default state (any body)
//   POST /__mock/config    merge {org?, integrationInfo?: "served"|"absent", links?: "served"|"absent", keys?}
//   POST /__mock/faults    queue {rpc?: "*"|Rpc, status?, code?, message?, retryAfter?, delayMs?, times?}
//   POST /__mock/verifications  update {id, status?, passClass?, consumed?}: decide a verification as the shopper would
//   GET  /__mock/verifications  every verification (no identity), plus shipments and links
//   GET  /__mock/requests  recorded calls: {rpc, key (fixture name or null), headers, body}; never the key itself
//
// Run: node tests/mock-agecheck/server.mjs [--port 8787] [--host 127.0.0.1]
// (or MOCK_AGECHECK_PORT / MOCK_AGECHECK_HOST). Point the plugin at it with
// define( 'NAVEXA_AGECHECK_API_URL', 'http://<host>:<port>' ) in wp-config.php.

import http from "node:http"
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

function defaultState() {
  return {
    org: {
      minimumAge: 21,
      displayName: "Mock Store",
      // wp-env's development and tests sites.
      allowedOrigins: ["http://localhost:8888", "http://localhost:8889"],
    },
    integrationInfo: "served",
    links: "absent",
    keys: structuredClone(FIXTURE_KEYS),
    faults: [],
    requests: [],
    verifications: new Map(),
    idempotency: new Map(),
    shipments: [],
    linksSent: [],
    nextId: 1,
  }
}

const US_COUNTRIES = new Set(["US", "PR", "GU", "VI", "AS", "MP", "UM"])
const STATUS = {
  created: "VERIFICATION_STATUS_CREATED",
  passed: "VERIFICATION_STATUS_PASSED",
  needs_doc: "VERIFICATION_STATUS_NEEDS_DOC",
  doc_pending: "VERIFICATION_STATUS_DOC_PENDING",
  held: "VERIFICATION_STATUS_HELD",
  failed: "VERIFICATION_STATUS_FAILED",
  awaiting_identity: "VERIFICATION_STATUS_AWAITING_IDENTITY",
}
const PASS_CLASS = {
  db_verified: "PASS_CLASS_DB_VERIFIED",
  db_unattested: "PASS_CLASS_DB_UNATTESTED",
  doc_only: "PASS_CLASS_DOC_ONLY",
  merchant_override: "PASS_CLASS_MERCHANT_OVERRIDE",
  data_verified: "PASS_CLASS_DATA_VERIFIED",
}

/** The engine's ShipToCanonical: folded lines, postal code without whitespace, country; city and region left out. */
function shipToKey(a) {
  if (!a || typeof a !== "object") return null
  const fold = (v) => String(v ?? "").toLowerCase().split(/\s+/).filter(Boolean).join(" ")
  return JSON.stringify([fold(a.line1), fold(a.line2), String(a.postalCode ?? "").replace(/\s+/g, "").toUpperCase(), String(a.country ?? "").trim().toUpperCase()])
}

/** Address problems as the engine reports them (field name only), or null. */
function addressProblem(a, field) {
  if (!a || typeof a !== "object") return `${field}: is required`
  for (const f of ["line1", "city", "region", "postalCode"]) {
    if (typeof a[f] !== "string" || a[f].trim() === "") return `${field}.${f}: is required`
  }
  if (!/^[A-Za-z]{2}$/.test(a.country ?? "")) return `${field}.country: must be an ISO 3166-1 alpha-2 code`
  if (!US_COUNTRIES.has(a.country.toUpperCase())) return "AgeCheck verifies US addresses only"
  return null
}

function verificationMessage(v) {
  return omitZero({
    id: v.id,
    status: STATUS[v.status],
    passClass: v.status === "passed" ? PASS_CLASS[v.passClass] : undefined,
    orderExternalId: v.orderExternalId,
    createdAt: v.createdAt,
    decidedAt: v.decidedAt,
    livemode: v.livemode,
  })
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

/** Runs a mutating RPC under its idempotency key: a retry replays the first answer. */
function idempotent(state, key, request, rpc, fn) {
  const ikey = request.idempotencyKey
  if (typeof ikey !== "string" || ikey.length < 1 || ikey.length > 255) return ["invalid_argument", "idempotency_key: must be 1 to 255 characters"]
  const scope = `${key.livemode ? "live" : "test"}:${rpc}:${ikey}`
  const fingerprint = JSON.stringify(request)
  const seen = state.idempotency.get(scope)
  if (seen) return seen.fingerprint === fingerprint ? seen.result : ["invalid_argument", "idempotency_key was used with a different request"]
  const result = fn()
  // The engine stores answers, not validation refusals.
  if (!result[0] || !["invalid_argument"].includes(result[0])) state.idempotency.set(scope, { fingerprint, result })
  return result
}

function findVerification(state, key, id) {
  const v = state.verifications.get(id)
  return v && v.livemode === key.livemode ? v : null
}

function boundTo(state, key, orderExternalId) {
  for (const v of state.verifications.values()) {
    if (v.livemode === key.livemode && v.orderExternalId === orderExternalId) return v
  }
  return null
}

function requireScope(key, scope) {
  return key.scopes.includes(scope) ? null : ["permission_denied", `the API key lacks the ${scope} scope`]
}

/** RPC handlers: (state, keyEntry, request) => [code?, responseOrMessage]. */
const RPCS = {
  CreateVerification(state, key, request) {
    return requireScope(key, "agecheck:verify") ?? idempotent(state, key, request, "CreateVerification", () => {
      const hasOrder = typeof request.orderExternalId === "string" && request.orderExternalId !== ""
      if (hasOrder !== (request.shipTo !== undefined)) return ["invalid_argument", "ship_to is required with order_external_id, and allowed only with it"]
      if (request.intendedShipTo !== undefined && hasOrder) return ["invalid_argument", "intended_ship_to is allowed only without order_external_id"]
      const identity = request.identity ?? {}
      if (request.documentNameCheck === "DOCUMENT_NAME_CHECK_REQUIRED" && !identity.name) return ["invalid_argument", "identity.name: is required with document_name_check REQUIRED"]
      if (hasOrder) {
        const problem = addressProblem(request.shipTo, "ship_to")
        if (problem) return ["invalid_argument", problem]
        if (boundTo(state, key, request.orderExternalId)) return ["already_exists", "agecheck: order is already bound to a verification"]
      }
      if (request.intendedShipTo !== undefined) {
        const problem = addressProblem(request.intendedShipTo, "intended_ship_to")
        if (problem) return ["invalid_argument", problem]
      }
      const name = String(identity.name ?? "")
      let status = "needs_doc"
      let passClass
      if (/docpass$/i.test(name)) [status, passClass] = ["passed", "doc_only"]
      else if (/docdecline$/i.test(name)) status = "failed"
      else if (/dataunattested$/i.test(name)) [status, passClass] = ["passed", "db_unattested"]
      const id = `acv_mock${String(state.nextId++).padStart(6, "0")}`
      const now = new Date().toISOString()
      const v = {
        id,
        status,
        passClass,
        livemode: key.livemode,
        orderExternalId: hasOrder ? request.orderExternalId : "",
        shipToKey: hasOrder ? shipToKey(request.shipTo) : null,
        intendedShipToKey: request.intendedShipTo ? shipToKey(request.intendedShipTo) : null,
        hasEmail: typeof identity.email === "string" && identity.email !== "",
        documentNameCheck: request.documentNameCheck ?? "DOCUMENT_NAME_CHECK_UNSPECIFIED",
        returnUrl: request.returnUrl ?? "",
        consumed: false,
        createdAt: now,
        decidedAt: status === "passed" || status === "failed" ? now : undefined,
      }
      state.verifications.set(id, v)
      const message = verificationMessage(v)
      if (status === "needs_doc") message.hostedUrl = `https://verify.mock.invalid/v/session-for-${id}`
      return [null, { verification: message }]
    })
  },

  GetVerification(state, key, request) {
    const denied = requireScope(key, "agecheck:verify")
    if (denied) return denied
    const v = findVerification(state, key, request.id)
    return v ? [null, { verification: verificationMessage(v) }] : ["not_found", "agecheck: verification not found"]
  },

  AttachOrder(state, key, request) {
    return requireScope(key, "agecheck:verify") ?? idempotent(state, key, request, "AttachOrder", () => {
      const problem = addressProblem(request.shipTo, "ship_to")
      if (problem) return ["invalid_argument", problem]
      const v = findVerification(state, key, request.verificationId)
      if (!v) return ["not_found", "agecheck: verification not found"]
      if (v.orderExternalId) return ["failed_precondition", "agecheck: verification is already bound to an order"]
      if (boundTo(state, key, request.orderExternalId)) return ["already_exists", "agecheck: order is already bound to a verification"]
      const target = shipToKey(request.shipTo)
      if (v.intendedShipToKey && v.intendedShipToKey !== target) return ["failed_precondition", "ship_to_changed"]
      v.orderExternalId = request.orderExternalId
      v.shipToKey = target
      return [null, { verification: verificationMessage(v) }]
    })
  },

  GetClearance(state, key, request) {
    return requireScope(key, "agecheck:clearance") ?? idempotent(state, key, request, "GetClearance", () => {
      const problem = addressProblem(request.shipTo, "ship_to")
      if (problem && problem !== "AgeCheck verifies US addresses only") return ["invalid_argument", problem]
      const answer = (decision, reason, v) =>
        [null, omitZero({ decision: `CLEARANCE_DECISION_${decision}`, verificationId: v?.id, reason, livemode: key.livemode })]
      const v = boundTo(state, key, request.orderExternalId)
      if (!v) return answer("DENIED", "no_verification")
      if (v.shipToKey !== shipToKey(request.shipTo)) return answer("DENIED", "ship_to_mismatch", v)
      if (v.status === "failed") return answer("DENIED", "not_verified", v)
      if (v.status !== "passed") return answer("HOLD", "pending", v)
      if (v.passClass === "db_unattested") return answer("HOLD", "unattested", v)
      if (v.consumed) return answer("DENIED", "replayed", v)
      v.consumed = true
      return answer("CLEARED", "verified", v)
    })
  },

  ReportShipment(state, key, request) {
    const denied = requireScope(key, "agecheck:clearance")
    if (denied) return denied
    if (typeof request.orderExternalId !== "string" || request.orderExternalId === "") return ["invalid_argument", "order_external_id: is required"]
    if (typeof request.shippedAt !== "string") return ["invalid_argument", "shipped_at: is required"]
    if (!state.shipments.some((s) => s.orderExternalId === request.orderExternalId && s.livemode === key.livemode)) {
      const v = boundTo(state, key, request.orderExternalId)
      state.shipments.push({
        orderExternalId: request.orderExternalId,
        shippedAt: request.shippedAt,
        livemode: key.livemode,
        flagged: !v || !v.consumed,
      })
    }
    return [null, {}]
  },

  ListVerifications(state, key, request) {
    const denied = requireScope(key, "agecheck:verify")
    if (denied) return denied
    const entries = [...state.verifications.values()]
      .filter((v) => v.livemode === key.livemode)
      .filter((v) => !request.orderExternalId || v.orderExternalId === request.orderExternalId)
      .reverse()
      .slice(0, request.pageSize || 50)
      .map((v) => omitZero({ verification: verificationMessage(v), pactDbVerified: v.status === "passed" && v.passClass === "db_verified" }))
    return [null, omitZero({ verifications: entries })]
  },

  SendVerificationLink(state, key, request) {
    if (state.links !== "served") return null
    return requireScope(key, "agecheck:verify") ?? idempotent(state, key, request, "SendVerificationLink", () => {
      const v = findVerification(state, key, request.verificationId)
      if (!v) return ["not_found", "agecheck: verification not found"]
      if (!["needs_doc", "doc_pending", "awaiting_identity"].includes(v.status)) return ["failed_precondition", "the verification takes no link in its state"]
      if (!v.hasEmail) return ["failed_precondition", "the verification has no email"]
      if (!state.org.displayName) return ["failed_precondition", "set a display name first"]
      const linkId = `acl_mock${String(state.linksSent.length + 1).padStart(6, "0")}`
      state.linksSent.push({ linkId, verificationId: v.id })
      return [null, { linkId, expiresAt: new Date(Date.now() + 7 * 86400e3).toISOString(), emailQueued: true }]
    })
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
    if (req.method === "GET" && url.pathname === "/__mock/verifications") {
      return sendJSON(res, 200, { verifications: [...state.verifications.values()], shipments: state.shipments, links: state.linksSent })
    }
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
        if (body.links) state.links = body.links
        if (body.keys) state.keys = { ...state.keys, ...body.keys }
        return sendJSON(res, 200, { ok: true })
      case "/__mock/verifications": {
        const v = state.verifications.get(body.id)
        if (!v) return sendJSON(res, 404, { error: "no such verification" })
        if (body.status) {
          if (!STATUS[body.status]) return sendJSON(res, 400, { error: "unknown status" })
          v.status = body.status
          v.decidedAt = ["passed", "failed"].includes(body.status) ? new Date().toISOString() : undefined
          if (body.status === "passed") v.passClass = body.passClass ?? v.passClass ?? "doc_only"
        }
        if (body.consumed !== undefined) v.consumed = Boolean(body.consumed)
        return sendJSON(res, 200, { ok: true, verification: v })
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
    const result = handler ? handler(state, key, request) : null
    if (!result) return sendNotFound(res)
    const [code, payload] = result
    if (code) return sendConnectError(res, code, payload)
    return sendJSON(res, 200, payload)
  }

  const server = http.createServer(async (req, res) => {
    try {
      const url = new URL(req.url ?? "/", "http://mock.invalid")
      if (url.pathname.startsWith("/__mock/")) return await handleControl(req, res, url)
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
