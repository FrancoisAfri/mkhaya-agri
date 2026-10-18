<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Attendee {
  id: number; user_id: number|null; display_name: string; role: string; role_label: string
  external_name: string|null; external_org: string|null; attended: boolean; apology_received: boolean; notes: string|null
}
interface Minute {
  id: number; minute_type: string; type_label: string; sequence: number
  speaker_name: string|null; content: string; minute_time: string|null; recorded_by: string
}
interface HearingOutcome {
  id: number; verdict: string; verdict_color: string; outcome: string|null; sanction: string|null
  reasoning: string; sanction_detail: string|null; effective_date: string|null; appeal_deadline: string|null
  appeal_lodged: boolean; decided_at: string
}
interface HearingDetail {
  id: number; hearing_number: string; case_id: number; case_number: string
  case_title: string; employee_name: string; category: string|null
  chairperson_id: number|null; chairperson_name: string; initiator_name: string; hr_rep_name: string
  status: string; status_color: string; hearing_type: string
  scheduled_at: string; venue: string|null; venue_type: string; meeting_link: string|null
  duration_minutes: number|null; notice_issued: boolean; notice_issued_date: string|null
  pack_generated: boolean; adjournment_reason: string|null; adjourned_to: string|null
}
interface OutcomeOption { id: number; name: string; requires_sanction: boolean }
interface SanctionOption { id: number; name: string }
interface Employee { id: number; name: string; email: string }

const props = defineProps<{
  hearing:   HearingDetail
  attendees: Attendee[]
  minutes:   Minute[]
  outcome:   HearingOutcome|null
  outcomes:  OutcomeOption[]
  sanctions: SanctionOption[]
  employees: Employee[]
  canEdit:   boolean
  isChair:   boolean
}>()

const tab = ref('overview')
const canAct = computed(() => props.canEdit || props.isChair)

// ── Issue notice ───────────────────────────────────────────────────────────────
const noticeForm = useForm({})
function issueNotice() {
  noticeForm.post(route('disciplinary.hearings.issue-notice', props.hearing.id))
}

// ── Generate pack ──────────────────────────────────────────────────────────────
const packForm = useForm({})
function generatePack() {
  packForm.post(route('disciplinary.hearings.generate-pack', props.hearing.id))
}

// ── Add minute ─────────────────────────────────────────────────────────────────
const minuteDialog = ref(false)
const minuteForm = useForm({ minute_type: 'general', speaker_name: '', content: '', minute_time: '' })
function submitMinute() {
  minuteForm.post(route('disciplinary.hearings.add-minute', props.hearing.id), {
    onSuccess: () => { minuteDialog.value = false; minuteForm.reset() },
  })
}

// ── Add attendee ───────────────────────────────────────────────────────────────
const attendeeDialog = ref(false)
const attendeeForm = useForm({ user_id: null as number|null, role: 'witness', external_name: '', external_org: '' })
function submitAttendee() {
  attendeeForm.post(route('disciplinary.hearings.add-attendee', props.hearing.id), {
    onSuccess: () => {
      attendeeDialog.value = false
      attendeeForm.reset()
      router.reload({ only: ['attendees'] })
    },
  })
}

// ── Mark attendance ────────────────────────────────────────────────────────────
const attendanceForm = useForm({ attendees: props.attendees.map(a => ({ id: a.id, attended: a.attended, apology_received: a.apology_received })) })

// Keep attendance form in sync when attendees prop updates after add/remove
watch(() => props.attendees, (updated) => {
  attendanceForm.attendees = updated.map(a => ({ id: a.id, attended: a.attended, apology_received: a.apology_received }))
}, { deep: true })

function submitAttendance() {
  attendanceForm.post(route('disciplinary.hearings.mark-attendance', props.hearing.id), {
    onSuccess: () => router.reload({ only: ['attendees'] }),
  })
}

// ── Capture outcome ────────────────────────────────────────────────────────────
const outcomeDialog = ref(false)
const outcomeForm = useForm({
  verdict:        '' as string,
  outcome_id:     null as number|null,
  sanction_id:    null as number|null,
  reasoning:      '',
  sanction_detail:'',
  effective_date: '',
  appeal_deadline:'',
})
function submitOutcome() {
  outcomeForm.post(route('disciplinary.hearings.capture-outcome', props.hearing.id), {
    onSuccess: () => { outcomeDialog.value = false },
  })
}
const selectedOutcomeRequiresSanction = computed(() =>
  props.outcomes.find(o => o.id === outcomeForm.outcome_id)?.requires_sanction ?? false
)

const minuteTypes = ['opening','charge_reading','plea','evidence','witness_testimony','closing','procedural','general']
const attendeeRoles = ['employee','employee_rep','chairperson','initiator','hr_rep','witness','observer']
</script>

<template>
  <div class="pa-4">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <p class="text-overline text-medium-emphasis mb-0">{{ t('disciplinary.module_label') }}</p>
        <h1 class="text-h5 font-weight-bold">{{ hearing.hearing_number }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-0">{{ hearing.case_title }}</p>
      </div>
      <div class="d-flex ga-2 flex-wrap">
        <VBtn variant="outlined" size="small" prepend-icon="tabler-arrow-left" :href="route('disciplinary.hearings.index')">
          {{ t('disciplinary.hearings') }}
        </VBtn>
        <VBtn variant="outlined" size="small" prepend-icon="tabler-briefcase" :href="route('disciplinary.cases.show', hearing.case_id)">
          {{ hearing.case_number }}
        </VBtn>
        <VBtn v-if="canEdit && !hearing.notice_issued" size="small" variant="tonal" prepend-icon="tabler-mail" :loading="noticeForm.processing" @click="issueNotice">
          {{ t('disciplinary.issue_notice') }}
        </VBtn>
        <VBtn v-if="canEdit" size="small" variant="tonal" prepend-icon="tabler-file-text" :loading="packForm.processing" @click="generatePack">
          {{ t('disciplinary.generate_pack') }}
        </VBtn>
        <VBtn v-if="canAct && !outcome" color="primary" size="small" prepend-icon="tabler-gavel" @click="outcomeDialog = true">
          {{ t('disciplinary.capture_outcome') }}
        </VBtn>
      </div>
    </div>

    <!-- Status strip -->
    <VRow dense class="mb-4">
      <VCol cols="12" sm="auto">
        <VChip :color="hearing.status_color" size="large" prepend-icon="tabler-point">{{ hearing.status.replace('_',' ') }}</VChip>
      </VCol>
      <VCol cols="12" sm="auto">
        <VChip color="grey" size="large">{{ hearing.hearing_type }}</VChip>
      </VCol>
      <VCol v-if="hearing.notice_issued" cols="12" sm="auto">
        <VChip color="success" size="large" prepend-icon="tabler-mail-check">{{ t('disciplinary.notice_issued') }}</VChip>
      </VCol>
      <VCol v-if="hearing.pack_generated" cols="12" sm="auto">
        <VBtn
          color="primary"
          size="small"
          variant="tonal"
          prepend-icon="tabler-file-download"
          :href="route('disciplinary.hearings.download-pack', hearing.id)"
        >{{ t('disciplinary.pack_ready') }}</VBtn>
      </VCol>
    </VRow>

    <!-- Tabs -->
    <VCard>
      <VTabs v-model="tab" color="primary">
        <VTab value="overview">{{ t('disciplinary.overview') }}</VTab>
        <VTab value="attendees">{{ t('disciplinary.attendees') }} <VChip class="ml-2" size="x-small">{{ attendees.length }}</VChip></VTab>
        <VTab value="minutes">{{ t('disciplinary.minutes') }} <VChip class="ml-2" size="x-small">{{ minutes.length }}</VChip></VTab>
        <VTab v-if="outcome" value="outcome">{{ t('disciplinary.outcome') }}</VTab>
      </VTabs>
      <VDivider />

      <VTabsWindow v-model="tab">

        <!-- Overview -->
        <VTabsWindowItem value="overview">
          <VCardText>
            <VRow>
              <VCol cols="12" md="8">
                <VRow dense>
                  <VCol cols="12" sm="6">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.employee') }}</div>
                    <div class="font-weight-medium">{{ hearing.employee_name }}</div>
                  </VCol>
                  <VCol cols="12" sm="6">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.category') }}</div>
                    <div>{{ hearing.category ?? '—' }}</div>
                  </VCol>
                  <VCol cols="12" sm="6">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.scheduled_at') }}</div>
                    <div>{{ hearing.scheduled_at }}</div>
                  </VCol>
                  <VCol cols="12" sm="6">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.venue') }}</div>
                    <div>{{ hearing.venue ?? '—' }} ({{ hearing.venue_type }})</div>
                  </VCol>
                  <VCol v-if="hearing.meeting_link" cols="12">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.meeting_link') }}</div>
                    <a :href="hearing.meeting_link" target="_blank" class="text-body-2 text-primary">{{ hearing.meeting_link }}</a>
                  </VCol>
                  <VCol v-if="hearing.adjournment_reason" cols="12">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.adjournment_reason') }}</div>
                    <div class="text-body-2">{{ hearing.adjournment_reason }}</div>
                  </VCol>
                </VRow>
              </VCol>
              <VCol cols="12" md="4">
                <VCard variant="tonal" color="grey" class="mb-3">
                  <VCardText class="pb-2">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.chairperson') }}</div>
                    <div class="font-weight-medium">{{ hearing.chairperson_name }}</div>
                  </VCardText>
                </VCard>
                <VCard variant="tonal" color="grey" class="mb-3">
                  <VCardText class="pb-2">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.initiator') }}</div>
                    <div>{{ hearing.initiator_name }}</div>
                  </VCardText>
                </VCard>
                <VCard variant="tonal" color="grey">
                  <VCardText class="pb-2">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.hr_rep') }}</div>
                    <div>{{ hearing.hr_rep_name }}</div>
                  </VCardText>
                </VCard>
              </VCol>
            </VRow>
          </VCardText>
        </VTabsWindowItem>

        <!-- Attendees -->
        <VTabsWindowItem value="attendees">
          <VCardText>
            <div class="d-flex justify-end ga-2 mb-3">
              <VBtn v-if="canEdit" size="small" variant="tonal" prepend-icon="tabler-plus" @click="attendeeDialog = true">{{ t('disciplinary.add_attendee') }}</VBtn>
              <VBtn v-if="canAct" size="small" variant="tonal" color="success" prepend-icon="tabler-user-check" :loading="attendanceForm.processing" @click="submitAttendance">
                {{ t('disciplinary.save_attendance') }}
              </VBtn>
            </div>
            <VTable density="compact">
              <thead>
                <tr>
                  <th>{{ t('disciplinary.name') }}</th>
                  <th>{{ t('disciplinary.role') }}</th>
                  <th class="text-center">{{ t('disciplinary.attended') }}</th>
                  <th class="text-center">{{ t('disciplinary.apology') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(a, i) in attendees" :key="a.id">
                  <td>{{ a.display_name }}</td>
                  <td><VChip size="x-small" color="grey">{{ a.role_label }}</VChip></td>
                  <td class="text-center">
                    <VCheckbox v-if="canAct" v-model="attendanceForm.attendees[i].attended" hide-details density="compact" class="d-inline-flex" />
                    <VIcon v-else :color="a.attended ? 'success' : 'grey'">{{ a.attended ? 'tabler-check' : 'tabler-minus' }}</VIcon>
                  </td>
                  <td class="text-center">
                    <VCheckbox v-if="canAct" v-model="attendanceForm.attendees[i].apology_received" hide-details density="compact" class="d-inline-flex" />
                    <VIcon v-else :color="a.apology_received ? 'warning' : 'grey'">{{ a.apology_received ? 'tabler-check' : 'tabler-minus' }}</VIcon>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
        </VTabsWindowItem>

        <!-- Minutes -->
        <VTabsWindowItem value="minutes">
          <VCardText>
            <div class="d-flex justify-end mb-3">
              <VBtn v-if="canAct" size="small" variant="tonal" color="primary" prepend-icon="tabler-plus" @click="minuteDialog = true">
                {{ t('disciplinary.add_minute') }}
              </VBtn>
            </div>
            <div v-if="!minutes.length" class="text-center py-8 text-medium-emphasis">
              <VIcon size="48" class="mb-2">tabler-note</VIcon>
              <p>{{ t('disciplinary.no_minutes') }}</p>
            </div>
            <VTimeline side="end" density="compact">
              <VTimelineItem v-for="m in minutes" :key="m.id" dot-color="primary" size="small">
                <VCard variant="outlined" class="mb-2">
                  <VCardText class="py-2 px-3">
                    <div class="d-flex justify-space-between align-start mb-1">
                      <VChip size="x-small" color="primary">{{ m.type_label }}</VChip>
                      <span class="text-caption text-medium-emphasis">{{ m.minute_time }}</span>
                    </div>
                    <div v-if="m.speaker_name" class="text-caption font-weight-medium text-medium-emphasis mb-1">{{ m.speaker_name }}</div>
                    <div class="text-body-2">{{ m.content }}</div>
                  </VCardText>
                </VCard>
              </VTimelineItem>
            </VTimeline>
          </VCardText>
        </VTabsWindowItem>

        <!-- Outcome -->
        <VTabsWindowItem v-if="outcome" value="outcome">
          <VCardText>
            <VRow>
              <VCol cols="12" md="8">
                <div class="mb-4">
                  <VChip :color="outcome.verdict_color" size="large" class="mb-3">
                    {{ outcome.verdict?.replace('_', ' ').toUpperCase() }}
                  </VChip>
                  <div v-if="outcome.outcome" class="mb-2">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.outcome') }}</div>
                    <div class="font-weight-medium">{{ outcome.outcome }}</div>
                  </div>
                  <div v-if="outcome.sanction" class="mb-2">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.sanction') }}</div>
                    <div class="font-weight-medium">{{ outcome.sanction }}</div>
                  </div>
                  <div class="mb-3">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.reasoning') }}</div>
                    <div class="text-body-2 pa-2 rounded bg-grey-lighten-5">{{ outcome.reasoning }}</div>
                  </div>
                  <div v-if="outcome.sanction_detail" class="mb-3">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.sanction_detail') }}</div>
                    <div class="text-body-2">{{ outcome.sanction_detail }}</div>
                  </div>
                </div>
              </VCol>
              <VCol cols="12" md="4">
                <VCard v-if="outcome.effective_date" variant="tonal" color="grey" class="mb-3">
                  <VCardText class="pb-2">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.effective_date') }}</div>
                    <div>{{ outcome.effective_date }}</div>
                  </VCardText>
                </VCard>
                <VCard v-if="outcome.appeal_deadline" variant="tonal" :color="outcome.appeal_lodged ? 'error' : 'grey'">
                  <VCardText class="pb-2">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.appeal_deadline') }}</div>
                    <div>{{ outcome.appeal_deadline }}</div>
                    <VChip v-if="outcome.appeal_lodged" color="error" size="x-small" class="mt-1">{{ t('disciplinary.appeal_lodged') }}</VChip>
                  </VCardText>
                </VCard>
              </VCol>
            </VRow>
          </VCardText>
        </VTabsWindowItem>

      </VTabsWindow>
    </VCard>

    <!-- Add Minute Dialog -->
    <VDialog v-if="canAct" v-model="minuteDialog" max-width="520">
      <VCard :title="t('disciplinary.add_minute')">
        <VCardText class="d-flex flex-column ga-3">
          <VSelect v-model="minuteForm.minute_type" :items="minuteTypes" :label="t('disciplinary.minute_type')" variant="outlined" density="compact" />
          <VCombobox
            v-model="minuteForm.speaker_name"
            :items="attendees"
            item-title="display_name"
            :return-object="false"
            :label="t('disciplinary.speaker')"
            variant="outlined"
            density="compact"
            clearable
            hint="Select an attendee or type a custom name"
            persistent-hint
          />
          <VTextField v-model="minuteForm.minute_time" :label="t('disciplinary.time')" variant="outlined" density="compact" type="time" />
          <VTextarea v-model="minuteForm.content" :label="t('disciplinary.content')" variant="outlined" density="compact" rows="5" :error-messages="minuteForm.errors.content" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="outlined" @click="minuteDialog = false">{{ t('disciplinary.cancel') }}</VBtn>
          <VBtn color="primary" :loading="minuteForm.processing" @click="submitMinute">{{ t('disciplinary.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Add Attendee Dialog -->
    <VDialog v-if="canEdit" v-model="attendeeDialog" max-width="440">
      <VCard :title="t('disciplinary.add_attendee')">
        <VCardText class="d-flex flex-column ga-3">
          <VSelect v-model="attendeeForm.role" :items="attendeeRoles" :label="t('disciplinary.role')" variant="outlined" density="compact" />
          <VAutocomplete v-model="attendeeForm.user_id" :items="employees" item-title="name" item-value="id" :label="t('disciplinary.employee_optional')" variant="outlined" density="compact" clearable />
          <VTextField v-model="attendeeForm.external_name" :label="t('disciplinary.external_name')" variant="outlined" density="compact" />
          <VTextField v-model="attendeeForm.external_org" :label="t('disciplinary.external_org')" variant="outlined" density="compact" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="outlined" @click="attendeeDialog = false">{{ t('disciplinary.cancel') }}</VBtn>
          <VBtn color="primary" :loading="attendeeForm.processing" @click="submitAttendee">{{ t('disciplinary.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Capture Outcome Dialog -->
    <VDialog v-if="canAct" v-model="outcomeDialog" max-width="560">
      <VCard :title="t('disciplinary.capture_outcome')">
        <VCardText class="d-flex flex-column ga-3">
          <VSelect v-model="outcomeForm.verdict" :items="['guilty','not_guilty','inconclusive']" :label="t('disciplinary.verdict')" variant="outlined" density="compact" :error-messages="outcomeForm.errors.verdict" />
          <VSelect v-model="outcomeForm.outcome_id" :items="outcomes" item-title="name" item-value="id" :label="t('disciplinary.outcome')" variant="outlined" density="compact" clearable />
          <VSelect v-if="selectedOutcomeRequiresSanction" v-model="outcomeForm.sanction_id" :items="sanctions" item-title="name" item-value="id" :label="t('disciplinary.sanction')" variant="outlined" density="compact" clearable />
          <VTextarea v-model="outcomeForm.reasoning" :label="t('disciplinary.reasoning')" variant="outlined" density="compact" rows="4" :error-messages="outcomeForm.errors.reasoning" />
          <VTextarea v-model="outcomeForm.sanction_detail" :label="t('disciplinary.sanction_detail')" variant="outlined" density="compact" rows="2" />
          <VRow dense>
            <VCol cols="6"><VTextField v-model="outcomeForm.effective_date" :label="t('disciplinary.effective_date')" variant="outlined" density="compact" type="date" /></VCol>
            <VCol cols="6"><VTextField v-model="outcomeForm.appeal_deadline" :label="t('disciplinary.appeal_deadline')" variant="outlined" density="compact" type="date" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="outlined" @click="outcomeDialog = false">{{ t('disciplinary.cancel') }}</VBtn>
          <VBtn color="primary" :loading="outcomeForm.processing" @click="submitOutcome">{{ t('disciplinary.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
