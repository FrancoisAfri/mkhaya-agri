<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PageErrors from '../Components/PageErrors.vue'
import FormDialog, { type FormField } from '../Components/FormDialog.vue'
import FarmFilter from '../Components/FarmFilter.vue'
import { human, money, num, statusColor, today } from '../Components/agri'

/**
 * Phase 2 §9: what is particular to selling a crop — contracts, the contract position, market prices
 * and the marketplace. Customers, price lists, quotes, orders and disputes are Accounting's screens.
 */
const props = defineProps<{
  tab: 'contracts' | 'position' | 'market' | 'marketplace'
  farm: null | { id: string; name: string }
  farms: Array<{ id: string; name: string }>
  buyers: Array<{ id: number; name: string; channel: string | null; verified: boolean }>
  accounting: { customers: string; price_lists: string; quotes: string; orders: string; disputes: string; debtors: string }
  data: any
  options: { crop_types: any[]; standards: any[]; plans: any[]; lots: any[] }
  can: { manage: boolean; approve: boolean; dispatch: boolean }
}>()
const TABS = [
  { key: 'contracts', title: 'Contracts', icon: 'tabler-file-certificate' }, { key: 'position', title: 'Contract position', icon: 'tabler-scale' },
  { key: 'market', title: 'Market prices', icon: 'tabler-chart-line' }, { key: 'marketplace', title: 'Listings & requests', icon: 'tabler-building-bank' },
]
// Selling itself is done in Accounting, charged to the farm.
const ACCOUNTING = [
  { key: 'customers', title: 'Customers', icon: 'tabler-building-store' }, { key: 'price_lists', title: 'Price lists', icon: 'tabler-list-numbers' },
  { key: 'quotes', title: 'Quotes', icon: 'tabler-file-dollar' }, { key: 'orders', title: 'Sales orders', icon: 'tabler-clipboard-list' },
  { key: 'disputes', title: 'Disputes', icon: 'tabler-message-report' }, { key: 'debtors', title: 'Who owes what', icon: 'tabler-cash' },
] as const
const keep = { preserveScroll: true }
const go = (tab: string, extra: Record<string, any> = {}) => router.get(route('agri.sales', tab), { farm: props.farm?.id, ...extra }, { preserveState: false })
const dialog = ref<null | { kind: string; row?: any }>(null)
const open = (kind: string, row: any = null) => { dialog.value = { kind, row } }
const close = (v: boolean) => { if (!v) dialog.value = null }
const buyerItems = props.buyers.map(b => ({ title: `${b.name}${b.verified ? ' ✓' : ''}`, value: b.id }))
const opts = (xs: string[]) => xs.map(x => ({ title: human(x), value: x }))

// contracts
const ct = ref<any>(null)
const newContract = () => {
  ct.value = { party_id: null, crop_type_id: null, product: '', quantity: null, unit: 'kg', tolerance_pct: 5, windows: [{ from: today(), to: today(), quantity: null, location: '' }],
    standard_id: null, accepted_grades: [], quality_spec: '', pricing: 'fixed', price: null, formula: { market: '', product: '', unit: 'kg', lookback_days: 7, adjust_pct: 0, premium: 0, floor: null, cap: null },
    packaging: '', transport: 'farm', rejection_rules: '', dispute_procedure: '', advance_amount: null, input_financing: [] }
}
const saveContract = () => router.post(route('agri.sales.contracts.store'), { ...ct.value, farm_id: props.farm?.id, formula: ct.value.pricing === 'formula' ? ct.value.formula : null },
  { ...keep, onSuccess: () => { ct.value = null } })
const setStatus = (c: any, status: string) => router.post(route('agri.sales.contracts.status', c.uuid), { status }, keep)
const amendFields = (c: any): FormField[] => [
  { key: 'quantity', label: 'Quantity', type: 'number', cols: 6 }, { key: 'price', label: 'Fixed price', type: 'number', cols: 6, show: () => c.pricing === 'fixed' },
  { key: 'quality_spec', label: 'Quality specification', type: 'textarea', cols: 12 }, { key: 'reason', label: 'Why', type: 'textarea', required: true, cols: 12 },
]
const decideAmendment = (a: any, approve: boolean) => router.post(route('agri.sales.amendments.decide', a.uuid), { approve }, keep)
const orderFields: FormField[] = [{ key: 'deliver_on', label: 'Deliver on', type: 'date', required: true, cols: 6 }, { key: 'grade', label: 'Grade', cols: 3 }, { key: 'quantity', label: 'Quantity', type: 'number', required: true, cols: 3 }]
const orderOn = ref<any>(null)
const sendOrder = (values: any) => router.post(route('agri.sales.contracts.orders', orderOn.value.uuid), { farm_id: props.farm?.id, deliver_on: values.deliver_on,
  lines: [{ grade: values.grade || null, quantity: values.quantity }] }, { ...keep, onSuccess: () => { orderOn.value = null } })
const orderVals = ref<any>({ deliver_on: today(), grade: '', quantity: null })


// market
const priceFields: FormField[] = [
  { key: 'product', label: 'Product', required: true, cols: 6 }, { key: 'grade', label: 'Grade', cols: 6 }, { key: 'market', label: 'Market', required: true, cols: 6 },
  { key: 'region', label: 'Region', cols: 6 }, { key: 'price_date', label: 'Date', type: 'date', required: true, cols: 4 }, { key: 'price', label: 'Price', type: 'number', required: true, cols: 4 },
  { key: 'unit', label: 'Per', required: true, cols: 2 }, { key: 'currency', label: 'Currency', required: true, cols: 2 },
  { key: 'kind', label: 'Kind', type: 'select', required: true, items: [{ title: 'Actual price', value: 'actual' }, { title: 'Forecast (estimate)', value: 'forecast' }], cols: 4 },
  { key: 'source', label: 'Source', required: true, cols: 8, hint: 'Who published it' }, { key: 'source_url', label: 'Link', cols: 12 },
]
const marketQuery = ref({ product: props.data.product ?? '', transport: 0, handling: 0, plan: null as string | null })

// marketplace
const listingFields: FormField[] = [
  { key: 'product', label: 'Product', required: true, cols: 6 }, { key: 'grade', label: 'Grade', cols: 3 }, { key: 'unit', label: 'Unit', required: true, cols: 3 },
  { key: 'quantity', label: 'Quantity', type: 'number', required: true, cols: 4 },
  { key: 'availability', label: 'Availability', type: 'select', required: true, items: [{ title: 'In stock (from lots)', value: 'in_stock' }, { title: 'Forecast (not yet harvested)', value: 'forecast' }], cols: 8 },
  { key: 'lot_ids', label: 'Lots', type: 'multiselect', items: props.options.lots, cols: 12, show: (f: any) => f.availability === 'in_stock' },
  { key: 'crop_plan_id', label: 'From crop', type: 'select', items: props.options.plans.map(p => ({ title: p.title, value: p.id })), cols: 6, show: (f: any) => f.availability === 'forecast' },
  { key: 'available_from', label: 'Available from', type: 'date', cols: 6, show: (f: any) => f.availability === 'forecast' },
  { key: 'asking_price', label: 'Asking price', type: 'number', cols: 6 },
]
const requestFields: FormField[] = [
  { key: 'buyer_name', label: 'Buyer', required: true, cols: 8 }, { key: 'party_id', label: 'Customer on record', type: 'select', items: buyerItems, cols: 4 },
  { key: 'product', label: 'Product', required: true, cols: 6 }, { key: 'grade', label: 'Grade', cols: 3 }, { key: 'unit', label: 'Unit', required: true, cols: 3 },
  { key: 'quantity', label: 'Quantity', type: 'number', required: true, cols: 4 }, { key: 'needed_by', label: 'Needed by', type: 'date', cols: 4 }, { key: 'target_price', label: 'Target price', type: 'number', cols: 4 },
]
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div>
        <div class="text-body-2 mb-1"><Link :href="route('agri.hub.operations')">Operations</Link> / {{ farm?.name }}</div>
        <h4 class="text-h4 mb-1">Contracts &amp; market</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">Supply contracts, market prices and listings. Quotes, orders, invoices and disputes are raised in Accounting, charged to the farm.</p>
      </div>
      <FarmFilter :farms="farms" :model-value="farm?.id" :allow-all="false" />
    </div>
    <div class="d-flex flex-wrap gap-2 mb-5">
      <VChip v-for="t in TABS" :key="t.key" :color="tab === t.key ? 'primary' : undefined" :variant="tab === t.key ? 'flat' : 'tonal'" :prepend-icon="t.icon" @click="go(t.key)">{{ t.title }}</VChip>
    </div>
    <VCard variant="outlined" class="mb-5"><VCardText class="d-flex flex-wrap align-center gap-2 py-3">
      <span class="text-body-2 text-medium-emphasis me-2">In Accounting:</span>
      <VBtn v-for="a in ACCOUNTING" :key="a.key" :href="accounting[a.key]" size="small" variant="tonal" :prepend-icon="a.icon">{{ a.title }}</VBtn>
    </VCardText></VCard>

    <template v-if="tab === 'contracts'">
      <div class="d-flex gap-2 mb-3"><VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" :disabled="!buyers.length" @click="newContract">Contract</VBtn></div>
      <VRow>
        <VCol v-for="c in data.contracts" :key="c.id" cols="12" md="6"><VCard>
          <VCardItem><VCardTitle>{{ c.reference }} · {{ c.buyer }}</VCardTitle><VCardSubtitle>{{ num(c.quantity) }} {{ c.unit }} {{ c.product }} · v{{ c.version }}</VCardSubtitle>
            <template #append><VChip size="small" :color="statusColor(c.status)">{{ human(c.status) }}</VChip></template></VCardItem>
          <VCardText>
            <div class="text-body-2">Delivered {{ num(c.position.delivered) }} · ordered {{ num(c.position.ordered) }} · left {{ num(c.position.remaining) }} {{ c.unit }}</div>
            <div class="text-body-2">Price now: <strong>{{ c.price_now.price !== null ? money(c.price_now.price) : '—' }}</strong> <span class="text-caption">{{ c.price_now.basis }}</span></div>
            <div class="text-caption">Windows: {{ c.windows.map((w: any) => `${w.from}–${w.to} ${num(w.quantity)}${w.location ? ' at ' + w.location : ''}`).join('; ') }}</div>
            <div v-if="c.accepted_grades?.length" class="text-caption">Grades: {{ c.accepted_grades.join(', ') }}<template v-if="c.tolerance_pct"> · ±{{ c.tolerance_pct }}%</template></div>
            <div v-if="c.rejection_rules" class="text-caption">Rejection: {{ c.rejection_rules }}</div>
            <div v-if="c.advance_amount" class="text-caption">Advance {{ money(c.advance_amount) }}<template v-if="c.input_financing?.length"> · inputs financed: {{ c.input_financing.map((x: any) => `${x.item} ${money(x.value)}`).join(', ') }}</template></div>
            <div v-for="a in c.amendments" :key="a.id" class="text-caption mt-1">v{{ a.version }} {{ human(a.status) }}: {{ Object.entries(a.changes).map(([k, v]: any) => `${human(k)} ${JSON.stringify(v[0])} → ${JSON.stringify(v[1])}`).join('; ') }} — {{ a.reason }}
              <template v-if="can.approve && a.status === 'proposed'"><VBtn size="x-small" variant="text" color="success" @click="decideAmendment(a, true)">Approve</VBtn><VBtn size="x-small" variant="text" color="error" @click="decideAmendment(a, false)">Reject</VBtn></template></div>
          </VCardText>
          <VCardActions v-if="can.manage || can.approve">
            <VBtn v-if="can.approve && c.status === 'draft'" size="small" color="success" variant="tonal" @click="setStatus(c, 'active')">Approve</VBtn>
            <VBtn v-if="can.manage && c.status === 'active'" size="small" variant="tonal" @click="orderOn = c; orderVals = { deliver_on: today(), grade: c.accepted_grades?.[0] ?? '', quantity: null }">Order a delivery</VBtn>
            <VBtn v-if="can.manage && ['draft', 'active', 'suspended'].includes(c.status)" size="small" variant="text" @click="open('amend', c)">Amend</VBtn>
            <VBtn v-if="can.manage && c.status === 'active'" size="small" variant="text" @click="setStatus(c, 'suspended')">Suspend</VBtn>
            <VBtn v-if="can.manage && c.status === 'active'" size="small" variant="text" @click="setStatus(c, 'completed')">Complete</VBtn>
          </VCardActions>
        </VCard></VCol>
      </VRow>
      <VAlert v-if="!data.contracts?.length" type="info" variant="tonal">No contracts yet.</VAlert>
    </template>

    <template v-if="tab === 'position'">
      <VCard subtitle="What live contracts and open commitments still need, against what is forecast still to come in and what is on hand.">
        <VTable density="compact">
          <thead><tr><th>Crop</th><th class="text-end">Committed</th><th class="text-end">Forecast to harvest</th><th class="text-end">Harvested</th><th class="text-end">On hand</th><th class="text-end">Gap</th></tr></thead>
          <tbody><tr v-for="p in data.crops" :key="p.crop"><td>{{ p.crop }}</td><td class="text-end">{{ num(p.committed) }}<div class="text-caption">contracts {{ num(p.contracts) }} · commitments {{ num(p.commitments) }}</div></td>
            <td class="text-end">{{ num(p.projected_harvest) }}</td><td class="text-end">{{ num(p.harvested) }}</td><td class="text-end">{{ num(p.on_hand) }}</td>
            <td class="text-end font-weight-medium" :class="p.gap < 0 ? 'text-error' : 'text-success'">{{ num(p.gap) }}</td></tr>
            <tr v-if="!data.crops?.length"><td colspan="6" class="text-center text-medium-emphasis py-4">No crops or contracts.</td></tr></tbody>
        </VTable>
        <VCardText class="text-caption">{{ data.crops?.[0]?.basis }}</VCardText>
      </VCard>
    </template>

    <template v-if="tab === 'market'">
      <VCard class="mb-4"><VCardText class="d-flex flex-wrap gap-2 align-center">
        <VCombobox v-model="marketQuery.product" :items="data.products" label="Product" density="compact" hide-details style="max-inline-size: 240px" />
        <VTextField v-model.number="marketQuery.transport" type="number" label="Transport / unit" density="compact" hide-details style="max-inline-size: 150px" />
        <VTextField v-model.number="marketQuery.handling" type="number" label="Handling / unit" density="compact" hide-details style="max-inline-size: 150px" />
        <VSelect v-model="marketQuery.plan" :items="options.plans" label="Crop cost from" clearable density="compact" hide-details style="max-inline-size: 180px" />
        <VBtn variant="tonal" @click="go('market', marketQuery)">Show</VBtn>
        <VSpacer /><VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="open('price')">Price</VBtn></VCardText></VCard>
      <VRow v-if="data.trends">
        <VCol cols="12" md="7"><VCard title="Trends (monthly average, actual prices)">
          <VTable density="compact"><tbody><tr v-for="t in data.trends.actual" :key="t.market + t.unit"><td>{{ t.market }}<div class="text-caption">{{ t.currency }}/{{ t.unit }} · {{ t.sources.join(', ') }}</div></td>
            <td class="text-caption">{{ Object.entries(t.months).map(([m, p]) => `${m}: ${p}`).join(' · ') }}</td><td class="text-end">{{ t.latest.price }}<div class="text-caption">{{ t.latest.date }}</div></td></tr></tbody></VTable>
          <VCardText v-if="data.trends.forecast.length"><div class="text-subtitle-2">Forecasts (estimates, not guaranteed prices)</div>
            <div v-for="(f, i) in data.trends.forecast" :key="i" class="text-caption">{{ f.date }} {{ f.market }}: {{ f.price }} {{ f.currency }}/{{ f.unit }} ({{ f.source }})</div></VCardText></VCard></VCol>
        <VCol cols="12" md="5"><VCard title="Estimated margin by market" :subtitle="data.margins?.label">
          <VTable density="compact"><tbody><tr v-for="m in data.margins?.rows ?? []" :key="m.market"><td>{{ m.market }}<div class="text-caption">{{ m.region }} · {{ m.date }} · {{ m.source }}</div></td>
            <td class="text-end">{{ m.price }}</td><td class="text-end">net {{ m.net }}</td><td class="text-end" :class="(m.margin ?? 0) < 0 ? 'text-error' : ''">{{ m.margin !== null ? 'margin ' + m.margin : '' }}</td></tr></tbody></VTable></VCard></VCol>
      </VRow>
      <VCard title="Recorded prices" class="mt-4"><VTable density="compact"><tbody><tr v-for="p in data.prices" :key="p.id"><td>{{ String(p.price_date).slice(0, 10) }}</td><td>{{ p.product }} {{ p.grade }}</td>
        <td>{{ p.market }}<div class="text-caption">{{ p.region }}</div></td><td class="text-end">{{ p.price }} {{ p.currency }}/{{ p.unit }}</td>
        <td><VChip size="x-small" :color="p.kind === 'forecast' ? 'warning' : undefined">{{ p.kind === 'forecast' ? 'Estimate' : 'Actual' }}</VChip></td><td class="text-caption">{{ p.source }}</td></tr></tbody></VTable></VCard>
    </template>

    <template v-if="tab === 'marketplace'">
      <VAlert type="info" variant="tonal" class="mb-4">First stage: the farm's own listings and buyer requests. In stock means on hand in lots; forecast means not yet harvested. Payments are not taken here.</VAlert>
      <div class="d-flex gap-2 mb-3"><VBtn v-if="can.manage" color="primary" prepend-icon="tabler-plus" @click="open('listing')">Listing</VBtn>
        <VBtn v-if="can.manage" variant="tonal" prepend-icon="tabler-plus" @click="open('request')">Buyer request</VBtn></div>
      <VRow>
        <VCol cols="12" md="6"><VCard title="Listings"><VTable density="compact"><tbody><tr v-for="l in data.listings" :key="l.id"><td>{{ l.product }} {{ l.grade }}</td>
          <td class="text-end">{{ num(l.quantity) }} {{ l.unit }}</td><td><VChip size="x-small" :color="l.availability === 'in_stock' ? 'success' : 'warning'">{{ l.availability === 'in_stock' ? 'In stock' : 'Forecast' }}</VChip>
            <div v-if="l.available_from" class="text-caption">from {{ String(l.available_from).slice(0, 10) }}</div></td><td class="text-end">{{ l.asking_price ? money(l.asking_price) : '' }}</td></tr>
          <tr v-if="!data.listings?.length"><td class="text-center text-medium-emphasis py-4">No listings.</td></tr></tbody></VTable></VCard></VCol>
        <VCol cols="12" md="6"><VCard title="Buyer requests and what could meet them"><VTable density="compact"><tbody><tr v-for="m in data.matches" :key="m.request.id"><td>{{ m.request.buyer_name }}<div class="text-caption">{{ num(m.request.quantity) }} {{ m.request.unit }} {{ m.request.product }} {{ m.request.grade }}{{ m.request.needed_by ? ' by ' + String(m.request.needed_by).slice(0, 10) : '' }}</div></td>
          <td class="text-caption">{{ m.listings.length ? m.listings.map((l: any) => `${num(l.quantity)} (${l.availability === 'in_stock' ? 'in stock' : 'forecast ' + l.available_from})`).join(', ') : 'Nothing listed matches.' }}</td></tr>
          <tr v-if="!data.matches?.length"><td class="text-center text-medium-emphasis py-4">No open requests.</td></tr></tbody></VTable></VCard></VCol>
      </VRow>
    </template>

    <!-- contract -->
    <VDialog :model-value="!!ct" max-width="980" @update:model-value="v => { if (!v) ct = null }">
      <VCard v-if="ct" title="Buyer contract">
        <VCardText>
          <VRow dense>
            <VCol cols="4"><VSelect v-model="ct.party_id" :items="buyerItems" label="Customer *" hint="Customers are kept in Accounting" persistent-hint /></VCol><VCol cols="4"><VAutocomplete v-model="ct.crop_type_id" :items="options.crop_types" label="Crop" clearable /></VCol>
            <VCol cols="4"><VTextField v-model="ct.product" label="Product *" /></VCol>
            <VCol cols="3"><VTextField v-model.number="ct.quantity" type="number" label="Quantity *" /></VCol><VCol cols="2"><VTextField v-model="ct.unit" label="Unit" /></VCol>
            <VCol cols="2"><VTextField v-model.number="ct.tolerance_pct" type="number" label="Tolerance %" /></VCol>
            <VCol cols="5"><VCombobox v-model="ct.accepted_grades" multiple chips label="Accepted grades" /></VCol>
          </VRow>
          <div class="text-subtitle-2">Delivery windows (must add up to the quantity)</div>
          <VRow v-for="(w, k) in ct.windows" :key="k" dense class="align-center">
            <VCol cols="3"><VTextField v-model="w.from" type="date" label="From" density="compact" hide-details /></VCol><VCol cols="3"><VTextField v-model="w.to" type="date" label="To" density="compact" hide-details /></VCol>
            <VCol cols="2"><VTextField v-model.number="w.quantity" type="number" label="Qty" density="compact" hide-details /></VCol><VCol cols="3"><VTextField v-model="w.location" label="Where" density="compact" hide-details /></VCol>
            <VCol cols="1"><VBtn icon="tabler-x" size="small" variant="text" @click="ct.windows.splice(k, 1)" /></VCol>
          </VRow>
          <VBtn size="small" variant="text" prepend-icon="tabler-plus" @click="ct.windows.push({ from: today(), to: today(), quantity: null, location: '' })">Window</VBtn>
          <VRow dense class="mt-2">
            <VCol cols="3"><VSelect v-model="ct.pricing" :items="[{ title: 'Fixed price', value: 'fixed' }, { title: 'Market formula', value: 'formula' }]" label="Pricing" /></VCol>
            <VCol v-if="ct.pricing === 'fixed'" cols="3"><VTextField v-model.number="ct.price" type="number" label="Price per unit" /></VCol>
            <template v-else>
              <VCol cols="3"><VTextField v-model="ct.formula.market" label="Market followed" /></VCol><VCol cols="3"><VTextField v-model="ct.formula.product" label="As quoted (product)" /></VCol>
              <VCol cols="3"><VTextField v-model.number="ct.formula.lookback_days" type="number" label="Look back (days)" /></VCol>
              <VCol cols="3"><VTextField v-model.number="ct.formula.adjust_pct" type="number" label="Adjust %" /></VCol><VCol cols="3"><VTextField v-model.number="ct.formula.premium" type="number" label="Premium / unit" /></VCol>
              <VCol cols="3"><VTextField v-model.number="ct.formula.floor" type="number" label="Floor" /></VCol><VCol cols="3"><VTextField v-model.number="ct.formula.cap" type="number" label="Cap" /></VCol>
            </template>
            <VCol cols="6"><VSelect v-model="ct.standard_id" :items="options.standards" label="Grading standard" clearable /></VCol>
            <VCol cols="6"><VSelect v-model="ct.transport" :items="[{ title: 'Farm delivers', value: 'farm' }, { title: 'Buyer collects', value: 'buyer' }]" label="Transport" /></VCol>
            <VCol cols="12"><VTextarea v-model="ct.quality_spec" label="Quality specification" rows="2" /></VCol>
            <VCol cols="6"><VTextarea v-model="ct.packaging" label="Packaging obligations" rows="2" /></VCol><VCol cols="6"><VTextarea v-model="ct.rejection_rules" label="Rejection rules" rows="2" /></VCol>
            <VCol cols="8"><VTextarea v-model="ct.dispute_procedure" label="Dispute procedure" rows="2" /></VCol><VCol cols="4"><VTextField v-model.number="ct.advance_amount" type="number" label="Advance from the buyer" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="ct = null">Cancel</VBtn><VBtn color="primary" :disabled="!ct.party_id || !ct.product" @click="saveContract">Draft contract</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog :model-value="!!orderOn" max-width="560" @update:model-value="v => { if (!v) orderOn = null }">
      <VCard v-if="orderOn" :title="`Delivery on ${orderOn.reference}`">
        <VCardText><VRow dense>
          <VCol cols="6"><VTextField v-model="orderVals.deliver_on" type="date" label="Deliver on" /></VCol>
          <VCol cols="3"><VCombobox v-model="orderVals.grade" :items="orderOn.accepted_grades ?? []" label="Grade" /></VCol>
          <VCol cols="3"><VTextField v-model.number="orderVals.quantity" type="number" :label="`Qty (${orderOn.unit})`" /></VCol></VRow>
          <div class="text-caption">Priced by the contract on the delivery date; must fall in a delivery window.</div></VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="orderOn = null">Cancel</VBtn><VBtn color="primary" :disabled="!orderVals.quantity" @click="sendOrder(orderVals)">Create order</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <FormDialog v-if="dialog?.kind === 'amend'" :model-value="true" :title="`Amend ${dialog.row.reference}`" :url="route('agri.sales.contracts.amend', dialog.row.uuid)" :fields="amendFields(dialog.row)"
      :values="{ quantity: dialog.row.quantity, price: dialog.row.price, quality_spec: dialog.row.quality_spec }" intro="The contract changes only once someone else approves the amendment." @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'price'" :model-value="true" title="Market price" :url="route('agri.sales.prices.store')" :fields="priceFields"
      :values="{ price_date: today(), currency: 'ZAR', unit: 'kg', kind: 'actual', product: marketQuery.product }" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'listing'" :model-value="true" title="Listing" :url="route('agri.sales.listings.store')" :fields="listingFields" :values="{ farm_id: farm?.id, availability: 'in_stock', unit: 'kg' }" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'request'" :model-value="true" title="Buyer request" :url="route('agri.sales.requests.store')" :fields="requestFields" :values="{ unit: 'kg' }" @update:model-value="close" />
  </div>
</template>
