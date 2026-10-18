<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type ViolationNote = {
  id: number
  content: string
  type: string
  author_id: number
  author: { id: number; email: string; person: { first_name: string; surname: string } | null } | null
  attachment_path: string | null
  created_at: string
}

type Violation = {
  id: number
  severity: string
  status: string
  outcome: string | null
  explanation: string | null
  event_snapshot: Record<string, any> | null
  resolved_at: string | null
  created_at: string
  updated_at: string
  policy: { id: number; name: string } | null
  rule: { id: number; name: string; severity: string } | null
  clause: { id: number; title: string } | null
  user: { id: number; email: string; person: { first_name: string; surname: string } | null } | null
  investigator: { id: number; email: string; person: { first_name: string; surname: string } | null } | null
  notes: ViolationNote[]
}

const props = defineProps<{ violation: Violation }>()

const updateForm = useForm({
  status: props.violation.status,
  outcome: props.violation.outcome ?? '',
  investigator_id: props.violation.investigator?.id ?? null as number | null,
})

function submitUpdate() {
  updateForm.patch(route('policy-violations.update', props.violation.id))
}

const showNoteForm = ref(false)
const noteForm = useForm({
  content: '',
  type: 'observation',
  attachment: null as File | null,
})

function submitNote() {
  noteForm.post(route('policy-violations.notes.store', props.violation.id), {
    forceFormData: true,
    onSuccess: () => {
      noteForm.reset()
      showNoteForm.value = false
    },
  })
}

function fullName(user: Violation['user'] | ViolationNote['author']) {
  if (!user) return '—'
  const p = user.person
  if (p) return `${p.first_name} ${p.surname}`.trim()
  return user.email
}

function severityColor(s: string): string {
  return ({ critical: 'error', high: 'warning', medium: 'info', low: 'success' } as Record<string, string>)[s] ?? 'default'
}

function statusColor(s: string): string {
  return ({ open: 'warning', under_investigation: 'info', resolved: 'success', dismissed: 'default' } as Record<string, string>)[s] ?? 'default'
}

function noteTypeColor(t: string): string {
  return ({ observation: 'secondary', action: 'primary', evidence: 'info', escalation: 'error', resolution: 'success' } as Record<string, string>)[t] ?? 'default'
}

function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}

const hasSnapshot = (snap: Record<string, any> | null): boolean => {
  return snap !== null && snap !== undefined && Object.keys(snap).length > 0
}
</script>

<template>
  <div class="pa-6">
    <!-- Breadcrumb row -->
    <div class="d-flex align-center mb-4 ga-2">
      <VBtn
        icon="tabler-arrow-left"
        variant="text"
        size="small"
        @click="router.visit(route('policy-violations.index'))"
      />
      <span
        class="text-body-2 text-primary cursor-pointer"
        @click="router.visit(route('policy-violations.index'))"
      >Policy Violations</span>
      <VIcon size="small" color="medium-emphasis">tabler-chevron-right</VIcon>
      <VChip size="small" color="surface-variant" label>
        #{{ violation.id }}
      </VChip>
    </div>

    <!-- Header -->
    <div class="d-flex align-center flex-wrap mb-6 ga-3">
      <span class="text-h4 font-weight-bold">Violation #{{ violation.id }}</span>
      <VChip :color="severityColor(violation.severity)" size="small" class="text-capitalize">
        {{ violation.severity }}
      </VChip>
      <VChip :color="statusColor(violation.status)" size="small" class="text-capitalize">
        {{ violation.status.replace('_', ' ') }}
      </VChip>
    </div>

    <!-- Two-column layout -->
    <VRow>
      <!-- Left column -->
      <VCol cols="12" md="8">
        <!-- Details card -->
        <VCard elevation="0" border rounded="lg" class="mb-4">
          <VCardTitle class="pa-4 pb-2">Violation Details</VCardTitle>
          <VCardText>
            <VRow>
              <VCol cols="12" sm="6">
                <div class="text-caption text-medium-emphasis mb-1">Policy</div>
                <VChip
                  v-if="violation.policy"
                  size="small"
                  color="primary"
                  variant="tonal"
                >
                  {{ violation.policy.name }}
                </VChip>
                <span v-else class="text-body-2">—</span>
              </VCol>

              <VCol cols="12" sm="6">
                <div class="text-caption text-medium-emphasis mb-1">Rule</div>
                <span class="text-body-2">{{ violation.rule?.name ?? '—' }}</span>
              </VCol>

              <VCol v-if="violation.clause" cols="12" sm="6">
                <div class="text-caption text-medium-emphasis mb-1">Clause</div>
                <span class="text-body-2">{{ violation.clause.title }}</span>
              </VCol>

              <VCol cols="12" sm="6">
                <div class="text-caption text-medium-emphasis mb-1">Employee</div>
                <span class="text-body-2">{{ fullName(violation.user) }}</span>
              </VCol>

              <VCol cols="12" sm="6">
                <div class="text-caption text-medium-emphasis mb-1">Severity</div>
                <VChip :color="severityColor(violation.severity)" size="small" class="text-capitalize">
                  {{ violation.severity }}
                </VChip>
              </VCol>

              <VCol cols="12" sm="6">
                <div class="text-caption text-medium-emphasis mb-1">Status</div>
                <VChip :color="statusColor(violation.status)" size="small" class="text-capitalize">
                  {{ violation.status.replace('_', ' ') }}
                </VChip>
              </VCol>

              <VCol v-if="violation.outcome" cols="12">
                <div class="text-caption text-medium-emphasis mb-1">Outcome</div>
                <span class="text-body-2">{{ violation.outcome }}</span>
              </VCol>

              <VCol v-if="violation.resolved_at" cols="12" sm="6">
                <div class="text-caption text-medium-emphasis mb-1">Resolved At</div>
                <span class="text-body-2">{{ formatDate(violation.resolved_at) }}</span>
              </VCol>

              <VCol cols="12" sm="6">
                <div class="text-caption text-medium-emphasis mb-1">Reported On</div>
                <span class="text-body-2">{{ formatDate(violation.created_at) }}</span>
              </VCol>
            </VRow>
          </VCardText>
        </VCard>

        <!-- AI Explanation card -->
        <VCard
          v-if="violation.explanation"
          elevation="0"
          border
          rounded="lg"
          class="mb-4"
        >
          <VCardTitle class="pa-4 pb-2 d-flex align-center ga-2">
            <VIcon color="primary">tabler-robot</VIcon>
            AI Analysis
          </VCardTitle>
          <VCardText>
            <p class="text-body-2 font-italic">{{ violation.explanation }}</p>
          </VCardText>
        </VCard>

        <!-- Event Snapshot card -->
        <VCard
          v-if="hasSnapshot(violation.event_snapshot)"
          elevation="0"
          border
          rounded="lg"
          class="mb-4"
        >
          <VCardTitle class="pa-4 pb-2">Event Data</VCardTitle>
          <VCardText>
            <pre class="text-caption overflow-x-auto">{{ JSON.stringify(violation.event_snapshot, null, 2) }}</pre>
          </VCardText>
        </VCard>

        <!-- Notes card -->
        <VCard elevation="0" border rounded="lg">
          <VCardTitle class="pa-4 pb-2 d-flex align-center ga-2">
            Investigation Notes
            <VBadge
              :content="violation.notes.length"
              color="primary"
              inline
            />
          </VCardTitle>
          <VCardText class="pa-0">
            <template v-if="violation.notes.length > 0">
              <div
                v-for="(note, index) in violation.notes"
                :key="note.id"
              >
                <VDivider v-if="index > 0" />
                <div class="pa-4">
                  <div class="d-flex align-center flex-wrap ga-2 mb-2">
                    <span class="text-body-2 font-weight-medium">{{ fullName(note.author) }}</span>
                    <VChip
                      :color="noteTypeColor(note.type)"
                      size="x-small"
                      class="text-capitalize"
                    >
                      {{ note.type }}
                    </VChip>
                    <span class="text-caption text-medium-emphasis ml-auto">{{ formatDate(note.created_at) }}</span>
                  </div>
                  <p class="text-body-2 mb-2">{{ note.content }}</p>
                  <div v-if="note.attachment_path" class="d-flex align-center ga-1 text-caption text-medium-emphasis">
                    <VIcon size="small">tabler-paperclip</VIcon>
                    <span>Attachment</span>
                  </div>
                </div>
              </div>
            </template>
            <div v-else class="pa-4 text-body-2 text-medium-emphasis">
              No notes yet.
            </div>

            <VDivider />

            <!-- Add Note toggle -->
            <div class="pa-4">
              <VBtn
                :prepend-icon="showNoteForm ? 'tabler-minus' : 'tabler-plus'"
                variant="tonal"
                color="primary"
                size="small"
                class="mb-3"
                @click="showNoteForm = !showNoteForm"
              >
                {{ showNoteForm ? 'Cancel' : 'Add Note' }}
              </VBtn>

              <VExpandTransition>
                <div v-if="showNoteForm">
                  <VTextarea
                    v-model="noteForm.content"
                    label="Note"
                    rows="3"
                    variant="outlined"
                    density="compact"
                    class="mb-3"
                    required
                    :error-messages="noteForm.errors.content"
                  />
                  <VSelect
                    v-model="noteForm.type"
                    :items="['observation', 'action', 'evidence', 'escalation', 'resolution']"
                    label="Type"
                    variant="outlined"
                    density="compact"
                    class="mb-3"
                    :error-messages="noteForm.errors.type"
                  />
                  <VFileInput
                    v-model="noteForm.attachment"
                    label="Attachment (optional)"
                    accept="image/*,.pdf,.doc,.docx"
                    variant="outlined"
                    density="compact"
                    class="mb-3"
                    prepend-icon="tabler-paperclip"
                  />
                  <VBtn
                    color="primary"
                    :loading="noteForm.processing"
                    @click="submitNote"
                  >
                    Add Note
                  </VBtn>
                </div>
              </VExpandTransition>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Right column -->
      <VCol cols="12" md="4">
        <VCard elevation="0" border rounded="lg">
          <VCardTitle class="pa-4 pb-2">Actions</VCardTitle>
          <VCardText>
            <!-- Update Status section -->
            <p class="text-caption text-medium-emphasis mb-2">Update Status</p>
            <VSelect
              v-model="updateForm.status"
              :items="['open', 'under_investigation', 'resolved', 'dismissed']"
              label="Status"
              variant="outlined"
              density="compact"
              class="mb-3"
            />
            <VExpandTransition>
              <VTextarea
                v-if="updateForm.status === 'resolved' || updateForm.status === 'dismissed'"
                v-model="updateForm.outcome"
                label="Outcome / Resolution Notes"
                variant="outlined"
                density="compact"
                rows="3"
                class="mb-3"
              />
            </VExpandTransition>
            <VBtn
              block
              color="primary"
              :loading="updateForm.processing"
              class="mb-4"
              @click="submitUpdate"
            >
              Update Status
            </VBtn>

            <VDivider class="mb-4" />

            <!-- Investigator section -->
            <p class="text-caption text-medium-emphasis mb-2">Assigned Investigator</p>
            <div
              v-if="violation.investigator"
              class="d-flex align-center ga-2 mb-3 text-body-2"
            >
              <VIcon color="primary">tabler-user-circle</VIcon>
              <span>{{ fullName(violation.investigator) }}</span>
            </div>
            <p v-else class="text-body-2 text-medium-emphasis mb-3">No investigator assigned.</p>

            <VTextField
              v-model.number="updateForm.investigator_id"
              label="Assign Investigator (User ID)"
              variant="outlined"
              density="compact"
              type="number"
              class="mb-3"
            />
            <VBtn
              block
              variant="tonal"
              color="secondary"
              :loading="updateForm.processing"
              @click="submitUpdate"
            >
              Assign
            </VBtn>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
