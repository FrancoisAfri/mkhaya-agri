<script setup lang="ts">
import { computed } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface BehaviorProfile {
  id: number; period_start: string|null; period_end: string|null
  overall_risk_score: number; risk_level: string
  attendance_score: number; performance_score: number
  disciplinary_score: number; compliance_score: number
  active_warnings: number; total_cases_ytd: number
  repeated_offences: number; late_arrivals: number
  unexcused_absences: number; risk_factors: string[]|null
  ai_narrative: string|null; is_ai_generated: boolean; generated_at: string|null
}

const props = defineProps<{
  employee_id: number
  profiles: BehaviorProfile[]
}>()

const riskColor = (l: string) => ({ low: 'success', medium: 'warning', high: 'error', critical: 'red' })[l] ?? 'grey'
const cap = (s: string|null|undefined) => s ? s.charAt(0).toUpperCase() + s.slice(1) : '—'

const latest = computed(() => props.profiles[0] ?? null)

const scoreGauges = computed(() => latest.value ? [
  { label: t('disciplinary_workforce-intelligence.show.score_gauge.attendance'),   value: latest.value.attendance_score,   color: 'primary' },
  { label: t('disciplinary_workforce-intelligence.show.score_gauge.performance'),  value: latest.value.performance_score,  color: 'success' },
  { label: t('disciplinary_workforce-intelligence.show.score_gauge.disciplinary'), value: latest.value.disciplinary_score, color: 'warning' },
  { label: t('disciplinary_workforce-intelligence.show.score_gauge.compliance'),   value: latest.value.compliance_score,   color: 'info' },
] : [])
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-5 flex-wrap ga-2">
      <div>
        <p class="text-overline text-medium-emphasis mb-0">{{ t('disciplinary_workforce-intelligence.show.breadcrumb') }}</p>
        <h1 class="text-h5 font-weight-bold">{{ t('disciplinary_workforce-intelligence.show.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('disciplinary_workforce-intelligence.show.subtitle') }}</p>
      </div>
      <VBtn variant="outlined" prepend-icon="tabler-arrow-left" :href="route('disciplinary.workforce-intelligence.index')">
        {{ t('disciplinary_workforce-intelligence.show.back') }}
      </VBtn>
    </div>

    <VAlert v-if="!profiles.length" type="info" variant="tonal" class="mb-4">
      {{ t('disciplinary_workforce-intelligence.show.no_data') }}
      <template #append>
        <VBtn size="small" variant="tonal" :href="route('disciplinary.workforce-intelligence.index')">
          {{ t('disciplinary_workforce-intelligence.show.generate_link') }}
        </VBtn>
      </template>
    </VAlert>

    <template v-else>
      <VCard class="mb-4">
        <VCardTitle class="text-body-1 font-weight-semibold pa-4 pb-2 d-flex justify-space-between align-center">
          <span><VIcon start>tabler-chart-bar</VIcon>{{ t('disciplinary_workforce-intelligence.show.latest_assessment') }} — {{ latest?.period_end }}</span>
          <VChip :color="riskColor(latest?.risk_level ?? '')" size="small">{{ cap(latest?.risk_level) }} Risk</VChip>
        </VCardTitle>
        <VCardText>
          <div class="d-flex align-center ga-4 mb-4">
            <VProgressCircular :model-value="latest?.overall_risk_score ?? 0" :color="riskColor(latest?.risk_level ?? '')" size="72" width="8">
              <span class="text-h6 font-weight-bold">{{ latest?.overall_risk_score }}</span>
            </VProgressCircular>
            <div>
              <div class="text-body-1 font-weight-medium">{{ t('disciplinary_workforce-intelligence.show.risk_score') }}</div>
              <div class="text-caption text-medium-emphasis">Period: {{ latest?.period_start }} – {{ latest?.period_end }}</div>
              <div class="text-caption text-medium-emphasis mt-1">
                {{ latest?.active_warnings }} {{ t('disciplinary_workforce-intelligence.show.active_warnings_label') }} ·
                {{ latest?.total_cases_ytd }} {{ t('disciplinary_workforce-intelligence.show.cases_ytd_label') }} ·
                {{ latest?.repeated_offences }} {{ t('disciplinary_workforce-intelligence.show.repeat_offences_label') }}
              </div>
            </div>
          </div>

          <VRow dense>
            <VCol v-for="g in scoreGauges" :key="g.label" cols="6" sm="3">
              <div class="text-center">
                <VProgressCircular :model-value="g.value * 10" :color="g.color" size="56" width="6">
                  <span class="text-body-2 font-weight-bold">{{ g.value }}</span>
                </VProgressCircular>
                <div class="text-caption mt-1">{{ g.label }}</div>
              </div>
            </VCol>
          </VRow>

          <div v-if="latest?.risk_factors?.length" class="mt-4">
            <div class="text-caption text-medium-emphasis font-weight-medium mb-2">{{ t('disciplinary_workforce-intelligence.show.risk_factors') }}</div>
            <div class="d-flex flex-wrap ga-1">
              <VChip v-for="f in latest.risk_factors" :key="f" color="error" size="x-small" variant="tonal">{{ f }}</VChip>
            </div>
          </div>

          <VCard v-if="latest?.ai_narrative" variant="tonal" color="primary" class="pa-3 mt-4">
            <div class="d-flex align-center ga-2 mb-2">
              <VIcon color="primary" size="18">tabler-robot</VIcon>
              <span class="text-caption font-weight-medium">{{ t('disciplinary_workforce-intelligence.show.ai_analysis') }}</span>
              <VChip size="x-small" color="primary">{{ t('disciplinary_workforce-intelligence.show.ai_generated') }}</VChip>
            </div>
            <div class="text-body-2">{{ latest.ai_narrative }}</div>
          </VCard>
        </VCardText>
      </VCard>

      <VCard>
        <VCardTitle class="text-body-1 font-weight-semibold pa-4 pb-2">
          <VIcon start>tabler-history</VIcon>{{ t('disciplinary_workforce-intelligence.show.assessment_history') }} ({{ profiles.length }})
        </VCardTitle>
        <VCardText class="pa-0">
          <VList density="compact" class="px-2 pb-2">
            <VListItem v-for="p in profiles" :key="p.id" class="mb-1">
              <template #prepend>
                <VChip :color="riskColor(p.risk_level)" size="small" class="mr-3">{{ cap(p.risk_level) }}</VChip>
              </template>
              <VListItemTitle class="text-body-2">{{ p.period_start }} – {{ p.period_end }}</VListItemTitle>
              <VListItemSubtitle class="text-caption">
                Score: {{ p.overall_risk_score }} · {{ p.active_warnings }} warnings · {{ p.total_cases_ytd }} cases
              </VListItemSubtitle>
              <template #append>
                <VChip v-if="p.is_ai_generated" color="primary" size="x-small" variant="tonal">AI</VChip>
              </template>
            </VListItem>
          </VList>
        </VCardText>
      </VCard>
    </template>
  </div>
</template>
