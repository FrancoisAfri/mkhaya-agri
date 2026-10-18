<template>
  <VContainer fluid class="pa-6" style="max-width: 720px; margin: 0 auto">
    <!-- Header -->
    <VCard border flat class="mb-6 pa-4">
      <div class="d-flex align-center ga-3 mb-2">
        <VAvatar color="primary" variant="tonal" size="44">
          <VIcon>tabler-users</VIcon>
        </VAvatar>
        <div>
          <h1 class="text-h6 font-weight-bold">{{ review.title }}</h1>
          <p class="text-body-2 text-medium-emphasis">
            {{ t('pa.feedback.about') }}: <strong>{{ review.employee_name }}</strong>
          </p>
        </div>
      </div>

      <VChip
        size="small"
        color="primary"
        variant="tonal"
        class="mb-2"
      >
        {{ relationshipLabel(rater.relationship) }}
      </VChip>

      <p v-if="review.instructions" class="text-body-2 text-medium-emphasis mt-2">
        {{ review.instructions }}
      </p>

      <VAlert
        v-if="review.require_anonymity"
        type="info"
        variant="tonal"
        density="compact"
        class="mt-3"
      >
        Your responses are anonymous. Your name will not be visible on the report.
      </VAlert>

      <VAlert v-if="rater.status === 'submitted'" type="success" variant="tonal" density="compact" class="mt-3">
        You have already submitted your feedback. You may update it below.
      </VAlert>
    </VCard>

    <!-- Questions form -->
    <VForm ref="responseForm" @submit.prevent="submitResponses">
      <div v-for="(group, category) in groupedQuestions" :key="category" class="mb-6">
        <h2 class="text-body-1 font-weight-bold text-capitalize mb-3">
          <VIcon start size="16" color="primary">tabler-tag</VIcon>
          {{ category }}
        </h2>

        <VCard
          v-for="q in group"
          :key="q.id"
          border
          flat
          class="mb-3"
        >
          <VCardText>
            <p class="text-body-2 font-weight-medium mb-3">{{ q.question }}</p>

            <!-- Rating input -->
            <template v-if="q.question_type === 'rating' || q.question_type === 'both'">
              <p class="text-caption text-medium-emphasis mb-1">Rating (0–5)</p>
              <div class="d-flex align-center ga-2 mb-1">
                <VSlider
                  v-model="responses[q.id].rating"
                  min="0"
                  max="5"
                  step="0.5"
                  thumb-label
                  color="amber"
                  :tick-size="4"
                  show-ticks="always"
                  class="flex-grow-1"
                />
                <VChip size="small" color="amber" variant="tonal" style="min-width:50px; justify-content:center">
                  {{ responses[q.id].rating ?? 0 }}/5
                </VChip>
              </div>
            </template>

            <!-- Text input -->
            <template v-if="q.question_type === 'text' || q.question_type === 'both'">
              <VTextarea
                v-model="responses[q.id].comment"
                :label="q.question_type === 'both' ? 'Comments (optional)' : 'Your response'"
                density="compact"
                variant="outlined"
                rows="3"
                hide-details
                class="mt-2"
              />
            </template>
          </VCardText>
        </VCard>
      </div>

      <!-- Submit -->
      <VBtn
        type="submit"
        color="primary"
        size="large"
        block
        :loading="submitting"
        class="mt-2"
      >
        {{ rater.status === 'submitted' ? 'Update Submission' : 'Submit Feedback' }}
      </VBtn>

      <p class="text-caption text-medium-emphasis text-center mt-2">
        Once submitted, your feedback is locked in and processed with other raters' responses.
      </p>
    </VForm>
  </VContainer>
</template>

<script setup lang="ts">
import { ref, reactive, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'

const { t } = useI18n()

interface RaterData {
  id: number
  relationship: string
  status: string
}

interface ReviewData {
  id: number
  title: string
  instructions: string | null
  employee_name: string
  require_anonymity: boolean
}

interface Question {
  id: number
  question: string
  category: string
  question_type: 'rating' | 'text' | 'both'
  sort_order: number
}

interface ExistingResponse {
  rating_value: number | null
  text_value: string | null
}

const props = defineProps<{
  rater:     RaterData
  review:    ReviewData
  questions: Question[]
  existing:  Record<number, ExistingResponse>
}>()

// Initialise responses from existing values
const responses = reactive<Record<number, { rating: number | null; comment: string }>>(
  Object.fromEntries(
    props.questions.map(q => [
      q.id,
      {
        rating:  props.existing[q.id]?.rating_value ?? null,
        comment: props.existing[q.id]?.text_value   ?? '',
      },
    ])
  )
)

// Group by category maintaining sort order
const groupedQuestions = computed(() => {
  const groups: Record<string, Question[]> = {}
  for (const q of props.questions) {
    if (!groups[q.category]) groups[q.category] = []
    groups[q.category].push(q)
  }
  return groups
})

// Submit
const responseForm = ref()
const submitting   = ref(false)

async function submitResponses() {
  const { valid } = await responseForm.value.validate()
  if (!valid) return

  const payload = {
    responses: props.questions.map(q => ({
      question_id: q.id,
      rating:      responses[q.id].rating,
      comment:     responses[q.id].comment || null,
    })),
  }

  submitting.value = true
  router.post(route('performance.360.respond.submit', props.rater.id), payload, {
    onFinish: () => { submitting.value = false },
  })
}

// Helpers
const relationshipMap: Record<string, string> = {
  self:          'Self Assessment',
  manager:       'Manager',
  peer:          'Peer',
  direct_report: 'Direct Report',
  external:      'External',
}

function relationshipLabel(rel: string) {
  return relationshipMap[rel] ?? rel
}
</script>
