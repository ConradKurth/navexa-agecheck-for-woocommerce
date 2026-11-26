/**
 * Date-of-birth arithmetic for the entry gate. Dates are calendar days in the
 * visitor's own time zone (what "today" means to them); no time of day.
 */

export interface Day {
  y: number
  m: number
  d: number
}

/** The visitor's local calendar day at epoch milliseconds ms. */
export function localDay(ms: number): Day {
  const t = new Date(ms)
  return { y: t.getFullYear(), m: t.getMonth() + 1, d: t.getDate() }
}

/** Days in month m (1..12) of year y, leap years included. */
export function daysInMonth(y: number, m: number): number {
  return new Date(Date.UTC(y, m, 0)).getUTCDate()
}

/** Whether y-m-d is a real calendar date from 1900 on. */
export function validDate(day: Day): boolean {
  const { y, m, d } = day
  return Number.isInteger(y) && Number.isInteger(m) && Number.isInteger(d) && y >= 1900 && m >= 1 && m <= 12 && d >= 1 && d <= daysInMonth(y, m)
}

/** -1, 0 or 1 as a is before, on or after b. */
export function compareDays(a: Day, b: Day): number {
  return Math.sign(a.y - b.y || a.m - b.m || a.d - b.d)
}

/**
 * Completed years between birth and today. The birthday counts from its own
 * day: someone born on 29 February turns a year older on 1 March in a common
 * year (never on 28 February), the later and stricter reading.
 */
export function ageOn(birth: Day, today: Day): number {
  let age = today.y - birth.y
  if (today.m < birth.m || (today.m === birth.m && today.d < birth.d)) age--
  return age
}

/**
 * The gate's answer for a typed date of birth: "invalid" (not a date, before
 * 1900 or in the future), "under" or "ok".
 */
export function judgeBirth(birth: Day, today: Day, minAge: number): "invalid" | "under" | "ok" {
  if (!validDate(birth) || compareDays(birth, today) > 0) return "invalid"
  return ageOn(birth, today) >= minAge ? "ok" : "under"
}
