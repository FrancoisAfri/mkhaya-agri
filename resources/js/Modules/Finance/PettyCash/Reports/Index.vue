<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  filters: { from: string; to: string }
  requestsByStatus: { status: string; count: number; total: string }[]
  requestsByCategory: { category: string; count: number; total: string }[]
  floatActivity: { float_name: string; custodian: string | null; current_balance: number; total_requested: number; total_issued: number }[]
  violations: { violation_type: string; severity: string; count: number }[]
  floats: { id: number; name: string }[]
}>()

const from = ref(props.filters.from)
const to   = ref(props.filters.to)
const apply = () => router.get(route('petty-cash.reports.index'), { from: from.value, to: to.value }, { preserveState: true, replace: true })

const fmt = (n: number) => 'R ' + Number(n).toLocaleString('en-ZA', { minimumFractionDigits: 2 })
const statusColor = (s: string) => ({ draft: 'grey', pending: 'blue', approved: 'teal', rejected: 'error', issued: 'orange', settled: 'success' })[s] ?? 'grey'
const severityColor = (s: string) => ({ low: 'success', medium: 'warning', high: 'deep-orange', critical: 'error' })[s] ?? 'grey'

const floatActivityHeaders = computed(()=> [
  { title: t('petty_cash_reports.float_activity.table.float'), key: 'float_name' },
  { title: t('petty_cash_reports.float_activity.table.custodian'), key: 'custodian' },
  { title: t('petty_cash_reports.float_activity.table.current_balance'), key: 'current_balance' },
  { title: t('petty_cash_reports.float_activity.table.total_requested'), key: 'total_requested' },
  { title: t('petty_cash_reports.float_activity.table.total_issued'), key: 'total_issued' },
])
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('petty_cash_reports.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('petty_cash_reports.description') }}</p>
      </div>
    </div>

    <!-- Date Filters -->
    <VCard elevation="0" border class="mb-4">
      <VCardText class="d-flex ga-3 align-center flex-wrap pa-3">
        <VTextField v-model="from" :label="t('petty_cash_reports.filters.from')" type="date" density="compact" variant="outlined" hide-details style="max-width:160px" />
        <VTextField v-model="to" :label="t('petty_cash_reports.filters.to')" type="date" density="compact" variant="outlined" hide-details style="max-width:160px" />
        <VBtn color="primary" @click="apply" density="comfortable">{{ t('petty_cash_reports.filters.apply') }}</VBtn>
      </VCardText>
    </VCard>

    <VRow>
      <!-- Requests by Status -->
      <VCol cols="12" md="6">
        <VCard elevation="0" border>
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">{{ t('petty_cash_reports.requests_by_status.title') }}</VCardTitle>
          <VDivider />
          <VList density="compact">
            <VListItem v-for="row in requestsByStatus" :key="row.status">
              <template #prepend>
                <VChip :color="statusColor(row.status)" size="x-small" variant="tonal" class="mr-2">{{ row.status }}</VChip>
              </template>
              <VListItemTitle class="text-body-2">{{ t('petty_cash_reports.requests_by_status.requests', { count: row.count }) }}</VListItemTitle>
              <template #append>
                <span class="text-body-2 font-weight-semibold">{{ fmt(Number(row.total)) }}</span>
              </template>
            </VListItem>
            <VListItem v-if="!requestsByStatus.length">
              <VListItemSubtitle class="text-caption">{{ t('petty_cash_reports.requests_by_status.no_data') }}</VListItemSubtitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>

      <!-- Requests by Category -->
      <VCol cols="12" md="6">
        <VCard elevation="0" border>
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">{{ t('petty_cash_reports.requests_by_category.title') }}</VCardTitle>
          <VDivider />
          <VList density="compact">
            <VListItem v-for="row in requestsByCategory" :key="row.category">
              <VListItemTitle class="text-body-2 text-capitalize">{{ row.category?.replace('_', ' ') ?? t('petty_cash_reports.requests_by_category.uncategorised') }}</VListItemTitle>
              <VListItemSubtitle class="text-caption">{{ t('petty_cash_reports.requests_by_category.requests', { count: row.count }) }}</VListItemSubtitle>
              <template #append>
                <span class="text-body-2 font-weight-semibold">{{ fmt(Number(row.total)) }}</span>
              </template>
            </VListItem>
            <VListItem v-if="!requestsByCategory.length">
              <VListItemSubtitle class="text-caption">{{ t('petty_cash_reports.requests_by_category.no_data') }}</VListItemSubtitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>

      <!-- Float Activity -->
      <VCol cols="12">
        <VCard elevation="0" border>
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">{{ t('petty_cash_reports.float_activity.title') }}</VCardTitle>
          <VDivider />
          <VDataTable
            :headers="floatActivityHeaders"
            :items="floatActivity"
            :items-per-page="-1"
            hide-default-footer
          >
            <template #item.current_balance="{ item }">{{ fmt(item.current_balance) }}</template>
            <template #item.total_requested="{ item }">{{ fmt(Number(item.total_requested)) }}</template>
            <template #item.total_issued="{ item }">{{ fmt(Number(item.total_issued)) }}</template>
          </VDataTable>
        </VCard>
      </VCol>

      <!-- Violations Summary -->
      <VCol cols="12" md="6" v-if="violations.length">
        <VCard elevation="0" border>
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">{{ t('petty_cash_reports.violations_summary.title') }}</VCardTitle>
          <VDivider />
          <VList density="compact">
            <VListItem v-for="violation in violations" :key="violation.violation_type + violation.severity">
              <template #prepend>
                <VChip :color="severityColor(violation.severity)" size="x-small" variant="flat" class="mr-2">{{ violation.severity }}</VChip>
              </template>
              <VListItemTitle class="text-body-2 text-capitalize">{{ violation.violation_type.replace(/_/g, ' ') }}</VListItemTitle>
              <template #append>
                <span class="text-body-2">{{ violation.count }}x</span>
              </template>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
