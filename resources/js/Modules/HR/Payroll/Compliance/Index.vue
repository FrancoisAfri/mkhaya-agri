<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3'
import { ref, watch, computed } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Anomaly {
  id: number; uuid: string; run_id: number | null
  anomaly_type: string; description: string
  severity: string; status: string
  detected_at: string
  employee_id: number | null; employee_name: string | null
  metadata: Record<string, any> | null
  payroll_run: { uuid: string; ref_number: string; name: string } | null
}
interface Run { id: number; uuid: string; ref_number: string; name: string }
interface Page<T> { data: T[]; current_page: number; last_page: number; total: number }

const props = defineProps<{
  anomalies:       Page<Anomaly>
  filters:         Record<string, string>
  stats:           { total_open: number; total_critical: number; total_high: number; total_resolved: number }
  runs:            Run[]
  complianceTypes: string[]
}>()

const statusFilter   = ref(props.filters.status   ?? '')
const severityFilter = ref(props.filters.severity ?? '')
const runIdFilter    = ref(props.filters.run_id   ?? '')
const typeFilter     = ref(props.filters.type     ?? '')

watch([statusFilter, severityFilter, runIdFilter, typeFilter], () => {
  router.get(route('payroll.compliance.index'), {
    status: statusFilter.value, severity: severityFilter.value,
    run_id: runIdFilter.value,  type: typeFilter.value,
  }, { preserveState: true, replace: true })
})

const runDialog   = ref(false)
const selectedRun = ref<Run | null>(null)
const runForm     = useForm({})

function doRun() {
  if (!selectedRun.value) return
  runForm.post(route('payroll.compliance.run', selectedRun.value.uuid), {
    onSuccess: () => { runDialog.value = false },
  })
}

const selected      = ref<Anomaly | null>(null)
const resolveDialog = ref(false)
const resolveForm   = useForm({ resolution_notes: '' })

function openResolve(anomaly: Anomaly) { selected.value = anomaly; resolveForm.reset(); resolveDialog.value = true }
function doResolve() {
  if (!selected.value) return
  resolveForm.post(route('payroll.anomalies.resolve', selected.value.uuid), {
    onSuccess: () => { resolveDialog.value = false },
  })
}

const sevColor    = (s: string) => ({ low: 'info', medium: 'warning', high: 'error', critical: 'purple' }[s] ?? 'grey')
const statusColor = (s: string) => ({ open: 'error', investigating: 'warning', resolved: 'success', dismissed: 'grey' }[s] ?? 'grey')

const complianceIcon = (type: string): string => ({
  overtime_law_violation: 'tabler-clock-exclamation',
  minimum_wage_violation: 'tabler-cash-off',
  leave_compliance_issue: 'tabler-umbrella-beach',
  contract_payroll_mismatch: 'tabler-file-exclamation',
  tax_compliance_issue: 'tabler-receipt',
  overtime_rate_violation: 'tabler-gavel',
}[type] ?? 'tabler-alert-circle')

const complianceColor = (type: string): string => ({
  overtime_law_violation: 'error',
  minimum_wage_violation: 'purple',
  leave_compliance_issue: 'warning',
  contract_payroll_mismatch: 'orange',
  tax_compliance_issue: 'deep-orange',
  overtime_rate_violation: 'red',
}[type] ?? 'grey')

const complianceLabel = (type: string): string => t(`payroll_compliance.violation_types.${type}`)

function getTag(anomalyType: string): string {
  return anomalyType.endsWith('_violation') ? t('payroll_compliance.violation_tag') : t('payroll_compliance.issue_tag')
}

function formatDate(date: string): string {
  if (!date) return '—'
  const d = new Date(date)
  return d.toLocaleDateString('en-ZA', { year: 'numeric', month: 'short', day: '2-digit' })
}

const statusItems = computed(() => [
  { title: t('payroll_compliance.filters.all'), value: '' },
  { title: t('payroll_compliance.filters.statuses.open'), value: 'open' },
  { title: t('payroll_compliance.filters.statuses.investigating'), value: 'investigating' },
  { title: t('payroll_compliance.filters.statuses.resolved'), value: 'resolved' },
  { title: t('payroll_compliance.filters.statuses.dismissed'), value: 'dismissed' },
])

const severityItems = computed(() => [
  { title: t('payroll_compliance.filters.severities.all'), value: '' },
  { title: t('payroll_compliance.filters.severities.critical'), value: 'critical' },
  { title: t('payroll_compliance.filters.severities.high'), value: 'high' },
  { title: t('payroll_compliance.filters.severities.medium'), value: 'medium' },
])

const typeItems = computed(() => [
  { title: t('payroll_compliance.filters.all_types'), value: '' },
  ...props.complianceTypes.map(ct => ({ title: complianceLabel(ct), value: ct })),
])

const runItems = computed(() => [
  { id: '', ref_number: t('payroll_compliance.filters.all_runs'), name: '' },
  ...props.runs,
])
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('payroll_compliance.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">
          {{ t('payroll_compliance.description', { total: anomalies.total }) }}
        </p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-shield-check" @click="runDialog = true">
        {{ t('payroll_compliance.run_check') }}
      </VBtn>
    </div>

    <VRow class="mb-4">
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="error" size="22" class="mb-1">tabler-alert-circle</VIcon>
            <div class="text-h5 font-weight-bold text-error">{{ stats.total_open }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_compliance.stats.open_violations') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="purple" size="22" class="mb-1">tabler-shield-exclamation</VIcon>
            <div class="text-h5 font-weight-bold text-purple">{{ stats.total_critical }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_compliance.stats.critical') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="warning" size="22" class="mb-1">tabler-alert-triangle</VIcon>
            <div class="text-h5 font-weight-bold text-warning">{{ stats.total_high }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_compliance.stats.high') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="success" size="22" class="mb-1">tabler-shield-check</VIcon>
            <div class="text-h5 font-weight-bold text-success">{{ stats.total_resolved }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_compliance.stats.resolved') }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard elevation="0" border class="mb-4 pa-3">
      <div class="d-flex flex-wrap ga-2 align-center">
        <span class="text-caption text-medium-emphasis font-weight-medium">{{ t('payroll_compliance.compliance_checks') }}</span>
        <VChip v-for="ct in complianceTypes" :key="ct" size="x-small" variant="tonal" :color="complianceColor(ct)">
          <VIcon start size="12">{{ complianceIcon(ct) }}</VIcon>
          {{ complianceLabel(ct) }}
        </VChip>
      </div>
    </VCard>

    <VCard elevation="0" border class="mb-4">
      <VCardText class="pa-3">
        <VRow dense>
          <VCol cols="12" sm="3">
            <VSelect v-model="statusFilter" :label="t('payroll_compliance.filters.status')"
              :items="statusItems" item-title="title" item-value="value" density="compact" variant="outlined" hide-details />
          </VCol>
          <VCol cols="12" sm="3">
            <VSelect v-model="severityFilter" :label="t('payroll_compliance.filters.severity')"
              :items="severityItems" item-title="title" item-value="value" density="compact" variant="outlined" hide-details />
          </VCol>
          <VCol cols="12" sm="3">
            <VSelect v-model="typeFilter" :label="t('payroll_compliance.filters.type')"
              :items="typeItems" item-title="title" item-value="value" density="compact" variant="outlined" hide-details />
          </VCol>
          <VCol cols="12" sm="3">
            <VSelect v-model="runIdFilter" :label="t('payroll_compliance.filters.run')"
              :items="runItems" item-title="ref_number" item-value="id" density="compact" variant="outlined" hide-details />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VDataTable
        :items="anomalies.data"
        :headers="[
          { title: t('payroll_compliance.table.icon'),           key: 'icon',          sortable: false, width: '40px' },
          { title: t('payroll_compliance.table.violation'),  key: 'anomaly_type',  sortable: false },
          { title: t('payroll_compliance.table.run'),        key: 'payroll_run',   sortable: false },
          { title: t('payroll_compliance.table.employee'),   key: 'employee_name', sortable: false },
          { title: t('payroll_compliance.table.severity'),   key: 'severity',      sortable: false },
          { title: t('payroll_compliance.table.status'),     key: 'status',        sortable: false },
          { title: t('payroll_compliance.table.detected'),   key: 'detected_at',   sortable: false },
          { title: '',           key: 'actions',       sortable: false },
        ]"
        density="comfortable"
        hide-default-footer
      >
        <template #item.icon="{ item }">
          <VIcon size="18" :color="complianceColor(item.anomaly_type)">
            {{ complianceIcon(item.anomaly_type) }}
          </VIcon>
        </template>

        <template #item.anomaly_type="{ item }">
          <div>
            <div class="d-flex align-center ga-1 flex-wrap">
              <span class="text-body-2 font-weight-medium">{{ complianceLabel(item.anomaly_type) }}</span>
              <VChip size="x-small" :color="complianceColor(item.anomaly_type)" variant="tonal">
                {{ getTag(item.anomaly_type) }}
              </VChip>
            </div>
            <div class="text-caption text-medium-emphasis" style="max-width:400px; white-space:normal;">
              {{ item.description?.substring(0, 120) }}{{ (item.description?.length ?? 0) > 120 ? '…' : '' }}
            </div>
          </div>
        </template>

        <template #item.payroll_run="{ item }">
          <span v-if="item.payroll_run" class="text-caption font-weight-medium">{{ item.payroll_run.ref_number }}</span>
          <span v-else class="text-caption text-medium-emphasis">—</span>
        </template>

        <template #item.employee_name="{ item }">
          <span class="text-body-2">{{ item.employee_name ?? '—' }}</span>
        </template>

        <template #item.severity="{ item }">
          <VChip size="x-small" :color="sevColor(item.severity)" variant="tonal">{{ item.severity }}</VChip>
        </template>

        <template #item.status="{ item }">
          <VChip size="x-small" :color="statusColor(item.status)" variant="tonal">{{ item.status }}</VChip>
        </template>

        <template #item.detected_at="{ item }">
          <span class="text-caption text-medium-emphasis">{{ formatDate(item.detected_at) }}</span>
        </template>

        <template #item.actions="{ item }">
          <VBtn v-if="item.status === 'open'" size="x-small" color="success" variant="tonal"
            prepend-icon="tabler-check" @click="openResolve(item)">{{ t('payroll_compliance.actions.resolve') }}</VBtn>
        </template>
      </VDataTable>

      <VDivider v-if="anomalies.last_page > 1" />
      <div v-if="anomalies.last_page > 1" class="d-flex justify-center pa-3">
        <VPagination :model-value="anomalies.current_page" :length="anomalies.last_page" density="compact"
          @update:model-value="p => router.get(route('payroll.compliance.index'), { ...filters, page: p }, { preserveState: true })" />
      </div>
    </VCard>

    <VDialog v-model="runDialog" max-width="480">
      <VCard>
        <VCardTitle class="pa-4 d-flex align-center ga-2 text-body-1 font-weight-semibold">
          <VIcon color="primary">tabler-shield-check</VIcon>{{ t('payroll_compliance.dialog.run_title') }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAlert type="info" variant="tonal" density="compact" class="mb-4">
            {{ t('payroll_compliance.dialog.run_info') }}
          </VAlert>
          <VSelect v-model="selectedRun" :label="t('payroll_compliance.dialog.select_run')" :items="runs"
            item-title="ref_number" item-value="id" return-object variant="outlined" density="compact">
            <template #item="{ item, props: p }">
              <VListItem v-bind="p"><template #subtitle>{{ item.raw.name }}</template></VListItem>
            </template>
          </VSelect>
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="runDialog = false">{{ t('payroll_compliance.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="runForm.processing" :disabled="!selectedRun" @click="doRun">
            {{ t('payroll_compliance.dialog.run_checks') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="resolveDialog" max-width="500">
      <VCard v-if="selected">
        <VCardTitle class="pa-4 d-flex align-center ga-2 text-body-1 font-weight-semibold">
          <VIcon color="success">tabler-circle-check</VIcon>{{ t('payroll_compliance.dialog.resolve_title') }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAlert variant="tonal" :color="complianceColor(selected.anomaly_type)" density="compact" class="mb-4">
            <div class="font-weight-bold mb-1">{{ complianceLabel(selected.anomaly_type) }}</div>
            <div class="text-caption">{{ selected.description }}</div>
          </VAlert>
          <VTextarea v-model="resolveForm.resolution_notes" :label="t('payroll_compliance.dialog.resolution_notes')"
            variant="outlined" density="compact" rows="3"
            :error-messages="resolveForm.errors.resolution_notes" />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="resolveDialog = false">{{ t('payroll_compliance.dialog.cancel') }}</VBtn>
          <VBtn color="success" :loading="resolveForm.processing" @click="doResolve">{{ t('payroll_compliance.dialog.mark_resolved') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
