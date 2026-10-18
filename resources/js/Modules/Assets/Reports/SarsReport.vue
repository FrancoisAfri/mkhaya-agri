<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Row = {
  asset_number: string
  name: string
  category: string
  sars_section: string
  rate_percent: number
  cost: number
  claimable_amount: number
}

const props = defineProps<{
  rows: Row[]
  totals: { cost: number; claimable: number }
  year: number
}>()

function money(n: number | null) { return n != null ? `R ${Number(n).toLocaleString('en-ZA', { minimumFractionDigits: 2 })}` : '—' }
</script>

<template>
  <div class="pa-4">
    <h1 class="text-h4 font-weight-bold mb-4">{{ t('assets_reports_sars_wear_tear.page_title') }} ({{ props.year }})</h1>

    <VCard class="mb-4">
      <VCardItem>
        <VRow>
          <VCol cols="12" md="6">
            <div class="text-overline">{{ t('assets_reports_sars_wear_tear.totals.total_cost_base') }}</div>
            <div class="text-h5">{{ money(props.totals.cost) }}</div>
          </VCol>
          <VCol cols="12" md="6">
            <div class="text-overline">{{ t('assets_reports_sars_wear_tear.totals.total_claimable') }}</div>
            <div class="text-h5">{{ money(props.totals.claimable) }}</div>
          </VCol>
        </VRow>
      </VCardItem>
    </VCard>

    <VCard>
      <VCardItem>
        <VTable density="compact">
          <thead>
            <tr>
              <th>{{ t('assets_reports_sars_wear_tear.table.asset_no') }}</th>
              <th>{{ t('assets_reports_sars_wear_tear.table.name') }}</th>
              <th>{{ t('assets_reports_sars_wear_tear.table.category') }}</th>
              <th>{{ t('assets_reports_sars_wear_tear.table.sars_section') }}</th>
              <th>{{ t('assets_reports_sars_wear_tear.table.rate') }}</th>
              <th>{{ t('assets_reports_sars_wear_tear.table.cost') }}</th>
              <th>{{ t('assets_reports_sars_wear_tear.table.claimable') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in props.rows" :key="r.asset_number">
              <td>{{ r.asset_number }}</td>
              <td>{{ r.name }}</td>
              <td>{{ r.category }}</td>
              <td>{{ r.sars_section }}</td>
              <td>{{ r.rate_percent }}%</td>
              <td>{{ money(r.cost) }}</td>
              <td>{{ money(r.claimable_amount) }}</td>
            </tr>
          </tbody>
        </VTable>
      </VCardItem>
    </VCard>
  </div>
</template>
