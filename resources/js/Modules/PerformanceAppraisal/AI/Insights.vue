<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Cycle = { id: number; name: string; status: string }
type Insight = {
  id: number
  employee_id: number
  employee_name: string
  cycle_id: number | null
  cycle_name: string | null
  insight_type: string
  severity: 'info' | 'warning' | 'critical'
  title: string
  body: string
  data: Record<string, unknown> | null
  acknowledged_at: string | null
  created_at: string
}

const props = defineProps<{
  insights: Insight[]
  cycles: Cycle[]
  selectedCycleId: number | null
  summary: { total: number; critical: number; warning: number; unread: number }
}>()

const selectedCycle = ref<number | null>(props.selectedCycleId)
const analysing = ref(false)

function filterByCycle() {
  router.get(route('performance.ai.index'), { cycle_id: selectedCycle.value }, { preserveState: true, replace: true })
}

function acknowledge(insight: Insight) {
  router.patch(route('performance.ai.acknowledge', insight.id), {}, { preserveScroll: true })
}

function runAnalysis() {
  if (!selectedCycle.value) return
  analysing.value = true
  router.post(route('performance.ai.analyse', selectedCycle.value), {}, {
    preserveScroll: true,
    onFinish: () => { analysing.value = false },
  })
}

function runBiasDetection() {
  if (!selectedCycle.value) return
  router.post(route('performance.ai.bias', selectedCycle.value), {}, { preserveScroll: true })
}

function runInconsistency() {
  if (!selectedCycle.value) return
  router.post(route('performance.ai.inconsistency', selectedCycle.value), {}, { preserveScroll: true })
}

const severityColor = (s: string) => ({ info: 'info', warning: 'warning', critical: 'error' })[s] ?? 'default'
const severityIcon  = (s: string) => ({ info: 'ri-information-line', warning: 'ri-alert-line', critical: 'ri-error-warning-line' })[s] ?? 'ri-lightbulb-line'

const insightTypeLabel = (type: string) => {
  const labels: Record<string, string> = {
    declining_performance: t('pa_ai.insight_types.declining_performance'),
    exceptional_performance: t('pa_ai.insight_types.exceptional_performance'),
    inconsistent_scoring: t('pa_ai.insight_types.inconsistent_scoring'),
    scoring_bias: t('pa_ai.insight_types.scoring_bias'),
    kpi_at_risk: t('pa_ai.insight_types.kpi_at_risk'),
    trend_analysis: t('pa_ai.insight_types.trend_analysis'),
  }
  return labels[type] ?? type.replace(/_/g, ' ')
}
</script>

<template>
  <div class="pa-4">
    <!-- Header -->
    <div class="d-flex align-center mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('pa_ai.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">{{ t('pa_ai.subtitle') }}</p>
      </div>
      <VSpacer />
      <VSelect
        v-model="selectedCycle"
        :items="[{ id: null, name: 'All Cycles', status: '' }, ...props.cycles]"
        item-title="name" item-value="id"
        density="compact" style="max-width:220px" class="mr-3"
        @update:model-value="filterByCycle"
      />
    </div>

    <!-- KPI strip -->
    <VRow class="mb-4">
      <VCol cols="6" sm="3">
        <VCard variant="tonal" color="primary">
          <VCardText class="text-center py-3">
            <div class="text-h5 font-weight-bold">{{ props.summary.total }}</div>
            <div class="text-caption">{{ t('pa_ai.stats.total_insights') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard variant="tonal" color="error">
          <VCardText class="text-center py-3">
            <div class="text-h5 font-weight-bold">{{ props.summary.critical }}</div>
            <div class="text-caption">{{ t('pa_ai.stats.critical') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard variant="tonal" color="warning">
          <VCardText class="text-center py-3">
            <div class="text-h5 font-weight-bold">{{ props.summary.warning }}</div>
            <div class="text-caption">{{ t('pa_ai.stats.warnings') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard variant="tonal" color="info">
          <VCardText class="text-center py-3">
            <div class="text-h5 font-weight-bold">{{ props.summary.unread }}</div>
            <div class="text-caption">{{ t('pa_ai.stats.unread') }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Analysis actions -->
    <VCard class="mb-4">
      <VCardTitle class="text-subtitle-2 font-weight-medium">{{ t('pa_ai.run_analysis') }}</VCardTitle>
      <VCardText class="d-flex flex-wrap gap-3">
        <VBtn
          color="deep-purple" prepend-icon="ri-brain-line" variant="tonal"
          :loading="analysing" :disabled="!selectedCycle"
          @click="runAnalysis"
        >
          {{ t('pa_ai.actions.ai_trend') }}
        </VBtn>
        <VBtn
          color="orange" prepend-icon="ri-scales-line" variant="tonal" :disabled="!selectedCycle"
          @click="runBiasDetection"
        >
          {{ t('pa_ai.actions.detect_bias') }}
        </VBtn>
        <VBtn
          color="blue" prepend-icon="ri-shuffle-line" variant="tonal" :disabled="!selectedCycle"
          @click="runInconsistency"
        >
          {{ t('pa_ai.actions.detect_inconsistency') }}
        </VBtn>
      </VCardText>
    </VCard>

    <!-- Insights list -->
    <div v-if="props.insights.length === 0" class="text-center text-medium-emphasis pa-8">
      <VIcon icon="ri-brain-line" size="48" class="mb-3 d-block mx-auto" />
      <p>{{ t('pa_ai.empty') }}</p>
    </div>

    <VCard v-for="insight in props.insights" :key="insight.id" class="mb-3"
      :class="insight.acknowledged_at ? 'opacity-60' : ''">
      <VCardText>
        <div class="d-flex align-start gap-3">
          <VIcon :icon="severityIcon(insight.severity)" :color="severityColor(insight.severity)" size="24" class="mt-1" />
          <div class="flex-1">
            <div class="d-flex align-center gap-2 mb-1">
              <VChip size="x-small" :color="severityColor(insight.severity)" label>{{ insight.severity }}</VChip>
              <VChip size="x-small" variant="tonal" label>{{ insightTypeLabel(insight.insight_type) }}</VChip>
              <span class="text-caption text-medium-emphasis ml-auto">{{ insight.created_at }}</span>
            </div>
            <p class="font-weight-medium mb-1">{{ insight.title }}</p>
            <p class="text-body-2 text-medium-emphasis mb-2">{{ insight.body }}</p>
            <div class="d-flex align-center gap-2 text-caption text-medium-emphasis">
              <VIcon icon="ri-user-line" size="14" />
              <span>{{ insight.employee_name }}</span>
              <span v-if="insight.cycle_name">· {{ insight.cycle_name }}</span>
            </div>

            <!-- Supporting data -->
            <VExpansionPanels v-if="insight.data && Object.keys(insight.data).length > 0" variant="accordion" class="mt-2">
              <VExpansionPanel :title="t('pa_ai.supporting_data')">
                <VExpansionPanelText>
                  <pre class="text-caption pa-2 bg-surface rounded" style="overflow-x:auto">{{ JSON.stringify(insight.data, null, 2) }}</pre>
                </VExpansionPanelText>
              </VExpansionPanel>
            </VExpansionPanels>
          </div>

          <div class="d-flex flex-column gap-1">
            <VBtn
              v-if="!insight.acknowledged_at"
              size="small" variant="tonal" color="success"
              @click="acknowledge(insight)"
            >
              {{ t('pa_ai.acknowledge') }}
            </VBtn>
            <span v-else class="text-caption text-success">
              <VIcon icon="ri-check-line" size="14" /> {{ t('pa_ai.acknowledged') }}
            </span>
          </div>
        </div>
      </VCardText>
    </VCard>
  </div>
</template>
