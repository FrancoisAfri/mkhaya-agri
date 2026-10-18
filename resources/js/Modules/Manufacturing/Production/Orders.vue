<script setup lang="ts">
import { ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { orderStatusColor } from './status'

const { t } = useI18n()
const route = ziggyRoute

type Row = {
  id: string; number: string; status: string; product_code: string | null; product_name: string | null
  factory: string | null; planned_qty: number; qty_completed: number; due_date: string | null
  priority: number; demand_reference: string | null; estimated_total: number; is_overdue: boolean
}
type Option = { id: string; code: string | null; name: string | null }

const props = defineProps<{
  orders: { data: Row[]; current_page: number; last_page: number }
  filters: { status?: string; factory?: string; search?: string }
  statuses: string[]
  factories: Array<{ id: string; code: string; name: string; default_warehouse_id: number | null }>
  products: Option[]
  warehouses: Array<{ id: number; code: string; name: string }>
  people: Array<{ id: number; name: string }>
  can: { create: boolean }
}>()

const search = ref(props.filters.search ?? '')
const status = ref<string | null>(props.filters.status ?? 'OPEN')
const factory = ref<string | null>(props.filters.factory ?? null)
let timer: ReturnType<typeof setTimeout> | undefined

function apply(extra: Record<string, unknown> = {}) {
  router.get(route('manufacturing.production.orders.index'), {
    search: search.value || undefined, status: status.value || undefined, factory: factory.value || undefined, ...extra,
  }, { preserveState: true, preserveScroll: true, replace: true })
}

watch(search, () => { clearTimeout(timer); timer = setTimeout(() => apply(), 300) })
watch([status, factory], () => apply())

const statusItems = [
  { title: t('manufacturing.orders.open_only'), value: 'OPEN' },
  ...props.statuses.map(s => ({ title: t(`manufacturing.order_status.${s}`), value: s })),
]
const warehouseItems = props.warehouses.map(w => ({ title: `${w.code} — ${w.name}`, value: w.id }))
const demandTypes = ['MANUAL', 'SALES_ORDER', 'STOCK', 'FORECAST', 'PROJECT']

// ── New order ───────────────────────────────────────────────────────────────
const dialog = ref(false)
const form = useForm({
  item_uuid: null as string | null, factory_uuid: props.factories.length === 1 ? props.factories[0].id : null as string | null,
  quantity: null as number | null, due_date: '', start: '', warehouse_id: null as number | null,
  component_warehouse_id: null as number | null, priority: 3, supervisor_id: null as number | null,
  demand_type: 'MANUAL', demand_reference: '', notes: '', allow_below_min_batch: false,
})

// A factory names the warehouse its stock lands in; offer it rather than make people look it up.
watch(() => form.factory_uuid, (id) => {
  const f = props.factories.find(x => x.id === id)
  if (f?.default_warehouse_id && !form.warehouse_id) form.warehouse_id = f.default_warehouse_id
}, { immediate: true })

function save() {
  form.transform(d => ({ ...d, start: d.start || null, component_warehouse_id: d.component_warehouse_id || null }))
    .post(route('manufacturing.production.orders.store'), { onSuccess: () => { dialog.value = false; form.reset() } })
}

const headers = [
  { title: t('manufacturing.orders.number'), key: 'number' },
  { title: t('manufacturing.bom.product'), key: 'product_code' },
  { title: t('manufacturing.orders.factory'), key: 'factory' },
  { title: t('manufacturing.orders.quantity'), key: 'planned_qty', align: 'end' as const },
  { title: t('manufacturing.orders.due'), key: 'due_date' },
  { title: t('manufacturing.orders.priority'), key: 'priority' },
  { title: t('manufacturing.bom.status'), key: 'status' },
  { title: t('manufacturing.orders.estimate'), key: 'estimated_total', align: 'end' as const },
]
const money = (n: number) => n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('manufacturing.orders.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.orders.subtitle') }}</p>
      </div>
      <VBtn v-if="can.create" color="primary" prepend-icon="tabler-plus" @click="dialog = true">
        {{ t('manufacturing.orders.new') }}
      </VBtn>
    </div>

    <VCard border>
      <VCardText class="d-flex gap-3 flex-wrap align-center">
        <VTextField v-model="search" density="compact" hide-details clearable style="max-width: 280px"
                    prepend-inner-icon="tabler-search" :label="t('manufacturing.orders.search')" />
        <VSelect v-model="status" density="compact" hide-details clearable style="max-width: 220px"
                 :items="statusItems" :label="t('manufacturing.bom.status')" />
        <VSelect v-if="factories.length > 1" v-model="factory" density="compact" hide-details clearable style="max-width: 220px"
                 :items="factories.map(f => ({ title: `${f.code} — ${f.name}`, value: f.id }))"
                 :label="t('manufacturing.orders.factory')" />
      </VCardText>

      <VDataTable :headers="headers" :items="orders.data" item-value="id" hover :items-per-page="-1" hide-default-footer
                  @click:row="(_: unknown, { item }: any) => router.visit(route('manufacturing.production.orders.show', item.id))">
        <template #item.number="{ item }">
          <span class="font-weight-medium">{{ item.number }}</span>
          <div v-if="item.demand_reference" class="text-caption text-medium-emphasis">{{ item.demand_reference }}</div>
        </template>
        <template #item.product_code="{ item }">
          <div>{{ item.product_code }}</div>
          <div class="text-caption text-medium-emphasis">{{ item.product_name }}</div>
        </template>
        <template #item.planned_qty="{ item }">
          {{ item.qty_completed }} / {{ item.planned_qty }}
        </template>
        <template #item.due_date="{ item }">
          <span :class="{ 'text-error font-weight-medium': item.is_overdue }">{{ item.due_date }}</span>
          <VChip v-if="item.is_overdue" size="x-small" color="error" label class="ms-1">{{ t('manufacturing.orders.overdue') }}</VChip>
        </template>
        <template #item.priority="{ item }">{{ t(`manufacturing.orders.priority_${item.priority}`) }}</template>
        <template #item.status="{ item }">
          <VChip size="small" label variant="tonal" :color="orderStatusColor(item.status)">{{ t(`manufacturing.order_status.${item.status}`) }}</VChip>
        </template>
        <template #item.estimated_total="{ item }">{{ money(item.estimated_total) }}</template>
        <template #no-data>
          <div class="py-8 text-medium-emphasis">
            {{ t('manufacturing.orders.none') }}
            <div class="text-caption">{{ t('manufacturing.orders.none_hint') }}</div>
          </div>
        </template>
      </VDataTable>

      <VCardText v-if="orders.last_page > 1" class="d-flex justify-center">
        <VPagination :model-value="orders.current_page" :length="orders.last_page" total-visible="7"
                     @update:model-value="(p: number) => apply({ page: p })" />
      </VCardText>
    </VCard>

    <VDialog v-model="dialog" max-width="680">
      <VCard :title="t('manufacturing.orders.new')" :subtitle="t('manufacturing.orders.new_hint')">
        <VCardText>
          <VRow dense>
            <VCol cols="12">
              <VAutocomplete v-model="form.item_uuid" :items="products" item-value="id"
                             :item-title="(i: any) => `${i.code} — ${i.name}`" :error-messages="form.errors.item_uuid"
                             :label="t('manufacturing.bom.product')" />
            </VCol>
            <VCol cols="12" sm="6">
              <VSelect v-model="form.factory_uuid" :items="factories.map(f => ({ title: `${f.code} — ${f.name}`, value: f.id }))"
                       :error-messages="form.errors.factory_uuid" :label="t('manufacturing.orders.factory')" />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField v-model.number="form.quantity" type="number" min="0" step="any"
                          :error-messages="form.errors.quantity" :label="t('manufacturing.orders.quantity')" />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField v-model="form.start" type="date" :error-messages="form.errors.start"
                          :label="t('manufacturing.orders.start')" :hint="t('manufacturing.orders.start_hint')" persistent-hint />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField v-model="form.due_date" type="date" :error-messages="form.errors.due_date" :label="t('manufacturing.orders.due')" />
            </VCol>
            <VCol cols="12" sm="6">
              <VSelect v-model="form.warehouse_id" :items="warehouseItems" :error-messages="form.errors.warehouse_id"
                       :label="t('manufacturing.orders.output_warehouse')" />
            </VCol>
            <VCol cols="12" sm="6">
              <VSelect v-model="form.component_warehouse_id" :items="warehouseItems" clearable
                       :error-messages="form.errors.component_warehouse_id"
                       :label="t('manufacturing.orders.material_warehouse')" :hint="t('manufacturing.orders.material_warehouse_hint')" persistent-hint />
            </VCol>
            <VCol cols="12" sm="6">
              <VSelect v-model="form.priority" :items="[1, 2, 3, 4, 5].map(p => ({ title: t(`manufacturing.orders.priority_${p}`), value: p }))"
                       :label="t('manufacturing.orders.priority')" />
            </VCol>
            <VCol cols="12" sm="6">
              <VAutocomplete v-model="form.supervisor_id" :items="people" item-value="id" item-title="name" clearable
                             :error-messages="form.errors.supervisor_id" :label="t('manufacturing.orders.supervisor')" />
            </VCol>
            <VCol cols="12" sm="6">
              <VSelect v-model="form.demand_type" :items="demandTypes.map(d => ({ title: t(`manufacturing.orders.demand_${d}`), value: d }))"
                       :label="t('manufacturing.orders.demand')" />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField v-model="form.demand_reference" :label="t('manufacturing.orders.demand_reference')" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="form.notes" rows="2" auto-grow :label="t('manufacturing.orders.notes')" />
            </VCol>
            <VCol cols="12">
              <VCheckbox v-model="form.allow_below_min_batch" density="compact" hide-details
                         :label="t('manufacturing.orders.allow_below_min_batch')" />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing"
                :disabled="!form.item_uuid || !form.factory_uuid || !form.quantity || !form.due_date || !form.warehouse_id" @click="save">
            {{ t('manufacturing.orders.create') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
