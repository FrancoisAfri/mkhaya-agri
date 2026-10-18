<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useTheme } from 'vuetify'
import VueApexCharts from 'vue3-apexcharts'
import type { ApexOptions } from 'apexcharts'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
useTheme()

const success = '#28c76f'; const warning = '#ff9f43'; const error = '#ea5455'; const info = '#00cfe8'; const secondary = '#82868b'

interface Kpis { total_records: number; late_count: number; late_pct: number; early_departure_count: number; avg_hours_worked: number; total_overtime_hrs: number; avg_late_minutes: number; approved_pct: number }
interface FilterOptions { departments: { id: number; name: string }[]; shifts: { id: number; name: string }[]; statuses: { id: number; name: string }[] }
interface ActiveFilters { department_id: number | null; shift_id: number | null; status_id: number | null }

const props = defineProps<{
  period: string; dateFrom: string | null; dateTo: string | null
  filters: ActiveFilters; filterOptions: FilterOptions; kpis: Kpis
  attendanceTrend: { month: string; total: number; late: number; early_departure: number }[]
  latenessTrend: { month: string; late_count: number; avg_late_mins: number }[]
  hoursTrend: { month: string; avg_hours: number; total_hours: number }[]
  byDepartment: { department: string; records: number; late_count: number; avg_hours: number }[]
  byShift: { shift: string; count: number; avg_hours: number }[]
  byStatus: { status: string; count: number }[]
  overtimeTrend: { month: string; total_ot: number; employees_with_ot: number }[]
  topLate: { name: string; late_count: number; avg_late_mins: number; total_late_mins: number }[]
  topOvertime: { name: string; total_ot: number; ot_days: number }[]
}>()

const filtersOpen = ref(false)

const periodOptions = computed(() => [
  { value: 'month', label: t('attendance_analytics.period_last_1_month') },
  { value: 'quarter', label: t('attendance_analytics.period_last_3_months') },
  { value: 'halfyear', label: t('attendance_analytics.period_last_6_months') },
  { value: 'year', label: t('attendance_analytics.period_last_12_months') },
  { value: 'lastyear', label: 'Last Year' },
])

const form = ref({
  period: props.period ?? 'month', date_from: props.dateFrom ?? '', date_to: props.dateTo ?? '',
  department_id: props.filters.department_id ? String(props.filters.department_id) : '',
  shift_id: props.filters.shift_id ? String(props.filters.shift_id) : '',
  status_id: props.filters.status_id ? String(props.filters.status_id) : '',
})
const useCustomDates = ref(!!(props.dateFrom || props.dateTo))
const activeFilterCount = computed(() => [props.filters.department_id, props.filters.shift_id, props.filters.status_id].filter(Boolean).length + (props.dateFrom || props.dateTo ? 1 : 0))

function applyFilters() {
  const params: Record<string, string> = {}
  if (useCustomDates.value) { if (form.value.date_from) params.date_from = form.value.date_from; if (form.value.date_to) params.date_to = form.value.date_to }
  else params.period = form.value.period
  if (form.value.department_id) params.department_id = form.value.department_id
  if (form.value.shift_id) params.shift_id = form.value.shift_id
  if (form.value.status_id) params.status_id = form.value.status_id
  router.get(window.location.pathname, params, { preserveScroll: true })
}
function clearFilters() {
  form.value = { period: 'month', date_from: '', date_to: '', department_id: '', shift_id: '', status_id: '' }
  useCustomDates.value = false
  router.get(window.location.pathname, { period: 'month' }, { preserveScroll: true })
}

const fmtN = (v: number) => Number(v).toLocaleString('en-ZA')
const monthLabel = (m: string) => { const [y, mo] = m.split('-'); return new Date(+y, +mo - 1).toLocaleString('en-ZA', { month: 'short', year: '2-digit' }) }

const kpiCards = computed(() => [
  { title: t('attendance_analytics.total_records'), value: fmtN(props.kpis.total_records), sub: t('attendance_analytics.records_sub'), icon: 'tabler-account-clock', color: 'primary' },
  { title: t('attendance_analytics.late_pct'), value: `${props.kpis.late_pct}%`, sub: t('attendance_analytics.late_sub', { count: props.kpis.late_count }), icon: 'tabler-clock-alert', color: 'error' },
  { title: t('attendance_analytics.avg_hours_worked'), value: `${props.kpis.avg_hours_worked}h`, sub: t('attendance_analytics.hours_sub'), icon: 'tabler-timer-outline', color: 'info' },
  { title: t('attendance_analytics.total_overtime'), value: `${props.kpis.total_overtime_hrs}h`, sub: t('attendance_analytics.overtime_sub'), icon: 'tabler-clock-plus', color: 'warning' },
  { title: t('attendance_analytics.avg_late_minutes'), value: `${props.kpis.avg_late_minutes}m`, sub: t('attendance_analytics.late_min_sub'), icon: 'tabler-timer-alert', color: 'secondary' },
  { title: t('attendance_analytics.manager_approved'), value: `${props.kpis.approved_pct}%`, sub: t('attendance_analytics.approved_sub'), icon: 'tabler-check-decagram', color: 'success' },
])

const attendTrendOptions = computed<ApexOptions>(() => ({
  chart: { type: 'line', toolbar: { show: false }, zoom: { enabled: false } }, stroke: { curve: 'smooth', width: [3, 2, 2] }, colors: ['#696cff', error, warning],
  xaxis: { categories: props.attendanceTrend.map(r => monthLabel(r.month)) }, yaxis: { labels: { formatter: (v: number) => String(Math.round(v)) } },
  tooltip: { y: { formatter: (v: number) => `${v} records` } }, legend: { position: 'top' }, markers: { size: 4 }, grid: { borderColor: '#f1f3f4' },
}))
const attendTrendSeries = computed(() => [
  { name: 'Total', data: props.attendanceTrend.map(r => r.total) },
  { name: 'Late', data: props.attendanceTrend.map(r => r.late) },
  { name: 'Early Departure', data: props.attendanceTrend.map(r => r.early_departure) },
])

const hoursTrendOptions = computed<ApexOptions>(() => ({
  chart: { type: 'area', toolbar: { show: false }, zoom: { enabled: false } }, stroke: { curve: 'smooth', width: 2 },
  fill: { type: 'gradient', gradient: { opacityFrom: 0.4, opacityTo: 0.05 } }, colors: [success],
  xaxis: { categories: props.hoursTrend.map(r => monthLabel(r.month)) }, yaxis: { labels: { formatter: (v: number) => `${v}h` } },
  tooltip: { y: { formatter: (v: number) => `${v} hours` } }, grid: { borderColor: '#f1f3f4' },
}))
const hoursTrendSeries = computed(() => [{ name: 'Avg Hours', data: props.hoursTrend.map(r => r.avg_hours) }])

const otTrendOptions = computed<ApexOptions>(() => ({
  chart: { type: 'bar', toolbar: { show: false } }, plotOptions: { bar: { borderRadius: 4, columnWidth: '55%' } }, colors: [warning],
  xaxis: { categories: props.overtimeTrend.map(r => monthLabel(r.month)) }, yaxis: { labels: { formatter: (v: number) => `${v}h` } },
  tooltip: { y: { formatter: (v: number) => `${v} hours` } }, dataLabels: { enabled: false }, grid: { borderColor: '#f1f3f4' },
}))
const otTrendSeries = computed(() => [{ name: 'Overtime Hours', data: props.overtimeTrend.map(r => r.total_ot) }])

const byStatusOptions = computed<ApexOptions>(() => ({
  chart: { type: 'donut' }, labels: props.byStatus.map(r => r.status), colors: ['#696cff', success, warning, info, error],
  legend: { position: 'bottom' }, plotOptions: { pie: { donut: { size: '65%', labels: { show: true, total: { show: true, label: 'Total' } } } } }, dataLabels: { enabled: false },
}))
const byStatusSeries = computed(() => props.byStatus.map(r => r.count))

const deptHeaders = computed(() => [
  { title: t('attendance_analytics.table_department'), key: 'department' },
  { title: t('attendance_analytics.table_records'), key: 'records' },
  { title: t('attendance_analytics.table_late_count'), key: 'late_count' },
  { title: t('attendance_analytics.table_late_pct'), key: 'late_pct_display' },
  { title: t('attendance_analytics.table_avg_hours'), key: 'avg_hours' },
])
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-4">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('attendance_analytics.bi_breadcrumb') }}</div>
        <h4 class="text-h4 font-weight-bold">{{ t('attendance_analytics.bi_title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('attendance_analytics.bi_subtitle') }}</p>
      </div>
      <div class="d-flex align-center gap-2 flex-wrap">
        <VBtn variant="tonal" prepend-icon="tabler-tune-variant" @click="filtersOpen = !filtersOpen">
          {{ t('attendance_analytics.filters') }} <VBadge v-if="activeFilterCount > 0" :content="activeFilterCount" color="primary" floating />
        </VBtn>
        <VBtn variant="tonal" prepend-icon="tabler-file-chart" href="/attendance/reports">{{ t('attendance_analytics.reports') }}</VBtn>
      </div>
    </div>

    <VExpandTransition>
      <VCard v-if="filtersOpen" class="mb-5" variant="outlined">
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12" class="pb-1">
              <VBtnToggle v-model="useCustomDates" density="compact" variant="outlined" mandatory>
                <VBtn :value="false" size="small">{{ t('attendance_analytics.period_preset') }}</VBtn>
                <VBtn :value="true" size="small">{{ t('attendance_analytics.custom_dates') }}</VBtn>
              </VBtnToggle>
            </VCol>
            <VCol v-if="!useCustomDates" cols="12" sm="6" md="3">
              <VSelect v-model="form.period" :items="periodOptions" item-title="label" item-value="value" :label="t('attendance_analytics.filter_period')" density="compact" variant="outlined" hide-details />
            </VCol>
            <template v-if="useCustomDates">
              <VCol cols="12" sm="6" md="3"><VTextField v-model="form.date_from" type="date" :label="t('attendance_analytics.filter_from_date')" density="compact" variant="outlined" hide-details /></VCol>
              <VCol cols="12" sm="6" md="3"><VTextField v-model="form.date_to" type="date" :label="t('attendance_analytics.filter_to_date')" density="compact" variant="outlined" hide-details /></VCol>
            </template>
            <VCol cols="12" sm="6" md="3"><VSelect v-model="form.department_id" :items="filterOptions.departments" item-title="name" item-value="id" :label="t('attendance_analytics.filter_department')" density="compact" variant="outlined" hide-details clearable /></VCol>
            <VCol cols="12" sm="6" md="3"><VSelect v-model="form.shift_id" :items="filterOptions.shifts" item-title="name" item-value="id" :label="t('attendance_analytics.filter_shift')" density="compact" variant="outlined" hide-details clearable /></VCol>
            <VCol cols="12" sm="6" md="3"><VSelect v-model="form.status_id" :items="filterOptions.statuses" item-title="name" item-value="id" :label="t('attendance_analytics.filter_status')" density="compact" variant="outlined" hide-details clearable /></VCol>
            <VCol cols="12" class="d-flex gap-2 pt-2">
              <VBtn color="primary" prepend-icon="tabler-magnify" @click="applyFilters">{{ t('attendance_analytics.apply_filters') }}</VBtn>
              <VBtn variant="tonal" prepend-icon="tabler-close" @click="clearFilters">{{ t('attendance_analytics.clear_all') }}</VBtn>
            </VCol>
          </VRow>
        </VCardText>
      </VCard>
    </VExpandTransition>

    <div v-if="activeFilterCount > 0" class="d-flex flex-wrap gap-2 mb-4">
      <VChip v-if="dateFrom || dateTo" size="small" closable color="primary" @click:close="clearFilters">{{ dateFrom }} → {{ dateTo }}</VChip>
      <VChip v-else size="small" color="primary" variant="tonal">{{ periodOptions.find(p => p.value === period)?.label }}</VChip>
      <VChip v-if="filters.department_id" size="small" closable color="secondary" variant="tonal" @click:close="() => { form.department_id = ''; applyFilters() }">Dept: {{ filterOptions.departments.find(d => d.id === filters.department_id)?.name }}</VChip>
      <VChip v-if="filters.shift_id" size="small" closable color="secondary" variant="tonal" @click:close="() => { form.shift_id = ''; applyFilters() }">Shift: {{ filterOptions.shifts.find(s => s.id === filters.shift_id)?.name }}</VChip>
      <VChip v-if="filters.status_id" size="small" closable color="secondary" variant="tonal" @click:close="() => { form.status_id = ''; applyFilters() }">Status: {{ filterOptions.statuses.find(s => s.id === filters.status_id)?.name }}</VChip>
    </div>

    <VRow class="mb-4">
      <VCol v-for="card in kpiCards" :key="card.title" cols="12" sm="6" md="4" lg="2">
        <VCard height="100%">
          <VCardText class="pa-4">
            <div class="d-flex align-center justify-space-between mb-2"><VIcon :color="card.color" size="32">{{ card.icon }}</VIcon><VChip :color="card.color" size="x-small" label variant="tonal">{{ card.color }}</VChip></div>
            <div class="text-h5 font-weight-bold">{{ card.value }}</div>
            <div class="text-subtitle-2 font-weight-semibold mt-1">{{ card.title }}</div>
            <div class="text-caption text-medium-emphasis">{{ card.sub }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow class="mb-4">
      <VCol cols="12" md="8">
        <VCard height="100%">
          <VCardItem><VCardTitle>{{ t('attendance_analytics.attendance_trend') }}</VCardTitle><VCardSubtitle>{{ t('attendance_analytics.trend_sub') }}</VCardSubtitle></VCardItem>
          <VCardText>
            <VueApexCharts v-if="attendanceTrend.length" type="line" :height="280" :options="attendTrendOptions" :series="attendTrendSeries" />
            <VEmptyState v-else icon="tabler-chart-line" :title="t('attendance_analytics.no_attendance_data')" :text="t('attendance_analytics.no_records_found')" size="80" />
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="4">
        <VCard height="100%">
          <VCardItem><VCardTitle>{{ t('attendance_analytics.by_status') }}</VCardTitle><VCardSubtitle>{{ t('attendance_analytics.status_sub') }}</VCardSubtitle></VCardItem>
          <VCardText>
            <VueApexCharts v-if="byStatus.length" type="donut" :height="280" :options="byStatusOptions" :series="byStatusSeries" />
            <VEmptyState v-else icon="tabler-calendar-check" :title="t('attendance_analytics.no_status_data')" size="60" />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow class="mb-4">
      <VCol cols="12" md="6">
        <VCard height="100%">
          <VCardItem><VCardTitle>{{ t('attendance_analytics.avg_hours_trend') }}</VCardTitle><VCardSubtitle>{{ t('attendance_analytics.hours_trend_sub') }}</VCardSubtitle></VCardItem>
          <VCardText>
            <VueApexCharts v-if="hoursTrend.length" type="area" :height="260" :options="hoursTrendOptions" :series="hoursTrendSeries" />
            <VEmptyState v-else icon="tabler-timer" :title="t('attendance_analytics.no_hours_data')" size="70" />
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="6">
        <VCard height="100%">
          <VCardItem><VCardTitle>{{ t('attendance_analytics.overtime_trend') }}</VCardTitle><VCardSubtitle>{{ t('attendance_analytics.overtime_trend_sub') }}</VCardSubtitle></VCardItem>
          <VCardText>
            <VueApexCharts v-if="overtimeTrend.length" type="bar" :height="260" :options="otTrendOptions" :series="otTrendSeries" />
            <VEmptyState v-else icon="tabler-clock-plus" :title="t('attendance_analytics.no_overtime_data')" size="70" />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow class="mb-4">
      <VCol cols="12" md="6">
        <VCard>
          <VCardItem><VCardTitle class="d-flex align-center gap-2"><VIcon color="error" size="20">tabler-clock-alert</VIcon>{{ t('attendance_analytics.most_late_employees') }}</VCardTitle><VCardSubtitle>{{ t('attendance_analytics.late_employees_sub') }}</VCardSubtitle></VCardItem>
          <VTable density="compact">
            <thead><tr><th>{{ t('attendance_analytics.table_rank') }}</th><th>{{ t('attendance_analytics.table_employee') }}</th><th class="text-right">{{ t('attendance_analytics.table_late_count') }}</th><th class="text-right">{{ t('attendance_analytics.table_avg_late_min') }}</th></tr></thead>
            <tbody>
              <tr v-if="!topLate.length"><td colspan="4" class="text-center text-medium-emphasis pa-4">{{ t('attendance_analytics.no_late_data') }}</td></tr>
              <tr v-for="(e, i) in topLate" :key="e.name">
                <td><VChip size="x-small" :color="i < 3 ? 'error' : 'default'" label>{{ i + 1 }}</VChip></td>
                <td class="font-weight-medium">{{ e.name }}</td>
                <td class="text-right">{{ fmtN(e.late_count) }}</td>
                <td class="text-right"><VChip size="x-small" :color="e.avg_late_mins > 30 ? 'error' : 'warning'" label>{{ e.avg_late_mins }}m</VChip></td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>

      <VCol cols="12" md="6">
        <VCard>
          <VCardItem><VCardTitle>{{ t('attendance_analytics.top_overtime_employees') }}</VCardTitle><VCardSubtitle>{{ t('attendance_analytics.overtime_employees_sub') }}</VCardSubtitle></VCardItem>
          <VTable density="compact">
            <thead><tr><th>{{ t('attendance_analytics.table_rank') }}</th><th>{{ t('attendance_analytics.table_employee') }}</th><th class="text-right">{{ t('attendance_analytics.table_total_ot_hrs') }}</th><th class="text-right">{{ t('attendance_analytics.table_ot_days') }}</th></tr></thead>
            <tbody>
              <tr v-if="!topOvertime.length"><td colspan="4" class="text-center text-medium-emphasis pa-4">{{ t('attendance_analytics.no_overtime_data_table') }}</td></tr>
              <tr v-for="(e, i) in topOvertime" :key="e.name">
                <td><VChip size="x-small" :color="i < 3 ? 'warning' : 'default'" label>{{ i + 1 }}</VChip></td>
                <td class="font-weight-medium">{{ e.name }}</td>
                <td class="text-right font-weight-medium">{{ e.total_ot }}h</td>
                <td class="text-right text-medium-emphasis">{{ e.ot_days }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>

    <VRow v-if="byDepartment.length">
      <VCol cols="12">
        <VCard>
          <VCardItem><VCardTitle>{{ t('attendance_analytics.department_summary') }}</VCardTitle><VCardSubtitle>{{ t('attendance_analytics.dept_summary_sub') }}</VCardSubtitle></VCardItem>
          <VTable density="compact">
            <thead><tr><th>{{ t('attendance_analytics.table_department') }}</th><th class="text-right">{{ t('attendance_analytics.table_records') }}</th><th class="text-right">{{ t('attendance_analytics.table_late_count') }}</th><th class="text-right">{{ t('attendance_analytics.table_late_pct') }}</th><th class="text-right">{{ t('attendance_analytics.table_avg_hours') }}</th></tr></thead>
            <tbody>
              <tr v-for="d in byDepartment" :key="d.department">
                <td class="font-weight-medium">{{ d.department }}</td>
                <td class="text-right">{{ fmtN(d.records) }}</td>
                <td class="text-right">{{ fmtN(d.late_count) }}</td>
                <td class="text-right"><VChip size="x-small" :color="d.records > 0 && d.late_count / d.records > 0.2 ? 'error' : 'success'" label>{{ d.records > 0 ? Math.round(d.late_count / d.records * 100) : 0 }}%</VChip></td>
                <td class="text-right">{{ d.avg_hours }}h</td>
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
