<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import axios from 'axios'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Alert = {
  id: number; employee: string; employee_id: number
  alert_type: string; type_label: string; type_icon: string
  severity: string; severity_color: string
  status: string; status_color: string
  description: string; evidence: Record<string, any> | null
  ai_insight: string | null
  period_start: string; period_end: string; created_at: string
}
type Paginated<T> = { data: T[]; current_page: number; last_page: number; total: number }

const props = defineProps<{
  alerts:  Paginated<Alert>
  summary: { open: number; critical: number; high: number }
  filters: { type: string; severity: string; status: string }
  types:   Record<string, string>
}>()

const subNav         = ref('wellness')
const scanDialog     = ref(false)
const resolveDialog  = ref(false)
const resolveTarget  = ref<Alert | null>(null)
const explainLoading = ref<number | null>(null)
const insightDept    = ref('')
const insight        = ref<string | null>(null)
const insightLoading = ref(false)

const filterType     = ref(props.filters.type)
const filterSeverity = ref(props.filters.severity)
const filterStatus   = ref(props.filters.status)

const scanForm    = useForm({
  period_start: new Date(Date.now() - 30 * 86400000).toISOString().split('T')[0],
  period_end:   new Date().toISOString().split('T')[0],
})
const resolveForm = useForm({ manager_notes: '' })

const filterTypeOptions = computed(() => [
  { title: t('attendance_wellness.filter_all_types'), value: 'all' },
  ...Object.entries(props.types).map(([v, t]) => ({ title: t, value: v })),
])

const filterSeverityOptions = computed(() => [
  { title: t('attendance_wellness.filter_all'), value: 'all' },
  { title: 'low', value: 'low' },
  { title: 'medium', value: 'medium' },
  { title: 'high', value: 'high' },
  { title: 'critical', value: 'critical' },
])

const filterStatusOptions = computed(() => [
  { title: t('attendance_wellness.filter_all'), value: 'all' },
  { title: t('attendance_wellness.status_open'), value: 'open' },
  { title: t('attendance_wellness.status_acknowledged'), value: 'acknowledged' },
  { title: t('attendance_wellness.status_resolved'), value: 'resolved' },
  { title: t('attendance_wellness.status_dismissed'), value: 'dismissed' },
])

function applyFilter() {
  router.get('/attendance/wellness', {
    type:     filterType.value     !== 'all' ? filterType.value     : undefined,
    severity: filterSeverity.value !== 'all' ? filterSeverity.value : undefined,
    status:   filterStatus.value,
  }, { preserveState: true })
}

function runScan() { scanForm.post('/attendance/wellness/scan', { onSuccess: () => { scanDialog.value = false } }) }
function acknowledge(id: number) { useForm({}).post(`/attendance/wellness/alerts/${id}/acknowledge`, {}) }
function openResolve(a: Alert) { resolveTarget.value = a; resolveForm.reset(); resolveDialog.value = true }
function submitResolve() { if (!resolveTarget.value) return; resolveForm.post(`/attendance/wellness/alerts/${resolveTarget.value.id}/resolve`, { onSuccess: () => { resolveDialog.value = false } }) }
function dismiss(id: number) { useForm({}).post(`/attendance/wellness/alerts/${id}/dismiss`, {}) }
function explainAlert(id: number) { explainLoading.value = id; useForm({}).post(`/attendance/wellness/alerts/${id}/explain`, { onFinish: () => { explainLoading.value = null } }) }

async function getManagerInsight() {
  if (!insightDept.value) return
  insightLoading.value = true; insight.value = null
  try { const res = await axios.post('/attendance/wellness/manager-insights', { department: insightDept.value, period_start: scanForm.period_start, period_end: scanForm.period_end }); insight.value = res.data.insight }
  finally { insightLoading.value = false }
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <VCol cols="12" class="pb-0">
        <VCard variant="outlined">
          <VTabs v-model="subNav" color="primary" density="compact">
            <VTab value="workforce" href="/attendance/workforce"><VIcon start size="16" icon="tabler-account-group" />{{ t('attendance_wellness.tab_overview') }}</VTab>
            <VTab value="burnout" href="/attendance/workforce/burnout-profiles"><VIcon start size="16" icon="tabler-fire" />{{ t('attendance_wellness.tab_burnout') }}</VTab>
            <VTab value="predictions" href="/attendance/workforce/predictions"><VIcon start size="16" icon="tabler-crystal-ball" />{{ t('attendance_wellness.tab_predictions') }}</VTab>
            <VTab value="wellness" href="/attendance/wellness"><VIcon start size="16" icon="tabler-heart-pulse" />{{ t('attendance_wellness.tab_wellness') }}</VTab>
            <VTab value="dept-wellness" href="/attendance/wellness/departments"><VIcon start size="16" icon="tabler-domain" />{{ t('attendance_wellness.tab_dept_wellness') }}</VTab>
          </VTabs>
        </VCard>
      </VCol>
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div>
              <div class="text-overline text-medium-emphasis">{{ t('attendance_wellness.breadcrumb_module') }}</div>
              <h4 class="text-h4 mb-1">{{ t('attendance_wellness.title') }}</h4>
              <p class="mb-0 text-medium-emphasis">{{ t('attendance_wellness.subtitle') }}</p>
            </div>
            <div class="d-flex gap-2">
              <VBtn variant="text" color="info" href="/attendance/wellness/departments"><i class="fas fa-building me-2"></i>{{ t('attendance_wellness.dept_scores_btn') }}</VBtn>
              <VBtn color="primary" @click="scanDialog = true"><i class="fas fa-search-heart me-2"></i>{{ t('attendance_wellness.run_wellness_scan') }}</VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="4"><VCard color="warning" variant="tonal" class="text-center"><VCardText class="py-5"><div class="text-h4 font-weight-bold">{{ summary.open }}</div><div class="text-caption">{{ t('attendance_wellness.open_alerts') }}</div></VCardText></VCard></VCol>
      <VCol cols="12" md="4"><VCard color="deep-purple" variant="tonal" class="text-center"><VCardText class="py-5"><div class="text-h4 font-weight-bold">{{ summary.critical }}</div><div class="text-caption">{{ t('attendance_wellness.critical') }}</div></VCardText></VCard></VCol>
      <VCol cols="12" md="4"><VCard color="error" variant="tonal" class="text-center"><VCardText class="py-5"><div class="text-h4 font-weight-bold">{{ summary.high }}</div><div class="text-caption">{{ t('attendance_wellness.high_severity') }}</div></VCardText></VCard></VCol>

      <VCol cols="12">
        <VCard variant="outlined">
          <VCardTitle class="py-3 px-5"><i class="fas fa-user-tie me-2 text-primary"></i>{{ t('attendance_wellness.manager_insights_title') }}</VCardTitle>
          <VDivider />
          <VCardText class="py-3">
            <div class="d-flex flex-wrap gap-3 mb-3">
              <VTextField v-model="insightDept" :label="t('attendance_wellness.field_department_name')" density="compact" hide-details style="max-width:240px" />
              <VBtn color="primary" :loading="insightLoading" @click="getManagerInsight" :disabled="!insightDept"><i class="fas fa-robot me-2"></i>{{ t('attendance_wellness.generate_insight') }}</VBtn>
            </div>
            <div v-if="insight" class="pa-3 rounded" style="background:rgba(0,0,0,0.03);white-space:pre-wrap;font-size:0.88rem"><i class="fas fa-robot me-2 text-info"></i>{{ insight }}</div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap gap-3 py-3">
            <VSelect v-model="filterType" :items="filterTypeOptions" :label="t('attendance_wellness.filter_alert_type')" density="compact" hide-details style="max-width:220px" />
            <VSelect v-model="filterSeverity" :items="filterSeverityOptions" :label="t('attendance_wellness.filter_severity')" density="compact" hide-details style="max-width:160px" />
            <VSelect v-model="filterStatus" :items="filterStatusOptions" :label="t('attendance_wellness.filter_status')" density="compact" hide-details style="max-width:160px" />
            <VBtn color="primary" @click="applyFilter">{{ t('attendance_wellness.filter_btn') }}</VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12">
        <VCard>
          <VTable hover density="comfortable">
            <thead>
              <tr>
                <th>{{ t('attendance_wellness.table_employee') }}</th>
                <th>{{ t('attendance_wellness.table_alert_type') }}</th>
                <th>{{ t('attendance_wellness.table_severity') }}</th>
                <th>{{ t('attendance_wellness.table_status') }}</th>
                <th>{{ t('attendance_wellness.table_period') }}</th>
                <th>{{ t('attendance_wellness.table_detected') }}</th>
                <th class="text-center">{{ t('attendance_wellness.table_actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!alerts.data.length"><td colspan="7" class="text-center py-8 text-medium-emphasis">{{ t('attendance_wellness.no_alerts') }}</td></tr>
              <template v-for="a in alerts.data" :key="a.id">
                <tr>
                  <td class="font-weight-medium">{{ a.employee }}</td>
                  <td><div class="d-flex align-center gap-2"><i :class="a.type_icon" style="font-size:12px;opacity:0.6"></i>{{ a.type_label }}</div></td>
                  <td><VChip :color="a.severity_color" size="small" variant="tonal" class="text-capitalize">{{ a.severity }}</VChip></td>
                  <td><VChip :color="a.status_color" size="small" variant="tonal" class="text-capitalize">{{ a.status }}</VChip></td>
                  <td class="text-caption">{{ a.period_start }} – {{ a.period_end }}</td>
                  <td class="text-caption">{{ a.created_at.substring(0, 10) }}</td>
                  <td class="text-center">
                    <VBtn v-if="a.status === 'open'" icon size="small" variant="text" color="info" title="Acknowledge" @click="acknowledge(a.id)"><i class="fas fa-check"></i></VBtn>
                    <VBtn icon size="small" variant="text" color="success" title="Resolve" @click="openResolve(a)"><i class="fas fa-check-double"></i></VBtn>
                    <VBtn icon size="small" variant="text" color="secondary" title="Dismiss" @click="dismiss(a.id)"><i class="fas fa-times"></i></VBtn>
                    <VBtn icon size="small" variant="text" color="deep-purple" title="AI Explain" :loading="explainLoading === a.id" @click="explainAlert(a.id)"><i class="fas fa-robot"></i></VBtn>
                  </td>
                </tr>
                <tr v-if="a.description || a.ai_insight">
                  <td colspan="7" class="py-2 px-6" style="background:rgba(0,0,0,0.02)">
                    <div class="text-caption text-medium-emphasis mb-1">{{ a.description }}</div>
                    <VAlert v-if="a.ai_insight" type="info" variant="tonal" density="compact" class="mt-2" style="font-size:0.82rem"><i class="fas fa-robot me-2"></i><strong>{{ t('attendance_wellness.ai_insight_label') }}</strong> {{ a.ai_insight }}</VAlert>
                    <div v-if="a.evidence && Object.keys(a.evidence).length" class="d-flex flex-wrap gap-2 mt-1"><VChip v-for="(val, key) in a.evidence" :key="key" size="x-small" variant="outlined" class="text-capitalize">{{ String(key).replace(/_/g,' ') }}: {{ val }}</VChip></div>
                  </td>
                </tr>
              </template>
            </tbody>
          </VTable>
          <VCardText v-if="alerts.last_page > 1" class="d-flex justify-center py-4"><VPagination :model-value="alerts.current_page" :length="alerts.last_page" @update:model-value="page => router.get('/attendance/wellness', { page }, { preserveState: true })" /></VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="scanDialog" max-width="440">
      <VCard>
        <VCardTitle class="py-4 px-5">{{ t('attendance_wellness.scan_dialog_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="py-4">
          <VAlert type="info" variant="tonal" density="compact" class="mb-4" style="font-size:0.82rem">{{ t('attendance_wellness.scan_dialog_info') }}</VAlert>
          <VRow dense>
            <VCol cols="6"><VTextField v-model="scanForm.period_start" :label="t('attendance_wellness.field_period_start')" type="date" density="compact" /></VCol>
            <VCol cols="6"><VTextField v-model="scanForm.period_end" :label="t('attendance_wellness.field_period_end')" type="date" density="compact" /></VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="px-5 py-3"><VSpacer /><VBtn variant="text" @click="scanDialog = false">{{ t('attendance_wellness.cancel') }}</VBtn><VBtn color="primary" :loading="scanForm.processing" @click="runScan"><i class="fas fa-search-heart me-2"></i>{{ t('attendance_wellness.run_scan') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="resolveDialog" max-width="420">
      <VCard v-if="resolveTarget">
        <VCardTitle class="py-4 px-5">{{ t('attendance_wellness.resolve_dialog_title', { employee: resolveTarget.employee }) }}</VCardTitle>
        <VDivider />
        <VCardText class="py-4"><VTextarea v-model="resolveForm.manager_notes" :label="t('attendance_wellness.field_manager_notes')" rows="3" density="compact" /></VCardText>
        <VDivider />
        <VCardActions class="px-5 py-3"><VSpacer /><VBtn variant="text" @click="resolveDialog = false">{{ t('attendance_wellness.cancel') }}</VBtn><VBtn color="success" :loading="resolveForm.processing" @click="submitResolve">{{ t('attendance_wellness.resolve') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
