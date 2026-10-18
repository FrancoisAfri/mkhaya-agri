<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useTheme } from 'vuetify'
import VueApexCharts from 'vue3-apexcharts'
import type { ApexOptions } from 'apexcharts'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
useTheme()

const primary   = '#696cff'
const success   = '#28c76f'
const warning   = '#ff9f43'
const error     = '#ea5455'
const info      = '#00cfe8'
const secondary = '#82868b'

interface Props {
  period: string
  dateFrom: string | null
  dateTo: string | null
  filters: { course_id: number | null; department_id: string | null }
  filterOptions: {
    courses: { id: number; title: string }[]
    departments: { id: string; name: string }[]
  }
  kpis: { total: number; completed: number; inProgress: number; overdue: number; avgScore: number; certIssued: number; rate: number }
  enrollmentTrend: { month: string; total: number; completed: number; in_progress: number }[]
  completionByCourse: { course: string; total: number; completed: number; completion_rate: number }[]
  complianceRate: { month: string; total: number; completed: number; overdue: number; compliance_rate: number }[]
  quizPassFail: { course: string; attempts: number; passed: number; failed: number; pass_rate: number; avg_score: number }[]
  departmentProgress: { department: string; total: number; completed: number; completion_rate: number; avg_score: number | null }[]
  skillsGapTop: { skill: string; affected: number; avg_gap: number }[]
  topLearners: { name: string; department: string; completions: number; avg_score: number | null }[]
  expiringCerts: { employee: string; course: string; expires_at: string; days_left: number }[]
  learnershipStatus: { status: string; count: number }[]
}

const props = defineProps<Props>()

const filtersOpen   = ref(false)
const periodOptions = [
  { value: 'month',    label: t('lms-admins_analytic.period_options.month') },
  { value: 'quarter',  label: t('lms-admins_analytic.period_options.quarter') },
  { value: 'halfyear', label: t('lms-admins_analytic.period_options.halfyear') },
  { value: 'year',     label: t('lms-admins_analytic.period_options.year') },
  { value: 'lastyear', label: t('lms-admins_analytic.period_options.lastyear') },
]

const form = ref({
  period:        props.period ?? 'year',
  date_from:     props.dateFrom ?? '',
  date_to:       props.dateTo ?? '',
  course_id:     props.filters.course_id ? String(props.filters.course_id) : '',
  department_id: props.filters.department_id ?? '',
})
const useCustomDates = ref(!!(props.dateFrom || props.dateTo))

const activeFilterCount = computed(() =>
  [props.filters.course_id, props.filters.department_id].filter(Boolean).length +
  (props.dateFrom || props.dateTo ? 1 : 0)
)

function applyFilters() {
  const params: Record<string, string> = {}
  if (useCustomDates.value) {
    if (form.value.date_from) params.date_from = form.value.date_from
    if (form.value.date_to)   params.date_to   = form.value.date_to
  } else {
    params.period = form.value.period
  }
  if (form.value.course_id)     params.course_id     = form.value.course_id
  if (form.value.department_id) params.department_id = form.value.department_id
  router.get(window.location.pathname, params, { preserveScroll: true })
}

function clearFilters() {
  form.value = { period: 'year', date_from: '', date_to: '', course_id: '', department_id: '' }
  useCustomDates.value = false
  router.get(window.location.pathname, { period: 'year' }, { preserveScroll: true })
}

const monthLabel = (m: string) => {
  const [y, mo] = m.split('-')
  return new Date(+y, +mo - 1).toLocaleString('en-ZA', { month: 'short', year: '2-digit' })
}

// ── KPI Cards ────────────────────────────────────────────────────────────────
const kpiCards = computed(() => [
  { title: t('lms-admins_analytic.kpi_cards.total_enrolments'),   value: props.kpis.total,                     sub: t('lms-admins_analytic.kpi_cards.total_enrolments_sub'),                  icon: 'tabler-users',          color: 'primary' },
  { title: t('lms-admins_analytic.kpi_cards.completed'),          value: props.kpis.completed,                 sub: t('lms-admins_analytic.kpi_cards.completed_sub', { rate: props.kpis.rate }), icon: 'tabler-circle-check',   color: 'success' },
  { title: t('lms-admins_analytic.kpi_cards.in_progress'),        value: props.kpis.inProgress,                sub: t('lms-admins_analytic.kpi_cards.in_progress_sub'),                     icon: 'tabler-progress',       color: 'info' },
  { title: t('lms-admins_analytic.kpi_cards.overdue_compliance'), value: props.kpis.overdue,                   sub: t('lms-admins_analytic.kpi_cards.overdue_compliance_sub'),                       icon: 'tabler-alert-circle',   color: 'error' },
  { title: t('lms-admins_analytic.kpi_cards.avg_score'),          value: `${props.kpis.avgScore}%`,            sub: t('lms-admins_analytic.kpi_cards.avg_score_sub'),                      icon: 'tabler-star',           color: 'warning' },
  { title: t('lms-admins_analytic.kpi_cards.certs_issued'),       value: props.kpis.certIssued,                sub: t('lms-admins_analytic.kpi_cards.certs_issued_sub'),                  icon: 'tabler-certificate',    color: 'secondary' },
])

// ── Enrolment & Completion Trend ─────────────────────────────────────────────
const trendOptions = computed<ApexOptions>(() => ({
  chart: { type: 'line', toolbar: { show: false }, zoom: { enabled: false } },
  stroke: { curve: 'smooth', width: [3, 3, 2] },
  colors: [primary, success, warning],
  xaxis: { categories: props.enrollmentTrend.map(r => monthLabel(r.month)) },
  yaxis: { labels: { formatter: (v: number) => String(Math.round(v)) } },
  tooltip: { y: { formatter: (v: number) => `${v} enrolments` } },
  legend: { position: 'top' },
  markers: { size: 4 },
  grid: { borderColor: '#f1f3f4' },
}))
const trendSeries = computed(() => [
  { name: t('lms-admins_analytic.charts.enrolment_trend.total'),       data: props.enrollmentTrend.map(r => r.total) },
  { name: t('lms-admins_analytic.charts.enrolment_trend.completed'),   data: props.enrollmentTrend.map(r => r.completed) },
  { name: t('lms-admins_analytic.charts.enrolment_trend.in_progress'), data: props.enrollmentTrend.map(r => r.in_progress) },
])

// ── Compliance Rate Over Time ─────────────────────────────────────────────────
const complianceLineOptions = computed<ApexOptions>(() => ({
  chart: { type: 'line', toolbar: { show: false }, zoom: { enabled: false } },
  stroke: { curve: 'smooth', width: 3 },
  colors: [success],
  xaxis: { categories: props.complianceRate.map(r => monthLabel(r.month)) },
  yaxis: { min: 0, max: 100, labels: { formatter: (v: number) => `${v}%` } },
  tooltip: { y: { formatter: (v: number) => `${v}%` } },
  markers: { size: 4 },
  grid: { borderColor: '#f1f3f4' },
}))
const complianceLineSeries = computed(() => [
  { name: 'Compliance Rate %', data: props.complianceRate.map(r => r.compliance_rate) },
])

// ── Completion by Course (horizontal bar) ─────────────────────────────────────
const completionByCourseOptions = computed<ApexOptions>(() => ({
  chart: { type: 'bar', toolbar: { show: false } },
  plotOptions: { bar: { horizontal: true, borderRadius: 4 } },
  colors: [primary],
  xaxis: { categories: props.completionByCourse.map(r => r.course) },
  tooltip: { y: { formatter: (v: number) => `${v}%` } },
  dataLabels: { enabled: false },
  grid: { borderColor: '#f1f3f4' },
}))
const completionByCourseSeries = computed(() => [
  { name: 'Completion Rate %', data: props.completionByCourse.map(r => r.completion_rate) },
])

// ── Department Training Progress (column bar) ─────────────────────────────────
const deptProgressOptions = computed<ApexOptions>(() => ({
  chart: { type: 'bar', toolbar: { show: false } },
  plotOptions: { bar: { borderRadius: 4, columnWidth: '55%' } },
  colors: [info],
  xaxis: { categories: props.departmentProgress.map(r => r.department) },
  yaxis: { min: 0, max: 100, labels: { formatter: (v: number) => `${v}%` } },
  tooltip: { y: { formatter: (v: number) => `${v}%` } },
  dataLabels: { enabled: false },
  grid: { borderColor: '#f1f3f4' },
}))
const deptProgressSeries = computed(() => [
  { name: 'Completion Rate %', data: props.departmentProgress.map(r => r.completion_rate) },
])

// ── Skills Gap (horizontal bar) ───────────────────────────────────────────────
const skillsGapOptions = computed<ApexOptions>(() => ({
  chart: { type: 'bar', toolbar: { show: false } },
  plotOptions: { bar: { horizontal: true, borderRadius: 4 } },
  colors: [error],
  xaxis: { categories: props.skillsGapTop.map(r => r.skill) },
  tooltip: { y: { formatter: (v: number) => `${v} employees` } },
  dataLabels: { enabled: false },
  grid: { borderColor: '#f1f3f4' },
}))
const skillsGapSeries = computed(() => [
  { name: 'Affected Employees', data: props.skillsGapTop.map(r => r.affected) },
])

// ── Helpers ───────────────────────────────────────────────────────────────────
function passRateColor(rate: number) {
  if (rate >= 70) return 'success'
  if (rate >= 50) return 'warning'
  return 'error'
}

function daysLeftColor(days: number) {
  if (days <= 30) return 'error'
  if (days <= 60) return 'warning'
  return 'info'
}

const learnershipStatusColor: Record<string, string> = {
  active: 'success', completed: 'primary', withdrawn: 'error', suspended: 'warning', pending: 'info',
}
</script>

<template>
  <div class="pa-4">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-4">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('lms-admins_analytic.breadcrumb') }}</div>
        <h4 class="text-h4 font-weight-bold">{{ t('lms-admins_analytic.title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('lms-admins_analytic.subtitle') }}</p>
      </div>
      <div class="d-flex align-center gap-2 flex-wrap">
        <VBtn variant="tonal" prepend-icon="tabler-tune-variant" @click="filtersOpen = !filtersOpen">
          {{ t('lms-admins_analytic.filter_button') }}
          <VBadge v-if="activeFilterCount > 0" :content="activeFilterCount" color="primary" floating />
        </VBtn>
        <VBtn variant="tonal" prepend-icon="tabler-file-chart" href="/lms/admin/reports">{{ t('lms-admins_analytic.reports_button') }}</VBtn>
      </div>
    </div>

    <!-- Filters Panel -->
    <VExpandTransition>
      <VCard v-if="filtersOpen" class="mb-5" variant="outlined">
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12" class="pb-1">
              <VBtnToggle v-model="useCustomDates" density="compact" variant="outlined" mandatory>
                <VBtn :value="false" size="small">{{ t('lms-admins_analytic.filter_panel.period_preset') }}</VBtn>
                <VBtn :value="true" size="small">{{ t('lms-admins_analytic.filter_panel.custom_dates') }}</VBtn>
              </VBtnToggle>
            </VCol>
            <VCol v-if="!useCustomDates" cols="12" sm="6" md="3">
              <VSelect v-model="form.period" :items="periodOptions" item-title="label" item-value="value" :label="t('lms-admins_analytic.filter_panel.period')" density="compact" variant="outlined" hide-details />
            </VCol>
            <template v-if="useCustomDates">
              <VCol cols="12" sm="6" md="3">
                <VTextField v-model="form.date_from" type="date" :label="t('lms-admins_analytic.filter_panel.from_date')" density="compact" variant="outlined" hide-details />
              </VCol>
              <VCol cols="12" sm="6" md="3">
                <VTextField v-model="form.date_to" type="date" :label="t('lms-admins_analytic.filter_panel.to_date')" density="compact" variant="outlined" hide-details />
              </VCol>
            </template>
            <VCol cols="12" sm="6" md="3">
              <VSelect v-model="form.course_id" :items="filterOptions.courses" item-title="title" item-value="id" :label="t('lms-admins_analytic.filter_panel.course')" density="compact" variant="outlined" hide-details clearable />
            </VCol>
            <VCol cols="12" sm="6" md="3">
              <VSelect v-model="form.department_id" :items="filterOptions.departments" item-title="name" item-value="id" :label="t('lms-admins_analytic.filter_panel.department')" density="compact" variant="outlined" hide-details clearable />
            </VCol>
            <VCol cols="12" class="d-flex gap-2 pt-2">
              <VBtn color="primary" prepend-icon="tabler-magnify" @click="applyFilters">{{ t('lms-admins_analytic.filter_panel.apply') }}</VBtn>
              <VBtn variant="tonal" prepend-icon="tabler-close" @click="clearFilters">{{ t('lms-admins_analytic.filter_panel.clear_all') }}</VBtn>
            </VCol>
          </VRow>
        </VCardText>
      </VCard>
    </VExpandTransition>

    <!-- Active Filter Chips -->
    <div v-if="activeFilterCount > 0" class="d-flex flex-wrap gap-2 mb-4">
      <VChip v-if="dateFrom || dateTo" size="small" closable color="primary" @click:close="clearFilters">{{ dateFrom }} → {{ dateTo }}</VChip>
      <VChip v-else size="small" color="primary" variant="tonal">{{ periodOptions.find(p => p.value === period)?.label }}</VChip>
      <VChip v-if="filters.course_id" size="small" closable color="secondary" variant="tonal" @click:close="() => { form.course_id = ''; applyFilters() }">
        {{ t('lms-admins_analytic.filter_chips.course_prefix') }} {{ filterOptions.courses.find(c => c.id === filters.course_id)?.title }}
      </VChip>
      <VChip v-if="filters.department_id" size="small" closable color="secondary" variant="tonal" @click:close="() => { form.department_id = ''; applyFilters() }">
        {{ t('lms-admins_analytic.filter_chips.dept_prefix') }} {{ filterOptions.departments.find(d => d.id === filters.department_id)?.name }}
      </VChip>
    </div>

    <!-- KPI Cards -->
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

    <!-- Row 1: Enrolment Trend + Compliance Rate -->
    <VRow class="mb-4">
      <VCol cols="12" md="8">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>{{ t('lms-admins_analytic.charts.enrolment_trend.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('lms-admins_analytic.charts.enrolment_trend.subtitle') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts v-if="enrollmentTrend.length" type="line" :height="280" :options="trendOptions" :series="trendSeries" />
            <VEmptyState v-else icon="tabler-chart-line" :title="t('lms-admins_analytic.charts.enrolment_trend.empty_title')" :text="t('lms-admins_analytic.charts.enrolment_trend.empty_text')" size="80" />
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="4">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>{{ t('lms-admins_analytic.charts.compliance_rate.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('lms-admins_analytic.charts.compliance_rate.subtitle') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts v-if="complianceRate.length" type="line" :height="280" :options="complianceLineOptions" :series="complianceLineSeries" />
            <VEmptyState v-else icon="tabler-shield-check" :title="t('lms-admins_analytic.charts.compliance_rate.empty_title')" size="60" />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Row 2: Completion by Course + Department Progress -->
    <VRow class="mb-4">
      <VCol cols="12" md="6">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>{{ t('lms-admins_analytic.charts.completion_by_course.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('lms-admins_analytic.charts.completion_by_course.subtitle') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts v-if="completionByCourse.length" type="bar" :height="260" :options="completionByCourseOptions" :series="completionByCourseSeries" />
            <VEmptyState v-else icon="tabler-books" :title="t('lms-admins_analytic.charts.completion_by_course.empty_title')" size="70" />
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="6">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>{{ t('lms-admins_analytic.charts.department_progress.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('lms-admins_analytic.charts.department_progress.subtitle') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts v-if="departmentProgress.length" type="bar" :height="260" :options="deptProgressOptions" :series="deptProgressSeries" />
            <VEmptyState v-else icon="tabler-office-building" :title="t('lms-admins_analytic.charts.department_progress.empty_title')" size="70" />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Skills Gap Row -->
    <VRow class="mb-4">
      <VCol cols="12">
        <VCard>
          <VCardItem>
            <VCardTitle>{{ t('lms-admins_analytic.charts.skills_gap.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('lms-admins_analytic.charts.skills_gap.subtitle') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts v-if="skillsGapTop.length" type="bar" :height="260" :options="skillsGapOptions" :series="skillsGapSeries" />
            <VEmptyState v-else icon="tabler-chart-bar" :title="t('lms-admins_analytic.charts.skills_gap.empty_title')" :text="t('lms-admins_analytic.charts.skills_gap.empty_text')" size="80" />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Tables Row 1: Quiz Pass/Fail + Top Learners -->
    <VRow class="mb-4">
      <VCol cols="12" md="6">
        <VCard>
          <VCardItem>
            <VCardTitle>{{ t('lms-admins_analytic.tables.quiz_pass_fail.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('lms-admins_analytic.tables.quiz_pass_fail.subtitle') }}</VCardSubtitle>
          </VCardItem>
          <VTable density="compact">
            <thead>
              <tr>
                <th>{{ t('lms-admins_analytic.tables.quiz_pass_fail.course') }}</th>
                <th class="text-right">{{ t('lms-admins_analytic.tables.quiz_pass_fail.attempts') }}</th>
                <th class="text-right">{{ t('lms-admins_analytic.tables.quiz_pass_fail.passed') }}</th>
                <th class="text-right">{{ t('lms-admins_analytic.tables.quiz_pass_fail.failed') }}</th>
                <th class="text-right">{{ t('lms-admins_analytic.tables.quiz_pass_fail.pass_rate') }}</th>
                <th class="text-right">{{ t('lms-admins_analytic.tables.quiz_pass_fail.avg_score') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!quizPassFail.length">
                <td colspan="6" class="text-center text-medium-emphasis pa-4">{{ t('lms-admins_analytic.tables.quiz_pass_fail.no_data') }}</td>
              </tr>
              <tr v-for="q in quizPassFail" :key="q.course">
                <td class="font-weight-medium">{{ q.course }}</td>
                <td class="text-right">{{ q.attempts }}</td>
                <td class="text-right text-success">{{ q.passed }}</td>
                <td class="text-right text-error">{{ q.failed }}</td>
                <td class="text-right">
                  <VChip size="x-small" :color="passRateColor(q.pass_rate)" label>{{ q.pass_rate }}%</VChip>
                </td>
                <td class="text-right">{{ q.avg_score }}%</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>

      <VCol cols="12" md="6">
        <VCard>
          <VCardItem>
            <VCardTitle>{{ t('lms-admins_analytic.tables.top_learners.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('lms-admins_analytic.tables.top_learners.subtitle') }}</VCardSubtitle>
          </VCardItem>
          <VTable density="compact">
            <thead>
              <tr>
                <th>#</th>
                <th>{{ t('lms-admins_analytic.tables.top_learners.employee') }}</th>
                <th>{{ t('lms-admins_analytic.tables.top_learners.department') }}</th>
                <th class="text-right">{{ t('lms-admins_analytic.tables.top_learners.completions') }}</th>
                <th class="text-right">{{ t('lms-admins_analytic.tables.top_learners.avg_score') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!topLearners.length">
                <td colspan="5" class="text-center text-medium-emphasis pa-4">{{ t('lms-admins_analytic.tables.top_learners.no_data') }}</td>
              </tr>
              <tr v-for="(l, i) in topLearners" :key="l.name">
                <td><VChip size="x-small" :color="i < 3 ? 'primary' : 'default'" label>{{ i + 1 }}</VChip></td>
                <td class="font-weight-medium">{{ l.name }}</td>
                <td class="text-medium-emphasis">{{ l.department }}</td>
                <td class="text-right font-weight-medium">{{ l.completions }}</td>
                <td class="text-right">{{ l.avg_score != null ? `${l.avg_score}%` : '—' }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>

    <!-- Tables Row 2: Expiring Certs + Learnership Status -->
    <VRow>
      <VCol cols="12" md="6">
        <VCard>
          <VCardItem>
            <VCardTitle class="d-flex align-center gap-2">
              <VIcon color="warning" size="20">tabler-certificate</VIcon>
              {{ t('lms-admins_analytic.tables.expiring_certs.title') }}
            </VCardTitle>
            <VCardSubtitle>{{ t('lms-admins_analytic.tables.expiring_certs.subtitle') }}</VCardSubtitle>
          </VCardItem>
          <VTable density="compact">
            <thead>
              <tr>
                <th>{{ t('lms-admins_analytic.tables.expiring_certs.employee') }}</th>
                <th>{{ t('lms-admins_analytic.tables.expiring_certs.course') }}</th>
                <th>{{ t('lms-admins_analytic.tables.expiring_certs.expires') }}</th>
                <th class="text-right">{{ t('lms-admins_analytic.tables.expiring_certs.days_left') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!expiringCerts.length">
                <td colspan="4" class="text-center text-medium-emphasis pa-4">{{ t('lms-admins_analytic.tables.expiring_certs.no_data') }}</td>
              </tr>
              <tr v-for="c in expiringCerts" :key="`${c.employee}-${c.expires_at}`">
                <td class="font-weight-medium">{{ c.employee }}</td>
                <td>{{ c.course }}</td>
                <td class="text-caption text-medium-emphasis">{{ c.expires_at }}</td>
                <td class="text-right">
                  <VChip size="x-small" :color="daysLeftColor(c.days_left)" label>{{ c.days_left }}d</VChip>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>

      <VCol cols="12" md="6">
        <VCard>
          <VCardItem>
            <VCardTitle>{{ t('lms-admins_analytic.tables.learnership_status.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('lms-admins_analytic.tables.learnership_status.subtitle') }}</VCardSubtitle>
          </VCardItem>
          <VTable density="compact">
            <thead>
              <tr>
                <th>{{ t('lms-admins_analytic.tables.learnership_status.status') }}</th>
                <th class="text-right">{{ t('lms-admins_analytic.tables.learnership_status.count') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!learnershipStatus.length">
                <td colspan="2" class="text-center text-medium-emphasis pa-4">{{ t('lms-admins_analytic.tables.learnership_status.no_data') }}</td>
              </tr>
              <tr v-for="ls in learnershipStatus" :key="ls.status">
                <td>
                  <VChip size="x-small" :color="learnershipStatusColor[ls.status] ?? 'default'" label class="text-capitalize">
                    {{ ls.status }}
                  </VChip>
                </td>
                <td class="text-right font-weight-medium">{{ ls.count }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>

<style scoped>
</style>
