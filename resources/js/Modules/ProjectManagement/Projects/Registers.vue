<script setup lang="ts">
import { computed, ref } from 'vue'
import { useDisplay } from 'vuetify'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

const { t } = useI18n()
const route = ziggyRoute

// Dialogs go full-screen on a phone: these are long forms, and a capped-width dialog on a 375px
// screen is a column of clipped fields.
const { smAndDown } = useDisplay()

type Risk = {
  id: string; code: string; title: string; description: string | null; category: string
  probability: number; impact: number; score: number; band: string
  strategy: string; mitigation: string | null; contingency: string | null
  status: string; owner_id: number | null; review_due: string | null; overdue_review: boolean
  closure_note: string | null; cost_impact: number | null; schedule_days: number | null
}
type Issue = {
  id: string; code: string; title: string; description: string | null; kind: string
  severity: string; priority: string; status: string; assigned_to: number | null
  due_date: string | null; overdue: boolean; resolution: string | null
  root_cause: string | null; corrective_action: string | null; preventative_action: string | null
  needs_root_cause: boolean; from_risk: string | null; cost_impact: number | null
}
type Change = {
  id: string; code: string; title: string; description: string | null; kind: string
  reason: string | null; impact_scope: string | null; impact_cost: number | null
  impact_days: number | null; impact_quality: string | null; impact_risk: string | null
  status: string; editable: boolean; awaiting_implementation: boolean
  decided_at: string | null; decision_note: string | null
}

const props = defineProps<{
  project: { id: string; code: string; name: string; currency: string }
  risks: Risk[]
  issues: Issue[]
  changes: Change[]
  members: Array<{ id: number; name: string }>
  tasks: Array<{ id: string; name: string }>
  options: Record<string, string[]>
  can: { manage: boolean; costs: boolean }
}>()

const tab = ref<'risks' | 'issues' | 'changes'>('risks')

// The numbers that belong at the top of a steering meeting, not buried in a column.
const stats = computed(() => ({
  highRisks: props.risks.filter(r => r.band === 'high' && ['open', 'mitigating'].includes(r.status)).length,
  reviewsDue: props.risks.filter(r => r.overdue_review).length,
  openIssues: props.issues.filter(i => ['open', 'in_progress'].includes(i.status)).length,
  overdueIssues: props.issues.filter(i => i.overdue).length,
  pendingChanges: props.changes.filter(c => c.status === 'submitted').length,
  toImplement: props.changes.filter(c => c.awaiting_implementation).length,
}))

function bandColour(band: string) {
  return band === 'high' ? 'error' : band === 'medium' ? 'warning' : 'success'
}
function severityColour(s: string) {
  return s === 'critical' ? 'error' : s === 'high' ? 'warning' : s === 'medium' ? 'info' : 'default'
}
function statusColour(s: string) {
  return ({
    open: 'error', in_progress: 'warning', mitigating: 'warning', resolved: 'success',
    closed: 'default', realised: 'error', draft: 'default', submitted: 'info',
    approved: 'success', rejected: 'error', implemented: 'primary',
  } as Record<string, string>)[s] ?? 'default'
}
function money(v: number | null) {
  return v === null ? '—' : `${props.project.currency} ${v.toFixed(2)}`
}
function memberName(id: number | null) {
  return props.members.find(m => m.id === id)?.name ?? '—'
}

// ── Risks ───────────────────────────────────────────────────────────────────
const riskDialog = ref(false)
const editingRisk = ref<Risk | null>(null)
const riskForm = useForm({
  title: '', description: '', category: 'technical', probability: 3, impact: 3,
  response_strategy: 'reduce', mitigation: '', contingency: '', status: 'open',
  owner_user_id: null as number | null, review_due: '',
  cost_impact: null as number | null, schedule_impact_days: null as number | null,
})

// Shown as you move the sliders, because probability × impact is the whole judgement and making
// somebody save to find out what band they landed in is how registers end up all-amber.
const riskScore = computed(() => riskForm.probability * riskForm.impact)
const riskBand = computed(() => riskScore.value >= 15 ? 'high' : riskScore.value >= 8 ? 'medium' : 'low')

function openRisk(r: Risk | null) {
  editingRisk.value = r
  riskForm.clearErrors()
  if (r) {
    Object.assign(riskForm, {
      title: r.title, description: r.description ?? '', category: r.category,
      probability: r.probability, impact: r.impact, response_strategy: r.strategy,
      mitigation: r.mitigation ?? '', contingency: r.contingency ?? '', status: r.status,
      owner_user_id: r.owner_id, review_due: r.review_due ?? '',
      cost_impact: r.cost_impact, schedule_impact_days: r.schedule_days,
    })
  } else {
    riskForm.reset()
  }
  riskDialog.value = true
}

function saveRisk() {
  const opts = { preserveScroll: true, onSuccess: () => { riskDialog.value = false } }
  editingRisk.value
    ? riskForm.put(route('project-management.risks.update', [props.project.id, editingRisk.value.id]), opts)
    : riskForm.post(route('project-management.risks.store', props.project.id), opts)
}

const closeRiskDialog = ref(false)
const closeRiskForm = useForm({ closure_note: '' })
const closingRisk = ref<Risk | null>(null)

function openCloseRisk(r: Risk) {
  closingRisk.value = r
  closeRiskForm.reset()
  closeRiskForm.clearErrors()
  closeRiskDialog.value = true
}

function submitCloseRisk() {
  if (!closingRisk.value) return
  closeRiskForm.post(route('project-management.risks.close', [props.project.id, closingRisk.value.id]), {
    preserveScroll: true, onSuccess: () => { closeRiskDialog.value = false },
  })
}

const realiseDialog = ref(false)
const realiseForm = useForm({ kind: 'issue', severity: 'high' })
const realisingRisk = ref<Risk | null>(null)

function openRealise(r: Risk) {
  realisingRisk.value = r
  realiseForm.reset()
  realiseDialog.value = true
}

function submitRealise() {
  if (!realisingRisk.value) return
  realiseForm.post(route('project-management.risks.realise', [props.project.id, realisingRisk.value.id]), {
    preserveScroll: true, onSuccess: () => { realiseDialog.value = false; tab.value = 'issues' },
  })
}

// ── Issues ──────────────────────────────────────────────────────────────────
const issueDialog = ref(false)
const editingIssue = ref<Issue | null>(null)
const issueForm = useForm({
  title: '', description: '', kind: 'issue', severity: 'medium', priority: 'medium',
  status: 'open', assigned_to: null as number | null, task_id: '',
  due_date: '', cost_impact: null as number | null,
})

function openIssue(i: Issue | null) {
  editingIssue.value = i
  issueForm.clearErrors()
  if (i) {
    Object.assign(issueForm, {
      title: i.title, description: i.description ?? '', kind: i.kind, severity: i.severity,
      priority: i.priority, status: i.status, assigned_to: i.assigned_to, task_id: '',
      due_date: i.due_date ?? '', cost_impact: i.cost_impact,
    })
  } else {
    issueForm.reset()
  }
  issueDialog.value = true
}

function saveIssue() {
  const opts = { preserveScroll: true, onSuccess: () => { issueDialog.value = false } }
  editingIssue.value
    ? issueForm.put(route('project-management.issues.update', [props.project.id, editingIssue.value.id]), opts)
    : issueForm.post(route('project-management.issues.store', props.project.id), opts)
}

const resolveDialog = ref(false)
const resolvingIssue = ref<Issue | null>(null)
const resolveForm = useForm({
  resolution: '', root_cause: '', corrective_action: '', preventative_action: '',
})

function openResolve(i: Issue) {
  resolvingIssue.value = i
  resolveForm.reset()
  resolveForm.clearErrors()
  resolveDialog.value = true
}

function submitResolve() {
  if (!resolvingIssue.value) return
  resolveForm.post(route('project-management.issues.resolve', [props.project.id, resolvingIssue.value.id]), {
    preserveScroll: true, onSuccess: () => { resolveDialog.value = false },
  })
}

// ── Change requests ─────────────────────────────────────────────────────────
const changeDialog = ref(false)
const editingChange = ref<Change | null>(null)
const changeForm = useForm({
  title: '', description: '', kind: 'scope', reason: '',
  impact_scope: '', impact_cost: null as number | null, impact_days: null as number | null,
  impact_quality: '', impact_risk: '',
})

function openChange(c: Change | null) {
  editingChange.value = c
  changeForm.clearErrors()
  if (c) {
    Object.assign(changeForm, {
      title: c.title, description: c.description ?? '', kind: c.kind, reason: c.reason ?? '',
      impact_scope: c.impact_scope ?? '', impact_cost: c.impact_cost,
      impact_days: c.impact_days, impact_quality: c.impact_quality ?? '',
      impact_risk: c.impact_risk ?? '',
    })
  } else {
    changeForm.reset()
  }
  changeDialog.value = true
}

function saveChange() {
  const opts = { preserveScroll: true, onSuccess: () => { changeDialog.value = false } }
  editingChange.value
    ? changeForm.put(route('project-management.changes.update', [props.project.id, editingChange.value.id]), opts)
    : changeForm.post(route('project-management.changes.store', props.project.id), opts)
}

const confirmer = ref()

function submitChange(c: Change) {
  confirmer.value.open({
    title: t('project_management.registers.confirm_submit_title'),
    message: t('project_management.registers.confirm_submit_body', { code: c.code }),
    confirmText: t('project_management.registers.submit_for_decision'),
    icon: 'tabler-send',
  }, () => router.post(route('project-management.changes.submit', [props.project.id, c.id]), {}, { preserveScroll: true }))
}

function implementChange(c: Change) {
  confirmer.value.open({
    title: t('project_management.registers.confirm_implement_title'),
    message: t('project_management.registers.confirm_implement_body', { code: c.code }),
    confirmText: t('project_management.registers.implement'),
    confirmColor: 'primary',
    icon: 'tabler-check',
  }, () => router.post(route('project-management.changes.implement', [props.project.id, c.id]), {}, { preserveScroll: true }))
}

function remove(kind: 'risks' | 'issues' | 'changes', record: { id: string; code: string; title: string }) {
  confirmer.value.open({
    title: t('project_management.registers.confirm_remove_title'),
    message: t('project_management.registers.confirm_remove_body', { code: record.code, title: record.title }),
    confirmText: t('project_management.actions.delete'),
    icon: 'tabler-trash',
  }, () => router.delete(route(`project-management.${kind}.destroy`, [props.project.id, record.id]), { preserveScroll: true }))
}
</script>

<template>
  <div class="pa-4">
    <VBtn variant="text" prepend-icon="tabler-arrow-left" class="mb-3"
          @click="router.get(route('project-management.projects.show', project.id))">
      {{ project.code }} · {{ project.name }}
    </VBtn>

    <div class="mb-4">
      <h4 class="text-h4">{{ t('project_management.registers.title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('project_management.registers.subtitle') }}</p>
    </div>

    <!-- What somebody needs to act on, above the tabs rather than inside one of them. -->
    <VRow dense class="mb-2">
      <VCol cols="6" md="2">
        <VCard border><VCardText class="py-3">
          <div class="text-h5 text-error">{{ stats.highRisks }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('project_management.registers.stat_high_risks') }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" md="2">
        <VCard border><VCardText class="py-3">
          <div class="text-h5" :class="stats.reviewsDue ? 'text-warning' : ''">{{ stats.reviewsDue }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('project_management.registers.stat_reviews_due') }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" md="2">
        <VCard border><VCardText class="py-3">
          <div class="text-h5">{{ stats.openIssues }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('project_management.registers.stat_open_issues') }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" md="2">
        <VCard border><VCardText class="py-3">
          <div class="text-h5" :class="stats.overdueIssues ? 'text-error' : ''">{{ stats.overdueIssues }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('project_management.registers.stat_overdue_issues') }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" md="2">
        <VCard border><VCardText class="py-3">
          <div class="text-h5">{{ stats.pendingChanges }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('project_management.registers.stat_pending_changes') }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" md="2">
        <VCard border><VCardText class="py-3">
          <div class="text-h5" :class="stats.toImplement ? 'text-primary' : ''">{{ stats.toImplement }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('project_management.registers.stat_to_implement') }}</div>
        </VCardText></VCard>
      </VCol>
    </VRow>

    <VCard border>
      <VTabs v-model="tab" show-arrows>
        <VTab value="risks" prepend-icon="tabler-alert-triangle">
          {{ t('project_management.registers.risks') }} ({{ risks.length }})
        </VTab>
        <VTab value="issues" prepend-icon="tabler-bug">
          {{ t('project_management.registers.issues') }} ({{ issues.length }})
        </VTab>
        <VTab value="changes" prepend-icon="tabler-git-branch">
          {{ t('project_management.registers.changes') }} ({{ changes.length }})
        </VTab>
      </VTabs>
      <VDivider />

      <VWindow v-model="tab">
        <!-- ── Risks ──────────────────────────────────────────────────────── -->
        <VWindowItem value="risks">
          <VCardText class="d-flex align-center justify-space-between flex-wrap gap-2">
            <span class="text-body-2 text-medium-emphasis">{{ t('project_management.registers.risks_sub') }}</span>
            <VBtn v-if="can.manage" size="small" color="primary" prepend-icon="tabler-plus" @click="openRisk(null)">
              {{ t('project_management.registers.add_risk') }}
            </VBtn>
          </VCardText>

          <VTable v-if="risks.length" density="comfortable">
            <thead>
              <tr>
                <th>{{ t('project_management.registers.col_code') }}</th>
                <th>{{ t('project_management.registers.col_risk') }}</th>
                <th class="text-center">{{ t('project_management.registers.col_score') }}</th>
                <th>{{ t('project_management.registers.col_strategy') }}</th>
                <th>{{ t('project_management.registers.col_owner') }}</th>
                <th>{{ t('project_management.registers.col_status') }}</th>
                <th v-if="can.costs" class="text-end">{{ t('project_management.registers.col_exposure') }}</th>
                <th class="text-end">{{ t('project_management.actions.actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in risks" :key="r.id">
                <td class="text-no-wrap">{{ r.code }}</td>
                <td>
                  <div>{{ r.title }}</div>
                  <div class="text-caption text-medium-emphasis">
                    {{ t(`project_management.registers.category_${r.category}`) }}
                    <template v-if="r.overdue_review">
                      · <span class="text-warning">{{ t('project_management.registers.review_overdue') }}</span>
                    </template>
                  </div>
                </td>
                <td class="text-center">
                  <!-- Colour is not the only signal: the number is there too, for anybody who
                       cannot distinguish the bands. -->
                  <VChip size="small" label :color="bandColour(r.band)">
                    {{ r.score }} · {{ t(`project_management.registers.band_${r.band}`) }}
                  </VChip>
                  <div class="text-caption text-medium-emphasis">{{ r.probability }} × {{ r.impact }}</div>
                </td>
                <td>{{ t(`project_management.registers.strategy_${r.strategy}`) }}</td>
                <td>{{ memberName(r.owner_id) }}</td>
                <td>
                  <VChip size="x-small" label :color="statusColour(r.status)">
                    {{ t(`project_management.registers.risk_status_${r.status}`) }}
                  </VChip>
                </td>
                <td v-if="can.costs" class="text-end text-no-wrap">
                  {{ money(r.cost_impact) }}
                  <div v-if="r.schedule_days" class="text-caption text-medium-emphasis">
                    {{ t('project_management.registers.days_n', { n: r.schedule_days }) }}
                  </div>
                </td>
                <td class="text-end text-no-wrap">
                  <template v-if="can.manage">
                    <VBtn size="small" variant="text" icon="tabler-pencil"
                          :aria-label="t('project_management.registers.edit_named', { code: r.code })"
                          @click="openRisk(r)" />
                    <VBtn v-if="['open', 'mitigating'].includes(r.status)" size="small" variant="text"
                          icon="tabler-flame" color="error"
                          :aria-label="t('project_management.registers.realise_named', { code: r.code })"
                          @click="openRealise(r)" />
                    <VBtn v-if="['open', 'mitigating'].includes(r.status)" size="small" variant="text"
                          icon="tabler-circle-check" color="success"
                          :aria-label="t('project_management.registers.close_named', { code: r.code })"
                          @click="openCloseRisk(r)" />
                    <VBtn size="small" variant="text" icon="tabler-trash"
                          :aria-label="t('project_management.registers.remove_named', { code: r.code })"
                          @click="remove('risks', r)" />
                  </template>
                </td>
              </tr>
            </tbody>
          </VTable>
          <VCardText v-else class="text-center text-medium-emphasis py-8">
            {{ t('project_management.registers.no_risks') }}
            <div class="text-caption mt-1">{{ t('project_management.registers.no_risks_hint') }}</div>
          </VCardText>
        </VWindowItem>

        <!-- ── Issues ─────────────────────────────────────────────────────── -->
        <VWindowItem value="issues">
          <VCardText class="d-flex align-center justify-space-between flex-wrap gap-2">
            <span class="text-body-2 text-medium-emphasis">{{ t('project_management.registers.issues_sub') }}</span>
            <VBtn v-if="can.manage" size="small" color="primary" prepend-icon="tabler-plus" @click="openIssue(null)">
              {{ t('project_management.registers.add_issue') }}
            </VBtn>
          </VCardText>

          <VTable v-if="issues.length" density="comfortable">
            <thead>
              <tr>
                <th>{{ t('project_management.registers.col_code') }}</th>
                <th>{{ t('project_management.registers.col_issue') }}</th>
                <th>{{ t('project_management.registers.col_kind') }}</th>
                <th>{{ t('project_management.registers.col_severity') }}</th>
                <th>{{ t('project_management.registers.col_assignee') }}</th>
                <th>{{ t('project_management.registers.col_due') }}</th>
                <th>{{ t('project_management.registers.col_status') }}</th>
                <th class="text-end">{{ t('project_management.actions.actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="i in issues" :key="i.id">
                <td class="text-no-wrap">{{ i.code }}</td>
                <td>
                  <div>{{ i.title }}</div>
                  <div v-if="i.from_risk" class="text-caption text-medium-emphasis">
                    {{ t('project_management.registers.from_risk', { code: i.from_risk }) }}
                  </div>
                </td>
                <td>{{ t(`project_management.registers.kind_${i.kind}`) }}</td>
                <td>
                  <VChip size="x-small" label :color="severityColour(i.severity)">
                    {{ t(`project_management.registers.severity_${i.severity}`) }}
                  </VChip>
                </td>
                <td>{{ memberName(i.assigned_to) }}</td>
                <td class="text-no-wrap" :class="i.overdue ? 'text-error' : ''">{{ i.due_date || '—' }}</td>
                <td>
                  <VChip size="x-small" label :color="statusColour(i.status)">
                    {{ t(`project_management.registers.issue_status_${i.status}`) }}
                  </VChip>
                </td>
                <td class="text-end text-no-wrap">
                  <template v-if="can.manage">
                    <VBtn size="small" variant="text" icon="tabler-pencil"
                          :aria-label="t('project_management.registers.edit_named', { code: i.code })"
                          @click="openIssue(i)" />
                    <VBtn v-if="['open', 'in_progress'].includes(i.status)" size="small" variant="text"
                          icon="tabler-circle-check" color="success"
                          :aria-label="t('project_management.registers.resolve_named', { code: i.code })"
                          @click="openResolve(i)" />
                    <VBtn size="small" variant="text" icon="tabler-trash"
                          :aria-label="t('project_management.registers.remove_named', { code: i.code })"
                          @click="remove('issues', i)" />
                  </template>
                </td>
              </tr>
            </tbody>
          </VTable>
          <VCardText v-else class="text-center text-medium-emphasis py-8">
            {{ t('project_management.registers.no_issues') }}
          </VCardText>
        </VWindowItem>

        <!-- ── Change requests ────────────────────────────────────────────── -->
        <VWindowItem value="changes">
          <VCardText class="d-flex align-center justify-space-between flex-wrap gap-2">
            <span class="text-body-2 text-medium-emphasis">{{ t('project_management.registers.changes_sub') }}</span>
            <VBtn v-if="can.manage" size="small" color="primary" prepend-icon="tabler-plus" @click="openChange(null)">
              {{ t('project_management.registers.add_change') }}
            </VBtn>
          </VCardText>

          <VList v-if="changes.length" density="comfortable">
            <VListItem v-for="c in changes" :key="c.id">
              <VListItemTitle class="d-flex align-center gap-2 flex-wrap">
                <span class="font-weight-medium">{{ c.code }}</span>
                <span>{{ c.title }}</span>
                <VChip size="x-small" label :color="statusColour(c.status)">
                  {{ t(`project_management.registers.change_status_${c.status}`) }}
                </VChip>
                <VChip size="x-small" label variant="tonal">
                  {{ t(`project_management.registers.change_kind_${c.kind}`) }}
                </VChip>
              </VListItemTitle>
              <VListItemSubtitle class="text-caption">
                <template v-if="can.costs && c.impact_cost !== null">
                  {{ t('project_management.registers.impact_cost') }}: {{ money(c.impact_cost) }} ·
                </template>
                <template v-if="c.impact_days !== null">
                  {{ t('project_management.registers.impact_days_n', { n: c.impact_days }) }} ·
                </template>
                {{ c.reason || c.description || '—' }}
              </VListItemSubtitle>
              <div v-if="c.decision_note" class="text-caption text-medium-emphasis mt-1">
                {{ t('project_management.registers.decision') }}: {{ c.decision_note }}
              </div>

              <template #append>
                <div class="d-flex align-center gap-1">
                  <VBtn v-if="can.manage && c.editable" size="small" variant="text" icon="tabler-pencil"
                        :aria-label="t('project_management.registers.edit_named', { code: c.code })"
                        @click="openChange(c)" />
                  <VBtn v-if="can.manage && c.editable" size="small" variant="tonal" color="primary"
                        @click="submitChange(c)">
                    {{ t('project_management.registers.submit_for_decision') }}
                  </VBtn>
                  <VBtn v-if="can.manage && c.awaiting_implementation" size="small" variant="tonal" color="success"
                        @click="implementChange(c)">
                    {{ t('project_management.registers.implement') }}
                  </VBtn>
                  <VBtn v-if="can.manage" size="small" variant="text" icon="tabler-trash"
                        :aria-label="t('project_management.registers.remove_named', { code: c.code })"
                        @click="remove('changes', c)" />
                </div>
              </template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-center text-medium-emphasis py-8">
            {{ t('project_management.registers.no_changes') }}
            <div class="text-caption mt-1">{{ t('project_management.registers.no_changes_hint') }}</div>
          </VCardText>
        </VWindowItem>
      </VWindow>
    </VCard>

    <!-- ── Risk dialog ──────────────────────────────────────────────────── -->
    <VDialog v-model="riskDialog" :fullscreen="smAndDown" max-width="760">
      <VCard>
        <VCardItem>
          <VCardTitle>
            {{ editingRisk ? t('project_management.registers.edit_risk') : t('project_management.registers.add_risk') }}
          </VCardTitle>
        </VCardItem>
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="8">
              <VTextField v-model="riskForm.title" :label="t('project_management.registers.field_title')"
                          :error-messages="riskForm.errors.title" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="4">
              <VSelect v-model="riskForm.category"
                       :items="options.risk_categories.map(c => ({ title: t(`project_management.registers.category_${c}`), value: c }))"
                       :label="t('project_management.registers.field_category')"
                       :error-messages="riskForm.errors.category" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="riskForm.description" :label="t('project_management.registers.field_description')"
                         variant="outlined" density="compact" rows="2" />
            </VCol>

            <VCol cols="12" md="5">
              <div class="text-caption mb-1">{{ t('project_management.registers.field_probability') }}: {{ riskForm.probability }}</div>
              <VSlider v-model="riskForm.probability" :min="1" :max="5" :step="1" show-ticks="always"
                       density="compact" hide-details thumb-label />
            </VCol>
            <VCol cols="12" md="5">
              <div class="text-caption mb-1">{{ t('project_management.registers.field_impact') }}: {{ riskForm.impact }}</div>
              <VSlider v-model="riskForm.impact" :min="1" :max="5" :step="1" show-ticks="always"
                       density="compact" hide-details thumb-label />
            </VCol>
            <VCol cols="12" md="2" class="d-flex align-center justify-center">
              <VChip label :color="bandColour(riskBand)">
                {{ riskScore }} · {{ t(`project_management.registers.band_${riskBand}`) }}
              </VChip>
            </VCol>

            <VCol cols="12" md="6">
              <VSelect v-model="riskForm.response_strategy"
                       :items="options.risk_strategies.map(s => ({ title: t(`project_management.registers.strategy_${s}`), value: s }))"
                       :label="t('project_management.registers.field_strategy')"
                       :hint="t('project_management.registers.strategy_hint')" persistent-hint
                       variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect v-model="riskForm.status"
                       :items="options.risk_statuses.filter(s => s !== 'realised').map(s => ({ title: t(`project_management.registers.risk_status_${s}`), value: s }))"
                       :label="t('project_management.registers.field_status')"
                       variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="riskForm.mitigation" :label="t('project_management.registers.field_mitigation')"
                         :hint="t('project_management.registers.mitigation_hint')" persistent-hint
                         variant="outlined" density="compact" rows="2" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="riskForm.contingency" :label="t('project_management.registers.field_contingency')"
                         :hint="t('project_management.registers.contingency_hint')" persistent-hint
                         variant="outlined" density="compact" rows="2" />
            </VCol>
            <VCol cols="12" md="4">
              <VAutocomplete v-model="riskForm.owner_user_id"
                       :items="members.map(m => ({ title: m.name, value: m.id }))"
                       :label="t('project_management.registers.field_owner')" clearable
                       variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField v-model="riskForm.review_due" type="date"
                          :label="t('project_management.registers.field_review_due')"
                          :hint="t('project_management.registers.review_hint')" persistent-hint
                          variant="outlined" density="compact" />
            </VCol>
            <VCol v-if="can.costs" cols="6" md="2">
              <VTextField v-model.number="riskForm.cost_impact" type="number" step="0.01"
                          :label="t('project_management.registers.field_cost_impact')"
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="6" md="2">
              <VTextField v-model.number="riskForm.schedule_impact_days" type="number"
                          :label="t('project_management.registers.field_schedule_days')"
                          variant="outlined" density="compact" />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="riskDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="riskForm.processing" :disabled="!riskForm.title" @click="saveRisk">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Close a risk ─────────────────────────────────────────────────── -->
    <VDialog v-model="closeRiskDialog" :fullscreen="smAndDown" max-width="520">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.registers.close_risk') }}</VCardTitle>
          <VCardSubtitle>{{ closingRisk?.code }} · {{ closingRisk?.title }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VTextarea v-model="closeRiskForm.closure_note"
                     :label="t('project_management.registers.field_closure_note')"
                     :hint="t('project_management.registers.closure_hint')" persistent-hint
                     :error-messages="closeRiskForm.errors.closure_note"
                     variant="outlined" density="compact" rows="3" autofocus />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="closeRiskDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="success" :loading="closeRiskForm.processing"
                :disabled="!closeRiskForm.closure_note" @click="submitCloseRisk">
            {{ t('project_management.registers.close_risk') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Risk realised ────────────────────────────────────────────────── -->
    <VDialog v-model="realiseDialog" :fullscreen="smAndDown" max-width="520">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.registers.realise_risk') }}</VCardTitle>
          <VCardSubtitle>{{ realisingRisk?.code }} · {{ realisingRisk?.title }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VAlert type="info" variant="tonal" density="compact" class="mb-4">
            {{ t('project_management.registers.realise_note') }}
          </VAlert>
          <VSelect v-model="realiseForm.kind"
                   :items="options.issue_kinds.map(k => ({ title: t(`project_management.registers.kind_${k}`), value: k }))"
                   :label="t('project_management.registers.field_kind')" variant="outlined" density="compact" class="mb-3" />
          <VSelect v-model="realiseForm.severity"
                   :items="options.issue_severities.map(s => ({ title: t(`project_management.registers.severity_${s}`), value: s }))"
                   :label="t('project_management.registers.field_severity')" variant="outlined" density="compact" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="realiseDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="error" :loading="realiseForm.processing" @click="submitRealise">
            {{ t('project_management.registers.realise_risk') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Issue dialog ─────────────────────────────────────────────────── -->
    <VDialog v-model="issueDialog" :fullscreen="smAndDown" max-width="720">
      <VCard>
        <VCardItem>
          <VCardTitle>
            {{ editingIssue ? t('project_management.registers.edit_issue') : t('project_management.registers.add_issue') }}
          </VCardTitle>
        </VCardItem>
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="8">
              <VTextField v-model="issueForm.title" :label="t('project_management.registers.field_title')"
                          :error-messages="issueForm.errors.title" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="4">
              <VSelect v-model="issueForm.kind"
                       :items="options.issue_kinds.map(k => ({ title: t(`project_management.registers.kind_${k}`), value: k }))"
                       :label="t('project_management.registers.field_kind')"
                       :hint="t('project_management.registers.kind_hint')" persistent-hint
                       variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="issueForm.description" :label="t('project_management.registers.field_description')"
                         variant="outlined" density="compact" rows="2" />
            </VCol>
            <VCol cols="6" md="3">
              <VSelect v-model="issueForm.severity"
                       :items="options.issue_severities.map(s => ({ title: t(`project_management.registers.severity_${s}`), value: s }))"
                       :label="t('project_management.registers.field_severity')" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="6" md="3">
              <VSelect v-model="issueForm.status"
                       :items="options.issue_statuses.map(s => ({ title: t(`project_management.registers.issue_status_${s}`), value: s }))"
                       :label="t('project_management.registers.field_status')" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="3">
              <VAutocomplete v-model="issueForm.assigned_to"
                       :items="members.map(m => ({ title: m.name, value: m.id }))"
                       :label="t('project_management.registers.field_assignee')" clearable
                       variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="3">
              <VTextField v-model="issueForm.due_date" type="date"
                          :label="t('project_management.registers.field_due')"
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="6">
              <VAutocomplete v-model="issueForm.task_id"
                             :items="tasks.map(tk => ({ title: tk.name, value: tk.id }))"
                             :label="t('project_management.registers.field_task')" clearable
                             :hint="t('project_management.registers.task_hint')" persistent-hint
                             variant="outlined" density="compact" />
            </VCol>
            <VCol v-if="can.costs" cols="12" md="6">
              <VTextField v-model.number="issueForm.cost_impact" type="number" step="0.01"
                          :label="t('project_management.registers.field_cost_impact')"
                          variant="outlined" density="compact" />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="issueDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="issueForm.processing" :disabled="!issueForm.title" @click="saveIssue">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Resolve an issue ─────────────────────────────────────────────── -->
    <VDialog v-model="resolveDialog" :fullscreen="smAndDown" max-width="620">
      <VCard>
        <VCardItem>
          <VCardTitle>{{ t('project_management.registers.resolve_issue') }}</VCardTitle>
          <VCardSubtitle>{{ resolvingIssue?.code }} · {{ resolvingIssue?.title }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VAlert v-if="resolvingIssue?.needs_root_cause" type="warning" variant="tonal"
                  density="compact" class="mb-4">
            {{ t('project_management.registers.root_cause_required') }}
          </VAlert>
          <VTextarea v-model="resolveForm.resolution" :label="t('project_management.registers.field_resolution')"
                     :error-messages="resolveForm.errors.resolution"
                     variant="outlined" density="compact" rows="2" class="mb-3" />
          <VTextarea v-model="resolveForm.root_cause" :label="t('project_management.registers.field_root_cause')"
                     :error-messages="resolveForm.errors.root_cause"
                     variant="outlined" density="compact" rows="2" class="mb-3" />
          <VTextarea v-model="resolveForm.corrective_action"
                     :label="t('project_management.registers.field_corrective')"
                     :hint="t('project_management.registers.corrective_hint')" persistent-hint
                     variant="outlined" density="compact" rows="2" class="mb-3" />
          <VTextarea v-model="resolveForm.preventative_action"
                     :label="t('project_management.registers.field_preventative')"
                     :hint="t('project_management.registers.preventative_hint')" persistent-hint
                     variant="outlined" density="compact" rows="2" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="resolveDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="success" :loading="resolveForm.processing"
                :disabled="!resolveForm.resolution" @click="submitResolve">
            {{ t('project_management.registers.resolve') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Change request dialog ────────────────────────────────────────── -->
    <VDialog v-model="changeDialog" :fullscreen="smAndDown" max-width="760">
      <VCard>
        <VCardItem>
          <VCardTitle>
            {{ editingChange ? t('project_management.registers.edit_change') : t('project_management.registers.add_change') }}
          </VCardTitle>
          <VCardSubtitle>{{ t('project_management.registers.change_sub') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VRow dense>
            <VCol cols="12" md="8">
              <VTextField v-model="changeForm.title" :label="t('project_management.registers.field_title')"
                          :error-messages="changeForm.errors.title" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="4">
              <VSelect v-model="changeForm.kind"
                       :items="options.change_kinds.map(k => ({ title: t(`project_management.registers.change_kind_${k}`), value: k }))"
                       :label="t('project_management.registers.field_kind')" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="changeForm.description" :label="t('project_management.registers.field_description')"
                         variant="outlined" density="compact" rows="2" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="changeForm.reason" :label="t('project_management.registers.field_reason')"
                         :hint="t('project_management.registers.reason_hint')" persistent-hint
                         variant="outlined" density="compact" rows="2" />
            </VCol>

            <VCol cols="12"><VDivider class="my-2" /></VCol>
            <VCol cols="12">
              <div class="text-body-2 font-weight-medium mb-1">{{ t('project_management.registers.impact_analysis') }}</div>
              <p class="text-caption text-medium-emphasis mb-2">{{ t('project_management.registers.impact_note') }}</p>
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="changeForm.impact_scope" :label="t('project_management.registers.field_impact_scope')"
                         variant="outlined" density="compact" rows="2" />
            </VCol>
            <VCol v-if="can.costs" cols="6" md="4">
              <VTextField v-model.number="changeForm.impact_cost" type="number" step="0.01"
                          :label="t('project_management.registers.field_impact_cost')"
                          :error-messages="changeForm.errors.impact_cost"
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="6" md="4">
              <VTextField v-model.number="changeForm.impact_days" type="number"
                          :label="t('project_management.registers.field_impact_days')"
                          :hint="t('project_management.registers.impact_days_hint')" persistent-hint
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" md="4">
              <VTextField v-model="changeForm.impact_quality"
                          :label="t('project_management.registers.field_impact_quality')"
                          variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="changeForm.impact_risk" :label="t('project_management.registers.field_impact_risk')"
                         variant="outlined" density="compact" rows="2" />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="changeDialog = false">{{ t('project_management.actions.cancel') }}</VBtn>
          <VBtn color="primary" :loading="changeForm.processing" :disabled="!changeForm.title" @click="saveChange">
            {{ t('project_management.actions.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmer" />
  </div>
</template>
