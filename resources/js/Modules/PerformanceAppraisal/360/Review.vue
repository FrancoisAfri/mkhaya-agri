<template>
  <VContainer fluid class="pa-6">
    <!-- Header -->
    <div class="d-flex align-center mb-2">
      <VBtn icon variant="text" @click="router.visit(route('performance.360.index'))" class="mr-2">
        <VIcon>tabler-arrow-left</VIcon>
      </VBtn>
      <div class="flex-grow-1">
        <h1 class="text-h5 font-weight-bold">{{ review.title }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">
          Subject: {{ review.employee_name }}
          <span v-if="review.cycle_name"> · {{ review.cycle_name }}</span>
          <span v-if="review.due_date"> · Due {{ review.due_date }}</span>
        </p>
      </div>
      <div class="d-flex ga-2 align-center">
        <VChip :color="statusColor(review.status)" variant="tonal">{{ review.status }}</VChip>

        <VBtn
          v-if="review.status === 'draft'"
          color="primary"
          variant="flat"
          prepend-icon="tabler-player-play"
          @click="openCollection"
          :loading="transitioning"
        >
          Open Collection
        </VBtn>
        <VBtn
          v-else-if="review.status === 'collecting'"
          color="warning"
          variant="flat"
          prepend-icon="tabler-player-stop"
          @click="closeCollection"
          :loading="transitioning"
        >
          Close Collection
        </VBtn>
        <VBtn
          v-if="['closed', 'collecting'].includes(review.status)"
          color="secondary"
          variant="tonal"
          prepend-icon="tabler-robot"
          @click="generateReport"
          :loading="generating"
        >
          Generate AI Report
        </VBtn>
      </div>
    </div>

    <VDivider class="mb-5" />

    <VRow>
      <!-- Left column: raters + info -->
      <VCol cols="12" md="5">
        <!-- Completion card -->
        <VCard border flat class="mb-4">
          <VCardText>
            <div class="d-flex justify-space-between align-center mb-2">
              <span class="text-body-2 font-weight-medium">Completion Rate</span>
              <span class="text-h6 font-weight-bold">{{ review.completion_rate }}%</span>
            </div>
            <VProgressLinear
              :model-value="review.completion_rate"
              color="primary"
              height="8"
              rounded
            />
            <p class="text-caption text-medium-emphasis mt-1">
              {{ raters.filter(r => r.status === 'submitted').length }} of {{ raters.length }} raters submitted
            </p>
            <VChip
              v-if="review.require_anonymity"
              size="x-small"
              color="purple"
              variant="tonal"
              class="mt-2"
            >
              <VIcon start size="12">tabler-eye-off</VIcon>
              Anonymous
            </VChip>
          </VCardText>
        </VCard>

        <!-- Add rater -->
        <VCard border flat class="mb-4">
          <VCardTitle class="text-body-2 font-weight-bold pa-4 pb-0">{{ t('pa.review360.add_employee') }}</VCardTitle>
          <VCardText>
            <VForm ref="raterForm" @submit.prevent="addRater">
              <VSelect
                v-model="raterData.rater_id"
                :items="employees"
                item-title="name"
                item-value="id"
                label="Person"
                density="compact"
                variant="outlined"
                :rules="[v => !!v || 'Required']"
                class="mb-2"
              />
              <VSelect
                v-model="raterData.relationship"
                :items="relationships"
                item-title="label"
                item-value="value"
                label="Relationship"
                density="compact"
                variant="outlined"
                :rules="[v => !!v || 'Required']"
                class="mb-3"
              />
              <VBtn type="submit" color="primary" block size="small" :loading="addingRater">
                Add Rater
              </VBtn>
            </VForm>
          </VCardText>
        </VCard>

        <!-- Rater list -->
        <VCard border flat>
          <VCardTitle class="text-body-2 font-weight-bold pa-4 pb-2">Raters</VCardTitle>
          <VList density="compact" class="pa-2">
            <VListItem
              v-for="rater in raters"
              :key="rater.id"
              class="mb-1"
              :class="{ 'border': true }"
            >
              <template #prepend>
                <VIcon :color="raterStatusColor(rater.status)" size="16">
                  {{ rater.status === 'submitted' ? 'tabler-circle-check' : 'tabler-clock' }}
                </VIcon>
              </template>
              <template #title>
                <span class="text-body-2">{{ rater.rater_name }}</span>
              </template>
              <template #subtitle>
                <span class="text-caption">{{ rater.relationship }}</span>
                <span v-if="rater.submitted_at" class="text-caption text-medium-emphasis"> · {{ rater.submitted_at }}</span>
              </template>
              <template #append>
                <VBtn
                  icon
                  size="x-small"
                  variant="text"
                  color="error"
                  @click="removeRater(rater.id)"
                >
                  <VIcon>tabler-trash</VIcon>
                </VBtn>
              </template>
            </VListItem>
            <VListItem v-if="!raters.length">
              <span class="text-caption text-medium-emphasis">No raters added yet.</span>
            </VListItem>
          </VList>
        </VCard>
      </VCol>

      <!-- Right column: report / instructions -->
      <VCol cols="12" md="7">
        <!-- Instructions -->
        <VCard v-if="review.instructions" border flat class="mb-4">
          <VCardTitle class="text-body-2 font-weight-bold pa-4 pb-1">Instructions</VCardTitle>
          <VCardText class="text-body-2 text-medium-emphasis">{{ review.instructions }}</VCardText>
        </VCard>

        <!-- AI Summary -->
        <VCard v-if="review.ai_summary" border flat class="mb-4">
          <VCardItem>
            <template #prepend>
              <VAvatar color="secondary" variant="tonal" size="32">
                <VIcon size="16">tabler-robot</VIcon>
              </VAvatar>
            </template>
            <VCardTitle class="text-body-2 font-weight-bold">AI Narrative Summary</VCardTitle>
          </VCardItem>
          <VCardText class="text-body-2" style="white-space: pre-wrap">{{ review.ai_summary }}</VCardText>
        </VCard>

        <!-- Report data -->
        <template v-if="report">
          <!-- Category scores -->
          <VCard border flat class="mb-4">
            <VCardTitle class="text-body-2 font-weight-bold pa-4 pb-2">Category Scores</VCardTitle>
            <VCardText>
              <div v-for="(score, cat) in report.category_scores" :key="cat" class="mb-3">
                <div class="d-flex justify-space-between text-body-2 mb-1">
                  <span class="text-capitalize">{{ cat }}</span>
                  <span class="font-weight-medium">{{ score }}/5</span>
                </div>
                <VProgressLinear
                  :model-value="(score / 5) * 100"
                  :color="scoreColor(score)"
                  height="6"
                  rounded
                />
              </div>
            </VCardText>
          </VCard>

          <!-- Per-question breakdown -->
          <VExpansionPanels variant="accordion" border class="mb-4">
            <VExpansionPanel
              v-for="(q, idx) in report.questions"
              :key="idx"
            >
              <VExpansionPanelTitle class="text-body-2">
                <div class="d-flex align-center ga-2">
                  <VChip size="x-small" color="secondary" variant="tonal">{{ q.category }}</VChip>
                  <span>{{ q.question }}</span>
                  <VChip v-if="q.avg_rating !== null" size="x-small" :color="scoreColor(q.avg_rating)" variant="tonal">
                    {{ q.avg_rating }}/5
                  </VChip>
                </div>
              </VExpansionPanelTitle>
              <VExpansionPanelText>
                <!-- By relationship -->
                <div v-if="Object.keys(q.by_relationship).length" class="mb-3">
                  <p class="text-caption font-weight-bold text-medium-emphasis mb-1">Rating by Group</p>
                  <div v-for="(avg, rel) in q.by_relationship" :key="rel" class="d-flex align-center ga-2 mb-1">
                    <span class="text-caption text-capitalize" style="min-width: 100px">{{ rel }}</span>
                    <VProgressLinear
                      :model-value="(avg / 5) * 100"
                      :color="scoreColor(avg)"
                      height="6"
                      rounded
                      class="flex-grow-1"
                    />
                    <span class="text-caption font-weight-medium" style="min-width: 28px">{{ avg }}</span>
                  </div>
                </div>

                <!-- Comments -->
                <div v-if="q.comments.length">
                  <p class="text-caption font-weight-bold text-medium-emphasis mb-1">Comments</p>
                  <VList density="compact" class="pa-0">
                    <template v-if="review.require_anonymity">
                      <VListItem
                        v-for="(c, ci) in q.comments"
                        :key="ci"
                        :subtitle="c"
                        class="pa-2"
                      />
                    </template>
                    <template v-else>
                      <VListItem
                        v-for="(c, ci) in q.comments"
                        :key="ci"
                        :title="c.relationship"
                        :subtitle="c.comment"
                        class="pa-2"
                      />
                    </template>
                  </VList>
                </div>
              </VExpansionPanelText>
            </VExpansionPanel>
          </VExpansionPanels>
        </template>

        <VAlert v-else-if="review.status === 'draft'" type="info" variant="tonal">
          Open the review collection for raters to start submitting feedback. The report will appear here once there are submissions.
        </VAlert>
      </VCol>
    </VRow>
  </VContainer>
</template>

<script setup lang="ts">
import { ref, reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'

const { t } = useI18n()

interface ReviewData {
  id: number; title: string; instructions: string | null; due_date: string | null
  status: string; require_anonymity: boolean; ai_summary: string | null
  employee_id: number; employee_name: string; cycle_name: string | null; completion_rate: number
}

interface Rater {
  id: number; rater_id: number; rater_name: string; relationship: string
  status: string; submitted_at: string | null
}

interface Question { id: number; question: string; category: string; question_type: string }

interface Report {
  review_id: number; employee_id: number; title: string
  submitted_count: number; completion_rate: number
  category_scores: Record<string, number>
  questions: Array<{
    question: string; category: string; avg_rating: number | null
    rating_count: number; by_relationship: Record<string, number>
    comments: any[]
  }>
}

interface Employee { id: number; name: string }

const props = defineProps<{
  review:    ReviewData
  raters:    Rater[]
  questions: Question[]
  employees: Employee[]
  report:    Report | null
}>()

// Transitions
const transitioning = ref(false)
const generating    = ref(false)

function openCollection() {
  transitioning.value = true
  router.patch(route('performance.360.open', props.review.id), {}, {
    onFinish: () => { transitioning.value = false },
  })
}

function closeCollection() {
  transitioning.value = true
  router.patch(route('performance.360.close', props.review.id), {}, {
    onFinish: () => { transitioning.value = false },
  })
}

function generateReport() {
  generating.value = true
  router.post(route('performance.360.report', props.review.id), {}, {
    onFinish: () => { generating.value = false },
  })
}

// Rater management
const raterForm  = ref()
const addingRater = ref(false)
const raterData  = reactive({ rater_id: null as number | null, relationship: '' })

const relationships = [
  { value: 'self',          label: 'Self' },
  { value: 'manager',       label: 'Manager' },
  { value: 'peer',          label: 'Peer' },
  { value: 'direct_report', label: 'Direct Report' },
  { value: 'external',      label: 'External' },
]

async function addRater() {
  const { valid } = await raterForm.value.validate()
  if (!valid) return
  addingRater.value = true
  router.post(route('performance.360.raters.add', props.review.id), raterData as any, {
    onSuccess: () => { raterData.rater_id = null; raterData.relationship = '' },
    onFinish:  () => { addingRater.value = false },
  })
}

function removeRater(raterId: number) {
  router.delete(route('performance.360.raters.remove', { review: props.review.id, rater: raterId }))
}

// Helpers
function statusColor(status: string) {
  return { draft: 'grey', collecting: 'blue', closed: 'orange', reported: 'green' }[status] ?? 'grey'
}

function raterStatusColor(status: string) {
  return { pending: 'orange', submitted: 'green', declined: 'error' }[status] ?? 'grey'
}

function scoreColor(score: number) {
  if (score >= 4)   return 'green'
  if (score >= 3)   return 'blue'
  if (score >= 2)   return 'orange'
  return 'error'
}
</script>
