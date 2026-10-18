export const staticPrimaryColor = '#7367F0'
export const staticPrimaryDarkenColor = '#6259EE'

/**
 * The `primary-darken-1` shade for an arbitrary primary colour.
 *
 * Vuetify treats `primary-darken-1` as a theme colour in its own right — it is what hover, pressed
 * and raised states resolve to, and the calendar paints selections with. The five preset swatches
 * ship a hand-picked darken value, but the custom colour picker and the saved-preferences path had
 * nothing to give it, so both were passing the primary colour straight back. The result: choose
 * your own colour, or simply reload after saving, and every hover state goes flat — the theme
 * looks applied but stops responding to the pointer.
 *
 * 7% is the ratio between the shipped pair (#7367F0 → #6259EE), so a custom colour behaves like
 * the built-in ones rather than merely differently-wrong.
 */
export function darkenHex(hex: string, amount = 0.07): string {
  const match = /^#?([0-9a-f]{6})$/i.exec(hex.trim())

  // Not a colour we can reason about — hand it back untouched rather than emit `#NaNNaNNaN`,
  // which would take the theme down with it.
  if (!match)
    return hex

  const value = parseInt(match[1], 16)

  const channel = (shift: number) => {
    const c = (value >> shift) & 0xff

    return Math.max(0, Math.min(255, Math.round(c * (1 - amount))))
  }

  return '#' + [channel(16), channel(8), channel(0)]
    .map(c => c.toString(16).padStart(2, '0'))
    .join('')
}
