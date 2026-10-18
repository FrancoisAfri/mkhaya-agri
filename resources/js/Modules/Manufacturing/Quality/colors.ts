/** Shared colours for the quality screens. */
export const inspectionStatusColor = (s: string): string =>
  ({ OPEN: 'info', AWAITING_DISPOSITION: 'warning', CLOSED: 'grey' } as Record<string, string>)[s] ?? 'default'

export const resultColor = (r: string): string =>
  ({ PENDING: 'grey', PASSED: 'success', FAILED: 'error' } as Record<string, string>)[r] ?? 'default'

export const severityColor = (s: string): string =>
  ({ MINOR: 'info', MAJOR: 'warning', CRITICAL: 'error' } as Record<string, string>)[s] ?? 'default'

export const ncrStatusColor = (s: string): string =>
  ({ OPEN: 'error', INVESTIGATING: 'warning', ACTION: 'orange', VERIFICATION: 'purple', CLOSED: 'success', VOID: 'grey' } as Record<string, string>)[s] ?? 'default'
