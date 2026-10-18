<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Portfolio = {
  id: number; uuid: string; title: string; status: string
  user: { id: number; person: { first_name: string; surname: string } | null }
  course: { title: string } | null
  assessor: { first_name: string; last_name: string } | null; assessor_id: number | null
  moderator: { first_name: string; last_name: string } | null; moderator_id: number | null
  submitted_at: string | null; assessed_at: string | null; moderated_at: string | null
  assessment_decision: string | null; moderation_decision: string | null
  learner_declared: boolean
}

const props = defineProps<{
  portfolios: Portfolio[]
  assessors:  { id: number; first_name: string; last_name: string }[]
  moderators: { id: number; first_name: string; last_name: string }[]
}>()

const filter    = ref('all')
const assignDialog  = ref(false)
const assessDialog  = ref(false)
const moderDialog   = ref(false)
const activePortfolio = ref<Portfolio | null>(null)

const assignForm = useForm({ assessor_id: null as number | null, moderator_id: null as number | null })
const assessForm = useForm({ assessment_decision: 'competent', assessor_feedback: '' })
const moderForm  = useForm({ moderation_decision: 'agreed', moderator_feedback: '' })

const filtered = computed(() => {
  if (filter.value === 'all') return props.portfolios
  return props.portfolios.filter(p => p.status === filter.value)
})

const filterItems = computed(() => [
  { value: 'all', label: t('lms_poe.filter.all', { count: props.portfolios.length }) },
  { value: 'submitted', label: t('lms_poe.filter.submitted') },
  { value: 'under_review', label: t('lms_poe.filter.under_review') },
  { value: 'approved', label: t('lms_poe.filter.approved') },
  { value: 'certified', label: t('lms_poe.filter.certified') },
  { value: 'returned', label: t('lms_poe.filter.returned') },
])

const decisionItems = computed(() => [
  { title: t('lms_poe.decisions.competent'), value: 'competent' },
  { title: t('lms_poe.decisions.not_yet_competent'), value: 'not_yet_competent' },
  { title: t('lms_poe.decisions.insufficient_evidence'), value: 'insufficient_evidence' },
])

const moderationItems = computed(() => [
  { title: t('lms_poe.moderation_decisions.agreed'), value: 'agreed' },
  { title: t('lms_poe.moderation_decisions.different'), value: 'different' },
])

function openAssign(p: Portfolio) { activePortfolio.value = p; assignForm.reset(); assignForm.assessor_id = p.assessor_id; assignForm.moderator_id = p.moderator_id; assignDialog.value = true }
function openAssess(p: Portfolio) { activePortfolio.value = p; assessForm.reset(); assessDialog.value = true }
function openModerate(p: Portfolio) { activePortfolio.value = p; moderForm.reset(); moderDialog.value = true }
function saveAssign() { assignForm.post(route('lms.admin.qcto.poe.assign', { portfolio: activePortfolio.value!.id }), { onSuccess: () => { assignDialog.value = false } }) }
function saveAssess() { assessForm.post(route('lms.admin.qcto.poe.assess', { portfolio: activePortfolio.value!.id }), { onSuccess: () => { assessDialog.value = false } }) }
function saveModerate() { moderForm.post(route('lms.admin.qcto.poe.moderate', { portfolio: activePortfolio.value!.id }), { onSuccess: () => { moderDialog.value = false } }) }

const statusColor: Record<string, string> = { draft: 'default', submitted: 'info', under_review: 'warning', returned: 'error', approved: 'success', rejected: 'error', certified: 'success' }

function learnerName(p: Portfolio) { const person = p.user?.person; return person ? `${person.first_name} ${person.surname}` : `User #${p.user?.id}` }
</script>

<template>
  <div class="pa-5">
    <div class="d-flex align-center gap-3 mb-5">
      <div class="flex-1-1">
        <h4 class="text-h4 mb-0">{{ t('lms_poe.title') }}</h4>
        <p class="text-caption text-medium-emphasis mb-0">{{ t('lms_poe.subtitle') }}</p>
      </div>
    </div>

    <div class="d-flex gap-2 mb-4 flex-wrap">
      <VBtnToggle v-model="filter" variant="outlined" density="compact">
        <VBtn v-for="fi in filterItems" :key="fi.value" :value="fi.value">{{ fi.label }}</VBtn>
      </VBtnToggle>
    </div>

    <VCard rounded="lg" elevation="0" border>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('lms_poe.table.learner') }}</th>
            <th>{{ t('lms_poe.table.portfolio') }}</th>
            <th>{{ t('lms_poe.table.course') }}</th>
            <th>{{ t('lms_poe.table.submitted') }}</th>
            <th>{{ t('lms_poe.table.assessor') }}</th>
            <th>{{ t('lms_poe.table.decision') }}</th>
            <th>{{ t('lms_poe.table.status') }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="p in filtered" :key="p.id">
            <td>{{ learnerName(p) }}</td>
            <td>
              <p class="text-body-2 font-weight-medium mb-0">{{ p.title }}</p>
              <VChip v-if="!p.learner_declared" color="warning" size="x-small" variant="tonal">{{ t('lms_poe.table.no_declaration') }}</VChip>
            </td>
            <td>{{ p.course?.title ?? '—' }}</td>
            <td>{{ p.submitted_at ? new Date(p.submitted_at).toLocaleDateString('en-ZA') : '—' }}</td>
            <td><span v-if="p.assessor">{{ p.assessor.first_name }} {{ p.assessor.last_name }}</span><span v-else class="text-medium-emphasis">{{ t('lms_poe.table.not_assigned') }}</span></td>
            <td><VChip v-if="p.assessment_decision" :color="p.assessment_decision === 'competent' ? 'success' : 'error'" size="x-small" variant="tonal" class="text-capitalize">{{ p.assessment_decision.replace('_',' ') }}</VChip><span v-else class="text-medium-emphasis">—</span></td>
            <td><VChip :color="statusColor[p.status]" size="x-small" variant="tonal" class="text-capitalize">{{ p.status.replace('_',' ') }}</VChip></td>
            <td>
              <div class="d-flex gap-1">
                <VBtn v-if="['submitted','under_review'].includes(p.status)" size="x-small" variant="tonal" @click="openAssign(p)">{{ t('lms_poe.table.assign') }}</VBtn>
                <VBtn v-if="p.status === 'under_review' && !p.assessment_decision" size="x-small" variant="tonal" color="primary" @click="openAssess(p)">{{ t('lms_poe.table.assess') }}</VBtn>
                <VBtn v-if="p.status === 'approved' && !p.moderation_decision" size="x-small" variant="tonal" color="warning" @click="openModerate(p)">{{ t('lms_poe.table.moderate') }}</VBtn>
              </div>
            </td>
          </tr>
          <tr v-if="!filtered.length">
            <td colspan="8" class="text-center text-medium-emphasis pa-6">{{ t('lms_poe.table.no_portfolios') }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="assignDialog" max-width="480">
      <VCard rounded="lg">
        <VCardTitle class="pa-5 pb-0">{{ t('lms_poe.assign_dialog.title') }}</VCardTitle>
        <VCardText class="pa-5">
          <VSelect v-model="assignForm.assessor_id" :label="t('lms_poe.assign_dialog.assessor_label')" :items="assessors.map(a => ({title: a.first_name + ' ' + a.last_name, value: a.id}))" :error-messages="assignForm.errors.assessor_id" class="mb-3" />
          <VSelect v-model="assignForm.moderator_id" :label="t('lms_poe.assign_dialog.moderator_label')" :items="moderators.map(m => ({title: m.first_name + ' ' + m.last_name, value: m.id}))" clearable />
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2 justify-end"><VBtn variant="outlined" @click="assignDialog = false">{{ t('lms_poe.assign_dialog.cancel') }}</VBtn><VBtn color="primary" :loading="assignForm.processing" @click="saveAssign">{{ t('lms_poe.assign_dialog.assign') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="assessDialog" max-width="480">
      <VCard rounded="lg">
        <VCardTitle class="pa-5 pb-0">{{ t('lms_poe.assess_dialog.title') }}</VCardTitle>
        <VCardText class="pa-5">
          <VSelect v-model="assessForm.assessment_decision" :label="t('lms_poe.assess_dialog.decision_label')" :items="decisionItems" class="mb-3" />
          <VTextarea v-model="assessForm.assessor_feedback" :label="t('lms_poe.assess_dialog.feedback_label')" rows="3" auto-grow />
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2 justify-end"><VBtn variant="outlined" @click="assessDialog = false">{{ t('lms_poe.assess_dialog.cancel') }}</VBtn><VBtn color="primary" :loading="assessForm.processing" @click="saveAssess">{{ t('lms_poe.assess_dialog.record') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="moderDialog" max-width="480">
      <VCard rounded="lg">
        <VCardTitle class="pa-5 pb-0">{{ t('lms_poe.moderate_dialog.title') }}</VCardTitle>
        <VCardText class="pa-5">
          <VSelect v-model="moderForm.moderation_decision" :label="t('lms_poe.moderate_dialog.decision_label')" :items="moderationItems" class="mb-3" />
          <VTextarea v-model="moderForm.moderator_feedback" :label="t('lms_poe.moderate_dialog.notes_label')" rows="3" auto-grow />
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2 justify-end"><VBtn variant="outlined" @click="moderDialog = false">{{ t('lms_poe.moderate_dialog.cancel') }}</VBtn><VBtn color="success" :loading="moderForm.processing" @click="saveModerate">{{ t('lms_poe.moderate_dialog.record') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
