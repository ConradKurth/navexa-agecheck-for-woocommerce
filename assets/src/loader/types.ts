/**
 * The Store API contract between the checkout glue and the store's server
 * (Navexa\AgeCheck\Start): POST /wc/store/v1/cart/extensions {namespace:
 * "navexa-agecheck", data: {v: 1, action}} answers, in the cart's
 * extensions["navexa-agecheck"], {v, required?, status, hosted_url?,
 * message?, code?}. The browser never holds a verification id, a guard or a
 * key; whatever the answer says, the server decides at Place order.
 */

export type Status = "none" | "pending" | "passed" | "failed" | "refused" | "unavailable" | "hold"
export type Action = "start" | "refresh"

/**
 * The store's answer. code is a machine reason on refusals (1.1.0, additive).
 * status "hold" (TAX-1841): AgeCheck cannot run the check and the store takes
 * and holds orders then; Place order goes on with no modal, and the server's
 * gate decides again when the order is placed.
 */
export interface StoreAnswer {
  v: number
  status: string
  required?: boolean
  hosted_url?: string
  message?: string
  code?: string
}

export type Transport = (action: Action) => Promise<StoreAnswer>

/**
 * Refusal codes after which the classic checkout submits anyway: WooCommerce
 * shows its own field errors (no_address), or nothing needs a check
 * (not_required, pickup). The server still decides the order.
 */
export const SUBMIT_ANYWAY_CODES = ["no_address", "not_required", "pickup"]

/** The answer's status as the key of its shopper copy (i18n). */
export function statusKey(status: string): string {
  switch (status) {
    case "passed":
    case "failed":
    case "pending":
    case "unavailable":
    case "hold":
      return status
    case "none":
      return "pending"
    default:
      return "unavailable"
  }
}

/** The origin of a hosted page URL, or "" (the loader's host option: the page's own origin, which the server checked). */
export function originOf(url: string): string {
  try {
    return new URL(url).origin
  } catch {
    return ""
  }
}
