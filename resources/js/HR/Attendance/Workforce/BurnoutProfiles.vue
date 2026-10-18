<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Profile = {
  id: number; employee: string; employee_id: number
  overall_risk: string; risk_color: string; composite_score: number
  overtime_overload_score: number; consecutive_work_score: number
  absence_pattern_score: number; late_arrival_score: number; shift_instability_score: number
  risk_factors: string[]; recommendations: string[]
  ai_narrative: string | null; period_start: string; period_end: string; assessed_at: string
}
type Paginated<T> = { data: T[]; current_page: number; last_page: number; total: number }

const props = defineProps<{
  profiles: Paginated<Profile>
  filters:  { risk: string }
  summary:  { critical: number; high: number; medium: number; low: number }
}>()

const subNav           = ref('burnout')
const narrativeLoading = ref<number | null>(null)
const filterRisk       = ref(props.filters.risk)

const filterRiskOptions = computed(() => [
  { title: t('attendance_workforce.filter_all'), value: 'all' },
  { title: t('attendance_workforce.critical_risk'), value: 'critical' },
  { title: t('attendance_workforce.high_risk'), value: 'high' },
  { title: t('attendance_workforce.medium_risk'), value: 'medium' },
  { title: t('attendance_workforce.low_risk'), value: 'low' },
])

const summaryLabels: Record<string, string> = {
  critical: t('attendance_workforce.critical_risk'),
  high: t('attendance_workforce.high_risk'),
  medium: t('attendance_workforce.medium_risk'),
  low: t('attendance_workforce.low_risk'),
}

const dimensionLabels: Record<string, string> = {
  overtime_overload: t('attendance_workforce.overtime_overload'),
  consecutive_work: t('attendance_workforce.consecutive_work'),
  absence_pattern: t('attendance_workforce.absence_pattern'),
  late_arrival: t('attendance_workforce.late_arrivals_dim'),
  shift_instability: t('attendance_workforce.shift_instability'),
}

function applyFilter() { router.get('/attendance/workforce/burnout-profiles', { risk: filterRisk.value }, { preserveState: true }) }

function generateNarrative(id: number) {
  narrativeLoading.value = id
  useForm({}).post(`/attendance/workforce/burnout/${id}/narrative`, { onFinish: () => { narrativeLoading.value = null } })
}

function scoreColor(score: number): string {
  if (score >= 75) return 'error'
  if (score >= 50) return 'warning'
  if (score >= 25) return 'info'
  return 'success'
}

function getDimensionScores(p: Profile): Record<string, number> {
  return {
    [t('attendance_workforce.overtime_overload')]: p.overtime_overload_score,
    [t('attendance_workforce.consecutive_work')]: p.consecutive_work_score,
    [t('attendance_workforce.absence_pattern')]: p.absence_pattern_score,
    [t('attendance_workforce.late_arrivals_dim')]: p.late_arrival_score,
    [t('attendance_workforce.shift_instability')]: p.shift_instability_score,
  }
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
              <h4 class="text-h4 mb-1">{{ t('attendance_workforce.burnout_title') }}</h4>
              <p class="mb-0 text-medium-emphasis">{{ t('attendance_workforce.burnout_subtitle') }}</p>
            </div>
            <div class="d-flex gap-2">
              <VBtn variant="text" color="primary" href="/attendance/workforce"><i class="fas fa-heartbeat me-2"></i>{{ t('attendance_workforce.wellness_scores_btn') }}</VBtn>
              <VBtn variant="text" color="warning" href="/attendance/workforce/predictions"><i class="fas fa-chart-line me-2"></i>{{ t('attendance_workforce.predictions_btn') }}</VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol v-for="(count, level) in summary" :key="level" cols="6" md="3">
        <VCard :color="level === 'critical' ? 'deep-purple' : level === 'high' ? 'error' : level === 'medium' ? 'warning' : 'success'" variant="tonal" class="text-center">
          <VCardText class="py-4"><div class="text-h4 font-weight-bold">{{ count }}</div><div class="text-caption text-capitalize">{{ summaryLabels[level] }}</div></VCardText>
        </VCard>
      </VCol>

      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex gap-3 py-3">
            <VSelect v-model="filterRisk" :items="filterRiskOptions" :label="t('attendance_workforce.filter_risk_level')" density="compact" hide-details style="max-width:160px" />
            <VBtn color="primary" @click="applyFilter">{{ t('attendance_workforce.filter_btn') }}</VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <VCol v-if="!profiles.data.length" cols="12">
        <VAlert type="info" variant="tonal">{{ t('attendance_workforce.no_burnout_profiles') }}</VAlert>
      </VCol>

      <VCol cols="12" md="6" xl="4" v-for="p in profiles.data" :key="p.id">
        <VCard height="100%">
          <VCardTitle class="py-3 px-5 d-flex align-center justify-space-between">
            <div class="d-flex align-center gap-2">
              <VAvatar :color="p.risk_color" variant="tonal" size="36"><i class="fas fa-fire" style="font-size:14px"></i></VAvatar>
              <span class="text-body-1 font-weight-medium">{{ p.employee }}</span>
            </div>
            <VChip :color="p.risk_color" size="small" variant="tonal" class="text-capitalize">{{ p.overall_risk }}</VChip>
          </VCardTitle>
          <VDivider />
          <VCardText class="py-3">
            <div class="mb-3">
              <div class="d-flex justify-space-between text-caption mb-1"><span class="text-medium-emphasis">{{ t('attendance_workforce.composite_risk_score') }}</span><span class="font-weight-bold">{{ p.composite_score }}/100</span></div>
              <VProgressLinear :model-value="p.composite_score" :color="p.risk_color" height="8" rounded />
            </div>
            <div class="mb-3 pa-2 rounded" style="background:rgba(0,0,0,0.04)">
              <div class="text-caption font-weight-medium mb-2">{{ t('attendance_workforce.risk_dimensions') }}</div>
              <div v-for="(val, label) in getDimensionScores(p)" :key="label" class="d-flex align-center gap-2 mb-1">
                <span class="text-caption text-medium-emphasis" style="min-width:120px">{{ label }}</span>
                <VProgressLinear :model-value="val" :color="scoreColor(val)" height="6" rounded class="flex-grow-1" />
                <span class="text-caption font-weight-medium" style="min-width:28px;text-align:right">{{ val }}</span>
              </div>
            </div>
            <div v-if="p.risk_factors?.length" class="mb-3">
              <div class="text-caption font-weight-medium mb-1">{{ t('attendance_workforce.risk_factors') }}</div>
              <div v-for="f in p.risk_factors" :key="f" class="d-flex align-center gap-1 text-caption mb-1"><i class="fas fa-exclamation-circle text-warning" style="font-size:10px"></i>{{ f }}</div>
            </div>
            <div v-if="p.recommendations?.length" class="mb-3">
              <div class="text-caption font-weight-medium mb-1">{{ t('attendance_workforce.recommendations') }}</div>
              <div v-for="r in p.recommendations" :key="r" class="d-flex align-center gap-1 text-caption mb-1"><i class="fas fa-check-circle text-success" style="font-size:10px"></i>{{ r }}</div>
            </div>
            <VAlert v-if="p.ai_narrative" type="info" variant="tonal" density="compact" style="font-size:0.8rem"><i class="fas fa-robot me-1"></i>{{ p.ai_narrative }}</VAlert>
            <div v-else class="text-caption text-medium-emphasis font-italic">{{ t('attendance_workforce.ai_narrative_not_generated') }}</div>
          </VCardText>
          <VCardActions class="px-5 pb-3">
            <VBtn size="small" variant="tonal" color="info" :loading="narrativeLoading === p.id" @click="generateNarrative(p.id)"><i class="fas fa-robot me-1"></i>{{ t('attendance_workforce.generate_ai_narrative') }}</VBtn>
            <span class="text-caption text-medium-emphasis ms-2">{{ p.assessed_at.split('T')[0] ?? p.assessed_at.substring(0,10) }}</span>
          </VCardActions>
        </VCard>
      </VCol>

      <VCol cols="12" v-if="profiles.last_page > 1">
        <div class="d-flex justify-center">
          <VPagination :model-value="profiles.current_page" :length="profiles.last_page" @update:model-value="page => router.get('/attendance/workforce/burnout-profiles', { page }, { preserveState: true })" />
        </div>
      </VCol>
    </VRow>
  </VContainer>
</template>
