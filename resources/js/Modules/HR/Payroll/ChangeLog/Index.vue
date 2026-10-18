<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3'
import { ref, watch, computed } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface ChangeRow {
  id: number; employee_id: number; employee_name: string | null
  field_changed: string; old_value: string | null; new_value: string | null
  change_type: string; risk_score: number; risk_level: string | null
  changed_at: string; change_reason: string | null; run_id: number
  run_ref: string | null; risk_id: number | null; risk_type: string | null
  severity: string | null; risk_status: string | null; risk_description: string | null
}
interface Run { id: number; uuid: string; ref_number: string; name: string }
interface Page<T> { data: T[]; current_page: number; last_page: number; total: number }

const props = defineProps<{
  changes: Page<ChangeRow>
  filters: Record<string, string>
  stats: { total: number; manual: number; flagged: number; critical: number }
  runs: Run[]
}>()

const runIdFilter      = ref(props.filters.run_id      ?? '')
const changeTypeFilter = ref(props.filters.change_type ?? '')
const riskLevelFilter  = ref(props.filters.risk_level  ?? '')
const fieldFilter      = ref(props.filters.field       ?? '')

watch([runIdFilter, changeTypeFilter, riskLevelFilter, fieldFilter], () => {
  router.get(route('payroll.change-log.index'), {
    run_id: runIdFilter.value, change_type: changeTypeFilter.value,
    risk_level: riskLevelFilter.value, field: fieldFilter.value,
  }, { preserveState: true, replace: true })
})

const analyzeDialog   = ref(false)
const selectedRun     = ref<Run | null>(null)
const analyzeForm     = useForm({})

function doAnalyze() {
  if (!selectedRun.value) return
  analyzeForm.post(route('payroll.change-log.analyze', selectedRun.value.uuid), {
    onSuccess: () => { analyzeDialog.value = false },
  })
}

const reviewForm = useForm({})
const clearForm  = useForm({})

function doReview(riskId: number) {
  reviewForm.post(route('payroll.change-log.risks.review', riskId))
}
function doClear(riskId: number) {
  clearForm.post(route('payroll.change-log.risks.clear', riskId))
}

const riskScoreColor  = (s: number) => s >= 90 ? 'error' : s >= 70 ? 'warning' : s >= 50 ? 'info' : 'success'
const sevColor        = (s: string) => ({ low: 'info', medium: 'warning', high: 'error', critical: 'purple' }[s] ?? 'grey')
const riskStatusColor = (s: string) => ({ flagged: 'error', reviewed: 'warning', cleared: 'success', escalated: 'purple' }[s] ?? 'grey')
const typeColor       = (t: string) => t === 'manual' ? 'warning' : 'grey'

const fmtRiskType = (t: string) => t?.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) ?? '—'
const truncate    = (v: string | null, n = 40) => v ? (v.length > n ? v.substring(0, n) + '…' : v) : '—'

function formatDate(date: string): string {
  if (!date) return '—'
  const d = new Date(date)
  return d.toLocaleDateString('en-ZA', { year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit' })
}

const runItems = computed(() => [
  { id: '', ref_number: t('payroll_change_log.filters.all_runs'), name: '' },
  ...props.runs,
])

const changeTypeItems = computed(() => [
  { title: t('payroll_change_log.filters.all_types'), value: '' },
  { title: t('payroll_change_log.filters.types.manual'), value: 'manual' },
  { title: t('payroll_change_log.filters.types.system'), value: 'system' },
])

const riskLevelItems = computed(() => [
  { title: t('payroll_change_log.filters.all_levels'), value: '' },
  { title: t('payroll_change_log.filters.levels.critical'), value: 'critical' },
  { title: t('payroll_change_log.filters.levels.high'), value: 'high' },
  { title: t('payroll_change_log.filters.levels.medium'), value: 'medium' },
  { title: t('payroll_change_log.filters.levels.low'), value: 'low' },
])
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('payroll_change_log.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">
          {{ t('payroll_change_log.description', { total: changes.total }) }}
        </p>
      </div>
      <VBtn color="warning" prepend-icon="tabler-zoom-scan" @click="analyzeDialog = true">
        {{ t('payroll_change_log.run_analysis') }}
      </VBtn>
    </div>

    <VRow class="mb-4">
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="info" size="22" class="mb-1">tabler-history</VIcon>
            <div class="text-h5 font-weight-bold text-info">{{ stats.total }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_change_log.stats.total_changes') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="warning" size="22" class="mb-1">tabler-pencil</VIcon>
            <div class="text-h5 font-weight-bold text-warning">{{ stats.manual }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_change_log.stats.manual_changes') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="error" size="22" class="mb-1">tabler-flag</VIcon>
            <div class="text-h5 font-weight-bold text-error">{{ stats.flagged }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_change_log.stats.flagged_risks') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="purple" size="22" class="mb-1">tabler-skull</VIcon>
            <div class="text-h5 font-weight-bold text-purple">{{ stats.critical }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_change_log.stats.critical_risks') }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard elevation="0" border class="mb-4">
      <VCardText class="pa-3">
        <VRow dense>
          <VCol cols="12" sm="3">
            <VSelect v-model="runIdFilter" :label="t('payroll_change_log.filters.run')"
              :items="runItems" item-title="ref_number" item-value="id" density="compact" variant="outlined" hide-details />
          </VCol>
          <VCol cols="12" sm="3">
            <VSelect v-model="changeTypeFilter" :label="t('payroll_change_log.filters.change_type')"
              :items="changeTypeItems" item-title="title" item-value="value" density="compact" variant="outlined" hide-details />
          </VCol>
          <VCol cols="12" sm="3">
            <VSelect v-model="riskLevelFilter" :label="t('payroll_change_log.filters.risk_level')"
              :items="riskLevelItems" item-title="title" item-value="value" density="compact" variant="outlined" hide-details />
          </VCol>
          <VCol cols="12" sm="3">
            <VTextField v-model="fieldFilter" :label="t('payroll_change_log.filters.field')" density="compact" variant="outlined"
              hide-details :placeholder="t('payroll_change_log.filters.field_placeholder')" clearable />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VDataTable
        :items="changes.data"
        :headers="[
          { title: t('payroll_change_log.table.employee'),    key: 'employee_name', sortable: false },
          { title: t('payroll_change_log.table.field'),       key: 'field_changed', sortable: false },
          { title: t('payroll_change_log.table.before'),      key: 'old_value',     sortable: false },
          { title: t('payroll_change_log.table.after'),       key: 'new_value',     sortable: false },
          { title: t('payroll_change_log.table.type'),        key: 'change_type',   sortable: false },
          { title: t('payroll_change_log.table.risk_score'),  key: 'risk_score',    sortable: false },
          { title: t('payroll_change_log.table.run'),         key: 'run_ref',       sortable: false },
          { title: t('payroll_change_log.table.risk_status'), key: 'risk_status',   sortable: false },
          { title: t('payroll_change_log.table.changed'),     key: 'changed_at',    sortable: false },
          { title: '',            key: 'actions',       sortable: false },
        ]"
        density="comfortable"
        hide-default-footer
      >
        <template #item.employee_name="{ item }">
          <div class="text-body-2 font-weight-medium">{{ item.employee_name ?? `#${item.employee_id}` }}</div>
          <div v-if="item.change_reason" class="text-caption text-medium-emphasis">{{ truncate(item.change_reason, 45) }}</div>
        </template>

        <template #item.field_changed="{ item }">
          <VChip size="x-small" color="blue-grey" variant="tonal">{{ item.field_changed }}</VChip>
        </template>

        <template #item.old_value="{ item }">
          <span class="text-caption text-error">{{ truncate(item.old_value) }}</span>
        </template>

        <template #item.new_value="{ item }">
          <span class="text-caption text-success">{{ truncate(item.new_value) }}</span>
        </template>

        <template #item.change_type="{ item }">
          <VChip size="x-small" :color="typeColor(item.change_type)" variant="tonal">{{ item.change_type }}</VChip>
        </template>

        <template #item.risk_score="{ item }">
          <div class="d-flex align-center ga-1">
            <VChip size="x-small" :color="riskScoreColor(item.risk_score)" variant="tonal">{{ item.risk_score }}</VChip>
            <VChip v-if="item.risk_level && item.risk_level !== 'none'" size="x-small"
              :color="sevColor(item.risk_level)" variant="tonal" class="text-uppercase">{{ item.risk_level }}</VChip>
          </div>
        </template>

        <template #item.run_ref="{ item }">
          <span class="text-caption font-weight-medium">{{ item.run_ref ?? '—' }}</span>
        </template>

        <template #item.risk_status="{ item }">
          <template v-if="item.risk_id">
            <VChip size="x-small" :color="riskStatusColor(item.risk_status ?? '')" variant="tonal">
              {{ item.risk_status }}
            </VChip>
            <div class="text-caption text-medium-emphasis mt-1" style="max-width: 160px; white-space: normal;">
              {{ fmtRiskType(item.risk_type ?? '') }}
            </div>
          </template>
          <span v-else class="text-caption text-medium-emphasis">—</span>
        </template>

        <template #item.changed_at="{ item }">
          <span class="text-caption">{{ formatDate(item.changed_at) }}</span>
        </template>

        <template #item.actions="{ item }">
          <div v-if="item.risk_id && item.risk_status === 'flagged'" class="d-flex ga-1">
            <VBtn size="x-small" color="warning" variant="tonal"
              :loading="reviewForm.processing" @click="doReview(item.risk_id)">
              {{ t('payroll_change_log.actions.review') }}
            </VBtn>
            <VBtn size="x-small" color="success" variant="tonal"
              :loading="clearForm.processing" @click="doClear(item.risk_id)">
              {{ t('payroll_change_log.actions.clear') }}
            </VBtn>
          </div>
        </template>
      </VDataTable>

      <VDivider v-if="changes.last_page > 1" />
      <div v-if="changes.last_page > 1" class="d-flex justify-center pa-3">
        <VPagination :model-value="changes.current_page" :length="changes.last_page" density="compact"
          @update:model-value="p => router.get(route('payroll.change-log.index'), { ...filters, page: p }, { preserveState: true })" />
      </div>
    </VCard>

    <VDialog v-model="analyzeDialog" max-width="480">
      <VCard>
        <VCardTitle class="pa-4 d-flex align-center ga-2 text-body-1 font-weight-semibold">
          <VIcon color="warning">tabler-zoom-scan</VIcon>{{ t('payroll_change_log.dialog.run_title') }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <p class="text-body-2 text-medium-emphasis mb-4">{{ t('payroll_change_log.dialog.run_description') }}</p>
          <VSelect v-model="selectedRun" :label="t('payroll_change_log.dialog.select_run')" :items="runs"
            item-title="ref_number" item-value="id" return-object variant="outlined" density="compact">
            <template #item="{ item, props: p }">
              <VListItem v-bind="p"><template #subtitle>{{ item.raw.name }}</template></VListItem>
            </template>
          </VSelect>
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="analyzeDialog = false">{{ t('payroll_change_log.dialog.cancel') }}</VBtn>
          <VBtn color="warning" :loading="analyzeForm.processing" :disabled="!selectedRun" @click="doAnalyze">
            {{ t('payroll_change_log.dialog.analyse_risks') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
