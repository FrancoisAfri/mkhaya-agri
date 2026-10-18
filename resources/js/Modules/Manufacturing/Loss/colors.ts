export const lossStatusColor = (s: string) => ({
  DETECTED: 'warning', INVESTIGATING: 'info', VERIFIED: 'error', DISMISSED: 'grey', ACTIONED: 'primary', CLOSED: 'success',
} as Record<string, string>)[s] ?? 'grey'

export const confidenceColor = (c: string) => ({ HIGH: 'success', MEDIUM: 'warning', LOW: 'grey' } as Record<string, string>)[c] ?? 'grey'
