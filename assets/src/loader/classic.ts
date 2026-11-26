/**
 * Classic (shortcode) checkout glue (TAX-1874): the age check opens at Place
 * order in the shared loader's in-page modal (window.NavexaAgeCheck.verify,
 * vendored in assets/vendor/agecheck-js/), driven over the Store API
 * (store-api.ts); place-order.ts holds the flow, lock.ts the field lock.
 *
 * - Checkout\Classic renders a status container at
 *   woocommerce_review_order_before_submit ([data-navexa-agecheck], with
 *   data-status: the server's state; no button), inside the fragment
 *   WooCommerce replaces on every checkout update, and a lock bar with
 *   "Change details" above the customer details.
 * - After a pass the name and ship-to fields are locked (readOnly, or
 *   disabled with a hidden mirror). "Change details" unlocks them and says a
 *   new address means a new check; a checkout update whose state left passed
 *   (the ship-to changed) unlocks them too. An expired guard (pending) keeps
 *   the lock: the next Place order mints a new one without the modal.
 * - Refusals go where WooCommerce puts its own checkout errors (a
 *   .woocommerce-NoticeGroup-checkout with a role="alert" list, built with
 *   textContent) and are announced through the live region outside the
 *   replaced fragment (a11y.ts).
 * - With Turnstile on, the start carries a Turnstile token rendered into the
 *   container's [data-navexa-agecheck-turnstile]; an interactive challenge
 *   refuses the start at once and moves the focus to it (turnstile.ts).
 * - Back from the loader's full-page redirect fallback (data-status
 *   "pending" on load): one refresh, no modal.
 */
import { StartRefused, storeApiTransport } from "./store-api.ts"
import { announce, focusOnReturn, type DocLike as A11yDoc, type ElementLike, type WinLike } from "./a11y.ts"
import { createPlaceOrderFlow } from "./place-order.ts"
import { isLocked, lockForm, unlockForm, type DocLike as LockDoc, type FormLike } from "./lock.ts"
import { originOf } from "./types.ts"
import type { NavexaAgeCheckApi } from "./navexa-agecheck.ts"
import type { TurnstileConfig } from "./turnstile.ts"

interface ClassicData {
  namespace: string
  storeApi: string
  restNonce?: string
  turnstile?: TurnstileConfig
  i18n: Record<string, string>
  labels?: Record<string, string>
}

interface JQueryObject {
  on(event: string, handler: (...args: any[]) => unknown): JQueryObject
  one(event: string, handler: (...args: any[]) => unknown): JQueryObject
  off(event: string, handler: (...args: any[]) => unknown): JQueryObject
  trigger(event: string): JQueryObject
  block?(options?: Record<string, unknown>): JQueryObject
  unblock?(): JQueryObject
}
type JQueryLike = (target: unknown) => JQueryObject

declare global {
  interface Window {
    navexaAgeCheckClassic?: ClassicData
    jQuery?: JQueryLike
  }
}

const CONTAINER = "[data-navexa-agecheck]"
const MESSAGE = ".navexa-agecheck__message"
const TURNSTILE = "[data-navexa-agecheck-turnstile]"
const LOCK_BAR = "[data-navexa-agecheck-lock]"
const UNLOCK = "[data-navexa-agecheck-unlock]"
const NOTICE = "data-navexa-agecheck-notice"
const UPDATE_TIMEOUT_MS = 8000

function boot(): void {
  const data = window.navexaAgeCheckClassic
  const api: NavexaAgeCheckApi | undefined = window.NavexaAgeCheck
  const $ = window.jQuery
  const formEl = document.querySelector<HTMLFormElement>("form.checkout")
  if (!data || !api || typeof api.verify !== "function" || !$ || !formEl) return
  const verify = api.verify
  const $form = $(formEl)
  const doc = document as unknown as A11yDoc
  const t = (key: string): string => data.i18n[key] ?? ""
  const turnstile = data.turnstile && window.NavexaAgeCheckTurnstile ? window.NavexaAgeCheckTurnstile.create(data.turnstile) : null
  const form = formEl as unknown as FormLike
  /** The shopper unlocked the fields after this pass: do not lock them again until a new pass. */
  let unlockedByShopper = false

  const container = () => document.querySelector<HTMLElement>(CONTAINER)
  const status = (): string | null => container()?.getAttribute("data-status") ?? null

  /** Runs one WooCommerce checkout update and waits for it (or a timeout). */
  const updateCheckout = (): Promise<void> =>
    new Promise((resolve) => {
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

  const setMessage = (text: string) => {
    const el = container()?.querySelector<HTMLElement>(MESSAGE)
    if (el && text) el.textContent = text
  }

  const showLockBar = (show: boolean) => {
    const bar = document.querySelector<HTMLElement>(LOCK_BAR)
    if (!bar) return
    // Inline display too: a theme's .woocommerce-info{display:…} beats [hidden].
    bar.hidden = !show
    bar.style.display = show ? "" : "none"
  }

  const lock = () => {
    if (unlockedByShopper) return
    lockForm(form, document as unknown as LockDoc)
    showLockBar(isLocked(form))
  }
  const unlock = () => {
    unlockForm(form)
    showLockBar(false)
  }

  const clearNotices = () => {
    document.querySelectorAll(`[${NOTICE}]`).forEach((n) => n.remove())
  }

  /** A refusal where WooCommerce shows its own checkout errors, announced and focused. */
  const fail = (message: string, focusChallenge: boolean) => {
    clearNotices()
    document.querySelectorAll(".woocommerce-NoticeGroup-checkout").forEach((n) => n.remove())
    const group = document.createElement("div")
    group.className = "woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"
    group.setAttribute(NOTICE, "1")
    group.tabIndex = -1
    const list = document.createElement("ul")
    list.className = "woocommerce-error"
    list.setAttribute("role", "alert")
    const item = document.createElement("li")
    item.textContent = message
    list.appendChild(item)
    group.appendChild(list)
    formEl.insertBefore(group, formEl.firstChild)
    setMessage(message)
    announce(doc, message)
    const target = focusChallenge ? document.querySelector<HTMLElement>(TURNSTILE) : group
    focusOnReturn(target as unknown as ElementLike | null, window as unknown as WinLike)
    $(document.body).trigger("checkout_error")
  }

  const flow = createPlaceOrderFlow({
    status,
    updateCheckout,
    transport: storeApiTransport({ base: data.storeApi, namespace: data.namespace, restNonce: data.restNonce, startData }),
    verify: (url) => verify({ url }, { host: originOf(url), labels: data.labels }),
    block: () => {
      container()?.setAttribute("aria-busy", "true")
      $form.block?.({ message: null, overlayCSS: { background: "#fff", opacity: 0.6 } })
    },
    unblock: () => {
      container()?.setAttribute("aria-busy", "false")
      $form.unblock?.()
    },
    submit: () => {
      clearNotices()
      $form.trigger("submit")
    },
    passed: () => {
      unlockedByShopper = false
      container()?.setAttribute("data-status", "passed")
      setMessage(t("passed"))
      announce(doc, t("passed"))
      lock()
    },
    fail,
    t,
    challenging: () => !!turnstile?.challenging(),
  })

  $form.on("checkout_place_order", (event: { stopImmediatePropagation(): void }) => flow.onPlaceOrder(event))

  document.addEventListener("click", (event) => {
    const target = event.target as Element | null
    if (!target || typeof target.closest !== "function" || !target.closest(UNLOCK)) return
    event.preventDefault()
    unlockedByShopper = true
    unlock()
    announce(doc, t("unlocked"))
    const first = document.querySelector<HTMLElement>('[name="billing_first_name"]')
    first?.focus()
  })

  $(document.body).on("updated_checkout", () => {
    if (flow.busy()) return
    const now = status()
    if (now === "passed") lock()
    else if (now !== "pending") {
      unlockedByShopper = false
      if (isLocked(form)) unlock()
      // A message the server rendered (ship-to changed, refused) is news too.
      const text = container()?.querySelector(MESSAGE)?.textContent ?? ""
      if (text && now !== null) announce(doc, text)
    }
  })

  const initial = status()
  if (initial === "passed") lock()
  else if (initial === "pending") {
    // Back from a full-page redirect, or a reload mid-check: ask once.
    void storeApiTransport({ base: data.storeApi, namespace: data.namespace, restNonce: data.restNonce })("refresh")
      .then((answer) => {
        if (answer.status === "passed") {
          container()?.setAttribute("data-status", "passed")
          setMessage(t("passed"))
          announce(doc, t("passed"))
          lock()
        }
      })
      .catch(() => undefined)
  }
}

if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot)
else boot()
