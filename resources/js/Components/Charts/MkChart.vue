<script setup lang="ts">
/**
 * Every chart in Mkhaya, drawn with ECharts in the Mkhaya design system (purple first, quiet axes,
 * rounded bars, donuts with the percentage in the legend, rand-formatted numbers).
 *
 * It takes the same props the old ApexCharts tag did (type, series, options, height), so a screen
 * moves over by changing its tag and nothing else. Only what the screens use is translated: line,
 * area, bar (incl. horizontal / stacked / mixed series), donut / pie, heatmap, radialBar, radar.
 */
import { computed } from 'vue'
import { useTheme } from 'vuetify'
import { use } from 'echarts/core'
import { CanvasRenderer } from 'echarts/renderers'
import { BarChart, LineChart, PieChart, HeatmapChart, GaugeChart, RadarChart, ScatterChart } from 'echarts/charts'
import { GridComponent, TooltipComponent, LegendComponent, VisualMapComponent, DataZoomComponent, RadarComponent, MarkLineComponent } from 'echarts/components'
import VChart from 'vue-echarts'
import { PALETTE, fmt } from './charts'

use([CanvasRenderer, BarChart, LineChart, PieChart, HeatmapChart, GaugeChart, RadarChart, ScatterChart,
  GridComponent, TooltipComponent, LegendComponent, VisualMapComponent, DataZoomComponent, RadarComponent, MarkLineComponent])

const props = withDefaults(defineProps<{ type?: string; series?: any[]; options?: Record<string, any>; height?: number | string; width?: number | string }>(), {
  type: undefined, series: () => [], options: () => ({}), height: 300,
})

// Colours the old charts passed for meaning (Sneat's palette) map onto Mkhaya's; any other colour is kept.
const REMAP: Record<string, string> = { '#7367f0': '#6A0DAD', '#28c76f': '#2E7D32', '#00cfe8': '#00A8B5', '#ff9f43': '#E4A800', '#ea5455': '#C62828', '#696cff': '#6A0DAD', '#71dd37': '#2E7D32', '#ffab00': '#E4A800', '#ff3e1d': '#C62828', '#03c3ec': '#00A8B5',
  '#4f46e5': '#6A0DAD', '#10b981': '#2E7D32', '#f59e0b': '#E4A800', '#ef4444': '#C62828', '#3b82f6': '#1E88E5', '#8b5cf6': '#8E5CD9' }
// The page's primary colour is the agency's brand when white label is on (branding.ts sets it), so
// "purple" in a chart always means "this page's brand", never a fixed Mkhaya purple on a teal page.
const theme = useTheme()
const brand = computed(() => String(theme.current.value.colors.primary || '#6A0DAD'))
const colours = computed<string[]>(() => {
  const ownBrand = (c: string) => (c.toUpperCase() === '#6A0DAD' ? brand.value : c)
  // Some screens build colours as "106,13,173, 0.7)" (hexToRgb gives bare numbers). ApexCharts
  // shrugged that off; ECharts paints it grey, so it is repaired into rgb()/rgba() here.
  const fix = (c: string) => {
    const m = /^\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([\d.]+))?\s*\)?\s*$/.exec(c)
    return m ? (m[4] !== undefined ? `rgba(${m[1]}, ${m[2]}, ${m[3]}, ${m[4]})` : `rgb(${m[1]}, ${m[2]}, ${m[3]})`) : c
  }
  const given = (props.options?.colors ?? []).filter((c: any) => typeof c === 'string').map(fix)
  if (given.length) return given.map((c: string) => ownBrand(REMAP[c.toLowerCase()] ?? c))
  return [brand.value, ...PALETTE.filter(c => c.toUpperCase() !== '#6A0DAD' && c.toUpperCase() !== brand.value.toUpperCase())]
})


const o = computed(() => props.options ?? {})
const yFormatter = computed(() => {
  const y = Array.isArray(o.value.yaxis) ? o.value.yaxis[0] : o.value.yaxis
  return y?.labels?.formatter ?? o.value.tooltip?.y?.formatter ?? fmt
})
const tipFormatter = computed(() => o.value.tooltip?.y?.formatter ?? yFormatter.value)
const safe = (f: any, v: any) => { try { return f(v) } catch { return fmt(v) } }

const base = () => ({
  color: colours.value,
  textStyle: { fontFamily: 'Nunito Sans, Inter, sans-serif' },
  grid: { left: 8, right: 16, top: 36, bottom: 8, containLabel: true },
  tooltip: {
    trigger: 'axis', confine: true, backgroundColor: '#fff', borderColor: '#ECECF1', borderWidth: 1,
    textStyle: { color: '#1F1F29', fontSize: 12 },
    extraCssText: 'box-shadow:0 8px 24px rgba(17,17,26,.08); border-radius:10px;',
    valueFormatter: (v: any) => safe(tipFormatter.value, v),
  },
  legend: { top: 0, icon: 'circle', itemWidth: 8, itemHeight: 8, textStyle: { color: '#5B5B6E' } },
})
const axisCommon = { axisLine: { lineStyle: { color: '#E6E6EE' } }, axisTick: { show: false }, axisLabel: { color: '#5B5B6E', fontSize: 11, hideOverlap: true } }

const kind = computed(() => String(props.type || props.options?.chart?.type || 'line').toLowerCase())

const option = computed(() => {
  const s = props.series ?? []
  const t = kind.value

  // Part-to-whole: donut (never a pie with leader lines); the legend carries the percentage.
  if (t === 'donut' || t === 'pie' || t === 'polararea') {
    const labels: string[] = o.value.labels ?? []
    const data = (s as any[]).map((v, i) => ({ name: labels[i] ?? `Item ${i + 1}`, value: Number(typeof v === 'object' ? v?.value ?? 0 : v) }))
    const total = data.reduce((a, d) => a + (d.value || 0), 0) || 1
    return {
      ...base(),
      tooltip: { ...base().tooltip, trigger: 'item' },
      legend: { ...base().legend, type: 'scroll', top: 'bottom', formatter: (n: string) => { const d = data.find(x => x.name === n); return `${n}  ${d ? Math.round((d.value / total) * 100) : 0}%` } },
      series: [{ type: 'pie', radius: ['55%', '78%'], center: ['50%', '45%'], avoidLabelOverlap: true, label: { show: false }, labelLine: { show: false },
        itemStyle: { borderColor: '#fff', borderWidth: 2 }, data }],
    }
  }

  // A single percentage: a clean gauge.
  if (t === 'radialbar') {
    const v = Number(Array.isArray(s) ? s[0] : s) || 0
    return {
      ...base(),
      series: [{ type: 'gauge', startAngle: 220, endAngle: -40, min: 0, max: 100, progress: { show: true, width: 14, roundCap: true, itemStyle: { color: colours.value[0] } },
        axisLine: { lineStyle: { width: 14, color: [[1, '#F1F1F5']] } }, axisTick: { show: false }, splitLine: { show: false }, axisLabel: { show: false }, pointer: { show: false },
        title: { show: !!o.value.labels?.[0], offsetCenter: [0, '30%'], color: '#77778A', fontSize: 12 },
        detail: { valueAnimation: true, offsetCenter: [0, '-5%'], fontSize: 26, fontWeight: 700, color: '#16161F', formatter: '{value}%' },
        data: [{ value: Math.round(v * 10) / 10, name: o.value.labels?.[0] ?? '' }] }],
    }
  }

  // Heatmap: rows are the series, columns the x values, purple intensity.
  if (t === 'heatmap') {
    const xs: string[] = []
    ;(s as any[]).forEach(r => (r.data ?? []).forEach((p: any) => { const x = String(p?.x ?? ''); if (!xs.includes(x)) xs.push(x) }))
    const ys = (s as any[]).map(r => r.name)
    const data: any[] = []
    ;(s as any[]).forEach((r, yi) => (r.data ?? []).forEach((p: any) => data.push([xs.indexOf(String(p?.x ?? '')), yi, Number(p?.y ?? 0)])))
    const max = Math.max(1, ...data.map(d => d[2]))
    return {
      ...base(),
      tooltip: { ...base().tooltip, trigger: 'item', formatter: (p: any) => `${ys[p.value[1]]} · ${xs[p.value[0]]}: <b>${safe(tipFormatter.value, p.value[2])}</b>` },
      grid: { left: 8, right: 16, top: 10, bottom: 40, containLabel: true },
      xAxis: { type: 'category', data: xs, ...axisCommon, splitArea: { show: false } },
      yAxis: { type: 'category', data: ys, ...axisCommon },
      visualMap: { min: 0, max, calculable: false, orient: 'horizontal', left: 'center', bottom: 0, itemHeight: 120, inRange: { color: ['#F4F4F8', brand.value] }, textStyle: { color: '#77778A' } },
      series: [{ type: 'heatmap', data, itemStyle: { borderColor: '#fff', borderWidth: 2, borderRadius: 4 } }],
    }
  }

  if (t === 'radar') {
    const cats: string[] = o.value.xaxis?.categories ?? o.value.labels ?? []
    const max = Math.max(1, ...(s as any[]).flatMap(r => (r.data ?? []).map((v: any) => Number(v) || 0)))
    return {
      ...base(), tooltip: { ...base().tooltip, trigger: 'item' },
      radar: { indicator: cats.map(name => ({ name, max })), axisName: { color: '#5B5B6E' }, splitLine: { lineStyle: { color: '#F1F1F5' } }, splitArea: { show: false } },
      series: [{ type: 'radar', data: (s as any[]).map(r => ({ name: r.name, value: r.data, areaStyle: { opacity: 0.12 } })) }],
    }
  }

  // Axis charts: line, area, bar/column, mixed.
  const horizontal = !!o.value.plotOptions?.bar?.horizontal
  const stacked = !!o.value.chart?.stacked
  const firstData = (s as any[]).find(r => (r.data ?? []).length)?.data ?? []
  const pointObjects = firstData.length && typeof firstData[0] === 'object' && firstData[0] !== null && 'x' in firstData[0]
  let cats: any[] = o.value.xaxis?.categories ?? o.value.labels ?? []
  // {x, y} points: every series' x values on one shared axis (series need not share dates).
  if ((!cats || !cats.length) && pointObjects) {
    const xs: any[] = []
    ;(s as any[]).forEach(r => (r.data ?? []).forEach((p: any) => { if (p && !xs.includes(p.x)) xs.push(p.x) }))
    cats = xs.every(x => typeof x === 'number') ? xs.sort((a, b) => a - b) : xs
  }
  const at = (r: any, pick: (p: any) => any) => pointObjects
    ? cats.map(c => { const p = (r.data ?? []).find((q: any) => q?.x === c); return p ? pick(p) : null })
    : (r.data ?? []).map(pick)
  const isDate = o.value.xaxis?.type === 'datetime'
  const catLabels = isDate ? cats.map((c: any) => { const d = new Date(c); return Number.isNaN(d.getTime()) ? c : d.toLocaleDateString('en-ZA', { day: 'numeric', month: 'short' }) }) : cats

  const series = (s as any[]).flatMap((r, i) => {
    const rt = (r.type ?? t).toLowerCase()
    const colour = colours.value[i % colours.value.length]
    // A band (min..max): an invisible lower line, then the height of the band stacked on it and shaded.
    if (rt === 'rangearea') {
      const lo = at(r, p => (Array.isArray(p?.y) ? Number(p.y[0]) : null))
      const hi = at(r, p => (Array.isArray(p?.y) ? Number(p.y[1]) : null))
      const band = `band${i}`
      return [
        { name: r.name, type: 'line', data: lo, stack: band, symbol: 'none', lineStyle: { opacity: 0 }, color: colour, tooltip: { show: false }, connectNulls: true },
        { name: r.name, type: 'line', data: hi.map((h, k) => (h === null || lo[k] === null ? null : h - (lo[k] as number))), stack: band, symbol: 'none', lineStyle: { opacity: 0 }, areaStyle: { opacity: 0.35, color: colour }, color: colour, connectNulls: true },
      ]
    }
    const asBar = rt === 'bar' || rt === 'column'
    const values = at(r, (p: any) => (p && typeof p === 'object' ? Number(p.y ?? p.value ?? 0) : p === null || p === undefined ? null : Number(p)))
    const lastYear = /last year|previous|prior/i.test(String(r.name ?? ''))
    return [asBar
      ? { name: r.name, type: 'bar', data: values, stack: stacked ? 'total' : undefined, barMaxWidth: 26, itemStyle: { borderRadius: horizontal ? [0, 6, 6, 0] : [6, 6, 0, 0] } }
      : { name: r.name, type: 'line', data: values, smooth: false, symbol: 'circle', symbolSize: 5, showSymbol: values.length <= 31, connectNulls: true,
          lineStyle: { width: 2.5, type: lastYear ? 'dashed' : 'solid' }, stack: stacked && rt === 'area' ? 'total' : undefined,
          areaStyle: rt === 'area' ? { opacity: (s as any[]).length === 1 ? 0.12 : 0.08 } : undefined }].map(x => ({ ...x, color: colour }))
  })

  const ya = Array.isArray(o.value.yaxis) ? o.value.yaxis[0] : o.value.yaxis
  const valueAxis = { type: 'value', min: ya?.min, max: ya?.max, ...axisCommon, axisLine: { show: false }, splitLine: { lineStyle: { color: '#F1F1F5' } }, axisLabel: { ...axisCommon.axisLabel, formatter: (v: any) => safe(yFormatter.value, v) } }
  const catAxis = { type: 'category', data: catLabels, ...axisCommon, axisLabel: { ...axisCommon.axisLabel, ...(horizontal ? { width: 130, overflow: 'truncate' } : {}) } }

  return {
    ...base(),
    legend: { ...base().legend, show: (s as any[]).length > 1 },
    xAxis: horizontal ? valueAxis : catAxis,
    yAxis: horizontal ? { ...catAxis, inverse: true } : valueAxis,
    dataZoom: !horizontal && catLabels.length > 40 ? [{ type: 'inside' }] : undefined,
    series,
  }
})

const empty = computed(() => {
  const s = props.series ?? []
  if (!s.length) return true
  if (typeof s[0] === 'number') return s.every((v: any) => !Number(v))
  return s.every((r: any) => !(r?.data ?? []).length)
})
// Screens pass height="300" (a string): a bare number is pixels, anything else is used as given.
const h = computed(() => {
  const v = props.height ?? 300
  return typeof v === 'number' || /^\d+(\.\d+)?$/.test(String(v)) ? `${v}px` : String(v)
})
</script>

<template>
  <div class="mk-chart" :style="{ height: h }">
    <div v-if="empty" class="mk-chart-empty">No data for these filters</div>
    <VChart v-else :option="option" autoresize />
  </div>
</template>

<style scoped>
.mk-chart { position: relative; width: 100%; min-height: 0; }
.mk-chart-empty { height: 100%; display: flex; align-items: center; justify-content: center; color: #9AA0A6; font-size: 13px; }
</style>
