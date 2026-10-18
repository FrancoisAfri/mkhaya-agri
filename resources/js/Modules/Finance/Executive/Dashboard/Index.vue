<script setup lang="ts">
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Widget { label: string; value: string | number; icon: string; color: string }
interface SpendMonth { month: string; petty_cash: number; loans: number; advances: number; total: number }
interface FinanceMetric { label: string; value: number; format?: string }
interface FinanceCard { code: string; name: string; icon: string; color: string; link: string; metrics: FinanceMetric[] }

interface PettyCashData {
  total_float_exposure: number; total_current_balance: number
  floats_needing_replenishment: number; missing_receipts: number
  pending_reconciliations: number; total_issued_this_month: number
  open_violations: number
}
interface LoanData {
  active_loans: number; active_advances: number; total_outstanding: number
  monthly_deduction_exposure: number; pending_approvals: number
  default_risk: number; overdue_repayments: number; high_deduction_employees: number
}
interface RiskSummary { wellness_risk_count: number; open_violations: number; critical_loans: number }
interface Compliance {
  total_violations: number; petty_cash_violations: number; loan_violations: number
  resolved: number; resolution_rate: number
}

defineProps<{
  widgets: Widget[]
  moduleFinance: FinanceCard[]
  pettyCash: PettyCashData
  loans: LoanData
  riskSummary: RiskSummary
  spendTrend: SpendMonth[]
  compliance: Compliance
}>()

const fmt = (n: number) => 'R' + Number(n).toLocaleString()
const pct = (n: number) => Number(n).toFixed(1) + '%'

const widgetColor = (w: Widget) => {
  if (typeof w.value === 'number' && w.value === 0 && ['error', 'warning'].includes(w.color)) return 'success'
  return w.color
}

const trendMax = (trend: SpendMonth[]) => Math.max(...trend.map(m => m.total), 1)
const barH = (val: number, max: number) => Math.round((val / max) * 80)
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('executive_finance.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('executive_finance.description') }}</p>
      </div>
      <div class="d-flex ga-2">
        <VBtn variant="outlined" prepend-icon="tabler-building-bank" :href="route('staff-loans.dashboard')">{{ t('executive_finance.staff_loans') }}</VBtn>
        <VBtn variant="outlined" prepend-icon="tabler-wallet" :href="route('petty-cash.dashboard')">{{ t('executive_finance.petty_cash') }}</VBtn>
      </div>
    </div>

    <VRow class="mb-4">
      <VCol v-for="widget in widgets" :key="widget.label" cols="6" sm="4" md="3" lg="2">
        <VCard elevation="0" border class="pa-3 text-center">
          <VIcon :color="widgetColor(widget)" size="28" class="mb-1">{{ widget.icon }}</VIcon>
          <div class="text-h6 font-weight-bold" :class="`text-${widgetColor(widget)}`">{{ widget.value }}</div>
          <div class="text-caption text-medium-emphasis">{{ widget.label }}</div>
        </VCard>
      </VCol>
    </VRow>

    <!-- Finance across every ACTIVE module — the executive cross-module overview. -->
    <template v-if="moduleFinance && moduleFinance.length">
      <div class="d-flex align-center mb-2">
        <VIcon class="mr-2" color="primary">tabler-chart-infographic</VIcon>
        <span class="text-body-1 font-weight-semibold">{{ t('executive_finance.by_module_title') }}</span>
      </div>
      <VRow class="mb-4">
        <VCol v-for="card in moduleFinance" :key="card.code" cols="12" sm="6" md="4" lg="3">
          <VCard elevation="0" border class="h-100">
            <VCardText class="pa-4">
              <div class="d-flex align-center justify-space-between mb-3">
                <div class="d-flex align-center">
                  <VIcon :color="card.color" size="22" class="mr-2">{{ card.icon }}</VIcon>
                  <span class="text-body-1 font-weight-semibold">{{ card.name }}</span>
                </div>
                <VBtn :href="card.link" size="x-small" variant="tonal" :color="card.color" icon="tabler-arrow-up-right" />
              </div>
              <div v-for="m in card.metrics" :key="m.label" class="d-flex align-center justify-space-between py-1">
                <span class="text-caption text-medium-emphasis">{{ m.label }}</span>
                <span class="font-weight-semibold text-body-2">{{ m.format === 'currency' ? fmt(m.value) : Number(m.value).toLocaleString() }}</span>
              </div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </template>

    <VRow>
      <VCol cols="12" md="4">
        <VCard elevation="0" border class="mb-4">
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">
            <VIcon class="mr-2" color="teal">tabler-wallet</VIcon> {{ t('executive_finance.petty_cash_section.title') }}
          </VCardTitle>
          <VDivider />
          <VCardText>
            <VList density="compact">
              <VListItem>
                <VListItemTitle class="text-caption text-medium-emphasis">{{ t('executive_finance.petty_cash_section.float_exposure') }}</VListItemTitle>
                <template #append><span class="font-weight-semibold text-body-2">{{ fmt(pettyCash.total_float_exposure) }}</span></template>
              </VListItem>
              <VListItem>
                <VListItemTitle class="text-caption text-medium-emphasis">{{ t('executive_finance.petty_cash_section.current_balance') }}</VListItemTitle>
                <template #append><span class="font-weight-semibold text-body-2">{{ fmt(pettyCash.total_current_balance) }}</span></template>
              </VListItem>
              <VListItem>
                <VListItemTitle class="text-caption text-medium-emphasis">{{ t('executive_finance.petty_cash_section.issued_this_month') }}</VListItemTitle>
                <template #append><span class="font-weight-semibold text-body-2">{{ fmt(pettyCash.total_issued_this_month) }}</span></template>
              </VListItem>
            </VList>
            <VDivider class="my-2" />
            <div class="d-flex ga-2 flex-wrap">
              <VChip size="x-small" :color="pettyCash.floats_needing_replenishment > 0 ? 'warning' : 'success'" variant="tonal">
                {{ t('executive_finance.petty_cash_section.need_replenishment', { count: pettyCash.floats_needing_replenishment }) }}
              </VChip>
              <VChip size="x-small" :color="pettyCash.missing_receipts > 0 ? 'error' : 'success'" variant="tonal">
                {{ t('executive_finance.petty_cash_section.missing_receipts', { count: pettyCash.missing_receipts }) }}
              </VChip>
              <VChip size="x-small" :color="pettyCash.pending_reconciliations > 0 ? 'warning' : 'success'" variant="tonal">
                {{ t('executive_finance.petty_cash_section.pending_recon', { count: pettyCash.pending_reconciliations }) }}
              </VChip>
              <VChip size="x-small" :color="pettyCash.open_violations > 0 ? 'error' : 'success'" variant="tonal">
                {{ t('executive_finance.petty_cash_section.violations', { count: pettyCash.open_violations }) }}
              </VChip>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard elevation="0" border class="mb-4">
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">
            <VIcon class="mr-2" color="primary">tabler-building-bank</VIcon> {{ t('executive_finance.loans_section.title') }}
          </VCardTitle>
          <VDivider />
          <VCardText>
            <VList density="compact">
              <VListItem>
                <VListItemTitle class="text-caption text-medium-emphasis">{{ t('executive_finance.loans_section.total_outstanding') }}</VListItemTitle>
                <template #append><span class="font-weight-semibold text-body-2">{{ fmt(loans.total_outstanding) }}</span></template>
              </VListItem>
              <VListItem>
                <VListItemTitle class="text-caption text-medium-emphasis">{{ t('executive_finance.loans_section.monthly_deduction_exposure') }}</VListItemTitle>
                <template #append><span class="font-weight-semibold text-body-2 text-deep-orange">{{ fmt(loans.monthly_deduction_exposure) }}</span></template>
              </VListItem>
              <VListItem>
                <VListItemTitle class="text-caption text-medium-emphasis">{{ t('executive_finance.loans_section.pending_approvals') }}</VListItemTitle>
                <template #append><span class="font-weight-semibold text-body-2">{{ loans.pending_approvals }}</span></template>
              </VListItem>
            </VList>
            <VDivider class="my-2" />
            <div class="d-flex ga-2 flex-wrap">
              <VChip size="x-small" :color="loans.default_risk > 0 ? 'error' : 'success'" variant="tonal">
                {{ t('executive_finance.loans_section.default_risk', { count: loans.default_risk }) }}
              </VChip>
              <VChip size="x-small" :color="loans.overdue_repayments > 0 ? 'error' : 'success'" variant="tonal">
                {{ t('executive_finance.loans_section.overdue', { count: loans.overdue_repayments }) }}
              </VChip>
              <VChip size="x-small" :color="loans.high_deduction_employees > 0 ? 'warning' : 'success'" variant="tonal">
                {{ t('executive_finance.loans_section.high_deduction', { count: loans.high_deduction_employees }) }}
              </VChip>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard elevation="0" border class="mb-4">
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">
            <VIcon class="mr-2" :color="compliance.total_violations > 0 ? 'error' : 'success'">tabler-shield-check</VIcon>
            {{ t('executive_finance.compliance_section.title') }}
          </VCardTitle>
          <VDivider />
          <VCardText>
            <div class="text-center mb-3">
              <div class="text-h4 font-weight-bold" :class="compliance.resolution_rate >= 80 ? 'text-success' : 'text-warning'">
                {{ compliance.resolution_rate }}%
              </div>
              <div class="text-caption text-medium-emphasis">{{ t('executive_finance.compliance_section.resolution_rate') }}</div>
              <VProgressLinear :model-value="compliance.resolution_rate"
                :color="compliance.resolution_rate >= 80 ? 'success' : 'warning'" rounded height="6" class="mt-2" />
            </div>
            <VDivider class="mb-2" />
            <VList density="compact">
              <VListItem>
                <VListItemTitle class="text-caption text-medium-emphasis">{{ t('executive_finance.compliance_section.petty_cash_violations') }}</VListItemTitle>
                <template #append>
                  <VChip size="x-small" :color="compliance.petty_cash_violations > 0 ? 'error' : 'success'" variant="tonal">{{ compliance.petty_cash_violations }}</VChip>
                </template>
              </VListItem>
              <VListItem>
                <VListItemTitle class="text-caption text-medium-emphasis">{{ t('executive_finance.compliance_section.loan_violations') }}</VListItemTitle>
                <template #append>
                  <VChip size="x-small" :color="compliance.loan_violations > 0 ? 'error' : 'success'" variant="tonal">{{ compliance.loan_violations }}</VChip>
                </template>
              </VListItem>
              <VListItem>
                <VListItemTitle class="text-caption text-medium-emphasis">{{ t('executive_finance.compliance_section.resolved') }}</VListItemTitle>
                <template #append><span class="font-weight-semibold text-body-2 text-success">{{ compliance.resolved }}</span></template>
              </VListItem>
            </VList>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard elevation="0" border>
      <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">
        <VIcon class="mr-2">tabler-chart-bar</VIcon> {{ t('executive_finance.spend_trend.title') }}
      </VCardTitle>
      <VDivider />
      <VCardText class="pa-4">
        <div class="d-flex align-end ga-4 overflow-x-auto pb-2" style="min-height:120px">
          <div v-for="month in spendTrend" :key="month.month" class="d-flex flex-column align-center" style="min-width:90px;flex:1">
            <div class="d-flex flex-column align-center justify-end" style="height:100px;gap:1px">
              <div v-if="month.loans > 0" :style="`height:${barH(month.loans, trendMax(spendTrend))}px;width:28px;border-radius:3px 3px 0 0`"
                class="bg-primary" :title="`Loans: R${month.loans.toLocaleString()}`" />
              <div v-if="month.advances > 0" :style="`height:${barH(month.advances, trendMax(spendTrend))}px;width:28px`"
                class="bg-info" :title="`Advances: R${month.advances.toLocaleString()}`" />
              <div v-if="month.petty_cash > 0" :style="`height:${barH(month.petty_cash, trendMax(spendTrend))}px;width:28px`"
                class="bg-teal" :title="`Petty Cash: R${month.petty_cash.toLocaleString()}`" />
              <div v-if="month.total === 0" style="height:4px;width:28px;border-radius:3px" class="bg-grey-lighten-2" />
            </div>
            <div class="text-caption text-medium-emphasis mt-1 text-center">{{ month.month }}</div>
            <div class="text-caption font-weight-semibold">{{ fmt(month.total) }}</div>
          </div>
        </div>
        <div class="d-flex ga-4 mt-3 flex-wrap">
          <div class="d-flex align-center ga-1"><div style="width:12px;height:12px;border-radius:2px" class="bg-primary" /><span class="text-caption">{{ t('executive_finance.spend_trend.staff_loans') }}</span></div>
          <div class="d-flex align-center ga-1"><div style="width:12px;height:12px;border-radius:2px" class="bg-info" /><span class="text-caption">{{ t('executive_finance.spend_trend.salary_advances') }}</span></div>
          <div class="d-flex align-center ga-1"><div style="width:12px;height:12px;border-radius:2px" class="bg-teal" /><span class="text-caption">{{ t('executive_finance.spend_trend.petty_cash') }}</span></div>
        </div>
      </VCardText>
    </VCard>
  </div>
</template>
