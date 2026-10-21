/** Small formatting helpers shared by the Mkhaya Agri screens. */
export const human = (s: string | null | undefined) => (s ?? '').replace(/_/g, ' ').replace(/^\w/, c => c.toUpperCase())

export const money = (n: number | null | undefined, currency = 'ZAR') =>
  n === null || n === undefined ? '—' : new Intl.NumberFormat('en-ZA', { style: 'currency', currency, maximumFractionDigits: 0 }).format(Math.abs(n) < 0.5 ? 0 : n)

export const num = (n: number | null | undefined, digits = 2) =>
  n === null || n === undefined ? '—' : new Intl.NumberFormat('en-ZA', { maximumFractionDigits: digits }).format(n)

const COLORS: Record<string, string> = {
  active: 'success', approved: 'success', completed: 'success', issued: 'success', valid: 'success', resolved: 'success',
  planned: 'info', draft: 'secondary', open: 'info', in_progress: 'primary', planted: 'primary', harvesting: 'warning',
  submitted: 'warning', expiring: 'warning', warning: 'warning', maintenance: 'warning', acknowledged: 'secondary',
  cancelled: 'secondary', archived: 'secondary', closed: 'secondary', disposed: 'secondary', superseded: 'secondary', none: 'secondary',
  rejected: 'error', expired: 'error', critical: 'error', died: 'error', missing: 'error',
  sold: 'info', culled: 'secondary', slaughtered: 'secondary', transferred: 'secondary', info: 'info',
  low: 'success', medium: 'warning', high: 'error', urgent: 'error', normal: 'info',
}
export const statusColor = (s: string | null | undefined) => COLORS[s ?? ''] ?? 'default'

/** A local "YYYY-MM-DD" (toISOString would give the UTC date: yesterday, before 02:00 in SAST). */
export const ymd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
export const today = () => ymd(new Date())

/** A local "YYYY-MM-DDTHH:MM" for datetime inputs (toISOString would give UTC, two hours off in SAST). */
export const localDateTime = (d: Date) => {
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`
}
export const hoursFromNow = (h: number) => { const d = new Date(); d.setMinutes(0, 0, 0); d.setHours(d.getHours() + h); return localDateTime(d) }
