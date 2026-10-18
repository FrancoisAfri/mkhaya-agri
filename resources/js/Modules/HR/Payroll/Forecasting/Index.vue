<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface ForecastMonth {
  month: string; label: string
  forecasted_cost: number; growth_pct: number
}
interface OvertimeMonth {
  month: string; label: string
  forecasted_overtime: number; trend_pct: number
}
interface HeadcountMonth {
  month: string; label: string
  headcount: number; estimated_cost: number; cost_per_head: number
}
interface DeptBonus { department: string; dept_net: number; estimated_bonus: number }
interface BonusData {
  estimated_bonus_pool: number; annual_net: number
  bonus_percentage: number; per_department: DeptBonus[]
}
interface LeaveLiability { current_balance_days: number; estimated_cost: number; avg_daily_rate: number }
interface Summary {
  annual_total: number; peak_monthly: number
  overtime_total: number; leave_liability: number; bonus_pool: number
}

const props = defineProps<{
  annual:    ForecastMonth[]
  overtime:  OvertimeMonth[]
  leave:     LeaveLiability
  headcount: HeadcountMonth[]
  bonus:     BonusData
  summary:   Summary
  params:    { months: number; bonus_pct: number }
}>()

const monthsFilter   = ref(props.params.months)
const bonusPctFilter = ref(props.params.bonus_pct)

watch([monthsFilter, bonusPctFilter], () => {
  router.get(route('payroll.forecasting.index'), {
    months: monthsFilter.value, bonus_pct: bonusPctFilter.value,
  }, { preserveState: true, replace: true })
})

const fmt = (n: number) =>
  'R ' + new Intl.NumberFormat('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n)

const pctColor = (p: number) => p > 5 ? 'error' : p > 0 ? 'warning' : 'success'

const horizonItems = computed(() => [
  { title: t('payroll_forecasting.horizon_options.3_months'), value: 3 },
  { title: t('payroll_forecasting.horizon_options.6_months'), value: 6 },
  { title: t('payroll_forecasting.horizon_options.12_months'), value: 12 },
  { title: t('payroll_forecasting.horizon_options.24_months'), value: 24 },
])

const annualHeaders = [
  { title: t('payroll_forecasting.annual_forecast.table.month'), key: 'label', sortable: false },
  { title: t('payroll_forecasting.annual_forecast.table.forecasted_cost'), key: 'forecasted_cost', sortable: false },
  { title: t('payroll_forecasting.annual_forecast.table.growth'), key: 'growth_pct', sortable: false },
]
const otHeaders = [
  { title: t('payroll_forecasting.overtime_forecast.table.month'), key: 'label', sortable: false },
  { title: t('payroll_forecasting.overtime_forecast.table.forecasted_ot'), key: 'forecasted_overtime', sortable: false },
  { title: t('payroll_forecasting.overtime_forecast.table.trend'), key: 'trend_pct', sortable: false },
]
const hcHeaders = [
  { title: t('payroll_forecasting.headcount_forecast.table.month'), key: 'label', sortable: false },
  { title: t('payroll_forecasting.headcount_forecast.table.headcount'), key: 'headcount', sortable: false },
  { title: t('payroll_forecasting.headcount_forecast.table.estimated_cost'), key: 'estimated_cost', sortable: false },
  { title: t('payroll_forecasting.headcount_forecast.table.cost_per_head'), key: 'cost_per_head', sortable: false },
]
const deptHeaders = [
  { title: t('payroll_forecasting.bonus_exposure.table.department'), key: 'department', sortable: false },
  { title: t('payroll_forecasting.bonus_exposure.table.annual_net'), key: 'dept_net', sortable: false },
  { title: t('payroll_forecasting.bonus_exposure.table.estimated_bonus'), key: 'estimated_bonus', sortable: false },
]
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('payroll_forecasting.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('payroll_forecasting.description') }}</p>
      </div>
    </div>

    <VRow class="mb-4">
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="primary" size="22" class="mb-1">tabler-currency-dollar</VIcon>
            <div class="text-h6 font-weight-bold text-primary">{{ fmt(summary.annual_total) }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_forecasting.summary.12_month_total') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="warning" size="22" class="mb-1">tabler-trending-up</VIcon>
            <div class="text-h6 font-weight-bold text-warning">{{ fmt(summary.peak_monthly) }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_forecasting.summary.peak_monthly') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="error" size="22" class="mb-1">tabler-umbrella-beach</VIcon>
            <div class="text-h6 font-weight-bold text-error">{{ fmt(summary.leave_liability) }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_forecasting.summary.leave_liability') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="success" size="22" class="mb-1">tabler-gift</VIcon>
            <div class="text-h6 font-weight-bold text-success">{{ fmt(summary.bonus_pool) }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_forecasting.summary.bonus_pool', { pct: params.bonus_pct }) }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard elevation="0" border class="mb-4">
      <VCardText class="pa-3">
        <div class="d-flex flex-wrap ga-4 align-center">
          <div class="text-caption font-weight-medium text-medium-emphasis">{{ t('payroll_forecasting.forecast_params') }}</div>
          <div style="width:200px">
            <VSelect v-model="monthsFilter" :label="t('payroll_forecasting.horizon')"
              :items="horizonItems"
              item-title="title" item-value="value" density="compact" variant="outlined" hide-details />
          </div>
          <div style="width:200px">
            <VTextField v-model.number="bonusPctFilter" :label="t('payroll_forecasting.bonus_assumption')"
              type="number" min="1" max="30" density="compact" variant="outlined" hide-details suffix="%" />
          </div>
        </div>
      </VCardText>
    </VCard>

    <VRow>
      <VCol cols="12" md="6">
        <VCard elevation="0" border class="mb-4">
          <VCardTitle class="pa-3 text-body-1 font-weight-semibold d-flex align-center ga-2">
            <VIcon color="primary" size="20">tabler-chart-line</VIcon>{{ t('payroll_forecasting.annual_forecast.title') }}
          </VCardTitle>
          <VDivider />
          <VDataTable :items="annual" :headers="annualHeaders" density="comfortable" hide-default-footer items-per-page="-1">
            <template #item.forecasted_cost="{ item }">
              <span class="font-weight-medium">{{ fmt(item.forecasted_cost) }}</span>
            </template>
            <template #item.growth_pct="{ item }">
              <VChip size="x-small" :color="pctColor(item.growth_pct)" variant="tonal">
                +{{ item.growth_pct }}%
              </VChip>
            </template>
          </VDataTable>
        </VCard>
      </VCol>

      <VCol cols="12" md="6">
        <VCard elevation="0" border class="mb-4">
          <VCardTitle class="pa-3 text-body-1 font-weight-semibold d-flex align-center ga-2">
            <VIcon color="warning" size="20">tabler-clock-exclamation</VIcon>{{ t('payroll_forecasting.overtime_forecast.title') }}
          </VCardTitle>
          <VDivider />
          <div v-if="overtime.length === 0" class="pa-6 text-center text-medium-emphasis text-caption">
            {{ t('payroll_forecasting.overtime_forecast.no_data') }}
          </div>
          <VDataTable v-else :items="overtime" :headers="otHeaders" density="comfortable" hide-default-footer items-per-page="-1">
            <template #item.forecasted_overtime="{ item }">
              <span class="font-weight-medium">{{ fmt(item.forecasted_overtime) }}</span>
            </template>
            <template #item.trend_pct="{ item }">
              <VChip size="x-small" :color="item.trend_pct > 0 ? 'error' : 'success'" variant="tonal">
                {{ item.trend_pct >= 0 ? '+' : '' }}{{ item.trend_pct }}%
              </VChip>
            </template>
          </VDataTable>
        </VCard>
      </VCol>

      <VCol cols="12" md="6">
        <VCard elevation="0" border class="mb-4">
          <VCardTitle class="pa-3 text-body-1 font-weight-semibold d-flex align-center ga-2">
            <VIcon color="info" size="20">tabler-users</VIcon>{{ t('payroll_forecasting.headcount_forecast.title') }}
          </VCardTitle>
          <VDivider />
          <div v-if="headcount.length === 0" class="pa-6 text-center text-medium-emphasis text-caption">
            {{ t('payroll_forecasting.headcount_forecast.no_data') }}
          </div>
          <VDataTable v-else :items="headcount" :headers="hcHeaders" density="comfortable" hide-default-footer items-per-page="-1">
            <template #item.headcount="{ item }">
              <VChip size="x-small" color="info" variant="tonal">{{ item.headcount }}</VChip>
            </template>
            <template #item.estimated_cost="{ item }">
              <span class="font-weight-medium">{{ fmt(item.estimated_cost) }}</span>
            </template>
            <template #item.cost_per_head="{ item }">
              <span class="text-caption text-medium-emphasis">{{ fmt(item.cost_per_head) }}</span>
            </template>
          </VDataTable>
        </VCard>
      </VCol>

      <VCol cols="12" md="6">
        <VCard elevation="0" border class="mb-4">
          <VCardTitle class="pa-3 text-body-1 font-weight-semibold d-flex align-center ga-2">
            <VIcon color="error" size="20">tabler-umbrella-beach</VIcon>{{ t('payroll_forecasting.leave_liability.title') }}
          </VCardTitle>
          <VDivider />
          <VCardText class="pa-4">
            <VRow dense>
              <VCol cols="4" class="text-center">
                <div class="text-h5 font-weight-bold text-error">{{ leave.current_balance_days }}</div>
                <div class="text-caption text-medium-emphasis">{{ t('payroll_forecasting.leave_liability.pending_days') }}</div>
              </VCol>
              <VCol cols="4" class="text-center">
                <div class="text-h5 font-weight-bold text-warning">{{ fmt(leave.avg_daily_rate) }}</div>
                <div class="text-caption text-medium-emphasis">{{ t('payroll_forecasting.leave_liability.avg_daily_rate') }}</div>
              </VCol>
              <VCol cols="4" class="text-center">
                <div class="text-h5 font-weight-bold text-primary">{{ fmt(leave.estimated_cost) }}</div>
                <div class="text-caption text-medium-emphasis">{{ t('payroll_forecasting.leave_liability.estimated_cost') }}</div>
              </VCol>
            </VRow>
            <VAlert type="info" variant="tonal" density="compact" class="mt-4 text-caption">
              {{ t('payroll_forecasting.leave_liability.info') }}
            </VAlert>
          </VCardText>
        </VCard>

        <VCard elevation="0" border>
          <VCardTitle class="pa-3 text-body-1 font-weight-semibold d-flex align-center ga-2">
            <VIcon color="success" size="20">tabler-gift</VIcon>
            {{ t('payroll_forecasting.bonus_exposure.title') }}
            <VChip size="x-small" color="success" variant="tonal" class="ml-auto">{{ params.bonus_pct }}%</VChip>
          </VCardTitle>
          <VDivider />
          <div v-if="!bonus.per_department?.length" class="pa-6 text-center text-medium-emphasis text-caption">
            {{ t('payroll_forecasting.bonus_exposure.no_data') }}
          </div>
          <VDataTable v-else :items="bonus.per_department" :headers="deptHeaders" density="comfortable" hide-default-footer items-per-page="-1">
            <template #item.dept_net="{ item }">
              <span class="text-caption text-medium-emphasis">{{ fmt(item.dept_net) }}</span>
            </template>
            <template #item.estimated_bonus="{ item }">
              <span class="font-weight-medium text-success">{{ fmt(item.estimated_bonus) }}</span>
            </template>
          </VDataTable>
          <VDivider />
          <div class="pa-3 d-flex justify-space-between align-center text-body-2">
            <span class="text-medium-emphasis">{{ t('payroll_forecasting.bonus_exposure.total_pool') }}</span>
            <span class="font-weight-bold text-success">{{ fmt(bonus.estimated_bonus_pool) }}</span>
          </div>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
