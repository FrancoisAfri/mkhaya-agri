<script lang="ts" setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const { t } = useI18n()

interface Row { account_id?: number; code: string; name: string; type: string; debit: number; credit: number; balance: number; debit_col?: number; credit_col?: number }
interface CfLine { label: string; amount: number }
const props = defineProps<{
  as_of: string
  from: string
  trialBalance: { rows: Row[]; total_debit: number; total_credit: number; balanced: boolean }
  incomeStatement: { income: Row[]; expenses: Row[]; income_total: number; expense_total: number; net_profit: number }
  balanceSheet: { assets: Row[]; liabilities: Row[]; equity: Row[]; asset_total: number; liability_total: number; equity_total: number; current_earnings: number; equity_and_earnings: number; liab_equity_total: number; balanced: boolean }
  cashFlow: { from: string; to: string; operating: CfLine[]; operating_total: number; investing: CfLine[]; investing_total: number; financing: CfLine[]; financing_total: number; net_change: number; opening_cash: number; closing_cash: number; reconciles: boolean }
}>()

const tab = ref('tb')
const asOf = ref(props.as_of)
const from = ref(props.from)
function reload() { router.get(route('finance.accounting.reports'), { as_of: asOf.value, from: from.value }, { preserveState: true, preserveScroll: true }) }
function drill(r: Row) { if (r.account_id) router.get(route('finance.accounting.ledger'), { account_id: r.account_id, to: asOf.value }) }
const money = (n: number) => (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('acc.gl_crumb') }}</div>
        <h2 class="text-h5 mb-0">{{ t('acc.financial_reports') }}</h2>
      </div>
      <div class="d-flex gap-3">
        <VTextField v-if="tab === 'cf'" v-model="from" type="date" :label="t('acc.from')" density="compact" hide-details style="max-width: 180px" @update:model-value="reload" />
        <VTextField v-model="asOf" type="date" :label="tab === 'cf' ? t('acc.to') : t('acc.as_at')" density="compact" hide-details style="max-width: 180px" @update:model-value="reload" />
      </div>
    </div>

    <VTabs v-model="tab" class="mb-4">
      <VTab value="tb">{{ t('acc.trial_balance') }}</VTab>
      <VTab value="is">{{ t('acc.income_statement') }}</VTab>
      <VTab value="bs">{{ t('acc.balance_sheet') }}</VTab>
      <VTab value="cf">{{ t('acc.cash_flow') }}</VTab>
    </VTabs>

    <!-- Trial Balance -->
    <VCard v-if="tab === 'tb'" border>
      <VTable>
        <thead><tr><th>{{ t('acc.code') }}</th><th>{{ t('acc.account') }}</th><th class="text-right">{{ t('acc.debit') }}</th><th class="text-right">{{ t('acc.credit') }}</th></tr></thead>
        <tbody>
          <tr v-for="r in trialBalance.rows" :key="r.code" style="cursor: pointer" @click="drill(r)">
            <td class="font-weight-medium text-primary">{{ r.code }}</td>
            <td class="text-primary">{{ r.name }}</td>
            <td class="text-right">{{ r.debit_col ? money(r.debit_col) : '' }}</td>
            <td class="text-right">{{ r.credit_col ? money(r.credit_col) : '' }}</td>
          </tr>
        </tbody>
        <tfoot>
          <tr class="font-weight-bold"><td colspan="2" class="text-right">{{ t('acc.totals') }}</td><td class="text-right">{{ money(trialBalance.total_debit) }}</td><td class="text-right">{{ money(trialBalance.total_credit) }}</td></tr>
        </tfoot>
      </VTable>
      <VCardText><VChip :color="trialBalance.balanced ? 'success' : 'error'" variant="tonal" size="small">{{ trialBalance.balanced ? t('acc.balanced') : t('acc.out_of_balance_r') }}</VChip></VCardText>
    </VCard>

    <!-- Income Statement -->
    <VCard v-else-if="tab === 'is'" border>
      <VTable>
        <tbody>
          <tr class="text-overline"><td colspan="2">{{ t('acc.income') }}</td></tr>
          <tr v-for="r in incomeStatement.income" :key="r.code"><td class="ps-6">{{ r.code }} · {{ r.name }}</td><td class="text-right">{{ money(r.balance) }}</td></tr>
          <tr class="font-weight-medium"><td class="ps-4">{{ t('acc.total_income') }}</td><td class="text-right">{{ money(incomeStatement.income_total) }}</td></tr>
          <tr class="text-overline"><td colspan="2" class="pt-4">{{ t('acc.expenses_word') }}</td></tr>
          <tr v-for="r in incomeStatement.expenses" :key="r.code"><td class="ps-6">{{ r.code }} · {{ r.name }}</td><td class="text-right">{{ money(r.balance) }}</td></tr>
          <tr class="font-weight-medium"><td class="ps-4">{{ t('acc.total_expenses') }}</td><td class="text-right">{{ money(incomeStatement.expense_total) }}</td></tr>
          <tr class="font-weight-bold text-h6"><td>{{ t('acc.net_profit_loss') }}</td><td class="text-right" :class="incomeStatement.net_profit >= 0 ? 'text-success' : 'text-error'">{{ money(incomeStatement.net_profit) }}</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <!-- Cash Flow (indirect) -->
    <VCard v-else-if="tab === 'cf'" border>
      <VTable>
        <tbody>
          <tr class="text-overline"><td colspan="2">{{ t('acc.operating_activities') }}</td></tr>
          <tr v-for="(l, i) in cashFlow.operating" :key="'op' + i"><td class="ps-6">{{ l.label }}</td><td class="text-right">{{ money(l.amount) }}</td></tr>
          <tr class="font-weight-medium"><td class="ps-4">{{ t('acc.net_cash_operating') }}</td><td class="text-right">{{ money(cashFlow.operating_total) }}</td></tr>

          <tr class="text-overline"><td colspan="2" class="pt-4">{{ t('acc.investing_activities') }}</td></tr>
          <tr v-for="(l, i) in cashFlow.investing" :key="'inv' + i"><td class="ps-6">{{ l.label }}</td><td class="text-right">{{ money(l.amount) }}</td></tr>
          <tr v-if="!cashFlow.investing.length"><td class="ps-6 text-medium-emphasis">—</td><td></td></tr>
          <tr class="font-weight-medium"><td class="ps-4">{{ t('acc.net_cash_investing') }}</td><td class="text-right">{{ money(cashFlow.investing_total) }}</td></tr>

          <tr class="text-overline"><td colspan="2" class="pt-4">{{ t('acc.financing_activities') }}</td></tr>
          <tr v-for="(l, i) in cashFlow.financing" :key="'fin' + i"><td class="ps-6">{{ l.label }}</td><td class="text-right">{{ money(l.amount) }}</td></tr>
          <tr v-if="!cashFlow.financing.length"><td class="ps-6 text-medium-emphasis">—</td><td></td></tr>
          <tr class="font-weight-medium"><td class="ps-4">{{ t('acc.net_cash_financing') }}</td><td class="text-right">{{ money(cashFlow.financing_total) }}</td></tr>

          <tr class="font-weight-bold text-h6"><td>{{ t('acc.net_change_cash') }}</td><td class="text-right">{{ money(cashFlow.net_change) }}</td></tr>
          <tr><td class="ps-4">{{ t('acc.opening_cash') }}</td><td class="text-right">{{ money(cashFlow.opening_cash) }}</td></tr>
          <tr class="font-weight-bold"><td class="ps-4">{{ t('acc.closing_cash') }}</td><td class="text-right">{{ money(cashFlow.closing_cash) }}</td></tr>
        </tbody>
      </VTable>
      <VCardText>
        <VChip :color="cashFlow.reconciles ? 'success' : 'error'" variant="tonal" size="small">{{ cashFlow.reconciles ? t('acc.reconciles_cash') : t('acc.not_reconcile') }}</VChip>
        <span class="text-caption text-medium-emphasis ms-3">{{ t('acc.indirect_method') }} · {{ cashFlow.from }} → {{ cashFlow.to }}</span>
      </VCardText>
    </VCard>

    <!-- Balance Sheet -->
    <VCard v-else border>
      <VTable>
        <tbody>
          <tr class="text-overline"><td colspan="2">{{ t('acc.assets_word') }}</td></tr>
          <tr v-for="r in balanceSheet.assets" :key="r.code"><td class="ps-6">{{ r.code }} · {{ r.name }}</td><td class="text-right">{{ money(r.balance) }}</td></tr>
          <tr class="font-weight-bold"><td class="ps-4">{{ t('acc.total_assets') }}</td><td class="text-right">{{ money(balanceSheet.asset_total) }}</td></tr>

          <tr class="text-overline"><td colspan="2" class="pt-4">{{ t('acc.liabilities_word') }}</td></tr>
          <tr v-for="r in balanceSheet.liabilities" :key="r.code"><td class="ps-6">{{ r.code }} · {{ r.name }}</td><td class="text-right">{{ money(r.balance) }}</td></tr>
          <tr class="font-weight-medium"><td class="ps-4">{{ t('acc.total_liabilities') }}</td><td class="text-right">{{ money(balanceSheet.liability_total) }}</td></tr>

          <tr class="text-overline"><td colspan="2" class="pt-4">{{ t('acc.equity_word') }}</td></tr>
          <tr v-for="r in balanceSheet.equity" :key="r.code"><td class="ps-6">{{ r.code }} · {{ r.name }}</td><td class="text-right">{{ money(r.balance) }}</td></tr>
          <tr><td class="ps-6">{{ t('acc.current_year_earnings') }}</td><td class="text-right">{{ money(balanceSheet.current_earnings) }}</td></tr>
          <tr class="font-weight-medium"><td class="ps-4">{{ t('acc.total_equity') }}</td><td class="text-right">{{ money(balanceSheet.equity_and_earnings) }}</td></tr>

          <tr class="font-weight-bold"><td>{{ t('acc.liab_plus_equity') }}</td><td class="text-right">{{ money(balanceSheet.liab_equity_total) }}</td></tr>
        </tbody>
      </VTable>
      <VCardText><VChip :color="balanceSheet.balanced ? 'success' : 'error'" variant="tonal" size="small">{{ balanceSheet.balanced ? t('acc.balanced_ale') : t('acc.out_of_balance_r') }}</VChip></VCardText>
    </VCard>
  </div>
</template>
