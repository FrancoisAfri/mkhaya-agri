export type RecallLotRow = { id: number; lot: string | null; item: string; uom: string; depth: number; in_stock_at_open: number; dispatched_at_open: number; held: number; released: number; disposed: number; retrieved: number; outstanding: number }
export type RecallPayload = {
  recall: { id: string; number: string; title: string; reason: string; severity: string; status: string; scope: string; item: string | null; serial_from: string | null; serial_to: string | null; opened_at: string; closed_at: string | null; closure_notes: string | null }
  lots: RecallLotRow[]
  actions: Array<{ kind: string; qty: number | null; reference: string | null; notes: string | null; at: string }>
  impact: {
    stock: Array<{ item: string; name: string; uom: string; warehouse: string; bin: string | null; bin_kind: string | null; lot: string | null; status: string; qty: number; in_transit: boolean; held: boolean }>
    production: Array<{ order: string; status: string; qty: number }>
    shipments: Array<{ shipment: string; dispatched_at: string | null; status: string; order: string; customer: string | null; ship_to: unknown; item: string; lot: string | null; qty: number }>
    customers: Array<{ customer: string; shipments: string[]; qty: number; notified: boolean }>
    queue: Array<{ kind: string; text: string }>
    totals: { here: number; held: number; shipped: number }
  }
  evidence: Array<{ id: string; kind: string; name: string; caption: string | null; by: string | null; at: string }>
}
