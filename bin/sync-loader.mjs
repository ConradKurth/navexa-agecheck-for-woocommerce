#!/usr/bin/env node
// Vendors the shared Navexa AgeCheck loader (@navexa/agecheck-js, tax repo
// agecheck-js/, TAX-1873) into assets/vendor/agecheck-js/, byte for byte, and
// checks the copy with agecheck-js's own verify-vendored CLI (vendored here as
// bin/agecheck-js/agecheck-js.mjs so CI can run it without the private tax
// repository).
//
//   node bin/sync-loader.mjs --from <tax checkout> --version <x.y.z>
//       Copies, from <tax>/backend/internal/agecheck/http/loaderjs/ (the
//       committed build the AgeCheck host serves; agecheck-js/dist/ holds the
//       same bytes when built): agecheck-<v>.js (minified), agecheck-<v>.debug.js,
//       agecheck-gate-<v>.js, agecheck-gate-<v>.debug.js, schema/<v>/** and
//       manifest.json; from <tax>/agecheck-js/: src/*.ts without tests (the
//       loader's TypeScript source: wordpress.org Guideline 4, the tax repo is
//       private), LICENSE (MIT), bin/agecheck-js.mjs (the checker) and
//       mock/server.mjs (the shared mock of the hosted page, C1 v2 embed mode,
//       as tests/mock-agecheck/hosted-engine.mjs, which the plugin's Connect
//       mock mounts). Then rewrites Frontend::LOADER_VERSION and runs --check.
//   node bin/sync-loader.mjs --check [--remote]
//       CI: verify-vendored (pin, sha384 against the vendored manifest, MIT
//       banner, schema/<v>/, --complete), every script with its .debug.js
//       sibling, the TypeScript source present, and Frontend::LOADER_VERSION
//       equal to the vendored version. --remote also compares the hashes
//       with https://verify.navexa.co/js/manifest.json (one read-only GET;
//       release time, once TAX-1873 is deployed).
//
// It never builds the loader, re-banners it or runs git in the tax repo:
// rebuilding here would change the sha384 that production serves. Never edit
// a vendored file by hand; sync a new version instead.
import { copyFileSync, existsSync, mkdirSync, readFileSync, readdirSync, rmSync, statSync, writeFileSync } from "node:fs"
import path from "node:path"
import { fileURLToPath } from "node:url"

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..")
export const VENDOR_DIR = path.join(root, "assets/vendor/agecheck-js")
const CLI = path.join(root, "bin/agecheck-js/agecheck-js.mjs")
const HOSTED_MOCK = path.join(root, "tests/mock-agecheck/hosted-engine.mjs")
const FRONTEND = path.join(root, "includes/Frontend.php")
const CONSTANT = /const LOADER_VERSION = '(\d+\.\d+\.\d+)';/

function fail(message) {
  console.error(`sync-loader: ${message}`)
  process.exit(1)
}

function walk(dir, prefix = "") {
  const out = []
  for (const name of readdirSync(path.join(dir, prefix)).sort()) {
    const rel = prefix ? `${prefix}/${name}` : name
    if (statSync(path.join(dir, rel)).isDirectory()) out.push(...walk(dir, rel))
    else out.push(rel)
  }
  return out
}

function copy(from, to) {
  mkdirSync(path.dirname(to), { recursive: true })
  copyFileSync(from, to)
}

/** The PHP constant's value, or "". */
export function constantVersion(text = readFileSync(FRONTEND, "utf8")) {
  return CONSTANT.exec(text)?.[1] ?? ""
}

/** Problems with the vendored copy beyond what verify-vendored checks. */
export function extraProblems(dir, version, phpVersion) {
  const problems = []
  if (phpVersion !== version) problems.push(`Frontend::LOADER_VERSION is ${phpVersion || "missing"}, the vendored loader is ${version || "missing"}`)
  for (const name of readdirSync(dir)) {
    const m = /^(agecheck(?:-gate)?-\d+\.\d+\.\d+)\.js$/.exec(name)
    if (m && !existsSync(path.join(dir, `${m[1]}.debug.js`))) problems.push(`${name} has no readable ${m[1]}.debug.js next to it (Guideline 4)`)
  }
  for (const name of [`agecheck-${version}.js`, `agecheck-gate-${version}.js`, "LICENSE", "src/index.ts", "src/verify.ts", "src/gate.ts"]) {
    if (!existsSync(path.join(dir, name))) problems.push(`${name} is missing`)
  }
  if (existsSync(path.join(dir, "src"))) {
    for (const name of walk(dir, "src")) if (/\.test\.ts$/.test(name)) problems.push(`src/${name}: tests are not vendored`)
  }
  return problems
}

/** verify-vendored plus extraProblems(); returns {version, files, problems}. */
export async function check({ dir = VENDOR_DIR, remote = false, phpVersion = constantVersion() } = {}) {
  const { verifyVendored } = await import(CLI)
  const result = await verifyVendored(dir, { version: phpVersion || undefined, complete: true, remote })
  result.problems.push(...extraProblems(dir, result.version, phpVersion))
  return result
}

async function sync(from, version) {
  const build = [path.join(from, "backend/internal/agecheck/http/loaderjs"), path.join(from, "agecheck-js/dist")].find((d) => existsSync(path.join(d, "manifest.json")))
  const source = path.join(from, "agecheck-js")
  if (!build) fail(`${from}: no loader build (backend/internal/agecheck/http/loaderjs/ or agecheck-js/dist/ with manifest.json)`)
  const pkg = JSON.parse(readFileSync(path.join(source, "package.json"), "utf8"))
  if (pkg.name !== "@navexa/agecheck-js") fail(`${source}/package.json is not @navexa/agecheck-js`)
  if (pkg.version !== version) fail(`${source} is @navexa/agecheck-js ${pkg.version}, not ${version} (an exact pin, never a range)`)
  const manifest = JSON.parse(readFileSync(path.join(build, "manifest.json"), "utf8"))
  const entry = manifest?.versions?.[version]
  if (!entry?.files) fail(`${build}/manifest.json has no entry for ${version}`)

  rmSync(VENDOR_DIR, { recursive: true, force: true })
  mkdirSync(VENDOR_DIR, { recursive: true })
  for (const name of Object.keys(entry.files)) {
    if (/^agecheck(-gate)?-\d+\.\d+\.\d+(\.debug)?\.js$/.test(name) || name.startsWith(`schema/${version}/`)) copy(path.join(build, name), path.join(VENDOR_DIR, name))
  }
  copy(path.join(build, "manifest.json"), path.join(VENDOR_DIR, "manifest.json"))
  copy(path.join(source, "LICENSE"), path.join(VENDOR_DIR, "LICENSE"))
  for (const name of walk(path.join(source, "src"))) {
    if (name.endsWith(".ts") && !name.endsWith(".test.ts")) copy(path.join(source, "src", name), path.join(VENDOR_DIR, "src", name))
  }
  copy(path.join(source, "bin/agecheck-js.mjs"), CLI)
  copy(path.join(source, "mock/server.mjs"), HOSTED_MOCK)

  const php = readFileSync(FRONTEND, "utf8")
  if (!CONSTANT.test(php)) fail("includes/Frontend.php has no LOADER_VERSION constant")
  writeFileSync(FRONTEND, php.replace(CONSTANT, `const LOADER_VERSION = '${version}';`))
  console.log(`sync-loader: vendored @navexa/agecheck-js ${version} (commit ${entry.commit ?? "unknown"}) from ${path.relative(from, build)}`)
}

const invoked = process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)
if (invoked) {
  const args = process.argv.slice(2)
  const value = (name) => {
    const i = args.indexOf(name)
    return i >= 0 ? args[i + 1] : undefined
  }
  if (!args.includes("--check")) {
    const from = value("--from")
    const version = value("--version")
    if (!from || !version || !/^\d+\.\d+\.\d+$/.test(version)) fail("usage: node bin/sync-loader.mjs --from <tax checkout> --version <x.y.z> | --check [--remote]")
    await sync(path.resolve(from), version)
  }
  const { version, files, problems } = await check({ remote: args.includes("--remote") })
  for (const p of problems) console.error(`FAIL ${p}`)
  if (problems.length) process.exit(1)
  console.log(`sync-loader: ok: @navexa/agecheck-js ${version}, ${files.length} files match the manifest${args.includes("--remote") ? " and the served manifest" : ""}; Frontend::LOADER_VERSION matches`)
}
