<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import VueApexCharts from 'vue3-apexcharts'
import type { ApexOptions } from 'apexcharts'

const { t } = useI18n()
const route = ziggyRoute

const success = '#28c76f'
const warning = '#ff9f43'
const error   = '#ea5455'
const info     = '#00cfe8'
const primary  = '#696cff'
const secondary = '#82868b'

interface Kpis {
  total: number; active: number; expiring_90: number
  total_value: number; avg_value: number; high_risk: number
}
interface FilterOptions {
  statuses: { value: string; label: string }[]
  types: { id: number; name: string }[]
}

const props = defineProps<{
  period: string
  dateFrom: string | null
  dateTo: string | null
  filters: { status: string | null; type: number | null }
  filterOptions: FilterOptions
  kpis: Kpis
  byStatus: { status: string; count: number }[]
  byType: { type: string; count: number; value: number }[]
  valueByStatus: { status: string; value: number }[]
  monthlyTrend: { month: string; created: number; expiring: number }[]
  bySupplier: { supplier: string; count: number; value: number }[]
  riskDistribution: { band: string; count: number }[]
}>()

const periodOptions = [
  { value: 'month', label: t('contracts_analytics.periods.month') },
  { value: 'quarter', label: t('contracts_analytics.periods.quarter') },
  { value: 'halfyear', label: t('contracts_analytics.periods.halfyear') },
  { value: 'year', label: t('contracts_analytics.periods.year') },
  { value: 'lastyear', label: t('contracts_analytics.periods.lastyear') },
  { value: 'all', label: t('contracts_analytics.periods.all') },
]

const form = ref({
  period: props.period ?? 'year',
  status: props.filters.status ?? '',
  type: props.filters.type ? String(props.filters.type) : '',
})

function applyFilters() {
  const params: Record<string, string> = { period: form.value.period }
  if (form.value.status) params.status = form.value.status
  if (form.value.type)   params.type   = form.value.type
  router.get(route('contracts.analytics'), params, { preserveState: true, preserveScroll: true })
}
function clearFilters() {
  form.value = { period: 'year', status: '', type: '' }
  router.get(route('contracts.analytics'), { period: 'year' }, { preserveState: true, preserveScroll: true })
}

const fmtN = (v: number) => Number(v).toLocaleString('en-ZA')
const money = (v: number) => new Intl.NumberFormat('en-ZA', { style: 'currency', currency: 'ZAR', maximumFractionDigits: 0 }).format(v)
const monthLabel = (m: string) => { const [y, mo] = m.split('-'); return new Date(+y, +mo - 1).toLocaleString('en-ZA', { month: 'short', year: '2-digit' }) }

const kpiCards = computed(() => [
  { title: t('contracts_analytics.kpis.total_contracts'), value: fmtN(props.kpis.total), sub: t('contracts_analytics.kpis.in_selected_period'), icon: 'tabler-file-text', color: 'primary' },
  { title: t('contracts_analytics.kpis.active'), value: fmtN(props.kpis.active), sub: t('contracts_analytics.kpis.currently_active'), icon: 'tabler-circle-check', color: 'success' },
  { title: t('contracts_analytics.kpis.expiring_90d'), value: fmtN(props.kpis.expiring_90), sub: t('contracts_analytics.kpis.end_within_90_days'), icon: 'tabler-clock-exclamation', color: 'warning' },
  { title: t('contracts_analytics.kpis.total_value'), value: money(props.kpis.total_value), sub: t('contracts_analytics.kpis.portfolio_value'), icon: 'tabler-cash', color: 'info' },
  { title: t('contracts_analytics.kpis.avg_value'), value: money(props.kpis.avg_value), sub: t('contracts_analytics.kpis.per_contract'), icon: 'tabler-calculator', color: 'secondary' },
  { title: t('contracts_analytics.kpis.high_risk'), value: fmtN(props.kpis.high_risk), sub: t('contracts_analytics.kpis.risk_score_67'), icon: 'tabler-alert-triangle', color: 'error' },
])

// Status donut
const statusOptions = computed<ApexOptions>(() => ({
  chart: { type: 'donut' },
  labels: props.byStatus.map(r => r.status),
  colors: [primary, success, warning, error, info, secondary],
  legend: { position: 'bottom' },
  plotOptions: { pie: { donut: { size: '65%', labels: { show: true, total: { show: true, label: t('contracts_analytics.charts.total') } } } } },
  dataLabels: { enabled: false },
}))
const statusSeries = computed(() => props.byStatus.map(r => r.count))

// Type bar
const typeOptions = computed<ApexOptions>(() => ({
  chart: { type: 'bar', toolbar: { show: false } },
  plotOptions: { bar: { horizontal: true, borderRadius: 4 } },
  colors: [primary],
  xaxis: { categories: props.byType.map(r => r.type) },
  dataLabels: { enabled: false },
  grid: { borderColor: '#f1f3f4' },
  tooltip: { y: { formatter: (v: number) => `${v} ${t('contracts_analytics.charts.contracts')}` } },
}))
const typeSeries = computed(() => [{ name: t('contracts_analytics.charts.contracts'), data: props.byType.map(r => r.count) }])

// Monthly line
const trendOptions = computed<ApexOptions>(() => ({
  chart: { type: 'line', toolbar: { show: false }, zoom: { enabled: false } },
  stroke: { curve: 'smooth', width: [3, 3] },
  colors: [primary, warning],
  xaxis: { categories: props.monthlyTrend.map(r => monthLabel(r.month)) },
  yaxis: { labels: { formatter: (v: number) => String(Math.round(v)) } },
  legend: { position: 'top' },
  markers: { size: 4 },
  grid: { borderColor: '#f1f3f4' },
}))
const trendSeries = computed(() => [
  { name: t('contracts_analytics.charts.created'), data: props.monthlyTrend.map(r => r.created) },
  { name: t('contracts_analytics.charts.expiring'), data: props.monthlyTrend.map(r => r.expiring) },
])

// Risk donut
const riskColors: Record<string, string> = { Low: success, Medium: warning, High: error }
const riskOptions = computed<ApexOptions>(() => ({
  chart: { type: 'donut' },
  labels: props.riskDistribution.map(r => r.band),
  colors: props.riskDistribution.map(r => riskColors[r.band] ?? secondary),
  legend: { position: 'bottom' },
  plotOptions: { pie: { donut: { size: '65%', labels: { show: true, total: { show: true, label: t('contracts_analytics.charts.analyzed') } } } } },
  dataLabels: { enabled: false },
}))
const riskSeries = computed(() => props.riskDistribution.map(r => r.count))

// Top suppliers bar
const supplierOptions = computed<ApexOptions>(() => ({
  chart: { type: 'bar', toolbar: { show: false } },
  plotOptions: { bar: { borderRadius: 4, columnWidth: '55%' } },
  colors: [info],
  xaxis: { categories: props.bySupplier.map(r => r.supplier) },
  yaxis: { labels: { formatter: (v: number) => String(Math.round(v)) } },
  dataLabels: { enabled: false },
  grid: { borderColor: '#f1f3f4' },
  tooltip: { y: { formatter: (v: number) => `${v} ${t('contracts_analytics.charts.contracts')}` } },
}))
const supplierSeries = computed(() => [{ name: t('contracts_analytics.charts.contracts'), data: props.bySupplier.map(r => r.count) }])

const activeFilterCount = computed(() => [props.filters.status, props.filters.type].filter(Boolean).length)
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div class="d-flex align-center gap-3">
        <VAvatar color="primary" variant="tonal" size="48" rounded>
          <VIcon icon="tabler-chart-pie" size="26" />
        </VAvatar>
        <div>
          <h4 class="text-h4 mb-0">{{ t('contracts_analytics.page_title') }}</h4>
          <p class="text-caption text-medium-emphasis mb-0">{{ t('contracts_analytics.description') }}</p>
        </div>
      </div>
      <VBtn variant="tonal" color="primary" prepend-icon="tabler-file-analytics" @click="router.visit(route('contracts.reports'))">
        {{ t('contracts_analytics.reports') }}
      </VBtn>
    </div>

    <!-- Filter bar -->
    <VCard border class="mb-4">
      <VCardText class="d-flex align-center flex-wrap gap-3">
        <VSelect
          v-model="form.period"
          :items="periodOptions"
          item-title="label"
          item-value="value"
          :label="t('contracts_analytics.filters.period')"
          variant="outlined"
          density="compact"
          hide-details
          style="max-width: 200px"
        />
        <VSelect
          v-model="form.status"
          :items="filterOptions.statuses"
          item-title="label"
          item-value="value"
          :label="t('contracts_analytics.filters.status')"
          variant="outlined"
          density="compact"
          clearable
          hide-details
          style="max-width: 200px"
        />
        <VSelect
          v-model="form.type"
          :items="filterOptions.types"
          item-title="name"
          item-value="id"
          :label="t('contracts_analytics.filters.type')"
          variant="outlined"
          density="compact"
          clearable
          hide-details
          style="max-width: 200px"
        />
        <VBtn color="primary" prepend-icon="tabler-filter" @click="applyFilters">
          {{ t('contracts_analytics.filters.apply') }}
        </VBtn>
        <VBtn v-if="activeFilterCount" variant="text" @click="clearFilters">
          {{ t('contracts_analytics.filters.clear') }}
        </VBtn>
      </VCardText>
    </VCard>

    <!-- KPI cards -->
    <VRow class="mb-2">
      <VCol v-for="kpi in kpiCards" :key="kpi.title" cols="12" sm="6" md="4" lg="2">
        <VCard border>
          <VCardText class="d-flex align-center gap-3">
            <VAvatar :color="kpi.color" variant="tonal" size="44" rounded>
              <VIcon :icon="kpi.icon" size="24" />
            </VAvatar>
            <div>
              <div class="text-h6 mb-0">{{ kpi.value }}</div>
              <div class="text-caption text-medium-emphasis">{{ kpi.title }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Charts -->
    <VRow>
      <VCol cols="12" md="8">
        <VCard border>
          <VCardTitle>{{ t('contracts_analytics.charts.monthly_trend') }}</VCardTitle>
          <VCardText>
            <VueApexCharts type="line" :height="280" :options="trendOptions" :series="trendSeries" />
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="4">
        <VCard border>
          <VCardTitle>{{ t('contracts_analytics.charts.by_status') }}</VCardTitle>
          <VCardText>
            <VueApexCharts type="donut" :height="280" :options="statusOptions" :series="statusSeries" />
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="6">
        <VCard border>
          <VCardTitle>{{ t('contracts_analytics.charts.by_type') }}</VCardTitle>
          <VCardText>
            <VueApexCharts type="bar" :height="280" :options="typeOptions" :series="typeSeries" />
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="6">
        <VCard border>
          <VCardTitle>{{ t('contracts_analytics.charts.ai_risk_distribution') }}</VCardTitle>
          <VCardText>
            <VueApexCharts type="donut" :height="280" :options="riskOptions" :series="riskSeries" />
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="6">
        <VCard border>
          <VCardTitle>{{ t('contracts_analytics.charts.top_suppliers') }}</VCardTitle>
          <VCardText>
            <VueApexCharts type="bar" :height="280" :options="supplierOptions" :series="supplierSeries" />
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="6">
        <VCard border>
          <VCardTitle>{{ t('contracts_analytics.charts.value_by_status') }}</VCardTitle>
          <VTable>
            <thead>
              <tr>
                <th>{{ t('contracts_analytics.table.status') }}</th>
                <th class="text-right">{{ t('contracts_analytics.table.value') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!valueByStatus.length">
                <td colspan="2" class="text-center text-medium-emphasis py-6">{{ t('contracts_analytics.charts.no_data') }}</td>
              </tr>
              <tr v-for="row in valueByStatus" :key="row.status">
                <td>{{ row.status }}</td>
                <td class="text-right">{{ money(row.value) }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>

      <VCol cols="12">
        <VCard border>
          <VCardTitle>{{ t('contracts_analytics.charts.contracts_by_type_detail') }}</VCardTitle>
          <VTable>
            <thead>
              <tr>
                <th>{{ t('contracts_analytics.table.type') }}</th>
                <th class="text-right">{{ t('contracts_analytics.table.contracts') }}</th>
                <th class="text-right">{{ t('contracts_analytics.table.value') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!byType.length">
                <td colspan="3" class="text-center text-medium-emphasis py-6">{{ t('contracts_analytics.charts.no_data') }}</td>
              </tr>
              <tr v-for="row in byType" :key="row.type">
                <td>{{ row.type }}</td>
                <td class="text-right">{{ fmtN(row.count) }}</td>
                <td class="text-right">{{ money(row.value) }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>
  </VContainer>
</template>
