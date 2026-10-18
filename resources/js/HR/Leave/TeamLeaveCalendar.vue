<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'

export type TeamLeave = { name: string; leave_type: string | null; color: string; start: string; end: string; status: string }

const { t } = useI18n()
const props = defineProps<{
  leave: TeamLeave[]
  department?: string | null
  startDate?: string
  endDate?: string
  loading?: boolean
}>()

const pad = (n: number) => String(n).padStart(2, '0')
const iso = (y: number, m: number, d: number) => `${y}-${pad(m + 1)}-${pad(d)}`
const calCursor = ref(new Date())
calCursor.value.setDate(1)
watch(() => props.startDate, (v) => { if (v) { const d = new Date(v + 'T00:00:00'); calCursor.value = new Date(d.getFullYear(), d.getMonth(), 1) } })

const monthLabel = computed(() => calCursor.value.toLocaleDateString(undefined, { month: 'long', year: 'numeric' }))
const calCells = computed(() => {
  const y = calCursor.value.getFullYear(), m = calCursor.value.getMonth()
  const startWeekday = (new Date(y, m, 1).getDay() + 6) % 7 // Monday-first
  const daysInMonth = new Date(y, m + 1, 0).getDate()
  const cells: Array<{ date: string | null; day: number | null }> = []
  for (let i = 0; i < startWeekday; i++) cells.push({ date: null, day: null })
  for (let d = 1; d <= daysInMonth; d++) cells.push({ date: iso(y, m, d), day: d })
  return cells
})
const teammatesOn = (date: string) => props.leave.filter(l => date >= l.start && date <= l.end)
const inSelected = (date: string) => {
  if (!props.startDate) return false
  if (!props.endDate) return date === props.startDate
  return date >= props.startDate && date <= props.endDate
}
const prevMonth = () => { calCursor.value = new Date(calCursor.value.getFullYear(), calCursor.value.getMonth() - 1, 1) }
const nextMonth = () => { calCursor.value = new Date(calCursor.value.getFullYear(), calCursor.value.getMonth() + 1, 1) }
const weekLabels = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su']
</script>

<template>
  <VCard style="position: sticky; top: 16px">
    <VCardTitle class="pa-4 pb-1 text-subtitle-1 d-flex align-center gap-2">
      <VIcon icon="tabler-users-group" size="20" />
      {{ t('apply_page.team_calendar') }}
      <VProgressCircular v-if="loading" size="14" width="2" indeterminate class="ms-1" />
    </VCardTitle>
    <div class="px-4 pb-1 text-caption text-medium-emphasis">
      {{ department ? t('apply_page.team_dept', { dept: department }) : t('apply_page.team_no_dept') }}
    </div>
    <VDivider />
    <VCardText class="pa-4">
      <div class="d-flex align-center justify-space-between mb-2">
        <VBtn icon="tabler-chevron-left" size="x-small" variant="text" @click="prevMonth" />
        <span class="text-body-2 font-weight-medium">{{ monthLabel }}</span>
        <VBtn icon="tabler-chevron-right" size="x-small" variant="text" @click="nextMonth" />
      </div>

      <div class="cal-grid text-caption text-medium-emphasis mb-1">
        <div v-for="w in weekLabels" :key="w" class="text-center">{{ w }}</div>
      </div>
      <div class="cal-grid">
        <template v-for="(c, i) in calCells" :key="i">
          <div v-if="!c.date" />
          <div v-else class="cal-day" :class="{ 'cal-sel': inSelected(c.date), 'cal-busy': teammatesOn(c.date).length }">
            <VTooltip v-if="teammatesOn(c.date).length" activator="parent" location="top">
              <div v-for="(l, j) in teammatesOn(c.date)" :key="j" class="text-caption">{{ l.name }} · {{ l.leave_type }}</div>
            </VTooltip>
            <span class="cal-num">{{ c.day }}</span>
            <div v-if="teammatesOn(c.date).length" class="cal-dots">
              <span v-for="(l, j) in teammatesOn(c.date).slice(0, 3)" :key="j" class="cal-dot" :style="{ background: l.color }" />
              <span v-if="teammatesOn(c.date).length > 3" class="cal-more">+{{ teammatesOn(c.date).length - 3 }}</span>
            </div>
          </div>
        </template>
      </div>

      <VDivider class="my-3" />

      <div class="text-caption text-medium-emphasis mb-1">{{ t('apply_page.team_upcoming') }}</div>
      <div v-if="!leave.length" class="text-caption text-disabled py-2">{{ t('apply_page.team_none') }}</div>
      <div v-for="(l, i) in leave.slice(0, 8)" :key="i" class="d-flex align-center gap-2 py-1">
        <span class="cal-dot" :style="{ background: l.color }" />
        <span class="text-caption font-weight-medium">{{ l.name }}</span>
        <VSpacer />
        <span class="text-caption text-medium-emphasis">{{ l.start }} → {{ l.end }}</span>
      </div>
    </VCardText>
  </VCard>
</template>

<style scoped>
.cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; }
.cal-day {
  position: relative; aspect-ratio: 1; border-radius: 6px; display: flex; flex-direction: column;
  align-items: center; justify-content: center; font-size: 11px; border: 1px solid transparent;
}
.cal-num { line-height: 1; }
.cal-busy { background: rgba(var(--v-theme-warning), 0.12); }
.cal-sel { border-color: rgb(var(--v-theme-primary)); background: rgba(var(--v-theme-primary), 0.14); font-weight: 700; }
.cal-dots { display: flex; align-items: center; gap: 1px; margin-top: 2px; height: 5px; }
.cal-dot { width: 5px; height: 5px; border-radius: 50%; display: inline-block; }
.cal-more { font-size: 8px; line-height: 1; color: rgba(var(--v-theme-on-surface), 0.6); }
</style>
