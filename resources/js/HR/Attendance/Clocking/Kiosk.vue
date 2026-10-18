<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Employee = { id: number; name: string }
type Clocking = { id: number; type: string; method: string; work_type: string; time: string; location: string | null; is_missed: boolean; is_verified: boolean }
type OpenBreak = { id: number; break_type: string; start_time: string; duration: number }

const props = defineProps<{
  employee: Employee | null; today: string; clockState: 'clock_in' | 'clock_out' | null
  openBreak: OpenBreak | null; todayClockings: Clocking[]; googleMapsApiKey: string | null
}>()

const currentTime = ref(new Date().toLocaleTimeString('en-ZA', { hour: '2-digit', minute: '2-digit', second: '2-digit' }))
let clockTimer: ReturnType<typeof setInterval>
onMounted(() => { clockTimer = setInterval(() => { currentTime.value = new Date().toLocaleTimeString('en-ZA', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) }, 1000) })
onUnmounted(() => clearInterval(clockTimer))

const breakDuration = ref(props.openBreak?.duration ?? 0)
let breakTimer: ReturnType<typeof setInterval>
onMounted(() => { if (props.openBreak) { breakTimer = setInterval(() => { breakDuration.value++ }, 60000) } })
onUnmounted(() => clearInterval(breakTimer))

const workTypeDialog = ref(false); const breakDialog = ref(false); const gpsDialog = ref(false)
const alertDialog = ref(false); const alertTitle = ref(''); const alertMessage = ref('')
const alertType = ref<'error' | 'warning' | 'info' | 'success'>('error')

function showAlert(message: string, title = t('attendance_clocking_kiosk.alert_title_notice'), type: typeof alertType.value = 'error') {
  alertTitle.value = title; alertMessage.value = message; alertType.value = type; alertDialog.value = true
}

const clockForm = useForm({ employee_id: props.employee?.id ?? 0, work_type: 'office' as 'office' | 'remote' | 'hybrid', location: '', latitude: null as number | null, longitude: null as number | null, notes: '' })
const breakForm = useForm({ employee_id: props.employee?.id ?? 0, action: 'start' as 'start' | 'end', break_type: 'other' as string, notes: '', latitude: null as number | null, longitude: null as number | null, location: '' })
const gpsForm = useForm({ employee_id: props.employee?.id ?? 0, latitude: null as number | null, longitude: null as number | null, accuracy: null as number | null, address: '', work_type: 'remote' as string })

const isClockIn = computed(() => props.clockState === 'clock_in' || !props.clockState)

function performClock() {
  if (isClockIn.value) { workTypeDialog.value = true }
  else { clockForm.post('/attendance/clocking/clock-out', { onSuccess: () => { clockForm.reset() } }) }
}

function submitClockIn() { clockForm.post('/attendance/clocking/clock-in', { onSuccess: () => { workTypeDialog.value = false; clockForm.reset() } }) }

function submitWfh() {
  const form = useForm({ employee_id: props.employee?.id, latitude: null as number | null, longitude: null as number | null, location: '' })
  const doPost = () => form.post('/attendance/clocking/wfh', { onSuccess: () => { workTypeDialog.value = false } })
  if (!navigator.geolocation) { doPost(); return }
  navigator.geolocation.getCurrentPosition(
    async (pos) => {
      form.latitude = pos.coords.latitude; form.longitude = pos.coords.longitude
      if (props.googleMapsApiKey) {
        try { const res = await fetch(`https://maps.googleapis.com/maps/api/geocode/json?latlng=${pos.coords.latitude},${pos.coords.longitude}&key=${props.googleMapsApiKey}`); const data = await res.json(); if (data.status === 'OK' && data.results?.[0]) form.location = data.results[0].formatted_address } catch { /* silent */ }
      }
      doPost()
    },
    () => doPost(),
    { timeout: 5000 }
  )
}

const geocoding = ref(false); const locationHelpDialog = ref(false); const detectedBrowser = ref<'chrome' | 'firefox' | 'safari' | 'edge' | 'other'>('other')

function detectBrowser(): typeof detectedBrowser.value {
  const ua = navigator.userAgent
  if (ua.includes('Edg/')) return 'edge'; if (ua.includes('Chrome/')) return 'chrome'
  if (ua.includes('Firefox/')) return 'firefox'; if (ua.includes('Safari/')) return 'safari'; return 'other'
}

function captureGps() {
  if (!navigator.geolocation) { showAlert(t('attendance_clocking_kiosk.alert_not_supported'), t('attendance_clocking_kiosk.alert_not_supported_title'), 'warning'); return }
  navigator.geolocation.getCurrentPosition(
    async (pos) => {
      gpsForm.latitude = pos.coords.latitude; gpsForm.longitude = pos.coords.longitude; gpsForm.accuracy = pos.coords.accuracy
      if (props.googleMapsApiKey) {
        geocoding.value = true
        try { const res = await fetch(`https://maps.googleapis.com/maps/api/geocode/json?latlng=${pos.coords.latitude},${pos.coords.longitude}&key=${props.googleMapsApiKey}`); const data = await res.json(); if (data.status === 'OK' && data.results?.[0]) gpsForm.address = data.results[0].formatted_address } catch { /* */ } finally { geocoding.value = false }
      }
      gpsDialog.value = true
    },
    () => { detectedBrowser.value = detectBrowser(); locationHelpDialog.value = true },
    { timeout: 10000 }
  )
}

function submitGps() { gpsForm.post('/attendance/clocking/gps', { onSuccess: () => { gpsDialog.value = false } }) }

function openBreakDialog(action: 'start' | 'end') {
  breakForm.action = action; breakForm.break_type = 'other'; breakForm.latitude = null; breakForm.longitude = null; breakForm.location = ''
  if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
      async (pos) => {
        breakForm.latitude = pos.coords.latitude; breakForm.longitude = pos.coords.longitude
        if (props.googleMapsApiKey) {
          try { const res = await fetch(`https://maps.googleapis.com/maps/api/geocode/json?latlng=${pos.coords.latitude},${pos.coords.longitude}&key=${props.googleMapsApiKey}`); const data = await res.json(); if (data.status === 'OK' && data.results?.[0]) breakForm.location = data.results[0].formatted_address } catch { /* */ }
        }
      },
      () => { /* */ }, { timeout: 5000 }
    )
  }
  breakDialog.value = true
}

function submitBreak() { breakForm.post('/attendance/clocking/break', { onSuccess: () => { breakDialog.value = false; breakForm.reset() } }) }

const typeIcon: Record<string, string> = { clock_in: 'fas fa-sign-in-alt', clock_out: 'fas fa-sign-out-alt', break_start: 'fas fa-coffee', break_end: 'fas fa-play' }
const typeColor: Record<string, string> = { clock_in: 'success', clock_out: 'error', break_start: 'warning', break_end: 'info' }
const methodIcon: Record<string, string> = { manual: 'fas fa-hand-pointer', biometric: 'fas fa-fingerprint', facial: 'fas fa-face-smile', gps: 'fas fa-map-marker-alt', qr_code: 'fas fa-qrcode', mobile: 'fas fa-mobile-alt', web: 'fas fa-globe' }
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow justify="center">
      <VCol cols="12" md="10" lg="8">
        <VCard class="mb-4">
          <VCardText class="d-flex align-center justify-space-between py-5 px-6">
            <div>
              <div class="text-overline text-medium-emphasis">{{ t('attendance_clocking_kiosk.breadcrumb_module') }}</div>
              <h4 class="text-h4 mb-0">{{ t('attendance_clocking_kiosk.title') }}</h4>
            </div>
            <div class="text-h4 font-weight-bold font-monospace">{{ currentTime }}</div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol v-if="!employee" cols="12" md="10" lg="8">
        <VAlert type="warning" variant="tonal" :title="t('attendance_clocking_kiosk.employee_not_linked')">
          {{ t('attendance_clocking_kiosk.employee_not_linked_desc') }}
        </VAlert>
      </VCol>

      <template v-if="employee">
        <VCol cols="12" md="10" lg="8">
          <VCard>
            <VCardText class="text-center py-8">
              <div class="text-h6 mb-1 text-medium-emphasis">{{ employee.name }}</div>
              <div class="text-body-2 mb-6 text-medium-emphasis">{{ today }}</div>

              <VAlert v-if="openBreak" type="warning" variant="tonal" class="mb-6 text-start">
                <div class="d-flex align-center justify-space-between flex-wrap gap-2">
                  <div>
                    <i class="fas fa-coffee me-2"></i>
                    <strong>{{ t('attendance_clocking_kiosk.break_in_progress') }}</strong> — {{ t('attendance_clocking_kiosk.break_started_at', { type: openBreak.break_type, time: openBreak.start_time }) }}
                    <span class="ms-2">({{ t('attendance_clocking_kiosk.duration_min', { duration: breakDuration }) }})</span>
                  </div>
                  <VBtn size="small" color="warning" @click="openBreakDialog('end')">{{ t('attendance_clocking_kiosk.end_break') }}</VBtn>
                </div>
              </VAlert>

              <VBtn :color="isClockIn ? 'success' : 'error'" size="x-large" rounded="pill" class="px-12 py-6 mb-6" style="font-size:1.3rem;font-weight:700;min-width:250px" :loading="clockForm.processing" @click="performClock">
                <i :class="isClockIn ? 'fas fa-sign-in-alt' : 'fas fa-sign-out-alt'" class="me-3" style="font-size:1.5rem"></i>
                {{ isClockIn ? t('attendance_clocking_kiosk.clock_in') : t('attendance_clocking_kiosk.clock_out') }}
              </VBtn>

              <div class="d-flex justify-center flex-wrap gap-3">
                <VBtn variant="outlined" color="info" :loading="geocoding" @click="captureGps"><i class="fas fa-map-marker-alt me-2"></i>{{ t('attendance_clocking_kiosk.gps_clock') }}</VBtn>
                <VBtn variant="outlined" color="secondary" @click="submitWfh"><i class="fas fa-home me-2"></i>{{ t('attendance_clocking_kiosk.work_from_home') }}</VBtn>
                <VBtn v-if="!openBreak" variant="outlined" color="warning" :disabled="isClockIn" @click="openBreakDialog('start')"><i class="fas fa-coffee me-2"></i>{{ t('attendance_clocking_kiosk.start_break') }}</VBtn>
                <VBtn v-else variant="outlined" color="warning" @click="openBreakDialog('end')"><i class="fas fa-play me-2"></i>{{ t('attendance_clocking_kiosk.end_break') }}</VBtn>
              </div>
            </VCardText>
          </VCard>
        </VCol>

        <VCol cols="12" md="10" lg="8">
          <VCard>
            <VCardTitle class="py-4 px-5">{{ t('attendance_clocking_kiosk.todays_activity') }}</VCardTitle>
            <VCardText v-if="!todayClockings.length" class="text-center py-6 text-medium-emphasis">{{ t('attendance_clocking_kiosk.no_activity') }}</VCardText>
            <VTimeline v-else side="end" density="compact" class="px-4 pb-4">
              <VTimelineItem v-for="c in todayClockings" :key="c.id" :dot-color="typeColor[c.type] ?? 'grey'" size="small">
                <template #icon><i :class="typeIcon[c.type] ?? 'fas fa-circle'" style="font-size:12px;color:white"></i></template>
                <div class="d-flex align-center gap-3 flex-wrap">
                  <span class="font-weight-bold font-monospace">{{ c.time }}</span>
                  <VChip :color="typeColor[c.type] ?? 'grey'" size="small" variant="tonal">{{ c.type.replace('_', ' ').toUpperCase() }}</VChip>
                  <VChip size="x-small" variant="outlined"><i :class="methodIcon[c.method] ?? 'fas fa-circle'" class="me-1"></i>{{ c.method }}</VChip>
                  <VChip v-if="c.work_type !== 'office'" size="x-small" color="secondary" variant="tonal">{{ c.work_type }}</VChip>
                  <VChip v-if="c.is_missed" size="x-small" color="error" variant="tonal">{{ t('attendance_clocking_kiosk.missed') }}</VChip>
                  <VChip v-if="c.is_verified" size="x-small" color="success" variant="tonal">{{ t('attendance_clocking_kiosk.verified') }}</VChip>
                  <span v-if="c.location" class="text-caption text-medium-emphasis">{{ c.location }}</span>
                </div>
              </VTimelineItem>
            </VTimeline>
          </VCard>
        </VCol>
      </template>
    </VRow>

    <VDialog v-model="workTypeDialog" max-width="460">
      <VCard>
        <VCardTitle class="py-4 px-5">{{ t('attendance_clocking_kiosk.dialog_clock_in_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="py-4">
          <VBtnToggle v-model="clockForm.work_type" mandatory class="mb-4 w-100" divided>
            <VBtn value="office" class="flex-grow-1"><i class="fas fa-building me-2"></i>{{ t('attendance_clocking_kiosk.office') }}</VBtn>
            <VBtn value="remote" class="flex-grow-1"><i class="fas fa-home me-2"></i>{{ t('attendance_clocking_kiosk.remote') }}</VBtn>
            <VBtn value="hybrid" class="flex-grow-1"><i class="fas fa-laptop-house me-2"></i>{{ t('attendance_clocking_kiosk.hybrid') }}</VBtn>
          </VBtnToggle>
          <VTextField v-model="clockForm.location" :label="t('attendance_clocking_kiosk.field_location_office')" density="compact" class="mb-3" />
          <VTextarea v-model="clockForm.notes" :label="t('attendance_clocking_kiosk.field_notes_optional')" rows="2" density="compact" />
        </VCardText>
        <VDivider />
        <VCardActions class="px-5 py-3"><VSpacer /><VBtn variant="text" @click="workTypeDialog = false">{{ t('attendance_clocking_kiosk.cancel') }}</VBtn><VBtn color="success" :loading="clockForm.processing" @click="submitClockIn"><i class="fas fa-sign-in-alt me-2"></i>{{ t('attendance_clocking_kiosk.clock_in') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="gpsDialog" max-width="440">
      <VCard>
        <VCardTitle class="py-4 px-5">{{ t('attendance_clocking_kiosk.dialog_gps_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="py-4">
          <VAlert type="info" variant="tonal" class="mb-3" density="compact">
            {{ t('attendance_clocking_kiosk.location_captured', { lat: gpsForm.latitude?.toFixed(5) ?? '—', lng: gpsForm.longitude?.toFixed(5) ?? '—' }) }}
            <span v-if="gpsForm.accuracy"> ({{ t('attendance_clocking_kiosk.accuracy_m', { accuracy: Math.round(gpsForm.accuracy ?? 0) }) }})</span>
          </VAlert>
          <VSelect v-model="gpsForm.work_type" :items="['office','remote','hybrid'].map(v=>({title:v,value:v}))" :label="t('attendance_clocking_kiosk.field_work_type')" density="compact" class="mb-3" />
          <VTextField v-model="gpsForm.address" :label="googleMapsApiKey ? t('attendance_clocking_kiosk.field_address') : t('attendance_clocking_kiosk.field_address_optional')" density="compact" :loading="geocoding" :hint="!googleMapsApiKey ? t('attendance_clocking_kiosk.no_google_maps_hint') : ''" :persistent-hint="!googleMapsApiKey" />
        </VCardText>
        <VDivider />
        <VCardActions class="px-5 py-3"><VSpacer /><VBtn variant="text" @click="gpsDialog = false">{{ t('attendance_clocking_kiosk.cancel') }}</VBtn><VBtn color="info" :loading="gpsForm.processing" @click="submitGps"><i class="fas fa-map-marker-alt me-2"></i>{{ t('attendance_clocking_kiosk.submit_gps_clock') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="alertDialog" max-width="420">
      <VCard>
        <VCardTitle class="d-flex align-center gap-3 py-4 px-5">
          <VIcon :color="alertType" :icon="alertType === 'error' ? 'tabler-alert-circle' : alertType === 'warning' ? 'tabler-alert' : 'tabler-information'" />{{ alertTitle }}
        </VCardTitle>
        <VDivider />
        <VCardText class="py-4"><VAlert :type="alertType" variant="tonal" density="compact">{{ alertMessage }}</VAlert></VCardText>
        <VDivider />
        <VCardActions class="px-5 py-3 justify-end"><VBtn color="primary" @click="alertDialog = false">{{ t('attendance_clocking_kiosk.ok') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="locationHelpDialog" max-width="520">
      <VCard>
        <VCardTitle class="d-flex align-center gap-3 py-4 px-5">
          <div style="width:38px;height:38px;border-radius:50%;background:#fee2e2;display:flex;align-items:center;justify-content:center;flex-shrink:0"><VIcon color="error" size="20">tabler-map-marker-off</VIcon></div>
          <div><div class="text-body-1 font-weight-bold">{{ t('attendance_clocking_kiosk.location_blocked_title') }}</div><div class="text-caption text-medium-emphasis">{{ t('attendance_clocking_kiosk.location_blocked_sub') }}</div></div>
        </VCardTitle>
        <VDivider />
        <VCardText class="py-4 px-5">
          <VAlert type="error" variant="tonal" density="compact" class="mb-4">{{ t('attendance_clocking_kiosk.location_blocked_desc') }}</VAlert>

          <div v-if="detectedBrowser === 'chrome'">
            <div class="text-subtitle-2 font-weight-bold mb-2 d-flex align-center gap-2"><VIcon size="16" color="primary">tabler-google-chrome</VIcon> Google Chrome</div>
            <VList density="compact" class="pa-0">
              <VListItem class="px-0"><template #prepend><VChip size="x-small" color="primary" class="me-2">1</VChip></template><VListItemTitle class="text-body-2">{{ t('attendance_clocking_kiosk.chrome_step1') }}</VListItemTitle></VListItem>
              <VListItem class="px-0"><template #prepend><VChip size="x-small" color="primary" class="me-2">2</VChip></template><VListItemTitle class="text-body-2">{{ t('attendance_clocking_kiosk.chrome_step2') }}</VListItemTitle></VListItem>
              <VListItem class="px-0"><template #prepend><VChip size="x-small" color="primary" class="me-2">3</VChip></template><VListItemTitle class="text-body-2">{{ t('attendance_clocking_kiosk.chrome_step3') }}</VListItemTitle></VListItem>
              <VListItem class="px-0"><template #prepend><VChip size="x-small" color="primary" class="me-2">4</VChip></template><VListItemTitle class="text-body-2">{{ t('attendance_clocking_kiosk.chrome_step4') }}</VListItemTitle></VListItem>
            </VList>
          </div>

          <div v-else-if="detectedBrowser === 'edge'">
            <div class="text-subtitle-2 font-weight-bold mb-2 d-flex align-center gap-2"><VIcon size="16" color="primary">tabler-microsoft-edge</VIcon> Microsoft Edge</div>
            <VList density="compact" class="pa-0">
              <VListItem class="px-0"><template #prepend><VChip size="x-small" color="primary" class="me-2">1</VChip></template><VListItemTitle class="text-body-2">{{ t('attendance_clocking_kiosk.edge_step1') }}</VListItemTitle></VListItem>
              <VListItem class="px-0"><template #prepend><VChip size="x-small" color="primary" class="me-2">2</VChip></template><VListItemTitle class="text-body-2">{{ t('attendance_clocking_kiosk.edge_step2') }}</VListItemTitle></VListItem>
              <VListItem class="px-0"><template #prepend><VChip size="x-small" color="primary" class="me-2">3</VChip></template><VListItemTitle class="text-body-2">{{ t('attendance_clocking_kiosk.edge_step3') }}</VListItemTitle></VListItem>
              <VListItem class="px-0"><template #prepend><VChip size="x-small" color="primary" class="me-2">4</VChip></template><VListItemTitle class="text-body-2">{{ t('attendance_clocking_kiosk.edge_step4') }}</VListItemTitle></VListItem>
            </VList>
          </div>

          <div v-else-if="detectedBrowser === 'firefox'">
            <div class="text-subtitle-2 font-weight-bold mb-2 d-flex align-center gap-2"><VIcon size="16" color="warning">tabler-firefox</VIcon> Mozilla Firefox</div>
            <VList density="compact" class="pa-0">
              <VListItem class="px-0"><template #prepend><VChip size="x-small" color="warning" class="me-2">1</VChip></template><VListItemTitle class="text-body-2">{{ t('attendance_clocking_kiosk.firefox_step1') }}</VListItemTitle></VListItem>
              <VListItem class="px-0"><template #prepend><VChip size="x-small" color="warning" class="me-2">2</VChip></template><VListItemTitle class="text-body-2">{{ t('attendance_clocking_kiosk.firefox_step2') }}</VListItemTitle></VListItem>
              <VListItem class="px-0"><template #prepend><VChip size="x-small" color="warning" class="me-2">3</VChip></template><VListItemTitle class="text-body-2">{{ t('attendance_clocking_kiosk.firefox_step3') }}</VListItemTitle></VListItem>
              <VListItem class="px-0"><template #prepend><VChip size="x-small" color="warning" class="me-2">4</VChip></template><VListItemTitle class="text-body-2">{{ t('attendance_clocking_kiosk.firefox_step4') }}</VListItemTitle></VListItem>
            </VList>
          </div>

          <div v-else-if="detectedBrowser === 'safari'">
            <div class="text-subtitle-2 font-weight-bold mb-2 d-flex align-center gap-2"><VIcon size="16" color="info">tabler-apple-safari</VIcon> Safari</div>
            <VList density="compact" class="pa-0">
              <VListItem class="px-0"><template #prepend><VChip size="x-small" color="info" class="me-2">1</VChip></template><VListItemTitle class="text-body-2">{{ t('attendance_clocking_kiosk.safari_step1') }}</VListItemTitle></VListItem>
              <VListItem class="px-0"><template #prepend><VChip size="x-small" color="info" class="me-2">2</VChip></template><VListItemTitle class="text-body-2">{{ t('attendance_clocking_kiosk.safari_step2') }}</VListItemTitle></VListItem>
              <VListItem class="px-0"><template #prepend><VChip size="x-small" color="info" class="me-2">3</VChip></template><VListItemTitle class="text-body-2">{{ t('attendance_clocking_kiosk.safari_step3') }}</VListItemTitle></VListItem>
            </VList>
          </div>

          <div v-else>
            <VList density="compact" class="pa-0">
              <VListItem class="px-0"><template #prepend><VChip size="x-small" color="primary" class="me-2">1</VChip></template><VListItemTitle class="text-body-2">{{ t('attendance_clocking_kiosk.generic_step1') }}</VListItemTitle></VListItem>
              <VListItem class="px-0"><template #prepend><VChip size="x-small" color="primary" class="me-2">2</VChip></template><VListItemTitle class="text-body-2">{{ t('attendance_clocking_kiosk.generic_step2') }}</VListItemTitle></VListItem>
              <VListItem class="px-0"><template #prepend><VChip size="x-small" color="primary" class="me-2">3</VChip></template><VListItemTitle class="text-body-2">{{ t('attendance_clocking_kiosk.generic_step3') }}</VListItemTitle></VListItem>
              <VListItem class="px-0"><template #prepend><VChip size="x-small" color="primary" class="me-2">4</VChip></template><VListItemTitle class="text-body-2">{{ t('attendance_clocking_kiosk.generic_step4') }}</VListItemTitle></VListItem>
            </VList>
          </div>

          <VAlert type="info" variant="tonal" density="compact" class="mt-4" style="font-size:0.8rem"><i class="fas fa-lightbulb me-1"></i>{{ t('attendance_clocking_kiosk.location_tip') }}</VAlert>
        </VCardText>
        <VDivider />
        <VCardActions class="px-5 py-3 d-flex gap-2 justify-end">
          <VBtn variant="outlined" @click="locationHelpDialog = false">{{ t('attendance_clocking_kiosk.close') }}</VBtn>
          <VBtn color="primary" prepend-icon="tabler-reload" @click="locationHelpDialog = false; captureGps()">{{ t('attendance_clocking_kiosk.try_again') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="breakDialog" max-width="400">
      <VCard>
        <VCardTitle class="py-4 px-5">{{ breakForm.action === 'start' ? t('attendance_clocking_kiosk.dialog_break_start_title') : t('attendance_clocking_kiosk.dialog_break_end_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="py-4">
          <VSelect v-if="breakForm.action === 'start'" v-model="breakForm.break_type" :items="['lunch','tea','personal','other'].map(v=>({title:v,value:v}))" :label="t('attendance_clocking_kiosk.field_break_type')" density="compact" class="mb-3" />
          <VTextField v-model="breakForm.notes" :label="t('attendance_clocking_kiosk.field_notes_optional')" density="compact" class="mb-3" />
          <VAlert v-if="breakForm.latitude" type="success" variant="tonal" density="compact" class="mb-0" style="font-size:0.8rem"><i class="fas fa-map-marker-alt me-1"></i>{{ t('attendance_clocking_kiosk.location_captured_label') }}<span v-if="breakForm.location"> — {{ breakForm.location }}</span><span v-else> — {{ breakForm.latitude?.toFixed(5) }}, {{ breakForm.longitude?.toFixed(5) }}</span></VAlert>
          <VAlert v-else type="info" variant="tonal" density="compact" style="font-size:0.8rem"><i class="fas fa-map-marker-alt me-1"></i>{{ t('attendance_clocking_kiosk.no_location_captured') }}</VAlert>
        </VCardText>
        <VDivider />
        <VCardActions class="px-5 py-3"><VSpacer /><VBtn variant="text" @click="breakDialog = false">{{ t('attendance_clocking_kiosk.cancel') }}</VBtn><VBtn color="warning" :loading="breakForm.processing" @click="submitBreak"><i :class="breakForm.action === 'start' ? 'fas fa-coffee' : 'fas fa-play'" class="me-2"></i>{{ breakForm.action === 'start' ? t('attendance_clocking_kiosk.start_break') : t('attendance_clocking_kiosk.end_break') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
