<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type WellnessScore = {
  id: number
  employee: string
  employee_id: number
  wellness_score: number
  burnout_risk: string
  risk_color: string
  fatigue_level: string
  total_overtime_h: number
  consecutive_days: number
  late_count: number
  absenteeism_pct: number
  period_start: string
  period_end: string
  ai_summary: string | null
}

type Prediction = {
  id: number
  type: string
  type_label: string
  scope: string
  department: string | null
  employee: string | null
  confidence: number
  risk_level: string
  risk_color: string
  predicted_for: string
  evidence: any
  ai_explanation: string | null
  status: string
}

const props = defineProps<{
  scores: { data: WellnessScore[]; links: any[]; meta: any }
  stats: { critical: number; high: number; medium: number; low: number }
  predictions: Prediction[]
  employees: any[]
  filters: { period_start: string; period_end: string }
}>()

const periodStart = ref(props.filters.period_start)
const periodEnd   = ref(props.filters.period_end)
const scanDialog  = ref(false)

function applyFilter() {
  router.get(route('attendance.workforce.index'), { period_start: periodStart.value, period_end: periodEnd.value }, { preserveState: true, replace: true })
}

function runScan() {
  router.post(route('attendance.workforce.scan'), { period_start: periodStart.value, period_end: periodEnd.value }, {
    onSuccess: () => { scanDialog.value = false },
  })
}

function dismiss(id: number) { router.post(route('attendance.workforce.predictions.dismiss', { workforcePressurePrediction: id })) }

const headers = [
  { title: 'Employee',      key: 'employee',       sortable: false },
  { title: 'Score',         key: 'wellness_score', sortable: false, align: 'center' as const },
  { title: 'Burnout Risk',  key: 'burnout_risk',   sortable: false },
  { title: 'Fatigue',       key: 'fatigue_level',  sortable: false },
  { title: 'OT Hours',      key: 'total_overtime_h',sortable: false, align: 'end' as const },
  { title: 'Consec. Days',  key: 'consecutive_days',sortable: false, align: 'end' as const },
  { title: 'Late',          key: 'late_count',     sortable: false, align: 'end' as const },
]
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.dashboard'))" />
        <div>
          <h4 class="text-h4">Workforce Pressure</h4>
          <p class="text-medium-emphasis mb-0">Wellness scores, burnout risk and pressure predictions</p>
        </div>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" color="warning" @click="router.visit(route('attendance.workforce.burnout'))">Burnout Profiles</VBtn>
        <VBtn variant="tonal" color="info" @click="router.visit(route('attendance.workforce.predictions'))">Predictions</VBtn>
        <VBtn color="error" variant="tonal" prepend-icon="tabler-scan" @click="scanDialog = true">Run Scan</VBtn>
      </div>
    </div>

    <!-- Stats -->
    <VRow class="mb-5">
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-error">{{ stats.critical }}</h6><p class="text-caption text-medium-emphasis mb-0">Critical</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-warning">{{ stats.high }}</h6><p class="text-caption text-medium-emphasis mb-0">High Risk</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-info">{{ stats.medium }}</h6><p class="text-caption text-medium-emphasis mb-0">Medium Risk</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-success">{{ stats.low }}</h6><p class="text-caption text-medium-emphasis mb-0">Low Risk</p></VCardText></VCard></VCol>
    </VRow>

    <!-- Predictions -->
    <VCard v-if="predictions.length" elevation="0" border class="mb-5">
      <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Active Predictions</VCardTitle>
      <VDivider />
      <VList>
        <VListItem v-for="p in predictions" :key="p.id">
          <template #prepend>
            <VAvatar :color="p.risk_color" variant="tonal" size="36" rounded class="me-3">
              <VIcon icon="tabler-brain" size="18" />
            </VAvatar>
          </template>
          <template #title>
            <span class="text-body-2 font-weight-semibold">{{ p.type_label }}</span>
            <VChip :color="p.risk_color" size="x-small" variant="tonal" class="ms-2 text-capitalize">{{ p.risk_level }}</VChip>
          </template>
          <template #subtitle>
            <span class="text-caption text-medium-emphasis">{{ p.department ?? p.employee ?? p.scope }} · For {{ p.predicted_for }} · {{ p.confidence }}% confidence</span>
          </template>
          <template #append>
            <VBtn icon="tabler-x" size="x-small" variant="text" color="secondary" @click="dismiss(p.id)" />
          </template>
        </VListItem>
      </VList>
    </VCard>

    <!-- Filter -->
    <VCard elevation="0" border class="mb-5">
      <VCardText class="pa-4">
        <VRow dense align="center">
          <VCol cols="12" sm="4"><VTextField v-model="periodStart" label="From" type="date" variant="outlined" density="compact" hide-details /></VCol>
          <VCol cols="12" sm="4"><VTextField v-model="periodEnd" label="To" type="date" variant="outlined" density="compact" hide-details :min="periodStart" /></VCol>
          <VCol cols="auto"><VBtn color="primary" variant="tonal" @click="applyFilter">Apply</VBtn></VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Scores table -->
    <VCard elevation="0" border>
      <VDataTable :headers="headers" :items="scores.data" hide-default-footer hover :items-per-page="20">
        <template #item.wellness_score="{ item }">
          <VChip :color="item.risk_color" size="small" variant="tonal" class="font-weight-semibold">{{ item.wellness_score }}</VChip>
        </template>
        <template #item.burnout_risk="{ item }">
          <VChip :color="item.risk_color" size="x-small" variant="tonal" class="text-capitalize">{{ item.burnout_risk }}</VChip>
        </template>
        <template #item.fatigue_level="{ item }">
          <span class="text-caption text-capitalize text-medium-emphasis">{{ item.fatigue_level }}</span>
        </template>
        <template #no-data>
          <div class="text-center pa-10">
            <VIcon icon="tabler-heart" size="56" color="success" class="mb-3" />
            <p class="text-body-1 text-medium-emphasis">No wellness scores. Run a scan to calculate.</p>
          </div>
        </template>
      </VDataTable>
    </VCard>

    <!-- Scan dialog -->
    <VDialog v-model="scanDialog" max-width="440" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-h6">Run Workforce Pressure Scan</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12"><VTextField v-model="periodStart" label="Period Start *" type="date" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12"><VTextField v-model="periodEnd" label="Period End *" type="date" variant="outlined" density="comfortable" :min="periodStart" /></VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="scanDialog = false">Cancel</VBtn>
          <VBtn color="error" variant="flat" @click="runScan">Run Scan</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
