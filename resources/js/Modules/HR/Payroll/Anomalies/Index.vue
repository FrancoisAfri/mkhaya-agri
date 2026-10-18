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
  detected_at: string; employee_id: number | null; employee_name: string | null
  metadata: Record<string, any> | null
  payroll_run: { uuid: string; ref_number: string; name: string } | null
}
interface Run { id: number; uuid: string; ref_number: string; name: string }
interface Page<T> { data: T[]; current_page: number; last_page: number; total: number }

const props = defineProps<{
  anomalies: Page<Anomaly>
  filters: Record<string, string>
  stats: { total_open: number; total_critical: number; total_high: number; total_resolved: number }
  runs: Run[]
}>()

const statusFilter   = ref(props.filters.status   ?? '')
const severityFilter = ref(props.filters.severity ?? '')
const runIdFilter    = ref(props.filters.run_id   ?? '')

watch([statusFilter, severityFilter, runIdFilter], () => {
  router.get(route('payroll.anomalies.index'), {
    status:   statusFilter.value,
    severity: severityFilter.value,
    run_id:   runIdFilter.value,
  }, { preserveState: true, replace: true })
})

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

const dismissDialog = ref(false)
const dismissForm   = useForm({ resolution_notes: '' })

function openDismiss(anomaly: Anomaly) { selected.value = anomaly; dismissForm.reset(); dismissDialog.value = true }
function doDismiss() {
  if (!selected.value) return
  dismissForm.post(route('payroll.anomalies.dismiss', selected.value.uuid), {
    onSuccess: () => { dismissDialog.value = false },
  })
}

const sevColor    = (s: string) => ({ low: 'info', medium: 'warning', high: 'error', critical: 'purple' }[s] ?? 'grey')
const statusColor = (s: string) => ({ open: 'error', investigating: 'warning', resolved: 'success', dismissed: 'grey' }[s] ?? 'grey')

const anomalyIcon = (t: string): string => ({
  duplicate_payment:            'tabler-copy',
  inactive_employee_paid:       'tabler-user-off',
  terminated_employee_paid:     'tabler-user-cancel',
  unusual_overtime:             'tabler-clock-exclamation',
  unauthorized_salary_increase: 'tabler-trending-up',
  duplicate_bank_account:       'tabler-building-bank',
  negative_net_pay:             'tabler-circle-minus',
  payroll_above_budget:         'tabler-currency-dollar-off',
  late_payroll_change:          'tabler-calendar-clock',
}[t] ?? 'tabler-alert-circle')

const fmtType = (type: string) => type.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())

function formatDate(date: string): string {
  if (!date) return '—'
  const d = new Date(date)
  return d.toLocaleDateString('en-ZA', {
    year: 'numeric',
    month: 'short',
    day: '2-digit',
  })
}

const statusItems = computed(() => [
  { title: t('payroll_anomalies.filters.all'), value: '' },
  { title: t('payroll_anomalies.filters.statuses.open'), value: 'open' },
  { title: t('payroll_anomalies.filters.statuses.investigating'), value: 'investigating' },
  { title: t('payroll_anomalies.filters.statuses.resolved'), value: 'resolved' },
  { title: t('payroll_anomalies.filters.statuses.dismissed'), value: 'dismissed' },
])

const severityItems = computed(() => [
  { title: t('payroll_anomalies.filters.severities.all'), value: '' },
  { title: t('payroll_anomalies.filters.severities.critical'), value: 'critical' },
  { title: t('payroll_anomalies.filters.severities.high'), value: 'high' },
  { title: t('payroll_anomalies.filters.severities.medium'), value: 'medium' },
  { title: t('payroll_anomalies.filters.severities.low'), value: 'low' },
])

const runItems = computed(() => [
  { id: '', ref_number: t('payroll_anomalies.filters.all_runs'), name: '' },
  ...props.runs,
])
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('payroll_anomalies.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('payroll_anomalies.description', { total: anomalies.total }) }}</p>
      </div>
    </div>

    <VRow class="mb-4">
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="error" size="22" class="mb-1">tabler-alert-circle</VIcon>
            <div class="text-h5 font-weight-bold text-error">{{ stats.total_open }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_anomalies.stats.open') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="purple" size="22" class="mb-1">tabler-skull</VIcon>
            <div class="text-h5 font-weight-bold text-purple">{{ stats.total_critical }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_anomalies.stats.critical') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="warning" size="22" class="mb-1">tabler-alert-triangle</VIcon>
            <div class="text-h5 font-weight-bold text-warning">{{ stats.total_high }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_anomalies.stats.high') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="success" size="22" class="mb-1">tabler-circle-check</VIcon>
            <div class="text-h5 font-weight-bold text-success">{{ stats.total_resolved }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_anomalies.stats.resolved') }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard elevation="0" border class="mb-4">
      <VCardText class="pa-3">
        <VRow dense>
          <VCol cols="12" sm="4">
            <VSelect
              v-model="statusFilter"
              :label="t('payroll_anomalies.filters.status')"
              :items="statusItems"
              item-title="title"
              item-value="value"
              density="compact" variant="outlined" hide-details
            />
          </VCol>
          <VCol cols="12" sm="4">
            <VSelect
              v-model="severityFilter"
              :label="t('payroll_anomalies.filters.severity')"
              :items="severityItems"
              item-title="title"
              item-value="value"
              density="compact" variant="outlined" hide-details
            />
          </VCol>
          <VCol cols="12" sm="4">
            <VSelect
              v-model="runIdFilter"
              :label="t('payroll_anomalies.filters.run')"
              :items="runItems"
              item-title="ref_number"
              item-value="id"
              density="compact" variant="outlined" hide-details
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VDataTable
        :items="anomalies.data"
        :headers="[
          { title: t('payroll_anomalies.table.icon'),         key: 'icon',         sortable: false, width: '40px' },
          { title: t('payroll_anomalies.table.type'),       key: 'anomaly_type', sortable: false },
          { title: t('payroll_anomalies.table.run'),        key: 'payroll_run',  sortable: false },
          { title: t('payroll_anomalies.table.employee'),   key: 'employee_name',sortable: false },
          { title: t('payroll_anomalies.table.severity'),   key: 'severity',     sortable: false },
          { title: t('payroll_anomalies.table.status'),     key: 'status',       sortable: false },
          { title: t('payroll_anomalies.table.detected'),   key: 'detected_at',  sortable: false },
          { title: '',           key: 'actions',      sortable: false },
        ]"
        density="comfortable"
        hide-default-footer
      >
        <template #item.icon="{ item }">
          <VIcon size="18" :color="sevColor(item.severity)">{{ anomalyIcon(item.anomaly_type) }}</VIcon>
        </template>

        <template #item.anomaly_type="{ item }">
          <div>
            <div class="text-body-2 font-weight-medium">{{ fmtType(item.anomaly_type) }}</div>
            <div class="text-caption text-medium-emphasis" style="max-width:340px; white-space:normal;">
              {{ item.description?.substring(0, 100) }}{{ (item.description?.length ?? 0) > 100 ? '…' : '' }}
            </div>
          </div>
        </template>

        <template #item.payroll_run="{ item }">
          <span v-if="item.payroll_run" class="text-caption font-weight-medium">
            {{ item.payroll_run.ref_number }}
          </span>
          <span v-else class="text-caption text-medium-emphasis">—</span>
        </template>

        <template #item.employee_name="{ item }">
          <span class="text-body-2">{{ item.employee_name ?? t('payroll_anomalies.run_level') }}</span>
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
          <div v-if="item.status === 'open'" class="d-flex ga-1">
            <VBtn size="x-small" color="success" variant="tonal" prepend-icon="tabler-check"
              @click="openResolve(item)">{{ t('payroll_anomalies.actions.resolve') }}</VBtn>
            <VBtn size="x-small" color="grey" variant="text" icon="tabler-eye-off" aria-label="Dismiss this anomaly" class="row-action"
              @click="openDismiss(item)" />
          </div>
        </template>
      </VDataTable>
    </VCard>

    <VDialog v-model="resolveDialog" max-width="500">
      <VCard v-if="selected">
        <VCardTitle class="pa-4 d-flex align-center ga-2 text-body-1 font-weight-semibold">
          <VIcon color="success">tabler-circle-check</VIcon>{{ t('payroll_anomalies.dialog.resolve_title') }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAlert variant="tonal" :color="sevColor(selected.severity)" density="compact" class="mb-4">
            <div class="font-weight-bold mb-1">{{ fmtType(selected.anomaly_type) }}</div>
            <div class="text-caption">{{ selected.description }}</div>
            <div v-if="selected.metadata" class="mt-2 d-flex flex-wrap ga-1">
              <VChip v-for="(val, key) in selected.metadata" :key="key" size="x-small" variant="outlined">
                {{ key }}: {{ val }}
              </VChip>
            </div>
          </VAlert>
          <VTextarea
            v-model="resolveForm.resolution_notes"
            :label="t('payroll_anomalies.dialog.resolution_notes')"
            variant="outlined"
            density="compact"
            rows="3"
            :error-messages="resolveForm.errors.resolution_notes"
          />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="resolveDialog = false">{{ t('payroll_anomalies.dialog.cancel') }}</VBtn>
          <VBtn color="success" :loading="resolveForm.processing" @click="doResolve">{{ t('payroll_anomalies.dialog.mark_resolved') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="dismissDialog" max-width="500">
      <VCard v-if="selected">
        <VCardTitle class="pa-4 d-flex align-center ga-2 text-body-1 font-weight-semibold">
          <VIcon color="grey">tabler-eye-off</VIcon>{{ t('payroll_anomalies.dialog.dismiss_title') }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <p class="text-body-2 text-medium-emphasis mb-3">
            {{ t('payroll_anomalies.dialog.dismiss_description') }}
          </p>
          <VAlert variant="tonal" color="grey" density="compact" class="mb-4">
            <div class="font-weight-bold">{{ fmtType(selected.anomaly_type) }}</div>
            <div class="text-caption">{{ selected.description }}</div>
          </VAlert>
          <VTextarea
            v-model="dismissForm.resolution_notes"
            :label="t('payroll_anomalies.dialog.dismissal_reason')"
            variant="outlined"
            density="compact"
            rows="3"
            :error-messages="dismissForm.errors.resolution_notes"
          />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="dismissDialog = false">{{ t('payroll_anomalies.dialog.cancel') }}</VBtn>
          <VBtn color="grey" :loading="dismissForm.processing" @click="doDismiss">{{ t('payroll_anomalies.dialog.dismiss_btn') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
