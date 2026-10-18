<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type HeatmapCell = {
  day_of_week: number; hour_of_day: number; total_clockings: number; late_count: number; late_rate: number
}
type Summary = {
  total_clockings: number; total_late: number; avg_late_rate: number; worst_hour: HeatmapCell | null
}

const props = defineProps<{ cells: HeatmapCell[]; summary: Summary; days: number }>()

const DAY_LABELS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
const HOURS = Array.from({ length: 24 }, (_, i) => i)
const DAYS  = Array.from({ length: 7 },  (_, i) => i)

const selectedDays = ref(props.days)

const periodOptions = computed(() => [
  { title: t('attendance_analytics.period_last_30_days'), value: 30 },
  { title: t('attendance_analytics.period_last_60_days'), value: 60 },
  { title: t('attendance_analytics.period_last_90_days'), value: 90 },
  { title: t('attendance_analytics.period_last_180_days'), value: 180 },
  { title: t('attendance_analytics.period_last_365_days'), value: 365 },
])

function onPeriodChange() { router.get('/attendance/analytics/heatmap', { days: selectedDays.value }) }

const cellMap = computed<Record<string, HeatmapCell>>(() => {
  const map: Record<string, HeatmapCell> = {}
  for (const c of props.cells) { map[`${c.day_of_week}_${c.hour_of_day}`] = c }
  return map
})

function getCell(day: number, hour: number): HeatmapCell | null { return cellMap.value[`${day}_${hour}`] ?? null }

function cellBg(cell: HeatmapCell | null): string {
  if (!cell || cell.total_clockings === 0) return '#f1f5f9'
  const r = cell.late_rate
  if (r === 0) return '#f8fafc'; if (r <= 10) return '#fef3c7'; if (r <= 25) return '#fde68a'
  if (r <= 50) return '#f97316'; return '#dc2626'
}

function cellOpacity(cell: HeatmapCell | null): string { return (!cell || cell.total_clockings === 0) ? '0.4' : '1' }

function cellTooltip(cell: HeatmapCell | null): string {
  if (!cell || cell.total_clockings === 0) return t('attendance_analytics.no_data')
  return `${cell.late_count} late / ${cell.total_clockings} total (${cell.late_rate.toFixed(1)}%)`
}

const worstHourLabel = computed(() => {
  const w = props.summary.worst_hour
  if (!w) return '—'
  return `${DAY_LABELS[w.day_of_week]} ${String(w.hour_of_day).padStart(2, '0')}:00`
})

const LEGEND = computed(() => [
  { bg: '#f8fafc', label: t('attendance_analytics.legend_0') },
  { bg: '#fef3c7', label: t('attendance_analytics.legend_1_10') },
  { bg: '#fde68a', label: t('attendance_analytics.legend_11_25') },
  { bg: '#f97316', label: t('attendance_analytics.legend_26_50') },
  { bg: '#dc2626', label: t('attendance_analytics.legend_51_plus') },
])
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <VCol cols="12">
        <VCard elevation="0" border>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div>
              <div class="text-overline text-medium-emphasis">{{ t('attendance_analytics.heatmap_breadcrumb') }}</div>
              <h4 class="text-h4 mb-1">{{ t('attendance_analytics.heatmap_title') }}</h4>
              <p class="mb-0 text-medium-emphasis">{{ t('attendance_analytics.heatmap_subtitle') }}</p>
            </div>
            <VSelect v-model="selectedDays" :items="periodOptions" label="Period" density="compact" hide-details style="max-width: 200px" @update:model-value="onPeriodChange" />
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="text-center pa-4">
            <VIcon icon="tabler-clock-check-outline" color="primary" size="32" class="mb-2" />
            <div class="text-h4 font-weight-bold">{{ summary.total_clockings.toLocaleString() }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('attendance_analytics.total_clockings') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="text-center pa-4">
            <VIcon icon="tabler-alarm-check" color="warning" size="32" class="mb-2" />
            <div class="text-h4 font-weight-bold text-warning">{{ summary.total_late.toLocaleString() }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('attendance_analytics.late_arrivals') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="text-center pa-4">
            <VIcon icon="tabler-percent-outline" color="orange" size="32" class="mb-2" />
            <div class="text-h4 font-weight-bold" style="color:#f57c00">{{ summary.avg_late_rate.toFixed(1) }}%</div>
            <div class="text-caption text-medium-emphasis">{{ t('attendance_analytics.avg_late_rate') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="text-center pa-4">
            <VIcon icon="tabler-fire-alert" color="error" size="32" class="mb-2" />
            <div class="text-h5 font-weight-bold text-error">{{ worstHourLabel }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('attendance_analytics.worst_hour') }}</div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12">
        <VCard elevation="0" border class="pa-4">
          <VCardTitle class="pa-0 pb-4"><VIcon icon="tabler-grid" class="me-2" />{{ t('attendance_analytics.heatmap_grid') }}</VCardTitle>
          <div class="heatmap-scroll">
            <div class="heatmap-grid">
              <div class="heatmap-corner" />
              <div v-for="h in HOURS" :key="`h-${h}`" class="heatmap-hour-label">{{ String(h).padStart(2, '0') }}</div>
              <template v-for="d in DAYS" :key="`row-${d}`">
                <div class="heatmap-day-label">{{ DAY_LABELS[d] }}</div>
                <div v-for="h in HOURS" :key="`cell-${d}-${h}`" class="heatmap-cell" :style="{ backgroundColor: cellBg(getCell(d, h)), opacity: cellOpacity(getCell(d, h)) }" :title="cellTooltip(getCell(d, h))" />
              </template>
            </div>
          </div>
          <div class="d-flex align-center gap-4 flex-wrap mt-4">
            <span class="text-caption text-medium-emphasis font-weight-medium">{{ t('attendance_analytics.late_rate_legend') }}</span>
            <div v-for="item in LEGEND" :key="item.label" class="d-flex align-center gap-1">
              <div class="legend-swatch" :style="{ backgroundColor: item.bg, border: '1px solid rgba(0,0,0,0.1)' }" />
              <span class="text-caption">{{ item.label }}</span>
            </div>
          </div>
        </VCard>
      </VCol>
    </VRow>
  </VContainer>
</template>

<style scoped>
.heatmap-scroll { overflow-x: auto; }
.heatmap-grid { display: grid; grid-template-columns: 48px repeat(24, 32px); grid-template-rows: 24px repeat(7, 32px); gap: 3px; min-width: max-content; }
.heatmap-corner { grid-column: 1; grid-row: 1; }
.heatmap-hour-label { display: flex; align-items: center; justify-content: center; font-size: 0.65rem; font-weight: 600; color: rgba(0,0,0,0.45); height: 24px; }
.heatmap-day-label { display: flex; align-items: center; justify-content: flex-end; padding-right: 6px; font-size: 0.72rem; font-weight: 600; color: rgba(0,0,0,0.55); height: 32px; }
.heatmap-cell { width: 32px; height: 32px; border-radius: 4px; cursor: default; transition: transform 0.1s, box-shadow 0.1s; }
.heatmap-cell:hover { transform: scale(1.15); box-shadow: 0 2px 8px rgba(0,0,0,0.18); z-index: 2; position: relative; }
.legend-swatch { width: 18px; height: 18px; border-radius: 4px; flex-shrink: 0; }
</style>
