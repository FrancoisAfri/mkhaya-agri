<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import AppTextField from '@/@core/components/app-form-elements/AppTextField.vue'
import AppTextarea  from '@/@core/components/app-form-elements/AppTextarea.vue'

const { t } = useI18n()
const route = ziggyRoute

type AssetType = { id: number; name: string; description: string | null; status: number }
defineProps<{ types: AssetType[] }>()

const showDialog   = ref(false)
const deleteDialog = ref(false)
const deletingId   = ref<number | null>(null)

const blankForm = () => ({ name: '', description: '', status: true })
const form      = useForm(blankForm())
const editingId = ref<number | null>(null)

const headers = [
  { title: t('assets_types.table.name'), key: 'name', sortable: true },
  { title: t('assets_types.table.description'), key: 'description', sortable: false },
  { title: t('assets_types.table.status'), key: 'status', sortable: false, width: '100px' },
  { title: '', key: 'actions', sortable: false, width: '90px' },
]

function openCreate() { editingId.value = null; form.reset(); Object.assign(form, blankForm()); showDialog.value = true }
function openEdit(type: AssetType) {
  editingId.value = type.id; form.name = type.name; form.description = type.description ?? ''
  form.status = Boolean(type.status); form.clearErrors(); showDialog.value = true
}
function save() {
  if (editingId.value) {
    form.patch(route('assets.settings.types.update', editingId.value), { onSuccess: () => { showDialog.value = false } })
  } else {
    form.post(route('assets.settings.types.store'), { onSuccess: () => { showDialog.value = false; form.reset() } })
  }
}
function confirmDelete(id: number) { deletingId.value = id; deleteDialog.value = true }
function doDelete() {
  if (!deletingId.value) return
  router.delete(route('assets.settings.types.destroy', deletingId.value), { onSuccess: () => { deleteDialog.value = false } })
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <h4 class="text-h4">{{ t('assets_types.page_title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('assets_types.description') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">{{ t('assets_types.new_type') }}</VBtn>
    </div>

    <VCard>
      <VDataTable :headers="headers" :items="types" item-value="id" :items-per-page="25" hover>
        <template #item.name="{ item }"><span class="text-body-2 font-weight-medium">{{ item.name }}</span></template>
        <template #item.description="{ item }"><span class="text-body-2 text-medium-emphasis">{{ item.description ?? '—' }}</span></template>
        <template #item.status="{ item }">
          <VChip :color="item.status ? 'success' : 'default'" size="small" variant="tonal" label class="font-weight-medium">
            {{ item.status ? t('assets_types.active') : t('assets_types.inactive') }}
          </VChip>
        </template>
        <template #item.actions="{ item }">
          <VBtn size="small" variant="text" icon @click="openEdit(item)"><VIcon icon="tabler-pencil" size="18" /></VBtn>
          <VBtn size="small" variant="text" icon color="error" @click="confirmDelete(item.id)"><VIcon icon="tabler-trash" size="18" /></VBtn>
        </template>
        <template #no-data>
          <div class="text-center py-12">
            <VIcon icon="tabler-tags" size="64" color="medium-emphasis" class="mb-4" />
            <p class="text-body-1 font-weight-medium mb-1">{{ t('assets_types.empty.title') }}</p>
            <p class="text-body-2 text-medium-emphasis mb-4">{{ t('assets_types.empty.description') }}</p>
            <VBtn color="primary" variant="tonal" prepend-icon="tabler-plus" @click="openCreate">{{ t('assets_types.empty.button') }}</VBtn>
          </div>
        </template>
      </VDataTable>
    </VCard>

    <VDialog v-model="showDialog" max-width="500" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ editingId ? t('assets_types.dialog.edit_title') : t('assets_types.dialog.new_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VForm @submit.prevent="save">
            <VRow>
              <VCol cols="12"><AppTextField v-model="form.name" :label="t('assets_types.dialog.name')" :error-messages="form.errors.name" autofocus /></VCol>
              <VCol cols="12"><AppTextarea v-model="form.description" :label="t('assets_types.dialog.description')" rows="3" :error-messages="form.errors.description" /></VCol>
              <VCol cols="12"><VSwitch v-model="form.status" :label="t('assets_types.dialog.active')" color="success" inset hide-details /></VCol>
            </VRow>
          </VForm>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4 gap-2">
          <VSpacer />
          <VBtn variant="text" @click="showDialog = false">{{ t('assets_types.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">
            {{ editingId ? t('assets_types.dialog.save') : t('assets_types.dialog.create') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="deleteDialog" max-width="420">
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('assets_types.delete.title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4"><VAlert type="warning" variant="tonal" density="compact">{{ t('assets_types.delete.warning') }}</VAlert></VCardText>
        <VDivider />
        <VCardActions class="pa-4 gap-2">
          <VSpacer />
          <VBtn variant="text" @click="deleteDialog = false">{{ t('assets_types.delete.cancel') }}</VBtn>
          <VBtn color="error" @click="doDelete">{{ t('assets_types.delete.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
