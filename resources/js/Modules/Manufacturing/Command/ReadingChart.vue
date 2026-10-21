<script setup lang="ts">
/**
 * A reading graph: one metric for a device or a unit, with its unit on the axis, the farm's time
 * zone on the time axis, the alarm limits drawn in, and a clear "stale" label when the source has
 * stopped reporting. Points measured late are drawn where they were measured.
 */
import { computed, onMounted, ref, watch } from 'vue'
import VueApexCharts from 'vue3-apexcharts'
import axios from 'axios'
import { ago } from '../Agriculture/prd'

const props = withDefaults(defineProps<{ device?: string | null; unit?: string | null; metric: string; hours?: number; height?: number; title?: string }>(),
  { hours: 24, height: 220 })

type Series = { metric: string; unit: string | null; timezone: string; points: Array<{ t: string; v: number; q?: string }>; bucketed: boolean
  stale: boolean; last_seen_at: string | null; thresholds: Array<{ min: number | null; max: number | null; severity: string }> }
const data = ref<Series | null>(null)
const loading = ref(false)
const failed = ref(false)
const hours = ref(props.hours)

async function load() {
  loading.value = true
  failed.value = false
  try {
    const r = await axios.get('/manufacturing/command/series', { params: { device: props.device, unit: props.unit, metric: props.metric, hours: hours.value } })
    data.value = r.data
  }
  catch { failed.value = true }
  finally { loading.value = false }
}
onMounted(load)
watch(() => [props.device, props.unit, props.metric, hours.value], load)
defineExpose({ load })

const series = computed(() => [{ name: `${props.metric} (${data.value?.unit ?? ''})`, data: (data.value?.points ?? []).map(p => ({ x: new Date(p.t).getTime(), y: p.v })) }])
const options = computed(() => {
  const lines: any[] = []
  for (const t of data.value?.thresholds ?? []) {
    const color = t.severity === 'critical' || t.severity === 'emergency' ? '#ea5455' : '#ff9f43'
    if (t.min !== null) lines.push({ y: t.min, borderColor: color, strokeDashArray: 4, label: { text: `min ${t.min}`, style: { background: color, color: '#fff' } } })
    if (t.max !== null) lines.push({ y: t.max, borderColor: color, strokeDashArray: 4, label: { text: `max ${t.max}`, style: { background: color, color: '#fff' } } })
  }
  return {
    chart: { id: `rc-${props.metric}`, toolbar: { show: false }, animations: { enabled: false }, zoom: { enabled: false } },
    stroke: { width: 2, curve: 'straight' }, dataLabels: { enabled: false }, markers: { size: 0 },
    xaxis: { type: 'datetime', labels: { datetimeUTC: false } },
    yaxis: { title: { text: data.value?.unit ?? '' }, decimalsInFloat: 1 },
    tooltip: { x: { format: 'dd MMM HH:mm' } },
    annotations: { yaxis: lines },
    colors: [data.value?.stale ? '#a8aaae' : '#7367f0'],
  }
})
</script>

<template>
  <div>
    <div class="d-flex align-center justify-space-between mb-1 flex-wrap gap-2">
      <div class="text-subtitle-2">{{ title ?? metric }} <span v-if="data?.unit" class="text-medium-emphasis">({{ data.unit }})</span></div>
      <div class="d-flex align-center gap-2">
        <VChip v-if="data?.stale" size="x-small" color="warning" label prepend-icon="tabler-clock-pause">Stale — last {{ ago(data?.last_seen_at) }}</VChip>
        <VChip v-if="data?.bucketed" size="x-small" label>averaged</VChip>
        <VBtnToggle v-model="hours" density="compact" variant="outlined" divided mandatory>
          <VBtn :value="6" size="x-small">6h</VBtn><VBtn :value="24" size="x-small">24h</VBtn><VBtn :value="168" size="x-small">7d</VBtn>
        </VBtnToggle>
      </div>
    </div>
    <VProgressLinear v-if="loading" indeterminate height="2" />
    <VAlert v-if="failed" type="warning" variant="tonal" density="compact">The readings could not be loaded.</VAlert>
    <div v-else-if="data && !data.points.length" class="text-caption text-medium-emphasis py-6 text-center">No readings in this period.</div>
    <VueApexCharts v-else-if="data" type="line" :height="height" :options="options" :series="series" />
    <div v-if="data" class="text-caption text-medium-emphasis">Times in {{ data.timezone }}.</div>
  </div>
</template>
