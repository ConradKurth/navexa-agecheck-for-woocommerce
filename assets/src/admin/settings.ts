/**
 * WooCommerce > Settings > AgeCheck > Entry age gate (TAX-1874): the logo
 * picker (the WordPress media library, images only, one at a time) and a live
 * contrast readout for the two text colour pairs. Settings.php refuses a pair
 * under 4.5:1 on save whatever this script shows; this only tells the
 * merchant before they save.
 *
 * Built to assets/js/admin-settings.js (+ .min.js) by esbuild; loaded only on
 * that section, after wp_enqueue_media().
 */

interface SettingsData {
  theme: string
  pairs: Array<[string, string]>
  minContrast: number
  i18n: Record<string, string>
}

interface MediaAttachment {
  id: number
  url?: string
  sizes?: Record<string, { url: string }>
}

interface MediaFrame {
  on(event: string, handler: () => void): void
  open(): void
  state(): { get(name: string): { first(): { toJSON(): MediaAttachment } } }
}

declare global {
  interface Window {
    navexaAgeCheckSettings?: SettingsData
    wp?: { media?: (options: Record<string, unknown>) => MediaFrame }
  }
}

/** WCAG 2.x relative luminance of #rgb / #rrggbb, or null. */
export function luminance(color: string): number | null {
  const m = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(color.trim())
  if (!m) return null
  const hex = m[1].length === 3 ? m[1].replace(/./g, (c) => c + c) : m[1]
  const channel = (i: number) => {
    const c = parseInt(hex.slice(i, i + 2), 16) / 255
    return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4)
  }
  return 0.2126 * channel(0) + 0.7152 * channel(2) + 0.0722 * channel(4)
}

/** WCAG 2.x contrast ratio, unrounded; null when either is not a colour. */
export function contrast(a: string, b: string): number | null {
  const la = luminance(a)
  const lb = luminance(b)
  if (la === null || lb === null) return null
  return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05)
}

/** A ratio rounded down to two decimals (4.497 never reads 4.50). */
export function ratioText(ratio: number): string {
  return (Math.floor(ratio * 100) / 100).toFixed(2)
}

function bootMedia(data: SettingsData): void {
  document.querySelectorAll<HTMLElement>("[data-navexa-agecheck-media]").forEach((cell) => {
    const input = cell.querySelector<HTMLInputElement>("[data-navexa-agecheck-media-id]")
    const preview = cell.querySelector<HTMLElement>("[data-navexa-agecheck-media-preview]")
    const remove = cell.querySelector<HTMLButtonElement>("[data-navexa-agecheck-media-remove]")
    cell.querySelector("[data-navexa-agecheck-media-choose]")?.addEventListener("click", (event) => {
      event.preventDefault()
      if (!window.wp?.media || !input) return
      const frame = window.wp.media({ title: data.i18n.choose, button: { text: data.i18n.use }, library: { type: "image" }, multiple: false })
      frame.on("select", () => {
        const picked = frame.state().get("selection").first().toJSON()
        input.value = String(picked.id)
        if (preview) {
          preview.textContent = ""
          const img = document.createElement("img")
          img.src = picked.sizes?.thumbnail?.url ?? picked.url ?? ""
          img.alt = ""
          img.style.maxWidth = "80px"
          img.style.height = "auto"
          preview.appendChild(img)
        }
        if (remove) remove.hidden = false
      })
      frame.open()
    })
    remove?.addEventListener("click", (event) => {
      event.preventDefault()
      if (input) input.value = ""
      if (preview) preview.textContent = ""
      remove.hidden = true
    })
  })
}

function bootContrast(data: SettingsData): void {
  const field = (key: string) => document.getElementById(`${data.theme}[${key}]`) as HTMLInputElement | null
  for (const [fg, bg] of data.pairs) {
    const fgInput = field(fg)
    const bgInput = field(bg)
    if (!fgInput || !bgInput) continue
    const out = document.createElement("span")
    out.className = "description"
    out.setAttribute("aria-live", "polite")
    out.style.marginLeft = "8px"
    fgInput.insertAdjacentElement("afterend", out)
    const update = () => {
      const ratio = contrast(fgInput.value, bgInput.value)
      if (ratio === null) {
        out.textContent = ""
        return
      }
      const low = ratio < data.minContrast
      out.textContent = (low ? data.i18n.tooLow : data.i18n.contrast).replace("%s", ratioText(ratio))
      out.style.color = low ? "#d63638" : ""
    }
    for (const input of [fgInput, bgInput]) {
      input.addEventListener("input", update)
      input.addEventListener("change", update)
    }
    // WooCommerce's iris picker changes the value without an input event.
    const jq = (window as unknown as { jQuery?: (el: unknown) => { on(event: string, handler: () => void): void } }).jQuery
    if (jq) for (const input of [fgInput, bgInput]) jq(input).on("irischange", () => window.setTimeout(update, 0))
    update()
  }
}

function boot(): void {
  const data = window.navexaAgeCheckSettings
  if (!data) return
  bootMedia(data)
  bootContrast(data)
}

if (typeof document !== "undefined") {
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot)
  else boot()
}
