<template>
  <div class="mx-auto" style="max-width: 800px;">
    <!-- Score card -->
    <VCard class="mb-4 text-center pa-6">
      <div class="mb-2">
        <VIcon
          :color="statusColor"
          size="64"
        >{{ statusIcon }}</VIcon>
      </div>
      <div class="text-h5 mb-1">{{ statusHeading }}</div>
      <div v-if="attempt.score !== null" class="text-h3 font--weight-bold my-3" :style="{ color: `rgb(var(--v-theme-${statusColor}))` }">
        {{ attempt.score }}%
      </div>
      <div class="text-medium-emphasis">
        <span v-if="questionnaire.pass_score !== null">
          {{ t('lms_questionnaire_result.pass_score', { score: questionnaire.pass_score }) }} ·
        </span>
        {{ t('lms_questionnaire_result.attempt_number', { number: attempt.attempt_number }) }}
      </div>
      <div v-if="attempt.completed_at" class="text-caption text-medium-emphasis mt-1">
        {{ t('lms_questionnaire_result.completed', { date: formatDate(attempt.completed_at) }) }}
      </div>

      <div class="mt-4 d-flex justify-center gap-3">
        <VBtn :href="route('lms.catalog')" variant="tonal">{{ t('lms_questionnaire_result.back_to_catalog') }}</VBtn>
        <VBtn :href="route('lms.my-learning')" variant="tonal">{{ t('lms_questionnaire_result.my_learning') }}</VBtn>
      </div>
    </VCard>

    <!-- Answers review (if show_answers_after) -->
    <template v-if="questionnaire.show_answers_after && questions.length">
      <div class="text-subtitle-1 font-weight-medium mb-3">{{ t('lms_questionnaire_result.review_answers') }}</div>

      <VCard
        v-for="(q, idx) in questions"
        :key="q.id"
        class="mb-3"
      >
        <VCardText class="pa-5">
          <div class="d-flex align-start gap-3 mb-3">
            <VAvatar
              :color="q.response?.is_correct === true ? 'success' : q.response?.is_correct === false ? 'error' : 'grey'"
              variant="tonal"
              size="34"
            >{{ idx + 1 }}</VAvatar>
            <div class="flex-grow-1">
              <div class="text-subtitle-1 font-weight-medium">{{ q.question_text }}</div>
              <div class="text-caption mt-1">
                <VChip size="x-small" class="mr-1">
                  {{ t(`lms_questionnaire_result.question_types.${q.type}`) }}
                </VChip>
                {{ t('lms_questionnaire_result.points', { count: q.points }) }}
                <span v-if="q.response?.points_awarded !== null">
                  · {{ t('lms_questionnaire_result.awarded', { points: q.response?.points_awarded ?? 0 }) }}
                </span>
              </div>
            </div>
            <VIcon v-if="q.is_auto_graded && q.response?.is_correct === true" color="success">mdi-check-circle</VIcon>
            <VIcon v-else-if="q.is_auto_graded && q.response?.is_correct === false" color="error">mdi-close-circle</VIcon>
            <VChip v-else-if="!q.is_auto_graded" size="small" color="orange">
              {{ t('lms_questionnaire_result.pending_review_label') }}
            </VChip>
          </div>

          <!-- Show user's response -->
          <div v-if="q.response" class="mb-3">
            <div class="text-caption text-medium-emphasis mb-1">{{ t('lms_questionnaire_result.your_answer') }}</div>

            <!-- Option-based -->
            <template v-if="['mcq', 'true_false', 'yes_no', 'multi_select', 'rating_scale'].includes(q.type)">
              <div
                v-for="opt in q.options"
                :key="opt.id"
                class="d-flex align-center gap-2 py-1"
              >
                <VIcon
                  v-if="isSelectedOption(q, opt.id)"
                  :color="opt.is_correct ? 'success' : 'error'"
                  size="18"
                >{{ opt.is_correct ? 'mdi-check-circle' : 'mdi-close-circle' }}</VIcon>
                <VIcon v-else-if="opt.is_correct" color="success" size="18">mdi-check</VIcon>
                <VIcon v-else size="18" color="transparent">mdi-circle-outline</VIcon>
                <span :class="{ 'font-weight-medium': isSelectedOption(q, opt.id) }">
                  {{ opt.option_text }}
                </span>
                <VChip v-if="opt.is_correct" size="x-small" color="success" variant="tonal">
                  {{ t('lms_questionnaire_result.correct') }}
                </VChip>
              </div>
            </template>

            <template v-else-if="q.type === 'policy_ack'">
              <VChip :color="q.response.response_json?.acknowledged ? 'success' : 'error'" size="small">
                {{ q.response.response_json?.acknowledged
                  ? t('lms_questionnaire_result.acknowledged')
                  : t('lms_questionnaire_result.not_acknowledged') }}
              </VChip>
            </template>

            <template v-else-if="['file_upload', 'practical'].includes(q.type)">
              <div v-if="q.response.file_name" class="text-body-2">
                <VIcon size="16" class="mr-1">mdi-paperclip</VIcon>{{ q.response.file_name }}
              </div>
              <div v-else class="text-medium-emphasis text-body-2">{{ t('lms_questionnaire_result.no_file_submitted') }}</div>
            </template>

            <template v-else>
              <div class="text-body-2 pa-2 rounded bg-surface-variant">
                {{ q.response.response_text || t('lms_questionnaire_result.no_answer') }}
              </div>
            </template>

            <div v-if="q.response.reviewer_notes" class="mt-2">
              <div class="text-caption text-medium-emphasis">{{ t('lms_questionnaire_result.reviewer_notes') }}</div>
              <div class="text-body-2">{{ q.response.reviewer_notes }}</div>
            </div>
          </div>

          <!-- Explanation -->
          <VAlert v-if="q.explanation" type="info" variant="tonal" density="compact" class="mt-2">
            {{ q.explanation }}
          </VAlert>
        </VCardText>
      </VCard>
    </template>

    <!-- Pending review message -->
    <VAlert
      v-else-if="attempt.status === 'pending_review'"
      type="warning"
      variant="tonal"
      class="mt-4"
    >
      {{ t('lms_questionnaire_result.pending_review_message') }}
    </VAlert>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  questionnaire: any
  attempt: any
  questions: any[]
}>()

const statusMap = {
  passed: {
    color: 'success',
    icon: 'mdi-trophy',
    heading: t('lms_questionnaire_result.statuses.passed')
  },
  failed: {
    color: 'error',
    icon: 'mdi-close-circle',
    heading: t('lms_questionnaire_result.statuses.failed')
  },
  completed: {
    color: 'primary',
    icon: 'mdi-check-circle',
    heading: t('lms_questionnaire_result.statuses.completed')
  },
  pending_review: {
    color: 'warning',
    icon: 'mdi-clock-outline',
    heading: t('lms_questionnaire_result.statuses.pending_review')
  },
}

const statusInfo = computed(() =>
  statusMap[props.attempt.status as keyof typeof statusMap] ?? statusMap.completed
)
const statusColor = computed(() => statusInfo.value.color)
const statusIcon = computed(() => statusInfo.value.icon)
const statusHeading = computed(() => statusInfo.value.heading)

function formatDate(dt: string) {
  return new Date(dt).toLocaleString()
}

function isSelectedOption(q: any, optId: number) {
  if (!q.response?.response_json) return false
  const ids = q.response.response_json.option_ids ?? []
  return ids.includes(optId)
}
</script>
