<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useTheme } from 'vuetify'
import VueApexCharts from 'vue3-apexcharts'
import type { ApexOptions } from 'apexcharts'

useTheme()
const success   = '#28c76f'
const warning   = '#ff9f43'
const error     = '#ea5455'
const info      = '#00cfe8'
const secondary = '#82868b'

interface Kpis {
  total_assets: number; total_value: number; book_value: number
  assigned_count: number; utilization_pct: number; avg_age_years: number
  maintenance_cost: number; maintenance_count: number; incidents: number
}
interface FilterOptions {
  categories: { id: number; name: string }[]
  locations:  { id: number; name: string }[]
  statuses:   string[]
}
interface ActiveFilters {
  category_id: number | null
  location_id: number | null
  status: string | null
}

const props = defineProps<{
  period: string
  dateFrom: string | null
  dateTo: string | null
  filters: ActiveFilters
  filterOptions: FilterOptions
  kpis: Kpis
  byCategory: { category: string; count: number; total_value: number }[]
  byStatus: { status: string; count: number }[]
  byLocation: { location: string; count: number }[]
  valueTrend: { month: string; count: number; value: number }[]
  maintenanceCostTrend: { month: string; jobs: number; cost: number }[]
  maintenanceByType: { type: string; count: number; cost: number }[]
  incidentsByType: { type: string; severity: string; count: number }[]
  depreciationByCategory: { category: string; original_value: number; book_value: number; depreciation_pct: number }[]
  assignmentTrend: { month: string; assignments: number }[]
  topValueAssets: { id: number; asset_number: string; name: string; category: string; status: string; purchase_price: number; current_value: number }[]
  mostMaintained: { asset_number: string; name: string; jobs: number; total_cost: number }[]
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
  period:      props.period ?? 'year',
  date_from:   props.dateFrom ?? '',
  date_to:     props.dateTo   ?? '',
  category_id: props.filters.category_id ? String(props.filters.category_id) : '',
  location_id: props.filters.location_id ? String(props.filters.location_id) : '',
  status:      props.filters.status ?? '',
})

const useCustomDates = ref(!!(props.dateFrom || props.dateTo))

const activeFilterCount = computed(() => {
  const f = props.filters
  return [f.category_id, f.location_id, f.status].filter(Boolean).length
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
  if (form.value.category_id) params.category_id = form.value.category_id
  if (form.value.location_id) params.location_id = form.value.location_id
  if (form.value.status)      params.status      = form.value.status
  router.get(window.location.pathname, params, { preserveScroll: true })
}

function clearFilters() {
  form.value = { period: 'year', date_from: '', date_to: '', category_id: '', location_id: '', status: '' }
  useCustomDates.value = false
  router.get(window.location.pathname, { period: 'year' }, { preserveScroll: true })
}

// ── Formatters ────────────────────────────────────────────────────────────────
const fmtR = (v: number) => `R ${Number(v).toLocaleString('en-ZA', { minimumFractionDigits: 0, maximumFractionDigits: 0 })}`
const fmtN = (v: number) => Number(v).toLocaleString('en-ZA')
const monthLabel = (m: string) => {
  const [y, mo] = m.split('-')
  return new Date(+y, +mo - 1).toLocaleString('en-ZA', { month: 'short', year: '2-digit' })
}

// ── KPI cards ─────────────────────────────────────────────────────────────────
const kpiCards = computed(() => [
  { title: 'Total Assets',       value: fmtN(props.kpis.total_assets),      sub: `${props.kpis.assigned_count} assigned`,            icon: 'tabler-package', color: 'primary' },
  { title: 'Asset Value',        value: fmtR(props.kpis.total_value),        sub: `Book value: ${fmtR(props.kpis.book_value)}`,       icon: 'tabler-currency-dollar',            color: 'success' },
  { title: 'Utilisation',        value: `${props.kpis.utilization_pct}%`,    sub: `${props.kpis.assigned_count} of ${props.kpis.total_assets}`, icon: 'tabler-chart-donut', color: 'info' },
  { title: 'Avg Asset Age',      value: `${props.kpis.avg_age_years} yrs`,   sub: 'Since purchase date',                              icon: 'tabler-calendar-clock',          color: 'warning' },
  { title: 'Maintenance Cost',   value: fmtR(props.kpis.maintenance_cost),   sub: `${props.kpis.maintenance_count} jobs in period`,   icon: 'tabler-tool',                  color: 'secondary' },
  { title: 'Incidents',          value: fmtN(props.kpis.incidents),           sub: 'Reported in period',                               icon: 'tabler-alert-circle',            color: 'error' },
])

// ── Value trend (area) ────────────────────────────────────────────────────────
const valueTrendOptions = computed<ApexOptions>(() => ({
  chart:   { type: 'area', toolbar: { show: false }, zoom: { enabled: false } },
  stroke:  { curve: 'smooth', width: 2 },
  fill:    { type: 'gradient', gradient: { opacityFrom: 0.4, opacityTo: 0.05 } },
  colors:  ['#696cff'],
  xaxis:   { categories: props.valueTrend.map(r => monthLabel(r.month)) },
  yaxis:   { labels: { formatter: (v: number) => fmtR(v) } },
  tooltip: { y: { formatter: (v: number) => fmtR(v) } },
  grid:    { borderColor: '#f1f3f4' },
}))
const valueTrendSeries = computed(() => [
  { name: 'Acquired Value', data: props.valueTrend.map(r => r.value) },
])

// ── Assets by category (bar) ─────────────────────────────────────────────────
const byCategoryOptions = computed<ApexOptions>(() => ({
  chart:   { type: 'bar', toolbar: { show: false } },
  plotOptions: { bar: { horizontal: true, borderRadius: 4 } },
  colors:  ['#696cff'],
  xaxis:   { categories: props.byCategory.map(r => r.category) },
  tooltip: { y: { formatter: (v: number) => `${v} assets` } },
  dataLabels: { enabled: false },
  grid: { borderColor: '#f1f3f4' },
}))
const byCategorySeries = computed(() => [
  { name: 'Assets', data: props.byCategory.map(r => r.count) },
])

// ── By status donut ───────────────────────────────────────────────────────────
const statusColors: Record<string, string> = {
  available: success, assigned: '#696cff', maintenance: warning,
  disposed: secondary, lost: error, stolen: error,
}
const byStatusOptions = computed<ApexOptions>(() => ({
  chart:   { type: 'donut' },
  labels:  props.byStatus.map(r => r.status),
  colors:  props.byStatus.map(r => statusColors[r.status] ?? secondary),
  legend:  { position: 'bottom' },
  plotOptions: { pie: { donut: { size: '65%', labels: { show: true, total: { show: true, label: 'Total' } } } } },
  dataLabels: { enabled: false },
}))
const byStatusSeries = computed(() => props.byStatus.map(r => r.count))

// ── Maintenance cost trend (bar) ──────────────────────────────────────────────
const maintTrendOptions = computed<ApexOptions>(() => ({
  chart:   { type: 'bar', toolbar: { show: false } },
  plotOptions: { bar: { borderRadius: 4, columnWidth: '55%' } },
  colors:  [warning],
  xaxis:   { categories: props.maintenanceCostTrend.map(r => monthLabel(r.month)) },
  yaxis:   { labels: { formatter: (v: number) => fmtR(v) } },
  tooltip: { y: { formatter: (v: number) => fmtR(v) } },
  dataLabels: { enabled: false },
  grid: { borderColor: '#f1f3f4' },
}))
const maintTrendSeries = computed(() => [
  { name: 'Maintenance Cost', data: props.maintenanceCostTrend.map(r => r.cost) },
])

// ── Maintenance by type (donut) ───────────────────────────────────────────────
const maintByTypeOptions = computed<ApexOptions>(() => ({
  chart:   { type: 'pie' },
  labels:  props.maintenanceByType.map(r => r.type),
  colors:  ['#696cff', warning, success, info, error],
  legend:  { position: 'bottom' },
  dataLabels: { enabled: true },
}))
const maintByTypeSeries = computed(() => props.maintenanceByType.map(r => r.count))

// ── Depreciation by category (grouped bar) ────────────────────────────────────
const deprOptions = computed<ApexOptions>(() => ({
  chart:   { type: 'bar', toolbar: { show: false } },
  plotOptions: { bar: { borderRadius: 4, columnWidth: '60%' } },
  colors:  ['#696cff', success],
  xaxis:   { categories: props.depreciationByCategory.map(r => r.category) },
  yaxis:   { labels: { formatter: (v: number) => fmtR(v) } },
  tooltip: { y: { formatter: (v: number) => fmtR(v) } },
  dataLabels: { enabled: false },
  legend:  { position: 'top' },
  grid: { borderColor: '#f1f3f4' },
}))
const deprSeries = computed(() => [
  { name: 'Original Value', data: props.depreciationByCategory.map(r => r.original_value) },
  { name: 'Book Value',     data: props.depreciationByCategory.map(r => r.book_value) },
])

// ── Assignment trend (line) ───────────────────────────────────────────────────
const assignTrendOptions = computed<ApexOptions>(() => ({
  chart:   { type: 'line', toolbar: { show: false }, zoom: { enabled: false } },
  stroke:  { curve: 'smooth', width: 3 },
  colors:  [info],
  markers: { size: 4 },
  xaxis:   { categories: props.assignmentTrend.map(r => monthLabel(r.month)) },
  yaxis:   { labels: { formatter: (v: number) => String(Math.round(v)) } },
  tooltip: { y: { formatter: (v: number) => `${v} assignments` } },
  grid:    { borderColor: '#f1f3f4' },
}))
const assignTrendSeries = computed(() => [
  { name: 'Assignments', data: props.assignmentTrend.map(r => r.assignments) },
])
</script>

<template>
  <div class="pa-4">
    <!-- ── Header ─────────────────────────────────────────────────────────── -->
    <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-4">
      <div>
        <div class="text-overline text-medium-emphasis">Asset Management</div>
        <h4 class="text-h4 font-weight-bold">BI Report Analytics</h4>
        <p class="text-medium-emphasis mb-0">Portfolio value, utilisation, maintenance costs, depreciation, and incidents.</p>
      </div>
      <div class="d-flex align-center gap-2 flex-wrap">
        <VBtn variant="tonal" prepend-icon="tabler-adjustments-horizontal" @click="filtersOpen = !filtersOpen">
          Filters
          <VBadge v-if="activeFilterCount > 0" :content="activeFilterCount" color="primary" floating />
        </VBtn>
        <VBtn variant="tonal" prepend-icon="tabler-file-analytics" href="/assets/reports">Reports</VBtn>
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
              <VSelect v-model="form.period" :items="periodOptions" item-title="label" item-value="value" label="Period" density="compact" variant="outlined" hide-details />
            </VCol>
            <template v-if="useCustomDates">
              <VCol cols="12" sm="6" md="3">
                <VTextField v-model="form.date_from" type="date" label="From Date" density="compact" variant="outlined" hide-details />
              </VCol>
              <VCol cols="12" sm="6" md="3">
                <VTextField v-model="form.date_to" type="date" label="To Date" density="compact" variant="outlined" hide-details />
              </VCol>
            </template>
            <VCol cols="12" sm="6" md="3">
              <VSelect v-model="form.category_id" :items="filterOptions.categories" item-title="name" item-value="id" label="Category" density="compact" variant="outlined" hide-details clearable />
            </VCol>
            <VCol cols="12" sm="6" md="3">
              <VSelect v-model="form.location_id" :items="filterOptions.locations" item-title="name" item-value="id" label="Location" density="compact" variant="outlined" hide-details clearable />
            </VCol>
            <VCol cols="12" sm="6" md="3">
              <VSelect v-model="form.status" :items="filterOptions.statuses" label="Status" density="compact" variant="outlined" hide-details clearable />
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
      <VChip v-if="dateFrom || dateTo" size="small" closable color="primary" @click:close="clearFilters">
        {{ dateFrom }} → {{ dateTo }}
      </VChip>
      <VChip v-else size="small" color="primary" variant="tonal">
        {{ periodOptions.find(p => p.value === period)?.label }}
      </VChip>
      <VChip v-if="filters.category_id" size="small" closable color="secondary" variant="tonal" @click:close="() => { form.category_id = ''; applyFilters() }">
        Category: {{ filterOptions.categories.find(c => c.id === filters.category_id)?.name }}
      </VChip>
      <VChip v-if="filters.location_id" size="small" closable color="secondary" variant="tonal" @click:close="() => { form.location_id = ''; applyFilters() }">
        Location: {{ filterOptions.locations.find(l => l.id === filters.location_id)?.name }}
      </VChip>
      <VChip v-if="filters.status" size="small" closable color="secondary" variant="tonal" @click:close="() => { form.status = ''; applyFilters() }">
        Status: {{ filters.status }}
      </VChip>
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

    <!-- ── Row 2: Value Trend + Status Donut ─────────────────────────────── -->
    <VRow class="mb-4">
      <VCol cols="12" md="8">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>Asset Acquisition Value Trend</VCardTitle>
            <VCardSubtitle>Monthly purchase value of assets acquired in the period</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts v-if="valueTrend.length" type="area" :height="280" :options="valueTrendOptions" :series="valueTrendSeries" />
            <VEmptyState v-else icon="tabler-chart-line" title="No acquisition data" text="No assets purchased in this period." size="80" />
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="4">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>Asset Status</VCardTitle>
            <VCardSubtitle>Current portfolio breakdown by status</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts v-if="byStatus.length" type="donut" :height="280" :options="byStatusOptions" :series="byStatusSeries" />
            <VEmptyState v-else icon="tabler-package" title="No assets" size="60" />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Row 3: By Category + By Location ──────────────────────────────── -->
    <VRow class="mb-4">
      <VCol cols="12" md="7">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>Assets by Category</VCardTitle>
            <VCardSubtitle>Count of assets per category</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts v-if="byCategory.length" type="bar" :height="280" :options="byCategoryOptions" :series="byCategorySeries" />
            <VEmptyState v-else icon="tabler-shapes" title="No category data" size="70" />
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="5">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>Assets by Location</VCardTitle>
            <VCardSubtitle>Top 10 locations by asset count</VCardSubtitle>
          </VCardItem>
          <VTable density="compact">
            <thead>
              <tr><th>Location</th><th class="text-right">Count</th></tr>
            </thead>
            <tbody>
              <tr v-if="!byLocation.length">
                <td colspan="2" class="text-center text-medium-emphasis pa-4">No location data</td>
              </tr>
              <tr v-for="r in byLocation" :key="r.location">
                <td>{{ r.location }}</td>
                <td class="text-right font-weight-medium">{{ fmtN(r.count) }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Row 4: Maintenance cost trend + By type ────────────────────────── -->
    <VRow class="mb-4">
      <VCol cols="12" md="8">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>Maintenance Cost Trend</VCardTitle>
            <VCardSubtitle>Monthly maintenance spend</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts v-if="maintenanceCostTrend.length" type="bar" :height="260" :options="maintTrendOptions" :series="maintTrendSeries" />
            <VEmptyState v-else icon="tabler-tool" title="No maintenance data" text="No maintenance records in this period." size="70" />
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="4">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>Maintenance by Type</VCardTitle>
            <VCardSubtitle>Work order breakdown by type</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts v-if="maintenanceByType.length" type="pie" :height="260" :options="maintByTypeOptions" :series="maintByTypeSeries" />
            <VEmptyState v-else icon="tabler-clipboard-list" title="No maintenance types" size="60" />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Row 5: Depreciation + Assignment trend ─────────────────────────── -->
    <VRow class="mb-4">
      <VCol cols="12" md="7">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>Depreciation by Category</VCardTitle>
            <VCardSubtitle>Original vs current book value comparison</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts v-if="depreciationByCategory.length" type="bar" :height="260" :options="deprOptions" :series="deprSeries" />
            <VEmptyState v-else icon="tabler-trending-down" title="No depreciation data" size="70" />
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="5">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>Assignment Activity</VCardTitle>
            <VCardSubtitle>Monthly asset assignment events</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts v-if="assignmentTrend.length" type="line" :height="260" :options="assignTrendOptions" :series="assignTrendSeries" />
            <VEmptyState v-else icon="tabler-user-share" title="No assignment data" size="70" />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Row 6: Top Value Assets ────────────────────────────────────────── -->
    <VRow class="mb-4">
      <VCol cols="12" md="6">
        <VCard>
          <VCardItem>
            <VCardTitle>Top Value Assets</VCardTitle>
            <VCardSubtitle>Highest-value assets by purchase price</VCardSubtitle>
          </VCardItem>
          <VTable density="compact">
            <thead>
              <tr>
                <th>#</th>
                <th>Asset</th>
                <th>Category</th>
                <th>Status</th>
                <th class="text-right">Purchase Price</th>
                <th class="text-right">Book Value</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!topValueAssets.length">
                <td colspan="6" class="text-center text-medium-emphasis pa-4">No asset data for this period.</td>
              </tr>
              <tr v-for="(a, i) in topValueAssets" :key="a.id">
                <td><VChip size="x-small" :color="i < 3 ? 'primary' : 'default'" label>{{ i + 1 }}</VChip></td>
                <td>
                  <div class="font-weight-medium">{{ a.name }}</div>
                  <div class="text-caption text-medium-emphasis">{{ a.asset_number }}</div>
                </td>
                <td class="text-medium-emphasis">{{ a.category || '—' }}</td>
                <td><VChip size="x-small" label>{{ a.status }}</VChip></td>
                <td class="text-right">{{ fmtR(a.purchase_price) }}</td>
                <td class="text-right">{{ a.current_value ? fmtR(a.current_value) : '—' }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>

      <!-- ── Most Maintained ─────────────────────────────────────────────── -->
      <VCol cols="12" md="6">
        <VCard>
          <VCardItem>
            <VCardTitle>Most Maintained Assets</VCardTitle>
            <VCardSubtitle>Assets with highest maintenance spend in period</VCardSubtitle>
          </VCardItem>
          <VTable density="compact">
            <thead>
              <tr>
                <th>#</th>
                <th>Asset</th>
                <th class="text-right">Jobs</th>
                <th class="text-right">Total Cost</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!mostMaintained.length">
                <td colspan="4" class="text-center text-medium-emphasis pa-4">No maintenance data for this period.</td>
              </tr>
              <tr v-for="(a, i) in mostMaintained" :key="a.asset_number">
                <td><VChip size="x-small" :color="i < 3 ? 'warning' : 'default'" label>{{ i + 1 }}</VChip></td>
                <td>
                  <div class="font-weight-medium">{{ a.name }}</div>
                  <div class="text-caption text-medium-emphasis">{{ a.asset_number }}</div>
                </td>
                <td class="text-right">{{ a.jobs }}</td>
                <td class="text-right font-weight-medium">{{ fmtR(a.total_cost) }}</td>
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
