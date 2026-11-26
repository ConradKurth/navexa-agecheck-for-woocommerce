#!/usr/bin/env node
// W2 pre-payment flow over real HTTP against wp-env and the mock engine:
// the Store API start/refresh extension (block and classic pages), the
// hosted page round trip, the return page, block checkout (Store API
// /checkout) and classic checkout (?wc-ajax=checkout) enforcement.
//
//   node tests/mock-agecheck/server.mjs --host 0.0.0.0 --port 8797 &
//   npm run env start          (NAVEXA_AGECHECK_API_URL -> host.docker.internal:8797)
//   npm run env run cli wp eval-file wp-content/plugins/navexa-agecheck-for-woocommerce/tests/wp-env/prepayment-setup.php
//   node tests/wp-env/prepayment.mjs --site http://localhost:8888 --mock http://localhost:8797 --product <id> --classic <page id> \
//     --exempt <exempt product id> --order-pay <order id>:<order key>
//
// Exits non-zero on the first failed group. Zero dependencies (Node 22+).

import assert from "node:assert/strict"

function arg(name, fallback) {
  const i = process.argv.indexOf(name)
  return i >= 0 ? process.argv[i + 1] : fallback
}

const SITE = arg("--site", "http://localhost:8888").replace(/\/+$/, "")
const MOCK = arg("--mock", "http://localhost:8797").replace(/\/+$/, "")
const PRODUCT = Number(arg("--product", "0"))
const CLASSIC = Number(arg("--classic", "0"))
const EXEMPT = Number(arg("--exempt", "0"))
const ORDER_PAY = arg("--order-pay", "")
const STORE = `${SITE}/wp-json/wc/store/v1`
const NS = "navexa-agecheck"

const US = {
  first_name: "Pat",
  last_name: "Doe",
  address_1: "123 Main St",
  address_2: "Apt 4",
  city: "Springfield",
  state: "IL",
  postcode: "62701",
  country: "US",
  email: "pat@example.com",
  phone: "",
}

/** A browser-ish client: a cookie jar and the Store API nonce. */
class Shopper {
  /** @param {string} [ip] X-Real-IP (the setup trusts it); a random one by default. */
  constructor(ip) {
    this.cookies = new Map()
    this.nonce = null
    this.ip = ip ?? `198.18.${Math.floor(Math.random() * 250)}.${1 + Math.floor(Math.random() * 250)}`
  }

  cookieHeader() {
    return [...this.cookies].map(([k, v]) => `${k}=${v}`).join("; ")
  }

  async fetch(url, init = {}) {
    const headers = { "X-Real-IP": this.ip, ...(init.headers ?? {}) }
    if (this.cookies.size) headers.Cookie = this.cookieHeader()
    const res = await fetch(url, { ...init, headers, redirect: init.redirect ?? "manual" })
    for (const line of res.headers.getSetCookie?.() ?? []) {
      const [pair] = line.split(";")
      const eq = pair.indexOf("=")
      const name = pair.slice(0, eq).trim()
      const value = pair.slice(eq + 1).trim()
      if (/expires=Thu, 01[- ]Jan[- ]1970/i.test(line) || value === "deleted") this.cookies.delete(name)
      else this.cookies.set(name, value)
    }
    const nonce = res.headers.get("Nonce")
    if (nonce) this.nonce = nonce
    return res
  }

  async store(method, path, body) {
    if (!this.nonce && method !== "GET") await this.fetch(`${STORE}/cart`)
    const res = await this.fetch(`${STORE}${path}`, {
      method,
      headers: { "Content-Type": "application/json", Nonce: this.nonce ?? "" },
      body: body === undefined ? undefined : JSON.stringify(body),
    })
    const text = await res.text()
    let json = null
    try {
      json = JSON.parse(text)
    } catch {
      json = text
    }
    return { status: res.status, json }
  }

  async addProduct(address = US, shipping = address, id = PRODUCT) {
    const added = await this.store("POST", "/cart/add-item", { id, quantity: 1 })
    assert.equal(added.status, 201, `add-item: ${JSON.stringify(added.json).slice(0, 300)}`)
    return this.setAddress(address, shipping)
  }

  async setAddress(billing, shipping = billing) {
    const res = await this.store("POST", "/cart/update-customer", { billing_address: billing, shipping_address: shipping })
    assert.equal(res.status, 200, `update-customer: ${JSON.stringify(res.json).slice(0, 300)}`)
    return res.json
  }

  async ageCheck(action) {
    const res = await this.store("POST", "/cart/extensions", { namespace: NS, data: { v: 1, action } })
    assert.equal(res.status, 200, `extensions ${action}: ${JSON.stringify(res.json).slice(0, 300)}`)
    return res.json.extensions[NS]
  }

  async checkout(address = US, shipping = address, extra = {}) {
    const cart = await this.store("GET", "/cart")
    const rate = cart.json.shipping_rates?.[0]?.shipping_rates?.find((r) => r.method_id === (extra.method ?? "flat_rate"))
    if (rate) await this.store("POST", "/cart/select-shipping-rate", { package_id: 0, rate_id: rate.rate_id })
    return this.store("POST", "/checkout", { billing_address: billing(address), shipping_address: shipping, payment_method: extra.payment ?? "cod" })
  }
}

const billing = (a) => ({ ...a, email: a.email || "pat@example.com" })

async function mock(path, body) {
  const res = await fetch(`${MOCK}/__mock/${path}`, { method: "POST", body: JSON.stringify(body ?? {}) })
  assert.equal(res.status, 200, `mock ${path}`)
}

async function decideOnHostedPage(hostedUrl, decision) {
  const res = await fetch(hostedUrl, {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: `decision=${decision}`,
    redirect: "manual",
  })
  assert.equal(res.status, 303, "hosted page answers 303")
  return res.headers.get("location")
}

let failures = 0
async function group(name, fn) {
  try {
    await fn()
    console.log(`ok   ${name}`)
  } catch (err) {
    failures++
    console.log(`FAIL ${name}\n     ${err?.stack?.split("\n").slice(0, 3).join("\n     ")}`)
  }
}

assert.ok(PRODUCT > 0, "--product is required")
await mock("reset")
await mock("config", { hostedBase: MOCK, org: { allowedOrigins: [SITE] } })

await group("cart data says the cart needs a check, with no AgeCheck call", async () => {
  const s = new Shopper()
  const cart = await s.addProduct()
  assert.deepEqual(cart.extensions[NS], { v: 1, required: true, status: "none" })
})

let verified
await group("block flow: start, hosted page, return page, refresh, place order", async () => {
  const s = new Shopper()
  await s.addProduct()
  const started = await s.ageCheck("start")
  assert.equal(started.status, "pending")
  assert.match(started.hosted_url, new RegExp(`^${MOCK}/v/[A-Za-z0-9_-]+\\.[A-Za-z0-9_-]+$`))
  assert.ok(!JSON.stringify(started).includes("acv_"), "no verification id reaches the browser")

  const requests = await (await fetch(`${MOCK}/__mock/requests`)).json()
  const create = requests.filter((r) => r.rpc === "CreateVerification").pop()
  assert.equal(create.key, "liveStart", "start key only")
  assert.equal(create.body.documentNameCheck, "DOCUMENT_NAME_CHECK_NONE")
  assert.equal(create.body.intendedShipTo.postalCode, "62701")
  assert.equal(create.body.returnUrl, `${SITE}/?wc-api=navexa_agecheck_return&popup=1`)
  assert.equal(create.body.identity?.dob, undefined)
  assert.deepEqual(create.body.identity, { name: "Pat Doe", residentialAddress: create.body.intendedShipTo }, "the data match pair, in the ship-to's form; no email")
  const recorded = (await (await fetch(`${MOCK}/__mock/verifications`)).json()).verifications.at(-1)
  assert.equal(recorded.dataMatch, "awaiting", "data-eligible: billing is the ship-to (E4b collects the DOB)")

  // The loader's poll: Bearer token, CORS for the store origin.
  const token = new URL(started.hosted_url).pathname.slice(3)
  const poll = await fetch(`${MOCK}/hosted/v1/status`, { headers: { Authorization: `Bearer ${token}`, Origin: SITE } })
  assert.equal(poll.headers.get("access-control-allow-origin"), SITE)
  assert.deepEqual(await poll.json(), { status: "needs_doc" })

  const back = await decideOnHostedPage(started.hosted_url, "pass")
  assert.equal(back, `${SITE}/?wc-api=navexa_agecheck_return&popup=1`)
  const page = await s.fetch(back)
  assert.equal(page.status, 200)
  const html = await page.text()
  assert.match(html, /new BroadcastChannel\("navexa-agecheck"\)/)
  assert.match(html, /You can close this window/)
  assert.match(page.headers.get("cache-control") ?? "", /no-store|no-cache/)
  const redirect = await s.fetch(`${SITE}/?wc-api=navexa_agecheck_return`)
  assert.equal(redirect.status, 302)
  assert.match(redirect.headers.get("location"), /checkout/)

  const refreshed = await s.ageCheck("refresh")
  assert.deepEqual(refreshed, { v: 1, required: true, status: "passed" })

  const placed = await s.checkout()
  assert.equal(placed.status, 200, JSON.stringify(placed.json).slice(0, 400))
  assert.ok(placed.json.order_id > 0)
  verified = s
})

/** The last verification the mock created, as it records it (never the identity). */
async function lastVerification() {
  return (await (await fetch(`${MOCK}/__mock/verifications`)).json()).verifications.at(-1)
}

/** The last CreateVerification request body. */
async function lastCreate() {
  return (await (await fetch(`${MOCK}/__mock/requests`)).json()).filter((r) => r.rpc === "CreateVerification").pop().body
}

await group("billing elsewhere than the ship-to: still verifies (ID check), no identity sent", async () => {
  const s = new Shopper()
  const elsewhere = { ...US, address_1: "9 Elm St", address_2: "" }
  await s.addProduct(elsewhere, US)
  const started = await s.ageCheck("start")
  assert.equal(started.status, "pending")
  const create = await lastCreate()
  assert.equal(create.identity, undefined, "never data-eligible: no billing name or address leaves the store")
  assert.equal(create.intendedShipTo.line1, "123 Main St")
  assert.equal(create.documentNameCheck, "DOCUMENT_NAME_CHECK_NONE")
  assert.equal((await lastVerification()).dataMatch, "ineligible")
  await decideOnHostedPage(started.hosted_url, "pass")
  assert.equal((await s.ageCheck("refresh")).status, "passed")
  const placed = await s.checkout(elsewhere, US)
  assert.equal(placed.status, 200, JSON.stringify(placed.json).slice(0, 400))
})

await group("non-US billing or no name: no identity at all (ID check)", async () => {
  const s = new Shopper()
  const abroad = { ...US, city: "Toronto", state: "ON", postcode: "M5V 2T6", country: "CA" }
  await s.addProduct(abroad, US)
  assert.equal((await s.ageCheck("start")).status, "pending")
  assert.equal((await lastCreate()).identity, undefined)
  const t = new Shopper()
  await t.addProduct({ ...US, first_name: "", last_name: "" }, US)
  assert.equal((await t.ageCheck("start")).status, "pending")
  assert.equal((await lastCreate()).identity, undefined)
})

await group("data match (E4b served): consent + DOB on the hosted page, DATA_VERIFIED admits the checkout", async () => {
  await mock("config", { awaitingIdentity: "served" })
  try {
    const s = new Shopper()
    const match = { ...US, last_name: "Match" }
    await s.addProduct(match)
    const started = await s.ageCheck("start")
    assert.equal(started.status, "pending", "AWAITING_IDENTITY gets a hosted link")
    assert.equal((await lastVerification()).status, "awaiting_identity")
    const res = await fetch(started.hosted_url, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: "decision=identity&dob=1990-01-01&consent=yes",
      redirect: "manual",
    })
    assert.equal(res.status, 303)
    assert.equal((await lastVerification()).passClass, "data_verified")
    assert.deepEqual(await s.ageCheck("refresh"), { v: 1, required: true, status: "passed" })
    const placed = await s.checkout(match)
    assert.equal(placed.status, 200, JSON.stringify(placed.json).slice(0, 400))
  } finally {
    await mock("config", { awaitingIdentity: "absent" })
  }
})

await group("block checkout without a check is refused", async () => {
  const s = new Shopper()
  await s.addProduct()
  const res = await s.checkout()
  assert.equal(res.status, 400)
  assert.equal(res.json.code, "woocommerce_rest_checkout_custom_validation_error")
  assert.match(res.json.message, /Verify your age on the checkout page/)
})

await group("a ship-to change after the pass needs a new check", async () => {
  const s = new Shopper()
  await s.addProduct()
  const started = await s.ageCheck("start")
  await decideOnHostedPage(started.hosted_url, "pass")
  assert.equal((await s.ageCheck("refresh")).status, "passed")
  const moved = { ...US, address_1: "9 Elm St" }
  const cart = await s.setAddress(moved)
  assert.equal(cart.extensions[NS].status, "none")
  assert.match(cart.extensions[NS].message, /shipping address changed/)
  const res = await s.checkout(moved)
  assert.equal(res.status, 400)
  assert.match(res.json.message, /shipping address changed/)

  // ZIP+4 of the same address is the same ship-to.
  await s.setAddress(US)
  const zip4 = { ...US, postcode: "62701-1234" }
  const ok = await s.checkout(zip4)
  assert.equal(ok.status, 200, JSON.stringify(ok.json).slice(0, 300))
})

await group("non-US ship-to is refused, territories are not", async () => {
  const s = new Shopper()
  const ca = { ...US, city: "Toronto", state: "ON", postcode: "M5V 2T6", country: "CA" }
  const cart = await s.addProduct(ca)
  assert.equal(cart.extensions[NS].required, true)
  assert.match(cart.extensions[NS].message, /only to US addresses/)
  const started = await s.ageCheck("start")
  assert.equal(started.status, "refused")
  const res = await s.checkout(ca)
  assert.equal(res.status, 400)
  assert.match(res.json.message, /only to US addresses/)

  const pr = { ...US, city: "San Juan", state: "", postcode: "00901", country: "PR" }
  await s.setAddress(pr)
  assert.equal((await s.ageCheck("start")).status, "pending", "Puerto Rico is accepted")
})

await group("local pickup skips AgeCheck", async () => {
  const s = new Shopper()
  await s.addProduct()
  const res = await s.checkout(US, US, { method: "local_pickup" })
  assert.equal(res.status, 200, JSON.stringify(res.json).slice(0, 300))
})

// Orders the groups below place are billed to their own email, so
// prepayment-orders.php tells them from the checkout orders it follows.
const REUSE = { ...US, email: "reuse@example.com" }

// Payment retries (1.0.4): the order a checkout placed is bound to the
// shopper's check before payment, and the check leaves the session. After a
// declined card WooCommerce resumes that order (classic order_awaiting_payment,
// the Store API's store_api_draft_order) and the gate admits it at its bound
// ship-to, so the cart must say passed: the checkout block refuses Place order
// otherwise, and a second, billed check would never be attached to anything.
// navexa_test_decline is the wp-env test gateway (tests/wp-env/mu-plugins).
const RETRY = { ...US, email: "retry@example.com" }
const DECLINE = "navexa_test_decline"

/** How many times the mock engine was asked for an RPC so far. */
async function rpcCount(rpc) {
  return (await (await fetch(`${MOCK}/__mock/requests`)).json()).filter((r) => r.rpc === rpc).length
}

/** The order id of the last AttachOrder call (wc:{install}:{blog}:{order}). */
async function lastAttachedOrder() {
  const attach = (await (await fetch(`${MOCK}/__mock/requests`)).json()).filter((r) => r.rpc === "AttachOrder").pop()
  return Number(attach.body.orderExternalId.split(":").pop())
}

/** A shopper who passed a check for RETRY's ship-to and whose first payment was declined. */
async function declinedAfterPass() {
  const s = new Shopper()
  await s.addProduct(RETRY)
  const started = await s.ageCheck("start")
  await decideOnHostedPage(started.hosted_url, "pass")
  assert.equal((await s.ageCheck("refresh")).status, "passed")
  const declined = await s.checkout(RETRY, RETRY, { payment: DECLINE })
  assert.equal(declined.status, 400, JSON.stringify(declined.json).slice(0, 400))
  assert.match(declined.json.message, /declined/, JSON.stringify(declined.json).slice(0, 400))
  // The order was bound (AttachOrder) before the gateway ran.
  return { s, order: await lastAttachedOrder() }
}

await group("block: a declined payment keeps the check: the cart says passed and the retry places the same order with no new check (1.0.4)", async () => {
  const { s, order } = await declinedAfterPass()
  const creates = await rpcCount("CreateVerification")
  const cart = await s.store("GET", "/cart")
  assert.deepEqual(cart.json.extensions[NS], { v: 1, required: true, status: "passed" }, "the checkout block lets Place order through")
  const placed = await s.checkout(RETRY)
  assert.equal(placed.status, 200, JSON.stringify(placed.json).slice(0, 400))
  assert.equal(placed.json.order_id, order, "WooCommerce resumed the declined order")
  assert.equal(await rpcCount("CreateVerification"), creates, "no second verification")
})

await group("block: a ship-to change after a declined payment needs a new check", async () => {
  const { s, order } = await declinedAfterPass()
  const moved = { ...RETRY, address_1: "9 Elm St" }
  const cart = await s.setAddress(moved)
  assert.equal(cart.extensions[NS].status, "none", "the bound order's check is not for this ship-to")
  const refused = await s.checkout(moved)
  assert.equal(refused.status, 400)
  assert.match(refused.json.message, /shipping address changed/)
  const started = await s.ageCheck("start")
  assert.equal(started.status, "pending", "a new check for the new ship-to")
  await decideOnHostedPage(started.hosted_url, "pass")
  assert.equal((await s.ageCheck("refresh")).status, "passed")
  const placed = await s.checkout(moved)
  assert.equal(placed.status, 200, JSON.stringify(placed.json).slice(0, 400))
  assert.notEqual(placed.json.order_id, order, "a new order, admitted by the new check")
})

await group("block: a different cart after a declined payment is a new order that needs its own check", async () => {
  const { s } = await declinedAfterPass()
  const added = await s.store("POST", "/cart/add-item", { id: PRODUCT, quantity: 1 })
  assert.equal(added.status, 201)
  assert.equal(added.json.extensions[NS].status, "none", "WooCommerce will not resume the declined order for another cart")
  const refused = await s.checkout(RETRY)
  assert.equal(refused.status, 400)
  assert.match(refused.json.message, /Verify your age on the checkout page/)
})

await group("a refused block checkout's draft is not stuck as required: local pickup next", async () => {
  // WooCommerce 11.3 saves the refused draft and reuses it on the next attempt.
  const s = new Shopper()
  await s.addProduct(REUSE)
  const refused = await s.checkout(REUSE)
  assert.equal(refused.status, 400)
  assert.match(refused.json.message, /Verify your age on the checkout page/)
  const pickup = await s.checkout(REUSE, REUSE, { method: "local_pickup" })
  assert.equal(pickup.status, 200, JSON.stringify(pickup.json).slice(0, 300))
})

await group("a refused block checkout's draft is not stuck as required: restricted item removed", async () => {
  assert.ok(EXEMPT > 0, "--exempt is required")
  const s = new Shopper()
  await s.addProduct(REUSE)
  await s.addProduct(REUSE, REUSE, EXEMPT)
  const refused = await s.checkout(REUSE)
  assert.equal(refused.status, 400)
  const cart = await s.store("GET", "/cart")
  const restricted = cart.json.items.find((item) => item.id === PRODUCT)
  const removed = await s.store("POST", "/cart/remove-item", { key: restricted.key })
  assert.equal(removed.status, 200, JSON.stringify(removed.json).slice(0, 300))
  assert.equal(removed.json.extensions[NS].required, false)
  const placed = await s.checkout(REUSE)
  assert.equal(placed.status, 200, JSON.stringify(placed.json).slice(0, 300))
})

await group("Store API order-pay takes the hold path: a passed session check is not bound to it (Q11b)", async () => {
  assert.ok(/^\d+:wc_order_/.test(ORDER_PAY), "--order-pay <id>:<key> is required")
  const [id, key] = ORDER_PAY.split(":")
  const s = new Shopper()
  await s.addProduct()
  const started = await s.ageCheck("start")
  await decideOnHostedPage(started.hosted_url, "pass")
  assert.equal((await s.ageCheck("refresh")).status, "passed", "the session holds a check for the same ship-to")
  const seen = (await (await fetch(`${MOCK}/__mock/requests`)).json()).length
  const email = "orderpay@example.com"
  const paid = await s.store("POST", `/checkout/${id}`, {
    key,
    billing_email: email,
    billing_address: { ...US, email },
    // WC 9.9 order-pay passes this straight to set_shipping_address() (null = fatal); the block checkout always sends it.
    shipping_address: { ...US },
    payment_method: "cod",
  })
  assert.equal(paid.status, 200, JSON.stringify(paid.json).slice(0, 400))
  const rpcs = (await (await fetch(`${MOCK}/__mock/requests`)).json()).slice(seen).map((r) => r.rpc)
  assert.ok(!rpcs.includes("AttachOrder"), `nothing attached to the paid order (${rpcs.join(", ")})`)
  // prepayment-orders.php follows the order: held, then an order-bound check.
})

await group("an engine error at checkout fails closed", async () => {
  const s = new Shopper()
  await s.addProduct()
  const started = await s.ageCheck("start")
  await decideOnHostedPage(started.hosted_url, "pass")
  assert.equal((await s.ageCheck("refresh")).status, "passed")
  await mock("faults", { rpc: "GetVerification", code: "unavailable", times: 1 })
  const res = await s.checkout()
  assert.equal(res.status, 400)
  assert.match(res.json.message, /temporarily unavailable/)
})

await group("a failed check refuses, and a new start makes a new verification", async () => {
  const s = new Shopper()
  await s.addProduct()
  const first = await s.ageCheck("start")
  await decideOnHostedPage(first.hosted_url, "fail")
  const refreshed = await s.ageCheck("refresh")
  assert.equal(refreshed.status, "failed")
  const res = await s.checkout()
  assert.equal(res.status, 400)
  assert.match(res.json.message, /could not verify your age/)
  const again = await s.ageCheck("start")
  assert.equal(again.status, "pending")
  const creates = (await (await fetch(`${MOCK}/__mock/requests`)).json()).filter((r) => r.rpc === "CreateVerification")
  const keys = creates.slice(-2).map((r) => r.body.idempotencyKey)
  assert.notEqual(keys[0], keys[1])
  assert.match(keys[1], /:1$/)
})

await group("starts are rate limited per session", async () => {
  const s = new Shopper()
  await s.addProduct()
  let last
  for (let i = 0; i < 11; i++) last = await s.ageCheck("start")
  assert.equal(last.status, "unavailable")
  assert.match(last.message, /Too many age check attempts/)
})

await group("parallel starts never get past the session limit (atomic counter)", async () => {
  const s = new Shopper()
  await s.addProduct()
  const answers = await Promise.all(Array.from({ length: 16 }, () => s.ageCheck("start")))
  const limited = answers.filter((a) => /Too many/.test(a.message ?? "")).length
  assert.ok(limited >= 6, `at most 10 of 16 got through (${16 - limited} did)`)
})

await group("starts are rate limited per client IP across sessions", async () => {
  const ip = "198.19.0.7"
  for (let i = 0; i < 2; i++) {
    const s = new Shopper(ip)
    await s.addProduct()
    for (let j = 0; j < 10; j++) assert.equal((await s.ageCheck("start")).status, "pending", `session ${i} start ${j}`)
  }
  const third = new Shopper(ip)
  await third.addProduct()
  const answer = await third.ageCheck("start")
  assert.equal(answer.status, "unavailable")
  assert.match(answer.message, /Too many/)
  const elsewhere = new Shopper()
  await elsewhere.addProduct()
  assert.equal((await elsewhere.ageCheck("start")).status, "pending", "another IP is not limited")
})

await group("classic page: Store API nonce bootstrap, start, and ?wc-ajax=checkout enforcement", async () => {
  assert.ok(CLASSIC > 0, "--classic is required")
  const s = new Shopper()
  await s.addProduct()
  const page = await s.fetch(`${SITE}/?page_id=${CLASSIC}`, { redirect: "follow" })
  const html = await page.text()
  assert.match(html, /agecheck-1\.0\.0(\.min)?\.js/, "loader enqueued")
  assert.match(html, /checkout-classic(\.min)?\.js/, "classic glue enqueued")
  assert.match(html, /data-navexa-agecheck-verify="1"/, "Verify age button rendered")
  assert.match(html, /window\.navexaAgeCheckClassic = /)
  const nonce = /name="woocommerce-process-checkout-nonce" value="([^"]+)"/.exec(html)?.[1]
  assert.ok(nonce, "checkout nonce")

  const form = (address) =>
    new URLSearchParams({
      billing_first_name: address.first_name,
      billing_last_name: address.last_name,
      billing_country: address.country,
      billing_address_1: address.address_1,
      billing_address_2: address.address_2,
      billing_city: address.city,
      billing_state: address.state,
      billing_postcode: address.postcode,
      billing_phone: "",
      billing_email: "pat@example.com",
      "shipping_method[0]": "",
      payment_method: "cod",
      "woocommerce-process-checkout-nonce": nonce,
      _wp_http_referer: `/?page_id=${CLASSIC}`,
    })
  const cart = await s.store("GET", "/cart")
  const flat = cart.json.shipping_rates[0].shipping_rates.find((r) => r.method_id === "flat_rate")
  const submit = async (address) => {
    const body = form(address)
    body.set("shipping_method[0]", flat.rate_id)
    const res = await s.fetch(`${SITE}/?wc-ajax=checkout`, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: body.toString(),
    })
    return res.json()
  }

  const refused = await submit(US)
  assert.equal(refused.result, "failure")
  assert.match(refused.messages, /Verify your age on the checkout page/)

  // A fresh nonce from GET /cart (what the classic glue does), then start.
  s.nonce = null
  const started = await s.ageCheck("start")
  assert.equal(started.status, "pending")
  await decideOnHostedPage(started.hosted_url, "pass")
  assert.equal((await s.ageCheck("refresh")).status, "passed")

  const placed = await submit(US)
  assert.equal(placed.result, "success", JSON.stringify(placed).slice(0, 400))

  // WooCommerce's classic checkout update saves the billing address but not
  // the name; the plugin copies the typed name so the start sends it.
  const guest = new Shopper()
  await guest.addProduct({ ...US, first_name: "", last_name: "" }, US)
  const classicHtml = await (await guest.fetch(`${SITE}/?page_id=${CLASSIC}`, { redirect: "follow" })).text()
  const reviewNonce = /"update_order_review_nonce":"([^"]+)"/.exec(classicHtml)?.[1]
  assert.ok(reviewNonce, "update_order_review nonce")
  const typed = form({ ...US, first_name: "Robin", last_name: "Typed" })
  const review = new URLSearchParams({
    security: reviewNonce,
    payment_method: "cod",
    country: "US",
    state: "IL",
    postcode: US.postcode,
    city: US.city,
    address: US.address_1,
    address_2: US.address_2,
    s_country: "US",
    s_state: "IL",
    s_postcode: US.postcode,
    s_city: US.city,
    s_address: US.address_1,
    s_address_2: US.address_2,
    has_full_address: "true",
    post_data: typed.toString(),
  })
  const reviewed = await guest.fetch(`${SITE}/?wc-ajax=update_order_review`, { method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: review.toString() })
  assert.equal(reviewed.status, 200)
  guest.nonce = null
  assert.equal((await guest.ageCheck("start")).status, "pending")
  assert.equal((await lastCreate()).identity?.name, "Robin Typed", "the name typed on the classic form")

  const moved = new Shopper()
  await moved.addProduct()
  const st = await moved.ageCheck("start")
  await decideOnHostedPage(st.hosted_url, "pass")
  await moved.ageCheck("refresh")
  const page2 = await moved.fetch(`${SITE}/?page_id=${CLASSIC}`, { redirect: "follow" })
  const nonce2 = /name="woocommerce-process-checkout-nonce" value="([^"]+)"/.exec(await page2.text())?.[1]
  const body = form({ ...US, address_1: "9 Elm St" })
  body.set("woocommerce-process-checkout-nonce", nonce2)
  body.set("shipping_method[0]", flat.rate_id)
  const res = await (await moved.fetch(`${SITE}/?wc-ajax=checkout`, { method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: body.toString() })).json()
  assert.equal(res.result, "failure")
  assert.match(res.messages, /shipping address changed/)
})

/** The classic checkout page's HTML, in the shopper's session. */
async function classicPage(s) {
  const page = await s.fetch(`${SITE}/?page_id=${CLASSIC}`, { redirect: "follow" })
  assert.equal(page.status, 200)
  return page.text()
}

/** Posts the classic checkout form (?wc-ajax=checkout) with the nonce from $html. */
async function classicSubmit(s, html, address, payment = "cod") {
  const nonce = /name="woocommerce-process-checkout-nonce" value="([^"]+)"/.exec(html)?.[1]
  assert.ok(nonce, "checkout nonce")
  const cart = await s.store("GET", "/cart")
  const flat = cart.json.shipping_rates[0].shipping_rates.find((r) => r.method_id === "flat_rate")
  const body = new URLSearchParams({
    billing_first_name: address.first_name,
    billing_last_name: address.last_name,
    billing_country: address.country,
    billing_address_1: address.address_1,
    billing_address_2: address.address_2,
    billing_city: address.city,
    billing_state: address.state,
    billing_postcode: address.postcode,
    billing_phone: "",
    billing_email: address.email,
    "shipping_method[0]": flat.rate_id,
    payment_method: payment,
    "woocommerce-process-checkout-nonce": nonce,
    _wp_http_referer: `/?page_id=${CLASSIC}`,
  })
  const res = await s.fetch(`${SITE}/?wc-ajax=checkout`, { method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: body.toString() })
  return res.json()
}

await group("classic: a declined payment keeps the check: the page says passed and the retry resumes the order with no new check (1.0.4)", async () => {
  assert.ok(CLASSIC > 0, "--classic is required")
  const s = new Shopper()
  await s.addProduct(RETRY)
  const started = await s.ageCheck("start")
  await decideOnHostedPage(started.hosted_url, "pass")
  assert.equal((await s.ageCheck("refresh")).status, "passed")
  const declined = await classicSubmit(s, await classicPage(s), RETRY, DECLINE)
  assert.equal(declined.result, "failure", JSON.stringify(declined).slice(0, 400))
  assert.match(declined.messages, /declined/)
  const order = await lastAttachedOrder()
  const creates = await rpcCount("CreateVerification")

  assert.equal((await s.store("GET", "/cart")).json.extensions[NS].status, "passed")
  const html = await classicPage(s)
  assert.match(html, /data-navexa-agecheck="1" data-status="passed"/, "the page shows the check passed")
  assert.doesNotMatch(html, /data-navexa-agecheck-verify="1"/, "no Verify age button")
  const placed = await classicSubmit(s, html, RETRY)
  assert.equal(placed.result, "success", JSON.stringify(placed).slice(0, 400))
  assert.match(placed.redirect, new RegExp(`order-received/${order}/`), "WooCommerce resumed the declined order")
  assert.equal(await rpcCount("CreateVerification"), creates, "no second verification")
})

await group("classic: a ship-to change after a declined payment needs a new check", async () => {
  const s = new Shopper()
  await s.addProduct(RETRY)
  const started = await s.ageCheck("start")
  await decideOnHostedPage(started.hosted_url, "pass")
  assert.equal((await s.ageCheck("refresh")).status, "passed")
  const declined = await classicSubmit(s, await classicPage(s), RETRY, DECLINE)
  assert.equal(declined.result, "failure", JSON.stringify(declined).slice(0, 400))
  const moved = { ...RETRY, address_1: "9 Elm St" }
  assert.equal((await s.setAddress(moved)).extensions[NS].status, "none")
  const html = await classicPage(s)
  assert.match(html, /data-navexa-agecheck-verify="1"/, "Verify age is offered again")
  const refused = await classicSubmit(s, html, moved)
  assert.equal(refused.result, "failure")
  assert.match(refused.messages, /shipping address changed/)
})

await group("a signed-out shopper's verification survives nothing it should not: a new session has no guard", async () => {
  assert.ok(verified, "needs the block flow")
  const stranger = new Shopper()
  await stranger.addProduct()
  const res = await stranger.checkout()
  assert.equal(res.status, 400)
})

if (failures > 0) {
  console.error(`${failures} group(s) failed`)
  process.exit(1)
}
console.log("prepayment flow passed")
