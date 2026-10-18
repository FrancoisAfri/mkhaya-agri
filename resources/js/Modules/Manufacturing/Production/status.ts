/** One colour per production-order status, shared by the list and the order page. */
export const orderStatusColor = (s: string): string => ({
  DRAFT: 'grey',
  PLANNED: 'info',
  APPROVED: 'primary',
  RELEASED: 'indigo',
  IN_PRODUCTION: 'warning',
  QUALITY_REVIEW: 'purple',
  COMPLETED: 'success',
  CLOSED: 'teal',
  ON_HOLD: 'orange',
  CANCELLED: 'error',
  REJECTED: 'error',
} as Record<string, string>)[s] ?? 'default'
