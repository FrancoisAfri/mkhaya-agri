<script setup lang="ts">
import { reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Report = {
  warehouses: Array<{ warehouse: string; name: string; items: number; value: number; client_qty: number; carrying_rate: number | null; carrying_cost: number | null }>
  total_value: number; carrying_cost: number | null
  write_offs: { confirmed: number; suspected: number; recovered: number }
  landed: { components: Record<string, number>; to_inventory: number; to_cogs: number }
  price_variance: { total: number; items: Array<{ item: string; name: string; qty: number; paid: number; standard: number; variance: number }> }
  slow_moving: { days: number; value: number; items: Array<{ item: string; name: string; warehouse: string; qty: number; last_out: string | null; value: number }> }
  excess: { value: number; items: Array<{ item: string; name: string; on_hand: number; max: number; excess: number; value: number }> }
}
const props = defineProps<{ report: Report; filters: { from: string; to: string; warehouse: string | null; slow_days: number }; warehouses: Array<{ id: string; title: string }> }>()
const f = reactive({ ...props.filters })
const apply = () => router.get(route('warehouse.accounting.costs'), f, { preserveState: true })
const money = (v: number | null) => v === null ? '—' : v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <div class="text-caption"><a :href="route('warehouse.accounting.index')">{{ t('warehouse_management.accounting.title') }}</a></div>
      <h4 class="text-h4">{{ t('warehouse_management.costs.title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.costs.subtitle') }}</p>
    </div>
    <div class="d-flex gap-2 mb-4 flex-wrap align-center">
      <VSelect v-model="f.warehouse" :items="warehouses" item-value="id" clearable density="compact" hide-details style="max-width: 260px" :label="t('warehouse_management.common.warehouse')" />
      <VTextField v-model="f.from" type="date" density="compact" hide-details style="max-width: 170px" :label="t('warehouse_management.accuracy.from')" />
      <VTextField v-model="f.to" type="date" density="compact" hide-details style="max-width: 170px" :label="t('warehouse_management.accuracy.to')" />
      <VTextField v-model.number="f.slow_days" type="number" density="compact" hide-details style="max-width: 150px" :label="t('warehouse_management.costs.slow_days')" />
      <VBtn color="primary" @click="apply">{{ t('warehouse_management.accuracy.apply') }}</VBtn>
    </div>
    <div class="d-flex gap-3 mb-4 flex-wrap">
      <VCard border class="pa-3" min-width="170"><div class="text-caption">{{ t('warehouse_management.costs.value') }}</div><div class="text-h5">{{ money(report.total_value) }}</div></VCard>
      <VCard border class="pa-3" min-width="170"><div class="text-caption">{{ t('warehouse_management.costs.carrying') }}</div><div class="text-h5">{{ report.carrying_cost === null ? t('warehouse_management.costs.no_rate') : money(report.carrying_cost) }}</div></VCard>
      <VCard border class="pa-3" min-width="170"><div class="text-caption">{{ t('warehouse_management.losses.confirmed') }}</div><div class="text-h5 text-error">{{ money(report.write_offs.confirmed) }}</div></VCard>
      <VCard border class="pa-3" min-width="170"><div class="text-caption">{{ t('warehouse_management.costs.ppv') }}</div><div class="text-h5">{{ money(report.price_variance.total) }}</div></VCard>
      <VCard border class="pa-3" min-width="170"><div class="text-caption">{{ t('warehouse_management.costs.slow', { n: report.slow_moving.days }) }}</div><div class="text-h5 text-warning">{{ money(report.slow_moving.value) }}</div></VCard>
      <VCard border class="pa-3" min-width="170"><div class="text-caption">{{ t('warehouse_management.costs.excess') }}</div><div class="text-h5 text-warning">{{ money(report.excess.value) }}</div></VCard>
    </div>
    <VRow>
      <VCol cols="12" md="6">
        <VCard border :title="t('warehouse_management.costs.by_warehouse')">
          <VTable density="compact"><tbody>
            <tr v-for="w in report.warehouses" :key="w.warehouse"><td>{{ w.warehouse }} <span class="text-caption">{{ w.name }}</span><div v-if="w.client_qty" class="text-caption">{{ t('warehouse_management.costs.client_qty', { n: w.client_qty }) }}</div></td>
              <td class="text-end">{{ money(w.value) }}</td><td class="text-end text-caption">{{ w.carrying_rate === null ? '' : t('warehouse_management.costs.at_rate', { rate: w.carrying_rate, cost: money(w.carrying_cost) }) }}</td></tr>
          </tbody></VTable>
        </VCard>
        <VCard border class="mt-4" :title="t('warehouse_management.costs.landed')">
          <VCardText v-if="!Object.keys(report.landed.components).length" class="text-medium-emphasis">{{ t('warehouse_management.accounting.no_landed') }}</VCardText>
          <VTable v-else density="compact"><tbody>
            <tr v-for="(v, k) in report.landed.components" :key="k"><td>{{ t(`warehouse_management.landed_kind.${k}`) }}</td><td class="text-end">{{ money(v) }}</td></tr>
            <tr><td class="text-caption">{{ t('warehouse_management.accounting.split', { inv: money(report.landed.to_inventory), cogs: money(report.landed.to_cogs) }) }}</td><td /></tr>
          </tbody></VTable>
        </VCard>
      </VCol>
      <VCol cols="12" md="6">
        <VCard border :title="t('warehouse_management.costs.slow', { n: report.slow_moving.days })">
          <VCardText v-if="!report.slow_moving.items.length" class="text-medium-emphasis">{{ t('warehouse_management.costs.none') }}</VCardText>
          <VTable v-else density="compact"><tbody>
            <tr v-for="s in report.slow_moving.items" :key="`${s.item}${s.warehouse}`"><td>{{ s.item }} <span class="text-caption">{{ s.warehouse }}</span><div class="text-caption">{{ s.last_out ? t('warehouse_management.costs.last_out', { d: s.last_out }) : t('warehouse_management.costs.never_out') }}</div></td>
              <td class="text-end">{{ s.qty }}</td><td class="text-end">{{ money(s.value) }}</td></tr>
          </tbody></VTable>
        </VCard>
        <VCard v-if="report.excess.items.length" border class="mt-4" :title="t('warehouse_management.costs.excess')">
          <VTable density="compact"><tbody><tr v-for="e in report.excess.items" :key="e.item"><td>{{ e.item }}<div class="text-caption">{{ t('warehouse_management.costs.above_max', { max: e.max }) }}</div></td><td class="text-end">{{ e.excess }}</td><td class="text-end">{{ money(e.value) }}</td></tr></tbody></VTable>
        </VCard>
        <VCard v-if="report.price_variance.items.length" border class="mt-4" :title="t('warehouse_management.costs.ppv')">
          <VTable density="compact"><tbody><tr v-for="p in report.price_variance.items" :key="p.item"><td>{{ p.item }}</td><td class="text-end">{{ money(p.paid) }} / {{ money(p.standard) }}</td><td class="text-end" :class="p.variance > 0 ? 'text-error' : 'text-success'">{{ money(p.variance) }}</td></tr></tbody></VTable>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
