<script setup lang="ts">
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Balance = {
  leave_type_id: number
  leave_type_name: string
  statutory_type: string | null
  balance_hours: number | null
  balance_days: number | null
  used_hours: number | null
  pending_hours: number | null
  actual_balance_hours: number | null
}

type LeaveRequest = {
  id: number
  request_number: string
  leave_type: string
  start_date: string
  end_date: string
  working_days: number
  status: string
  status_label: string
  status_color: string
}

type Payout = {
  id: number
  leave_type: string
  days_paid_out: number
  amount: number
  payout_date: string | null
  status: string
}

const props = defineProps<{
  recentRequests: LeaveRequest[]
  balances: Balance[]
  pendingApprovals: LeaveRequest[]
  summary: {
    my_pending: number
    my_approved_ytd: number
    pending_my_action: number
    open_anomalies: number
    pending_delegations: number
  }
  activeCycle: { name: string; end_date: string } | null
  pendingDelegationsList: any[]
  allowEmployeePayouts: boolean
  myPayouts: Payout[]
}>()

const statCards = computed(() => [
  { label: 'My Pending',          value: props.summary.my_pending,        icon: 'tabler-clock',        color: 'warning' },
  { label: 'Approved YTD',        value: props.summary.my_approved_ytd,   icon: 'tabler-circle-check', color: 'success' },
  { label: 'Awaiting My Action',  value: props.summary.pending_my_action, icon: 'tabler-bell',         color: 'primary' },
  { label: 'Open Anomalies',      value: props.summary.open_anomalies,    icon: 'tabler-alert-triangle',color: 'error' },
])

const typeColor: Record<string, string> = {
  annual: 'success', sick: 'error', maternity: 'purple', paternity: 'purple',
  parental: 'purple', study: 'info', family_responsibility: 'warning', unpaid: 'secondary',
  special: 'orange', default: 'default',
}

function balanceColor(b: Balance) {
  const days = b.balance_days ?? 0
  if (days < 0) return 'error'
  if (days < 3) return 'warning'
  return typeColor[b.statutory_type ?? ''] ?? 'primary'
}

function formatDays(hours: number | null) {
  if (hours === null) return '—'
  return (hours / 8).toFixed(1) + ' d'
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">

    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <h4 class="text-h4">Leave Management</h4>
        <p class="text-medium-emphasis mb-0">
          {{ activeCycle ? `Cycle: ${activeCycle.name} · ends ${activeCycle.end_date}` : 'No active leave cycle' }}
        </p>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <VBtn variant="tonal" color="default" prepend-icon="tabler-list" @click="router.visit(route('hr.leave.my-applications'))">My Leave</VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="router.visit(route('hr.leave.apply'))">Apply for Leave</VBtn>
      </div>
    </div>

    <!-- Summary Cards -->
    <VRow class="mb-6">
      <VCol v-for="card in statCards" :key="card.label" cols="6" lg="3">
        <VCard>
          <VCardText class="d-flex align-center gap-3 pa-4">
            <VAvatar :color="card.color" variant="tonal" size="46" rounded>
              <VIcon :icon="card.icon" size="22" />
            </VAvatar>
            <div>
              <p class="text-body-2 text-medium-emphasis mb-0">{{ card.label }}</p>
              <h5 class="text-h5">{{ card.value }}</h5>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow>
      <!-- Leave Balances -->
      <VCol cols="12" lg="7">
        <VCard class="mb-5">
          <VCardTitle class="pa-4 pb-2 d-flex align-center justify-space-between">
            <span class="text-body-1 font-weight-semibold">My Leave Balances</span>
            <VBtn variant="text" size="small" @click="router.visit(route('hr.leave.balances'))">View All</VBtn>
          </VCardTitle>
          <VDivider />
          <VCardText class="pa-4">
            <div v-if="balances.length" class="d-flex flex-column gap-3">
              <div v-for="b in balances" :key="b.leave_type_id" class="d-flex align-center gap-3">
                <VAvatar :color="balanceColor(b)" variant="tonal" size="38" rounded>
                  <span class="text-caption font-weight-bold">{{ (b.leave_type_name ?? '?')[0] }}</span>
                </VAvatar>
                <div class="flex-1-1">
                  <div class="d-flex justify-space-between mb-1">
                    <span class="text-body-2 font-weight-medium">{{ b.leave_type_name }}</span>
                    <span class="text-body-2 font-weight-bold" :class="(b.balance_days ?? 0) < 0 ? 'text-error' : ''">
                      {{ formatDays(b.balance_hours) }}
                    </span>
                  </div>
                  <VProgressLinear
                    :model-value="Math.max(0, b.balance_days ?? 0)"
                    :max="Math.max(b.balance_days ?? 0, b.used_hours ? (b.used_hours / 8) : 1, 1)"
                    :color="balanceColor(b)"
                    rounded
                    height="6"
                  />
                  <div class="d-flex justify-space-between mt-1">
                    <span class="text-caption text-medium-emphasis">Used: {{ formatDays(b.used_hours) }}</span>
                    <span v-if="b.pending_hours" class="text-caption text-warning">Pending: {{ formatDays(b.pending_hours) }}</span>
                  </div>
                </div>
              </div>
            </div>
            <div v-else class="text-center py-6 text-medium-emphasis">
              <VIcon icon="tabler-calendar-off" size="40" class="mb-2" />
              <p class="text-body-2">No leave balances found. Contact HR to set up your leave profile.</p>
            </div>
          </VCardText>
        </VCard>

        <!-- Recent Requests -->
        <VCard>
          <VCardTitle class="pa-4 pb-2 d-flex align-center justify-space-between">
            <span class="text-body-1 font-weight-semibold">Recent Applications</span>
            <VBtn variant="text" size="small" @click="router.visit(route('hr.leave.my-applications'))">View All</VBtn>
          </VCardTitle>
          <VDivider />
          <VList v-if="recentRequests.length">
            <VListItem
              v-for="req in recentRequests"
              :key="req.id"
              :subtitle="`${req.start_date} → ${req.end_date} · ${req.working_days} day(s)`"
              class="cursor-pointer"
              @click="router.visit(route('hr.leave.show', { leaveRequest: req.id }))"
            >
              <template #title>
                <span class="text-body-2 font-weight-medium">{{ req.leave_type }}</span>
                <span class="text-caption text-medium-emphasis ms-2">{{ req.request_number }}</span>
              </template>
              <template #append>
                <VChip :color="req.status_color" size="x-small" variant="tonal">{{ req.status_label }}</VChip>
              </template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-center py-6 text-medium-emphasis">
            <p class="text-body-2">No leave applications yet.</p>
            <VBtn color="primary" size="small" variant="tonal" class="mt-2" @click="router.visit(route('hr.leave.apply'))">Apply Now</VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Right Column -->
      <VCol cols="12" lg="5">

        <!-- Pending Approvals -->
        <VCard v-if="pendingApprovals.length" class="mb-5">
          <VCardTitle class="pa-4 pb-2 d-flex align-center justify-space-between">
            <span class="text-body-1 font-weight-semibold">Awaiting My Approval</span>
            <VChip color="warning" size="x-small" variant="tonal">{{ pendingApprovals.length }}</VChip>
          </VCardTitle>
          <VDivider />
          <VList>
            <VListItem
              v-for="req in pendingApprovals"
              :key="req.id"
              :subtitle="`${req.start_date} → ${req.end_date}`"
              class="cursor-pointer"
              @click="router.visit(route('hr.leave.approvals'))"
            >
              <template #title>
                <span class="text-body-2 font-weight-medium">{{ req.employee_name }}</span>
              </template>
              <template #prepend>
                <VAvatar color="warning" variant="tonal" size="36" rounded>
                  <VIcon icon="tabler-user" size="18" />
                </VAvatar>
              </template>
              <template #append>
                <VChip color="warning" size="x-small" variant="tonal">{{ req.leave_type }}</VChip>
              </template>
            </VListItem>
          </VList>
          <VCardActions class="pa-3">
            <VBtn color="warning" variant="tonal" block size="small" @click="router.visit(route('hr.leave.approvals'))">
              View Approvals Queue
            </VBtn>
          </VCardActions>
        </VCard>

        <!-- Quick Actions -->
        <VCard>
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Quick Links</VCardTitle>
          <VDivider />
          <VList density="compact">
            <VListItem prepend-icon="tabler-calendar" @click="router.visit(route('hr.leave.calendar'))">Leave Calendar</VListItem>
            <VListItem prepend-icon="tabler-chart-bar" @click="router.visit(route('hr.leave.balances'))">My Balances</VListItem>
            <VListItem prepend-icon="tabler-layout-dashboard" @click="router.visit(route('hr.leave.manager-dashboard'))">Manager Dashboard</VListItem>
            <VListItem prepend-icon="tabler-users" @click="router.visit(route('hr.leave.approvals'))">Approvals Queue</VListItem>
            <VListItem prepend-icon="tabler-report" @click="router.visit(route('hr.leave.reports'))">Reports</VListItem>
          </VList>
        </VCard>

        <!-- Payouts -->
        <VCard v-if="allowEmployeePayouts && myPayouts.length" class="mt-5">
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">My Leave Payouts</VCardTitle>
          <VDivider />
          <VList density="compact">
            <VListItem
              v-for="p in myPayouts.slice(0, 5)"
              :key="p.id"
              :subtitle="`${p.days_paid_out} days · R${p.amount.toLocaleString()}`"
            >
              <template #title>
                <span class="text-body-2">{{ p.leave_type }}</span>
              </template>
              <template #append>
                <VChip
                  :color="p.status === 'paid' ? 'success' : p.status === 'rejected' ? 'error' : 'warning'"
                  size="x-small"
                  variant="tonal"
                  class="text-capitalize"
                >{{ p.status }}</VChip>
              </template>
            </VListItem>
          </VList>
        </VCard>

      </VCol>
    </VRow>

  </VContainer>
</template>
