/**
 * @navexa/agecheck-js: the Navexa AgeCheck browser loader.
 *
 * - verify(): the AgeCheck hosted page in an in-page modal (verify.ts).
 * - gate(): the free entry age gate (gate.ts); isRemembered() is its
 *   remember rule for platform glue.
 * - gateConfig(), gateOptionsFrom(), contrastRatio(): the option rules
 *   (schema/gate-options.schema.json) for settings UIs and generators.
 *
 * The script builds (agecheck-<version>.js and the gate-only
 * agecheck-gate-<version>.js, served from verify.navexa.co/js/) set
 * window.NavexaAgeCheck and start the gate from their own script tag's
 * data- attributes.
 */
export { VERSION } from "./version"
export {
  verify,
  hostOrigin,
  parseHostedUrl,
  DEFAULT_HOST,
  DEFAULT_VERIFY_LABELS,
  VERIFY_LABEL_KEYS,
  REDIRECT_MARKER,
  START_STATUSES,
} from "./verify"
export type { VerifySource, VerifyOptions, VerifyResult, VerifyLabels, HostedPage, StartAnswer } from "./verify"
export { gate, isRemembered, STORAGE_KEY } from "./gate"
export type { GateHandle } from "./gate"
export {
  gateConfig,
  gateOptionsFrom,
  attributeOf,
  DEFAULT_LABELS,
  DEFAULT_TEXT,
  DEFAULT_THEME,
  DEFAULT_MIN_AGE,
  DEFAULT_REMEMBER_DAYS,
  GATE_ATTRIBUTES,
  GATE_LABEL_KEYS,
  MAX_LENGTH,
} from "./options"
export type { GateOptions, GateConfig, GateIssue, GateTheme, GateLabels, GateLabelKey, GateMode, MinAge } from "./options"
export { contrastRatio, MIN_CONTRAST } from "./contrast"
export { isCrawler } from "./crawler"
