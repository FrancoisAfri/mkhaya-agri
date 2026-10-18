<script setup lang="ts">
import { ref, reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import NudgeWidget from '../Components/NudgeWidget.vue'

const { t } = useI18n()

type Snapshot   = { id: number; overall_score: number | null; kpi_completion_rate: number | null; snapshot_date: string | null; cycle: string | null; appraisal_status: string | null; employee_score: number | null; manager_score: number | null; score_360: number | null; final_score: number | null; employee_comments: string | null; manager_comments: string | null; submitted_at: string | null; approved_at: string | null }
type ProgressLogEntry = { value: number; notes: string | null; log_date: string }
type Kpi        = { id: number; title: string; target_value: number | null; unit: string | null; weighting: number | null; status: string; cycle: string | null; current: number | null; score_pct: number | null; self_update_allowed: boolean; progress_logs_count: number; recent_logs: ProgressLogEntry[] }
type Checkin    = { id: number; title: string | null; scheduled_at: string; location: string | null; status: string }
type Feedback   = { id: number; feedback_type: string; content: string; rating: number | null; is_anonymous: boolean; author: string | null; acknowledged_at: string | null; created_at: string }
type Coaching   = { id: number; title: string | null; session_date: string; status: string; objectives: string | null; outcomes: string | null }
type Pip        = { id: number; title: string; start_date: string | null; target_end: string | null; status: string; days_remaining: number; progress: number; objectives_met: number; objectives_total: number }
type Review360  = { id: number; title: string; status: string; cycle: string | null; due_date: string | null; completion_rate: number }
type Pending360 = { rater_id: number; review_id: number; review_title: string; relationship: string; due_date: string | null }
type Development = { id: number; title: string; cycle: string | null; created_at: string }
type Probation        = { id: number; probation_type: string; status: string; start_date: string | null; end_date: string | null; days_remaining: number }
type ProbationCheckin = { id: number; checkin_type: string; scheduled_at: string; agenda: string | null; status: string }
type ProbationFeedback = { id: number; feedback_type: string; content: string; rating: number | null; author: string; created_at: string }
type Employee   = { id: number; name: string }

const props = defineProps<{
  snapshot:            Snapshot | null
  kpis:                Kpi[]
  checkins:            Checkin[]
  feedback:            Feedback[]
  coaching:            Coaching[]
  pip:                 Pip | null
  reviews360:          Review360[]
  pending360:          Pending360[]
  development:         Development[]
  probation:           Probation | null
  probation_checkins:  ProbationCheckin[]
  probation_feedback:  ProbationFeedback[]
  employees:           Employee[]
  has_reports:         boolean
}>()

const tab = ref('overview')

// ── Log Progress dialog ───────────────────────────────────────────────────────
const logDialog  = ref(false)
const logTarget  = ref<Kpi | null>(null)
const logSaving  = ref(false)
const logForm    = reactive({ value: '' as string | number, notes: '', log_date: new Date().toISOString().slice(0, 10) })

function openLogProgress(kpi: Kpi) {
  logTarget.value = kpi
  logForm.value   = kpi.current ?? ''
  logForm.notes   = ''
  logForm.log_date = new Date().toISOString().slice(0, 10)
  logDialog.value = true
}

function submitLogProgress() {
  if (!logTarget.value) return
  logSaving.value = true
  router.post(route('performance.kpis.progress.log', logTarget.value.id), logForm as any, {
    onSuccess: () => { logDialog.value = false },
    onFinish:  () => { logSaving.value = false },
  })
}

// ── Give Feedback dialog ──────────────────────────────────────────────────────
const fbDialog  = ref(false)
const fbSaving  = ref(false)
const fbForm    = reactive({
  employee_id:   null as number | null,
  feedback_type: 'peer' as string,
  content:       '',
  rating:        null as number | null,
  is_anonymous:  false,
  is_draft:      false,
})

function openFeedback() {
  Object.assign(fbForm, { employee_id: null, feedback_type: 'peer', content: '', rating: null, is_anonymous: false, is_draft: false })
  fbDialog.value = true
}

function submitFeedback() {
  fbSaving.value = true
  router.post(route('performance.feedback.store'), fbForm as any, {
    onSuccess: () => { fbDialog.value = false },
    onFinish:  () => { fbSaving.value = false },
  })
}

// ── Self-assessment submit dialog ─────────────────────────────────────────────
const selfOpen    = ref(false)
const selfLoading = ref(false)
const selfForm    = reactive({ employee_score: '' as number | string, employee_comments: '' })

function openSelfSubmit() {
  selfForm.employee_score    = props.snapshot?.employee_score ?? ''
  selfForm.employee_comments = props.snapshot?.employee_comments ?? ''
  selfOpen.value = true
}

function submitSelfAssessment() {
  if (!props.snapshot) return
  selfLoading.value = true
  router.post(route('performance.appraisal-review.submit', props.snapshot.id), selfForm as any, {
    onSuccess: () => { selfOpen.value = false },
    onFinish:  () => { selfLoading.value = false },
  })
}

// ── KPI row expansion ─────────────────────────────────────────────────────────
const kpiExpanded = ref<Set<number>>(new Set())
function toggleKpi(id: number) {
  if (kpiExpanded.value.has(id)) kpiExpanded.value.delete(id)
  else kpiExpanded.value.add(id)
}

// ── Helpers ───────────────────────────────────────────────────────────────────
const statusColor = (s: string) =>
  ({ active: 'success', completed: 'primary', pending: 'warning', scheduled: 'info', in_progress: 'primary', closed: 'default', cancelled: 'error' })[s] ?? 'default'

const scoreColor = (score: number | null) => {
  if (score === null) return 'default'
  if (score >= 80) return 'success'
  if (score >= 60) return 'warning'
  return 'error'
}

const achievementPct = (kpi: Kpi) => {
  if (kpi.current == null || !kpi.target_value) return null
  return Math.min(100, Math.round((kpi.current / kpi.target_value) * 100))
}

const feedbackTypes = ['manager', 'peer', 'upward']
</script>

<template>
  <div class="pa-4">
    <!-- Header row with Give Feedback action -->
    <div class="d-flex align-center mb-6">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('pa.my_dashboard.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">{{ t('pa.my_dashboard.subtitle') }}</p>
      </div>
      <VSpacer />
      <div class="d-flex ga-2">
        <VBtn
          v-if="props.has_reports"
          color="secondary"
          variant="tonal"
          prepend-icon="ri-team-line"
          @click="router.visit(route('performance.manager-dashboard'))"
        >
          {{ t('pa.my_dashboard.team_performance') }}
        </VBtn>
        <VBtn color="primary" prepend-icon="ri-chat-3-line" @click="openFeedback">
          {{ t('pa.my_dashboard.give_feedback') }}
        </VBtn>
      </div>
    </div>

    <!-- Proactive AI nudges -->
    <NudgeWidget />

    <!-- Score Cards Row -->
    <VRow class="mb-4">
      <VCol cols="12" sm="6" md="3">
        <VCard class="text-center pa-4">
          <VIcon icon="ri-bar-chart-2-line" size="32" :color="scoreColor(props.snapshot?.overall_score ?? null)" class="mb-2" />
          <div class="text-h4 font-weight-bold" :class="props.snapshot ? '' : 'text-medium-emphasis'">
            {{ props.snapshot?.overall_score != null ? props.snapshot.overall_score.toFixed(1) + '%' : '—' }}
          </div>
          <div class="text-caption text-medium-emphasis">{{ t('pa.my_dashboard.overall_score') }}</div>
          <div v-if="props.snapshot?.cycle" class="text-caption text-medium-emphasis mt-1">{{ props.snapshot.cycle }}</div>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard class="text-center pa-4">
          <VIcon icon="ri-checkbox-circle-line" size="32" color="primary" class="mb-2" />
          <div class="text-h4 font-weight-bold" :class="props.snapshot ? '' : 'text-medium-emphasis'">
            {{ props.snapshot?.kpi_completion_rate != null ? props.snapshot.kpi_completion_rate.toFixed(1) + '%' : '—' }}
          </div>
          <div class="text-caption text-medium-emphasis">{{ t('pa.my_dashboard.kpi_completion') }}</div>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard class="text-center pa-4">
          <VIcon icon="ri-calendar-check-line" size="32" color="info" class="mb-2" />
          <div class="text-h4 font-weight-bold">{{ props.checkins.length }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('pa.my_dashboard.checkins') }}</div>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard class="text-center pa-4">
          <VIcon icon="ri-survey-line" size="32" :color="props.pending360.length ? 'warning' : 'success'" class="mb-2" />
          <div class="text-h4 font-weight-bold">{{ props.pending360.length }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('pa.my_dashboard.pending_360s') }}</div>
        </VCard>
      </VCol>
    </VRow>

    <!-- Tabs -->
    <VTabs v-model="tab" color="primary" class="mb-4">
      <VTab value="overview">{{ t('pa.my_dashboard.tab_overview') }}</VTab>
      <VTab value="kpis">
        {{ t('pa.my_dashboard.tab_kpis') }}
        <VBadge v-if="props.kpis.filter(k => k.self_update_allowed).length" :content="props.kpis.filter(k => k.self_update_allowed).length" color="primary" inline class="ml-1" />
      </VTab>
      <VTab value="checkins">{{ t('pa.my_dashboard.tab_checkins') }}</VTab>
      <VTab value="feedback">{{ t('pa.my_dashboard.tab_feedback') }}</VTab>
      <VTab value="coaching">{{ t('pa.my_dashboard.tab_coaching') }}</VTab>
      <VTab value="reviews360">
        {{ t('pa.my_dashboard.tab_360') }}
        <VBadge v-if="props.pending360.length" :content="props.pending360.length" color="warning" inline class="ml-1" />
      </VTab>
      <VTab value="development">{{ t('pa.my_dashboard.tab_development') }}</VTab>
    </VTabs>

    <VWindow v-model="tab">

      <!-- Overview Tab -->
      <VWindowItem value="overview">
        <VRow>
          <!-- Performance Snapshot -->
          <VCol cols="12" md="6">
            <VCard>
              <VCardTitle>{{ t('pa.my_dashboard.overall_score') }}</VCardTitle>
              <VCardText>
                <div v-if="props.snapshot" class="d-flex align-center gap-4">
                  <VProgressCircular :model-value="props.snapshot.overall_score ?? 0" :color="scoreColor(props.snapshot.overall_score)" size="80" width="8">
                    <span class="text-body-2 font-weight-bold">{{ props.snapshot.overall_score?.toFixed(0) ?? '—' }}%</span>
                  </VProgressCircular>
                  <div class="flex-grow-1">
                    <div class="text-body-2">{{ t('pa.my_dashboard.kpi_completion') }}: <strong>{{ props.snapshot.kpi_completion_rate?.toFixed(1) }}%</strong></div>
                    <div class="text-caption text-medium-emphasis mt-1">{{ props.snapshot.cycle }}</div>
                    <div class="text-caption text-medium-emphasis">{{ props.snapshot.snapshot_date }}</div>
                    <!-- Appraisal status row -->
                    <div class="mt-2 d-flex align-center gap-2 flex-wrap">
                      <VChip
                        v-if="props.snapshot.appraisal_status"
                        size="x-small"
                        :color="{ draft: 'grey', employee_submitted: 'blue', manager_review: 'orange', approved: 'green', rejected: 'red' }[props.snapshot.appraisal_status] ?? 'grey'"
                        variant="tonal"
                      >
                        {{ { draft: 'Draft', employee_submitted: 'Submitted', manager_review: 'Under Review', approved: 'Approved', rejected: 'Returned' }[props.snapshot.appraisal_status] ?? props.snapshot.appraisal_status }}
                      </VChip>
                      <VChip v-if="props.snapshot.final_score !== null" size="x-small" color="primary" variant="flat">
                        Final: {{ props.snapshot.final_score?.toFixed(1) }}%
                      </VChip>
                    </div>
                    <VBtn
                      v-if="props.snapshot.appraisal_status === 'draft' || props.snapshot.appraisal_status === 'rejected'"
                      size="x-small" color="primary" variant="tonal" class="mt-2"
                      @click="openSelfSubmit"
                    >
                      <VIcon start size="14">tabler-send</VIcon>
                      Submit Self-Assessment
                    </VBtn>
                    <div v-if="props.snapshot.appraisal_status === 'approved'" class="mt-2">
                      <div class="text-caption text-medium-emphasis">
                        Self: {{ props.snapshot.employee_score?.toFixed(1) ?? '—' }}% &nbsp;|&nbsp;
                        Mgr: {{ props.snapshot.manager_score?.toFixed(1) ?? '—' }}% &nbsp;|&nbsp;
                        360: {{ props.snapshot.score_360?.toFixed(1) ?? '—' }}%
                      </div>
                    </div>
                  </div>
                </div>
                <div v-else class="text-medium-emphasis text-body-2">{{ t('pa.my_dashboard.no_snapshot') }}</div>
              </VCardText>
            </VCard>
          </VCol>

          <!-- Pending 360s summary -->
          <VCol cols="12" md="6">
            <VCard>
              <VCardTitle>{{ t('pa.my_dashboard.pending_360s') }}</VCardTitle>
              <VCardText>
                <div v-if="props.pending360.length === 0" class="text-medium-emphasis text-body-2">
                  {{ t('pa.my_dashboard.no_pending_360s') }}
                </div>
                <VList v-else density="compact" class="pa-0">
                  <VListItem v-for="r in props.pending360" :key="r.rater_id" :subtitle="r.due_date ? `Due ${r.due_date}` : r.relationship">
                    <template #title><span class="text-body-2">{{ r.review_title }}</span></template>
                    <template #append>
                      <VBtn size="x-small" color="primary" variant="tonal" @click="router.visit(route('performance.360.respond', r.rater_id))">
                        {{ t('pa.my_dashboard.respond') }}
                      </VBtn>
                    </template>
                  </VListItem>
                </VList>
              </VCardText>
            </VCard>
          </VCol>

          <!-- Active PIP -->
          <VCol v-if="props.pip" cols="12" md="6">
            <VCard>
              <VCardTitle>{{ t('pa.my_dashboard.pip') }}</VCardTitle>
              <VCardText>
                <div class="d-flex justify-space-between mb-2">
                  <span class="text-body-2 font-weight-medium">{{ props.pip.title }}</span>
                  <VChip size="x-small" color="warning">{{ t('pa.my_dashboard.pip_active') }}</VChip>
                </div>
                <VProgressLinear :model-value="props.pip.progress" color="warning" rounded height="8" class="mb-2" />
                <div class="d-flex justify-space-between text-caption text-medium-emphasis">
                  <span>{{ t('pa.my_dashboard.pip_objectives') }}: {{ props.pip.objectives_met }}/{{ props.pip.objectives_total }}</span>
                  <span>{{ props.pip.days_remaining }} {{ t('pa.my_dashboard.days_remaining') }}</span>
                </div>
              </VCardText>
            </VCard>
          </VCol>

          <!-- Probation -->
          <VCol v-if="props.probation" cols="12">
            <VCard>
              <VCardTitle class="d-flex align-center justify-space-between">
                <span>{{ t('pa.my_dashboard.probation') }}</span>
                <div class="d-flex align-center gap-2">
                  <VChip size="small" :color="statusColor(props.probation.status)">{{ props.probation.status }}</VChip>
                  <span class="text-caption text-medium-emphasis">
                    {{ props.probation.days_remaining > 0 ? props.probation.days_remaining + ' days remaining' : 'Overdue' }}
                  </span>
                </div>
              </VCardTitle>
              <VCardSubtitle>{{ props.probation.probation_type }} · {{ props.probation.start_date }} – {{ props.probation.end_date }}</VCardSubtitle>

              <!-- Probation tabs -->
              <VTabs density="compact" class="mt-1">
                <VTab value="prob_checkins">
                  <VIcon start size="14">tabler-calendar-check</VIcon>
                  Check-Ins
                  <VBadge v-if="props.probation_checkins.length" :content="props.probation_checkins.length" color="info" inline />
                </VTab>
                <VTab value="prob_feedback">
                  <VIcon start size="14">tabler-message-dots</VIcon>
                  Feedback
                  <VBadge v-if="props.probation_feedback.length" :content="props.probation_feedback.length" color="primary" inline />
                </VTab>
              </VTabs>

              <VTabsWindow>
                <VTabsWindowItem value="prob_checkins">
                  <VCardText class="pt-2">
                    <div v-if="!props.probation_checkins.length" class="text-body-2 text-medium-emphasis py-2">
                      No upcoming probation check-ins.
                    </div>
                    <VList v-else density="compact" class="pa-0">
                      <VListItem
                        v-for="c in props.probation_checkins"
                        :key="c.id"
                        :title="(c.checkin_type === 'formal' ? 'Formal' : 'Informal') + ' Check-In'"
                        :subtitle="c.scheduled_at + (c.agenda ? ' · ' + c.agenda : '')"
                        prepend-icon="tabler-calendar-check"
                      >
                        <template #append>
                          <VChip size="x-small" color="info" variant="tonal">{{ c.status }}</VChip>
                        </template>
                      </VListItem>
                    </VList>
                  </VCardText>
                </VTabsWindowItem>

                <VTabsWindowItem value="prob_feedback">
                  <VCardText class="pt-2">
                    <div v-if="!props.probation_feedback.length" class="text-body-2 text-medium-emphasis py-2">
                      No feedback received yet.
                    </div>
                    <VList v-else density="compact" class="pa-0">
                      <VListItem
                        v-for="f in props.probation_feedback"
                        :key="f.id"
                        prepend-icon="tabler-message-dots"
                      >
                        <template #title>
                          <div class="d-flex align-center gap-2">
                            <VChip size="x-small" color="primary" variant="tonal">{{ f.feedback_type }}</VChip>
                            <span class="text-caption text-medium-emphasis">{{ f.author }} · {{ f.created_at }}</span>
                            <span v-if="f.rating" class="text-caption">⭐ {{ f.rating }}/5</span>
                          </div>
                        </template>
                        <template #subtitle>
                          <span class="text-body-2">{{ f.content }}</span>
                        </template>
                      </VListItem>
                    </VList>
                  </VCardText>
                </VTabsWindowItem>
              </VTabsWindow>
            </VCard>
          </VCol>

          <!-- Upcoming check-ins summary -->
          <VCol cols="12" md="6">
            <VCard>
              <VCardTitle>{{ t('pa.my_dashboard.checkins') }}</VCardTitle>
              <VCardText>
                <div v-if="props.checkins.length === 0" class="text-medium-emphasis text-body-2">{{ t('pa.my_dashboard.no_checkins') }}</div>
                <VList v-else density="compact" class="pa-0">
                  <VListItem v-for="c in props.checkins.slice(0, 3)" :key="c.id" :subtitle="c.scheduled_at">
                    <template #title><span class="text-body-2">{{ c.title || t('pa.my_dashboard.checkins') }}</span></template>
                    <template #append><VChip size="x-small" color="info">{{ c.status }}</VChip></template>
                  </VListItem>
                </VList>
              </VCardText>
            </VCard>
          </VCol>

          <!-- KPI Progress overview -->
          <VCol cols="12">
            <VCard>
              <VCardTitle class="d-flex align-center">
                {{ t('pa.my_dashboard.kpis') }}
                <VSpacer />
                <VBtn size="small" variant="text" color="primary" @click="tab = 'kpis'">View all</VBtn>
              </VCardTitle>
              <VCardText v-if="props.kpis.length === 0" class="text-medium-emphasis text-body-2">{{ t('pa.my_dashboard.no_kpis') }}</VCardText>
              <div v-else class="px-4 pb-4">
                <div v-for="kpi in props.kpis.slice(0, 4)" :key="kpi.id" class="mb-4">
                  <div class="d-flex align-center justify-space-between mb-1">
                    <span class="text-body-2 font-weight-medium">{{ kpi.title }}</span>
                    <div class="d-flex align-center gap-2">
                      <VChip v-if="kpi.score_pct !== null" size="x-small" :color="scoreColor(kpi.score_pct)" label>
                        {{ kpi.score_pct }}%
                      </VChip>
                      <span class="text-caption text-medium-emphasis">
                        {{ kpi.current != null ? kpi.current : '—' }}{{ kpi.unit ? ' ' + kpi.unit : '' }}
                        / {{ kpi.target_value != null ? kpi.target_value : '?' }}{{ kpi.unit ? ' ' + kpi.unit : '' }}
                      </span>
                    </div>
                  </div>
                  <VProgressLinear
                    :model-value="achievementPct(kpi) ?? 0"
                    :color="achievementPct(kpi) == null ? 'surface-variant' : achievementPct(kpi)! >= 80 ? 'success' : achievementPct(kpi)! >= 50 ? 'primary' : 'warning'"
                    height="6" rounded bg-color="surface-variant"
                  />
                  <div class="d-flex justify-space-between mt-1">
                    <span class="text-caption text-medium-emphasis">
                      {{ kpi.progress_logs_count }} {{ kpi.progress_logs_count === 1 ? 'entry' : 'entries' }}
                      <span v-if="kpi.recent_logs.length"> · Last: {{ kpi.recent_logs[0].log_date }}</span>
                    </span>
                    <span class="text-caption font-weight-medium">{{ achievementPct(kpi) != null ? achievementPct(kpi) + '%' : 'No data' }}</span>
                  </div>
                </div>
              </div>
            </VCard>
          </VCol>
        </VRow>
      </VWindowItem>

      <!-- KPIs Tab -->
      <VWindowItem value="kpis">
        <div v-if="props.kpis.length === 0" class="text-medium-emphasis text-body-2 py-8 text-center">
          {{ t('pa.my_dashboard.no_kpis') }}
        </div>
        <div v-else class="d-flex flex-column gap-3">
          <VCard v-for="kpi in props.kpis" :key="kpi.id" border elevation="0">
            <VCardText class="pb-2">
              <!-- KPI header row -->
              <div class="d-flex align-center gap-2 mb-2">
                <div class="flex-grow-1">
                  <div class="text-body-2 font-weight-semibold">{{ kpi.title }}</div>
                  <div class="text-caption text-medium-emphasis">{{ kpi.cycle ?? '—' }} · {{ kpi.weighting }}% weight</div>
                </div>
                <VChip size="x-small" :color="statusColor(kpi.status)" variant="tonal" label>{{ kpi.status }}</VChip>
                <VChip v-if="kpi.score_pct !== null" size="x-small" :color="scoreColor(kpi.score_pct)" label>
                  Score {{ kpi.score_pct }}%
                </VChip>
              </div>

              <!-- Progress bar -->
              <div class="d-flex justify-space-between text-caption mb-1">
                <span class="text-medium-emphasis">
                  Current: <strong>{{ kpi.current != null ? kpi.current + (kpi.unit ? ' ' + kpi.unit : '') : '—' }}</strong>
                </span>
                <span class="text-medium-emphasis">
                  Target: <strong>{{ kpi.target_value != null ? kpi.target_value + (kpi.unit ? ' ' + kpi.unit : '') : '—' }}</strong>
                  <span v-if="achievementPct(kpi) !== null" class="ml-1 font-weight-bold">· {{ achievementPct(kpi) }}%</span>
                </span>
              </div>
              <VProgressLinear
                :model-value="achievementPct(kpi) ?? 0"
                :color="achievementPct(kpi) == null ? 'surface-variant' : achievementPct(kpi)! >= 80 ? 'success' : achievementPct(kpi)! >= 50 ? 'primary' : 'warning'"
                height="8" rounded bg-color="surface-variant" class="mb-2"
              />

              <!-- Actions + history toggle -->
              <div class="d-flex align-center gap-2 mt-2">
                <VBtn v-if="kpi.self_update_allowed" size="x-small" color="primary" variant="tonal" prepend-icon="ri-line-chart-line" @click="openLogProgress(kpi)">
                  {{ t('pa.my_dashboard.log_progress') }}
                </VBtn>
                <VBtn size="x-small" variant="text" :href="`/performance/kpis/${kpi.id}/progress`" prepend-icon="ri-eye-line">
                  View full history
                </VBtn>
                <VSpacer />
                <VBtn
                  v-if="kpi.recent_logs.length"
                  size="x-small" variant="text"
                  :append-icon="kpiExpanded.has(kpi.id) ? 'tabler-chevron-up' : 'tabler-chevron-down'"
                  @click="toggleKpi(kpi.id)"
                >
                  {{ kpi.progress_logs_count }} {{ kpi.progress_logs_count === 1 ? 'entry' : 'entries' }}
                </VBtn>
                <span v-else class="text-caption text-medium-emphasis">No entries yet</span>
              </div>
            </VCardText>

            <!-- Recent logs (expandable) -->
            <VExpandTransition>
              <div v-if="kpiExpanded.has(kpi.id)">
                <VDivider />
                <VCardText class="pt-2 pb-3">
                  <div class="text-caption text-medium-emphasis mb-2 font-weight-medium">Recent progress</div>
                  <div
                    v-for="(log, idx) in kpi.recent_logs"
                    :key="idx"
                    class="d-flex align-start gap-3 mb-2"
                  >
                    <VChip size="x-small" color="primary" variant="tonal" label class="mt-1" style="min-width:60px; justify-content:center">
                      {{ log.value }}{{ kpi.unit ? ' ' + kpi.unit : '' }}
                    </VChip>
                    <div>
                      <div class="text-caption font-weight-medium">{{ log.log_date }}</div>
                      <div v-if="log.notes" class="text-caption text-medium-emphasis">{{ log.notes }}</div>
                    </div>
                  </div>
                </VCardText>
              </div>
            </VExpandTransition>
          </VCard>
        </div>
      </VWindowItem>

      <!-- Check-Ins Tab -->
      <VWindowItem value="checkins">
        <VCard>
          <VCardTitle>{{ t('pa.my_dashboard.checkins') }}</VCardTitle>
          <VCardText v-if="props.checkins.length === 0" class="text-medium-emphasis">{{ t('pa.my_dashboard.no_checkins') }}</VCardText>
          <VTable v-else>
            <thead>
              <tr>
                <th>{{ t('pa.common.title') }}</th>
                <th>{{ t('pa.my_dashboard.scheduled') }}</th>
                <th>{{ t('pa.common.location') }}</th>
                <th>{{ t('pa.common.status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in props.checkins" :key="c.id">
                <td>{{ c.title || '—' }}</td>
                <td>{{ c.scheduled_at }}</td>
                <td>{{ c.location || '—' }}</td>
                <td><VChip size="x-small" color="info">{{ c.status }}</VChip></td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VWindowItem>

      <!-- Feedback Tab -->
      <VWindowItem value="feedback">
        <VCard>
          <VCardTitle class="d-flex align-center">
            {{ t('pa.my_dashboard.feedback') }}
            <VSpacer />
            <VBtn size="small" color="primary" variant="tonal" prepend-icon="ri-chat-3-line" @click="openFeedback">
              {{ t('pa.my_dashboard.give_feedback') }}
            </VBtn>
          </VCardTitle>
          <VCardText v-if="props.feedback.length === 0" class="text-medium-emphasis">{{ t('pa.my_dashboard.no_feedback') }}</VCardText>
          <div v-else class="pa-2">
            <VCard v-for="fb in props.feedback" :key="fb.id" variant="outlined" class="mb-3">
              <VCardText>
                <div class="d-flex align-center gap-2 mb-2">
                  <VChip size="x-small" color="primary">{{ fb.feedback_type }}</VChip>
                  <VChip v-if="fb.rating" size="x-small" :color="scoreColor(fb.rating * 20)">{{ fb.rating }}/5</VChip>
                  <VSpacer />
                  <span class="text-caption text-medium-emphasis">
                    {{ fb.is_anonymous ? t('pa.my_dashboard.anonymous') : fb.author }} · {{ fb.created_at }}
                  </span>
                </div>
                <p class="text-body-2 mb-0">{{ fb.content }}</p>
                <div v-if="fb.acknowledged_at" class="text-caption text-success mt-1">✓ {{ fb.acknowledged_at }}</div>
              </VCardText>
            </VCard>
          </div>
        </VCard>
      </VWindowItem>

      <!-- Coaching Tab -->
      <VWindowItem value="coaching">
        <VCard>
          <VCardTitle>{{ t('pa.my_dashboard.coaching') }}</VCardTitle>
          <VCardText v-if="props.coaching.length === 0" class="text-medium-emphasis">{{ t('pa.my_dashboard.no_coaching') }}</VCardText>
          <VTable v-else>
            <thead>
              <tr>
                <th>{{ t('pa.common.title') }}</th>
                <th>{{ t('pa.common.date') }}</th>
                <th>{{ t('pa.common.status') }}</th>
                <th>{{ t('pa.common.notes') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in props.coaching" :key="s.id">
                <td>{{ s.title || '—' }}</td>
                <td>{{ s.session_date }}</td>
                <td><VChip size="x-small" :color="statusColor(s.status)">{{ s.status }}</VChip></td>
                <td class="text-caption">{{ s.outcomes || s.objectives || '—' }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VWindowItem>

      <!-- 360 Reviews Tab -->
      <VWindowItem value="reviews360">
        <!-- Pending responses (user as rater) -->
        <VCard class="mb-4" v-if="props.pending360.length">
          <VCardTitle class="d-flex align-center">
            {{ t('pa.my_dashboard.pending_360s') }}
            <VBadge :content="props.pending360.length" color="warning" inline class="ml-2" />
          </VCardTitle>
          <VCardText class="pa-0">
            <VList>
              <VListItem
                v-for="r in props.pending360"
                :key="r.rater_id"
                :subtitle="r.relationship + (r.due_date ? ' · Due ' + r.due_date : '')"
              >
                <template #title><span class="text-body-2 font-weight-medium">{{ r.review_title }}</span></template>
                <template #append>
                  <VBtn color="primary" variant="tonal" size="small" @click="router.visit(route('performance.360.respond', r.rater_id))">
                    {{ t('pa.my_dashboard.respond') }}
                  </VBtn>
                </template>
              </VListItem>
            </VList>
          </VCardText>
        </VCard>

        <!-- All 360 reviews where user is subject -->
        <VCard>
          <VCardTitle>{{ t('pa.my_dashboard.reviews_360') }}</VCardTitle>
          <VCardText v-if="props.reviews360.length === 0" class="text-medium-emphasis">{{ t('pa.my_dashboard.no_reviews') }}</VCardText>
          <VTable v-else>
            <thead>
              <tr>
                <th>{{ t('pa.common.title') }}</th>
                <th>{{ t('pa.my_dashboard.cycle') }}</th>
                <th>{{ t('pa.common.status') }}</th>
                <th>{{ t('pa.common.due_date') }}</th>
                <th>{{ t('pa.my_dashboard.kpi_completion') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="rev in props.reviews360" :key="rev.id">
                <td>{{ rev.title }}</td>
                <td class="text-caption text-medium-emphasis">{{ rev.cycle || '—' }}</td>
                <td><VChip size="x-small" :color="statusColor(rev.status)">{{ rev.status }}</VChip></td>
                <td class="text-caption text-medium-emphasis">{{ rev.due_date || '—' }}</td>
                <td>
                  <VProgressLinear :model-value="rev.completion_rate" rounded height="6" color="primary" />
                  <span class="text-caption">{{ rev.completion_rate }}%</span>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VWindowItem>

      <!-- Development Tab -->
      <VWindowItem value="development">
        <VCard>
          <VCardTitle>{{ t('pa.my_dashboard.development') }}</VCardTitle>
          <VCardText v-if="props.development.length === 0" class="text-medium-emphasis">{{ t('pa.my_dashboard.no_development') }}</VCardText>
          <VTable v-else>
            <thead>
              <tr>
                <th>{{ t('pa.common.title') }}</th>
                <th>{{ t('pa.my_dashboard.cycle') }}</th>
                <th>{{ t('pa.common.created') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="plan in props.development" :key="plan.id">
                <td>{{ plan.title }}</td>
                <td class="text-caption text-medium-emphasis">{{ plan.cycle || '—' }}</td>
                <td class="text-caption text-medium-emphasis">{{ plan.created_at }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VWindowItem>

    </VWindow>

    <!-- Log Progress Dialog -->
    <VDialog v-model="logDialog" max-width="440">
      <VCard>
        <VCardTitle>{{ t('pa.my_dashboard.log_progress_title') }}</VCardTitle>
        <VCardSubtitle v-if="logTarget">{{ logTarget.title }}</VCardSubtitle>
        <VCardText class="pt-2">
          <VTextField
            v-model="logForm.value"
            :label="t('pa.my_dashboard.log_value') + (logTarget?.unit ? ` (${logTarget.unit})` : '')"
            type="number"
            density="compact"
            variant="outlined"
            class="mb-3"
          />
          <VTextField
            v-model="logForm.log_date"
            :label="t('pa.my_dashboard.log_date')"
            type="date"
            density="compact"
            variant="outlined"
            class="mb-3"
          />
          <VTextarea
            v-model="logForm.notes"
            :label="t('pa.my_dashboard.log_notes')"
            density="compact"
            variant="outlined"
            rows="2"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="logDialog = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="logSaving" @click="submitLogProgress">{{ t('pa.my_dashboard.log_progress') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Give Feedback Dialog -->
    <VDialog v-model="fbDialog" max-width="500">
      <VCard>
        <VCardTitle>{{ t('pa.my_dashboard.give_feedback') }}</VCardTitle>
        <VCardText class="pt-2">
          <VAutocomplete
            v-model="fbForm.employee_id"
            :items="props.employees"
            item-title="name"
            item-value="id"
            :label="t('pa.my_dashboard.feedback_for')"
            density="compact"
            variant="outlined"
            class="mb-3"
          />
          <VSelect
            v-model="fbForm.feedback_type"
            :items="feedbackTypes"
            :label="t('pa.my_dashboard.feedback_type_label')"
            density="compact"
            variant="outlined"
            class="mb-3"
          />
          <VTextarea
            v-model="fbForm.content"
            :label="t('pa.my_dashboard.feedback_content')"
            density="compact"
            variant="outlined"
            rows="3"
            class="mb-3"
          />
          <VTextField
            v-model="fbForm.rating"
            :label="t('pa.my_dashboard.rating_optional')"
            type="number"
            :min="1"
            :max="5"
            density="compact"
            variant="outlined"
            class="mb-3"
          />
          <VSwitch
            v-model="fbForm.is_anonymous"
            :label="t('pa.my_dashboard.anonymous_label')"
            color="primary"
            inset
            density="compact"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="fbDialog = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="fbSaving" :disabled="!fbForm.employee_id || !fbForm.content" @click="submitFeedback">
            {{ t('pa.my_dashboard.submit_feedback') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Self-Assessment Submit Dialog -->
    <VDialog v-model="selfOpen" max-width="500">
      <VCard>
        <VToolbar color="primary" density="compact">
          <VToolbarTitle>Submit Self-Assessment</VToolbarTitle>
          <VBtn icon @click="selfOpen = false"><VIcon>tabler-x</VIcon></VBtn>
        </VToolbar>
        <VCardText class="pt-4">
          <VAlert type="info" variant="tonal" density="compact" class="mb-4 text-caption">
            KPI auto-score: <strong>{{ props.snapshot?.overall_score?.toFixed(1) ?? '—' }}%</strong>
            &nbsp;·&nbsp; {{ props.snapshot?.cycle }} &nbsp;·&nbsp; {{ props.snapshot?.snapshot_date }}
          </VAlert>
          <VTextField
            v-model.number="selfForm.employee_score"
            label="Your Overall Score (%)"
            type="number" min="0" max="100" step="0.5"
            variant="outlined" density="compact" class="mb-3"
            :rules="[v => (v !== '' && Number(v) >= 0 && Number(v) <= 100) || 'Required, 0–100']"
          />
          <VTextarea
            v-model="selfForm.employee_comments"
            label="Your Comments / Reflection (optional)"
            rows="4" variant="outlined" density="compact"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="selfOpen = false">Cancel</VBtn>
          <VBtn
            color="primary" variant="flat" :loading="selfLoading"
            :disabled="selfForm.employee_score === '' || Number(selfForm.employee_score) < 0 || Number(selfForm.employee_score) > 100"
            @click="submitSelfAssessment"
          >
            Submit for Manager Review
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

  </div>
</template>
