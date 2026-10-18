<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Payslip {
  id: number; employee_id: number; employee_name: string; employee_title: string
  basic_salary: number; gross_pay: number; total_deductions: number; net_pay: number
  paye: number; uif: number; pension: number; status: string
}

interface Employee {
  id: number; name: string; job_title: string | null; basic_salary: number | null
}

interface AuditLog {
  id: number; action: string; description: string; meta: Record<string, any> | null
  user_id: number | null; created_at: string
}

const props = defineProps<{
  run: any
  payslips: Payslip[]
  anomalies: any[]
  approval: any | null
  changeLogs: any[]
  auditLogs: AuditLog[]
  availableEmployees: Employee[]
  employerCosts?: { gross: number; employer_uif: number; sdl: number; employer_pension: number; employer_medical: number; total_employer_cost: number }
  leaveActive?: boolean
  validation?: {
    blockers: number; warnings: number; checked: number
    findings: { severity: 'blocker' | 'warning'; category: string; code: string; message: string; employee: string | null; employee_number: string | null }[]
  } | null
}>()

// The pre-release report. Expanded by itself when something would produce a wrong payment, because
// a panel somebody has to think to open is a panel nobody opens.
const showReport = ref<number[]>(props.validation?.blockers ? [0] : [])

// Full status system: colour + icon per stage of the payroll lifecycle.
const STATUS_META: Record<string, { color: string; icon: string }> = {
  draft:     { color: 'grey',        icon: 'tabler-pencil' },
  processing:{ color: 'info',        icon: 'tabler-calculator' },
  review:    { color: 'orange',      icon: 'tabler-clock-hour-4' },
  approved:  { color: 'success',     icon: 'tabler-circle-check' },
  released:  { color: 'success',     icon: 'tabler-cash' },
  cancelled: { color: 'error',       icon: 'tabler-ban' },
}
const statusMeta = (s: string) => STATUS_META[s] ?? { color: 'grey', icon: 'tabler-circle' }

// "July 2026" period + pay date (last day of the period) for the header.
const periodLabel = computed(() => {
  const d = props.run.period_start ? new Date(props.run.period_start) : null
  return d ? d.toLocaleDateString('en-ZA', { month: 'long', year: 'numeric' }) : '—'
})
const payDate = computed(() => {
  const d = props.run.period_end ? new Date(props.run.period_end) : null
  return d ? d.toLocaleDateString('en-ZA', { day: 'numeric', month: 'short', year: 'numeric' }) : '—'
})

// Per-employee deduction breakdown from stored fields (PAYE / UIF / Pension / Other).
function otherDeductions(p: Payslip) {
  return Math.max(0, (p.total_deductions || 0) - (p.paye || 0) - (p.uif || 0) - (p.pension || 0))
}

const activeTab      = ref('employees')
const addDialog      = ref(false)
const selectedEmpIds = ref<number[]>([])

const anomalyCheckForm    = useForm({})
const lockForm            = useForm({})
const calculateForm       = useForm({})
const overtimeForm        = useForm({})
const refreshSalariesForm = useForm({})
const removeEmployeeForm  = useForm({})
const syncLeaveForm       = useForm({})

const allSelected = computed(() =>
  props.availableEmployees.length > 0 && selectedEmpIds.value.length === props.availableEmployees.length)
function toggleSelectAll() {
  selectedEmpIds.value = allSelected.value ? [] : props.availableEmployees.map((e) => e.id)
}
const removeTarget = ref<{ id: number; name: string } | null>(null)
function askRemove(item: { id: number; employee_name: string }) {
  removeTarget.value = { id: item.id, name: item.employee_name }
}
function confirmRemove() {
  if (!removeTarget.value) return
  removeEmployeeForm.delete(route('payroll.runs.remove-employee', { run: props.run.uuid, payslip: removeTarget.value.id }), {
    preserveScroll: true,
    onFinish: () => { removeTarget.value = null },
  })
}

// Add an earnings / deduction line to an employee's payslip (recalculates via the tax engine).
const lineTarget = ref<{ id: number; name: string } | null>(null)
const lineForm = useForm({ line_type: 'allowance', description: '', amount: null as number | null })
const lineTypeOptions = computed(() => [
  { title: t('payroll_runs_show.line_dialog.types.allowance'), value: 'allowance' },
  { title: t('payroll_runs_show.line_dialog.types.travel_allowance'), value: 'travel_allowance' },
  { title: t('payroll_runs_show.line_dialog.types.overtime'), value: 'overtime' },
  { title: t('payroll_runs_show.line_dialog.types.bonus'), value: 'bonus' },
  { title: t('payroll_runs_show.line_dialog.types.commission'), value: 'commission' },
  { title: t('payroll_runs_show.line_dialog.types.pension'), value: 'pension' },
  { title: t('payroll_runs_show.line_dialog.types.medical_aid'), value: 'medical_aid' },
  { title: t('payroll_runs_show.line_dialog.types.other_deduction'), value: 'other_deduction' },
  { title: t('payroll_runs_show.line_dialog.types.employer_medical'), value: 'employer_medical' },
  { title: t('payroll_runs_show.line_dialog.types.employer_pension'), value: 'employer_pension' },
])

// Bulk: apply one line to EVERY employee on the run (for large payrolls).
const bulkDialog = ref(false)
const bulkForm = useForm({ line_type: 'allowance', description: '', amount: null as number | null })
function openBulk() { bulkForm.reset(); bulkForm.clearErrors(); bulkForm.line_type = 'allowance'; bulkDialog.value = true }
function submitBulk() {
  bulkForm.post(route('payroll.runs.bulk-line', props.run.uuid), { preserveScroll: true, onSuccess: () => { bulkDialog.value = false } })
}
function openAddLine(item: { id: number; employee_name: string }) {
  lineTarget.value = { id: item.id, name: item.employee_name }
  lineForm.reset(); lineForm.clearErrors(); lineForm.line_type = 'allowance'
}
function submitLine() {
  if (!lineTarget.value) return
  lineForm.post(route('payroll.payslips.lines.store', { run: props.run.id, payslip: lineTarget.value.id }), {
    preserveScroll: true,
    onSuccess: () => { lineTarget.value = null },
  })
}
const submitApprovalForm  = useForm({})
const releaseForm         = useForm({})
const cancelForm          = useForm({})
const addEmployeesForm    = useForm({ employee_ids: [] as number[] })

function openAddDialog() {
  selectedEmpIds.value = []
  addDialog.value = true
}

function confirmAddEmployees() {
  addEmployeesForm.employee_ids = selectedEmpIds.value
  addEmployeesForm.post(route('payroll.runs.add-employees', props.run.uuid), {
    onSuccess: () => { addDialog.value = false },
  })
}

const statusColor = (s: string) => ({
  draft: 'grey', processing: 'info', review: 'warning',
  released: 'success', cancelled: 'error',
}[s] ?? 'grey')

const sevColor   = (s: string) => ({ low: 'info', medium: 'warning', high: 'error', critical: 'error' }[s] ?? 'grey')
const fmt        = (n: number | null) => n !== null ? 'R' + Number(n).toLocaleString('en-ZA', { minimumFractionDigits: 2 }) : '—'
const actionIcon = (a: string) => ({
  'run.created': 'tabler-circle-plus',
  'run.employees_added': 'tabler-user-plus',
  'run.calculated': 'tabler-calculator',
  'run.locked': 'tabler-lock',
  'run.submitted': 'tabler-send',
  'run.released': 'tabler-circle-check',
  'run.cancelled': 'tabler-ban',
}[a] ?? 'tabler-history')

const isDraft    = () => props.run.status === 'draft'
const isReview   = () => props.run.status === 'review'
const isReleased = () => props.run.status === 'released'
const canEdit    = () => !isReleased() && props.run.status !== 'cancelled'
</script>

<template>
  <div class="pa-4">
    <!-- Loading overlay -->
    <VOverlay
      v-if="anomalyCheckForm.processing || lockForm.processing || calculateForm.processing || submitApprovalForm.processing || releaseForm.processing || cancelForm.processing || addEmployeesForm.processing"
      contained class="align-center justify-center" style="position: fixed; z-index: 9999;"
    >
      <VProgressCircular indeterminate color="primary" size="64" />
    </VOverlay>

    <!--
      What is wrong with this run, before anybody releases it. Releasing locks the payslips, emails
      them, posts the journal and charges the budget — a blocker found after that is found by the
      employee.
    -->
    <VExpansionPanels v-if="validation && validation.findings.length" v-model="showReport" class="mb-4">
      <VExpansionPanel>
        <VExpansionPanelTitle>
          <div class="d-flex align-center ga-2 flex-wrap">
            <VChip v-if="validation.blockers" size="small" color="error" variant="tonal">
              {{ t('payroll_runs_show.validation.blockers', { count: validation.blockers }) }}
            </VChip>
            <VChip v-if="validation.warnings" size="small" color="warning" variant="tonal">
              {{ t('payroll_runs_show.validation.warnings', { count: validation.warnings }) }}
            </VChip>
            <span class="text-caption text-medium-emphasis">
              {{ t('payroll_runs_show.validation.checked', { count: validation.checked }) }}
            </span>
          </div>
        </VExpansionPanelTitle>
        <VExpansionPanelText>
          <VList density="compact">
            <VListItem v-for="(f, i) in validation.findings" :key="i">
              <template #prepend>
                <VIcon
                  :icon="f.severity === 'blocker' ? 'tabler-alert-octagon' : 'tabler-alert-triangle'"
                  :color="f.severity === 'blocker' ? 'error' : 'warning'"
                  size="small"
                />
              </template>
              <VListItemTitle class="text-body-2">{{ f.message }}</VListItemTitle>
              <VListItemSubtitle v-if="f.employee" class="text-caption">
                {{ [f.employee, f.employee_number].filter(Boolean).join(' · ') }}
              </VListItemSubtitle>
            </VListItem>
          </VList>
          <div v-if="validation.blockers" class="text-caption text-medium-emphasis mt-2">
            {{ t('payroll_runs_show.validation.blocked_note') }}
          </div>
        </VExpansionPanelText>
      </VExpansionPanel>
    </VExpansionPanels>

    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <div class="d-flex align-center ga-2 mb-1">
          <VChip size="small" variant="outlined" color="grey">{{ run.ref_number }}</VChip>
          <VChip size="small" :color="statusMeta(run.status).color" variant="tonal" :prepend-icon="statusMeta(run.status).icon">{{ run.status }}</VChip>
          <VChip v-if="run.ai_risk_level" size="small" :color="sevColor(run.ai_risk_level)" variant="tonal">
            {{ t('payroll_runs_show.risk_label', { level: run.ai_risk_level }) }}
          </VChip>
        </div>
        <h1 class="text-h5 font-weight-bold">{{ run.name }}</h1>
        <div class="d-flex flex-wrap ga-4 mt-1">
          <span class="text-body-2"><span class="text-medium-emphasis">{{ t('payroll_runs_show.header.period') }}:</span> <strong>{{ periodLabel }}</strong></span>
          <span class="text-body-2"><span class="text-medium-emphasis">{{ t('payroll_runs_show.header.pay_date') }}:</span> <strong>{{ payDate }}</strong></span>
          <span class="text-body-2"><span class="text-medium-emphasis">{{ t('payroll_runs_show.header.status') }}:</span> <strong class="text-capitalize">{{ run.status }}</strong></span>
        </div>
      </div>

      <div class="d-flex ga-2 flex-wrap">
        <VBtn
          v-if="canEdit()"
          size="small" variant="outlined" prepend-icon="tabler-shield-search"
          :loading="anomalyCheckForm.processing"
          @click="anomalyCheckForm.post(route('payroll.runs.anomaly-check', run.uuid))"
        >{{ t('payroll_runs_show.actions.anomaly_check') }}</VBtn>

        <VBtn
          v-if="canEdit() && payslips.length > 0"
          size="small" color="info" variant="tonal" prepend-icon="tabler-calculator"
          :loading="calculateForm.processing"
          @click="calculateForm.post(route('payroll.runs.calculate', run.uuid))"
        >{{ t('payroll_runs_show.actions.calculate') }}</VBtn>

        <!-- Overtime at the BCEA multipliers, worked out from attendance. Separate from
             calculating because it changes what people are paid; safe to press twice. -->
        <VBtn
          v-if="canEdit() && payslips.length > 0"
          size="small" variant="outlined" prepend-icon="tabler-clock-hour-9"
          :loading="overtimeForm.processing"
          @click="overtimeForm.post(route('payroll.runs.apply-overtime', run.uuid))"
        >{{ t('payroll_runs_show.actions.apply_overtime') }}</VBtn>

        <VBtn
          v-if="canEdit() && payslips.length > 0"
          size="small" variant="outlined" prepend-icon="tabler-refresh"
          :loading="refreshSalariesForm.processing"
          @click="refreshSalariesForm.post(route('payroll.runs.refresh-salaries', run.uuid))"
        >{{ t('payroll_runs_show.actions.refresh_salaries') }}</VBtn>

        <VBtn
          v-if="leaveActive && canEdit() && payslips.length > 0"
          size="small" variant="outlined" prepend-icon="tabler-calendar-off"
          :loading="syncLeaveForm.processing"
          @click="syncLeaveForm.post(route('payroll.runs.sync-leave', run.uuid))"
        >{{ t('payroll_runs_show.actions.sync_leave') }}</VBtn>

        <VBtn
          v-if="isDraft() || isReview()"
          size="small" color="warning" variant="tonal" prepend-icon="tabler-lock"
          :loading="lockForm.processing"
          @click="lockForm.post(route('payroll.runs.lock', run.uuid))"
        >{{ t('payroll_runs_show.actions.lock') }}</VBtn>

        <VBtn
          v-if="isReview()"
          size="small" color="primary" prepend-icon="tabler-send"
          :loading="submitApprovalForm.processing"
          @click="submitApprovalForm.post(route('payroll.runs.submit', run.uuid))"
        >{{ t('payroll_runs_show.actions.submit_approval') }}</VBtn>

        <VBtn
          v-if="isReview()"
          size="small" color="success" prepend-icon="tabler-circle-check"
          :loading="releaseForm.processing"
          @click="releaseForm.post(route('payroll.runs.release', run.uuid))"
        >{{ t('payroll_runs_show.actions.release') }}</VBtn>

        <VBtn
          v-if="canEdit()"
          size="small" color="error" variant="text" prepend-icon="tabler-ban"
          :loading="cancelForm.processing"
          @click="cancelForm.post(route('payroll.runs.cancel', run.uuid))"
        >{{ t('payroll_runs_show.actions.cancel') }}</VBtn>
      </div>
    </div>

    <!-- Approval banner: payroll can't be paid until approved -->
    <VAlert v-if="isDraft() || isReview()" :type="isReview() ? 'info' : 'warning'" variant="tonal" class="mb-4" border="start" prominent>
      <div class="d-flex align-center ga-2">
        <VIcon>{{ isReview() ? 'tabler-clock-hour-4' : 'tabler-alert-triangle' }}</VIcon>
        <span>{{ isReview() ? t('payroll_runs_show.banner.pending_approval') : t('payroll_runs_show.banner.not_approved') }}</span>
      </div>
    </VAlert>

    <VRow class="mb-4">
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <div class="text-caption text-medium-emphasis mb-1">{{ t('payroll_runs_show.summary.total_gross') }}</div>
            <div class="text-h6 font-weight-bold text-primary">{{ fmt(run.total_gross) }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <div class="text-caption text-medium-emphasis mb-1">{{ t('payroll_runs_show.summary.total_deductions') }}</div>
            <div class="text-h6 font-weight-bold text-error">{{ fmt(run.total_deductions) }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <div class="text-caption text-medium-emphasis mb-1">{{ t('payroll_runs_show.summary.total_net_pay') }}</div>
            <div class="text-h6 font-weight-bold text-success">{{ fmt(run.total_net) }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <div class="text-caption text-medium-emphasis mb-1">{{ t('payroll_runs_show.summary.employees') }}</div>
            <div class="text-h6 font-weight-bold">{{ run.employee_count ?? 0 }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Employer cost: the TRUE cost of payroll (gross + employer statutory contributions) -->
    <VCard v-if="employerCosts" elevation="0" border color="primary" variant="tonal" class="mb-4">
      <VCardText class="pa-4">
        <div class="d-flex align-center justify-space-between flex-wrap ga-3">
          <div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_runs_show.employer.total_cost') }}</div>
            <div class="text-h5 font-weight-bold text-primary">{{ fmt(employerCosts.total_employer_cost) }}</div>
          </div>
          <div class="d-flex flex-wrap ga-6">
            <div><div class="text-caption text-medium-emphasis">{{ t('payroll_runs_show.employer.gross') }}</div><div class="font-weight-medium">{{ fmt(employerCosts.gross) }}</div></div>
            <div><div class="text-caption text-medium-emphasis">{{ t('payroll_runs_show.employer.employer_uif') }}</div><div class="font-weight-medium">{{ fmt(employerCosts.employer_uif) }}</div></div>
            <div><div class="text-caption text-medium-emphasis">{{ t('payroll_runs_show.employer.sdl') }}</div><div class="font-weight-medium">{{ fmt(employerCosts.sdl) }}</div></div>
            <div v-if="employerCosts.employer_pension > 0"><div class="text-caption text-medium-emphasis">{{ t('payroll_runs_show.employer.employer_pension') }}</div><div class="font-weight-medium">{{ fmt(employerCosts.employer_pension) }}</div></div>
            <div v-if="employerCosts.employer_medical > 0"><div class="text-caption text-medium-emphasis">{{ t('payroll_runs_show.employer.employer_medical') }}</div><div class="font-weight-medium">{{ fmt(employerCosts.employer_medical) }}</div></div>
          </div>
        </div>
      </VCardText>
    </VCard>

    <VTabs v-model="activeTab" class="mb-4">
      <VTab value="employees">
        {{ t('payroll_runs_show.tabs.employees') }}
        <VChip v-if="payslips.length" size="x-small" class="ml-1">{{ payslips.length }}</VChip>
      </VTab>
      <VTab value="anomalies">
        {{ t('payroll_runs_show.tabs.anomalies') }}
        <VChip v-if="anomalies.length" size="x-small" color="error" class="ml-1">{{ anomalies.length }}</VChip>
      </VTab>
      <VTab value="approval">{{ t('payroll_runs_show.tabs.approval') }}</VTab>
      <VTab value="changelog">{{ t('payroll_runs_show.tabs.changelog') }}</VTab>
      <VTab value="audit">{{ t('payroll_runs_show.tabs.audit') }}</VTab>
    </VTabs>

    <VTabsWindow v-model="activeTab">
      <VTabsWindowItem value="employees">
        <VCard elevation="0" border>
          <div class="d-flex align-center justify-space-between pa-4 pb-2 flex-wrap ga-2">
            <span class="text-body-1 font-weight-semibold">{{ t('payroll_runs_show.employees_tab.payslips') }}</span>
            <div class="d-flex ga-2">
              <VBtn
                v-if="canEdit() && payslips.length > 0"
                size="small" variant="tonal" prepend-icon="tabler-stack-2"
                @click="openBulk"
              >{{ t('payroll_runs_show.bulk_dialog.button') }}</VBtn>
              <VBtn
                v-if="canEdit()"
                size="small" color="primary" variant="tonal" prepend-icon="tabler-user-plus"
                @click="openAddDialog"
              >{{ t('payroll_runs_show.employees_tab.add_employees') }}</VBtn>
            </div>
          </div>
          <VDivider />

          <template v-if="payslips.length">
            <VDataTable
              :items="payslips"
              :headers="[
                { title: t('payroll_runs_show.employees_tab.table.employee'),    key: 'employee_name' },
                { title: t('payroll_runs_show.employees_tab.table.title'),       key: 'employee_title' },
                { title: t('payroll_runs_show.employees_tab.table.basic'),       key: 'basic_salary',    align: 'end' },
                { title: t('payroll_runs_show.employees_tab.table.gross'),       key: 'gross_pay',       align: 'end' },
                { title: t('payroll_runs_show.employees_tab.table.paye'),        key: 'paye',            align: 'end' },
                { title: t('payroll_runs_show.employees_tab.table.uif'),         key: 'uif',             align: 'end' },
                { title: t('payroll_runs_show.employees_tab.table.deductions'),  key: 'total_deductions',align: 'end' },
                { title: t('payroll_runs_show.employees_tab.table.net_pay'),     key: 'net_pay',         align: 'end' },
                { title: t('payroll_runs_show.employees_tab.table.status'),      key: 'status' },
                { title: '', key: 'actions', align: 'end', sortable: false },
              ]"
              density="compact"
              hide-default-footer
            >
              <template #item.basic_salary="{ item }">{{ fmt(item.basic_salary) }}</template>
              <template #item.gross_pay="{ item }">{{ fmt(item.gross_pay) }}</template>
              <template #item.paye="{ item }">{{ fmt(item.paye) }}</template>
              <template #item.uif="{ item }">{{ fmt(item.uif) }}</template>
              <template #item.total_deductions="{ item }">
                <VMenu open-on-hover location="top">
                  <template #activator="{ props: menuProps }">
                    <span v-bind="menuProps" class="text-error" style="cursor: help; border-bottom: 1px dotted currentColor">{{ fmt(item.total_deductions) }}</span>
                  </template>
                  <VCard min-width="220"><VList density="compact">
                    <VListItem :title="t('payroll_runs_show.employees_tab.table.paye')" :subtitle="fmt(item.paye)" />
                    <VListItem :title="t('payroll_runs_show.employees_tab.table.uif')" :subtitle="fmt(item.uif)" />
                    <VListItem :title="t('payroll_runs_show.breakdown.pension')" :subtitle="fmt(item.pension)" />
                    <VListItem :title="t('payroll_runs_show.breakdown.other')" :subtitle="fmt(otherDeductions(item))" />
                    <VDivider />
                    <VListItem :title="t('payroll_runs_show.breakdown.total')" :subtitle="fmt(item.total_deductions)" class="font-weight-bold" />
                  </VList></VCard>
                </VMenu>
              </template>
              <template #item.net_pay="{ item }">
                <span class="font-weight-bold text-success">{{ fmt(item.net_pay) }}</span>
              </template>
              <template #item.status="{ item }">
                <VChip size="x-small" variant="tonal"
                  :color="item.status === 'finalised' ? 'success' : 'grey'"
                >{{ item.status }}</VChip>
              </template>
              <template #item.actions="{ item }">
                <VBtn
                  v-if="canEdit()"
                  icon="tabler-plus" size="small" variant="text" color="primary" class="row-action"
                  :title="t('payroll_runs_show.line_dialog.add')"
                  :aria-label="t('payroll_runs_show.line_dialog.add')"
                  @click="openAddLine(item)"
                />
                <VBtn
                  v-if="canEdit()"
                  icon="tabler-trash" size="small" variant="text" color="error" class="row-action"
                  :title="t('payroll_runs_show.employees_tab.remove')"
                  :aria-label="t('payroll_runs_show.employees_tab.remove')"
                  @click="askRemove(item)"
                />
              </template>
            </VDataTable>
          </template>

          <VCardText v-else class="text-center text-medium-emphasis py-10">
            <VIcon size="44" class="mb-2">tabler-users</VIcon>
            <p class="mb-3">{{ t('payroll_runs_show.employees_tab.empty') }}</p>
            <VBtn v-if="canEdit()" color="primary" variant="tonal" prepend-icon="tabler-user-plus" @click="openAddDialog">
              {{ t('payroll_runs_show.employees_tab.add_employees') }}
            </VBtn>
          </VCardText>
        </VCard>
      </VTabsWindowItem>

      <VTabsWindowItem value="anomalies">
        <VCard elevation="0" border>
          <template v-if="anomalies.length">
            <VDataTable
              :items="anomalies"
              :headers="[
                { title: t('payroll_runs_show.anomalies_tab.table.type'),        key: 'anomaly_type' },
                { title: t('payroll_runs_show.anomalies_tab.table.description'), key: 'description' },
                { title: t('payroll_runs_show.anomalies_tab.table.severity'),    key: 'severity' },
                { title: t('payroll_runs_show.anomalies_tab.table.status'),      key: 'status' },
                { title: t('payroll_runs_show.anomalies_tab.table.detected'),    key: 'detected_at' },
              ]"
              density="compact"
              hide-default-footer
            >
              <template #item.anomaly_type="{ item }">{{ item.anomaly_type.replace(/_/g, ' ') }}</template>
              <template #item.description="{ item }">
                <span class="text-caption">{{ item.description?.substring(0, 90) }}{{ (item.description?.length ?? 0) > 90 ? '…' : '' }}</span>
              </template>
              <template #item.severity="{ item }">
                <VChip size="x-small" :color="sevColor(item.severity)" variant="tonal">{{ item.severity }}</VChip>
              </template>
              <template #item.status="{ item }">
                <VChip size="x-small" variant="tonal"
                  :color="{ open: 'error', investigating: 'warning', resolved: 'success', dismissed: 'grey' }[item.status] ?? 'grey'"
                >{{ item.status }}</VChip>
              </template>
            </VDataTable>
          </template>
          <VCardText v-else class="text-center text-medium-emphasis py-10">
            <VIcon size="44" class="mb-2">tabler-circle-check</VIcon>
            <p>{{ t('payroll_runs_show.anomalies_tab.empty') }}</p>
          </VCardText>
        </VCard>
      </VTabsWindowItem>

      <VTabsWindowItem value="approval">
        <VCard elevation="0" border>
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">{{ t('payroll_runs_show.approval_tab.title') }}</VCardTitle>
          <VDivider />
          <VCardText>
            <template v-if="approval">
              <div class="mb-4 d-flex align-center ga-3">
                <VChip :color="{ pending: 'warning', approved: 'success', rejected: 'error' }[approval.status] ?? 'grey'" variant="tonal">
                  {{ approval.status }}
                </VChip>
                <span class="text-body-2 text-medium-emphasis">Level {{ approval.current_level }} · Due {{ approval.due_date }}</span>
              </div>
              <VTimeline v-if="approval.steps?.length" density="compact" align="start">
                <VTimelineItem
                  v-for="(step, i) in approval.steps" :key="i"
                  :dot-color="{ pending: 'grey', approved: 'success', rejected: 'error', escalated: 'warning' }[step.status] ?? 'grey'"
                  size="small"
                >
                  <div class="text-body-2 font-weight-medium">{{ step.approver ?? `Level ${step.level_order ?? i + 1}` }}</div>
                  <div class="text-caption text-medium-emphasis">
                    {{ step.status }}<span v-if="step.acted_at"> · {{ step.acted_at }}</span>
                  </div>
                  <div v-if="step.comments" class="text-caption mt-1 font-italic text-medium-emphasis">"{{ step.comments }}"</div>
                </VTimelineItem>
              </VTimeline>
            </template>
            <div v-else class="text-center text-medium-emphasis py-10">
              <VIcon size="44" class="mb-2">tabler-send-2</VIcon>
              <p>{{ t('payroll_runs_show.approval_tab.empty') }}</p>
            </div>
          </VCardText>
        </VCard>
      </VTabsWindowItem>

      <VTabsWindowItem value="changelog">
        <VCard elevation="0" border>
          <template v-if="changeLogs.length">
            <VDataTable
              :items="changeLogs"
              :headers="[
                { title: t('payroll_runs_show.changelog_tab.table.field'),      key: 'field_changed' },
                { title: t('payroll_runs_show.changelog_tab.table.old'),        key: 'old_value' },
                { title: t('payroll_runs_show.changelog_tab.table.new'),        key: 'new_value' },
                { title: t('payroll_runs_show.changelog_tab.table.type'),       key: 'change_type' },
                { title: t('payroll_runs_show.changelog_tab.table.risk'),       key: 'risk_score', align: 'center' },
                { title: t('payroll_runs_show.changelog_tab.table.changed_at'), key: 'changed_at' },
              ]"
              density="compact"
              hide-default-footer
            >
              <template #item.change_type="{ item }">
                <VChip size="x-small" variant="tonal"
                  :color="{ manual: 'warning', system: 'info', import: 'primary', correction: 'deep-orange' }[item.change_type] ?? 'grey'"
                >{{ item.change_type }}</VChip>
              </template>
              <template #item.risk_score="{ item }">
                <VChip v-if="item.risk_score != null" size="x-small" variant="tonal"
                  :color="item.risk_score >= 70 ? 'error' : item.risk_score >= 40 ? 'warning' : 'success'"
                >{{ item.risk_score }}</VChip>
                <span v-else class="text-caption text-medium-emphasis">—</span>
              </template>
            </VDataTable>
          </template>
          <VCardText v-else class="text-center text-medium-emphasis py-10">
            <VIcon size="44" class="mb-2">tabler-history</VIcon>
            <p>{{ t('payroll_runs_show.changelog_tab.empty') }}</p>
          </VCardText>
        </VCard>
      </VTabsWindowItem>

      <VTabsWindowItem value="audit">
        <VCard elevation="0" border>
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">
            <VIcon class="mr-2" size="18">tabler-shield-check</VIcon>{{ t('payroll_runs_show.audit_tab.title') }}
          </VCardTitle>
          <VDivider />
          <template v-if="auditLogs.length">
            <VTimeline density="compact" align="start" class="pa-4">
              <VTimelineItem
                v-for="log in auditLogs" :key="log.id"
                :dot-color="log.action.includes('released') ? 'success' : log.action.includes('cancel') ? 'error' : 'primary'"
                size="x-small"
              >
                <div class="d-flex align-center ga-2 mb-1">
                  <VIcon size="14">{{ actionIcon(log.action) }}</VIcon>
                  <span class="text-body-2 font-weight-medium">{{ log.action.replace('.', ' ').replace(/_/g, ' ') }}</span>
                  <span class="text-caption text-medium-emphasis ml-auto">{{ log.created_at }}</span>
                </div>
                <p class="text-caption text-medium-emphasis mb-0">{{ log.description }}</p>
                <div v-if="log.meta && Object.keys(log.meta).length" class="mt-1">
                  <VChip
                    v-for="(val, key) in log.meta" :key="key"
                    size="x-small" variant="outlined" class="mr-1"
                  >{{ key }}: {{ val }}</VChip>
                </div>
              </VTimelineItem>
            </VTimeline>
          </template>
          <VCardText v-else class="text-center text-medium-emphasis py-10">
            <VIcon size="44" class="mb-2">tabler-shield</VIcon>
            <p>{{ t('payroll_runs_show.audit_tab.empty') }}</p>
          </VCardText>
        </VCard>
      </VTabsWindowItem>
    </VTabsWindow>

    <VDialog v-model="addDialog" max-width="640">
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">
          <VIcon class="mr-2">tabler-user-plus</VIcon>{{ t('payroll_runs_show.dialog.add_employees_title') }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <p class="text-body-2 text-medium-emphasis mb-3">{{ t('payroll_runs_show.dialog.add_employees_description') }}</p>

          <div v-if="availableEmployees.length" class="d-flex align-center justify-space-between mb-2">
            <VBtn size="small" variant="text" :prepend-icon="allSelected ? 'tabler-square-off' : 'tabler-checkbox'" @click="toggleSelectAll">
              {{ allSelected ? t('payroll_runs_show.dialog.clear_all') : t('payroll_runs_show.dialog.select_all') }}
            </VBtn>
            <span class="text-caption text-medium-emphasis">{{ t('payroll_runs_show.dialog.selected_count', { count: selectedEmpIds.length, total: availableEmployees.length }) }}</span>
          </div>

          <VList v-if="availableEmployees.length" lines="two" density="compact" select-strategy="classic" v-model:selected="selectedEmpIds">
            <VListItem
              v-for="emp in availableEmployees"
              :key="emp.id"
              :value="emp.id"
              :subtitle="emp.job_title ?? 'No title'"
            >
              <template #title>
                <span class="text-body-2 font-weight-medium">{{ emp.name }}</span>
              </template>
              <template #append>
                <span class="text-caption text-medium-emphasis">{{ emp.basic_salary ? fmt(emp.basic_salary) : t('payroll_runs_show.dialog.no_salary') }}</span>
              </template>
            </VListItem>
          </VList>

          <div v-else class="text-center text-medium-emphasis py-6">
            <VIcon size="36" class="mb-2">tabler-user-check</VIcon>
            <p class="text-body-2">All active employees are already on this run.</p>
          </div>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="outlined" @click="addDialog = false">{{ t('payroll_runs_show.dialog.cancel') }}</VBtn>
          <VBtn
            color="primary"
            :disabled="!selectedEmpIds.length"
            :loading="addEmployeesForm.processing"
            @click="confirmAddEmployees"
          >{{ selectedEmpIds.length ? t('payroll_runs_show.dialog.add_count', { count: selectedEmpIds.length }) : t('payroll_runs_show.dialog.add') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Bulk add a line to every employee on the run -->
    <VDialog v-model="bulkDialog" max-width="500">
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold d-flex align-center ga-2">
          <VIcon color="primary">tabler-stack-2</VIcon>{{ t('payroll_runs_show.bulk_dialog.title') }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAlert type="info" variant="tonal" density="compact" class="mb-3">{{ t('payroll_runs_show.bulk_dialog.applies_to', { count: run.employee_count ?? payslips.length }) }}</VAlert>
          <VSelect v-model="bulkForm.line_type" :items="lineTypeOptions" item-title="title" item-value="value" :label="t('payroll_runs_show.line_dialog.type')" variant="outlined" density="comfortable" class="mb-3" />
          <VTextField v-model="bulkForm.description" :label="t('payroll_runs_show.line_dialog.description')" variant="outlined" density="comfortable" class="mb-3" :error-messages="bulkForm.errors.description" />
          <VTextField v-model="bulkForm.amount" type="number" prefix="R" :label="t('payroll_runs_show.line_dialog.amount')" variant="outlined" density="comfortable" :error-messages="bulkForm.errors.amount" />
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="outlined" @click="bulkDialog = false">{{ t('payroll_runs_show.line_dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="bulkForm.processing" :disabled="!bulkForm.description || !bulkForm.amount" @click="submitBulk">
            {{ t('payroll_runs_show.bulk_dialog.confirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Add earnings / deduction line -->
    <VDialog :model-value="lineTarget !== null" max-width="480" @update:model-value="lineTarget = null">
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold d-flex align-center ga-2">
          <VIcon color="primary">tabler-plus</VIcon>{{ t('payroll_runs_show.line_dialog.title', { name: lineTarget?.name ?? '' }) }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VSelect v-model="lineForm.line_type" :items="lineTypeOptions" item-title="title" item-value="value"
            :label="t('payroll_runs_show.line_dialog.type')" variant="outlined" density="comfortable" class="mb-3" :error-messages="lineForm.errors.line_type" />
          <VTextField v-model="lineForm.description" :label="t('payroll_runs_show.line_dialog.description')" variant="outlined" density="comfortable" class="mb-3" :error-messages="lineForm.errors.description" />
          <VTextField v-model="lineForm.amount" type="number" prefix="R" :label="t('payroll_runs_show.line_dialog.amount')" variant="outlined" density="comfortable" :error-messages="lineForm.errors.amount" />
          <div class="text-caption text-medium-emphasis mt-1">{{ t('payroll_runs_show.line_dialog.hint') }}</div>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="outlined" @click="lineTarget = null">{{ t('payroll_runs_show.line_dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="lineForm.processing" :disabled="!lineForm.description || !lineForm.amount" @click="submitLine">
            {{ t('payroll_runs_show.line_dialog.confirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Remove-employee confirmation -->
    <VDialog :model-value="removeTarget !== null" max-width="440" @update:model-value="removeTarget = null">
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold d-flex align-center ga-2">
          <VIcon color="error">tabler-alert-triangle</VIcon>{{ t('payroll_runs_show.remove_dialog.title') }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          {{ t('payroll_runs_show.remove_dialog.message', { name: removeTarget?.name ?? '' }) }}
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="outlined" @click="removeTarget = null">{{ t('payroll_runs_show.remove_dialog.cancel') }}</VBtn>
          <VBtn color="error" :loading="removeEmployeeForm.processing" @click="confirmRemove">
            {{ t('payroll_runs_show.remove_dialog.confirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
