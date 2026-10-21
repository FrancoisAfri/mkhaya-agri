<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import axios from 'axios'
import { route } from 'ziggy-js'
import PageErrors from '../Components/PageErrors.vue'
import FormDialog, { type FormField } from '../Components/FormDialog.vue'
import FarmFilter from '../Components/FarmFilter.vue'
import { human, money, num, statusColor } from '../Components/agri'

/** Phase 2 §6: budgets and their approval, cost reports, cash flow with scenarios, reconciliation and export. */
const props = defineProps<{
  tab: 'budgets' | 'costs' | 'cashflow' | 'reconcile' | 'exports'
  from: string
  to: string
  farm: null | { id: string; name: string }
  farms: Array<{ id: string; name: string }>
  data: any
  options: { categories: string[]; scenario: Record<string, number>; frequencies: string[]; farms: any[] }
  can: { manage: boolean; approve: boolean }
}>()
const TABS = [
  { key: 'budgets', title: 'Budgets', icon: 'tabler-report-money' }, { key: 'costs', title: 'Cost reports', icon: 'tabler-chart-pie' },
  { key: 'cashflow', title: 'Cash flow', icon: 'tabler-cash' }, { key: 'reconcile', title: 'Reconciliation', icon: 'tabler-scale' },
  { key: 'exports', title: 'Accounting export', icon: 'tabler-file-export' },
]
const keep = { preserveScroll: true }
const period = ref({ from: props.from, to: props.to })
const query = (extra: Record<string, any> = {}) => ({ farm: props.farm?.id, from: period.value.from, to: period.value.to, ...extra })
const go = (tab: string, extra: Record<string, any> = {}) => router.get(route('agri.finance.intelligence', tab), query(extra), { preserveState: false })
const dialog = ref<null | { kind: string; row?: any }>(null)
const close = (v: boolean) => { if (!v) dialog.value = null }
const csrf = computed(() => (usePage().props as any).csrf_token ?? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'))

// ── budgets ──
const submit = (l: any) => router.post(route('agri.finance.budgets.submit', l.id), {}, keep)
const decide = (l: any, approve: boolean) => router.post(route('agri.finance.budgets.decide', l.id), { approve }, keep)
const decideRevision = (id: string, approve: boolean) => { const note = approve ? null : prompt('Why is the revision rejected?'); if (approve || note) router.post(route('agri.finance.revisions.decide', id), { approve, note }, keep) }
const reviseFields: FormField[] = [{ key: 'amount', label: 'New amount', type: 'number', required: true }, { key: 'reason', label: 'Why it changes', type: 'textarea', required: true, cols: 12 }]
const budgetFields: FormField[] = [
  { key: 'category', label: 'Category', type: 'select', required: true, items: props.options.categories.map(c => ({ title: human(c), value: c })) },
  { key: 'description', label: 'Description' }, { key: 'amount', label: 'Amount', type: 'number', required: true },
  { key: 'period_start', label: 'From', type: 'date', required: true, cols: 6 }, { key: 'period_end', label: 'To', type: 'date', required: true, cols: 6 },
]

// ── cash flow ──
const scenario = ref<Record<string, number>>({ ...props.options.scenario, ...(props.data.forecast?.scenario ?? {}) })
const runScenario = () => go('cashflow', { s: scenario.value })
const sources = computed(() => {
  const w = props.data.forecast?.weeks ?? []
  return { in: [...new Set(w.flatMap((x: any) => Object.keys(x.in)))] as string[], out: [...new Set(w.flatMap((x: any) => Object.keys(x.out)))] as string[] }
})
const loanFields: FormField[] = [
  { key: 'farm_id', label: 'Farm (optional)', type: 'select', items: props.options.farms }, { key: 'lender', label: 'Lender', required: true },
  { key: 'reference', label: 'Reference', cols: 6 }, { key: 'principal', label: 'Principal', type: 'number', required: true, cols: 6 },
  { key: 'annual_rate_pct', label: 'Interest % a year', type: 'number', cols: 6 }, { key: 'payment', label: 'Instalment', type: 'number', required: true, cols: 6 },
  { key: 'frequency', label: 'Paid', type: 'select', required: true, items: props.options.frequencies.map(f => ({ title: human(f), value: f })), cols: 6 },
  { key: 'instalments', label: 'Instalments', type: 'number', required: true, cols: 6 }, { key: 'first_payment_on', label: 'First payment', type: 'date', required: true },
]
const capexFields: FormField[] = [
  { key: 'farm_id', label: 'Farm (optional)', type: 'select', items: props.options.farms }, { key: 'description', label: 'What', required: true },
  { key: 'amount', label: 'Amount', type: 'number', required: true, cols: 6 }, { key: 'planned_on', label: 'Planned for', type: 'date', required: true, cols: 6 },
  { key: 'status', label: 'Status', type: 'select', items: [{ title: 'Planned', value: 'planned' }, { title: 'Committed', value: 'committed' }] },
]
const capexStatus = (c: any, status: string) => router.patch(route('agri.finance.capex.update', c.uuid), { status }, keep)
const closeLoan = (l: any) => { if (confirm(`Close the ${l.lender} loan?`)) router.post(route('agri.finance.loans.close', l.uuid), {}, keep) }

// ── reconciliation / export ──
const traced = ref<any>(null)
const journalId = ref<number | null>(null)
async function trace() { if (journalId.value) traced.value = (await axios.get(route('agri.finance.journals.trace', journalId.value))).data }
const exportForm = ref({ target: 'Sage', from: props.from, to: props.to })
const ackFile = ref<File | null>(null)
const acknowledge = (e: any) => { if (ackFile.value) router.post(route('agri.finance.exports.acknowledge', e.uuid), { file: ackFile.value }, { ...keep, forceFormData: true, onSuccess: () => { ackFile.value = null } }) }
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div>
        <div class="text-body-2 mb-1"><Link :href="route('agri.finance.index', { farm: farm?.id })">Farm finance</Link> / {{ farm?.name }}</div>
        <h4 class="text-h4 mb-1">Financial intelligence</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">Figures come from the shared ledger; each one says where it comes from.</p>
      </div>
      <div class="d-flex flex-wrap gap-2 align-center">
        <VTextField v-model="period.from" type="date" label="From" density="compact" hide-details style="max-inline-size: 160px" />
        <VTextField v-model="period.to" type="date" label="To" density="compact" hide-details style="max-inline-size: 160px" />
        <VBtn variant="tonal" @click="go(tab)">Apply</VBtn>
        <FarmFilter :farms="farms" :model-value="farm?.id" :allow-all="false" />
      </div>
    </div>
    <div class="d-flex flex-wrap gap-2 mb-5">
      <VChip v-for="t in TABS" :key="t.key" :color="tab === t.key ? 'primary' : undefined" :variant="tab === t.key ? 'flat' : 'tonal'" :prepend-icon="t.icon" @click="go(t.key)">{{ t.title }}</VChip>
    </div>

    <template v-if="tab === 'budgets'">
      <div class="d-flex gap-2 mb-3"><VBtn v-if="can.manage && farm" color="primary" prepend-icon="tabler-plus" @click="dialog = { kind: 'budget' }">Budget line</VBtn></div>
      <VCard subtitle="Only approved lines count. Actual is posted journals with the line's dimensions; committed is orders and reserved materials; forecast adds the run rate to the period end.">
        <VTable density="compact">
          <thead><tr><th>Line</th><th>Period</th><th>Status</th><th class="text-end">Budget</th><th class="text-end">Actual</th><th class="text-end">Committed</th><th class="text-end">Forecast</th><th class="text-end">Variance</th><th /></tr></thead>
          <tbody>
            <tr v-for="l in data.lines" :key="l.id">
              <td>{{ human(l.category) }}<div class="text-caption">{{ l.description }}<template v-if="l.enterprise"> · {{ l.enterprise_label ?? l.enterprise }}</template><template v-if="l.version > 1"> · v{{ l.version }}</template></div>
                <div v-if="l.pending_revision" class="text-caption text-warning">Revision to {{ money(l.pending_revision.to) }} waiting: {{ l.pending_revision.reason }}</div></td>
              <td class="text-caption">{{ l.period[0] }} – {{ l.period[1] }}</td>
              <td><VChip size="x-small" :color="statusColor(l.status)">{{ human(l.status) }}</VChip></td>
              <td class="text-end">{{ money(l.budget) }}</td><td class="text-end">{{ money(l.actual) }}</td><td class="text-end">{{ money(l.committed) }}</td><td class="text-end">{{ money(l.forecast) }}</td>
              <td class="text-end" :class="l.variance < 0 ? 'text-error' : 'text-success'">{{ money(l.variance) }}<div v-if="l.used_pct !== null" class="text-caption">{{ l.used_pct }}% used</div></td>
              <td class="text-end text-no-wrap">
                <VBtn v-if="can.manage && ['draft', 'rejected'].includes(l.status)" size="x-small" variant="tonal" @click="submit(l)">Submit</VBtn>
                <template v-if="can.approve && l.status === 'submitted'"><VBtn size="x-small" color="success" variant="tonal" class="me-1" @click="decide(l, true)">Approve</VBtn>
                  <VBtn size="x-small" color="error" variant="text" @click="decide(l, false)">Reject</VBtn></template>
                <VBtn v-if="can.manage && l.status === 'approved' && !l.pending_revision" size="x-small" variant="text" @click="dialog = { kind: 'revise', row: l }">Revise</VBtn>
                <template v-if="can.approve && l.pending_revision"><VBtn size="x-small" color="success" variant="tonal" class="me-1" @click="decideRevision(l.pending_revision.id, true)">Approve revision</VBtn>
                  <VBtn size="x-small" color="error" variant="text" @click="decideRevision(l.pending_revision.id, false)">Reject</VBtn></template>
              </td>
            </tr>
            <tr v-if="!data.lines?.length"><td colspan="9" class="text-center text-medium-emphasis py-4">No budget lines in this period.</td></tr>
          </tbody>
        </VTable>
      </VCard>
      <VCard v-if="data.revisions?.length" title="Revision history" class="mt-4">
        <VTable density="compact"><tbody><tr v-for="r in data.revisions" :key="r.id"><td>{{ human(r.line?.category ?? '') }} {{ r.line?.description }}</td>
          <td class="text-end">{{ money(r.from_amount) }} → {{ money(r.to_amount) }}</td><td>{{ r.reason }}</td>
          <td><VChip size="x-small" :color="statusColor(r.status)">{{ human(r.status) }}</VChip><div v-if="r.decision_note" class="text-caption">{{ r.decision_note }}</div></td></tr></tbody></VTable>
      </VCard>
    </template>

    <template v-if="tab === 'costs'">
      <VCard title="Per worker" subtitle="Approved time and piecework." class="mb-4">
        <VTable density="compact"><thead><tr><th>Worker</th><th class="text-end">Days</th><th class="text-end">Hours</th><th class="text-end">Overtime</th><th class="text-end">Time</th><th class="text-end">Piecework</th><th class="text-end">Total</th><th class="text-end">Per hour</th></tr></thead>
          <tbody><tr v-for="w in data.workers" :key="w.worker"><td>{{ w.worker }}<div class="text-caption">{{ human(w.category ?? '') }}</div></td><td class="text-end">{{ w.days }}</td><td class="text-end">{{ w.hours }}</td>
            <td class="text-end">{{ w.overtime_hours }}</td><td class="text-end">{{ money(w.time_cost) }}</td><td class="text-end">{{ money(w.piecework) }}</td><td class="text-end font-weight-medium">{{ money(w.total) }}</td>
            <td class="text-end">{{ w.per_hour !== null ? money(w.per_hour) : '—' }}</td></tr>
            <tr v-if="!data.workers?.length"><td colspan="8" class="text-center text-medium-emphasis py-4">No approved time in this period.</td></tr></tbody></VTable>
      </VCard>
      <VCard title="Per activity" subtitle="Inputs (corrections netted), labour and machine time; per hectare covered." class="mb-4">
        <VTable density="compact"><thead><tr><th>Activity</th><th>Crop</th><th>Done</th><th class="text-end">Inputs</th><th class="text-end">Labour</th><th class="text-end">Machinery</th><th class="text-end">Total</th><th class="text-end">Per ha</th></tr></thead>
          <tbody><tr v-for="(a, i) in data.activities" :key="i"><td>{{ a.activity }}<div class="text-caption">{{ human(a.type) }}</div></td><td>{{ a.crop }}</td><td>{{ a.on }}</td>
            <td class="text-end">{{ money(a.inputs) }}</td><td class="text-end">{{ money(a.labour) }}</td><td class="text-end">{{ money(a.machinery) }}</td><td class="text-end font-weight-medium">{{ money(a.total) }}</td>
            <td class="text-end">{{ a.per_ha !== null ? money(a.per_ha) : '—' }}</td></tr>
            <tr v-if="!data.activities?.length"><td colspan="8" class="text-center text-medium-emphasis py-4">No completed activities in this period.</td></tr></tbody></VTable>
      </VCard>
      <VCard title="Per machine hour" subtitle="Fuel, repairs and services posted to the machine, over the hours logged, against its charge-out rate." class="mb-4">
        <VTable density="compact"><thead><tr><th>Machine</th><th class="text-end">Hours</th><th class="text-end">Fuel (l)</th><th>Costs</th><th class="text-end">Per hour</th><th class="text-end">Charge rate</th></tr></thead>
          <tbody><tr v-for="m in data.machines" :key="m.machine"><td>{{ m.machine }}</td><td class="text-end">{{ m.hours }}</td><td class="text-end">{{ m.fuel_litres }}</td>
            <td><VChip v-for="(v, k) in m.costs" :key="k" size="x-small" class="me-1">{{ human(String(k)) }} {{ money(v) }}</VChip></td>
            <td class="text-end" :class="m.under_charged ? 'text-error' : ''">{{ m.per_hour !== null ? money(m.per_hour) : '—' }}</td><td class="text-end">{{ m.charge_rate !== null ? money(m.charge_rate) : '—' }}</td></tr>
            <tr v-if="!data.machines?.length"><td colspan="6" class="text-center text-medium-emphasis py-4">No machine costs or hours in this period.</td></tr></tbody></VTable>
      </VCard>
      <VRow>
        <VCol v-for="g in [{ key: 'by_buyer', title: 'Margin per buyer', col: 'buyer' }, { key: 'by_channel', title: 'Margin per channel', col: 'channel' }]" :key="g.key" cols="12" md="6">
          <VCard :title="g.title" subtitle="Delivered against commitments, less the crop's full cost per saleable unit.">
            <VTable density="compact"><thead><tr><th>{{ human(g.col) }}</th><th class="text-end">Revenue</th><th class="text-end">Margin</th><th class="text-end">%</th></tr></thead>
              <tbody><tr v-for="r in data[g.key]" :key="r[g.col]"><td>{{ human(r[g.col]) }}</td><td class="text-end">{{ money(r.revenue) }}</td>
                <td class="text-end">{{ r.margin !== null ? money(r.margin) : '—' }}</td><td class="text-end">{{ r.margin_pct ?? '—' }}</td></tr>
                <tr v-if="!data[g.key]?.length"><td colspan="4" class="text-center text-medium-emphasis py-4">No deliveries yet.</td></tr></tbody></VTable>
          </VCard>
        </VCol>
      </VRow>
    </template>

    <template v-if="tab === 'cashflow'">
      <VCard title="Scenario" subtitle="A scenario changes the assumptions, never the records." class="mb-4">
        <VCardText><VRow dense>
          <VCol v-for="(v, k) in options.scenario" :key="k" cols="6" md="3"><VTextField v-model.number="scenario[k]" type="number" :label="human(String(k))" density="compact" hide-details /></VCol>
        </VRow>
          <div class="d-flex gap-2 mt-3"><VBtn color="primary" @click="runScenario">Run</VBtn><VBtn variant="text" @click="scenario = { ...options.scenario }; runScenario()">Reset</VBtn></div></VCardText>
      </VCard>
      <VRow class="mb-1">
        <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">Opening</div><div class="text-h5">{{ money(data.forecast.opening) }}</div></VCardText></VCard></VCol>
        <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">Closing</div><div class="text-h5">{{ money(data.forecast.closing) }}</div></VCardText></VCard></VCol>
        <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">Lowest</div><div class="text-h5" :class="data.forecast.lowest < 0 ? 'text-error' : ''">{{ money(data.forecast.lowest) }}</div></VCardText></VCard></VCol>
        <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">First shortage</div><div class="text-h5" :class="data.forecast.first_shortage ? 'text-error' : 'text-success'">{{ data.forecast.first_shortage ?? 'None' }}</div></VCardText></VCard></VCol>
      </VRow>
      <VCard class="mb-4">
        <VTable density="compact" fixed-header height="480">
          <thead><tr><th>Week</th><th v-for="s in sources.in" :key="'i' + s" class="text-end">In: {{ human(s) }}</th><th v-for="s in sources.out" :key="'o' + s" class="text-end">Out: {{ human(s) }}</th><th class="text-end">Closing</th></tr></thead>
          <tbody><tr v-for="w in data.forecast.weeks" :key="w.start"><td class="text-no-wrap">{{ w.start }}</td>
            <td v-for="s in sources.in" :key="'i' + s" class="text-end">{{ w.in[s] ? money(w.in[s]) : '' }}</td><td v-for="s in sources.out" :key="'o' + s" class="text-end">{{ w.out[s] ? money(w.out[s]) : '' }}</td>
            <td class="text-end font-weight-medium" :class="w.closing < 0 ? 'text-error' : ''">{{ money(w.closing) }}</td></tr></tbody>
        </VTable>
      </VCard>
      <VCard title="Assumptions" class="mb-4"><VCardText><ul class="ps-4"><li v-for="(a, i) in data.forecast.assumptions" :key="i">{{ a }}</li></ul></VCardText></VCard>
      <VRow>
        <VCol cols="12" md="6"><VCard title="Loans"><template #append><VBtn v-if="can.manage" size="small" variant="tonal" prepend-icon="tabler-plus" @click="dialog = { kind: 'loan' }">Loan</VBtn></template>
          <VTable density="compact"><tbody><tr v-for="l in data.loans" :key="l.id"><td>{{ l.lender }}<div class="text-caption">{{ l.reference }}</div></td>
            <td class="text-end">{{ money(l.payment) }} {{ l.frequency }} × {{ l.instalments }}<div class="text-caption">from {{ String(l.first_payment_on).slice(0, 10) }}</div></td>
            <td><VChip size="x-small" :color="l.is_active ? 'success' : undefined">{{ l.is_active ? 'Active' : 'Closed' }}</VChip></td>
            <td class="text-end"><VBtn v-if="can.manage && l.is_active" size="x-small" variant="text" @click="closeLoan(l)">Close</VBtn></td></tr>
            <tr v-if="!data.loans.length"><td class="text-center text-medium-emphasis py-4">No loans.</td></tr></tbody></VTable></VCard></VCol>
        <VCol cols="12" md="6"><VCard title="Capital spending"><template #append><VBtn v-if="can.manage" size="small" variant="tonal" prepend-icon="tabler-plus" @click="dialog = { kind: 'capex' }">Plan</VBtn></template>
          <VTable density="compact"><tbody><tr v-for="c in data.capex" :key="c.id"><td>{{ c.description }}<div class="text-caption">{{ String(c.planned_on).slice(0, 10) }}</div></td>
            <td class="text-end">{{ money(c.amount) }}</td>
            <td><VSelect v-if="can.manage" :model-value="c.status" :items="['planned', 'committed', 'done', 'dropped']" density="compact" hide-details @update:model-value="capexStatus(c, $event)" />
              <VChip v-else size="x-small">{{ human(c.status) }}</VChip></td></tr>
            <tr v-if="!data.capex.length"><td class="text-center text-medium-emphasis py-4">No capital spending planned.</td></tr></tbody></VTable></VCard></VCol>
      </VRow>
    </template>

    <template v-if="tab === 'reconcile'">
      <VAlert :type="data.ok ? 'success' : 'warning'" variant="tonal" class="mb-4">{{ data.ok ? 'Every farm record in this period is in the books, and the totals agree.' : 'Some records are not in the books, or the totals differ. See below.' }}</VAlert>
      <VRow>
        <VCol cols="12" md="6"><VCard title="Records posted">
          <VTable density="compact"><thead><tr><th>Record</th><th class="text-end">Records</th><th class="text-end">Posted</th><th class="text-end">Missing</th></tr></thead>
            <tbody><tr v-for="r in data.records" :key="r.kind"><td>{{ r.kind }}</td><td class="text-end">{{ r.records }}</td><td class="text-end">{{ r.posted }}</td>
              <td class="text-end" :class="r.missing ? 'text-error font-weight-medium' : ''">{{ r.missing }}</td></tr></tbody></VTable></VCard></VCol>
        <VCol cols="12" md="6"><VCard title="Operational against the books">
          <VTable density="compact"><thead><tr><th>Category</th><th class="text-end">Farm records</th><th class="text-end">Books</th><th class="text-end">Difference</th></tr></thead>
            <tbody><tr v-for="r in data.by_category" :key="r.category"><td>{{ human(r.category) }}</td><td class="text-end">{{ money(r.operational) }}</td><td class="text-end">{{ money(r.books) }}</td>
              <td class="text-end" :class="Math.abs(r.difference) >= 0.01 ? 'text-error' : ''">{{ money(r.difference) }}</td></tr></tbody></VTable></VCard></VCol>
      </VRow>
      <VCard v-if="data.untraced?.length" title="Not in the books" class="mt-4">
        <VTable density="compact"><tbody><tr v-for="(u, i) in data.untraced" :key="i"><td>{{ u.kind }}</td><td>{{ u.record }}</td></tr></tbody></VTable>
      </VCard>
      <VCard title="Trace a journal" subtitle="From a journal number in the ledger back to the farm record it came from." class="mt-4">
        <VCardText><div class="d-flex gap-2"><VTextField v-model.number="journalId" type="number" label="Journal id" density="compact" hide-details style="max-inline-size: 200px" />
          <VBtn variant="tonal" @click="trace">Trace</VBtn></div>
          <div v-if="traced" class="mt-3 text-body-2">
            <div><strong>{{ traced.journal.journal_no }}</strong> · {{ traced.journal.journal_date }} · {{ traced.journal.description }}</div>
            <div>From: {{ human(traced.origin.record ?? '') }} {{ traced.origin.id ?? traced.origin.uuid ?? traced.origin.ref ?? '' }}</div>
            <div class="text-caption">Key {{ traced.key }}<template v-if="traced.reverses"> · reverses journal {{ traced.reverses }}</template></div>
          </div></VCardText>
      </VCard>
    </template>

    <template v-if="tab === 'exports'">
      <VCard v-if="can.manage" title="Export farm journals" subtitle="Each journal goes once. Failed ones go again in the next export." class="mb-4">
        <VCardText>
          <form method="post" :action="route('agri.finance.exports.store')" class="d-flex flex-wrap gap-2 align-center">
            <input type="hidden" name="_token" :value="csrf">
            <VTextField v-model="exportForm.target" name="target" label="Accounting system" density="compact" hide-details style="max-inline-size: 200px" />
            <VTextField v-model="exportForm.from" name="from" type="date" label="From" density="compact" hide-details style="max-inline-size: 160px" />
            <VTextField v-model="exportForm.to" name="to" type="date" label="To" density="compact" hide-details style="max-inline-size: 160px" />
            <VBtn type="submit" color="primary" prepend-icon="tabler-download">Export</VBtn>
          </form>
        </VCardText>
      </VCard>
      <VCard>
        <VTable density="compact">
          <thead><tr><th>Export</th><th>Period</th><th class="text-end">Journals</th><th class="text-end">Total</th><th>Status</th><th /></tr></thead>
          <tbody>
            <tr v-for="e in data.exports" :key="e.id"><td>{{ e.target }}<div class="text-caption">{{ String(e.created_at).slice(0, 16).replace('T', ' ') }}</div></td>
              <td class="text-caption">{{ String(e.period_from).slice(0, 10) }} – {{ String(e.period_to).slice(0, 10) }}</td>
              <td class="text-end">{{ e.journals }}</td><td class="text-end">{{ money(e.total) }}</td>
              <td><VChip size="x-small" :color="statusColor(e.status)">{{ human(e.status) }}</VChip>
                <div v-for="f in e.failed" :key="f.external_ref" class="text-caption text-error">{{ f.external_ref }}: {{ f.error }}</div></td>
              <td class="text-end" style="min-inline-size: 260px"><div v-if="can.manage && e.status !== 'acknowledged'" class="d-flex gap-1 align-center">
                <VFileInput v-model="ackFile" accept=".json" label="Results file" density="compact" hide-details />
                <VBtn size="x-small" variant="tonal" :disabled="!ackFile" @click="acknowledge(e)">Record</VBtn></div></td></tr>
            <tr v-if="!data.exports.length"><td colspan="6" class="text-center text-medium-emphasis py-4">Nothing exported yet.</td></tr>
          </tbody>
        </VTable>
      </VCard>
    </template>

    <FormDialog v-if="dialog?.kind === 'budget'" :model-value="true" title="Budget line" :url="route('agri.finance.budgets.store')" :fields="budgetFields"
      :values="{ farm_id: farm?.id, period_start: from, period_end: to }" intro="It is drafted; submit it, and it counts once someone else approves it." @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'revise'" :model-value="true" :title="`Revise ${human(dialog.row.category)}`" :url="route('agri.finance.budgets.revise', dialog.row.id)"
      :fields="reviseFields" :values="{ amount: dialog.row.budget }" intro="The approved amount stands until the revision is approved." @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'loan'" :model-value="true" title="Loan" :url="route('agri.finance.loans.store')" :fields="loanFields" :values="{ frequency: 'monthly' }" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'capex'" :model-value="true" title="Capital spending" :url="route('agri.finance.capex.store')" :fields="capexFields" :values="{ status: 'planned' }" @update:model-value="close" />
  </div>
</template>
