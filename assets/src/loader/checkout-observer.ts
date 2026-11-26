/**
 * Block checkout: the age check at Place order (TAX-1874, design-woo §4).
 *
 * Registered with window.wc.blocksCheckoutEvents.checkoutEvents
 * .onCheckoutValidation(observer, 10) (script wc-blocks-checkout-events,
 * WooCommerce 9.9 and later). WooCommerce awaits every validation observer
 * before it places the order and keeps only object answers with a type: true
 * (or nothing) lets it go on, {type: "error", errorMessage} shows the message
 * in the checkout notices and stops. No timeout exists there, so the modal
 * (and an ID step inside it) can take minutes.
 *
 * - Idempotent: a payment method's own button can emit validation twice for
 *   one attempt (useValidateCheckout), so concurrent calls share one run: one
 *   start, one modal.
 * - Outage fallback (TAX-1841): a start (or refresh) answering "hold" lets
 *   the order go with no modal (the block shows the hold copy from the cart
 *   state); Checkout\Blocks::validate() admits it only on the outage this
 *   session's own start saw, and it is held until the shopper verifies by
 *   link. With the store refusing (the default) the start answers
 *   "unavailable" and the order is stopped.
 * - Short-circuits without a modal when the cart needs no check or has passed,
 *   when WooCommerce's own field validation has errors (it shows them; the
 *   observer still runs after a failing validation observer of its own), and
 *   while an express payment method is active (refused by the server; hidden
 *   by default).
 * - Pushes the customer data first: WooCommerce sends address edits to the
 *   session debounced (1.5 s), and the start binds the session's ship-to.
 * - An error answer hands the focus back to Place order (restoreFocus) unless
 *   a Turnstile challenge takes it: WooCommerce keeps the button disabled
 *   while it awaits the observer, so the modal's own focus return finds it
 *   disabled and the focus would fall to the body.
 *
 * The browser never decides: Checkout\Blocks::validate() refuses an order
 * that has not passed on the server.
 */
import { statusKey, type Transport } from "./types.ts"
import type { VerifyResult } from "./navexa-agecheck.ts"
import { failureMessage } from "./place-order.ts"

/** The answer WooCommerce's checkout events emitter understands. */
export type ObserverAnswer = true | { type: "error"; errorMessage: string }

/** Refusal codes after which the block checkout goes on (nothing to check). */
export const PROCEED_CODES = ["not_required", "pickup"]

export interface ObserverDeps {
  /** The cart's extensions["navexa-agecheck"], or null. */
  state(): { required?: boolean; status?: string } | null
  hasValidationErrors(): boolean
  expressActive(): boolean
  /** Sends the address the shopper sees to the session and waits for it. */
  pushCustomerData(): Promise<void>
  transport: Transport
  /** Opens the hosted page in the modal; null when the loader threw. */
  verify(url: string): Promise<VerifyResult | null>
  t(key: string): string
  /** Whether Turnstile shows an interactive challenge (then focus it). */
  challenging(): boolean
  onChallenge?(): void
  /**
   * The run answers an error (other than a Turnstile challenge): hand the
   * focus back to Place order once WooCommerce enables it again.
   */
  restoreFocus?(): void
}

export function createCheckoutObserver(deps: ObserverDeps): () => Promise<ObserverAnswer> {
  let inflight: Promise<ObserverAnswer> | null = null

  async function run(): Promise<ObserverAnswer> {
    const state = deps.state()
    if (!state || !state.required || state.status === "passed") return true
    if (deps.hasValidationErrors() || deps.expressActive()) return true
    try {
      try {
        await deps.pushCustomerData()
      } catch {
        // The start answers no_address or the like; WooCommerce shows its own errors.
      }
      let answer = await deps.transport("start")
      if (answer.status === "refused" && answer.code && PROCEED_CODES.includes(answer.code)) return true
      if (answer.status === "hold") return true
      let closed = false
      if (answer.status === "pending" && answer.hosted_url) {
        let result: VerifyResult | null = null
        try {
          result = await deps.verify(answer.hosted_url)
        } catch {
          result = null
        }
        closed = result?.status === "closed"
        answer = await deps.transport("refresh")
      }
      if (answer.status === "passed" || answer.status === "hold") return true
      if (answer.status === "refused" && deps.challenging()) deps.onChallenge?.()
      else deps.restoreFocus?.()
      return { type: "error", errorMessage: failureMessage(answer, closed, deps.t) || deps.t(statusKey(answer.status)) }
    } catch {
      deps.restoreFocus?.()
      return { type: "error", errorMessage: deps.t("unavailable") }
    }
  }

  return () => {
    if (!inflight) {
      inflight = run().finally(() => {
        inflight = null
      })
    }
    return inflight
  }
}
