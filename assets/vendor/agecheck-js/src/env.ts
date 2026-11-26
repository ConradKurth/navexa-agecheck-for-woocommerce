/**
 * The browser calls the loader makes that tests replace (jsdom cannot spy on
 * location.assign). Nothing else reads the outside world.
 */

/** The part of a fetch Response the status poll reads. */
export interface StatusResponse {
  status: number
  headers: { get(name: string): string | null }
  json(): Promise<unknown>
}

/** The part of a popup window the loader touches. */
export interface PopupHandle {
  closed: boolean
  focus?(): void
  close?(): void
}

export interface Env {
  open(url: string, name: string, features: string): PopupHandle | null
  assign(url: string): void
  fetch(url: string, init: RequestInit): Promise<StatusResponse>
  now(): number
  userAgent(): string
  href(): string
  local(): Storage | null
  session(): Storage | null
}

const storage = (read: () => Storage): Storage | null => {
  try {
    return read()
  } catch {
    // Opaque-origin frames and some private modes throw on access.
    return null
  }
}

export const env: Env = {
  open: (url, name, features) => window.open(url, name, features),
  assign: (url) => window.location.assign(url),
  fetch: (url, init) => window.fetch(url, init),
  now: () => Date.now(),
  userAgent: () => (typeof navigator === "undefined" ? "" : navigator.userAgent || ""),
  href: () => window.location.href,
  local: () => storage(() => window.localStorage),
  session: () => storage(() => window.sessionStorage),
}
