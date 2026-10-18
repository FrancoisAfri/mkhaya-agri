<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type WeeklyViolation = {
  employee_id: number
  employee_name: string
  week_start: string
  total_hours: number
  total_overtime: number
  violation_type: string
}

type RestViolation = {
  employee_id: number
  employee_name: string
  first_day: string
  first_clock_out: string
  second_day: string
  second_clock_in: string
  rest_hours: number
}

const props = defineProps<{
  weeklyViolations: WeeklyViolation[]
  restViolations: RestViolation[]
  stats: { weekly_violations: number; rest_violations: number; affected_employees: number; weeks_analysed: number }
  weeks: number
}>()

const weeksFilter = ref(props.weeks)
const activeTab   = ref('weekly')

function applyFilter() {
  router.get(route('attendance.bcea.index'), { weeks: weeksFilter.value }, { preserveState: true, replace: true })
}

const weeklyHeaders = [
  { title: 'Employee',    key: 'employee_name',  sortable: false },
  { title: 'Week Start',  key: 'week_start',     sortable: false },
  { title: 'Total Hrs',   key: 'total_hours',    sortable: false, align: 'end' as const },
  { title: 'OT Hrs',      key: 'total_overtime', sortable: false, align: 'end' as const },
  { title: 'Violation',   key: 'violation_type', sortable: false },
]

const restHeaders = [
  { title: 'Employee',    key: 'employee_name', sortable: false },
  { title: 'Day 1',       key: 'first_day',     sortable: false },
  { title: 'Clock Out',   key: 'first_clock_out',sortable: false },
  { title: 'Day 2',       key: 'second_day',    sortable: false },
  { title: 'Clock In',    key: 'second_clock_in',sortable: false },
  { title: 'Rest (hrs)',  key: 'rest_hours',    sortable: false, align: 'end' as const },
]
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center gap-2 mb-6">
      <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.dashboard'))" />
      <div>
        <h4 class="text-h4">BCEA Compliance</h4>
        <p class="text-medium-emphasis mb-0">Basic Conditions of Employment Act working hours monitoring</p>
      </div>
    </div>

    <!-- Stats -->
    <VRow class="mb-5">
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-error">{{ stats.weekly_violations }}</h6><p class="text-caption text-medium-emphasis mb-0">Weekly Violations</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-warning">{{ stats.rest_violations }}</h6><p class="text-caption text-medium-emphasis mb-0">Rest Violations</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-info">{{ stats.affected_employees }}</h6><p class="text-caption text-medium-emphasis mb-0">Affected Employees</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6">{{ stats.weeks_analysed }}</h6><p class="text-caption text-medium-emphasis mb-0">Weeks Analysed</p></VCardText></VCard></VCol>
    </VRow>

    <!-- BCEA rules reminder -->
    <VAlert type="info" variant="tonal" class="mb-5" icon="tabler-scale">
      <strong>BCEA Limits:</strong> Ordinary hours ≤ 45h/week · Overtime ≤ 10h/week · Minimum 12h rest between shifts
    </VAlert>

    <!-- Filter -->
    <VCard elevation="0" border class="mb-5">
      <VCardText class="pa-4">
        <VRow dense align="center">
          <VCol cols="12" sm="4">
            <VSelect v-model="weeksFilter" :items="[{title:'4 Weeks',value:4},{title:'12 Weeks',value:12},{title:'26 Weeks',value:26},{title:'52 Weeks',value:52}]" item-title="title" item-value="value" label="Period" variant="outlined" density="compact" hide-details @update:model-value="applyFilter" />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VTabs v-model="activeTab" class="mb-5">
      <VTab value="weekly">Weekly Hours ({{ weeklyViolations.length }})</VTab>
      <VTab value="rest">Rest Period ({{ restViolations.length }})</VTab>
    </VTabs>

    <VWindow v-model="activeTab">
      <VWindowItem value="weekly">
        <VCard elevation="0" border>
          <VDataTable :headers="weeklyHeaders" :items="weeklyViolations" hover :items-per-page="25">
            <template #item.violation_type="{ item }">
              <VChip color="error" size="x-small" variant="tonal">{{ item.violation_type === 'overtime_exceeded' ? 'OT Exceeded (>10h)' : 'Ordinary Exceeded (>45h)' }}</VChip>
            </template>
            <template #item.total_hours="{ item }">
              <span :class="item.total_hours > 45 ? 'text-error font-weight-semibold' : ''">{{ item.total_hours }}h</span>
            </template>
            <template #item.total_overtime="{ item }">
              <span :class="item.total_overtime > 10 ? 'text-error font-weight-semibold' : ''">{{ item.total_overtime }}h</span>
            </template>
            <template #no-data><div class="text-center pa-6 text-medium-emphasis">No weekly violations.</div></template>
          </VDataTable>
        </VCard>
      </VWindowItem>

      <VWindowItem value="rest">
        <VCard elevation="0" border>
          <VDataTable :headers="restHeaders" :items="restViolations" hover :items-per-page="25">
            <template #item.rest_hours="{ item }">
              <span class="text-error font-weight-semibold">{{ item.rest_hours }}h</span>
            </template>
            <template #no-data><div class="text-center pa-6 text-medium-emphasis">No rest period violations.</div></template>
          </VDataTable>
        </VCard>
      </VWindowItem>
    </VWindow>
  </VContainer>
</template>
