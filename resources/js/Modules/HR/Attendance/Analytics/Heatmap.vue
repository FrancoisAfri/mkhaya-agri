<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type HeatCell = {
  day_of_week: number
  hour_of_day: number
  total_clockings: number
  late_count: number
  late_rate: number
}

const props = defineProps<{
  cells: HeatCell[]
  summary: { total_clockings: number; total_late: number; avg_late_rate: number; worst_hour: HeatCell | null }
  days: number
}>()

const daysFilter = ref(props.days)

const DAY_LABELS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
const HOURS = Array.from({ length: 24 }, (_, i) => i)
const DAYS  = [1, 2, 3, 4, 5] // Mon-Fri

function cellFor(day: number, hour: number): HeatCell | undefined {
  return props.cells.find(c => c.day_of_week === day && c.hour_of_day === hour)
}

function intensity(lateRate: number): string {
  if (lateRate >= 60) return '#ef5350'
  if (lateRate >= 40) return '#ff7043'
  if (lateRate >= 20) return '#ffa726'
  if (lateRate >= 10) return '#ffee58'
  if (lateRate > 0)   return '#a5d6a7'
  return '#e8f5e9'
}

function applyFilter() {
  router.get(route('attendance.analytics.heatmap'), { days: daysFilter.value }, { preserveState: true, replace: true })
}

const workHours = computed(() => HOURS.filter(h => h >= 5 && h <= 20))
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.analytics'))" />
        <div>
          <h4 class="text-h4">Attendance Heatmap</h4>
          <p class="text-medium-emphasis mb-0">Late arrival patterns by day and hour</p>
        </div>
      </div>
    </div>

    <!-- Summary -->
    <VRow class="mb-5">
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6">{{ summary.total_clockings.toLocaleString() }}</h6><p class="text-caption text-medium-emphasis mb-0">Total Clockings</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-error">{{ summary.total_late.toLocaleString() }}</h6><p class="text-caption text-medium-emphasis mb-0">Late Arrivals</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-warning">{{ summary.avg_late_rate }}%</h6><p class="text-caption text-medium-emphasis mb-0">Avg Late Rate</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border><VCardText class="text-center pa-3">
          <h6 class="text-h6 text-error">{{ summary.worst_hour ? `${DAY_LABELS[summary.worst_hour.day_of_week]} ${summary.worst_hour.hour_of_day}:00` : '—' }}</h6>
          <p class="text-caption text-medium-emphasis mb-0">Worst Slot</p>
        </VCardText></VCard>
      </VCol>
    </VRow>

    <!-- Filter -->
    <VCard elevation="0" border class="mb-5">
      <VCardText class="pa-4">
        <VRow dense align="center">
          <VCol cols="12" sm="4">
            <VSelect v-model="daysFilter" :items="[{ title: 'Last 30 days', value: 30 }, { title: 'Last 90 days', value: 90 }, { title: 'Last 180 days', value: 180 }, { title: 'Last 365 days', value: 365 }]" item-title="title" item-value="value" label="Period" variant="outlined" density="compact" hide-details @update:model-value="applyFilter" />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Heatmap -->
    <VCard elevation="0" border>
      <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Late Rate by Day & Hour (Mon–Fri)</VCardTitle>
      <VDivider />
      <VCardText class="pa-4" style="overflow-x: auto;">
        <table style="border-collapse: separate; border-spacing: 2px; min-width: 600px;">
          <thead>
            <tr>
              <th style="width:40px;" />
              <th v-for="d in DAYS" :key="d" class="text-caption text-center font-weight-semibold" style="padding: 4px 8px;">{{ DAY_LABELS[d] }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="h in workHours" :key="h">
              <td class="text-caption text-medium-emphasis text-right pe-2" style="white-space:nowrap;">{{ h.toString().padStart(2,'0') }}:00</td>
              <td
                v-for="d in DAYS"
                :key="d"
                :title="`${DAY_LABELS[d]} ${h}:00 — ${cellFor(d, h)?.total_clockings ?? 0} clockings, ${cellFor(d, h)?.late_rate ?? 0}% late`"
                :style="{ background: intensity(cellFor(d, h)?.late_rate ?? 0), width: '50px', height: '30px', borderRadius: '4px', cursor: 'default' }"
              />
            </tr>
          </tbody>
        </table>
        <!-- Legend -->
        <div class="d-flex align-center gap-3 mt-4 flex-wrap">
          <span class="text-caption text-medium-emphasis">Late rate:</span>
          <div v-for="[color, label] in [['#e8f5e9','0%'],['#a5d6a7','1–9%'],['#ffee58','10–19%'],['#ffa726','20–39%'],['#ff7043','40–59%'],['#ef5350','60%+']]" :key="label" class="d-flex align-center gap-1">
            <div :style="{ background: color, width:'16px', height:'16px', borderRadius:'3px' }" />
            <span class="text-caption">{{ label }}</span>
          </div>
        </div>
      </VCardText>
    </VCard>
  </VContainer>
</template>
