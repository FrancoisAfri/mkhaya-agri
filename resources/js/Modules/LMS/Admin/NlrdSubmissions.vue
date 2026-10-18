<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Submission = {
  id: number; uuid: string; submission_type: string
  period_start: string; period_end: string
  seta: { acronym: string } | null; seta_id: number | null
  sdf: any; sdf_id: number | null
  submission_date: string | null; reference_number: string | null
  status: string; records_count: number; error_log: string | null
  submitted_by: any; acknowledged_at: string | null
}

const props = defineProps<{
  submissions: Submission[]
  setas: { id: number; name: string; acronym: string }[]
  sdfs:  any[]
}>()

const confirmDialog = ref()
const dialog  = ref(false)
const errorDialog = ref(false)
const activeErrors = ref('')

const form = useForm({
  submission_type: 'learner_enrolment',
  period_start: '', period_end: '',
  seta_id: null as number | null, sdf_id: null as number | null,
})

const typeItems = computed(() => [
  { title: t('lms_nlrd.types.learner_enrolment'), value: 'learner_enrolment' },
  { title: t('lms_nlrd.types.learner_achievement'), value: 'learner_achievement' },
  { title: t('lms_nlrd.types.wspa'), value: 'wspa' },
  { title: t('lms_nlrd.types.atr'), value: 'atr' },
])

const typeLabel: Record<string, string> = {
  learner_enrolment: t('lms_nlrd.types.learner_enrolment'),
  learner_achievement: t('lms_nlrd.types.learner_achievement'),
  wspa: t('lms_nlrd.types.wspa'),
  atr: t('lms_nlrd.types.atr'),
}

function openNew() { form.reset(); dialog.value = true }
function save() { form.post(route('lms.admin.qcto.nlrd.store'), { onSuccess: () => { dialog.value = false } }) }
function validate(s: Submission) { useForm({}).post(route('lms.admin.qcto.nlrd.validate', { submission: s.id })) }
function showErrors(s: Submission) { activeErrors.value = s.error_log ?? 'No errors'; errorDialog.value = true }

function resubmit(s: Submission) {
  confirmDialog.value.open(
    { title: t('lms_nlrd.resubmit_title'), message: t('lms_nlrd.resubmit_message'), confirmText: t('lms_nlrd.resubmit_confirm'), confirmColor: 'warning', icon: 'tabler-alert-triangle' },
    () => { useForm({}).post(route('lms.admin.qcto.nlrd.resubmit', { submission: s.id })) },
  )
}

function destroy(s: Submission) {
  confirmDialog.value.open(
    { title: t('lms_nlrd.delete_title'), message: t('lms_nlrd.delete_message'), confirmText: t('lms_nlrd.delete_confirm'), confirmColor: 'error', icon: 'tabler-trash' },
    () => { useForm({}).delete(route('lms.admin.qcto.nlrd.destroy', { submission: s.id })) },
  )
}

const statusColor: Record<string, string> = { draft: 'default', validated: 'info', submitted: 'primary', accepted: 'success', rejected: 'error', correction_required: 'warning' }
</script>

<template>
  <div class="pa-5">
    <ConfirmDialog ref="confirmDialog" />
    <div class="d-flex align-center gap-3 mb-5">
      <div class="flex-1-1">
        <h4 class="text-h4 mb-0">{{ t('lms_nlrd.title') }}</h4>
        <p class="text-caption text-medium-emphasis mb-0">{{ t('lms_nlrd.subtitle') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openNew">{{ t('lms_nlrd.new_submission') }}</VBtn>
    </div>

    <VCard rounded="lg" elevation="0" border>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('lms_nlrd.table.type') }}</th>
            <th>{{ t('lms_nlrd.table.period') }}</th>
            <th>{{ t('lms_nlrd.table.seta') }}</th>
            <th>{{ t('lms_nlrd.table.records') }}</th>
            <th>{{ t('lms_nlrd.table.status') }}</th>
            <th>{{ t('lms_nlrd.table.reference') }}</th>
            <th>{{ t('lms_nlrd.table.submitted') }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="s in submissions" :key="s.id">
            <td><VChip color="primary" size="x-small" variant="tonal">{{ typeLabel[s.submission_type] }}</VChip></td>
            <td class="text-caption">{{ s.period_start }} → {{ s.period_end }}</td>
            <td>{{ s.seta?.acronym ?? '—' }}</td>
            <td>{{ s.records_count }}</td>
            <td><VChip :color="statusColor[s.status]" size="x-small" variant="tonal" class="text-capitalize">{{ s.status.replace('_',' ') }}</VChip><VBtn v-if="s.error_log" icon="tabler-alert-circle" size="x-small" variant="text" color="error" @click="showErrors(s)" /></td>
            <td>{{ s.reference_number ?? '—' }}</td>
            <td>{{ s.submission_date ?? '—' }}</td>
            <td>
              <div class="d-flex gap-1 flex-wrap">
                <VBtn v-if="['rejected','correction_required'].includes(s.status)" size="x-small" variant="tonal" color="warning" prepend-icon="tabler-refresh" @click="resubmit(s)">{{ t('lms_nlrd.table.resubmit') }}</VBtn>
                <VBtn v-else size="x-small" variant="tonal" @click="validate(s)">{{ t('lms_nlrd.table.validate') }}</VBtn>
                <VBtn size="x-small" variant="tonal" color="success" :href="route('lms.admin.qcto.nlrd.export', { submission: s.id })" prepend-icon="tabler-download">{{ t('lms_nlrd.table.csv') }}</VBtn>
                <VBtn icon="tabler-trash" size="x-small" variant="text" color="error" @click="destroy(s)" />
              </div>
            </td>
          </tr>
          <tr v-if="!submissions.length">
            <td colspan="8" class="text-center text-medium-emphasis pa-6">{{ t('lms_nlrd.table.no_submissions') }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="dialog" max-width="500">
      <VCard rounded="lg">
        <VCardTitle class="pa-5 pb-0">{{ t('lms_nlrd.dialog.title_new') }}</VCardTitle>
        <VCardText class="pa-5">
          <VAlert type="info" variant="tonal" density="compact" class="mb-4">{{ t('lms_nlrd.dialog.alert') }}</VAlert>
          <VRow dense>
            <VCol cols="12"><VSelect v-model="form.submission_type" :label="t('lms_nlrd.dialog.type_label')" :items="typeItems" /></VCol>
            <VCol cols="6"><VTextField v-model="form.period_start" :label="t('lms_nlrd.dialog.period_start_label')" type="date" /></VCol>
            <VCol cols="6"><VTextField v-model="form.period_end" :label="t('lms_nlrd.dialog.period_end_label')" type="date" /></VCol>
            <VCol cols="6"><VSelect v-model="form.seta_id" :label="t('lms_nlrd.dialog.seta_label')" :items="setas.map(s => ({title: s.acronym, value: s.id}))" clearable /></VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2 justify-end"><VBtn variant="outlined" @click="dialog = false">{{ t('lms_nlrd.dialog.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" @click="save">{{ t('lms_nlrd.dialog.prepare') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="errorDialog" max-width="600">
      <VCard rounded="lg">
        <VCardTitle class="pa-5 pb-0">{{ t('lms_nlrd.error_dialog.title') }}</VCardTitle>
        <VCardText class="pa-5"><pre class="text-caption" style="white-space:pre-wrap">{{ activeErrors }}</pre></VCardText>
        <VCardActions class="pa-5 pt-0 justify-end"><VBtn variant="outlined" @click="errorDialog = false">{{ t('lms_nlrd.error_dialog.close') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
