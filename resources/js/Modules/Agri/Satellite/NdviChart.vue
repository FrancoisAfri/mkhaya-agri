<script lang="ts">
export type NdviPoint = { id: string; date: string; mean: number; min: number | null; max: number | null; cloud_pct: number | null; change_pct: number | null; source: string; image: boolean }
export type NdviZone = { id: string; name: string; zone_class: string | null; points: Array<{ date: string; value: number }> }
export type NdviSeries = { field: { id: string; code: string; name: string }; points: NdviPoint[]; zones: NdviZone[] }
</script>

<script setup lang="ts">
/**
 * A field's NDVI over time: the mean as a line inside its min–max band, and one dashed line per
 * management zone. ApexCharts is loaded only when a chart is first shown.
 */
import { computed, defineAsyncComponent } from 'vue'
import { useI18n } from 'vue-i18n'

const props = withDefaults(defineProps<{ series: NdviSeries; height?: number }>(), { height: 280 })
const VueApexCharts = defineAsyncComponent(() => import('vue3-apexcharts'))
const { t } = useI18n()
const k = (key: string) => t(`agri.satellite.${key}`)

const ZONE_COLOURS = ['#8e24aa', '#f4511e', '#3949ab', '#00897b', '#6d4c41', '#c0ca33', '#d81b60']
const ms = (d: string) => new Date(`${d}T00:00:00`).getTime()

const chartSeries = computed(() => [
  { name: k('band'), type: 'rangeArea', data: props.series.points.filter(p => p.min !== null && p.max !== null).map(p => ({ x: ms(p.date), y: [p.min, p.max] })) },
  { name: k('mean'), type: 'line', data: props.series.points.map(p => ({ x: ms(p.date), y: p.mean })) },
  ...props.series.zones.map(z => ({ name: z.name, type: 'line', data: z.points.map(p => ({ x: ms(p.date), y: p.value })) })),
])
const options = computed(() => {
  const zones = props.series.zones.length
  return {
    chart: { type: 'rangeArea', toolbar: { show: false }, zoom: { enabled: false }, animations: { enabled: false } },
    colors: ['#a5d6a7', '#2e7d32', ...props.series.zones.map((_, i) => ZONE_COLOURS[i % ZONE_COLOURS.length])],
    fill: { opacity: [0.35, 1, ...Array(zones).fill(1)] },
    stroke: { curve: 'straight', width: [0, 3, ...Array(zones).fill(2)], dashArray: [0, 0, ...Array(zones).fill(5)] },
    markers: { size: [0, 4, ...Array(zones).fill(2)] },
    dataLabels: { enabled: false },
    legend: { position: 'bottom', show: true },
    xaxis: { type: 'datetime' },
    yaxis: { min: 0, max: 1, tickAmount: 5, decimalsInFloat: 2, title: { text: 'NDVI' } },
    tooltip: { shared: true, x: { format: 'dd MMM yyyy' } },
  }
})
</script>

<template>
  <div v-if="series.points.length">
    <VueApexCharts type="rangeArea" :height="height" :options="options" :series="chartSeries" />
  </div>
  <div v-else class="text-body-2 text-medium-emphasis py-6 text-center">{{ k('no_series') }}</div>
</template>
