<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Report = {
  id: number; uuid: string; course: { title: string } | null
  moderation_type: string
  moderator: { first_name: string; last_name: string } | null; moderator_id: number
  assessor:  { first_name: string; last_name: string } | null; assessor_id: number | null
  moderation_date: string; total_assessed: number; sampled_count: number
  sample_pct: number; agreed_count: number; disagreed_count: number; agreement_rate: number
  findings: string | null; recommendations: string | null
  action_required: boolean; corrective_actions: string | null
  status: string; seta_submission_reference: string | null; seta_submitted_at: string | null
  seta_acknowledged_at: string | null; seta_acknowledgement_reference: string | null
}

const props = defineProps<{
  reports:    Report[]
  courses:    { id: number; title: string }[]
  moderators: { id: number; first_name: string; last_name: string }[]
  assessors:  { id: number; first_name: string; last_name: string }[]
}>()

const confirmDialog = ref()
const dialog  = ref(false)
const editing = ref<Report | null>(null)
const ackDialog = ref(false)
const ackTarget = ref<Report | null>(null)
const ackForm   = useForm({ seta_acknowledgement_reference: '' })

const form = useForm({
  course_id: null as number | null,
  moderation_type: 'internal',
  moderator_id: null as number | null,
  assessor_id: null as number | null,
  moderation_date: new Date().toISOString().slice(0,10),
  total_assessed: 0, sampled_count: 0, agreed_count: 0,
  findings: '', recommendations: '', action_required: false, corrective_actions: '',
  status: 'draft', seta_submission_reference: '', seta_submitted_at: '',
})

const typeItems = computed(() => [
  { title: t('lms_moderation.types.internal'), value: 'internal' },
  { title: t('lms_moderation.types.external'), value: 'external' },
])

const statusItems = computed(() => [
  { title: t('lms_moderation.statuses.draft'), value: 'draft' },
  { title: t('lms_moderation.statuses.submitted'), value: 'submitted' },
  { title: t('lms_moderation.statuses.approved'), value: 'approved' },
  { title: t('lms_moderation.statuses.rejected'), value: 'rejected' },
])

function openNew() { editing.value = null; form.reset(); form.moderation_date = new Date().toISOString().slice(0,10); dialog.value = true }
function openEdit(r: Report) {
  editing.value = r
  Object.assign(form, {
    course_id: r.course ? props.courses.find(c => c.title === r.course!.title)?.id : null,
    moderation_type: r.moderation_type, moderator_id: r.moderator_id, assessor_id: r.assessor_id,
    moderation_date: r.moderation_date, total_assessed: r.total_assessed,
    sampled_count: r.sampled_count, agreed_count: r.agreed_count,
    findings: r.findings ?? '', recommendations: r.recommendations ?? '',
    action_required: r.action_required, corrective_actions: r.corrective_actions ?? '',
    status: r.status, seta_submission_reference: r.seta_submission_reference ?? '',
    seta_submitted_at: r.seta_submitted_at ?? '',
  })
  dialog.value = true
}

function save() {
  if (editing.value) { form.patch(route('lms.admin.qcto.moderation.update', { report: editing.value.id }), { onSuccess: () => { dialog.value = false } }) }
  else { form.post(route('lms.admin.qcto.moderation.store'), { onSuccess: () => { dialog.value = false } }) }
}

function destroy(r: Report) {
  confirmDialog.value.open(
    { title: t('lms_moderation.delete_title'), message: t('lms_moderation.delete_message'), confirmText: t('lms_moderation.delete_confirm'), confirmColor: 'error', icon: 'tabler-trash' },
    () => { useForm({}).delete(route('lms.admin.qcto.moderation.destroy', { report: r.id })) },
  )
}

function openAcknowledge(r: Report) { ackTarget.value = r; ackForm.seta_acknowledgement_reference = r.seta_acknowledgement_reference ?? ''; ackDialog.value = true }
function submitAcknowledge() { ackForm.post(route('lms.admin.qcto.moderation.acknowledge', { report: ackTarget.value!.id }), { onSuccess: () => { ackDialog.value = false } }) }

const statusColor: Record<string, string> = { draft: 'default', submitted: 'info', approved: 'success', rejected: 'error' }
</script>

<template>
  <div class="pa-5">
    <ConfirmDialog ref="confirmDialog" />
    <div class="d-flex align-center gap-3 mb-5">
      <div class="flex-1-1">
        <h4 class="text-h4 mb-0">{{ t('lms_moderation.title') }}</h4>
        <p class="text-caption text-medium-emphasis mb-0">{{ t('lms_moderation.subtitle') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openNew">{{ t('lms_moderation.new_report') }}</VBtn>
    </div>

    <VCard rounded="lg" elevation="0" border>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('lms_moderation.table.course') }}</th>
            <th>{{ t('lms_moderation.table.type') }}</th>
            <th>{{ t('lms_moderation.table.moderator') }}</th>
            <th>{{ t('lms_moderation.table.date') }}</th>
            <th>{{ t('lms_moderation.table.sample') }}</th>
            <th>{{ t('lms_moderation.table.agreement') }}</th>
            <th>{{ t('lms_moderation.table.status') }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in reports" :key="r.id">
            <td>{{ r.course?.title ?? '—' }}</td>
            <td><VChip :color="r.moderation_type === 'internal' ? 'info' : 'warning'" size="x-small" variant="tonal" class="text-capitalize">{{ r.moderation_type }}</VChip></td>
            <td><span v-if="r.moderator">{{ r.moderator.first_name }} {{ r.moderator.last_name }}</span><span v-else class="text-medium-emphasis">—</span></td>
            <td>{{ r.moderation_date }}</td>
            <td class="text-caption">{{ r.sampled_count }}/{{ r.total_assessed }} ({{ r.sample_pct }}%)</td>
            <td>
              <VChip :color="r.agreement_rate >= 80 ? 'success' : 'error'" size="x-small" variant="tonal">{{ r.agreement_rate }}%</VChip>
              <VChip v-if="r.action_required" color="warning" size="x-small" variant="tonal" class="ms-1">{{ t('lms_moderation.table.action_required') }}</VChip>
            </td>
            <td><VChip :color="statusColor[r.status]" size="x-small" variant="tonal" class="text-capitalize">{{ r.status }}</VChip></td>
            <td>
              <div class="d-flex gap-1 flex-wrap">
                <VBtn v-if="r.seta_submitted_at && !r.seta_acknowledged_at" size="x-small" variant="tonal" color="success" prepend-icon="tabler-circle-check" @click="openAcknowledge(r)">{{ t('lms_moderation.table.acknowledge') }}</VBtn>
                <VChip v-else-if="r.seta_acknowledged_at" color="success" size="x-small" variant="tonal">{{ t('lms_moderation.table.acknowledged') }}</VChip>
                <VBtn icon="tabler-edit" size="x-small" variant="text" @click="openEdit(r)" />
                <VBtn icon="tabler-trash" size="x-small" variant="text" color="error" @click="destroy(r)" />
              </div>
            </td>
          </tr>
          <tr v-if="!reports.length">
            <td colspan="8" class="text-center text-medium-emphasis pa-6">{{ t('lms_moderation.table.no_reports') }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="ackDialog" max-width="460" persistent>
      <VCard rounded="lg">
        <VCardTitle class="pa-5 pb-0">{{ t('lms_moderation.acknowledge_dialog.title') }}</VCardTitle>
        <VCardText class="pa-5">
          <VAlert type="info" variant="tonal" density="compact" class="mb-4">{{ t('lms_moderation.acknowledge_dialog.alert') }}</VAlert>
          <VTextField v-model="ackForm.seta_acknowledgement_reference" :label="t('lms_moderation.acknowledge_dialog.reference_label')" :hint="t('lms_moderation.acknowledge_dialog.reference_hint')" persistent-hint />
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2 justify-end"><VBtn variant="outlined" @click="ackDialog = false">{{ t('lms_moderation.acknowledge_dialog.cancel') }}</VBtn><VBtn color="success" :loading="ackForm.processing" @click="submitAcknowledge">{{ t('lms_moderation.acknowledge_dialog.record') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="dialog" max-width="680">
      <VCard rounded="lg">
        <VCardTitle class="pa-5 pb-0">{{ editing ? t('lms_moderation.dialog.title_edit') : t('lms_moderation.dialog.title_new') }}</VCardTitle>
        <VCardText class="pa-5">
          <VRow dense>
            <VCol cols="6"><VSelect v-model="form.course_id" :label="t('lms_moderation.dialog.course_label')" :items="courses.map(c => ({title: c.title, value: c.id}))" clearable /></VCol>
            <VCol cols="6"><VSelect v-model="form.moderation_type" :label="t('lms_moderation.dialog.type_label')" :items="typeItems" /></VCol>
            <VCol cols="6"><VSelect v-model="form.moderator_id" :label="t('lms_moderation.dialog.moderator_label')" :items="moderators.map(m => ({title: m.first_name + ' ' + m.last_name, value: m.id}))" :error-messages="form.errors.moderator_id" /></VCol>
            <VCol cols="6"><VSelect v-model="form.assessor_id" :label="t('lms_moderation.dialog.assessor_label')" :items="assessors.map(a => ({title: a.first_name + ' ' + a.last_name, value: a.id}))" clearable /></VCol>
            <VCol cols="4"><VTextField v-model="form.moderation_date" :label="t('lms_moderation.dialog.date_label')" type="date" /></VCol>
            <VCol cols="4"><VTextField v-model="form.total_assessed" :label="t('lms_moderation.dialog.total_assessed_label')" type="number" min="0" /></VCol>
            <VCol cols="4"><VTextField v-model="form.sampled_count" :label="t('lms_moderation.dialog.sampled_count_label')" type="number" min="0" /></VCol>
            <VCol cols="4"><VTextField v-model="form.agreed_count" :label="t('lms_moderation.dialog.agreed_count_label')" type="number" min="0" /></VCol>
            <VCol cols="8" class="d-flex align-center"><VCheckbox v-model="form.action_required" :label="t('lms_moderation.dialog.action_required_label')" /></VCol>
            <VCol cols="12"><VTextarea v-model="form.findings" :label="t('lms_moderation.dialog.findings_label')" rows="2" auto-grow /></VCol>
            <VCol cols="12"><VTextarea v-model="form.recommendations" :label="t('lms_moderation.dialog.recommendations_label')" rows="2" auto-grow /></VCol>
            <VCol v-if="form.action_required" cols="12"><VTextarea v-model="form.corrective_actions" :label="t('lms_moderation.dialog.corrective_actions_label')" rows="2" auto-grow /></VCol>
            <VCol cols="6"><VSelect v-model="form.status" :label="t('lms_moderation.dialog.status_label')" :items="statusItems" /></VCol>
            <VCol cols="6"><VTextField v-model="form.seta_submission_reference" :label="t('lms_moderation.dialog.seta_reference_label')" /></VCol>
            <VCol cols="6"><VTextField v-model="form.seta_submitted_at" :label="t('lms_moderation.dialog.seta_submission_date_label')" type="date" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2 justify-end"><VBtn variant="outlined" @click="dialog = false">{{ t('lms_moderation.dialog.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" @click="save">{{ t('lms_moderation.dialog.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
