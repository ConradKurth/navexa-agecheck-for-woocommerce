/**
 * Entry gate options: the public types, the defaults, and the validation every
 * value goes through, whether it comes from gate() or from the script tag's
 * data- attributes (merchants hand-edit tags). The rules are
 * schema/gate-options.schema.json; schema/vectors/ pins them for every
 * consumer (Woo PHP, the free gate generator, BigCommerce, Shopify).
 *
 * - An invalid value is ignored: its default is used and an issue is
 *   reported (gate() prints each as a console warning). A value the schema
 *   accepts raises no issue, and every value it refuses raises one.
 * - Text is checked as given (length in Unicode code points), then control
 *   characters fold to spaces and the ends are trimmed. Nothing given reaches
 *   HTML (text is set as textContent) or raw CSS (colours and the font match
 *   strict patterns and go into custom properties).
 * - Contrast (B6): text on card and button text on button must reach 4.5:1;
 *   a pair under it falls back to the default pair.
 */
import { MIN_CONTRAST, contrastRatio } from "./contrast"

export type GateMode = "attest" | "dob"
export type MinAge = 18 | 19 | 21

export interface GateTheme {
  /** Colour behind the card, #rgb or #rrggbb (a colour only, no image). */
  backdrop?: string
  /** Card background. */
  card?: string
  /** Card text. */
  text?: string
  /** Buttons and focus ring. */
  accent?: string
  /** Button text. */
  accentText?: string
  /** CSS font-family list, or "inherit" for the site's own font. */
  font?: string
}

/** Every gate label key, in the order the schema lists them. */
export const GATE_LABEL_KEYS = ["yes", "no", "submit", "dob", "month", "day", "year", "invalid", "denied", "leaving"] as const
export type GateLabelKey = (typeof GATE_LABEL_KEYS)[number]
export type GateLabels = { [K in GateLabelKey]?: string }

export interface GateOptions {
  mode?: GateMode
  minAge?: MinAge
  /** 0..365; 0 remembers a Yes in this tab only (sessionStorage). */
  rememberDays?: number
  exitUrl?: string
  siteName?: string
  title?: string
  message?: string
  logoUrl?: string
  theme?: GateTheme
  labels?: GateLabels
}

export interface GateConfig {
  mode: GateMode
  minAge: MinAge
  rememberDays: number
  exitUrl: string
  siteName: string
  title: string
  message: string
  logoUrl: string
  theme: Required<GateTheme>
  labels: Required<GateLabels>
}

/** One ignored value: its option path (contrast.<fg>-<bg> for a pair) and why. */
export interface GateIssue {
  field: string
  problem: string
}

export const DEFAULT_MIN_AGE: MinAge = 21
export const DEFAULT_REMEMBER_DAYS = 30

export const DEFAULT_THEME: Required<GateTheme> = {
  backdrop: "#1e1e1e",
  card: "#ffffff",
  text: "#1e1e1e",
  accent: "#1e1e1e",
  accentText: "#ffffff",
  font: 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
}

/** Default copy; {age} is the minimum age, {site} the site name. */
export const DEFAULT_TEXT = {
  title: "Welcome",
  titleSite: "Welcome to {site}",
  messageAttest: "You must be {age} or older to enter this site. Please confirm your age.",
  messageDob: "You must be {age} or older to enter this site. Please enter your date of birth.",
}

export const DEFAULT_LABELS: Required<GateLabels> = {
  yes: "I am {age} or older",
  no: "I am under {age}",
  submit: "Enter",
  dob: "Date of birth",
  month: "Month",
  day: "Day",
  year: "Year",
  invalid: "Enter a real date of birth: month, day and year.",
  denied: "Sorry, you must be {age} or older to visit this site.",
  leaving: "Leaving this site…",
}

/** Maximum lengths in Unicode code points, of the value as given. */
export const MAX_LENGTH = { siteName: 60, title: 80, message: 300, url: 2048, label: 40, longLabel: 160 }
const LONG_LABELS: string[] = ["invalid", "denied", "leaving"]

/** GateOptions keys and their data- attribute names (README, schema x-attribute). */
export const GATE_ATTRIBUTES = {
  mode: "data-mode",
  minAge: "data-min-age",
  rememberDays: "data-remember-days",
  exitUrl: "data-exit-url",
  siteName: "data-site-name",
  title: "data-title",
  message: "data-message",
  logoUrl: "data-logo-url",
} as const
const THEME_KEYS = Object.keys(DEFAULT_THEME)

const COLOUR = /^#(?:[0-9a-f]{3}){1,2}$/i
const FONT = /^[A-Za-z0-9 ,'"-]{1,100}$/
const URL_TEXT = /^https?:\/\/[^\s"<>\\\u0000-\u001f\u007f]+$/i
const CONTROL = /[\u0000-\u001f\u007f]+/g

const isObject = (value: unknown): value is Record<string, unknown> => !!value && typeof value === "object" && !Array.isArray(value)

/** Trimmed text with control characters folded to spaces, at most max long; "" when not a string. */
export function cleanText(value: unknown, max: number): string {
  return typeof value === "string" ? value.replace(CONTROL, " ").trim().slice(0, max) : ""
}

function fill(text: string, age: number, site: string): string {
  return text.replace(/\{age\}/g, String(age)).replace(/\{site\}/g, site)
}

/**
 * Validates options into the gate's full configuration; every ignored value
 * is pushed onto issues.
 */
export function gateConfig(options?: GateOptions, issues: GateIssue[] = []): GateConfig {
  const bad = (field: string, problem: string) => {
    issues.push({ field, problem })
  }
  const object = (value: unknown, field: string, known: readonly string[]): Record<string, unknown> => {
    if (value === undefined) return {}
    if (!isObject(value)) {
      bad(field, "not an object")
      return {}
    }
    for (const key of Object.keys(value)) if (!known.includes(key)) bad(field ? `${field}.${key}` : key, "unknown option")
    return value
  }
  const oneOf = <T>(value: unknown, allowed: readonly T[], fallback: T, field: string): T => {
    if (value === undefined) return fallback
    if (allowed.includes(value as T)) return value as T
    bad(field, `not one of ${allowed.join(", ")}`)
    return fallback
  }
  const text = (value: unknown, max: number, field: string): string => {
    if (value === undefined || value === "") return ""
    if (typeof value !== "string") bad(field, "not text")
    else if ([...value].length > max) bad(field, `over ${max} characters`)
    else return value.replace(CONTROL, " ").trim()
    return ""
  }
  const url = (value: unknown, field: string, https: boolean): string => {
    if (value === undefined || value === "") return ""
    let ok = typeof value === "string" && value.length <= MAX_LENGTH.url && URL_TEXT.test(value)
    if (ok) {
      try {
        const u = new URL(value as string)
        ok = u.protocol === "https:" || (!https && u.protocol === "http:")
      } catch {
        ok = false
      }
    }
    if (ok) return value as string
    bad(field, https ? "not an absolute https: URL" : "not an absolute http(s) URL")
    return ""
  }

  const o = object(options, "", [...Object.keys(GATE_ATTRIBUTES), "theme", "labels"])
  const mode = oneOf<GateMode>(o.mode, ["attest", "dob"], "attest", "mode")
  const minAge = oneOf<MinAge>(o.minAge, [18, 19, 21], DEFAULT_MIN_AGE, "minAge")
  let rememberDays = DEFAULT_REMEMBER_DAYS
  if (o.rememberDays !== undefined) {
    const n = o.rememberDays
    if (typeof n === "number" && Number.isInteger(n) && n >= 0 && n <= 365) rememberDays = n
    else bad("rememberDays", "not a whole number 0..365")
  }
  const siteName = text(o.siteName, MAX_LENGTH.siteName, "siteName")

  const t = object(o.theme, "theme", THEME_KEYS)
  const theme = { ...DEFAULT_THEME }
  for (const key of THEME_KEYS as Array<keyof GateTheme>) {
    const value = t[key]
    if (value === undefined || value === "") continue
    const ok = typeof value === "string" && (key === "font" ? FONT : COLOUR).test(value)
    if (ok) theme[key] = key === "font" ? value.trim() || DEFAULT_THEME.font : value.toLowerCase()
    else bad(`theme.${key}`, key === "font" ? "not a plain font-family list" : "not #rgb or #rrggbb")
  }
  for (const [fg, bg] of [
    ["text", "card"],
    ["accentText", "accent"],
  ] as const) {
    const ratio = contrastRatio(theme[fg], theme[bg])
    if (ratio < MIN_CONTRAST) {
      bad(`contrast.${fg}-${bg}`, `${ratio.toFixed(2)}:1, under ${MIN_CONTRAST}:1; using the default ${fg} and ${bg}`)
      theme[fg] = DEFAULT_THEME[fg]
      theme[bg] = DEFAULT_THEME[bg]
    }
  }

  const given = object(o.labels, "labels", GATE_LABEL_KEYS)
  const labels = { ...DEFAULT_LABELS }
  for (const key of GATE_LABEL_KEYS) {
    const max = LONG_LABELS.includes(key) ? MAX_LENGTH.longLabel : MAX_LENGTH.label
    labels[key] = fill(text(given[key], max, `labels.${key}`) || labels[key], minAge, siteName)
  }
  const title = text(o.title, MAX_LENGTH.title, "title") || (siteName ? DEFAULT_TEXT.titleSite : DEFAULT_TEXT.title)
  const message = text(o.message, MAX_LENGTH.message, "message") || (mode === "dob" ? DEFAULT_TEXT.messageDob : DEFAULT_TEXT.messageAttest)
  return {
    mode,
    minAge,
    rememberDays,
    exitUrl: url(o.exitUrl, "exitUrl", false),
    siteName,
    title: fill(title, minAge, siteName),
    message: fill(message, minAge, siteName),
    logoUrl: url(o.logoUrl, "logoUrl", true),
    theme,
    labels,
  }
}

/** "accent-text" -> "accentText". */
function camel(name: string): string {
  return name.replace(/-([a-z])/g, (_, c: string) => c.toUpperCase())
}

/** The data- attribute of an option path: theme.accentText -> data-theme-accent-text. */
export function attributeOf(field: string): string {
  return `data-${field.replace(/^labels\./, "label-").replace(/^theme\./, "theme-")}`.replace(/[A-Z]/g, (c) => `-${c.toLowerCase()}`)
}

/**
 * GateOptions from a script tag's data- attributes (README): the
 * GATE_ATTRIBUTES, data-theme-<key> and data-label-<key> (kebab-case keys).
 * Values are trimmed; an empty one counts as not set; data-min-age and
 * data-remember-days become numbers when they are digits. gateConfig
 * validates the rest (an unknown theme or label key is an issue there).
 */
export function gateOptionsFrom(script: Element): GateOptions {
  const out: Record<string, unknown> = {}
  const theme: Record<string, string> = {}
  const labels: Record<string, string> = {}
  for (const { name, value } of Array.from(script.attributes)) {
    const v = value.trim()
    if (!v) continue
    if (name.startsWith("data-theme-")) theme[camel(name.slice(11))] = v
    else if (name.startsWith("data-label-")) labels[camel(name.slice(11))] = v
  }
  for (const [key, attr] of Object.entries(GATE_ATTRIBUTES)) {
    const v = (script.getAttribute(attr) || "").trim()
    if (v) out[key] = (key === "minAge" || key === "rememberDays") && /^\d{1,3}$/.test(v) ? Number(v) : v
  }
  if (Object.keys(theme).length) out.theme = theme
  if (Object.keys(labels).length) out.labels = labels
  return out as GateOptions
}
