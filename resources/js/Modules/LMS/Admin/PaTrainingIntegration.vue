<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  readiness?:       { data: any[]; links?: any[]; meta?: any }
  recommendations?: { data: any[]; links?: any[]; meta?: any }
  devPlans?:        { data: any[]; links?: any[]; meta?: any }
  paPlans?:         { id: number; label: string }[]
  summary?:         { total: number; ready: number; avg_score: number; pending_recs: number }
  progressReport?:  Record<string, any> | null
  filters?:         Record<string, any>
  activeTab?:       string
}>()

const tab = ref(props.activeTab ?? 'readiness')

const cycleFilter = ref<string>(props.filters?.cycle_id ?? '')
const recFilters  = ref({ cycle_id: props.filters?.cycle_id ?? '', employee_id: props.filters?.employee_id ?? '', status: props.filters?.status ?? '' })

const computeDialog   = ref(false)
const recommendDialog = ref(false)
const planDialog      = ref(false)
const reportLoading   = ref(false)

const computeForm = useForm({ employee_id: '', cycle_id: '' })
const recForm     = useForm({ employee_id: '', cycle_id: '' })
const planForm    = useForm({ pa_plan_id: null as number | null, course_ids: [] as string[] })
const reportForm  = ref({ employee_id: '', cycle_id: '' })

const readinessRows = computed(() => props.readiness?.data ?? [])
const recRows       = computed(() => props.recommendations?.data ?? [])
const devPlanRows   = computed(() => props.devPlans?.data ?? [])
const paPlansOptions = computed(() => props.paPlans ?? [])

const summaryKpis = computed(() => {
  const s = props.summary
  if (!s) return []
  return [
    { label: t('lms_pa_training.summary.total_employees'), value: s.total, color: 'text-primary' },
    { label: t('lms_pa_training.summary.promotion_ready'), value: s.ready, color: 'text-success' },
    { label: t('lms_pa_training.summary.avg_training_score'), value: `${s.avg_score}%`, color: 'text-info' },
    { label: t('lms_pa_training.summary.pending_recs'), value: s.pending_recs, color: 'text-warning' },
  ]
})

const reportMetrics = computed(() => {
  const r = props.progressReport
  if (!r) return []
  return [
    { label: t('lms_pa_training.progress_report.metrics.completion_rate'), value: `${r.completion_rate}%`, color: rateColor(r.completion_rate, 70) },
    { label: t('lms_pa_training.progress_report.metrics.pass_rate'), value: `${r.pass_rate}%`, color: rateColor(r.pass_rate, 70) },
    { label: t('lms_pa_training.progress_report.metrics.understanding'), value: r.understanding_avg != null ? `${r.understanding_avg}%` : '—', color: rateColor(r.understanding_avg, 65) },
    { label: t('lms_pa_training.progress_report.metrics.enrollments'), value: `${r.completed_count}/${r.total_enrollments}`, color: '' },
  ]
})

const readinessHeaders = computed(() => [
  { title: t('lms_pa_training.readiness.table.employee'),      key: 'employee_name' },
  { title: t('lms_pa_training.readiness.table.score'),         key: 'overall_training_score', align: 'end' as const },
  { title: t('lms_pa_training.readiness.table.completion'),    key: 'completion_rate',        align: 'end' as const },
  { title: t('lms_pa_training.readiness.table.pass_rate'),     key: 'pass_rate',              align: 'end' as const },
  { title: t('lms_pa_training.readiness.table.understanding'), key: 'understanding_avg',      align: 'end' as const },
  { title: t('lms_pa_training.readiness.table.dev_plan'),      key: 'development_completion_pct', align: 'end' as const },
  { title: t('lms_pa_training.readiness.table.readiness'),     key: 'is_promotion_ready' },
  { title: t('lms_pa_training.readiness.table.computed'),      key: 'computed_at' },
  { title: '', key: 'actions', sortable: false },
])

const recHeaders = computed(() => [
  { title: t('lms_pa_training.recommendations.table.employee'), key: 'employee_name' },
  { title: t('lms_pa_training.recommendations.table.gap_area'), key: 'gap_area' },
  { title: t('lms_pa_training.recommendations.table.score'),    key: 'score_pct', align: 'end' as const },
  { title: t('lms_pa_training.recommendations.table.course'),   key: 'course_title' },
  { title: t('lms_pa_training.recommendations.table.priority'), key: 'priority' },
  { title: t('lms_pa_training.recommendations.table.status'),   key: 'status' },
  { title: t('lms_pa_training.recommendations.table.action'),   key: 'actions', sortable: false },
])

const planHeaders = computed(() => [
  { title: t('lms_pa_training.dev_plans.table.employee'), key: 'employee_name' },
  { title: t('lms_pa_training.dev_plans.table.manager'),  key: 'manager_name' },
  { title: t('lms_pa_training.dev_plans.table.title'),    key: 'title' },
  { title: t('lms_pa_training.dev_plans.table.status'),   key: 'status' },
  { title: t('lms_pa_training.dev_plans.table.progress'), key: 'overall_progress' },
  { title: t('lms_pa_training.dev_plans.table.courses'),  key: 'activities_count', align: 'end' as const },
  { title: t('lms_pa_training.dev_plans.table.due'),      key: 'target_date' },
  { title: '', key: 'actions', sortable: false },
])

const recStatusOptions = computed(() => [
  t('lms_pa_training.recommendations.statuses.pending'),
  t('lms_pa_training.recommendations.statuses.accepted'),
  t('lms_pa_training.recommendations.statuses.enrolled'),
  t('lms_pa_training.recommendations.statuses.completed'),
  t('lms_pa_training.recommendations.statuses.dismissed'),
])

function filterReadiness() { router.get(route('lms.admin.pa.dashboard'), { cycle_id: cycleFilter.value }, { preserveState: true, replace: true }) }
function filterRecs() { router.get(route('lms.admin.pa.recommendations'), recFilters.value, { preserveState: true, replace: true }) }
function openComputeFor(item: any) { computeForm.employee_id = String(item.user_id ?? ''); computeForm.cycle_id = String(item.cycle_id ?? ''); computeDialog.value = true }
function openReport(item: any) { reportForm.value.employee_id = String(item.user_id ?? ''); reportForm.value.cycle_id = String(item.cycle_id ?? ''); tab.value = 'progress_report'; loadReport() }
function computeReadiness() { computeForm.post(route('lms.admin.pa.compute-readiness'), { onSuccess: () => { computeDialog.value = false; computeForm.reset() } }) }
function generateRecs() { recForm.post(route('lms.admin.pa.recommend'), { onSuccess: () => { recommendDialog.value = false; recForm.reset() } }) }
function updateRec(id: number, status: string) { router.patch(route('lms.admin.pa.recommendations.update', id), { status }, { preserveScroll: true }) }
function createPlan() { const ids = planForm.course_ids.flatMap((v: any) => String(v).split(',')).map((v: string) => parseInt(v.trim())).filter((v: number) => !isNaN(v)); planForm.transform(() => ({ pa_plan_id: planForm.pa_plan_id, course_ids: ids })).post(route('lms.admin.pa.plans.create'), { onSuccess: () => { planDialog.value = false; planForm.reset() } }) }
function syncPlan(id: number) { router.post(route('lms.admin.pa.plans.track', id), {}, { preserveScroll: true }) }
async function loadReport() { reportLoading.value = true; router.get(route('lms.admin.pa.progress-report'), { employee_id: reportForm.value.employee_id, cycle_id: reportForm.value.cycle_id }, { preserveState: true, replace: true, onFinish: () => { reportLoading.value = false } }) }

function fmt(v: number | null | undefined): string { if (v == null) return '—'; return Number(v).toFixed(1) }
function rateColor(v: number | null | undefined, threshold: number): string { if (v == null) return ''; return v >= threshold ? 'text-success' : v >= threshold * 0.7 ? 'text-warning' : 'text-error' }
function progressColor(pct: number): string { if (pct >= 80) return 'success'; if (pct >= 50) return 'info'; if (pct >= 25) return 'warning'; return 'error' }
</script>

<template>
  <VContainer fluid class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('lms_pa_training.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('lms_pa_training.subtitle') }}</p>
      </div>
    </div>

    <VRow v-if="summary" class="mb-4">
      <VCol v-for="kpi in summaryKpis" :key="kpi.label" cols="12" sm="6" md="3">
        <VCard rounded="lg" border><VCardText class="pa-4"><div class="text-caption text-medium-emphasis text-uppercase mb-1">{{ kpi.label }}</div><div class="text-h5 font-weight-bold" :class="kpi.color">{{ kpi.value }}</div></VCardText></VCard>
      </VCol>
    </VRow>

    <VTabs v-model="tab" class="mb-4">
      <VTab value="readiness">{{ t('lms_pa_training.tabs.readiness') }}</VTab>
      <VTab value="recommendations">{{ t('lms_pa_training.tabs.recommendations') }}</VTab>
      <VTab value="dev_plans">{{ t('lms_pa_training.tabs.dev_plans') }}</VTab>
      <VTab value="progress_report">{{ t('lms_pa_training.tabs.progress_report') }}</VTab>
    </VTabs>

    <VWindow v-model="tab">
      <VWindowItem value="readiness">
        <VCard border rounded="lg">
          <VCardTitle class="pa-4 d-flex align-center justify-space-between">
            <span>{{ t('lms_pa_training.readiness.title') }}</span>
            <div class="d-flex gap-2">
              <VTextField v-model="cycleFilter" :label="t('lms_pa_training.readiness.cycle_id_label')" type="number" density="compact" hide-details style="width:140px" clearable @keyup.enter="filterReadiness" />
              <VBtn color="primary" size="small" @click="computeDialog = true">{{ t('lms_pa_training.readiness.compute_button') }}</VBtn>
            </div>
          </VCardTitle>
          <VDivider />
          <VDataTable :headers="readinessHeaders" :items="readinessRows" :items-per-page="25" class="text-body-2">
            <template #item.overall_training_score="{ item }"><span class="font-weight-medium">{{ fmt(item.overall_training_score) }}%</span></template>
            <template #item.completion_rate="{ item }">{{ fmt(item.completion_rate) }}%</template>
            <template #item.pass_rate="{ item }">{{ fmt(item.pass_rate) }}%</template>
            <template #item.understanding_avg="{ item }">{{ fmt(item.understanding_avg) }}%</template>
            <template #item.development_completion_pct="{ item }">{{ fmt(item.development_completion_pct) }}%</template>
            <template #item.is_promotion_ready="{ item }"><VChip :color="item.readiness_color" size="small" label>{{ item.readiness_level?.replace('_', ' ') }}</VChip></template>
            <template #item.computed_at="{ item }"><span class="text-caption">{{ item.computed_at?.slice(0, 10) ?? '—' }}</span></template>
            <template #item.actions="{ item }"><VBtn icon variant="text" size="small" :title="t('lms_pa_training.readiness.table.compute_tooltip')" @click="openComputeFor(item)"><VIcon size="16">mdi-refresh</VIcon></VBtn><VBtn icon variant="text" size="small" :title="t('lms_pa_training.readiness.table.report_tooltip')" @click="openReport(item)"><VIcon size="16">mdi-file-chart</VIcon></VBtn></template>
          </VDataTable>
        </VCard>
      </VWindowItem>

      <VWindowItem value="recommendations">
        <VCard border rounded="lg">
          <VCardTitle class="pa-4 d-flex align-center justify-space-between"><span>{{ t('lms_pa_training.recommendations.title') }}</span><VBtn color="primary" size="small" @click="recommendDialog = true">{{ t('lms_pa_training.recommendations.generate_button') }}</VBtn></VCardTitle>
          <VDivider />
          <VCardText class="pa-2">
            <div class="d-flex gap-2 pa-2 flex-wrap">
              <VTextField v-model="recFilters.cycle_id" :label="t('lms_pa_training.recommendations.filter_cycle')" type="number" density="compact" hide-details style="width:120px" clearable />
              <VTextField v-model="recFilters.employee_id" :label="t('lms_pa_training.recommendations.filter_employee')" type="number" density="compact" hide-details style="width:130px" clearable />
              <VSelect v-model="recFilters.status" :items="recStatusOptions" :label="t('lms_pa_training.recommendations.filter_status')" density="compact" hide-details style="width:150px" clearable />
              <VBtn size="small" @click="filterRecs">{{ t('lms_pa_training.recommendations.filter_button') }}</VBtn>
            </div>
          </VCardText>
          <VDataTable :headers="recHeaders" :items="recRows" :items-per-page="25" class="text-body-2">
            <template #item.priority="{ item }"><VChip :color="item.priority_color" size="x-small" label>{{ item.priority }}</VChip></template>
            <template #item.score_pct="{ item }"><span :class="item.score_pct != null && item.score_pct < 60 ? 'text-error' : ''">{{ item.score_pct != null ? fmt(item.score_pct) + '%' : '—' }}</span></template>
            <template #item.status="{ item }"><VChip :color="item.status_color" size="x-small" label>{{ item.status }}</VChip></template>
            <template #item.actions="{ item }"><VSelect :model-value="item.status" :items="recStatusOptions" density="compact" hide-details style="width:140px" @update:model-value="(v) => updateRec(item.id, v)" /></template>
          </VDataTable>
        </VCard>
      </VWindowItem>

      <VWindowItem value="dev_plans">
        <VCard border rounded="lg">
          <VCardTitle class="pa-4 d-flex align-center justify-space-between"><span>{{ t('lms_pa_training.dev_plans.title') }}</span><VBtn color="primary" size="small" @click="planDialog = true">{{ t('lms_pa_training.dev_plans.create_button') }}</VBtn></VCardTitle>
          <VDivider />
          <VDataTable :headers="planHeaders" :items="devPlanRows" :items-per-page="25" class="text-body-2">
            <template #item.overall_progress="{ item }"><div class="d-flex align-center gap-2" style="min-width:120px"><VProgressLinear :model-value="item.overall_progress" :color="progressColor(item.overall_progress)" rounded height="8" style="min-width:80px" /><span class="text-caption">{{ item.overall_progress }}%</span></div></template>
            <template #item.status="{ item }"><VChip :color="item.status_color" size="x-small" label>{{ item.status }}</VChip></template>
            <template #item.actions="{ item }"><VBtn icon variant="text" size="small" :title="t('lms_pa_training.dev_plans.table.sync_tooltip')" @click="syncPlan(item.id)"><VIcon size="16">mdi-sync</VIcon></VBtn></template>
          </VDataTable>
        </VCard>
      </VWindowItem>

      <VWindowItem value="progress_report">
        <VCard border rounded="lg">
          <VCardText class="pa-4">
            <div class="d-flex gap-3 align-center mb-4 flex-wrap">
              <VTextField v-model="reportForm.employee_id" :label="t('lms_pa_training.progress_report.employee_id_label')" type="number" density="compact" hide-details style="width:150px" />
              <VTextField v-model="reportForm.cycle_id" :label="t('lms_pa_training.progress_report.cycle_id_label')" type="number" density="compact" hide-details style="width:120px" />
              <VBtn color="primary" @click="loadReport" :loading="reportLoading">{{ t('lms_pa_training.progress_report.load_button') }}</VBtn>
            </div>
            <template v-if="progressReport">
              <h3 class="text-subtitle-1 font-weight-bold mb-3">{{ progressReport.employee_name }}</h3>
              <VRow class="mb-4"><VCol cols="6" sm="3" v-for="m in reportMetrics" :key="m.label"><VCard border rounded="lg"><VCardText class="pa-3 text-center"><div class="text-caption text-medium-emphasis">{{ m.label }}</div><div class="text-h6 font-weight-bold" :class="m.color">{{ m.value }}</div></VCardText></VCard></VCol></VRow>
              <h4 class="text-body-2 font-weight-bold mb-2">{{ t('lms_pa_training.progress_report.recommendations_title') }}</h4>
              <VTable density="compact" class="mb-4">
                <thead><tr><th>{{ t('lms_pa_training.progress_report.table.gap_area') }}</th><th>{{ t('lms_pa_training.progress_report.table.course') }}</th><th>{{ t('lms_pa_training.progress_report.table.status') }}</th><th>{{ t('lms_pa_training.progress_report.table.priority') }}</th></tr></thead>
                <tbody><tr v-for="r in progressReport.recommendations" :key="r.gap_area"><td>{{ r.gap_area }}</td><td>{{ r.course ?? '—' }}</td><td>{{ r.status }}</td><td>{{ r.priority }}</td></tr><tr v-if="!progressReport.recommendations?.length"><td colspan="4" class="text-center text-medium-emphasis pa-4">{{ t('lms_pa_training.progress_report.table.no_recommendations') }}</td></tr></tbody>
              </VTable>
              <template v-if="progressReport.development_plan">
                <h4 class="text-body-2 font-weight-bold mb-2">{{ t('lms_pa_training.progress_report.dev_plan_title') }} {{ progressReport.development_plan.title }}</h4>
                <div class="d-flex align-center gap-2 mb-3"><VProgressLinear :model-value="progressReport.development_plan.overall_progress" :color="progressColor(progressReport.development_plan.overall_progress)" height="10" rounded /><span class="text-caption">{{ progressReport.development_plan.overall_progress }}%</span></div>
                <VTable density="compact">
                  <thead><tr><th>{{ t('lms_pa_training.progress_report.dev_plan_table.course') }}</th><th>{{ t('lms_pa_training.progress_report.dev_plan_table.status') }}</th><th>{{ t('lms_pa_training.progress_report.dev_plan_table.progress') }}</th><th>{{ t('lms_pa_training.progress_report.dev_plan_table.understanding') }}</th></tr></thead>
                  <tbody><tr v-for="a in progressReport.development_plan.activities" :key="a.course_id"><td>{{ a.title }}</td><td>{{ a.status }}</td><td>{{ a.progress_pct }}%</td><td>{{ a.understanding_score != null ? fmt(a.understanding_score) + '%' : '—' }}</td></tr></tbody>
                </VTable>
              </template>
            </template>
            <VAlert v-else-if="!reportLoading" type="info" variant="tonal" class="mt-2">{{ t('lms_pa_training.progress_report.no_report') }}</VAlert>
          </VCardText>
        </VCard>
      </VWindowItem>
    </VWindow>

    <VDialog v-model="computeDialog" max-width="440">
      <VCard>
        <VCardTitle class="pa-4">{{ t('lms_pa_training.dialogs.compute_title') }}</VCardTitle><VDivider />
        <VCardText class="pa-4">
          <VTextField v-model="computeForm.employee_id" :label="t('lms_pa_training.dialogs.employee_id_label')" type="number" class="mb-3" />
          <VTextField v-model="computeForm.cycle_id" :label="t('lms_pa_training.dialogs.cycle_id_label')" type="number" />
        </VCardText>
        <VCardActions class="pa-4 pt-0 justify-end"><VBtn variant="text" @click="computeDialog = false">{{ t('lms_pa_training.dialogs.cancel') }}</VBtn><VBtn color="primary" @click="computeReadiness" :loading="computeForm.processing">{{ t('lms_pa_training.dialogs.compute') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="recommendDialog" max-width="440">
      <VCard>
        <VCardTitle class="pa-4">{{ t('lms_pa_training.dialogs.recommend_title') }}</VCardTitle><VDivider />
        <VCardText class="pa-4">
          <VTextField v-model="recForm.employee_id" :label="t('lms_pa_training.dialogs.employee_id_label')" type="number" class="mb-3" />
          <VTextField v-model="recForm.cycle_id" :label="t('lms_pa_training.dialogs.cycle_id_label')" type="number" />
        </VCardText>
        <VCardActions class="pa-4 pt-0 justify-end"><VBtn variant="text" @click="recommendDialog = false">{{ t('lms_pa_training.dialogs.cancel') }}</VBtn><VBtn color="primary" @click="generateRecs" :loading="recForm.processing">{{ t('lms_pa_training.dialogs.generate') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="planDialog" max-width="500">
      <VCard>
        <VCardTitle class="pa-4">{{ t('lms_pa_training.dialogs.plan_title') }}</VCardTitle><VDivider />
        <VCardText class="pa-4">
          <VSelect v-model="planForm.pa_plan_id" :items="paPlansOptions" :label="t('lms_pa_training.dialogs.pa_plan_label')" item-title="label" item-value="id" class="mb-3" />
          <VCombobox v-model="planForm.course_ids" :label="t('lms_pa_training.dialogs.course_ids_label')" chips multiple closable-chips hint="Enter course IDs" persistent-hint />
        </VCardText>
        <VCardActions class="pa-4 pt-0 justify-end"><VBtn variant="text" @click="planDialog = false">{{ t('lms_pa_training.dialogs.cancel') }}</VBtn><VBtn color="primary" @click="createPlan" :loading="planForm.processing">{{ t('lms_pa_training.dialogs.create_plan') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
