<script setup lang="ts">
import { ref, reactive, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import VueApexCharts from 'vue3-apexcharts'
import NudgeWidget from '../Components/NudgeWidget.vue'

const { t } = useI18n()

// ── Types ────────────────────────────────────────────────────────────────────
type Cycle      = { id: number; name: string; status: string }
type Snapshot   = { overall_score: number | null; kpi_completion_rate: number | null; snapshot_date: string | null; cycle: string | null }
type ProgressLogEntry = { value: number; notes: string | null; log_date: string }
type Kpi        = { id: number; title: string; weighting: number; status: string; cycle: string | null; current: number | null; target_value: number | null; unit: string | null; score_pct: number | null; manager_update_allowed: boolean; progress_logs_count: number; recent_logs: ProgressLogEntry[] }
type Probation  = { id: number; probation_type: string; status: string; start_date: string | null; end_date: string | null; days_remaining: number }
type PipPlan    = { id: number; title: string; status: string; overall_progress: number; days_remaining: number; objectives_met: number; objectives_total: number; start_date: string | null; target_end_date: string | null }
type Checkin    = { id: number; title: string | null; scheduled_at: string; location: string | null }
type Coaching   = { id: number; title: string | null; session_date: string; status: string }
type Feedback   = { id: number; feedback_type: string; content: string; rating: number | null; author: string | null; created_at: string }
type Review360  = { id: number; title: string; status: string; due_date: string | null; completion_rate: number }
type DevPlan    = { id: number; title: string; created_at: string }

type WellnessEntry = {
  name: string; wellness_score: number | null; wellness_grade: string | null
  burnout_risk: string | null; kpi_score: number | null
  feedback_score: number | null; checkin_score: number | null
}
type Signal = {
  id: number; employee: string; signal_type: string
  severity: string; title: string; description: string | null
}
type KpiChartEntry  = { name: string; score: number }
type BurnoutChart   = { labels: string[]; series: number[] }

type PendingAppraisal = {
  id: number; employee_name: string; employee_id: number
  cycle_name: string | null; snapshot_date: string | null
  overall_score: number | null; employee_score: number | null
  manager_score: number | null; score_360: number | null; final_score: number | null
  appraisal_status: string; employee_comments: string | null; manager_comments: string | null
  submitted_at: string | null
}

type Report = {
  user_id: number; name: string; email: string
  is_direct_report: boolean
  snapshot:   Snapshot | null
  kpis:       Kpi[]
  probation:  Probation | null
  pip:        PipPlan | null
  checkins:   Checkin[]
  coaching:   Coaching[]
  feedback:   Feedback[]
  reviews_360: Review360[]
  dev_plans:  DevPlan[]
}

const props = defineProps<{
  reports:           Report[]
  cycles:            Cycle[]
  wellness_data:     WellnessEntry[]
  signals:           Signal[]
  kpi_chart:         KpiChartEntry[]
  burnout_chart:     BurnoutChart
  pending_approvals: PendingAppraisal[]
}>()

// ── Expanded cards ────────────────────────────────────────────────────────────
const expanded = ref<Set<number>>(new Set())
const activeTab = ref<Record<number, string>>({})
function toggle(uid: number) {
  if (expanded.value.has(uid)) { expanded.value.delete(uid) }
  else { expanded.value.add(uid); if (!activeTab.value[uid]) activeTab.value[uid] = 'kpis' }
}

// ── KPI row expansion per employee ───────────────────────────────────────────
const kpiExpanded = ref<Record<string, Set<number>>>({})
function toggleKpiRow(empId: number, kpiId: number) {
  if (!kpiExpanded.value[empId]) kpiExpanded.value[empId] = new Set()
  const s = kpiExpanded.value[empId]
  if (s.has(kpiId)) s.delete(kpiId)
  else s.add(kpiId)
}
function isKpiExpanded(empId: number, kpiId: number) {
  return kpiExpanded.value[empId]?.has(kpiId) ?? false
}

const kpiAchievementPct = (kpi: Kpi) => {
  if (kpi.current == null || !kpi.target_value) return null
  return Math.min(100, Math.round((kpi.current / kpi.target_value) * 100))
}

// ── KPI progress log ──────────────────────────────────────────────────────────
const logOpen   = ref(false)
const logTarget = ref<Kpi | null>(null)
const logEmpName = ref('')
const logSaving = ref(false)
const logForm   = reactive({ value: '' as string | number, notes: '', log_date: new Date().toISOString().slice(0, 10) })

function openLog(kpi: Kpi, empName: string) {
  logTarget.value  = kpi
  logEmpName.value = empName
  logForm.value    = kpi.current ?? ''
  logForm.notes    = ''
  logForm.log_date = new Date().toISOString().slice(0, 10)
  logOpen.value    = true
}

function submitLog() {
  if (!logTarget.value) return
  logSaving.value = true
  router.post(route('performance.kpis.progress.log', logTarget.value.id), logForm as any, {
    onSuccess: () => { logOpen.value = false },
    onFinish:  () => { logSaving.value = false },
  })
}

// ── Give feedback ─────────────────────────────────────────────────────────────
const fbOpen   = ref(false)
const fbTarget = ref<Report | null>(null)
const fbSaving = ref(false)
const fbForm   = reactive({ employee_id: null as number | null, feedback_type: 'manager', content: '', rating: null as number | null, is_anonymous: false, is_draft: false })

function openFeedback(rep: Report) {
  fbTarget.value = rep
  Object.assign(fbForm, { employee_id: rep.user_id, feedback_type: 'manager', content: '', rating: null, is_anonymous: false, is_draft: false })
  fbOpen.value = true
}

function submitFeedback() {
  fbSaving.value = true
  router.post(route('performance.feedback.store'), fbForm as any, {
    onSuccess: () => { fbOpen.value = false },
    onFinish:  () => { fbSaving.value = false },
  })
}

// ── Appraisal approval actions ────────────────────────────────────────────────
const approvalScoreOpen    = ref(false)
const approvalScoreTarget  = ref<PendingAppraisal | null>(null)
const approvalScoreForm    = reactive({ manager_score: '' as number | string, score_360: '' as number | string, manager_comments: '' })
const approvalScoreLoading = ref(false)

function openApprovalScore(snap: PendingAppraisal) {
  approvalScoreTarget.value = snap
  Object.assign(approvalScoreForm, {
    manager_score:    snap.manager_score ?? '',
    score_360:        snap.score_360 ?? '',
    manager_comments: snap.manager_comments ?? '',
  })
  approvalScoreOpen.value = true
}

const approvalPreviewFinal = computed(() => {
  if (!approvalScoreTarget.value) return null
  const kpi = approvalScoreTarget.value.overall_score ?? 0
  const mgr = Number(approvalScoreForm.manager_score)
  const s360 = approvalScoreForm.score_360 !== '' ? Number(approvalScoreForm.score_360) : null
  if (isNaN(mgr)) return null
  return s360 !== null && !isNaN(s360)
    ? Math.round((kpi * 0.50 + mgr * 0.30 + s360 * 0.20) * 100) / 100
    : Math.round((kpi * 0.60 + mgr * 0.40) * 100) / 100
})

function submitApprovalScore() {
  if (!approvalScoreTarget.value) return
  approvalScoreLoading.value = true
  router.patch(
    route('performance.appraisal-review.manager-score', approvalScoreTarget.value.id),
    {
      manager_score:    approvalScoreForm.manager_score,
      score_360:        approvalScoreForm.score_360 === '' ? null : approvalScoreForm.score_360,
      manager_comments: approvalScoreForm.manager_comments,
    },
    {
      preserveScroll: true,
      onFinish: () => { approvalScoreLoading.value = false; approvalScoreOpen.value = false },
    }
  )
}

const approvalApproveOpen    = ref(false)
const approvalApproveTarget  = ref<PendingAppraisal | null>(null)
const approvalApproveLoading = ref(false)

function openApprovalApprove(snap: PendingAppraisal) {
  approvalApproveTarget.value = snap
  approvalApproveOpen.value   = true
}

function submitApprovalApprove() {
  if (!approvalApproveTarget.value) return
  approvalApproveLoading.value = true
  router.post(
    route('performance.appraisal-review.approve', approvalApproveTarget.value.id),
    {},
    {
      preserveScroll: true,
      onFinish: () => { approvalApproveLoading.value = false; approvalApproveOpen.value = false },
    }
  )
}

const approvalRejectOpen    = ref(false)
const approvalRejectTarget  = ref<PendingAppraisal | null>(null)
const approvalRejectComment = ref('')
const approvalRejectLoading = ref(false)

function openApprovalReject(snap: PendingAppraisal) {
  approvalRejectTarget.value  = snap
  approvalRejectComment.value = ''
  approvalRejectOpen.value    = true
}

function submitApprovalReject() {
  if (!approvalRejectTarget.value) return
  approvalRejectLoading.value = true
  router.post(
    route('performance.appraisal-review.reject', approvalRejectTarget.value.id),
    { manager_comments: approvalRejectComment.value },
    {
      preserveScroll: true,
      onFinish: () => { approvalRejectLoading.value = false; approvalRejectOpen.value = false },
    }
  )
}

// ── Helpers ───────────────────────────────────────────────────────────────────
const scoreColor = (s: number | null) => s == null ? 'default' : s >= 80 ? 'success' : s >= 60 ? 'warning' : 'error'
const statusColor = (s: string) =>
  ({ active: 'success', in_progress: 'primary', pending: 'warning', extended: 'orange', scheduled: 'info', completed: 'success', escalated: 'error', not_started: 'default' })[s] ?? 'default'

const initials = (name: string) => name.split(' ').map(n => n[0]).slice(0, 2).join('').toUpperCase()
const avatarColor = (uid: number) => ['primary', 'secondary', 'success', 'warning', 'info', 'error'][uid % 6]

// Search / filter
const search = ref('')
const filteredReports = computed(() => {
  const base = search.value
    ? props.reports.filter(r => r.name.toLowerCase().includes(search.value.toLowerCase()) || r.email.toLowerCase().includes(search.value.toLowerCase()))
    : props.reports
  // Direct reports first, then indirect
  return [...base].sort((a, b) => (b.is_direct_report ? 1 : 0) - (a.is_direct_report ? 1 : 0))
})

// Stats for header strip
const totalReports    = computed(() => props.reports.length)
const directReports   = computed(() => props.reports.filter(r => r.is_direct_report).length)
const onProbation     = computed(() => props.reports.filter(r => r.probation).length)
const activePip       = computed(() => props.reports.filter(r => r.pip).length)
const avgScore        = computed(() => {
  const scores = props.reports.map(r => r.snapshot?.overall_score).filter(s => s != null) as number[]
  return scores.length ? (scores.reduce((a, b) => a + b, 0) / scores.length).toFixed(1) : null
})
const criticalSignals = computed(() => props.signals.filter(s => s.severity === 'critical').length)

// ── Chart configs ─────────────────────────────────────────────────────────────
const kpiBarOptions = computed(() => ({
  chart: { type: 'bar', toolbar: { show: false }, fontFamily: 'inherit' },
  plotOptions: { bar: { horizontal: true, borderRadius: 4, dataLabels: { position: 'top' } } },
  dataLabels: { enabled: true, formatter: (v: number) => v + '%', offsetX: 14, style: { fontSize: '11px' } },
  xaxis: {
    categories: props.kpi_chart.map(e => e.name),
    max: 100,
    labels: { formatter: (v: string) => v + '%' },
  },
  colors: ['#6366f1'],
  grid: { borderColor: 'rgba(0,0,0,0.06)' },
  tooltip: { y: { formatter: (v: number) => v + '%' } },
}))

const kpiBarSeries = computed(() => [{
  name: 'KPI Completion',
  data: props.kpi_chart.map(e => e.score),
}])

const burnoutDonutOptions = computed(() => ({
  chart: { type: 'donut', fontFamily: 'inherit' },
  labels: props.burnout_chart.labels,
  colors: ['#22c55e', '#f59e0b', '#ef4444', '#6366f1'],
  legend: { position: 'bottom' as const },
  plotOptions: { pie: { donut: { size: '65%', labels: { show: true, total: { show: true, label: 'Total' } } } } },
  dataLabels: { enabled: true },
}))

const wellnessBarOptions = computed(() => ({
  chart: { type: 'bar', toolbar: { show: false }, fontFamily: 'inherit' },
  plotOptions: { bar: { horizontal: false, borderRadius: 4, columnWidth: '60%' } },
  dataLabels: { enabled: false },
  xaxis: { categories: props.wellness_data.map(w => w.name.split(' ')[0]) },
  yaxis: { max: 100 },
  colors: ['#22c55e'],
  tooltip: {
    custom: ({ dataPointIndex }: any) => {
      const w = props.wellness_data[dataPointIndex]
      return `<div class="pa-2 text-caption">
        <b>${w.name}</b><br/>
        Wellness: ${w.wellness_score ?? '—'} (${w.wellness_grade ?? '—'})<br/>
        Burnout: ${w.burnout_risk ?? '—'}
      </div>`
    },
  },
}))

const wellnessBarSeries = computed(() => [{
  name: 'Wellness Score',
  data: props.wellness_data.map(w => w.wellness_score ?? 0),
}])

// burnout risk colour per employee row
const burnoutColor = (risk: string | null) =>
  ({ low: 'success', medium: 'warning', high: 'error', critical: 'error' }[risk ?? ''] ?? 'default')
</script>

<template>
  <VContainer fluid class="pa-6">

    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('pa.manager_dashboard.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">{{ t('pa.manager_dashboard.subtitle') }}</p>
      </div>
      <VTextField
        v-model="search"
        density="compact" variant="outlined" hide-details clearable
        prepend-inner-icon="tabler-search"
        placeholder="Search team member…"
        style="max-width: 260px"
      />
    </div>

    <!-- Proactive AI nudges -->
    <NudgeWidget />

    <!-- Stats strip -->
    <VRow dense class="mb-4">
      <VCol cols="6" sm="2">
        <VCard border flat class="pa-3 text-center">
          <p class="text-h5 font-weight-bold text-primary">{{ directReports }}</p>
          <p class="text-caption text-medium-emphasis">Direct Reports</p>
          <p v-if="totalReports > directReports" class="text-caption text-medium-emphasis">({{ totalReports }} total in tree)</p>
        </VCard>
      </VCol>
      <VCol cols="6" sm="2">
        <VCard border flat class="pa-3 text-center">
          <p class="text-h5 font-weight-bold" :class="avgScore ? 'text-success' : 'text-medium-emphasis'">
            {{ avgScore != null ? avgScore + '%' : '—' }}
          </p>
          <p class="text-caption text-medium-emphasis">{{ t('pa.manager_dashboard.overall_score') }}</p>
        </VCard>
      </VCol>
      <VCol cols="6" sm="2">
        <VCard border flat class="pa-3 text-center">
          <p class="text-h5 font-weight-bold" :class="onProbation > 0 ? 'text-warning' : 'text-medium-emphasis'">{{ onProbation }}</p>
          <p class="text-caption text-medium-emphasis">{{ t('pa.manager_dashboard.on_probation') }}</p>
        </VCard>
      </VCol>
      <VCol cols="6" sm="2">
        <VCard border flat class="pa-3 text-center">
          <p class="text-h5 font-weight-bold" :class="activePip > 0 ? 'text-error' : 'text-medium-emphasis'">{{ activePip }}</p>
          <p class="text-caption text-medium-emphasis">{{ t('pa.manager_dashboard.active_pip') }}</p>
        </VCard>
      </VCol>
      <VCol cols="6" sm="2">
        <VCard border flat class="pa-3 text-center">
          <p class="text-h5 font-weight-bold" :class="props.wellness_data.length ? 'text-success' : 'text-medium-emphasis'">
            {{ props.wellness_data.length }}
          </p>
          <p class="text-caption text-medium-emphasis">Wellness Scores</p>
        </VCard>
      </VCol>
      <VCol cols="6" sm="2">
        <VCard border flat class="pa-3 text-center">
          <p class="text-h5 font-weight-bold" :class="criticalSignals > 0 ? 'text-error' : 'text-medium-emphasis'">{{ criticalSignals }}</p>
          <p class="text-caption text-medium-emphasis">Critical Signals</p>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Critical signals alert ────────────────────────────────────────── -->
    <VAlert
      v-if="criticalSignals > 0"
      type="error" variant="tonal" border="start" class="mb-4"
      prepend-icon="tabler-alert-circle"
    >
      <strong>{{ criticalSignals }} critical behaviour signal{{ criticalSignals > 1 ? 's' : '' }}</strong>
      require your attention. See the Signals section below.
    </VAlert>

    <!-- ── Charts row ────────────────────────────────────────────────────── -->
    <VRow dense class="mb-6" v-if="props.reports.length">

      <!-- KPI Completion bar chart -->
      <VCol cols="12" md="7">
        <VCard border flat>
          <VCardTitle class="text-body-2 font-weight-semibold px-4 pt-3 pb-0">
            <VIcon size="16" class="mr-1" color="primary">tabler-chart-bar</VIcon>
            Team KPI Completion
          </VCardTitle>
          <VCardText class="pa-2">
            <VueApexCharts
              v-if="kpi_chart.length"
              type="bar"
              :height="Math.max(180, kpi_chart.length * 36)"
              :options="kpiBarOptions"
              :series="kpiBarSeries"
            />
            <div v-else class="pa-4 text-center text-medium-emphasis text-caption">
              No KPI snapshot data available yet.
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Burnout risk donut -->
      <VCol cols="12" md="5">
        <VCard border flat height="100%">
          <VCardTitle class="text-body-2 font-weight-semibold px-4 pt-3 pb-0">
            <VIcon size="16" class="mr-1" color="warning">tabler-flame</VIcon>
            Team Burnout Risk
          </VCardTitle>
          <VCardText class="pa-2">
            <VueApexCharts
              v-if="burnout_chart.series.length"
              type="donut"
              height="220"
              :options="burnoutDonutOptions"
              :series="burnout_chart.series"
            />
            <div v-else class="pa-8 text-center text-medium-emphasis text-caption">
              No wellness assessments recorded yet.
            </div>
          </VCardText>
        </VCard>
      </VCol>

    </VRow>

    <!-- ── Pending Appraisal Approvals ──────────────────────────────────── -->
    <VCard v-if="props.pending_approvals.length" border flat class="mb-6">
      <VCardTitle class="text-body-2 font-weight-semibold px-4 pt-3 pb-0 d-flex align-center gap-2">
        <VIcon size="16" color="orange">tabler-clipboard-check</VIcon>
        Pending Appraisal Approvals
        <VChip size="x-small" color="orange" variant="flat" class="ml-1">{{ props.pending_approvals.length }}</VChip>
        <VSpacer />
        <VBtn size="x-small" variant="tonal" color="primary" :href="route('performance.appraisal-review.index')">
          View All
        </VBtn>
      </VCardTitle>
      <VCardText class="pa-0">
        <VTable density="compact" hover>
          <thead>
            <tr>
              <th>Employee</th>
              <th>Cycle</th>
              <th class="text-center">KPI Score</th>
              <th class="text-center">Self Score</th>
              <th class="text-center">Mgr Score</th>
              <th class="text-center">360</th>
              <th class="text-center">Final</th>
              <th class="text-center">Status</th>
              <th class="text-center">Submitted</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="snap in props.pending_approvals" :key="snap.id">
              <td class="font-weight-medium">{{ snap.employee_name }}</td>
              <td class="text-caption">{{ snap.cycle_name ?? '—' }}</td>
              <td class="text-center">
                <VChip size="x-small" :color="scoreColor(snap.overall_score)" variant="tonal">
                  {{ snap.overall_score !== null ? snap.overall_score.toFixed(1) + '%' : '—' }}
                </VChip>
              </td>
              <td class="text-center">
                <VChip size="x-small" :color="scoreColor(snap.employee_score)" variant="tonal">
                  {{ snap.employee_score !== null ? snap.employee_score.toFixed(1) + '%' : '—' }}
                </VChip>
              </td>
              <td class="text-center">
                <VChip size="x-small" :color="scoreColor(snap.manager_score)" variant="tonal">
                  {{ snap.manager_score !== null ? snap.manager_score.toFixed(1) + '%' : '—' }}
                </VChip>
              </td>
              <td class="text-center">
                <VChip size="x-small" :color="scoreColor(snap.score_360)" variant="tonal">
                  {{ snap.score_360 !== null ? snap.score_360.toFixed(1) + '%' : '—' }}
                </VChip>
              </td>
              <td class="text-center">
                <VChip size="x-small" :color="scoreColor(snap.final_score)" variant="flat">
                  <strong>{{ snap.final_score !== null ? snap.final_score.toFixed(1) + '%' : '—' }}</strong>
                </VChip>
              </td>
              <td class="text-center">
                <VChip size="x-small" :color="{ draft: 'grey', employee_submitted: 'blue', manager_review: 'orange', approved: 'green' }[snap.appraisal_status] ?? 'grey'" variant="tonal">
                  {{ { draft: 'Draft', employee_submitted: 'Submitted', manager_review: 'Under Review', approved: 'Approved' }[snap.appraisal_status] ?? snap.appraisal_status }}
                </VChip>
              </td>
              <td class="text-caption text-medium-emphasis">{{ snap.submitted_at ?? '—' }}</td>
              <td>
                <div class="d-flex gap-1 justify-end">
                  <VBtn icon size="x-small" variant="text" color="primary" title="Enter manager score" @click="openApprovalScore(snap)">
                    <VIcon size="16">tabler-pencil</VIcon>
                  </VBtn>
                  <VBtn
                    icon size="x-small" variant="text" color="success"
                    :disabled="snap.manager_score === null"
                    title="Approve" @click="openApprovalApprove(snap)"
                  >
                    <VIcon size="16">tabler-checks</VIcon>
                  </VBtn>
                  <VBtn icon size="x-small" variant="text" color="error" title="Return to employee" @click="openApprovalReject(snap)">
                    <VIcon size="16">tabler-rotate-counter-clockwise-2</VIcon>
                  </VBtn>
                </div>
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCardText>
    </VCard>

    <!-- ── Team Wellness ─────────────────────────────────────────────────── -->
    <VCard v-if="props.wellness_data.length" border flat class="mb-6">
      <VCardTitle class="text-body-2 font-weight-semibold px-4 pt-3 pb-0">
        <VIcon size="16" class="mr-1" color="success">tabler-heartbeat</VIcon>
        Team Wellness Overview
      </VCardTitle>
      <VCardText class="pa-2">
        <VRow dense>
          <VCol cols="12" md="8">
            <VueApexCharts
              type="bar"
              height="200"
              :options="wellnessBarOptions"
              :series="wellnessBarSeries"
            />
          </VCol>
          <VCol cols="12" md="4">
            <VList density="compact" lines="two">
              <VListItem
                v-for="w in props.wellness_data" :key="w.name"
                :title="w.name"
              >
                <template #subtitle>
                  <div class="d-flex align-center gap-2 mt-1">
                    <VChip size="x-small" :color="burnoutColor(w.burnout_risk)" variant="tonal">
                      {{ w.burnout_risk ?? '—' }} risk
                    </VChip>
                    <span class="text-caption">Score: {{ w.wellness_score ?? '—' }}</span>
                    <VChip v-if="w.wellness_grade" size="x-small" variant="outlined">{{ w.wellness_grade }}</VChip>
                  </div>
                </template>
              </VListItem>
            </VList>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- ── Behaviour signals ─────────────────────────────────────────────── -->
    <VCard v-if="props.signals.length" border flat class="mb-6">
      <VCardTitle class="text-body-2 font-weight-semibold px-4 pt-3 pb-1">
        <VIcon size="16" class="mr-1" color="error">tabler-alert-octagon</VIcon>
        Unacknowledged Behaviour Signals
        <VChip size="x-small" color="error" variant="tonal" class="ml-2">{{ props.signals.length }}</VChip>
      </VCardTitle>
      <VDivider />
      <VList density="compact" lines="two">
        <VListItem
          v-for="sig in props.signals" :key="sig.id"
          :title="sig.title"
          :prepend-icon="sig.severity === 'critical' ? 'tabler-alert-circle' : 'tabler-alert-triangle'"
          :base-color="sig.severity === 'critical' ? 'error' : 'warning'"
        >
          <template #subtitle>
            <span class="text-caption">
              {{ sig.employee }} ·
              <VChip size="x-small" :color="sig.severity === 'critical' ? 'error' : 'warning'" variant="tonal">
                {{ sig.severity }}
              </VChip>
              · {{ sig.signal_type.replace(/_/g, ' ') }}
            </span>
          </template>
        </VListItem>
      </VList>
    </VCard>

    <!-- Empty state -->
    <VAlert v-if="!props.reports.length" type="info" variant="tonal">
      {{ t('pa.manager_dashboard.no_reports') }}
    </VAlert>

    <!-- Employee cards -->
    <div v-for="rep in filteredReports" :key="rep.user_id" class="mb-4">
      <VCard border flat>

        <!-- Card header row (always visible) -->
        <VCardItem class="py-3">
          <template #prepend>
            <VAvatar :color="avatarColor(rep.user_id)" variant="tonal" size="44">
              <span class="text-body-2 font-weight-bold">{{ initials(rep.name) }}</span>
            </VAvatar>
          </template>

          <VCardTitle class="text-body-1 font-weight-semibold">{{ rep.name }}</VCardTitle>
          <VCardSubtitle>{{ rep.email }}</VCardSubtitle>

          <template #append>
            <div class="d-flex align-center ga-2">
              <!-- Indirect report indicator -->
              <VChip v-if="!rep.is_direct_report" size="x-small" color="default" variant="outlined" label>
                <VIcon start size="12">tabler-social</VIcon>Indirect
              </VChip>

              <!-- Score badge -->
              <VChip
                v-if="rep.snapshot?.overall_score != null"
                size="small"
                :color="scoreColor(rep.snapshot.overall_score)"
                variant="tonal"
                label
              >
                {{ rep.snapshot.overall_score.toFixed(1) }}%
              </VChip>

              <!-- Probation alert -->
              <VChip v-if="rep.probation" size="small" color="warning" variant="tonal" label>
                <VIcon start size="14">tabler-clock-exclamation</VIcon>Probation
              </VChip>

              <!-- PIP alert -->
              <VChip v-if="rep.pip" size="small" color="error" variant="tonal" label>
                <VIcon start size="14">tabler-alert-triangle</VIcon>PIP
              </VChip>

              <!-- Quick actions -->
              <VBtn v-if="rep.is_direct_report" size="x-small" icon variant="text" color="primary" @click="openFeedback(rep)">
                <VIcon>tabler-message-plus</VIcon>
                <VTooltip activator="parent">Give Feedback</VTooltip>
              </VBtn>

              <!-- Expand toggle -->
              <VBtn size="x-small" icon variant="text" @click="toggle(rep.user_id)">
                <VIcon>{{ expanded.has(rep.user_id) ? 'tabler-chevron-up' : 'tabler-chevron-down' }}</VIcon>
              </VBtn>
            </div>
          </template>
        </VCardItem>

        <!-- Score mini-bar -->
        <div v-if="rep.snapshot" class="px-4 pb-2">
          <VProgressLinear
            :model-value="rep.snapshot.kpi_completion_rate ?? 0"
            :color="scoreColor(rep.snapshot.kpi_completion_rate)"
            height="4" rounded bg-color="surface-variant"
          />
          <p class="text-caption text-medium-emphasis mt-1">
            KPI Completion {{ rep.snapshot.kpi_completion_rate?.toFixed(0) ?? '—' }}%
            <span v-if="rep.snapshot.cycle" class="ml-2">· {{ rep.snapshot.cycle }}</span>
          </p>
        </div>

        <!-- Expanded detail tabs -->
        <template v-if="expanded.has(rep.user_id)">
          <VDivider />
          <VTabs v-model="activeTab[rep.user_id]" density="compact" class="px-2">
            <VTab value="kpis">
              <VIcon start size="16">tabler-chart-bar</VIcon>{{ t('pa.manager_dashboard.tab_kpis') }}
              <VBadge v-if="rep.kpis.length" :content="rep.kpis.length" color="primary" inline />
            </VTab>
            <VTab value="probation" :disabled="!rep.probation">
              <VIcon start size="16">tabler-clock-exclamation</VIcon>{{ t('pa.manager_dashboard.tab_probation') }}
            </VTab>
            <VTab value="pip" :disabled="!rep.pip">
              <VIcon start size="16">tabler-alert-triangle</VIcon>{{ t('pa.manager_dashboard.tab_pip') }}
            </VTab>
            <VTab value="checkins">
              <VIcon start size="16">tabler-calendar-check</VIcon>{{ t('pa.manager_dashboard.tab_checkins') }}
              <VBadge v-if="rep.checkins.length" :content="rep.checkins.length" color="info" inline />
            </VTab>
            <VTab value="coaching">
              <VIcon start size="16">tabler-microphone</VIcon>{{ t('pa.manager_dashboard.tab_coaching') }}
            </VTab>
            <VTab value="feedback">
              <VIcon start size="16">tabler-message-dots</VIcon>{{ t('pa.manager_dashboard.tab_feedback') }}
            </VTab>
            <VTab value="reviews360">
              <VIcon start size="16">tabler-rotate-360</VIcon>{{ t('pa.manager_dashboard.tab_360') }}
            </VTab>
            <VTab value="development">
              <VIcon start size="16">tabler-school</VIcon>{{ t('pa.manager_dashboard.tab_development') }}
            </VTab>
          </VTabs>

          <VTabsWindow v-model="activeTab[rep.user_id]" class="pa-4">

            <!-- KPIs tab -->
            <VTabsWindowItem value="kpis">
              <VAlert v-if="!rep.kpis.length" type="info" variant="tonal" density="compact">{{ t('pa.manager_dashboard.no_kpis') }}</VAlert>
              <div v-else class="d-flex flex-column gap-2">
                <VCard
                  v-for="kpi in rep.kpis"
                  :key="kpi.id"
                  border elevation="0"
                >
                  <VCardText class="pb-2">
                    <!-- KPI header -->
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
                        <span v-if="kpiAchievementPct(kpi) !== null" class="ml-1 font-weight-bold">· {{ kpiAchievementPct(kpi) }}%</span>
                      </span>
                    </div>
                    <VProgressLinear
                      :model-value="kpiAchievementPct(kpi) ?? 0"
                      :color="kpiAchievementPct(kpi) == null ? 'surface-variant' : kpiAchievementPct(kpi)! >= 80 ? 'success' : kpiAchievementPct(kpi)! >= 50 ? 'primary' : 'warning'"
                      height="8" rounded bg-color="surface-variant" class="mb-2"
                    />

                    <!-- Actions row -->
                    <div class="d-flex align-center gap-2 mt-2">
                      <VBtn
                        v-if="kpi.manager_update_allowed && rep.is_direct_report"
                        size="x-small" variant="tonal" color="primary"
                        prepend-icon="ri-line-chart-line"
                        @click="openLog(kpi, rep.name)"
                      >
                        {{ t('pa.manager_dashboard.log_progress') }}
                      </VBtn>
                      <VBtn
                        size="x-small" variant="text"
                        prepend-icon="ri-eye-line"
                        :href="`/performance/kpis/${kpi.id}/progress`"
                      >
                        View history
                      </VBtn>
                      <VSpacer />
                      <VBtn
                        v-if="kpi.recent_logs.length"
                        size="x-small" variant="text"
                        :append-icon="isKpiExpanded(rep.user_id, kpi.id) ? 'tabler-chevron-up' : 'tabler-chevron-down'"
                        @click="toggleKpiRow(rep.user_id, kpi.id)"
                      >
                        {{ kpi.progress_logs_count }} {{ kpi.progress_logs_count === 1 ? 'entry' : 'entries' }}
                      </VBtn>
                      <span v-else class="text-caption text-medium-emphasis">No entries yet</span>
                    </div>
                  </VCardText>

                  <!-- Recent logs expandable -->
                  <VExpandTransition>
                    <div v-if="isKpiExpanded(rep.user_id, kpi.id)">
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
            </VTabsWindowItem>

            <!-- Probation tab -->
            <VTabsWindowItem value="probation">
              <VAlert v-if="!rep.probation" type="info" variant="tonal" density="compact">{{ t('pa.manager_dashboard.no_probation') }}</VAlert>
              <template v-else>
                <VRow dense class="mb-3">
                  <VCol cols="6" sm="3">
                    <div class="text-caption text-medium-emphasis">{{ t('pa.probation.type') }}</div>
                    <div class="text-body-2 font-weight-medium">{{ rep.probation.probation_type }}</div>
                  </VCol>
                  <VCol cols="6" sm="3">
                    <div class="text-caption text-medium-emphasis">{{ t('pa.common.status') }}</div>
                    <VChip size="x-small" :color="statusColor(rep.probation.status)" variant="tonal" label>{{ rep.probation.status }}</VChip>
                  </VCol>
                  <VCol cols="6" sm="3">
                    <div class="text-caption text-medium-emphasis">{{ t('pa.cycles.start_date') }}</div>
                    <div class="text-body-2">{{ rep.probation.start_date ?? '—' }}</div>
                  </VCol>
                  <VCol cols="6" sm="3">
                    <div class="text-caption text-medium-emphasis">{{ t('pa.common.end_date') }}</div>
                    <div class="text-body-2">{{ rep.probation.end_date ?? '—' }}</div>
                  </VCol>
                </VRow>
                <div class="d-flex align-center ga-2 mb-2">
                  <VIcon :color="rep.probation.days_remaining > 0 ? 'warning' : 'error'" size="16">tabler-clock</VIcon>
                  <span class="text-caption">
                    <span v-if="rep.probation.days_remaining > 0">{{ rep.probation.days_remaining }} {{ t('pa.manager_dashboard.days_remaining') }}</span>
                    <span v-else class="text-error">{{ t('pa.manager_dashboard.overdue') }}</span>
                  </span>
                </div>
                <VBtn size="small" color="primary" variant="tonal" :href="route('performance.probation.show', rep.probation.id)">
                  {{ t('pa.manager_dashboard.manage_probation') }}
                </VBtn>
              </template>
            </VTabsWindowItem>

            <!-- PIP tab -->
            <VTabsWindowItem value="pip">
              <VAlert v-if="!rep.pip" type="info" variant="tonal" density="compact">{{ t('pa.manager_dashboard.no_pip') }}</VAlert>
              <template v-else>
                <VRow dense class="mb-3">
                  <VCol cols="12" sm="6">
                    <div class="text-body-2 font-weight-medium mb-1">{{ rep.pip.title }}</div>
                    <div class="d-flex align-center ga-2 mb-2">
                      <VChip size="x-small" :color="statusColor(rep.pip.status)" variant="tonal" label>{{ rep.pip.status }}</VChip>
                      <span class="text-caption text-medium-emphasis">
                        {{ rep.pip.start_date }} → {{ rep.pip.target_end_date }}
                      </span>
                    </div>
                    <div class="d-flex justify-space-between text-caption mb-1">
                      <span>{{ t('pa.common.progress') }}</span>
                      <span class="font-weight-medium">{{ rep.pip.overall_progress }}%</span>
                    </div>
                    <VProgressLinear
                      :model-value="rep.pip.overall_progress"
                      :color="rep.pip.overall_progress >= 75 ? 'success' : rep.pip.overall_progress >= 40 ? 'primary' : 'error'"
                      height="6" rounded class="mb-2"
                    />
                    <div class="text-caption text-medium-emphasis">
                      {{ rep.pip.objectives_met }}/{{ rep.pip.objectives_total }} {{ t('pa.manager_dashboard.objectives_met') }}
                      ·
                      <span :class="rep.pip.days_remaining > 0 ? 'text-warning' : 'text-error'">
                        {{ rep.pip.days_remaining > 0 ? rep.pip.days_remaining + ' ' + t('pa.manager_dashboard.days_remaining') : t('pa.manager_dashboard.overdue') }}
                      </span>
                    </div>
                  </VCol>
                </VRow>
                <VBtn size="small" color="primary" variant="tonal" @click="router.visit(route('performance.pip.show', rep.pip.id))">
                  {{ t('pa.manager_dashboard.manage_pip') }}
                </VBtn>
              </template>
            </VTabsWindowItem>

            <!-- Check-ins tab -->
            <VTabsWindowItem value="checkins">
              <VAlert v-if="!rep.checkins.length" type="info" variant="tonal" density="compact">{{ t('pa.manager_dashboard.no_checkins') }}</VAlert>
              <VList v-else density="compact" lines="two">
                <VListItem
                  v-for="ci in rep.checkins" :key="ci.id"
                  :title="ci.title ?? 'Check-in'"
                  :subtitle="ci.scheduled_at + (ci.location ? ' · ' + ci.location : '')"
                  prepend-icon="tabler-calendar-check"
                />
              </VList>
              <VBtn size="small" color="secondary" variant="tonal" class="mt-2" @click="router.visit(route('performance.checkins.index'))">
                {{ t('pa.manager_dashboard.view_checkins') }}
              </VBtn>
            </VTabsWindowItem>

            <!-- Coaching tab -->
            <VTabsWindowItem value="coaching">
              <VAlert v-if="!rep.coaching.length" type="info" variant="tonal" density="compact">{{ t('pa.manager_dashboard.no_coaching') }}</VAlert>
              <VList v-else density="compact" lines="two">
                <VListItem
                  v-for="s in rep.coaching" :key="s.id"
                  :title="s.title ?? 'Session'"
                  :subtitle="s.session_date"
                  prepend-icon="tabler-microphone"
                >
                  <template #append>
                    <VChip size="x-small" :color="statusColor(s.status)" variant="tonal">{{ s.status }}</VChip>
                  </template>
                </VListItem>
              </VList>
            </VTabsWindowItem>

            <!-- Feedback tab -->
            <VTabsWindowItem value="feedback">
              <VAlert v-if="!rep.feedback.length" type="info" variant="tonal" density="compact">{{ t('pa.manager_dashboard.no_feedback') }}</VAlert>
              <VList v-else density="compact" lines="three">
                <VListItem
                  v-for="fb in rep.feedback" :key="fb.id"
                  :subtitle="fb.content"
                  prepend-icon="tabler-message-dots"
                >
                  <template #title>
                    <span class="text-caption">
                      <VChip size="x-small" :color="statusColor('active')" variant="tonal" class="mr-1">{{ fb.feedback_type }}</VChip>
                      {{ fb.author ?? 'Anonymous' }} · {{ fb.created_at }}
                      <span v-if="fb.rating" class="ml-1">· ⭐ {{ fb.rating }}/5</span>
                    </span>
                  </template>
                </VListItem>
              </VList>
              <VBtn size="small" color="primary" variant="tonal" class="mt-2" @click="openFeedback(rep)">
                {{ t('pa.manager_dashboard.give_feedback') }}
              </VBtn>
            </VTabsWindowItem>

            <!-- 360 Reviews tab -->
            <VTabsWindowItem value="reviews360">
              <VAlert v-if="!rep.reviews_360.length" type="info" variant="tonal" density="compact">No 360 reviews found.</VAlert>
              <VList v-else density="compact" lines="two">
                <VListItem
                  v-for="r in rep.reviews_360" :key="r.id"
                  :title="r.title"
                  prepend-icon="tabler-rotate-360"
                >
                  <template #subtitle>
                    <span class="text-caption">
                      {{ r.due_date ?? '—' }} · Completion: {{ r.completion_rate }}%
                    </span>
                  </template>
                  <template #append>
                    <VChip size="x-small" :color="statusColor(r.status)" variant="tonal">{{ r.status }}</VChip>
                  </template>
                </VListItem>
              </VList>
            </VTabsWindowItem>

            <!-- Development Plans tab -->
            <VTabsWindowItem value="development">
              <VAlert v-if="!rep.dev_plans.length" type="info" variant="tonal" density="compact">No development plans.</VAlert>
              <VList v-else density="compact" lines="two">
                <VListItem
                  v-for="d in rep.dev_plans" :key="d.id"
                  :title="d.title"
                  :subtitle="d.created_at"
                  prepend-icon="tabler-school"
                />
              </VList>
            </VTabsWindowItem>

          </VTabsWindow>
        </template>

      </VCard>
    </div>

  </VContainer>

  <!-- Log KPI Progress dialog -->
  <VDialog v-model="logOpen" max-width="420">
    <VCard>
      <VCardTitle class="text-body-1 font-weight-bold pa-4">
        {{ t('pa.manager_dashboard.log_kpi_title') }}
      </VCardTitle>
      <VCardText>
        <p class="text-caption text-medium-emphasis mb-3">{{ logEmpName }} · {{ logTarget?.title }}</p>
        <VTextField
          v-model.number="logForm.value"
          label="Value"
          type="number"
          density="compact" variant="outlined" class="mb-3"
          :suffix="logTarget?.unit ?? ''"
        />
        <VTextField
          v-model="logForm.log_date"
          :label="t('pa.my_dashboard.log_date')"
          type="date"
          density="compact" variant="outlined" class="mb-3"
        />
        <VTextarea
          v-model="logForm.notes"
          :label="t('pa.my_dashboard.log_notes')"
          density="compact" variant="outlined" rows="2"
        />
      </VCardText>
      <VCardActions class="pa-4 pt-0">
        <VSpacer />
        <VBtn variant="text" @click="logOpen = false">{{ t('pa.common.cancel') }}</VBtn>
        <VBtn color="primary" variant="flat" :loading="logSaving" @click="submitLog">
          {{ t('pa.manager_dashboard.log_progress') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <!-- Give Feedback dialog -->
  <VDialog v-model="fbOpen" max-width="480">
    <VCard>
      <VCardTitle class="text-body-1 font-weight-bold pa-4">
        {{ t('pa.manager_dashboard.give_feedback') }} — {{ fbTarget?.name }}
      </VCardTitle>
      <VCardText>
        <VSelect
          v-model="fbForm.feedback_type"
          :items="['manager', 'peer', 'upward']"
          :label="t('pa.my_dashboard.feedback_type_label')"
          density="compact" variant="outlined" class="mb-3"
        />
        <VTextarea
          v-model="fbForm.content"
          :label="t('pa.my_dashboard.feedback_content')"
          density="compact" variant="outlined" rows="4" class="mb-3"
        />
        <VTextField
          v-model.number="fbForm.rating"
          :label="t('pa.my_dashboard.rating_optional')"
          type="number" min="1" max="5"
          density="compact" variant="outlined" class="mb-3"
        />
        <VCheckbox
          v-model="fbForm.is_draft"
          label="Save as draft"
          density="compact" hide-details
        />
      </VCardText>
      <VCardActions class="pa-4 pt-0">
        <VSpacer />
        <VBtn variant="text" @click="fbOpen = false">{{ t('pa.common.cancel') }}</VBtn>
        <VBtn color="primary" variant="flat" :loading="fbSaving" :disabled="!fbForm.content" @click="submitFeedback">
          {{ t('pa.my_dashboard.submit_feedback') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <!-- ── Approval: Manager Score dialog ──────────────────────────────────── -->
  <VDialog v-model="approvalScoreOpen" max-width="560">
    <VCard v-if="approvalScoreTarget">
      <VToolbar color="primary" density="compact">
        <VToolbarTitle>Manager Score — {{ approvalScoreTarget.employee_name }}</VToolbarTitle>
        <VBtn icon @click="approvalScoreOpen = false"><VIcon>tabler-x</VIcon></VBtn>
      </VToolbar>
      <VCardText class="pt-4">
        <VRow dense class="mb-4">
          <VCol cols="4" class="text-center">
            <div class="text-caption text-medium-emphasis">KPI Auto-Score</div>
            <div class="text-h6 font-weight-bold" :class="`text-${scoreColor(approvalScoreTarget.overall_score)}`">
              {{ approvalScoreTarget.overall_score !== null ? approvalScoreTarget.overall_score.toFixed(1) + '%' : '—' }}
            </div>
          </VCol>
          <VCol cols="4" class="text-center">
            <div class="text-caption text-medium-emphasis">Self Score</div>
            <div class="text-h6 font-weight-bold" :class="`text-${scoreColor(approvalScoreTarget.employee_score)}`">
              {{ approvalScoreTarget.employee_score !== null ? approvalScoreTarget.employee_score.toFixed(1) + '%' : '—' }}
            </div>
          </VCol>
          <VCol cols="4" class="text-center">
            <div class="text-caption text-medium-emphasis">Projected Final</div>
            <div class="text-h6 font-weight-bold" :class="`text-${scoreColor(approvalPreviewFinal)}`">
              {{ approvalPreviewFinal !== null ? approvalPreviewFinal.toFixed(1) + '%' : '—' }}
            </div>
          </VCol>
        </VRow>
        <VDivider class="mb-4" />
        <div v-if="approvalScoreTarget.employee_comments" class="mb-4">
          <div class="text-caption text-medium-emphasis mb-1">Employee Comments</div>
          <VSheet class="pa-3 bg-grey-lighten-4 text-body-2">{{ approvalScoreTarget.employee_comments }}</VSheet>
        </div>
        <VTextField
          v-model.number="approvalScoreForm.manager_score"
          label="Manager Score (%)" type="number" min="0" max="100" step="0.5"
          variant="outlined" density="compact" class="mb-3"
        />
        <VTextField
          v-model.number="approvalScoreForm.score_360"
          label="360 Score (%) — optional" type="number" min="0" max="100" step="0.5"
          variant="outlined" density="compact" class="mb-3"
        />
        <VTextarea
          v-model="approvalScoreForm.manager_comments"
          label="Manager Comments (optional)" rows="3" variant="outlined" density="compact"
        />
        <VAlert type="info" variant="tonal" density="compact" class="mt-2 text-caption">
          Final = KPI 50% + Manager 30% + 360 20% (or KPI 60% + Manager 40% without 360)
        </VAlert>
      </VCardText>
      <VCardActions class="justify-end pa-3">
        <VBtn variant="text" @click="approvalScoreOpen = false">Cancel</VBtn>
        <VBtn
          color="primary" variant="flat" :loading="approvalScoreLoading"
          :disabled="approvalScoreForm.manager_score === '' || Number(approvalScoreForm.manager_score) < 0 || Number(approvalScoreForm.manager_score) > 100"
          @click="submitApprovalScore"
        >Save Score</VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <!-- ── Approval: Approve confirm ────────────────────────────────────────── -->
  <VDialog v-model="approvalApproveOpen" max-width="420">
    <VCard v-if="approvalApproveTarget">
      <VToolbar color="success" density="compact">
        <VToolbarTitle>Approve Appraisal</VToolbarTitle>
        <VBtn icon @click="approvalApproveOpen = false"><VIcon>tabler-x</VIcon></VBtn>
      </VToolbar>
      <VCardText class="pt-4">
        <p>Approve the appraisal for <strong>{{ approvalApproveTarget.employee_name }}</strong>?</p>
        <VRow dense class="mt-4">
          <VCol cols="6" class="text-center">
            <div class="text-caption text-medium-emphasis">Manager Score</div>
            <div class="text-h6" :class="`text-${scoreColor(approvalApproveTarget.manager_score)}`">
              {{ approvalApproveTarget.manager_score !== null ? approvalApproveTarget.manager_score.toFixed(1) + '%' : '—' }}
            </div>
          </VCol>
          <VCol cols="6" class="text-center">
            <div class="text-caption text-medium-emphasis">Final Score</div>
            <div class="text-h5 font-weight-bold" :class="`text-${scoreColor(approvalApproveTarget.final_score)}`">
              {{ approvalApproveTarget.final_score !== null ? approvalApproveTarget.final_score.toFixed(1) + '%' : '—' }}
            </div>
          </VCol>
        </VRow>
        <VAlert type="warning" variant="tonal" density="compact" class="mt-4 text-caption">
          This action is final. The employee will be notified.
        </VAlert>
      </VCardText>
      <VCardActions class="justify-end pa-3">
        <VBtn variant="text" @click="approvalApproveOpen = false">Cancel</VBtn>
        <VBtn color="success" variant="flat" :loading="approvalApproveLoading" @click="submitApprovalApprove">
          Confirm Approve
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <!-- ── Approval: Reject / Return ────────────────────────────────────────── -->
  <VDialog v-model="approvalRejectOpen" max-width="460">
    <VCard v-if="approvalRejectTarget">
      <VToolbar color="error" density="compact">
        <VToolbarTitle>Return to Employee — {{ approvalRejectTarget.employee_name }}</VToolbarTitle>
        <VBtn icon @click="approvalRejectOpen = false"><VIcon>tabler-x</VIcon></VBtn>
      </VToolbar>
      <VCardText class="pt-4">
        <p class="mb-3">Provide feedback explaining why the appraisal is being returned.</p>
        <VTextarea
          v-model="approvalRejectComment"
          label="Reason / Feedback *" rows="4" variant="outlined"
        />
      </VCardText>
      <VCardActions class="justify-end pa-3">
        <VBtn variant="text" @click="approvalRejectOpen = false">Cancel</VBtn>
        <VBtn
          color="error" variant="flat" :loading="approvalRejectLoading"
          :disabled="!approvalRejectComment.trim()"
          @click="submitApprovalReject"
        >Return to Employee</VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

</template>
