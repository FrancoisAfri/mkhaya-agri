<script setup lang="ts">
import { reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Row = { lot_id: number; lot: string; item: string; name: string; uom: string; warehouse: string; expires_on: string; days_left: number; qty: number; daily_demand: number; demand_before_expiry: number; at_risk_qty: number; unit_cost: number; exposure: number; thresholds: number[]; next_threshold: number | null; demand_known: boolean }
const props = defineProps<{ rows: Row[]; totals: { lots: number; qty: number; at_risk_qty: number; exposure: number; expired: number }; filters: { warehouse: string | null; within: number }; warehouses: Array<{ id: string; title: string }> }>()
const f = reactive({ ...props.filters })
const apply = () => router.get(route('warehouse.batches.expiry'), f, { preserveState: true })
const money = (v: number) => v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const band = (d: number) => d < 0 ? 'error' : d <= 7 ? 'error' : d <= 30 ? 'warning' : d <= 90 ? 'info' : 'secondary'
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <div class="text-caption"><a :href="route('warehouse.batches.index')">{{ t('warehouse_management.batches.title') }}</a></div>
      <h4 class="text-h4">{{ t('warehouse_management.expiry.title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.expiry.subtitle') }}</p>
    </div>
    <div class="d-flex gap-2 mb-4 flex-wrap align-center">
      <VSelect v-model="f.warehouse" :items="warehouses" item-value="id" clearable density="compact" hide-details style="max-width: 260px" :label="t('warehouse_management.common.warehouse')" />
      <VSelect v-model="f.within" :items="[30, 60, 90, 120, 180, 365].map(n => ({ value: n, title: t('warehouse_management.batches.within', { n }) }))" density="compact" hide-details style="max-width: 200px" :label="t('warehouse_management.batches.expiring')" />
      <VBtn color="primary" @click="apply">{{ t('warehouse_management.accuracy.apply') }}</VBtn>
    </div>
    <div class="d-flex gap-3 mb-4 flex-wrap">
      <VCard border class="pa-3" min-width="160"><div class="text-caption">{{ t('warehouse_management.expiry.lots') }}</div><div class="text-h5">{{ totals.lots }}</div></VCard>
      <VCard border class="pa-3" min-width="160"><div class="text-caption">{{ t('warehouse_management.expiry.expired') }}</div><div class="text-h5 text-error">{{ totals.expired }}</div></VCard>
      <VCard border class="pa-3" min-width="160"><div class="text-caption">{{ t('warehouse_management.expiry.at_risk') }}</div><div class="text-h5">{{ totals.at_risk_qty }}</div></VCard>
      <VCard border class="pa-3" min-width="160"><div class="text-caption">{{ t('warehouse_management.expiry.exposure') }}</div><div class="text-h5 text-warning">{{ money(totals.exposure) }}</div></VCard>
    </div>
    <VCard border>
      <VCardText v-if="!rows.length" class="text-center text-medium-emphasis py-10">{{ t('warehouse_management.expiry.none') }}</VCardText>
      <div v-else class="overflow-x-auto">
        <VTable density="comfortable">
          <thead><tr><th>{{ t('warehouse_management.counts.item') }}</th><th>{{ t('warehouse_management.batches.lot') }}</th><th>{{ t('warehouse_management.batches.expires') }}</th>
            <th class="text-end">{{ t('warehouse_management.batches.on_hand') }}</th><th class="text-end">{{ t('warehouse_management.expiry.will_use') }}</th>
            <th class="text-end">{{ t('warehouse_management.expiry.at_risk') }}</th><th class="text-end">{{ t('warehouse_management.expiry.exposure') }}</th></tr></thead>
          <tbody>
            <tr v-for="r in rows" :key="`${r.lot_id}-${r.warehouse}`">
              <td>{{ r.item }} <span class="text-medium-emphasis">{{ r.name }}</span><div class="text-caption">{{ r.warehouse }}</div></td>
              <td>{{ r.lot }}</td>
              <td><VChip size="small" :color="band(r.days_left)" variant="tonal">{{ r.days_left < 0 ? t('warehouse_management.batches.expired_ago', { n: -r.days_left }) : t('warehouse_management.batches.days_left', { n: r.days_left }) }}</VChip>
                <div class="text-caption">{{ r.expires_on }}</div></td>
              <td class="text-end">{{ r.qty }} {{ r.uom }}</td>
              <td class="text-end">{{ r.demand_known ? r.demand_before_expiry : t('warehouse_management.expiry.no_history') }}</td>
              <td class="text-end">{{ r.at_risk_qty }}</td>
              <td class="text-end">{{ money(r.exposure) }}</td>
            </tr>
          </tbody>
        </VTable>
      </div>
    </VCard>
    <p class="text-caption text-medium-emphasis mt-2">{{ t('warehouse_management.expiry.method') }}</p>
  </div>
</template>
