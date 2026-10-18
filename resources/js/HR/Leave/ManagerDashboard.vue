<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Stats = {
  team_size: number
  on_leave_today: number
  pending_approvals: number
  anomaly_flags: number
}

type TeamMember = {
  employee_name: string
  leave_type: string
  end_date: string
  working_days: number
}

type Approval = {
  id: number
  request_number: string
  employee_name: string
  leave_type: string
  start_date: string
  end_date: string
  working_days: number
  status: string
  status_color: string
  status_label: string
}

type UpcomingLeave = {
  employee_name: string
  leave_type: string
  start_date: string
  end_date: string
  working_days: number
  status: string
}

type Anomaly = {
  employee_name: string
  anomaly_type: string
  severity: string
  detected_at: string
}

type BalanceRow = {
  employee_name: string
  leave_type: string
  balance_days: number
  is_negative: boolean
}

const props = withDefaults(defineProps<{
  stats: Stats
  teamOnLeaveToday: TeamMember[]
  pendingApprovals: Approval[]
  upcomingLeave: UpcomingLeave[]
  teamAnomalies: Anomaly[]
  teamBalances: BalanceRow[]
}>(), {
  stats: () => ({ team_size: 0, on_leave_today: 0, pending_approvals: 0, anomaly_flags: 0 }),
  teamOnLeaveToday: () => [],
  pendingApprovals: () => [],
  upcomingLeave: () => [],
  teamAnomalies: () => [],
  teamBalances: () => [],
})

const activeTab = ref<'overview' | 'approvals' | 'upcoming' | 'anomalies' | 'balances'>('overview')

const approvalLoading = ref<number | null>(null)
const toast = ref<{ show: boolean; text: string; color: string }>({ show: false, text: '', color: 'success' })

const rejectDialog  = ref(false)
const rejectTargetId = ref<number | null>(null)
const rejectReason   = ref('')

const openReject = (id: number) => {
  rejectTargetId.value = id
  rejectReason.value   = ''
  rejectDialog.value   = true
}

const severityColor = (s: string) => ({ critical: 'error', high: 'warning', medium: 'orange', low: 'info' } as Record<string, string>)[s] ?? 'secondary'

const presentPercent = computed(() => {
  if (!props.stats.team_size) return 100
  return Math.round(((props.stats.team_size - props.stats.on_leave_today) / props.stats.team_size) * 100)
})

const presentColor = computed(() => presentPercent.value >= 80 ? 'success' : presentPercent.value >= 65 ? 'warning' : 'error')

function showToast(text: string, color = 'success') {
  toast.value = { show: true, text, color }
}

async function quickApprove(id: number) {
  approvalLoading.value = id
  try {
    await fetch(`/leave-management/approvals/${id}/approve`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
      },
      body: JSON.stringify({ notes: 'Approved from Manager Dashboard' }),
    })
    showToast(t('leave_manager_dashboard.toast_approved'))
    router.reload({ only: ['pendingApprovals', 'stats'] })
  } catch {
    showToast(t('leave_manager_dashboard.toast_approve_failed'), 'error')
  } finally {
    approvalLoading.value = null
  }
}

async function submitReject() {
  if (!rejectTargetId.value) return
  const id = rejectTargetId.value
  approvalLoading.value = id
  rejectDialog.value = false
  try {
    await fetch(`/leave-management/approvals/${id}/reject`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
      },
      body: JSON.stringify({ reason: rejectReason.value || 'Rejected from Manager Dashboard' }),
    })
    showToast(t('leave_manager_dashboard.toast_rejected'))
    router.reload({ only: ['pendingApprovals', 'stats'] })
  } catch {
    showToast(t('leave_manager_dashboard.toast_reject_failed'), 'error')
  } finally {
    approvalLoading.value = null
  }
}
</script>

<template>
  <div>
    <!-- Header -->
    <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4 px-1">
      <div>
        <h3 class="text-h5 font-weight-semibold mb-1">{{ t('leave.manager_dashboard') }}</h3>
        <p class="text-medium-emphasis mb-0 text-body-2">{{ t('leave_manager_dashboard.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" size="small" href="/leave-management/approvals">
          <i class="ri-check-double-line me-1"></i>{{ t('leave.all_approvals') }}
        </VBtn>
        <VBtn variant="tonal" size="small" color="primary" href="/leave-management/calendar">
          <i class="ri-calendar-line me-1"></i>{{ t('leave_manager_dashboard.team_calendar') }}
        </VBtn>
      </div>
    </div>

    <!-- Stat cards -->
    <VRow class="mb-4">
      <VCol cols="6" sm="3">
        <VCard>
          <VCardText class="text-center pa-4">
            <i class="ri-team-line text-h4 text-medium-emphasis"></i>
            <div class="text-h4 font-weight-bold mt-1">{{ stats.team_size }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('leave.team_size') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard>
          <VCardText class="text-center pa-4">
            <i class="ri-user-unfollow-line text-h4 text-warning"></i>
            <div class="text-h4 font-weight-bold mt-1">{{ stats.on_leave_today }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('leave.on_leave_today') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard>
          <VCardText class="text-center pa-4">
            <i class="ri-time-line text-h4 text-info"></i>
            <div class="text-h4 font-weight-bold mt-1">{{ stats.pending_approvals }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('leave.pending_approvals') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard>
          <VCardText class="text-center pa-4">
            <i class="ri-shield-check-line text-h4" :class="`text-${presentColor}`"></i>
            <div class="text-h4 font-weight-bold mt-1" :class="`text-${presentColor}`">{{ presentPercent }}%</div>
            <VProgressLinear :model-value="presentPercent" :color="presentColor" height="3" class="mt-1 rounded" />
            <div class="text-caption text-medium-emphasis mt-1">{{ t('leave_manager_dashboard.team_present') }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Tabs -->
    <VTabs v-model="activeTab" class="mb-4" density="comfortable">
      <VTab value="overview"><i class="ri-dashboard-line me-2"></i>{{ t('leave.overview_tab') }}</VTab>
      <VTab value="approvals">
        <i class="ri-check-line me-2"></i>{{ t('leave.pending_approvals') }}
        <VChip v-if="stats.pending_approvals" size="x-small" color="error" class="ms-1">{{ stats.pending_approvals }}</VChip>
      </VTab>
      <VTab value="upcoming"><i class="ri-calendar-event-line me-2"></i>{{ t('leave.upcoming_tab') }}</VTab>
      <VTab value="anomalies">
        <i class="ri-error-warning-line me-2"></i>{{ t('leave.anomaly_flags') }}
        <VChip v-if="stats.anomaly_flags" size="x-small" color="warning" class="ms-1">{{ stats.anomaly_flags }}</VChip>
      </VTab>
      <VTab value="balances"><i class="ri-scales-line me-2"></i>{{ t('leave.team_leave_balances') }}</VTab>
    </VTabs>

    <VWindow v-model="activeTab">

      <VWindowItem value="overview">
        <VRow>
          <VCol cols="12" md="6">
            <VCard height="100%">
              <VCardTitle class="pa-4">{{ t('leave.on_leave_today') }}</VCardTitle>
              <VDivider />
              <VCardText class="pa-0">
                <div v-if="!teamOnLeaveToday.length" class="pa-6 text-center text-medium-emphasis">
                  <i class="ri-checkbox-circle-line" style="font-size:2rem;color:#4caf50"></i>
                  <p class="mt-2 mb-0">{{ t('leave_manager_dashboard.full_team_in') }}</p>
                </div>
                <VTable v-else density="compact" hover>
                  <thead>
                    <tr>
                      <th>{{ t('leave.employee_label') }}</th>
                      <th>{{ t('leave.leave_type') }}</th>
                      <th>{{ t('leave.returns') }}</th>
                      <th>{{ t('leave.days') }}</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="m in teamOnLeaveToday" :key="m.employee_name + m.leave_type">
                      <td class="font-weight-medium">{{ m.employee_name }}</td>
                      <td>{{ m.leave_type }}</td>
                      <td class="text-caption">{{ m.end_date }}</td>
                      <td>{{ m.working_days }}</td>
                    </tr>
                  </tbody>
                </VTable>
              </VCardText>
            </VCard>
          </VCol>

          <VCol cols="12" md="6">
            <VCard height="100%">
              <VCardTitle class="pa-4">{{ t('leave.quick_approvals') }}</VCardTitle>
              <VDivider />
              <VCardText class="pa-0">
                <div v-if="!pendingApprovals.length" class="pa-6 text-center text-medium-emphasis">
                  <i class="ri-inbox-line" style="font-size:2rem"></i>
                  <p class="mt-2 mb-0">{{ t('leave_manager_dashboard.no_pending_approvals_overview') }}</p>
                </div>
                <VList v-else density="compact">
                  <VListItem v-for="r in pendingApprovals.slice(0, 5)" :key="r.id" class="py-2">
                    <template #title>
                      <span class="font-weight-medium">{{ r.employee_name }}</span>
                      <span class="text-caption text-medium-emphasis ms-2">{{ r.leave_type }}</span>
                    </template>
                    <template #subtitle>{{ r.start_date }} – {{ r.end_date }} &nbsp;({{ r.working_days }}d)</template>
                    <template #append>
                      <div class="d-flex gap-1">
                        <VBtn size="x-small" color="success" :loading="approvalLoading === r.id" @click="quickApprove(r.id)">{{ t('leave.approve') }}</VBtn>
                        <VBtn size="x-small" color="error" variant="tonal" :loading="approvalLoading === r.id" @click="openReject(r.id)">{{ t('leave.decline') }}</VBtn>
                      </div>
                    </template>
                  </VListItem>
                </VList>
                <VCardActions v-if="pendingApprovals.length > 5">
                  <VBtn variant="text" size="small" href="/leave-management/approvals">
                    {{ t('leave_manager_dashboard.view_all_approvals', { count: pendingApprovals.length }) }}
                  </VBtn>
                </VCardActions>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>
      </VWindowItem>

      <VWindowItem value="approvals">
        <VCard>
          <VCardTitle class="pa-4">{{ t('leave.pending_approvals') }}</VCardTitle>
          <VDivider />
          <VCardText class="pa-0">
            <div v-if="!pendingApprovals.length" class="pa-8 text-center text-medium-emphasis">{{ t('leave.no_pending_approvals') }}</div>
            <VTable v-else density="comfortable" hover>
              <thead>
                <tr>
                  <th>{{ t('leave.ref') }}</th>
                  <th>{{ t('leave.employee_label') }}</th>
                  <th>{{ t('leave.leave_type') }}</th>
                  <th>{{ t('leave.period') }}</th>
                  <th>{{ t('leave.days') }}</th>
                  <th>{{ t('leave.status') }}</th>
                  <th>{{ t('leave.actions') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in pendingApprovals" :key="r.id">
                  <td class="text-caption font-weight-medium">{{ r.request_number }}</td>
                  <td class="font-weight-medium">{{ r.employee_name }}</td>
                  <td>{{ r.leave_type }}</td>
                  <td class="text-caption">{{ r.start_date }} – {{ r.end_date }}</td>
                  <td>{{ r.working_days }}</td>
                  <td><VChip size="x-small" :color="r.status_color" label>{{ r.status_label }}</VChip></td>
                  <td>
                    <div class="d-flex gap-1">
                      <VBtn size="x-small" color="success" :loading="approvalLoading === r.id" @click="quickApprove(r.id)">{{ t('leave.approve') }}</VBtn>
                      <VBtn size="x-small" color="error" variant="tonal" :loading="approvalLoading === r.id" @click="openReject(r.id)">{{ t('leave.decline') }}</VBtn>
                      <VBtn size="x-small" variant="tonal" :href="`/leave-management/my-applications/${r.id}`">{{ t('leave.view') }}</VBtn>
                    </div>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
        </VCard>
      </VWindowItem>

      <VWindowItem value="upcoming">
        <VCard>
          <VCardTitle class="pa-4">{{ t('leave.upcoming_leave') }}</VCardTitle>
          <VDivider />
          <VCardText class="pa-0">
            <div v-if="!upcomingLeave.length" class="pa-8 text-center text-medium-emphasis">{{ t('leave.no_recent_requests') }}</div>
            <VTable v-else density="comfortable" hover>
              <thead>
                <tr>
                  <th>{{ t('leave.employee_label') }}</th>
                  <th>{{ t('leave.leave_type') }}</th>
                  <th>{{ t('leave.start_date') }}</th>
                  <th>{{ t('leave.end_date') }}</th>
                  <th>{{ t('leave.days') }}</th>
                  <th>{{ t('leave.status') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in upcomingLeave" :key="r.employee_name + r.start_date">
                  <td class="font-weight-medium">{{ r.employee_name }}</td>
                  <td>{{ r.leave_type }}</td>
                  <td>{{ r.start_date }}</td>
                  <td>{{ r.end_date }}</td>
                  <td>{{ r.working_days }}</td>
                  <td><VChip size="x-small" :color="r.status === 'approved' ? 'success' : 'warning'" label>{{ r.status }}</VChip></td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
        </VCard>
      </VWindowItem>

      <VWindowItem value="anomalies">
        <VCard>
          <VCardTitle class="pa-4">{{ t('leave.team_anomaly_flags') }}</VCardTitle>
          <VDivider />
          <VCardText class="pa-0">
            <div v-if="!teamAnomalies.length" class="pa-8 text-center text-medium-emphasis">
              <i class="ri-checkbox-circle-line" style="font-size:2rem;color:#4caf50"></i>
              <p class="mt-2 mb-0">{{ t('leave.no_pending_approvals') }}</p>
            </div>
            <VTable v-else density="comfortable" hover>
              <thead>
                <tr>
                  <th>{{ t('leave.employee_label') }}</th>
                  <th>{{ t('leave.anomaly_type') }}</th>
                  <th>{{ t('leave.severity') }}</th>
                  <th>{{ t('leave.detected') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="a in teamAnomalies" :key="a.employee_name + a.detected_at">
                  <td class="font-weight-medium">{{ a.employee_name }}</td>
                  <td>{{ a.anomaly_type }}</td>
                  <td><VChip size="x-small" :color="severityColor(a.severity)" label>{{ a.severity.toUpperCase() }}</VChip></td>
                  <td class="text-caption">{{ a.detected_at }}</td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
        </VCard>
      </VWindowItem>

      <VWindowItem value="balances">
        <VCard>
          <VCardTitle class="pa-4">{{ t('leave.team_leave_balances') }}</VCardTitle>
          <VDivider />
          <VCardText class="pa-0">
            <div v-if="!teamBalances.length" class="pa-8 text-center text-medium-emphasis">{{ t('leave.no_balance_data') }}</div>
            <VTable v-else density="comfortable" hover>
              <thead>
                <tr>
                  <th>{{ t('leave.employee_label') }}</th>
                  <th>{{ t('leave.leave_type') }}</th>
                  <th>{{ t('leave.balance_days') }}</th>
                  <th>{{ t('leave.status') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="b in teamBalances" :key="b.employee_name + b.leave_type">
                  <td class="font-weight-medium">{{ b.employee_name }}</td>
                  <td>{{ b.leave_type }}</td>
                  <td :class="b.is_negative ? 'text-error font-weight-bold' : ''">{{ b.balance_days }}</td>
                  <td>
                    <VChip v-if="b.is_negative" size="x-small" color="error" label>{{ t('leave_manager_dashboard.negative') }}</VChip>
                    <VChip v-else size="x-small" color="success" label>{{ t('leave_manager_dashboard.ok') }}</VChip>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
        </VCard>
      </VWindowItem>

    </VWindow>

    <!-- Reject dialog -->
    <VDialog v-model="rejectDialog" max-width="480">
      <VCard>
        <VCardTitle class="pa-4">{{ t('leave.reject_request') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VTextarea v-model="rejectReason" :label="t('leave.rejection_reason')" variant="outlined" density="comfortable" rows="3" autofocus />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="tonal" @click="rejectDialog = false">{{ t('leave.cancel') }}</VBtn>
          <VBtn color="error" :disabled="!rejectReason.trim()" @click="submitReject">{{ t('leave.confirm_rejection') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VSnackbar v-model="toast.show" :color="toast.color" timeout="2500" location="top right">
      {{ toast.text }}
    </VSnackbar>
  </div>
</template>
