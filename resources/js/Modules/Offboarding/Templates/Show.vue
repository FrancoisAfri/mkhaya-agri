<script setup lang="ts">
import { ref, reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Task = { id: number; title: string; description: string | null; category: string; assigned_role: string | null; days_before_last_day: number; sort_order: number }
type Template = { id: number; uuid: string; title: string; description: string | null; notice_period_days: number; tasks: Task[] }

const props = defineProps<{ template: Template; roles: string[] }>()

const confirmDialog = ref()

// ── Edit template header ──────────────────────────────────────────────────────
const editingHeader = ref(false)
const headerForm = reactive({ title: props.template.title, description: props.template.description ?? '', notice_period_days: props.template.notice_period_days })
function saveHeader() { router.put(route('offboarding.templates.update', { template: props.template.id }), headerForm, { preserveScroll: true, onSuccess: () => { editingHeader.value = false } }) }

const taskDialog = ref(false); const editingTask = ref<Task | null>(null); const submittingTask = ref(false)
const taskForm = reactive({ title: '', description: '', category: 'hr', assigned_role: '', days_before_last_day: 0 })

function openAddTask() { editingTask.value = null; taskForm.title = ''; taskForm.description = ''; taskForm.category = 'hr'; taskForm.assigned_role = ''; taskForm.days_before_last_day = 0; taskDialog.value = true }
function openEditTask(task: Task) { editingTask.value = task; taskForm.title = task.title; taskForm.description = task.description ?? ''; taskForm.category = task.category; taskForm.assigned_role = task.assigned_role ?? ''; taskForm.days_before_last_day = task.days_before_last_day; taskDialog.value = true }
function submitTask() { submittingTask.value = true; const url = editingTask.value ? route('offboarding.templates.tasks.update', { template: props.template.id, task: editingTask.value.id }) : route('offboarding.templates.tasks.store', { template: props.template.id }); const method = editingTask.value ? 'put' : 'post'; router[method](url, taskForm, { preserveScroll: true, onFinish: () => { submittingTask.value = false }, onSuccess: () => { taskDialog.value = false } }) }
function deleteTask(task: Task) {
  confirmDialog.value.open(
    { title: 'Delete Task', message: `Delete task "${task.title}"?`, confirmText: 'Delete', confirmColor: 'error', icon: 'tabler-trash' },
    () => {
      router.delete(route('offboarding.templates.tasks.destroy', { template: props.template.id, task: task.id }), {
        preserveScroll: true,
      })
    },
  )
}

const categoryColors: Record<string, string> = { hr: 'primary', it: 'info', finance: 'success', facilities: 'secondary', manager: 'warning' }
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center gap-2 mb-6">
      <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('offboarding.templates.index'))" />
      <div class="flex-1-1">
        <template v-if="!editingHeader">
          <div class="d-flex align-center gap-3"><h4 class="text-h4">{{ template.title }}</h4><VBtn icon="tabler-edit" size="x-small" variant="text" color="default" @click="editingHeader = true" /></div>
          <p v-if="template.description" class="text-medium-emphasis mb-0">{{ template.description }}</p>
          <p class="text-caption text-medium-emphasis mb-0">{{ t('offboarding_templates.notice_period_label', { days: template.notice_period_days }) }}</p>
        </template>
        <template v-else>
          <VRow dense class="mb-0">
            <VCol cols="12" md="5"><VTextField v-model="headerForm.title" :label="t('offboarding_templates.field_title')" variant="outlined" density="compact" hide-details /></VCol>
            <VCol cols="12" md="4"><VTextField v-model="headerForm.description" :label="t('offboarding_templates.field_description')" variant="outlined" density="compact" hide-details /></VCol>
            <VCol cols="6" md="2"><VTextField v-model="headerForm.notice_period_days" :label="t('offboarding_templates.field_notice_period_days')" type="number" variant="outlined" density="compact" hide-details /></VCol>
            <VCol cols="6" md="1" class="d-flex align-center gap-1"><VBtn color="primary" size="small" @click="saveHeader">{{ t('offboarding_templates.detail_save') }}</VBtn><VBtn size="small" variant="text" @click="editingHeader = false">{{ t('offboarding_templates.cancel') }}</VBtn></VCol>
          </VRow>
        </template>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" class="ms-auto" @click="openAddTask">{{ t('offboarding_templates.detail_add_task') }}</VBtn>
    </div>

    <VCard elevation="0" border>
      <VList v-if="template.tasks.length">
        <VListItem v-for="task in template.tasks" :key="task.id" :subtitle="task.description ?? undefined">
          <template #prepend><VAvatar :color="categoryColors[task.category] ?? 'default'" variant="tonal" size="36" rounded class="me-3"><span class="text-caption font-weight-bold">{{ task.category.substring(0, 2).toUpperCase() }}</span></VAvatar></template>
          <template #title><span class="text-body-2 font-weight-medium">{{ task.title }}</span></template>
          <template #append>
            <div class="d-flex align-center gap-3">
              <VChip :color="categoryColors[task.category] ?? 'default'" size="x-small" variant="tonal">{{ task.category }}</VChip>
              <span v-if="task.days_before_last_day > 0" class="text-caption text-medium-emphasis">{{ task.days_before_last_day }}{{ t('offboarding_templates.detail_days_before_exit') }}</span>
              <VBtn icon="tabler-edit" size="x-small" variant="text" color="primary" @click="openEditTask(task)" />
              <VBtn icon="tabler-trash" size="x-small" variant="text" color="error" @click="deleteTask(task)" />
            </div>
          </template>
        </VListItem>
      </VList>
      <VCardText v-else class="text-center py-12"><VIcon icon="tabler-checklist" size="56" color="secondary" class="mb-3" /><p class="text-body-1 text-medium-emphasis mb-4">{{ t('offboarding_templates.detail_no_tasks') }}</p><VBtn color="primary" variant="tonal" prepend-icon="tabler-plus" @click="openAddTask">{{ t('offboarding_templates.detail_add_task') }}</VBtn></VCardText>
    </VCard>

    <VDialog v-model="taskDialog" max-width="560">
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-h6">{{ editingTask ? t('offboarding_templates.dialog_task_title_edit') : t('offboarding_templates.dialog_task_title_add') }}</VCardTitle><VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12"><VTextField v-model="taskForm.title" :label="t('offboarding_templates.field_title')" variant="outlined" density="compact" /></VCol>
            <VCol cols="12"><VTextarea v-model="taskForm.description" :label="t('offboarding_templates.field_description')" variant="outlined" density="compact" rows="2" /></VCol>
            <VCol cols="6"><VSelect v-model="taskForm.category" :items="categoryOptions" item-title="title" item-value="value" :label="t('offboarding_templates.field_category')" variant="outlined" density="compact" /></VCol>
            <VCol cols="6"><VTextField v-model="taskForm.days_before_last_day" :label="t('offboarding_templates.field_days_before_exit')" type="number" variant="outlined" density="compact" /></VCol>
            <VCol cols="12"><VCombobox v-model="taskForm.assigned_role" :items="roles" :label="t('offboarding_templates.field_assigned_role')" variant="outlined" density="compact" clearable :placeholder="t('offboarding_templates.field_assigned_role_placeholder')" /></VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="taskDialog = false">{{ t('offboarding_templates.cancel') }}</VBtn><VBtn color="primary" :loading="submittingTask" :disabled="!taskForm.title" @click="submitTask">{{ editingTask ? t('offboarding_templates.save') : t('offboarding_templates.add_task') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmDialog" />

  </VContainer>
</template>
