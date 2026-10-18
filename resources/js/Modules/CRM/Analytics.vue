<script lang="ts" setup>
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import VueApexCharts from 'vue3-apexcharts'
import type { ApexOptions } from 'apexcharts'

const { t } = useI18n()

interface Trend { month: string; count: number; value: number }
interface FunnelRow { stage: string; count: number; value: number; conversion: number }
interface RepSeries { categories: string[]; reps: { name: string; data: number[] }[] }

const props = defineProps<{
  summary: { open_count: number; open_value: number; weighted_pipeline: number; won_count: number; won_value: number; win_rate: number; avg_deal_size: number }
  dealsCreated: Trend[]
  wonTrend: Trend[]
  leadsTrend: Trend[]
  funnel: FunnelRow[]
  leadSourceMix: { source: string; count: number }[]
  activityVolume: { type: string; count: number }[]
  callsEmailsTrend: { month: string; calls: number; emails: number }[]
  winLoss: { won: number; lost: number; win_rate: number }
  repMonthly: RepSeries
  repWeekly: RepSeries
  narrative: string | null
  aiEnabled: boolean
}>()

function narrate() { router.post(ziggyRoute('crm.analytics.narrate'), {}, { preserveScroll: true }) }

const money = (n: number) => 'R ' + (n ?? 0).toLocaleString('en-ZA', { maximumFractionDigits: 0 })

const cards = computed(() => [
  { label: t('crm_analytics_index.cards.open_pipeline'), value: money(props.summary.open_value), color: 'primary' },
  { label: t('crm_analytics_index.cards.weighted_forecast'), value: money(props.summary.weighted_pipeline), color: 'info' },
  { label: t('crm_analytics_index.cards.won_value'), value: money(props.summary.won_value), color: 'success' },
  { label: t('crm_analytics_index.cards.win_rate'), value: props.summary.win_rate + '%', color: 'teal' },
])

const trendSeries = computed(() => [
  { name: 'Deals created', type: 'column', data: props.dealsCreated.map(d => d.count) },
  { name: 'Won value', type: 'line', data: props.wonTrend.map(d => d.value) },
])
const trendOptions = computed<ApexOptions>(() => ({
  chart: { stacked: false, toolbar: { show: false } },
  stroke: { width: [0, 3], curve: 'smooth' },
  xaxis: { categories: props.dealsCreated.map(d => d.month) },
  yaxis: [{ title: { text: 'Deals' } }, { opposite: true, title: { text: 'Won value' }, labels: { formatter: (v: number) => money(v) } }],
}))

const leadsSeries = computed(() => [{ name: 'New leads', data: props.leadsTrend.map(d => d.count) }])
const leadsOptions = computed<ApexOptions>(() => ({
  chart: { type: 'area', toolbar: { show: false } }, stroke: { curve: 'smooth', width: 2 },
  xaxis: { categories: props.leadsTrend.map(d => d.month) }, dataLabels: { enabled: false },
}))

const funnelSeries = computed(() => [{ name: 'Deals', data: props.funnel.map(f => f.count) }])
const funnelOptions = computed<ApexOptions>(() => ({
  chart: { type: 'bar', toolbar: { show: false } },
  plotOptions: { bar: { horizontal: true, distributed: true, borderRadius: 4 } },
  xaxis: { categories: props.funnel.map(f => f.stage) }, legend: { show: false }, dataLabels: { enabled: true },
}))

const sourceSeries = computed(() => props.leadSourceMix.map(s => s.count))
const sourceOptions = computed<ApexOptions>(() => ({ chart: { type: 'donut' }, labels: props.leadSourceMix.map(s => s.source), legend: { position: 'bottom' } }))

const actSeries = computed(() => [{ name: 'Activities', data: props.activityVolume.map(a => a.count) }])
const actOptions = computed<ApexOptions>(() => ({
  chart: { type: 'bar', toolbar: { show: false } }, plotOptions: { bar: { borderRadius: 4, distributed: true } },
  xaxis: { categories: props.activityVolume.map(a => a.type) }, legend: { show: false },
}))

const commsSeries = computed(() => [
  { name: 'Calls', data: props.callsEmailsTrend.map(d => d.calls) },
  { name: 'Emails', data: props.callsEmailsTrend.map(d => d.emails) },
])
const commsOptions = computed<ApexOptions>(() => ({
  chart: { type: 'line', toolbar: { show: false } }, stroke: { width: 3, curve: 'smooth' },
  xaxis: { categories: props.callsEmailsTrend.map(d => d.month) }, dataLabels: { enabled: false },
  legend: { position: 'top' }, colors: ['#16B1FF', '#7367F0'],
}))
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3">
      <div>
        <h5 class="text-h5 font-weight-semibold">{{ t('crm_analytics_index.page_title') }}</h5>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_analytics_index.description') }}</p>
      </div>
      <VBtn color="primary" variant="tonal" prepend-icon="tabler-sparkles" :disabled="!aiEnabled" @click="narrate">{{ t('crm_analytics_index.ai_narrative') }}</VBtn>
    </div>

    <VCard v-if="narrative" variant="tonal" color="primary" class="mb-4"><VCardText>
      <div class="d-flex align-center ga-2 mb-1"><VIcon icon="tabler-sparkles" /><span class="font-weight-medium">{{ t('crm_analytics_index.executive_narrative') }}</span></div>
      <div class="text-body-1" style="white-space: pre-wrap">{{ narrative }}</div>
    </VCardText></VCard>

    <VRow class="mb-2">
      <VCol v-for="c in cards" :key="c.label" cols="12" sm="6" md="3">
        <VCard><VCardText>
          <div class="text-h5 font-weight-bold" :class="`text-${c.color}`">{{ c.value }}</div>
          <div class="text-body-2 text-medium-emphasis">{{ c.label }}</div>
        </VCardText></VCard>
      </VCol>
    </VRow>

    <VRow>
      <VCol cols="12" md="8">
        <VCard :title="t('crm_analytics_index.charts.deals_vs_won')">
          <VCardText><VueApexCharts type="line" :height="300" :options="trendOptions" :series="trendSeries" /></VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="4">
        <VCard :title="t('crm_analytics_index.charts.lead_sources')">
          <VCardText>
            <VueApexCharts v-if="leadSourceMix.length" type="donut" :height="300" :options="sourceOptions" :series="sourceSeries" />
            <div v-else class="text-medium-emphasis text-body-2">{{ t('crm_analytics_index.charts.no_leads') }}</div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="6">
        <VCard :title="t('crm_analytics_index.charts.new_leads')">
          <VCardText><VueApexCharts type="area" :height="280" :options="leadsOptions" :series="leadsSeries" /></VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="6">
        <VCard :title="t('crm_analytics_index.charts.pipeline_funnel')">
          <VCardText>
            <VueApexCharts v-if="funnel.length" type="bar" :height="280" :options="funnelOptions" :series="funnelSeries" />
            <div v-else class="text-medium-emphasis text-body-2">{{ t('crm_analytics_index.charts.no_pipeline') }}</div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12">
        <VCard :title="t('crm_analytics_index.charts.calls_emails')">
          <VCardText><VueApexCharts type="line" :height="280" :options="commsOptions" :series="commsSeries" /></VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="6">
        <VCard :title="t('crm_analytics_index.charts.activity_volume')">
          <VCardText>
            <VueApexCharts v-if="activityVolume.length" type="bar" :height="260" :options="actOptions" :series="actSeries" />
            <div v-else class="text-medium-emphasis text-body-2">{{ t('crm_analytics_index.charts.no_activities') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="6">
        <VCard :title="t('crm_analytics_index.charts.win_loss')">
          <VCardText class="d-flex align-center justify-space-around" style="min-height: 260px">
            <div class="text-center"><div class="text-h3 text-success">{{ winLoss.won }}</div><div class="text-medium-emphasis">{{ t('crm_analytics_index.charts.won') }}</div></div>
            <div class="text-center"><div class="text-h3 text-error">{{ winLoss.lost }}</div><div class="text-medium-emphasis">{{ t('crm_analytics_index.charts.lost') }}</div></div>
            <div class="text-center"><div class="text-h3 text-primary">{{ winLoss.win_rate }}%</div><div class="text-medium-emphasis">{{ t('crm_analytics_index.cards.win_rate') }}</div></div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
