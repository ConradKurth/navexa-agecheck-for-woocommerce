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
//
// W4 accessibility: the Verify age button is pressed from the keyboard, and
// after the popup returns passed the focus must be on the status message
// (the button is gone) and the result announced in a live region. While the
// check runs (the store's answer is held back) the button stays enabled and
// aria-disabled (on the block checkout it keeps the focus), and a popup
// closed before the store answered returns the focus to the button.
//
// --turnstile-interactive: the site runs Turnstile with Cloudflare's
// force-interactive test site key (3x00000000000000000000FF) and the
// always-pass secret; only the interactive-challenge checks run: the popup
// closes at once, the status says to complete the check shown, the focus is
// on the challenge, and once it is solved the next click opens the age check.

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
const TURNSTILE_INTERACTIVE = process.argv.includes("--turnstile-interactive")
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

/** Holds the Store API start/refresh back for ms, so the busy phase can be observed. */
async function delayStarts(page, ms) {
  await page.route(
    (url) => decodeURIComponent(url.toString()).includes("/cart/extensions"),
    async (route) => {
      await new Promise((resolve) => setTimeout(resolve, ms))
      await route.continue().catch(() => {})
    },
  )
}

/** Presses Verify age from the keyboard; returns the popup. */
async function pressVerify(context, page, selector) {
  const popupPromise = context.waitForEvent("page", { timeout: 15000 })
  await page.focus(selector)
  await page.keyboard.press("Enter")
  return popupPromise
}

/**
 * Clicks Cloudflare's checkbox in the Turnstile iframe (it may sit in a closed
 * shadow root) until Turnstile writes its token into the container's response
 * field; the checkbox can take a moment to become clickable.
 */
async function solveTurnstile(page) {
  const solved = () => page.evaluate(() => (document.querySelector(".navexa-agecheck__turnstile [name='cf-turnstile-response']")?.value ?? "") !== "")
  for (let attempt = 1; attempt <= 6; attempt++) {
    const frame = page.frames().find((f) => f.url().includes("challenges.cloudflare.com"))
    const box = frame
      ? await frame
          .frameElement()
          .then((el) => el.boundingBox())
          .catch(() => null)
      : null
    if (box && box.width > 0) await page.mouse.click(box.x + 30, box.y + box.height / 2)
    for (let i = 0; i < 10; i++) {
      if (await solved()) return `click ${attempt}`
      await page.waitForTimeout(500)
    }
  }
  throw new Error("the Turnstile challenge was not solved")
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

async function verifyInPopup(context, page, buttonSelector, { keyboard = false } = {}) {
  const popupPromise = context.waitForEvent("page", { timeout: 15000 })
  if (keyboard) {
    await page.focus(buttonSelector)
    await page.keyboard.press("Enter")
  } else {
    await page.click(buttonSelector)
  }
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

    if (TURNSTILE_INTERACTIVE) {
      for (const [checkout, pageId, container, button, live] of [
        ["block", BLOCK, ".navexa-agecheck", ".navexa-agecheck__verify", ".navexa-agecheck__message"],
        ["classic", CLASSIC, "[data-navexa-agecheck]", "[data-navexa-agecheck-verify]", "#navexa-agecheck-live"],
      ]) {
        await check(`${name}: ${checkout} checkout, Turnstile interactive challenge: the popup closes, the shopper is told and focused on it, then the next click opens the age check`, async () => {
          const context = await browser.newContext()
          await context.addInitScript(KEEP_POPUP)
          await prepareCart(context)
          const page = await context.newPage()
          await page.goto(`${SITE}/?page_id=${pageId}`)
          await page.locator(button).waitFor({ timeout: 20000 })
          await page.waitForFunction(() => !document.querySelector(".blockUI"), null, { timeout: 15000 })
          await page.waitForFunction((b) => !document.querySelector(b)?.disabled, button, { timeout: 15000 })
          const started = Date.now()
          const popup = await pressVerify(context, page, button)
          await popup.waitForEvent("close", { timeout: 30000 })
          await page.waitForFunction((l) => /Complete the security check shown below/.test(document.querySelector(l)?.textContent ?? ""), live, { timeout: 10000 })
          const closedAfter = Date.now() - started
          assert.ok(closedAfter < 30000, `refused in ${closedAfter} ms, not after the 120 s token timeout`)
          await page.waitForFunction(() => document.activeElement?.classList.contains("navexa-agecheck__turnstile"), null, { timeout: 5000 })
          const group = await page.evaluate(() => ({ role: document.activeElement?.getAttribute("role"), label: document.activeElement?.getAttribute("aria-label") }))
          assert.deepEqual(group, { role: "group", label: "Security check" })
          assert.equal(await page.locator(`${container}[data-status="passed"]`).count(), 0)
          const how = await solveTurnstile(page)
          const popupPromise = context.waitForEvent("page", { timeout: 15000 })
          await page.click(button)
          const next = await popupPromise
          await next.waitForURL(/\/v\//, { timeout: 20000 })
          await next.click("button[value=pass]")
          await page.waitForSelector(`${container}[data-status="passed"]`, { timeout: 30000 })
          await context.close()
          return `popup closed after ${closedAfter} ms; challenge solved by ${how}`
        })
      }
      continue
    }

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

    await check(`${name}: keyboard on the block checkout: Enter verifies, the focus lands on the status, the result is announced`, async () => {
      const context = await browser.newContext()
      await context.addInitScript(KEEP_POPUP)
      await prepareCart(context)
      const page = await context.newPage()
      await page.goto(`${SITE}/?page_id=${BLOCK}`)
      const button = page.locator(".navexa-agecheck__verify")
      await button.waitFor({ timeout: 20000 })
      assert.equal(await button.evaluate((el) => el.tagName), "BUTTON")
      const describedBy = await button.getAttribute("aria-describedby")
      assert.ok(describedBy && (await page.locator(`#${describedBy}`).count()) === 1, "the button is described by the status line")
      assert.equal(await page.locator(".navexa-agecheck__message").getAttribute("aria-live"), "polite")
      await page.waitForFunction(() => !document.querySelector(".navexa-agecheck__verify")?.disabled, null, { timeout: 15000 })
      await verifyInPopup(context, page, ".navexa-agecheck__verify", { keyboard: true })
      await page.waitForSelector('.navexa-agecheck[data-status="passed"]', { timeout: 30000 })
      await page.waitForFunction(() => document.activeElement?.classList.contains("navexa-agecheck__message"), null, { timeout: 10000 })
      const text = await page.evaluate(() => document.activeElement?.textContent ?? "")
      assert.match(text, /Age verified/)
      await context.close()
      return `focus on "${text.trim()}"`
    })

    await check(`${name}: keyboard on the classic checkout: Enter verifies, the focus survives the fragment refresh, the result is announced`, async () => {
      const context = await browser.newContext()
      await context.addInitScript(KEEP_POPUP)
      await prepareCart(context)
      const page = await context.newPage()
      await page.goto(`${SITE}/?page_id=${CLASSIC}`)
      await page.waitForSelector("[data-navexa-agecheck-verify]", { timeout: 20000 })
      await page.waitForFunction(() => !document.querySelector(".blockUI"), null, { timeout: 15000 })
      assert.equal(await page.locator("[data-navexa-agecheck-verify]").getAttribute("aria-describedby"), "navexa-agecheck-message")
      await verifyInPopup(context, page, "[data-navexa-agecheck-verify]", { keyboard: true })
      await page.waitForSelector('[data-navexa-agecheck][data-status="passed"]', { timeout: 30000 })
      await page.waitForFunction(() => document.activeElement?.id === "navexa-agecheck-message", null, { timeout: 15000 })
      await page.waitForFunction(() => /Age verified/.test(document.getElementById("navexa-agecheck-live")?.textContent ?? ""), null, { timeout: 10000 })
      const region = await page.evaluate(() => {
        const el = document.getElementById("navexa-agecheck-live")
        return { role: el?.getAttribute("role"), live: el?.getAttribute("aria-live"), outside: !el?.closest(".woocommerce-checkout-review-order, #order_review") }
      })
      assert.deepEqual(region, { role: "status", live: "polite", outside: true })
      await context.close()
    })

    await check(`${name}: keyboard on the block checkout: while a check runs the button keeps the focus, enabled and aria-disabled; a closed popup returns the focus to it`, async () => {
      const context = await browser.newContext()
      await context.addInitScript(KEEP_POPUP)
      await prepareCart(context)
      const page = await context.newPage()
      await page.goto(`${SITE}/?page_id=${BLOCK}`)
      await page.locator(".navexa-agecheck__verify").waitFor({ timeout: 20000 })
      await page.waitForFunction(() => !document.querySelector(".navexa-agecheck__verify")?.disabled, null, { timeout: 15000 })
      await delayStarts(page, 3000)
      const popup = await pressVerify(context, page, ".navexa-agecheck__verify")
      await page.waitForFunction(() => document.querySelector(".navexa-agecheck__verify")?.getAttribute("aria-disabled") === "true", null, { timeout: 5000 })
      const busy = await page.evaluate(() => {
        const button = document.querySelector(".navexa-agecheck__verify")
        return {
          focused: document.activeElement === button,
          disabled: button?.disabled,
          containerBusy: document.querySelector(".navexa-agecheck")?.getAttribute("aria-busy"),
        }
      })
      assert.deepEqual(busy, { focused: true, disabled: false, containerBusy: "true" }, "busy: focus kept on an enabled, aria-disabled button")
      await popup.close()
      await page.waitForFunction(() => document.querySelector(".navexa-agecheck__verify")?.getAttribute("aria-disabled") === "false", null, { timeout: 15000 })
      await page.waitForFunction(() => document.activeElement === document.querySelector(".navexa-agecheck__verify"), null, { timeout: 5000 })
      const text = await page.locator(".navexa-agecheck__message").textContent()
      assert.match(text ?? "", /window was closed/)
      await context.close()
      return `then "${(text ?? "").trim()}"`
    })

    await check(`${name}: keyboard on the classic checkout: while a check runs the button is enabled and aria-disabled; a closed popup returns the focus to it`, async () => {
      const context = await browser.newContext()
      await context.addInitScript(KEEP_POPUP)
      await prepareCart(context)
      const page = await context.newPage()
      await page.goto(`${SITE}/?page_id=${CLASSIC}`)
      await page.waitForSelector("[data-navexa-agecheck-verify]", { timeout: 20000 })
      await page.waitForFunction(() => !document.querySelector(".blockUI"), null, { timeout: 15000 })
      await delayStarts(page, 3000)
      const popup = await pressVerify(context, page, "[data-navexa-agecheck-verify]")
      await page.waitForFunction(() => document.querySelector("[data-navexa-agecheck-verify]")?.getAttribute("aria-disabled") === "true", null, { timeout: 8000 })
      const busy = await page.evaluate(() => ({
        disabled: document.querySelector("[data-navexa-agecheck-verify]")?.disabled,
        containerBusy: document.querySelector("[data-navexa-agecheck]")?.getAttribute("aria-busy"),
      }))
      assert.deepEqual(busy, { disabled: false, containerBusy: "true" }, "busy: an enabled, aria-disabled button")
      await popup.close()
      await page.waitForFunction(() => document.querySelector("[data-navexa-agecheck-verify]")?.getAttribute("aria-disabled") === "false", null, { timeout: 15000 })
      await page
        .waitForFunction(() => document.activeElement?.matches?.("[data-navexa-agecheck-verify]") && /window was closed/.test(document.getElementById("navexa-agecheck-live")?.textContent ?? ""), null, { timeout: 8000 })
        .catch(async () => {
          const state = await page.evaluate(() => ({ active: document.activeElement?.outerHTML?.slice(0, 120), live: document.getElementById("navexa-agecheck-live")?.textContent }))
          throw new Error(`focus back on the button and "closed" announced; got ${JSON.stringify(state)}`)
        })
      await context.close()
    })

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
