#!/usr/bin/env node
// Browser run of the 1.1.0 checkout (TAX-1874): Place order opens the age
// check in the shared loader's in-page modal, on the block and the classic
// checkout. WordPress runs on localhost and the mock's hosted pages on
// 127.0.0.1, so the frame is cross-site (brief C1 v2 embed mode, served by
// the shared mock engine through tests/mock-agecheck/server.mjs); the mock
// sets no cookie, so the flow must work with third-party cookies blocked
// (WebKit's default ITP; Chromium runs with --test-third-party-cookie-phaseout).
//
// Needs wp-env, the mock engine (listening on 0.0.0.0) and
// tests/wp-env/prepayment-setup.php, as for tests/wp-env/prepayment.mjs, and
// Playwright browsers (npx playwright-core install chromium webkit firefox):
//
//   node tests/e2e/checkout-modal-browser.mjs --site http://localhost:8888 --mock http://127.0.0.1:8787 \
//     --product <id> --exempt <id> --free-product <id> --block <page id> --classic <page id> [--browsers chromium,webkit,firefox]
//     [--only <text>] [--turnstile-interactive | --outage-hold]
//     [--setup-cmd "npx --yes @wordpress/env@12.1.0 run cli --env-cwd=wp-content/plugins/navexa-agecheck-for-woocommerce wp eval-file tests/wp-env/w3b-setup.php"]
//
// --mock must be the 127.0.0.1 address: it is also the hosted pages' base.
// Checks: the modal passes and the order is placed without leaving checkout;
// a fail in the frame; closing the modal ({status:'closed'}), then a second
// Place order on the same verification; a refused frame falls back to a popup
// opened from a click inside the modal; a blocked popup falls back to a
// full-page redirect and back; the name and ship-to lock after a pass, a
// declined payment retried with no second check, Change details, and a
// devtools edit of a locked field refused by the server; an order the server
// refuses while the page says passed brings the status back from the server,
// so the next Place order opens the modal; the modal's dialog semantics, its
// focus trap, and where the focus goes when it closes without a pass (classic:
// the refusal notice; block: back to Place order, which WooCommerce keeps
// disabled while it awaits the observer, after Escape, the close button and a
// fail); another plugin's false from a
// checkout_place_order handler bound before the glue still stops WooCommerce
// (a cart that needs no check, and the resubmit after a pass); a second Place
// order while the start is in flight starts nothing more (one modal, one
// hosted session, one order; classic: the status container is aria-busy and
// the live region a polite status outside the replaced fragment); a cart that
// needs no payment (--free-product: the block checkout's fallback status
// block, no payment step) opens the modal and places the order on a pass.
//
// Also: after a failed check the next Place order opens a new check in the
// modal (the checkout's retry: there is no Verify age button); with AgeCheck
// down and the store refusing (the default "When AgeCheck is down"), Place
// order opens no modal, says so and places no order (fail closed); and a
// guest's billing email, typed on the page, reaches the start while
// AgeCheck offers the returning-guest code (TAX-1839; GetIntegrationInfo is
// flushed through --setup-cmd, tests/wp-env/w3b-setup.php).
//
// --outage-hold (TAX-1841; run tests/wp-env/outage-fallback-setup.php first,
// so the store takes and holds orders while AgeCheck is down): only the hold
// checks run. With the mock's links on and a display name, a warm-up start
// records that AgeCheck emails links (the fallback needs that evidence);
// then AgeCheck is down (the mock's down knob) and Place order, on block and
// classic checkout, opens no modal, shows the hold copy in the polite status
// and places the order, which is held ("Awaiting age check") and says on the
// order-received page why. Set the setting back afterwards
// (wp option update navexa_agecheck_outage_fallback refuse).
//
// --turnstile-interactive: the site runs Turnstile with Cloudflare's
// force-interactive test site key (3x00000000000000000000FF) and the
// always-pass secret (1x0000000000000000000000000000000AA), set beforehand;
// only the interactive-challenge checks run, on block and classic checkout:
// Place order opens no modal and sends nothing to the store, the shopper is
// told to complete the check shown and the focus is on it, and once it is
// solved the next Place order opens the modal and the order is placed. Needs
// challenges.cloudflare.com from the browser and from WordPress.

import assert from "node:assert/strict"
import { spawn } from "node:child_process"
import { chromium, firefox, webkit } from "playwright-core"

function arg(name, fallback) {
  const i = process.argv.indexOf(name)
  return i >= 0 ? process.argv[i + 1] : fallback
}

const SITE = arg("--site", "http://localhost:8888").replace(/\/+$/, "")
const MOCK = arg("--mock", "http://127.0.0.1:8787").replace(/\/+$/, "")
const PRODUCT = Number(arg("--product", "0"))
const EXEMPT = Number(arg("--exempt", "0"))
const FREE = Number(arg("--free-product", "0"))
const BLOCK = Number(arg("--block", "0"))
const CLASSIC = Number(arg("--classic", "0"))
const BROWSERS = arg("--browsers", "chromium,webkit,firefox").split(",")
const ONLY = arg("--only", "")
const TURNSTILE_INTERACTIVE = process.argv.includes("--turnstile-interactive")
const OUTAGE_HOLD = process.argv.includes("--outage-hold")
const SETUP = arg(
  "--setup-cmd",
  "npx --yes @wordpress/env@12.1.0 run cli --env-cwd=wp-content/plugins/navexa-agecheck-for-woocommerce wp eval-file tests/wp-env/w3b-setup.php",
)
const HOLD_COPY = /Age verification is unavailable right now\. You can place your order/
const ENGINES = { chromium, firefox, webkit }
const LAUNCH = { chromium: { args: ["--test-third-party-cookie-phaseout"] }, firefox: {}, webkit: {} }

const ADDRESS = {
  first_name: "Pat",
  last_name: "Doe",
  address_1: "123 Main St",
  address_2: "",
  city: "Springfield",
  state: "IL",
  postcode: "62701",
  country: "US",
  email: "pat@example.com",
  phone: "5555550100",
}

const BLOCK_POPUPS = () => {
  window.open = () => null
}

/**
 * Another plugin that refuses Place order from a generic checkout_place_order
 * handler, bound before the glue (as jQuery ready handlers are: the glue
 * binds at DOMContentLoaded). window.__other.refuses switches it off.
 */
const OTHER_PLUGIN = () => {
  document.addEventListener("DOMContentLoaded", () => {
    const form = document.querySelector("form.checkout")
    if (!form || !window.jQuery) return
    const other = { calls: 0, refuses: true, fn: null }
    other.fn = () => {
      other.calls++
      return other.refuses ? false : undefined
    }
    window.__other = other
    window.jQuery(form).on("checkout_place_order", other.fn)
  })
}

/** Whether the other plugin's handler is bound first and the glue's after it. */
function otherBoundFirst(page) {
  return page.evaluate(() => {
    const handlers = window.jQuery._data(document.querySelector("form.checkout"), "events")?.checkout_place_order ?? []
    return handlers.length >= 2 && handlers[0].handler === window.__other?.fn
  })
}

/** Counts WooCommerce's checkout POSTs (the order being placed). */
function countCheckoutPosts(page) {
  const posts = { n: 0 }
  page.on("request", (req) => {
    if (req.method() === "POST" && req.url().includes("wc-ajax=checkout")) posts.n++
  })
  return posts
}

const MODAL = '[data-navexa-agecheck="verify"] .nac-modal'
const FRAME = `${MODAL} iframe`
const PLACE_BLOCK = ".wc-block-components-checkout-place-order-button"

async function mock(path, body) {
  const res = await fetch(`${MOCK}/__mock/${path}`, { method: "POST", body: JSON.stringify(body ?? {}) })
  assert.equal(res.status, 200)
}

async function requests(rpc) {
  return (await (await fetch(`${MOCK}/__mock/requests`)).json()).filter((r) => r.rpc === rpc).length
}

async function embedPages() {
  return (await (await fetch(`${MOCK}/__mock/verifications`)).json()).embedPages ?? []
}

/** The bodies of the mock's recorded calls of one RPC, oldest first. */
async function calls(rpc) {
  return (await (await fetch(`${MOCK}/__mock/requests`)).json()).filter((r) => r.rpc === rpc).map((r) => r.body)
}

/**
 * Runs tests/wp-env/w3b-setup.php with these arguments (asynchronously, as
 * tests/wp-env/w3b.mjs does); its JSON answer.
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

/**
 * Records, across navigations, every text the age check status and the live
 * region showed (with the region's aria-live) and whether the modal ever
 * opened, so a check that submits at once can still be asserted after the
 * page has moved on to order-received.
 */
async function watchStatus(context) {
  const seen = { texts: [], modal: false }
  await context.exposeBinding("__navexaSeen", (_source, kind, text, live) => {
    if (kind === "modal") seen.modal = true
    else seen.texts.push({ kind, text, live })
  })
  await context.addInitScript(() => {
    const report = () => {
      for (const [kind, sel] of [["status", ".navexa-agecheck__message"], ["live", "#navexa-agecheck-live"]]) {
        const el = document.querySelector(sel)
        const text = el?.textContent?.trim() ?? ""
        if (text && el.__navexaLast !== text) {
          el.__navexaLast = text
          const region = el.closest("[aria-live]")
          window.__navexaSeen(kind, text, region?.getAttribute("aria-live") ?? "")
        }
      }
      if (document.querySelector('[data-navexa-agecheck="verify"] .nac-modal')) window.__navexaSeen("modal", "", "")
    }
    new MutationObserver(report).observe(document, { subtree: true, childList: true, characterData: true, attributes: true })
  })
  return seen
}

/** Fills the cart and the session's addresses through the Store API, in the browser's session. */
async function prepareCart(context, address = ADDRESS, product = PRODUCT, method = "flat_rate") {
  const cart = await context.request.get(`${SITE}/wp-json/wc/store/v1/cart`)
  const headers = { Nonce: cart.headers()["nonce"], "Content-Type": "application/json" }
  const added = await context.request.post(`${SITE}/wp-json/wc/store/v1/cart/add-item`, { headers, data: { id: product, quantity: 1 } })
  assert.equal(added.status(), 201)
  const updated = await context.request.post(`${SITE}/wp-json/wc/store/v1/cart/update-customer`, { headers, data: { billing_address: address, shipping_address: address } })
  assert.equal(updated.status(), 200)
  const rate = ((await updated.json()).shipping_rates?.[0]?.shipping_rates ?? []).find((r) => r.method_id === method)
  if (rate) {
    const chosen = await context.request.post(`${SITE}/wp-json/wc/store/v1/cart/select-shipping-rate`, { headers, data: { package_id: 0, rate_id: rate.rate_id } })
    assert.equal(chosen.status(), 200)
  }
}

/**
 * Holds the page's Store API cart/extensions calls (the start and the
 * refresh) back for ms, so the busy phase can be observed; then hands them
 * to the context's route (its client IP header).
 */
async function delayStarts(page, ms) {
  await page.route(
    (url) => decodeURIComponent(url.toString()).includes("/cart/extensions"),
    async (route) => {
      await new Promise((resolve) => setTimeout(resolve, ms))
      await route.fallback().catch(() => {})
    },
  )
}

let nextIp = 1
/**
 * A browser context with its own client IP for the store: prepayment-setup.php
 * trusts X-Real-IP, and the start limit is 20 an hour per IP. Added only to
 * the store's requests, never to the hosted page's.
 */
async function newContext(browser, init) {
  const context = await browser.newContext()
  const ip = `10.77.${Math.floor(nextIp / 250)}.${(nextIp++ % 250) + 1}`
  await context.route(
    (url) => url.toString().startsWith(SITE),
    (route) => route.continue({ headers: { ...route.request().headers(), "x-real-ip": ip } }),
  )
  if (init) await context.addInitScript(init)
  return context
}

const results = []
let failures = 0
async function check(name, fn) {
  if (ONLY && !name.includes(ONLY)) return
  try {
    const note = await fn()
    console.log(`ok   ${name}${note ? ` (${note})` : ""}`)
  } catch (err) {
    failures++
    console.log(`FAIL ${name}\n     ${String(err?.message ?? err).split("\n").slice(0, 6).join("\n     ")}`)
  }
}

/** Main-frame URLs the page visits (it must stay on checkout until order-received). */
function trackNavigation(page) {
  const urls = []
  page.on("framenavigated", (frame) => {
    if (frame === page.mainFrame()) urls.push(frame.url())
  })
  return urls
}

async function openClassic(context) {
  const page = await context.newPage()
  const urls = trackNavigation(page)
  await page.goto(`${SITE}/?page_id=${CLASSIC}`)
  await page.waitForSelector('[data-navexa-agecheck="1"]', { timeout: 20000 })
  await page.waitForFunction(() => !document.querySelector(".blockUI"), null, { timeout: 20000 })
  await page.check("#terms").catch(() => {})
  return { page, urls }
}

async function openBlock(context) {
  const page = await context.newPage()
  const urls = trackNavigation(page)
  await page.goto(`${SITE}/?page_id=${BLOCK}`)
  try {
    await page.locator('.navexa-agecheck[data-navexa-agecheck="1"]').waitFor({ timeout: 60000 })
  } catch (err) {
    const state = await page.evaluate(() => ({
      url: location.href,
      cart: window.wp?.data?.select("wc/store/cart")?.getCartData?.()?.extensions?.["navexa-agecheck"] ?? null,
      items: window.wp?.data?.select("wc/store/cart")?.getCartData?.()?.items?.length ?? null,
      notices: [...document.querySelectorAll(".wc-block-components-notice-banner")].map((n) => n.textContent.trim()).slice(0, 3),
    })).catch(() => null)
    throw new Error(`${err.message.split("\n")[0]}; page state: ${JSON.stringify(state)}`)
  }
  return { page, urls }
}

/**
 * Clicks Cloudflare's checkbox in the Turnstile iframe (it may sit in a closed
 * shadow root) until Turnstile writes its token into the status container's
 * response field; the checkbox can take a moment to become clickable. The
 * frame is scrolled into view first: the block checkout scrolls up to its
 * error notice.
 */
async function solveTurnstile(page) {
  const solved = () => page.evaluate(() => (document.querySelector(".navexa-agecheck__turnstile [name='cf-turnstile-response']")?.value ?? "") !== "")
  for (let attempt = 1; attempt <= 6; attempt++) {
    const frame = page.frames().find((f) => f.url().includes("challenges.cloudflare.com"))
    const element = frame ? await frame.frameElement().catch(() => null) : null
    if (element) await element.scrollIntoViewIfNeeded().catch(() => {})
    const box = element ? await element.boundingBox().catch(() => null) : null
    if (box && box.width > 0) await page.mouse.click(box.x + 30, box.y + box.height / 2)
    for (let i = 0; i < 10; i++) {
      if (await solved()) return `click ${attempt}`
      await page.waitForTimeout(500)
    }
  }
  throw new Error("the Turnstile challenge was not solved")
}

/**
 * Waits for the keyboard focus on the block checkout's Place order: the glue
 * hands it back once WooCommerce enables the button again.
 */
async function focusOnPlaceOrder(page, when) {
  await page.waitForFunction((sel) => !!document.activeElement?.closest?.(sel), PLACE_BLOCK, { timeout: 10000 }).catch(async () => {
    const at = await page.evaluate(() => document.activeElement?.tagName.toLowerCase() ?? "none").catch(() => "unknown")
    throw new Error(`the focus goes back to Place order ${when}; it is on ${at}`)
  })
}

/** Waits for the modal's frame, checks it is the embedded page on the mock (cross-site), and answers in it. */
async function answerInFrame(page, decision) {
  await page.waitForSelector(FRAME, { timeout: 30000 })
  const src = await page.locator(FRAME).getAttribute("src")
  const url = new URL(src)
  assert.equal(url.origin, new URL(MOCK).origin, "the frame is AgeCheck's page on the hosted origin")
  assert.notEqual(url.origin, new URL(SITE).origin, "cross-site frame")
  assert.equal(url.searchParams.get("embed"), "1")
  assert.equal(url.searchParams.get("parent_origin"), SITE)
  const frame = page.frameLocator(FRAME)
  await frame.locator(`#mock-${decision}`).click({ timeout: 20000 })
  return src
}

for (const name of BROWSERS) {
  const browser = await ENGINES[name].launch(LAUNCH[name])
  try {
    await mock("reset")
    await mock("config", { hostedBase: MOCK, org: { allowedOrigins: [SITE] } })

    if (TURNSTILE_INTERACTIVE) {
      for (const [checkout, open, place, notice] of [
        ["block", openBlock, PLACE_BLOCK, ".wc-block-components-notice-banner.is-error"],
        ["classic", openClassic, "#place_order", "[data-navexa-agecheck-notice]"],
      ]) {
        await check(`${name}: ${checkout} checkout, Turnstile interactive challenge: Place order opens no modal, the shopper is told and focused on the challenge; solved, the next Place order opens the modal and places the order`, async () => {
          const context = await newContext(browser)
          await prepareCart(context, { ...ADDRESS, email: `turnstile-${checkout}@example.com` })
          const { page } = await open(context)
          const creates = await requests("CreateVerification")
          const sessions = await requests("CreateHostedSession")
          const started = Date.now()
          await page.click(place)
          await page.waitForFunction((sel) => /Complete the security check shown/.test(document.querySelector(sel)?.textContent ?? ""), notice, { timeout: 30000 })
          const refusedAfter = Date.now() - started
          assert.ok(refusedAfter < 30000, `refused in ${refusedAfter} ms, not after the token timeout`)
          await page.waitForFunction(() => !!document.activeElement?.classList?.contains("navexa-agecheck__turnstile"), null, { timeout: 5000 }).catch(() => {
            throw new Error("the focus is on the Security check group")
          })
          const group = await page.evaluate(() => ({ role: document.activeElement?.getAttribute("role"), label: document.activeElement?.getAttribute("aria-label") }))
          assert.deepEqual(group, { role: "group", label: "Security check" })
          if (checkout === "classic") {
            await page.waitForFunction(() => /Complete the security check shown/.test(document.getElementById("navexa-agecheck-live")?.textContent ?? ""), null, { timeout: 10000 })
          }
          assert.equal(await page.locator(MODAL).count(), 0, "no modal")
          assert.equal(await requests("CreateVerification"), creates, "nothing reached AgeCheck")
          assert.equal(await requests("CreateHostedSession"), sessions, "nothing reached AgeCheck")
          assert.ok(!/order-received/.test(page.url()))
          const how = await solveTurnstile(page)
          await page.click(place)
          await answerInFrame(page, "pass")
          await page.waitForURL(/order-received/, { timeout: 45000 })
          await context.close()
          return `refused in ${refusedAfter} ms; challenge solved by ${how}`
        })
      }
      continue
    }

    if (OUTAGE_HOLD) {
      // The fallback needs AgeCheck's word that it emails links: flush the
      // cached GetIntegrationInfo, then one start with the engine up asks it.
      await mock("config", { links: "on", org: { allowedOrigins: [SITE], displayName: "Mock Store" } })
      await setup("mode", "prepayment")
      const warm = await newContext(browser)
      await prepareCart(warm, { ...ADDRESS, email: "outage-warm@example.com" })
      const cart = await warm.request.get(`${SITE}/wp-json/wc/store/v1/cart`)
      const started = await warm.request.post(`${SITE}/wp-json/wc/store/v1/cart/extensions`, {
        headers: { Nonce: cart.headers()["nonce"], "Content-Type": "application/json" },
        data: { namespace: "navexa-agecheck", data: { v: 1, action: "start" } },
      })
      assert.equal(started.status(), 200)
      await warm.close()
      for (const [checkout, open, place] of [
        ["block", openBlock, PLACE_BLOCK],
        ["classic", openClassic, "#place_order"],
      ]) {
        await check(`${name}: ${checkout} checkout, AgeCheck down, the store takes and holds orders: Place order opens no modal, shows the hold copy politely and places the order, held`, async () => {
          const context = await newContext(browser)
          const seen = await watchStatus(context)
          await prepareCart(context, { ...ADDRESS, email: `outage-${checkout}@example.com` })
          const { page } = await open(context)
          await mock("config", { down: true })
          try {
            await page.click(place)
            await page.waitForURL(/order-received/, { timeout: 45000 })
          } finally {
            await mock("config", { down: false })
          }
          assert.equal(seen.modal, false, "no modal")
          const held = seen.texts.filter((t) => HOLD_COPY.test(t.text))
          assert.ok(held.length > 0, `the hold copy was shown: ${JSON.stringify(seen.texts)}`)
          // Block: the status line is the polite region. Classic: the status sits in the
          // fragment WooCommerce replaces (no region); the glue announces in its own one.
          const region = held.filter((t) => t.kind === (checkout === "classic" ? "live" : "status"))
          assert.ok(region.length > 0 && region.every((t) => t.live === "polite"), `announced politely: ${JSON.stringify(held)}`)
          await page.waitForSelector("text=Age verification was unavailable when you placed this order", { timeout: 20000 })
          const id = Number((/order-received[=/](\d+)/.exec(page.url()) ?? [])[1] ?? 0)
          assert.ok(id > 0, `an order id in ${page.url()}`)
          const order = await setup("order", String(id))
          assert.equal(order.status, "agecheck-hold", "held until the shopper verifies")
          assert.equal(order.vid, "", "no verification bound: verified by link after payment")
          await context.close()
          return `order ${id} ${order.status}`
        })
      }
      continue
    }

    await check(`${name}: block checkout: Place order opens the modal, a pass in the cross-site frame places the order without leaving checkout`, async () => {
      const context = await newContext(browser)
      await prepareCart(context, { ...ADDRESS, email: "block-pass@example.com" })
      const { page, urls } = await openBlock(context)
      const creates = await requests("CreateVerification")
      assert.match(await page.locator(".navexa-agecheck__message").textContent(), /When you place it/)
      assert.equal(await page.locator(".navexa-agecheck button").count(), 0, "no Verify age button")
      await page.click(PLACE_BLOCK)
      await page.waitForSelector(MODAL, { timeout: 30000 })
      const dialog = await page.locator(MODAL).evaluate((el) => ({ role: el.getAttribute("role"), modal: el.getAttribute("aria-modal"), labelled: !!document.getElementById(el.getAttribute("aria-labelledby") ?? "") }))
      assert.deepEqual(dialog, { role: "dialog", modal: "true", labelled: true })
      await answerInFrame(page, "pass")
      await page.waitForURL(/order-received/, { timeout: 45000 })
      const before = urls.slice(0, urls.findIndex((u) => /order-received/.test(u)))
      assert.ok(before.length > 0 && before.every((u) => u === before[0] || u.startsWith(`${SITE}/?page_id=${BLOCK}`) || u.startsWith(`${SITE}/checkout`)), `stayed on checkout: ${before.join(" ")}`)
      assert.ok(before.every((u) => !u.startsWith(MOCK)), "never on the hosted page")
      assert.equal((await requests("CreateVerification")) - creates, 1, "one verification")
      await context.close()
    })

    await check(`${name}: classic checkout: Place order opens the modal, a pass places the order in one click`, async () => {
      const context = await newContext(browser)
      await prepareCart(context, { ...ADDRESS, email: "classic-pass@example.com" })
      const { page, urls } = await openClassic(context)
      const creates = await requests("CreateVerification")
      assert.equal(await page.locator("[data-navexa-agecheck-verify]").count(), 0, "no Verify age button")
      await page.click("#place_order")
      await answerInFrame(page, "pass")
      await page.waitForURL(/order-received/, { timeout: 45000 })
      const before = urls.slice(0, urls.findIndex((u) => /order-received/.test(u)))
      assert.ok(before.length > 0 && before.every((u) => u === before[0] || u.includes(`page_id=${CLASSIC}`)), `stayed on checkout: ${before.join(" ")}`)
      assert.ok(before.every((u) => !u.startsWith(MOCK)), "never on the hosted page")
      assert.equal((await requests("CreateVerification")) - creates, 1, "one verification")
      await context.close()
    })

    await check(`${name}: classic checkout: a fail in the frame places no order, the error is shown, announced and focused`, async () => {
      const context = await newContext(browser)
      await prepareCart(context, { ...ADDRESS, email: "classic-fail@example.com" })
      const { page } = await openClassic(context)
      await page.click("#place_order")
      await answerInFrame(page, "fail")
      await page.waitForSelector('[data-navexa-agecheck-notice] [role="alert"]', { timeout: 30000 })
      const text = await page.locator("[data-navexa-agecheck-notice]").textContent()
      assert.match(text, /could not verify your age/)
      await page.waitForFunction(() => /could not verify your age/.test(document.getElementById("navexa-agecheck-live")?.textContent ?? ""), null, { timeout: 10000 })
      await page.waitForFunction(() => !!document.activeElement?.closest?.("[data-navexa-agecheck-notice]"), null, { timeout: 5000 })
      assert.ok(!/order-received/.test(page.url()))
      assert.equal(await page.locator(MODAL).count(), 0, "the modal is gone")
      await context.close()
    })

    await check(`${name}: block checkout: Enter on Place order opens the modal, Escape closes it, no order and it says so; Place order again reuses the verification and passes`, async () => {
      const context = await newContext(browser)
      await prepareCart(context, { ...ADDRESS, email: "block-close@example.com" })
      const { page } = await openBlock(context)
      const creates = await requests("CreateVerification")
      const sessions = await requests("CreateHostedSession")
      await page.focus(PLACE_BLOCK)
      await page.keyboard.press("Enter")
      await page.waitForSelector(FRAME, { timeout: 30000 })
      await page.waitForFunction((sel) => document.activeElement?.closest?.(sel) !== null, MODAL, { timeout: 10000 })
      await page.keyboard.press("Escape")
      await page.waitForSelector(".wc-block-components-notice-banner.is-error", { timeout: 30000 })
      assert.match(await page.locator(".wc-block-components-notice-banner.is-error").first().textContent(), /closed before it finished/)
      assert.equal(await page.locator(MODAL).count(), 0)
      await focusOnPlaceOrder(page, "after Escape")
      await page.click(PLACE_BLOCK)
      await answerInFrame(page, "pass")
      await page.waitForURL(/order-received/, { timeout: 45000 })
      assert.equal((await requests("CreateVerification")) - creates, 1, "one verification for both attempts")
      assert.equal((await requests("CreateHostedSession")) - sessions, 2, "two hosted sessions")
      await context.close()
    })

    await check(`${name}: block checkout: the modal's close button, then a fail in the frame: no order, each says so, and the focus goes back to Place order both times`, async () => {
      const context = await newContext(browser)
      await prepareCart(context, { ...ADDRESS, email: "block-close-fail@example.com" })
      const { page } = await openBlock(context)
      const notices = ".wc-block-components-notice-banner.is-error"
      const shows = (text) => page.waitForFunction(([sel, re]) => [...document.querySelectorAll(sel)].some((n) => new RegExp(re).test(n.textContent ?? "")), [notices, text], { timeout: 30000 })
      await page.focus(PLACE_BLOCK)
      await page.keyboard.press("Enter")
      await page.waitForSelector(FRAME, { timeout: 30000 })
      await page.click(`${MODAL} .nac-x`)
      await shows("closed before it finished")
      await focusOnPlaceOrder(page, "after the close button")
      // From the keyboard again: Enter on the focused Place order.
      await page.keyboard.press("Enter")
      await answerInFrame(page, "fail")
      await shows("could not verify your age")
      assert.equal(await page.locator(MODAL).count(), 0, "the modal is gone")
      await focusOnPlaceOrder(page, "after a fail")
      assert.ok(!/order-received/.test(page.url()))
      await context.close()
    })

    await check(`${name}: classic checkout: keyboard: Enter on Place order opens the modal with the focus trapped in it; its close button ends the attempt with the closed message announced and focused`, async () => {
      const context = await newContext(browser)
      await prepareCart(context, { ...ADDRESS, email: "classic-close@example.com" })
      const { page } = await openClassic(context)
      await page.focus("#place_order")
      await page.keyboard.press("Enter")
      await page.waitForSelector(FRAME, { timeout: 30000 })
      await page.keyboard.press("Tab")
      await page.waitForFunction((sel) => !!document.activeElement?.closest?.(sel), MODAL, { timeout: 5000 }).catch(() => {
        throw new Error("the focus stays inside the modal (focus trap)")
      })
      await page.click(`${MODAL} .nac-x`)
      await page.waitForSelector("[data-navexa-agecheck-notice]", { timeout: 30000 })
      await page.waitForFunction(() => !!document.activeElement?.closest?.("[data-navexa-agecheck-notice]"), null, { timeout: 5000 }).catch(() => {
        throw new Error("the focus moves to the closed message in the checkout notices (the glue's refusal rule)")
      })
      assert.match(await page.locator("[data-navexa-agecheck-notice]").textContent(), /closed before it finished/)
      await page.waitForFunction(() => /closed before it finished/.test(document.getElementById("navexa-agecheck-live")?.textContent ?? ""), null, { timeout: 10000 })
      const inert = await page.evaluate(() => document.querySelector("form.checkout")?.closest("[inert]") !== null)
      assert.equal(inert, false, "the page is interactive again")
      await context.close()
    })

    await check(`${name}: a refused frame (frame-ancestors 'none') offers a new window from a click in the modal; the popup passes and the order is placed`, async () => {
      await mock("config", { embed: "refuse" })
      try {
        const context = await newContext(browser)
        await prepareCart(context, { ...ADDRESS, email: "popup@example.com" })
        const { page } = await openClassic(context)
        await page.click("#place_order")
        const button = page.locator(`${MODAL} .nac-btn`)
        await button.waitFor({ state: "visible", timeout: 30000 })
        assert.match(await button.textContent(), /Continue in a new window/)
        const popupPromise = context.waitForEvent("page", { timeout: 15000 })
        await button.click()
        const popup = await popupPromise
        await popup.waitForSelector("text=Mock AgeCheck", { timeout: 20000 })
        assert.ok(!popup.url().includes("embed=1"), "the window gets the plain page")
        await popup.click("button[value=pass]")
        await page.waitForURL(/order-received/, { timeout: 45000 })
        if (!popup.isClosed()) await popup.close()
        await context.close()
      } finally {
        await mock("config", { embed: "serve" })
      }
    })

    await check(`${name}: a blocked popup too: the link loads the check in this tab, the return page brings the shopper back to checkout passed, and Place order goes through with no modal`, async () => {
      await mock("config", { embed: "refuse" })
      try {
        const context = await newContext(browser, BLOCK_POPUPS)
        await prepareCart(context, { ...ADDRESS, email: "redirect@example.com" })
        const { page } = await openBlock(context)
        await page.click(PLACE_BLOCK)
        await page.locator(`${MODAL} .nac-btn`).waitFor({ state: "visible", timeout: 30000 })
        await page.click(`${MODAL} .nac-btn`)
        const link = page.locator(`${MODAL} .nac-link`)
        await link.waitFor({ state: "visible", timeout: 10000 })
        await link.click()
        await page.waitForURL(/\/v\//, { timeout: 20000 })
        assert.ok(!page.url().includes("embed=1"), "the plain page")
        await page.click("button[value=pass]")
        // The return page sends a redirected tab back to WooCommerce's checkout page.
        await page.waitForURL((u) => u.toString().startsWith(SITE) && !u.toString().includes("wc-api"), { timeout: 30000 })
        await page.waitForSelector('.navexa-agecheck[data-status="passed"]', { timeout: 30000 })
        const sessions = await requests("CreateHostedSession")
        await page.click(PLACE_BLOCK)
        await page.waitForURL(/order-received/, { timeout: 45000 })
        assert.equal(await requests("CreateHostedSession"), sessions, "no modal: the check had passed")
        await context.close()
      } finally {
        await mock("config", { embed: "serve" })
      }
    })

    await check(`${name}: classic checkout, a cart that needs no check: another plugin's false from a checkout_place_order handler bound before the glue still stops WooCommerce`, async () => {
      assert.ok(EXEMPT > 0, "--exempt <exempt product id> is required")
      const context = await newContext(browser, OTHER_PLUGIN)
      await prepareCart(context, { ...ADDRESS, email: "other-plugin-exempt@example.com" }, EXEMPT)
      const page = await context.newPage()
      const posts = countCheckoutPosts(page)
      await page.goto(`${SITE}/?page_id=${CLASSIC}`)
      await page.waitForSelector("#place_order", { timeout: 20000 })
      await page.waitForFunction(() => !document.querySelector(".blockUI"), null, { timeout: 20000 })
      const setup = await page.evaluate(() => ({ glue: !!window.navexaAgeCheckClassic, container: !!document.querySelector('[data-navexa-agecheck="1"]') }))
      assert.deepEqual(setup, { glue: true, container: false }, "the glue loads, the cart needs no check")
      assert.equal(await otherBoundFirst(page), true, "the other handler is bound before the glue's")
      await page.check("#payment_method_cod").catch(() => {})
      await page.waitForFunction(() => !document.querySelector(".blockUI"), null, { timeout: 20000 })
      await page.check("#terms").catch(() => {})
      await page.click("#place_order")
      await page.waitForFunction(() => window.__other.calls > 0, null, { timeout: 10000 })
      await page.waitForTimeout(1500)
      assert.equal(posts.n, 0, "no checkout POST: the glue must not answer true over the other handler's false")
      assert.ok(!/order-received/.test(page.url()))
      await page.evaluate(() => {
        window.__other.refuses = false
      })
      await page.click("#place_order")
      await page.waitForURL(/order-received/, { timeout: 45000 })
      assert.equal(posts.n, 1, "control: the order is placed once the other handler stops refusing")
      await context.close()
    })

    await check(`${name}: classic checkout: another plugin's false still stops the resubmit after a pass; with it gone the next Place order goes through with no second check`, async () => {
      const context = await newContext(browser, OTHER_PLUGIN)
      await prepareCart(context, { ...ADDRESS, email: "other-plugin-pass@example.com" })
      const { page } = await openClassic(context)
      assert.equal(await otherBoundFirst(page), true, "the other handler is bound before the glue's")
      const posts = countCheckoutPosts(page)
      await page.click("#place_order")
      await answerInFrame(page, "pass")
      await page.waitForSelector('[data-navexa-agecheck="1"][data-status="passed"]', { timeout: 30000 })
      await page.waitForFunction(() => /order-received/.test(location.href) || ((window.__other?.calls ?? 0) >= 2 && !document.querySelector(".blockUI")), null, { timeout: 30000 })
      await page.waitForTimeout(1500)
      assert.equal(posts.n, 0, "the resubmit past the bypass is still refused by the other handler")
      const sessions = await requests("CreateHostedSession")
      await page.evaluate(() => {
        window.__other.refuses = false
      })
      await page.click("#place_order")
      await page.waitForURL(/order-received/, { timeout: 45000 })
      assert.equal(await requests("CreateHostedSession"), sessions, "no second check: the session had passed")
      await context.close()
    })

    await check(`${name}: classic checkout: an order the server refuses while the page says passed brings the status back from the server, and the next Place order opens the modal (there is no Verify age button to fall back on)`, async () => {
      const context = await newContext(browser, OTHER_PLUGIN)
      await prepareCart(context, { ...ADDRESS, email: "classic-refused@example.com" })
      const { page } = await openClassic(context)
      const posts = countCheckoutPosts(page)
      // A pass whose resubmit another handler stops (as a payment form left incomplete does): the page says passed, no order.
      await page.click("#place_order")
      await answerInFrame(page, "pass")
      await page.waitForSelector('[data-navexa-agecheck="1"][data-status="passed"]', { timeout: 30000 })
      await page.waitForFunction(() => (window.__other?.calls ?? 0) >= 2 && !document.querySelector(".blockUI"), null, { timeout: 30000 })
      assert.equal(posts.n, 0)
      // The server stops admitting it (here: the posted ship-to is no longer the one checked, edited past the lock).
      await page.evaluate(() => {
        window.__other.refuses = false
        document.querySelector('form.checkout [name="billing_address_1"]').value = "9 Elm St"
      })
      const sessions = await requests("CreateHostedSession")
      await page.click("#place_order")
      await page.waitForFunction(() => /shipping address changed after your age check/.test(document.querySelector(".woocommerce-NoticeGroup-checkout")?.textContent ?? ""), null, { timeout: 30000 })
      assert.equal(posts.n, 1, "the order was posted, and refused")
      assert.equal(await requests("CreateHostedSession"), sessions, "no modal for it: the page said passed")
      // Before the fix the page kept saying passed, and every Place order posted again into the same refusal.
      await page.waitForFunction(() => document.querySelector('[data-navexa-agecheck="1"]')?.getAttribute("data-status") === "none", null, { timeout: 20000 }).catch(() => {
        throw new Error("the status comes back from the server after the refusal (WooCommerce's checkout update)")
      })
      await page.waitForFunction(() => !document.querySelector(".blockUI"), null, { timeout: 20000 })
      assert.match(await page.locator('[data-navexa-agecheck="1"] .navexa-agecheck__message').textContent(), /shipping address changed/)
      await page.click("#place_order")
      await answerInFrame(page, "pass")
      await page.waitForURL(/order-received/, { timeout: 45000 })
      assert.equal(posts.n, 2, "placed on the next attempt")
      assert.equal((await requests("CreateHostedSession")) - sessions, 1, "the next Place order opened the modal")
      await context.close()
    })

    await check(`${name}: classic checkout: a second Place order while the start is in flight starts nothing more: one modal, one hosted session, one order; the container is aria-busy meanwhile and the live region a polite status outside the replaced fragment`, async () => {
      const context = await newContext(browser)
      await prepareCart(context, { ...ADDRESS, email: "classic-busy@example.com" })
      const { page } = await openClassic(context)
      const posts = countCheckoutPosts(page)
      const creates = await requests("CreateVerification")
      const sessions = await requests("CreateHostedSession")
      await delayStarts(page, 3000)
      await page.focus("#place_order")
      await page.keyboard.press("Enter")
      await page.waitForFunction(() => document.querySelector('[data-navexa-agecheck="1"]')?.getAttribute("aria-busy") === "true", null, { timeout: 15000 })
      // Enter again on the focused button under WooCommerce's overlay, and a scripted click (another double-submit path).
      await page.keyboard.press("Enter")
      await page.evaluate(() => document.querySelector("#place_order")?.click())
      await page.waitForSelector(FRAME, { timeout: 30000 })
      await page.waitForTimeout(1000)
      assert.equal(await page.locator(MODAL).count(), 1, "one modal")
      assert.equal((await requests("CreateVerification")) - creates, 1, "one verification")
      assert.equal((await requests("CreateHostedSession")) - sessions, 1, "one hosted session")
      assert.equal(await page.locator('[data-navexa-agecheck="1"]').getAttribute("aria-busy"), "false", "not busy while the shopper is in the modal")
      const region = await page.evaluate(() => {
        const el = document.getElementById("navexa-agecheck-live")
        return { role: el?.getAttribute("role"), live: el?.getAttribute("aria-live"), outside: !el?.closest(".woocommerce-checkout-review-order, #order_review") }
      })
      assert.deepEqual(region, { role: "status", live: "polite", outside: true })
      await answerInFrame(page, "pass")
      await page.waitForURL(/order-received/, { timeout: 45000 })
      assert.equal(posts.n, 1, "one order placed")
      await context.close()
    })

    await check(`${name}: block checkout: a second Place order while the start is in flight starts nothing more: one modal, one hosted session, one order`, async () => {
      const context = await newContext(browser)
      await prepareCart(context, { ...ADDRESS, email: "block-busy@example.com" })
      const { page } = await openBlock(context)
      const creates = await requests("CreateVerification")
      const sessions = await requests("CreateHostedSession")
      const orders = { n: 0 }
      page.on("request", (req) => {
        if (req.method() === "POST" && /\/wc\/store\/v1\/checkout(\?|$)/.test(decodeURIComponent(req.url()))) orders.n++
      })
      await delayStarts(page, 3000)
      await page.click(PLACE_BLOCK)
      await page.waitForTimeout(300)
      const during = await page.evaluate((sel) => {
        const button = document.querySelector(sel)
        button?.click()
        return { disabled: !!button?.disabled, busy: button?.getAttribute("aria-disabled") ?? null }
      }, PLACE_BLOCK)
      await page.waitForSelector(FRAME, { timeout: 30000 })
      await page.waitForTimeout(1000)
      assert.equal(await page.locator(MODAL).count(), 1, "one modal")
      assert.equal((await requests("CreateVerification")) - creates, 1, "one verification")
      assert.equal((await requests("CreateHostedSession")) - sessions, 1, "one hosted session")
      await answerInFrame(page, "pass")
      await page.waitForURL(/order-received/, { timeout: 45000 })
      assert.equal(orders.n, 1, "one checkout POST")
      await context.close()
      return `Place order while the start was in flight: disabled=${during.disabled}`
    })

    if (FREE > 0) {
      for (const [checkout, open, place] of [
        ["block", openBlock, PLACE_BLOCK],
        ["classic", openClassic, "#place_order"],
      ]) {
        await check(`${name}: ${checkout} checkout, a cart that needs no payment: ${checkout === "block" ? "the fallback status block shows with no payment step; " : ""}Place order opens the modal and a pass places the order`, async () => {
          const context = await newContext(browser)
          await prepareCart(context, { ...ADDRESS, email: `free-${checkout}@example.com` }, FREE, "free_shipping")
          const { page } = await open(context)
          if (checkout === "block") {
            assert.equal(await page.locator(".wc-block-checkout__payment-method").count(), 0, "no payment step")
            assert.equal(await page.locator('.navexa-agecheck[data-navexa-agecheck="1"]').count(), 1, "one status block: the fallback")
          } else {
            assert.equal(await page.locator("#payment .wc_payment_methods").count(), 0, "no payment methods")
          }
          const creates = await requests("CreateVerification")
          await page.click(place)
          await answerInFrame(page, "pass")
          await page.waitForURL(/order-received/, { timeout: 45000 })
          assert.equal((await requests("CreateVerification")) - creates, 1, "one verification")
          await context.close()
        })
      }
    }

    /** Classic checkout: Place order with the always-declining test gateway, a pass in the modal, the decline shown. */
    async function passThenDecline(context, email) {
      await prepareCart(context, { ...ADDRESS, email })
      const { page } = await openClassic(context)
      await page.check("#payment_method_navexa_test_decline")
      await page.click("#place_order")
      await answerInFrame(page, "pass")
      await page.waitForSelector(".woocommerce-NoticeGroup-checkout", { timeout: 45000 })
      await page.waitForFunction(() => !document.querySelector(".blockUI"), null, { timeout: 20000 })
      return page
    }

    await check(`${name}: classic checkout: after a pass the name and ship-to lock (read-only, or disabled with a mirror); a declined payment is retried with no second check`, async () => {
      const context = await newContext(browser)
      const page = await passThenDecline(context, "lock@example.com")
      const lock = await page.evaluate(() => {
        const f = (n) => document.querySelector(`form.checkout [name="${n}"]:not([data-navexa-agecheck-mirror])`)
        const mirror = (n) => document.querySelector(`form.checkout [data-navexa-agecheck-mirror][name="${n}"]`)?.value ?? null
        return {
          first: f("billing_first_name")?.readOnly,
          address: f("billing_address_1")?.readOnly,
          country: f("billing_country")?.disabled,
          countryMirror: mirror("billing_country"),
          email: f("billing_email")?.readOnly,
          bar: getComputedStyle(document.querySelector("[data-navexa-agecheck-lock]")).display !== "none",
          status: document.querySelector('[data-navexa-agecheck="1"]')?.getAttribute("data-status"),
        }
      })
      assert.deepEqual(lock, { first: true, address: true, country: true, countryMirror: "US", email: false, bar: true, status: "passed" })
      const creates = await requests("CreateVerification")
      await page.check("#payment_method_cod")
      await page.waitForFunction(() => !document.querySelector(".blockUI"), null, { timeout: 20000 })
      await page.check("#terms").catch(() => {})
      await page.click("#place_order")
      await page.waitForURL(/order-received/, { timeout: 45000 })
      assert.equal(await requests("CreateVerification"), creates, "no second check: the posted (mirrored) values placed the bound order")
      await context.close()
    })

    await check(`${name}: classic checkout: a devtools edit of a locked field is refused by the server; the checkout update after the refusal unlocks the fields, and the next Place order opens the modal`, async () => {
      const context = await newContext(browser)
      const page = await passThenDecline(context, "devtools@example.com")
      await page.check("#payment_method_cod")
      await page.waitForFunction(() => !document.querySelector(".blockUI"), null, { timeout: 20000 })
      await page.evaluate(() => {
        document.querySelector('form.checkout [name="billing_address_1"]').value = "9 Elm St"
      })
      const sessions = await requests("CreateHostedSession")
      await page.check("#terms").catch(() => {})
      await page.click("#place_order")
      await page.waitForFunction(() => /shipping address changed after your age check/.test(document.querySelector(".woocommerce-NoticeGroup-checkout")?.textContent ?? ""), null, { timeout: 30000 })
      assert.equal(await requests("CreateHostedSession"), sessions, "no modal: the server refused the edit")
      assert.ok(!/order-received/.test(page.url()))
      // No Change details and no update by hand: the refusal has WooCommerce update the checkout.
      await page.waitForFunction(() => document.querySelector('[data-navexa-agecheck="1"]')?.getAttribute("data-status") === "none", null, { timeout: 20000 })
      await page.waitForFunction(() => !document.querySelector(".blockUI"), null, { timeout: 20000 })
      const unlocked = await page.evaluate(() => ({
        address: document.querySelector('form.checkout [name="billing_address_1"]')?.readOnly,
        mirrors: document.querySelectorAll("[data-navexa-agecheck-mirror]").length,
        bar: getComputedStyle(document.querySelector("[data-navexa-agecheck-lock]")).display !== "none",
      }))
      assert.deepEqual(unlocked, { address: false, mirrors: 0, bar: false })
      await page.click("#place_order")
      await answerInFrame(page, "pass")
      await page.waitForURL(/order-received/, { timeout: 45000 })
      assert.equal((await requests("CreateHostedSession")) - sessions, 1, "the next Place order opened the modal")
      await context.close()
    })

    await check(`${name}: classic checkout: a new ship-to after Change details opens the modal again`, async () => {
      const context = await newContext(browser)
      const page = await passThenDecline(context, "move@example.com")
      await page.click("[data-navexa-agecheck-unlock]")
      await page.waitForFunction(() => /confirm your age again/.test(document.getElementById("navexa-agecheck-live")?.textContent ?? ""), null, { timeout: 10000 })
      await page.fill('form.checkout [name="billing_address_1"]', "9 Elm St")
      await page.evaluate(() => window.jQuery(document.body).trigger("update_checkout"))
      await page.waitForFunction(() => document.querySelector('[data-navexa-agecheck="1"]')?.getAttribute("data-status") === "none", null, { timeout: 20000 })
      await page.waitForFunction(() => !document.querySelector(".blockUI"), null, { timeout: 20000 })
      await page.check("#payment_method_cod")
      await page.waitForFunction(() => !document.querySelector(".blockUI"), null, { timeout: 20000 })
      await page.click("#place_order")
      await answerInFrame(page, "pass")
      await page.waitForURL(/order-received/, { timeout: 45000 })
      await context.close()
    })

    await check(`${name}: block checkout: a declined payment keeps the check (address cards after the pass), the retry places the order with no second modal`, async () => {
      const context = await newContext(browser)
      await prepareCart(context, { ...ADDRESS, email: "block-retry@example.com" })
      const { page } = await openBlock(context)
      await page.click('label[for="radio-control-wc-payment-method-options-navexa_test_decline"]')
      await page.click(PLACE_BLOCK)
      await answerInFrame(page, "pass")
      await page.waitForSelector(".wc-block-components-notice-banner.is-error", { timeout: 45000 })
      await page.waitForSelector('.navexa-agecheck[data-status="passed"]', { timeout: 30000 })
      const cards = await page.locator(".wc-block-components-address-card").count()
      const creates = await requests("CreateVerification")
      await page.click('label[for="radio-control-wc-payment-method-options-cod"]')
      await page.click(PLACE_BLOCK)
      await page.waitForURL(/order-received/, { timeout: 45000 })
      assert.equal(await requests("CreateVerification"), creates, "no second check")
      results.push({ browser: name, addressCardsAfterPass: cards })
      await context.close()
      return `${cards} address card(s) shown after the pass`
    })

    for (const [checkout, open, place, notice] of [
      ["block", openBlock, PLACE_BLOCK, ".wc-block-components-notice-banner.is-error"],
      ["classic", openClassic, "#place_order", "[data-navexa-agecheck-notice]"],
    ]) {
      await check(`${name}: ${checkout} checkout: after a fail in the frame, the next Place order opens a new check in the modal, and a pass places the order`, async () => {
        const context = await newContext(browser)
        await prepareCart(context, { ...ADDRESS, email: `retry-${checkout}@example.com` })
        const { page } = await open(context)
        const creates = await requests("CreateVerification")
        await page.click(place)
        await answerInFrame(page, "fail")
        await page.waitForFunction(([sel, re]) => [...document.querySelectorAll(sel)].some((n) => new RegExp(re).test(n.textContent ?? "")), [notice, "could not verify your age"], { timeout: 30000 })
        await page.waitForFunction((sel) => !document.querySelector(sel), MODAL, { timeout: 10000 })
        await page.waitForFunction(() => !document.querySelector(".blockUI"), null, { timeout: 20000 })
        await page.click(place)
        await answerInFrame(page, "pass")
        await page.waitForURL(/order-received/, { timeout: 45000 })
        assert.equal((await requests("CreateVerification")) - creates, 2, "a new check for the second attempt")
        await context.close()
      })

      await check(`${name}: ${checkout} checkout, AgeCheck down and the store refusing (the default): Place order opens no modal, says it is unavailable and places no order`, async () => {
        const context = await newContext(browser)
        const seen = await watchStatus(context)
        await prepareCart(context, { ...ADDRESS, email: `down-${checkout}@example.com` })
        const { page } = await open(context)
        const posts = countCheckoutPosts(page)
        await mock("config", { down: true })
        try {
          await page.click(place)
          await page.waitForFunction(([sel, re]) => [...document.querySelectorAll(sel)].some((n) => new RegExp(re).test(n.textContent ?? "")), [notice, "temporarily unavailable"], { timeout: 30000 })
          await page.waitForTimeout(1500)
        } finally {
          await mock("config", { down: false })
        }
        assert.equal(seen.modal, false, "no modal")
        assert.ok(!/order-received/.test(page.url()), "no order")
        if (checkout === "classic") assert.equal(posts.n, 0, "WooCommerce never posted the order")
        assert.ok(!seen.texts.some((t) => HOLD_COPY.test(t.text)), "no hold copy")
        await context.close()
      })
    }

    await check(`${name}: a guest's billing email typed on the page reaches the start on block and classic checkout while AgeCheck offers the returning-guest code (TAX-1839)`, async () => {
      await mock("config", { reuse: "on", org: { displayName: "Mock Store" } })
      await setup("mode", "prepayment") // flushes the cached GetIntegrationInfo
      try {
        for (const [checkout, open, place, field] of [
          ["block", openBlock, PLACE_BLOCK, "#email"],
          ["classic", openClassic, "#place_order", "#billing_email"],
        ]) {
          const typed = `typed-${checkout}-${name}@example.com`
          const context = await newContext(browser)
          await prepareCart(context, { ...ADDRESS, email: `stale-${checkout}@example.com` })
          const { page } = await open(context)
          await page.fill(field, typed)
          await page.locator(field).blur()
          const before = (await calls("CreateVerification")).length
          await page.click(place)
          await page.waitForSelector(FRAME, { timeout: 30000 })
          const creates = (await calls("CreateVerification")).slice(before)
          assert.equal(creates.length, 1, `${checkout}: one start`)
          assert.equal(creates[0].identity?.email, typed, `${checkout}: the email the shopper typed`)
          assert.match(creates[0].idempotencyKey, /:e[0-9a-f]{16}$/, `${checkout}: the key carries the email's tag`)
          await page.keyboard.press("Escape")
          await context.close()
        }
      } finally {
        await mock("config", { reuse: "off" })
        await setup("mode", "prepayment")
      }
    })

    const pages = await embedPages()
    results.push({ browser: name, embedPages: pages.length, parentOrigins: [...new Set(pages.map((p) => p.parentOrigin))] })
  } finally {
    await browser.close()
  }
}

console.log(JSON.stringify(results, null, 2))
if (failures > 0) {
  console.error(`${failures} check(s) failed`)
  process.exit(1)
}
