<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Action {
  id: number; action_number: string; employee: string; employee_id: number
  action_type: string; title: string; due_date: string; completed_date: string|null
  status: string; progress_percent: number; is_mandatory: boolean
  ai_recommended: boolean; case_number: string|null; assigned_by: string
}
interface Stats { pending: number; in_progress: number; completed: number; overdue: number }
interface Paginated<T> { data: T[]; current_page: number; last_page: number; total: number }
interface Employee { id: number; name: string; email: string }
interface OpenCase { id: number; label: string }

const props = defineProps<{
  actions: Paginated<Action>
  stats: Stats
  filters: Record<string, string>
  employees: Employee[]
  open_cases: OpenCase[]
}>()

const createDialog = ref(false)
const createForm   = useForm({
  employee_id: null as number|null, action_type: 'counseling', title: '', description: '',
  due_date: '', case_id: null as number|null, is_mandatory: false,
})

function submitCreate() {
  createForm.post(route('disciplinary.corrective-actions.store'), {
    onSuccess: () => { createDialog.value = false; createForm.reset() },
  })
}

const statusColor = (s: string) => ({ pending: 'warning', in_progress: 'blue', completed: 'success', failed: 'error', cancelled: 'grey' })[s] ?? 'grey'

const actionTypes = [
  { value: 'training', title: t('disciplinary_corrective-action.action_types.training') },
  { value: 'counseling', title: t('disciplinary_corrective-action.action_types.counseling') },
  { value: 'coaching', title: t('disciplinary_corrective-action.action_types.coaching') },
  { value: 'performance_plan', title: t('disciplinary_corrective-action.action_types.performance_plan') },
  { value: 'work_shadowing', title: t('disciplinary_corrective-action.action_types.work_shadowing') },
  { value: 'mentoring', title: t('disciplinary_corrective-action.action_types.mentoring') },
  { value: 'other', title: t('disciplinary_corrective-action.action_types.other') },
]
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('disciplinary_corrective-action.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('disciplinary_corrective-action.subtitle') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="createDialog = true">{{ t('disciplinary_corrective-action.new_action') }}</VBtn>
    </div>

    <VRow class="mb-4">
      <VCol cols="12" sm="3" v-for="(val, key) in stats" :key="key">
        <VCard variant="tonal" :color="key === 'overdue' ? 'error' : key === 'completed' ? 'success' : 'primary'">
          <VCardText class="text-center">
            <div class="text-h4 font-weight-bold">{{ val }}</div>
            <div class="text-caption text-uppercase mt-1">{{ key === 'in_progress' ? t('disciplinary_corrective-action.stats.in_progress') : key === 'pending' ? t('disciplinary_corrective-action.stats.pending') : key === 'completed' ? t('disciplinary_corrective-action.stats.completed') : t('disciplinary_corrective-action.stats.overdue') }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard>
      <VDataTable
        :items="actions.data"
        :headers="[
          { title: t('disciplinary_corrective-action.table.number'), key: 'action_number' },
          { title: t('disciplinary_corrective-action.table.employee'), key: 'employee' },
          { title: t('disciplinary_corrective-action.table.type'), key: 'action_type' },
          { title: t('disciplinary_corrective-action.table.title'), key: 'title' },
          { title: t('disciplinary_corrective-action.table.due'), key: 'due_date' },
          { title: t('disciplinary_corrective-action.table.progress'), key: 'progress_percent' },
          { title: t('disciplinary_corrective-action.table.status'), key: 'status' },
          { title: t('disciplinary_corrective-action.table.actions'), key: '_actions', sortable: false },
        ]"
      >
        <template #item.action_type="{ item }">
          <VChip size="small" color="blue-grey" variant="tonal">{{ item.action_type.replace('_', ' ') }}</VChip>
        </template>
        <template #item.status="{ item }">
          <VChip :color="statusColor(item.status)" size="small" variant="tonal">{{ item.status.replace('_', ' ') }}</VChip>
        </template>
        <template #item.progress_percent="{ item }">
          <VProgressLinear :model-value="item.progress_percent" color="primary" height="14" rounded>
            <template #default>{{ item.progress_percent }}%</template>
          </VProgressLinear>
        </template>
        <template #item._actions="{ item }">
          <VBtn size="small" variant="text" color="primary" :href="route('disciplinary.corrective-actions.show', item.id)">{{ t('disciplinary_corrective-action.table.view') }}</VBtn>
        </template>
      </VDataTable>
    </VCard>

    <VDialog v-model="createDialog" max-width="560">
      <VCard>
        <VCardTitle class="pa-4">{{ t('disciplinary_corrective-action.dialog.title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12">
              <VAutocomplete
                v-model="createForm.employee_id"
                :items="employees"
                item-title="name"
                item-value="id"
                :label="t('disciplinary_corrective-action.dialog.employee_label')"
                variant="outlined"
                density="compact"
                :error-messages="createForm.errors.employee_id"
                clearable
              >
                <template #item="{ props: itemProps, item }">
                  <VListItem v-bind="itemProps" :subtitle="item.raw.email" />
                </template>
              </VAutocomplete>
            </VCol>
            <VCol cols="12">
              <VSelect v-model="createForm.action_type" :label="t('disciplinary_corrective-action.dialog.action_type_label')" :items="actionTypes" :error-messages="createForm.errors.action_type" />
            </VCol>
            <VCol cols="12">
              <VTextField v-model="createForm.title" :label="t('disciplinary_corrective-action.dialog.title_label')" :error-messages="createForm.errors.title" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="createForm.description" :label="t('disciplinary_corrective-action.dialog.description_label')" rows="3" :error-messages="createForm.errors.description" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="createForm.due_date" :label="t('disciplinary_corrective-action.dialog.due_date_label')" type="date" :error-messages="createForm.errors.due_date" />
            </VCol>
            <VCol cols="6">
              <VAutocomplete
                v-model="createForm.case_id"
                :items="open_cases"
                item-title="label"
                item-value="id"
                :label="t('disciplinary_corrective-action.dialog.case_label')"
                variant="outlined"
                density="compact"
                :error-messages="createForm.errors.case_id"
                clearable
              />
            </VCol>
            <VCol cols="12">
              <VCheckbox v-model="createForm.is_mandatory" :label="t('disciplinary_corrective-action.dialog.mandatory_label')" />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="createDialog = false">{{ t('disciplinary_corrective-action.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="createForm.processing" @click="submitCreate">{{ t('disciplinary_corrective-action.dialog.create') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
