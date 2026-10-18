<script lang="ts" setup>
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const { t } = useI18n()

interface Balance { warehouse_id: number; qty: number }
interface Item { id: number; code: string; name: string; method: string; qty_on_hand: number; avg_cost: number; value: number; reorder_level: number; low_stock: boolean; balances: Balance[] }
interface Warehouse { id: number; code: string; name: string; is_default: boolean }
const props = defineProps<{ items: Item[]; methods: Record<string, string>; summary: { count: number; value: number }; accounts: { id: number; code: string; label: string }[]; warehouses: Warehouse[] }>()

const money = (n: number) => (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const qty = (n: number) => (n ?? 0).toLocaleString('en-ZA', { maximumFractionDigits: 4 })
const lowStockCount = computed(() => props.items.filter(i => i.low_stock).length)

// Warehouse filter — 'all' shows total on-hand; a specific warehouse shows that location's qty.
const defaultWh = computed(() => props.warehouses.find(w => w.is_default)?.id ?? props.warehouses[0]?.id ?? null)
const whFilter = ref<number | 'all'>('all')
const moveWh = computed(() => (whFilter.value === 'all' ? defaultWh.value : whFilter.value))
function itemQty(i: Item): number {
  if (whFilter.value === 'all') return i.qty_on_hand
  return i.balances.find(b => b.warehouse_id === whFilter.value)?.qty ?? 0
}
const whItems = ['all', ...props.warehouses.map(w => w.id)].map(v => ({ title: v === 'all' ? t('acc.all_warehouses') : (props.warehouses.find(w => w.id === v)?.name ?? ''), value: v }))

// New item
const dialog = ref(false)
const form = useForm<any>({ code: '', name: '', cost_method: 'weighted_avg', uom: '', standard_cost: 0, reorder_level: 0, inventory_account_id: null, cogs_account_id: null })
function openNew() { form.reset(); form.cost_method = 'weighted_avg'; form.standard_cost = 0; form.reorder_level = 0; dialog.value = true }
function save() { form.post(route('finance.accounting.inventory.store'), { preserveScroll: true, onSuccess: () => { dialog.value = false } }) }

// Adjust
const adjDialog = ref(false)
const adjItem = ref<Item | null>(null)
const adjForm = useForm<any>({ qty_delta: null, unit_cost: null, reason: '', date: new Date().toISOString().slice(0, 10), warehouse_id: null })
function openAdjust(item: Item) { adjItem.value = item; adjForm.reset(); adjForm.date = new Date().toISOString().slice(0, 10); adjForm.unit_cost = item.avg_cost; adjForm.warehouse_id = moveWh.value; adjDialog.value = true }
function submitAdjust() { adjForm.post(route('finance.accounting.inventory.adjust', adjItem.value!.id), { preserveScroll: true, onSuccess: () => { adjDialog.value = false } }) }

// Receive / issue
const moveDialog = ref(false)
const moveType = ref<'receive' | 'issue'>('receive')
const moveItem = ref<Item | null>(null)
const moveForm = useForm<any>({ qty: null, unit_cost: null, date: new Date().toISOString().slice(0, 10), contra: '', reference: '', warehouse_id: null })
function openMove(item: Item, type: 'receive' | 'issue') { moveItem.value = item; moveType.value = type; moveForm.reset(); moveForm.date = new Date().toISOString().slice(0, 10); moveForm.warehouse_id = moveWh.value; moveDialog.value = true }
function submitMove() {
  const r = moveType.value === 'receive' ? 'finance.accounting.inventory.receive' : 'finance.accounting.inventory.issue'
  moveForm.post(route(r, moveItem.value!.id), { preserveScroll: true, onSuccess: () => { moveDialog.value = false } })
}

// Transfer between warehouses
const xferDialog = ref(false)
const xferItem = ref<Item | null>(null)
const xferForm = useForm<any>({ qty: null, from_id: null, to_id: null, date: new Date().toISOString().slice(0, 10), reference: '' })
function openTransfer(item: Item) {
  xferItem.value = item; xferForm.reset(); xferForm.date = new Date().toISOString().slice(0, 10)
  xferForm.from_id = (whFilter.value === 'all' ? defaultWh.value : whFilter.value)
  xferForm.to_id = props.warehouses.find(w => w.id !== xferForm.from_id)?.id ?? null
  xferDialog.value = true
}
function submitTransfer() { xferForm.post(route('finance.accounting.inventory.transfer', xferItem.value!.id), { preserveScroll: true, onSuccess: () => { xferDialog.value = false } }) }
const whSelectItems = computed(() => props.warehouses.map(w => ({ title: w.name, value: w.id })))
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('acc.finance') }} · {{ t('acc.crumb_inventory') }}</div>
        <h2 class="text-h5 mb-0">{{ t('acc.inventory') }}</h2>
        <div class="text-caption text-medium-emphasis">{{ t('acc.inventory_sub') }}</div>
      </div>
      <div class="d-flex align-center gap-2 flex-wrap">
        <VSelect v-model="whFilter" :items="whItems" density="compact" variant="outlined" hide-details prepend-inner-icon="tabler-building-warehouse" style="min-width: 190px" />
        <VBtn variant="tonal" prepend-icon="tabler-building-warehouse" @click="router.visit(route('finance.accounting.warehouses'))">{{ t('acc.warehouses') }}</VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="openNew">{{ t('acc.new_stock_item') }}</VBtn>
      </div>
    </div>

    <VRow class="mb-2">
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('acc.stock_items') }}</div><div class="text-h6">{{ summary.count }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('acc.total_stock_value') }}</div><div class="text-h6 text-success">{{ money(summary.value) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border :color="lowStockCount ? 'warning' : undefined" :variant="lowStockCount ? 'tonal' : undefined"><VCardText><div class="text-caption text-medium-emphasis">{{ t('acc.low_stock') }}</div><div class="text-h6">{{ lowStockCount }}</div></VCardText></VCard></VCol>
    </VRow>

    <VCard border>
      <VTable density="compact">
        <thead><tr><th>{{ t('acc.code') }}</th><th>{{ t('acc.item') }}</th><th>{{ t('acc.method') }}</th><th class="text-right">{{ t('acc.on_hand') }}</th><th class="text-right">{{ t('acc.reorder_at') }}</th><th class="text-right">{{ t('acc.avg_cost') }}</th><th class="text-right">{{ t('acc.value') }}</th><th></th></tr></thead>
        <tbody>
          <tr v-if="!items.length"><td colspan="8" class="text-center text-medium-emphasis py-8">{{ t('acc.no_stock_items') }}</td></tr>
          <tr v-for="i in items" :key="i.id">
            <td class="text-caption">{{ i.code }}</td>
            <td class="font-weight-medium">{{ i.name }}</td>
            <td class="text-caption">{{ methods[i.method] ?? i.method }}</td>
            <td class="text-right">
              {{ qty(itemQty(i)) }}
              <span v-if="whFilter !== 'all' && itemQty(i) !== i.qty_on_hand" class="text-caption text-disabled">/ {{ qty(i.qty_on_hand) }}</span>
              <VChip v-if="i.low_stock" size="x-small" color="warning" variant="flat" class="ms-1">{{ t('acc.low_stock') }}</VChip>
            </td>
            <td class="text-right text-caption text-medium-emphasis">{{ i.reorder_level > 0 ? qty(i.reorder_level) : '—' }}</td>
            <td class="text-right text-caption">{{ money(i.avg_cost) }}</td>
            <td class="text-right font-weight-medium">{{ money(i.value) }}</td>
            <td class="text-right" style="white-space: nowrap">
              <VBtn size="x-small" variant="tonal" color="success" class="me-1" @click="openMove(i, 'receive')">{{ t('acc.receive') }}</VBtn>
              <VBtn size="x-small" variant="tonal" color="warning" class="me-1" :disabled="i.qty_on_hand <= 0" @click="openMove(i, 'issue')">{{ t('acc.issue') }}</VBtn>
              <VBtn v-if="warehouses.length > 1" size="x-small" variant="tonal" color="info" class="me-1" @click="openTransfer(i)">{{ t('acc.transfer') }}</VBtn>
              <VBtn size="x-small" variant="tonal" @click="openAdjust(i)">{{ t('acc.adjust') }}</VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <!-- New item -->
    <VDialog v-model="dialog" max-width="560">
      <VCard>
        <VCardTitle>{{ t('acc.new_stock_item') }}</VCardTitle>
        <VCardText>
          <VAlert v-if="(form.errors as any).item" type="error" variant="tonal" density="compact" class="mb-3">{{ (form.errors as any).item }}</VAlert>
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model="form.code" :label="t('acc.code_sku')" variant="outlined" density="comfortable" style="max-width: 180px" :error-messages="form.errors.code" />
            <VTextField v-model="form.name" :label="t('acc.name')" variant="outlined" density="comfortable" :error-messages="form.errors.name" />
          </div>
          <div class="d-flex gap-3 mb-3">
            <VSelect v-model="form.cost_method" :items="Object.entries(methods).map(([v, m]) => ({ title: m, value: v }))" :label="t('acc.cost_method')" variant="outlined" density="comfortable" />
            <VTextField v-model="form.uom" :label="t('acc.unit')" variant="outlined" density="comfortable" style="max-width: 120px" />
            <VTextField v-if="form.cost_method === 'standard'" v-model.number="form.standard_cost" type="number" :label="t('acc.standard_cost')" prefix="R" variant="outlined" density="comfortable" style="max-width: 160px" />
          </div>
          <VTextField v-model.number="form.reorder_level" type="number" :label="t('acc.reorder_level')" :hint="t('acc.reorder_hint')" persistent-hint variant="outlined" density="comfortable" style="max-width: 220px" />
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('acc.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" :disabled="!form.code || !form.name" @click="save">{{ t('acc.create') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Receive / issue -->
    <VDialog v-model="moveDialog" max-width="480">
      <VCard>
        <VCardTitle>{{ moveType === 'receive' ? t('acc.receive') : t('acc.issue') }} — {{ moveItem?.name }}</VCardTitle>
        <VCardText>
          <VAlert v-if="(moveForm.errors as any).item" type="error" variant="tonal" density="compact" class="mb-3">{{ (moveForm.errors as any).item }}</VAlert>
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model.number="moveForm.qty" type="number" :label="t('acc.quantity')" variant="outlined" density="comfortable" :error-messages="moveForm.errors.qty" />
            <VTextField v-if="moveType === 'receive'" v-model.number="moveForm.unit_cost" type="number" :label="t('acc.unit_cost')" prefix="R" variant="outlined" density="comfortable" :error-messages="moveForm.errors.unit_cost" />
          </div>
          <VSelect v-if="warehouses.length" v-model="moveForm.warehouse_id" :items="whSelectItems" :label="moveType === 'receive' ? t('acc.receive_into') : t('acc.issue_from')" variant="outlined" density="comfortable" class="mb-3" prepend-inner-icon="tabler-building-warehouse" />
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model="moveForm.date" type="date" :label="t('acc.date')" variant="outlined" density="comfortable" />
            <VTextField v-model="moveForm.reference" :label="t('acc.reference')" variant="outlined" density="comfortable" />
          </div>
          <VSelect v-if="moveType === 'receive'" v-model="moveForm.contra" :items="accounts.map(a => ({ title: a.label, value: a.code }))" :label="t('acc.funded_from_ap')" clearable variant="outlined" density="comfortable" />
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="moveDialog = false">{{ t('acc.cancel') }}</VBtn>
          <VBtn :color="moveType === 'receive' ? 'success' : 'warning'" :loading="moveForm.processing" :disabled="!moveForm.qty || (moveType === 'receive' && moveForm.unit_cost == null)" @click="submitMove">{{ moveType === 'receive' ? t('acc.receive') : t('acc.issue') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Adjust -->
    <VDialog v-model="adjDialog" max-width="480">
      <VCard>
        <VCardTitle>{{ t('acc.adjust_stock') }} — {{ adjItem?.name }}</VCardTitle>
        <VCardText>
          <VAlert v-if="(adjForm.errors as any).item" type="error" variant="tonal" density="compact" class="mb-3">{{ (adjForm.errors as any).item }}</VAlert>
          <VAlert type="info" variant="tonal" density="compact" class="mb-3">{{ t('acc.adjust_hint') }}</VAlert>
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model.number="adjForm.qty_delta" type="number" :label="t('acc.qty_change')" :hint="t('acc.qty_change_hint')" persistent-hint variant="outlined" density="comfortable" :error-messages="adjForm.errors.qty_delta" />
            <VTextField v-model.number="adjForm.unit_cost" type="number" :label="t('acc.unit_cost')" prefix="R" variant="outlined" density="comfortable" :error-messages="adjForm.errors.unit_cost" />
          </div>
          <VSelect v-if="warehouses.length" v-model="adjForm.warehouse_id" :items="whSelectItems" :label="t('acc.warehouse')" variant="outlined" density="comfortable" class="mb-3" prepend-inner-icon="tabler-building-warehouse" />
          <div class="d-flex gap-3">
            <VTextField v-model="adjForm.reason" :label="t('acc.reason')" variant="outlined" density="comfortable" :error-messages="adjForm.errors.reason" />
            <VTextField v-model="adjForm.date" type="date" :label="t('acc.date')" variant="outlined" density="comfortable" style="max-width: 180px" />
          </div>
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="adjDialog = false">{{ t('acc.cancel') }}</VBtn>
          <VBtn color="primary" :loading="adjForm.processing" :disabled="!adjForm.qty_delta" @click="submitAdjust">{{ t('acc.adjust') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Transfer between warehouses -->
    <VDialog v-model="xferDialog" max-width="480">
      <VCard>
        <VCardTitle>{{ t('acc.transfer_stock') }} — {{ xferItem?.name }}</VCardTitle>
        <VCardText>
          <VAlert v-if="(xferForm.errors as any).item" type="error" variant="tonal" density="compact" class="mb-3">{{ (xferForm.errors as any).item }}</VAlert>
          <div class="d-flex gap-3 mb-3">
            <VSelect v-model="xferForm.from_id" :items="whSelectItems" :label="t('acc.from_warehouse')" variant="outlined" density="comfortable" :error-messages="xferForm.errors.from_id" />
            <VIcon icon="tabler-arrow-right" class="align-self-center" />
            <VSelect v-model="xferForm.to_id" :items="whSelectItems" :label="t('acc.to_warehouse')" variant="outlined" density="comfortable" :error-messages="xferForm.errors.to_id" />
          </div>
          <div class="d-flex gap-3">
            <VTextField v-model.number="xferForm.qty" type="number" :label="t('acc.quantity')" variant="outlined" density="comfortable" :error-messages="xferForm.errors.qty" />
            <VTextField v-model="xferForm.date" type="date" :label="t('acc.date')" variant="outlined" density="comfortable" style="max-width: 180px" />
          </div>
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="xferDialog = false">{{ t('acc.cancel') }}</VBtn>
          <VBtn color="info" :loading="xferForm.processing" :disabled="!xferForm.qty || !xferForm.from_id || !xferForm.to_id || xferForm.from_id === xferForm.to_id" @click="submitTransfer">{{ t('acc.transfer') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
