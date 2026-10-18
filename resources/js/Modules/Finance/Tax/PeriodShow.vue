<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { label, money, statusColor } from './useTax'

const route = ziggyRoute

const props = defineProps<{
  period: {
    uuid: string; label: string; tax_type: string; entity: string | null; start_date: string; end_date: string
    filing_due_date: string | null; payment_due_date: string | null; status: string; reopen_count: number; overdue: boolean
    registration_reference: string | null
    events: Array<{ from: string | null; to: string; reason: string | null; by: string; at: string | null }>
  }
  blockers: string[]
  summary: { fields: Array<{ field: string; label: string; taxable: string; tax: string; lines: number; mapped: boolean }>; output_tax: string; input_tax: string; net: string } | null
  counts: { lines: number; exceptions: number }
  reconciliation: { uuid: string; version: number; status: string; difference: string; exception_count: number; type: string } | null
  payroll: { by_type: Record<string, string>; runs: Array<{ run_id: number; reference: string; employees: number; paye: string; uif: string; sdl: string; eti: string }>; employees: number; total_payable: string } | null
  employees: Array<{ employee_id: number; name: string; gross: string; paye: string; uif: string; sdl: string; eti: string; exceptions: string[] }> | null
  taxReturn: { uuid: string; reference: string; version: number; status: string; amount_payable: string; submission_status: string; payment_status: string } | null
  can: { prepare: boolean; review: boolean; approve: boolean; manage: boolean; reopen: boolean; reconcile: boolean }
}>()

const isVat = computed(() => props.period.tax_type === 'VAT')
const isPaye = computed(() => props.period.tax_type === 'PAYE')
const hasReturn = computed(() => isVat.value || isPaye.value)
const processable = computed(() => ['open', 'in_preparation', 'rejected'].includes(props.period.status))

/** The next steps from here, each with the permission that takes it (mirrors the server's own rules). */
const steps = computed(() => {
  const s = props.period.status
  const c = props.can
  const out: Array<{ to: string; text: string; color: string; needs: 'reason' | 'reference' | null; allowed: boolean }> = []
  if (s === 'open' || s === 'rejected') out.push({ to: 'in_preparation', text: 'Start preparing', color: 'primary', needs: s === 'rejected' ? 'reason' : null, allowed: c.prepare })
  if (s === 'in_preparation') out.push({ to: 'under_review', text: 'Send for review', color: 'primary', needs: null, allowed: c.prepare })
  if (s === 'under_review') {
    out.push({ to: 'in_preparation', text: 'Return to preparer', color: 'warning', needs: 'reason', allowed: c.review || c.approve })
    out.push({ to: 'approved', text: 'Approve', color: 'success', needs: null, allowed: c.approve })
  }
  if (s === 'approved') {
    out.push({ to: 'in_preparation', text: 'Return to preparer', color: 'warning', needs: 'reason', allowed: c.approve })
    out.push({ to: 'submitted', text: 'Record submission', color: 'primary', needs: 'reference', allowed: c.approve })
  }
  if (s === 'submitted') {
    out.push({ to: 'rejected', text: 'Record SARS rejection', color: 'error', needs: 'reference', allowed: c.approve })
    out.push({ to: 'accepted', text: 'Record SARS acceptance', color: 'success', needs: 'reference', allowed: c.approve })
  }
  if (s === 'accepted') out.push({ to: 'closed', text: 'Close', color: 'blue-grey', needs: null, allowed: c.manage })
  if (s === 'closed') out.push({ to: 'locked', text: 'Lock', color: 'blue-grey', needs: null, allowed: c.manage })

  return out.filter(x => x.allowed)
})

const stepDialog = ref(false)
const step = ref<(typeof steps.value)[number] | null>(null)
const stepForm = useForm({ to: '', reason: '', reference: '' })
function openStep(s: (typeof steps.value)[number]) {
  step.value = s
  stepForm.reset()
  stepForm.to = s.to
  stepDialog.value = true
}
function doStep() {
  stepForm.post(route('tax.periods.transition', props.period.uuid), { preserveScroll: true, onSuccess: () => { stepDialog.value = false } })
}

const reopenDialog = ref(false)
const reopenForm = useForm({ reason: '' })
function doReopen() {
  reopenForm.post(route('tax.periods.reopen', props.period.uuid), { preserveScroll: true, onSuccess: () => { reopenDialog.value = false } })
}

const busy = ref(false)
function refresh() {
  busy.value = true
  router.post(route('tax.periods.refresh', props.period.uuid), {}, { preserveScroll: true, onFinish: () => { busy.value = false } })
}
function reconcile() {
  busy.value = true
  if (isPaye.value) router.post(route('tax.periods.reconcile_payroll', props.period.uuid), {}, { onFinish: () => { busy.value = false } })
  else router.post(route('tax.reconciliations.run'), { period: props.period.uuid }, { onFinish: () => { busy.value = false } })
}
function importPayroll() {
  busy.value = true
  router.post(route('tax.periods.import_payroll', props.period.uuid), {}, { preserveScroll: true, onFinish: () => { busy.value = false } })
}
function prepareReturn() {
  busy.value = true
  router.post(route('tax.returns.prepare', props.period.uuid), {}, { onFinish: () => { busy.value = false } })
}
const showEmployees = ref(false)
</script>

<template>
  <div class="pa-4">
    <VBtn variant="text" prepend-icon="tabler-arrow-left" class="mb-2" @click="router.get(route('tax.periods.index'))">Tax periods</VBtn>

    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div>
        <h4 class="text-h4">{{ period.tax_type }} · {{ period.label }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ period.entity }} · {{ period.registration_reference ?? 'no reference' }} · {{ period.start_date }} → {{ period.end_date }}
        </p>
      </div>
      <div class="d-flex flex-wrap gap-2 align-center">
        <VChip :color="statusColor[period.status]" label>{{ label(period.status) }}</VChip>
        <VBtn v-for="s in steps" :key="s.to" :color="s.color" variant="tonal" @click="openStep(s)">{{ s.text }}</VBtn>
        <VBtn v-if="can.reopen && ['submitted', 'accepted', 'closed', 'locked'].includes(period.status)" variant="text" color="warning" @click="reopenDialog = true">Reopen</VBtn>
      </div>
    </div>

    <VRow>
      <VCol cols="12" md="8">
        <VAlert v-if="blockers.length && ['in_preparation', 'under_review'].includes(period.status)" type="warning" variant="tonal" class="mb-4">
          <div class="font-weight-medium mb-1">Before this period can be approved</div>
          <ul class="ps-4"><li v-for="b in blockers" :key="b">{{ b }}</li></ul>
        </VAlert>
        <VAlert v-if="!processable" type="info" variant="tonal" density="compact" class="mb-4">
          This period is {{ label(period.status).toLowerCase() }}; its figures are fixed. Changes to its source documents are reported in the next open period.
        </VAlert>

        <VCard v-if="isVat && summary" border class="mb-4">
          <VCardItem>
            <VCardTitle class="text-body-1">VAT return supporting schedule</VCardTitle>
            <VCardSubtitle>{{ counts.lines }} register line(s) · {{ counts.exceptions }} need attention</VCardSubtitle>
            <template #append>
              <div class="d-flex gap-2">
                <VBtn v-if="can.prepare && processable" size="small" variant="tonal" prepend-icon="tabler-refresh" :loading="busy" @click="refresh">Build register</VBtn>
                <VBtn size="small" variant="text" @click="router.get(route('tax.transactions.index', { period: period.uuid }))">View lines</VBtn>
              </div>
            </template>
          </VCardItem>
          <VTable density="compact">
            <thead><tr><th>Field</th><th>Description</th><th class="text-right">Value (excl. VAT)</th><th class="text-right">VAT</th><th class="text-right">Lines</th></tr></thead>
            <tbody>
              <tr v-for="f in summary.fields" :key="f.field">
                <td>{{ f.field }}</td>
                <td>{{ f.label }}<VChip v-if="!f.mapped" size="x-small" color="warning" class="ms-1" label>default mapping</VChip></td>
                <td class="text-right">{{ money(f.taxable) }}</td>
                <td class="text-right">{{ money(f.tax) }}</td>
                <td class="text-right">{{ f.lines }}</td>
              </tr>
              <tr v-if="!summary.fields.length"><td colspan="5" class="text-center text-medium-emphasis py-4">No register lines yet — build the register.</td></tr>
            </tbody>
            <tfoot>
              <tr><td colspan="3" class="text-right">Output VAT</td><td class="text-right">{{ money(summary.output_tax) }}</td><td /></tr>
              <tr><td colspan="3" class="text-right">Input VAT</td><td class="text-right">{{ money(summary.input_tax) }}</td><td /></tr>
              <tr class="font-weight-bold">
                <td colspan="3" class="text-right">{{ Number(summary.net) >= 0 ? 'Payable' : 'Refundable' }}
                  <span class="text-caption font-weight-regular" :class="processable ? 'text-warning' : 'text-success'">({{ processable ? 'draft' : 'approved' }})</span>
                </td>
                <td class="text-right">{{ money(Math.abs(Number(summary.net))) }}</td><td />
              </tr>
            </tfoot>
          </VTable>
        </VCard>

        <VCard v-if="isPaye && payroll" border class="mb-4">
          <VCardItem>
            <VCardTitle class="text-body-1">Payroll taxes (EMP201)</VCardTitle>
            <VCardSubtitle>From released pay runs — PAYE as payroll calculated it. {{ payroll.employees }} employee(s).</VCardSubtitle>
            <template #append>
              <VBtn v-if="can.prepare && processable" size="small" variant="tonal" prepend-icon="tabler-download" :loading="busy" @click="importPayroll">Import payroll</VBtn>
            </template>
          </VCardItem>
          <VTable density="compact">
            <thead><tr><th>Run</th><th class="text-right">Employees</th><th class="text-right">PAYE</th><th class="text-right">UIF</th><th class="text-right">SDL</th><th class="text-right">ETI</th></tr></thead>
            <tbody>
              <tr v-for="r in payroll.runs" :key="r.run_id">
                <td>{{ r.reference }}</td><td class="text-right">{{ r.employees }}</td><td class="text-right">{{ money(r.paye) }}</td>
                <td class="text-right">{{ money(r.uif) }}</td><td class="text-right">{{ money(r.sdl) }}</td><td class="text-right">{{ money(r.eti) }}</td>
              </tr>
              <tr v-if="!payroll.runs.length"><td colspan="6" class="text-center text-medium-emphasis py-4">No released pay run imported yet.</td></tr>
            </tbody>
            <tfoot>
              <tr class="font-weight-bold"><td colspan="2">Total (PAYE + UIF + SDL − ETI)</td><td class="text-right" colspan="4">{{ money(payroll.total_payable) }}</td></tr>
            </tfoot>
          </VTable>
          <VCardText v-if="employees">
            <VBtn size="small" variant="text" @click="showEmployees = !showEmployees">{{ showEmployees ? 'Hide' : 'Show' }} employee-level detail</VBtn>
            <VTable v-if="showEmployees" density="compact">
              <thead><tr><th>Employee</th><th class="text-right">Gross</th><th class="text-right">PAYE</th><th class="text-right">UIF</th><th class="text-right">SDL</th><th class="text-right">ETI</th></tr></thead>
              <tbody>
                <tr v-for="e in employees" :key="e.employee_id">
                  <td>{{ e.name || 'Unknown employee' }}<div v-for="x in e.exceptions" :key="x" class="text-caption text-warning">{{ x }}</div></td>
                  <td class="text-right">{{ money(e.gross) }}</td><td class="text-right">{{ money(e.paye) }}</td><td class="text-right">{{ money(e.uif) }}</td>
                  <td class="text-right">{{ money(e.sdl) }}</td><td class="text-right">{{ money(e.eti) }}</td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
          <VCardText v-else class="text-caption text-medium-emphasis">Employee-level figures need the "view payroll tax" permission.</VCardText>
        </VCard>

        <VCard v-if="hasReturn" border class="mb-4">
          <VCardItem>
            <VCardTitle class="text-body-1">Return</VCardTitle>
            <template #append>
              <VBtn v-if="can.prepare && processable" size="small" variant="tonal" :loading="busy" @click="prepareReturn">
                {{ taxReturn ? 'Prepare a new version' : 'Prepare return' }}
              </VBtn>
            </template>
          </VCardItem>
          <VCardText v-if="taxReturn" class="d-flex flex-wrap align-center gap-3">
            <span class="font-weight-medium">{{ taxReturn.reference }}</span>
            <VChip :color="statusColor[taxReturn.status]" label size="small">{{ label(taxReturn.status) }}</VChip>
            <span>{{ money(taxReturn.amount_payable) }}</span>
            <span class="text-caption">SARS {{ label(taxReturn.submission_status) }} · payment {{ label(taxReturn.payment_status) }}</span>
            <VSpacer />
            <VBtn size="small" variant="text" @click="router.get(route('tax.returns.show', taxReturn.uuid))">Open</VBtn>
          </VCardText>
          <VCardText v-else class="text-medium-emphasis">No return yet. Build the register{{ isPaye ? ' (import payroll)' : '' }}, reconcile, then prepare it here.</VCardText>
        </VCard>

        <VCard v-if="isVat || isPaye" border>
          <VCardItem>
            <VCardTitle class="text-body-1">Reconciliation to the ledger</VCardTitle>
            <template #append>
              <VBtn v-if="can.reconcile && processable" size="small" variant="tonal" :loading="busy" @click="reconcile">Run reconciliation</VBtn>
            </template>
          </VCardItem>
          <VCardText v-if="reconciliation" class="d-flex align-center gap-3">
            <VChip :color="statusColor[reconciliation.status]" label>v{{ reconciliation.version }} · {{ label(reconciliation.status) }}</VChip>
            <span>Difference {{ money(reconciliation.difference) }}</span>
            <span v-if="reconciliation.exception_count" class="text-error">{{ reconciliation.exception_count }} exception(s)</span>
            <VSpacer />
            <VBtn size="small" variant="text" @click="router.get(route('tax.reconciliations.show', reconciliation.uuid))">Open</VBtn>
          </VCardText>
          <VCardText v-else class="text-medium-emphasis">Not reconciled yet. {{ isPaye ? 'Import payroll, then reconcile it to the payroll ledger accounts.' : 'Build the register first, then reconcile it to the VAT control accounts.' }}</VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard border class="mb-4">
          <VCardItem><VCardTitle class="text-body-1">Deadlines</VCardTitle></VCardItem>
          <VCardText>
            <div class="d-flex justify-space-between"><span class="text-medium-emphasis">Return</span><span :class="period.overdue ? 'text-error' : ''">{{ period.filing_due_date ?? '—' }}</span></div>
            <div class="d-flex justify-space-between"><span class="text-medium-emphasis">Payment</span><span>{{ period.payment_due_date ?? '—' }}</span></div>
            <p class="text-caption text-medium-emphasis mt-2 mb-0">From the published SARS rule for this tax. Public holidays are not applied — adjust on the calendar if one applies.</p>
          </VCardText>
        </VCard>
        <VCard border>
          <VCardItem><VCardTitle class="text-body-1">History</VCardTitle></VCardItem>
          <VTimeline density="compact" side="end" class="px-4 pb-4">
            <VTimelineItem v-for="(e, i) in period.events" :key="i" size="x-small" :dot-color="statusColor[e.to] ?? 'grey'">
              <div class="text-body-2">{{ label(e.to) }}</div>
              <div class="text-caption text-medium-emphasis">{{ e.by }} · {{ e.at }}</div>
              <div v-if="e.reason" class="text-caption">{{ e.reason }}</div>
            </VTimelineItem>
          </VTimeline>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="stepDialog" max-width="520">
      <VCard v-if="step" :title="step.text">
        <VCardText>
          <VTextField v-if="step.needs === 'reference'" v-model="stepForm.reference" label="SARS reference / acknowledgement number" :error-messages="stepForm.errors.reference"
                      hint="Recorded as evidence. A return is never marked submitted or accepted without one." persistent-hint class="mb-3" />
          <VTextarea v-model="stepForm.reason" :label="step.needs === 'reason' ? 'Why it is going back' : 'Note (optional)'" rows="2" auto-grow :error-messages="stepForm.errors.reason" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="stepDialog = false">Cancel</VBtn><VBtn :color="step.color" :loading="stepForm.processing" @click="doStep">{{ step.text }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="reopenDialog" max-width="520">
      <VCard title="Reopen period">
        <VCardText>
          <p class="text-body-2">Reopening returns the period to preparation. Its history, earlier figures and any submission evidence are kept. The reason is recorded on the audit trail.</p>
          <VTextarea v-model="reopenForm.reason" label="Reason for reopening" rows="3" auto-grow :error-messages="reopenForm.errors.reason" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="reopenDialog = false">Cancel</VBtn><VBtn color="warning" :loading="reopenForm.processing" :disabled="reopenForm.reason.trim().length < 10" @click="doReopen">Reopen</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
