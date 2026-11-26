// node --test bin/sync-loader.test.mjs: the vendored-loader check CI runs.
import { test } from "node:test"
import assert from "node:assert/strict"
import { cpSync, mkdtempSync, readFileSync, rmSync, writeFileSync, appendFileSync } from "node:fs"
import os from "node:os"
import path from "node:path"
import { VENDOR_DIR, check, constantVersion, extraProblems } from "./sync-loader.mjs"

function fixture() {
  const dir = mkdtempSync(path.join(os.tmpdir(), "agecheck-vendor-"))
  cpSync(VENDOR_DIR, dir, { recursive: true })
  return dir
}

test("the committed vendored loader passes: pin, sha384, banner, schema, source, PHP constant", async () => {
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
