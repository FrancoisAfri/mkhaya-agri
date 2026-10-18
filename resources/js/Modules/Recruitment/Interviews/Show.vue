<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Question = {
  id: number
  question: string
  category: string
  ai_generated: boolean
  order: number
}

type Score = {
  overall_score: number | null
  technical_score: number | null
  communication_score: number | null
  culture_fit_score: number | null
  recommendation: string | null
  comments: string | null
}

const props = defineProps<{
  job: { id: number; title: string }
  application: { id: number }
  interview: {
    id: number
    type: string
    type_label: string
    status: string
    scheduled_at: string
    duration_minutes: number | null
    location: string | null
    meeting_url: string | null
    notes: string | null
    interviewer: { id: number; name: string } | null
    questions: Question[]
    score: Score | null
    created_at: string
  }
  candidate: { id: number; full_name: string }
}>()

// ─── Status colour map ────────────────────────────────────────────────────────
const statusColor = computed(() => {
  const map: Record<string, string> = {
    scheduled:  'info',
    completed:  'success',
    cancelled:  'error',
  }
  return map[props.interview.status] ?? 'default'
})

// ─── Grouped questions ────────────────────────────────────────────────────────
const questionsByCategory = computed(() => {
  const groups: Record<string, Question[]> = {}
  for (const q of props.interview.questions ?? []) {
    if (!groups[q.category]) groups[q.category] = []
    groups[q.category].push(q)
  }
  return groups
})

// ─── Recommendation options ───────────────────────────────────────────────────
const recommendationOptions = [
  { value: 'strong_yes', label: 'Strong Yes', color: 'success' },
  { value: 'yes',        label: 'Yes',        color: 'success' },
  { value: 'maybe',      label: 'Maybe',      color: 'warning' },
  { value: 'no',         label: 'No',         color: 'error'   },
  { value: 'strong_no',  label: 'Strong No',  color: 'error'   },
]

function recommendationColor(val: string | null) {
  return recommendationOptions.find(o => o.value === val)?.color ?? 'default'
}

function recommendationLabel(val: string | null) {
  return recommendationOptions.find(o => o.value === val)?.label ?? val ?? '—'
}

// ─── Generating state (local spinner while waiting for page refresh) ──────────
const generating = ref(false)

// ─── Forms ────────────────────────────────────────────────────────────────────
const generateForm = useForm({})
function generateQuestions() {
  generating.value = true
  generateForm.post(
    route('recruitment.jobs.applications.interviews.generate-questions', [props.job.id, props.application.id, props.interview.id]),
    { onFinish: () => { generating.value = false } }
  )
}

const completeForm = useForm({})
function markComplete() {
  completeForm.post(
    route('recruitment.jobs.applications.interviews.complete', [props.job.id, props.application.id, props.interview.id])
  )
}

const cancelForm = useForm({})
function cancelInterview() {
  cancelForm.post(
    route('recruitment.jobs.applications.interviews.cancel', [props.job.id, props.application.id, props.interview.id])
  )
}

const scoreForm = useForm({
  technical_score:     0 as number,
  communication_score: 0 as number,
  culture_fit_score:   0 as number,
  overall_score:       0 as number,
  recommendation:      'maybe' as string,
  comments:            '' as string,
})

function submitScore() {
  scoreForm.post(
    route('recruitment.jobs.applications.interviews.score', [props.job.id, props.application.id, props.interview.id])
  )
}

// ─── Helpers ─────────────────────────────────────────────────────────────────
function formatDateTime(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleString('en-ZA', {
    day:    '2-digit',
    month:  'short',
    year:   'numeric',
    hour:   '2-digit',
    minute: '2-digit',
  })
}
</script>

<template>
  <div class="pa-6">
    <!-- Breadcrumb -->
    <div class="d-flex align-center gap-2 mb-4 flex-wrap">
      <span
        class="text-medium-emphasis text-body-2 cursor-pointer"
        @click="router.visit(route('recruitment.jobs.index'))"
      >
        Recruitment
      </span>
      <VIcon icon="tabler-chevron-right" size="16" class="text-medium-emphasis" />
      <span
        class="text-medium-emphasis text-body-2 cursor-pointer"
        @click="router.visit(route('recruitment.jobs.index'))"
      >
        Jobs
      </span>
      <VIcon icon="tabler-chevron-right" size="16" class="text-medium-emphasis" />
      <span
        class="text-medium-emphasis text-body-2 cursor-pointer"
        @click="router.visit(route('recruitment.jobs.show', job.id))"
      >
        {{ job.title }}
      </span>
      <VIcon icon="tabler-chevron-right" size="16" class="text-medium-emphasis" />
      <span
        class="text-medium-emphasis text-body-2 cursor-pointer"
        @click="router.visit(route('recruitment.jobs.applications.index', job.id))"
      >
        Applications
      </span>
      <VIcon icon="tabler-chevron-right" size="16" class="text-medium-emphasis" />
      <span
        class="text-medium-emphasis text-body-2 cursor-pointer"
        @click="router.visit(route('recruitment.jobs.applications.show', [job.id, application.id]))"
      >
        {{ candidate.full_name }}
      </span>
      <VIcon icon="tabler-chevron-right" size="16" class="text-medium-emphasis" />
      <span class="text-body-2 font-weight-medium">Interview</span>
    </div>

    <!-- Page Header -->
    <div class="d-flex align-start justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <div class="d-flex align-center gap-2 mb-1 flex-wrap">
          <h4 class="text-h4 font-weight-bold">
            {{ interview.type_label }} Interview &mdash; {{ candidate.full_name }}
          </h4>
          <VChip :color="statusColor" size="small" variant="tonal" class="text-capitalize font-weight-medium">
            {{ interview.status }}
          </VChip>
        </div>
        <p class="text-medium-emphasis mb-0">
          Scheduled: {{ formatDateTime(interview.scheduled_at) }}
        </p>
      </div>
      <div class="d-flex align-center gap-2 flex-wrap">
        <VBtn
          color="info"
          variant="tonal"
          prepend-icon="tabler-robot"
          :loading="generateForm.processing || generating"
          @click="generateQuestions"
        >
          Generate AI Questions
        </VBtn>
        <VBtn
          v-if="interview.status === 'scheduled'"
          color="success"
          variant="tonal"
          prepend-icon="tabler-circle-check"
          :loading="completeForm.processing"
          @click="markComplete"
        >
          Mark Complete
        </VBtn>
        <VBtn
          v-if="interview.status === 'scheduled'"
          color="error"
          variant="tonal"
          prepend-icon="tabler-circle-x"
          :loading="cancelForm.processing"
          @click="cancelInterview"
        >
          Cancel Interview
        </VBtn>
      </div>
    </div>

    <VRow>
      <!-- ── Left: main content ─────────────────────────────────────────── -->
      <VCol cols="12" lg="8">

        <!-- Interview Details card -->
        <VCard elevation="0" border class="mb-4">
          <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">
            Interview Details
          </VCardTitle>
          <VCardText class="pa-5 pt-0">
            <div class="d-flex flex-column gap-3">
              <div class="d-flex align-center gap-2">
                <VChip color="primary" size="small" variant="tonal" label class="font-weight-medium">
                  {{ interview.type_label }}
                </VChip>
                <span class="text-body-2 text-medium-emphasis">
                  {{ formatDateTime(interview.scheduled_at) }}
                </span>
              </div>

              <div v-if="interview.duration_minutes" class="d-flex align-center gap-2">
                <VIcon icon="tabler-clock" size="16" class="text-medium-emphasis" />
                <span class="text-body-2">{{ interview.duration_minutes }} minutes</span>
              </div>

              <div v-if="interview.location" class="d-flex align-center gap-2">
                <VIcon icon="tabler-map-pin" size="16" class="text-medium-emphasis" />
                <span class="text-body-2">{{ interview.location }}</span>
              </div>

              <div v-if="interview.meeting_url" class="d-flex align-center gap-2">
                <VIcon icon="tabler-video" size="16" class="text-medium-emphasis" />
                <a
                  :href="interview.meeting_url"
                  target="_blank"
                  rel="noopener"
                  class="text-body-2 text-info"
                >
                  {{ interview.meeting_url }}
                </a>
              </div>

              <div v-if="interview.interviewer" class="d-flex align-center gap-2">
                <VIcon icon="tabler-user" size="16" class="text-medium-emphasis" />
                <span class="text-body-2">{{ interview.interviewer.name }}</span>
                <span class="text-caption text-medium-emphasis">(Interviewer)</span>
              </div>

              <div v-if="interview.notes">
                <VDivider class="my-2" />
                <p class="text-caption text-medium-emphasis mb-1">Notes</p>
                <p class="text-body-2" style="white-space: pre-wrap;">{{ interview.notes }}</p>
              </div>
            </div>
          </VCardText>
        </VCard>

        <!-- Questions card -->
        <VCard elevation="0" border class="mb-4">
          <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold d-flex align-center gap-2">
            Interview Questions
            <VBadge
              v-if="interview.questions?.length"
              :content="interview.questions.length"
              color="primary"
              inline
            />
          </VCardTitle>
          <VCardText class="pa-5 pt-0">
            <!-- Generating spinner -->
            <VProgressLinear
              v-if="generating"
              indeterminate
              color="info"
              class="mb-4"
              rounded
            />

            <!-- Empty state -->
            <div
              v-if="!interview.questions?.length && !generating"
              class="text-center py-8 text-medium-emphasis"
            >
              <VIcon icon="tabler-robot" size="48" color="info" class="mb-3 d-block mx-auto" />
              <p class="text-body-1 font-weight-medium mb-1">No questions yet</p>
              <p class="text-body-2 text-medium-emphasis mb-0">
                Click "Generate AI Questions" to get tailored questions for this interview.
              </p>
            </div>

            <!-- Question groups -->
            <div v-if="interview.questions?.length" class="d-flex flex-column gap-4">
              <div
                v-for="(questions, category) in questionsByCategory"
                :key="category"
              >
                <p class="text-caption text-medium-emphasis font-weight-semibold text-uppercase mb-2">
                  {{ category }}
                </p>
                <div class="d-flex flex-column gap-2">
                  <div
                    v-for="(q, qi) in questions"
                    :key="q.id"
                    class="pa-3 rounded-lg d-flex align-start gap-3"
                    style="border: 1px solid rgba(0,0,0,0.08);"
                  >
                    <span class="text-caption text-medium-emphasis font-weight-bold mt-1">
                      {{ qi + 1 }}.
                    </span>
                    <div class="flex-grow-1">
                      <p class="text-body-2 mb-1">{{ q.question }}</p>
                      <div class="d-flex align-center gap-2">
                        <VChip size="x-small" variant="tonal" color="default" label>
                          {{ q.category }}
                        </VChip>
                        <VIcon
                          v-if="q.ai_generated"
                          icon="tabler-robot"
                          size="14"
                          color="info"
                          title="AI Generated"
                        />
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </VCardText>
        </VCard>

        <!-- Score Submission card -->
        <VCard
          v-if="interview.status === 'scheduled' || (interview.status === 'completed' && !interview.score)"
          elevation="0"
          border
        >
          <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">
            Submit Score
          </VCardTitle>
          <VCardText class="pa-5 pt-0">
            <VRow dense>
              <VCol cols="12" md="6">
                <p class="text-body-2 font-weight-medium mb-1">
                  Technical
                  <span class="text-medium-emphasis ms-1">{{ scoreForm.technical_score }}</span>
                </p>
                <VSlider
                  v-model="scoreForm.technical_score"
                  :min="0"
                  :max="100"
                  :step="5"
                  color="primary"
                  track-color="grey-lighten-3"
                  thumb-label
                  class="mb-2"
                />
              </VCol>
              <VCol cols="12" md="6">
                <p class="text-body-2 font-weight-medium mb-1">
                  Communication
                  <span class="text-medium-emphasis ms-1">{{ scoreForm.communication_score }}</span>
                </p>
                <VSlider
                  v-model="scoreForm.communication_score"
                  :min="0"
                  :max="100"
                  :step="5"
                  color="info"
                  track-color="grey-lighten-3"
                  thumb-label
                  class="mb-2"
                />
              </VCol>
              <VCol cols="12" md="6">
                <p class="text-body-2 font-weight-medium mb-1">
                  Culture Fit
                  <span class="text-medium-emphasis ms-1">{{ scoreForm.culture_fit_score }}</span>
                </p>
                <VSlider
                  v-model="scoreForm.culture_fit_score"
                  :min="0"
                  :max="100"
                  :step="5"
                  color="success"
                  track-color="grey-lighten-3"
                  thumb-label
                  class="mb-2"
                />
              </VCol>
              <VCol cols="12" md="6">
                <p class="text-body-2 font-weight-medium mb-1">
                  Overall
                  <span class="text-medium-emphasis ms-1">{{ scoreForm.overall_score }}</span>
                </p>
                <VSlider
                  v-model="scoreForm.overall_score"
                  :min="0"
                  :max="100"
                  :step="5"
                  color="warning"
                  track-color="grey-lighten-3"
                  thumb-label
                  class="mb-2"
                />
              </VCol>
              <VCol cols="12" md="6">
                <VSelect
                  v-model="scoreForm.recommendation"
                  :items="recommendationOptions"
                  item-title="label"
                  item-value="value"
                  label="Recommendation"
                  variant="outlined"
                  density="comfortable"
                  :error-messages="scoreForm.errors.recommendation"
                />
              </VCol>
              <VCol cols="12">
                <VTextarea
                  v-model="scoreForm.comments"
                  label="Comments"
                  variant="outlined"
                  density="comfortable"
                  rows="3"
                  placeholder="Additional notes or observations..."
                  :error-messages="scoreForm.errors.comments"
                />
              </VCol>
            </VRow>
          </VCardText>
          <VCardActions class="pa-5 pt-0">
            <VSpacer />
            <VBtn
              color="primary"
              :loading="scoreForm.processing"
              @click="submitScore"
            >
              Submit Score
            </VBtn>
          </VCardActions>
        </VCard>

      </VCol>

      <!-- ── Right: sidebar ─────────────────────────────────────────────── -->
      <VCol cols="12" lg="4">
        <div style="position: sticky; top: 80px;" class="d-flex flex-column gap-4">

          <!-- AI Score Results card -->
          <VCard
            v-if="interview.score"
            elevation="0"
            border
          >
            <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold d-flex align-center gap-2">
              <VIcon icon="tabler-robot" color="info" size="18" />
              AI Assessment
            </VCardTitle>
            <VCardText class="pa-5 pt-0">
              <div class="d-flex flex-column gap-3">
                <!-- Overall -->
                <div>
                  <div class="d-flex justify-space-between mb-1">
                    <span class="text-body-2">Overall</span>
                    <span class="text-body-2 font-weight-medium">
                      {{ interview.score.overall_score ?? '—' }}
                    </span>
                  </div>
                  <VProgressLinear
                    :model-value="interview.score.overall_score ?? 0"
                    color="primary"
                    height="6"
                    rounded
                  />
                </div>
                <!-- Technical -->
                <div>
                  <div class="d-flex justify-space-between mb-1">
                    <span class="text-body-2">Technical</span>
                    <span class="text-body-2 font-weight-medium">
                      {{ interview.score.technical_score ?? '—' }}
                    </span>
                  </div>
                  <VProgressLinear
                    :model-value="interview.score.technical_score ?? 0"
                    color="info"
                    height="6"
                    rounded
                  />
                </div>
                <!-- Communication -->
                <div>
                  <div class="d-flex justify-space-between mb-1">
                    <span class="text-body-2">Communication</span>
                    <span class="text-body-2 font-weight-medium">
                      {{ interview.score.communication_score ?? '—' }}
                    </span>
                  </div>
                  <VProgressLinear
                    :model-value="interview.score.communication_score ?? 0"
                    color="success"
                    height="6"
                    rounded
                  />
                </div>
                <!-- Culture Fit -->
                <div>
                  <div class="d-flex justify-space-between mb-1">
                    <span class="text-body-2">Culture Fit</span>
                    <span class="text-body-2 font-weight-medium">
                      {{ interview.score.culture_fit_score ?? '—' }}
                    </span>
                  </div>
                  <VProgressLinear
                    :model-value="interview.score.culture_fit_score ?? 0"
                    color="warning"
                    height="6"
                    rounded
                  />
                </div>

                <VDivider />

                <!-- Recommendation -->
                <div class="d-flex align-center justify-space-between">
                  <span class="text-body-2">Recommendation</span>
                  <VChip
                    :color="recommendationColor(interview.score.recommendation)"
                    size="small"
                    variant="tonal"
                    label
                  >
                    {{ recommendationLabel(interview.score.recommendation) }}
                  </VChip>
                </div>

                <!-- Comments -->
                <VAlert
                  v-if="interview.score.comments"
                  type="info"
                  variant="tonal"
                  density="compact"
                  class="text-body-2"
                >
                  {{ interview.score.comments }}
                </VAlert>
              </div>
            </VCardText>
          </VCard>

          <!-- Quick Actions card -->
          <VCard elevation="0" border>
            <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">
              Quick Actions
            </VCardTitle>
            <VCardText class="pa-5 pt-0 d-flex flex-column gap-2">
              <VBtn
                variant="tonal"
                color="primary"
                block
                prepend-icon="tabler-user"
                @click="router.visit(route('recruitment.candidates.show', candidate.id))"
              >
                View Candidate Profile
              </VBtn>
              <VBtn
                variant="tonal"
                color="default"
                block
                prepend-icon="tabler-arrow-left"
                @click="router.visit(route('recruitment.jobs.applications.show', [job.id, application.id]))"
              >
                Back to Application
              </VBtn>
            </VCardText>
          </VCard>

        </div>
      </VCol>
    </VRow>
  </div>
</template>
