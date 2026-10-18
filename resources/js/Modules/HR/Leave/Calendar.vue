<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type CalendarEvent = {
  id: number
  employee_name: string
  leave_type: string
  start_date: string
  end_date: string
  status: string
  status_color: string
  division: string | null
}

type LeaveType = { id: number; name: string; statutory_type: string | null }

const props = defineProps<{
  events: CalendarEvent[]
  leaveTypes: LeaveType[]
  clashes: CalendarEvent[][]
  criticalWarnings: string[]
  divisions: string[]
  selectedDivisions: string[]
  teamView: boolean
  calendarRole: string
  dateRange: { start: string; end: string }
}>()

const viewStart = ref(props.dateRange.start)
const viewEnd = ref(props.dateRange.end)
const selectedDivs = ref([...props.selectedDivisions])
const showTeam = ref(props.teamView)

function applyFilters() {
  router.get(route('hr.leave.calendar'), {
    start: viewStart.value,
    end: viewEnd.value,
    divisions: selectedDivs.value,
    team: showTeam.value ? 1 : undefined,
  }, { preserveState: true, replace: true })
}

function prevMonth() {
  const d = new Date(viewStart.value)
  d.setMonth(d.getMonth() - 1)
  viewStart.value = d.toISOString().slice(0, 10)
  const e = new Date(viewEnd.value)
  e.setMonth(e.getMonth() - 1)
  viewEnd.value = e.toISOString().slice(0, 10)
  applyFilters()
}

function nextMonth() {
  const d = new Date(viewStart.value)
  d.setMonth(d.getMonth() + 1)
  viewStart.value = d.toISOString().slice(0, 10)
  const e = new Date(viewEnd.value)
  e.setMonth(e.getMonth() + 1)
  viewEnd.value = e.toISOString().slice(0, 10)
  applyFilters()
}

const monthLabel = computed(() => {
  const d = new Date(viewStart.value)
  return d.toLocaleDateString('en-ZA', { month: 'long', year: 'numeric' })
})

const statusColorMap: Record<string, string> = {
  approved: 'success', pending: 'warning', pending_manager: 'warning',
  pending_hr: 'warning', rejected: 'error', cancelled: 'secondary',
}

function groupByDate(): Record<string, CalendarEvent[]> {
  const out: Record<string, CalendarEvent[]> = {}
  for (const ev of props.events) {
    const d = ev.start_date
    if (!out[d]) out[d] = []
    out[d].push(ev)
  }
  return out
}

const grouped = computed(groupByDate)

function calendarDays(): string[] {
  const days: string[] = []
  const start = new Date(viewStart.value)
  const end = new Date(viewEnd.value)
  for (let d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
    days.push(d.toISOString().slice(0, 10))
  }
  return days
}

const days = computed(calendarDays)
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">

    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('hr.leave.index'))" />
        <div>
          <h4 class="text-h4">Leave Calendar</h4>
          <p class="text-medium-emphasis mb-0">Team leave visibility and scheduling</p>
        </div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="router.visit(route('hr.leave.apply'))">Apply for Leave</VBtn>
    </div>

    <!-- Critical Warnings -->
    <VAlert v-if="criticalWarnings.length" type="error" variant="tonal" class="mb-5">
      <template #title>Critical Coverage Gaps</template>
      <ul class="pl-4 mb-0">
        <li v-for="w in criticalWarnings" :key="w">{{ w }}</li>
      </ul>
    </VAlert>

    <!-- Clashes -->
    <VAlert v-if="clashes.length" type="warning" variant="tonal" class="mb-5">
      <template #title>Leave Clashes Detected</template>
      {{ clashes.length }} overlap(s) found. Review before approving.
    </VAlert>

    <!-- Controls -->
    <VCard elevation="0" border class="mb-5">
      <VCardText class="pa-4">
        <VRow dense align="center">
          <VCol cols="12" sm="auto" class="d-flex align-center gap-2">
            <VBtn icon="tabler-chevron-left" size="small" variant="tonal" @click="prevMonth" />
            <span class="text-body-1 font-weight-semibold" style="min-width: 160px; text-align: center;">{{ monthLabel }}</span>
            <VBtn icon="tabler-chevron-right" size="small" variant="tonal" @click="nextMonth" />
          </VCol>
          <VCol cols="12" sm="4">
            <VAutocomplete
              v-if="divisions.length"
              v-model="selectedDivs"
              :items="divisions"
              label="Filter by Division"
              variant="outlined"
              density="compact"
              hide-details
              multiple
              chips
              closable-chips
              @update:model-value="applyFilters"
            />
          </VCol>
          <VCol cols="auto">
            <VSwitch
              v-model="showTeam"
              label="Team View"
              hide-details
              density="compact"
              @update:model-value="applyFilters"
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Calendar Grid — list-style for simplicity -->
    <VCard elevation="0" border>
      <VCardText class="pa-0">
        <div v-if="events.length">
          <!-- Month rows -->
          <div
            v-for="day in days.filter(d => grouped[d]?.length)"
            :key="day"
            class="d-flex gap-3 pa-3"
            style="border-bottom: 1px solid rgba(0,0,0,.06);"
          >
            <div style="min-width: 80px;">
              <p class="text-body-2 font-weight-semibold mb-0">
                {{ new Date(day).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short' }) }}
              </p>
              <p class="text-caption text-medium-emphasis mb-0">
                {{ new Date(day).toLocaleDateString('en-ZA', { weekday: 'short' }) }}
              </p>
            </div>
            <div class="d-flex flex-wrap gap-2 align-center">
              <VChip
                v-for="ev in grouped[day]"
                :key="ev.id"
                :color="statusColorMap[ev.status] ?? 'default'"
                size="small"
                variant="tonal"
                class="cursor-pointer"
                @click="router.visit(route('hr.leave.show', { leaveRequest: ev.id }))"
              >
                {{ ev.employee_name }} · {{ ev.leave_type }}
              </VChip>
            </div>
          </div>
        </div>
        <div v-else class="text-center py-16">
          <VIcon icon="tabler-calendar" size="56" color="secondary" class="mb-3" />
          <p class="text-body-1 text-medium-emphasis">No leave scheduled in this period.</p>
        </div>
      </VCardText>
    </VCard>

    <!-- Legend -->
    <div class="d-flex flex-wrap gap-3 mt-4">
      <div v-for="(color, status) in statusColorMap" :key="status" class="d-flex align-center gap-1">
        <VChip :color="color" size="x-small" variant="tonal" class="text-capitalize">{{ status.replace('_', ' ') }}</VChip>
      </div>
    </div>

  </VContainer>
</template>
