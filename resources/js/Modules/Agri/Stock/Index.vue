<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PageErrors from '../Components/PageErrors.vue'
import FormDialog from '../Components/FormDialog.vue'
import FarmFilter from '../Components/FarmFilter.vue'
import { human, money, num, today } from '../Components/agri'

const props = defineProps<{
  farm: null | { id: string; name: string; code: string; has_store: boolean }
  farms: Array<{ id: string; name: string }>
  items: any[]; movements: any[]; requisitions: any[]
  catalogue: Array<{ id: number; name: string; uom: string; avg_cost: number }>
  enterprises: Array<{ value: string; title: string }>
  can: { receive: boolean; sell: boolean }
}>()
const dialog = ref<null | string>(null)
const close = (v: boolean) => { if (!v) dialog.value = null }
const lines = ref([{ stock_item_id: null as number | null, quantity: null as number | null, unit_price: null as number | null }])
const req = ref({ justification: '', needed_by: '' })
const reqErrors = ref<Record<string, string>>({})
function openStore() { router.post(route('agri.stock.store.open'), { farm_id: props.farm?.id }, { preserveScroll: true }) }
function sendRequisition() {
  router.post(route('agri.stock.requisitions.store'), { farm_id: props.farm?.id, ...req.value, needed_by: req.value.needed_by || null, lines: lines.value }, {
    preserveScroll: true, onSuccess: () => { dialog.value = null; lines.value = [{ stock_item_id: null, quantity: null, unit_price: null }] }, onError: e => (reqErrors.value = e),
  })
}
const items = props.catalogue.map(i => ({ title: i.name, value: i.id }))
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-6">
      <div>
        <h4 class="text-h4 mb-1">Farm Stores</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">Seed, fertiliser, chemicals, feed, medicines and fuel held on each farm — the same stock as the company's inventory, never a second count.</p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <FarmFilter :farms="farms" :model-value="farm?.id" :allow-all="false" />
        <Link :href="route('agri.stock.control', { tab: 'stores', farm: farm?.id })"><VBtn variant="tonal" prepend-icon="tabler-adjustments-horizontal">Stock control & purchasing</VBtn></Link>
        <Link :href="route('agri.packhouse', { tab: 'lots', farm: farm?.id })"><VBtn variant="tonal" prepend-icon="tabler-package">Packhouse &amp; dispatch</VBtn></Link>
        <VMenu v-if="(can.receive || can.sell) && farm">
          <template #activator="{ props: p }"><VBtn v-bind="p" color="primary" prepend-icon="tabler-plus">Stock</VBtn></template>
          <VList density="compact">
            <VListItem v-if="can.receive" prepend-icon="tabler-truck-delivery" title="Receive stock / opening balance" @click="dialog = 'receive'" />
            <VListItem v-if="can.sell && items.length" prepend-icon="tabler-cash" title="Sell from store" @click="dialog = 'sell'" />
            <VListItem v-if="can.receive" prepend-icon="tabler-file-invoice" title="Raise purchase requisition" @click="dialog = 'requisition'" />
            <VListItem v-if="can.receive" prepend-icon="tabler-box" title="New stock item" @click="dialog = 'item'" />
          </VList>
        </VMenu>
      </div>
    </div>

    <VAlert v-if="farm && !farm.has_store" type="info" variant="tonal" class="mb-4">
      {{ farm.name }} has no store yet. It is opened automatically with the first receipt<template v-if="can.receive">, or <a href="#" @click.prevent="openStore">open it now</a></template>.
    </VAlert>

    <VCard title="On hand" class="mb-4">
      <VTable density="comfortable">
        <thead><tr><th>Item</th><th class="text-end">On hand</th><th class="text-end">Available</th><th class="text-end">Reorder at</th><th class="text-end">Avg cost</th><th class="text-end">Value</th><th>Last moved</th></tr></thead>
        <tbody>
          <tr v-for="i in items" :key="i.id" :class="i.low ? 'bg-warning-lighten' : ''">
            <td><strong>{{ i.name }}</strong><div class="text-caption">{{ i.code }}</div></td>
            <td class="text-end">{{ num(i.on_hand) }} {{ i.uom }}</td>
            <td class="text-end"><VChip v-if="i.low" color="warning" size="small" variant="tonal">{{ num(i.available) }}</VChip><span v-else>{{ num(i.available) }}</span></td>
            <td class="text-end">{{ i.reorder_level ? num(i.reorder_level) : '—' }}</td>
            <td class="text-end">{{ money(i.avg_cost) }}</td>
            <td class="text-end">{{ money(i.value) }}</td>
            <td>{{ i.last_moved }}</td>
          </tr>
          <tr v-if="!items.length"><td colspan="7" class="text-center text-medium-emphasis py-6">Nothing in this store yet.</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <VRow>
      <VCol cols="12" md="7">
        <VCard title="Recent movements">
          <VTable density="compact">
            <thead><tr><th>Date</th><th>Item</th><th>Type</th><th class="text-end">Qty</th><th class="text-end">Value</th><th>Reference</th></tr></thead>
            <tbody>
              <tr v-for="(m, i) in movements" :key="i">
                <td>{{ m.date }}</td><td>{{ m.item }}</td><td>{{ human(m.type) }}<div class="text-caption">{{ m.source ? human(m.source.replace('agri_', '')) : '' }}</div></td>
                <td class="text-end" :class="m.qty < 0 ? 'text-error' : 'text-success'">{{ m.qty > 0 ? '+' : '' }}{{ num(m.qty) }} {{ m.uom }}</td>
                <td class="text-end">{{ money(Math.abs(m.value)) }}</td><td class="text-caption">{{ m.reference }}</td>
              </tr>
              <tr v-if="!movements.length"><td colspan="6" class="text-center text-medium-emphasis py-4">No movements yet.</td></tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
      <VCol cols="12" md="5">
        <VCard title="Purchase requisitions">
          <VList v-if="requisitions.length" density="compact">
            <VListItem v-for="r in requisitions" :key="r.uuid" :title="r.request_number" :subtitle="`${human(r.status)} · needed ${r.needed_by ?? '—'}`">
              <template #append>{{ money(Number(r.estimated_total)) }}</template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-medium-emphasis">None raised from this farm. Requisitions go to Procurement for approval, ordering and receiving.</VCardText>
        </VCard>
      </VCol>
    </VRow>

    <FormDialog v-if="farm" :model-value="dialog === 'receive'" :title="`Receive into ${farm.name} store`" :url="route('agri.stock.receive')"
      :values="{ farm_id: farm.id, received_on: today(), basis: 'purchase' }" :fields="[
        { key: 'stock_item_id', label: 'Item', type: 'autocomplete', required: true, items, cols: 12 },
        { key: 'quantity', label: 'Quantity', type: 'number', required: true, cols: 4 },
        { key: 'unit_cost', label: 'Unit cost', type: 'number', required: true, cols: 4 },
        { key: 'received_on', label: 'Date', type: 'date', required: true, cols: 4 },
        { key: 'basis', label: 'What is this', type: 'select', required: true, items: [{ title: 'Delivery from a supplier (owed)', value: 'purchase' }, { title: 'Opening balance', value: 'opening' }] },
        { key: 'reference', label: 'Delivery note / reference' },
      ]" intro="Posts to the shared ledger: Dr Inventory, Cr Accounts payable (or retained earnings for an opening balance)." @update:model-value="close" />
    <FormDialog v-if="farm" :model-value="dialog === 'sell'" :title="`Sell from ${farm.name} store`" :url="route('agri.stock.sell')"
      :values="{ farm_id: farm.id, sold_on: today(), settled_by: 'receivable' }" :fields="[
        { key: 'stock_item_id', label: 'What is sold', type: 'select', required: true, items: props.items.map(i => ({ title: `${i.name} (${num(i.available)} ${i.uom} available)`, value: i.id })), cols: 12 },
        { key: 'quantity', label: 'Quantity', type: 'number', required: true, cols: 4 },
        { key: 'amount', label: 'Sale amount (total)', type: 'number', required: true, cols: 4 },
        { key: 'sold_on', label: 'Date', type: 'date', required: true, cols: 4 },
        { key: 'description', label: 'Description', required: true, cols: 12 },
        { key: 'buyer', label: 'Buyer' },
        { key: 'settled_by', label: 'Paid', type: 'select', items: [{ title: 'Received in the bank', value: 'bank' }, { title: 'Owed by the buyer', value: 'receivable' }] },
        { key: 'enterprise', label: 'Crop or herd it came from', type: 'select', items: enterprises, cols: 12 },
      ]" intro="The stock leaves the store at what it is carried at, and the sale posts as farm income — both on this farm." @update:model-value="close" />
    <FormDialog :model-value="dialog === 'item'" title="New stock item" :url="route('agri.stock.items.store')" :fields="[
        { key: 'code', label: 'Code', required: true, cols: 4 }, { key: 'name', label: 'Name', required: true, cols: 8 },
        { key: 'uom', label: 'Unit', required: true, hint: 'kg, l, bag, dose…', cols: 4 }, { key: 'standard_cost', label: 'Standard cost', type: 'number', cols: 4 },
        { key: 'reorder_level', label: 'Reorder level', type: 'number', cols: 4 },
      ]" @update:model-value="close" />

    <VDialog :model-value="dialog === 'requisition'" max-width="760" @update:model-value="close">
      <VCard title="Purchase requisition">
        <VCardText>
          <p class="text-body-2 text-medium-emphasis">Drafted in Procurement for {{ farm?.name }}; it is approved, ordered and received there.</p>
          <VRow>
            <VCol cols="12" sm="8"><VTextField v-model="req.justification" label="What is it for? *" :error-messages="reqErrors.justification" /></VCol>
            <VCol cols="12" sm="4"><VTextField v-model="req.needed_by" type="date" label="Needed by" :error-messages="reqErrors.needed_by" /></VCol>
          </VRow>
          <VRow v-for="(l, i) in lines" :key="i" dense>
            <VCol cols="12" sm="6"><VAutocomplete v-model="l.stock_item_id" :items="items" label="Item" density="compact" :error-messages="reqErrors[`lines.${i}.stock_item_id`]" /></VCol>
            <VCol cols="5" sm="2"><VTextField v-model.number="l.quantity" type="number" label="Qty" density="compact" :error-messages="reqErrors[`lines.${i}.quantity`]" /></VCol>
            <VCol cols="5" sm="3"><VTextField v-model.number="l.unit_price" type="number" label="Price" density="compact" /></VCol>
            <VCol cols="2" sm="1"><VBtn icon variant="text" size="small" :disabled="lines.length === 1" @click="lines.splice(i, 1)"><VIcon icon="tabler-x" /></VBtn></VCol>
          </VRow>
          <VBtn variant="text" size="small" prepend-icon="tabler-plus" @click="lines.push({ stock_item_id: null, quantity: null, unit_price: null })">Line</VBtn>
          <VAlert v-if="reqErrors.lines" type="error" variant="tonal" density="compact" class="mt-2">{{ reqErrors.lines }}</VAlert>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="dialog = null">Cancel</VBtn><VBtn color="primary" @click="sendRequisition">Draft requisition</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
