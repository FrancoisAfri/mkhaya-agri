<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Inspection { id: number; inspection_type: string; inspected_at: string; odometer_reading: number }
interface Booking {
  id: number; reference: string; workflow_state: string
  vehicle: string; vehicle_id: number
  driver: string; requester: string
  purpose: string; destination: string
  departure_at: string; expected_return_at: string
  is_local_trip: boolean
  start_odometer: number | null
  inspections: Inspection[]
}

const props = defineProps<{ bookings: Booking[] }>()

const snack = ref<{ show: boolean; msg: string; color: string }>({ show: false, msg: '', color: 'success' })
function toast(msg: string, color = 'success') { snack.value = { show: true, msg, color } }

// ── State ──────────────────────────────────────────────────────────────────────
const inspectDialog     = ref(false)
const turnBackDialog    = ref(false)
const selectedBooking   = ref<Booking | null>(null)
const inspectionType    = ref<'pre_trip' | 'post_trip'>('pre_trip')
const signaturePad      = ref<HTMLCanvasElement | null>(null)
let   isDrawing         = false
const turnBackReason    = ref('')

// ── Body damage areas ──────────────────────────────────────────────────────────
const damageAreas = [
  { key: 'front_bumper', label: 'Front Bumper' },
  { key: 'rear_bumper',  label: 'Rear Bumper'  },
  { key: 'left_door',    label: 'Left Door'    },
  { key: 'right_door',   label: 'Right Door'   },
  { key: 'hood',         label: 'Hood'         },
  { key: 'trunk',        label: 'Trunk / Boot' },
  { key: 'roof',         label: 'Roof'         },
  { key: 'other',        label: 'Other'        },
]

// per-area damage state: { area_key: { has_damage: bool, photo: base64|'' } }
const damageState = ref<Record<string, { has_damage: boolean; photo: string }>>(
  Object.fromEntries(damageAreas.map(a => [a.key, { has_damage: false, photo: '' }]))
)

function onAreaPhoto(key: string, e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  const reader = new FileReader()
  reader.onload = ev => { damageState.value[key].photo = ev.target?.result as string }
  reader.readAsDataURL(file)
}

// Build damage_markers array for submission
const buildDamageMarkers = () =>
  damageAreas
    .filter(a => damageState.value[a.key].has_damage)
    .map(a => ({ id: a.key, area: a.key, label: a.label, photo: damageState.value[a.key].photo }))

// ── Equipment checks ───────────────────────────────────────────────────────────
const criticalEquipment = [
  { key: 'spare_tyre_ok', label: 'Spare wheel in vehicle' },
  { key: 'fire_ext_ok',   label: 'Fire extinguisher'      },
  { key: 'lights_ok',     label: 'Lights working'         },
  { key: 'tyres_ok',      label: 'Tyre condition good'    },
  { key: 'first_aid_ok',  label: 'First aid kit present'  },
]
const otherChecks = [
  { key: 'brakes_ok',   label: 'Brakes working'  },
  { key: 'wipers_ok',   label: 'Wipers working'  },
  { key: 'fluids_ok',   label: 'Fluids OK'       },
  { key: 'interior_ok', label: 'Interior clean'  },
]

// ── Inspection form ────────────────────────────────────────────────────────────
const inspectForm = useForm({
  inspection_type:   'pre_trip' as 'pre_trip' | 'post_trip',
  odometer_reading:  '' as unknown as number,
  fuel_level:        'full'   as string,
  condition_overall: 'good'   as string,
  // Equipment — all start as 'yes'
  spare_tyre_ok: true, fire_ext_ok: true, lights_ok: true, tyres_ok: true, first_aid_ok: true,
  brakes_ok: true, wipers_ok: true, fluids_ok: true, interior_ok: true,
  notes:         '',
  damage_markers: [] as any[],
  signature:      '',
  photos:         [] as string[],
})

// Failed critical items (pre-trip only) — if 2+ fail, car should be turned back
const failedCritical = computed(() =>
  criticalEquipment.filter(c => !(inspectForm as any)[c.key]).map(c => c.label)
)
const shouldTurnBack = computed(() =>
  inspectionType.value === 'pre_trip' && failedCritical.value.length >= 2
)

// ── Dialog open/close ──────────────────────────────────────────────────────────
function openInspection(booking: Booking, type: 'pre_trip' | 'post_trip') {
  selectedBooking.value  = booking
  inspectionType.value   = type
  inspectForm.reset()
  inspectForm.inspection_type = type
  if (type === 'pre_trip') {
    inspectForm.odometer_reading = booking.start_odometer as unknown as number
  }
  damageAreas.forEach(a => { damageState.value[a.key] = { has_damage: false, photo: '' } })
  inspectDialog.value = true
  setTimeout(() => clearSignature(), 80)
}

function triggerPhotoInput(key: string) {
  document.getElementById(`photo-${key}`)?.click()
}

// ── Submit inspection ──────────────────────────────────────────────────────────
function submitInspection() {
  if (!selectedBooking.value) return

  // Capture signature
  if (signaturePad.value) {
    inspectForm.signature = signaturePad.value.toDataURL('image/png')
  }
  if (!inspectForm.signature || inspectForm.signature === signaturePad.value?.toDataURL('image/png') && isCanvasBlank()) {
    toast('Driver signature is required before submitting.', 'error')
    return
  }

  // For check-in, validate odometer > check-out odometer
  if (inspectionType.value === 'post_trip' && selectedBooking.value.start_odometer !== null) {
    if (!inspectForm.odometer_reading || Number(inspectForm.odometer_reading) <= Number(selectedBooking.value.start_odometer)) {
      toast(`Check-in odometer must be greater than check-out odometer (${selectedBooking.value.start_odometer?.toLocaleString()} km).`, 'error')
      return
    }
  }

  // Attach damage markers
  inspectForm.damage_markers = buildDamageMarkers()

  inspectForm.post(route('fleet.security-check.inspection', selectedBooking.value.id), {
    preserveScroll: true,
    onSuccess: () => {
      inspectDialog.value = false
      inspectForm.reset()
      clearSignature()
      toast(inspectionType.value === 'pre_trip'
        ? 'Vehicle checked out — Security Out.'
        : 'Vehicle checked in — awaiting fleet manager confirmation.')
    },
    onError: (e) => toast(Object.values(e)[0] as string, 'error'),
  })
}

// ── Turn back car ──────────────────────────────────────────────────────────────
function openTurnBack() { turnBackDialog.value = true }

function confirmTurnBack() {
  if (!selectedBooking.value || !turnBackReason.value.trim()) {
    toast('A reason is required to turn the car back.', 'error')
    return
  }
  router.post(route('fleet.security-check.turn-back', selectedBooking.value.id), {
    reason: turnBackReason.value,
  }, {
    onSuccess: () => {
      turnBackDialog.value = false
      inspectDialog.value  = false
      turnBackReason.value = ''
      toast('Vehicle turned back. Fleet manager notified.')
    },
    onError: () => toast('Could not turn back the vehicle.', 'error'),
  })
}

// ── Signature pad helpers ──────────────────────────────────────────────────────
function startDraw(e: MouseEvent | TouchEvent) { isDrawing = true; draw(e) }
function stopDraw()  { isDrawing = false }
function draw(e: MouseEvent | TouchEvent) {
  if (!isDrawing || !signaturePad.value) return
  const ctx  = signaturePad.value.getContext('2d')!
  const rect = signaturePad.value.getBoundingClientRect()
  const scaleX = signaturePad.value.width / rect.width
  const scaleY = signaturePad.value.height / rect.height
  const x = (('touches' in e ? e.touches[0].clientX : e.clientX) - rect.left) * scaleX
  const y = (('touches' in e ? e.touches[0].clientY : e.clientY) - rect.top)  * scaleY
  ctx.lineWidth  = 2
  ctx.strokeStyle = '#1a1a1a'
  ctx.lineCap    = 'round'
  ctx.lineJoin   = 'round'
  ctx.lineTo(x, y)
  ctx.stroke()
  ctx.beginPath()
  ctx.moveTo(x, y)
}
function clearSignature() {
  if (!signaturePad.value) return
  signaturePad.value.getContext('2d')!.clearRect(0, 0, signaturePad.value.width, signaturePad.value.height)
  inspectForm.signature = ''
}
function isCanvasBlank(): boolean {
  if (!signaturePad.value) return true
  const ctx  = signaturePad.value.getContext('2d')!
  const data = ctx.getImageData(0, 0, signaturePad.value.width, signaturePad.value.height).data
  return !data.some(v => v !== 0)
}

// ── State helpers ──────────────────────────────────────────────────────────────
const stateColor = (s: string) => ({
  collected:    'warning',
  security_out: 'primary',
  security_in:  'success',
}[s] ?? 'grey')

const stateLabel = (s: string) => ({
  collected:    'Collected — Awaiting Check-Out',
  security_out: 'Security Out — In Use',
  security_in:  'Security Checked — Awaiting Return',
}[s] ?? s)

const canCheckOut = (b: Booking) => b.workflow_state === 'collected'     && !b.inspections.some(i => i.inspection_type === 'pre_trip')
const canCheckIn  = (b: Booking) => b.workflow_state === 'security_out'  && !b.inspections.some(i => i.inspection_type === 'post_trip')

const fuelLevels    = ['empty', 'quarter', 'half', 'three_quarter', 'full']
const conditionList = ['excellent', 'good', 'fair', 'poor']
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center mb-4">
      <VIcon color="primary" class="mr-3">tabler-shield-check</VIcon>
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('fleet.security_check') }}</h1>
        <p class="text-caption text-medium-emphasis">{{ t('fleet.security_check_subtitle') }}</p>
      </div>
    </div>

    <VSnackbar v-model="snack.show" :color="snack.color" location="top right" :timeout="4000">{{ snack.msg }}</VSnackbar>

    <!-- Bookings table -->
    <VCard>
      <VTable density="comfortable">
        <thead>
          <tr>
            <th>{{ t('fleet.reference') }}</th>
            <th>{{ t('fleet.vehicle') }}</th>
            <th>{{ t('fleet.driver') }}</th>
            <th>{{ t('fleet.departure') }}</th>
            <th>{{ t('fleet.expected_return') }}</th>
            <th>{{ t('fleet.purpose') }}</th>
            <th>{{ t('fleet.status') }}</th>
            <th class="text-right">{{ t('fleet.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="b in bookings" :key="b.id">
            <td class="font-weight-medium text-primary">{{ b.reference }}</td>
            <td>{{ b.vehicle }}</td>
            <td>{{ b.driver }}</td>
            <td class="text-body-2">{{ b.departure_at ? new Date(b.departure_at).toLocaleString('en-ZA') : '—' }}</td>
            <td class="text-body-2">{{ b.expected_return_at ? new Date(b.expected_return_at).toLocaleString('en-ZA') : '—' }}</td>
            <td>
              <div class="text-body-2">{{ b.purpose }}</div>
              <div class="text-caption text-medium-emphasis">{{ b.destination }}</div>
            </td>
            <td>
              <VChip size="small" :color="stateColor(b.workflow_state)" label>{{ stateLabel(b.workflow_state) }}</VChip>
            </td>
            <td class="text-right">
              <div class="d-flex justify-end gap-1">
                <VBtn v-if="canCheckOut(b)" size="small" color="primary" variant="tonal"
                  prepend-icon="tabler-car-suv" @click="openInspection(b,'pre_trip')">
                  {{ t('fleet.check_out_inspection') }}
                </VBtn>
                <VBtn v-if="canCheckIn(b)" size="small" color="warning" variant="tonal"
                  prepend-icon="tabler-car" @click="openInspection(b,'post_trip')">
                  {{ t('fleet.check_in') }}
                </VBtn>
                <VChip v-if="b.workflow_state === 'security_in'" size="small" color="success" variant="tonal"
                  prepend-icon="tabler-circle-check">
                  Checked In — Awaiting Fleet Manager
                </VChip>
              </div>
            </td>
          </tr>
          <tr v-if="!bookings.length">
            <td colspan="8" class="text-center text-medium-emphasis pa-8">{{ t('fleet.no_bookings_for_inspection') }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <!-- ── Inspection Dialog ──────────────────────────────────────────────────── -->
    <VDialog v-model="inspectDialog" max-width="780" persistent scrollable>
      <VCard>

        <!-- Title bar -->
        <VCardTitle class="d-flex align-center pa-4 gap-3"
          :style="inspectionType==='pre_trip' ? 'background:#1565C0;color:#fff' : 'background:#2E7D32;color:#fff'">
          <VIcon>{{ inspectionType==='pre_trip' ? 'tabler-car-suv' : 'tabler-car' }}</VIcon>
          <span>{{ inspectionType==='pre_trip' ? t('fleet.check_out_inspection') : t('fleet.check_in_inspection') }}</span>
          <VSpacer />
          <div class="text-body-2 opacity-80" v-if="selectedBooking">
            {{ selectedBooking.reference }} · {{ selectedBooking.driver }}
          </div>
        </VCardTitle>

        <VCardText class="pa-0">

          <!-- Critical failure alert (pre-trip only) -->
          <VAlert v-if="shouldTurnBack" type="error" variant="tonal" class="ma-4 mb-0" density="compact"
            prepend-icon="tabler-alert-octagon">
            <strong>{{ failedCritical.length }} critical item(s) failed:</strong> {{ failedCritical.join(', ') }}.
            This vehicle should be turned back.
          </VAlert>

          <div class="pa-4 d-flex flex-column gap-5">

            <!-- ── Section 1: Readings ──────────────────────────────────────── -->
            <div>
              <div class="text-subtitle-2 font-weight-bold text-uppercase text-medium-emphasis mb-3 d-flex align-center gap-1">
                <VIcon size="16">tabler-gauge</VIcon> Readings
              </div>

              <!-- Check-out odometer reference (post-trip only) -->
              <VAlert v-if="inspectionType === 'post_trip' && selectedBooking?.start_odometer"
                type="info" variant="tonal" density="compact" class="mb-3"
                icon="tabler-counter">
                <strong>Check-Out Odometer:</strong> {{ selectedBooking.start_odometer?.toLocaleString() }} km
                — Enter the current reading below (must be higher than this value).
              </VAlert>

              <VRow dense>
                <VCol cols="12" sm="4">
                  <VTextField v-model.number="inspectForm.odometer_reading"
                    :label="inspectionType === 'pre_trip' ? 'Odometer at Check-Out (km)' : 'Odometer at Check-In (km) *'"
                    type="number" variant="outlined" density="comfortable"
                    prepend-inner-icon="tabler-counter"
                    :readonly="inspectionType === 'pre_trip'"
                    :hint="inspectionType === 'pre_trip' ? 'Recorded at collection — cannot be changed here' : ''"
                    persistent-hint
                    :error-messages="inspectForm.errors.odometer_reading" />
                </VCol>
                <VCol cols="12" sm="4">
                  <VSelect v-model="inspectForm.fuel_level" :items="fuelLevels" label="Fuel Level"
                    variant="outlined" density="comfortable" prepend-inner-icon="tabler-gas-station" />
                </VCol>
                <VCol cols="12" sm="4">
                  <VSelect v-model="inspectForm.condition_overall" :items="conditionList" label="Overall Condition"
                    variant="outlined" density="comfortable" prepend-inner-icon="tabler-car-garage" />
                </VCol>
              </VRow>
            </div>

            <VDivider />

            <!-- ── Section 2: Body Damage ───────────────────────────────────── -->
            <div>
              <div class="text-subtitle-2 font-weight-bold text-uppercase text-medium-emphasis mb-3 d-flex align-center gap-1">
                <VIcon size="16">tabler-car-garage</VIcon> Body Damage
                <VChip size="x-small" color="info" class="ml-2">Check each panel</VChip>
              </div>
              <VRow dense>
                <VCol v-for="area in damageAreas" :key="area.key" cols="12" sm="6">
                  <VCard variant="outlined" class="pa-3" :color="damageState[area.key].has_damage ? 'error' : undefined">
                    <div class="d-flex align-center justify-space-between mb-2">
                      <span class="text-body-2 font-weight-medium">{{ area.label }}</span>
                      <VSwitch v-model="damageState[area.key].has_damage" :label="damageState[area.key].has_damage ? 'Damage found' : 'No damage'"
                        color="error" density="compact" hide-details inset />
                    </div>
                    <VExpandTransition>
                      <div v-if="damageState[area.key].has_damage">
                        <input type="file" accept="image/*" :id="`photo-${area.key}`"
                          class="d-none" @change="onAreaPhoto(area.key, $event)" />
                        <VBtn size="x-small" variant="tonal" color="warning" prepend-icon="tabler-camera"
                          @click="triggerPhotoInput(area.key)">
                          Attach photo
                        </VBtn>
                        <VImg v-if="damageState[area.key].photo" :src="damageState[area.key].photo"
                          height="60" width="80" cover class="mt-2" />
                      </div>
                    </VExpandTransition>
                  </VCard>
                </VCol>
              </VRow>
            </div>

            <VDivider />

            <!-- ── Section 3: Equipment Checks ─────────────────────────────── -->
            <div>
              <div class="text-subtitle-2 font-weight-bold text-uppercase text-medium-emphasis mb-3 d-flex align-center gap-1">
                <VIcon size="16">tabler-clipboard-check</VIcon> Vehicle Equipment &amp; Safety
              </div>

              <!-- Critical items -->
              <div class="text-caption text-error font-weight-bold mb-2">CRITICAL ITEMS</div>
              <VRow dense class="mb-3">
                <VCol v-for="item in criticalEquipment" :key="item.key" cols="12" sm="6">
                  <VCard variant="outlined" class="pa-2"
                    :color="!(inspectForm as any)[item.key] ? 'error' : 'success'">
                    <div class="d-flex align-center justify-space-between">
                      <span class="text-body-2">{{ item.label }}</span>
                      <div class="d-flex gap-1">
                        <VBtn size="x-small" :variant="(inspectForm as any)[item.key] ? 'flat' : 'tonal'"
                          color="success" @click="(inspectForm as any)[item.key] = true">Yes</VBtn>
                        <VBtn size="x-small" :variant="!(inspectForm as any)[item.key] ? 'flat' : 'tonal'"
                          color="error" @click="(inspectForm as any)[item.key] = false">No</VBtn>
                      </div>
                    </div>
                  </VCard>
                </VCol>
              </VRow>

              <!-- Other checks -->
              <div class="text-caption text-medium-emphasis font-weight-bold mb-2">OTHER CHECKS</div>
              <VRow dense>
                <VCol v-for="item in otherChecks" :key="item.key" cols="12" sm="6">
                  <div class="d-flex align-center justify-space-between pa-2 rounded" style="border:1px solid #e0e0e0">
                    <span class="text-body-2">{{ item.label }}</span>
                    <div class="d-flex gap-1">
                      <VBtn size="x-small" :variant="(inspectForm as any)[item.key] ? 'flat' : 'tonal'"
                        color="success" @click="(inspectForm as any)[item.key] = true">Yes</VBtn>
                      <VBtn size="x-small" :variant="!(inspectForm as any)[item.key] ? 'flat' : 'tonal'"
                        color="error" @click="(inspectForm as any)[item.key] = false">No</VBtn>
                    </div>
                  </div>
                </VCol>
              </VRow>
            </div>

            <VDivider />

            <!-- ── Section 4: Notes ─────────────────────────────────────────── -->
            <VTextarea v-model="inspectForm.notes" label="Notes / Remarks" variant="outlined"
              density="comfortable" rows="2" auto-grow prepend-inner-icon="tabler-note" />

            <VDivider />

            <!-- ── Section 5: Driver Signature (required) ──────────────────── -->
            <div>
              <div class="text-subtitle-2 font-weight-bold text-uppercase text-medium-emphasis mb-2 d-flex align-center gap-1">
                <VIcon size="16" color="error">tabler-pencil</VIcon>
                Driver Signature
                <VChip size="x-small" color="error" class="ml-1">Required</VChip>
              </div>
              <div style="border:2px solid #bdbdbd;border-radius:8px;background:#fafafa;position:relative">
                <canvas ref="signaturePad" width="700" height="180"
                  style="cursor:crosshair;display:block;width:100%;touch-action:none"
                  @mousedown="startDraw" @mousemove="draw" @mouseup="stopDraw" @mouseleave="stopDraw"
                  @touchstart.prevent="startDraw" @touchmove.prevent="draw" @touchend="stopDraw" />
                <div style="position:absolute;top:8px;left:12px;color:#bdbdbd;font-size:13px;pointer-events:none">
                  Sign here ↓
                </div>
              </div>
              <div class="d-flex align-center justify-space-between mt-1">
                <span class="text-caption text-medium-emphasis">
                  I confirm this inspection is accurate and the vehicle was received in the condition described.
                </span>
                <VBtn size="x-small" variant="text" color="error" @click="clearSignature">Clear</VBtn>
              </div>
              <VAlert v-if="inspectForm.errors.signature" type="error" density="compact" class="mt-2">
                {{ inspectForm.errors.signature }}
              </VAlert>
            </div>

          </div>
        </VCardText>

        <VDivider />

        <VCardActions class="pa-4 d-flex flex-wrap gap-2">
          <!-- Turn Back Car (pre-trip only) -->
          <VBtn v-if="inspectionType === 'pre_trip'" color="error" variant="outlined"
            prepend-icon="tabler-car-off" @click="openTurnBack">
            {{ t('fleet.turn_back') }}
          </VBtn>
          <VSpacer />
          <VBtn variant="text" @click="inspectDialog = false">{{ t('fleet.cancel') }}</VBtn>
          <VBtn :color="inspectionType==='pre_trip' ? 'primary' : 'success'" variant="flat"
            :loading="inspectForm.processing" @click="submitInspection"
            :prepend-icon="inspectionType==='pre_trip' ? 'tabler-car-suv' : 'tabler-car'">
            {{ inspectionType==='pre_trip' ? t('fleet.check_out_inspection') : t('fleet.check_in_inspection') }}
          </VBtn>
        </VCardActions>

      </VCard>
    </VDialog>

    <!-- ── Turn Back Dialog ───────────────────────────────────────────────────── -->
    <VDialog v-model="turnBackDialog" max-width="460" persistent>
      <VCard>
        <VCardTitle class="pa-4 d-flex align-center gap-2">
          <VIcon color="error">tabler-car-off</VIcon> Turn Back Vehicle
        </VCardTitle>
        <VCardText>
          <VAlert type="warning" variant="tonal" density="compact" class="mb-4">
            The vehicle will be sent back to the fleet manager. The booking will not proceed.
          </VAlert>
          <VTextarea v-model="turnBackReason" label="Reason for turning back *" variant="outlined"
            density="comfortable" rows="3" placeholder="e.g. Critical safety items missing — spare tyre and fire extinguisher absent" />
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="turnBackDialog = false">{{ t('fleet.cancel') }}</VBtn>
          <VBtn color="error" variant="flat" prepend-icon="tabler-car-off" @click="confirmTurnBack">
            {{ t('fleet.confirm') }} {{ t('fleet.turn_back') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

  </div>
</template>
