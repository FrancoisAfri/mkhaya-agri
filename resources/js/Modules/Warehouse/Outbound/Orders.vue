<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import RefusalAlert from '../components/RefusalAlert.vue'
import { orderColor } from './statusColor'

const { t } = useI18n()
const route = ziggyRoute

type Order = { id: string; number: string; source: string; external_ref: string; status: string; warehouse: string; customer: string | null; priority: number; required_by: string | null; sla_due_at: string | null; at_risk: boolean; overdue: boolean; lines: number; qty: number; allocated: number; picked: number; dispatched: number; short: number }

const props = defineProps<{
  orders: { data: Order[]; links: Array<{ url: string | null; label: string; active: boolean }> }
  filters: { view: string; warehouse: string | null; q: string | null }
  warehouses: Array<{ id: string; title: string }>
  sales_orders: Array<{ id: number; title: string }>
  production_orders: Array<{ id: number; title: string }>
  counts: Record<string, number>
  can: { manage: boolean; pick: boolean; pack: boolean; dispatch: boolean }
}>()

const q = ref(props.filters.q ?? '')
function reload(patch: Record<string, unknown>) {
  router.get(route('warehouse.outbound.orders.index'), { ...props.filters, q: q.value, ...patch }, { preserveScroll: true })
}

const dialog = ref(false)
const form = useForm({ warehouse: props.warehouses[0]?.id ?? null, kind: 'SALES_ORDER', sales_order_id: null as number | null, production_order_id: null as number | null,
  reference: '', customer_name: '', required_by: '', priority: 50, allow_partial: true, lines: [] as Array<{ item: string; title: string; qty: number }> })
const itemOptions = ref<Array<{ id: string; title: string }>>([])
const pick = ref<string | null>(null)
async function searchItems(s: string) { itemOptions.value = (await axios.get(route('warehouse.products.search'), { params: { q: s } })).data }
function addLine() {
  const o = itemOptions.value.find(x => x.id === pick.value)
  if (o) form.lines.push({ item: o.id, title: o.title, qty: 1 })
  pick.value = null
}
function create() {
  form.transform(d => ({ ...d, required_by: d.required_by || null })).post(route('warehouse.outbound.orders.store'))
}
const sum = (states: string[]) => states.reduce((a, s) => a + (props.counts[s] ?? 0), 0)
const due = (iso: string | null) => iso ? new Date(iso).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' }) : '—'
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.outbound.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.outbound.subtitle') }}</p>
      </div>
      <VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="dialog = true">{{ t('warehouse_management.outbound.take_in') }}</VBtn>
    </div>
    <RefusalAlert />
    <div class="d-flex gap-2 mb-3 flex-wrap align-center">
      <VChip v-for="v in [['open', ['NEW', 'RELEASED', 'ALLOCATED', 'PARTIALLY_ALLOCATED', 'BACKORDERED', 'PICKING', 'PICKED', 'PACKED', 'PARTIALLY_DISPATCHED']], ['new', ['NEW']], ['short', ['BACKORDERED', 'PARTIALLY_ALLOCATED']], ['done', ['DISPATCHED', 'DELIVERED', 'CANCELLED']]] as const"
             :key="v[0]" :variant="filters.view === v[0] ? 'flat' : 'tonal'" :color="v[0] === 'short' ? 'error' : 'primary'" @click="reload({ view: v[0], page: 1 })">
        {{ t(`warehouse_management.outbound.view.${v[0]}`) }} ({{ sum([...v[1]]) }})
      </VChip>
      <VSelect :model-value="filters.warehouse" :items="warehouses" item-title="title" item-value="id" clearable density="compact" hide-details style="max-width: 240px"
               :label="t('warehouse_management.common.warehouse')" @update:model-value="v => reload({ warehouse: v })" />
      <VTextField v-model="q" prepend-inner-icon="tabler-search" density="compact" hide-details clearable style="max-width: 260px" :placeholder="t('warehouse_management.outbound.search')" @keyup.enter="reload({ page: 1 })" />
    </div>
    <VCard border>
      <VCardText v-if="!orders.data.length" class="text-center text-medium-emphasis py-10">{{ t('warehouse_management.outbound.none') }}</VCardText>
      <div v-else style="overflow-x: auto">
        <VTable density="comfortable">
          <thead><tr><th>{{ t('warehouse_management.outbound.order') }}</th><th>{{ t('warehouse_management.outbound.customer') }}</th><th>{{ t('warehouse_management.outbound.due') }}</th>
            <th class="text-end">{{ t('warehouse_management.outbound.progress') }}</th><th>{{ t('warehouse_management.locations.status') }}</th></tr></thead>
          <tbody>
            <tr v-for="o in orders.data" :key="o.id">
              <td><a :href="route('warehouse.outbound.orders.show', o.id)" class="font-weight-medium">{{ o.number }}</a>
                <div class="text-caption text-medium-emphasis">{{ t(`warehouse_management.order_source.${o.source}`) }} · {{ o.external_ref }} · {{ o.warehouse }}</div></td>
              <td>{{ o.customer ?? '—' }}<div class="text-caption">{{ t('warehouse_management.transfers.priority') }} {{ o.priority }}</div></td>
              <td class="text-no-wrap" :class="{ 'text-error font-weight-bold': o.overdue, 'text-warning': o.at_risk && !o.overdue }">{{ due(o.sla_due_at) }}
                <VChip v-if="o.at_risk" size="x-small" :color="o.overdue ? 'error' : 'warning'" variant="tonal" class="ms-1">{{ o.overdue ? t('warehouse_management.outbound.late') : t('warehouse_management.outbound.at_risk') }}</VChip></td>
              <td class="text-end text-caption" style="font-variant-numeric: tabular-nums">
                {{ t('warehouse_management.outbound.progress_line', { qty: o.qty, allocated: o.allocated, picked: o.picked, dispatched: o.dispatched }) }}
                <div v-if="o.short" class="text-error">{{ t('warehouse_management.transfers.short', { n: o.short }) }}</div></td>
              <td><VChip size="small" label variant="tonal" :color="orderColor(o.status)">{{ t(`warehouse_management.order_status.${o.status}`) }}</VChip></td>
            </tr>
          </tbody>
        </VTable>
      </div>
      <VCardActions v-if="orders.links.length > 3" class="justify-center flex-wrap">
        <VBtn v-for="(l, i) in orders.links" :key="i" size="small" :variant="l.active ? 'flat' : 'text'" :disabled="!l.url" @click="l.url && router.get(l.url)"><span v-html="l.label" /></VBtn>
      </VCardActions>
    </VCard>

    <VDialog v-model="dialog" max-width="620" scrollable>
      <VCard>
        <VCardTitle>{{ t('warehouse_management.outbound.take_in') }}</VCardTitle>
        <VCardText>
          <VSelect v-model="form.warehouse" :items="warehouses" item-title="title" item-value="id" :label="t('warehouse_management.common.warehouse')" />
          <VRadioGroup v-model="form.kind" inline>
            <VRadio value="SALES_ORDER" :label="t('warehouse_management.order_source.SALES_ORDER')" />
            <VRadio value="MANUFACTURING" :label="t('warehouse_management.order_source.MANUFACTURING')" />
            <VRadio value="INTERNAL" :label="t('warehouse_management.order_source.INTERNAL')" />
          </VRadioGroup>
          <VAutocomplete v-if="form.kind === 'SALES_ORDER'" v-model="form.sales_order_id" :items="sales_orders" item-title="title" item-value="id" :label="t('warehouse_management.outbound.sales_order')" :error-messages="form.errors.sales_order_id" />
          <VAutocomplete v-if="form.kind === 'MANUFACTURING'" v-model="form.production_order_id" :items="production_orders" item-title="title" item-value="id" :label="t('warehouse_management.outbound.production_order')" :error-messages="form.errors.production_order_id" />
          <template v-if="form.kind === 'INTERNAL'">
            <VTextField v-model="form.reference" :label="t('warehouse_management.outbound.reference')" :error-messages="form.errors.reference" />
            <VTextField v-model="form.customer_name" :label="t('warehouse_management.outbound.issued_to')" />
            <div v-for="(l, i) in form.lines" :key="i" class="d-flex gap-2 align-center mb-2">
              <div class="flex-grow-1">{{ l.title }}</div>
              <VTextField v-model.number="l.qty" type="number" min="0" density="compact" hide-details style="max-width: 110px" />
              <VBtn icon="tabler-x" size="small" variant="text" :aria-label="t('warehouse_management.common.remove')" @click="form.lines.splice(i, 1)" />
            </div>
            <VAutocomplete v-model="pick" :items="itemOptions" item-title="title" item-value="id" density="compact" :label="t('warehouse_management.nonpo.add_item')" @update:search="searchItems" @update:model-value="addLine" />
          </template>
          <VRow dense>
            <VCol cols="6"><VTextField v-model="form.required_by" type="datetime-local" :label="t('warehouse_management.outbound.required_by')" /></VCol>
            <VCol cols="6"><VTextField v-model.number="form.priority" type="number" min="1" max="100" :label="t('warehouse_management.transfers.priority')" /></VCol>
          </VRow>
          <VSwitch v-model="form.allow_partial" color="primary" density="compact" :label="t('warehouse_management.outbound.allow_partial')" />
          <VAlert v-if="(form.errors as any).refused" type="error" variant="tonal">{{ (form.errors as any).refused }}</VAlert>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('warehouse_management.common.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" @click="create">{{ t('warehouse_management.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
