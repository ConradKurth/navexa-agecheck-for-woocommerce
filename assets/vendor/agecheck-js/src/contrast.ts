/**
 * WCAG 2.x contrast for the gate's colour pairs (brief C2 v2, B6): text on
 * its background must reach 4.5:1. Settings UIs refuse below it, the gate
 * generator blocks the copy, and the gate itself falls back to its default
 * pair (options.ts). Vectors: schema/vectors/contrast.json.
 */

/** The minimum contrast ratio for text on its background. */
export const MIN_CONTRAST = 4.5

/** Relative luminance of a #rgb or #rrggbb colour. */
function luminance(hex: string): number {
  let h = hex.slice(1)
  if (h.length === 3) h = h.replace(/./g, "$&$&")
  const [r, g, b] = [0, 2, 4].map((i) => {
    const c = parseInt(h.slice(i, i + 2), 16) / 255
    return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
  })
  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

/**
 * The contrast ratio (1..21) of two #rgb or #rrggbb colours, unrounded:
 * compare it to MIN_CONTRAST as is (4.499 fails).
 */
export function contrastRatio(a: string, b: string): number {
  const [x, y] = [luminance(a), luminance(b)].sort((p, q) => q - p)
  return (x + 0.05) / (y + 0.05)
}
