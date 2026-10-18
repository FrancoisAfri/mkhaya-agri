<script setup lang="ts">
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'

type Score = {
  id: number; employee: string; employee_id: number; score: number; risk_level: string
  risk_color: string; attendance_rate: number; late_count: number; absent_count: number
  anomaly_count: number; period: string; calculated_at: string; ai_summary: string | null
}
type Paginated<T> = { data: T[]; current_page: number; last_page: number; total: number }

defineProps<{
  scores: Paginated<Score>
  stats:  { critical: number; high: number; medium: number; low: number }
}>()

const subNav    = ref('risk')
const explainId = ref<number | null>(null)

function explainScore(id: number) {
  explainId.value = id
  useForm({}).post(`/attendance/intelligence/risk-scores/${id}/explain`, {
    onFinish: () => { explainId.value = null },
  })
}

function scoreColor(score: number): string {
  if (score >= 75) return 'deep-purple'
  if (score >= 50) return 'error'
  if (score >= 25) return 'warning'
  return 'success'
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <VCol cols="12" class="pb-0">
        <VCard variant="outlined">
          <VTabs v-model="subNav" color="primary" density="compact">
            <VTab value="hub" href="/attendance/intelligence"><VIcon start size="16" icon="tabler-robot" />AI Hub</VTab>
            <VTab value="anomalies" href="/attendance/intelligence/anomalies"><VIcon start size="16" icon="tabler-bug" />Anomaly Detection</VTab>
            <VTab value="risk" href="/attendance/intelligence/risk-scores"><VIcon start size="16" icon="tabler-chart-bar" />Risk Scores</VTab>
            <VTab value="patterns" href="/attendance/intelligence/patterns"><VIcon start size="16" icon="tabler-chart-timeline-variant" />Behaviour Patterns</VTab>
          </VTabs>
        </VCard>
      </VCol>
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div>
              <div class="text-overline text-medium-emphasis">Attendance Intelligence</div>
              <h4 class="text-h4 mb-1">Attendance Risk Scores</h4>
              <p class="mb-0 text-medium-emphasis">Composite risk scores computed from lateness, absenteeism, and anomaly data.</p>
            </div>
            <div class="d-flex gap-2">
              <VBtn variant="text" color="primary" href="/attendance/intelligence/anomalies">
                <i class="fas fa-bug me-2"></i>Anomalies
              </VBtn>
              <VBtn variant="text" color="info" href="/attendance/intelligence">
                <i class="fas fa-robot me-2"></i>AI Intelligence
              </VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Stats -->
      <VCol v-for="(count, level) in stats" :key="level" cols="6" md="3">
        <VCard :color="level === 'critical' ? 'deep-purple' : level === 'high' ? 'error' : level === 'medium' ? 'warning' : 'success'" variant="tonal" class="text-center">
          <VCardText class="py-5">
            <div class="text-h4 font-weight-bold">{{ count }}</div>
            <div class="text-caption text-capitalize">{{ level }} Risk</div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Table -->
      <VCol cols="12">
        <VCard>
          <VTable hover density="comfortable">
            <thead>
              <tr>
                <th>Employee</th>
                <th>Risk Score</th>
                <th>Risk Level</th>
                <th>Attendance</th>
                <th>Late</th>
                <th>Absent</th>
                <th>Anomalies</th>
                <th>Period</th>
                <th class="text-center">AI</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!scores.data.length">
                <td colspan="9" class="text-center py-8 text-medium-emphasis">No risk scores computed yet. Run an anomaly scan to generate scores.</td>
              </tr>
              <template v-for="s in scores.data" :key="s.id">
                <tr>
                  <td class="font-weight-medium">{{ s.employee }}</td>
                  <td>
                    <VProgressLinear
                      :model-value="s.score"
                      :color="scoreColor(s.score)"
                      height="20"
                      rounded
                    >
                      <span class="text-caption font-weight-bold">{{ s.score }}</span>
                    </VProgressLinear>
                  </td>
                  <td><VChip :color="s.risk_color" size="small" variant="tonal" class="text-capitalize">{{ s.risk_level }}</VChip></td>
                  <td>
                    <VChip :color="s.attendance_rate >= 90 ? 'success' : s.attendance_rate >= 75 ? 'warning' : 'error'" size="small" variant="tonal">
                      {{ s.attendance_rate }}%
                    </VChip>
                  </td>
                  <td><VChip :color="s.late_count >= 5 ? 'error' : 'medium-emphasis'" size="small" variant="tonal">{{ s.late_count }}</VChip></td>
                  <td><VChip :color="s.absent_count >= 5 ? 'error' : 'medium-emphasis'" size="small" variant="tonal">{{ s.absent_count }}</VChip></td>
                  <td><VChip :color="s.anomaly_count > 0 ? 'warning' : 'success'" size="small" variant="tonal">{{ s.anomaly_count }}</VChip></td>
                  <td class="text-caption">{{ s.period }}</td>
                  <td class="text-center">
                    <VBtn icon size="small" variant="text" color="secondary" title="AI Explain" :loading="explainId === s.id" @click="explainScore(s.id)">
                      <i class="fas fa-robot"></i>
                    </VBtn>
                  </td>
                </tr>
                <tr v-if="s.ai_summary">
                  <td colspan="9" style="background:rgba(0,0,0,0.02)" class="px-6 py-2">
                    <VAlert type="info" variant="tonal" density="compact" style="font-size:0.82rem">
                      <i class="fas fa-robot me-2"></i><strong>AI Summary:</strong> {{ s.ai_summary }}
                    </VAlert>
                  </td>
                </tr>
              </template>
            </tbody>
          </VTable>
          <VCardText v-if="scores.last_page > 1" class="d-flex justify-center py-4">
            <VPagination :model-value="scores.current_page" :length="scores.last_page" @update:model-value="page => router.get('/attendance/intelligence/risk-scores', { page }, { preserveState: true })" />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </VContainer>
</template>
