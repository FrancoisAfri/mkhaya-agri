<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import AppTextField from '@/@core/components/app-form-elements/AppTextField.vue'
import AppTextarea  from '@/@core/components/app-form-elements/AppTextarea.vue'
import AppSelect    from '@/@core/components/app-form-elements/AppSelect.vue'

const { t } = useI18n()
const route = ziggyRoute

type Location = {
  id: number; uuid: string; name: string; code: string | null
  parent_id: number | null; parent_name: string | null
  address: string | null; status: number; assets_count: number
}

const props = defineProps<{ locations: Location[] }>()

const showDialog   = ref(false)
const deleteDialog = ref(false)
const deletingId   = ref<string | null>(null)
const search       = ref('')

const blankForm = () => ({
  name: '', code: '', parent_id: null as number | null, address: '', status: true,
})

const form      = useForm(blankForm())
const editingId = ref<string | null>(null)

const headers = [
  { title: t('assets_locations.table.name'), key: 'name', sortable: true },
  { title: t('assets_locations.table.code'), key: 'code', sortable: true, width: '120px' },
  { title: t('assets_locations.table.parent'), key: 'parent_name', sortable: false },
  { title: t('assets_locations.table.assets'), key: 'assets_count', sortable: false, width: '90px' },
  { title: t('assets_locations.table.status'), key: 'status', sortable: false, width: '100px' },
  { title: '', key: 'actions', sortable: false, width: '90px' },
]

const parentOptions = computed(() =>
  props.locations.map(loc => ({ id: loc.id, name: loc.code ? `${loc.name} (${loc.code})` : loc.name }))
)

function openCreate(parentId?: number | null) {
  editingId.value = null; form.reset(); Object.assign(form, blankForm())
  if (parentId) form.parent_id = parentId; showDialog.value = true
}

function openEdit(loc: Location) {
  editingId.value = loc.uuid
  form.name = loc.name; form.code = loc.code ?? ''
  form.parent_id = loc.parent_id; form.address = loc.address ?? ''
  form.status = Boolean(loc.status); form.clearErrors(); showDialog.value = true
}

function save() {
  if (editingId.value) {
    form.patch(route('assets.settings.locations.update', editingId.value), {
      onSuccess: () => { showDialog.value = false },
    })
  } else {
    form.post(route('assets.settings.locations.store'), {
      onSuccess: () => { showDialog.value = false; form.reset() },
    })
  }
}

function confirmDelete(uuid: string) { deletingId.value = uuid; deleteDialog.value = true }

function doDelete() {
  if (!deletingId.value) return
  router.delete(route('assets.settings.locations.destroy', deletingId.value), {
    onSuccess: () => { deleteDialog.value = false },
  })
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <h4 class="text-h4">{{ t('assets_locations.page_title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('assets_locations.description') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate()">{{ t('assets_locations.new_location') }}</VBtn>
    </div>

    <VCard>
      <VCardText class="pa-4 pb-0">
        <VTextField v-model="search" :placeholder="t('assets_locations.search')"
          prepend-inner-icon="tabler-search" variant="outlined" density="compact" hide-details style="max-width:320px" />
      </VCardText>

      <VDataTable :headers="headers" :items="locations" :search="search" item-value="uuid" :items-per-page="25" hover class="mt-2">
        <template #item.name="{ item }">
          <span class="text-body-2 font-weight-medium">{{ item.name }}</span>
        </template>
        <template #item.code="{ item }">
          <VChip v-if="item.code" size="x-small" variant="tonal" color="default">{{ item.code }}</VChip>
          <span v-else class="text-medium-emphasis">—</span>
        </template>
        <template #item.parent_name="{ item }">
          <span class="text-body-2">{{ item.parent_name ?? '—' }}</span>
        </template>
        <template #item.assets_count="{ item }">
          <VChip size="x-small" variant="tonal" color="default">{{ item.assets_count }}</VChip>
        </template>
        <template #item.status="{ item }">
          <VChip :color="item.status ? 'success' : 'default'" size="small" variant="tonal" label class="font-weight-medium">
            {{ item.status ? t('assets_locations.active') : t('assets_locations.inactive') }}
          </VChip>
        </template>
        <template #item.actions="{ item }">
          <VBtn size="small" variant="text" icon @click="openEdit(item)"><VIcon icon="tabler-pencil" size="18" /></VBtn>
          <VBtn size="small" variant="text" icon color="error" @click="confirmDelete(item.uuid)"><VIcon icon="tabler-trash" size="18" /></VBtn>
        </template>
        <template #no-data>
          <div class="text-center py-12">
            <VIcon icon="tabler-map-pin" size="64" color="medium-emphasis" class="mb-4" />
            <p class="text-body-1 font-weight-medium mb-1">{{ t('assets_locations.empty.title') }}</p>
            <p class="text-body-2 text-medium-emphasis mb-4">{{ t('assets_locations.empty.description') }}</p>
            <VBtn color="primary" variant="tonal" prepend-icon="tabler-plus" @click="openCreate()">{{ t('assets_locations.empty.button') }}</VBtn>
          </div>
        </template>
      </VDataTable>
    </VCard>

    <VDialog v-model="showDialog" max-width="540" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ editingId ? t('assets_locations.dialog.edit_title') : t('assets_locations.dialog.new_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VForm @submit.prevent="save">
            <VRow>
              <VCol cols="12" sm="8">
                <AppTextField v-model="form.name" :label="t('assets_locations.dialog.name')" :error-messages="form.errors.name" autofocus />
              </VCol>
              <VCol cols="12" sm="4">
                <AppTextField v-model="form.code" :label="t('assets_locations.dialog.code')" :error-messages="form.errors.code" />
              </VCol>
              <VCol cols="12">
                <AppSelect v-model="form.parent_id" :items="parentOptions" item-title="name" item-value="id"
                  :label="t('assets_locations.dialog.parent_location')" clearable :error-messages="form.errors.parent_id" />
              </VCol>
              <VCol cols="12">
                <AppTextarea v-model="form.address" :label="t('assets_locations.dialog.address')" rows="2" :error-messages="form.errors.address" />
              </VCol>
              <VCol cols="12">
                <VSwitch v-model="form.status" :label="t('assets_locations.dialog.active')" color="success" inset hide-details />
              </VCol>
            </VRow>
          </VForm>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4 gap-2">
          <VSpacer />
          <VBtn variant="text" @click="showDialog = false">{{ t('assets_locations.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">
            {{ editingId ? t('assets_locations.dialog.save') : t('assets_locations.dialog.create') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="deleteDialog" max-width="420">
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('assets_locations.delete.title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAlert type="warning" variant="tonal" density="compact">{{ t('assets_locations.delete.warning') }}</VAlert>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4 gap-2">
          <VSpacer />
          <VBtn variant="text" @click="deleteDialog = false">{{ t('assets_locations.delete.cancel') }}</VBtn>
          <VBtn color="error" @click="doDelete">{{ t('assets_locations.delete.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
