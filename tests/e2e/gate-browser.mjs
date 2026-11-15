#!/usr/bin/env node
// Browser check of the entry age gate (W3 PR 13; self-attestation, not
// verification) against wp-env and the mock engine, in Chromium by default.
//
// Needs wp-env with the mock running (as for tests/e2e/prepayment-browser.mjs)
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
// which pages carry the gate script, in the head, with the same
// configuration for every visitor and no cookie.
//
// Checks: off by default and when off; the dialog's ARIA, initial focus, Tab
// and Shift+Tab trapped, Escape ignored, the page hidden and inert; "under
// age" goes to the exit page, where the gate never shows, and is not
// remembered; "of age" by keyboard closes it and is remembered across pages
// and reloads (localStorage; sessionStorage for 0 days, one tab, where a
// localStorage answer does not count) and puts back the page's own
// aria-hidden; a theme's root font size and button rules do not restyle the
// dialog; both script tags carry the optimizer opt-outs; the minimum age and
// store name come from GetIntegrationInfo (the mock); restricted scope gates
// only restricted product and category pages; never on the login page,
// wp-admin, REST or the AgeCheck return page; no request leaves the store's
// origin; the server's HTML is the same whatever the visitor answered (page
// caching) and sets no cookie.

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
// The gate's own dialog: WooCommerce's product gallery (PhotoSwipe) and wp-admin have dialogs too.
const DIALOG = '.navexa-agecheck-gate [role="dialog"][aria-modal="true"]'

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

/** A fresh context recording every request that leaves the store's origin. */
async function context(browser, foreign) {
  const ctx = await browser.newContext()
  ctx.on("request", (req) => {
    const url = req.url()
    if (url.startsWith("data:") || url.startsWith("blob:")) return
    if (new URL(url).origin !== ORIGIN) foreign.push(url)
  })
  return ctx
}

const gateScript = /entry-gate(\.min)?\.js/

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

console.log("# HTTP")
await mock("config", { org: { minimumAge: 21, displayName: "Mock Store", allowedOrigins: [SITE] } })
let urls = setup("off")

await check("off: no page carries the gate", async () => {
  for (const url of [urls.home, urls.product_url, urls.category_url]) assert.doesNotMatch((await html(url)).text, gateScript, url)
})

await mock("config", { org: { minimumAge: 18, displayName: "Gate Test Store" } })
urls = setup("on", "site", "30", "exit")
assert.equal(urls.minimum_age, 18, "the mock's minimum age reached the store")
assert.equal(urls.display_name, "Gate Test Store")

await check("site scope: the script in the head, the same configuration for everyone, no cookie", async () => {
  const first = await html(urls.home)
  const head = first.text.slice(0, first.text.indexOf("</head>"))
  assert.match(head, gateScript, "loaded in the head")
  assert.match(head, /window\.navexaAgeCheckGate = /)
  const config = (text) => /window\.navexaAgeCheckGate = (\{.*?\});/.exec(text)?.[1]
  const second = await html(urls.home, { Cookie: "navexa-agecheck-gate=1" })
  assert.equal(config(first.text), config(second.text), "the server never reads an answer")
  assert.equal(first.res.headers.get("set-cookie"), null, "the gate sets no cookie")
  const parsed = JSON.parse(config(first.text))
  assert.deepEqual(Object.keys(parsed).sort(), ["days", "exitUrl", "i18n", "minimumAge", "v"])
  assert.equal(parsed.minimumAge, 18)
  assert.equal(parsed.i18n.title, "Welcome to Gate Test Store")
  assert.doesNotMatch(config(first.text), /txk_/)
  // Optimizer opt-outs on both tags (Rocket Loader reads data-cfasync before src).
  for (const id of ["navexa-agecheck-entry-gate-js", "navexa-agecheck-entry-gate-js-before"]) {
    const tag = new RegExp(`<script[^>]* id="${id}"[^>]*>`).exec(head)?.[0] ?? ""
    for (const attr of [/ data-cfasync="false"/, / data-no-optimize="1"/, / data-no-defer="1"/, / data-no-minify="1"/, / nowprocket(="nowprocket")?[ >]/]) assert.match(tag, attr, id)
    if (id.endsWith("-js")) assert.match(tag, / data-cfasync="false".* src="[^"]*entry-gate(\.min)?\.js/, "data-cfasync before src")
  }
  assert.match((await html(urls.exit_url)).text, gateScript, "the exit page carries it too; the script skips that page")
})

await check("never on the login page, wp-admin, REST or the AgeCheck return page", async () => {
  for (const url of [urls.login_url, urls.return_url, urls.rest_url, `${SITE}/wp-admin/`]) {
    assert.doesNotMatch((await html(url)).text, gateScript, url)
  }
})

await check("restricted scope: only restricted product and category pages", async () => {
  setup("on", "restricted", "30", "noexit")
  assert.doesNotMatch((await html(urls.home)).text, gateScript, "home")
  assert.doesNotMatch((await html(urls.exempt_url)).text, gateScript, "exempt product")
  assert.match((await html(urls.product_url)).text, gateScript, "restricted product")
  assert.match((await html(urls.category_url)).text, gateScript, "its category")
  setup("on", "site", "30", "exit")
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

  await check("site scope: an accessible modal dialog hides the page", async () => {
    const ctx = await context(browser, foreign)
    const page = await ctx.newPage()
    await page.goto(urls.home)
    const dialog = page.locator(DIALOG)
    await dialog.waitFor({ state: "visible" })
    const title = page.locator("#navexa-agecheck-gate-title")
    assert.equal(await title.textContent(), "Welcome to Gate Test Store", "GetIntegrationInfo display_name")
    assert.match(await page.locator("#navexa-agecheck-gate-text").textContent(), /18 or older/, "GetIntegrationInfo minimum_age")
    assert.equal(await dialog.getAttribute("aria-labelledby"), "navexa-agecheck-gate-title")
    assert.equal(await dialog.getAttribute("aria-describedby"), "navexa-agecheck-gate-text")
    assert.equal(await page.evaluate(() => document.activeElement?.id), "navexa-agecheck-gate-title", "focus starts on the question")
    const hidden = await page.evaluate(() => {
      const others = [...document.body.children].filter((el) => !el.classList.contains("navexa-agecheck-gate") && el.tagName !== "SCRIPT")
      return {
        attr: document.documentElement.hasAttribute("data-navexa-agecheck-gate"),
        allHidden: others.every((el) => getComputedStyle(el).visibility === "hidden"),
        allInert: others.every((el) => el.hasAttribute("inert") && el.getAttribute("aria-hidden") === "true"),
        count: others.length,
      }
    })
    assert.ok(hidden.count > 0)
    assert.deepEqual(hidden, { attr: true, allHidden: true, allInert: true, count: hidden.count })
    const box = await page.locator(".navexa-agecheck-gate__yes").boundingBox()
    assert.ok(box && box.height >= 44, "44 px touch target")
    await ctx.close()
  })

  await check("keyboard: Tab and Shift+Tab stay in the dialog, Escape does not dismiss, Enter answers", async () => {
    const ctx = await context(browser, foreign)
    // A body child the page itself marks aria-hidden (as WooCommerce's PhotoSwipe container), there before the gate opens.
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
    const focused = () => page.evaluate(() => document.activeElement?.className ?? "")
    await page.keyboard.press("Tab")
    assert.match(await focused(), /__yes/)
    await page.keyboard.press("Tab")
    assert.match(await focused(), /__no/)
    await page.keyboard.press("Tab")
    assert.match(await focused(), /__yes/, "Tab wraps")
    await page.keyboard.press("Shift+Tab")
    assert.match(await focused(), /__no/, "Shift+Tab wraps")
    for (let i = 0; i < 6; i++) await page.keyboard.press("Tab")
    assert.match(await focused(), /navexa-agecheck-gate__button/, "never leaves the dialog")
    await page.keyboard.press("Escape")
    assert.equal(await page.locator(DIALOG).isVisible(), true, "Escape does not dismiss")
    await page.locator(".navexa-agecheck-gate__yes").focus()
    await page.keyboard.press("Enter")
    await page.locator(DIALOG).waitFor({ state: "detached" })
    assert.equal(await page.evaluate(() => document.documentElement.hasAttribute("data-navexa-agecheck-gate")), false)
    assert.equal(await page.evaluate(() => [...document.body.children].some((el) => el.hasAttribute("inert"))), false, "the page is usable again")
    assert.equal(await page.getAttribute("#page-own-hidden", "aria-hidden"), "true", "the page's own aria-hidden is put back")
    const stored = JSON.parse(await page.evaluate(() => localStorage.getItem("navexa-agecheck-gate")))
    assert.equal(stored.v, 1)
    assert.equal(stored.age, 18)
    await page.reload()
    await page.waitForLoadState("domcontentloaded")
    assert.equal(await page.locator(DIALOG).count(), 0, "remembered on reload")
    await page.goto(urls.product_url)
    assert.equal(await page.locator(DIALOG).count(), 0, "remembered on other pages")
    assert.equal(await page.evaluate(() => document.cookie.includes("navexa")), false, "no cookie")
    await ctx.close()
  })

  await check("under age: the exit page, where the gate never shows; not remembered", async () => {
    const ctx = await context(browser, foreign)
    const page = await ctx.newPage()
    await page.goto(urls.home)
    await page.locator(DIALOG).waitFor()
    await Promise.all([page.waitForURL((u) => u.href.replace(/\/+$/, "") === urls.exit_url.replace(/\/+$/, "")), page.locator(".navexa-agecheck-gate__no").click()])
    await page.waitForLoadState("domcontentloaded")
    assert.equal(await page.locator(DIALOG).count(), 0, "no gate on the exit page")
    assert.match(await page.content(), /Come back when you are older/)
    await page.goto(urls.home)
    await page.locator(DIALOG).waitFor({ state: "visible" })
    await ctx.close()
  })

  await check("under age without an exit URL: the refusal is shown and announced", async () => {
    setup("on", "site", "30", "noexit")
    const ctx = await context(browser, foreign)
    const page = await ctx.newPage()
    await page.goto(urls.home)
    await page.locator(DIALOG).waitFor()
    await page.locator(".navexa-agecheck-gate__no").click()
    const alert = page.locator('[role="alert"]')
    assert.match(await alert.textContent(), /must be 18 or older/)
    assert.equal(await page.locator(DIALOG).isVisible(), true)
    assert.equal(await page.evaluate(() => localStorage.getItem("navexa-agecheck-gate")), null)
    await ctx.close()
  })

  await check("0 days: remembered for the browser session only (sessionStorage)", async () => {
    setup("on", "site", "0", "noexit")
    const ctx = await context(browser, foreign)
    const page = await ctx.newPage()
    await page.goto(urls.home)
    await page.locator(".navexa-agecheck-gate__yes").click()
    assert.equal(await page.evaluate(() => localStorage.getItem("navexa-agecheck-gate")), null)
    assert.ok(await page.evaluate(() => sessionStorage.getItem("navexa-agecheck-gate")))
    await page.goto(urls.product_url)
    assert.equal(await page.locator(DIALOG).count(), 0)
    // sessionStorage is one tab's: a new tab asks again, and a localStorage
    // answer (left by a longer setting) does not count at 0 days.
    const tab = await ctx.newPage()
    await tab.goto(urls.home)
    await tab.locator(DIALOG).waitFor({ state: "visible" })
    await tab.evaluate(() => localStorage.setItem("navexa-agecheck-gate", JSON.stringify({ v: 1, age: 18, at: Date.now() - 86_400_000 })))
    const another = await ctx.newPage()
    await another.goto(urls.home)
    await another.locator(DIALOG).waitFor({ state: "visible" })
    await ctx.close()
  })

  await check("a theme's root font size and button rules do not restyle the dialog", async () => {
    setup("on", "site", "30", "noexit")
    const ctx = await context(browser, foreign)
    const page = await ctx.newPage()
    await page.goto(urls.home)
    await page.locator(DIALOG).waitFor({ state: "visible" })
    // Twenty Twenty's and Twenty Fifteen's root size, Twenty Twenty-One's button rule (0,3,1), Storefront's button:hover; appended after the gate's style.
    await page.addStyleTag({
      content:
        "html{font-size:62.5%}h2{font-size:1.4rem;font-family:serif}p{font-size:1rem}" +
        "button:not(:hover):not(:active):not(.has-background){background-color:#28303d;color:#d1e4dd;font-size:1.2rem;text-transform:uppercase}" +
        "button:hover,button:focus{background:#999;color:#000}",
    })
    const styles = () =>
      page.evaluate(() => {
        const css = (sel) => getComputedStyle(document.querySelector(sel))
        const px = (sel) => parseFloat(css(sel).fontSize)
        return {
          title: px(".navexa-agecheck-gate__title"),
          text: px(".navexa-agecheck-gate__text"),
          yes: px(".navexa-agecheck-gate__yes"),
          no: px(".navexa-agecheck-gate__no"),
          yesBg: css(".navexa-agecheck-gate__yes").backgroundColor,
          noBg: css(".navexa-agecheck-gate__no").backgroundColor,
          transform: css(".navexa-agecheck-gate__yes").textTransform,
        }
      })
    const before = await styles()
    assert.ok(before.text >= 16 && before.yes >= 16 && before.no >= 16 && before.title >= 24, JSON.stringify(before))
    assert.equal(before.yesBg, "rgb(30, 30, 30)")
    assert.equal(before.noBg, "rgb(255, 255, 255)", "the under-age button stays secondary")
    assert.equal(before.transform, "none")
    await page.hover(".navexa-agecheck-gate__no")
    assert.equal((await styles()).noBg, "rgb(255, 255, 255)", "button:hover does not take over")
    await ctx.close()
    setup("on", "site", "30", "exit")
  })

  await check("off: no dialog", async () => {
    setup("off")
    const ctx = await context(browser, foreign)
    const page = await ctx.newPage()
    await page.goto(urls.home)
    await page.waitForLoadState("domcontentloaded")
    assert.equal(await page.locator(DIALOG).count(), 0)
    await ctx.close()
    setup("on", "site", "30", "exit")
  })

  await check("no dialog on the login page or in wp-admin", async () => {
    setup("on", "site", "30", "exit")
    // wp-admin loads Gravatar avatars itself: not a storefront page, not recorded.
    const ctx = await context(browser, [])
    const page = await ctx.newPage()
    await page.goto(urls.login_url)
    assert.equal(await page.locator(DIALOG).count(), 0, "login page")
    await page.fill("#user_login", "admin")
    await page.fill("#user_pass", "password")
    await Promise.all([page.waitForURL(/wp-admin/), page.click("#wp-submit")])
    assert.equal(await page.locator(DIALOG).count(), 0, "wp-admin")
    assert.doesNotMatch(await page.content(), gateScript)
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
