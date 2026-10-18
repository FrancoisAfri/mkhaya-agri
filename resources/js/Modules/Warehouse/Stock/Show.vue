<script setup lang="ts">
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import MovementTable from '../components/MovementTable.vue'

const { t } = useI18n()
const route = ziggyRoute

type Totals = Record<string, number>

defineProps<{
  item: { id: string; code: string; name: string; uom: string; status: string; is_valued: boolean; owner: string | null }
  availability: { totals: Totals; by_state: Record<string, number>; by_warehouse: Array<Totals & { warehouse: string | null }> }
  positions: Array<{ warehouse: string; bin: string | null; bin_kind: string | null; lot: string | null; expires_on: string | null; expired: boolean; status: string; qty: number }>
  reconciliation: Array<{ warehouse: string | null; balance: number; movements: number; difference: number }>
  movements: any
  states: Array<{ state: string; derived: boolean; physical: boolean; available: boolean; fulfilment: boolean; valuation: boolean }>
}>()

const figures = ['physical', 'available_on_hand', 'reserved', 'allocated_not_picked', 'available_to_promise', 'in_transit', 'held', 'expired', 'expected_receipts', 'backordered'] as const
const fmt = (n: number) => new Intl.NumberFormat(undefined, { maximumFractionDigits: 4 }).format(n)
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <div class="text-caption"><a :href="route('warehouse.stock.index')">{{ t('warehouse_management.stock.title') }}</a></div>
        <h4 class="text-h4">{{ item.code }} — {{ item.name }}</h4>
        <div class="text-body-2 text-medium-emphasis">
          <template v-if="item.owner">{{ t('warehouse_management.stock.owned_by', { owner: item.owner }) }}</template>
          <template v-else>{{ t('warehouse_management.stock.own_stock') }}</template>
        </div>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" prepend-icon="tabler-package" :href="route('warehouse.products.show', item.id)">{{ t('warehouse_management.stock.item_details') }}</VBtn>
        <VBtn variant="tonal" prepend-icon="tabler-adjustments" :href="route('warehouse.stock.adjustments.index')">{{ t('warehouse_management.cards.adjustments.title') }}</VBtn>
      </div>
    </div>

    <div class="d-grid mb-4" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px">
      <VCard v-for="f in figures" :key="f" border :color="f === 'available_to_promise' ? 'primary' : undefined" :variant="f === 'available_to_promise' ? 'tonal' : 'elevated'">
        <VCardText>
          <div class="text-caption">{{ t(`warehouse_management.stock.col.${f}`) }}</div>
          <div class="text-h6" style="font-variant-numeric: tabular-nums">{{ fmt(availability.totals[f] ?? 0) }} <span class="text-caption">{{ item.uom }}</span></div>
        </VCardText>
      </VCard>
    </div>
    <p class="text-caption text-medium-emphasis">{{ t('warehouse_management.stock.atp_rule') }}</p>

    <VRow>
      <VCol cols="12" lg="7">
        <VCard border class="mb-4">
          <VCardTitle>{{ t('warehouse_management.stock.where') }}</VCardTitle>
          <VCardText v-if="!positions.length" class="text-medium-emphasis">{{ t('warehouse_management.stock.nothing_held') }}</VCardText>
          <VTable v-else density="compact">
            <thead><tr><th>{{ t('warehouse_management.common.warehouse') }}</th><th>{{ t('warehouse_management.stock.bin') }}</th><th>{{ t('warehouse_management.stock.lot') }}</th><th>{{ t('warehouse_management.locations.status') }}</th><th class="text-end">{{ t('warehouse_management.locations.qty') }}</th></tr></thead>
            <tbody>
              <tr v-for="(p, i) in positions" :key="i">
                <td>{{ p.warehouse }}</td>
                <td>{{ p.bin ?? t('warehouse_management.stock.no_bin') }}<VChip v-if="p.bin_kind === 'IN_TRANSIT'" size="x-small" class="ms-1">{{ t('warehouse_management.kind.IN_TRANSIT') }}</VChip></td>
                <td>{{ p.lot ?? '—' }}<div v-if="p.expires_on" class="text-caption" :class="{ 'text-error': p.expired }">{{ t('warehouse_management.stock.expires', { date: p.expires_on }) }}</div></td>
                <td><VChip size="small" label variant="tonal" :color="p.status === 'AVAILABLE' && !p.expired ? 'success' : 'warning'">{{ t(`warehouse_management.stock_state.${p.status}`) }}</VChip></td>
                <td class="text-end" style="font-variant-numeric: tabular-nums">{{ fmt(p.qty) }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
      <VCol cols="12" lg="5">
        <VCard border class="mb-4">
          <VCardTitle>{{ t('warehouse_management.stock.by_warehouse') }}</VCardTitle>
          <VTable density="compact">
            <thead><tr><th>{{ t('warehouse_management.common.warehouse') }}</th><th class="text-end">{{ t('warehouse_management.stock.col.physical') }}</th><th class="text-end">{{ t('warehouse_management.stock.col.available_to_promise') }}</th></tr></thead>
            <tbody><tr v-for="(w, i) in availability.by_warehouse" :key="i"><td>{{ w.warehouse }}</td><td class="text-end">{{ fmt(w.physical) }}</td><td class="text-end font-weight-bold">{{ fmt(w.available_to_promise) }}</td></tr></tbody>
          </VTable>
        </VCard>
        <VCard border>
          <VCardTitle>{{ t('warehouse_management.stock.reconcile') }}</VCardTitle>
          <VCardText class="text-caption text-medium-emphasis">{{ t('warehouse_management.stock.reconcile_hint') }}</VCardText>
          <VTable density="compact">
            <tbody>
              <tr v-for="(r, i) in reconciliation" :key="i">
                <td>{{ r.warehouse ?? t('warehouse_management.stock.no_warehouse') }}</td>
                <td class="text-end">{{ fmt(r.balance) }}</td>
                <td class="text-end">{{ fmt(r.movements) }}</td>
                <td><VChip size="x-small" :color="Math.abs(r.difference) < 0.0001 ? 'success' : 'error'" variant="tonal">
                  {{ Math.abs(r.difference) < 0.0001 ? t('warehouse_management.stock.agrees') : t('warehouse_management.stock.differs', { diff: fmt(r.difference) }) }}
                </VChip></td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>

    <VCard border>
      <VCardTitle class="d-flex justify-space-between align-center">
        {{ t('warehouse_management.cards.movements.title') }}
        <VBtn size="small" variant="text" :href="route('warehouse.stock.movements', { item: item.id })">{{ t('warehouse_management.common.all') }}</VBtn>
      </VCardTitle>
      <VCardText><MovementTable :movements="movements" /></VCardText>
    </VCard>
  </div>
</template>
