<script lang="ts" setup>
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface Field { name?: string; label: string; type: string; options?: string[]; required: boolean }
interface ObjectType { id: number; uuid: string; name: string; slug: string; icon: string; record_label: string | null; fields: Field[]; records: number }
interface Record { id: number; label: string; data: Record2; at: string | null }
type Record2 = { [k: string]: any }

const props = defineProps<{
  types: ObjectType[]
  activeType: string | null
  records: Record[]
  fieldTypes: string[]
}>()

const typeDialog = ref(false)
const recordDialog = ref(false)
const editingRecord = ref<Record | null>(null)

const active = computed(() => props.types.find(tp => tp.slug === props.activeType) ?? null)

const typeForm = useForm({ name: '', icon: 'box', fields: [{ label: '', type: 'text', options: [] as string[], required: false }] as Field[] })
const optionText = ref<Record2>({})

function addField() { typeForm.fields.push({ label: '', type: 'text', options: [], required: false }) }
function removeField(i: number) { typeForm.fields.splice(i, 1) }
function saveType() {
  typeForm.fields = typeForm.fields.map((f, i) => ({
    ...f,
    options: ['select', 'multiselect'].includes(f.type) ? (optionText.value[i] ?? '').split(',').map((s: string) => s.trim()).filter(Boolean) : [],
  }))
  typeForm.post(route('crm.objects.types.store'), { preserveScroll: true, onSuccess: () => { typeDialog.value = false; typeForm.reset() } })
}
function deleteType(tp: ObjectType) { useForm({}).delete(route('crm.objects.types.destroy', tp.slug), { preserveScroll: true }) }
function selectType(tp: ObjectType) { router.get(route('crm.objects'), { type: tp.slug }, { preserveState: false }) }

/*
 * `values`, not `data`.
 *
 * An Inertia form owns `data` — `form.data()` is how you read the payload back — so a field of
 * that name shadows the method. It happened to keep working because assigning an object over the
 * method leaves something indexable behind, but `form.data()` was gone and Inertia was saying so
 * in the console. Mapped back on submit, since the endpoint reads `data`.
 */
const recordForm = useForm({ values: {} as Record2 })
function openCreateRecord() { editingRecord.value = null; recordForm.reset(); recordForm.values = {}; recordDialog.value = true }
function openEditRecord(r: Record) { editingRecord.value = r; recordForm.values = { ...r.data }; recordDialog.value = true }
function saveRecord() {
  if (!active.value) return
  if (editingRecord.value) recordForm.transform(d => ({ data: d.values })).patch(route('crm.objects.records.update', [active.value.slug, editingRecord.value.id]), { preserveScroll: true, onSuccess: () => recordDialog.value = false })
  else recordForm.transform(d => ({ data: d.values })).post(route('crm.objects.records.store', active.value.slug), { preserveScroll: true, onSuccess: () => recordDialog.value = false })
}
function deleteRecord(r: Record) {
  if (!active.value) return
  useForm({}).delete(route('crm.objects.records.destroy', [active.value.slug, r.id]), { preserveScroll: true })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3">
      <div>
        <h5 class="text-h5 font-weight-semibold">{{ t('crm_objects_index.page_title') }}</h5>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_objects_index.description') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="typeDialog = true">{{ t('crm_objects_index.new_object') }}</VBtn>
    </div>

    <VRow>
      <VCol cols="12" md="3">
        <VCard :title="t('crm_objects_index.objects')">
          <VList>
            <VListItem v-for="tp in types" :key="tp.id" :active="tp.slug === activeType" @click="selectType(tp)">
              <template #prepend><VIcon :icon="`tabler-${tp.icon}`" /></template>
              <VListItemTitle>{{ tp.name }}</VListItemTitle>
              <VListItemSubtitle>{{ tp.records }} {{ t('crm_objects_index.records_count') }}</VListItemSubtitle>
              <template #append><VBtn size="x-small" variant="text" icon="tabler-trash" color="error" @click.stop="deleteType(tp)" /></template>
            </VListItem>
            <VListItem v-if="!types.length" :title="t('crm_objects_index.no_objects')" />
          </VList>
        </VCard>
      </VCol>

      <VCol cols="12" md="9">
        <VCard v-if="active">
          <VCardTitle class="d-flex align-center">
            {{ active.name }} <VSpacer />
            <VBtn size="small" color="primary" prepend-icon="tabler-plus" @click="openCreateRecord">{{ t('crm_objects_index.add_record', { name: active.name }) }}</VBtn>
          </VCardTitle>
          <VTable density="comfortable">
            <thead>
              <tr><th v-for="f in active.fields" :key="f.name">{{ f.label }}</th><th></th></tr>
            </thead>
            <tbody>
              <tr v-for="r in records" :key="r.id">
                <td v-for="f in active.fields" :key="f.name">
                  {{ Array.isArray(r.data[f.name!]) ? r.data[f.name!].join(', ') : r.data[f.name!] }}
                </td>
                <td class="text-right">
                  <VBtn size="x-small" variant="text" icon="tabler-edit" @click="openEditRecord(r)" />
                  <VBtn size="x-small" variant="text" icon="tabler-trash" color="error" @click="deleteRecord(r)" />
                </td>
              </tr>
              <tr v-if="!records.length"><td :colspan="active.fields.length + 1" class="text-medium-emphasis">{{ t('crm_objects_index.no_records') }}</td></tr>
            </tbody>
          </VTable>
        </VCard>
        <VAlert v-else type="info" variant="tonal">{{ t('crm_objects_index.select_or_create') }}</VAlert>
      </VCol>
    </VRow>

    <VDialog v-model="typeDialog" max-width="640" scrollable persistent>
      <VCard>
        <VCardTitle>{{ t('crm_objects_index.dialog_type.title') }}</VCardTitle>
        <VCardText>
          <VRow>
            <VCol cols="8"><VTextField v-model="typeForm.name" :label="t('crm_objects_index.dialog_type.name')" :placeholder="t('crm_objects_index.dialog_type.name_placeholder')" /></VCol>
            <VCol cols="4"><VTextField v-model="typeForm.icon" :label="t('crm_objects_index.dialog_type.icon')" placeholder="box" /></VCol>
          </VRow>
          <div class="text-overline mt-2">{{ t('crm_objects_index.dialog_type.fields') }}</div>
          <div v-for="(f, i) in typeForm.fields" :key="i" class="pa-3 mb-2" style="border:1px solid rgba(0,0,0,0.1); border-radius:8px;">
            <div class="d-flex gap-2 align-center">
              <VTextField v-model="f.label" :label="t('crm_objects_index.dialog_type.field_label')" density="compact" hide-details />
              <VSelect v-model="f.type" :items="fieldTypes" :label="t('crm_objects_index.dialog_type.type')" density="compact" hide-details style="max-width: 150px" />
              <VCheckbox v-model="f.required" :label="t('crm_objects_index.dialog_type.required')" density="compact" hide-details />
              <VBtn size="x-small" variant="text" icon="tabler-x" color="error" @click="removeField(i)" />
            </div>
            <VTextField v-if="['select','multiselect'].includes(f.type)" v-model="optionText[i]" :label="t('crm_objects_index.dialog_type.options_hint')" density="compact" hide-details class="mt-2" />
          </div>
          <VBtn size="small" variant="tonal" prepend-icon="tabler-plus" @click="addField">{{ t('crm_objects_index.dialog_type.add_field') }}</VBtn>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="tonal" @click="typeDialog = false">{{ t('crm_objects_index.dialog_type.cancel') }}</VBtn><VBtn color="primary" :loading="typeForm.processing" @click="saveType">{{ t('crm_objects_index.dialog_type.create') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="recordDialog" max-width="540" persistent>
      <VCard v-if="active">
        <VCardTitle>{{ editingRecord ? t('crm_objects_index.dialog_record.title_edit', { name: active.name }) : t('crm_objects_index.dialog_record.title_create', { name: active.name }) }}</VCardTitle>
        <VCardText>
          <VRow>
            <VCol v-for="f in active.fields" :key="f.name" cols="12" sm="6">
              <VTextField v-if="f.type === 'text'" v-model="recordForm.values[f.name!]" :label="f.label" />
              <VTextField v-else-if="f.type === 'number'" v-model="recordForm.values[f.name!]" type="number" :label="f.label" />
              <VTextField v-else-if="f.type === 'date'" v-model="recordForm.values[f.name!]" type="date" :label="f.label" />
              <VCheckbox v-else-if="f.type === 'boolean'" v-model="recordForm.values[f.name!]" :label="f.label" />
              <VSelect v-else-if="f.type === 'select'" v-model="recordForm.values[f.name!]" :items="f.options" :label="f.label" clearable />
              <VSelect v-else-if="f.type === 'multiselect'" v-model="recordForm.values[f.name!]" :items="f.options" :label="f.label" multiple chips />
              <VTextField v-else v-model="recordForm.values[f.name!]" :label="f.label" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="tonal" @click="recordDialog = false">{{ t('crm_objects_index.dialog_record.cancel') }}</VBtn><VBtn color="primary" :loading="recordForm.processing" @click="saveRecord">{{ t('crm_objects_index.dialog_record.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
