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
//     --product <id> --block <page id> --classic <page id> [--browsers chromium,webkit,firefox] [--only <text>]
//
// --mock must be the 127.0.0.1 address: it is also the hosted pages' base.
// Checks: the modal passes and the order is placed without leaving checkout;
// a fail in the frame; closing the modal ({status:'closed'}), then a second
// Place order on the same verification; a refused frame falls back to a popup
// opened from a click inside the modal; a blocked popup falls back to a
// full-page redirect and back; the name and ship-to lock after a pass, a
// declined payment retried with no second check, Change details, and a
// devtools edit of a locked field refused by the server; the modal's dialog
// semantics and the focus back on Place order.

import assert from "node:assert/strict"
import { chromium, firefox, webkit } from "playwright-core"

function arg(name, fallback) {
  const i = process.argv.indexOf(name)
  return i >= 0 ? process.argv[i + 1] : fallback
}

const SITE = arg("--site", "http://localhost:8888").replace(/\/+$/, "")
const MOCK = arg("--mock", "http://127.0.0.1:8787").replace(/\/+$/, "")
const PRODUCT = Number(arg("--product", "0"))
const BLOCK = Number(arg("--block", "0"))
const CLASSIC = Number(arg("--classic", "0"))
const BROWSERS = arg("--browsers", "chromium,webkit,firefox").split(",")
const ONLY = arg("--only", "")
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

/** Fills the cart and the session's addresses through the Store API, in the browser's session. */
async function prepareCart(context, address = ADDRESS) {
  const cart = await context.request.get(`${SITE}/wp-json/wc/store/v1/cart`)
  const headers = { Nonce: cart.headers()["nonce"], "Content-Type": "application/json" }
  const added = await context.request.post(`${SITE}/wp-json/wc/store/v1/cart/add-item`, { headers, data: { id: PRODUCT, quantity: 1 } })
  assert.equal(added.status(), 201)
  const updated = await context.request.post(`${SITE}/wp-json/wc/store/v1/cart/update-customer`, { headers, data: { billing_address: address, shipping_address: address } })
  assert.equal(updated.status(), 200)
  const rate = ((await updated.json()).shipping_rates?.[0]?.shipping_rates ?? []).find((r) => r.method_id === "flat_rate")
  if (rate) await context.request.post(`${SITE}/wp-json/wc/store/v1/cart/select-shipping-rate`, { headers, data: { package_id: 0, rate_id: rate.rate_id } })
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

    await check(`${name}: block checkout: closing the modal places no order and says so; Place order again reuses the verification and passes`, async () => {
      const context = await newContext(browser)
      await prepareCart(context, { ...ADDRESS, email: "block-close@example.com" })
      const { page } = await openBlock(context)
      const creates = await requests("CreateVerification")
      const sessions = await requests("CreateHostedSession")
      await page.click(PLACE_BLOCK)
      await page.waitForSelector(FRAME, { timeout: 30000 })
      await page.waitForFunction((sel) => document.activeElement?.closest?.(sel) !== null, MODAL, { timeout: 10000 })
      await page.keyboard.press("Escape")
      await page.waitForSelector(".wc-block-components-notice-banner.is-error", { timeout: 30000 })
      assert.match(await page.locator(".wc-block-components-notice-banner.is-error").first().textContent(), /closed before it finished/)
      assert.equal(await page.locator(MODAL).count(), 0)
      await page.click(PLACE_BLOCK)
      await answerInFrame(page, "pass")
      await page.waitForURL(/order-received/, { timeout: 45000 })
      assert.equal((await requests("CreateVerification")) - creates, 1, "one verification for both attempts")
      assert.equal((await requests("CreateHostedSession")) - sessions, 2, "two hosted sessions")
      await context.close()
    })

    await check(`${name}: classic checkout: keyboard: Enter on Place order opens the modal with the focus trapped in it; its close button ends the attempt with the closed message announced`, async () => {
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

    await check(`${name}: classic checkout: a devtools edit of a locked field is refused by the server; Change details unlocks, and the next Place order opens the modal`, async () => {
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
      await page.click("[data-navexa-agecheck-unlock]")
      const unlocked = await page.evaluate(() => ({
        address: document.querySelector('form.checkout [name="billing_address_1"]')?.readOnly,
        mirrors: document.querySelectorAll("[data-navexa-agecheck-mirror]").length,
      }))
      assert.deepEqual(unlocked, { address: false, mirrors: 0 })
      await page.evaluate(() => window.jQuery(document.body).trigger("update_checkout"))
      await page.waitForFunction(() => document.querySelector('[data-navexa-agecheck="1"]')?.getAttribute("data-status") === "none", null, { timeout: 20000 })
      await page.waitForFunction(() => !document.querySelector(".blockUI"), null, { timeout: 20000 })
      await page.click("#place_order")
      await answerInFrame(page, "pass")
      await page.waitForURL(/order-received/, { timeout: 45000 })
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
