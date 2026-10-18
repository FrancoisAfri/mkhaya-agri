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
  anomalies: Page<Anomaly>
  filters: Record<string, string>
  stats: { total_open: number; total_confirmed: number; total_high: number; total_resolved: number }
  runs: Run[]
  ghostTypes: string[]
}>()

const statusFilter   = ref(props.filters.status   ?? '')
const severityFilter = ref(props.filters.severity ?? '')
const runIdFilter    = ref(props.filters.run_id   ?? '')
const typeFilter     = ref(props.filters.type     ?? '')

watch([statusFilter, severityFilter, runIdFilter, typeFilter], () => {
  router.get(route('payroll.ghost.index'), {
    status: statusFilter.value, severity: severityFilter.value,
    run_id: runIdFilter.value,  type: typeFilter.value,
  }, { preserveState: true, replace: true })
})

const runDialog   = ref(false)
const selectedRun = ref<Run | null>(null)
const runForm     = useForm({})

function doRun() {
  if (!selectedRun.value) return
  runForm.post(route('payroll.ghost.run', selectedRun.value.uuid), {
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

const ghostIcon = (type: string): string => ({
  ghost_employee:                        'tabler-ghost',
  ghost_employee_no_attendance:          'tabler-user-clock',
  ghost_employee_no_system_activity:     'tabler-device-desktop-off',
  ghost_employee_no_leave_activity:      'tabler-calendar-x',
  ghost_employee_no_manager_interaction: 'tabler-users',
}[type] ?? 'tabler-ghost')

const signalLabel = (type: string): string => t(`payroll_ghost.signals.${type}`)

function formatDate(date: string): string {
  if (!date) return '—'
  const d = new Date(date)
  return d.toLocaleDateString('en-ZA', { year: 'numeric', month: 'short', day: '2-digit' })
}

const statusItems = computed(() => [
  { title: t('payroll_ghost.filters.all'), value: '' },
  { title: t('payroll_ghost.filters.statuses.open'), value: 'open' },
  { title: t('payroll_ghost.filters.statuses.investigating'), value: 'investigating' },
  { title: t('payroll_ghost.filters.statuses.resolved'), value: 'resolved' },
  { title: t('payroll_ghost.filters.statuses.dismissed'), value: 'dismissed' },
])

const severityItems = computed(() => [
  { title: t('payroll_ghost.filters.severities.all'), value: '' },
  { title: t('payroll_ghost.filters.severities.critical'), value: 'critical' },
  { title: t('payroll_ghost.filters.severities.high'), value: 'high' },
  { title: t('payroll_ghost.filters.severities.medium'), value: 'medium' },
])

const typeItems = computed(() => [
  { title: t('payroll_ghost.filters.all_signals'), value: '' },
  ...props.ghostTypes.map(gt => ({ title: signalLabel(gt), value: gt })),
])

const runItems = computed(() => [
  { id: '', ref_number: t('payroll_ghost.filters.all_runs'), name: '' },
  ...props.runs,
])
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('payroll_ghost.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">
          {{ t('payroll_ghost.description', { total: anomalies.total }) }}
        </p>
      </div>
      <VBtn color="error" prepend-icon="tabler-ghost" @click="runDialog = true">
        {{ t('payroll_ghost.run_detection') }}
      </VBtn>
    </div>

    <VRow class="mb-4">
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="purple" size="22" class="mb-1">tabler-ghost</VIcon>
            <div class="text-h5 font-weight-bold text-purple">{{ stats.total_confirmed }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_ghost.stats.confirmed_ghosts') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="error" size="22" class="mb-1">tabler-alert-circle</VIcon>
            <div class="text-h5 font-weight-bold text-error">{{ stats.total_open }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_ghost.stats.open_signals') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="warning" size="22" class="mb-1">tabler-alert-triangle</VIcon>
            <div class="text-h5 font-weight-bold text-warning">{{ stats.total_high }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_ghost.stats.high_severity') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="success" size="22" class="mb-1">tabler-circle-check</VIcon>
            <div class="text-h5 font-weight-bold text-success">{{ stats.total_resolved }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_ghost.stats.resolved') }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard elevation="0" border class="mb-4 pa-3">
      <div class="d-flex flex-wrap ga-2 align-center">
        <span class="text-caption text-medium-emphasis font-weight-medium">{{ t('payroll_ghost.detection_signals') }}</span>
        <VChip v-for="gt in ghostTypes" :key="gt" size="x-small" variant="tonal"
          :color="gt === 'ghost_employee' ? 'purple' : 'grey'">
          <VIcon start size="12">{{ ghostIcon(gt) }}</VIcon>
          {{ signalLabel(gt) }}
        </VChip>
      </div>
    </VCard>

    <VCard elevation="0" border class="mb-4">
      <VCardText class="pa-3">
        <VRow dense>
          <VCol cols="12" sm="3">
            <VSelect v-model="statusFilter" :label="t('payroll_ghost.filters.status')"
              :items="statusItems" item-title="title" item-value="value" density="compact" variant="outlined" hide-details />
          </VCol>
          <VCol cols="12" sm="3">
            <VSelect v-model="severityFilter" :label="t('payroll_ghost.filters.severity')"
              :items="severityItems" item-title="title" item-value="value" density="compact" variant="outlined" hide-details />
          </VCol>
          <VCol cols="12" sm="3">
            <VSelect v-model="typeFilter" :label="t('payroll_ghost.filters.type')"
              :items="typeItems" item-title="title" item-value="value" density="compact" variant="outlined" hide-details />
          </VCol>
          <VCol cols="12" sm="3">
            <VSelect v-model="runIdFilter" :label="t('payroll_ghost.filters.run')"
              :items="runItems" item-title="ref_number" item-value="id" density="compact" variant="outlined" hide-details />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VDataTable
        :items="anomalies.data"
        :headers="[
          { title: t('payroll_ghost.table.icon'),         key: 'icon',          sortable: false, width: '40px' },
          { title: t('payroll_ghost.table.signal'),   key: 'anomaly_type',  sortable: false },
          { title: t('payroll_ghost.table.run'),      key: 'payroll_run',   sortable: false },
          { title: t('payroll_ghost.table.employee'), key: 'employee_name', sortable: false },
          { title: t('payroll_ghost.table.severity'), key: 'severity',      sortable: false },
          { title: t('payroll_ghost.table.status'),   key: 'status',        sortable: false },
          { title: t('payroll_ghost.table.detected'), key: 'detected_at',   sortable: false },
          { title: '',         key: 'actions',       sortable: false },
        ]"
        density="comfortable"
        hide-default-footer
      >
        <template #item.icon="{ item }">
          <VIcon size="18" :color="item.anomaly_type === 'ghost_employee' ? 'purple' : sevColor(item.severity)">
            {{ ghostIcon(item.anomaly_type) }}
          </VIcon>
        </template>

        <template #item.anomaly_type="{ item }">
          <div>
            <div class="d-flex align-center ga-1">
              <span class="text-body-2 font-weight-medium">{{ signalLabel(item.anomaly_type) }}</span>
              <VChip v-if="item.anomaly_type === 'ghost_employee'" size="x-small" color="purple" variant="tonal">
                {{ t('payroll_ghost.composite') }}
              </VChip>
            </div>
            <div class="text-caption text-medium-emphasis" style="max-width:380px; white-space:normal;">
              {{ item.description?.substring(0, 110) }}{{ (item.description?.length ?? 0) > 110 ? '…' : '' }}
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
          <span class="text-caption">{{ formatDate(item.detected_at) }}</span>
        </template>

        <template #item.actions="{ item }">
          <VBtn v-if="item.status === 'open'" size="x-small" color="success" variant="tonal"
            prepend-icon="tabler-check" @click="openResolve(item)">{{ t('payroll_ghost.actions.resolve') }}</VBtn>
        </template>
      </VDataTable>

      <VDivider v-if="anomalies.last_page > 1" />
      <div v-if="anomalies.last_page > 1" class="d-flex justify-center pa-3">
        <VPagination :model-value="anomalies.current_page" :length="anomalies.last_page" density="compact"
          @update:model-value="p => router.get(route('payroll.ghost.index'), { ...filters, page: p }, { preserveState: true })" />
      </div>
    </VCard>

    <VDialog v-model="runDialog" max-width="480">
      <VCard>
        <VCardTitle class="pa-4 d-flex align-center ga-2 text-body-1 font-weight-semibold">
          <VIcon color="error">tabler-ghost</VIcon>{{ t('payroll_ghost.dialog.run_title') }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAlert type="warning" variant="tonal" density="compact" class="mb-4">
            {{ t('payroll_ghost.dialog.run_info') }}
          </VAlert>
          <VSelect v-model="selectedRun" :label="t('payroll_ghost.dialog.select_run')" :items="runs"
            item-title="ref_number" item-value="id" return-object variant="outlined" density="compact">
            <template #item="{ item, props: p }">
              <VListItem v-bind="p"><template #subtitle>{{ item.raw.name }}</template></VListItem>
            </template>
          </VSelect>
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="runDialog = false">{{ t('payroll_ghost.dialog.cancel') }}</VBtn>
          <VBtn color="error" :loading="runForm.processing" :disabled="!selectedRun" @click="doRun">
            {{ t('payroll_ghost.dialog.detect_ghosts') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="resolveDialog" max-width="500">
      <VCard v-if="selected">
        <VCardTitle class="pa-4 d-flex align-center ga-2 text-body-1 font-weight-semibold">
          <VIcon color="success">tabler-circle-check</VIcon>{{ t('payroll_ghost.dialog.resolve_title') }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAlert variant="tonal" :color="sevColor(selected.severity)" density="compact" class="mb-4">
            <div class="font-weight-bold mb-1">{{ signalLabel(selected.anomaly_type) }}</div>
            <div class="text-caption">{{ selected.description }}</div>
          </VAlert>
          <VTextarea v-model="resolveForm.resolution_notes" :label="t('payroll_ghost.dialog.resolution_notes')"
            variant="outlined" density="compact" rows="3"
            :error-messages="resolveForm.errors.resolution_notes" />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="resolveDialog = false">{{ t('payroll_ghost.dialog.cancel') }}</VBtn>
          <VBtn color="success" :loading="resolveForm.processing" @click="doResolve">{{ t('payroll_ghost.dialog.mark_resolved') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
