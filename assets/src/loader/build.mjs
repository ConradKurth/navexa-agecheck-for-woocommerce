// Builds the plugin's own scripts with esbuild (the shared AgeCheck loader is
// not built here: it is vendored byte for byte in assets/vendor/agecheck-js/
// by bin/sync-loader.mjs):
//   assets/src/loader/classic.ts   -> assets/js/checkout-classic.js and .min.js
//   assets/src/loader/turnstile.ts -> assets/js/turnstile.js and .min.js (loaded only with Turnstile on)
//   assets/src/gate/boot.ts        -> assets/js/gate-boot.js and .min.js (the entry gate on the shared loader)
//   assets/src/admin/settings.ts   -> assets/js/admin-settings.js and .min.js (the entry gate settings section)
//   assets/src/loader/gate.ts      -> assets/js/entry-gate.js and .min.js (the 1.0.x gate: no longer
//                                     enqueued, kept for pages a cache stored under 1.0.x; goes in 1.2.0)
// Run: npm run build:loader. The output is committed (wordpress.org
// Guideline 4: the readable source and its build ship together).
import { build } from "esbuild"
import path from "node:path"
import { fileURLToPath } from "node:url"

const here = path.dirname(fileURLToPath(import.meta.url))
const src = path.resolve(here, "..")
const root = path.resolve(here, "../../..")

const banner = (name, source) => `/*! ${name} | Navexa AgeCheck for WooCommerce | GPL-2.0-or-later | source: ${source} */`

const targets = [
  { entry: "loader/classic.ts", out: "checkout-classic", name: "Navexa AgeCheck classic checkout" },
  { entry: "loader/turnstile.ts", out: "turnstile", name: "Navexa AgeCheck Turnstile glue" },
  { entry: "gate/boot.ts", out: "gate-boot", name: "Navexa AgeCheck entry age gate boot (self-attestation)" },
  { entry: "admin/settings.ts", out: "admin-settings", name: "Navexa AgeCheck entry gate settings" },
  { entry: "loader/gate.ts", out: "entry-gate", name: "Navexa AgeCheck entry age gate (self-attestation)" },
]

for (const target of targets) {
  for (const minify of [false, true]) {
    await build({
      entryPoints: [path.join(src, target.entry)],
      outfile: path.join(root, "assets/js", `${target.out}${minify ? ".min" : ""}.js`),
      bundle: true,
      format: "iife",
      target: ["es2019"],
      platform: "browser",
      minify,
      legalComments: "none",
      banner: { js: banner(target.name, target.entry === "loader/gate.ts" ? "assets/src/loader/" : `assets/src/${path.dirname(target.entry)}/`) },
      logLevel: "warning",
    })
  }
}
console.log("built the classic glue, the Turnstile glue, the gate boot, the gate settings script and the 1.0.x entry gate")
