<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { inspectionStatusColor, resultColor } from './colors'

const { t } = useI18n()
const route = ziggyRoute

type Row = {
  id: string; number: string; stage: string; status: string; result: string; disposition: string | null
  item_code: string | null; item_name: string | null; lot: string | null; qty: number; plan_code: string | null
  order: { id: string; number: string } | null; created_at: string | null
}
type Options = {
  materials: Array<{ id: number; code: string; name: string; cost: number }>
  warehouses: Array<{ id: number; code: string; name: string }>
  suppliers: Array<{ id: number; name: string }>
  purchase_orders: Array<{ id: number; number: string; supplier_id: number | null }>
}

const props = defineProps<{
  inspections: { data: Row[]; current_page: number; last_page: number }
  filters: { status?: string; stage?: string; result?: string; search?: string }
  stages: string[]
  counts: { open: number; awaiting: number; failed: number }
  options: Options | null
  can: { create: boolean }
}>()

const search = ref(props.filters.search ?? '')
const status = ref<string | null>(props.filters.status ?? 'PENDING')
const stage = ref<string | null>(props.filters.stage ?? null)
const result = ref<string | null>(props.filters.result ?? null)
let timer: ReturnType<typeof setTimeout> | undefined

function apply(extra: Record<string, unknown> = {}) {
  router.get(route('manufacturing.quality.inspections.index'), {
    search: search.value || undefined, status: status.value || undefined, stage: stage.value || undefined,
    result: result.value || undefined, ...extra,
  }, { preserveState: true, preserveScroll: true, replace: true })
}
watch(search, () => { clearTimeout(timer); timer = setTimeout(() => apply(), 300) })
watch([status, stage, result], () => apply())

const statusItems = [
  { title: t('manufacturing.quality.pending_only'), value: 'PENDING' },
  ...['OPEN', 'AWAITING_DISPOSITION', 'CLOSED'].map(s => ({ title: t(`manufacturing.quality.status.${s}`), value: s })),
]
const stageItems = computed(() => props.stages.map(s => ({ title: t(`manufacturing.quality.stage.${s}`), value: s })))
const materialItems = computed(() => (props.options?.materials ?? []).map(m => ({ title: `${m.code} — ${m.name}`, value: m.id })))
const warehouseItems = computed(() => (props.options?.warehouses ?? []).map(w => ({ title: `${w.code} — ${w.name}`, value: w.id })))

// ── Receive for inspection ──────────────────────────────────────────────────
const receiveDialog = ref(false)
const receive = useForm({
  stock_item_id: null as number | null, warehouse_id: null as number | null, qty: null as number | null, unit_cost: null as number | null,
  lot_number: '', supplier_id: null as number | null, purchase_order_id: null as number | null,
  supplier_lot_number: '', expires_on: '', reference: '', idempotency_key: '',
})
watch(() => receive.stock_item_id, (id) => {
  const m = props.options?.materials.find(x => x.id === id)
  if (m && receive.unit_cost === null) receive.unit_cost = m.cost
})
const poItems = computed(() => (props.options?.purchase_orders ?? [])
  .filter(p => !receive.supplier_id || p.supplier_id === receive.supplier_id)
  .map(p => ({ title: p.number, value: p.id })))
function openReceive() {
  receive.reset()
  receive.idempotency_key = globalThis.crypto?.randomUUID?.() ?? `${Date.now()}`
  receiveDialog.value = true
}
function submitReceive() {
  receive.transform(d => ({ ...d, expires_on: d.expires_on || null }))
    .post(route('manufacturing.quality.inspections.receive'), { onSuccess: () => { receiveDialog.value = false } })
}

// ── Hold stock ──────────────────────────────────────────────────────────────
const holdDialog = ref(false)
const hold = useForm({
  stock_item_id: null as number | null, warehouse_id: null as number | null, qty: null as number | null,
  lot_number: '', stage: 'FINAL', reason: '',
})
function submitHold() {
  hold.post(route('manufacturing.quality.inspections.hold'), { onSuccess: () => { holdDialog.value = false; hold.reset() } })
}

const headers = [
  { title: t('manufacturing.quality.number'), key: 'number' },
  { title: t('manufacturing.quality.stage_label'), key: 'stage' },
  { title: t('manufacturing.orders.material'), key: 'item_code' },
  { title: t('manufacturing.quality.lot'), key: 'lot' },
  { title: t('manufacturing.orders.quantity'), key: 'qty', align: 'end' as const },
  { title: t('manufacturing.quality.result'), key: 'result' },
  { title: t('manufacturing.bom.status'), key: 'status' },
]
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('manufacturing.quality.inspections_title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.quality.inspections_subtitle') }}</p>
      </div>
      <div v-if="can.create" class="d-flex gap-2 flex-wrap">
        <VBtn color="primary" prepend-icon="tabler-truck-delivery" @click="openReceive">{{ t('manufacturing.quality.receive') }}</VBtn>
        <VBtn variant="tonal" color="warning" prepend-icon="tabler-hand-stop" @click="holdDialog = true">{{ t('manufacturing.quality.hold') }}</VBtn>
      </div>
    </div>

    <VRow class="mb-1">
      <VCol cols="12" sm="4"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('manufacturing.quality.count_open') }}</div><div class="text-h5">{{ counts.open }}</div></VCardText></VCard></VCol>
      <VCol cols="12" sm="4"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('manufacturing.quality.count_awaiting') }}</div><div class="text-h5 text-warning">{{ counts.awaiting }}</div></VCardText></VCard></VCol>
      <VCol cols="12" sm="4"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('manufacturing.quality.count_failed') }}</div><div class="text-h5 text-error">{{ counts.failed }}</div></VCardText></VCard></VCol>
    </VRow>

    <VCard border>
      <VCardText class="d-flex gap-3 flex-wrap align-center">
        <VTextField v-model="search" density="compact" hide-details clearable style="max-width: 280px"
                    prepend-inner-icon="tabler-search" :label="t('manufacturing.quality.search')" />
        <VSelect v-model="status" density="compact" hide-details clearable style="max-width: 220px" :items="statusItems" :label="t('manufacturing.bom.status')" />
        <VSelect v-model="stage" density="compact" hide-details clearable style="max-width: 200px" :items="stageItems" :label="t('manufacturing.quality.stage_label')" />
        <VSelect v-model="result" density="compact" hide-details clearable style="max-width: 180px"
                 :items="['PENDING', 'PASSED', 'FAILED'].map(r => ({ title: t(`manufacturing.quality.result_${r}`), value: r }))" :label="t('manufacturing.quality.result')" />
      </VCardText>

      <VDataTable :headers="headers" :items="inspections.data" item-value="id" hover :items-per-page="-1" hide-default-footer
                  @click:row="(_: unknown, { item }: any) => router.visit(route('manufacturing.quality.inspections.show', item.id))">
        <template #item.number="{ item }">
          <span class="font-weight-medium">{{ item.number }}</span>
          <div v-if="item.order" class="text-caption text-medium-emphasis">{{ item.order.number }}</div>
        </template>
        <template #item.stage="{ item }">{{ t(`manufacturing.quality.stage.${item.stage}`) }}</template>
        <template #item.item_code="{ item }">
          <div>{{ item.item_code }}</div>
          <div class="text-caption text-medium-emphasis">{{ item.item_name }}</div>
        </template>
        <template #item.lot="{ item }">{{ item.lot ?? '—' }}</template>
        <template #item.result="{ item }">
          <VChip size="small" label variant="tonal" :color="resultColor(item.result)">{{ t(`manufacturing.quality.result_${item.result}`) }}</VChip>
        </template>
        <template #item.status="{ item }">
          <VChip size="small" label variant="tonal" :color="inspectionStatusColor(item.status)">{{ t(`manufacturing.quality.status.${item.status}`) }}</VChip>
          <div v-if="item.disposition" class="text-caption">{{ t(`manufacturing.quality.disposition.${item.disposition}`) }}</div>
        </template>
        <template #no-data>
          <div class="py-8 text-medium-emphasis">{{ t('manufacturing.quality.none') }}</div>
        </template>
      </VDataTable>

      <VCardText v-if="inspections.last_page > 1" class="d-flex justify-center">
        <VPagination :model-value="inspections.current_page" :length="inspections.last_page" total-visible="7"
                     @update:model-value="(p: number) => apply({ page: p })" />
      </VCardText>
    </VCard>

    <!-- Receive for inspection -->
    <VDialog v-model="receiveDialog" max-width="680">
      <VCard :title="t('manufacturing.quality.receive')" :subtitle="t('manufacturing.quality.receive_hint')">
        <VCardText>
          <VRow dense>
            <VCol cols="12"><VAutocomplete v-model="receive.stock_item_id" :items="materialItems" :error-messages="receive.errors.stock_item_id" :label="t('manufacturing.orders.material')" /></VCol>
            <VCol cols="12" sm="6"><VSelect v-model="receive.warehouse_id" :items="warehouseItems" :error-messages="receive.errors.warehouse_id" :label="t('manufacturing.quality.warehouse')" /></VCol>
            <VCol cols="6" sm="3"><VTextField v-model.number="receive.qty" type="number" min="0" step="any" :error-messages="receive.errors.qty" :label="t('manufacturing.orders.quantity')" /></VCol>
            <VCol cols="6" sm="3"><VTextField v-model.number="receive.unit_cost" type="number" min="0" step="any" :error-messages="receive.errors.unit_cost" :label="t('manufacturing.orders.unit_cost')" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="receive.lot_number" :error-messages="receive.errors.lot_number" :label="t('manufacturing.quality.lot_number')" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="receive.supplier_lot_number" :label="t('manufacturing.quality.supplier_lot')" /></VCol>
            <VCol cols="12" sm="6"><VAutocomplete v-model="receive.supplier_id" :items="options?.suppliers ?? []" item-value="id" item-title="name" clearable :error-messages="receive.errors.supplier_id" :label="t('manufacturing.quality.supplier')" /></VCol>
            <VCol cols="12" sm="6"><VAutocomplete v-model="receive.purchase_order_id" :items="poItems" clearable :error-messages="receive.errors.purchase_order_id" :label="t('manufacturing.quality.purchase_order')" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="receive.expires_on" type="date" :label="t('manufacturing.quality.expires_on')" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="receive.reference" :label="t('manufacturing.quality.reference')" :hint="t('manufacturing.quality.reference_hint')" persistent-hint /></VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="receiveDialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="receive.processing"
                :disabled="!receive.stock_item_id || !receive.warehouse_id || !receive.qty || receive.unit_cost === null || !receive.lot_number" @click="submitReceive">
            {{ t('manufacturing.quality.receive_into_quarantine') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Hold stock -->
    <VDialog v-model="holdDialog" max-width="560">
      <VCard :title="t('manufacturing.quality.hold')" :subtitle="t('manufacturing.quality.hold_hint')">
        <VCardText>
          <VRow dense>
            <VCol cols="12"><VAutocomplete v-model="hold.stock_item_id" :items="materialItems" :error-messages="hold.errors.stock_item_id" :label="t('manufacturing.orders.material')" /></VCol>
            <VCol cols="12" sm="6"><VSelect v-model="hold.warehouse_id" :items="warehouseItems" :error-messages="hold.errors.warehouse_id" :label="t('manufacturing.quality.warehouse')" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model.number="hold.qty" type="number" min="0" step="any" :error-messages="hold.errors.qty" :label="t('manufacturing.orders.quantity')" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="hold.lot_number" :label="t('manufacturing.quality.lot_number')" :hint="t('manufacturing.quality.lot_optional')" persistent-hint /></VCol>
            <VCol cols="12" sm="6"><VSelect v-model="hold.stage" :items="stageItems" :label="t('manufacturing.quality.stage_label')" /></VCol>
            <VCol cols="12"><VTextarea v-model="hold.reason" rows="2" auto-grow :error-messages="hold.errors.reason" :label="t('manufacturing.orders.reason')" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="holdDialog = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="warning" :loading="hold.processing" :disabled="!hold.stock_item_id || !hold.warehouse_id || !hold.qty || !hold.reason.trim()" @click="submitHold">
            {{ t('manufacturing.quality.hold') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
