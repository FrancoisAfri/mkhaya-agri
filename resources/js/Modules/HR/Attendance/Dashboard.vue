<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type RecentRecord = {
  id: number
  employee: string
  status: string
  statusColor: string
  clock_in: string | null
  clock_out: string | null
  hours: number | null
  is_late: boolean
}

defineProps<{
  stats: {
    total_employees: number
    present_today: number
    absent_today: number
    late_today: number
    overtime_today: number
    monthly_hours: number
    monthly_overtime: number
    monthly_late: number
  }
  recentRecords: RecentRecord[]
  today: string
}>()
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">

    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <h4 class="text-h4">Time & Attendance</h4>
        <p class="text-medium-emphasis mb-0">{{ today }}</p>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <VBtn variant="tonal" color="default" prepend-icon="tabler-fingerprint" @click="router.visit(route('attendance.clocking.kiosk'))">Kiosk</VBtn>
        <VBtn variant="tonal" color="default" prepend-icon="tabler-list" @click="router.visit(route('attendance.records.index'))">Records</VBtn>
        <VBtn color="primary" prepend-icon="tabler-chart-bar" @click="router.visit(route('attendance.analytics'))">Analytics</VBtn>
      </div>
    </div>

    <!-- Today's Stats -->
    <VRow class="mb-5">
      <VCol cols="6" sm="4" lg="2">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VAvatar color="primary" variant="tonal" size="40" rounded class="mb-2">
              <VIcon icon="tabler-users" size="20" />
            </VAvatar>
            <h6 class="text-h6">{{ stats.total_employees }}</h6>
            <p class="text-caption text-medium-emphasis mb-0">Total</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="4" lg="2">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VAvatar color="success" variant="tonal" size="40" rounded class="mb-2">
              <VIcon icon="tabler-circle-check" size="20" />
            </VAvatar>
            <h6 class="text-h6">{{ stats.present_today }}</h6>
            <p class="text-caption text-medium-emphasis mb-0">Present</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="4" lg="2">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VAvatar color="error" variant="tonal" size="40" rounded class="mb-2">
              <VIcon icon="tabler-circle-x" size="20" />
            </VAvatar>
            <h6 class="text-h6">{{ stats.absent_today }}</h6>
            <p class="text-caption text-medium-emphasis mb-0">Absent</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="4" lg="2">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VAvatar color="warning" variant="tonal" size="40" rounded class="mb-2">
              <VIcon icon="tabler-clock-x" size="20" />
            </VAvatar>
            <h6 class="text-h6">{{ stats.late_today }}</h6>
            <p class="text-caption text-medium-emphasis mb-0">Late</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="4" lg="2">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VAvatar color="info" variant="tonal" size="40" rounded class="mb-2">
              <VIcon icon="tabler-clock-plus" size="20" />
            </VAvatar>
            <h6 class="text-h6">{{ stats.overtime_today }}</h6>
            <p class="text-caption text-medium-emphasis mb-0">Overtime</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="4" lg="2">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VAvatar color="secondary" variant="tonal" size="40" rounded class="mb-2">
              <VIcon icon="tabler-clock" size="20" />
            </VAvatar>
            <h6 class="text-h6">{{ stats.monthly_hours }}h</h6>
            <p class="text-caption text-medium-emphasis mb-0">MTD Hours</p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow>
      <!-- Today's Attendance -->
      <VCol cols="12" lg="8">
        <VCard elevation="0" border>
          <VCardTitle class="pa-4 pb-2 d-flex align-center justify-space-between text-body-1 font-weight-semibold">
            Today's Attendance
            <VBtn variant="text" size="small" @click="router.visit(route('attendance.records.index'))">View All</VBtn>
          </VCardTitle>
          <VDivider />
          <VList v-if="recentRecords.length" density="compact">
            <VListItem
              v-for="r in recentRecords"
              :key="r.id"
              :subtitle="`Clock In: ${r.clock_in ?? '—'}  ·  Out: ${r.clock_out ?? '—'}  ·  ${r.hours ? r.hours.toFixed(1) + 'h' : '—'}`"
            >
              <template #title>
                <span class="text-body-2 font-weight-medium">{{ r.employee }}</span>
                <VChip v-if="r.is_late" color="warning" size="x-small" variant="tonal" class="ms-2">Late</VChip>
              </template>
              <template #prepend>
                <VAvatar :color="r.statusColor" variant="tonal" size="34" rounded class="me-2">
                  <VIcon icon="tabler-user" size="16" />
                </VAvatar>
              </template>
              <template #append>
                <VChip :color="r.statusColor" size="x-small" variant="tonal">{{ r.status }}</VChip>
              </template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-center py-10">
            <VIcon icon="tabler-calendar-off" size="48" color="secondary" class="mb-3" />
            <p class="text-body-2 text-medium-emphasis">No attendance records for today.</p>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Quick Links -->
      <VCol cols="12" lg="4">
        <VCard elevation="0" border>
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Quick Links</VCardTitle>
          <VDivider />
          <VList density="compact">
            <VListItem prepend-icon="tabler-fingerprint" @click="router.visit(route('attendance.clocking.kiosk'))">Clocking Kiosk</VListItem>
            <VListItem prepend-icon="tabler-clock-x" @click="router.visit(route('attendance.clocking.missed'))">Missed Clockings</VListItem>
            <VListItem prepend-icon="tabler-calendar-week" @click="router.visit(route('attendance.roster.index'))">Roster</VListItem>
            <VListItem prepend-icon="tabler-heart" @click="router.visit(route('attendance.wellness.index'))">Wellness Alerts</VListItem>
            <VListItem prepend-icon="tabler-robot" @click="router.visit(route('attendance.intelligence.index'))">AI Intelligence</VListItem>
            <VListItem prepend-icon="tabler-shield-exclamation" @click="router.visit(route('attendance.discipline.index'))">Discipline Cases</VListItem>
            <VListItem prepend-icon="tabler-file-alt" @click="router.visit(route('attendance.reports.index'))">Reports</VListItem>
            <VListItem prepend-icon="tabler-cog" @click="router.visit(route('attendance.settings.index'))">Settings</VListItem>
          </VList>
        </VCard>

        <!-- Monthly Summary -->
        <VCard elevation="0" border class="mt-4">
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Month to Date</VCardTitle>
          <VDivider />
          <VCardText class="pa-4">
            <div class="d-flex justify-space-between mb-3">
              <span class="text-body-2 text-medium-emphasis">Total Hours</span>
              <span class="text-body-2 font-weight-bold">{{ stats.monthly_hours }}h</span>
            </div>
            <div class="d-flex justify-space-between mb-3">
              <span class="text-body-2 text-medium-emphasis">Overtime</span>
              <span class="text-body-2 font-weight-bold text-warning">{{ stats.monthly_overtime }}h</span>
            </div>
            <div class="d-flex justify-space-between">
              <span class="text-body-2 text-medium-emphasis">Late Arrivals</span>
              <span class="text-body-2 font-weight-bold text-error">{{ stats.monthly_late }}</span>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

  </VContainer>
</template>
