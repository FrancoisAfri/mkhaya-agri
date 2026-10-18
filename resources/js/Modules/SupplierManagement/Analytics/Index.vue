<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import VueApexCharts from 'vue3-apexcharts'
import type { ApexOptions } from 'apexcharts'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

// ── Colors ──────────────────────────────────────────────────────────────────────
const success = '#28c76f'
const warning = '#ff9f43'
const error   = '#ea5455'
const info     = '#00cfe8'
const primary  = '#7367f0'
const secondary = '#82868b'

// ── Types ──────────────────────────────────────────────────────────────────────
interface Kpis {
  total: number
  active: number
  pending_kyc: number
  high_risk: number
  open_scars: number
  expiring_docs_90: number
}

interface StatusItem {
  value: number
  label: string
}

interface ByStatus {
  status: string
  count: number
}

interface BySegment {
  segment: string
  count: number
}

interface RiskByLevel {
  level: string
  count: number
}

interface OnboardingTrend {
  month: string
  count: number
}

interface TopSpend {
  supplier: string
  total: number
}

interface DocExpiryBucket {
  bucket: string
  count: number
}

// ── Props ──────────────────────────────────────────────────────────────────────
const props = defineProps<{
  period: string
  dateFrom: string | null
  dateTo: string | null
  filters: { segment: string | null; status: number | null }
  statusOptions: StatusItem[]
  segmentOptions: string[]
  kpis: Kpis
  byStatus: ByStatus[]
  bySegment: BySegment[]
  riskByLevel: RiskByLevel[]
  onboardingTrend: OnboardingTrend[]
  topSpend: TopSpend[]
  docExpiryBuckets: DocExpiryBucket[]
}>()

// ── Period options ─────────────────────────────────────────────────────────────
const periodOptions = computed(()=> [
  { value: 'month', label: t('supplier_analytics.filters.period_options.month') },
  { value: 'quarter', label: t('supplier_analytics.filters.period_options.quarter') },
  { value: 'halfyear', label: t('supplier_analytics.filters.period_options.halfyear') },
  { value: 'year', label: t('supplier_analytics.filters.period_options.year') },
  { value: 'lastyear', label: t('supplier_analytics.filters.period_options.lastyear') },
])

// ── State ──────────────────────────────────────────────────────────────────────
const useCustomDates = ref(!!(props.dateFrom || props.dateTo))
const form = ref({
  period: props.period ?? 'year',
  date_from: props.dateFrom ?? '',
  date_to: props.dateTo ?? '',
  segment: props.filters.segment ?? '',
  status: props.filters.status !== null ? String(props.filters.status) : '',
})

// ── Methods ────────────────────────────────────────────────────────────────────
function applyFilters(): void {
  const params: Record<string, string> = {}
  if (useCustomDates.value) {
    if (form.value.date_from) params.date_from = form.value.date_from
    if (form.value.date_to) params.date_to = form.value.date_to
  } else {
    params.period = form.value.period
  }
  if (form.value.segment) params.segment = form.value.segment
  if (form.value.status !== '') params.status = form.value.status
  router.get(route('suppliers.analytics'), params, {
    preserveState: true,
    preserveScroll: true
  })
}

function clearFilters(): void {
  form.value = { period: 'year', date_from: '', date_to: '', segment: '', status: '' }
  useCustomDates.value = false
  router.get(route('suppliers.analytics'), { period: 'year' }, {
    preserveState: true,
    preserveScroll: true
  })
}

// ── Helpers ────────────────────────────────────────────────────────────────────
const fmtN = (v: number): string => Number(v).toLocaleString('en-ZA')
const fmtMoney = (v: number): string =>
  'R ' + Number(v).toLocaleString('en-ZA', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  })

const monthLabel = (m: string): string => {
  const [y, mo] = m.split('-')
  return new Date(+y, +mo - 1).toLocaleString('en-ZA', {
    month: 'short',
    year: '2-digit'
  })
}

function getStatusLabel(status: string): string {
  const map: Record<string, string> = {
    pending: t('supplier_analytics.statuses.pending'),
    active: t('supplier_analytics.statuses.active'),
    refused: t('supplier_analytics.statuses.refused'),
    pending_kyc: t('supplier_analytics.statuses.pending_kyc'),
  }
  return map[status] || status
}

function getSegmentLabel(segment: string): string {
  const map: Record<string, string> = {
    strategic: t('supplier_analytics.segments.strategic'),
    preferred: t('supplier_analytics.segments.preferred'),
    approved: t('supplier_analytics.segments.approved'),
    tail: t('supplier_analytics.segments.tail'),
  }
  return map[segment] || segment
}

function getRiskLabel(level: string): string {
  const map: Record<string, string> = {
    low: t('supplier_analytics.risk_levels.low'),
    medium: t('supplier_analytics.risk_levels.medium'),
    high: t('supplier_analytics.risk_levels.high'),
    critical: t('supplier_analytics.risk_levels.critical'),
  }
  return map[level] || level
}

// ── Computed ──────────────────────────────────────────────────────────────────
const kpiCards = computed(() => [
  {
    title: t('supplier_analytics.kpis.total_suppliers'),
    value: fmtN(props.kpis.total),
    sub: `${props.kpis.active} ${t('supplier_analytics.kpis.active')}`,
    icon: 'tabler-building-store',
    color: 'primary'
  },
  {
    title: t('supplier_analytics.kpis.active'),
    value: fmtN(props.kpis.active),
    sub: t('supplier_analytics.sub_labels.active'),
    icon: 'tabler-circle-check',
    color: 'success'
  },
  {
    title: t('supplier_analytics.kpis.pending_kyc'),
    value: fmtN(props.kpis.pending_kyc),
    sub: t('supplier_analytics.sub_labels.pending_kyc'),
    icon: 'tabler-id-badge-2',
    color: 'warning'
  },
  {
    title: t('supplier_analytics.kpis.high_risk'),
    value: fmtN(props.kpis.high_risk),
    sub: t('supplier_analytics.sub_labels.high_risk'),
    icon: 'tabler-alert-triangle',
    color: 'error'
  },
  {
    title: t('supplier_analytics.kpis.open_scars'),
    value: fmtN(props.kpis.open_scars),
    sub: t('supplier_analytics.sub_labels.open_scars'),
    icon: 'tabler-clipboard-x',
    color: 'error'
  },
  {
    title: t('supplier_analytics.kpis.docs_expiring'),
    value: fmtN(props.kpis.expiring_docs_90),
    sub: t('supplier_analytics.sub_labels.docs_expiring'),
    icon: 'tabler-file-alert',
    color: 'warning'
  },
])

// ── Status donut ──────────────────────────────────────────────────────────────
const statusSeries = computed(() => props.byStatus.map(r => r.count))
const statusOptionsChart = computed<ApexOptions>(() => ({
  chart: { type: 'donut' },
  labels: props.byStatus.map(r => getStatusLabel(r.status)),
  colors: [warning, success, error, info],
  legend: { position: 'bottom' },
}))

// ── Segment donut ─────────────────────────────────────────────────────────────
const segmentSeries = computed(() => props.bySegment.map(r => r.count))
const segmentOptionsChart = computed<ApexOptions>(() => ({
  chart: { type: 'donut' },
  labels: props.bySegment.map(r => getSegmentLabel(r.segment)),
  colors: [primary, info, success, warning, error, secondary],
  legend: { position: 'bottom' },
}))

// ── Risk donut ────────────────────────────────────────────────────────────────
const riskSeries = computed(() => props.riskByLevel.map(r => r.count))
const riskOptionsChart = computed<ApexOptions>(() => ({
  chart: { type: 'donut' },
  labels: props.riskByLevel.map(r => getRiskLabel(r.level)),
  colors: [error, warning, success, secondary],
  legend: { position: 'bottom' },
}))

// ── Onboarding line ───────────────────────────────────────────────────────────
const onboardingSeries = computed(() => [
  { name: t('supplier_analytics.kpis.total_suppliers'), data: props.onboardingTrend.map(r => r.count) }
])
const onboardingOptionsChart = computed<ApexOptions>(() => ({
  chart: { type: 'line', toolbar: { show: false }, zoom: { enabled: false } },
  stroke: { curve: 'smooth', width: 3 },
  colors: [primary],
  xaxis: { categories: props.onboardingTrend.map(r => monthLabel(r.month)) },
  yaxis: { labels: { formatter: (v: number) => String(Math.round(v)) } },
}))

// ── Top spend bar ─────────────────────────────────────────────────────────────
const spendSeries = computed(() => [
  { name: t('supplier_analytics.charts.top_spend'), data: props.topSpend.map(r => r.total) }
])
const spendOptionsChart = computed<ApexOptions>(() => ({
  chart: { type: 'bar', toolbar: { show: false } },
  plotOptions: { bar: { horizontal: true, borderRadius: 4 } },
  colors: [success],
  xaxis: { categories: props.topSpend.map(r => r.supplier) },
  dataLabels: { enabled: false },
  tooltip: { y: { formatter: (v: number) => fmtMoney(v) } },
}))

// ── Doc expiry buckets bar ────────────────────────────────────────────────────
const bucketSeries = computed(() => [
  { name: t('supplier_analytics.charts.doc_expiry'), data: props.docExpiryBuckets.map(r => r.count) }
])
const bucketOptionsChart = computed<ApexOptions>(() => ({
  chart: { type: 'bar', toolbar: { show: false } },
  plotOptions: { bar: { borderRadius: 4, columnWidth: '45%' } },
  colors: [warning],
  xaxis: { categories: props.docExpiryBuckets.map(r => `${r.bucket} days`) },
  dataLabels: { enabled: true },
}))
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <h1 class="text-h4">{{ t('supplier_analytics.page_title') }}</h1>
        <p class="text-medium-emphasis mb-0">{{ t('supplier_analytics.page_description') }}</p>
      </div>
    </div>

    <!-- Filter bar -->
    <VCard elevation="0" border class="mb-6">
      <VCardText>
        <VRow dense align="center">
          <VCol cols="12" sm="3">
            <VSwitch
              v-model="useCustomDates"
              :label="t('supplier_analytics.filters.custom_dates')"
              hide-details
              density="compact"
            />
          </VCol>
          <VCol v-if="!useCustomDates" cols="12" sm="3">
            <VSelect
              v-model="form.period"
              :items="periodOptions"
              item-title="label"
              item-value="value"
              :label="t('supplier_analytics.filters.period')"
              variant="outlined"
              density="compact"
              hide-details
            />
          </VCol>
          <template v-else>
            <VCol cols="12" sm="3">
              <VTextField
                v-model="form.date_from"
                type="date"
                :label="t('supplier_analytics.filters.from')"
                variant="outlined"
                density="compact"
                hide-details
              />
            </VCol>
            <VCol cols="12" sm="3">
              <VTextField
                v-model="form.date_to"
                type="date"
                :label="t('supplier_analytics.filters.to')"
                variant="outlined"
                density="compact"
                hide-details
              />
            </VCol>
          </template>
          <VCol cols="12" sm="3">
            <VSelect
              v-model="form.segment"
              :items="segmentOptions"
              :label="t('supplier_analytics.filters.segment')"
              variant="outlined"
              density="compact"
              clearable
              hide-details
              :item-title="(v: string) => getSegmentLabel(v)"
            />
          </VCol>
          <VCol cols="12" sm="3">
            <VSelect
              v-model="form.status"
              :items="statusOptions"
              item-title="label"
              :item-value="(i: any) => String(i.value)"
              :label="t('supplier_analytics.filters.status')"
              variant="outlined"
              density="compact"
              clearable
              hide-details
            />
          </VCol>
          <VCol cols="12" class="d-flex gap-2 justify-end">
            <VBtn variant="text" @click="clearFilters">{{ t('supplier_analytics.filters.clear') }}</VBtn>
            <VBtn color="primary" @click="applyFilters">{{ t('supplier_analytics.filters.apply') }}</VBtn>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- KPIs -->
    <VRow class="mb-2">
      <VCol v-for="card in kpiCards" :key="card.title" cols="12" sm="6" md="4" lg="2">
        <VCard elevation="0" border>
          <VCardText class="d-flex align-center gap-3">
            <VAvatar :color="card.color" variant="tonal" size="44" rounded>
              <VIcon :icon="card.icon" size="22" />
            </VAvatar>
            <div>
              <div class="text-h5">{{ card.value }}</div>
              <div class="text-caption text-medium-emphasis">{{ card.title }}</div>
              <div class="text-caption text-disabled">{{ card.sub }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Charts -->
    <VRow>
      <VCol cols="12" md="4">
        <VCard elevation="0" border>
          <VCardTitle class="text-subtitle-1">{{ t('supplier_analytics.charts.by_status') }}</VCardTitle>
          <VCardText>
            <VueApexCharts type="donut" :height="280" :options="statusOptionsChart" :series="statusSeries" />
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="4">
        <VCard elevation="0" border>
          <VCardTitle class="text-subtitle-1">{{ t('supplier_analytics.charts.by_segment') }}</VCardTitle>
          <VCardText>
            <VueApexCharts type="donut" :height="280" :options="segmentOptionsChart" :series="segmentSeries" />
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="4">
        <VCard elevation="0" border>
          <VCardTitle class="text-subtitle-1">{{ t('supplier_analytics.charts.risk_by_level') }}</VCardTitle>
          <VCardText>
            <VueApexCharts type="donut" :height="280" :options="riskOptionsChart" :series="riskSeries" />
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="6">
        <VCard elevation="0" border>
          <VCardTitle class="text-subtitle-1">{{ t('supplier_analytics.charts.onboarding_trend') }}</VCardTitle>
          <VCardText>
            <VueApexCharts type="line" :height="280" :options="onboardingOptionsChart" :series="onboardingSeries" />
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="6">
        <VCard elevation="0" border>
          <VCardTitle class="text-subtitle-1">{{ t('supplier_analytics.charts.top_spend') }}</VCardTitle>
          <VCardText>
            <VueApexCharts type="bar" :height="280" :options="spendOptionsChart" :series="spendSeries" />
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="6">
        <VCard elevation="0" border>
          <VCardTitle class="text-subtitle-1">{{ t('supplier_analytics.charts.doc_expiry') }}</VCardTitle>
          <VCardText>
            <VueApexCharts type="bar" :height="280" :options="bucketOptionsChart" :series="bucketSeries" />
          </VCardText>
        </VCard>
      </VCol>

      <!-- Top spend table -->
      <VCol cols="12" md="6">
        <VCard elevation="0" border>
          <VCardTitle class="text-subtitle-1">{{ t('supplier_analytics.charts.top_spend_detail') }}</VCardTitle>
          <VTable density="compact">
            <thead>
              <tr>
                <th class="text-left">{{ t('supplier_analytics.charts.top_spend') }}</th>
                <th class="text-right">{{ t('supplier_analytics.charts.top_spend') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in topSpend" :key="row.supplier">
                <td>{{ row.supplier }}</td>
                <td class="text-right">{{ fmtMoney(row.total) }}</td>
              </tr>
              <tr v-if="!topSpend.length">
                <td colspan="2" class="text-center text-medium-emphasis py-4">
                  {{ t('supplier_analytics.no_data') }}
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>
  </VContainer>
</template>
