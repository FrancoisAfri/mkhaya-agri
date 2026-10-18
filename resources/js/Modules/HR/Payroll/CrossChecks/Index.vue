<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import { route } from 'ziggy-js'

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
  stats: { total_open: number; total_high: number; total_critical: number; total_resolved: number }
  runs: Run[]
  checkTypes: string[]
}>()

// ── Filters ───────────────────────────────────────────────────────────────────
const status   = ref(props.filters.status   ?? '')
const severity = ref(props.filters.severity ?? '')
const runId    = ref(props.filters.run_id   ?? '')
const type     = ref(props.filters.type     ?? '')

watch([status, severity, runId, type], () => {
  router.get(route('payroll.cross-checks.index'), {
    status:   status.value,
    severity: severity.value,
    run_id:   runId.value,
    type:     type.value,
  }, { preserveState: true, replace: true })
})

// ── Run cross-check ───────────────────────────────────────────────────────────
const runDialog   = ref(false)
const selectedRun = ref<Run | null>(null)
const runForm     = useForm({})

function openRunDialog(r: Run) { selectedRun.value = r; runDialog.value = true }
function doRun() {
  if (!selectedRun.value) return
  runForm.post(route('payroll.cross-checks.run', selectedRun.value.uuid), {
    onSuccess: () => { runDialog.value = false },
  })
}

// ── Resolve/dismiss ───────────────────────────────────────────────────────────
const selected      = ref<Anomaly | null>(null)
const resolveDialog = ref(false)
const resolveForm   = useForm({ resolution_notes: '' })

function openResolve(a: Anomaly) { selected.value = a; resolveForm.reset(); resolveDialog.value = true }
function doResolve() {
  if (!selected.value) return
  resolveForm.post(route('payroll.anomalies.resolve', selected.value.uuid), {
    onSuccess: () => { resolveDialog.value = false },
  })
}

// ── Helpers ───────────────────────────────────────────────────────────────────
const sevColor    = (s: string) => ({ low: 'info', medium: 'warning', high: 'error', critical: 'purple' }[s] ?? 'grey')
const statusColor = (s: string) => ({ open: 'error', investigating: 'warning', resolved: 'success', dismissed: 'grey' }[s] ?? 'grey')

const checkIcon = (t: string): string => ({
  zero_attendance:                'tabler-user-clock',
  unpaid_leave_full_pay:          'tabler-calendar-exclamation',
  unpaid_leave_in_period:         'tabler-calendar-minus',
  excessive_absence_no_leave:     'tabler-user-off',
  overtime_claim_mismatch:        'tabler-clock-exclamation',
  contract_ends_in_period:        'tabler-file-exclamation',
  non_active_employee_in_payroll: 'tabler-user-cancel',
  allowance_with_zero_basic:      'tabler-cash-off',
  acting_allowance_ineligible:    'tabler-user-star',
  shift_allowance_ineligible:     'tabler-switch-horizontal',
}[t] ?? 'tabler-alert-circle')

const fmtType = (t: string) => t.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">Payroll Cross-Checks</h1>
        <p class="text-body-2 text-medium-emphasis">
          {{ anomalies.total }} finding(s) — attendance, leave, overtime, contracts and allowance validation
        </p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-player-play" @click="runDialog = true">
        Run Cross-Checks
      </VBtn>
    </div>

    <!-- Stat cards -->
    <VRow class="mb-4">
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="error" size="22" class="mb-1">tabler-alert-circle</VIcon>
            <div class="text-h5 font-weight-bold text-error">{{ stats.total_open }}</div>
            <div class="text-caption text-medium-emphasis">Open</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="purple" size="22" class="mb-1">tabler-skull</VIcon>
            <div class="text-h5 font-weight-bold text-purple">{{ stats.total_critical }}</div>
            <div class="text-caption text-medium-emphasis">Critical</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="warning" size="22" class="mb-1">tabler-alert-triangle</VIcon>
            <div class="text-h5 font-weight-bold text-warning">{{ stats.total_high }}</div>
            <div class="text-caption text-medium-emphasis">High</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="success" size="22" class="mb-1">tabler-circle-check</VIcon>
            <div class="text-h5 font-weight-bold text-success">{{ stats.total_resolved }}</div>
            <div class="text-caption text-medium-emphasis">Resolved</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Filters -->
    <VCard elevation="0" border class="mb-4">
      <VCardText class="pa-3">
        <VRow dense>
          <VCol cols="12" sm="3">
            <VSelect v-model="status" label="Status"
              :items="[{ title: 'All', value: '' }, { title: 'Open', value: 'open' }, { title: 'Investigating', value: 'investigating' }, { title: 'Resolved', value: 'resolved' }, { title: 'Dismissed', value: 'dismissed' }]"
              item-title="title" item-value="value" density="compact" variant="outlined" hide-details />
          </VCol>
          <VCol cols="12" sm="3">
            <VSelect v-model="severity" label="Severity"
              :items="[{ title: 'All', value: '' }, { title: 'Critical', value: 'critical' }, { title: 'High', value: 'high' }, { title: 'Medium', value: 'medium' }, { title: 'Low', value: 'low' }]"
              item-title="title" item-value="value" density="compact" variant="outlined" hide-details />
          </VCol>
          <VCol cols="12" sm="3">
            <VSelect v-model="type" label="Check Type"
              :items="[{ title: 'All types', value: '' }, ...checkTypes.map(t => ({ title: fmtType(t), value: t }))]"
              item-title="title" item-value="value" density="compact" variant="outlined" hide-details />
          </VCol>
          <VCol cols="12" sm="3">
            <VSelect v-model="runId" label="Payroll Run"
              :items="[{ id: '', ref_number: 'All runs', name: '' }, ...runs]"
              item-title="ref_number" item-value="id" density="compact" variant="outlined" hide-details />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Table -->
    <VCard elevation="0" border>
      <VDataTable
        :items="anomalies.data"
        :headers="[
          { title: '',         key: 'icon',         sortable: false, width: '40px' },
          { title: 'Check',    key: 'anomaly_type', sortable: false },
          { title: 'Run',      key: 'payroll_run',  sortable: false },
          { title: 'Employee', key: 'employee_name', sortable: false },
          { title: 'Severity', key: 'severity',     sortable: false },
          { title: 'Status',   key: 'status',       sortable: false },
          { title: 'Detected', key: 'detected_at',  sortable: false },
          { title: '',         key: 'actions',      sortable: false },
        ]"
        density="comfortable"
        hide-default-footer
      >
        <template #item.icon="{ item }">
          <VIcon size="18" :color="sevColor(item.severity)">{{ checkIcon(item.anomaly_type) }}</VIcon>
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
          <span class="text-body-2">{{ item.employee_name ?? '(run-level)' }}</span>
        </template>

        <template #item.severity="{ item }">
          <VChip size="x-small" :color="sevColor(item.severity)" variant="tonal">{{ item.severity }}</VChip>
        </template>

        <template #item.status="{ item }">
          <VChip size="x-small" :color="statusColor(item.status)" variant="tonal">{{ item.status }}</VChip>
        </template>

        <template #item.actions="{ item }">
          <VBtn v-if="item.status === 'open'" size="x-small" color="success" variant="tonal"
            prepend-icon="tabler-check" @click="openResolve(item)">Resolve</VBtn>
        </template>
      </VDataTable>

      <!-- Pagination -->
      <VDivider v-if="anomalies.last_page > 1" />
      <div v-if="anomalies.last_page > 1" class="d-flex justify-center pa-3">
        <VPagination
          :model-value="anomalies.current_page"
          :length="anomalies.last_page"
          density="compact"
          @update:model-value="p => router.get(route('payroll.cross-checks.index'), { ...filters, page: p }, { preserveState: true })"
        />
      </div>
    </VCard>

    <!-- Run cross-checks dialog -->
    <VDialog v-model="runDialog" max-width="480">
      <VCard>
        <VCardTitle class="pa-4 d-flex align-center ga-2 text-body-1 font-weight-semibold">
          <VIcon color="primary">tabler-player-play</VIcon>Run Cross-Checks
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <p class="text-body-2 text-medium-emphasis mb-4">
            Select a payroll run to cross-check against attendance, leave, overtime, contracts and allowances.
            New findings will be saved to the anomaly list.
          </p>
          <VSelect
            v-model="selectedRun"
            label="Select Payroll Run"
            :items="runs"
            item-title="ref_number"
            item-value="id"
            return-object
            variant="outlined"
            density="compact"
          >
            <template #item="{ item, props: itemProps }">
              <VListItem v-bind="itemProps">
                <template #subtitle>{{ item.raw.name }}</template>
              </VListItem>
            </template>
          </VSelect>
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="runDialog = false">Cancel</VBtn>
          <VBtn color="primary" :loading="runForm.processing" :disabled="!selectedRun" @click="doRun">
            Run Checks
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Resolve dialog -->
    <VDialog v-model="resolveDialog" max-width="500">
      <VCard v-if="selected">
        <VCardTitle class="pa-4 d-flex align-center ga-2 text-body-1 font-weight-semibold">
          <VIcon color="success">tabler-circle-check</VIcon>Resolve Finding
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAlert variant="tonal" :color="sevColor(selected.severity)" density="compact" class="mb-4">
            <div class="font-weight-bold mb-1">{{ fmtType(selected.anomaly_type) }}</div>
            <div class="text-caption">{{ selected.description }}</div>
          </VAlert>
          <VTextarea v-model="resolveForm.resolution_notes" label="Resolution Notes *"
            variant="outlined" density="compact" rows="3"
            :error-messages="resolveForm.errors.resolution_notes" />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="resolveDialog = false">Cancel</VBtn>
          <VBtn color="success" :loading="resolveForm.processing" @click="doResolve">Mark Resolved</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
