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
 */
import type { LoaderState, Status } from "./agecheck"
import type { NavexaAgeCheckGlobal } from "./index"
import { storeApiTransport } from "./store-api"

interface ClassicData {
  namespace: string
  storeApi: string
  restNonce?: string
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
const UPDATE_TIMEOUT_MS = 8000

function boot(): void {
  const data = window.navexaAgeCheckClassic
  const api: NavexaAgeCheckGlobal | undefined = window.NavexaAgeCheck
  if (!data || !api) return
  const $ = window.jQuery
  const t = (key: string): string => data.i18n[key] ?? ""

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

  const initial = (document.querySelector(CONTAINER)?.getAttribute("data-status") ?? "none") as Status
  let lastPassed = initial === "passed"

  const render = (state: LoaderState): void => {
    const message =
      state.message ||
      (state.reason === "closed"
        ? t("closed")
        : state.busy && state.status !== "pending"
          ? t("checking")
          : t(state.status === "none" || state.status === "refused" ? "prompt" : state.status))
    document.querySelectorAll<HTMLElement>(CONTAINER).forEach((container) => {
      container.setAttribute("data-status", state.status)
      const text = container.querySelector<HTMLElement>(".navexa-agecheck__message")
      if (text && message) text.textContent = message
      const button = container.querySelector<HTMLButtonElement>(BUTTON)
      if (button) {
        button.disabled = state.busy
        button.textContent = state.busy ? t("verifying") : t("verify")
        button.hidden = state.status === "passed"
      }
    })
    if (state.status === "passed" && !state.busy && !lastPassed && $) {
      // Let the server re-render the review with the verified state.
      $(document.body).trigger("update_checkout")
    }
    lastPassed = state.status === "passed"
  }

  const loader = api.create({
    transport: storeApiTransport({ base: data.storeApi, namespace: data.namespace, restNonce: data.restNonce, beforeStart: updateCheckout }),
    status: initial,
    openingText: t("opening"),
    onChange: render,
  })

  document.addEventListener("click", (event) => {
    const target = event.target as Element | null
    if (!target || typeof target.closest !== "function" || !target.closest(BUTTON)) return
    event.preventDefault()
    loader.verify()
  })

  if ($) {
    $(document.body).on("updated_checkout", () => {
      const state = loader.getState()
      if (state.busy) render(state)
    })
  }

  // Back from a full-page redirect, or a reload mid-check: ask once.
  if (initial === "pending") void loader.refresh()
}

if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot)
else boot()
