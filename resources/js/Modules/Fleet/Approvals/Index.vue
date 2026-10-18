<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

// ── Types ──────────────────────────────────────────────────────────────────────
type PendingStep = {
  approval_id: number; booking_id: number; reference: string
  workflow_state: string; purpose: string | null; destination: string | null
  departure_at: string; expected_return_at: string; is_local_trip: boolean
  step_number: number; step_label: string; approver_type: string
  vehicle: string; requester_name: string; submitted_at: string | null
}
type ActionedRow = {
  approval_id: number; reference: string; step_number: number; step_label: string
  status: string; comments: string | null; action_at: string; requester_name: string
}
type Delegation = {
  id: number; delegate_name: string; start_date: string; end_date: string
  reason: string | null; is_active: boolean
}
type Employee = { user_id: number; name: string }

const props = defineProps<{
  pendingSteps:   PendingStep[]
  recentActioned: ActionedRow[]
  myDelegation:   Delegation | null
  delegations:    Delegation[]
  employees:      Employee[]
  summary:        { total_pending: number }
}>()

const tab = ref('pending')
const confirmDialog = ref()

// ── Approve dialog ─────────────────────────────────────────────────────────────
const approveDialog = ref(false)
const activeStep    = ref<PendingStep | null>(null)
const approveForm   = useForm({ comments: '' })

function openApprove(step: PendingStep) { activeStep.value = step; approveDialog.value = true }
function submitApprove() {
  if (!activeStep.value) return
  approveForm.patch(route('fleet.approvals.steps.approve', activeStep.value.approval_id), {
    onSuccess: () => { approveDialog.value = false; approveForm.reset() },
  })
}

// ── Reject dialog ──────────────────────────────────────────────────────────────
const rejectDialog = ref(false)
const rejectForm   = useForm({ comments: '' })

function openReject(step: PendingStep) { activeStep.value = step; rejectDialog.value = true }
function submitReject() {
  if (!activeStep.value) return
  rejectForm.patch(route('fleet.approvals.steps.reject', activeStep.value.approval_id), {
    onSuccess: () => { rejectDialog.value = false; rejectForm.reset() },
  })
}

// ── Delegation dialog ──────────────────────────────────────────────────────────
const delegateDialog = ref(false)
const delegateForm   = useForm({
  delegate_id: null as number | null,
  start_date:  '',
  end_date:    '',
  reason:      '',
})

function openDelegate() { delegateDialog.value = true }
function submitDelegate() {
  delegateForm.post(route('fleet.approvals.delegations.store'), {
    onSuccess: () => { delegateDialog.value = false; delegateForm.reset() },
  })
}

function revokeDelegation(id: number) {
  confirmDialog.value.open(
    { title: 'Revoke delegation', message: 'Revoke this delegation?', confirmText: 'Revoke', confirmColor: 'warning', icon: 'tabler-alert-triangle' },
    () => { router.delete(route('fleet.approvals.delegations.destroy', id)) },
  )
}

// ── Helpers ────────────────────────────────────────────────────────────────────
const employeeItems = computed(() =>
  props.employees.map(e => ({ title: e.name, value: e.user_id }))
)

const stateColor = (s: string) => ({
  pending: 'warning', approved: 'success', collected: 'teal',
  security_out: 'primary', security_in: 'deep-purple',
  returned: 'grey', cancelled: 'error', rejected: 'error', turned_back: 'orange',
})[s] ?? 'grey'

const statusColor = (s: string) => ({ approved: 'success', rejected: 'error' })[s] ?? 'grey'

function fmt(dt: string | null) {
  if (!dt) return '—'
  return new Date(dt).toLocaleString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}
function fmtDate(d: string) {
  return new Date(d).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}

const minToday = new Date().toISOString().slice(0, 10)
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <ConfirmDialog ref="confirmDialog" />

    <!-- Header -->
    <VCard class="mb-4">
      <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
        <div>
          <div class="text-overline text-medium-emphasis">{{ t('fleet.title') }}</div>
          <h4 class="text-h4">{{ t('fleet.approval_queue') }}</h4>
        </div>
        <div class="d-flex gap-2 flex-wrap align-center">
          <VChip color="warning" size="large" label>{{ summary.total_pending }} {{ t('fleet.pending') }}</VChip>
          <VBtn variant="tonal" prepend-icon="tabler-user-share" size="small" @click="openDelegate">
            {{ t('fleet.delegate_approvals') }}
          </VBtn>
        </div>
      </VCardText>
    </VCard>

    <!-- Active delegation banner -->
    <VAlert v-if="myDelegation" type="info" variant="tonal" density="compact" class="mb-4" icon="tabler-user-share">
      Your approval authority is currently delegated to <strong>{{ myDelegation.delegate_name }}</strong>
      ({{ fmtDate(myDelegation.start_date) }} – {{ fmtDate(myDelegation.end_date) }}).
      <VBtn size="x-small" variant="text" class="ms-2" @click="tab = 'delegations'">Manage</VBtn>
    </VAlert>

    <!-- Tabs -->
    <VCard>
      <VTabs v-model="tab" color="primary">
        <VTab value="pending">
          {{ t('fleet.pending') }}
          <VChip size="x-small" class="ms-2" :color="summary.total_pending ? 'warning' : 'success'" label>
            {{ summary.total_pending }}
          </VChip>
        </VTab>
        <VTab value="history">{{ t('fleet.approval_history') }}</VTab>
        <VTab value="delegations">
          {{ t('fleet.delegations') }}
          <VChip v-if="delegations.length" size="x-small" class="ms-2" color="info" label>{{ delegations.length }}</VChip>
        </VTab>
      </VTabs>
      <VDivider />

      <VTabsWindow v-model="tab">

        <!-- ── Pending ─────────────────────────────────────────────────────── -->
        <VTabsWindowItem value="pending">
          <VTable hover density="comfortable">
            <thead>
              <tr>
                <th>{{ t('fleet.reference') }}</th>
                <th>{{ t('fleet.requester') }}</th>
                <th>{{ t('fleet.vehicle') }}</th>
                <th>{{ t('fleet.purpose') }}</th>
                <th>{{ t('fleet.departure') }}</th>
                <th>{{ t('fleet.step') }}</th>
                <th>{{ t('fleet.actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!pendingSteps.length">
                <td colspan="7" class="text-center py-12 text-medium-emphasis">
                  <VIcon icon="tabler-circle-check" size="48" class="mb-2 d-block" color="success" />
                  {{ t('fleet.no_pending_approvals') }}
                </td>
              </tr>
              <tr v-for="step in pendingSteps" :key="step.approval_id">
                <td>
                  <div class="font-weight-medium">{{ step.reference }}</div>
                  <VChip size="x-small" :color="stateColor(step.workflow_state)" label class="mt-1">
                    {{ step.workflow_state.replace(/_/g, ' ') }}
                  </VChip>
                  <VChip v-if="step.is_local_trip" size="x-small" color="teal" variant="tonal" label class="mt-1 ms-1">Local</VChip>
                  <div v-if="step.project" class="text-caption text-medium-emphasis mt-1">
                    <VIcon size="11">tabler-briefcase</VIcon> {{ step.project }}
                  </div>
                </td>
                <td>
                  <div class="font-weight-medium">{{ step.requester_name }}</div>
                  <div v-if="step.driver_user_id && step.driver_user_id !== step.requester_id && step.driver_name" class="text-caption text-medium-emphasis">
                    <VIcon size="11">tabler-steering-wheel</VIcon> {{ step.driver_name }}
                  </div>
                  <div v-if="step.passenger_count > 0" class="text-caption text-medium-emphasis">
                    <VIcon size="11">tabler-users</VIcon> {{ step.passenger_count }} passenger{{ step.passenger_count > 1 ? 's' : '' }}
                  </div>
                </td>
                <td class="text-caption">{{ step.vehicle }}</td>
                <td>
                  <div class="text-body-2">{{ step.purpose ?? '—' }}</div>
                  <div class="text-caption text-medium-emphasis">{{ step.destination ?? '—' }}</div>
                </td>
                <td class="text-caption text-no-wrap">
                  <div>{{ fmt(step.departure_at) }}</div>
                  <div class="text-disabled">↩ {{ fmt(step.expected_return_at) }}</div>
                </td>
                <td>
                  <VChip size="x-small" color="primary" label>{{ step.step_label || ('Step ' + step.step_number) }}</VChip>
                </td>
                <td>
                  <div class="d-flex gap-1">
                    <VBtn size="x-small" color="success" variant="flat" @click="openApprove(step)">
                      <VIcon start size="14">tabler-check</VIcon>{{ t('fleet.approve') }}
                    </VBtn>
                    <VBtn size="x-small" color="error" variant="tonal" @click="openReject(step)">
                      <VIcon start size="14">tabler-x</VIcon>{{ t('fleet.reject') }}
                    </VBtn>
                  </div>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VTabsWindowItem>

        <!-- ── History ────────────────────────────────────────────────────── -->
        <VTabsWindowItem value="history">
          <VTable hover density="comfortable">
            <thead>
              <tr>
                <th>{{ t('fleet.reference') }}</th>
                <th>{{ t('fleet.requester') }}</th>
                <th>{{ t('fleet.step') }}</th>
                <th>{{ t('fleet.decision') }}</th>
                <th>{{ t('fleet.comments') }}</th>
                <th>{{ t('fleet.actioned') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!recentActioned.length">
                <td colspan="6" class="text-center py-10 text-medium-emphasis">{{ t('fleet.no_approval_history') }}</td>
              </tr>
              <tr v-for="row in recentActioned" :key="row.approval_id">
                <td class="font-weight-medium">{{ row.reference }}</td>
                <td>{{ row.requester_name }}</td>
                <td><VChip size="x-small" color="primary" label>{{ row.step_label || ('Step ' + row.step_number) }}</VChip></td>
                <td>
                  <VChip size="x-small" :color="statusColor(row.status)" label>{{ row.status }}</VChip>
                </td>
                <td class="text-caption text-medium-emphasis">{{ row.comments ?? '—' }}</td>
                <td class="text-caption text-no-wrap">{{ fmt(row.action_at) }}</td>
              </tr>
            </tbody>
          </VTable>
        </VTabsWindowItem>

        <!-- ── Delegations ─────────────────────────────────────────────────── -->
        <VTabsWindowItem value="delegations" class="pa-4">
          <div class="d-flex justify-space-between align-center mb-3">
            <div class="text-subtitle-1 font-weight-bold">{{ t('fleet.my_delegations') }}</div>
            <VBtn size="small" color="primary" variant="tonal" prepend-icon="tabler-plus" @click="openDelegate">
              {{ t('fleet.new_delegation') }}
            </VBtn>
          </div>
          <VAlert v-if="!delegations.length" type="info" variant="tonal">
            You have no delegations. Use delegations to temporarily hand over your approval authority when you are away.
          </VAlert>
          <VTable v-else hover density="comfortable">
            <thead>
              <tr>
                <th>{{ t('fleet.delegate') }}</th>
                <th>{{ t('fleet.from') }}</th>
                <th>{{ t('fleet.to') }}</th>
                <th>{{ t('fleet.reason') }}</th>
                <th>{{ t('fleet.status') }}</th>
                <th>{{ t('fleet.actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="d in delegations" :key="d.id">
                <td class="font-weight-medium">{{ d.delegate_name }}</td>
                <td>{{ fmtDate(d.start_date) }}</td>
                <td>{{ fmtDate(d.end_date) }}</td>
                <td class="text-caption text-medium-emphasis">{{ d.reason ?? '—' }}</td>
                <td>
                  <VChip size="x-small" :color="d.is_active ? 'success' : 'grey'" label>
                    {{ d.is_active ? t('fleet.active') : t('fleet.inactive') }}
                  </VChip>
                </td>
                <td>
                  <VBtn v-if="d.is_active" size="x-small" color="error" variant="tonal" @click="revokeDelegation(d.id)">
                    {{ t('fleet.revoke') }}
                  </VBtn>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VTabsWindowItem>

      </VTabsWindow>
    </VCard>

    <!-- ── Approve Dialog ─────────────────────────────────────────────────── -->
    <VDialog v-model="approveDialog" max-width="520">
      <VCard>
        <VCardTitle class="pt-5 px-6">{{ t('fleet.approve_booking_step') }}</VCardTitle>
        <VCardText class="px-6">
          <p class="text-body-2 mb-3">
            Approving <strong>{{ activeStep?.reference }}</strong> —
            <em>{{ activeStep?.step_label }}</em>
          </p>
          <div class="mb-3 text-caption text-medium-emphasis">
            <strong>Vehicle:</strong> {{ activeStep?.vehicle }}<br>
            <strong>Requester:</strong> {{ activeStep?.requester_name }}<br>
            <strong>Departure:</strong> {{ fmt(activeStep?.departure_at ?? null) }}
          </div>
          <VTextarea
            v-model="approveForm.comments"
            label="Comments (optional)"
            variant="outlined"
            density="comfortable"
            rows="2"
            auto-grow
          />
        </VCardText>
        <VCardActions class="pa-4 gap-2">
          <VSpacer />
          <VBtn variant="text" @click="approveDialog = false">{{ t('fleet.cancel') }}</VBtn>
          <VBtn color="success" variant="flat" :loading="approveForm.processing" @click="submitApprove">
            <VIcon start>tabler-check</VIcon>{{ t('fleet.approve') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Reject Dialog ──────────────────────────────────────────────────── -->
    <VDialog v-model="rejectDialog" max-width="520">
      <VCard>
        <VCardTitle class="pt-5 px-6">{{ t('fleet.reject_booking') }}</VCardTitle>
        <VCardText class="px-6">
          <p class="text-body-2 mb-3">
            Rejecting <strong>{{ activeStep?.reference }}</strong> will cancel the booking and notify the requester.
          </p>
          <VTextarea
            v-model="rejectForm.comments"
            label="Reason for rejection *"
            variant="outlined"
            density="comfortable"
            rows="3"
            :error-messages="rejectForm.errors.comments"
          />
        </VCardText>
        <VCardActions class="pa-4 gap-2">
          <VSpacer />
          <VBtn variant="text" @click="rejectDialog = false">{{ t('fleet.cancel') }}</VBtn>
          <VBtn color="error" variant="flat" :loading="rejectForm.processing" @click="submitReject">
            <VIcon start>tabler-x</VIcon>{{ t('fleet.reject_booking') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Delegation Dialog ───────────────────────────────────────────────── -->
    <VDialog v-model="delegateDialog" max-width="540">
      <VCard>
        <VCardTitle class="pt-5 px-6">{{ t('fleet.delegate_authority') }}</VCardTitle>
        <VCardText class="px-6">
          <VAlert type="info" variant="tonal" density="compact" class="mb-4">
            While your delegation is active, the selected person will receive your approval notifications
            and can action bookings on your behalf.
          </VAlert>
          <VRow dense>
            <VCol cols="12">
              <VAutocomplete
                v-model="delegateForm.delegate_id"
                label="Delegate to *"
                :items="employeeItems"
                variant="outlined"
                density="comfortable"
                :error-messages="delegateForm.errors.delegate_id"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="delegateForm.start_date"
                label="From *"
                type="date"
                :min="minToday"
                variant="outlined"
                density="comfortable"
                :error-messages="delegateForm.errors.start_date"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="delegateForm.end_date"
                label="To *"
                type="date"
                :min="delegateForm.start_date || minToday"
                variant="outlined"
                density="comfortable"
                :error-messages="delegateForm.errors.end_date"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="delegateForm.reason"
                label="Reason (optional)"
                variant="outlined"
                density="comfortable"
                rows="2"
                placeholder="e.g. On leave from 20–27 May"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-4 gap-2">
          <VSpacer />
          <VBtn variant="text" @click="delegateDialog = false">{{ t('fleet.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="delegateForm.processing" @click="submitDelegate">
            <VIcon start>tabler-user-share</VIcon>{{ t('fleet.save_delegation') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

  </VContainer>
</template>
