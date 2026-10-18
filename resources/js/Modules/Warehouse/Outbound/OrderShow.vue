<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import axios from 'axios'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import RefusalAlert from '../components/RefusalAlert.vue'
import { orderColor } from './statusColor'

const { t } = useI18n()
const route = ziggyRoute

type Pkg = { id: string; sscc: string; type: string; status: string; weight_kg: number | null; tracking_number: string | null; contents: Array<{ id: number; item: string; lot: string | null; qty: number; line_id: number }> }

const props = defineProps<{
  order: Record<string, any>
  lines: Array<Record<string, any>>
  tasks: Array<Record<string, any>>
  packages: Pkg[]
  shipments: Array<Record<string, any>>
  carrier_quote: { parcel: { weight_kg: number; packages: number; zone: string | null }; options: Array<{ carrier: string; carrier_name: string; service: string; price: number; transit_days: number | null }> } | null
  carriers: Array<{ value: string; title: string }>
  tracking_statuses: string[]
  pack_check: Array<{ line: number; item: string; picked: number; packed: number; difference: number }>
  strategies: string[]
  package_types: string[]
  can: { manage: boolean; pick: boolean; pack: boolean; dispatch: boolean; quality: boolean; override: boolean }
}>()

const opts = { preserveScroll: true }
const strategy = ref('DISCRETE')
function act(action: string, extra: Record<string, unknown> = {}) {
  router.post(route('warehouse.outbound.orders.act', props.order.id), { action, ...extra }, opts)
}
const cancelReason = ref('')
const cancelling = ref(false)

// ── Packing ─────────────────────────────────────────────────────────────────
const openPkg = computed(() => props.packages.find(p => p.status === 'OPEN') ?? null)
const packType = ref('CARTON')
const add = useForm({ action: 'add', package: '', line_id: null as number | null, qty: 1, scan: '' })
function pack(action: string, extra: Record<string, unknown> = {}) {
  router.post(route('warehouse.outbound.orders.pack', props.order.id), { action, ...extra }, opts)
}
function addToPackage() {
  if (!openPkg.value) return
  add.package = openPkg.value.id
  add.post(route('warehouse.outbound.orders.pack', props.order.id), { ...opts, onSuccess: () => { add.qty = 1; add.scan = '' } })
}
const closing = ref<Pkg | null>(null)
const closeForm = useForm({ action: 'close', package: '', weight_kg: null as number | null, length_cm: null as number | null, width_cm: null as number | null, height_cm: null as number | null, tracking_number: '' })
function close() {
  if (!closing.value) return
  closeForm.package = closing.value.id
  closeForm.post(route('warehouse.outbound.orders.pack', props.order.id), { ...opts, onSuccess: () => { closing.value = null } })
}
// ── Labels (WMS-152) ────────────────────────────────────────────────────────
const labels = (kind: string, ids: string[]) => router.post(route('warehouse.labels.print'), { kind, ids, output: 'BROWSER' })

// ── Rotation override (WMS-092) ─────────────────────────────────────────────
const overriding = ref<Record<string, any> | null>(null)
const overrideOptions = ref<Array<{ bin_id: number | null; lot_id: number | null; qty: number; title: string }>>([])
const overridePick = ref<number | null>(null)
const overrideForm = useForm({ line_id: 0, bin_id: null as number | null, lot_id: null as number | null, qty: 0, reason: '' })
async function openOverride(l: Record<string, any>) {
  overriding.value = l
  overrideForm.reset()
  overrideForm.line_id = l.id
  overrideForm.qty = l.qty - l.cancelled - l.allocated
  overridePick.value = null
  overrideOptions.value = (await axios.get(route('warehouse.outbound.orders.override.options', props.order.id), { params: { line_id: l.id } })).data
}
function submitOverride() {
  const o = overrideOptions.value[overridePick.value ?? -1]
  if (o) { overrideForm.bin_id = o.bin_id; overrideForm.lot_id = o.lot_id }
  overrideForm.post(route('warehouse.outbound.orders.override', props.order.id), { preserveScroll: true, onSuccess: () => { overriding.value = null } })
}
const packable = computed(() => props.lines.filter(l => l.picked - l.packed > 0).map(l => ({ value: l.id, title: `${l.line_no}. ${l.item} — ${l.picked - l.packed} ${l.uom}` })))

// ── Dispatch ────────────────────────────────────────────────────────────────
const dispatchForm = useForm({ packages: [] as string[], carrier_id: null as string | null, carrier_service: '', carrier: props.order.carrier ?? '', service: '', vehicle_reg: '', driver_name: '', tracking_reference: '', handover_name: '', signature: null as File | null })
const closedPkgs = computed(() => props.packages.filter(p => p.status === 'CLOSED'))
function dispatch() {
  dispatchForm.post(route('warehouse.outbound.orders.dispatch', props.order.id), { ...opts, forceFormData: true, onSuccess: () => dispatchForm.reset() })
}
function chooseQuote(o: { carrier: string; service: string }) {
  dispatchForm.carrier_id = o.carrier
  dispatchForm.carrier_service = o.service
}
const trackFor = ref<string | null>(null)
const trackForm = useForm({ status: 'IN_TRANSIT', occurred_at: '', location: '', detail: '', received_by: '' })
function saveTracking() {
  if (!trackFor.value) return
  trackForm.transform(d => ({ ...d, occurred_at: d.occurred_at || null })).post(route('warehouse.outbound.shipments.tracking', trackFor.value), { ...opts, onSuccess: () => { trackFor.value = null; trackForm.reset() } })
}
const podFor = ref<string | null>(null)
const pod = useForm({ received_by: '', delivered_at: '', note: '', pod: null as File | null })
function savePod() {
  if (!podFor.value) return
  pod.transform(d => ({ ...d, delivered_at: d.delivered_at || null })).post(route('warehouse.outbound.shipments.pod', podFor.value), { ...opts, forceFormData: true, onSuccess: () => { podFor.value = null } })
}
const when = (iso: string | null) => iso ? new Date(iso).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' }) : '—'
const live = computed(() => !['DISPATCHED', 'DELIVERED', 'CANCELLED'].includes(props.order.status))
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <div class="text-caption"><a :href="route('warehouse.outbound.orders.index')">{{ t('warehouse_management.outbound.title') }}</a></div>
        <h4 class="text-h4">{{ order.number }} <VChip size="small" label :color="orderColor(order.status)" variant="tonal">{{ t(`warehouse_management.order_status.${order.status}`) }}</VChip>
          <VChip v-if="order.at_risk" size="small" :color="order.overdue ? 'error' : 'warning'" variant="tonal" class="ms-1">{{ order.overdue ? t('warehouse_management.outbound.late') : t('warehouse_management.outbound.at_risk') }}</VChip></h4>
        <div class="text-body-2 text-medium-emphasis">{{ t(`warehouse_management.order_source.${order.source}`) }} {{ order.external_ref }} · {{ order.customer }} · {{ order.warehouse }}
          · {{ t('warehouse_management.outbound.due') }} {{ when(order.sla_due_at) }}</div>
      </div>
      <div v-if="live" class="d-flex gap-2 flex-wrap">
        <VBtn v-if="can.manage && order.status === 'NEW'" color="primary" @click="act('release')">{{ t('warehouse_management.outbound.release') }}</VBtn>
        <VBtn v-if="can.manage && ['RELEASED', 'PARTIALLY_ALLOCATED', 'BACKORDERED'].includes(order.status)" variant="tonal" @click="act('allocate')">{{ t('warehouse_management.outbound.allocate') }}</VBtn>
        <VBtn v-if="can.manage && ['ALLOCATED', 'PARTIALLY_ALLOCATED', 'PICKING'].includes(order.status)" variant="text" @click="act('reallocate')">{{ t('warehouse_management.outbound.recheck') }}</VBtn>
        <template v-if="can.pick && ['ALLOCATED', 'PARTIALLY_ALLOCATED', 'PICKING'].includes(order.status)">
          <VSelect v-model="strategy" :items="strategies.map(s => ({ value: s, title: t(`warehouse_management.strategy.${s}`) }))" density="compact" hide-details style="max-width: 170px" />
          <VBtn color="primary" variant="tonal" @click="act('pick', { strategy })">{{ t('warehouse_management.outbound.release_pick') }}</VBtn>
        </template>
        <VBtn v-if="can.quality && ['PICKING', 'PICKED', 'PACKED', 'PARTIALLY_DISPATCHED'].includes(order.status)" variant="text" prepend-icon="tabler-flask"
              @click="router.post(route('warehouse.quality.order', order.id), {}, { preserveScroll: true })">{{ t('warehouse_management.outbound.inspect') }}</VBtn>
        <VBtn v-if="can.manage" variant="text" color="error" @click="cancelling = true">{{ t('warehouse_management.common.cancel') }}</VBtn>
      </div>
    </div>
    <RefusalAlert />
    <VAlert v-if="order.cancel_reason" type="warning" variant="tonal" class="mb-3">{{ order.cancel_reason }}</VAlert>

    <div class="d-flex flex-wrap gap-2 mb-4">
      <VChip v-for="(v, k) in order.timeline" :key="k" size="small" :variant="v ? 'tonal' : 'outlined'" :color="v ? 'primary' : undefined">
        {{ t(`warehouse_management.outbound.stage.${k}`) }}<template v-if="v">: {{ when(v) }}</template>
      </VChip>
    </div>

    <VCard border class="mb-4">
      <VCardTitle>{{ t('warehouse_management.outbound.lines') }}</VCardTitle>
      <div style="overflow-x: auto">
        <VTable density="compact">
          <thead><tr><th>#</th><th>{{ t('warehouse_management.movements.item') }}</th><th class="text-end">{{ t('warehouse_management.outbound.ordered') }}</th><th class="text-end">{{ t('warehouse_management.outbound.allocated') }}</th>
            <th class="text-end">{{ t('warehouse_management.outbound.picked') }}</th><th class="text-end">{{ t('warehouse_management.outbound.packed') }}</th><th class="text-end">{{ t('warehouse_management.outbound.dispatched') }}</th><th>{{ t('warehouse_management.outbound.from') }}</th></tr></thead>
          <tbody>
            <tr v-for="l in lines" :key="l.id">
              <td>{{ l.line_no }}</td>
              <td>{{ l.item }}<div class="text-caption text-medium-emphasis">{{ l.name }}<template v-if="l.substitute_of"> · {{ t('warehouse_management.outbound.substitute_for', { n: l.substitute_of }) }}</template></div>
                <div v-if="l.short" class="text-caption text-error">{{ t('warehouse_management.transfers.short', { n: l.short }) }}</div>
                <div v-if="l.cancelled" class="text-caption">{{ t('warehouse_management.outbound.cancelled_qty', { n: l.cancelled }) }}</div></td>
              <td class="text-end">{{ l.qty }} {{ l.uom }}</td><td class="text-end">{{ l.allocated }}</td><td class="text-end">{{ l.picked }}</td><td class="text-end">{{ l.packed }}</td><td class="text-end">{{ l.dispatched }}</td>
              <td class="text-caption"><div v-for="(a, i) in l.allocations" :key="i">{{ a.bin ?? t('warehouse_management.stock.no_bin') }}<template v-if="a.lot"> · {{ a.lot }}<template v-if="a.expires_on"> ({{ a.expires_on }})</template></template> · {{ a.qty }}
                  <VIcon v-if="a.override" icon="tabler-hand-finger" size="12" color="warning" :title="a.override" /></div>
                <VBtn v-if="can.override && l.qty - l.cancelled - l.allocated > 0 && ['RELEASED', 'PARTIALLY_ALLOCATED', 'BACKORDERED', 'ALLOCATED'].includes(order.status)"
                      size="x-small" variant="text" @click="openOverride(l)">{{ t('warehouse_management.outbound.override') }}</VBtn></td>
            </tr>
          </tbody>
        </VTable>
      </div>
    </VCard>

    <VRow>
      <VCol cols="12" lg="6">
        <VCard border class="mb-4">
          <VCardTitle>{{ t('warehouse_management.outbound.picks') }}</VCardTitle>
          <VCardText v-if="!tasks.length" class="text-medium-emphasis">{{ t('warehouse_management.outbound.no_picks') }}</VCardText>
          <VTable v-else density="compact">
            <tbody><tr v-for="tk in tasks" :key="tk.id"><td><a :href="route('warehouse.outbound.waves.show', tk.wave_id)">{{ tk.wave }}</a></td><td>{{ tk.bin }}</td><td>{{ tk.item }}<span v-if="tk.lot" class="text-caption"> · {{ tk.lot }}</span></td>
              <td class="text-end">{{ tk.picked }}/{{ tk.qty }}<span v-if="tk.short" class="text-error"> −{{ tk.short }}</span></td><td><VChip size="x-small" variant="tonal">{{ t(`warehouse_management.pick_status.${tk.status}`) }}</VChip></td></tr></tbody>
          </VTable>
        </VCard>
      </VCol>
      <VCol cols="12" lg="6">
        <VCard border class="mb-4">
          <VCardTitle class="d-flex justify-space-between align-center">{{ t('warehouse_management.outbound.packing') }}
            <VBtn v-if="packages.length" size="small" variant="text" prepend-icon="tabler-printer" :href="route('warehouse.outbound.orders.documents', order.id)">{{ t('warehouse_management.outbound.documents') }}</VBtn>
            <VBtn v-if="packages.length" size="small" variant="text" prepend-icon="tabler-tag" @click="labels('CARTON', packages.map(p => p.id))">{{ t('warehouse_management.outbound.package_labels') }}</VBtn></VCardTitle>
          <VCardText>
            <div v-for="p in packages" :key="p.id" class="mb-3">
              <div class="d-flex align-center gap-2">
                <strong style="font-family: ui-monospace, monospace">{{ p.sscc }}</strong>
                <VChip size="x-small" variant="tonal">{{ t(`warehouse_management.package_type.${p.type}`) }}</VChip>
                <VChip size="x-small" variant="tonal" :color="p.status === 'OPEN' ? 'warning' : p.status === 'CLOSED' ? 'primary' : 'success'">{{ t(`warehouse_management.package_status.${p.status}`) }}</VChip>
                <span v-if="p.weight_kg" class="text-caption">{{ p.weight_kg }} kg</span>
                <VSpacer />
                <template v-if="can.pack">
                  <VBtn v-if="p.status === 'OPEN'" size="x-small" variant="tonal" @click="closing = p; closeForm.reset()">{{ t('warehouse_management.outbound.close_package') }}</VBtn>
                  <VBtn v-if="p.status === 'CLOSED'" size="x-small" variant="text" @click="pack('reopen', { package: p.id })">{{ t('warehouse_management.outbound.reopen') }}</VBtn>
                </template>
              </div>
              <div v-for="c in p.contents" :key="c.id" class="text-caption d-flex align-center gap-1">
                {{ c.qty }} × {{ c.item }}<template v-if="c.lot"> ({{ c.lot }})</template>
                <VBtn v-if="can.pack && p.status === 'OPEN'" icon="tabler-x" size="x-small" variant="text" :aria-label="t('warehouse_management.common.remove')" @click="pack('remove', { package: p.id, content_id: c.id })" />
              </div>
            </div>
            <template v-if="can.pack && live && ['PICKING', 'PICKED', 'PACKED', 'PARTIALLY_DISPATCHED'].includes(order.status)">
              <div v-if="!openPkg" class="d-flex gap-2">
                <VSelect v-model="packType" :items="package_types.map(p => ({ value: p, title: t(`warehouse_management.package_type.${p}`) }))" density="compact" hide-details style="max-width: 160px" />
                <VBtn variant="tonal" prepend-icon="tabler-box" @click="pack('open', { type: packType })">{{ t('warehouse_management.outbound.new_package') }}</VBtn>
              </div>
              <div v-else class="d-flex gap-2 flex-wrap">
                <VSelect v-model="add.line_id" :items="packable" density="compact" hide-details style="min-width: 220px" :label="t('warehouse_management.outbound.line')" />
                <VTextField v-model.number="add.qty" type="number" min="0" density="compact" hide-details style="max-width: 90px" />
                <VTextField v-model="add.scan" prepend-inner-icon="tabler-scan" density="compact" hide-details style="max-width: 200px" :label="t('warehouse_management.outbound.scan_to_check')" />
                <VBtn color="primary" :disabled="!add.line_id" :loading="add.processing" @click="addToPackage">{{ t('warehouse_management.common.add') }}</VBtn>
              </div>
            </template>
            <div v-if="pack_check.some(c => c.difference)" class="text-caption text-warning mt-2">
              {{ t('warehouse_management.outbound.unpacked', { list: pack_check.filter(c => c.difference).map(c => `${c.item} ${c.difference}`).join(', ') }) }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard v-if="can.dispatch && closedPkgs.length" border class="mb-4">
      <VCardTitle>{{ t('warehouse_management.outbound.dispatch') }}</VCardTitle>
      <VCardText>
        <div class="d-flex flex-wrap gap-2 mb-2">
          <VCheckbox v-for="p in closedPkgs" :key="p.id" v-model="dispatchForm.packages" :value="p.id" :label="p.sscc" density="compact" hide-details />
        </div>
        <VRow dense>
          <VCol v-if="carrier_quote && carrier_quote.options.length" cols="12">
            <div class="text-caption mb-1">{{ t('warehouse_management.carriers.quotes', { kg: carrier_quote.parcel.weight_kg, n: carrier_quote.parcel.packages }) }}</div>
            <div class="d-flex flex-wrap gap-2">
              <VChip v-for="o in carrier_quote.options" :key="o.carrier + o.service" :color="dispatchForm.carrier_id === o.carrier && dispatchForm.carrier_service === o.service ? 'primary' : undefined"
                     variant="outlined" @click="chooseQuote(o)">{{ o.carrier_name }} · {{ o.service }} · {{ o.price.toFixed(2) }}<template v-if="o.transit_days !== null"> · {{ t('warehouse_management.carriers.days', { n: o.transit_days }) }}</template></VChip>
              <VChip v-if="dispatchForm.carrier_id" variant="text" @click="dispatchForm.carrier_id = null; dispatchForm.carrier_service = ''">{{ t('warehouse_management.carriers.no_booking') }}</VChip>
            </div>
          </VCol>
          <template v-if="!dispatchForm.carrier_id">
            <VCol cols="6" md="3"><VTextField v-model="dispatchForm.carrier" :label="t('warehouse_management.appointments.carrier')" /></VCol>
            <VCol cols="6" md="3"><VTextField v-model="dispatchForm.service" :label="t('warehouse_management.outbound.service')" /></VCol>
          </template>
          <VCol cols="6" md="3"><VTextField v-model="dispatchForm.vehicle_reg" :label="t('warehouse_management.appointments.vehicle')" /></VCol>
          <VCol cols="6" md="3"><VTextField v-model="dispatchForm.driver_name" :label="t('warehouse_management.appointments.driver')" /></VCol>
          <VCol cols="12" md="4"><VTextField v-model="dispatchForm.tracking_reference" :label="t('warehouse_management.transfers.tracking')" /></VCol>
          <VCol cols="12" md="4"><VTextField v-model="dispatchForm.handover_name" :label="t('warehouse_management.outbound.handed_to')" :error-messages="dispatchForm.errors.handover_name" /></VCol>
          <VCol cols="12" md="4"><VFileInput accept="image/*" density="compact" :label="t('warehouse_management.outbound.signature')" @update:model-value="(f: any) => dispatchForm.signature = Array.isArray(f) ? f[0] ?? null : f" /></VCol>
        </VRow>
        <VAlert v-if="(dispatchForm.errors as any).refused" type="error" variant="tonal">{{ (dispatchForm.errors as any).refused }}</VAlert>
      </VCardText>
      <VCardActions><VSpacer /><VBtn color="primary" :disabled="!dispatchForm.packages.length" :loading="dispatchForm.processing" @click="dispatch">{{ t('warehouse_management.outbound.confirm_dispatch') }}</VBtn></VCardActions>
    </VCard>

    <VCard v-if="shipments.length" border>
      <VCardTitle>{{ t('warehouse_management.outbound.shipments') }}</VCardTitle>
      <VList density="compact">
        <VListItem v-for="s in shipments" :key="s.id" :title="`${s.number} · ${s.carrier ?? ''} ${s.tracking_reference ?? ''}`"
                   :subtitle="[when(s.dispatched_at), t('warehouse_management.outbound.handed_to') + ': ' + (s.handover_name ?? ''), s.delivery_note ? t('warehouse_management.outbound.delivery_note', { n: s.delivery_note }) : null, s.packages.join(', ')].filter(Boolean).join(' · ')">
          <template #append>
            <VChip size="small" :color="s.status === 'DELIVERED' ? 'success' : 'primary'" variant="tonal">{{ t(`warehouse_management.shipment_status.${s.status}`) }}<template v-if="s.pod_received_by">: {{ s.pod_received_by }}</template></VChip>
            <VBtn v-if="s.has_pod" size="small" variant="text" :href="route('warehouse.outbound.shipments.pod.file', s.id)">{{ t('warehouse_management.outbound.pod') }}</VBtn>
            <VBtn v-if="can.dispatch && s.status === 'DISPATCHED'" size="small" variant="tonal" class="ms-1" @click="podFor = s.id; pod.reset()">{{ t('warehouse_management.outbound.record_delivery') }}</VBtn>
            <VBtn size="small" variant="text" :href="route('warehouse.outbound.orders.documents', { uuid: order.id, shipment: s.id })">{{ t('warehouse_management.outbound.dispatch_note') }}</VBtn>
            <VBtn size="small" variant="text" @click="labels('DISPATCH', [s.id])">{{ t('warehouse_management.outbound.dispatch_labels') }}</VBtn>
            <VBtn v-if="can.dispatch && s.status !== 'DELIVERED'" size="small" variant="text" @click="trackFor = s.id">{{ t('warehouse_management.carriers.add_tracking') }}</VBtn>
          </template>
          <div v-if="s.booked || s.tracking.length" class="text-caption mt-1">
            <span v-if="s.booked">{{ t('warehouse_management.carriers.booked', { ref: s.booking_reference, service: s.service }) }}<template v-if="s.quoted_price !== null"> · {{ s.quoted_price.toFixed(2) }}</template></span>
            <a v-if="s.tracking_link" :href="s.tracking_link" target="_blank" rel="noopener" class="ms-2">{{ t('warehouse_management.carriers.track_link') }}</a>
            <div v-for="(e, i) in s.tracking" :key="i">{{ when(e.occurred_at) }} · {{ t(`warehouse_management.tracking_status.${e.status}`) }}<template v-if="e.location"> · {{ e.location }}</template><template v-if="e.detail"> · {{ e.detail }}</template></div>
          </div>
        </VListItem>
      </VList>
    </VCard>

    <VDialog :model-value="!!closing" max-width="460" @update:model-value="v => { if (!v) closing = null }">
      <VCard v-if="closing">
        <VCardTitle>{{ t('warehouse_management.outbound.close_package') }} {{ closing.sscc }}</VCardTitle>
        <VCardText>
          <VRow dense>
            <VCol cols="6"><VTextField v-model.number="closeForm.weight_kg" type="number" min="0" :label="t('warehouse_management.products.weight_kg')" /></VCol>
            <VCol cols="6"><VTextField v-model="closeForm.tracking_number" :label="t('warehouse_management.transfers.tracking')" /></VCol>
            <VCol cols="4"><VTextField v-model.number="closeForm.length_cm" type="number" min="0" :label="t('warehouse_management.locations.length_cm')" /></VCol>
            <VCol cols="4"><VTextField v-model.number="closeForm.width_cm" type="number" min="0" :label="t('warehouse_management.locations.width_cm')" /></VCol>
            <VCol cols="4"><VTextField v-model.number="closeForm.height_cm" type="number" min="0" :label="t('warehouse_management.locations.height_cm')" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="closing = null">{{ t('warehouse_management.common.cancel') }}</VBtn><VBtn color="primary" @click="close">{{ t('warehouse_management.common.confirm') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog :model-value="!!trackFor" max-width="460" @update:model-value="v => { if (!v) trackFor = null }">
      <VCard>
        <VCardTitle>{{ t('warehouse_management.carriers.add_tracking') }}</VCardTitle>
        <VCardText>
          <VSelect v-model="trackForm.status" :items="tracking_statuses.map(v => ({ value: v, title: t(`warehouse_management.tracking_status.${v}`) }))" :label="t('warehouse_management.carriers.status')" />
          <VTextField v-model="trackForm.occurred_at" type="datetime-local" :label="t('warehouse_management.carriers.occurred_at')" />
          <VTextField v-model="trackForm.location" :label="t('warehouse_management.carriers.location')" />
          <VTextField v-model="trackForm.detail" :label="t('warehouse_management.adjustments.note')" />
          <VTextField v-if="trackForm.status === 'DELIVERED'" v-model="trackForm.received_by" :label="t('warehouse_management.outbound.received_by')" :error-messages="(trackForm.errors as any).refused" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="trackFor = null">{{ t('warehouse_management.common.cancel') }}</VBtn><VBtn color="primary" :loading="trackForm.processing" @click="saveTracking">{{ t('warehouse_management.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog :model-value="!!podFor" max-width="460" @update:model-value="v => { if (!v) podFor = null }">
      <VCard>
        <VCardTitle>{{ t('warehouse_management.outbound.record_delivery') }}</VCardTitle>
        <VCardText>
          <VTextField v-model="pod.received_by" :label="t('warehouse_management.outbound.received_by')" :error-messages="pod.errors.received_by" />
          <VTextField v-model="pod.delivered_at" type="datetime-local" :label="t('warehouse_management.outbound.delivered_at')" />
          <VFileInput accept=".pdf,image/*" density="compact" :label="t('warehouse_management.outbound.pod')" @update:model-value="(f: any) => pod.pod = Array.isArray(f) ? f[0] ?? null : f" />
          <VTextField v-model="pod.note" :label="t('warehouse_management.adjustments.note')" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="podFor = null">{{ t('warehouse_management.common.cancel') }}</VBtn><VBtn color="primary" :loading="pod.processing" @click="savePod">{{ t('warehouse_management.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="cancelling" max-width="420">
      <VCard>
        <VCardTitle>{{ t('warehouse_management.outbound.cancel_title', { n: order.number }) }}</VCardTitle>
        <VCardText><p class="text-body-2">{{ t('warehouse_management.outbound.cancel_hint') }}</p><VTextarea v-model="cancelReason" rows="2" auto-grow :label="t('warehouse_management.common.reason')" /></VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="cancelling = false">{{ t('warehouse_management.common.cancel') }}</VBtn><VBtn color="error" :disabled="!cancelReason" @click="act('cancel', { reason: cancelReason }); cancelling = false">{{ t('warehouse_management.common.confirm') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
    <VDialog :model-value="overriding !== null" max-width="520" @update:model-value="v => !v && (overriding = null)">
      <VCard v-if="overriding" :title="t('warehouse_management.outbound.override_title', { item: overriding.item })">
        <VCardText class="d-flex flex-column gap-3">
          <p class="text-body-2 mb-0">{{ t('warehouse_management.outbound.override_hint') }}</p>
          <VSelect v-model="overridePick" :items="overrideOptions.map((o, i) => ({ value: i, title: o.title }))" :label="t('warehouse_management.outbound.from')" />
          <VTextField v-model.number="overrideForm.qty" type="number" min="0" :label="t('warehouse_management.quality.qty')" />
          <VTextField v-model="overrideForm.reason" :label="t('warehouse_management.common.reason')" :error-messages="overrideForm.errors.reason" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="overriding = null">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="warning" :loading="overrideForm.processing" :disabled="overridePick === null" @click="submitOverride">{{ t('warehouse_management.outbound.override') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
