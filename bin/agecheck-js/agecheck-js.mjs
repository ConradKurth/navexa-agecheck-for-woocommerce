#!/usr/bin/env node
// agecheck-js command line (TAX-1873, brief C2 v2 B7). Zero dependencies.
//
//   agecheck-js verify-vendored <dir> [--version <x.y.z>] [--manifest <file>]
//                               [--complete] [--remote [<manifest url>]]
//
// Checks a vendored copy of the loader (WooCommerce assets/vendor/agecheck-js,
// the Shopify theme extension, a Medusa storefront) before it ships:
//   - pin: every agecheck[-gate]-<x.y.z>[.debug].js in <dir> is one version,
//     equal to --version when given; any other agecheck*.js is refused;
//   - integrity: each file's sha384 equals the manifest's entry for it
//     (<dir>/manifest.json, or --manifest; shape {latest, versions: {<v>:
//     {commit, files: {<file>: "sha384-..."}}}});
//   - banner: each script starts with `/*! @navexa/agecheck-js v<x.y.z> | MIT | `;
//   - schema: when <dir>/schema/ exists (the GateOptions schema and vectors,
//     copied as https://verify.navexa.co/js/schema/<x.y.z>/ serves them),
//     every file under it is schema/<that version>/... with the manifest's
//     sha384;
//   - --complete: every script the manifest lists for that version is there,
//     and with <dir>/schema/ every schema file too;
//   - --remote: the same hashes in the served manifest (default
//     https://verify.navexa.co/js/manifest.json; one read-only GET), proving
//     the copy is byte-equal to what production serves.
// Exit 0 when all hold, 1 otherwise (each problem on stderr), 2 on usage.
// Run from a tax checkout: node agecheck-js/bin/agecheck-js.mjs verify-vendored <dir>
// (or npx --package <path to agecheck-js> agecheck-js verify-vendored <dir>).
import { createHash } from "node:crypto"
import { existsSync, readFileSync, readdirSync, realpathSync, statSync } from "node:fs"
import path from "node:path"
import { fileURLToPath } from "node:url"

export const DEFAULT_REMOTE = "https://verify.navexa.co/js/manifest.json"
const FILE = /^agecheck(?:-gate)?-(\d+\.\d+\.\d+)(?:\.debug)?\.js$/

/** Every file under dir, as "/"-separated paths relative to it, sorted. */
function walk(dir, prefix = "") {
  const out = []
  for (const name of readdirSync(path.join(dir, prefix)).sort()) {
    const rel = prefix ? `${prefix}/${name}` : name
    if (statSync(path.join(dir, rel)).isDirectory()) out.push(...walk(dir, rel))
    else out.push(rel)
  }
  return out
}

const sri = (bytes) => `sha384-${createHash("sha384").update(bytes).digest("base64")}`

function parseManifest(text, where) {
  const m = JSON.parse(text)
  if (!m || typeof m !== "object" || typeof m.latest !== "string" || !m.versions || typeof m.versions !== "object") {
    throw new Error(`${where}: not a loader manifest ({latest, versions})`)
  }
  return m
}

/**
 * Verifies dir; returns {version, files: [{name, sri}], problems: [string]}.
 * opts: {version?, manifest? (path), complete?, remote? (URL string), fetch?}.
 */
export async function verifyVendored(dir, opts = {}) {
  const problems = []
  const names = readdirSync(dir).filter((name) => name.startsWith("agecheck") && name.endsWith(".js")).sort()
  const versions = new Set()
  const loader = []
  for (const name of names) {
    const m = FILE.exec(name)
    if (!m) problems.push(`${name}: not a loader file name (agecheck[-gate]-<x.y.z>[.debug].js)`)
    else {
      versions.add(m[1])
      loader.push(name)
    }
  }
  if (loader.length === 0) problems.push(`${dir}: no agecheck-<x.y.z>.js or agecheck-gate-<x.y.z>.js`)
  if (versions.size > 1) problems.push(`more than one version vendored: ${[...versions].join(", ")}`)
  const version = [...versions][0] ?? ""
  if (opts.version && version && opts.version !== version) problems.push(`pinned ${opts.version}, vendored ${version}`)

  const manifestPath = opts.manifest ?? path.join(dir, "manifest.json")
  let entry = null
  try {
    const manifest = parseManifest(readFileSync(manifestPath, "utf8"), manifestPath)
    entry = manifest.versions[version] ?? null
    if (version && !entry) problems.push(`${manifestPath} has no entry for ${version}`)
  } catch (err) {
    problems.push(`${manifestPath}: ${err.message}`)
  }

  const schema = []
  for (const name of existsSync(path.join(dir, "schema")) ? walk(dir, "schema") : []) {
    if (version && name.startsWith(`schema/${version}/`)) schema.push(name)
    else problems.push(`${name}: not under schema/${version || "<the vendored version>"}/`)
  }
  const files = []
  for (const name of [...loader, ...schema]) {
    const bytes = readFileSync(path.join(dir, name))
    const hash = sri(bytes)
    files.push({ name, sri: hash })
    const want = entry?.files?.[name]
    if (entry && !want) problems.push(`${name}: not in the manifest's ${version} entry`)
    else if (want && want !== hash) problems.push(`${name}: sha384 is ${hash}, the manifest says ${want}`)
    if (!FILE.test(name)) continue
    const head = bytes.subarray(0, 300).toString("utf8")
    const banner = /^\/\*! @navexa\/agecheck-js v(\d+\.\d+\.\d+) \| ([A-Za-z0-9.+-]+) \| /.exec(head)
    if (!banner) problems.push(`${name}: no @navexa/agecheck-js banner`)
    else {
      if (banner[1] !== version) problems.push(`${name}: banner says v${banner[1]}`)
      if (banner[2] !== "MIT") problems.push(`${name}: banner license ${banner[2]}, expected MIT`)
    }
  }
  if (opts.complete && entry?.files) {
    for (const name of Object.keys(entry.files)) {
      const wanted = FILE.test(name) || (schema.length > 0 && name.startsWith("schema/"))
      if (wanted && !loader.includes(name) && !schema.includes(name)) problems.push(`${name}: listed for ${version} but not vendored`)
    }
  }

  if (opts.remote && version) {
    const url = opts.remote === true ? DEFAULT_REMOTE : opts.remote
    try {
      const res = await (opts.fetch ?? fetch)(url, { headers: { Accept: "application/json" } })
      if (!res.ok) throw new Error(`HTTP ${res.status}`)
      const served = parseManifest(await res.text(), url).versions[version]
      if (!served) problems.push(`${url} does not serve ${version}`)
      else for (const f of files) if (served.files?.[f.name] !== f.sri) problems.push(`${f.name}: ${url} says ${served.files?.[f.name] ?? "nothing"}`)
    } catch (err) {
      problems.push(`${url}: ${err.message}`)
    }
  }
  return { version, files, problems }
}

function usage(message) {
  if (message) console.error(message)
  console.error("usage: agecheck-js verify-vendored <dir> [--version <x.y.z>] [--manifest <file>] [--complete] [--remote [<manifest url>]]")
  return 2
}

export async function main(argv) {
  const [command, ...rest] = argv
  if (command !== "verify-vendored") return usage(command ? `unknown command ${command}` : "")
  const opts = {}
  let dir = ""
  for (let i = 0; i < rest.length; i++) {
    const arg = rest[i]
    if (arg === "--version") opts.version = rest[++i]
    else if (arg === "--manifest") opts.manifest = rest[++i]
    else if (arg === "--complete") opts.complete = true
    else if (arg === "--remote") opts.remote = rest[i + 1] && !rest[i + 1].startsWith("--") ? rest[++i] : true
    else if (arg.startsWith("--")) return usage(`unknown option ${arg}`)
    else if (!dir) dir = arg
    else return usage(`unexpected ${arg}`)
  }
  if (!dir || (opts.version !== undefined && !/^\d+\.\d+\.\d+$/.test(opts.version))) return usage("")
  const { version, files, problems } = await verifyVendored(dir, opts)
  for (const p of problems) console.error(`FAIL ${p}`)
  if (problems.length) return 1
  for (const f of files) console.log(`ok   ${f.name} ${f.sri}`)
  console.log(`verified ${files.length} file(s) of @navexa/agecheck-js v${version}${opts.remote ? " against the served manifest" : ""}`)
  return 0
}

const invoked = process.argv[1] && realpathSync(process.argv[1]) === fileURLToPath(import.meta.url)
if (invoked) process.exitCode = await main(process.argv.slice(2))
