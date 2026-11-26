// Accessibility helper tests: node --test, a fake document.
import { test } from "node:test"
import assert from "node:assert/strict"
import { ENABLED_HOLD_MS, ENABLED_WAIT_MS, FOCUS_RETRIES, FOCUS_RETRY_MS, LIVE_REGION_ID, announce, focusElement, focusOnReturn, focusWhenEnabled, isEnabled, liveRegion, mayRestoreFocus, type ControlLike, type DocLike, type ElementLike } from "./a11y.ts"

function el(id = ""): ElementLike & { attrs: Record<string, string>; children: ElementLike[]; focused: number } {
  const node = {
    id,
    textContent: "" as string | null,
    attrs: {} as Record<string, string>,
    children: [] as ElementLike[],
    focused: 0,
    setAttribute(name: string, value: string) {
      node.attrs[name] = value
    },
    appendChild(child: ElementLike) {
      node.children.push(child)
      return child
    },
    contains(other: unknown) {
      return other === node || node.children.includes(other as ElementLike)
    },
    focus() {
      node.focused++
    },
  }
  return node
}

function doc(): DocLike & { created: number } {
  const body = el("body")
  const d = {
    created: 0,
    body,
    activeElement: body as unknown,
    getElementById(id: string) {
      return (body.children.find((c) => c.id === id) as ElementLike | undefined) ?? null
    },
    createElement() {
      d.created++
      return el()
    },
  }
  return d
}

test("one polite, atomic, visually hidden live region per page", () => {
  const d = doc()
  const region = liveRegion(d) as ReturnType<typeof el>
  assert.equal(region.id, LIVE_REGION_ID)
  assert.equal(region.attrs.role, "status")
  assert.equal(region.attrs["aria-live"], "polite")
  assert.equal(region.attrs["aria-atomic"], "true")
  assert.match(region.attrs.style, /clip:rect\(0,0,0,0\)/)
  assert.equal(liveRegion(d), region)
  assert.equal(d.created, 1)
})

test("announce empties the region, then fills it on the next tick", () => {
  const d = doc()
  const queued: Array<() => void> = []
  assert.equal(announce(d, "Age verified.", (fn) => queued.push(fn)), "Age verified.")
  const region = liveRegion(d)!
  assert.equal(region.textContent, "")
  queued.shift()!()
  assert.equal(region.textContent, "Age verified.")
  announce(d, "Age verified.", (fn) => queued.push(fn))
  assert.equal(region.textContent, "", "the same text is announced again")
  assert.equal(announce(d, "", (fn) => queued.push(fn)), "")
})

test("the focus moves back only after a check the shopper started, and only when it was lost or still inside", () => {
  const d = doc()
  const container = el("box")
  const button = el("button")
  container.appendChild(button)
  assert.equal(mayRestoreFocus(d, container, false), false, "not the shopper's check")
  d.activeElement = d.body
  assert.equal(mayRestoreFocus(d, container, true), true, "lost to the body (the button was removed)")
  d.activeElement = null
  assert.equal(mayRestoreFocus(d, container, true), true)
  d.activeElement = button
  assert.equal(mayRestoreFocus(d, container, true), true, "still on the button")
  d.activeElement = el("email field")
  assert.equal(mayRestoreFocus(d, container, true), false, "the shopper moved on")
  assert.equal(mayRestoreFocus(d, null, true), false)
})

test("focusElement focuses what can be focused", () => {
  const target = el("message")
  assert.equal(focusElement(target), true)
  assert.equal(target.focused, 1)
  assert.equal(focusElement(null), false)
  const broken = el("x")
  broken.focus = () => {
    throw new Error("detached")
  }
  assert.equal(focusElement(broken), false)
})

test("focusOnReturn tries again while the browser ignores focus(), and stops when the shopper moves on", () => {
  const timers: Array<() => void> = []
  const win = { document: { activeElement: null as unknown }, setTimeout: (fn: () => void) => void timers.push(fn) }
  const ignored = el("group") // focus() does not move the focus (Firefox, right after a popup closed).
  assert.equal(focusOnReturn(ignored, win), true)
  assert.equal(ignored.focused, 1)
  timers.shift()!()
  assert.equal(ignored.focused, 2, "tried again")
  win.document.activeElement = ignored // It took.
  timers.shift()!()
  assert.equal(ignored.focused, 2)
  assert.equal(timers.length, 0, "stops once the focus is there")

  const target = el("group")
  let shopperMoved = false
  focusOnReturn(target, win, () => !shopperMoved)
  shopperMoved = true
  win.document.activeElement = null
  timers.shift()!()
  assert.equal(target.focused, 1, "the shopper moved the focus: left alone")
  assert.equal(timers.length, 0)

  const stubborn = el("group")
  focusOnReturn(stubborn, win)
  while (timers.length) timers.shift()!()
  assert.equal(stubborn.focused, 1 + FOCUS_RETRIES, "gives up after FOCUS_RETRIES")
  assert.equal(focusOnReturn(null, win), false)
})

/** A button that takes the focus in $d (focus() moves activeElement), disabled until enabled. */
function control(d: { activeElement: unknown }, id = "place order"): ControlLike & { focused: number; options: unknown[]; aria: string | null } {
  const node = {
    ...el(id),
    disabled: true,
    aria: null as string | null,
    options: [] as unknown[],
    getAttribute(name: string) {
      return name === "aria-disabled" ? node.aria : null
    },
    focus(options?: unknown) {
      node.focused++
      node.options.push(options)
      d.activeElement = node
    },
  }
  return node
}

test("isEnabled: neither disabled nor aria-disabled", () => {
  const d = doc()
  const button = control(d)
  assert.equal(isEnabled(button), false)
  button.disabled = false
  assert.equal(isEnabled(button), true)
  button.aria = "true"
  assert.equal(isEnabled(button), false)
})

test("focusWhenEnabled waits for the control to be enabled on two looks, focuses it without scrolling, and holds it through a later disable", () => {
  const d = doc()
  const timers: Array<() => void> = []
  const win = { setTimeout: (fn: () => void) => void timers.push(fn) }
  const button = control(d)
  focusWhenEnabled(() => button, d, win)
  timers.shift()!()
  timers.shift()!()
  assert.equal(button.focused, 0, "disabled while WooCommerce awaits the observer")
  button.disabled = false
  timers.shift()!()
  assert.equal(button.focused, 0, "enabled on one look only: WooCommerce may not have drawn its notice yet")
  timers.shift()!()
  assert.equal(button.focused, 1)
  assert.deepEqual(button.options, [{ preventScroll: true }], "WooCommerce scrolled to its error notice")
  assert.equal(d.activeElement, button)
  // A cart update disables it again right after: the focus falls to the body and comes back.
  button.disabled = true
  d.activeElement = d.body
  timers.shift()!()
  button.disabled = false
  timers.shift()!()
  timers.shift()!()
  assert.equal(button.focused, 2)
  for (let i = 0; i < ENABLED_HOLD_MS / FOCUS_RETRY_MS && timers.length; i++) timers.shift()!()
  assert.equal(timers.length, 0, "stops once the focus has stayed on the control")
})

test("focusWhenEnabled leaves focus the shopper (or a dialog) moved alone, gives up after ENABLED_WAIT_MS, and can be stopped", () => {
  const d = doc()
  const timers: Array<() => void> = []
  const win = { setTimeout: (fn: () => void) => void timers.push(fn) }
  const button = control(d)
  focusWhenEnabled(() => button, d, win)
  d.activeElement = el("email field")
  button.disabled = false
  timers.shift()!()
  timers.shift()?.()
  assert.equal(button.focused, 0)
  assert.equal(timers.length, 0)

  d.activeElement = d.body
  const never = control(d)
  let looks = 0
  focusWhenEnabled(() => (looks++, never), d, win)
  while (timers.length) timers.shift()!()
  assert.equal(never.focused, 0)
  assert.equal(looks, ENABLED_WAIT_MS / FOCUS_RETRY_MS, "gives up")

  const missing = { n: 0 }
  focusWhenEnabled(() => (missing.n++, null), d, win)
  while (timers.length) timers.shift()!()
  assert.equal(missing.n, ENABLED_WAIT_MS / FOCUS_RETRY_MS, "no button: looks, focuses nothing")

  const stopped = control(d)
  stopped.disabled = false
  const stop = focusWhenEnabled(() => stopped, d, win)
  stop()
  while (timers.length) timers.shift()!()
  assert.equal(stopped.focused, 0)
})
