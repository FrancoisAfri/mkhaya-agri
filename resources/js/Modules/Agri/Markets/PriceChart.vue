<script setup lang="ts">
/**
 * Commodity price history: one line per market, price type and futures month. ApexCharts is
 * loaded only when the chart is first shown.
 */
import { computed, defineAsyncComponent } from 'vue'

type Series = { key: string; label: string; unit: string; currency: string; points: Array<{ date: string; price: number }> }
const props = withDefaults(defineProps<{ series: Series[]; height?: number }>(), { height: 320 })
const VueApexCharts = defineAsyncComponent(() => import('vue3-apexcharts'))
const ms = (d: string) => new Date(`${d}T00:00:00`).getTime()

const chartSeries = computed(() => props.series.map(s => ({ name: s.label, data: s.points.map(p => ({ x: ms(p.date), y: p.price })) })))
const options = computed(() => ({
  chart: { type: 'line', toolbar: { show: false }, zoom: { enabled: false }, animations: { enabled: false } },
  stroke: { curve: 'straight', width: 2 },
  markers: { size: props.series.some(s => s.points.length < 20) ? 3 : 0 },
  dataLabels: { enabled: false },
  legend: { position: 'bottom' },
  xaxis: { type: 'datetime' },
  yaxis: { decimalsInFloat: 0, title: { text: props.series[0] ? `${props.series[0].currency}/${props.series[0].unit}` : '' } },
  tooltip: { shared: true, x: { format: 'dd MMM yyyy' } },
}))
</script>

<template>
  <VueApexCharts v-if="series.length" type="line" :height="height" :options="options" :series="chartSeries" />
</template>
