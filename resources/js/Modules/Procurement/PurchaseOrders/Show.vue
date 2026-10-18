<script setup lang="ts">
import { ref, reactive, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Item = { id: number; name: string; quantity: number; unit_price: number; line_total: number; received_qty: number; is_asset: boolean }
type Delivery = { id: number; delivery_number: string; supplier_ref: string | null; delivered_at: string | null; notes: string | null }
type GrnItem = { purchase_order_item_id: number; accepted_qty: number; rejected_qty: number; condition: string }
type Grn = { id: number; grn_number: string; status: string; received_value: number; received_at: string | null; notes: string | null; items: GrnItem[] }
type Amendment = { id: number; type: string; old_amount: number | null; new_amount: number | null; detail: string | null; reason: string | null; created_at: string | null }
type AssetLink = { id: number; asset_ref: string | null; name: string | null; value: number | null }
type InvLink = { id: number; inventory_ref: string | null; name: string | null; quantity: number }
type Snapshot = { allocated: number; reserved: number; committed: number; actual: number; overrides: number; available: number }

const props = defineProps<{
  order: {
    id: number; po_number: string; title: string; amount: number; currency: string | null
    status: string; received_status: string; source: string
    order_date: string | null; expected_delivery: string | null; delivered_at: string | null
    supplier: { company_name: string } | null
    requisition: { requisition_number: string } | null
    budget: { code: string; name: string } | null
    items: Item[]; deliveryNotes: Delivery[]; goodsReceivedNotes: Grn[]
    amendments: Amendment[]; assetLinks: AssetLink[]; inventoryLinks: InvLink[]
  }
  budgetSnapshot: Snapshot | null
}>()

const confirmDialog = ref()
const o = computed(() => props.order)
const TERMINAL = ['cancelled']
const canCancel = computed(() => !TERMINAL.includes(o.value.status))
const canApprove = computed(() => o.value.status === 'draft')
const canSend = computed(() => o.value.status === 'approved')
const canReceive = computed(() => ['approved', 'sent'].includes(o.value.status) && o.value.received_status !== 'received')

// Delivery note form
const delivery = reactive({ supplier_ref: '', delivered_at: '', notes: '' })
// GRN receipt: per-item accepted/rejected/condition
const grnLines = reactive<Record<number, { accepted_qty: number; rejected_qty: number; condition: string; note: string }>>({})
o.value.items.forEach(it => {
  grnLines[it.id] = { accepted_qty: Math.max(0, Number(it.quantity) - Number(it.received_qty)), rejected_qty: 0, condition: 'good', note: '' }
})
const grnNotes = ref('')

// Amendment form
const amendment = reactive({ type: 'value', new_amount: o.value.amount, detail: '', reason: '' })

function money(v: number | null) {
  if (v == null) return '—'
  try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: o.value.currency || 'ZAR' }).format(v) }
  catch { return `${o.value.currency || ''} ${v}`.trim() }
}

function approve() { router.post(route('purchase-orders.approve', o.value.uuid), {}, { preserveScroll: true }) }
function send() { router.post(route('purchase-orders.send', o.value.uuid), {}, { preserveScroll: true }) }
function cancel() {
  confirmDialog.value.open(
    { title: 'Cancel PO', message: 'Cancel this PO? Any outstanding budget commitment will be released.', confirmText: 'Cancel PO', confirmColor: 'warning', icon: 'tabler-alert-triangle' },
    () => { router.post(route('purchase-orders.cancel', o.value.uuid), {}, { preserveScroll: true }) },
  )
}
function saveDelivery() {
  router.post(route('purchase-orders.delivery', o.value.uuid), { ...delivery }, {
    preserveScroll: true,
    onSuccess: () => { delivery.supplier_ref = ''; delivery.delivered_at = ''; delivery.notes = '' },
  })
}
function receiveGoods() {
  const lines = o.value.items.map(it => ({ item_id: it.id, ...grnLines[it.id] }))
    .filter(l => Number(l.accepted_qty) > 0 || Number(l.rejected_qty) > 0)
  if (!lines.length) { alert('Enter at least one received quantity.'); return }
  router.post(route('purchase-orders.grn', o.value.uuid), { lines, notes: grnNotes.value }, { preserveScroll: true })
}
function submitAmendment() {
  router.post(route('purchase-orders.amend', o.value.uuid), { ...amendment }, { preserveScroll: true })
}

const statusColor: Record<string, string> = { draft: 'grey', approved: 'info', sent: 'primary', cancelled: 'error' }
const recvColor: Record<string, string> = { pending: 'grey', partial: 'warning', received: 'success' }
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div class="d-flex align-center gap-3">
        <VBtn icon="tabler-arrow-left" variant="text" @click="router.get(route('purchase-orders.index'))" />
        <div>
          <h4 class="text-h4 mb-0">{{ o.po_number }}</h4>
          <p class="text-caption text-medium-emphasis mb-0">{{ o.title }}</p>
        </div>
        <VChip :color="statusColor[o.status] || 'grey'" variant="tonal">{{ o.status }}</VChip>
        <VChip :color="recvColor[o.received_status] || 'grey'" variant="tonal">{{ o.received_status }}</VChip>
        <VChip v-if="o.requisition" color="info" variant="tonal" prepend-icon="tabler-link">{{ o.requisition.requisition_number }}</VChip>
      </div>
      <div class="d-flex gap-2">
        <VBtn v-if="canApprove" color="info" prepend-icon="tabler-check" @click="approve">Approve</VBtn>
        <VBtn v-if="canSend" color="primary" prepend-icon="tabler-send" @click="send">Send to supplier</VBtn>
        <VBtn v-if="canCancel" color="error" variant="tonal" prepend-icon="tabler-x" @click="cancel">Cancel</VBtn>
      </div>
    </div>

    <VRow>
      <VCol cols="12" md="7">
        <!-- Items + receipt progress -->
        <VCard border class="mb-4">
          <VCardTitle>Items</VCardTitle>
          <VTable>
            <thead>
              <tr><th>Name</th><th class="text-right">Qty</th><th class="text-right">Received</th><th class="text-right">Unit</th><th class="text-right">Total</th><th>Type</th></tr>
            </thead>
            <tbody>
              <tr v-if="!o.items.length"><td colspan="6" class="text-center text-medium-emphasis py-4">No items.</td></tr>
              <tr v-for="it in o.items" :key="it.id">
                <td class="font-weight-medium">{{ it.name }}</td>
                <td class="text-right">{{ it.quantity }}</td>
                <td class="text-right">{{ it.received_qty }}</td>
                <td class="text-right">{{ money(it.unit_price) }}</td>
                <td class="text-right">{{ money(it.line_total) }}</td>
                <td><VChip size="x-small" :color="it.is_asset ? 'deep-purple' : 'teal'" variant="tonal">{{ it.is_asset ? 'asset' : 'stock' }}</VChip></td>
              </tr>
            </tbody>
            <tfoot v-if="o.items.length">
              <tr><td colspan="4" class="text-right font-weight-medium">Total</td><td class="text-right font-weight-bold">{{ money(o.amount) }}</td><td></td></tr>
            </tfoot>
          </VTable>
        </VCard>

        <!-- Goods receipt (GRN) -->
        <VCard v-if="canReceive" border class="mb-4">
          <VCardTitle>Receive goods (GRN)</VCardTitle>
          <VCardText>
            <VTable density="compact">
              <thead>
                <tr><th>Item</th><th>Outstanding</th><th>Accepted</th><th>Rejected</th><th>Condition</th></tr>
              </thead>
              <tbody>
                <tr v-for="it in o.items" :key="it.id">
                  <td class="font-weight-medium">{{ it.name }}</td>
                  <td>{{ Math.max(0, Number(it.quantity) - Number(it.received_qty)) }}</td>
                  <td style="max-width:110px">
                    <VTextField v-model.number="grnLines[it.id].accepted_qty" type="number" density="compact" variant="outlined" hide-details min="0" />
                  </td>
                  <td style="max-width:110px">
                    <VTextField v-model.number="grnLines[it.id].rejected_qty" type="number" density="compact" variant="outlined" hide-details min="0" />
                  </td>
                  <td style="max-width:140px">
                    <VSelect v-model="grnLines[it.id].condition" :items="['good','damaged']" density="compact" variant="outlined" hide-details />
                  </td>
                </tr>
              </tbody>
            </VTable>
            <VTextarea v-model="grnNotes" label="GRN notes" rows="2" variant="outlined" density="compact" hide-details class="mt-3" />
            <VBtn color="success" class="mt-3" prepend-icon="tabler-package-import" @click="receiveGoods">Confirm receipt</VBtn>
          </VCardText>
        </VCard>

        <!-- Delivery note capture -->
        <VCard border class="mb-4">
          <VCardTitle>Delivery notes</VCardTitle>
          <VCardText>
            <VList v-if="o.deliveryNotes.length" density="compact" class="mb-3">
              <VListItem v-for="d in o.deliveryNotes" :key="d.id" :title="d.delivery_number"
                :subtitle="`${d.delivered_at || ''} ${d.supplier_ref ? '· ref ' + d.supplier_ref : ''}`" prepend-icon="tabler-truck-delivery" />
            </VList>
            <div v-else class="text-medium-emphasis mb-3">No delivery notes captured.</div>
            <div class="d-flex flex-wrap gap-3">
              <VTextField v-model="delivery.supplier_ref" label="Supplier ref" density="compact" variant="outlined" hide-details style="max-width:200px" />
              <VTextField v-model="delivery.delivered_at" type="date" label="Delivered at" density="compact" variant="outlined" hide-details style="max-width:200px" />
              <VTextField v-model="delivery.notes" label="Notes" density="compact" variant="outlined" hide-details style="min-width:200px;flex:1" />
              <VBtn color="primary" variant="tonal" @click="saveDelivery">Capture</VBtn>
            </div>
          </VCardText>
        </VCard>

        <!-- GRN history -->
        <VCard v-if="o.goodsReceivedNotes.length" border class="mb-4">
          <VCardTitle>Goods received</VCardTitle>
          <VTable density="compact">
            <thead><tr><th>GRN #</th><th>Status</th><th class="text-right">Value</th><th>Received at</th></tr></thead>
            <tbody>
              <tr v-for="g in o.goodsReceivedNotes" :key="g.id">
                <td class="font-weight-medium">{{ g.grn_number }}</td>
                <td><VChip size="x-small" :color="g.status === 'complete' ? 'success' : 'warning'" variant="tonal">{{ g.status }}</VChip></td>
                <td class="text-right">{{ money(g.received_value) }}</td>
                <td>{{ g.received_at || '—' }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>

      <VCol cols="12" md="5">
        <!-- Budget snapshot -->
        <VCard v-if="budgetSnapshot" border class="mb-4">
          <VCardTitle class="d-flex align-center justify-space-between">
            <span>Budget</span>
            <VChip v-if="o.budget" size="small" variant="tonal">{{ o.budget.code }}</VChip>
          </VCardTitle>
          <VCardText>
            <div class="d-flex justify-space-between py-1"><span class="text-medium-emphasis">Allocated</span><span>{{ money(budgetSnapshot.allocated) }}</span></div>
            <div class="d-flex justify-space-between py-1"><span class="text-medium-emphasis">Reserved</span><span>{{ money(budgetSnapshot.reserved) }}</span></div>
            <div class="d-flex justify-space-between py-1"><span class="text-medium-emphasis">Committed</span><span>{{ money(budgetSnapshot.committed) }}</span></div>
            <div class="d-flex justify-space-between py-1"><span class="text-medium-emphasis">Actual (spent)</span><span>{{ money(budgetSnapshot.actual) }}</span></div>
            <VDivider class="my-2" />
            <div class="d-flex justify-space-between py-1 font-weight-bold"><span>Available</span><span>{{ money(budgetSnapshot.available) }}</span></div>
          </VCardText>
        </VCard>

        <!-- Asset / inventory links -->
        <VCard v-if="o.assetLinks.length || o.inventoryLinks.length" border class="mb-4">
          <VCardTitle>Created assets &amp; inventory</VCardTitle>
          <VCardText>
            <div v-if="o.assetLinks.length" class="mb-2">
              <div class="text-overline">Assets</div>
              <VList density="compact">
                <VListItem v-for="a in o.assetLinks" :key="a.id" :title="a.name || a.asset_ref || ''"
                  :subtitle="`${a.asset_ref || ''} · ${money(a.value)}`" prepend-icon="tabler-device-laptop" />
              </VList>
            </div>
            <div v-if="o.inventoryLinks.length">
              <div class="text-overline">Inventory</div>
              <VList density="compact">
                <VListItem v-for="iv in o.inventoryLinks" :key="iv.id" :title="iv.name || iv.inventory_ref || ''"
                  :subtitle="`${iv.inventory_ref || ''} · qty ${iv.quantity}`" prepend-icon="tabler-box" />
              </VList>
            </div>
          </VCardText>
        </VCard>

        <!-- Amendments -->
        <VCard border class="mb-4">
          <VCardTitle>Amendments</VCardTitle>
          <VCardText>
            <VList v-if="o.amendments.length" density="compact" class="mb-3">
              <VListItem v-for="a in o.amendments" :key="a.id"
                :title="`${a.type}${a.new_amount != null ? ' → ' + money(a.new_amount) : ''}`"
                :subtitle="a.reason || a.detail || ''" prepend-icon="tabler-edit" />
            </VList>
            <div v-else class="text-medium-emphasis mb-3">No amendments.</div>
            <div v-if="canCancel" class="d-flex flex-column gap-2">
              <VSelect v-model="amendment.type" :items="['value','quantity','other']" label="Type" density="compact" variant="outlined" hide-details />
              <VTextField v-if="amendment.type === 'value'" v-model.number="amendment.new_amount" type="number" label="New amount" density="compact" variant="outlined" hide-details />
              <VTextField v-model="amendment.reason" label="Reason" density="compact" variant="outlined" hide-details />
              <VBtn color="primary" variant="tonal" @click="submitAmendment">Record amendment</VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <ConfirmDialog ref="confirmDialog" />
  </div>
</template>
