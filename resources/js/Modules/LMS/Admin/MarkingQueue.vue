<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  pending: any[]
  recentlyFinalized: any[]
  markers: { id: number; name: string }[]
}>()

const tab         = ref('pending')
const snackbar    = ref(false)
const snackbarText = ref('')

const headers =computed(()=> [
  { title: t('lms_marking.table.learner'),       key: 'learner_name' },
  { title: t('lms_marking.table.questionnaire'),  key: 'questionnaire_title' },
  { title: t('lms_marking.table.type'),          key: 'questionnaire_type' },
  { title: t('lms_marking.table.submitted'),     key: 'completed_at' },
  { title: t('lms_marking.table.progress'),      key: 'progress', sortable: false },
  { title: '', key: 'actions', sortable: false, align: 'end' },
])

const doneHeaders = computed(()=> [
  { title: t('lms_marking.table.learner'),       key: 'learner_name' },
  { title: t('lms_marking.table.questionnaire'),  key: 'questionnaire_title' },
  { title: t('lms_marking.table.type'),          key: 'questionnaire_type' },
  { title: t('lms_marking.table.status'),        key: 'status' },
  { title: t('lms_marking.table.score'),         key: 'score' },
  { title: t('lms_marking.table.submitted'),     key: 'completed_at' },
  { title: '', key: 'actions', sortable: false, align: 'end' },
])

const typeLabelMap: Record<string, string> = {
  questionnaire: t('lms_marking.types.questionnaire'),
  pre_assessment: t('lms_marking.types.pre_assessment'),
  post_assessment: t('lms_marking.types.post_assessment'),
  knowledge_check: t('lms_marking.types.knowledge_check'),
  final_test: t('lms_marking.types.final_test'),
  practical_assessment: t('lms_marking.types.practical_assessment'),
  self_assessment: t('lms_marking.types.self_assessment'),
  manager_observation: t('lms_marking.types.manager_observation'),
  feedback: t('lms_marking.types.feedback'),
}
const typeColorMap: Record<string, string> = {
  questionnaire: 'blue-grey', pre_assessment: 'orange', post_assessment: 'green',
  knowledge_check: 'cyan', final_test: 'deep-purple', practical_assessment: 'teal',
  self_assessment: 'indigo', manager_observation: 'brown', feedback: 'pink',
}
const typeLabel = (t: string) => typeLabelMap[t] ?? t
const typeColor = (t: string) => typeColorMap[t] ?? 'grey'

function formatDate(dt: string | null) {
  if (!dt) return '—'
  return new Date(dt).toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' })
}

function quickAssign(attemptId: number, markerId: number) {
  router.post(route('lms.admin.marking.assign', attemptId), { assigned_to: markerId }, { onSuccess: () => { snackbarText.value = t('lms_marking.toast.assigned_successfully'); snackbar.value = true }, preserveScroll: true })
}
</script>

<template>
  <VCard>
    <VCardTitle class="d-flex align-center pa-4">
      <span class="text-h6">{{ t('lms_marking.queue.title') }}</span>
      <VSpacer />
      <VChip v-if="pending.length > 0" color="warning" label>{{ t('lms_marking.queue.pending_count', { count: pending.length }) }}</VChip>
    </VCardTitle>

    <VTabs v-model="tab" color="primary">
      <VTab value="pending">{{ t('lms_marking.queue.tabs.pending') }}<VBadge v-if="pending.length > 0" :content="pending.length" color="warning" inline class="ml-2" /></VTab>
      <VTab value="done">{{ t('lms_marking.queue.tabs.done') }} ({{ recentlyFinalized.length }})</VTab>
    </VTabs>

    <VWindow v-model="tab">
      <VWindowItem value="pending">
        <div v-if="pending.length === 0" class="text-center text-medium-emphasis py-12"><VIcon size="48" class="mb-3" color="success">mdi-check-circle</VIcon><div>{{ t('lms_marking.queue.no_pending') }}</div></div>
        <VDataTable v-else :headers="headers" :items="pending" :items-per-page="20">
          <template #item.learner_name="{ item }"><span class="font-weight-medium">{{ item.learner_name }}</span></template>
          <template #item.questionnaire_type="{ item }"><VChip size="small" :color="typeColor(item.questionnaire_type)" label>{{ typeLabel(item.questionnaire_type) }}</VChip></template>
          <template #item.progress="{ item }"><div class="d-flex align-center gap-2" style="min-width: 120px;"><VProgressLinear :model-value="item.total_items > 0 ? (item.reviewed_count / item.total_items) * 100 : 0" color="success" rounded height="6" class="flex-grow-1" /><span class="text-caption text-no-wrap">{{ item.reviewed_count }}/{{ item.total_items }}</span></div></template>
          <template #item.completed_at="{ item }"><span class="text-caption">{{ formatDate(item.completed_at) }}</span></template>
          <template #item.actions="{ item }">
            <div class="d-flex gap-1 align-center">
              <VMenu>
                <template #activator="{ props: menuProps }"><VBtn v-bind="menuProps" size="small" variant="tonal" prepend-icon="mdi-account-arrow-right">{{ t('lms_marking.queue.assign') }}</VBtn></template>
                <VList density="compact" min-width="200"><VListItem v-for="marker in markers" :key="marker.id" :title="marker.name" @click="quickAssign(item.id, marker.id)" /></VList>
              </VMenu>
              <VBtn size="small" color="primary" variant="tonal" prepend-icon="mdi-pencil-ruler" :href="route('lms.admin.marking.show', item.id)">{{ t('lms_marking.queue.mark') }}</VBtn>
            </div>
          </template>
        </VDataTable>
      </VWindowItem>

      <VWindowItem value="done">
        <div v-if="recentlyFinalized.length === 0" class="text-center text-medium-emphasis py-12">{{ t('lms_marking.queue.no_finalised') }}</div>
        <VDataTable v-else :headers="doneHeaders" :items="recentlyFinalized" :items-per-page="20">
          <template #item.questionnaire_type="{ item }"><VChip size="small" :color="typeColor(item.questionnaire_type)" label>{{ typeLabel(item.questionnaire_type) }}</VChip></template>
          <template #item.status="{ item }"><VChip size="small" :color="item.status === 'passed' ? 'success' : item.status === 'failed' ? 'error' : 'primary'">{{ item.status }}</VChip></template>
          <template #item.score="{ item }"><span v-if="item.score !== null" class="font-weight-medium">{{ item.score }}%</span><span v-else class="text-medium-emphasis">—</span></template>
          <template #item.completed_at="{ item }"><span class="text-caption">{{ formatDate(item.completed_at) }}</span></template>
          <template #item.actions="{ item }"><VBtn size="small" variant="text" icon :href="route('lms.admin.marking.show', item.id)"><VIcon>mdi-eye</VIcon><VTooltip activator="parent">{{ t('lms_marking.queue.view') }}</VTooltip></VBtn></template>
        </VDataTable>
      </VWindowItem>
    </VWindow>

    <VSnackbar v-model="snackbar" :timeout="3000" color="success" location="bottom right">{{ snackbarText }}</VSnackbar>
  </VCard>
</template>
