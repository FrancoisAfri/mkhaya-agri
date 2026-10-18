<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import axios from 'axios'

const { t } = useI18n()

interface Category {
  id: number; name: string; code: string
  max_amount: number | null; requires_receipt: boolean
  max_age_days: number; is_travel: boolean; km_rate: number | null
}

interface Item {
  item_date: string; description: string; unit_price: number
  quantity: number; item_type: string; receipt_required: boolean
  _file: File | null
}

interface Trip {
  trip_date: string; departure_time: string
  origin: string; destination: string
  distance_km: number | null
  duration_minutes: number | null; duration_in_traffic_minutes: number | null
  is_peak_hour: boolean; traffic_condition: string | null
  km_rate: number; base_amount: number; peak_surcharge: number; total_amount: number
  notes: string; _calculating: boolean; _error: string | null; _calculated: boolean
  _file: File | null
}

const props = defineProps<{ categories: Category[] }>()

const title       = ref('')
const categoryId  = ref<number | null>(null)
const claimDate   = ref(new Date().toISOString().slice(0, 10))
const description = ref('')
const currency    = ref('ZAR')
const submitting  = ref(false)
const titleError  = ref('')
const catError    = ref('')

const selectedCategory      = computed(() => props.categories.find(cat => cat.id === categoryId.value) ?? null)
const isTravel              = computed(() => selectedCategory.value?.is_travel ?? false)
const kmRate                = computed(() => selectedCategory.value?.km_rate ?? 0)
const requiresReceipt       = computed(() => !isTravel.value && (selectedCategory.value?.requires_receipt ?? false))
const travelRequiresReceipt = computed(() => isTravel.value && (selectedCategory.value?.requires_receipt ?? false))

watch(categoryId, () => {
  if (isTravel.value) { trips.value = []; addTrip() }
})

const blankItem = (): Item => ({
  item_date: claimDate.value, description: '', unit_price: 0,
  quantity: 1, item_type: '', receipt_required: true, _file: null,
})
const items     = ref<Item[]>([])
const newItem   = ref<Item>(blankItem())
const itemsError = ref('')

const newItemFileRef = ref<HTMLInputElement | null>(null)

function onNewItemFile(e: Event) {
  newItem.value._file = (e.target as HTMLInputElement).files?.[0] ?? null
}

const canAddItem = computed(() =>
  !!(newItem.value.description.trim() && newItem.value.unit_price > 0) &&
  (!requiresReceipt.value || newItem.value._file !== null)
)

const itemsMissingFiles = computed<Item[]>(() =>
  requiresReceipt.value ? items.value.filter(item => !item._file) : []
)

function addItem() {
  if (!canAddItem.value) return
  items.value.push({ ...newItem.value })
  newItem.value = blankItem()
  if (newItemFileRef.value) newItemFileRef.value.value = ''
}

function removeItem(i: number) { items.value.splice(i, 1) }

const itemsTotal = computed(() => items.value.reduce((s, item) => s + item.unit_price * item.quantity, 0))

const trips        = ref<Trip[]>([])
const tripFileRefs = ref<(HTMLInputElement | null)[]>([])

function setTripFileRef(i: number, el: HTMLInputElement | null) {
  tripFileRefs.value[i] = el
}
function onTripFile(e: Event, trip: Trip) {
  trip._file = (e.target as HTMLInputElement).files?.[0] ?? null
}
function clickTripFile(i: number) { tripFileRefs.value[i]?.click() }

function makeTrip(): Trip {
  return {
    trip_date: claimDate.value, departure_time: '08:00',
    origin: '', destination: '',
    distance_km: null, duration_minutes: null, duration_in_traffic_minutes: null,
    is_peak_hour: false, traffic_condition: null,
    km_rate: kmRate.value, base_amount: 0, peak_surcharge: 0, total_amount: 0,
    notes: '', _calculating: false, _error: null, _calculated: false, _file: null,
  }
}
function addTrip() { trips.value.push(makeTrip()) }
function removeTrip(i: number) { trips.value.splice(i, 1) }

const tripsMissingFiles = computed<Trip[]>(() =>
  travelRequiresReceipt.value ? trips.value.filter(trip => !trip._file) : []
)

async function calculateTrip(trip: Trip) {
  if (!trip.origin || !trip.destination) { trip._error = 'Origin and destination are required.'; return }
  trip._error = null; trip._calculating = true
  try {
    const res = await axios.post(route('claims.travel.calculate-distance'), {
      origin: trip.origin, destination: trip.destination,
      trip_date: trip.trip_date, departure_time: trip.departure_time, km_rate: kmRate.value,
    })
    const d = res.data
    trip.distance_km = d.distance_km; trip.duration_minutes = d.duration_minutes
    trip.duration_in_traffic_minutes = d.duration_in_traffic_minutes
    trip.is_peak_hour = d.is_peak_hour; trip.traffic_condition = d.traffic_condition
    trip.km_rate = d.km_rate; trip.base_amount = d.base_amount
    trip.peak_surcharge = d.peak_surcharge; trip.total_amount = d.total_amount
    trip._calculated = true
  } catch (e: any) {
    trip._error = e?.response?.data?.error ?? e?.response?.data?.message ?? 'Calculation failed.'
  } finally { trip._calculating = false }
}

async function calculateAll() {
  for (const trip of trips.value) {
    if (!trip._calculated && trip.origin && trip.destination) await calculateTrip(trip)
  }
}

const tripsTotal         = computed(() => trips.value.reduce((s, trip) => s + trip.total_amount, 0))
const tripsTotalKm       = computed(() => trips.value.reduce((s, trip) => s + (trip.distance_km ?? 0), 0))
const tripsPeakSurcharge = computed(() => trips.value.reduce((s, trip) => s + trip.peak_surcharge, 0))
const tripsBaseAmount    = computed(() => trips.value.reduce((s, trip) => s + trip.base_amount, 0))
const allTripsCalculated = computed(() => trips.value.length > 0 && trips.value.every(trip => trip._calculated))

const grandTotal = computed(() => isTravel.value ? tripsTotal.value : itemsTotal.value)

function validate(forSubmit = false): boolean {
  let ok = true
  titleError.value = ''; catError.value = ''; itemsError.value = ''

  if (!title.value.trim()) { titleError.value = t('claims_create.claim_details.claim_title') + ' required.'; ok = false }
  if (isTravel.value && !categoryId.value) { catError.value = 'Category is required for travel claims.'; ok = false }

  if (isTravel.value) {
    if (trips.value.length === 0 || !allTripsCalculated.value) ok = false
    if (forSubmit && tripsMissingFiles.value.length > 0) ok = false
  } else {
    if (items.value.length === 0) { itemsError.value = 'Add at least one line item.'; ok = false }
    if (forSubmit && itemsMissingFiles.value.length > 0) {
      itemsError.value = `Receipt required — upload a document for: ${itemsMissingFiles.value.map(item => `"${item.description}"`).join(', ')}.`
      ok = false
    }
  }
  return ok
}

function buildTravelFormData(autoSubmit: boolean): FormData {
  const fd = new FormData()
  fd.append('title', title.value)
  fd.append('category_id', String(categoryId.value))
  fd.append('claim_date', claimDate.value)
  if (description.value) fd.append('description', description.value)
  fd.append('currency', currency.value)
  fd.append('is_travel', '1')
  fd.append('auto_submit', autoSubmit ? '1' : '0')
  trips.value.forEach((trip, i) => {
    fd.append(`trips[${i}][trip_date]`, trip.trip_date)
    fd.append(`trips[${i}][departure_time]`, trip.departure_time)
    fd.append(`trips[${i}][origin]`, trip.origin)
    fd.append(`trips[${i}][destination]`, trip.destination)
    fd.append(`trips[${i}][distance_km]`, String(trip.distance_km ?? 0))
    if (trip.duration_minutes != null) fd.append(`trips[${i}][duration_minutes]`, String(trip.duration_minutes))
    if (trip.duration_in_traffic_minutes != null) fd.append(`trips[${i}][duration_in_traffic_minutes]`, String(trip.duration_in_traffic_minutes))
    fd.append(`trips[${i}][is_peak_hour]`, trip.is_peak_hour ? '1' : '0')
    if (trip.traffic_condition) fd.append(`trips[${i}][traffic_condition]`, trip.traffic_condition)
    fd.append(`trips[${i}][km_rate]`, String(trip.km_rate))
    fd.append(`trips[${i}][base_amount]`, String(trip.base_amount))
    fd.append(`trips[${i}][peak_surcharge]`, String(trip.peak_surcharge))
    fd.append(`trips[${i}][total_amount]`, String(trip.total_amount))
    if (trip.notes) fd.append(`trips[${i}][notes]`, trip.notes)
    if (trip._file) fd.append(`trip_receipt_files[${i}]`, trip._file, trip._file.name)
  })
  return fd
}

function buildStandardFormData(autoSubmit: boolean): FormData {
  const fd = new FormData()
  fd.append('title', title.value)
  if (categoryId.value) fd.append('category_id', String(categoryId.value))
  fd.append('claim_date', claimDate.value)
  if (description.value) fd.append('description', description.value)
  fd.append('currency', currency.value)
  fd.append('is_travel', '0')
  fd.append('auto_submit', autoSubmit ? '1' : '0')
  items.value.forEach((item, i) => {
    fd.append(`items[${i}][description]`, item.description)
    fd.append(`items[${i}][unit_price]`, String(item.unit_price))
    fd.append(`items[${i}][quantity]`, String(item.quantity || 1))
    if (item.item_date) fd.append(`items[${i}][item_date]`, item.item_date)
    if (item.item_type) fd.append(`items[${i}][item_type]`, item.item_type)
    fd.append(`items[${i}][receipt_required]`, item.receipt_required ? '1' : '0')
    if (item._file) fd.append(`receipt_files[${i}]`, item._file, item._file.name)
  })
  return fd
}

function saveDraft() {
  if (!validate(false)) return
  submitting.value = true
  const payload = isTravel.value ? buildTravelFormData(false) : buildStandardFormData(false)
  router.post(route('claims.my.store'), payload as any, { onFinish: () => { submitting.value = false } })
}

function submitNow() {
  if (!validate(true)) return
  submitting.value = true
  const payload = isTravel.value ? buildTravelFormData(true) : buildStandardFormData(true)
  router.post(route('claims.my.store'), payload as any, { onFinish: () => { submitting.value = false } })
}

const fmt = (n: number) => 'R ' + n.toLocaleString('en-ZA', { minimumFractionDigits: 2 })
const trafficColor = (c: string | null) => ({ heavy: 'error', moderate: 'warning', light: 'success' })[c ?? ''] ?? 'grey'
const fmtKm = (n: number | null) => n != null ? n.toFixed(1) + ' km' : '—'
</script>

<template>
  <div class="pa-4" style="max-width:1100px; margin:0 auto">
    <div class="d-flex align-center ga-2 mb-5">
      <VBtn icon="tabler-arrow-left" variant="text" :href="route('claims.my.index')" />
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('claims_create.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('claims_create.description') }}</p>
      </div>
    </div>

    <!-- Claim Details -->
    <VCard class="mb-4 pa-4">
      <div class="text-body-1 font-weight-bold mb-3 d-flex align-center ga-2">
        <VIcon color="primary">tabler-file-text</VIcon> {{ t('claims_create.claim_details.title') }}
      </div>
      <VRow dense>
        <VCol cols="12" md="8">
          <VTextField v-model="title" :label="t('claims_create.claim_details.claim_title')" variant="outlined" density="compact"
            :error-messages="titleError" />
        </VCol>
        <VCol cols="12" md="4">
          <VTextField v-model="claimDate" :label="t('claims_create.claim_details.claim_date')" type="date" variant="outlined" density="compact" />
        </VCol>
        <VCol cols="12" md="6">
          <VSelect v-model="categoryId" :label="t('claims_create.claim_details.category')" variant="outlined" density="compact"
            :items="categories.map(cat => ({ value: cat.id, title: cat.name + (cat.is_travel ? ' 🚗' : '') }))"
            clearable :error-messages="catError" />
        </VCol>
        <VCol cols="12" md="6">
          <VSelect v-model="currency" :label="t('claims_create.claim_details.currency')" variant="outlined" density="compact"
            :items="['ZAR', 'USD', 'EUR', 'GBP']" />
        </VCol>
        <VCol cols="12">
          <VTextarea v-model="description" :label="t('claims_create.claim_details.description_purpose')" variant="outlined" density="compact" rows="2" />
        </VCol>
      </VRow>

      <VAlert v-if="isTravel" type="info" variant="tonal" density="compact" class="mt-1">
        <VIcon start>tabler-car-suv</VIcon>
        {{ t('claims_create.claim_details.travel_info', { rate: Number(kmRate).toFixed(2) }) }}
      </VAlert>

      <VAlert v-if="requiresReceipt" type="warning" variant="tonal" density="compact" class="mt-2" prepend-icon="tabler-receipt">
        {{ t('claims_create.claim_details.receipt_warning') }}
      </VAlert>
    </VCard>

    <!-- Travel Trips -->
    <VCard v-if="isTravel" class="mb-4 pa-4">
      <div class="d-flex align-center justify-space-between mb-4">
        <div class="text-body-1 font-weight-bold d-flex align-center ga-2">
          <VIcon color="info">tabler-route</VIcon> {{ t('claims_create.travel_trips.title') }}
        </div>
        <div class="d-flex ga-2">
          <VBtn size="small" variant="outlined" color="info" prepend-icon="tabler-calculator" @click="calculateAll">
            {{ t('claims_create.travel_trips.calculate_all') }}
          </VBtn>
          <VBtn size="small" color="primary" prepend-icon="tabler-plus" @click="addTrip">
            {{ t('claims_create.travel_trips.add_trip') }}
          </VBtn>
        </div>
      </div>

      <div v-if="trips.length === 0" class="text-center py-8 text-medium-emphasis">
        <VIcon size="48" class="opacity-30 mb-2">tabler-car</VIcon>
        <div class="text-caption">{{ t('claims_create.travel_trips.empty') }}</div>
      </div>

      <div v-for="(trip, i) in trips" :key="i" class="mb-3">
        <VCard variant="outlined" :color="travelRequiresReceipt && !trip._file && trip._calculated ? 'error' : trip._calculated ? 'success' : undefined" class="pa-3">
          <div class="d-flex align-center justify-space-between mb-3">
            <span class="text-caption font-weight-bold text-medium-emphasis">{{ t('claims_create.travel_trips.trip_number', { number: i + 1 }) }}</span>
            <div class="d-flex align-center ga-1">
              <VChip v-if="trip._calculated" color="success" size="x-small" variant="tonal" prepend-icon="tabler-check">
                {{ fmtKm(trip.distance_km) }}
              </VChip>
              <VChip v-if="trip.is_peak_hour" color="warning" size="x-small" variant="tonal" prepend-icon="tabler-clock-exclamation">
                {{ t('claims_create.travel_trips.peak_hour') }}
              </VChip>
              <VChip v-if="trip.traffic_condition" :color="trafficColor(trip.traffic_condition)" size="x-small" variant="tonal">
                {{ t('claims_create.travel_trips.traffic', { condition: trip.traffic_condition }) }}
              </VChip>
              <VBtn icon="tabler-trash" size="x-small" variant="text" color="error" @click="removeTrip(i)" />
            </div>
          </div>

          <VRow dense>
            <VCol cols="6" md="3">
              <VTextField v-model="trip.trip_date" :label="t('claims_create.travel_trips.trip_date')" type="date" variant="outlined" density="compact"
                hide-details @change="trip._calculated = false" />
            </VCol>
            <VCol cols="6" md="2">
              <VTextField v-model="trip.departure_time" :label="t('claims_create.travel_trips.departure_time')" type="time" variant="outlined"
                density="compact" hide-details @change="trip._calculated = false" />
            </VCol>
            <VCol cols="12" md="3">
              <VTextField v-model="trip.origin" :label="t('claims_create.travel_trips.origin')" variant="outlined" density="compact"
                hide-details :placeholder="t('claims_create.travel_trips.origin_placeholder')"
                prepend-inner-icon="tabler-map-pin" @input="trip._calculated = false" />
            </VCol>
            <VCol cols="12" md="3">
              <VTextField v-model="trip.destination" :label="t('claims_create.travel_trips.destination')" variant="outlined" density="compact"
                hide-details :placeholder="t('claims_create.travel_trips.destination_placeholder')"
                prepend-inner-icon="tabler-map-pin-check" @input="trip._calculated = false" />
            </VCol>
            <VCol cols="12" md="1" class="d-flex align-center">
              <VBtn block color="primary" variant="tonal" size="small" :loading="trip._calculating"
                :disabled="!trip.origin || !trip.destination"
                @click="calculateTrip(trip)">
                {{ t('claims_create.travel_trips.calc') }}
              </VBtn>
            </VCol>
          </VRow>

          <VAlert v-if="trip._error" type="error" variant="tonal" density="compact" class="mt-2">
            {{ trip._error }}
          </VAlert>

          <div v-if="trip._calculated" class="mt-3 d-flex align-center ga-4 flex-wrap">
            <div class="text-caption">
              <span class="text-medium-emphasis">{{ t('claims_create.travel_trips.distance') }}</span>
              <strong class="ml-1">{{ fmtKm(trip.distance_km) }}</strong>
            </div>
            <div class="text-caption">
              <span class="text-medium-emphasis">{{ t('claims_create.travel_trips.free_flow') }}</span>
              <strong class="ml-1">{{ trip.duration_minutes }}min</strong>
            </div>
            <div class="text-caption">
              <span class="text-medium-emphasis">{{ t('claims_create.travel_trips.with_traffic') }}</span>
              <strong class="ml-1">{{ trip.duration_in_traffic_minutes }}min</strong>
            </div>
            <div class="text-caption">
              <span class="text-medium-emphasis">{{ t('claims_create.travel_trips.base', { distance: fmtKm(trip.distance_km), rate: Number(trip.km_rate).toFixed(2) }) }}</span>
              <strong class="ml-1">{{ fmt(trip.base_amount) }}</strong>
            </div>
            <div v-if="trip.is_peak_hour" class="text-caption text-warning">
              <span class="text-medium-emphasis">{{ t('claims_create.travel_trips.peak_surcharge') }}</span>
              <strong class="ml-1">{{ fmt(trip.peak_surcharge) }}</strong>
            </div>
            <div class="text-caption text-success font-weight-bold">
              {{ t('claims_create.travel_trips.trip_total', { amount: fmt(trip.total_amount) }) }}
            </div>
          </div>

          <VTextField v-model="trip.notes" :label="t('claims_create.travel_trips.trip_notes')" variant="outlined" density="compact"
            class="mt-3" hide-details />

          <template v-if="travelRequiresReceipt">
            <VDivider class="my-3" />
            <div class="d-flex align-center ga-3">
              <VIcon :color="trip._file ? 'success' : 'warning'" size="18">
                {{ trip._file ? 'tabler-paperclip' : 'tabler-file-upload' }}
              </VIcon>
              <VTooltip :text="trip._file ? t('claims_create.travel_trips.change_document') : t('claims_create.travel_trips.attach_document')">
                <template #activator="{ props: tp }">
                  <VBtn
                    v-bind="tp"
                    size="small"
                    :color="trip._file ? 'success' : 'warning'"
                    variant="tonal"
                    :prepend-icon="trip._file ? 'tabler-circle-check' : 'tabler-file-upload'"
                    style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                    @click="clickTripFile(i)"
                  >{{ trip._file ? trip._file.name : t('claims_create.travel_trips.attach_document_btn') }}</VBtn>
                </template>
              </VTooltip>
              <input
                :ref="(el) => setTripFileRef(i, el as HTMLInputElement | null)"
                type="file"
                accept=".pdf,.jpg,.jpeg,.png"
                class="d-none"
                @change="onTripFile($event, trip)"
              />
              <span v-if="!trip._file" class="text-caption text-warning">{{ t('claims_create.travel_trips.document_required') }}</span>
            </div>
          </template>
        </VCard>
      </div>

      <VAlert v-if="travelRequiresReceipt && trips.length > 0" type="warning" variant="tonal" density="compact" class="mt-2 mb-2" prepend-icon="tabler-receipt">
        {{ t('claims_create.travel_trips.document_warning') }}
        <span v-if="tripsMissingFiles.length > 0">
          <strong class="text-error ml-1">{{ t('claims_create.travel_trips.trips_missing', { count: tripsMissingFiles.length }) }}</strong>
        </span>
        <span v-else class="text-success ml-1">{{ t('claims_create.travel_trips.all_documents_attached') }}</span>
      </VAlert>

      <VAlert v-if="trips.length > 0 && !allTripsCalculated" type="warning" variant="tonal" density="compact" class="mt-2">
        <VIcon start>tabler-calculator</VIcon>
        {{ t('claims_create.travel_trips.calculate_warning') }}
      </VAlert>
    </VCard>

    <!-- Standard Line Items -->
    <VCard v-if="!isTravel" class="mb-4 pa-4">
      <div class="text-body-1 font-weight-bold mb-3 d-flex align-center ga-2">
        <VIcon color="primary">tabler-list</VIcon> {{ t('claims_create.line_items.title') }}
      </div>
      <VAlert v-if="itemsError" type="error" density="compact" class="mb-3" prepend-icon="tabler-alert-circle">{{ itemsError }}</VAlert>

      <VCard variant="outlined" class="pa-3 mb-3" color="surface-variant">
        <div class="text-caption font-weight-bold mb-2 text-medium-emphasis">{{ t('claims_create.line_items.add_item') }}</div>
        <VRow dense align="center">
          <VCol cols="6" md="2">
            <VTextField v-model="newItem.item_date" :label="t('claims_create.line_items.date')" type="date" variant="outlined" density="compact" hide-details />
          </VCol>
          <VCol cols="12" md="3">
            <VTextField v-model="newItem.description" :label="t('claims_create.line_items.description')" variant="outlined" density="compact" hide-details />
          </VCol>
          <VCol cols="6" md="2">
            <VTextField v-model.number="newItem.unit_price" :label="t('claims_create.line_items.unit_price')" type="number" min="0.01" step="0.01"
              variant="outlined" density="compact" hide-details />
          </VCol>
          <VCol cols="4" md="1">
            <VTextField v-model.number="newItem.quantity" :label="t('claims_create.line_items.quantity')" type="number" min="1"
              variant="outlined" density="compact" hide-details />
          </VCol>
          <VCol cols="8" md="2">
            <VTextField v-model="newItem.item_type" :label="t('claims_create.line_items.type')" variant="outlined" density="compact"
              hide-details :placeholder="t('claims_create.line_items.type_placeholder')" />
          </VCol>
          <VCol v-if="!requiresReceipt" cols="auto">
            <VCheckbox v-model="newItem.receipt_required" :label="t('claims_create.line_items.receipt_required')" density="compact" hide-details />
          </VCol>
          <VCol cols="12" md="auto">
            <div class="d-flex align-center ga-2">
              <template v-if="requiresReceipt">
                <VTooltip :text="newItem._file ? t('claims_create.travel_trips.change_document') : t('claims_create.line_items.attach_document')">
                  <template #activator="{ props: tp }">
                    <VBtn
                      v-bind="tp"
                      size="small"
                      :color="newItem._file ? 'success' : 'warning'"
                      variant="tonal"
                      :prepend-icon="newItem._file ? 'tabler-paperclip' : 'tabler-file-upload'"
                      style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                      @click="newItemFileRef?.click()"
                    >{{ newItem._file ? newItem._file.name : t('claims_create.travel_trips.attach_document_btn') }}</VBtn>
                  </template>
                </VTooltip>
                <input
                  ref="newItemFileRef"
                  type="file"
                  accept=".pdf,.jpg,.jpeg,.png"
                  class="d-none"
                  @change="onNewItemFile"
                />
              </template>
              <VTooltip v-if="!canAddItem" location="top"
                :text="requiresReceipt && !newItem._file ? t('claims_create.line_items.attach_document') : t('claims_create.line_items.fill_fields')">
                <template #activator="{ props: tp }">
                  <span v-bind="tp">
                    <VBtn color="primary" prepend-icon="tabler-plus" size="small" disabled>{{ t('claims_create.line_items.add') }}</VBtn>
                  </span>
                </template>
              </VTooltip>
              <VBtn v-else color="primary" prepend-icon="tabler-plus" size="small" @click="addItem">{{ t('claims_create.line_items.add') }}</VBtn>
            </div>
          </VCol>
        </VRow>
      </VCard>

      <VTable v-if="items.length" density="compact">
        <thead>
          <tr>
            <th>{{ t('claims_create.line_items.table.date') }}</th>
            <th>{{ t('claims_create.line_items.table.description') }}</th>
            <th class="text-right">{{ t('claims_create.line_items.table.unit_price') }}</th>
            <th class="text-right">{{ t('claims_create.line_items.table.qty') }}</th>
            <th class="text-right">{{ t('claims_create.line_items.table.amount') }}</th>
            <th>{{ t('claims_create.line_items.table.type') }}</th>
            <th>{{ t('claims_create.line_items.table.document') }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(item, i) in items" :key="i"
            :class="{ 'bg-error-subtle': requiresReceipt && !item._file }">
            <td class="text-no-wrap text-caption text-medium-emphasis">{{ item.item_date || '—' }}</td>
            <td class="text-body-2">{{ item.description }}</td>
            <td class="text-right text-no-wrap">{{ fmt(item.unit_price) }}</td>
            <td class="text-right">{{ item.quantity }}</td>
            <td class="text-right font-weight-bold text-no-wrap">{{ fmt(item.unit_price * item.quantity) }}</td>
            <td><span class="text-caption text-medium-emphasis">{{ item.item_type || '—' }}</span></td>
            <td style="min-width:160px">
              <div v-if="item._file" class="d-flex align-center ga-1">
                <VIcon color="success" size="14">tabler-paperclip</VIcon>
                <span class="text-caption text-success" style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" :title="item._file.name">
                  {{ item._file.name }}
                </span>
              </div>
              <div v-else-if="item.receipt_required" class="d-flex align-center ga-1">
                <VIcon color="error" size="14">tabler-alert-circle</VIcon>
                <span class="text-caption text-error">{{ t('claims_create.line_items.document_required') }}</span>
              </div>
              <span v-else class="text-caption text-medium-emphasis">—</span>
            </td>
            <td>
              <VBtn icon="tabler-trash" size="x-small" variant="text" color="error" @click="removeItem(i)" />
            </td>
          </tr>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="4" class="text-right font-weight-bold pa-2">{{ t('claims_create.line_items.total') }}</td>
            <td class="text-h6 font-weight-bold text-primary pa-2 text-right">{{ fmt(itemsTotal) }}</td>
            <td colspan="3"></td>
          </tr>
        </tfoot>
      </VTable>

      <div v-else class="text-center py-6 text-medium-emphasis">
        <VIcon size="40" class="opacity-30 mb-1">tabler-list</VIcon>
        <div class="text-caption">{{ t('claims_create.line_items.empty') }}</div>
      </div>
    </VCard>

    <!-- Claim Summary -->
    <VCard class="mb-4 pa-4" variant="tonal" color="primary">
      <div class="text-body-1 font-weight-bold mb-3 d-flex align-center ga-2">
        <VIcon color="primary">tabler-clipboard-text</VIcon> {{ t('claims_create.claim_summary.title') }}
      </div>
      <VRow dense>
        <VCol v-if="isTravel" cols="6" md="3">
          <div class="text-caption text-medium-emphasis">{{ t('claims_create.claim_summary.total_trips') }}</div>
          <div class="text-h6 font-weight-bold">{{ trips.length }}</div>
        </VCol>
        <VCol v-if="isTravel" cols="6" md="3">
          <div class="text-caption text-medium-emphasis">{{ t('claims_create.claim_summary.total_distance') }}</div>
          <div class="text-h6 font-weight-bold">{{ tripsTotalKm.toFixed(1) }} km</div>
        </VCol>
        <VCol v-if="isTravel" cols="6" md="3">
          <div class="text-caption text-medium-emphasis">{{ t('claims_create.claim_summary.base_amount') }}</div>
          <div class="text-h6 font-weight-bold">{{ fmt(tripsBaseAmount) }}</div>
        </VCol>
        <VCol v-if="isTravel && tripsPeakSurcharge > 0" cols="6" md="3">
          <div class="text-caption text-warning">{{ t('claims_create.claim_summary.peak_surcharge') }}</div>
          <div class="text-h6 font-weight-bold text-warning">+ {{ fmt(tripsPeakSurcharge) }}</div>
        </VCol>
        <VCol v-if="!isTravel" cols="6" md="3">
          <div class="text-caption text-medium-emphasis">{{ t('claims_create.claim_summary.line_items') }}</div>
          <div class="text-h6 font-weight-bold">{{ items.length }}</div>
        </VCol>
        <VCol cols="6" md="3">
          <div class="text-caption text-medium-emphasis">{{ t('claims_create.claim_summary.currency_label') }}</div>
          <div class="text-h6 font-weight-bold">{{ currency }}</div>
        </VCol>
        <VCol cols="12" md="3">
          <div class="text-caption text-medium-emphasis">{{ t('claims_create.claim_summary.claim_total') }}</div>
          <div class="text-h4 font-weight-bold text-primary">{{ fmt(grandTotal) }}</div>
        </VCol>
      </VRow>

      <VDivider class="my-3" />

      <div class="text-caption text-medium-emphasis">
        <VIcon size="14" class="mr-1">tabler-info-circle</VIcon>
        {{ t('claims_create.claim_summary.info') }}
      </div>
    </VCard>

    <!-- Missing docs summary banner -->
    <VAlert
      v-if="!isTravel && requiresReceipt && itemsMissingFiles.length > 0"
      type="error"
      variant="tonal"
      class="mb-4"
      prepend-icon="tabler-receipt-off"
    >
      {{ t('claims_create.missing_docs_banner', { count: itemsMissingFiles.length, items: itemsMissingFiles.map(item => `"${item.description}"`).join(', ') }) }}
    </VAlert>

    <!-- Actions -->
    <div class="d-flex ga-3 justify-end flex-wrap">
      <VBtn variant="outlined" :href="route('claims.my.index')">{{ t('claims_create.cancel') }}</VBtn>
      <VBtn variant="outlined" color="primary" :loading="submitting"
        :disabled="isTravel ? trips.length === 0 : items.length === 0"
        @click="saveDraft">
        <VIcon start>tabler-device-floppy</VIcon> {{ t('claims_create.save_draft') }}
      </VBtn>
      <VTooltip
        v-if="(isTravel && travelRequiresReceipt && tripsMissingFiles.length > 0) || (!isTravel && requiresReceipt && itemsMissingFiles.length > 0)"
        location="top"
      >
        <template #activator="{ props: tp }">
          <span v-bind="tp">
            <VBtn color="primary" disabled prepend-icon="tabler-send">{{ t('claims_create.save_submit') }}</VBtn>
          </span>
        </template>
        <span v-if="isTravel">{{ t('claims_create.missing_docs_tooltip', { count: tripsMissingFiles.length }) }}</span>
        <span v-else>{{ t('claims_create.missing_docs_tooltip', { count: itemsMissingFiles.length }) }}</span>
      </VTooltip>
      <VBtn v-else color="primary" :loading="submitting"
        :disabled="isTravel ? !allTripsCalculated : items.length === 0"
        @click="submitNow">
        <VIcon start>tabler-send</VIcon> {{ t('claims_create.save_submit') }}
      </VBtn>
    </div>
  </div>
</template>
