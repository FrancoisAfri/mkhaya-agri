<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useTheme } from 'vuetify'
import VueApexCharts from 'vue3-apexcharts'
import type { ApexOptions } from 'apexcharts'
import { useI18n } from 'vue-i18n'

useTheme()
const { t } = useI18n()
const success   = '#28c76f'
const warning   = '#ff9f43'
const error     = '#ea5455'
const info      = '#00cfe8'
const secondary = '#82868b'

interface Kpis {
  total: number; approved: number; pending: number; rejected: number; cancelled: number
  approval_rate: number; avg_days: number; total_days: number
}
interface FilterOptions {
  leave_types: { id: number; name: string }[]
  departments: { id: number; name: string }[]
  statuses:    { value: string; label: string }[]
}
interface ActiveFilters { leave_type_id: number | null; department_id: number | null; status: string | null }

const props = defineProps<{
  period: string; dateFrom: string | null; dateTo: string | null
  filters: ActiveFilters; filterOptions: FilterOptions; kpis: Kpis
  byLeaveType: { leave_type: string; count: number; days_taken: number }[]
  byStatus: { status: string; count: number }[]
  monthlyTrend: { month: string; total: number; approved: number; pending: number }[]
  byDepartment: { department: string; count: number; avg_days: number }[]
  leaveDuration: { leave_type: string; avg_days: number; max_days: number; count: number }[]
  topTakers: { name: string; requests: number; total_days: number }[]
  pendingApprovals: { name: string; leave_type: string; leave_days: number; created_at: string }[]
}>()

const filtersOpen   = ref(false)
const periodOptions = [
  { value: 'month', label: 'This Month' }, { value: 'quarter', label: 'Last 3 Months' },
  { value: 'halfyear', label: 'Last 6 Months' }, { value: 'year', label: 'This Year' }, { value: 'lastyear', label: 'Last Year' },
]
const form = ref({
  period:        props.period ?? 'year',
  date_from:     props.dateFrom ?? '',
  date_to:       props.dateTo ?? '',
  leave_type_id: props.filters.leave_type_id ? String(props.filters.leave_type_id) : '',
  department_id: props.filters.department_id ? String(props.filters.department_id) : '',
  status:        props.filters.status ?? '',
})
const useCustomDates = ref(!!(props.dateFrom || props.dateTo))
const activeFilterCount = computed(() => [props.filters.leave_type_id, props.filters.department_id, props.filters.status].filter(Boolean).length + (props.dateFrom || props.dateTo ? 1 : 0))

function applyFilters() {
  const params: Record<string, string> = {}
  if (useCustomDates.value) { if (form.value.date_from) params.date_from = form.value.date_from; if (form.value.date_to) params.date_to = form.value.date_to }
  else params.period = form.value.period
  if (form.value.leave_type_id) params.leave_type_id = form.value.leave_type_id
  if (form.value.department_id) params.department_id = form.value.department_id
  if (form.value.status)        params.status        = form.value.status
  router.get(window.location.pathname, params, { preserveScroll: true })
}
function clearFilters() {
  form.value = { period: 'year', date_from: '', date_to: '', leave_type_id: '', department_id: '', status: '' }
  useCustomDates.value = false
  router.get(window.location.pathname, { period: 'year' }, { preserveScroll: true })
}

const fmtN = (v: number) => Number(v).toLocaleString('en-ZA')
const monthLabel = (m: string) => { const [y, mo] = m.split('-'); return new Date(+y, +mo - 1).toLocaleString('en-ZA', { month: 'short', year: '2-digit' }) }

const kpiCards = computed(() => [
  { title: t('leave.total_requests'),   value: fmtN(props.kpis.total),       sub: 'In selected period',                icon: 'tabler-calendar-account',  color: 'primary' },
  { title: t('leave.approved'),         value: fmtN(props.kpis.approved),    sub: `${props.kpis.approval_rate}% approval rate`, icon: 'tabler-check-circle',    color: 'success' },
  { title: t('leave.pending'),          value: fmtN(props.kpis.pending),     sub: 'Awaiting approval',                 icon: 'tabler-clock-outline',     color: 'warning' },
  { title: t('leave.rejected'),         value: fmtN(props.kpis.rejected),    sub: 'Declined requests',                 icon: 'tabler-close-circle',      color: 'error' },
  { title: t('leave.avg_leave_days'),   value: `${props.kpis.avg_days}d`,    sub: 'Per approved request',              icon: 'tabler-calendar-range',    color: 'info' },
  { title: t('leave.total_days_taken'), value: `${props.kpis.total_days}d`,  sub: 'Cumulative approved days',          icon: 'tabler-briefcase-off',     color: 'secondary' },
])

const trendOptions = computed<ApexOptions>(() => ({
  chart: { type: 'line', toolbar: { show: false }, zoom: { enabled: false } },
  stroke: { curve: 'smooth', width: [3, 3, 2] }, colors: ['#696cff', success, warning],
  xaxis: { categories: props.monthlyTrend.map(r => monthLabel(r.month)) },
  yaxis: { labels: { formatter: (v: number) => String(Math.round(v)) } },
  tooltip: { y: { formatter: (v: number) => `${v} requests` } },
  legend: { position: 'top' }, markers: { size: 4 }, grid: { borderColor: '#f1f3f4' },
}))
const trendSeries = computed(() => [
  { name: 'Total', data: props.monthlyTrend.map(r => r.total) },
  { name: 'Approved', data: props.monthlyTrend.map(r => r.approved) },
  { name: 'Pending', data: props.monthlyTrend.map(r => r.pending) },
])

const statusColors: Record<string, string> = { Approved: success, Pending: warning, Rejected: error, Cancelled: secondary, Unknown: info }
const byStatusOptions = computed<ApexOptions>(() => ({
  chart: { type: 'donut' }, labels: props.byStatus.map(r => r.status),
  colors: props.byStatus.map(r => statusColors[r.status] ?? secondary),
  legend: { position: 'bottom' },
  plotOptions: { pie: { donut: { size: '65%', labels: { show: true, total: { show: true, label: 'Total' } } } } },
  dataLabels: { enabled: false },
}))
const byStatusSeries = computed(() => props.byStatus.map(r => r.count))

const byTypeOptions = computed<ApexOptions>(() => ({
  chart: { type: 'bar', toolbar: { show: false } },
  plotOptions: { bar: { horizontal: true, borderRadius: 4 } }, colors: ['#696cff'],
  xaxis: { categories: props.byLeaveType.map(r => r.leave_type) },
  tooltip: { y: { formatter: (v: number) => `${v} requests` } },
  dataLabels: { enabled: false }, grid: { borderColor: '#f1f3f4' },
}))
const byTypeSeries = computed(() => [{ name: 'Requests', data: props.byLeaveType.map(r => r.count) }])

const deptOptions = computed<ApexOptions>(() => ({
  chart: { type: 'bar', toolbar: { show: false } },
  plotOptions: { bar: { borderRadius: 4, columnWidth: '55%' } }, colors: [info],
  xaxis: { categories: props.byDepartment.map(r => r.department) },
  yaxis: { labels: { formatter: (v: number) => String(Math.round(v)) } },
  tooltip: { y: { formatter: (v: number) => `${v} requests` } },
  dataLabels: { enabled: false }, grid: { borderColor: '#f1f3f4' },
}))
const deptSeries = computed(() => [{ name: 'Requests', data: props.byDepartment.map(r => r.count) }])
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-4">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('leave.title') }}</div>
        <h4 class="text-h4 font-weight-bold">{{ t('leave.bi_analytics') }}</h4>
        <p class="text-medium-emphasis mb-0">Leave utilisation, approval rates, type breakdown, department patterns, and pending queue.</p>
      </div>
      <div class="d-flex align-center gap-2 flex-wrap">
        <VBtn variant="tonal" prepend-icon="tabler-tune-variant" @click="filtersOpen = !filtersOpen">
          Filters <VBadge v-if="activeFilterCount > 0" :content="activeFilterCount" color="primary" floating />
        </VBtn>
        <VBtn variant="tonal" prepend-icon="tabler-file-chart" href="/leave-management/reports">{{ t('leave.reports') }}</VBtn>
      </div>
    </div>

    <VExpandTransition>
      <VCard v-if="filtersOpen" class="mb-5" variant="outlined">
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12" class="pb-1">
              <VBtnToggle v-model="useCustomDates" density="compact" variant="outlined" mandatory>
                <VBtn :value="false" size="small">{{ t('leave.period_preset') }}</VBtn>
                <VBtn :value="true" size="small">Custom Dates</VBtn>
              </VBtnToggle>
            </VCol>
            <VCol v-if="!useCustomDates" cols="12" sm="6" md="3">
              <VSelect v-model="form.period" :items="periodOptions" item-title="label" item-value="value" label="Period" density="compact" variant="outlined" hide-details />
            </VCol>
            <template v-if="useCustomDates">
              <VCol cols="12" sm="6" md="3"><VTextField v-model="form.date_from" type="date" label="From Date" density="compact" variant="outlined" hide-details /></VCol>
              <VCol cols="12" sm="6" md="3"><VTextField v-model="form.date_to" type="date" label="To Date" density="compact" variant="outlined" hide-details /></VCol>
            </template>
            <VCol cols="12" sm="6" md="3">
              <VSelect v-model="form.leave_type_id" :items="filterOptions.leave_types" item-title="name" item-value="id" label="Leave Type" density="compact" variant="outlined" hide-details clearable />
            </VCol>
            <VCol cols="12" sm="6" md="3">
              <VSelect v-model="form.department_id" :items="filterOptions.departments" item-title="name" item-value="id" label="Department" density="compact" variant="outlined" hide-details clearable />
            </VCol>
            <VCol cols="12" sm="6" md="3">
              <VSelect v-model="form.status" :items="filterOptions.statuses" item-title="label" item-value="value" label="Status" density="compact" variant="outlined" hide-details clearable />
            </VCol>
            <VCol cols="12" class="d-flex gap-2 pt-2">
              <VBtn color="primary" prepend-icon="tabler-magnify" @click="applyFilters">{{ t('leave.apply_filters') }}</VBtn>
              <VBtn variant="tonal" prepend-icon="tabler-close" @click="clearFilters">{{ t('leave.clear_all') }}</VBtn>
            </VCol>
          </VRow>
        </VCardText>
      </VCard>
    </VExpandTransition>

    <div v-if="activeFilterCount > 0" class="d-flex flex-wrap gap-2 mb-4">
      <VChip v-if="dateFrom || dateTo" size="small" closable color="primary" @click:close="clearFilters">{{ dateFrom }} → {{ dateTo }}</VChip>
      <VChip v-else size="small" color="primary" variant="tonal">{{ periodOptions.find(p => p.value === period)?.label }}</VChip>
      <VChip v-if="filters.leave_type_id" size="small" closable color="secondary" variant="tonal" @click:close="() => { form.leave_type_id = ''; applyFilters() }">Type: {{ filterOptions.leave_types.find(t => t.id === filters.leave_type_id)?.name }}</VChip>
      <VChip v-if="filters.department_id" size="small" closable color="secondary" variant="tonal" @click:close="() => { form.department_id = ''; applyFilters() }">Dept: {{ filterOptions.departments.find(d => d.id === filters.department_id)?.name }}</VChip>
      <VChip v-if="filters.status !== null" size="small" closable color="secondary" variant="tonal" @click:close="() => { form.status = ''; applyFilters() }">Status: {{ filterOptions.statuses.find(s => s.value === filters.status)?.label }}</VChip>
    </div>

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

    <VRow class="mb-4">
      <VCol cols="12" md="8">
        <VCard height="100%">
          <VCardItem><VCardTitle>{{ t('leave.monthly_leave_trend') }}</VCardTitle><VCardSubtitle>Requests submitted, approved, and pending per month</VCardSubtitle></VCardItem>
          <VCardText>
            <VueApexCharts v-if="monthlyTrend.length" type="line" :height="280" :options="trendOptions" :series="trendSeries" />
            <VEmptyState v-else icon="tabler-chart-line" title="No trend data" text="No leave requests found for this period." size="80" />
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="4">
        <VCard height="100%">
          <VCardItem><VCardTitle>{{ t('leave.by_status') }}</VCardTitle><VCardSubtitle>Request distribution by approval status</VCardSubtitle></VCardItem>
          <VCardText>
            <VueApexCharts v-if="byStatus.length" type="donut" :height="280" :options="byStatusOptions" :series="byStatusSeries" />
            <VEmptyState v-else icon="tabler-calendar" title="No data" size="60" />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow class="mb-4">
      <VCol cols="12" md="6">
        <VCard height="100%">
          <VCardItem><VCardTitle>{{ t('leave.requests_by_leave_type') }}</VCardTitle><VCardSubtitle>Volume of applications per leave category</VCardSubtitle></VCardItem>
          <VCardText>
            <VueApexCharts v-if="byLeaveType.length" type="bar" :height="260" :options="byTypeOptions" :series="byTypeSeries" />
            <VEmptyState v-else icon="tabler-tag" title="No leave type data" size="70" />
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="6">
        <VCard height="100%">
          <VCardItem><VCardTitle>{{ t('leave.by_department') }}</VCardTitle><VCardSubtitle>Leave requests per department</VCardSubtitle></VCardItem>
          <VCardText>
            <VueApexCharts v-if="byDepartment.length" type="bar" :height="260" :options="deptOptions" :series="deptSeries" />
            <VEmptyState v-else icon="tabler-office-building" title="No department data" size="70" />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow class="mb-4">
      <VCol cols="12" md="6">
        <VCard>
          <VCardItem><VCardTitle>{{ t('leave.top_leave_takers') }}</VCardTitle><VCardSubtitle>Employees with most approved leave days</VCardSubtitle></VCardItem>
          <VTable density="compact">
            <thead><tr><th>#</th><th>Employee</th><th class="text-right">Requests</th><th class="text-right">Total Days</th></tr></thead>
            <tbody>
              <tr v-if="!topTakers.length"><td colspan="4" class="text-center text-medium-emphasis pa-4">No data for this period.</td></tr>
              <tr v-for="(e, i) in topTakers" :key="e.name">
                <td><VChip size="x-small" :color="i < 3 ? 'primary' : 'default'" label>{{ i + 1 }}</VChip></td>
                <td class="font-weight-medium">{{ e.name }}</td>
                <td class="text-right">{{ e.requests }}</td>
                <td class="text-right font-weight-medium">{{ e.total_days }}d</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>

      <VCol cols="12" md="6">
        <VCard>
          <VCardItem>
            <VCardTitle class="d-flex align-center gap-2">
              <VIcon color="warning" size="20">tabler-clock-alert</VIcon>
              {{ t('leave.pending_approvals') }}
            </VCardTitle>
            <VCardSubtitle>Leave requests awaiting manager approval</VCardSubtitle>
          </VCardItem>
          <VTable density="compact">
            <thead><tr><th>Employee</th><th>Leave Type</th><th class="text-right">Days</th><th>Applied</th></tr></thead>
            <tbody>
              <tr v-if="!pendingApprovals.length"><td colspan="4" class="text-center text-medium-emphasis pa-4">No pending approvals.</td></tr>
              <tr v-for="p in pendingApprovals" :key="`${p.name}-${p.created_at}`">
                <td class="font-weight-medium">{{ p.name }}</td>
                <td><VChip size="x-small" color="info" label>{{ p.leave_type }}</VChip></td>
                <td class="text-right">{{ p.leave_days }}</td>
                <td class="text-caption text-medium-emphasis">{{ new Date(p.created_at).toLocaleDateString('en-ZA') }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>

    <VRow>
      <VCol cols="12">
        <VCard>
          <VCardItem><VCardTitle>{{ t('leave.avg_duration_by_type') }}</VCardTitle><VCardSubtitle>How long employees typically take per leave category</VCardSubtitle></VCardItem>
          <VTable density="compact">
            <thead><tr><th>Leave Type</th><th class="text-right">Avg Days</th><th class="text-right">Max Days</th><th class="text-right">Approved Requests</th></tr></thead>
            <tbody>
              <tr v-if="!leaveDuration.length"><td colspan="4" class="text-center text-medium-emphasis pa-4">No duration data for this period.</td></tr>
              <tr v-for="d in leaveDuration" :key="d.leave_type">
                <td class="font-weight-medium">{{ d.leave_type }}</td>
                <td class="text-right"><VChip size="x-small" :color="d.avg_days > 10 ? 'warning' : 'success'" label>{{ d.avg_days }}d</VChip></td>
                <td class="text-right text-medium-emphasis">{{ d.max_days }}d</td>
                <td class="text-right text-medium-emphasis">{{ d.count }}</td>
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
