<script lang="ts" setup>
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface Field { id: number; name: string; label: string; field_type: string; options: string[]; is_required: boolean; active: boolean; sort_order: number }

const props = defineProps<{
  fieldsByEntity: Record<string, Field[]>
  entityTypes: string[]
  fieldTypes: string[]
}>()

const entity = ref(props.entityTypes[0])
const dialog = ref(false)
const optionsText = ref('')

const form = useForm({ entity_type: entity.value, label: '', field_type: 'text', options: [] as string[], is_required: false })

const current = computed(() => props.fieldsByEntity[entity.value] ?? [])
const needsOptions = computed(() => ['select', 'multiselect'].includes(form.field_type))

function openCreate() { form.reset(); form.entity_type = entity.value; optionsText.value = ''; dialog.value = true }
function save() {
  form.options = needsOptions.value ? optionsText.value.split(',').map(s => s.trim()).filter(Boolean) : []
  form.post(route('crm.custom-fields.store'), { preserveScroll: true, onSuccess: () => dialog.value = false })
}
function toggleActive(f: Field) {
  useForm({ label: f.label, field_type: f.field_type, options: f.options, is_required: f.is_required, active: !f.active })
    .patch(route('crm.custom-fields.update', f.id), { preserveScroll: true })
}
function remove(f: Field) { useForm({}).delete(route('crm.custom-fields.destroy', f.id), { preserveScroll: true }) }
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3">
      <div>
        <h5 class="text-h5 font-weight-semibold">{{ t('crm_custom_fields_index.page_title') }}</h5>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_custom_fields_index.description') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">{{ t('crm_custom_fields_index.add_field') }}</VBtn>
    </div>

    <VTabs v-model="entity" class="mb-3">
      <VTab v-for="tp in entityTypes" :key="tp" :value="tp" class="text-capitalize">
        {{ tp }}s
        <VChip v-if="(fieldsByEntity[tp] ?? []).length" size="x-small" label class="ml-2">{{ (fieldsByEntity[tp] ?? []).length }}</VChip>
      </VTab>
    </VTabs>

    <VCard>
      <VTable density="comfortable">
        <thead>
          <tr><th>{{ t('crm_custom_fields_index.table.label') }}</th><th>{{ t('crm_custom_fields_index.table.key') }}</th><th>{{ t('crm_custom_fields_index.table.type') }}</th><th>{{ t('crm_custom_fields_index.table.options') }}</th><th>{{ t('crm_custom_fields_index.table.required') }}</th><th>{{ t('crm_custom_fields_index.table.active') }}</th><th></th></tr>
        </thead>
        <tbody>
          <tr v-for="f in current" :key="f.id">
            <td class="font-weight-medium">{{ f.label }}</td>
            <td class="text-medium-emphasis"><code>{{ f.name }}</code></td>
            <td class="text-capitalize">{{ f.field_type }}</td>
            <td class="text-medium-emphasis text-caption">{{ (f.options ?? []).join(', ') }}</td>
            <td><VIcon :icon="f.is_required ? 'tabler-check' : 'tabler-minus'" :color="f.is_required ? 'success' : 'disabled'" size="18" /></td>
            <td><VSwitch :model-value="f.active" density="compact" hide-details color="success" @update:model-value="toggleActive(f)" /></td>
            <td class="text-right"><VBtn size="x-small" variant="text" icon="tabler-trash" color="error" @click="remove(f)" /></td>
          </tr>
          <tr v-if="!current.length"><td colspan="7" class="text-medium-emphasis">{{ t('crm_custom_fields_index.no_fields', { entity }) }}</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="dialog" max-width="480" persistent>
      <VCard>
        <VCardTitle>{{ t('crm_custom_fields_index.dialog.title', { entity }) }}</VCardTitle>
        <VCardText>
          <VTextField v-model="form.label" :label="t('crm_custom_fields_index.dialog.label')" class="mb-3" />
          <VSelect v-model="form.field_type" :label="t('crm_custom_fields_index.dialog.type')" :items="fieldTypes" class="mb-3" />
          <VTextField v-if="needsOptions" v-model="optionsText" :label="t('crm_custom_fields_index.dialog.options_hint')" class="mb-3" />
          <VCheckbox v-model="form.is_required" :label="t('crm_custom_fields_index.dialog.required')" />
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="tonal" @click="dialog = false">{{ t('crm_custom_fields_index.dialog.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" @click="save">{{ t('crm_custom_fields_index.dialog.add') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
