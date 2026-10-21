<script lang="ts" setup>
import { ref, computed, onMounted } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const { t } = useI18n()

interface Line { account_id: number | null; item_id: number | null; description: string; quantity: number; unit_price: number | null; tax_rate: number; unit?: string | null; grade?: string | null; price_basis?: string | null }
interface Quote { id: number; number: string; party: string; party_id: number; date: string; valid_until: string | null; subtotal: number; discount_percent: number; discount_amount: number; tax_total: number; total: number; status: string; description: string | null; customer_ref: string | null; vendor_number: string | null; sales_rep: string | null; delivery_address: string | null; postal_address: string | null; invoice_id: number | null; sales_order_id: number | null; crm_deal_id: number | null; editable: boolean; charge_to?: string | null }
interface Option { id: number; label: string }
interface Item { id: number; code: string; name: string; description: string | null; unit: string; price: number; tax_rate: number; account_id: number | null }
const props = defineProps<{
  quotes: Quote[]
  quoteLines: Record<number, Line[]>
  customers: { id: number; name: string; price_list_id: number | null; tax_exempt: boolean; address: string | null; city: string | null; country: string | null; postal_address: string | null; vendor_number: string | null }[]
  incomeAccounts: Option[]
  items: Item[]
  employees: string[]
  priceLists: Record<number, Record<number, number>>
  crm_active: boolean
  deals: { id: number; label: string; party_id: number | null }[]
  templates: { id: number; name: string; terms: string | null; is_default: boolean }[]
  defaultTerms: string | null
  chargeTo: { value: string; title: string }[]
}>()

// The customer's price for a line's item or product (by grade and unit), and which list it came from.
async function priceFromList(l: any) {
  const q = new URLSearchParams(Object.entries({ party_id: form.party_id, item_id: l.item_id, description: l.item_id ? null : l.description, grade: l.grade, unit: l.unit })
    .filter(([, v]) => v !== null && v !== '' && v !== undefined).map(([k, v]) => [k, String(v)]))
  const r = await fetch(`${route('finance.accounting.price-lists.price')}?${q}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
  if (!r.ok) return
  const p = await r.json()
  if (p.price !== null) { l.unit_price = p.price; l.price_basis = p.basis } else { l.price_basis = t('acc.no_list_price') }
}

const defaultTemplateId = props.templates.find(t => t.is_default)?.id ?? props.templates[0]?.id ?? null
function onTemplateChange(id: number | null) {
  // Prefill terms from the chosen template (or the tenant default) when the box is empty.
  const tpl = props.templates.find(t => t.id === id)
  if (!form.terms) form.terms = tpl?.terms || props.defaultTerms || ''
}

// The price map for the currently selected customer (price list overrides item default).
function customerPrices(): Record<number, number> | null {
  const plId = props.customers.find(c => c.id === form.party_id)?.price_list_id
  return plId ? (props.priceLists[plId] ?? null) : null
}
function priceFor(itemId: number, fallback: number): number {
  const pl = customerPrices()
  return pl && pl[itemId] != null ? pl[itemId] : fallback
}
function customerExempt(): boolean {
  return !!props.customers.find(c => c.id === form.party_id)?.tax_exempt
}
function repriceLines() {
  const exempt = customerExempt()
  form.lines.forEach((l: any) => {
    if (l.item_id) {
      const it = props.items.find(i => i.id === l.item_id)
      if (it) l.unit_price = priceFor(it.id, it.price)
    }
    if (exempt) l.tax_rate = 0
  })
}

// Selecting a customer auto-fills the delivery & postal addresses.
function onCustomerSelected(id: number | null) {
  const c = props.customers.find(x => x.id === id)
  if (!c) return
  const addr = [c.address, c.city, c.country].filter(Boolean).join('\n')
  if (addr) form.delivery_address = addr
  form.postal_address = c.postal_address || addr
  /*
   * The supplier account this customer files us under, filled in from their record.
   *
   * Only when the field is still empty, so a number corrected on this one quote is not overwritten
   * by re-picking the customer.
   */
  if (!form.vendor_number) form.vendor_number = c.vendor_number ?? ''
  repriceLines() // apply the customer's price list to existing lines
}
// Picking a CRM deal auto-selects its customer (and its address).
function onDealSelected(dealId: number | null) {
  const deal = props.deals.find(d => d.id === dealId)
  if (deal?.party_id) { form.party_id = deal.party_id; onCustomerSelected(deal.party_id) }
}

const dialog = ref(false)
const editingId = ref<number | null>(null)
const blankLine = (): any => ({ account_id: props.incomeAccounts[0]?.id ?? null, item_id: null, description: '', note: '', _showNote: false, quantity: 1, unit_price: null, tax_rate: 15, unit: null, grade: null, price_basis: null })
const form = useForm<any>({ party_id: null, quote_date: new Date().toISOString().slice(0, 10), valid_until: '', customer_ref: '', vendor_number: '', sales_rep: '', discount_percent: 0, description: '', delivery_address: '', postal_address: '', crm_deal_id: null, template_id: defaultTemplateId, terms: '', charge_to: null, lines: [blankLine()] })

const money = (n: number) => (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

/** Filters the quotes already on the page — number, customer, status, dates, rep, reference. */
const search = ref('')
const visibleQuotes = computed(() => {
  const term = search.value.trim().toLowerCase()
  if (!term) return props.quotes

  return props.quotes.filter(q =>
    [q.number, q.party, q.status, q.date, q.valid_until, q.customer_ref, q.sales_rep, q.description]
      .some(v => (v ?? '').toString().toLowerCase().includes(term)))
})
const statusColor: Record<string, string> = { draft: 'default', sent: 'info', accepted: 'success', declined: 'error', expired: 'warning', invoiced: 'purple' }

const grossSubtotal = computed(() => form.lines.reduce((s: number, l: Line) => s + (Number(l.quantity) || 0) * (Number(l.unit_price) || 0), 0))
const grossTax = computed(() => form.lines.reduce((s: number, l: Line) => s + (Number(l.quantity) || 0) * (Number(l.unit_price) || 0) * (Number(l.tax_rate) || 0) / 100, 0))
const discountAmount = computed(() => grossSubtotal.value * (Number(form.discount_percent) || 0) / 100)
const netFactor = computed(() => grossSubtotal.value > 0 ? (grossSubtotal.value - discountAmount.value) / grossSubtotal.value : 1)
const taxTotal = computed(() => grossTax.value * netFactor.value)
const total = computed(() => grossSubtotal.value - discountAmount.value + taxTotal.value)

function openNew() { editingId.value = null; form.reset(); form.quote_date = new Date().toISOString().slice(0, 10); form.discount_percent = 0; form.template_id = defaultTemplateId; form.terms = (props.templates.find(t => t.id === defaultTemplateId)?.terms) || props.defaultTerms || ''; form.lines = [blankLine()]; dialog.value = true }
function openEdit(q: Quote) {
  editingId.value = q.id
  form.party_id = q.party_id; form.quote_date = q.date; form.valid_until = q.valid_until ?? ''; form.description = q.description ?? ''; form.crm_deal_id = q.crm_deal_id
  form.customer_ref = q.customer_ref ?? ''; form.vendor_number = q.vendor_number ?? ''; form.sales_rep = q.sales_rep ?? ''; form.discount_percent = q.discount_percent ?? 0
  form.delivery_address = q.delivery_address ?? ''; form.postal_address = q.postal_address ?? ''; form.charge_to = q.charge_to ?? null
  form.template_id = (q as any).template_id ?? defaultTemplateId; form.terms = (q as any).terms ?? ''
  const lines = props.quoteLines[q.id] ?? []
  form.lines = lines.length ? lines.map(l => ({ ...l })) : [blankLine()]
  dialog.value = true
}
// Selecting an item fills price, tax, description and the income account — Sage-style.
function applyItem(line: Line) {
  const it = props.items.find(i => i.id === line.item_id)
  if (!it) return
  line.description = it.name + (it.description ? ` — ${it.description}` : '')
  line.unit_price = priceFor(it.id, it.price)
  line.tax_rate = customerExempt() ? 0 : it.tax_rate
  if (it.account_id) line.account_id = it.account_id
}
function addLine() { form.lines.push(blankLine()) }
function save() {
  const opts = { preserveScroll: true, onSuccess: () => { dialog.value = false } }
  editingId.value ? form.patch(route('finance.accounting.quotes.update', editingId.value), opts) : form.post(route('finance.accounting.quotes.store'), opts)
}
function setStatus(q: Quote, status: string) { useForm({ status }).post(route('finance.accounting.quotes.status', q.id), { preserveScroll: true }) }
const confirm = ref()
function convert(q: Quote) {
  confirm.value.open(
    { title: t('acc.convert_to_invoice'), message: t('acc.convert_to_invoice_confirm', { number: q.number }), confirmText: t('acc.convert'), confirmColor: 'primary', icon: 'tabler-file-invoice' },
    () => useForm({}).post(route('finance.accounting.quotes.convert', q.id), { preserveScroll: true }),
  )
}
function emailDoc(q: Quote) {
  confirm.value.open(
    { title: t('acc.email_quote'), message: t('acc.email_quote_confirm', { number: q.number, party: q.party }), confirmText: t('acc.send'), confirmColor: 'primary', icon: 'tabler-mail' },
    () => useForm({}).post(route('finance.accounting.quotes.email', q.id), { preserveScroll: true }),
  )
}
function whatsappDoc(q: Quote) {
  confirm.value.open(
    { title: t('acc.send_on_whatsapp'), message: t('acc.whatsapp_quote_confirm', { number: q.number, party: q.party }), confirmText: t('acc.send'), confirmColor: 'success', icon: 'tabler-brand-whatsapp' },
    () => useForm({}).post(route('finance.accounting.quotes.whatsapp', q.id), { preserveScroll: true }),
  )
}
function toOrder(q: Quote) {
  confirm.value.open(
    { title: t('acc.create_sales_order'), message: t('acc.create_sales_order_confirm', { number: q.number }), confirmText: t('acc.create_order'), confirmColor: 'primary', icon: 'tabler-clipboard-list' },
    () => useForm({}).post(route('finance.accounting.quotes.to-order', q.id), { preserveScroll: true }),
  )
}

// ── Inline "add customer" from the customer dropdown (no blocking fields) ────
const custDialog = ref(false)
const custForm = useForm({ kind: 'customer', name: '', email: '', phone: '' })
function saveCustomer() {
  custForm.post(route('finance.accounting.parties.store'), {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => {
      // Props refresh with the new party; select it by the name we just added.
      const match = [...props.customers].reverse().find(c => c.name === custForm.name)
      if (match) { form.party_id = match.id; onCustomerSelected(match.id) }
      custDialog.value = false
      custForm.reset()
    },
  })
}

// ── Inline "add item" from a line's item dropdown ───────────────────────────
const itemDialog = ref(false)
const itemTargetLine = ref<any>(null)
const itemForm = useForm({ name: '', type: 'service', sales_price: null as number | null, unit: '' })
function openNewItem(line: any) { itemTargetLine.value = line; itemForm.reset(); itemDialog.value = true }
function saveItem() {
  itemForm.post(route('finance.accounting.items.store'), {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => {
      const match = [...props.items].reverse().find(i => i.name === itemForm.name)
      if (match && itemTargetLine.value) { itemTargetLine.value.item_id = match.id; applyItem(itemTargetLine.value) }
      itemDialog.value = false
      itemForm.reset()
    },
  })
}

// Open the new-quote form with a party preselected when arriving from the customer list.
onMounted(() => {
  const pid = Number(new URLSearchParams(window.location.search).get('party') || '')
  if (pid && props.customers.some(c => c.id === pid)) { openNew(); form.party_id = pid; onCustomerSelected(pid) }
})
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('acc.finance') }} · {{ t('acc.crumb_accounting') }}</div>
        <h2 class="text-h5 mb-0">{{ t('acc.quotes_word') }}</h2>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openNew">{{ t('acc.new_quote') }}</VBtn>
    </div>

    <VAlert v-if="(form.errors as any).quote" type="error" variant="tonal" density="compact" class="mb-3">{{ (form.errors as any).quote }}</VAlert>

    <VCard border>
      <VCardText class="pb-0">
        <VTextField
          v-model="search"
          :placeholder="t('acc.search')"
          prepend-inner-icon="tabler-search"
          density="compact" variant="outlined" hide-details clearable single-line
          style="max-width: 340px"
        />
      </VCardText>
      <VTable>
        <thead><tr><th>{{ t('acc.quote_no') }}</th><th>{{ t('acc.customer') }}</th><th>{{ t('acc.date') }}</th><th>{{ t('acc.valid_until') }}</th><th class="text-right">{{ t('acc.total') }}</th><th>{{ t('acc.status') }}</th><th class="text-right"></th></tr></thead>
        <tbody>
          <tr v-for="q in visibleQuotes" :key="q.id">
            <td class="font-weight-medium">{{ q.number }}</td>
            <td>{{ q.party }}</td>
            <td class="text-caption">{{ q.date }}</td>
            <td class="text-caption">{{ q.valid_until || '—' }}</td>
            <td class="text-right">{{ money(q.total) }}</td>
            <td><VChip size="x-small" :color="statusColor[q.status]" variant="tonal" class="text-capitalize">{{ q.status }}</VChip></td>
            <td class="text-right text-no-wrap">
              <!-- What has become of the quote stays on the row: it is a fact about the quote
                   rather than something to do with it, and burying it in a menu means opening
                   every row to find the one that was already invoiced. -->
              <VChip v-if="q.sales_order_id && !q.invoice_id" size="x-small" color="info" variant="tonal" class="me-1">{{ t('acc.ordered') }}</VChip>
              <VChip v-if="q.invoice_id" size="x-small" color="purple" variant="tonal" class="me-1">{{ t('acc.invoiced') }}</VChip>

              <VMenu location="bottom end">
                <template #activator="{ props: menu }">
                  <VBtn
                    v-bind="menu" icon="tabler-dots-vertical" size="small" variant="text"
                    :aria-label="t('acc.actions_for', { number: q.number })"
                  />
                </template>
                <VList density="compact" min-width="220">
                  <VListItem
                    v-if="q.editable" prepend-icon="tabler-pencil"
                    :title="t('acc.edit')" @click="openEdit(q)"
                  />
                  <VListItem
                    prepend-icon="tabler-file-type-pdf" :title="t('acc.download_pdf_title')"
                    :href="route('finance.accounting.quotes.pdf', q.id)" target="_blank"
                  />
                  <VListItem
                    prepend-icon="tabler-mail" :title="t('acc.email_to_customer')"
                    @click="emailDoc(q)"
                  />
                  <VListItem
                    prepend-icon="tabler-brand-whatsapp" :title="t('acc.whatsapp')"
                    @click="whatsappDoc(q)"
                  />

                  <VDivider v-if="q.status === 'draft' || q.status === 'sent'" class="my-1" />

                  <VListItem
                    v-if="q.status === 'draft'" prepend-icon="tabler-send"
                    :title="t('acc.send')" @click="setStatus(q, 'sent')"
                  />
                  <VListItem
                    v-if="q.status === 'sent'" prepend-icon="tabler-check"
                    :title="t('acc.accept')" @click="setStatus(q, 'accepted')"
                  />
                  <VListItem
                    v-if="q.status === 'sent'" prepend-icon="tabler-x"
                    :title="t('acc.decline')" @click="setStatus(q, 'declined')"
                  />

                  <VDivider v-if="!q.sales_order_id && !q.invoice_id && q.status !== 'declined'" class="my-1" />

                  <VListItem
                    v-if="!q.sales_order_id && !q.invoice_id && q.status !== 'declined'"
                    prepend-icon="tabler-clipboard-list" :title="t('acc.to_order')" @click="toOrder(q)"
                  />
                  <VListItem
                    v-if="!q.sales_order_id && !q.invoice_id && q.status !== 'declined'"
                    prepend-icon="tabler-file-invoice" :title="t('acc.to_invoice')" @click="convert(q)"
                  />
                </VList>
              </VMenu>
            </td>
          </tr>
          <tr v-if="!visibleQuotes.length"><td colspan="7" class="text-center text-medium-emphasis py-6">{{ t('acc.no_quotes') }}</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="dialog" fullscreen persistent scrollable transition="dialog-bottom-transition">
      <VCard>
        <VToolbar color="primary" density="comfortable">
          <VBtn icon="tabler-x" @click="dialog = false" />
          <VToolbarTitle>{{ editingId ? t('acc.edit_quote') : t('acc.new_quote_dlg') }}</VToolbarTitle>
          <VSpacer />
          <VBtn variant="flat" color="white" class="text-primary me-2" :loading="form.processing" :disabled="!form.party_id || total <= 0" @click="save">{{ editingId ? t('acc.save') : t('acc.create_quote') }}</VBtn>
        </VToolbar>
        <VCardText class="mx-auto py-6" style="max-width: 1600px; width: 100%">
          <div class="d-flex gap-3 mb-3 flex-wrap">
            <VAutocomplete v-model="form.party_id" :items="customers.map(c => ({ title: c.name, value: c.id }))" :label="t('acc.customer')" variant="outlined" density="comfortable" style="min-width: 240px" :error-messages="(form.errors as any).party_id" @update:model-value="onCustomerSelected">
              <template #append>
                <VBtn icon="tabler-user-plus" size="small" variant="tonal" color="primary" :title="t('acc.add_customer')" @click="custDialog = true" />
              </template>
            </VAutocomplete>
            <VTextField v-model="form.customer_ref" :label="t('acc.customer_ref')" variant="outlined" density="comfortable" style="max-width: 180px" />
            <!-- Their supplier account for us — one per customer, not one per company. -->
            <VTextField v-model="form.vendor_number" :label="t('acc.vendor_no')" :hint="t('acc.vendor_no_doc_hint')" persistent-hint variant="outlined" density="comfortable" style="max-width: 200px" />
            <VTextField v-model="form.quote_date" type="date" :label="t('acc.date')" variant="outlined" density="comfortable" style="max-width: 160px" />
            <VTextField v-model="form.valid_until" type="date" :label="t('acc.valid_until')" variant="outlined" density="comfortable" style="max-width: 170px" />
            <VSelect v-if="crm_active" v-model="form.crm_deal_id" :items="deals.map(d => ({ title: d.label, value: d.id }))" :label="t('acc.link_crm_deal')" variant="outlined" density="comfortable" clearable style="min-width: 220px" @update:model-value="onDealSelected" />
          </div>
          <div class="d-flex gap-3 mb-3 flex-wrap">
            <VAutocomplete v-model="form.sales_rep" :items="employees" :label="t('acc.sales_rep')" variant="outlined" density="comfortable" clearable style="max-width: 240px" />
            <VTextField v-model.number="form.discount_percent" type="number" :label="t('acc.discount_pct')" variant="outlined" density="comfortable" style="max-width: 150px" suffix="%" />
            <VTextarea v-model="form.delivery_address" :label="t('acc.delivery_address')" variant="outlined" density="comfortable" rows="2" auto-grow />
            <VTextarea v-model="form.postal_address" :label="t('acc.postal_address')" variant="outlined" density="comfortable" rows="2" auto-grow />
          </div>
          <div class="d-flex gap-3 mb-3 flex-wrap">
            <VTextField v-model="form.description" :label="t('acc.description_notes')" variant="outlined" density="comfortable" style="flex:1 1 300px" />
            <VSelect v-if="chargeTo.length" v-model="form.charge_to" :items="chargeTo" :label="t('acc.charge_to')" :hint="t('acc.charge_to_hint')" persistent-hint clearable variant="outlined" density="comfortable" style="min-width: 240px" :error-messages="(form.errors as any).charge_to" />
            <VSelect v-if="templates.length" v-model="form.template_id" :items="templates.map(tp => ({ title: tp.name + (tp.is_default ? ` · ${t('acc.default_label')}` : ''), value: tp.id }))" :label="t('acc.template')" variant="outlined" density="comfortable" style="max-width: 240px" @update:model-value="onTemplateChange" />
          </div>
          <VTextarea v-model="form.terms" :label="t('acc.terms_conditions')" :placeholder="t('acc.terms_ph')" variant="outlined" density="comfortable" rows="2" auto-grow class="mb-3" />

          <VTable density="compact">
            <thead><tr><th style="width: 22%">{{ t('acc.item') }}</th><th style="width: 18%">{{ t('acc.income_account') }}</th><th>{{ t('acc.description') }}</th><th style="width: 90px">{{ t('acc.grade') }}</th><th style="width: 70px">{{ t('acc.unit') }}</th><th class="text-right" style="width: 80px">{{ t('acc.qty') }}</th><th class="text-right" style="width: 120px">{{ t('acc.price') }}</th><th class="text-right" style="width: 80px">{{ t('acc.tax_pct') }}</th><th class="text-right">{{ t('acc.total') }}</th><th></th></tr></thead>
            <tbody>
              <template v-for="(l, i) in form.lines" :key="i">
                <tr>
                  <td class="pa-1"><VAutocomplete v-model="l.item_id" :items="items.map(it => ({ title: `${it.code} · ${it.name}`, value: it.id }))" density="compact" variant="outlined" hide-details clearable :placeholder="t('acc.search_item')" @update:model-value="applyItem(l)">
                    <template #append-item>
                      <VDivider />
                      <VListItem prepend-icon="tabler-plus" :title="t('acc.add_new_item')" class="text-primary" @click="openNewItem(l)" />
                    </template>
                  </VAutocomplete></td>
                  <td class="pa-1"><VAutocomplete v-model="l.account_id" :items="incomeAccounts.map(a => ({ title: a.label, value: a.id }))" density="compact" variant="outlined" hide-details /></td>
                  <td class="pa-1"><VTextField v-model="l.description" density="compact" variant="outlined" hide-details /></td>
                  <td class="pa-1"><VTextField v-model="l.grade" density="compact" variant="outlined" hide-details /></td>
                  <td class="pa-1"><VTextField v-model="l.unit" density="compact" variant="outlined" hide-details /></td>
                  <td class="pa-1"><VTextField v-model.number="l.quantity" type="number" density="compact" variant="outlined" hide-details reverse /></td>
                  <td class="pa-1"><VTextField v-model.number="l.unit_price" type="number" density="compact" variant="outlined" reverse :hint="l.price_basis ?? undefined" :persistent-hint="!!l.price_basis" :hide-details="!l.price_basis" /></td>
                  <td class="pa-1"><VTextField v-model.number="l.tax_rate" type="number" density="compact" variant="outlined" hide-details reverse /></td>
                  <td class="text-right pe-2">{{ money((Number(l.quantity) || 0) * (Number(l.unit_price) || 0)) }}</td>
                  <td class="pa-1 text-no-wrap">
                    <VBtn icon="tabler-list-search" size="x-small" variant="text" :title="t('acc.price_from_list')" :disabled="!l.item_id && !l.description" @click="priceFromList(l)" />
                    <VBtn :icon="l.note || l._showNote ? 'tabler-notes' : 'tabler-note'" size="x-small" variant="text" :color="l.note ? 'primary' : undefined" :title="t('acc.line_note')" @click="l._showNote = !l._showNote" />
                    <VBtn icon="tabler-x" size="x-small" variant="text" :disabled="form.lines.length <= 1" @click="form.lines.splice(i, 1)" />
                  </td>
                </tr>
                <tr v-if="l._showNote || l.note">
                  <td></td>
                  <td colspan="9" class="pa-1"><VTextField v-model="l.note" density="compact" variant="outlined" hide-details :placeholder="t('acc.line_note_placeholder')" prepend-inner-icon="tabler-note" /></td>
                </tr>
              </template>
            </tbody>
            <tfoot>
              <tr><td colspan="8" class="text-right">{{ t('acc.subtotal') }}</td><td class="text-right">{{ money(grossSubtotal) }}</td><td></td></tr>
              <tr v-if="discountAmount > 0"><td colspan="8" class="text-right">{{ t('acc.discount') }} ({{ form.discount_percent }}%)</td><td class="text-right text-error">−{{ money(discountAmount) }}</td><td></td></tr>
              <tr><td colspan="8" class="text-right">{{ t('acc.vat') }}</td><td class="text-right">{{ money(taxTotal) }}</td><td></td></tr>
              <tr class="font-weight-bold"><td colspan="8" class="text-right">{{ t('acc.total') }}</td><td class="text-right">{{ money(total) }}</td><td></td></tr>
            </tfoot>
          </VTable>
          <VBtn size="small" variant="text" prepend-icon="tabler-plus" class="mt-2" @click="addLine">{{ t('acc.add_line') }}</VBtn>
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('acc.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" :disabled="!form.party_id || total <= 0" @click="save">{{ editingId ? t('acc.save') : t('acc.create_quote') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Quick add customer -->
    <VDialog v-model="custDialog" max-width="440">
      <VCard rounded="lg">
        <VCardTitle class="text-body-1 font-weight-semibold">{{ t('acc.add_customer') }}</VCardTitle>
        <VDivider />
        <VCardText>
          <VTextField v-model="custForm.name" :label="t('acc.name')" variant="outlined" density="comfortable" class="mb-2" :error-messages="(custForm.errors as any).name" autofocus />
          <VTextField v-model="custForm.email" type="email" :label="t('acc.email')" variant="outlined" density="comfortable" class="mb-2" :error-messages="(custForm.errors as any).email" />
          <VTextField v-model="custForm.phone" :label="t('acc.phone')" variant="outlined" density="comfortable" />
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="custDialog = false">{{ t('acc.cancel') }}</VBtn>
          <VBtn color="primary" :loading="custForm.processing" :disabled="!custForm.name" @click="saveCustomer">{{ t('acc.add') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Quick add item -->
    <VDialog v-model="itemDialog" max-width="460">
      <VCard rounded="lg">
        <VCardTitle class="text-body-1 font-weight-semibold">{{ t('acc.add_new_item') }}</VCardTitle>
        <VDivider />
        <VCardText>
          <VTextField v-model="itemForm.name" :label="t('acc.name')" variant="outlined" density="comfortable" class="mb-2" :error-messages="(itemForm.errors as any).name" autofocus />
          <div class="d-flex gap-2">
            <VSelect v-model="itemForm.type" :items="[{ title: t('acc.type_service'), value: 'service' }, { title: t('acc.type_product'), value: 'product' }]" :label="t('acc.type')" variant="outlined" density="comfortable" style="max-width: 160px" />
            <VTextField v-model.number="itemForm.sales_price" type="number" :label="t('acc.price')" variant="outlined" density="comfortable" />
            <VTextField v-model="itemForm.unit" :label="t('acc.unit')" variant="outlined" density="comfortable" style="max-width: 120px" />
          </div>
        </VCardText>
        <VDivider />
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="itemDialog = false">{{ t('acc.cancel') }}</VBtn>
          <VBtn color="primary" :loading="itemForm.processing" :disabled="!itemForm.name" @click="saveItem">{{ t('acc.add') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirm" />
  </div>
</template>
