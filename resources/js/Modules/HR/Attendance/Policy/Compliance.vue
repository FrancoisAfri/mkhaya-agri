<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type ComplianceScore = {
  id: number
  employee: string
  period_start: string
  period_end: string
  total_score: number
  score_color: string
  score_label: string
  lateness_score: number
  overtime_score: number
  attendance_score: number
  adherence_score: number
  violations_count: number
  exceptions_count: number
  ai_summary: string | null
  computed_at: string
}

const props = defineProps<{
  scores: { data: ComplianceScore[]; links: any[]; meta: any }
  averageScore: number
}>()

const genDialog = ref(false)
const genFrom   = ref('')
const genTo     = ref('')
const generating = ref(false)

function runGenerate() {
  generating.value = true
  router.post(route('attendance.policy.generate-compliance'), { period_start: genFrom.value, period_end: genTo.value }, {
    onFinish: () => { generating.value = false; genDialog.value = false },
  })
}

const headers = [
  { title: 'Employee',   key: 'employee',      sortable: false },
  { title: 'Score',      key: 'total_score',   sortable: false, align: 'center' as const },
  { title: 'Attendance', key: 'attendance_score', sortable: false, align: 'end' as const },
  { title: 'Lateness',   key: 'lateness_score',sortable: false, align: 'end' as const },
  { title: 'Overtime',   key: 'overtime_score',sortable: false, align: 'end' as const },
  { title: 'Adherence',  key: 'adherence_score',sortable: false, align: 'end' as const },
  { title: 'Violations', key: 'violations_count',sortable: false, align: 'end' as const },
  { title: 'Period',     key: 'period_start',  sortable: false },
]
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.policy.index'))" />
        <div>
          <h4 class="text-h4">Compliance Scores</h4>
          <p class="text-medium-emphasis mb-0">Employee attendance compliance ratings</p>
        </div>
      </div>
      <VBtn color="primary" variant="tonal" prepend-icon="tabler-calculator" @click="genDialog = true">Generate Scores</VBtn>
    </div>

    <!-- Org average -->
    <VCard elevation="0" border class="mb-5">
      <VCardText class="pa-5">
        <VRow align="center">
          <VCol cols="12" sm="4" class="text-center">
            <h3 class="text-h3 font-weight-bold" :class="averageScore >= 80 ? 'text-success' : averageScore >= 60 ? 'text-warning' : 'text-error'">{{ averageScore }}</h3>
            <p class="text-body-2 text-medium-emphasis mb-0">Organisation Average Score</p>
          </VCol>
          <VCol cols="12" sm="8">
            <VProgressLinear :model-value="averageScore" :color="averageScore >= 80 ? 'success' : averageScore >= 60 ? 'warning' : 'error'" height="12" rounded />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VDataTable :headers="headers" :items="scores.data" hide-default-footer hover :items-per-page="20">
        <template #item.total_score="{ item }">
          <VChip :color="item.score_color" size="small" variant="tonal" class="font-weight-semibold">{{ item.total_score }} — {{ item.score_label }}</VChip>
        </template>
        <template #item.period_start="{ item }">
          <span class="text-caption text-medium-emphasis">{{ item.period_start }} – {{ item.period_end }}</span>
        </template>
        <template #no-data><div class="text-center pa-10 text-medium-emphasis">No scores. Use Generate Scores to calculate.</div></template>
      </VDataTable>
    </VCard>

    <!-- Generate dialog -->
    <VDialog v-model="genDialog" max-width="440" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-h6">Generate Compliance Scores</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12"><VTextField v-model="genFrom" label="Period Start *" type="date" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12"><VTextField v-model="genTo" label="Period End *" type="date" variant="outlined" density="comfortable" :min="genFrom" /></VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="genDialog = false">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="generating" :disabled="!genFrom || !genTo" @click="runGenerate">Generate</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
