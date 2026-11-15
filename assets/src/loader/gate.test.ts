// Entry age gate tests: node --test, a fake document and storage.
import { test } from "node:test"
import assert from "node:assert/strict"
import {
  ROOT_ATTRIBUTE,
  ROOT_CLASS,
  STORAGE_KEY,
  STYLE,
  answer,
  isExitPage,
  isRemembered,
  nextFocus,
  readConfig,
  remember,
  remembered,
  runGate,
  type GateConfig,
  type GateDocument,
  type GateElement,
  type GateEvent,
  type StorageLike,
} from "./gate.ts"

const DAY = 86_400_000
const NOW = Date.UTC(2026, 10, 15, 12)

const CONFIG: GateConfig = {
  v: 1,
  minimumAge: 21,
  days: 30,
  exitUrl: "",
  i18n: { title: "Welcome to Shop", body: "You must be 21 or older.", yes: "I am 21 or older", no: "I am under 21", denied: "Sorry, you must be 21 or older.", leaving: "Leaving…" },
}

type Listener = (event: GateEvent) => void

class FakeElement implements GateElement {
  tagName: string
  textContent: string | null = ""
  hidden = false
  className = ""
  attrs: Record<string, string> = {}
  childList: FakeElement[] = []
  parent: FakeElement | null = null
  listeners: Record<string, Listener[]> = {}
  private doc: FakeDocument
  constructor(tag: string, doc: FakeDocument) {
    this.tagName = tag.toUpperCase()
    this.doc = doc
  }
  get children() {
    return this.childList
  }
  setAttribute(name: string, value: string) {
    this.attrs[name] = value
  }
  getAttribute(name: string) {
    return name in this.attrs ? this.attrs[name] : null
  }
  removeAttribute(name: string) {
    delete this.attrs[name]
  }
  hasAttribute(name: string) {
    return name in this.attrs
  }
  appendChild(child: GateElement) {
    const c = child as FakeElement
    c.parent = this
    this.childList.push(c)
    return c
  }
  removeChild(child: GateElement) {
    this.childList = this.childList.filter((c) => c !== child)
    ;(child as FakeElement).parent = null
    return child
  }
  contains(other: unknown): boolean {
    if (other === this) return true
    return this.childList.some((c) => c.contains(other))
  }
  addEventListener(type: string, listener: Listener) {
    ;(this.listeners[type] ??= []).push(listener)
  }
  focus() {
    const focusable = this.tagName === "BUTTON" || this.hasAttribute("tabindex")
    if (!focusable || this.hasAttribute("inert")) return
    this.doc.activeElement = this
    this.doc.dispatch("focusin", { target: this })
  }
  click() {
    for (const l of this.listeners.click ?? []) l(event())
  }
  find(predicate: (e: FakeElement) => boolean): FakeElement | null {
    if (predicate(this)) return this
    for (const c of this.childList) {
      const hit = c.find(predicate)
      if (hit) return hit
    }
    return null
  }
}

function event(extra: Partial<GateEvent> = {}): GateEvent & { prevented: boolean; stopped: boolean } {
  const e = {
    prevented: false,
    stopped: false,
    preventDefault() {
      e.prevented = true
    },
    stopPropagation() {
      e.stopped = true
    },
    ...extra,
  }
  return e
}

class FakeDocument implements GateDocument {
  readyState = "complete"
  activeElement: unknown = null
  documentElement: FakeElement
  head: FakeElement
  body: FakeElement
  listeners: Record<string, Listener[]> = {}
  constructor() {
    this.documentElement = new FakeElement("html", this)
    this.head = new FakeElement("head", this)
    this.body = new FakeElement("body", this)
    this.activeElement = this.body
  }
  createElement(tag: string) {
    return new FakeElement(tag, this)
  }
  addEventListener(type: string, listener: Listener) {
    ;(this.listeners[type] ??= []).push(listener)
  }
  removeEventListener(type: string, listener: Listener) {
    this.listeners[type] = (this.listeners[type] ?? []).filter((l) => l !== listener)
  }
  dispatch(type: string, extra: Partial<GateEvent> = {}) {
    const e = event(extra)
    for (const l of [...(this.listeners[type] ?? [])]) l(e)
    return e
  }
}

class MemoryStorage implements StorageLike {
  data = new Map<string, string>()
  getItem(key: string) {
    return this.data.has(key) ? (this.data.get(key) as string) : null
  }
  setItem(key: string, value: string) {
    this.data.set(key, value)
  }
}

const throwing: StorageLike = {
  getItem() {
    throw new Error("SecurityError")
  },
  setItem() {
    throw new Error("QuotaExceededError")
  },
}

function setup(config: Partial<GateConfig> = {}, opts: { href?: string; local?: StorageLike | null; session?: StorageLike | null; readyState?: string } = {}) {
  const doc = new FakeDocument()
  doc.readyState = opts.readyState ?? "complete"
  const content = doc.createElement("main")
  const link = doc.createElement("a")
  link.setAttribute("tabindex", "0")
  content.appendChild(link)
  doc.body.appendChild(content)
  const already = doc.createElement("div")
  already.setAttribute("inert", "")
  doc.body.appendChild(already)
  const local = opts.local === undefined ? new MemoryStorage() : opts.local
  const session = opts.session === undefined ? new MemoryStorage() : opts.session
  const assigned: string[] = []
  const watch: { added: ((node: GateElement) => void) | null; disconnected: number } = { added: null, disconnected: 0 }
  const handle = runGate(
    {
      document: doc,
      href: opts.href ?? "https://shop.example/product/vape/",
      local,
      session,
      now: () => NOW,
      assign: (url) => assigned.push(url),
      watchChildren: (_body, added) => {
        watch.added = added
        return {
          disconnect() {
            watch.disconnected++
            watch.added = null
          },
        }
      },
    },
    { ...CONFIG, ...config },
  )
  const gate = () => doc.body.find((e) => e.className === ROOT_CLASS)
  const dialog = () => doc.body.find((e) => e.getAttribute("role") === "dialog")
  const button = (cls: string) => doc.body.find((e) => e.className.includes(cls))
  return { doc, handle, content, link, already, local, session, assigned, gate, dialog, button, watch }
}

test("readConfig refuses malformed configuration", () => {
  assert.deepEqual(readConfig(CONFIG), CONFIG)
  for (const bad of [null, "x", { ...CONFIG, v: 2 }, { ...CONFIG, minimumAge: "21" }, { ...CONFIG, minimumAge: 0 }, { ...CONFIG, days: -1 }, { ...CONFIG, days: 1.5 }, { ...CONFIG, i18n: null }]) {
    assert.equal(readConfig(bad), null, JSON.stringify(bad))
  }
  assert.equal(readConfig({ ...CONFIG, exitUrl: "javascript:alert(1)" })?.exitUrl, "", "only an http(s) exit URL")
  assert.equal(readConfig({ ...CONFIG, exitUrl: "/relative" })?.exitUrl, "")
  assert.equal(readConfig({ ...CONFIG, exitUrl: "https://example.org/bye" })?.exitUrl, "https://example.org/bye")
})

test("a remembered answer covers the gate while it is young enough and for the age asked", () => {
  const yes = answer(21, NOW - 29 * DAY)
  assert.equal(remembered(yes, 21, 30, NOW), true)
  assert.equal(remembered(answer(21, NOW - 30 * DAY), 21, 30, NOW), false, "expired after the configured days")
  assert.equal(remembered(yes, 21, 7, NOW), false, "a shorter setting shortens old answers too")
  assert.equal(remembered(answer(18, NOW), 21, 30, NOW), false, "a raised minimum age asks again")
  assert.equal(remembered(answer(21, NOW), 18, 30, NOW), true)
  assert.equal(remembered(answer(21, NOW + DAY), 21, 30, NOW), false, "an answer from the future is not trusted")
  assert.equal(remembered(answer(21, NOW - 400 * DAY), 21, 0, NOW), true, "0 days: no age limit (isRemembered reads only sessionStorage then)")
  for (const junk of [null, "", "yes", "{}", "[1]", '{"v":2,"age":21,"at":1}', '{"v":1,"age":"21","at":1}']) {
    assert.equal(remembered(junk, 21, 30, NOW), false, String(junk))
  }
})

test("remember writes local storage, or session storage for 0 days, and survives blocked storage", () => {
  const local = new MemoryStorage()
  const session = new MemoryStorage()
  assert.equal(remember(CONFIG, local, session, NOW), true)
  assert.deepEqual(JSON.parse(local.getItem(STORAGE_KEY) as string), { v: 1, age: 21, at: NOW })
  assert.equal(session.getItem(STORAGE_KEY), null)
  assert.equal(isRemembered(CONFIG, local, session, NOW + DAY), true)

  const sessionOnly = { ...CONFIG, days: 0 }
  const local2 = new MemoryStorage()
  assert.equal(remember(sessionOnly, local2, session, NOW), true)
  assert.equal(local2.getItem(STORAGE_KEY), null, "0 days: nothing outlives the browser session")
  assert.equal(isRemembered(sessionOnly, local2, session, NOW), true)

  // 0 days after a longer setting: an answer left in localStorage never counts (it has no age limit at 0 days).
  const kept = new MemoryStorage()
  kept.setItem(STORAGE_KEY, answer(21, NOW - 400 * DAY))
  assert.equal(isRemembered(sessionOnly, kept, new MemoryStorage(), NOW), false, "0 days: a year-old localStorage answer opens the gate")
  kept.setItem(STORAGE_KEY, answer(21, NOW - DAY))
  assert.equal(isRemembered(sessionOnly, kept, new MemoryStorage(), NOW), false, "0 days: a recent localStorage answer opens the gate too")
  assert.equal(isRemembered(CONFIG, kept, new MemoryStorage(), NOW), true, "30 days: the same answer counts")
  assert.equal(setup({ days: 0 }, { local: kept }).handle?.isOpen(), true, "the gate opens")

  assert.equal(remember(CONFIG, throwing, null, NOW), false)
  assert.equal(remember(CONFIG, null, null, NOW), false)
  assert.equal(isRemembered(CONFIG, throwing, throwing, NOW), false, "blocked storage remembers nothing")
})

test("isExitPage compares origin and path only", () => {
  assert.equal(isExitPage("https://shop.example/goodbye/", "https://shop.example/goodbye?x=1#y"), true)
  assert.equal(isExitPage("https://SHOP.example/goodbye", "https://shop.example/goodbye/"), true)
  assert.equal(isExitPage("https://shop.example/goodbye/", "https://shop.example/"), false)
  assert.equal(isExitPage("https://example.org/", "https://shop.example/"), false)
  assert.equal(isExitPage("", "https://shop.example/"), false)
})

test("nextFocus wraps both ways and enters from outside", () => {
  const items = ["yes", "no"]
  assert.equal(nextFocus(items, "yes", false), "no")
  assert.equal(nextFocus(items, "no", false), "yes", "Tab wraps")
  assert.equal(nextFocus(items, "yes", true), "no", "Shift+Tab wraps")
  assert.equal(nextFocus(items, "title", false), "yes")
  assert.equal(nextFocus(items, "title", true), "no")
  assert.equal(nextFocus([], "x", false), null)
})

test("the gate hides the page and opens an accessible modal dialog", () => {
  const { doc, handle, dialog, button, content, already } = setup()
  assert.ok(handle?.isOpen())
  assert.equal(doc.documentElement.hasAttribute(ROOT_ATTRIBUTE), true, "the page is hidden at once")
  assert.match(doc.head.childList[0].textContent ?? "", /visibility:hidden/)
  const d = dialog() as FakeElement
  assert.equal(d.getAttribute("aria-modal"), "true")
  assert.equal(d.getAttribute("aria-labelledby"), `${ROOT_CLASS}-title`)
  assert.equal(d.getAttribute("aria-describedby"), `${ROOT_CLASS}-text`)
  const title = d.find((e) => e.getAttribute("id") === `${ROOT_CLASS}-title`) as FakeElement
  assert.equal(title.textContent, "Welcome to Shop")
  assert.equal(doc.activeElement, title, "the focus starts on the question")
  assert.equal(button("__yes")?.getAttribute("type"), "button")
  assert.equal(button("__yes")?.textContent, "I am 21 or older")
  assert.equal(button("__no")?.textContent, "I am under 21")
  assert.equal(content.getAttribute("inert"), "")
  assert.equal(content.getAttribute("aria-hidden"), "true")
  assert.equal(already.getAttribute("aria-hidden"), null, "an element inert already is left alone")
})

test("closing puts back each node's own aria-hidden", () => {
  const { doc, handle, button, watch } = setup({}, { readyState: "loading" })
  const lightbox = doc.createElement("div")
  lightbox.setAttribute("aria-hidden", "true") // WooCommerce's PhotoSwipe container, a body child.
  doc.body.appendChild(lightbox)
  const shown = doc.createElement("div")
  shown.setAttribute("aria-hidden", "false")
  doc.body.appendChild(shown)
  doc.dispatch("DOMContentLoaded")
  assert.ok(handle?.isOpen())
  const later = doc.createElement("div")
  later.setAttribute("aria-hidden", "true")
  doc.body.appendChild(later)
  watch.added?.(later)
  for (const node of [lightbox, shown, later]) assert.equal(node.getAttribute("aria-hidden"), "true", "hidden while open")
  ;(button("__yes") as FakeElement).click()
  assert.equal(lightbox.getAttribute("aria-hidden"), "true", "a page's own aria-hidden survives")
  assert.equal(shown.getAttribute("aria-hidden"), "false")
  assert.equal(later.getAttribute("aria-hidden"), "true")
  assert.equal(lightbox.hasAttribute("inert"), false)
})

test("the style is sized in px and em and scoped above common theme rules", () => {
  assert.doesNotMatch(STYLE, /[0-9.]rem\b/, "no rem: html{font-size:62.5%} would shrink it")
  assert.match(STYLE, new RegExp(`html\\[${ROOT_ATTRIBUTE}\\] \\.${ROOT_CLASS}\\{[^}]*font-size:16px`))
  for (const rule of STYLE.split("\n").filter((r) => r.includes(`.${ROOT_CLASS}__`))) {
    assert.ok(rule.startsWith(`html[${ROOT_ATTRIBUTE}] .${ROOT_CLASS} `), rule)
  }
  assert.match(STYLE, new RegExp(`\\.${ROOT_CLASS}__actions \\.${ROOT_CLASS}__yes\\{`), "button colours at 0,4,1: above button:not(:hover):not(:active):not(.has-background)")
})

test("what scripts add to the page later is inert too, until the gate closes", () => {
  const { doc, handle, button, watch } = setup()
  const widget = doc.createElement("div")
  doc.body.appendChild(widget)
  watch.added?.(widget)
  assert.equal(widget.getAttribute("inert"), "")
  assert.equal(widget.getAttribute("aria-hidden"), "true")
  ;(button("__yes") as FakeElement).click()
  assert.equal(handle?.isOpen(), false)
  assert.equal(widget.hasAttribute("inert"), false)
  assert.equal(watch.disconnected, 1, "stops watching")
})

test("the focus stays in the dialog and Escape does not dismiss it", () => {
  const { doc, handle, button, link } = setup()
  const yes = button("__yes") as FakeElement
  const no = button("__no") as FakeElement
  const tab = (shiftKey = false) => doc.dispatch("keydown", { key: "Tab", shiftKey })
  assert.equal(tab().prevented, true)
  assert.equal(doc.activeElement, yes)
  tab()
  assert.equal(doc.activeElement, no)
  tab()
  assert.equal(doc.activeElement, yes, "Tab wraps")
  tab(true)
  assert.equal(doc.activeElement, no, "Shift+Tab wraps")

  const escape = doc.dispatch("keydown", { key: "Escape" })
  assert.equal(escape.prevented, true)
  assert.equal(escape.stopped, true)
  assert.ok(handle?.isOpen(), "Escape does not dismiss")

  link.removeAttribute("inert") // A browser without inert: the focus is pulled back.
  doc.activeElement = link
  doc.dispatch("focusin", { target: link })
  assert.equal(doc.activeElement, yes)
})

test("yes remembers the answer, restores the page and closes", () => {
  const { doc, handle, button, gate, content, local } = setup()
  ;(button("__yes") as FakeElement).click()
  assert.equal(handle?.isOpen(), false)
  assert.equal(gate(), null)
  assert.equal(doc.documentElement.hasAttribute(ROOT_ATTRIBUTE), false)
  assert.equal(content.hasAttribute("inert"), false)
  assert.equal(content.hasAttribute("aria-hidden"), false)
  assert.ok(remembered(local?.getItem(STORAGE_KEY) ?? null, 21, 30, NOW + 1))
  assert.equal(doc.listeners.keydown.length, 0, "no key handling left behind")

  const again = setup({}, { local })
  assert.equal(again.handle, null, "remembered: no gate")
  assert.equal(again.doc.documentElement.hasAttribute(ROOT_ATTRIBUTE), false)
})

test("no without an exit URL keeps the gate and says why; with one, leaves", () => {
  const stay = setup()
  ;(stay.button("__no") as FakeElement).click()
  assert.ok(stay.handle?.isOpen())
  const denied = stay.dialog()?.find((e) => e.getAttribute("role") === "alert") as FakeElement
  assert.equal(denied.hidden, false)
  assert.equal(denied.textContent, "Sorry, you must be 21 or older.")
  assert.equal(stay.local?.getItem(STORAGE_KEY), null, "a no is not remembered")

  const leave = setup({ exitUrl: "https://example.org/bye" })
  ;(leave.button("__no") as FakeElement).click()
  assert.deepEqual(leave.assigned, ["https://example.org/bye"])
})

test("no gate on the exit page, with a remembered answer, or without configuration", () => {
  assert.equal(setup({ exitUrl: "https://shop.example/product/vape" }).handle, null)
  const local = new MemoryStorage()
  local.setItem(STORAGE_KEY, answer(21, NOW - DAY))
  assert.equal(setup({}, { local }).handle, null)
  const doc = new FakeDocument()
  assert.equal(runGate({ document: doc, href: "https://shop.example/", local: null, session: null, now: () => NOW, assign: () => {} }, undefined), null)
  assert.equal(doc.documentElement.hasAttribute(ROOT_ATTRIBUTE), false)
})

test("blocked storage still gates (and asks again next time)", () => {
  const { handle, button } = setup({}, { local: throwing, session: throwing })
  assert.ok(handle?.isOpen())
  ;(button("__yes") as FakeElement).click()
  assert.equal(handle?.isOpen(), false, "yes still lets the visitor in")
})

test("loaded in the head, the dialog opens when the body is parsed", () => {
  const { doc, handle } = setup({}, { readyState: "loading" })
  assert.equal(handle?.isOpen(), false)
  assert.equal(doc.documentElement.hasAttribute(ROOT_ATTRIBUTE), true, "hidden before the body renders")
  doc.dispatch("DOMContentLoaded")
  assert.equal(handle?.isOpen(), true)
  assert.equal(doc.listeners.DOMContentLoaded.length, 0)
})

test("an error never leaves the page hidden", () => {
  const doc = new FakeDocument()
  doc.createElement = () => {
    throw new Error("boom")
  }
  const handle = runGate({ document: doc, href: "https://shop.example/", local: null, session: null, now: () => NOW, assign: () => {} }, CONFIG)
  assert.equal(handle, null)
  assert.equal(doc.documentElement.hasAttribute(ROOT_ATTRIBUTE), false)

  const late = new FakeDocument()
  late.readyState = "loading"
  const gate = runGate({ document: late, href: "https://shop.example/", local: null, session: null, now: () => NOW, assign: () => {} }, CONFIG)
  late.createElement = () => {
    throw new Error("boom")
  }
  late.dispatch("DOMContentLoaded")
  assert.equal(gate?.isOpen(), false)
  assert.equal(late.documentElement.hasAttribute(ROOT_ATTRIBUTE), false)
})
