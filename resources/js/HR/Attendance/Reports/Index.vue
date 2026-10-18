<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type ReportRow = Record<string, unknown>

interface EmployeeOption   { id: number; name: string }
interface DepartmentOption { id: string; name: string }

interface Props {
  lateArrivals:       ReportRow[]
  overtimeRows:       ReportRow[]
  earlyDepartures:    ReportRow[]
  clockingLog:        ReportRow[]
  attendanceRate:     ReportRow[]
  deptSummary:        ReportRow[]
  wellnessReport:     ReportRow[]
  productivityReport: ReportRow[]
  policyViolations:   ReportRow[]
  disciplineCases:    ReportRow[]
  employees:          EmployeeOption[]
  departments:        DepartmentOption[]
  filters:            { date_from: string; date_to: string }
}

const props = withDefaults(defineProps<Props>(), {
  lateArrivals:       () => [],
  overtimeRows:       () => [],
  earlyDepartures:    () => [],
  clockingLog:        () => [],
  attendanceRate:     () => [],
  deptSummary:        () => [],
  wellnessReport:     () => [],
  productivityReport: () => [],
  policyViolations:   () => [],
  disciplineCases:    () => [],
  employees:          () => [],
  departments:        () => [],
  filters:            () => ({ date_from: '', date_to: '' }),
})

const activeTab   = ref('late_arrivals')
const queueLoading = ref(false)
const toast = reactive({ show: false, text: '', color: 'success' })

const filters = reactive({
  employeeIds: [] as number[],
  departments: [] as string[],
  dateFrom:    props.filters.date_from,
  dateTo:      props.filters.date_to,
})

// ── Report catalogue ─────────────────────────────────────────────────────────

const reportCards = computed(() => [
  { key: 'late_arrivals',    icon: 'tabler-alarm-check',         color: 'warning',    title: t('attendance_report.report_late_arrivals'),    description: t('attendance_report.report_late_arrivals_desc') },
  { key: 'overtime',         icon: 'tabler-clock-plus-outline',  color: 'orange',     title: t('attendance_report.report_overtime'),         description: t('attendance_report.report_overtime_desc') },
  { key: 'early_departures', icon: 'tabler-exit-run',            color: 'error',      title: t('attendance_report.report_early_departures'), description: t('attendance_report.report_early_departures_desc') },
  { key: 'clocking_log',     icon: 'tabler-fingerprint',         color: 'primary',    title: t('attendance_report.report_clocking_log'),     description: t('attendance_report.report_clocking_log_desc') },
  { key: 'attendance_rate',  icon: 'tabler-account-check',       color: 'success',    title: t('attendance_report.report_attendance_rate'),  description: t('attendance_report.report_attendance_rate_desc') },
  { key: 'dept_summary',     icon: 'tabler-office-building',     color: 'teal',       title: t('attendance_report.report_dept_summary'),     description: t('attendance_report.report_dept_summary_desc') },
  { key: 'wellness',         icon: 'tabler-heart-pulse',         color: 'pink',       title: t('attendance_report.report_wellness'),         description: t('attendance_report.report_wellness_desc') },
  { key: 'productivity',     icon: 'tabler-chart-line-variant',  color: 'indigo',     title: t('attendance_report.report_productivity'),     description: t('attendance_report.report_productivity_desc') },
  { key: 'policy_violations',icon: 'tabler-scale-balance',       color: 'deep-orange',title: t('attendance_report.report_policy_violations'),description: t('attendance_report.report_policy_violations_desc') },
  { key: 'discipline_cases', icon: 'tabler-gavel',               color: 'red',        title: t('attendance_report.report_discipline_cases'), description: t('attendance_report.report_discipline_cases_desc') },
])

// ── Table header definitions ─────────────────────────────────────────────────

const headersByReport = computed<Record<string, { title: string; key: string }[]>>(() => ({
  late_arrivals:    [{ title: t('attendance_report.table_employee'), key: 'employee_name' }, { title: t('attendance_report.table_department'), key: 'department' }, { title: t('attendance_report.table_date'), key: 'date' }, { title: t('attendance_report.table_clock_in'), key: 'clock_in' }, { title: t('attendance_report.table_late_min'), key: 'late_minutes' }, { title: t('attendance_report.table_shift'), key: 'shift' }],
  overtime:         [{ title: t('attendance_report.table_employee'), key: 'employee_name' }, { title: t('attendance_report.table_department'), key: 'department' }, { title: t('attendance_report.table_date'), key: 'date' }, { title: t('attendance_report.table_hours_worked'), key: 'hours_worked' }, { title: t('attendance_report.table_overtime_h'), key: 'overtime_hours' }, { title: t('attendance_report.table_approved'), key: 'approved' }],
  early_departures: [{ title: t('attendance_report.table_employee'), key: 'employee_name' }, { title: t('attendance_report.table_department'), key: 'department' }, { title: t('attendance_report.table_date'), key: 'date' }, { title: t('attendance_report.table_clock_out'), key: 'clock_out' }, { title: t('attendance_report.table_hours_worked'), key: 'hours_worked' }],
  clocking_log:     [{ title: t('attendance_report.table_employee'), key: 'employee_name' }, { title: t('attendance_report.table_department'), key: 'department' }, { title: t('attendance_report.table_type'), key: 'type' }, { title: t('attendance_report.table_method'), key: 'method' }, { title: t('attendance_report.table_work_type'), key: 'work_type' }, { title: t('attendance_report.table_clocked_at'), key: 'clocked_at' }, { title: t('attendance_report.table_location'), key: 'location' }, { title: t('attendance_report.table_missed'), key: 'is_missed' }],
  attendance_rate:  [{ title: t('attendance_report.table_employee'), key: 'employee_name' }, { title: t('attendance_report.table_department'), key: 'department' }, { title: t('attendance_report.table_total_days'), key: 'total_days' }, { title: t('attendance_report.table_present'), key: 'days_present' }, { title: t('attendance_report.table_late'), key: 'late_count' }, { title: t('attendance_report.table_rate_pct'), key: 'attendance_rate' }, { title: t('attendance_report.table_total_hours'), key: 'total_hours' }, { title: t('attendance_report.table_total_overtime'), key: 'total_overtime' }],
  dept_summary:     [{ title: t('attendance_report.table_department'), key: 'department' }, { title: t('attendance_report.table_headcount'), key: 'headcount' }, { title: t('attendance_report.table_records'), key: 'total_records' }, { title: t('attendance_report.table_total_late'), key: 'total_late' }, { title: t('attendance_report.table_avg_hours'), key: 'avg_hours' }, { title: t('attendance_report.table_total_ot'), key: 'total_overtime' }],
  wellness:         [{ title: t('attendance_report.table_employee'), key: 'employee_name' }, { title: t('attendance_report.table_department'), key: 'department' }, { title: t('attendance_report.table_wellness_score'), key: 'wellness_score' }, { title: t('attendance_report.table_burnout_risk'), key: 'burnout_risk' }, { title: t('attendance_report.table_fatigue'), key: 'fatigue_level' }, { title: t('attendance_report.table_ot_hours'), key: 'total_overtime_h' }, { title: t('attendance_report.table_absent_pct'), key: 'absenteeism_pct' }],
  productivity:     [{ title: t('attendance_report.table_employee'), key: 'employee_name' }, { title: t('attendance_report.table_department'), key: 'department' }, { title: t('attendance_report.table_score'), key: 'overall_score' }, { title: t('attendance_report.table_tier'), key: 'tier' }, { title: t('attendance_report.table_attendance_pct'), key: 'attendance_rate' }, { title: t('attendance_report.table_late'), key: 'late_count' }, { title: t('attendance_report.table_early'), key: 'early_count' }],
  policy_violations:[{ title: t('attendance_report.table_employee'), key: 'employee_name' }, { title: t('attendance_report.table_department'), key: 'department' }, { title: t('attendance_report.table_rule'), key: 'rule' }, { title: t('attendance_report.table_category'), key: 'category' }, { title: t('attendance_report.table_severity'), key: 'severity' }, { title: t('attendance_report.table_status'), key: 'status' }, { title: t('attendance_report.table_blocked'), key: 'blocked' }, { title: t('attendance_report.table_date'), key: 'created_at' }],
  discipline_cases: [{ title: t('attendance_report.table_employee'), key: 'employee_name' }, { title: t('attendance_report.table_department'), key: 'department' }, { title: t('attendance_report.table_case_no'), key: 'case_number' }, { title: t('attendance_report.table_case_type'), key: 'case_type' }, { title: t('attendance_report.table_severity'), key: 'severity' }, { title: t('attendance_report.table_status'), key: 'status' }, { title: t('attendance_report.table_period'), key: 'period_start' }],
}))

const reportDataMap = computed<Record<string, ReportRow[]>>(() => ({
  late_arrivals:    props.lateArrivals,
  overtime:         props.overtimeRows,
  early_departures: props.earlyDepartures,
  clocking_log:     props.clockingLog,
  attendance_rate:  props.attendanceRate,
  dept_summary:     props.deptSummary,
  wellness:         props.wellnessReport,
  productivity:     props.productivityReport,
  policy_violations:props.policyViolations,
  discipline_cases: props.disciplineCases,
}))

// ── Client-side filtering ────────────────────────────────────────────────────

function applyFilters(rows: ReportRow[]): ReportRow[] {
  let next = [...rows]
  if (filters.employeeIds.length) {
    const names = new Set(filters.employeeIds.map(id => props.employees.find(e => e.id === id)?.name ?? '').filter(Boolean))
    next = next.filter(r => names.has(String(r.employee_name ?? '')))
  }
  if (filters.departments.length) {
    const depts = new Set(filters.departments)
    next = next.filter(r => !r.department || depts.has(String(r.department)))
  }
  return next
}

function reportRows(key: string): ReportRow[] { return applyFilters(reportDataMap.value[key] ?? []) }

const activeRows    = computed(() => reportRows(activeTab.value))
const activeHeaders = computed(() => headersByReport.value[activeTab.value] ?? [])

// ── Column totals ────────────────────────────────────────────────────────────

const sumColumns: Record<string, string[]> = {
  late_arrivals:   ['late_minutes'],
  overtime:        ['hours_worked', 'overtime_hours'],
  attendance_rate: ['total_days', 'days_present', 'late_count', 'total_hours', 'total_overtime'],
  dept_summary:    ['headcount', 'total_records', 'total_late', 'total_overtime'],
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
  if (colKey.endsWith('_hours') || colKey === 'overtime') return `${(Math.round(val * 10) / 10).toLocaleString('en-ZA')} h`
  if (colKey.endsWith('_minutes')) return `${Math.round(val)} min`
  return String(Math.round(val * 10) / 10)
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function severityColor(s: unknown) {
  return ({ critical: 'error', high: 'warning', medium: 'orange', low: 'info' } as Record<string, string>)[String(s)] ?? 'secondary'
}

function tierColor(t: unknown) {
  return ({ high: 'success', average: 'info', low: 'warning', at_risk: 'error' } as Record<string, string>)[String(t)] ?? 'default'
}

function riskColor(r: unknown) {
  return ({ critical: 'error', high: 'warning', medium: 'orange', low: 'success' } as Record<string, string>)[String(r)] ?? 'default'
}

// ── Actions ──────────────────────────────────────────────────────────────────

function setToast(text: string, color = 'success') { toast.text = text; toast.color = color; toast.show = true }

async function generateReport(key: string) {
  activeTab.value = key
  try {
    const res = await fetch(`/attendance/reports/generate/${key}`, { headers: { Accept: 'application/json' } })
    if (!res.ok) throw new Error()
    const json = await res.json()
    setToast(t('attendance_report.toast_generated', { count: json.count, report: key.replace(/_/g, ' ') }))
  } catch { setToast(t('attendance_report.toast_generate_failed', { report: key }), 'error') }
}

function buildQs() {
  const p = new URLSearchParams()
  if (filters.employeeIds.length) p.set('employee_ids', filters.employeeIds.join(','))
  if (filters.departments.length) p.set('departments', filters.departments.join(','))
  if (filters.dateFrom) p.set('date_from', filters.dateFrom)
  if (filters.dateTo)   p.set('date_to', filters.dateTo)
  return p.toString()
}

function exportReport(key: string, format: 'excel' | 'pdf') {
  const qs  = buildQs()
  const fmt = format === 'excel' ? 'xlsx' : 'pdf'
  window.open(`/attendance/reports/export/${key}/${fmt}${qs ? `?${qs}` : ''}`, '_blank')
  setToast(t('attendance_report.toast_export_started', { format: format.toUpperCase() }))
}

async function queueReport(key: string) {
  queueLoading.value = true
  try {
    const res = await fetch(`/attendance/reports/queue/${key}`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
      },
      body: JSON.stringify({}),
    })
    if (!res.ok) throw new Error()
    setToast(t('attendance_report.toast_queued', { report: key.replace(/_/g, ' ') }))
  } catch { setToast(t('attendance_report.toast_queue_failed', { report: key }), 'error') }
  finally { queueLoading.value = false }
}

function clearFilters() { filters.employeeIds = []; filters.departments = [] }
</script>

<template>
  <VRow>
    <!-- Header ribbon -->
    <VCol cols="12">
      <VCard class="attendance-ribbon">
        <VCardText class="d-flex flex-wrap align-center justify-space-between gap-4 py-6">
          <div>
            <div class="text-overline text-white opacity-90">{{ t('attendance_report.breadcrumb_module') }}</div>
            <h4 class="text-h4 text-white mb-1">{{ t('attendance_report.title') }}</h4>
            <p class="mb-0 text-white opacity-90">
              {{ t('attendance_report.subtitle') }}
            </p>
          </div>
          <div class="d-flex flex-column align-end gap-2">
            <VChip color="white" class="text-primary font-weight-bold" label>
              {{ activeRows.length }} {{ t('attendance_report.active_rows') }}
            </VChip>
            <VBtn variant="outlined" color="white" size="small" prepend-icon="tabler-chart-bar" href="/attendance/analytics">
              {{ t('attendance_report.bi_analytics') }}
            </VBtn>
          </div>
        </VCardText>
      </VCard>
    </VCol>

    <!-- Filters -->
    <VCol cols="12">
      <VCard :title="t('attendance_report.filter_title')">
        <VCardText>
          <VRow>
            <VCol cols="12" sm="6" md="4">
              <VAutocomplete
                v-model="filters.employeeIds"
                :items="employees"
                item-title="name"
                item-value="id"
                :label="t('attendance_report.filter_employees')"
                prepend-inner-icon="tabler-account-search"
                multiple chips closable-chips clearable
                density="comfortable"
                :placeholder="t('attendance_report.filter_employees_placeholder')"
              />
            </VCol>
            <VCol cols="12" sm="6" md="3">
              <VAutocomplete
                v-model="filters.departments"
                :items="departments"
                item-title="name"
                item-value="id"
                :label="t('attendance_report.filter_departments')"
                prepend-inner-icon="tabler-office-building-outline"
                multiple chips closable-chips clearable
                density="comfortable"
                :placeholder="t('attendance_report.filter_departments_placeholder')"
              />
            </VCol>
            <VCol cols="6" sm="3" md="2">
              <div class="native-date-field">
                <span class="native-date-field__label">{{ t('attendance_report.filter_from') }}</span>
                <input v-model="filters.dateFrom" type="date" class="native-date-field__input" />
              </div>
            </VCol>
            <VCol cols="6" sm="3" md="2">
              <div class="native-date-field">
                <span class="native-date-field__label">{{ t('attendance_report.filter_to') }}</span>
                <input v-model="filters.dateTo" type="date" class="native-date-field__input" />
              </div>
            </VCol>
            <VCol cols="12" md="1" class="d-flex align-center">
              <VBtn variant="tonal" color="secondary" icon="tabler-filter-remove" @click="clearFilters" />
            </VCol>
          </VRow>
        </VCardText>
      </VCard>
    </VCol>

    <!-- Report cards grid -->
    <VCol cols="12">
      <VCard :title="t('attendance_report.available_reports')">
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
                      {{ t('attendance_report.generate') }}
                    </VBtn>
                    <VBtn size="small" variant="tonal" prepend-icon="tabler-table" @click="activeTab = report.key">
                      {{ t('attendance_report.open_table') }}
                    </VBtn>
                    <VMenu>
                      <template #activator="{ props: menuProps }">
                        <VBtn v-bind="menuProps" size="small" color="secondary" variant="tonal" prepend-icon="tabler-export">
                          {{ t('attendance_report.export') }}
                        </VBtn>
                      </template>
                      <VList>
                        <VListItem :title="t('attendance_report.export_excel')" @click="exportReport(report.key, 'excel')" />
                        <VListItem :title="t('attendance_report.export_pdf')" @click="exportReport(report.key, 'pdf')" />
                        <VListItem :title="t('attendance_report.queue_email')" @click="queueReport(report.key)" />
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
          <VTab value="late_arrivals">{{ t('attendance_report.tab_late_arrivals') }}</VTab>
          <VTab value="overtime">{{ t('attendance_report.tab_overtime') }}</VTab>
          <VTab value="early_departures">{{ t('attendance_report.tab_early_departures') }}</VTab>
          <VTab value="clocking_log">{{ t('attendance_report.tab_clocking_log') }}</VTab>
          <VTab value="attendance_rate">{{ t('attendance_report.tab_attendance_rate') }}</VTab>
          <VTab value="dept_summary">{{ t('attendance_report.tab_dept_summary') }}</VTab>
          <VTab value="wellness">{{ t('attendance_report.tab_wellness') }}</VTab>
          <VTab value="productivity">{{ t('attendance_report.tab_productivity') }}</VTab>
          <VTab value="policy_violations">{{ t('attendance_report.tab_policy_violations') }}</VTab>
          <VTab value="discipline_cases">{{ t('attendance_report.tab_discipline_cases') }}</VTab>
        </VTabs>
        <VDivider />

        <VCardText class="d-flex gap-2 flex-wrap align-center">
          <VBtn color="primary"   prepend-icon="tabler-play"              @click="generateReport(activeTab)">{{ t('attendance_report.generate') }}</VBtn>
          <VBtn color="success"   prepend-icon="tabler-file-delimited"    variant="tonal" @click="exportReport(activeTab, 'excel')">{{ t('attendance_report.export_excel_btn') }}</VBtn>
          <VBtn color="error"     prepend-icon="tabler-file-pdf-box"      variant="tonal" @click="exportReport(activeTab, 'pdf')">{{ t('attendance_report.export_pdf_btn') }}</VBtn>
          <VBtn color="secondary" prepend-icon="tabler-email-fast-outline" variant="tonal" :loading="queueLoading" @click="queueReport(activeTab)">{{ t('attendance_report.queue_email_btn') }}</VBtn>
        </VCardText>

        <VDataTable
          :headers="activeHeaders"
          :items="activeRows"
          :items-per-page="15"
          height="640"
          fixed-header
        >
          <!-- Severity chip -->
          <template #item.severity="{ item }">
            <VChip size="small" variant="tonal" :color="severityColor(item.severity)">{{ item.severity }}</VChip>
          </template>

          <!-- Status chip -->
          <template #item.status="{ item }">
            <VChip size="small" variant="tonal"
              :color="['resolved', 'closed'].includes(String(item.status)) ? 'success' : ['escalated', 'open'].includes(String(item.status)) ? 'error' : 'warning'"
            >{{ item.status }}</VChip>
          </template>

          <!-- Productivity tier chip -->
          <template #item.tier="{ item }">
            <VChip size="small" variant="tonal" :color="tierColor(item.tier)">{{ item.tier }}</VChip>
          </template>

          <!-- Burnout risk chip -->
          <template #item.burnout_risk="{ item }">
            <VChip size="small" variant="tonal" :color="riskColor(item.burnout_risk)">{{ item.burnout_risk }}</VChip>
          </template>

          <!-- Wellness score bar -->
          <template #item.wellness_score="{ item }">
            <div class="d-flex align-center gap-2" style="min-width: 100px">
              <VProgressLinear :model-value="Number(item.wellness_score)" :color="Number(item.wellness_score) >= 70 ? 'success' : Number(item.wellness_score) >= 50 ? 'warning' : 'error'" height="6" rounded />
              <span class="text-caption">{{ item.wellness_score }}</span>
            </div>
          </template>

          <!-- Productivity score bar -->
          <template #item.overall_score="{ item }">
            <div class="d-flex align-center gap-2" style="min-width: 100px">
              <VProgressLinear :model-value="Number(item.overall_score)" :color="Number(item.overall_score) >= 80 ? 'success' : Number(item.overall_score) >= 60 ? 'info' : Number(item.overall_score) >= 40 ? 'warning' : 'error'" height="6" rounded />
              <span class="text-caption">{{ item.overall_score }}</span>
            </div>
          </template>

          <!-- Blocked flag -->
          <template #item.blocked="{ item }">
            <VChip v-if="item.blocked === 'Yes'" size="small" color="error" variant="tonal">{{ t('attendance_report.blocked_chip') }}</VChip>
            <span v-else class="text-medium-emphasis">—</span>
          </template>

          <!-- Approved flag -->
          <template #item.approved="{ item }">
            <VChip size="small" variant="tonal" :color="item.approved === 'Yes' ? 'success' : 'default'">{{ item.approved }}</VChip>
          </template>

          <!-- Column totals footer -->
          <template v-if="Object.keys(columnTotals).length" #tfoot>
            <tr class="totals-row">
              <td v-for="(header, idx) in activeHeaders" :key="header.key" class="pa-2 text-caption">
                <template v-if="idx === 0">
                  <span class="font-weight-bold text-primary text-uppercase">{{ t('attendance_report.total_label', { count: activeRows.length }) }}</span>
                </template>
                <template v-else-if="columnTotals[header.key] !== undefined">
                  <span class="font-weight-bold">{{ fmtTotal(header.key, columnTotals[header.key]) }}</span>
                </template>
              </td>
            </tr>
          </template>
        </VDataTable>

        <!-- Totals strip -->
        <div v-if="Object.keys(columnTotals).length" class="d-flex flex-wrap align-center gap-x-6 gap-y-1 px-4 py-3 totals-strip">
          <VIcon icon="tabler-sigma" size="16" color="primary" />
          <span class="text-caption font-weight-bold text-primary text-uppercase me-2">
            {{ t('attendance_report.rows_label', { count: activeRows.length }) }}
          </span>
          <template v-for="header in activeHeaders" :key="header.key">
            <div v-if="columnTotals[header.key] !== undefined" class="d-flex align-center gap-1">
              <span class="text-caption text-medium-emphasis">{{ header.title }}:</span>
              <strong class="text-caption">{{ fmtTotal(header.key, columnTotals[header.key]) }}</strong>
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
.attendance-ribbon {
  border: 0;
  overflow: hidden;
  background: linear-gradient(135deg, #0d47a1 0%, #1976d2 100%);
}
:deep(.v-data-table-header__content) { font-weight: 700; }
:deep(.totals-row) {
  position: sticky; bottom: 0; z-index: 2;
  td { background-color: rgb(var(--v-theme-surface)); border-top: 2px solid rgb(var(--v-theme-primary)); padding: 8px 12px; }
}
.totals-strip {
  border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  background-color: rgba(var(--v-theme-primary), 0.04);
}
.native-date-field {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 4px; padding: 6px 12px 7px; min-height: 48px;
  display: flex; flex-direction: column; justify-content: center;
  &:hover { border-color: rgba(var(--v-theme-on-surface), 0.87); }
  &:focus-within { border-color: rgb(var(--v-theme-primary)); border-width: 2px; padding: 5px 11px 6px; }
  &__label { display: block; font-size: 0.75rem; line-height: 1; color: rgba(var(--v-theme-on-surface), 0.6); margin-bottom: 3px; }
  &__input { border: none; outline: none; background: transparent; color: rgb(var(--v-theme-on-surface)); font-size: 0.875rem; width: 100%; font-family: inherit;
    &::-webkit-calendar-picker-indicator { cursor: pointer; opacity: 0.7; &:hover { opacity: 1; } }
  }
}
</style>
