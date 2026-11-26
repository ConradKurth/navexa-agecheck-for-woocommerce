import { test } from "node:test"
import assert from "node:assert/strict"
import { LOCKED_ATTRIBUTE, LOCKED_FIELDS, MIRROR_ATTRIBUTE, isLocked, lockForm, lockKind, unlockForm, type FieldLike } from "./lock.ts"

class FakeField implements FieldLike {
  attrs = new Map<string, string>()
  readOnly = false
  disabled = false
  checked = false
  removed = false
  name: string
  tagName: string
  type: string
  value: string
  constructor(name: string, tagName: string, type = "", value = "") {
    this.name = name
    this.tagName = tagName
    this.type = type
    this.value = value
  }
  getAttribute(name: string) {
    return this.attrs.has(name) ? (this.attrs.get(name) as string) : null
  }
  setAttribute(name: string, value: string) {
    this.attrs.set(name, value)
  }
  removeAttribute(name: string) {
    this.attrs.delete(name)
  }
  remove() {
    this.removed = true
  }
}

function fakeForm(fields: FakeField[]) {
  const children = [...fields]
  const form = {
    children,
    querySelectorAll(selector: string) {
      const live = children.filter((c) => !c.removed)
      if (selector === `[${LOCKED_ATTRIBUTE}]`) return live.filter((c) => c.getAttribute(LOCKED_ATTRIBUTE) !== null)
      if (selector === `[${MIRROR_ATTRIBUTE}]`) return live.filter((c) => c.getAttribute(MIRROR_ATTRIBUTE) !== null)
      const names = [...selector.matchAll(/\[name="([^"]+)"\]/g)].map((m) => m[1])
      return live.filter((c) => names.includes(c.name))
    },
    appendChild(child: FieldLike) {
      children.push(child as FakeField)
      return child
    },
  }
  const doc = { createElement: (tag: string) => new FakeField("", tag.toUpperCase()) }
  return { form, doc, children }
}

test("the lock covers name, address and ship-to-different, never email, phone, company or notes", () => {
  assert.ok(LOCKED_FIELDS.includes("billing_first_name"))
  assert.ok(LOCKED_FIELDS.includes("shipping_postcode"))
  assert.ok(LOCKED_FIELDS.includes("ship_to_different_address"))
  for (const name of ["billing_email", "billing_phone", "billing_company", "order_comments"]) assert.ok(!LOCKED_FIELDS.includes(name), name)
})

test("text inputs turn read-only (still posted); selects and the checkbox are disabled with a hidden mirror of their value", () => {
  const first = new FakeField("billing_first_name", "INPUT", "text", "Ada")
  const country = new FakeField("billing_country", "SELECT", "", "US")
  const state = new FakeField("billing_state", "SELECT", "", "CA")
  const box = new FakeField("ship_to_different_address", "INPUT", "checkbox", "1")
  box.checked = true
  const email = new FakeField("billing_email", "INPUT", "email", "a@example.com")
  const { form, doc, children } = fakeForm([first, country, state, box, email])
  assert.equal(lockForm(form, doc), 4)
  assert.equal(first.readOnly, true)
  assert.equal(first.disabled, false)
  assert.equal(country.disabled, true)
  assert.equal(box.disabled, true)
  assert.equal(email.readOnly, false, "email stays editable")
  const mirrors = children.filter((c) => c.getAttribute(MIRROR_ATTRIBUTE) !== null)
  assert.deepEqual(
    mirrors.map((m) => [m.name, m.type, m.value]),
    [
      ["billing_country", "hidden", "US"],
      ["billing_state", "hidden", "CA"],
      ["ship_to_different_address", "hidden", "1"],
    ],
  )
  assert.ok(isLocked(form))
  assert.equal(lockForm(form, doc), 0, "locking twice changes nothing")
  assert.equal(children.filter((c) => c.getAttribute(MIRROR_ATTRIBUTE) !== null).length, 3, "no second mirror")
})

test("an unchecked checkbox gets no mirror (it would not be posted either); hidden and already locked controls are left alone", () => {
  const box = new FakeField("ship_to_different_address", "INPUT", "checkbox", "1")
  const hiddenState = new FakeField("billing_state", "INPUT", "hidden", "")
  const themed = new FakeField("billing_city", "INPUT", "text", "Austin")
  themed.readOnly = true
  const { form, doc, children } = fakeForm([box, hiddenState, themed])
  assert.equal(lockForm(form, doc), 1)
  assert.equal(children.filter((c) => c.getAttribute(MIRROR_ATTRIBUTE) !== null).length, 0)
  assert.equal(lockKind(hiddenState), null)
  assert.equal(themed.getAttribute(LOCKED_ATTRIBUTE), null, "a theme's read-only field is not ours to restore")
  unlockForm(form)
  assert.equal(themed.readOnly, true, "and stays read-only")
})

test("unlock restores exactly what the lock changed and removes the mirrors", () => {
  const last = new FakeField("shipping_last_name", "INPUT", "text", "Lovelace")
  const country = new FakeField("shipping_country", "SELECT", "", "US")
  const { form, doc, children } = fakeForm([last, country])
  lockForm(form, doc)
  unlockForm(form)
  assert.equal(last.readOnly, false)
  assert.equal(country.disabled, false)
  assert.equal(last.getAttribute(LOCKED_ATTRIBUTE), null)
  assert.ok(children.filter((c) => c.getAttribute(MIRROR_ATTRIBUTE) !== null).every((m) => m.removed))
  assert.equal(isLocked(form), false)
})
