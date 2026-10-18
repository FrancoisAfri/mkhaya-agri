<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useTheme } from 'vuetify'
import { useI18n } from 'vue-i18n'
import VueApexCharts from 'vue3-apexcharts'
import type { ApexOptions } from 'apexcharts'

const { t } = useI18n()
useTheme()
const success   = '#28c76f'
const warning   = '#ff9f43'
const error     = '#ea5455'
const info      = '#00cfe8'
const secondary = '#82868b'

// ── Props ─────────────────────────────────────────────────────────────────────
interface Kpis {
  total_vehicles: number; in_use: number; utilization_pct: number
  total_bookings: number; completed_bookings: number; total_km: number
  fuel_cost: number; fuel_litres: number; maintenance_cost: number
  outstanding_fines: number; aarto_points_exposure: number; incidents: number
}
interface TrendPoint   { month: string; total: number; completed: number; cancelled: number }
interface FuelPoint    { month: string; cost: number; litres: number }
interface FuelByType   { vehicle_type: string; avg_consumption: number; total_cost: number }
interface MaintCat     { category: string; count: number; total_cost: number }
interface MaintStatus  { status: string; count: number }
interface FineStatus   { status: string; count: number; total_amount: number }
interface FineAuth     { authority: string; count: number; total_amount: number }
interface CostBreak    { fuel: number; maintenance: number; fines: number; tollgates: number; total: number }
interface TopVehicle   { registration: string; make_model: string; trips: number; total_km: number; fuel_cost: number }
interface TopDriver    { name: string; trips: number; total_km: number; avg_consumption: number | null }
interface FilterOptions {
  vehicle_types: string[]
  fuel_types: string[]
  departments: { id: number; name: string }[]
  vehicles: { id: number; label: string }[]
  drivers: { id: number; name: string }[]
}
interface ActiveFilters {
  vehicle_type: string | null
  fuel_type: string | null
  department_id: number | null
  vehicle_id: number | null
  driver_id: number | null
}

const props = defineProps<{
  period: string
  dateFrom: string | null
  dateTo: string | null
  filters: ActiveFilters
  filterOptions: FilterOptions
  kpis: Kpis
  vehicleStatus: { status: string; count: number }[]
  bookingTrend: TrendPoint[]
  fuelTrend: FuelPoint[]
  fuelByVehicleType: FuelByType[]
  maintenanceByCategory: MaintCat[]
  maintenanceByStatus: MaintStatus[]
  finesByStatus: FineStatus[]
  finesByAuthority: FineAuth[]
  costBreakdown: CostBreak
  topVehicles: TopVehicle[]
  topDrivers: TopDriver[]
}>()

// ── Filter state ──────────────────────────────────────────────────────────────
const filtersOpen    = ref(false)
const periodOptions  = [
  { value: 'month',    label: t('fleet_analytics_index.periods.month') },
  { value: 'quarter',  label: t('fleet_analytics_index.periods.quarter') },
  { value: 'halfyear', label: t('fleet_analytics_index.periods.halfyear') },
  { value: 'year',     label: t('fleet_analytics_index.periods.year') },
  { value: 'lastyear', label: t('fleet_analytics_index.periods.lastyear') },
]

const form = ref({
  period:       props.period ?? 'year',
  date_from:    props.dateFrom ?? '',
  date_to:      props.dateTo   ?? '',
  vehicle_type: props.filters.vehicle_type  ?? '',
  fuel_type:    props.filters.fuel_type     ?? '',
  department_id:props.filters.department_id ? String(props.filters.department_id) : '',
  vehicle_id:   props.filters.vehicle_id    ? String(props.filters.vehicle_id)    : '',
  driver_id:    props.filters.driver_id     ? String(props.filters.driver_id)     : '',
})

const useCustomDates = ref(!!(props.dateFrom || props.dateTo))

const activeFilterCount = computed(() => {
  const f = props.filters
  return [f.vehicle_type, f.fuel_type, f.department_id, f.vehicle_id, f.driver_id].filter(Boolean).length
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

  if (form.value.vehicle_type)  params.vehicle_type   = form.value.vehicle_type
  if (form.value.fuel_type)     params.fuel_type       = form.value.fuel_type
  if (form.value.department_id) params.department_id  = form.value.department_id
  if (form.value.vehicle_id)    params.vehicle_id      = form.value.vehicle_id
  if (form.value.driver_id)     params.driver_id       = form.value.driver_id

  router.get(window.location.pathname, params, { preserveScroll: true })
}

function clearFilters() {
  form.value = { period: 'year', date_from: '', date_to: '', vehicle_type: '', fuel_type: '', department_id: '', vehicle_id: '', driver_id: '' }
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
  { title: t('fleet_analytics_index.kpis.total_bookings'),    value: fmtN(props.kpis.total_bookings),    sub: t('fleet_analytics_index.kpis.completed_sub', { count: props.kpis.completed_bookings }), icon: 'tabler-calendar-check', color: 'primary' },
  { title: t('fleet_analytics_index.kpis.utilization'),       value: `${props.kpis.utilization_pct}%`,   sub: t('fleet_analytics_index.kpis.utilization_sub', { used: props.kpis.in_use, total: props.kpis.total_vehicles }), icon: 'tabler-cars', color: 'success' },
  { title: t('fleet_analytics_index.kpis.total_distance'),    value: `${fmtN(props.kpis.total_km)} km`,  sub: t('fleet_analytics_index.kpis.distance_sub'),    icon: 'tabler-map-2', color: 'info' },
  { title: t('fleet_analytics_index.kpis.fuel_spend'),        value: fmtR(props.kpis.fuel_cost),         sub: t('fleet_analytics_index.kpis.fuel_sub', { litres: fmtN(props.kpis.fuel_litres) }), icon: 'tabler-gas-station', color: 'warning' },
  { title: t('fleet_analytics_index.kpis.maintenance_cost'),  value: fmtR(props.kpis.maintenance_cost),  sub: t('fleet_analytics_index.kpis.maintenance_sub'), icon: 'tabler-tool', color: 'secondary' },
  { title: t('fleet_analytics_index.kpis.outstanding_fines'), value: fmtR(props.kpis.outstanding_fines), sub: t('fleet_analytics_index.kpis.fines_sub', { points: props.kpis.aarto_points_exposure }), icon: 'tabler-shield-star', color: 'error' },
])

// ── Booking trend chart ───────────────────────────────────────────────────────
const bookingTrendOptions = computed<ApexOptions>(() => ({
  chart:   { type: 'line', toolbar: { show: false }, zoom: { enabled: false } },
  stroke:  { curve: 'smooth', width: [3, 3, 2] },
  colors:  ['#696cff', success, error],
  xaxis:   { categories: props.bookingTrend.map(r => monthLabel(r.month)) },
  yaxis:   { labels: { formatter: (v: number) => String(Math.round(v)) } },
  tooltip: { y: { formatter: (v: number) => t('fleet_analytics_index.charts.bookings_tooltip', { count: v }) } },
  legend:  { position: 'top' },
  markers: { size: 4 },
  grid:    { borderColor: '#f1f3f4' },
}))
const bookingTrendSeries = computed(() => [
  { name: t('fleet_analytics_index.charts.total'),     data: props.bookingTrend.map(r => r.total) },
  { name: t('fleet_analytics_index.charts.completed'), data: props.bookingTrend.map(r => r.completed) },
  { name: t('fleet_analytics_index.charts.cancelled'), data: props.bookingTrend.map(r => r.cancelled) },
])

// ── Vehicle status donut ──────────────────────────────────────────────────────
const statusColors: Record<string, string> = {
  available:   success,
  booked:      '#696cff',
  maintenance: warning,
  offroad:     error,
  disposed:    secondary,
}
const vehicleStatusOptions = computed<ApexOptions>(() => ({
  chart:   { type: 'donut' },
  labels:  props.vehicleStatus.map(r => r.status),
  colors:  props.vehicleStatus.map(r => statusColors[r.status] ?? secondary),
  legend:  { position: 'bottom' },
  plotOptions: { pie: { donut: { size: '65%', labels: { show: true, total: { show: true, label: t('fleet_analytics_index.charts.total_label') } } } } },
  dataLabels: { enabled: false },
}))
const vehicleStatusSeries = computed(() => props.vehicleStatus.map(r => r.count))

// ── Fuel spend area chart ─────────────────────────────────────────────────────
const fuelTrendOptions = computed<ApexOptions>(() => ({
  chart:   { type: 'area', toolbar: { show: false }, zoom: { enabled: false } },
  stroke:  { curve: 'smooth', width: 2 },
  fill:    { type: 'gradient', gradient: { opacityFrom: 0.4, opacityTo: 0.05 } },
  colors:  [warning],
  xaxis:   { categories: props.fuelTrend.map(r => monthLabel(r.month)) },
  yaxis:   { labels: { formatter: (v: number) => fmtR(v) } },
  tooltip: { y: { formatter: (v: number) => fmtR(v) } },
  grid:    { borderColor: '#f1f3f4' },
}))
const fuelTrendSeries = computed(() => [
  { name: t('fleet_analytics_index.charts.fuel_spend'), data: props.fuelTrend.map(r => r.cost) },
])

// ── Fuel efficiency horizontal bar ────────────────────────────────────────────
const fuelEfficiencyOptions = computed<ApexOptions>(() => ({
  chart:   { type: 'bar', toolbar: { show: false } },
  plotOptions: { bar: { horizontal: true, borderRadius: 4, dataLabels: { position: 'top' } } },
  colors:  [info],
  xaxis:   {
    categories: props.fuelByVehicleType.map(r => r.vehicle_type),
    labels: { formatter: (v: string) => t('fleet_analytics_index.charts.l_per_100km', { value: v }) },
  },
  tooltip: { y: { formatter: (v: number) => t('fleet_analytics_index.charts.l_per_100km', { value: String(v) }) } },
  dataLabels: { enabled: true, formatter: (v: number) => `${v}`, offsetX: 25 },
  grid: { borderColor: '#f1f3f4' },
}))
const fuelEfficiencySeries = computed(() => [
  { name: t('fleet_analytics_index.charts.avg_consumption'), data: props.fuelByVehicleType.map(r => r.avg_consumption) },
])

// ── Maintenance cost by category bar ─────────────────────────────────────────
const maintCatOptions = computed<ApexOptions>(() => ({
  chart:   { type: 'bar', toolbar: { show: false } },
  plotOptions: { bar: { borderRadius: 4, columnWidth: '55%' } },
  colors:  ['#696cff'],
  xaxis:   { categories: props.maintenanceByCategory.map(r => r.category) },
  yaxis:   { labels: { formatter: (v: number) => fmtR(v) } },
  tooltip: { y: { formatter: (v: number) => fmtR(v) } },
  dataLabels: { enabled: false },
  grid: { borderColor: '#f1f3f4' },
}))
const maintCatSeries = computed(() => [
  { name: t('fleet_analytics_index.charts.cost'), data: props.maintenanceByCategory.map(r => r.total_cost) },
])

// ── Maintenance status donut ──────────────────────────────────────────────────
const maintStatusColors: Record<string, string> = {
  scheduled:   '#696cff',
  in_progress: warning,
  completed:   success,
  cancelled:   secondary,
}
const maintStatusOptions = computed<ApexOptions>(() => ({
  chart:   { type: 'donut' },
  labels:  props.maintenanceByStatus.map(r => r.status),
  colors:  props.maintenanceByStatus.map(r => maintStatusColors[r.status] ?? secondary),
  legend:  { position: 'bottom' },
  dataLabels: { enabled: true },
  plotOptions: { pie: { donut: { size: '65%' } } },
}))
const maintStatusSeries = computed(() => props.maintenanceByStatus.map(r => r.count))

// ── Fines by status donut ─────────────────────────────────────────────────────
const fineStatusColors: Record<string, string> = {
  outstanding: error,
  paid:        success,
  disputed:    info,
  written_off: secondary,
}
const fineStatusOptions = computed<ApexOptions>(() => ({
  chart:   { type: 'donut' },
  labels:  props.finesByStatus.map(r => r.status),
  colors:  props.finesByStatus.map(r => fineStatusColors[r.status] ?? secondary),
  legend:  { position: 'bottom' },
  dataLabels: { enabled: false },
  plotOptions: { pie: { donut: { size: '65%', labels: { show: true, total: { show: true, label: t('fleet_analytics_index.charts.total_label'), formatter: (w: any) => `R${w.globals.seriesTotals.reduce((a: number, b: number) => a + b, 0).toLocaleString('en-ZA')}` } } } } },
}))
const fineStatusSeries = computed(() => props.finesByStatus.map(r => r.total_amount))

// ── Fines by authority bar ────────────────────────────────────────────────────
const fineAuthOptions = computed<ApexOptions>(() => ({
  chart:   { type: 'bar', toolbar: { show: false } },
  plotOptions: { bar: { horizontal: true, borderRadius: 4 } },
  colors:  [error],
  xaxis:   { categories: props.finesByAuthority.map(r => r.authority) },
  yaxis:   { labels: { formatter: (v: number) => fmtR(v) } },
  tooltip: { y: { formatter: (v: number) => fmtR(v) } },
  dataLabels: { enabled: false },
  grid: { borderColor: '#f1f3f4' },
}))
const fineAuthSeries = computed(() => [
  { name: t('fleet_analytics_index.charts.amount'), data: props.finesByAuthority.map(r => r.total_amount) },
])

// ── Cost breakdown donut ──────────────────────────────────────────────────────
const costBreakdownOptions = computed<ApexOptions>(() => ({
  chart:   { type: 'donut' },
  labels:  [t('fleet_analytics_index.charts.fuel'), t('fleet_analytics_index.charts.maintenance'), t('fleet_analytics_index.charts.fines'), t('fleet_analytics_index.charts.tollgates')],
  colors:  [warning, '#696cff', error, info],
  legend:  { position: 'bottom' },
  dataLabels: { enabled: false },
  plotOptions: {
    pie: {
      donut: {
        size: '70%',
        labels: {
          show: true,
          total: {
            show: true,
            label: t('fleet_analytics_index.charts.total_cost'),
            formatter: (w: any) => `R${w.globals.seriesTotals.reduce((a: number, b: number) => a + b, 0).toLocaleString('en-ZA')}`,
          },
        },
      },
    },
  },
}))
const costBreakdownSeries = computed(() => [
  props.costBreakdown.fuel,
  props.costBreakdown.maintenance,
  props.costBreakdown.fines,
  props.costBreakdown.tollgates,
])
</script>

<template>
  <div class="pa-4">
    <!-- ── Header ──────────────────────────────────────────────────────────── -->
    <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-4">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('fleet_analytics_index.header.subtitle') }}</div>
        <h4 class="text-h4 font-weight-bold">{{ t('fleet_analytics_index.header.title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('fleet_analytics_index.header.description') }}</p>
      </div>
      <div class="d-flex align-center gap-2 flex-wrap">
        <VBtn
          variant="tonal"
          prepend-icon="tabler-adjustments-horizontal"
          @click="filtersOpen = !filtersOpen"
        >
          {{ t('fleet_analytics_index.filters.title') }}
          <VBadge
            v-if="activeFilterCount > 0"
            :content="activeFilterCount"
            color="primary"
            floating
          />
        </VBtn>
        <VBtn variant="tonal" prepend-icon="tabler-file-analytics" href="/fleet/reports">{{ t('fleet_analytics_index.reports') }}</VBtn>
      </div>
    </div>

    <!-- ── Filter panel ────────────────────────────────────────────────────── -->
    <VExpandTransition>
      <VCard v-if="filtersOpen" class="mb-5" variant="outlined">
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12" class="pb-1">
              <VBtnToggle v-model="useCustomDates" density="compact" variant="outlined" mandatory>
                <VBtn :value="false" size="small">{{ t('fleet_analytics_index.filters.period_preset') }}</VBtn>
                <VBtn :value="true"  size="small">{{ t('fleet_analytics_index.filters.custom_dates') }}</VBtn>
              </VBtnToggle>
            </VCol>

            <VCol v-if="!useCustomDates" cols="12" sm="6" md="3">
              <VSelect
                v-model="form.period"
                :items="periodOptions"
                item-title="label"
                item-value="value"
                :label="t('fleet_analytics_index.filters.period')"
                density="compact"
                variant="outlined"
                hide-details
              />
            </VCol>

            <template v-if="useCustomDates">
              <VCol cols="12" sm="6" md="3">
                <VTextField
                  v-model="form.date_from"
                  type="date"
                  :label="t('fleet_analytics_index.filters.from_date')"
                  density="compact"
                  variant="outlined"
                  hide-details
                />
              </VCol>
              <VCol cols="12" sm="6" md="3">
                <VTextField
                  v-model="form.date_to"
                  type="date"
                  :label="t('fleet_analytics_index.filters.to_date')"
                  density="compact"
                  variant="outlined"
                  hide-details
                />
              </VCol>
            </template>

            <VCol cols="12" sm="6" md="3">
              <VSelect
                v-model="form.vehicle_type"
                :items="filterOptions.vehicle_types"
                :label="t('fleet_analytics_index.filters.vehicle_type')"
                density="compact"
                variant="outlined"
                hide-details
                clearable
              />
            </VCol>

            <VCol cols="12" sm="6" md="3">
              <VSelect
                v-model="form.fuel_type"
                :items="filterOptions.fuel_types"
                :label="t('fleet_analytics_index.filters.fuel_type')"
                density="compact"
                variant="outlined"
                hide-details
                clearable
              />
            </VCol>

            <VCol cols="12" sm="6" md="3">
              <VSelect
                v-model="form.department_id"
                :items="filterOptions.departments"
                item-title="name"
                item-value="id"
                :label="t('fleet_analytics_index.filters.department')"
                density="compact"
                variant="outlined"
                hide-details
                clearable
              />
            </VCol>

            <VCol cols="12" sm="6" md="4">
              <VAutocomplete
                v-model="form.vehicle_id"
                :items="filterOptions.vehicles"
                item-title="label"
                item-value="id"
                :label="t('fleet_analytics_index.filters.specific_vehicle')"
                density="compact"
                variant="outlined"
                hide-details
                clearable
              />
            </VCol>

            <VCol cols="12" sm="6" md="4">
              <VAutocomplete
                v-model="form.driver_id"
                :items="filterOptions.drivers"
                item-title="name"
                item-value="id"
                :label="t('fleet_analytics_index.filters.specific_driver')"
                density="compact"
                variant="outlined"
                hide-details
                clearable
              />
            </VCol>

            <VCol cols="12" class="d-flex gap-2 pt-2">
              <VBtn color="primary" prepend-icon="tabler-search" @click="applyFilters">{{ t('fleet_analytics_index.filters.apply') }}</VBtn>
              <VBtn variant="tonal"  prepend-icon="tabler-x" @click="clearFilters">{{ t('fleet_analytics_index.filters.clear') }}</VBtn>
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
      <VChip v-if="filters.vehicle_type"  size="small" closable color="secondary" variant="tonal" @click:close="() => { form.vehicle_type = ''; applyFilters() }">
        {{ t('fleet_analytics_index.filters.chip_type') }}: {{ filters.vehicle_type }}
      </VChip>
      <VChip v-if="filters.fuel_type"     size="small" closable color="secondary" variant="tonal" @click:close="() => { form.fuel_type = ''; applyFilters() }">
        {{ t('fleet_analytics_index.filters.chip_fuel') }}: {{ filters.fuel_type }}
      </VChip>
      <VChip v-if="filters.department_id" size="small" closable color="secondary" variant="tonal" @click:close="() => { form.department_id = ''; applyFilters() }">
        {{ t('fleet_analytics_index.filters.chip_dept') }}: {{ filterOptions.departments.find(d => d.id === filters.department_id)?.name }}
      </VChip>
      <VChip v-if="filters.vehicle_id"    size="small" closable color="secondary" variant="tonal" @click:close="() => { form.vehicle_id = ''; applyFilters() }">
        {{ t('fleet_analytics_index.filters.chip_vehicle') }}: {{ filterOptions.vehicles.find(v => v.id === filters.vehicle_id)?.label }}
      </VChip>
      <VChip v-if="filters.driver_id"     size="small" closable color="secondary" variant="tonal" @click:close="() => { form.driver_id = ''; applyFilters() }">
        {{ t('fleet_analytics_index.filters.chip_driver') }}: {{ filterOptions.drivers.find(d => d.id === filters.driver_id)?.name }}
      </VChip>
    </div>

    <!-- ── KPI cards ───────────────────────────────────────────────────────── -->
    <VRow class="mb-4">
      <VCol
        v-for="card in kpiCards"
        :key="card.title"
        cols="12" sm="6" md="4" lg="2"
      >
        <VCard height="100%">
          <VCardText class="pa-4">
            <div class="d-flex align-center justify-space-between mb-2">
              <VIcon :color="card.color" size="32">{{ card.icon }}</VIcon>
            </div>
            <div class="text-h5 font-weight-bold">{{ card.value }}</div>
            <div class="text-subtitle-2 font-weight-semibold mt-1">{{ card.title }}</div>
            <div class="text-caption text-medium-emphasis">{{ card.sub }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Row 2: Booking Trend + Vehicle Status ───────────────────────────── -->
    <VRow class="mb-4">
      <VCol cols="12" md="8">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>{{ t('fleet_analytics_index.charts.booking_trend') }}</VCardTitle>
            <VCardSubtitle>{{ t('fleet_analytics_index.charts.booking_trend_sub') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts
              v-if="bookingTrend.length"
              type="line"
              :height="280"
              :options="bookingTrendOptions"
              :series="bookingTrendSeries"
            />
            <div v-else class="text-center pa-8 text-medium-emphasis">
              <VIcon icon="tabler-chart-line" size="80" class="mb-2" />
              <div>{{ t('fleet_analytics_index.empty.no_booking_data') }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>{{ t('fleet_analytics_index.charts.fleet_status') }}</VCardTitle>
            <VCardSubtitle>{{ t('fleet_analytics_index.charts.fleet_status_sub') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts
              v-if="vehicleStatus.length"
              type="donut"
              :height="280"
              :options="vehicleStatusOptions"
              :series="vehicleStatusSeries"
            />
            <div v-else class="text-center pa-8 text-medium-emphasis">
              <VIcon icon="tabler-car" size="60" class="mb-2" />
              <div>{{ t('fleet_analytics_index.empty.no_vehicles') }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Row 3: Fuel Spend + Efficiency ─────────────────────────────────── -->
    <VRow class="mb-4">
      <VCol cols="12" md="7">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>{{ t('fleet_analytics_index.charts.fuel_spend_trend') }}</VCardTitle>
            <VCardSubtitle>{{ t('fleet_analytics_index.charts.fuel_spend_trend_sub') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts
              v-if="fuelTrend.length"
              type="area"
              :height="260"
              :options="fuelTrendOptions"
              :series="fuelTrendSeries"
            />
            <div v-else class="text-center pa-8 text-medium-emphasis">
              <VIcon icon="tabler-gas-station" size="70" class="mb-2" />
              <div>{{ t('fleet_analytics_index.empty.no_fuel_data') }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="5">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>{{ t('fleet_analytics_index.charts.fuel_efficiency') }}</VCardTitle>
            <VCardSubtitle>{{ t('fleet_analytics_index.charts.fuel_efficiency_sub') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts
              v-if="fuelByVehicleType.length"
              type="bar"
              :height="260"
              :options="fuelEfficiencyOptions"
              :series="fuelEfficiencySeries"
            />
            <div v-else class="text-center pa-8 text-medium-emphasis">
              <VIcon icon="tabler-gauge" size="70" class="mb-2" />
              <div>{{ t('fleet_analytics_index.empty.no_consumption_data') }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Row 4: Maintenance ─────────────────────────────────────────────── -->
    <VRow class="mb-4">
      <VCol cols="12" md="8">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>{{ t('fleet_analytics_index.charts.maintenance_cost') }}</VCardTitle>
            <VCardSubtitle>{{ t('fleet_analytics_index.charts.maintenance_cost_sub') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts
              v-if="maintenanceByCategory.length"
              type="bar"
              :height="260"
              :options="maintCatOptions"
              :series="maintCatSeries"
            />
            <div v-else class="text-center pa-8 text-medium-emphasis">
              <VIcon icon="tabler-tool" size="70" class="mb-2" />
              <div>{{ t('fleet_analytics_index.empty.no_maintenance_data') }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>{{ t('fleet_analytics_index.charts.maintenance_status') }}</VCardTitle>
            <VCardSubtitle>{{ t('fleet_analytics_index.charts.maintenance_status_sub') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts
              v-if="maintenanceByStatus.length"
              type="donut"
              :height="260"
              :options="maintStatusOptions"
              :series="maintStatusSeries"
            />
            <div v-else class="text-center pa-8 text-medium-emphasis">
              <VIcon icon="tabler-clipboard-list" size="60" class="mb-2" />
              <div>{{ t('fleet_analytics_index.empty.no_maintenance') }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Row 5: Fines ───────────────────────────────────────────────────── -->
    <VRow class="mb-4">
      <VCol cols="12" md="5">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>{{ t('fleet_analytics_index.charts.fines_by_status') }}</VCardTitle>
            <VCardSubtitle>{{ t('fleet_analytics_index.charts.fines_by_status_sub') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts
              v-if="finesByStatus.length"
              type="donut"
              :height="260"
              :options="fineStatusOptions"
              :series="fineStatusSeries"
            />
            <div v-else class="text-center pa-8 text-medium-emphasis">
              <VIcon icon="tabler-shield-star" size="70" class="mb-2" />
              <div>{{ t('fleet_analytics_index.empty.no_fines_data') }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="7">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>{{ t('fleet_analytics_index.charts.fines_by_authority') }}</VCardTitle>
            <VCardSubtitle>{{ t('fleet_analytics_index.charts.fines_by_authority_sub') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts
              v-if="finesByAuthority.length"
              type="bar"
              :height="260"
              :options="fineAuthOptions"
              :series="fineAuthSeries"
            />
            <div v-else class="text-center pa-8 text-medium-emphasis">
              <VIcon icon="tabler-gavel" size="70" class="mb-2" />
              <div>{{ t('fleet_analytics_index.empty.no_fines_authority') }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Row 6: Cost Breakdown + Top Vehicles ──────────────────────────── -->
    <VRow class="mb-4">
      <VCol cols="12" md="4">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>{{ t('fleet_analytics_index.charts.cost_breakdown') }}</VCardTitle>
            <VCardSubtitle>{{ t('fleet_analytics_index.charts.cost_breakdown_sub') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts
              type="donut"
              :height="280"
              :options="costBreakdownOptions"
              :series="costBreakdownSeries"
            />
            <VTable density="compact" class="mt-3">
              <tbody>
                <tr v-for="(val, key) in { [t('fleet_analytics_index.charts.fuel')]: costBreakdown.fuel, [t('fleet_analytics_index.charts.maintenance')]: costBreakdown.maintenance, [t('fleet_analytics_index.charts.fines')]: costBreakdown.fines, [t('fleet_analytics_index.charts.tollgates')]: costBreakdown.tollgates }" :key="key">
                  <td class="text-body-2">{{ key }}</td>
                  <td class="text-right font-weight-medium">{{ fmtR(val) }}</td>
                  <td class="text-right text-caption text-medium-emphasis" style="width:60px">
                    {{ costBreakdown.total > 0 ? Math.round(val / costBreakdown.total * 100) : 0 }}%
                  </td>
                </tr>
                <tr>
                  <td class="font-weight-bold">{{ t('fleet_analytics_index.charts.total') }}</td>
                  <td class="text-right font-weight-bold">{{ fmtR(costBreakdown.total) }}</td>
                  <td></td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="8">
        <VCard height="100%">
          <VCardItem>
            <VCardTitle>{{ t('fleet_analytics_index.charts.top_vehicles') }}</VCardTitle>
            <VCardSubtitle>{{ t('fleet_analytics_index.charts.top_vehicles_sub') }}</VCardSubtitle>
          </VCardItem>
          <VTable density="compact">
            <thead>
              <tr>
                <th>#</th>
                <th>{{ t('fleet_analytics_index.table.registration') }}</th>
                <th>{{ t('fleet_analytics_index.table.vehicle') }}</th>
                <th class="text-right">{{ t('fleet_analytics_index.table.trips') }}</th>
                <th class="text-right">{{ t('fleet_analytics_index.table.distance_km') }}</th>
                <th class="text-right">{{ t('fleet_analytics_index.table.fuel_cost') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!topVehicles.length">
                <td colspan="6" class="text-center text-medium-emphasis pa-4">{{ t('fleet_analytics_index.empty.no_trip_data') }}</td>
              </tr>
              <tr v-for="(v, i) in topVehicles" :key="v.registration">
                <td>
                  <VChip size="x-small" :color="i < 3 ? 'primary' : 'default'" label>{{ i + 1 }}</VChip>
                </td>
                <td class="font-weight-medium">{{ v.registration }}</td>
                <td class="text-medium-emphasis">{{ v.make_model || '—' }}</td>
                <td class="text-right">{{ fmtN(v.trips) }}</td>
                <td class="text-right">{{ fmtN(v.total_km) }}</td>
                <td class="text-right">{{ v.fuel_cost > 0 ? fmtR(v.fuel_cost) : '—' }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Row 7: Top Drivers ─────────────────────────────────────────────── -->
    <VRow>
      <VCol cols="12">
        <VCard>
          <VCardItem>
            <VCardTitle>{{ t('fleet_analytics_index.charts.top_drivers') }}</VCardTitle>
            <VCardSubtitle>{{ t('fleet_analytics_index.charts.top_drivers_sub') }}</VCardSubtitle>
          </VCardItem>
          <VTable density="compact">
            <thead>
              <tr>
                <th>#</th>
                <th>{{ t('fleet_analytics_index.table.driver') }}</th>
                <th class="text-right">{{ t('fleet_analytics_index.table.trips') }}</th>
                <th class="text-right">{{ t('fleet_analytics_index.table.distance_km') }}</th>
                <th class="text-right">{{ t('fleet_analytics_index.table.avg_consumption') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!topDrivers.length">
                <td colspan="5" class="text-center text-medium-emphasis pa-4">{{ t('fleet_analytics_index.empty.no_driver_data') }}</td>
              </tr>
              <tr v-for="(d, i) in topDrivers" :key="d.name">
                <td>
                  <VChip size="x-small" :color="i < 3 ? 'success' : 'default'" label>{{ i + 1 }}</VChip>
                </td>
                <td class="font-weight-medium">{{ d.name }}</td>
                <td class="text-right">{{ fmtN(d.trips) }}</td>
                <td class="text-right">{{ fmtN(d.total_km) }}</td>
                <td class="text-right">
                  <span v-if="d.avg_consumption">{{ d.avg_consumption }}</span>
                  <span v-else class="text-medium-emphasis">—</span>
                </td>
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
