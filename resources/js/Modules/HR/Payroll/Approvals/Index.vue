<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3'
import { ref, watch, computed } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Step {
  id: number; level: string; action: string; comments: string | null
  acted_at: string; approver_name: string
}
interface Comment {
  id: number; comment: string; is_internal: boolean; created_at: string; user_name: string
}
interface RunMeta {
  uuid: string; ref_number: string; name: string
  period_start: string; period_end: string
  total_net: number; employee_count: number; ai_risk_level: string | null
}
interface Approval {
  id: number; uuid: string; run_id: number
  current_level: string; status: string
  submitted_by: number; submitted_by_name: string
  due_date: string | null; high_risk: boolean; auto_escalated: boolean
  payroll_run: RunMeta | null
  steps: Step[]
  comments: Comment[]
  metadata: Record<string, any> | null
}
interface Run { id: number; uuid: string; ref_number: string; name: string; status: string }
interface Page<T> { data: T[]; current_page: number; last_page: number; total: number }
    function formatDate(date: string | null): string {
  if (!date) return '—'
  const d = new Date(date)
  return d.toLocaleDateString('en-ZA', {
    year: 'numeric',
    month: 'short',
    day: '2-digit',
  })
}

const props = defineProps<{
  approvals: Page<Approval>
  filters:   Record<string, string>
  stats:     { pending: number; escalated: number; approved: number; rejected: number }
  runs:      Run[]
  levels:    string[]
}>()

const statusFilter = ref(props.filters.status ?? '')
const levelFilter  = ref(props.filters.level  ?? '')

watch([statusFilter, levelFilter], () => {
  router.get(route('payroll.approvals.index'), { status: statusFilter.value, level: levelFilter.value }, { preserveState: true, replace: true })
})

const submitDialog = ref(false)
const selectedRun  = ref<Run | null>(null)
const submitForm   = useForm({})

function doSubmit() {
  if (!selectedRun.value) return
  submitForm.post(route('payroll.approvals.submit', selectedRun.value.uuid), {
    onSuccess: () => { submitDialog.value = false },
  })
}

const approveTarget = ref<Approval | null>(null)
const approveDialog = ref(false)
const approveForm   = useForm({ comments: '' })

function openApprove(approval: Approval) { approveTarget.value = approval; approveForm.reset(); approveDialog.value = true }
function doApprove() {
  if (!approveTarget.value) return
  approveForm.post(route('payroll.approvals.approve', approveTarget.value.id), {
    onSuccess: () => { approveDialog.value = false },
  })
}

const rejectTarget = ref<Approval | null>(null)
const rejectDialog = ref(false)
const rejectForm   = useForm({ reason: '' })

function openReject(approval: Approval) { rejectTarget.value = approval; rejectForm.reset(); rejectDialog.value = true }
function doReject() {
  if (!rejectTarget.value) return
  rejectForm.post(route('payroll.approvals.reject', rejectTarget.value.id), {
    onSuccess: () => { rejectDialog.value = false },
  })
}

const corrTarget   = ref<Approval | null>(null)
const corrDialog   = ref(false)
const corrForm     = useForm({ notes: '' })

function openCorrection(approval: Approval) { corrTarget.value = approval; corrForm.reset(); corrDialog.value = true }
function doRequestCorrection() {
  if (!corrTarget.value) return
  corrForm.post(route('payroll.approvals.request-correction', corrTarget.value.id), {
    onSuccess: () => { corrDialog.value = false },
  })
}

const escalateTarget = ref<Approval | null>(null)
const escalateDialog = ref(false)
const escalateForm   = useForm({ reason: '' })

function openEscalate(approval: Approval) { escalateTarget.value = approval; escalateForm.reset(); escalateDialog.value = true }
function doEscalate() {
  if (!escalateTarget.value) return
  escalateForm.post(route('payroll.approvals.escalate', escalateTarget.value.id), {
    onSuccess: () => { escalateDialog.value = false },
  })
}

const commentTarget = ref<Approval | null>(null)
const commentDialog = ref(false)
const commentForm   = useForm({ comment: '', is_internal: false })

function openComment(approval: Approval) { commentTarget.value = approval; commentForm.reset(); commentDialog.value = true }
function doComment() {
  if (!commentTarget.value) return
  commentForm.post(route('payroll.approvals.comment', commentTarget.value.id), {
    onSuccess: () => { commentDialog.value = false },
  })
}

const expanded = ref<Set<number>>(new Set())
function toggle(id: number) { expanded.value.has(id) ? expanded.value.delete(id) : expanded.value.add(id) }

const LEVEL_ORDER = ['payroll_officer', 'hr_manager', 'finance', 'executive']
const levelLabel  = (l: string) => t(`payroll_approvals.levels.${l}`)

const levelIndex = (l: string) => LEVEL_ORDER.indexOf(l)

const statusColor = (s: string) => ({
  pending: 'warning', escalated: 'orange', approved: 'success',
  rejected: 'error', info_requested: 'info',
}[s] ?? 'grey')

const actionIcon = (a: string) => ({
  approved: 'tabler-circle-check', rejected: 'tabler-circle-x',
  escalated: 'tabler-circle-arrow-up', info_requested: 'tabler-message-question',
}[a] ?? 'tabler-circle')

const actionColor = (a: string) => ({
  approved: 'success', rejected: 'error', escalated: 'orange', info_requested: 'info',
}[a] ?? 'grey')

const riskColor = (r: string | null) => ({ low: 'success', medium: 'warning', high: 'error', critical: 'purple' }[r ?? ''] ?? 'grey')

const canAct = (approval: Approval) => ['pending', 'escalated'].includes(approval.status)
const fmt = (n: number) => 'R ' + new Intl.NumberFormat('en-ZA', { minimumFractionDigits: 2 }).format(n)

const statusItems = computed(() => [
  { title: t('payroll_approvals.filters.all'), value: '' },
  { title: t('payroll_approvals.filters.statuses.pending'), value: 'pending' },
  { title: t('payroll_approvals.filters.statuses.escalated'), value: 'escalated' },
  { title: t('payroll_approvals.filters.statuses.approved'), value: 'approved' },
  { title: t('payroll_approvals.filters.statuses.rejected'), value: 'rejected' },
  { title: t('payroll_approvals.filters.statuses.info_requested'), value: 'info_requested' },
])

const levelItems = computed(() => [
  { title: t('payroll_approvals.filters.all_levels'), value: '' },
  ...props.levels.map(l => ({ title: levelLabel(l), value: l })),
])
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('payroll_approvals.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">
          {{ t('payroll_approvals.description') }}
        </p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-send" @click="submitDialog = true">
        {{ t('payroll_approvals.submit_run') }}
      </VBtn>
    </div>

    <VRow class="mb-4">
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="warning" size="22" class="mb-1">tabler-clock</VIcon>
            <div class="text-h5 font-weight-bold text-warning">{{ stats.pending }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_approvals.stats.pending') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="orange" size="22" class="mb-1">tabler-circle-arrow-up</VIcon>
            <div class="text-h5 font-weight-bold text-orange">{{ stats.escalated }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_approvals.stats.escalated') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="success" size="22" class="mb-1">tabler-circle-check</VIcon>
            <div class="text-h5 font-weight-bold text-success">{{ stats.approved }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_approvals.stats.approved') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="error" size="22" class="mb-1">tabler-circle-x</VIcon>
            <div class="text-h5 font-weight-bold text-error">{{ stats.rejected }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_approvals.stats.rejected') }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard elevation="0" border class="mb-4">
      <VCardText class="pa-3">
        <VRow dense>
          <VCol cols="12" sm="6">
            <VSelect v-model="statusFilter" :label="t('payroll_approvals.filters.status')"
              :items="statusItems"
              item-title="title" item-value="value" density="compact" variant="outlined" hide-details />
          </VCol>
          <VCol cols="12" sm="6">
            <VSelect v-model="levelFilter" :label="t('payroll_approvals.filters.level')"
              :items="levelItems"
              item-title="title" item-value="value" density="compact" variant="outlined" hide-details />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard v-if="!approvals.data.length" elevation="0" border class="text-center pa-12">
      <VIcon size="52" class="mb-3 opacity-30">tabler-checks</VIcon>
      <div class="text-h6 mb-2">{{ t('payroll_approvals.empty.title') }}</div>
      <p class="text-body-2 text-medium-emphasis">{{ t('payroll_approvals.empty.description') }}</p>
    </VCard>

    <div class="d-flex flex-column ga-3">
      <VCard v-for="approval in approvals.data" :key="approval.id" elevation="0" border>
        <VCardText class="pa-4">

          <div class="d-flex align-center justify-space-between flex-wrap ga-2 mb-3">
            <div class="flex-grow-1">
              <div class="d-flex align-center ga-2 mb-1 flex-wrap">
                <span class="text-body-1 font-weight-bold">{{ approval.payroll_run?.ref_number ?? '—' }}</span>
                <span class="text-body-2 text-medium-emphasis">{{ approval.payroll_run?.name }}</span>
                <VChip v-if="approval.high_risk" size="x-small" color="error" variant="tonal" prepend-icon="tabler-alert-triangle">
                  {{ t('payroll_approvals.card.high_risk') }}
                </VChip>
                <VChip v-if="approval.auto_escalated" size="x-small" color="orange" variant="tonal">
                  {{ t('payroll_approvals.card.auto_escalated') }}
                </VChip>
                <VChip size="x-small" :color="statusColor(approval.status)" variant="tonal">{{ approval.status }}</VChip>
                <VBtn v-if="approval.payroll_run" size="x-small" variant="tonal" color="primary" prepend-icon="tabler-eye"
                  :href="route('payroll.runs.show', approval.payroll_run.uuid)">
                  {{ t('payroll_approvals.card.view_details') }}
                </VBtn>
              </div>
              <div class="text-caption text-medium-emphasis d-flex flex-wrap ga-3">
                <span>{{ t('payroll_approvals.card.submitted_by', { name: approval.submitted_by_name }) }}</span>
             <span v-if="approval.payroll_run">
  {{ formatDate(approval.payroll_run.period_start) }} → {{ formatDate(approval.payroll_run.period_end) }}
</span>
                <span v-if="approval.payroll_run?.total_net">{{ t('payroll_approvals.card.net', { amount: fmt(approval.payroll_run.total_net) }) }}</span>

                <span v-if="approval.due_date">{{ t('payroll_approvals.card.due', { date: formatDate(approval.due_date) }) }}</span>
                <span v-if="approval.payroll_run?.ai_risk_level">
                  {{ t('payroll_approvals.card.risk') }} <VChip size="x-small" :color="riskColor(approval.payroll_run.ai_risk_level)" variant="tonal">{{ approval.payroll_run.ai_risk_level }}</VChip>
                </span>
              </div>
            </div>
          </div>

          <div class="d-flex align-center ga-1 mb-3 flex-wrap">
            <template v-for="(lvl, idx) in levels" :key="lvl">
              <div class="d-flex align-center ga-1">
                <VChip
                  size="small"
                  :color="lvl === approval.current_level ? statusColor(approval.status) : (levelIndex(lvl) < levelIndex(approval.current_level) ? 'success' : 'default')"
                  :variant="lvl === approval.current_level ? 'tonal' : 'outlined'"
                >
                  <VIcon v-if="levelIndex(lvl) < levelIndex(approval.current_level)" start size="12">tabler-check</VIcon>
                  {{ levelLabel(lvl) }}
                </VChip>
                <VIcon v-if="idx < levels.length - 1" size="14" color="grey-lighten-1">tabler-chevron-right</VIcon>
              </div>
            </template>
          </div>

          <div v-if="canAct(approval)" class="d-flex ga-2 flex-wrap mb-3">
            <VBtn size="small" color="success" prepend-icon="tabler-check" @click="openApprove(approval)">{{ t('payroll_approvals.actions.approve') }}</VBtn>
            <VBtn size="small" color="error" variant="outlined" prepend-icon="tabler-x" @click="openReject(approval)">{{ t('payroll_approvals.actions.reject') }}</VBtn>
            <VBtn size="small" color="info" variant="outlined" prepend-icon="tabler-pencil-plus" @click="openCorrection(approval)">
              {{ t('payroll_approvals.actions.request_correction') }}
            </VBtn>
            <VBtn v-if="approval.current_level !== 'executive'" size="small" color="orange" variant="outlined"
              prepend-icon="tabler-circle-arrow-up" @click="openEscalate(approval)">
              {{ t('payroll_approvals.actions.escalate') }}
            </VBtn>
          </div>

          <VDivider class="mb-2" />
          <div class="d-flex align-center justify-space-between">
            <VBtn size="x-small" variant="text" :prepend-icon="expanded.has(approval.id) ? 'tabler-chevron-up' : 'tabler-chevron-down'"
              @click="toggle(approval.id)">
              {{ t('payroll_approvals.card.history', { steps: approval.steps.length, comments: approval.comments.length }) }}
            </VBtn>
            <VBtn size="x-small" variant="text" prepend-icon="tabler-message-circle" @click="openComment(approval)">
              {{ t('payroll_approvals.card.add_comment') }}
            </VBtn>
          </div>

          <template v-if="expanded.has(approval.id)">
            <VDivider class="mt-2 mb-3" />

            <div v-if="approval.steps.length" class="mb-3">
              <div class="text-caption font-weight-medium text-medium-emphasis mb-2">{{ t('payroll_approvals.card.approval_history') }}</div>
              <div v-for="step in approval.steps" :key="step.id" class="d-flex ga-2 mb-2">
                <VIcon :color="actionColor(step.action)" size="16" class="mt-1">{{ actionIcon(step.action) }}</VIcon>
                <div>
               <div class="text-caption font-weight-medium">
  {{ levelLabel(step.level) }} · <span :class="`text-${actionColor(step.action)}`">{{ step.action }}</span>
  · {{ step.approver_name }} · {{ formatDate(step.acted_at) }}
</div>
                  <div v-if="step.comments" class="text-caption text-medium-emphasis">{{ step.comments }}</div>
                </div>
              </div>
            </div>

            <div v-if="approval.comments.length">
              <div class="text-caption font-weight-medium text-medium-emphasis mb-2">{{ t('payroll_approvals.card.comments') }}</div>
              <div v-for="comment in approval.comments" :key="comment.id"
                class="pa-2 mb-2 rounded border" :class="comment.is_internal ? 'bg-grey-lighten-5' : ''">
                <div class="d-flex align-center ga-2 mb-1">
                  <span class="text-caption font-weight-medium">{{ comment.user_name }}</span>
                  <VChip v-if="comment.is_internal" size="x-small" color="grey" variant="tonal">{{ t('payroll_approvals.card.internal') }}</VChip>
                <span class="text-caption text-medium-emphasis ml-auto">{{ formatDate(comment.created_at) }}</span>
                </div>
                <div class="text-caption">{{ comment.comment }}</div>
              </div>
            </div>
          </template>

        </VCardText>
      </VCard>
    </div>

    <div v-if="approvals.last_page > 1" class="d-flex justify-center mt-4">
      <VPagination :model-value="approvals.current_page" :length="approvals.last_page" density="compact"
        @update:model-value="p => router.get(route('payroll.approvals.index'), { ...filters, page: p }, { preserveState: true })" />
    </div>

    <VDialog v-model="submitDialog" max-width="500">
      <VCard>
        <VCardTitle class="pa-4 d-flex align-center ga-2 text-body-1 font-weight-semibold">
          <VIcon color="primary">tabler-send</VIcon>{{ t('payroll_approvals.dialog.submit_title') }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAlert type="info" variant="tonal" density="compact" class="mb-4">
            {{ t('payroll_approvals.dialog.submit_info') }}
          </VAlert>
          <VSelect v-model="selectedRun" :label="t('payroll_approvals.dialog.select_run')" :items="runs"
            item-title="ref_number" item-value="id" return-object variant="outlined" density="compact">
            <template #item="{ item, props: p }">
              <VListItem v-bind="p">
                <template #subtitle>{{ item.raw.name }} · {{ item.raw.status }}</template>
              </VListItem>
            </template>
          </VSelect>
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="submitDialog = false">{{ t('payroll_approvals.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="submitForm.processing" :disabled="!selectedRun" @click="doSubmit">
            {{ t('payroll_approvals.dialog.submit_btn') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="approveDialog" max-width="480">
      <VCard v-if="approveTarget">
        <VCardTitle class="pa-4 d-flex align-center ga-2 text-body-1 font-weight-semibold">
          <VIcon color="success">tabler-circle-check</VIcon>
          {{ t('payroll_approvals.dialog.approve_title', { ref: approveTarget.payroll_run?.ref_number }) }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAlert v-if="approveTarget.high_risk" type="warning" variant="tonal" density="compact" class="mb-3">
            {{ t('payroll_approvals.dialog.approve_high_risk') }}
          </VAlert>
          <VTextarea v-model="approveForm.comments" :label="t('payroll_approvals.dialog.approve_comments')" variant="outlined" density="compact" rows="3" />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="approveDialog = false">{{ t('payroll_approvals.dialog.cancel') }}</VBtn>
          <VBtn color="success" :loading="approveForm.processing" @click="doApprove">{{ t('payroll_approvals.dialog.approve_btn') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="rejectDialog" max-width="480">
      <VCard v-if="rejectTarget">
        <VCardTitle class="pa-4 d-flex align-center ga-2 text-body-1 font-weight-semibold">
          <VIcon color="error">tabler-circle-x</VIcon>
          {{ t('payroll_approvals.dialog.reject_title', { ref: rejectTarget.payroll_run?.ref_number }) }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VTextarea v-model="rejectForm.reason" :label="t('payroll_approvals.dialog.reject_reason')" variant="outlined" density="compact" rows="3"
            :error-messages="rejectForm.errors.reason" />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="rejectDialog = false">{{ t('payroll_approvals.dialog.cancel') }}</VBtn>
          <VBtn color="error" :loading="rejectForm.processing" @click="doReject">{{ t('payroll_approvals.dialog.reject_btn') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="corrDialog" max-width="480">
      <VCard v-if="corrTarget">
        <VCardTitle class="pa-4 d-flex align-center ga-2 text-body-1 font-weight-semibold">
          <VIcon color="info">tabler-pencil-plus</VIcon>
          {{ t('payroll_approvals.dialog.correction_title', { ref: corrTarget.payroll_run?.ref_number }) }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAlert type="info" variant="tonal" density="compact" class="mb-3">
            {{ t('payroll_approvals.dialog.correction_info') }}
          </VAlert>
          <VTextarea v-model="corrForm.notes" :label="t('payroll_approvals.dialog.correction_notes')" variant="outlined" density="compact" rows="3"
            :error-messages="corrForm.errors.notes" />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="corrDialog = false">{{ t('payroll_approvals.dialog.cancel') }}</VBtn>
          <VBtn color="info" :loading="corrForm.processing" @click="doRequestCorrection">{{ t('payroll_approvals.dialog.correction_btn') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="escalateDialog" max-width="480">
      <VCard v-if="escalateTarget">
        <VCardTitle class="pa-4 d-flex align-center ga-2 text-body-1 font-weight-semibold">
          <VIcon color="orange">tabler-arrow-up-circle</VIcon>
          {{ t('payroll_approvals.dialog.escalate_title', { ref: escalateTarget.payroll_run?.ref_number }) }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VTextarea v-model="escalateForm.reason" :label="t('payroll_approvals.dialog.escalate_reason')" variant="outlined" density="compact" rows="3" />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="escalateDialog = false">{{ t('payroll_approvals.dialog.cancel') }}</VBtn>
          <VBtn color="orange" :loading="escalateForm.processing" @click="doEscalate">{{ t('payroll_approvals.dialog.escalate_btn') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="commentDialog" max-width="480">
      <VCard v-if="commentTarget">
        <VCardTitle class="pa-4 d-flex align-center ga-2 text-body-1 font-weight-semibold">
          <VIcon>tabler-message-circle</VIcon>{{ t('payroll_approvals.dialog.comment_title') }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VTextarea v-model="commentForm.comment" :label="t('payroll_approvals.dialog.comment_label')" variant="outlined" density="compact" rows="3"
            :error-messages="commentForm.errors.comment" />
          <VCheckbox v-model="commentForm.is_internal" :label="t('payroll_approvals.dialog.comment_internal')" density="compact" hide-details />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="commentDialog = false">{{ t('payroll_approvals.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="commentForm.processing" @click="doComment">{{ t('payroll_approvals.dialog.comment_btn') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

  </div>
</template>
