<script setup lang="ts">
import { computed, ref } from 'vue'
import { useTheme } from 'vuetify'
import { useI18n } from 'vue-i18n'

/**
 * The schedule as bars.
 *
 * Hand-drawn SVG rather than a charting library, for three reasons that all turned out to matter:
 * a Gantt is a bar per row against a shared time axis and nothing a chart library adds is worth the
 * weight; the critical path has to be *the* visual signal, which means controlling the fill; and a
 * baseline bar has to sit under the current one at the same scale, which most libraries make
 * awkward.
 *
 * The one thing this deliberately does not do is let you drag a bar. Dates here are computed by the
 * CPM engine from durations, links and constraints — dragging a bar would mean silently pinning a
 * constraint on the task, and the next reschedule would either undo it or, worse, keep it and
 * quietly stop the plan from moving. Dates are changed on the task, where the reason for the change
 * can be recorded.
 */
const { t } = useI18n()
const theme = useTheme()

type Row = {
  id: string; code: string; name: string; status: string
  early_start: string | null; early_finish: string | null
  late_start: string | null; late_finish: string | null
  float_days: number | null; is_critical: boolean; is_milestone: boolean
  duration_days: number; scheduled: boolean
}

const props = defineProps<{
  tasks: Row[]
  /** Baseline dates by task code, drawn as a ghost bar under the current one. */
  baselineByCode?: Record<string, { start: string | null; end: string | null }>
}>()

const ROW_HEIGHT = 30
const BAR_HEIGHT = 14
const LABEL_WIDTH = 260
const PADDING = 16

const showCriticalOnly = ref(false)

const rows = computed(() => props.tasks
  .filter(task => task.scheduled && task.early_start && task.early_finish)
  .filter(task => !showCriticalOnly.value || task.is_critical))

const day = (value: string | null) => (value ? new Date(value).getTime() : null)

/**
 * The window every bar is drawn against.
 *
 * A day of padding either side, so a bar that starts on the first day is not flush against the
 * axis and unreadable.
 */
const window_ = computed(() => {
  const starts = rows.value.map(r => day(r.early_start)!).filter(Boolean)
  const ends = rows.value.map(r => day(r.early_finish)!).filter(Boolean)

  if (!starts.length) return null

  const min = Math.min(...starts)
  const max = Math.max(...ends)
  const span = Math.max(max - min, 86400000)

  return { min: min - span * 0.02, max: max + span * 0.02 }
})

const chartWidth = 900
const plotWidth = chartWidth - LABEL_WIDTH - PADDING

function x(value: string | null): number {
  if (!value || !window_.value) return LABEL_WIDTH

  const { min, max } = window_.value
  const fraction = (new Date(value).getTime() - min) / (max - min)

  return LABEL_WIDTH + fraction * plotWidth
}

const height = computed(() => Math.max(120, rows.value.length * ROW_HEIGHT + 44))

/** Month boundaries, as the only gridlines — a line per day is noise at any real project length. */
const gridlines = computed(() => {
  if (!window_.value) return []

  const out: Array<{ x: number; label: string }> = []
  const cursor = new Date(window_.value.min)
  cursor.setDate(1)

  while (cursor.getTime() <= window_.value.max) {
    const at = x(cursor.toISOString())

    if (at > LABEL_WIDTH && at < chartWidth) {
      out.push({
        x: at,
        label: cursor.toLocaleDateString(undefined, { month: 'short', year: '2-digit' }),
      })
    }

    cursor.setMonth(cursor.getMonth() + 1)
  }

  return out
})

const todayX = computed(() => {
  if (!window_.value) return null

  const now = Date.now()

  return now >= window_.value.min && now <= window_.value.max ? x(new Date(now).toISOString()) : null
})

const colours = computed(() => {
  const c = theme.current.value.colors

  return {
    critical: c.error,
    normal: c.primary,
    done: c.success,
    baseline: theme.current.value.dark ? '#5A5F80' : '#C9CCDA',
    grid: theme.current.value.dark ? '#3A3E5B' : '#E6E6E8',
    text: theme.current.value.dark ? '#CFD3EC' : '#4B465C',
    muted: theme.current.value.dark ? '#8A8DA8' : '#8A8D93',
  }
})

function barColour(task: Row) {
  if (task.status === 'done') return colours.value.done

  // Critical beats everything else: a day lost here is a day off the end of the job, and that is
  // the one thing the reader must not have to look up.
  return task.is_critical ? colours.value.critical : colours.value.normal
}

function baselineFor(task: Row) {
  const b = props.baselineByCode?.[task.code]

  return b?.start && b?.end ? b : null
}

const criticalCount = computed(() => props.tasks.filter(t => t.is_critical).length)
</script>

<template>
  <VCard border>
    <VCardItem>
      <VCardTitle class="text-body-1">{{ t('project_management.schedule.gantt') }}</VCardTitle>
      <VCardSubtitle>{{ t('project_management.schedule.gantt_sub') }}</VCardSubtitle>
      <template #append>
        <VBtn
          size="small" variant="tonal"
          :color="showCriticalOnly ? 'error' : undefined"
          :prepend-icon="showCriticalOnly ? 'tabler-filter-filled' : 'tabler-filter'"
          @click="showCriticalOnly = !showCriticalOnly"
        >
          {{ t('project_management.schedule.critical_only', { count: criticalCount }) }}
        </VBtn>
      </template>
    </VCardItem>
    <VDivider />

    <VCardText v-if="!rows.length" class="text-center text-medium-emphasis py-8">
      {{ t('project_management.schedule.nothing_scheduled') }}
    </VCardText>

    <!-- Scrolls inside itself: a long project must not make the whole page scroll sideways. -->
    <div v-else class="gantt-scroll">
      <svg :width="chartWidth" :height="height" role="img"
           :aria-label="t('project_management.schedule.gantt')">
        <!-- Month gridlines and their labels -->
        <g>
          <template v-for="line in gridlines" :key="line.x">
            <line :x1="line.x" :y1="24" :x2="line.x" :y2="height" :stroke="colours.grid" stroke-width="1" />
            <text :x="line.x + 4" y="16" :fill="colours.muted" font-size="10">{{ line.label }}</text>
          </template>
        </g>

        <!-- Today. The single most useful line on the chart. -->
        <g v-if="todayX !== null">
          <line :x1="todayX" y1="24" :x2="todayX" :y2="height"
                :stroke="colours.critical" stroke-width="1.5" stroke-dasharray="4 3" />
          <text :x="todayX + 4" y="34" :fill="colours.critical" font-size="10">
            {{ t('project_management.schedule.today') }}
          </text>
        </g>

        <g v-for="(task, i) in rows" :key="task.id" :transform="`translate(0, ${34 + i * ROW_HEIGHT})`">
          <text x="0" :y="BAR_HEIGHT" :fill="colours.text" font-size="11">
            <tspan :fill="colours.muted">{{ task.code }}</tspan>
            <tspan dx="6">{{ task.name.length > 30 ? task.name.slice(0, 29) + '…' : task.name }}</tspan>
          </text>

          <!-- The baseline, drawn under and behind: what was committed to, against what is planned
               now. Absent when no baseline has been taken. -->
          <rect
            v-if="baselineFor(task)"
            :x="x(baselineFor(task)!.start)" :y="BAR_HEIGHT + 2"
            :width="Math.max(2, x(baselineFor(task)!.end) - x(baselineFor(task)!.start))"
            height="4" rx="2" :fill="colours.baseline"
          />

          <!-- A milestone is a moment, not a span: a diamond rather than a bar of arbitrary width. -->
          <g v-if="task.is_milestone">
            <rect
              :x="x(task.early_finish) - 5" :y="1" width="10" height="10" rx="1"
              :fill="barColour(task)" :transform="`rotate(45, ${x(task.early_finish)}, 6)`"
            />
          </g>
          <rect
            v-else
            :x="x(task.early_start)" y="0"
            :width="Math.max(3, x(task.early_finish) - x(task.early_start))"
            :height="BAR_HEIGHT" rx="3" :fill="barColour(task)"
          >
            <title>{{ task.code }} · {{ task.name }} — {{ task.early_start }} → {{ task.early_finish }}</title>
          </rect>

          <!-- Float, as a thin tail: how far this task can slip before it moves the end date. -->
          <line
            v-if="!task.is_critical && task.late_finish && task.float_days"
            :x1="x(task.early_finish)" :y1="BAR_HEIGHT / 2"
            :x2="x(task.late_finish)" :y2="BAR_HEIGHT / 2"
            :stroke="colours.muted" stroke-width="1" stroke-dasharray="2 2"
          />
        </g>
      </svg>
    </div>

    <VDivider />
    <VCardText class="d-flex flex-wrap gap-4 text-caption text-medium-emphasis py-2">
      <span><VIcon icon="tabler-square-filled" size="12" color="error" /> {{ t('project_management.schedule.legend_critical') }}</span>
      <span><VIcon icon="tabler-square-filled" size="12" color="primary" /> {{ t('project_management.schedule.legend_normal') }}</span>
      <span><VIcon icon="tabler-square-filled" size="12" color="success" /> {{ t('project_management.schedule.legend_done') }}</span>
      <span><VIcon icon="tabler-minus" size="12" /> {{ t('project_management.schedule.legend_float') }}</span>
      <span><VIcon icon="tabler-diamond" size="12" /> {{ t('project_management.schedule.legend_milestone') }}</span>
    </VCardText>
  </VCard>
</template>

<style scoped>
.gantt-scroll {
  overflow-x: auto;
  padding: 8px 16px 16px;
}
</style>
