<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Enrolment = {
  id: number; uuid: string
  hr_person: { id: number; first_name: string; surname: string; employee_number: string | null }
  seta_learner_id: string | null; seta_registration_number: string | null
  start_date: string; end_date: string | null; status: string
  we_hours_logged: number; we_logbook_complete: boolean
  k_completed_at: string | null; p_completed_at: string | null; we_completed_at: string | null
  final_score: number | null; passed: boolean | null
  certificate_number: string | null; certificate_issued_at: string | null
  stipend_amount: number | null; notes: string | null
}

const props = defineProps<{
  learnership: { id: number; title: string; we_hours_required: number; seta: { acronym: string } | null }
  enrolments: Enrolment[]
  employees: { id: number; first_name: string; surname: string; employee_number: string | null }[]
}>()

const dialog        = ref(false)
const editing       = ref<Enrolment | null>(null)
const logbookDialog = ref(false)
const activeEnrolment = ref<Enrolment | null>(null)
const logEntries    = ref<any[]>([])

const enrolForm = useForm({
  hr_person_id: null as number | null,
  seta_learner_id: '', seta_registration_number: '',
  start_date: '', end_date: '',
  stipend_amount: null as number | null, notes: '',
})

const editForm = useForm({
  seta_learner_id: '', seta_registration_number: '',
  start_date: '', end_date: '',
  status: 'registered',
  final_assessment_date: '', final_score: null as number | null,
  passed: null as boolean | null,
  certificate_number: '', certificate_issued_at: '',
  stipend_amount: null as number | null, notes: '',
})

const logForm = useForm({
  log_date: '', activity_description: '', hours_logged: 1,
  workplace_coach: '', learner_reflection: '',
  evidence_file: null as File | null,
})

const evidenceFile = ref<File | null>(null)

const statusItems = computed(() =>  [
  { title: t('lms_learnership_enrolments.statuses.registered'), value: 'registered' },
  { title: t('lms_learnership_enrolments.statuses.active'), value: 'active' },
  { title: t('lms_learnership_enrolments.statuses.completed'), value: 'completed' },
  { title: t('lms_learnership_enrolments.statuses.withdrawn'), value: 'withdrawn' },
  { title: t('lms_learnership_enrolments.statuses.transferred'), value: 'transferred' },
  { title: t('lms_learnership_enrolments.statuses.lapsed'), value: 'lapsed' },
])

const passedItems = computed(() => [
  { title: t('lms_learnership_enrolments.dialog.passed_yes'), value: true },
  { title: t('lms_learnership_enrolments.dialog.passed_no'), value: false },
  { title: t('lms_learnership_enrolments.dialog.passed_pending'), value: null },
])

function openNew() { editing.value = null; enrolForm.reset(); dialog.value = true }
function openEdit(e: Enrolment) {
  editing.value = e
  Object.assign(editForm, {
    seta_learner_id: e.seta_learner_id ?? '', seta_registration_number: e.seta_registration_number ?? '',
    start_date: e.start_date, end_date: e.end_date ?? '',
    status: e.status, final_score: e.final_score,
    passed: e.passed, certificate_number: e.certificate_number ?? '',
    certificate_issued_at: e.certificate_issued_at ?? '',
    stipend_amount: e.stipend_amount, notes: e.notes ?? '',
  })
  dialog.value = true
}

function saveNew() { enrolForm.post(route('lms.admin.qcto.learnerships.enrolments.store', { learnership: props.learnership.id }), { onSuccess: () => { dialog.value = false } }) }
function saveEdit() { editForm.patch(route('lms.admin.qcto.enrolments.update', { enrolment: editing.value!.id }), { onSuccess: () => { dialog.value = false } }) }

async function openLogbook(e: Enrolment) {
  activeEnrolment.value = e
  const res = await fetch(route('lms.admin.qcto.enrolments.logbook', { enrolment: e.id }))
  logEntries.value = await res.json()
  logForm.reset()
  logForm.log_date = new Date().toISOString().slice(0, 10)
  evidenceFile.value = null
  logbookDialog.value = true
}

function addLog() {
  logForm.evidence_file = evidenceFile.value
  logForm.post(route('lms.admin.qcto.enrolments.logbook.store', { enrolment: activeEnrolment.value!.id }), {
    onSuccess: async () => {
      logForm.reset(); logForm.log_date = new Date().toISOString().slice(0, 10); evidenceFile.value = null
      const res = await fetch(route('lms.admin.qcto.enrolments.logbook', { enrolment: activeEnrolment.value!.id }))
      logEntries.value = await res.json()
    },
  })
}

const statusColor: Record<string, string> = {
  registered: 'info', active: 'primary', completed: 'success',
  withdrawn: 'error', transferred: 'warning', lapsed: 'default',
}
</script>

<template>
  <div class="pa-5">
    <div class="d-flex align-center gap-3 mb-5">
      <div class="flex-1-1">
        <h4 class="text-h4 mb-0">{{ learnership.title }}</h4>
        <p class="text-caption text-medium-emphasis mb-0">
          {{ learnership.seta?.acronym }} · {{ t('lms_learnership_enrolments.title_suffix') }}
        </p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openNew">{{ t('lms_learnership_enrolments.enrol_learner') }}</VBtn>
    </div>

    <VCard rounded="lg" elevation="0" border>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('lms_learnership_enrolments.table.learner') }}</th>
            <th>{{ t('lms_learnership_enrolments.table.seta_learner_id') }}</th>
            <th>{{ t('lms_learnership_enrolments.table.start_date') }}</th>
            <th>{{ t('lms_learnership_enrolments.table.status') }}</th>
            <th>{{ t('lms_learnership_enrolments.table.k') }}</th>
            <th>{{ t('lms_learnership_enrolments.table.p') }}</th>
            <th>{{ t('lms_learnership_enrolments.table.we_hours') }}</th>
            <th>{{ t('lms_learnership_enrolments.table.score') }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="e in enrolments" :key="e.id">
            <td>
              <p class="text-body-2 font-weight-medium mb-0">{{ e.hr_person.first_name }} {{ e.hr_person.surname }}</p>
              <p class="text-caption text-medium-emphasis mb-0">{{ e.hr_person.employee_number ?? '' }}</p>
            </td>
            <td>{{ e.seta_learner_id ?? '—' }}</td>
            <td>{{ e.start_date }}</td>
            <td><VChip :color="statusColor[e.status]" size="x-small" variant="tonal" class="text-capitalize">{{ e.status }}</VChip></td>
            <td><VIcon :icon="e.k_completed_at ? 'tabler-circle-check' : 'tabler-circle'" :color="e.k_completed_at ? 'success' : 'default'" size="18" /></td>
            <td><VIcon :icon="e.p_completed_at ? 'tabler-circle-check' : 'tabler-circle'" :color="e.p_completed_at ? 'success' : 'default'" size="18" /></td>
            <td><span :class="e.we_logbook_complete ? 'text-success' : ''">{{ e.we_hours_logged }} / {{ learnership.we_hours_required }}h</span></td>
            <td>
              <span v-if="e.final_score !== null">{{ e.final_score }}%<VIcon :icon="e.passed ? 'tabler-circle-check' : 'tabler-x'" :color="e.passed ? 'success' : 'error'" size="14" /></span>
              <span v-else class="text-medium-emphasis">—</span>
            </td>
            <td>
              <div class="d-flex gap-1 flex-wrap">
                <VBtn size="x-small" variant="tonal" @click="openLogbook(e)">{{ t('lms_learnership_enrolments.table.wbl_log') }}</VBtn>
                <VBtn v-if="e.status === 'completed'" size="x-small" variant="tonal" color="success" prepend-icon="tabler-user-check" @click="useForm({}).post(route('lms.admin.qcto.enrolments.absorb', { enrolment: e.id }))">{{ t('lms_learnership_enrolments.table.absorb') }}</VBtn>
                <VBtn icon="tabler-edit" size="x-small" variant="text" @click="openEdit(e)" />
              </div>
            </td>
          </tr>
          <tr v-if="!enrolments.length">
            <td colspan="9" class="text-center text-medium-emphasis pa-6">{{ t('lms_learnership_enrolments.table.no_learners') }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <!-- New/Edit enrolment dialog -->
    <VDialog v-model="dialog" max-width="560">
      <VCard rounded="lg">
        <VCardTitle class="pa-5 pb-0">{{ editing ? t('lms_learnership_enrolments.dialog.title_edit') : t('lms_learnership_enrolments.dialog.title_new') }}</VCardTitle>
        <VCardText class="pa-5">
          <VRow dense>
            <template v-if="!editing">
              <VCol cols="12"><VAutocomplete v-model="enrolForm.hr_person_id" :label="t('lms_learnership_enrolments.dialog.employee_label')" :items="employees.map(e => ({title: e.first_name + ' ' + e.surname, value: e.id}))" :error-messages="enrolForm.errors.hr_person_id" /></VCol>
              <VCol cols="6"><VTextField v-model="enrolForm.start_date" :label="t('lms_learnership_enrolments.dialog.start_date_label')" type="date" /></VCol>
              <VCol cols="6"><VTextField v-model="enrolForm.end_date" :label="t('lms_learnership_enrolments.dialog.end_date_label')" type="date" /></VCol>
              <VCol cols="6"><VTextField v-model="enrolForm.seta_learner_id" :label="t('lms_learnership_enrolments.dialog.seta_learner_id_label')" /></VCol>
              <VCol cols="6"><VTextField v-model="enrolForm.seta_registration_number" :label="t('lms_learnership_enrolments.dialog.seta_reg_number_label')" /></VCol>
              <VCol cols="6"><VTextField v-model="enrolForm.stipend_amount" :label="t('lms_learnership_enrolments.dialog.stipend_label')" type="number" /></VCol>
              <VCol cols="12"><VTextarea v-model="enrolForm.notes" :label="t('lms_learnership_enrolments.dialog.notes_label')" rows="2" auto-grow /></VCol>
            </template>
            <template v-else>
              <VCol cols="6"><VTextField v-model="editForm.seta_learner_id" :label="t('lms_learnership_enrolments.dialog.seta_learner_id_label')" /></VCol>
              <VCol cols="6"><VTextField v-model="editForm.seta_registration_number" :label="t('lms_learnership_enrolments.dialog.seta_reg_number_label')" /></VCol>
              <VCol cols="6"><VTextField v-model="editForm.start_date" :label="t('lms_learnership_enrolments.dialog.start_date_label')" type="date" /></VCol>
              <VCol cols="6"><VTextField v-model="editForm.end_date" :label="t('lms_learnership_enrolments.dialog.end_date_label')" type="date" /></VCol>
              <VCol cols="6"><VSelect v-model="editForm.status" :label="t('lms_learnership_enrolments.dialog.status_label')" :items="statusItems" /></VCol>
              <VCol cols="6"><VTextField v-model="editForm.final_assessment_date" :label="t('lms_learnership_enrolments.dialog.final_assessment_date_label')" type="date" /></VCol>
              <VCol cols="4"><VTextField v-model="editForm.final_score" :label="t('lms_learnership_enrolments.dialog.final_score_label')" type="number" min="0" max="100" /></VCol>
              <VCol cols="4"><VSelect v-model="editForm.passed" :label="t('lms_learnership_enrolments.dialog.passed_label')" :items="passedItems" /></VCol>
              <VCol cols="4"><VTextField v-model="editForm.certificate_number" :label="t('lms_learnership_enrolments.dialog.certificate_number_label')" /></VCol>
              <VCol cols="6"><VTextField v-model="editForm.certificate_issued_at" :label="t('lms_learnership_enrolments.dialog.certificate_date_label')" type="date" /></VCol>
              <VCol cols="6"><VTextField v-model="editForm.stipend_amount" :label="t('lms_learnership_enrolments.dialog.stipend_label')" type="number" /></VCol>
              <VCol cols="12"><VTextarea v-model="editForm.notes" :label="t('lms_learnership_enrolments.dialog.notes_label')" rows="2" auto-grow /></VCol>
            </template>
          </VRow>
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2 justify-end">
          <VBtn variant="outlined" @click="dialog = false">{{ t('lms_learnership_enrolments.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="editing ? editForm.processing : enrolForm.processing" @click="editing ? saveEdit() : saveNew()">{{ t('lms_learnership_enrolments.dialog.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- WBL Logbook dialog -->
    <VDialog v-model="logbookDialog" max-width="700">
      <VCard rounded="lg">
        <VCardTitle class="pa-5 pb-0">
          {{ t('lms_learnership_enrolments.logbook.title', { name: activeEnrolment?.hr_person.first_name + ' ' + activeEnrolment?.hr_person.surname }) }}
        </VCardTitle>
        <VCardText class="pa-5">
          <p class="text-caption text-medium-emphasis mb-3">
            {{ t('lms_learnership_enrolments.logbook.hours_logged', { logged: activeEnrolment?.we_hours_logged, required: learnership.we_hours_required }) }}
          </p>

          <div v-if="logEntries.length" class="mb-4">
            <div v-for="entry in logEntries" :key="entry.id" class="pa-3 rounded mb-2" style="border:1px solid rgba(var(--v-border-color),var(--v-border-opacity))">
              <div class="d-flex align-center gap-2 mb-1">
                <span class="text-body-2 font-weight-semibold">{{ entry.log_date }}</span>
                <VChip size="x-small" variant="outlined">{{ entry.hours_logged }}h</VChip>
                <VChip v-if="entry.coach_signed_off" color="success" size="x-small" variant="tonal"><VIcon icon="tabler-circle-check" size="12" class="me-1" />{{ t('lms_learnership_enrolments.logbook.signed_off') }}</VChip>
              </div>
              <p class="text-body-2 mb-0">{{ entry.activity_description }}</p>
              <p v-if="entry.workplace_coach" class="text-caption text-medium-emphasis mb-0">{{ t('lms_learnership_enrolments.logbook.coach_label') }} {{ entry.workplace_coach }}</p>
              <div v-if="entry.evidence_file_name" class="mt-1">
                <VBtn size="x-small" variant="tonal" color="primary" prepend-icon="tabler-paperclip" :href="route('lms.admin.qcto.logbook.evidence.download', { entry: entry.id })" target="_blank">{{ entry.evidence_file_name }}</VBtn>
              </div>
            </div>
          </div>
          <VAlert v-else type="info" variant="tonal" density="compact" class="mb-4">{{ t('lms_learnership_enrolments.logbook.no_entries') }}</VAlert>

          <VDivider class="mb-4" />
          <p class="text-body-2 font-weight-semibold mb-3">{{ t('lms_learnership_enrolments.logbook.add_entry') }}</p>
          <VRow dense>
            <VCol cols="4"><VTextField v-model="logForm.log_date" :label="t('lms_learnership_enrolments.logbook.date_label')" type="date" /></VCol>
            <VCol cols="4"><VTextField v-model="logForm.hours_logged" :label="t('lms_learnership_enrolments.logbook.hours_label')" type="number" min="0.5" step="0.5" /></VCol>
            <VCol cols="4"><VTextField v-model="logForm.workplace_coach" :label="t('lms_learnership_enrolments.logbook.workplace_coach_label')" /></VCol>
            <VCol cols="12"><VTextarea v-model="logForm.activity_description" :label="t('lms_learnership_enrolments.logbook.activity_label')" rows="2" auto-grow /></VCol>
            <VCol cols="12"><VTextarea v-model="logForm.learner_reflection" :label="t('lms_learnership_enrolments.logbook.reflection_label')" rows="2" auto-grow /></VCol>
            <VCol cols="12"><VFileInput v-model="evidenceFile" :label="t('lms_learnership_enrolments.logbook.evidence_label')" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg" prepend-icon="tabler-paperclip" clearable density="compact" :error-messages="logForm.errors.evidence_file" /></VCol>
          </VRow>
          <VBtn color="primary" :loading="logForm.processing" prepend-icon="tabler-plus" @click="addLog">{{ t('lms_learnership_enrolments.logbook.add_button') }}</VBtn>
        </VCardText>
        <VCardActions class="pa-5 pt-0 justify-end">
          <VBtn variant="outlined" @click="logbookDialog = false">{{ t('lms_learnership_enrolments.logbook.close') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
