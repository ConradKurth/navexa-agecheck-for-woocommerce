/**
 * Transport for the WooCommerce Store API on pages without the block checkout:
 * POST /wc/store/v1/cart/extensions {namespace, data: {v: 1, action}}.
 *
 * The Store API wants its own nonce in a Nonce header. It is fetched at click
 * time from GET /wc/store/v1/cart, which answers one in the Nonce response
 * header after loading the shopper's session, so a cached page never carries
 * a stale nonce and a guest's nonce matches the guest's session. A signed-in
 * shopper's request also carries X-WP-Nonce (WordPress REST cookie auth), or
 * WordPress would treat it as signed out.
 *
 * A start can carry extra data (startData: the Turnstile token when the
 * merchant turned Turnstile on); startData throwing StartRefused answers
 * refused with its message, without calling the store.
 */
import type { Action, StoreAnswer, Transport } from "./agecheck"

export interface StoreApiOptions {
  /** Store API base, ending in /wc/store/v1/. */
  base: string
  /** Extension namespace. */
  namespace: string
  /** wp_rest nonce for a signed-in shopper. */
  restNonce?: string
  /** Runs before a start (classic checkout pushes the address it shows into the session). */
  beforeStart?: () => Promise<void>
  /** Extra fields for a start's data, asked after beforeStart. */
  startData?: () => Promise<Record<string, unknown>>
  fetch?: typeof fetch
}

export class StoreApiError extends Error {}

/** Thrown by startData: the start must not happen; its message is shown. */
export class StartRefused extends Error {}

export function storeApiTransport(options: StoreApiOptions): Transport {
  const doFetch = options.fetch ?? ((input: RequestInfo | URL, init?: RequestInit) => window.fetch(input, init))
  const base = options.base.endsWith("/") ? options.base : `${options.base}/`
  let nonce: string | null = null

  const headers = (extra: Record<string, string> = {}): Record<string, string> => {
    const out: Record<string, string> = { Accept: "application/json", ...extra }
    if (options.restNonce) out["X-WP-Nonce"] = options.restNonce
    return out
  }

  async function freshNonce(): Promise<string> {
    const res = await doFetch(`${base}cart`, { method: "GET", credentials: "same-origin", cache: "no-store", headers: headers() })
    const value = res.headers.get("Nonce")
    if (!res.ok || !value) throw new StoreApiError(`cart ${res.status}`)
    nonce = value
    return value
  }

  async function post(action: Action, value: string, extra: Record<string, unknown>): Promise<Response> {
    return doFetch(`${base}cart/extensions`, {
      method: "POST",
      credentials: "same-origin",
      cache: "no-store",
      headers: headers({ "Content-Type": "application/json", Nonce: value }),
      body: JSON.stringify({ namespace: options.namespace, data: { ...extra, v: 1, action } }),
    })
  }

  return async (action: Action): Promise<StoreAnswer> => {
    let extra: Record<string, unknown> = {}
    if (action === "start") {
      if (options.beforeStart) await options.beforeStart()
      if (options.startData) {
        try {
          extra = await options.startData()
        } catch (err) {
          if (err instanceof StartRefused) return { v: 1, status: "refused", message: err.message }
          throw err
        }
      }
    }
    let res = await post(action, nonce ?? (await freshNonce()), extra)
    if (res.status === 401 || res.status === 403) {
      // A stale or missing nonce: fetch a new one and try once more.
      res = await post(action, await freshNonce(), extra)
    }
    const next = res.headers.get("Nonce")
    if (next) nonce = next
    if (!res.ok) throw new StoreApiError(`extensions ${res.status}`)
    const cart = (await res.json()) as { extensions?: Record<string, unknown> }
    const answer = cart?.extensions?.[options.namespace] as StoreAnswer | undefined
    if (!answer || typeof answer.status !== "string") throw new StoreApiError("no answer")
    return answer
  }
}
