<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Delegation = {
  id: number
  delegate_name: string
  delegate_email: string
  start_date: string
  end_date: string
  status: string
  reason: string | null
  created_at: string
}

type IncomingDelegation = {
  id: number
  delegator_name: string
  start_date: string
  end_date: string
  status: string
  reason: string | null
}

const props = defineProps<{
  myDelegations: Delegation[]
  incomingDelegations: IncomingDelegation[]
}>()

const createDialog = ref(false)
const confirmDialog = ref()
const createForm = useForm({
  delegate_email: '',
  start_date: '',
  end_date: '',
  reason: '',
})

function submitCreate() {
  createForm.post(route('hr.leave.delegations.store'), {
    onSuccess: () => { createDialog.value = false },
  })
}

function revoke(d: Delegation) {
  confirmDialog.value.open(
    { title: 'Revoke Delegation', message: `Revoke delegation to ${d.delegate_name}?`, confirmText: 'Revoke', confirmColor: 'warning', icon: 'tabler-alert-triangle' },
    () => {
      router.delete(route('hr.leave.delegations.revoke', { delegation: d.id }))
    },
  )
}

function approveIncoming(d: IncomingDelegation) {
  router.post(route('hr.leave.delegations.approve', { delegation: d.id }))
}

function declineIncoming(d: IncomingDelegation) {
  router.post(route('hr.leave.delegations.decline', { delegation: d.id }))
}

const statusColor = (s: string) => s === 'active' ? 'success' : s === 'pending' ? 'warning' : s === 'declined' ? 'error' : 'default'

function formatDate(val: string) {
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6" style="max-width: 860px;">

    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('hr.leave.approvals'))" />
        <div>
          <h4 class="text-h4">Approval Delegations</h4>
          <p class="text-medium-emphasis mb-0">Manage who approves leave on your behalf</p>
        </div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="createDialog = true">New Delegation</VBtn>
    </div>

    <!-- My Delegations -->
    <VCard elevation="0" border class="mb-5">
      <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">My Delegations (Outgoing)</VCardTitle>
      <VDivider />
      <VList v-if="myDelegations.length">
        <VListItem
          v-for="d in myDelegations"
          :key="d.id"
          :subtitle="`${d.start_date} → ${d.end_date}${d.reason ? ' · ' + d.reason : ''}`"
        >
          <template #title>
            <span class="text-body-2 font-weight-medium">{{ d.delegate_name }}</span>
            <span class="text-caption text-medium-emphasis ms-2">{{ d.delegate_email }}</span>
          </template>
          <template #prepend>
            <VAvatar color="primary" variant="tonal" size="36" rounded class="me-2">
              <VIcon icon="tabler-user-share" size="18" />
            </VAvatar>
          </template>
          <template #append>
            <div class="d-flex align-center gap-2">
              <VChip :color="statusColor(d.status)" size="x-small" variant="tonal">{{ d.status }}</VChip>
              <VBtn
                v-if="d.status !== 'revoked'"
                icon="tabler-x"
                size="x-small"
                variant="text"
                color="error"
                @click="revoke(d)"
              />
            </div>
          </template>
        </VListItem>
      </VList>
      <VCardText v-else class="text-center py-8 text-medium-emphasis">
        <VIcon icon="tabler-user-share" size="40" class="mb-2" />
        <p class="text-body-2">No active delegations. Create one when you're going on leave.</p>
      </VCardText>
    </VCard>

    <!-- Incoming Delegations -->
    <VCard v-if="incomingDelegations.length" elevation="0" border>
      <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Incoming Delegations</VCardTitle>
      <VDivider />
      <VList>
        <VListItem
          v-for="d in incomingDelegations"
          :key="d.id"
          :subtitle="`${d.start_date} → ${d.end_date}${d.reason ? ' · ' + d.reason : ''}`"
        >
          <template #title>
            <span class="text-body-2 font-weight-medium">From: {{ d.delegator_name }}</span>
          </template>
          <template #prepend>
            <VAvatar color="warning" variant="tonal" size="36" rounded class="me-2">
              <VIcon icon="tabler-user-receive" size="18" />
            </VAvatar>
          </template>
          <template #append>
            <div class="d-flex align-center gap-2">
              <VChip :color="statusColor(d.status)" size="x-small" variant="tonal">{{ d.status }}</VChip>
              <template v-if="d.status === 'pending'">
                <VBtn icon="tabler-check" size="x-small" variant="text" color="success" @click="approveIncoming(d)" />
                <VBtn icon="tabler-x" size="x-small" variant="text" color="error" @click="declineIncoming(d)" />
              </template>
            </div>
          </template>
        </VListItem>
      </VList>
    </VCard>

    <!-- Create Delegation Dialog -->
    <VDialog v-model="createDialog" max-width="500" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-h6">New Delegation</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12">
              <VTextField
                v-model="createForm.delegate_email"
                label="Delegate's Email *"
                type="email"
                variant="outlined"
                density="comfortable"
                :error-messages="createForm.errors.delegate_email"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="createForm.start_date"
                label="Start Date *"
                type="date"
                variant="outlined"
                density="comfortable"
                :error-messages="createForm.errors.start_date"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="createForm.end_date"
                label="End Date *"
                type="date"
                variant="outlined"
                density="comfortable"
                :min="createForm.start_date"
                :error-messages="createForm.errors.end_date"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="createForm.reason"
                label="Reason (optional)"
                variant="outlined"
                density="comfortable"
                rows="2"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="createDialog = false">Cancel</VBtn>
          <VBtn
            color="primary"
            variant="flat"
            :loading="createForm.processing"
            :disabled="!createForm.delegate_email || !createForm.start_date || !createForm.end_date"
            @click="submitCreate"
          >
            Create Delegation
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmDialog" />

  </VContainer>
</template>
