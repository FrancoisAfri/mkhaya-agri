<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Prediction {
  id: number; employee_id: number; employee: string
  prediction_type: string; probability: number; risk_level: string
  prediction_window_end: string; reasoning: string
  contributing_factors: Array<{factor: string; value: number; weight: number}>
  recommended_actions: string[]; actioned: boolean
  is_ai_generated: boolean; created_at: string
}
interface DeptRisk {
  department: string; total_cases: number; risk_score: number
  risk_level: string; trend: string; ai_narrative: string|null
}
interface Heatmap {
  id: number; type: string; title: string
  period_start: string; period_end: string
  data_points: Array<{label: string; value: number; risk_level: string}>
  legend: Array<{label: string; color: string}>|null
}
interface Stats {
  critical_predictions: number; high_predictions: number
  actioned: number; dept_high_risk: number; pending_predictions: number
}
interface Employee { id: number; name: string; email: string }
interface Department { id: number; name: string }
interface PredictionResult {
  employee: string; prediction_type: string; risk_level: string
  probability: number; reasoning: string; recommended_actions: string[]
}

const props = defineProps<{
  predictions: Prediction[]
  dept_risks: DeptRisk[]
  heatmaps: Heatmap[]
  stats: Stats
  period_start: string; period_end: string
  employees: Employee[]
  departments: Department[]
}>()

const page = usePage()

const predictDialog = ref(false)
const deptDialog    = ref(false)
const heatmapDialog = ref(false)
const resultDialog  = ref(false)
const tab           = ref('predictions')
const riskFilter    = ref<string|null>(null)

const predictionResult = ref<PredictionResult|null>(null)

watch(() => (page.props as any).flash?.prediction_result, (val) => {
  if (val) {
    predictionResult.value = val
    resultDialog.value = true
  }
}, { immediate: true })

const filteredPredictions = computed(() =>
  riskFilter.value
    ? props.predictions.filter(p => p.risk_level === riskFilter.value)
    : props.predictions
)

const predictForm = useForm<{ employee_id: number|null; prediction_type: string }>({ employee_id: null, prediction_type: 'behavioral_decline' })
const deptForm    = useForm<{ department_id: number|null; period_start: string; period_end: string }>({ department_id: null, period_start: '', period_end: '' })
const heatmapForm = useForm({ heatmap_type: 'department_risk', period_start: '', period_end: '', year: new Date().getFullYear().toString() })

function submitPredict() {
  predictForm.post(route('disciplinary.risk-intelligence.predict-employee'), {
    onSuccess: () => { predictDialog.value = false; predictForm.reset() },
  })
}
function submitDept() {
  deptForm.post(route('disciplinary.risk-intelligence.analyze-department'), {
    onSuccess: () => { deptDialog.value = false; deptForm.reset() },
  })
}
function submitHeatmap() {
  heatmapForm.post(route('disciplinary.risk-intelligence.heatmap'), {
    onSuccess: () => { heatmapDialog.value = false; heatmapForm.reset() },
  })
}
function actionPrediction(id: number) {
  useForm({}).post(route('disciplinary.risk-intelligence.action-prediction', id))
}

const levelColor = (l: string) => ({ low: 'success', medium: 'warning', high: 'orange', critical: 'error' })[l] ?? 'grey'
const trendIcon  = (t: string) => ({ improving: 'tabler-trending-down', stable: 'tabler-minus', worsening: 'tabler-trending-up' })[t] ?? 'tabler-minus'
const trendColor = (t: string) => ({ improving: 'success', stable: 'blue', worsening: 'error' })[t] ?? 'grey'
const levelIcon  = (l: string) => ({ low: 'tabler-shield-check', medium: 'tabler-shield-exclamation', high: 'tabler-shield-off', critical: 'tabler-alert-octagon' })[l] ?? 'tabler-question-mark'

const predictionTypes = [
  { value: 'behavioral_decline', title: t('disciplinary_risk-intelligence.prediction_types.behavioral_decline') },
  { value: 'dismissal_risk', title: t('disciplinary_risk-intelligence.prediction_types.dismissal_risk') },
  { value: 'repeat_offence', title: t('disciplinary_risk-intelligence.prediction_types.repeat_offence') },
  { value: 'escalation', title: t('disciplinary_risk-intelligence.prediction_types.escalation') },
  { value: 'suspension_risk', title: t('disciplinary_risk-intelligence.prediction_types.suspension_risk') },
]

const heatmapTypes = [
  { value: 'department_risk', title: t('disciplinary_risk-intelligence.heatmap_types.department_risk') },
  { value: 'offence_category', title: t('disciplinary_risk-intelligence.heatmap_types.offence_category') },
  { value: 'monthly_trend', title: t('disciplinary_risk-intelligence.heatmap_types.monthly_trend') },
]

const statsLabels: Record<string, string> = {
  critical_predictions: t('disciplinary_risk-intelligence.stats.critical_predictions'),
  high_predictions: t('disciplinary_risk-intelligence.stats.high_predictions'),
  actioned: t('disciplinary_risk-intelligence.stats.actioned'),
  dept_high_risk: t('disciplinary_risk-intelligence.stats.dept_high_risk'),
  pending_predictions: t('disciplinary_risk-intelligence.stats.pending_predictions'),
}
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('disciplinary_risk-intelligence.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('disciplinary_risk-intelligence.subtitle') }}</p>
      </div>
      <div class="d-flex ga-2">
        <VBtn color="primary" variant="tonal" prepend-icon="tabler-building" @click="deptDialog = true">{{ t('disciplinary_risk-intelligence.dept_analysis') }}</VBtn>
        <VBtn color="primary" variant="tonal" prepend-icon="tabler-chart-bubble" @click="heatmapDialog = true">{{ t('disciplinary_risk-intelligence.heatmap') }}</VBtn>
        <VBtn color="primary" prepend-icon="tabler-bolt" @click="predictDialog = true">{{ t('disciplinary_risk-intelligence.predict_risk') }}</VBtn>
      </div>
    </div>

    <VRow class="mb-4">
      <VCol v-for="(val, key) in stats" :key="key" cols="12" sm="4" md="2">
        <VCard variant="tonal" :color="key === 'critical_predictions' ? 'error' : key === 'high_predictions' ? 'orange' : 'primary'">
          <VCardText class="text-center py-3">
            <div class="text-h4 font-weight-bold">{{ val }}</div>
            <div class="text-caption text-uppercase mt-1">{{ statsLabels[key] ?? key }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VTabs v-model="tab" class="mb-4">
      <VTab value="predictions">{{ t('disciplinary_risk-intelligence.tabs.employee_predictions') }}</VTab>
      <VTab value="departments">{{ t('disciplinary_risk-intelligence.tabs.department_risk') }}</VTab>
      <VTab value="heatmaps">{{ t('disciplinary_risk-intelligence.tabs.heatmaps') }}</VTab>
    </VTabs>

    <VWindow v-model="tab">
      <VWindowItem value="predictions">
        <VCard>
          <VCardText class="pb-0">
            <div class="d-flex align-center ga-2 flex-wrap">
              <span class="text-body-2 text-medium-emphasis">{{ t('disciplinary_risk-intelligence.filter') }}</span>
              <VChip :variant="riskFilter === null ? 'elevated' : 'tonal'" size="small" @click="riskFilter = null">{{ t('disciplinary_risk-intelligence.all') }}</VChip>
              <VChip v-for="lvl in ['low','medium','high','critical']" :key="lvl" :color="levelColor(lvl)" :variant="riskFilter === lvl ? 'elevated' : 'tonal'" size="small" @click="riskFilter = lvl">{{ lvl }}</VChip>
            </div>
          </VCardText>
          <VDataTable
            :items="filteredPredictions"
            :headers="[
              { title: t('disciplinary_risk-intelligence.table.employee'), key: 'employee' },
              { title: t('disciplinary_risk-intelligence.table.type'), key: 'prediction_type' },
              { title: t('disciplinary_risk-intelligence.table.probability'), key: 'probability' },
              { title: t('disciplinary_risk-intelligence.table.risk_level'), key: 'risk_level' },
              { title: t('disciplinary_risk-intelligence.table.window_end'), key: 'prediction_window_end' },
              { title: t('disciplinary_risk-intelligence.table.ai'), key: 'is_ai_generated' },
              { title: t('disciplinary_risk-intelligence.table.actioned'), key: 'actioned' },
              { title: t('disciplinary_risk-intelligence.table.actions'), key: '_actions', sortable: false },
            ]"
          >
            <template #item.risk_level="{ item }">
              <VChip :color="levelColor(item.risk_level)" size="small" variant="tonal">{{ item.risk_level }}</VChip>
            </template>
            <template #item.probability="{ item }">
              <VProgressLinear :model-value="item.probability" :color="levelColor(item.risk_level)" height="14" rounded>
                <template #default>{{ Math.round(item.probability) }}%</template>
              </VProgressLinear>
            </template>
            <template #item.prediction_type="{ item }">
              <VChip size="small" color="blue-grey" variant="tonal">{{ item.prediction_type.replace(/_/g, ' ') }}</VChip>
            </template>
            <template #item.is_ai_generated="{ item }">
              <VIcon v-if="item.is_ai_generated" color="blue" size="small">tabler-robot</VIcon>
              <VIcon v-else color="grey" size="small">tabler-user</VIcon>
            </template>
            <template #item.actioned="{ item }">
              <VChip :color="item.actioned ? 'success' : 'warning'" size="small" variant="tonal">{{ item.actioned ? t('disciplinary_risk-intelligence.table.done') : t('disciplinary_risk-intelligence.table.pending') }}</VChip>
            </template>
            <template #item._actions="{ item }">
              <VBtn v-if="!item.actioned" size="small" color="success" variant="tonal" @click="actionPrediction(item.id)">{{ t('disciplinary_risk-intelligence.table.action') }}</VBtn>
            </template>
          </VDataTable>
        </VCard>
      </VWindowItem>

      <VWindowItem value="departments">
        <VCard>
          <VDataTable
            :items="dept_risks"
            :headers="[
              { title: t('disciplinary_risk-intelligence.table.department'), key: 'department' },
              { title: t('disciplinary_risk-intelligence.table.total_cases'), key: 'total_cases' },
              { title: t('disciplinary_risk-intelligence.table.risk_score'), key: 'risk_score' },
              { title: t('disciplinary_risk-intelligence.table.risk_level'), key: 'risk_level' },
              { title: t('disciplinary_risk-intelligence.table.trend'), key: 'trend' },
            ]"
          >
            <template #item.risk_level="{ item }">
              <VChip :color="levelColor(item.risk_level)" size="small" variant="tonal">{{ item.risk_level }}</VChip>
            </template>
            <template #item.risk_score="{ item }">
              <VProgressLinear :model-value="item.risk_score" :color="levelColor(item.risk_level)" height="14" rounded>
                <template #default>{{ Math.round(item.risk_score) }}</template>
              </VProgressLinear>
            </template>
            <template #item.trend="{ item }">
              <VChip :color="trendColor(item.trend)" size="small" variant="tonal" :prepend-icon="trendIcon(item.trend)">{{ item.trend }}</VChip>
            </template>
          </VDataTable>
        </VCard>
      </VWindowItem>

      <VWindowItem value="heatmaps">
        <VRow>
          <VCol v-for="h in heatmaps" :key="h.id" cols="12" md="6">
            <VCard>
              <VCardTitle>{{ h.title }}</VCardTitle>
              <VCardSubtitle>{{ h.period_start }} → {{ h.period_end }}</VCardSubtitle>
              <VCardText>
                <div class="d-flex flex-wrap ga-2">
                  <VChip v-for="dp in h.data_points.slice(0, 15)" :key="dp.label" :color="levelColor(dp.risk_level)" size="small" variant="tonal">
                    {{ dp.label }}: {{ dp.value }}
                  </VChip>
                </div>
              </VCardText>
            </VCard>
          </VCol>
          <VCol v-if="!heatmaps.length" cols="12">
            <VCard>
              <VCardText class="text-center text-medium-emphasis pa-8">{{ t('disciplinary_risk-intelligence.no_heatmaps') }}</VCardText>
            </VCard>
          </VCol>
        </VRow>
      </VWindowItem>
    </VWindow>

    <!-- Prediction Result Dialog -->
    <VDialog v-model="resultDialog" max-width="560" persistent>
      <VCard v-if="predictionResult">
        <VCardTitle class="pa-4 d-flex align-center ga-2">
          <VIcon :color="levelColor(predictionResult.risk_level)">{{ levelIcon(predictionResult.risk_level) }}</VIcon>
          {{ t('disciplinary_risk-intelligence.result.title') }}
          <VSpacer />
          <VChip :color="levelColor(predictionResult.risk_level)" variant="tonal" size="small">{{ predictionResult.risk_level.toUpperCase() }}</VChip>
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <div class="mb-4">
            <div class="text-body-2 text-medium-emphasis mb-1">{{ t('disciplinary_risk-intelligence.result.employee') }}</div>
            <div class="text-body-1 font-weight-medium">{{ predictionResult.employee }}</div>
          </div>
          <div class="mb-4">
            <div class="text-body-2 text-medium-emphasis mb-1">{{ t('disciplinary_risk-intelligence.result.prediction_type') }}</div>
            <VChip size="small" color="blue-grey" variant="tonal">{{ predictionResult.prediction_type.replace(/_/g, ' ') }}</VChip>
          </div>
          <div class="mb-4">
            <div class="text-body-2 text-medium-emphasis mb-2">{{ t('disciplinary_risk-intelligence.result.risk_probability') }}</div>
            <VProgressLinear :model-value="predictionResult.probability" :color="levelColor(predictionResult.risk_level)" height="20" rounded>
              <template #default>{{ predictionResult.probability }}%</template>
            </VProgressLinear>
          </div>
          <div v-if="predictionResult.reasoning" class="mb-4">
            <div class="text-body-2 text-medium-emphasis mb-1">{{ t('disciplinary_risk-intelligence.result.reasoning') }}</div>
            <div class="text-body-2 pa-3 rounded" style="background: rgba(0,0,0,0.05)">{{ predictionResult.reasoning }}</div>
          </div>
          <div v-if="predictionResult.recommended_actions?.length">
            <div class="text-body-2 text-medium-emphasis mb-2">{{ t('disciplinary_risk-intelligence.result.recommended_actions') }}</div>
            <VList density="compact" class="pa-0">
              <VListItem v-for="(action, i) in predictionResult.recommended_actions" :key="i" :prepend-icon="`tabler-number-${i + 1}-small`" :title="action" class="px-0" />
            </VList>
          </div>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn color="primary" variant="tonal" @click="resultDialog = false">{{ t('disciplinary_risk-intelligence.result.close') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Predict Employee Dialog -->
    <VDialog v-model="predictDialog" max-width="440">
      <VCard>
        <VCardTitle class="pa-4">{{ t('disciplinary_risk-intelligence.dialog.predict_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAutocomplete
            v-model="predictForm.employee_id"
            :items="employees"
            item-title="name"
            item-value="id"
            :label="t('disciplinary_risk-intelligence.dialog.employee_label')"
            variant="outlined"
            density="compact"
            :error-messages="predictForm.errors.employee_id"
            class="mb-3"
            clearable
          >
            <template #item="{ props: itemProps, item }"><VListItem v-bind="itemProps" :subtitle="item.raw.email" /></template>
          </VAutocomplete>
          <VSelect v-model="predictForm.prediction_type" :label="t('disciplinary_risk-intelligence.dialog.prediction_type_label')" variant="outlined" density="compact" :items="predictionTypes" :error-messages="predictForm.errors.prediction_type" />
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="predictDialog = false">{{ t('disciplinary_risk-intelligence.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="predictForm.processing" @click="submitPredict">{{ t('disciplinary_risk-intelligence.dialog.predict') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Department Analysis Dialog -->
    <VDialog v-model="deptDialog" max-width="440">
      <VCard>
        <VCardTitle class="pa-4">{{ t('disciplinary_risk-intelligence.dialog.dept_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAutocomplete
            v-model="deptForm.department_id"
            :items="departments"
            item-title="name"
            item-value="id"
            :label="t('disciplinary_risk-intelligence.dialog.department_label')"
            variant="outlined"
            density="compact"
            :error-messages="deptForm.errors.department_id"
            class="mb-3"
            clearable
          />
          <VRow dense>
            <VCol cols="6"><VTextField v-model="deptForm.period_start" :label="t('disciplinary_risk-intelligence.dialog.period_start_label')" type="date" /></VCol>
            <VCol cols="6"><VTextField v-model="deptForm.period_end" :label="t('disciplinary_risk-intelligence.dialog.period_end_label')" type="date" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="deptDialog = false">{{ t('disciplinary_risk-intelligence.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="deptForm.processing" @click="submitDept">{{ t('disciplinary_risk-intelligence.dialog.analyze') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Heatmap Dialog -->
    <VDialog v-model="heatmapDialog" max-width="440">
      <VCard>
        <VCardTitle class="pa-4">{{ t('disciplinary_risk-intelligence.dialog.heatmap_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VSelect v-model="heatmapForm.heatmap_type" :label="t('disciplinary_risk-intelligence.dialog.heatmap_type_label')" :items="heatmapTypes" class="mb-3" />
          <VTextField v-if="heatmapForm.heatmap_type === 'monthly_trend'" v-model="heatmapForm.year" :label="t('disciplinary_risk-intelligence.dialog.year_label')" type="number" />
          <VRow v-else dense>
            <VCol cols="6"><VTextField v-model="heatmapForm.period_start" label="Period Start" type="date" /></VCol>
            <VCol cols="6"><VTextField v-model="heatmapForm.period_end" label="Period End" type="date" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="heatmapDialog = false">{{ t('disciplinary_risk-intelligence.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="heatmapForm.processing" @click="submitHeatmap">{{ t('disciplinary_risk-intelligence.dialog.generate') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
