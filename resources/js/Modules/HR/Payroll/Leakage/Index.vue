<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3'
import { ref, computed, watch } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Finding {
  id: number; uuid: string; run_id: number | null
  anomaly_type: string; description: string
  severity: string; status: string
  detected_at: string
  employee_id: number | null; employee_name: string | null
  leakage_amount: number
  metadata: Record<string, any> | null
  payroll_run: { uuid: string; ref_number: string; name: string } | null
}
interface Run { id: number; uuid: string; ref_number: string; name: string }
interface Page<T> { data: T[]; current_page: number; last_page: number; total: number }
interface TopSource { type: string; estimated_leakage: number }
interface DeptRow { department: string; total_net: number; headcount: number }
interface TypeCount { count: number }

const props = defineProps<{
  findings: Page<Finding>
  filters: Record<string, string>
  estimate: {
    monthly_estimate: number
    annual_estimate: number
    top_sources: TopSource[]
    department_breakdown: DeptRow[]
  }
  byType: Record<string, TypeCount>
  runs: Run[]
  leakageTypes: string[]
}>()

const statusFilter   = ref(props.filters.status   ?? '')
const severityFilter = ref(props.filters.severity ?? '')
const runIdFilter    = ref(props.filters.run_id   ?? '')
const typeFilter     = ref(props.filters.type     ?? '')

watch([statusFilter, severityFilter, runIdFilter, typeFilter], () => {
  router.get(route('payroll.leakage.index'), {
    status:   statusFilter.value,
    severity: severityFilter.value,
    run_id:   runIdFilter.value,
    type:     typeFilter.value,
  }, { preserveState: true, replace: true })
})

const runDialog   = ref(false)
const selectedRun = ref<Run | null>(null)
const runForm     = useForm({})

function doRun() {
  if (!selectedRun.value) return
  runForm.post(route('payroll.leakage.run', selectedRun.value.uuid), {
    onSuccess: () => { runDialog.value = false },
  })
}

const selected      = ref<Finding | null>(null)
const resolveDialog = ref(false)
const resolveForm   = useForm({ resolution_notes: '' })

function openResolve(finding: Finding) { selected.value = finding; resolveForm.reset(); resolveDialog.value = true }
function doResolve() {
  if (!selected.value) return
  resolveForm.post(route('payroll.anomalies.resolve', selected.value.uuid), {
    onSuccess: () => { resolveDialog.value = false },
  })
}

const fmt = (n: number) => new Intl.NumberFormat('en-ZA', { style: 'currency', currency: 'ZAR', maximumFractionDigits: 0 }).format(n)

const sevColor = (s: string) => ({ low: 'info', medium: 'warning', high: 'error', critical: 'purple' }[s] ?? 'grey')
const statusColor = (s: string) => ({ open: 'error', investigating: 'warning', resolved: 'success', dismissed: 'grey' }[s] ?? 'grey')

const leakageIcon = (type: string): string => ({
  duplicate_allowance:         'tabler-copy',
  allowance_leakage:           'tabler-cash',
  benefit_after_termination:   'tabler-user-off',
  suspicious_low_deductions:   'tabler-shield-exclamation',
  recurring_payroll_drift:     'tabler-chart-line',
}[type] ?? 'tabler-droplet-off')

const fmtType = (type: string) => type.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())

function formatDate(date: string): string {
  if (!date) return '—'
  const d = new Date(date)
  return d.toLocaleDateString('en-ZA', { year: 'numeric', month: 'short', day: '2-digit' })
}

const statusItems = computed(() => [
  { title: t('payroll_leakage.filters.all'), value: '' },
  { title: t('payroll_leakage.filters.statuses.open'), value: 'open' },
  { title: t('payroll_leakage.filters.statuses.investigating'), value: 'investigating' },
  { title: t('payroll_leakage.filters.statuses.resolved'), value: 'resolved' },
  { title: t('payroll_leakage.filters.statuses.dismissed'), value: 'dismissed' },
])

const severityItems = computed(() => [
  { title: t('payroll_leakage.filters.severities.all'), value: '' },
  { title: t('payroll_leakage.filters.severities.critical'), value: 'critical' },
  { title: t('payroll_leakage.filters.severities.high'), value: 'high' },
  { title: t('payroll_leakage.filters.severities.medium'), value: 'medium' },
  { title: t('payroll_leakage.filters.severities.low'), value: 'low' },
])

const typeItems = computed(() => [
  { title: t('payroll_leakage.filters.all_types'), value: '' },
  ...props.leakageTypes.map(lt => ({ title: fmtType(lt), value: lt })),
])

const runItems = computed(() => [
  { id: '', ref_number: t('payroll_leakage.filters.all_runs'), name: '' },
  ...props.runs,
])
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('payroll_leakage.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">
          {{ t('payroll_leakage.description', { total: findings.total }) }}
        </p>
      </div>
      <VBtn color="warning" prepend-icon="tabler-zoom-scan" @click="runDialog = true">
        {{ t('payroll_leakage.run_detection') }}
      </VBtn>
    </div>

    <VRow class="mb-4">
      <VCol cols="12" sm="6" md="3">
        <VCard elevation="0" border>
          <VCardText class="pa-4">
            <div class="d-flex align-center justify-space-between mb-2">
              <span class="text-caption text-medium-emphasis font-weight-medium text-uppercase">{{ t('payroll_leakage.cards.monthly_leakage') }}</span>
              <VIcon color="warning" size="20">tabler-droplet-off</VIcon>
            </div>
            <div class="text-h5 font-weight-bold text-warning">{{ fmt(estimate.monthly_estimate) }}</div>
            <div class="text-caption text-medium-emphasis mt-1">{{ t('payroll_leakage.cards.monthly_subtitle') }}</div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" sm="6" md="3">
        <VCard elevation="0" border>
          <VCardText class="pa-4">
            <div class="d-flex align-center justify-space-between mb-2">
              <span class="text-caption text-medium-emphasis font-weight-medium text-uppercase">{{ t('payroll_leakage.cards.annual_leakage') }}</span>
              <VIcon color="error" size="20">tabler-trending-up</VIcon>
            </div>
            <div class="text-h5 font-weight-bold text-error">{{ fmt(estimate.annual_estimate) }}</div>
            <div class="text-caption text-medium-emphasis mt-1">{{ t('payroll_leakage.cards.annual_subtitle') }}</div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" sm="6" md="3">
        <VCard elevation="0" border>
          <VCardText class="pa-4">
            <div class="d-flex align-center justify-space-between mb-2">
              <span class="text-caption text-medium-emphasis font-weight-medium text-uppercase">{{ t('payroll_leakage.cards.top_sources') }}</span>
              <VIcon color="info" size="20">tabler-list</VIcon>
            </div>
            <div v-if="estimate.top_sources.length">
              <div v-for="source in estimate.top_sources.slice(0, 3)" :key="source.type"
                class="d-flex justify-space-between align-center mb-1">
                <span class="text-caption">{{ fmtType(source.type) }}</span>
                <span class="text-caption font-weight-bold">{{ fmt(source.estimated_leakage) }}</span>
              </div>
            </div>
            <div v-else class="text-caption text-medium-emphasis">{{ t('payroll_leakage.cards.no_sources') }}</div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" sm="6" md="3">
        <VCard elevation="0" border>
          <VCardText class="pa-4">
            <div class="d-flex align-center justify-space-between mb-2">
              <span class="text-caption text-medium-emphasis font-weight-medium text-uppercase">{{ t('payroll_leakage.cards.high_risk_dept') }}</span>
              <VIcon color="purple" size="20">tabler-building-exclamation</VIcon>
            </div>
            <div v-if="estimate.department_breakdown.length">
              <div v-for="dept in estimate.department_breakdown.slice(0, 3)" :key="dept.department"
                class="d-flex justify-space-between align-center mb-1">
                <span class="text-caption text-truncate" style="max-width:130px;">{{ dept.department }}</span>
                <span class="text-caption font-weight-bold">{{ t('payroll_leakage.cards.emp', { count: dept.headcount }) }}</span>
              </div>
            </div>
            <div v-else class="text-caption text-medium-emphasis">{{ t('payroll_leakage.cards.no_dept_data') }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard elevation="0" border class="mb-4">
      <VCardText class="pa-3">
        <VRow dense>
          <VCol cols="12" sm="3">
            <VSelect v-model="statusFilter" :label="t('payroll_leakage.filters.status')"
              :items="statusItems" item-title="title" item-value="value" density="compact" variant="outlined" hide-details />
          </VCol>
          <VCol cols="12" sm="3">
            <VSelect v-model="severityFilter" :label="t('payroll_leakage.filters.severity')"
              :items="severityItems" item-title="title" item-value="value" density="compact" variant="outlined" hide-details />
          </VCol>
          <VCol cols="12" sm="3">
            <VSelect v-model="typeFilter" :label="t('payroll_leakage.filters.type')"
              :items="typeItems" item-title="title" item-value="value" density="compact" variant="outlined" hide-details />
          </VCol>
          <VCol cols="12" sm="3">
            <VSelect v-model="runIdFilter" :label="t('payroll_leakage.filters.run')"
              :items="runItems" item-title="ref_number" item-value="id" density="compact" variant="outlined" hide-details />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VDataTable
        :items="findings.data"
        :headers="[
          { title: t('payroll_leakage.table.icon'),          key: 'icon',          sortable: false, width: '40px' },
          { title: t('payroll_leakage.table.type'),      key: 'anomaly_type',  sortable: false },
          { title: t('payroll_leakage.table.run'),       key: 'payroll_run',   sortable: false },
          { title: t('payroll_leakage.table.employee'),  key: 'employee_name', sortable: false },
          { title: t('payroll_leakage.table.est_loss'),  key: 'leakage_amount', sortable: false },
          { title: t('payroll_leakage.table.severity'),  key: 'severity',      sortable: false },
          { title: t('payroll_leakage.table.status'),    key: 'status',        sortable: false },
          { title: '',          key: 'actions',       sortable: false },
        ]"
        density="comfortable"
        hide-default-footer
      >
        <template #item.icon="{ item }">
          <VIcon size="18" :color="sevColor(item.severity)">{{ leakageIcon(item.anomaly_type) }}</VIcon>
        </template>

        <template #item.anomaly_type="{ item }">
          <div>
            <div class="text-body-2 font-weight-medium">{{ fmtType(item.anomaly_type) }}</div>
            <div class="text-caption text-medium-emphasis" style="max-width:360px; white-space:normal;">
              {{ item.description?.substring(0, 110) }}{{ (item.description?.length ?? 0) > 110 ? '…' : '' }}
            </div>
          </div>
        </template>

        <template #item.payroll_run="{ item }">
          <span v-if="item.payroll_run" class="text-caption font-weight-medium">{{ item.payroll_run.ref_number }}</span>
          <span v-else class="text-caption text-medium-emphasis">—</span>
        </template>

        <template #item.employee_name="{ item }">
          <span class="text-body-2">{{ item.employee_name ?? t('payroll_leakage.run_level') }}</span>
        </template>

        <template #item.leakage_amount="{ item }">
          <span v-if="item.leakage_amount > 0" class="text-body-2 font-weight-medium text-error">
            {{ fmt(item.leakage_amount) }}
          </span>
          <span v-else class="text-caption text-medium-emphasis">—</span>
        </template>

        <template #item.severity="{ item }">
          <VChip size="x-small" :color="sevColor(item.severity)" variant="tonal">{{ item.severity }}</VChip>
        </template>

        <template #item.status="{ item }">
          <VChip size="x-small" :color="statusColor(item.status)" variant="tonal">{{ item.status }}</VChip>
        </template>

        <template #item.actions="{ item }">
          <VBtn v-if="item.status === 'open'" size="x-small" color="success" variant="tonal"
            prepend-icon="tabler-check" @click="openResolve(item)">{{ t('payroll_leakage.actions.resolve') }}</VBtn>
        </template>
      </VDataTable>

      <VDivider v-if="findings.last_page > 1" />
      <div v-if="findings.last_page > 1" class="d-flex justify-center pa-3">
        <VPagination :model-value="findings.current_page" :length="findings.last_page" density="compact"
          @update:model-value="p => router.get(route('payroll.leakage.index'), { ...filters, page: p }, { preserveState: true })" />
      </div>
    </VCard>

    <VDialog v-model="runDialog" max-width="480">
      <VCard>
        <VCardTitle class="pa-4 d-flex align-center ga-2 text-body-1 font-weight-semibold">
          <VIcon color="warning">tabler-zoom-scan</VIcon>{{ t('payroll_leakage.dialog.run_title') }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <p class="text-body-2 text-medium-emphasis mb-4">{{ t('payroll_leakage.dialog.run_description') }}</p>
          <VSelect v-model="selectedRun" :label="t('payroll_leakage.dialog.select_run')" :items="runs"
            item-title="ref_number" item-value="id" return-object variant="outlined" density="compact">
            <template #item="{ item, props: itemProps }">
              <VListItem v-bind="itemProps"><template #subtitle>{{ item.raw.name }}</template></VListItem>
            </template>
          </VSelect>
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="runDialog = false">{{ t('payroll_leakage.dialog.cancel') }}</VBtn>
          <VBtn color="warning" :loading="runForm.processing" :disabled="!selectedRun" @click="doRun">
            {{ t('payroll_leakage.dialog.detect_leakage') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="resolveDialog" max-width="500">
      <VCard v-if="selected">
        <VCardTitle class="pa-4 d-flex align-center ga-2 text-body-1 font-weight-semibold">
          <VIcon color="success">tabler-circle-check</VIcon>{{ t('payroll_leakage.dialog.resolve_title') }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAlert variant="tonal" :color="sevColor(selected.severity)" density="compact" class="mb-4">
            <div class="font-weight-bold mb-1">{{ fmtType(selected.anomaly_type) }}</div>
            <div class="text-caption">{{ selected.description }}</div>
            <div v-if="selected.leakage_amount > 0" class="mt-1 text-caption font-weight-bold">
              {{ t('payroll_leakage.dialog.estimated_loss', { amount: fmt(selected.leakage_amount) }) }}
            </div>
          </VAlert>
          <VTextarea v-model="resolveForm.resolution_notes" :label="t('payroll_leakage.dialog.resolution_notes')"
            variant="outlined" density="compact" rows="3" :error-messages="resolveForm.errors.resolution_notes" />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="resolveDialog = false">{{ t('payroll_leakage.dialog.cancel') }}</VBtn>
          <VBtn color="success" :loading="resolveForm.processing" @click="doResolve">{{ t('payroll_leakage.dialog.mark_resolved') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
