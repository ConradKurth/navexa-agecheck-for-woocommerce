/**
 * Classic checkout: the name and ship-to fields locked after a pass
 * (TAX-1874, design-woo §6.1).
 *
 * A soft lock for consistency, not a control: the server binds the ship-to
 * (the checkout guard's ship-to MAC, AttachOrder's intended ship-to,
 * AddressGuard after binding), so a different address placed anyway is
 * refused or re-verified there. The name is not bound (owner rule: no name
 * match before the order).
 *
 * - Locked: billing and shipping first and last name, country, address lines,
 *   city, state and postcode, and "Ship to a different address?". Email,
 *   phone, company, notes, the shipping method and payment stay editable.
 * - Text inputs become readOnly (still posted). Selects (country, a state
 *   select; selectWoo follows the disabled attribute) and the checkbox become
 *   disabled, each with a hidden mirror input carrying its value, because
 *   WooCommerce posts the form with $form.serialize(), which skips disabled
 *   controls. .val() on a disabled select still works for update_order_review.
 * - Only controls this module changed are marked (data-navexa-agecheck-locked)
 *   and restored; one a theme made read-only or disabled is left alone.
 */

export const LOCKED_ATTRIBUTE = "data-navexa-agecheck-locked"
export const MIRROR_ATTRIBUTE = "data-navexa-agecheck-mirror"

const PARTS = ["first_name", "last_name", "country", "address_1", "address_2", "city", "state", "postcode"]

/** Every field name the lock covers. */
export const LOCKED_FIELDS: string[] = [...PARTS.map((p) => `billing_${p}`), ...PARTS.map((p) => `shipping_${p}`), "ship_to_different_address"]

/** The part of a form control the lock touches; tests pass fakes. */
export interface FieldLike {
  name: string
  tagName: string
  type?: string
  value: string
  checked?: boolean
  readOnly?: boolean
  disabled?: boolean
  getAttribute(name: string): string | null
  setAttribute(name: string, value: string): void
  removeAttribute(name: string): void
}

/** The part of a form the lock touches. */
export interface FormLike {
  querySelectorAll(selector: string): ArrayLike<FieldLike>
  appendChild(child: FieldLike): unknown
  removeChild?(child: FieldLike): unknown
}

export interface DocLike {
  createElement(tag: string): FieldLike
}

/** How a control is locked: "readonly" (still posted), "disabled" (needs a mirror) or null (left alone). */
export function lockKind(field: FieldLike): "readonly" | "disabled" | null {
  const tag = field.tagName.toUpperCase()
  const type = (field.type || "").toLowerCase()
  if (tag === "SELECT") return field.disabled ? null : "disabled"
  if (tag === "TEXTAREA") return field.readOnly || field.disabled ? null : "readonly"
  if (tag !== "INPUT" || field.disabled) return null
  if (type === "checkbox") return "disabled"
  if (["hidden", "radio", "button", "submit", "file", "image", "reset"].includes(type)) return null
  return field.readOnly ? null : "readonly"
}

/** The controls of the locked fields in form. */
function controls(form: FormLike): FieldLike[] {
  const out: FieldLike[] = []
  const all = form.querySelectorAll(LOCKED_FIELDS.map((name) => `[name="${name}"]`).join(","))
  for (let i = 0; i < all.length; i++) {
    const field = all[i]
    if (field.getAttribute(MIRROR_ATTRIBUTE) === null) out.push(field)
  }
  return out
}

/** Whether form is locked now. */
export function isLocked(form: FormLike): boolean {
  return form.querySelectorAll(`[${LOCKED_ATTRIBUTE}]`).length > 0
}

/** Locks the name and ship-to fields; returns how many controls it changed. Locking twice changes nothing. */
export function lockForm(form: FormLike, doc: DocLike): number {
  let changed = 0
  for (const field of controls(form)) {
    if (field.getAttribute(LOCKED_ATTRIBUTE) !== null) continue
    const kind = lockKind(field)
    if (kind === "readonly") {
      field.readOnly = true
      field.setAttribute("readonly", "readonly")
    } else if (kind === "disabled") {
      const isBox = (field.type || "").toLowerCase() === "checkbox"
      if (!isBox || field.checked) {
        const mirror = doc.createElement("input")
        mirror.type = "hidden"
        mirror.name = field.name
        mirror.value = isBox ? field.value || "1" : field.value
        mirror.setAttribute(MIRROR_ATTRIBUTE, "1")
        form.appendChild(mirror)
      }
      field.disabled = true
      field.setAttribute("disabled", "disabled")
    } else {
      continue
    }
    field.setAttribute(LOCKED_ATTRIBUTE, kind)
    changed++
  }
  return changed
}

/** Unlocks what lockForm() locked and removes the mirrors. */
export function unlockForm(form: FormLike): void {
  const locked = form.querySelectorAll(`[${LOCKED_ATTRIBUTE}]`)
  for (let i = 0; i < locked.length; i++) {
    const field = locked[i]
    if (field.getAttribute(LOCKED_ATTRIBUTE) === "readonly") {
      field.readOnly = false
      field.removeAttribute("readonly")
    } else {
      field.disabled = false
      field.removeAttribute("disabled")
    }
    field.removeAttribute(LOCKED_ATTRIBUTE)
  }
  const mirrors = form.querySelectorAll(`[${MIRROR_ATTRIBUTE}]`)
  const list: FieldLike[] = []
  for (let i = 0; i < mirrors.length; i++) list.push(mirrors[i])
  for (const mirror of list) {
    const node = mirror as unknown as { remove?: () => void }
    if (typeof node.remove === "function") node.remove()
    else form.removeChild?.(mirror)
  }
}
