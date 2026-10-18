<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const MetricRow = {
  props: { label: String, value: [String, Number], color: { type: String, default: '' } },
  template: `<div class="d-flex justify-space-between align-center py-1">
    <span class="text-body-2 text-medium-emphasis">{{ label }}</span>
    <span class="text-body-2 font-weight-medium" :class="color ? 'text-' + color : ''">{{ value ?? '—' }}</span>
  </div>`,
}

const props = defineProps<{
  kpi?: {
    avg_completion_rate: number
    avg_pass_rate: number
    avg_understanding_score: number
    avg_roi_pct: number
    courses_with_data: number
  }
  courseRows?: any[]
  recentReports?: any[]
  courses: { id: number; title: string }[]
  filters?: Record<string, any>
  course?: { id: number; title: string } | null
  completion?: any
  passRate?: any
  understanding?: any
  performance?: any
  vsAppraisal?: any
  vsIncidents?: any
  roi?: any
  costs?: any
  activeTab?: string
}>()

const confirmDialog = ref()
const activeTab = ref(props.activeTab ?? (props.course ? 'analyse' : 'overview'))

// ── Date helpers ──────────────────────────────────────────────────────────────
/** Convertit une date ISO (ou autre) en "YYYY-MM-DD" pour un <input type="date"> */
function toDateInput(value: string | null | undefined): string {
  if (!value) return ''
  if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return value
  const d = new Date(value)
  if (isNaN(d.getTime())) return ''
  return d.toISOString().slice(0, 10)
}

/** Formate une date pour affichage humain (ex: tableaux) */
function formatDate(value: string | null | undefined): string {
  if (!value) return '—'
  const d = new Date(value)
  if (isNaN(d.getTime())) return '—'
  return d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

const filterForm = ref({
  course_id:    props.filters?.course_id    ?? null,
  period_start: toDateInput(props.filters?.period_start),
  period_end:   toDateInput(props.filters?.period_end),
})

// ── KPI tiles ─────────────────────────────────────────────────────────────────
const kpiTiles = computed(() => [
  { label: t('lms_effectiveness.kpi.avg_completion_rate'), value: (props.kpi?.avg_completion_rate ?? 0) + '%', icon: 'mdi-check-circle', color: 'success' },
  { label: t('lms_effectiveness.kpi.avg_pass_rate'),       value: (props.kpi?.avg_pass_rate ?? 0) + '%',       icon: 'mdi-school',      color: 'primary' },
  { label: t('lms_effectiveness.kpi.avg_understanding'),   value: (props.kpi?.avg_understanding_score ?? 0) + '', icon: 'mdi-brain',   color: 'secondary' },
  { label: t('lms_effectiveness.kpi.avg_roi'),             value: (props.kpi?.avg_roi_pct ?? 0) + '%',         icon: 'mdi-cash-multiple', color: (props.kpi?.avg_roi_pct ?? 0) >= 0 ? 'success' : 'error' },
])

// ── Table headers ─────────────────────────────────────────────────────────────
const courseTableHeaders = computed(() => [
  { title: t('lms_effectiveness.overview.table.course'),            key: 'course_title' },
  { title: t('lms_effectiveness.overview.table.completion'),        key: 'completion_rate' },
  { title: t('lms_effectiveness.overview.table.pass_rate'),         key: 'pass_rate' },
  { title: t('lms_effectiveness.overview.table.understanding_imp'), key: 'understanding_imp' },
  { title: t('lms_effectiveness.overview.table.performance_delta'), key: 'performance_delta' },
  { title: t('lms_effectiveness.overview.table.roi'),              key: 'roi_pct' },
  { title: t('lms_effectiveness.overview.table.computed'),         key: 'computed_at' },
  { title: '', key: 'actions', sortable: false as const },
])

const costHeaders = computed(() => [
  { title: t('lms_effectiveness.costs.table.course'),            key: 'course.title' },
  { title: t('lms_effectiveness.costs.table.cost_per_employee'), key: 'cost_per_employee' },
  { title: t('lms_effectiveness.costs.table.period'),            key: 'period' },
  { title: t('lms_effectiveness.costs.table.notes'),             key: 'notes' },
  { title: '', key: 'actions', sortable: false as const },
])

const reportHeaders = computed(() => [
  { title: t('lms_effectiveness.reports.table.title'),        key: 'title' },
  { title: t('lms_effectiveness.reports.table.period'),       key: 'period' },
  { title: t('lms_effectiveness.reports.table.roi'),          key: 'roi' },
  { title: t('lms_effectiveness.reports.table.generated_by'), key: 'generated_by' },
  { title: t('lms_effectiveness.reports.table.generated_at'), key: 'generated_at' },
])

// ── Filter / analyse ──────────────────────────────────────────────────────────
function applyFilters() {
  router.get(route('lms.admin.effectiveness'), filterForm.value, { preserveScroll: true })
}

function analyseCourse() {
  if (!filterForm.value.course_id) return
  router.get(route('lms.admin.effectiveness.analyse', filterForm.value.course_id), {
    period_start: filterForm.value.period_start,
    period_end:   filterForm.value.period_end,
  })
}

// ── Report form ───────────────────────────────────────────────────────────────
const reportDialog = ref(false)
const reportForm   = useForm({ course_id: null as number | null, period_start: '', period_end: '' })
const reportErrors = computed(() => reportForm.errors)

function submitReport() {
  reportForm.post(route('lms.admin.effectiveness.report'), {
    onSuccess: () => { reportDialog.value = false; reportForm.reset() },
  })
}

// ── Cost form ─────────────────────────────────────────────────────────────────
const costDialog   = ref(false)
const editingCost  = ref<any>(null)
const costForm     = useForm({
  course_id:         null as number | null,
  cost_per_employee: '' as string | number,
  currency:          'ZAR',
  period_start:      '',
  period_end:        '',
  notes:             '',
})
const costErrors = computed(() => costForm.errors)

function editCost(item: any) {
  editingCost.value = item
  costForm.cost_per_employee = item.cost_per_employee
  costForm.currency          = item.currency
  costForm.period_start      = toDateInput(item.period_start)
  costForm.period_end        = toDateInput(item.period_end)
  costForm.notes             = item.notes ?? ''
  costDialog.value = true
}

function closeCostDialog() {
  costDialog.value = false
  editingCost.value = null
  costForm.reset()
}

function submitCost() {
  if (editingCost.value) {
    costForm.patch(route('lms.admin.costs.update', editingCost.value.id), {
      onSuccess: closeCostDialog,
    })
  } else {
    costForm.post(route('lms.admin.costs.store'), {
      onSuccess: closeCostDialog,
    })
  }
}

function deleteCost(item: any) {
  confirmDialog.value.open(
    { title: t('lms_effectiveness.costs.delete_title'), message: t('lms_effectiveness.costs.delete_message'), confirmText: t('lms_effectiveness.costs.delete_confirm'), confirmColor: 'error', icon: 'tabler-trash' },
    () => { router.delete(route('lms.admin.costs.destroy', item.id)) },
  )
}

// ── Formatting helpers ────────────────────────────────────────────────────────
function fmt(v: any) {
  if (v === null || v === undefined) return '—'
  return typeof v === 'number' ? v.toFixed(1) : v
}
function fmtPct(v: any)      { return v !== null && v !== undefined ? fmt(v) + '%' : '—' }
function fmtDelta(v: any)    { if (v === null || v === undefined) return '—'; return (v >= 0 ? '+' : '') + fmt(v) }
function fmtCurrency(v: any) { if (v === null || v === undefined) return '0.00'; return Number(v).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',') }

function rateColor(v: any) {
  if (v === null || v === undefined) return 'grey'
  if (v >= 80) return 'success'
  if (v >= 60) return 'warning'
  return 'error'
}

function deltaClass(v: any) {
  if (v === null || v === undefined) return 'text-medium-emphasis'
  return v >= 0 ? 'text-success font-weight-medium' : 'text-error font-weight-medium'
}

function deltaColorVal(v: any) {
  if (v === null || v === undefined) return ''
  return v >= 0 ? 'success' : 'error'
}
</script>

<template>
  <div class="pa-4">
    <ConfirmDialog ref="confirmDialog" />
    <div class="d-flex align-center justify-space-between mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('lms_effectiveness.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">{{ t('lms_effectiveness.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VBtn v-if="course" variant="tonal" :href="route('lms.admin.effectiveness')" prepend-icon="mdi-arrow-left">
          {{ t('lms_effectiveness.all_courses') }}
        </VBtn>
        <VBtn color="primary" prepend-icon="mdi-file-chart" @click="reportDialog = true">
          {{ t('lms_effectiveness.generate_report') }}
        </VBtn>
      </div>
    </div>

    <!-- Filters -->
    <VCard class="mb-4" variant="outlined">
      <VCardText>
        <VRow dense>
          <VCol cols="12" md="3">
            <VSelect v-model="filterForm.course_id" :items="courses" item-title="title" item-value="id" :label="t('lms_effectiveness.filters.course')" clearable density="compact" hide-details />
          </VCol>
          <VCol cols="12" md="3">
            <VTextField v-model="filterForm.period_start" :label="t('lms_effectiveness.filters.period_start')" type="date" density="compact" hide-details />
          </VCol>
          <VCol cols="12" md="3">
            <VTextField v-model="filterForm.period_end" :label="t('lms_effectiveness.filters.period_end')" type="date" density="compact" hide-details />
          </VCol>
          <VCol cols="12" md="3" class="d-flex align-center gap-2">
            <VBtn color="primary" @click="applyFilters" density="compact">{{ t('lms_effectiveness.filters.apply') }}</VBtn>
            <VBtn v-if="filterForm.course_id" color="secondary" variant="tonal" @click="analyseCourse" density="compact" prepend-icon="mdi-magnify">{{ t('lms_effectiveness.filters.analyse') }}</VBtn>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VTabs v-model="activeTab" class="mb-4">
      <VTab value="overview">{{ t('lms_effectiveness.tabs.overview') }}</VTab>
      <VTab v-if="course" value="analyse">{{ t('lms_effectiveness.tabs.analyse') }}</VTab>
      <VTab value="costs">{{ t('lms_effectiveness.tabs.costs') }}</VTab>
      <VTab value="reports">{{ t('lms_effectiveness.tabs.reports') }}</VTab>
    </VTabs>

    <VWindow v-model="activeTab">

      <!-- ── Overview Tab ── -->
      <VWindowItem value="overview">
        <VRow class="mb-4">
          <VCol cols="12" sm="6" md="3" v-for="tile in kpiTiles" :key="tile.label">
            <VCard variant="outlined" class="text-center pa-4">
              <VIcon :icon="tile.icon" :color="tile.color" size="32" class="mb-2" />
              <div class="text-h5 font-weight-bold" :class="`text-${tile.color}`">{{ tile.value }}</div>
              <div class="text-caption text-medium-emphasis">{{ tile.label }}</div>
            </VCard>
          </VCol>
        </VRow>

        <VCard variant="outlined">
          <VCardTitle class="pa-4">{{ t('lms_effectiveness.overview.title') }}</VCardTitle>
          <VDataTable :headers="courseTableHeaders" :items="courseRows" density="compact" hover>
            <template #item.completion_rate="{ item }">
              <div class="d-flex align-center gap-2"><VProgressLinear :model-value="item.completion_rate ?? 0" :color="rateColor(item.completion_rate)" height="8" rounded style="width:80px" /><span class="text-body-2">{{ fmt(item.completion_rate) }}%</span></div>
            </template>
            <template #item.pass_rate="{ item }">
              <div class="d-flex align-center gap-2"><VProgressLinear :model-value="item.pass_rate ?? 0" :color="rateColor(item.pass_rate)" height="8" rounded style="width:80px" /><span class="text-body-2">{{ fmt(item.pass_rate) }}%</span></div>
            </template>
            <template #item.roi_pct="{ item }">
              <VChip v-if="item.roi_pct !== null" :color="item.roi_pct >= 0 ? 'success' : 'error'" size="small" variant="tonal">{{ item.roi_pct >= 0 ? '+' : '' }}{{ fmt(item.roi_pct) }}%</VChip>
              <span v-else class="text-medium-emphasis">—</span>
            </template>
            <template #item.understanding_imp="{ item }"><span :class="deltaClass(item.understanding_imp)">{{ item.understanding_imp !== null ? (item.understanding_imp >= 0 ? '+' : '') + fmt(item.understanding_imp) : '—' }}</span></template>
            <template #item.performance_delta="{ item }"><span :class="deltaClass(item.performance_delta)">{{ item.performance_delta !== null ? (item.performance_delta >= 0 ? '+' : '') + fmt(item.performance_delta) : '—' }}</span></template>
            <template #item.computed_at="{ item }">{{ formatDate(item.computed_at) }}</template>
            <template #item.actions="{ item }"><VBtn size="small" variant="text" icon="mdi-magnify" :href="route('lms.admin.effectiveness.analyse', item.course_id)" /></template>
          </VDataTable>
        </VCard>
      </VWindowItem>

      <!-- ── Course Analysis Tab ── -->
      <VWindowItem value="analyse">
        <template v-if="course">
          <h2 class="text-h6 font-weight-bold mb-4">{{ course.title }}</h2>

          <VRow class="mb-4">
            <VCol cols="12" md="4">
              <VCard variant="outlined" class="pa-4">
                <div class="text-subtitle-2 font-weight-bold mb-3">{{ t('lms_effectiveness.analyse.completion_pass') }}</div>
                <MetricRow :label="t('lms_effectiveness.analyse.enrolled')" :value="completion?.total" />
                <MetricRow :label="t('lms_effectiveness.analyse.completed')" :value="completion?.completed" />
                <MetricRow :label="t('lms_effectiveness.analyse.completion_rate')" :value="fmtPct(completion?.completion_rate)" />
                <VDivider class="my-2" />
                <MetricRow :label="t('lms_effectiveness.analyse.pass_rate')" :value="fmtPct(passRate?.pass_rate)" />
                <MetricRow :label="t('lms_effectiveness.analyse.avg_score')" :value="fmtPct(passRate?.avg_score)" />
                <MetricRow :label="t('lms_effectiveness.analyse.repeated_failures')" :value="fmtPct(passRate?.repeated_failure_rate)" color="error" />
              </VCard>
            </VCol>

            <VCol cols="12" md="4">
              <VCard variant="outlined" class="pa-4">
                <div class="text-subtitle-2 font-weight-bold mb-3">{{ t('lms_effectiveness.analyse.understanding_score') }}</div>
                <MetricRow :label="t('lms_effectiveness.analyse.users_with_scores')" :value="understanding?.users_with_scores" />
                <MetricRow :label="t('lms_effectiveness.analyse.avg_first_score')" :value="fmt(understanding?.avg_first_score)" />
                <MetricRow :label="t('lms_effectiveness.analyse.avg_latest_score')" :value="fmt(understanding?.avg_latest_score)" />
                <MetricRow :label="t('lms_effectiveness.analyse.avg_improvement')" :value="fmtDelta(understanding?.avg_improvement)" :color="deltaColorVal(understanding?.avg_improvement)" />
                <VDivider class="my-2" />
                <MetricRow :label="t('lms_effectiveness.analyse.improved')" :value="understanding?.improved_count" color="success" />
                <MetricRow :label="t('lms_effectiveness.analyse.declined')" :value="understanding?.declined_count" color="error" />
              </VCard>
            </VCol>

            <VCol cols="12" md="4">
              <VCard variant="outlined" class="pa-4">
                <div class="text-subtitle-2 font-weight-bold mb-3">{{ t('lms_effectiveness.analyse.post_training_performance') }}</div>
                <MetricRow :label="t('lms_effectiveness.analyse.users_compared')" :value="performance?.users_compared" />
                <MetricRow :label="t('lms_effectiveness.analyse.avg_score_before')" :value="fmt(performance?.avg_before)" />
                <MetricRow :label="t('lms_effectiveness.analyse.avg_score_after')" :value="fmt(performance?.avg_after)" />
                <MetricRow :label="t('lms_effectiveness.analyse.delta')" :value="fmtDelta(performance?.delta)" :color="deltaColorVal(performance?.delta)" />
                <VDivider class="my-2" />
                <MetricRow :label="t('lms_effectiveness.analyse.improved')" :value="performance?.improved_count" color="success" />
                <MetricRow :label="t('lms_effectiveness.analyse.declined')" :value="performance?.declined_count" color="error" />
              </VCard>
            </VCol>
          </VRow>

          <VRow class="mb-4">
            <VCol cols="12" md="6">
              <VCard variant="outlined" class="pa-4">
                <div class="text-subtitle-2 font-weight-bold mb-3">{{ t('lms_effectiveness.analyse.vs_appraisal') }}</div>
                <MetricRow :label="t('lms_effectiveness.analyse.trained_avg')" :value="fmt(vsAppraisal?.trained_avg)" />
                <MetricRow :label="t('lms_effectiveness.analyse.untrained_avg')" :value="fmt(vsAppraisal?.untrained_avg)" />
                <MetricRow :label="t('lms_effectiveness.analyse.gap')" :value="fmtDelta(vsAppraisal?.gap)" :color="deltaColorVal(vsAppraisal?.gap)" />
                <MetricRow :label="t('lms_effectiveness.analyse.trained_count')" :value="vsAppraisal?.trained_count" />
              </VCard>
            </VCol>

            <VCol cols="12" md="6">
              <VCard variant="outlined" class="pa-4">
                <div class="text-subtitle-2 font-weight-bold mb-3">{{ t('lms_effectiveness.analyse.vs_incidents') }}</div>
                <MetricRow :label="t('lms_effectiveness.analyse.users_compared')" :value="vsIncidents?.users_compared" />
                <MetricRow :label="t('lms_effectiveness.analyse.avg_incidents_before')" :value="fmt(vsIncidents?.avg_before)" />
                <MetricRow :label="t('lms_effectiveness.analyse.avg_incidents_after')" :value="fmt(vsIncidents?.avg_after)" />
                <MetricRow :label="t('lms_effectiveness.analyse.incident_delta')" :value="fmtDelta(vsIncidents?.incident_delta)" :color="vsIncidents?.incident_delta < 0 ? 'success' : 'error'" />
                <MetricRow :label="t('lms_effectiveness.analyse.window', { days: vsIncidents?.window_days ?? 0 })" :value="(vsIncidents?.window_days ?? 0) + ' days each side'" />
              </VCard>
            </VCol>
          </VRow>

          <!-- ROI Card -->
          <VCard variant="outlined" class="pa-4 mb-4">
            <div class="text-subtitle-2 font-weight-bold mb-3">{{ t('lms_effectiveness.analyse.roi_title') }}</div>
            <VRow>
              <VCol cols="6" md="3"><div class="text-caption text-medium-emphasis">{{ t('lms_effectiveness.analyse.completed_employees') }}</div><div class="text-h6">{{ roi?.completed_count ?? 0 }}</div></VCol>
              <VCol cols="6" md="3"><div class="text-caption text-medium-emphasis">{{ t('lms_effectiveness.analyse.training_cost') }}</div><div class="text-h6">{{ roi?.currency }} {{ fmtCurrency(roi?.total_training_cost) }}</div></VCol>
              <VCol cols="6" md="3"><div class="text-caption text-medium-emphasis">{{ t('lms_effectiveness.analyse.total_benefit') }}</div><div class="text-h6 text-success">{{ roi?.currency }} {{ fmtCurrency(roi?.total_benefit) }}</div></VCol>
              <VCol cols="6" md="3"><div class="text-caption text-medium-emphasis">{{ t('lms_effectiveness.analyse.roi_label') }}</div><div class="text-h5 font-weight-bold" :class="roi?.roi_pct >= 0 ? 'text-success' : 'text-error'">{{ roi?.roi_pct !== null ? (roi.roi_pct >= 0 ? '+' : '') + fmt(roi.roi_pct) + '%' : t('lms_effectiveness.analyse.na') }}</div></VCol>
            </VRow>
            <VRow class="mt-2">
              <VCol cols="6" md="3"><div class="text-caption text-medium-emphasis">{{ t('lms_effectiveness.analyse.cost_per_employee') }}</div><div class="text-body-1">{{ roi?.currency }} {{ fmtCurrency(roi?.cost_per_employee) }}</div></VCol>
              <VCol cols="6" md="3"><div class="text-caption text-medium-emphasis">{{ t('lms_effectiveness.analyse.performance_gain') }}</div><div class="text-body-1 text-success">{{ roi?.currency }} {{ fmtCurrency(roi?.performance_gain_value) }}</div></VCol>
              <VCol cols="6" md="3"><div class="text-caption text-medium-emphasis">{{ t('lms_effectiveness.analyse.incident_savings') }}</div><div class="text-body-1 text-success">{{ roi?.currency }} {{ fmtCurrency(roi?.incident_savings) }}</div></VCol>
            </VRow>
          </VCard>
        </template>
      </VWindowItem>

      <!-- ── Training Costs Tab ── -->
      <VWindowItem value="costs">
        <div class="d-flex justify-end mb-3">
          <VBtn color="primary" prepend-icon="mdi-plus" @click="costDialog = true">{{ t('lms_effectiveness.costs.add_record') }}</VBtn>
        </div>
        <VCard variant="outlined">
          <VDataTable :headers="costHeaders" :items="costs?.data ?? costs ?? []" density="compact" hover>
            <template #item.cost_per_employee="{ item }">{{ item.currency }} {{ fmtCurrency(item.cost_per_employee) }}</template>
            <template #item.period="{ item }">{{ formatDate(item.period_start) }} → {{ formatDate(item.period_end) }}</template>
            <template #item.actions="{ item }"><VBtn size="small" variant="text" icon="mdi-pencil" @click="editCost(item)" /><VBtn size="small" variant="text" icon="mdi-delete" color="error" @click="deleteCost(item)" /></template>
          </VDataTable>
        </VCard>
      </VWindowItem>

      <!-- ── Saved Reports Tab ── -->
      <VWindowItem value="reports">
        <VCard variant="outlined">
          <VDataTable :headers="reportHeaders" :items="recentReports" density="compact" hover>
            <template #item.period="{ item }">{{ formatDate(item.period_start) }} → {{ formatDate(item.period_end) }}</template>
            <template #item.generated_at="{ item }">{{ formatDate(item.generated_at) }}</template>
            <template #item.roi="{ item }">
              <template v-if="item.metrics?.roi?.roi_pct !== undefined && item.metrics.roi.roi_pct !== null">
                <VChip :color="item.metrics.roi.roi_pct >= 0 ? 'success' : 'error'" size="small" variant="tonal">{{ item.metrics.roi.roi_pct >= 0 ? '+' : '' }}{{ fmt(item.metrics.roi.roi_pct) }}%</VChip>
              </template>
              <span v-else class="text-medium-emphasis">—</span>
            </template>
          </VDataTable>
        </VCard>
      </VWindowItem>
    </VWindow>

    <!-- ── Generate Report Dialog ── -->
    <VDialog v-model="reportDialog" max-width="480">
      <VCard>
        <VCardTitle class="pa-4">{{ t('lms_effectiveness.dialog.report_title') }}</VCardTitle>
        <VCardText>
          <VSelect v-model="reportForm.course_id" :items="courses" item-title="title" item-value="id" :label="t('lms_effectiveness.dialog.course')" :error-messages="reportErrors.course_id" class="mb-3" />
          <VTextField v-model="reportForm.period_start" :label="t('lms_effectiveness.dialog.period_start')" type="date" :error-messages="reportErrors.period_start" class="mb-3" />
          <VTextField v-model="reportForm.period_end" :label="t('lms_effectiveness.dialog.period_end')" type="date" :error-messages="reportErrors.period_end" class="mb-3" />
        </VCardText>
        <VCardActions class="pa-4 pt-0"><VSpacer /><VBtn variant="text" @click="reportDialog = false">{{ t('lms_effectiveness.dialog.cancel') }}</VBtn><VBtn color="primary" :loading="reportForm.processing" @click="submitReport">{{ t('lms_effectiveness.dialog.generate') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Cost Dialog ── -->
    <VDialog v-model="costDialog" max-width="520">
      <VCard>
        <VCardTitle class="pa-4">{{ editingCost ? t('lms_effectiveness.costs.dialog.title_edit') : t('lms_effectiveness.costs.dialog.title_new') }}</VCardTitle>
        <VCardText>
          <VSelect v-if="!editingCost" v-model="costForm.course_id" :items="courses" item-title="title" item-value="id" :label="t('lms_effectiveness.costs.dialog.course')" :error-messages="costErrors.course_id" class="mb-3" />
          <VTextField v-model="costForm.cost_per_employee" :label="t('lms_effectiveness.costs.dialog.cost_per_employee')" type="number" min="0" step="0.01" :error-messages="costErrors.cost_per_employee" class="mb-3" />
          <VTextField v-model="costForm.currency" :label="t('lms_effectiveness.costs.dialog.currency')" placeholder="ZAR" :error-messages="costErrors.currency" class="mb-3" />
          <VTextField v-model="costForm.period_start" :label="t('lms_effectiveness.costs.dialog.period_start')" type="date" :error-messages="costErrors.period_start" class="mb-3" />
          <VTextField v-model="costForm.period_end" :label="t('lms_effectiveness.costs.dialog.period_end')" type="date" :error-messages="costErrors.period_end" class="mb-3" />
          <VTextarea v-model="costForm.notes" :label="t('lms_effectiveness.costs.dialog.notes')" rows="2" auto-grow :error-messages="costErrors.notes" />
        </VCardText>
        <VCardActions class="pa-4 pt-0"><VSpacer /><VBtn variant="text" @click="closeCostDialog">{{ t('lms_effectiveness.costs.dialog.cancel') }}</VBtn><VBtn color="primary" :loading="costForm.processing" @click="submitCost">{{ editingCost ? t('lms_effectiveness.costs.dialog.update') : t('lms_effectiveness.costs.dialog.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
