export const fulfilmentFlagColor = (flag: string) => ({
  READY: 'success', DELIVERED: 'grey', ON_TRACK: 'primary', AT_RISK: 'warning', LATE: 'error', CANCELLED: 'grey',
} as Record<string, string>)[flag] ?? 'grey'

export const subcontractStatusColor = (status: string) => ({
  DRAFT: 'grey', SENT: 'info', PART_RECEIVED: 'warning', RECEIVED: 'primary', CLOSED: 'success', CANCELLED: 'grey',
} as Record<string, string>)[status] ?? 'grey'
