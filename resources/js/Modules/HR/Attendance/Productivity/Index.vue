<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

defineProps<{
  scores: any[]
  departments: any[]
  trends: any[]
  summary: {
    avg_score: number
    high_performers: number
    low_performers: number
    avg_attendance: number
    overworked_depts: number
  }
}>()

const scanDialog = { value: false }

const headers = [
  { title: 'Employee',    key: 'employee_name',    sortable: false },
  { title: 'Score',       key: 'overall_score',    sortable: false, align: 'center' as const },
  { title: 'Tier',        key: 'productivity_tier',sortable: false },
  { title: 'Attendance%', key: 'attendance_rate',  sortable: false, align: 'end' as const },
]

const tierColor = (t: string) => t === 'high' ? 'success' : t === 'medium' ? 'info' : t === 'at_risk' ? 'error' : 'warning'
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.dashboard'))" />
        <div>
          <h4 class="text-h4">Productivity Overview</h4>
          <p class="text-medium-emphasis mb-0">Employee attendance-based productivity scores</p>
        </div>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" color="default" @click="router.visit(route('attendance.productivity.departments'))">By Department</VBtn>
        <VBtn variant="tonal" color="default" @click="router.visit(route('attendance.productivity.trends'))">Trends</VBtn>
        <VBtn color="warning" variant="tonal" prepend-icon="tabler-scan" @click="router.post(route('attendance.productivity.scan'))">Run Scan</VBtn>
      </div>
    </div>

    <!-- Summary -->
    <VRow class="mb-5">
      <VCol cols="6" sm="2"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6">{{ summary.avg_score }}</h6><p class="text-caption text-medium-emphasis mb-0">Avg Score</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="2"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-success">{{ summary.high_performers }}</h6><p class="text-caption text-medium-emphasis mb-0">High</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="2"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-error">{{ summary.low_performers }}</h6><p class="text-caption text-medium-emphasis mb-0">Low/At Risk</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6">{{ summary.avg_attendance }}%</h6><p class="text-caption text-medium-emphasis mb-0">Avg Attendance</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-warning">{{ summary.overworked_depts }}</h6><p class="text-caption text-medium-emphasis mb-0">Overworked Depts</p></VCardText></VCard></VCol>
    </VRow>

    <VCard elevation="0" border>
      <VDataTable :headers="headers" :items="scores" hover :items-per-page="25">
        <template #item.overall_score="{ item }">
          <VChip :color="tierColor(item.productivity_tier)" size="small" variant="tonal" class="font-weight-semibold">{{ item.overall_score }}</VChip>
        </template>
        <template #item.productivity_tier="{ item }">
          <VChip :color="tierColor(item.productivity_tier)" size="x-small" variant="tonal" class="text-capitalize">{{ item.productivity_tier?.replace('_', ' ') }}</VChip>
        </template>
        <template #item.attendance_rate="{ item }">
          <span :class="item.attendance_rate >= 90 ? 'text-success' : item.attendance_rate >= 75 ? 'text-warning' : 'text-error'">{{ item.attendance_rate }}%</span>
        </template>
        <template #no-data><div class="text-center pa-10 text-medium-emphasis">No scores. Run a productivity scan first.</div></template>
      </VDataTable>
    </VCard>
  </VContainer>
</template>
