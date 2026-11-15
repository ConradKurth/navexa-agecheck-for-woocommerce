/**
 * Cloudflare Turnstile for the Verify age start (optional, off by default).
 *
 * The loader opens its popup synchronously in the click, then asks the store
 * to start; this runs inside that asynchronous start, so a Turnstile check
 * never blocks the popup. The widget is rendered on demand into the Verify
 * age container (explicit rendering, execution "execute", appearance
 * "interaction-only": invisible unless Cloudflare needs the shopper to
 * interact), executed, and its token handed to the start. Tokens are single
 * use: every start resets the widget, and a widget whose container the
 * classic checkout replaced is removed and rendered again.
 *
 * When Cloudflare needs the shopper to interact (before-interactive-callback)
 * the challenge shows on the checkout page, not in the popup, so the waiting
 * start gives up at once (token() answers null and challenging() is true):
 * the glue closes the popup, says to complete the check shown, and moves the
 * focus to it. No timeout runs while the challenge shows. The token the
 * solved challenge gives is kept (at most READY_MAX_AGE_MS; Cloudflare's
 * tokens last 300 s) for the next Verify age click, which then needs no new
 * challenge. Asking again while the challenge is still unsolved answers null
 * without resetting it.
 *
 * Built to assets/js/turnstile.js (+ .min.js), exposed as
 * window.NavexaAgeCheckTurnstile; the Cloudflare API script
 * (challenges.cloudflare.com/turnstile/v0/api.js?render=explicit) loads only
 * when the merchant turned Turnstile on.
 */

export interface TurnstileRenderOptions {
  sitekey: string
  action?: string
  execution?: "render" | "execute"
  appearance?: "always" | "execute" | "interaction-only"
  callback?: (token: string) => void
  "error-callback"?: (code?: unknown) => boolean | void
  "expired-callback"?: () => void
  "timeout-callback"?: () => void
  "before-interactive-callback"?: () => void
  "unsupported-callback"?: () => void
}

/** The part of window.turnstile used here. */
export interface TurnstileApi {
  render(container: unknown, options: TurnstileRenderOptions): string | null | undefined
  execute(widget: unknown): void
  reset(widget?: string): void
  remove(widget: string): void
}

export interface TurnstileConfig {
  siteKey: string
  action: string
}

export interface TurnstileEnv {
  api(): TurnstileApi | undefined
  setTimeout(fn: () => void, ms: number): unknown
  clearTimeout(handle: unknown): void
  now(): number
}

/** How long a token from a solved challenge is kept for the next start. */
export const READY_MAX_AGE_MS = 240000

export interface TurnstileOptions extends TurnstileConfig {
  /** How long to wait for Cloudflare's script to load. */
  loadTimeoutMs?: number
  /** How long to wait for a token (the shopper may have to interact). */
  tokenTimeoutMs?: number
  env?: Partial<TurnstileEnv>
}

export interface TurnstileClient {
  /** A fresh token, or null when Turnstile could not give one (see challenging()). */
  token(container: unknown): Promise<string | null>
  /** Cloudflare shows an interactive challenge the shopper has not solved yet. */
  challenging(): boolean
}

function browserEnv(): TurnstileEnv {
  return {
    api: () => (typeof window !== "undefined" ? (window as unknown as { turnstile?: TurnstileApi }).turnstile : undefined),
    setTimeout: (fn, ms) => setTimeout(fn, ms),
    clearTimeout: (handle) => clearTimeout(handle as ReturnType<typeof setTimeout>),
    now: () => Date.now(),
  }
}

export function createTurnstile(options: TurnstileOptions): TurnstileClient {
  const env: TurnstileEnv = { ...browserEnv(), ...options.env } as TurnstileEnv
  const loadTimeoutMs = options.loadTimeoutMs ?? 10000
  const tokenTimeoutMs = options.tokenTimeoutMs ?? 120000
  let widget: string | null = null
  let rendered: unknown = null
  let settle: ((token: string | null) => void) | null = null
  let challenge = false
  let ready: { token: string; at: number } | null = null

  const finish = (token: string | null) => {
    const done = settle
    settle = null
    if (done) done(token)
  }

  /** A token arrived: the waiting start takes it, or it is kept for the next one. */
  const solved = (token: unknown) => {
    challenge = false
    const value = typeof token === "string" && token !== "" ? token : null
    if (settle) {
      finish(value)
      return
    }
    ready = value ? { token: value, at: env.now() } : null
  }

  const waitForApi = (): Promise<TurnstileApi | null> =>
    new Promise((resolve) => {
      let waited = 0
      const check = () => {
        const api = env.api()
        if (api && typeof api.render === "function") {
          resolve(api)
          return
        }
        if (waited >= loadTimeoutMs) {
          resolve(null)
          return
        }
        waited += 100
        env.setTimeout(check, 100)
      }
      check()
    })

  return {
    challenging: () => challenge,
    async token(container: unknown): Promise<string | null> {
      if (!options.siteKey || !container) return null
      const kept = ready
      ready = null
      if (kept && env.now() - kept.at < READY_MAX_AGE_MS) return kept.token
      const api = await waitForApi()
      if (!api) return null
      // The challenge the shopper was asked to complete is still showing: keep it.
      if (challenge && widget !== null && rendered === container) return null
      finish(null) // A start still waiting gives up its token.
      return new Promise<string | null>((resolve) => {
        const timer = env.setTimeout(() => finish(null), tokenTimeoutMs)
        settle = (token) => {
          env.clearTimeout(timer)
          resolve(token)
        }
        challenge = false
        try {
          if (widget !== null && rendered !== container) {
            try {
              api.remove(widget)
            } catch {
              // The old container is gone already.
            }
            widget = null
          }
          if (widget === null) {
            const id = api.render(container, {
              sitekey: options.siteKey,
              action: options.action,
              execution: "execute",
              appearance: "interaction-only",
              callback: solved,
              "error-callback": () => {
                challenge = false
                finish(null)
                return true
              },
              "expired-callback": () => {
                ready = null
                finish(null)
              },
              "timeout-callback": () => {
                challenge = false
                finish(null)
              },
              "before-interactive-callback": () => {
                challenge = true
                finish(null)
              },
              "unsupported-callback": () => finish(null),
            })
            if (typeof id !== "string" || id === "") {
              finish(null)
              return
            }
            widget = id
            rendered = container
          } else {
            api.reset(widget)
          }
          api.execute(widget)
        } catch {
          finish(null)
        }
      })
    },
  }
}

declare global {
  interface Window {
    NavexaAgeCheckTurnstile?: { create: typeof createTurnstile }
  }
}

if (typeof window !== "undefined" && !window.NavexaAgeCheckTurnstile) {
  window.NavexaAgeCheckTurnstile = { create: createTurnstile }
}
