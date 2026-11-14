/**
 * Navexa AgeCheck loader (plan §2.7, "Loader and start contract").
 *
 * Platform-neutral: the store supplies a transport that calls its own server
 * ("start" and "refresh"); the loader opens AgeCheck's hosted page and learns
 * when the shopper is done. Zero dependencies; GPL-2.0-or-later.
 *
 * - verify() must run synchronously inside the click handler: it opens the
 *   popup first (window.open("", "navexa-agecheck", "popup")) and only then
 *   asks the store for the hosted URL. A blocked popup falls back to a
 *   full-page redirect (location.assign); the store's return page sends the
 *   shopper back to checkout.
 * - Completion: the hosted page's provider (Didit) sends
 *   Cross-Origin-Opener-Policy: same-origin, so a popup loses window.opener
 *   once it gets there, and popup.closed reads true in Chrome, Firefox and
 *   Safari as soon as the popup moves to a COOP page. popup.closed is
 *   therefore never read after navigation and never means "abandoned". The
 *   loader polls GET {hosted origin}/hosted/v1/status with the session token
 *   as Authorization: Bearer (never in a URL), every 3 s with backoff, and
 *   also listens on BroadcastChannel("navexa-agecheck"), the storage event
 *   (browsers without BroadcastChannel) and postMessage
 *   {type: "navexa-agecheck", v: 1, ...} from the hosted origin. Any terminal
 *   signal makes it ask the store to refresh; only the store's answer counts.
 * - It never logs the hosted URL or the token.
 */

export const LOADER_VERSION = "1.0.0"

export type Status = "none" | "pending" | "passed" | "failed" | "refused" | "unavailable"
export type Action = "start" | "refresh"

/** The store's answer: {v: 1, status, hosted_url?, message?}. */
export interface StoreAnswer {
  v: number
  status: string
  hosted_url?: string
  message?: string
}

export type Transport = (action: Action) => Promise<StoreAnswer>

export interface LoaderState {
  status: Status
  /** A start or refresh is in flight. */
  busy: boolean
  /** The store's copy for the shopper, if any. */
  message?: string
  /** Why the loader changed state without the store: "closed" (the popup was closed before it loaded). */
  reason?: "closed"
}

/** What the loader needs from the browser; tests pass fakes. */
export interface LoaderEnv {
  open(url: string, name: string, features: string): PopupHandle | null
  assign(url: string): void
  fetch(url: string, init: { method: string; headers: Record<string, string>; credentials: "omit"; cache: "no-store"; mode: "cors" }): Promise<{
    status: number
    headers: { get(name: string): string | null }
    json(): Promise<unknown>
  }>
  setTimeout(fn: () => void, ms: number): unknown
  clearTimeout(handle: unknown): void
  now(): number
  addEventListener(type: string, listener: (event: any) => void): void
  removeEventListener(type: string, listener: (event: any) => void): void
  visible(): boolean
  broadcastChannel(name: string): ChannelHandle | null
  sessionStorage(): { setItem(key: string, value: string): void } | null
}

/** The part of a BroadcastChannel the loader touches. */
export interface ChannelHandle {
  onmessage: ((event: { data: unknown }) => void) | null
  close(): void
}

/** The part of a popup window the loader touches. */
export interface PopupHandle {
  closed: boolean
  close(): void
  focus?(): void
  location: { replace(url: string): void }
  document?: { title: string; body: { textContent: string | null } | null }
}

export interface LoaderOptions {
  transport: Transport
  onChange?: (state: LoaderState) => void
  /** Initial status, from the page (e.g. the store said "pending" when it rendered). */
  status?: Status
  /** Text shown in the popup while the store answers. */
  openingText?: string
  pollIntervalMs?: number
  maxPollIntervalMs?: number
  /** Stop polling after this long (a hosted session lasts 30 minutes). */
  maxPollMs?: number
  /** Least time between two refreshes triggered by focus or visibility. */
  focusRefreshMs?: number
  env?: Partial<LoaderEnv>
}

export const CHANNEL = "navexa-agecheck"
export const POPUP_NAME = "navexa-agecheck"
export const RETURN_STORAGE_KEY = "navexa-agecheck-return"
export const REDIRECT_STORAGE_KEY = "navexa-agecheck-redirect"
const POPUP_FEATURES = "popup,width=480,height=760"
const TERMINAL = new Set(["passed", "failed"])
const STATUSES = new Set<Status>(["none", "pending", "passed", "failed", "refused", "unavailable"])

/**
 * The session token and status endpoint of a hosted page link
 * (https://host/v/{payload}.{mac}); null for anything else. http is allowed
 * for localhost only (the mock engine).
 */
export function parseHostedUrl(hostedUrl: string): { origin: string; token: string; statusUrl: string } | null {
  let url: URL
  try {
    url = new URL(hostedUrl)
  } catch {
    return null
  }
  const local = url.hostname === "localhost" || url.hostname === "127.0.0.1" || url.hostname === "host.docker.internal"
  if (url.protocol !== "https:" && !(url.protocol === "http:" && local)) return null
  const match = /^\/v\/([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)$/.exec(url.pathname)
  if (!match) return null
  return { origin: url.origin, token: match[1], statusUrl: `${url.origin}/hosted/v1/status` }
}

function browserEnv(): LoaderEnv {
  const w = window
  return {
    open: (url, name, features) => w.open(url, name, features) as unknown as PopupHandle | null,
    assign: (url) => w.location.assign(url),
    fetch: (url, init) => w.fetch(url, init),
    setTimeout: (fn, ms) => w.setTimeout(fn, ms),
    clearTimeout: (handle) => w.clearTimeout(handle as number),
    now: () => Date.now(),
    addEventListener: (type, listener) => (type === "visibilitychange" ? document : w).addEventListener(type, listener),
    removeEventListener: (type, listener) => (type === "visibilitychange" ? document : w).removeEventListener(type, listener),
    visible: () => document.visibilityState !== "hidden",
    broadcastChannel: (name) => (typeof BroadcastChannel === "function" ? (new BroadcastChannel(name) as unknown as ChannelHandle) : null),
    sessionStorage: () => {
      try {
        return w.sessionStorage
      } catch {
        return null
      }
    },
  }
}

export class AgeCheckLoader {
  private readonly transport: Transport
  private readonly onChange: (state: LoaderState) => void
  private readonly env: LoaderEnv
  private readonly opts: Required<Pick<LoaderOptions, "pollIntervalMs" | "maxPollIntervalMs" | "maxPollMs" | "focusRefreshMs" | "openingText">>
  private state: LoaderState
  private watch: { origin: string; token: string; statusUrl: string; startedAt: number; interval: number; timer: unknown; inFlight: boolean } | null = null
  /** Origin of the last hosted page opened; postMessage is accepted from it only. */
  private hostedOrigin = ""
  private refreshing: Promise<StoreAnswer | null> | null = null
  private lastFocusRefresh = 0
  private channel: ChannelHandle | null = null
  private readonly listeners: Array<[string, (event: any) => void]> = []
  private destroyed = false

  constructor(options: LoaderOptions) {
    this.transport = options.transport
    this.onChange = options.onChange ?? (() => {})
    this.env = { ...(typeof window === "undefined" ? ({} as LoaderEnv) : browserEnv()), ...options.env } as LoaderEnv
    this.opts = {
      pollIntervalMs: options.pollIntervalMs ?? 3000,
      maxPollIntervalMs: options.maxPollIntervalMs ?? 15000,
      maxPollMs: options.maxPollMs ?? 30 * 60 * 1000,
      focusRefreshMs: options.focusRefreshMs ?? 5000,
      openingText: options.openingText ?? "Opening the secure age check…",
    }
    this.state = { status: options.status && STATUSES.has(options.status) ? options.status : "none", busy: false }
    this.listen()
  }

  /** The current state. */
  getState(): LoaderState {
    return { ...this.state }
  }

  /**
   * Starts (or resumes) verification. Call it synchronously from a click
   * handler, so the popup is not blocked.
   */
  verify(): void {
    if (this.destroyed || this.state.busy) return
    let popup: PopupHandle | null = null
    try {
      popup = this.env.open("", POPUP_NAME, POPUP_FEATURES)
    } catch {
      popup = null
    }
    if (popup) {
      try {
        if (popup.document) {
          popup.document.title = this.opts.openingText
          if (popup.document.body) popup.document.body.textContent = this.opts.openingText
        }
      } catch {
        // A reused named popup may already be on another origin.
      }
    }
    this.set({ status: this.state.status, busy: true, message: undefined })
    this.transport("start").then(
      (answer) => this.afterStart(answer, popup),
      () => {
        closeQuietly(popup)
        this.set({ status: "unavailable", busy: false })
      },
    )
  }

  /**
   * Asks the store to re-read the verification; resolves to its answer, or
   * null when the call failed. Concurrent calls share one request.
   */
  refresh(): Promise<StoreAnswer | null> {
    if (this.destroyed) return Promise.resolve(null)
    if (this.refreshing) return this.refreshing
    this.set({ ...this.state, busy: true })
    this.refreshing = this.transport("refresh")
      .then(
        (answer) => {
          this.apply(answer)
          return answer
        },
        () => {
          this.set({ status: "unavailable", busy: false })
          return null
        },
      )
      .finally(() => {
        this.refreshing = null
      })
    return this.refreshing
  }

  /** Stops polling and listening. */
  destroy(): void {
    this.destroyed = true
    this.stopWatch()
    for (const [type, listener] of this.listeners) this.env.removeEventListener(type, listener)
    this.listeners.length = 0
    this.channel?.close()
    this.channel = null
  }

  private afterStart(answer: StoreAnswer, popup: PopupHandle | null): void {
    const status = normalizeStatus(answer)
    if (status !== "pending" || !answer.hosted_url) {
      closeQuietly(popup)
      this.apply(answer)
      return
    }
    const hosted = parseHostedUrl(answer.hosted_url)
    if (!hosted) {
      closeQuietly(popup)
      this.set({ status: "unavailable", busy: false })
      return
    }
    if (popup && popup.closed) {
      // Closed while the store answered, before it left about:blank: a cancel.
      this.set({ status: "none", busy: false, reason: "closed" })
      return
    }
    if (popup) {
      try {
        popup.location.replace(answer.hosted_url)
        popup.focus?.()
        this.set({ status: "pending", busy: false, message: answer.message })
        this.startWatch(hosted)
        return
      } catch {
        closeQuietly(popup)
      }
    }
    // Popup blocked: go there in this tab; the return page brings the shopper back.
    try {
      this.env.sessionStorage()?.setItem(REDIRECT_STORAGE_KEY, "1")
    } catch {
      // Without the flag the return page shows a link back to checkout.
    }
    this.set({ status: "pending", busy: true })
    this.env.assign(answer.hosted_url)
  }

  private apply(answer: StoreAnswer): void {
    const status = normalizeStatus(answer)
    if (status !== "pending") this.stopWatch()
    this.set({ status, busy: false, message: answer.message })
  }

  private listen(): void {
    try {
      this.channel = this.env.broadcastChannel ? this.env.broadcastChannel(CHANNEL) : null
      if (this.channel) this.channel.onmessage = (event) => this.onSignal(event.data)
    } catch {
      this.channel = null
    }
    this.on("storage", (event: { key?: string | null }) => {
      if (event && event.key === RETURN_STORAGE_KEY) this.onSignal({ type: CHANNEL, v: 1, event: "return" })
    })
    this.on("message", (event: { origin?: string; data?: unknown }) => {
      // postMessage is a bonus: the hosted page posts only to the signed return origin.
      if (this.hostedOrigin !== "" && event && event.origin === this.hostedOrigin) this.onSignal(event.data)
    })
    const onVisible = () => {
      if (!this.env.visible() || this.state.status !== "pending") return
      if (this.watch) {
        this.pollNow()
        return
      }
      const now = this.env.now()
      if (now - this.lastFocusRefresh < this.opts.focusRefreshMs) return
      this.lastFocusRefresh = now
      void this.refresh()
    }
    this.on("focus", onVisible)
    this.on("visibilitychange", onVisible)
  }

  private on(type: string, listener: (event: any) => void): void {
    if (!this.env.addEventListener) return
    this.env.addEventListener(type, listener)
    this.listeners.push([type, listener])
  }

  private onSignal(data: unknown): void {
    if (!data || typeof data !== "object") return
    const message = data as { type?: unknown; v?: unknown; status?: unknown; event?: unknown }
    if (message.type !== CHANNEL || message.v !== 1) return
    if (message.event === "return" || (typeof message.status === "string" && TERMINAL.has(message.status))) {
      if (this.state.status === "pending") void this.refresh()
    }
  }

  private startWatch(hosted: { origin: string; token: string; statusUrl: string }): void {
    this.stopWatch()
    this.hostedOrigin = hosted.origin
    this.watch = { ...hosted, startedAt: this.env.now(), interval: this.opts.pollIntervalMs, timer: null, inFlight: false }
    this.schedule(this.opts.pollIntervalMs)
  }

  private stopWatch(): void {
    if (this.watch && this.watch.timer !== null) this.env.clearTimeout(this.watch.timer)
    this.watch = null
  }

  private schedule(ms: number): void {
    const watch = this.watch
    if (!watch) return
    if (watch.timer !== null) this.env.clearTimeout(watch.timer)
    watch.timer = this.env.setTimeout(() => {
      watch.timer = null
      void this.poll()
    }, ms)
  }

  private pollNow(): void {
    if (this.watch && !this.watch.inFlight) this.schedule(0)
  }

  private async poll(): Promise<void> {
    const watch = this.watch
    if (!watch || watch.inFlight) return
    if (this.env.now() - watch.startedAt > this.opts.maxPollMs) {
      this.stopWatch()
      return
    }
    watch.inFlight = true
    let next = Math.min(Math.round(watch.interval * 1.5), this.opts.maxPollIntervalMs)
    try {
      const res = await this.env.fetch(watch.statusUrl, {
        method: "GET",
        headers: { Authorization: `Bearer ${watch.token}` },
        credentials: "omit",
        cache: "no-store",
        mode: "cors",
      })
      if (res.status === 200) {
        const body = (await res.json()) as { status?: unknown }
        if (typeof body?.status === "string" && TERMINAL.has(body.status)) {
          watch.inFlight = false
          this.stopWatch()
          void this.refresh()
          return
        }
        next = watch.interval // Healthy: keep the pace.
      } else if (res.status === 429) {
        const retry = Number(res.headers.get("Retry-After"))
        if (Number.isFinite(retry) && retry > 0) next = Math.max(next, retry * 1000)
      } else if (res.status === 401 || res.status === 403 || res.status === 404) {
        // The session token expired or is not ours to read: stop; focus and the return page still refresh.
        watch.inFlight = false
        this.stopWatch()
        return
      }
    } catch {
      // Network error or CORS refusal (origin not allowed): back off, keep the other signals.
    }
    watch.inFlight = false
    if (this.watch !== watch) return
    watch.interval = next
    this.schedule(next)
  }

  private set(state: LoaderState): void {
    this.state = state
    try {
      this.onChange({ ...state })
    } catch {
      // The page's handler must not break the loader.
    }
  }
}

function normalizeStatus(answer: StoreAnswer | null | undefined): Status {
  const status = answer && typeof answer.status === "string" ? (answer.status as Status) : "unavailable"
  return STATUSES.has(status) ? status : "unavailable"
}

function closeQuietly(popup: PopupHandle | null): void {
  try {
    if (popup && !popup.closed) popup.close()
  } catch {
    // Ignore.
  }
}

/** Creates a loader. */
export function create(options: LoaderOptions): AgeCheckLoader {
  return new AgeCheckLoader(options)
}
