<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Session {
  id: number; session_type: string; scheduled_at: string
  duration_minutes: number|null; venue: string|null
  status: string; coach: string|null; outcome: string|null
}
interface RehabRecord { period_end: string; overall_score: number; trend: string }
interface Action {
  id: number; action_number: string; employee: string; employee_id: number
  action_type: string; title: string; description: string
  due_date: string; completed_date: string|null; status: string
  progress_percent: number; progress_notes: string|null
  is_mandatory: boolean; ai_recommended: boolean; ai_reasoning: string|null
  case_number: string|null; assigned_by: string
}

interface Employee { id: number; name: string; email: string }

const props = defineProps<{
  action: Action
  sessions: Session[]
  rehab: RehabRecord[]
  employees: Employee[]
}>()

const progressDialog = ref(false)
const sessionDialog  = ref(false)
const rehabDialog    = ref(false)

const progressForm = useForm({ progress_percent: props.action.progress_percent, progress_notes: '' })
const sessionForm  = useForm({
  employee_id: props.action.employee_id, coach_id: null as number|null, session_type: 'coaching',
  scheduled_at: '', duration_minutes: '60', venue: '',
  agenda: '', corrective_action_id: props.action.id,
})
const rehabForm = useForm({
  employee_id: props.action.employee_id, period_start: '', period_end: '',
  'scores[behavior]': '5', 'scores[attendance]': '5',
  'scores[performance]': '5', 'scores[compliance]': '5',
  notes: '',
})

function submitProgress() {
  progressForm.post(route('disciplinary.corrective-actions.update-progress', props.action.id), {
    onSuccess: () => { progressDialog.value = false },
  })
}
function submitSession() {
  sessionForm.post(route('disciplinary.corrective-actions.sessions.store'), {
    onSuccess: () => { sessionDialog.value = false; sessionForm.reset() },
  })
}
function submitRehab() {
  rehabForm.post(route('disciplinary.corrective-actions.rehabilitation.store'), {
    onSuccess: () => { rehabDialog.value = false; rehabForm.reset() },
  })
}

const statusColor = (s: string) => ({ pending: 'warning', in_progress: 'blue', completed: 'success', failed: 'error', cancelled: 'grey' })[s] ?? 'grey'
const trendColor  = (t: string) => ({ improving: 'success', stable: 'blue', declining: 'error' })[t] ?? 'grey'

const sessionTypes = [
  { value: 'coaching', title: t('disciplinary_corrective-action-show.session_types.coaching') },
  { value: 'counseling', title: t('disciplinary_corrective-action-show.session_types.counseling') },
  { value: 'mentoring', title: t('disciplinary_corrective-action-show.session_types.mentoring') },
  { value: 'mediation', title: t('disciplinary_corrective-action-show.session_types.mediation') },
  { value: 'performance_review', title: t('disciplinary_corrective-action-show.session_types.performance_review') },
]
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center ga-3 mb-6">
      <VBtn icon variant="text" :href="route('disciplinary.corrective-actions.index')">
        <VIcon>tabler-arrow-left</VIcon>
      </VBtn>
      <div>
        <h1 class="text-h5 font-weight-bold">{{ action.action_number }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ action.employee }}</p>
      </div>
      <VSpacer />
      <VChip :color="statusColor(action.status)" variant="tonal">{{ action.status.replace('_', ' ') }}</VChip>
    </div>

    <VRow>
      <!-- Action details -->
      <VCol cols="12" md="8">
        <VCard class="mb-4">
          <VCardTitle>{{ t('disciplinary_corrective-action-show.action_details') }}</VCardTitle>
          <VCardText>
            <VChip color="blue-grey" size="small" variant="tonal" class="mb-3">{{ action.action_type.replace('_', ' ') }}</VChip>
            <h3 class="text-body-1 font-weight-medium mb-2">{{ action.title }}</h3>
            <p class="text-body-2 text-medium-emphasis mb-4">{{ action.description }}</p>
            <VRow>
              <VCol cols="6"><strong>{{ t('disciplinary_corrective-action-show.due_date') }}:</strong> {{ action.due_date }}</VCol>
              <VCol cols="6"><strong>{{ t('disciplinary_corrective-action-show.assigned_by') }}:</strong> {{ action.assigned_by }}</VCol>
              <VCol cols="6" v-if="action.case_number"><strong>{{ t('disciplinary_corrective-action-show.case') }}:</strong> {{ action.case_number }}</VCol>
            </VRow>
            <div v-if="action.ai_recommended" class="mt-3 pa-3 bg-blue-grey-lighten-5 rounded">
              <div class="d-flex align-center ga-1 mb-1"><VIcon size="small">tabler-robot</VIcon><strong class="text-body-2">{{ t('disciplinary_corrective-action-show.ai_recommended') }}</strong></div>
              <p class="text-body-2">{{ action.ai_reasoning }}</p>
            </div>
          </VCardText>
        </VCard>

        <!-- Progress -->
        <VCard class="mb-4">
          <VCardTitle class="d-flex align-center justify-space-between">
            <span>{{ t('disciplinary_corrective-action-show.progress') }}</span>
            <VBtn size="small" color="primary" variant="tonal" @click="progressDialog = true">{{ t('disciplinary_corrective-action-show.update') }}</VBtn>
          </VCardTitle>
          <VCardText>
            <VProgressLinear :model-value="action.progress_percent" color="primary" height="20" rounded class="mb-2">
              <template #default>{{ action.progress_percent }}%</template>
            </VProgressLinear>
            <p v-if="action.progress_notes" class="text-body-2 text-medium-emphasis">{{ action.progress_notes }}</p>
          </VCardText>
        </VCard>

        <!-- Coaching Sessions -->
        <VCard class="mb-4">
          <VCardTitle class="d-flex align-center justify-space-between">
            <span>{{ t('disciplinary_corrective-action-show.coaching_sessions') }}</span>
            <VBtn size="small" color="primary" variant="tonal" @click="sessionDialog = true">{{ t('disciplinary_corrective-action-show.schedule') }}</VBtn>
          </VCardTitle>
          <VCardText>
            <VList v-if="sessions.length" lines="two">
              <VListItem v-for="s in sessions" :key="s.id" :title="s.session_type + ' — ' + s.scheduled_at" :subtitle="s.coach">
                <template #append>
                  <VChip :color="s.status === 'completed' ? 'success' : 'blue'" size="small" variant="tonal">{{ s.status }}</VChip>
                </template>
              </VListItem>
            </VList>
            <p v-else class="text-medium-emphasis text-body-2">{{ t('disciplinary_corrective-action-show.no_sessions') }}</p>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Rehabilitation progress -->
      <VCol cols="12" md="4">
        <VCard>
          <VCardTitle class="d-flex align-center justify-space-between">
            <span>{{ t('disciplinary_corrective-action-show.rehabilitation') }}</span>
            <VBtn size="small" color="primary" variant="tonal" @click="rehabDialog = true">{{ t('disciplinary_corrective-action-show.record') }}</VBtn>
          </VCardTitle>
          <VCardText>
            <VTimeline v-if="rehab.length" side="end" density="compact">
              <VTimelineItem v-for="r in rehab" :key="r.period_end" :dot-color="trendColor(r.trend)" size="small">
                <div class="text-body-2 font-weight-medium">{{ r.period_end }}</div>
                <div class="text-body-2">Score: {{ r.overall_score }}</div>
                <VChip :color="trendColor(r.trend)" size="x-small" variant="tonal">{{ r.trend }}</VChip>
              </VTimelineItem>
            </VTimeline>
            <p v-else class="text-medium-emphasis text-body-2">{{ t('disciplinary_corrective-action-show.no_rehab') }}</p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Update Progress Dialog -->
    <VDialog v-model="progressDialog" max-width="440">
      <VCard>
        <VCardTitle class="pa-4">{{ t('disciplinary_corrective-action-show.dialog.progress_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <p class="text-body-2 mb-3">Progress: {{ progressForm.progress_percent }}%</p>
          <VSlider v-model="progressForm.progress_percent" min="0" max="100" step="5" thumb-label class="mb-3" />
          <VTextarea v-model="progressForm.progress_notes" :label="t('disciplinary_corrective-action-show.dialog.progress_label')" rows="3" :error-messages="progressForm.errors.progress_notes" />
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="progressDialog = false">{{ t('disciplinary_corrective-action-show.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="progressForm.processing" @click="submitProgress">{{ t('disciplinary_corrective-action-show.dialog.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Schedule Session Dialog -->
    <VDialog v-model="sessionDialog" max-width="480">
      <VCard>
        <VCardTitle class="pa-4">{{ t('disciplinary_corrective-action-show.dialog.session_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12">
              <VAutocomplete
                v-model="sessionForm.coach_id"
                :items="employees"
                item-title="name"
                item-value="id"
                :label="t('disciplinary_corrective-action-show.dialog.coach_label')"
                variant="outlined"
                density="compact"
                :error-messages="sessionForm.errors.coach_id"
                clearable
              >
                <template #item="{ props: itemProps, item }">
                  <VListItem v-bind="itemProps" :subtitle="item.raw.email" />
                </template>
              </VAutocomplete>
            </VCol>
            <VCol cols="12">
              <VSelect v-model="sessionForm.session_type" :label="t('disciplinary_corrective-action-show.dialog.type_label')" :items="sessionTypes" :error-messages="sessionForm.errors.session_type" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="sessionForm.scheduled_at" :label="t('disciplinary_corrective-action-show.dialog.datetime_label')" type="datetime-local" :error-messages="sessionForm.errors.scheduled_at" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="sessionForm.duration_minutes" :label="t('disciplinary_corrective-action-show.dialog.duration_label')" type="number" />
            </VCol>
            <VCol cols="12">
              <VTextField v-model="sessionForm.venue" :label="t('disciplinary_corrective-action-show.dialog.venue_label')" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="sessionForm.agenda" :label="t('disciplinary_corrective-action-show.dialog.agenda_label')" rows="2" />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="sessionDialog = false">{{ t('disciplinary_corrective-action-show.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="sessionForm.processing" @click="submitSession">{{ t('disciplinary_corrective-action-show.dialog.schedule_button') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Record Rehab Dialog -->
    <VDialog v-model="rehabDialog" max-width="480">
      <VCard>
        <VCardTitle class="pa-4">{{ t('disciplinary_corrective-action-show.dialog.rehab_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="6">
              <VTextField v-model="rehabForm.period_start" :label="t('disciplinary_corrective-action-show.dialog.period_start_label')" type="date" :error-messages="rehabForm.errors.period_start" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="rehabForm.period_end" :label="t('disciplinary_corrective-action-show.dialog.period_end_label')" type="date" :error-messages="rehabForm.errors.period_end" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="rehabForm['scores[behavior]']" :label="t('disciplinary_corrective-action-show.dialog.behavior_label')" type="number" min="0" max="10" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="rehabForm['scores[attendance]']" :label="t('disciplinary_corrective-action-show.dialog.attendance_label')" type="number" min="0" max="10" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="rehabForm['scores[performance]']" :label="t('disciplinary_corrective-action-show.dialog.performance_label')" type="number" min="0" max="10" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="rehabForm['scores[compliance]']" :label="t('disciplinary_corrective-action-show.dialog.compliance_label')" type="number" min="0" max="10" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="rehabForm.notes" :label="t('disciplinary_corrective-action-show.dialog.notes_label')" rows="2" />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="rehabDialog = false">{{ t('disciplinary_corrective-action-show.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="rehabForm.processing" @click="submitRehab">{{ t('disciplinary_corrective-action-show.dialog.record_button') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
