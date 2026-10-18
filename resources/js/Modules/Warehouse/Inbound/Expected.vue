<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute

type Line = { po_id: string; po_number: string; expected_delivery: string | null; supplier: string | null; warehouse: string | null; line_id: string; name: string; quantity: number; received_qty: number; outstanding: number; stock_item_id: string | null; stock_code: string | null; uom: string | null; overdue: boolean; linked: boolean }

const props = defineProps<{
  lines: Line[]
  filters: { warehouse: string | null; overdue: boolean }
  warehouses: Array<{ id: string; title: string }>
  units: Array<{ id: number; title: string }>
  can: { receive: boolean; link: boolean }
}>()

function reload(patch: Record<string, unknown>) {
  router.get(route('warehouse.inbound.expected'), { ...props.filters, ...patch }, { preserveScroll: true })
}

const linking = ref<Line | null>(null)
const linkForm = useForm({ item: null as string | null, uom_id: null as number | null })
const itemOptions = ref<Array<{ id: string; title: string }>>([])
async function searchItems(q: string) {
  const { data } = await axios.get(route('warehouse.products.search'), { params: { q } })
  itemOptions.value = data
}
function link() {
  if (!linking.value) return
  linkForm.post(route('warehouse.inbound.lines.link', linking.value.line_id), { preserveScroll: true, onSuccess: () => { linking.value = null } })
}
const fmt = (n: number) => new Intl.NumberFormat(undefined, { maximumFractionDigits: 4 }).format(n)
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.expected.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.expected.subtitle') }}</p>
      </div>
      <VBtn v-if="can.receive" variant="tonal" prepend-icon="tabler-package-import" :href="route('warehouse.inbound.nonpo.form')">{{ t('warehouse_management.cards.receive_without_order.title') }}</VBtn>
    </div>
    <RefusalAlert />
    <VCard border>
      <VCardText class="d-flex gap-3 flex-wrap align-center">
        <VSelect :model-value="filters.warehouse" :items="warehouses" item-title="title" item-value="id" clearable density="compact" hide-details style="max-width: 280px"
                 :label="t('warehouse_management.common.warehouse')" @update:model-value="v => reload({ warehouse: v })" />
        <VSwitch :model-value="filters.overdue" color="error" density="compact" hide-details :label="t('warehouse_management.expected.only_overdue')" @update:model-value="v => reload({ overdue: v ? 1 : 0 })" />
      </VCardText>
      <VCardText v-if="!lines.length" class="text-center text-medium-emphasis py-10">{{ t('warehouse_management.expected.none') }}</VCardText>
      <div v-else style="overflow-x: auto">
        <VTable density="compact">
          <thead>
            <tr><th>{{ t('warehouse_management.expected.order') }}</th><th>{{ t('warehouse_management.expected.due') }}</th><th>{{ t('warehouse_management.expected.line') }}</th>
              <th>{{ t('warehouse_management.movements.item') }}</th><th class="text-end">{{ t('warehouse_management.expected.ordered') }}</th>
              <th class="text-end">{{ t('warehouse_management.expected.received') }}</th><th class="text-end">{{ t('warehouse_management.expected.outstanding') }}</th><th /></tr>
          </thead>
          <tbody>
            <tr v-for="l in lines" :key="l.line_id">
              <td class="text-no-wrap">{{ l.po_number }}<div class="text-caption text-medium-emphasis">{{ l.supplier }}</div></td>
              <td class="text-no-wrap">
                <span :class="{ 'text-error font-weight-bold': l.overdue }">{{ l.expected_delivery ?? '—' }}</span>
                <VChip v-if="l.overdue" size="x-small" color="error" variant="tonal" class="ms-1">{{ t('warehouse_management.expected.overdue') }}</VChip>
              </td>
              <td>{{ l.name }}</td>
              <td>
                <template v-if="l.linked">{{ l.stock_code }}</template>
                <VBtn v-else-if="can.link" size="x-small" variant="tonal" color="warning" @click="linking = l; linkForm.reset()">{{ t('warehouse_management.expected.link') }}</VBtn>
                <span v-else class="text-caption text-warning">{{ t('warehouse_management.expected.not_linked') }}</span>
              </td>
              <td class="text-end">{{ fmt(l.quantity) }} {{ l.uom }}</td>
              <td class="text-end">{{ fmt(l.received_qty) }}</td>
              <td class="text-end font-weight-bold">{{ fmt(l.outstanding) }}</td>
              <td class="text-end"><VBtn v-if="can.receive" size="small" color="primary" variant="tonal" :href="route('warehouse.inbound.receive.form', l.po_id)">{{ t('warehouse_management.expected.receive') }}</VBtn></td>
            </tr>
          </tbody>
        </VTable>
      </div>
    </VCard>

    <VDialog :model-value="!!linking" max-width="520" @update:model-value="v => { if (!v) linking = null }">
      <VCard v-if="linking">
        <VCardTitle>{{ t('warehouse_management.expected.link_title', { line: linking.name }) }}</VCardTitle>
        <VCardText>
          <p class="text-body-2 text-medium-emphasis">{{ t('warehouse_management.expected.link_hint') }}</p>
          <VAutocomplete v-model="linkForm.item" :items="itemOptions" item-title="title" item-value="id" :label="t('warehouse_management.movements.item')" @update:search="searchItems" />
          <VSelect v-model="linkForm.uom_id" :items="units" item-title="title" item-value="id" clearable :label="t('warehouse_management.expected.order_unit')" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="linking = null">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="linkForm.processing" :disabled="!linkForm.item" @click="link">{{ t('warehouse_management.common.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
