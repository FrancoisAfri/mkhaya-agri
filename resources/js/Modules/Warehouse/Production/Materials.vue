<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Line = { item: string; uom: string; required: number; issued: number; returned: number; still_needed: number; reserved: number; free: number; short: number; staged: number; expected_for_output: number; used: number; variance_pct: number | null; flag: boolean }
type Order = { id: string; number: string; status: string; item: string | null; item_name: string | null; planned_qty: number; completed: number; scrapped: number; planned_start: string | null; lines: Line[]; short_lines: number; flags: number; can_release: boolean; warehouse_order: string | null }
defineProps<{
  orders: Order[]
  finished_awaiting_putaway: Array<{ position: number; code: string; name: string; warehouse: string; lot: string | null; qty: number }>
  variance_threshold: number
  can: { orders: boolean; move: boolean }
}>()
const pick = (o: Order) => router.post(route('warehouse.production.pick', o.id), {}, { preserveScroll: true })
const putAway = (position: number) => router.post(route('warehouse.production.putaway', position), {}, { preserveScroll: true })
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('warehouse_management.production.title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.production.subtitle', { pct: variance_threshold }) }}</p>
    </div>

    <VCard v-if="finished_awaiting_putaway.length" border class="mb-4" :title="t('warehouse_management.production.awaiting')">
      <VList density="compact">
        <VListItem v-for="f in finished_awaiting_putaway" :key="f.position">
          <VListItemTitle>{{ f.code }} · {{ f.name }} <span class="text-caption">{{ f.warehouse }}<template v-if="f.lot"> · {{ f.lot }}</template></span></VListItemTitle>
          <VListItemSubtitle>{{ f.qty }}</VListItemSubtitle>
          <template #append><VBtn v-if="can.move" size="small" variant="tonal" @click="putAway(f.position)">{{ t('warehouse_management.production.put_away') }}</VBtn></template>
        </VListItem>
      </VList>
    </VCard>

    <VCard v-if="!orders.length" border><VCardText class="text-center text-medium-emphasis py-10">{{ t('warehouse_management.production.none') }}</VCardText></VCard>
    <VCard v-for="o in orders" :key="o.id" border class="mb-4">
      <VCardItem>
        <VCardTitle>{{ o.number }} · {{ o.item }} <span class="text-body-2">{{ o.item_name }}</span>
          <VChip size="x-small" class="ms-1">{{ o.status }}</VChip>
          <VChip v-if="o.short_lines" size="x-small" color="error" class="ms-1">{{ t('warehouse_management.production.short_n', { n: o.short_lines }) }}</VChip>
          <VChip v-else size="x-small" color="success" class="ms-1">{{ t('warehouse_management.production.ready') }}</VChip>
          <VChip v-if="o.flags" size="x-small" color="warning" class="ms-1">{{ t('warehouse_management.production.over_used_n', { n: o.flags }) }}</VChip>
        </VCardTitle>
        <VCardSubtitle>{{ t('warehouse_management.production.progress', { done: o.completed, scrap: o.scrapped, planned: o.planned_qty }) }}<template v-if="o.planned_start"> · {{ o.planned_start }}</template></VCardSubtitle>
        <template #append>
          <VChip v-if="o.warehouse_order" size="small" variant="outlined">{{ t('warehouse_management.production.pick_order', { n: o.warehouse_order }) }}</VChip>
          <VBtn v-else-if="can.orders" size="small" color="primary" variant="tonal" @click="pick(o)">{{ t('warehouse_management.production.raise_pick') }}</VBtn>
        </template>
      </VCardItem>
      <div style="overflow-x: auto">
        <VTable density="compact">
          <thead><tr>
            <th>{{ t('warehouse_management.production.component') }}</th><th class="text-end">{{ t('warehouse_management.production.required') }}</th>
            <th class="text-end">{{ t('warehouse_management.production.issued') }}</th><th class="text-end">{{ t('warehouse_management.production.still_needed') }}</th>
            <th class="text-end">{{ t('warehouse_management.production.reserved') }}</th><th class="text-end">{{ t('warehouse_management.production.free') }}</th>
            <th class="text-end">{{ t('warehouse_management.production.short') }}</th><th class="text-end">{{ t('warehouse_management.production.staged') }}</th>
            <th class="text-end">{{ t('warehouse_management.production.variance') }}</th>
          </tr></thead>
          <tbody>
            <tr v-for="l in o.lines" :key="l.item">
              <td>{{ l.item }} <span class="text-caption">{{ l.uom }}</span></td><td class="text-end">{{ l.required }}</td>
              <td class="text-end">{{ l.issued }}<span v-if="l.returned" class="text-caption"> (−{{ l.returned }})</span></td><td class="text-end">{{ l.still_needed }}</td>
              <td class="text-end">{{ l.reserved }}</td><td class="text-end">{{ l.free }}</td>
              <td class="text-end" :class="{ 'text-error font-weight-bold': l.short > 0 }">{{ l.short }}</td><td class="text-end">{{ l.staged }}</td>
              <td class="text-end" :class="{ 'text-warning font-weight-bold': l.flag }">{{ l.variance_pct === null ? '—' : `${l.variance_pct}%` }}</td>
            </tr>
          </tbody>
        </VTable>
      </div>
    </VCard>
  </div>
</template>
