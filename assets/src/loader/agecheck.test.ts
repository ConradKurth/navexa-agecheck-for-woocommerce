// Loader tests: node --test (Node strips the types). No DOM: the loader takes
// its browser through LoaderEnv, faked here.
import { test } from "node:test"
import assert from "node:assert/strict"
import { readFileSync } from "node:fs"
import { AgeCheckLoader, parseHostedUrl, REDIRECT_STORAGE_KEY, type LoaderEnv, type LoaderState, type StoreAnswer } from "./agecheck.ts"

const HOSTED = "https://verify.navexa.co/v/eyJ2IjoxfQ.c2ln"

type FetchReply = { status: number; body?: unknown; headers?: Record<string, string> } | Error

function fakeEnv(opts: { popup?: boolean } = {}) {
  let clock = 1_000_000
  const timers: Array<{ at: number; fn: () => void; id: number }> = []
  let nextId = 1
  const listeners = new Map<string, Array<(e: any) => void>>()
  const fetches: Array<{ url: string; init: any }> = []
  const replies: FetchReply[] = []
  const storage = new Map<string, string>()
  const assigned: string[] = []
  const opened: Array<{ url: string; name: string; features: string }> = []
  const channel = { onmessage: null as null | ((e: { data: unknown }) => void), closed: false, close() { this.closed = true } }
  const popup = {
    closed: false,
    url: "",
    close() { this.closed = true },
    focus() {},
    location: { replace: (url: string) => { popup.url = url } },
    document: { title: "", body: { textContent: "" as string | null } },
  }
  const env: LoaderEnv = {
    open: (url, name, features) => {
      opened.push({ url, name, features })
      return opts.popup === false ? null : popup
    },
    assign: (url) => assigned.push(url),
    fetch: async (url, init) => {
      fetches.push({ url, init })
      const reply = replies.shift() ?? { status: 200, body: { status: "needs_doc" } }
      if (reply instanceof Error) throw reply
      return {
        status: reply.status,
        headers: { get: (name: string) => reply.headers?.[name] ?? reply.headers?.[name.toLowerCase()] ?? null },
        json: async () => reply.body,
      }
    },
    setTimeout: (fn, ms) => {
      const id = nextId++
      timers.push({ at: clock + ms, fn, id })
      return id
    },
    clearTimeout: (id) => {
      const i = timers.findIndex((t) => t.id === id)
      if (i >= 0) timers.splice(i, 1)
    },
    now: () => clock,
    addEventListener: (type, fn) => listeners.set(type, [...(listeners.get(type) ?? []), fn]),
    removeEventListener: (type, fn) => listeners.set(type, (listeners.get(type) ?? []).filter((l) => l !== fn)),
    visible: () => true,
    broadcastChannel: () => channel,
    sessionStorage: () => ({ setItem: (k: string, v: string) => void storage.set(k, v) }),
  }
  return {
    env,
    popup,
    channel,
    fetches,
    replies,
    storage,
    assigned,
    opened,
    pendingTimers: () => timers.length,
    nextDelay: () => (timers.length ? Math.min(...timers.map((t) => t.at)) - clock : null),
    dispatch: (type: string, event: unknown) => (listeners.get(type) ?? []).forEach((l) => l(event)),
    /** Advances the clock, running due timers, and lets promises settle. */
    async tick(ms: number) {
      const until = clock + ms
      for (;;) {
        timers.sort((a, b) => a.at - b.at)
        const due = timers[0]
        if (!due || due.at > until) break
        timers.shift()
        clock = due.at
        due.fn()
        await settle()
      }
      clock = until
      await settle()
    },
  }
}

const settle = async () => {
  for (let i = 0; i < 10; i++) await Promise.resolve()
  await new Promise((r) => setImmediate(r))
}

function harness(answers: Array<StoreAnswer | Error>, opts: { popup?: boolean; status?: LoaderState["status"] } = {}) {
  const f = fakeEnv(opts)
  const calls: string[] = []
  const states: LoaderState[] = []
  const loader = new AgeCheckLoader({
    transport: async (action) => {
      calls.push(action)
      const next = answers.shift()
      if (!next) throw new Error("no more answers")
      if (next instanceof Error) throw next
      return next
    },
    onChange: (s) => states.push(s),
    status: opts.status,
    env: f.env,
  })
  return { f, loader, calls, states, last: () => states[states.length - 1] }
}

test("verify opens the popup before asking the store, then sends it to the hosted URL", async () => {
  const h = harness([{ v: 1, status: "pending", hosted_url: HOSTED }])
  h.loader.verify()
  assert.equal(h.f.opened.length, 1, "opened synchronously, inside the click")
  assert.deepEqual(h.f.opened[0].name, "navexa-agecheck")
  assert.match(h.f.opened[0].features, /popup/)
  assert.equal(h.f.popup.document.body.textContent, "Opening the secure age check…")
  assert.equal(h.last().busy, true)
  await settle()
  assert.deepEqual(h.calls, ["start"])
  assert.equal(h.f.popup.url, HOSTED)
  assert.deepEqual(h.last(), { status: "pending", busy: false, message: undefined })
  assert.equal(h.f.assigned.length, 0)
})

test("polls the status route with the token as a Bearer header, then refreshes on a terminal status", async () => {
  const h = harness([{ v: 1, status: "pending", hosted_url: HOSTED }, { v: 1, status: "passed" }])
  h.loader.verify()
  await settle()
  h.f.replies.push({ status: 200, body: { status: "needs_doc" } }, { status: 200, body: { status: "passed" } })
  await h.f.tick(3000)
  assert.equal(h.f.fetches.length, 1)
  assert.equal(h.f.fetches[0].url, "https://verify.navexa.co/hosted/v1/status")
  assert.equal(h.f.fetches[0].init.headers.Authorization, "Bearer eyJ2IjoxfQ.c2ln")
  assert.equal(h.f.fetches[0].init.credentials, "omit")
  assert.ok(!h.f.fetches[0].url.includes("eyJ2"), "the token never goes in a URL")
  assert.equal(h.f.nextDelay(), 3000, "a healthy poll keeps the pace")
  await h.f.tick(3000)
  assert.deepEqual(h.calls, ["start", "refresh"])
  assert.equal(h.last().status, "passed")
  assert.equal(h.f.pendingTimers(), 0, "polling stopped")
})

test("a blocked popup falls back to a full-page redirect and marks the tab", async () => {
  const h = harness([{ v: 1, status: "pending", hosted_url: HOSTED }], { popup: false })
  h.loader.verify()
  await settle()
  assert.deepEqual(h.f.assigned, [HOSTED])
  assert.equal(h.f.storage.get(REDIRECT_STORAGE_KEY), "1")
})

test("a popup closed before the store answered is a cancel, not a redirect", async () => {
  const h = harness([{ v: 1, status: "pending", hosted_url: HOSTED }])
  h.loader.verify()
  h.f.popup.closed = true
  await settle()
  assert.equal(h.f.assigned.length, 0)
  assert.equal(h.f.popup.url, "")
  assert.deepEqual(h.last(), { status: "none", busy: false, reason: "closed" })
})

test("refusals and failures close the popup and show the store's copy", async () => {
  const h = harness([{ v: 1, status: "refused", message: "US only" }])
  h.loader.verify()
  await settle()
  assert.equal(h.f.popup.closed, true)
  assert.deepEqual(h.last(), { status: "refused", busy: false, message: "US only" })

  const e = harness([new Error("network")])
  e.loader.verify()
  await settle()
  assert.equal(e.f.popup.closed, true)
  assert.equal(e.last().status, "unavailable")
})

test("a passed start needs no popup", async () => {
  const h = harness([{ v: 1, status: "passed" }])
  h.loader.verify()
  await settle()
  assert.equal(h.f.popup.closed, true)
  assert.equal(h.last().status, "passed")
})

test("a hold answer (AgeCheck down, the store takes and holds the order) closes the popup and keeps the store's copy", async () => {
  const h = harness([{ v: 1, status: "hold", message: "held until you verify" }])
  h.loader.verify()
  await settle()
  assert.equal(h.f.popup.closed, true)
  assert.deepEqual(h.last(), { status: "hold", busy: false, message: "held until you verify" })
  assert.equal(h.f.assigned.length, 0)
})

test("an unusable hosted URL fails closed", async () => {
  for (const url of ["http://verify.navexa.co/v/a.b", "https://verify.navexa.co/other/a.b", "javascript:alert(1)", "not a url"]) {
    const h = harness([{ v: 1, status: "pending", hosted_url: url }])
    h.loader.verify()
    await settle()
    assert.equal(h.last().status, "unavailable", url)
    assert.equal(h.f.popup.url, "", url)
    assert.equal(h.f.assigned.length, 0, url)
  }
})

test("unknown statuses read as unavailable; a second click while busy does nothing", async () => {
  const h = harness([{ v: 1, status: "maybe" }])
  h.loader.verify()
  h.loader.verify()
  assert.equal(h.f.opened.length, 1)
  await settle()
  assert.equal(h.last().status, "unavailable")
})

test("network and CORS errors back off up to the cap, 429 honours Retry-After, 401 stops", async () => {
  const h = harness([{ v: 1, status: "pending", hosted_url: HOSTED }])
  h.loader.verify()
  await settle()
  h.f.replies.push(new TypeError("Failed to fetch"), new TypeError("Failed to fetch"))
  await h.f.tick(3000)
  assert.equal(h.f.nextDelay(), 4500)
  await h.f.tick(4500)
  assert.equal(h.f.nextDelay(), 6750)
  h.f.replies.push({ status: 429, headers: { "Retry-After": "40" } })
  await h.f.tick(6750)
  assert.equal(h.f.nextDelay(), 40000)
  h.f.replies.push({ status: 401 })
  await h.f.tick(40000)
  assert.equal(h.f.pendingTimers(), 0)
  assert.equal(h.last().status, "pending", "a dead token is not a failure")
})

test("polling gives up after the hosted session's lifetime", async () => {
  const h = harness([{ v: 1, status: "pending", hosted_url: HOSTED }])
  h.loader.verify()
  await settle()
  for (let i = 0; i < 200 && h.f.pendingTimers() > 0; i++) {
    h.f.replies.push(new TypeError("Failed to fetch"))
    await h.f.tick(15000)
  }
  assert.equal(h.f.pendingTimers(), 0)
  assert.ok(h.f.fetches.length < 200)
})

test("the return page's BroadcastChannel message and storage event trigger a refresh", async () => {
  const h = harness([{ v: 1, status: "pending" }, { v: 1, status: "passed" }], { status: "pending" })
  h.f.channel.onmessage?.({ data: { type: "navexa-agecheck", v: 1, event: "return" } })
  await settle()
  assert.deepEqual(h.calls, ["refresh"])
  h.f.dispatch("storage", { key: "unrelated" })
  await settle()
  assert.deepEqual(h.calls, ["refresh"])
  h.f.dispatch("storage", { key: "navexa-agecheck-return" })
  await settle()
  assert.deepEqual(h.calls, ["refresh", "refresh"])
  assert.equal(h.last().status, "passed")
  h.f.channel.onmessage?.({ data: { type: "navexa-agecheck", v: 1, event: "return" } })
  await settle()
  assert.deepEqual(h.calls, ["refresh", "refresh"], "nothing to refresh once passed")
})

test("postMessage counts only from the hosted origin and with the contract's shape", async () => {
  const h = harness([{ v: 1, status: "pending", hosted_url: HOSTED }, { v: 1, status: "failed", message: "no" }])
  h.loader.verify()
  await settle()
  h.f.dispatch("message", { origin: "https://evil.example", data: { type: "navexa-agecheck", v: 1, status: "passed" } })
  h.f.dispatch("message", { origin: "https://verify.navexa.co", data: { type: "other", v: 1, status: "passed" } })
  h.f.dispatch("message", { origin: "https://verify.navexa.co", data: { type: "navexa-agecheck", v: 2, status: "passed" } })
  await settle()
  assert.deepEqual(h.calls, ["start"])
  h.f.dispatch("message", { origin: "https://verify.navexa.co", data: { type: "navexa-agecheck", v: 1, verification_id: "acv_1", status: "failed" } })
  await settle()
  assert.deepEqual(h.calls, ["start", "refresh"])
  assert.deepEqual(h.last(), { status: "failed", busy: false, message: "no" })
})

test("focus polls at once while watching, and refreshes (throttled) after a reload", async () => {
  const h = harness([{ v: 1, status: "pending" }, { v: 1, status: "pending" }], { status: "pending" })
  h.f.dispatch("focus", {})
  h.f.dispatch("visibilitychange", {})
  await settle()
  assert.deepEqual(h.calls, ["refresh"], "two signals inside 5 s refresh once")
  await h.f.tick(5001)
  h.f.dispatch("focus", {})
  await settle()
  assert.deepEqual(h.calls, ["refresh", "refresh"])

  const w = harness([{ v: 1, status: "pending", hosted_url: HOSTED }])
  w.loader.verify()
  await settle()
  w.f.dispatch("focus", {})
  await w.f.tick(0)
  assert.equal(w.f.fetches.length, 1, "focus polls the status route instead of the store")
})

test("refresh calls share one request; destroy stops everything", async () => {
  const h = harness([{ v: 1, status: "pending" }], { status: "pending" })
  const a = h.loader.refresh()
  const b = h.loader.refresh()
  assert.equal(a, b)
  await a
  assert.deepEqual(h.calls, ["refresh"])
  h.loader.destroy()
  assert.equal(h.f.channel.closed, true)
  assert.equal(await h.loader.refresh(), null)
})

test("parseHostedUrl accepts https hosted links and localhost http only", () => {
  assert.deepEqual(parseHostedUrl("https://verify.navexa.co/v/abc.def"), {
    origin: "https://verify.navexa.co",
    token: "abc.def",
    statusUrl: "https://verify.navexa.co/hosted/v1/status",
  })
  assert.ok(parseHostedUrl("http://localhost:8787/v/abc.def"))
  assert.equal(parseHostedUrl("http://shop.example/v/abc.def"), null)
  assert.equal(parseHostedUrl("https://verify.navexa.co/v/abc"), null)
  assert.equal(parseHostedUrl("https://verify.navexa.co/v/a.b.c"), null)
  assert.equal(parseHostedUrl("https://verify.navexa.co/v/a.b/status"), null)
})

test("the loader source never logs", () => {
  const source = readFileSync(new URL("./agecheck.ts", import.meta.url), "utf8")
  assert.ok(!/console\./.test(source))
})
