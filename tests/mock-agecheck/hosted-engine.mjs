#!/usr/bin/env node
// Mock AgeCheck engine: the hosted surface the loader talks to (TAX-1873,
// brief C2 v2 B7). One mock for the loader's own e2e and for every consumer's
// browser tests (WooCommerce, Medusa, the BigCommerce and Shopify apps), kept
// in step with the engine's hosted page (TAX-1872, C1 v2). Zero
// dependencies (Node 22+).
//
// Engine surface (as verify.navexa.co serves it):
//   GET  /v/<token>                 the hosted page (session tokens only:
//                                   <base64url payload>.<base64url mac>)
//     ?embed=1[&parent_origin=<o>]  embed mode (C1 v2): CSP frame-ancestors
//                                   'self' + allowedOrigins; posts
//                                   {type:'navexa-agecheck', v:2, event,
//                                   verification_id, status?, height?} to its
//                                   parent at parent_origin when that is in
//                                   allowedOrigins (exact match), else at the
//                                   return URL's origin when that is, else
//                                   posts nothing (A2): ready on load, resize
//                                   on every height change, decided on a
//                                   decided view, close_requested from
//                                   "Return to checkout". A decision renders
//                                   a done view in the frame and never goes
//                                   to the return URL (A1). "Continue on this
//                                   device" opens the plain page in a new
//                                   window from the click (A6).
//     plain (popup or redirect)     frame-ancestors 'self'; a decided page
//                                   posts the v1 message {type, v:1,
//                                   verification_id, status} to its opener at
//                                   the return URL's origin, or, with no
//                                   opener, goes to the return URL.
//   POST /v/<token>                 decision=pass|fail (the stand-in for the
//                                   real checks), then 303 to the same page
//   GET  /hosted/v1/status          Authorization: Bearer <session token> ->
//                                   {status}; CORS for allowedOrigins only;
//                                   401 without a live session
//   OPTIONS /hosted/v1/status       its preflight (allowedOrigins only)
//   GET  /js/<file>                 the loader build (default: agecheck-js/dist when
//                                   built, else the committed loaderjs/ copy),
//                                   Access-Control-Allow-Origin: *
//
// Test controls (never part of the engine; CORS *, JSON bodies):
//   POST /__mock/start     the store server's "start": creates a verification
//                          and a hosted session -> {url, verificationId}; body
//                          {returnUrl?, status?}. With config startAnswer set,
//                          answers that instead ({status, message?}: B1).
//   POST /__mock/decide    {verificationId | token, status}: decide out of band
//   POST /__mock/config    merge {allowedOrigins?: string[], embed?: "serve" |
//                          "refuse" (frame-ancestors 'none': the browser
//                          refuses the frame) | "silent" (no messages at all),
//                          startAnswer?: {status, message?} | null}
//   POST /__mock/reset     default config, no verifications
//   GET  /__mock/state     {config, verifications: [{id, status, returnUrl,
//                          sessions}]}
//   GET  /__mock/health    {ok: true}
//
// Library: createMockEngine(options) -> {handle(req, res): boolean, reset(),
// state} (mount it inside another mock, e.g. Woo's Connect mock), and
// startMockEngine({port, host, ...options}) -> {origin, engine, close()}.
// CLI: node mock/server.mjs [--port 8787] [--host 127.0.0.1]
//      [--allowed-origin <origin>]... [--js-dir <dir>]
import { createHmac, randomBytes, timingSafeEqual } from "node:crypto"
import { existsSync, readFileSync, realpathSync } from "node:fs"
import http from "node:http"
import path from "node:path"
import { fileURLToPath } from "node:url"

const here = path.dirname(fileURLToPath(import.meta.url))
// The local build (npm run build), else the committed copy the engine embeds.
const DIST_DIR = path.resolve(here, "../dist")
const EMBED_DIR = path.resolve(here, "../../backend/internal/agecheck/http/loaderjs")
const DEFAULT_JS_DIR = existsSync(path.join(DIST_DIR, "manifest.json")) || !existsSync(EMBED_DIR) ? DIST_DIR : EMBED_DIR
const SESSION_SECONDS = 30 * 60
const FINAL = ["passed", "failed"]
const STATES = ["created", "db_checking", "awaiting_identity", "needs_doc", "doc_pending", "held", "passed", "failed"]
const LOADER_FILE = /^(agecheck(-gate)?-\d+\.\d+\.\d+(\.debug)?\.js|agecheck\.mjs|manifest\.json)$/
const MAX_BODY = 64 * 1024

const defaults = () => ({ allowedOrigins: [], embed: "serve", startAnswer: null })

const b64 = (buf) => Buffer.from(buf).toString("base64url")
const esc = (s) => String(s).replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`)
const originOf = (url) => {
  try {
    const u = new URL(url)
    return u.protocol === "http:" || u.protocol === "https:" ? u.origin : ""
  } catch {
    return ""
  }
}

export function createMockEngine(options = {}) {
  const key = randomBytes(32)
  const state = { config: { ...defaults(), ...pick(options) }, verifications: new Map() }
  const jsDir = options.jsDir ?? DEFAULT_JS_DIR

  function pick(o) {
    const out = {}
    if (Array.isArray(o.allowedOrigins)) out.allowedOrigins = o.allowedOrigins.map(String)
    if (["serve", "refuse", "silent"].includes(o.embed)) out.embed = o.embed
    if (o.startAnswer === null || (o.startAnswer && typeof o.startAnswer.status === "string")) out.startAnswer = o.startAnswer
    return out
  }
  const allowed = (origin) => !!origin && state.config.allowedOrigins.includes(origin)

  function mint(vid) {
    const payload = b64(JSON.stringify({ v: 1, vid, exp: Math.floor(Date.now() / 1000) + SESSION_SECONDS, jti: b64(randomBytes(16)) }))
    return `${payload}.${b64(createHmac("sha256", key).update(payload).digest())}`
  }
  /** The verification of a live session token, or null. */
  function session(token) {
    const [payload, mac, extra] = String(token || "").split(".")
    if (!payload || !mac || extra !== undefined) return null
    const want = createHmac("sha256", key).update(payload).digest()
    const got = Buffer.from(mac, "base64url")
    if (got.length !== want.length || !timingSafeEqual(got, want)) return null
    const claims = JSON.parse(Buffer.from(payload, "base64url").toString("utf8"))
    if (claims.exp < Date.now() / 1000) return null
    return state.verifications.get(claims.vid) ?? null
  }
  function create(returnUrl, status = "created") {
    const id = `acv_mock${b64(randomBytes(9))}`
    const v = { id, status: STATES.includes(status) ? status : "created", returnUrl: originOf(returnUrl) ? returnUrl : "", sessions: 0 }
    state.verifications.set(id, v)
    return v
  }

  function send(res, code, body, headers = {}) {
    const json = typeof body !== "string"
    res.writeHead(code, { "Content-Type": json ? "application/json" : "text/html; charset=utf-8", "Cache-Control": "no-store", ...headers })
    res.end(json ? JSON.stringify(body) : body)
  }
  function readBody(req) {
    return new Promise((resolve, reject) => {
      let size = 0
      const chunks = []
      req.on("data", (c) => {
        size += c.length
        if (size > MAX_BODY) reject(new Error("body too large"))
        else chunks.push(c)
      })
      req.on("end", () => resolve(Buffer.concat(chunks).toString("utf8")))
      req.on("error", reject)
    })
  }
  const json = async (req) => {
    const text = await readBody(req)
    return text ? JSON.parse(text) : {}
  }

  async function control(req, res, route, base) {
    const cors = { "Access-Control-Allow-Origin": "*" }
    if (req.method === "OPTIONS") {
      return send(res, 204, "", { ...cors, "Access-Control-Allow-Methods": "GET, POST", "Access-Control-Allow-Headers": "Content-Type" })
    }
    if (route === "health") return send(res, 200, { ok: true }, cors)
    if (route === "state" && req.method === "GET") {
      return send(res, 200, { config: state.config, verifications: [...state.verifications.values()] }, cors)
    }
    if (req.method !== "POST") return send(res, 405, { error: "method not allowed" }, cors)
    const body = await json(req)
    if (route === "reset") {
      state.config = defaults()
      state.verifications.clear()
      return send(res, 200, { ok: true }, cors)
    }
    if (route === "config") {
      state.config = { ...state.config, ...pick(body) }
      return send(res, 200, state.config, cors)
    }
    if (route === "start") {
      if (state.config.startAnswer) return send(res, 200, state.config.startAnswer, cors)
      const v = create(body.returnUrl, body.status)
      v.sessions++
      return send(res, 200, { url: `${base}/v/${mint(v.id)}`, verificationId: v.id }, cors)
    }
    if (route === "decide") {
      const v = body.verificationId ? state.verifications.get(body.verificationId) : session(body.token)
      if (!v || !STATES.includes(body.status)) return send(res, 400, { error: "unknown verification or status" }, cors)
      v.status = body.status
      return send(res, 200, v, cors)
    }
    return send(res, 404, { error: "not found" }, cors)
  }

  function status(req, res) {
    const origin = req.headers.origin
    const cors = allowed(origin) ? { "Access-Control-Allow-Origin": origin, Vary: "Origin" } : { Vary: "Origin" }
    if (req.method === "OPTIONS") {
      return send(res, 204, "", allowed(origin) ? { ...cors, "Access-Control-Allow-Methods": "GET", "Access-Control-Allow-Headers": "Authorization", "Access-Control-Max-Age": "600" } : cors)
    }
    if (req.method !== "GET") return send(res, 405, { error: "method not allowed" }, cors)
    const [scheme, token] = String(req.headers.authorization || "").split(" ")
    const v = /^bearer$/i.test(scheme || "") ? session(token) : null
    if (!v) return send(res, 401, { error: "session expired or invalid" }, cors)
    return send(res, 200, { status: v.status }, cors)
  }

  async function page(req, res, token, url) {
    const v = session(token)
    if (!v) return send(res, 404, "<!doctype html><title>Not found</title><p>This age check link has expired.</p>")
    const embed = url.searchParams.get("embed") === "1"
    if (req.method === "POST") {
      const form = new URLSearchParams(await readBody(req))
      const decision = form.get("decision")
      if (!FINAL.includes(v.status) && (decision === "pass" || decision === "fail")) v.status = decision === "pass" ? "passed" : "failed"
      return send(res, 303, "", { Location: url.pathname + url.search })
    }
    if (req.method !== "GET") return send(res, 405, "<!doctype html><p>Method not allowed</p>")
    const parentOrigin = url.searchParams.get("parent_origin") || ""
    const returnOrigin = originOf(v.returnUrl)
    const target = embed ? (allowed(parentOrigin) ? parentOrigin : allowed(returnOrigin) ? returnOrigin : "") : allowed(returnOrigin) ? returnOrigin : ""
    const ancestors = embed ? (state.config.embed === "refuse" ? "'none'" : ["'self'", ...state.config.allowedOrigins].join(" ")) : "'self'"
    const nonce = b64(randomBytes(12))
    const plain = new URL(url.href)
    plain.searchParams.delete("embed")
    plain.searchParams.delete("parent_origin")
    const final = FINAL.includes(v.status)
    const silent = state.config.embed === "silent"
    const html = `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mock age check</title>
<style>body{margin:0;font:16px/1.5 system-ui,sans-serif}main{padding:16px}button,a{font:inherit;margin:4px 0;display:block}</style>
</head><body>
<main id="hosted" data-embed="${embed ? "1" : "0"}" data-target-origin="${esc(target)}" data-verification-id="${esc(v.id)}" data-status="${esc(v.status)}" data-final="${final}" data-return-url="${esc(v.returnUrl)}" data-silent="${silent}">
<h1>${final ? (v.status === "passed" ? "Age verified" : "Age check failed") : "Mock age check"}</h1>
<p id="state">Status: ${esc(v.status)}</p>
${final ? "" : `<form method="post"><button name="decision" value="pass" id="mock-pass">Pass</button><button name="decision" value="fail" id="mock-fail">Fail</button></form>`}
${embed ? `<button type="button" data-close id="mock-close">Return to checkout</button>${final ? "" : `<a id="mock-handoff" href="${esc(plain.href)}" target="_blank" rel="opener">Continue on this device</a>`}` : ""}
</main>
<script nonce="${nonce}">
(function () {
  var main = document.getElementById("hosted");
  var d = main.dataset;
  var base = { type: "navexa-agecheck", verification_id: d.verificationId };
  if (d.embed === "1") {
    if (d.silent === "true" || !d.targetOrigin || window.parent === window) return;
    var post = function (event, extra) {
      var msg = Object.assign({ v: 2, event: event }, base, extra || {});
      window.parent.postMessage(msg, d.targetOrigin);
    };
    post("ready", d.final === "true" ? { status: d.status } : {});
    var last = -1;
    var report = function () {
      var h = Math.ceil(document.documentElement.getBoundingClientRect().height);
      if (h !== last) { last = h; post("resize", { height: h }); }
    };
    report();
    if (window.ResizeObserver) new ResizeObserver(report).observe(document.documentElement);
    if (d.final === "true") post("decided", { status: d.status });
    var close = document.getElementById("mock-close");
    if (close) close.addEventListener("click", function () { post("close_requested"); });
    var handoff = document.getElementById("mock-handoff");
    if (handoff) handoff.addEventListener("click", function (ev) {
      if (window.open(handoff.href, "navexa-agecheck-id", "popup,width=480,height=760")) ev.preventDefault();
    });
    // The frame keeps polling (a decision made in the handoff window or out of band) and shows it.
    if (d.final !== "true") {
      var token = location.pathname.split("/").pop();
      setInterval(function () {
        fetch("/hosted/v1/status", { headers: { Authorization: "Bearer " + token }, cache: "no-store" })
          .then(function (r) { return r.ok ? r.json() : null; })
          .then(function (b) { if (b && (b.status === "passed" || b.status === "failed")) location.reload(); })
          .catch(function () {});
      }, 1000);
    }
    return;
  }
  if (d.final !== "true") return;
  if (window.opener && d.targetOrigin) {
    window.opener.postMessage(Object.assign({ v: 1, status: d.status }, base), d.targetOrigin);
  } else if (!window.opener && d.returnUrl) {
    window.location.replace(d.returnUrl);
  }
})();
</script>
</body></html>`
    return send(res, 200, html, {
      "Content-Security-Policy": `default-src 'none'; style-src 'unsafe-inline'; script-src 'nonce-${nonce}'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors ${ancestors}`,
      "Referrer-Policy": "no-referrer",
      "X-Content-Type-Options": "nosniff",
    })
  }

  function js(req, res, name) {
    const headers = { "Access-Control-Allow-Origin": "*", "Cross-Origin-Resource-Policy": "cross-origin", "X-Content-Type-Options": "nosniff" }
    if (!LOADER_FILE.test(name)) return send(res, 404, { error: "not found" }, headers)
    let body
    try {
      body = readFileSync(path.join(jsDir, name))
    } catch {
      return send(res, 404, { error: "not found" }, headers)
    }
    res.writeHead(200, { ...headers, "Content-Type": name.endsWith(".json") ? "application/json" : "text/javascript; charset=utf-8", "Cache-Control": "no-store" })
    res.end(req.method === "HEAD" ? undefined : body)
  }

  /** Handles req when its path is the mock's; false otherwise (for mounting inside another server). */
  function handle(req, res) {
    const base = `http://${req.headers.host}`
    const url = new URL(req.url, base)
    const p = url.pathname
    let work = null
    if (p.startsWith("/__mock/")) work = control(req, res, p.slice(8), base)
    else if (p === "/hosted/v1/status") work = Promise.resolve(status(req, res))
    else if (/^\/v\/[^/]+$/.test(p)) work = page(req, res, decodeURIComponent(p.slice(3)), url)
    else if (/^\/js\/[^/]+$/.test(p) && (req.method === "GET" || req.method === "HEAD")) work = Promise.resolve(js(req, res, p.slice(4)))
    if (!work) return false
    work.catch((err) => {
      if (!res.headersSent) send(res, 400, { error: String(err?.message ?? err) })
      else res.end()
    })
    return true
  }

  return {
    handle,
    state,
    reset() {
      state.config = defaults()
      state.verifications.clear()
    },
    /** Creates a verification and a hosted session directly: {url, verificationId} on origin. */
    start(origin, returnUrl = "") {
      const v = create(returnUrl)
      v.sessions++
      return { url: `${origin}/v/${mint(v.id)}`, verificationId: v.id }
    },
  }
}

/** Starts the mock on host:port (0 = any free port): {origin, engine, server, close()}. */
export function startMockEngine({ port = 0, host = "127.0.0.1", ...options } = {}) {
  const engine = createMockEngine(options)
  const server = http.createServer((req, res) => {
    if (!engine.handle(req, res)) {
      res.writeHead(404, { "Content-Type": "application/json" })
      res.end(JSON.stringify({ error: "not found" }))
    }
  })
  return new Promise((resolve, reject) => {
    server.once("error", reject)
    server.listen(port, host, () => {
      const addr = server.address()
      const name = addr.family === "IPv6" ? `[${addr.address}]` : addr.address
      resolve({ origin: `http://${name}:${addr.port}`, engine, server, close: () => new Promise((done) => server.close(() => done())) })
    })
  })
}

const invoked = process.argv[1] && realpathSync(process.argv[1]) === fileURLToPath(import.meta.url)
if (invoked) {
  const args = process.argv.slice(2)
  const opts = { port: Number(process.env.MOCK_AGECHECK_PORT || 8787), host: process.env.MOCK_AGECHECK_HOST || "127.0.0.1", allowedOrigins: [] }
  for (let i = 0; i < args.length; i++) {
    if (args[i] === "--port") opts.port = Number(args[++i])
    else if (args[i] === "--host") opts.host = args[++i]
    else if (args[i] === "--allowed-origin") opts.allowedOrigins.push(args[++i])
    else if (args[i] === "--js-dir") opts.jsDir = path.resolve(args[++i])
    else {
      console.error(`unknown argument ${args[i]}`)
      process.exit(2)
    }
  }
  const { origin } = await startMockEngine(opts)
  console.log(`mock AgeCheck engine on ${origin} (allowed origins: ${opts.allowedOrigins.join(", ") || "none"})`)
}
