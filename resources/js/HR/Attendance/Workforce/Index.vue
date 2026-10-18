<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type WellnessScore = {
  id: number; employee: string; employee_id: number
  wellness_score: number; burnout_risk: string; risk_color: string
  fatigue_level: string; total_overtime_h: number; consecutive_days: number
  late_count: number; absenteeism_pct: number
  period_start: string; period_end: string; ai_summary: string | null
  score_breakdown: Record<string, number> | null
}
type Prediction = {
  id: number; type: string; type_label: string; scope: string
  department: string | null; employee: string | null
  confidence: number; risk_level: string; risk_color: string
  predicted_for: string; evidence: Record<string, any> | null
  ai_explanation: string | null; status: string
}
type Employee = { id: number; first_name: string; surname: string }
type Paginated<T> = { data: T[]; current_page: number; last_page: number; total: number }

const props = defineProps<{
  scores:      Paginated<WellnessScore>
  stats:       { critical: number; high: number; medium: number; low: number }
  predictions: Prediction[]
  employees:   Employee[]
  filters:     { period_start: string; period_end: string }
}>()

const subNav         = ref('workforce')
const scanDialog     = ref(false)
const detailId       = ref<number | null>(null)

const scanForm = useForm({
  period_start: props.filters.period_start,
  period_end:   props.filters.period_end,
})

const filterStart = ref(props.filters.period_start)
const filterEnd   = ref(props.filters.period_end)

function applyFilter() {
  router.get('/attendance/workforce', { period_start: filterStart.value, period_end: filterEnd.value }, { preserveState: true })
}

function runScan() { scanForm.post('/attendance/workforce/scan', { onSuccess: () => { scanDialog.value = false } }) }

function wellnessColor(score: number): string {
  if (score >= 80) return 'success'
  if (score >= 60) return 'warning'
  if (score >= 40) return 'error'
  return 'deep-purple'
}

function fatigueColor(level: string): string {
  return level === 'high' ? 'error' : level === 'medium' ? 'warning' : 'success'
}

const statLabels: Record<string, string> = {
  critical: t('attendance_workforce.critical_burnout_risk'),
  high: t('attendance_workforce.high_burnout_risk'),
  medium: t('attendance_workforce.medium_burnout_risk'),
  low: t('attendance_workforce.low_burnout_risk'),
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <VCol cols="12" class="pb-0">
        <VCard variant="outlined">
          <VTabs v-model="subNav" color="primary" density="compact">
            <VTab value="workforce" href="/attendance/workforce"><VIcon start size="16" icon="tabler-account-group" />{{ t('attendance_workforce.tab_overview') }}</VTab>
            <VTab value="burnout" href="/attendance/workforce/burnout-profiles"><VIcon start size="16" icon="tabler-fire" />{{ t('attendance_workforce.tab_burnout') }}</VTab>
            <VTab value="predictions" href="/attendance/workforce/predictions"><VIcon start size="16" icon="tabler-crystal-ball" />{{ t('attendance_workforce.tab_predictions') }}</VTab>
            <VTab value="wellness" href="/attendance/wellness"><VIcon start size="16" icon="tabler-heart-pulse" />{{ t('attendance_workforce.tab_wellness') }}</VTab>
            <VTab value="dept-wellness" href="/attendance/wellness/departments"><VIcon start size="16" icon="tabler-domain" />{{ t('attendance_workforce.tab_dept_wellness') }}</VTab>
          </VTabs>
        </VCard>
      </VCol>
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div>
              <div class="text-overline text-medium-emphasis">{{ t('attendance_workforce.breadcrumb_module') }}</div>
              <h4 class="text-h4 mb-1">{{ t('attendance_workforce.title') }}</h4>
              <p class="mb-0 text-medium-emphasis">{{ t('attendance_workforce.subtitle') }}</p>
            </div>
            <div class="d-flex gap-2">
              <VBtn variant="text" color="info" href="/attendance/workforce/burnout-profiles"><i class="fas fa-fire me-2"></i>{{ t('attendance_workforce.burnout_profiles_btn') }}</VBtn>
              <VBtn variant="text" color="warning" href="/attendance/workforce/predictions"><i class="fas fa-chart-line me-2"></i>{{ t('attendance_workforce.predictions_btn') }}</VBtn>
              <VBtn color="primary" @click="scanDialog = true"><i class="fas fa-heartbeat me-2"></i>{{ t('attendance_workforce.run_wellness_scan') }}</VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol v-for="(count, level) in stats" :key="level" cols="6" md="3">
        <VCard :color="level === 'critical' ? 'deep-purple' : level === 'high' ? 'error' : level === 'medium' ? 'warning' : 'success'" variant="tonal" class="text-center">
          <VCardText class="py-5"><div class="text-h4 font-weight-bold">{{ count }}</div><div class="text-caption text-capitalize">{{ statLabels[level] }}</div></VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" v-if="predictions.length">
        <VCard>
          <VCardTitle class="py-4 px-5"><i class="fas fa-exclamation-triangle me-2 text-warning"></i>{{ t('attendance_workforce.active_pressure_predictions') }}</VCardTitle>
          <VDivider />
          <VCardText class="py-3">
            <div class="d-flex flex-wrap gap-3">
              <VCard v-for="p in predictions" :key="p.id" variant="outlined" :color="p.risk_color" class="pa-3" style="min-width:220px;max-width:300px">
                <div class="d-flex justify-space-between align-center mb-2">
                  <VChip :color="p.risk_color" size="x-small" variant="tonal">{{ p.risk_level }}</VChip>
                  <span class="text-caption text-medium-emphasis">{{ t('attendance_workforce.confidence_pct', { pct: p.confidence }) }}</span>
                </div>
                <div class="font-weight-medium text-body-2 mb-1">{{ p.type_label }}</div>
                <div class="text-caption text-medium-emphasis">{{ p.department ?? p.employee ?? t('attendance_workforce.organisation_wide') }}</div>
              </VCard>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap gap-3 py-3">
            <VTextField v-model="filterStart" :label="t('attendance_workforce.filter_from')" type="date" density="compact" hide-details style="max-width:160px" />
            <VTextField v-model="filterEnd" :label="t('attendance_workforce.filter_to')" type="date" density="compact" hide-details style="max-width:160px" />
            <VBtn color="primary" @click="applyFilter">{{ t('attendance_workforce.filter_btn') }}</VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12">
        <VCard>
          <VTable hover density="comfortable">
            <thead>
              <tr>
                <th>{{ t('attendance_workforce.table_employee') }}</th>
                <th>{{ t('attendance_workforce.table_wellness') }}</th>
                <th>{{ t('attendance_workforce.table_burnout_risk') }}</th>
                <th>{{ t('attendance_workforce.table_fatigue') }}</th>
                <th>{{ t('attendance_workforce.table_overtime_h') }}</th>
                <th>{{ t('attendance_workforce.table_consecutive_days') }}</th>
                <th>{{ t('attendance_workforce.table_late_arrivals') }}</th>
                <th>{{ t('attendance_workforce.table_absent_pct') }}</th>
                <th>{{ t('attendance_workforce.table_period') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!scores.data.length">
                <td colspan="9" class="text-center py-8 text-medium-emphasis">{{ t('attendance_workforce.no_wellness_scores') }}</td>
              </tr>
              <template v-for="s in scores.data" :key="s.id">
                <tr @click="detailId = detailId === s.id ? null : s.id" style="cursor:pointer">
                  <td class="font-weight-medium">{{ s.employee }}</td>
                  <td>
                    <div class="d-flex align-center gap-2">
                      <VProgressLinear :model-value="s.wellness_score" :color="wellnessColor(s.wellness_score)" height="18" rounded style="min-width:80px">
                        <span class="text-caption font-weight-bold">{{ s.wellness_score }}</span>
                      </VProgressLinear>
                    </div>
                  </td>
                  <td><VChip :color="s.risk_color" size="small" variant="tonal" class="text-capitalize">{{ s.burnout_risk }}</VChip></td>
                  <td><VChip :color="fatigueColor(s.fatigue_level)" size="small" variant="tonal" class="text-capitalize">{{ s.fatigue_level }}</VChip></td>
                  <td><VChip :color="s.total_overtime_h > 20 ? 'error' : s.total_overtime_h > 10 ? 'warning' : 'secondary'" size="small" variant="tonal">{{ s.total_overtime_h }}h</VChip></td>
                  <td><VChip :color="s.consecutive_days >= 6 ? 'error' : s.consecutive_days >= 4 ? 'warning' : 'secondary'" size="small" variant="tonal">{{ s.consecutive_days }}</VChip></td>
                  <td><VChip :color="s.late_count >= 5 ? 'error' : 'secondary'" size="small" variant="tonal">{{ s.late_count }}</VChip></td>
                  <td><VChip :color="s.absenteeism_pct >= 20 ? 'error' : s.absenteeism_pct >= 10 ? 'warning' : 'success'" size="small" variant="tonal">{{ s.absenteeism_pct }}%</VChip></td>
                  <td class="text-caption">{{ s.period_start }} – {{ s.period_end }}</td>
                </tr>
                <tr v-if="detailId === s.id">
                  <td colspan="9" class="py-3 px-6" style="background:rgba(0,0,0,0.02)">
                    <VRow dense>
                      <VCol cols="12" md="6" v-if="s.score_breakdown">
                        <div class="text-caption font-weight-medium mb-2">{{ t('attendance_workforce.score_breakdown') }}</div>
                        <div v-for="(val, key) in s.score_breakdown" :key="key" class="d-flex justify-space-between text-caption mb-1">
                          <span class="text-medium-emphasis text-capitalize">{{ String(key).replace(/_/g,' ') }}</span>
                          <VChip :color="val > 10 ? 'error' : val > 5 ? 'warning' : 'secondary'" size="x-small" variant="tonal">-{{ val }}</VChip>
                        </div>
                      </VCol>
                      <VCol cols="12" md="6" v-if="s.ai_summary">
                        <VAlert type="info" variant="tonal" density="compact" style="font-size:0.82rem"><i class="fas fa-robot me-2"></i><strong>{{ t('attendance_workforce.ai_label') }}</strong> {{ s.ai_summary }}</VAlert>
                      </VCol>
                    </VRow>
                  </td>
                </tr>
              </template>
            </tbody>
          </VTable>
          <VCardText v-if="scores.last_page > 1" class="d-flex justify-center py-4">
            <VPagination :model-value="scores.current_page" :length="scores.last_page" @update:model-value="page => router.get('/attendance/workforce', { page }, { preserveState: true })" />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="scanDialog" max-width="440">
      <VCard>
        <VCardTitle class="py-4 px-5">{{ t('attendance_workforce.scan_dialog_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="py-4">
          <VAlert type="info" variant="tonal" density="compact" class="mb-4" style="font-size:0.82rem">{{ t('attendance_workforce.scan_dialog_info') }}</VAlert>
          <VRow dense>
            <VCol cols="6"><VTextField v-model="scanForm.period_start" :label="t('attendance_workforce.field_period_start')" type="date" density="compact" /></VCol>
            <VCol cols="6"><VTextField v-model="scanForm.period_end" :label="t('attendance_workforce.field_period_end')" type="date" density="compact" /></VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="px-5 py-3"><VSpacer /><VBtn variant="text" @click="scanDialog = false">{{ t('attendance_workforce.cancel') }}</VBtn><VBtn color="primary" :loading="scanForm.processing" @click="runScan"><i class="fas fa-heartbeat me-2"></i>{{ t('attendance_workforce.run_scan') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
