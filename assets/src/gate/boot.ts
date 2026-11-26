/**
 * The entry age gate's boot script (TAX-1874): runs in the page head right
 * after the shared loader's gate-only build (assets/vendor/agecheck-js/
 * agecheck-gate-<v>.js), reads the gate's options from its own script tag's
 * data-config attribute (EntryGate.php; no inline script) and calls
 * window.NavexaAgeCheck.gate(options). The loader owns everything else: the
 * dialog, the date-of-birth check (in the browser, never stored or sent),
 * the remember rule, crawlers, the exit page, accessibility, and never
 * leaving the page hidden on an error.
 *
 * Before that it moves a remembered answer of the 1.0.x gate (key
 * navexa-agecheck-gate = {v: 1, age, at}) to the loader's key
 * (navexa_agecheck_gate_v1 = {passedAt, minAge}) in the same storage, when
 * the loader's key is absent and the old answer still counts by the 1.0.x
 * rule (given for at least the minimum age, not in the future, younger than
 * the remember-days; for 0 days only sessionStorage is read), so nobody who
 * answered before the update is asked again. Storage that throws is skipped.
 * No network call, no cookie.
 *
 * Built to assets/js/gate-boot.js (+ .min.js) by esbuild.
 */

export const LEGACY_KEY = "navexa-agecheck-gate"
export const KEY = "navexa_agecheck_gate_v1"
const DAY_MS = 86_400_000

export interface StorageLike {
  getItem(key: string): string | null
  setItem(key: string, value: string): void
}

export interface BootConfig {
  v: number
  options: Record<string, unknown>
}

/** The boot configuration from the data-config attribute's text, or null. */
export function readConfig(text: string | null | undefined): BootConfig | null {
  if (!text) return null
  try {
    const value = JSON.parse(text) as Partial<BootConfig>
    if (!value || typeof value !== "object" || value.v !== 2 || !value.options || typeof value.options !== "object" || Array.isArray(value.options)) return null
    return { v: 2, options: value.options as Record<string, unknown> }
  } catch {
    return null
  }
}

/** The loader's value for a 1.0.x answer that still counts, or null. */
export function migrated(raw: string | null, minAge: number, days: number, now: number): string | null {
  if (!raw) return null
  let value: unknown
  try {
    value = JSON.parse(raw)
  } catch {
    return null
  }
  if (!value || typeof value !== "object") return null
  const { v, age, at } = value as { v?: unknown; age?: unknown; at?: unknown }
  if (v !== 1 || typeof age !== "number" || typeof at !== "number" || !Number.isFinite(at) || age < minAge || at > now) return null
  if (days !== 0 && now - at >= days * DAY_MS) return null
  return JSON.stringify({ passedAt: at, minAge: age })
}

/**
 * Moves the 1.0.x answer to the loader's key where it still counts; returns
 * whether it wrote one. rememberDays 0 reads (and writes) sessionStorage only.
 */
export function migrateLegacy(options: Record<string, unknown>, local: StorageLike | null, session: StorageLike | null, now: number): boolean {
  const minAge = typeof options.minAge === "number" ? options.minAge : 21
  const days = typeof options.rememberDays === "number" && Number.isInteger(options.rememberDays) && options.rememberDays >= 0 ? options.rememberDays : 30
  let wrote = false
  for (const store of days === 0 ? [session] : [local, session]) {
    if (!store) continue
    try {
      if (store.getItem(KEY) !== null) continue
      const value = migrated(store.getItem(LEGACY_KEY), minAge, days, now)
      if (value) {
        store.setItem(KEY, value)
        wrote = true
      }
    } catch {
      // Blocked storage: the gate asks again.
    }
  }
  return wrote
}

/** The browser storages, null where reading them throws. */
function storages(): { local: StorageLike | null; session: StorageLike | null } {
  let local: StorageLike | null = null
  let session: StorageLike | null = null
  try {
    local = window.localStorage
  } catch {
    local = null
  }
  try {
    session = window.sessionStorage
  } catch {
    session = null
  }
  return { local, session }
}

/** Boots the gate from a script element; never throws. */
export function boot(script: { getAttribute(name: string): string | null } | null): void {
  try {
    const config = readConfig(script?.getAttribute("data-config"))
    const api = typeof window !== "undefined" ? window.NavexaAgeCheck : undefined
    if (!config || !api || typeof api.gate !== "function") return
    const { local, session } = storages()
    migrateLegacy(config.options, local, session, Date.now())
    api.gate(config.options)
  } catch {
    // The loader's gate never leaves the page hidden; nothing else to undo.
  }
}

if (typeof document !== "undefined" && document.currentScript) boot(document.currentScript)
