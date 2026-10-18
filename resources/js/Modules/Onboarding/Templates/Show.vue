<script setup lang="ts">
import { ref, reactive, watch, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type TemplateTask = { id: number; type: { value: string; label: string; icon: string }; title: string; description: string | null; day_offset: number; owner_type: string | null; sort_order: number }
type TemplateDetail = { id: number; uuid: string; name: string; description: string | null; department: string | null; job_title: string | null; employment_type: string | null; is_active: boolean; tasks: TemplateTask[] }
type Option = { id: number; name: string }

const props = defineProps<{ template: TemplateDetail; departments: Option[]; positions: Option[] }>()

const departmentOptions = computed(() => props.departments.map(d => ({ title: d.name, value: d.name })))
const positionOptions = computed(() => props.positions.map(p => ({ title: p.name, value: p.name })))

function employmentTypeLabel(val: string | null) { if (!val) return null; const map: Record<string, string> = { permanent: t('onboarding_templates.employment_permanent'), contract: t('onboarding_templates.employment_contract'), part_time: t('onboarding_templates.employment_part_time'), fixed_term: t('onboarding_templates.employment_fixed_term') }; return map[val] ?? val }
function ownerTypeLabel(val: string | null) { if (!val) return null; const map: Record<string, string> = { hr: t('onboarding_templates.owner_hr'), it: t('onboarding_templates.owner_it'), manager: t('onboarding_templates.owner_manager'), buddy: t('onboarding_templates.owner_buddy') }; return map[val] ?? val }
function ownerTypeColor(val: string | null) { if (val === 'hr') return 'primary'; if (val === 'it') return 'info'; if (val === 'manager') return 'warning'; if (val === 'buddy') return 'success'; return 'default' }

const taskTypes = computed(() => [
  { title: t('onboarding_templates.task_type_meeting'), value: 'meeting', icon: 'mdi-calendar-account' },
  { title: t('onboarding_templates.task_type_training'), value: 'training', icon: 'mdi-school-outline' },
  { title: t('onboarding_templates.task_type_reading'), value: 'reading', icon: 'mdi-book-open-outline' },
  { title: t('onboarding_templates.task_type_system_setup'), value: 'system_setup', icon: 'mdi-monitor-account' },
  { title: t('onboarding_templates.task_type_introduction'), value: 'introduction', icon: 'mdi-account-multiple-outline' },
  { title: t('onboarding_templates.task_type_policy_acknowledgement'), value: 'policy_acknowledgement', icon: 'mdi-file-check-outline' },
])

const ownerTypes = computed(() => [
  { title: t('onboarding_templates.owner_hr'), value: 'hr' },
  { title: t('onboarding_templates.owner_it'), value: 'it' },
  { title: t('onboarding_templates.owner_manager'), value: 'manager' },
  { title: t('onboarding_templates.owner_buddy'), value: 'buddy' },
])

const employmentTypes = computed(() => [
  { title: t('onboarding_templates.employment_permanent'), value: 'permanent' },
  { title: t('onboarding_templates.employment_contract'), value: 'contract' },
  { title: t('onboarding_templates.employment_part_time'), value: 'part_time' },
  { title: t('onboarding_templates.employment_fixed_term'), value: 'fixed_term' },
])

const editDialog = ref(false); const submittingEdit = ref(false)
const editForm = reactive({ name: props.template.name, description: props.template.description ?? '', department: props.template.department ?? '', job_title: props.template.job_title ?? '', employment_type: props.template.employment_type ?? '', is_active: props.template.is_active })

function openEdit() { editForm.name = props.template.name; editForm.description = props.template.description ?? ''; editForm.department = props.template.department ?? ''; editForm.job_title = props.template.job_title ?? ''; editForm.employment_type = props.template.employment_type ?? ''; editForm.is_active = props.template.is_active; editDialog.value = true }
function submitEdit() { submittingEdit.value = true; router.put(route('onboarding.templates.update', [props.template.uuid]), { ...editForm }, { preserveScroll: true, onSuccess: () => { editDialog.value = false }, onFinish: () => { submittingEdit.value = false } }) }

const taskDialog = ref(false); const submittingTask = ref(false)
const taskForm = reactive({ type: '', title: '', description: '', day_offset: 0, owner_type: null as string | null })
const taskErrors = ref<Record<string, string>>({})

function resetTaskForm() { taskForm.type = ''; taskForm.title = ''; taskForm.description = ''; taskForm.day_offset = 0; taskForm.owner_type = null; taskErrors.value = {} }
function submitTask() { submittingTask.value = true; taskErrors.value = {}; router.post(route('onboarding.templates.tasks.store', [props.template.uuid]), { ...taskForm, owner_type: taskForm.owner_type || null }, { preserveScroll: true, onSuccess: () => { taskDialog.value = false; resetTaskForm() }, onError: (errors) => { taskErrors.value = errors }, onFinish: () => { submittingTask.value = false } }) }
function deleteTask(task: TemplateTask) { router.delete(route('onboarding.templates.tasks.destroy', [props.template.uuid, task.id]), { preserveScroll: true }) }

watch(taskDialog, open => { if (!open) resetTaskForm() })
watch(editDialog, open => { if (!open) resetTaskForm() })
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center gap-2 mb-4">
      <VBtn icon="mdi-arrow-left" variant="text" size="small" @click="router.visit(route('onboarding.templates.index'))" />
      <span class="text-medium-emphasis text-body-2 cursor-pointer" @click="router.visit(route('onboarding.index'))">{{ t('onboarding_templates.breadcrumb_onboarding') }}</span>
      <VIcon icon="mdi-chevron-right" size="16" class="text-medium-emphasis" />
      <span class="text-medium-emphasis text-body-2 cursor-pointer" @click="router.visit(route('onboarding.templates.index'))">{{ t('onboarding_templates.breadcrumb_templates') }}</span>
      <VIcon icon="mdi-chevron-right" size="16" class="text-medium-emphasis" />
      <span class="text-body-2 font-weight-medium">{{ template.name }}</span>
    </div>

    <div class="d-flex align-start justify-space-between mb-6">
      <div>
        <div class="d-flex align-center gap-2 mb-1">
          <h4 class="text-h4 font-weight-bold">{{ template.name }}</h4>
          <VChip :color="template.is_active ? 'success' : 'default'" size="small" label variant="tonal" class="font-weight-medium">{{ template.is_active ? t('onboarding_templates.active') : t('onboarding_templates.inactive') }}</VChip>
        </div>
        <p v-if="template.description" class="text-medium-emphasis mb-0">{{ template.description }}</p>
      </div>
      <div class="d-flex gap-2">
        <VBtn color="default" variant="tonal" prepend-icon="mdi-pencil-outline" @click="openEdit">{{ t('onboarding_templates.edit_template') }}</VBtn>
        <VBtn color="primary" variant="tonal" prepend-icon="mdi-school-outline" @click="router.visit(route('pre-boarding.index'))">{{ t('onboarding_templates.use_template') }}</VBtn>
      </div>
    </div>

    <div class="d-flex flex-wrap gap-4 mb-6">
      <div class="d-flex align-center gap-1"><VIcon icon="mdi-domain" size="16" color="medium-emphasis" /><span class="text-body-2 text-medium-emphasis">{{ template.department ?? t('onboarding_templates.all_departments') }}</span></div>
      <div class="d-flex align-center gap-1"><VIcon icon="mdi-briefcase-outline" size="16" color="medium-emphasis" /><span class="text-body-2 text-medium-emphasis">{{ template.job_title ?? t('onboarding_templates.all_job_titles') }}</span></div>
      <div v-if="template.employment_type" class="d-flex align-center gap-1"><VIcon icon="mdi-card-account-details-outline" size="16" color="medium-emphasis" /><VChip size="x-small" color="default" variant="tonal" label>{{ employmentTypeLabel(template.employment_type) }}</VChip></div>
      <div class="d-flex align-center gap-1"><VIcon icon="mdi-clipboard-list-outline" size="16" color="medium-emphasis" /><span class="text-body-2 text-medium-emphasis">{{ t('onboarding_templates.tasks_count', { count: template.tasks.length }) }}</span></div>
    </div>

    <VCard rounded="lg" elevation="0" border>
      <VCardTitle class="pa-5 pb-2 d-flex align-center justify-space-between"><span class="text-body-1 font-weight-semibold">{{ t('onboarding_templates.template_tasks') }}</span><VBtn size="small" color="primary" variant="tonal" prepend-icon="mdi-plus" @click="taskDialog = true">{{ t('onboarding_templates.add_task') }}</VBtn></VCardTitle>
      <VCardText class="pa-5 pt-0">
        <div v-if="template.tasks.length" class="d-flex flex-column gap-3">
          <div v-for="task in template.tasks" :key="task.id" class="d-flex align-start gap-3 pa-3 rounded-lg" style="border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));">
            <VIcon icon="mdi-drag-vertical" size="18" color="medium-emphasis" class="mt-1 flex-shrink-0 cursor-grab" />
            <VIcon :icon="task.type.icon" color="primary" size="18" class="mt-1 flex-shrink-0" />
            <div class="flex-grow-1"><p class="text-body-2 font-weight-medium mb-1">{{ task.title }}</p><p v-if="task.description" class="text-caption text-medium-emphasis mb-1">{{ task.description }}</p><div class="d-flex align-center gap-2 flex-wrap"><VChip size="x-small" color="default" variant="tonal" label>{{ t('onboarding_templates.day_label') }} {{ task.day_offset }}</VChip><VChip size="x-small" color="default" variant="text">{{ task.type.label }}</VChip><VChip v-if="task.owner_type" size="x-small" :color="ownerTypeColor(task.owner_type)" variant="tonal" label>{{ ownerTypeLabel(task.owner_type) }}</VChip></div></div>
            <VBtn size="x-small" color="error" variant="text" icon="mdi-delete-outline" class="flex-shrink-0" @click="deleteTask(task)" />
          </div>
        </div>
        <div v-else class="text-center py-10"><VIcon icon="mdi-clipboard-list-outline" size="52" color="medium-emphasis" class="mb-3 d-block mx-auto" /><p class="text-body-2 font-weight-medium mb-1">{{ t('onboarding_templates.no_tasks') }}</p><p class="text-caption text-medium-emphasis mb-4">{{ t('onboarding_templates.no_tasks_sub') }}</p><VBtn size="small" color="primary" variant="tonal" prepend-icon="mdi-plus" @click="taskDialog = true">{{ t('onboarding_templates.add_task') }}</VBtn></div>
      </VCardText>
    </VCard>

    <VDialog v-model="editDialog" max-width="520">
      <VCard rounded="lg">
        <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">{{ t('onboarding_templates.dialog_edit_title') }}</VCardTitle>
        <VCardText class="pa-5 pt-2 d-flex flex-column gap-4">
          <VTextField v-model="editForm.name" :label="t('onboarding_templates.field_template_name')" variant="outlined" density="comfortable" hide-details="auto" />
          <VTextarea v-model="editForm.description" :label="t('onboarding_templates.field_description_optional')" variant="outlined" density="comfortable" rows="2" hide-details="auto" />
          <VRow dense><VCol cols="12" sm="6"><VSelect v-model="editForm.department" :items="departmentOptions" :label="t('onboarding_templates.field_department_optional')" variant="outlined" density="comfortable" clearable hide-details="auto" /></VCol><VCol cols="12" sm="6"><VSelect v-model="editForm.job_title" :items="positionOptions" :label="t('onboarding_templates.field_job_title_optional')" variant="outlined" density="comfortable" clearable hide-details="auto" /></VCol></VRow>
          <VSelect v-model="editForm.employment_type" :items="employmentTypes" :label="t('onboarding_templates.field_employment_type_optional')" variant="outlined" density="comfortable" clearable hide-details="auto" />
          <VSwitch v-model="editForm.is_active" :label="t('onboarding_templates.field_active')" color="primary" hide-details />
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2"><VSpacer /><VBtn variant="text" color="default" :disabled="submittingEdit" @click="editDialog = false">{{ t('onboarding_templates.cancel') }}</VBtn><VBtn color="primary" variant="flat" :loading="submittingEdit" :disabled="!editForm.name" @click="submitEdit">{{ t('onboarding_templates.save_changes') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="taskDialog" max-width="520">
      <VCard rounded="lg">
        <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">{{ t('onboarding_templates.dialog_task_title') }}</VCardTitle>
        <VCardText class="pa-5 pt-2 d-flex flex-column gap-4">
          <VSelect v-model="taskForm.type" :items="taskTypes" item-title="title" item-value="value" :label="t('onboarding_templates.field_task_type')" variant="outlined" density="comfortable" :error-messages="taskErrors.type" />
          <VTextField v-model="taskForm.title" :label="t('onboarding_templates.field_title')" variant="outlined" density="comfortable" :error-messages="taskErrors.title" />
          <VTextarea v-model="taskForm.description" :label="t('onboarding_templates.field_description_optional')" variant="outlined" density="comfortable" rows="2" hide-details="auto" />
          <VRow dense>
            <VCol cols="12" sm="6"><VTextField v-model.number="taskForm.day_offset" :label="t('onboarding_templates.field_day_offset')" type="number" :min="0" :max="90" :hint="t('onboarding_templates.field_day_offset_hint')" variant="outlined" density="comfortable" persistent-hint :error-messages="taskErrors.day_offset" /></VCol>
            <VCol cols="12" sm="6"><VSelect v-model="taskForm.owner_type" :items="ownerTypes" :label="t('onboarding_templates.field_owner_optional')" variant="outlined" density="comfortable" clearable hide-details="auto" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2"><VSpacer /><VBtn variant="text" color="default" :disabled="submittingTask" @click="taskDialog = false">{{ t('onboarding_templates.cancel') }}</VBtn><VBtn color="primary" variant="flat" :loading="submittingTask" :disabled="!taskForm.type || !taskForm.title" @click="submitTask">{{ t('onboarding_templates.add_task_btn') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
