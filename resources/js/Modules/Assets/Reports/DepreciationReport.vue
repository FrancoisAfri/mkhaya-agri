<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Row = {
  asset_number: string
  name: string
  category: string
  cost: number
  accounting_depreciation_ytd: number
  accounting_book_value: number
  tax_depreciation_ytd: number
  tax_book_value: number
  difference: number
}

const props = defineProps<{
  rows: Row[]
  totals: { cost: number; accounting: number; tax: number; difference: number }
  year: number
}>()

function money(n: number | null) { return n != null ? `R ${Number(n).toLocaleString('en-ZA', { minimumFractionDigits: 2 })}` : '—' }
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center mb-4">
      <h1 class="text-h4 font-weight-bold">{{ t('assets_reports_depreciation.page_title') }} ({{ props.year }})</h1>
      <VSpacer />
      <VChip variant="tonal">{{ t('assets_reports_depreciation.ifrs_sars_dual') }}</VChip>
    </div>

    <VCard class="mb-4">
      <VCardItem>
        <VRow>
          <VCol cols="6" md="3"><div class="text-overline">{{ t('assets_reports_depreciation.totals.total_cost') }}</div><div class="text-h6">{{ money(props.totals.cost) }}</div></VCol>
          <VCol cols="6" md="3"><div class="text-overline">{{ t('assets_reports_depreciation.totals.accounting_ifrs') }}</div><div class="text-h6">{{ money(props.totals.accounting) }}</div></VCol>
          <VCol cols="6" md="3"><div class="text-overline">{{ t('assets_reports_depreciation.totals.tax_sars') }}</div><div class="text-h6">{{ money(props.totals.tax) }}</div></VCol>
          <VCol cols="6" md="3"><div class="text-overline">{{ t('assets_reports_depreciation.totals.difference') }}</div><div class="text-h6">{{ money(props.totals.difference) }}</div></VCol>
        </VRow>
      </VCardItem>
    </VCard>

    <VCard>
      <VCardItem>
        <VTable density="compact">
          <thead>
            <tr>
              <th>{{ t('assets_reports_depreciation.table.asset_no') }}</th>
              <th>{{ t('assets_reports_depreciation.table.name') }}</th>
              <th>{{ t('assets_reports_depreciation.table.category') }}</th>
              <th>{{ t('assets_reports_depreciation.table.cost') }}</th>
              <th>{{ t('assets_reports_depreciation.table.acc_ytd') }}</th>
              <th>{{ t('assets_reports_depreciation.table.acc_nbv') }}</th>
              <th>{{ t('assets_reports_depreciation.table.tax_ytd') }}</th>
              <th>{{ t('assets_reports_depreciation.table.tax_nbv') }}</th>
              <th>{{ t('assets_reports_depreciation.table.diff') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in props.rows" :key="r.asset_number">
              <td>{{ r.asset_number }}</td>
              <td>{{ r.name }}</td>
              <td>{{ r.category }}</td>
              <td>{{ money(r.cost) }}</td>
              <td>{{ money(r.accounting_depreciation_ytd) }}</td>
              <td>{{ money(r.accounting_book_value) }}</td>
              <td>{{ money(r.tax_depreciation_ytd) }}</td>
              <td>{{ money(r.tax_book_value) }}</td>
              <td>{{ money(r.difference) }}</td>
            </tr>
          </tbody>
        </VTable>
      </VCardItem>
    </VCard>
  </div>
</template>
