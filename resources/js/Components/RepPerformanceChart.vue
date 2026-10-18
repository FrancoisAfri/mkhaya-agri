<script lang="ts" setup>
import { ref, computed } from 'vue'
import VueApexCharts from 'vue3-apexcharts'
import type { ApexOptions } from 'apexcharts'

interface Series { categories: string[]; reps: { name: string; data: number[] }[] }

const props = withDefaults(defineProps<{
  monthly: Series
  weekly: Series
  title?: string
}>(), { title: 'Sales rep performance (won revenue)' })

const mode = ref<'monthly' | 'weekly'>('monthly')
const stacked = ref(false)

const active = computed(() => (mode.value === 'monthly' ? props.monthly : props.weekly))
const hasData = computed(() => active.value.reps.length > 0)

const money = (n: number) => 'R ' + (n ?? 0).toLocaleString('en-ZA', { maximumFractionDigits: 0 })

const series = computed(() => active.value.reps.map(r => ({ name: r.name, data: r.data })))
const options = computed<ApexOptions>(() => ({
  chart: { type: 'bar', stacked: stacked.value, toolbar: { show: false } },
  plotOptions: { bar: { borderRadius: 3, columnWidth: '70%' } },
  dataLabels: { enabled: false },
  xaxis: { categories: active.value.categories },
  yaxis: { labels: { formatter: (v: number) => money(v) } },
  legend: { position: 'bottom' },
  tooltip: { y: { formatter: (v: number) => money(v) } },
  grid: { borderColor: 'rgba(var(--v-border-color), 0.08)' },
}))
</script>

<template>
  <VCard>
    <VCardTitle class="d-flex flex-wrap align-center justify-space-between ga-2">
      <span class="text-body-1 font-weight-bold">{{ title }}</span>
      <div class="d-flex align-center ga-2">
        <VBtnToggle v-model="mode" density="compact" variant="outlined" mandatory divided>
          <VBtn value="monthly" size="small">Monthly</VBtn>
          <VBtn value="weekly" size="small">Weekly</VBtn>
        </VBtnToggle>
        <VBtn :variant="stacked ? 'flat' : 'tonal'" :color="stacked ? 'primary' : undefined" size="small" icon="tabler-chart-bar" @click="stacked = !stacked" />
      </div>
    </VCardTitle>
    <VCardText>
      <VueApexCharts v-if="hasData" type="bar" :height="360" :options="options" :series="series" />
      <div v-else class="text-medium-emphasis text-body-2 py-8 text-center">
        No won-revenue data yet for the {{ mode }} view.
      </div>
    </VCardText>
  </VCard>
</template>
