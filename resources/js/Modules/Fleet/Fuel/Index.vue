<script setup lang="ts">
import { ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type FuelLog = {
  id: number; vehicle: string; vehicle_id: number
  driver: string | null; filled_at: string
  litres: number; cost_per_litre: number | null; total_cost: number
  odometer_reading: number; full_tank: boolean
  consumption_per_100km: number | null; notes: string | null
}
type Vehicle = { id: number; registration: string; make: string; model: string }

const props = defineProps<{
  fuelLogs: {
    data: FuelLog[]
    meta: { current_page: number; last_page: number; total: number; per_page: number }
    links: { prev: string | null; next: string | null }
  }
  filters: Record<string, any>
  vehicles: Vehicle[]
  summary: { total_cost: number; total_litres: number; avg_consumption: number | null; fills_this_month: number }
}>()

const search    = ref(props.filters?.search ?? '')
const vehicleId = ref(props.filters?.vehicle_id ?? '')
let timer: ReturnType<typeof setTimeout>

function applyFilters(extra: Record<string, any> = {}) {
  router.get(route('fleet.fuel.index'), {
    search: search.value || undefined,
    vehicle_id: vehicleId.value || undefined,
    ...extra,
  }, { preserveState: true, replace: true })
}

watch(search, () => { clearTimeout(timer); timer = setTimeout(() => applyFilters(), 400) })
watch(vehicleId, () => applyFilters())

// ── Server-side pagination/sort handler for VDataTableServer ──────────────
function onOptionsUpdate(options: { page: number; itemsPerPage: number; sortBy: { key: string; order: string }[] }) {
  applyFilters({
    page: options.page,
    per_page: options.itemsPerPage,
    sort: options.sortBy?.[0]?.key || undefined,
    direction: options.sortBy?.[0]?.order || undefined,
  })
}

const dialog = ref(false)
const form = useForm({
  vehicle_id: null as number | null,
  driver_id: null as number | null,
  filled_at: '',
  fuel_type: 'petrol',
  litres: null as number | null,
  cost_per_litre: null as number | null,
  total_cost: null as number | null,
  odometer_reading: null as number | null,
  station_name: '',
  station_city: '',
  full_tank: true,
  notes: '',
})

function openDialog() {
  form.reset()
  form.fuel_type = 'petrol'
  form.full_tank = true
  dialog.value = true
}

function save() {
  form.post(route('fleet.fuel.store'), {
    preserveScroll: true,
    onSuccess: () => { dialog.value = false; form.reset() },
  })
}

const deleteDialog = ref(false)
const toDelete = ref<FuelLog | null>(null)
function confirmDelete(f: FuelLog) { toDelete.value = f; deleteDialog.value = true }
function doDelete() {
  if (!toDelete.value) return
  router.delete(route('fleet.fuel.destroy', toDelete.value.id), {
    preserveScroll: true,
    onSuccess: () => { deleteDialog.value = false },
  })
}

const summaryCards = [
  { label: t('fleet_fuel_index.summary.total_spend'),     value: `R ${props.summary.total_cost?.toLocaleString()}`,           color: 'primary',      icon: 'ri-money-dollar-circle-line' },
  { label: t('fleet_fuel_index.summary.total_litres'),    value: `${props.summary.total_litres?.toLocaleString()} L`,          color: 'info',         icon: 'ri-drop-line' },
  { label: t('fleet_fuel_index.summary.avg_consumption'), value: props.summary.avg_consumption ? `${props.summary.avg_consumption} L/100km` : '—', color: 'deep-purple', icon: 'ri-dashboard-line' },
  { label: t('fleet_fuel_index.summary.fills_this_month'),value: String(props.summary.fills_this_month),                       color: 'success',      icon: 'ri-gas-station-line' },
]

const headers = [
  { title: t('fleet_fuel_index.table.date'),        key: 'filled_at' },
  { title: t('fleet_fuel_index.table.vehicle'),     key: 'vehicle' },
  { title: t('fleet_fuel_index.table.driver'),      key: 'driver' },
  { title: t('fleet_fuel_index.table.litres'),      key: 'litres' },
  { title: t('fleet_fuel_index.table.cost_per_litre'), key: 'cost_per_litre' },
  { title: t('fleet_fuel_index.table.total_cost'),  key: 'total_cost' },
  { title: t('fleet_fuel_index.table.odometer'),    key: 'odometer_reading' },
  { title: t('fleet_fuel_index.table.consumption'), key: 'consumption_per_100km' },
  { title: t('fleet_fuel_index.table.full_tank'),   key: 'full_tank' },
  { title: '', key: 'actions', sortable: false, width: '60px' },
]
</script>

<template>
  <div class="pa-4">
    <!-- Header -->
    <div class="d-flex align-center mb-5">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('fleet_fuel_index.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">{{ t('fleet_fuel_index.description') }}</p>
      </div>
      <VSpacer />
      <VBtn color="primary" prepend-icon="ri-gas-station-line" @click="openDialog">
        {{ t('fleet_fuel_index.log_fill_up') }}
      </VBtn>
    </div>

    <!-- Summary Cards -->
    <VRow class="mb-4">
      <VCol v-for="s in summaryCards" :key="s.label" cols="6" md="3">
        <VCard variant="tonal" :color="s.color">
          <VCardText class="d-flex align-center gap-3 py-3">
            <VIcon :icon="s.icon" size="28" />
            <div>
              <div class="text-h6 font-weight-bold">{{ s.value }}</div>
              <div class="text-caption">{{ s.label }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Filters -->
    <VCard class="mb-4 pa-3">
      <VRow dense>
        <VCol cols="12" md="6">
          <VTextField v-model="search" :label="t('fleet_fuel_index.search_placeholder')" prepend-inner-icon="ri-search-line" clearable density="compact" variant="outlined" hide-details />
        </VCol>
        <VCol cols="12" md="4">
          <VSelect v-model="vehicleId" :label="t('fleet_fuel_index.table.vehicle')" :items="vehicles"
            item-title="registration" item-value="id"
            clearable density="compact" variant="outlined" hide-details />
        </VCol>
      </VRow>
    </VCard>

    <!-- Table -->
    <VCard>
      <VDataTableServer
        :headers="headers"
        :items="fuelLogs.data"
        :items-length="fuelLogs.meta?.total ?? 0"
        :items-per-page="fuelLogs.meta?.per_page ?? 10"
        :page="fuelLogs.meta?.current_page ?? 1"
        density="comfortable"
        hover
        @update:options="onOptionsUpdate"
      >
        <template #item.total_cost="{ item }">R {{ item.total_cost?.toLocaleString() }}</template>
        <template #item.odometer_reading="{ item }">{{ item.odometer_reading?.toLocaleString() }}</template>
        <template #item.cost_per_litre="{ item }">{{ item.cost_per_litre ?? '—' }}</template>
        <template #item.consumption_per_100km="{ item }">
          <span v-if="item.consumption_per_100km">{{ item.consumption_per_100km }}</span>
          <span v-else class="text-medium-emphasis text-caption">—</span>
        </template>
        <template #item.driver="{ item }">{{ item.driver ?? '—' }}</template>
        <template #item.full_tank="{ item }">
          <VIcon :icon="item.full_tank ? 'ri-checkbox-circle-line' : 'ri-close-circle-line'"
            :color="item.full_tank ? 'success' : 'default'" size="18" />
        </template>
        <template #item.actions="{ item }">
          <VBtn icon size="x-small" variant="text" color="error" @click="confirmDelete(item)">
            <VIcon icon="ri-delete-bin-line" />
          </VBtn>
        </template>
      </VDataTableServer>
    </VCard>

    <!-- Log Fill Dialog -->
    <VDialog v-model="dialog" max-width="520" persistent>
      <VCard :title="t('fleet_fuel_index.log_fill_title')">
        <VCardText>
          <VRow dense>
            <VCol cols="12">
              <VSelect v-model="form.vehicle_id" :label="t('fleet_fuel_index.table.vehicle') + ' *'" :items="vehicles"
                item-title="registration" item-value="id"
                :item-subtitle="(v: Vehicle) => `${v.make} ${v.model}`"
                :error-messages="form.errors.vehicle_id" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.filled_at" :label="t('fleet_fuel_index.form.fill_date') + ' *'" type="datetime-local" :error-messages="form.errors.filled_at" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="form.fuel_type" :label="t('fleet_fuel_index.form.fuel_type') + ' *'"
                :items="['petrol', 'diesel', 'hybrid', 'electric', 'lpg']"
                :error-messages="form.errors.fuel_type" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model.number="form.odometer_reading" :label="t('fleet_fuel_index.form.odometer')" type="number" :error-messages="form.errors.odometer_reading" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.station_name" :label="t('fleet_fuel_index.form.station_name')" />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField v-model.number="form.litres" :label="t('fleet_fuel_index.form.litres') + ' *'" type="number" :error-messages="form.errors.litres" />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField v-model.number="form.cost_per_litre" :label="t('fleet_fuel_index.form.cost_per_litre')" type="number" />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField v-model.number="form.total_cost" :label="t('fleet_fuel_index.form.total_cost')" type="number" :error-messages="form.errors.total_cost" />
            </VCol>
            <VCol cols="12">
              <VSwitch v-model="form.full_tank" :label="t('fleet_fuel_index.form.full_tank')" color="primary" hide-details />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="form.notes" :label="t('fleet_fuel_index.form.notes')" rows="2" auto-grow />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('fleet_fuel_index.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">{{ t('fleet_fuel_index.log_fill_up') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Delete Confirm -->
    <VDialog v-model="deleteDialog" max-width="400" persistent>
      <VCard :title="t('fleet_fuel_index.delete_title')">
        <VCardText>{{ t('fleet_fuel_index.delete_message', { vehicle: toDelete?.vehicle, date: toDelete?.filled_at }) }}</VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="deleteDialog = false">{{ t('fleet_fuel_index.cancel') }}</VBtn>
          <VBtn color="error" @click="doDelete">{{ t('fleet_fuel_index.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
