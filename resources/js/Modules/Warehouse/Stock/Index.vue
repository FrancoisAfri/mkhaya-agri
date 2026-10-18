<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Row = { id: string; code: string; name: string; uom: string; status: string; owned_by_client: boolean } & Record<string, number>

const props = defineProps<{
  warehouses: Array<{ id: string; title: string }>
  filters: { warehouse: string | null; q: string }
  items: { data: Row[]; links: Array<{ url: string | null; label: string; active: boolean }> }
}>()

const q = ref(props.filters.q)
function reload(patch: Record<string, unknown>) {
  router.get(route('warehouse.stock.index'), { ...props.filters, q: q.value, ...patch }, { preserveScroll: true, preserveState: true })
}
const cols = ['physical', 'held', 'in_transit', 'available_on_hand', 'reserved', 'allocated_not_picked', 'available_to_promise', 'expected_receipts', 'backordered'] as const
const fmt = (n: number) => new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(n)
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.stock.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.stock.subtitle') }}</p>
      </div>
      <VBtn variant="tonal" prepend-icon="tabler-list-details" :href="route('warehouse.stock.states')">{{ t('warehouse_management.cards.stock_states.title') }}</VBtn>
    </div>

    <VCard border>
      <VCardText class="d-flex gap-3 flex-wrap">
        <VTextField v-model="q" prepend-inner-icon="tabler-search" density="compact" hide-details clearable style="max-width: 300px"
                    :placeholder="t('warehouse_management.stock.search')" @keyup.enter="reload({ page: 1 })" @click:clear="q = ''; reload({ page: 1 })" />
        <VSelect :model-value="filters.warehouse" :items="warehouses" item-title="title" item-value="id" clearable density="compact" hide-details
                 style="max-width: 280px" :label="t('warehouse_management.common.warehouse')" @update:model-value="v => reload({ warehouse: v, page: 1 })" />
      </VCardText>
      <VCardText v-if="!items.data.length" class="text-center text-medium-emphasis py-10">{{ t('warehouse_management.stock.none') }}</VCardText>
      <div v-else style="overflow-x: auto">
        <VTable density="compact">
          <thead>
            <tr>
              <th>{{ t('warehouse_management.movements.item') }}</th>
              <th v-for="c in cols" :key="c" class="text-end text-no-wrap">{{ t(`warehouse_management.stock.col.${c}`) }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in items.data" :key="r.id">
              <td>
                <a :href="route('warehouse.stock.show', r.id)" class="font-weight-medium">{{ r.code }}</a>
                <VChip v-if="r.owned_by_client" size="x-small" variant="tonal" class="ms-1">{{ t('warehouse_management.stock.client_owned') }}</VChip>
                <div class="text-caption text-medium-emphasis">{{ r.name }}</div>
              </td>
              <td v-for="c in cols" :key="c" class="text-end" style="font-variant-numeric: tabular-nums"
                  :class="{ 'font-weight-bold': c === 'available_to_promise', 'text-warning': c === 'held' && r[c] > 0, 'text-error': c === 'backordered' && r[c] > 0 }">
                {{ fmt(r[c]) }}
              </td>
            </tr>
          </tbody>
        </VTable>
      </div>
      <VCardActions v-if="items.links.length > 3" class="justify-center flex-wrap">
        <VBtn v-for="(l, i) in items.links" :key="i" size="small" :variant="l.active ? 'flat' : 'text'" :disabled="!l.url"
              @click="l.url && router.get(l.url, {}, { preserveScroll: true })"><span v-html="l.label" /></VBtn>
      </VCardActions>
    </VCard>
  </div>
</template>
