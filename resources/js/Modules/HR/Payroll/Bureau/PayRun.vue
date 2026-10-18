<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()
const u = (key: string, a?: Record<string, unknown>) => t('bureau.ui.' + key, a ?? {})
const k = (key: string, a?: Record<string, unknown>) => t('bureau.emp.' + key, a ?? {})
const v = (key: string, a?: Record<string, unknown>) => t('bureau.validation.' + key, a ?? {})
const l = (key: string, a?: Record<string, unknown>) => t('bureau.lifecycle.' + key, a ?? {})
interface PayrollEmployee { uuid: string; name: string; number: string | null; ident: string | null; status: string }
interface Finding {
  severity: 'blocker' | 'warning'; category: string; code: string
  message: string; employee: string | null; employee_number: string | null
}
interface Report { blockers: number; warnings: number; checked: number; findings: Finding[] }

const props = defineProps<{
  company: { uuid: string; name: string }
  run: Record<string, any>
  emp201: Record<string, number>
  payslips: any[]
  employees: PayrollEmployee[]
  validation: Report | null
  roster: { uuid: string; name: string; number: string | null; status: string; via: string }[]
  approval: {
    steps: { sequence: number; name: string; approver: string; current: boolean; done: boolean }[]
    current_step: number | null
    round: number
    can_sign: boolean
    history: { step: string; decision: string; by: string | null; at: string | null; comment: string | null; round: number }[]
  }
}>()

// Searchable, and labelled with what somebody actually knows about the person — a uuid is a database
// identifier and was never a thing to ask a payroll clerk to type. It stays the value underneath.
const employeeItems = computed(() => props.employees.map(e => ({
  value: e.uuid,
  title: [e.name, e.number].filter(Boolean).join(' · '),
  subtitle: [e.ident, e.status !== 'active' ? e.status : null].filter(Boolean).join(' · '),
})))
const rand = (n: number) => 'R ' + (Number(n) || 0).toLocaleString('en-ZA', { minimumFractionDigits: 2 })

// The pre-approval report. Expanded by itself when something would produce a wrong payment, because
// a panel somebody has to think to open is a panel nobody opens.
const showReport = ref<number[]>(props.validation?.blockers ? [0] : [])
const findingColour = (f: Finding) => (f.severity === 'blocker' ? 'error' : 'warning')
const inputForm = useForm({ employee_uuid: '', kind: 'bonus', description: '', amount: 0 })
function addInput() { inputForm.post(route('payroll.bureau.runs.inputs.add', [props.company.uuid, props.run.uuid]), { preserveScroll: true, onSuccess: () => inputForm.reset() }) }
function process() { router.post(route('payroll.bureau.runs.process', [props.company.uuid, props.run.uuid]), {}, { preserveScroll: true }) }
function finalise() { router.post(route('payroll.bureau.runs.finalise', [props.company.uuid, props.run.uuid]), {}, { preserveScroll: true }) }
const approveForm = useForm({ comment: '' })
function approve() {
  approveForm.post(route('payroll.bureau.runs.approve', [props.company.uuid, props.run.uuid]),
    { preserveScroll: true, onSuccess: () => approveForm.reset() })
}

// Sending it back needs a reason — it goes to whoever prepared the run.
const rejectDialog = ref(false)
const rejectForm = useForm({ reason: '' })
function reject() {
  rejectForm.post(route('payroll.bureau.runs.reject', [props.company.uuid, props.run.uuid]), {
    preserveScroll: true,
    onSuccess: () => { rejectDialog.value = false; rejectForm.reset() },
  })
}

// ── Overtime, captured as hours ────────────────────────────────────────────
// There is no attendance to read on the bureau side, so the officer enters hours and the engine
// prices them against each employee's own rate. One person or many — "these six worked four hours
// on Saturday" is how the instruction usually arrives.
const overtimeDialog = ref(false)
const overtimeForm = useForm({ kind: 'overtime', hours: 0, employees: [] as string[] })
function addOvertime() {
  overtimeForm.post(route('payroll.bureau.runs.overtime', [props.company.uuid, props.run.uuid]), {
    preserveScroll: true,
    onSuccess: () => { overtimeDialog.value = false; overtimeForm.reset() },
  })
}
const overtimeKinds = computed(() => [
  { title: l('ot.overtime'), value: 'overtime' },
  { title: l('ot.sunday'), value: 'sunday_time' },
  { title: l('ot.public_holiday'), value: 'public_holiday_time' },
])

// ── Who is on this run ─────────────────────────────────────────────────────
const rosterDialog = ref(false)
const rosterForm = useForm({ employees: [] as string[] })
const onRun = computed(() => new Set(props.roster.map(r => r.uuid)))
const addable = computed(() => employeeItems.value.filter(e => !onRun.value.has(e.value)))
function addEmployees() {
  rosterForm.post(route('payroll.bureau.runs.employees.add', [props.company.uuid, props.run.uuid]), {
    preserveScroll: true,
    onSuccess: () => { rosterDialog.value = false; rosterForm.reset() },
  })
}
function removeEmployee(uuid: string) {
  router.delete(route('payroll.bureau.runs.employees.remove', [props.company.uuid, props.run.uuid, uuid]),
    { preserveScroll: true })
}
function markPaid() { router.post(route('payroll.bureau.runs.paid', [props.company.uuid, props.run.uuid]), {}, { preserveScroll: true }) }
function complete() { router.post(route('payroll.bureau.runs.complete', [props.company.uuid, props.run.uuid]), {}, { preserveScroll: true }) }

// Reopening a signed-off run. Deliberately behind a dialog that will not submit without a reason —
// the reason is the whole control, and it stays on the run afterwards.
const reopenDialog = ref(false)
const reopenForm = useForm({ reason: '' })
function reopen() {
  reopenForm.post(route('payroll.bureau.runs.reopen', [props.company.uuid, props.run.uuid]), {
    preserveScroll: true,
    onSuccess: () => { reopenDialog.value = false; reopenForm.reset() },
  })
}

// Where the run is, and where it can go. Derived from the status rather than repeated per button,
// so a state added to the lifecycle does not need every `v-if` on this page revisited.
const at = (name: string) => props.run.status === name
const locked = computed(() => ['approved', 'finalised', 'paid', 'completed'].includes(props.run.status))
const stageColour = computed(() => ({
  draft: 'grey', review: 'warning', approved: 'info',
  finalised: 'success', paid: 'success', completed: 'success',
}[props.run.status as string] ?? 'grey'))
function email() { router.post(route('payroll.bureau.runs.email', [props.company.uuid, props.run.uuid]), {}, { preserveScroll: true }) }
function download(p: any) { window.open(route('payroll.bureau.runs.payslip.download', [props.company.uuid, props.run.uuid, p.uuid]), '_blank') }
function genBankFile() { router.post(route('payroll.bureau.runs.bankfile.generate', [props.company.uuid, props.run.uuid]), {}, { preserveScroll: true }) }
function downloadBankFile() { window.open(route('payroll.bureau.runs.bankfile.download', [props.company.uuid, props.run.uuid]), '_blank') }
function emp201() { window.open(route('payroll.bureau.runs.emp201', [props.company.uuid, props.run.uuid]), '_blank') }
const filing = ref(false)
function fileEmp201() { router.post(route('payroll.bureau.runs.emp201.file', [props.company.uuid, props.run.uuid]), {}, { preserveScroll: true, onStart: () => (filing.value = true), onFinish: () => (filing.value = false) }) }
function glJournal() { window.open(route('payroll.bureau.runs.gl', [props.company.uuid, props.run.uuid]), '_blank') }
function disburse() { router.post(route('payroll.bureau.payouts.disburse', [props.company.uuid, props.run.uuid]), {}, { preserveScroll: true }) }
</script>

<template>
  <div class="pa-4" style="max-width:1000px;margin:auto">
    <div class="d-flex align-center gap-3 mb-4">
      <VBtn icon="tabler-arrow-left" aria-label="Back to the pay runs" class="row-action" variant="text" @click="router.get(route('payroll.bureau.runs.index', company.uuid))" />
      <div class="flex-grow-1">
        <h4 class="text-h4 mb-0">{{ run.name }}</h4>
        <p class="text-caption text-medium-emphasis mb-0">{{ run.period_start }} → {{ run.period_end }} · tax year {{ run.tax_year }}</p>
      </div>
      <VChip :color="stageColour" variant="tonal">{{ l('status.' + run.status) }}</VChip>
    </div>

    <!-- Where the run is. Shown as the sequence rather than one word, because "finalised" says
         nothing about whether anybody has been paid — which is the question being asked. -->
    <div class="d-flex align-center flex-wrap ga-1 mb-4">
      <template v-for="(name, i) in (run.stages ?? [])" :key="name">
        <VChip
          size="small"
          :variant="i <= run.stage ? 'flat' : 'outlined'"
          :color="i <= run.stage ? (i === run.stage ? stageColour : 'success') : undefined"
        >
          {{ l('status.' + name) }}
        </VChip>
        <VIcon v-if="i < (run.stages.length - 1)" size="14" class="text-medium-emphasis">tabler-chevron-right</VIcon>
      </template>
    </div>

    <VAlert v-if="run.approved_by" type="success" variant="tonal" density="compact" class="mb-4">
      {{ l('approved_by', { who: run.approved_by, when: run.approved_at }) }}
    </VAlert>

    <VAlert v-if="run.reopen_reason" type="warning" variant="tonal" density="compact" class="mb-4">
      {{ l('reopened', { when: run.reopened_at, reason: run.reopen_reason }) }}
    </VAlert>

    <div class="d-flex gap-2 mb-4 flex-wrap">
      <VBtn v-if="!locked" color="primary" prepend-icon="tabler-calculator" @click="process">{{ k('generate_calc') }}</VBtn>
      <VBtn v-if="at('review')" color="success" prepend-icon="tabler-writing-sign" @click="approve">{{ l('approve') }}</VBtn>
      <VBtn v-if="at('approved')" color="success" prepend-icon="tabler-lock" @click="finalise">{{ k('finalise_lock') }}</VBtn>
      <VBtn v-if="at('finalised')" variant="tonal" prepend-icon="tabler-mail" @click="email">{{ k('email_payslips') }}</VBtn>
      <VBtn v-if="at('finalised')" color="primary" variant="tonal" prepend-icon="tabler-building-bank" @click="genBankFile">{{ k('generate_bank') }}</VBtn>
      <VBtn v-if="at('finalised')" color="success" prepend-icon="tabler-bolt" @click="disburse">{{ k('disburse') }}</VBtn>
      <VBtn v-if="at('finalised')" variant="text" prepend-icon="tabler-download" @click="downloadBankFile">{{ k('download_bank') }}</VBtn>
      <!-- Explicit, because most bureaus still upload the bank file themselves and a download is
           somebody looking at it, not evidence that anybody was paid. -->
      <VBtn v-if="at('finalised')" variant="tonal" prepend-icon="tabler-cash-banknote" @click="markPaid">{{ l('mark_paid') }}</VBtn>
      <VBtn v-if="at('paid')" color="primary" variant="tonal" prepend-icon="tabler-checkbox" @click="complete">{{ l('complete') }}</VBtn>
      <VBtn v-if="['finalised','paid','completed'].includes(run.status)" variant="text" prepend-icon="tabler-file-spreadsheet" @click="emp201">{{ k('emp201_csv') }}</VBtn>
      <VBtn v-if="['finalised','paid','completed'].includes(run.status)" color="primary" variant="text" prepend-icon="tabler-file-certificate" :loading="filing" @click="fileEmp201">{{ k('file_emp201') }}</VBtn>
      <VBtn v-if="!at('draft')" variant="text" prepend-icon="tabler-book" @click="glJournal">{{ k('gl_journal') }}</VBtn>
      <VSpacer />
      <VBtn v-if="locked" variant="text" color="warning" prepend-icon="tabler-lock-open" @click="reopenDialog = true">{{ l('reopen') }}</VBtn>
    </div>

    <VDialog v-model="reopenDialog" max-width="520">
      <VCard>
        <VCardTitle>{{ l('reopen_title') }}</VCardTitle>
        <VCardText>
          <p class="text-body-2 text-medium-emphasis">{{ l('reopen_help') }}</p>
          <VTextarea
            v-model="reopenForm.reason" :label="l('reopen_reason')" rows="3" counter="255"
            :error-messages="reopenForm.errors.reason"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="reopenDialog = false">{{ u('cancel') }}</VBtn>
          <VBtn color="warning" :loading="reopenForm.processing" @click="reopen">{{ l('reopen_confirm') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VRow class="mb-2">
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ u('gross') }}</div><div class="text-h6">{{ rand(run.total_gross) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ u('paye') }}</div><div class="text-h6">{{ rand(run.total_paye) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ u('net') }}</div><div class="text-h6">{{ rand(run.total_net) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border color="primary" variant="tonal"><VCardText><div class="text-caption">{{ k('emp201_payable') }}</div><div class="text-h6">{{ rand(emp201.total_payable) }}</div></VCardText></VCard></VCol>
    </VRow>

    <VCard v-if="run.status !== 'finalised'" border class="mb-4">
      <VCardTitle class="text-subtitle-1">Add once-off input (bonus / retro / leave pay-out / severance / garnishee)</VCardTitle>
      <VCardText class="d-flex flex-wrap gap-2 align-end">
        <VSelect v-model="inputForm.kind" :items="['bonus','retro','leave_payout','severance','garnishee','unpaid_leave','other_earning','other_deduction']" :label="u('type')" density="compact" hide-details style="max-width:180px" />
        <VAutocomplete
          v-model="inputForm.employee_uuid"
          :items="employeeItems"
          :label="u('employee')"
          density="compact"
          hide-details
          style="min-width:280px"
          :error-messages="inputForm.errors.employee_uuid"
        >
          <template #item="{ props: itemProps, item }">
            <VListItem v-bind="itemProps" :subtitle="item.raw.subtitle" />
          </template>
        </VAutocomplete>
        <VTextField v-model="inputForm.description" :label="k('description')" density="compact" hide-details />
        <VTextField v-model.number="inputForm.amount" type="number" :label="inputForm.kind === 'unpaid_leave' ? 'Unpaid days' : 'Amount'" density="compact" hide-details style="max-width:150px" :error-messages="inputForm.errors.amount || inputForm.errors.days" />
        <VBtn color="primary" size="small" :loading="inputForm.processing" @click="addInput">{{ u('add') }}</VBtn>
      </VCardText>
      <VCardText class="text-caption text-medium-emphasis pt-0">After adding inputs, re-run "Generate &amp; calculate". Bonuses are taxed at the marginal rate; garnishees are post-tax.</VCardText>
    </VCard>

    <VExpansionPanels v-if="validation" v-model="showReport" class="mb-4">
      <VExpansionPanel>
        <VExpansionPanelTitle>
          <div class="d-flex align-center ga-2 flex-wrap">
            <VIcon :color="validation.blockers ? 'error' : validation.warnings ? 'warning' : 'success'">
              {{ validation.blockers ? 'tabler-alert-octagon' : validation.warnings ? 'tabler-alert-triangle' : 'tabler-circle-check' }}
            </VIcon>
            <span class="text-subtitle-1">{{ v('title') }}</span>
            <VChip v-if="validation.blockers" size="small" color="error" variant="tonal">{{ v('blockers', { count: validation.blockers }) }}</VChip>
            <VChip v-if="validation.warnings" size="small" color="warning" variant="tonal">{{ v('warnings', { count: validation.warnings }) }}</VChip>
            <VChip v-if="!validation.blockers && !validation.warnings" size="small" color="success" variant="tonal">{{ v('clean') }}</VChip>
            <span class="text-caption text-medium-emphasis">{{ v('checked', { count: validation.checked }) }}</span>
          </div>
        </VExpansionPanelTitle>
        <VExpansionPanelText>
          <VAlert v-if="validation.blockers" type="error" variant="tonal" density="compact" class="mb-3">
            {{ v('blocked_notice') }}
          </VAlert>
          <VList density="compact">
            <VListItem v-for="(f, i) in validation.findings" :key="i">
              <template #prepend>
                <VIcon :color="findingColour(f)" size="18" class="me-2">
                  {{ f.severity === 'blocker' ? 'tabler-alert-octagon' : 'tabler-alert-triangle' }}
                </VIcon>
              </template>
              <VListItemTitle class="text-body-2">{{ f.message }}</VListItemTitle>
              <VListItemSubtitle v-if="f.employee_number" class="text-caption">{{ f.employee_number }}</VListItemSubtitle>
            </VListItem>
            <VListItem v-if="!validation.findings.length">
              <VListItemTitle class="text-body-2 text-medium-emphasis">{{ v('nothing_found') }}</VListItemTitle>
            </VListItem>
          </VList>
        </VExpansionPanelText>
      </VExpansionPanel>
    </VExpansionPanels>

    <!-- Where the run stands in its approval chain, and whose signature it waits on. -->
    <VCard v-if="approval.steps.length" border class="mb-4">
      <VCardTitle class="text-subtitle-1 d-flex align-center justify-space-between flex-wrap ga-2">
        <span>{{ l('chain') }}</span>
        <span v-if="approval.round > 1" class="text-caption text-medium-emphasis">
          {{ l('round', { round: approval.round }) }}
        </span>
      </VCardTitle>
      <VCardText>
        <div class="d-flex align-center flex-wrap ga-1 mb-3">
          <template v-for="(s, i) in approval.steps" :key="s.sequence">
            <VChip
              size="small"
              :color="s.done ? 'success' : s.current ? 'warning' : undefined"
              :variant="s.done || s.current ? 'flat' : 'outlined'"
            >
              {{ s.name }} · {{ s.approver }}
            </VChip>
            <VIcon v-if="i < approval.steps.length - 1" size="14" class="text-medium-emphasis">tabler-chevron-right</VIcon>
          </template>
        </div>

        <div v-if="at('review') && approval.current_step" class="d-flex align-center ga-2 flex-wrap">
          <VTextField
            v-model="approveForm.comment" :label="l('comment')" density="compact" hide-details
            style="min-width:240px;flex:1 1 16rem"
          />
          <VBtn v-if="approval.can_sign" color="success" prepend-icon="tabler-writing-sign" @click="approve">
            {{ l('sign_step') }}
          </VBtn>
          <VBtn v-if="approval.can_sign" variant="tonal" color="error" prepend-icon="tabler-arrow-back-up" @click="rejectDialog = true">
            {{ l('send_back') }}
          </VBtn>
          <span v-else class="text-caption text-medium-emphasis">{{ l('not_your_step') }}</span>
        </div>

        <VDivider v-if="approval.history.length" class="my-3" />
        <div v-for="(h, i) in approval.history" :key="i" class="text-caption mb-1">
          <VIcon size="14" :color="h.decision === 'approved' ? 'success' : 'error'" class="me-1">
            {{ h.decision === 'approved' ? 'tabler-check' : 'tabler-x' }}
          </VIcon>
          <strong>{{ h.step }}</strong> — {{ h.by }} · {{ h.at }}
          <span v-if="h.comment" class="text-medium-emphasis">— {{ h.comment }}</span>
        </div>
      </VCardText>
    </VCard>

    <!-- Who this run pays. A regular run starts with everybody on its cycle; every other kind
         starts empty so the officer says who it is for. -->
    <VCard border class="mb-4">
      <VCardTitle class="text-subtitle-1 d-flex align-center justify-space-between flex-wrap ga-2">
        <span>{{ l('on_this_run', { count: roster.length }) }}</span>
        <div class="d-flex ga-2 flex-wrap">
          <VBtn v-if="!locked" size="small" variant="tonal" prepend-icon="tabler-clock-hour-9" @click="overtimeDialog = true">
            {{ l('ot.capture') }}
          </VBtn>
          <VBtn v-if="!locked" size="small" variant="tonal" prepend-icon="tabler-user-plus" @click="rosterDialog = true">
            {{ l('add_employees') }}
          </VBtn>
        </div>
      </VCardTitle>
      <VCardText v-if="!roster.length" class="text-medium-emphasis text-body-2">
        {{ l('roster_empty') }}
      </VCardText>
      <VTable v-else density="compact">
        <thead>
          <tr><th>{{ u('employee') }}</th><th>{{ l('added') }}</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="r in roster" :key="r.uuid">
            <td>
              {{ r.name }}
              <span class="text-caption text-medium-emphasis">{{ r.number }}</span>
              <VChip v-if="r.status !== 'active'" size="x-small" color="warning" variant="tonal" class="ms-1">
                {{ r.status }}
              </VChip>
            </td>
            <td><VChip size="x-small" variant="tonal">{{ l('via.' + r.via) }}</VChip></td>
            <td class="text-end">
              <VBtn
                v-if="!locked" icon size="small" variant="text" color="error" class="row-action"
                :aria-label="`Remove ${r.name} from this run`" @click="removeEmployee(r.uuid)"
              ><VIcon>tabler-user-minus</VIcon></VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="rosterDialog" max-width="620">
      <VCard>
        <VCardTitle>{{ l('add_employees') }}</VCardTitle>
        <VCardText>
          <p class="text-body-2 text-medium-emphasis">{{ l('add_help') }}</p>
          <VAutocomplete
            v-model="rosterForm.employees" :items="addable" multiple chips closable-chips
            :label="u('employee')" :error-messages="rosterForm.errors.employees"
          >
            <template #item="{ props: ip, item }">
              <VListItem v-bind="ip" :subtitle="item.raw.subtitle" />
            </template>
          </VAutocomplete>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="rosterDialog = false">{{ u('cancel') }}</VBtn>
          <VBtn color="primary" :loading="rosterForm.processing" @click="addEmployees">{{ u('add') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="overtimeDialog" max-width="640">
      <VCard>
        <VCardTitle>{{ l('ot.capture') }}</VCardTitle>
        <VCardText class="d-flex flex-column ga-3">
          <p class="text-body-2 text-medium-emphasis mb-0">{{ l('ot.help') }}</p>
          <div class="field-row">
            <VSelect v-model="overtimeForm.kind" :items="overtimeKinds" :label="l('ot.kind')" />
            <VTextField
              v-model.number="overtimeForm.hours" type="number" step="0.25" min="0"
              :label="l('ot.hours')" :error-messages="overtimeForm.errors.hours"
            />
          </div>
          <VAutocomplete
            v-model="overtimeForm.employees" :items="employeeItems" multiple chips closable-chips
            :label="l('ot.who')" :hint="l('ot.who_hint')" persistent-hint
            :error-messages="overtimeForm.errors.employees"
          >
            <template #item="{ props: ip, item }">
              <VListItem v-bind="ip" :subtitle="item.raw.subtitle" />
            </template>
          </VAutocomplete>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="overtimeDialog = false">{{ u('cancel') }}</VBtn>
          <VBtn color="primary" :loading="overtimeForm.processing" @click="addOvertime">{{ u('add') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="rejectDialog" max-width="520">
      <VCard>
        <VCardTitle>{{ l('send_back') }}</VCardTitle>
        <VCardText>
          <p class="text-body-2 text-medium-emphasis">{{ l('send_back_help') }}</p>
          <VTextarea
            v-model="rejectForm.reason" :label="l('reason')" rows="3"
            :error-messages="rejectForm.errors.reason"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="rejectDialog = false">{{ u('cancel') }}</VBtn>
          <VBtn color="error" :loading="rejectForm.processing" @click="reject">{{ l('send_back') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VCard border>
      <VCardTitle class="text-subtitle-1">{{ k('payslips') }}</VCardTitle>
      <VTable>
        <thead><tr><th>{{ u('employee') }}</th><th class="text-end">{{ u('gross') }}</th><th class="text-end">{{ u('paye') }}</th><th class="text-end">{{ k('uif') }}</th><th class="text-end">{{ u('net') }}</th><th></th></tr></thead>
        <tbody>
          <tr v-for="p in payslips" :key="p.uuid">
            <td>{{ p.name }}</td><td class="text-end">{{ rand(p.gross) }}</td><td class="text-end">{{ rand(p.paye) }}</td>
            <td class="text-end">{{ rand(p.uif) }}</td><td class="text-end">{{ rand(p.net) }}</td>
            <td class="text-end"><VBtn v-if="p.locked" size="x-small" variant="text" prepend-icon="tabler-download" @click="download(p)">{{ k('pdf') }}</VBtn></td>
          </tr>
          <tr v-if="!payslips.length"><td colspan="6" class="text-center text-medium-emphasis py-6">{{ k('generate_first') }}</td></tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>

<style scoped>
/* Fields sharing a row keep an even split whatever is written beneath them. */
.field-row { display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: flex-start; }
.field-row > * { flex: 1 1 12rem; min-width: 0; }
</style>
