<script lang="ts" setup>
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface Progress { actual: number; target: number; percent: number | null }
interface Metric { metric: string; label: string; icon: string; format: string; weekly: Progress; monthly: Progress }
interface Scorecard { metrics: Metric[]; weekly: { from: string; to: string }; monthly: { from: string; to: string } }
interface Task { id: number; subject: string; type: string; due_at: string | null; overdue: boolean }
interface RepSeries { categories: string[]; reps: { name: string; data: number[] }[] }

defineProps<{ scorecard: Scorecard; tasks: Task[]; repMonthly: RepSeries; repWeekly: RepSeries }>()

const fmt = (v: number, f: string) => f === 'currency' ? 'R ' + (v ?? 0).toLocaleString('en-ZA', { maximumFractionDigits: 0 }) : (v ?? 0).toLocaleString('en-ZA')
const pctColor = (p: number | null) => p === null ? 'grey' : p >= 100 ? 'success' : p >= 60 ? 'primary' : 'warning'

const links = [
  { title: t('crm_my_dashboard.links.my_day'), icon: 'tabler-sun', to: route('crm.my-day') },
  { title: t('crm_my_dashboard.links.make_calls'), icon: 'tabler-phone', to: route('crm.calls') },
  { title: t('crm_my_dashboard.links.my_campaigns'), icon: 'tabler-speakerphone', to: route('crm.campaigns') },
  { title: t('crm_my_dashboard.links.my_deals'), icon: 'tabler-chart-arrows-vertical', to: route('crm.deals') },
  { title: t('crm_my_dashboard.links.my_leads'), icon: 'tabler-user-plus', to: route('crm.leads') },
  { title: t('crm_my_dashboard.links.activities'), icon: 'tabler-timeline', to: route('crm.activities') },
]

// Quick-add a personal task / reminder (no related record needed).
const taskDialog = ref(false)
const taskForm = useForm({ type: 'task', subject: '', due_at: '' })
function addTask() {
  taskForm.post(route('crm.activities.store'), { preserveScroll: true, onSuccess: () => { taskDialog.value = false; taskForm.reset() } })
}
function completeTask(id: number) { useForm({}).post(route('crm.activities.complete', id), { preserveScroll: true }) }
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h5 class="text-h5 font-weight-semibold">{{ t('crm_my_dashboard.title') }}</h5>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_my_dashboard.subtitle') }}</p>
    </div>

    <div class="d-flex flex-wrap gap-2 mb-4">
      <VBtn v-for="l in links" :key="l.title" :href="l.to" variant="tonal" :prepend-icon="l.icon" size="small">{{ l.title }}</VBtn>
    </div>

    <!-- Scorecard: weekly + monthly progress per metric -->
    <VRow>
      <VCol v-for="m in scorecard.metrics" :key="m.metric" cols="12" sm="6" md="4">
        <VCard class="h-100">
          <VCardText>
            <div class="d-flex align-center ga-2 mb-3">
              <VIcon :icon="`tabler-${m.icon}`" color="primary" />
              <span class="font-weight-medium">{{ m.label }}</span>
            </div>

            <!-- Weekly -->
            <div class="d-flex align-center justify-space-between">
              <span class="text-caption text-medium-emphasis">{{ t('crm_my_dashboard.weekly') }}</span>
              <VChip :color="pctColor(m.weekly.percent)" size="x-small" label>{{ m.weekly.percent === null ? t('crm_my_dashboard.no_target') : m.weekly.percent + '%' }}</VChip>
            </div>
            <div class="text-body-2">
              {{ fmt(m.weekly.actual, m.format) }}
              <span class="text-medium-emphasis">/ {{ m.weekly.target > 0 ? fmt(m.weekly.target, m.format) : '—' }}</span>
            </div>
            <VProgressLinear :model-value="Math.min(100, m.weekly.percent ?? 0)" :color="pctColor(m.weekly.percent)" height="5" rounded class="mb-3 mt-1" />

            <!-- Monthly -->
            <div class="d-flex align-center justify-space-between">
              <span class="text-caption text-medium-emphasis">{{ t('crm_my_dashboard.monthly') }}</span>
              <VChip :color="pctColor(m.monthly.percent)" size="x-small" label>{{ m.monthly.percent === null ? t('crm_my_dashboard.no_target') : m.monthly.percent + '%' }}</VChip>
            </div>
            <div class="text-body-2">
              {{ fmt(m.monthly.actual, m.format) }}
              <span class="text-medium-emphasis">/ {{ m.monthly.target > 0 ? fmt(m.monthly.target, m.format) : '—' }}</span>
            </div>
            <VProgressLinear :model-value="Math.min(100, m.monthly.percent ?? 0)" :color="pctColor(m.monthly.percent)" height="5" rounded class="mt-1" />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <RepPerformanceChart :monthly="repMonthly" :weekly="repWeekly" :title="t('crm_my_dashboard.chart_title')" class="mt-4" />

    <VCard class="mt-4">
      <VCardItem>
        <VCardTitle>{{ t('crm_my_dashboard.open_tasks_title') }}</VCardTitle>
        <template #append>
          <VBtn size="small" variant="tonal" color="primary" prepend-icon="tabler-plus" @click="taskDialog = true">{{ t('crm_my_dashboard.add_task') }}</VBtn>
        </template>
      </VCardItem>
      <VList v-if="tasks.length" lines="one">
        <template v-for="task in tasks" :key="task.id">
          <VListItem>
            <template #prepend><VIcon :icon="task.overdue ? 'tabler-alert-circle' : 'tabler-clock'" :color="task.overdue ? 'error' : 'primary'" /></template>
            <VListItemTitle>{{ task.subject }}</VListItemTitle>
            <VListItemSubtitle :class="task.overdue ? 'text-error' : ''">{{ task.type }} · {{ t('crm_my_dashboard.due') }} {{ task.due_at }}</VListItemSubtitle>
            <template #append>
              <VBtn size="x-small" variant="text" color="success" icon="tabler-check" @click="completeTask(task.id)" />
            </template>
          </VListItem>
          <VDivider />
        </template>
      </VList>
      <VCardText v-else class="text-medium-emphasis">{{ t('crm_my_dashboard.no_tasks') }}</VCardText>
    </VCard>

    <!-- Quick add task / reminder -->
    <VDialog v-model="taskDialog" max-width="460">
      <VCard>
        <VCardTitle>{{ t('crm_my_dashboard.dialog_title') }}</VCardTitle>
        <VCardText>
          <VRow>
            <VCol cols="12"><VTextField v-model="taskForm.subject" :label="t('crm_my_dashboard.subject_label')" :error-messages="taskForm.errors.subject" autofocus /></VCol>
            <VCol cols="6"><VSelect v-model="taskForm.type" :label="t('crm_my_dashboard.type_label')" :items="[{ title: t('crm_my_dashboard.type_task'), value: 'task' }, { title: t('crm_my_dashboard.type_reminder'), value: 'reminder' }]" /></VCol>
            <VCol cols="6"><AppDateTimePicker v-model="taskForm.due_at" :label="t('crm_my_dashboard.due_label')" :placeholder="t('crm_my_dashboard.due_placeholder')" :config="{ enableTime: true, dateFormat: 'Y-m-d H:i', altInput: true, altFormat: 'd M Y, H:i', minDate: 'today' }" :error-messages="taskForm.errors.due_at" /></VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="tonal" @click="taskDialog = false">{{ t('crm_my_dashboard.cancel') }}</VBtn><VBtn color="primary" :loading="taskForm.processing" :disabled="!taskForm.subject || !taskForm.due_at" @click="addTask">{{ t('crm_my_dashboard.add') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>