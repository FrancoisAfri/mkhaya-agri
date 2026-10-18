<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

// ─── Types ────────────────────────────────────────────────────────────────────
type PayrollRun = {
  id: number
  uuid: string
  name: string
  period_start: string
  period_end: string
  status: 'draft' | 'processing' | 'review' | 'released' | 'cancelled'
  total_gross: string | null
  total_deductions: string | null
  total_net: string | null
  employee_count: number
  ai_risk_level: 'low' | 'medium' | 'high' | 'critical' | null
  released_at: string | null
  created_at: string
}

type AnomalyResult = {
  employee_id: string
  employee_name: string
  type: string
  description: string
  severity: 'low' | 'medium' | 'high'
  recommended_action: string
}

type PayrollRunDetail = PayrollRun & {
  notes: string | null
  ai_anomaly_summary: AnomalyResult[] | null
}

type PayslipRow = {
  id: number
  uuid: string
  employee_id: number
  employee_name: string
  status: 'draft' | 'finalised' | 'viewed'
  gross_pay: string
  total_deductions: string
  net_pay: string
  basic_salary: string
}

const props = defineProps<{
  run: PayrollRunDetail
  payslips: PayslipRow[]
  employees: { id: number; name: string }[]
}>()

// ─── Helpers ──────────────────────────────────────────────────────────────────
function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}

function formatCurrency(val: string | null) {
  if (val == null) return '—'
  return new Intl.NumberFormat('en-ZA', { style: 'currency', currency: 'ZAR', maximumFractionDigits: 0 }).format(Number(val))
}

function statusColor(status: PayrollRun['status']): string {
  const map: Record<PayrollRun['status'], string> = {
    draft:      'secondary',
    processing: 'info',
    review:     'warning',
    released:   'success',
    cancelled:  'error',
  }
  return map[status] ?? 'default'
}

function payslipStatusColor(status: PayslipRow['status']): string {
  if (status === 'finalised') return 'success'
  if (status === 'viewed')    return 'info'
  return 'secondary'
}

function severityColor(sev: AnomalyResult['severity']): string {
  if (sev === 'high')   return 'error'
  if (sev === 'medium') return 'warning'
  return 'success'
}

function riskColor(level: PayrollRun['ai_risk_level']): string {
  if (level === 'low')                        return 'success'
  if (level === 'medium')                     return 'warning'
  if (level === 'high' || level === 'critical') return 'error'
  return 'default'
}

// ─── Computed state ───────────────────────────────────────────────────────────
const isTerminal = computed(() =>
  props.run.status === 'released' || props.run.status === 'cancelled',
)

const canRelease = computed(() => props.run.status === 'review')

const canCancel = computed(() => !isTerminal.value)

// ─── Action buttons: AI check, release, cancel ────────────────────────────────
const aiChecking  = ref(false)
const releasing   = ref(false)
const cancelling  = ref(false)

function runAiCheck() {
  aiChecking.value = true
  router.post(
    route('payroll.runs.anomaly-check', props.run.uuid),
    {},
    { preserveScroll: true, onFinish: () => { aiChecking.value = false } },
  )
}

function releaseRun() {
  releasing.value = true
  router.post(
    route('payroll.runs.release', props.run.uuid),
    {},
    { preserveScroll: true, onFinish: () => { releasing.value = false } },
  )
}

function cancelRun() {
  cancelling.value = true
  router.post(
    route('payroll.runs.cancel', props.run.uuid),
    {},
    { preserveScroll: true, onFinish: () => { cancelling.value = false } },
  )
}

// ─── Add payslip dialog ───────────────────────────────────────────────────────
const addPayslipDialog = ref(false)

const addForm = useForm({
  employee_id:   null as number | null,
  basic_salary:  '',
})

function submitPayslip() {
  addForm.post(route('payroll.payslips.store', props.run.id), {
    onSuccess: () => {
      addPayslipDialog.value = false
      addForm.reset()
    },
  })
}

watch(addPayslipDialog, (open) => {
  if (!open) addForm.reset()
})

// ─── Anomalies ────────────────────────────────────────────────────────────────
const anomalies = computed(() => props.run.ai_anomaly_summary ?? [])
const hasAnomalies = computed(() => anomalies.value.length > 0)
</script>

<template>
  <div class="pa-6">
    <!-- Back link -->
    <div class="d-flex align-center gap-2 mb-4">
      <VBtn
        icon="tabler-arrow-left"
        variant="text"
        size="small"
        @click="router.visit(route('payroll.runs.index'))"
      />
      <span
        class="text-medium-emphasis text-body-2 cursor-pointer"
        @click="router.visit(route('payroll.runs.index'))"
      >
        Payroll Runs
      </span>
      <VIcon icon="tabler-chevron-right" size="16" class="text-medium-emphasis" />
      <span class="text-body-2 font-weight-medium">{{ run.name }}</span>
    </div>

    <!-- Run Header -->
    <div class="d-flex align-start justify-space-between mb-6 flex-wrap gap-4">
      <div>
        <div class="d-flex align-center gap-2 mb-1 flex-wrap">
          <h4 class="text-h4 font-weight-bold">{{ run.name }}</h4>
          <VChip
            :color="statusColor(run.status)"
            size="small"
            label
            variant="tonal"
            class="font-weight-medium text-capitalize"
          >
            {{ run.status }}
          </VChip>
        </div>
        <p class="text-medium-emphasis mb-0">
          {{ formatDate(run.period_start) }} – {{ formatDate(run.period_end) }}
          <span v-if="run.released_at" class="ms-2 text-success">
            · Released {{ formatDate(run.released_at) }}
          </span>
        </p>
      </div>

      <div class="d-flex gap-2 flex-wrap">
        <VBtn
          color="info"
          variant="tonal"
          prepend-icon="tabler-robot"
          :loading="aiChecking"
          @click="runAiCheck"
        >
          Run AI Check
        </VBtn>
        <VBtn
          v-if="canRelease"
          color="success"
          variant="tonal"
          prepend-icon="tabler-rosette-discount-check"
          :loading="releasing"
          @click="releaseRun"
        >
          Release
        </VBtn>
        <VBtn
          v-if="canCancel"
          color="error"
          variant="outlined"
          prepend-icon="tabler-ban"
          :loading="cancelling"
          @click="cancelRun"
        >
          Cancel
        </VBtn>
      </div>
    </div>

    <VRow>
      <!-- ── Left: Payslips table (8 cols) ─────────────────────────────────── -->
      <VCol cols="12" lg="8">
        <VCard elevation="0" border>
          <VCardTitle class="pa-5 pb-2 d-flex align-center justify-space-between">
            <span class="text-body-1 font-weight-semibold">
              Payslips
              <span class="text-caption text-medium-emphasis ms-2">{{ payslips.length }} employees</span>
            </span>
            <VBtn
              v-if="!isTerminal"
              size="small"
              color="primary"
              variant="tonal"
              prepend-icon="tabler-user-plus"
              @click="addPayslipDialog = true"
            >
              Add Employee
            </VBtn>
          </VCardTitle>

          <VTable>
            <thead>
              <tr>
                <th class="text-body-2 font-weight-semibold">Employee</th>
                <th class="text-body-2 font-weight-semibold text-end">Gross Pay</th>
                <th class="text-body-2 font-weight-semibold text-end">Deductions</th>
                <th class="text-body-2 font-weight-semibold text-end">Net Pay</th>
                <th class="text-body-2 font-weight-semibold">Status</th>
                <th class="text-body-2 font-weight-semibold text-end">View</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!payslips.length">
                <td colspan="6">
                  <div class="text-center py-10">
                    <VIcon icon="tabler-user-dollar" size="52" color="medium-emphasis" class="mb-3" />
                    <p class="text-body-2 font-weight-medium mb-1">No payslips yet</p>
                    <p class="text-caption text-medium-emphasis mb-4">
                      Add employees to generate payslips for this run.
                    </p>
                    <VBtn
                      v-if="!isTerminal"
                      size="small"
                      color="primary"
                      variant="tonal"
                      prepend-icon="tabler-user-plus"
                      @click="addPayslipDialog = true"
                    >
                      Add Employee
                    </VBtn>
                  </div>
                </td>
              </tr>
              <tr
                v-for="slip in payslips"
                :key="slip.id"
              >
                <td>
                  <p class="text-body-2 font-weight-medium mb-0">{{ slip.employee_name }}</p>
                  <p class="text-caption text-medium-emphasis mb-0">Basic: {{ formatCurrency(slip.basic_salary) }}</p>
                </td>
                <td class="text-end">
                  <span class="text-body-2">{{ formatCurrency(slip.gross_pay) }}</span>
                </td>
                <td class="text-end">
                  <span class="text-body-2 text-error">{{ formatCurrency(slip.total_deductions) }}</span>
                </td>
                <td class="text-end">
                  <span class="text-body-2 font-weight-semibold">{{ formatCurrency(slip.net_pay) }}</span>
                </td>
                <td>
                  <VChip
                    :color="payslipStatusColor(slip.status)"
                    size="small"
                    label
                    variant="tonal"
                    class="font-weight-medium text-capitalize"
                  >
                    {{ slip.status }}
                  </VChip>
                </td>
                <td class="text-end">
                  <VBtn
                    size="small"
                    variant="text"
                    color="primary"
                    icon="tabler-eye"
                    @click="router.visit(route('payroll.payslips.show', [run.uuid, slip.uuid]))"
                  />
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>

      <!-- ── Right: Summary + AI Anomaly cards (4 cols) ─────────────────────── -->
      <VCol cols="12" lg="4">
        <div style="position: sticky; top: 80px;" class="d-flex flex-column gap-4">

          <!-- Run Summary -->
          <VCard elevation="0" border>
            <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">Run Summary</VCardTitle>
            <VCardText class="pa-5 pt-0">
              <div class="d-flex flex-column gap-3">
                <div class="d-flex align-center justify-space-between">
                  <span class="text-caption text-medium-emphasis">Employees</span>
                  <span class="text-body-2 font-weight-medium">{{ run.employee_count }}</span>
                </div>
                <VDivider />
                <div class="d-flex align-center justify-space-between">
                  <span class="text-caption text-medium-emphasis">Total Gross</span>
                  <span class="text-body-2 font-weight-medium">{{ formatCurrency(run.total_gross) }}</span>
                </div>
                <div class="d-flex align-center justify-space-between">
                  <span class="text-caption text-medium-emphasis">Total Deductions</span>
                  <span class="text-body-2 font-weight-medium text-error">{{ formatCurrency(run.total_deductions) }}</span>
                </div>
                <VDivider />
                <div class="d-flex align-center justify-space-between">
                  <span class="text-body-2 font-weight-semibold">Total Net Pay</span>
                  <span
                    class="text-body-1 font-weight-bold"
                    style="color: var(--v-theme-success)"
                  >
                    {{ formatCurrency(run.total_net) }}
                  </span>
                </div>
              </div>

              <div v-if="run.notes" class="mt-4 pa-3 rounded-lg" style="background: rgba(var(--v-border-color), 0.05); border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));">
                <p class="text-caption text-medium-emphasis mb-1">Notes</p>
                <p class="text-body-2 mb-0">{{ run.notes }}</p>
              </div>
            </VCardText>
          </VCard>

          <!-- AI Anomaly card (only shown after check) -->
          <VCard v-if="run.ai_risk_level" elevation="0" border>
            <VCardTitle class="pa-5 pb-2 d-flex align-center justify-space-between">
              <div class="d-flex align-center gap-2">
                <VIcon icon="tabler-robot" size="18" color="info" />
                <span class="text-body-1 font-weight-semibold">AI Risk Analysis</span>
              </div>
              <VChip
                :color="riskColor(run.ai_risk_level)"
                size="small"
                label
                variant="tonal"
                class="font-weight-medium text-capitalize"
              >
                {{ run.ai_risk_level }} Risk
              </VChip>
            </VCardTitle>
            <VCardText class="pa-5 pt-0">
              <div v-if="hasAnomalies" class="d-flex flex-column gap-3">
                <div
                  v-for="anomaly in anomalies"
                  :key="anomaly.employee_id + anomaly.type"
                  class="pa-3 rounded-lg"
                  style="border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));"
                >
                  <div class="d-flex align-start justify-space-between gap-2 mb-1">
                    <div>
                      <p class="text-body-2 font-weight-semibold mb-0">{{ anomaly.employee_name }}</p>
                      <p class="text-caption text-medium-emphasis mb-0 text-capitalize">{{ anomaly.type.replace('_', ' ') }}</p>
                    </div>
                    <VChip
                      :color="severityColor(anomaly.severity)"
                      size="x-small"
                      label
                      variant="tonal"
                      class="font-weight-medium text-capitalize flex-shrink-0"
                    >
                      {{ anomaly.severity }}
                    </VChip>
                  </div>
                  <p class="text-caption text-medium-emphasis mb-1">{{ anomaly.description }}</p>
                  <div class="d-flex align-start gap-1">
                    <VIcon icon="tabler-bulb" size="14" color="warning" class="mt-px flex-shrink-0" />
                    <p class="text-caption mb-0">{{ anomaly.recommended_action }}</p>
                  </div>
                </div>
              </div>

              <div v-else class="text-center py-4">
                <VIcon icon="tabler-shield-check" size="40" color="success" class="mb-2" />
                <p class="text-body-2 text-medium-emphasis mb-0">No anomalies detected</p>
              </div>
            </VCardText>
          </VCard>

          <!-- Prompt to run AI check if not done yet -->
          <VCard v-else elevation="0" border>
            <VCardText class="pa-5 text-center">
              <VIcon icon="tabler-robot" size="40" color="medium-emphasis" class="mb-2" />
              <p class="text-body-2 font-weight-medium mb-1">AI Risk Check</p>
              <p class="text-caption text-medium-emphasis mb-3">
                Run an AI anomaly check to detect potential payroll errors before releasing.
              </p>
              <VBtn
                color="info"
                variant="tonal"
                size="small"
                prepend-icon="tabler-robot"
                :loading="aiChecking"
                @click="runAiCheck"
              >
                Run AI Check
              </VBtn>
            </VCardText>
          </VCard>
        </div>
      </VCol>
    </VRow>

    <!-- Add Employee to Payslip Dialog -->
    <VDialog v-model="addPayslipDialog" max-width="480" persistent>
      <VCard elevation="0" border>
        <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">
          Add Employee to Payroll Run
        </VCardTitle>
        <VCardText class="pa-5 pt-2 d-flex flex-column gap-4">
          <VAutocomplete
            v-model="addForm.employee_id"
            :items="employees"
            item-title="name"
            item-value="id"
            label="Employee"
            variant="outlined"
            density="comfortable"
            :error-messages="addForm.errors.employee_id"
            hide-details="auto"
          />
          <VTextField
            v-model="addForm.basic_salary"
            label="Basic Salary (ZAR)"
            type="number"
            min="0"
            step="0.01"
            variant="outlined"
            density="comfortable"
            prefix="R"
            :error-messages="addForm.errors.basic_salary"
            hide-details="auto"
          />
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2">
          <VSpacer />
          <VBtn variant="text" color="default" :disabled="addForm.processing" @click="addPayslipDialog = false">
            Cancel
          </VBtn>
          <VBtn
            color="primary"
            variant="flat"
            :loading="addForm.processing"
            :disabled="!addForm.employee_id || !addForm.basic_salary"
            @click="submitPayslip"
          >
            Add to Run
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
