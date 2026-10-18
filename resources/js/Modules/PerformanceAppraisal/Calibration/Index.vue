<template>
  <VContainer fluid class="pa-6">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('pa.calibration.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">{{ t('pa.calibration.subtitle') }}</p>
      </div>
      <div class="d-flex ga-2">
        <VBtn variant="tonal" prepend-icon="tabler-git-branch" @click="workflowOpen = true">{{ t('pa.calibration.new_workflow') }}</VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="sessionOpen = true">{{ t('pa.calibration.new_session') }}</VBtn>
      </div>
    </div>

    <!-- Stats strip -->
    <VRow dense class="mb-5">
      <VCol cols="6" sm="3">
        <VCard border flat class="pa-3 text-center">
          <p class="text-h5 font-weight-bold text-primary">{{ stats.sessions }}</p>
          <p class="text-caption text-medium-emphasis">{{ t('pa.calibration.active_sessions') }}</p>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard border flat class="pa-3 text-center">
          <p class="text-h5 font-weight-bold text-blue">{{ stats.active_sessions }}</p>
          <p class="text-caption text-medium-emphasis">{{ t('performance_calibration.in_progress') }}</p>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard border flat class="pa-3 text-center">
          <p class="text-h5 font-weight-bold text-green">{{ stats.workflows }}</p>
          <p class="text-caption text-medium-emphasis">{{ t('pa.calibration.workflows') }}</p>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard border flat class="pa-3 text-center">
          <p class="text-h5 font-weight-bold text-orange">{{ stats.pending_approvals }}</p>
          <p class="text-caption text-medium-emphasis">{{ t('pa.calibration.pending_approvals') }}</p>
        </VCard>
      </VCol>
    </VRow>

    <VTabs v-model="tab" class="mb-4">
      <VTab value="sessions">
        <VIcon start>tabler-scale</VIcon>{{ t('performance_calibration.tabs.sessions') }} ({{ sessions.length }})
      </VTab>
      <VTab value="workflows">
        <VIcon start>tabler-git-branch</VIcon>{{ t('performance_calibration.tabs.workflows') }} ({{ workflows.length }})
      </VTab>
      <VTab value="requests">
        <VIcon start>tabler-clipboard-check</VIcon>{{ t('performance_calibration.tabs.requests') }}
        <VBadge v-if="pendingRequests.length" :content="pendingRequests.length" color="orange" inline class="ml-1" />
      </VTab>
    </VTabs>

    <VTabsWindow v-model="tab">

      <!-- ── Calibration Sessions ─────────────────────────────────────────── -->
      <VTabsWindowItem value="sessions">
        <VList v-if="sessions.length" class="pa-0">
          <VListItem
            v-for="s in sessions"
            :key="s.id"
            class="mb-2 border"
          >
            <template #prepend>
              <VAvatar :color="sessionStatusColor(s.status)" variant="tonal" size="40">
                <VIcon size="18">tabler-scale</VIcon>
              </VAvatar>
            </template>
            <template #title>
              <span class="text-body-2 font-weight-medium">{{ s.title }}</span>
            </template>
            <template #subtitle>
              <span class="text-caption">
                {{ s.facilitator_name }} · {{ s.scheduled_date }}
                · {{ t('performance_calibration.sessions.participants_count', { count: s.participant_count }) }} ({{ t('performance_calibration.sessions.participants_finalized', { finalized: s.finalized_count }) }})
              </span>
            </template>
            <template #append>
              <div class="d-flex align-center ga-2">
                <VChip size="x-small" :color="sessionStatusColor(s.status)" variant="tonal">{{ s.status }}</VChip>
                <VBtn size="x-small" variant="tonal" @click="openManageSession(s)">{{ t('performance_calibration.sessions.manage') }}</VBtn>
                <VBtn
                  v-if="['draft','in_progress'].includes(s.status)"
                  size="x-small"
                  variant="tonal"
                  color="success"
                  @click="finalizeSession(s.id)"
                >
                  {{ t('performance_calibration.sessions.finalize') }}
                </VBtn>
                <VBtn size="x-small" icon variant="text" color="error" @click="deleteSession(s.id)">
                  <VIcon>tabler-trash</VIcon>
                </VBtn>
              </div>
            </template>
          </VListItem>
        </VList>
        <VAlert v-else type="info" variant="tonal">{{ t('performance_calibration.sessions.no_sessions') }}</VAlert>
      </VTabsWindowItem>

      <!-- ── Approval Workflows ───────────────────────────────────────────── -->
      <VTabsWindowItem value="workflows">
        <div class="d-flex justify-end mb-3">
          <VBtn size="small" variant="tonal" prepend-icon="tabler-send" @click="reqOpen = true">
            {{ t('performance_calibration.workflows.submit_request') }}
          </VBtn>
        </div>
        <VList v-if="workflows.length" class="pa-0">
          <VListItem
            v-for="w in workflows"
            :key="w.id"
            class="mb-2 border"
          >
            <template #prepend>
              <VAvatar :color="w.is_active ? 'green' : 'grey'" variant="tonal" size="38">
                <VIcon size="16">tabler-git-branch</VIcon>
              </VAvatar>
            </template>
            <template #title>
              <span class="text-body-2 font-weight-medium">{{ w.name }}</span>
              <VChip v-if="w.resource_type" size="x-small" color="blue" variant="tonal" class="ml-2">{{ w.resource_type }}</VChip>
            </template>
            <template #subtitle>
              <span class="text-caption text-medium-emphasis">{{ t('performance_calibration.workflows.steps_count', { count: w.steps_count }) }} · {{ w.description ?? t('performance_calibration.workflows.no_description') }}</span>
            </template>
            <template #append>
              <div class="d-flex align-center ga-2">
                <VSwitch
                  :model-value="w.is_active"
                  color="primary"
                  density="compact"
                  hide-details
                  @update:model-value="toggleWorkflow(w.id)"
                />
                <VBtn size="x-small" icon variant="text" color="error" @click="deleteWorkflow(w.id)">
                  <VIcon>tabler-trash</VIcon>
                </VBtn>
              </div>
            </template>
          </VListItem>
        </VList>
        <VAlert v-else type="info" variant="tonal">{{ t('performance_calibration.workflows.no_workflows') }}</VAlert>
      </VTabsWindowItem>

      <!-- ── Approval Requests ────────────────────────────────────────────── -->
      <VTabsWindowItem value="requests">
        <VList v-if="requests.length" class="pa-0">
          <VListItem
            v-for="r in requests"
            :key="r.id"
            class="mb-2 border"
          >
            <template #prepend>
              <VAvatar :color="requestStatusColor(r.status)" variant="tonal" size="40">
                <span class="text-caption font-weight-bold">{{ r.current_step }}/{{ r.total_steps }}</span>
              </VAvatar>
            </template>
            <template #title>
              <span class="text-body-2 font-weight-medium">{{ r.title }}</span>
            </template>
            <template #subtitle>
              <span class="text-caption">{{ r.submitted_by_name }} · {{ r.workflow_name }}</span>
            </template>
            <template #append>
              <div class="d-flex align-center ga-2">
                <VChip size="x-small" :color="requestStatusColor(r.status)" variant="flat">{{ r.status }}</VChip>
                <template v-if="['pending','in_progress'].includes(r.status)">
                  <VBtn size="x-small" variant="tonal" color="success" @click="approveRequest(r.id)">{{ t('performance_calibration.requests.approve') }}</VBtn>
                  <VBtn size="x-small" variant="tonal" color="error" @click="openReject(r)">{{ t('performance_calibration.requests.reject') }}</VBtn>
                </template>
                <VBtn size="x-small" icon variant="text" color="error" @click="deleteRequest(r.id)">
                  <VIcon>tabler-trash</VIcon>
                </VBtn>
              </div>
            </template>
            <!-- Progress bar -->
            <template #default>
              <VProgressLinear
                :model-value="r.progress_pct"
                :color="requestStatusColor(r.status)"
                height="3"
                rounded
                class="mt-1"
              />
            </template>
          </VListItem>
        </VList>
        <VAlert v-else type="success" variant="tonal">{{ t('performance_calibration.requests.no_requests') }}</VAlert>
      </VTabsWindowItem>

    </VTabsWindow>

    <!-- New Calibration Session dialog -->
    <VDialog v-model="sessionOpen" max-width="480">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold pa-4">{{ t('pa.calibration.new_session') }}</VCardTitle>
        <VCardText>
          <VSelect v-model="sForm.cycle_id" :items="cycles" item-title="name" item-value="id" :label="t('pa.common.cycle')" density="compact" variant="outlined" class="mb-3" />
          <VTextField v-model="sForm.title" :label="t('pa.calibration.session_title')" density="compact" variant="outlined" class="mb-3" />
          <VSelect v-model="sForm.facilitator_id" :items="employees" item-title="name" item-value="id" :label="t('pa.calibration.facilitator')" density="compact" variant="outlined" class="mb-3" />
          <VTextField v-model="sForm.scheduled_date" :label="t('pa.calibration.scheduled_date')" type="date" density="compact" variant="outlined" class="mb-3" />
          <VTextarea v-model="sForm.notes" :label="t('pa.calibration.notes')" density="compact" variant="outlined" rows="2" />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="sessionOpen = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="savingSession" :disabled="!sForm.title || !sForm.cycle_id" @click="submitSession">
            {{ t('pa.common.create') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Manage Session dialog -->
    <VDialog v-model="manageOpen" max-width="640" scrollable>
      <VCard>
        <VToolbar color="secondary" density="compact">
          <VToolbarTitle class="text-body-1">{{ t('performance_calibration.manage_session.title', { title: managingSession?.title }) }}</VToolbarTitle>
          <template #append><VBtn icon @click="manageOpen = false"><VIcon>tabler-x</VIcon></VBtn></template>
        </VToolbar>
        <VCardText class="pa-4">
          <!-- Add participant -->
          <div class="d-flex ga-2 mb-4">
            <VSelect
              v-model="participantForm.employee_id"
              :items="employees"
              item-title="name"
              item-value="id"
              :label="t('performance_calibration.manage_session.add_employee')"
              density="compact"
              variant="outlined"
              hide-details
              class="flex-1-1"
            />
            <VTextField v-model.number="participantForm.original_score" :label="t('performance_calibration.manage_session.original')" type="number" density="compact" variant="outlined" hide-details style="max-width: 100px" />
            <VTextField v-model.number="participantForm.calibrated_score" :label="t('performance_calibration.manage_session.calibrated')" type="number" density="compact" variant="outlined" hide-details style="max-width: 110px" />
            <VBtn variant="tonal" :loading="addingParticipant" :disabled="!participantForm.employee_id" @click="addParticipant">{{ t('performance_calibration.manage_session.add') }}</VBtn>
          </div>

          <!-- Participants list -->
          <div v-if="sessionParticipants.length">
            <VCard
              v-for="p in sessionParticipants"
              :key="p.id"
              border
              flat
              class="mb-2 pa-3"
            >
              <div class="d-flex align-center ga-3">
                <span class="text-body-2 font-weight-medium flex-1-1">{{ p.employee_name }}</span>
                <VTextField
                  v-model.number="p.calibrated_score_edit"
                  :label="t('performance_calibration.manage_session.calibrated')"
                  type="number"
                  density="compact"
                  variant="outlined"
                  hide-details
                  style="max-width: 110px"
                />
                <VBtn size="x-small" variant="tonal" @click="saveParticipant(p)">{{ t('performance_calibration.manage_session.save') }}</VBtn>
                <VChip
                  v-if="p.calibrated_score !== null && p.original_score !== null"
                  size="x-small"
                  :color="(p.calibrated_score - p.original_score) > 0 ? 'green' : (p.calibrated_score - p.original_score) < 0 ? 'error' : 'grey'"
                  variant="tonal"
                >
                  {{ (p.calibrated_score - p.original_score) > 0 ? '+' : '' }}{{ (p.calibrated_score - p.original_score).toFixed(1) }}
                </VChip>
                <VIcon v-if="p.finalized" color="success" size="18">tabler-circle-check</VIcon>
                <VBtn size="x-small" icon variant="text" color="error" @click="removeParticipant(p.id)">
                  <VIcon size="16">tabler-trash</VIcon>
                </VBtn>
              </div>
            </VCard>
          </div>
          <VAlert v-else type="info" variant="tonal" density="compact">{{ t('performance_calibration.manage_session.empty') }}</VAlert>
        </VCardText>
      </VCard>
    </VDialog>

    <!-- New Approval Workflow drawer -->
    <VNavigationDrawer v-model="workflowOpen" location="right" width="500" temporary>
      <VToolbar color="primary" density="compact">
        <VToolbarTitle class="text-body-1">{{ t('pa.calibration.new_workflow') }}</VToolbarTitle>
        <template #append><VBtn icon @click="workflowOpen = false"><VIcon>tabler-x</VIcon></VBtn></template>
      </VToolbar>
      <div class="pa-4">
        <VTextField v-model="wForm.name" :label="t('pa.calibration.workflow_name')" density="compact" variant="outlined" class="mb-3" />
        <VTextField v-model="wForm.resource_type" :label="t('pa.calibration.resource_type')" density="compact" variant="outlined" :hint="t('pa.calibration.resource_type_hint')" class="mb-3" />
        <VTextarea v-model="wForm.description" :label="t('pa.calibration.description')" density="compact" variant="outlined" rows="2" class="mb-4" />

        <p class="text-body-2 font-weight-medium mb-2">{{ t('performance_calibration.workflows.steps_title') }}</p>
        <div v-for="(step, idx) in wForm.steps" :key="idx" class="d-flex ga-2 mb-2 align-start">
          <span class="text-caption mt-3">{{ idx + 1 }}.</span>
          <VTextField v-model="step.step_label" :label="t('performance_calibration.workflows.step_label', { n: idx + 1 })" density="compact" variant="outlined" hide-details />
          <VSelect v-model="step.approver_id" :items="employees" item-title="name" item-value="id" :label="t('performance_calibration.workflows.approver')" density="compact" variant="outlined" clearable hide-details style="max-width: 180px" />
          <VBtn size="x-small" icon variant="text" color="error" @click="removeStep(idx)">
            <VIcon>tabler-trash</VIcon>
          </VBtn>
        </div>
        <VBtn size="small" variant="tonal" prepend-icon="tabler-plus" class="mb-4" @click="addStep">{{ t('performance_calibration.workflows.add_step') }}</VBtn>

        <VBtn color="primary" block :loading="savingWorkflow" :disabled="!wForm.name" @click="submitWorkflow">
          {{ t('performance_calibration.workflows.create_workflow') }}
        </VBtn>
      </div>
    </VNavigationDrawer>

    <!-- Submit Approval Request dialog -->
    <VDialog v-model="reqOpen" max-width="440">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold pa-4">{{ t('pa.calibration.new_workflow') }}</VCardTitle>
        <VCardText>
          <VSelect v-model="reqForm.workflow_id" :items="workflows.filter(w => w.is_active)" item-title="name" item-value="id" :label="t('pa.calibration.workflow_name')" density="compact" variant="outlined" class="mb-3" />
          <VTextField v-model="reqForm.title" :label="t('pa.calibration.request_title')" density="compact" variant="outlined" class="mb-3" />
          <VTextField v-model="reqForm.resource_type" :label="t('pa.calibration.resource_type')" density="compact" variant="outlined" class="mb-3" />
          <VTextField v-model.number="reqForm.resource_id" :label="t('pa.calibration.resource_id')" type="number" density="compact" variant="outlined" />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="reqOpen = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="submittingReq" :disabled="!reqForm.workflow_id || !reqForm.title" @click="submitRequest">
            {{ t('pa.common.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Reject dialog -->
    <VDialog v-model="rejectOpen" max-width="400">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold pa-4">{{ t('pa.calibration.rejection_reason') }}</VCardTitle>
        <VCardText>
          <VTextarea v-model="rejectNotes" :label="t('pa.calibration.rejection_reason')" density="compact" variant="outlined" rows="3" />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="rejectOpen = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="error" variant="flat" :loading="rejecting" @click="confirmReject">{{ t('pa.common.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

  </VContainer>
</template>

<script setup lang="ts">
import { ref, reactive, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'

const { t } = useI18n()

interface CalibrationSession {
  id: number; title: string; facilitator_name: string
  scheduled_date: string; status: string
  participant_count: number; finalized_count: number
}
interface Workflow { id: number; name: string; resource_type: string | null; description: string | null; is_active: boolean; steps_count: number }
interface ApprovalRequest {
  id: number; title: string; submitted_by_name: string; workflow_name: string
  current_step: number; total_steps: number; status: string; progress_pct: number
}
interface Participant {
  id: number; employee_id: number; employee_name: string
  original_score: number | null; calibrated_score: number | null
  calibrated_score_edit: number | null; adjustment_reason: string | null; finalized: boolean
}
interface Cycle    { id: number; name: string }
interface Employee { id: number; name: string }

const props = defineProps<{
  sessions: CalibrationSession[]; workflows: Workflow[]; requests: ApprovalRequest[]
  cycles: Cycle[]; employees: Employee[]
  stats: { sessions: number; active_sessions: number; workflows: number; pending_approvals: number }
}>()

const tab = ref('sessions')

const pendingRequests = computed(() => props.requests.filter(r => ['pending', 'in_progress'].includes(r.status)))

// ── Sessions ──────────────────────────────────────────────────────────────────
const sessionOpen   = ref(false)
const savingSession = ref(false)
const sForm         = reactive({ cycle_id: null as number | null, title: '', facilitator_id: null as number | null, scheduled_date: '', notes: '' })

function submitSession() {
  savingSession.value = true
  router.post(route('performance.calibration.sessions.store'), sForm as any, {
    onSuccess: () => { sessionOpen.value = false },
    onFinish:  () => { savingSession.value = false },
  })
}

function finalizeSession(id: number) {
  router.patch(route('performance.calibration.sessions.finalize', id))
}

function deleteSession(id: number) {
  router.delete(route('performance.calibration.sessions.destroy', id))
}

// ── Manage Session dialog ─────────────────────────────────────────────────────
const manageOpen          = ref(false)
const managingSession     = ref<CalibrationSession | null>(null)
const sessionParticipants = ref<Participant[]>([])
const participantForm     = reactive({ employee_id: null as number | null, original_score: null as number | null, calibrated_score: null as number | null })
const addingParticipant   = ref(false)

async function openManageSession(s: CalibrationSession) {
  managingSession.value = s
  manageOpen.value = true
  const res  = await fetch(route('performance.calibration.sessions.summary', s.id))
  const data = await res.json()
  sessionParticipants.value = (data.participants ?? []).map((p: any) => ({
    ...p, calibrated_score_edit: p.calibrated_score,
  }))
}

function addParticipant() {
  if (!managingSession.value) return
  addingParticipant.value = true
  router.post(route('performance.calibration.participants.store', managingSession.value.id), participantForm as any, {
    onSuccess: () => { openManageSession(managingSession.value!) },
    onFinish:  () => { addingParticipant.value = false },
  })
}

function saveParticipant(p: Participant) {
  router.patch(route('performance.calibration.participants.update', p.id), {
    calibrated_score: p.calibrated_score_edit,
  }, {
    onSuccess: () => { openManageSession(managingSession.value!) },
  })
}

function removeParticipant(id: number) {
  router.delete(route('performance.calibration.participants.destroy', id), {
    onSuccess: () => { openManageSession(managingSession.value!) },
  })
}

// ── Workflows ─────────────────────────────────────────────────────────────────
const workflowOpen   = ref(false)
const savingWorkflow = ref(false)
const wForm          = reactive<{
  name: string; resource_type: string; description: string
  steps: Array<{ step_label: string; approver_id: number | null; is_required: boolean }>
}>({ name: '', resource_type: '', description: '', steps: [] })

function addStep() {
  wForm.steps.push({ step_label: '', approver_id: null, is_required: true })
}
function removeStep(idx: number) {
  wForm.steps.splice(idx, 1)
}

function submitWorkflow() {
  savingWorkflow.value = true
  router.post(route('performance.calibration.workflows.store'), wForm as any, {
    onSuccess: () => { workflowOpen.value = false; wForm.steps = [] },
    onFinish:  () => { savingWorkflow.value = false },
  })
}

function toggleWorkflow(id: number) {
  router.patch(route('performance.calibration.workflows.toggle', id))
}

function deleteWorkflow(id: number) {
  router.delete(route('performance.calibration.workflows.destroy', id))
}

// ── Approval Requests ─────────────────────────────────────────────────────────
const reqOpen       = ref(false)
const submittingReq = ref(false)
const reqForm       = reactive({ workflow_id: null as number | null, title: '', resource_type: '', resource_id: null as number | null })

function submitRequest() {
  submittingReq.value = true
  router.post(route('performance.calibration.requests.store'), reqForm as any, {
    onSuccess: () => { reqOpen.value = false },
    onFinish:  () => { submittingReq.value = false },
  })
}

function approveRequest(id: number) {
  router.post(route('performance.calibration.requests.action', id), { action: 'approved' })
}

const rejectOpen   = ref(false)
const rejecting    = ref(false)
const rejectTarget = ref<ApprovalRequest | null>(null)
const rejectNotes  = ref('')

function openReject(r: ApprovalRequest) {
  rejectTarget.value = r
  rejectNotes.value  = ''
  rejectOpen.value   = true
}

function confirmReject() {
  if (!rejectTarget.value) return
  rejecting.value = true
  router.post(route('performance.calibration.requests.action', rejectTarget.value.id), {
    action: 'rejected', notes: rejectNotes.value,
  }, {
    onSuccess: () => { rejectOpen.value = false },
    onFinish:  () => { rejecting.value = false },
  })
}

function deleteRequest(id: number) {
  router.delete(route('performance.calibration.requests.destroy', id))
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function sessionStatusColor(s: string): string {
  return ({ draft: 'grey', in_progress: 'blue', completed: 'green', cancelled: 'error' } as Record<string,string>)[s] ?? 'grey'
}
function requestStatusColor(s: string): string {
  return ({ pending: 'grey', in_progress: 'blue', approved: 'green', rejected: 'error', recalled: 'orange' } as Record<string,string>)[s] ?? 'grey'
}
</script>
