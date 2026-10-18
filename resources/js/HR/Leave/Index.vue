<script setup lang="ts">
import { ref, computed, reactive } from 'vue'
import { router, usePage, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

type Balance = {
  leave_type_id: number; leave_type_name: string
  balance_hours: number; balance_days: number
  actual_balance_hours: number; actual_balance_days: number
  future_committed_hours: number; future_committed_days: number
}
type Request = {
  id: number; uuid: string; request_number: string; employee_name: string
  leave_type: string; start_date: string; end_date: string; working_days: number
  status: string; status_label: string; status_color: string; submitted_at: string | null; notes: string | null
}
type PendingDelegation = {
  id: number; delegator: string; delegate: string
  start_date: string; end_date: string; reason: string | null
}

type PayoutRow = {
  id: number; leave_type: string; days_paid_out: number
  amount: number; payout_date: string | null; payroll_reference: string
  status: 'pending' | 'processed' | 'reversed'; notes: string
}

const props = defineProps<{
  recentRequests:         Request[]
  pendingApprovals:       Request[]
  balances:               Balance[]
  summary:                { my_pending: number; my_approved_ytd: number; pending_my_action: number; open_anomalies: number; pending_delegations: number }
  activeCycle:            { name: string; end_date: string } | null
  pendingDelegationsList: PendingDelegation[]
  allowEmployeePayouts:   boolean
  myPayouts:              PayoutRow[]
}>()

// ── Delegation approve / decline ──────────────────────────────────────────
const declineDialog   = ref(false)
const declineTargetId = ref<number | null>(null)
const declineForm     = useForm({ reason: '' })

const openDecline = (id: number) => { declineTargetId.value = id; declineForm.reset(); declineDialog.value = true }

const submitDecline = () => {
  if (!declineTargetId.value) return
  declineForm.post(`/leave-management/delegations/${declineTargetId.value}/decline`, {
    onSuccess: () => { declineDialog.value = false },
  })
}

const approveDelegate = (id: number) => router.post(`/leave-management/delegations/${id}/approve`, {})

const { t } = useI18n()

const page = usePage()
const authRoles = computed<string[]>(() => (page.props as any).auth?.roles ?? [])
const isHrAdmin = computed(() => ['super admin', 'administrator', 'HR'].some(r => authRoles.value.includes(r)))
const isRegularUser = computed(() => authRoles.value.length > 0 && authRoles.value.every(r => r === 'user'))

const allQuickActions = computed(() => [
  { label: t('leave.apply_for_leave'),  path: '/leave-management/apply',            icon: 'fa-plus-circle',   color: 'primary',   hrOnly: false },
  { label: t('leave.my_applications'),  path: '/leave-management/my-applications',  icon: 'fa-list-alt',      color: 'info',      hrOnly: false },
  { label: t('leave.my_balances'),      path: '/leave-management/balances',         icon: 'fa-wallet',        color: 'success',   hrOnly: false },
  { label: t('leave.leave_calendar'),   path: '/leave-management/calendar',         icon: 'fa-calendar-alt',  color: 'secondary', hrOnly: false },
  { label: t('leave.parental_leave'),   path: '/leave-management/parental-leave',   icon: 'fa-baby',          color: 'pink',      hrOnly: true  },
  { label: t('leave.cancel_leave'),     path: '/leave-management/admin-cancel',     icon: 'tabler-cancel',       color: 'error',     hrOnly: true  },
])
const quickActions = computed(() => allQuickActions.value.filter(a => !a.hrOnly || isHrAdmin.value))

// ── Payout request ────────────────────────────────────────────────────────────
const payoutDialog  = ref(false)
const payoutSnack   = reactive({ show: false, text: '', color: 'success' })
const payoutForm    = useForm({ leave_type_id: null as number | null, days: '' as string | number, notes: '' })

const leaveTypesWithBalance = computed(() =>
  props.balances.filter(b => b.balance_days > 5).map(b => ({
    id:    b.leave_type_id,
    title: `${b.leave_type_name} (${b.balance_days} d available)`,
  }))
)

const submitPayout = () => {
  payoutForm.post('/leave-management/my-payouts', {
    onSuccess: () => {
      payoutDialog.value = false
      payoutForm.reset()
      payoutSnack.text  = 'Payout request submitted. HR has been notified.'
      payoutSnack.color = 'success'
      payoutSnack.show  = true
    },
    onError: () => {
      payoutSnack.text  = payoutForm.errors.days ?? 'Could not submit payout request.'
      payoutSnack.color = 'error'
      payoutSnack.show  = true
    },
  })
}

const statusColor = (s: string) => ({ pending: 'warning', processed: 'success', reversed: 'error' }[s] ?? 'default')

function fmtDate(val: string | null) {
  if (!val) return '—'
  return new Date(val + 'T00:00:00').toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}

// ── AI Advisor chat ───────────────────────────────────────────────────────────
type ChatMsg = { role: 'user' | 'assistant'; text: string }

const chatOpen     = ref(false)
const chatInput    = ref('')
const chatLoading  = ref(false)
const chatMessages = ref<ChatMsg[]>([
  { role: 'assistant', text: 'Hi! I\'m your AI Leave Advisor. Ask me anything about your leave entitlements, BCEA rules, or how to apply.' },
])

async function sendChat() {
  const q = chatInput.value.trim()
  if (!q || chatLoading.value) return

  chatMessages.value.push({ role: 'user', text: q })
  chatInput.value = ''
  chatLoading.value = true

  try {
    const res = await fetch('/leave-management/ai-advisor', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
      },
      body: JSON.stringify({ question: q }),
    })
    const data = await res.json()
    chatMessages.value.push({ role: 'assistant', text: data.answer ?? 'Sorry, I could not process your request.' })
  } catch {
    chatMessages.value.push({ role: 'assistant', text: 'AI advisor is temporarily unavailable. Please contact HR directly.' })
  } finally {
    chatLoading.value = false
  }
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <!-- Header -->
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div>
              <div class="text-overline text-medium-emphasis">{{ t('leave.module') }}</div>
              <h4 class="text-h4 mb-1">{{ t('leave.title') }}</h4>
              <p class="mb-0 text-medium-emphasis">
                AI-powered leave platform — BCEA compliant &amp; Van Wyk 2025 ready
                <span v-if="activeCycle" class="ms-2">
                  <VChip size="small" color="info" label>{{ activeCycle.name }} · ends {{ activeCycle.end_date }}</VChip>
                </span>
              </p>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Quick Actions -->
      <VCol cols="12">
        <VRow dense>
          <VCol v-for="action in quickActions" :key="action.label" cols="6" sm="4" md="">
            <VCard
              class="cursor-pointer text-center quick-action-card"
              variant="tonal"
              :color="action.color"
              @click="router.visit(action.path)"
            >
              <VCardText class="pa-4">
                <VIcon :icon="action.icon" size="30" class="mb-2" />
                <div class="text-body-2 font-weight-medium">{{ action.label }}</div>
              </VCardText>
            </VCard>
          </VCol>

          <!-- Payout request button — shown only when feature is enabled -->
          <VCol v-if="allowEmployeePayouts" cols="6" sm="4" md="">
            <VCard
              class="cursor-pointer text-center quick-action-card"
              variant="tonal"
              color="success"
              @click="payoutDialog = true"
            >
              <VCardText class="pa-4">
                <VIcon icon="tabler-cash-plus" size="30" class="mb-2" />
                <div class="text-body-2 font-weight-medium">{{ t('leave.request_payout') }}</div>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>
      </VCol>

      <!-- Summary cards — all in one sub-row so HR/admin 5th card stays aligned -->
      <VCol cols="12">
        <VRow dense>
          <VCol cols="6" sm="3">
            <VCard color="warning" variant="tonal">
              <VCardText class="text-center py-4">
                <VIcon icon="fa-clock" size="28" class="mb-2" />
                <div class="text-h4 font-weight-bold">{{ summary.my_pending }}</div>
                <div class="text-body-2">{{ t('leave.my_pending') }}</div>
              </VCardText>
            </VCard>
          </VCol>
          <VCol cols="6" sm="3">
            <VCard color="success" variant="tonal">
              <VCardText class="text-center py-4">
                <VIcon icon="fa-check-circle" size="28" class="mb-2" />
                <div class="text-h4 font-weight-bold">{{ summary.my_approved_ytd }}</div>
                <div class="text-body-2">{{ t('leave.approved_ytd') }}</div>
              </VCardText>
            </VCard>
          </VCol>
          <VCol v-if="!isRegularUser" cols="6" sm="3">
            <VCard color="primary" variant="tonal">
              <VCardText class="text-center py-4">
                <VIcon icon="fa-tasks" size="28" class="mb-2" />
                <div class="text-h4 font-weight-bold">{{ summary.pending_my_action }}</div>
                <div class="text-body-2">{{ t('leave.awaiting_my_action') }}</div>
              </VCardText>
            </VCard>
          </VCol>
          <VCol cols="6" sm="3">
            <VCard :color="summary.open_anomalies > 0 ? 'error' : 'secondary'" variant="tonal">
              <VCardText class="text-center py-4">
                <VIcon icon="fa-exclamation-triangle" size="28" class="mb-2" />
                <div class="text-h4 font-weight-bold">{{ summary.open_anomalies }}</div>
                <div class="text-body-2">{{ t('leave.anomaly_flags') }}</div>
              </VCardText>
            </VCard>
          </VCol>
          <!-- Delegation approvals stat — HR/admin only -->
          <VCol v-if="isHrAdmin" cols="6" sm="3">
            <VCard
              :color="summary.pending_delegations > 0 ? 'warning' : 'secondary'"
              variant="tonal"
              class="cursor-pointer"
              @click="router.visit('/leave-management/delegations')"
            >
              <VCardText class="text-center py-4">
                <VIcon icon="tabler-account-arrow-right" size="28" class="mb-2" />
                <div class="text-h4 font-weight-bold">{{ summary.pending_delegations }}</div>
                <div class="text-body-2">{{ t('leave.delegation_approvals') }}</div>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>
      </VCol>

      <!-- Pending Delegation Approvals inline section — HR/admin only -->
      <VCol v-if="isHrAdmin" cols="12">
        <VCard>
          <VCardTitle class="pt-4 px-4 d-flex align-center gap-2">
            <VIcon icon="tabler-account-clock" color="warning" />
            {{ t('leave.pending_delegation_list') }}
            <VChip v-if="summary.pending_delegations" size="x-small" color="warning" class="ms-1">
              {{ summary.pending_delegations }}
            </VChip>
          </VCardTitle>
          <VDivider />
          <VTable density="comfortable">
            <thead>
              <tr>
                <th>{{ t('leave.delegator') }}</th>
                <th>{{ t('leave.delegating_to') }}</th>
                <th>{{ t('leave.period') }}</th>
                <th>{{ t('leave.reason') }}</th>
                <th>{{ t('leave.actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!pendingDelegationsList.length">
                <td colspan="5" class="text-center py-6 text-medium-emphasis">
                  {{ t('leave.no_pending_delegations') }}
                </td>
              </tr>
              <tr v-for="d in pendingDelegationsList" :key="d.id">
                <td class="font-weight-medium">{{ d.delegator }}</td>
                <td>{{ d.delegate }}</td>
                <td class="text-caption text-no-wrap">{{ d.start_date }} – {{ d.end_date }}</td>
                <td class="text-caption" style="max-width:200px">{{ d.reason ?? '—' }}</td>
                <td>
                  <div class="d-flex gap-1">
                    <VBtn size="x-small" color="success" @click="approveDelegate(d.id)">{{ t('leave.approve') }}</VBtn>
                    <VBtn size="x-small" color="error" variant="tonal" @click="openDecline(d.id)">{{ t('leave.decline') }}</VBtn>
                  </div>
                </td>
              </tr>
            </tbody>
          </VTable>
          <VCardActions>
            <VBtn variant="text" size="small" @click="router.visit('/leave-management/delegations')">
              {{ t('leave.view_all') }} →
            </VBtn>
          </VCardActions>
        </VCard>
      </VCol>

      <!-- Leave Balances -->
      <VCol cols="12" :md="isRegularUser ? 12 : 5">
        <VCard height="100%">
          <VCardTitle class="pt-4 px-4">
            <VIcon icon="fa-wallet" class="me-2" />{{ t('leave.my_leave_balance') }}
          </VCardTitle>
          <VCardText>
            <div v-if="!balances.length" class="text-medium-emphasis text-center py-6">{{ t('leave.no_balance_data') }}</div>
            <VList v-else density="compact">
              <VListItem v-for="b in balances" :key="b.leave_type_id" class="px-0">
                <template #title>{{ b.leave_type_name }}</template>
                <template #append>
                  <div class="text-end">
                    <div class="font-weight-bold text-body-2">{{ b.balance_days }}d</div>
                    <div v-if="b.future_committed_hours > 0" class="text-caption text-info">
                      Actual: {{ b.actual_balance_days }}d
                    </div>
                  </div>
                </template>
              </VListItem>
            </VList>
          </VCardText>
          <VCardActions>
            <VBtn variant="text" color="primary" @click="router.visit('/leave-management/balances')">{{ t('leave.view_full_balances') }}</VBtn>
          </VCardActions>
        </VCard>
      </VCol>

      <!-- Pending Approvals — hidden for regular employees -->
      <VCol v-if="!isRegularUser" cols="12" md="7">
        <VCard height="100%">
          <VCardTitle class="pt-4 px-4">
            <VIcon icon="fa-tasks" class="me-2" />{{ t('leave.pending_my_approval') }}
          </VCardTitle>
          <VTable density="compact">
            <thead>
              <tr>
                <th>{{ t('leave.ref') }}</th>
                <th>{{ t('leave.employee') }}</th>
                <th>{{ t('leave.type') }}</th>
                <th>{{ t('leave.period') }}</th>
                <th>{{ t('leave.days') }}</th>
                <th>{{ t('leave.actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!pendingApprovals.length">
                <td colspan="6" class="text-center py-6 text-medium-emphasis">{{ t('leave.no_pending_approvals') }}</td>
              </tr>
              <tr v-for="r in pendingApprovals" :key="r.id">
                <td class="text-caption">{{ r.request_number }}</td>
                <td class="font-weight-medium">{{ r.employee_name }}</td>
                <td>{{ r.leave_type }}</td>
                <td class="text-caption">{{ r.start_date }} – {{ r.end_date }}</td>
                <td>{{ r.working_days }}</td>
                <td>
                  <VBtn size="x-small" color="primary" variant="tonal" :href="`/leave-management/approvals`">{{ t('leave.review') }}</VBtn>
                </td>
              </tr>
            </tbody>
          </VTable>
          <VCardActions>
            <VBtn variant="text" color="primary" @click="router.visit('/leave-management/approvals')">{{ t('leave.all_approvals') }}</VBtn>
          </VCardActions>
        </VCard>
      </VCol>

      <!-- My Payout Requests (shown when feature is enabled and employee has requests) -->
      <VCol v-if="allowEmployeePayouts" cols="12">
        <VCard>
          <VCardTitle class="d-flex align-center justify-space-between pt-4 px-4 pb-2">
            <span><VIcon icon="tabler-cash-multiple" class="me-2" color="success" />{{ t('leave.my_payouts') }}</span>
            <VBtn size="small" color="success" prepend-icon="tabler-cash-plus" variant="tonal" @click="payoutDialog = true">
              {{ t('leave.request_payout') }}
            </VBtn>
          </VCardTitle>
          <VTable hover density="comfortable">
            <thead>
              <tr>
                <th>{{ t('leave.leave_type') }}</th>
                <th>{{ t('leave.days') }}</th>
                <th>{{ t('leave.amount') }}</th>
                <th>{{ t('leave.payout_date') }}</th>
                <th>{{ t('leave.payroll_ref') }}</th>
                <th>{{ t('leave.status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!myPayouts.length">
                <td colspan="6" class="text-center py-8 text-medium-emphasis">
                  {{ t('leave.no_payouts') }}
                </td>
              </tr>
              <tr v-for="p in myPayouts" :key="p.id">
                <td>{{ p.leave_type }}</td>
                <td>{{ p.days_paid_out }} d</td>
                <td>
                  <span v-if="p.amount">R {{ p.amount.toLocaleString('en-ZA', { minimumFractionDigits: 2 }) }}</span>
                  <span v-else class="text-medium-emphasis">—</span>
                </td>
                <td>{{ fmtDate(p.payout_date) }}</td>
                <td class="text-caption">{{ p.payroll_reference || '—' }}</td>
                <td><VChip :color="statusColor(p.status)" size="small" label>{{ p.status }}</VChip></td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>

      <!-- Recent My Applications -->
      <VCol cols="12">
        <VCard>
          <VCardTitle class="pt-4 px-4">
            <VIcon icon="fa-list-alt" class="me-2" />{{ t('leave.my_recent_applications') }}
          </VCardTitle>
          <VTable hover density="comfortable">
            <thead>
              <tr>
                <th>{{ t('leave.ref') }}</th>
                <th>{{ t('leave.leave_type') }}</th>
                <th>{{ t('leave.period') }}</th>
                <th>{{ t('leave.days') }}</th>
                <th>{{ t('leave.status') }}</th>
                <th>{{ t('leave.submitted') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!recentRequests.length">
                <td colspan="6" class="text-center py-8 text-medium-emphasis">{{ t('leave.no_recent_requests') }}</td>
              </tr>
              <tr v-for="r in recentRequests" :key="r.id">
                <td class="font-weight-medium">{{ r.request_number }}</td>
                <td>{{ r.leave_type }}</td>
                <td>{{ r.start_date }} – {{ r.end_date }}</td>
                <td>{{ r.working_days }}</td>
                <td><VChip size="small" :color="r.status_color" label>{{ r.status_label }}</VChip></td>
                <td class="text-caption">{{ r.submitted_at }}</td>
              </tr>
            </tbody>
          </VTable>
          <VCardActions>
            <VBtn variant="text" @click="router.visit('/leave-management/my-applications')">{{ t('leave.view_all_applications') }}</VBtn>
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>
  </VContainer>

  <!-- ── AI Leave Advisor chat bubble ─────────────────────────────────────── -->
  <div class="ai-chat-fab">
    <!-- Collapsed FAB -->
    <VBtn
      v-if="!chatOpen"
      color="primary"
      icon
      size="large"
      elevation="0" border
      @click="chatOpen = true"
    >
      <VIcon icon="tabler-robot" />
      <VTooltip activator="parent" location="start">{{ t('leave.ai_advisor') }}</VTooltip>
    </VBtn>

    <!-- Expanded chat panel -->
    <VCard v-else width="360" elevation="0" border class="ai-chat-panel d-flex flex-column">
      <!-- Header -->
      <VCardTitle class="d-flex align-center gap-2 pa-3 bg-primary text-white">
        <VIcon icon="tabler-robot" size="20" />
        <span class="text-body-1 font-weight-medium flex-grow-1">{{ t('leave.ai_advisor') }}</span>
        <VBtn icon size="x-small" variant="text" color="white" @click="chatOpen = false">
          <VIcon icon="tabler-close" />
        </VBtn>
      </VCardTitle>

      <!-- Messages -->
      <VCardText class="ai-chat-messages flex-grow-1 pa-3">
        <div
          v-for="(msg, i) in chatMessages"
          :key="i"
          class="mb-3"
          :class="msg.role === 'user' ? 'd-flex justify-end' : 'd-flex justify-start'"
        >
          <div
            class="ai-chat-bubble text-body-2 pa-2 px-3"
            :class="msg.role === 'user' ? 'bg-primary text-white' : 'bg-surface-variant'"
          >
            {{ msg.text }}
          </div>
        </div>
        <div v-if="chatLoading" class="d-flex justify-start mb-2">
          <div class="ai-chat-bubble bg-surface-variant pa-2 px-3">
            <VProgressCircular size="16" width="2" indeterminate color="primary" />
          </div>
        </div>
      </VCardText>

      <!-- Input -->
      <VDivider />
      <VCardText class="pa-2">
        <VTextField
          v-model="chatInput"
          placeholder="Ask about leave, BCEA rules…"
          density="compact"
          variant="outlined"
          hide-details
          :disabled="chatLoading"
          @keyup.enter="sendChat"
        >
          <template #append-inner>
            <VBtn
              icon
              size="x-small"
              variant="text"
              color="primary"
              :disabled="!chatInput.trim() || chatLoading"
              @click="sendChat"
            >
              <VIcon icon="tabler-send" />
            </VBtn>
          </template>
        </VTextField>
      </VCardText>
    </VCard>
  </div>

  <!-- Decline delegation dialog -->
  <VDialog v-model="declineDialog" max-width="480">
    <VCard>
      <VCardTitle class="pa-4">
        <VIcon icon="tabler-account-remove" color="error" class="me-2" />{{ t('leave.decline') }}
      </VCardTitle>
      <VDivider />
      <VCardText class="pa-4">
        <VTextarea
          v-model="declineForm.reason"
          :label="t('leave.delegation_decline_reason')"
          variant="outlined"
          density="comfortable"
          rows="3"
          autofocus
          :error-messages="declineForm.errors.reason"
        />
      </VCardText>
      <VCardActions class="pa-4 pt-0">
        <VSpacer />
        <VBtn variant="tonal" @click="declineDialog = false">{{ t('leave.cancel') }}</VBtn>
        <VBtn
          color="error"
          :loading="declineForm.processing"
          :disabled="!declineForm.reason.trim()"
          @click="submitDecline"
        >
          {{ t('leave.confirm_decline') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <!-- ── Payout Request Dialog ────────────────────────────────────────────── -->
  <VDialog v-model="payoutDialog" max-width="480" v-if="allowEmployeePayouts">
    <VCard>
      <VCardTitle class="pa-4 pb-2">
        <VIcon icon="tabler-cash-plus" class="me-2" color="success" />{{ t('leave.payout_request') }}
      </VCardTitle>
      <VDivider />
      <VCardText class="pa-4">
        <p class="text-body-2 text-medium-emphasis mb-4">
          Select the leave type and number of days to pay out. Your balance will be deducted and HR will be notified.
          A minimum of 5 days must remain after the payout.
        </p>
        <VForm @submit.prevent="submitPayout">
          <VSelect
            v-model="payoutForm.leave_type_id"
            :items="leaveTypesWithBalance"
            item-title="title"
            item-value="id"
            :label="t('leave.leave_type')"
            variant="outlined"
            density="comfortable"
            :error-messages="payoutForm.errors.leave_type_id"
            class="mb-4"
            no-data-text="No leave types with sufficient balance (>5 days required)."
          />
          <VTextField
            v-model="payoutForm.days"
            :label="t('leave.days_to_pay_out')"
            type="number"
            min="0.5"
            step="0.5"
            variant="outlined"
            density="comfortable"
            hint="Half-day minimum. Each day = 8 hours."
            persistent-hint
            :error-messages="payoutForm.errors.days"
            class="mb-4"
          />
          <VTextarea
            v-model="payoutForm.notes"
            label="Reason / Notes (optional)"
            variant="outlined"
            density="comfortable"
            rows="2"
            :error-messages="payoutForm.errors.notes"
          />
        </VForm>
      </VCardText>
      <VCardActions class="pa-4 pt-0 gap-2 justify-end">
        <VBtn variant="tonal" @click="payoutDialog = false">{{ t('leave.cancel') }}</VBtn>
        <VBtn
          color="success"
          prepend-icon="tabler-cash-check"
          :loading="payoutForm.processing"
          :disabled="!payoutForm.leave_type_id || !payoutForm.days"
          @click="submitPayout"
        >
          {{ t('leave.submit') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <!-- ── Payout snackbar ───────────────────────────────────────────────────── -->
  <VSnackbar v-model="payoutSnack.show" :color="payoutSnack.color" location="top right" timeout="5000">
    {{ payoutSnack.text }}
  </VSnackbar>
</template>

<style scoped>
.quick-action-card {
  transition: transform 0.15s, box-shadow 0.15s;
}
.quick-action-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 16px rgba(0,0,0,0.15) !important;
}

.ai-chat-fab {
  position: fixed;
  bottom: 24px;
  right: 24px;
  z-index: 999;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 12px;
}

.ai-chat-panel {
  max-height: 480px;
}

.ai-chat-messages {
  overflow-y: auto;
  max-height: 320px;
}

.ai-chat-bubble {
  max-width: 260px;
  border-radius: 12px;
  line-height: 1.5;
  word-break: break-word;
}
</style>
