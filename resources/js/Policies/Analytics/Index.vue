<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useTheme } from 'vuetify'
import VueApexCharts from 'vue3-apexcharts'
import type { ApexOptions } from 'apexcharts'

useTheme()
const success   = '#28c76f'
const warning   = '#ff9f43'
const error     = '#ea5455'
const info      = '#00cfe8'
const secondary = '#82868b'

interface Kpis {
  total_policies: number; active_policies: number; require_attestation: number
  violations: number; open_violations: number
  attestations: number; completed_attestations: number; overdue_attestations: number
  attestation_rate: number
}
interface FilterOptions {
  categories: { id: number; name: string }[]
  departments: { id: number; name: string }[]
  statuses: string[]
}
interface ActiveFilters { category_id: number | null; department_id: number | null; status: string | null }

const props = defineProps<{
  period: string; dateFrom: string | null; dateTo: string | null
  filters: ActiveFilters; filterOptions: FilterOptions; kpis: Kpis
  byStatus: { status: string; count: number }[]
  byCategory: { category: string; count: number; active: number }[]
  violationTrend: { month: string; total: number; resolved: number }[]
  violationBySeverity: { severity: string; count: number }[]
  violationByOutcome: { outcome: string; count: number }[]
  attestationTrend: { month: string; total: number; completed: number; overdue: number }[]
  attestationByStatus: { status: string; count: number }[]
  complianceByDept: { department: string; violations: number; resolved: number }[]
  topViolatedPolicies: { policy_name: string; violations: number; open_count: number }[]
  openViolations: { policy_name: string; employee: string; severity: string; outcome: string; status: string; created_at: string }[]
}>()

const filtersOpen   = ref(false)
const periodOptions = [
  { value: 'month', label: 'This Month' }, { value: 'quarter', label: 'Last 3 Months' },
  { value: 'halfyear', label: 'Last 6 Months' }, { value: 'year', label: 'This Year' }, { value: 'lastyear', label: 'Last Year' },
]
const form = ref({
  period:        props.period ?? 'year',
  date_from:     props.dateFrom ?? '',
  date_to:       props.dateTo ?? '',
  category_id:   props.filters.category_id   ? String(props.filters.category_id)   : '',
  department_id: props.filters.department_id ? String(props.filters.department_id) : '',
  status:        props.filters.status ?? '',
})
const useCustomDates = ref(!!(props.dateFrom || props.dateTo))
const activeFilterCount = computed(() => [props.filters.category_id, props.filters.department_id, props.filters.status].filter(Boolean).length + (props.dateFrom || props.dateTo ? 1 : 0))

function applyFilters() {
  const params: Record<string, string> = {}
  if (useCustomDates.value) { if (form.value.date_from) params.date_from = form.value.date_from; if (form.value.date_to) params.date_to = form.value.date_to }
  else params.period = form.value.period
  if (form.value.category_id)   params.category_id   = form.value.category_id
  if (form.value.department_id) params.department_id = form.value.department_id
  if (form.value.status)        params.status        = form.value.status
  router.get(window.location.pathname, params, { preserveScroll: true })
}
function clearFilters() {
  form.value = { period: 'year', date_from: '', date_to: '', category_id: '', department_id: '', status: '' }
  useCustomDates.value = false
  router.get(window.location.pathname, { period: 'year' }, { preserveScroll: true })
}

const fmtN = (v: number) => Number(v).toLocaleString('en-ZA')
const monthLabel = (m: string) => { const [y, mo] = m.split('-'); return new Date(+y, +mo - 1).toLocaleString('en-ZA', { month: 'short', year: '2-digit' }) }

const kpiCards = computed(() => [
  { title: 'Total Policies',       value: fmtN(props.kpis.total_policies),        sub: `${props.kpis.active_policies} active`,            icon: 'tabler-file-text',     color: 'primary' },
  { title: 'Violations',           value: fmtN(props.kpis.violations),             sub: `${props.kpis.open_violations} still open`,        icon: 'tabler-alert-octagon',     color: 'error' },
  { title: 'Attestation Rate',     value: `${props.kpis.attestation_rate}%`,       sub: `${props.kpis.completed_attestations} completed`,  icon: 'tabler-lock-pen',          color: props.kpis.attestation_rate >= 80 ? 'success' : 'warning' },
  { title: 'Overdue Attestations', value: fmtN(props.kpis.overdue_attestations),   sub: 'Past deadline, not signed',                       icon: 'tabler-calendar-exclamation',    color: 'warning' },
  { title: 'Require Attestation',  value: fmtN(props.kpis.require_attestation),    sub: 'Policies with mandatory sign-off',                icon: 'tabler-signature',         color: 'info' },
  { title: 'Open Violations',      value: fmtN(props.kpis.open_violations),        sub: 'Pending investigation or action',                 icon: 'tabler-shield-exclamation',      color: 'error' },
])

const violationTrendOptions = computed<ApexOptions>(() => ({
  chart: { type: 'line', toolbar: { show: false }, zoom: { enabled: false } },
  stroke: { curve: 'smooth', width: [3, 2] }, colors: [error, success],
  xaxis: { categories: props.violationTrend.map(r => monthLabel(r.month)) },
  yaxis: { labels: { formatter: (v: number) => String(Math.round(v)) } },
  tooltip: { y: { formatter: (v: number) => `${v} violations` } },
  legend: { position: 'top' }, markers: { size: 4 }, grid: { borderColor: '#f1f3f4' },
}))
const violationTrendSeries = computed(() => [
  { name: 'Total', data: props.violationTrend.map(r => r.total) },
  { name: 'Resolved', data: props.violationTrend.map(r => r.resolved) },
])

const attestationTrendOptions = computed<ApexOptions>(() => ({
  chart: { type: 'area', toolbar: { show: false }, zoom: { enabled: false } },
  stroke: { curve: 'smooth', width: [2, 2, 2] },
  fill: { type: 'gradient', gradient: { opacityFrom: 0.3, opacityTo: 0.05 } },
  colors: ['#696cff', success, warning],
  xaxis: { categories: props.attestationTrend.map(r => monthLabel(r.month)) },
  yaxis: { labels: { formatter: (v: number) => String(Math.round(v)) } },
  legend: { position: 'top' }, grid: { borderColor: '#f1f3f4' },
}))
const attestationTrendSeries = computed(() => [
  { name: 'Total', data: props.attestationTrend.map(r => r.total) },
  { name: 'Completed', data: props.attestationTrend.map(r => r.completed) },
  { name: 'Overdue', data: props.attestationTrend.map(r => r.overdue) },
])

const severityColors: Record<string, string> = { low: info, medium: warning, high: error, critical: '#8B0000' }
const severityOptions = computed<ApexOptions>(() => ({
  chart: { type: 'donut' }, labels: props.violationBySeverity.map(r => r.severity),
  colors: props.violationBySeverity.map(r => severityColors[r.severity] ?? secondary),
  legend: { position: 'bottom' }, dataLabels: { enabled: true },
  plotOptions: { pie: { donut: { size: '65%' } } },
}))
const severitySeries = computed(() => props.violationBySeverity.map(r => r.count))

const attestStatusColors: Record<string, string> = { pending: warning, completed: success, failed: error, overdue: '#ff6b35' }
const attestStatusOptions = computed<ApexOptions>(() => ({
  chart: { type: 'pie' }, labels: props.attestationByStatus.map(r => r.status),
  colors: props.attestationByStatus.map(r => attestStatusColors[r.status] ?? secondary),
  legend: { position: 'bottom' }, dataLabels: { enabled: true },
}))
const attestStatusSeries = computed(() => props.attestationByStatus.map(r => r.count))

const policyStatusColors: Record<string, string> = { active: success, draft: warning, under_review: info, archived: secondary }
const byPolicyStatusOptions = computed<ApexOptions>(() => ({
  chart: { type: 'donut' }, labels: props.byStatus.map(r => r.status),
  colors: props.byStatus.map(r => policyStatusColors[r.status] ?? secondary),
  legend: { position: 'bottom' },
  plotOptions: { pie: { donut: { size: '65%', labels: { show: true, total: { show: true, label: 'Policies' } } } } },
  dataLabels: { enabled: false },
}))
const byPolicyStatusSeries = computed(() => props.byStatus.map(r => r.count))
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-4">
      <div>
        <div class="text-overline text-medium-emphasis">Policy Enforcement</div>
        <h4 class="text-h4 font-weight-bold">BI Report Analytics</h4>
        <p class="text-medium-emphasis mb-0">Policy portfolio, violations, attestation compliance, severity breakdown, and open cases.</p>
      </div>
      <div class="d-flex align-center gap-2 flex-wrap">
        <VBtn variant="tonal" prepend-icon="tabler-adjustments-alt" @click="filtersOpen = !filtersOpen">
          Filters <VBadge v-if="activeFilterCount > 0" :content="activeFilterCount" color="primary" floating />
        </VBtn>
        <VBtn variant="tonal" prepend-icon="tabler-file-analytics" href="/policy-reports">Reports</VBtn>
      </div>
    </div>

    <VExpandTransition>
      <VCard v-if="filtersOpen" class="mb-5" variant="outlined">
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12" class="pb-1">
              <VBtnToggle v-model="useCustomDates" density="compact" rounded="lg" variant="outlined" mandatory>
                <VBtn :value="false" size="small">Period Preset</VBtn>
                <VBtn :value="true" size="small">Custom Dates</VBtn>
              </VBtnToggle>
            </VCol>
            <VCol v-if="!useCustomDates" cols="12" sm="6" md="3">
              <VSelect v-model="form.period" :items="periodOptions" item-title="label" item-value="value" label="Period" density="compact" variant="outlined" hide-details />
            </VCol>
            <template v-if="useCustomDates">
              <VCol cols="12" sm="6" md="3"><VTextField v-model="form.date_from" type="date" label="From Date" density="compact" variant="outlined" hide-details /></VCol>
              <VCol cols="12" sm="6" md="3"><VTextField v-model="form.date_to" type="date" label="To Date" density="compact" variant="outlined" hide-details /></VCol>
            </template>
            <VCol cols="12" sm="6" md="3">
              <VSelect v-model="form.category_id" :items="filterOptions.categories" item-title="name" item-value="id" label="Category" density="compact" variant="outlined" hide-details clearable />
            </VCol>
            <VCol cols="12" sm="6" md="3">
              <VSelect v-model="form.department_id" :items="filterOptions.departments" item-title="name" item-value="id" label="Department" density="compact" variant="outlined" hide-details clearable />
            </VCol>
            <VCol cols="12" sm="6" md="3">
              <VSelect v-model="form.status" :items="filterOptions.statuses" label="Policy Status" density="compact" variant="outlined" hide-details clearable />
            </VCol>
            <VCol cols="12" class="d-flex gap-2 pt-2">
              <VBtn color="primary" prepend-icon="tabler-search" @click="applyFilters">Apply Filters</VBtn>
              <VBtn variant="tonal" prepend-icon="tabler-x" @click="clearFilters">Clear All</VBtn>
            </VCol>
          </VRow>
        </VCardText>
      </VCard>
    </VExpandTransition>

    <div v-if="activeFilterCount > 0" class="d-flex flex-wrap gap-2 mb-4">
      <VChip v-if="dateFrom || dateTo" size="small" closable color="primary" @click:close="clearFilters">{{ dateFrom }} → {{ dateTo }}</VChip>
      <VChip v-else size="small" color="primary" variant="tonal">{{ periodOptions.find(p => p.value === period)?.label }}</VChip>
      <VChip v-if="filters.category_id"   size="small" closable color="secondary" variant="tonal" @click:close="() => { form.category_id = ''; applyFilters() }">Category: {{ filterOptions.categories.find(c => c.id === filters.category_id)?.name }}</VChip>
      <VChip v-if="filters.department_id" size="small" closable color="secondary" variant="tonal" @click:close="() => { form.department_id = ''; applyFilters() }">Dept: {{ filterOptions.departments.find(d => d.id === filters.department_id)?.name }}</VChip>
      <VChip v-if="filters.status"        size="small" closable color="secondary" variant="tonal" @click:close="() => { form.status = ''; applyFilters() }">Status: {{ filters.status }}</VChip>
    </div>

    <VRow class="mb-4">
      <VCol v-for="card in kpiCards" :key="card.title" cols="12" sm="6" md="4" lg="2">
        <VCard height="100%">
          <VCardText class="pa-4">
            <div class="d-flex align-center justify-space-between mb-2">
              <VIcon :color="card.color" size="32">{{ card.icon }}</VIcon>
              <VChip :color="card.color" size="x-small" label variant="tonal">{{ card.color }}</VChip>
            </div>
            <div class="text-h5 font-weight-bold">{{ card.value }}</div>
            <div class="text-subtitle-2 font-weight-semibold mt-1">{{ card.title }}</div>
            <div class="text-caption text-medium-emphasis">{{ card.sub }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow class="mb-4">
      <VCol cols="12" md="8">
        <VCard height="100%">
          <VCardItem><VCardTitle>Violation Trend</VCardTitle><VCardSubtitle>Monthly violations raised vs resolved</VCardSubtitle></VCardItem>
          <VCardText>
            <VueApexCharts v-if="violationTrend.length" type="line" :height="280" :options="violationTrendOptions" :series="violationTrendSeries" />
            <VEmptyState v-else icon="tabler-chart-line" title="No violation trend" text="No violations in this period." size="80" />
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="4">
        <VCard height="100%">
          <VCardItem><VCardTitle>Policy Portfolio</VCardTitle><VCardSubtitle>Policies by status</VCardSubtitle></VCardItem>
          <VCardText>
            <VueApexCharts v-if="byStatus.length" type="donut" :height="280" :options="byPolicyStatusOptions" :series="byPolicyStatusSeries" />
            <VEmptyState v-else icon="tabler-file-text" title="No policy data" size="60" />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow class="mb-4">
      <VCol cols="12" md="8">
        <VCard height="100%">
          <VCardItem><VCardTitle>Attestation Trend</VCardTitle><VCardSubtitle>Monthly attestations — total, completed, and overdue</VCardSubtitle></VCardItem>
          <VCardText>
            <VueApexCharts v-if="attestationTrend.length" type="area" :height="260" :options="attestationTrendOptions" :series="attestationTrendSeries" />
            <VEmptyState v-else icon="tabler-lock-pen" title="No attestation data" size="70" />
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="4">
        <VCard height="100%">
          <VCardItem><VCardTitle>Violation Severity</VCardTitle><VCardSubtitle>Breakdown by severity level</VCardSubtitle></VCardItem>
          <VCardText>
            <VueApexCharts v-if="violationBySeverity.length" type="donut" :height="260" :options="severityOptions" :series="severitySeries" />
            <VEmptyState v-else icon="tabler-alert-triangle" title="No severity data" size="60" />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow class="mb-4">
      <VCol cols="12" md="4">
        <VCard height="100%">
          <VCardItem><VCardTitle>Attestation Status</VCardTitle><VCardSubtitle>Current status distribution</VCardSubtitle></VCardItem>
          <VCardText>
            <VueApexCharts v-if="attestationByStatus.length" type="pie" :height="260" :options="attestStatusOptions" :series="attestStatusSeries" />
            <VEmptyState v-else icon="tabler-checks" title="No attestation data" size="60" />
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="8">
        <VCard height="100%">
          <VCardItem><VCardTitle>Most Violated Policies</VCardTitle><VCardSubtitle>Top 10 policies by violation count</VCardSubtitle></VCardItem>
          <VTable density="compact">
            <thead><tr><th>#</th><th>Policy</th><th class="text-right">Violations</th><th class="text-right">Open</th></tr></thead>
            <tbody>
              <tr v-if="!topViolatedPolicies.length"><td colspan="4" class="text-center text-medium-emphasis pa-4">No violation data for this period.</td></tr>
              <tr v-for="(p, i) in topViolatedPolicies" :key="p.policy_name">
                <td><VChip size="x-small" :color="i < 3 ? 'error' : 'default'" label>{{ i + 1 }}</VChip></td>
                <td class="font-weight-medium">{{ p.policy_name }}</td>
                <td class="text-right">{{ fmtN(p.violations) }}</td>
                <td class="text-right"><VChip v-if="p.open_count > 0" size="x-small" color="error" label>{{ p.open_count }}</VChip><span v-else class="text-medium-emphasis">—</span></td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>

    <VRow class="mb-4" v-if="complianceByDept.length">
      <VCol cols="12">
        <VCard>
          <VCardItem><VCardTitle>Violations by Department</VCardTitle><VCardSubtitle>Total violations vs resolved per department</VCardSubtitle></VCardItem>
          <VTable density="compact">
            <thead><tr><th>Department</th><th class="text-right">Violations</th><th class="text-right">Resolved</th><th class="text-right">Resolution Rate</th></tr></thead>
            <tbody>
              <tr v-for="d in complianceByDept" :key="d.department">
                <td class="font-weight-medium">{{ d.department }}</td>
                <td class="text-right">{{ fmtN(d.violations) }}</td>
                <td class="text-right text-success">{{ fmtN(d.resolved) }}</td>
                <td class="text-right"><VChip size="x-small" :color="d.violations > 0 && d.resolved / d.violations >= 0.8 ? 'success' : 'warning'" label>{{ d.violations > 0 ? Math.round(d.resolved / d.violations * 100) : 0 }}%</VChip></td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>

    <VRow>
      <VCol cols="12">
        <VCard>
          <VCardItem>
            <VCardTitle class="d-flex align-center gap-2">
              <VIcon color="error" size="20">tabler-shield-exclamation</VIcon>
              Open Violations
            </VCardTitle>
            <VCardSubtitle>Active violations requiring attention</VCardSubtitle>
          </VCardItem>
          <VTable density="compact">
            <thead><tr><th>Policy</th><th>Employee</th><th>Severity</th><th>Outcome</th><th>Status</th><th>Date</th></tr></thead>
            <tbody>
              <tr v-if="!openViolations.length"><td colspan="6" class="text-center text-medium-emphasis pa-4">No open violations — great compliance!</td></tr>
              <tr v-for="v in openViolations" :key="`${v.employee}-${v.created_at}`">
                <td class="font-weight-medium">{{ v.policy_name }}</td>
                <td class="text-medium-emphasis">{{ v.employee }}</td>
                <td><VChip size="x-small" :color="v.severity === 'critical' ? 'error' : v.severity === 'high' ? 'error' : v.severity === 'medium' ? 'warning' : 'info'" label>{{ v.severity }}</VChip></td>
                <td><VChip size="x-small" label>{{ v.outcome }}</VChip></td>
                <td><VChip size="x-small" :color="v.status === 'open' ? 'error' : 'warning'" label>{{ v.status }}</VChip></td>
                <td class="text-caption text-medium-emphasis">{{ new Date(v.created_at).toLocaleDateString('en-ZA') }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>

<style lang="scss" scoped>
@use "@core/scss/template/libs/apex-chart.scss";
</style>
