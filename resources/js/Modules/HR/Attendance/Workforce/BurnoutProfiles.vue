<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Profile = {
  id: number
  employee: string
  employee_id: number
  overall_risk: string
  risk_color: string
  composite_score: number
  overtime_overload_score: number
  consecutive_work_score: number
  absence_pattern_score: number
  late_arrival_score: number
  shift_instability_score: number
  risk_factors: string[] | null
  recommendations: string[] | null
  ai_narrative: string | null
  period_start: string
  period_end: string
  assessed_at: string
}

const props = defineProps<{
  profiles: { data: Profile[]; links: any[]; meta: any }
  filters: { risk: string }
  summary: { critical: number; high: number; medium: number; low: number }
}>()

const riskFilter = ref(props.filters.risk)
const expanded   = ref<number | null>(null)

function applyFilter() {
  router.get(route('attendance.workforce.burnout'), { risk: riskFilter.value || undefined }, { preserveState: true, replace: true })
}

function generateNarrative(id: number) {
  router.post(route('attendance.workforce.burnout.narrative', { burnoutRiskProfile: id }))
}

const headers = [
  { title: 'Employee',   key: 'employee',       sortable: false },
  { title: 'Risk',       key: 'overall_risk',   sortable: false },
  { title: 'Score',      key: 'composite_score',sortable: false, align: 'center' as const },
  { title: 'OT Load',   key: 'overtime_overload_score', sortable: false, align: 'end' as const },
  { title: 'Consec.',   key: 'consecutive_work_score',  sortable: false, align: 'end' as const },
  { title: 'Absence',   key: 'absence_pattern_score',   sortable: false, align: 'end' as const },
  { title: 'Period',    key: 'period_start',    sortable: false },
  { title: '',          key: 'actions',         sortable: false, align: 'end' as const },
]
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center gap-2 mb-6">
      <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.workforce.index'))" />
      <div>
        <h4 class="text-h4">Burnout Risk Profiles</h4>
        <p class="text-medium-emphasis mb-0">Detailed burnout risk assessment per employee</p>
      </div>
    </div>

    <!-- Summary -->
    <VRow class="mb-5">
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-error">{{ summary.critical }}</h6><p class="text-caption text-medium-emphasis mb-0">Critical</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-warning">{{ summary.high }}</h6><p class="text-caption text-medium-emphasis mb-0">High</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-info">{{ summary.medium }}</h6><p class="text-caption text-medium-emphasis mb-0">Medium</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-success">{{ summary.low }}</h6><p class="text-caption text-medium-emphasis mb-0">Low</p></VCardText></VCard></VCol>
    </VRow>

    <!-- Filter -->
    <VCard elevation="0" border class="mb-5">
      <VCardText class="pa-4">
        <VSelect v-model="riskFilter" :items="[{title:'All',value:''},{title:'Critical',value:'critical'},{title:'High',value:'high'},{title:'Medium',value:'medium'},{title:'Low',value:'low'}]" item-title="title" item-value="value" label="Risk Level" variant="outlined" density="compact" hide-details style="max-width:240px;" @update:model-value="applyFilter" />
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VDataTable :headers="headers" :items="profiles.data" hide-default-footer hover :items-per-page="15">
        <template #item.overall_risk="{ item }">
          <VChip :color="item.risk_color" size="x-small" variant="tonal" class="text-capitalize">{{ item.overall_risk }}</VChip>
        </template>
        <template #item.composite_score="{ item }">
          <VChip :color="item.risk_color" size="small" variant="tonal" class="font-weight-semibold">{{ item.composite_score }}</VChip>
        </template>
        <template #item.period_start="{ item }">
          <span class="text-caption text-medium-emphasis">{{ item.period_start }} – {{ item.period_end }}</span>
        </template>
        <template #item.actions="{ item }">
          <VBtn icon="tabler-robot" size="x-small" variant="text" color="primary" title="Generate AI Narrative" @click="generateNarrative(item.id)" />
        </template>
        <template #expanded-row="{ item }">
          <td :colspan="headers.length" class="pa-4 bg-surface">
            <p v-if="item.ai_narrative" class="text-body-2 mb-2">{{ item.ai_narrative }}</p>
            <div v-if="item.risk_factors?.length" class="mb-2">
              <span class="text-caption font-weight-semibold text-medium-emphasis">Risk Factors: </span>
              <VChip v-for="f in item.risk_factors" :key="f" size="x-small" variant="tonal" color="error" class="me-1">{{ f }}</VChip>
            </div>
            <div v-if="item.recommendations?.length">
              <span class="text-caption font-weight-semibold text-medium-emphasis">Recommendations: </span>
              <ul class="ms-4">
                <li v-for="r in item.recommendations" :key="r" class="text-caption">{{ r }}</li>
              </ul>
            </div>
          </td>
        </template>
        <template #no-data>
          <div class="text-center pa-10">
            <VIcon icon="tabler-heart-check" size="56" color="success" class="mb-3" />
            <p class="text-body-1 text-medium-emphasis">No burnout profiles found.</p>
          </div>
        </template>
      </VDataTable>
    </VCard>
  </VContainer>
</template>
