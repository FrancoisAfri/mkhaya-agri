<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  attempt: any
  markings: any[]
  markers: { id: number; name: string }[]
  allReviewed: boolean
}>()

const markForms = ref<Record<number, { points: number; notes: string }>>({})
props.markings.forEach(m => {
  markForms.value[m.id] = { points: m.points_awarded ?? 0, notes: m.marker_notes ?? '' }
})

const saving     = ref<Record<number, boolean>>({})
const finalizing = ref(false)
const snackbar   = ref(false)
const snackbarText = ref('')
const errors     = ref<Record<string, string>>({})

const reviewedCount = computed(() => props.markings.filter(m => m.status === 'reviewed').length)

const scoreColor = computed(() => {
  if (props.attempt.score === null) return 'grey'
  if (props.attempt.pass_score === null) return 'primary'
  return props.attempt.score >= props.attempt.pass_score ? 'success' : 'error'
})

function formatDate(dt: string | null) {
  if (!dt) return '—'
  return new Date(dt).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' })
}

function saveMark(m: any) {
  saving.value[m.id] = true
  router.post(route('lms.admin.marking.response', { attempt: props.attempt.id, marking: m.id }), { points_awarded: markForms.value[m.id].points, marker_notes: markForms.value[m.id].notes }, { onSuccess: () => { snackbarText.value = t('lms_marking.toast.saved'); snackbar.value = true }, onFinish: () => { saving.value[m.id] = false }, preserveScroll: true })
}

function assignMarker(markerId: number) {
  router.post(route('lms.admin.marking.assign', props.attempt.id), { assigned_to: markerId }, { onSuccess: () => { snackbarText.value = t('lms_marking.toast.assigned'); snackbar.value = true }, preserveScroll: true })
}

function finalize() {
  finalizing.value = true; errors.value = {}
  router.post(route('lms.admin.marking.finalize', props.attempt.id), {}, { onError: (e) => { errors.value = e }, onFinish: () => { finalizing.value = false } })
}
</script>

<template>
  <div>
    <div class="d-flex align-center gap-3 mb-4">
      <VBtn icon variant="text" :href="route('lms.admin.marking')"><VIcon>mdi-arrow-left</VIcon></VBtn>
      <div class="flex-grow-1">
        <div class="text-h6">{{ attempt.learner_name }}</div>
        <div class="text-body-2 text-medium-emphasis">{{ attempt.questionnaire_title }}<span v-if="attempt.completed_at"> · Submitted {{ formatDate(attempt.completed_at) }}</span></div>
      </div>
      <VChip v-if="attempt.pass_score !== null" label>{{ t('lms_marking.pass_label') }} {{ attempt.pass_score }}%</VChip>
      <VChip v-if="attempt.score !== null" :color="scoreColor" label>{{ t('lms_marking.auto_score') }} {{ attempt.score }}%</VChip>
    </div>

    <VCard class="mb-4 pa-4">
      <div class="d-flex align-center gap-4">
        <div class="flex-grow-1">
          <div class="text-caption mb-1">{{ t('lms_marking.marking_progress', { reviewed: reviewedCount, total: markings.length }) }}</div>
          <VProgressLinear :model-value="markings.length > 0 ? (reviewedCount / markings.length) * 100 : 0" color="success" rounded height="8" />
        </div>
        <VMenu>
          <template #activator="{ props: menuProps }"><VBtn v-bind="menuProps" variant="tonal" prepend-icon="mdi-account-arrow-right" size="small">{{ t('lms_marking.assign_marker') }}</VBtn></template>
          <VList density="compact" min-width="220"><VListItem v-for="m in markers" :key="m.id" :title="m.name" @click="assignMarker(m.id)" /></VList>
        </VMenu>
        <VBtn color="success" prepend-icon="mdi-check-all" :disabled="!allReviewed || attempt.status !== 'pending_review'" :loading="finalizing" @click="finalize">{{ t('lms_marking.finalise_marking') }}</VBtn>
      </div>
      <VAlert v-if="errors.finalize" type="error" variant="tonal" class="mt-3" density="compact">{{ errors.finalize }}</VAlert>
      <VAlert v-if="attempt.status !== 'pending_review'" type="info" variant="tonal" class="mt-3" density="compact">{{ t('lms_marking.already_finalised', { status: attempt.status }) }}</VAlert>
    </VCard>

    <VCard v-for="(m, idx) in markings" :key="m.id" class="mb-4" :class="m.status === 'reviewed' ? 'border-success' : 'border-warning'">
      <VCardText class="pa-5">
        <div class="d-flex align-start gap-3 mb-4">
          <VAvatar :color="m.status === 'reviewed' ? 'success' : 'warning'" variant="tonal" size="36">{{ idx + 1 }}</VAvatar>
          <div class="flex-grow-1">
            <div class="text-subtitle-1 font-weight-medium">{{ m.question.question_text }}</div>
            <div v-if="m.question.context_text" class="text-body-2 text-medium-emphasis mt-1">{{ m.question.context_text }}</div>
            <div class="d-flex gap-2 mt-2">
              <VChip size="x-small" color="primary">{{ m.question.type_label }}</VChip>
              <VChip size="x-small" variant="tonal">{{ t('lms_marking.question_card.max_points', { points: m.question.points }) }}</VChip>
              <VChip v-if="m.status === 'reviewed'" size="x-small" color="success">{{ t('lms_marking.question_card.reviewed_points', { points: m.points_awarded }) }}</VChip>
              <VChip v-else-if="m.status === 'in_review'" size="x-small" color="info">{{ t('lms_marking.question_card.in_review') }}</VChip>
              <VChip v-else size="x-small" color="warning">{{ t('lms_marking.question_card.pending') }}</VChip>
            </div>
          </div>
          <div v-if="m.marked_by_name" class="text-right text-caption text-medium-emphasis">{{ t('lms_marking.question_card.marked_by', { name: m.marked_by_name }) }}<br>{{ formatDate(m.marked_at) }}</div>
        </div>

        <VCard variant="tonal" color="surface-variant" class="mb-4">
          <VCardText class="pa-3">
            <div class="text-caption text-medium-emphasis mb-2">{{ t('lms_marking.question_card.learner_response') }}</div>
            <template v-if="m.response?.response_text"><div class="text-body-2 whitespace-pre-wrap">{{ m.response.response_text }}</div></template>
            <template v-else-if="m.question.type === 'policy_ack'"><VChip :color="m.response?.response_json?.acknowledged ? 'success' : 'error'" size="small">{{ m.response?.response_json?.acknowledged ? t('lms_marking.question_card.acknowledged') : t('lms_marking.question_card.not_acknowledged') }}</VChip></template>
            <template v-else-if="m.response?.has_file"><div class="d-flex align-center gap-2"><VIcon color="primary">mdi-file</VIcon><span class="text-body-2">{{ m.response.file_name }}</span><VBtn size="small" variant="tonal" prepend-icon="mdi-download" :href="m.response.download_url" target="_blank">{{ t('lms_marking.question_card.download') }}</VBtn></div></template>
            <template v-else><span class="text-medium-emphasis text-body-2">{{ t('lms_marking.question_card.no_response') }}</span></template>
          </VCardText>
        </VCard>

        <VRow dense align="center">
          <VCol cols="12" md="4">
            <div class="text-caption mb-1">{{ t('lms_marking.question_card.points_label', { max: m.question.points }) }}</div>
            <div class="d-flex align-center gap-3"><VSlider v-model="markForms[m.id].points" :min="0" :max="m.question.points" :step="0.5" color="primary" hide-details class="flex-grow-1" /><VTextField v-model.number="markForms[m.id].points" :min="0" :max="m.question.points" type="number" density="compact" hide-details style="width: 80px;" /></div>
          </VCol>
          <VCol cols="12" md="6"><VTextarea v-model="markForms[m.id].notes" :label="t('lms_marking.question_card.feedback_label')" rows="2" auto-grow hide-details density="compact" /></VCol>
          <VCol cols="12" md="2" class="d-flex align-end justify-end"><VBtn color="primary" :loading="saving[m.id]" prepend-icon="mdi-content-save" @click="saveMark(m)">{{ t('lms_marking.question_card.save') }}</VBtn></VCol>
        </VRow>
      </VCardText>
    </VCard>

    <div v-if="markings.length > 0" class="d-flex justify-end mt-2 mb-6">
      <VBtn color="success" size="large" prepend-icon="mdi-check-all" :disabled="!allReviewed || attempt.status !== 'pending_review'" :loading="finalizing" @click="finalize">{{ t('lms_marking.finalise_button') }}</VBtn>
    </div>

    <VSnackbar v-model="snackbar" :timeout="2500" color="success" location="bottom right">{{ snackbarText }}</VSnackbar>
  </div>
</template>

<style scoped>
.whitespace-pre-wrap { white-space: pre-wrap; }
.border-success { border-left: 4px solid rgb(var(--v-theme-success)) !important; }
.border-warning { border-left: 4px solid rgb(var(--v-theme-warning)) !important; }
</style>
