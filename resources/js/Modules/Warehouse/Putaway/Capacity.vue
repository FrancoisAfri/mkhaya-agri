<script setup lang="ts">
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

defineProps<{
  warehouses: Array<Record<string, any>>
  restricted_items: Array<{ uuid: string; code: string; name: string; weight_kg: number | null; hazard_class: string | null; temperature_min_c: number | null; temperature_max_c: number | null; eligible_bins: number }>
}>()
const pct = (v: number | null) => v === null ? '—' : `${Math.round(v * 100)}%`
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('warehouse_management.capacity_page.title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.capacity_page.subtitle') }}</p>
    </div>
    <VCard border class="mb-4">
      <VTable>
        <thead><tr><th>{{ t('warehouse_management.common.warehouse') }}</th><th class="text-end">{{ t('warehouse_management.dashboard.bins') }}</th><th class="text-end">{{ t('warehouse_management.capacity_page.occupied') }}</th>
          <th style="min-width: 160px">{{ t('warehouse_management.locations.occupancy') }}</th><th class="text-end">{{ t('warehouse_management.locations.totals.bins_near_full') }}</th><th class="text-end">{{ t('warehouse_management.capacity_page.over') }}</th><th class="text-end">{{ t('warehouse_management.capacity.PALLETS') }}</th></tr></thead>
        <tbody>
          <tr v-for="w in warehouses" :key="w.code">
            <td><a :href="route('warehouse.layout.locations.index')">{{ w.code }}</a> — {{ w.name }}</td>
            <td class="text-end">{{ w.bins }}</td>
            <td class="text-end">{{ w.occupied }}</td>
            <td><VProgressLinear v-if="w.occupancy !== null" :model-value="Math.min(100, w.occupancy * 100)" height="8" rounded :color="w.occupancy > 0.9 ? 'error' : w.occupancy > 0.75 ? 'warning' : 'primary'" />
              <span class="text-caption">{{ pct(w.occupancy) }}</span></td>
            <td class="text-end" :class="{ 'text-warning': w.near_full > 0 }">{{ w.near_full }}</td>
            <td class="text-end" :class="{ 'text-error': w.over > 0 }">{{ w.over }}</td>
            <td class="text-end">{{ w.pallet_positions }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
    <VCard border>
      <VCardTitle>{{ t('warehouse_management.capacity_page.restricted') }}</VCardTitle>
      <VCardText class="text-body-2 text-medium-emphasis">{{ t('warehouse_management.capacity_page.restricted_hint') }}</VCardText>
      <VCardText v-if="!restricted_items.length" class="text-medium-emphasis">{{ t('warehouse_management.capacity_page.no_restricted') }}</VCardText>
      <VTable v-else density="compact">
        <tbody>
          <tr v-for="i in restricted_items" :key="i.uuid">
            <td><a :href="route('warehouse.products.show', i.uuid)">{{ i.code }}</a> — {{ i.name }}</td>
            <td class="text-caption">
              <template v-if="i.weight_kg">{{ i.weight_kg }} kg </template>
              <template v-if="i.hazard_class">· {{ t('warehouse_management.products.hazard_class') }} {{ i.hazard_class }} </template>
              <template v-if="i.temperature_max_c !== null">· {{ i.temperature_min_c }}–{{ i.temperature_max_c }} °C</template>
            </td>
            <td class="text-end"><VChip size="small" :color="i.eligible_bins === 0 ? 'error' : i.eligible_bins < 5 ? 'warning' : 'success'" variant="tonal">{{ t('warehouse_management.capacity_page.eligible', { n: i.eligible_bins }) }}</VChip></td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
