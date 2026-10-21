<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import axios from 'axios'
import { route } from 'ziggy-js'
import PageErrors from '../Components/PageErrors.vue'
import FormDialog, { type FormField } from '../Components/FormDialog.vue'
import FarmFilter from '../Components/FarmFilter.vue'
import { human, money, num, statusColor } from '../Components/agri'

/** Phase 2 §5 for a farm: stores, transfers, holds, reorder, the chemical register, purchasing and suppliers. */
const props = defineProps<{
  tab: 'stores' | 'transfers' | 'reorder' | 'chemicals' | 'purchasing' | 'suppliers'
  farm: null | { id: string; name: string }
  farms: Array<{ id: string; name: string }>
  stores: Array<{ id: string; name: string; kind: string; restricted: boolean; allowed_storage_classes: string[]; capacity: number | null; capacity_unit: string | null }>
  data: any
  options: { items: any[]; suppliers: any[]; kinds: string[]; categories: string[]; storage_classes: string[]; dispute_kinds: string[]; competencies: any[]; people: Array<{ id: number; name: string }> }
  can: { manage: boolean; issue: boolean; finance: boolean }
}>()
const TABS = [
  { key: 'stores', title: 'Stores', icon: 'tabler-building-warehouse' }, { key: 'transfers', title: 'Transfers', icon: 'tabler-truck-delivery' },
  { key: 'reorder', title: 'Reorder', icon: 'tabler-shopping-cart' }, { key: 'chemicals', title: 'Chemicals', icon: 'tabler-flask' },
  { key: 'purchasing', title: 'Purchasing', icon: 'tabler-file-invoice' }, { key: 'suppliers', title: 'Suppliers', icon: 'tabler-building-store' },
]
const keep = { preserveScroll: true }
const go = (tab: string) => router.get(route('agri.stock.control', tab), { farm: props.farm?.id }, { preserveState: false })
const dialog = ref<null | { kind: string; row?: any }>(null)
const open = (kind: string, row: any = null) => { dialog.value = { kind, row } }
const close = (v: boolean) => { if (!v) dialog.value = null }
const opts = (xs: string[]) => xs.map(x => ({ title: human(x), value: x }))
const storeItems = props.stores.map(s => ({ title: `${s.name} (${human(s.kind)})`, value: s.id }))

const storeFields: FormField[] = [
  { key: 'kind', label: 'Kind', type: 'select', required: true, items: opts(props.options.kinds.filter(k => k !== 'main')) }, { key: 'name', label: 'Name', required: true },
  { key: 'capacity', label: 'Capacity', type: 'number', cols: 6 }, { key: 'capacity_unit', label: 'Unit', cols: 6, hint: 't, l, m³' },
  { key: 'allowed_storage_classes', label: 'Chemical storage classes it may hold', type: 'multiselect', items: opts(props.options.storage_classes), cols: 12, hint: 'Leave empty for a store that holds no chemicals' },
  { key: 'restricted', label: 'Locked store: only authorised people issue from it', type: 'switch', cols: 12 },
]
const transferLines = ref<Array<{ item_id: number | null; qty: number | null }>>([{ item_id: null, qty: null }])
const transferForm = ref<{ from_store_id: string | null; to_store_id: string | null; reason: string }>({ from_store_id: props.stores[0]?.id ?? null, to_store_id: null, reason: '' })
const transferOpen = ref(false)
const sendTransfer = () => router.post(route('agri.stock.transfers.store'), { ...transferForm.value, lines: transferLines.value.filter(l => l.item_id && l.qty) },
  { ...keep, onSuccess: () => { transferOpen.value = false; transferLines.value = [{ item_id: null, qty: null }] } })
const step = (o: any, s: 'dispatch' | 'receive') => router.post(route('agri.stock.transfers.step', o.id), { step: s }, keep)

const holdFields: FormField[] = [
  { key: 'store_id', label: 'Store', type: 'select', required: true, items: storeItems },
  { key: 'item_id', label: 'Item', type: 'autocomplete', required: true, items: props.options.items },
  { key: 'qty', label: 'Quantity', type: 'number', required: true, cols: 4 },
  { key: 'from', label: 'From', type: 'select', required: true, items: opts(['AVAILABLE', 'QUARANTINE', 'DAMAGED', 'REJECTED']), cols: 4 },
  { key: 'to', label: 'To', type: 'select', required: true, items: opts(['AVAILABLE', 'QUARANTINE', 'DAMAGED', 'REJECTED']), cols: 4 },
  { key: 'reason', label: 'Why', required: true, cols: 12 },
]
const chemicalFields: FormField[] = [
  { key: 'stock_item_id', label: 'Stock item', type: 'autocomplete', required: true, items: props.options.items, cols: 8 },
  { key: 'registration_no', label: 'Registration no. (e.g. L-number)', cols: 4 }, { key: 'active_ingredient', label: 'Active ingredient', cols: 8 },
  { key: 'formulation', label: 'Formulation', cols: 4 }, { key: 'hazard_class', label: 'Hazard class', cols: 4 },
  { key: 'storage_class', label: 'Storage class', type: 'select', required: true, items: opts(props.options.storage_classes), cols: 4 },
  { key: 'incompatible_with', label: 'May not be stored with', type: 'multiselect', items: opts(props.options.storage_classes), cols: 4 },
  { key: 'ppe', label: 'Protective equipment', cols: 12 }, { key: 'handling', label: 'Handling', type: 'textarea', cols: 12 },
  { key: 'reentry_hours', label: 'Re-entry (h)', type: 'number', cols: 4 }, { key: 'withholding_days', label: 'Withholding (days)', type: 'number', cols: 4 },
  { key: 'registration_expires_on', label: 'Registration expires', type: 'date', cols: 4 },
  { key: 'restricted', label: 'Restricted: only authorised people issue or apply it', type: 'switch', cols: 12 },
  { key: 'competency_code', label: 'Competency required (licence)', type: 'select', items: props.options.competencies, show: (f: any) => f.restricted },
  { key: 'authorised_user_ids', label: 'Or these people', type: 'multiselect', items: props.options.people.map(p => ({ title: p.name, value: p.id })), show: (f: any) => f.restricted },
  { key: 'label', label: 'Product label', type: 'file', cols: 6 }, { key: 'sds', label: 'Safety data sheet (PDF)', type: 'file', cols: 6 },
  { key: 'sds_revised_on', label: 'SDS revised on', type: 'date' },
]
const disputeFields: FormField[] = [
  { key: 'supplier_id', label: 'Supplier', type: 'autocomplete', required: true, items: props.options.suppliers },
  { key: 'kind', label: 'About', type: 'select', required: true, items: opts(props.options.dispute_kinds) },
  { key: 'amount', label: 'Amount in dispute', type: 'number' }, { key: 'description', label: 'What happened', type: 'textarea', required: true, cols: 12 },
]
const settle = (d: any, outcome: 'resolved' | 'rejected') => { const r = prompt(`How was it ${outcome}?`); if (r) router.post(route('agri.stock.disputes.settle', d.uuid), { outcome, resolution: r }, keep) }
const evidence = ref<any>(null)
async function showEvidence(s: any) { evidence.value = { supplier: s, ...(await axios.get(route('agri.stock.suppliers.evidence', s.supplier_uuid))).data } }
const requisition = (r: any) => router.post(route('agri.stock.requisitions.store'), { farm_id: props.farm?.id, justification: `Reorder: ${r.item} — ${r.days_left} days left at ${r.per_day}/day`,
  lines: [{ stock_item_id: r.item_id, quantity: r.suggest }] }, keep)
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div>
        <div class="text-body-2 mb-1"><Link :href="route('agri.stock.index', { farm: farm?.id })">Farm stores</Link> / {{ farm?.name }}</div>
        <h4 class="text-h4 mb-1">Stock control & purchasing</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">Every change is a stock movement; nothing is edited after the fact.</p>
      </div>
      <FarmFilter :farms="farms" :model-value="farm?.id" :allow-all="false" />
    </div>
    <div class="d-flex flex-wrap gap-2 mb-5">
      <VChip v-for="t in TABS" :key="t.key" :color="tab === t.key ? 'primary' : undefined" :variant="tab === t.key ? 'flat' : 'tonal'" :prepend-icon="t.icon" @click="go(t.key)">{{ t.title }}</VChip>
    </div>

    <template v-if="tab === 'stores'">
      <div class="d-flex gap-2 mb-3"><VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="open('store')">Store</VBtn>
        <VBtn v-if="can.manage" variant="tonal" prepend-icon="tabler-lock" @click="open('hold')">Hold or release stock</VBtn></div>
      <VRow>
        <VCol v-for="v in data.valuation" :key="v.id" cols="12" sm="6" md="4">
          <VCard>
            <VCardItem><VCardTitle>{{ v.name }}</VCardTitle><VCardSubtitle>{{ human(v.kind) }} · {{ v.items }} item(s)</VCardSubtitle>
              <template #append><VBtn v-if="can.manage" icon="tabler-edit" size="small" variant="text" @click="open('store', stores.find(s => s.id === v.id))" /></template></VCardItem>
            <VCardText><div class="text-h5">{{ money(v.value) }}</div>
              <VChip v-for="(val, st) in v.by_status" :key="st" size="x-small" class="me-1" :color="st === 'AVAILABLE' ? undefined : 'warning'">{{ human(String(st).toLowerCase()) }} {{ money(val) }}</VChip></VCardText>
          </VCard>
        </VCol>
      </VRow>
      <VCard v-if="data.held.length" title="Stock on hold" class="mt-4">
        <VTable density="compact"><tbody><tr v-for="(h, i) in data.held" :key="i"><td>{{ h.store }}</td><td>{{ h.item }}</td><td class="text-end">{{ num(h.qty) }} {{ h.uom }}</td>
          <td><VChip size="x-small" color="warning">{{ human(h.status.toLowerCase()) }}</VChip></td></tr></tbody></VTable>
      </VCard>
    </template>

    <template v-if="tab === 'transfers'">
      <div class="d-flex gap-2 mb-3"><VBtn v-if="can.issue && stores.length > 1" color="primary" prepend-icon="tabler-plus" @click="transferOpen = true">Transfer</VBtn>
        <span v-if="stores.length < 2" class="text-body-2 text-medium-emphasis align-self-center">Add a second store to move stock between stores.</span></div>
      <VCard>
        <VTable density="compact">
          <thead><tr><th>Transfer</th><th>From → to</th><th>Lines</th><th>Status</th><th /></tr></thead>
          <tbody>
            <tr v-for="o in data.orders" :key="o.id"><td>{{ o.number }}<div class="text-caption">{{ o.created }}<template v-if="o.reason"> · {{ o.reason }}</template></div></td>
              <td>{{ o.from }} → {{ o.to }}</td>
              <td><div v-for="(l, i) in o.lines" :key="i" class="text-caption">{{ num(l.qty) }} {{ l.item }}<template v-if="l.dispatched"> · sent {{ num(l.dispatched) }}</template><template v-if="l.received"> · arrived {{ num(l.received) }}</template></div></td>
              <td><VChip size="x-small" :color="statusColor(o.status.toLowerCase())">{{ human(o.status.toLowerCase()) }}</VChip></td>
              <td class="text-end"><template v-if="can.issue">
                <VBtn v-if="['APPROVED', 'PICKED'].includes(o.status)" size="x-small" variant="tonal" @click="step(o, 'dispatch')">Dispatch</VBtn>
                <VBtn v-if="['DISPATCHED', 'PARTIALLY_RECEIVED'].includes(o.status)" size="x-small" color="success" variant="tonal" @click="step(o, 'receive')">Confirm received</VBtn></template></td></tr>
            <tr v-if="!data.orders.length"><td colspan="5" class="text-center text-medium-emphasis py-4">No transfers.</td></tr>
          </tbody>
        </VTable>
      </VCard>
    </template>

    <template v-if="tab === 'reorder'">
      <VCard title="Reorder suggestions" subtitle="From the last 90 days' use, what is in the farm's stores and on order, and the supplier's lead time.">
        <VTable density="compact">
          <thead><tr><th>Item</th><th class="text-end">Use / day</th><th class="text-end">Available</th><th class="text-end">On order</th><th class="text-end">Days left</th><th class="text-end">Lead</th><th class="text-end">Suggest</th><th>Order by</th><th /></tr></thead>
          <tbody>
            <tr v-for="r in data.suggestions" :key="r.item_id"><td>{{ r.item }}</td><td class="text-end">{{ r.per_day }}</td><td class="text-end">{{ num(r.available) }}</td>
              <td class="text-end">{{ num(r.on_order) }}</td><td class="text-end" :class="r.days_left < r.lead_time_days ? 'text-error' : 'text-warning'">{{ r.days_left }}</td>
              <td class="text-end">{{ r.lead_time_days }} d</td><td class="text-end font-weight-medium">{{ num(r.suggest) }} {{ r.uom }}</td><td>{{ r.order_by }}</td>
              <td class="text-end"><VBtn v-if="can.manage && r.suggest > 0" size="x-small" variant="tonal" @click="requisition(r)">Requisition</VBtn></td></tr>
            <tr v-if="!data.suggestions.length"><td colspan="9" class="text-center text-medium-emphasis py-4">Nothing is running short.</td></tr>
          </tbody>
        </VTable>
      </VCard>
    </template>

    <template v-if="tab === 'chemicals'">
      <div class="d-flex gap-2 mb-3"><VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="open('chemical')">Chemical</VBtn></div>
      <VCard>
        <VTable density="compact">
          <thead><tr><th>Product</th><th>Registration</th><th>Storage</th><th>Use</th><th>Documents</th><th /></tr></thead>
          <tbody>
            <tr v-for="c in data.products" :key="c.id"><td><strong>{{ c.item }}</strong><div class="text-caption">{{ c.active_ingredient }}<template v-if="c.hazard_class"> · {{ c.hazard_class }}</template></div></td>
              <td>{{ c.registration_no ?? '—' }}<div v-if="c.registration_expires_on" class="text-caption" :class="c.expired ? 'text-error' : ''">{{ c.expired ? 'expired' : 'until' }} {{ c.registration_expires_on.slice(0, 10) }}</div></td>
              <td>{{ human(c.storage_class) }}<div v-if="c.incompatible_with?.length" class="text-caption">not with {{ c.incompatible_with.map(human).join(', ') }}</div></td>
              <td><VChip v-if="c.restricted" size="x-small" color="error">Restricted{{ c.competency_code ? ' · ' + c.competency_code : '' }}</VChip><span v-else class="text-caption">Anyone with stock rights</span>
                <div class="text-caption"><template v-if="c.reentry_hours">REI {{ c.reentry_hours }}h </template><template v-if="c.withholding_days">· PHI {{ c.withholding_days }}d</template></div></td>
              <td><a v-if="c.label" :href="route('agri.stock.chemicals.file', [c.uuid, 'label'])" target="_blank" class="me-2">Label</a><a v-if="c.sds" :href="route('agri.stock.chemicals.file', [c.uuid, 'sds'])" target="_blank">SDS</a>
                <span v-if="!c.sds" class="text-caption text-error">No SDS</span></td>
              <td class="text-end"><VBtn v-if="can.manage" icon="tabler-edit" size="small" variant="text" @click="open('chemical', c)" /></td></tr>
            <tr v-if="!data.products.length"><td colspan="6" class="text-center text-medium-emphasis py-4">No regulated chemicals registered.</td></tr>
          </tbody>
        </VTable>
      </VCard>
      <p class="text-caption text-medium-emphasis mt-2">A chemical is received or transferred only into a store that allows its storage class and holds nothing incompatible; a restricted one is issued only by a named person or someone holding its competency, and nothing is used after its registration expires.</p>
    </template>

    <template v-if="tab === 'purchasing'">
      <VCard title="Orders for this farm's stores" class="mb-4">
        <VTable density="compact">
          <thead><tr><th>Order</th><th>Supplier</th><th>Lines</th><th>Expected</th><th>Status</th></tr></thead>
          <tbody>
            <tr v-for="o in data.orders" :key="o.id"><td>{{ o.po }}<div class="text-caption">{{ o.ordered }}</div></td><td>{{ o.supplier }}</td>
              <td><div v-for="(l, i) in o.lines" :key="i" class="text-caption">{{ l.name }}: {{ num(l.received) }} / {{ num(l.ordered) }}<span v-if="l.outstanding" class="text-warning"> · {{ num(l.outstanding) }} to come</span></div></td>
              <td :class="o.late ? 'text-error' : ''">{{ o.expected ?? '—' }}</td>
              <td><VChip size="x-small">{{ human(o.status) }}</VChip><VChip v-if="o.backordered" size="x-small" color="warning" class="ms-1">Backorder</VChip><VChip v-if="o.late" size="x-small" color="error" class="ms-1">Late</VChip></td></tr>
            <tr v-if="!data.orders.length"><td colspan="5" class="text-center text-medium-emphasis py-4">No orders deliver to this farm's stores. Raise a requisition from the store or the reorder list.</td></tr>
          </tbody>
        </VTable>
      </VCard>
      <VCard title="Price variance" class="mb-4">
        <VTable density="compact">
          <thead><tr><th>Order</th><th>Item</th><th class="text-end">Ordered at</th><th class="text-end">Invoiced at</th><th class="text-end">Invoice variance</th><th class="text-end">vs last order</th></tr></thead>
          <tbody><tr v-for="(v, i) in data.variance" :key="i"><td>{{ v.po }}<div class="text-caption">{{ v.supplier }} · {{ v.date }}</div></td><td>{{ v.item }}</td>
            <td class="text-end">{{ money(v.ordered_at) }}</td><td class="text-end">{{ v.invoiced_at !== null ? money(v.invoiced_at) : '—' }}</td>
            <td class="text-end" :class="(v.invoice_variance ?? 0) > 0 ? 'text-error' : ''">{{ v.invoice_variance !== null ? money(v.invoice_variance) : '—' }}</td>
            <td class="text-end" :class="(v.change_pct ?? 0) > 0 ? 'text-error' : 'text-success'">{{ v.change_pct !== null ? (v.change_pct > 0 ? '+' : '') + v.change_pct + '%' : '—' }}</td></tr>
            <tr v-if="!data.variance.length"><td colspan="6" class="text-center text-medium-emphasis py-4">No price movements worth a look.</td></tr></tbody>
        </VTable>
      </VCard>
      <VCard title="Supplier disputes">
        <template #append><VBtn v-if="can.manage || can.finance" size="small" variant="tonal" prepend-icon="tabler-plus" @click="open('dispute')">Dispute</VBtn></template>
        <VList lines="two"><VListItem v-for="d in data.disputes" :key="d.id" :title="`${d.supplier} · ${human(d.kind)}${d.amount ? ' · ' + money(d.amount) : ''}`" :subtitle="d.resolution ? `${d.description} — ${d.resolution}` : d.description">
          <template #append><VChip size="x-small" :color="d.status === 'open' ? 'warning' : undefined">{{ human(d.status) }}</VChip>
            <template v-if="d.status === 'open' && (can.manage || can.finance)"><VBtn size="x-small" variant="text" @click="settle(d, 'resolved')">Resolve</VBtn><VBtn size="x-small" variant="text" @click="settle(d, 'rejected')">Reject</VBtn></template></template></VListItem>
          <VListItem v-if="!data.disputes.length" title="No disputes" /></VList>
      </VCard>
    </template>

    <template v-if="tab === 'suppliers'">
      <VCard title="Supplier performance, last 12 months" subtitle="Each score is the average of what was measured; open a supplier to see the deliveries and disputes behind it.">
        <VTable density="compact">
          <thead><tr><th>Supplier</th><th class="text-end">Score</th><th>Measured</th><th class="text-end">Deliveries</th><th class="text-end">Lead time</th><th class="text-end">Disputes</th><th /></tr></thead>
          <tbody>
            <tr v-for="s in data.suppliers" :key="s.supplier_id"><td>{{ s.supplier }}</td>
              <td class="text-end font-weight-medium" :class="(s.score ?? 100) < 70 ? 'text-error' : ''">{{ s.score ?? '—' }}</td>
              <td><VChip v-for="(v, k) in s.score_basis" :key="k" size="x-small" class="me-1" variant="outlined">{{ k }} {{ v }}</VChip></td>
              <td class="text-end">{{ s.deliveries ?? 0 }}</td><td class="text-end">{{ s.avg_lead_time_days !== null && s.avg_lead_time_days !== undefined ? s.avg_lead_time_days + ' d' : '—' }}</td>
              <td class="text-end">{{ s.disputes }}<span v-if="s.open_disputes" class="text-warning"> ({{ s.open_disputes }} open)</span></td>
              <td class="text-end"><VBtn size="x-small" variant="text" @click="showEvidence(s)">Evidence</VBtn></td></tr>
            <tr v-if="!data.suppliers.length"><td colspan="7" class="text-center text-medium-emphasis py-4">No deliveries recorded in the last year.</td></tr>
          </tbody>
        </VTable>
      </VCard>
      <VDialog :model-value="!!evidence" max-width="760" @update:model-value="v => !v && (evidence = null)">
        <VCard v-if="evidence" :title="`Behind ${evidence.supplier.supplier}'s score`">
          <VCardText>
            <div class="text-subtitle-2">Deliveries</div>
            <VTable density="compact"><tbody><tr v-for="d in evidence.deliveries" :key="d.grn_number"><td>{{ d.grn_number }}</td><td>{{ d.po_number }}</td><td>{{ String(d.received_at).slice(0, 10) }}</td>
              <td>expected {{ d.expected_delivery ?? '—' }}</td><td><VChip v-if="d.on_time !== null" size="x-small" :color="d.on_time ? 'success' : 'error'">{{ d.on_time ? 'on time' : 'late' }}</VChip></td>
              <td class="text-caption">{{ d.discrepancy_status ?? '' }} {{ d.inspection_status ?? '' }}</td></tr></tbody></VTable>
            <div class="text-subtitle-2 mt-3">Disputes</div>
            <div v-for="(d, i) in evidence.disputes" :key="i" class="text-body-2">{{ d.raised }} · {{ human(d.kind) }} · {{ human(d.status) }} — {{ d.description }}</div>
            <div v-if="!evidence.disputes.length" class="text-caption">None.</div>
          </VCardText>
        </VCard>
      </VDialog>
    </template>

    <FormDialog v-if="dialog?.kind === 'store'" :model-value="true" :title="dialog.row ? `Edit ${dialog.row.name}` : 'New store'" :method="dialog.row ? 'patch' : 'post'"
      :url="dialog.row ? route('agri.stock.stores.update', dialog.row.id) : route('agri.stock.stores.store')" :fields="dialog.row ? storeFields.slice(1) : storeFields"
      :values="dialog.row ? { ...dialog.row } : { farm_id: farm?.id, kind: 'silo' }" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'hold'" :model-value="true" title="Hold or release stock" :url="route('agri.stock.holds.store')" :fields="holdFields"
      :values="{ store_id: stores[0]?.id, from: 'AVAILABLE', to: 'QUARANTINE' }" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'chemical'" :model-value="true" :title="dialog.row ? `Edit ${dialog.row.item}` : 'Register a chemical'"
      :url="dialog.row ? route('agri.stock.chemicals.update', dialog.row.uuid) : route('agri.stock.chemicals.store')" :fields="chemicalFields"
      :values="dialog.row ? { ...dialog.row, registration_expires_on: dialog.row.registration_expires_on?.slice(0, 10), sds_revised_on: dialog.row.sds_revised_on?.slice(0, 10) } : { storage_class: 'toxic' }"
      @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'dispute'" :model-value="true" title="Supplier dispute" :url="route('agri.stock.disputes.store')" :fields="disputeFields"
      :values="{ farm_id: farm?.id, kind: 'short_delivery' }" @update:model-value="close" />
    <VDialog v-model="transferOpen" max-width="620">
      <VCard title="Transfer between stores">
        <VCardText>
          <VRow dense><VCol cols="6"><VSelect v-model="transferForm.from_store_id" :items="storeItems" label="From *" /></VCol>
            <VCol cols="6"><VSelect v-model="transferForm.to_store_id" :items="storeItems.filter(s => s.value !== transferForm.from_store_id)" label="To *" /></VCol></VRow>
          <VRow v-for="(l, i) in transferLines" :key="i" dense class="align-center">
            <VCol cols="8"><VAutocomplete v-model="l.item_id" :items="options.items" label="Item" density="compact" hide-details /></VCol>
            <VCol cols="3"><VTextField v-model.number="l.qty" type="number" label="Qty" density="compact" hide-details /></VCol>
            <VCol cols="1"><VBtn icon="tabler-x" size="small" variant="text" @click="transferLines.splice(i, 1)" /></VCol>
          </VRow>
          <VBtn size="small" variant="text" prepend-icon="tabler-plus" class="mt-2" @click="transferLines.push({ item_id: null, qty: null })">Line</VBtn>
          <VTextField v-model="transferForm.reason" label="Why" class="mt-2" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="transferOpen = false">Cancel</VBtn>
          <VBtn color="primary" :disabled="!transferForm.from_store_id || !transferForm.to_store_id" @click="sendTransfer">Create transfer</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
