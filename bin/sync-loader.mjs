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
//       The sync also writes bin/sync-loader.lock.json: the sha384 of every
//       copied file the manifest does not hash (manifest.json itself, LICENSE,
//       src/*.ts, the checker and the shared mock), with the build's commit.
//   node bin/sync-loader.mjs --check [--remote]
//       CI: verify-vendored (pin, sha384 against the vendored manifest, MIT
//       banner, schema/<v>/, --complete), every script with its .debug.js
//       sibling, the TypeScript source present, Frontend::LOADER_VERSION
//       equal to the vendored version, and every other vendored file equal to
//       its sha384 in the lock (a file under assets/vendor/agecheck-js/ that
//       neither the manifest nor the lock hashes fails too). --remote also
//       compares the manifest's hashes with
//       https://verify.navexa.co/js/manifest.json (one read-only GET; release
//       time, once TAX-1873 is deployed).
//
// It never builds the loader, re-banners it or runs git in the tax repo:
// rebuilding here would change the sha384 that production serves. Never edit
// a vendored file by hand; sync a new version instead.
import { createHash } from "node:crypto"
import { copyFileSync, existsSync, mkdirSync, readFileSync, readdirSync, rmSync, statSync, writeFileSync } from "node:fs"
import path from "node:path"
import { fileURLToPath } from "node:url"

export const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..")
export const VENDOR_DIR = path.join(ROOT, "assets/vendor/agecheck-js")
export const LOCK = path.join(ROOT, "bin/sync-loader.lock.json")
/** The vendored copies outside assets/vendor/agecheck-js/, relative to the repository root. */
export const TOOLS = ["bin/agecheck-js/agecheck-js.mjs", "tests/mock-agecheck/hosted-engine.mjs"]
const CLI = path.join(ROOT, TOOLS[0])
const HOSTED_MOCK = path.join(ROOT, TOOLS[1])
const FRONTEND = path.join(ROOT, "includes/Frontend.php")
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

const sri = (file) => `sha384-${createHash("sha384").update(readFileSync(file)).digest("base64")}`

/**
 * The lock for the vendored copy in dir (tools under root): the sha384 of
 * every file in dir that verify-vendored does not hash against the manifest
 * (manifest.json, LICENSE, src/*.ts) and of the tools.
 */
export function lockFor(dir, version, commit, root = ROOT) {
  const vendor = {}
  for (const name of walk(dir)) if (!isManifestFile(name)) vendor[name] = sri(path.join(dir, name))
  const tools = {}
  for (const name of TOOLS) tools[name] = sri(path.join(root, name))
  return { package: "@navexa/agecheck-js", version, commit: commit ?? null, vendor, tools }
}

/** Whether verify-vendored hashes this vendored file against the manifest (the builds and schema/). */
function isManifestFile(name) {
  return /^agecheck(?:-gate)?-\d+\.\d+\.\d+(?:\.debug)?\.js$/.test(name) || name.startsWith("schema/")
}

/**
 * Problems with the files the manifest does not hash: each must equal its
 * sha384 in the lock, every file in dir must be hashed by the manifest or the
 * lock, and the lock must be for the vendored version and cover manifest.json,
 * LICENSE, the TypeScript source and the tools.
 */
export function lockProblems(dir, version, { lock = LOCK, root = ROOT } = {}) {
  const where = path.relative(ROOT, lock) || lock
  let data
  try {
    data = JSON.parse(readFileSync(lock, "utf8"))
  } catch (err) {
    return [`${where}: ${err.code === "ENOENT" ? "missing (run a sync)" : err.message}`]
  }
  const problems = []
  const vendor = data?.vendor && typeof data.vendor === "object" ? data.vendor : {}
  const tools = data?.tools && typeof data.tools === "object" ? data.tools : {}
  if (data?.version !== version) problems.push(`${where} is for ${data?.version ?? "no version"}, the vendored loader is ${version || "missing"}`)
  for (const name of ["manifest.json", "LICENSE"]) if (!(name in vendor)) problems.push(`${where} has no hash for ${name}`)
  if (!Object.keys(vendor).some((name) => /^src\/.+\.ts$/.test(name))) problems.push(`${where} has no hash for the TypeScript source (src/*.ts)`)
  for (const name of TOOLS) if (!(name in tools)) problems.push(`${where} has no hash for ${name}`)
  const compare = (base, entries) => {
    for (const [name, want] of Object.entries(entries)) {
      const file = path.join(base, name)
      if (!existsSync(file)) problems.push(`${name} is missing (${where} hashes it)`)
      else if (sri(file) !== want) problems.push(`${name}: sha384 is ${sri(file)}, ${where} says ${want}`)
    }
  }
  compare(dir, vendor)
  compare(root, tools)
  if (existsSync(dir)) {
    for (const name of walk(dir)) {
      if (!isManifestFile(name) && !(name in vendor)) problems.push(`${name}: neither the manifest nor ${where} hashes it (sync a version, never add a file by hand)`)
    }
  }
  return problems
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

/** verify-vendored plus extraProblems() and lockProblems(); returns {version, files, problems}. */
export async function check({ dir = VENDOR_DIR, remote = false, phpVersion = constantVersion(), lock = LOCK, root = ROOT } = {}) {
  const { verifyVendored } = await import(CLI)
  const result = await verifyVendored(dir, { version: phpVersion || undefined, complete: true, remote })
  result.problems.push(...extraProblems(dir, result.version, phpVersion))
  result.problems.push(...lockProblems(dir, result.version, { lock, root }))
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
  writeFileSync(LOCK, `${JSON.stringify(lockFor(VENDOR_DIR, version, entry.commit), null, 2)}\n`)

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
  console.log(`sync-loader: ok: @navexa/agecheck-js ${version}, ${files.length} files match the manifest${args.includes("--remote") ? " and the served manifest" : ""}, the rest match ${path.relative(ROOT, LOCK)}; Frontend::LOADER_VERSION matches`)
}
