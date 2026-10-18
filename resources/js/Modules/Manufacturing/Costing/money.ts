/** Money as the costing screens show it: two decimals, grouped. */
export const money = (n: number | null | undefined): string =>
  n == null ? '—' : Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })

export const statusColor = (s: string): string =>
  ({ FINAL: 'success', PROVISIONAL: 'warning', ESTIMATED: 'grey', MISSING: 'error', DRAFT: 'warning', APPROVED: 'success', SUPERSEDED: 'grey' } as Record<string, string>)[s] ?? 'default'
