<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type RiskScore = {
  id: number
  employee: string
  employee_id: number
  score: number
  risk_level: string
  risk_color: string
  attendance_rate: number
  late_count: number
  absent_count: number
  anomaly_count: number
  period: string
  calculated_at: string
  ai_summary: string | null
}

defineProps<{
  scores: { data: RiskScore[]; links: any[]; meta: any }
  stats: { critical: number; high: number; medium: number; low: number }
}>()

const headers = [
  { title: 'Employee',       key: 'employee',       sortable: false },
  { title: 'Score',          key: 'score',          sortable: false, align: 'center' as const },
  { title: 'Risk',           key: 'risk_level',     sortable: false },
  { title: 'Attendance %',   key: 'attendance_rate',sortable: false, align: 'end' as const },
  { title: 'Late',           key: 'late_count',     sortable: false, align: 'end' as const },
  { title: 'Absent',         key: 'absent_count',   sortable: false, align: 'end' as const },
  { title: 'Anomalies',      key: 'anomaly_count',  sortable: false, align: 'end' as const },
  { title: 'Period',         key: 'period',         sortable: false },
]
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center gap-2 mb-6">
      <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.intelligence.index'))" />
      <div>
        <h4 class="text-h4">Attendance Risk Scores</h4>
        <p class="text-medium-emphasis mb-0">AI-calculated risk scores per employee</p>
      </div>
    </div>

    <!-- Summary -->
    <VRow class="mb-5">
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-error">{{ stats.critical }}</h6><p class="text-caption text-medium-emphasis mb-0">Critical</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-warning">{{ stats.high }}</h6><p class="text-caption text-medium-emphasis mb-0">High</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-info">{{ stats.medium }}</h6><p class="text-caption text-medium-emphasis mb-0">Medium</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-success">{{ stats.low }}</h6><p class="text-caption text-medium-emphasis mb-0">Low</p></VCardText></VCard></VCol>
    </VRow>

    <VCard elevation="0" border>
      <VDataTable :headers="headers" :items="scores.data" hide-default-footer hover :items-per-page="30">
        <template #item.score="{ item }">
          <VChip :color="item.risk_color" size="small" variant="tonal" class="font-weight-semibold">{{ item.score }}</VChip>
        </template>
        <template #item.risk_level="{ item }">
          <VChip :color="item.risk_color" size="x-small" variant="tonal" class="text-capitalize">{{ item.risk_level }}</VChip>
        </template>
        <template #item.attendance_rate="{ item }">
          <span :class="item.attendance_rate >= 90 ? 'text-success' : item.attendance_rate >= 75 ? 'text-warning' : 'text-error'">{{ item.attendance_rate }}%</span>
        </template>
        <template #no-data>
          <div class="text-center pa-10">
            <VIcon icon="tabler-shield-check" size="56" color="success" class="mb-3" />
            <p class="text-body-1 text-medium-emphasis">No risk scores calculated yet.</p>
          </div>
        </template>
      </VDataTable>
    </VCard>
  </VContainer>
</template>
