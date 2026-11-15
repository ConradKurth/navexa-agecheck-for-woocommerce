/**
 * Browser entry: exposes the loader as window.NavexaAgeCheck.
 * Built to assets/js/agecheck-<version>.js (+ .min.js) by esbuild.
 */
import { create, parseHostedUrl, LOADER_VERSION } from "./agecheck"

export type { AgeCheckLoader, LoaderOptions, LoaderState, Status, StoreAnswer, Transport, Action } from "./agecheck"

export interface NavexaAgeCheckGlobal {
  version: string
  create: typeof create
  parseHostedUrl: typeof parseHostedUrl
}

declare global {
  interface Window {
    NavexaAgeCheck?: NavexaAgeCheckGlobal
  }
}

if (typeof window !== "undefined" && !window.NavexaAgeCheck) {
  window.NavexaAgeCheck = { version: LOADER_VERSION, create, parseHostedUrl }
}
