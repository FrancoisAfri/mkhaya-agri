<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import VueApexCharts from 'vue3-apexcharts'
import type { ApexOptions } from 'apexcharts'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

/**
 * The reporting dashboard.
 *
 * Every figure here is a column on the client's own document — projects, stage, status, progress,
 * the three fee totals and the monthly cash flow. Nothing is invented, which was explicit in the
 * brief and is why there is no health score, no RAG rollup and no trend line: none of those exist
 * on the report this digitises.
 */
const props = defineProps<{
  period: { id: string; label: string; name: string; status: string } | null
  summary: Record<string, any> | null
  /** Across periods, unfiltered: the tiles say where we are, this says which way it is going. */
  trend: Array<{ label: string; estimated: string; invoiced: string; outstanding: string }>
  filters: { period: string | null; project: string | null; manager: number | null; stage: string | null; status: string | null }
  periods: Array<{ id: string; label: string }>
  projects: Array<{ id: string; label: string }>
  stages: string[]
  statuses: string[]
  people: Array<{ id: number; label: string }>
}>()

const period = ref(props.filters.period ?? props.period?.id ?? null)
const project = ref(props.filters.project)
const manager = ref(props.filters.manager)
const stage = ref(props.filters.stage)
const status = ref(props.filters.status)

let debounce: ReturnType<typeof setTimeout>
watch([period, project, manager, stage, status], () => {
  clearTimeout(debounce)
  debounce = setTimeout(() => {
    router.get(route('project-management.reporting.dashboard'), {
      period: period.value || undefined,
      project: project.value || undefined,
      manager: manager.value || undefined,
      stage: stage.value || undefined,
      status: status.value || undefined,
    }, { preserveState: true, replace: true })
  }, 300)
})

function money(amount: string | null | undefined) {
  if (amount === null || amount === undefined || amount === '') return '—'
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'ZAR' }).format(Number(amount))
  } catch {
    return String(amount)
  }
}

function compact(amount: string | null | undefined) {
  if (amount === null || amount === undefined || amount === '') return '—'
  const value = Number(amount)
  if (Number.isNaN(value)) return String(amount)
  try {
    return new Intl.NumberFormat(undefined, {
      style: 'currency', currency: 'ZAR', notation: 'compact', maximumFractionDigits: 1,
    }).format(value)
  } catch {
    return money(amount)
  }
}

// ── The picture ──────────────────────────────────────────────────────────────

const num = (v: unknown) => Number(v) || 0

/** Shared so a legend, a tooltip and an axis in three charts cannot format money three ways. */
const currencyAxis = {
  labels: { formatter: (v: number) => compact(String(v)) },
}

const trendOptions = computed<ApexOptions>(() => ({
  chart: { type: 'line', toolbar: { show: false }, zoom: { enabled: false } },
  stroke: { width: 2, curve: 'straight' },
  colors: ['#26a69a', '#42a5f5', '#ef5350'],
  xaxis: { categories: props.trend.map(t => t.label) },
  yaxis: currencyAxis,
  legend: { position: 'top', horizontalAlign: 'left' },
  // Points, because a month nobody filled in is a real zero and a bare line hides that it is a
  // data point at all.
  markers: { size: 3 },
  dataLabels: { enabled: false },
  tooltip: { y: { formatter: (v: number) => money(String(v)) } },
}))

const trendSeries = computed(() => [
  { name: t('project_management.monthly_reporting.summary_estimated'), data: props.trend.map(x => num(x.estimated)) },
  { name: t('project_management.monthly_reporting.summary_invoiced'), data: props.trend.map(x => num(x.invoiced)) },
  { name: t('project_management.monthly_reporting.summary_outstanding'), data: props.trend.map(x => num(x.outstanding)) },
])

/** Stage and status as donuts: both are "how the portfolio divides", which is what a donut says. */
function mixOptions(labels: string[], colours: string[]): ApexOptions {
  return {
    chart: { type: 'donut' },
    labels,
    colors: colours,
    legend: { position: 'bottom' },
    dataLabels: { enabled: true, formatter: (v: number) => `${Math.round(v)}%` },
  }
}

const STAGE_COLOURS = ['#5e35b1', '#3949ab', '#1e88e5', '#00897b', '#43a047', '#fb8c00', '#8e24aa']
const STATUS_COLOURS = ['#43a047', '#fb8c00', '#e53935', '#1e88e5', '#8e24aa', '#00897b']

const stageLabels = computed(() => Object.keys(props.summary?.by_stage ?? {}))
const stageSeries = computed(() => Object.values(props.summary?.by_stage ?? {}).map(n => Number(n)))

const statusLabels = computed(() => Object.keys(props.summary?.by_status ?? {}))
const statusSeries = computed(() => Object.values(props.summary?.by_status ?? {}).map(n => Number(n)))

/** Cash expected, month by month. A bar per month reads as "how much lands when". */
const cashflowOptions = computed<ApexOptions>(() => ({
  chart: { type: 'bar', toolbar: { show: false } },
  plotOptions: { bar: { borderRadius: 4, columnWidth: '55%' } },
  colors: ['#26a69a'],
  xaxis: { categories: (props.summary?.cashflow ?? []).map((m: any) => m.label) },
  yaxis: currencyAxis,
  dataLabels: { enabled: false },
  tooltip: { y: { formatter: (v: number) => money(String(v)) } },
}))

const cashflowSeries = computed(() => [{
  name: t('project_management.monthly_reporting.cashflow_heading'),
  data: (props.summary?.cashflow ?? []).map((m: any) => num(m.amount)),
}])

/**
 * How far along the work is, as a spread rather than an average.
 *
 * 55% average describes a portfolio where everything is half done and one where half is finished
 * and half has not started. These are opposite situations and the bands say which one this is.
 */
const BANDS = ['not_started', 'early', 'halfway', 'most', 'complete'] as const

const bandOptions = computed<ApexOptions>(() => ({
  chart: { type: 'bar', toolbar: { show: false } },
  plotOptions: { bar: { horizontal: true, borderRadius: 4, distributed: true } },
  colors: ['#9e9e9e', '#ef5350', '#fb8c00', '#42a5f5', '#43a047'],
  xaxis: {
    categories: BANDS.map(b => t(`project_management.monthly_reporting.band_${b}`)),
    // Whole projects, so a half-tick on the axis would be a project that does not exist.
    labels: { formatter: (v: string) => String(Math.round(Number(v))) },
  },
  legend: { show: false },
  dataLabels: { enabled: true },
}))

const bandSeries = computed(() => [{
  name: t('project_management.monthly_reporting.summary_projects'),
  data: BANDS.map(b => Number(props.summary?.progress_bands?.[b] ?? 0)),
}])

const tiles = computed(() => props.summary ? [
  { key: 'summary_projects', value: String(props.summary.projects), exact: '', icon: 'tabler-folders', colour: 'primary' },
  { key: 'summary_progress', value: props.summary.average_progress === null ? '—' : props.summary.average_progress + '%', exact: '', icon: 'tabler-progress', colour: 'info' },
  { key: 'summary_estimated', value: compact(props.summary.estimated_fee), exact: money(props.summary.estimated_fee), icon: 'tabler-file-percent', colour: 'teal' },
  { key: 'summary_invoiced', value: compact(props.summary.invoiced), exact: money(props.summary.invoiced), icon: 'tabler-receipt', colour: 'success' },
  { key: 'summary_outstanding', value: compact(props.summary.outstanding), exact: money(props.summary.outstanding), icon: 'tabler-clock-dollar', colour: 'warning' },
] : [])

</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('project_management.monthly_reporting.dashboard_title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">
        {{ props.period ? props.period.name : t('project_management.monthly_reporting.dashboard_sub') }}
      </p>
    </div>

    <VCard border class="mb-4">
      <VCardText class="d-flex flex-wrap gap-3">
        <VAutocomplete
          v-model="period" :items="props.periods.map(p => ({ title: p.label, value: p.id }))"
          :label="t('project_management.monthly_reporting.filter_month')"
          density="compact" variant="outlined" hide-details clearable style="max-width: 220px"
        />
        <VAutocomplete
          v-model="project" :items="props.projects.map(p => ({ title: p.label, value: p.id }))"
          :label="t('project_management.monthly_reporting.filter_project')"
          density="compact" variant="outlined" hide-details clearable style="max-width: 300px"
        />
        <VAutocomplete
          v-model="manager" :items="props.people.map(p => ({ title: p.label, value: p.id }))"
          :label="t('project_management.monthly_reporting.filter_manager')"
          density="compact" variant="outlined" hide-details clearable style="max-width: 240px"
        />
        <VSelect
          v-model="stage" :items="props.stages"
          :label="t('project_management.monthly_reporting.filter_stage')"
          density="compact" variant="outlined" hide-details clearable style="max-width: 260px"
        />
        <VSelect
          v-model="status" :items="props.statuses"
          :label="t('project_management.monthly_reporting.filter_status')"
          density="compact" variant="outlined" hide-details clearable style="max-width: 200px"
        />
      </VCardText>
    </VCard>

    <template v-if="props.summary">
      <div class="summary-strip mb-4">
        <VCard v-for="tile in tiles" :key="tile.key" border>
          <VCardText class="d-flex align-center gap-3">
            <VAvatar :color="tile.colour" variant="tonal" rounded size="42">
              <VIcon :icon="tile.icon" />
            </VAvatar>
            <div style="min-inline-size: 0">
              <div class="text-h6 text-no-wrap tile-value" :title="tile.exact">{{ tile.value }}</div>
              <div class="text-caption text-medium-emphasis text-truncate">
                {{ t('project_management.monthly_reporting.' + tile.key) }}
              </div>
            </div>
          </VCardText>
        </VCard>
      </div>

      <!--
        Which way it is going, before how it divides.

        The tiles above say where the portfolio stands this month. A month on its own says nothing
        about direction, and direction is what gets acted on: outstanding of two million after four
        months of one and a half is a different situation from outstanding of two million that has
        been flat all year.
      -->
      <VCard v-if="props.trend.length > 1" border class="mb-4">
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('project_management.monthly_reporting.trend_title') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.monthly_reporting.trend_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VueApexCharts type="line" :height="300" :options="trendOptions" :series="trendSeries" />
        </VCardText>
      </VCard>

      <VRow>
        <VCol cols="12" md="4">
          <VCard border class="h-100">
            <VCardItem>
              <VCardTitle class="text-body-1">{{ t('project_management.monthly_reporting.by_stage') }}</VCardTitle>
            </VCardItem>
            <VCardText>
              <VueApexCharts
                v-if="stageSeries.length" type="donut" :height="280"
                :options="mixOptions(stageLabels, STAGE_COLOURS)" :series="stageSeries"
              />
              <div v-else class="text-body-2 text-medium-emphasis py-8 text-center">
                {{ t('project_management.monthly_reporting.no_reports') }}
              </div>
            </VCardText>
          </VCard>
        </VCol>

        <VCol cols="12" md="4">
          <VCard border class="h-100">
            <VCardItem>
              <VCardTitle class="text-body-1">{{ t('project_management.monthly_reporting.by_status') }}</VCardTitle>
            </VCardItem>
            <VCardText>
              <VueApexCharts
                v-if="statusSeries.length" type="donut" :height="280"
                :options="mixOptions(statusLabels, STATUS_COLOURS)" :series="statusSeries"
              />
              <div v-else class="text-body-2 text-medium-emphasis py-8 text-center">
                {{ t('project_management.monthly_reporting.no_reports') }}
              </div>
            </VCardText>
          </VCard>
        </VCol>

        <VCol cols="12" md="4">
          <VCard border class="h-100">
            <VCardItem>
              <VCardTitle class="text-body-1">{{ t('project_management.monthly_reporting.by_progress') }}</VCardTitle>
              <VCardSubtitle>{{ t('project_management.monthly_reporting.by_progress_sub') }}</VCardSubtitle>
            </VCardItem>
            <VCardText>
              <VueApexCharts type="bar" :height="260" :options="bandOptions" :series="bandSeries" />
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <!--
        Where the money is, not just how much.
        A total says how much is owed; it does not say that three quarters of it is one project —
        which is the difference between a collections problem and a client problem.
      -->
      <VCard v-if="(props.summary.top_outstanding ?? []).length" border class="mt-4">
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('project_management.monthly_reporting.top_outstanding') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.monthly_reporting.top_outstanding_sub') }}</VCardSubtitle>
        </VCardItem>
        <VDivider />
        <VTable density="compact">
          <tbody>
            <tr v-for="row in props.summary.top_outstanding" :key="row.code ?? row.project">
              <td>
                <div>{{ row.project }}</div>
                <div class="text-caption text-medium-emphasis">{{ row.code }}</div>
              </td>
              <td style="inline-size: 45%">
                <!-- Against the largest, so the shape of the concentration reads at a glance. -->
                <VProgressLinear
                  :model-value="(Number(row.amount) / Number(props.summary.top_outstanding[0].amount)) * 100"
                  color="warning" height="8" rounded
                />
              </td>
              <td class="text-end text-no-wrap tile-value">{{ money(row.amount) }}</td>
            </tr>
          </tbody>
        </VTable>
      </VCard>

      <VCard border class="mt-4">
        <VCardItem>
          <VCardTitle class="text-body-1">
            {{ t('project_management.monthly_reporting.cashflow_by_month') }}
          </VCardTitle>
          <VCardSubtitle>{{ t('project_management.monthly_reporting.cashflow_heading') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VueApexCharts type="bar" :height="280" :options="cashflowOptions" :series="cashflowSeries" />
        </VCardText>
      </VCard>
    </template>

    <VCard v-else border>
      <VCardText class="text-center text-medium-emphasis py-8">
        {{ t('project_management.monthly_reporting.no_period') }}
      </VCardText>
    </VCard>
  </div>
</template>

<style scoped>
.summary-strip {
  display: grid;
  gap: 1rem;
  grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr));
}

.tile-value { font-variant-numeric: tabular-nums; }

</style>
