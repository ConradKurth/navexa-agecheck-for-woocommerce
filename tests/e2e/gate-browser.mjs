#!/usr/bin/env node
// Browser check of the entry age gate (W3 PR 13, TAX-1874 for the shared
// loader, date of birth and branding, TAX-1875 for gate-only; self-attestation,
// not verification) against wp-env and the mock engine, in Chromium by default.
//
// Needs wp-env with the mock running (as for tests/e2e/checkout-modal-browser.mjs)
// and Playwright's Chromium (npx playwright-core install chromium). The
// script switches the gate's settings itself through
// tests/wp-env/gate-setup.php, run by --setup-cmd (default: wp-env's cli
// container for this checkout):
//
//   node tests/e2e/gate-browser.mjs --site http://localhost:8888 --mock http://localhost:8787 [--browsers chromium]
//     [--setup-cmd "npx --yes @wordpress/env@12.1.0 run cli --env-cwd=wp-content/plugins/navexa-agecheck-for-woocommerce wp eval-file tests/wp-env/gate-setup.php"]
//     [--http-only]
//
// --http-only runs only the server-side checks over HTTP (no browser; CI):
// which pages carry the gate, both scripts in the head with the optimizer
// opt-outs, the options in data-config (no inline script), the same for
// every visitor, no cookie; gate-only with no keys.
//
// Browser checks: the loader's dialog (ARIA, initial focus, Tab trapped,
// Escape ignored, the page hidden and inert, its own aria-hidden put back);
// "of age" remembered (navexa_agecheck_gate_v1; sessionStorage for 0 days);
// "under age" goes to the exit page, where the gate never shows; a 1.0.x
// remembered answer is migrated (no gate); date of birth: under age exits, of
// age is remembered, the date of birth is in no storage and no request;
// branding: the merchant's colours on the dialog and text with {age} and
// {site}; gate-only with no keys; restricted scope; never on the login page
// or in wp-admin; no request leaves the store's origin.

import assert from "node:assert/strict"
import { execSync } from "node:child_process"

function arg(name, fallback) {
  const i = process.argv.indexOf(name)
  return i >= 0 ? process.argv[i + 1] : fallback
}

const SITE = arg("--site", "http://localhost:8888").replace(/\/+$/, "")
const MOCK = arg("--mock", "http://localhost:8787").replace(/\/+$/, "")
const HTTP_ONLY = process.argv.includes("--http-only")
const BROWSERS = HTTP_ONLY ? [] : arg("--browsers", "chromium").split(",")
const SETUP = arg(
  "--setup-cmd",
  "npx --yes @wordpress/env@12.1.0 run cli --env-cwd=wp-content/plugins/navexa-agecheck-for-woocommerce wp eval-file tests/wp-env/gate-setup.php",
)
const ORIGIN = new URL(SITE).origin
// The loader's gate dialog (WooCommerce's product gallery and wp-admin have dialogs too).
const DIALOG = '[data-navexa-agecheck="gate"] [role="dialog"][aria-modal="true"]'
const KEY = "navexa_agecheck_gate_v1"
const LEGACY_KEY = "navexa-agecheck-gate"

/** Runs gate-setup.php with these arguments; its JSON answer. */
function setup(...args) {
  const out = execSync(`${SETUP} ${args.join(" ")}`, { encoding: "utf8", stdio: ["ignore", "pipe", "inherit"] })
  const line = out.trim().split("\n").filter((l) => l.startsWith("{")).pop()
  assert.ok(line, `gate-setup printed no JSON: ${out}`)
  return JSON.parse(line)
}

async function mock(path, body) {
  const res = await fetch(`${MOCK}/__mock/${path}`, { method: "POST", body: JSON.stringify(body ?? {}) })
  assert.equal(res.status, 200, `mock ${path}`)
}

let failures = 0
async function check(name, fn) {
  try {
    await fn()
    console.log(`ok   ${name}`)
  } catch (err) {
    failures++
    const where = err?.stack?.split("\n").find((l) => l.includes("gate-browser.mjs")) ?? ""
    console.log(`FAIL ${name}\n     ${String(err?.message ?? err).split("\n").slice(0, 30).join("\n     ")}\n     ${where.trim()}${err?.cause ? `\n     cause: ${err.cause}` : ""}`)
  }
}

/** A fresh context recording every request that leaves the store's origin (and every request URL and body). */
async function context(browser, foreign, seen = []) {
  const ctx = await browser.newContext()
  ctx.on("request", (req) => {
    const url = req.url()
    seen.push(`${url} ${req.postData() ?? ""}`)
    if (url.startsWith("data:") || url.startsWith("blob:")) return
    if (new URL(url).origin !== ORIGIN) foreign.push(url)
  })
  return ctx
}

const gateLoader = /assets\/vendor\/agecheck-js\/agecheck-gate-\d+\.\d+\.\d+(\.debug)?\.js/
const gateBoot = /assets\/js\/gate-boot(\.min)?\.js/
const legacyGate = /entry-gate(\.min)?\.js/

/** The page's HTML over plain HTTP (a visitor with no answer stored, as a page cache sees it). */
async function html(url, headers = {}) {
  for (let attempt = 1; ; attempt++) {
    try {
      const res = await fetch(url, { headers, redirect: "manual" })
      return { res, text: await res.text() }
    } catch (err) {
      // A kept-alive connection the server closed while wp-cli ran: once more.
      if (attempt >= 2) throw err
    }
  }
}

/** The boot script's tag and its data-config, decoded. */
function bootTag(text) {
  const tag = /<script[^>]* id="navexa-agecheck-entry-gate-js"[^>]*>/.exec(text)?.[0] ?? ""
  const raw = / data-config="([^"]*)"/.exec(tag)?.[1] ?? ""
  const json = raw.replace(/&quot;/g, '"').replace(/&#0?39;/g, "'").replace(/&lt;/g, "<").replace(/&gt;/g, ">").replace(/&amp;/g, "&")
  return { tag, raw, config: raw ? JSON.parse(json) : null }
}

const carries = (text) => gateLoader.test(text) && gateBoot.test(text)

console.log("# HTTP")
await mock("config", { org: { minimumAge: 21, displayName: "Mock Store", allowedOrigins: [SITE] } })
let urls = setup("off")

await check("off: no page carries the gate", async () => {
  for (const url of [urls.home, urls.product_url, urls.category_url]) assert.ok(!carries((await html(url)).text), url)
})

await mock("config", { org: { minimumAge: 18, displayName: "Gate Test Store" } })
urls = setup("on", "site", "30", "exit")
assert.equal(urls.minimum_age, 18, "the mock's minimum age reached the store")
assert.equal(urls.display_name, "Gate Test Store")

await check("site scope: the shared loader's gate build and the boot script in the head, options in data-config, the same for everyone, no cookie", async () => {
  const first = await html(urls.home)
  const head = first.text.slice(0, first.text.indexOf("</head>"))
  assert.match(head, gateLoader, "the vendored gate-only build in the head")
  assert.match(head, gateBoot, "the boot script in the head")
  assert.ok(head.search(gateLoader) < head.search(gateBoot), "the loader before the boot script")
  assert.doesNotMatch(first.text, legacyGate, "not the 1.0.x gate")
  assert.doesNotMatch(first.text, /navexaAgeCheckGate/, "no inline configuration")
  const { tag, raw, config } = bootTag(head)
  assert.ok(config, `data-config on the boot tag: ${tag}`)
  const second = await html(urls.home, { Cookie: `${LEGACY_KEY}=1; ${KEY}=1` })
  assert.equal(bootTag(second.text).raw, raw, "the server never reads an answer")
  assert.equal(first.res.headers.get("set-cookie"), null, "the gate sets no cookie")
  assert.equal(config.v, 2)
  const o = config.options
  assert.deepEqual(Object.keys(o).sort(), ["exitUrl", "labels", "message", "minAge", "mode", "rememberDays", "siteName", "theme", "title"])
  assert.equal(o.mode, "attest")
  assert.equal(o.minAge, 18)
  assert.equal(o.title, "Welcome to Gate Test Store")
  assert.equal(o.labels.yes, "I am 18 or older")
  assert.deepEqual(o.theme, { backdrop: "#1e1e1e", card: "#ffffff", text: "#1e1e1e", accent: "#1e1e1e", accentText: "#ffffff" }, "the 1.0.x look")
  assert.doesNotMatch(raw, /txk_/)
  // Optimizer opt-outs on both tags (Rocket Loader reads data-cfasync before src).
  for (const id of ["navexa-agecheck-gate-loader-js", "navexa-agecheck-entry-gate-js"]) {
    const t = new RegExp(`<script[^>]* id="${id}"[^>]*>`).exec(head)?.[0] ?? ""
    for (const attr of [/ data-cfasync="false"/, / data-no-optimize="1"/, / data-no-defer="1"/, / data-no-minify="1"/, / nowprocket(="nowprocket")?[ >]/]) assert.match(t, attr, id)
    assert.match(t, / data-cfasync="false".* src="/, `${id}: data-cfasync before src`)
  }
  assert.ok(carries((await html(urls.exit_url)).text), "the exit page carries it too; the loader skips that page")
})

await check("never on the login page, wp-admin, REST or the AgeCheck return page", async () => {
  for (const url of [urls.login_url, urls.return_url, urls.rest_url, `${SITE}/wp-admin/`]) assert.ok(!carries((await html(url)).text), url)
})

await check("restricted scope: only restricted product and category pages", async () => {
  setup("on", "restricted", "30", "noexit")
  assert.ok(!carries((await html(urls.home)).text), "home")
  assert.ok(!carries((await html(urls.exempt_url)).text), "exempt product")
  assert.ok(carries((await html(urls.product_url)).text), "restricted product")
  assert.ok(carries((await html(urls.category_url)).text), "its category")
})

await check("date of birth and branding: mode, the merchant's colours and text ({age} and {site} left to the loader)", async () => {
  urls = setup("on", "site", "30", "exit", "dob", "verify", "brand")
  const o = bootTag((await html(urls.home)).text).config.options
  assert.equal(o.mode, "dob")
  assert.deepEqual(o.theme, { backdrop: "#0b3d2e", card: "#fffbe6", text: "#222222", accent: "#0a6b3d", accentText: "#ffffff" })
  assert.equal(o.title, "Hello from {site}")
  assert.equal(o.message, "Only {age}+ past this point.")
  assert.equal(o.labels.submit, "Go")
  assert.equal(o.labels.dob, "Date of birth")
  assert.equal(o.logoUrl, undefined, "no logo chosen (and an http store would never get one)")
})

await check("gate-only: no keys, the gate still serves at its own minimum age, and a restricted cart checks out with no AgeCheck call", async () => {
  urls = setup("on", "site", "30", "exit", "attest", "gate_only")
  assert.equal(urls.minimum_age, 19)
  const o = bootTag((await html(urls.home)).text).config.options
  assert.equal(o.minAge, 19)
  const before = (await (await fetch(`${MOCK}/__mock/requests`)).json()).length
  // A Store API checkout of the restricted product: no check, no hold.
  const cart = await fetch(`${SITE}/wp-json/wc/store/v1/cart`)
  const cookie = cart.headers.getSetCookie().map((c) => c.split(";")[0]).join("; ")
  const headers = { Nonce: cart.headers.get("nonce"), Cookie: cookie, "Content-Type": "application/json" }
  const added = await fetch(`${SITE}/wp-json/wc/store/v1/cart/add-item`, { method: "POST", headers, body: JSON.stringify({ id: urls.product_id, quantity: 1 }) })
  assert.equal(added.status, 201)
  const jar = new Map([...cookie.split("; "), ...added.headers.getSetCookie().map((c) => c.split(";")[0])].filter(Boolean).map((c) => [c.split("=")[0], c]))
  headers.Cookie = [...jar.values()].join("; ")
  headers.Nonce = added.headers.get("nonce") ?? headers.Nonce
  const data = await added.json()
  assert.equal(data.extensions["navexa-agecheck"].required, false, "nothing needs a check")
  const address = { first_name: "Gate", last_name: "Only", address_1: "1 Main St", city: "Springfield", state: "IL", postcode: "62701", country: "US", email: "gate-only@example.com", phone: "5555550100" }
  const placed = await fetch(`${SITE}/wp-json/wc/store/v1/checkout`, {
    method: "POST",
    headers,
    body: JSON.stringify({ billing_address: address, shipping_address: address, payment_method: "cod" }),
  })
  const body = await placed.json()
  assert.equal(placed.status, 200, JSON.stringify(body).slice(0, 300))
  assert.notEqual(body.status, "agecheck-hold", "not held")
  const after = (await (await fetch(`${MOCK}/__mock/requests`)).json()).length
  assert.equal(after, before, "the mock recorded no request")
})

// Playwright only for the browser checks (the CI job runs --http-only without node_modules).
const ENGINES = BROWSERS.length ? await import("playwright-core") : {}

for (const name of BROWSERS) {
  const engine = ENGINES[name]
  assert.ok(engine, `unknown browser ${name}`)
  const browser = await engine.launch()
  const foreign = []
  console.log(`# ${name}`)
  urls = setup("on", "site", "30", "exit")

  await check("site scope: the loader's accessible modal dialog hides the page", async () => {
    const ctx = await context(browser, foreign)
    const page = await ctx.newPage()
    await page.goto(urls.home)
    const dialog = page.locator(DIALOG)
    await dialog.waitFor({ state: "visible" })
    assert.equal(await page.locator("#navexa-agecheck-gate-title").textContent(), "Welcome to Gate Test Store", "GetIntegrationInfo display_name")
    assert.match(await page.locator("#navexa-agecheck-gate-msg").textContent(), /18 or older/, "GetIntegrationInfo minimum_age")
    assert.equal(await dialog.getAttribute("aria-labelledby"), "navexa-agecheck-gate-title")
    assert.equal(await dialog.getAttribute("aria-describedby"), "navexa-agecheck-gate-msg")
    assert.equal(await page.evaluate(() => document.activeElement?.id), "navexa-agecheck-gate-title", "focus starts on the question")
    const hidden = await page.evaluate(() => {
      const others = [...document.body.children].filter((el) => !el.matches('[data-navexa-agecheck="gate"]') && el.tagName !== "SCRIPT" && el.tagName !== "STYLE")
      return {
        attr: document.documentElement.hasAttribute("data-navexa-agecheck-gate"),
        allHidden: others.every((el) => getComputedStyle(el).visibility === "hidden"),
        allInert: others.every((el) => el.hasAttribute("inert") && el.getAttribute("aria-hidden") === "true"),
        count: others.length,
      }
    })
    assert.ok(hidden.count > 0)
    assert.deepEqual(hidden, { attr: true, allHidden: true, allInert: true, count: hidden.count })
    const box = await page.locator(".nac-yes").boundingBox()
    assert.ok(box && box.height >= 44, "44 px touch target")
    await ctx.close()
  })

  await check("keyboard: Tab stays in the dialog, Escape does not dismiss, Enter answers; remembered, the page's own aria-hidden put back", async () => {
    const ctx = await context(browser, foreign)
    await ctx.addInitScript(() => {
      document.addEventListener("DOMContentLoaded", () => {
        const own = document.createElement("div")
        own.id = "page-own-hidden"
        own.setAttribute("aria-hidden", "true")
        document.body.appendChild(own)
      })
    })
    const page = await ctx.newPage()
    await page.goto(urls.home)
    await page.locator(DIALOG).waitFor()
    for (let i = 0; i < 6; i++) await page.keyboard.press("Tab")
    assert.equal(await page.evaluate(() => !!document.activeElement?.closest('[data-navexa-agecheck="gate"]')), true, "never leaves the dialog")
    await page.keyboard.press("Escape")
    assert.equal(await page.locator(DIALOG).isVisible(), true, "Escape does not dismiss")
    await page.locator(".nac-yes").focus()
    await page.keyboard.press("Enter")
    await page.locator(DIALOG).waitFor({ state: "detached" })
    assert.equal(await page.evaluate(() => document.documentElement.hasAttribute("data-navexa-agecheck-gate")), false)
    assert.equal(await page.evaluate(() => [...document.body.children].some((el) => el.hasAttribute("inert"))), false, "the page is usable again")
    assert.equal(await page.getAttribute("#page-own-hidden", "aria-hidden"), "true", "the page's own aria-hidden is put back")
    const stored = JSON.parse(await page.evaluate((k) => localStorage.getItem(k), KEY))
    assert.equal(stored.minAge, 18)
    assert.equal(typeof stored.passedAt, "number")
    await page.reload()
    await page.waitForLoadState("domcontentloaded")
    assert.equal(await page.locator(DIALOG).count(), 0, "remembered on reload")
    await page.goto(urls.product_url)
    assert.equal(await page.locator(DIALOG).count(), 0, "remembered on other pages")
    assert.equal(await page.evaluate(() => document.cookie.includes("navexa")), false, "no cookie")
    await ctx.close()
  })

  await check("a visitor who answered the 1.0.x gate is not asked again (migrated in the browser)", async () => {
    const ctx = await context(browser, foreign)
    await ctx.addInitScript((k) => {
      if (!sessionStorage.getItem("seeded")) {
        sessionStorage.setItem("seeded", "1")
        localStorage.setItem(k, JSON.stringify({ v: 1, age: 21, at: Date.now() - 86_400_000 }))
      }
    }, LEGACY_KEY)
    const page = await ctx.newPage()
    await page.goto(urls.home)
    await page.waitForLoadState("load")
    assert.equal(await page.locator(DIALOG).count(), 0, "no gate")
    const migrated = JSON.parse(await page.evaluate((k) => localStorage.getItem(k), KEY))
    assert.equal(migrated.minAge, 21)
    await ctx.close()
  })

  await check("under age: the exit page, where the gate never shows; not remembered", async () => {
    const ctx = await context(browser, foreign)
    const page = await ctx.newPage()
    await page.goto(urls.home)
    await page.locator(DIALOG).waitFor()
    await Promise.all([page.waitForURL((u) => u.href.replace(/\/+$/, "") === urls.exit_url.replace(/\/+$/, "")), page.locator(".nac-no").click()])
    await page.waitForLoadState("domcontentloaded")
    assert.equal(await page.locator(DIALOG).count(), 0, "no gate on the exit page")
    assert.match(await page.content(), /Come back when you are older/)
    await page.goto(urls.home)
    await page.locator(DIALOG).waitFor({ state: "visible" })
    await ctx.close()
  })

  await check("0 days: remembered in this tab only (sessionStorage)", async () => {
    urls = setup("on", "site", "0", "noexit")
    const ctx = await context(browser, foreign)
    const page = await ctx.newPage()
    await page.goto(urls.home)
    await page.locator(".nac-yes").click()
    assert.equal(await page.evaluate((k) => localStorage.getItem(k), KEY), null)
    assert.ok(await page.evaluate((k) => sessionStorage.getItem(k), KEY))
    await page.goto(urls.product_url)
    assert.equal(await page.locator(DIALOG).count(), 0)
    const tab = await ctx.newPage()
    await tab.goto(urls.home)
    await tab.locator(DIALOG).waitFor({ state: "visible" })
    await ctx.close()
  })

  await check("date of birth: an invalid date is refused, under age goes to the exit page; of age is remembered; the date of birth is stored nowhere and sent nowhere", async () => {
    urls = setup("on", "site", "30", "exit", "dob", "verify", "brand")
    const seen = []
    const ctx = await context(browser, foreign, seen)
    const page = await ctx.newPage()
    await page.goto(urls.home)
    await page.locator(DIALOG).waitFor({ state: "visible" })
    assert.equal(await page.locator("#navexa-agecheck-gate-title").textContent(), "Hello from Gate Test Store", "{site}")
    assert.equal(await page.locator("#navexa-agecheck-gate-msg").textContent(), "Only 18+ past this point.", "{age}")
    const colors = await page.evaluate(() => {
      const card = document.querySelector('[data-navexa-agecheck="gate"] .nac-card')
      const yes = document.querySelector('[data-navexa-agecheck="gate"] .nac-yes')
      return { card: getComputedStyle(card).backgroundColor, text: getComputedStyle(card).color, button: getComputedStyle(yes).backgroundColor, buttonText: getComputedStyle(yes).color, label: yes.textContent }
    })
    assert.deepEqual(colors, { card: "rgb(255, 251, 230)", text: "rgb(34, 34, 34)", button: "rgb(10, 107, 61)", buttonText: "rgb(255, 255, 255)", label: "Go" })
    await page.fill(".nac-month", "13")
    await page.fill(".nac-day", "40")
    await page.fill(".nac-year", "1990")
    await page.click(".nac-yes")
    assert.match(await page.locator(".nac-err").textContent(), /real date of birth/)
    const year = String(new Date().getFullYear() - 10)
    await page.fill(".nac-month", "4")
    await page.fill(".nac-day", "17")
    await page.fill(".nac-year", year)
    await Promise.all([page.waitForURL((u) => u.href.replace(/\/+$/, "") === urls.exit_url.replace(/\/+$/, "")), page.click(".nac-yes")])
    await page.goto(urls.home)
    await page.locator(DIALOG).waitFor({ state: "visible" })
    await page.fill(".nac-month", "4")
    await page.fill(".nac-day", "17")
    await page.fill(".nac-year", "1987")
    await page.click(".nac-yes")
    await page.locator(DIALOG).waitFor({ state: "detached" })
    const storage = await page.evaluate(() => JSON.stringify({ ...localStorage, ...sessionStorage }))
    assert.doesNotMatch(storage, /1987|04-17|4\/17/, "the date of birth is not stored")
    assert.ok(seen.every((r) => !r.includes("1987") && !r.includes(year)), "the date of birth is in no request")
    await page.reload()
    await page.waitForLoadState("domcontentloaded")
    assert.equal(await page.locator(DIALOG).count(), 0, "remembered")
    await ctx.close()
    urls = setup("on", "site", "30", "exit")
  })

  await check("gate-only with no keys: the gate shows at the gate's own minimum age", async () => {
    urls = setup("on", "site", "30", "exit", "attest", "gate_only")
    const ctx = await context(browser, foreign)
    const page = await ctx.newPage()
    await page.goto(urls.home)
    await page.locator(DIALOG).waitFor({ state: "visible" })
    assert.equal(await page.locator(".nac-yes").textContent(), "I am 19 or older")
    await ctx.close()
    urls = setup("on", "site", "30", "exit")
  })

  await check("no dialog on the login page or in wp-admin", async () => {
    // wp-admin loads Gravatar avatars itself: not a storefront page, not recorded.
    const ctx = await context(browser, [])
    const page = await ctx.newPage()
    await page.goto(urls.login_url)
    assert.equal(await page.locator(DIALOG).count(), 0, "login page")
    await page.fill("#user_login", "admin")
    await page.fill("#user_pass", "password")
    await Promise.all([page.waitForURL(/wp-admin/), page.click("#wp-submit")])
    assert.equal(await page.locator(DIALOG).count(), 0, "wp-admin")
    assert.ok(!carries(await page.content()))
    await ctx.close()
  })

  await check("restricted scope: only restricted product and category pages", async () => {
    setup("on", "restricted", "30", "noexit")
    const ctx = await context(browser, foreign)
    const page = await ctx.newPage()
    const gated = async (url) => {
      await page.goto(url)
      await page.waitForLoadState("domcontentloaded")
      return (await page.locator(DIALOG).count()) > 0
    }
    assert.equal(await gated(urls.home), false, "home")
    assert.equal(await gated(urls.exempt_url), false, "exempt product")
    assert.equal(await gated(urls.product_url), true, "restricted product")
    assert.equal(await gated(urls.category_url), true, "its category")
    await ctx.close()
  })

  await check("no request left the store's origin", async () => {
    assert.deepEqual(foreign, [])
  })

  await browser.close()
}

setup("off")
if (failures > 0) {
  console.error(`${failures} check(s) failed`)
  process.exit(1)
}
console.log("entry gate browser checks passed")
