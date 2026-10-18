<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type TeamMember = {
  id: number
  name: string
  leave_type: string
  return_date: string | null
}

type PendingApproval = {
  id: number
  request_number: string
  employee_name: string
  leave_type: string
  start_date: string
  end_date: string
  working_days: number
  submitted_at: string | null
}

type UpcomingLeave = {
  id: number
  employee_name: string
  leave_type: string
  start_date: string
  end_date: string
}

type Anomaly = {
  employee_name: string
  type: string
  message: string
  severity: string
}

type TeamBalance = {
  employee_name: string
  leave_type: string
  balance_days: number | null
}

defineProps<{
  stats: {
    team_size: number
    on_leave_today: number
    pending_approvals: number
    upcoming_this_week: number
  }
  teamOnLeaveToday: TeamMember[]
  pendingApprovals: PendingApproval[]
  upcomingLeave: UpcomingLeave[]
  teamAnomalies: Anomaly[]
  teamBalances: TeamBalance[]
}>()

function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short' })
}

const severityColor = (s: string) => s === 'high' ? 'error' : s === 'medium' ? 'warning' : 'info'
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">

    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('hr.leave.index'))" />
        <div>
          <h4 class="text-h4">Manager Dashboard</h4>
          <p class="text-medium-emphasis mb-0">Team leave overview and coverage management</p>
        </div>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" color="default" prepend-icon="tabler-calendar" @click="router.visit(route('hr.leave.calendar'))">Calendar</VBtn>
        <VBtn color="primary" prepend-icon="tabler-check" @click="router.visit(route('hr.leave.approvals'))">
          Approvals
          <VBadge v-if="stats.pending_approvals" :content="stats.pending_approvals" color="error" floating />
        </VBtn>
      </div>
    </div>

    <!-- Stat Cards -->
    <VRow class="mb-6">
      <VCol cols="6" lg="3">
        <VCard elevation="0" border>
          <VCardText class="d-flex align-center gap-3 pa-4">
            <VAvatar color="primary" variant="tonal" size="46" rounded>
              <VIcon icon="tabler-users" size="22" />
            </VAvatar>
            <div>
              <p class="text-body-2 text-medium-emphasis mb-0">Team Size</p>
              <h5 class="text-h5">{{ stats.team_size }}</h5>
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" lg="3">
        <VCard elevation="0" border>
          <VCardText class="d-flex align-center gap-3 pa-4">
            <VAvatar color="warning" variant="tonal" size="46" rounded>
              <VIcon icon="tabler-calendar-minus" size="22" />
            </VAvatar>
            <div>
              <p class="text-body-2 text-medium-emphasis mb-0">On Leave Today</p>
              <h5 class="text-h5">{{ stats.on_leave_today }}</h5>
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" lg="3">
        <VCard elevation="0" border>
          <VCardText class="d-flex align-center gap-3 pa-4">
            <VAvatar color="error" variant="tonal" size="46" rounded>
              <VIcon icon="tabler-bell" size="22" />
            </VAvatar>
            <div>
              <p class="text-body-2 text-medium-emphasis mb-0">Pending Approvals</p>
              <h5 class="text-h5">{{ stats.pending_approvals }}</h5>
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" lg="3">
        <VCard elevation="0" border>
          <VCardText class="d-flex align-center gap-3 pa-4">
            <VAvatar color="info" variant="tonal" size="46" rounded>
              <VIcon icon="tabler-calendar-event" size="22" />
            </VAvatar>
            <div>
              <p class="text-body-2 text-medium-emphasis mb-0">Upcoming (7d)</p>
              <h5 class="text-h5">{{ stats.upcoming_this_week }}</h5>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow>
      <!-- Left Column -->
      <VCol cols="12" lg="7">

        <!-- On Leave Today -->
        <VCard elevation="0" border class="mb-5">
          <VCardTitle class="pa-4 pb-2 d-flex align-center justify-space-between text-body-1 font-weight-semibold">
            On Leave Today
            <VChip v-if="teamOnLeaveToday.length" color="warning" size="x-small" variant="tonal">{{ teamOnLeaveToday.length }}</VChip>
          </VCardTitle>
          <VDivider />
          <VList v-if="teamOnLeaveToday.length" density="compact">
            <VListItem
              v-for="m in teamOnLeaveToday"
              :key="m.id"
              :subtitle="`Returns: ${m.return_date ?? 'TBD'}`"
            >
              <template #title>
                <span class="text-body-2 font-weight-medium">{{ m.name }}</span>
                <span class="text-caption text-medium-emphasis ms-2">{{ m.leave_type }}</span>
              </template>
              <template #prepend>
                <VAvatar color="warning" variant="tonal" size="34" rounded class="me-2">
                  <VIcon icon="tabler-user" size="16" />
                </VAvatar>
              </template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-center py-6 text-medium-emphasis">
            <p class="text-body-2">No team members on leave today.</p>
          </VCardText>
        </VCard>

        <!-- Pending Approvals -->
        <VCard elevation="0" border class="mb-5">
          <VCardTitle class="pa-4 pb-2 d-flex align-center justify-space-between text-body-1 font-weight-semibold">
            Pending Approvals
            <VBtn variant="text" size="small" @click="router.visit(route('hr.leave.approvals'))">View All</VBtn>
          </VCardTitle>
          <VDivider />
          <VList v-if="pendingApprovals.length">
            <VListItem
              v-for="req in pendingApprovals.slice(0, 5)"
              :key="req.id"
              :subtitle="`${req.start_date} → ${req.end_date} · ${req.working_days}d`"
              class="cursor-pointer"
              @click="router.visit(route('hr.leave.approvals'))"
            >
              <template #title>
                <span class="text-body-2 font-weight-medium">{{ req.employee_name }}</span>
                <span class="text-caption text-medium-emphasis ms-2">{{ req.leave_type }}</span>
              </template>
              <template #append>
                <VChip color="warning" size="x-small" variant="tonal">{{ formatDate(req.submitted_at) }}</VChip>
              </template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-center py-6 text-medium-emphasis">
            <p class="text-body-2">No pending approvals.</p>
          </VCardText>
        </VCard>

        <!-- Upcoming Leave -->
        <VCard elevation="0" border>
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Upcoming Leave (Next 7 Days)</VCardTitle>
          <VDivider />
          <VList v-if="upcomingLeave.length" density="compact">
            <VListItem
              v-for="req in upcomingLeave"
              :key="req.id"
              :subtitle="req.leave_type"
            >
              <template #title>
                <span class="text-body-2 font-weight-medium">{{ req.employee_name }}</span>
              </template>
              <template #append>
                <span class="text-caption text-medium-emphasis">{{ req.start_date }} → {{ req.end_date }}</span>
              </template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-center py-6 text-medium-emphasis">
            <p class="text-body-2">No upcoming leave in the next 7 days.</p>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Right Column -->
      <VCol cols="12" lg="5">

        <!-- Anomalies -->
        <VCard v-if="teamAnomalies.length" elevation="0" border class="mb-5">
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold d-flex align-center gap-2">
            <VIcon icon="tabler-alert-triangle" color="warning" size="20" />
            Team Anomalies
          </VCardTitle>
          <VDivider />
          <VList density="compact">
            <VListItem
              v-for="(a, i) in teamAnomalies"
              :key="i"
              :subtitle="a.message"
            >
              <template #title>
                <span class="text-body-2 font-weight-medium">{{ a.employee_name }}</span>
              </template>
              <template #append>
                <VChip :color="severityColor(a.severity)" size="x-small" variant="tonal">{{ a.type }}</VChip>
              </template>
            </VListItem>
          </VList>
        </VCard>

        <!-- Team Balances Snapshot -->
        <VCard elevation="0" border>
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Team Balance Snapshot</VCardTitle>
          <VDivider />
          <VList v-if="teamBalances.length" density="compact">
            <VListItem
              v-for="(b, i) in teamBalances.slice(0, 8)"
              :key="i"
              :subtitle="b.leave_type"
            >
              <template #title>
                <span class="text-body-2 font-weight-medium">{{ b.employee_name }}</span>
              </template>
              <template #append>
                <VChip
                  :color="(b.balance_days ?? 0) < 0 ? 'error' : (b.balance_days ?? 0) < 3 ? 'warning' : 'success'"
                  size="x-small"
                  variant="tonal"
                >
                  {{ b.balance_days?.toFixed(1) ?? '—' }}d
                </VChip>
              </template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-center py-6 text-medium-emphasis">
            <p class="text-body-2">No team balances available.</p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

  </VContainer>
</template>
