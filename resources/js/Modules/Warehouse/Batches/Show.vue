<script setup lang="ts">
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

defineProps<{
  lot: { id: string; number: string; kind: string; item: string; item_name: string; item_id: string; uom: string; origin: string | null; supplier: string | null; supplier_lot: string | null; manufactured_on: string | null; expires_on: string | null; expired: boolean; source: string | null; notes: string | null; received_on: string | null; on_hand: number; serial_state: string | null }
  positions: Array<{ warehouse: string; bin: string | null; bin_kind: string | null; status: string; qty: number }>
  history: Array<{ id: string; type: string; qty: number; date: string; at: string; reference: string | null; reason_code: string | null; from_status: string | null; to_status: string | null; source: string | null; warehouse: string | null; bin: string | null; to_bin: string | null; by: string | null }>
  inspections: Array<{ number: string; stage: string; result: string; disposition: string | null; created_at: string }>
  recalls: Array<{ uuid: string; number: string; title: string; status: string }>
  shipments: Array<{ shipment: string; dispatched_at: string | null; order: string; customer: string | null; qty: number }>
  genealogy: { made_from: Array<{ lot: string; number: string; item: string; depth: number; in_stock: number }>; went_into: Array<{ lot: string; number: string; item: string; depth: number; in_stock: number }> }
}>()
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <div class="text-caption"><a :href="route('warehouse.batches.index')">{{ t('warehouse_management.batches.title') }}</a></div>
      <h4 class="text-h4">{{ lot.number }}
        <VChip size="small" variant="tonal">{{ t(`warehouse_management.batches.kind_${lot.kind}`) }}</VChip>
        <VChip v-if="lot.expired" size="small" color="error" class="ms-1">{{ t('warehouse_management.batches.expired') }}</VChip>
        <VChip v-if="lot.serial_state" size="small" color="primary" variant="outlined" class="ms-1">{{ t(`warehouse_management.serial_state.${lot.serial_state}`) }}</VChip></h4>
      <p class="text-body-2 mb-0"><a :href="route('warehouse.stock.show', lot.item_id)">{{ lot.item }}</a> {{ lot.item_name }}</p>
    </div>
    <VRow>
      <VCol cols="12" md="4">
        <VCard border :title="t('warehouse_management.batches.facts')">
          <VList density="compact">
            <VListItem :title="t('warehouse_management.batches.on_hand')" :subtitle="`${lot.on_hand} ${lot.uom}`" />
            <VListItem :title="t('warehouse_management.batches.supplier')" :subtitle="(lot.supplier ?? '—') + (lot.supplier_lot ? ` · ${lot.supplier_lot}` : '')" />
            <VListItem :title="t('warehouse_management.batches.origin')" :subtitle="lot.origin ?? lot.source ?? '—'" />
            <VListItem :title="t('warehouse_management.batches.received')" :subtitle="lot.received_on ?? '—'" />
            <VListItem :title="t('warehouse_management.batches.made')" :subtitle="lot.manufactured_on ?? '—'" />
            <VListItem :title="t('warehouse_management.batches.expires')" :subtitle="lot.expires_on ?? '—'" />
          </VList>
        </VCard>
        <VCard v-if="recalls.length" border class="mt-4" :title="t('warehouse_management.cards.recalls.title')">
          <VList density="compact"><VListItem v-for="r in recalls" :key="r.uuid" :href="route('warehouse.recalls.show', r.uuid)" :title="`${r.number} · ${r.title}`" :subtitle="t(`warehouse_management.recall_status.${r.status}`)" /></VList>
        </VCard>
      </VCol>
      <VCol cols="12" md="8">
        <VCard border class="mb-4" :title="t('warehouse_management.recalls.where')">
          <VCardText v-if="!positions.length" class="text-medium-emphasis">{{ t('warehouse_management.recalls.nothing_here') }}</VCardText>
          <VTable v-else density="compact"><tbody>
            <tr v-for="(p, i) in positions" :key="i"><td>{{ p.warehouse }} {{ p.bin ?? '' }}</td><td>{{ t(`warehouse_management.stock_state.${p.status}`) }}</td><td class="text-end">{{ p.qty }} {{ lot.uom }}</td></tr>
          </tbody></VTable>
        </VCard>
        <VCard v-if="shipments.length" border class="mb-4" :title="t('warehouse_management.recalls.customers_title')">
          <VTable density="compact"><tbody>
            <tr v-for="s in shipments" :key="s.shipment"><td>{{ s.customer ?? '—' }}</td><td>{{ s.order }} · {{ s.shipment }}</td><td>{{ s.dispatched_at ? new Date(s.dispatched_at).toLocaleDateString() : '—' }}</td><td class="text-end">{{ s.qty }}</td></tr>
          </tbody></VTable>
        </VCard>
        <VCard v-if="genealogy.made_from.length || genealogy.went_into.length" border class="mb-4" :title="t('warehouse_management.batches.genealogy')">
          <VRow class="pa-2">
            <VCol cols="12" sm="6"><div class="text-caption mb-1">{{ t('warehouse_management.batches.made_from') }}</div>
              <div v-for="g in genealogy.made_from" :key="g.lot" class="text-body-2"><a :href="route('warehouse.batches.show', g.lot)">{{ g.item }} {{ g.number }}</a> <span class="text-caption">· {{ t('warehouse_management.batches.steps', { n: g.depth }) }}</span></div>
              <div v-if="!genealogy.made_from.length" class="text-caption text-medium-emphasis">—</div></VCol>
            <VCol cols="12" sm="6"><div class="text-caption mb-1">{{ t('warehouse_management.batches.went_into') }}</div>
              <div v-for="g in genealogy.went_into" :key="g.lot" class="text-body-2"><a :href="route('warehouse.batches.show', g.lot)">{{ g.item }} {{ g.number }}</a> <span class="text-caption">· {{ g.in_stock }} {{ t('warehouse_management.batches.in_stock_short') }}</span></div>
              <div v-if="!genealogy.went_into.length" class="text-caption text-medium-emphasis">—</div></VCol>
          </VRow>
        </VCard>
        <VCard v-if="inspections.length" border class="mb-4" :title="t('warehouse_management.cards.inspections.title')">
          <VTable density="compact"><tbody>
            <tr v-for="i in inspections" :key="i.number"><td>{{ i.number }}</td><td>{{ t(`warehouse_management.inspection_stage.${i.stage}`) }}</td>
              <td>{{ t(`warehouse_management.inspection_result.${i.result}`) }}</td><td>{{ i.disposition ? t(`warehouse_management.disposition.${i.disposition}`) : '—' }}</td></tr>
          </tbody></VTable>
        </VCard>
        <VCard border :title="t('warehouse_management.batches.history')">
          <div class="overflow-x-auto">
            <VTable density="compact">
              <thead><tr><th>{{ t('warehouse_management.batches.when') }}</th><th>{{ t('warehouse_management.common.type') }}</th><th>{{ t('warehouse_management.quality.where') }}</th><th class="text-end">{{ t('warehouse_management.quality.qty') }}</th><th>{{ t('warehouse_management.batches.by') }}</th></tr></thead>
              <tbody>
                <tr v-for="h in history" :key="h.id">
                  <td class="text-no-wrap">{{ h.date }}</td>
                  <td>{{ h.type }}<span v-if="h.to_status && h.from_status !== h.to_status" class="text-caption"> · {{ h.from_status }} → {{ h.to_status }}</span><div v-if="h.reference" class="text-caption">{{ h.reference }}</div></td>
                  <td>{{ h.warehouse }} {{ h.bin ?? '' }}<span v-if="h.to_bin"> → {{ h.to_bin }}</span></td>
                  <td class="text-end" :class="h.qty < 0 ? 'text-error' : ''">{{ h.qty }}</td><td class="text-caption">{{ h.by ?? '—' }}</td>
                </tr>
              </tbody>
            </VTable>
          </div>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
