import { test } from "node:test"
import assert from "node:assert/strict"
import { storeApiTransport } from "./store-api.ts"

function fakeFetch(replies: Array<{ status: number; body?: unknown; nonce?: string }>) {
  const calls: Array<{ url: string; init: any }> = []
  const fetch = (async (url: string, init: any) => {
    calls.push({ url, init })
    const reply = replies.shift()
    if (!reply) throw new Error("unexpected fetch " + url)
    return {
      ok: reply.status >= 200 && reply.status < 300,
      status: reply.status,
      headers: { get: (name: string) => (name === "Nonce" ? reply.nonce ?? null : null) },
      json: async () => reply.body,
    }
  }) as unknown as typeof globalThis.fetch
  return { fetch, calls }
}

const answer = { extensions: { "navexa-agecheck": { v: 1, status: "pending", hosted_url: "https://verify.navexa.co/v/a.b" } } }

test("fetches the Store API nonce from GET /cart, then posts the action with it", async () => {
  const f = fakeFetch([{ status: 200, nonce: "n1" }, { status: 200, body: answer, nonce: "n2" }, { status: 200, body: answer }])
  let before = 0
  const transport = storeApiTransport({ base: "https://shop.example/wp-json/wc/store/v1", namespace: "navexa-agecheck", fetch: f.fetch, beforeStart: async () => void before++ })
  assert.deepEqual(await transport("start"), answer.extensions["navexa-agecheck"])
  assert.equal(before, 1)
  assert.equal(f.calls[0].url, "https://shop.example/wp-json/wc/store/v1/cart")
  assert.equal(f.calls[1].url, "https://shop.example/wp-json/wc/store/v1/cart/extensions")
  assert.equal(f.calls[1].init.headers.Nonce, "n1")
  assert.equal(f.calls[1].init.credentials, "same-origin")
  assert.deepEqual(JSON.parse(f.calls[1].init.body), { namespace: "navexa-agecheck", data: { v: 1, action: "start" } })
  assert.equal(f.calls[1].init.headers["X-WP-Nonce"], undefined, "guests send no REST nonce")
  await transport("refresh")
  assert.equal(before, 1, "beforeStart runs for start only")
  assert.equal(f.calls[2].init.headers.Nonce, "n2", "the newest nonce is reused")
})

test("a refused nonce is refetched once; signed-in shoppers send X-WP-Nonce", async () => {
  const f = fakeFetch([{ status: 200, nonce: "old" }, { status: 403, body: {} }, { status: 200, nonce: "new" }, { status: 200, body: answer }])
  const transport = storeApiTransport({ base: "/wp-json/wc/store/v1/", namespace: "navexa-agecheck", restNonce: "rest", fetch: f.fetch })
  await transport("refresh")
  assert.equal(f.calls[3].init.headers.Nonce, "new")
  for (const call of f.calls) assert.equal(call.init.headers["X-WP-Nonce"], "rest")
})

test("errors and answers without the extension throw", async () => {
  const bad = fakeFetch([{ status: 200, nonce: "n" }, { status: 500, body: {} }])
  await assert.rejects(storeApiTransport({ base: "/x/", namespace: "navexa-agecheck", fetch: bad.fetch })("start"))
  const empty = fakeFetch([{ status: 200, nonce: "n" }, { status: 200, body: { extensions: {} } }])
  await assert.rejects(storeApiTransport({ base: "/x/", namespace: "navexa-agecheck", fetch: empty.fetch })("start"))
  const nononce = fakeFetch([{ status: 200 }])
  await assert.rejects(storeApiTransport({ base: "/x/", namespace: "navexa-agecheck", fetch: nononce.fetch })("start"))
})
