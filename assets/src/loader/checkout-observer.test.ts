import { test } from "node:test"
import assert from "node:assert/strict"
import { createCheckoutObserver, type ObserverDeps } from "./checkout-observer.ts"
import type { StoreAnswer } from "./types.ts"

function deps(over: Partial<ObserverDeps> & { answers?: StoreAnswer[] } = {}) {
  const log: string[] = []
  const answers = [...(over.answers ?? [])]
  const d: ObserverDeps = {
    state: () => ({ required: true, status: "none" }),
    hasValidationErrors: () => false,
    expressActive: () => false,
    pushCustomerData: async () => void log.push("push"),
    transport: async (action) => {
      log.push(action)
      const next = answers.shift()
      if (!next) throw new Error("no answer")
      return next
    },
    verify: async (url) => {
      log.push(`verify ${url}`)
      await new Promise((r) => setTimeout(r, 5))
      return { status: "passed" }
    },
    t: (key) => `t:${key}`,
    challenging: () => false,
    ...over,
  }
  return { d, log }
}

test("nothing to check, already passed, field errors or an express payment: true at once, no modal", async () => {
  const cases: Array<Partial<ObserverDeps>> = [
    { state: () => null },
    { state: () => ({ required: false, status: "none" }) },
    { state: () => ({ required: true, status: "passed" }) },
    { hasValidationErrors: () => true },
    { expressActive: () => true },
  ]
  for (const over of cases) {
    const { d, log } = deps(over)
    assert.equal(await createCheckoutObserver(d)(), true)
    assert.deepEqual(log, [])
  }
})

test("pushes the customer data, starts, opens the modal, refreshes; passed lets the order go", async () => {
  const { d, log } = deps({ answers: [{ v: 1, status: "pending", hosted_url: "https://verify.navexa.co/v/a.b" }, { v: 1, status: "passed" }] })
  assert.equal(await createCheckoutObserver(d)(), true)
  assert.deepEqual(log, ["push", "start", "verify https://verify.navexa.co/v/a.b", "refresh"])
})

test("two concurrent emits share one run: one start, one modal", async () => {
  const { d, log } = deps({ answers: [{ v: 1, status: "pending", hosted_url: "https://verify.navexa.co/v/a.b" }, { v: 1, status: "passed" }] })
  const observer = createCheckoutObserver(d)
  const [a, b] = await Promise.all([observer(), observer()])
  assert.equal(a, true)
  assert.equal(b, true)
  assert.equal(log.filter((l) => l === "start").length, 1)
  assert.equal(log.filter((l) => l.startsWith("verify")).length, 1)
})

test("a failed check or a server refusal is an error response with the server's message", async () => {
  const failed = deps({ answers: [{ v: 1, status: "pending", hosted_url: "https://verify.navexa.co/v/a.b" }, { v: 1, status: "failed", message: "We could not verify your age." }] })
  assert.deepEqual(await createCheckoutObserver(failed.d)(), { type: "error", errorMessage: "We could not verify your age." })
  const refused = deps({ answers: [{ v: 1, status: "refused", code: "us_only", message: "US only." }] })
  assert.deepEqual(await createCheckoutObserver(refused.d)(), { type: "error", errorMessage: "US only." })
})

test("not_required and pickup refusals let the order go (the server decides)", async () => {
  for (const code of ["not_required", "pickup"]) {
    const { d } = deps({ answers: [{ v: 1, status: "refused", code }] })
    assert.equal(await createCheckoutObserver(d)(), true)
  }
})

test("closing the modal: refresh, then the closed copy", async () => {
  const { d, log } = deps({ answers: [{ v: 1, status: "pending", hosted_url: "https://verify.navexa.co/v/a.b" }, { v: 1, status: "pending" }], verify: async () => ({ status: "closed" }) })
  assert.deepEqual(await createCheckoutObserver(d)(), { type: "error", errorMessage: "t:closed" })
  assert.ok(log.includes("refresh"))
})

test("a transport that throws is an error response (unavailable); a failing customer push still starts", async () => {
  const thrown = deps({ answers: [] })
  assert.deepEqual(await createCheckoutObserver(thrown.d)(), { type: "error", errorMessage: "t:unavailable" })
  const push = deps({ answers: [{ v: 1, status: "passed" }], pushCustomerData: async () => Promise.reject(new Error("400")) })
  assert.equal(await createCheckoutObserver(push.d)(), true)
})

test("a Turnstile challenge focuses the widget", async () => {
  let focused = 0
  const { d } = deps({ answers: [{ v: 1, status: "refused", message: "Complete the check." }], challenging: () => true, onChallenge: () => void focused++ })
  assert.deepEqual(await createCheckoutObserver(d)(), { type: "error", errorMessage: "Complete the check." })
  assert.equal(focused, 1)
})
