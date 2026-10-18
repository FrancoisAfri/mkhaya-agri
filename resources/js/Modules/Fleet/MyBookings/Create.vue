<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

// ── Types ──────────────────────────────────────────────────────────────────────
type DriverRecord = { id: number; user_id: number; name: string; licence_type: string; licence_expiry: string | null; is_eligible: boolean }
type Employee     = { user_id: number; name: string }
type Project      = { id: number; name: string }
type ApprovalStep = { step_number: number; step_label: string; approver_type: string }
type Vehicle = {
  id: number; registration_number: string; make: string; model: string
  year: number | null; fuel_type: string | null; vehicle_type: string
  seating_capacity: number; odometer_reading: number | null
  is_recommended: boolean; recommendation_reason: string | null; recommendation_score: number
}

const props = defineProps<{
  driver:        DriverRecord | null
  isEligible:    boolean
  isManager:     boolean
  selfDriverId:  number | null
  drivers:       DriverRecord[]
  employees:     Employee[]
  vehicleTypes:  string[]
  usageTypes:    string[]
  projects:      Project[]
  approvalSteps: ApprovalStep[]
}>()

// ── Step control ───────────────────────────────────────────────────────────────
const step = ref<1 | 2>(1)

// ── Step 1: Search ─────────────────────────────────────────────────────────────
const searchFrom        = ref('')
const searchTo          = ref('')
const searchVehicleType = ref('')
const searching         = ref(false)
const searchError       = ref('')
const availableVehicles = ref<Vehicle[]>([])
const selectedVehicle   = ref<Vehicle | null>(null)

function minFromDate() {
  return new Date().toISOString().slice(0, 16)
}
function minToDate() {
  return searchFrom.value || minFromDate()
}

async function findVehicles() {
  searchError.value = ''
  if (!searchFrom.value || !searchTo.value) {
    searchError.value = t('fleet_my_bookings_create.errors.select_dates')
    return
  }
  if (searchTo.value <= searchFrom.value) {
    searchError.value = t('fleet_my_bookings_create.errors.return_after_departure')
    return
  }
  searching.value = true
  availableVehicles.value = []
  try {
    const params = new URLSearchParams({ from: searchFrom.value, to: searchTo.value })
    if (searchVehicleType.value) params.append('vehicle_type', searchVehicleType.value)

    const res = await fetch(route('fleet.bookings.available-vehicles') + '?' + params.toString(), {
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
    availableVehicles.value = await res.json()
    if (availableVehicles.value.length === 0) {
      searchError.value = t('fleet_my_bookings_create.errors.no_vehicles')
    }
  } catch {
    searchError.value = t('fleet_my_bookings_create.errors.fetch_failed')
  } finally {
    searching.value = false
  }
}

function pickVehicle(v: Vehicle) {
  selectedVehicle.value = v
  form.value.vehicle_id     = v.id
  form.value.departure_at   = searchFrom.value
  form.value.expected_return_at = searchTo.value
  step.value = 2
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

// ── Step 2: Booking form ───────────────────────────────────────────────────────
const supportingDocFile = ref<File | null>(null)
const submitting        = ref(false)
const errors            = ref<Record<string, string>>({})

const form = ref({
  vehicle_id:          null as number | null,
  driver_id:           props.selfDriverId as number | null,
  on_behalf_of_id:     null as number | null,
  purpose:             '',
  destination:         '',
  departure_at:        '',
  expected_return_at:  '',
  usage_type:          'official',
  is_local_trip:       false,
  project:             '',
  passenger_ids:       [] as number[],
  notes:               '',
})

const maxPassengers = computed(() =>
  selectedVehicle.value ? selectedVehicle.value.seating_capacity - 1 : 50
)

const capacityWarning = computed(() => {
  if (!selectedVehicle.value) return null
  const total = form.value.passenger_ids.length + 1
  if (total > selectedVehicle.value.seating_capacity) {
    return t('fleet_my_bookings_create.errors.too_many_occupants', { total, seats: selectedVehicle.value.seating_capacity })
  }
  return null
})

const employeeItems = computed(() =>
  props.employees.map(e => ({ title: e.name, value: e.user_id }))
)

const driverItems = computed(() =>
  props.drivers.filter(d => d.is_eligible).map(d => ({ title: d.name, value: d.id }))
)

function onFileChange(e: Event) {
  const input = e.target as HTMLInputElement
  supportingDocFile.value = input.files?.[0] ?? null
}

function submit() {
  errors.value = {}
  if (!form.value.purpose)     { errors.value.purpose = t('fleet_my_bookings_create.errors.purpose_required'); return }
  if (!form.value.destination) { errors.value.destination = t('fleet_my_bookings_create.errors.destination_required'); return }
  if (!form.value.usage_type)  { errors.value.usage_type = t('fleet_my_bookings_create.errors.usage_required'); return }
  if (capacityWarning.value)   { errors.value.passenger_ids = capacityWarning.value; return }

  submitting.value = true

  const fd = new FormData()
  fd.append('vehicle_id',         String(form.value.vehicle_id))
  if (form.value.driver_id != null) fd.append('driver_id', String(form.value.driver_id))
  fd.append('purpose',            form.value.purpose)
  fd.append('destination',        form.value.destination)
  fd.append('departure_at',       form.value.departure_at)
  fd.append('expected_return_at', form.value.expected_return_at)
  fd.append('usage_type',         form.value.usage_type)
  fd.append('is_local_trip',      form.value.is_local_trip ? '1' : '0')

  if (form.value.on_behalf_of_id) fd.append('on_behalf_of_id', String(form.value.on_behalf_of_id))
  if (form.value.project)         fd.append('project', form.value.project)
  if (form.value.notes)           fd.append('notes', form.value.notes)

  form.value.passenger_ids.forEach(id => fd.append('passenger_ids[]', String(id)))
  if (supportingDocFile.value) fd.append('supporting_document', supportingDocFile.value)

  router.post(route('fleet.bookings.store'), fd as any, {
    forceFormData: true,
    onError: (errs) => { errors.value = errs; submitting.value = false },
    onSuccess: () => { submitting.value = false },
  })
}

function backToSearch() {
  step.value = 1
  selectedVehicle.value = null
  errors.value = {}
}

// ── Helpers ────────────────────────────────────────────────────────────────────
const stateColour: Record<string, string> = {
  sedan: 'blue', suv: 'teal', truck: 'orange', van: 'purple',
  motorcycle: 'red', bus: 'green', other: 'grey',
}
function fuelIcon(f: string | null) {
  return f === 'electric' ? 'tabler-bolt' : 'tabler-gas-station'
}
</script>

<template>
  <VContainer fluid class="py-6 px-4" style="max-width:1560px">

    <!-- Header -->
    <div class="d-flex align-center mb-6 gap-3">
      <VBtn icon variant="text" @click="router.visit(route('fleet.my-dashboard'))">
        <VIcon>tabler-arrow-left</VIcon>
      </VBtn>
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('fleet_my_bookings_create.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ t('fleet_my_bookings_create.step_indicator', { step, total: 2, action: step === 1 ? t('fleet_my_bookings_create.step_find') : t('fleet_my_bookings_create.step_complete') }) }}
        </p>
      </div>
    </div>

    <!-- Not eligible notice -->
    <VAlert v-if="!isEligible" type="warning" variant="tonal" class="mb-6">
      {{ t('fleet_my_bookings_create.not_eligible') }}
    </VAlert>

    <!-- ── STEP 1: Search ──────────────────────────────────────────────────── -->
    <template v-if="step === 1 && isEligible">
      <VCard elevation="0" border class="mb-6">
        <VCardTitle class="px-6 pt-5 pb-2 text-subtitle-1 font-weight-bold">
          <VIcon class="mr-2" color="primary">tabler-search</VIcon>
          {{ t('fleet_my_bookings_create.search_title') }}
        </VCardTitle>
        <VCardText class="px-6 pb-4">
          <VRow dense>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="searchFrom"
                :label="t('fleet_my_bookings_create.from_date') + ' *'"
                type="datetime-local"
                :min="minFromDate()"
                variant="outlined"
                density="comfortable"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="searchTo"
                :label="t('fleet_my_bookings_create.to_date') + ' *'"
                type="datetime-local"
                :min="minToDate()"
                variant="outlined"
                density="comfortable"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VSelect
                v-model="searchVehicleType"
                :label="t('fleet_my_bookings_create.vehicle_type_optional')"
                :items="[{title: t('fleet_my_bookings_create.any_type'), value: ''}, ...vehicleTypes.map(t => ({title: t, value: t}))]"
                variant="outlined"
                density="comfortable"
                clearable
              />
            </VCol>
            <VCol cols="12" sm="6" class="d-flex align-center">
              <VBtn
                color="primary"
                size="large"
                block
                :loading="searching"
                @click="findVehicles"
              >
                <VIcon start>tabler-car-search</VIcon>
                {{ t('fleet_my_bookings_create.find_vehicles') }}
              </VBtn>
            </VCol>
          </VRow>

          <VAlert v-if="searchError" type="error" variant="tonal" class="mt-3" dense>
            {{ searchError }}
          </VAlert>
        </VCardText>
      </VCard>

      <!-- Vehicle results -->
      <template v-if="availableVehicles.length">
        <p class="text-body-2 text-medium-emphasis mb-3">
          {{ t('fleet_my_bookings_create.vehicles_available', { count: availableVehicles.length }) }}
        </p>
        <VRow dense>
          <VCol v-for="v in availableVehicles" :key="v.id" cols="12" sm="6" md="4">
            <VCard
              elevation="0" border
              class="cursor-pointer vehicle-card"
              :class="{ 'border-primary': selectedVehicle?.id === v.id }"
              @click="pickVehicle(v)"
              hover
            >
              <VCardText class="pa-4">
                <div v-if="v.is_recommended" class="d-flex justify-end mb-1">
                  <VChip color="success" size="x-small" label>
                    <VIcon start size="12">tabler-star</VIcon>
                    {{ t('fleet_my_bookings_create.recommended') }}
                  </VChip>
                </div>

                <div class="d-flex align-center gap-2 mb-2">
                  <VIcon :color="stateColour[v.vehicle_type] ?? 'grey'" size="28">tabler-car</VIcon>
                  <div>
                    <div class="font-weight-bold text-body-1 line-clamp-1">{{ v.make }} {{ v.model }}</div>
                    <div class="text-caption text-medium-emphasis">{{ v.registration_number }} · {{ v.year ?? '—' }}</div>
                  </div>
                </div>

                <div class="d-flex flex-wrap gap-1 mb-2">
                  <VChip size="x-small" label :color="stateColour[v.vehicle_type] ?? 'grey'" variant="tonal">
                    {{ v.vehicle_type }}
                  </VChip>
                  <VChip size="x-small" label color="grey" variant="tonal">
                    <VIcon start size="12" :icon="fuelIcon(v.fuel_type)" />
                    {{ v.fuel_type ?? '—' }}
                  </VChip>
                  <VChip size="x-small" label color="blue" variant="tonal">
                    <VIcon start size="12">tabler-users</VIcon>
                    {{ v.seating_capacity }} {{ t('fleet_my_bookings_create.seats') }}
                  </VChip>
                </div>

                <div v-if="v.recommendation_reason" class="text-caption text-success">
                  <VIcon size="12" color="success">tabler-circle-check</VIcon>
                  {{ v.recommendation_reason }}
                </div>

                <div class="text-caption text-medium-emphasis mt-1">
                  {{ t('fleet_my_bookings_create.odometer') }}: {{ v.odometer_reading != null ? v.odometer_reading.toLocaleString() + ' km' : '—' }}
                </div>
              </VCardText>
              <VDivider />
              <VCardActions class="pa-3">
                <VSpacer />
                <VBtn color="primary" size="small" variant="tonal" @click.stop="pickVehicle(v)">
                  {{ t('fleet_my_bookings_create.select_vehicle') }}
                  <VIcon end>tabler-arrow-right</VIcon>
                </VBtn>
              </VCardActions>
            </VCard>
          </VCol>
        </VRow>
      </template>
    </template>

    <!-- ── STEP 2: Booking form ────────────────────────────────────────────── -->
    <template v-if="step === 2 && isEligible && selectedVehicle">

      <!-- Selected vehicle summary -->
      <VCard elevation="0" border class="mb-5 bg-primary-lighten-5">
        <VCardText class="pa-4">
          <div class="d-flex align-center justify-space-between flex-wrap gap-2">
            <div class="d-flex align-center gap-3">
              <VIcon color="primary" size="32">tabler-car</VIcon>
              <div>
                <div class="font-weight-bold text-body-1">
                  {{ selectedVehicle.make }} {{ selectedVehicle.model }} — {{ selectedVehicle.registration_number }}
                </div>
                <div class="text-caption text-medium-emphasis">
                  {{ selectedVehicle.vehicle_type }} ·
                  {{ selectedVehicle.seating_capacity }} {{ t('fleet_my_bookings_create.seats') }} ·
                  {{ selectedVehicle.fuel_type ?? '—' }} ·
                  <strong>{{ t('fleet_my_bookings_create.odometer') }}: {{ selectedVehicle.odometer_reading != null ? selectedVehicle.odometer_reading.toLocaleString() + ' km' : '—' }}</strong>
                </div>
              </div>
            </div>
            <VBtn size="small" variant="text" color="primary" @click="backToSearch">
              <VIcon start>tabler-arrows-left-right</VIcon>
              {{ t('fleet_my_bookings_create.change_vehicle') }}
            </VBtn>
          </div>
        </VCardText>
      </VCard>

      <!-- Approval info -->
      <VAlert v-if="approvalSteps.length" type="info" variant="tonal" class="mb-5" density="compact">
        <strong>{{ t('fleet_my_bookings_create.approval_steps', { count: approvalSteps.length }) }}</strong>
        ({{ approvalSteps.map(s => s.step_label || ('Step ' + s.step_number)).join(' → ') }}).
        {{ t('fleet_my_bookings_create.approvers_notified') }}
      </VAlert>

      <VCard elevation="0" border>
        <VCardTitle class="px-6 pt-5 pb-2 text-subtitle-1 font-weight-bold">
          <VIcon class="mr-2" color="primary">tabler-calendar-plus</VIcon>
          {{ t('fleet_my_bookings_create.booking_details') }}
        </VCardTitle>
        <VCardText class="px-6 pb-4">
          <VRow dense>
            <VCol cols="12" sm="6">
              <VTextField
                :model-value="form.departure_at"
                :label="t('fleet_my_bookings_create.departure')"
                type="datetime-local"
                variant="outlined"
                density="comfortable"
                readonly
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                :model-value="form.expected_return_at"
                :label="t('fleet_my_bookings_create.return_by')"
                type="datetime-local"
                variant="outlined"
                density="comfortable"
                readonly
              />
            </VCol>

            <VCol cols="12" sm="6">
              <VSelect
                v-if="isManager"
                v-model="form.driver_id"
                :label="t('fleet_my_bookings_create.driver') + ' *'"
                :items="driverItems"
                variant="outlined"
                density="comfortable"
                :error-messages="errors.driver_id"
              />
              <VTextField
                v-else
                :model-value="driver?.name ?? '—'"
                :label="t('fleet_my_bookings_create.driver')"
                variant="outlined"
                density="comfortable"
                readonly
                :hint="t('fleet_my_bookings_create.driver_hint')"
                persistent-hint
              />
            </VCol>

            <VCol v-if="isManager" cols="12" sm="6">
              <VSelect
                v-model="form.on_behalf_of_id"
                :label="t('fleet_my_bookings_create.on_behalf_of')"
                :items="employees.map(e => ({ title: e.name, value: e.user_id }))"
                variant="outlined"
                density="comfortable"
                clearable
                :hint="t('fleet_my_bookings_create.on_behalf_hint')"
                persistent-hint
              />
            </VCol>

            <VCol cols="12">
              <VTextarea
                v-model="form.purpose"
                :label="t('fleet_my_bookings_create.purpose') + ' *'"
                :placeholder="t('fleet_my_bookings_create.purpose_placeholder')"
                variant="outlined"
                density="comfortable"
                rows="2"
                auto-grow
                :error-messages="errors.purpose"
              />
            </VCol>

            <VCol cols="12" sm="6">
              <VTextField
                v-model="form.destination"
                :label="t('fleet_my_bookings_create.destination') + ' *'"
                variant="outlined"
                density="comfortable"
                :error-messages="errors.destination"
              />
            </VCol>

            <VCol cols="12" sm="6">
              <VSelect
                v-model="form.usage_type"
                :label="t('fleet_my_bookings_create.usage_type') + ' *'"
                :items="usageTypes.map(t => ({ title: t, value: t }))"
                variant="outlined"
                density="comfortable"
                :error-messages="errors.usage_type"
              />
            </VCol>

            <VCol cols="12">
              <VAutocomplete
                v-model="form.passenger_ids"
                :label="t('fleet_my_bookings_create.passengers')"
                :items="employeeItems"
                variant="outlined"
                density="comfortable"
                multiple
                chips
                closable-chips
                :max-items="maxPassengers"
                :error-messages="errors.passenger_ids || capacityWarning || undefined"
                :hint="selectedVehicle ? t('fleet_my_bookings_create.passengers_hint', { seats: selectedVehicle.seating_capacity, max: maxPassengers }) : ''"
                persistent-hint
              >
                <template #chip="{ props: chipProps, item }">
                  <VChip v-bind="chipProps" :text="item.title" size="small" />
                </template>
              </VAutocomplete>
              <VAlert
                v-if="capacityWarning"
                type="error"
                variant="tonal"
                density="compact"
                class="mt-1"
              >
                {{ capacityWarning }}
              </VAlert>
            </VCol>

            <VCol cols="12" sm="6">
              <VSwitch
                v-model="form.is_local_trip"
                :label="t('fleet_my_bookings_create.local_trip')"
                color="primary"
                density="comfortable"
                :hint="t('fleet_my_bookings_create.local_trip_hint')"
                persistent-hint
              />
            </VCol>

            <VCol cols="12" sm="6">
              <VSelect
                v-model="form.project"
                :label="t('fleet_my_bookings_create.project')"
                :items="[{title: t('fleet_my_bookings_create.none'), value: ''}, ...projects.map(p => ({title: p.name, value: p.name}))]"
                variant="outlined"
                density="comfortable"
                clearable
              />
            </VCol>

            <VCol cols="12">
              <VTextarea
                v-model="form.notes"
                :label="t('fleet_my_bookings_create.notes')"
                variant="outlined"
                density="comfortable"
                rows="2"
                auto-grow
              />
            </VCol>

            <VCol cols="12">
              <div class="text-body-2 mb-1 text-medium-emphasis">{{ t('fleet_my_bookings_create.supporting_doc') }}</div>
              <input
                type="file"
                accept=".pdf,.jpg,.jpeg,.png"
                style="display:block; width:100%"
                @change="onFileChange"
              />
              <div class="text-caption text-medium-emphasis mt-1">{{ t('fleet_my_bookings_create.doc_limits') }}</div>
            </VCol>

          </VRow>
        </VCardText>

        <VDivider />
        <VCardActions class="pa-4 gap-2">
          <VBtn variant="text" @click="backToSearch">
            <VIcon start>tabler-arrow-left</VIcon>
            {{ t('fleet_my_bookings_create.back') }}
          </VBtn>
          <VSpacer />
          <VBtn
            color="primary"
            size="large"
            :loading="submitting"
            :disabled="!!capacityWarning"
            @click="submit"
          >
            <VIcon start>tabler-send</VIcon>
            {{ t('fleet_my_bookings_create.submit_booking') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </template>

  </VContainer>
</template>

<style scoped>
.vehicle-card { transition: box-shadow .15s, border-color .15s; border: 2px solid transparent; }
.vehicle-card:hover { box-shadow: 0 4px 20px rgba(0,0,0,.12) !important; }
.border-primary { border-color: rgb(var(--v-theme-primary)) !important; }
.cursor-pointer { cursor: pointer; }
.line-clamp-1 { overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
</style>
