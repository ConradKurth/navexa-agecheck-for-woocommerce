/**
 * The overlay both the gate and the verify modal use: one fixed layer over
 * the page (no reflow: the page's own boxes, overflow and scrollbar are left
 * alone), the rest of the page inert and hidden from assistive technology,
 * focus kept inside, and focus handed back when it closes.
 */

/** Class of every overlay root; the page hiding style spares it. */
export const ROOT = "nac"
/** Set on <html> while the gate hides the page. */
export const GATE_ATTR = "data-navexa-agecheck-gate"

const STYLE_ID = "navexa-agecheck-style"
const FONT = 'system-ui,-apple-system,"Segoe UI",Roboto,sans-serif'

/**
 * Every rule is scoped to .nac (or the gate's <html> marker) and sets what it
 * relies on, so a store's own CSS rarely reaches in. Colours and the font come
 * from custom properties the loader sets from validated values only. BASE_CSS
 * goes in with either part; the gate-only build carries GATE_CSS alone.
 */
export const BASE_CSS = `.${ROOT}{all:initial;position:fixed;inset:0;z-index:2147483646;display:flex;align-items:center;justify-content:center;box-sizing:border-box;padding:16px;overflow:auto;overscroll-behavior:contain;background:var(--nac-backdrop,rgba(0,0,0,.6));color:var(--nac-text,#1e1e1e);font:16px/1.5 var(--nac-font,${FONT});visibility:visible;animation:nac-in .15s ease-out}
.${ROOT} *{box-sizing:border-box;letter-spacing:normal;text-transform:none;text-shadow:none}
.${ROOT} :focus-visible{outline:3px solid var(--nac-accent,#2271b1);outline-offset:2px}
.nac-card,.nac-modal{position:relative;width:100%;max-height:100%;margin:auto;border-radius:8px;background:var(--nac-card,#fff);color:var(--nac-text,#1e1e1e);box-shadow:0 8px 32px rgba(0,0,0,.4)}
.${ROOT} .nac-btn{display:block;min-height:44px;width:100%;margin:0;padding:.625em 1em;border:2px solid var(--nac-accent,#1e1e1e);border-radius:6px;background:var(--nac-accent,#1e1e1e);color:var(--nac-accent-text,#fff);font-family:inherit;font-size:1em;font-weight:600;line-height:1.5;text-decoration:none;cursor:pointer}
.${ROOT} [hidden]{display:none!important}
@keyframes nac-in{from{opacity:0}to{opacity:1}}
@media (prefers-reduced-motion:reduce){.${ROOT},.nac-spin{animation:none!important}}`

export const GATE_CSS = `html[${GATE_ATTR}]{background:var(--nac-pre)!important}
html[${GATE_ATTR}] body>:not(.${ROOT}){visibility:hidden!important}
.nac-card{max-width:28em;overflow:auto;padding:2em 1.5em;text-align:center}
.nac-logo{display:block;max-width:12em;max-height:5em;width:auto;height:auto;margin:0 auto 1em}
.nac-title{margin:0 0 .5em;padding:0;font-family:inherit;font-size:1.5em;font-weight:700;line-height:1.25;color:inherit}
.nac-title:focus{outline:none}
.nac-msg{margin:0 0 1.5em;padding:0;font:inherit;color:inherit}
.nac-actions{display:flex;flex-direction:column;gap:.75em;margin:0;padding:0}
.${ROOT} .nac-alt{background:transparent;color:inherit}
.nac-dob{margin:0 0 1em;padding:0;border:0;min-width:0}
.nac-dob legend{margin:0 0 .5em;padding:0;font-weight:600}
.nac-fields{display:flex;gap:.5em;justify-content:center}
.nac-fields label{display:flex;flex-direction:column;gap:.25em;flex:1 1 0;max-width:7em;font-size:.875em}
.${ROOT} .nac-fields input{width:100%;min-height:44px;margin:0;padding:.5em;border:2px solid currentColor;border-radius:6px;background:#fff;color:#1e1e1e;font-family:inherit;font-size:1.125em;line-height:1.25;text-align:center}
.nac-err,.nac-denied{margin:1em 0 0;padding:0;font-weight:600}
.nac-err:empty,.nac-denied:empty{display:none}`

/**
 * The verify modal (brief C1 v2, A7): full screen up to 540px wide; 500px
 * wide from 541px, its height the frame's resize height (--nac-h) plus the
 * header; from 1024px up to 960x660, at most 90% of the viewport. The
 * header with its close button is always visible. The pass moment (1.0.1):
 * .nac-ok over the page (a white tick on #1a7f37, 5.1:1), still under
 * prefers-reduced-motion (.nac-still, set from the same query), and .nac-sr
 * for text only assistive technology reads.
 */
export const MODAL_CSS = `.${ROOT}.nac-v{padding:0}
.nac-modal{display:flex;flex-direction:column;height:100%;border-radius:0;overflow:hidden}
.nac-head{display:flex;align-items:center;justify-content:space-between;gap:.5em;min-height:48px;padding:0 .25em 0 1em;border-bottom:1px solid rgba(0,0,0,.12)}
.nac-head h2{margin:0;font-family:inherit;font-size:1em;font-weight:600;line-height:1.25;color:inherit}
.${ROOT} .nac-x{display:flex;align-items:center;justify-content:center;width:44px;height:44px;margin:0;padding:0;border:0;border-radius:6px;background:transparent;color:inherit;font-family:inherit;font-size:1.75em;font-weight:400;line-height:1;cursor:pointer}
.nac-body{position:relative;flex:1;min-height:0;display:flex;flex-direction:column}
.nac-body iframe{display:block;flex:1;width:100%;min-height:0;border:0}
.nac-wait{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1em;padding:1.5em;background:var(--nac-card,#fff);text-align:center}
.nac-wait p{margin:0}
.nac-wait .nac-btn{width:auto}
.${ROOT} .nac-link{color:inherit;text-decoration:underline;font-weight:600}
.nac-spin{width:2em;height:2em;border:3px solid rgba(0,0,0,.15);border-top-color:currentColor;border-radius:50%;animation:nac-spin 1s linear infinite}
@keyframes nac-spin{to{transform:rotate(1turn)}}
.nac-ok{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.75em;padding:1.5em;background:var(--nac-card,#fff);text-align:center;animation:nac-in .2s ease-out}
.nac-ok p{margin:0;font-size:1.25em;font-weight:700}
.nac-tick{position:relative;width:3em;height:3em;border-radius:50%;background:#1a7f37;animation:nac-pop .3s ease-out}
.nac-tick::after{content:"";position:absolute;left:1.08em;top:.62em;width:.75em;height:1.4em;border:solid #fff;border-width:0 .25em .25em 0;transform:rotate(45deg)}
@keyframes nac-pop{from{transform:scale(.6)}to{transform:scale(1)}}
.nac-ok.nac-still,.nac-still .nac-tick{animation:none!important}
@media (prefers-reduced-motion:reduce){.nac-ok,.nac-tick{animation:none!important}}
.nac-sr{position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
@media (min-width:541px){.${ROOT}.nac-v{padding:16px}.nac-modal{width:500px;max-width:100%;height:min(var(--nac-h,640px),100%);border-radius:8px}}
@media (min-width:1024px){.nac-modal{width:min(960px,90vw);height:min(var(--nac-h,660px),660px,90vh)}}`

/**
 * Adds BASE_CSS and the named part once each: a constructable sheet
 * (CSP-safe) where supported, else a <style>.
 */
export function addStyle(doc: Document, name: string, css: string): void {
  for (const [key, text] of [
    ["base", BASE_CSS],
    [name, css],
  ]) {
    const id = `${STYLE_ID}-${key}`
    const marked = doc as Document & { __nac?: Record<string, boolean> }
    const seen = (marked.__nac = marked.__nac || {})
    if (seen[key] || doc.getElementById(id)) continue
    seen[key] = true
    try {
      if ("adoptedStyleSheets" in doc && typeof CSSStyleSheet === "function") {
        const sheet = new CSSStyleSheet()
        sheet.replaceSync(text)
        doc.adoptedStyleSheets = [...doc.adoptedStyleSheets, sheet]
        continue
      }
    } catch {
      // Fall back to a <style> element.
    }
    const style = doc.createElement("style")
    style.id = id
    style.textContent = text
    ;(doc.head || doc.documentElement).appendChild(style)
  }
}

/** createElement with a class and optional text. */
export function el<K extends keyof HTMLElementTagNameMap>(doc: Document, tag: K, className?: string, text?: string): HTMLElementTagNameMap[K] {
  const node = doc.createElement(tag)
  if (className) node.className = className
  if (text !== undefined) node.textContent = text
  return node
}

const FOCUSABLE = "button,input,select,textarea,iframe,a[href],[tabindex]"

/** The elements of root that Tab reaches, in order (sentinels excluded). */
export function focusables(root: HTMLElement): HTMLElement[] {
  return Array.from(root.querySelectorAll<HTMLElement>(FOCUSABLE)).filter(
    (node) => !node.hasAttribute("data-nac-edge") && node.tabIndex >= 0 && !(node as HTMLButtonElement).disabled && !node.closest("[hidden]"),
  )
}

export interface Layer {
  /** Removes the layer and gives the page back; safe to call twice. */
  close(): void
}

/** The open layers (tests close leftovers between cases). */
export const layers = new Set<Layer>()

export interface LayerOptions {
  /** What Escape does; unset = Escape is swallowed (the gate). */
  onEscape?: () => void
  /** Where focus goes first. */
  initialFocus: () => HTMLElement | null
}

/**
 * Shows root (an element with class ROOT) as a modal layer over the page.
 * Every other child of <body>, and any added while it is open, gets inert
 * and aria-hidden; Tab and Shift+Tab wrap inside root (sentinels catch focus
 * leaving an iframe); focus that lands outside is pulled back.
 */
export function openLayer(doc: Document, root: HTMLElement, opts: LayerOptions): Layer {
  const body = doc.body
  const before = doc.activeElement as HTMLElement | null
  const hidden: Array<[Element, string | null]> = []
  const hide = (node: Element) => {
    if (node === root || node.hasAttribute("inert") || node.tagName === "SCRIPT" || node.tagName === "STYLE") return
    hidden.push([node, node.getAttribute("aria-hidden")])
    node.setAttribute("inert", "")
    node.setAttribute("aria-hidden", "true")
  }
  const first = () => focusables(root)[0]
  const last = () => {
    const all = focusables(root)
    return all[all.length - 1]
  }
  const edge = (pick: () => HTMLElement | undefined) => {
    const node = el(doc, "span")
    node.tabIndex = 0
    node.setAttribute("data-nac-edge", "")
    node.addEventListener("focus", () => pick()?.focus())
    return node
  }
  root.insertBefore(edge(last), root.firstChild)
  root.appendChild(edge(first))

  const onKey = (event: KeyboardEvent) => {
    if (event.key === "Escape" || event.key === "Esc") {
      event.preventDefault()
      event.stopPropagation()
      opts.onEscape?.()
      return
    }
    if (event.key !== "Tab") return
    const all = focusables(root)
    if (all.length === 0) return
    const active = doc.activeElement as HTMLElement | null
    const at = active ? all.indexOf(active) : -1
    if (at < 0 || (event.shiftKey ? at === 0 : at === all.length - 1)) {
      event.preventDefault()
      all[event.shiftKey ? all.length - 1 : 0].focus()
    }
  }
  const onFocusIn = (event: FocusEvent) => {
    const target = event.target as Node | null
    if (target && !root.contains(target)) first()?.focus()
  }

  for (const child of Array.from(body.children)) hide(child)
  body.appendChild(root)
  const watcher =
    typeof MutationObserver === "function"
      ? new MutationObserver((records) => {
          for (const record of records) record.addedNodes.forEach((node) => node.nodeType === 1 && hide(node as Element))
        })
      : null
  watcher?.observe(body, { childList: true })
  doc.addEventListener("keydown", onKey, true)
  doc.addEventListener("focusin", onFocusIn, true)
  ;(opts.initialFocus() || first())?.focus()

  let open = true
  const layer: Layer = {
    close() {
      if (!open) return
      open = false
      layers.delete(layer)
      watcher?.disconnect()
      doc.removeEventListener("keydown", onKey, true)
      doc.removeEventListener("focusin", onFocusIn, true)
      for (const [node, aria] of hidden) {
        node.removeAttribute("inert")
        if (aria === null) node.removeAttribute("aria-hidden")
        else node.setAttribute("aria-hidden", aria)
      }
      root.remove()
      if (before && before !== doc.body && before.isConnected) {
        try {
          before.focus({ preventScroll: true })
        } catch {
          // An element that cannot take focus any more.
        }
      }
    },
  }
  layers.add(layer)
  return layer
}
