<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Vehicle = {
  id: number; uuid: string; registration: string; registration_number: string; make: string; model: string; year: number
  color: string | null; colour: string | null; vin: string | null; fuel_type: string; vehicle_type: string
  status: string; odometer_reading: number; odometer_unit: string
  asset_class?: string; name?: string | null; serial_number?: string | null; hours_reading?: number | null; farm?: string | null
  current_driver: string | null; department: string | null
  company_unit_id: number | null; tank_size: number | null
  next_service_due: string | null; insurance_expiry: string | null
  primary_image_url: string | null
}
type Lookup = { id: number; name: string }
type VehicleModel = { id: number; make_id: number; name: string }

const props = defineProps<{
  vehicles: { data: Vehicle[]; meta: any; links: any[] }
  filters: Record<string, any>
  stats: { total: number; available: number; on_booking: number; in_maintenance: number }
  makes: Lookup[]
  vehicleModels: VehicleModel[]
  fuelTypes: Lookup[]
  vehicleTypes: Lookup[]
  companyUnits: Lookup[]
}>()

const search   = ref(props.filters?.search ?? '')
const status   = ref(props.filters?.status ?? '')
const fuelType = ref(props.filters?.fuel_type ?? '')
const assetClass = ref(props.filters?.asset_class ?? '')
const assetClasses = computed(() => [
  { title: t('fleet_vehicles_index.asset_class.vehicle'), value: 'vehicle' },
  { title: t('fleet_vehicles_index.asset_class.equipment'), value: 'equipment' },
])
let timer: ReturnType<typeof setTimeout>

function applyFilters() {
  router.get(route('fleet.vehicles.index'), {
    search: search.value || undefined,
    status: status.value || undefined,
    fuel_type: fuelType.value || undefined,
    asset_class: assetClass.value || undefined,
  }, { preserveState: true, replace: true })
}

watch(search, () => { clearTimeout(timer); timer = setTimeout(applyFilters, 400) })
watch([status, fuelType, assetClass], applyFilters)

const dialog  = ref(false)
const editing = ref<Vehicle | null>(null)

const selectedMakeId = ref<number | null>(null)
const filteredModels = computed(() =>
  selectedMakeId.value ? props.vehicleModels.filter(m => m.make_id === selectedMakeId.value) : props.vehicleModels
)

function onMakeChange(makeName: string) {
  form.model = ''
  const found = props.makes.find(m => m.name === makeName)
  selectedMakeId.value = found?.id ?? null
}

const form = useForm({
  asset_class: 'vehicle', name: '', serial_number: '', hours_reading: null as number | null,
  registration_number: '', make: '', model: '', year: new Date().getFullYear(),
  colour: '', vin_number: '', engine_number: '', fuel_type: '', vehicle_type: '',
  seating_capacity: 5, tank_size: null as number | null,
  odometer_reading: 0,
  company_unit_id: null as number | null,
  purchase_date: '', purchase_price: null as number | null,
  insurance_expiry: '', roadworthy_expiry: '', licence_disc_expiry: '',
  status: 'available', notes: '',
})

function openAdd() {
  editing.value = null; form.reset(); selectedMakeId.value = null; dialog.value = true
}

function openEdit(v: Vehicle) {
  editing.value = v
  form.registration_number = v.registration_number ?? v.registration ?? ''
  form.asset_class = v.asset_class ?? 'vehicle'
  form.name = v.name ?? ''
  form.serial_number = v.serial_number ?? ''
  form.hours_reading = v.hours_reading ?? null
  form.make = v.make ?? ''
  form.model = v.model ?? ''
  const foundMake = props.makes.find(m => m.name === form.make)
  selectedMakeId.value = foundMake?.id ?? null
  form.year = v.year ?? new Date().getFullYear()
  form.colour = v.colour ?? v.color ?? ''
  form.vin_number = v.vin ?? ''
  form.engine_number = (v as any).engine_number ?? ''
  form.fuel_type = v.fuel_type ?? ''
  form.vehicle_type = v.vehicle_type ?? ''
  form.seating_capacity = (v as any).seating_capacity ?? (v as any).seats ?? 5
  form.tank_size = v.tank_size ?? null
  form.odometer_reading = v.odometer_reading ?? 0
  form.company_unit_id = v.company_unit_id ?? null
  form.purchase_date = (v as any).purchase_date ?? ''
  form.purchase_price = (v as any).purchase_price ?? null
  form.insurance_expiry = v.insurance_expiry ?? ''
  form.roadworthy_expiry = (v as any).roadworthy_expiry ?? ''
  form.licence_disc_expiry = (v as any).licence_disc_expiry ?? ''
  form.status = v.status ?? 'available'
  form.notes = (v as any).notes ?? ''
  dialog.value = true
}

function save() {
  if (editing.value) {
    form.patch(route('fleet.vehicles.update', editing.value.uuid), {
      onSuccess: () => { dialog.value = false; form.reset() },
    })
  } else {
    form.post(route('fleet.vehicles.store'), {
      onSuccess: () => { dialog.value = false; form.reset() },
    })
  }
}

const deleteDialog = ref(false)
const toDelete = ref<Vehicle | null>(null)

function confirmDelete(v: Vehicle) { toDelete.value = v; deleteDialog.value = true }
function doDelete() {
  if (!toDelete.value) return
  router.delete(route('fleet.vehicles.destroy', toDelete.value.uuid), {
    onSuccess: () => { deleteDialog.value = false; toDelete.value = null },
  })
}

const statusColor = (s: string) => ({
  available: 'success', booked: 'primary', in_use: 'deep-purple',
  maintenance: 'warning', retired: 'error', inactive: 'default',
})[s] ?? 'default'

const statusLabel = (s: string) => {
  const labels: Record<string, string> = {
    available: t('fleet_vehicles_index.statuses.available'),
    booked: t('fleet_vehicles_index.statuses.booked'),
    in_use: t('fleet_vehicles_index.statuses.in_use'),
    maintenance: t('fleet_vehicles_index.statuses.maintenance'),
    retired: t('fleet_vehicles_index.statuses.retired'),
    inactive: t('fleet_vehicles_index.statuses.inactive'),
  }
  return labels[s] ?? s
}

const headers = [
  { title: t('fleet_vehicles_index.table.registration'), key: 'registration', width: '120px' },
  { title: t('fleet_vehicles_index.table.vehicle'),      key: 'vehicle' },
  { title: t('fleet_vehicles_index.table.type_fuel'),  key: 'type_fuel' },
  { title: t('fleet_vehicles_index.table.odometer'),     key: 'odometer_reading' },
  { title: t('fleet_vehicles_index.table.hours'),        key: 'hours_reading' },
  { title: t('fleet_vehicles_index.table.driver'),       key: 'current_driver' },
  { title: t('fleet_vehicles_index.table.status'),       key: 'status' },
  { title: '',             key: 'actions', sortable: false, width: '100px' },
]
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center mb-5">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('fleet_vehicles_index.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">{{ t('fleet_vehicles_index.description') }}</p>
      </div>
      <VSpacer />
      <VBtn color="primary" prepend-icon="ri-add-line" @click="openAdd">{{ t('fleet_vehicles_index.add_vehicle') }}</VBtn>
    </div>

    <VRow class="mb-4">
      <VCol v-for="s in [
        { label: t('fleet_vehicles_index.stats.total'),          value: stats.total,          color: 'primary', icon: 'ri-car-line' },
        { label: t('fleet_vehicles_index.stats.available'),      value: stats.available,      color: 'success', icon: 'ri-checkbox-circle-line' },
        { label: t('fleet_vehicles_index.stats.on_booking'),     value: stats.on_booking,     color: 'info',    icon: 'ri-route-line' },
        { label: t('fleet_vehicles_index.stats.maintenance'),    value: stats.in_maintenance, color: 'warning', icon: 'ri-tools-line' },
      ]" :key="s.label" cols="6" md="3">
        <VCard variant="tonal" :color="s.color">
          <VCardText class="d-flex align-center gap-3 py-3">
            <VIcon :icon="s.icon" size="28" />
            <div>
              <div class="text-h5 font-weight-bold">{{ s.value }}</div>
              <div class="text-caption">{{ s.label }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard class="mb-4 pa-3">
      <VRow dense>
        <VCol cols="12" md="3">
          <VTextField v-model="search" :label="t('fleet_vehicles_index.filters.search')" prepend-inner-icon="ri-search-line" clearable density="compact" variant="outlined" hide-details />
        </VCol>
        <VCol cols="6" md="3">
          <VSelect v-model="status" :label="t('fleet_vehicles_index.filters.status')" :items="['available','booked','in_use','maintenance','retired','inactive']" clearable density="compact" variant="outlined" hide-details />
        </VCol>
        <VCol cols="6" md="3">
          <VSelect v-model="assetClass" :label="t('fleet_vehicles_index.filters.asset_class')" :items="assetClasses" clearable density="compact" variant="outlined" hide-details />
        </VCol>
        <VCol cols="6" md="3">
          <VSelect v-model="fuelType" :label="t('fleet_vehicles_index.filters.fuel_type')" :items="fuelTypes" item-title="name" item-value="name" clearable density="compact" variant="outlined" hide-details />
        </VCol>
      </VRow>
    </VCard>

    <VCard>
      <VDataTableServer
        :headers="headers"
        :items="vehicles.data"
        :items-length="vehicles.meta?.total ?? 0"
        density="comfortable"
        hover
      >
        <template #item.vehicle="{ item }">
          <div class="d-flex align-center gap-2 py-1">
            <VAvatar v-if="item.primary_image_url" :image="item.primary_image_url" size="40" />
            <VAvatar v-else size="40" color="grey-lighten-2">
              <VIcon icon="ri-car-line" color="grey-darken-1" />
            </VAvatar>
            <div>
              <div class="font-weight-medium">{{ item.name || `${item.make ?? ''} ${item.model ?? ''}` }}</div>
              <div class="text-caption text-medium-emphasis">{{ item.year }}<template v-if="item.color"> · {{ item.color }}</template><template v-if="item.farm"> · {{ item.farm }}</template></div>
            </div>
          </div>
        </template>
        <template #item.type_fuel="{ item }">
          <VChip size="x-small" variant="tonal" color="primary" class="mr-1">{{ item.vehicle_type }}</VChip>
          <VChip size="x-small" variant="tonal" color="info">{{ item.fuel_type }}</VChip>
        </template>
        <template #item.hours_reading="{ item }">
          <span v-if="item.asset_class === 'equipment' || Number(item.hours_reading) > 0">{{ Number(item.hours_reading ?? 0).toLocaleString() }} h</span>
          <span v-else class="text-medium-emphasis">—</span>
        </template>
        <template #item.registration="{ item }">
          <span v-if="item.registration">{{ item.registration }}</span>
          <VChip v-else size="x-small" variant="tonal">{{ t('fleet_vehicles_index.asset_class.equipment') }}</VChip>
        </template>
        <template #item.odometer_reading="{ item }">
          {{ item.odometer_reading?.toLocaleString() }} {{ item.odometer_unit }}
        </template>
        <template #item.current_driver="{ item }">
          <span v-if="item.current_driver">{{ item.current_driver }}</span>
          <span v-else class="text-medium-emphasis text-caption">{{ t('fleet_vehicles_index.table.no_driver') }}</span>
        </template>
        <template #item.status="{ item }">
          <VChip :color="statusColor(item.status)" size="x-small" label class="text-capitalize">
            {{ statusLabel(item.status) }}
          </VChip>
        </template>
        <template #item.actions="{ item }">
          <div class="d-flex gap-1 align-center">
            <VBtn size="x-small" variant="tonal" color="info" :href="route('fleet.vehicles.show', item.uuid)" prepend-icon="ri-eye-line">{{ t('fleet_vehicles_index.table.view') }}</VBtn>
            <VBtn icon size="x-small" variant="text" color="primary" @click="openEdit(item)"><VIcon icon="ri-pencil-line" /></VBtn>
            <VBtn icon size="x-small" variant="text" color="error" @click="confirmDelete(item)"><VIcon icon="ri-delete-bin-line" /></VBtn>
          </div>
        </template>
      </VDataTableServer>
    </VCard>

    <VDialog v-model="dialog" max-width="760" scrollable>
      <VCard :title="editing ? t('fleet_vehicles_index.dialog.title_edit') : t('fleet_vehicles_index.dialog.title_add')">
        <VDivider />
        <VCardText style="max-height:75vh">
          <VRow dense class="mt-1">
            <VCol cols="12" md="4"><VSelect v-model="form.asset_class" :label="t('fleet_vehicles_index.filters.asset_class')" :items="assetClasses" variant="outlined" density="comfortable" :error-messages="form.errors.asset_class" /></VCol>
            <VCol cols="12" md="4"><VTextField v-model="form.name" :label="t('fleet_vehicles_index.dialog.name')" variant="outlined" density="comfortable" :error-messages="form.errors.name" /></VCol>
            <VCol cols="12" md="4"><VTextField v-model="form.serial_number" :label="t('fleet_vehicles_index.dialog.serial_number')" variant="outlined" density="comfortable" :error-messages="form.errors.serial_number" /></VCol>
            <VCol v-if="form.asset_class === 'equipment'" cols="12" md="4"><VTextField v-model.number="form.hours_reading" type="number" :label="t('fleet_vehicles_index.table.hours')" variant="outlined" density="comfortable" :error-messages="form.errors.hours_reading" /></VCol>
            <VCol cols="12" md="4"><VTextField v-model="form.registration_number" :label="t('fleet_vehicles_index.dialog.registration')" :error-messages="form.errors.registration_number" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="4">
              <VSelect v-model="form.make" :label="t('fleet_vehicles_index.dialog.make')" :items="makes" item-title="name" item-value="name"
                variant="outlined" density="comfortable" :error-messages="form.errors.make"
                @update:model-value="onMakeChange" />
            </VCol>
            <VCol cols="12" md="4">
              <VSelect v-model="form.model" :label="t('fleet_vehicles_index.dialog.model')" :items="filteredModels" item-title="name" item-value="name"
                variant="outlined" density="comfortable" :error-messages="form.errors.model"
                :disabled="!form.make" />
            </VCol>
            <VCol cols="6" md="2"><VTextField v-model.number="form.year" :label="t('fleet_vehicles_index.dialog.year')" type="number" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="6" md="2"><VTextField v-model="form.colour" :label="t('fleet_vehicles_index.dialog.color')" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="4"><VTextField v-model="form.vin_number" :label="t('fleet_vehicles_index.dialog.vin')" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="4"><VTextField v-model="form.engine_number" :label="t('fleet_vehicles_index.dialog.engine')" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="form.fuel_type" :label="t('fleet_vehicles_index.dialog.fuel_type')" :items="fuelTypes" item-title="name" item-value="name"
                variant="outlined" density="comfortable" :error-messages="form.errors.fuel_type" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="form.vehicle_type" :label="t('fleet_vehicles_index.dialog.vehicle_type')" :items="vehicleTypes" item-title="name" item-value="name"
                variant="outlined" density="comfortable" :error-messages="form.errors.vehicle_type" />
            </VCol>
            <VCol cols="6" md="3"><VTextField v-model.number="form.seating_capacity" :label="t('fleet_vehicles_index.dialog.seats')" type="number" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="6" md="3"><VTextField v-model.number="form.tank_size" :label="t('fleet_vehicles_index.dialog.fuel_tank')" type="number" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="6"><VTextField v-model.number="form.odometer_reading" :label="t('fleet_vehicles_index.dialog.odometer')" type="number" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="form.company_unit_id" :label="t('fleet_vehicles_index.dialog.department')" :items="companyUnits" item-title="name" item-value="id"
                clearable variant="outlined" density="comfortable" :error-messages="form.errors.company_unit_id" />
            </VCol>
            <VCol cols="12" md="3"><VTextField v-model="form.purchase_date" :label="t('fleet_vehicles_index.dialog.purchase_date')" type="date" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="3"><VTextField v-model.number="form.purchase_price" :label="t('fleet_vehicles_index.dialog.purchase_price')" type="number" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="4"><VTextField v-model="form.insurance_expiry" :label="t('fleet_vehicles_index.dialog.insurance_expiry')" type="date" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="4"><VTextField v-model="form.roadworthy_expiry" :label="t('fleet_vehicles_index.dialog.roadworthy_expiry')" type="date" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="4"><VTextField v-model="form.licence_disc_expiry" :label="t('fleet_vehicles_index.dialog.licence_disc_expiry')" type="date" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12" md="4">
              <VSelect v-model="form.status" :label="t('fleet_vehicles_index.dialog.status_label')" variant="outlined" density="comfortable"
                :items="['available','booked','maintenance','offroad','disposed']" />
            </VCol>
            <VCol cols="12"><VTextarea v-model="form.notes" :label="t('fleet_vehicles_index.dialog.notes')" rows="2" auto-grow variant="outlined" density="comfortable" /></VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VAlert v-if="Object.keys(form.errors).length" type="error" variant="tonal" density="compact" class="mx-4 mt-3">
          {{ t('fleet_vehicles_index.dialog.error_alert') }}
        </VAlert>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('fleet_vehicles_index.dialog.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="form.processing" @click="save">
            {{ editing ? t('fleet_vehicles_index.dialog.save') : t('fleet_vehicles_index.dialog.add') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="deleteDialog" max-width="420">
      <VCard :title="t('fleet_vehicles_index.delete_dialog.title')">
        <VCardText>{{ t('fleet_vehicles_index.delete_dialog.message', { registration: toDelete?.registration }) }}</VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="deleteDialog = false">{{ t('fleet_vehicles_index.delete_dialog.cancel') }}</VBtn>
          <VBtn color="error" @click="doDelete">{{ t('fleet_vehicles_index.delete_dialog.confirm') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
