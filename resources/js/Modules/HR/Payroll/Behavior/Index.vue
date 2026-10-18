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
interface ChangeRow {
  changed_by: number
  changer_name: string
  total_changes: number
  manual_changes: number
  max_risk_score: number
}

const props = defineProps<{
  anomalies: Page<Anomaly>
  filters: Record<string, string>
  stats: { total_open: number; total_high: number; total_spikes: number; total_resolved: number }
  changesByUser: ChangeRow[]
  runs: Run[]
  behaviorTypes: string[]
}>()

const statusFilter   = ref(props.filters.status   ?? '')
const severityFilter = ref(props.filters.severity ?? '')
const runIdFilter    = ref(props.filters.run_id   ?? '')
const typeFilter     = ref(props.filters.type     ?? '')

watch([statusFilter, severityFilter, runIdFilter, typeFilter], () => {
  router.get(route('payroll.behavior.index'), {
    status: statusFilter.value, severity: severityFilter.value,
    run_id: runIdFilter.value,  type: typeFilter.value,
  }, { preserveState: true, replace: true })
})

const runDialog   = ref(false)
const selectedRun = ref<Run | null>(null)
const runForm     = useForm({})

function doRun() {
  if (!selectedRun.value) return
  runForm.post(route('payroll.behavior.run', selectedRun.value.uuid), {
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
const riskColor   = (score: number) => score >= 70 ? 'error' : score >= 40 ? 'warning' : score >= 20 ? 'info' : 'success'

const behaviorIcon = (type: string): string => ({
  abnormal_approval_behavior:  'tabler-rosette-discount-check',
  repeated_payroll_overrides:  'tabler-pencil-minus',
  after_hours_payroll_change:  'tabler-clock-exclamation',
  department_payroll_spike:    'tabler-building-exclamation',
}[type] ?? 'tabler-alert-circle')

const fmtType = (type: string) => type.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())

function formatDate(date: string): string {
  if (!date) return '—'
  const d = new Date(date)
  return d.toLocaleDateString('en-ZA', { year: 'numeric', month: 'short', day: '2-digit' })
}

const statusItems = computed(() => [
  { title: t('payroll_behavior.filters.all'), value: '' },
  { title: t('payroll_behavior.filters.statuses.open'), value: 'open' },
  { title: t('payroll_behavior.filters.statuses.investigating'), value: 'investigating' },
  { title: t('payroll_behavior.filters.statuses.resolved'), value: 'resolved' },
  { title: t('payroll_behavior.filters.statuses.dismissed'), value: 'dismissed' },
])

const severityItems = computed(() => [
  { title: t('payroll_behavior.filters.severities.all'), value: '' },
  { title: t('payroll_behavior.filters.severities.high'), value: 'high' },
  { title: t('payroll_behavior.filters.severities.medium'), value: 'medium' },
  { title: t('payroll_behavior.filters.severities.low'), value: 'low' },
])

const typeItems = computed(() => [
  { title: t('payroll_behavior.filters.all_patterns'), value: '' },
  ...props.behaviorTypes.map(bt => ({ title: fmtType(bt), value: bt })),
])

const runItems = computed(() => [
  { id: '', ref_number: t('payroll_behavior.filters.all_runs'), name: '' },
  ...props.runs,
])
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('payroll_behavior.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">
          {{ t('payroll_behavior.description', { total: anomalies.total }) }}
        </p>
      </div>
      <VBtn color="info" prepend-icon="tabler-timeline" @click="runDialog = true">
        {{ t('payroll_behavior.run_analysis') }}
      </VBtn>
    </div>

    <VRow class="mb-4">
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="error" size="22" class="mb-1">tabler-alert-circle</VIcon>
            <div class="text-h5 font-weight-bold text-error">{{ stats.total_open }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_behavior.stats.open_patterns') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="warning" size="22" class="mb-1">tabler-alert-triangle</VIcon>
            <div class="text-h5 font-weight-bold text-warning">{{ stats.total_high }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_behavior.stats.high_severity') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="info" size="22" class="mb-1">tabler-building-exclamation</VIcon>
            <div class="text-h5 font-weight-bold text-info">{{ stats.total_spikes }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_behavior.stats.dept_spikes') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="success" size="22" class="mb-1">tabler-circle-check</VIcon>
            <div class="text-h5 font-weight-bold text-success">{{ stats.total_resolved }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_behavior.stats.resolved') }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard v-if="changesByUser.length" elevation="0" border class="mb-4">
      <VCardTitle class="pa-4 text-body-1 font-weight-semibold d-flex align-center ga-2">
        <VIcon color="info" size="20">tabler-user-edit</VIcon>
        {{ t('payroll_behavior.change_activity') }}
      </VCardTitle>
      <VDivider />
      <VDataTable
        :items="changesByUser"
        :headers="[
          { title: t('payroll_behavior.change_table.user'),           key: 'changer_name',  sortable: false },
          { title: t('payroll_behavior.change_table.total_changes'),  key: 'total_changes', sortable: false },
          { title: t('payroll_behavior.change_table.manual'),         key: 'manual_changes', sortable: false },
          { title: t('payroll_behavior.change_table.max_risk_score'), key: 'max_risk_score', sortable: false },
        ]"
        density="compact"
        hide-default-footer
      >
        <template #item.manual_changes="{ item }">
          <VChip v-if="item.manual_changes > 0" size="x-small"
            :color="item.manual_changes > 3 ? 'error' : 'warning'" variant="tonal">
            {{ t('payroll_behavior.manual_changes', { count: item.manual_changes }) }}
          </VChip>
          <span v-else class="text-caption text-medium-emphasis">—</span>
        </template>
        <template #item.max_risk_score="{ item }">
          <VChip size="x-small" :color="riskColor(item.max_risk_score)" variant="tonal">
            {{ item.max_risk_score }}
          </VChip>
        </template>
      </VDataTable>
    </VCard>

    <VCard elevation="0" border class="mb-4">
      <VCardText class="pa-3">
        <VRow dense>
          <VCol cols="12" sm="3">
            <VSelect v-model="statusFilter" :label="t('payroll_behavior.filters.status')"
              :items="statusItems" item-title="title" item-value="value" density="compact" variant="outlined" hide-details />
          </VCol>
          <VCol cols="12" sm="3">
            <VSelect v-model="severityFilter" :label="t('payroll_behavior.filters.severity')"
              :items="severityItems" item-title="title" item-value="value" density="compact" variant="outlined" hide-details />
          </VCol>
          <VCol cols="12" sm="3">
            <VSelect v-model="typeFilter" :label="t('payroll_behavior.filters.type')"
              :items="typeItems" item-title="title" item-value="value" density="compact" variant="outlined" hide-details />
          </VCol>
          <VCol cols="12" sm="3">
            <VSelect v-model="runIdFilter" :label="t('payroll_behavior.filters.run')"
              :items="runItems" item-title="ref_number" item-value="id" density="compact" variant="outlined" hide-details />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VDataTable
        :items="anomalies.data"
        :headers="[
          { title: t('payroll_behavior.table.icon'),         key: 'icon',          sortable: false, width: '40px' },
          { title: t('payroll_behavior.table.pattern'),  key: 'anomaly_type',  sortable: false },
          { title: t('payroll_behavior.table.run'),      key: 'payroll_run',   sortable: false },
          { title: t('payroll_behavior.table.subject'),  key: 'employee_name', sortable: false },
          { title: t('payroll_behavior.table.severity'), key: 'severity',      sortable: false },
          { title: t('payroll_behavior.table.status'),   key: 'status',        sortable: false },
          { title: t('payroll_behavior.table.detected'), key: 'detected_at',   sortable: false },
          { title: '',         key: 'actions',       sortable: false },
        ]"
        density="comfortable"
        hide-default-footer
      >
        <template #item.icon="{ item }">
          <VIcon size="18" :color="sevColor(item.severity)">{{ behaviorIcon(item.anomaly_type) }}</VIcon>
        </template>

        <template #item.anomaly_type="{ item }">
          <div>
            <div class="text-body-2 font-weight-medium">{{ fmtType(item.anomaly_type) }}</div>
            <div class="text-caption text-medium-emphasis" style="max-width:380px; white-space:normal;">
              {{ item.description?.substring(0, 120) }}{{ (item.description?.length ?? 0) > 120 ? '…' : '' }}
            </div>
          </div>
        </template>

        <template #item.payroll_run="{ item }">
          <span v-if="item.payroll_run" class="text-caption font-weight-medium">{{ item.payroll_run.ref_number }}</span>
          <span v-else class="text-caption text-medium-emphasis">—</span>
        </template>

        <template #item.employee_name="{ item }">
          <div class="text-body-2">
            {{ item.employee_name ?? (item.metadata?.changer_name ?? item.metadata?.approver_name ?? item.metadata?.department ?? t('payroll_behavior.run_level')) }}
          </div>
        </template>

        <template #item.severity="{ item }">
          <VChip size="x-small" :color="sevColor(item.severity)" variant="tonal">{{ item.severity }}</VChip>
        </template>

        <template #item.status="{ item }">
          <VChip size="x-small" :color="statusColor(item.status)" variant="tonal">{{ item.status }}</VChip>
        </template>

        <template #item.detected_at="{ item }">
          <span class="text-caption">{{ formatDate(item.detected_at) }}</span>
        </template>

        <template #item.actions="{ item }">
          <VBtn v-if="item.status === 'open'" size="x-small" color="success" variant="tonal"
            prepend-icon="tabler-check" @click="openResolve(item)">{{ t('payroll_behavior.actions.resolve') }}</VBtn>
        </template>
      </VDataTable>

      <VDivider v-if="anomalies.last_page > 1" />
      <div v-if="anomalies.last_page > 1" class="d-flex justify-center pa-3">
        <VPagination :model-value="anomalies.current_page" :length="anomalies.last_page" density="compact"
          @update:model-value="p => router.get(route('payroll.behavior.index'), { ...filters, page: p }, { preserveState: true })" />
      </div>
    </VCard>

    <VDialog v-model="runDialog" max-width="480">
      <VCard>
        <VCardTitle class="pa-4 d-flex align-center ga-2 text-body-1 font-weight-semibold">
          <VIcon color="info">tabler-chart-gantt</VIcon>{{ t('payroll_behavior.dialog.run_title') }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <p class="text-body-2 text-medium-emphasis mb-4">{{ t('payroll_behavior.dialog.run_description') }}</p>
          <VSelect v-model="selectedRun" :label="t('payroll_behavior.dialog.select_run')" :items="runs"
            item-title="ref_number" item-value="id" return-object variant="outlined" density="compact">
            <template #item="{ item, props: p }">
              <VListItem v-bind="p"><template #subtitle>{{ item.raw.name }}</template></VListItem>
            </template>
          </VSelect>
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="runDialog = false">{{ t('payroll_behavior.dialog.cancel') }}</VBtn>
          <VBtn color="info" :loading="runForm.processing" :disabled="!selectedRun" @click="doRun">
            {{ t('payroll_behavior.dialog.analyse') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="resolveDialog" max-width="500">
      <VCard v-if="selected">
        <VCardTitle class="pa-4 d-flex align-center ga-2 text-body-1 font-weight-semibold">
          <VIcon color="success">tabler-circle-check</VIcon>{{ t('payroll_behavior.dialog.resolve_title') }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAlert variant="tonal" :color="sevColor(selected.severity)" density="compact" class="mb-4">
            <div class="font-weight-bold mb-1">{{ fmtType(selected.anomaly_type) }}</div>
            <div class="text-caption">{{ selected.description }}</div>
          </VAlert>
          <VTextarea v-model="resolveForm.resolution_notes" :label="t('payroll_behavior.dialog.resolution_notes')"
            variant="outlined" density="compact" rows="3"
            :error-messages="resolveForm.errors.resolution_notes" />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="resolveDialog = false">{{ t('payroll_behavior.dialog.cancel') }}</VBtn>
          <VBtn color="success" :loading="resolveForm.processing" @click="doResolve">{{ t('payroll_behavior.dialog.mark_resolved') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
