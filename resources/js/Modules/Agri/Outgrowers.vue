<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PageErrors from './Components/PageErrors.vue'
import FormDialog, { type FormField } from './Components/FormDialog.vue'
import FarmFilter from './Components/FarmFilter.vue'
import { human, money, num, statusColor, today } from './Components/agri'

/** Phase 2 §16D: growers, contracts, advances, inspections, collections, settlements. */
const props = defineProps<{
  farm: null | { id: string; name: string }
  farms: Array<{ id: string; name: string }>
  growers: any[]; contracts: any[]; advances: any[]; inspections: any[]; collections: any[]; settlements: any[]; forecast: any[]
  options: { stores: any[]; items: any[] }
  can: { manage: boolean; approve: boolean }
}>()
const tab = ref('growers')
const keep = { preserveScroll: true }
const dialog = ref<null | { kind: string; row?: any }>(null)
const close = (v: boolean) => { if (!v) dialog.value = null }
const growerFields: FormField[] = [{ key: 'name', label: 'Name', required: true, cols: 8 }, { key: 'code', label: 'Code', required: true, cols: 4 },
  { key: 'phone', label: 'Phone', cols: 6 }, { key: 'hectares', label: 'Hectares', type: 'number', cols: 6 }, { key: 'location', label: 'Where', cols: 12 },
  { key: 'bank_name', label: 'Bank', cols: 6 }, { key: 'bank_account', label: 'Account', cols: 6 }]
const contractFields: FormField[] = [{ key: 'product', label: 'Product', required: true, cols: 6 }, { key: 'hectares', label: 'Hectares', type: 'number', cols: 6 },
  { key: 'expected_quantity', label: 'Expected quantity', type: 'number', required: true, cols: 4 }, { key: 'unit', label: 'Unit', required: true, cols: 2 },
  { key: 'price', label: 'Price per unit', type: 'number', required: true, cols: 3 }, { key: 'advance_limit', label: 'Advance limit', type: 'number', cols: 3 }]
const advanceFields: FormField[] = [{ key: 'kind', label: 'Advance', type: 'select', required: true, items: [{ title: 'Inputs from the store', value: 'input' }, { title: 'Cash', value: 'cash' }] },
  { key: 'stock_item_id', label: 'Item', type: 'autocomplete', items: props.options.items, show: (f: any) => f.kind === 'input', cols: 8 },
  { key: 'quantity', label: 'Quantity', type: 'number', show: (f: any) => f.kind === 'input', cols: 4 }, { key: 'amount', label: 'Amount', type: 'number', show: (f: any) => f.kind === 'cash' },
  { key: 'issued_on', label: 'Date', type: 'date' }]
const inspectFields: FormField[] = [{ key: 'inspected_on', label: 'Date', type: 'date', required: true, cols: 6 }, { key: 'stage', label: 'Crop stage', cols: 6 },
  { key: 'estimated_quantity', label: 'Estimated crop', type: 'number', cols: 6 },
  { key: 'outcome', label: 'Outcome', type: 'select', required: true, items: ['satisfactory', 'concern', 'failed'].map(x => ({ title: human(x), value: x })), cols: 6 },
  { key: 'notes', label: 'Notes', type: 'textarea', cols: 12 }]
const collectFields: FormField[] = [{ key: 'store_id', label: 'Into store', type: 'select', required: true, items: props.options.stores, cols: 6 },
  { key: 'stock_item_id', label: 'Stock item', type: 'autocomplete', required: true, items: props.options.items, cols: 6 },
  { key: 'quantity', label: 'Delivered', type: 'number', required: true, cols: 4 }, { key: 'rejected', label: 'Rejected', type: 'number', cols: 4 }, { key: 'grade', label: 'Grade', cols: 4 },
  { key: 'collected_on', label: 'Date', type: 'date', cols: 6 }, { key: 'location', label: 'Bin / bay', cols: 6 }]
const approve = (c: any) => router.post(route('agri.outgrowers.contracts.approve', c.uuid), {}, keep)
const settle = (g: any) => router.post(route('agri.outgrowers.settle', g.uuid), {}, keep)
const approveSettlement = (s: any) => router.post(route('agri.outgrowers.settlements.approve', s.uuid), {}, keep)
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div><h4 class="text-h4 mb-1">Outgrowers</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">Growers are paid for what they deliver, less the advances approved for them — all through Finance.</p></div>
      <div class="d-flex gap-2"><VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="dialog = { kind: 'grower' }">Grower</VBtn>
        <FarmFilter :farms="farms" :model-value="farm?.id" :allow-all="false" /></div>
    </div>
    <VTabs v-model="tab" class="mb-4"><VTab value="growers">Growers</VTab><VTab value="contracts">Contracts</VTab><VTab value="forecast">Forecast</VTab>
      <VTab value="collections">Collections</VTab><VTab value="settlements">Settlements</VTab></VTabs>

    <VCard v-if="tab === 'growers'"><VTable density="compact">
      <thead><tr><th>Grower</th><th class="text-end">Advanced</th><th class="text-end">Outstanding</th><th class="text-end">Collected</th><th class="text-end">To pay</th><th /></tr></thead>
      <tbody><tr v-for="g in growers" :key="g.id"><td>{{ g.code }} {{ g.name }}<div class="text-caption">{{ g.location }}<template v-if="g.hectares"> · {{ g.hectares }} ha</template></div></td>
        <td class="text-end">{{ money(g.advanced) }}</td><td class="text-end">{{ money(g.advances_outstanding) }}</td><td class="text-end">{{ money(g.collected_value) }}</td><td class="text-end">{{ money(g.to_pay) }}</td>
        <td class="text-end text-no-wrap"><template v-if="can.manage"><VBtn size="x-small" variant="text" @click="dialog = { kind: 'contract', row: g }">Contract</VBtn>
          <VBtn size="x-small" variant="tonal" @click="settle(g)">Settle</VBtn></template></td></tr>
        <tr v-if="!growers.length"><td colspan="6" class="text-center text-medium-emphasis py-4">No growers.</td></tr></tbody>
    </VTable></VCard>

    <VCard v-if="tab === 'contracts'"><VTable density="compact">
      <thead><tr><th>Contract</th><th>Grower</th><th>Crop</th><th class="text-end">Price</th><th>Status</th><th /></tr></thead>
      <tbody><tr v-for="c in contracts" :key="c.id"><td>{{ c.reference }}</td><td>{{ c.grower }}</td><td>{{ num(c.expected_quantity) }} {{ c.unit }} {{ c.product }}</td><td class="text-end">{{ money(c.price) }}</td>
        <td><VChip size="x-small" :color="statusColor(c.status)">{{ human(c.status) }}</VChip></td>
        <td class="text-end text-no-wrap">
          <VBtn v-if="can.approve && c.status === 'draft'" size="x-small" color="success" variant="tonal" @click="approve(c)">Approve</VBtn>
          <template v-if="can.manage && c.status === 'active'"><VBtn size="x-small" variant="text" @click="dialog = { kind: 'advance', row: c }">Advance</VBtn>
            <VBtn size="x-small" variant="text" @click="dialog = { kind: 'inspect', row: c }">Inspect</VBtn><VBtn size="x-small" variant="tonal" @click="dialog = { kind: 'collect', row: c }">Collect</VBtn></template></td></tr>
        <tr v-if="!contracts.length"><td colspan="6" class="text-center text-medium-emphasis py-4">No contracts.</td></tr></tbody>
    </VTable></VCard>

    <VCard v-if="tab === 'forecast'" subtitle="The latest inspection estimate, else the contract, less what has been collected."><VTable density="compact">
      <thead><tr><th>Contract</th><th>Grower</th><th class="text-end">Expected</th><th>Basis</th><th class="text-end">Collected</th><th class="text-end">Still to come</th></tr></thead>
      <tbody><tr v-for="f in forecast" :key="f.contract"><td>{{ f.contract }}</td><td>{{ f.grower }}</td><td class="text-end">{{ num(f.expected) }} {{ f.unit }}</td><td class="text-caption">{{ f.basis }}</td>
        <td class="text-end">{{ num(f.collected) }}</td><td class="text-end">{{ num(f.to_come) }}</td></tr></tbody>
    </VTable></VCard>

    <VCard v-if="tab === 'collections'"><VTable density="compact">
      <thead><tr><th>Date</th><th>Grower</th><th class="text-end">Delivered</th><th class="text-end">Rejected</th><th>Grade</th><th class="text-end">Value</th><th>Settled</th></tr></thead>
      <tbody><tr v-for="c in collections" :key="c.id"><td>{{ String(c.collected_on).slice(0, 10) }}</td><td>{{ c.grower }}</td><td class="text-end">{{ num(c.quantity) }}</td><td class="text-end">{{ num(c.rejected) }}</td>
        <td>{{ c.grade }}</td><td class="text-end">{{ money(c.value) }}</td><td>{{ c.settlement_id ? 'Yes' : '' }}</td></tr></tbody>
    </VTable></VCard>

    <VCard v-if="tab === 'settlements'"><VTable density="compact">
      <thead><tr><th>Settlement</th><th>Grower</th><th class="text-end">Gross</th><th class="text-end">Advances recovered</th><th class="text-end">Net</th><th>Status</th><th /></tr></thead>
      <tbody><tr v-for="s in settlements" :key="s.id"><td>{{ s.reference }}</td><td>{{ s.grower }}</td><td class="text-end">{{ money(s.gross) }}</td><td class="text-end">{{ money(s.advances_recovered) }}</td>
        <td class="text-end font-weight-medium">{{ money(s.net) }}</td><td><VChip size="x-small" :color="statusColor(s.status)">{{ human(s.status) }}</VChip></td>
        <td class="text-end"><VBtn v-if="can.approve && s.status === 'draft'" size="x-small" color="success" variant="tonal" @click="approveSettlement(s)">Approve</VBtn></td></tr></tbody>
    </VTable></VCard>

    <FormDialog v-if="dialog?.kind === 'grower'" :model-value="true" title="Grower" :url="route('agri.outgrowers.store')" :fields="growerFields" :values="{ farm_id: farm?.id }" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'contract'" :model-value="true" :title="`Contract for ${dialog.row.code}`" :url="route('agri.outgrowers.contracts.store', dialog.row.uuid)" :fields="contractFields" :values="{ unit: 'kg' }" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'advance'" :model-value="true" :title="`Advance on ${dialog.row.reference}`" :url="route('agri.outgrowers.advances.store', dialog.row.uuid)" :fields="advanceFields" :values="{ kind: 'input', issued_on: today() }" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'inspect'" :model-value="true" :title="`Inspection, ${dialog.row.reference}`" :url="route('agri.outgrowers.inspections.store', dialog.row.uuid)" :fields="inspectFields" :values="{ inspected_on: today(), outcome: 'satisfactory' }" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'collect'" :model-value="true" :title="`Collection, ${dialog.row.reference}`" :url="route('agri.outgrowers.collections.store', dialog.row.uuid)" :fields="collectFields" :values="{ collected_on: today() }" @update:model-value="close" />
  </div>
</template>
