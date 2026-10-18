<script setup lang="ts">
import { reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { orderColor } from './statusColor'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  metrics: Record<string, number | null>
  at_risk: Array<Record<string, any>>
  filters: { from: string; to: string; warehouse: string | null }
  warehouses: Array<{ id: string; title: string }>
}>()

const f = reactive({ ...props.filters })
const tiles = [
  ['orders', 'n'], ['dispatched', 'n'], ['on_time_rate', '%'], ['picking_accuracy', '%'], ['release_to_pick_minutes', 'min'],
  ['pick_minutes', 'min'], ['pack_minutes', 'min'], ['release_to_dispatch_minutes', 'min'], ['short_lines', 'n'], ['at_risk_now', 'n'],
] as const
const show = (v: number | null, kind: string) => v === null ? '—' : kind === '%' ? `${Math.round(v * 1000) / 10}%` : kind === 'min' ? (v >= 120 ? `${Math.round(v / 6) / 10} h` : `${Math.round(v)} min`) : String(v)
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.sla.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.sla.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <VSelect v-model="f.warehouse" :items="warehouses" item-title="title" item-value="id" clearable density="compact" hide-details style="min-width: 220px" :label="t('warehouse_management.common.warehouse')" />
        <VTextField v-model="f.from" type="date" density="compact" hide-details style="max-width: 160px" :label="t('warehouse_management.movements.from')" />
        <VTextField v-model="f.to" type="date" density="compact" hide-details style="max-width: 160px" :label="t('warehouse_management.movements.to')" />
        <VBtn color="primary" @click="router.get(route('warehouse.outbound.sla'), f)">{{ t('warehouse_management.movements.apply') }}</VBtn>
      </div>
    </div>
    <div class="mb-4" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 12px">
      <VCard v-for="[k, kind] in tiles" :key="k" border>
        <VCardText>
          <div class="text-caption">{{ t(`warehouse_management.sla.metric.${k}`) }}</div>
          <div class="text-h5" style="font-variant-numeric: tabular-nums">{{ show(metrics[k] ?? null, kind) }}</div>
        </VCardText>
      </VCard>
    </div>
    <VCard border>
      <VCardTitle>{{ t('warehouse_management.sla.due_soon') }}</VCardTitle>
      <VCardText v-if="!at_risk.length" class="text-medium-emphasis">{{ t('warehouse_management.sla.nothing_due') }}</VCardText>
      <VTable v-else density="compact">
        <tbody>
          <tr v-for="o in at_risk" :key="o.id">
            <td><a :href="route('warehouse.outbound.orders.show', o.id)">{{ o.number }}</a> · {{ o.customer }}</td>
            <td :class="{ 'text-error': o.overdue }">{{ new Date(o.sla_due_at).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' }) }}</td>
            <td><VChip size="x-small" :color="orderColor(o.status)" variant="tonal">{{ t(`warehouse_management.order_status.${o.status}`) }}</VChip></td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
