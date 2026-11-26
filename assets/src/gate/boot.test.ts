import { test } from "node:test"
import assert from "node:assert/strict"
import { KEY, LEGACY_KEY, migrateLegacy, migrated, readConfig, type StorageLike } from "./boot.ts"

const DAY = 86_400_000
const NOW = 1_781_517_600_000

function store(initial: Record<string, string> = {}): StorageLike & { data: Map<string, string> } {
  const data = new Map(Object.entries(initial))
  return {
    data,
    getItem: (k) => (data.has(k) ? (data.get(k) as string) : null),
    setItem: (k, v) => void data.set(k, v),
  }
}
const legacy = (age: number, at: number) => JSON.stringify({ v: 1, age, at })

test("readConfig accepts only {v: 2, options: {...}}", () => {
  assert.deepEqual(readConfig('{"v":2,"options":{"minAge":21}}'), { v: 2, options: { minAge: 21 } })
  for (const bad of [null, "", "nope", '{"v":1,"options":{}}', '{"v":2}', '{"v":2,"options":[]}']) assert.equal(readConfig(bad), null, String(bad))
})

test("a valid 1.0.x answer moves to the loader's key as {passedAt, minAge}", () => {
  assert.equal(migrated(legacy(21, NOW - DAY), 21, 30, NOW), JSON.stringify({ passedAt: NOW - DAY, minAge: 21 }))
  const local = store({ [LEGACY_KEY]: legacy(21, NOW - DAY) })
  assert.equal(migrateLegacy({ minAge: 21, rememberDays: 30 }, local, store(), NOW), true)
  assert.equal(local.data.get(KEY), JSON.stringify({ passedAt: NOW - DAY, minAge: 21 }))
})

test("not migrated: expired, given for a lower age, in the future, malformed, or already migrated", () => {
  assert.equal(migrated(legacy(21, NOW - 30 * DAY), 21, 30, NOW), null, "30 days old at 30 days")
  assert.equal(migrated(legacy(18, NOW), 21, 30, NOW), null, "18 does not cover 21")
  assert.equal(migrated(legacy(21, NOW + 1), 21, 30, NOW), null)
  assert.equal(migrated("{oops", 21, 30, NOW), null)
  assert.equal(migrated(JSON.stringify({ v: 2, age: 21, at: NOW }), 21, 30, NOW), null)
  const local = store({ [LEGACY_KEY]: legacy(21, NOW), [KEY]: "kept" })
  assert.equal(migrateLegacy({ minAge: 21, rememberDays: 30 }, local, null, NOW), false)
  assert.equal(local.data.get(KEY), "kept")
})

test("0 days: only sessionStorage is read and written, at any age of the answer", () => {
  const local = store({ [LEGACY_KEY]: legacy(21, NOW - 400 * DAY) })
  const session = store({ [LEGACY_KEY]: legacy(21, NOW - 400 * DAY) })
  assert.equal(migrateLegacy({ minAge: 21, rememberDays: 0 }, local, session, NOW), true)
  assert.equal(local.data.has(KEY), false, "a localStorage answer never counts at 0 days")
  assert.ok(session.data.has(KEY))
})

test("storage that throws is skipped, never thrown", () => {
  const broken: StorageLike = {
    getItem: () => {
      throw new Error("SecurityError")
    },
    setItem: () => {
      throw new Error("SecurityError")
    },
  }
  assert.equal(migrateLegacy({ minAge: 21, rememberDays: 30 }, broken, broken, NOW), false)
})
