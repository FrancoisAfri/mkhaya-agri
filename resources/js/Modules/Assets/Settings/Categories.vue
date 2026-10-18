<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import AppTextField  from '@/@core/components/app-form-elements/AppTextField.vue'
import AppSelect     from '@/@core/components/app-form-elements/AppSelect.vue'
import AppTextarea   from '@/@core/components/app-form-elements/AppTextarea.vue'

const { t } = useI18n()
const route = ziggyRoute

type Category = {
  id: number; uuid: string; name: string; description: string | null
  parent_id: number | null; parent_name: string | null
  sars_allowance_section: string | null; default_depreciation_method: string | null
  default_useful_life_years: number | null; sars_wear_rate: number | null
  status: number; children_count: number; assets_count: number
}

const props = defineProps<{
  categories: Category[]
  depreciation_methods: { value: string; label: string }[]
  sars_sections: { value: string; label: string }[]
}>()

const showDialog   = ref(false)
const deleteDialog = ref(false)
const deletingId   = ref<string | null>(null)

const blankForm = () => ({
  name: '', description: '', parent_id: null as number | null,
  sars_allowance_section: null as string | null,
  default_depreciation_method: null as string | null,
  default_useful_life_years: null as number | null,
  sars_wear_rate: null as number | null, status: true,
})

const form      = useForm(blankForm())
const editingId = ref<string | null>(null)

const headers = [
  { title: t('assets_categories.table.name'), key: 'name', sortable: true },
  { title: t('assets_categories.table.parent'), key: 'parent_name', sortable: false },
  { title: t('assets_categories.table.method'), key: 'default_depreciation_method', sortable: false },
  { title: t('assets_categories.table.life_yrs'), key: 'default_useful_life_years', sortable: false, width: '110px' },
  { title: t('assets_categories.table.assets'), key: 'assets_count', sortable: false, width: '90px' },
  { title: t('assets_categories.table.status'), key: 'status', sortable: false, width: '100px' },
  { title: '', key: 'actions', sortable: false, width: '90px' },
]

function openCreate() {
  editingId.value = null; form.reset(); Object.assign(form, blankForm()); showDialog.value = true
}

function openEdit(cat: Category) {
  editingId.value = cat.uuid
  form.name = cat.name; form.description = cat.description ?? ''
  form.parent_id = cat.parent_id; form.sars_allowance_section = cat.sars_allowance_section
  form.default_depreciation_method = cat.default_depreciation_method
  form.default_useful_life_years = cat.default_useful_life_years
  form.sars_wear_rate = cat.sars_wear_rate; form.status = Boolean(cat.status)
  form.clearErrors(); showDialog.value = true
}

function save() {
  if (editingId.value) {
    form.patch(route('assets.settings.categories.update', editingId.value), {
      onSuccess: () => { showDialog.value = false },
    })
  } else {
    form.post(route('assets.settings.categories.store'), {
      onSuccess: () => { showDialog.value = false; form.reset() },
    })
  }
}

function confirmDelete(uuid: string) { deletingId.value = uuid; deleteDialog.value = true }

function doDelete() {
  if (!deletingId.value) return
  router.delete(route('assets.settings.categories.destroy', deletingId.value), {
    onSuccess: () => { deleteDialog.value = false },
  })
}

function methodLabel(val: string | null) {
  if (!val) return '—'
  return props.depreciation_methods.find(m => m.value === val)?.label ?? val
}

const parentOptions = props.categories.map(c => ({ id: c.id, name: c.name }))
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <h4 class="text-h4">{{ t('assets_categories.page_title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('assets_categories.description') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">{{ t('assets_categories.new_category') }}</VBtn>
    </div>

    <VCard>
      <VDataTable :headers="headers" :items="categories" item-value="uuid" :items-per-page="25" hover>
        <template #item.name="{ item }">
          <span class="text-body-2 font-weight-medium">{{ item.name }}</span>
        </template>
        <template #item.parent_name="{ item }">
          <span class="text-body-2">{{ item.parent_name ?? '—' }}</span>
        </template>
        <template #item.default_depreciation_method="{ item }">
          <span class="text-body-2">{{ methodLabel(item.default_depreciation_method) }}</span>
        </template>
        <template #item.default_useful_life_years="{ item }">
          <span class="text-body-2">{{ item.default_useful_life_years ?? '—' }}</span>
        </template>
        <template #item.assets_count="{ item }">
          <VChip size="x-small" variant="tonal" color="default">{{ item.assets_count }}</VChip>
        </template>
        <template #item.status="{ item }">
          <VChip :color="item.status ? 'success' : 'default'" size="small" variant="tonal" label class="font-weight-medium">
            {{ item.status ? t('assets_categories.active') : t('assets_categories.inactive') }}
          </VChip>
        </template>
        <template #item.actions="{ item }">
          <VBtn size="small" variant="text" icon @click="openEdit(item)"><VIcon icon="tabler-pencil" size="18" /></VBtn>
          <VBtn size="small" variant="text" icon color="error" @click="confirmDelete(item.uuid)"><VIcon icon="tabler-trash" size="18" /></VBtn>
        </template>
        <template #no-data>
          <div class="text-center py-12">
            <VIcon icon="tabler-category" size="64" color="medium-emphasis" class="mb-4" />
            <p class="text-body-1 font-weight-medium mb-1">{{ t('assets_categories.empty.title') }}</p>
            <p class="text-body-2 text-medium-emphasis mb-4">{{ t('assets_categories.empty.description') }}</p>
            <VBtn color="primary" variant="tonal" prepend-icon="tabler-plus" @click="openCreate">{{ t('assets_categories.empty.button') }}</VBtn>
          </div>
        </template>
      </VDataTable>
    </VCard>

    <VDialog v-model="showDialog" max-width="600" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ editingId ? t('assets_categories.dialog.edit_title') : t('assets_categories.dialog.new_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VForm @submit.prevent="save">
            <VRow>
              <VCol cols="12">
                <AppTextField v-model="form.name" :label="t('assets_categories.dialog.name')" :error-messages="form.errors.name" autofocus />
              </VCol>
              <VCol cols="12">
                <AppTextarea v-model="form.description" :label="t('assets_categories.dialog.description')" rows="2" :error-messages="form.errors.description" />
              </VCol>
              <VCol cols="12" sm="6">
                <AppSelect v-model="form.parent_id" :items="parentOptions" item-title="name" item-value="id"
                  :label="t('assets_categories.dialog.parent_category')" clearable :error-messages="form.errors.parent_id" />
              </VCol>
              <VCol cols="12" sm="6">
                <AppSelect v-model="form.sars_allowance_section" :items="sars_sections" item-title="label" item-value="value"
                  :label="t('assets_categories.dialog.sars_section')" clearable :error-messages="form.errors.sars_allowance_section" />
              </VCol>
              <VCol cols="12" sm="6">
                <AppSelect v-model="form.default_depreciation_method" :items="depreciation_methods" item-title="label" item-value="value"
                  :label="t('assets_categories.dialog.depreciation_method')" clearable :error-messages="form.errors.default_depreciation_method" />
              </VCol>
              <VCol cols="12" sm="6">
                <AppTextField v-model="form.default_useful_life_years" :label="t('assets_categories.dialog.useful_life')"
                  type="number" min="1" max="100" :error-messages="form.errors.default_useful_life_years" />
              </VCol>
              <VCol cols="12" sm="6">
                <AppTextField v-model="form.sars_wear_rate" :label="t('assets_categories.dialog.sars_wear_rate')"
                  type="number" min="0" max="1" step="0.01" :hint="t('assets_categories.dialog.sars_wear_rate_hint')"
                  persistent-hint :error-messages="form.errors.sars_wear_rate" />
              </VCol>
              <VCol cols="12" sm="6" class="d-flex align-center">
                <VSwitch v-model="form.status" :label="t('assets_categories.dialog.active')" color="success" inset hide-details />
              </VCol>
            </VRow>
          </VForm>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4 gap-2">
          <VSpacer />
          <VBtn variant="text" @click="showDialog = false">{{ t('assets_categories.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">
            {{ editingId ? t('assets_categories.dialog.save') : t('assets_categories.dialog.create') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="deleteDialog" max-width="420">
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('assets_categories.delete.title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAlert type="warning" variant="tonal" density="compact">{{ t('assets_categories.delete.warning') }}</VAlert>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4 gap-2">
          <VSpacer />
          <VBtn variant="text" @click="deleteDialog = false">{{ t('assets_categories.delete.cancel') }}</VBtn>
          <VBtn color="error" @click="doDelete">{{ t('assets_categories.delete.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
