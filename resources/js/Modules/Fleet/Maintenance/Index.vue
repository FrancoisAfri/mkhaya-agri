<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

type Maintenance = {
  id: number; vehicle: string; vehicle_id: number
  category: string; description: string | null; priority: string; status: string
  scheduled_date: string; completed_date: string | null
  service_provider: string | null; labour_cost: number | null
  parts_cost: number | null; total_cost: number | null
  odometer_at_service: number | null
}
type Vehicle = { id: number; registration: string; make: string; model: string }

const props = defineProps<{
  maintenance: { data: Maintenance[]; meta: any; links: any[] }
  filters: Record<string, any>
  vehicles: Vehicle[]
  stats: { scheduled: number; in_progress: number; completed: number; overdue: number }
}>()

const search   = ref(props.filters?.search ?? '')
const status   = ref(props.filters?.status ?? '')
const priority = ref(props.filters?.priority ?? '')
let timer: ReturnType<typeof setTimeout>

function applyFilters() {
  router.get(route('fleet.maintenance.index'), {
    search: search.value || undefined,
    status: status.value || undefined,
    priority: priority.value || undefined,
  }, { preserveState: true, replace: true })
}

watch(search, () => { clearTimeout(timer); timer = setTimeout(applyFilters, 400) })
watch([status, priority], applyFilters)

const dialog  = ref(false)
const editing = ref<Maintenance | null>(null)

const form = useForm({
  vehicle_id: null as number | null,
  maintenance_type: 'scheduled',
  category: 'service',
  description: '',
  priority: 'normal',
  status: 'scheduled',
  scheduled_date: '',
  completed_date: '',
  service_provider: '',
  labour_cost: null as number | null,
  parts_cost: null as number | null,
  odometer_at_service: null as number | null,
  notes: '',
})

function openAdd() {
  editing.value = null
  form.reset()
  form.maintenance_type = 'scheduled'
  form.category = 'service'
  form.priority = 'normal'
  form.status = 'scheduled'
  dialog.value = true
}

function openEdit(m: Maintenance) {
  editing.value = m
  form.vehicle_id = m.vehicle_id
  form.category = m.category
  form.description = m.description ?? ''
  form.priority = m.priority
  form.status = m.status
  form.scheduled_date = m.scheduled_date
  form.completed_date = m.completed_date ?? ''
  form.service_provider = m.service_provider ?? ''
  form.labour_cost = m.labour_cost
  form.parts_cost = m.parts_cost
  form.odometer_at_service = m.odometer_at_service
  dialog.value = true
}

function save() {
  const opts = { preserveScroll: true, onSuccess: () => { dialog.value = false; form.reset() } }
  if (editing.value) {
    form.patch(route('fleet.maintenance.update', editing.value.id), opts)
  } else {
    form.post(route('fleet.maintenance.store'), opts)
  }
}

const deleteDialog = ref(false)
const toDelete = ref<Maintenance | null>(null)
function confirmDelete(m: Maintenance) { toDelete.value = m; deleteDialog.value = true }
function doDelete() {
  if (!toDelete.value) return
  router.delete(route('fleet.maintenance.destroy', toDelete.value.id), {
    preserveScroll: true,
    onSuccess: () => { deleteDialog.value = false },
  })
}

const priorityColor = (p: string) => ({ emergency: 'error', high: 'warning', normal: 'info', low: 'success' })[p] ?? 'default'
const statusColor   = (s: string) => ({
  scheduled: 'info', in_progress: 'primary', completed: 'success', cancelled: 'default',
})[s] ?? 'default'

// ── MAPPING : Traduction des valeurs DB → labels affichés ──────────────────
const priorityLabel = (p: string): string => ({
  emergency: t('fleet_maintenance_index.form.priority_emergency'),
  high:      t('fleet_maintenance_index.form.priority_high'),
  normal:    t('fleet_maintenance_index.form.priority_normal'),
  low:       t('fleet_maintenance_index.form.priority_low'),
})[p] ?? p

const statusLabel = (s: string): string => ({
  scheduled:    t('fleet_maintenance_index.form.status_scheduled'),
  in_progress:  t('fleet_maintenance_index.form.status_in_progress'),
  completed:    t('fleet_maintenance_index.form.status_completed'),
  cancelled:    t('fleet_maintenance_index.form.status_cancelled'),
})[s] ?? s.replace('_', ' ')

const statCards = computed(() => [
  { label: t('fleet_maintenance_index.stats.scheduled'),   value: props.stats.scheduled,   color: 'info',    icon: 'ri-calendar-line' },
  { label: t('fleet_maintenance_index.stats.in_progress'), value: props.stats.in_progress, color: 'primary', icon: 'ri-tools-line' },
  { label: t('fleet_maintenance_index.stats.completed'),   value: props.stats.completed,   color: 'success', icon: 'ri-checkbox-circle-line' },
  { label: t('fleet_maintenance_index.stats.overdue'),     value: props.stats.overdue,     color: 'error',   icon: 'ri-alarm-warning-line' },
])

const headers = computed(() => [
  { title: t('fleet_maintenance_index.table.vehicle'),     key: 'vehicle' },
  { title: t('fleet_maintenance_index.table.category'),    key: 'category' },
  { title: t('fleet_maintenance_index.table.description'), key: 'description' },
  { title: t('fleet_maintenance_index.table.priority'),    key: 'priority' },
  { title: t('fleet_maintenance_index.table.scheduled'),   key: 'scheduled_date' },
  { title: t('fleet_maintenance_index.table.completed'),   key: 'completed_date' },
  { title: t('fleet_maintenance_index.table.cost'),        key: 'total_cost' },
  { title: t('fleet_maintenance_index.table.status'),      key: 'status' },
  { title: '', key: 'actions', sortable: false, width: '80px' },
])
</script>

<template>
  <div class="pa-4">
    <!-- Header -->
    <div class="d-flex align-center mb-5">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('fleet_maintenance_index.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">{{ t('fleet_maintenance_index.description') }}</p>
      </div>
      <VSpacer />
      <VBtn color="primary" prepend-icon="ri-add-line" @click="openAdd">{{ t('fleet_maintenance_index.schedule') }}</VBtn>
    </div>

    <!-- Stats -->
    <VRow class="mb-4">
      <VCol v-for="s in statCards" :key="s.label" cols="6" md="3">
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

    <!-- Filters -->
    <VCard class="mb-4 pa-3">
      <VRow dense>
        <VCol cols="12" md="5">
          <VTextField v-model="search" :label="t('fleet_maintenance_index.search_placeholder')" prepend-inner-icon="ri-search-line" clearable density="compact" variant="outlined" hide-details />
        </VCol>
        <VCol cols="6" md="3">
          <VSelect v-model="status" :label="t('fleet_maintenance_index.table.status')" :items="[
            { title: t('fleet_maintenance_index.filters.status_scheduled'),   value: 'scheduled' },
            { title: t('fleet_maintenance_index.filters.status_in_progress'), value: 'in_progress' },
            { title: t('fleet_maintenance_index.filters.status_completed'),   value: 'completed' },
            { title: t('fleet_maintenance_index.filters.status_cancelled'),   value: 'cancelled' },
          ]" item-title="title" item-value="value" clearable density="compact" variant="outlined" hide-details />
        </VCol>
        <VCol cols="6" md="3">
          <VSelect v-model="priority" :label="t('fleet_maintenance_index.table.priority')" :items="[
            { title: t('fleet_maintenance_index.filters.priority_emergency'), value: 'emergency' },
            { title: t('fleet_maintenance_index.filters.priority_high'),      value: 'high' },
            { title: t('fleet_maintenance_index.filters.priority_normal'),    value: 'normal' },
            { title: t('fleet_maintenance_index.filters.priority_low'),       value: 'low' },
          ]" item-title="title" item-value="value" clearable density="compact" variant="outlined" hide-details />
        </VCol>
      </VRow>
    </VCard>

    <!-- Table -->
    <VCard>
      <VDataTableServer
        :headers="headers"
        :items="maintenance.data"
        :items-length="maintenance.meta?.total ?? 0"
        density="comfortable"
        hover
      >
        <template #item.category="{ item }">
          {{ item.category.replace(/_/g, ' ').replace(/\b\w/g, (c: string) => c.toUpperCase()) }}
        </template>
        <template #item.description="{ item }">
          <span class="text-truncate d-inline-block" style="max-width:160px">{{ item.description ?? '—' }}</span>
        </template>
        <template #item.priority="{ item }">
          <VChip :color="priorityColor(item.priority)" size="x-small" label class="text-capitalize">{{ priorityLabel(item.priority) }}</VChip>
        </template>
        <template #item.completed_date="{ item }">{{ item.completed_date ?? '—' }}</template>
        <template #item.total_cost="{ item }">{{ item.total_cost ? `R ${item.total_cost?.toLocaleString()}` : '—' }}</template>
        <template #item.status="{ item }">
          <VChip :color="statusColor(item.status)" size="x-small" label class="text-capitalize">{{ statusLabel(item.status) }}</VChip>
        </template>
        <template #item.actions="{ item }">
          <div class="d-flex gap-1">
            <VBtn icon size="x-small" variant="text" color="primary" @click="openEdit(item)">
              <VIcon icon="ri-pencil-line" />
            </VBtn>
            <VBtn icon size="x-small" variant="text" color="error" @click="confirmDelete(item)">
              <VIcon icon="ri-delete-bin-line" />
            </VBtn>
          </div>
        </template>
      </VDataTableServer>
    </VCard>

    <!-- Add/Edit Dialog -->
    <VDialog v-model="dialog" max-width="640" scrollable persistent>
      <VCard :title="editing ? t('fleet_maintenance_index.edit_record') : t('fleet_maintenance_index.schedule_title')">
        <VCardText>
          <VRow dense>
            <VCol cols="12">
              <VSelect v-model="form.vehicle_id" :label="t('fleet_maintenance_index.table.vehicle') + ' *'" :items="vehicles"
                item-title="registration" item-value="id"
                :item-subtitle="(v: Vehicle) => `${v.make} ${v.model}`"
                :error-messages="form.errors.vehicle_id" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="form.category" :label="t('fleet_maintenance_index.table.category') + ' *'" :items="[
                'service','tyre_replacement','brake_service','oil_change','battery_replacement',
                'electrical','bodywork','inspection','other']" />
            </VCol>
            <VCol cols="12" md="3">
              <VSelect v-model="form.priority" :label="t('fleet_maintenance_index.table.priority')" :items="[
                { title: t('fleet_maintenance_index.form.priority_emergency'), value: 'emergency' },
                { title: t('fleet_maintenance_index.form.priority_high'),      value: 'high' },
                { title: t('fleet_maintenance_index.form.priority_normal'),    value: 'normal' },
                { title: t('fleet_maintenance_index.form.priority_low'),       value: 'low' },
              ]" item-title="title" item-value="value" />
            </VCol>
            <VCol cols="12" md="3">
              <VSelect v-model="form.status" :label="t('fleet_maintenance_index.table.status')" :items="[
                { title: t('fleet_maintenance_index.form.status_scheduled'),   value: 'scheduled' },
                { title: t('fleet_maintenance_index.form.status_in_progress'), value: 'in_progress' },
                { title: t('fleet_maintenance_index.form.status_completed'),   value: 'completed' },
                { title: t('fleet_maintenance_index.form.status_cancelled'),   value: 'cancelled' },
              ]" item-title="title" item-value="value" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="form.description" :label="t('fleet_maintenance_index.table.description')" rows="2" auto-grow />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.scheduled_date" :label="t('fleet_maintenance_index.table.scheduled') + ' *'" type="date" :error-messages="form.errors.scheduled_date" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.completed_date" :label="t('fleet_maintenance_index.table.completed')" type="date" />
            </VCol>
            <VCol cols="12">
              <VTextField v-model="form.service_provider" :label="t('fleet_maintenance_index.form.service_provider')" />
            </VCol>
            <VCol cols="6" md="4">
              <VTextField v-model.number="form.labour_cost" :label="t('fleet_maintenance_index.form.labour_cost')" type="number" />
            </VCol>
            <VCol cols="6" md="4">
              <VTextField v-model.number="form.parts_cost" :label="t('fleet_maintenance_index.form.parts_cost')" type="number" />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField v-model.number="form.odometer_at_service" :label="t('fleet_maintenance_index.form.odometer')" type="number" />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('fleet_maintenance_index.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">
            {{ editing ? t('fleet_maintenance_index.update') : t('fleet_maintenance_index.schedule') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Delete Confirm -->
    <VDialog v-model="deleteDialog" max-width="400" persistent>
      <VCard :title="t('fleet_maintenance_index.delete_title')">
        <VCardText>{{ t('fleet_maintenance_index.delete_message', { vehicle: toDelete?.vehicle }) }}</VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="deleteDialog = false">{{ t('fleet_maintenance_index.cancel') }}</VBtn>
          <VBtn color="error" @click="doDelete">{{ t('fleet_maintenance_index.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
