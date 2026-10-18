// Order and task states share one palette across the fulfilment screens.
export const orderColor = (s: string): string | undefined => ({
  NEW: 'secondary', RELEASED: 'primary', ALLOCATED: 'primary', PARTIALLY_ALLOCATED: 'warning', BACKORDERED: 'error',
  PICKING: 'info', PICKED: 'info', PACKED: 'info', PARTIALLY_DISPATCHED: 'warning', DISPATCHED: 'success', DELIVERED: 'success', CANCELLED: 'secondary',
} as Record<string, string>)[s]
