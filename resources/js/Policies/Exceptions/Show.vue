<script setup lang="ts">
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type ExceptionUser = {
  id: number
  email: string
  person: { first_name: string; surname: string } | null
} | null

type Exception = {
  id: number
  status: string
  reason: string
  evidence: string | null
  valid_from: string | null
  valid_until: string | null
  decided_at: string | null
  decision_notes: string | null
  created_at: string
  policy: { id: number; name: string } | null
  rule: { id: number; name: string } | null
  requestedBy: ExceptionUser
  approvedBy: ExceptionUser
}

const props = defineProps<{ exception: Exception }>()

const confirmDialog = ref()

const decideForm = useForm({
  decision: '' as 'approved' | 'rejected',
  decision_notes: '',
})

function submitDecide(decision: 'approved' | 'rejected') {
  decideForm.decision = decision
  decideForm.post(route('policy-exceptions.decide', props.exception.id))
}

function revoke() {
  confirmDialog.value.open(
    { title: 'Revoke Exception', message: 'Are you sure you want to revoke this exception?', confirmText: 'Revoke', confirmColor: 'warning', icon: 'tabler-alert-triangle' },
    () => { router.patch(route('policy-exceptions.revoke', props.exception.id)) },
  )
}

function fullName(user: ExceptionUser): string {
  if (!user) return '—'
  const p = user.person
  if (p) return `${p.first_name} ${p.surname}`.trim()
  return user.email
}

function statusColor(s: string): string {
  return ({ pending: 'warning', approved: 'success', rejected: 'error', expired: 'default', revoked: 'error' } as Record<string, string>)[s] ?? 'default'
}

function formatDate(val: string | null): string {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
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
        @click="router.visit(route('policy-exceptions.index'))"
      />
      <span
        class="text-body-2 text-primary cursor-pointer"
        @click="router.visit(route('policy-exceptions.index'))"
      >Policy Exceptions</span>
      <VIcon size="small" color="medium-emphasis">tabler-chevron-right</VIcon>
      <VChip size="small" color="surface-variant" label>
        Exception #{{ exception.id }}
      </VChip>
    </div>

    <!-- Header -->
    <div class="d-flex align-center flex-wrap mb-6 ga-3">
      <span class="text-h4 font-weight-bold">Exception Request #{{ exception.id }}</span>
      <VChip :color="statusColor(exception.status)" size="small" class="text-capitalize">
        {{ exception.status }}
      </VChip>
    </div>

    <!-- Two-column layout -->
    <VRow>
      <!-- Left column -->
      <VCol cols="12" md="8">
        <!-- Exception Details card -->
        <VCard elevation="0" border rounded="lg" class="mb-4">
          <VCardTitle class="pa-4 pb-2">Exception Details</VCardTitle>
          <VCardText>
            <VRow>
              <VCol cols="12" sm="6">
                <div class="text-caption text-medium-emphasis mb-1">Policy</div>
                <VChip
                  v-if="exception.policy"
                  size="small"
                  color="primary"
                  variant="tonal"
                >
                  {{ exception.policy.name }}
                </VChip>
                <span v-else class="text-body-2">—</span>
              </VCol>

              <VCol v-if="exception.rule" cols="12" sm="6">
                <div class="text-caption text-medium-emphasis mb-1">Rule</div>
                <span class="text-body-2">{{ exception.rule.name }}</span>
              </VCol>

              <VCol cols="12" sm="6">
                <div class="text-caption text-medium-emphasis mb-1">Requested By</div>
                <span class="text-body-2">{{ fullName(exception.requestedBy) }}</span>
              </VCol>

              <VCol cols="12" sm="6">
                <div class="text-caption text-medium-emphasis mb-1">Requested On</div>
                <span class="text-body-2">{{ formatDate(exception.created_at) }}</span>
              </VCol>

              <VCol v-if="exception.valid_from" cols="12" sm="6">
                <div class="text-caption text-medium-emphasis mb-1">Valid From</div>
                <span class="text-body-2">{{ formatDate(exception.valid_from) }}</span>
              </VCol>

              <VCol v-if="exception.valid_until" cols="12" sm="6">
                <div class="text-caption text-medium-emphasis mb-1">Valid Until</div>
                <span class="text-body-2">{{ formatDate(exception.valid_until) }}</span>
              </VCol>

              <VCol cols="12">
                <div class="text-caption text-medium-emphasis mb-1">Reason</div>
                <p class="text-body-2">{{ exception.reason }}</p>
              </VCol>

              <VCol v-if="exception.evidence" cols="12">
                <div class="text-caption text-medium-emphasis mb-1">Evidence</div>
                <p class="text-body-2 font-italic">{{ exception.evidence }}</p>
              </VCol>
            </VRow>
          </VCardText>
        </VCard>

        <!-- Decision card -->
        <VCard
          v-if="exception.decided_at"
          elevation="0"
          border
          rounded="lg"
        >
          <VCardTitle class="pa-4 pb-2">Decision</VCardTitle>
          <VCardText>
            <VRow>
              <VCol cols="12" sm="6">
                <div class="text-caption text-medium-emphasis mb-1">Decided On</div>
                <span class="text-body-2">{{ formatDate(exception.decided_at) }}</span>
              </VCol>

              <VCol cols="12" sm="6">
                <div class="text-caption text-medium-emphasis mb-1">Decided By</div>
                <span class="text-body-2">{{ fullName(exception.approvedBy) }}</span>
              </VCol>

              <VCol cols="12" sm="6">
                <div class="text-caption text-medium-emphasis mb-1">Decision</div>
                <VChip
                  :color="statusColor(exception.status)"
                  size="small"
                  class="text-capitalize"
                >
                  {{ exception.status === 'approved' ? 'Approved' : 'Rejected' }}
                </VChip>
              </VCol>

              <VCol v-if="exception.decision_notes" cols="12">
                <div class="text-caption text-medium-emphasis mb-1">Decision Notes</div>
                <p class="text-body-2">{{ exception.decision_notes }}</p>
              </VCol>
            </VRow>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Right column -->
      <VCol cols="12" md="4">
        <VCard elevation="0" border rounded="lg">
          <!-- Pending: approve or reject -->
          <template v-if="exception.status === 'pending'">
            <VCardTitle class="pa-4 pb-2">Review Exception</VCardTitle>
            <VCardText>
              <VTextarea
                v-model="decideForm.decision_notes"
                label="Decision Notes"
                variant="outlined"
                density="compact"
                rows="3"
                class="mb-4"
                :error-messages="decideForm.errors.decision_notes"
              />
              <div class="d-flex ga-3">
                <VBtn
                  color="success"
                  :loading="decideForm.processing"
                  class="flex-1-1"
                  @click="submitDecide('approved')"
                >
                  Approve
                </VBtn>
                <VBtn
                  color="error"
                  :loading="decideForm.processing"
                  class="flex-1-1"
                  @click="submitDecide('rejected')"
                >
                  Reject
                </VBtn>
              </div>
            </VCardText>
          </template>

          <!-- Approved: can revoke -->
          <template v-else-if="exception.status === 'approved'">
            <VCardTitle class="pa-4 pb-2">Actions</VCardTitle>
            <VCardText>
              <VBtn
                color="error"
                variant="tonal"
                block
                @click="revoke"
              >
                Revoke Exception
              </VBtn>
            </VCardText>
          </template>

          <!-- All other statuses -->
          <template v-else>
            <VCardTitle class="pa-4 pb-2">Actions</VCardTitle>
            <VCardText>
              <p class="text-body-2 text-medium-emphasis">No actions available.</p>
            </VCardText>
          </template>
        </VCard>
      </VCol>
    </VRow>

    <ConfirmDialog ref="confirmDialog" />
  </div>
</template>
