<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type ReportRow = Record<string, unknown>

interface CycleOption      { id: number; name: string }
interface DeptOption       { id: number | string; name: string }
interface EmployeeOption   { id: number; name: string }

interface Props {
  distribution:    ReportRow[]
  highPerformers:  ReportRow[]
  lowPerformers:   ReportRow[]
  pip:             ReportRow[]
  promotionReady:  ReportRow[]
  burnoutRisk:     ReportRow[]
  deptScores:      ReportRow[]
  bonus:           ReportRow[]
  calibrationRisk: ReportRow[]
  policyViolations:ReportRow[]
  kpiCompletion:   ReportRow[]
  feedbackSummary: ReportRow[]
  pipOutcomes:     ReportRow[]
  probation:       ReportRow[]
  cycles:          CycleOption[]
  departments:     DeptOption[]
  employees:       EmployeeOption[]
}

const props = withDefaults(defineProps<Props>(), {
  distribution:    () => [],
  highPerformers:  () => [],
  lowPerformers:   () => [],
  pip:             () => [],
  promotionReady:  () => [],
  burnoutRisk:     () => [],
  deptScores:      () => [],
  bonus:           () => [],
  calibrationRisk: () => [],
  policyViolations:() => [],
  kpiCompletion:   () => [],
  feedbackSummary: () => [],
  pipOutcomes:     () => [],
  probation:       () => [],
  cycles:          () => [],
  departments:     () => [],
  employees:       () => [],
})

const activeTab    = ref('distribution')
const queueLoading = ref(false)
const toast        = reactive({ show: false, text: '', color: 'success' })

const filters = reactive({
  cycleId:     null as number | null,
  departments: [] as (number | string)[],
  employeeIds: [] as number[],
  dateFrom:    null as string | null,
  dateTo:      null as string | null,
})

// ── Report cards ──────────────────────────────────────────────────────────────
const reportCards = [
  { key: 'distribution',     icon: 'tabler-chart-line',        color: 'primary',    title: t('pa_reports.cards.distribution.title'),     description: t('pa_reports.cards.distribution.description') },
  { key: 'high_performers',  icon: 'tabler-star',             color: 'success',    title: t('pa_reports.cards.high_performers.title'),   description: t('pa_reports.cards.high_performers.description') },
  { key: 'low_performers',   icon: 'tabler-alert-circle',    color: 'error',      title: t('pa_reports.cards.low_performers.title'),    description: t('pa_reports.cards.low_performers.description') },
  { key: 'pip',              icon: 'tabler-clipboard-list',  color: 'warning',    title: t('pa_reports.cards.pip.title'),               description: t('pa_reports.cards.pip.description') },
  { key: 'promotion_ready',  icon: 'tabler-user-up',        color: 'teal',       title: t('pa_reports.cards.promotion_ready.title'),   description: t('pa_reports.cards.promotion_ready.description') },
  { key: 'burnout_risk',     icon: 'tabler-flame',              color: 'red',        title: t('pa_reports.cards.burnout_risk.title'),      description: t('pa_reports.cards.burnout_risk.description') },
  { key: 'dept_scores',      icon: 'tabler-building', color: 'indigo',     title: t('pa_reports.cards.dept_scores.title'),       description: t('pa_reports.cards.dept_scores.description') },
  { key: 'bonus',            icon: 'tabler-cash',           color: 'orange',     title: t('pa_reports.cards.bonus.title'),             description: t('pa_reports.cards.bonus.description') },
  { key: 'calibration_risk', icon: 'tabler-scale',        color: 'deep-purple', title: t('pa_reports.cards.calibration_risk.title'),  description: t('pa_reports.cards.calibration_risk.description') },
  { key: 'policy_violations',icon: 'tabler-shield-exclamation', color: 'red-darken-3', title: t('pa_reports.cards.policy_violations.title'), description: t('pa_reports.cards.policy_violations.description') },
  { key: 'kpi_completion',   icon: 'tabler-circle-check',    color: 'cyan',       title: t('pa_reports.cards.kpi_completion.title'),    description: t('pa_reports.cards.kpi_completion.description') },
  { key: 'feedback_summary', icon: 'tabler-message-dots',    color: 'blue-grey',  title: t('pa_reports.cards.feedback_summary.title'),  description: t('pa_reports.cards.feedback_summary.description') },
  { key: 'pip_outcomes',     icon: 'tabler-progress-check',  color: 'green',      title: t('pa_reports.cards.pip_outcomes.title'),      description: t('pa_reports.cards.pip_outcomes.description') },
  { key: 'probation',        icon: 'tabler-user-clock',   color: 'brown',      title: t('pa_reports.cards.probation.title'),         description: t('pa_reports.cards.probation.description') },
]

// ── Table headers ─────────────────────────────────────────────────────────────
const headersByReport: Record<string, { title: string; key: string }[]> = {
  distribution: [
    { title: t('pa_reports.table.band'),       key: 'band' },
    { title: t('pa_reports.table.count'),      key: 'count' },
    { title: t('pa_reports.table.percentage'), key: 'percentage' },
  ],
  high_performers: [
    { title: t('pa_reports.table.employee'),    key: 'employee_name' },
    { title: t('pa_reports.table.department'),  key: 'department' },
    { title: t('pa_reports.table.score'),       key: 'score' },
    { title: t('pa_reports.table.cycle'),       key: 'cycle' },
    { title: t('pa_reports.table.ranked'),      key: 'ranked_at' },
  ],
  low_performers: [
    { title: t('pa_reports.table.employee'),    key: 'employee_name' },
    { title: t('pa_reports.table.department'),  key: 'department' },
    { title: t('pa_reports.table.score'),       key: 'score' },
    { title: t('pa_reports.table.cycle'),       key: 'cycle' },
    { title: t('pa_reports.table.has_pip'),     key: 'has_pip' },
  ],
  pip: [
    { title: t('pa_reports.table.employee'),    key: 'employee_name' },
    { title: t('pa_reports.table.department'),  key: 'department' },
    { title: t('pa_reports.table.start_date'),  key: 'start_date' },
    { title: t('pa_reports.table.end_date'),    key: 'end_date' },
    { title: t('pa_reports.table.status'),      key: 'status' },
    { title: t('pa_reports.table.manager'),     key: 'manager' },
  ],
  promotion_ready: [
    { title: t('pa_reports.table.employee'),    key: 'employee_name' },
    { title: t('pa_reports.table.department'),  key: 'department' },
    { title: t('pa_reports.table.score'),       key: 'score' },
    { title: t('pa_reports.table.readiness'),   key: 'readiness_level' },
    { title: t('pa_reports.table.flagged'),     key: 'created_at' },
  ],
  burnout_risk: [
    { title: t('pa_reports.table.employee'),    key: 'employee_name' },
    { title: t('pa_reports.table.department'),  key: 'department' },
    { title: t('pa_reports.table.risk_score'),  key: 'wellness_score' },
    { title: t('pa_reports.table.signals'),     key: 'signal_count' },
    { title: t('pa_reports.table.recorded'),    key: 'created_at' },
  ],
  dept_scores: [
    { title: t('pa_reports.table.department'),  key: 'department' },
    { title: t('pa_reports.table.employees'),   key: 'employee_count' },
    { title: t('pa_reports.table.avg_score'),   key: 'avg_score' },
    { title: t('pa_reports.table.cycle'),       key: 'cycle' },
  ],
  bonus: [
    { title: t('pa_reports.table.employee'),    key: 'employee_name' },
    { title: t('pa_reports.table.department'),  key: 'department' },
    { title: t('pa_reports.table.score'),       key: 'score' },
    { title: t('pa_reports.table.cycle'),       key: 'cycle' },
  ],
  calibration_risk: [
    { title: t('pa_reports.table.employee'),           key: 'employee_name' },
    { title: t('pa_reports.table.original_score'),     key: 'original_score' },
    { title: t('pa_reports.table.calibrated_score'),   key: 'calibrated_score' },
    { title: t('pa_reports.table.adjustment'),         key: 'adjustment' },
    { title: t('pa_reports.table.session'),            key: 'session' },
    { title: t('pa_reports.table.reason'),             key: 'adjustment_reason' },
  ],
  policy_violations: [
    { title: t('pa_reports.table.employee'),    key: 'employee_name' },
    { title: t('pa_reports.table.policy'),      key: 'policy' },
    { title: t('pa_reports.table.severity'),    key: 'severity' },
    { title: t('pa_reports.table.status'),      key: 'status' },
    { title: t('pa_reports.table.detected'),    key: 'created_at' },
  ],
  kpi_completion: [
    { title: t('pa_reports.table.employee'),    key: 'employee_name' },
    { title: t('pa_reports.table.kpi'),         key: 'kpi_name' },
    { title: t('pa_reports.table.target'),      key: 'target_value' },
    { title: t('pa_reports.table.actual'),      key: 'actual_value' },
    { title: t('pa_reports.table.score'),       key: 'score' },
    { title: t('pa_reports.table.cycle'),       key: 'cycle' },
  ],
  feedback_summary: [
    { title: t('pa_reports.table.employee'),    key: 'employee_name' },
    { title: t('pa_reports.table.department'),  key: 'department' },
    { title: t('pa_reports.table.praise'),      key: 'praise_count' },
    { title: t('pa_reports.table.coaching'),    key: 'coaching_count' },
    { title: t('pa_reports.table.concerns'),    key: 'concern_count' },
    { title: t('pa_reports.table.total'),       key: 'total_count' },
  ],
  pip_outcomes: [
    { title: t('pa_reports.table.employee'),    key: 'employee_name' },
    { title: t('pa_reports.table.department'),  key: 'department' },
    { title: t('pa_reports.table.outcome'),     key: 'outcome' },
    { title: t('pa_reports.table.start_date'),  key: 'start_date' },
    { title: t('pa_reports.table.end_date'),    key: 'end_date' },
    { title: t('pa_reports.table.duration'),    key: 'duration_days' },
  ],
  probation: [
    { title: t('pa_reports.table.employee'),    key: 'employee_name' },
    { title: t('pa_reports.table.department'),  key: 'department' },
    { title: t('pa_reports.table.decision'),    key: 'decision' },
    { title: t('pa_reports.table.risk_score'),  key: 'risk_score' },
    { title: t('pa_reports.table.review_date'), key: 'review_date' },
    { title: t('pa_reports.table.end_date'),    key: 'probation_end_date' },
  ],
}

const reportDataMap = computed<Record<string, ReportRow[]>>(() => ({
  distribution:     props.distribution,
  high_performers:  props.highPerformers,
  low_performers:   props.lowPerformers,
  pip:              props.pip,
  promotion_ready:  props.promotionReady,
  burnout_risk:     props.burnoutRisk,
  dept_scores:      props.deptScores,
  bonus:            props.bonus,
  calibration_risk: props.calibrationRisk,
  policy_violations:props.policyViolations,
  kpi_completion:   props.kpiCompletion,
  feedback_summary: props.feedbackSummary,
  pip_outcomes:     props.pipOutcomes,
  probation:        props.probation,
}))

function normalizeDate(value: unknown): number | null {
  if (!value) return null
  const s   = String(value)
  const iso = /^\d{4}-\d{2}-\d{2}$/.test(s) ? Date.parse(s + 'T00:00:00') : Date.parse(s)
  return Number.isNaN(iso) ? null : iso
}

function applyFilters(rows: ReportRow[]): ReportRow[] {
  let next = [...rows]
  if (filters.employeeIds.length) {
    const names = new Set(filters.employeeIds.map(id => props.employees.find(e => e.id === id)?.name ?? '').filter(Boolean))
    next = next.filter(r => names.has(String(r.employee_name ?? '')))
  }
  if (filters.departments.length) {
    const deptNames = new Set(filters.departments.map(id => props.departments.find(d => String(d.id) === String(id))?.name ?? '').filter(Boolean))
    next = next.filter(r => !r.department || deptNames.has(String(r.department ?? '')))
  }
  const fromTs = normalizeDate(filters.dateFrom)
  const toTs   = normalizeDate(filters.dateTo)
  if (fromTs !== null || toTs !== null) {
    const dateKeys = ['start_date', 'end_date', 'created_at', 'review_date', 'ranked_at']
    next = next.filter(r => {
      for (const k of dateKeys) {
        const dt = normalizeDate(r[k])
        if (dt === null) continue
        if (fromTs !== null && dt < fromTs) return false
        if (toTs   !== null && dt > toTs)   return false
        return true
      }
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

function clearFilters() {
  filters.cycleId     = null
  filters.departments = []
  filters.employeeIds = []
  filters.dateFrom    = null
  filters.dateTo      = null
}

function setToast(text: string, color = 'success') {
  toast.text  = text
  toast.color = color
  toast.show  = true
}

function buildQs() {
  const p = new URLSearchParams()
  if (filters.cycleId)            p.set('cycle_id',    String(filters.cycleId))
  if (filters.employeeIds.length) p.set('employee_ids', filters.employeeIds.join(','))
  if (filters.departments.length) p.set('departments',  filters.departments.join(','))
  if (filters.dateFrom)           p.set('dateFrom',    filters.dateFrom)
  if (filters.dateTo)             p.set('dateTo',      filters.dateTo)
  return p.toString()
}

async function generateReport(key: string) {
  activeTab.value = key
  const qs  = buildQs()
  const url = `/performance/reports/generate/${key}${qs ? `?${qs}` : ''}`
  try {
    const res = await fetch(url, { headers: { Accept: 'application/json' } })
    if (!res.ok) throw new Error()
    const payload = await res.json()
    setToast(t('pa_reports.toast.generated', { count: payload.count, report: key }))
  } catch {
    setToast(t('pa_reports.toast.generated_error', { report: key }), 'error')
  }
}

async function queueReport(key: string) {
  queueLoading.value = true
  try {
    const res = await fetch(`/performance/reports/queue/${key}`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
      },
      body: JSON.stringify({}),
    })
    if (!res.ok) throw new Error()
    setToast(t('pa_reports.toast.queued', { report: key }))
  } catch {
    setToast(t('pa_reports.toast.queued_error', { report: key }), 'error')
  } finally {
    queueLoading.value = false
  }
}

function exportReport(key: string, format: 'excel' | 'pdf') {
  const qs  = buildQs()
  const fmt = format === 'excel' ? 'xlsx' : 'pdf'
  const url = `/performance/reports/export/${key}/${fmt}${qs ? `?${qs}` : ''}`
  window.open(url, '_blank')
  setToast(t('pa_reports.toast.export_started', { format: format.toUpperCase(), report: key }))
}

function switchToReport(key: string) { activeTab.value = key }

function formatDate(val: unknown): string {
  if (!val) return '—'
  const d = new Date(String(val) + 'T00:00:00')
  if (Number.isNaN(d.getTime())) return String(val)
  return d.toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}

function scoreColor(score: unknown): string {
  const n = Number(score)
  if (n >= 80) return 'success'
  if (n >= 60) return 'primary'
  if (n >= 40) return 'warning'
  return 'error'
}

function outcomeColor(o: unknown): string {
  return ({ improved: 'success', dismissed: 'error', extended: 'warning', withdrawn: 'secondary' } as Record<string, string>)[String(o)] ?? 'default'
}

function decisionColor(d: unknown): string {
  return ({ confirmed: 'success', extended: 'warning', terminated: 'error', resigned: 'secondary' } as Record<string, string>)[String(d)] ?? 'default'
}

const sumColumns: Record<string, string[]> = {
  distribution:     ['count'],
  dept_scores:      ['employee_count'],
  feedback_summary: ['praise_count', 'coaching_count', 'concern_count', 'total_count'],
  pip_outcomes:     ['duration_days'],
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
</script>

<template>
  <VRow>
    <!-- Ribbon header -->
    <VCol cols="12">
      <VCard class="pa-ribbon">
        <VCardText class="d-flex flex-wrap align-center justify-space-between gap-4 py-6">
          <div>
            <div class="text-overline text-white opacity-90">{{ t('pa_reports.module') }}</div>
            <h4 class="text-h4 text-white mb-1">{{ t('pa_reports.title') }}</h4>
            <p class="mb-0 text-white opacity-90">{{ t('pa_reports.subtitle') }}</p>
          </div>
          <div class="d-flex flex-column align-end gap-2">
            <VChip color="white" class="text-primary font-weight-bold" label>
              {{ activeRows.length }} {{ t('pa_reports.active_rows') }}
            </VChip>
            <div class="d-flex gap-2">
              <VBtn variant="outlined" color="white" size="small" prepend-icon="tabler-chart-bar" href="/performance/analytics">
                {{ t('pa_reports.bi_analytics') }}
              </VBtn>
              <VBtn variant="outlined" color="white" size="small" prepend-icon="tabler-calendar-clock" href="/performance/reports/scheduled">
                {{ t('pa_reports.scheduled_reports') }}
              </VBtn>
            </div>
          </div>
        </VCardText>
      </VCard>
    </VCol>

    <!-- Filters -->
    <VCol cols="12">
      <VCard :title="t('pa_reports.report_filters')">
        <VCardText>
          <VRow>
            <VCol cols="12" sm="6" md="3">
              <VSelect
                v-model="filters.cycleId"
                :items="cycles"
                item-title="name"
                item-value="id"
                :label="t('pa.analytics.review_cycle')"
                prepend-inner-icon="tabler-refresh"
                clearable
                density="comfortable"
                :placeholder="t('pa_reports.all_cycles')"
              />
            </VCol>
            <VCol cols="12" sm="6" md="3">
              <VAutocomplete
                v-model="filters.employeeIds"
                :items="employees"
                item-title="name"
                item-value="id"
                :label="t('pa.common.employee')"
                prepend-inner-icon="tabler-user-search"
                multiple chips closable-chips clearable
                density="comfortable"
                :placeholder="t('pa_reports.all_employees')"
              />
            </VCol>
            <VCol cols="12" sm="6" md="3">
              <VAutocomplete
                v-model="filters.departments"
                :items="departments"
                item-title="name"
                item-value="id"
                :label="t('pa_reports.departments')"
                prepend-inner-icon="tabler-building"
                multiple chips closable-chips clearable
                density="comfortable"
                :placeholder="t('pa_reports.all_departments')"
              />
            </VCol>
            <VCol cols="6" sm="3" md="1.5">
              <div class="native-date-field">
                <span class="native-date-field__label">{{ t('pa_reports.date_from') }}</span>
                <input v-model="filters.dateFrom" type="date" class="native-date-field__input" />
              </div>
            </VCol>
            <VCol cols="6" sm="3" md="1.5">
              <div class="native-date-field">
                <span class="native-date-field__label">{{ t('pa_reports.date_to') }}</span>
                <input v-model="filters.dateTo" type="date" class="native-date-field__input" />
              </div>
            </VCol>
          </VRow>
          <div v-if="filters.employeeIds.length || filters.departments.length" class="d-flex flex-wrap gap-1 mt-1 mb-2">
            <VChip v-for="id in filters.employeeIds" :key="`emp-${id}`" size="small" closable @click:close="filters.employeeIds = filters.employeeIds.filter(x => x !== id)">
              {{ employees.find(e => e.id === id)?.name ?? id }}
            </VChip>
            <VChip v-for="id in filters.departments" :key="`dept-${id}`" size="small" color="primary" closable @click:close="filters.departments = filters.departments.filter(x => x !== id)">
              {{ departments.find(d => String(d.id) === String(id))?.name ?? id }}
            </VChip>
          </div>
          <div class="d-flex justify-end">
            <VBtn variant="tonal" color="secondary" prepend-icon="tabler-filter-off" @click="clearFilters">
              {{ t('pa_reports.clear_filters') }}
            </VBtn>
          </div>
        </VCardText>
      </VCard>
    </VCol>

    <!-- Report cards -->
    <VCol cols="12">
      <VCard :title="t('pa_reports.reports_engine')">
        <VCardText>
          <VRow>
            <VCol v-for="report in reportCards" :key="report.key" cols="12" md="6" lg="4">
              <VCard variant="outlined" class="h-100">
                <VCardText>
                  <div class="d-flex align-center gap-2 mb-2">
                    <VIcon :icon="report.icon" :color="report.color" size="20" />
                    <span class="text-subtitle-1 font-weight-medium">{{ report.title }}</span>
                  </div>
                  <div class="text-body-2 text-medium-emphasis mb-4">{{ report.description }}</div>
                  <div class="d-flex flex-wrap gap-2">
                    <VBtn size="small" color="primary" prepend-icon="tabler-player-play" @click="generateReport(report.key)">
                      {{ t('pa_reports.generate') }}
                    </VBtn>
                    <VBtn size="small" variant="tonal" prepend-icon="tabler-table" @click="switchToReport(report.key)">
                      {{ t('pa_reports.view_table') }}
                    </VBtn>
                    <VMenu>
                      <template #activator="{ props: menuProps }">
                        <VBtn v-bind="menuProps" size="small" color="secondary" variant="tonal" prepend-icon="tabler-upload">
                          {{ t('pa_reports.export') }}
                        </VBtn>
                      </template>
                      <VList>
                        <VListItem :title="t('pa_reports.export_excel')" @click="exportReport(report.key, 'excel')" />
                        <VListItem :title="t('pa_reports.export_pdf')"   @click="exportReport(report.key, 'pdf')" />
                        <VListItem :title="t('pa_reports.queue_email')"  @click="queueReport(report.key)" />
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
          <VTab value="distribution">{{ t('pa_reports.cards.distribution.title') }}</VTab>
          <VTab value="high_performers">{{ t('pa_reports.cards.high_performers.title') }}</VTab>
          <VTab value="low_performers">{{ t('pa_reports.cards.low_performers.title') }}</VTab>
          <VTab value="pip">{{ t('pa_reports.cards.pip.title') }}</VTab>
          <VTab value="promotion_ready">{{ t('pa_reports.cards.promotion_ready.title') }}</VTab>
          <VTab value="burnout_risk">{{ t('pa_reports.cards.burnout_risk.title') }}</VTab>
          <VTab value="dept_scores">{{ t('pa_reports.cards.dept_scores.title') }}</VTab>
          <VTab value="bonus">{{ t('pa_reports.cards.bonus.title') }}</VTab>
          <VTab value="calibration_risk">{{ t('pa_reports.cards.calibration_risk.title') }}</VTab>
          <VTab value="policy_violations">{{ t('pa_reports.cards.policy_violations.title') }}</VTab>
          <VTab value="kpi_completion">{{ t('pa_reports.cards.kpi_completion.title') }}</VTab>
          <VTab value="feedback_summary">{{ t('pa_reports.cards.feedback_summary.title') }}</VTab>
          <VTab value="pip_outcomes">{{ t('pa_reports.cards.pip_outcomes.title') }}</VTab>
          <VTab value="probation">{{ t('pa_reports.cards.probation.title') }}</VTab>
        </VTabs>

        <VDivider />

        <VCardText class="d-flex gap-2 flex-wrap align-center">
          <VBtn color="primary"   prepend-icon="tabler-player-play"             @click="generateReport(activeTab)">{{ t('pa_reports.generate') }}</VBtn>
          <VBtn color="success"   prepend-icon="tabler-file-type-csv"   variant="tonal" @click="exportReport(activeTab, 'excel')">{{ t('pa_reports.export_excel') }}</VBtn>
          <VBtn color="error"     prepend-icon="tabler-file-type-pdf"     variant="tonal" @click="exportReport(activeTab, 'pdf')">{{ t('pa_reports.export_pdf') }}</VBtn>
          <VBtn color="secondary" prepend-icon="tabler-mail-forward" variant="tonal" :loading="queueLoading" @click="queueReport(activeTab)">{{ t('pa_reports.queue_email') }}</VBtn>
        </VCardText>

        <VDataTable :headers="activeHeaders" :items="activeRows" :items-per-page="15" height="600" fixed-header>
          <template #item.start_date="{ item }">{{ formatDate(item.start_date) }}</template>
          <template #item.end_date="{ item }">{{ formatDate(item.end_date) }}</template>
          <template #item.created_at="{ item }">{{ formatDate(item.created_at) }}</template>
          <template #item.review_date="{ item }">{{ formatDate(item.review_date) }}</template>
          <template #item.probation_end_date="{ item }">{{ formatDate(item.probation_end_date) }}</template>
          <template #item.ranked_at="{ item }">{{ formatDate(item.ranked_at) }}</template>
          <template #item.score="{ item }"><VChip size="small" variant="tonal" :color="scoreColor(item.score)">{{ item.score }}</VChip></template>
          <template #item.avg_score="{ item }"><VChip size="small" variant="tonal" :color="scoreColor(item.avg_score)">{{ item.avg_score }}</VChip></template>
          <template #item.status="{ item }"><VChip size="small" variant="tonal" :color="String(item.status) === 'active' ? 'warning' : ['completed','closed'].includes(String(item.status)) ? 'success' : 'secondary'">{{ item.status }}</VChip></template>
          <template #item.outcome="{ item }"><VChip size="small" variant="tonal" :color="outcomeColor(item.outcome)">{{ item.outcome }}</VChip></template>
          <template #item.decision="{ item }"><VChip size="small" variant="tonal" :color="decisionColor(item.decision)">{{ item.decision ?? '—' }}</VChip></template>
          <template #item.severity="{ item }"><VChip size="small" variant="tonal" :color="String(item.severity) === 'critical' ? 'error' : String(item.severity) === 'high' ? 'warning' : 'info'">{{ item.severity }}</VChip></template>
          <template #item.has_pip="{ item }"><VIcon :icon="item.has_pip ? 'tabler-circle-check' : 'tabler-circle-minus'" :color="item.has_pip ? 'warning' : 'secondary'" size="18" /></template>
          <template v-if="Object.keys(columnTotals).length" #tfoot>
            <tr class="totals-row">
              <td v-for="(header, idx) in activeHeaders" :key="header.key" class="pa-2 text-caption">
                <template v-if="idx === 0">
                  <span class="font-weight-bold text-primary text-uppercase">{{ t('pa_reports.total') }} ({{ activeRows.length }})</span>
                </template>
                <template v-else-if="columnTotals[header.key] !== undefined">
                  <span class="font-weight-bold">{{ columnTotals[header.key] }}</span>
                </template>
              </td>
            </tr>
          </template>
        </VDataTable>

        <div v-if="Object.keys(columnTotals).length" class="d-flex flex-wrap align-center gap-x-6 gap-y-1 px-4 py-3 totals-strip">
          <VIcon icon="tabler-math-symbols" size="16" color="primary" />
          <span class="text-caption font-weight-bold text-primary text-uppercase me-2">
            {{ activeRows.length }} {{ activeRows.length !== 1 ? t('pa_reports.row_plural') : t('pa_reports.row_singular') }}
          </span>
          <template v-for="header in activeHeaders" :key="header.key">
            <div v-if="columnTotals[header.key] !== undefined" class="d-flex align-center gap-1">
              <span class="text-caption text-medium-emphasis">{{ header.title }}:</span>
              <strong class="text-caption text-high-emphasis">{{ columnTotals[header.key] }}</strong>
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
.pa-ribbon {
  border: 0;
  overflow: hidden;
  background: linear-gradient(135deg, #1a237e 0%, #7367f0 100%);
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
  border-radius: 4px; padding: 6px 12px 7px; cursor: pointer; transition: border-color 0.15s; min-height: 48px;
  display: flex; flex-direction: column; justify-content: center;
  &:hover { border-color: rgba(var(--v-theme-on-surface), 0.87); }
  &:focus-within { border-color: rgb(var(--v-theme-primary)); border-width: 2px; padding: 5px 11px 6px; }
  &__label { display: block; font-size: 0.75rem; line-height: 1; color: rgba(var(--v-theme-on-surface), 0.6); margin-bottom: 3px; }
  &__input { border: none; outline: none; background: transparent; color: rgb(var(--v-theme-on-surface)); font-size: 0.875rem; width: 100%; cursor: pointer; font-family: inherit;
    &::-webkit-calendar-picker-indicator { cursor: pointer; opacity: 0.7; &:hover { opacity: 1; } }
  }
}
</style>
