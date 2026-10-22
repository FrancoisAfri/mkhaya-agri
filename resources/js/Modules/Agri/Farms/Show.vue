<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import PageErrors from '../Components/PageErrors.vue'

type Farm = {
  id: string; code: string; name: string; farm_type: string; location: string | null; address: string | null
  area: number | null; area_unit: string; latitude: number | null; longitude: number | null
  timezone: string; currency: string; status: string
  fields_count: number; locations_count: number; members_count: number
  fields_hectares: number; has_history: boolean
}
type Field = {
  id: string; code: string; name: string; land_use_type: string; area: number | null; area_unit: string
  hectares: number | null; soil_type: string | null; irrigation: string | null
  latitude: number | null; longitude: number | null; status: string; notes: string | null
}
type Place = { id: string; name: string; location_type: string; latitude: number | null; longitude: number | null; status: string; notes: string | null }
type Member = { id: string; user_id: number; name: string; email: string | null; role: string; status: string; since: string | null; alert_emails: boolean }

const props = defineProps<{
  farm: Farm
  fields: Field[]
  locations: Place[]
  members: Member[]
  people: Array<{ id: number; name: string; email: string }>
  options: {
    farm_types: string[]; area_units: string[]; statuses: string[]; land_use_types: string[]
    irrigation: string[]; location_types: string[]; member_roles: string[]
  }
  can: { manage: boolean; manage_fields: boolean; manage_access: boolean }
}>()

const { t } = useI18n()
const tab = ref('fields')
const label = (s: string | null) => (s ?? '').replace(/_/g, ' ').replace(/^\w/, c => c.toUpperCase())
const statusColor = (s: string) => ({ active: 'success', inactive: 'warning', archived: 'secondary', suspended: 'error' } as Record<string, string>)[s] ?? 'default'
const operational = computed(() => props.farm.status === 'active')
const keep = { preserveScroll: true }

// ── Farm details ────────────────────────────────────────────────────────────
const farmDialog = ref(false)
const farmForm = useForm({ ...props.farm })
function editFarm() {
  Object.assign(farmForm, { ...props.farm, location: props.farm.location ?? '', address: props.farm.address ?? '' })
  farmForm.clearErrors()
  farmDialog.value = true
}
function saveFarm() {
  farmForm.transform(d => ({
    code: d.code, name: d.name, farm_type: d.farm_type, location: d.location, address: d.address,
    area: d.area, area_unit: d.area_unit, latitude: d.latitude, longitude: d.longitude, timezone: d.timezone, currency: d.currency,
  })).patch(route('agri.farms.update', props.farm.id), { ...keep, onSuccess: () => { farmDialog.value = false } })
}
function setStatus(status: string) {
  const words: Record<string, string> = {
    archived: 'Archive this farm? It leaves day-to-day work but all of its history stays in reports.',
    inactive: 'Set this farm inactive? No new work can be recorded against it until it is reactivated.',
    active: 'Reactivate this farm?',
  }
  if (confirm(words[status])) router.post(route('agri.farms.status', props.farm.id), { status }, keep)
}
function destroyFarm() {
  if (confirm('Delete this farm permanently? This is only possible while it has no fields, locations or other records.'))
    router.delete(route('agri.farms.destroy', props.farm.id))
}

// ── Fields ──────────────────────────────────────────────────────────────────
const fieldDialog = ref(false)
const fieldEditing = ref<string | null>(null)
const fieldForm = useForm({
  code: '', name: '', land_use_type: 'field', area: null as number | null, area_unit: 'ha',
  soil_type: '', irrigation: null as string | null, latitude: null as number | null, longitude: null as number | null, notes: '',
})
function newField() { fieldEditing.value = null; fieldForm.reset(); fieldForm.clearErrors(); fieldDialog.value = true }
function editField(f: Field) {
  fieldEditing.value = f.id
  Object.assign(fieldForm, {
    code: f.code, name: f.name, land_use_type: f.land_use_type, area: f.area, area_unit: f.area_unit,
    soil_type: f.soil_type ?? '', irrigation: f.irrigation, latitude: f.latitude, longitude: f.longitude, notes: f.notes ?? '',
  })
  fieldForm.clearErrors()
  fieldDialog.value = true
}
function saveField() {
  const done = { ...keep, onSuccess: () => { fieldDialog.value = false } }
  fieldEditing.value
    ? fieldForm.patch(route('agri.farms.fields.update', [props.farm.id, fieldEditing.value]), done)
    : fieldForm.post(route('agri.farms.fields.store', props.farm.id), done)
}
function fieldStatus(f: Field, status: string) {
  router.patch(route('agri.farms.fields.update', [props.farm.id, f.id]), { ...f, status, irrigation: f.irrigation, soil_type: f.soil_type, notes: f.notes }, keep)
}

// ── Locations ───────────────────────────────────────────────────────────────
const placeDialog = ref(false)
const placeEditing = ref<string | null>(null)
const placeForm = useForm({ name: '', location_type: 'camp', latitude: null as number | null, longitude: null as number | null, notes: '' })
function newPlace() { placeEditing.value = null; placeForm.reset(); placeForm.clearErrors(); placeDialog.value = true }
function editPlace(p: Place) {
  placeEditing.value = p.id
  Object.assign(placeForm, { name: p.name, location_type: p.location_type, latitude: p.latitude, longitude: p.longitude, notes: p.notes ?? '' })
  placeForm.clearErrors()
  placeDialog.value = true
}
function savePlace() {
  const done = { ...keep, onSuccess: () => { placeDialog.value = false } }
  placeEditing.value
    ? placeForm.patch(route('agri.farms.locations.update', [props.farm.id, placeEditing.value]), done)
    : placeForm.post(route('agri.farms.locations.store', props.farm.id), done)
}

// ── Access ──────────────────────────────────────────────────────────────────
const grantForm = useForm({ user_id: null as number | null, role: 'worker' })
const grantable = computed(() => props.people.filter(p => !props.members.some(m => m.user_id === p.id && m.status === 'active')))
function grant() { grantForm.post(route('agri.farms.access.store', props.farm.id), { ...keep, onSuccess: () => grantForm.reset() }) }
function updateMember(m: Member, data: Record<string, string | boolean>) {
  router.patch(route('agri.farms.access.update', [props.farm.id, m.id]), data, keep)
}
function revoke(m: Member) {
  if (confirm(`Remove ${m.name}'s access to ${props.farm.name}?`)) router.delete(route('agri.farms.access.destroy', [props.farm.id, m.id]), keep)
}
</script>

<template>
  <div>
    <PageErrors />
    <div class="mb-2">
      <Link :href="route('agri.farms.index')" class="text-body-2"><VIcon icon="tabler-arrow-left" size="16" /> Farms &amp; Fields</Link>
    </div>
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-6">
      <div>
        <h4 class="text-h4 mb-1">
          {{ farm.name }}
          <VChip :color="statusColor(farm.status)" size="small" variant="tonal" class="ms-2">{{ label(farm.status) }}</VChip>
        </h4>
        <p class="text-body-1 text-medium-emphasis mb-0">
          {{ farm.code }} · {{ label(farm.farm_type) }}<template v-if="farm.location"> · {{ farm.location }}</template>
        </p>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" prepend-icon="tabler-map-2" @click="router.visit(route('agri.farms.map', farm.id))">{{ t('agri.field_map.open_map') }}</VBtn>
        <template v-if="can.manage">
        <VBtn variant="tonal" prepend-icon="tabler-edit" @click="editFarm">Edit</VBtn>
        <VMenu>
          <template #activator="{ props: p }"><VBtn v-bind="p" icon variant="text" aria-label="More actions"><VIcon icon="tabler-dots-vertical" /></VBtn></template>
          <VList density="compact">
            <VListItem v-if="farm.status !== 'active'" prepend-icon="tabler-player-play" title="Reactivate" @click="setStatus('active')" />
            <VListItem v-if="farm.status === 'active'" prepend-icon="tabler-player-pause" title="Set inactive" @click="setStatus('inactive')" />
            <VListItem v-if="farm.status !== 'archived'" prepend-icon="tabler-archive" title="Archive" @click="setStatus('archived')" />
            <VListItem v-if="!farm.has_history" prepend-icon="tabler-trash" base-color="error" title="Delete" @click="destroyFarm" />
          </VList>
        </VMenu>
        </template>
      </div>
    </div>

    <VAlert v-if="!operational" type="warning" variant="tonal" class="mb-4">
      This farm is {{ farm.status }}. Its records stay available, but no new fields, locations or work can be added until it is reactivated.
    </VAlert>

    <VRow class="mb-2">
      <VCol v-for="k in [
        { label: 'Registered area', value: farm.area !== null ? `${farm.area.toLocaleString()} ${farm.area_unit}` : '—' },
        { label: 'Under fields', value: `${farm.fields_hectares.toLocaleString()} ha` },
        { label: 'Fields', value: fields.filter(f => f.status !== 'archived').length },
        { label: 'Timezone · currency', value: `${farm.timezone} · ${farm.currency}` },
      ]" :key="k.label" cols="6" md="3">
        <VCard><VCardText><div class="text-h6">{{ k.value }}</div><div class="text-body-2 text-medium-emphasis">{{ k.label }}</div></VCardText></VCard>
      </VCol>
    </VRow>

    <VCard>
      <VTabs v-model="tab">
        <VTab value="fields">Fields ({{ fields.length }})</VTab>
        <VTab value="locations">Locations ({{ locations.length }})</VTab>
        <VTab v-if="can.manage_access" value="access">People &amp; access ({{ members.length }})</VTab>
      </VTabs>
      <VDivider />

      <VWindow v-model="tab">
        <!-- Fields -->
        <VWindowItem value="fields">
          <VCardText class="d-flex justify-end" v-if="can.manage_fields">
            <VBtn color="primary" prepend-icon="tabler-plus" :disabled="!operational" @click="newField">Add field</VBtn>
          </VCardText>
          <VTable>
            <thead><tr><th>Code</th><th>Name</th><th>Use</th><th class="text-end">Area</th><th>Soil · irrigation</th><th>Status</th><th /></tr></thead>
            <tbody>
              <tr v-for="f in fields" :key="f.id" :class="{ 'text-disabled': f.status === 'archived' }">
                <td class="font-weight-medium">{{ f.code }}</td>
                <td>{{ f.name }}</td>
                <td>{{ label(f.land_use_type) }}</td>
                <td class="text-end">{{ f.area !== null ? `${f.area.toLocaleString()} ${f.area_unit}` : '—' }}</td>
                <td>{{ [f.soil_type, label(f.irrigation)].filter(Boolean).join(' · ') || '—' }}</td>
                <td><VChip :color="statusColor(f.status)" size="small" variant="tonal">{{ label(f.status) }}</VChip></td>
                <td class="text-end text-no-wrap">
                  <VBtn icon variant="text" size="small" :aria-label="t('agri.field_map.field_on_map')" :title="t('agri.field_map.field_on_map')"
                    @click="router.visit(route('agri.farms.map', { farm: farm.id, field: f.id }))"><VIcon icon="tabler-map-pin" /></VBtn>
                  <VMenu v-if="can.manage_fields">
                    <template #activator="{ props: p }"><VBtn v-bind="p" icon variant="text" size="small" aria-label="Field actions"><VIcon icon="tabler-dots-vertical" /></VBtn></template>
                    <VList density="compact">
                      <VListItem prepend-icon="tabler-edit" title="Edit" @click="editField(f)" />
                      <VListItem v-if="f.status !== 'archived'" prepend-icon="tabler-archive" title="Archive" @click="fieldStatus(f, 'archived')" />
                      <VListItem v-else prepend-icon="tabler-player-play" title="Reactivate" @click="fieldStatus(f, 'active')" />
                    </VList>
                  </VMenu>
                </td>
              </tr>
              <tr v-if="!fields.length"><td colspan="7" class="text-center text-medium-emphasis py-6">No fields yet. Add the fields, paddocks, orchards or greenhouses on this farm.</td></tr>
            </tbody>
          </VTable>
        </VWindowItem>

        <!-- Locations -->
        <VWindowItem value="locations">
          <VCardText class="d-flex justify-end" v-if="can.manage_fields">
            <VBtn color="primary" prepend-icon="tabler-plus" :disabled="!operational" @click="newPlace">Add location</VBtn>
          </VCardText>
          <VTable>
            <thead><tr><th>Name</th><th>Type</th><th>Coordinates</th><th>Status</th><th v-if="can.manage_fields" /></tr></thead>
            <tbody>
              <tr v-for="p in locations" :key="p.id">
                <td class="font-weight-medium">{{ p.name }}</td>
                <td>{{ label(p.location_type) }}</td>
                <td>{{ p.latitude !== null ? `${p.latitude}, ${p.longitude}` : '—' }}</td>
                <td><VChip :color="statusColor(p.status)" size="small" variant="tonal">{{ label(p.status) }}</VChip></td>
                <td v-if="can.manage_fields" class="text-end"><VBtn icon variant="text" size="small" aria-label="Edit location" @click="editPlace(p)"><VIcon icon="tabler-edit" /></VBtn></td>
              </tr>
              <tr v-if="!locations.length"><td colspan="5" class="text-center text-medium-emphasis py-6">No locations yet: camps, kraals, stores, sheds, dams, boreholes.</td></tr>
            </tbody>
          </VTable>
        </VWindowItem>

        <!-- Access -->
        <VWindowItem v-if="can.manage_access" value="access">
          <VCardText>
            <p class="text-body-2 text-medium-emphasis">
              Only the people listed here (and organisation administrators) can see this farm and its records.
            </p>
            <div class="d-flex flex-wrap gap-3 align-start">
              <VAutocomplete v-model="grantForm.user_id" :items="grantable" item-title="name" item-value="id" label="Person"
                density="compact" style="min-width: 280px" :error-messages="grantForm.errors.user_id" />
              <VSelect v-model="grantForm.role" :items="options.member_roles.map(r => ({ title: label(r), value: r }))" label="Role"
                density="compact" style="max-width: 180px" />
              <VBtn color="primary" :disabled="!grantForm.user_id" :loading="grantForm.processing" @click="grant">Give access</VBtn>
            </div>
          </VCardText>
          <VTable>
            <thead><tr><th>Person</th><th>Role</th><th>Status</th><th>Since</th><th /></tr></thead>
            <tbody>
              <tr v-for="m in members" :key="m.id">
                <td><div class="font-weight-medium">{{ m.name }}</div><div class="text-caption text-medium-emphasis">{{ m.email }}</div></td>
                <td style="max-width: 170px">
                  <VSelect :model-value="m.role" :items="options.member_roles.map(r => ({ title: label(r), value: r }))" density="compact"
                    hide-details @update:model-value="v => updateMember(m, { role: v as string })" />
                </td>
                <td><VChip :color="statusColor(m.status)" size="small" variant="tonal">{{ label(m.status) }}</VChip></td>
                <td>{{ m.since }}</td>
                <td class="text-end">
                  <VMenu>
                    <template #activator="{ props: p }"><VBtn v-bind="p" icon variant="text" size="small" aria-label="Access actions"><VIcon icon="tabler-dots-vertical" /></VBtn></template>
                    <VList density="compact">
                      <VListItem v-if="['manager', 'supervisor'].includes(m.role)" :prepend-icon="m.alert_emails ? 'tabler-mail-off' : 'tabler-mail'"
                        :title="m.alert_emails ? 'Stop alert emails' : 'Send alert emails'" @click="updateMember(m, { alert_emails: !m.alert_emails })" />
                      <VListItem v-if="m.status === 'active'" prepend-icon="tabler-player-pause" title="Suspend" @click="updateMember(m, { status: 'suspended' })" />
                      <VListItem v-else prepend-icon="tabler-player-play" title="Reinstate" @click="updateMember(m, { status: 'active' })" />
                      <VListItem prepend-icon="tabler-trash" base-color="error" title="Remove" @click="revoke(m)" />
                    </VList>
                  </VMenu>
                </td>
              </tr>
              <tr v-if="!members.length"><td colspan="5" class="text-center text-medium-emphasis py-6">Nobody has been given access to this farm yet.</td></tr>
            </tbody>
          </VTable>
        </VWindowItem>
      </VWindow>
    </VCard>

    <!-- Farm dialog -->
    <VDialog v-model="farmDialog" max-width="720">
      <VCard title="Edit farm">
        <VCardText>
          <VRow>
            <VCol cols="12" sm="4"><VTextField v-model="farmForm.code" label="Farm code *" :error-messages="farmForm.errors.code" /></VCol>
            <VCol cols="12" sm="8"><VTextField v-model="farmForm.name" label="Farm name *" :error-messages="farmForm.errors.name" /></VCol>
            <VCol cols="12" sm="6"><VSelect v-model="farmForm.farm_type" label="Farm type *" :items="options.farm_types.map(t => ({ title: label(t), value: t }))" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="farmForm.location" label="District / nearest town" /></VCol>
            <VCol cols="12"><VTextarea v-model="farmForm.address" label="Address" rows="2" /></VCol>
            <VCol cols="8" sm="4"><VTextField v-model.number="farmForm.area" type="number" label="Total area" :error-messages="farmForm.errors.area" /></VCol>
            <VCol cols="4" sm="2"><VSelect v-model="farmForm.area_unit" label="Unit" :items="options.area_units" /></VCol>
            <VCol cols="6" sm="3"><VTextField v-model.number="farmForm.latitude" type="number" label="Latitude" :error-messages="farmForm.errors.latitude" /></VCol>
            <VCol cols="6" sm="3"><VTextField v-model.number="farmForm.longitude" type="number" label="Longitude" :error-messages="farmForm.errors.longitude" /></VCol>
            <VCol cols="8"><VTextField v-model="farmForm.timezone" label="Timezone *" :error-messages="farmForm.errors.timezone" /></VCol>
            <VCol cols="4"><VTextField v-model="farmForm.currency" label="Currency *" maxlength="3" :error-messages="farmForm.errors.currency" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="farmDialog = false">Cancel</VBtn><VBtn color="primary" :loading="farmForm.processing" @click="saveFarm">Save</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Field dialog -->
    <VDialog v-model="fieldDialog" max-width="680">
      <VCard :title="fieldEditing ? 'Edit field' : 'Add field'">
        <VCardText>
          <VRow>
            <VCol cols="12" sm="4"><VTextField v-model="fieldForm.code" label="Field code *" :error-messages="fieldForm.errors.code" hint="Unique on this farm" persistent-hint /></VCol>
            <VCol cols="12" sm="8"><VTextField v-model="fieldForm.name" label="Name *" :error-messages="fieldForm.errors.name" /></VCol>
            <VCol cols="12" sm="6"><VSelect v-model="fieldForm.land_use_type" label="Use *" :items="options.land_use_types.map(t => ({ title: label(t), value: t }))" /></VCol>
            <VCol cols="8" sm="4"><VTextField v-model.number="fieldForm.area" type="number" label="Area" :error-messages="fieldForm.errors.area" /></VCol>
            <VCol cols="4" sm="2"><VSelect v-model="fieldForm.area_unit" label="Unit" :items="options.area_units" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="fieldForm.soil_type" label="Soil type" /></VCol>
            <VCol cols="12" sm="6"><VSelect v-model="fieldForm.irrigation" label="Irrigation" clearable :items="options.irrigation.map(t => ({ title: label(t), value: t }))" /></VCol>
            <VCol cols="6"><VTextField v-model.number="fieldForm.latitude" type="number" label="Latitude" :error-messages="fieldForm.errors.latitude" /></VCol>
            <VCol cols="6"><VTextField v-model.number="fieldForm.longitude" type="number" label="Longitude" :error-messages="fieldForm.errors.longitude" /></VCol>
            <VCol cols="12"><VTextarea v-model="fieldForm.notes" label="Notes" rows="2" /></VCol>
          </VRow>
          <VAlert v-if="fieldForm.errors.farm" type="error" variant="tonal" class="mt-2">{{ fieldForm.errors.farm }}</VAlert>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="fieldDialog = false">Cancel</VBtn><VBtn color="primary" :loading="fieldForm.processing" @click="saveField">Save</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Location dialog -->
    <VDialog v-model="placeDialog" max-width="560">
      <VCard :title="placeEditing ? 'Edit location' : 'Add location'">
        <VCardText>
          <VRow>
            <VCol cols="12" sm="7"><VTextField v-model="placeForm.name" label="Name *" :error-messages="placeForm.errors.name" /></VCol>
            <VCol cols="12" sm="5"><VSelect v-model="placeForm.location_type" label="Type *" :items="options.location_types.map(t => ({ title: label(t), value: t }))" /></VCol>
            <VCol cols="6"><VTextField v-model.number="placeForm.latitude" type="number" label="Latitude" :error-messages="placeForm.errors.latitude" /></VCol>
            <VCol cols="6"><VTextField v-model.number="placeForm.longitude" type="number" label="Longitude" :error-messages="placeForm.errors.longitude" /></VCol>
            <VCol cols="12"><VTextarea v-model="placeForm.notes" label="Notes" rows="2" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="placeDialog = false">Cancel</VBtn><VBtn color="primary" :loading="placeForm.processing" @click="savePlace">Save</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
