<script lang="ts" setup>
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const { t } = useI18n()

interface Line { id?: number; account_id: number | null; item_id: number | null; description: string; quantity: number; unit_price: number | null; tax_rate: number; qty_delivered?: number; outstanding?: number }
interface Delivery { id: number; number: string; date: string }
interface Order { id: number; number: string; party: string; party_id: number; date: string; total: number; status: string; customer_ref: string | null; description: string | null; invoice_id: number | null; deliveries: Delivery[]; lines: Line[] }
interface Option { id: number; label: string }
interface Item { id: number; code: string; name: string; description: string | null; price: number; tax_rate: number; account_id: number | null }
const props = defineProps<{ orders: Order[]; customers: { id: number; name: string }[]; incomeAccounts: Option[]; items: Item[] }>()

const money = (n: number) => (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const statusColor: Record<string, string> = { open: 'info', part_delivered: 'warning', delivered: 'success', invoiced: 'purple', cancelled: 'error' }
const confirm = ref()

// Create / edit order
const dialog = ref(false)
const editingId = ref<number | null>(null)
const blankLine = (): Line => ({ account_id: props.incomeAccounts[0]?.id ?? null, item_id: null, description: '', quantity: 1, unit_price: null, tax_rate: 15 })
const form = useForm<any>({ party_id: null, order_date: new Date().toISOString().slice(0, 10), customer_ref: '', description: '', lines: [blankLine()] })
const total = computed(() => form.lines.reduce((s: number, l: Line) => s + (Number(l.quantity) || 0) * (Number(l.unit_price) || 0) * (1 + (Number(l.tax_rate) || 0) / 100), 0))
function applyItem(l: Line) {
  const it = props.items.find(i => i.id === l.item_id); if (!it) return
  l.description = it.name + (it.description ? ` — ${it.description}` : ''); l.unit_price = it.price; l.tax_rate = it.tax_rate
  if (it.account_id) l.account_id = it.account_id
}
function openNew() { editingId.value = null; form.reset(); form.order_date = new Date().toISOString().slice(0, 10); form.lines = [blankLine()]; dialog.value = true }
function openEdit(o: Order) {
  editingId.value = o.id
  Object.assign(form, { party_id: o.party_id, order_date: o.date, customer_ref: o.customer_ref ?? '', description: o.description ?? '' })
  form.lines = o.lines.map(l => ({ ...l }))
  dialog.value = true
}
function save() {
  const opts = { preserveScroll: true, onSuccess: () => { dialog.value = false } }
  editingId.value ? form.patch(route('finance.accounting.sales-orders.update', editingId.value), opts) : form.post(route('finance.accounting.sales-orders.store'), opts)
}

// Deliver
const delDialog = ref(false)
const delOrder = ref<Order | null>(null)
const delForm = useForm<any>({ notes: '', deliveries: [] })
function openDeliver(o: Order) {
  delOrder.value = o; delForm.reset()
  delForm.deliveries = o.lines.map(l => ({ order_line_id: l.id, description: l.description, outstanding: l.outstanding, quantity: l.outstanding }))
  delDialog.value = true
}
function saveDeliver() { delForm.post(route('finance.accounting.sales-orders.deliver', delOrder.value!.id), { preserveScroll: true, onSuccess: () => { delDialog.value = false } }) }

function invoice(o: Order) {
  confirm.value.open(
    { title: t('acc.invoice_order'), message: t('acc.invoice_order_confirm', { number: o.number, amount: money(o.total) }), confirmText: t('acc.invoice_word'), confirmColor: 'primary', icon: 'tabler-file-invoice' },
    () => useForm({}).post(route('finance.accounting.sales-orders.invoice', o.id), { preserveScroll: true }),
  )
}
function cancel(o: Order) {
  confirm.value.open(
    { title: t('acc.cancel_order'), message: t('acc.cancel_order_confirm', { number: o.number }), confirmText: t('acc.cancel_order'), confirmColor: 'error', icon: 'tabler-x' },
    () => useForm({}).post(route('finance.accounting.sales-orders.cancel', o.id), { preserveScroll: true }),
  )
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('acc.finance') }} · {{ t('acc.crumb_accounting') }}</div>
        <h2 class="text-h5 mb-0">{{ t('acc.sales_orders') }}</h2>
        <div class="text-caption text-medium-emphasis">{{ t('acc.sales_orders_sub') }}</div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openNew">{{ t('acc.new_order') }}</VBtn>
    </div>

    <VAlert v-if="(form.errors as any).order || (delForm.errors as any).order" type="error" variant="tonal" density="compact" class="mb-3">{{ (form.errors as any).order || (delForm.errors as any).order }}</VAlert>

    <VCard border>
      <VTable>
        <thead><tr><th>{{ t('acc.order_no') }}</th><th>{{ t('acc.customer') }}</th><th>{{ t('acc.date') }}</th><th class="text-right">{{ t('acc.total') }}</th><th>{{ t('acc.deliveries') }}</th><th>{{ t('acc.status') }}</th><th class="text-right"></th></tr></thead>
        <tbody>
          <tr v-for="o in orders" :key="o.id">
            <td class="font-weight-medium">{{ o.number }}</td>
            <td>{{ o.party }}</td>
            <td class="text-caption">{{ o.date }}</td>
            <td class="text-right">{{ money(o.total) }}</td>
            <td class="text-caption">
              <template v-if="o.deliveries.length">
                <VBtn v-for="d in o.deliveries" :key="d.id" size="x-small" variant="text" prepend-icon="tabler-file-type-pdf" :href="route('finance.accounting.delivery-notes.pdf', d.id)" target="_blank" :title="d.date">{{ d.number }}</VBtn>
              </template>
              <span v-else>—</span>
            </td>
            <td><VChip size="x-small" :color="statusColor[o.status]" variant="tonal" class="text-capitalize">{{ o.status.replace('_', ' ') }}</VChip></td>
            <td class="text-right text-no-wrap">
              <VBtn v-if="!['invoiced', 'cancelled'].includes(o.status)" icon="tabler-pencil" size="small" variant="text" :title="t('acc.edit')" @click="openEdit(o)" />
              <VBtn v-if="!['invoiced', 'cancelled'].includes(o.status)" size="x-small" variant="tonal" color="warning" class="ms-1" @click="openDeliver(o)">{{ t('acc.deliver') }}</VBtn>
              <VBtn v-if="!['invoiced', 'cancelled'].includes(o.status)" size="x-small" variant="tonal" color="primary" class="ms-1" @click="invoice(o)">{{ t('acc.invoice_word') }}</VBtn>
              <VChip v-if="o.invoice_id" size="x-small" color="purple" variant="tonal" class="ms-1">{{ t('acc.invoiced') }}</VChip>
              <VBtn v-if="!['invoiced', 'cancelled'].includes(o.status)" size="x-small" variant="text" color="error" class="ms-1" @click="cancel(o)">{{ t('acc.cancel') }}</VBtn>
            </td>
          </tr>
          <tr v-if="!orders.length"><td colspan="7" class="text-center text-medium-emphasis py-6">{{ t('acc.no_sales_orders') }}</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <!-- Order dialog -->
    <VDialog v-model="dialog" max-width="1000" persistent>
      <VCard>
        <VCardTitle>{{ editingId ? t('acc.edit_sales_order') : t('acc.new_sales_order') }}</VCardTitle>
        <VCardText>
          <div class="d-flex gap-3 mb-3 flex-wrap">
            <VAutocomplete v-model="form.party_id" :items="customers.map(c => ({ title: c.name, value: c.id }))" :label="t('acc.customer')" variant="outlined" density="comfortable" style="min-width: 240px" :error-messages="(form.errors as any).party_id" />
            <VTextField v-model="form.order_date" type="date" :label="t('acc.order_date')" variant="outlined" density="comfortable" style="max-width: 180px" />
            <VTextField v-model="form.customer_ref" :label="t('acc.customer_ref')" variant="outlined" density="comfortable" style="max-width: 200px" />
          </div>
          <VTextField v-model="form.description" :label="t('acc.description')" variant="outlined" density="comfortable" class="mb-3" />
          <VTable density="compact">
            <thead><tr><th style="width: 24%">{{ t('acc.item') }}</th><th style="width: 20%">{{ t('acc.income_account') }}</th><th>{{ t('acc.description') }}</th><th class="text-right" style="width: 80px">{{ t('acc.qty') }}</th><th class="text-right" style="width: 120px">{{ t('acc.price') }}</th><th class="text-right" style="width: 80px">{{ t('acc.tax_pct') }}</th><th></th></tr></thead>
            <tbody>
              <tr v-for="(l, i) in form.lines" :key="i">
                <td class="pa-1"><VAutocomplete v-model="l.item_id" :items="items.map(it => ({ title: `${it.code} · ${it.name}`, value: it.id }))" density="compact" variant="outlined" hide-details clearable :placeholder="t('acc.search_item')" @update:model-value="applyItem(l)" /></td>
                <td class="pa-1"><VAutocomplete v-model="l.account_id" :items="incomeAccounts.map(a => ({ title: a.label, value: a.id }))" density="compact" variant="outlined" hide-details /></td>
                <td class="pa-1"><VTextField v-model="l.description" density="compact" variant="outlined" hide-details /></td>
                <td class="pa-1"><VTextField v-model.number="l.quantity" type="number" density="compact" variant="outlined" hide-details reverse /></td>
                <td class="pa-1"><VTextField v-model.number="l.unit_price" type="number" density="compact" variant="outlined" hide-details reverse /></td>
                <td class="pa-1"><VTextField v-model.number="l.tax_rate" type="number" density="compact" variant="outlined" hide-details reverse /></td>
                <td class="pa-1"><VBtn icon="tabler-x" size="x-small" variant="text" :disabled="form.lines.length <= 1" @click="form.lines.splice(i, 1)" /></td>
              </tr>
            </tbody>
            <tfoot><tr class="font-weight-bold"><td colspan="6" class="text-right">{{ t('acc.total') }}</td><td class="text-right">{{ money(total) }}</td></tr></tfoot>
          </VTable>
          <VBtn size="small" variant="text" prepend-icon="tabler-plus" class="mt-2" @click="form.lines.push(blankLine())">{{ t('acc.add_line') }}</VBtn>
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('acc.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" :disabled="!form.party_id || total <= 0" @click="save">{{ editingId ? t('acc.save') : t('acc.create') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Deliver dialog -->
    <VDialog v-model="delDialog" max-width="640">
      <VCard>
        <VCardTitle>{{ t('acc.record_delivery') }} — {{ delOrder?.number }}</VCardTitle>
        <VCardText>
          <VTable density="compact">
            <thead><tr><th>{{ t('acc.line') }}</th><th class="text-right">{{ t('acc.outstanding') }}</th><th class="text-right" style="width: 140px">{{ t('acc.deliver_now') }}</th></tr></thead>
            <tbody>
              <tr v-for="(d, i) in delForm.deliveries" :key="i">
                <td>{{ d.description }}</td>
                <td class="text-right">{{ d.outstanding }}</td>
                <td class="pa-1"><VTextField v-model.number="d.quantity" type="number" density="compact" variant="outlined" hide-details reverse :max="d.outstanding" /></td>
              </tr>
            </tbody>
          </VTable>
          <VTextField v-model="delForm.notes" :label="t('acc.notes')" variant="outlined" density="comfortable" class="mt-3" />
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="delDialog = false">{{ t('acc.cancel') }}</VBtn><VBtn color="primary" :loading="delForm.processing" @click="saveDeliver">{{ t('acc.record_delivery') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirm" />
  </div>
</template>
