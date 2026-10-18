<template>
  <VCard>
    <VCardTitle class="d-flex align-center pa-4">
      <span class="text-h6">Booking Calendar</span>
      <VSpacer />
      <VBtnToggle v-model="viewMode" mandatory density="compact" color="primary" class="me-3">
        <VBtn value="timeGridWeek" size="small">Week</VBtn>
        <VBtn value="dayGridMonth" size="small">Month</VBtn>
        <VBtn value="listWeek" size="small">List</VBtn>
        <VBtn value="map" size="small" prepend-icon="ri-map-pin-line">Map</VBtn>
      </VBtnToggle>
    </VCardTitle>

    <VDivider />

    <!-- FullCalendar views -->
    <div v-if="viewMode !== 'map'" class="pa-3">
      <FullCalendar :options="calendarOptions" />
    </div>

    <!-- Mapbox map view -->
    <div v-else>
      <div ref="mapContainer" style="height:500px;border-radius:0 0 4px 4px;" />
      <VList density="compact" class="pa-2">
        <VListItem
          v-for="b in activeBookings"
          :key="b.id"
          :subtitle="`${b.driver} → ${b.destination}`"
          :title="b.vehicle"
          :prepend-icon="statusIcon(b.status)"
          class="px-3"
        />
      </VList>
    </div>

    <!-- Booking detail dialog -->
    <VDialog v-model="detailDialog" max-width="480">
      <VCard v-if="selectedEvent">
        <VCardTitle>{{ selectedEvent.title }}</VCardTitle>
        <VCardText>
          <VList density="compact">
            <VListItem title="Driver" :subtitle="selectedEvent.extendedProps?.driver" />
            <VListItem title="Purpose" :subtitle="selectedEvent.extendedProps?.purpose" />
            <VListItem title="Destination" :subtitle="selectedEvent.extendedProps?.destination" />
            <VListItem title="Status">
              <template #append>
                <VChip :color="statusColor(selectedEvent.extendedProps?.status)" size="small">
                  {{ selectedEvent.extendedProps?.status }}
                </VChip>
              </template>
            </VListItem>
          </VList>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn text @click="detailDialog = false">Close</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VCard>
</template>

<script setup lang="ts">
import FullCalendar from '@fullcalendar/vue3'
import dayGridPlugin from '@fullcalendar/daygrid'
import timeGridPlugin from '@fullcalendar/timegrid'
import listPlugin from '@fullcalendar/list'
import interactionPlugin from '@fullcalendar/interaction'
import type { CalendarOptions, EventClickArg, EventDropArg, EventInput } from '@fullcalendar/core'
import axios from 'axios'

const viewMode = ref<'timeGridWeek' | 'dayGridMonth' | 'listWeek' | 'map'>('timeGridWeek')
const mapContainer = ref<HTMLElement | null>(null)
const detailDialog = ref(false)
const selectedEvent = ref<any>(null)
const events = ref<EventInput[]>([])
const activeBookings = ref<any[]>([])

let mapInstance: any = null

const statusColor = (s: string) =>
  ({ approved: 'success', pending: 'warning', in_use: 'info', completed: 'grey', cancelled: 'error' }[s] ?? 'grey')

const statusIcon = (s: string) =>
  ({ approved: 'ri-check-line', pending: 'ri-time-line', in_use: 'ri-car-line', completed: 'ri-flag-line', cancelled: 'ri-close-line' }[s] ?? 'ri-circle-line')

async function fetchEvents(start: Date, end: Date): Promise<EventInput[]> {
  const { data } = await axios.get('/fleet/bookings', {
    params: { start: start.toISOString(), end: end.toISOString(), format: 'calendar' },
  })
  const bookings = Array.isArray(data) ? data : (data.data ?? [])
  activeBookings.value = bookings.filter((b: any) => ['security_out', 'security_in'].includes(b.status))
  return bookings.map((b: any) => ({
    id: String(b.id),
    title: `${b.vehicle} — ${b.driver ?? 'Unassigned'}`,
    start: b.start_datetime,
    end: b.end_datetime,
    backgroundColor: statusColor(b.status),
    borderColor: statusColor(b.status),
    extendedProps: {
      driver: b.driver,
      purpose: b.purpose,
      destination: b.destination,
      status: b.status,
    },
  }))
}

const calendarOptions = computed<CalendarOptions>(() => ({
  plugins: [dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin],
  initialView: viewMode.value === 'map' ? 'timeGridWeek' : viewMode.value,
  headerToolbar: { left: 'prev,next today', center: 'title', right: '' },
  editable: true,
  selectable: true,
  height: 520,
  events: async (info, success) => {
    const ev = await fetchEvents(info.start, info.end)
    events.value = ev
    success(ev)
  },
  eventClick: (info: EventClickArg) => {
    selectedEvent.value = info.event
    detailDialog.value = true
  },
  eventDrop: async (info: EventDropArg) => {
    try {
      await axios.patch(`/fleet/bookings/${info.event.id}`, {
        start_datetime: info.event.startStr,
        end_datetime: info.event.endStr,
      })
    } catch {
      info.revert()
    }
  },
}))

watch(viewMode, async (val) => {
  if (val === 'map') {
    await nextTick()
    await initMap()
  }
})

async function initMap() {
  if (!mapContainer.value) return
  const mapboxgl = (await import('mapbox-gl')).default
  // Use environment key if set, else show placeholder
  mapboxgl.accessToken = (window as any).__mapboxToken ?? ''

  if (mapInstance) {
    mapInstance.remove()
    mapInstance = null
  }

  mapInstance = new mapboxgl.Map({
    container: mapContainer.value!,
    style: 'mapbox://styles/mapbox/streets-v12',
    center: [28.0473, -26.2041], // Johannesburg default
    zoom: 10,
  })

  mapInstance.addControl(new mapboxgl.NavigationControl(), 'top-right')

  // Drop a marker for each active booking that has a location
  for (const b of activeBookings.value) {
    if (b.latitude && b.longitude) {
      new mapboxgl.Marker({ color: '#1867C0' })
        .setLngLat([b.longitude, b.latitude])
        .setPopup(
          new mapboxgl.Popup({ offset: 25 }).setHTML(
            `<strong>${b.vehicle}</strong><br>${b.driver}<br>${b.destination}`
          )
        )
        .addTo(mapInstance)
    }
  }
}

onBeforeUnmount(() => {
  mapInstance?.remove()
})
</script>

<style>
@import 'mapbox-gl/dist/mapbox-gl.css';
</style>
