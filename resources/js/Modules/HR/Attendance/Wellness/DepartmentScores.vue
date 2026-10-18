<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type DeptScore = {
  id: number
  department: string
  wellness_score: number
  risk_level: string
  risk_color: string
  headcount: number
  high_risk_count: number
  open_alerts: number
  avg_absenteeism: number
  avg_overtime: number
  top_issues: string[] | null
  ai_summary: string | null
  period_start: string
  period_end: string
}

const props = defineProps<{
  scores: DeptScore[]
  departments: string[]
  filters: { period_start: string; period_end: string }
}>()

const periodStart = ref(props.filters.period_start)
const periodEnd   = ref(props.filters.period_end)

function applyFilter() {
  router.get(route('attendance.wellness.departments'), { period_start: periodStart.value, period_end: periodEnd.value }, { preserveState: true, replace: true })
}

const headers = [
  { title: 'Department',   key: 'department',      sortable: false },
  { title: 'Score',        key: 'wellness_score',  sortable: false, align: 'center' as const },
  { title: 'Risk',         key: 'risk_level',      sortable: false },
  { title: 'Headcount',    key: 'headcount',       sortable: false, align: 'end' as const },
  { title: 'High Risk',    key: 'high_risk_count', sortable: false, align: 'end' as const },
  { title: 'Alerts',       key: 'open_alerts',     sortable: false, align: 'end' as const },
  { title: 'Absenteeism%', key: 'avg_absenteeism', sortable: false, align: 'end' as const },
  { title: 'Avg OT hrs',   key: 'avg_overtime',    sortable: false, align: 'end' as const },
]
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center gap-2 mb-6">
      <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.wellness.index'))" />
      <div>
        <h4 class="text-h4">Department Wellness Scores</h4>
        <p class="text-medium-emphasis mb-0">Aggregate wellness across departments</p>
      </div>
    </div>

    <VCard elevation="0" border class="mb-5">
      <VCardText class="pa-4">
        <VRow dense align="center">
          <VCol cols="12" sm="4"><VTextField v-model="periodStart" label="From" type="date" variant="outlined" density="compact" hide-details /></VCol>
          <VCol cols="12" sm="4"><VTextField v-model="periodEnd" label="To" type="date" variant="outlined" density="compact" hide-details :min="periodStart" /></VCol>
          <VCol cols="auto"><VBtn color="primary" variant="tonal" @click="applyFilter">Apply</VBtn></VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VDataTable :headers="headers" :items="scores" hover :items-per-page="25">
        <template #item.wellness_score="{ item }">
          <div class="d-flex align-center gap-2" style="min-width:100px;">
            <VProgressLinear :model-value="item.wellness_score" :color="item.risk_color" height="6" rounded style="flex:1;" />
            <span class="text-caption font-weight-semibold">{{ item.wellness_score }}</span>
          </div>
        </template>
        <template #item.risk_level="{ item }">
          <VChip :color="item.risk_color" size="x-small" variant="tonal" class="text-capitalize">{{ item.risk_level }}</VChip>
        </template>
        <template #item.avg_absenteeism="{ item }">{{ item.avg_absenteeism }}%</template>
        <template #item.avg_overtime="{ item }">{{ item.avg_overtime }}h</template>
        <template #no-data>
          <div class="text-center pa-10">
            <VIcon icon="tabler-building" size="56" color="secondary" class="mb-3" />
            <p class="text-body-1 text-medium-emphasis">No department scores available.</p>
          </div>
        </template>
      </VDataTable>
    </VCard>
  </VContainer>
</template>
