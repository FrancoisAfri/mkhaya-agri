<script setup lang="ts">
import VueApexCharts from 'vue3-apexcharts'
import { useTheme } from 'vuetify'
import type { ApexOptions } from 'apexcharts'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface KPIs {
  headcount: number; on_leave_today: number; coverage_pct: number
  pending: number; anomalies: number; burnout_risks: number
  violations: number; liability_days: number
}
interface LiabilityRow { leave_type: string; employee_count: number; total_hours: number; total_days: number }
interface DivisionRow { division: string; headcount: number; absent: number; pending: number; coverage_pct: number; burnout_flags: number; pressure: 'critical' | 'high' | 'medium' | 'low' }
interface BurnoutRow { employee_name: string; risk_score: number; days_used: string | number; sick_days: string | number; flagged_at: string }
interface TrendSeries { name: string; data: number[] }

const props = withDefaults(defineProps<{
  kpis: KPIs; liabilityByType: LiabilityRow[]; divisionPressure: DivisionRow[]
  trendSeries: TrendSeries[]; trendMonths: string[]; burnoutRisks: BurnoutRow[]
  aiSummary: string | null; year: number
}>(), {
  kpis: () => ({ headcount: 0, on_leave_today: 0, coverage_pct: 100, pending: 0, anomalies: 0, burnout_risks: 0, violations: 0, liability_days: 0 }),
  liabilityByType: () => [], divisionPressure: () => [], trendSeries: () => [], trendMonths: () => [], burnoutRisks: () => [], aiSummary: null,
  year: new Date().getFullYear(),
})

const vuetifyTheme = useTheme()

const pressureColor = (p: string) => ({ critical: 'error', high: 'deep-orange', medium: 'warning', low: 'success' }[p] ?? 'default')
const coverageColor = (pct: number) => pct >= 95 ? 'success' : pct >= 85 ? 'warning' : pct >= 70 ? 'deep-orange' : 'error'

const trendChartOptions = computed<ApexOptions>(() => ({
  chart: { type: 'bar', stacked: false, toolbar: { show: false }, fontFamily: 'inherit' },
  plotOptions: { bar: { columnWidth: '55%', borderRadius: 4 } },
  xaxis: { categories: props.trendMonths, labels: { style: { fontSize: '12px' } } },
  yaxis: { title: { text: 'Requests' }, min: 0 },
  legend: { position: 'top' }, tooltip: { shared: true, intersect: false },
  theme: { mode: vuetifyTheme.global.name.value as 'light' | 'dark' },
  grid: { borderColor: 'rgba(0,0,0,0.08)' },
}))

const liabilityDonutOptions = computed<ApexOptions>(() => ({
  chart: { type: 'donut', fontFamily: 'inherit' },
  labels: props.liabilityByType.map(r => r.leave_type),
  legend: { position: 'bottom' },
  theme: { mode: vuetifyTheme.global.name.value as 'light' | 'dark' },
  tooltip: { y: { formatter: (val: number) => `${val.toFixed(1)} ${t('leave_executive_dashboard.days_label')}` } },
  plotOptions: { pie: { donut: { size: '65%' } } },
}))
const liabilityDonutSeries = computed(() => props.liabilityByType.map(r => r.total_days))

const pressureChartOptions = computed<ApexOptions>(() => ({
  chart: { type: 'bar', horizontal: true, toolbar: { show: false }, fontFamily: 'inherit' },
  plotOptions: { bar: { borderRadius: 4, dataLabels: { position: 'top' } } },
  xaxis: { categories: props.divisionPressure.map(d => d.division), max: 100, labels: { formatter: (v: string) => `${v}%` } },
  yaxis: { labels: { style: { fontSize: '11px' } } },
  colors: props.divisionPressure.map(d => d.coverage_pct < 70 ? '#f44336' : d.coverage_pct < 85 ? '#ff9800' : d.coverage_pct < 95 ? '#ff6d00' : '#4caf50'),
  dataLabels: { enabled: true, formatter: (val: number) => `${val}%`, offsetX: 5 },
  tooltip: { y: { formatter: (v: number) => t('leave_executive_dashboard.coverage_pct_label', { pct: v }) } },
  theme: { mode: vuetifyTheme.global.name.value as 'light' | 'dark' },
  grid: { borderColor: 'rgba(0,0,0,0.08)' },
}))
const pressureSeries = computed(() => [{ name: 'Coverage %', data: props.divisionPressure.map(d => d.coverage_pct) }])

const divisionHeaders = computed(() => [
  { title: t('leave.division'),         key: 'division' },
  { title: t('leave.headcount'),        key: 'headcount' },
  { title: t('leave.absent'),           key: 'absent' },
  { title: t('leave.coverage'),         key: 'coverage_pct' },
  { title: t('leave.pending_requests'), key: 'pending' },
  { title: t('leave.burnout'),          key: 'burnout_flags' },
  { title: t('leave.pressure'),         key: 'pressure' },
])
</script>

<template>
  <VContainer fluid class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('leave.executive_dashboard') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">{{ t('leave_executive_dashboard.subtitle', { year: props.year }) }}</p>
      </div>
      <VChip color="primary" variant="tonal" prepend-icon="ri-government-line">{{ t('leave.leadership_view') }}</VChip>
    </div>

    <VRow class="mb-6">
      <VCol cols="6" sm="4" md="3" lg=""><VCard variant="tonal" color="primary" class="h-100"><VCardText class="pa-4"><p class="text-caption mb-1 text-medium-emphasis">{{ t('leave.headcount') }}</p><p class="text-h4 font-weight-bold">{{ props.kpis.headcount }}</p><p class="text-caption mt-1">{{ t('leave_executive_dashboard.active_employees') }}</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="4" md="3" lg=""><VCard variant="tonal" :color="coverageColor(props.kpis.coverage_pct)" class="h-100"><VCardText class="pa-4"><p class="text-caption mb-1 text-medium-emphasis">{{ t('leave.coverage_today') }}</p><p class="text-h4 font-weight-bold">{{ props.kpis.coverage_pct }}%</p><p class="text-caption mt-1">{{ t('leave_executive_dashboard.absent_count', { count: props.kpis.on_leave_today }) }}</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="4" md="3" lg=""><VCard variant="tonal" color="warning" class="h-100"><VCardText class="pa-4"><p class="text-caption mb-1 text-medium-emphasis">{{ t('leave.pending_approvals') }}</p><p class="text-h4 font-weight-bold">{{ props.kpis.pending }}</p><p class="text-caption mt-1">{{ t('leave_executive_dashboard.awaiting_action') }}</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="4" md="3" lg=""><VCard variant="tonal" color="info" class="h-100"><VCardText class="pa-4"><p class="text-caption mb-1 text-medium-emphasis">{{ t('leave.liability_days') }}</p><p class="text-h4 font-weight-bold">{{ props.kpis.liability_days.toLocaleString() }}</p><p class="text-caption mt-1">{{ t('leave_executive_dashboard.outstanding_balance') }}</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="4" md="3" lg=""><VCard variant="tonal" color="deep-orange" class="h-100"><VCardText class="pa-4"><p class="text-caption mb-1 text-medium-emphasis">{{ t('leave.burnout_risks') }}</p><p class="text-h4 font-weight-bold">{{ props.kpis.burnout_risks }}</p><p class="text-caption mt-1">{{ t('leave_executive_dashboard.flagged_employees') }}</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="4" md="3" lg=""><VCard variant="tonal" color="error" class="h-100"><VCardText class="pa-4"><p class="text-caption mb-1 text-medium-emphasis">{{ t('leave.open_violations') }}</p><p class="text-h4 font-weight-bold">{{ props.kpis.violations }}</p><p class="text-caption mt-1">{{ t('leave_executive_dashboard.policy_breaches') }}</p></VCardText></VCard></VCol>
    </VRow>

    <VCard v-if="props.aiSummary" variant="tonal" color="primary" class="mb-6">
      <VCardText class="pa-5">
        <div class="d-flex align-start gap-3">
          <VIcon color="primary" size="28" class="mt-1">ri-sparkling-line</VIcon>
          <div><p class="text-subtitle-2 font-weight-bold mb-2">{{ t('leave.ai_executive_summary') }}</p><p class="text-body-2" style="white-space: pre-wrap; line-height: 1.7">{{ props.aiSummary }}</p></div>
        </div>
      </VCardText>
    </VCard>

    <VRow class="mb-6">
      <VCol cols="12" md="8">
        <VCard variant="outlined" class="h-100">
          <VCardTitle class="pa-4 pb-2 d-flex align-center gap-2"><VIcon color="primary">ri-bar-chart-grouped-line</VIcon>{{ t('leave.leave_trends') }} — {{ props.year }}</VCardTitle>
          <VCardText>
            <VueApexCharts v-if="props.trendSeries.length" type="bar" height="280" :options="trendChartOptions" :series="props.trendSeries"></VueApexCharts>
            <div v-else class="pa-8 text-center text-medium-emphasis">{{ t('leave_executive_dashboard.no_leave_data', { year: props.year }) }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="4">
        <VCard variant="outlined" class="h-100">
          <VCardTitle class="pa-4 pb-2 d-flex align-center gap-2"><VIcon color="info">ri-money-dollar-circle-line</VIcon>{{ t('leave.liability_by_type') }}</VCardTitle>
          <VCardText>
            <VueApexCharts v-if="liabilityDonutSeries.length" type="donut" height="280" :options="liabilityDonutOptions" :series="liabilityDonutSeries"></VueApexCharts>
            <div v-else class="pa-8 text-center text-medium-emphasis">{{ t('leave_executive_dashboard.no_liability_data') }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard variant="outlined" class="mb-6">
      <VCardTitle class="pa-4 pb-2 d-flex align-center gap-2"><VIcon color="warning">ri-building-line</VIcon>{{ t('leave.department_pressure') }}</VCardTitle>
      <VRow class="pa-4 pt-0">
        <VCol cols="12" md="7">
          <VueApexCharts v-if="props.divisionPressure.length" type="bar" :height="Math.max(200, props.divisionPressure.length * 38)" :options="pressureChartOptions" :series="pressureSeries"></VueApexCharts>
          <div v-else class="pa-6 text-center text-medium-emphasis">{{ t('leave_executive_dashboard.no_division_data') }}</div>
        </VCol>
        <VCol cols="12" md="5">
          <VDataTable :headers="divisionHeaders" :items="props.divisionPressure" :items-per-page="-1" density="compact" hide-default-footer>
            <template #item.coverage_pct="{ item }"><VChip :color="coverageColor(item.coverage_pct)" size="x-small" variant="tonal">{{ item.coverage_pct }}%</VChip></template>
            <template #item.pressure="{ item }"><VChip :color="pressureColor(item.pressure)" size="x-small" variant="tonal">{{ item.pressure }}</VChip></template>
            <template #no-data><div class="pa-4 text-center text-medium-emphasis">{{ t('leave_executive_dashboard.no_department_data') }}</div></template>
          </VDataTable>
        </VCol>
      </VRow>
    </VCard>

    <VRow>
      <VCol cols="12" md="6">
        <VCard variant="outlined" class="h-100">
          <VCardTitle class="pa-4 pb-2 d-flex align-center gap-2"><VIcon color="deep-orange">ri-fire-line</VIcon>{{ t('leave.top_burnout_risks') }}<VChip v-if="props.burnoutRisks.length" size="x-small" color="deep-orange" class="ms-1">{{ props.burnoutRisks.length }}</VChip></VCardTitle>
          <VCardText class="pa-0">
            <template v-if="props.burnoutRisks.length">
              <div v-for="(emp, i) in props.burnoutRisks" :key="i" class="d-flex align-center justify-space-between px-4 py-3" :class="{ 'border-b': i < props.burnoutRisks.length - 1 }">
                <div><p class="text-body-2 font-weight-medium">{{ emp.employee_name }}</p><p class="text-caption text-medium-emphasis">{{ t('leave_executive_dashboard.days_used_label') }} {{ emp.days_used }} &nbsp;|&nbsp; {{ t('leave_executive_dashboard.sick_label') }} {{ emp.sick_days }} &nbsp;|&nbsp; {{ emp.flagged_at }}</p></div>
                <VChip :color="emp.risk_score >= 0.7 ? 'error' : emp.risk_score >= 0.4 ? 'warning' : 'success'" size="small" variant="tonal">{{ Math.round(emp.risk_score * 100) }}%</VChip>
              </div>
            </template>
            <div v-else class="pa-6 text-center text-medium-emphasis">{{ t('leave_executive_dashboard.no_burnout_flagged') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="6">
        <VCard variant="outlined" class="h-100">
          <VCardTitle class="pa-4 pb-2 d-flex align-center gap-2"><VIcon color="info">ri-stack-line</VIcon>{{ t('leave.liability_breakdown') }}</VCardTitle>
          <VCardText class="pa-0">
            <template v-if="props.liabilityByType.length">
              <div v-for="(row, i) in props.liabilityByType" :key="i" class="d-flex align-center justify-space-between px-4 py-3" :class="{ 'border-b': i < props.liabilityByType.length - 1 }">
                <div><p class="text-body-2 font-weight-medium">{{ row.leave_type }}</p><p class="text-caption text-medium-emphasis">{{ row.employee_count }} {{ t('leave_executive_dashboard.employees_label') }}</p></div>
                <div class="text-right"><p class="text-body-2 font-weight-bold">{{ row.total_days.toLocaleString() }} {{ t('leave_executive_dashboard.days_label') }}</p><p class="text-caption text-medium-emphasis">{{ row.total_hours.toLocaleString() }} {{ t('leave_executive_dashboard.hrs_label') }}</p></div>
              </div>
            </template>
            <div v-else class="pa-6 text-center text-medium-emphasis">{{ t('leave_executive_dashboard.no_outstanding_liabilities') }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </VContainer>
</template>
