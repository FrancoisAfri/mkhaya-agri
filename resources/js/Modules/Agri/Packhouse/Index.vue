<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PageErrors from '../Components/PageErrors.vue'
import FormDialog, { type FormField } from '../Components/FormDialog.vue'
import FarmFilter from '../Components/FarmFilter.vue'
import { human, money, num, statusColor, today } from '../Components/agri'

/**
 * Phase 2 §8: lots, packhouse operations, storage conditions, dispatch, traceability, recalls, grading standards.
 * Operations are Manufacturing production runs, loads are Warehouse fulfilment orders and shipments, and
 * recalls are Manufacturing recalls; each row links to its record there.
 */
const props = defineProps<{
  tab: 'lots' | 'operations' | 'storage' | 'dispatch' | 'trace' | 'recalls' | 'standards'
  farm: null | { id: string; name: string }
  farms: Array<{ id: string; name: string }>
  stores: Array<{ id: string; name: string; kind: string; temperature_min: number | null; temperature_max: number | null; humidity_min: number | null; humidity_max: number | null }>
  data: any
  query: { lot: string | null }
  options: { kinds: string[]; standards: any[]; items: any[]; crews: any[]; machines: any[]; crop_types: any[] }
  can: { packhouse: boolean; dispatch: boolean; recall: boolean }
}>()
const TABS = [
  { key: 'lots', title: 'Lots', icon: 'tabler-box' }, { key: 'operations', title: 'Grading & packing', icon: 'tabler-package' },
  { key: 'storage', title: 'Storage', icon: 'tabler-temperature' }, { key: 'dispatch', title: 'Orders & dispatch', icon: 'tabler-truck' },
  { key: 'trace', title: 'Trace', icon: 'tabler-route' }, { key: 'recalls', title: 'Recalls', icon: 'tabler-alert-octagon' },
  { key: 'standards', title: 'Grading standards', icon: 'tabler-list-check' },
]
const keep = { preserveScroll: true }
const go = (tab: string, extra: Record<string, any> = {}) => router.get(route('agri.packhouse', tab), { farm: props.farm?.id, ...extra }, { preserveState: false })
const dialog = ref<null | { kind: string; row?: any }>(null)
const open = (kind: string, row: any = null) => { dialog.value = { kind, row } }
const close = (v: boolean) => { if (!v) dialog.value = null }
const storeItems = props.stores.map(s => ({ title: `${s.name} (${human(s.kind)})`, value: s.id }))
const LOT_COLOR: Record<string, string> = { available: 'success', quarantined: 'warning', recalled: 'error', written_off: 'error' }

// lots
const showAll = ref(false)
const moveFields: FormField[] = [{ key: 'store_id', label: 'Store', type: 'select', items: storeItems }, { key: 'location', label: 'Bin / rack / pallet position' }]
const recallFields: FormField[] = [{ key: 'reason', label: 'Why', type: 'textarea', required: true, cols: 12 },
  { key: 'severity', label: 'Severity', type: 'select', required: true, items: ['low', 'medium', 'high'].map(x => ({ title: human(x), value: x })) }]
const writeOff = (l: any) => { const reason = prompt(`Why is ${l.code} written off?`); if (reason) router.post(route('agri.packhouse.lots.write-off', l.uuid), { reason }, keep) }

// operation builder
const lots = computed(() => (props.data.lots ?? []).filter((l: any) => l.status === 'available'))
const op = ref<any>(null)
const newOp = () => {
  op.value = { kind: 'packing', standard_id: null, performed_on: today(), inputs: [{ lot_id: null, quantity: null }],
    outputs: [{ product: '', grade: null, quantity: null, unit: 'ctn', stock_item_id: null, store_id: props.stores[0]?.id ?? null, location: '', best_before: null }],
    packaging: [], quality_checks: [], loss_reason: '', workers: null, labour_minutes: null, labour_cost: null, machine_id: null, machine_hours: null, crew_id: null }
}
const opStandard = computed(() => props.options.standards.find(s => s.id === op.value?.standard_id))
const pickStandard = () => { op.value.quality_checks = (opStandard.value?.checks ?? []).map((c: any) => ({ check: c.check, value: null, note: '' })) }
const inTotal = computed(() => (op.value?.inputs ?? []).reduce((a: number, i: any) => a + (Number(i.quantity) || 0), 0))
const outTotal = computed(() => (op.value?.outputs ?? []).reduce((a: number, o: any) => a + (Number(o.quantity) || 0), 0))
const saveOp = () => router.post(route('agri.packhouse.operations.store'), { farm_id: props.farm?.id, ...op.value,
  inputs: op.value.inputs.filter((i: any) => i.lot_id && i.quantity), packaging: op.value.packaging.filter((p: any) => p.stock_item_id && p.quantity),
  quality_checks: op.value.quality_checks.filter((c: any) => c.value !== null && c.value !== '') }, { ...keep, onSuccess: () => { op.value = null } })
const lotItems = computed(() => (props.data.lots ?? []).filter((l: any) => l.status === 'available')
  .map((l: any) => ({ title: `${l.code} · ${l.product}${l.grade ? ' ' + l.grade : ''} · ${num(l.remaining)} ${l.unit}`, value: l.uuid })))

// storage
const rangeFields: FormField[] = [
  { key: 'temperature_min', label: 'Min °C', type: 'number', cols: 6 }, { key: 'temperature_max', label: 'Max °C', type: 'number', cols: 6 },
  { key: 'humidity_min', label: 'Min humidity %', type: 'number', cols: 6 }, { key: 'humidity_max', label: 'Max humidity %', type: 'number', cols: 6 },
  { key: 'sensor_ref', label: 'Sensor reference', cols: 12, hint: 'What the sensor gateway calls this store' },
]
const readingFields: FormField[] = [{ key: 'temperature', label: 'Temperature °C', type: 'number', cols: 6 }, { key: 'humidity', label: 'Humidity %', type: 'number', cols: 6 },
  { key: 'recorded_at', label: 'When', type: 'datetime', cols: 12 }]
const correct = (r: any) => { const action = prompt(`What was done about it? (${r.deviation})`); if (action) router.post(route('agri.packhouse.readings.correct', r.id), { action }, keep) }

// dispatch
const pickSuggested = (o: any) => {
  const items = o.picks.flatMap((l: any) => l.picks.map((p: any) => ({ order_line_id: l.line_id, lot_id: p.lot_id, quantity: p.quantity })))
  if (!items.length) return alert('Nothing available to pick for this order.')
  const vehicle = prompt('Vehicle registration?') ?? undefined
  const driver = prompt('Driver?') ?? undefined
  router.post(route('agri.packhouse.orders.pick', o.uuid), { items, vehicle, driver }, keep)
}
const verify = (d: any) => {
  const counts: Record<number, number> = {}
  for (const i of d.items) { const c = prompt(`Counted onto the vehicle: ${i.lot} (picked ${i.quantity})`, String(i.quantity)); if (c === null) return; counts[i.id] = Number(c) }
  const seal_no = prompt('Seal number?') ?? undefined
  router.post(route('agri.packhouse.dispatches.verify', d.id), { counts, seal_no }, keep)
}
const send = (d: any) => router.post(route('agri.packhouse.dispatches.dispatch', d.id), {}, keep)
const delivery = ref<any>(null)
const openDelivery = (d: any) => { delivery.value = { d, received_by: '', note: '', pod: null, results: Object.fromEntries(d.items.map((i: any) => [i.id, { accepted: i.quantity, rejected: 0, rejection_reason: '', rejected_to: 'returned' }])) } }
const saveDelivery = () => router.post(route('agri.packhouse.dispatches.deliver', delivery.value.d.id),
  { results: delivery.value.results, received_by: delivery.value.received_by, note: delivery.value.note, pod: delivery.value.pod ?? undefined },
  { ...keep, forceFormData: true, onSuccess: () => { delivery.value = null } })

// trace and recalls
const traceKey = ref(props.query.lot ?? '')

// standards
const std = ref<any>(null)
const newStd = (s: any = null) => { std.value = s ? JSON.parse(JSON.stringify(s)) : { name: '', crop_type_id: null, grades: [{ code: '', name: '', criteria: '' }], checks: [], is_active: true } }
const saveStd = () => router.post(std.value.uuid ? route('agri.packhouse.standards.update', std.value.uuid) : route('agri.packhouse.standards.store'), std.value,
  { ...keep, onSuccess: () => { std.value = null } })
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div>
        <div class="text-body-2 mb-1"><Link :href="route('agri.stock.index', { farm: farm?.id })">Farm stores</Link> / {{ farm?.name }}</div>
        <h4 class="text-h4 mb-1">Packhouse &amp; post-harvest</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">Every lot traces back to its field and forward to its customer.</p>
      </div>
      <FarmFilter :farms="farms" :model-value="farm?.id" :allow-all="false" />
    </div>
    <div class="d-flex flex-wrap gap-2 mb-5">
      <VChip v-for="t in TABS" :key="t.key" :color="tab === t.key ? 'primary' : undefined" :variant="tab === t.key ? 'flat' : 'tonal'" :prepend-icon="t.icon" @click="go(t.key)">{{ t.title }}</VChip>
    </div>

    <template v-if="tab === 'lots'">
      <div class="d-flex gap-2 mb-3 align-center"><VSwitch v-model="showAll" label="Include finished lots" density="compact" hide-details @update:model-value="go('lots', { all: showAll ? 1 : undefined })" /></div>
      <VCard>
        <VTable density="compact">
          <thead><tr><th>Lot</th><th>Product</th><th class="text-end">Remaining</th><th>Where</th><th>Dates</th><th>Status</th><th /></tr></thead>
          <tbody>
            <tr v-for="l in data.lots" :key="l.id">
              <td><a href="#" @click.prevent="go('trace', { lot: l.code })">{{ l.code }}</a><div class="text-caption">{{ human(l.kind) }}</div></td>
              <td>{{ l.product }}<span v-if="l.grade" class="text-caption"> · {{ l.grade }}</span></td>
              <td class="text-end">{{ num(l.remaining) }} / {{ num(l.quantity) }} {{ l.unit }}<div v-if="l.unit_cost" class="text-caption">{{ money(l.unit_cost) }}/{{ l.unit }}</div></td>
              <td>{{ l.store ?? '—' }}<div class="text-caption">{{ [l.location, l.pallet_ref].filter(Boolean).join(' · ') }}</div></td>
              <td class="text-caption">{{ l.produced_on }}<template v-if="l.best_before"><br>best before {{ l.best_before }}</template></td>
              <td><VChip size="x-small" :color="LOT_COLOR[l.status] ?? statusColor(l.status)">{{ human(l.status) }}</VChip></td>
              <td class="text-end text-no-wrap">
                <VBtn v-if="can.packhouse && ['available', 'quarantined'].includes(l.status)" size="x-small" variant="text" @click="open('move', l)">Move</VBtn>
                <VBtn v-if="can.recall && l.status !== 'written_off'" size="x-small" variant="text" color="error" @click="open('recall', l)">Recall</VBtn>
                <VBtn v-if="can.recall && l.status === 'quarantined' && l.remaining > 0" size="x-small" variant="text" color="error" @click="writeOff(l)">Write off</VBtn>
              </td>
            </tr>
            <tr v-if="!data.lots?.length"><td colspan="7" class="text-center text-medium-emphasis py-4">No lots. Harvests kept on the farm become lots.</td></tr>
          </tbody>
        </VTable>
      </VCard>
    </template>

    <template v-if="tab === 'operations'">
      <div class="d-flex flex-wrap align-center gap-2 mb-3"><VBtn v-if="can.packhouse" color="primary" prepend-icon="tabler-plus" @click="go('lots')">New operation (pick lots on the Lots tab)</VBtn>
        <VBtn variant="tonal" prepend-icon="tabler-building-factory-2" :href="data.manufacturing">Production runs (Manufacturing)</VBtn>
        <span class="text-caption text-medium-emphasis">Grading and packing stocked produce is a production run at the farm's packhouse; the lots made are stock lots.</span></div>
      <VCard>
        <VTable density="compact">
          <thead><tr><th>Operation</th><th>Date</th><th class="text-end">In</th><th class="text-end">Out</th><th class="text-end">Loss</th><th>Packaging</th><th class="text-end">Value</th><th>Quality</th></tr></thead>
          <tbody>
            <tr v-for="o in data.operations" :key="o.id"><td>{{ o.reference }}<div class="text-caption">{{ human(o.kind) }}</div>
              <a v-if="o.production_order" :href="`/manufacturing/production/orders/${o.production_order.uuid}`" class="text-caption">Run {{ o.production_order.number }}</a></td><td>{{ String(o.performed_on).slice(0, 10) }}</td>
              <td class="text-end">{{ num(o.input_quantity) }}</td><td class="text-end">{{ num(o.output_quantity) }}</td>
              <td class="text-end">{{ num(o.loss_quantity) }}<div v-if="o.loss_pct !== null" class="text-caption">{{ o.loss_pct }}% · {{ o.loss_reason }}</div></td>
              <td class="text-caption">{{ (o.packaging ?? []).map((p: any) => `${num(p.qty)} ${p.item}`).join(', ') }}</td>
              <td class="text-end">{{ o.output_value ? money(o.output_value) : '—' }}</td>
              <td><VChip size="x-small" :color="o.quality_passed ? 'success' : 'error'">{{ o.quality_passed ? 'Passed' : 'Failed' }}</VChip>
                <div v-for="c in o.quality_checks ?? []" :key="c.check" class="text-caption" :class="c.ok ? '' : 'text-error'">{{ c.check }}: {{ c.value }}</div></td></tr>
            <tr v-if="!data.operations?.length"><td colspan="8" class="text-center text-medium-emphasis py-4">No operations yet.</td></tr>
          </tbody>
        </VTable>
      </VCard>
    </template>

    <template v-if="tab === 'storage'">
      <VRow class="mb-2">
        <VCol v-for="s in stores.filter(x => ['cold_room', 'silo', 'packhouse', 'main', 'other'].includes(x.kind))" :key="s.id" cols="12" sm="6" md="4">
          <VCard>
            <VCardItem><VCardTitle>{{ s.name }}</VCardTitle><VCardSubtitle>{{ human(s.kind) }}</VCardSubtitle>
              <template #append><VBtn v-if="can.packhouse" icon="tabler-adjustments" size="small" variant="text" @click="open('range', s)" /></template></VCardItem>
            <VCardText>
              <div class="text-body-2">{{ s.temperature_min ?? '—' }} – {{ s.temperature_max ?? '—' }} °C · {{ s.humidity_min ?? '—' }} – {{ s.humidity_max ?? '—' }} %</div>
              <VBtn v-if="can.packhouse" size="small" variant="tonal" class="mt-2" prepend-icon="tabler-thermometer" @click="open('reading', s)">Reading</VBtn>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
      <VCard title="Readings" subtitle="A reading out of range stays open until its corrective action is recorded.">
        <VTable density="compact">
          <thead><tr><th>When</th><th>Store</th><th class="text-end">°C</th><th class="text-end">%RH</th><th>Source</th><th>Out of range</th><th /></tr></thead>
          <tbody><tr v-for="r in data.readings" :key="r.id" :class="r.out_of_range && !r.action ? 'bg-error-lighten' : ''"><td>{{ r.at }}</td><td>{{ r.store }}</td>
            <td class="text-end">{{ r.temperature ?? '' }}</td><td class="text-end">{{ r.humidity ?? '' }}</td><td>{{ human(r.source) }}</td>
            <td><span v-if="r.out_of_range" class="text-error">{{ r.deviation }}</span><div v-if="r.action" class="text-caption">Done: {{ r.action }}</div></td>
            <td class="text-end"><VBtn v-if="can.packhouse && r.out_of_range && !r.action" size="x-small" color="error" variant="tonal" @click="correct(r)">Record action</VBtn></td></tr>
            <tr v-if="!data.readings?.length"><td colspan="7" class="text-center text-medium-emphasis py-4">No readings.</td></tr></tbody>
        </VTable>
      </VCard>
    </template>

    <template v-if="tab === 'dispatch'">
      <div class="d-flex flex-wrap align-center gap-2 mb-3"><VBtn v-if="can.dispatch" color="primary" prepend-icon="tabler-plus" :href="data.new_order">Sales order (Accounting)</VBtn>
        <VBtn variant="tonal" prepend-icon="tabler-file-certificate" :href="route('agri.sales', { tab: 'contracts', farm: farm?.id })">Contracts &amp; market</VBtn>
        <VBtn variant="tonal" prepend-icon="tabler-truck-delivery" :href="data.warehouse?.orders">Outbound (Warehouse)</VBtn>
        <span class="text-caption text-medium-emphasis">Orders are Accounting's sales orders charged to this farm; each load is a warehouse order, picked onto a pallet, counted and shipped.</span></div>
      <VCard title="Orders" class="mb-4">
        <VTable density="compact">
          <thead><tr><th>Order</th><th>Buyer</th><th>Lines</th><th>Pick list (first to expire first)</th><th>Status</th><th /></tr></thead>
          <tbody>
            <tr v-for="o in data.orders" :key="o.id"><td>{{ o.reference }}<div class="text-caption">by {{ String(o.deliver_on).slice(0, 10) }}</div></td>
              <td>{{ o.buyer }}<div class="text-caption">{{ o.delivery_address }}</div></td>
              <td><div v-for="l in o.lines" :key="l.id" class="text-caption">{{ num(l.quantity) }} {{ l.unit }} {{ l.product }} {{ l.grade }} @ {{ money(l.price) }}<template v-if="l.dispatched"> · sent {{ num(l.dispatched) }}</template><template v-if="l.rejected"> · rejected {{ num(l.rejected) }}</template></div></td>
              <td><div v-for="p in o.picks" :key="p.line_id" class="text-caption">
                <span v-for="x in p.picks" :key="x.lot_id" class="me-2">{{ x.lot }} × {{ num(x.quantity) }}<template v-if="x.location"> ({{ x.location }})</template></span>
                <span v-if="p.short" class="text-error">short {{ num(p.short) }}</span></div></td>
              <td><VChip size="x-small" :color="statusColor(o.status)">{{ human(o.status) }}</VChip></td>
              <td class="text-end"><VBtn v-if="can.dispatch && ['open', 'picking'].includes(o.status) && o.picks.some((p: any) => p.picks.length)" size="x-small" variant="tonal" @click="pickSuggested(o)">Pick</VBtn></td></tr>
            <tr v-if="!data.orders?.length"><td colspan="6" class="text-center text-medium-emphasis py-4">No orders.</td></tr>
          </tbody>
        </VTable>
      </VCard>
      <VCard title="Loads" class="mb-4">
        <VTable density="compact">
          <thead><tr><th>Dispatch</th><th>Order</th><th>Lots</th><th>Vehicle</th><th>Status</th><th /></tr></thead>
          <tbody>
            <tr v-for="d in data.dispatches" :key="d.id"><td><a :href="d.warehouse_url">{{ d.reference }}</a><div v-if="d.shipment" class="text-caption">{{ d.shipment.number }}</div></td><td>{{ d.order }}<div class="text-caption">{{ d.buyer }}</div></td>
              <td><div v-for="i in d.items" :key="i.id" class="text-caption">{{ i.lot }} × {{ num(i.quantity) }} {{ i.unit }}</div></td>
              <td class="text-caption">{{ d.vehicle }}<template v-if="d.seal_no"><br>seal {{ d.seal_no }}</template></td>
              <td><VChip size="x-small" :color="statusColor(d.status)">{{ human(d.status) }}</VChip>
                <div v-if="d.shipment?.delivered_at" class="text-caption">{{ d.shipment.delivered_at }} · {{ d.shipment.received_by }}</div></td>
              <td class="text-end text-no-wrap"><template v-if="can.dispatch">
                <VBtn v-if="d.status === 'picking'" size="x-small" variant="tonal" @click="verify(d)">Count onto vehicle</VBtn>
                <VBtn v-if="d.status === 'loaded'" size="x-small" color="primary" variant="tonal" @click="send(d)">Dispatch</VBtn>
                <VBtn v-if="d.status === 'dispatched'" size="x-small" color="success" variant="tonal" @click="openDelivery(d)">Delivered</VBtn></template>
                <a v-if="d.shipment?.has_pod" :href="`/warehouse/outbound/shipments/${d.shipment.id}/pod`" class="text-caption ms-2">POD</a></td></tr>
            <tr v-if="!data.dispatches?.length"><td colspan="6" class="text-center text-medium-emphasis py-4">No loads.</td></tr>
          </tbody>
        </VTable>
      </VCard>
      <VCard v-if="data.exceptions?.length" title="Shortages and rejections">
        <VTable density="compact"><tbody><tr v-for="(x, i) in data.exceptions" :key="i"><td>{{ x.delivered_at }}</td><td>{{ x.dispatch }}</td><td>{{ x.buyer }}</td><td>{{ x.lot }}</td>
          <td class="text-end">sent {{ num(x.sent) }}</td><td class="text-end text-error">{{ x.rejected ? `rejected ${num(x.rejected)}` : '' }} {{ x.short ? `short ${num(x.short)}` : '' }}</td>
        </tr></tbody></VTable>
      </VCard>
    </template>

    <template v-if="tab === 'trace'">
      <VCard class="mb-4"><VCardText class="d-flex gap-2">
        <VTextField v-model="traceKey" label="Lot code" density="compact" hide-details style="max-inline-size: 320px" @keyup.enter="go('trace', { lot: traceKey })" />
        <VBtn color="primary" @click="go('trace', { lot: traceKey })">Trace</VBtn></VCardText></VCard>
      <VAlert v-if="query.lot && !data.backward" type="warning" variant="tonal">No lot {{ query.lot }} on this farm.</VAlert>
      <VRow v-if="data.backward">
        <VCol cols="12" md="6"><VCard title="Backward: where it came from">
          <VCardText>
            <div v-for="h in data.backward.harvests" :key="h.lot" class="mb-3">
              <div class="font-weight-medium">Field {{ h.field }} · {{ h.crop }} ({{ h.crop_plan }})</div>
              <div class="text-body-2">Harvest lot {{ h.lot }} on {{ h.harvested_on }}<template v-if="h.inspection"> · inspection {{ h.inspection }}</template><template v-if="h.containers"> · {{ h.containers }}</template></div>
              <div v-for="(x, i) in h.inputs" :key="i" class="text-caption">{{ x.applied_on }} · {{ x.activity }}: {{ num(x.quantity) }} {{ x.uom }} {{ x.item }}<template v-if="x.withholding_days"> (withholding {{ x.withholding_days }} d)</template></div>
            </div>
            <div v-for="o in data.backward.operations" :key="o.reference" class="text-body-2">{{ o.on }} · {{ o.reference }} {{ human(o.kind) }} · loss {{ num(o.loss) }} · quality {{ o.quality_passed ? 'passed' : 'failed' }}</div>
            <div v-for="(s, i) in data.backward.storage_events" :key="i" class="text-caption text-error">{{ s.at }} · {{ s.deviation }}<template v-if="s.action"> — {{ s.action }}</template></div>
          </VCardText></VCard></VCol>
        <VCol cols="12" md="6"><VCard title="Forward: where it went">
          <VTable density="compact"><tbody><tr v-for="l in data.forward.lots" :key="l.id"><td>{{ l.code }}<div class="text-caption">{{ human(l.kind) }}</div></td>
            <td>{{ num(l.remaining) }} / {{ num(l.quantity) }} {{ l.unit }}</td><td>{{ l.store ?? '' }} {{ l.location ?? '' }}</td>
            <td><VChip size="x-small" :color="LOT_COLOR[l.status] ?? statusColor(l.status)">{{ human(l.status) }}</VChip></td></tr></tbody></VTable>
          <VCardText><div v-for="d in data.forward.deliveries" :key="d.dispatch" class="text-body-2 mb-1"><strong>{{ d.buyer }}</strong> · {{ d.dispatch }} ({{ d.order }}) · {{ human(d.status ?? '') }} {{ d.delivered_at ?? d.dispatched_at ?? '' }}
            <div class="text-caption">{{ d.address }} — {{ d.lots.map((x: any) => `${x.lot} × ${x.quantity}`).join(', ') }}</div></div>
            <div v-if="!data.forward.deliveries.length" class="text-medium-emphasis">Not dispatched to anyone yet.</div></VCardText></VCard></VCol>
      </VRow>
    </template>

    <template v-if="tab === 'recalls'">
      <div class="d-flex flex-wrap align-center gap-2 mb-3"><VBtn variant="tonal" prepend-icon="tabler-alert-octagon" :href="data.manufacturing">All recalls (Manufacturing)</VBtn>
        <span class="text-caption text-medium-emphasis">A recall is opened from a lot and run in Manufacturing: it traces every lot and shipment, holds what is in stock, and is released, disposed of and closed there.</span></div>
      <VCard>
        <VTable density="compact">
          <thead><tr><th>Recall</th><th>Reason</th><th class="text-end">Lots</th><th>Status</th><th /></tr></thead>
          <tbody>
            <tr v-for="r in data.recalls" :key="r.id"><td>{{ r.reference }}<div class="text-caption">{{ r.opened_at }} · {{ human(r.severity) }}</div></td>
              <td>{{ r.title }}<div class="text-caption">{{ r.reason }}</div></td>
              <td class="text-end">{{ r.lots }}</td>
              <td><VChip size="x-small" :color="r.status === 'closed' ? 'success' : 'error'">{{ human(r.status) }}</VChip></td>
              <td class="text-end"><VBtn size="x-small" variant="tonal" :href="r.url">Open</VBtn></td></tr>
            <tr v-if="!data.recalls?.length"><td colspan="5" class="text-center text-medium-emphasis py-4">No recalls. Start one from a lot.</td></tr>
          </tbody>
        </VTable>
      </VCard>
    </template>

    <template v-if="tab === 'standards'">
      <div class="d-flex gap-2 mb-3"><VBtn v-if="can.packhouse" color="primary" prepend-icon="tabler-plus" @click="newStd()">Standard</VBtn></div>
      <VRow>
        <VCol v-for="s in data.standards" :key="s.id" cols="12" md="6"><VCard>
          <VCardItem><VCardTitle>{{ s.name }}</VCardTitle><VCardSubtitle>v{{ s.version }}{{ s.is_active ? '' : ' · inactive' }}</VCardSubtitle>
            <template #append><VBtn v-if="can.packhouse" icon="tabler-edit" size="small" variant="text" @click="newStd(s)" /></template></VCardItem>
          <VCardText><div v-for="g in s.grades" :key="g.code" class="text-body-2"><strong>{{ g.code }}</strong> {{ g.name }} <span class="text-caption">{{ g.criteria }}</span></div>
            <div v-for="c in s.checks ?? []" :key="c.check" class="text-caption">Check {{ c.check }}: {{ c.min ?? '—' }} – {{ c.max ?? '—' }} {{ c.unit }}</div></VCardText></VCard></VCol>
      </VRow>
    </template>

    <!-- operation from the lots tab -->
    <div v-if="tab === 'lots' && can.packhouse" class="mt-4"><VBtn color="primary" prepend-icon="tabler-package" :disabled="!lots.length" @click="newOp">Grade, pack or process</VBtn></div>
    <VDialog :model-value="!!op" max-width="980" @update:model-value="v => { if (!v) op = null }">
      <VCard v-if="op" title="Grading, packing or processing">
        <VCardText>
          <VRow dense>
            <VCol cols="4"><VSelect v-model="op.kind" :items="options.kinds.map(k => ({ title: human(k), value: k }))" label="Operation" /></VCol>
            <VCol cols="4"><VSelect v-model="op.standard_id" :items="options.standards.map(s => ({ title: s.name, value: s.id }))" label="Grading standard" clearable @update:model-value="pickStandard" /></VCol>
            <VCol cols="4"><VTextField v-model="op.performed_on" type="date" label="Date" /></VCol>
          </VRow>
          <div class="text-subtitle-2 mt-2">In ({{ num(inTotal) }})</div>
          <VRow v-for="(i, k) in op.inputs" :key="'i' + k" dense class="align-center">
            <VCol cols="8"><VAutocomplete v-model="i.lot_id" :items="lotItems" label="Lot" density="compact" hide-details /></VCol>
            <VCol cols="3"><VTextField v-model.number="i.quantity" type="number" label="Quantity" density="compact" hide-details /></VCol>
            <VCol cols="1"><VBtn icon="tabler-x" size="small" variant="text" @click="op.inputs.splice(k, 1)" /></VCol>
          </VRow>
          <VBtn size="small" variant="text" prepend-icon="tabler-plus" @click="op.inputs.push({ lot_id: null, quantity: null })">Lot in</VBtn>
          <div class="text-subtitle-2 mt-2">Out ({{ num(outTotal) }})</div>
          <VRow v-for="(o, k) in op.outputs" :key="'o' + k" dense class="align-center">
            <VCol cols="3"><VTextField v-model="o.product" label="Product" density="compact" hide-details /></VCol>
            <VCol cols="2"><VCombobox v-model="o.grade" :items="opStandard?.grades?.map((g: any) => g.code) ?? []" label="Grade" density="compact" hide-details /></VCol>
            <VCol cols="1"><VTextField v-model.number="o.quantity" type="number" label="Qty" density="compact" hide-details /></VCol>
            <VCol cols="1"><VTextField v-model="o.unit" label="Unit" density="compact" hide-details /></VCol>
            <VCol cols="2"><VAutocomplete v-model="o.stock_item_id" :items="options.items" label="Stock item" density="compact" hide-details /></VCol>
            <VCol cols="2"><VSelect v-model="o.store_id" :items="storeItems" label="Store" density="compact" hide-details /></VCol>
            <VCol cols="1"><VBtn icon="tabler-x" size="small" variant="text" @click="op.outputs.splice(k, 1)" /></VCol>
            <VCol cols="3" offset="3"><VTextField v-model="o.location" label="Location" density="compact" hide-details /></VCol>
            <VCol cols="3"><VTextField v-model="o.best_before" type="date" label="Best before" density="compact" hide-details /></VCol>
          </VRow>
          <VBtn size="small" variant="text" prepend-icon="tabler-plus" @click="op.outputs.push({ product: op.outputs[0]?.product ?? '', grade: null, quantity: null, unit: op.outputs[0]?.unit ?? 'ctn', stock_item_id: null, store_id: stores[0]?.id ?? null, location: '', best_before: null })">Lot out</VBtn>
          <div class="text-subtitle-2 mt-2">Packaging used</div>
          <VRow v-for="(p, k) in op.packaging" :key="'p' + k" dense class="align-center">
            <VCol cols="8"><VAutocomplete v-model="p.stock_item_id" :items="options.items" label="Item" density="compact" hide-details /></VCol>
            <VCol cols="3"><VTextField v-model.number="p.quantity" type="number" label="Quantity" density="compact" hide-details /></VCol>
            <VCol cols="1"><VBtn icon="tabler-x" size="small" variant="text" @click="op.packaging.splice(k, 1)" /></VCol>
          </VRow>
          <VBtn size="small" variant="text" prepend-icon="tabler-plus" @click="op.packaging.push({ stock_item_id: null, quantity: null })">Packaging</VBtn>
          <template v-if="op.quality_checks.length"><div class="text-subtitle-2 mt-2">Quality checks</div>
            <VRow v-for="c in op.quality_checks" :key="c.check" dense><VCol cols="4" class="align-self-center">{{ c.check }}</VCol>
              <VCol cols="3"><VTextField v-model="c.value" label="Value" density="compact" hide-details /></VCol><VCol cols="5"><VTextField v-model="c.note" label="Note" density="compact" hide-details /></VCol></VRow></template>
          <VRow dense class="mt-2">
            <VCol cols="6"><VTextField v-model="op.loss_reason" label="Why the loss" density="compact" :hint="`Loss ${num(Math.max(0, inTotal - outTotal))} if the units match`" persistent-hint /></VCol>
            <VCol cols="3"><VSelect v-model="op.crew_id" :items="options.crews" label="Crew" density="compact" clearable /></VCol>
            <VCol cols="3"><VTextField v-model.number="op.workers" type="number" label="Workers" density="compact" /></VCol>
            <VCol cols="3"><VTextField v-model.number="op.labour_minutes" type="number" label="Labour minutes" density="compact" /></VCol>
            <VCol cols="3"><VTextField v-model.number="op.labour_cost" type="number" label="Labour cost" density="compact" /></VCol>
            <VCol cols="3"><VSelect v-model="op.machine_id" :items="options.machines" label="Equipment" density="compact" clearable /></VCol>
            <VCol cols="3"><VTextField v-model.number="op.machine_hours" type="number" label="Machine hours" density="compact" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="op = null">Cancel</VBtn><VBtn color="primary" @click="saveOp">Record</VBtn></VCardActions>
      </VCard>
    </VDialog>


    <VDialog :model-value="!!delivery" max-width="820" @update:model-value="v => { if (!v) delivery = null }">
      <VCard v-if="delivery" :title="`Delivery of ${delivery.d.reference}`">
        <VCardText>
          <VRow v-for="i in delivery.d.items" :key="i.id" dense class="align-center">
            <VCol cols="3">{{ i.lot }} <span class="text-caption">sent {{ num(i.quantity) }}</span></VCol>
            <VCol cols="2"><VTextField v-model.number="delivery.results[i.id].accepted" type="number" label="Accepted" density="compact" hide-details /></VCol>
            <VCol cols="2"><VTextField v-model.number="delivery.results[i.id].rejected" type="number" label="Rejected" density="compact" hide-details /></VCol>
            <template v-if="delivery.results[i.id].rejected > 0">
              <VCol cols="3"><VTextField v-model="delivery.results[i.id].rejection_reason" label="Why" density="compact" hide-details /></VCol>
              <VCol cols="2"><VSelect v-model="delivery.results[i.id].rejected_to" :items="[{ title: 'Came back', value: 'returned' }, { title: 'Written off', value: 'written_off' }, { title: 'Buyer kept', value: 'buyer_kept' }]" density="compact" hide-details /></VCol>
            </template>
          </VRow>
          <div class="text-caption mt-2">Anything neither accepted nor rejected is recorded as short.</div>
          <VRow dense class="mt-2"><VCol cols="6"><VTextField v-model="delivery.received_by" label="Received by" /></VCol><VCol cols="6"><VTextField v-model="delivery.note" label="Note" /></VCol>
            <VCol cols="12"><VFileInput label="Proof of delivery (signed note or photo)" prepend-icon="" prepend-inner-icon="tabler-paperclip" density="compact"
              @update:model-value="(f: any) => { delivery.pod = Array.isArray(f) ? f[0] : f }" /></VCol></VRow>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="delivery = null">Cancel</VBtn><VBtn color="success" @click="saveDelivery">Confirm delivery</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog :model-value="!!std" max-width="760" @update:model-value="v => { if (!v) std = null }">
      <VCard v-if="std" title="Grading standard">
        <VCardText>
          <VRow dense><VCol cols="8"><VTextField v-model="std.name" label="Name *" /></VCol>
            <VCol cols="4"><VAutocomplete v-model="std.crop_type_id" :items="options.crop_types" label="Crop" clearable /></VCol></VRow>
          <div class="text-subtitle-2">Grades</div>
          <VRow v-for="(g, k) in std.grades" :key="'g' + k" dense><VCol cols="3"><VTextField v-model="g.code" label="Code" density="compact" hide-details /></VCol>
            <VCol cols="3"><VTextField v-model="g.name" label="Name" density="compact" hide-details /></VCol><VCol cols="5"><VTextField v-model="g.criteria" label="Criteria" density="compact" hide-details /></VCol>
            <VCol cols="1"><VBtn icon="tabler-x" size="small" variant="text" @click="std.grades.splice(k, 1)" /></VCol></VRow>
          <VBtn size="small" variant="text" prepend-icon="tabler-plus" @click="std.grades.push({ code: '', name: '', criteria: '' })">Grade</VBtn>
          <div class="text-subtitle-2 mt-2">Quality checks</div>
          <VRow v-for="(c, k) in std.checks" :key="'c' + k" dense><VCol cols="4"><VTextField v-model="c.check" label="Check" density="compact" hide-details /></VCol>
            <VCol cols="2"><VTextField v-model="c.unit" label="Unit" density="compact" hide-details /></VCol><VCol cols="2"><VTextField v-model.number="c.min" type="number" label="Min" density="compact" hide-details /></VCol>
            <VCol cols="2"><VTextField v-model.number="c.max" type="number" label="Max" density="compact" hide-details /></VCol><VCol cols="1"><VBtn icon="tabler-x" size="small" variant="text" @click="std.checks.splice(k, 1)" /></VCol></VRow>
          <VBtn size="small" variant="text" prepend-icon="tabler-plus" @click="(std.checks ??= []).push({ check: '', unit: '', min: null, max: null })">Check</VBtn>
          <VSwitch v-model="std.is_active" label="In use" density="compact" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="std = null">Cancel</VBtn><VBtn color="primary" @click="saveStd">Save</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <FormDialog v-if="dialog?.kind === 'move'" :model-value="true" :title="`Move ${dialog.row.code}`" :url="route('agri.packhouse.lots.move', dialog.row.uuid)" :fields="moveFields"
      :values="{ store_id: stores.find(s => s.name === dialog?.row.store)?.id, location: dialog.row.location }" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'recall'" :model-value="true" :title="`Recall from ${dialog.row.code}`" :url="route('agri.packhouse.lots.recall', dialog.row.uuid)" :fields="recallFields"
      :values="{ severity: 'high' }" intro="Every lot made from this one is traced; what is still on hand is quarantined, and every customer who has some is listed." @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'range'" :model-value="true" :title="`${dialog.row.name}: ranges`" :url="route('agri.packhouse.stores.range', dialog.row.id)" :fields="rangeFields"
      :values="{ ...dialog.row }" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'reading'" :model-value="true" :title="`${dialog.row.name}: reading`" :url="route('agri.packhouse.stores.readings', dialog.row.id)" :fields="readingFields"
      :values="{}" @update:model-value="close" />
  </div>
</template>
