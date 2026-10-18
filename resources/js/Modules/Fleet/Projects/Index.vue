<script setup lang="ts">
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Project { id: number; name: string; description: string | null; company_unit_id: number | null; department: string | null; active: boolean }
interface CompanyUnit { id: number; name: string }

const props = defineProps<{ projects: Project[]; companyUnits: CompanyUnit[] }>()

const snack = ref<{ show: boolean; msg: string; color: string }>({ show: false, msg: '', color: 'success' })
function toast(msg: string, color = 'success') { snack.value = { show: true, msg, color } }

const dialog       = ref(false)
const editMode     = ref(false)
const editId       = ref<number | null>(null)
const deleteDialog = ref(false)
const deleteTarget = ref<Project | null>(null)

const form = useForm({ name: '', description: '', company_unit_id: null as number | null, active: true })

function openAdd() { editMode.value = false; editId.value = null; form.reset(); dialog.value = true }
function openEdit(p: Project) {
  editMode.value = true; editId.value = p.id
  form.name = p.name; form.description = p.description ?? ''; form.company_unit_id = p.company_unit_id; form.active = p.active
  dialog.value = true
}
function submit() {
  if (editMode.value && editId.value) {
    form.patch(route('fleet.projects.update', editId.value), { preserveScroll: true, onSuccess: () => { dialog.value = false; toast(t('fleet_projects_index.toasts.updated')) } })
  } else {
    form.post(route('fleet.projects.store'), { preserveScroll: true, onSuccess: () => { dialog.value = false; form.reset(); toast(t('fleet_projects_index.toasts.added')) } })
  }
}
function confirmRemove(p: Project) { deleteTarget.value = p; deleteDialog.value = true }
function doRemove() {
  if (!deleteTarget.value) return
  router.delete(route('fleet.projects.destroy', deleteTarget.value.id), {
    preserveScroll: true,
    onSuccess: () => { deleteDialog.value = false; toast(t('fleet_projects_index.toasts.deleted')) },
  })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4">
      <div class="d-flex align-center ga-2">
        <VIcon color="primary">tabler-folders</VIcon>
        <div>
          <h1 class="text-h5 font-weight-bold">{{ t('fleet_projects_index.page_title') }}</h1>
          <p class="text-caption text-medium-emphasis">{{ t('fleet_projects_index.description') }}</p>
        </div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openAdd">{{ t('fleet_projects_index.add_project') }}</VBtn>
    </div>

    <VSnackbar v-model="snack.show" :color="snack.color" location="top right" :timeout="3000">{{ snack.msg }}</VSnackbar>

    <VCard>
      <VTable density="comfortable">
        <thead>
          <tr>
            <th></th>
            <th>{{ t('fleet_projects_index.table.name') }}</th>
            <th>{{ t('fleet_projects_index.table.description') }}</th>
            <th>{{ t('fleet_projects_index.table.department') }}</th>
            <th>{{ t('fleet_projects_index.table.status') }}</th>
            <th class="text-right">{{ t('fleet_projects_index.table.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="p in projects" :key="p.id">
            <td><VIcon size="18" color="primary">tabler-folder</VIcon></td>
            <td class="font-weight-medium">{{ p.name }}</td>
            <td class="text-body-2 text-medium-emphasis">{{ p.description ?? '—' }}</td>
            <td>{{ p.department ?? '—' }}</td>
            <td>
              <VChip size="x-small" :color="p.active?'success':'grey'">{{ p.active ? t('fleet_projects_index.table.active') : t('fleet_projects_index.table.inactive') }}</VChip>
            </td>
            <td class="text-right">
              <VBtn size="small" variant="tonal" prepend-icon="tabler-pencil" class="mr-1" @click="openEdit(p)">{{ t('fleet_projects_index.dialog.save') }}</VBtn>
              <VBtn size="small" color="error" variant="tonal" @click="confirmRemove(p)">{{ t('fleet_projects_index.delete_dialog.confirm') }}</VBtn>
            </td>
          </tr>
          <tr v-if="!projects.length">
            <td colspan="6" class="text-center text-medium-emphasis pa-6">{{ t('fleet_projects_index.table.no_data') }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="dialog" max-width="480" persistent>
      <VCard>
        <VCardTitle class="pa-4">{{ editMode ? t('fleet_projects_index.dialog.title_edit') : t('fleet_projects_index.dialog.title_add') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12">
              <VTextField v-model="form.name" :label="t('fleet_projects_index.dialog.project_name')" variant="outlined" density="comfortable" :error-messages="form.errors.name" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="form.description" :label="t('fleet_projects_index.dialog.description')" variant="outlined" density="comfortable" rows="2" auto-grow />
            </VCol>
            <VCol cols="12">
              <VAutocomplete v-model="form.company_unit_id" :items="companyUnits" item-title="name" item-value="id" :label="t('fleet_projects_index.dialog.department_label')" variant="outlined" density="comfortable" clearable :error-messages="form.errors.company_unit_id" />
            </VCol>
            <VCol cols="12" v-if="editMode">
              <VCheckbox v-model="form.active" :label="t('fleet_projects_index.dialog.active')" density="compact" hide-details />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="dialog=false">{{ t('fleet_projects_index.dialog.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="form.processing" @click="submit">{{ editMode ? t('fleet_projects_index.dialog.save') : t('fleet_projects_index.dialog.add') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Delete Confirmation -->
    <VDialog v-model="deleteDialog" max-width="420">
      <VCard>
        <VCardTitle class="pa-4">{{ t('fleet_projects_index.delete_dialog.title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          {{ t('fleet_projects_index.delete_dialog.message', { name: deleteTarget?.name }) }}
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="tonal" @click="deleteDialog = false">{{ t('fleet_projects_index.delete_dialog.cancel') }}</VBtn>
          <VBtn color="error" variant="flat" @click="doRemove">{{ t('fleet_projects_index.delete_dialog.confirm') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
