/**
 * Accessibility helpers for the Verify age button (WCAG 2.1 AA):
 *
 * - A persistent, visually hidden polite live region. The classic checkout
 *   replaces the button's markup on every checkout update, and a live region
 *   inserted with its text already in it is not announced by most screen
 *   readers, so status changes are announced through one region that stays.
 * - Focus restoration. When the shopper started the check (the focus was on
 *   the Verify age control) and the control is gone afterwards (the button is
 *   removed once the check passes, or the classic fragment was replaced), the
 *   focus goes to the status message (tabindex="-1") on a pass, otherwise to
 *   the Verify age button, never to the top of the page. Focus the shopper
 *   moved elsewhere is left alone.
 * - Focus handed to a control that is disabled for a moment
 *   (focusWhenEnabled(): the block checkout's Place order after the modal
 *   closed without a pass).
 */

export const LIVE_REGION_ID = "navexa-agecheck-live"

/** Inline style that hides an element visually but not from screen readers. */
export const VISUALLY_HIDDEN =
  "position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0"

/** The part of a document these helpers touch; tests pass fakes. */
export interface DocLike {
  getElementById(id: string): ElementLike | null
  createElement(tag: string): ElementLike
  body: ElementLike | null
  activeElement: unknown
}

export interface ElementLike {
  id: string
  textContent: string | null
  setAttribute(name: string, value: string): void
  appendChild(child: ElementLike): unknown
  contains?(other: unknown): boolean
  focus?(options?: { preventScroll?: boolean }): void
}

/** The page's live region, created once. */
export function liveRegion(doc: DocLike): ElementLike | null {
  const existing = doc.getElementById(LIVE_REGION_ID)
  if (existing) return existing
  if (!doc.body) return null
  const region = doc.createElement("div")
  region.id = LIVE_REGION_ID
  region.setAttribute("role", "status")
  region.setAttribute("aria-live", "polite")
  region.setAttribute("aria-atomic", "true")
  region.setAttribute("style", VISUALLY_HIDDEN)
  doc.body.appendChild(region)
  return region
}

/**
 * Announces $text through the live region. The region is emptied first and
 * filled on the next tick, so the same text twice is announced twice.
 */
export function announce(doc: DocLike, text: string, defer: (fn: () => void) => void = (fn) => void setTimeout(fn, 50)): string {
  const region = liveRegion(doc)
  if (!region || !text) return ""
  region.textContent = ""
  defer(() => {
    region.textContent = text
  })
  return text
}

/**
 * Whether the focus may be moved back to the Verify age control: the shopper
 * started the check from it ($owned), and the focus is still inside the
 * control's container, or was lost (on the body, or nowhere) because the
 * control was removed.
 */
export function mayRestoreFocus(doc: DocLike, container: ElementLike | null, owned: boolean): boolean {
  if (!owned) return false
  const active = doc.activeElement
  if (active === null || active === undefined || active === doc.body) return true
  return !!container && typeof container.contains === "function" && container.contains(active)
}

/** Focuses $target (the browser scrolls it into view unless $options.preventScroll); false when there is none. */
export function focusElement(target: ElementLike | null | undefined, options?: { preventScroll?: boolean }): boolean {
  if (!target || typeof target.focus !== "function") return false
  try {
    if (options) target.focus(options)
    else target.focus()
    return true
  } catch {
    return false
  }
}

/** The part of a window focusOnReturn() touches; tests pass fakes. */
export interface WinLike {
  document: { activeElement: unknown }
  setTimeout(fn: () => void, ms: number): unknown
}

/** Retries of focusOnReturn(), RETRY_MS apart. */
export const FOCUS_RETRIES = 10
export const FOCUS_RETRY_MS = 100

/**
 * Focuses $target, and tries again for a moment while the focus did not move
 * there: right after a popup closed, Firefox ignores focus() until the page's
 * window is active again. Stops once the focus is on $target, or when
 * $mayMove() says the shopper moved it elsewhere.
 */
export function focusOnReturn(target: ElementLike | null | undefined, win: WinLike | null | undefined, mayMove: () => boolean = () => true): boolean {
  const done = focusElement(target)
  if (!done || !win) return done
  let tries = 0
  const retry = () => {
    if (win.document.activeElement === target || ++tries > FOCUS_RETRIES || !mayMove()) return
    focusElement(target)
    win.setTimeout(retry, FOCUS_RETRY_MS)
  }
  win.setTimeout(retry, FOCUS_RETRY_MS)
  return done
}

/** focusWhenEnabled() looks every FOCUS_RETRY_MS, for ENABLED_WAIT_MS at most. */
export const ENABLED_WAIT_MS = 5000
/** focusWhenEnabled() stops once the focus has stayed on the control this long. */
export const ENABLED_HOLD_MS = 1000

/** A control focusWhenEnabled() hands the focus to. */
export interface ControlLike extends ElementLike {
  disabled?: boolean
  getAttribute?(name: string): string | null
}

/** Whether $control takes the focus: neither disabled nor aria-disabled. */
export function isEnabled(control: ControlLike): boolean {
  return control.disabled !== true && control.getAttribute?.("aria-disabled") !== "true"
}

/**
 * Hands the focus to a control that is disabled for a moment. WooCommerce's
 * block checkout keeps Place order disabled while it awaits its checkout
 * observers (and while the cart updates), so the modal's own focus return
 * finds the button disabled and the focus falls to the body.
 *
 * Every FOCUS_RETRY_MS, while the focus is lost (on the body, or nowhere),
 * the control $find() returns is focused once it has been enabled on two
 * looks in a row, without scrolling: WooCommerce scrolls to its new error
 * notice only while no button has the focus, and has drawn it by then. Stops
 * when the focus is anywhere else (the shopper moved it, or a dialog took
 * it), once it has stayed on the control for ENABLED_HOLD_MS (the button
 * can be disabled again by a cart update right after), or after
 * ENABLED_WAIT_MS. Returns a function that stops it.
 */
export function focusWhenEnabled(
  find: () => ControlLike | null,
  doc: Pick<DocLike, "body" | "activeElement">,
  win: Pick<WinLike, "setTimeout">,
): () => void {
  let stopped = false
  let waited = 0
  let held = 0
  let ready: ControlLike | null = null
  const look = () => {
    if (stopped) return
    const target = find()
    const active = doc.activeElement
    if (target !== null && active === target) {
      held += FOCUS_RETRY_MS
      if (held >= ENABLED_HOLD_MS) return
    } else if (active === null || active === undefined || active === doc.body) {
      held = 0
      if (target !== null && isEnabled(target)) {
        if (ready === target) focusElement(target, { preventScroll: true })
        ready = target
      } else {
        ready = null
      }
    } else {
      return
    }
    waited += FOCUS_RETRY_MS
    if (waited < ENABLED_WAIT_MS) win.setTimeout(look, FOCUS_RETRY_MS)
  }
  win.setTimeout(look, FOCUS_RETRY_MS)
  return () => {
    stopped = true
  }
}
