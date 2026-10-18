<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  score: any
  weakAreas: any[]
  report: any
  refreshers: any[]
  weights: Record<string, number>
}>()

const confirming  = ref(false)
const generating  = ref(false)
const snackbar    = ref(false)
const snackbarText = ref('')

const components = computed(() => [
  { key: 'lesson_completion', label: t('lms_learning_gap.components.lesson_completion'), value: props.score.lesson_completion_score, color: 'primary' },
  { key: 'quiz',              label: t('lms_learning_gap.components.quiz'),              value: props.score.quiz_score,              color: 'blue' },
  { key: 'practical',         label: t('lms_learning_gap.components.practical'),         value: props.score.practical_score,         color: 'teal' },
  { key: 'time',              label: t('lms_learning_gap.components.time'),              value: props.score.time_score,              color: 'cyan' },
  { key: 'manager',           label: t('lms_learning_gap.components.manager'),           value: props.score.manager_score,           color: 'success' },
])

const hasAnomalies = computed(() =>
  props.score.guessing_detected || props.score.rushed_detected ||
  props.score.low_engagement_detected || props.score.is_certificate_blocked
)

const gradeColorMap: Record<string, string> = { A: 'success', B: 'light-green', C: 'orange', D: 'deep-orange', F: 'error' }
const gradeColor = (g: string) => gradeColorMap[g] ?? 'grey'

function formatDate(dt: string) {
  return new Date(dt).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' })
}

function confirmManager() {
  confirming.value = true
  router.post(route('lms.admin.understanding.confirm', props.score.id), {}, {
    onSuccess: () => { snackbarText.value = t('lms_learning_gap.toast.confirmed'); snackbar.value = true },
    onFinish: () => { confirming.value = false },
    preserveScroll: true,
  })
}

function generateReport() {
  generating.value = true
  router.post(route('lms.admin.understanding.report', props.score.enrollment_id), {}, {
    onSuccess: () => { snackbarText.value = t('lms_learning_gap.toast.report_generated'); snackbar.value = true },
    onFinish: () => { generating.value = false },
    preserveScroll: true,
  })
}
</script>

<template>
  <div>
    <!-- Nav -->
    <div class="d-flex align-center gap-3 mb-4">
      <VBtn icon variant="text" :href="route('lms.admin.understanding')"><VIcon>mdi-arrow-left</VIcon></VBtn>
      <div>
        <div class="text-h6">{{ t('lms_learning_gap.title') }}</div>
        <div class="text-body-2 text-medium-emphasis">{{ score.learner_name }} · {{ score.course_title }}</div>
      </div>
      <VSpacer />
      <VBtn variant="tonal" prepend-icon="mdi-file-refresh" :loading="generating" @click="generateReport">{{ t('lms_learning_gap.refresh_report') }}</VBtn>
    </div>

    <VRow>
      <VCol cols="12" md="7">
        <VCard class="mb-4">
          <VCardTitle class="pa-4 pb-2">
            <div class="d-flex align-center justify-space-between">
              <span>{{ t('lms_learning_gap.understanding_score') }}</span>
              <div class="d-flex align-center gap-3">
                <VChip :color="score.grade_color" size="large" label class="font-weight-bold text-h6 px-4">{{ score.grade }}</VChip>
                <div class="text-h4 font-weight-bold" :style="{ color: `rgb(var(--v-theme-${score.grade_color}))` }">{{ score.total_score }}%</div>
              </div>
            </div>
          </VCardTitle>
          <VCardText>
            <div v-for="comp in components" :key="comp.key" class="mb-4">
              <div class="d-flex justify-space-between mb-1">
                <div>
                  <span class="text-body-2 font-weight-medium">{{ comp.label }}</span>
                  <span class="text-caption text-medium-emphasis ml-2">{{ t('lms_learning_gap.weight_label', { weight: (weights[comp.key] * 100).toFixed(0) }) }}</span>
                </div>
                <div class="text-body-2 font-weight-medium">{{ comp.value.toFixed(1) }}%<span class="text-caption text-medium-emphasis">{{ t('lms_learning_gap.pts_label', { pts: (comp.value * weights[comp.key]).toFixed(1) }) }}</span></div>
              </div>
              <VProgressLinear :model-value="comp.value" :color="comp.color" rounded height="10" bg-color="surface-variant" />
            </div>
            <VDivider class="my-3" />
            <div class="d-flex justify-space-between align-center">
              <span class="text-subtitle-1 font-weight-medium">{{ t('lms_learning_gap.weighted_total') }}</span>
              <span class="text-h6 font-weight-bold" :style="{ color: `rgb(var(--v-theme-${score.grade_color}))` }">{{ score.total_score }}%</span>
            </div>
          </VCardText>
        </VCard>

        <!-- Anomaly flags -->
        <VCard class="mb-4" v-if="hasAnomalies">
          <VCardTitle class="pa-4 pb-2">{{ t('lms_learning_gap.anomaly_flags') }}</VCardTitle>
          <VCardText>
            <VAlert v-if="score.guessing_detected" type="error" variant="tonal" density="compact" prepend-icon="mdi-dice-multiple" class="mb-2"><strong>{{ t('lms_learning_gap.anomalies.guessing.title') }}</strong> {{ t('lms_learning_gap.anomalies.guessing.body') }}</VAlert>
            <VAlert v-if="score.rushed_detected" type="warning" variant="tonal" density="compact" prepend-icon="mdi-run-fast" class="mb-2"><strong>{{ t('lms_learning_gap.anomalies.rushed.title') }}</strong> {{ t('lms_learning_gap.anomalies.rushed.body') }}</VAlert>
            <VAlert v-if="score.low_engagement_detected" type="warning" variant="tonal" density="compact" prepend-icon="mdi-sleep" class="mb-2"><strong>{{ t('lms_learning_gap.anomalies.low_engagement.title') }}</strong> {{ t('lms_learning_gap.anomalies.low_engagement.body') }}</VAlert>
            <VAlert v-if="score.is_certificate_blocked" type="error" variant="tonal" density="compact" prepend-icon="mdi-certificate-outline"><strong>{{ t('lms_learning_gap.anomalies.certificate_blocked.title') }}</strong> {{ score.certificate_blocked_reason }}</VAlert>
          </VCardText>
        </VCard>

        <!-- Weak learning areas -->
        <VCard class="mb-4">
          <VCardTitle class="pa-4 pb-2">{{ t('lms_learning_gap.weak_areas.title') }}<VChip v-if="weakAreas.length" size="small" color="error" class="ml-2">{{ weakAreas.length }}</VChip></VCardTitle>
          <div v-if="weakAreas.length === 0" class="text-center text-medium-emphasis py-6">{{ t('lms_learning_gap.weak_areas.no_weak') }}</div>
          <VList v-else density="compact">
            <VListItem v-for="w in weakAreas" :key="w.id" rounded="lg" class="mx-2 mb-1">
              <template #prepend><VAvatar color="error" variant="tonal" size="32">{{ w.failure_count }}</VAvatar></template>
              <VListItemTitle class="text-wrap text-body-2">{{ w.topic }}</VListItemTitle>
              <VListItemSubtitle>{{ t('lms_learning_gap.weak_areas.failed_count', { count: w.failure_count }) }} · {{ t('lms_learning_gap.weak_areas.last') }} {{ w.last_failed_at ?? '—' }}</VListItemSubtitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>

      <VCol cols="12" md="5">
        <!-- Manager confirmation -->
        <VCard class="mb-4">
          <VCardTitle class="pa-4 pb-2">{{ t('lms_learning_gap.manager_confirmation.title') }}</VCardTitle>
          <VCardText>
            <div v-if="score.manager_confirmed" class="d-flex align-center gap-2 mb-2">
              <VIcon color="success">mdi-account-check</VIcon>
              <div>
                <div class="text-body-2 font-weight-medium text-success">{{ t('lms_learning_gap.manager_confirmation.confirmed') }}</div>
                <div class="text-caption text-medium-emphasis">{{ t('lms_learning_gap.manager_confirmation.by') }} {{ score.confirmed_by_name ?? '—' }} {{ t('lms_learning_gap.manager_confirmation.on') }} {{ score.manager_confirmed_at ? formatDate(score.manager_confirmed_at) : '—' }}</div>
              </div>
            </div>
            <div v-else class="text-body-2 text-medium-emphasis mb-3">{{ t('lms_learning_gap.manager_confirmation.not_confirmed') }}</div>
            <VBtn :color="score.manager_confirmed ? 'success' : 'primary'" variant="tonal" prepend-icon="mdi-account-check" :loading="confirming" @click="confirmManager">{{ score.manager_confirmed ? t('lms_learning_gap.manager_confirmation.reconfirm_button') : t('lms_learning_gap.manager_confirmation.confirm_button') }}</VBtn>
          </VCardText>
        </VCard>

        <!-- Refresher recommendations -->
        <VCard class="mb-4">
          <VCardTitle class="pa-4 pb-2">{{ t('lms_learning_gap.refresher_recommendations.title') }}</VCardTitle>
          <VCardText v-if="refreshers.length === 0" class="text-medium-emphasis">{{ t('lms_learning_gap.refresher_recommendations.no_refresher') }}</VCardText>
          <VList v-else density="compact">
            <VListItem v-for="r in refreshers" :key="r.course_id" rounded="lg" class="mb-1">
              <template #prepend><VAvatar :color="gradeColor(r.grade)" variant="tonal" size="32">{{ r.grade }}</VAvatar></template>
              <VListItemTitle>{{ r.course_title }}</VListItemTitle>
              <VListItemSubtitle>{{ t('lms_learning_gap.refresher_recommendations.score') }} {{ r.score }}% · <span v-for="(reason, i) in r.reasons" :key="i">{{ reason }}<span v-if="i < r.reasons.length - 1">, </span></span></VListItemSubtitle>
            </VListItem>
          </VList>
        </VCard>

        <!-- Report summary -->
        <VCard v-if="report">
          <VCardTitle class="pa-4 pb-2">{{ t('lms_learning_gap.last_report.title') }}</VCardTitle>
          <VCardText>
            <div class="text-caption text-medium-emphasis mb-2">{{ t('lms_learning_gap.last_report.generated') }} {{ report.generated_at ? formatDate(report.generated_at) : '—' }}</div>
            <div v-if="report.recommendations?.length" class="mb-1"><div class="text-body-2 font-weight-medium mb-1">{{ t('lms_learning_gap.last_report.recommendations') }}</div><ul class="text-body-2 pl-4"><li v-for="(rec, i) in report.recommendations" :key="i">{{ rec }}</li></ul></div>
            <div v-else class="text-medium-emphasis text-body-2">{{ t('lms_learning_gap.last_report.no_recommendations') }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VSnackbar v-model="snackbar" :timeout="2500" color="success" location="bottom right">{{ snackbarText }}</VSnackbar>
  </div>
</template>
