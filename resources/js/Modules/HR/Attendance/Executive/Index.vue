<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type WeeklyTrend = {
  week: string
  attendance_rate: number
  late_count: number
  overtime: number
}

type AtRiskEmployee = {
  employee: string
  department: string
  overall_risk: string
  risk_color: string
  composite_score: number
  risk_factors: string[] | null
}

type OpenViolation = {
  id: number
  employee: string
  policy_rule: string | null
  category_label: string
  severity: string
  severity_color: string
  status: string
  status_color: string
}

type DeptEfficiency = {
  department: string
  efficiency_score: number
  headcount: number
  avg_attendance_rate: number
}

type Prediction = {
  id: number
  type_label: string
  risk_level: string
  risk_color: string
  predicted_for: string
  confidence: number
}

const props = defineProps<{
  today: string
  totalActive: number
  todayKpis: { present: number; absent: number; late: number; wfh: number; overtime: number }
  weeklyTrend: WeeklyTrend[]
  burnoutSummary: { critical: number; high: number; medium: number }
  atRiskEmployees: AtRiskEmployee[]
  openViolations: OpenViolation[]
  deptEfficiency: DeptEfficiency[]
  productivitySummary: any
  predictions: Prediction[]
}>()

const aiSummary = ref<string | null>(null)
const loadingAi = ref(false)

async function fetchAiSummary() {
  loadingAi.value = true
  try {
    const res = await fetch(route('attendance.executive.ai-summary'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': (document.querySelector('meta[name=csrf-token]') as any)?.content ?? '',
      },
      body: JSON.stringify({ today: props.today }),
    })
    const data = await res.json()
    aiSummary.value = data.summary
  } finally {
    loadingAi.value = false
  }
}

const attendanceRate = Math.round((props.todayKpis.present / props.totalActive) * 100) || 0
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <h4 class="text-h4">Executive Attendance Dashboard</h4>
        <p class="text-medium-emphasis mb-0">{{ today }} · Organisation-wide view</p>
      </div>
      <VBtn color="primary" variant="tonal" prepend-icon="tabler-robot" :loading="loadingAi" @click="fetchAiSummary">AI Summary</VBtn>
    </div>

    <!-- AI Summary -->
    <VCard v-if="aiSummary" elevation="0" border class="mb-5" color="primary">
      <VCardText class="pa-5">
        <div class="d-flex gap-3">
          <VIcon icon="tabler-robot" size="24" class="mt-1 flex-shrink-0" />
          <p class="text-body-2 mb-0" style="white-space: pre-wrap;">{{ aiSummary }}</p>
        </div>
      </VCardText>
    </VCard>

    <!-- Today KPIs -->
    <VRow class="mb-5">
      <VCol cols="6" sm="4" lg="2">
        <VCard elevation="0" border>
          <VCardText class="text-center pa-3">
            <h5 class="text-h5 text-success">{{ todayKpis.present }}</h5>
            <p class="text-caption text-medium-emphasis mb-1">Present</p>
            <VProgressLinear :model-value="attendanceRate" color="success" height="4" rounded />
            <p class="text-caption font-weight-semibold mt-1">{{ attendanceRate }}%</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="4" lg="2"><VCard elevation="0" border><VCardText class="text-center pa-3"><h5 class="text-h5 text-error">{{ todayKpis.absent }}</h5><p class="text-caption text-medium-emphasis mb-0">Absent</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="4" lg="2"><VCard elevation="0" border><VCardText class="text-center pa-3"><h5 class="text-h5 text-warning">{{ todayKpis.late }}</h5><p class="text-caption text-medium-emphasis mb-0">Late</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="4" lg="2"><VCard elevation="0" border><VCardText class="text-center pa-3"><h5 class="text-h5 text-info">{{ todayKpis.wfh }}</h5><p class="text-caption text-medium-emphasis mb-0">WFH</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="4" lg="2"><VCard elevation="0" border><VCardText class="text-center pa-3"><h5 class="text-h5 text-secondary">{{ todayKpis.overtime }}</h5><p class="text-caption text-medium-emphasis mb-0">Overtime</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="4" lg="2"><VCard elevation="0" border><VCardText class="text-center pa-3"><h5 class="text-h5">{{ totalActive }}</h5><p class="text-caption text-medium-emphasis mb-0">Total Active</p></VCardText></VCard></VCol>
    </VRow>

    <VRow>
      <!-- Weekly Trend -->
      <VCol cols="12" lg="8">
        <VCard elevation="0" border class="mb-5">
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Weekly Attendance Trend (12 Weeks)</VCardTitle>
          <VDivider />
          <VCardText class="pa-4" style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;min-width:500px;">
              <thead>
                <tr>
                  <th class="text-left text-caption text-medium-emphasis pa-2" style="border-bottom:1px solid rgba(0,0,0,.08);">Week</th>
                  <th class="text-right text-caption text-medium-emphasis pa-2" style="border-bottom:1px solid rgba(0,0,0,.08);">Attendance %</th>
                  <th class="text-right text-caption text-medium-emphasis pa-2" style="border-bottom:1px solid rgba(0,0,0,.08);">Late</th>
                  <th class="text-right text-caption text-medium-emphasis pa-2" style="border-bottom:1px solid rgba(0,0,0,.08);">Overtime hrs</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="t in weeklyTrend" :key="t.week" style="border-bottom:1px solid rgba(0,0,0,.04);">
                  <td class="pa-2 text-body-2 text-medium-emphasis">{{ t.week }}</td>
                  <td class="pa-2 text-right">
                    <span :class="t.attendance_rate >= 90 ? 'text-success' : t.attendance_rate >= 75 ? 'text-warning' : 'text-error'" class="font-weight-semibold text-body-2">{{ t.attendance_rate }}%</span>
                  </td>
                  <td class="pa-2 text-right text-body-2">{{ t.late_count }}</td>
                  <td class="pa-2 text-right text-body-2">{{ t.overtime }}</td>
                </tr>
              </tbody>
            </table>
          </VCardText>
        </VCard>

        <!-- Department Efficiency -->
        <VCard elevation="0" border>
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Department Efficiency</VCardTitle>
          <VDivider />
          <VCardText class="pa-4">
            <div v-for="d in deptEfficiency" :key="d.department" class="mb-3">
              <div class="d-flex justify-space-between align-center mb-1">
                <span class="text-body-2">{{ d.department }}</span>
                <span class="text-caption text-medium-emphasis">{{ d.headcount }} staff · {{ d.avg_attendance_rate }}% present</span>
              </div>
              <VProgressLinear :model-value="d.efficiency_score" :color="d.efficiency_score >= 80 ? 'success' : d.efficiency_score >= 60 ? 'warning' : 'error'" height="8" rounded />
            </div>
            <p v-if="!deptEfficiency.length" class="text-center text-medium-emphasis py-4">No department data.</p>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Right column -->
      <VCol cols="12" lg="4">
        <!-- Burnout -->
        <VCard elevation="0" border class="mb-5">
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Burnout Risk</VCardTitle>
          <VDivider />
          <VCardText class="pa-4">
            <div class="d-flex gap-3 mb-4">
              <VCard elevation="0" variant="tonal" color="error" class="flex-grow-1 text-center pa-3 rounded-lg">
                <h6 class="text-h6">{{ burnoutSummary.critical }}</h6>
                <p class="text-caption mb-0">Critical</p>
              </VCard>
              <VCard elevation="0" variant="tonal" color="warning" class="flex-grow-1 text-center pa-3 rounded-lg">
                <h6 class="text-h6">{{ burnoutSummary.high }}</h6>
                <p class="text-caption mb-0">High</p>
              </VCard>
              <VCard elevation="0" variant="tonal" color="info" class="flex-grow-1 text-center pa-3 rounded-lg">
                <h6 class="text-h6">{{ burnoutSummary.medium }}</h6>
                <p class="text-caption mb-0">Medium</p>
              </VCard>
            </div>
            <VList density="compact">
              <VListItem v-for="e in atRiskEmployees" :key="e.employee">
                <template #prepend>
                  <VAvatar :color="e.risk_color" variant="tonal" size="30" rounded class="me-2">
                    <VIcon icon="tabler-user-exclamation" size="14" />
                  </VAvatar>
                </template>
                <template #title><span class="text-body-2">{{ e.employee }}</span></template>
                <template #subtitle><span class="text-caption text-medium-emphasis">{{ e.department }}</span></template>
                <template #append>
                  <VChip :color="e.risk_color" size="x-small" variant="tonal" class="text-capitalize">{{ e.overall_risk }}</VChip>
                </template>
              </VListItem>
            </VList>
          </VCardText>
        </VCard>

        <!-- Open violations -->
        <VCard elevation="0" border>
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Open Policy Violations</VCardTitle>
          <VDivider />
          <VList v-if="openViolations.length" density="compact">
            <VListItem v-for="v in openViolations" :key="v.id">
              <template #title><span class="text-body-2">{{ v.employee }}</span></template>
              <template #subtitle><span class="text-caption text-medium-emphasis">{{ v.policy_rule }}</span></template>
              <template #append>
                <VChip :color="v.severity_color" size="x-small" variant="tonal" class="text-capitalize">{{ v.severity }}</VChip>
              </template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-center py-6 text-medium-emphasis">No open violations.</VCardText>
        </VCard>
      </VCol>
    </VRow>
  </VContainer>
</template>
