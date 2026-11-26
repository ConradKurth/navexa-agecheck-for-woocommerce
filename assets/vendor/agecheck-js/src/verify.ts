/**
 * verify(): the AgeCheck hosted page in an in-page modal (brief C2 with
 * AMENDMENTS v2, B1 and B2).
 *
 * - The store's own server creates the verification and its hosted session;
 *   the loader gets only the hosted page URL ({url}, or {start} that fetches
 *   it). It never holds an API key and never logs the URL or its token.
 *   start() may instead answer {status, message?} with no url (passed,
 *   refused, not_required, unavailable): verify() then resolves with it at
 *   once and opens nothing.
 * - The modal frames the page with embed=1 and parent_origin=<this page's
 *   origin> (set with the URL API, so a URL that already has them is not
 *   doubled), allow="camera; microphone; fullscreen; clipboard-write". The
 *   embedded page posts {type:"navexa-agecheck", v:2, event,
 *   verification_id?, status?, height?}: ready, resize (height px), decided
 *   (status), close_requested. A message counts only from the host's origin
 *   and from the frame or window the loader opened; an unknown event or
 *   version is ignored (C1 v2 A3). The v1 message {v:1, verification_id,
 *   status} with a final status counts too.
 * - No popup without a user gesture (B2). No "ready" within readyTimeoutMs:
 *   the modal offers "Continue in a new window" (the popup opens from that
 *   click); if the browser blocks it, a link that loads the page here (it
 *   first sets sessionStorage navexa-agecheck-redirect = "1" for the store's
 *   return page). The window and the redirect get the plain page (no embed,
 *   no parent_origin). A late "ready" still shows the frame.
 * - Status fallback: GET {host}/hosted/v1/status with the session token as
 *   Authorization: Bearer (never in a URL), every pollIntervalMs while the
 *   page is healthy, backing off on errors and honouring Retry-After;
 *   stopped by 401/403/404 (session over or origin not allowed). Focus, a
 *   visible tab and a BroadcastChannel("navexa-agecheck") message from the
 *   store's return page poll at once.
 * - Settles once and never rejects for anything the shopper does: {status,
 *   verificationId} on "decided" or a final poll (passed, failed), {status:
 *   "closed"} when the shopper closes the modal (close button, Escape, the
 *   page's close_requested). It rejects only for the caller's mistakes (no
 *   usable source or host, a URL that is not a hosted page on the host, a
 *   start() answer with neither) and when start() itself fails. Whatever it
 *   says, the store must ask its own server: only GetClearance decides.
 */
import { MODAL_CSS, ROOT, addStyle, el, openLayer } from "./dom"
import { env, type PopupHandle } from "./env"
import { cleanText } from "./options"

export const DEFAULT_HOST = "https://verify.navexa.co"
/** sessionStorage key the redirect fallback sets to "1" before it leaves the page. */
export const REDIRECT_MARKER = "navexa-agecheck-redirect"
/** The statuses start() may answer with no url. */
export const START_STATUSES = ["passed", "refused", "not_required", "unavailable"] as const

export interface VerifyLabels {
  /** The modal's heading. */
  title?: string
  /** The close button's accessible name. */
  close?: string
  /** Shown while the page loads. */
  loading?: string
  /** Shown when the page has not loaded in time. */
  slow?: string
  /** The button that opens the page in a new window. */
  newWindow?: string
  /** Shown while the check runs in that window. */
  popup?: string
  /** The button that brings that window back. */
  reopen?: string
  /** Shown when the browser blocked the window. */
  blocked?: string
  /** The link that loads the check in this tab instead. */
  samePage?: string
  /** The frame's accessible title. */
  frame?: string
}

export const DEFAULT_VERIFY_LABELS: Required<VerifyLabels> = {
  title: "Verify your age",
  close: "Close",
  loading: "Loading the secure age check…",
  slow: "The age check is taking longer than usual to load.",
  newWindow: "Continue in a new window",
  popup: "The age check is open in another window. Finish it there; this page updates when you are done.",
  reopen: "Show the age check window",
  blocked: "Your browser blocked the new window.",
  samePage: "Continue the age check on this page",
  frame: "Age check",
}
/** Every verify label key (WordPress and other translators pass these). */
export const VERIFY_LABEL_KEYS = Object.keys(DEFAULT_VERIFY_LABELS) as Array<keyof VerifyLabels>

const TYPE = "navexa-agecheck"
const POPUP_NAME = "navexa-agecheck"
const POPUP_FEATURES = "popup,width=480,height=760"
const FINAL = ["passed", "failed"]
const STATUS = /^[a-z_]{1,40}$/
const MAX_POLL_MS = 30 * 60 * 1000
const MAX_POLL_INTERVAL_MS = 15000
const HEAD_PX = 49

export interface HostedPage {
  /** The hosted page URL, https://<host>/v/<session token>. */
  url: string
  /** The verification's id, if the store knows it. */
  verificationId?: string
}

/** What start() answers: a hosted page, or a status with no url (B1). */
export interface StartAnswer {
  url?: string
  verificationId?: string
  status?: string
  message?: string
}

export type VerifySource = HostedPage | { start: () => Promise<StartAnswer> }

export interface VerifyResult {
  /**
   * "passed" or "failed" (or another final status the page posts),
   * "closed", or a start() status: "passed", "refused", "not_required",
   * "unavailable".
   */
  status: string
  verificationId?: string
  /** start()'s message, with a start() status. */
  message?: string
}

export interface VerifyOptions {
  onResult?: (result: VerifyResult) => void
  onClose?: () => void
  /** AgeCheck host origin; default https://verify.navexa.co (http only for localhost, 127.0.0.1, [::1]). */
  host?: string
  labels?: VerifyLabels
  /** How long the frame has to post "ready" before the modal offers a new window; default 10000. */
  readyTimeoutMs?: number
  /** Status poll interval; default 3000. */
  pollIntervalMs?: number
}

/** The origin of host when it is https (or http on a loopback host); null otherwise. */
export function hostOrigin(host: string = DEFAULT_HOST): string | null {
  try {
    const url = new URL(host)
    const local = ["localhost", "127.0.0.1", "[::1]"].includes(url.hostname)
    return url.protocol === "https:" || (url.protocol === "http:" && local) ? url.origin : null
  } catch {
    return null
  }
}

/**
 * A hosted page URL on origin (origin/v/<payload>.<mac>, a session token):
 * the plain page (no embed, no parent_origin), the framed page (embed=1, and
 * parent_origin when given), and the token; null for anything else.
 */
export function parseHostedUrl(url: string, origin: string, parentOrigin?: string): { page: string; embed: string; token: string } | null {
  let u: URL
  try {
    u = new URL(url)
  } catch {
    return null
  }
  const match = /^\/v\/([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)$/.exec(u.pathname)
  if (u.origin !== origin || !match) return null
  u.searchParams.delete("embed")
  u.searchParams.delete("parent_origin")
  const page = u.href
  u.searchParams.set("embed", "1")
  if (parentOrigin && parentOrigin !== "null") u.searchParams.set("parent_origin", parentOrigin)
  return { page, embed: u.href, token: match[1] }
}

let active: Promise<VerifyResult> | null = null

/**
 * Opens the age check in an in-page modal. A call while one is open (or its
 * start() runs) returns that one's promise.
 */
export function verify(source: VerifySource, options: VerifyOptions = {}): Promise<VerifyResult> {
  if (active) return active
  const promise = new Promise<VerifyResult>((resolve, reject) => run(source, options || {}, resolve, reject))
  active = promise
  const clear = () => {
    if (active === promise) active = null
  }
  promise.then(clear, clear)
  return promise
}

function positive(value: unknown, fallback: number): number {
  return typeof value === "number" && value > 0 && Number.isFinite(value) ? value : fallback
}

const id200 = (value: unknown) => (typeof value === "string" && value ? value.slice(0, 200) : undefined)

function run(source: VerifySource, o: VerifyOptions, resolve: (r: VerifyResult) => void, reject: (e: Error) => void): void {
  const origin = hostOrigin(o.host)
  const src = (source || {}) as Partial<HostedPage> & { start?: unknown }
  if (!origin || (typeof src.url !== "string" && typeof src.start !== "function")) {
    reject(new TypeError("NavexaAgeCheck.verify: needs {url} or {start} and an https host"))
    return
  }
  const settle = (result: VerifyResult, callback?: () => void) => {
    try {
      callback?.()
    } catch {
      // The store's handler must not keep the promise from settling.
    }
    resolve(result)
  }
  const got: Promise<StartAnswer> = typeof src.url === "string" ? Promise.resolve(src) : Promise.resolve().then(() => (src.start as () => Promise<StartAnswer>)())
  got
    .then((answer) => {
      const a = (answer || {}) as StartAnswer
      if (typeof a.url === "string") {
        const hosted = parseHostedUrl(a.url, origin, window.location.origin)
        if (!hosted) throw new TypeError(`NavexaAgeCheck.verify: not a hosted page URL on ${origin}`)
        open(hosted, id200(a.verificationId), origin, o, settle)
        return
      }
      const status = a.status as (typeof START_STATUSES)[number]
      if (!START_STATUSES.includes(status)) throw new TypeError("NavexaAgeCheck.verify: start() answered neither a url nor a status")
      const result: VerifyResult = { status }
      const id = id200(a.verificationId)
      if (id) result.verificationId = id
      if (typeof a.message === "string" && a.message) result.message = cleanText(a.message, 500)
      settle(result, () => o.onResult?.(result))
    })
    .catch((err: unknown) => reject(err instanceof Error ? err : new Error("NavexaAgeCheck.verify: start() failed")))
}

function open(
  hosted: { page: string; embed: string; token: string },
  givenId: string | undefined,
  origin: string,
  o: VerifyOptions,
  settle: (result: VerifyResult, callback?: () => void) => void,
): void {
  const doc = document
  const win = window
  const labels = { ...DEFAULT_VERIFY_LABELS }
  const given = (o.labels || {}) as Record<string, unknown>
  for (const key of VERIFY_LABEL_KEYS) labels[key] = cleanText(given[key], 200) || labels[key]
  const readyMs = positive(o.readyTimeoutMs, 10000)
  const pollMs = positive(o.pollIntervalMs, 3000)
  const page = hosted.page
  let token = hosted.token
  let verificationId = givenId

  addStyle(doc, "modal", MODAL_CSS)
  const root = el(doc, "div", `${ROOT} nac-v`)
  root.setAttribute("data-navexa-agecheck", "verify")
  const modal = el(doc, "div", "nac-modal")
  modal.setAttribute("role", "dialog")
  modal.setAttribute("aria-modal", "true")
  modal.setAttribute("aria-labelledby", "navexa-agecheck-verify-title")
  const head = el(doc, "div", "nac-head")
  const title = el(doc, "h2", undefined, labels.title)
  title.id = "navexa-agecheck-verify-title"
  const x = el(doc, "button", "nac-x", "×")
  x.type = "button"
  x.setAttribute("aria-label", labels.close)
  const body = el(doc, "div", "nac-body")
  const wait = el(doc, "div", "nac-wait")
  wait.setAttribute("role", "status")
  const spin = el(doc, "div", "nac-spin")
  spin.setAttribute("aria-hidden", "true")
  const waitText = el(doc, "p", undefined, labels.loading)
  const action = el(doc, "button", "nac-btn")
  action.type = "button"
  const link = el(doc, "a", "nac-link", labels.samePage)
  link.href = page
  wait.append(spin, waitText, action, link)
  body.appendChild(wait)
  head.append(title, x)
  modal.append(head, body)
  root.appendChild(modal)

  let frame: HTMLIFrameElement | null = null
  let popup: PopupHandle | null = null
  let done = false
  let readyTimer: ReturnType<typeof setTimeout> | undefined
  let pollTimer: ReturnType<typeof setTimeout> | undefined
  let pollDelay = pollMs
  let polling = false
  const pollStart = env.now()
  let channel: BroadcastChannel | null = null

  /** The waiting panel: its text, and which of the spinner, button and link show. */
  const show = (text: string, button?: string, withLink = false) => {
    wait.hidden = false
    spin.hidden = !!button
    waitText.textContent = text
    action.hidden = !button
    action.textContent = button || ""
    link.hidden = !withLink
  }
  show(labels.loading)

  const end = (result: VerifyResult, callback?: () => void) => {
    if (done) return
    done = true
    clearTimeout(readyTimer)
    clearTimeout(pollTimer)
    win.removeEventListener("message", onMessage)
    win.removeEventListener("focus", pollSoon)
    doc.removeEventListener("visibilitychange", pollSoon)
    try {
      channel?.close()
    } catch {
      // Already closed.
    }
    try {
      if (popup && !popup.closed) popup.close?.()
    } catch {
      // Gone already.
    }
    layer.close()
    settle(result, callback)
  }
  const finish = (status: string, id?: string) => {
    const result: VerifyResult = { status }
    const vid = id || verificationId
    if (vid) result.verificationId = vid
    end(result, () => o.onResult?.(result))
  }
  const cancel = () => {
    const result: VerifyResult = { status: "closed" }
    if (verificationId) result.verificationId = verificationId
    end(result, () => o.onClose?.())
  }

  /** Opens (or brings back) the page in a popup; only ever from a click. */
  const openWindow = () => {
    if (popup && !popup.closed) {
      try {
        popup.focus?.()
      } catch {
        // Cross-origin by now.
      }
      return
    }
    let w: PopupHandle | null = null
    try {
      w = env.open(page, POPUP_NAME, POPUP_FEATURES)
    } catch {
      w = null
    }
    if (!w) {
      show(labels.blocked, labels.newWindow, true)
      return
    }
    popup = w
    clearTimeout(readyTimer)
    frame?.remove()
    frame = null
    show(labels.popup, labels.reopen)
  }

  function onMessage(event: MessageEvent): void {
    if (done || event.origin !== origin || !event.source) return
    if (event.source !== frame?.contentWindow && event.source !== popup) return
    const data = event.data as { type?: unknown; v?: unknown; event?: unknown; status?: unknown; verification_id?: unknown; height?: unknown }
    if (!data || typeof data !== "object" || data.type !== TYPE) return
    const status = typeof data.status === "string" && STATUS.test(data.status) ? data.status : ""
    if (data.v === 2) {
      if (data.event === "ready") {
        if (!frame || event.source !== frame.contentWindow) return
        verificationId = verificationId || id200(data.verification_id)
        clearTimeout(readyTimer)
        wait.hidden = true
      } else if (data.event === "resize" && typeof data.height === "number" && Number.isFinite(data.height)) {
        modal.style.setProperty("--nac-h", `${Math.round(Math.min(Math.max(data.height, 120), 4000)) + HEAD_PX}px`)
      } else if (data.event === "decided" && status) finish(status, id200(data.verification_id))
      else if (data.event === "close_requested") cancel()
    } else if (data.v === 1 && FINAL.includes(status)) {
      finish(status, id200(data.verification_id))
    }
  }

  const schedulePoll = (ms: number) => {
    clearTimeout(pollTimer)
    pollTimer = setTimeout(poll, ms)
  }
  async function poll(): Promise<void> {
    if (done || polling || !token) return
    if (env.now() - pollStart > MAX_POLL_MS) return
    polling = true
    let next = Math.min(Math.round(pollDelay * 1.5), MAX_POLL_INTERVAL_MS)
    try {
      const res = await env.fetch(`${origin}/hosted/v1/status`, {
        method: "GET",
        headers: { Authorization: `Bearer ${token}` },
        credentials: "omit",
        cache: "no-store",
        mode: "cors",
      })
      if (res.status === 200) {
        const answer = (await res.json()) as { status?: unknown } | null
        const status = answer && typeof answer.status === "string" ? answer.status : ""
        if (FINAL.includes(status)) {
          polling = false
          finish(status)
          return
        }
        next = pollMs
      } else if (res.status === 429) {
        const retry = Number(res.headers.get("Retry-After"))
        if (retry > 0) next = Math.max(next, retry * 1000)
      } else if (res.status === 401 || res.status === 403 || res.status === 404) {
        // The session is over, or this origin may not read it: stop; the page's own messages still count.
        token = ""
      }
    } catch {
      // Network error or CORS refusal: back off.
    }
    polling = false
    if (done || !token) return
    pollDelay = next
    schedulePoll(next)
  }
  function pollSoon(): void {
    if (!done && token && !polling && doc.visibilityState !== "hidden") schedulePoll(0)
  }

  const layer = openLayer(doc, root, { onEscape: cancel, initialFocus: () => x })
  x.addEventListener("click", cancel)
  action.addEventListener("click", openWindow)
  link.addEventListener("click", () => {
    try {
      env.session()?.setItem(REDIRECT_MARKER, "1")
    } catch {
      // Storage blocked: the return page polls anyway.
    }
  })
  win.addEventListener("message", onMessage)
  win.addEventListener("focus", pollSoon)
  doc.addEventListener("visibilitychange", pollSoon)
  try {
    channel = typeof BroadcastChannel === "function" ? new BroadcastChannel(TYPE) : null
    if (channel) channel.onmessage = (event) => (event.data as { type?: unknown })?.type === TYPE && pollSoon()
  } catch {
    channel = null
  }

  frame = el(doc, "iframe")
  frame.title = labels.frame
  frame.setAttribute("allow", "camera; microphone; fullscreen; clipboard-write")
  frame.src = hosted.embed
  body.insertBefore(frame, wait)
  readyTimer = setTimeout(() => {
    if (!done && frame) show(labels.slow, labels.newWindow)
  }, readyMs)
  schedulePoll(pollMs)
}
