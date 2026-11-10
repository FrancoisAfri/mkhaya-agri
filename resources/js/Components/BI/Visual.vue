<script setup lang="ts">
// One BI visual (copied from Mkhaya Sales and made generic): fetches its numbers from the shared
// measure engine and draws them. A visual whose data belongs to a module the company does not
// subscribe to — or the person may not see — shows why instead of numbers.
import axios from 'axios'
import * as echarts from 'echarts'
import VChart from 'vue-echarts'
import { computed, onMounted, ref, watch } from 'vue'
import { buildOption, fmt, GOOD, AMBER, BAD, visual } from './visuals'

const props = defineProps<{ widget: any; filters: Record<string, any>; editing?: boolean; dashboardName?: string }>()
const emit = defineEmits<{ filter: [{ dimension: string; key: any; label: string }]; edit: []; remove: []; duplicate: [] }>()
void echarts

const v = computed(() => visual(props.widget.type))
const data = ref<any>(null)
const loading = ref(false)
const error = ref('')
const chart = ref<any>(null)
const ai = ref('')
const aiBusy = ref(false)
const special = computed(() => v.value.special)

async function load() {
  if (special.value === 'text' || v.value.kind === 'none') return
  loading.value = true; error.value = ''
  try {
    const defaults = Object.fromEntries(Object.entries(v.value.defaults ?? {}).filter(([, x]) => x !== 'auto'))
    const config = { ...defaults, ...(props.widget.config ?? {}), kind: v.value.kind }
    data.value = (await axios.post('/bi/query', { config, filters: props.filters })).data
  } catch (e: any) { error.value = e.response?.data?.message ?? 'Could not load this visual.' } finally { loading.value = false }
}
onMounted(load)
watch(() => [props.filters, props.widget.config, props.widget.type], load, { deep: true })

const locked = computed(() => data.value?.locked)
const option = computed(() => data.value && !locked.value && !['kpi', 'table', 'narrative', 'none'].includes(v.value.kind) && !['matrix_table', 'variance_table', 'ranking_table'].includes(props.widget.type) ? buildOption(props.widget.type, data.value) : null)
const isEmpty = computed(() => {
  const d = data.value
  if (!d) return false
  if (d.series) return !d.series.some((s: any) => s.data?.some((x: any) => x))
  if (d.tree) return !d.tree.length
  if (d.cells) return !d.cells.length
  if (d.steps) return !d.steps.length && !d.start?.value && !d.end?.value
  return false
})

// ── KPI helpers ──
const vals = computed(() => data.value?.values ?? {})
const m0 = computed(() => props.widget.config?.measures?.[0])
const m1 = computed(() => props.widget.config?.measures?.[1])
const f0 = computed(() => data.value?.formats?.[m0.value] ?? 'number')
const pct = computed(() => Number(vals.value[m0.value] ?? 0))
const status = (p: number) => (p >= 90 ? GOOD : p >= 70 ? AMBER : BAD)
const statusText = (p: number) => (p >= 90 ? 'On track' : p >= 70 ? 'Needs attention' : 'Off track')
const sparkOption = computed(() => ({ grid: { left: 0, right: 0, top: 4, bottom: 0 }, xAxis: { type: 'category', show: false }, yAxis: { type: 'value', show: false, scale: true },
  series: [{ type: 'line', smooth: true, symbol: 'none', data: data.value?.spark ?? [], lineStyle: { width: 2, color: '#6A0DAD' }, areaStyle: { color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [{ offset: 0, color: 'rgba(106,13,173,.25)' }, { offset: 1, color: 'rgba(106,13,173,0)' }]) } }] }))
const gaugeOption = computed(() => ({ series: [{ type: 'gauge', startAngle: 210, endAngle: -30, min: 0, max: Math.max(100, pct.value), radius: '96%', center: ['50%', '62%'], progress: { show: true, width: 9, roundCap: true, itemStyle: { color: status(pct.value) } },
  axisLine: { lineStyle: { width: 9, color: [[1, '#F1EDF7']] } }, axisTick: { show: false }, splitLine: { show: false }, axisLabel: { show: false }, pointer: { show: false }, anchor: { show: false }, title: { show: false },
  detail: { valueAnimation: true, offsetCenter: [0, '8%'], fontSize: 18, fontWeight: 700, color: '#1F1F29', formatter: (x: number) => x.toFixed(1) + '%' }, data: [{ value: +pct.value.toFixed(1), name: data.value?.labels?.[m0.value] ?? '' }] }] }))

// ── Interactions: click a bar / slice to filter the whole dashboard by it ──
function onClick(p: any) {
  const d = data.value
  const dim = d?.dimension
  if (!dim || !d?.keys) return
  const flipped = props.widget.type.startsWith('bar') || props.widget.type === 'lollipop' || props.widget.type === 'diverging_bar'
  const key = d.keys[p.dataIndex !== undefined ? (flipped ? d.keys.length - 1 - p.dataIndex : p.dataIndex) : -1]
  if (key !== undefined && key !== 'other' && key !== null) emit('filter', { dimension: dim, key, label: p.name })
}
function exportData(format: 'csv' | 'xlsx') {
  const w = { type: props.widget.type, title: props.widget.title, config: props.widget.config ?? {} }
  window.location.href = `/bi/export/visual?format=${format}&widget=${encodeURIComponent(JSON.stringify(w))}&filters=${encodeURIComponent(JSON.stringify(props.filters ?? {}))}`
}
function exportPng() {
  const url = chart.value?.getDataURL?.({ pixelRatio: 2, backgroundColor: '#fff' })
  if (!url) return
  const a = document.createElement('a'); a.href = url; a.download = (props.widget.title || 'chart') + '.png'; a.click()
}
async function askAi() {
  aiBusy.value = true
  const facts = (data.value?.lines ?? []).join(' ')
  try { ai.value = (await axios.post('/ai-copilot/chat', { message: `This is the "${props.dashboardName ?? 'dashboard'}" dashboard. The figures: ${facts} In five short bullet points, what should management know and do? Separate facts from recommendations.` })).data.message?.content ?? '' } catch { ai.value = 'The AI assistant is not available right now.' } finally { aiBusy.value = false }
}

// ── Tables ──
const tableRows = computed(() => {
  const d = data.value
  if (!d?.series) return []
  return d.categories.map((c: string, i: number) => ({ label: c, key: d.keys?.[i], values: d.series.map((s: any) => s.data[i]) }))
})
const tableSort = ref<{ i: number; dir: 1 | -1 } | null>(null)
const sortedRows = computed(() => !tableSort.value ? tableRows.value : [...tableRows.value].sort((a, b) => ((a.values[tableSort.value!.i] ?? -Infinity) - (b.values[tableSort.value!.i] ?? -Infinity)) * tableSort.value!.dir))
const heat = (val: number, max: number) => `rgba(106,13,173,${Math.min(0.85, 0.08 + (max ? val / max : 0) * 0.77)})`
const cell = (ci: number, ri: number) => (data.value.cells.find((x: any) => x[0] === ci && x[1] === ri) ?? [0, 0, 0])[2]
</script>

<template>
  <div class="mkbi-card">
    <div class="mkbi-card__head">
      <div class="mkbi-card__title" :title="widget.title">{{ widget.title || v.name }}</div>
      <div class="mkbi-card__actions">
        <VProgressCircular v-if="loading" indeterminate size="14" width="2" color="primary" class="me-1" />
        <template v-if="editing">
          <VBtn icon="tabler-settings" size="x-small" variant="text" @click="emit('edit')" />
          <VBtn icon="tabler-copy" size="x-small" variant="text" @click="emit('duplicate')" />
          <VBtn icon="tabler-trash" size="x-small" variant="text" color="error" @click="emit('remove')" />
        </template>
        <VMenu v-else location="bottom end">
          <template #activator="{ props: p }"><VBtn v-bind="p" icon="tabler-dots" size="x-small" variant="text" class="mkbi-card__menu" /></template>
          <VList density="compact">
            <VListItem v-if="option" prepend-icon="tabler-photo-down" title="Download PNG" @click="exportPng" />
            <template v-if="v.kind !== 'none' && !data?.locked">
              <VListItem prepend-icon="tabler-file-spreadsheet" title="Export to Excel" @click="exportData('xlsx')" />
              <VListItem prepend-icon="tabler-file-text" title="Export to CSV" @click="exportData('csv')" />
            </template>
            <VListItem prepend-icon="tabler-refresh" title="Refresh" @click="load" />
          </VList>
        </VMenu>
      </div>
    </div>

    <div class="mkbi-card__body">
      <div v-if="error" class="mkbi-empty"><VIcon icon="tabler-alert-circle" color="error" />{{ error }}</div>
      <div v-else-if="special === 'text'" class="mkbi-text">{{ widget.config?.text || 'Add your text in the visual settings.' }}</div>
      <div v-else-if="!data && loading" class="mkbi-skeleton" />
      <div v-else-if="locked" class="mkbi-empty mkbi-locked">
        <VIcon icon="tabler-lock" size="26" />
        <strong>{{ locked === 'subscription' ? 'Not in your subscription' : locked === 'permission' ? 'You do not have access to this data' : 'Not available' }}</strong>
        <span>{{ locked === 'subscription' ? 'This visual uses a module your company has not subscribed to.' : locked === 'permission' ? 'Ask an administrator for access to the module behind it.' : '' }}</span>
      </div>
      <template v-else-if="data">
        <!-- KPI family -->
        <template v-if="v.kind === 'kpi'">
          <div v-if="widget.type === 'gauge'" class="mkbi-gauge"><VChart :option="gaugeOption" autoresize /></div>
          <div v-else-if="['linear_gauge', 'progress'].includes(widget.type)" class="mkbi-kpi">
            <div class="mkbi-kpi__value">{{ fmt(pct, 'percent') }}</div>
            <div class="mkbi-bar"><span :style="{ width: Math.min(100, pct) + '%', background: status(pct) }" /></div>
            <div class="mkbi-kpi__note">{{ data.labels?.[m0] }} · {{ statusText(pct) }}</div>
          </div>
          <div v-else-if="widget.type === 'traffic_light'" class="mkbi-kpi mkbi-kpi--center">
            <div class="mkbi-light"><span v-for="c in [BAD, AMBER, GOOD]" :key="c" :style="{ background: status(pct) === c ? c : '#ECECF1' }" /></div>
            <div class="mkbi-kpi__note mt-2"><strong>{{ fmt(pct, 'percent') }}</strong> · {{ statusText(pct) }}</div>
          </div>
          <div v-else-if="widget.type === 'variance_card'" class="mkbi-kpi">
            <div class="mkbi-kpi__value" :style="{ color: Number(vals[m0] ?? 0) >= 0 ? GOOD : BAD }">{{ Number(vals[m0] ?? 0) >= 0 && f0 === 'percent' ? '+' : '' }}{{ fmt(vals[m0], f0) }}</div>
            <div class="mkbi-kpi__note">{{ m1 ? `${data.labels?.[m1]}: ${fmt(vals[m1], data.formats?.[m1])}` : data.labels?.[m0] }}</div>
          </div>
          <div v-else-if="widget.type === 'multi_kpi'" class="mkbi-multi">
            <div v-for="m in widget.config?.measures ?? []" :key="m"><small>{{ data.labels?.[m] }}</small><strong>{{ fmt(vals[m], data.formats?.[m]) }}</strong></div>
          </div>
          <div v-else class="mkbi-kpi">
            <div class="mkbi-kpi__value">{{ fmt(vals[m0], f0) }}</div>
            <div v-if="m1" class="mkbi-kpi__note">
              <span v-if="m1 === 'growth_pct'" :style="{ color: (vals.growth_pct ?? 0) >= 0 ? GOOD : BAD }"><VIcon :icon="(vals.growth_pct ?? 0) >= 0 ? 'tabler-arrow-up-right' : 'tabler-arrow-down-right'" size="14" />{{ vals.growth_pct === null || vals.growth_pct === undefined ? 'no figures last year' : fmt(Math.abs(vals.growth_pct), 'percent') + ' vs last year' }}</span>
              <span v-else>{{ data.labels?.[m1] }}: {{ fmt(vals[m1], data.formats?.[m1]) }}</span>
            </div>
            <div v-if="widget.type === 'kpi_sparkline'" class="mkbi-spark"><VChart :option="sparkOption" autoresize /></div>
          </div>
        </template>

        <!-- Text -->
        <div v-else-if="special === 'ai'" class="mkbi-text">
          <VChip size="x-small" color="warning" variant="tonal" class="mb-2">AI-generated · review before acting</VChip>
          <div v-if="ai" style="white-space: pre-wrap">{{ ai }}</div>
          <template v-else>
            <ul class="mkbi-narrative mb-3"><li v-for="l in data.lines" :key="l">{{ l }}</li></ul>
            <VBtn size="small" color="primary" variant="tonal" prepend-icon="tabler-sparkles" :loading="aiBusy" @click="askAi">Ask AI what it means</VBtn>
          </template>
        </div>
        <ul v-else-if="v.kind === 'narrative'" class="mkbi-narrative"><li v-for="l in data.lines" :key="l">{{ l }}</li></ul>

        <!-- Tables -->
        <div v-else-if="widget.type === 'matrix_table'" class="mkbi-scroll">
          <table class="mkbi-table mkbi-table--matrix"><thead><tr><th /><th v-for="c in data.columns" :key="c" class="r">{{ c }}</th></tr></thead>
            <tbody><tr v-for="(r, ri) in data.rows" :key="r"><td>{{ r }}</td><td v-for="(c, ci) in data.columns" :key="c" class="r" :style="{ background: heat(cell(ci, ri), data.max), color: cell(ci, ri) / (data.max || 1) > .55 ? '#fff' : '#1F1F29' }">{{ fmt(cell(ci, ri), data.formats?.[widget.config?.measures?.[0]]) }}</td></tr></tbody></table>
        </div>
        <div v-else-if="['table', 'ranking_table', 'variance_table'].includes(widget.type)" class="mkbi-scroll">
          <table class="mkbi-table"><thead><tr><th>{{ data.dimension ? data.dimension.replaceAll('_', ' ') : 'Period' }}</th>
            <th v-for="(s, i) in data.series" :key="s.key" class="r sortable" @click="tableSort = { i, dir: tableSort?.i === i && tableSort.dir === -1 ? 1 : -1 }">{{ s.name }} <VIcon v-if="tableSort?.i === i" :icon="tableSort.dir === -1 ? 'tabler-chevron-down' : 'tabler-chevron-up'" size="12" /></th></tr></thead>
            <tbody><tr v-for="(r, i) in sortedRows" :key="r.label + i" :class="{ clickable: data.dimension }" @click="onClick({ dataIndex: tableRows.indexOf(r), name: r.label })">
              <td><span v-if="widget.type === 'ranking_table'" class="mkbi-rank">{{ i + 1 }}</span>{{ r.label }}</td>
              <td v-for="(val, j) in r.values" :key="j" class="r" :style="{ color: data.series[j].measure === 'growth_pct' ? (val ?? 0) >= 0 ? GOOD : BAD : undefined }">{{ fmt(val, data.formats?.[data.series[j].measure], false) }}</td></tr></tbody>
            <tfoot v-if="data.total !== undefined"><tr><td>Total ({{ data.count }})</td><td class="r">{{ fmt(data.total, data.formats?.[data.series[0]?.measure], false) }}</td><td :colspan="Math.max(0, data.series.length - 1)" /></tr></tfoot></table>
        </div>

        <!-- Charts -->
        <div v-else-if="isEmpty" class="mkbi-empty"><VIcon icon="tabler-chart-dots" size="28" />No data for these filters.</div>
        <VChart v-else-if="option" ref="chart" class="mkbi-fill" :option="option" autoresize @click="onClick" />
      </template>
    </div>
  </div>
</template>

<style scoped>
.mkbi-card { height: 100%; display: flex; flex-direction: column; background: #fff; border: 1px solid #EEEEF3; border-radius: 18px; box-shadow: 0 1px 2px rgba(17,17,26,.03); overflow: hidden; transition: box-shadow .2s; }
.mkbi-card:hover { box-shadow: 0 10px 30px rgba(17,17,26,.06); }
.mkbi-card__head { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px 4px 18px; min-height: 40px; }
.mkbi-card__title { font-size: 13px; font-weight: 600; color: #3A3A4A; letter-spacing: .1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mkbi-card__actions { display: flex; align-items: center; }
.mkbi-card__menu { opacity: 0; transition: opacity .15s; }
.mkbi-card:hover .mkbi-card__menu { opacity: 1; }
.mkbi-card__body { flex: 1; min-height: 0; padding: 4px 14px 14px; position: relative; }
.mkbi-fill { width: 100%; height: 100%; }
.mkbi-kpi { display: flex; flex-direction: column; justify-content: center; height: 100%; padding: 0 4px; }
.mkbi-kpi--center { align-items: center; }
.mkbi-kpi__value { font-size: 28px; font-weight: 700; color: #16161F; letter-spacing: -.5px; line-height: 1.15; }
.mkbi-kpi__note { font-size: 12px; color: #77778A; margin-top: 6px; display: flex; align-items: center; gap: 2px; flex-wrap: wrap; }
.mkbi-spark { height: 38px; margin-top: 6px; }
.mkbi-multi { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 16px; height: 100%; align-content: center; }
.mkbi-multi small { display: block; color: #8A8A99; font-size: 11px; } .mkbi-multi strong { font-size: 18px; color: #16161F; }
.mkbi-bar { position: relative; height: 10px; border-radius: 99px; background: #F1EDF7; margin-top: 10px; overflow: hidden; }
.mkbi-bar span { position: absolute; inset: 0 auto 0 0; border-radius: 99px; transition: width .6s; display: block; }
.mkbi-gauge { width: 100%; height: 100%; min-height: 110px; }
.mkbi-light { display: flex; gap: 8px; } .mkbi-light span { width: 26px; height: 26px; border-radius: 50%; transition: background .3s; }
.mkbi-narrative { margin: 0; padding-left: 18px; font-size: 13px; color: #3A3A4A; line-height: 1.6; } .mkbi-narrative li { margin-bottom: 4px; }
.mkbi-text { font-size: 13px; color: #3A3A4A; line-height: 1.6; overflow: auto; height: 100%; }
.mkbi-scroll { height: 100%; overflow: auto; }
.mkbi-table { width: 100%; border-collapse: collapse; font-size: 12px; color: #2B2B38; }
.mkbi-table th { position: sticky; top: 0; background: #fff; text-align: left; font-weight: 600; color: #77778A; padding: 6px 8px; border-bottom: 1px solid #EEEEF3; white-space: nowrap; text-transform: capitalize; }
.mkbi-table td { padding: 6px 8px; border-bottom: 1px solid #F5F5F8; white-space: nowrap; max-width: 240px; overflow: hidden; text-overflow: ellipsis; }
.mkbi-table .r { text-align: right; font-variant-numeric: tabular-nums; }
.mkbi-table tfoot td { font-weight: 700; border-top: 1px solid #EEEEF3; }
.mkbi-table .sortable { cursor: pointer; } .mkbi-table tr.clickable { cursor: pointer; } .mkbi-table tr.clickable:hover td { background: #FAF8FD; }
.mkbi-table--matrix td.r { border: 2px solid #fff; border-radius: 4px; }
.mkbi-rank { display: inline-flex; width: 20px; height: 20px; border-radius: 6px; background: #F1EDF7; color: #6A0DAD; font-size: 10px; font-weight: 700; align-items: center; justify-content: center; margin-right: 8px; }
.mkbi-empty { height: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px; color: #8A8A99; font-size: 13px; text-align: center; padding: 0 12px; }
.mkbi-locked strong { color: #3A3A4A; font-size: 13px; } .mkbi-locked span { font-size: 12px; max-width: 260px; }
.mkbi-skeleton { height: 100%; border-radius: 12px; background: linear-gradient(90deg, #F6F5FA 25%, #EFEDF5 37%, #F6F5FA 63%); background-size: 400% 100%; animation: mkbi-shimmer 1.4s ease infinite; }
@keyframes mkbi-shimmer { 0% { background-position: 100% 50% } 100% { background-position: 0 50% } }
</style>
