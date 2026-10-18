<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

const props = defineProps<{
  job: { id: number; title: string }
  shortlist: {
    id: number
    status: string
    status_label: string
    notes: string | null
    approved_at: string | null
    generated_by: { id: number; name: string } | null
    approved_by: { id: number; name: string } | null
    items: Array<{
      id: number
      rank: number
      ai_recommended: boolean
      candidate: { id: number; uuid: string; full_name: string; email: string }
      fit_score: number | null
    }>
    overrides: Array<{
      id: number
      action: string
      override_reason: string
      approved_at: string | null
      overridden_by: { id: number; name: string }
    }>
    created_at: string
  }
}>()

// ─── Status helpers ───────────────────────────────────────────────────────────
function statusColor(s: string) {
  const map: Record<string, string> = {
    draft:            'secondary',
    pending_approval: 'warning',
    approved:         'success',
    rejected:         'error',
  }
  return map[s] ?? 'default'
}

// ─── Submit for approval ──────────────────────────────────────────────────────
const submitForm = useForm({})

function submitForApproval() {
  submitForm.post(
    route('recruitment.jobs.shortlists.submit', [props.job.id, props.shortlist.id])
  )
}

// ─── Approve ──────────────────────────────────────────────────────────────────
const approveForm = useForm({})

function approve() {
  approveForm.post(
    route('recruitment.jobs.shortlists.approve', [props.job.id, props.shortlist.id])
  )
}

// ─── Reject dialog ────────────────────────────────────────────────────────────
const showRejectDialog = ref(false)
const rejectForm = useForm({ reason: '' })

function submitReject() {
  rejectForm.post(
    route('recruitment.jobs.shortlists.reject', [props.job.id, props.shortlist.id]),
    { onSuccess: () => { showRejectDialog.value = false } }
  )
}

// ─── Override request dialog ──────────────────────────────────────────────────
const showOverrideDialog = ref(false)
const overrideForm = useForm({
  candidate_id:    null as number | null,
  action:          'added' as 'added' | 'removed',
  override_reason: '',
})

const overrideActionOptions = [
  { title: 'Add candidate', value: 'added' },
  { title: 'Remove candidate', value: 'removed' },
]

function candidateOptions() {
  return props.shortlist.items.map(i => ({
    title: i.candidate.full_name,
    value: i.candidate.id,
  }))
}

function submitOverride() {
  overrideForm.post(
    route('recruitment.jobs.shortlists.overrides.store', [props.job.id, props.shortlist.id]),
    {
      onSuccess: () => {
        showOverrideDialog.value = false
        overrideForm.reset()
      },
    }
  )
}

// ─── Table headers ────────────────────────────────────────────────────────────
const headers = [
  { title: 'Rank',          key: 'rank',          sortable: false, width: '90px'  },
  { title: 'Candidate',     key: 'candidate',     sortable: false                 },
  { title: 'Fit Score',     key: 'fit_score',     sortable: true,  width: '180px' },
  { title: 'AI Rec.',       key: 'ai_recommended', sortable: false, width: '80px' },
]

// ─── Date helper ─────────────────────────────────────────────────────────────
function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', {
    day:   '2-digit',
    month: 'short',
    year:  'numeric',
  })
}

// ─── Override action label ────────────────────────────────────────────────────
function overrideActionLabel(action: string) {
  if (action === 'add') return 'Added'
  if (action === 'remove') return 'Removed'
  return action
}
</script>

<template>
  <div class="pa-6">
    <!-- Breadcrumb -->
    <div class="d-flex align-center gap-2 mb-4">
      <VBtn
        icon="tabler-arrow-left"
        variant="text"
        size="small"
        @click="router.visit(route('recruitment.jobs.show', job.id))"
      />
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
      <span class="text-body-2 font-weight-medium">Shortlist</span>
    </div>

    <!-- Header -->
    <div class="d-flex align-start justify-space-between mb-6">
      <div>
        <div class="d-flex align-center gap-3 mb-1">
          <h4 class="text-h4 font-weight-bold">Shortlist</h4>
          <VChip
            :color="statusColor(shortlist.status)"
            variant="tonal"
            label
            class="font-weight-medium text-capitalize"
          >
            {{ shortlist.status_label }}
          </VChip>
        </div>
        <p class="text-medium-emphasis mb-0">{{ job.title }}</p>
      </div>

      <!-- Action buttons -->
      <div class="d-flex align-center gap-2">
        <!-- Submit for approval (draft) -->
        <VBtn
          v-if="shortlist.status === 'draft'"
          color="primary"
          variant="tonal"
          prepend-icon="tabler-send"
          :loading="submitForm.processing"
          @click="submitForApproval"
        >
          Submit for Approval
        </VBtn>

        <!-- Approve / Reject (pending_approval) -->
        <template v-if="shortlist.status === 'pending_approval'">
          <VBtn
            color="error"
            variant="tonal"
            prepend-icon="tabler-circle-x"
            @click="showRejectDialog = true"
          >
            Reject
          </VBtn>
          <VBtn
            color="success"
            variant="tonal"
            prepend-icon="tabler-circle-check"
            :loading="approveForm.processing"
            @click="approve"
          >
            Approve
          </VBtn>
        </template>

        <!-- Request override (pending_approval only) -->
        <VBtn
          v-if="shortlist.status === 'pending_approval'"
          color="warning"
          variant="tonal"
          prepend-icon="tabler-switch-horizontal"
          @click="showOverrideDialog = true"
        >
          Request Override
        </VBtn>
      </div>
    </div>

    <!-- Info row -->
    <VCard elevation="0" border class="mb-4">
      <VCardText class="pa-4">
        <div class="d-flex flex-wrap gap-6">
          <div v-if="shortlist.generated_by" class="d-flex align-center gap-2">
            <VIcon icon="tabler-user" size="16" class="text-medium-emphasis" />
            <div>
              <p class="text-caption text-medium-emphasis mb-0">Generated by</p>
              <p class="text-body-2 font-weight-medium mb-0">
                {{ shortlist.generated_by.name }}
                <span class="text-medium-emphasis font-weight-regular">
                  on {{ formatDate(shortlist.created_at) }}
                </span>
              </p>
            </div>
          </div>

          <div v-if="shortlist.approved_by" class="d-flex align-center gap-2">
            <VIcon icon="tabler-rosette-discount-check" size="16" color="success" />
            <div>
              <p class="text-caption text-medium-emphasis mb-0">Approved by</p>
              <p class="text-body-2 font-weight-medium mb-0">
                {{ shortlist.approved_by.name }}
                <span class="text-medium-emphasis font-weight-regular">
                  on {{ formatDate(shortlist.approved_at) }}
                </span>
              </p>
            </div>
          </div>

          <div v-if="shortlist.notes" class="d-flex align-center gap-2">
            <VIcon icon="tabler-note" size="16" class="text-medium-emphasis" />
            <div>
              <p class="text-caption text-medium-emphasis mb-0">Notes</p>
              <p class="text-body-2 mb-0">{{ shortlist.notes }}</p>
            </div>
          </div>
        </div>
      </VCardText>
    </VCard>

    <!-- Shortlist items table -->
    <VCard elevation="0" border class="mb-4">
      <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">
        Candidates
        <VChip
          v-if="shortlist.items.length"
          size="x-small"
          color="primary"
          variant="tonal"
          label
          class="ms-2"
        >
          {{ shortlist.items.length }}
        </VChip>
      </VCardTitle>

      <VDataTable
        :headers="headers"
        :items="shortlist.items"
        hide-default-footer
        hover
        @click:row="(_: unknown, row: any) => router.visit(route('recruitment.candidates.show', row.item.candidate.id))"
      >
        <!-- Rank -->
        <template #item.rank="{ item }">
          <VChip size="small" color="deep-purple" variant="tonal" label class="font-weight-bold">
            #{{ item.rank }}
          </VChip>
        </template>

        <!-- Candidate -->
        <template #item.candidate="{ item }">
          <div class="d-flex align-center gap-3 py-2">
            <VAvatar size="36" color="primary" variant="tonal" class="flex-shrink-0">
              <span class="text-body-2 font-weight-bold">
                {{ (item.candidate.full_name ?? '?')[0].toUpperCase() }}
              </span>
            </VAvatar>
            <div>
              <p class="text-body-2 font-weight-medium mb-0">{{ item.candidate.full_name }}</p>
              <p class="text-caption text-medium-emphasis mb-0">{{ item.candidate.email }}</p>
            </div>
          </div>
        </template>

        <!-- Fit Score -->
        <template #item.fit_score="{ item }">
          <div v-if="item.fit_score !== null" class="d-flex align-center gap-2" style="min-width: 120px;">
            <VProgressLinear
              :model-value="item.fit_score"
              color="primary"
              height="6"
              rounded
              class="flex-grow-1"
            />
            <span class="text-body-2 font-weight-medium flex-shrink-0" style="min-width: 36px; text-align: right;">
              {{ item.fit_score }}%
            </span>
          </div>
          <span v-else class="text-medium-emphasis">—</span>
        </template>

        <!-- AI Recommended -->
        <template #item.ai_recommended="{ item }">
          <VIcon
            v-if="item.ai_recommended"
            icon="tabler-robot"
            color="info"
            size="20"
            title="AI Recommended"
          />
          <span v-else class="text-medium-emphasis">—</span>
        </template>

        <template #no-data>
          <div class="text-center py-10">
            <VIcon icon="tabler-user-off" size="40" class="mb-2 d-block mx-auto text-medium-emphasis" />
            <p class="text-body-2 text-medium-emphasis mb-0">No candidates in this shortlist.</p>
          </div>
        </template>
      </VDataTable>
    </VCard>

    <!-- Overrides section -->
    <VCard
      v-if="shortlist.overrides && shortlist.overrides.length > 0"
      elevation="0"
      border
    >
      <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">
        Override Requests
        <VChip
          size="x-small"
          color="warning"
          variant="tonal"
          label
          class="ms-2"
        >
          {{ shortlist.overrides.length }}
        </VChip>
      </VCardTitle>
      <VCardText class="pa-5 pt-0">
        <div class="d-flex flex-column gap-3">
          <div
            v-for="override in shortlist.overrides"
            :key="override.id"
            class="d-flex align-start gap-3 pa-3 rounded-lg"
            style="border: 1px solid rgba(0,0,0,0.08);"
          >
            <VChip
              :color="override.action === 'add' ? 'success' : 'error'"
              size="small"
              variant="tonal"
              label
              class="font-weight-medium flex-shrink-0"
            >
              {{ overrideActionLabel(override.action) }}
            </VChip>
            <div class="flex-grow-1">
              <p class="text-body-2 mb-1">{{ override.override_reason }}</p>
              <div class="d-flex align-center gap-2 flex-wrap">
                <span class="text-caption text-medium-emphasis">
                  By {{ override.overridden_by.name }}
                </span>
                <span
                  v-if="override.approved_at"
                  class="text-caption text-medium-emphasis"
                >
                  · Approved {{ formatDate(override.approved_at) }}
                </span>
              </div>
            </div>
          </div>
        </div>
      </VCardText>
    </VCard>

    <!-- Reject Dialog -->
    <VDialog v-model="showRejectDialog" max-width="480">
      <VCard>
        <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">
          Reject Shortlist
        </VCardTitle>
        <VCardText class="pa-5 pt-0">
          <p class="text-body-2 text-medium-emphasis mb-4">
            Please provide a reason for rejecting this shortlist.
            The recruiter will be notified and can revise and resubmit.
          </p>
          <VTextarea
            v-model="rejectForm.reason"
            label="Reason for Rejection"
            rows="4"
            variant="outlined"
            placeholder="e.g. Shortlist does not include candidates with required qualifications..."
            :error-messages="rejectForm.errors.reason"
          />
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2">
          <VSpacer />
          <VBtn
            variant="text"
            @click="showRejectDialog = false"
          >
            Cancel
          </VBtn>
          <VBtn
            color="error"
            :loading="rejectForm.processing"
            @click="submitReject"
          >
            Confirm Reject
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Override Request Dialog -->
    <VDialog v-model="showOverrideDialog" max-width="520">
      <VCard>
        <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">
          Request Shortlist Override
        </VCardTitle>
        <VCardText class="pa-5 pt-0">
          <p class="text-body-2 text-medium-emphasis mb-5">
            Override requests require a second approver. Provide the action, candidate, and reason below.
          </p>

          <VSelect
            v-model="overrideForm.action"
            label="Action"
            :items="overrideActionOptions"
            item-title="title"
            item-value="value"
            variant="outlined"
            class="mb-4"
          />

          <VSelect
            v-model="overrideForm.candidate_id"
            label="Candidate"
            :items="candidateOptions()"
            item-title="title"
            item-value="value"
            variant="outlined"
            class="mb-4"
            :hint="overrideForm.action === 'removed'
              ? 'Select the shortlisted candidate to remove.'
              : 'Select the candidate to add (must be an applicant).'"
            persistent-hint
            :error-messages="overrideForm.errors.candidate_id"
          />

          <VTextarea
            v-model="overrideForm.override_reason"
            label="Reason"
            rows="3"
            variant="outlined"
            placeholder="Explain why this override is required..."
            :error-messages="overrideForm.errors.override_reason"
          />
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2">
          <VSpacer />
          <VBtn
            variant="text"
            @click="showOverrideDialog = false; overrideForm.reset()"
          >
            Cancel
          </VBtn>
          <VBtn
            color="warning"
            :loading="overrideForm.processing"
            :disabled="!overrideForm.candidate_id || !overrideForm.override_reason"
            @click="submitOverride"
          >
            Submit Override Request
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
