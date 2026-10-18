<script setup lang="ts">
import { computed } from 'vue'
import { useTheme } from 'vuetify'
import { useI18n } from 'vue-i18n'
import VueApexCharts from 'vue3-apexcharts'
import type { ApexOptions } from 'apexcharts'

/**
 * Every Project Management chart, in one place.
 *
 * Shared by the dashboard and the Analytics page so the two cannot drift into showing the same
 * figure differently — which is how a landing page and a report end up disagreeing and nobody
 * trusts either.
 */

type Labelled = { label: string; count: number }

const props = defineProps<{
  charts: {
    projects_by_stage: Labelled[]
    projects_by_status: Labelled[]
    task_status_mix: Labelled[]
    evidence_trend: { month: string; count: number }[]
    evidence_by_domain: Labelled[]
    progress_by_project: { code: string; name: string; progress: number }[]
    budget_vs_actual: { code: string; budget: number; actual: number }[]
    hours_trend: { month: string; approved: number; pending: number }[]
    schedule_health: { on_track: number; at_risk: number; overdue: number; unscheduled: number }
    overdue_by_project: { code: string; name: string; overdue: number }[]
    cost_by_category: { label: string; amount: number }[]
    cost_position: { budget: number; labour: number; other: number; committed: number; remaining: number }
    cost_trend: { month: string; labour: number; other: number }[]
    allocation_by_person: { name: string; allocation: number; tasks: number }[]
    workload_health: { over: number; full: number; light: number; idle: number }
    role_mix: Labelled[]
    approval_backlog: { today: number; this_week: number; over_a_week: number; over_a_month: number }
    earned_value: { bac: number | null; pv: number | null; ev: number | null; ac: number | null; spi: number | null; cpi: number | null }
    health_by_project: Array<{ code: string; name: string; score: number; spi: number | null; cpi: number | null; worst: string }>
    cash_flow: Array<{ month: string; out: number; committed: number; in: number }>
    upcoming_milestones: Array<{ project: string; name: string; due: string | null; days: number; progress: number; overdue: boolean }>
    high_risks: Array<{ project: string | null; code: string; title: string; score: number; has_mitigation: boolean }>
    client_satisfaction: { score: number | null; responses: number }
  }
  /** Which charts to draw. Omit for all of them. */
  only?: string[]
  /**
   * Column widths per chart, overriding the defaults below.
   *
   * The defaults suit the Analytics page, which draws every chart and so tiles by construction. A
   * caller showing a subset inherits whatever widths those particular charts happen to carry, and
   * five charts adding up to 4+4+8+6+6 leaves two rows with a third of the width empty. This lets
   * the caller lay its own selection out so the rows fill.
   */
  span?: Record<string, { cols?: number | string; sm?: number | string; md?: number | string; lg?: number | string }>
  /**
   * Charts that keep their card when they have no data, showing an empty state instead of
   * disappearing.
   *
   * A chart that vanishes is fine on the Analytics page, where twenty of them close the gap. On a
   * fixed five-card layout it leaves its row-mate stranded at half width — which is a hole in the
   * page that looks like a rendering fault rather than an absence of data.
   */
  keepEmpty?: string[]
  height?: number
}>()

const { t } = useI18n()
const theme = useTheme()

const show = (key: string) => !props.only || props.only.includes(key)
const h = computed(() => props.height ?? 280)

/** Does this chart hold its place in the grid when it has nothing to draw? */
const keep = (key: string) => props.keepEmpty?.includes(key) ?? false

/** A chart's grid width: its own default, unless the caller laid this selection out itself. */
const col = (key: string, fallback: Record<string, number | string> = {}) => ({
  cols: 12,
  ...fallback,
  ...(props.span?.[key] ?? {}),
})

/** Chart text has to follow the theme, or a dark-mode dashboard renders black labels on black. */
const base = computed<ApexOptions>(() => {
  const c = theme.current.value.colors
  const onSurface = theme.current.value.dark ? '#CFD3EC' : '#4B465C'

  return {
    chart: { toolbar: { show: false }, fontFamily: 'inherit', foreColor: onSurface },
    grid: { borderColor: theme.current.value.dark ? '#3A3E5B' : '#E6E6E8', strokeDashArray: 4 },
    dataLabels: { enabled: false },
    legend: { labels: { colors: onSurface } },
    tooltip: { theme: theme.current.value.dark ? 'dark' : 'light' },
    colors: [c.primary, c.info, c.success, c.warning, c.error, c['primary-darken-1']],
  }
})

const label = (section: string, key: string) => {
  const full = `project_management.${section}.${key}`
  const translated = t(full)

  // vue-i18n hands the key back when there is no translation. Showing the raw value beats showing
  // "project_management.task_status.blocked" on an axis.
  return translated === full ? key.replace(/_/g, ' ') : translated
}

// ── Series ───────────────────────────────────────────────────────────────────

const stageOptions = computed<ApexOptions>(() => ({
  ...base.value,
  labels: props.charts.projects_by_stage.map(r => label('stage', r.label)),
  legend: { ...base.value.legend, position: 'bottom' },
}))
const stageSeries = computed(() => props.charts.projects_by_stage.map(r => r.count))

const taskMixOptions = computed<ApexOptions>(() => ({
  ...base.value,
  labels: props.charts.task_status_mix.map(r => label('task_status', r.label)),
  legend: { ...base.value.legend, position: 'bottom' },
}))
const taskMixSeries = computed(() => props.charts.task_status_mix.map(r => r.count))

const evidenceOptions = computed<ApexOptions>(() => ({
  ...base.value,
  xaxis: { categories: props.charts.evidence_trend.map(r => r.month) },
  stroke: { curve: 'smooth', width: 2 },
  fill: { type: 'gradient', gradient: { opacityFrom: 0.4, opacityTo: 0.05 } },
}))
const evidenceSeries = computed(() => [
  { name: t('project_management.insights.evidence_recorded'), data: props.charts.evidence_trend.map(r => r.count) },
])

const domainOptions = computed<ApexOptions>(() => ({
  ...base.value,
  labels: props.charts.evidence_by_domain.map(r => label('evidence_domain', r.label)),
  legend: { ...base.value.legend, position: 'bottom' },
}))
const domainSeries = computed(() => props.charts.evidence_by_domain.map(r => r.count))

const progressOptions = computed<ApexOptions>(() => ({
  ...base.value,
  chart: { ...base.value.chart, type: 'bar' },
  plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '60%' } },
  xaxis: { categories: props.charts.progress_by_project.map(r => r.code), max: 100 },
  dataLabels: { enabled: true, formatter: (v: number) => `${v}%` },
}))
const progressSeries = computed(() => [
  { name: t('project_management.tasks.col_progress'), data: props.charts.progress_by_project.map(r => r.progress) },
])

const budgetOptions = computed<ApexOptions>(() => ({
  ...base.value,
  chart: { ...base.value.chart, type: 'bar' },
  plotOptions: { bar: { borderRadius: 4, columnWidth: '55%' } },
  xaxis: { categories: props.charts.budget_vs_actual.map(r => r.code) },
}))
const budgetSeries = computed(() => [
  { name: t('project_management.delivery.budget'), data: props.charts.budget_vs_actual.map(r => r.budget) },
  { name: t('project_management.delivery.actual'), data: props.charts.budget_vs_actual.map(r => r.actual) },
])

const hoursOptions = computed<ApexOptions>(() => ({
  ...base.value,
  chart: { ...base.value.chart, type: 'bar', stacked: true },
  plotOptions: { bar: { borderRadius: 4, columnWidth: '55%' } },
  xaxis: { categories: props.charts.hours_trend.map(r => r.month) },
}))
const hoursSeries = computed(() => [
  { name: t('project_management.delivery.approved_hours'), data: props.charts.hours_trend.map(r => r.approved) },
  { name: t('project_management.delivery.pending_hours'), data: props.charts.hours_trend.map(r => r.pending) },
])

const healthOptions = computed<ApexOptions>(() => ({
  ...base.value,
  labels: [
    t('project_management.insights.on_track'),
    t('project_management.insights.at_risk'),
    t('project_management.insights.overdue'),
    t('project_management.insights.unscheduled'),
  ],
  colors: [
    theme.current.value.colors.success,
    theme.current.value.colors.warning,
    theme.current.value.colors.error,
    theme.current.value.colors['on-surface-variant'] ?? '#9E9E9E',
  ],
  legend: { ...base.value.legend, position: 'bottom' },
}))
const healthSeries = computed(() => [
  props.charts.schedule_health.on_track,
  props.charts.schedule_health.at_risk,
  props.charts.schedule_health.overdue,
  props.charts.schedule_health.unscheduled,
])

const overdueOptions = computed<ApexOptions>(() => ({
  ...base.value,
  chart: { ...base.value.chart, type: 'bar' },
  plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '60%' } },
  xaxis: { categories: props.charts.overdue_by_project.map(r => r.code) },
  colors: [theme.current.value.colors.error],
}))
const overdueSeries = computed(() => [
  { name: t('project_management.insights.overdue'), data: props.charts.overdue_by_project.map(r => r.overdue) },
])

/** A donut of nothing renders as a grey ring, which reads as data rather than the absence of it. */
const hasAny = (arr: unknown[]) => Array.isArray(arr) && arr.some(v => (typeof v === 'number' ? v > 0 : true))

// ── Finance ──────────────────────────────────────────────────────────────────

const money = (v: number) => new Intl.NumberFormat(undefined, { notation: 'compact' }).format(v)

const costCategoryOptions = computed<ApexOptions>(() => ({
  ...base.value,
  labels: props.charts.cost_by_category.map(r => label('delivery', `category_${r.label}`)),
  legend: { ...base.value.legend, position: 'bottom' },
}))
const costCategorySeries = computed(() => props.charts.cost_by_category.map(r => r.amount))

/**
 * The portfolio's money as one bar.
 *
 * Labour and materials are separate series rather than one "spent" figure, because they behave
 * differently — labour is a run rate you can slow down, materials are committed the moment the
 * order goes out — and which of the two is the problem is the point of looking.
 */
const costPositionOptions = computed<ApexOptions>(() => ({
  ...base.value,
  chart: { ...base.value.chart, type: 'bar', stacked: true },
  plotOptions: { bar: { horizontal: true, borderRadius: 4 } },
  xaxis: { categories: [t('project_management.insights.portfolio')], labels: { formatter: money } },
  legend: { ...base.value.legend, position: 'bottom' },
}))
const costPositionSeries = computed(() => {
  const p = props.charts.cost_position

  return [
    { name: t('project_management.delivery.of_which_labour', { amount: '' }).trim() || t('project_management.insights.labour'), data: [p.labour] },
    { name: t('project_management.insights.other_cost'), data: [p.other] },
    { name: t('project_management.delivery.committed'), data: [p.committed] },
    { name: t('project_management.delivery.remaining'), data: [Math.max(0, p.remaining)] },
  ]
})

const costTrendOptions = computed<ApexOptions>(() => ({
  ...base.value,
  chart: { ...base.value.chart, type: 'area', stacked: true },
  stroke: { curve: 'smooth', width: 2 },
  fill: { type: 'gradient', gradient: { opacityFrom: 0.4, opacityTo: 0.05 } },
  xaxis: { categories: props.charts.cost_trend.map(r => r.month) },
  yaxis: { labels: { formatter: money } },
}))
const costTrendSeries = computed(() => [
  { name: t('project_management.insights.labour'), data: props.charts.cost_trend.map(r => r.labour) },
  { name: t('project_management.insights.other_cost'), data: props.charts.cost_trend.map(r => r.other) },
])

// ── Resources ────────────────────────────────────────────────────────────────

/**
 * Allocation per person, with 100% marked.
 *
 * The line is the whole chart: a bar at 140% means nothing without something to read it against,
 * and nobody plans to over-commit — they do it one task at a time.
 */
const allocationOptions = computed<ApexOptions>(() => ({
  ...base.value,
  chart: { ...base.value.chart, type: 'bar' },
  plotOptions: { bar: { horizontal: true, borderRadius: 4, distributed: true } },
  legend: { show: false },
  xaxis: {
    categories: props.charts.allocation_by_person.map(r => r.name),
    labels: { formatter: (v: string) => `${v}%` },
  },
  annotations: {
    xaxis: [{
      x: 100,
      borderColor: theme.current.value.colors.error,
      label: { text: t('project_management.insights.capacity'), style: { background: theme.current.value.colors.error, color: '#fff' } },
    }],
  },
  colors: props.charts.allocation_by_person.map(r =>
    r.allocation > 100 ? theme.current.value.colors.error : theme.current.value.colors.primary),
}))
const allocationSeries = computed(() => [
  { name: t('project_management.insights.allocation'), data: props.charts.allocation_by_person.map(r => r.allocation) },
])

const workloadOptions = computed<ApexOptions>(() => ({
  ...base.value,
  labels: [
    t('project_management.insights.workload_over'),
    t('project_management.insights.workload_full'),
    t('project_management.insights.workload_light'),
    t('project_management.insights.workload_idle'),
  ],
  colors: [
    theme.current.value.colors.error, theme.current.value.colors.success,
    theme.current.value.colors.info, theme.current.value.colors.warning,
  ],
  legend: { ...base.value.legend, position: 'bottom' },
}))
const workloadSeries = computed(() => {
  const w = props.charts.workload_health

  return [w.over, w.full, w.light, w.idle]
})

const roleMixOptions = computed<ApexOptions>(() => ({
  ...base.value,
  labels: props.charts.role_mix.map(r => label('roles', r.label)),
  legend: { ...base.value.legend, position: 'bottom' },
}))
const roleMixSeries = computed(() => props.charts.role_mix.map(r => r.count))

// ── Earned value ─────────────────────────────────────────────────────────────

/**
 * Planned, earned and actual as three bars.
 *
 * The whole point is seeing them together: "60% of the budget is gone" and "60% of the work is
 * done" are different statements, and a project reports the first far more readily than the second.
 */
const evmOptions = computed<ApexOptions>(() => ({
  ...base.value,
  chart: { ...base.value.chart, type: 'bar' },
  plotOptions: { bar: { borderRadius: 4, distributed: true, columnWidth: '45%' } },
  legend: { show: false },
  xaxis: {
    categories: [
      t('project_management.insights.evm_pv'),
      t('project_management.insights.evm_ev'),
      t('project_management.insights.evm_ac'),
    ],
  },
  yaxis: { labels: { formatter: money } },
  colors: [
    theme.current.value.colors.info,
    theme.current.value.colors.success,
    theme.current.value.colors.warning,
  ],
}))
const evmSeries = computed(() => {
  const e = props.charts.earned_value

  return [{ name: t('project_management.insights.earned_value'), data: [e.pv ?? 0, e.ev ?? 0, e.ac ?? 0] }]
})

/** Below 1 is bad on both indices, so the colour is the reading. */
const indexColour = (v: number | null) => (v === null ? 'grey' : v >= 1 ? 'success' : v >= 0.9 ? 'warning' : 'error')

// ── Cash flow ────────────────────────────────────────────────────────────────

const cashFlowOptions = computed<ApexOptions>(() => ({
  ...base.value,
  chart: { ...base.value.chart, type: 'bar', stacked: true },
  plotOptions: { bar: { borderRadius: 4, columnWidth: '55%' } },
  xaxis: { categories: props.charts.cash_flow.map(r => r.month) },
  yaxis: { labels: { formatter: money } },
  legend: { ...base.value.legend, position: 'bottom' },
  colors: [theme.current.value.colors.error, theme.current.value.colors.warning],
}))
const cashFlowSeries = computed(() => [
  { name: t('project_management.insights.cash_out'), data: props.charts.cash_flow.map(r => r.out) },
  // Committed is drawn separately: it has not left yet, and stacking it as if it had would
  // overstate what has actually gone.
  { name: t('project_management.delivery.committed'), data: props.charts.cash_flow.map(r => r.committed) },
])

// ── Approvals ────────────────────────────────────────────────────────────────

/**
 * The queue by age, bucketed rather than averaged.
 *
 * One request stuck for three weeks is the thing worth seeing, and a mean waiting time buries it
 * under forty that cleared the same day.
 */
const approvalOptions = computed<ApexOptions>(() => ({
  ...base.value,
  chart: { ...base.value.chart, type: 'bar' },
  plotOptions: { bar: { borderRadius: 4, distributed: true, columnWidth: '45%' } },
  legend: { show: false },
  xaxis: {
    categories: [
      t('project_management.insights.age_today'),
      t('project_management.insights.age_week'),
      t('project_management.insights.age_over_week'),
      t('project_management.insights.age_over_month'),
    ],
  },
  colors: [
    theme.current.value.colors.success, theme.current.value.colors.info,
    theme.current.value.colors.warning, theme.current.value.colors.error,
  ],
}))
const approvalSeries = computed(() => {
  const a = props.charts.approval_backlog

  return [{ name: t('project_management.approvals.title'), data: [a.today, a.this_week, a.over_a_week, a.over_a_month] }]
})
</script>

<template>
  <VRow>
    <!-- Schedule health -->
    <VCol v-if="show('schedule_health')" v-bind="col('schedule_health', { md: 4 })">
      <VCard border class="h-100">
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('project_management.insights.schedule_health') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.insights.schedule_health_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VueApexCharts
            v-if="healthSeries.some(v => v > 0)"
            type="donut" :height="h" :options="healthOptions" :series="healthSeries"
          />
          <p v-else class="text-body-2 text-medium-emphasis text-center py-8">
            {{ t('project_management.insights.nothing_to_chart') }}
          </p>
        </VCardText>
      </VCard>
    </VCol>

    <!-- Task status mix -->
    <VCol v-if="show('task_status_mix')" v-bind="col('task_status_mix', { md: 4 })">
      <VCard border class="h-100">
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('project_management.insights.task_mix') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.insights.task_mix_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VueApexCharts
            v-if="taskMixSeries.some(v => v > 0)"
            type="donut" :height="h" :options="taskMixOptions" :series="taskMixSeries"
          />
          <p v-else class="text-body-2 text-medium-emphasis text-center py-8">
            {{ t('project_management.insights.nothing_to_chart') }}
          </p>
        </VCardText>
      </VCard>
    </VCol>

    <!-- Projects by stage -->
    <VCol v-if="show('projects_by_stage')" v-bind="col('projects_by_stage', { md: 4 })">
      <VCard border class="h-100">
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('project_management.insights.by_stage') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.insights.by_stage_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VueApexCharts
            v-if="stageSeries.some(v => v > 0)"
            type="donut" :height="h" :options="stageOptions" :series="stageSeries"
          />
          <p v-else class="text-body-2 text-medium-emphasis text-center py-8">
            {{ t('project_management.insights.nothing_to_chart') }}
          </p>
        </VCardText>
      </VCard>
    </VCol>

    <!-- Evidence trend -->
    <VCol v-if="show('evidence_trend')" v-bind="col('evidence_trend', { md: 8 })">
      <VCard border class="h-100">
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('project_management.insights.evidence_trend') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.insights.evidence_trend_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VueApexCharts type="area" :height="h" :options="evidenceOptions" :series="evidenceSeries" />
        </VCardText>
      </VCard>
    </VCol>

    <!-- Evidence by domain -->
    <VCol v-if="show('evidence_by_domain')" v-bind="col('evidence_by_domain', { md: 4 })">
      <VCard border class="h-100">
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('project_management.insights.evidence_domains') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.insights.evidence_domains_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VueApexCharts
            v-if="domainSeries.length"
            type="donut" :height="h" :options="domainOptions" :series="domainSeries"
          />
          <p v-else class="text-body-2 text-medium-emphasis text-center py-8">
            {{ t('project_management.insights.nothing_to_chart') }}
          </p>
        </VCardText>
      </VCard>
    </VCol>

    <!-- Progress by project -->
    <VCol v-if="show('progress_by_project')" v-bind="col('progress_by_project', { md: 6 })">
      <VCard border class="h-100">
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('project_management.insights.progress_by_project') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.insights.progress_by_project_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VueApexCharts
            v-if="charts.progress_by_project.length"
            type="bar" :height="h" :options="progressOptions" :series="progressSeries"
          />
          <p v-else class="text-body-2 text-medium-emphasis text-center py-8">
            {{ t('project_management.insights.nothing_to_chart') }}
          </p>
        </VCardText>
      </VCard>
    </VCol>

    <!-- Overdue by project -->
    <VCol v-if="show('overdue_by_project')" v-bind="col('overdue_by_project', { md: 6 })">
      <VCard border class="h-100">
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('project_management.insights.overdue_by_project') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.insights.overdue_by_project_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VueApexCharts
            v-if="charts.overdue_by_project.length"
            type="bar" :height="h" :options="overdueOptions" :series="overdueSeries"
          />
          <p v-else class="text-body-2 text-medium-emphasis text-center py-8">
            {{ t('project_management.insights.nothing_overdue') }}
          </p>
        </VCardText>
      </VCard>
    </VCol>

    <!-- Hours -->
    <VCol v-if="show('hours_trend')" v-bind="col('hours_trend', { md: 6 })">
      <VCard border class="h-100">
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('project_management.insights.hours_trend') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.insights.hours_trend_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VueApexCharts type="bar" :height="h" :options="hoursOptions" :series="hoursSeries" />
        </VCardText>
      </VCard>
    </VCol>

    <!-- Budget vs actual — absent entirely without the cost grant -->
    <VCol
      v-if="show('budget_vs_actual') && (charts.budget_vs_actual.length || keep('budget_vs_actual'))"
      v-bind="col('budget_vs_actual', { md: 6 })"
    >
      <VCard border class="h-100">
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('project_management.insights.budget_vs_actual') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.insights.budget_vs_actual_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VueApexCharts
            v-if="charts.budget_vs_actual.length"
            type="bar" :height="h" :options="budgetOptions" :series="budgetSeries"
          />
          <!--
            Says which of the two reasons this is empty. "Nothing to chart" would leave someone
            hunting for a broken chart when the answer is that no active project carries a budget
            — a draft one with a budget does not count, because nothing is being spent against it.
          -->
          <p v-else class="text-body-2 text-medium-emphasis text-center py-8">
            {{ t('project_management.insights.no_budgets_yet') }}
          </p>
        </VCardText>
      </VCard>
    </VCol>

    <!-- ── Finance ──────────────────────────────────────────────────────────── -->

    <VCol v-if="show('cost_position') && charts.cost_position.budget > 0" v-bind="col('cost_position')">
      <VCard border>
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('project_management.insights.cost_position') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.insights.cost_position_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VueApexCharts type="bar" :height="160" :options="costPositionOptions" :series="costPositionSeries" />
        </VCardText>
      </VCard>
    </VCol>

    <VCol v-if="show('cost_trend')" v-bind="col('cost_trend', { md: 8 })">
      <VCard border class="h-100">
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('project_management.insights.cost_trend') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.insights.cost_trend_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VueApexCharts type="area" :height="h" :options="costTrendOptions" :series="costTrendSeries" />
        </VCardText>
      </VCard>
    </VCol>

    <VCol v-if="show('cost_by_category') && charts.cost_by_category.length" v-bind="col('cost_by_category', { md: 4 })">
      <VCard border class="h-100">
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('project_management.insights.cost_by_category') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.insights.cost_by_category_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VueApexCharts type="donut" :height="h" :options="costCategoryOptions" :series="costCategorySeries" />
        </VCardText>
      </VCard>
    </VCol>

    <!-- ── Resources ────────────────────────────────────────────────────────── -->

    <VCol v-if="show('allocation_by_person') && charts.allocation_by_person.length" v-bind="col('allocation_by_person', { md: 8 })">
      <VCard border class="h-100">
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('project_management.insights.allocation_by_person') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.insights.allocation_by_person_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VueApexCharts type="bar" :height="h" :options="allocationOptions" :series="allocationSeries" />
        </VCardText>
      </VCard>
    </VCol>

    <VCol v-if="show('workload_health')" v-bind="col('workload_health', { md: 4 })">
      <VCard border class="h-100">
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('project_management.insights.workload_health') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.insights.workload_health_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VueApexCharts type="donut" :height="h" :options="workloadOptions" :series="workloadSeries" />
        </VCardText>
      </VCard>
    </VCol>

    <VCol v-if="show('role_mix') && charts.role_mix.length" v-bind="col('role_mix', { md: 4 })">
      <VCard border class="h-100">
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('project_management.insights.role_mix') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.insights.role_mix_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VueApexCharts type="donut" :height="h" :options="roleMixOptions" :series="roleMixSeries" />
        </VCardText>
      </VCard>
    </VCol>

    <!-- ── Earned value ─────────────────────────────────────────────────────── -->

    <!-- The risks a steering committee should be looking at, and whether anything is being
         done about them. A high score with no mitigation is the one worth seeing. -->
    <VCol v-if="show('high_risks') && charts.high_risks.length" v-bind="col('high_risks', { md: 6 })">
      <VCard border height="100%">
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('project_management.insights.high_risks') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.insights.high_risks_sub') }}</VCardSubtitle>
        </VCardItem>
        <VDivider />
        <VList density="compact">
          <VListItem v-for="r in charts.high_risks" :key="r.code">
            <VListItemTitle class="text-body-2">
              <span class="text-medium-emphasis">{{ r.project }}</span> {{ r.code }} · {{ r.title }}
            </VListItemTitle>
            <VListItemSubtitle v-if="!r.has_mitigation" class="text-caption text-error">
              {{ t('project_management.insights.no_mitigation') }}
            </VListItemSubtitle>
            <template #append>
              <VChip size="small" label color="error">{{ r.score }}</VChip>
            </template>
          </VListItem>
        </VList>
      </VCard>
    </VCol>

    <VCol v-if="show('client_satisfaction')" v-bind="col('client_satisfaction', { md: 3 })">
      <VCard border height="100%">
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('project_management.insights.satisfaction') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.insights.satisfaction_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText class="text-center">
          <div class="text-h3">
            {{ charts.client_satisfaction.score !== null ? charts.client_satisfaction.score : '—' }}
            <span v-if="charts.client_satisfaction.score !== null" class="text-h6 text-medium-emphasis">/ 5</span>
          </div>
          <!-- Says how many people said it: an average of one is not a satisfaction score. -->
          <div class="text-caption text-medium-emphasis">
            {{ charts.client_satisfaction.responses
              ? t('project_management.insights.n_responses', { n: charts.client_satisfaction.responses })
              : t('project_management.insights.no_responses') }}
          </div>
        </VCardText>
      </VCard>
    </VCol>

    <VCol v-if="show('earned_value') && charts.earned_value.bac !== null" v-bind="col('earned_value', { md: 4 })">
      <VCard border class="h-100">
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('project_management.insights.earned_value') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.insights.earned_value_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VueApexCharts type="bar" :height="h - 60" :options="evmOptions" :series="evmSeries" />

          <!-- The two indices, which are what a project manager is actually asked for. -->
          <div class="d-flex gap-4 justify-center mt-2">
            <div class="text-center">
              <VChip size="small" label :color="indexColour(charts.earned_value.spi)">
                {{ charts.earned_value.spi ?? '—' }}
              </VChip>
              <div class="text-caption text-medium-emphasis mt-1">
                {{ t('project_management.insights.spi') }}
              </div>
            </div>
            <div class="text-center">
              <VChip size="small" label :color="indexColour(charts.earned_value.cpi)">
                {{ charts.earned_value.cpi ?? '—' }}
              </VChip>
              <div class="text-caption text-medium-emphasis mt-1">
                {{ t('project_management.insights.cpi') }}
              </div>
            </div>
          </div>
        </VCardText>
      </VCard>
    </VCol>

    <VCol v-if="show('cash_flow') && charts.cash_flow.length" v-bind="col('cash_flow', { md: 8 })">
      <VCard border class="h-100">
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('project_management.insights.cash_flow') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.insights.cash_flow_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VueApexCharts type="bar" :height="h" :options="cashFlowOptions" :series="cashFlowSeries" />
        </VCardText>
      </VCard>
    </VCol>

    <!-- Health, worst first — a list rather than a chart, because the useful part is *why*. -->
    <VCol v-if="show('health_by_project') && charts.health_by_project.length" v-bind="col('health_by_project', { md: 6 })">
      <VCard border class="h-100">
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('project_management.insights.health') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.insights.health_sub') }}</VCardSubtitle>
        </VCardItem>
        <VDivider />
        <VList density="compact">
          <VListItem v-for="p in charts.health_by_project" :key="p.code">
            <VListItemTitle class="text-body-2">
              <span class="text-medium-emphasis me-1">{{ p.code }}</span>{{ p.name }}
            </VListItemTitle>
            <VListItemSubtitle class="text-caption">
              {{ t(`project_management.insights.health_worst_${p.worst}`) }}
              <template v-if="p.spi !== null"> · SPI {{ p.spi }}</template>
              <template v-if="p.cpi !== null"> · CPI {{ p.cpi }}</template>
            </VListItemSubtitle>
            <template #append>
              <VChip
                size="small" label
                :color="p.score >= 80 ? 'success' : p.score >= 60 ? 'warning' : 'error'"
              >
                {{ p.score }}
              </VChip>
            </template>
          </VListItem>
        </VList>
      </VCard>
    </VCol>

    <!-- Milestones due, overdue first. -->
    <VCol v-if="show('upcoming_milestones') && charts.upcoming_milestones.length" v-bind="col('upcoming_milestones', { md: 6 })">
      <VCard border class="h-100">
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('project_management.insights.upcoming_milestones') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.insights.upcoming_milestones_sub') }}</VCardSubtitle>
        </VCardItem>
        <VDivider />
        <VList density="compact">
          <VListItem v-for="(m, i) in charts.upcoming_milestones" :key="i">
            <template #prepend>
              <VIcon
                :icon="m.overdue ? 'tabler-flag-exclamation' : 'tabler-flag'"
                :color="m.overdue ? 'error' : 'primary'" size="18" class="me-2"
              />
            </template>
            <VListItemTitle class="text-body-2">
              <span class="text-medium-emphasis me-1">{{ m.project }}</span>{{ m.name }}
            </VListItemTitle>
            <VListItemSubtitle class="text-caption" :class="m.overdue ? 'text-error' : ''">
              {{ m.due }} ·
              {{ m.overdue
                ? t('project_management.insights.days_late', { count: Math.abs(m.days) })
                : t('project_management.insights.days_away', { count: m.days }) }}
            </VListItemSubtitle>
            <template #append>
              <span class="text-caption">{{ m.progress }}%</span>
            </template>
          </VListItem>
        </VList>
      </VCard>
    </VCol>

    <!-- ── Approvals ────────────────────────────────────────────────────────── -->

    <VCol v-if="show('approval_backlog')" v-bind="col('approval_backlog', { md: 8 })">
      <VCard border class="h-100">
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('project_management.insights.approval_backlog') }}</VCardTitle>
          <VCardSubtitle>{{ t('project_management.insights.approval_backlog_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VueApexCharts type="bar" :height="h" :options="approvalOptions" :series="approvalSeries" />
        </VCardText>
      </VCard>
    </VCol>
  </VRow>
</template>
