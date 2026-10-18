<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useTheme } from 'vuetify'
import VueApexCharts from 'vue3-apexcharts'
import type { ApexOptions } from 'apexcharts'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

useTheme()
const success   = '#28c76f'
const warning   = '#ff9f43'
const error     = '#ea5455'
const info      = '#00cfe8'
const secondary = '#82868b'

interface Kpis {
  total_reviewed: number; avg_score: number
  high_performers: number; high_performer_pct: number; low_performers: number
  pip_count: number; pip_active: number; kpi_completion_pct: number; feedback_count: number
}
interface FilterOptions {
  cycles:      { id: number; name: string }[]
  departments: { id: number; name: string }[]
}
interface ActiveFilters {
  cycle_id: number | null
  department_id: number | null
}

const props = defineProps<{
  period: string
  dateFrom: string | null
  dateTo: string | null
  filters: ActiveFilters
  filterOptions: FilterOptions
  kpis: Kpis
  scoreDistribution: { band: number; label: string; count: number }[]
  deptPerformance: { department: string; avg_score: number; reviewed: number }[]
  scoreTrend: { month: string; avg_score: number; count: number }[]
  kpiCompletion: { department: string; total: number; completed: number; completion_pct: number }[]
  pipStatus: { status: string; count: number }[]
  feedbackTrend: { month: string; count: number; avg_rating: number }[]
  goalProgress: { status: string; count: number; avg_progress: number }[]
  topPerformers: { name: string; department: string; score: number }[]
  pipList: { name: string; department: string; status: string; start_date: string; end_date: string; reason: string }[]
  probationSummary: { status: string; count: number }[]
}>()

// ── Filter state ──────────────────────────────────────────────────────────────
const filtersOpen   = ref(false)
const periodOptions = [
  { value: 'month',    label: 'This Month' },
  { value: 'quarter',  label: 'Last 3 Months' },
  { value: 'halfyear', label: 'Last 6 Months' },
  { value: 'year',     label: 'This Year' },
  { value: 'lastyear', label: 'Last Year' },
]

const form = ref({
  period:        props.period ?? 'year',
  date_from:     props.dateFrom ?? '',
  date_to:       props.dateTo   ?? '',
  cycle_id:      props.filters.cycle_id      ? String(props.filters.cycle_id)      : '',
  department_id: props.filters.department_id ? String(props.filters.department_id) : '',
})

const useCustomDates = ref(!!(props.dateFrom || props.dateTo))

const activeFilterCount = computed(() => {
  const f = props.filters
  return [f.cycle_id, f.department_id].filter(Boolean).length
    + (props.dateFrom || props.dateTo ? 1 : 0)
})

function applyFilters() {
  const params: Record<string, string> = {}
  if (useCustomDates.value) {
    if (form.value.date_from) params.date_from = form.value.date_from
    if (form.value.date_to)   params.date_to   = form.value.date_to
  } else {
    params.period = form.value.period
  }
  if (form.value.cycle_id)      params.cycle_id      = form.value.cycle_id
  if (form.value.department_id) params.department_id = form.value.department_id
  router.get(window.location.pathname, params, { preserveScroll: true })
}

function clearFilters() {
  form.value = { period: 'year', date_from: '', date_to: '', cycle_id: '', department_id: '' }
  useCustomDates.value = false
  router.get(window.location.pathname, { period: 'year' }, { preserveScroll: true })
}

// ── Formatters ────────────────────────────────────────────────────────────────
const fmtN = (v: number) => Number(v).toLocaleString('en-ZA')
const monthLabel = (m: string) => {
  const [y, mo] = m.split('-')
  return new Date(+y, +mo - 1).toLocaleString('en-ZA', { month: 'short', year: '2-digit' })
}
const scoreColor = (s: number) => s >= 4 ? success : s >= 3 ? info : s >= 2 ? warning : error

// ── KPI cards ─────────────────────────────────────────────────────────────────
const kpiCards = computed(() => [
  { title: 'Employees Reviewed',  value: fmtN(props.kpis.total_reviewed),      sub: 'In selected period',                                  icon: 'tabler-users',     color: 'primary' },
  { title: 'Average Score',       value: `${props.kpis.avg_score} / 5`,         sub: 'Overall performance rating',                          icon: 'tabler-star',              color: props.kpis.avg_score >= 3.5 ? 'success' : 'warning' },
  { title: 'High Performers',     value: `${props.kpis.high_performer_pct}%`,   sub: `${props.kpis.high_performers} employees (score ≥ 4)`, icon: 'tabler-user-star',      color: 'success' },
  { title: 'Low Performers',      value: fmtN(props.kpis.low_performers),       sub: 'Score below 2 — at risk',                             icon: 'tabler-user-exclamation',     color: 'error' },
  { title: 'Active PIPs',         value: fmtN(props.kpis.pip_active),            sub: `${props.kpis.pip_count} total plans`,                 icon: 'tabler-clipboard-exclamation',   color: 'warning' },
  { title: 'KPI Completion',      value: `${props.kpis.kpi_completion_pct}%`,   sub: 'KPIs marked completed',                               icon: 'tabler-circle-check',      color: 'info' },
])

// ── Score distribution (bar) ──────────────────────────────────────────────────
const scoreDistOptions = computed<ApexOptions>(() => ({
  chart:   { type: 'bar', toolbar: { show: false } },
  plotOptions: { bar: { borderRadius: 6, columnWidth: '55%', distributed: true } },
  colors:  props.scoreDistribution.map(r => scoreColor(r.band)),
  xaxis:   { categories: props.scoreDistribution.map(r => `Band ${r.band}`) },
  yaxis:   { labels: { formatter: (v: number) => String(Math.round(v)) } },
  tooltip: { y: { formatter: (v: number) => `${v} employees` } },
  legend:  { show: false },
  dataLabels: { enabled: true, formatter: (v: number) => v },
  grid: { borderColor: '#f1f3f4' },
}))
const scoreDistSeries = computed(() => [
  { name: 'Employees', data: props.scoreDistribution.map(r => r.count) },
])

// ── Score trend (line) ────────────────────────────────────────────────────────
const scoreTrendOptions = computed<ApexOptions>(() => ({
  chart:   { type: 'line', toolbar: { show: false }, zoom: { enabled: false } },
  stroke:  { curve: 'smooth', width: 3 },
  colors:  ['#696cff'],
  markers: { size: 5 },
  xaxis:   { categories: props.scoreTrend.map(r => monthLabel(r.month)) },
  yaxis:   { min: 0, max: 5, labels: { formatter: (v: number) => v.toFixed(1) } },
  tooltip: { y: { formatter: (v: number) => `${v} avg score` } },
  grid:    { borderColor: '#f1f3f4' },
  annotations: {
    yaxis: [{ y: 3.5, borderColor: success, label: { text: 'Target 3.5', style: { color: success } } }],
  },
}))
const scoreTrendSeries = computed(() => [
  { name: 'Avg Score', data: props.scoreTrend.map(r => r.avg_score) },
])

// ── Dept performance bar ──────────────────────────────────────────────────────
const deptPerfOptions = computed<ApexOptions>(() => ({
  chart:   { type: 'bar', toolbar: { show: false } },
  plotOptions: { bar: { horizontal: true, borderRadius: 4 } },
  colors:  ['#696cff'],
  xaxis:   { categories: props.deptPerformance.map(r => r.department), min: 0, max: 5 },
  tooltip: { y: { formatter: (v: number) => `${v} avg score` } },
  dataLabels: { enabled: true, formatter: (v: number) => v.toFixed(2), offsetX: 25 },
  grid: { borderColor: '#f1f3f4' },
}))
const deptPerfSeries = computed(() => [
  { name: 'Avg Score', data: props.deptPerformance.map(r => r.avg_score) },
])

// ── KPI completion by dept bar ────────────────────────────────────────────────
const kpiCompOptions = computed<ApexOptions>(() => ({
  chart:   { type: 'bar', toolbar: { show: false } },
  plotOptions: { bar: { horizontal: true, borderRadius: 4 } },
  colors:  [success],
  xaxis:   { categories: props.kpiCompletion.map(r => r.department), min: 0, max: 100 },
  yaxis:   { labels: { formatter: (v: number) => `${v}%` } },
  tooltip: { y: { formatter: (v: number) => `${v}%` } },
  dataLabels: { enabled: true, formatter: (v: number) => `${v}%`, offsetX: 30 },
  grid: { borderColor: '#f1f3f4' },
}))
const kpiCompSeries = computed(() => [
  { name: 'Completion %', data: props.kpiCompletion.map(r => r.completion_pct) },
])

// ── PIP status donut ──────────────────────────────────────────────────────────
const pipColors: Record<string, string> = {
  active: warning, completed: success, failed: error, cancelled: secondary,
}
const pipStatusOptions = computed<ApexOptions>(() => ({
  chart:   { type: 'donut' },
  labels:  props.pipStatus.map(r => r.status),
  colors:  props.pipStatus.map(r => pipColors[r.status] ?? secondary),
  legend:  { position: 'bottom' },
  plotOptions: { pie: { donut: { size: '65%', labels: { show: true, total: { show: true, label: 'Total PIPs' } } } } },
  dataLabels: { enabled: false },
}))
const pipStatusSeries = computed(() => props.pipStatus.map(r => r.count))

// ── Feedback trend (area) ─────────────────────────────────────────────────────
const feedbackTrendOptions = computed<ApexOptions>(() => ({
  chart:   { type: 'area', toolbar: { show: false }, zoom: { enabled: false } },
  stroke:  { curve: 'smooth', width: 2 },
  fill:    { type: 'gradient', gradient: { opacityFrom: 0.4, opacityTo: 0.05 } },
  colors:  [info],
  xaxis:   { categories: props.feedbackTrend.map(r => monthLabel(r.month)) },
  yaxis:   { labels: { formatter: (v: number) => String(Math.round(v)) } },
  tooltip: { y: { formatter: (v: number) => `${v} feedback` } },
  grid:    { borderColor: '#f1f3f4' },
}))
const feedbackTrendSeries = computed(() => [
  { name: 'Feedback Count', data: props.feedbackTrend.map(r => r.count) },
])

// ── Probation donut ───────────────────────────────────────────────────────────
const probationColors: Record<string, string> = {
  passed: success, extended: warning, failed: error, pending: info,
}
const probationOptions = computed<ApexOptions>(() => ({
  chart:   { type: 'pie' },
  labels:  props.probationSummary.map(r => r.status),
  colors:  props.probationSummary.map(r => probationColors[r.status] ?? secondary),
  legend:  { position: 'bottom' },
  dataLabels: { enabled: true },
}))
const probationSeries = computed(() => props.probationSummary.map(r => r.count))
</script>

<template>
  <div class="pa-4">
    <!-- ── Header ─────────────────────────────────────────────────────────── -->
    <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-4">
      <div>
        <div class="text-overline text-medium-emphasis">Performance Appraisal</div>
        <h4 class="text-h4 font-weight-bold">{{ t('pa.analytics.title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('pa.analytics.subtitle') }}</p>
      </div>
      <div class="d-flex align-center gap-2 flex-wrap">
        <VBtn variant="tonal" prepend-icon="tabler-adjustments-horizontal" @click="filtersOpen = !filtersOpen">
          Filters
          <VBadge v-if="activeFilterCount > 0" :content="activeFilterCount" color="primary" floating />
        </VBtn>
        <VBtn variant="tonal" prepend-icon="tabler-file-analytics" href="/performance/reports">Reports</VBtn>
      </div>
    </div>

    <!-- ── Filter panel ───────────────────────────────────────────────────── -->
    <VExpandTransition>
      <VCard v-if="filtersOpen" class="mb-5" variant="outlined">
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12" class="pb-1">
              <VBtnToggle v-model="useCustomDates" density="compact" variant="outlined" mandatory>
                <VBtn :value="false" size="small">Period Preset</VBtn>
                <VBtn :value="true"  size="small">Custom Dates</VBtn>
              </VBtnToggle>
            </VCol>
            <VCol v-if="!useCustomDates" cols="12" sm="6" md="3">
              <VSelect v-model="form.period" :items="periodOptions" item-title="label" item-value="value" :label="t('pa.analytics.period')" density="compact" variant="outlined" hide-details />
            </VCol>
            <template v-if="useCustomDates">
              <VCol cols="12" sm="6" md="3">
                <VTextField v-model="form.date_from" type="date" :label="t('pa.analytics.date_from')" density="compact" variant="outlined" hide-details />
              </VCol>
              <VCol cols="12" sm="6" md="3">
                <VTextField v-model="form.date_to" type="date" :label="t('pa.analytics.date_to')" density="compact" variant="outlined" hide-details />
              </VCol>
            </template>
            <VCol cols="12" sm="6" md="3">
              <VSelect v-model="form.cycle_id" :items="filterOptions.cycles" item-title="name" item-value="id" :label="t('pa.analytics.review_cycle')" density="compact" variant="outlined" hide-details clearable />
            </VCol>
            <VCol cols="12" sm="6" md="3">
              <VSelect v-model="form.department_id" :items="filterOptions.departments" item-title="name" item-value="id" :label="t('pa.analytics.department')" density="compact" variant="outlined" hide-details clearable />
            </VCol>
            <VCol cols="12" class="d-flex gap-2 pt-2">
              <VBtn color="primary" prepend-icon="tabler-search" @click="applyFilters">Apply Filters</VBtn>
              <VBtn variant="tonal" prepend-icon="tabler-x" @click="clearFilters">Clear All</VBtn>
            </VCol>
          </VRow>
        </VCardText>
      </VCard>
    </VExpandTransition>

    <!-- Active filter chips -->
    <div v-if="activeFilterCount > 0" class="d-flex flex-wrap gap-2 mb-4">
      <VChip v-if="dateFrom || dateTo" size="small" closable color="primary" @click:close="clearFilters">{{ dateFrom }} → {{ dateTo }}</VChip>
      <VChip v-else size="small" color="primary" variant="tonal">{{ periodOptions.find(p => p.value === period)?.label }}</VChip>
      <VChip v-if="filters.cycle_id"      size="small" closable color="secondary" variant="tonal" @click:close="() => { form.cycle_id = ''; applyFilters() }">Cycle: {{ filterOptions.cycles.find(c => c.id === filters.cycle_id)?.name }}</VChip>
      <VChip v-if="filters.department_id" size="small" closable color="secondary" variant="tonal" @click:close="() => { form.department_id = ''; applyFilters() }">Dept: {{ filterOptions.departments.find(d => d.id === filters.department_id)?.name }}</VChip>
    </div>

    <!-- ── KPI cards ──────────────────────────────────────────────────────── -->
    <VRow class="mb-4">
      <VCol v-for="card in kpiCards" :key="card.title" cols="12" sm="6" md="4" lg="2">
        <VCard height="100%">
          <VCardText class="pa-4">
            <div class="d-flex align-center justify-space-between mb-2">
              <VIcon :color="card.color" size="32">{{ card.icon }}</VIcon>
              <VChip :color="card.color" size="x-small" label variant="tonal">{{ card.color }}</VChip>
            </div>
            <div class="text-h5 font-weight-bold">{{ card.value }}</div>
            <div class="text-subtitle-2 font-weight-semibold mt-1">{{ card.title }}</div>
            <div class="text-caption text-medium-emphasis">{{ card.sub }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Row 2: Score Trend + Score Distribution ────────────────────────── -->
    <VRow class="mb-4">
      <VCol cols="12" md="7">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>Score Trend</VCardTitle>
            <VCardSubtitle>Average performance score over time (target: 3.5)</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts v-if="scoreTrend.length" type="line" :height="280" :options="scoreTrendOptions" :series="scoreTrendSeries" />
            <VEmptyState v-else icon="tabler-chart-line" :title="t('pa.analytics.no_scores')" :text="t('pa.analytics.no_scores_sub')" size="80" />
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="5">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>Score Distribution</VCardTitle>
            <VCardSubtitle>Employees per score band (1–5)</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts v-if="scoreDistribution.length" type="bar" :height="280" :options="scoreDistOptions" :series="scoreDistSeries" />
            <VEmptyState v-else icon="tabler-chart-bar" :title="t('pa.analytics.no_distribution_data')" size="60" />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Row 3: Dept Performance + KPI Completion ──────────────────────── -->
    <VRow class="mb-4">
      <VCol cols="12" md="6">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>Department Performance</VCardTitle>
            <VCardSubtitle>Average score per department</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts v-if="deptPerformance.length" type="bar" :height="300" :options="deptPerfOptions" :series="deptPerfSeries" />
            <VEmptyState v-else icon="tabler-building" :title="t('pa.analytics.no_dept_data')" size="70" />
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="6">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>KPI Completion by Department</VCardTitle>
            <VCardSubtitle>Percentage of KPIs marked completed</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts v-if="kpiCompletion.length" type="bar" :height="300" :options="kpiCompOptions" :series="kpiCompSeries" />
            <VEmptyState v-else icon="tabler-circle-check" :title="t('pa.analytics.no_kpi_data')" size="70" />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Row 4: PIP Status + Feedback Trend + Probation ────────────────── -->
    <VRow class="mb-4">
      <VCol cols="12" md="4">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>PIP Status</VCardTitle>
            <VCardSubtitle>Performance improvement plans by status</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts v-if="pipStatus.length" type="donut" :height="260" :options="pipStatusOptions" :series="pipStatusSeries" />
            <VEmptyState v-else icon="tabler-clipboard-exclamation" :title="t('pa.analytics.no_pip_data')" size="60" />
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="4">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>Feedback Volume Trend</VCardTitle>
            <VCardSubtitle>Monthly 360° and peer feedback submissions</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts v-if="feedbackTrend.length" type="area" :height="260" :options="feedbackTrendOptions" :series="feedbackTrendSeries" />
            <VEmptyState v-else icon="tabler-messages" :title="t('pa.analytics.no_feedback_data')" size="60" />
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="4">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>Probation Reviews</VCardTitle>
            <VCardSubtitle>Outcomes by status</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts v-if="probationSummary.length" type="pie" :height="260" :options="probationOptions" :series="probationSeries" />
            <VEmptyState v-else icon="tabler-user-clock" :title="t('pa.analytics.no_probation_data')" size="60" />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Row 5: Top Performers table ───────────────────────────────────── -->
    <VRow class="mb-4">
      <VCol cols="12" md="6">
        <VCard>
          <VCardItem>
            <VCardTitle>Top Performers</VCardTitle>
            <VCardSubtitle>Employees with highest overall scores</VCardSubtitle>
          </VCardItem>
          <VTable density="compact">
            <thead>
              <tr>
                <th>#</th>
                <th>{{ t('pa.common.employee') }}</th>
                <th>{{ t('pa.succession.department') }}</th>
                <th class="text-right">{{ t('pa.common.score') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!topPerformers.length">
                <td colspan="4" class="text-center text-medium-emphasis pa-4">No performance data for this period.</td>
              </tr>
              <tr v-for="(e, i) in topPerformers" :key="`${e.name}-${i}`">
                <td><VChip size="x-small" :color="i < 3 ? 'primary' : 'default'" label>{{ i + 1 }}</VChip></td>
                <td class="font-weight-medium">{{ e.name }}</td>
                <td class="text-medium-emphasis">{{ e.department }}</td>
                <td class="text-right">
                  <VChip size="x-small" :color="e.score >= 4 ? 'success' : e.score >= 3 ? 'info' : 'warning'" label>{{ e.score }}</VChip>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>

      <!-- ── PIP List ────────────────────────────────────────────────────── -->
      <VCol cols="12" md="6">
        <VCard>
          <VCardItem>
            <VCardTitle>Active PIP Employees</VCardTitle>
            <VCardSubtitle>Performance improvement plan overview</VCardSubtitle>
          </VCardItem>
          <VTable density="compact">
            <thead>
              <tr>
                <th>{{ t('pa.common.employee') }}</th>
                <th>{{ t('pa.succession.department') }}</th>
                <th>{{ t('pa.common.status') }}</th>
                <th>{{ t('pa.common.start_date') }}</th>
                <th>{{ t('pa.common.end_date') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!pipList.length">
                <td colspan="5" class="text-center text-medium-emphasis pa-4">No PIP records for this period.</td>
              </tr>
              <tr v-for="p in pipList" :key="`${p.name}-${p.start_date}`">
                <td class="font-weight-medium">{{ p.name }}</td>
                <td class="text-medium-emphasis">{{ p.department }}</td>
                <td>
                  <VChip size="x-small" :color="p.status === 'active' ? 'warning' : p.status === 'completed' ? 'success' : 'error'" label>
                    {{ p.status }}
                  </VChip>
                </td>
                <td class="text-caption text-medium-emphasis">{{ p.start_date ? new Date(p.start_date).toLocaleDateString('en-ZA') : '—' }}</td>
                <td class="text-caption text-medium-emphasis">{{ p.end_date ? new Date(p.end_date).toLocaleDateString('en-ZA') : '—' }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>

<style lang="scss" scoped>
@use "@core/scss/template/libs/apex-chart.scss";
</style>
