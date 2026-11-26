import { test } from "node:test"
import assert from "node:assert/strict"
import { createPlaceOrderFlow, failureMessage, type PlaceOrderDeps } from "./place-order.ts"
import type { StoreAnswer } from "./types.ts"

const T: Record<string, string> = { passed: "Age verified.", failed: "Failed.", pending: "Not finished.", unavailable: "Unavailable.", closed: "Closed." }

type Handler = (event: { stopImmediatePropagation(): void }) => unknown

/**
 * jQuery's triggerHandler, as WooCommerce's checkout.js reads it: the last
 * result that is not undefined; stopImmediatePropagation() skips the handlers
 * bound later (a false only stops bubbling, so they still run).
 */
function triggerHandler(handlers: Handler[]): unknown {
  let result: unknown = undefined
  let stopped = false
  const event = { stopImmediatePropagation: () => void (stopped = true) }
  for (const handler of handlers) {
    if (stopped) break
    const ret = handler(event)
    if (ret !== undefined) result = ret
  }
  return result
}

function harness(opts: { status?: string | null; answers?: StoreAnswer[]; verify?: () => Promise<any>; challenging?: boolean; updateStatus?: string | null; before?: Handler } = {}) {
  const log: string[] = []
  let status: string | null = opts.status === undefined ? "none" : opts.status
  const answers = [...(opts.answers ?? [])]
  let flow: ReturnType<typeof createPlaceOrderFlow>
  const deps: PlaceOrderDeps = {
    status: () => status,
    updateCheckout: async () => {
      log.push("update")
      if (opts.updateStatus !== undefined) status = opts.updateStatus
    },
    transport: async (action) => {
      log.push(action)
      const next = answers.shift()
      if (!next) throw new Error("no answer")
      return next
    },
    verify: async (url) => {
      log.push(`verify ${url}`)
      return opts.verify ? opts.verify() : { status: "passed" }
    },
    block: () => log.push("block"),
    unblock: () => log.push("unblock"),
    submit: () => {
      log.push("submit")
      // WooCommerce's submit() asks checkout_place_order again, synchronously
      // (opts.before: another plugin's handler, bound before ours).
      log.push(`handler:${String(triggerHandler([...(opts.before ? [opts.before] : []), (e) => flow.onPlaceOrder(e)]))}`)
    },
    passed: () => {
      log.push("passed")
      status = "passed"
    },
    fail: (message, focus) => log.push(`fail ${message}${focus ? " (focus challenge)" : ""}`),
    t: (key) => T[key] ?? key,
    challenging: () => !!opts.challenging,
  }
  flow = createPlaceOrderFlow(deps)
  return { flow, log, deps }
}

const event = () => {
  const e = { stopped: 0, stopImmediatePropagation() { e.stopped++ } }
  return e
}

test("no container (nothing to check) or a passed status answers undefined (never true) and leaves WooCommerce alone", () => {
  for (const status of [null, "passed"]) {
    const { flow, log } = harness({ status })
    const e = event()
    assert.equal(flow.onPlaceOrder(e), undefined)
    assert.equal(e.stopped, 0)
    assert.deepEqual(log, [])
    assert.notEqual(triggerHandler([(ev) => flow.onPlaceOrder(ev)]), false, "WooCommerce goes on (it tests !== false)")
  }
})

test("another plugin's false from a handler bound before ours stands: no check needed, passed, and the resubmit past the bypass", async () => {
  const other: Handler = () => false
  for (const status of [null, "passed"]) {
    const { flow } = harness({ status })
    assert.equal(triggerHandler([other, (e) => flow.onPlaceOrder(e)]), false, String(status))
  }
  const { flow, log } = harness({ answers: [{ v: 1, status: "passed" }], before: other })
  await flow.run()
  assert.deepEqual(log.slice(-2), ["submit", "handler:false"], "the resubmit is still refused by the other handler")
  const needed = harness()
  assert.equal(triggerHandler([() => true, (e) => needed.flow.onPlaceOrder(e), () => true]), false, "a needed check: false, and later handlers are skipped")
  while (needed.flow.busy()) await new Promise((r) => setTimeout(r, 1))
})

test("a needed check answers false, stops later handlers, then start, modal, refresh and exactly one resubmit past the bypass", async () => {
  const { flow, log } = harness({ answers: [{ v: 1, status: "pending", hosted_url: "https://verify.navexa.co/v/a.b" }, { v: 1, status: "passed" }] })
  const e = event()
  assert.equal(flow.onPlaceOrder(e), false)
  assert.equal(e.stopped, 1)
  assert.equal(flow.busy(), true)
  assert.equal(flow.onPlaceOrder(event()), false, "a second click while busy starts nothing new")
  while (flow.busy()) await new Promise((r) => setTimeout(r, 1))
  assert.deepEqual(log, ["block", "update", "start", "unblock", "verify https://verify.navexa.co/v/a.b", "block", "refresh", "passed", "unblock", "submit", "handler:undefined"])
  assert.equal(log.filter((l) => l === "start").length, 1)
  assert.equal(flow.onPlaceOrder(event()), undefined, "passed now: the next attempt goes through")
})

test("unblock happens before the resubmit, and never after it (WooCommerce's own overlay stays)", async () => {
  const { flow, log } = harness({ answers: [{ v: 1, status: "passed" }] })
  await flow.run()
  const submitAt = log.indexOf("submit")
  assert.ok(log.lastIndexOf("unblock") < submitAt)
})

test("a session already passed after the update resubmits without a start", async () => {
  const { flow, log } = harness({ updateStatus: "passed" })
  await flow.run()
  assert.deepEqual(log, ["block", "update", "unblock", "submit", "handler:undefined"])
})

test("failed and unavailable answers show the message and never resubmit", async () => {
  for (const answer of [{ v: 1, status: "failed", message: "We could not verify your age." }, { v: 1, status: "unavailable" }]) {
    const { flow, log } = harness({ answers: [answer] })
    await flow.run()
    assert.ok(!log.includes("submit"), JSON.stringify(log))
    assert.ok(log.some((l) => l.startsWith("fail ")))
  }
})

test("no_address, not_required and pickup refusals submit anyway (WooCommerce and the server decide); other refusals stop", async () => {
  for (const code of ["no_address", "not_required", "pickup"]) {
    const { flow, log } = harness({ answers: [{ v: 1, status: "refused", code, message: "x" }] })
    await flow.run()
    assert.ok(log.includes("submit"), code)
  }
  for (const code of ["us_only", "turnstile", "rate_limited", "outdated"]) {
    const { flow, log } = harness({ answers: [{ v: 1, status: "refused", code, message: "No." }] })
    await flow.run()
    assert.ok(!log.includes("submit"), code)
    assert.ok(log.includes("fail No."), code)
  }
})

test("a Turnstile challenge moves the focus to it", async () => {
  const { flow, log } = harness({ answers: [{ v: 1, status: "refused", message: "Complete the security check." }], challenging: true })
  await flow.run()
  assert.ok(log.includes("fail Complete the security check. (focus challenge)"))
})

test("closing the modal refreshes, then says it was closed; a verify() that throws still refreshes", async () => {
  const closed = harness({ answers: [{ v: 1, status: "pending", hosted_url: "https://verify.navexa.co/v/a.b" }, { v: 1, status: "pending" }], verify: async () => ({ status: "closed" }) })
  await closed.flow.run()
  assert.ok(closed.log.includes("refresh"))
  assert.ok(closed.log.includes("fail Closed."))
  const threw = harness({ answers: [{ v: 1, status: "pending", hosted_url: "https://verify.navexa.co/v/a.b" }, { v: 1, status: "passed" }], verify: async () => Promise.reject(new Error("boom")) })
  await threw.flow.run()
  assert.ok(threw.log.includes("refresh"))
  assert.ok(threw.log.includes("submit"), "the server said passed")
})

test("a transport error reads as unavailable, with no resubmit", async () => {
  const { flow, log } = harness({ answers: [] })
  await flow.run()
  assert.ok(log.includes("fail Unavailable."))
  assert.ok(!log.includes("submit"))
})

test("failureMessage: the server's message wins, then closed, then the status copy", () => {
  const t = (k: string) => T[k] ?? k
  assert.equal(failureMessage({ v: 1, status: "failed", message: "Server says." }, true, t), "Server says.")
  assert.equal(failureMessage({ v: 1, status: "pending" }, true, t), "Closed.")
  assert.equal(failureMessage({ v: 1, status: "failed" }, true, t), "Failed.", "a failure stays a failure")
  assert.equal(failureMessage({ v: 1, status: "none" }, false, t), "Not finished.")
  assert.equal(failureMessage({ v: 1, status: "weird" }, false, t), "Unavailable.")
})
