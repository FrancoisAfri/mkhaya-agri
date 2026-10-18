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

type EarningsLine = {
  id: number
  line_type: string
  description: string
  amount: string
  quantity: string
}

type Payslip = {
  id: number
  uuid: string
  employee_id: number
  employee_name: string
  status: string
  basic_salary: string
  gross_pay: string
  total_deductions: string
  net_pay: string
  paye: string
  uif: string
  pension: string
  period_start: string
  period_end: string
  issued_at: string | null
  earnings_lines: EarningsLine[]
}

const props = defineProps<{
  run: PayrollRun
  payslip: Payslip
}>()

// ─── Helpers ──────────────────────────────────────────────────────────────────
function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}

function formatCurrency(val: string | number | null) {
  if (val == null) return '—'
  return new Intl.NumberFormat('en-ZA', { style: 'currency', currency: 'ZAR', minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(val))
}

function statusColor(status: string): string {
  if (status === 'finalised') return 'success'
  if (status === 'viewed')    return 'info'
  return 'secondary'
}

const DEDUCTION_TYPES = new Set(['paye', 'uif', 'pension', 'other_deduction'])

function isDeduction(lineType: string): boolean {
  return DEDUCTION_TYPES.has(lineType)
}

// ─── Computed earnings / deductions splits ─────────────────────────────────────
const earningLines    = computed(() => props.payslip.earnings_lines.filter(l => !isDeduction(l.line_type)))
const deductionLines  = computed(() => props.payslip.earnings_lines.filter(l => isDeduction(l.line_type)))

// ─── Run state guard ───────────────────────────────────────────────────────────
const isTerminal = computed(() =>
  props.run.status === 'released' || props.run.status === 'cancelled',
)

// ─── Add Line dialog ──────────────────────────────────────────────────────────
const addLineDialog = ref(false)

const lineTypeOptions = [
  { title: 'Basic Salary',     value: 'basic_salary' },
  { title: 'Overtime',         value: 'overtime' },
  { title: 'Commission',       value: 'commission' },
  { title: 'Bonus',            value: 'bonus' },
  { title: 'Allowance',        value: 'allowance' },
  { title: 'Other Earning',    value: 'other_earning' },
  { title: 'PAYE Tax',         value: 'paye' },
  { title: 'UIF',              value: 'uif' },
  { title: 'Pension / RA',     value: 'pension' },
  { title: 'Other Deduction',  value: 'other_deduction' },
]

const lineForm = useForm({
  line_type:   '',
  description: '',
  amount:      '',
  quantity:    '1',
})

function submitLine() {
  lineForm.post(
    route('payroll.payslips.lines.store', [props.run.id, props.payslip.id]),
    {
      onSuccess: () => {
        addLineDialog.value = false
        lineForm.reset()
        lineForm.quantity = '1'
      },
    },
  )
}

watch(addLineDialog, (open) => {
  if (!open) {
    lineForm.reset()
    lineForm.quantity = '1'
  }
})

// ─── Delete line ───────────────────────────────────────────────────────────────
const deletingLine = ref<number | null>(null)

function deleteLine(line: EarningsLine) {
  deletingLine.value = line.id
  router.delete(
    route('payroll.payslips.lines.destroy', [props.run.id, props.payslip.id, line.id]),
    {
      preserveScroll: true,
      onFinish: () => { deletingLine.value = null },
    },
  )
}

// ─── Line type label helper ────────────────────────────────────────────────────
function lineTypeLabel(val: string): string {
  return lineTypeOptions.find(o => o.value === val)?.title ?? val
}
</script>

<template>
  <div class="pa-6">
    <!-- Back link -->
    <div class="d-flex align-center gap-2 mb-4">
      <VBtn
        icon="tabler-arrow-left"
        variant="text"
        size="small"
        @click="router.visit(route('payroll.runs.show', run.uuid))"
      />
      <span
        class="text-medium-emphasis text-body-2 cursor-pointer"
        @click="router.visit(route('payroll.runs.index'))"
      >
        Payroll Runs
      </span>
      <VIcon icon="tabler-chevron-right" size="16" class="text-medium-emphasis" />
      <span
        class="text-medium-emphasis text-body-2 cursor-pointer"
        @click="router.visit(route('payroll.runs.show', run.uuid))"
      >
        {{ run.name }}
      </span>
      <VIcon icon="tabler-chevron-right" size="16" class="text-medium-emphasis" />
      <span class="text-body-2 font-weight-medium">{{ payslip.employee_name }}</span>
    </div>

    <!-- Payslip Header -->
    <div class="d-flex align-start justify-space-between mb-6 flex-wrap gap-4">
      <div>
        <div class="d-flex align-center gap-2 mb-1 flex-wrap">
          <h4 class="text-h4 font-weight-bold">{{ payslip.employee_name }}</h4>
          <VChip
            :color="statusColor(payslip.status)"
            size="small"
            label
            variant="tonal"
            class="font-weight-medium text-capitalize"
          >
            {{ payslip.status }}
          </VChip>
        </div>
        <p class="text-medium-emphasis mb-0">
          Pay period: {{ formatDate(payslip.period_start) }} – {{ formatDate(payslip.period_end) }}
          <span v-if="payslip.issued_at" class="ms-2 text-success">
            · Issued {{ formatDate(payslip.issued_at) }}
          </span>
        </p>
      </div>

      <div class="d-flex gap-2 flex-wrap">
        <VBtn
          v-if="run.status === 'released'"
          color="success"
          variant="tonal"
          prepend-icon="tabler-file-type-pdf"
          :href="route('payroll.payslips.download', [run.id, payslip.id])"
          target="_blank"
        >
          Download PDF
        </VBtn>
        <VBtn
          v-if="!isTerminal"
          color="primary"
          variant="tonal"
          prepend-icon="tabler-plus"
          @click="addLineDialog = true"
        >
          Add Line
        </VBtn>
      </div>
    </div>

    <VRow>
      <!-- ── Left: Earnings + Deductions tables ────────────────────────────── -->
      <VCol cols="12" lg="8">
        <!-- Earnings -->
        <VCard elevation="0" border class="mb-5">
          <VCardTitle class="pa-5 pb-2 d-flex align-center gap-2">
            <VIcon icon="tabler-cash" color="success" size="20" />
            <span class="text-body-1 font-weight-semibold">Earnings</span>
          </VCardTitle>
          <VTable>
            <thead>
              <tr>
                <th class="text-body-2 font-weight-semibold">Description</th>
                <th class="text-body-2 font-weight-semibold">Type</th>
                <th class="text-body-2 font-weight-semibold text-end">Qty</th>
                <th class="text-body-2 font-weight-semibold text-end">Amount</th>
                <th v-if="!isTerminal" class="text-body-2 font-weight-semibold text-end" style="width: 52px;" />
              </tr>
            </thead>
            <tbody>
              <tr v-if="!earningLines.length">
                <td :colspan="isTerminal ? 4 : 5" class="text-center py-8 text-medium-emphasis text-body-2">
                  No earning lines added yet.
                </td>
              </tr>
              <tr v-for="line in earningLines" :key="line.id">
                <td>
                  <span class="text-body-2 font-weight-medium">{{ line.description }}</span>
                </td>
                <td>
                  <VChip size="x-small" color="success" variant="tonal" label class="font-weight-medium">
                    {{ lineTypeLabel(line.line_type) }}
                  </VChip>
                </td>
                <td class="text-end">
                  <span class="text-body-2 text-medium-emphasis">{{ line.quantity }}</span>
                </td>
                <td class="text-end">
                  <span class="text-body-2 font-weight-medium text-success">{{ formatCurrency(line.amount) }}</span>
                </td>
                <td v-if="!isTerminal" class="text-end">
                  <VBtn
                    size="x-small"
                    variant="text"
                    color="error"
                    icon="tabler-trash"
                    :loading="deletingLine === line.id"
                    @click="deleteLine(line)"
                  />
                </td>
              </tr>
              <!-- Gross subtotal row -->
              <tr v-if="earningLines.length" style="background: rgba(var(--v-theme-success), 0.06);">
                <td :colspan="isTerminal ? 3 : 4" class="text-end">
                  <span class="text-body-2 font-weight-semibold">Gross Pay</span>
                </td>
                <td class="text-end">
                  <span class="text-body-1 font-weight-bold text-success">{{ formatCurrency(payslip.gross_pay) }}</span>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>

        <!-- Deductions -->
        <VCard elevation="0" border>
          <VCardTitle class="pa-5 pb-2 d-flex align-center gap-2">
            <VIcon icon="tabler-cash-off" color="error" size="20" />
            <span class="text-body-1 font-weight-semibold">Deductions</span>
          </VCardTitle>
          <VTable>
            <thead>
              <tr>
                <th class="text-body-2 font-weight-semibold">Description</th>
                <th class="text-body-2 font-weight-semibold">Type</th>
                <th class="text-body-2 font-weight-semibold text-end">Qty</th>
                <th class="text-body-2 font-weight-semibold text-end">Amount</th>
                <th v-if="!isTerminal" class="text-body-2 font-weight-semibold text-end" style="width: 52px;" />
              </tr>
            </thead>
            <tbody>
              <!-- Statutory deductions from top-level fields (if no custom lines) -->
              <tr v-if="!deductionLines.length && (payslip.paye || payslip.uif || payslip.pension)">
                <td colspan="5" class="py-1">
                  <div class="d-flex flex-column gap-1 py-2 px-4">
                    <div v-if="Number(payslip.paye)" class="d-flex align-center justify-space-between py-1">
                      <div class="d-flex align-center gap-2">
                        <span class="text-body-2 font-weight-medium">PAYE Tax</span>
                        <VChip size="x-small" color="error" variant="tonal" label>PAYE</VChip>
                      </div>
                      <span class="text-body-2 text-error">{{ formatCurrency(payslip.paye) }}</span>
                    </div>
                    <div v-if="Number(payslip.uif)" class="d-flex align-center justify-space-between py-1">
                      <div class="d-flex align-center gap-2">
                        <span class="text-body-2 font-weight-medium">UIF Contribution</span>
                        <VChip size="x-small" color="error" variant="tonal" label>UIF</VChip>
                      </div>
                      <span class="text-body-2 text-error">{{ formatCurrency(payslip.uif) }}</span>
                    </div>
                    <div v-if="Number(payslip.pension)" class="d-flex align-center justify-space-between py-1">
                      <div class="d-flex align-center gap-2">
                        <span class="text-body-2 font-weight-medium">Pension / RA</span>
                        <VChip size="x-small" color="error" variant="tonal" label>Pension</VChip>
                      </div>
                      <span class="text-body-2 text-error">{{ formatCurrency(payslip.pension) }}</span>
                    </div>
                  </div>
                </td>
              </tr>

              <tr v-if="!deductionLines.length && !Number(payslip.paye) && !Number(payslip.uif) && !Number(payslip.pension)">
                <td :colspan="isTerminal ? 4 : 5" class="text-center py-8 text-medium-emphasis text-body-2">
                  No deduction lines added yet.
                </td>
              </tr>

              <tr v-for="line in deductionLines" :key="line.id">
                <td>
                  <span class="text-body-2 font-weight-medium">{{ line.description }}</span>
                </td>
                <td>
                  <VChip size="x-small" color="error" variant="tonal" label class="font-weight-medium">
                    {{ lineTypeLabel(line.line_type) }}
                  </VChip>
                </td>
                <td class="text-end">
                  <span class="text-body-2 text-medium-emphasis">{{ line.quantity }}</span>
                </td>
                <td class="text-end">
                  <span class="text-body-2 font-weight-medium text-error">{{ formatCurrency(line.amount) }}</span>
                </td>
                <td v-if="!isTerminal" class="text-end">
                  <VBtn
                    size="x-small"
                    variant="text"
                    color="error"
                    icon="tabler-trash"
                    :loading="deletingLine === line.id"
                    @click="deleteLine(line)"
                  />
                </td>
              </tr>

              <!-- Deductions subtotal -->
              <tr style="background: rgba(var(--v-theme-error), 0.06);">
                <td :colspan="isTerminal ? 3 : 4" class="text-end">
                  <span class="text-body-2 font-weight-semibold">Total Deductions</span>
                </td>
                <td class="text-end">
                  <span class="text-body-1 font-weight-bold text-error">{{ formatCurrency(payslip.total_deductions) }}</span>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>

      <!-- ── Right: Summary box ─────────────────────────────────────────────── -->
      <VCol cols="12" lg="4">
        <div style="position: sticky; top: 80px;" class="d-flex flex-column gap-4">

          <!-- Net Pay summary — styled prominently -->
          <VCard elevation="0" border>
            <VCardText class="pa-6">
              <p class="text-caption text-medium-emphasis mb-4 text-uppercase font-weight-semibold tracking-widest">
                Pay Summary
              </p>

              <div class="d-flex flex-column gap-3 mb-4">
                <div class="d-flex align-center justify-space-between">
                  <span class="text-body-2 text-medium-emphasis">Basic Salary</span>
                  <span class="text-body-2 font-weight-medium">{{ formatCurrency(payslip.basic_salary) }}</span>
                </div>
                <div class="d-flex align-center justify-space-between">
                  <span class="text-body-2 text-medium-emphasis">Gross Pay</span>
                  <span class="text-body-2 font-weight-semibold text-success">{{ formatCurrency(payslip.gross_pay) }}</span>
                </div>
                <div class="d-flex align-center justify-space-between">
                  <span class="text-body-2 text-medium-emphasis">PAYE Tax</span>
                  <span class="text-body-2 text-error">{{ formatCurrency(payslip.paye) }}</span>
                </div>
                <div class="d-flex align-center justify-space-between">
                  <span class="text-body-2 text-medium-emphasis">UIF</span>
                  <span class="text-body-2 text-error">{{ formatCurrency(payslip.uif) }}</span>
                </div>
                <div class="d-flex align-center justify-space-between">
                  <span class="text-body-2 text-medium-emphasis">Pension / RA</span>
                  <span class="text-body-2 text-error">{{ formatCurrency(payslip.pension) }}</span>
                </div>
                <div class="d-flex align-center justify-space-between">
                  <span class="text-body-2 text-medium-emphasis">Total Deductions</span>
                  <span class="text-body-2 font-weight-semibold text-error">{{ formatCurrency(payslip.total_deductions) }}</span>
                </div>
              </div>

              <VDivider class="mb-4" />

              <div
                class="pa-4 rounded-lg d-flex align-center justify-space-between"
                style="background: rgba(var(--v-theme-success), 0.1); border: 1px solid rgba(var(--v-theme-success), 0.3);"
              >
                <div>
                  <p class="text-caption font-weight-semibold text-uppercase text-success mb-0">Net Pay</p>
                  <p class="text-caption text-medium-emphasis mb-0">Take-home amount</p>
                </div>
                <span class="text-h5 font-weight-bold text-success">
                  {{ formatCurrency(payslip.net_pay) }}
                </span>
              </div>

              <div v-if="payslip.issued_at" class="mt-4 d-flex align-center gap-2">
                <VIcon icon="tabler-circle-check" color="success" size="16" />
                <span class="text-caption text-success">Issued {{ formatDate(payslip.issued_at) }}</span>
              </div>
            </VCardText>
          </VCard>

          <!-- Run Context card -->
          <VCard elevation="0" border>
            <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">Payroll Run</VCardTitle>
            <VCardText class="pa-5 pt-0">
              <div class="d-flex flex-column gap-2">
                <div class="d-flex align-center justify-space-between">
                  <span class="text-caption text-medium-emphasis">Run</span>
                  <span
                    class="text-body-2 font-weight-medium text-primary cursor-pointer"
                    @click="router.visit(route('payroll.runs.show', run.uuid))"
                  >
                    {{ run.name }}
                  </span>
                </div>
                <div class="d-flex align-center justify-space-between">
                  <span class="text-caption text-medium-emphasis">Status</span>
                  <VChip
                    :color="run.status === 'released' ? 'success' : run.status === 'cancelled' ? 'error' : run.status === 'review' ? 'warning' : run.status === 'processing' ? 'info' : 'secondary'"
                    size="x-small"
                    label
                    variant="tonal"
                    class="font-weight-medium text-capitalize"
                  >
                    {{ run.status }}
                  </VChip>
                </div>
                <div class="d-flex align-center justify-space-between">
                  <span class="text-caption text-medium-emphasis">Period</span>
                  <span class="text-body-2">{{ formatDate(run.period_start) }} – {{ formatDate(run.period_end) }}</span>
                </div>
              </div>
            </VCardText>
          </VCard>
        </div>
      </VCol>
    </VRow>

    <!-- Add Line Dialog -->
    <VDialog v-model="addLineDialog" max-width="500" persistent>
      <VCard elevation="0" border>
        <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">
          Add Earnings / Deduction Line
        </VCardTitle>
        <VCardText class="pa-5 pt-2 d-flex flex-column gap-4">
          <VSelect
            v-model="lineForm.line_type"
            :items="lineTypeOptions"
            item-title="title"
            item-value="value"
            label="Line Type"
            variant="outlined"
            density="comfortable"
            :error-messages="lineForm.errors.line_type"
            hide-details="auto"
          />
          <VTextField
            v-model="lineForm.description"
            label="Description"
            placeholder="e.g. Overtime May 2026"
            variant="outlined"
            density="comfortable"
            :error-messages="lineForm.errors.description"
            hide-details="auto"
          />
          <VRow dense>
            <VCol cols="12" sm="7">
              <VTextField
                v-model="lineForm.amount"
                label="Amount (ZAR)"
                type="number"
                min="0"
                step="0.01"
                variant="outlined"
                density="comfortable"
                prefix="R"
                :error-messages="lineForm.errors.amount"
                hide-details="auto"
              />
            </VCol>
            <VCol cols="12" sm="5">
              <VTextField
                v-model="lineForm.quantity"
                label="Quantity"
                type="number"
                min="1"
                step="1"
                variant="outlined"
                density="comfortable"
                :error-messages="lineForm.errors.quantity"
                hide-details="auto"
              />
            </VCol>
          </VRow>

          <div
            v-if="lineForm.line_type"
            class="pa-3 rounded-lg"
            :style="isDeduction(lineForm.line_type)
              ? 'background: rgba(var(--v-theme-error), 0.07); border: 1px solid rgba(var(--v-theme-error), 0.25);'
              : 'background: rgba(var(--v-theme-success), 0.07); border: 1px solid rgba(var(--v-theme-success), 0.25);'"
          >
            <div class="d-flex align-center gap-2">
              <VIcon
                :icon="isDeduction(lineForm.line_type) ? 'tabler-cash-off' : 'tabler-cash'"
                :color="isDeduction(lineForm.line_type) ? 'error' : 'success'"
                size="16"
              />
              <span
                class="text-caption font-weight-medium"
                :class="isDeduction(lineForm.line_type) ? 'text-error' : 'text-success'"
              >
                This line will be recorded as a {{ isDeduction(lineForm.line_type) ? 'deduction' : 'earning' }}
              </span>
            </div>
          </div>
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2">
          <VSpacer />
          <VBtn variant="text" color="default" :disabled="lineForm.processing" @click="addLineDialog = false">
            Cancel
          </VBtn>
          <VBtn
            color="primary"
            variant="flat"
            :loading="lineForm.processing"
            :disabled="!lineForm.line_type || !lineForm.description || !lineForm.amount"
            @click="submitLine"
          >
            Add Line
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
