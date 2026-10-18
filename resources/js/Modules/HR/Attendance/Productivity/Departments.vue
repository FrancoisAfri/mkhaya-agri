<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type DeptScore = {
  id: number
  department: string
  efficiency_score: number
  headcount: number
  avg_attendance_rate: number
  avg_productivity_score: number
  high_productivity_count: number
  low_productivity_count: number
  is_overworked_dept: boolean
  ai_summary: string | null
  period_start: string
  period_end: string
}

defineProps<{ departments: DeptScore[] }>()

const headers = [
  { title: 'Department',   key: 'department',           sortable: false },
  { title: 'Eff. Score',   key: 'efficiency_score',     sortable: false, align: 'center' as const },
  { title: 'Headcount',    key: 'headcount',            sortable: false, align: 'end' as const },
  { title: 'Attendance%',  key: 'avg_attendance_rate',  sortable: false, align: 'end' as const },
  { title: 'High Perf.',   key: 'high_productivity_count', sortable: false, align: 'end' as const },
  { title: 'Low Perf.',    key: 'low_productivity_count',  sortable: false, align: 'end' as const },
  { title: 'Overworked',   key: 'is_overworked_dept',   sortable: false, align: 'center' as const },
]
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center gap-2 mb-6">
      <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.productivity.index'))" />
      <div>
        <h4 class="text-h4">Department Efficiency</h4>
        <p class="text-medium-emphasis mb-0">Productivity and attendance scores by department</p>
      </div>
    </div>

    <VCard elevation="0" border>
      <VDataTable :headers="headers" :items="departments" hover :items-per-page="25">
        <template #item.efficiency_score="{ item }">
          <div class="d-flex align-center gap-2" style="min-width:100px;">
            <VProgressLinear :model-value="item.efficiency_score" :color="item.efficiency_score >= 80 ? 'success' : item.efficiency_score >= 60 ? 'warning' : 'error'" height="6" rounded style="flex:1;" />
            <span class="text-caption font-weight-semibold">{{ item.efficiency_score }}</span>
          </div>
        </template>
        <template #item.avg_attendance_rate="{ item }">
          <span :class="item.avg_attendance_rate >= 90 ? 'text-success' : item.avg_attendance_rate >= 75 ? 'text-warning' : 'text-error'">{{ item.avg_attendance_rate }}%</span>
        </template>
        <template #item.is_overworked_dept="{ item }">
          <VIcon :icon="item.is_overworked_dept ? 'tabler-alert-triangle' : 'tabler-check'" :color="item.is_overworked_dept ? 'warning' : 'success'" size="18" />
        </template>
        <template #no-data><div class="text-center pa-10 text-medium-emphasis">No department data available.</div></template>
      </VDataTable>
    </VCard>
  </VContainer>
</template>
