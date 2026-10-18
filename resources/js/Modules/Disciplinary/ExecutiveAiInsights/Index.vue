<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface AiSummary {
  id: number; summary_type: string; summary: string
  recommendation: string[]; ai_metadata: Record<string, any>
  ai_model: string; acknowledged: boolean; generated_at: string|null
}

const props = defineProps<{
  summaries: Record<string, AiSummary>
}>()

const generating = ref<string|null>(null)
const generateAllForm = useForm({})

const reports = [
  { key: 'executive_summary',      label: t('disciplinary_executive-ai.reports.executive_summary'),           icon: 'tabler-briefcase',      color: 'primary',      route: 'disciplinary.executive-ai.summary' },
  { key: 'misconduct_trend',       label: t('disciplinary_executive-ai.reports.misconduct_trend'),            icon: 'tabler-chart-line',              color: 'deep-orange',  route: 'disciplinary.executive-ai.trend-analysis' },
  { key: 'compliance_insights',    label: t('disciplinary_executive-ai.reports.compliance_insights'),         icon: 'tabler-shield-check',            color: 'success',      route: 'disciplinary.executive-ai.compliance-insights' },
  { key: 'behavioral_risk',        label: t('disciplinary_executive-ai.reports.behavioral_risk'),             icon: 'tabler-brain',                   color: 'warning',      route: 'disciplinary.executive-ai.behavioral-risk' },
  { key: 'corrective_effectiveness', label: t('disciplinary_executive-ai.reports.corrective_effectiveness'),  icon: 'tabler-clipboard-check',  color: 'teal',         route: 'disciplinary.executive-ai.corrective-report' },
]

function generateReport(routeName: string, key: string) {
  generating.value = key
  useForm({}).post(route(routeName), {
    onSuccess: () => { generating.value = null },
    onError:   () => { generating.value = null },
  })
}

function generateAll() {
  generating.value = 'all'
  generateAllForm.post(route('disciplinary.executive-ai.generate-all'), {
    onSuccess: () => { generating.value = null },
    onError:   () => { generating.value = null },
  })
}

const expandedCard = ref<string|null>(null)

const riskBadgeColor = (meta: Record<string, any>) => {
  const r = meta?.risk_rating ?? meta?.overall_risk ?? meta?.overall_effectiveness
  return r === 'critical' || r === 'low' ? 'error'
       : r === 'high'     || r === 'low' ? 'warning'
       : r === 'moderate'                ? 'info'
       : 'success'
}

const riskBadge = (meta: Record<string, any>) =>
  meta?.risk_rating ?? meta?.overall_risk ?? meta?.overall_effectiveness
  ?? (meta?.overall_compliance_score !== undefined ? `${meta.overall_compliance_score}% compliance` : null)
</script>

<template>
  <div class="pa-6">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('disciplinary_executive-ai.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('disciplinary_executive-ai.subtitle') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-robot" :loading="generating === 'all'" @click="generateAll">
        {{ t('disciplinary_executive-ai.generate_all') }}
      </VBtn>
    </div>

    <!-- Report Cards -->
    <VRow dense>
      <VCol cols="12" v-for="report in reports" :key="report.key">
        <VCard>
          <VCardTitle class="pa-4 d-flex align-center justify-space-between flex-wrap ga-2">
            <div class="d-flex align-center ga-2">
              <VIcon :color="report.color">{{ report.icon }}</VIcon>
              <span class="text-body-1 font-weight-bold">{{ report.label }}</span>
              <VChip
                v-if="summaries[report.key]"
                :color="riskBadgeColor(summaries[report.key].ai_metadata)"
                size="x-small" variant="tonal"
              >{{ riskBadge(summaries[report.key].ai_metadata) }}</VChip>
            </div>
            <div class="d-flex align-center ga-2">
              <span v-if="summaries[report.key]" class="text-caption text-medium-emphasis">
                {{ t('disciplinary_executive-ai.generated_at', { date: summaries[report.key].generated_at }) }}
              </span>
              <VBtn
                size="small" :color="report.color" variant="tonal"
                :loading="generating === report.key"
                prepend-icon="tabler-refresh"
                @click="generateReport(report.route, report.key)"
              >{{ t('disciplinary_executive-ai.regenerate') }}</VBtn>
              <VBtn
                v-if="summaries[report.key]"
                size="small" variant="text"
                :icon="expandedCard === report.key ? 'tabler-chevron-up' : 'tabler-chevron-down'"
                @click="expandedCard = expandedCard === report.key ? null : report.key"
              />
            </div>
          </VCardTitle>

          <template v-if="!summaries[report.key]">
            <VDivider />
            <VCardText class="text-center text-medium-emphasis pa-6">
              <VIcon size="40" class="mb-2 opacity-40">tabler-robot</VIcon>
              <div>{{ t('disciplinary_executive-ai.no_analysis') }}</div>
              <div class="text-caption">{{ t('disciplinary_executive-ai.no_analysis_hint') }}</div>
            </VCardText>
          </template>

          <template v-else>
            <VDivider />
            <!-- Summary preview -->
            <VCardText class="pa-4">
              <p class="text-body-2" style="white-space: pre-wrap; max-height:100px; overflow:hidden; text-overflow:ellipsis">
                {{ summaries[report.key].summary }}
              </p>
            </VCardText>

            <!-- Expanded detail -->
            <VExpandTransition>
              <div v-if="expandedCard === report.key">
                <VDivider />
                <VCardText class="pa-4">
                  <p class="text-body-2 mb-4" style="white-space: pre-wrap">{{ summaries[report.key].summary }}</p>

                  <template v-if="summaries[report.key].recommendation?.length">
                    <div class="text-body-2 font-weight-bold mb-2">{{ t('disciplinary_executive-ai.recommendations') }}</div>
                    <VList density="compact" class="mb-4">
                      <VListItem
                        v-for="(rec, i) in summaries[report.key].recommendation"
                        :key="i"
                        :prepend-icon="'tabler-number-' + (i+1) + '-small'"
                      >
                        <VListItemTitle class="text-body-2 text-wrap">{{ typeof rec === 'string' ? rec : JSON.stringify(rec) }}</VListItemTitle>
                      </VListItem>
                    </VList>
                  </template>

                  <div class="d-flex flex-wrap ga-2">
                    <template v-for="(val, key2) in summaries[report.key].ai_metadata" :key="key2">
                      <VChip
                        v-if="typeof val === 'string' || typeof val === 'number'"
                        size="x-small" variant="outlined" color="grey"
                      >{{ key2 }}: {{ val }}</VChip>
                    </template>
                    <VChip size="x-small" variant="outlined" color="grey-lighten-1">
                      {{ t('disciplinary_executive-ai.model') }} {{ summaries[report.key].ai_model }}
                    </VChip>
                  </div>
                </VCardText>
              </div>
            </VExpandTransition>
          </template>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
