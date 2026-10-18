<template>
  <div class="mx-auto" style="max-width: 800px;">

    <!-- Can't attempt -->
    <VCard v-if="!canAttempt" class="text-center pa-8">
      <VIcon size="64" color="error" class="mb-4">mdi-lock</VIcon>
      <div class="text-h6 mb-2">{{ t('lms_questionnaire_attempt.retake_denied') }}</div>
      <div v-if="lastAttempt" class="text-body-2 text-medium-emphasis mb-4">
        {{ t('lms_questionnaire_attempt.last_score', { score: lastAttempt.score !== null ? lastAttempt.score : 'N/A', status: statusLabel(lastAttempt.status) }) }}
      </div>
      <VBtn :href="route('lms.catalog')" variant="tonal">{{ t('lms_questionnaire_attempt.back_to_catalog') }}</VBtn>
    </VCard>

    <!-- Start screen (no active attempt) -->
    <template v-else-if="!activeAttemptId && !started">
      <VCard>
        <VCardTitle class="pa-6 pb-2 text-h5">{{ questionnaire.title }}</VCardTitle>
        <VCardSubtitle class="px-6 pb-0">
          <VChip size="small" class="mr-1">{{ typeLabel }}</VChip>
          <VChip v-if="questionnaire.time_limit_mins" size="small" prepend-icon="mdi-timer">
            {{ t('lms_questionnaire_attempt.start_screen.time_limit', { minutes: questionnaire.time_limit_mins }) }}
          </VChip>
        </VCardSubtitle>
        <VCardText class="px-6 py-4">
          <p v-if="questionnaire.description" class="mb-3">{{ questionnaire.description }}</p>

          <VAlert v-if="questionnaire.instructions" type="info" variant="tonal" class="mb-4">
            {{ questionnaire.instructions }}
          </VAlert>

          <VList density="compact">
            <VListItem v-if="questions.length">
              <template #prepend><VIcon>mdi-help-circle</VIcon></template>
              <VListItemTitle>
                {{ t('lms_questionnaire_attempt.start_screen.questions_count', { count: questions.length }) }}
              </VListItemTitle>
            </VListItem>
            <VListItem v-if="questionnaire.pass_score !== null">
              <template #prepend><VIcon>mdi-trophy</VIcon></template>
              <VListItemTitle>
                {{ t('lms_questionnaire_attempt.start_screen.pass_score', { score: questionnaire.pass_score }) }}
              </VListItemTitle>
            </VListItem>
            <VListItem v-if="previousAttempts > 0">
              <template #prepend><VIcon>mdi-history</VIcon></template>
              <VListItemTitle>
                {{ t('lms_questionnaire_attempt.start_screen.previous_attempts', { count: previousAttempts }) }}
              </VListItemTitle>
            </VListItem>
          </VList>
        </VCardText>
        <VCardActions class="pa-6">
          <VBtn color="primary" size="large" :loading="starting" @click="startAttempt">
            {{ previousAttempts > 0 ? t('lms_questionnaire_attempt.start_screen.retry') : t('lms_questionnaire_attempt.start_screen.start') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </template>

    <!-- Active questionnaire -->
    <template v-else>
      <!-- Timer -->
      <div v-if="questionnaire.time_limit_mins" class="d-flex justify-end mb-3">
        <VChip
          :color="timerWarning ? 'error' : 'primary'"
          prepend-icon="mdi-timer"
          label
        >
          {{ formattedTime }}
        </VChip>
      </div>

      <!-- Progress -->
      <VProgressLinear
        :model-value="answeredCount / questions.length * 100"
        color="primary"
        rounded
        class="mb-4"
        height="6"
      />
      <div class="text-caption text-medium-emphasis mb-4">
        {{ t('lms_questionnaire_attempt.question.answered_count', { answered: answeredCount, total: questions.length }) }}
      </div>

      <!-- Questions -->
      <VCard
        v-for="(q, idx) in questions"
        :key="q.id"
        :id="`q-${q.id}`"
        class="mb-4"
        :class="{ 'border-error': submitted && !responses[q.id] }"
      >
        <VCardText class="pa-5">
          <div class="d-flex align-start gap-3 mb-3">
            <VAvatar color="primary" variant="tonal" size="34">{{ idx + 1 }}</VAvatar>
            <div>
              <div class="text-subtitle-1 font-weight-medium">{{ q.question_text }}</div>
              <div v-if="q.context_text" class="text-body-2 text-medium-emphasis mt-1">
                {{ q.context_text }}
              </div>
              <div class="text-caption mt-1">
                {{ t('lms_questionnaire_attempt.question.points', { count: q.points }) }}
              </div>
            </div>
          </div>

          <!-- MCQ / true_false / yes_no — single select -->
          <template v-if="['mcq', 'true_false', 'yes_no'].includes(q.type)">
            <VRadioGroup v-model="responses[q.id].option_id" hide-details>
              <VRadio
                v-for="opt in q.options"
                :key="opt.id"
                :value="opt.id"
                :label="opt.option_text"
              />
            </VRadioGroup>
          </template>

          <!-- Multi-select -->
          <template v-else-if="q.type === 'multi_select'">
            <VCheckbox
              v-for="opt in q.options"
              :key="opt.id"
              v-model="responses[q.id].option_ids"
              :value="opt.id"
              :label="opt.option_text"
              density="compact"
              hide-details
            />
          </template>

          <!-- Rating scale -->
          <template v-else-if="q.type === 'rating_scale'">
            <VRadioGroup v-model="responses[q.id].option_id" hide-details inline>
              <VRadio
                v-for="opt in q.options"
                :key="opt.id"
                :value="opt.id"
                :label="String(opt.rating_value ?? opt.option_text)"
              />
            </VRadioGroup>
          </template>

          <!-- Short answer -->
          <template v-else-if="q.type === 'short_answer'">
            <VTextField v-model="responses[q.id].text" :placeholder="t('lms_questionnaire_attempt.placeholders.your_answer')" />
          </template>

          <!-- Long answer / scenario -->
          <template v-else-if="['long_answer', 'scenario'].includes(q.type)">
            <VTextarea v-model="responses[q.id].text" :placeholder="t('lms_questionnaire_attempt.placeholders.your_answer')" rows="4" auto-grow />
          </template>

          <!-- File upload / practical -->
          <template v-else-if="['file_upload', 'practical'].includes(q.type)">
            <VFileInput
              v-model="responses[q.id].file"
              :label="t('lms_questionnaire_attempt.file_upload.upload_file')"
              prepend-icon="mdi-paperclip"
              :hint="q.type === 'practical' ? t('lms_questionnaire_attempt.file_upload.evidence_hint') : t('lms_questionnaire_attempt.file_upload.upload_response_hint')"
              persistent-hint
            />
            <div v-if="responses[q.id].saved_file_name" class="text-caption mt-1">
              {{ t('lms_questionnaire_attempt.file_upload.previously_uploaded', { filename: responses[q.id].saved_file_name }) }}
            </div>
          </template>

          <!-- Policy acknowledgement -->
          <template v-else-if="q.type === 'policy_ack'">
            <VCheckbox
              v-model="responses[q.id].acknowledged"
              :label="t('lms_questionnaire_attempt.policy.acknowledge')"
              color="primary"
              hide-details
            />
          </template>
        </VCardText>
      </VCard>

      <div class="d-flex justify-end gap-3 mt-4">
        <VBtn variant="tonal" :disabled="submitting" @click="saveAll">{{ t('lms_questionnaire_attempt.question.save_progress') }}</VBtn>
        <VBtn color="primary" size="large" :loading="submitting" @click="submitAttempt">
          {{ t('lms_questionnaire_attempt.question.submit') }}
        </VBtn>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  questionnaire: any
  questions: any[]
  attemptId: number | null
  savedResponses: Record<number, any>
  canAttempt: boolean
  retakeDeniedReason: string | null
  previousAttempts: number
  lastAttempt: any
  enrollmentId: number | null
  assignmentId: number | null
}>()

const activeAttemptId = ref<number | null>(props.attemptId)
const started         = ref(false)
const starting        = ref(false)
const submitting      = ref(false)
const submitted       = ref(false)

// Build response map keyed by question ID
type ResponseState = {
  option_id?: number | null
  option_ids?: number[]
  text?: string
  acknowledged?: boolean
  file?: File | null
  saved_file_name?: string | null
}
const responses = ref<Record<number, ResponseState>>({})

function initResponses() {
  for (const q of props.questions) {
    const saved = props.savedResponses?.[q.id]
    if (['mcq', 'true_false', 'yes_no', 'rating_scale'].includes(q.type)) {
      responses.value[q.id] = {
        option_id: saved?.response_json?.option_ids?.[0] ?? null,
      }
    } else if (q.type === 'multi_select') {
      responses.value[q.id] = {
        option_ids: saved?.response_json?.option_ids ?? [],
      }
    } else if (q.type === 'policy_ack') {
      responses.value[q.id] = {
        acknowledged: saved?.response_json?.acknowledged ?? false,
      }
    } else if (['file_upload', 'practical'].includes(q.type)) {
      responses.value[q.id] = {
        file: null,
        saved_file_name: saved?.file_name ?? null,
      }
    } else {
      responses.value[q.id] = {
        text: saved?.response_text ?? '',
      }
    }
  }
}

onMounted(() => {
  initResponses()
  if (activeAttemptId.value) {
    started.value = true
    if (props.questionnaire.time_limit_mins) startTimer()
  }
})

// ── Timer ──────────────────────────────────────────────────────────────────
const secondsLeft = ref((props.questionnaire.time_limit_mins ?? 0) * 60)
const timerWarning = computed(() => secondsLeft.value < 120)
const formattedTime = computed(() => {
  const m = Math.floor(secondsLeft.value / 60)
  const s = secondsLeft.value % 60
  return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`
})
let timerInterval: ReturnType<typeof setInterval> | null = null

function startTimer() {
  timerInterval = setInterval(() => {
    if (secondsLeft.value <= 0) {
      clearInterval(timerInterval!)
      submitAttempt()
    } else {
      secondsLeft.value--
    }
  }, 1000)
}

onUnmounted(() => { if (timerInterval) clearInterval(timerInterval) })

// ── Computed ───────────────────────────────────────────────────────────────
const answeredCount = computed(() => {
  return props.questions.filter(q => {
    const r = responses.value[q.id]
    if (!r) return false
    if ('option_id' in r) return r.option_id !== null
    if ('option_ids' in r) return (r.option_ids?.length ?? 0) > 0
    if ('acknowledged' in r) return r.acknowledged
    if ('file' in r) return r.file !== null || !!r.saved_file_name
    return (r.text?.trim().length ?? 0) > 0
  }).length
})

const typeLabel = computed(() => {
  const map: Record<string, string> = {
    questionnaire: t('lms_questionnaire_attempt.questionnaire_types.questionnaire'),
    pre_assessment: t('lms_questionnaire_attempt.questionnaire_types.pre_assessment'),
    post_assessment: t('lms_questionnaire_attempt.questionnaire_types.post_assessment'),
    knowledge_check: t('lms_questionnaire_attempt.questionnaire_types.knowledge_check'),
    final_test: t('lms_questionnaire_attempt.questionnaire_types.final_test'),
    practical_assessment: t('lms_questionnaire_attempt.questionnaire_types.practical_assessment'),
    self_assessment: t('lms_questionnaire_attempt.questionnaire_types.self_assessment'),
    manager_observation: t('lms_questionnaire_attempt.questionnaire_types.manager_observation'),
    feedback: t('lms_questionnaire_attempt.questionnaire_types.feedback'),
  }
  return map[props.questionnaire.type] ?? props.questionnaire.type
})

function statusLabel(s: string) {
  return t(`lms_questionnaire_attempt.statuses.${s}`) || s
}

// ── Start attempt ──────────────────────────────────────────────────────────
async function startAttempt() {
  starting.value = true
  try {
    const { data } = await axios.post(
      route('lms.questionnaires.start', props.questionnaire.id),
      { enrollment_id: props.enrollmentId, assignment_id: props.assignmentId }
    )
    activeAttemptId.value = data.attempt_id
    started.value = true
    if (props.questionnaire.time_limit_mins) {
      secondsLeft.value = props.questionnaire.time_limit_mins * 60
      startTimer()
    }
  } finally {
    starting.value = false
  }
}

// ── Build payload ──────────────────────────────────────────────────────────
function buildResponseJson(q: any): any {
  const r = responses.value[q.id]
  if (['mcq', 'true_false', 'yes_no', 'rating_scale'].includes(q.type)) {
    return { option_ids: r.option_id ? [r.option_id] : [] }
  } else if (q.type === 'multi_select') {
    return { option_ids: r.option_ids ?? [] }
  } else if (q.type === 'policy_ack') {
    return { acknowledged: r.acknowledged ?? false }
  }
  return null
}

// ── Save all (auto-save) ───────────────────────────────────────────────────
async function saveAll() {
  if (!activeAttemptId.value) return
  for (const q of props.questions) {
    const r = responses.value[q.id]
    const payload: any = { question_id: q.id }

    if (['file_upload', 'practical'].includes(q.type) && r.file) {
      const fd = new FormData()
      fd.append('question_id', String(q.id))
      fd.append('file', r.file as File)
      await axios.post(
        route('lms.questionnaires.attempts.response', { questionnaire: props.questionnaire.id, attempt: activeAttemptId.value }),
        fd,
        { headers: { 'Content-Type': 'multipart/form-data' } }
      ).catch(() => {})
    } else {
      payload.response_text = ('text' in r) ? r.text : null
      payload.response_json = buildResponseJson(q)
      await axios.post(
        route('lms.questionnaires.attempts.response', { questionnaire: props.questionnaire.id, attempt: activeAttemptId.value }),
        payload
      ).catch(() => {})
    }
  }
}

// ── Submit ─────────────────────────────────────────────────────────────────
async function submitAttempt() {
  if (!activeAttemptId.value) return
  submitting.value = true
  submitted.value = true

  // Upload any file responses first
  for (const q of props.questions) {
    const r = responses.value[q.id]
    if (['file_upload', 'practical'].includes(q.type) && r.file) {
      const fd = new FormData()
      fd.append('question_id', String(q.id))
      fd.append('file', r.file as File)
      await axios.post(
        route('lms.questionnaires.attempts.response', { questionnaire: props.questionnaire.id, attempt: activeAttemptId.value }),
        fd,
        { headers: { 'Content-Type': 'multipart/form-data' } }
      ).catch(() => {})
    }
  }

  // Build non-file responses payload
  const textResponses: Record<number, any> = {}
  for (const q of props.questions) {
    const r = responses.value[q.id]
    if (['file_upload', 'practical'].includes(q.type)) continue
    textResponses[q.id] = {
      response_text: ('text' in r) ? r.text : null,
      response_json: buildResponseJson(q),
    }
  }

  router.post(
    route('lms.questionnaires.attempts.submit', { questionnaire: props.questionnaire.id, attempt: activeAttemptId.value }),
    { responses: textResponses },
    { onFinish: () => { submitting.value = false } }
  )
}
</script>
