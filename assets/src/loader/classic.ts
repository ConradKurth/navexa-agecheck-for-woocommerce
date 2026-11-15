/**
 * Classic (shortcode) checkout glue: the Verify age button rendered by
 * Checkout\Classic at woocommerce_review_order_before_submit, driving the
 * loader (window.NavexaAgeCheck) over the Store API.
 *
 * The button sits in the fragment WooCommerce replaces on every checkout
 * update, so clicks are delegated from the document, and the server's markup
 * is the state after each update. Before a start, the checkout is updated once
 * so the session holds the address the shopper sees (the guard is bound to
 * the session's ship-to).
 *
 * Accessibility (a11y.ts): status changes are announced through one live
 * region outside the replaced fragment; while a check runs the button stays
 * focusable (aria-disabled, not disabled) and the container is aria-busy;
 * when a check the shopper started ends, the focus goes to the status message
 * on a pass (the button is gone then) or back to the button, also after the
 * fragment was replaced.
 *
 * With Turnstile on, a start first gets a Turnstile token (turnstile.ts,
 * window.NavexaAgeCheckTurnstile), rendered into the fragment's
 * [data-navexa-agecheck-turnstile] element. When Cloudflare shows an
 * interactive challenge there instead, the start is refused at once (the
 * popup closes, nothing reaches the store), the status says to complete the
 * check shown, and the focus goes to it; the next Verify age click uses the
 * solved challenge's token.
 */
import type { LoaderState, Status } from "./agecheck"
import type { NavexaAgeCheckGlobal } from "./index"
import { StartRefused, storeApiTransport } from "./store-api.ts"
import { announce, focusOnReturn, mayRestoreFocus, type DocLike, type ElementLike, type WinLike } from "./a11y.ts"
import type { TurnstileConfig } from "./turnstile"

interface ClassicData {
  namespace: string
  storeApi: string
  restNonce?: string
  turnstile?: TurnstileConfig
  i18n: Record<string, string>
}

interface JQueryLike {
  (target: unknown): { on(event: string, handler: (...args: unknown[]) => void): unknown; one(event: string, handler: (...args: unknown[]) => void): unknown; off(event: string, handler: (...args: unknown[]) => void): unknown; trigger(event: string): unknown }
}

declare global {
  interface Window {
    navexaAgeCheckClassic?: ClassicData
    jQuery?: JQueryLike
  }
}

const CONTAINER = "[data-navexa-agecheck]"
const BUTTON = "[data-navexa-agecheck-verify]"
const MESSAGE = ".navexa-agecheck__message"
const TURNSTILE = "[data-navexa-agecheck-turnstile]"
const UPDATE_TIMEOUT_MS = 8000

function boot(): void {
  const data = window.navexaAgeCheckClassic
  const api: NavexaAgeCheckGlobal | undefined = window.NavexaAgeCheck
  if (!data || !api) return
  const $ = window.jQuery
  const doc = document as unknown as DocLike
  const t = (key: string): string => data.i18n[key] ?? ""
  const turnstile = data.turnstile && window.NavexaAgeCheckTurnstile ? window.NavexaAgeCheckTurnstile.create(data.turnstile) : null

  /** Runs one WooCommerce checkout update and waits for it (or a timeout). */
  const updateCheckout = (): Promise<void> =>
    new Promise((resolve) => {
      if (!$ || !document.querySelector("form.checkout")) {
        resolve()
        return
      }
      let done = false
      const finish = () => {
        if (done) return
        done = true
        $(document.body).off("updated_checkout", finish)
        $(document.body).off("checkout_error", finish)
        resolve()
      }
      $(document.body).one("updated_checkout", finish)
      $(document.body).one("checkout_error", finish)
      window.setTimeout(finish, UPDATE_TIMEOUT_MS)
      $(document.body).trigger("update_checkout")
    })

  /** The Turnstile token for a start, when the merchant turned Turnstile on. */
  const startData = async (): Promise<Record<string, unknown>> => {
    if (!data.turnstile) return {}
    const token = turnstile ? await turnstile.token(document.querySelector(TURNSTILE)) : null
    if (!token) throw new StartRefused(turnstile?.challenging() ? t("challenge") : t("turnstile"))
    return { turnstile: token }
  }

  const initial = (document.querySelector(CONTAINER)?.getAttribute("data-status") ?? "none") as Status
  let lastPassed = initial === "passed"
  let lastAnnounced = ""
  /** The shopper started the running check from the button: its end may move the focus. */
  let owned = false
  /** A pass re-renders the fragment: restore the focus after that update. */
  let focusAfterUpdate = false

  const messageFor = (state: LoaderState): string =>
    state.message ||
    (state.reason === "closed"
      ? t("closed")
      : state.busy && state.status !== "pending"
        ? t("checking")
        : t(state.status === "none" || state.status === "refused" ? "prompt" : state.status))

  /** Puts the focus back on the Verify age control after a check the shopper started. */
  const restoreFocus = (passed: boolean): void => {
    const container = document.querySelector(CONTAINER) as unknown as ElementLike | null
    if (!mayRestoreFocus(doc, container, owned)) {
      owned = false
      return
    }
    owned = false
    const target = passed
      ? document.querySelector(`${CONTAINER} ${MESSAGE}`)
      : turnstile?.challenging()
        ? document.querySelector(TURNSTILE)
        : document.querySelector(BUTTON)
    focusOnReturn((target ?? document.querySelector(`${CONTAINER} ${MESSAGE}`)) as unknown as ElementLike | null, window as unknown as WinLike, () =>
      mayRestoreFocus(doc, document.querySelector(CONTAINER) as unknown as ElementLike | null, true),
    )
  }

  const render = (state: LoaderState): void => {
    const message = messageFor(state)
    document.querySelectorAll<HTMLElement>(CONTAINER).forEach((container) => {
      container.setAttribute("data-status", state.status)
      container.setAttribute("aria-busy", state.busy ? "true" : "false")
      const text = container.querySelector<HTMLElement>(MESSAGE)
      if (text && message) text.textContent = message
      const button = container.querySelector<HTMLButtonElement>(BUTTON)
      if (button) {
        // aria-disabled, not disabled: a disabled button drops the focus.
        button.setAttribute("aria-disabled", state.busy ? "true" : "false")
        button.textContent = state.busy ? t("verifying") : t("verify")
        button.hidden = state.status === "passed"
      }
    })
    if (message && message !== lastAnnounced && !(state.busy && state.status === "pending")) {
      lastAnnounced = announce(doc, message)
    }
    if (state.status === "passed" && !state.busy && !lastPassed && $) {
      // Let the server re-render the review with the verified state, then focus it.
      focusAfterUpdate = owned
      $(document.body).trigger("update_checkout")
    } else if (!state.busy && state.status !== "pending" && owned) {
      restoreFocus(state.status === "passed")
    }
    lastPassed = state.status === "passed"
  }

  const loader = api.create({
    transport: storeApiTransport({ base: data.storeApi, namespace: data.namespace, restNonce: data.restNonce, beforeStart: updateCheckout, startData }),
    status: initial,
    openingText: t("opening"),
    onChange: render,
  })

  document.addEventListener("click", (event) => {
    const target = event.target as Element | null
    if (!target || typeof target.closest !== "function" || !target.closest(BUTTON)) return
    event.preventDefault()
    if (loader.getState().busy) return
    owned = true
    lastAnnounced = "" // Announce this attempt's outcome even when it repeats the last one.
    loader.verify()
  })

  if ($) {
    $(document.body).on("updated_checkout", () => {
      const state = loader.getState()
      if (state.busy) {
        render(state)
        return
      }
      // A message the server rendered (ship-to changed, refused) is news too.
      const text = document.querySelector(`${CONTAINER} ${MESSAGE}`)?.textContent ?? ""
      if (text && text !== lastAnnounced) lastAnnounced = announce(doc, text)
      if (focusAfterUpdate) {
        focusAfterUpdate = false
        owned = true
        restoreFocus(state.status === "passed")
      }
    })
  }

  // Back from a full-page redirect, or a reload mid-check: ask once.
  if (initial === "pending") void loader.refresh()
}

if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot)
else boot()
