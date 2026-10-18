<script setup lang="ts">
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Booking {
  id: number; reference: string; vehicle: string | null; vehicle_id: number; vehicle_uuid: string | null
  destination: string; departure_at: string; return_at: string
  workflow_state: string; project: string | null; purpose: string | null
  is_mine?: boolean; driver?: string
}

const props = defineProps<{
  myBookings:   Booking[]
  mySummary:    { pending: number; approved: number; in_use: number; ytd: number }
  weekBookings: Booking[]
  weekStart:    string
  weekEnd:      string
  fleetStats:   { total_vehicles: number; available: number; pending_approval: number; alerts: number }
}>()

const stateColor = (s: string): string => ({
  pending: 'warning', approved: 'success', collected: 'teal',
  security_out: 'primary', security_in: 'deep-purple',
  returned: 'grey', cancelled: 'error', rejected: 'error', turned_back: 'orange',
})[s] ?? 'grey'

const stateIcon = (s: string): string => ({
  pending: 'tabler-clock', approved: 'tabler-circle-check', collected: 'tabler-key',
  security_out: 'tabler-car', security_in: 'tabler-car-suv',
  returned: 'tabler-flag-2', cancelled: 'tabler-ban',
  rejected: 'tabler-circle-x', turned_back: 'tabler-arrow-back',
})[s] ?? 'tabler-circle-filled'

// Build week days Mon–Sun
const weekDays = computed(() => {
  const days: { label: string; date: string; bookings: Booking[] }[] = []
  const start = new Date(props.weekStart + 'T00:00:00')
  for (let i = 0; i < 7; i++) {
    const d = new Date(start)
    d.setDate(start.getDate() + i)
    const iso = d.toISOString().split('T')[0]
    days.push({
      label: d.toLocaleDateString('en-ZA', { weekday: 'short', day: 'numeric', month: 'short' }),
      date: iso,
      bookings: props.weekBookings.filter(b => b.departure_at?.startsWith(iso)),
    })
  }
  return days
})

const todayStr = new Date().toISOString().split('T')[0]

const upcomingBookings = computed(() => props.myBookings.filter(b => ['pending', 'approved', 'collected', 'security_out', 'security_in'].includes(b.workflow_state)))
const pastBookings     = computed(() => props.myBookings.filter(b => ['returned', 'cancelled'].includes(b.workflow_state)))
</script>

<template>
  <div class="pa-4">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-5 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('fleet.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('fleet.subtitle') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" :href="route('fleet.bookings.index')">{{ t('fleet.new_booking') }}</VBtn>
    </div>

    <!-- Quick action cards -->
    <VRow dense class="mb-4">
      <VCol cols="6" sm="4" md="2" v-for="action in [
        { label: t('fleet.new_booking'),  icon: 'tabler-calendar-plus',       color: 'primary',    href: route('fleet.bookings.index') },
        { label: t('fleet.my_bookings'), icon: 'tabler-calendar-user',    color: 'deep-purple',href: route('fleet.bookings.index') },
        { label: t('fleet.vehicles'),    icon: 'tabler-cars',        color: 'info',       href: route('fleet.vehicles.index') },
        { label: t('fleet.alerts'),      icon: 'tabler-bell-ringing',          color: 'error',      href: route('fleet.alerts') },
        { label: t('fleet.reports'),     icon: 'tabler-chart-bar',           color: 'teal',       href: route('fleet.reports.index') },
        { label: t('fleet.setup'),       icon: 'tabler-settings',                 color: 'grey',       href: route('fleet.setup.index') },
      ]" :key="action.label">
        <VCard :color="action.color" variant="tonal" class="text-center cursor-pointer" @click="router.visit(action.href)">
          <VCardText class="pa-3">
            <VIcon :icon="action.icon" size="26" class="mb-1" />
            <div class="text-caption font-weight-medium">{{ action.label }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- My summary stats -->
    <VRow dense class="mb-4">
      <VCol cols="6" sm="3" v-for="stat in [
        { label: t('fleet.my_pending'),     value: mySummary.pending,  color: 'warning', icon: 'tabler-clock' },
        { label: t('fleet.approved'),       value: mySummary.approved, color: 'success', icon: 'tabler-circle-check' },
        { label: t('fleet.in_use'),         value: mySummary.in_use,   color: 'primary', icon: 'tabler-car' },
        { label: t('fleet.completed_ytd'), value: mySummary.ytd,      color: 'info',    icon: 'tabler-flag-2' },
      ]" :key="stat.label">
        <VCard :color="stat.color" variant="tonal">
          <VCardText class="d-flex align-center ga-3 py-3">
            <VIcon :icon="stat.icon" size="32" />
            <div>
              <div class="text-h5 font-weight-bold">{{ stat.value }}</div>
              <div class="text-caption">{{ stat.label }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow>
      <!-- My active / upcoming bookings -->
      <VCol cols="12" md="6">
        <VCard>
          <VCardTitle class="d-flex align-center ga-2 pa-4 pb-2">
            <VIcon color="primary">tabler-calendar-user</VIcon>
            {{ t('fleet.my_bookings') }}
            <VSpacer />
            <VChip v-if="upcomingBookings.length" size="small" color="primary">{{ upcomingBookings.length }} {{ t('fleet.active') }}</VChip>
          </VCardTitle>
          <VDivider />
          <VList density="compact" class="pa-0">
            <template v-if="upcomingBookings.length">
              <VListItem v-for="b in upcomingBookings" :key="b.id" class="py-2"
                :href="b.vehicle_uuid ? route('fleet.vehicles.show', b.vehicle_uuid) : '#'">
                <template #prepend>
                  <VIcon :color="stateColor(b.workflow_state)">{{ stateIcon(b.workflow_state) }}</VIcon>
                </template>
                <VListItemTitle class="font-weight-medium">{{ b.reference }}</VListItemTitle>
                <VListItemSubtitle>
                  {{ b.vehicle ?? '—' }}
                  <span v-if="b.destination"> → {{ b.destination }}</span>
                </VListItemSubtitle>
                <VListItemSubtitle class="text-caption">{{ b.departure_at }}</VListItemSubtitle>
                <template #append>
                  <VChip size="x-small" :color="stateColor(b.workflow_state)" label class="text-capitalize">
                    {{ b.workflow_state.replace(/_/g,' ') }}
                  </VChip>
                </template>
              </VListItem>
            </template>
            <VListItem v-else class="text-center text-medium-emphasis pa-6">
              <VIcon size="40" class="mb-2">tabler-calendar</VIcon>
              <div>{{ t('fleet.no_active_bookings') }} <a :href="route('fleet.bookings.index')" class="text-primary">{{ t('fleet.book_a_vehicle') }}</a></div>
            </VListItem>
          </VList>

          <!-- Recent past bookings -->
          <template v-if="pastBookings.length">
            <VDivider />
            <VCardSubtitle class="pa-3 text-caption text-medium-emphasis">{{ t('fleet.recent_history') }}</VCardSubtitle>
            <VList density="compact" class="pa-0">
              <VListItem v-for="b in pastBookings.slice(0,5)" :key="b.id" class="py-1">
                <template #prepend>
                  <VIcon size="16" :color="stateColor(b.workflow_state)">{{ stateIcon(b.workflow_state) }}</VIcon>
                </template>
                <VListItemTitle class="text-body-2">{{ b.reference }} · {{ b.vehicle }}</VListItemTitle>
                <VListItemSubtitle class="text-caption">{{ b.departure_at }}</VListItemSubtitle>
                <template #append>
                  <VChip size="x-small" :color="stateColor(b.workflow_state)" variant="outlined" label>{{ b.workflow_state }}</VChip>
                </template>
              </VListItem>
            </VList>
          </template>
        </VCard>
      </VCol>

      <!-- Fleet-wide quick stats -->
      <VCol cols="12" md="6">
        <VCard class="mb-4">
          <VCardTitle class="d-flex align-center ga-2 pa-4 pb-2">
            <VIcon color="info">tabler-car</VIcon>
            {{ t('fleet.fleet_overview') }}
          </VCardTitle>
          <VDivider />
          <VRow dense class="pa-4">
            <VCol cols="6" v-for="s in [
              { label: t('fleet.total_vehicles'),   value: fleetStats.total_vehicles,   color: 'primary', icon: 'tabler-cars' },
              { label: t('fleet.available_now'),    value: fleetStats.available,        color: 'success', icon: 'tabler-circle-check' },
              { label: t('fleet.pending_approval'), value: fleetStats.pending_approval, color: 'warning', icon: 'tabler-car' },
              { label: t('fleet.active_alerts'),    value: fleetStats.alerts,           color: 'error',   icon: 'tabler-bell-ringing' },
            ]" :key="s.label">
              <VCard :color="s.color" variant="tonal" class="text-center pa-3">
                <VIcon :icon="s.icon" size="24" class="mb-1" />
                <div class="text-h6 font-weight-bold">{{ s.value }}</div>
                <div class="text-caption">{{ s.label }}</div>
              </VCard>
            </VCol>
          </VRow>
          <VCardActions class="pa-3 pt-0">
            <VBtn size="small" variant="tonal" :href="route('fleet.vehicles.index')" prepend-icon="tabler-car">{{ t('fleet.manage_vehicles') }}</VBtn>
            <VBtn v-if="fleetStats.alerts > 0" size="small" variant="tonal" color="error" :href="route('fleet.alerts')" prepend-icon="tabler-bell-ringing">{{ t('fleet.view_alerts') }}</VBtn>
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>

    <!-- Current Week Calendar -->
    <VCard class="mt-4">
      <VCardTitle class="d-flex align-center ga-2 pa-4 pb-2">
        <VIcon color="primary">tabler-calendar-week</VIcon>
        {{ t('fleet.this_weeks_bookings') }}
        <VSpacer />
        <VChip size="small" variant="outlined">{{ weekStart }} – {{ weekEnd }}</VChip>
      </VCardTitle>
      <VDivider />
      <div class="pa-4 overflow-x-auto">
        <div class="d-flex ga-2" style="min-width: 700px">
          <div v-for="day in weekDays" :key="day.date"
            class="flex-grow-1 rounded-lg pa-2"
            :style="{ minWidth: '130px', background: day.date === todayStr ? 'rgba(var(--v-theme-primary), 0.08)' : 'rgba(var(--v-theme-surface-variant), 0.3)' }">
            <div class="text-caption font-weight-bold mb-2"
              :class="day.date === todayStr ? 'text-primary' : 'text-medium-emphasis'">
              {{ day.label }}
              <VChip v-if="day.date === todayStr" size="x-small" color="primary" class="ml-1">{{ t('fleet.today') }}</VChip>
            </div>
            <div v-if="day.bookings.length" class="d-flex flex-column ga-1">
              <VChip
                v-for="b in day.bookings" :key="b.id"
                size="x-small"
                :color="b.is_mine ? stateColor(b.workflow_state) : 'grey'"
                :variant="b.is_mine ? 'flat' : 'tonal'"
                class="text-truncate"
                :title="`${b.reference} · ${b.vehicle} → ${b.destination}`"
              >
                <VIcon start size="10">tabler-car</VIcon>
                {{ b.vehicle ?? b.reference }}
              </VChip>
            </div>
            <div v-else class="text-caption text-disabled text-center py-2">—</div>
          </div>
        </div>
      </div>
    </VCard>
  </div>
</template>
