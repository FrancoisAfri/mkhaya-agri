<script setup lang="ts">
/**
 * Grain loads, load by load: the field log, the weighbridge (device and by hand), load tickets with
 * grade and deductions, each bin's accountability, lost and duplicate loads, the buyers' settlement
 * statements matched to our tickets, and the contracts' balances.
 */
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import PageErrors from '../Components/PageErrors.vue'
import FarmFilter from '../Components/FarmFilter.vue'
import FormDialog, { type FormField } from '../Components/FormDialog.vue'
import { human, money, num, statusColor, today, localDateTime } from '../Components/agri'

type Opt = { value: any; title: string }
const props = defineProps<{
  tab: 'loads' | 'weighbridge' | 'bins' | 'reconciliation' | 'settlements' | 'contracts'
  farm: null | { id: string; name: string }
  farms: Array<{ id: string; name: string }>
  data: Record<string, any>
  options: { plans: Opt[]; stores: Opt[]; buyers: Opt[]; contracts: Array<Opt & { party_id: number }>; items: Opt[]; movements: string[] }
  can: { record: boolean; sales: boolean; manage_sales: boolean; devices: boolean }
  secret: null | { name: string; secret: string; endpoint: string }
}>()

const { t } = useI18n()
const k = (key: string, params?: Record<string, unknown>) => t(`agri.grain.${key}`, params ?? {})
const keep = { preserveScroll: true }
const TABS = computed(() => ['loads', 'weighbridge', 'bins', 'reconciliation', ...(props.can.sales ? ['settlements', 'contracts'] : [])])
const go = (tab: string, extra: Record<string, any> = {}) => router.get(route('agri.grain', tab), { farm: props.farm?.id, ...extra }, { preserveState: false })
const kg = (v: number | null | undefined) => (v === null || v === undefined ? '—' : `${num(v, 0)} kg`)
const tn = (v: number | null | undefined) => (v === null || v === undefined ? '—' : `${num(v, 3)} t`)
const movementItems = props.options.movements.map(m => ({ title: k(`movement.${m}`), value: m }))
const MATCH_COLOR: Record<string, string> = { matched: 'success', differs: 'warning', unmatched: 'error', duplicate: 'error' }
const RECON_COLOR: Record<string, string> = { lost: 'error', no_receipt: 'error', duplicate: 'error', duplicate_receipt: 'error', unknown_receipt: 'warning', half_weighed: 'warning', awaiting_statement: 'info' }

// ── ticket forms ─────────────────────────────────────────────────────────────────────────────
const toBuyer = (f: any) => ['field_to_buyer', 'store_to_buyer'].includes(f.movement)
const whereFields: FormField[] = [
  { key: 'movement', label: k('f.movement'), type: 'select', items: movementItems, required: true, cols: 12 },
  { key: 'crop_plan_id', label: k('f.crop'), type: 'select', items: props.options.plans, show: f => String(f.movement ?? '').startsWith('field_'), cols: 6 },
  { key: 'to_store_id', label: k('f.to_store'), type: 'select', items: props.options.stores, show: f => f.movement === 'field_to_store', cols: 6 },
  { key: 'from_store_id', label: k('f.from_store'), type: 'select', items: props.options.stores, show: f => f.movement === 'store_to_buyer', cols: 6 },
  { key: 'stock_item_id', label: k('f.item'), type: 'autocomplete', items: props.options.items, show: f => f.movement === 'field_to_store' || f.movement === 'store_to_buyer', cols: 6, hint: k('f.item_hint') },
  { key: 'party_id', label: k('f.buyer'), type: 'autocomplete', items: props.options.buyers, show: toBuyer, cols: 6 },
  { key: 'contract_id', label: k('f.contract'), type: 'select', items: (f: any) => props.options.contracts.filter(c => !f.party_id || c.party_id === f.party_id), show: toBuyer, cols: 6 },
  { key: 'destination_name', label: k('f.destination'), show: toBuyer, cols: 6, hint: k('f.destination_hint') },
]
const truckFields: FormField[] = [
  { key: 'vehicle_reg', label: k('f.vehicle'), required: true, cols: 4 }, { key: 'trailer_reg', label: k('f.trailer'), cols: 4 }, { key: 'driver', label: k('f.driver'), cols: 4 },
  { key: 'load_ref', label: k('f.load_ref'), cols: 6, hint: k('f.load_ref_hint') },
]
const qualityFields: FormField[] = [
  { key: 'moisture_pct', label: k('f.moisture'), type: 'number', required: true, cols: 3 }, { key: 'foreign_matter_pct', label: k('f.foreign_matter'), type: 'number', cols: 3 },
  { key: 'defects_pct', label: k('f.defects'), type: 'number', cols: 3 }, { key: 'other_colour_pct', label: k('f.other_colour'), type: 'number', cols: 3 },
  { key: 'protein_pct', label: k('f.protein'), type: 'number', cols: 3 }, { key: 'hectolitre_kg', label: k('f.hectolitre'), type: 'number', cols: 3 },
  { key: 'grade', label: k('f.grade'), cols: 3, hint: k('f.grade_hint') }, { key: 'buyer_ticket_ref', label: k('f.buyer_ticket'), cols: 3, show: toBuyer },
  { key: 'notes', label: k('f.notes'), type: 'textarea', cols: 12 },
  { key: 'photos', label: k('f.photos'), type: 'file', multiple: true, accept: 'image/*', cols: 12 },
]
const dispatchFields: FormField[] = [...whereFields, ...truckFields, { key: 'dispatched_at', label: k('f.dispatched_at'), type: 'datetime', cols: 6 }, { key: 'notes', label: k('f.notes'), type: 'textarea', cols: 12 }]
const captureFields: FormField[] = [...whereFields, ...truckFields,
  { key: 'gross_kg', label: k('f.gross'), type: 'number', required: true, cols: 3 }, { key: 'tare_kg', label: k('f.tare'), type: 'number', required: true, cols: 3 },
  { key: 'weighed_in_at', label: k('f.weighed_in'), type: 'datetime', cols: 3 }, { key: 'weighed_out_at', label: k('f.weighed_out'), type: 'datetime', cols: 3 },
  ...qualityFields]
const assessFields: FormField[] = [...whereFields.map(f => ({ ...f, required: false })), ...qualityFields]
const weighFields: FormField[] = [
  { key: 'vehicle_reg', label: k('f.vehicle'), cols: 6 }, { key: 'ticket', label: k('f.ticket'), cols: 6, hint: k('f.ticket_hint') },
  { key: 'mass_kg', label: k('f.mass'), type: 'number', required: true, cols: 4 },
  { key: 'kind', label: k('f.kind'), type: 'select', items: [{ title: k('f.gross'), value: 'gross' }, { title: k('f.tare'), value: 'tare' }], cols: 4, hint: k('f.kind_hint') },
  { key: 'weighed_at', label: k('f.weighed_at'), type: 'datetime', cols: 4 },
]
const voidFields: FormField[] = [{ key: 'reason', label: k('f.reason'), type: 'textarea', required: true, cols: 12 }]
const photoFields: FormField[] = [{ key: 'photos', label: k('f.photos'), type: 'file', multiple: true, accept: 'image/*', required: true, cols: 12 }]
const deviceFields: FormField[] = [{ key: 'name', label: k('f.device_name'), required: true, cols: 12 }]

const dialog = ref<null | { kind: string; row?: any }>(null)
const open = (kind: string, row: any = null) => { dialog.value = { kind, row } }
const close = (v: boolean) => { if (!v) dialog.value = null }
const assessValues = (r: any) => ({ movement: r.movement, moisture_pct: r.moisture_pct, foreign_matter_pct: r.foreign_matter_pct, grade: r.grade, buyer_ticket_ref: r.buyer_ticket_ref,
  defects_pct: r.quality?.defects_pct ?? null, other_colour_pct: r.quality?.other_colour_pct ?? null, protein_pct: r.quality?.protein_pct ?? null, hectolitre_kg: r.quality?.hectolitre_kg ?? null })
const expanded = ref<string | null>(null)

// ── settlements ──────────────────────────────────────────────────────────────────────────────
const blankLine = () => ({ buyer_ticket_ref: '', our_ticket_ref: '', vehicle_reg: '', delivered_on: today(), net_kg: null, moisture_pct: null, grade: '', settled_kg: null, price_per_t: null, amount: null })
const st = ref<any>(null)
const newStatement = () => { st.value = { party_id: null, contract_id: null, reference: '', statement_date: today(), period_from: null, period_to: null, charges: 0, charges_note: '', lines: [blankLine()] } }
const saveStatement = () => router.post(route('agri.grain.settlements.store'), { ...st.value, farm_id: props.farm?.id }, { ...keep, onSuccess: () => { st.value = null } })
const importFields: FormField[] = [
  { key: 'party_id', label: k('f.buyer'), type: 'autocomplete', items: props.options.buyers, required: true, cols: 6 },
  { key: 'contract_id', label: k('f.contract'), type: 'select', items: (f: any) => props.options.contracts.filter(c => !f.party_id || c.party_id === f.party_id), cols: 6 },
  { key: 'reference', label: k('f.statement_ref'), required: true, cols: 6 }, { key: 'statement_date', label: k('f.statement_date'), type: 'date', required: true, cols: 6 },
  { key: 'period_from', label: k('f.period_from'), type: 'date', cols: 6 }, { key: 'period_to', label: k('f.period_to'), type: 'date', cols: 6 },
  { key: 'charges', label: k('f.charges'), type: 'number', cols: 6 }, { key: 'mass_unit', label: k('f.mass_unit'), type: 'select', items: [{ title: 'kg', value: 'kg' }, { title: 't', value: 't' }], cols: 6 },
  { key: 'charges_note', label: k('f.charges_note'), cols: 12 },
  { key: 'file', label: k('f.csv'), type: 'file', accept: '.csv,text/csv', required: true, cols: 12, hint: k('f.csv_hint') },
]
const matchAgain = (s: any) => router.post(route('agri.grain.settlements.match', s.uuid), {}, keep)
const postStatement = (s: any) => router.post(route('agri.grain.settlements.post', s.uuid), {}, keep)
const copy = (s: string) => navigator.clipboard?.writeText(s)
const example = (endpoint: string, secret: string) => `curl -X POST ${endpoint} \\\n  -H "Authorization: Bearer ${secret}" -H "Content-Type: application/json" \\\n  -d '{"weighings":[{"id":"WB-000123","vehicle_reg":"CA 123-456","mass_kg":42380,"kind":"gross","weighed_at":"2026-10-22T10:02:00+02:00"}]}'`
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div>
        <div class="text-body-2 mb-1"><Link :href="route('agri.hub.operations')">{{ k('breadcrumb') }}</Link> / {{ farm?.name }}</div>
        <h4 class="text-h4 mb-1">{{ k('title') }}</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">{{ k('subtitle') }}</p>
      </div>
      <FarmFilter :farms="farms" :model-value="farm?.id" :allow-all="false" />
    </div>
    <div class="d-flex flex-wrap gap-2 mb-5">
      <VChip v-for="x in TABS" :key="x" :color="tab === x ? 'primary' : undefined" :variant="tab === x ? 'flat' : 'tonal'" @click="go(x)">{{ k(`tabs.${x}`) }}</VChip>
    </div>

    <!-- loads -->
    <template v-if="tab === 'loads'">
      <div class="d-flex flex-wrap gap-2 mb-3 align-center">
        <VBtn v-if="can.record && farm" color="primary" prepend-icon="tabler-truck" @click="open('dispatch')">{{ k('log_load') }}</VBtn>
        <VBtn v-if="can.record && farm" variant="tonal" prepend-icon="tabler-scale" @click="open('capture')">{{ k('capture_ticket') }}</VBtn>
        <VSpacer />
        <VChip v-for="s in [null, 'dispatched', 'weighing', 'weighed', 'complete', 'void']" :key="String(s)" size="small" :color="data.status === s ? 'primary' : undefined"
          :variant="data.status === s ? 'flat' : 'tonal'" @click="go('loads', s ? { status: s } : {})">{{ s ? k(`status.${s}`) : k('all') }}</VChip>
      </div>
      <VCard>
        <VTable density="compact">
          <thead><tr><th>{{ k('col.ticket') }}</th><th>{{ k('col.route') }}</th><th>{{ k('col.truck') }}</th><th class="text-end">{{ k('col.gross_tare') }}</th>
            <th class="text-end">{{ k('col.net') }}</th><th>{{ k('col.quality') }}</th><th class="text-end">{{ k('col.settled') }}</th><th>{{ k('col.status') }}</th><th /></tr></thead>
          <tbody>
            <template v-for="r in data.tickets" :key="r.uuid">
              <tr>
                <td><a href="#" @click.prevent="expanded = expanded === r.uuid ? null : r.uuid"><strong>{{ r.number }}</strong></a><div class="text-caption">{{ r.date }}</div></td>
                <td><VChip size="x-small" variant="tonal">{{ k(`movement.${r.movement ?? 'unknown'}`) }}</VChip>
                  <div class="text-caption">{{ r.plan ?? r.from_store }} → {{ r.to_store ?? r.buyer ?? '?' }}<template v-if="r.contract"> · {{ r.contract }}</template></div></td>
                <td>{{ r.vehicle_reg }}<div class="text-caption">{{ r.driver }}<template v-if="r.load_ref"> · {{ r.load_ref }}</template></div></td>
                <td class="text-end">{{ kg(r.gross_kg) }}<div class="text-caption">{{ kg(r.tare_kg) }}</div></td>
                <td class="text-end font-weight-medium">{{ kg(r.net_kg) }}</td>
                <td><template v-if="r.moisture_pct !== null">{{ num(r.moisture_pct, 1) }}% {{ k('h2o') }}</template><template v-if="r.foreign_matter_pct !== null"> · {{ num(r.foreign_matter_pct, 2) }}% {{ k('fm') }}</template>
                  <div v-if="r.grade"><VChip size="x-small" color="primary" variant="tonal">{{ r.grade }}</VChip><span v-if="r.suggested_grade && r.suggested_grade !== r.grade" class="text-caption"> ({{ k('suggested', { grade: r.suggested_grade }) }})</span></div></td>
                <td class="text-end">{{ kg(r.settled_kg) }}<div v-if="r.settled" class="text-caption text-success">{{ k('buyer_settled') }}</div></td>
                <td><VChip size="small" :color="statusColor(r.status === 'complete' ? 'completed' : r.status === 'void' ? 'cancelled' : 'in_progress')">{{ k(`status.${r.status}`) }}</VChip>
                  <div v-for="f in r.flags ?? []" :key="f.code"><VChip size="x-small" color="error" variant="tonal" class="mt-1">{{ k(`flag.${f.code}`) }}</VChip></div>
                  <div v-if="r.completion_error" class="text-caption text-error">{{ r.completion_error }}</div></td>
                <td class="text-no-wrap">
                  <VBtn v-if="can.record && ['weighed', 'weighing', 'dispatched'].includes(r.status)" size="small" variant="tonal" @click="open('assess', r)">{{ r.status === 'weighed' ? k('complete') : k('edit') }}</VBtn>
                  <VBtn v-if="can.record && r.status === 'complete' && r.movement !== 'field_to_store'" size="small" variant="text" @click="open('assess', r)">{{ k('receipt') }}</VBtn>
                  <VBtn v-if="can.record && r.status !== 'void'" size="small" variant="text" icon="tabler-camera" @click="open('photos', r)" />
                  <VBtn v-if="can.record && !['complete', 'void'].includes(r.status)" size="small" variant="text" color="error" icon="tabler-x" @click="open('void', r)" />
                </td>
              </tr>
              <tr v-if="expanded === r.uuid"><td colspan="9" class="bg-grey-lighten-5 py-3">
                <div v-for="(b, i) in r.deduction_basis ?? []" :key="i" class="text-caption">{{ b }}</div>
                <div v-if="r.settled_kg" class="text-caption">{{ k('deductions_total', { m: kg(r.moisture_deduction_kg), h: kg(r.handling_deduction_kg), s: kg(r.screenings_deduction_kg) }) }}</div>
                <div v-for="f in r.flags ?? []" :key="f.code" class="text-caption text-error">{{ f.message }}</div>
                <div v-for="o in r.stock_out ?? []" :key="o.movement_id" class="text-caption">{{ k('out_of_lot', { lot: o.lot, grade: o.grade ?? '—', qty: num(o.quantity, 3), unit: o.unit }) }}</div>
                <div class="text-caption">{{ k('weighed', { first: r.first_weighed_at ?? '—', second: r.second_weighed_at ?? '—', source: r.weigh_source ?? '—' }) }}<template v-if="r.dispatched_at"> · {{ k('left_field', { at: r.dispatched_at }) }}</template></div>
                <div v-if="r.buyer_ticket_ref" class="text-caption">{{ k('buyer_ticket') }}: {{ r.buyer_ticket_ref }}</div>
                <div v-if="r.notes" class="text-caption">{{ r.notes }}</div>
                <div v-if="r.void_reason" class="text-caption text-error">{{ r.void_reason }}</div>
                <div v-if="r.photos.length" class="d-flex gap-2 mt-2"><a v-for="p in r.photos" :key="p.id" :href="route('agri.grain.photos.show', p.id)" target="_blank">
                  <VImg :src="route('agri.grain.photos.show', p.id)" width="96" height="72" cover class="rounded" /></a></div>
              </td></tr>
            </template>
            <tr v-if="!data.tickets?.length"><td colspan="9" class="text-center text-medium-emphasis py-4">{{ k('no_tickets') }}</td></tr>
          </tbody>
        </VTable>
      </VCard>
    </template>

    <!-- weighbridge -->
    <template v-if="tab === 'weighbridge'">
      <VAlert v-if="secret" type="warning" variant="tonal" class="mb-4" :title="k('secret_title', { name: secret.name })">
        <div class="text-body-2 mb-2">{{ k('secret_once') }}</div>
        <code class="d-block mb-2">{{ secret.secret }}</code>
        <pre class="text-caption" style="white-space: pre-wrap">{{ example(secret.endpoint, secret.secret) }}</pre>
        <VBtn size="small" variant="tonal" prepend-icon="tabler-copy" @click="copy(secret.secret)">{{ k('copy') }}</VBtn>
      </VAlert>
      <div class="d-flex flex-wrap gap-2 mb-3">
        <VBtn v-if="can.record && farm" color="primary" prepend-icon="tabler-scale" @click="open('weigh')">{{ k('manual_weighing') }}</VBtn>
        <VBtn v-if="can.devices && farm" variant="tonal" prepend-icon="tabler-plug" @click="open('device')">{{ k('register_bridge') }}</VBtn>
      </div>
      <VRow>
        <VCol cols="12" md="7"><VCard :title="k('open_weighings')" :subtitle="k('open_weighings_hint')">
          <VTable density="compact"><tbody>
            <tr v-for="r in data.open" :key="r.uuid"><td><strong>{{ r.number }}</strong><div class="text-caption">{{ r.vehicle_reg }}</div></td>
              <td>{{ k(`status.${r.status}`) }}<div class="text-caption">{{ r.first_weighed_at }}<template v-if="r.second_weighed_at"> → {{ r.second_weighed_at }}</template></div></td>
              <td class="text-end">{{ r.status === 'weighed' ? kg(r.net_kg) : kg(r.gross_kg ?? r.tare_kg) }}</td>
              <td><VBtn v-if="can.record" size="small" variant="tonal" @click="open('assess', r)">{{ r.status === 'weighed' ? k('complete') : k('edit') }}</VBtn></td></tr>
            <tr v-if="!data.open?.length"><td class="text-center text-medium-emphasis py-4">{{ k('nothing_open') }}</td></tr>
          </tbody></VTable></VCard></VCol>
        <VCol cols="12" md="5"><VCard :title="k('devices')" :subtitle="k('devices_hint', { pct: data.tolerance_pct, max: num(data.max_gross_kg, 0) })">
          <VTable density="compact"><tbody>
            <tr v-for="d in data.devices" :key="d.id"><td><strong>{{ d.name }}</strong><div class="text-caption">{{ d.endpoint }}</div></td>
              <td class="text-caption">{{ d.last_seen_at ?? k('never') }}<div>{{ k('events', { n: d.events }) }}</div></td>
              <td><VChip size="x-small" :color="d.active ? 'success' : undefined">{{ d.active ? k('on') : k('off') }}</VChip></td></tr>
            <tr v-if="!data.devices?.length"><td class="text-center text-medium-emphasis py-4">{{ k('no_devices') }}</td></tr>
          </tbody></VTable></VCard></VCol>
      </VRow>
      <VCard class="mt-4" :title="k('flagged')"><VTable density="compact"><tbody>
        <tr v-for="r in data.flagged" :key="r.uuid"><td><strong>{{ r.number }}</strong><div class="text-caption">{{ r.date }} · {{ r.vehicle_reg }}</div></td>
          <td><div v-for="f in r.flags" :key="f.code" class="text-body-2"><VChip size="x-small" color="error" variant="tonal" class="me-1">{{ k(`flag.${f.code}`) }}</VChip>{{ f.message }}</div></td></tr>
        <tr v-if="!data.flagged?.length"><td class="text-center text-medium-emphasis py-4">{{ k('no_flags') }}</td></tr>
      </tbody></VTable></VCard>
    </template>

    <!-- bins -->
    <template v-if="tab === 'bins'">
      <VRow>
        <VCol v-for="b in data.bins" :key="b.store.id" cols="12" md="6"><VCard>
          <VCardItem><VCardTitle>{{ b.store.name }}</VCardTitle><VCardSubtitle>{{ human(b.store.kind) }}<template v-if="b.store.capacity"> · {{ num(b.store.capacity) }} {{ b.store.capacity_unit }}</template></VCardSubtitle>
            <template #append><VChip v-if="b.on_hand.fill_pct !== null" size="small" color="primary" variant="tonal">{{ k('full', { pct: b.on_hand.fill_pct }) }}</VChip></template></VCardItem>
          <VCardText>
            <VTable density="compact"><tbody>
              <tr><td>{{ k('bin.in', { n: b.in.loads }) }}</td><td class="text-end">{{ tn(b.in.net_t) }}</td></tr>
              <tr><td class="ps-6 text-caption">{{ k('bin.moisture') }}</td><td class="text-end text-caption">− {{ tn(b.in.moisture_t) }}</td></tr>
              <tr><td class="ps-6 text-caption">{{ k('bin.handling') }}</td><td class="text-end text-caption">− {{ tn(b.in.handling_t) }}</td></tr>
              <tr><td class="ps-6 text-caption">{{ k('bin.screenings') }}</td><td class="text-end text-caption">− {{ tn(b.in.screenings_t) }}</td></tr>
              <tr><td>{{ k('bin.booked') }}</td><td class="text-end font-weight-medium">{{ tn(b.in.booked_t) }}</td></tr>
              <tr><td>{{ k('bin.out', { n: b.out.loads }) }}</td><td class="text-end">− {{ tn(b.out.net_t) }}</td></tr>
              <tr><td>{{ k('bin.on_hand') }}</td><td class="text-end font-weight-medium">{{ tn(b.on_hand.t) }}</td></tr>
              <tr><td>{{ k('bin.unexplained') }}</td><td class="text-end" :class="Math.abs(b.unexplained_t) > 0.5 ? 'text-warning' : ''">{{ tn(b.unexplained_t) }}</td></tr>
            </tbody></VTable>
            <div class="d-flex flex-wrap gap-2 mt-3"><VChip v-for="(q, g) in b.on_hand.by_grade" :key="g" size="small" variant="tonal">{{ g }}: {{ tn(q) }}</VChip></div>
            <div class="text-caption mt-2">{{ b.basis }}</div>
          </VCardText>
        </VCard></VCol>
      </VRow>
      <VAlert v-if="!data.bins?.length" type="info" variant="tonal">{{ k('no_bins') }}</VAlert>
    </template>

    <!-- reconciliation -->
    <template v-if="tab === 'reconciliation'">
      <div class="d-flex flex-wrap gap-2 mb-3"><VChip v-for="(n, c) in data.counts" :key="c" :color="RECON_COLOR[c]" variant="tonal">{{ k(`recon.${c}`) }}: {{ n }}</VChip></div>
      <VCard :subtitle="k('recon_hint')"><VTable density="compact">
        <thead><tr><th>{{ k('col.issue') }}</th><th>{{ k('col.ticket') }}</th><th>{{ k('col.truck') }}</th><th class="text-end">{{ k('col.net') }}</th><th>{{ k('col.detail') }}</th></tr></thead>
        <tbody><tr v-for="(x, i) in data.items" :key="i"><td><VChip size="small" :color="RECON_COLOR[x.code]" variant="tonal">{{ k(`recon.${x.code}`) }}</VChip></td>
          <td><strong>{{ x.ticket }}</strong><div class="text-caption">{{ x.date }}</div></td><td>{{ x.vehicle }}</td><td class="text-end">{{ kg(x.net_kg) }}</td><td class="text-body-2">{{ x.message }}</td></tr>
          <tr v-if="!data.items?.length"><td colspan="5" class="text-center text-medium-emphasis py-4">{{ k('all_accounted') }}</td></tr></tbody>
      </VTable></VCard>
    </template>

    <!-- settlements -->
    <template v-if="tab === 'settlements'">
      <div class="d-flex flex-wrap gap-2 mb-3">
        <VBtn v-if="can.manage_sales" color="primary" prepend-icon="tabler-plus" :disabled="!options.buyers.length" @click="newStatement">{{ k('capture_statement') }}</VBtn>
        <VBtn v-if="can.manage_sales" variant="tonal" prepend-icon="tabler-file-upload" @click="open('import')">{{ k('import_csv') }}</VBtn>
      </div>
      <VCard v-for="s in data.settlements" :key="s.uuid" class="mb-4">
        <VCardItem><VCardTitle>{{ s.buyer }} · {{ s.reference }}</VCardTitle>
          <VCardSubtitle>{{ s.statement_date }}<template v-if="s.period_from"> · {{ s.period_from }} – {{ s.period_to }}</template><template v-if="s.contract"> · {{ s.contract }}</template> · {{ k(`source.${s.source}`) }}</VCardSubtitle>
          <template #append><VChip :color="s.status === 'posted' ? 'success' : s.status === 'matched' ? 'info' : undefined">{{ k(`settlement_status.${s.status}`) }}</VChip></template></VCardItem>
        <VCardText>
          <div class="d-flex flex-wrap gap-2 mb-3">
            <VChip size="small" color="success" variant="tonal">{{ k('match.matched') }}: {{ s.totals?.matched ?? 0 }}</VChip>
            <VChip size="small" color="warning" variant="tonal">{{ k('match.differs') }}: {{ s.totals?.differs ?? 0 }}</VChip>
            <VChip size="small" color="error" variant="tonal">{{ k('match.unmatched') }}: {{ s.totals?.unmatched ?? 0 }}</VChip>
            <VChip size="small" color="error" variant="tonal">{{ k('match.duplicate') }}: {{ s.totals?.duplicate ?? 0 }}</VChip>
            <VSpacer />
            <span class="text-body-2">{{ k('statement_value', { gross: money(s.totals?.gross_value), charges: money(s.charges), net: money(s.totals?.net_value) }) }}</span>
          </div>
          <VTable density="compact">
            <thead><tr><th>#</th><th>{{ k('col.buyer_ticket') }}</th><th>{{ k('col.our_ticket') }}</th><th class="text-end">{{ k('col.net') }}</th><th>{{ k('col.quality') }}</th>
              <th class="text-end">{{ k('col.settled') }}</th><th class="text-end">{{ k('col.amount') }}</th><th>{{ k('col.match') }}</th></tr></thead>
            <tbody><tr v-for="l in s.lines" :key="l.line_no"><td>{{ l.line_no }}</td><td>{{ l.buyer_ticket_ref }}<div class="text-caption">{{ l.delivered_on }} · {{ l.vehicle_reg }}</div></td>
              <td>{{ l.ticket ?? '—' }}</td><td class="text-end">{{ kg(l.net_kg) }}</td>
              <td><template v-if="l.moisture_pct !== null">{{ num(l.moisture_pct, 1) }}%</template> {{ l.grade }}</td><td class="text-end">{{ kg(l.settled_kg) }}</td>
              <td class="text-end">{{ money(l.amount ?? (l.settled_kg && l.price_per_t ? l.settled_kg / 1000 * l.price_per_t : null)) }}<div v-if="l.expected_amount" class="text-caption">{{ k('expected', { v: money(l.expected_amount) }) }}</div></td>
              <td><VChip size="x-small" :color="MATCH_COLOR[l.match_status]">{{ k(`match.${l.match_status}`) }}</VChip>
                <div v-for="(d, i) in l.differences ?? []" :key="i" class="text-caption">{{ d.message }}</div></td></tr></tbody>
          </VTable>
        </VCardText>
        <VCardActions v-if="can.manage_sales && s.status !== 'posted'">
          <VBtn size="small" variant="tonal" @click="matchAgain(s)">{{ k('match_again') }}</VBtn>
          <VBtn size="small" color="success" variant="tonal" :disabled="s.status !== 'matched'" @click="postStatement(s)">{{ k('post') }}</VBtn>
          <span class="text-caption ms-2">{{ k('post_hint') }}</span>
        </VCardActions>
      </VCard>
      <VAlert v-if="!data.settlements?.length" type="info" variant="tonal">{{ k('no_statements') }}</VAlert>
    </template>

    <!-- contracts -->
    <template v-if="tab === 'contracts'">
      <VCard :subtitle="k('contracts_hint')"><VTable density="compact">
        <thead><tr><th>{{ k('col.contract') }}</th><th class="text-end">{{ k('col.contracted') }}</th><th class="text-end">{{ k('col.delivered') }}</th>
          <th class="text-end">{{ k('col.buyer_accepted') }}</th><th class="text-end">{{ k('col.outstanding') }}</th><th class="text-end">{{ k('col.paid') }}</th><th>{{ k('col.price_now') }}</th></tr></thead>
        <tbody><tr v-for="c in data.contracts" :key="c.id"><td><strong>{{ c.reference }}</strong> · {{ c.buyer }}<div class="text-caption">{{ c.product }} · {{ k(`pricing.${c.pricing}`) }} · {{ k('loads', { n: c.loads }) }}
            <template v-if="c.awaiting_receipt"> · {{ k('awaiting', { n: c.awaiting_receipt }) }}</template></div></td>
          <td class="text-end">{{ num(c.contracted, 3) }} {{ c.unit }}<div class="text-caption">± {{ num(c.tolerance, 3) }}</div></td>
          <td class="text-end">{{ num(c.delivered, 3) }}<div class="text-caption">{{ k('by_load', { q: num(c.delivered_by_load, 3) }) }}</div></td>
          <td class="text-end">{{ num(c.buyer_accepted, 3) }}</td>
          <td class="text-end font-weight-medium" :class="c.state === 'over' ? 'text-error' : ''">{{ num(c.outstanding, 3) }}<div class="text-caption">{{ k(`state.${c.state}`) }}</div></td>
          <td class="text-end">{{ money(c.paid_value) }}</td>
          <td><strong>{{ c.price_now.price !== null ? money(c.price_now.price) : '—' }}</strong><div class="text-caption">{{ c.price_now.basis }}</div></td></tr>
          <tr v-if="!data.contracts?.length"><td colspan="7" class="text-center text-medium-emphasis py-4">{{ k('no_contracts') }}</td></tr></tbody>
      </VTable></VCard>
    </template>

    <!-- statement typed in -->
    <VDialog :model-value="!!st" max-width="1200" scrollable @update:model-value="v => { if (!v) st = null }">
      <VCard v-if="st" :title="k('capture_statement')">
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="4"><VAutocomplete v-model="st.party_id" :items="options.buyers" :label="k('f.buyer') + ' *'" /></VCol>
            <VCol cols="12" md="4"><VSelect v-model="st.contract_id" :items="options.contracts.filter(c => !st.party_id || c.party_id === st.party_id)" :label="k('f.contract')" clearable /></VCol>
            <VCol cols="12" md="4"><VTextField v-model="st.reference" :label="k('f.statement_ref') + ' *'" /></VCol>
            <VCol cols="6" md="3"><VTextField v-model="st.statement_date" type="date" :label="k('f.statement_date')" /></VCol>
            <VCol cols="6" md="3"><VTextField v-model="st.period_from" type="date" :label="k('f.period_from')" /></VCol>
            <VCol cols="6" md="3"><VTextField v-model="st.period_to" type="date" :label="k('f.period_to')" /></VCol>
            <VCol cols="6" md="3"><VTextField v-model.number="st.charges" type="number" :label="k('f.charges')" /></VCol>
          </VRow>
          <div class="text-subtitle-2 mt-2">{{ k('lines') }}</div>
          <VRow v-for="(l, i) in st.lines" :key="i" dense class="align-center">
            <VCol cols="6" md="1"><VTextField v-model="l.buyer_ticket_ref" :label="k('col.buyer_ticket')" density="compact" hide-details /></VCol>
            <VCol cols="6" md="1"><VTextField v-model="l.our_ticket_ref" :label="k('col.our_ticket')" density="compact" hide-details /></VCol>
            <VCol cols="6" md="1"><VTextField v-model="l.vehicle_reg" :label="k('f.vehicle')" density="compact" hide-details /></VCol>
            <VCol cols="6" md="2"><VTextField v-model="l.delivered_on" type="date" :label="k('f.date')" density="compact" hide-details /></VCol>
            <VCol cols="4" md="1"><VTextField v-model.number="l.net_kg" type="number" :label="k('f.net')" density="compact" hide-details /></VCol>
            <VCol cols="4" md="1"><VTextField v-model.number="l.moisture_pct" type="number" :label="k('f.moisture')" density="compact" hide-details /></VCol>
            <VCol cols="4" md="1"><VTextField v-model="l.grade" :label="k('f.grade')" density="compact" hide-details /></VCol>
            <VCol cols="4" md="1"><VTextField v-model.number="l.settled_kg" type="number" :label="k('f.settled')" density="compact" hide-details /></VCol>
            <VCol cols="4" md="1"><VTextField v-model.number="l.price_per_t" type="number" :label="k('f.price_t')" density="compact" hide-details /></VCol>
            <VCol cols="4" md="1"><VTextField v-model.number="l.amount" type="number" :label="k('f.amount')" density="compact" hide-details /></VCol>
            <VCol cols="12" md="1"><VBtn icon="tabler-x" size="small" variant="text" @click="st.lines.splice(i, 1)" /></VCol>
          </VRow>
          <VBtn size="small" variant="text" prepend-icon="tabler-plus" @click="st.lines.push(blankLine())">{{ k('add_line') }}</VBtn>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="st = null">{{ k('cancel') }}</VBtn>
          <VBtn color="primary" :disabled="!st.party_id || !st.reference || !st.lines.length" @click="saveStatement">{{ k('save_and_match') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <FormDialog v-if="dialog?.kind === 'dispatch'" :model-value="true" :title="k('log_load')" :url="route('agri.grain.dispatch')" :fields="dispatchFields"
      :values="{ farm_id: farm?.id, movement: 'field_to_store', dispatched_at: localDateTime(new Date()) }" :intro="k('log_load_intro')" max-width="900" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'capture'" :model-value="true" :title="k('capture_ticket')" :url="route('agri.grain.capture')" :fields="captureFields"
      :values="{ farm_id: farm?.id, movement: 'field_to_store' }" :intro="k('capture_intro')" max-width="980" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'assess'" :model-value="true" :title="k('assess_title', { n: dialog.row.number })" :url="route('agri.grain.tickets.assess', dialog.row.uuid)"
      :fields="dialog.row.status === 'complete' ? [{ key: 'buyer_ticket_ref', label: k('f.buyer_ticket'), cols: 12 }, { key: 'notes', label: k('f.notes'), type: 'textarea', cols: 12 }] : assessFields"
      :values="assessValues(dialog.row)" :intro="dialog.row.status === 'weighed' ? k('assess_intro') : undefined" max-width="980" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'weigh'" :model-value="true" :title="k('manual_weighing')" :url="route('agri.grain.weighings.store')" :fields="weighFields"
      :values="{ farm_id: farm?.id, weighed_at: localDateTime(new Date()) }" :intro="k('weigh_intro')" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'void'" :model-value="true" :title="k('void_title', { n: dialog.row.number })" :url="route('agri.grain.tickets.void', dialog.row.uuid)" :fields="voidFields" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'photos'" :model-value="true" :title="k('photos_title', { n: dialog.row.number })" :url="route('agri.grain.tickets.photos', dialog.row.uuid)" :fields="photoFields" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'device'" :model-value="true" :title="k('register_bridge')" :url="route('agri.grain.weighbridges.store')" :fields="deviceFields"
      :values="{ farm_id: farm?.id }" :intro="k('register_intro')" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'import'" :model-value="true" :title="k('import_csv')" :url="route('agri.grain.settlements.import')" :fields="importFields"
      :values="{ farm_id: farm?.id, statement_date: today(), mass_unit: 'kg', charges: 0 }" :intro="k('import_intro')" @update:model-value="close" />
  </div>
</template>
