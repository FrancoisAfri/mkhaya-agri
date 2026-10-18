<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { ref, computed, onMounted, onUnmounted } from 'vue'

const route = ziggyRoute

// ── Types ──────────────────────────────────────────────────────────────────────

type QuestionType = 'multiple_choice' | 'true_false' | 'short_answer'

type Option = { label: string; value: string }

type Question = {
  id: number
  question_text: string
  type: QuestionType
  options: Option[] | null
  points: number
  sort_order: number
}

type Settings = {
  pass_mark: number
  time_limit_minutes: number | null
}

// ── Props ──────────────────────────────────────────────────────────────────────

const props = defineProps<{
  policy:         { id: number; uuid: string; name: string }
  questions:      Question[]
  settings:       Settings
  attempt_number: number
}>()

// ── Form ───────────────────────────────────────────────────────────────────────

const form = useForm<{ answers: Record<string, string> }>({
  answers: Object.fromEntries(props.questions.map(q => [String(q.id), ''])),
})

function submit() {
  form.post(route('policies.quiz.submit', props.policy.uuid))
}

// ── Stepper ────────────────────────────────────────────────────────────────────

const currentQuestionIndex = ref(0)

const currentQuestion = computed(() => props.questions[currentQuestionIndex.value])

const isFirstQuestion = computed(() => currentQuestionIndex.value === 0)

const isLastQuestion = computed(() => currentQuestionIndex.value === props.questions.length - 1)

const currentAnswerValue = computed(() => 
  form.answers[String(currentQuestion.value?.id)] || ''
)

function goToPrevious() {
  if (!isFirstQuestion.value) {
    currentQuestionIndex.value -= 1
  }
}

function goToNext() {
  if (!isLastQuestion.value) {
    currentQuestionIndex.value += 1
  }
}

// ── Progress ──────────────────────────────────────────────────────────────────

const answeredCount = computed(() =>
  Object.values(form.answers).filter(a => a.trim() !== '').length
)

const progressPct = computed(() =>
  props.questions.length ? Math.round((answeredCount.value / props.questions.length) * 100) : 0
)

const allAnswered = computed(() => answeredCount.value === props.questions.length)

// ── Timer ─────────────────────────────────────────────────────────────────────

const timeLeft = ref(
  props.settings.time_limit_minutes ? props.settings.time_limit_minutes * 60 : null
)

let timerHandle: ReturnType<typeof setInterval> | null = null

const timeDisplay = computed(() => {
  if (timeLeft.value === null) return null
  const m = Math.floor(timeLeft.value / 60).toString().padStart(2, '0')
  const s = (timeLeft.value % 60).toString().padStart(2, '0')

  return `${m}:${s}`
})

const timerColor = computed(() => {
  if (timeLeft.value === null) return 'info'
  if (timeLeft.value <= 60) return 'error'
  if (timeLeft.value <= 180) return 'warning'

  return 'info'
})

onMounted(() => {
  if (timeLeft.value === null) return

  timerHandle = setInterval(() => {
    if (timeLeft.value === null) return
    timeLeft.value -= 1
    if (timeLeft.value <= 0) {
      clearInterval(timerHandle!)
      submit() // auto-submit when time expires
    }
  }, 1000)
})

onUnmounted(() => {
  if (timerHandle) clearInterval(timerHandle)
})
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <!-- Header -->
      <VCol cols="12">
        <VCard elevation="0" border rounded="lg">
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-4 py-6">
            <div>
              <div class="text-overline text-medium-emphasis">Policy Quiz · Attempt #{{ attempt_number }}</div>
              <h4 class="text-h4 mb-1">{{ policy.name }}</h4>
              <p class="mb-0 text-medium-emphasis">
                Pass mark: <strong>{{ settings.pass_mark }}%</strong>
              </p>
            </div>
            <div class="d-flex align-center gap-4">
              <VChip
                v-if="timeDisplay"
                :color="timerColor"
                prepend-icon="ri-timer-line"
                size="large"
              >
                {{ timeDisplay }}
              </VChip>
            </div>
          </VCardText>
          <VProgressLinear
            :model-value="progressPct"
            :color="answeredCount > 0 && allAnswered ? 'success' : 'primary'"
            height="4"
            rounded
          />
          <VCardText class="text-body-2 text-medium-emphasis py-2">
            {{ answeredCount }} / {{ questions.length }} answered
          </VCardText>
        </VCard>
      </VCol>

      <!-- Question Stepper -->
      <VCol cols="12">
        <VCard elevation="0" border rounded="lg">
          <!-- Question Header -->
          <VCardText class="pb-4">
            <div class="d-flex align-center justify-space-between mb-4">
              <div>
                <div class="text-overline text-medium-emphasis">
                  Question {{ currentQuestionIndex + 1 }} of {{ questions.length }}
                </div>
                <h5 class="text-h5 mt-2 mb-0">{{ currentQuestion.question_text }}</h5>
              </div>
              <VChip size="small" color="secondary" label>
                {{ currentQuestion.points }} pt{{ currentQuestion.points !== 1 ? 's' : '' }}
              </VChip>
            </div>

            <!-- Question Content -->
            <VDivider class="my-4" />

            <div class="my-6">
              <!-- Multiple Choice -->
              <VRadioGroup
                v-if="currentQuestion.type === 'multiple_choice' && currentQuestion.options"
                v-model="form.answers[String(currentQuestion.id)]"
                :error-messages="form.errors[`answers.${currentQuestion.id}`]"
              >
                <VRadio
                  v-for="opt in currentQuestion.options"
                  :key="opt.label"
                  :label="`${opt.label}. ${opt.value}`"
                  :value="opt.label"
                />
              </VRadioGroup>

              <!-- True/False -->
              <VRadioGroup
                v-else-if="currentQuestion.type === 'true_false'"
                v-model="form.answers[String(currentQuestion.id)]"
                :error-messages="form.errors[`answers.${currentQuestion.id}`]"
                inline
              >
                <VRadio label="True" value="true" />
                <VRadio label="False" value="false" />
              </VRadioGroup>

              <!-- Short Answer -->
              <VTextarea
                v-else
                v-model="form.answers[String(currentQuestion.id)]"
                label="Your answer"
                rows="3"
                auto-grow
                counter
                :error-messages="form.errors[`answers.${currentQuestion.id}`]"
              />
            </div>

            <!-- Answer Status -->
            <div class="mb-4" v-if="currentAnswerValue.trim() !== ''">
              <VIcon size="small" color="success" class="mr-2">ri-checkbox-circle-line</VIcon>
              <span class="text-success text-body-2">Answer saved</span>
            </div>
          </VCardText>

          <!-- Navigation Buttons -->
          <VDivider />
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-4 pt-6">
            <div>
              <span v-if="!allAnswered" class="text-warning">
                <VIcon size="small">ri-error-warning-line</VIcon>
                {{ questions.length - answeredCount }} question(s) unanswered
              </span>
              <span v-else class="text-success">
                <VIcon size="small">ri-checkbox-circle-line</VIcon>
                Quiz ready to submit
              </span>
              <p v-if="form.errors.quiz" class="text-error mt-1 mb-0 text-body-2">
                {{ form.errors.quiz }}
              </p>
            </div>
            <div class="d-flex align-center gap-3 flex-wrap">
              <VBtn
                variant="tonal"
                :disabled="isFirstQuestion"
                @click="goToPrevious"
                prepend-icon="ri-arrow-left-line"
              >
                Previous
              </VBtn>

              <VBtn
                v-if="!isLastQuestion"
                variant="tonal"
                color="primary"
                @click="goToNext"
                append-icon="ri-arrow-right-line"
              >
                Next
              </VBtn>

              <VBtn
                v-else
                type="submit"
                color="success"
                size="large"
                :loading="form.processing"
                prepend-icon="ri-send-plane-line"
                @click="submit"
              >
                Submit Quiz
              </VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </VContainer>
</template>
