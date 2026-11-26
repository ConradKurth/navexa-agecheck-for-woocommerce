#!/usr/bin/env node
// TAX-1841 outage fallback over real HTTP against wp-env and the mock engine:
// a "Before payment" store that takes and holds orders while AgeCheck is down
// (Options::OUTAGE_FALLBACK = hold). Classic and block checkout with the
// engine down (every RPC a bodiless 503) and with the engine up but its ID
// check unavailable (GetCheckAvailability SERVICE_UNAVAILABLE); a refused
// block draft retried within the window; a ship-to change; and a shopper
// whose check failed, before and after a restart, never admitted.
//
//   node tests/mock-agecheck/server.mjs --host 0.0.0.0 --port 8787 &
//   npm run env start
//   npm run env -- run cli --env-cwd=wp-content/plugins/navexa-agecheck-for-woocommerce wp eval-file tests/wp-env/prepayment-setup.php
//   npm run env -- run cli --env-cwd=wp-content/plugins/navexa-agecheck-for-woocommerce wp eval-file tests/wp-env/outage-fallback-setup.php
//   node tests/wp-env/outage-fallback.mjs --site http://localhost:8888 --mock http://localhost:8787 --product <id> --classic <page id>
//   npm run env -- run cli --env-cwd=wp-content/plugins/navexa-agecheck-for-woocommerce wp eval-file tests/wp-env/outage-fallback-orders.php
//
// The orders it places are billed to outage@example.com (the ones taken
// without a check) or outage-pass@example.com (the warm-up's passed check);
// outage-fallback-orders.php follows them to a CLEARED release. Exits
// non-zero when a group failed. Zero dependencies (Node 22+).

import assert from "node:assert/strict"

function arg(name, fallback) {
  const i = process.argv.indexOf(name)
  return i >= 0 ? process.argv[i + 1] : fallback
}

const SITE = arg("--site", "http://localhost:8888").replace(/\/+$/, "")
const MOCK = arg("--mock", "http://localhost:8787").replace(/\/+$/, "")
const PRODUCT = Number(arg("--product", "0"))
const CLASSIC = Number(arg("--classic", "0"))
const STORE = `${SITE}/wp-json/wc/store/v1`
const NS = "navexa-agecheck"
const HOLD_COPY = /Age verification is unavailable right now\. You can place your order/

const US = {
  first_name: "Pat",
  last_name: "Doe",
  address_1: "123 Main St",
  address_2: "Apt 4",
  city: "Springfield",
  state: "IL",
  postcode: "62701",
  country: "US",
  email: "outage@example.com",
  phone: "",
}
const PASSED = { ...US, email: "outage-pass@example.com" }

/** A browser-ish client: a cookie jar and the Store API nonce. */
class Shopper {
  constructor() {
    this.cookies = new Map()
    this.nonce = null
    this.ip = `198.18.${Math.floor(Math.random() * 250)}.${1 + Math.floor(Math.random() * 250)}`
  }

  async fetch(url, init = {}) {
    const headers = { "X-Real-IP": this.ip, ...(init.headers ?? {}) }
    if (this.cookies.size) headers.Cookie = [...this.cookies].map(([k, v]) => `${k}=${v}`).join("; ")
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

  async addProduct(address = US) {
    const added = await this.store("POST", "/cart/add-item", { id: PRODUCT, quantity: 1 })
    assert.equal(added.status, 201, `add-item: ${JSON.stringify(added.json).slice(0, 300)}`)
    return this.setAddress(address)
  }

  async setAddress(address) {
    const res = await this.store("POST", "/cart/update-customer", { billing_address: address, shipping_address: address })
    assert.equal(res.status, 200, `update-customer: ${JSON.stringify(res.json).slice(0, 300)}`)
    return res.json
  }

  async ageCheck(action) {
    const res = await this.store("POST", "/cart/extensions", { namespace: NS, data: { v: 1, action } })
    assert.equal(res.status, 200, `extensions ${action}: ${JSON.stringify(res.json).slice(0, 300)}`)
    return res.json.extensions[NS]
  }

  async checkout(address = US, paymentMethod = "cod") {
    const cart = await this.store("GET", "/cart")
    const rate = cart.json.shipping_rates?.[0]?.shipping_rates?.find((r) => r.method_id === "flat_rate")
    if (rate) await this.store("POST", "/cart/select-shipping-rate", { package_id: 0, rate_id: rate.rate_id })
    return this.store("POST", "/checkout", { billing_address: address, shipping_address: address, payment_method: paymentMethod })
  }
}

async function mock(path, body) {
  const res = await fetch(`${MOCK}/__mock/${path}`, { method: "POST", body: JSON.stringify(body ?? {}) })
  assert.equal(res.status, 200, `mock ${path}`)
}

async function requests() {
  return (await fetch(`${MOCK}/__mock/requests`)).json()
}

async function decideOnHostedPage(hostedUrl, decision) {
  const res = await fetch(hostedUrl, {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: `decision=${decision}`,
    redirect: "manual",
  })
  assert.equal(res.status, 303, "hosted page answers 303")
}

let failures = 0
async function group(name, fn) {
  try {
    await fn()
    console.log(`ok   ${name}`)
  } catch (err) {
    failures++
    console.log(`FAIL ${name}\n     ${err?.stack?.split("\n").slice(0, 3).join("\n     ")}`)
  } finally {
    await mock("config", { down: false, availability: "available" })
  }
}

assert.ok(PRODUCT > 0, "--product is required")
assert.ok(CLASSIC > 0, "--classic is required")
await mock("reset")
await mock("config", { hostedBase: MOCK, links: "on", org: { allowedOrigins: [SITE], displayName: "Mock Store" } })

await group("warm-up: a passed check asks GetIntegrationInfo, which reports links enabled", async () => {
  const s = new Shopper()
  await s.addProduct(PASSED)
  const started = await s.ageCheck("start")
  assert.equal(started.status, "pending")
  await decideOnHostedPage(started.hosted_url, "pass")
  assert.equal((await s.ageCheck("refresh")).status, "passed")
  const info = (await requests()).filter((r) => r.rpc === "GetIntegrationInfo")
  assert.ok(info.length >= 1, "the store asked GetIntegrationInfo")
  const placed = await s.checkout(PASSED)
  assert.equal(placed.status, 200, JSON.stringify(placed.json).slice(0, 400))
})

await group("block: AgeCheck down at the start, hold; the order is placed without a check", async () => {
  await mock("config", { down: true })
  const s = new Shopper()
  await s.addProduct()
  const started = await s.ageCheck("start")
  assert.equal(started.status, "hold", JSON.stringify(started))
  assert.match(started.message, HOLD_COPY)
  assert.equal(started.hosted_url, undefined)
  assert.equal((await s.store("GET", "/cart")).json.extensions[NS].status, "hold", "the cart data says so while it lasts")
  const seen = (await requests()).length
  const placed = await s.checkout()
  assert.equal(placed.status, 200, JSON.stringify(placed.json).slice(0, 400))
  assert.ok(placed.json.order_id > 0)
  const rpcs = (await requests()).slice(seen).map((r) => r.rpc)
  assert.ok(!rpcs.includes("AttachOrder") && !rpcs.includes("CreateVerification"), `nothing bound at checkout (${rpcs.join(", ")})`)
})

await group("block: a draft refused for another reason and placed again within the window is admitted again", async () => {
  await mock("config", { down: true })
  const s = new Shopper()
  await s.addProduct()
  assert.equal((await s.ageCheck("start")).status, "hold")
  const refused = await s.checkout(US, "no_such_gateway")
  assert.equal(refused.status, 400, JSON.stringify(refused.json).slice(0, 300))
  assert.doesNotMatch(JSON.stringify(refused.json), /Verify your age/, "refused for the payment method, not the age check")
  const placed = await s.checkout()
  assert.equal(placed.status, 200, JSON.stringify(placed.json).slice(0, 400))
})

await group("a ship-to change after the hold needs a check again", async () => {
  await mock("config", { down: true })
  const s = new Shopper()
  await s.addProduct()
  assert.equal((await s.ageCheck("start")).status, "hold")
  const moved = { ...US, address_1: "9 Elm St" }
  await s.setAddress(moved)
  const res = await s.checkout(moved)
  assert.equal(res.status, 400)
  assert.match(res.json.message, /Verify your age|temporarily unavailable/)
})

await group("a failed check never falls back: AgeCheck down at the next start", async () => {
  const s = new Shopper()
  await s.addProduct()
  const first = await s.ageCheck("start")
  assert.equal(first.status, "pending")
  await decideOnHostedPage(first.hosted_url, "fail")
  assert.equal((await s.ageCheck("refresh")).status, "failed")
  await mock("config", { down: true })
  const again = await s.ageCheck("start")
  assert.equal(again.status, "unavailable", JSON.stringify(again))
  const res = await s.checkout()
  assert.equal(res.status, 400)
})

// The checks above cached AVAILABLE store-wide (Availability::SUCCESS_TTL,
// 60 s): wait it out, so the next start reads SERVICE_UNAVAILABLE.
console.log("     waiting 61 s for the cached availability answer to expire")
await new Promise((resolve) => setTimeout(resolve, 61000))

await group("block: AgeCheck up, its ID check unavailable (NEEDS_DOC, SERVICE_UNAVAILABLE): hold", async () => {
  await mock("config", { availability: "service_unavailable" })
  const s = new Shopper()
  // A billing address elsewhere than the ship-to: never data-eligible, so the create answers NEEDS_DOC.
  const res = await s.store("POST", "/cart/add-item", { id: PRODUCT, quantity: 1 })
  assert.equal(res.status, 201)
  const elsewhere = { ...US, address_1: "9 Elm St", address_2: "" }
  const updated = await s.store("POST", "/cart/update-customer", { billing_address: elsewhere, shipping_address: US })
  assert.equal(updated.status, 200)
  const started = await s.ageCheck("start")
  assert.equal(started.status, "hold", JSON.stringify(started))
  const rpcs = (await requests()).map((r) => r.rpc)
  assert.ok(rpcs.includes("GetCheckAvailability"), "the store read the availability")
  const cart = await s.store("GET", "/cart")
  const rate = cart.json.shipping_rates?.[0]?.shipping_rates?.find((r) => r.method_id === "flat_rate")
  if (rate) await s.store("POST", "/cart/select-shipping-rate", { package_id: 0, rate_id: rate.rate_id })
  const placed = await s.store("POST", "/checkout", { billing_address: elsewhere, shipping_address: US, payment_method: "cod" })
  assert.equal(placed.status, 200, JSON.stringify(placed.json).slice(0, 400))
})

await group("a failed check never falls back after a restart that needs the ID check while it is unavailable", async () => {
  await mock("config", { availability: "service_unavailable" })
  const s = new Shopper()
  await s.store("POST", "/cart/add-item", { id: PRODUCT, quantity: 1 })
  const elsewhere = { ...US, address_1: "9 Elm St", address_2: "" }
  await s.store("POST", "/cart/update-customer", { billing_address: elsewhere, shipping_address: US })
  assert.equal((await s.ageCheck("start")).status, "hold", "the ID check is unavailable")
  // The shopper's check fails (as an ID under age would).
  const v = (await (await fetch(`${MOCK}/__mock/verifications`)).json()).verifications.at(-1)
  await mock("decide", { id: v.id, status: "failed" })
  assert.equal((await s.ageCheck("refresh")).status, "failed")
  // Verify age again: a new verification, NEEDS_DOC, the ID check still
  // unavailable. The new record replaces the FAILED one; no hold.
  const restarted = await s.ageCheck("start")
  assert.notEqual(restarted.status, "hold", JSON.stringify(restarted))
  assert.equal(restarted.status, "pending", "the ID check's link, as without the setting")
  const res = await s.store("POST", "/checkout", { billing_address: elsewhere, shipping_address: US, payment_method: "cod" })
  assert.equal(res.status, 400, "the paid order is refused")
})

await group("classic: AgeCheck down at the start, hold; ?wc-ajax=checkout places the order", async () => {
  await mock("config", { down: true })
  const s = new Shopper()
  await s.addProduct()
  const page = await s.fetch(`${SITE}/?page_id=${CLASSIC}`, { redirect: "follow" })
  const html = await page.text()
  const nonce = /name="woocommerce-process-checkout-nonce" value="([^"]+)"/.exec(html)?.[1]
  assert.ok(nonce, "checkout nonce")
  s.nonce = null
  assert.equal((await s.ageCheck("start")).status, "hold")
  const cart = await s.store("GET", "/cart")
  const flat = cart.json.shipping_rates[0].shipping_rates.find((r) => r.method_id === "flat_rate")
  const body = new URLSearchParams({
    billing_first_name: US.first_name,
    billing_last_name: US.last_name,
    billing_country: US.country,
    billing_address_1: US.address_1,
    billing_address_2: US.address_2,
    billing_city: US.city,
    billing_state: US.state,
    billing_postcode: US.postcode,
    billing_phone: "",
    billing_email: US.email,
    "shipping_method[0]": flat.rate_id,
    payment_method: "cod",
    "woocommerce-process-checkout-nonce": nonce,
    _wp_http_referer: `/?page_id=${CLASSIC}`,
  })
  const res = await s.fetch(`${SITE}/?wc-ajax=checkout`, { method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: body.toString() })
  const placed = await res.json()
  assert.equal(placed.result, "success", JSON.stringify(placed).slice(0, 400))
})

if (failures > 0) {
  console.error(`${failures} group(s) failed`)
  process.exit(1)
}
console.log("outage fallback flow passed")
