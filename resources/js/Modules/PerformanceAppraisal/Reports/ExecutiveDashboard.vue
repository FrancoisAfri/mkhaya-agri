<script setup lang="ts">
import { computed } from 'vue'
import VueApexCharts from 'vue3-apexcharts'
import { useTheme } from 'vuetify'
import { useI18n } from 'vue-i18n'
import type { ApexOptions } from 'apexcharts'

const { t } = useI18n()

interface Kpis { total_employees: number; avg_score: number; high_performers: number; low_performers: number; kpi_completion_pct: number; active_pips: number; burnout_risks: number; open_violations: number }
interface DistRow  { band: string; count: number; percentage: number }
interface DeptRow  { department: string; avg_score: number; employee_count: number; cycle: string }
interface CycleRow { cycle: string; avg_score: number; participants: number }
interface StatusRow{ status: string; count: number }
interface TypeRow  { type: string; count: number }
interface EmployeeRow{ name: string; score: number }

const props = withDefaults(defineProps<{
  kpis: Kpis; distribution: DistRow[]; deptScores: DeptRow[]; cycleTrend: CycleRow[]
  pipByStatus: StatusRow[]; feedbackByType: TypeRow[]; kpiByStatus: StatusRow[]; topEmployees: EmployeeRow[]
}>(), {
  kpis: () => ({ total_employees: 0, avg_score: 0, high_performers: 0, low_performers: 0, kpi_completion_pct: 0, active_pips: 0, burnout_risks: 0, open_violations: 0 }),
  distribution: () => [], deptScores: () => [], cycleTrend: () => [], pipByStatus: () => [], feedbackByType: () => [], kpiByStatus: () => [], topEmployees: () => [],
})

const vuetifyTheme = useTheme()
const isDark = computed(() => vuetifyTheme.global.name.value === 'dark')
const themeMode = computed<'light' | 'dark'>(() => isDark.value ? 'dark' : 'light')
const bandColors = ['#4caf50', '#2196f3', '#ff9800', '#ff5722', '#f44336']

function scoreColor(score: number): string {
  if (score >= 80) return 'success'; if (score >= 60) return 'primary'; if (score >= 40) return 'warning'; return 'error'
}

const distDonutOptions = computed<ApexOptions>(() => ({
  chart: { type: 'donut', fontFamily: 'inherit', toolbar: { show: false } },
  labels: props.distribution.map(r => `${r.band} (${r.percentage}%)`),
  colors: bandColors, legend: { position: 'bottom', fontSize: '12px' }, theme: { mode: themeMode.value },
  plotOptions: { pie: { donut: { size: '65%', labels: { show: true, total: { show: true, label: t('pa_reports.exec_kpi.avg_score'), formatter: () => String(props.kpis.avg_score) } } } } },
  tooltip: { y: { formatter: (v: number) => `${v} employees` } }, dataLabels: { enabled: false },
}))
const distDonutSeries = computed(() => props.distribution.map(r => r.count))

const deptBarOptions = computed<ApexOptions>(() => ({
  chart: { type: 'bar', horizontal: true, toolbar: { show: false }, fontFamily: 'inherit' },
  plotOptions: { bar: { borderRadius: 4, dataLabels: { position: 'top' } } },
  xaxis: { categories: props.deptScores.map(r => r.department), min: 0, max: 100, labels: { formatter: (v: string) => `${v}` } },
  dataLabels: { enabled: true, formatter: (v: number) => `${v}`, offsetX: 5 },
  colors: props.deptScores.map(r => r.avg_score >= 80 ? '#4caf50' : r.avg_score >= 60 ? '#2196f3' : r.avg_score >= 40 ? '#ff9800' : '#f44336'),
  tooltip: { y: { formatter: (v: number) => `${v} avg score` } }, theme: { mode: themeMode.value }, grid: { borderColor: 'rgba(128,128,128,0.15)' },
}))
const deptBarSeries = computed(() => [{ name: 'Avg Score', data: props.deptScores.map(r => r.avg_score) }])

const trendOptions = computed<ApexOptions>(() => ({
  chart: { type: 'line', toolbar: { show: false }, fontFamily: 'inherit', zoom: { enabled: false } },
  stroke: { curve: 'smooth', width: 3 }, markers: { size: 5 },
  xaxis: { categories: props.cycleTrend.map(r => r.cycle), labels: { style: { fontSize: '11px' } } },
  yaxis: { min: 0, max: 100, title: { text: 'Avg Score %' } },
  tooltip: { shared: true, y: [{ formatter: (v: number) => `${v}%` }, { formatter: (v: number) => `${v} participants` }] },
  theme: { mode: themeMode.value }, grid: { borderColor: 'rgba(128,128,128,0.15)' }, colors: ['#7367f0', '#00cfe8'],
}))
const trendSeries = computed(() => [
  { name: 'Avg Score', data: props.cycleTrend.map(r => r.avg_score) },
  { name: 'Participants', data: props.cycleTrend.map(r => r.participants) },
])

const pipDonutOptions = computed<ApexOptions>(() => ({
  chart: { type: 'donut', fontFamily: 'inherit', toolbar: { show: false } },
  labels: props.pipByStatus.map(r => r.status), legend: { position: 'bottom', fontSize: '12px' }, theme: { mode: themeMode.value },
  tooltip: { y: { formatter: (v: number) => `${v} PIPs` } }, dataLabels: { enabled: true, formatter: (v: number) => `${Math.round(v)}%` },
  plotOptions: { pie: { donut: { size: '60%' } } },
}))
const pipDonutSeries = computed(() => props.pipByStatus.map(r => r.count))

const feedbackBarOptions = computed<ApexOptions>(() => ({
  chart: { type: 'bar', toolbar: { show: false }, fontFamily: 'inherit' },
  plotOptions: { bar: { borderRadius: 4, columnWidth: '55%', distributed: true } },
  xaxis: { categories: props.feedbackByType.map(r => r.type) }, yaxis: { title: { text: 'Count' } }, legend: { show: false },
  colors: ['#4caf50', '#2196f3', '#f44336', '#ff9800', '#9c27b0'], dataLabels: { enabled: true },
  theme: { mode: themeMode.value }, grid: { borderColor: 'rgba(128,128,128,0.15)' },
}))
const feedbackBarSeries = computed(() => [{ name: 'Feedback', data: props.feedbackByType.map(r => r.count) }])

const kpiStatusOptions = computed<ApexOptions>(() => ({
  chart: { type: 'pie', fontFamily: 'inherit', toolbar: { show: false } },
  labels: props.kpiByStatus.map(r => r.status), legend: { position: 'bottom', fontSize: '12px' }, theme: { mode: themeMode.value },
  tooltip: { y: { formatter: (v: number) => `${v} KPIs` } }, dataLabels: { enabled: true, formatter: (v: number) => `${Math.round(v)}%` },
}))
const kpiStatusSeries = computed(() => props.kpiByStatus.map(r => r.count))

const topEmpOptions = computed<ApexOptions>(() => ({
  chart: { type: 'bar', horizontal: true, toolbar: { show: false }, fontFamily: 'inherit' },
  plotOptions: { bar: { borderRadius: 4, dataLabels: { position: 'top' } } },
  xaxis: { categories: props.topEmployees.map(r => r.name), min: 0, max: 100 },
  colors: props.topEmployees.map(r => r.score >= 80 ? '#4caf50' : r.score >= 60 ? '#2196f3' : '#ff9800'),
  dataLabels: { enabled: true, formatter: (v: number) => `${v}%`, offsetX: 5 },
  tooltip: { y: { formatter: (v: number) => `${v} avg score` } }, theme: { mode: themeMode.value }, grid: { borderColor: 'rgba(128,128,128,0.15)' },
}))
const topEmpSeries = computed(() => [{ name: 'Score', data: props.topEmployees.map(r => r.score) }])
</script>

<template>
  <VRow>
    <VCol cols="12">
      <VCard class="exec-ribbon">
        <VCardText class="d-flex flex-wrap align-center justify-space-between gap-4 py-6">
          <div>
            <div class="text-overline text-white opacity-90">{{ t('pa_reports.module') }}</div>
            <h4 class="text-h4 text-white mb-1">{{ t('pa_reports.exec_title') }}</h4>
            <p class="mb-0 text-white opacity-90">{{ t('pa_reports.exec_subtitle') }}</p>
          </div>
          <div class="d-flex flex-column align-end gap-2">
            <VBtn variant="outlined" color="white" size="small" prepend-icon="tabler-table" href="/performance/reports">{{ t('pa_reports.reports_engine') }}</VBtn>
            <VBtn variant="outlined" color="white" size="small" prepend-icon="tabler-calendar-clock" href="/performance/reports/scheduled">{{ t('pa_reports.scheduled_reports') }}</VBtn>
          </div>
        </VCardText>
      </VCard>
    </VCol>

    <VCol cols="6" sm="4" md="3" lg=""><VCard variant="tonal" color="primary" class="h-100"><VCardText class="pa-4"><p class="text-caption mb-1 text-medium-emphasis">{{ t('pa_reports.exec_kpi.total_employees') }}</p><p class="text-h4 font-weight-bold">{{ kpis.total_employees.toLocaleString() }}</p><p class="text-caption mt-1">{{ t('pa_reports.exec_kpi.active_headcount') }}</p></VCardText></VCard></VCol>
    <VCol cols="6" sm="4" md="3" lg=""><VCard variant="tonal" :color="scoreColor(kpis.avg_score)" class="h-100"><VCardText class="pa-4"><p class="text-caption mb-1 text-medium-emphasis">{{ t('pa_reports.exec_kpi.avg_score') }}</p><p class="text-h4 font-weight-bold">{{ kpis.avg_score }}%</p><p class="text-caption mt-1">{{ t('pa_reports.exec_kpi.avg_score_sub') }}</p></VCardText></VCard></VCol>
    <VCol cols="6" sm="4" md="3" lg=""><VCard variant="tonal" color="success" class="h-100"><VCardText class="pa-4"><p class="text-caption mb-1 text-medium-emphasis">{{ t('pa_reports.exec_kpi.high_performers') }}</p><p class="text-h4 font-weight-bold">{{ kpis.high_performers }}</p><p class="text-caption mt-1">{{ t('pa_reports.exec_kpi.high_performers_sub') }}</p></VCardText></VCard></VCol>
    <VCol cols="6" sm="4" md="3" lg=""><VCard variant="tonal" color="error" class="h-100"><VCardText class="pa-4"><p class="text-caption mb-1 text-medium-emphasis">{{ t('pa_reports.exec_kpi.low_performers') }}</p><p class="text-h4 font-weight-bold">{{ kpis.low_performers }}</p><p class="text-caption mt-1">{{ t('pa_reports.exec_kpi.low_performers_sub') }}</p></VCardText></VCard></VCol>
    <VCol cols="6" sm="4" md="3" lg=""><VCard variant="tonal" color="warning" class="h-100"><VCardText class="pa-4"><p class="text-caption mb-1 text-medium-emphasis">{{ t('pa_reports.exec_kpi.active_pips') }}</p><p class="text-h4 font-weight-bold">{{ kpis.active_pips }}</p><p class="text-caption mt-1">{{ t('pa_reports.exec_kpi.active_pips_sub') }}</p></VCardText></VCard></VCol>
    <VCol cols="6" sm="4" md="3" lg=""><VCard variant="tonal" color="info" class="h-100"><VCardText class="pa-4"><p class="text-caption mb-1 text-medium-emphasis">{{ t('pa_reports.exec_kpi.high_scorer_rate') }}</p><p class="text-h4 font-weight-bold">{{ kpis.kpi_completion_pct }}%</p><p class="text-caption mt-1">{{ t('pa_reports.exec_kpi.high_scorer_sub') }}</p></VCardText></VCard></VCol>
    <VCol cols="6" sm="4" md="3" lg=""><VCard variant="tonal" color="deep-orange" class="h-100"><VCardText class="pa-4"><p class="text-caption mb-1 text-medium-emphasis">{{ t('pa_reports.exec_kpi.burnout_risks') }}</p><p class="text-h4 font-weight-bold">{{ kpis.burnout_risks }}</p><p class="text-caption mt-1">{{ t('pa_reports.exec_kpi.burnout_sub') }}</p></VCardText></VCard></VCol>
    <VCol cols="6" sm="4" md="3" lg=""><VCard variant="tonal" color="red-darken-2" class="h-100"><VCardText class="pa-4"><p class="text-caption mb-1 text-medium-emphasis">{{ t('pa_reports.exec_kpi.open_violations') }}</p><p class="text-h4 font-weight-bold">{{ kpis.open_violations }}</p><p class="text-caption mt-1">{{ t('pa_reports.exec_kpi.violations_sub') }}</p></VCardText></VCard></VCol>

    <VCol cols="12" md="4"><VCard class="h-100"><VCardTitle class="d-flex align-center gap-2 pa-4 pb-2"><VIcon color="primary" size="20">tabler-chart-donut</VIcon>{{ t('pa_reports.chart.score_distribution') }}</VCardTitle><VCardText><VueApexCharts v-if="distDonutSeries.length && distDonutSeries.some(v => v > 0)" type="donut" height="300" :options="distDonutOptions" :series="distDonutSeries" /><div v-else class="pa-8 text-center text-medium-emphasis"><VIcon size="40" class="mb-2 d-block mx-auto">tabler-chart-donut</VIcon>{{ t('pa_reports.empty_states.no_score_data') }}</div></VCardText></VCard></VCol>
    <VCol cols="12" md="8"><VCard class="h-100"><VCardTitle class="d-flex align-center gap-2 pa-4 pb-2"><VIcon color="indigo" size="20">tabler-chart-line</VIcon>{{ t('pa_reports.chart.cycle_trend') }}</VCardTitle><VCardText><VueApexCharts v-if="cycleTrend.length" type="line" height="300" :options="trendOptions" :series="trendSeries" /><div v-else class="pa-8 text-center text-medium-emphasis"><VIcon size="40" class="mb-2 d-block mx-auto">tabler-chart-line</VIcon>{{ t('pa_reports.empty_states.no_cycle_data') }}</div></VCardText></VCard></VCol>
    <VCol cols="12" md="8"><VCard class="h-100"><VCardTitle class="d-flex align-center gap-2 pa-4 pb-2"><VIcon color="teal" size="20">tabler-building</VIcon>{{ t('pa_reports.chart.dept_scores') }}</VCardTitle><VCardText><VueApexCharts v-if="deptScores.length" type="bar" :height="Math.max(250, deptScores.length * 38)" :options="deptBarOptions" :series="deptBarSeries" /><div v-else class="pa-8 text-center text-medium-emphasis">{{ t('pa_reports.empty_states.no_dept_data') }}</div></VCardText></VCard></VCol>
    <VCol cols="12" md="4"><VCard class="h-100"><VCardTitle class="d-flex align-center gap-2 pa-4 pb-2"><VIcon color="warning" size="20">tabler-clipboard-list</VIcon>{{ t('pa_reports.chart.pip_status') }}</VCardTitle><VCardText><VueApexCharts v-if="pipDonutSeries.length && pipDonutSeries.some(v => v > 0)" type="donut" height="260" :options="pipDonutOptions" :series="pipDonutSeries" /><div v-else class="pa-8 text-center text-medium-emphasis"><VIcon size="40" class="mb-2 d-block mx-auto">tabler-clipboard-check</VIcon>{{ t('pa_reports.empty_states.no_pip_data') }}</div></VCardText></VCard></VCol>
    <VCol cols="12" md="4"><VCard class="h-100"><VCardTitle class="d-flex align-center gap-2 pa-4 pb-2"><VIcon color="blue-grey" size="20">tabler-message-dots</VIcon>{{ t('pa_reports.chart.feedback_type') }}</VCardTitle><VCardText><VueApexCharts v-if="feedbackBarSeries[0].data.length" type="bar" height="260" :options="feedbackBarOptions" :series="feedbackBarSeries" /><div v-else class="pa-8 text-center text-medium-emphasis">{{ t('pa_reports.empty_states.no_feedback_data') }}</div></VCardText></VCard></VCol>
    <VCol cols="12" md="4"><VCard class="h-100"><VCardTitle class="d-flex align-center gap-2 pa-4 pb-2"><VIcon color="cyan" size="20">tabler-rosette-discount-check</VIcon>{{ t('pa_reports.chart.kpi_status') }}</VCardTitle><VCardText><VueApexCharts v-if="kpiStatusSeries.length && kpiStatusSeries.some(v => v > 0)" type="pie" height="260" :options="kpiStatusOptions" :series="kpiStatusSeries" /><div v-else class="pa-8 text-center text-medium-emphasis">{{ t('pa_reports.empty_states.no_kpi_data') }}</div></VCardText></VCard></VCol>
    <VCol cols="12" md="4"><VCard class="h-100"><VCardTitle class="d-flex align-center gap-2 pa-4 pb-2"><VIcon color="success" size="20">tabler-trophy</VIcon>{{ t('pa_reports.chart.top_performers') }}</VCardTitle><VCardText><VueApexCharts v-if="topEmployees.length" type="bar" :height="Math.max(240, topEmployees.length * 32)" :options="topEmpOptions" :series="topEmpSeries" /><div v-else class="pa-8 text-center text-medium-emphasis">{{ t('pa_reports.empty_states.no_employee_data') }}</div></VCardText></VCard></VCol>

    <VCol cols="12">
      <VCard><VCardTitle class="d-flex align-center gap-2 pa-4 pb-0"><VIcon color="primary" size="20">tabler-list</VIcon>{{ t('pa_reports.chart.score_band_summary') }}</VCardTitle>
        <VCardText>
          <VDataTable :headers="[{ title: t('pa_reports.table.band'), key: 'band' },{ title: t('pa_reports.table.count'), key: 'count' },{ title: t('pa_reports.table.percentage'), key: 'percentage' },{ title: 'Visual', key: 'bar' }]" :items="distribution" :items-per-page="-1" hide-default-footer density="comfortable">
            <template #item.percentage="{ item }"><strong>{{ item.percentage }}%</strong></template>
            <template #item.bar="{ item }"><VProgressLinear :model-value="item.percentage" :color="item.band.includes('Outstanding') ? 'success' : item.band.includes('Good') ? 'primary' : item.band.includes('Meets') ? 'warning' : item.band.includes('Below') ? 'deep-orange' : 'error'" rounded height="8" style="max-width: 200px" /></template>
          </VDataTable>
        </VCardText>
      </VCard>
    </VCol>
  </VRow>
</template>

<style scoped lang="scss">
.exec-ribbon { border: 0; overflow: hidden; background: linear-gradient(135deg, #0d47a1 0%, #7367f0 60%, #9c27b0 100%); }
</style>
