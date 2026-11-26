// node --test bin/sync-loader.test.mjs: the vendored-loader check CI runs.
import { test } from "node:test"
import assert from "node:assert/strict"
import { cpSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync, appendFileSync } from "node:fs"
import os from "node:os"
import path from "node:path"
import { LOCK, ROOT, TOOLS, VENDOR_DIR, check, constantVersion, extraProblems, lockFor, lockProblems } from "./sync-loader.mjs"

function fixture() {
  const dir = mkdtempSync(path.join(os.tmpdir(), "agecheck-vendor-"))
  cpSync(VENDOR_DIR, dir, { recursive: true })
  return dir
}

test("the committed vendored loader passes: pin, sha384, banner, schema, source, lock, PHP constant", async () => {
  const { version, problems } = await check()
  assert.deepEqual(problems, [])
  assert.equal(version, constantVersion())
})

test("a tampered byte fails against the manifest", async () => {
  const dir = fixture()
  try {
    appendFileSync(path.join(dir, `agecheck-${constantVersion()}.js`), " ")
    const { problems } = await check({ dir })
    assert.ok(problems.some((p) => /sha384/.test(p)), problems.join("\n"))
  } finally {
    rmSync(dir, { recursive: true, force: true })
  }
})

test("Frontend::LOADER_VERSION must equal the vendored version", async () => {
  const { problems } = await check({ phpVersion: "9.9.9" })
  assert.ok(problems.some((p) => /LOADER_VERSION is 9\.9\.9/.test(p)), problems.join("\n"))
  assert.equal(constantVersion("const LOADER_VERSION = '1.2.3';"), "1.2.3")
})

test("a script without its readable .debug.js sibling, missing source, or a vendored test fails", () => {
  const dir = fixture()
  try {
    const v = constantVersion()
    rmSync(path.join(dir, `agecheck-gate-${v}.debug.js`))
    rmSync(path.join(dir, "src/verify.ts"))
    writeFileSync(path.join(dir, "src/verify.test.ts"), "")
    const problems = extraProblems(dir, v, v)
    assert.ok(problems.some((p) => p.includes(".debug.js")), problems.join("\n"))
    assert.ok(problems.some((p) => p.includes("src/verify.ts is missing")), problems.join("\n"))
    assert.ok(problems.some((p) => p.includes("tests are not vendored")), problems.join("\n"))
  } finally {
    rmSync(dir, { recursive: true, force: true })
  }
})

test("the vendored manifest names the commit the build came from", () => {
  const manifest = JSON.parse(readFileSync(path.join(VENDOR_DIR, "manifest.json"), "utf8"))
  assert.match(manifest.versions[constantVersion()].commit, /^[0-9a-f]{40}$/)
})

/** A copy of the tools (the checker and the shared mock) under a temporary root. */
function toolsRoot() {
  const root = mkdtempSync(path.join(os.tmpdir(), "agecheck-tools-"))
  for (const name of TOOLS) {
    mkdirSync(path.dirname(path.join(root, name)), { recursive: true })
    cpSync(path.join(ROOT, name), path.join(root, name))
  }
  return root
}

test("the lock hashes manifest.json, LICENSE, every source file and both tools, and nothing the manifest hashes", () => {
  const lock = JSON.parse(readFileSync(LOCK, "utf8"))
  const manifest = JSON.parse(readFileSync(path.join(VENDOR_DIR, "manifest.json"), "utf8"))
  const v = constantVersion()
  assert.equal(lock.version, v)
  assert.equal(lock.commit, manifest.versions[v].commit, "the build's commit, for provenance")
  assert.deepEqual(Object.keys(lock.tools).sort(), [...TOOLS].sort())
  assert.ok(lock.vendor.LICENSE && lock.vendor["manifest.json"] && lock.vendor["src/verify.ts"])
  for (const name of Object.keys(manifest.versions[v].files)) assert.ok(!(name in lock.vendor), `${name} is the manifest's to hash`)
  assert.deepEqual(lockFor(VENDOR_DIR, v, lock.commit), lock, "the committed lock is what a sync writes")
})

for (const [what, file] of [
  ["the TypeScript source", "src/verify.ts"],
  ["LICENSE", "LICENSE"],
  ["the vendored manifest", "manifest.json"],
]) {
  test(`a changed byte in ${what} fails against the lock`, async () => {
    const dir = fixture()
    try {
      appendFileSync(path.join(dir, file), " ")
      const { problems } = await check({ dir })
      assert.ok(problems.some((p) => p.startsWith(`${file}: sha384 is`) && p.includes("sync-loader.lock.json says")), problems.join("\n"))
    } finally {
      rmSync(dir, { recursive: true, force: true })
    }
  })
}

test("a changed byte in the checker or the shared mock fails against the lock", () => {
  const v = constantVersion()
  for (const name of TOOLS) {
    const root = toolsRoot()
    try {
      assert.deepEqual(lockProblems(VENDOR_DIR, v, { root }), [], "an unchanged copy passes")
      appendFileSync(path.join(root, name), "\n")
      const problems = lockProblems(VENDOR_DIR, v, { root })
      assert.ok(problems.some((p) => p.startsWith(`${name}: sha384 is`)), problems.join("\n"))
    } finally {
      rmSync(root, { recursive: true, force: true })
    }
  }
})

test("a file added by hand, a removed source file, or a lock for another version fails", () => {
  const dir = fixture()
  const tmp = mkdtempSync(path.join(os.tmpdir(), "agecheck-lock-"))
  try {
    const v = constantVersion()
    writeFileSync(path.join(dir, "src/extra.ts"), "export {}\n")
    writeFileSync(path.join(dir, "NOTES.md"), "hand-written\n")
    rmSync(path.join(dir, "src/age.ts"))
    let problems = lockProblems(dir, v)
    assert.ok(problems.some((p) => p.startsWith("src/extra.ts: neither the manifest nor")), problems.join("\n"))
    assert.ok(problems.some((p) => p.startsWith("NOTES.md: neither the manifest nor")), problems.join("\n"))
    assert.ok(problems.some((p) => p.startsWith("src/age.ts is missing")), problems.join("\n"))

    const lock = JSON.parse(readFileSync(LOCK, "utf8"))
    const other = path.join(tmp, "lock.json")
    writeFileSync(other, JSON.stringify({ ...lock, version: "9.9.9", tools: {}, vendor: { "src/version.ts": lock.vendor["src/version.ts"] } }))
    problems = lockProblems(VENDOR_DIR, v, { lock: other })
    for (const want of ["is for 9.9.9", "no hash for manifest.json", "no hash for LICENSE", `no hash for ${TOOLS[0]}`, "LICENSE: neither the manifest nor"]) {
      assert.ok(problems.some((p) => p.includes(want)), `${want}\n${problems.join("\n")}`)
    }
    assert.match(lockProblems(VENDOR_DIR, v, { lock: path.join(tmp, "none.json") })[0], /missing \(run a sync\)/)
  } finally {
    rmSync(dir, { recursive: true, force: true })
    rmSync(tmp, { recursive: true, force: true })
  }
})
