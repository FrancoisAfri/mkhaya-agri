<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ResellerNav from './ResellerNav.vue'
const route = ziggyRoute
const { t } = useI18n()

interface Recent { period: string; client: string | null; amount: number; status: string }
defineProps<{
  reseller: { name: string; commission_type: string; commission_value: number }
  stats: { clients: number; active_clients: number; pending: number | null; paid: number | null }
  recent: Recent[]
}>()
const money = (n: number) => 'R ' + (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 0, maximumFractionDigits: 2 })
</script>

<template>
  <div class="pa-4">
    <ResellerNav />
    <div class="mb-4">
      <h1 class="text-h5 font-weight-bold mb-0">{{ t('reseller_portal.welcome', { name: reseller.name }) }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">
        {{ t('reseller_portal.your_rate') }}:
        <strong>{{ reseller.commission_type === 'percent' ? reseller.commission_value + '%' : money(reseller.commission_value) }}</strong>
        {{ t('reseller_portal.per_client_month') }}
      </p>
    </div>

    <VRow>
      <VCol cols="6" md="3"><VCard elevation="0" border rounded="lg"><VCardText class="text-center py-4"><div class="text-caption text-medium-emphasis">{{ t('reseller_portal.clients') }}</div><div class="text-h4 font-weight-bold">{{ stats.clients }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard elevation="0" border rounded="lg"><VCardText class="text-center py-4"><div class="text-caption text-medium-emphasis">{{ t('reseller_portal.active') }}</div><div class="text-h4 font-weight-bold text-success">{{ stats.active_clients }}</div></VCardText></VCard></VCol>
      <VCol v-if="stats.pending !== null" cols="6" md="3"><VCard elevation="0" border rounded="lg"><VCardText class="text-center py-4"><div class="text-caption text-medium-emphasis">{{ t('reseller_portal.pending') }}</div><div class="text-h5 font-weight-bold text-warning">{{ money(stats.pending) }}</div></VCardText></VCard></VCol>
      <VCol v-if="stats.paid !== null" cols="6" md="3"><VCard elevation="0" border rounded="lg"><VCardText class="text-center py-4"><div class="text-caption text-medium-emphasis">{{ t('reseller_portal.paid') }}</div><div class="text-h5 font-weight-bold text-success">{{ money(stats.paid) }}</div></VCardText></VCard></VCol>
    </VRow>

    <VCard v-if="stats.pending !== null" elevation="0" border rounded="lg" class="mt-4">
      <VCardTitle class="text-subtitle-1 font-weight-semibold d-flex align-center justify-space-between">
        <span>{{ t('reseller_portal.recent_commission') }}</span>
        <Link :href="route('reseller.commissions')" class="text-caption text-primary text-decoration-none">{{ t('reseller_portal.view_all') }}</Link>
      </VCardTitle>
      <VTable density="comfortable">
        <thead><tr><th>{{ t('reseller_portal.period') }}</th><th>{{ t('reseller_portal.client') }}</th><th class="text-right">{{ t('reseller_portal.commission') }}</th><th>{{ t('reseller_portal.status') }}</th></tr></thead>
        <tbody>
          <tr v-for="(r, i) in recent" :key="i">
            <td>{{ r.period }}</td><td>{{ r.client }}</td><td class="text-right">{{ money(r.amount) }}</td>
            <td><VChip size="x-small" :color="r.status === 'paid' ? 'success' : 'warning'" variant="tonal">{{ r.status }}</VChip></td>
          </tr>
          <tr v-if="!recent.length"><td colspan="4" class="text-center text-medium-emphasis py-6">{{ t('reseller_portal.no_commission') }}</td></tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
