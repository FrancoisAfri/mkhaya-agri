<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import ResellerNav from './ResellerNav.vue'
const { t } = useI18n()

interface Commission { period: string; client: string | null; base_amount: number; commission_amount: number; status: string; paid_at: string | null }
defineProps<{ commissions: Commission[]; totals: { pending: number; paid: number } }>()

const money = (n: number) => 'R ' + (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 0, maximumFractionDigits: 2 })
const headers = [
  { title: t('reseller_portal.period'), key: 'period' },
  { title: t('reseller_portal.client'), key: 'client' },
  { title: t('reseller_portal.subscription'), key: 'base_amount', align: 'end' as const },
  { title: t('reseller_portal.commission'), key: 'commission_amount', align: 'end' as const },
  { title: t('reseller_portal.status'), key: 'status' },
  { title: t('reseller_portal.paid_on'), key: 'paid_at' },
]
</script>

<template>
  <div class="pa-4">
    <ResellerNav />
    <div class="mb-4">
      <h1 class="text-h5 font-weight-bold mb-0">{{ t('reseller_portal.my_commission') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('reseller_portal.commission_subtitle') }}</p>
    </div>

    <VRow class="mb-1">
      <VCol cols="6"><VCard elevation="0" border rounded="lg"><VCardText class="text-center py-4"><div class="text-caption text-medium-emphasis">{{ t('reseller_portal.pending') }}</div><div class="text-h5 font-weight-bold text-warning">{{ money(totals.pending) }}</div></VCardText></VCard></VCol>
      <VCol cols="6"><VCard elevation="0" border rounded="lg"><VCardText class="text-center py-4"><div class="text-caption text-medium-emphasis">{{ t('reseller_portal.paid') }}</div><div class="text-h5 font-weight-bold text-success">{{ money(totals.paid) }}</div></VCardText></VCard></VCol>
    </VRow>

    <VCard elevation="0" border rounded="lg">
      <VDataTable :headers="headers" :items="commissions" :items-per-page="25" density="comfortable">
        <template #item.base_amount="{ item }">{{ money(item.base_amount) }}</template>
        <template #item.commission_amount="{ item }"><span class="font-weight-medium">{{ money(item.commission_amount) }}</span></template>
        <template #item.status="{ item }"><VChip size="x-small" :color="item.status === 'paid' ? 'success' : item.status === 'void' ? 'default' : 'warning'" variant="tonal">{{ item.status }}</VChip></template>
        <template #no-data><div class="text-center py-8 text-medium-emphasis">{{ t('reseller_portal.no_commission') }}</div></template>
      </VDataTable>
    </VCard>
  </div>
</template>
