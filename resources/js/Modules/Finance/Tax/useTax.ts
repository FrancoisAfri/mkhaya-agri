// Shared formatting for the tax screens. Amounts arrive from the server as decimal strings and are
// only formatted here — never added up in the browser, so no tax figure passes through a float.

export function money(v: string | number | null | undefined, currency = 'ZAR'): string {
  if (v === null || v === undefined || v === '') return '—'
  const n = typeof v === 'number' ? v : Number(v)
  if (Number.isNaN(n)) return String(v)
  return new Intl.NumberFormat('en-ZA', { style: 'currency', currency, minimumFractionDigits: 2 }).format(n)
}

export function label(s: string | null | undefined): string {
  if (!s) return '—'
  const t = s.replace(/_/g, ' ')
  return t.charAt(0).toUpperCase() + t.slice(1)
}

// The SRS asks that calculated, adjusted, approved, submitted, accepted, rejected and paid values look
// different from one another (§3.2); one colour per state, used on every screen.
export const statusColor: Record<string, string> = {
  open: 'grey', in_preparation: 'info', under_review: 'warning', approved: 'success', submitted: 'primary',
  accepted: 'success', rejected: 'error', closed: 'blue-grey', locked: 'blue-grey',
  pending: 'warning', active: 'success', suspended: 'grey', deregistered: 'grey',
  draft: 'grey', pending_approval: 'warning', published: 'success', retired: 'blue-grey',
  completed: 'info', signed_off: 'success', reopened: 'warning', failed: 'error',
  in_progress: 'info', done: 'success', overdue: 'error', waived: 'grey',
  matched: 'success', partial: 'warning', unmatched: 'warning', exception: 'error', merged: 'blue-grey',
  recorded: 'grey', adjusted: 'info', excluded: 'blue-grey',
  success: 'success', denied: 'error',
}

export const frequencyLabel: Record<string, string> = {
  monthly: 'Monthly', bi_monthly_even: 'Every two months (ending Feb, Apr…)', bi_monthly_odd: 'Every two months (ending Jan, Mar…)',
  four_monthly: 'Every four months', six_monthly: 'Six-monthly', annual: 'Annual',
}

export const months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
  .map((title, i) => ({ title, value: i + 1 }))
