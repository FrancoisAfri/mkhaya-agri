<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type ApprovalRequest = {
  id: number
  request_number: string
  employee_name: string
  leave_type: string
  start_date: string
  end_date: string
  working_days: number
  submitted_at: string | null
  notes: string | null
  bcea_validation: any
}

type Delegation = {
  id: number
  delegate_name: string
  start_date: string
  end_date: string
  status: string
}

const props = defineProps<{
  pending: ApprovalRequest[]
  recentActioned: ApprovalRequest[]
  summary: { pending_count: number; approved_today: number; rejected_today: number }
  myDelegation: Delegation | null
}>()

const actionDialog = ref(false)
const selectedRequest = ref<ApprovalRequest | null>(null)
const actionType = ref<'approve' | 'reject'>('approve')

const actionForm = useForm({ comment: '' })

function openAction(req: ApprovalRequest, type: 'approve' | 'reject') {
  selectedRequest.value = req
  actionType.value = type
  actionForm.reset()
  actionDialog.value = true
}

function submitAction() {
  if (!selectedRequest.value) return
  const routeName = actionType.value === 'approve' ? 'hr.leave.approve' : 'hr.leave.reject'
  actionForm.post(route(routeName, { leaveRequest: selectedRequest.value.id }), {
    onSuccess: () => { actionDialog.value = false },
  })
}

function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">

    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('hr.leave.index'))" />
        <div>
          <h4 class="text-h4">Approvals Queue</h4>
          <p class="text-medium-emphasis mb-0">Review and action leave requests awaiting your approval</p>
        </div>
      </div>
      <VBtn variant="tonal" color="default" prepend-icon="tabler-calendar-share" @click="router.visit(route('hr.leave.delegations'))">
        Delegations
      </VBtn>
    </div>

    <!-- Summary Cards -->
    <VRow class="mb-6">
      <VCol cols="12" sm="4">
        <VCard elevation="0" border>
          <VCardText class="d-flex align-center gap-3 pa-4">
            <VAvatar color="warning" variant="tonal" size="46" rounded>
              <VIcon icon="tabler-clock" size="22" />
            </VAvatar>
            <div>
              <p class="text-body-2 text-medium-emphasis mb-0">Pending</p>
              <h5 class="text-h5">{{ summary.pending_count }}</h5>
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="4">
        <VCard elevation="0" border>
          <VCardText class="d-flex align-center gap-3 pa-4">
            <VAvatar color="success" variant="tonal" size="46" rounded>
              <VIcon icon="tabler-circle-check" size="22" />
            </VAvatar>
            <div>
              <p class="text-body-2 text-medium-emphasis mb-0">Approved Today</p>
              <h5 class="text-h5">{{ summary.approved_today }}</h5>
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="4">
        <VCard elevation="0" border>
          <VCardText class="d-flex align-center gap-3 pa-4">
            <VAvatar color="error" variant="tonal" size="46" rounded>
              <VIcon icon="tabler-circle-x" size="22" />
            </VAvatar>
            <div>
              <p class="text-body-2 text-medium-emphasis mb-0">Rejected Today</p>
              <h5 class="text-h5">{{ summary.rejected_today }}</h5>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Active Delegation Banner -->
    <VAlert v-if="myDelegation" type="info" variant="tonal" class="mb-5">
      <template #title>Active Delegation</template>
      Your approvals are currently delegated to <strong>{{ myDelegation.delegate_name }}</strong>
      until {{ myDelegation.end_date }}.
    </VAlert>

    <!-- Pending Requests -->
    <VCard elevation="0" border class="mb-5">
      <VCardTitle class="pa-4 pb-2 d-flex align-center justify-space-between text-body-1 font-weight-semibold">
        Pending Requests
        <VChip v-if="pending.length" color="warning" size="small" variant="tonal">{{ pending.length }}</VChip>
      </VCardTitle>
      <VDivider />

      <div v-if="pending.length" class="d-flex flex-column">
        <div
          v-for="req in pending"
          :key="req.id"
          class="pa-4 d-flex align-start gap-4"
          style="border-bottom: 1px solid rgba(0,0,0,.07);"
        >
          <VAvatar color="warning" variant="tonal" size="42" rounded>
            <VIcon icon="tabler-user" size="20" />
          </VAvatar>
          <div class="flex-1-1">
            <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-1">
              <div>
                <span class="text-body-2 font-weight-semibold">{{ req.employee_name }}</span>
                <span class="text-caption text-medium-emphasis ms-2">{{ req.request_number }}</span>
              </div>
              <div class="d-flex gap-2">
                <VBtn color="success" size="small" variant="tonal" prepend-icon="tabler-check" @click="openAction(req, 'approve')">Approve</VBtn>
                <VBtn color="error" size="small" variant="tonal" prepend-icon="tabler-x" @click="openAction(req, 'reject')">Reject</VBtn>
                <VBtn icon="tabler-eye" size="small" variant="text" color="primary" @click="router.visit(route('hr.leave.show', { leaveRequest: req.id }))" />
              </div>
            </div>
            <p class="text-body-2 mb-1">
              <strong>{{ req.leave_type }}</strong> · {{ req.start_date }} → {{ req.end_date }} · {{ req.working_days }} day(s)
            </p>
            <p v-if="req.notes" class="text-caption text-medium-emphasis mb-0">{{ req.notes }}</p>
            <VAlert v-if="req.bcea_validation?.warnings?.length" type="warning" variant="tonal" density="compact" rounded class="mt-2">
              BCEA: {{ req.bcea_validation.warnings[0] }}
            </VAlert>
          </div>
        </div>
      </div>

      <VCardText v-else class="text-center py-10">
        <VIcon icon="tabler-circle-check" size="56" color="success" class="mb-3" />
        <p class="text-body-1 text-medium-emphasis">All caught up! No pending approvals.</p>
      </VCardText>
    </VCard>

    <!-- Recently Actioned -->
    <VCard v-if="recentActioned.length" elevation="0" border>
      <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Recently Actioned</VCardTitle>
      <VDivider />
      <VList density="compact">
        <VListItem
          v-for="req in recentActioned"
          :key="req.id"
          :subtitle="`${req.start_date} → ${req.end_date} · ${req.working_days}d`"
          class="cursor-pointer"
          @click="router.visit(route('hr.leave.show', { leaveRequest: req.id }))"
        >
          <template #title>
            <span class="text-body-2 font-weight-medium">{{ req.employee_name }}</span>
            <span class="text-caption text-medium-emphasis ms-2">{{ req.leave_type }}</span>
          </template>
          <template #append>
            <span class="text-caption text-medium-emphasis">{{ formatDate(req.submitted_at) }}</span>
          </template>
        </VListItem>
      </VList>
    </VCard>

    <!-- Action Dialog -->
    <VDialog v-model="actionDialog" max-width="480" persistent>
      <VCard v-if="selectedRequest">
        <VCardTitle class="pa-4 pb-2 text-h6">
          {{ actionType === 'approve' ? 'Approve' : 'Reject' }} Leave Request
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAlert :type="actionType === 'approve' ? 'success' : 'error'" variant="tonal" density="compact" class="mb-4">
            <strong>{{ selectedRequest.employee_name }}</strong> — {{ selectedRequest.leave_type }} ({{ selectedRequest.working_days }}d)
          </VAlert>
          <VTextarea
            v-model="actionForm.comment"
            :label="actionType === 'reject' ? 'Rejection Reason *' : 'Comment (optional)'"
            variant="outlined"
            density="comfortable"
            rows="3"
            :error-messages="actionForm.errors.comment"
          />
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="actionDialog = false">Cancel</VBtn>
          <VBtn
            :color="actionType === 'approve' ? 'success' : 'error'"
            variant="flat"
            :loading="actionForm.processing"
            :disabled="actionType === 'reject' && !actionForm.comment"
            @click="submitAction"
          >
            Confirm {{ actionType === 'approve' ? 'Approval' : 'Rejection' }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

  </VContainer>
</template>
