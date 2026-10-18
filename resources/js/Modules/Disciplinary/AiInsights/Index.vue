<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface RiskScore {
  employee_id: number; employee_name: string
  risk_score: number; risk_level: string; risk_color: string
  total_cases: number; is_repeat: boolean
  last_case_date: string|null; analyzed_at: string|null
}
interface BehaviorPattern {
  id: number; employee_id: number; employee_name: string
  pattern_type: string; pattern_label: string; description: string
  severity: string; severity_color: string; icon: string; detected_at: string
}
interface DeptTrend { department: string; case_count: number }
interface Stats {
  critical_risk: number; high_risk: number; repeat_offenders: number
  active_patterns: number; total_analyzed: number
}

const props = defineProps<{
  riskScores:       RiskScore[]
  patterns:         BehaviorPattern[]
  departmentTrends: DeptTrend[]
  stats:            Stats
}>()

const tab           = ref('risk')
const analyzing     = ref(false)
const analyzeForm   = useForm({})

function runAnalysis() {
  analyzing.value = true
  analyzeForm.post(route('disciplinary.ai-insights.analyze'), {
    onFinish: () => { analyzing.value = false },
  })
}

const riskLevelOrder = { critical: 0, high: 1, medium: 2, low: 3 }
</script>

<template>
  <div class="pa-4">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <p class="text-overline text-medium-emphasis mb-0">{{ t('disciplinary.module_label') }}</p>
        <h1 class="text-h5 font-weight-bold">{{ t('disciplinary.ai_insights') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-0">{{ t('disciplinary.ai_insights_subtitle') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-brain" :loading="analyzing" @click="runAnalysis">
        {{ t('disciplinary.run_analysis') }}
      </VBtn>
    </div>

    <!-- Stat strip -->
    <VRow dense class="mb-4">
      <VCol cols="6" sm="4" md="auto">
        <VCard variant="tonal" color="error" class="pa-3 text-center">
          <div class="text-h4 font-weight-bold">{{ stats.critical_risk }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('disciplinary.critical_risk') }}</div>
        </VCard>
      </VCol>
      <VCol cols="6" sm="4" md="auto">
        <VCard variant="tonal" color="deep-orange" class="pa-3 text-center">
          <div class="text-h4 font-weight-bold">{{ stats.high_risk }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('disciplinary.high_risk') }}</div>
        </VCard>
      </VCol>
      <VCol cols="6" sm="4" md="auto">
        <VCard variant="tonal" color="warning" class="pa-3 text-center">
          <div class="text-h4 font-weight-bold">{{ stats.repeat_offenders }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('disciplinary.repeat_offenders') }}</div>
        </VCard>
      </VCol>
      <VCol cols="6" sm="4" md="auto">
        <VCard variant="tonal" color="primary" class="pa-3 text-center">
          <div class="text-h4 font-weight-bold">{{ stats.active_patterns }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('disciplinary.active_patterns') }}</div>
        </VCard>
      </VCol>
      <VCol cols="6" sm="4" md="auto">
        <VCard variant="tonal" color="grey" class="pa-3 text-center">
          <div class="text-h4 font-weight-bold">{{ stats.total_analyzed }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('disciplinary.total_analyzed') }}</div>
        </VCard>
      </VCol>
    </VRow>

    <!-- Tabs -->
    <VCard>
      <VTabs v-model="tab" color="primary">
        <VTab value="risk">{{ t('disciplinary.risk_scores') }} <VChip class="ml-2" size="x-small">{{ riskScores.length }}</VChip></VTab>
        <VTab value="patterns">{{ t('disciplinary.behavior_patterns') }} <VChip class="ml-2" size="x-small">{{ patterns.length }}</VChip></VTab>
        <VTab value="departments">{{ t('disciplinary.department_trends') }}</VTab>
      </VTabs>
      <VDivider />

      <VTabsWindow v-model="tab">

        <!-- Risk Scores tab -->
        <VTabsWindowItem value="risk">
          <VCardText class="pa-0">
            <VDataTable
              :items="riskScores"
              :headers="[
                { title: t('disciplinary.employee'), key: 'employee_name' },
                { title: t('disciplinary.risk_score'), key: 'risk_score', align: 'center' },
                { title: t('disciplinary.risk_level'), key: 'risk_level', align: 'center' },
                { title: t('disciplinary.total_cases'), key: 'total_cases', align: 'center' },
                { title: t('disciplinary.repeat_offender'), key: 'is_repeat', align: 'center' },
                { title: t('disciplinary.last_case'), key: 'last_case_date', align: 'end' },
              ]"
              density="compact"
              hover
            >
              <template #item.risk_score="{ item }">
                <div class="d-flex align-center ga-2">
                  <VProgressLinear
                    :model-value="item.risk_score"
                    :color="item.risk_color"
                    height="6"
                    rounded
                    style="width:80px"
                  />
                  <span class="text-body-2 font-weight-medium">{{ item.risk_score }}</span>
                </div>
              </template>
              <template #item.risk_level="{ item }">
                <VChip :color="item.risk_color" size="small">{{ item.risk_level }}</VChip>
              </template>
              <template #item.is_repeat="{ item }">
                <VIcon v-if="item.is_repeat" color="error">tabler-repeat</VIcon>
                <VIcon v-else color="grey">tabler-minus</VIcon>
              </template>
            </VDataTable>
          </VCardText>
        </VTabsWindowItem>

        <!-- Behavior Patterns tab -->
        <VTabsWindowItem value="patterns">
          <VCardText>
            <div v-if="!patterns.length" class="text-center py-8 text-medium-emphasis">
              <VIcon size="48" class="mb-2">tabler-circle-check</VIcon>
              <p>{{ t('disciplinary.no_active_patterns') }}</p>
            </div>
            <VRow dense>
              <VCol v-for="p in patterns" :key="p.id" cols="12" md="6">
                <VCard variant="outlined" class="pa-3">
                  <div class="d-flex align-start ga-3">
                    <VAvatar :color="p.severity_color" size="36" rounded>
                      <VIcon size="18" color="white">{{ p.icon }}</VIcon>
                    </VAvatar>
                    <div class="flex-1-1">
                      <div class="d-flex align-center justify-space-between">
                        <span class="font-weight-medium text-body-2">{{ p.pattern_label }}</span>
                        <VChip :color="p.severity_color" size="x-small">{{ p.severity }}</VChip>
                      </div>
                      <div class="text-caption text-medium-emphasis mb-1">{{ p.employee_name }}</div>
                      <div class="text-caption">{{ p.description }}</div>
                    </div>
                  </div>
                  <div class="d-flex justify-end mt-2">
                    <VBtn
                      size="x-small" variant="tonal" color="primary"
                      @click="useForm({}).post(route('disciplinary.ai-insights.acknowledge-pattern', { pattern: p.id }))"
                    >
                      {{ t('disciplinary.acknowledge') }}
                    </VBtn>
                  </div>
                </VCard>
              </VCol>
            </VRow>
          </VCardText>
        </VTabsWindowItem>

        <!-- Department Trends tab -->
        <VTabsWindowItem value="departments">
          <VCardText>
            <div v-if="!departmentTrends.length" class="text-center py-8 text-medium-emphasis">
              <VIcon size="48" class="mb-2">tabler-building</VIcon>
              <p>{{ t('disciplinary.no_department_data') }}</p>
            </div>
            <VRow dense>
              <VCol v-for="d in departmentTrends" :key="d.department" cols="12" md="6">
                <VCard variant="outlined" class="pa-3">
                  <div class="d-flex align-center justify-space-between mb-2">
                    <span class="font-weight-medium">{{ d.department }}</span>
                    <VChip size="small" color="primary">{{ d.case_count }} {{ t('disciplinary.cases') }}</VChip>
                  </div>
                  <VProgressLinear
                    :model-value="(d.case_count / (departmentTrends[0]?.case_count || 1)) * 100"
                    color="primary" height="8" rounded
                  />
                </VCard>
              </VCol>
            </VRow>
          </VCardText>
        </VTabsWindowItem>

      </VTabsWindow>
    </VCard>
  </div>
</template>
