/**
 * Types of window.NavexaAgeCheck, the shared Navexa AgeCheck loader
 * (@navexa/agecheck-js, brief C2 v2), vendored byte for byte in
 * assets/vendor/agecheck-js/. The glue codes against this contract, never
 * against the vendored source's layout. The full build sets {version,
 * verify, gate, isRemembered}; the gate-only build {version, gate,
 * isRemembered}.
 */

export interface VerifyResult {
  /** passed | failed (or another final status), closed, or a start() status. */
  status: string
  verificationId?: string
  message?: string
}

export interface VerifyOptions {
  onResult?: (result: VerifyResult) => void
  onClose?: () => void
  /** The AgeCheck host origin; https, or http on localhost/127.0.0.1 (tests). */
  host?: string
  /** The modal's strings (VERIFY_LABEL_KEYS). */
  labels?: Record<string, string>
  readyTimeoutMs?: number
  pollIntervalMs?: number
}

export interface NavexaAgeCheckApi {
  version: string
  /** Opens the hosted page in the in-page modal; resolves once, never rejects for a shopper's action ({status: "closed"} on close). */
  verify?: (source: { url: string; verificationId?: string }, options?: VerifyOptions) => Promise<VerifyResult>
  gate: (options: Record<string, unknown>) => unknown
  isRemembered: (minAge: number, rememberDays?: number) => boolean
}

declare global {
  interface Window {
    NavexaAgeCheck?: NavexaAgeCheckApi
  }
}
