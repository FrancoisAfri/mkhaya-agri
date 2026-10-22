<script lang="ts">
export type BalancePoint = { day: string; forecast: boolean; depletion: number; raw: number; taw: number; etc: number | null; et0: number | null; et0_source: string | null; kc: number | null; rain: number; irrigation: number; sensor: boolean }
</script>

<script setup lang="ts">
/**
 * A zone's root-zone water balance: depletion (mm) against the readily available water (the
 * irrigation threshold) and the total available water, with rain and irrigation as bars. Forecast
 * days are shaded. ApexCharts is loaded only when the chart is first shown.
 */
import { computed, defineAsyncComponent } from 'vue'
import { useI18n } from 'vue-i18n'

const props = withDefaults(defineProps<{ points: BalancePoint[]; height?: number }>(), { height: 260 })
const VueApexCharts = defineAsyncComponent(() => import('vue3-apexcharts'))
const { t } = useI18n()
const k = (key: string) => t(`agri.water.${key}`)
const ms = (d: string) => new Date(`${d}T00:00:00`).getTime()

const series = computed(() => [
  { name: k('rain'), type: 'column', data: props.points.map(p => ({ x: ms(p.day), y: p.rain })) },
  { name: k('irrigation'), type: 'column', data: props.points.map(p => ({ x: ms(p.day), y: p.irrigation })) },
  { name: k('depletion'), type: 'line', data: props.points.map(p => ({ x: ms(p.day), y: p.depletion })) },
  { name: k('threshold'), type: 'line', data: props.points.map(p => ({ x: ms(p.day), y: p.raw })) },
  { name: k('taw'), type: 'line', data: props.points.map(p => ({ x: ms(p.day), y: p.taw })) },
])
const firstForecast = computed(() => props.points.find(p => p.forecast)?.day ?? null)
const options = computed(() => ({
  chart: { type: 'line', stacked: false, toolbar: { show: false }, zoom: { enabled: false }, animations: { enabled: false } },
  colors: ['#42a5f5', '#26a69a', '#e65100', '#c62828', '#9e9e9e'],
  stroke: { curve: 'straight', width: [0, 0, 3, 2, 1], dashArray: [0, 0, 0, 6, 3] },
  markers: { size: [0, 0, 3, 0, 0] },
  dataLabels: { enabled: false },
  legend: { position: 'bottom' },
  xaxis: { type: 'datetime' },
  yaxis: { min: 0, decimalsInFloat: 0, title: { text: 'mm' } },
  tooltip: { shared: true, x: { format: 'dd MMM' } },
  annotations: firstForecast.value
    ? { xaxis: [{ x: ms(firstForecast.value), x2: ms(props.points[props.points.length - 1].day), fillColor: '#fff59d', opacity: 0.25, label: { text: k('forecast') } }] }
    : {},
}))
</script>

<template>
  <div v-if="points.length">
    <VueApexCharts type="line" :height="height" :options="options" :series="series" />
  </div>
  <div v-else class="text-body-2 text-medium-emphasis py-6 text-center">{{ k('no_points') }}</div>
</template>
