<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PageErrors from '../Components/PageErrors.vue'

type FarmRow = {
  id: string; code: string; name: string; farm_type: string; location: string | null
  area: number | null; area_unit: string; status: string
  fields_count: number; locations_count: number; members_count: number
}

const props = defineProps<{
  farms: FarmRow[]
  filters: { status: string; search: string }
  options: { farm_types: string[]; area_units: string[]; statuses: string[] }
  can: { manage: boolean }
  scope: { all_farms: boolean }
}>()

const label = (s: string) => s.replace(/_/g, ' ').replace(/^\w/, c => c.toUpperCase())
const statusColor = (s: string) => ({ active: 'success', inactive: 'warning', archived: 'secondary' } as Record<string, string>)[s] ?? 'default'

// ── Filters ─────────────────────────────────────────────────────────────────
const search = ref(props.filters.search)
const status = ref(props.filters.status)
let timer: ReturnType<typeof setTimeout> | undefined
function reload() {
  router.get(route('agri.farms.index'), { search: search.value || undefined, status: status.value }, { preserveState: true, replace: true })
}
watch(status, reload)
watch(search, () => { clearTimeout(timer); timer = setTimeout(reload, 350) })

// ── Register a farm ─────────────────────────────────────────────────────────
const dialog = ref(false)
const form = useForm({
  code: '', name: '', farm_type: 'mixed', location: '', address: '',
  area: null as number | null, area_unit: 'ha', latitude: null as number | null, longitude: null as number | null,
  timezone: 'Africa/Johannesburg', currency: 'ZAR',
})
function openCreate() { form.reset(); form.clearErrors(); dialog.value = true }
function save() {
  form.post(route('agri.farms.store'), { onSuccess: () => { dialog.value = false } })
}
onMounted(() => {
  if (props.can.manage && new URLSearchParams(window.location.search).get('create')) openCreate()
})

const headers = [
  { title: 'Farm', key: 'name' },
  { title: 'Type', key: 'farm_type' },
  { title: 'Area', key: 'area', align: 'end' as const },
  { title: 'Fields', key: 'fields_count', align: 'end' as const },
  { title: 'People', key: 'members_count', align: 'end' as const },
  { title: 'Status', key: 'status' },
]
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-6">
      <div>
        <h4 class="text-h4 mb-1">Farms &amp; Fields</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">
          Register farms, their fields and who works on them.
          <template v-if="!scope.all_farms">You see the farms you have been given access to.</template>
        </p>
      </div>
      <VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="openCreate">Register farm</VBtn>
    </div>

    <VCard>
      <VCardText class="d-flex flex-wrap gap-4">
        <VTextField v-model="search" placeholder="Search name, code or location" prepend-inner-icon="tabler-search"
          density="compact" clearable hide-details style="max-width: 360px" />
        <VSelect v-model="status" :items="[{ title: 'All statuses', value: 'all' }, ...options.statuses.map(s => ({ title: label(s), value: s }))]"
          density="compact" hide-details style="max-width: 200px" />
      </VCardText>

      <VDataTable :headers="headers" :items="farms" item-value="id" :items-per-page="25">
        <template #item.name="{ item }">
          <Link :href="route('agri.farms.show', item.id)" class="font-weight-medium">{{ item.name }}</Link>
          <div class="text-caption text-medium-emphasis">{{ item.code }}<template v-if="item.location"> · {{ item.location }}</template></div>
        </template>
        <template #item.farm_type="{ item }">{{ label(item.farm_type) }}</template>
        <template #item.area="{ item }">
          <span v-if="item.area !== null">{{ item.area.toLocaleString() }} {{ item.area_unit }}</span>
          <span v-else class="text-disabled">—</span>
        </template>
        <template #item.status="{ item }">
          <VChip :color="statusColor(item.status)" size="small" variant="tonal">{{ label(item.status) }}</VChip>
        </template>
        <template #no-data>
          <div class="py-8 text-center text-medium-emphasis">
            {{ scope.all_farms ? 'No farms match.' : 'No farms match, or you have not been given access to any yet.' }}
          </div>
        </template>
      </VDataTable>
    </VCard>

    <VDialog v-model="dialog" max-width="720">
      <VCard title="Register a farm">
        <VCardText>
          <VRow>
            <VCol cols="12" sm="4"><VTextField v-model="form.code" label="Farm code *" :error-messages="form.errors.code" hint="Unique in your organisation, e.g. NORTH-01" persistent-hint /></VCol>
            <VCol cols="12" sm="8"><VTextField v-model="form.name" label="Farm name *" :error-messages="form.errors.name" /></VCol>
            <VCol cols="12" sm="6">
              <VSelect v-model="form.farm_type" label="Farm type *" :items="options.farm_types.map(t => ({ title: label(t), value: t }))" :error-messages="form.errors.farm_type" />
            </VCol>
            <VCol cols="12" sm="6"><VTextField v-model="form.location" label="District / nearest town" :error-messages="form.errors.location" /></VCol>
            <VCol cols="12"><VTextarea v-model="form.address" label="Address" rows="2" :error-messages="form.errors.address" /></VCol>
            <VCol cols="8" sm="4"><VTextField v-model.number="form.area" type="number" min="0" step="0.01" label="Total area" :error-messages="form.errors.area" /></VCol>
            <VCol cols="4" sm="2"><VSelect v-model="form.area_unit" label="Unit" :items="options.area_units" :error-messages="form.errors.area_unit" /></VCol>
            <VCol cols="6" sm="3"><VTextField v-model.number="form.latitude" type="number" step="0.000001" label="Latitude" :error-messages="form.errors.latitude" /></VCol>
            <VCol cols="6" sm="3"><VTextField v-model.number="form.longitude" type="number" step="0.000001" label="Longitude" :error-messages="form.errors.longitude" /></VCol>
            <VCol cols="8"><VTextField v-model="form.timezone" label="Timezone *" :error-messages="form.errors.timezone" /></VCol>
            <VCol cols="4"><VTextField v-model="form.currency" label="Currency *" maxlength="3" :error-messages="form.errors.currency" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">Cancel</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">Register farm</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
