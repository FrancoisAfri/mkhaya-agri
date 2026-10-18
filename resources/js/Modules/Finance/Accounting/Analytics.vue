<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import VueApexCharts from 'vue3-apexcharts'
const route = ziggyRoute
const { t } = useI18n()

interface Slice { name: string; value: number }
interface MonthRow { month: string; income: number; expense: number; net: number }
const props = defineProps<{
  data: {
    period: { from: string; to: string }
    income_by_account: Slice[]; expenses_by_category: Slice[]
    income_by_customer: Slice[]; top_suppliers: Slice[]
    monthly: MonthRow[]
    expense_monthly: { months: string[]; series: { name: string; data: number[] }[] }
    ar_ap: { buckets: string[]; receivables: number[]; payables: number[] }
  }
  range: { from: string; to: string }
}>()

const money = (n: number) => 'R ' + (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 0, maximumFractionDigits: 0 })
const rng = ref({ ...props.range })
function apply() { router.get(route('finance.accounting.analytics'), { from: rng.value.from, to: rng.value.to }, { preserveState: false }) }
const year = computed(() => new Date(props.range.to).getFullYear())

const donut = (slices: Slice[], colors: string[]) => ({
  options: { labels: slices.map(s => s.name), legend: { position: 'bottom' }, colors, dataLabels: { formatter: (v: number) => v.toFixed(0) + '%' }, tooltip: { y: { formatter: (v: number) => money(v) } }, stroke: { width: 0 } },
  series: slices.map(s => s.value),
})
const incomeDonut = computed(() => donut(props.data.income_by_account, ['#5b46f2', '#7c66ff', '#9d8bff', '#c0b3ff', '#d9d0ff']))
const expenseDonut = computed(() => donut(props.data.expenses_by_category, ['#e5544b', '#ef7a5a', '#f4a261', '#f7c59f', '#8d99ae', '#b7b7c9']))

const hBar = (slices: Slice[], color: string) => ({
  options: { chart: { toolbar: { show: false } }, plotOptions: { bar: { horizontal: true, borderRadius: 3 } }, xaxis: { categories: slices.map(c => c.name), labels: { formatter: (v: number) => money(v) } }, colors: [color], dataLabels: { enabled: false } },
  series: [{ name: t('acc.amount_series'), data: slices.map(c => c.value) }],
})
const customers = computed(() => hBar(props.data.income_by_customer, '#5b46f2'))
const suppliers = computed(() => hBar(props.data.top_suppliers, '#e5544b'))

const trendSeries = computed(() => [
  { name: t('acc.income'), data: props.data.monthly.map(m => m.income) },
  { name: t('acc.expenses_word'), data: props.data.monthly.map(m => m.expense) },
  { name: t('acc.net'), data: props.data.monthly.map(m => m.net) },
])
const trendOptions = computed(() => ({ chart: { toolbar: { show: false } }, stroke: { curve: 'smooth', width: [2, 2, 3] }, xaxis: { categories: props.data.monthly.map(m => m.month) }, colors: ['#2a9d8f', '#e5544b', '#5b46f2'], yaxis: { labels: { formatter: (v: number) => money(v) } }, legend: { position: 'top' } }))

// Cumulative net profit (running sum of monthly net)
const cumulativeSeries = computed(() => { let run = 0; return [{ name: t('acc.cumulative_net_series'), data: props.data.monthly.map(m => (run = Math.round((run + m.net) * 100) / 100)) }] })
const cumulativeOptions = computed(() => ({ chart: { toolbar: { show: false } }, stroke: { curve: 'smooth', width: 3 }, fill: { type: 'gradient', gradient: { opacityFrom: 0.4, opacityTo: 0.05 } }, xaxis: { categories: props.data.monthly.map(m => m.month) }, colors: ['#5b46f2'], yaxis: { labels: { formatter: (v: number) => money(v) } } }))

// AR vs AP by ageing bucket
const arApSeries = computed(() => [{ name: t('acc.receivables'), data: props.data.ar_ap.receivables }, { name: t('acc.payables'), data: props.data.ar_ap.payables }])
const arApOptions = computed(() => ({ chart: { toolbar: { show: false } }, plotOptions: { bar: { borderRadius: 3, columnWidth: '60%' } }, xaxis: { categories: props.data.ar_ap.buckets }, colors: ['#2a9d8f', '#e5544b'], yaxis: { labels: { formatter: (v: number) => money(v) } }, legend: { position: 'top' } }))

// Expense mix by month (stacked)
const expOptions = computed(() => ({ chart: { stacked: true, toolbar: { show: false } }, plotOptions: { bar: { borderRadius: 2, columnWidth: '65%' } }, xaxis: { categories: props.data.expense_monthly.months }, yaxis: { labels: { formatter: (v: number) => money(v) } }, legend: { position: 'top' }, colors: ['#e5544b', '#ef7a5a', '#f4a261', '#f7c59f', '#8d99ae'] }))

const has = (a: any[]) => a && a.length > 0
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('acc.finance') }} · {{ t('acc.crumb_bi') }}</div>
        <h2 class="text-h5 mb-0">{{ t('acc.analytics_word') }}</h2>
        <div class="text-caption text-medium-emphasis">{{ t('acc.analytics_sub') }}</div>
      </div>
      <div class="d-flex gap-2 align-center">
        <VTextField v-model="rng.from" type="date" density="compact" variant="outlined" hide-details style="max-width: 160px" />
        <VTextField v-model="rng.to" type="date" density="compact" variant="outlined" hide-details style="max-width: 160px" />
        <VBtn color="primary" variant="tonal" @click="apply">{{ t('acc.apply_word') }}</VBtn>
      </div>
    </div>

    <VRow>
      <VCol cols="12" md="6" lg="4">
        <VCard border class="h-100"><VCardTitle class="text-subtitle-1">{{ t('acc.income_by_account') }}</VCardTitle><VCardText>
          <VueApexCharts v-if="has(data.income_by_account)" type="donut" :height="290" :options="incomeDonut.options" :series="incomeDonut.series" />
          <div v-else class="text-center text-medium-emphasis py-12">{{ t('acc.no_income_period') }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="12" md="6" lg="4">
        <VCard border class="h-100"><VCardTitle class="text-subtitle-1">{{ t('acc.expenses_by_category') }}</VCardTitle><VCardText>
          <VueApexCharts v-if="has(data.expenses_by_category)" type="donut" :height="290" :options="expenseDonut.options" :series="expenseDonut.series" />
          <div v-else class="text-center text-medium-emphasis py-12">{{ t('acc.no_expenses_period') }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="12" lg="4">
        <VCard border class="h-100"><VCardTitle class="text-subtitle-1">{{ t('acc.receivables_vs_payables') }}</VCardTitle><VCardText>
          <VueApexCharts type="bar" :height="290" :options="arApOptions" :series="arApSeries" />
        </VCardText></VCard>
      </VCol>

      <VCol cols="12" lg="8">
        <VCard border><VCardTitle class="text-subtitle-1">{{ t('acc.monthly_ien') }} · {{ year }}</VCardTitle><VCardText>
          <VueApexCharts type="line" :height="320" :options="trendOptions" :series="trendSeries" />
        </VCardText></VCard>
      </VCol>
      <VCol cols="12" lg="4">
        <VCard border class="h-100"><VCardTitle class="text-subtitle-1">{{ t('acc.cumulative_net_profit') }} · {{ year }}</VCardTitle><VCardText>
          <VueApexCharts type="area" :height="320" :options="cumulativeOptions" :series="cumulativeSeries" />
        </VCardText></VCard>
      </VCol>

      <VCol cols="12" lg="6">
        <VCard border class="h-100"><VCardTitle class="text-subtitle-1">{{ t('acc.top_customers') }}</VCardTitle><VCardText>
          <VueApexCharts v-if="has(data.income_by_customer)" type="bar" :height="300" :options="customers.options" :series="customers.series" />
          <div v-else class="text-center text-medium-emphasis py-12">{{ t('acc.no_invoiced_revenue') }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="12" lg="6">
        <VCard border class="h-100"><VCardTitle class="text-subtitle-1">{{ t('acc.top_suppliers') }}</VCardTitle><VCardText>
          <VueApexCharts v-if="has(data.top_suppliers)" type="bar" :height="300" :options="suppliers.options" :series="suppliers.series" />
          <div v-else class="text-center text-medium-emphasis py-12">{{ t('acc.no_supplier_bills') }}</div>
        </VCardText></VCard>
      </VCol>

      <VCol cols="12">
        <VCard border><VCardTitle class="text-subtitle-1">{{ t('acc.expense_mix_by_month') }} · {{ year }}</VCardTitle><VCardText>
          <VueApexCharts v-if="has(data.expense_monthly.series)" type="bar" :height="320" :options="expOptions" :series="data.expense_monthly.series" />
          <div v-else class="text-center text-medium-emphasis py-12">{{ t('acc.no_expense_data_year') }}</div>
        </VCardText></VCard>
      </VCol>
    </VRow>
  </div>
</template>
