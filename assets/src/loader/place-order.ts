/**
 * Classic checkout: the age check at Place order (TAX-1874, design-woo §3).
 *
 * WooCommerce's checkout.js submit() runs
 * $form.triggerHandler("checkout_place_order") and goes on only when that
 * does not answer false, before the gateway's own checkout_place_order_<id>
 * handler. jQuery's triggerHandler answers the LAST result that is not
 * undefined, so the handler stops the event (stopImmediatePropagation) as
 * well as answering false while a check is needed: a handler bound after ours
 * cannot turn the false back into true. When it does not step in (the
 * one-shot bypass, no check needed, already passed) it answers undefined,
 * never true: a true would replace a false from another plugin's handler
 * bound before ours, and WooCommerce would submit anyway.
 *
 * One attempt: push the address the shopper sees into the session (an
 * update_checkout), then start; a hosted page opens in the loader's in-page
 * modal (NavexaAgeCheck.verify({url})); whatever it resolves (passed,
 * failed, closed) the store's server is asked again (refresh), and on passed
 * the fields are locked and the form submitted once more with a one-shot
 * bypass, so the shopper never clicks twice. Anything else shows the
 * server's message where WooCommerce shows its own checkout errors.
 *
 * The browser never decides: Checkout\Classic::validate() refuses an order
 * that has not passed on the server, whatever this code did.
 */
import { SUBMIT_ANYWAY_CODES, statusKey, type StoreAnswer, type Transport } from "./types.ts"
import type { VerifyResult } from "./navexa-agecheck.ts"

export interface PlaceOrderDeps {
  /** The status the page shows (the server's, data-status), or null when the cart needs no check (no container). */
  status(): string | null
  /** Runs a WooCommerce checkout update (the session gets the address the shopper sees) and waits for it. */
  updateCheckout(): Promise<void>
  transport: Transport
  /** Opens the hosted page in the modal; null when the loader threw (the result is a hint only). */
  verify(url: string): Promise<VerifyResult | null>
  /** Blocks the form visually while the store answers (never WooCommerce's .processing class). */
  block(): void
  unblock(): void
  /** Submits the form again ($form.trigger("submit")). */
  submit(): void
  /** The server said passed: lock the fields and show the pass. */
  passed(): void
  /** Shows a refusal; focusChallenge when Turnstile shows an interactive challenge. */
  fail(message: string, focusChallenge: boolean): void
  /** Shopper copy by key. */
  t(key: string): string
  /** Whether Turnstile shows an interactive challenge on the page. */
  challenging(): boolean
}

export interface PlaceOrderFlow {
  /**
   * The checkout_place_order handler: false stops WooCommerce; undefined
   * (never true) leaves the answer to the other handlers.
   */
  onPlaceOrder(event: { stopImmediatePropagation(): void }): false | undefined
  /** One attempt (the handler starts it). */
  run(): Promise<void>
  busy(): boolean
}

/** The copy for an answer that did not pass. */
export function failureMessage(answer: StoreAnswer, closed: boolean, t: (key: string) => string): string {
  if (answer.message) return answer.message
  if (closed && answer.status !== "failed") return t("closed")
  return t(statusKey(answer.status))
}

export function createPlaceOrderFlow(deps: PlaceOrderDeps): PlaceOrderFlow {
  let bypass = false
  let running = false
  let blocked = false

  const block = () => {
    if (!blocked) {
      blocked = true
      deps.block()
    }
  }
  const unblock = () => {
    if (blocked) {
      blocked = false
      deps.unblock()
    }
  }
  /** Submits once more past this handler. Unblocks first: unblocking later would remove WooCommerce's own processing overlay. */
  const resubmit = () => {
    unblock()
    bypass = true
    try {
      deps.submit()
    } finally {
      bypass = false
    }
  }

  async function run(): Promise<void> {
    if (running) return
    running = true
    block()
    try {
      await deps.updateCheckout()
      if (deps.status() === "passed") {
        resubmit()
        return
      }
      let answer = await deps.transport("start")
      if (answer.status === "refused" && answer.code && SUBMIT_ANYWAY_CODES.includes(answer.code)) {
        resubmit()
        return
      }
      let closed = false
      if (answer.status === "pending" && answer.hosted_url) {
        unblock()
        let result: VerifyResult | null = null
        try {
          result = await deps.verify(answer.hosted_url)
        } catch {
          result = null
        }
        closed = result?.status === "closed"
        block()
        answer = await deps.transport("refresh")
      }
      if (answer.status === "passed") {
        deps.passed()
        resubmit()
        return
      }
      deps.fail(failureMessage(answer, closed, deps.t), answer.status === "refused" && deps.challenging())
    } catch {
      deps.fail(deps.t("unavailable"), false)
    } finally {
      running = false
      unblock()
    }
  }

  return {
    onPlaceOrder(event) {
      if (bypass) return undefined
      const status = deps.status()
      if (status === null || status === "passed") return undefined
      event.stopImmediatePropagation()
      if (!running) void run()
      return false
    },
    run,
    busy: () => running,
  }
}
