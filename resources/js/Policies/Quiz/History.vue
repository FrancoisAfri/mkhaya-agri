<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { ref, computed } from 'vue'
import { formatDateTime } from '@/utils/policyFormatters'

const route = ziggyRoute

// ── Types ──────────────────────────────────────────────────────────────────────

type GradedAnswer = {
  question_id:    number
  question_text:  string
  given_answer:   string
  correct_answer: string
  is_correct:     boolean
  explanation:    string | null
}

type Attempt = {
  id:                     number
  attempt_number:         number
  score:                  number
  passed:                 boolean
  total_questions:        number
  correct_answers_count:  number
  completed_at:           string | null
  answers:                GradedAnswer[] | null
}

type Settings = {
  pass_mark:            number
  max_attempts:         number
  show_correct_answers: boolean
}

// ── Props ──────────────────────────────────────────────────────────────────────

const props = defineProps<{
  policy:     { id: number; uuid: string; name: string }
  attempts:   Attempt[]
  settings:   Settings
  attestation_uuid: string | null
  can_retake: boolean
  has_passed: boolean
}>()

// ── Expanded detail ───────────────────────────────────────────────────────────

const expandedAttempt = ref<number | null>(null)

function toggleDetail(id: number) {
  expandedAttempt.value = expandedAttempt.value === id ? null : id
}

// ── Formatted helpers ─────────────────────────────────────────────────────────

function formatDate(dt: string | null) {
  return formatDateTime(dt)
}

const attemptsUsed = computed(() => props.attempts.length)

const attemptsRemaining = computed(() => {
  if (props.settings.max_attempts === 0) return null // unlimited

  return Math.max(0, props.settings.max_attempts - attemptsUsed.value)
})

const attemptHeaders = [
  { title: 'Attempt', key: 'attempt_number' },
  { title: 'Score', key: 'score' },
  { title: 'Result', key: 'passed' },
  { title: 'Correct', key: 'correct_answers_count' },
  { title: 'Completed', key: 'completed_at' },
  { title: 'Actions', key: 'actions', sortable: false, align: 'center' },
]
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
      <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4">
        <div>
          <h3 class="text-h5 font-weight-semibold mb-1">Policy Quiz History</h3>
          <p class="text-medium-emphasis mb-0">Review previous attempts for {{ policy.name }}</p>
        </div>
        <div class="d-flex align-center gap-2 flex-wrap">
          <VBtn
            color="secondary"
            variant="tonal"
            prepend-icon="tabler-list"
            :href="route('policies.attestations.index')"
          >
            My Attestations
          </VBtn>
          <VBtn
            v-if="attestation_uuid"
            color="info"
            variant="tonal"
            prepend-icon="tabler-arrow-back-up"
            :href="route('policies.attestations.show', attestation_uuid)"
          >
            Back To Attestation
          </VBtn>
          <VBtn v-if="can_retake" color="primary" prepend-icon="ri-refresh-line" @click="router.visit(route('policies.quiz.take', policy.uuid))">
            {{ attempts.length === 0 ? 'Start Quiz' : 'Retake Quiz' }}
          </VBtn>
        </div>
      </div>

      <VRow class="mb-4">
        <VCol cols="12" md="4">
          <VCard color="success" variant="tonal" elevation="0" border rounded="lg">
            <VCardText>
              <div class="text-caption text-medium-emphasis">Best Result</div>
              <div class="text-h5 font-weight-semibold">{{ attempts.length ? Math.max(...attempts.map(a => a.score)) : 0 }}%</div>
            </VCardText>
          </VCard>
        </VCol>
        <VCol cols="12" md="4">
          <VCard color="primary" variant="tonal" elevation="0" border rounded="lg">
            <VCardText>
              <div class="text-caption text-medium-emphasis">Pass Mark</div>
              <div class="text-h5 font-weight-semibold">{{ settings.pass_mark }}%</div>
            </VCardText>
          </VCard>
        </VCol>
        <VCol cols="12" md="4">
          <VCard color="secondary" variant="tonal" elevation="0" border rounded="lg">
            <VCardText>
              <div class="text-caption text-medium-emphasis">Attempts Used</div>
              <div class="text-h5 font-weight-semibold">{{ attemptsUsed }}</div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <VAlert v-if="has_passed" type="success" variant="tonal" class="mb-4">
        You have passed this quiz.
      </VAlert>
      <VAlert v-else-if="!can_retake" type="error" variant="tonal" class="mb-4">
        You have exhausted all {{ settings.max_attempts }} allowed attempts for this quiz and have not passed.
      </VAlert>
      <VAlert v-else-if="attempts.length > 0" type="warning" variant="tonal" class="mb-4">
        You have not passed yet.
        <template v-if="attemptsRemaining !== null">
          You have <strong>{{ attemptsRemaining }}</strong> attempt(s) remaining.
        </template>
        <template v-else>
          You have unlimited retries.
        </template>
      </VAlert>
      <VAlert v-else type="info" variant="tonal" class="mb-4">
        You haven't taken this quiz yet.
      </VAlert>

      <VCard elevation="0" border rounded="lg">
        <VCardTitle>Attempts</VCardTitle>
        <VDivider />
        <VCardText v-if="attempts.length === 0" class="text-center text-medium-emphasis py-12">
          No attempts recorded yet.
        </VCardText>
        <VDataTable
          v-else
          :headers="attemptHeaders"
          :items="attempts"
          item-value="id"
          hover
          class="text-no-wrap"
        >
          <template #item.score="{ item }">
            <VChip :color="item.passed ? 'success' : 'error'" variant="tonal" label size="small">
              {{ item.score }}%
            </VChip>
          </template>

          <template #item.passed="{ item }">
            <VChip :color="item.passed ? 'success' : 'error'" variant="tonal" label size="small">
              {{ item.passed ? 'PASSED' : 'FAILED' }}
            </VChip>
          </template>

          <template #item.completed_at="{ item }">
            {{ formatDate(item.completed_at) }}
          </template>

          <template #item.actions="{ item }">
            <VBtn
              v-if="settings.show_correct_answers && item.answers"
              variant="tonal"
              size="small"
              @click="toggleDetail(item.id)"
            >
              {{ expandedAttempt === item.id ? 'Hide Details' : 'View Details' }}
            </VBtn>
          </template>

        </VDataTable>

        <VCardText v-if="expandedAttempt && attempts.find(a => a.id === expandedAttempt)?.answers" class="pt-0">
          <VDivider class="mb-4" />
          <h6 class="text-h6 mb-4">Answer Breakdown</h6>
          <div
            v-for="(ans, i) in (attempts.find(a => a.id === expandedAttempt)?.answers || [])"
            :key="ans.question_id"
            class="mb-4"
          >
            <div class="d-flex gap-2 align-start mb-1">
              <VIcon :color="ans.is_correct ? 'success' : 'error'" size="18" class="mt-1">
                {{ ans.is_correct ? 'ri-checkbox-circle-line' : 'ri-close-circle-line' }}
              </VIcon>
              <div>
                <p class="text-body-2 font-weight-medium mb-0">Q{{ i + 1 }}. {{ ans.question_text }}</p>
                <p class="text-body-2 mb-0">
                  Your answer: <strong>{{ ans.given_answer || '(no answer)' }}</strong>
                  <template v-if="!ans.is_correct">
                    &nbsp;·&nbsp; Correct: <strong class="text-success">{{ ans.correct_answer }}</strong>
                  </template>
                </p>
                <p v-if="ans.explanation" class="text-body-2 text-medium-emphasis mt-1 mb-0">
                  {{ ans.explanation }}
                </p>
              </div>
            </div>
          </div>
        </VCardText>
      </VCard>
  </VContainer>
</template>
