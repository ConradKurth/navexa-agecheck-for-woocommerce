#!/usr/bin/env node
// Mock AgeCheck engine for plugin tests (wp-env integration and Playwright E2E).
//
// Speaks Connect unary JSON the way the engine does:
//   POST /agecheck.v1.VerificationService/<Rpc>, Authorization: Bearer txk_...
// Success is 200 with a proto3 JSON body (zero values omitted); errors are
// {"code","message"} with Connect's HTTP status; an RPC the engine does not
// serve is connect-go's plain-text 404. Zero dependencies (Node 20+).
//
// Follows the tax repo's proto/agecheck/v1/verification.proto at origin/main
// (E3, E9 in tax v0.0.163; E4a, E10a in tax v0.0.164; E5 data_then_doc and
// PASS_CLASS_DATA_VERIFIED in tax v0.0.167; AWAITING_IDENTITY is E4b's, served
// only with config awaitingIdentity: "served"; E12a per-store reuse and E8c
// verification links at tax d6dead63). Served RPCs:
//   GetIntegrationInfo {livemode, scopes, originAllowed, minimumAge, displayName, organizationId}
//   CreateVerification: identity optional; returnUrl (5), documentNameCheck (6),
//     intendedShipTo (7) with the proto's rules (order-bound is always REQUIRED
//     and needs identity.name; NONE needs intendedShipTo; unset is REQUIRED
//     with a name and NONE without); a sent name that trims to nothing is
//     invalid_argument, as the engine's Identity.ValidateFor; US-only
//     ship-to (E3); customerRef (8): 1..255 characters, checked whatever
//     reuse says
//   GetVerification, AttachOrder (ship_to_changed against intendedShipTo),
//   GetClearance, ReportShipment, ListVerifications, CreateHostedSession
//   SendVerificationLink (E8c), in the engine's order: config links "off"
//     (the default, AGECHECK_LINKS off in production) is failed_precondition
//     "agecheck: verification links are not enabled"; then not_found; then a
//     verification not waiting on the shopper (needs_doc, doc_pending,
//     awaiting_identity) or created without the ID name check; no org
//     displayName; no identity.email (each failed_precondition with the
//     engine's own text); 5 links per verification in 24 hours
//     (resource_exhausted); a returnUrl off the allowed origins
//     (invalid_argument). A send supersedes the verification's earlier
//     links; emailQueued is true in live mode and, in test mode, only for an
//     email in org.confirmedRecipients. links "absent" answers the plain 404
//     of an engine without the RPC. A decided verification's links are
//     revoked (decided).
//   RevokeVerificationLinks (E8c): any status, and whatever config links
//     says (AGECHECK_LINKS gates sending only); {revokedCount} of the links
//     still open, revoked (merchant); links "absent" answers the plain 404
//     of an engine without E8c
// Per-store reuse (E12a; config reuse "on", default "off" as in production):
//   a create with a customerRef under a policy other than default passes at
//   once as PASS_CLASS_REUSED on the newest earlier passed verification of
//   the same ref and mode whose class is data_verified, doc_only or
//   db_verified, decided within org.reuseWindowDays (30), and, for
//   data_verified, bound to the same ship-to. Underage blocks are not
//   modelled.
// Mutating RPCs replay their answer per idempotency key (a different request
// under the same key is invalid_argument), like the engine.
// A new verification is decided by the org's pass policy (config org.passPolicy):
//   data_then_doc (default; E5): the data match runs only for an identity with
//     a name and a residential address, a ship-to (or intended ship-to) that
//     is a US address equal to it (lines folded, ZIP+4 read as its ZIP; city
//     and region not compared), a DOB, and data consent (config dataConsent
//     "off", or the hosted page's consent step). Eligible without DOB or
//     consent: AWAITING_IDENTITY with a hosted link when config
//     awaitingIdentity is "served" (E4b), else NEEDS_DOC as on main. Anything
//     else NEEDS_DOC. The test-mode data match answers by the name's last word:
//     Match passes as data_verified; anything else needs a document. Never held.
//   no_gov_db (ID only): NEEDS_DOC, never a data match.
//   default (legacy): the identity must carry name, dob and residential
//     address (invalid_argument naming the first missing); Match passes as
//     db_verified, Error or Slow holds, anything else NEEDS_DOC.
// A sent DOB under the org's minimum age fails at once under every policy.
// Config createOutcome other than needs_doc overrides the policy (fault
// injection). Then, for a NEEDS_DOC answer: identity.name ending in Docpass
// passes (doc_only), Docdecline or Docunderage fails, Dataunattested passes as
// db_unattested. GET /__mock/verifications reports each verification's
// dataMatch route (run, awaiting, ineligible, or none) and never the identity.
//
// Hosted surface (E9):
//   OPTIONS /hosted/v1/status   preflight: 204 for any http(s) Origin
//   GET     /hosted/v1/status   Authorization: Bearer <session token> -> {status}; CORS only for allowed origins
//   GET     /v/<token>          a stand-in hosted page with Pass and Fail buttons (sends COOP same-origin); for
//                               AWAITING_IDENTITY, E4b's consent + DOB step (dob, consent=yes) and "Use my ID instead"
//   POST    /v/<token>          decision=pass|fail: decides, then 303 to the signed return URL;
//                               decision=identity|use_id on AWAITING_IDENTITY: the data match or the ID check
//   GET     /v/<token>/status   the page's own poll (no CORS)
//
// Control endpoints for tests (never part of the engine):
//   GET  /__mock/health    {ok: true}
//   POST /__mock/reset     restore the default state (any body)
//   POST /__mock/config    merge {org? (passPolicy: data_then_doc|no_gov_db|default, displayName, reuseWindowDays,
//                          confirmedRecipients, allowedOrigins), integrationInfo?: "served"|"absent",
//                          links?: "on"|"off"|"absent" ("served" = "on"), reuse?: "on"|"off",
//                          awaitingIdentity?: "served"|"absent", dataConsent?: "required"|"off",
//                          keys?, createOutcome?, hostedBase?}
//   POST /__mock/faults    queue {rpc?: "*"|Rpc, status?, code?, message?, retryAfter?, delayMs?, times?}
//   POST /__mock/verifications  update {id, status?, passClass?, consumed?}: decide a verification as the shopper would
//   POST /__mock/decide    {id, status, passClass?}: the same, status required
//   GET  /__mock/verifications  {verifications (no identity, no email), shipments, links (with returnUrl, revokedReason)}
//   GET  /__mock/requests  recorded calls: {rpc, key (fixture name or null), headers, body}; never the key itself
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

/** Fixture keys: {name: {key, livemode, scopes, organizationId?}}. Shared with the PHP and E2E suites. */
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

/** Freely associated states: refused as the country, the region or by ZIP (E3). */
const FREELY_ASSOCIATED = ["FM", "MH", "PW"]
const FREELY_ASSOCIATED_ZIPS = ["96939", "96940", "96941", "96942", "96943", "96944", "96960", "96970"]

/** Hosted session lifetime, as the engine's (30 minutes). */
const HOSTED_TTL_SECONDS = 30 * 60

/** Signs mock hosted session tokens; random per process. */
const TOKEN_SECRET = randomBytes(32)

/** VerificationStatus (proto) by the mock's short name. */
const STATUS = {
  created: "VERIFICATION_STATUS_CREATED",
  db_checking: "VERIFICATION_STATUS_DB_CHECKING",
  passed: "VERIFICATION_STATUS_PASSED",
  needs_doc: "VERIFICATION_STATUS_NEEDS_DOC",
  doc_pending: "VERIFICATION_STATUS_DOC_PENDING",
  held: "VERIFICATION_STATUS_HELD",
  failed: "VERIFICATION_STATUS_FAILED",
  awaiting_identity: "VERIFICATION_STATUS_AWAITING_IDENTITY",
}

/** PassClass (proto) by the mock's short name. */
const PASS_CLASS = {
  db_verified: "PASS_CLASS_DB_VERIFIED",
  db_unattested: "PASS_CLASS_DB_UNATTESTED",
  doc_only: "PASS_CLASS_DOC_ONLY",
  merchant_override: "PASS_CLASS_MERCHANT_OVERRIDE",
  data_verified: "PASS_CLASS_DATA_VERIFIED",
  reused: "PASS_CLASS_REUSED",
}

/** Pass classes a reuse may rest on (domain.ReuseSourceClasses). */
const REUSE_SOURCE_CLASSES = ["data_verified", "doc_only", "db_verified"]

/** Verification link lifetime and send budget (domain.LinkTTL, MaxLinkSendsPerDay, LinkSendWindow). */
const LINK_TTL_MS = 7 * 86400e3
const MAX_LINK_SENDS = 5
const LINK_SEND_WINDOW_MS = 86400e3

/** Pass policies the mock models (org.passPolicy). */
const PASS_POLICIES = ["data_then_doc", "no_gov_db", "default"]

/** States the hosted page (and CreateHostedSession) serves. */
const HOSTED_STATES = ["needs_doc", "doc_pending", "awaiting_identity"]

const NAME_CHECKS = ["DOCUMENT_NAME_CHECK_UNSPECIFIED", "DOCUMENT_NAME_CHECK_REQUIRED", "DOCUMENT_NAME_CHECK_NONE"]

function defaultState() {
  return {
    org: {
      id: "org_mock000001",
      minimumAge: 21,
      displayName: "Mock Store",
      // The pass policy new verifications run under (PASS_POLICIES).
      passPolicy: "data_then_doc",
      // wp-env's development and tests sites.
      allowedOrigins: ["http://localhost:8888", "http://localhost:8889"],
      // settings.reuse_window_days (E12a), read only while reuse is "on".
      reuseWindowDays: 30,
      // Confirmed notification recipients: test mode emails a link only to these.
      confirmedRecipients: [],
    },
    integrationInfo: "served",
    // AGECHECK_LINKS: "on", "off" (production today) or "absent" (an engine without the RPC).
    links: "off",
    // AGECHECK_REUSE: "on" or "off" (production today).
    reuse: "off",
    // E4b's AWAITING_IDENTITY: "served" sends a data-eligible verification
    // without DOB or consent there; "absent" (main today) sends it to NEEDS_DOC.
    awaitingIdentity: "absent",
    // AGECHECK_DATA_CONSENT: "required" (default) or "off".
    dataConsent: "required",
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
    shipments: [],
    linkRows: [],
    // identity.email per verification id (lower case), never reported.
    emails: new Map(),
    nextId: 1,
  }
}

/** A short status or pass class name from either form ("passed" or "VERIFICATION_STATUS_PASSED"). */
function shortName(value, prefix) {
  const text = String(value ?? "")
  return (text.startsWith(prefix) ? text.slice(prefix.length) : text).toLowerCase()
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

/** The engine's ship-to comparison form (Address.ShipToCanonical, without the length prefixes): folded lines, postal code without whitespace, country; city and region left out. */
function shipToKey(a) {
  if (!a || typeof a !== "object") return null
  const fold = (v) => String(v ?? "").toLowerCase().split(/\s+/).filter(Boolean).join(" ")
  return [fold(a.line1), fold(a.line2), String(a.postalCode ?? "").replace(/\s+/g, "").toUpperCase(), String(a.country ?? "").trim().toUpperCase()].join("\n")
}

/** The data match's address comparison (domain.sameResidence): shipToKey with a leading ZIP read as its five digits. */
function residenceKey(a) {
  const key = shipToKey(a)
  if (key === null) return null
  const parts = key.split("\n")
  if (/^[0-9]{5}/.test(parts[2])) parts[2] = parts[2].slice(0, 5)
  return parts.join("\n")
}

/**
 * domain.DataMatchEligible: "ineligible" unless the identity has a name and a
 * residential address, a ship-to is known, both are US addresses and they are
 * one residence; then "awaiting" without a DOB or consent, else "run".
 */
export function dataMatchRoute(identity, shipTo, consentOK) {
  const name = typeof identity?.name === "string" ? identity.name.trim() : ""
  const home = identity?.residentialAddress
  if (name === "" || !home || !shipTo) return "ineligible"
  if (addressProblem(home, "a") || usOnlyProblem(home, "a") || addressProblem(shipTo, "s") || usOnlyProblem(shipTo, "s")) return "ineligible"
  if (residenceKey(home) !== residenceKey(shipTo)) return "ineligible"
  return typeof identity.dob === "string" && identity.dob !== "" && consentOK ? "run" : "awaiting"
}

/** The test-mode data match's answer, by the name's last word: "match" or "nomatch". */
function dataAnswer(name) {
  const last = String(name ?? "").trim().split(/\s+/).pop() ?? ""
  return last.toLowerCase() === "match" ? "match" : last.toLowerCase() === "error" || last.toLowerCase() === "slow" ? "error" : "nomatch"
}

/** Whether a YYYY-MM-DD date of birth is under minimumAge today (UTC). */
function underAge(dob, minimumAge) {
  const m = /^([0-9]{4})-([0-9]{2})-([0-9]{2})$/.exec(String(dob ?? ""))
  if (!m) return false
  const now = new Date()
  let age = now.getUTCFullYear() - Number(m[1])
  if (now.getUTCMonth() + 1 < Number(m[2]) || (now.getUTCMonth() + 1 === Number(m[2]) && now.getUTCDate() < Number(m[3]))) age--
  return age < minimumAge
}

/** An Address message's first problem (Address rules), or null. */
function addressProblem(a, field) {
  if (!a || typeof a !== "object") return `${field}: is required`
  for (const name of ["line1", "city", "region", "postalCode"]) {
    if (typeof a[name] !== "string" || a[name].trim() === "") return `${field}.${name}: is required`
    if ([...a[name]].length > 256) return `${field}.${name}: must be at most 256 characters`
  }
  if (a.line2 !== undefined && (typeof a.line2 !== "string" || [...a.line2].length > 256)) return `${field}.line2: must be at most 256 characters`
  if (typeof a.country !== "string" || !/^[A-Za-z]{2}$/.test(a.country)) return `${field}.country: must be an ISO 3166-1 alpha-2 code`
  return null
}

/** The E3 US-only refusal for a ship-to (or intended ship-to), or null. */
function usOnlyProblem(a, field) {
  const country = a.country.toUpperCase()
  if (!ACCEPTED_COUNTRIES.includes(country)) return `${field}.country: AgeCheck verifies US addresses only`
  if (FREELY_ASSOCIATED.includes(String(a.region).trim().toUpperCase())) return `${field}.region: AgeCheck verifies US addresses only`
  if (FREELY_ASSOCIATED_ZIPS.includes(String(a.postalCode).replace(/\s+/g, "").slice(0, 5))) return `${field}.postal_code: AgeCheck verifies US addresses only`
  return null
}

/** A ship-to's first problem, US-only included, or null. */
function shipToProblem(a, field) {
  return addressProblem(a, field) ?? usOnlyProblem(a, field)
}

/** Whether returnUrl is an absolute https URL (http for localhost) on one of the org's allowed origins. */
function returnUrlAllowed(state, returnUrl) {
  if (typeof returnUrl !== "string" || returnUrl.length > 2048) return false
  try {
    const url = new URL(returnUrl)
    const local = url.hostname === "localhost" || url.hostname === "127.0.0.1"
    if (url.protocol !== "https:" && !(url.protocol === "http:" && local)) return false
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
    status: STATUS[v.status],
    passClass: v.status === "passed" ? PASS_CLASS[v.passClass] : undefined,
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

/**
 * Runs a mutating RPC under its idempotency key: a retry with the same request
 * replays (replay(stored) when given, else the stored answer); the same key
 * with another request is invalid_argument. Validation refusals are not stored.
 */
function idempotent(state, key, request, rpc, fn, replay) {
  const ikey = request.idempotencyKey
  if (typeof ikey !== "string" || ikey.length < 1 || ikey.length > 255) return ["invalid_argument", "idempotency_key: must be 1 to 255 characters"]
  const scope = `${key.livemode ? "live" : "test"}:${rpc}:${ikey}`
  const { idempotencyKey: _ignored, ...rest } = request
  const fingerprint = JSON.stringify(rest)
  const seen = state.idempotency.get(scope)
  if (seen) {
    if (seen.fingerprint !== fingerprint) return ["invalid_argument", "idempotency_key was used with a different request"]
    return replay ? replay(seen.result) : seen.result
  }
  const result = fn()
  if (result[0] !== "invalid_argument") state.idempotency.set(scope, { fingerprint, result })
  return result
}

function findVerification(state, key, id) {
  const v = typeof id === "string" ? state.verifications.get(id) : undefined
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

/** CreateVerification's validation, in the engine's order; the first problem or null. */
function createProblem(state, key, request) {
  const hasOrder = typeof request.orderExternalId === "string" && request.orderExternalId !== ""
  if (hasOrder !== (request.shipTo != null)) return "ship_to is required with order_external_id, and allowed only with it"
  if (hasOrder && request.intendedShipTo != null) return "intended_ship_to is allowed only without order_external_id"
  for (const [field, address] of [["ship_to", request.shipTo], ["intended_ship_to", request.intendedShipTo]]) {
    if (address == null) continue
    const problem = shipToProblem(address, field)
    if (problem) return problem
  }
  const identity = request.identity
  if (identity != null && (typeof identity !== "object" || Array.isArray(identity))) return "identity: must be a message"
  const name = typeof identity?.name === "string" ? identity.name : ""
  if (identity != null) {
    if (identity.name != null && (typeof identity.name !== "string" || [...identity.name].length > 256)) return "identity.name: must be at most 256 characters"
    // domain.Identity.ValidateFor: a name that is sent counts as sent before it is trimmed, then must not trim to nothing.
    if (typeof identity.name === "string" && identity.name !== "" && identity.name.trim() === "") return "identity.name: is required"
    if (identity.dob != null && !/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/.test(identity.dob)) return "identity.dob: must be YYYY-MM-DD"
    if (identity.residentialAddress != null) {
      const problem = addressProblem(identity.residentialAddress, "identity.residential_address")
      if (problem) return problem
    }
    if (identity.email != null && (typeof identity.email !== "string" || identity.email.length > 256 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(identity.email))) return "identity.email: must be an email address"
  }
  const sent = request.documentNameCheck ?? "DOCUMENT_NAME_CHECK_UNSPECIFIED"
  if (!NAME_CHECKS.includes(sent)) return "document_name_check: must be a defined value"
  if (hasOrder) {
    if (sent === "DOCUMENT_NAME_CHECK_NONE") return "document_name_check: NONE is not allowed with order_external_id"
    if (name === "") return "identity.name: is required with order_external_id"
  } else {
    const effective = sent !== "DOCUMENT_NAME_CHECK_UNSPECIFIED" ? sent : name !== "" ? "DOCUMENT_NAME_CHECK_REQUIRED" : "DOCUMENT_NAME_CHECK_NONE"
    if (effective === "DOCUMENT_NAME_CHECK_REQUIRED" && name === "") return "identity.name: is required with document_name_check REQUIRED"
    if (effective === "DOCUMENT_NAME_CHECK_NONE" && request.intendedShipTo == null) return "intended_ship_to: is required with document_name_check NONE"
  }
  if (request.returnUrl != null && !returnUrlAllowed(state, request.returnUrl)) return "return_url: must be an absolute URL on one of the organization's allowed origins"
  if (request.customerRef != null && (typeof request.customerRef !== "string" || request.customerRef.trim() === "" || [...request.customerRef].length > 255)) {
    return "customer_ref: must be 1 to 255 characters of valid UTF-8"
  }
  if (state.org.passPolicy === "default") {
    // The legacy policy runs the database check on every create: it needs the whole identity.
    if (name.trim() === "") return "identity.name: is required"
    if (typeof identity?.dob !== "string" || identity.dob === "") return "identity.dob: is required"
    if (identity?.residentialAddress == null) return "identity.residential_address: is required"
  }
  return null
}

/**
 * What a new verification's pass policy decides at creation (before the
 * test-mode document magic names): [status, passClass, dataMatch route].
 */
function decideByPolicy(state, identity, shipTo) {
  if (typeof identity.dob === "string" && identity.dob !== "" && underAge(identity.dob, state.org.minimumAge)) return ["failed", undefined, "none"]
  switch (state.org.passPolicy) {
    case "no_gov_db":
      return ["needs_doc", undefined, "none"]
    case "default": {
      const answer = dataAnswer(identity.name)
      if (answer === "match") return ["passed", "db_verified", "none"]
      return [answer === "error" ? "held" : "needs_doc", undefined, "none"]
    }
    default: {
      const route = dataMatchRoute(identity, shipTo, state.dataConsent === "off")
      if (route === "run") return dataAnswer(identity.name) === "match" ? ["passed", "data_verified", route] : ["needs_doc", undefined, route]
      if (route === "awaiting" && state.awaitingIdentity === "served") return ["awaiting_identity", undefined, route]
      return ["needs_doc", undefined, route]
    }
  }
}

/** The newest verification a create with customerRef may pass on (E12a), or null. */
function reuseSource(state, key, customerRef, shipTo) {
  if (state.reuse !== "on" || typeof customerRef !== "string" || state.org.passPolicy === "default") return null
  const days = Number(state.org.reuseWindowDays ?? 0)
  if (!(days > 0)) return null
  const since = Date.now() - days * 86400e3
  const target = shipTo ? shipToKey(shipTo) : null
  const sources = [...state.verifications.values()].filter(
    (v) =>
      v.livemode === key.livemode &&
      v.customerRef === customerRef &&
      v.status === "passed" &&
      REUSE_SOURCE_CLASSES.includes(v.passClass) &&
      Date.parse(v.decidedAt ?? "") >= since &&
      (v.passClass !== "data_verified" || (target !== null && (v.shipToKey ?? v.intendedShipToKey) === target)),
  )
  return sources.length ? sources[sources.length - 1] : null
}

/** The document name check a verification was created with (domain.ResolveNameCheck). */
function effectiveNameCheck(request) {
  const sent = request.documentNameCheck ?? "DOCUMENT_NAME_CHECK_UNSPECIFIED"
  if (typeof request.orderExternalId === "string" && request.orderExternalId !== "") return "DOCUMENT_NAME_CHECK_REQUIRED"
  if (sent !== "DOCUMENT_NAME_CHECK_UNSPECIFIED") return sent
  return typeof request.identity?.name === "string" && request.identity.name !== "" ? "DOCUMENT_NAME_CHECK_REQUIRED" : "DOCUMENT_NAME_CHECK_NONE"
}

/** Revokes v's links not revoked yet, with reason; answers how many were still open. */
function revokeLinks(state, v, reason) {
  let n = 0
  for (const link of state.linkRows) {
    if (link.verificationId !== v.id || link.revokedAt) continue
    link.revokedAt = new Date().toISOString()
    link.revokedReason = reason
    if (Date.parse(link.expiresAt) > Date.now()) n++
  }
  return n
}

/** RPC handlers: (state, keyEntry, request, ctx) => [code?, responseOrMessage], or null when not served. */
const RPCS = {
  CreateVerification(state, key, request, ctx) {
    const answer = (v) => [null, { verification: verificationMessage(v, v.status === "needs_doc" || v.status === "awaiting_identity" ? hostedUrlFor(state, v, v.returnUrl, ctx.host) : undefined) }]
    return (
      requireScope(key, "agecheck:verify") ??
      idempotent(
        state,
        key,
        request,
        "CreateVerification",
        () => {
          const problem = createProblem(state, key, request)
          if (problem) return ["invalid_argument", problem]
          const hasOrder = typeof request.orderExternalId === "string" && request.orderExternalId !== ""
          if (hasOrder && boundTo(state, key, request.orderExternalId)) return ["already_exists", "agecheck: order is already bound to a verification"]
          const identity = request.identity ?? {}
          const name = String(identity.name ?? "")
          const shipTo = request.shipTo ?? request.intendedShipTo ?? null
          let status = state.createOutcome
          let passClass = status === "passed" ? "doc_only" : undefined
          let dataMatch = "none"
          // E12a: tryReuse runs before any check; a DOB under age still fails first.
          const minor = typeof identity.dob === "string" && identity.dob !== "" && underAge(identity.dob, state.org.minimumAge)
          const source = status === "needs_doc" && !minor ? reuseSource(state, key, request.customerRef, shipTo) : null
          if (source) [status, passClass] = ["passed", "reused"]
          else if (status === "needs_doc") [status, passClass, dataMatch] = decideByPolicy(state, identity, shipTo)
          if (status === "needs_doc" && !source) {
            if (/docpass$/i.test(name)) [status, passClass] = ["passed", "doc_only"]
            else if (/doc(decline|underage)$/i.test(name)) [status, passClass] = ["failed", undefined]
            else if (/dataunattested$/i.test(name)) [status, passClass] = ["passed", "db_unattested"]
          }
          const now = new Date().toISOString()
          const v = {
            id: `acv_mock${String(state.nextId++).padStart(6, "0")}`,
            status,
            passClass,
            livemode: key.livemode,
            orderExternalId: hasOrder ? request.orderExternalId : "",
            shipToKey: hasOrder ? shipToKey(request.shipTo) : null,
            intendedShipToKey: request.intendedShipTo ? shipToKey(request.intendedShipTo) : null,
            hasEmail: typeof identity.email === "string" && identity.email !== "",
            customerRef: typeof request.customerRef === "string" ? request.customerRef : undefined,
            reusedFrom: source ? source.id : undefined,
            nameCheck: effectiveNameCheck(request),
            dataMatch,
            // The test-mode data match's answer for a later hosted DOB step; never the name itself.
            dataAnswer: dataMatch === "awaiting" ? dataAnswer(name) : undefined,
            documentNameCheck: request.documentNameCheck ?? "DOCUMENT_NAME_CHECK_UNSPECIFIED",
            returnUrl: request.returnUrl ?? "",
            consumed: false,
            createdAt: now,
            decidedAt: status === "passed" || status === "failed" ? now : undefined,
          }
          state.verifications.set(v.id, v)
          if (v.hasEmail) state.emails.set(v.id, identity.email.toLowerCase())
          return answer(v)
        },
        // A replay answers the verification as it is now, with a freshly minted link while it waits on the shopper.
        (stored) => (stored[0] ? stored : answer(state.verifications.get(stored[1].verification.id))),
      )
    )
  },

  GetVerification(state, key, request) {
    const denied = requireScope(key, "agecheck:verify")
    if (denied) return denied
    if (typeof request.id !== "string" || !request.id.startsWith("acv_") || request.id.length > 64) return ["invalid_argument", "id: must start with acv_"]
    const v = findVerification(state, key, request.id)
    return v ? [null, { verification: verificationMessage(v) }] : ["not_found", "agecheck: verification not found"]
  },

  AttachOrder(state, key, request) {
    return (
      requireScope(key, "agecheck:verify") ??
      idempotent(state, key, request, "AttachOrder", () => {
        const problem = shipToProblem(request.shipTo, "ship_to")
        if (problem) return ["invalid_argument", problem]
        if (typeof request.orderExternalId !== "string" || request.orderExternalId === "") return ["invalid_argument", "order_external_id: is required"]
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
    )
  },

  GetClearance(state, key, request) {
    return (
      requireScope(key, "agecheck:clearance") ??
      idempotent(state, key, request, "GetClearance", () => {
        // E3 refuses non-US addresses for new bindings only; clearance reads any.
        const problem = addressProblem(request.shipTo, "ship_to")
        if (problem) return ["invalid_argument", problem]
        const answer = (decision, reason, v) => [null, omitZero({ decision: `CLEARANCE_DECISION_${decision}`, verificationId: v?.id, reason, livemode: key.livemode })]
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
    )
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

  CreateHostedSession(state, key, request, ctx) {
    const denied = requireScope(key, "agecheck:verify")
    if (denied) return denied
    const v = findVerification(state, key, request.verificationId)
    if (!v) return ["not_found", "agecheck: verification not found"]
    if (!HOSTED_STATES.includes(v.status)) return ["failed_precondition", "agecheck: a hosted session needs a verification in needs_doc or doc_pending"]
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

  SendVerificationLink(state, key, request) {
    if (state.links === "absent") return null
    return (
      requireScope(key, "agecheck:verify") ??
      idempotent(state, key, request, "SendVerificationLink", () => {
        if (state.links !== "on") return ["failed_precondition", "agecheck: verification links are not enabled"]
        const v = findVerification(state, key, request.verificationId)
        if (!v) return ["not_found", "agecheck: verification not found"]
        if (!HOSTED_STATES.includes(v.status) || v.nameCheck !== "DOCUMENT_NAME_CHECK_REQUIRED") {
          return ["failed_precondition", "agecheck: a verification link needs a verification waiting on the shopper, with the document name check"]
        }
        if (!String(state.org.displayName ?? "").trim()) return ["failed_precondition", "agecheck: set the store's display name in the AgeCheck settings before sending verification links"]
        const email = state.emails.get(v.id)
        if (!email) return ["failed_precondition", "agecheck: the verification has no email to send the link to (identity.email)"]
        const recent = state.linkRows.filter((l) => l.verificationId === v.id && Date.parse(l.createdAt) > Date.now() - LINK_SEND_WINDOW_MS)
        if (recent.length >= MAX_LINK_SENDS) return ["resource_exhausted", "agecheck: too many verification links sent for this verification in the last 24 hours; retry later"]
        const returnUrl = request.returnUrl ?? ""
        if (returnUrl !== "" && !returnUrlAllowed(state, returnUrl)) return ["invalid_argument", "return_url: must be an absolute URL on one of the organization's allowed origins"]
        revokeLinks(state, v, "superseded")
        const emailQueued = key.livemode || (state.org.confirmedRecipients ?? []).some((r) => String(r).toLowerCase() === email)
        const now = Date.now()
        const link = {
          linkId: `acl_mock${String(state.linkRows.length + 1).padStart(6, "0")}`,
          verificationId: v.id,
          createdAt: new Date(now).toISOString(),
          expiresAt: new Date(now + LINK_TTL_MS).toISOString(),
          returnUrl,
          emailQueued,
        }
        state.linkRows.push(link)
        return [null, omitZero({ linkId: link.linkId, expiresAt: link.expiresAt, emailQueued })]
      })
    )
  },

  RevokeVerificationLinks(state, key, request) {
    if (state.links === "absent") return null
    return (
      requireScope(key, "agecheck:verify") ??
      idempotent(state, key, request, "RevokeVerificationLinks", () => {
        const v = findVerification(state, key, request.verificationId)
        if (!v) return ["not_found", "agecheck: verification not found"]
        return [null, omitZero({ revokedCount: revokeLinks(state, v, "merchant") })]
      })
    )
  },

  GetIntegrationInfo(state, key, request) {
    if (state.integrationInfo !== "served") return null
    const denied = requireScope(key, "agecheck:verify")
    if (denied) return denied
    const origin = typeof request.origin === "string" ? request.origin.toLowerCase() : ""
    return [
      null,
      omitZero({
        livemode: key.livemode,
        scopes: [...key.scopes].sort(),
        originAllowed: origin !== "" && originAllowed(state, origin),
        minimumAge: state.org.minimumAge,
        displayName: state.org.displayName,
        organizationId: key.organizationId ?? state.org.id,
      }),
    ]
  },
}

/** Moves a verification as the shopper (or a reviewer) would; a decision revokes its links (E8a). */
function decide(state, v, status, passClass) {
  v.status = status
  v.passClass = status === "passed" ? (passClass ? shortName(passClass, "PASS_CLASS_") : v.passClass || "doc_only") : undefined
  v.decidedAt = status === "passed" || status === "failed" ? new Date().toISOString() : undefined
  if (v.decidedAt) revokeLinks(state, v, "decided")
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
  let problem = ""
  if (req.method === "POST") {
    const form = new URLSearchParams(await readBody(req))
    const decision = form.get("decision")
    const awaiting = v.status === "awaiting_identity"
    if (awaiting) {
      // E4b's consent + DOB step: the data match runs once; "Use my ID instead" goes to the ID check.
      if (decision === "use_id") decide(state, v, "needs_doc")
      else if (decision === "identity") {
        const dob = form.get("dob") ?? ""
        if (form.get("consent") !== "yes") problem = "Agree to the data check, or use your ID instead."
        else if (!/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/.test(dob)) problem = "Enter your date of birth."
        else if (underAge(dob, state.org.minimumAge)) decide(state, v, "failed")
        else if (v.dataAnswer === "match") decide(state, v, "passed", "data_verified")
        else decide(state, v, "needs_doc")
      }
    } else if (decision === "pass") decide(state, v, "passed", "doc_only")
    else if (decision === "fail") decide(state, v, "failed")
    if (claims.ru && (!awaiting || v.status === "passed" || v.status === "failed")) {
      res.writeHead(303, { Location: claims.ru })
      return res.end()
    }
  }
  const step =
    v.status === "awaiting_identity"
      ? `<form method="post"><p id="problem">${escapeHTML(problem)}</p>
<label>Date of birth <input type="date" name="dob" id="dob"></label>
<label><input type="checkbox" name="consent" value="yes" id="consent"> I agree to the data check</label>
<button name="decision" value="identity">Continue</button> <button name="decision" value="use_id">Use my ID instead</button></form>`
      : `<form method="post"><button name="decision" value="pass">Pass</button> <button name="decision" value="fail">Fail</button></form>`
  const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Mock AgeCheck</title></head><body>
<h1>Mock AgeCheck (TEST)</h1><p>Status: <strong id="status">${escapeHTML(v.status)}</strong></p>
${step}
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
    if (req.method === "GET" && url.pathname === "/__mock/verifications") {
      return sendJSON(res, 200, { verifications: [...state.verifications.values()], shipments: state.shipments, links: state.linkRows })
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
        if (body.org?.passPolicy !== undefined && !PASS_POLICIES.includes(body.org.passPolicy)) return sendJSON(res, 400, { error: "unknown pass policy" })
        if (body.org) state.org = { ...state.org, ...body.org }
        if (body.integrationInfo) state.integrationInfo = body.integrationInfo
        if (body.links !== undefined && !["on", "served", "off", "absent"].includes(body.links)) return sendJSON(res, 400, { error: "links: on, off or absent" })
        if (body.reuse !== undefined && !["on", "off"].includes(body.reuse)) return sendJSON(res, 400, { error: "reuse: on or off" })
        if (body.links) state.links = body.links === "served" ? "on" : body.links
        if (body.reuse) state.reuse = body.reuse
        if (body.awaitingIdentity) state.awaitingIdentity = body.awaitingIdentity
        if (body.dataConsent) state.dataConsent = body.dataConsent
        if (body.keys) state.keys = { ...state.keys, ...body.keys }
        if (body.createOutcome) {
          const outcome = shortName(body.createOutcome, "VERIFICATION_STATUS_")
          if (!STATUS[outcome]) return sendJSON(res, 400, { error: "unknown status" })
          state.createOutcome = outcome
        }
        if (typeof body.hostedBase === "string") state.hostedBase = body.hostedBase.replace(/\/+$/, "")
        return sendJSON(res, 200, { ok: true })
      case "/__mock/verifications":
      case "/__mock/decide": {
        const v = state.verifications.get(body.id)
        if (!v) return sendJSON(res, url.pathname === "/__mock/decide" ? 400 : 404, { error: "no such verification" })
        if (body.status !== undefined || url.pathname === "/__mock/decide") {
          const status = shortName(body.status, "VERIFICATION_STATUS_")
          if (!STATUS[status]) return sendJSON(res, 400, { error: "unknown status" })
          if (body.passClass !== undefined && !PASS_CLASS[shortName(body.passClass, "PASS_CLASS_")]) return sendJSON(res, 400, { error: "unknown pass class" })
          decide(state, v, status, body.passClass)
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
