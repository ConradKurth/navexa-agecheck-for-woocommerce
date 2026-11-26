/**
 * The free entry age gate: a full-screen overlay asking visitors to confirm
 * their age (Yes/No) or type their date of birth before they see the site.
 * It is a self-confirmation, not an age verification: anyone can click Yes.
 *
 * - No network call, no API key, no cookie. A Yes is remembered in
 *   localStorage (STORAGE_KEY = {passedAt, minAge}) for rememberDays, or in
 *   sessionStorage (this tab only) when rememberDays is 0; with 0 only
 *   sessionStorage is read. A stored Yes counts only if its minAge is at
 *   least the gate's, so raising the minimum age asks again. The date of
 *   birth is judged in the browser and never stored or sent; the form never
 *   submits.
 * - Loaded in <head>, it marks <html> before the body paints: the page is
 *   hidden (visibility only, so nothing reflows) behind the backdrop colour,
 *   and the dialog opens as soon as <body> exists. The overlay is
 *   position:fixed and the page's overflow, scrollbar and boxes are left
 *   alone: no layout shift. Any error unmarks the page, never leaving it
 *   hidden.
 * - Not shown to major search-engine crawlers (crawler.ts), on the exit URL's
 *   own page, or twice (a second copy of the script stands down).
 * - Every option is validated (options.ts); an ignored one is a console
 *   warning, never an error. Storage that throws (opaque-origin frames,
 *   private modes) means the gate shows and asks again next page; the gate
 *   never throws.
 * - Accessible modal: role="dialog", aria-modal, labelled by its heading and
 *   described by its message; focus starts on the heading and stays in the
 *   dialog; the page behind is inert and aria-hidden; Escape does not dismiss
 *   it; every control is a native, labelled button or input; errors and the
 *   under-age answer are announced (role="alert"); motion follows
 *   prefers-reduced-motion.
 */
import { judgeBirth, localDay } from "./age"
import { isCrawler } from "./crawler"
import { GATE_ATTR, GATE_CSS, ROOT, addStyle, el, openLayer, type Layer } from "./dom"
import { env } from "./env"
import { DEFAULT_REMEMBER_DAYS, attributeOf, gateConfig, type GateConfig, type GateIssue, type GateOptions } from "./options"

export const STORAGE_KEY = "navexa_agecheck_gate_v1"
const DAY_MS = 86_400_000
const ID = "navexa-agecheck-gate"

export interface GateHandle {
  /** Whether the dialog is showing (false until <body> exists). */
  isOpen(): boolean
  /** Removes the gate without remembering anything. */
  close(): void
}

/** Whether a stored answer covers this gate at now. */
export function remembered(raw: string | null, cfg: Pick<GateConfig, "minAge" | "rememberDays">, now: number): boolean {
  if (!raw) return false
  let value: unknown
  try {
    value = JSON.parse(raw)
  } catch {
    return false
  }
  if (!value || typeof value !== "object") return false
  const { passedAt, minAge } = value as { passedAt?: unknown; minAge?: unknown }
  if (typeof passedAt !== "number" || typeof minAge !== "number" || minAge < cfg.minAge || passedAt > now) return false
  return cfg.rememberDays === 0 || now - passedAt < cfg.rememberDays * DAY_MS
}

function stores(cfg: Pick<GateConfig, "rememberDays">): Array<Storage | null> {
  return cfg.rememberDays === 0 ? [env.session()] : [env.local(), env.session()]
}

function rememberedIn(cfg: Pick<GateConfig, "minAge" | "rememberDays">, now: number): boolean {
  return stores(cfg).some((store) => {
    try {
      return !!store && remembered(store.getItem(STORAGE_KEY), cfg, now)
    } catch {
      return false
    }
  })
}

/**
 * Whether this browser holds a Yes that a gate with minAge and rememberDays
 * (default 30) accepts: the gate's own rule (B5), so platform glue never
 * reimplements it. Never throws; false when storage is unreadable.
 */
export function isRemembered(minAge: number, rememberDays: number = DEFAULT_REMEMBER_DAYS): boolean {
  const days = Number.isInteger(rememberDays) && rememberDays >= 0 && rememberDays <= 365 ? rememberDays : DEFAULT_REMEMBER_DAYS
  return typeof minAge === "number" && rememberedIn({ minAge: minAge as GateConfig["minAge"], rememberDays: days }, env.now())
}

/** Prints each ignored option as one console warning. */
function warn(issues: GateIssue[]): void {
  for (const { field, problem } of issues) {
    try {
      console.warn(`NavexaAgeCheck gate: ignored ${field}${field.startsWith("contrast.") ? "" : ` (${attributeOf(field)})`}: ${problem}`)
    } catch {
      // No console.
    }
  }
}

/** Stores a Yes: localStorage (sessionStorage if that fails), or sessionStorage alone for 0 days. */
function remember(cfg: GateConfig, now: number): void {
  const value = JSON.stringify({ passedAt: now, minAge: cfg.minAge })
  for (const store of stores(cfg)) {
    try {
      if (store) {
        store.setItem(STORAGE_KEY, value)
        return
      }
    } catch {
      // Full or blocked: try the next one; without either, the gate asks again next page.
    }
  }
}

/** origin + path, lower-case, no trailing slash: the "same page" test for the exit URL. */
function place(url: string): string | null {
  try {
    const u = new URL(url)
    return `${u.origin}${u.pathname.replace(/\/+$/, "")}`.toLowerCase()
  } catch {
    return null
  }
}

/**
 * Shows the entry gate unless this visitor is already remembered, is a
 * search-engine crawler, or is on the exit page; returns null when it does
 * not show.
 */
export function gate(options?: GateOptions): GateHandle | null {
  const doc = document
  const html = doc.documentElement
  if (html.hasAttribute(GATE_ATTR)) return null
  let cfg: GateConfig
  try {
    const issues: GateIssue[] = []
    cfg = gateConfig(options, issues)
    warn(issues)
    const exit = cfg.exitUrl && place(cfg.exitUrl)
    if (isCrawler(env.userAgent()) || (exit && exit === place(env.href())) || rememberedIn(cfg, env.now())) return null
  } catch {
    return null
  }

  let layer: Layer | null = null
  let closed = false
  let waiting: MutationObserver | null = null
  const unhide = () => {
    html.removeAttribute(GATE_ATTR)
    html.style.removeProperty("--nac-pre")
  }
  const close = () => {
    if (closed) return
    closed = true
    waiting?.disconnect()
    doc.removeEventListener("DOMContentLoaded", show)
    layer?.close()
    layer = null
    unhide()
  }

  function show(): void {
    if (closed || layer || !doc.body) return
    waiting?.disconnect()
    doc.removeEventListener("DOMContentLoaded", show)
    try {
      layer = render(doc, cfg, close)
    } catch {
      close()
    }
  }

  try {
    addStyle(doc, "gate", GATE_CSS)
    html.style.setProperty("--nac-pre", cfg.theme.backdrop)
    html.setAttribute(GATE_ATTR, "")
    if (doc.body) show()
    else {
      // Open as soon as the parser creates <body>; DOMContentLoaded is the backstop.
      if (typeof MutationObserver === "function") {
        waiting = new MutationObserver(show)
        waiting.observe(html, { childList: true })
      }
      doc.addEventListener("DOMContentLoaded", show)
    }
  } catch {
    close()
    return null
  }
  return { isOpen: () => !!layer, close }
}

function render(doc: Document, cfg: GateConfig, close: () => void): Layer {
  const { labels, theme } = cfg
  const root = el(doc, "div", `${ROOT} nac-g`)
  root.setAttribute("data-navexa-agecheck", "gate")
  const vars: Array<[string, string]> = [
    ["backdrop", theme.backdrop],
    ["card", theme.card],
    ["text", theme.text],
    ["accent", theme.accent],
    ["accent-text", theme.accentText],
    ["font", theme.font],
  ]
  for (const [name, value] of vars) root.style.setProperty(`--nac-${name}`, value)

  const card = el(doc, "div", "nac-card")
  card.setAttribute("role", "dialog")
  card.setAttribute("aria-modal", "true")
  card.setAttribute("aria-labelledby", `${ID}-title`)
  card.setAttribute("aria-describedby", `${ID}-msg`)
  if (cfg.logoUrl) {
    const logo = el(doc, "img", "nac-logo")
    logo.alt = cfg.siteName
    logo.decoding = "async"
    // A broken logo keeps its box (no shift) but shows nothing.
    logo.addEventListener("error", () => (logo.style.visibility = "hidden"))
    logo.src = cfg.logoUrl
    card.appendChild(logo)
  }
  const title = el(doc, "h2", "nac-title", cfg.title)
  title.id = `${ID}-title`
  title.tabIndex = -1
  const msg = el(doc, "p", "nac-msg", cfg.message)
  msg.id = `${ID}-msg`
  const denied = el(doc, "p", "nac-denied")
  denied.setAttribute("role", "alert")
  card.append(title, msg)

  const now = () => env.now()
  const pass = () => {
    remember(cfg, now())
    close()
  }
  const under = () => {
    if (cfg.exitUrl) {
      denied.textContent = labels.leaving
      env.assign(cfg.exitUrl)
      return
    }
    denied.textContent = labels.denied
  }
  const button = (text: string, className: string, type: "button" | "submit" = "button") => {
    const b = el(doc, "button", className, text)
    b.type = type
    return b
  }

  if (cfg.mode === "attest") {
    const actions = el(doc, "div", "nac-actions")
    const yes = button(labels.yes, "nac-btn nac-yes")
    const no = button(labels.no, "nac-btn nac-alt nac-no")
    yes.addEventListener("click", pass)
    no.addEventListener("click", under)
    actions.append(yes, no)
    card.appendChild(actions)
  } else {
    const form = el(doc, "form", "nac-form")
    form.noValidate = true
    const set = el(doc, "fieldset", "nac-dob")
    set.appendChild(el(doc, "legend", undefined, labels.dob))
    const fields = el(doc, "div", "nac-fields")
    const err = el(doc, "p", "nac-err")
    err.id = `${ID}-err`
    err.setAttribute("role", "alert")
    const input = (key: "month" | "day" | "year", size: number) => {
      const label = el(doc, "label")
      label.appendChild(el(doc, "span", undefined, labels[key]))
      const field = el(doc, "input", `nac-${key}`)
      field.type = "text"
      field.inputMode = "numeric"
      field.maxLength = size
      field.setAttribute("pattern", "[0-9]*")
      field.setAttribute("autocomplete", `bday-${key}`)
      field.setAttribute("aria-describedby", err.id)
      label.appendChild(field)
      fields.appendChild(label)
      return field
    }
    const inputs = [input("month", 2), input("day", 2), input("year", 4)]
    set.appendChild(fields)
    form.append(set, err, button(labels.submit, "nac-btn nac-yes", "submit"))
    form.addEventListener("submit", (event) => {
      event.preventDefault()
      denied.textContent = ""
      const [m, d, y] = inputs.map((i) => (/^\d{1,4}$/.test(i.value.trim()) ? Number(i.value) : NaN))
      const verdict = judgeBirth({ y, m, d }, localDay(now()), cfg.minAge)
      for (const i of inputs) i.setAttribute("aria-invalid", String(verdict === "invalid"))
      err.textContent = verdict === "invalid" ? labels.invalid : ""
      if (verdict === "invalid") inputs[0].focus()
      else if (verdict === "under") under()
      else pass()
    })
    card.appendChild(form)
  }
  card.appendChild(denied)
  root.appendChild(card)
  return openLayer(doc, root, { initialFocus: () => title })
}
