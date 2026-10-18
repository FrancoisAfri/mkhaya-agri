<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { computed, ref } from 'vue'

const route = ziggyRoute

type PolicyDoc = { id: number; filename: string; view_url: string }
type PolicyClause = { id: number; heading: string; type: string; body: string }

type AttestationProps = {
  id: number
  uuid: string
  status: string
  deadline: string | null
  viewed_at: string | null
  acknowledged_at: string | null
  signed_at: string | null
  quiz_score: number | null
  is_overdue: boolean
  deadline_passed: boolean
}

type PolicyProps = {
  id: number
  uuid: string
  name: string
  description: string | null
  category: string | null
  effective_date: string | null
  review_date: string | null
  extracted_text: string | null
  documents: PolicyDoc[]
  clauses: PolicyClause[]
}

type QuizData = {
  has_quiz: boolean
  can_attempt: boolean
}

const props = defineProps<{
  attestation: AttestationProps
  policy: PolicyProps
  alreadyCompleted: boolean
  quiz: QuizData
}>()

const activeTab = ref<'clauses' | 'text' | 'document'>('clauses')

const hasScrolledToBottom = ref(false)
function onClauseScroll(event: Event) {
  const el = event.target as HTMLElement
  if (el.scrollHeight - el.scrollTop - el.clientHeight < 80) hasScrolledToBottom.value = true
}

const hasScrolledTextToBottom = ref(false)
function onTextScroll(event: Event) {
  const el = event.target as HTMLElement
  if (el.scrollHeight - el.scrollTop - el.clientHeight < 80) hasScrolledTextToBottom.value = true
}

const searchQuery = ref('')

const filteredClauses = computed(() => {
  if (!searchQuery.value.trim()) return props.policy.clauses
  const q = searchQuery.value.toLowerCase()
  return props.policy.clauses.filter(
    c => c.heading?.toLowerCase().includes(q) || c.body?.toLowerCase().includes(q),
  )
})

function escapeRegex(s: string) {
  return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

const highlightedText = computed(() => {
  const raw = props.policy.extracted_text ?? ''
  if (!searchQuery.value.trim()) return raw
  const q = escapeRegex(searchQuery.value)
  return raw.replace(new RegExp(`(${q})`, 'gi'), '<mark>$1</mark>')
})

const selectedDocIndex = ref(0)
const currentDoc = computed(() => props.policy.documents[selectedDocIndex.value] ?? null)

const readCompleted = computed(() => {
  if (activeTab.value === 'clauses') return hasScrolledToBottom.value || props.policy.clauses.length === 0
  if (activeTab.value === 'text') return hasScrolledTextToBottom.value || !props.policy.extracted_text
  return true
})

const form = useForm({
  acknowledged: false,
  quiz_score: null as number | null,
  notes: '',
})

function submit() {
  form.post(route('policies.attestations.submit', props.attestation.uuid))
}

const canAttest = computed(() => form.acknowledged && readCompleted.value && !form.processing)

const deadlineBlocksAttestation = computed(
  () => props.attestation.deadline_passed || props.attestation.is_overdue,
)

const statusChipColor = computed(() => {
  if (props.attestation.status === 'completed') return 'success'
  if (props.attestation.is_overdue || props.attestation.deadline_passed) return 'error'
  return 'warning'
})
</script>

<template>
  <section class="bg-[#f8f8ff]">
    <VContainer fluid class="pa-4 pa-sm-6">

      <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4">
        <div>
          <div class="text-caption text-medium-emphasis mb-1">Policy Attestation</div>
          <h3 class="text-h5 font-weight-semibold mb-1">{{ policy.name }}</h3>
          <div class="d-flex flex-wrap gap-2 align-center">
            <VChip v-if="policy.category" size="small" color="primary" variant="tonal">{{ policy.category }}</VChip>
            <span v-if="policy.effective_date" class="text-caption text-medium-emphasis">
              Effective: {{ policy.effective_date }}
            </span>
          </div>
        </div>
        <div class="d-flex align-center gap-3">
          <VChip :color="statusChipColor" label size="large" variant="tonal">
            {{ attestation.status.toUpperCase() }}
          </VChip>
          <div v-if="quiz.has_quiz" class="d-flex align-center gap-2">
            <VBtn
              v-if="quiz.can_attempt"
              color="info"
              :href="route('policies.quiz.take', policy.uuid)"
              prepend-icon="tabler-help-circle"
            >
              Take Quick Quiz
            </VBtn>
            <VBtn
              color="secondary"
              variant="tonal"
              :href="route('policies.quiz.history', policy.uuid)"
              prepend-icon="tabler-history"
            >
              Attempts
            </VBtn>
          </div>
          <VBtn variant="text" prepend-icon="tabler-arrow-left" :href="route('policies.attestations.index')">
            Back
          </VBtn>
        </div>
      </div>

      <VAlert
        v-if="alreadyCompleted"
        type="success" variant="tonal" icon="tabler-circle-check" class="mb-4" closable
      >
        <strong>Attestation complete.</strong>
        Signed {{ attestation.signed_at }}
        <template v-if="attestation.quiz_score !== null"> &middot; Quiz score: {{ attestation.quiz_score }}%</template>
      </VAlert>

      <VAlert
        v-else-if="deadlineBlocksAttestation"
        type="error" variant="tonal" icon="tabler-calendar-x" class="mb-4"
      >
        <strong>The deadline has passed ({{ attestation.deadline }}).</strong>
        You can still read the policy but can no longer submit an acknowledgement.
      </VAlert>

      <VAlert
        v-else-if="attestation.deadline"
        type="warning" variant="tonal" icon="tabler-calendar-event" class="mb-4" density="compact"
      >
        Deadline: <strong>{{ attestation.deadline }}</strong>
      </VAlert>

      <VRow>
        <VCol cols="12" :md="!alreadyCompleted && !deadlineBlocksAttestation ? 8 : 12">

          <VCard elevation="0" border rounded="lg" class="mb-4">
            <VCardText class="pa-3">
              <VTextField
                v-model="searchQuery"
                density="compact" hide-details clearable
                prepend-inner-icon="tabler-search"
                placeholder="Search in policy content..."
                variant="outlined"
              />
            </VCardText>
          </VCard>

          <VCard elevation="0" border rounded="lg">
            <VTabs v-model="activeTab" bg-color="transparent" class="border-b">
              <VTab value="clauses" prepend-icon="tabler-list">
                Clauses
                <VChip v-if="policy.clauses.length" size="x-small" class="ms-1" color="primary" variant="tonal">
                  {{ filteredClauses.length }}
                </VChip>
              </VTab>
              <VTab v-if="policy.extracted_text" value="text" prepend-icon="tabler-file-text">
                Full Text
              </VTab>
              <VTab v-if="policy.documents.length" value="document" prepend-icon="tabler-file-description">
                Document Viewer
              </VTab>
            </VTabs>

            <VTabsWindow v-model="activeTab">
              <VTabsWindowItem value="clauses">
                <div
                  style="max-height: 540px; overflow-y: auto; padding: 20px"
                  @scroll="onClauseScroll"
                >
                  <VAlert
                    v-if="searchQuery && !filteredClauses.length"
                    type="info" variant="tonal" density="compact" class="mb-4"
                  >
                    No clauses match "<strong>{{ searchQuery }}</strong>".
                  </VAlert>

                  <div v-for="clause in filteredClauses" :key="clause.id" class="mb-6">
                    <div class="d-flex align-center gap-2 mb-2">
                      <h6 class="text-subtitle-1 font-weight-semibold">{{ clause.heading }}</h6>
                      <VChip v-if="clause.type" size="x-small" variant="tonal" color="secondary">{{ clause.type }}</VChip>
                    </div>
                    <p style="white-space: pre-wrap; line-height: 1.8; font-size: 0.9rem">{{ clause.body }}</p>
                    <VDivider class="mt-3" />
                  </div>

                  <div v-if="!policy.clauses.length" class="text-center py-10 text-medium-emphasis">
                    <VIcon icon="tabler-file-off" size="48" class="mb-3 opacity-40" />
                    <p class="mb-1">No clauses have been extracted yet.</p>
                    <p class="text-caption">Switch to the Full Text or Document Viewer tab to read the policy.</p>
                  </div>

                  <div v-else-if="filteredClauses.length" class="text-center py-3 text-caption text-medium-emphasis">
                    -- End of policy --
                  </div>
                </div>

                <VAlert
                  v-if="policy.clauses.length && !hasScrolledToBottom && !alreadyCompleted && !deadlineBlocksAttestation"
                  type="info" variant="tonal" density="compact" class="ma-4 mt-0"
                >
                  Scroll to the end of the policy to enable attestation.
                </VAlert>
              </VTabsWindowItem>

              <VTabsWindowItem v-if="policy.extracted_text" value="text">
                <div
                  style="max-height: 540px; overflow-y: auto; padding: 20px; font-family: monospace; font-size: 0.85rem; line-height: 1.7; white-space: pre-wrap"
                  @scroll="onTextScroll"
                  v-html="highlightedText"
                />
                <VAlert
                  v-if="!hasScrolledTextToBottom && !alreadyCompleted && !deadlineBlocksAttestation"
                  type="info" variant="tonal" density="compact" class="ma-4 mt-0"
                >
                  Scroll to the end of the text to enable attestation.
                </VAlert>
              </VTabsWindowItem>

              <VTabsWindowItem v-if="policy.documents.length" value="document">
                <div v-if="policy.documents.length > 1" class="px-4 pt-4">
                  <VSelect
                    v-model="selectedDocIndex"
                    :items="policy.documents.map((d, i) => ({ title: d.filename, value: i }))"
                    label="Select document"
                    density="compact" hide-details variant="outlined"
                    style="max-width: 360px"
                  />
                </div>
                <div class="pa-4">
                  <div
                    v-if="currentDoc"
                    style="border: 1px solid rgba(0,0,0,0.12); border-radius: 8px; overflow: hidden"
                  >
                    <iframe
                      :src="currentDoc.view_url"
                      style="width: 100%; height: 560px; border: none; display: block"
                      title="Policy document"
                    />
                  </div>
                  <VAlert v-else type="info" variant="tonal">No document selected.</VAlert>
                </div>
              </VTabsWindowItem>
            </VTabsWindow>
          </VCard>
        </VCol>

        <VCol v-if="!alreadyCompleted && !deadlineBlocksAttestation" cols="12" md="4">
          <VCard elevation="0" border rounded="lg" class="sticky-card">
            <VCardTitle class="pa-4 pb-2 text-subtitle-1 font-weight-semibold">
              Confirm Your Attestation
            </VCardTitle>
            <VCardText class="pa-4 pt-0">
              <VForm @submit.prevent="submit">
                <VTextField
                  v-model.number="form.quiz_score"
                  label="Quiz Score (optional)"
                  type="number" min="0" max="100" suffix="%"
                  density="compact" class="mb-4"
                />

                <VTextarea
                  v-model="form.notes"
                  label="Notes (optional)"
                  rows="3" density="compact" class="mb-4"
                />

                <VCheckbox
                  v-model="form.acknowledged"
                  :disabled="!readCompleted"
                  color="primary" class="mb-2"
                >
                  <template #label>
                    <span class="text-body-2">
                      I confirm I have read, understood, and accept the
                      <strong>{{ policy.name }}</strong> policy and agree to comply with its requirements.
                    </span>
                  </template>
                </VCheckbox>

                <p v-if="!readCompleted" class="text-caption text-warning ms-9 mb-4">
                  Please read the full policy content before confirming.
                </p>

                <VBtn
                  type="submit" color="success" block size="large"
                  :disabled="!canAttest" :loading="form.processing"
                  prepend-icon="tabler-signature"
                >
                  Submit Acknowledgement
                </VBtn>

                <VAlert
                  v-if="Object.keys(form.errors).length"
                  type="error" variant="tonal" density="compact" class="mt-4"
                >
                  <p v-for="(err, field) in form.errors" :key="field" class="mb-1 text-caption">{{ err }}</p>
                </VAlert>
              </VForm>
            </VCardText>
          </VCard>
        </VCol>

        <VCol v-else-if="alreadyCompleted" cols="12" md="4">
          <VCard elevation="0" border rounded="lg">
            <VCardTitle class="pa-4 pb-2 text-subtitle-1 font-weight-semibold">Attestation Record</VCardTitle>
            <VCardText class="pa-4 pt-0">
              <VList lines="one" density="compact">
                <VListItem>
                  <template #prepend><VIcon icon="tabler-calendar-check" color="success" class="me-2" /></template>
                  <VListItemTitle>Acknowledged at</VListItemTitle>
                  <VListItemSubtitle>{{ attestation.acknowledged_at ?? '—' }}</VListItemSubtitle>
                </VListItem>
                <VListItem>
                  <template #prepend><VIcon icon="tabler-signature" color="success" class="me-2" /></template>
                  <VListItemTitle>Digitally signed at</VListItemTitle>
                  <VListItemSubtitle>{{ attestation.signed_at ?? '—' }}</VListItemSubtitle>
                </VListItem>
                <VListItem v-if="attestation.quiz_score !== null">
                  <template #prepend><VIcon icon="tabler-chart-bar" color="primary" class="me-2" /></template>
                  <VListItemTitle>Quiz score</VListItemTitle>
                  <VListItemSubtitle>{{ attestation.quiz_score }}%</VListItemSubtitle>
                </VListItem>
              </VList>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

    </VContainer>
  </section>
</template>

<style scoped>
.sticky-card {
  position: sticky;
  top: 80px;
}

mark {
  background: rgb(var(--v-theme-warning));
  color: rgb(var(--v-theme-on-warning));
  border-radius: 2px;
  padding: 0 2px;
}
</style>
