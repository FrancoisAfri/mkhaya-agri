<script setup lang="ts">
import { computed } from 'vue'
import { useTheme } from 'vuetify'
import { useI18n } from 'vue-i18n'
import VueApexCharts from 'vue3-apexcharts'
import type { ApexOptions } from 'apexcharts'

/**
 * Every Budget Management chart, in one place.
 *
 * Shared by the dashboard and the Analytics page so the two cannot drift into showing the same
 * figure differently — which is how a landing page and a board pack end up disagreeing and nobody
 * trusts either.
 */

type Composition = { label: string; allocated: number; committed: number; actual: number }

const props = defineProps<{
  charts: {
    position: { reserved: number; committed: number; actual: number; available: number }
    by_category: Composition[]
    by_expenditure_type: Composition[]
    by_scope: Composition[]
    utilisation_bands: { under_50: number; from_50: number; from_80: number; from_95: number; over: number }
    top_utilised: Array<{ id: number; code: string; name: string; allocated: number; used: number; percent: number }>
    overruns: Array<{ id: number; code: string; name: string; allocated: number; over_by: number }>
    spend_trend: Array<{ month: string; amount: number; cumulative: number }>
    encumbrance_trend: Array<{ month: string; reserved: number; committed: number }>
    adjustment_mix: Array<{ type: string; status: string; count: number; amount: number }>
    approval_backlog: { today: number; this_week: number; over_a_week: number; over_a_month: number }
    approval_cycle: Array<{ label: string; count: number; hours: number }>
    alerts_by_severity: Array<{ label: string; count: number }>
    spend_by_source: Array<{ label: string; amount: number; count: number }>
  }
  /** Which charts to draw. Omit for all of them. */
  only?: string[]
  height?: number
  currency?: string | null
}>()

const { t } = useI18n()
const theme = useTheme()

const show = (key: string) => !props.only || props.only.includes(key)
const h = computed(() => props.height ?? 300)

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

/** Compact money for an axis — "R 1.2m" beats "1200000" on a label six pixels wide. */
function short(value: number) {
  try {
    return new Intl.NumberFormat(undefined, {
      style: 'currency', currency: props.currency || 'ZAR',
      notation: 'compact', maximumFractionDigits: 1,
    }).format(value)
  } catch {
    return String(Math.round(value))
  }
}

const moneyAxis = computed(() => ({ labels: { formatter: (v: number) => short(v) } }))

// ── Position ─────────────────────────────────────────────────────────────────

const positionOptions = computed<ApexOptions>(() => ({
  ...base.value,
  labels: [
    t('budgets_insights.chart.reserved'),
    t('budgets_insights.chart.committed'),
    t('budgets_insights.chart.actual'),
    t('budgets_insights.chart.available'),
  ],
  legend: { ...base.value.legend, position: 'bottom' },
  tooltip: { ...base.value.tooltip, y: { formatter: (v: number) => short(v) } },
}))
const positionSeries = computed(() => [
  props.charts.position.reserved,
  props.charts.position.committed,
  props.charts.position.actual,
  props.charts.position.available,
])

// ── Composition (category / type / scope) ────────────────────────────────────

function compositionOptions(rows: Composition[]): ApexOptions {
  return {
    ...base.value,
    chart: { ...base.value.chart, type: 'bar', stacked: false },
    plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '60%' } },
    xaxis: { categories: rows.map(r => r.label), ...moneyAxis.value },
    tooltip: { ...base.value.tooltip, y: { formatter: (v: number) => short(v) } },
  }
}

function compositionSeries(rows: Composition[]) {
  return [
    { name: t('budgets_insights.chart.allocated'), data: rows.map(r => r.allocated) },
    { name: t('budgets_insights.chart.committed'), data: rows.map(r => r.committed) },
    { name: t('budgets_insights.chart.actual'), data: rows.map(r => r.actual) },
  ]
}

// ── Bands ────────────────────────────────────────────────────────────────────

const bandOptions = computed<ApexOptions>(() => ({
  ...base.value,
  labels: [
    t('budgets_insights.band.under_50'),
    t('budgets_insights.band.from_50'),
    t('budgets_insights.band.from_80'),
    t('budgets_insights.band.from_95'),
    t('budgets_insights.band.over'),
  ],
  colors: [
    theme.current.value.colors.success,
    theme.current.value.colors.info,
    theme.current.value.colors.warning,
    theme.current.value.colors['primary-darken-1'],
    theme.current.value.colors.error,
  ],
  legend: { ...base.value.legend, position: 'bottom' },
}))
const bandSeries = computed(() => {
  const b = props.charts.utilisation_bands

  return [b.under_50, b.from_50, b.from_80, b.from_95, b.over]
})

// ── Trends ───────────────────────────────────────────────────────────────────

const spendOptions = computed<ApexOptions>(() => ({
  ...base.value,
  chart: { ...base.value.chart, type: 'line' },
  stroke: { curve: 'smooth', width: [0, 3] },
  xaxis: { categories: props.charts.spend_trend.map(r => r.month) },
  yaxis: [
    { ...moneyAxis.value, title: { text: t('budgets_insights.chart.per_month') } },
    { opposite: true, ...moneyAxis.value, title: { text: t('budgets_insights.chart.cumulative') } },
  ],
  tooltip: { ...base.value.tooltip, y: { formatter: (v: number) => short(v) } },
}))
const spendSeries = computed(() => [
  { name: t('budgets_insights.chart.per_month'), type: 'column', data: props.charts.spend_trend.map(r => r.amount) },
  { name: t('budgets_insights.chart.cumulative'), type: 'line', data: props.charts.spend_trend.map(r => r.cumulative) },
])

const encumbranceOptions = computed<ApexOptions>(() => ({
  ...base.value,
  chart: { ...base.value.chart, type: 'area', stacked: true },
  stroke: { curve: 'smooth', width: 2 },
  fill: { type: 'gradient', gradient: { opacityFrom: 0.4, opacityTo: 0.05 } },
  xaxis: { categories: props.charts.encumbrance_trend.map(r => r.month) },
  yaxis: moneyAxis.value,
  tooltip: { ...base.value.tooltip, y: { formatter: (v: number) => short(v) } },
}))
const encumbranceSeries = computed(() => [
  { name: t('budgets_insights.chart.reserved'), data: props.charts.encumbrance_trend.map(r => r.reserved) },
  { name: t('budgets_insights.chart.committed'), data: props.charts.encumbrance_trend.map(r => r.committed) },
])

// ── Governance ───────────────────────────────────────────────────────────────

const backlogOptions = computed<ApexOptions>(() => ({
  ...base.value,
  chart: { ...base.value.chart, type: 'bar' },
  plotOptions: { bar: { borderRadius: 4, columnWidth: '45%', distributed: true } },
  legend: { show: false },
  colors: [
    theme.current.value.colors.success,
    theme.current.value.colors.info,
    theme.current.value.colors.warning,
    theme.current.value.colors.error,
  ],
  xaxis: {
    categories: [
      t('budgets_insights.backlog.today'),
      t('budgets_insights.backlog.this_week'),
      t('budgets_insights.backlog.over_a_week'),
      t('budgets_insights.backlog.over_a_month'),
    ],
  },
}))
const backlogSeries = computed(() => [{
  name: t('budgets_insights.chart.waiting'),
  data: [
    props.charts.approval_backlog.today,
    props.charts.approval_backlog.this_week,
    props.charts.approval_backlog.over_a_week,
    props.charts.approval_backlog.over_a_month,
  ],
}])

const cycleOptions = computed<ApexOptions>(() => ({
  ...base.value,
  chart: { ...base.value.chart, type: 'bar' },
  plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '50%' } },
  xaxis: {
    categories: props.charts.approval_cycle.map(r => t(`budgets_approvals.subject_${r.label}`)),
    labels: { formatter: (v: number) => `${Math.round(Number(v))}h` },
  },
}))
const cycleSeries = computed(() => [{
  name: t('budgets_insights.chart.hours_to_decide'),
  data: props.charts.approval_cycle.map(r => r.hours),
}])

const alertOptions = computed<ApexOptions>(() => ({
  ...base.value,
  labels: props.charts.alerts_by_severity.map(r => t(`budgets_tracking.severity_${r.label}`)),
  colors: [
    theme.current.value.colors.info,
    theme.current.value.colors.warning,
    theme.current.value.colors.error,
    theme.current.value.colors['primary-darken-1'],
  ],
  legend: { ...base.value.legend, position: 'bottom' },
}))
const alertSeries = computed(() => props.charts.alerts_by_severity.map(r => r.count))

const sourceOptions = computed<ApexOptions>(() => ({
  ...base.value,
  chart: { ...base.value.chart, type: 'bar' },
  plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '55%' } },
  xaxis: { categories: props.charts.spend_by_source.map(r => r.label), ...moneyAxis.value },
  tooltip: { ...base.value.tooltip, y: { formatter: (v: number) => short(v) } },
}))
const sourceSeries = computed(() => [{
  name: t('budgets_insights.chart.actual'),
  data: props.charts.spend_by_source.map(r => r.amount),
}])

/** Adjustments, folded to one bar per type — approved and pending side by side. */
const adjustmentRows = computed(() => {
  const types = [...new Set(props.charts.adjustment_mix.map(r => r.type))]

  return types.map(type => ({
    type,
    approved: props.charts.adjustment_mix.filter(r => r.type === type && r.status === 'approved')
      .reduce((s, r) => s + r.amount, 0),
    pending: props.charts.adjustment_mix.filter(r => r.type === type && r.status === 'pending')
      .reduce((s, r) => s + r.amount, 0),
  }))
})

const adjustmentOptions = computed<ApexOptions>(() => ({
  ...base.value,
  chart: { ...base.value.chart, type: 'bar', stacked: true },
  plotOptions: { bar: { borderRadius: 4, columnWidth: '45%' } },
  xaxis: { categories: adjustmentRows.value.map(r => t(`budgets_insights.adjustment.${r.type}`)) },
  yaxis: moneyAxis.value,
  tooltip: { ...base.value.tooltip, y: { formatter: (v: number) => short(v) } },
}))
const adjustmentSeries = computed(() => [
  { name: t('budgets_approvals.status_approved'), data: adjustmentRows.value.map(r => r.approved) },
  { name: t('budgets_approvals.status_pending'), data: adjustmentRows.value.map(r => r.pending) },
])

const hasSpend = computed(() => props.charts.spend_trend.some(r => r.amount > 0))
</script>

<template>
  <VRow>
    <!-- Where the money stands right now -->
    <VCol v-if="show('position')" cols="12" md="4">
      <VCard border class="h-100">
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('budgets_insights.chart.position_title') }}</VCardTitle>
          <VCardSubtitle>{{ t('budgets_insights.chart.position_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VueApexCharts type="donut" :height="h" :options="positionOptions" :series="positionSeries" />
        </VCardText>
      </VCard>
    </VCol>

    <VCol v-if="show('bands')" cols="12" md="4">
      <VCard border class="h-100">
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('budgets_insights.chart.bands_title') }}</VCardTitle>
          <VCardSubtitle>{{ t('budgets_insights.chart.bands_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VueApexCharts type="donut" :height="h" :options="bandOptions" :series="bandSeries" />
        </VCardText>
      </VCard>
    </VCol>

    <VCol v-if="show('alerts')" cols="12" md="4">
      <VCard border class="h-100">
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('budgets_insights.chart.alerts_title') }}</VCardTitle>
          <VCardSubtitle>{{ t('budgets_insights.chart.alerts_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VueApexCharts type="donut" :height="h" :options="alertOptions" :series="alertSeries" />
        </VCardText>
      </VCard>
    </VCol>

    <!-- Composition -->
    <VCol v-if="show('by_category') && charts.by_category.length" cols="12" md="6">
      <VCard border class="h-100">
        <VCardItem><VCardTitle class="text-body-1">{{ t('budgets_insights.chart.by_category') }}</VCardTitle></VCardItem>
        <VCardText>
          <VueApexCharts
            type="bar" :height="h"
            :options="compositionOptions(charts.by_category)" :series="compositionSeries(charts.by_category)"
          />
        </VCardText>
      </VCard>
    </VCol>

    <VCol v-if="show('by_expenditure_type') && charts.by_expenditure_type.length" cols="12" md="6">
      <VCard border class="h-100">
        <VCardItem><VCardTitle class="text-body-1">{{ t('budgets_insights.chart.by_type') }}</VCardTitle></VCardItem>
        <VCardText>
          <VueApexCharts
            type="bar" :height="h"
            :options="compositionOptions(charts.by_expenditure_type)" :series="compositionSeries(charts.by_expenditure_type)"
          />
        </VCardText>
      </VCard>
    </VCol>

    <!-- Trends -->
    <VCol v-if="show('spend_trend')" cols="12" md="6">
      <VCard border class="h-100">
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('budgets_insights.chart.spend_title') }}</VCardTitle>
          <VCardSubtitle>{{ t('budgets_insights.chart.spend_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VueApexCharts v-if="hasSpend" :height="h" :options="spendOptions" :series="spendSeries" />
          <!-- An empty chart reads as broken; the sentence says which screen fills it. -->
          <div v-else class="text-center text-medium-emphasis py-8 text-body-2">
            {{ t('budgets_insights.chart.spend_empty') }}
          </div>
        </VCardText>
      </VCard>
    </VCol>

    <VCol v-if="show('encumbrance_trend')" cols="12" md="6">
      <VCard border class="h-100">
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('budgets_insights.chart.pipeline_title') }}</VCardTitle>
          <VCardSubtitle>{{ t('budgets_insights.chart.pipeline_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VueApexCharts type="area" :height="h" :options="encumbranceOptions" :series="encumbranceSeries" />
        </VCardText>
      </VCard>
    </VCol>

    <!-- Governance -->
    <VCol v-if="show('approval_backlog')" cols="12" md="6">
      <VCard border class="h-100">
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('budgets_insights.chart.backlog_title') }}</VCardTitle>
          <VCardSubtitle>{{ t('budgets_insights.chart.backlog_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VueApexCharts type="bar" :height="h" :options="backlogOptions" :series="backlogSeries" />
        </VCardText>
      </VCard>
    </VCol>

    <VCol v-if="show('approval_cycle') && charts.approval_cycle.length" cols="12" md="6">
      <VCard border class="h-100">
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('budgets_insights.chart.cycle_title') }}</VCardTitle>
          <VCardSubtitle>{{ t('budgets_insights.chart.cycle_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VueApexCharts type="bar" :height="h" :options="cycleOptions" :series="cycleSeries" />
        </VCardText>
      </VCard>
    </VCol>

    <VCol v-if="show('adjustment_mix') && adjustmentRows.length" cols="12" md="6">
      <VCard border class="h-100">
        <VCardItem><VCardTitle class="text-body-1">{{ t('budgets_insights.chart.adjustments_title') }}</VCardTitle></VCardItem>
        <VCardText>
          <VueApexCharts type="bar" :height="h" :options="adjustmentOptions" :series="adjustmentSeries" />
        </VCardText>
      </VCard>
    </VCol>

    <VCol v-if="show('spend_by_source') && charts.spend_by_source.length" cols="12" md="6">
      <VCard border class="h-100">
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('budgets_insights.chart.source_title') }}</VCardTitle>
          <VCardSubtitle>{{ t('budgets_insights.chart.source_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VueApexCharts type="bar" :height="h" :options="sourceOptions" :series="sourceSeries" />
        </VCardText>
      </VCard>
    </VCol>

    <!-- Leaderboards: the rows somebody acts on, not a chart to admire -->
    <VCol v-if="show('top_utilised') && charts.top_utilised.length" cols="12" md="6">
      <VCard border class="h-100">
        <VCardItem><VCardTitle class="text-body-1">{{ t('budgets_insights.chart.top_title') }}</VCardTitle></VCardItem>
        <VDivider />
        <VTable density="compact">
          <tbody>
            <tr v-for="row in charts.top_utilised" :key="row.id">
              <td>
                <div class="font-weight-medium">{{ row.code }}</div>
                <div class="text-caption text-medium-emphasis">{{ row.name }}</div>
              </td>
              <td style="inline-size: 45%">
                <VProgressLinear
                  :model-value="Math.min(100, row.percent)"
                  :color="row.percent >= 95 ? 'error' : row.percent >= 80 ? 'warning' : 'primary'"
                  height="8" rounded
                />
              </td>
              <td class="text-right text-no-wrap">{{ row.percent }}%</td>
            </tr>
          </tbody>
        </VTable>
      </VCard>
    </VCol>

    <VCol v-if="show('overruns') && charts.overruns.length" cols="12" md="6">
      <VCard border class="h-100">
        <VCardItem>
          <VCardTitle class="text-body-1">{{ t('budgets_insights.chart.overruns_title') }}</VCardTitle>
          <VCardSubtitle>{{ t('budgets_insights.chart.overruns_sub') }}</VCardSubtitle>
        </VCardItem>
        <VDivider />
        <VTable density="compact">
          <tbody>
            <tr v-for="row in charts.overruns" :key="row.id">
              <td>
                <div class="font-weight-medium">{{ row.code }}</div>
                <div class="text-caption text-medium-emphasis">{{ row.name }}</div>
              </td>
              <td class="text-right text-error font-weight-medium text-no-wrap">{{ short(row.over_by) }}</td>
            </tr>
          </tbody>
        </VTable>
      </VCard>
    </VCol>
  </VRow>
</template>
