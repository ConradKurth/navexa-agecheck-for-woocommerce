/**
 * The entry age gate: a self-attestation prompt ("I am 21 or older" / "I am
 * under 21") shown when a visitor enters the store. It is not an age
 * verification: anyone can answer yes. The checkout's AgeCheck verification
 * and the order hold apply whatever the visitor answers here.
 *
 * - No API key, no personal data, no network request: the configuration
 *   (window.navexaAgeCheckGate, printed by EntryGate.php) is the store's
 *   minimum age, how many days to remember the answer, an optional exit URL
 *   and the copy. Nothing is fetched, and no third-party host is contacted.
 * - The decision is made here, in the browser, from the visitor's own
 *   remembered answer, never by the server: a cached page serves every
 *   visitor correctly. The answer is kept in localStorage
 *   ({v: 1, age, at}); it covers a later visit while it is younger than the
 *   configured days and was given for at least the current minimum age. For
 *   0 days it is kept in sessionStorage, which belongs to one tab (a new tab
 *   or window asks again), and only sessionStorage is read: an answer left
 *   in localStorage under a longer setting does not count. No cookie, so
 *   nothing reaches the server or a page cache.
 * - Loaded in the page head: before the body is parsed it marks <html>
 *   (data-navexa-agecheck-gate) and adds the gate's style, which hides the
 *   page behind the dialog, so the store's content never flashes. Any error
 *   unmarks the page, never leaving it hidden.
 * - Accessible modal dialog (WAI-ARIA APG dialog pattern): role="dialog",
 *   aria-modal, labelled by its heading and described by its text; the focus
 *   moves to the heading when it opens and is trapped in the dialog (Tab and
 *   Shift+Tab wrap between its two buttons, focus that escapes is pulled
 *   back); the rest of the page is inert and hidden from assistive
 *   technology (each node's own aria-hidden is put back when it closes); Escape does not dismiss it (the visitor must answer); native
 *   buttons work with Enter and Space; an under-age answer is announced
 *   (role="alert"). Buttons are at least 44 px tall.
 * - "Under age" goes to the exit URL when the merchant set one (and the gate
 *   never shows on that page itself), otherwise the dialog stays with the
 *   refusal shown. That answer is not remembered.
 *
 * Built to assets/js/entry-gate.js (+ .min.js) by esbuild.
 */

export interface GateConfig {
  v: number
  minimumAge: number
  days: number
  exitUrl: string
  i18n: Record<string, string>
}

/** The storage the answer is kept in (localStorage or sessionStorage). */
export interface StorageLike {
  getItem(key: string): string | null
  setItem(key: string, value: string): void
}

export const STORAGE_KEY = "navexa-agecheck-gate"
export const ROOT_ATTRIBUTE = "data-navexa-agecheck-gate"
export const ROOT_CLASS = "navexa-agecheck-gate"
const DAY_MS = 86_400_000

declare global {
  interface Window {
    navexaAgeCheckGate?: GateConfig
  }
}

/** The configuration, or null when it is missing or malformed. */
export function readConfig(raw: unknown): GateConfig | null {
  if (!raw || typeof raw !== "object") return null
  const c = raw as Partial<GateConfig>
  if (c.v !== 1 || typeof c.minimumAge !== "number" || !Number.isInteger(c.minimumAge) || c.minimumAge < 1) return null
  if (typeof c.days !== "number" || !Number.isInteger(c.days) || c.days < 0) return null
  if (!c.i18n || typeof c.i18n !== "object") return null
  // Only an absolute http(s) exit URL; anything else keeps the visitor on the gate.
  const exitUrl = typeof c.exitUrl === "string" && place(c.exitUrl) !== null ? c.exitUrl : ""
  return { v: 1, minimumAge: c.minimumAge, days: c.days, exitUrl, i18n: c.i18n }
}

/**
 * Whether a remembered answer (the stored JSON) covers minimumAge now: given
 * for at least that age, in the past, and less than days ago (days 0: any
 * age; isRemembered() then reads only sessionStorage, this tab's session).
 */
export function remembered(raw: string | null, minimumAge: number, days: number, now: number): boolean {
  if (!raw) return false
  let value: unknown
  try {
    value = JSON.parse(raw)
  } catch {
    return false
  }
  if (!value || typeof value !== "object") return false
  const { v, age, at } = value as { v?: unknown; age?: unknown; at?: unknown }
  if (v !== 1 || typeof age !== "number" || typeof at !== "number" || age < minimumAge || at > now) return false
  return days === 0 || now - at < days * DAY_MS
}

/** The stored form of a yes for minimumAge, answered at now. */
export function answer(minimumAge: number, now: number): string {
  return JSON.stringify({ v: 1, age: minimumAge, at: now })
}

/** The storage the answer goes to for this many days: session storage for 0. */
export function storageFor(days: number, local: StorageLike | null, session: StorageLike | null): StorageLike | null {
  return days === 0 ? session : local
}

/**
 * Whether a remembered answer covers the gate now: from either storage, or for
 * 0 days from sessionStorage only (an answer kept in localStorage under an
 * earlier, longer setting has no age limit at 0 days, so it never counts
 * then). Storage that throws (blocked) holds none.
 */
export function isRemembered(config: GateConfig, local: StorageLike | null, session: StorageLike | null, now: number): boolean {
  for (const store of config.days === 0 ? [session] : [local, session]) {
    if (!store) continue
    try {
      if (remembered(store.getItem(STORAGE_KEY), config.minimumAge, config.days, now)) return true
    } catch {
      // Blocked storage: not remembered.
    }
  }
  return false
}

/** Remembers a yes; false when the storage is missing or refuses it (the gate still opens, it asks again next time). */
export function remember(config: GateConfig, local: StorageLike | null, session: StorageLike | null, now: number): boolean {
  const store = storageFor(config.days, local, session)
  if (!store) return false
  try {
    store.setItem(STORAGE_KEY, answer(config.minimumAge, now))
    return true
  } catch {
    return false
  }
}

/** Origin and path (no trailing slash) of an absolute URL, or null. */
function place(url: string): string | null {
  try {
    const u = new URL(url)
    if (u.protocol !== "http:" && u.protocol !== "https:") return null
    return `${u.origin}${u.pathname.replace(/\/+$/, "")}`.toLowerCase()
  } catch {
    return null
  }
}

/** Whether the page at href is the exit URL's page (the gate never shows there). */
export function isExitPage(exitUrl: string, href: string): boolean {
  if (!exitUrl) return false
  const exit = place(exitUrl)
  return exit !== null && exit === place(href)
}

/**
 * Where Tab (or Shift+Tab) moves the focus inside the dialog: the next (or
 * previous) of its focusable elements, wrapping; the first (or last) when
 * the focus is on none of them.
 */
export function nextFocus<T>(focusables: readonly T[], active: unknown, backwards: boolean): T | null {
  if (focusables.length === 0) return null
  const index = focusables.indexOf(active as T)
  if (index < 0) return backwards ? focusables[focusables.length - 1] : focusables[0]
  const step = backwards ? -1 : 1
  return focusables[(index + step + focusables.length) % focusables.length]
}

/**
 * The gate's style: the page hidden behind a centred dialog. No external
 * fonts or images. Sized in px at the root and em inside, so a theme's
 * html{font-size:62.5%} cannot shrink it; every dialog rule is scoped under
 * html[data-navexa-agecheck-gate] .navexa-agecheck-gate (the buttons with one
 * class more: specificity 0,4,1), so common theme rules such as
 * button:not(:hover):not(:active):not(.has-background) (0,3,1) or button:hover
 * do not restyle it.
 */
const SCOPE = `html[${ROOT_ATTRIBUTE}] .${ROOT_CLASS}`
export const STYLE = `html[${ROOT_ATTRIBUTE}]{overflow:hidden}
html[${ROOT_ATTRIBUTE}] body>:not(.${ROOT_CLASS}){visibility:hidden}
${SCOPE}{position:fixed;inset:0;z-index:2147483646;display:flex;align-items:center;justify-content:center;margin:0;padding:16px;background:#1e1e1e;visibility:visible;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;font-size:16px;line-height:1.5;letter-spacing:normal;text-transform:none}
${SCOPE} .${ROOT_CLASS}__dialog{box-sizing:border-box;width:100%;max-width:28em;max-height:100%;overflow:auto;margin:0;padding:2em 1.5em;border-radius:8px;background:#fff;color:#1e1e1e;font-size:1em;text-align:center;box-shadow:0 8px 32px rgba(0,0,0,.4)}
${SCOPE} .${ROOT_CLASS}__title{margin:0 0 .5em;padding:0;font-family:inherit;font-size:1.5em;line-height:1.25;font-weight:700;letter-spacing:normal;text-transform:none;color:#1e1e1e}
${SCOPE} .${ROOT_CLASS}__text{margin:0 0 1.5em;padding:0;font-family:inherit;font-size:1em;line-height:1.5;color:#1e1e1e}
${SCOPE} .${ROOT_CLASS}__denied{margin:1em 0 0;padding:0;font-family:inherit;font-size:1em;line-height:1.5;font-weight:600;color:#b32d2e}
${SCOPE} .${ROOT_CLASS}__actions{display:flex;flex-direction:column;gap:.75em;margin:0;padding:0}
${SCOPE} .${ROOT_CLASS}__actions .${ROOT_CLASS}__button{box-sizing:border-box;min-height:44px;width:100%;margin:0;padding:.625em 1em;border:2px solid #1e1e1e;border-radius:6px;box-shadow:none;font-family:inherit;font-size:1em;line-height:1.5;font-weight:600;letter-spacing:normal;text-transform:none;text-decoration:none;text-shadow:none;cursor:pointer}
${SCOPE} .${ROOT_CLASS}__actions .${ROOT_CLASS}__yes{background:#1e1e1e;color:#fff}
${SCOPE} .${ROOT_CLASS}__actions .${ROOT_CLASS}__no{background:#fff;color:#1e1e1e}
${SCOPE} :focus-visible{outline:3px solid #2271b1;outline-offset:2px}
${SCOPE} .${ROOT_CLASS}__title:focus{outline:none}`

/** The DOM the gate touches (a real document in the browser; tests pass a fake). */
export interface GateElement {
  textContent: string | null
  hidden: boolean
  className: string
  readonly children?: ArrayLike<GateElement>
  setAttribute(name: string, value: string): void
  getAttribute(name: string): string | null
  removeAttribute(name: string): void
  hasAttribute(name: string): boolean
  appendChild(child: GateElement): unknown
  removeChild(child: GateElement): unknown
  contains(other: unknown): boolean
  addEventListener(type: string, listener: (event: GateEvent) => void): void
  focus(): void
}

export interface GateEvent {
  key?: string
  shiftKey?: boolean
  target?: unknown
  preventDefault(): void
  stopPropagation(): void
}

export interface GateDocument {
  readyState: string
  activeElement: unknown
  documentElement: GateElement
  head: GateElement | null
  body: GateElement | null
  createElement(tag: string): GateElement
  addEventListener(type: string, listener: (event: GateEvent) => void, capture?: boolean): void
  removeEventListener(type: string, listener: (event: GateEvent) => void, capture?: boolean): void
}

/** MutationObserver's part used here: body children added while the dialog is open. */
export interface ChildWatcher {
  disconnect(): void
}

export interface GateEnv {
  document: GateDocument
  href: string
  /** Calls back with each node later added to the body (null: not supported; the style still hides it). */
  watchChildren?: (body: GateElement, added: (node: GateElement) => void) => ChildWatcher | null
  local: StorageLike | null
  session: StorageLike | null
  now(): number
  assign(url: string): void
}

export interface GateHandle {
  /** The dialog is open. */
  isOpen(): boolean
  /** The visitor's yes (as clicking it). */
  accept(): void
  /** The visitor's no (as clicking it). */
  refuse(): void
}

/**
 * Runs the gate on a page: does nothing when the answer is remembered or
 * this is the exit URL's page; otherwise hides the page at once and opens
 * the dialog when the body is ready. Never throws: an error shows the page.
 */
export function runGate(env: GateEnv, raw: unknown): GateHandle | null {
  const doc = env.document
  const root = doc.documentElement
  try {
    const config = readConfig(raw)
    if (!config || isExitPage(config.exitUrl, env.href) || isRemembered(config, env.local, env.session, env.now())) return null
    root.setAttribute(ROOT_ATTRIBUTE, "")
    if (doc.head) {
      const style = doc.createElement("style")
      style.setAttribute("id", `${ROOT_CLASS}-style`)
      style.textContent = STYLE
      doc.head.appendChild(style)
    }
    const gate = createGate(env, config)
    if (doc.readyState === "loading") {
      const ready = () => {
        doc.removeEventListener("DOMContentLoaded", ready)
        gate.open()
      }
      doc.addEventListener("DOMContentLoaded", ready)
    } else {
      gate.open()
    }
    return gate
  } catch {
    root.removeAttribute(ROOT_ATTRIBUTE)
    return null
  }
}

function createGate(env: GateEnv, config: GateConfig): GateHandle & { open(): void } {
  const doc = env.document
  const t = (key: string) => config.i18n[key] ?? ""
  let open = false
  let container: GateElement | null = null
  let focusables: GateElement[] = []
  let title: GateElement | null = null
  let denied: GateElement | null = null
  /** The nodes hide() took out, each with the aria-hidden it had (null: none), put back by close(). */
  const inerted: { node: GateElement; ariaHidden: string | null }[] = []
  let watcher: ChildWatcher | null = null

  /** Takes a node out of the tab order and the accessibility tree (one inert already is left alone). */
  const hide = (node: GateElement) => {
    if (node === container || node.hasAttribute("inert")) return
    inerted.push({ node, ariaHidden: node.getAttribute("aria-hidden") })
    node.setAttribute("inert", "")
    node.setAttribute("aria-hidden", "true")
  }

  const el = (tag: string, className: string, text?: string): GateElement => {
    const node = doc.createElement(tag)
    node.className = className
    if (text !== undefined) node.textContent = text
    return node
  }

  const onKeydown = (event: GateEvent) => {
    if (!open) return
    if (event.key === "Escape" || event.key === "Esc") {
      // The visitor must answer: Escape does not dismiss the gate.
      event.preventDefault()
      event.stopPropagation()
      return
    }
    if (event.key === "Tab") {
      event.preventDefault()
      nextFocus(focusables, doc.activeElement, !!event.shiftKey)?.focus()
    }
  }

  const onFocusin = (event: GateEvent) => {
    if (open && container && !container.contains(event.target)) focusables[0]?.focus()
  }

  const showPage = () => {
    doc.documentElement.removeAttribute(ROOT_ATTRIBUTE)
  }

  const close = () => {
    open = false
    doc.removeEventListener("keydown", onKeydown, true)
    doc.removeEventListener("focusin", onFocusin, true)
    watcher?.disconnect()
    watcher = null
    for (const { node, ariaHidden } of inerted) {
      node.removeAttribute("inert")
      if (ariaHidden === null) node.removeAttribute("aria-hidden")
      else node.setAttribute("aria-hidden", ariaHidden)
    }
    inerted.length = 0
    if (container && doc.body) doc.body.removeChild(container)
    container = null
    showPage()
  }

  const handle = {
    isOpen: () => open,
    open() {
      try {
        const body = doc.body
        if (!body) {
          showPage()
          return
        }
        container = el("div", ROOT_CLASS)
        const dialog = el("div", `${ROOT_CLASS}__dialog`)
        dialog.setAttribute("role", "dialog")
        dialog.setAttribute("aria-modal", "true")
        dialog.setAttribute("aria-labelledby", `${ROOT_CLASS}-title`)
        dialog.setAttribute("aria-describedby", `${ROOT_CLASS}-text`)
        title = el("h2", `${ROOT_CLASS}__title`, t("title"))
        title.setAttribute("id", `${ROOT_CLASS}-title`)
        title.setAttribute("tabindex", "-1")
        const text = el("p", `${ROOT_CLASS}__text`, t("body"))
        text.setAttribute("id", `${ROOT_CLASS}-text`)
        const actions = el("div", `${ROOT_CLASS}__actions`)
        const yes = el("button", `${ROOT_CLASS}__button ${ROOT_CLASS}__yes`, t("yes"))
        yes.setAttribute("type", "button")
        const no = el("button", `${ROOT_CLASS}__button ${ROOT_CLASS}__no`, t("no"))
        no.setAttribute("type", "button")
        denied = el("p", `${ROOT_CLASS}__denied`)
        denied.setAttribute("role", "alert")
        denied.hidden = true
        yes.addEventListener("click", () => handle.accept())
        no.addEventListener("click", () => handle.refuse())
        actions.appendChild(yes)
        actions.appendChild(no)
        dialog.appendChild(title)
        dialog.appendChild(text)
        dialog.appendChild(actions)
        dialog.appendChild(denied)
        container.appendChild(dialog)
        focusables = [yes, no]

        // The rest of the page leaves the tab order and the accessibility tree
        // (the style hides it too), also what scripts add to the body later.
        const children = body.children ? Array.from(body.children) : []
        for (const child of children) hide(child)
        body.appendChild(container)
        watcher = env.watchChildren ? env.watchChildren(body, hide) : null
        open = true
        doc.addEventListener("keydown", onKeydown, true)
        doc.addEventListener("focusin", onFocusin, true)
        title.focus()
      } catch {
        close()
      }
    },
    accept() {
      if (!open) return
      remember(config, env.local, env.session, env.now())
      close()
    },
    refuse() {
      if (!open) return
      if (config.exitUrl) {
        if (denied) {
          denied.textContent = t("leaving")
          denied.hidden = false
        }
        env.assign(config.exitUrl)
        return
      }
      if (denied) {
        denied.textContent = t("denied")
        denied.hidden = false
      }
    },
  }
  return handle
}

/** Storage that may be blocked: null when reading it throws. */
function safeStorage(read: () => StorageLike): StorageLike | null {
  try {
    return read()
  } catch {
    return null
  }
}

if (typeof window !== "undefined" && typeof document !== "undefined") {
  runGate(
    {
      document: document as unknown as GateDocument,
      href: window.location.href,
      local: safeStorage(() => window.localStorage),
      session: safeStorage(() => window.sessionStorage),
      now: () => Date.now(),
      assign: (url) => window.location.assign(url),
      watchChildren: (body, added) => {
        if (typeof MutationObserver !== "function") return null
        const observer = new MutationObserver((records) => {
          for (const record of records) {
            record.addedNodes.forEach((node) => {
              if (node.nodeType === 1) added(node as unknown as GateElement)
            })
          }
        })
        observer.observe(body as unknown as Node, { childList: true })
        return observer
      },
    },
    window.navexaAgeCheckGate,
  )
}
