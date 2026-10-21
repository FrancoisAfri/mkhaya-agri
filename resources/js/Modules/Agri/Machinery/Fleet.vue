<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PageErrors from '../Components/PageErrors.vue'
import FormDialog, { type FormField } from '../Components/FormDialog.vue'
import FarmFilter from '../Components/FarmFilter.vue'
import { human, money, num, statusColor, today } from '../Components/agri'

/** Phase 2 §7: bookings and approval, faults and repairs, pre-start checks, fuel and trips, risk ranking. */
const props = defineProps<{
  tab: 'bookings' | 'maintenance' | 'prestart' | 'fuel' | 'risk'
  farm: null | { id: string; name: string }
  farms: Array<{ id: string; name: string }>
  machines: Array<{ id: string; code: string; name: string; type: string; status: string; hours_reading: number; odometer_km: number | null; reason: string | null;
    inspection_expires_on: string | null; inspection_required: boolean; under_warranty: boolean; checklist: Array<{ item: string; critical: boolean }> }>
  data: any
  options: { severities: string[]; document_kinds: string[]; people: Array<{ id: number; name: string }>; fuel_items: any[] }
  can: { manage: boolean; approve: boolean; record: boolean; fuel: boolean; book: boolean }
}>()
const TABS = [
  { key: 'bookings', title: 'Bookings', icon: 'tabler-calendar-event' }, { key: 'maintenance', title: 'Faults & repairs', icon: 'tabler-tool' },
  { key: 'prestart', title: 'Pre-start checks', icon: 'tabler-checklist' }, { key: 'fuel', title: 'Fuel & trips', icon: 'tabler-gas-station' },
  { key: 'risk', title: 'Risk', icon: 'tabler-alert-triangle' },
]
const keep = { preserveScroll: true }
const go = (tab: string) => router.get(route('agri.machinery.fleet', tab), { farm: props.farm?.id }, { preserveState: false })
const dialog = ref<null | { kind: string; row?: any }>(null)
const open = (kind: string, row: any = null) => { dialog.value = { kind, row } }
const close = (v: boolean) => { if (!v) dialog.value = null }
const machineItems = props.machines.map(m => ({ title: `${m.code} ${m.name}`, value: m.id }))
const people = props.options.people.map(p => ({ title: p.name, value: p.id }))
const opts = (xs: string[]) => xs.map(x => ({ title: human(x), value: x }))

// bookings
const bookingFields: FormField[] = [
  { key: 'work_order_id', label: 'Work order', type: 'autocomplete', required: true, items: props.data.orders ?? [] },
  { key: 'machine_id', label: 'Machine', type: 'select', required: true, items: machineItems },
  { key: 'booked_from', label: 'From', type: 'datetime', required: true, cols: 6 }, { key: 'booked_to', label: 'To', type: 'datetime', required: true, cols: 6 },
  { key: 'planned_hours', label: 'Planned hours', type: 'number', cols: 6 }, { key: 'operator_id', label: 'Operator', type: 'autocomplete', items: people, cols: 6 },
  { key: 'accept_warnings', label: 'Book even with warnings (service due, under maintenance, licence lapsing)', type: 'switch', cols: 12 },
]
const decide = (b: any, approve: boolean) => router.post(route('agri.machinery.bookings.decide', b.id), { approve }, keep)
const takeOut = (m: any) => { const reason = prompt(`Why is ${m.code} out of service?`); if (reason) router.post(route('agri.machinery.out-of-service', m.id), { reason }, keep) }

// faults, repairs, warranty, documents
const faultFields: FormField[] = [
  { key: 'severity', label: 'Severity', type: 'select', required: true, items: opts(props.options.severities) },
  { key: 'description', label: 'What is wrong', type: 'textarea', required: true, cols: 12 },
  { key: 'stops_machine', label: 'The machine cannot be used until it is fixed', type: 'switch', cols: 12 },
]
const selected = ref<string | null>(props.machines[0]?.id ?? null)
const selectedCode = () => props.machines.find(m => m.id === selected.value)?.code ?? ''
const repair = (f: any) => router.post(route('agri.machinery.faults.repair', f.id), {}, keep)
const backToWork = (f: any) => { const note = prompt('Return-to-service note (what was checked)?'); if (note !== null) router.post(route('agri.machinery.faults.return', f.id), { note }, keep) }
const claimFields: FormField[] = [
  { key: 'provider', label: 'Claimed from', hint: 'Dealer, manufacturer or service supplier' }, { key: 'amount', label: 'Amount claimed', type: 'number', required: true, cols: 6 },
  { key: 'reference', label: 'Claim reference', cols: 6 }, { key: 'claimed_on', label: 'Claimed on', type: 'date' },
]
const settle = (c: any, status: string) => { const recovered = status === 'rejected' ? null : prompt('Amount recovered?', String(c.amount)); const outcome = prompt('Outcome?')
  router.post(route('agri.machinery.claims.settle', c.uuid), { status, recovered, outcome }, keep) }
const docFields: FormField[] = [
  { key: 'kind', label: 'Kind', type: 'select', required: true, items: opts(props.options.document_kinds), cols: 6 },
  { key: 'expires_on', label: 'Expires on', type: 'date', cols: 6, hint: 'Insurance, licence and inspection dates update the register' },
  { key: 'file', label: 'File', type: 'file', required: true, cols: 12 },
]

// pre-start
const checkMachine = ref<string | null>(props.machines[0]?.id ?? null)
const checkItems = ref<Array<{ item: string; critical: boolean; ok: boolean; note: string }>>([])
const checkReading = ref<{ hours_reading: number | null; odometer_km: number | null }>({ hours_reading: null, odometer_km: null })
const loadChecklist = () => { const m = props.machines.find(x => x.id === checkMachine.value); checkItems.value = (m?.checklist ?? []).map(c => ({ ...c, ok: true, note: '' })) }
loadChecklist()
const sendCheck = () => router.post(route('agri.machinery.prestart', checkMachine.value!), { items: checkItems.value.map(({ item, ok, note }) => ({ item, ok, note })), ...checkReading.value }, keep)

// fuel and trips
const fuelFields: FormField[] = [
  { key: 'store_id', label: 'Fuel tank', type: 'select', required: true, items: props.data.tanks ?? [], cols: 6 },
  { key: 'stock_item_id', label: 'Fuel', type: 'select', required: true, items: props.options.fuel_items, cols: 6 },
  { key: 'litres', label: 'Litres', type: 'number', required: true, cols: 4 },
  { key: 'hours_reading', label: 'Hour meter', type: 'number', cols: 4, hint: 'At the pump' }, { key: 'odometer_km', label: 'Odometer', type: 'number', cols: 4 },
  { key: 'driver_id', label: 'Driver / operator', type: 'autocomplete', items: people, cols: 6 }, { key: 'date', label: 'Date', type: 'date', cols: 6 },
]
const tripFields: FormField[] = [
  { key: 'odometer_start', label: 'Odometer at start', type: 'number', cols: 6 }, { key: 'odometer_end', label: 'Odometer at end', type: 'number', required: true, cols: 6 },
  { key: 'from', label: 'From', cols: 6 }, { key: 'to', label: 'To', cols: 6 }, { key: 'purpose', label: 'Purpose', required: true, cols: 12 },
  { key: 'driver_id', label: 'Driver', type: 'autocomplete', items: people, cols: 6 }, { key: 'date', label: 'Date', type: 'date', cols: 6 },
]
const at = (name: string) => route(name, selected.value ?? '_')
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div>
        <div class="text-body-2 mb-1"><Link :href="route('agri.machinery.index', { farm: farm?.id })">Machinery</Link> / {{ farm?.name }}</div>
        <h4 class="text-h4 mb-1">Bookings, maintenance &amp; fuel</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">A machine that stops flags every job booked on it.</p>
      </div>
      <FarmFilter :farms="farms" :model-value="farm?.id" :allow-all="false" />
    </div>
    <div class="d-flex flex-wrap gap-2 mb-4">
      <VChip v-for="t in TABS" :key="t.key" :color="tab === t.key ? 'primary' : undefined" :variant="tab === t.key ? 'flat' : 'tonal'" :prepend-icon="t.icon" @click="go(t.key)">{{ t.title }}</VChip>
    </div>
    <div class="d-flex flex-wrap gap-2 mb-5">
      <VChip v-for="m in machines" :key="m.id" size="small" :color="statusColor(m.status)" variant="tonal">{{ m.code }} · {{ human(m.status) }}
        <VTooltip v-if="m.reason" activator="parent">{{ m.reason }}</VTooltip></VChip>
    </div>

    <template v-if="tab === 'bookings'">
      <div class="d-flex gap-2 mb-3"><VBtn v-if="can.book" color="primary" prepend-icon="tabler-plus" @click="open('booking')">{{ can.approve ? 'Book a machine' : 'Request a machine' }}</VBtn></div>
      <VCard>
        <VTable density="compact">
          <thead><tr><th>Machine</th><th>Job</th><th>When</th><th>Status</th><th /></tr></thead>
          <tbody>
            <tr v-for="b in data.bookings" :key="b.id"><td>{{ b.machine }}</td>
              <td><Link v-if="b.work_order_id" :href="route('agri.work-orders.show', b.work_order_id)">{{ b.work_order }}</Link> {{ b.title }}
                <div v-if="b.conflict" class="text-caption text-error"><VIcon icon="tabler-alert-triangle" size="14" /> {{ b.conflict }}</div>
                <div v-else-if="b.warnings" class="text-caption text-warning">{{ b.warnings }}</div></td>
              <td class="text-caption text-no-wrap">{{ b.from }} – {{ b.to }}</td>
              <td><VChip size="x-small" :color="statusColor(b.status)">{{ human(b.status) }}</VChip></td>
              <td class="text-end text-no-wrap"><template v-if="can.approve && b.status === 'requested'">
                <VBtn size="x-small" color="success" variant="tonal" class="me-1" @click="decide(b, true)">Approve</VBtn>
                <VBtn size="x-small" color="error" variant="text" @click="decide(b, false)">Turn down</VBtn></template></td></tr>
            <tr v-if="!data.bookings?.length"><td colspan="5" class="text-center text-medium-emphasis py-4">No bookings.</td></tr>
          </tbody>
        </VTable>
      </VCard>
      <VCard v-if="can.manage" title="Take a machine out of service" class="mt-4">
        <VCardText class="d-flex flex-wrap gap-2"><VBtn v-for="m in machines.filter(x => !['out_of_service', 'maintenance'].includes(x.status))" :key="m.id" size="small" variant="tonal" color="error" @click="takeOut(m)">{{ m.code }}</VBtn></VCardText>
      </VCard>
    </template>

    <template v-if="tab === 'maintenance'">
      <div class="d-flex flex-wrap gap-2 mb-3 align-center">
        <VSelect v-model="selected" :items="machineItems" label="Machine" density="compact" hide-details style="max-inline-size: 260px" />
        <VBtn v-if="can.record" color="primary" prepend-icon="tabler-alert-octagon" :disabled="!selected" @click="open('fault')">Report a fault</VBtn>
        <VBtn v-if="can.manage" variant="tonal" prepend-icon="tabler-shield-check" :disabled="!selected" @click="open('claim')">Warranty claim</VBtn>
        <VBtn v-if="can.manage" variant="tonal" prepend-icon="tabler-file-upload" :disabled="!selected" @click="open('document')">Document</VBtn>
      </div>
      <VCard title="Faults" class="mb-4">
        <VTable density="compact">
          <thead><tr><th>Machine</th><th>Fault</th><th>Repair</th><th class="text-end">Down</th><th>Status</th><th /></tr></thead>
          <tbody>
            <tr v-for="f in data.faults" :key="f.id"><td>{{ f.machine }}</td>
              <td><VChip size="x-small" :color="f.severity === 'critical' ? 'error' : f.severity === 'major' ? 'warning' : undefined" class="me-1">{{ human(f.severity) }}</VChip>{{ f.description }}
                <div class="text-caption">{{ f.reported }} · from {{ human(f.source) }}</div>
                <div v-if="f.affected.length" class="text-caption text-error">Jobs affected: {{ f.affected.map((a: any) => a.reference).join(', ') }}</div></td>
              <td><Link v-if="f.work_order" :href="route('agri.work-orders.show', f.work_order.id)">{{ f.work_order.reference }}</Link><div v-if="f.work_order" class="text-caption">{{ human(f.work_order.status) }}</div></td>
              <td class="text-end">{{ f.downtime_hours !== null ? num(f.downtime_hours, 1) + ' h' : '—' }}</td>
              <td><VChip size="x-small" :color="statusColor(f.status)">{{ human(f.status) }}</VChip></td>
              <td class="text-end text-no-wrap">
                <VBtn v-if="can.manage && f.status === 'open' && !f.work_order" size="x-small" variant="tonal" @click="repair(f)">Open repair job</VBtn>
                <VBtn v-if="can.approve && f.status !== 'closed' && (!f.work_order || f.status === 'awaiting_return')" size="x-small" color="success" variant="tonal" @click="backToWork(f)">Return to service</VBtn>
              </td></tr>
            <tr v-if="!data.faults?.length"><td colspan="6" class="text-center text-medium-emphasis py-4">No faults.</td></tr>
          </tbody>
        </VTable>
      </VCard>
      <VCard title="Cost of keeping each machine running" subtitle="The last 12 months: repair parts and labour, outside services and fuel, less warranty recoveries." class="mb-4">
        <VTable density="compact">
          <thead><tr><th>Machine</th><th class="text-end">Jobs</th><th class="text-end">Parts</th><th class="text-end">Labour</th><th class="text-end">Services</th><th class="text-end">Recovered</th><th class="text-end">Maintenance</th><th class="text-end">Per hour</th><th class="text-end">Fuel</th><th class="text-end">Downtime</th></tr></thead>
          <tbody><tr v-for="c in data.costs" :key="c.machine"><td>{{ c.machine }}</td><td class="text-end">{{ c.repair_jobs }}</td><td class="text-end">{{ money(c.parts) }}</td>
            <td class="text-end">{{ money(c.labour) }}</td><td class="text-end">{{ money(c.services) }}</td><td class="text-end">{{ money(c.warranty_recovered) }}</td>
            <td class="text-end font-weight-medium">{{ money(c.maintenance) }}</td><td class="text-end">{{ c.maintenance_per_hour !== null ? money(c.maintenance_per_hour) : '—' }}</td>
            <td class="text-end">{{ money(c.fuel) }}</td><td class="text-end">{{ num(c.downtime_hours, 1) }} h</td></tr></tbody>
        </VTable>
      </VCard>
      <VRow>
        <VCol cols="12" md="6"><VCard title="Warranty claims">
          <VTable density="compact"><tbody><tr v-for="c in data.claims" :key="c.id"><td>{{ c.machine }}<div class="text-caption">{{ c.provider }} · {{ String(c.claimed_on).slice(0, 10) }}</div></td>
            <td class="text-end">{{ money(c.amount) }}<div v-if="c.recovered" class="text-caption">recovered {{ money(c.recovered) }}</div></td>
            <td><VChip size="x-small" :color="statusColor(c.status)">{{ human(c.status) }}</VChip></td>
            <td class="text-end text-no-wrap"><template v-if="can.manage && ['submitted', 'accepted'].includes(c.status)">
              <VBtn size="x-small" variant="text" @click="settle(c, 'settled')">Settled</VBtn><VBtn v-if="c.status === 'submitted'" size="x-small" color="error" variant="text" @click="settle(c, 'rejected')">Rejected</VBtn></template></td></tr>
            <tr v-if="!data.claims?.length"><td class="text-center text-medium-emphasis py-4">No claims.</td></tr></tbody></VTable></VCard></VCol>
        <VCol cols="12" md="6"><VCard title="Documents">
          <VTable density="compact"><tbody><tr v-for="d in data.documents" :key="d.id"><td>{{ d.machine }}</td><td><a :href="route('agri.machinery.documents.show', d.uuid)">{{ d.name }}</a><div class="text-caption">{{ human(d.kind) }}</div></td>
            <td class="text-caption">{{ d.expires_on ? 'expires ' + String(d.expires_on).slice(0, 10) : '' }}</td></tr>
            <tr v-if="!data.documents?.length"><td class="text-center text-medium-emphasis py-4">No documents.</td></tr></tbody></VTable></VCard></VCol>
      </VRow>
    </template>

    <template v-if="tab === 'prestart'">
      <VRow>
        <VCol v-if="can.record" cols="12" md="5"><VCard title="Today's check">
          <VCardText>
            <VSelect v-model="checkMachine" :items="machineItems" label="Machine" density="compact" class="mb-2" @update:model-value="loadChecklist" />
            <div v-for="c in checkItems" :key="c.item" class="d-flex align-center gap-2 mb-1">
              <VCheckbox v-model="c.ok" :label="c.item + (c.critical ? ' *' : '')" density="compact" hide-details />
              <VTextField v-if="!c.ok" v-model="c.note" placeholder="What is wrong" density="compact" hide-details />
            </div>
            <div class="text-caption mb-2">* A failed starred item stops the machine until it is repaired.</div>
            <VRow dense><VCol cols="6"><VTextField v-model.number="checkReading.hours_reading" type="number" label="Hour meter" density="compact" /></VCol>
              <VCol cols="6"><VTextField v-model.number="checkReading.odometer_km" type="number" label="Odometer" density="compact" /></VCol></VRow>
            <VBtn color="primary" :disabled="!checkMachine" @click="sendCheck">Record check</VBtn>
          </VCardText></VCard></VCol>
        <VCol cols="12" :md="can.record ? 7 : 12"><VCard title="Recent checks">
          <VTable density="compact"><tbody><tr v-for="c in data.checks" :key="c.id"><td>{{ c.on }}</td><td>{{ c.machine }}</td>
            <td><VChip size="x-small" :color="c.passed ? (c.failed.length ? 'warning' : 'success') : 'error'">{{ c.passed ? (c.failed.length ? 'Passed with faults' : 'Passed') : 'Failed' }}</VChip></td>
            <td class="text-caption">{{ c.failed.join(', ') }}</td><td class="text-end">{{ c.hours !== null ? num(c.hours, 1) + ' h' : '' }}</td></tr>
            <tr v-if="!data.checks?.length"><td class="text-center text-medium-emphasis py-4">No checks recorded.</td></tr></tbody></VTable></VCard></VCol>
      </VRow>
    </template>

    <template v-if="tab === 'fuel'">
      <div class="d-flex flex-wrap gap-2 mb-3 align-center">
        <VSelect v-model="selected" :items="machineItems" label="Machine" density="compact" hide-details style="max-inline-size: 260px" />
        <VBtn v-if="can.fuel" color="primary" prepend-icon="tabler-gas-station" :disabled="!data.tanks?.length || !selected" @click="open('fuel')">Issue fuel</VBtn>
        <VBtn v-if="can.record" variant="tonal" prepend-icon="tabler-route" :disabled="!selected" @click="open('trip')">Trip</VBtn>
        <span v-if="!data.tanks?.length" class="text-body-2 text-medium-emphasis align-self-center">Add a fuel-tank store on the stock control page to issue fuel.</span>
      </div>
      <VCard title="Worth a look" subtitle="Indicators to investigate, not proof that anything is wrong." class="mb-4">
        <VList v-if="data.indicators.length" density="compact" lines="two">
          <VListItem v-for="(f, i) in data.indicators" :key="i" :title="`${human(f.kind)}${f.machine ? ' · ' + f.machine : ''}`" :subtitle="f.detail" prepend-icon="tabler-flag">
            <template #append><span class="text-caption text-medium-emphasis" style="max-inline-size: 280px">{{ f.basis }}</span></template>
          </VListItem>
        </VList>
        <VCardText v-else class="text-medium-emphasis">Nothing stands out.</VCardText>
      </VCard>
      <VCard>
        <VTable density="compact">
          <thead><tr><th>Date</th><th>Machine</th><th>What</th><th class="text-end">Litres</th><th class="text-end">Cost</th><th class="text-end">Hours</th><th class="text-end">Km</th></tr></thead>
          <tbody><tr v-for="(l, i) in data.logs" :key="i"><td>{{ l.on }}</td><td>{{ l.machine }}</td>
            <td>{{ l.kind === 'trip' ? `Trip ${l.trip}` : 'Fuel' }}<div v-if="l.purpose" class="text-caption">{{ l.purpose }}</div></td>
            <td class="text-end">{{ l.litres ?? '' }}</td><td class="text-end">{{ l.cost ? money(l.cost) : '' }}</td>
            <td class="text-end">{{ l.hours ?? (l.kind === 'fuel' ? '—' : '') }}</td><td class="text-end">{{ l.distance ? `${l.distance} (${l.km})` : (l.km ?? '') }}</td></tr>
            <tr v-if="!data.logs?.length"><td colspan="7" class="text-center text-medium-emphasis py-4">No fuel issues or trips.</td></tr></tbody>
        </VTable>
      </VCard>
    </template>

    <template v-if="tab === 'risk'">
      <VAlert type="info" variant="tonal" class="mb-4">Ranks machines for inspection from their records. It does not predict when or whether a machine will fail.</VAlert>
      <VRow>
        <VCol v-for="r in data.machines" :key="r.machine.id" cols="12" md="6" lg="4">
          <VCard>
            <VCardItem><VCardTitle>{{ r.machine.code }} {{ r.machine.name }}</VCardTitle>
              <VCardSubtitle>Score {{ r.score }}/100 · confidence {{ r.confidence }} ({{ r.history_records }} records)</VCardSubtitle>
              <template #append><VChip :color="r.risk === 'high' ? 'error' : r.risk === 'medium' ? 'warning' : 'success'" size="small">{{ human(r.risk) }}</VChip></template></VCardItem>
            <VCardText>
              <div class="font-weight-medium mb-2">{{ r.recommendation }}</div>
              <div v-for="f in r.factors" :key="f.factor" class="text-body-2 mb-1"><strong>+{{ f.points }}</strong> {{ f.factor }}<div class="text-caption">{{ f.evidence }}</div></div>
              <div v-if="!r.factors.length" class="text-body-2 text-medium-emphasis">Nothing on record raises the risk.</div>
              <div class="text-caption text-medium-emphasis mt-2">{{ r.basis }}</div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </template>

    <FormDialog v-if="dialog?.kind === 'booking'" :model-value="true" :title="can.approve ? 'Book a machine' : 'Request a machine'" :url="route('agri.machinery.bookings.store')"
      :fields="bookingFields" :values="{ machine_id: machines[0]?.id }" :intro="can.approve ? undefined : 'The slot is held for your job until someone who manages the machinery approves it.'" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'fault'" :model-value="true" :title="`Fault on ${selectedCode()}`" :url="at('agri.machinery.faults.store')"
      :fields="faultFields" :values="{ severity: 'major' }" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'claim'" :model-value="true" :title="`Warranty claim: ${selectedCode()}`" :url="at('agri.machinery.claims.store')" :fields="claimFields"
      :values="{ claimed_on: today() }" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'document'" :model-value="true" :title="`Document for ${selectedCode()}`" :url="at('agri.machinery.documents.store')" :fields="docFields"
      :values="{ kind: 'manual' }" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'fuel'" :model-value="true" :title="`Fuel for ${selectedCode()}`" :url="at('agri.machinery.fuel')" :fields="fuelFields"
      :values="{ store_id: data.tanks?.[0]?.value, stock_item_id: options.fuel_items[0]?.value, date: today() }" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'trip'" :model-value="true" :title="`Trip: ${selectedCode()}`" :url="at('agri.machinery.trips')" :fields="tripFields"
      :values="{ date: today() }" @update:model-value="close" />
  </div>
</template>
