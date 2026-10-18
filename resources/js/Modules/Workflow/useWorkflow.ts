// Shared helpers for the workflow screens.

export function label(s: string | null | undefined): string {
  if (!s) return '—'
  const t = s.replace(/_/g, ' ')
  return t.charAt(0).toUpperCase() + t.slice(1)
}

export function when(iso: string | null | undefined, withTime = true): string {
  if (!iso) return '—'
  const d = new Date(iso)
  return d.toLocaleString('en-ZA', withTime ? { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' } : { day: 'numeric', month: 'short', year: 'numeric' })
}

/** "in 3 h", "2 d overdue" — how a deadline reads at a glance. */
export function due(iso: string | null | undefined): { text: string; color: string } {
  if (!iso) return { text: 'No deadline', color: 'grey' }
  const ms = new Date(iso).getTime() - Date.now()
  const h = Math.round(Math.abs(ms) / 36e5)
  const span = h >= 48 ? `${Math.round(h / 24)} d` : `${h} h`
  if (ms < 0) return { text: `${span} overdue`, color: 'error' }
  return { text: `due in ${span}`, color: h <= 24 ? 'warning' : 'success' }
}

export const statusColor: Record<string, string> = {
  running: 'info', completed: 'success', rejected: 'error', cancelled: 'grey', failed: 'error', suspended: 'warning',
  open: 'info', exception: 'error', draft: 'grey', in_review: 'warning', published: 'success', superseded: 'blue-grey', retired: 'blue-grey',
  approved: 'success', returned: 'warning', info_requested: 'warning', abstained: 'grey', resolved: 'success',
  on_track: 'success', warning: 'warning', breached: 'error', paused: 'grey', none: 'grey',
  active: 'success', revoked: 'grey', expired: 'grey', pending: 'warning', succeeded: 'success', waiting: 'info', done: 'success', skipped: 'grey',
  success: 'success', denied: 'error', high: 'error', normal: 'info', low: 'grey',
}

export const decisionLabel: Record<string, string> = {
  approved: 'Approve', rejected: 'Reject', returned: 'Return for correction', info_requested: 'Ask for information',
  completed: 'Complete', abstained: 'Abstain / declare a conflict', resolved: 'Resolve and continue', cancelled: 'Cancel the request',
}

export const decisionColor: Record<string, string> = {
  approved: 'success', rejected: 'error', returned: 'warning', info_requested: 'info', completed: 'primary', abstained: 'grey', resolved: 'success', cancelled: 'error',
}

// The engine's condition language (app/Modules/Workflow/Support/Conditions.php), for showing and hiding
// fields as the person types. The server evaluates again on submit; this is only for the screen.
type Cond = Record<string, any> | null | undefined
function get(data: any, path: string) { return path.split('.').reduce((o, k) => (o == null ? undefined : o[k]), data) }
function blank(v: any) { return v === null || v === undefined || v === '' || (Array.isArray(v) && v.length === 0) }
function num(v: any) { return v !== '' && v !== null && v !== undefined && !Number.isNaN(Number(v)) }
export function evaluate(c: Cond, data: Record<string, any>, depth = 0): boolean {
  if (!c || Object.keys(c).length === 0 || depth > 10) return true
  if (c.all) return (c.all as Cond[]).every(x => evaluate(x, data, depth + 1))
  if (c.any) return (c.any as Cond[]).some(x => evaluate(x, data, depth + 1))
  if ('not' in c) return !evaluate(c.not, data, depth + 1)
  const a = get(data, c.field ?? ''); const e = c.value
  const s = (v: any) => String(v ?? '').trim().toLowerCase()
  switch (c.operator) {
    case 'eq': return num(a) && num(e) ? Number(a) === Number(e) : s(a) === s(e)
    case 'neq': return num(a) && num(e) ? Number(a) !== Number(e) : s(a) !== s(e)
    case 'gt': return num(a) && num(e) && Number(a) > Number(e)
    case 'gte': return num(a) && num(e) && Number(a) >= Number(e)
    case 'lt': return num(a) && num(e) && Number(a) < Number(e)
    case 'lte': return num(a) && num(e) && Number(a) <= Number(e)
    case 'contains': return Array.isArray(a) ? a.includes(e) : s(a).includes(s(e))
    case 'not_contains': return !(Array.isArray(a) ? a.includes(e) : s(a).includes(s(e)))
    case 'starts_with': return s(a).startsWith(s(e))
    case 'ends_with': return s(a).endsWith(s(e))
    case 'empty': return blank(a)
    case 'not_empty': return !blank(a)
    case 'in': return (Array.isArray(e) ? e : [e]).map(String).includes(String(a))
    case 'not_in': return !(Array.isArray(e) ? e : [e]).map(String).includes(String(a))
    case 'before': return !!a && !!e && String(a) < String(e)
    case 'after': return !!a && !!e && String(a) > String(e)
    case 'between': return Array.isArray(e) && num(a) && Number(a) >= Number(e[0]) && Number(a) <= Number(e[1])
    case 'true': return a === true || a === 'true' || a === 1 || a === '1'
    case 'false': return !(a === true || a === 'true' || a === 1 || a === '1')
    default: return true
  }
}

export function describe(c: Cond, operators: Record<string, string> = {}): string {
  if (!c || Object.keys(c).length === 0) return 'always'
  if (c.all) return (c.all as Cond[]).map(x => describe(x, operators)).join(' and ')
  if (c.any) return (c.any as Cond[]).map(x => describe(x, operators)).join(' or ')
  if ('not' in c) return `not (${describe(c.not, operators)})`
  const v = Array.isArray(c.value) ? c.value.join(' – ') : (c.value ?? '')
  return `${c.field} ${operators[c.operator] ?? c.operator} ${v}`.trim()
}
