<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type PendingRow = {
  id: number; request_number: string; employee_name: string; leave_type: string
  start_date: string; end_date: string; working_days: number
  status: string; status_label: string; status_color: string
  submitted_at: string | null; notes: string | null
  days_waiting: number; sla_days: number; sla_due_at: string | null
  sla_overdue: boolean; is_escalated: boolean; escalation_count: number
  level_name: string | null; bcea_has_errors: boolean
}
type ActionedRow = {
  id: number; request_number: string; employee: string; leave_type: string
  level_name: string; status: string; comment: string | null
  actioned_at: string; is_policy_override: boolean
}
type Delegation = { id: number; delegate: string; start_date: string; end_date: string; reason: string | null }

const props = defineProps<{
  pending:        PendingRow[]
  recentActioned: ActionedRow[]
  summary:        { total_pending: number }
  myDelegation:   Delegation | null
}>()

const tab = ref('pending')

const approveDialog = ref(false)
const activeRow     = ref<PendingRow | null>(null)
const approveForm   = useForm({ comment: '' })

const openApprove = (row: PendingRow) => { activeRow.value = row; approveDialog.value = true }
const submitApprove = () => {
  if (!activeRow.value) return
  approveForm.post(`/leave-management/approvals/${activeRow.value.id}/approve`, {
    onSuccess: () => { approveDialog.value = false; approveForm.reset() },
  })
}

const rejectDialog = ref(false)
const rejectForm   = useForm({ reason: '' })

const openReject = (row: PendingRow) => { activeRow.value = row; rejectDialog.value = true }
const submitReject = () => {
  if (!activeRow.value) return
  rejectForm.post(`/leave-management/approvals/${activeRow.value.id}/reject`, {
    onSuccess: () => { rejectDialog.value = false; rejectForm.reset() },
  })
}

const overrideDialog = ref(false)
const overrideForm   = useForm({ reason: '' })

const openOverride = (row: PendingRow) => { activeRow.value = row; overrideDialog.value = true }
const submitOverride = () => {
  if (!activeRow.value) return
  overrideForm.post(`/leave-management/approvals/${activeRow.value.id}/policy-override`, {
    onSuccess: () => { overrideDialog.value = false; overrideForm.reset() },
  })
}

const slaColor = (row: PendingRow) => {
  if (row.sla_overdue || row.is_escalated) return 'error'
  if (row.days_waiting >= row.sla_days - 1)  return 'warning'
  return 'success'
}

const slaLabel = (row: PendingRow) => {
  if (row.is_escalated) return t('leave.escalated')
  if (row.sla_overdue)  return t('leave_approvals.sla_overdue_label')
  return `${row.days_waiting}d waiting`
}

const overdueCount  = computed(() => props.pending.filter(r => r.sla_overdue || r.is_escalated).length)
const escalateCount = computed(() => props.pending.filter(r => r.is_escalated).length)
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div>
              <div class="text-overline text-medium-emphasis">{{ t('leave.title') }}</div>
              <h4 class="text-h4">{{ t('leave.approval_queue') }}</h4>
            </div>
            <div class="d-flex gap-2 flex-wrap">
              <VChip color="warning" size="large" label>{{ t('leave_approvals.pending_count', { count: summary.total_pending }) }}</VChip>
              <VChip v-if="escalateCount" color="error" size="large" label prepend-icon="tabler-alarm-light">
                {{ escalateCount }} {{ t('leave.escalated') }}
              </VChip>
              <VChip v-if="overdueCount && !escalateCount" color="warning" size="large" label prepend-icon="tabler-clock-alert">
                {{ overdueCount }} {{ t('leave.sla') }}
              </VChip>
              <VBtn variant="tonal" prepend-icon="tabler-account-arrow-right" size="small" @click="router.visit('/leave-management/delegations')">
                {{ t('leave.delegations') }}
              </VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol v-if="myDelegation" cols="12">
        <VAlert type="info" variant="tonal" density="compact" icon="tabler-account-arrow-right">
          {{ t('leave_approvals.delegation_banner', { delegate: myDelegation.delegate, start: myDelegation.start_date, end: myDelegation.end_date }) }}
          <VBtn size="x-small" variant="text" class="ms-2" @click="router.visit('/leave-management/delegations')">{{ t('leave.manage') }}</VBtn>
        </VAlert>
      </VCol>

      <VCol cols="12">
        <VCard>
          <VTabs v-model="tab" color="primary">
            <VTab value="pending">
              {{ t('leave.pending_approvals') }}
              <VChip size="x-small" class="ms-2" :color="overdueCount ? 'error' : 'primary'" label>{{ pending.length }}</VChip>
            </VTab>
            <VTab value="history">{{ t('leave.approval_history') }}</VTab>
          </VTabs>
          <VDivider />

          <VTabsWindow v-model="tab">
            <VTabsWindowItem value="pending">
              <VTable hover density="comfortable">
                <thead>
                  <tr>
                    <th>{{ t('leave.ref') }}</th>
                    <th>{{ t('leave.employee') }}</th>
                    <th>{{ t('leave.leave_type') }}</th>
                    <th>{{ t('leave.period') }}</th>
                    <th>{{ t('leave.days') }}</th>
                    <th>{{ t('leave.level') }}</th>
                    <th>{{ t('leave.sla') }}</th>
                    <th>{{ t('leave.actions') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-if="!pending.length">
                    <td colspan="8" class="text-center py-10 text-medium-emphasis">
                      <VIcon icon="tabler-checkbox-marked-circle-outline" size="40" class="mb-2 d-block" color="success" />
                      {{ t('leave.no_pending_approvals') }}
                    </td>
                  </tr>
                  <tr v-for="row in pending" :key="row.id">
                    <td>
                      <div class="font-weight-medium">{{ row.request_number }}</div>
                      <div v-if="row.bcea_has_errors" class="d-flex align-center gap-1 mt-1">
                        <VChip size="x-small" color="error" label prepend-icon="tabler-shield-alert">{{ t('leave_approvals.bcea_flag') }}</VChip>
                      </div>
                    </td>
                    <td class="font-weight-medium">{{ row.employee_name }}</td>
                    <td>{{ row.leave_type }}</td>
                    <td class="text-no-wrap text-caption">{{ row.start_date }} – {{ row.end_date }}</td>
                    <td>{{ row.working_days }}</td>
                    <td><VChip size="x-small" color="primary" label>{{ row.level_name ?? '—' }}</VChip></td>
                    <td>
                      <div class="d-flex align-center gap-1">
                        <VIcon :icon="row.is_escalated ? 'tabler-alarm-light' : 'tabler-clock-outline'" size="16" :color="slaColor(row)" />
                        <span class="text-caption" :class="`text-${slaColor(row)}`">{{ slaLabel(row) }}</span>
                      </div>
                      <div v-if="row.sla_due_at" class="text-caption text-medium-emphasis">{{ t('leave_approvals.due_label') }} {{ row.sla_due_at }}</div>
                    </td>
                    <td>
                      <div class="d-flex gap-1 flex-wrap">
                        <VBtn size="x-small" variant="tonal" prepend-icon="tabler-eye" @click="router.visit(`/leave-management/my-applications/${row.id}`)">{{ t('leave.view') }}</VBtn>
                        <VBtn size="x-small" color="success" variant="tonal" prepend-icon="tabler-check" @click="openApprove(row)">{{ t('leave.approve') }}</VBtn>
                        <VBtn size="x-small" color="error" variant="tonal" prepend-icon="tabler-close" @click="openReject(row)">{{ t('leave.decline') }}</VBtn>
                        <VBtn v-if="row.bcea_has_errors" size="x-small" color="warning" variant="tonal" prepend-icon="tabler-shield-check" @click="openOverride(row)">{{ t('leave.override') }}</VBtn>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </VTabsWindowItem>

            <VTabsWindowItem value="history">
              <VTable hover density="comfortable">
                <thead>
                  <tr>
                    <th>{{ t('leave.ref') }}</th>
                    <th>{{ t('leave.employee') }}</th>
                    <th>{{ t('leave.leave_type') }}</th>
                    <th>{{ t('leave.level') }}</th>
                    <th>{{ t('leave.outcome') }}</th>
                    <th>{{ t('leave.comment') }}</th>
                    <th>{{ t('leave.actioned') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-if="!recentActioned.length">
                    <td colspan="7" class="text-center py-10 text-medium-emphasis">{{ t('leave.no_history') }}</td>
                  </tr>
                  <tr v-for="row in recentActioned" :key="`${row.id}-${row.level_name}`">
                    <td class="font-weight-medium">{{ row.request_number }}</td>
                    <td>{{ row.employee }}</td>
                    <td>{{ row.leave_type }}</td>
                    <td><VChip size="x-small" color="primary" label>{{ row.level_name }}</VChip></td>
                    <td>
                      <VChip size="x-small" :color="row.status === 'approved' || row.status === 'skipped' ? 'success' : 'error'" label :prepend-icon="row.is_policy_override ? 'tabler-shield-check' : undefined">
                        {{ row.is_policy_override ? t('leave_approvals.override_status') : row.status }}
                      </VChip>
                    </td>
                    <td class="text-caption" style="max-width:200px">
                      <span :title="row.comment ?? ''">{{ row.comment ? row.comment.substring(0, 60) + (row.comment.length > 60 ? '…' : '') : '—' }}</span>
                    </td>
                    <td class="text-caption text-medium-emphasis">{{ row.actioned_at }}</td>
                  </tr>
                </tbody>
              </VTable>
            </VTabsWindowItem>
          </VTabsWindow>
        </VCard>
      </VCol>
    </VRow>

    <!-- Approve dialog -->
    <VDialog v-model="approveDialog" max-width="480">
      <VCard>
        <VCardTitle class="pa-4">{{ t('leave.approve_request') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAlert type="success" variant="tonal" density="compact" class="mb-4">
            {{ t('leave_approvals.approving_request', { ref: activeRow?.request_number, name: activeRow?.employee_name, days: activeRow?.working_days }) }}
          </VAlert>
          <div v-if="activeRow?.level_name" class="text-caption text-medium-emphasis mb-3">
            {{ t('leave_approvals.acting_as', { level: activeRow.level_name }) }}
          </div>
          <VTextarea v-model="approveForm.comment" :label="t('leave_approvals.comment_optional')" variant="outlined" rows="2" density="comfortable" />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="tonal" @click="approveDialog = false">{{ t('leave.cancel') }}</VBtn>
          <VBtn color="success" :loading="approveForm.processing" @click="submitApprove">{{ t('leave.approve') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Reject dialog -->
    <VDialog v-model="rejectDialog" max-width="480">
      <VCard>
        <VCardTitle class="pa-4">{{ t('leave.reject_request') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAlert type="error" variant="tonal" density="compact" class="mb-4">
            {{ t('leave_approvals.rejecting_request', { ref: activeRow?.request_number }) }}
          </VAlert>
          <VTextarea v-model="rejectForm.reason" :label="t('leave.rejection_reason')" :error-messages="rejectForm.errors.reason" variant="outlined" rows="3" density="comfortable" />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="tonal" @click="rejectDialog = false">{{ t('leave.cancel') }}</VBtn>
          <VBtn color="error" :loading="rejectForm.processing" :disabled="!rejectForm.reason" @click="submitReject">{{ t('leave.confirm_rejection') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Policy override dialog -->
    <VDialog v-model="overrideDialog" max-width="520">
      <VCard>
        <VCardTitle class="pa-4">
          <VIcon icon="tabler-shield-check" class="me-2" color="warning" />{{ t('leave.policy_override') }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAlert type="warning" variant="tonal" density="compact" class="mb-4">{{ t('leave_approvals.override_alert') }}</VAlert>
          <VTextarea
            v-model="overrideForm.reason"
            :label="t('leave_approvals.override_justification')"
            :error-messages="overrideForm.errors.reason"
            variant="outlined" rows="3" density="comfortable"
            :hint="t('leave_approvals.override_hint')"
            persistent-hint
          />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="tonal" @click="overrideDialog = false">{{ t('leave.cancel') }}</VBtn>
          <VBtn color="warning" :loading="overrideForm.processing" :disabled="!overrideForm.reason" @click="submitOverride">{{ t('leave.apply_override') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
