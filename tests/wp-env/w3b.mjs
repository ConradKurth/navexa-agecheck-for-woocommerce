#!/usr/bin/env node
// W3 PRs 12 and 14 over real HTTP against wp-env and the mock engine:
// - a signed-in shopper's start (Store API with X-WP-Nonce) carries
//   customer_ref and a guest's none;
// - per-store reuse at checkout: with the mock's reuse on, a returning
//   customer's next start passes at once as PASS_CLASS_REUSED, the order is
//   bound, held and released, and the admin panel says so;
// - "Verify now" on the order-received page of a held order (accept and
//   hold): the plugin's start step (the order key and the session-bound
//   nonce), AgeCheck's hosted page, the return step back to the order page,
//   and the release; forged and stale starts refused; My account's view-order
//   page shows it for a signed-in customer.
// - returning guests (TAX-1839): a guest's start carries the billing email
//   (from the Store API's update-customer, and on the classic page from the
//   typed form through update_order_review) only while GetIntegrationInfo's
//   guestReuseCode is true; a returning guest passes REUSED by the emailed
//   code, and the order is bound, held and released.
//
//   node tests/mock-agecheck/server.mjs --host 0.0.0.0 --port 8787 &
//   npm run env start
//   npm run env -- run cli --env-cwd=wp-content/plugins/navexa-agecheck-for-woocommerce wp eval-file tests/wp-env/prepayment-setup.php
//   node tests/wp-env/w3b.mjs --site http://localhost:8888 --mock http://localhost:8787 --product <product> [--classic <classic_checkout>]
//     [--setup-cmd "npx --yes @wordpress/env@12.1.0 run cli --env-cwd=wp-content/plugins/navexa-agecheck-for-woocommerce wp eval-file tests/wp-env/w3b-setup.php"]
//
// Exits non-zero when a group fails. Zero dependencies (Node 22+).

import assert from "node:assert/strict"
import { spawn } from "node:child_process"

function arg(name, fallback) {
  const i = process.argv.indexOf(name)
  return i >= 0 ? process.argv[i + 1] : fallback
}

const SITE = arg("--site", "http://localhost:8888").replace(/\/+$/, "")
const MOCK = arg("--mock", "http://localhost:8787").replace(/\/+$/, "")
const PRODUCT = Number(arg("--product", "0"))
const CLASSIC = Number(arg("--classic", "0"))
const SETUP = arg(
  "--setup-cmd",
  "npx --yes @wordpress/env@12.1.0 run cli --env-cwd=wp-content/plugins/navexa-agecheck-for-woocommerce wp eval-file tests/wp-env/w3b-setup.php",
)
const STORE = `${SITE}/wp-json/wc/store/v1`
const NS = "navexa-agecheck"

const US = {
  first_name: "Ada",
  last_name: "Lovelace",
  address_1: "1 Main St",
  address_2: "",
  city: "Springfield",
  state: "IL",
  postcode: "62701",
  country: "US",
  email: "ada@example.com",
  phone: "",
}

/**
 * Runs w3b-setup.php with these arguments; its JSON answer. Asynchronous on
 * purpose: a synchronous child (execSync) blocks the event loop for the
 * seconds wp-cli takes, so fetch's pool never sees the servers close their
 * idle kept-alive sockets meanwhile and reuses a dead one next ("other side
 * closed").
 */
function setup(...args) {
  return new Promise((resolve, reject) => {
    const child = spawn(`${SETUP} ${args.join(" ")}`, { shell: true, stdio: ["ignore", "pipe", "inherit"] })
    let out = ""
    child.stdout.setEncoding("utf8").on("data", (chunk) => (out += chunk))
    child.on("error", reject)
    child.on("close", (code) => {
      if (code !== 0) return reject(new Error(`w3b-setup ${args.join(" ")} exited ${code}: ${out}`))
      const line = out.trim().split("\n").filter((l) => l.startsWith("{")).pop()
      if (!line) return reject(new Error(`w3b-setup printed no JSON: ${out}`))
      resolve(JSON.parse(line))
    })
  })
}

/** fetch through keepAliveSafe: every request this script makes goes through here or Shopper.fetch. */
function http(url, init) {
  return keepAliveSafe(() => fetch(url, init))
}

async function mock(path, body) {
  const res = await http(`${MOCK}/__mock/${path}`, { method: body === undefined ? "GET" : "POST", body: body === undefined ? undefined : JSON.stringify(body) })
  assert.equal(res.status, 200, `mock ${path}`)
  return res.json()
}

/** Bodies of the mock's recorded calls of rpc. */
async function calls(rpc) {
  return (await mock("requests")).filter((r) => r.rpc === rpc).map((r) => r.body)
}

/**
 * fetch, again once when the server had closed the kept-alive connection it
 * reused (Apache's keep-alive timeout runs out while wp-cli runs between two
 * requests): the request never reached WordPress.
 */
async function keepAliveSafe(send) {
  try {
    return await send()
  } catch (err) {
    if (err?.cause?.code !== "UND_ERR_SOCKET") throw err
    return send()
  }
}

/** A browser-ish client: a cookie jar, the Store API nonce and, signed in, the REST nonce. */
class Shopper {
  constructor() {
    this.cookies = new Map()
    this.nonce = null
    this.rest = null
    this.ip = `198.18.${Math.floor(Math.random() * 250)}.${1 + Math.floor(Math.random() * 250)}`
  }

  async fetch(url, init = {}) {
    const headers = { "X-Real-IP": this.ip, ...(init.headers ?? {}) }
    if (this.cookies.size) headers.Cookie = [...this.cookies].map(([k, v]) => `${k}=${v}`).join("; ")
    const res = await http(url, { ...init, headers, redirect: "manual" })
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

  async login(user, pass) {
    this.cookies.set("wordpress_test_cookie", "WP%20Cookie%20check")
    const body = new URLSearchParams({ log: user, pwd: pass, "wp-submit": "Log In", redirect_to: `${SITE}/`, testcookie: "1" })
    const res = await this.fetch(`${SITE}/wp-login.php`, { method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: body.toString() })
    assert.equal(res.status, 302, "login redirects")
    assert.ok([...this.cookies.keys()].some((k) => k.startsWith("wordpress_logged_in_")), "logged-in cookie set")
    const nonce = await this.fetch(`${SITE}/wp-admin/admin-ajax.php?action=rest-nonce`)
    this.rest = (await nonce.text()).trim()
    assert.match(this.rest, /^[0-9a-f]{10}$/, "REST nonce for the signed-in shopper")
  }

  async store(method, path, body) {
    if (!this.nonce && method !== "GET") await this.store("GET", "/cart")
    const headers = { "Content-Type": "application/json", Nonce: this.nonce ?? "" }
    if (this.rest) headers["X-WP-Nonce"] = this.rest
    const res = await this.fetch(`${STORE}${path}`, { method, headers, body: body === undefined ? undefined : JSON.stringify(body) })
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
    const res = await this.store("POST", "/cart/update-customer", { billing_address: address, shipping_address: address })
    assert.equal(res.status, 200, `update-customer: ${JSON.stringify(res.json).slice(0, 300)}`)
  }

  async ageCheck(action) {
    const res = await this.store("POST", "/cart/extensions", { namespace: NS, data: { v: 1, action } })
    assert.equal(res.status, 200, `extensions ${action}: ${JSON.stringify(res.json).slice(0, 300)}`)
    return res.json.extensions[NS]
  }

  async checkout(address = US) {
    const cart = await this.store("GET", "/cart")
    const rate = cart.json.shipping_rates?.[0]?.shipping_rates?.find((r) => r.method_id === "flat_rate")
    if (rate) await this.store("POST", "/cart/select-shipping-rate", { package_id: 0, rate_id: rate.rate_id })
    return this.store("POST", "/checkout", { billing_address: address, shipping_address: address, payment_method: "cod" })
  }
}

async function decideOnHostedPage(hostedUrl, decision) {
  const res = await http(hostedUrl, { method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: `decision=${decision}`, redirect: "manual" })
  assert.equal(res.status, 303, "hosted page answers 303")
  return res.headers.get("location")
}

/** The "Verify now" form's fields on an order page (or the form labelled $label), or null. */
function verifyForm(html, label = "Verify now") {
  const form = /<form method="post" action="([^"]+)">(.*?)<\/form>/s.exec(html)
  if (!form || !form[2].includes(label)) return null
  const fields = {}
  for (const m of form[2].matchAll(/<input type="hidden" name="([^"]+)" value="([^"]*)"/g)) fields[m[1]] = m[2].replace(/&amp;/g, "&")
  return { action: form[1].replace(/&#038;|&amp;/g, "&"), fields }
}

let failures = 0
async function group(name, fn) {
  try {
    await fn()
    console.log(`ok   ${name}`)
  } catch (err) {
    failures++
    console.log(`FAIL ${name}\n     ${err?.stack?.split("\n").slice(0, 4).join("\n     ")}${err?.cause ? `\n     cause: ${err.cause}` : ""}`)
  }
}

assert.ok(PRODUCT > 0, "--product is required")
await mock("reset", {})
await mock("config", { hostedBase: MOCK, org: { allowedOrigins: [SITE] } })
const user = await setup("user")
await setup("mode", "prepayment")

await group("PR 14: a signed-in shopper's start carries customer_ref; a guest's none", async () => {
  const guest = new Shopper()
  await guest.addProduct()
  assert.equal((await guest.ageCheck("start")).status, "pending")
  const signed = new Shopper()
  await signed.login(user.login, user.pass)
  await signed.addProduct()
  assert.equal((await signed.ageCheck("start")).status, "pending")
  const creates = await calls("CreateVerification")
  assert.equal(creates.length, 2)
  assert.equal(creates[0].customerRef, undefined, "a guest sends none")
  assert.equal(creates[1].customerRef, user.customer_ref, "the signed-in shopper's keyed reference")
  assert.match(creates[1].idempotencyKey, /:c[0-9a-f]{16}$/)
  assert.ok(!JSON.stringify(creates[1]).includes(`"${user.user_id}"`), "never the user id")
})

await group("PR 14: reuse on, a returning customer passes at once as REUSED; the order is bound, held and released", async () => {
  await mock("reset", {})
  await mock("config", { hostedBase: MOCK, org: { allowedOrigins: [SITE] }, reuse: "on" })
  const s = new Shopper()
  await s.login(user.login, user.pass)
  await s.addProduct()
  const started = await s.ageCheck("start")
  assert.equal(started.status, "pending")
  await decideOnHostedPage(started.hosted_url, "pass")
  assert.equal((await s.ageCheck("refresh")).status, "passed")
  const first = await s.checkout()
  assert.equal(first.status, 200, `first checkout: ${JSON.stringify(first.json).slice(0, 300)}`)
  const firstRun = await setup("run", first.json.order_id)
  assert.equal(firstRun.status, "processing", JSON.stringify(firstRun))

  await s.addProduct()
  const again = await s.ageCheck("start")
  assert.deepEqual(again, { v: 1, status: "passed", required: true }, "passed at the start: no popup, no hosted page")
  const creates = await calls("CreateVerification")
  assert.equal(creates.length, 2)
  assert.equal(creates[1].customerRef, user.customer_ref)
  const second = await s.checkout()
  assert.equal(second.status, 200, `second checkout: ${JSON.stringify(second.json).slice(0, 300)}`)
  const run = await setup("run", second.json.order_id)
  assert.equal(run.status, "processing", JSON.stringify(run))
  assert.equal(run.bound_by, "attach")
  assert.equal(run.pass_class, "REUSED")
  assert.equal(run.passed_by, "Returning customer (reused verification)")
  const state = await mock("verifications")
  const reused = state.verifications.find((v) => v.id === run.vid)
  assert.equal(reused.passClass, "reused")
  assert.ok(reused.reusedFrom, "AgeCheck names the earlier check")
})

await group("TAX-1839: a guest's start carries the billing email only while AgeCheck offers the code", async () => {
  await mock("reset", {})
  await mock("config", { hostedBase: MOCK, org: { allowedOrigins: [SITE] } })
  await setup("mode", "prepayment")
  const off = new Shopper()
  await off.addProduct()
  assert.equal((await off.ageCheck("start")).status, "pending")
  let creates = await calls("CreateVerification")
  assert.equal(creates.at(-1).identity?.email, undefined, "reuse off: no email")
  assert.match(creates.at(-1).idempotencyKey, /:[0-9]+$/)

  await mock("config", { reuse: "on" })
  await setup("mode", "prepayment") // flushes the cached GetIntegrationInfo
  const on = new Shopper()
  await on.addProduct()
  assert.equal((await on.ageCheck("start")).status, "pending")
  creates = await calls("CreateVerification")
  assert.equal(creates.at(-1).identity?.email, US.email, "the email the Store API's update-customer saved")
  assert.match(creates.at(-1).idempotencyKey, /:e[0-9a-f]{16}$/)
  assert.equal(creates.at(-1).documentNameCheck, "DOCUMENT_NAME_CHECK_NONE")

  const signed = new Shopper()
  await signed.login(user.login, user.pass)
  await signed.addProduct()
  await signed.ageCheck("start")
  creates = await calls("CreateVerification")
  assert.equal(creates.at(-1).identity?.email, undefined, "a signed-in customer never sends it")
  assert.equal(creates.at(-1).customerRef, user.customer_ref)

  await mock("config", { org: { displayName: "" } })
  await setup("mode", "prepayment")
  const unnamed = new Shopper()
  await unnamed.addProduct()
  await unnamed.ageCheck("start")
  creates = await calls("CreateVerification")
  assert.equal(creates.at(-1).identity?.email, undefined, "no store name: no code, no email")
})

await group("TAX-1839: a returning guest passes REUSED by the emailed code; the order is bound, held and released", async () => {
  await mock("reset", {})
  await mock("config", { hostedBase: MOCK, org: { allowedOrigins: [SITE] }, reuse: "on" })
  await setup("mode", "prepayment")
  const first = new Shopper()
  await first.addProduct()
  const started = await first.ageCheck("start")
  assert.equal(started.status, "pending")
  await decideOnHostedPage(started.hosted_url, "pass")
  assert.equal((await first.ageCheck("refresh")).status, "passed")
  const placed = await first.checkout()
  assert.equal(placed.status, 200, `first checkout: ${JSON.stringify(placed.json).slice(0, 300)}`)
  assert.equal((await setup("run", placed.json.order_id)).status, "processing")

  // Another session, the same guest: the hosted page offers the code; the code passes it.
  const back = new Shopper()
  await back.addProduct()
  const again = await back.ageCheck("start")
  assert.equal(again.status, "pending", "a guest is never reused at the start: the code is on AgeCheck's page")
  const page = await (await http(again.hosted_url)).text()
  assert.match(page, /Verified at this store before\?/)
  await decideOnHostedPage(again.hosted_url, "otp")
  assert.equal((await back.ageCheck("refresh")).status, "passed")
  const second = await back.checkout()
  assert.equal(second.status, 200, `second checkout: ${JSON.stringify(second.json).slice(0, 300)}`)
  const run = await setup("run", second.json.order_id)
  assert.equal(run.status, "processing", JSON.stringify(run))
  assert.equal(run.bound_by, "attach")
  assert.equal(run.pass_class, "REUSED")
})

if (CLASSIC > 0) {
  await group("TAX-1839: the classic checkout's typed billing email reaches the start", async () => {
    await mock("reset", {})
    await mock("config", { hostedBase: MOCK, org: { allowedOrigins: [SITE] }, reuse: "on" })
    await setup("mode", "prepayment")
    const guest = new Shopper()
    await guest.addProduct({ ...US, email: "stale@example.com" })
    // Shopper.fetch never follows redirects (the permalink's 301 included).
    let res = await guest.fetch(`${SITE}/?page_id=${CLASSIC}`)
    for (let hops = 0; res.status >= 300 && res.status < 400 && hops < 3; hops++) res = await guest.fetch(new URL(res.headers.get("location"), SITE).href)
    const html = await res.text()
    const reviewNonce = /"update_order_review_nonce":"([^"]+)"/.exec(html)?.[1]
    assert.ok(reviewNonce, "update_order_review nonce")
    const typed = new URLSearchParams({
      billing_first_name: US.first_name,
      billing_last_name: US.last_name,
      billing_country: US.country,
      billing_address_1: US.address_1,
      billing_address_2: US.address_2,
      billing_city: US.city,
      billing_state: US.state,
      billing_postcode: US.postcode,
      billing_email: "typed@example.com",
      payment_method: "cod",
    })
    const review = new URLSearchParams({
      security: reviewNonce,
      payment_method: "cod",
      country: "US",
      state: US.state,
      postcode: US.postcode,
      city: US.city,
      address: US.address_1,
      address_2: US.address_2,
      s_country: "US",
      s_state: US.state,
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
    const creates = await calls("CreateVerification")
    assert.equal(creates.at(-1).identity?.email, "typed@example.com", "the email typed on the classic form")
  })
}

await setup("mode", "hold")

await group("PR 12: Verify now on the order-received page: start, hosted page, return, release", async () => {
  await mock("reset", {})
  await mock("config", { hostedBase: MOCK, org: { allowedOrigins: [SITE] }, links: "on" })
  const s = new Shopper()
  await s.addProduct()
  const placed = await s.checkout()
  assert.equal(placed.status, 200, `checkout: ${JSON.stringify(placed.json).slice(0, 300)}`)
  const { order_id: id, order_key: key } = placed.json
  assert.equal((await setup("order", id)).status, "agecheck-hold", "accept and hold: the paid order is held")

  const page = await s.fetch(placed.json.payment_result.redirect_url)
  assert.equal(page.status, 200)
  const html = await page.text()
  const form = verifyForm(html)
  assert.ok(form, "the order-received page shows Verify now")
  assert.equal(form.fields.order, String(id))
  assert.equal(form.fields.key, key)
  assert.ok(!html.includes("acv_"), "no verification id on the page")
  assert.ok(!html.includes("txk_"), "no key on the page")

  const stranger = new Shopper()
  const forged = await stranger.fetch(form.action, { method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: new URLSearchParams(form.fields).toString() })
  assert.equal(forged.status, 403, "another browser's session cannot use the nonce")
  const wrongKey = await s.fetch(form.action, { method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: new URLSearchParams({ ...form.fields, key: "wc_order_nope" }).toString() })
  assert.equal(wrongKey.status, 403, "the order key is checked")

  const start = await s.fetch(form.action, { method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: new URLSearchParams(form.fields).toString() })
  assert.equal(start.status, 303)
  const hosted = start.headers.get("location")
  assert.ok(hosted.startsWith(`${MOCK}/v/`), `redirected to AgeCheck's page: ${hosted}`)
  const bound = await setup("order", id)
  assert.ok(bound.vid.startsWith("acv_mock"), "the start bound an order-bound check in the same request")
  assert.equal(bound.bound_by, "create")
  assert.ok(bound.link.startsWith("sent:"), `and AgeCheck emailed the link too: ${bound.link}`)
  const sessions = await calls("CreateHostedSession")
  assert.equal(sessions.at(-1).verificationId, bound.vid)
  assert.ok(sessions.at(-1).returnUrl.includes("step=return") && !sessions.at(-1).returnUrl.includes(key), "return step, never the order key")

  const back = await decideOnHostedPage(hosted, "pass")
  assert.ok(back.includes("wc-api=navexa_agecheck_order") && back.includes("step=return"), `back to the plugin: ${back}`)
  const returned = await s.fetch(back)
  assert.equal(returned.status, 303, "the shopper who started it goes back to their order")
  assert.equal(returned.headers.get("location"), placed.json.payment_result.redirect_url)
  const landed = await (await s.fetch(placed.json.payment_result.redirect_url)).text()
  assert.equal(verifyForm(landed), null, "the order page no longer offers Verify now, before any release run")
  assert.match(landed, /order-received|Order received|Thank you/i, "the order page itself")
  const run = await setup("run", id)
  assert.ok(run.queued_by_return, "the return queued a release run")
  assert.equal(run.status, "processing", JSON.stringify(run))
  const after = await (await s.fetch(placed.json.payment_result.redirect_url)).text()
  assert.equal(verifyForm(after), null, "no Verify now once released")

  const links = (await mock("verifications")).links.filter((l) => l.verificationId === bound.vid)
  assert.equal(links.at(-1).revokedReason, "decided", "the pass revoked the emailed link")
  const emailed = await http(links.at(-1).returnUrl, { redirect: "manual" })
  assert.equal(emailed.status, 200, "the emailed link's return, in a browser without the session: the finished page")
  assert.match(await emailed.text(), /Your age check is finished/)
})

await group("TAX-1838: a declined ID check on a held order: Try again on the order page retries the same verification, and a pass releases it", async () => {
  await mock("reset", {})
  await mock("config", { hostedBase: MOCK, org: { allowedOrigins: [SITE] }, links: "on", docRetry: "on" })
  await setup("mode", "hold") // flushes the cached GetIntegrationInfo (docRetry)
  const s = new Shopper()
  await s.addProduct({ ...US, email: "retry@example.com" })
  const placed = await s.checkout({ ...US, email: "retry@example.com" })
  assert.equal(placed.status, 200, `checkout: ${JSON.stringify(placed.json).slice(0, 300)}`)
  const { order_id: id } = placed.json
  const orderPage = placed.json.payment_result.redirect_url
  const form = verifyForm(await (await s.fetch(orderPage)).text())
  assert.ok(form, "the order-received page shows Verify now")
  const post = (f) => s.fetch(f.action, { method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: new URLSearchParams(f.fields).toString() })
  const start = await post(form)
  assert.equal(start.status, 303)
  const bound = await setup("order", id)
  const creates = await calls("CreateVerification")
  assert.equal(creates.at(-1).allowDocRetry, true, "the order-bound create asks for the retry")
  await s.fetch(await decideOnHostedPage(start.headers.get("location"), "fail"))
  const deferred = await setup("run", id)
  assert.equal(deferred.status, "agecheck-hold", `the failure waits for the retry, no refund: ${JSON.stringify(deferred)}`)

  const failedPage = await (await s.fetch(orderPage)).text()
  assert.match(failedPage, /Your ID check didn(&#039;|')t pass/)
  const again = verifyForm(failedPage, "Try again")
  assert.ok(again, "the order page offers Try again")
  const retried = await post(again)
  assert.equal(retried.status, 303, "Try again goes to AgeCheck's page")
  const hosted = retried.headers.get("location")
  assert.ok(hosted.startsWith(`${MOCK}/v/`), `AgeCheck's page: ${hosted}`)
  assert.equal((await calls("CreateHostedSession")).at(-1).verificationId, bound.vid, "the same verification")
  assert.equal((await calls("CreateVerification")).length, creates.length, "no new verification")
  await decideOnHostedPage(hosted, "retry")
  const back = await decideOnHostedPage(hosted, "pass")
  assert.equal((await s.fetch(back)).status, 303, "back to the order")
  const released = await setup("run", id)
  assert.equal(released.status, "processing", JSON.stringify(released))
  await mock("config", { docRetry: "off" })
  await setup("mode", "hold") // the next groups read GetIntegrationInfo without the retry, as production
})

await group("PR 12: links off at AgeCheck: held, Verify now still works", async () => {
  await mock("reset", {})
  await mock("config", { hostedBase: MOCK, org: { allowedOrigins: [SITE] } })
  const s = new Shopper()
  await s.addProduct()
  const placed = await s.checkout()
  const form = verifyForm(await (await s.fetch(placed.json.payment_result.redirect_url)).text())
  assert.ok(form, "Verify now shown")
  const start = await s.fetch(form.action, { method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: new URLSearchParams(form.fields).toString() })
  assert.equal(start.status, 303)
  const state = await setup("order", placed.json.order_id)
  assert.ok(state.link.startsWith("links_off:"), `links off recorded: ${state.link}`)
  assert.equal(state.status, "agecheck-hold")
})

await group("PR 12: a signed-in customer's held order shows Verify now on My account > view order", async () => {
  await mock("reset", {})
  await mock("config", { hostedBase: MOCK, org: { allowedOrigins: [SITE] } })
  const s = new Shopper()
  await s.login(user.login, user.pass)
  await s.addProduct()
  const placed = await s.checkout()
  assert.equal(placed.status, 200, `checkout: ${JSON.stringify(placed.json).slice(0, 300)}`)
  const view = await s.fetch((await setup("order", placed.json.order_id)).view_url)
  assert.equal(view.status, 200)
  const html = await view.text()
  const form = verifyForm(html)
  assert.ok(form, "view-order shows Verify now")
  const guest = new Shopper()
  const refused = await guest.fetch(form.action, { method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: new URLSearchParams(form.fields).toString() })
  assert.equal(refused.status, 403, "an account's order needs that customer signed in")
})

await setup("mode", "prepayment")

if (failures > 0) {
  console.error(`${failures} group(s) failed`)
  process.exit(1)
}
console.log("w3b flow passed")
