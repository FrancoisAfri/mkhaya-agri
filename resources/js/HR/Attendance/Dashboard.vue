<script setup lang="ts">
import { ref, computed, inject, onMounted, onUnmounted } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type RecentRecord = {
  id: number; employee: string; status: string; statusColor: string
  clock_in: string | null; clock_out: string | null; hours: number; is_late: boolean
}
type Stats = {
  total_employees: number; present_today: number; absent_today: number
  late_today: number; overtime_today: number
  monthly_hours: number; monthly_overtime: number; monthly_late: number
}
type LiveClocking = {
  id: string; employee_name: string; type: 'clock_in' | 'clock_out'
  method: string; clocked_at: string; is_late: boolean
}

const props = defineProps<{ stats: Stats; recentRecords: RecentRecord[]; today: string }>()
const echo = inject<any>('echo', null)

const livePresent = ref(props.stats.present_today)
const liveLate    = ref(props.stats.late_today)
const liveFeed    = ref<LiveClocking[]>([])
const isLive      = ref(false)
let channel: any = null

onMounted(() => {
  if (!echo) return
  channel = echo.channel('attendance.live')
  channel.listen('.clocking.recorded', (data: LiveClocking) => {
    isLive.value = true
    liveFeed.value.unshift({ ...data, id: `${data.employee_name}-${data.clocked_at}` })
    if (liveFeed.value.length > 20) liveFeed.value.pop()
    if (data.type === 'clock_in') livePresent.value++
    if (data.is_late) liveLate.value++
  })
})

onUnmounted(() => { echo?.leaveChannel('attendance.live') })

const attendanceRate = computed(() => {
  if (!props.stats.total_employees) return 0
  return Math.round((livePresent.value / props.stats.total_employees) * 100)
})

const statCards = computed(() => [
  { label: t('attendance_dashboard.total_employees'), value: props.stats.total_employees, icon: 'fas fa-users', color: 'primary' },
  { label: t('attendance_dashboard.present_today'),   value: livePresent.value,            icon: 'fas fa-user-check', color: 'success' },
  { label: t('attendance_dashboard.absent_today'),    value: props.stats.absent_today,     icon: 'fas fa-user-times', color: 'error' },
  { label: t('attendance_dashboard.late_today'),      value: liveLate.value,               icon: 'fas fa-clock', color: 'warning' },
  { label: t('attendance_dashboard.overtime_today'),  value: props.stats.overtime_today,   icon: 'fas fa-hourglass-half', color: 'info' },
  { label: t('attendance_dashboard.monthly_hours'),   value: props.stats.monthly_hours,    icon: 'fas fa-calendar-check', color: 'secondary' },
])

const quickLinks = computed(() => [
  { label: t('attendance_dashboard.view_records'),   icon: 'fas fa-list',         href: '/attendance/records' },
  { label: t('attendance_dashboard.manage_shifts'),  icon: 'fas fa-layer-group',  href: '/attendance/shifts' },
  { label: t('attendance_dashboard.work_calendars'), icon: 'fas fa-calendar-alt', href: '/attendance/calendars' },
  { label: t('attendance_dashboard.statuses'),       icon: 'fas fa-tag',          href: '/attendance/statuses' },
  { label: t('attendance_dashboard.settings'),       icon: 'fas fa-cog',          href: '/attendance/settings' },
])

function formatTime(iso: string): string {
  return new Date(iso).toLocaleTimeString('en-ZA', { hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <VCol cols="12">
        <VCard class="mb-2">
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div>
              <div class="text-overline text-medium-emphasis">{{ t('attendance_dashboard.breadcrumb_module') }}</div>
              <h4 class="text-h4 mb-1">{{ t('attendance_dashboard.title') }}</h4>
              <p class="mb-0 text-medium-emphasis">{{ t('attendance_dashboard.overview_for', { date: today }) }}</p>
            </div>
            <div class="d-flex align-center gap-2">
              <VChip v-if="isLive" color="error" variant="tonal" label>
                <span class="live-dot me-1"></span>{{ t('attendance_dashboard.live_chip') }}
              </VChip>
              <VChip color="success" variant="tonal" label>
                <i class="fas fa-signal me-1"></i>{{ t('attendance_dashboard.attendance_rate', { rate: attendanceRate }) }}
              </VChip>
              <VBtn color="primary" variant="tonal" prepend-icon="mdi-plus" @click="router.visit('/attendance/records')">
                {{ t('attendance_dashboard.add_record') }}
              </VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol v-for="card in statCards" :key="card.label" cols="6" md="4" lg="2">
        <VCard :color="card.color" variant="tonal" class="text-center">
          <VCardText class="py-5">
            <i :class="card.icon" class="text-h4 mb-2 d-block"></i>
            <div class="text-h5 font-weight-bold">{{ card.value }}</div>
            <div class="text-caption">{{ card.label }}</div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard height="100%">
          <VCardTitle class="py-4 px-5 d-flex align-center gap-2">
            {{ t('attendance_dashboard.live_feed_title') }}
            <VChip v-if="isLive" color="error" size="x-small" variant="tonal">
              <span class="live-dot-sm me-1"></span>{{ t('attendance_dashboard.live_chip') }}
            </VChip>
            <VChip v-else color="secondary" size="x-small" variant="tonal">{{ t('attendance_dashboard.waiting') }}</VChip>
          </VCardTitle>
          <VList density="compact" class="px-2">
            <VListItem v-if="!liveFeed.length" class="text-center py-6 text-medium-emphasis">
              <span>{{ t('attendance_dashboard.no_clockings') }}</span>
            </VListItem>
            <VListItem v-for="entry in liveFeed" :key="entry.id" class="mb-1">
              <template #prepend>
                <VAvatar :color="entry.type === 'clock_in' ? 'success' : 'secondary'" variant="tonal" size="32">
                  <i :class="entry.type === 'clock_in' ? 'fas fa-sign-in-alt' : 'fas fa-sign-out-alt'" style="font-size:12px"></i>
                </VAvatar>
              </template>
              <VListItemTitle class="text-body-2 font-weight-medium">{{ entry.employee_name }}</VListItemTitle>
              <VListItemSubtitle class="text-caption">
                {{ entry.type === 'clock_in' ? t('attendance_dashboard.clocked_in') : t('attendance_dashboard.clocked_out') }} · {{ formatTime(entry.clocked_at) }}
                <VChip v-if="entry.is_late" color="warning" size="x-small" variant="tonal" class="ms-1">{{ t('attendance_dashboard.late_chip') }}</VChip>
              </VListItemSubtitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard height="100%">
          <VCardTitle class="py-4 px-5">{{ t('attendance_dashboard.quick_access') }}</VCardTitle>
          <VList>
            <VListItem v-for="link in quickLinks" :key="link.label" :href="link.href" class="mx-2 mb-1">
              <template #prepend><VAvatar color="primary" variant="tonal" size="36"><i :class="link.icon" style="font-size:14px"></i></VAvatar></template>
              <VListItemTitle>{{ link.label }}</VListItemTitle>
              <template #append><i class="fas fa-chevron-right text-medium-emphasis" style="font-size:12px"></i></template>
            </VListItem>
          </VList>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard height="100%">
          <VCardTitle class="py-4 px-5">{{ t('attendance_dashboard.this_month') }}</VCardTitle>
          <VCardText>
            <div class="d-flex justify-space-between align-center py-3 border-b">
              <span class="text-medium-emphasis">{{ t('attendance_dashboard.total_hours_worked') }}</span>
              <VChip color="primary" variant="tonal" size="small">{{ stats.monthly_hours }} {{ t('attendance_dashboard.hours_suffix') }}</VChip>
            </div>
            <div class="d-flex justify-space-between align-center py-3 border-b">
              <span class="text-medium-emphasis">{{ t('attendance_dashboard.overtime_hours') }}</span>
              <VChip color="info" variant="tonal" size="small">{{ stats.monthly_overtime }} {{ t('attendance_dashboard.hours_suffix') }}</VChip>
            </div>
            <div class="d-flex justify-space-between align-center py-3">
              <span class="text-medium-emphasis">{{ t('attendance_dashboard.late_arrivals') }}</span>
              <VChip color="warning" variant="tonal" size="small">{{ stats.monthly_late }}</VChip>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard height="100%">
          <VCardTitle class="py-4 px-5">{{ t('attendance_dashboard.todays_attendance') }}</VCardTitle>
          <VCardText class="text-center">
            <VProgressCircular :model-value="attendanceRate" :size="120" :width="10" color="success" class="my-4">
              <span class="text-h5 font-weight-bold">{{ attendanceRate }}%</span>
            </VProgressCircular>
            <div class="text-body-2 text-medium-emphasis">{{ t('attendance_dashboard.employees_present', { present: livePresent, total: stats.total_employees }) }}</div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12">
        <VCard>
          <VCardTitle class="py-4 px-5">{{ t('attendance_dashboard.todays_recent_records') }}</VCardTitle>
          <VTable hover density="comfortable">
            <thead>
              <tr>
                <th>{{ t('attendance_dashboard.table_employee') }}</th>
                <th>{{ t('attendance_dashboard.table_status') }}</th>
                <th>{{ t('attendance_dashboard.table_clock_in') }}</th>
                <th>{{ t('attendance_dashboard.table_clock_out') }}</th>
                <th>{{ t('attendance_dashboard.table_hours') }}</th>
                <th>{{ t('attendance_dashboard.table_late') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!recentRecords.length">
                <td colspan="6" class="text-center py-8 text-medium-emphasis">{{ t('attendance_dashboard.no_records_today') }}</td>
              </tr>
              <tr v-for="row in recentRecords" :key="row.id">
                <td class="font-weight-medium">{{ row.employee }}</td>
                <td><VChip :color="row.statusColor" size="small" label variant="tonal">{{ row.status }}</VChip></td>
                <td>{{ row.clock_in ?? '—' }}</td>
                <td>{{ row.clock_out ?? '—' }}</td>
                <td>{{ row.hours }}</td>
                <td><VChip v-if="row.is_late" color="warning" size="small" variant="tonal">{{ t('attendance_dashboard.late_chip') }}</VChip><span v-else class="text-medium-emphasis">—</span></td>
              </tr>
            </tbody>
          </VTable>
          <VCardActions class="px-5 py-3">
            <VSpacer />
            <VBtn variant="text" color="primary" @click="router.visit('/attendance/records')">
              {{ t('attendance_dashboard.view_all_records') }} <i class="fas fa-arrow-right ms-2"></i>
            </VBtn>
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>
  </VContainer>
</template>

<style scoped>
.live-dot { display: inline-block; width: 8px; height: 8px; background: rgb(var(--v-theme-error)); border-radius: 50%; animation: pulse 1.4s ease-in-out infinite; }
.live-dot-sm { display: inline-block; width: 6px; height: 6px; background: rgb(var(--v-theme-error)); border-radius: 50%; animation: pulse 1.4s ease-in-out infinite; }
@keyframes pulse { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: .4; transform: scale(1.3); } }
</style>
