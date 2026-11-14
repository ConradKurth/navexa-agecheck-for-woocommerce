#!/usr/bin/env node
// Mock AgeCheck engine for plugin tests (wp-env integration and Playwright E2E).
//
// Speaks Connect unary JSON the way the engine does:
//   POST /agecheck.v1.VerificationService/<Rpc>, Authorization: Bearer txk_...
// Success is 200 with a proto3 JSON body (zero values omitted); errors are
// {"code","message"} with Connect's HTTP status; an RPC the engine does not
// serve is connect-go's plain-text 404. Zero dependencies (Node 20+).
//
// Served RPCs: GetIntegrationInfo (engine PR E10a; not in prod yet). Later
// milestones add CreateVerification, GetVerification, AttachOrder, ...
//
// Control endpoints for tests (never part of the engine):
//   GET  /__mock/health    {ok: true}
//   POST /__mock/reset     restore the default state (any body)
//   POST /__mock/config    merge {org?, integrationInfo?: "served"|"absent", keys?}
//   POST /__mock/faults    queue {rpc?: "*"|Rpc, status?, code?, message?, retryAfter?, delayMs?, times?}
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
    keys: structuredClone(FIXTURE_KEYS),
    faults: [],
    requests: [],
  }
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

/** RPC handlers: (state, keyEntry, request) => [code?, responseOrMessage]. */
const RPCS = {
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
        return sendJSON(res, 200, { ok: true })
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
