<script lang="ts" setup>
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import VueApexCharts from 'vue3-apexcharts'
const route = ziggyRoute
const { t } = useI18n()

interface Drill { label: string; route: string; params: Record<string, any> }
interface Widget { key: string; title: string; value: number; format: string; drill: Drill; sub: string | null; hidden: boolean }
interface Slice { name: string; value: number }
const props = defineProps<{
  widgets: Widget[]
  charts: {
    monthly: { month: string; income: number; expense: number; net: number }[]
    expenses: Slice[]; income_accounts: Slice[]
    ar_ap: { buckets: string[]; receivables: number[]; payables: number[] }
  }
}>()

const money = (n: number) => 'R ' + (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const money0 = (n: number) => 'R ' + (n ?? 0).toLocaleString('en-ZA', { maximumFractionDigits: 0 })

// Chart configs
const trendSeries = computed(() => [
  { name: 'Income', data: props.charts.monthly.map(m => m.income) },
  { name: 'Expenses', data: props.charts.monthly.map(m => m.expense) },
  { name: 'Net', data: props.charts.monthly.map(m => m.net) },
])
const trendOptions = computed(() => ({ chart: { toolbar: { show: false } }, stroke: { curve: 'smooth', width: [2, 2, 3] }, xaxis: { categories: props.charts.monthly.map(m => m.month) }, colors: ['#2a9d8f', '#e5544b', '#5b46f2'], yaxis: { labels: { formatter: (v: number) => money0(v) } }, legend: { position: 'top' } }))
const expenseDonut = computed(() => ({ options: { labels: props.charts.expenses.map(s => s.name), legend: { position: 'bottom' }, colors: ['#e5544b', '#ef7a5a', '#f4a261', '#f7c59f', '#8d99ae', '#b7b7c9'], dataLabels: { formatter: (v: number) => v.toFixed(0) + '%' }, tooltip: { y: { formatter: (v: number) => money0(v) } }, stroke: { width: 0 } }, series: props.charts.expenses.map(s => s.value) }))
const arApSeries = computed(() => [{ name: 'Receivables', data: props.charts.ar_ap.receivables }, { name: 'Payables', data: props.charts.ar_ap.payables }])
const arApOptions = computed(() => ({ chart: { toolbar: { show: false } }, plotOptions: { bar: { borderRadius: 3, columnWidth: '60%' } }, xaxis: { categories: props.charts.ar_ap.buckets }, colors: ['#2a9d8f', '#e5544b'], yaxis: { labels: { formatter: (v: number) => money0(v) } }, legend: { position: 'top' } }))
const hasExp = computed(() => props.charts.expenses.length > 0)
const fmt = (w: Widget) => w.format === 'money' ? money(w.value) : (w.value ?? 0).toLocaleString('en-ZA')
const negative = (w: Widget) => w.value < 0

const visible = computed(() => props.widgets.filter(w => !w.hidden))
function drill(w: Widget) { router.visit(route(w.drill.route, w.drill.params)) }

// Customize layout
const dialog = ref(false)
const items = ref<{ key: string; title: string; hidden: boolean }[]>([])
function openCustomize() { items.value = props.widgets.map(w => ({ key: w.key, title: w.title, hidden: w.hidden })); dialog.value = true }
function move(i: number, dir: number) {
  const j = i + dir
  if (j < 0 || j >= items.value.length) return
  const arr = items.value
  ;[arr[i], arr[j]] = [arr[j], arr[i]]
}
const form = useForm<any>({ order: [], hidden: [] })
function saveLayout() {
  form.order = items.value.map(i => i.key)
  form.hidden = items.value.filter(i => i.hidden).map(i => i.key)
  form.post(route('finance.accounting.dashboard.layout'), { preserveScroll: true, onSuccess: () => { dialog.value = false } })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('acc.finance') }}</div>
        <h2 class="text-h5 mb-0">{{ t('acc.dashboard') }}</h2>
        <div class="text-caption text-medium-emphasis">{{ t('acc.dashboard_sub') }}</div>
      </div>
      <VBtn variant="tonal" prepend-icon="tabler-adjustments" @click="openCustomize">{{ t('acc.customize') }}</VBtn>
    </div>

    <VRow>
      <VCol v-for="w in visible" :key="w.key" cols="12" sm="6" md="4" lg="3">
        <VCard border class="h-100 d-flex flex-column" hover @click="drill(w)" style="cursor: pointer">
          <VCardText class="flex-grow-1">
            <div class="text-caption text-medium-emphasis text-truncate">{{ w.title }}</div>
            <div class="text-h5 mt-1" :class="negative(w) ? 'text-error' : ''">{{ fmt(w) }}</div>
            <div v-if="w.sub" class="text-caption text-medium-emphasis mt-1">{{ w.sub }}</div>
          </VCardText>
          <VCardActions class="px-4 pb-2 pt-0">
            <VBtn size="x-small" variant="text" color="primary" :append-icon="'tabler-arrow-right'" @click.stop="drill(w)">{{ w.drill.label }}</VBtn>
          </VCardActions>
        </VCard>
      </VCol>
      <VCol v-if="!visible.length" cols="12">
        <VCard border><VCardText class="text-center text-medium-emphasis py-8">{{ t('acc.widgets_hidden') }}</VCardText></VCard>
      </VCol>
    </VRow>

    <!-- Charts -->
    <VRow class="mt-1">
      <VCol cols="12" lg="8">
        <VCard border><VCardTitle class="text-subtitle-1">{{ t('acc.trend_title') }}</VCardTitle><VCardText>
          <VueApexCharts type="line" :height="300" :options="trendOptions" :series="trendSeries" />
        </VCardText></VCard>
      </VCol>
      <VCol cols="12" lg="4">
        <VCard border class="h-100"><VCardTitle class="text-subtitle-1">{{ t('acc.expenses_by_category') }}</VCardTitle><VCardText>
          <VueApexCharts v-if="hasExp" type="donut" :height="300" :options="expenseDonut.options" :series="expenseDonut.series" />
          <div v-else class="text-center text-medium-emphasis py-12">{{ t('acc.no_expenses') }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="12">
        <VCard border><VCardTitle class="text-subtitle-1 d-flex align-center">{{ t('acc.ar_ap_title') }}<VSpacer />
          <VBtn size="small" variant="text" color="primary" append-icon="tabler-arrow-right" @click="router.visit(route('finance.accounting.statements', { tab: 'ar_aging' }))">{{ t('acc.aging_report') }}</VBtn>
        </VCardTitle><VCardText>
          <VueApexCharts type="bar" :height="280" :options="arApOptions" :series="arApSeries" />
        </VCardText></VCard>
      </VCol>
    </VRow>

    <!-- Customize dialog -->
    <VDialog v-model="dialog" max-width="520">
      <VCard>
        <VCardTitle>{{ t('acc.customize_dashboard') }}</VCardTitle>
        <VCardText>
          <div class="text-caption text-medium-emphasis mb-2">{{ t('acc.toggle_widgets') }}</div>
          <VList density="compact" class="border rounded">
            <VListItem v-for="(it, i) in items" :key="it.key">
              <template #prepend>
                <VBtn size="x-small" variant="text" icon="tabler-chevron-up" :disabled="i === 0" @click="move(i, -1)" />
                <VBtn size="x-small" variant="text" icon="tabler-chevron-down" :disabled="i === items.length - 1" @click="move(i, 1)" />
              </template>
              <VListItemTitle class="text-body-2">{{ it.title }}</VListItemTitle>
              <template #append>
                <VSwitch v-model="it.hidden" :true-value="false" :false-value="true" color="primary" hide-details density="compact" />
              </template>
            </VListItem>
          </VList>
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('acc.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" @click="saveLayout">{{ t('acc.save_layout') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
