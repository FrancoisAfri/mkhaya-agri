// Shared by every Mkhaya chart: the series order (purple first) and number formatting.
export const PALETTE = ['#6A0DAD', '#00A8B5', '#E4A800', '#E8572A', '#2E7D32', '#8E5CD9', '#1E88E5', '#C2185B', '#6D4C41', '#546E7A']
export const GOOD = '#2E7D32'
export const AMBER = '#E4A800'
export const BAD = '#C62828'

/** R 1.2m, 12.4k, 34% -- never a raw 1234567.89 on an axis. */
export function fmt(v: number): string {
  if (v === null || v === undefined || Number.isNaN(Number(v))) return ''
  const n = Number(v)
  const a = Math.abs(n)
  if (a >= 1e9) return (n / 1e9).toFixed(1).replace(/\.0$/, '') + 'bn'
  if (a >= 1e6) return (n / 1e6).toFixed(1).replace(/\.0$/, '') + 'm'
  if (a >= 1e4) return (n / 1e3).toFixed(1).replace(/\.0$/, '') + 'k'
  return Number.isInteger(n) ? n.toLocaleString('en-ZA') : n.toLocaleString('en-ZA', { maximumFractionDigits: 2 })
}
