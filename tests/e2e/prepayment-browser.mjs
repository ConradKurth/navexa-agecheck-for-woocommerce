#!/usr/bin/env node
// Browser run of the W2 pre-payment flow (the plan's W2 spikes): block and
// classic checkout, the Verify age button, the popup to the (mock) hosted
// page, which sends Cross-Origin-Opener-Policy: same-origin like Didit, the
// return page, the popup-blocked full-page redirect, and what popup.closed
// reads after the COOP navigation.
//
// Needs wp-env, the mock engine and tests/wp-env/prepayment-setup.php, as for
// tests/wp-env/prepayment.mjs, and Playwright browsers
// (npx playwright install chromium webkit):
//
//   node tests/e2e/prepayment-browser.mjs --site http://localhost:8888 --mock http://localhost:8787 \
//     --product <id> --block <page id> --classic <page id> [--free-product <id>] [--browsers chromium,webkit]
//
// Prints one line per check and a JSON summary of the popup observations.

import assert from "node:assert/strict"
import { chromium, firefox, webkit } from "playwright-core"

function arg(name, fallback) {
  const i = process.argv.indexOf(name)
  return i >= 0 ? process.argv[i + 1] : fallback
}

const SITE = arg("--site", "http://localhost:8888").replace(/\/+$/, "")
const MOCK = arg("--mock", "http://localhost:8787").replace(/\/+$/, "")
const PRODUCT = Number(arg("--product", "0"))
const BLOCK = Number(arg("--block", "0"))
const CLASSIC = Number(arg("--classic", "0"))
const FREE = Number(arg("--free-product", "0"))
const BROWSERS = arg("--browsers", "chromium,webkit").split(",")
const ENGINES = { chromium, firefox, webkit }

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

// Keeps the opener's handle on the popup so the spike can read popup.closed.
const KEEP_POPUP = () => {
  const open = window.open.bind(window)
  window.open = (...args) => {
    const w = open(...args)
    window.__navexaPopup = w
    return w
  }
}
const BLOCK_POPUPS = () => {
  window.open = () => null
}

async function mock(path, body) {
  const res = await fetch(`${MOCK}/__mock/${path}`, { method: "POST", body: JSON.stringify(body ?? {}) })
  assert.equal(res.status, 200)
}

/** Fills the cart and the session's addresses through the Store API, in the browser's session. */
async function prepareCart(context, product = PRODUCT, method = "flat_rate") {
  const cart = await context.request.get(`${SITE}/wp-json/wc/store/v1/cart`)
  const nonce = cart.headers()["nonce"]
  const headers = { Nonce: nonce, "Content-Type": "application/json" }
  const added = await context.request.post(`${SITE}/wp-json/wc/store/v1/cart/add-item`, { headers, data: { id: product, quantity: 1 } })
  assert.equal(added.status(), 201)
  const updated = await context.request.post(`${SITE}/wp-json/wc/store/v1/cart/update-customer`, {
    headers,
    data: { billing_address: ADDRESS, shipping_address: ADDRESS },
  })
  assert.equal(updated.status(), 200)
  const rates = (await updated.json()).shipping_rates?.[0]?.shipping_rates ?? []
  const rate = rates.find((r) => r.method_id === method)
  if (rate) {
    const chosen = await context.request.post(`${SITE}/wp-json/wc/store/v1/cart/select-shipping-rate`, { headers, data: { package_id: 0, rate_id: rate.rate_id } })
    assert.equal(chosen.status(), 200)
  }
}

const results = []
let failures = 0
async function check(name, fn) {
  try {
    const note = await fn()
    console.log(`ok   ${name}${note ? ` (${note})` : ""}`)
  } catch (err) {
    failures++
    console.log(`FAIL ${name}\n     ${String(err?.message ?? err).split("\n").slice(0, 4).join("\n     ")}`)
  }
}

async function verifyInPopup(context, page, buttonSelector) {
  const popupPromise = context.waitForEvent("page", { timeout: 15000 })
  await page.click(buttonSelector)
  const popup = await popupPromise
  await popup.waitForURL(/\/v\//, { timeout: 15000 })
  await popup.waitForSelector("text=Mock AgeCheck", { timeout: 15000 })
  const closedAfterCoop = await page.evaluate(() => (window.__navexaPopup ? window.__navexaPopup.closed : null))
  const hasOpener = await popup.evaluate(() => window.opener !== null)
  await popup.click("button[value=pass]")
  await popup.waitForURL(/wc-api=navexa_agecheck_return/, { timeout: 15000 }).catch(() => {})
  await page.waitForTimeout(1500)
  const selfClosed = popup.isClosed()
  if (!selfClosed) await popup.close()
  return { closedAfterCoop, hasOpener, selfClosed }
}

for (const name of BROWSERS) {
  const browser = await ENGINES[name].launch()
  try {
    await mock("reset")
    await mock("config", { hostedBase: MOCK, org: { allowedOrigins: [SITE] } })

    await check(`${name}: block checkout renders the forced Verify age block and the popup flow passes`, async () => {
      const context = await browser.newContext()
      await context.addInitScript(KEEP_POPUP)
      await prepareCart(context)
      const page = await context.newPage()
      await page.goto(`${SITE}/?page_id=${BLOCK}`)
      const block = page.locator(".navexa-agecheck")
      await block.waitFor({ timeout: 20000 })
      const where = await block.evaluate((el) => {
        const step = el.closest(".wc-block-components-checkout-step, .wp-block-woocommerce-checkout-payment-block, [class*='payment']")
        return step ? step.className.split(" ").slice(0, 3).join(" ") : el.parentElement?.className
      })
      assert.equal(await block.getAttribute("data-status"), "none")

      // Place order before verifying: the client-side validation error.
      await page.click(".wc-block-components-checkout-place-order-button")
      await page.waitForSelector(".navexa-agecheck .wc-block-components-validation-error", { timeout: 10000 })

      const seen = await verifyInPopup(context, page, ".navexa-agecheck__verify")
      await page.waitForSelector('.navexa-agecheck[data-status="passed"]', { timeout: 30000 })
      results.push({ browser: name, checkout: "block", where, ...seen })

      await page.click(".wc-block-components-checkout-place-order-button")
      await page.waitForURL(/order-received/, { timeout: 30000 })
      await context.close()
      return `in ${where}; popup.closed after COOP=${seen.closedAfterCoop}, opener kept=${seen.hasOpener}, return page closed itself=${seen.selfClosed}`
    })

    await check(`${name}: classic checkout button, popup flow and Place order`, async () => {
      const context = await browser.newContext()
      await context.addInitScript(KEEP_POPUP)
      await prepareCart(context)
      const page = await context.newPage()
      await page.goto(`${SITE}/?page_id=${CLASSIC}`)
      await page.waitForSelector("[data-navexa-agecheck-verify]", { timeout: 20000 })
      await page.waitForFunction(() => !document.querySelector(".blockUI"), null, { timeout: 15000 })
      const seen = await verifyInPopup(context, page, "[data-navexa-agecheck-verify]")
      await page.waitForSelector('[data-navexa-agecheck][data-status="passed"]', { timeout: 30000 })
      results.push({ browser: name, checkout: "classic", ...seen })
      await page.waitForFunction(() => !document.querySelector(".blockUI"), null, { timeout: 15000 })
      await page.check("#terms").catch(() => {})
      await page.click("#place_order")
      await page.waitForURL(/order-received/, { timeout: 30000 })
      await context.close()
      return `popup.closed after COOP=${seen.closedAfterCoop}, return page closed itself=${seen.selfClosed}`
    })

    if (FREE > 0) {
      await check(`${name}: a cart that needs no payment gets the fallback block`, async () => {
        const context = await browser.newContext()
        await context.addInitScript(KEEP_POPUP)
        await prepareCart(context, FREE, "free_shipping")
        const page = await context.newPage()
        await page.goto(`${SITE}/?page_id=${BLOCK}`)
        const block = page.locator(".navexa-agecheck")
        await block.waitFor({ timeout: 20000 })
        assert.equal(await page.locator(".wc-block-checkout__payment-method").count(), 0, "no payment step")
        assert.equal(await block.count(), 1, "one Verify age block")
        await verifyInPopup(context, page, ".navexa-agecheck__verify")
        await page.waitForSelector('.navexa-agecheck[data-status="passed"]', { timeout: 30000 })
        await page.click(".wc-block-components-checkout-place-order-button")
        await page.waitForURL(/order-received/, { timeout: 30000 })
        await context.close()
      })
    }

    await check(`${name}: blocked popup falls back to a full-page redirect and comes back passed`, async () => {
      const context = await browser.newContext()
      await context.addInitScript(BLOCK_POPUPS)
      await prepareCart(context)
      const page = await context.newPage()
      await page.goto(`${SITE}/?page_id=${BLOCK}`)
      await page.locator(".navexa-agecheck__verify").waitFor({ timeout: 20000 })
      await page.click(".navexa-agecheck__verify")
      await page.waitForURL(/\/v\//, { timeout: 15000 })
      await page.click("button[value=pass]")
      await page.waitForURL(new RegExp(`page_id=${BLOCK}|/checkout`), { timeout: 20000 })
      await page.waitForSelector('.navexa-agecheck[data-status="passed"]', { timeout: 30000 })
      await context.close()
    })
  } finally {
    await browser.close()
  }
}

console.log(JSON.stringify(results, null, 2))
if (failures > 0) {
  console.error(`${failures} check(s) failed`)
  process.exit(1)
}
