<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Row = {
  asset_number: string
  name: string
  category: string
  location: string
  status: string
  condition: string
  purchase_date: string | null
  purchase_cost: number | null
  book_value: number | null
}

const props = defineProps<{
  rows: Row[]
  totals: { count: number; cost: number; book_value: number }
  byCategory: { name: string; count: number; cost: number }[]
}>()

function money(n: number | null) { return n != null ? `R ${Number(n).toLocaleString('en-ZA', { minimumFractionDigits: 2 })}` : '—' }
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center mb-4">
      <h1 class="text-h4 font-weight-bold">{{ t('assets_reports_register.page_title') }}</h1>
      <VSpacer />
      <VBtn variant="text" prepend-icon="tabler-file-download">{{ t('assets_reports_register.export_csv') }}</VBtn>
      <VBtn variant="text" prepend-icon="tabler-file-type-pdf">{{ t('assets_reports_register.export_pdf') }}</VBtn>
    </div>

    <VCard class="mb-4">
      <VCardItem>
        <VRow>
          <VCol cols="12" md="4"><div class="text-overline">{{ t('assets_reports_register.totals.total_assets') }}</div><div class="text-h4">{{ props.totals.count }}</div></VCol>
          <VCol cols="12" md="4"><div class="text-overline">{{ t('assets_reports_register.totals.total_cost') }}</div><div class="text-h4">{{ money(props.totals.cost) }}</div></VCol>
          <VCol cols="12" md="4"><div class="text-overline">{{ t('assets_reports_register.totals.total_book_value') }}</div><div class="text-h4">{{ money(props.totals.book_value) }}</div></VCol>
        </VRow>
      </VCardItem>
    </VCard>

    <VCard class="mb-4">
      <VCardItem>
        <VCardTitle>{{ t('assets_reports_register.by_category.title') }}</VCardTitle>
        <VTable density="compact">
          <thead><tr><th>{{ t('assets_reports_register.by_category.category') }}</th><th>{{ t('assets_reports_register.by_category.count') }}</th><th>{{ t('assets_reports_register.by_category.total_cost') }}</th></tr></thead>
          <tbody>
            <tr v-for="c in props.byCategory" :key="c.name">
              <td>{{ c.name }}</td><td>{{ c.count }}</td><td>{{ money(c.cost) }}</td>
            </tr>
          </tbody>
        </VTable>
      </VCardItem>
    </VCard>

    <VCard>
      <VCardItem>
        <VCardTitle>{{ t('assets_reports_register.all_assets.title') }}</VCardTitle>
        <VTable density="compact">
          <thead><tr><th>{{ t('assets_reports_register.all_assets.asset_no') }}</th><th>{{ t('assets_reports_register.all_assets.name') }}</th><th>{{ t('assets_reports_register.all_assets.category') }}</th><th>{{ t('assets_reports_register.all_assets.location') }}</th><th>{{ t('assets_reports_register.all_assets.status') }}</th><th>{{ t('assets_reports_register.all_assets.condition') }}</th><th>{{ t('assets_reports_register.all_assets.purchased') }}</th><th>{{ t('assets_reports_register.all_assets.cost') }}</th><th>{{ t('assets_reports_register.all_assets.book_value') }}</th></tr></thead>
          <tbody>
            <tr v-for="r in props.rows" :key="r.asset_number">
              <td>{{ r.asset_number }}</td><td>{{ r.name }}</td><td>{{ r.category }}</td>
              <td>{{ r.location }}</td><td>{{ r.status }}</td><td>{{ r.condition }}</td>
              <td>{{ r.purchase_date ?? '—' }}</td><td>{{ money(r.purchase_cost) }}</td><td>{{ money(r.book_value) }}</td>
            </tr>
          </tbody>
        </VTable>
      </VCardItem>
    </VCard>
  </div>
</template>
