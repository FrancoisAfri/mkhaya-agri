<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type DeptScore = {
  id: number; department: string; wellness_score: number
  risk_level: string; risk_color: string; headcount: number
  high_risk_count: number; open_alerts: number
  avg_absenteeism: number; avg_overtime: number
  top_issues: string[]; ai_summary: string | null
  period_start: string; period_end: string
}

const props = defineProps<{
  scores:      DeptScore[]
  departments: string[]
  filters:     { period_start: string; period_end: string }
}>()

const subNav      = ref('dept-wellness')
const filterStart = ref(props.filters.period_start)
const filterEnd   = ref(props.filters.period_end)

function applyFilter() { router.get('/attendance/wellness/departments', { period_start: filterStart.value, period_end: filterEnd.value }, { preserveState: true }) }
function scoreColor(score: number): string { if (score >= 80) return 'success'; if (score >= 60) return 'warning'; if (score >= 40) return 'error'; return 'deep-purple' }
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <VCol cols="12" class="pb-0">
        <VCard variant="outlined">
          <VTabs v-model="subNav" color="primary" density="compact">
            <VTab value="workforce" href="/attendance/workforce"><VIcon start size="16" icon="tabler-account-group" />{{ t('attendance_wellness.tab_overview') }}</VTab>
            <VTab value="burnout" href="/attendance/workforce/burnout-profiles"><VIcon start size="16" icon="tabler-fire" />{{ t('attendance_wellness.tab_burnout') }}</VTab>
            <VTab value="predictions" href="/attendance/workforce/predictions"><VIcon start size="16" icon="tabler-crystal-ball" />{{ t('attendance_wellness.tab_predictions') }}</VTab>
            <VTab value="wellness" href="/attendance/wellness"><VIcon start size="16" icon="tabler-heart-pulse" />{{ t('attendance_wellness.tab_wellness') }}</VTab>
            <VTab value="dept-wellness" href="/attendance/wellness/departments"><VIcon start size="16" icon="tabler-domain" />{{ t('attendance_wellness.tab_dept_wellness') }}</VTab>
          </VTabs>
        </VCard>
      </VCol>
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div>
              <div class="text-overline text-medium-emphasis">{{ t('attendance_wellness.breadcrumb_module') }}</div>
              <h4 class="text-h4 mb-1">{{ t('attendance_wellness.dept_title') }}</h4>
              <p class="mb-0 text-medium-emphasis">{{ t('attendance_wellness.dept_subtitle') }}</p>
            </div>
            <VBtn variant="text" color="primary" href="/attendance/wellness"><i class="fas fa-arrow-left me-2"></i>{{ t('attendance_wellness.wellness_alerts_back') }}</VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex gap-3 py-3">
            <VTextField v-model="filterStart" :label="t('attendance_wellness.filter_from')" type="date" density="compact" hide-details style="max-width:160px" />
            <VTextField v-model="filterEnd" :label="t('attendance_wellness.filter_to')" type="date" density="compact" hide-details style="max-width:160px" />
            <VBtn color="primary" @click="applyFilter">{{ t('attendance_wellness.filter_btn') }}</VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <VCol v-if="!scores.length" cols="12"><VAlert type="info" variant="tonal">{{ t('attendance_wellness.no_dept_scores') }}</VAlert></VCol>

      <VCol cols="12" md="6" xl="4" v-for="s in scores" :key="s.id">
        <VCard height="100%">
          <VCardTitle class="py-3 px-5 d-flex align-center justify-space-between">
            <div class="d-flex align-center gap-2"><VAvatar :color="s.risk_color" variant="tonal" size="36"><i class="fas fa-building" style="font-size:14px"></i></VAvatar><span class="text-body-2 font-weight-medium">{{ s.department }}</span></div>
            <VChip :color="s.risk_color" size="small" variant="tonal" class="text-capitalize">{{ s.risk_level }}</VChip>
          </VCardTitle>
          <VDivider />
          <VCardText class="py-3">
            <div class="d-flex align-center gap-3 mb-4">
              <VProgressCircular :model-value="s.wellness_score" :color="scoreColor(s.wellness_score)" size="64" width="6"><span class="text-body-2 font-weight-bold">{{ s.wellness_score }}</span></VProgressCircular>
              <div><div class="text-body-2 font-weight-medium">{{ t('attendance_wellness.wellness_score') }}</div><div class="text-caption text-medium-emphasis">{{ s.period_start }} – {{ s.period_end }}</div></div>
            </div>
            <div class="d-flex justify-space-between mb-2"><span class="text-caption text-medium-emphasis">{{ t('attendance_wellness.headcount') }}</span><span class="text-caption font-weight-medium">{{ s.headcount }}</span></div>
            <div class="d-flex justify-space-between mb-2"><span class="text-caption text-medium-emphasis">{{ t('attendance_wellness.high_risk_employees') }}</span><VChip :color="s.high_risk_count > 0 ? 'error' : 'success'" size="x-small" variant="tonal">{{ s.high_risk_count }}</VChip></div>
            <div class="d-flex justify-space-between mb-2"><span class="text-caption text-medium-emphasis">{{ t('attendance_wellness.open_alerts_metric') }}</span><VChip :color="s.open_alerts > 3 ? 'warning' : 'secondary'" size="x-small" variant="tonal">{{ s.open_alerts }}</VChip></div>
            <div class="d-flex justify-space-between mb-2"><span class="text-caption text-medium-emphasis">{{ t('attendance_wellness.avg_absenteeism') }}</span><VChip :color="s.avg_absenteeism >= 15 ? 'error' : s.avg_absenteeism >= 8 ? 'warning' : 'success'" size="x-small" variant="tonal">{{ s.avg_absenteeism }}%</VChip></div>
            <div class="d-flex justify-space-between mb-3"><span class="text-caption text-medium-emphasis">{{ t('attendance_wellness.avg_overtime') }}</span><VChip :color="s.avg_overtime >= 10 ? 'error' : 'secondary'" size="x-small" variant="tonal">{{ s.avg_overtime }}{{ t('attendance_wellness.hours_suffix') }}</VChip></div>
            <div v-if="s.top_issues?.length" class="mb-3"><div class="text-caption font-weight-medium mb-1">{{ t('attendance_wellness.top_issues') }}</div><div v-for="issue in s.top_issues" :key="issue" class="d-flex align-center gap-1 text-caption mb-1"><i class="fas fa-exclamation-circle text-warning" style="font-size:10px"></i>{{ issue }}</div></div>
            <VAlert v-if="s.ai_summary" type="info" variant="tonal" density="compact" style="font-size:0.8rem"><i class="fas fa-robot me-1"></i>{{ s.ai_summary }}</VAlert>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </VContainer>
</template>
