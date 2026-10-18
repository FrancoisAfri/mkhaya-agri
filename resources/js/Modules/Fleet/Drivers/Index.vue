<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Driver {
  id: number; user_id: number; name: string; email: string | null
  licence_number: string | null; licence_type: string | null; licence_expiry: string | null
  pdp_number: string | null; pdp_expiry: string | null
  status: string; restriction_reason: string | null
  total_trips: number; total_km: number; incidents_count: number; bookings_count: number
  licence_expired: boolean
}
interface UserOption { id: number; name: string; licence_number: string | null; licence_code: string | null }
interface HrPerson {
  id: number; user_id: number; name: string; email: string
  eligible_to_drive: boolean; drivers_licence_number: string | null; drivers_licence_code: string | null
}

const props = defineProps<{
  drivers:   Driver[]
  users:     UserOption[]
  hrPeople:  HrPerson[]
}>()

const tab = ref('drivers')

// ── Driver dialog ────────────────────────────────────────────────────────────
const dialog  = ref(false)
const editing = ref<Driver | null>(null)

const form = useForm({
  user_id:          null as number | null,
  licence_number:   '',
  licence_type:     'EB',
  licence_expiry:   '',
  pdp_number:       '',
  pdp_expiry:       '',
  status:           'active',
  restriction_reason: '',
})

function openAdd()  { editing.value = null; form.reset(); form.licence_type = 'EB'; form.status = 'active'; dialog.value = true }
function openEdit(d: Driver) {
  editing.value = d
  form.licence_number    = d.licence_number ?? ''
  form.licence_type      = d.licence_type ?? 'EB'
  form.licence_expiry    = d.licence_expiry ?? ''
  form.pdp_number        = d.pdp_number ?? ''
  form.pdp_expiry        = d.pdp_expiry ?? ''
  form.status            = d.status
  form.restriction_reason= d.restriction_reason ?? ''
  dialog.value = true
}

function save() {
  if (editing.value) {
    form.patch(route('fleet.drivers.update', editing.value.id), {
      preserveScroll: true,
      onSuccess: () => { dialog.value = false; form.reset() },
    })
  } else {
    form.post(route('fleet.drivers.store'), {
      preserveScroll: true,
      onSuccess: () => { dialog.value = false; form.reset() },
    })
  }
}

const deleteDialog = ref(false)
const toDelete = ref<Driver | null>(null)
function confirmDelete(d: Driver) { toDelete.value = d; deleteDialog.value = true }
function doDelete() {
  if (!toDelete.value) return
  router.delete(route('fleet.drivers.destroy', toDelete.value.id), {
    preserveScroll: true,
    onSuccess: () => { deleteDialog.value = false },
  })
}

// ── Eligibility management ───────────────────────────────────────────────────
const eligSearch = ref('')
const eligFilter = ref<'all' | 'eligible' | 'not_eligible'>('all')

const filteredPeople = computed(() => {
  return props.hrPeople.filter(p => {
    const nameMatch = !eligSearch.value || p.name.toLowerCase().includes(eligSearch.value.toLowerCase()) || p.email.toLowerCase().includes(eligSearch.value.toLowerCase())
    const flagMatch = eligFilter.value === 'all' || (eligFilter.value === 'eligible' ? p.eligible_to_drive : !p.eligible_to_drive)
    return nameMatch && flagMatch
  })
})

function toggleEligible(person: HrPerson, value: boolean) {
  router.patch(route('fleet.drivers.eligible.toggle', person.id), { eligible: value }, { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4">
    <!-- Header -->
    <div class="d-flex align-center mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('fleet_drivers_index.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('fleet_drivers_index.description') }}</p>
      </div>
      <VSpacer />
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openAdd">{{ t('fleet_drivers_index.add_driver') }}</VBtn>
    </div>

    <!-- Tabs -->
    <VTabs v-model="tab" color="primary" class="mb-4">
      <VTab value="drivers">
        <VIcon start>tabler-steering-wheel</VIcon>
        {{ t('fleet_drivers_index.tabs.drivers') }}
        <VChip size="x-small" class="ml-1">{{ drivers.length }}</VChip>
      </VTab>
      <VTab value="eligibility">
        <VIcon start>tabler-user-check</VIcon>
        {{ t('fleet_drivers_index.tabs.eligibility') }}
        <VChip size="x-small" color="success" class="ml-1">{{ hrPeople.filter(p => p.eligible_to_drive).length }}</VChip>
      </VTab>
    </VTabs>

    <VWindow v-model="tab">

      <!-- Registered Drivers -->
      <VWindowItem value="drivers">
        <VCard>
          <VTable density="comfortable" hover>
            <thead>
              <tr>
                <th>{{ t('fleet_drivers_index.table.name') }}</th>
                <th>{{ t('fleet_drivers_index.table.licence') }}</th>
                <th>{{ t('fleet_drivers_index.table.type') }}</th>
                <th>{{ t('fleet_drivers_index.table.expiry') }}</th>
                <th>{{ t('fleet_drivers_index.table.trips_km') }}</th>
                <th>{{ t('fleet_drivers_index.table.status') }}</th>
                <th>{{ t('fleet_drivers_index.table.actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="d in drivers" :key="d.id">
                <td>
                  <div class="font-weight-medium">{{ d.name }}</div>
                  <div class="text-caption text-medium-emphasis">{{ d.email }}</div>
                </td>
                <td>{{ d.licence_number ?? '—' }}</td>
                <td>{{ d.licence_type ?? '—' }}</td>
                <td>
                  <VChip v-if="d.licence_expired" size="x-small" color="error" label>{{ t('fleet_drivers_index.expired') }}: {{ d.licence_expiry }}</VChip>
                  <span v-else>{{ d.licence_expiry ?? '—' }}</span>
                </td>
                <td class="text-caption">{{ d.total_trips }} {{ t('fleet_drivers_index.trips') }} · {{ d.total_km?.toLocaleString() }} km</td>
                <td>
                  <VChip size="x-small" label :color="d.status === 'active' ? 'success' : d.status === 'suspended' ? 'warning' : 'grey'" class="text-capitalize">{{ d.status }}</VChip>
                </td>
                <td>
                  <VBtn icon size="x-small" variant="text" color="primary" @click="openEdit(d)"><VIcon>tabler-pencil</VIcon></VBtn>
                  <VBtn icon size="x-small" variant="text" color="error" @click="confirmDelete(d)"><VIcon>tabler-trash</VIcon></VBtn>
                </td>
              </tr>
              <tr v-if="!drivers.length">
                <td colspan="7" class="text-center text-medium-emphasis pa-8">{{ t('fleet_drivers_index.no_drivers') }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VWindowItem>

      <!-- Eligibility Management -->
      <VWindowItem value="eligibility">
        <VCard flat>
          <VCardText class="pa-3">
            <VRow dense>
              <VCol cols="12" sm="6">
                <VTextField v-model="eligSearch" :label="t('fleet_drivers_index.search_placeholder')" prepend-inner-icon="tabler-search"
                  clearable density="compact" variant="outlined" hide-details />
              </VCol>
              <VCol cols="12" sm="4">
                <VSelect v-model="eligFilter" :label="t('fleet_drivers_index.filter')" density="compact" variant="outlined" hide-details
                  :items="[
                    { title: t('fleet_drivers_index.filter_all'), value: 'all' },
                    { title: t('fleet_drivers_index.filter_eligible'), value: 'eligible' },
                    { title: t('fleet_drivers_index.filter_not_eligible'), value: 'not_eligible' },
                  ]" />
              </VCol>
            </VRow>
          </VCardText>
          <VTable density="comfortable">
            <thead>
              <tr>
                <th>{{ t('fleet_drivers_index.table.employee') }}</th>
                <th>{{ t('fleet_drivers_index.table.licence_number') }}</th>
                <th>{{ t('fleet_drivers_index.table.licence_code') }}</th>
                <th>{{ t('fleet_drivers_index.table.eligible') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in filteredPeople" :key="p.id">
                <td>
                  <div class="font-weight-medium">{{ p.name }}</div>
                  <div class="text-caption text-medium-emphasis">{{ p.email }}</div>
                </td>
                <td>{{ p.drivers_licence_number ?? '—' }}</td>
                <td>{{ p.drivers_licence_code ?? '—' }}</td>
                <td>
                  <VSwitch
                    :model-value="p.eligible_to_drive"
                    color="success"
                    density="compact"
                    hide-details
                    @update:model-value="val => toggleEligible(p, !!val)"
                  />
                </td>
              </tr>
              <tr v-if="!filteredPeople.length">
                <td colspan="4" class="text-center text-medium-emphasis pa-6">{{ t('fleet_drivers_index.no_employees') }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VWindowItem>

    </VWindow>

    <!-- Add/Edit Dialog -->
    <VDialog v-model="dialog" max-width="560" persistent>
      <VCard :title="editing ? t('fleet_drivers_index.edit_driver') : t('fleet_drivers_index.add_driver_title')">
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12" v-if="!editing">
              <VAutocomplete v-model="form.user_id" :label="t('fleet_drivers_index.form.link_employee') + ' *'" :items="users"
                item-title="name" item-value="id" variant="outlined" density="comfortable"
                :error-messages="form.errors.user_id" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.licence_number" :label="t('fleet_drivers_index.form.licence_number')" variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="form.licence_type" :label="t('fleet_drivers_index.form.licence_type')" variant="outlined" density="comfortable"
                :items="['A','B','C','C1','EB','EC','EC1','PrDP']" :error-messages="form.errors.licence_type" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.licence_expiry" :label="t('fleet_drivers_index.form.licence_expiry')" type="date" variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.pdp_number" :label="t('fleet_drivers_index.form.pdp_number')" variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.pdp_expiry" :label="t('fleet_drivers_index.form.pdp_expiry')" type="date" variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="form.status" :label="t('fleet_drivers_index.form.status')" variant="outlined" density="comfortable"
                :items="['active','suspended','inactive']" />
            </VCol>
            <VCol cols="12" v-if="form.status === 'suspended'">
              <VTextarea v-model="form.restriction_reason" :label="t('fleet_drivers_index.form.restriction_reason')" variant="outlined" density="comfortable" rows="2" />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="dialog=false">{{ t('fleet_drivers_index.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="form.processing" @click="save">{{ editing ? t('fleet_drivers_index.update') : t('fleet_drivers_index.add_driver') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Delete Confirm -->
    <VDialog v-model="deleteDialog" max-width="400" persistent>
      <VCard>
        <VCardTitle class="pa-4">{{ t('fleet_drivers_index.remove_driver') }}</VCardTitle>
        <VCardText class="pa-4">{{ t('fleet_drivers_index.remove_message', { name: toDelete?.name }) }}</VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="deleteDialog=false">{{ t('fleet_drivers_index.cancel') }}</VBtn>
          <VBtn color="error" variant="flat" @click="doDelete">{{ t('fleet_drivers_index.remove') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
