<script setup lang="ts">
import { ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

type FleetDoc = {
  id: number; vehicle: string; vehicle_id: number
  document_type: string; document_number: string | null
  issue_date: string | null; expiry_date: string | null
  days_left: number | null; status: string
  issuing_authority: string | null; notes: string | null
}
type Vehicle = { id: number; registration: string; make: string; model: string }

const props = defineProps<{
  documents: { data: FleetDoc[]; meta: any; links: any[] }
  filters: Record<string, any>
  vehicles: Vehicle[]
  documentTypes: string[]
}>()

const search  = ref(props.filters?.search ?? '')
const docType = ref(props.filters?.document_type ?? '')
const status  = ref(props.filters?.status ?? '')
let timer: ReturnType<typeof setTimeout>

function applyFilters() {
  router.get(route('fleet.documents.index'), {
    search: search.value || undefined,
    document_type: docType.value || undefined,
    status: status.value || undefined,
  }, { preserveState: true, replace: true })
}

watch(search, () => { clearTimeout(timer); timer = setTimeout(applyFilters, 400) })
watch([docType, status], applyFilters)

const dialog  = ref(false)
const editing = ref<FleetDoc | null>(null)

const form = useForm({
  vehicle_id: null as number | null,
  document_type: '',
  document_number: '',
  issue_date: '',
  expiry_date: '',
  issuing_authority: '',
  notes: '',
  file: null as File | null,
})

function openAdd() {
  editing.value = null
  form.reset()
  dialog.value = true
}

function openEdit(d: FleetDoc) {
  editing.value = d
  form.vehicle_id = d.vehicle_id
  form.document_type = d.document_type
  form.document_number = d.document_number ?? ''
  form.issue_date = d.issue_date ?? ''
  form.expiry_date = d.expiry_date ?? ''
  form.issuing_authority = d.issuing_authority ?? ''
  form.notes = d.notes ?? ''
  form.file = null
  dialog.value = true
}

function save() {
  const opts = {
    preserveScroll: true,
    onSuccess: () => { dialog.value = false; form.reset() },
    forceFormData: true,
  }
  if (editing.value) {
    form.transform(d => ({ ...d, _method: 'PATCH' }))
      .post(route('fleet.documents.update', editing.value.id), opts)
  } else {
    form.post(route('fleet.documents.store'), opts)
  }
}

const deleteDialog = ref(false)
const toDelete = ref<FleetDoc | null>(null)
function confirmDelete(d: FleetDoc) { toDelete.value = d; deleteDialog.value = true }
function doDelete() {
  if (!toDelete.value) return
  router.delete(route('fleet.documents.destroy', toDelete.value.id), {
    preserveScroll: true,
    onSuccess: () => { deleteDialog.value = false },
  })
}

const docDaysColor = (d: number | null) => !d ? 'error' : d <= 7 ? 'error' : d <= 30 ? 'warning' : 'success'

const headers = [
  { title: t('fleet_documents_index.table.vehicle'),      key: 'vehicle' },
  { title: t('fleet_documents_index.table.type'),         key: 'document_type' },
  { title: t('fleet_documents_index.table.number'),       key: 'document_number' },
  { title: t('fleet_documents_index.table.issue_date'),   key: 'issue_date' },
  { title: t('fleet_documents_index.table.expiry_date'),  key: 'expiry_date' },
  { title: t('fleet_documents_index.table.days_left'),    key: 'days_left' },
  { title: t('fleet_documents_index.table.status'),       key: 'status' },
  { title: '', key: 'actions', sortable: false, width: '80px' },
]

const statusOptions = [
  { title: t('fleet_documents_index.statuses.valid'), value: 'valid' },
  { title: t('fleet_documents_index.statuses.expiring_soon'), value: 'expiring_soon' },
  { title: t('fleet_documents_index.statuses.expired'), value: 'expired' },
]
</script>

<template>
  <div class="pa-4">
    <!-- Header -->
    <div class="d-flex align-center mb-5">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('fleet_documents_index.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">{{ t('fleet_documents_index.description') }}</p>
      </div>
      <VSpacer />
      <VBtn color="primary" prepend-icon="ri-add-line" @click="openAdd">{{ t('fleet_documents_index.add_document') }}</VBtn>
    </div>

    <!-- Filters -->
    <VCard class="mb-4 pa-3">
      <VRow dense>
        <VCol cols="12" md="5">
          <VTextField v-model="search" :label="t('fleet_documents_index.search_placeholder')" prepend-inner-icon="ri-search-line" clearable density="compact" variant="outlined" hide-details />
        </VCol>
        <VCol cols="6" md="3">
          <VSelect v-model="docType" :label="t('fleet_documents_index.table.type')" :items="documentTypes" clearable density="compact" variant="outlined" hide-details />
        </VCol>
        <VCol cols="6" md="3">
          <VSelect v-model="status" :label="t('fleet_documents_index.table.status')" :items="statusOptions" item-title="title" item-value="value" clearable density="compact" variant="outlined" hide-details />
        </VCol>
      </VRow>
    </VCard>

    <!-- Table -->
    <VCard>
      <VDataTableServer
        :headers="headers"
        :items="documents.data"
        :items-length="documents.meta?.total ?? 0"
        density="comfortable"
        hover
      >
        <template #item.document_type="{ item }">
          {{ item.document_type.replace(/_/g, ' ').replace(/\b\w/g, (c: string) => c.toUpperCase()) }}
        </template>
        <template #item.document_number="{ item }">{{ item.document_number ?? '—' }}</template>
        <template #item.issue_date="{ item }">{{ item.issue_date ?? '—' }}</template>
        <template #item.days_left="{ item }">
          <VChip v-if="item.days_left !== null" :color="docDaysColor(item.days_left)" size="x-small" label>
            {{ item.days_left }}{{ t('fleet_documents_index.days_abbr') }}
          </VChip>
          <span v-else class="text-medium-emphasis text-caption">—</span>
        </template>
        <template #item.status="{ item }">
          <VChip
            :color="item.status === 'valid' ? 'success' : item.status === 'expired' ? 'error' : 'warning'"
            size="x-small" label class="text-capitalize"
          >{{ item.status.replace('_', ' ') }}</VChip>
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
    <VDialog v-model="dialog" max-width="580" scrollable persistent>
      <VCard :title="editing ? t('fleet_documents_index.edit_document') : t('fleet_documents_index.add_document_title')">
        <VCardText>
          <VRow dense>
            <VCol cols="12">
              <VSelect v-model="form.vehicle_id" :label="t('fleet_documents_index.table.vehicle') + ' *'" :items="vehicles"
                item-title="registration" item-value="id"
                :item-subtitle="(v: Vehicle) => `${v.make} ${v.model}`"
                :error-messages="form.errors.vehicle_id" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="form.document_type" :label="t('fleet_documents_index.table.type') + ' *'"
                :items="documentTypes.length ? documentTypes : ['licence_disc','roadworthy','insurance','permit','registration','other']"
                :error-messages="form.errors.document_type" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.document_number" :label="t('fleet_documents_index.table.number')" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.issue_date" :label="t('fleet_documents_index.table.issue_date')" type="date" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.expiry_date" :label="t('fleet_documents_index.table.expiry_date')" type="date" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="form.issuing_authority" :label="t('fleet_documents_index.form.issuing_authority')" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="form.notes" :label="t('fleet_documents_index.form.notes')" rows="2" auto-grow />
            </VCol>
            <VCol cols="12">
              <VFileInput v-model="form.file" :label="t('fleet_documents_index.form.upload_file')" accept=".pdf,.jpg,.png" clearable
                prepend-icon="ri-upload-line" />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('fleet_documents_index.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">
            {{ editing ? t('fleet_documents_index.update') : t('fleet_documents_index.add_document') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Delete Confirm -->
    <VDialog v-model="deleteDialog" max-width="400" persistent>
      <VCard :title="t('fleet_documents_index.delete_title')">
        <VCardText>{{ t('fleet_documents_index.delete_message', { type: toDelete?.document_type?.replace(/_/g, ' '), vehicle: toDelete?.vehicle }) }}</VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="deleteDialog = false">{{ t('fleet_documents_index.cancel') }}</VBtn>
          <VBtn color="error" @click="doDelete">{{ t('fleet_documents_index.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
