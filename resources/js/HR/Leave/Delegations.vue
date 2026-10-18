<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

type Delegation = {
  id: number; delegate: string; start_date: string; end_date: string
  reason: string | null; approval_status?: string
}
type AuditEntry = {
  id: number; direction: 'outbound' | 'inbound'; other_party: string
  start_date: string; end_date: string; reason: string | null
  is_active: boolean; approval_status: string; decline_reason: string | null
  revoked_at: string | null
}
type PendingDelegation = {
  id: number; delegator: string; delegate: string
  start_date: string; end_date: string; reason: string | null
}
type User = { id: number; name: string; email: string }

const props = defineProps<{
  activeDelegation:      Delegation | null
  auditLog:              AuditEntry[]
  users:                 User[]
  pendingDelegations:    PendingDelegation[]
  canManageDelegations:  boolean
  allowDelegation:       boolean
}>()

// ── Create dialog ──────────────────────────────────────────────────────────
const createDialog   = ref(false)
const form = useForm({
  delegate_id: '' as number | '',
  start_date:  '',
  end_date:    '',
  reason:      '',
})
const today = new Date().toISOString().split('T')[0]
const submitCreate = () => {
  form.post('/leave-management/delegations', {
    onSuccess: () => { createDialog.value = false; form.reset() },
  })
}

// ── Revoke dialog ──────────────────────────────────────────────────────────
const revokeDialog   = ref(false)
const revokeTargetId = ref<number | null>(null)
const openRevoke = (id: number) => { revokeTargetId.value = id; revokeDialog.value = true }
const confirmRevoke = () => {
  if (!revokeTargetId.value) return
  router.delete(`/leave-management/delegations/${revokeTargetId.value}`, {
    onSuccess: () => { revokeDialog.value = false },
  })
}

// ── Decline dialog (HR/admin) ──────────────────────────────────────────────
const declineDialog   = ref(false)
const declineTargetId = ref<number | null>(null)
const declineReason   = ref('')
const declineLoading  = ref(false)

const openDecline = (id: number) => {
  declineTargetId.value = id
  declineReason.value   = ''
  declineDialog.value   = true
}
const confirmDecline = () => {
  if (!declineTargetId.value) return
  declineLoading.value = true
  router.post(`/leave-management/delegations/${declineTargetId.value}/decline`, { reason: declineReason.value }, {
    onFinish: () => { declineLoading.value = false },
    onSuccess: () => { declineDialog.value = false },
  })
}

const approveDelegate = (id: number) => {
  router.post(`/leave-management/delegations/${id}/approve`, {})
}

// ── Helpers ────────────────────────────────────────────────────────────────
const directionColor = (d: 'outbound' | 'inbound') => d === 'outbound' ? 'primary' : 'secondary'
const directionLabel = (d: 'outbound' | 'inbound', party: string) =>
  d === 'outbound' ? `Delegated to ${party}` : `Delegated from ${party}`

const approvalStatusColor = (s: string) =>
  ({ approved: 'success', pending: 'warning', declined: 'error' }[s] ?? 'secondary')

const { t } = useI18n()
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow justify="center">
      <VCol cols="12" md="9" lg="8">

        <!-- Header -->
        <div class="d-flex align-center gap-3 mb-6">
          <VBtn icon variant="text" @click="router.visit('/leave-management/approvals')">
            <VIcon icon="tabler-arrow-left" />
          </VBtn>
          <div class="flex-grow-1">
            <h4 class="text-h4">{{ t('leave.delegations') }}</h4>
            <p class="text-medium-emphasis mb-0">Temporarily transfer your approval authority to a colleague</p>
          </div>
          <VBtn
            color="primary"
            prepend-icon="tabler-plus"
            :disabled="!allowDelegation"
            @click="allowDelegation ? (createDialog = true) : null"
          >
            {{ t('leave.new_delegation') }}
          </VBtn>
        </div>

        <!-- Delegation disabled banner -->
        <VAlert v-if="!allowDelegation" type="warning" variant="tonal" density="compact" class="mb-4" icon="tabler-block-helper">
          Approval delegation is currently <strong>disabled</strong> by your system administrator. Contact HR or an administrator to enable it under Leave Setup → Approval Configuration.
        </VAlert>

        <!-- ── HR/Admin: Pending approvals panel ── -->
        <VCard v-if="canManageDelegations && pendingDelegations.length" class="mb-4" color="warning" variant="tonal">
          <VCardTitle class="pa-4 pb-2 text-subtitle-1">
            <VIcon icon="tabler-account-clock" class="me-2" />Delegations Awaiting Your Approval
            <VChip size="x-small" color="warning" class="ms-2">{{ pendingDelegations.length }}</VChip>
          </VCardTitle>
          <VDivider />
          <VTable density="comfortable">
            <thead>
              <tr>
                <th>{{ t('leave.delegator') }}</th>
                <th>{{ t('leave.delegate_name') }}</th>
                <th>{{ t('leave.period') }}</th>
                <th>{{ t('leave.delegation_reason') }}</th>
                <th>{{ t('leave.actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="pd in pendingDelegations" :key="pd.id">
                <td class="font-weight-medium">{{ pd.delegator }}</td>
                <td>{{ pd.delegate }}</td>
                <td class="text-caption text-no-wrap">{{ pd.start_date }} – {{ pd.end_date }}</td>
                <td class="text-caption" style="max-width:200px">{{ pd.reason ?? '—' }}</td>
                <td>
                  <div class="d-flex gap-1">
                    <VBtn size="x-small" color="success" @click="approveDelegate(pd.id)">{{ t('leave.approve') }}</VBtn>
                    <VBtn size="x-small" color="error" variant="tonal" @click="openDecline(pd.id)">{{ t('leave.decline') }}</VBtn>
                  </div>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>

        <VAlert
          v-else-if="canManageDelegations"
          type="success"
          variant="tonal"
          density="compact"
          class="mb-4"
          icon="tabler-check-circle-outline"
        >
          No delegations are currently awaiting approval.
        </VAlert>

        <!-- My active delegation banner -->
        <VCard v-if="activeDelegation" class="mb-4"
          :color="activeDelegation.approval_status === 'pending' ? 'warning' : 'info'"
          variant="tonal"
        >
          <VCardText class="d-flex align-center gap-3">
            <VIcon icon="tabler-account-arrow-right" size="32" />
            <div class="flex-grow-1">
              <div class="font-weight-medium">
                <span v-if="activeDelegation.approval_status === 'pending'">
                  Pending approval — delegation to {{ activeDelegation.delegate }}
                </span>
                <span v-else>Active delegation to {{ activeDelegation.delegate }}</span>
              </div>
              <div class="text-caption">
                {{ activeDelegation.start_date }} – {{ activeDelegation.end_date }}
                <span v-if="activeDelegation.reason"> · {{ activeDelegation.reason }}</span>
              </div>
              <VChip v-if="activeDelegation.approval_status === 'pending'" size="x-small" color="warning" class="mt-1">
                Awaiting HR/Admin Approval
              </VChip>
            </div>
            <VBtn
              v-if="activeDelegation.approval_status !== 'pending'"
              color="error"
              variant="tonal"
              size="small"
              prepend-icon="tabler-close"
              @click="openRevoke(activeDelegation.id)"
            >
              Revoke
            </VBtn>
          </VCardText>
        </VCard>

        <VAlert v-else type="info" variant="tonal" density="compact" class="mb-4" icon="tabler-information-outline">
          You have no active delegation. Any leave requests will be routed to you directly.
        </VAlert>

        <!-- Audit log -->
        <VCard>
          <VCardTitle class="pa-4 pb-2 text-subtitle-1">
            <VIcon icon="tabler-history" class="me-2" />{{ t('leave.approval_history') }}
          </VCardTitle>
          <VDivider />

          <div v-if="!auditLog.length" class="text-medium-emphasis text-center py-8">
            No delegation history.
          </div>

          <VTable v-else density="comfortable" hover>
            <thead>
              <tr>
                <th>Direction</th>
                <th>{{ t('leave.period') }}</th>
                <th>{{ t('leave.delegation_reason') }}</th>
                <th>{{ t('leave.status') }}</th>
                <th>{{ t('leave.revoke') }}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="entry in auditLog" :key="entry.id">
                <td>
                  <VChip
                    size="small"
                    :color="directionColor(entry.direction)"
                    label
                    :prepend-icon="entry.direction === 'outbound' ? 'tabler-arrow-right' : 'tabler-arrow-left'"
                  >
                    {{ directionLabel(entry.direction, entry.other_party) }}
                  </VChip>
                </td>
                <td class="text-caption text-no-wrap">{{ entry.start_date }} – {{ entry.end_date }}</td>
                <td class="text-caption" style="max-width:200px">{{ entry.reason ?? '—' }}</td>
                <td>
                  <VChip size="x-small" :color="approvalStatusColor(entry.approval_status)" label>
                    {{ entry.approval_status }}
                  </VChip>
                  <div v-if="entry.approval_status === 'declined' && entry.decline_reason" class="text-caption text-error mt-1">
                    {{ entry.decline_reason }}
                  </div>
                </td>
                <td class="text-caption text-medium-emphasis">{{ entry.revoked_at ?? '—' }}</td>
                <td>
                  <VBtn
                    v-if="entry.is_active && entry.direction === 'outbound' && entry.approval_status === 'approved'"
                    size="x-small"
                    color="error"
                    variant="tonal"
                    @click="openRevoke(entry.id)"
                  >
                    {{ t('leave.revoke') }}
                  </VBtn>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>

      </VCol>
    </VRow>

    <!-- Create delegation dialog -->
    <VDialog v-model="createDialog" max-width="500">
      <VCard>
        <VCardTitle class="pa-4">{{ t('leave.new_delegation') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAlert v-if="form.errors.delegation" type="error" variant="tonal" density="compact" class="mb-4">
            {{ form.errors.delegation }}
          </VAlert>
          <VAlert type="info" variant="tonal" density="compact" class="mb-4" icon="tabler-shield-account">
            All leave requests routed to you during the delegation period will instead be sent to your chosen delegate.
            Circular delegations are prevented automatically.
          </VAlert>
          <VRow>
            <VCol cols="12">
              <VAutocomplete
                v-model="form.delegate_id"
                :items="users"
                item-title="name"
                item-value="id"
                :label="t('leave.delegate_to')"
                variant="outlined"
                density="comfortable"
                :error-messages="form.errors.delegate_id"
              >
                <template #item="{ item, props: itemProps }">
                  <VListItem v-bind="itemProps">
                    <template #subtitle>{{ item.raw.email }}</template>
                  </VListItem>
                </template>
              </VAutocomplete>
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="form.start_date"
                :label="t('leave.start_date')"
                type="date"
                :min="today"
                variant="outlined"
                density="comfortable"
                :error-messages="form.errors.start_date"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="form.end_date"
                :label="t('leave.end_date')"
                type="date"
                :min="form.start_date || today"
                variant="outlined"
                density="comfortable"
                :error-messages="form.errors.end_date"
              />
            </VCol>
            <VCol cols="12">
              <VTextField
                v-model="form.reason"
                label="Reason (e.g. leave, conference)"
                variant="outlined"
                density="comfortable"
                :error-messages="form.errors.reason"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="tonal" @click="createDialog = false">{{ t('leave.cancel') }}</VBtn>
          <VBtn
            color="primary"
            :loading="form.processing"
            :disabled="!form.delegate_id || !form.start_date || !form.end_date"
            @click="submitCreate"
          >
            {{ t('leave.save_delegation') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Revoke confirmation dialog -->
    <VDialog v-model="revokeDialog" max-width="440">
      <VCard>
        <VCardTitle class="pa-4">
          <VIcon icon="tabler-close-circle-outline" color="error" class="me-2" />{{ t('leave.revoke') }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAlert type="warning" variant="tonal" density="compact">
            The original approver will immediately regain their approval authority.
            Any pending approvals currently with the delegate will be transferred back.
          </VAlert>
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="tonal" @click="revokeDialog = false">{{ t('leave.cancel') }}</VBtn>
          <VBtn color="error" @click="confirmRevoke">{{ t('leave.revoke') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Decline dialog (HR/admin only) -->
    <VDialog v-model="declineDialog" max-width="480">
      <VCard>
        <VCardTitle class="pa-4">
          <VIcon icon="tabler-account-remove" color="error" class="me-2" />{{ t('leave.decline') }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VTextarea
            v-model="declineReason"
            :label="t('leave.delegation_decline_reason')"
            variant="outlined"
            density="comfortable"
            rows="3"
            autofocus
          />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="tonal" @click="declineDialog = false">{{ t('leave.cancel') }}</VBtn>
          <VBtn color="error" :loading="declineLoading" :disabled="!declineReason.trim()" @click="confirmDecline">
            {{ t('leave.confirm_decline') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
