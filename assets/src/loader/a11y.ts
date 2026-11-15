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
  focus?(): void
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

/** Focuses $target (the browser scrolls it into view); false when there is none. */
export function focusElement(target: ElementLike | null | undefined): boolean {
  if (!target || typeof target.focus !== "function") return false
  try {
    target.focus()
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
