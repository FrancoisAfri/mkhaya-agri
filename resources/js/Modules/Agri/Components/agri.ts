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

export const today = () => new Date().toISOString().slice(0, 10)
