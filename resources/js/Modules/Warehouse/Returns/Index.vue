<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute

type Opt = { id: string; title: string }
type Row = { id: string; number: string; status: string; warehouse: string; against: string | null; customer: string | null; lines: number; credit_note: string | null; created_at: string }
defineProps<{
  returns: { data: Row[]; links: Array<{ url: string | null; label: string; active: boolean }> }
  filters: { status: string | null }
  statuses: string[]
  reasons: string[]
  warehouses: Opt[]
  can: { returns: boolean }
}>()

const dialog = ref(false)
const against = ref<'order' | 'invoice' | 'reference'>('order')
const form = useForm({ warehouse: null as string | null, order: null as string | null, invoice: null as string | null, reference: '', customer_name: '', reason: '',
  lines: [] as Array<{ item: string; title: string; qty: number; reason_code: string | null; sent?: number }> })
const orders = ref<Array<Opt & { lines: Array<{ item: string; title: string; sent: number; uom: string }> }>>([])
const invoices = ref<Opt[]>([])
const items = ref<Opt[]>([])
const pick = ref<string | null>(null)
async function findSources(q: string) {
  const { data } = await axios.get(route('warehouse.returns.sources'), { params: { q } })
  orders.value = data.orders
  invoices.value = data.invoices
}
async function searchItems(q: string) { items.value = (await axios.get(route('warehouse.products.search'), { params: { q } })).data }
function chooseOrder(id: string | null) {
  form.order = id
  const o = orders.value.find(x => x.id === id)
  form.lines = o ? o.lines.map(l => ({ item: l.item, title: l.title, qty: l.sent, reason_code: null, sent: l.sent })) : []
}
function addLine() {
  const o = items.value.find(x => x.id === pick.value)
  if (o) form.lines.push({ item: o.id, title: o.title, qty: 1, reason_code: null })
  pick.value = null
}
function save() {
  form.transform(d => ({ ...d, order: against.value === 'order' ? d.order : null, invoice: against.value === 'invoice' ? d.invoice : null,
    reference: against.value === 'reference' ? d.reference : null, lines: d.lines.filter(l => l.qty > 0) }))
    .post(route('warehouse.returns.store'), { onSuccess: () => { dialog.value = false; form.reset() } })
}
const color = (s: string) => ({ AUTHORISED: 'secondary', RECEIVED: 'info', INSPECTING: 'warning', COMPLETED: 'success', CANCELLED: 'secondary' } as Record<string, string>)[s]
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.returns_page.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.returns_page.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VSelect :model-value="filters.status" :items="statuses.map(s => ({ value: s, title: t(`warehouse_management.rma_status.${s}`) }))" clearable density="compact"
                 hide-details style="min-width: 200px" :label="t('warehouse_management.locations.status')"
                 @update:model-value="v => router.get(route('warehouse.returns.index'), { status: v })" />
        <VBtn v-if="can.returns" color="primary" prepend-icon="tabler-plus" @click="dialog = true; findSources('')">{{ t('warehouse_management.returns_page.new') }}</VBtn>
      </div>
    </div>
    <RefusalAlert />
    <VCard border>
      <VCardText v-if="!returns.data.length" class="text-center text-medium-emphasis py-10">{{ t('warehouse_management.returns_page.none') }}</VCardText>
      <VTable v-else density="comfortable">
        <thead><tr><th>{{ t('warehouse_management.returns_page.number') }}</th><th>{{ t('warehouse_management.returns_page.against') }}</th>
          <th>{{ t('warehouse_management.returns_page.customer') }}</th><th>{{ t('warehouse_management.common.warehouse') }}</th>
          <th class="text-end">{{ t('warehouse_management.counts.lines') }}</th><th>{{ t('warehouse_management.returns_page.credit') }}</th><th>{{ t('warehouse_management.locations.status') }}</th></tr></thead>
        <tbody>
          <tr v-for="r in returns.data" :key="r.id" style="cursor: pointer" @click="router.get(route('warehouse.returns.show', r.id))">
            <td class="font-weight-medium">{{ r.number }}</td><td>{{ r.against ?? '—' }}</td><td>{{ r.customer ?? '—' }}</td><td>{{ r.warehouse }}</td>
            <td class="text-end">{{ r.lines }}</td><td>{{ r.credit_note ?? '—' }}</td>
            <td><VChip size="small" :color="color(r.status)" variant="tonal">{{ t(`warehouse_management.rma_status.${r.status}`) }}</VChip></td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
    <div class="d-flex gap-1 mt-3 flex-wrap">
      <VBtn v-for="l in returns.links" :key="l.label" size="small" :variant="l.active ? 'flat' : 'text'" :disabled="!l.url" @click="l.url && router.get(l.url)"><span v-html="l.label" /></VBtn>
    </div>

    <VDialog v-model="dialog" max-width="720">
      <VCard :title="t('warehouse_management.returns_page.new')">
        <VCardText class="d-flex flex-column gap-3">
          <VSelect v-model="form.warehouse" :items="warehouses" item-value="id" :label="t('warehouse_management.returns_page.receive_at')" :error-messages="form.errors.warehouse" />
          <VBtnToggle v-model="against" mandatory density="compact" variant="outlined">
            <VBtn value="order">{{ t('warehouse_management.returns_page.by_order') }}</VBtn>
            <VBtn value="invoice">{{ t('warehouse_management.returns_page.by_invoice') }}</VBtn>
            <VBtn value="reference">{{ t('warehouse_management.returns_page.by_reference') }}</VBtn>
          </VBtnToggle>
          <VAutocomplete v-if="against === 'order'" :model-value="form.order" :items="orders" item-value="id" :label="t('warehouse_management.returns_page.order')"
                         @update:search="findSources" @update:model-value="chooseOrder" />
          <VAutocomplete v-if="against === 'invoice'" v-model="form.invoice" :items="invoices" item-value="id" :label="t('warehouse_management.returns_page.invoice')" @update:search="findSources" />
          <VTextField v-if="against === 'reference'" v-model="form.reference" :label="t('warehouse_management.returns_page.reference')" :hint="t('warehouse_management.returns_page.reference_hint')" persistent-hint />
          <VTextField v-model="form.customer_name" :label="t('warehouse_management.returns_page.customer')" />
          <VTextField v-model="form.reason" :label="t('warehouse_management.common.reason')" />
          <div v-if="against !== 'order'" class="d-flex gap-2 align-center">
            <VAutocomplete v-model="pick" :items="items" item-value="id" :label="t('warehouse_management.counts.item')" hide-details @update:search="searchItems" />
            <VBtn variant="tonal" @click="addLine">{{ t('warehouse_management.common.add') }}</VBtn>
          </div>
          <div v-for="(l, i) in form.lines" :key="i" class="d-flex gap-2 align-center">
            <div class="flex-grow-1">{{ l.title }}<div v-if="l.sent" class="text-caption">{{ t('warehouse_management.returns_page.sent', { n: l.sent }) }}</div></div>
            <VTextField v-model.number="l.qty" type="number" min="0" density="compact" hide-details style="max-width: 100px" />
            <VSelect v-model="l.reason_code" :items="reasons.map(r => ({ value: r, title: t(`warehouse_management.return_reason.${r}`) }))" density="compact" hide-details clearable style="max-width: 180px" :label="t('warehouse_management.common.reason')" />
          </div>
          <div v-if="form.errors.lines" class="text-error text-caption">{{ form.errors.lines }}</div>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" :disabled="!form.lines.length" @click="save">{{ t('warehouse_management.returns_page.authorise') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
