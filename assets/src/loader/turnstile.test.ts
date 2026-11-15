// Turnstile glue tests: node --test, a fake window.turnstile.
import { test } from "node:test"
import assert from "node:assert/strict"
import { createTurnstile, READY_MAX_AGE_MS, type TurnstileApi, type TurnstileRenderOptions } from "./turnstile.ts"

function fakeApi(behaviour: "token" | "error" | "silent" | "empty" | "interactive" = "token") {
  const calls: string[] = []
  let options: TurnstileRenderOptions | null = null
  let n = 0
  const api: TurnstileApi = {
    render(container, opts) {
      calls.push(`render:${String((container as { name: string }).name)}`)
      options = opts
      return `w${++n}`
    },
    execute(widget) {
      calls.push(`execute:${String(widget)}`)
      if (!options) return
      if (behaviour === "token") options.callback?.(`token-${calls.length}`)
      if (behaviour === "empty") options.callback?.("")
      if (behaviour === "error") options["error-callback"]?.("110200")
      if (behaviour === "interactive") options["before-interactive-callback"]?.()
    },
    reset(widget) {
      calls.push(`reset:${widget}`)
    },
    remove(widget) {
      calls.push(`remove:${widget}`)
    },
  }
  return { api, calls, options: () => options }
}

function fakeTimers() {
  const timers: Array<{ fn: () => void; ms: number; id: number }> = []
  let id = 0
  return {
    timers,
    setTimeout: (fn: () => void, ms: number) => {
      timers.push({ fn, ms, id: ++id })
      return id
    },
    clearTimeout: (handle: unknown) => {
      const i = timers.findIndex((t) => t.id === handle)
      if (i >= 0) timers.splice(i, 1)
    },
    runAll() {
      while (timers.length) timers.shift()!.fn()
    },
  }
}

const config = { siteKey: "1x00000000000000000000AA", action: "navexa_agecheck_start" }

test("renders an execute-mode, interaction-only widget with the site key and action, and returns its token", async () => {
  const fake = fakeApi()
  const timers = fakeTimers()
  const client = createTurnstile({ ...config, env: { api: () => fake.api, setTimeout: timers.setTimeout, clearTimeout: timers.clearTimeout } })
  const token = await client.token({ name: "box" })
  assert.equal(token, "token-2")
  assert.deepEqual(fake.calls, ["render:box", "execute:w1"])
  const options = fake.options()!
  assert.equal(options.sitekey, config.siteKey)
  assert.equal(options.action, config.action)
  assert.equal(options.execution, "execute")
  assert.equal(options.appearance, "interaction-only")
  assert.equal(timers.timers.length, 0, "the token timeout is cleared")
})

test("each start resets the widget for a fresh single-use token; a replaced container gets a new widget", async () => {
  const fake = fakeApi()
  const timers = fakeTimers()
  const client = createTurnstile({ ...config, env: { api: () => fake.api, setTimeout: timers.setTimeout, clearTimeout: timers.clearTimeout } })
  const box = { name: "box" }
  await client.token(box)
  await client.token(box)
  await client.token({ name: "new-box" })
  assert.deepEqual(fake.calls, ["render:box", "execute:w1", "reset:w1", "execute:w1", "remove:w1", "render:new-box", "execute:w2"])
})

test("errors, empty tokens, no container and no site key give null", async () => {
  for (const behaviour of ["error", "empty"] as const) {
    const fake = fakeApi(behaviour)
    const timers = fakeTimers()
    const client = createTurnstile({ ...config, env: { api: () => fake.api, setTimeout: timers.setTimeout, clearTimeout: timers.clearTimeout } })
    assert.equal(await client.token({ name: "box" }), null, behaviour)
  }
  const fake = fakeApi()
  const client = createTurnstile({ ...config, env: { api: () => fake.api } })
  assert.equal(await client.token(null), null)
  assert.equal(await createTurnstile({ siteKey: "", action: "a", env: { api: () => fake.api } }).token({ name: "box" }), null)
  assert.deepEqual(fake.calls, [], "nothing rendered without a container or a site key")
})

test("a widget that never answers times out to null", async () => {
  const fake = fakeApi("silent")
  const timers = fakeTimers()
  const client = createTurnstile({ ...config, tokenTimeoutMs: 5000, env: { api: () => fake.api, setTimeout: timers.setTimeout, clearTimeout: timers.clearTimeout } })
  const pending = client.token({ name: "box" })
  await new Promise((resolve) => setImmediate(resolve))
  assert.equal(timers.timers[0].ms, 5000)
  timers.runAll()
  assert.equal(await pending, null)
})

test("Cloudflare's script never loading gives null after the load timeout", async () => {
  const timers = fakeTimers()
  const client = createTurnstile({ ...config, loadTimeoutMs: 300, env: { api: () => undefined, setTimeout: timers.setTimeout, clearTimeout: timers.clearTimeout } })
  const pending = client.token({ name: "box" })
  timers.runAll()
  assert.equal(await pending, null)
})

test("waits for Cloudflare's script to load", async () => {
  const fake = fakeApi()
  const timers = fakeTimers()
  let loaded = false
  const client = createTurnstile({ ...config, env: { api: () => (loaded ? fake.api : undefined), setTimeout: timers.setTimeout, clearTimeout: timers.clearTimeout } })
  const pending = client.token({ name: "box" })
  assert.equal(timers.timers.length, 1)
  loaded = true
  timers.timers.shift()!.fn()
  assert.equal(await pending, "token-2")
})

test("a render that throws gives null", async () => {
  const fake = fakeApi()
  fake.api.render = () => {
    throw new Error("bad site key")
  }
  const timers = fakeTimers()
  const client = createTurnstile({ ...config, env: { api: () => fake.api, setTimeout: timers.setTimeout, clearTimeout: timers.clearTimeout } })
  assert.equal(await client.token({ name: "box" }), null)
})

test("an interactive challenge gives up the start at once, with no timeout, and its token serves the next start", async () => {
  const fake = fakeApi("interactive")
  const timers = fakeTimers()
  let now = 1000
  const client = createTurnstile({ ...config, env: { api: () => fake.api, setTimeout: timers.setTimeout, clearTimeout: timers.clearTimeout, now: () => now } })
  const box = { name: "box" }
  assert.equal(client.challenging(), false)
  assert.equal(await client.token(box), null, "the start does not wait behind the popup")
  assert.equal(client.challenging(), true)
  assert.equal(timers.timers.length, 0, "no timeout runs while the challenge shows")

  // Selected again before solving it: the shown challenge is kept, not reset.
  assert.equal(await client.token(box), null)
  assert.deepEqual(fake.calls, ["render:box", "execute:w1"])

  // The shopper solves it; the next start takes that token without a new challenge.
  fake.options()!.callback?.("solved-token")
  assert.equal(client.challenging(), false)
  now += 60000
  assert.equal(await client.token({ name: "replaced-box" }), "solved-token", "also after the classic checkout replaced the container")
  assert.deepEqual(fake.calls, ["render:box", "execute:w1"], "nothing rendered or executed for it")

  // Single use: the start after that runs the widget again.
  const next = client.token(box)
  await new Promise((resolve) => setImmediate(resolve))
  assert.deepEqual(fake.calls.slice(2), ["reset:w1", "execute:w1"])
  assert.equal(await next, null)
})

test("a kept token older than READY_MAX_AGE_MS is not used", async () => {
  const fake = fakeApi("interactive")
  const timers = fakeTimers()
  let now = 0
  const client = createTurnstile({ ...config, env: { api: () => fake.api, setTimeout: timers.setTimeout, clearTimeout: timers.clearTimeout, now: () => now } })
  const box = { name: "box" }
  await client.token(box)
  fake.options()!.callback?.("old-token")
  now += READY_MAX_AGE_MS
  const pending = client.token(box)
  await new Promise((resolve) => setImmediate(resolve))
  assert.deepEqual(fake.calls, ["render:box", "execute:w1", "reset:w1", "execute:w1"])
  assert.equal(await pending, null)
})

test("an expired kept token is dropped", async () => {
  const fake = fakeApi("interactive")
  const timers = fakeTimers()
  const client = createTurnstile({ ...config, env: { api: () => fake.api, setTimeout: timers.setTimeout, clearTimeout: timers.clearTimeout } })
  const box = { name: "box" }
  await client.token(box)
  fake.options()!.callback?.("token")
  fake.options()!["expired-callback"]?.()
  const pending = client.token(box)
  await new Promise((resolve) => setImmediate(resolve))
  assert.equal(fake.calls.length, 4, "a new run, not the expired token")
  assert.equal(await pending, null)
})
