<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import AppTextField from '@/@core/components/app-form-elements/AppTextField.vue'

const { t } = useI18n()
const route = ziggyRoute

type Make = { id: number; uuid: string; name: string; status: boolean; models_count: number; assets_count: number }
type AssetModel = { id: number; uuid: string; make_id: number; make_name: string; name: string; status: boolean; assets_count: number }

const props = defineProps<{ makes: Make[]; models: AssetModel[] }>()

// ── Makes ──────────────────────────────────────────────────────────────────────
const makeDialog = ref(false); const makeDeleteDialog = ref(false)
const deletingMakeUuid = ref<string | null>(null); const editingMakeUuid = ref<string | null>(null)
const makeForm = useForm({ name: '', status: true })
const makeHeaders = [
  { title: t('assets_makes_models.makes.table.name'), key: 'name', sortable: true },
  { title: t('assets_makes_models.makes.table.models'), key: 'models_count', sortable: false, width: '100px' },
  { title: t('assets_makes_models.makes.table.assets'), key: 'assets_count', sortable: false, width: '100px' },
  { title: t('assets_makes_models.makes.table.status'), key: 'status', sortable: false, width: '110px' },
  { title: '', key: 'actions', sortable: false, width: '90px' },
]

function openCreateMake() { editingMakeUuid.value = null; makeForm.reset(); makeForm.status = true; makeDialog.value = true }
function openEditMake(make: Make) { editingMakeUuid.value = make.uuid; makeForm.name = make.name; makeForm.status = make.status; makeForm.clearErrors(); makeDialog.value = true }
function saveMake() {
  if (editingMakeUuid.value) {
    makeForm.patch(route('assets.settings.makes.update', editingMakeUuid.value), { onSuccess: () => { makeDialog.value = false } })
  } else {
    makeForm.post(route('assets.settings.makes.store'), { onSuccess: () => { makeDialog.value = false; makeForm.reset(); makeForm.status = true } })
  }
}
function confirmDeleteMake(uuid: string) { deletingMakeUuid.value = uuid; makeDeleteDialog.value = true }
function doDeleteMake() { if (!deletingMakeUuid.value) return; router.delete(route('assets.settings.makes.destroy', deletingMakeUuid.value), { onSuccess: () => { makeDeleteDialog.value = false } }) }

// ── Models ─────────────────────────────────────────────────────────────────────
const modelDialog = ref(false); const modelDeleteDialog = ref(false)
const deletingModelUuid = ref<string | null>(null); const editingModelUuid = ref<string | null>(null)
const selectedMakeId = ref<number | null>(null)
const modelForm = useForm({ name: '', status: true })
const modelHeaders = [
  { title: t('assets_makes_models.models.table.make'), key: 'make_name', sortable: true },
  { title: t('assets_makes_models.models.table.name'), key: 'name', sortable: true },
  { title: t('assets_makes_models.models.table.assets'), key: 'assets_count', sortable: false, width: '100px' },
  { title: t('assets_makes_models.models.table.status'), key: 'status', sortable: false, width: '110px' },
  { title: '', key: 'actions', sortable: false, width: '90px' },
]
const makeOptions = computed(() => props.makes.map(m => ({ id: m.id, name: m.name, uuid: m.uuid })))
const filteredModels = computed(() => selectedMakeId.value ? props.models.filter(m => m.make_id === selectedMakeId.value) : props.models)

function openCreateModel() { editingModelUuid.value = null; modelForm.reset(); modelForm.status = true; modelDialog.value = true }
function openEditModel(model: AssetModel) { editingModelUuid.value = model.uuid; selectedMakeId.value = model.make_id; modelForm.name = model.name; modelForm.status = model.status; modelForm.clearErrors(); modelDialog.value = true }
function saveModel() {
  if (editingModelUuid.value) {
    modelForm.patch(route('assets.settings.models.update', editingModelUuid.value), { onSuccess: () => { modelDialog.value = false } })
  } else {
    const make = makeOptions.value.find(m => m.id === selectedMakeId.value)
    if (!make) return
    modelForm.post(route('assets.settings.models.store', make.uuid), { onSuccess: () => { modelDialog.value = false; modelForm.reset(); modelForm.status = true } })
  }
}
function confirmDeleteModel(uuid: string) { deletingModelUuid.value = uuid; modelDeleteDialog.value = true }
function doDeleteModel() { if (!deletingModelUuid.value) return; router.delete(route('assets.settings.models.destroy', deletingModelUuid.value), { onSuccess: () => { modelDeleteDialog.value = false } }) }
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <!-- Makes -->
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <h4 class="text-h4">{{ t('assets_makes_models.makes.page_title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('assets_makes_models.makes.description') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreateMake">{{ t('assets_makes_models.makes.new_make') }}</VBtn>
    </div>
    <VCard class="mb-8">
      <VDataTable :headers="makeHeaders" :items="makes" item-value="uuid" :items-per-page="15" hover>
        <template #item.status="{ item }"><VChip :color="item.status ? 'success' : 'default'" size="small" variant="tonal" label>{{ item.status ? t('assets_makes_models.makes.active') : t('assets_makes_models.makes.inactive') }}</VChip></template>
        <template #item.actions="{ item }"><VBtn size="small" variant="text" icon @click="openEditMake(item)"><VIcon icon="tabler-pencil" size="18" /></VBtn><VBtn size="small" variant="text" icon color="error" @click="confirmDeleteMake(item.uuid)"><VIcon icon="tabler-trash" size="18" /></VBtn></template>
        <template #no-data>
          <div class="text-center py-10">
            <VIcon icon="tabler-building-factory" size="56" color="medium-emphasis" class="mb-3" />
            <p class="text-body-1 font-weight-medium mb-1">{{ t('assets_makes_models.makes.empty.title') }}</p>
            <VBtn color="primary" variant="tonal" prepend-icon="tabler-plus" @click="openCreateMake">{{ t('assets_makes_models.makes.empty.button') }}</VBtn>
          </div>
        </template>
      </VDataTable>
    </VCard>

    <!-- Models -->
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <h4 class="text-h4">{{ t('assets_makes_models.models.page_title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('assets_makes_models.models.description') }}</p>
      </div>
      <div class="d-flex gap-3 align-center flex-wrap">
        <VSelect v-model="selectedMakeId" :items="makeOptions" item-title="name" item-value="id" :label="t('assets_makes_models.models.filter_by_make')" clearable density="compact" hide-details style="min-width:220px" />
        <VBtn color="primary" prepend-icon="tabler-plus" :disabled="!selectedMakeId" @click="openCreateModel">{{ t('assets_makes_models.models.new_model') }}</VBtn>
      </div>
    </div>
    <VCard>
      <VDataTable :headers="modelHeaders" :items="filteredModels" item-value="uuid" :items-per-page="20" hover>
        <template #item.status="{ item }"><VChip :color="item.status ? 'success' : 'default'" size="small" variant="tonal" label>{{ item.status ? t('assets_makes_models.models.active') : t('assets_makes_models.models.inactive') }}</VChip></template>
        <template #item.actions="{ item }"><VBtn size="small" variant="text" icon @click="openEditModel(item)"><VIcon icon="tabler-pencil" size="18" /></VBtn><VBtn size="small" variant="text" icon color="error" @click="confirmDeleteModel(item.uuid)"><VIcon icon="tabler-trash" size="18" /></VBtn></template>
        <template #no-data>
          <div class="text-center py-10">
            <VIcon icon="tabler-car-garage" size="56" color="medium-emphasis" class="mb-3" />
            <p class="text-body-1 font-weight-medium mb-1">{{ selectedMakeId ? t('assets_makes_models.models.empty.no_make') : t('assets_makes_models.models.empty.no_models') }}</p>
            <VBtn v-if="selectedMakeId" color="primary" variant="tonal" prepend-icon="tabler-plus" @click="openCreateModel">{{ t('assets_makes_models.models.new_model') }}</VBtn>
          </div>
        </template>
      </VDataTable>
    </VCard>

    <!-- Make Dialog -->
    <VDialog v-model="makeDialog" max-width="460" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ editingMakeUuid ? t('assets_makes_models.makes.dialog.edit_title') : t('assets_makes_models.makes.dialog.new_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VForm @submit.prevent="saveMake"><VRow><VCol cols="12"><AppTextField v-model="makeForm.name" :label="t('assets_makes_models.makes.dialog.name')" :error-messages="makeForm.errors.name" autofocus /></VCol><VCol cols="12"><VSwitch v-model="makeForm.status" :label="t('assets_makes_models.makes.dialog.active')" color="success" inset hide-details /></VCol></VRow></VForm>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4 gap-2"><VSpacer /><VBtn variant="text" @click="makeDialog = false">{{ t('assets_makes_models.makes.dialog.cancel') }}</VBtn><VBtn color="primary" :loading="makeForm.processing" @click="saveMake">{{ editingMakeUuid ? t('assets_makes_models.makes.dialog.save') : t('assets_makes_models.makes.dialog.create') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Make Delete Dialog -->
    <VDialog v-model="makeDeleteDialog" max-width="420">
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('assets_makes_models.makes.delete.title') }}</VCardTitle>
        <VDivider /><VCardText class="pa-4"><VAlert type="warning" variant="tonal" density="compact">{{ t('assets_makes_models.makes.delete.warning') }}</VAlert></VCardText>
        <VDivider /><VCardActions class="pa-4 gap-2"><VSpacer /><VBtn variant="text" @click="makeDeleteDialog = false">{{ t('assets_makes_models.makes.delete.cancel') }}</VBtn><VBtn color="error" @click="doDeleteMake">{{ t('assets_makes_models.makes.delete.delete') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Model Dialog -->
    <VDialog v-model="modelDialog" max-width="460" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ editingModelUuid ? t('assets_makes_models.models.dialog.edit_title') : t('assets_makes_models.models.dialog.new_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VForm @submit.prevent="saveModel"><VRow><VCol cols="12"><VSelect v-model="selectedMakeId" :items="makeOptions" item-title="name" item-value="id" :label="t('assets_makes_models.models.dialog.make')" :disabled="!!editingModelUuid" /></VCol><VCol cols="12"><AppTextField v-model="modelForm.name" :label="t('assets_makes_models.models.dialog.name')" :error-messages="modelForm.errors.name" autofocus /></VCol><VCol cols="12"><VSwitch v-model="modelForm.status" :label="t('assets_makes_models.models.dialog.active')" color="success" inset hide-details /></VCol></VRow></VForm>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4 gap-2"><VSpacer /><VBtn variant="text" @click="modelDialog = false">{{ t('assets_makes_models.models.dialog.cancel') }}</VBtn><VBtn color="primary" :loading="modelForm.processing" @click="saveModel">{{ editingModelUuid ? t('assets_makes_models.models.dialog.save') : t('assets_makes_models.models.dialog.create') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Model Delete Dialog -->
    <VDialog v-model="modelDeleteDialog" max-width="420">
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('assets_makes_models.models.delete.title') }}</VCardTitle>
        <VDivider /><VCardText class="pa-4"><VAlert type="warning" variant="tonal" density="compact">{{ t('assets_makes_models.models.delete.warning') }}</VAlert></VCardText>
        <VDivider /><VCardActions class="pa-4 gap-2"><VSpacer /><VBtn variant="text" @click="modelDeleteDialog = false">{{ t('assets_makes_models.models.delete.cancel') }}</VBtn><VBtn color="error" @click="doDeleteModel">{{ t('assets_makes_models.models.delete.delete') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
