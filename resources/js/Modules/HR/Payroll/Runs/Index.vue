<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { ref, watch, computed } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Run {
  id: number; uuid: string; name: string; period_start: string; period_end: string
  employee_count: number; total_gross: number; total_net: number
  status: string; risk_level: string | null
}
interface Page<T> { data: T[]; current_page: number; last_page: number; total: number }

const props = defineProps<{
  runs: Page<Run>
  filters: Record<string, string>
  stats: Record<string, any>
}>()

const statusItems = computed(() => [
  { title: t('payroll_runs_index.filters.statuses.all'), value: '' },
  { title: t('payroll_runs_index.filters.statuses.draft'), value: 'draft' },
  { title: t('payroll_runs_index.filters.statuses.processing'), value: 'processing' },
  { title: t('payroll_runs_index.filters.statuses.review'), value: 'review' },
  { title: t('payroll_runs_index.filters.statuses.released'), value: 'released' },
  { title: t('payroll_runs_index.filters.statuses.cancelled'), value: 'cancelled' },
])

const search = ref(props.filters.search ?? '')
const statusFilter = ref(props.filters.status ?? '')

watch([search, statusFilter], () => {
  router.get(route('payroll.runs.index'), { search: search.value, status: statusFilter.value }, { preserveState: true, replace: true })
})

const statusColor = (s: string) => ({
  draft: 'grey', processing: 'info', review: 'warning',
  released: 'success', cancelled: 'error',
}[s] ?? 'grey')

const riskColor = (r: string | null) => ({ low: 'success', medium: 'warning', high: 'error', critical: 'error' }[r ?? ''] ?? 'grey')
const fmt = (n: number) => 'R' + Number(n).toLocaleString('en-ZA', { minimumFractionDigits: 2 })

function formatDate(date: string): string {
  if (!date) return '—'
  const d = new Date(date)
  return d.toLocaleDateString('en-ZA', {
    year: 'numeric',
    month: 'short',
    day: '2-digit',
  })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <h1 class="text-h5 font-weight-bold">{{ t('payroll_runs_index.page_title') }}</h1>
      <VBtn color="primary" prepend-icon="tabler-plus" :href="route('payroll.runs.create')">
        {{ t('payroll_runs_index.new_run') }}
      </VBtn>
    </div>

    <VRow class="mb-4">
      <VCol cols="12" sm="4">
        <VCard elevation="0" border>
          <VCardText class="pa-3">
            <div class="d-flex align-center justify-space-between mb-1">
              <VIcon color="success" size="22">tabler-circle-check</VIcon>
              <span class="text-h6 font-weight-bold text-success">{{ stats.total_released ? fmt(stats.total_released) : 'R0' }}</span>
            </div>
            <p class="text-caption text-medium-emphasis mb-0">{{ t('payroll_runs_index.stats.total_released') }}</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="4">
        <VCard elevation="0" border>
          <VCardText class="pa-3">
            <div class="d-flex align-center justify-space-between mb-1">
              <VIcon color="primary" size="22">tabler-calendar-month</VIcon>
              <span class="text-h6 font-weight-bold text-primary">{{ stats.current_month_cost ? fmt(stats.current_month_cost) : 'R0' }}</span>
            </div>
            <p class="text-caption text-medium-emphasis mb-0">{{ t('payroll_runs_index.stats.current_month_cost') }}</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="4">
        <VCard elevation="0" border>
          <VCardText class="pa-3">
            <div class="d-flex align-center justify-space-between mb-1">
              <VIcon color="info" size="22">tabler-users</VIcon>
              <span class="text-h6 font-weight-bold text-info">{{ stats.total_employees ?? 0 }}</span>
            </div>
            <p class="text-caption text-medium-emphasis mb-0">{{ t('payroll_runs_index.stats.total_employees') }}</p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard elevation="0" border>
      <VCardText class="pa-3">
        <VRow dense>
          <VCol cols="12" sm="7">
            <VTextField
              v-model="search"
              :label="t('payroll_runs_index.filters.search_placeholder')"
              prepend-inner-icon="tabler-search"
              density="compact"
              variant="outlined"
              hide-details
              clearable
            />
          </VCol>
          <VCol cols="12" sm="5">
            <VSelect
              v-model="statusFilter"
              :label="t('payroll_runs_index.filters.status_label')"
              :items="statusItems"
              density="compact"
              variant="outlined"
              hide-details
              clearable
            />
          </VCol>
        </VRow>
      </VCardText>
      <VDivider />
      <VDataTableServer
        :items="runs.data"
        :items-length="runs.total"
        :headers="[
          { title: t('payroll_runs_index.table.name'), key: 'name' },
          { title: t('payroll_runs_index.table.period_start'), key: 'period_start' },
          { title: t('payroll_runs_index.table.period_end'), key: 'period_end' },
          { title: t('payroll_runs_index.table.employees'), key: 'employee_count' },
          { title: t('payroll_runs_index.table.gross_pay'), key: 'total_gross' },
          { title: t('payroll_runs_index.table.net_pay'), key: 'total_net' },
          { title: t('payroll_runs_index.table.risk'), key: 'risk_level' },
          { title: t('payroll_runs_index.table.status'), key: 'status' },
          { title: '', key: 'actions', sortable: false },
        ]"
        hide-default-footer
      >
        <template #item.period_start="{ item }">
          <span class="text-caption">{{ formatDate(item.period_start) }}</span>
        </template>
        <template #item.period_end="{ item }">
          <span class="text-caption">{{ formatDate(item.period_end) }}</span>
        </template>
        <template #item.total_gross="{ item }">{{ fmt(item.total_gross) }}</template>
        <template #item.total_net="{ item }"><span class="font-weight-medium">{{ fmt(item.total_net) }}</span></template>
        <template #item.risk_level="{ item }">
          <VChip v-if="item.risk_level" size="x-small" :color="riskColor(item.risk_level)" variant="tonal">{{ item.risk_level }}</VChip>
          <span v-else class="text-caption text-medium-emphasis">—</span>
        </template>
        <template #item.status="{ item }">
          <VChip size="small" :color="statusColor(item.status)" variant="tonal">
            {{ t(`statuses.${item.status}`) }}
          </VChip>
        </template>
        <template #item.actions="{ item }">
          <VBtn size="x-small" variant="text" icon="tabler-eye" aria-label="Open this payroll run" class="row-action" :href="route('payroll.runs.show', item.uuid)" />
        </template>
      </VDataTableServer>
    </VCard>
  </div>
</template>
