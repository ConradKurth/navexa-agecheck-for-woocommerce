/**
 * window.NavexaAgeCheck for the script builds. Each build adds what it has
 * and keeps what is there: the gate-only build and the full loader on one
 * page share one global (the first version wins for each member), and a
 * second copy of either never replaces the first.
 */
export interface NavexaAgeCheckGlobal {
  version: string
  gate: (typeof import("./gate"))["gate"]
  isRemembered: (typeof import("./gate"))["isRemembered"]
  verify?: (typeof import("./verify"))["verify"]
}

declare global {
  interface Window {
    NavexaAgeCheck?: NavexaAgeCheckGlobal
  }
}

export function publish(members: NavexaAgeCheckGlobal): NavexaAgeCheckGlobal {
  const g = (window.NavexaAgeCheck = window.NavexaAgeCheck || members) as unknown as Record<string, unknown>
  for (const [key, value] of Object.entries(members)) if (!(key in g)) g[key] = value
  return g as unknown as NavexaAgeCheckGlobal
}
