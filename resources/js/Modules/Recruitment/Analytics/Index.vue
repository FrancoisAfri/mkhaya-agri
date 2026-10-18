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
  total_jobs: number; open_jobs: number; filled_jobs: number
  total_applications: number; hired: number; hire_rate_pct: number
  avg_days_to_fill: number; avg_days_to_hire: number; offer_accept_rate: number
}
interface FilterOptions {
  departments: { id: number; name: string }[]
  sources:     string[]
  job_types:   string[]
  statuses:    string[]
}
interface ActiveFilters {
  department_id: number | null
  source: string | null
  job_type: string | null
  status: string | null
}

const props = defineProps<{
  period: string
  dateFrom: string | null
  dateTo: string | null
  filters: ActiveFilters
  filterOptions: FilterOptions
  kpis: Kpis
  applicationTrend: { month: string; total: number; hired: number }[]
  funnelConversion: { stage: string; count: number }[]
  sourceEffectiveness: { source: string; total: number; hired: number; hire_rate: number }[]
  jobsByStatus: { status: string; count: number }[]
  timeToFillByDept: { department: string; avg_days: number; filled: number }[]
  monthlyHires: { month: string; hires: number }[]
  offerConversion: { status: string; count: number }[]
  topOpenPositions: { id: number; title: string; department: string; job_type: string; created_at: string; applications: number }[]
}>()

// ── Filter state ──────────────────────────────────────────────────────────────
const filtersOpen   = ref(false)
const periodOptions = [
  { value: 'month',    label: t('recruitement_analytic.period_options.month') },
  { value: 'quarter',  label: t('recruitement_analytic.period_options.quarter') },
  { value: 'halfyear', label: t('recruitement_analytic.period_options.halfyear') },
  { value: 'year',     label: t('recruitement_analytic.period_options.year') },
  { value: 'lastyear', label: t('recruitement_analytic.period_options.lastyear') },
]

const form = ref({
  period:        props.period ?? 'year',
  date_from:     props.dateFrom ?? '',
  date_to:       props.dateTo   ?? '',
  department_id: props.filters.department_id ? String(props.filters.department_id) : '',
  source:        props.filters.source    ?? '',
  job_type:      props.filters.job_type  ?? '',
  status:        props.filters.status    ?? '',
})

const useCustomDates = ref(!!(props.dateFrom || props.dateTo))

const activeFilterCount = computed(() => {
  const f = props.filters
  return [f.department_id, f.source, f.job_type, f.status].filter(Boolean).length
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
  if (form.value.department_id) params.department_id = form.value.department_id
  if (form.value.source)        params.source        = form.value.source
  if (form.value.job_type)      params.job_type      = form.value.job_type
  if (form.value.status)        params.status        = form.value.status
  router.get(window.location.pathname, params, { preserveScroll: true })
}

function clearFilters() {
  form.value = { period: 'year', date_from: '', date_to: '', department_id: '', source: '', job_type: '', status: '' }
  useCustomDates.value = false
  router.get(window.location.pathname, { period: 'year' }, { preserveScroll: true })
}

// ── Formatters ────────────────────────────────────────────────────────────────
const fmtN = (v: number) => Number(v).toLocaleString('en-ZA')
const monthLabel = (m: string) => {
  const [y, mo] = m.split('-')
  return new Date(+y, +mo - 1).toLocaleString('en-ZA', { month: 'short', year: '2-digit' })
}

// ── KPI cards ─────────────────────────────────────────────────────────────────
const kpiCards = computed(() => [
  { title: t('recruitement_analytic.kpi_cards.total_jobs'),          value: fmtN(props.kpis.total_jobs),          sub: t('recruitement_analytic.kpi_cards.total_jobs_sub', { open: props.kpis.open_jobs, filled: props.kpis.filled_jobs }),  icon: 'tabler-briefcase',         color: 'primary' },
  { title: t('recruitement_analytic.kpi_cards.applications'),        value: fmtN(props.kpis.total_applications),   sub: t('recruitement_analytic.kpi_cards.applications_sub', { hired: props.kpis.hired }),                                       icon: 'tabler-users',  color: 'info' },
  { title: t('recruitement_analytic.kpi_cards.hire_rate'),           value: `${props.kpis.hire_rate_pct}%`,        sub: t('recruitement_analytic.kpi_cards.hire_rate_sub'),                                            icon: 'tabler-user-check',     color: 'success' },
  { title: t('recruitement_analytic.kpi_cards.avg_days_to_fill'),    value: `${props.kpis.avg_days_to_fill}d`,     sub: t('recruitement_analytic.kpi_cards.avg_days_to_fill_sub'),                                        icon: 'tabler-clock-bolt',        color: 'warning' },
  { title: t('recruitement_analytic.kpi_cards.avg_time_to_hire'),    value: `${props.kpis.avg_days_to_hire}d`,     sub: t('recruitement_analytic.kpi_cards.avg_time_to_hire_sub'),                                        icon: 'tabler-clock-hour-3',      color: 'info' },
  { title: t('recruitement_analytic.kpi_cards.offer_accept_rate'),   value: `${props.kpis.offer_accept_rate}%`,    sub: t('recruitement_analytic.kpi_cards.offer_accept_rate_sub'),                                                 icon: 'tabler-handshake',         color: 'secondary' },
  { title: t('recruitement_analytic.kpi_cards.hires_made'),          value: fmtN(props.kpis.hired),                sub: t('recruitement_analytic.kpi_cards.hires_made_sub'),                                              icon: 'tabler-user-star',      color: 'primary' },
])

// ── Application trend (line) ──────────────────────────────────────────────────
const appTrendOptions = computed<ApexOptions>(() => ({
  chart:   { type: 'line', toolbar: { show: false }, zoom: { enabled: false } },
  stroke:  { curve: 'smooth', width: [3, 2] },
  colors:  ['#696cff', success],
  xaxis:   { categories: props.applicationTrend.map(r => monthLabel(r.month)) },
  yaxis:   { labels: { formatter: (v: number) => String(Math.round(v)) } },
  tooltip: { y: { formatter: (v: number) => t('recruitement_analytic.charts.app_trend.tooltip_apps', { count: v }) } },
  legend:  { position: 'top' },
  markers: { size: 4 },
  grid:    { borderColor: '#f1f3f4' },
}))
const appTrendSeries = computed(() => [
  { name: t('recruitement_analytic.charts.app_trend.applications'), data: props.applicationTrend.map(r => r.total) },
  { name: t('recruitement_analytic.charts.app_trend.hires'),        data: props.applicationTrend.map(r => r.hired) },
])

// ── Funnel (bar) ──────────────────────────────────────────────────────────────
const funnelOptions = computed<ApexOptions>(() => ({
  chart:   { type: 'bar', toolbar: { show: false } },
  plotOptions: { bar: { horizontal: true, borderRadius: 4, distributed: true } },
  colors:  ['#696cff', info, warning, error, success],
  xaxis:   { categories: props.funnelConversion.map(r => r.stage.charAt(0).toUpperCase() + r.stage.slice(1)) },
  tooltip: { y: { formatter: (v: number) => t('recruitement_analytic.charts.funnel.tooltip', { count: v }) } },
  dataLabels: { enabled: true, formatter: (v: number) => v },
  legend: { show: false },
  grid: { borderColor: '#f1f3f4' },
}))
const funnelSeries = computed(() => [
  { name: 'Candidates', data: props.funnelConversion.map(r => r.count) },
])

// ── Monthly hires area ────────────────────────────────────────────────────────
const monthlyHiresOptions = computed<ApexOptions>(() => ({
  chart:   { type: 'area', toolbar: { show: false }, zoom: { enabled: false } },
  stroke:  { curve: 'smooth', width: 2 },
  fill:    { type: 'gradient', gradient: { opacityFrom: 0.4, opacityTo: 0.05 } },
  colors:  [success],
  xaxis:   { categories: props.monthlyHires.map(r => monthLabel(r.month)) },
  yaxis:   { labels: { formatter: (v: number) => String(Math.round(v)) } },
  tooltip: { y: { formatter: (v: number) => t('recruitement_analytic.charts.monthly_hires.tooltip', { count: v }) } },
  grid:    { borderColor: '#f1f3f4' },
}))
const monthlyHiresSeries = computed(() => [
  { name: t('recruitement_analytic.charts.monthly_hires.title'), data: props.monthlyHires.map(r => r.hires) },
])

// ── Jobs by status donut ──────────────────────────────────────────────────────
const jobStatusColors: Record<string, string> = {
  open: '#696cff', filled: success, closed: secondary, draft: warning, cancelled: error,
}
const jobsByStatusOptions = computed<ApexOptions>(() => ({
  chart:   { type: 'donut' },
  labels:  props.jobsByStatus.map(r => r.status),
  colors:  props.jobsByStatus.map(r => jobStatusColors[r.status] ?? secondary),
  legend:  { position: 'bottom' },
  plotOptions: { pie: { donut: { size: '65%', labels: { show: true, total: { show: true, label: t('recruitement_analytic.charts.jobs_by_status.total_label') } } } } },
  dataLabels: { enabled: false },
}))
const jobsByStatusSeries = computed(() => props.jobsByStatus.map(r => r.count))

// ── Source effectiveness bar ──────────────────────────────────────────────────
const sourceOptions = computed<ApexOptions>(() => ({
  chart:   { type: 'bar', toolbar: { show: false } },
  plotOptions: { bar: { borderRadius: 4, columnWidth: '55%' } },
  colors:  ['#696cff', success],
  xaxis:   { categories: props.sourceEffectiveness.map(r => r.source) },
  yaxis:   { labels: { formatter: (v: number) => String(Math.round(v)) } },
  tooltip: { y: [{ formatter: (v: number) => t('recruitement_analytic.charts.source_effectiveness.tooltip_apps', { count: v }) }, { formatter: (v: number) => t('recruitement_analytic.charts.source_effectiveness.tooltip_hires', { count: v }) }] },
  legend:  { position: 'top' },
  dataLabels: { enabled: false },
  grid: { borderColor: '#f1f3f4' },
}))
const sourceSeries = computed(() => [
  { name: t('recruitement_analytic.charts.source_effectiveness.applications'), data: props.sourceEffectiveness.map(r => r.total) },
  { name: t('recruitement_analytic.charts.source_effectiveness.hires'),        data: props.sourceEffectiveness.map(r => r.hired) },
])

// ── Offer conversion donut ────────────────────────────────────────────────────
const offerColors: Record<string, string> = {
  accepted: success, declined: error, pending: warning, withdrawn: secondary,
}
const offerOptions = computed<ApexOptions>(() => ({
  chart:   { type: 'pie' },
  labels:  props.offerConversion.map(r => r.status),
  colors:  props.offerConversion.map(r => offerColors[r.status] ?? secondary),
  legend:  { position: 'bottom' },
  dataLabels: { enabled: true },
}))
const offerSeries = computed(() => props.offerConversion.map(r => r.count))
</script>

<template>
  <div class="pa-4">
    <!-- ── Header ─────────────────────────────────────────────────────────── -->
    <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-4">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('recruitement_analytic.breadcrumb') }}</div>
        <h4 class="text-h4 font-weight-bold">{{ t('recruitement_analytic.title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('recruitement_analytic.subtitle') }}</p>
      </div>
      <div class="d-flex align-center gap-2 flex-wrap">
        <VBtn variant="tonal" prepend-icon="tabler-adjustments-horizontal" @click="filtersOpen = !filtersOpen">
          {{ t('recruitement_analytic.filter_button') }}
          <VBadge v-if="activeFilterCount > 0" :content="activeFilterCount" color="primary" floating />
        </VBtn>
        <VBtn variant="tonal" prepend-icon="tabler-file-analytics" href="/recruitment/reports">{{ t('recruitement_analytic.reports_button') }}</VBtn>
      </div>
    </div>

    <!-- ── Filter panel ───────────────────────────────────────────────────── -->
    <VExpandTransition>
      <VCard v-if="filtersOpen" class="mb-5" variant="outlined">
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12" class="pb-1">
              <VBtnToggle v-model="useCustomDates" density="compact" variant="outlined" mandatory>
                <VBtn :value="false" size="small">{{ t('recruitement_analytic.filter_panel.period_preset') }}</VBtn>
                <VBtn :value="true"  size="small">{{ t('recruitement_analytic.filter_panel.custom_dates') }}</VBtn>
              </VBtnToggle>
            </VCol>
            <VCol v-if="!useCustomDates" cols="12" sm="6" md="3">
              <VSelect v-model="form.period" :items="periodOptions" item-title="label" item-value="value" :label="t('recruitement_analytic.filter_panel.period')" density="compact" variant="outlined" hide-details />
            </VCol>
            <template v-if="useCustomDates">
              <VCol cols="12" sm="6" md="3">
                <VTextField v-model="form.date_from" type="date" :label="t('recruitement_analytic.filter_panel.from_date')" density="compact" variant="outlined" hide-details />
              </VCol>
              <VCol cols="12" sm="6" md="3">
                <VTextField v-model="form.date_to" type="date" :label="t('recruitement_analytic.filter_panel.to_date')" density="compact" variant="outlined" hide-details />
              </VCol>
            </template>
            <VCol cols="12" sm="6" md="3">
              <VSelect v-model="form.department_id" :items="filterOptions.departments" item-title="name" item-value="id" :label="t('recruitement_analytic.filter_panel.department')" density="compact" variant="outlined" hide-details clearable />
            </VCol>
            <VCol cols="12" sm="6" md="3">
              <VSelect v-model="form.source" :items="filterOptions.sources" :label="t('recruitement_analytic.filter_panel.source')" density="compact" variant="outlined" hide-details clearable />
            </VCol>
            <VCol cols="12" sm="6" md="3">
              <VSelect v-model="form.job_type" :items="filterOptions.job_types" :label="t('recruitement_analytic.filter_panel.job_type')" density="compact" variant="outlined" hide-details clearable />
            </VCol>
            <VCol cols="12" sm="6" md="3">
              <VSelect v-model="form.status" :items="filterOptions.statuses" :label="t('recruitement_analytic.filter_panel.job_status')" density="compact" variant="outlined" hide-details clearable />
            </VCol>
            <VCol cols="12" class="d-flex gap-2 pt-2">
              <VBtn color="primary" prepend-icon="tabler-search" @click="applyFilters">{{ t('recruitement_analytic.filter_panel.apply') }}</VBtn>
              <VBtn variant="tonal" prepend-icon="tabler-x" @click="clearFilters">{{ t('recruitement_analytic.filter_panel.clear_all') }}</VBtn>
            </VCol>
          </VRow>
        </VCardText>
      </VCard>
    </VExpandTransition>

    <!-- Active filter chips -->
    <div v-if="activeFilterCount > 0" class="d-flex flex-wrap gap-2 mb-4">
      <VChip v-if="dateFrom || dateTo" size="small" closable color="primary" @click:close="clearFilters">{{ dateFrom }} → {{ dateTo }}</VChip>
      <VChip v-else size="small" color="primary" variant="tonal">{{ periodOptions.find(p => p.value === period)?.label }}</VChip>
      <VChip v-if="filters.department_id" size="small" closable color="secondary" variant="tonal" @click:close="() => { form.department_id = ''; applyFilters() }">{{ t('recruitement_analytic.filter_chips.dept_prefix') }} {{ filterOptions.departments.find(d => d.id === filters.department_id)?.name }}</VChip>
      <VChip v-if="filters.source"   size="small" closable color="secondary" variant="tonal" @click:close="() => { form.source = ''; applyFilters() }">{{ t('recruitement_analytic.filter_chips.source_prefix') }} {{ filters.source }}</VChip>
      <VChip v-if="filters.job_type" size="small" closable color="secondary" variant="tonal" @click:close="() => { form.job_type = ''; applyFilters() }">{{ t('recruitement_analytic.filter_chips.type_prefix') }} {{ filters.job_type }}</VChip>
      <VChip v-if="filters.status"   size="small" closable color="secondary" variant="tonal" @click:close="() => { form.status = ''; applyFilters() }">{{ t('recruitement_analytic.filter_chips.status_prefix') }} {{ filters.status }}</VChip>
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

    <!-- ── Row 2: Application Trend + Jobs by Status ──────────────────────── -->
    <VRow class="mb-4">
      <VCol cols="12" md="8">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>{{ t('recruitement_analytic.charts.app_trend.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('recruitement_analytic.charts.app_trend.subtitle') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts v-if="applicationTrend.length" type="line" :height="280" :options="appTrendOptions" :series="appTrendSeries" />
            <VEmptyState v-else :icon="'tabler-chart-line'" :title="t('recruitement_analytic.charts.app_trend.empty_title')" :text="t('recruitement_analytic.charts.app_trend.empty_text')" size="80" />
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="4">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>{{ t('recruitement_analytic.charts.jobs_by_status.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('recruitement_analytic.charts.jobs_by_status.subtitle') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts v-if="jobsByStatus.length" type="donut" :height="280" :options="jobsByStatusOptions" :series="jobsByStatusSeries" />
            <VEmptyState v-else :icon="'tabler-briefcase'" :title="t('recruitement_analytic.charts.jobs_by_status.empty_title')" size="60" />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Row 3: Funnel + Monthly Hires ─────────────────────────────────── -->
    <VRow class="mb-4">
      <VCol cols="12" md="6">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>{{ t('recruitement_analytic.charts.funnel.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('recruitement_analytic.charts.funnel.subtitle') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts v-if="funnelConversion.length" type="bar" :height="280" :options="funnelOptions" :series="funnelSeries" />
            <VEmptyState v-else :icon="'tabler-filter'" :title="t('recruitement_analytic.charts.funnel.empty_title')" size="70" />
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="6">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>{{ t('recruitement_analytic.charts.monthly_hires.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('recruitement_analytic.charts.monthly_hires.subtitle') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts v-if="monthlyHires.length" type="area" :height="280" :options="monthlyHiresOptions" :series="monthlyHiresSeries" />
            <VEmptyState v-else :icon="'tabler-user-plus'" :title="t('recruitement_analytic.charts.monthly_hires.empty_title')" size="70" />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Row 4: Source Effectiveness + Offer Conversion ────────────────── -->
    <VRow class="mb-4">
      <VCol cols="12" md="8">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>{{ t('recruitement_analytic.charts.source_effectiveness.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('recruitement_analytic.charts.source_effectiveness.subtitle') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts v-if="sourceEffectiveness.length" type="bar" :height="260" :options="sourceOptions" :series="sourceSeries" />
            <VEmptyState v-else :icon="'tabler-world'" :title="t('recruitement_analytic.charts.source_effectiveness.empty_title')" size="70" />
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="4">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>{{ t('recruitement_analytic.charts.offer_conversion.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('recruitement_analytic.charts.offer_conversion.subtitle') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts v-if="offerConversion.length" type="pie" :height="260" :options="offerOptions" :series="offerSeries" />
            <VEmptyState v-else :icon="'tabler-handshake'" :title="t('recruitement_analytic.charts.offer_conversion.empty_title')" size="70" />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Row 5: Time to Fill by Dept + Top Open Positions ─────────────── -->
    <VRow>
      <VCol cols="12" md="5">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>{{ t('recruitement_analytic.tables.time_to_fill.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('recruitement_analytic.tables.time_to_fill.subtitle') }}</VCardSubtitle>
          </VCardItem>
          <VTable density="compact">
            <thead>
              <tr>
                <th>{{ t('recruitement_analytic.tables.time_to_fill.department') }}</th>
                <th class="text-right">{{ t('recruitement_analytic.tables.time_to_fill.avg_days') }}</th>
                <th class="text-right">{{ t('recruitement_analytic.tables.time_to_fill.filled') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!timeToFillByDept.length">
                <td colspan="3" class="text-center text-medium-emphasis pa-4">{{ t('recruitement_analytic.tables.time_to_fill.no_data') }}</td>
              </tr>
              <tr v-for="d in timeToFillByDept" :key="d.department">
                <td class="font-weight-medium">{{ d.department }}</td>
                <td class="text-right"><VChip size="x-small" :color="d.avg_days > 30 ? 'warning' : 'success'" label>{{ d.avg_days }}d</VChip></td>
                <td class="text-right text-medium-emphasis">{{ d.filled }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>

      <VCol cols="12" md="7">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>{{ t('recruitement_analytic.tables.top_open_positions.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('recruitement_analytic.tables.top_open_positions.subtitle') }}</VCardSubtitle>
          </VCardItem>
          <VTable density="compact">
            <thead>
              <tr>
                <th>{{ t('recruitement_analytic.tables.top_open_positions.position') }}</th>
                <th>{{ t('recruitement_analytic.tables.top_open_positions.department') }}</th>
                <th>{{ t('recruitement_analytic.tables.top_open_positions.type') }}</th>
                <th class="text-right">{{ t('recruitement_analytic.tables.top_open_positions.applications') }}</th>
                <th>{{ t('recruitement_analytic.tables.top_open_positions.posted') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!topOpenPositions.length">
                <td colspan="5" class="text-center text-medium-emphasis pa-4">{{ t('recruitement_analytic.tables.top_open_positions.no_data') }}</td>
              </tr>
              <tr v-for="j in topOpenPositions" :key="j.id">
                <td class="font-weight-medium">{{ j.title }}</td>
                <td class="text-medium-emphasis">{{ j.department }}</td>
                <td><VChip size="x-small" label>{{ j.job_type || '—' }}</VChip></td>
                <td class="text-right font-weight-medium">{{ j.applications }}</td>
                <td class="text-caption text-medium-emphasis">{{ new Date(j.created_at).toLocaleDateString('en-ZA') }}</td>
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
