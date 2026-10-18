<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

type ReportRow = Record<string, unknown>

interface LeaveTypeOption  { id: number; name: string }
interface DepartmentOption { id: number | string; name: string }
interface EmployeeOption   { id: number; name: string }

interface Props {
  balances:           ReportRow[]
  negativeBalances:   ReportRow[]
  taken:              ReportRow[]
  transactions:       ReportRow[]
  allowances:         ReportRow[]
  payouts:            ReportRow[]
  liability:          ReportRow[]
  pending:            ReportRow[]
  cancelled:          ReportRow[]
  violations:         ReportRow[]
  anomalies:          ReportRow[]
  burnoutRisks:       ReportRow[]
  deptTrends:         ReportRow[]
  leaveTypes:         LeaveTypeOption[]
  departments:        DepartmentOption[]
  employees:          EmployeeOption[]
  balanceAsOfEnabled: boolean
}

const props = withDefaults(defineProps<Props>(), {
  balances:           () => [],
  negativeBalances:   () => [],
  taken:              () => [],
  transactions:       () => [],
  allowances:         () => [],
  payouts:            () => [],
  liability:          () => [],
  pending:            () => [],
  cancelled:          () => [],
  violations:         () => [],
  anomalies:          () => [],
  burnoutRisks:       () => [],
  deptTrends:         () => [],
  leaveTypes:         () => [],
  departments:        () => [],
  employees:          () => [],
  balanceAsOfEnabled: false,
})

const activeTab = ref('balance')
const toast = reactive({ show: false, text: '', color: 'success' })

// ── Balance-as-of feature ─────────────────────────────────────────────────
const balanceAsOfDate    = ref<string | null>(null)
const balanceAsOfRows    = ref<ReportRow[] | null>(null)
const balanceAsOfLoading = ref(false)

async function loadBalanceAsOf(date: string) {
  if (!date) { balanceAsOfRows.value = null; return }
  balanceAsOfLoading.value = true
  try {
    const params = new URLSearchParams({ date })
    if (filters.employeeIds.length) params.set('employee_ids', filters.employeeIds.join(','))
    if (filters.departments.length)  params.set('dept_ids',     filters.departments.join(','))
    const res = await fetch(`/leave-management/reports/balance-as-of?${params}`, {
      headers: { Accept: 'application/json' },
    })
    if (!res.ok) throw new Error()
    const json = await res.json()
    balanceAsOfRows.value = json.rows
    setToast(`Balance as of ${date}: ${json.count} records loaded.`)
  } catch {
    setToast('Failed to load balance as-of data.', 'error')
  } finally {
    balanceAsOfLoading.value = false
  }
}

// When the date picker changes, fetch automatically
function onBalanceAsOfDateChange(val: string | null) {
  balanceAsOfDate.value = val
  if (val) loadBalanceAsOf(val)
  else balanceAsOfRows.value = null
}

// Friendly date display: "2026-05-13" → "13 May 2026"
function formatDate(val: unknown): string {
  if (!val) return '—'
  const d = new Date(String(val) + 'T00:00:00') // force local-tz parse
  if (Number.isNaN(d.getTime())) return String(val)
  return d.toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}

const filters = reactive({
  employeeIds:  [] as number[],
  departments:  [] as (number | string)[],
  leaveTypeId:  null as number | null,
  status:       null as string | null,
  dateFrom:     null as string | null,
  dateTo:       null as string | null,
})

const reportCards = [
  { key: 'balance',     icon: 'tabler-scale-balance',        color: 'primary',  title: 'Leave Balance Report',       description: 'Current balance, entitlement, and carry-over per employee and leave type.' },
  { key: 'taken',       icon: 'tabler-calendar-check',       color: 'success',  title: 'Leave Taken Report',          description: 'Approved leave taken by employee with dates, duration, and approver.' },
  { key: 'transaction', icon: 'tabler-swap-horizontal',      color: 'info',     title: 'Transaction Report',          description: 'Full ledger of credit and debit entries for each employee balance.' },
  { key: 'allowance',   icon: 'tabler-gift-outline',         color: 'teal',     title: 'Allowance Report',            description: 'Allocation vs. utilisation comparison per employee and leave type.' },
  { key: 'payout',      icon: 'tabler-cash-multiple',        color: 'orange',   title: 'Paid-Out Leave Report',       description: 'Leave days paid out in lieu of taking leave, with rand amounts.' },
  { key: 'pending',     icon: 'tabler-clock-outline',        color: 'warning',  title: 'Pending Leave Report',        description: 'All unactioned leave requests with submission date and days waiting.' },
  { key: 'cancelled',   icon: 'tabler-cancel',               color: 'secondary',title: 'Cancelled Leave Report',      description: 'Cancelled and withdrawn requests with cancellation reason.' },
  { key: 'violation',   icon: 'tabler-alert-circle-outline', color: 'error',    title: 'Policy Violation Report',     description: 'Leave requests that breach BCEA or policy rules (backdating, balance exceeded).' },
  { key: 'anomaly',     icon: 'tabler-robot-confused',       color: 'deep-purple', title: 'AI Anomaly Report',        description: 'Machine-learning anomaly flags: pattern deviations and suspicious absences.' },
  { key: 'burnout',     icon: 'tabler-fire-alert',           color: 'red',      title: 'Burnout Risk Report',         description: 'Employees at risk of burnout based on leave frequency and unused balance.' },
  { key: 'trend',       icon: 'tabler-chart-line',           color: 'indigo',   title: 'Department Leave Trend',      description: 'Aggregate leave trends by department, type, and month over the cycle.' },
  { key: 'negative',    icon: 'tabler-alert-circle',         color: 'error',    title: 'Negative Balance Report',     description: 'Employees with negative leave balances requiring HR review or repayment.' },
  { key: 'liability',   icon: 'tabler-cash-register',        color: 'brown',    title: 'Leave Liability Report',      description: 'Outstanding leave hours representing financial liability at cycle end.' },
]

const queueLoading = ref(false)

async function queueReport(key: string) {
  queueLoading.value = true
  try {
    const res = await fetch(`/leave-management/reports/queue/${key}`, {
      method:  'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
      },
      body: JSON.stringify({}),
    })
    if (!res.ok) throw new Error()
    setToast(`${key} report queued — you'll receive it by email when ready.`)
  } catch {
    setToast(`Failed to queue ${key} report.`, 'error')
  } finally {
    queueLoading.value = false
  }
}

// scheduledReports comes from props — loaded from the database

const headersByReport: Record<string, { title: string; key: string }[]> = {
  balance: [
    { title: 'Employee',       key: 'employee_name' },
    { title: 'Department',     key: 'department' },
    { title: 'Leave Type',     key: 'leave_type' },
    { title: 'Entitlement (d)', key: 'entitlement_days' },
    { title: 'Used (d)',       key: 'used_days' },
    { title: 'Remaining (d)',  key: 'remaining_days' },
    { title: 'Carry Over (d)', key: 'carry_over_days' },
    { title: 'Cycle',          key: 'cycle' },
  ],
  taken: [
    { title: 'Employee',    key: 'employee_name' },
    { title: 'Department',  key: 'department' },
    { title: 'Leave Type',  key: 'leave_type' },
    { title: 'Start Date',  key: 'start_date' },
    { title: 'End Date',    key: 'end_date' },
    { title: 'Days',        key: 'working_days' },
    { title: 'Status',      key: 'status' },
    { title: 'Approved By', key: 'approved_by' },
  ],
  transaction: [
    { title: 'Date',          key: 'created_at' },
    { title: 'Employee',      key: 'employee_name' },
    { title: 'Department',    key: 'department' },
    { title: 'Leave Type',    key: 'leave_type' },
    { title: 'Type',            key: 'transaction_type' },
    { title: 'Amount (days)',   key: 'days' },
    { title: 'Balance (days)',  key: 'balance_after_days' },
    { title: 'Reference',       key: 'reference' },
  ],
  allowance: [
    { title: 'Employee',       key: 'employee_name' },
    { title: 'Department',     key: 'department' },
    { title: 'Leave Type',     key: 'leave_type' },
    { title: 'Allocation (d)', key: 'allocation_days' },
    { title: 'Used (d)',       key: 'used_days' },
    { title: 'Remaining (d)',  key: 'remaining_days' },
    { title: 'Neg Balance',    key: 'allow_negative_balance' },
  ],
  payout: [
    { title: 'Employee',    key: 'employee_name' },
    { title: 'Department',  key: 'department' },
    { title: 'Leave Type',  key: 'leave_type' },
    { title: 'Days Paid',   key: 'days_paid' },
    { title: 'Amount (R)',  key: 'amount_rand' },
    { title: 'Paid On',     key: 'paid_on' },
    { title: 'Reference',   key: 'reference' },
  ],
  pending: [
    { title: 'Employee',      key: 'employee_name' },
    { title: 'Department',    key: 'department' },
    { title: 'Leave Type',    key: 'leave_type' },
    { title: 'Start Date',    key: 'start_date' },
    { title: 'End Date',      key: 'end_date' },
    { title: 'Days',          key: 'working_days' },
    { title: 'Submitted On',  key: 'created_at' },
    { title: 'Days Waiting',  key: 'days_waiting' },
  ],
  cancelled: [
    { title: 'Employee',      key: 'employee_name' },
    { title: 'Department',    key: 'department' },
    { title: 'Leave Type',    key: 'leave_type' },
    { title: 'Start Date',    key: 'start_date' },
    { title: 'End Date',      key: 'end_date' },
    { title: 'Days',          key: 'working_days' },
    { title: 'Cancelled By',  key: 'cancelled_by' },
    { title: 'Reason',        key: 'cancel_reason' },
  ],
  violation: [
    { title: 'Employee',        key: 'employee_name' },
    { title: 'Department',      key: 'department' },
    { title: 'Leave Type',      key: 'leave_type' },
    { title: 'Violation Type',  key: 'violation_type' },
    { title: 'Severity',        key: 'severity' },
    { title: 'Date',            key: 'created_at' },
    { title: 'Status',          key: 'status' },
  ],
  anomaly: [
    { title: 'Employee',     key: 'employee_name' },
    { title: 'Department',   key: 'department' },
    { title: 'Anomaly Type', key: 'anomaly_type' },
    { title: 'Severity',     key: 'severity' },
    { title: 'Detected At',  key: 'detected_at' },
    { title: 'AI Score',     key: 'ai_score' },
    { title: 'Status',       key: 'status' },
    { title: 'Action Taken', key: 'action_taken' },
  ],
  burnout: [
    { title: 'Employee',        key: 'employee_name' },
    { title: 'Department',      key: 'department' },
    { title: 'Risk Score',      key: 'risk_score' },
    { title: 'Leave Frequency', key: 'leave_frequency' },
    { title: 'Days Used',       key: 'days_used' },
    { title: 'Sick Days',       key: 'sick_days' },
    { title: 'Flag Date',       key: 'flagged_at' },
    { title: 'Reviewed',        key: 'reviewed' },
  ],
  trend: [
    { title: 'Department',         key: 'department' },
    { title: 'Leave Type',         key: 'leave_type' },
    { title: 'Total Requests',     key: 'total_requests' },
    { title: 'Total Days',         key: 'total_days' },
    { title: 'Avg Days/Employee',  key: 'avg_days_per_employee' },
    { title: 'Peak Month',         key: 'peak_month' },
  ],
  negative: [
    { title: 'Employee',       key: 'employee_name' },
    { title: 'Leave Type',     key: 'leave_type' },
    { title: 'Balance (h)',    key: 'balance_hours' },
    { title: 'Balance (d)',    key: 'balance_days' },
  ],
  liability: [
    { title: 'Employee',            key: 'employee_name' },
    { title: 'Leave Type',          key: 'leave_type' },
    { title: 'Balance (h)',         key: 'balance_hours' },
    { title: 'Balance (d)',         key: 'balance_days' },
    { title: 'Liability Hours',     key: 'estimated_liability_hours' },
  ],
}

const reportDataMap = computed<Record<string, ReportRow[]>>(() => ({
  balance:     balanceAsOfRows.value ?? props.balances,
  taken:       props.taken,
  transaction: props.transactions,
  allowance:   props.allowances,
  payout:      props.payouts,
  pending:     props.pending,
  cancelled:   props.cancelled,
  violation:   props.violations,
  anomaly:     props.anomalies,
  burnout:     props.burnoutRisks,
  trend:       props.deptTrends,
  negative:    props.negativeBalances,
  liability:   props.liability,
}))

function normalizeDate(value: unknown): number | null {
  if (!value) return null
  const s = String(value)
  // Dates from backend are ISO "YYYY-MM-DD" — force local-tz interpretation
  const iso = /^\d{4}-\d{2}-\d{2}$/.test(s) ? Date.parse(s + 'T00:00:00') : Date.parse(s)
  return Number.isNaN(iso) ? null : iso
}

function rowDateValue(row: ReportRow): number | null {
  const dateKeys = ['created_at', 'start_date', 'end_date', 'detected_at', 'flagged_at', 'paid_on']
  for (const key of dateKeys) {
    const parsed = normalizeDate(row[key])
    if (parsed !== null) return parsed
  }
  return null
}

function applyFilters(rows: ReportRow[]): ReportRow[] {
  let next = [...rows]

  // Employee filter — match by selected employee names from the dropdown
  if (filters.employeeIds.length) {
    const selectedNames = new Set(
      filters.employeeIds.map(id => props.employees.find(e => e.id === id)?.name ?? '').filter(Boolean)
    )
    next = next.filter(r => selectedNames.has(String(r.employee_name ?? '')))
  }

  // Department filter — one or many
  if (filters.departments.length) {
    const deptNames = new Set(
      filters.departments
        .map(id => props.departments.find(d => String(d.id) === String(id))?.name ?? '')
        .filter(Boolean)
    )
    next = next.filter(r => !r.department || deptNames.has(String(r.department ?? '')))
  }

  if (filters.leaveTypeId) {
    const ltName = props.leaveTypes.find(lt => lt.id === filters.leaveTypeId)?.name
    if (ltName) next = next.filter(r => r.leave_type === ltName)
  }

  if (filters.status) {
    const wanted = filters.status.toLowerCase()
    next = next.filter(r => String(r.status ?? '').toLowerCase() === wanted)
  }

  const fromTs = normalizeDate(filters.dateFrom)
  const toTs   = normalizeDate(filters.dateTo)
  if (fromTs !== null || toTs !== null) {
    next = next.filter((r) => {
      const dt = rowDateValue(r)
      if (dt === null) return true
      if (fromTs !== null && dt < fromTs) return false
      if (toTs   !== null && dt > toTs)   return false
      return true
    })
  }

  return next
}

function reportRows(key: string): ReportRow[] {
  return applyFilters(reportDataMap.value[key] ?? [])
}

const activeRows    = computed(() => reportRows(activeTab.value))
const activeHeaders = computed(() => headersByReport[activeTab.value] ?? [])

function setToast(text: string, color = 'success') {
  toast.text  = text
  toast.color = color
  toast.show  = true
}

function switchToReport(key: string) { activeTab.value = key }

function buildFilterQueryString() {
  const params = new URLSearchParams()
  if (filters.employeeIds.length)  params.set('employee_ids', filters.employeeIds.join(','))
  if (filters.departments.length)  params.set('departments',  filters.departments.join(','))
  if (filters.leaveTypeId)         params.set('leave_type_id', String(filters.leaveTypeId))
  if (filters.status)              params.set('status', filters.status)
  if (filters.dateFrom)            params.set('dateFrom', filters.dateFrom)
  if (filters.dateTo)              params.set('dateTo', filters.dateTo)
  return params.toString()
}

async function generateReport(key: string) {
  activeTab.value = key
  const qs  = buildFilterQueryString()
  const url = `/leave-management/reports/generate/${key}${qs ? `?${qs}` : ''}`
  try {
    const res = await fetch(url, { headers: { Accept: 'application/json' } })
    if (!res.ok) throw new Error()
    const payload = await res.json()
    setToast(`Generated ${payload.count} rows for ${key} report.`)
  }
  catch {
    setToast(`Could not generate ${key} report.`, 'error')
  }
}

function exportReport(key: string, format: 'excel' | 'pdf') {
  const qs            = buildFilterQueryString()
  const backendFormat = format === 'excel' ? 'xlsx' : 'pdf'
  const url           = `/leave-management/reports/export/${key}/${backendFormat}${qs ? `?${qs}` : ''}`
  window.open(url, '_blank')
  setToast(`Started ${format.toUpperCase()} export for ${key}.`)
}

function clearFilters() {
  filters.employeeIds  = []
  filters.departments  = []
  filters.leaveTypeId  = null
  filters.status       = null
  filters.dateFrom     = null
  filters.dateTo       = null
  balanceAsOfDate.value = null
  balanceAsOfRows.value = null
}

// ── Column totals ─────────────────────────────────────────────────────────

// Which columns to SUM per report tab
const sumColumns: Record<string, string[]> = {
  balance:     ['entitlement_days', 'used_days', 'remaining_days', 'carry_over_days'],
  taken:       ['working_days'],
  transaction: ['days'],
  allowance:   ['allocation_days', 'used_days', 'remaining_days'],
  payout:      ['hours_paid_out', 'amount'],
  pending:     ['working_days', 'days_waiting'],
  cancelled:   ['working_days'],
  trend:       ['total_requests', 'total_days'],
  negative:    ['balance_hours', 'balance_days'],
  liability:   ['balance_hours', 'balance_days', 'estimated_liability_hours'],
}

const columnTotals = computed<Record<string, number>>(() => {
  const cols = sumColumns[activeTab.value] ?? []
  if (!cols.length) return {}
  const totals: Record<string, number> = {}
  for (const col of cols) {
    totals[col] = activeRows.value.reduce((sum, row) => sum + (Number(row[col]) || 0), 0)
  }
  return totals
})

function fmtTotal(colKey: string, val: number): string {
  if (colKey === 'amount') {
    return `R ${val.toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
  }
  if (colKey === 'total_requests' || colKey === 'days_waiting') {
    return String(Math.round(val))
  }
  if (colKey.endsWith('_hours') || colKey === 'hours') {
    return `${(Math.round(val * 10) / 10).toLocaleString('en-ZA')} h`
  }
  // days columns
  return `${(Math.round(val * 10) / 10).toLocaleString('en-ZA')} d`
}

const { t } = useI18n()

function severityColor(s: unknown) {
  return ({ critical: 'error', high: 'warning', medium: 'orange', low: 'info' } as Record<string, string>)[String(s)] ?? 'secondary'
}

function riskColor(score: unknown) {
  const n = Number(score)
  if (n >= 80) return 'error'
  if (n >= 60) return 'warning'
  if (n >= 40) return 'orange'
  return 'success'
}
</script>

<template>
  <VRow>
    <!-- Ribbon header -->
    <VCol cols="12">
      <VCard class="leave-ribbon">
        <VCardText class="d-flex flex-wrap align-center justify-space-between gap-4 py-6">
          <div>
            <div class="text-overline text-white opacity-90">{{ t('leave.reports_workspace') }}</div>
            <h4 class="text-h4 text-white mb-1">{{ t('leave.reports_engine') }}</h4>
            <p class="mb-0 text-white opacity-90">
              11 specialised reports — balances, taken leave, transactions, allowances, payouts, pending, cancelled, violations, AI anomalies, burnout risk, and department trends.
            </p>
          </div>
          <div class="d-flex flex-column align-end gap-2">
            <VChip color="white" class="text-primary font-weight-bold" label>
              {{ activeRows.length }} Active Rows
            </VChip>
            <VBtn variant="outlined" color="white" size="small" prepend-icon="tabler-chart-bar" href="/leave-management/analytics">
              BI Analytics
            </VBtn>
          </div>
        </VCardText>
      </VCard>
    </VCol>

    <!-- Filters -->
    <VCol cols="12">
      <VCard :title="t('leave.report_filters')">
        <VCardText>
          <VRow>
            <!-- Employee dropdown (multi-select autocomplete) -->
            <VCol cols="12" sm="6" md="3">
              <VAutocomplete
                v-model="filters.employeeIds"
                :items="employees"
                item-title="name"
                item-value="id"
                :label="t('leave.filter_employee')"
                prepend-inner-icon="tabler-account-search"
                multiple
                chips
                closable-chips
                clearable
                density="comfortable"
                placeholder="All active employees"
              />
            </VCol>

            <!-- Department multi-select -->
            <VCol cols="12" sm="6" md="2">
              <VAutocomplete
                v-model="filters.departments"
                :items="departments"
                item-title="name"
                item-value="id"
                :label="t('leave.filter_department')"
                prepend-inner-icon="tabler-office-building-outline"
                multiple
                chips
                closable-chips
                clearable
                density="comfortable"
                placeholder="All departments"
              />
            </VCol>

            <!-- Leave type -->
            <VCol cols="12" sm="6" md="2">
              <VSelect
                v-model="filters.leaveTypeId"
                :items="leaveTypes"
                item-title="name"
                item-value="id"
                :label="t('leave.leave_type')"
                clearable
                density="comfortable"
              />
            </VCol>

            <!-- Status -->
            <VCol cols="12" sm="6" md="1">
              <VSelect
                v-model="filters.status"
                :items="['pending', 'approved', 'rejected', 'cancelled', 'open', 'resolved']"
                :label="t('leave.status')"
                clearable
                density="comfortable"
              />
            </VCol>

            <!-- Date range — native inputs bypass Vuetify's click-intercepting overlay -->
            <VCol cols="6" sm="3" md="2">
              <div class="native-date-field">
                <span class="native-date-field__label">{{ t('leave.filter_from') }}</span>
                <input v-model="filters.dateFrom" type="date" class="native-date-field__input" />
              </div>
            </VCol>
            <VCol cols="6" sm="3" md="2">
              <div class="native-date-field">
                <span class="native-date-field__label">{{ t('leave.filter_to') }}</span>
                <input v-model="filters.dateTo" type="date" class="native-date-field__input" />
              </div>
            </VCol>
          </VRow>

          <!-- Active filter chips summary -->
          <div v-if="filters.employeeIds.length || filters.departments.length" class="d-flex flex-wrap gap-1 mt-1 mb-2">
            <VChip
              v-for="id in filters.employeeIds"
              :key="`emp-${id}`"
              size="small"
              closable
              @click:close="filters.employeeIds = filters.employeeIds.filter(x => x !== id)"
            >
              {{ employees.find(e => e.id === id)?.name ?? id }}
            </VChip>
            <VChip
              v-for="id in filters.departments"
              :key="`dept-${id}`"
              size="small"
              color="primary"
              closable
              @click:close="filters.departments = filters.departments.filter(x => x !== id)"
            >
              {{ departments.find(d => String(d.id) === String(id))?.name ?? id }}
            </VChip>
          </div>

          <div class="d-flex justify-end">
            <VBtn variant="tonal" color="secondary" prepend-icon="tabler-filter-remove" @click="clearFilters">
              {{ t('leave.clear_filters') }}
            </VBtn>
          </div>
        </VCardText>
      </VCard>
    </VCol>

    <!-- Report cards -->
    <VCol cols="12">
      <VCard :title="t('leave.reports_engine')">
        <VCardText>
          <VRow>
            <VCol v-for="report in reportCards" :key="report.key" cols="12" md="6">
              <VCard variant="outlined" class="h-100">
                <VCardText>
                  <div class="d-flex align-center gap-2 mb-2">
                    <VIcon :icon="report.icon" :color="report.color" size="20" />
                    <span class="text-subtitle-1 font-weight-medium">{{ report.title }}</span>
                  </div>
                  <div class="text-body-2 text-medium-emphasis mb-4">{{ report.description }}</div>
                  <div class="d-flex flex-wrap gap-2">
                    <VBtn size="small" color="primary" prepend-icon="tabler-play" @click="generateReport(report.key)">
                      {{ t('leave.generate') }}
                    </VBtn>
                    <VBtn size="small" variant="tonal" prepend-icon="tabler-table" @click="switchToReport(report.key)">
                      {{ t('leave.open_table') }}
                    </VBtn>
                    <VMenu>
                      <template #activator="{ props: menuProps }">
                        <VBtn v-bind="menuProps" size="small" color="secondary" variant="tonal" prepend-icon="tabler-export">
                          {{ t('leave.export') }}
                        </VBtn>
                      </template>
                      <VList>
                        <VListItem title="Export Excel (XLSX)"    @click="exportReport(report.key, 'excel')" />
                        <VListItem title="Export PDF"             @click="exportReport(report.key, 'pdf')" />
                        <VListItem title="Queue (large / email)"  @click="queueReport(report.key)" />
                      </VList>
                    </VMenu>
                  </div>
                </VCardText>
              </VCard>
            </VCol>
          </VRow>
        </VCardText>
      </VCard>
    </VCol>

    <!-- Data table -->
    <VCol cols="12">
      <VCard>
        <VTabs v-model="activeTab" show-arrows>
          <VTab value="balance">     Balance      </VTab>
          <VTab value="taken">       Taken        </VTab>
          <VTab value="transaction"> Transactions </VTab>
          <VTab value="allowance">   Allowances   </VTab>
          <VTab value="payout">      Paid-Out     </VTab>
          <VTab value="pending">     Pending      </VTab>
          <VTab value="cancelled">   Cancelled    </VTab>
          <VTab value="violation">   Violations   </VTab>
          <VTab value="anomaly">     AI Anomalies </VTab>
          <VTab value="burnout">     Burnout Risk </VTab>
          <VTab value="trend">       Dept Trends  </VTab>
        </VTabs>

        <VDivider />

        <VCardText class="d-flex gap-2 flex-wrap align-center">
          <VBtn color="primary"    prepend-icon="tabler-play"              @click="generateReport(activeTab)">{{ t('leave.generate') }}</VBtn>
          <VBtn color="success"    prepend-icon="tabler-file-delimited"    variant="tonal" @click="exportReport(activeTab, 'excel')">{{ t('leave.export_excel') }}</VBtn>
          <VBtn color="error"      prepend-icon="tabler-file-pdf-box"      variant="tonal" @click="exportReport(activeTab, 'pdf')">{{ t('leave.export_pdf') }}</VBtn>
          <VBtn color="secondary"  prepend-icon="tabler-email-fast-outline" variant="tonal" :loading="queueLoading" @click="queueReport(activeTab)">{{ t('leave.queue_email') }}</VBtn>

          <!-- Balance-as-of date picker — only on balance tab when feature is enabled -->
          <template v-if="activeTab === 'balance' && balanceAsOfEnabled">
            <VDivider vertical class="mx-1" />
            <div class="native-date-field" style="min-width:190px">
              <span class="native-date-field__label d-flex align-center gap-1">
                <VProgressCircular v-if="balanceAsOfLoading" size="10" width="2" indeterminate />
                Balance as of date
              </span>
              <input
                :value="balanceAsOfDate"
                type="date"
                class="native-date-field__input"
                @change="onBalanceAsOfDateChange(($event.target as HTMLInputElement).value || null)"
              />
            </div>
            <VBtn
              v-if="balanceAsOfDate"
              icon="tabler-close"
              size="small"
              variant="text"
              @click="onBalanceAsOfDateChange(null)"
            />
            <VChip v-if="balanceAsOfDate" color="info" size="small" label>
              Showing balance as of {{ formatDate(balanceAsOfDate) }}
            </VChip>
          </template>
        </VCardText>

        <VDataTable
          :headers="activeHeaders"
          :items="activeRows"
          :items-per-page="15"
          height="640"
          fixed-header
        >
          <!-- Date columns — format ISO "YYYY-MM-DD" to "DD Mon YYYY" -->
          <template #item.start_date="{ item }">{{ formatDate(item.start_date) }}</template>
          <template #item.end_date="{ item }">{{ formatDate(item.end_date) }}</template>
          <template #item.created_at="{ item }">{{ formatDate(item.created_at) }}</template>
          <template #item.detected_at="{ item }">{{ formatDate(item.detected_at) }}</template>
          <template #item.flagged_at="{ item }">{{ formatDate(item.flagged_at) }}</template>
          <template #item.allocated_at="{ item }">{{ formatDate(item.allocated_at) }}</template>
          <template #item.payout_date="{ item }">{{ formatDate(item.payout_date) }}</template>

          <!-- Status chip -->
          <template #item.status="{ item }">
            <VChip size="small" variant="tonal"
              :color="['approved', 'resolved'].includes(String(item.status)) ? 'success'
                    : ['rejected', 'cancelled'].includes(String(item.status)) ? 'error'
                    : 'warning'"
            >
              {{ item.status }}
            </VChip>
          </template>

          <!-- Severity chip -->
          <template #item.severity="{ item }">
            <VChip size="small" variant="tonal" :color="severityColor(item.severity)">
              {{ item.severity }}
            </VChip>
          </template>

          <!-- Risk score chip -->
          <template #item.risk_score="{ item }">
            <VChip size="small" variant="tonal" :color="riskColor(item.risk_score)">
              {{ item.risk_score }}
            </VChip>
          </template>

          <!-- Transaction type chip -->
          <template #item.transaction_type="{ item }">
            <VChip size="small" variant="tonal" :color="item.transaction_type === 'credit' ? 'success' : 'error'">
              {{ item.transaction_type }}
            </VChip>
          </template>

          <!-- Negative balance -->
          <template #item.allow_negative_balance="{ item }">
            <VIcon
              :icon="item.allow_negative_balance ? 'tabler-check-circle' : 'tabler-close-circle'"
              :color="item.allow_negative_balance ? 'warning' : 'secondary'"
              size="18"
            />
          </template>

          <!-- Reviewed -->
          <template #item.reviewed="{ item }">
            <VChip size="small" variant="tonal" :color="item.reviewed ? 'success' : 'warning'">
              {{ item.reviewed ? 'Yes' : 'No' }}
            </VChip>
          </template>

          <!-- Action taken -->
          <template #item.action_taken="{ item }">
            <VChip v-if="item.action_taken" size="small" variant="tonal" color="info">
              {{ item.action_taken }}
            </VChip>
            <span v-else class="text-medium-emphasis">—</span>
          </template>

          <!-- ── Column totals footer ─────────────────────────────────────── -->
          <template v-if="Object.keys(columnTotals).length" #tfoot>
            <tr class="totals-row">
              <td
                v-for="(header, idx) in activeHeaders"
                :key="header.key"
                class="pa-2 text-caption"
              >
                <template v-if="idx === 0">
                  <span class="font-weight-bold text-primary text-uppercase">
                    Total ({{ activeRows.length }})
                  </span>
                </template>
                <template v-else-if="columnTotals[header.key] !== undefined">
                  <span class="font-weight-bold">
                    {{ fmtTotal(header.key, columnTotals[header.key]) }}
                  </span>
                </template>
              </td>
            </tr>
          </template>
        </VDataTable>

        <!-- Totals summary strip — always visible even before scrolling -->
        <div
          v-if="Object.keys(columnTotals).length"
          class="d-flex flex-wrap align-center gap-x-6 gap-y-1 px-4 py-3 totals-strip"
        >
          <VIcon icon="tabler-sigma" size="16" color="primary" />
          <span class="text-caption font-weight-bold text-primary text-uppercase me-2">
            {{ activeRows.length }} row{{ activeRows.length !== 1 ? 's' : '' }}
          </span>
          <template v-for="header in activeHeaders" :key="header.key">
            <div v-if="columnTotals[header.key] !== undefined" class="d-flex align-center gap-1">
              <span class="text-caption text-medium-emphasis">{{ header.title }}:</span>
              <strong class="text-caption text-high-emphasis">
                {{ fmtTotal(header.key, columnTotals[header.key]) }}
              </strong>
            </div>
          </template>
        </div>

      </VCard>
    </VCol>

    <VSnackbar v-model="toast.show" :color="toast.color" timeout="2500" location="top right">
      {{ toast.text }}
    </VSnackbar>
  </VRow>
</template>

<style scoped lang="scss">
.leave-ribbon {
  border: 0;
  overflow: hidden;
  background: linear-gradient(135deg, #4a148c 0%, #7367f0 100%);
}

:deep(.v-data-table-header__content) {
  font-weight: 700;
}

// Sticky totals row — stays visible at the bottom of the scrollable table area
:deep(.totals-row) {
  position: sticky;
  bottom: 0;
  z-index: 2;

  td {
    background-color: rgb(var(--v-theme-surface));
    border-top: 2px solid rgb(var(--v-theme-primary));
    padding: 8px 12px;
  }
}

// Always-visible summary strip below the table
.totals-strip {
  border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  background-color: rgba(var(--v-theme-primary), 0.04);
}

// Native date inputs — styled to match Vuetify outlined density="comfortable"
// Using native <input type="date"> bypasses Vuetify's click-intercepting overlay layer
.native-date-field {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 4px;
  padding: 6px 12px 7px;
  cursor: pointer;
  transition: border-color 0.15s;
  min-height: 48px;
  display: flex;
  flex-direction: column;
  justify-content: center;

  &:hover {
    border-color: rgba(var(--v-theme-on-surface), 0.87);
  }

  &:focus-within {
    border-color: rgb(var(--v-theme-primary));
    border-width: 2px;
    padding: 5px 11px 6px; // compensate for extra border width
  }

  &__label {
    display: block;
    font-size: 0.75rem;
    line-height: 1;
    color: rgba(var(--v-theme-on-surface), 0.6);
    margin-bottom: 3px;
  }

  &__input {
    border: none;
    outline: none;
    background: transparent;
    color: rgb(var(--v-theme-on-surface));
    font-size: 0.875rem;
    width: 100%;
    cursor: pointer;
    font-family: inherit;

    // Ensure calendar icon is always visible and clickable
    &::-webkit-calendar-picker-indicator {
      cursor: pointer;
      opacity: 0.7;

      &:hover {
        opacity: 1;
      }
    }
  }
}
</style>
