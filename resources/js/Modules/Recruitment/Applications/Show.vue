<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import AppStatusChip from '../partials/AppStatusChip.vue'

const route = ziggyRoute

// ─── Reference check ─────────────────────────────────────────────────────────
const showRefDialog     = ref(false)
const showRefUpdateDialog = ref(false)
const selectedRef       = ref<Reference | null>(null)

const refForm = useForm({
  referee_name:    '',
  referee_email:   '',
  referee_phone:   '',
  referee_company: '',
  referee_title:   '',
  relationship:    '',
})

function submitRef() {
  refForm.post(
    route('recruitment.jobs.applications.references.store', [props.job.id, props.application.id]),
    { onSuccess: () => { showRefDialog.value = false; refForm.reset() } },
  )
}

const refUpdateForm = useForm({ status: 'pending' as string, response_notes: '' })

function openRefUpdate(ref: Reference) {
  selectedRef.value = ref
  refUpdateForm.status = ref.status
  refUpdateForm.response_notes = ref.response_notes ?? ''
  showRefUpdateDialog.value = true
}

function submitRefUpdate() {
  if (!selectedRef.value) return
  refUpdateForm.patch(
    route('recruitment.jobs.applications.references.update', [props.job.id, props.application.id, selectedRef.value.id]),
    { onSuccess: () => { showRefUpdateDialog.value = false } },
  )
}

function deleteRef(refId: number) {
  router.delete(
    route('recruitment.jobs.applications.references.destroy', [props.job.id, props.application.id, refId]),
    { preserveScroll: true },
  )
}

function refStatusColor(status: string) {
  if (status === 'received')  return 'success'
  if (status === 'contacted') return 'warning'
  return 'default'
}

// ─── Communication Centre ────────────────────────────────────────────────────
const showMsgDialog = ref(false)
const msgForm = useForm({ channel: 'note' as string, subject: '', body: '' })

function submitMsg() {
  msgForm.post(
    route('recruitment.jobs.applications.messages.store', [props.job.id, props.application.id]),
    { onSuccess: () => { showMsgDialog.value = false; msgForm.reset() } },
  )
}

function msgIcon(channel: string) {
  return channel === 'email' ? 'tabler-mail' : 'tabler-note'
}

function msgColor(channel: string) {
  return channel === 'email' ? 'info' : 'default'
}

type Reference = {
  id: number
  referee_name: string
  referee_email: string | null
  referee_phone: string | null
  referee_company: string | null
  referee_title: string | null
  relationship: string | null
  status: 'pending' | 'contacted' | 'received'
  response_notes: string | null
  contacted_at: string | null
  received_at: string | null
  added_by: { id: number; name: string } | null
}

type Message = {
  id: number
  direction: 'outbound' | 'inbound'
  channel: 'email' | 'note'
  subject: string | null
  body: string
  sent_at: string
  sender: { id: number; name: string } | null
}

const props = defineProps<{
  job: { id: number; title: string }
  application: {
    id: number
    status: { value: string; label: string }
    source: string | null
    applied_at: string
    rejected_at: string | null
    rejected_reason: string | null
    withdrawn_at: string | null
    current_stage: { id: number; name: string; type: string } | null
    candidate: {
      id: number
      full_name: string
      email: string
      phone: string | null
      ai_profile: { summary: string | null; strengths: any; flags: any } | null
    } | null
    fit_score: { overall: number; skills: number; experience: number } | null
    recruiter: { id: number; name: string } | null
    interviews: any[]
  }
  statusOptions: { value: string; label: string }[]
  references: Reference[]
  messages: Message[]
  interviewers: { id: number; name: string }[]
}>()

// ─── Reject dialog ────────────────────────────────────────────────────────────
const showRejectDialog = ref(false)
const rejectForm = useForm({ reason: '' })

function submitReject() {
  rejectForm.post(
    route('recruitment.jobs.applications.reject', [props.job.id, props.application.id]),
    { onSuccess: () => { showRejectDialog.value = false } },
  )
}

const canReject = !['rejected', 'withdrawn', 'hired'].includes(props.application.status.value)

// ─── Move Stage dialog ────────────────────────────────────────────────────────
const showMoveDialog = ref(false)
const moveForm = useForm({
  status: '' as string,
  pipeline_stage_id: null as number | null,
  reason: '',
})

function submitMove() {
  moveForm.patch(
    route('recruitment.jobs.applications.move', [props.job.id, props.application.id]),
    { onSuccess: () => { showMoveDialog.value = false; moveForm.reset() } }
  )
}

// ─── Schedule interview dialog ─────────────────────────────────────────────
const showScheduleDialog = ref(false)
const interviewTypes = [
  { value: 'technical',   label: 'Technical' },
  { value: 'behavioural', label: 'Behavioural' },
  { value: 'leadership',  label: 'Leadership' },
  { value: 'cultural',    label: 'Cultural Fit' },
  { value: 'panel',       label: 'Panel' },
  { value: 'final',       label: 'Final' },
]
const scheduleForm = useForm({
  type:             'technical' as string,
  interviewer_id:   null as number | null,
  scheduled_at:     '' as string,
  duration_minutes: 60 as number,
  location:         '' as string,
  meeting_url:      '' as string,
  notes:            '' as string,
})

function submitSchedule() {
  scheduleForm.post(
    route('recruitment.jobs.applications.interviews.store', [props.job.id, props.application.id]),
    { onSuccess: () => { showScheduleDialog.value = false; scheduleForm.reset() } }
  )
}

// ─── Helpers ─────────────────────────────────────────────────────────────────
function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', {
    day:   '2-digit',
    month: 'short',
    year:  'numeric',
  })
}
</script>

<template>
  <div class="pa-6">
    <!-- Breadcrumb -->
    <div class="d-flex align-center gap-2 mb-4">
      <VBtn
        icon="tabler-arrow-left"
        variant="text"
        size="small"
        @click="router.visit(route('recruitment.jobs.applications.index', job.id))"
      />
      <span
        class="text-medium-emphasis text-body-2 cursor-pointer"
        @click="router.visit(route('recruitment.jobs.index'))"
      >
        Recruitment
      </span>
      <VIcon icon="tabler-chevron-right" size="16" class="text-medium-emphasis" />
      <span
        class="text-medium-emphasis text-body-2 cursor-pointer"
        @click="router.visit(route('recruitment.jobs.index'))"
      >
        Jobs
      </span>
      <VIcon icon="tabler-chevron-right" size="16" class="text-medium-emphasis" />
      <span
        class="text-medium-emphasis text-body-2 cursor-pointer"
        @click="router.visit(route('recruitment.jobs.show', job.id))"
      >
        {{ job.title }}
      </span>
      <VIcon icon="tabler-chevron-right" size="16" class="text-medium-emphasis" />
      <span
        class="text-medium-emphasis text-body-2 cursor-pointer"
        @click="router.visit(route('recruitment.jobs.applications.index', job.id))"
      >
        Applications
      </span>
      <VIcon icon="tabler-chevron-right" size="16" class="text-medium-emphasis" />
      <span class="text-body-2 font-weight-medium">
        {{ application.candidate?.full_name ?? 'Application' }}
      </span>
    </div>

    <!-- Header -->
    <div class="d-flex align-start justify-space-between mb-6">
      <div>
        <div class="d-flex align-center gap-2 mb-1">
          <h4 class="text-h4 font-weight-bold">
            {{ application.candidate?.full_name ?? 'Application' }}
          </h4>
          <AppStatusChip :status="application.status" />
        </div>
        <p class="text-medium-emphasis mb-0">
          {{ job.title }} · Applied {{ formatDate(application.applied_at) }}
        </p>
      </div>
      <div class="d-flex align-center gap-2">
        <VBtn
          v-if="!['rejected','withdrawn','accepted'].includes(props.application.status.value)"
          color="primary"
          variant="tonal"
          prepend-icon="tabler-transfer"
          class="me-2"
          @click="showMoveDialog = true"
        >
          Move Stage
        </VBtn>
        <VBtn
          v-if="!['rejected','withdrawn','accepted'].includes(props.application.status.value)"
          color="info"
          variant="tonal"
          prepend-icon="tabler-calendar-plus"
          @click="showScheduleDialog = true"
        >
          Schedule Interview
        </VBtn>
        <VBtn
          v-if="canReject"
          color="error"
          variant="tonal"
          prepend-icon="tabler-circle-x"
          @click="showRejectDialog = true"
        >
          Reject
        </VBtn>
      </div>
    </div>

    <VRow>
      <!-- ── Main: left 8 cols ──────────────────────────────────────────── -->
      <VCol cols="12" lg="8">
        <!-- Candidate Card -->
        <VCard
          v-if="application.candidate"
          elevation="0"
          border
          class="mb-4"
        >
          <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">
            Candidate
          </VCardTitle>
          <VCardText class="pa-5 pt-0">
            <div class="d-flex align-start gap-4 mb-4">
              <VAvatar size="56" color="primary" variant="tonal" class="flex-shrink-0">
                <span class="text-h5 font-weight-bold">
                  {{ (application.candidate.full_name ?? '?')[0].toUpperCase() }}
                </span>
              </VAvatar>
              <div class="flex-grow-1">
                <p class="text-body-1 font-weight-semibold mb-1">
                  {{ application.candidate.full_name }}
                </p>
                <div class="d-flex align-center gap-2 flex-wrap">
                  <div class="d-flex align-center gap-1">
                    <VIcon icon="tabler-mail" size="14" class="text-medium-emphasis" />
                    <span class="text-body-2 text-medium-emphasis">
                      {{ application.candidate.email }}
                    </span>
                  </div>
                  <div
                    v-if="application.candidate.phone"
                    class="d-flex align-center gap-1"
                  >
                    <VIcon icon="tabler-phone" size="14" class="text-medium-emphasis" />
                    <span class="text-body-2 text-medium-emphasis">
                      {{ application.candidate.phone }}
                    </span>
                  </div>
                </div>
              </div>
              <VBtn
                variant="tonal"
                size="small"
                @click="router.visit(route('recruitment.candidates.show', application.candidate.id))"
              >
                View Profile
              </VBtn>
            </div>

            <!-- AI Summary -->
            <div v-if="application.candidate.ai_profile?.summary">
              <VDivider class="mb-3" />
              <p class="text-body-2 font-weight-semibold mb-1">
                <VIcon icon="tabler-robot" size="16" color="info" class="me-1" />
                AI Summary
              </p>
              <p class="text-body-2 text-medium-emphasis mb-0" style="white-space: pre-wrap;">
                {{ application.candidate.ai_profile.summary }}
              </p>
            </div>
          </VCardText>
        </VCard>

        <!-- References Card -->
        <VCard elevation="0" border class="mb-4">
          <VCardTitle class="pa-5 pb-2 d-flex align-center justify-space-between">
            <span class="text-body-1 font-weight-semibold">
              Reference Checks
              <VBadge v-if="references.length" :content="references.length" color="primary" inline class="ms-2" />
            </span>
            <VBtn
              size="small"
              color="primary"
              variant="tonal"
              prepend-icon="tabler-plus"
              @click="showRefDialog = true"
            >
              Add Referee
            </VBtn>
          </VCardTitle>
          <VCardText class="pa-5 pt-0">
            <div v-if="references.length" class="d-flex flex-column gap-2">
              <div
                v-for="ref in references"
                :key="ref.id"
                class="d-flex align-start gap-3 pa-3 rounded-lg"
                style="border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));"
              >
                <VAvatar size="36" color="secondary" variant="tonal" class="flex-shrink-0 mt-1">
                  <span class="text-caption font-weight-bold">{{ ref.referee_name[0].toUpperCase() }}</span>
                </VAvatar>
                <div class="flex-grow-1">
                  <p class="text-body-2 font-weight-semibold mb-0">{{ ref.referee_name }}</p>
                  <p class="text-caption text-medium-emphasis mb-0">
                    {{ [ref.referee_title, ref.referee_company].filter(Boolean).join(' · ') || '—' }}
                  </p>
                  <p v-if="ref.relationship" class="text-caption text-medium-emphasis mb-0">{{ ref.relationship }}</p>
                  <p v-if="ref.response_notes" class="text-caption mt-1 mb-0" style="white-space: pre-wrap;">{{ ref.response_notes }}</p>
                </div>
                <div class="d-flex flex-column align-end gap-1 flex-shrink-0">
                  <VChip :color="refStatusColor(ref.status)" size="x-small" label variant="tonal" class="font-weight-medium text-capitalize">
                    {{ ref.status }}
                  </VChip>
                  <div class="d-flex gap-1">
                    <VBtn size="x-small" variant="text" icon="tabler-edit" @click="openRefUpdate(ref)" />
                    <VBtn size="x-small" variant="text" color="error" icon="tabler-trash" @click="deleteRef(ref.id)" />
                  </div>
                </div>
              </div>
            </div>
            <div v-else class="text-center py-6 text-medium-emphasis">
              <VIcon icon="tabler-address-book" size="40" class="mb-2 d-block mx-auto" />
              <p class="text-body-2 mb-0">No referees added yet.</p>
            </div>
          </VCardText>
        </VCard>

        <!-- Communications Card -->
        <VCard elevation="0" border class="mb-4">
          <VCardTitle class="pa-5 pb-2 d-flex align-center justify-space-between">
            <span class="text-body-1 font-weight-semibold">
              Communications
              <VBadge v-if="messages.length" :content="messages.length" color="primary" inline class="ms-2" />
            </span>
            <VBtn
              size="small"
              color="secondary"
              variant="tonal"
              prepend-icon="tabler-send"
              @click="showMsgDialog = true"
            >
              Add Note / Email
            </VBtn>
          </VCardTitle>
          <VCardText class="pa-5 pt-0">
            <div v-if="messages.length" class="d-flex flex-column gap-3">
              <div
                v-for="msg in [...messages].reverse()"
                :key="msg.id"
                class="d-flex gap-3"
              >
                <VAvatar size="32" :color="msgColor(msg.channel)" variant="tonal" class="flex-shrink-0 mt-1">
                  <VIcon :icon="msgIcon(msg.channel)" size="16" />
                </VAvatar>
                <div class="flex-grow-1">
                  <div class="d-flex align-center gap-2 mb-1">
                    <span class="text-body-2 font-weight-semibold">{{ msg.sender?.name ?? 'System' }}</span>
                    <VChip :color="msgColor(msg.channel)" size="x-small" label variant="tonal" class="font-weight-medium text-capitalize">
                      {{ msg.channel }}
                    </VChip>
                    <span class="text-caption text-medium-emphasis ms-auto">{{ formatDate(msg.sent_at) }}</span>
                  </div>
                  <p v-if="msg.subject" class="text-body-2 font-weight-medium mb-1">{{ msg.subject }}</p>
                  <p class="text-body-2 text-medium-emphasis mb-0" style="white-space: pre-wrap;">{{ msg.body }}</p>
                </div>
              </div>
            </div>
            <div v-else class="text-center py-6 text-medium-emphasis">
              <VIcon icon="tabler-message-circle" size="40" class="mb-2 d-block mx-auto" />
              <p class="text-body-2 mb-0">No messages or notes yet.</p>
            </div>
          </VCardText>
        </VCard>

        <!-- Interviews Card -->
        <VCard elevation="0" border>
          <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">
            Interviews
            <VBadge
              v-if="application.interviews?.length"
              :content="application.interviews.length"
              color="primary"
              inline
              class="ms-2"
            />
          </VCardTitle>
          <VCardText class="pa-5 pt-0">
            <div
              v-if="application.interviews?.length"
              class="d-flex flex-column gap-2"
            >
              <div
                v-for="(interview, idx) in application.interviews"
                :key="interview.id ?? idx"
                class="d-flex align-center justify-space-between pa-3 rounded-lg cursor-pointer"
                style="border: 1px solid rgba(0,0,0,0.08);"
                @click="router.visit(route('recruitment.jobs.applications.interviews.show', [job.id, application.id, (interview as any).id]))"
              >
                <div>
                  <p class="text-body-2 font-weight-medium mb-0">
                    {{ interview.type ?? 'Interview' }}
                  </p>
                  <p
                    v-if="interview.scheduled_at"
                    class="text-caption text-medium-emphasis mb-0"
                  >
                    {{ formatDate(interview.scheduled_at) }}
                  </p>
                </div>
                <VChip
                  :color="interview.status === 'completed' ? 'success' : interview.status === 'cancelled' ? 'error' : 'warning'"
                  size="small"
                  label
                  class="font-weight-medium text-capitalize"
                >
                  {{ interview.status ?? 'scheduled' }}
                </VChip>
              </div>
            </div>
            <div
              v-else
              class="text-center py-6 text-medium-emphasis"
            >
              <VIcon icon="tabler-calendar-clock" size="40" class="mb-2 d-block mx-auto" />
              <p class="text-body-2 mb-0">No interviews scheduled.</p>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- ── Sidebar: right 4 cols ──────────────────────────────────────── -->
      <VCol cols="12" lg="4">
        <div style="position: sticky; top: 80px;">
          <!-- Stage / Info card -->
          <VCard elevation="0" border class="mb-4">
            <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">
              Application Info
            </VCardTitle>
            <VCardText class="pa-5 pt-0">
              <div class="d-flex flex-column gap-3">
                <div class="d-flex align-center justify-space-between">
                  <span class="text-caption text-medium-emphasis">Current Stage</span>
                  <span class="text-body-2 font-weight-medium">
                    {{ application.current_stage?.name ?? '—' }}
                  </span>
                </div>
                <VDivider />
                <div class="d-flex align-center justify-space-between">
                  <span class="text-caption text-medium-emphasis">Source</span>
                  <VChip
                    v-if="application.source"
                    size="x-small"
                    variant="tonal"
                    color="default"
                    label
                  >
                    {{ application.source }}
                  </VChip>
                  <span v-else class="text-body-2">—</span>
                </div>
                <div
                  v-if="application.recruiter"
                  class="d-flex align-center justify-space-between"
                >
                  <VDivider />
                  <span class="text-caption text-medium-emphasis">Recruiter</span>
                  <span class="text-body-2">{{ application.recruiter.name }}</span>
                </div>
                <VDivider />
                <div class="d-flex align-center justify-space-between">
                  <span class="text-caption text-medium-emphasis">Applied</span>
                  <span class="text-body-2">{{ formatDate(application.applied_at) }}</span>
                </div>
                <div
                  v-if="application.rejected_at"
                  class="d-flex align-center justify-space-between"
                >
                  <span class="text-caption text-medium-emphasis">Rejected</span>
                  <span class="text-body-2">{{ formatDate(application.rejected_at) }}</span>
                </div>
                <div
                  v-if="application.withdrawn_at"
                  class="d-flex align-center justify-space-between"
                >
                  <span class="text-caption text-medium-emphasis">Withdrawn</span>
                  <span class="text-body-2">{{ formatDate(application.withdrawn_at) }}</span>
                </div>
              </div>
            </VCardText>
          </VCard>

          <!-- Fit Score card -->
          <VCard
            v-if="application.fit_score"
            elevation="0"
            border
          >
            <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">
              Fit Score
            </VCardTitle>
            <VCardText class="pa-5 pt-0">
              <!-- Overall -->
              <div class="text-center mb-4">
                <p class="text-h3 font-weight-bold text-primary mb-0">
                  {{ application.fit_score.overall }}%
                </p>
                <p class="text-caption text-medium-emphasis mb-0">
                  Overall Match
                </p>
              </div>

              <!-- Breakdown -->
              <div class="d-flex flex-column gap-3">
                <div>
                  <div class="d-flex justify-space-between mb-1">
                    <span class="text-body-2">Overall</span>
                    <span class="text-body-2 font-weight-medium">
                      {{ application.fit_score.overall }}%
                    </span>
                  </div>
                  <VProgressLinear
                    :model-value="application.fit_score.overall"
                    color="primary"
                    height="6"
                    rounded
                  />
                </div>
                <div>
                  <div class="d-flex justify-space-between mb-1">
                    <span class="text-body-2">Skills</span>
                    <span class="text-body-2 font-weight-medium">
                      {{ application.fit_score.skills }}%
                    </span>
                  </div>
                  <VProgressLinear
                    :model-value="application.fit_score.skills"
                    color="info"
                    height="6"
                    rounded
                  />
                </div>
                <div>
                  <div class="d-flex justify-space-between mb-1">
                    <span class="text-body-2">Experience</span>
                    <span class="text-body-2 font-weight-medium">
                      {{ application.fit_score.experience }}%
                    </span>
                  </div>
                  <VProgressLinear
                    :model-value="application.fit_score.experience"
                    color="success"
                    height="6"
                    rounded
                  />
                </div>
              </div>
            </VCardText>
          </VCard>
        </div>
      </VCol>
    </VRow>

    <!-- Add Referee Dialog -->
    <VDialog v-model="showRefDialog" max-width="560">
      <VCard>
        <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">Add Referee</VCardTitle>
        <VCardText class="pa-5 pt-0">
          <VRow dense>
            <VCol cols="12" md="6">
              <VTextField v-model="refForm.referee_name" label="Referee Name *" variant="outlined" density="comfortable" :error-messages="refForm.errors.referee_name" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="refForm.relationship" label="Relationship" variant="outlined" density="comfortable" placeholder="e.g. Direct Manager" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="refForm.referee_company" label="Company" variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="refForm.referee_title" label="Job Title" variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="refForm.referee_email" label="Email" type="email" variant="outlined" density="comfortable" :error-messages="refForm.errors.referee_email" />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField v-model="refForm.referee_phone" label="Phone" variant="outlined" density="comfortable" />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2">
          <VSpacer />
          <VBtn variant="text" @click="showRefDialog = false">Cancel</VBtn>
          <VBtn color="primary" :loading="refForm.processing" :disabled="!refForm.referee_name" @click="submitRef">Add</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Update Reference Dialog -->
    <VDialog v-model="showRefUpdateDialog" max-width="480">
      <VCard>
        <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">Update Reference — {{ selectedRef?.referee_name }}</VCardTitle>
        <VCardText class="pa-5 pt-0">
          <VSelect
            v-model="refUpdateForm.status"
            :items="[{value:'pending',title:'Pending'},{value:'contacted',title:'Contacted'},{value:'received',title:'Received'}]"
            item-title="title" item-value="value"
            label="Status" variant="outlined" density="comfortable" class="mb-3"
          />
          <VTextarea v-model="refUpdateForm.response_notes" label="Response Notes" rows="4" variant="outlined" placeholder="Notes from the reference call..." />
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2">
          <VSpacer />
          <VBtn variant="text" @click="showRefUpdateDialog = false">Cancel</VBtn>
          <VBtn color="primary" :loading="refUpdateForm.processing" @click="submitRefUpdate">Save</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Add Message / Note Dialog -->
    <VDialog v-model="showMsgDialog" max-width="560">
      <VCard>
        <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">Add Note / Email</VCardTitle>
        <VCardText class="pa-5 pt-0">
          <VBtnToggle v-model="msgForm.channel" mandatory density="comfortable" class="mb-4">
            <VBtn value="note" variant="tonal" size="small" prepend-icon="tabler-note">Internal Note</VBtn>
            <VBtn value="email" variant="tonal" size="small" prepend-icon="tabler-mail">Email</VBtn>
          </VBtnToggle>
          <VTextField
            v-if="msgForm.channel === 'email'"
            v-model="msgForm.subject"
            label="Subject"
            variant="outlined"
            density="comfortable"
            class="mb-3"
          />
          <VTextarea
            v-model="msgForm.body"
            :label="msgForm.channel === 'note' ? 'Note' : 'Message Body'"
            rows="5"
            variant="outlined"
            :error-messages="msgForm.errors.body"
          />
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2">
          <VSpacer />
          <VBtn variant="text" @click="showMsgDialog = false">Cancel</VBtn>
          <VBtn color="secondary" :loading="msgForm.processing" :disabled="!msgForm.body" @click="submitMsg">
            {{ msgForm.channel === 'note' ? 'Save Note' : 'Send Email' }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Move Stage Dialog -->
    <VDialog v-model="showMoveDialog" max-width="520">
      <VCard>
        <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">
          Move Application Stage
        </VCardTitle>
        <VCardText class="pa-5 pt-0">
          <p class="text-body-2 text-medium-emphasis mb-4">
            Move <strong>{{ application.candidate?.full_name }}</strong> to a new stage in the pipeline.
          </p>
          <VSelect
            v-model="moveForm.status"
            :items="statusOptions"
            item-title="label"
            item-value="value"
            label="New Status"
            variant="outlined"
            density="comfortable"
            class="mb-3"
            :error-messages="moveForm.errors.status"
          />
          <VTextField
            v-model="moveForm.reason"
            label="Reason (optional)"
            variant="outlined"
            density="comfortable"
            placeholder="e.g. Passed technical assessment — moving to interview stage"
            :error-messages="moveForm.errors.reason"
          />
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2">
          <VSpacer />
          <VBtn variant="text" @click="showMoveDialog = false">Cancel</VBtn>
          <VBtn
            color="primary"
            :loading="moveForm.processing"
            :disabled="!moveForm.status"
            @click="submitMove"
          >
            Move
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Schedule Interview Dialog -->
    <VDialog v-model="showScheduleDialog" max-width="600">
      <VCard>
        <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">
          Schedule Interview
        </VCardTitle>
        <VCardText class="pa-5 pt-0">
          <VRow dense>
            <VCol cols="12" md="6">
              <VSelect
                v-model="scheduleForm.type"
                :items="interviewTypes"
                item-title="label"
                item-value="value"
                label="Interview Type"
                variant="outlined"
                density="comfortable"
                :error-messages="scheduleForm.errors.type"
              />
            </VCol>
            <VCol cols="12">
              <VAutocomplete
                v-model="scheduleForm.interviewer_id"
                :items="props.interviewers"
                item-title="name"
                item-value="id"
                label="Interviewer *"
                variant="outlined"
                density="comfortable"
                :error-messages="scheduleForm.errors.interviewer_id"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model.number="scheduleForm.duration_minutes"
                label="Duration (minutes)"
                type="number"
                variant="outlined"
                density="comfortable"
                :error-messages="scheduleForm.errors.duration_minutes"
              />
            </VCol>
            <VCol cols="12">
              <VTextField
                v-model="scheduleForm.scheduled_at"
                label="Date & Time"
                type="datetime-local"
                variant="outlined"
                density="comfortable"
                :error-messages="scheduleForm.errors.scheduled_at"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="scheduleForm.location"
                label="Location (optional)"
                variant="outlined"
                density="comfortable"
                placeholder="e.g. Boardroom A"
                :error-messages="scheduleForm.errors.location"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="scheduleForm.meeting_url"
                label="Meeting URL (optional)"
                variant="outlined"
                density="comfortable"
                placeholder="https://meet.google.com/..."
                :error-messages="scheduleForm.errors.meeting_url"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="scheduleForm.notes"
                label="Notes (optional)"
                variant="outlined"
                density="comfortable"
                rows="3"
                :error-messages="scheduleForm.errors.notes"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2">
          <VSpacer />
          <VBtn variant="text" @click="showScheduleDialog = false">Cancel</VBtn>
          <VBtn
            color="info"
            :loading="scheduleForm.processing"
            :disabled="!scheduleForm.scheduled_at || !scheduleForm.interviewer_id"
            @click="submitSchedule"
          >
            Schedule
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Reject Dialog -->
    <VDialog v-model="showRejectDialog" max-width="480">
      <VCard>
        <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">
          Reject Application
        </VCardTitle>
        <VCardText class="pa-5 pt-0">
          <p class="text-body-2 text-medium-emphasis mb-4">
            Please provide a reason for rejecting
            <strong>{{ application.candidate?.full_name ?? 'this application' }}</strong>.
            This will be recorded for compliance purposes.
          </p>
          <VTextarea
            v-model="rejectForm.reason"
            label="Reason for Rejection"
            rows="4"
            variant="outlined"
            placeholder="e.g. Candidate does not meet minimum experience requirements..."
            :error-messages="rejectForm.errors.reason"
          />
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2">
          <VSpacer />
          <VBtn
            variant="text"
            @click="showRejectDialog = false"
          >
            Cancel
          </VBtn>
          <VBtn
            color="error"
            :loading="rejectForm.processing"
            @click="submitReject"
          >
            Confirm Reject
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
