<script setup lang="ts">
/**
 * Where a document is in its approval, and what this person can do about it.
 *
 * Shown as a vertical timeline rather than a status field, because the question somebody actually
 * has is "what is holding this up" — and that is answered by seeing which step is waiting and who
 * is in it, not by the word "under_review".
 *
 * Every state carries an icon and a word as well as a colour. Colour alone excludes anyone who
 * cannot distinguish red from green, and approve/reject is exactly the pair that breaks.
 */
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const d = (key: string, a?: Record<string, unknown>) => t('document_management.' + key, a ?? {})

export interface ApprovalStep {
  uuid: string
  position: number
  name: string
  status: string
  assigned_to: string | null
  delegated_to: string | null
  delegation_reason: string | null
  decided_by: string | null
  decided_at: string | null
  comment: string | null
  due_at: string | null
  is_overdue: boolean
  is_current: boolean
  can_decide: boolean
}

export interface Approval {
  uuid: string
  status: string
  workflow: string | null
  submitted_by: string | null
  submitted_at: string | null
  completed_at: string | null
  outcome_note: string | null
  is_open: boolean
  steps: ApprovalStep[]
}

const props = defineProps<{
  approval: Approval | null
  approvalStatus: string
  documentUuid: string
  workflows: { uuid: string; name: string }[]
  canManage: boolean
  people: { id: number; name: string }[]
}>()

/**
 * Steps grouped by position, which is what makes the parallel ones read as parallel.
 *
 * Two steps at the same position are drawn as one stage containing both, so the shape on screen
 * matches the shape of the workflow rather than presenting a branch as a queue.
 */
const stages = computed(() => {
  const groups = new Map<number, ApprovalStep[]>()

  for (const step of props.approval?.steps ?? []) {
    groups.set(step.position, [...(groups.get(step.position) ?? []), step])
  }

  return [...groups.entries()].sort(([a], [b]) => a - b).map(([position, steps]) => ({ position, steps }))
})

/** Icon, colour and word together — never colour on its own. */
const stepLook = (step: ApprovalStep) => {
  if (step.status === 'approved') return { icon: 'mdi-check-circle', colour: 'success', label: d('approval_approved') }
  if (step.status === 'rejected') return { icon: 'mdi-close-circle', colour: 'error', label: d('approval_rejected') }
  if (step.status === 'changes_requested') return { icon: 'mdi-pencil-circle', colour: 'warning', label: d('approval_changes_requested') }
  if (step.status === 'skipped') return { icon: 'mdi-minus-circle-outline', colour: undefined, label: d('approval_skipped') }
  if (step.is_overdue) return { icon: 'mdi-alert-circle', colour: 'error', label: d('approval_overdue') }
  if (step.is_current) return { icon: 'mdi-progress-clock', colour: 'primary', label: d('approval_waiting') }
  return { icon: 'mdi-circle-outline', colour: undefined, label: d('approval_pending') }
}

const documentLook = computed(() => ({
  draft: { icon: 'mdi-file-outline', colour: undefined },
  submitted: { icon: 'mdi-send-clock-outline', colour: 'info' },
  under_review: { icon: 'mdi-progress-clock', colour: 'primary' },
  changes_requested: { icon: 'mdi-pencil-circle', colour: 'warning' },
  approved: { icon: 'mdi-check-circle', colour: 'success' },
  rejected: { icon: 'mdi-close-circle', colour: 'error' },
  published: { icon: 'mdi-publish', colour: 'success' },
}[props.approvalStatus] ?? { icon: 'mdi-file-outline', colour: undefined }))

// ── Acting ──────────────────────────────────────────────────────────────────

const deciding = ref<{ step: ApprovalStep; decision: string } | null>(null)
const delegating = ref<ApprovalStep | null>(null)
const submitting = ref(false)

const comment = ref('')
const delegateTo = ref<number | null>(null)
const delegateReason = ref('')

// A spinner on the button that was pressed, because an approval that appears to do nothing gets
// pressed again.
const busy = ref(false)

const workflow = ref<string | null>(null)
const submitNote = ref('')

function openDecision(step: ApprovalStep, decision: string) {
  deciding.value = { step, decision }
  comment.value = ''
}

/** Refusing without saying why leaves the author guessing, so the field is required for those. */
const commentRequired = computed(() => deciding.value !== null && deciding.value.decision !== 'approved')

function decide() {
  if (!deciding.value) return
  if (commentRequired.value && !comment.value.trim()) return

  busy.value = true
  router.post(route('document-management.approvals.decide', deciding.value.step.uuid), {
    decision: deciding.value.decision,
    comment: comment.value,
  }, {
    preserveScroll: true,
    onFinish: () => { busy.value = false; deciding.value = null; comment.value = '' },
  })
}

function delegate() {
  if (!delegating.value || !delegateTo.value) return

  busy.value = true
  router.post(route('document-management.approvals.delegate', delegating.value.uuid), {
    to: delegateTo.value,
    reason: delegateReason.value,
  }, {
    preserveScroll: true,
    onFinish: () => { busy.value = false; delegating.value = null; delegateTo.value = null; delegateReason.value = '' },
  })
}

function submitForApproval() {
  busy.value = true
  router.post(route('document-management.documents.submit', props.documentUuid), {
    workflow: workflow.value, note: submitNote.value,
  }, {
    preserveScroll: true,
    onFinish: () => { busy.value = false; submitting.value = false; submitNote.value = '' },
  })
}

function publish() {
  busy.value = true
  router.post(route('document-management.documents.publish', props.documentUuid), {}, {
    preserveScroll: true,
    onFinish: () => { busy.value = false },
  })
}

function cancel() {
  if (!props.approval) return

  busy.value = true
  router.post(route('document-management.approvals.cancel', props.approval.uuid), {}, {
    preserveScroll: true,
    onFinish: () => { busy.value = false },
  })
}

const decisionTitle = computed(() => ({
  approved: d('approve'),
  rejected: d('reject'),
  changes_requested: d('request_changes'),
}[deciding.value?.decision ?? 'approved']))
</script>

<template>
  <VCard elevation="0" border>
    <VCardText class="d-flex align-center ga-2 pb-2">
      <VIcon size="20" icon="mdi-clipboard-check-outline" />
      <span class="text-body-2 font-weight-medium">{{ d('approval') }}</span>
      <VSpacer />
      <!-- Icon and word, not colour alone. -->
      <VChip size="small" :color="documentLook.colour" variant="tonal" :prepend-icon="documentLook.icon">
        {{ d('approval_' + approvalStatus) }}
      </VChip>
    </VCardText>

    <VDivider />

    <!-- Nothing started yet -->
    <VCardText v-if="!approval">
      <p class="text-body-2 text-medium-emphasis mb-3">{{ d('approval_none') }}</p>
      <VBtn
        v-if="canManage" color="primary" variant="tonal" size="small"
        prepend-icon="mdi-send-outline" @click="submitting = true"
      >{{ d('submit_for_approval') }}</VBtn>
    </VCardText>

    <template v-else>
      <VCardText class="pb-0">
        <div class="text-caption text-medium-emphasis">
          <span v-if="approval.workflow">{{ approval.workflow }} · </span>
          {{ d('approval_submitted_by', { user: approval.submitted_by, at: approval.submitted_at }) }}
        </div>
      </VCardText>

      <!-- The route the document is taking, so "waiting on finance" means something. -->
      <VCardText>
        <ol class="timeline">
          <li v-for="stage in stages" :key="stage.position" class="timeline-stage">
            <div v-for="step in stage.steps" :key="step.uuid" class="timeline-step">
              <VIcon
                :icon="stepLook(step).icon" :color="stepLook(step).colour" size="20"
                class="timeline-icon"
              />

              <div class="timeline-body">
                <div class="d-flex flex-wrap align-center ga-2">
                  <span class="text-body-2 font-weight-medium">{{ step.name }}</span>
                  <span class="text-caption text-medium-emphasis">{{ stepLook(step).label }}</span>
                  <VChip
                    v-if="stage.steps.length > 1" size="x-small" variant="tonal" label
                  >{{ d('approval_parallel') }}</VChip>
                </div>

                <div class="text-caption text-medium-emphasis">
                  <template v-if="step.delegated_to">
                    {{ d('approval_delegated_to', { from: step.assigned_to, to: step.delegated_to }) }}
                  </template>
                  <template v-else>{{ step.assigned_to }}</template>
                  <template v-if="step.due_at"> · {{ d('approval_due', { date: step.due_at }) }}</template>
                </div>

                <div v-if="step.decided_at" class="text-caption text-medium-emphasis">
                  {{ d('approval_decided_by', { user: step.decided_by, at: step.decided_at }) }}
                </div>
                <div v-if="step.comment" class="text-caption mt-1 comment">{{ step.comment }}</div>

                <!-- Only on the step that is actually this person's turn. -->
                <div v-if="step.can_decide" class="d-flex flex-wrap ga-1 mt-2">
                  <VBtn size="small" color="success" variant="tonal" prepend-icon="mdi-check"
                    @click="openDecision(step, 'approved')">{{ d('approve') }}</VBtn>
                  <VBtn size="small" color="warning" variant="tonal" prepend-icon="mdi-pencil"
                    @click="openDecision(step, 'changes_requested')">{{ d('request_changes') }}</VBtn>
                  <VBtn size="small" color="error" variant="tonal" prepend-icon="mdi-close"
                    @click="openDecision(step, 'rejected')">{{ d('reject') }}</VBtn>
                  <VBtn size="small" variant="text" prepend-icon="mdi-account-arrow-right-outline"
                    @click="delegating = step">{{ d('delegate') }}</VBtn>
                </div>
              </div>
            </div>
          </li>
        </ol>
      </VCardText>

      <VDivider />

      <VCardText class="d-flex flex-wrap ga-2">
        <VBtn
          v-if="approvalStatus === 'approved' && canManage"
          color="primary" size="small" prepend-icon="mdi-publish" :loading="busy" @click="publish"
        >{{ d('publish_document') }}</VBtn>

        <VBtn
          v-if="approval.is_open && canManage"
          size="small" variant="text" :loading="busy" @click="cancel"
        >{{ d('approval_withdraw') }}</VBtn>

        <VBtn
          v-if="!approval.is_open && canManage"
          size="small" variant="tonal" prepend-icon="mdi-send-outline" @click="submitting = true"
        >{{ d('approval_resubmit') }}</VBtn>
      </VCardText>
    </template>
  </VCard>

  <!-- ── Decide ───────────────────────────────────────────────────────────── -->
  <VDialog :model-value="deciding !== null" max-width="480" @update:model-value="deciding = null">
    <VCard v-if="deciding">
      <VCardTitle class="text-body-1 font-weight-medium">{{ decisionTitle }}</VCardTitle>
      <VCardText>
        <p class="text-body-2 text-medium-emphasis mb-3">{{ deciding.step.name }}</p>
        <VTextarea
          v-model="comment" rows="3" autofocus density="comfortable"
          :label="commentRequired ? d('approval_comment_required') : d('approval_comment')"
          :hint="commentRequired ? d('approval_comment_hint') : undefined" persistent-hint
        />
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn variant="text" @click="deciding = null">{{ d('cancel') }}</VBtn>
        <VBtn
          color="primary" :loading="busy"
          :disabled="commentRequired && !comment.trim()"
          @click="decide"
        >{{ decisionTitle }}</VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <!-- ── Delegate ─────────────────────────────────────────────────────────── -->
  <VDialog :model-value="delegating !== null" max-width="460" @update:model-value="delegating = null">
    <VCard v-if="delegating">
      <VCardTitle class="text-body-1 font-weight-medium">{{ d('delegate') }}</VCardTitle>
      <VCardText class="d-flex flex-column ga-3">
        <!-- Said plainly: delegating hands it over rather than sharing it. -->
        <p class="text-body-2 text-medium-emphasis mb-0">{{ d('delegate_explain') }}</p>
        <VAutocomplete
          v-model="delegateTo" :items="people" item-title="name" item-value="id"
          :label="d('delegate_to')" density="comfortable" autofocus
        />
        <VTextField v-model="delegateReason" :label="d('delegate_reason')" density="comfortable" />
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn variant="text" @click="delegating = null">{{ d('cancel') }}</VBtn>
        <VBtn color="primary" :loading="busy" :disabled="!delegateTo" @click="delegate">
          {{ d('delegate') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <!-- ── Submit ───────────────────────────────────────────────────────────── -->
  <VDialog v-model="submitting" max-width="460">
    <VCard>
      <VCardTitle class="text-body-1 font-weight-medium">{{ d('submit_for_approval') }}</VCardTitle>
      <VCardText class="d-flex flex-column ga-3">
        <VSelect
          v-if="workflows.length > 1"
          v-model="workflow" :items="workflows" item-title="name" item-value="uuid"
          :label="d('approval_workflow')" density="comfortable"
          :hint="d('approval_workflow_hint')" persistent-hint
        />
        <VTextarea v-model="submitNote" :label="d('approval_note')" rows="2" density="comfortable" />
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn variant="text" @click="submitting = false">{{ d('cancel') }}</VBtn>
        <VBtn color="primary" :loading="busy" @click="submitForApproval">
          {{ d('submit_for_approval') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>

<style scoped>
.timeline {
  list-style: none;
  margin: 0;
  padding: 0;
}

.timeline-stage {
  position: relative;
  padding-inline-start: 0;
}

/* The connecting line, drawn behind the icons so a stage with two steps reads as one stage. */
.timeline-stage:not(:last-child)::before {
  content: '';
  position: absolute;
  inset-inline-start: 9px;
  inset-block: 1.6rem -0.25rem;
  inline-size: 2px;
  background: rgba(127, 127, 127, 0.28);
}

.timeline-step {
  display: flex;
  gap: 0.75rem;
  padding-block: 0.4rem;
}

.timeline-icon {
  flex: 0 0 auto;
  margin-block-start: 2px;
  /* Sits above the connecting line so the line does not show through the icon. */
  position: relative;
  background: rgb(var(--v-theme-surface));
}

.timeline-body { min-inline-size: 0; }

.comment {
  border-inline-start: 2px solid rgba(127, 127, 127, 0.3);
  padding-inline-start: 0.5rem;
}

/* Every control here is at least a comfortable tap target on a phone, where approvals are
   actually answered. */
:deep(.v-btn--size-small) { min-block-size: 36px; }
</style>
