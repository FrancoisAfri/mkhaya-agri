<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Row { id: number; name: string; active: boolean; [key: string]: any }
interface VehicleModel extends Row { make_id: number; make_name: string }
interface FillingStation extends Row { location: string | null; fuel_types: string | null; contact_number: string | null }
interface CardType extends Row { description: string | null }
interface LicenceType extends Row { description: string | null; validity_months: number | null }
interface ApprovalStep {
  id: number; step_number: number; step_label: string; approver_type: string
  role_name: string | null; employee_id: number | null; employee_name: string | null
  company_unit_id: number | null; unit_name: string | null
  check_local_trip: boolean; is_active: boolean
}
interface User { id: number; name: string; email: string }
interface CompanyUnit { id: number; name: string }
interface Role { id: number; name: string }

const props = defineProps<{
  makes: Row[]
  models: VehicleModel[]
  vehicleTypes: Row[]
  fuelTypes: Row[]
  incidentTypes: (Row & { description: string | null })[]
  keySafes: (Row & { location_description: string | null })[]
  fillingStations: FillingStation[]
  cardTypes: CardType[]
  licenceTypes: LicenceType[]
  approvalSteps: ApprovalStep[]
  companyUnits: CompanyUnit[]
  roles: Role[]
  users: User[]
}>()

const tab = ref('')
const snack = ref<{ show: boolean; msg: string; color: string }>({ show: false, msg: '', color: 'success' })
function toast(msg: string, color = 'success') { snack.value = { show: true, msg, color } }

const deleteDialog     = ref(false)
const deleteRouteName  = ref('')
const deleteItemId     = ref<number | null>(null)
const deleteItemLabel  = ref('')
function confirmDel(routeName: string, id: number, label: string) {
  deleteRouteName.value = routeName
  deleteItemId.value    = id
  deleteItemLabel.value = label
  deleteDialog.value    = true
}
function executeDel() {
  if (!deleteItemId.value) return
  router.delete(route(deleteRouteName.value, deleteItemId.value), {
    preserveScroll: true,
    onSuccess: () => { deleteDialog.value = false; toast(t('fleet_setup_index.toasts.deleted')) },
  })
}

const sections = computed(() => [
  { key: 'vehicleTypes',    label: t('fleet_setup_index.sections.vehicle_types'),     icon: 'tabler-cars' },
  { key: 'makes',           label: t('fleet_setup_index.sections.makes'),            icon: 'tabler-car' },
  { key: 'cardTypes',       label: t('fleet_setup_index.sections.card_types'),       icon: 'tabler-credit-card' },
  { key: 'fillingStations', label: t('fleet_setup_index.sections.filling_stations'), icon: 'tabler-gas-station' },
  { key: 'licenceTypes',    label: t('fleet_setup_index.sections.licence_types'),    icon: 'tabler-certificate' },
  { key: 'incidentTypes',   label: t('fleet_setup_index.sections.incident_types'),   icon: 'tabler-alert-circle' },
  { key: 'keySafes',        label: t('fleet_setup_index.sections.key_safes'),        icon: 'tabler-shield-lock' },
  { key: 'approvalSteps',   label: t('fleet_setup_index.sections.approval_steps'),   icon: 'tabler-git-merge' },
  { key: 'settings',        label: t('fleet_setup_index.sections.settings'),         icon: 'tabler-settings' },
])

const editId = ref<number | null>(null)
const editData = ref<Record<string, any>>({})
function startEdit(row: any) { editId.value = row.id; editData.value = { ...row } }
function cancelEdit() { editId.value = null; editData.value = {} }
function del(routeName: string, id: number, label = 'this entry') {
  confirmDel(routeName, id, label)
}

const makeForm = useForm({ name: '' })
function addMake() { makeForm.post(route('fleet.setup.makes.store'), { preserveScroll: true, onSuccess: () => { makeForm.reset(); toast(t('fleet_setup_index.toasts.make_added')) } }) }
function saveMake(id: number) { router.patch(route('fleet.setup.makes.update', id), editData.value, { preserveScroll: true, onSuccess: () => { cancelEdit(); toast(t('fleet_setup_index.toasts.updated')) } }) }

const modelForm = useForm({ make_id: null as number | null, name: '' })
const selectedMakeForModel = ref<number | null>(null)
const filteredModels = computed(() =>
  selectedMakeForModel.value ? props.models.filter(m => m.make_id === selectedMakeForModel.value) : props.models
)
function addModel() { modelForm.post(route('fleet.setup.models.store'), { preserveScroll: true, onSuccess: () => { modelForm.reset(); toast(t('fleet_setup_index.toasts.model_added')) } }) }
function saveModel(id: number) { router.patch(route('fleet.setup.models.update', id), editData.value, { preserveScroll: true, onSuccess: () => { cancelEdit(); toast(t('fleet_setup_index.toasts.updated')) } }) }

function addLookup(routeName: string, form: any) { form.post(route(routeName), { preserveScroll: true, onSuccess: () => { form.reset(); toast(t('fleet_setup_index.toasts.added')) } }) }
function saveLookup(routeName: string, id: number) { router.patch(route(routeName, id), editData.value, { preserveScroll: true, onSuccess: () => { cancelEdit(); toast(t('fleet_setup_index.toasts.updated')) } }) }

const vehicleTypeForm   = useForm({ name: '' })
const fuelTypeForm      = useForm({ name: '' })
const incidentTypeForm  = useForm({ name: '', description: '' })
const keySafeForm       = useForm({ name: '', location_description: '' })
const cardTypeForm      = useForm({ name: '', description: '' })
const licenceTypeForm   = useForm({ name: '', description: '', validity_months: '' as unknown as number })
const stationForm       = useForm({ name: '', location: '', fuel_types: '', contact_number: '' })

const stepDialog = ref(false)
const editingStepId = ref<number | null>(null)
const stepForm = useForm({
  step_number: 1, step_label: '', approver_type: 'line_manager',
  role_name: '' as string | null, employee_id: null as number | null,
  company_unit_id: null as number | null, check_local_trip: false, is_active: true,
})
function openAddStep() {
  editingStepId.value = null
  stepForm.reset()
  stepForm.approver_type = 'line_manager'
  stepForm.is_active = true
  stepDialog.value = true
}
function openEditStep(s: ApprovalStep) {
  editingStepId.value = s.id
  stepForm.step_number    = s.step_number
  stepForm.step_label     = s.step_label
  stepForm.approver_type  = s.approver_type
  stepForm.role_name      = s.role_name ?? ''
  stepForm.employee_id    = s.employee_id ?? null
  stepForm.company_unit_id= s.company_unit_id ?? null
  stepForm.check_local_trip = s.check_local_trip
  stepForm.is_active      = s.is_active
  stepDialog.value = true
}
function submitStep() {
  if (editingStepId.value) {
    stepForm.patch(route('fleet.setup.approval-steps.update', editingStepId.value), {
      preserveScroll: true,
      onSuccess: () => { stepDialog.value = false; stepForm.reset(); toast(t('fleet_setup_index.toasts.step_updated')) },
    })
  } else {
    stepForm.post(route('fleet.setup.approval-steps.store'), {
      preserveScroll: true,
      onSuccess: () => { stepDialog.value = false; stepForm.reset(); toast(t('fleet_setup_index.toasts.step_added')) },
    })
  }
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center mb-4">
      <VIcon color="primary" class="mr-3">tabler-settings</VIcon>
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('fleet_setup_index.page_title') }}</h1>
        <p class="text-caption text-medium-emphasis">{{ t('fleet_setup_index.description') }}</p>
      </div>
    </div>

    <VSnackbar v-model="snack.show" :color="snack.color" location="top right" :timeout="3000">{{ snack.msg }}</VSnackbar>

    <VCard class="mb-4">
      <VCardSubtitle class="pa-3 pb-1">{{ t('fleet_setup_index.page_title') }}</VCardSubtitle>
      <VCardText class="pa-3">
        <div class="d-flex flex-wrap ga-2">
          <VBtn
            v-for="s in sections.filter(s => s.key !== 'settings')"
            :key="s.key"
            variant="tonal"
            :color="tab === s.key ? 'primary' : undefined"
            :prepend-icon="s.icon"
            size="small"
            @click="tab = s.key"
          >{{ s.label }}</VBtn>
          <VBtn
            variant="tonal"
            :color="tab === 'models' ? 'primary' : undefined"
            prepend-icon="tabler-car"
            size="small"
            @click="tab = 'models'"
          >{{ t('fleet_setup_index.sections.models') }}</VBtn>
          <VBtn
            variant="tonal"
            :color="tab === 'fuelTypes' ? 'primary' : undefined"
            prepend-icon="tabler-gas-station"
            size="small"
            @click="tab = 'fuelTypes'"
          >{{ t('fleet_setup_index.sections.fuel_types') }}</VBtn>
          <VBtn
            variant="tonal"
            prepend-icon="tabler-file-text"
            size="small"
            :href="route ? '/settings/document-types' : '#'"
          >{{ t('fleet_setup_index.sections.document_types') }}</VBtn>
          <VBtn
            variant="tonal"
            prepend-icon="tabler-calendar-clock"
            size="small"
            href="/fleet/reports/scheduled"
          >{{ t('fleet_setup_index.sections.scheduled_reports') }}</VBtn>
          <VBtn
            variant="tonal"
            prepend-icon="tabler-settings"
            size="small"
            href="/fleet/settings"
          >{{ t('fleet_setup_index.sections.fleet_settings') }}</VBtn>
          <VBtn
            variant="tonal"
            prepend-icon="tabler-plug-connected"
            size="small"
            href="/fleet/integrations"
          >{{ t('fleet_setup_index.sections.integrations') }}</VBtn>
          <VBtn
            variant="tonal"
            prepend-icon="tabler-chart-bar"
            size="small"
            href="/fleet/analytics"
          >{{ t('fleet_setup_index.sections.bi_analytics') }}</VBtn>
        </div>
      </VCardText>
    </VCard>

    <VCard v-if="tab">
      <VCardTitle class="d-flex align-center ga-2 pa-4 pb-2">
        <VIcon>{{ sections.find(s => s.key === tab)?.icon ?? 'tabler-settings' }}</VIcon>
        {{ sections.find(s => s.key === tab)?.label ?? tab }}
        <VSpacer />
        <VBtn icon size="small" variant="text" @click="tab = ''"><VIcon>tabler-x</VIcon></VBtn>
      </VCardTitle>
      <VDivider />

      <!-- Makes -->
      <div v-if="tab === 'makes'" class="pa-4">
        <VForm @submit.prevent="addMake" class="d-flex ga-2 mb-4">
          <VTextField v-model="makeForm.name" :label="t('fleet_setup_index.makes.make_name')" variant="outlined" density="comfortable" hide-details :error-messages="makeForm.errors.name" style="max-width:300px" />
          <VBtn type="submit" color="primary" :loading="makeForm.processing">{{ t('fleet_setup_index.common.add') }}</VBtn>
        </VForm>
        <VTable density="compact">
          <thead><tr><th>{{ t('fleet_setup_index.makes.table_name') }}</th><th>{{ t('fleet_setup_index.common.active') }}</th><th>{{ t('fleet_setup_index.common.actions') }}</th></tr></thead>
          <tbody>
            <tr v-for="m in makes" :key="m.id">
              <td><VTextField v-if="editId===m.id" v-model="editData.name" density="compact" variant="outlined" hide-details style="max-width:200px" /><span v-else>{{ m.name }}</span></td>
              <td><VChip size="x-small" :color="m.active?'success':'grey'">{{ m.active ? t('fleet_setup_index.common.active') : t('fleet_setup_index.common.inactive') }}</VChip></td>
              <td class="text-right">
                <template v-if="editId===m.id">
                  <VBtn size="small" color="success" variant="tonal" @click="saveMake(m.id)" class="mr-1">{{ t('fleet_setup_index.common.save') }}</VBtn>
                  <VBtn size="small" variant="text" @click="cancelEdit">{{ t('fleet_setup_index.common.cancel') }}</VBtn>
                </template>
                <template v-else>
                  <VBtn size="small" variant="tonal" @click="startEdit(m)" class="mr-1">{{ t('fleet_setup_index.common.edit') }}</VBtn>
                  <VBtn size="small" color="error" variant="tonal" @click="del('fleet.setup.makes.destroy', m.id, m.name)">{{ t('fleet_setup_index.common.delete') }}</VBtn>
                </template>
              </td>
            </tr>
            <tr v-if="!makes.length"><td colspan="3" class="text-center text-medium-emphasis pa-4">{{ t('fleet_setup_index.common.no_data') }}</td></tr>
          </tbody>
        </VTable>
      </div>

      <!-- Models -->
      <div v-if="tab === 'models'" class="pa-4">
        <VForm @submit.prevent="addModel" class="d-flex ga-2 mb-4 flex-wrap">
          <VSelect v-model="modelForm.make_id" :items="makes" item-title="name" item-value="id" :label="t('fleet_setup_index.models.make')" variant="outlined" density="comfortable" hide-details style="max-width:200px" />
          <VTextField v-model="modelForm.name" :label="t('fleet_setup_index.models.model_name')" variant="outlined" density="comfortable" hide-details style="max-width:250px" />
          <VBtn type="submit" color="primary" :loading="modelForm.processing">{{ t('fleet_setup_index.common.add') }}</VBtn>
        </VForm>
        <VSelect v-model="selectedMakeForModel" :items="[{id:null,name:t('fleet_setup_index.common.all_makes')},...makes]" item-title="name" item-value="id" :label="t('fleet_setup_index.common.filter_by_make')" variant="outlined" density="compact" hide-details class="mb-3" style="max-width:220px" />
        <VTable density="compact">
          <thead><tr><th>{{ t('fleet_setup_index.models.table_make') }}</th><th>{{ t('fleet_setup_index.models.table_model') }}</th><th>{{ t('fleet_setup_index.common.active') }}</th><th>{{ t('fleet_setup_index.common.actions') }}</th></tr></thead>
          <tbody>
            <tr v-for="m in filteredModels" :key="m.id">
              <td>{{ m.make_name }}</td>
              <td><VTextField v-if="editId===m.id" v-model="editData.name" density="compact" variant="outlined" hide-details style="max-width:200px" /><span v-else>{{ m.name }}</span></td>
              <td><VChip size="x-small" :color="m.active?'success':'grey'">{{ m.active ? t('fleet_setup_index.common.active') : t('fleet_setup_index.common.inactive') }}</VChip></td>
              <td class="text-right">
                <template v-if="editId===m.id">
                  <VBtn size="small" color="success" variant="tonal" @click="saveModel(m.id)" class="mr-1">{{ t('fleet_setup_index.common.save') }}</VBtn>
                  <VBtn size="small" variant="text" @click="cancelEdit">{{ t('fleet_setup_index.common.cancel') }}</VBtn>
                </template>
                <template v-else>
                  <VBtn size="small" variant="tonal" @click="startEdit(m)" class="mr-1">{{ t('fleet_setup_index.common.edit') }}</VBtn>
                  <VBtn size="small" color="error" variant="tonal" @click="del('fleet.setup.models.destroy', m.id, m.name)">{{ t('fleet_setup_index.common.delete') }}</VBtn>
                </template>
              </td>
            </tr>
            <tr v-if="!filteredModels.length"><td colspan="4" class="text-center text-medium-emphasis pa-4">{{ t('fleet_setup_index.common.no_data') }}</td></tr>
          </tbody>
        </VTable>
      </div>

      <!-- Vehicle Types -->
      <div v-if="tab === 'vehicleTypes'" class="pa-4">
        <VForm @submit.prevent="addLookup('fleet.setup.vehicle-types.store', vehicleTypeForm)" class="d-flex ga-2 mb-4">
          <VTextField v-model="vehicleTypeForm.name" :label="t('fleet_setup_index.vehicle_types.vehicle_type')" variant="outlined" density="comfortable" hide-details style="max-width:300px" />
          <VBtn type="submit" color="primary" :loading="vehicleTypeForm.processing">{{ t('fleet_setup_index.common.add') }}</VBtn>
        </VForm>
        <VTable density="compact">
          <thead><tr><th>{{ t('fleet_setup_index.vehicle_types.table_name') }}</th><th>{{ t('fleet_setup_index.common.active') }}</th><th>{{ t('fleet_setup_index.common.actions') }}</th></tr></thead>
          <tbody>
            <tr v-for="template in vehicleTypes" :key="template.id">
              <td><VTextField v-if="editId===template.id" v-model="editData.name" density="compact" variant="outlined" hide-details style="max-width:200px" /><span v-else>{{ template.name }}</span></td>
              <td><VChip size="x-small" :color="template.active?'success':'grey'">{{ template.active ? t('fleet_setup_index.common.active') : t('fleet_setup_index.common.inactive') }}</VChip></td>
              <td class="text-right">
                <template v-if="editId===template.id">
                  <VBtn size="small" color="success" variant="tonal" @click="saveLookup('fleet.setup.vehicle-types.update', template.id)" class="mr-1">{{ t('fleet_setup_index.common.save') }}</VBtn>
                  <VBtn size="small" variant="text" @click="cancelEdit">{{ t('fleet_setup_index.common.cancel') }}</VBtn>
                </template>
                <template v-else>
                  <VBtn size="small" variant="tonal" @click="startEdit(template)" class="mr-1">{{ t('fleet_setup_index.common.edit') }}</VBtn>
                  <VBtn size="small" color="error" variant="tonal" @click="del('fleet.setup.vehicle-types.destroy', template.id, template.name)">{{ t('fleet_setup_index.common.delete') }}</VBtn>
                </template>
              </td>
            </tr>
            <tr v-if="!vehicleTypes.length"><td colspan="3" class="text-center text-medium-emphasis pa-4">{{ t('fleet_setup_index.common.no_data') }}</td></tr>
          </tbody>
        </VTable>
      </div>

      <!-- Fuel Types -->
      <div v-if="tab === 'fuelTypes'" class="pa-4">
        <VForm @submit.prevent="addLookup('fleet.setup.fuel-types.store', fuelTypeForm)" class="d-flex ga-2 mb-4">
          <VTextField v-model="fuelTypeForm.name" :label="t('fleet_setup_index.fuel_types.fuel_type')" variant="outlined" density="comfortable" hide-details style="max-width:300px" />
          <VBtn type="submit" color="primary" :loading="fuelTypeForm.processing">{{ t('fleet_setup_index.common.add') }}</VBtn>
        </VForm>
        <VTable density="compact">
          <thead><tr><th>{{ t('fleet_setup_index.fuel_types.table_name') }}</th><th>{{ t('fleet_setup_index.common.active') }}</th><th>{{ t('fleet_setup_index.common.actions') }}</th></tr></thead>
          <tbody>
            <tr v-for="template in fuelTypes" :key="template.id">
              <td><VTextField v-if="editId===template.id" v-model="editData.name" density="compact" variant="outlined" hide-details style="max-width:200px" /><span v-else>{{ template.name }}</span></td>
              <td><VChip size="x-small" :color="template.active?'success':'grey'">{{ template.active ? t('fleet_setup_index.common.active') : t('fleet_setup_index.common.inactive') }}</VChip></td>
              <td class="text-right">
                <template v-if="editId===template.id">
                  <VBtn size="small" color="success" variant="tonal" @click="saveLookup('fleet.setup.fuel-types.update', template.id)" class="mr-1">{{ t('fleet_setup_index.common.save') }}</VBtn>
                  <VBtn size="small" variant="text" @click="cancelEdit">{{ t('fleet_setup_index.common.cancel') }}</VBtn>
                </template>
                <template v-else>
                  <VBtn size="small" variant="tonal" @click="startEdit(template)" class="mr-1">{{ t('fleet_setup_index.common.edit') }}</VBtn>
                  <VBtn size="small" color="error" variant="tonal" @click="del('fleet.setup.fuel-types.destroy', template.id, template.name)">{{ t('fleet_setup_index.common.delete') }}</VBtn>
                </template>
              </td>
            </tr>
            <tr v-if="!fuelTypes.length"><td colspan="3" class="text-center text-medium-emphasis pa-4">{{ t('fleet_setup_index.common.no_data') }}</td></tr>
          </tbody>
        </VTable>
      </div>

      <!-- Incident Types -->
      <div v-if="tab === 'incidentTypes'" class="pa-4">
        <VForm @submit.prevent="addLookup('fleet.setup.incident-types.store', incidentTypeForm)" class="d-flex ga-2 mb-4 flex-wrap">
          <VTextField v-model="incidentTypeForm.name" :label="t('fleet_setup_index.incident_types.incident_type')" variant="outlined" density="comfortable" hide-details style="max-width:250px" />
          <VTextField v-model="incidentTypeForm.description" :label="t('fleet_setup_index.incident_types.description_optional')" variant="outlined" density="comfortable" hide-details style="max-width:300px" />
          <VBtn type="submit" color="primary" :loading="incidentTypeForm.processing">{{ t('fleet_setup_index.common.add') }}</VBtn>
        </VForm>
        <VTable density="compact">
          <thead><tr><th>{{ t('fleet_setup_index.incident_types.table_name') }}</th><th>{{ t('fleet_setup_index.incident_types.table_description') }}</th><th>{{ t('fleet_setup_index.common.active') }}</th><th>{{ t('fleet_setup_index.common.actions') }}</th></tr></thead>
          <tbody>
            <tr v-for="template in incidentTypes" :key="template.id">
              <td><VTextField v-if="editId===template.id" v-model="editData.name" density="compact" variant="outlined" hide-details style="max-width:160px" /><span v-else>{{ template.name }}</span></td>
              <td><VTextField v-if="editId===template.id" v-model="editData.description" density="compact" variant="outlined" hide-details style="max-width:220px" /><span v-else class="text-caption">{{ template.description ?? '—' }}</span></td>
              <td><VChip size="x-small" :color="template.active?'success':'grey'">{{ template.active ? t('fleet_setup_index.common.active') : t('fleet_setup_index.common.inactive') }}</VChip></td>
              <td class="text-right">
                <template v-if="editId===template.id">
                  <VBtn size="small" color="success" variant="tonal" @click="saveLookup('fleet.setup.incident-types.update', template.id)" class="mr-1">{{ t('fleet_setup_index.common.save') }}</VBtn>
                  <VBtn size="small" variant="text" @click="cancelEdit">{{ t('fleet_setup_index.common.cancel') }}</VBtn>
                </template>
                <template v-else>
                  <VBtn size="small" variant="tonal" @click="startEdit(template)" class="mr-1">{{ t('fleet_setup_index.common.edit') }}</VBtn>
                  <VBtn size="small" color="error" variant="tonal" @click="del('fleet.setup.incident-types.destroy', template.id, template.name)">{{ t('fleet_setup_index.common.delete') }}</VBtn>
                </template>
              </td>
            </tr>
            <tr v-if="!incidentTypes.length"><td colspan="4" class="text-center text-medium-emphasis pa-4">{{ t('fleet_setup_index.common.no_data') }}</td></tr>
          </tbody>
        </VTable>
      </div>

      <!-- Key Safes -->
      <div v-if="tab === 'keySafes'" class="pa-4">
        <VForm @submit.prevent="addLookup('fleet.setup.key-safes.store', keySafeForm)" class="d-flex ga-2 mb-4 flex-wrap">
          <VTextField v-model="keySafeForm.name" :label="t('fleet_setup_index.key_safes.safe_name')" variant="outlined" density="comfortable" hide-details style="max-width:250px" />
          <VTextField v-model="keySafeForm.location_description" :label="t('fleet_setup_index.key_safes.location')" variant="outlined" density="comfortable" hide-details style="max-width:300px" />
          <VBtn type="submit" color="primary" :loading="keySafeForm.processing">{{ t('fleet_setup_index.common.add') }}</VBtn>
        </VForm>
        <VTable density="compact">
          <thead><tr><th>{{ t('fleet_setup_index.key_safes.table_name') }}</th><th>{{ t('fleet_setup_index.key_safes.table_location') }}</th><th>{{ t('fleet_setup_index.common.active') }}</th><th>{{ t('fleet_setup_index.common.actions') }}</th></tr></thead>
          <tbody>
            <tr v-for="s in keySafes" :key="s.id">
              <td><VTextField v-if="editId===s.id" v-model="editData.name" density="compact" variant="outlined" hide-details style="max-width:160px" /><span v-else>{{ s.name }}</span></td>
              <td><VTextField v-if="editId===s.id" v-model="editData.location_description" density="compact" variant="outlined" hide-details style="max-width:220px" /><span v-else class="text-caption">{{ s.location_description ?? '—' }}</span></td>
              <td><VChip size="x-small" :color="s.active?'success':'grey'">{{ s.active ? t('fleet_setup_index.common.active') : t('fleet_setup_index.common.inactive') }}</VChip></td>
              <td class="text-right">
                <template v-if="editId===s.id">
                  <VBtn size="small" color="success" variant="tonal" @click="saveLookup('fleet.setup.key-safes.update', s.id)" class="mr-1">{{ t('fleet_setup_index.common.save') }}</VBtn>
                  <VBtn size="small" variant="text" @click="cancelEdit">{{ t('fleet_setup_index.common.cancel') }}</VBtn>
                </template>
                <template v-else>
                  <VBtn size="small" variant="tonal" @click="startEdit(s)" class="mr-1">{{ t('fleet_setup_index.common.edit') }}</VBtn>
                  <VBtn size="small" color="error" variant="tonal" @click="del('fleet.setup.key-safes.destroy', s.id, s.name)">{{ t('fleet_setup_index.common.delete') }}</VBtn>
                </template>
              </td>
            </tr>
            <tr v-if="!keySafes.length"><td colspan="4" class="text-center text-medium-emphasis pa-4">{{ t('fleet_setup_index.common.no_data') }}</td></tr>
          </tbody>
        </VTable>
      </div>

      <!-- Filling Stations -->
      <div v-if="tab === 'fillingStations'" class="pa-4">
        <VForm @submit.prevent="addLookup('fleet.setup.filling-stations.store', stationForm)" class="d-flex ga-2 mb-4 flex-wrap">
          <VTextField v-model="stationForm.name" :label="t('fleet_setup_index.filling_stations.station_name')" variant="outlined" density="comfortable" hide-details style="max-width:200px" />
          <VTextField v-model="stationForm.location" :label="t('fleet_setup_index.filling_stations.location')" variant="outlined" density="comfortable" hide-details style="max-width:220px" />
          <VTextField v-model="stationForm.fuel_types" :label="t('fleet_setup_index.filling_stations.fuel_types')" variant="outlined" density="comfortable" hide-details style="max-width:200px" />
          <VTextField v-model="stationForm.contact_number" :label="t('fleet_setup_index.filling_stations.contact')" variant="outlined" density="comfortable" hide-details style="max-width:140px" />
          <VBtn type="submit" color="primary" :loading="stationForm.processing">{{ t('fleet_setup_index.common.add') }}</VBtn>
        </VForm>
        <VTable density="compact">
          <thead><tr><th>{{ t('fleet_setup_index.filling_stations.table_name') }}</th><th>{{ t('fleet_setup_index.filling_stations.table_location') }}</th><th>{{ t('fleet_setup_index.filling_stations.table_fuel_types') }}</th><th>{{ t('fleet_setup_index.filling_stations.table_contact') }}</th><th>{{ t('fleet_setup_index.common.active') }}</th><th>{{ t('fleet_setup_index.common.actions') }}</th></tr></thead>
          <tbody>
            <tr v-for="s in fillingStations" :key="s.id">
              <td><VTextField v-if="editId===s.id" v-model="editData.name" density="compact" variant="outlined" hide-details style="max-width:140px" /><span v-else>{{ s.name }}</span></td>
              <td><VTextField v-if="editId===s.id" v-model="editData.location" density="compact" variant="outlined" hide-details style="max-width:180px" /><span v-else class="text-caption">{{ s.location ?? '—' }}</span></td>
              <td class="text-caption">{{ s.fuel_types ?? '—' }}</td>
              <td class="text-caption">{{ s.contact_number ?? '—' }}</td>
              <td><VChip size="x-small" :color="s.active?'success':'grey'">{{ s.active ? t('fleet_setup_index.common.active') : t('fleet_setup_index.common.inactive') }}</VChip></td>
              <td class="text-right">
                <template v-if="editId===s.id">
                  <VBtn size="small" color="success" variant="tonal" @click="saveLookup('fleet.setup.filling-stations.update', s.id)" class="mr-1">{{ t('fleet_setup_index.common.save') }}</VBtn>
                  <VBtn size="small" variant="text" @click="cancelEdit">{{ t('fleet_setup_index.common.cancel') }}</VBtn>
                </template>
                <template v-else>
                  <VBtn size="small" variant="tonal" @click="startEdit(s)" class="mr-1">{{ t('fleet_setup_index.common.edit') }}</VBtn>
                  <VBtn size="small" color="error" variant="tonal" @click="del('fleet.setup.filling-stations.destroy', s.id, s.name)">{{ t('fleet_setup_index.common.delete') }}</VBtn>
                </template>
              </td>
            </tr>
            <tr v-if="!fillingStations.length"><td colspan="6" class="text-center text-medium-emphasis pa-4">{{ t('fleet_setup_index.common.no_data') }}</td></tr>
          </tbody>
        </VTable>
      </div>

      <!-- Card Types -->
      <div v-if="tab === 'cardTypes'" class="pa-4">
        <VForm @submit.prevent="addLookup('fleet.setup.card-types.store', cardTypeForm)" class="d-flex ga-2 mb-4 flex-wrap">
          <VTextField v-model="cardTypeForm.name" :label="t('fleet_setup_index.card_types.card_type')" variant="outlined" density="comfortable" hide-details style="max-width:250px" />
          <VTextField v-model="cardTypeForm.description" :label="t('fleet_setup_index.card_types.description')" variant="outlined" density="comfortable" hide-details style="max-width:300px" />
          <VBtn type="submit" color="primary" :loading="cardTypeForm.processing">{{ t('fleet_setup_index.common.add') }}</VBtn>
        </VForm>
        <VTable density="compact">
          <thead><tr><th>{{ t('fleet_setup_index.card_types.table_name') }}</th><th>{{ t('fleet_setup_index.card_types.table_description') }}</th><th>{{ t('fleet_setup_index.common.active') }}</th><th>{{ t('fleet_setup_index.common.actions') }}</th></tr></thead>
          <tbody>
            <tr v-for="template in cardTypes" :key="template.id">
              <td><VTextField v-if="editId===template.id" v-model="editData.name" density="compact" variant="outlined" hide-details style="max-width:180px" /><span v-else>{{ template.name }}</span></td>
              <td class="text-caption">{{ template.description ?? '—' }}</td>
              <td><VChip size="x-small" :color="template.active?'success':'grey'">{{ template.active ? t('fleet_setup_index.common.active') : t('fleet_setup_index.common.inactive') }}</VChip></td>
              <td class="text-right">
                <template v-if="editId===template.id">
                  <VBtn size="small" color="success" variant="tonal" @click="saveLookup('fleet.setup.card-types.update', template.id)" class="mr-1">{{ t('fleet_setup_index.common.save') }}</VBtn>
                  <VBtn size="small" variant="text" @click="cancelEdit">{{ t('fleet_setup_index.common.cancel') }}</VBtn>
                </template>
                <template v-else>
                  <VBtn size="small" variant="tonal" @click="startEdit(t)" class="mr-1">{{ t('fleet_setup_index.common.edit') }}</VBtn>
                  <VBtn size="small" color="error" variant="tonal" @click="del('fleet.setup.card-types.destroy', template.id, template.name)">{{ t('fleet_setup_index.common.delete') }}</VBtn>
                </template>
              </td>
            </tr>
            <tr v-if="!cardTypes.length"><td colspan="4" class="text-center text-medium-emphasis pa-4">{{ t('fleet_setup_index.common.no_data') }}</td></tr>
          </tbody>
        </VTable>
      </div>

      <!-- Licence Types -->
      <div v-if="tab === 'licenceTypes'" class="pa-4">
        <VForm @submit.prevent="addLookup('fleet.setup.licence-types.store', licenceTypeForm)" class="d-flex ga-2 mb-4 flex-wrap">
          <VTextField v-model="licenceTypeForm.name" :label="t('fleet_setup_index.licence_types.licence_type')" variant="outlined" density="comfortable" hide-details style="max-width:250px" />
          <VTextField v-model="licenceTypeForm.description" :label="t('fleet_setup_index.licence_types.description')" variant="outlined" density="comfortable" hide-details style="max-width:260px" />
          <VTextField v-model="licenceTypeForm.validity_months" :label="t('fleet_setup_index.licence_types.validity_months')" type="number" variant="outlined" density="comfortable" hide-details style="max-width:160px" />
          <VBtn type="submit" color="primary" :loading="licenceTypeForm.processing">{{ t('fleet_setup_index.common.add') }}</VBtn>
        </VForm>
        <VTable density="compact">
          <thead><tr><th>{{ t('fleet_setup_index.licence_types.table_name') }}</th><th>{{ t('fleet_setup_index.licence_types.table_description') }}</th><th>{{ t('fleet_setup_index.licence_types.table_validity') }}</th><th>{{ t('fleet_setup_index.common.active') }}</th><th>{{ t('fleet_setup_index.common.actions') }}</th></tr></thead>
          <tbody>
            <tr v-for="template in licenceTypes" :key="template.id">
              <td><VTextField v-if="editId===template.id" v-model="editData.name" density="compact" variant="outlined" hide-details style="max-width:180px" /><span v-else>{{ template.name }}</span></td>
              <td class="text-caption">{{ template.description ?? '—' }}</td>
              <td class="text-caption">{{ template.validity_months ? `${template.validity_months} ${t('fleet_setup_index.licence_types.months')}` : '—' }}</td>
              <td><VChip size="x-small" :color="template.active?'success':'grey'">{{ template.active ? t('fleet_setup_index.common.active') : t('fleet_setup_index.common.inactive') }}</VChip></td>
              <td class="text-right">
                <template v-if="editId===template.id">
                  <VBtn size="small" color="success" variant="tonal" @click="saveLookup('fleet.setup.licence-types.update', template.id)" class="mr-1">{{ t('fleet_setup_index.common.save') }}</VBtn>
                  <VBtn size="small" variant="text" @click="cancelEdit">{{ t('fleet_setup_index.common.cancel') }}</VBtn>
                </template>
                <template v-else>
                  <VBtn size="small" variant="tonal" @click="startEdit(t)" class="mr-1">{{ t('fleet_setup_index.common.edit') }}</VBtn>
                  <VBtn size="small" color="error" variant="tonal" @click="del('fleet.setup.licence-types.destroy', template.id, template.name)">{{ t('fleet_setup_index.common.delete') }}</VBtn>
                </template>
              </td>
            </tr>
            <tr v-if="!licenceTypes.length"><td colspan="5" class="text-center text-medium-emphasis pa-4">{{ t('fleet_setup_index.common.no_data') }}</td></tr>
          </tbody>
        </VTable>
      </div>

      <!-- Approval Steps -->
      <div v-if="tab === 'approvalSteps'" class="pa-4">
        <div class="d-flex justify-end mb-3">
          <VBtn color="primary" prepend-icon="tabler-plus" size="small" @click="openAddStep()">{{ t('fleet_setup_index.approval_steps.add_step') }}</VBtn>
        </div>
        <VTable density="compact">
          <thead><tr><th>{{ t('fleet_setup_index.approval_steps.table_step') }}</th><th>{{ t('fleet_setup_index.approval_steps.table_label') }}</th><th>{{ t('fleet_setup_index.approval_steps.table_approver_type') }}</th><th>{{ t('fleet_setup_index.approval_steps.table_role_employee_unit') }}</th><th>{{ t('fleet_setup_index.approval_steps.table_skip_local') }}</th><th>{{ t('fleet_setup_index.approval_steps.table_active') }}</th><th>{{ t('fleet_setup_index.common.actions') }}</th></tr></thead>
          <tbody>
            <tr v-for="s in approvalSteps" :key="s.id">
              <td>{{ s.step_number }}</td>
              <td><span class="font-weight-medium">{{ s.step_label }}</span></td>
              <td>{{ s.approver_type }}</td>
              <td class="text-caption">{{ s.role_name ?? s.employee_name ?? s.unit_name ?? '—' }}</td>
              <td>
                <VChip size="x-small" :color="s.check_local_trip ? 'warning' : 'grey'">
                  {{ s.check_local_trip ? t('fleet_setup_index.approval_steps.skip_if_local') : t('fleet_setup_index.approval_steps.always_required') }}
                </VChip>
              </td>
              <td><VChip size="x-small" :color="s.is_active?'success':'grey'">{{ s.is_active ? t('fleet_setup_index.common.active') : t('fleet_setup_index.common.inactive') }}</VChip></td>
              <td class="text-right">
                <VBtn size="small" variant="tonal" @click="openEditStep(s)" class="mr-1">{{ t('fleet_setup_index.common.edit') }}</VBtn>
                <VBtn size="small" color="error" variant="tonal" @click="del('fleet.setup.approval-steps.destroy', s.id, s.step_label)">{{ t('fleet_setup_index.common.delete') }}</VBtn>
              </td>
            </tr>
            <tr v-if="!approvalSteps.length"><td colspan="7" class="text-center text-medium-emphasis pa-4">{{ t('fleet_setup_index.approval_steps.no_steps') }}</td></tr>
          </tbody>
        </VTable>
      </div>

      <!-- Settings -->
      <div v-if="tab === 'settings'" class="pa-8 text-center">
        <VIcon size="48" color="grey" class="mb-3">tabler-settings</VIcon>
        <div class="mb-4">{{ t('fleet_setup_index.settings_tab.message') }}</div>
        <VBtn color="primary" :href="route('fleet.settings')">{{ t('fleet_setup_index.settings_tab.open_settings') }}</VBtn>
      </div>
    </VCard>

    <VCard v-else class="text-center pa-12">
      <VIcon size="64" color="grey-lighten-1" class="mb-3">tabler-settings</VIcon>
      <div class="text-h6 mb-2">{{ t('fleet_setup_index.empty_state.title') }}</div>
      <div class="text-medium-emphasis">{{ t('fleet_setup_index.empty_state.description') }}</div>
    </VCard>

    <!-- Approval Step Dialog -->
    <VDialog v-model="stepDialog" max-width="520" persistent>
      <VCard>
        <VCardTitle class="pa-4">{{ editingStepId ? t('fleet_setup_index.approval_steps.edit_step') : t('fleet_setup_index.approval_steps.add_step_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="4">
              <VTextField v-model="stepForm.step_number" :label="t('fleet_setup_index.approval_steps.step_number')" type="number" variant="outlined" density="comfortable" :error-messages="stepForm.errors.step_number" />
            </VCol>
            <VCol cols="8">
              <VTextField v-model="stepForm.step_label" :label="t('fleet_setup_index.approval_steps.step_label')" variant="outlined" density="comfortable" :error-messages="stepForm.errors.step_label" />
            </VCol>
            <VCol cols="12">
              <VSelect v-model="stepForm.approver_type" :label="t('fleet_setup_index.approval_steps.approver_type')" variant="outlined" density="comfortable"
                :items="['role','employee','line_manager','none']" />
            </VCol>
            <VCol cols="12" v-if="stepForm.approver_type==='role'">
              <VSelect v-model="stepForm.role_name" :label="t('fleet_setup_index.approval_steps.role')" variant="outlined" density="comfortable"
                :items="roles" item-title="name" item-value="name" clearable />
            </VCol>
            <VCol cols="12" v-if="stepForm.approver_type==='employee'">
              <VAutocomplete v-model="stepForm.employee_id" :items="users" item-title="name" item-value="id" :label="t('fleet_setup_index.approval_steps.employee')" variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="12">
              <VSwitch v-model="stepForm.check_local_trip" :label="t('fleet_setup_index.approval_steps.skip_local_trip')" density="compact" hide-details color="warning" class="mb-2" />
            </VCol>
            <VCol cols="12">
              <VSwitch v-model="stepForm.is_active" :label="t('fleet_setup_index.approval_steps.active_switch')" density="compact" hide-details color="success" />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="stepDialog=false">{{ t('fleet_setup_index.common.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="stepForm.processing" @click="submitStep">
            {{ editingStepId ? t('fleet_setup_index.approval_steps.save_changes') : t('fleet_setup_index.common.add') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Delete Dialog -->
    <VDialog v-model="deleteDialog" max-width="420">
      <VCard>
        <VCardTitle class="pa-4">{{ t('fleet_setup_index.delete_dialog.title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          {{ t('fleet_setup_index.delete_dialog.message', { label: deleteItemLabel }) }}
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="tonal" @click="deleteDialog = false">{{ t('fleet_setup_index.delete_dialog.cancel') }}</VBtn>
          <VBtn color="error" variant="flat" @click="executeDel">{{ t('fleet_setup_index.delete_dialog.confirm') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
