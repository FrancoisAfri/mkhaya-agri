/**
 * Formatting shared by every Procurement Governance page.
 *
 * The rule that matters here is the distinction between "zero" and "not known". Several figures in
 * this module come from modules a tenant may not have installed — committed spend needs Budget
 * Management, invoiced value needs Accounting — and the controller sends `null` when it genuinely
 * cannot see them. Rendering that as "R 0.00" is a claim ("there is no money against this") rather
 * than a fact ("we cannot see it from here"), and it is the kind of wrong number people act on.
 * So null formats as an em dash, everywhere, without exception.
 */

/** Money, with the tenant's currency code. Null renders as an em dash, never as zero. */
export function money(value: number | string | null | undefined, currency = 'ZAR'): string {
  if (value === null || value === undefined || value === '') return '—'

  const amount = typeof value === 'string' ? Number(value) : value
  if (Number.isNaN(amount)) return '—'

  return `${currency} ${amount.toLocaleString(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`
}

/** A score or percentage to two decimals. */
export function score(value: number | string | null | undefined, suffix = ''): string {
  if (value === null || value === undefined || value === '') return '—'

  const n = typeof value === 'string' ? Number(value) : value
  if (Number.isNaN(n)) return '—'

  return `${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}${suffix}`
}

/** A whole number, with thousands separators. */
export function count(value: number | string | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—'

  const n = typeof value === 'string' ? Number(value) : value

  return Number.isNaN(n) ? '—' : n.toLocaleString()
}

/** A date, in the viewer's locale. Time is dropped: procurement dates are read as days. */
export function date(value: string | null | undefined): string {
  if (!value) return '—'

  const parsed = new Date(value)

  return Number.isNaN(parsed.getTime())
    ? '—'
    : parsed.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}

/** A date and time — used where the exact moment matters, as in the audit trail. */
export function dateTime(value: string | null | undefined): string {
  if (!value) return '—'

  const parsed = new Date(value)

  return Number.isNaN(parsed.getTime())
    ? '—'
    : parsed.toLocaleString(undefined, {
        year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
      })
}

/** Whole days from now; negative means overdue. Null when there is no date to measure to. */
export function daysUntil(value: string | null | undefined): number | null {
  if (!value) return null

  const target = new Date(value)
  if (Number.isNaN(target.getTime())) return null

  const start = new Date()
  start.setHours(0, 0, 0, 0)
  target.setHours(0, 0, 0, 0)

  return Math.round((target.getTime() - start.getTime()) / 86_400_000)
}

/** SCREAMING_SNAKE → "Screaming snake", for the status values stored as enums. */
export function humanise(value: string | null | undefined): string {
  if (!value) return '—'

  const words = value.replace(/_/g, ' ').toLowerCase()

  return words.charAt(0).toUpperCase() + words.slice(1)
}

/**
 * Status → Vuetify colour. Every consumer also renders the label, never the colour alone: colour
 * must not be the only carrier of meaning, both for accessibility and because these states are
 * consequential enough that a colour-blind reader guessing is not acceptable.
 */
export function statusColour(status: string | null | undefined): string {
  if (!status) return 'grey'

  const map: Record<string, string> = {
    // Neutral / in progress
    DRAFT: 'grey', PLANNED: 'grey', NOT_STARTED: 'grey', PENDING: 'warning', ACTIVE: 'info',
    SUBMITTED: 'info', IN_PROGRESS: 'info', EVALUATING: 'info', ASSIGNED: 'grey',
    OPEN: 'warning', AWAITING_RESPONSE: 'warning', REOPENED: 'warning',

    // Review stages
    DEPARTMENT_REVIEW: 'info', BUDGET_VERIFICATION: 'info', PROCUREMENT_REVIEW: 'info',
    COMPLIANCE_REVIEW: 'info', FINANCIAL_REVIEW: 'info', MANAGEMENT_APPROVAL: 'warning',
    COMMITTEE_SETUP: 'info', CRITERIA_SETUP: 'info', EVALUATION: 'info', MODERATION: 'warning',
    RECOMMENDATION: 'warning', APPROVAL: 'warning',

    // Good outcomes
    APPROVED: 'success', COMPLETED: 'success', AWARDED: 'success', CLOSED: 'success',
    RESOLVED: 'success', PASS: 'success', COMPLIANT: 'success', SHORTLISTED: 'success',
    RECOMMENDED: 'success', DECLARED: 'success', APPROVED_TO_PARTICIPATE: 'success',

    // Attention
    WARNING: 'warning', ATTENTION: 'warning', DELAYED: 'warning', ESCALATED: 'warning',
    HIGH: 'warning', NOT_COMPLETED: 'warning', UNKNOWN: 'warning',

    // Bad outcomes
    REJECTED: 'error', CANCELLED: 'error', DISQUALIFIED: 'error', FAIL: 'error',
    NON_COMPLIANT: 'error', CRITICAL: 'error', OVERLOADED: 'error', CONFLICT_IDENTIFIED: 'error',

    // Withdrawn / inactive
    WITHDRAWN: 'grey', RECUSED: 'grey', REMOVED: 'grey', SKIPPED: 'grey', SUPERSEDED: 'grey',
    UNSUCCESSFUL: 'grey', SUSPENDED: 'grey', ARCHIVED: 'grey', DISMISSED: 'grey',
    UNDER_UTILISED: 'grey', LOW: 'grey', NORMAL: 'success', MEDIUM: 'info',
  }

  return map[status] ?? 'grey'
}

/**
 * An icon per status family, so meaning survives without colour (WCAG 1.4.1) and reads at a glance
 * on a dense register.
 */
export function statusIcon(status: string | null | undefined): string {
  const colour = statusColour(status)

  return {
    success: 'mdi-check-circle-outline',
    error: 'mdi-alert-circle-outline',
    warning: 'mdi-alert-outline',
    info: 'mdi-progress-clock',
    grey: 'mdi-circle-outline',
  }[colour] ?? 'mdi-circle-outline'
}

/** Risk level → colour, in the order procurement people read them. */
export function riskColour(level: string | null | undefined): string {
  return {
    LOW: 'success', MEDIUM: 'info', HIGH: 'warning', CRITICAL: 'error',
  }[level ?? ''] ?? 'grey'
}
