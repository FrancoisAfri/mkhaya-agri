<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type ReportRow = Record<string, unknown>

interface Props {
  learnerCompletion:     ReportRow[]
  trainingCompliance:    ReportRow[]
  overdueAssignments:    ReportRow[]
  certificationExpiry:   ReportRow[]
  skillsGap:             ReportRow[]
  quizPerformance:       ReportRow[]
  effectivenessRoi:      ReportRow[]
  understandingScores:   ReportRow[]
  qctoLearnershipStatus: ReportRow[]
  qctoAssessorExpiry:    ReportRow[]
  registrationExpiry:    ReportRow[]
  qctoWspAtrStatus:      ReportRow[]
  qctoNlrdPending:       ReportRow[]
  departmentCapability:  ReportRow[]
  policyViolations:      ReportRow[]
  developmentPlans:      ReportRow[]
  courses:               { id: number; title: string }[]
  departments:           { id: string; name: string }[]
}

const props = withDefaults(defineProps<Props>(), {
  learnerCompletion:     () => [],
  trainingCompliance:    () => [],
  overdueAssignments:    () => [],
  certificationExpiry:   () => [],
  skillsGap:             () => [],
  quizPerformance:       () => [],
  effectivenessRoi:      () => [],
  understandingScores:   () => [],
  qctoLearnershipStatus: () => [],
  qctoAssessorExpiry:    () => [],
  registrationExpiry:    () => [],
  qctoWspAtrStatus:      () => [],
  qctoNlrdPending:       () => [],
  departmentCapability:  () => [],
  policyViolations:      () => [],
  developmentPlans:      () => [],
  courses:               () => [],
  departments:           () => [],
})

const activeTab    = ref('learner_completion')
const queueLoading = ref(false)
const toast        = reactive({ show: false, text: '', color: 'success' })

const filters = reactive({
  courseId:    null as number | null,
  departments: [] as (number | string)[],
  dateFrom:    null as string | null,
  dateTo:      null as string | null,
})

// ── Report catalogue ──────────────────────────────────────────────────────────
const reportCards = computed(()=> [
  { key: 'learner_completion',   icon: 'tabler-user-check',              color: 'primary',   title: t('lms_admin_report.report_cards.learner_completion.title'), description: t('lms_admin_report.report_cards.learner_completion.description') },
  { key: 'training_compliance',  icon: 'tabler-shield-check',            color: 'success',   title: t('lms_admin_report.report_cards.training_compliance.title'), description: t('lms_admin_report.report_cards.training_compliance.description') },
  { key: 'overdue_assignments',  icon: 'tabler-alert-circle',            color: 'error',     title: t('lms_admin_report.report_cards.overdue_assignments.title'), description: t('lms_admin_report.report_cards.overdue_assignments.description') },
  { key: 'certification_expiry', icon: 'tabler-certificate',             color: 'warning',   title: t('lms_admin_report.report_cards.certification_expiry.title'), description: t('lms_admin_report.report_cards.certification_expiry.description') },
  { key: 'skills_gap',           icon: 'tabler-chart-arrows-vertical',   color: 'orange',    title: t('lms_admin_report.report_cards.skills_gap.title'), description: t('lms_admin_report.report_cards.skills_gap.description') },
  { key: 'quiz_performance',     icon: 'tabler-help-circle',             color: 'indigo',    title: t('lms_admin_report.report_cards.quiz_performance.title'), description: t('lms_admin_report.report_cards.quiz_performance.description') },
  { key: 'effectiveness_roi',    icon: 'tabler-trending-up',             color: 'teal',      title: t('lms_admin_report.report_cards.effectiveness_roi.title'), description: t('lms_admin_report.report_cards.effectiveness_roi.description') },
  { key: 'understanding_scores', icon: 'tabler-brain',                   color: 'purple',    title: t('lms_admin_report.report_cards.understanding_scores.title'), description: t('lms_admin_report.report_cards.understanding_scores.description') },
  { key: 'qcto_learnership',     icon: 'tabler-school',                  color: 'primary',   title: t('lms_admin_report.report_cards.qcto_learnership.title'), description: t('lms_admin_report.report_cards.qcto_learnership.description') },
  { key: 'qcto_assessor_expiry', icon: 'tabler-user-shield',             color: 'error',     title: t('lms_admin_report.report_cards.qcto_assessor_expiry.title'), description: t('lms_admin_report.report_cards.qcto_assessor_expiry.description') },
  { key: 'registration_expiry',  icon: 'tabler-alert-triangle',          color: 'deep-orange', title: t('lms_admin_report.report_cards.registration_expiry.title'), description: t('lms_admin_report.report_cards.registration_expiry.description') },
  { key: 'qcto_wsp_atr',         icon: 'tabler-file-report',             color: 'warning',   title: t('lms_admin_report.report_cards.qcto_wsp_atr.title'), description: t('lms_admin_report.report_cards.qcto_wsp_atr.description') },
  { key: 'qcto_nlrd',            icon: 'tabler-database-import',         color: 'secondary', title: t('lms_admin_report.report_cards.qcto_nlrd.title'), description: t('lms_admin_report.report_cards.qcto_nlrd.description') },
  { key: 'dept_capability',      icon: 'tabler-building',                color: 'cyan',      title: t('lms_admin_report.report_cards.dept_capability.title'), description: t('lms_admin_report.report_cards.dept_capability.description') },
  { key: 'policy_violations',    icon: 'tabler-shield-x',                color: 'red',       title: t('lms_admin_report.report_cards.policy_violations.title'), description: t('lms_admin_report.report_cards.policy_violations.description') },
  { key: 'development_plans',    icon: 'tabler-list-check',              color: 'green',     title: t('lms_admin_report.report_cards.development_plans.title'), description: t('lms_admin_report.report_cards.development_plans.description') },
])

// ── Table headers per report ──────────────────────────────────────────────────
const headersByReport: Record<string, { title: string; key: string }[]> =  {
  learner_completion: [
    { title: t('lms_admin_report.table.headers.employee'), key: 'employee_name' },
    { title: t('lms_admin_report.table.headers.course'), key: 'course_title' },
    { title: t('lms_admin_report.table.headers.status'), key: 'status' },
    { title: t('lms_admin_report.table.headers.progress'), key: 'progress' },
    { title: t('lms_admin_report.table.headers.score'), key: 'score' },
    { title: t('lms_admin_report.table.headers.enrolled'), key: 'enrolled_at' },
    { title: t('lms_admin_report.table.headers.completed'), key: 'completed_at' },
  ],
  training_compliance: [
    { title: t('lms_admin_report.table.headers.employee'), key: 'employee_name' },
    { title: t('lms_admin_report.table.headers.rule'), key: 'rule_name' },
    { title: t('lms_admin_report.table.headers.course'), key: 'course_title' },
    { title: t('lms_admin_report.table.headers.status'), key: 'status' },
    { title: t('lms_admin_report.table.headers.due_date'), key: 'due_date' },
    { title: t('lms_admin_report.table.headers.assigned'), key: 'assigned_at' },
  ],
  overdue_assignments: [
    { title: t('lms_admin_report.table.headers.employee'), key: 'employee_name' },
    { title: t('lms_admin_report.table.headers.rule'), key: 'rule_name' },
    { title: t('lms_admin_report.table.headers.course'), key: 'course_title' },
    { title: t('lms_admin_report.table.headers.due_date'), key: 'due_date' },
    { title: t('lms_admin_report.table.headers.days_overdue'), key: 'days_overdue' },
  ],
  certification_expiry: [
    { title: t('lms_admin_report.table.headers.employee'), key: 'employee_name' },
    { title: t('lms_admin_report.table.headers.course'), key: 'course_title' },
    { title: t('lms_admin_report.table.headers.certificate_number'), key: 'certificate_number' },
    { title: t('lms_admin_report.table.headers.issued'), key: 'issued_at' },
    { title: t('lms_admin_report.table.headers.expires'), key: 'expires_at' },
    { title: t('lms_admin_report.table.headers.days_until_expiry'), key: 'days_until_expiry' },
  ],
  skills_gap: [
    { title: t('lms_admin_report.table.headers.employee'), key: 'employee_name' },
    { title: t('lms_admin_report.table.headers.skill'), key: 'skill_name' },
    { title: t('lms_admin_report.table.headers.current_level'), key: 'current_level' },
    { title: t('lms_admin_report.table.headers.required_level'), key: 'required_level' },
    { title: t('lms_admin_report.table.headers.gap'), key: 'gap' },
    { title: t('lms_admin_report.table.headers.identified'), key: 'identified_at' },
  ],
  quiz_performance: [
    { title: t('lms_admin_report.table.headers.employee'), key: 'employee_name' },
    { title: t('lms_admin_report.table.headers.course'), key: 'course_title' },
    { title: t('lms_admin_report.table.headers.score'), key: 'score' },
    { title: t('lms_admin_report.table.headers.passed'), key: 'passed' },
    { title: t('lms_admin_report.table.headers.attempt_number'), key: 'attempt_number' },
    { title: t('lms_admin_report.table.headers.attempted_at'), key: 'attempted_at' },
  ],
  effectiveness_roi: [
    { title: t('lms_admin_report.table.headers.course'), key: 'course_title' },
    { title: t('lms_admin_report.table.headers.completion_rate'), key: 'completion_rate' },
    { title: t('lms_admin_report.table.headers.pass_rate'), key: 'pass_rate' },
    { title: t('lms_admin_report.table.headers.avg_understanding_score'), key: 'avg_understanding_score' },
    { title: t('lms_admin_report.table.headers.roi_pct'), key: 'roi_pct' },
    { title: t('lms_admin_report.table.headers.snapshot_date'), key: 'snapshot_date' },
  ],
  understanding_scores: [
    { title: t('lms_admin_report.table.headers.employee'), key: 'employee_name' },
    { title: t('lms_admin_report.table.headers.course'), key: 'course_title' },
    { title: t('lms_admin_report.table.headers.total_score'), key: 'total_score' },
    { title: t('lms_admin_report.table.headers.quiz_score'), key: 'quiz_score' },
    { title: t('lms_admin_report.table.headers.time_score'), key: 'time_score' },
    { title: t('lms_admin_report.table.headers.refresher_recommended'), key: 'refresher_recommended' },
    { title: t('lms_admin_report.table.headers.assessed_at'), key: 'assessed_at' },
  ],
  qcto_learnership: [
    { title: t('lms_admin_report.table.headers.employee'), key: 'employee_name' },
    { title: t('lms_admin_report.table.headers.learnership_name'), key: 'learnership_name' },
    { title: t('lms_admin_report.table.headers.status'), key: 'status' },
    { title: t('lms_admin_report.table.headers.enrolled'), key: 'enrolled_at' },
    { title: t('lms_admin_report.table.headers.expected_end_date'), key: 'expected_end_date' },
  ],
  qcto_assessor_expiry: [
    { title: t('lms_admin_report.table.headers.name'), key: 'full_name' },
    { title: t('lms_admin_report.table.headers.person_type'), key: 'person_type' },
    { title: t('lms_admin_report.table.headers.seta_acronym'), key: 'seta_acronym' },
    { title: t('lms_admin_report.table.headers.registration_number'), key: 'registration_number' },
    { title: t('lms_admin_report.table.headers.registered_at'), key: 'registered_at' },
    { title: t('lms_admin_report.table.headers.expires_at'), key: 'expires_at' },
    { title: t('lms_admin_report.table.headers.status'), key: 'status' },
    { title: t('lms_admin_report.table.headers.days_until_expiry'), key: 'days_until_expiry' },
  ],
  registration_expiry: [
    { title: t('lms_admin_report.table.headers.role'), key: 'role' },
    { title: t('lms_admin_report.table.headers.name'), key: 'name' },
    { title: t('lms_admin_report.table.headers.seta'), key: 'seta' },
    { title: t('lms_admin_report.table.headers.registration_number'), key: 'registration_number' },
    { title: t('lms_admin_report.table.headers.registered_at'), key: 'registered_at' },
    { title: t('lms_admin_report.table.headers.expires_at'), key: 'expires_at' },
    { title: t('lms_admin_report.table.headers.days_until_expiry'), key: 'days_until_expiry' },
    { title: t('lms_admin_report.table.headers.alert_status'), key: 'alert_status' },
    { title: t('lms_admin_report.table.headers.status'), key: 'status' },
  ],
  qcto_wsp_atr: [
    { title: t('lms_admin_report.table.headers.plan_year'), key: 'plan_year' },
    { title: t('lms_admin_report.table.headers.seta_acronym'), key: 'seta_acronym' },
    { title: t('lms_admin_report.table.headers.status'), key: 'status' },
    { title: t('lms_admin_report.table.headers.submitted_at'), key: 'submitted_at' },
    { title: t('lms_admin_report.table.headers.atr_count'), key: 'atr_count' },
  ],
  qcto_nlrd: [
    { title: t('lms_admin_report.table.headers.reference_number'), key: 'reference_number' },
    { title: t('lms_admin_report.table.headers.submission_type'), key: 'submission_type' },
    { title: t('lms_admin_report.table.headers.status'), key: 'status' },
    { title: t('lms_admin_report.table.headers.period_start'), key: 'period_start' },
    { title: t('lms_admin_report.table.headers.period_end'), key: 'period_end' },
    { title: t('lms_admin_report.table.headers.submitted_at'), key: 'submitted_at' },
  ],
  dept_capability: [
    { title: t('lms_admin_report.table.headers.department'), key: 'department' },
    { title: t('lms_admin_report.table.headers.completion_rate'), key: 'completion_rate' },
    { title: t('lms_admin_report.table.headers.understanding_avg'), key: 'understanding_avg' },
    { title: t('lms_admin_report.table.headers.capability_score'), key: 'capability_score' },
    { title: t('lms_admin_report.table.headers.assessed_count'), key: 'assessed_count' },
  ],
  policy_violations: [
    { title: t('lms_admin_report.table.headers.employee'), key: 'employee_name' },
    { title: t('lms_admin_report.table.headers.course'), key: 'course_title' },
    { title: t('lms_admin_report.table.headers.violation_type'), key: 'violation_type' },
    { title: t('lms_admin_report.table.headers.severity'), key: 'severity' },
    { title: t('lms_admin_report.table.headers.action_taken'), key: 'action_taken' },
    { title: t('lms_admin_report.table.headers.is_resolved'), key: 'is_resolved' },
    { title: t('lms_admin_report.table.headers.created_at'), key: 'created_at' },
  ],
  development_plans: [
    { title: t('lms_admin_report.table.headers.employee'), key: 'employee_name' },
    { title: t('lms_admin_report.table.headers.course'), key: 'course_title' },
    { title: t('lms_admin_report.table.headers.plan_type'), key: 'plan_type' },
    { title: t('lms_admin_report.table.headers.status'), key: 'status' },
    { title: t('lms_admin_report.table.headers.planned_date'), key: 'planned_date' },
    { title: t('lms_admin_report.table.headers.completed_date'), key: 'completed_date' },
  ],
}

// ── Data map ──────────────────────────────────────────────────────────────────
const reportDataMap = computed<Record<string, ReportRow[]>>(() => ({
  learner_completion:   props.learnerCompletion,
  training_compliance:  props.trainingCompliance,
  overdue_assignments:  props.overdueAssignments,
  certification_expiry: props.certificationExpiry,
  skills_gap:           props.skillsGap,
  quiz_performance:     props.quizPerformance,
  effectiveness_roi:    props.effectivenessRoi,
  understanding_scores: props.understandingScores,
  qcto_learnership:     props.qctoLearnershipStatus,
  qcto_assessor_expiry: props.qctoAssessorExpiry,
  registration_expiry:  props.registrationExpiry,
  qcto_wsp_atr:         props.qctoWspAtrStatus,
  qcto_nlrd:            props.qctoNlrdPending,
  dept_capability:      props.departmentCapability,
  policy_violations:    props.policyViolations,
  development_plans:    props.developmentPlans,
}))

// ── Filters ───────────────────────────────────────────────────────────────────
function normalizeDate(value: unknown): number | null {
  if (!value) return null
  const s   = String(value)
  const iso = /^\d{4}-\d{2}-\d{2}$/.test(s) ? Date.parse(s + 'T00:00:00') : Date.parse(s)
  return Number.isNaN(iso) ? null : iso
}

function applyFilters(rows: ReportRow[]): ReportRow[] {
  let next = [...rows]

  if (filters.courseId) {
    const course = props.courses.find(c => c.id === filters.courseId)
    if (course) {
      next = next.filter(r => String(r.course_title ?? '') === course.title)
    }
  }

  if (filters.departments.length) {
    const deptNames = new Set(
      filters.departments
        .map(id => props.departments.find(d => String(d.id) === String(id))?.name ?? '')
        .filter(Boolean)
    )
    next = next.filter(r => !r.department || deptNames.has(String(r.department ?? '')))
  }

  const fromTs = normalizeDate(filters.dateFrom)
  const toTs   = normalizeDate(filters.dateTo)
  if (fromTs !== null || toTs !== null) {
    const dateKeys = [
      'enrolled_at', 'completed_at', 'due_date', 'assigned_at',
      'issued_at', 'expires_at', 'identified_at', 'attempted_at',
      'snapshot_date', 'assessed_at', 'enrolled_at', 'expected_end_date',
      'registered_at', 'submitted_at', 'period_start', 'created_at', 'planned_date',
    ]
    next = next.filter(r => {
      for (const k of dateKeys) {
        const dt = normalizeDate(r[k])
        if (dt === null) continue
        if (fromTs !== null && dt < fromTs) return false
        if (toTs   !== null && dt > toTs)   return false
        return true
      }
      return true
    })
  }

  return next
}

function reportRows(key: string): ReportRow[] {
  return applyFilters(reportDataMap.value[key] ?? [])
}

const activeRows    = computed(() => reportRows(activeTab.value))
const activeHeaders = computed(() => headersByReport[activeTab.value] ?? [])

function clearFilters() {
  filters.courseId    = null
  filters.departments = []
  filters.dateFrom    = null
  filters.dateTo      = null
}

// ── Actions ───────────────────────────────────────────────────────────────────
function setToast(text: string, color = 'success') {
  toast.text  = text
  toast.color = color
  toast.show  = true
}

function buildQs() {
  const p = new URLSearchParams()
  if (filters.courseId)            p.set('course_id',    String(filters.courseId))
  if (filters.departments.length) p.set('departments',  filters.departments.join(','))
  if (filters.dateFrom)           p.set('dateFrom',    filters.dateFrom)
  if (filters.dateTo)             p.set('dateTo',      filters.dateTo)
  return p.toString()
}

async function generateReport(key: string) {
  activeTab.value = key
  const qs  = buildQs()
  const url = `/lms/admin/reports/generate/${key}${qs ? `?${qs}` : ''}`
  try {
    const res = await fetch(url, { headers: { Accept: 'application/json' } })
    if (!res.ok) throw new Error()
    const payload = await res.json()
    setToast(t('lms_admin_report.toast.generated', { count: payload.count, key: key }))
  } catch {
    setToast(t('lms_admin_report.toast.error_generate', { key: key }), 'error')
  }
}

async function queueReport(key: string) {
  queueLoading.value = true
  try {
    const res = await fetch(`/lms/admin/reports/queue/${key}`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
      },
      body: JSON.stringify({}),
    })
    if (!res.ok) throw new Error()
    setToast(t('lms_admin_report.toast.queued', { key: key }))
  } catch {
    setToast(t('lms_admin_report.toast.error_queue', { key: key }), 'error')
  } finally {
    queueLoading.value = false
  }
}

function exportReport(key: string, format: 'csv' | 'pdf') {
  const qs  = buildQs()
  const url = `/lms/admin/reports/export/${key}/${format}${qs ? `?${qs}` : ''}`
  window.open(url, '_blank')
  setToast(t('lms_admin_report.toast.export_started', { format: format.toUpperCase(), key: key }))
}

function switchToReport(key: string) { activeTab.value = key }

// ── Formatting helpers ────────────────────────────────────────────────────────
function formatDate(val: unknown): string {
  if (!val) return '—'
  const d = new Date(String(val) + 'T00:00:00')
  if (Number.isNaN(d.getTime())) return String(val)
  return d.toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}

function statusColor(s: unknown): string {
  return ({
    completed: 'success',
    active:    'warning',
    overdue:   'error',
    passed:    'success',
    failed:    'error',
    draft:     'secondary',
    submitted: 'primary',
  } as Record<string, string>)[String(s)] ?? 'default'
}

function scoreColor(score: unknown): string {
  const n = Number(score)
  if (n >= 80) return 'success'
  if (n >= 60) return 'primary'
  if (n >= 40) return 'warning'
  return 'error'
}

function daysChipColor(days: unknown): string {
  const n = Number(days)
  if (n > 90)  return 'success'
  if (n > 30)  return 'warning'
  return 'error'
}

// Column totals
const sumColumns: Record<string, string[]> = {
  dept_capability:      ['assessed_count'],
  overdue_assignments:  ['days_overdue'],
}

const columnTotals = computed<Record<string, number>>(() => {
  const cols = sumColumns[activeTab.value] ?? []
  if (!cols.length) return {}
  const totals: Record<string, number> = {}
  for (const col of cols) {
    totals[col] = activeRows.value.reduce((sum, row) => sum + (Number(row[col]) || 0), 0)
  }
  return totals
})
</script>

<template>
  <VRow>
    <!-- Ribbon header -->
    <VCol cols="12">
      <VCard class="lms-ribbon">
        <VCardText class="d-flex flex-wrap align-center justify-space-between gap-4 py-6">
          <div>
            <div class="text-overline text-white opacity-90">Learning Management</div>
            <h4 class="text-h4 text-white mb-1">{{ t('lms_admin_report.page_title') }}</h4>
            <p class="mb-0 text-white opacity-90">
              {{ t('lms_admin_report.page_description') }}
            </p>
          </div>
          <div class="d-flex flex-column align-end gap-2">
            <VChip color="white" class="text-primary font-weight-bold" label>
              {{ t('lms_admin_report.active_rows', { count: activeRows.length }) }}
            </VChip>
            <div class="d-flex gap-2">
              <VBtn
                variant="outlined"
                color="white"
                size="small"
                prepend-icon="tabler-calendar-clock"
                href="/lms/admin/scheduled-reports"
              >
                {{ t('lms_admin_report.scheduled_reports') }}
              </VBtn>
            </div>
          </div>
        </VCardText>
      </VCard>
    </VCol>

    <!-- Filters -->
    <VCol cols="12">
      <VCard :title="t('lms_admin_report.filters.title')">
        <VCardText>
          <VRow>
            <VCol cols="12" sm="6" md="3">
              <VSelect
                v-model="filters.courseId"
                :items="courses"
                item-title="title"
                item-value="id"
                :label="t('lms_admin_report.filters.course')"
                prepend-inner-icon="tabler-book"
                clearable
                density="comfortable"
                :placeholder="t('lms_admin_report.filters.all_courses')"
              />
            </VCol>

            <VCol cols="12" sm="6" md="3">
              <VAutocomplete
                v-model="filters.departments"
                :items="departments"
                item-title="name"
                item-value="id"
                :label="t('lms_admin_report.filters.departments')"
                prepend-inner-icon="tabler-building"
                multiple
                chips
                closable-chips
                clearable
                density="comfortable"
                :placeholder="t('lms_admin_report.filters.all_departments')"
              />
            </VCol>

            <VCol cols="6" sm="3" md="1.5">
              <div class="native-date-field">
                <span class="native-date-field__label">{{ t('lms_admin_report.filters.date_from') }}</span>
                <input v-model="filters.dateFrom" type="date" class="native-date-field__input" />
              </div>
            </VCol>

            <VCol cols="6" sm="3" md="1.5">
              <div class="native-date-field">
                <span class="native-date-field__label">{{ t('lms_admin_report.filters.date_to') }}</span>
                <input v-model="filters.dateTo" type="date" class="native-date-field__input" />
              </div>
            </VCol>
          </VRow>

          <div v-if="filters.departments.length" class="d-flex flex-wrap gap-1 mt-1 mb-2">
            <VChip
              v-for="id in filters.departments"
              :key="`dept-${id}`"
              size="small"
              color="primary"
              closable
              @click:close="filters.departments = filters.departments.filter(x => x !== id)"
            >
              {{ departments.find(d => String(d.id) === String(id))?.name ?? id }}
            </VChip>
          </div>

          <div class="d-flex justify-end">
            <VBtn variant="tonal" color="secondary" prepend-icon="tabler-filter-off" @click="clearFilters">
              {{ t('lms_admin_report.filters.clear_filters') }}
            </VBtn>
          </div>
        </VCardText>
      </VCard>
    </VCol>

    <!-- Report cards -->
    <VCol cols="12">
      <VCard title="Reports Engine">
        <VCardText>
          <VRow>
            <VCol v-for="report in reportCards" :key="report.key" cols="12" md="6" lg="4">
              <VCard variant="outlined" class="h-100">
                <VCardText>
                  <div class="d-flex align-center gap-2 mb-2">
                    <VIcon :icon="report.icon" :color="report.color" size="20" />
                    <span class="text-subtitle-1 font-weight-medium">{{ report.title }}</span>
                  </div>
                  <div class="text-body-2 text-medium-emphasis mb-4">{{ report.description }}</div>
                  <div class="d-flex flex-wrap gap-2">
                    <VBtn size="small" color="primary" prepend-icon="tabler-player-play" @click="generateReport(report.key)">
                      {{ t('lms_admin_report.actions.generate') }}
                    </VBtn>
                    <VBtn size="small" variant="tonal" prepend-icon="tabler-table" @click="switchToReport(report.key)">
                      {{ t('lms_admin_report.actions.view_table') }}
                    </VBtn>
                    <VMenu>
                      <template #activator="{ props: menuProps }">
                        <VBtn v-bind="menuProps" size="small" color="secondary" variant="tonal" prepend-icon="tabler-upload">
                          {{ t('lms_admin_report.actions.export') }}
                        </VBtn>
                      </template>
                      <VList>
                        <VListItem :title="t('lms_admin_report.actions.export_csv')" @click="exportReport(report.key, 'csv')" />
                        <VListItem :title="t('lms_admin_report.actions.export_pdf')" @click="exportReport(report.key, 'pdf')" />
                        <VListItem :title="t('lms_admin_report.actions.queue_email')" @click="queueReport(report.key)" />
                      </VList>
                    </VMenu>
                  </div>
                </VCardText>
              </VCard>
            </VCol>
          </VRow>
        </VCardText>
      </VCard>
    </VCol>

    <!-- Data table -->
    <VCol cols="12">
      <VCard>
        <VTabs v-model="activeTab" show-arrows>
          <VTab value="learner_completion">   {{ t('lms_admin_report.tabs.learner_completion') }}        </VTab>
          <VTab value="training_compliance">  {{ t('lms_admin_report.tabs.training_compliance') }}        </VTab>
          <VTab value="overdue_assignments">  {{ t('lms_admin_report.tabs.overdue_assignments') }}        </VTab>
          <VTab value="certification_expiry"> {{ t('lms_admin_report.tabs.certification_expiry') }}        </VTab>
          <VTab value="skills_gap">           {{ t('lms_admin_report.tabs.skills_gap') }}                 </VTab>
          <VTab value="quiz_performance">     {{ t('lms_admin_report.tabs.quiz_performance') }}           </VTab>
          <VTab value="effectiveness_roi">    {{ t('lms_admin_report.tabs.effectiveness_roi') }}          </VTab>
          <VTab value="understanding_scores"> {{ t('lms_admin_report.tabs.understanding_scores') }}       </VTab>
          <VTab value="qcto_learnership">     {{ t('lms_admin_report.tabs.qcto_learnership') }}           </VTab>
          <VTab value="qcto_assessor_expiry"> {{ t('lms_admin_report.tabs.qcto_assessor_expiry') }}       </VTab>
          <VTab value="registration_expiry">  {{ t('lms_admin_report.tabs.registration_expiry') }}        </VTab>
          <VTab value="qcto_wsp_atr">         {{ t('lms_admin_report.tabs.qcto_wsp_atr') }}               </VTab>
          <VTab value="qcto_nlrd">            {{ t('lms_admin_report.tabs.qcto_nlrd') }}                  </VTab>
          <VTab value="dept_capability">      {{ t('lms_admin_report.tabs.dept_capability') }}            </VTab>
          <VTab value="policy_violations">    {{ t('lms_admin_report.tabs.policy_violations') }}          </VTab>
          <VTab value="development_plans">    {{ t('lms_admin_report.tabs.development_plans') }}          </VTab>
        </VTabs>

        <VDivider />

        <VCardText class="d-flex gap-2 flex-wrap align-center">
          <VBtn color="primary"   prepend-icon="tabler-player-play"   @click="generateReport(activeTab)">{{ t('lms_admin_report.actions.generate') }}</VBtn>
          <VBtn color="success"   prepend-icon="tabler-file-type-csv" variant="tonal" @click="exportReport(activeTab, 'csv')">{{ t('lms_admin_report.actions.export_csv') }}</VBtn>
          <VBtn color="error"     prepend-icon="tabler-file-type-pdf" variant="tonal" @click="exportReport(activeTab, 'pdf')">{{ t('lms_admin_report.actions.export_pdf') }}</VBtn>
          <VBtn color="secondary" prepend-icon="tabler-mail-forward"  variant="tonal" :loading="queueLoading" @click="queueReport(activeTab)">{{ t('lms_admin_report.actions.queue_email') }}</VBtn>
        </VCardText>

        <VDataTable
          :headers="activeHeaders"
          :items="activeRows"
          :items-per-page="15"
          height="600"
          fixed-header
        >
          <!-- Date columns -->
          <template #item.enrolled_at="{ item }">{{ formatDate(item.enrolled_at) }}</template>
          <template #item.completed_at="{ item }">{{ formatDate(item.completed_at) }}</template>
          <template #item.due_date="{ item }">{{ formatDate(item.due_date) }}</template>
          <template #item.assigned_at="{ item }">{{ formatDate(item.assigned_at) }}</template>
          <template #item.issued_at="{ item }">{{ formatDate(item.issued_at) }}</template>
          <template #item.expires_at="{ item }">{{ formatDate(item.expires_at) }}</template>
          <template #item.identified_at="{ item }">{{ formatDate(item.identified_at) }}</template>
          <template #item.attempted_at="{ item }">{{ formatDate(item.attempted_at) }}</template>
          <template #item.snapshot_date="{ item }">{{ formatDate(item.snapshot_date) }}</template>
          <template #item.assessed_at="{ item }">{{ formatDate(item.assessed_at) }}</template>
          <template #item.expected_end_date="{ item }">{{ formatDate(item.expected_end_date) }}</template>
          <template #item.registered_at="{ item }">{{ formatDate(item.registered_at) }}</template>
          <template #item.submitted_at="{ item }">{{ formatDate(item.submitted_at) }}</template>
          <template #item.period_start="{ item }">{{ formatDate(item.period_start) }}</template>
          <template #item.period_end="{ item }">{{ formatDate(item.period_end) }}</template>
          <template #item.created_at="{ item }">{{ formatDate(item.created_at) }}</template>
          <template #item.planned_date="{ item }">{{ formatDate(item.planned_date) }}</template>
          <template #item.completed_date="{ item }">{{ formatDate(item.completed_date) }}</template>

          <!-- Status chip -->
          <template #item.status="{ item }">
            <VChip size="small" variant="tonal" :color="statusColor(item.status)">
              {{ t(`lms_admin_report.statuses.${String(item.status).toLowerCase()}`) || item.status }}
            </VChip>
          </template>

          <!-- Score chip -->
          <template #item.score="{ item }">
            <VChip v-if="item.score !== null && item.score !== undefined" size="small" variant="tonal" :color="scoreColor(item.score)">
              {{ item.score }}
            </VChip>
            <span v-else>—</span>
          </template>

          <!-- Total score chip -->
          <template #item.total_score="{ item }">
            <VChip size="small" variant="tonal" :color="scoreColor(item.total_score)">
              {{ item.total_score }}
            </VChip>
          </template>

          <!-- Capability score chip -->
          <template #item.capability_score="{ item }">
            <VChip size="small" variant="tonal" :color="scoreColor(item.capability_score)">
              {{ item.capability_score }}
            </VChip>
          </template>

          <!-- Boolean: passed -->
          <template #item.passed="{ item }">
            <VIcon
              :icon="item.passed ? 'tabler-circle-check' : 'tabler-circle-x'"
              :color="item.passed ? 'success' : 'error'"
              size="18"
            />
          </template>

          <!-- Boolean: refresher_recommended -->
          <template #item.refresher_recommended="{ item }">
            <VIcon
              :icon="item.refresher_recommended ? 'tabler-circle-check' : 'tabler-circle-minus'"
              :color="item.refresher_recommended ? 'warning' : 'secondary'"
              size="18"
            />
          </template>

          <!-- Boolean: is_resolved -->
          <template #item.is_resolved="{ item }">
            <VIcon
              :icon="item.is_resolved ? 'tabler-circle-check' : 'tabler-circle-x'"
              :color="item.is_resolved ? 'success' : 'error'"
              size="18"
            />
          </template>

          <!-- Severity chip -->
          <template #item.severity="{ item }">
            <VChip size="small" variant="tonal"
              :color="String(item.severity) === 'critical' ? 'error'
                    : String(item.severity) === 'high' ? 'warning'
                    : String(item.severity) === 'medium' ? 'info'
                    : 'success'"
            >
              {{ t(`lms_admin_report.severity.${String(item.severity).toLowerCase()}`) || item.severity }}
            </VChip>
          </template>

          <!-- Days overdue chip -->
          <template #item.days_overdue="{ item }">
            <VChip size="small" variant="tonal" color="error">
              {{ item.days_overdue }}
            </VChip>
          </template>

          <!-- Days until expiry chip -->
          <template #item.days_until_expiry="{ item }">
            <template v-if="item.days_until_expiry !== null && item.days_until_expiry !== undefined">
              <VChip size="small" variant="tonal" :color="daysChipColor(item.days_until_expiry)">
                {{ item.days_until_expiry }}
              </VChip>
            </template>
            <span v-else>—</span>
          </template>

          <!-- Alert status chip -->
          <template #item.alert_status="{ item }">
            <VChip size="small" variant="tonal"
              :color="item.alert_status === 'expired'  ? 'error'
                    : item.alert_status === 'critical' ? 'error'
                    : item.alert_status === 'urgent'   ? 'warning'
                    : item.alert_status === 'warning'  ? 'info'
                    : 'secondary'"
            >
              {{ t(`lms_admin_report.alert_status.${String(item.alert_status).toLowerCase()}`) || item.alert_status }}
            </VChip>
          </template>

          <!-- ── Column totals footer ──────────────────────────────────── -->
          <template v-if="Object.keys(columnTotals).length" #tfoot>
            <tr class="totals-row">
              <td
                v-for="(header, idx) in activeHeaders"
                :key="header.key"
                class="pa-2 text-caption"
              >
                <template v-if="idx === 0">
                  <span class="font-weight-bold text-primary text-uppercase">
                    {{ t('lms_admin_report.total_rows', { count: activeRows.length }) }}
                  </span>
                </template>
                <template v-else-if="columnTotals[header.key] !== undefined">
                  <span class="font-weight-bold">{{ columnTotals[header.key] }}</span>
                </template>
              </td>
            </tr>
          </template>
        </VDataTable>

        <div
          v-if="Object.keys(columnTotals).length"
          class="d-flex flex-wrap align-center gap-x-6 gap-y-1 px-4 py-3 totals-strip"
        >
          <VIcon icon="tabler-math-symbols" size="16" color="primary" />
          <span class="text-caption font-weight-bold text-primary text-uppercase me-2">
            {{ t('lms_admin_report.total_rows', { count: activeRows.length }) }}
          </span>
          <template v-for="header in activeHeaders" :key="header.key">
            <div v-if="columnTotals[header.key] !== undefined" class="d-flex align-center gap-1">
              <span class="text-caption text-medium-emphasis">{{ header.title }}:</span>
              <strong class="text-caption text-high-emphasis">{{ columnTotals[header.key] }}</strong>
            </div>
          </template>
        </div>
      </VCard>
    </VCol>

    <VSnackbar v-model="toast.show" :color="toast.color" timeout="2500" location="top right">
      {{ toast.text }}
    </VSnackbar>
  </VRow>
</template>

<style scoped lang="scss">
.lms-ribbon {
  border: 0;
  overflow: hidden;
  background: linear-gradient(135deg, #0d47a1 0%, #1976d2 100%);
}

:deep(.v-data-table-header__content) { font-weight: 700; }

:deep(.totals-row) {
  position: sticky;
  bottom: 0;
  z-index: 2;
  td {
    background-color: rgb(var(--v-theme-surface));
    border-top: 2px solid rgb(var(--v-theme-primary));
    padding: 8px 12px;
  }
}

.totals-strip {
  border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  background-color: rgba(var(--v-theme-primary), 0.04);
}

.native-date-field {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 4px;
  padding: 6px 12px 7px;
  cursor: pointer;
  transition: border-color 0.15s;
  min-height: 48px;
  display: flex;
  flex-direction: column;
  justify-content: center;

  &:hover { border-color: rgba(var(--v-theme-on-surface), 0.87); }

  &:focus-within {
    border-color: rgb(var(--v-theme-primary));
    border-width: 2px;
    padding: 5px 11px 6px;
  }

  &__label {
    display: block;
    font-size: 0.75rem;
    line-height: 1;
    color: rgba(var(--v-theme-on-surface), 0.6);
    margin-bottom: 3px;
  }

  &__input {
    border: none;
    outline: none;
    background: transparent;
    color: rgb(var(--v-theme-on-surface));
    font-size: 0.875rem;
    width: 100%;
    cursor: pointer;
    font-family: inherit;

    &::-webkit-calendar-picker-indicator {
      cursor: pointer;
      opacity: 0.7;
      &:hover { opacity: 1; }
    }
  }
}
</style>
