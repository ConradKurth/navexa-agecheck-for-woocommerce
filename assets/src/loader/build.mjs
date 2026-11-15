// Builds the AgeCheck loader and the classic checkout glue with esbuild:
//   assets/src/loader/index.ts   -> assets/js/agecheck-<LOADER_VERSION>.js and .min.js
//   assets/src/loader/classic.ts -> assets/js/checkout-classic.js and .min.js
//   assets/src/loader/turnstile.ts -> assets/js/turnstile.js and .min.js (loaded only with Turnstile on)
// Run: npm run build:loader. The output is committed (wordpress.org
// Guideline 4: the readable source and its build ship together).
import { build } from "esbuild"
import { readFileSync } from "node:fs"
import path from "node:path"
import { fileURLToPath } from "node:url"

const here = path.dirname(fileURLToPath(import.meta.url))
const root = path.resolve(here, "../../..")
const version = /export const LOADER_VERSION = "([0-9.]+)"/.exec(readFileSync(path.join(here, "agecheck.ts"), "utf8"))[1]

const banner = (name) =>
  `/*! ${name} | Navexa AgeCheck for WooCommerce | GPL-2.0-or-later | source: assets/src/loader/ */`

const targets = [
  { entry: "index.ts", out: `agecheck-${version}`, name: `Navexa AgeCheck loader ${version}` },
  { entry: "classic.ts", out: "checkout-classic", name: "Navexa AgeCheck classic checkout" },
  { entry: "turnstile.ts", out: "turnstile", name: "Navexa AgeCheck Turnstile glue" },
]

for (const target of targets) {
  for (const minify of [false, true]) {
    await build({
      entryPoints: [path.join(here, target.entry)],
      outfile: path.join(root, "assets/js", `${target.out}${minify ? ".min" : ""}.js`),
      bundle: true,
      format: "iife",
      target: ["es2019"],
      platform: "browser",
      minify,
      legalComments: "none",
      banner: { js: banner(target.name) },
      logLevel: "warning",
    })
  }
}
console.log(`built loader ${version}, the classic glue and the Turnstile glue`)
