<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Stage = {
  id?: number; stage_number?: number; name: string
  approver_role: string | null; approver_user_id: number | null
  is_required: boolean; min_approvers: number
}
type Workflow = {
  id: number; uuid: string; name: string; description: string | null
  entity_type: string; is_active: boolean; stages: Stage[]
}
type Option = { value: string; label: string }

const props = defineProps<{
  workflows: Workflow[]; entityTypes: Option[]; roles: string[]
}>()

const showDialog   = ref(false)
const deleteDialog = ref(false)
const editingId    = ref<number | null>(null)
const deletingId   = ref<number | null>(null)

const blank = (): Stage => ({
  name: '', approver_role: null, approver_user_id: null, is_required: true, min_approvers: 1,
})

const form = useForm<{ name: string; description: string; entity_type: string; is_active: boolean; stages: Stage[] }>({
  name: '', description: '', entity_type: 'requisition', is_active: true, stages: [blank()],
})

function openCreate() { editingId.value = null; form.reset(); form.stages = [blank()]; form.clearErrors(); showDialog.value = true }
function openEdit(w: Workflow) { editingId.value = w.id; form.name = w.name; form.description = w.description ?? ''; form.entity_type = w.entity_type; form.is_active = w.is_active; form.stages = w.stages.length ? w.stages.map(s => ({ ...s })) : [blank()]; form.clearErrors(); showDialog.value = true }
function addStage() { form.stages.push(blank()) }
function removeStage(i: number) { if (form.stages.length > 1) form.stages.splice(i, 1) }
function moveStage(i: number, dir: -1 | 1) { const j = i + dir; if (j < 0 || j >= form.stages.length) return; const tmp = form.stages[i]; form.stages[i] = form.stages[j]; form.stages[j] = tmp }

function save() {
  if (editingId.value) {
    form.put(route('recruitment.settings.approval-workflows.update', editingId.value), { onSuccess: () => { showDialog.value = false }, preserveScroll: true })
  } else {
    form.post(route('recruitment.settings.approval-workflows.store'), { onSuccess: () => { showDialog.value = false; form.reset(); form.stages = [blank()] }, preserveScroll: true })
  }
}

function confirmDelete(id: number) { deletingId.value = id; deleteDialog.value = true }
function doDelete() { if (!deletingId.value) return; router.delete(route('recruitment.settings.approval-workflows.destroy', deletingId.value), { onSuccess: () => { deleteDialog.value = false }, preserveScroll: true }) }

const groupedWorkflows = computed(() => {
  const groups: Record<string, Workflow[]> = {}
  for (const t of props.entityTypes) groups[t.value] = []
  for (const w of props.workflows) { (groups[w.entity_type] ??= []).push(w) }
  return groups
})

function entityLabel(value: string): string { return props.entityTypes.find(t => t.value === value)?.label ?? value }
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <h4 class="text-h4">{{ t('recruitment_settings_approval_workflows.title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('recruitment_settings_approval_workflows.subtitle') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">{{ t('recruitment_settings_approval_workflows.new_workflow') }}</VBtn>
    </div>

    <div v-for="(group, type) in groupedWorkflows" :key="type" class="mb-8">
      <h5 class="text-h6 mb-3">{{ entityLabel(type) }}</h5>
      <VAlert v-if="!group.length" type="info" variant="tonal" density="compact">
        {{ t('recruitment_settings_approval_workflows.no_workflow', { entity: entityLabel(type).toLowerCase() }) }}
      </VAlert>
      <VRow v-else>
        <VCol v-for="w in group" :key="w.id" cols="12" md="6">
          <VCard border>
            <VCardTitle class="d-flex align-center justify-space-between">
              <div>
                {{ w.name }}
                <VChip :color="w.is_active ? 'success' : 'default'" size="small" variant="tonal" class="ms-2">
                  {{ w.is_active ? t('recruitment_settings_approval_workflows.active') : t('recruitment_settings_approval_workflows.inactive') }}
                </VChip>
              </div>
              <div>
                <VBtn icon="tabler-pencil" variant="text" size="small" @click="openEdit(w)" />
                <VBtn icon="tabler-trash" variant="text" size="small" color="error" @click="confirmDelete(w.id)" />
              </div>
            </VCardTitle>
            <VCardText>
              <p v-if="w.description" class="text-body-2 mb-3">{{ w.description }}</p>
              <VList density="compact">
                <VListItem v-for="(s, i) in w.stages" :key="s.id ?? i" :prepend-icon="s.is_required ? 'tabler-circle-check' : 'tabler-circle'">
                  <VListItemTitle>
                    <strong>{{ i + 1 }}.</strong> {{ s.name }}
                    <span v-if="s.approver_role" class="text-medium-emphasis">{{ t('recruitment_settings_approval_workflows.role_prefix', { role: s.approver_role }) }}</span>
                    <span v-else-if="s.approver_user_id" class="text-medium-emphasis">{{ t('recruitment_settings_approval_workflows.user_prefix', { id: s.approver_user_id }) }}</span>
                  </VListItemTitle>
                  <VListItemSubtitle>
                    {{ t('recruitment_settings_approval_workflows.min_approvers', { count: s.min_approvers }) }} · {{ s.is_required ? t('recruitment_settings_approval_workflows.required') : t('recruitment_settings_approval_workflows.optional') }}
                  </VListItemSubtitle>
                </VListItem>
              </VList>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </div>

    <VDialog v-model="showDialog" max-width="900" persistent scrollable>
      <VCard>
        <VCardTitle>{{ editingId ? t('recruitment_settings_approval_workflows.dialog_edit_title') : t('recruitment_settings_approval_workflows.dialog_create_title') }}</VCardTitle>
        <VDivider />
        <VCardText style="max-height: 70vh;">
          <VRow>
            <VCol cols="12" md="8"><VTextField v-model="form.name" :label="t('recruitment_settings_approval_workflows.field_name')" variant="outlined" density="comfortable" :error-messages="form.errors.name" /></VCol>
            <VCol cols="12" md="4"><VSelect v-model="form.entity_type" :items="entityTypes" item-title="label" item-value="value" :label="t('recruitment_settings_approval_workflows.field_applies_to')" variant="outlined" density="comfortable" :error-messages="form.errors.entity_type" /></VCol>
            <VCol cols="12"><VTextarea v-model="form.description" :label="t('recruitment_settings_approval_workflows.field_description')" variant="outlined" density="comfortable" rows="2" :error-messages="form.errors.description" /></VCol>
            <VCol cols="12"><VSwitch v-model="form.is_active" :label="t('recruitment_settings_approval_workflows.field_active')" color="primary" hide-details /></VCol>
          </VRow>
          <VDivider class="my-4" />
          <div class="d-flex align-center justify-space-between mb-3">
            <h6 class="text-h6 mb-0">{{ t('recruitment_settings_approval_workflows.stages_title') }}</h6>
            <VBtn size="small" prepend-icon="tabler-plus" @click="addStage">{{ t('recruitment_settings_approval_workflows.add_stage') }}</VBtn>
          </div>
          <VCard v-for="(stage, i) in form.stages" :key="i" variant="outlined" class="mb-3 pa-3">
            <VRow dense>
              <VCol cols="12" md="1" class="d-flex flex-column align-center justify-center"><strong>{{ i + 1 }}</strong><VBtn icon="tabler-arrow-up" variant="text" size="x-small" :disabled="i === 0" @click="moveStage(i, -1)" /><VBtn icon="tabler-arrow-down" variant="text" size="x-small" :disabled="i === form.stages.length - 1" @click="moveStage(i, 1)" /></VCol>
              <VCol cols="12" md="4"><VTextField v-model="stage.name" :label="t('recruitment_settings_approval_workflows.field_stage_name')" variant="outlined" density="compact" :error-messages="form.errors[`stages.${i}.name`]" /></VCol>
              <VCol cols="12" md="3"><VSelect v-model="stage.approver_role" :items="roles" :label="t('recruitment_settings_approval_workflows.field_approver_role')" variant="outlined" density="compact" clearable :error-messages="form.errors[`stages.${i}.approver_role`]" /></VCol>
              <VCol cols="6" md="2"><VTextField v-model.number="stage.min_approvers" :label="t('recruitment_settings_approval_workflows.field_min_approvers')" type="number" variant="outlined" density="compact" :min="1" :max="10" /></VCol>
              <VCol cols="4" md="1" class="d-flex align-center"><VSwitch v-model="stage.is_required" :label="t('recruitment_settings_approval_workflows.field_required')" color="primary" hide-details density="compact" /></VCol>
              <VCol cols="2" md="1" class="d-flex align-center justify-end"><VBtn icon="tabler-x" variant="text" size="small" color="error" :disabled="form.stages.length === 1" @click="removeStage(i)" /></VCol>
            </VRow>
          </VCard>
        </VCardText>
        <VDivider />
        <VCardActions><VSpacer /><VBtn variant="text" @click="showDialog = false">{{ t('recruitment_settings_approval_workflows.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" @click="save">{{ editingId ? t('recruitment_settings_approval_workflows.save_changes') : t('recruitment_settings_approval_workflows.create_workflow') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="deleteDialog" max-width="420">
      <VCard>
        <VCardTitle>{{ t('recruitment_settings_approval_workflows.delete_title') }}</VCardTitle>
        <VCardText>{{ t('recruitment_settings_approval_workflows.delete_text') }}</VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="deleteDialog = false">{{ t('recruitment_settings_approval_workflows.cancel') }}</VBtn><VBtn color="error" @click="doDelete">{{ t('recruitment_settings_approval_workflows.delete') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
