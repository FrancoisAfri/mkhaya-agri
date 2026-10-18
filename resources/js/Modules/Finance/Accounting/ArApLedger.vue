<script lang="ts" setup>
import { ref, computed, onMounted } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute

interface Invoice { id: number; number: string; party: string; party_id: number; party_ref: string | null; vendor_number: string | null; date: string; due: string; total: number; paid: number; outstanding: number; status: string; description: string | null; doc_type: string; terms: string | null; template_id: number | null; editable: boolean; lines: any[] }
interface Party { id: number; name: string; code: string; on_hold: boolean; vendor_number: string | null }
interface Option { id: number; label: string }
interface AgeRow { party_id: number; party: string; current: number; '1-30': number; '31-60': number; '61-90': number; '91-120': number; '120+': number; total: number }
const props = defineProps<{
  direction: 'receivable' | 'payable'
  as_of: string
  invoices: Invoice[]
  parties: Party[]
  lineAccounts: Option[]
  banks: { code: string; label: string }[]
  items: { id: number; code: string; name: string; description: string | null; unit: string; price: number; tax_rate: number; account_id: number | null }[]
  ageing: { labels: string[]; rows: AgeRow[]; totals: any; gl_control_code: string; gl_balance: number; ties: boolean }
  can_writeoff?: boolean
  templates: { id: number; name: string; terms: string | null; is_default: boolean }[]
  defaultTerms: string | null
}>()

const defaultTemplateId = props.templates?.find(tp => tp.is_default)?.id ?? props.templates?.[0]?.id ?? null
function onTemplateChange(id: number | null) {
  const tpl = props.templates.find(tp => tp.id === id)
  if (!invForm.terms) invForm.terms = tpl?.terms || props.defaultTerms || ''
}

const { t: tr } = useI18n()
const isAr = computed(() => props.direction === 'receivable')

/** Filters the documents already on the page — our number, theirs, party, status, dates. */
const search = ref('')
const visibleInvoices = computed(() => {
  const term = search.value.trim().toLowerCase()
  if (!term) return props.invoices

  return props.invoices.filter(i =>
    [i.number, i.party_ref, i.party, i.status, i.date, i.due, i.description]
      .some(v => (v ?? '').toString().toLowerCase().includes(term)))
})
const t = computed(() => isAr.value
  ? { entity: tr('acc.customer'), doc: tr('acc.invoice'), pay: tr('acc.receipt'), payDir: 'receipt', accs: tr('acc.income_account') }
  : { entity: tr('acc.supplier'), doc: tr('acc.bill'), pay: tr('acc.payment'), payDir: 'payment', accs: tr('acc.expense_account') })

const tab = ref('docs')
const money = (n: number) => (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const statusColor: Record<string, string> = { open: 'info', part_paid: 'warning', paid: 'success', void: 'default' }

// ── New invoice / bill ─────────────────────────────────────────────────────
const invDialog = ref(false)
const blankLine = (): any => ({ account_id: null as number | null, item_id: null as number | null, description: '', note: '', _showNote: false, quantity: 1, unit_price: null as number | null, discount_rate: 0, tax_rate: 15 })
const confirm = ref()
function emailInvoice(inv: Invoice) {
  confirm.value.open(
    { title: tr('acc.email_document'), message: tr('acc.email_confirm', { number: inv.number, party: inv.party }), confirmText: tr('acc.send'), confirmColor: 'primary', icon: 'tabler-mail' },
    () => useForm({}).post(route('finance.accounting.invoices.email', inv.id), { preserveScroll: true }),
  )
}
function whatsappInvoice(inv: Invoice) {
  confirm.value.open(
    { title: tr('acc.whatsapp_document'), message: tr('acc.whatsapp_confirm', { number: inv.number, party: inv.party }), confirmText: tr('acc.send'), confirmColor: 'success', icon: 'tabler-brand-whatsapp' },
    () => useForm({}).post(route('finance.accounting.invoices.whatsapp', inv.id), { preserveScroll: true }),
  )
}

// Credit note (AR only)
const creditDialog = ref(false)
const creditInvoice = ref<Invoice | null>(null)
const creditForm = useForm({ amount: null as number | null, reason: '' })
function openCredit(inv: Invoice) { creditInvoice.value = inv; creditForm.reset(); creditForm.amount = inv.outstanding; creditDialog.value = true }
function saveCredit() {
  const r = isAr.value ? 'finance.accounting.invoices.credit-note' : 'finance.accounting.invoices.debit-note'
  creditForm.post(route(r, creditInvoice.value!.id), { preserveScroll: true, onSuccess: () => { creditDialog.value = false } })
}

// Bad-debt write-off (AR only, permission-gated)
const woDialog = ref(false)
const woInvoice = ref<Invoice | null>(null)
const woForm = useForm({ amount: null as number | null, reason: '' })
function openWriteoff(inv: Invoice) { woInvoice.value = inv; woForm.reset(); woForm.amount = inv.outstanding; woDialog.value = true }
function saveWriteoff() { woForm.post(route('finance.accounting.invoices.write-off', woInvoice.value!.id), { preserveScroll: true, onSuccess: () => { woDialog.value = false } }) }
const docLabel = computed<Record<string, string>>(() => ({ credit_note: tr('acc.credit_note'), debit_note: tr('acc.debit_note'), writeoff: tr('acc.write_off') }))
function applyItem(l: any) {
  const it = props.items.find(i => i.id === l.item_id)
  if (!it) return
  l.description = it.name + (it.description ? ` — ${it.description}` : '')
  l.unit_price = it.price
  l.tax_rate = it.tax_rate
  if (it.account_id) l.account_id = it.account_id
}
/**
 * Fill in what the chosen customer already tells us.
 *
 * Only when the field is still empty: somebody who has corrected the number on this one invoice —
 * a client mid-way through changing their purchasing system, say — must not have it overwritten by
 * picking the customer again.
 */
function applyPartyDefaults(id: number | null) {
  if (invForm.vendor_number) return
  invForm.vendor_number = props.parties.find(p => p.id === id)?.vendor_number ?? ''
}

const invForm = useForm<any>({ party_id: null, direction: props.direction, invoice_date: props.as_of, due_date: '', party_ref: '', vendor_number: '', description: '', template_id: defaultTemplateId, terms: '', lines: [blankLine()] })
function openInvoice() { invForm.reset(); editingInvoiceId.value = null; invForm.direction = props.direction; invForm.invoice_date = props.as_of; invForm.template_id = defaultTemplateId; invForm.terms = (props.templates.find(tp => tp.id === defaultTemplateId)?.terms) || props.defaultTerms || ''; invForm.lines = [blankLine()]; invDialog.value = true }
// Mirrors ArApService: gross, less the discount, and VAT on what is left — tax is due on the
// consideration actually charged, so a total computed on the gross would disagree with the posted
// invoice and the customer would be quoted a figure they are never billed.
const lineGross = (l: any) => (Number(l.quantity) || 0) * (Number(l.unit_price) || 0)
const lineDiscount = (l: any) => lineGross(l) * Math.min(100, Math.max(0, Number(l.discount_rate) || 0)) / 100
const lineNet = (l: any) => lineGross(l) - lineDiscount(l)

const invDiscount = computed(() => invForm.lines.reduce((s: number, l: any) => s + lineDiscount(l), 0))
const invSubtotal = computed(() => invForm.lines.reduce((s: number, l: any) => s + lineNet(l), 0))
const invTax = computed(() => invForm.lines.reduce((s: number, l: any) => s + lineNet(l) * (Number(l.tax_rate) || 0) / 100, 0))
const invTotal = computed(() => invSubtotal.value + invTax.value)
/*
 * The same dialog raises and amends.
 *
 * Holding the id is the whole difference: null posts a new invoice, set PATCHes the one being
 * corrected. A separate edit form would be a second place for the line maths to drift from
 * ArApService, and a total on screen that disagrees with the one posted is the bug this file's
 * comment above already warns about.
 */
const editingInvoiceId = ref<number | null>(null)

function openEditInvoice(inv: Invoice) {
  invForm.reset()
  editingInvoiceId.value = inv.id
  invForm.direction = props.direction
  invForm.party_id = inv.party_id
  invForm.invoice_date = inv.date
  invForm.due_date = inv.due ?? ''
  invForm.party_ref = inv.party_ref ?? ''
  invForm.vendor_number = inv.vendor_number ?? ''
  invForm.description = inv.description ?? ''
  invForm.template_id = inv.template_id ?? defaultTemplateId
  invForm.terms = inv.terms ?? ''
  // Opened on what the invoice actually says; retyping it is how an amendment ends up differing
  // from the original in ways nobody intended.
  invForm.lines = (inv.lines ?? []).length ? JSON.parse(JSON.stringify(inv.lines)) : [blankLine()]
  invDialog.value = true
}

function saveInvoice() {
  const done = { preserveScroll: true, onSuccess: () => { invDialog.value = false; editingInvoiceId.value = null } }

  if (editingInvoiceId.value) {
    invForm.patch(route('finance.accounting.invoices.update', editingInvoiceId.value), done)

    return
  }

  invForm.post(route('finance.accounting.invoices.store'), done)
}

// ── Inline "add customer/supplier" from the party dropdown (no blocking fields) ─
const custDialog = ref(false)
const custForm = useForm({ kind: props.direction === 'receivable' ? 'customer' : 'supplier', name: '', email: '', phone: '' })
function saveParty() {
  custForm.post(route('finance.accounting.parties.store'), {
    preserveScroll: true, preserveState: true,
    onSuccess: () => {
      const match = [...props.parties].reverse().find(p => p.name === custForm.name)
      if (match) invForm.party_id = match.id
      custDialog.value = false
      custForm.reset('name', 'email', 'phone')
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
    preserveScroll: true, preserveState: true,
    onSuccess: () => {
      const match = [...props.items].reverse().find(i => i.name === itemForm.name)
      if (match && itemTargetLine.value) { itemTargetLine.value.item_id = match.id; applyItem(itemTargetLine.value) }
      itemDialog.value = false
      itemForm.reset()
    },
  })
}

// Preselect a party + open the invoice dialog when arriving from the customer/supplier list.
onMounted(() => {
  const pid = Number(new URLSearchParams(window.location.search).get('party') || '')
  if (pid && props.parties.some(p => p.id === pid)) { openInvoice(); invForm.party_id = pid }
})

// ── Record receipt / payment ───────────────────────────────────────────────
const payDialog = ref(false)
const payForm = useForm<any>({ party_id: null, direction: t.value.payDir, payment_date: props.as_of, bank_account_id: props.banks[0]?.code ?? '', reference: '', allocations: [] as any[] })
const partyOpen = computed(() => payForm.party_id ? props.invoices.filter(i => i.party_id === payForm.party_id && i.outstanding > 0.001 && i.status !== 'void') : [])
const payTotal = computed(() => payForm.allocations.reduce((s: number, a: any) => s + (Number(a.amount) || 0), 0))
// Sage-style: the user enters the money received, then allocates it across open items.
const amountReceived = ref<number | null>(null)
const remaining = computed(() => (Number(amountReceived.value) || 0) - payTotal.value)
const remainingClass = computed(() => Math.abs(remaining.value) < 0.005 ? 'text-success' : (remaining.value < 0 ? 'text-error' : 'text-warning'))
function openPayment(inv?: Invoice) {
  payForm.reset(); payForm.direction = t.value.payDir; payForm.payment_date = props.as_of; payForm.bank_account_id = props.banks[0]?.code ?? ''
  payForm.party_id = inv?.party_id ?? null
  payForm.allocations = []
  amountReceived.value = inv ? inv.outstanding : null
  payDialog.value = true
  if (inv) setTimeout(() => { syncAllocations(); const a = payForm.allocations.find((x: any) => x.invoice_id === inv.id); if (a) a.amount = inv.outstanding }, 0)
}
function syncAllocations() {
  payForm.allocations = partyOpen.value.map(i => ({ invoice_id: i.id, number: i.number, party_ref: i.party_ref, date: i.date, outstanding: i.outstanding, amount: 0 }))
}
function payRowFull(a: any) { a.amount = a.outstanding }
function payAllInFull() { payForm.allocations.forEach((a: any) => { a.amount = a.outstanding }) }
function clearAllocations() { payForm.allocations.forEach((a: any) => { a.amount = 0 }) }
// Distribute the amount received across open items, oldest first (Sage "Automatic").
function autoAllocate() {
  const target = Number(amountReceived.value) || 0
  if (target <= 0) { payAllInFull(); return }
  let remain = target
  const order = [...payForm.allocations].sort((x: any, y: any) => String(x.date ?? '').localeCompare(String(y.date ?? '')))
  for (const a of order) {
    const amt = Math.min(a.outstanding, Math.max(0, remain))
    a.amount = Number(amt.toFixed(2))
    remain = Number((remain - amt).toFixed(2))
  }
}
function savePayment() { payForm.post(route('finance.accounting.payments.store'), { preserveScroll: true, onSuccess: () => { payDialog.value = false } }) }

function voidInvoice(inv: Invoice) {
  confirm.value.open(
    { title: tr('acc.void_document'), message: tr('acc.void_confirm', { number: inv.number }), confirmText: tr('acc.void'), confirmColor: 'error', icon: 'tabler-ban' },
    () => useForm({}).post(route('finance.accounting.invoices.void', inv.id), { preserveScroll: true }),
  )
}
function reloadAgeing(v: string) { router.get(route(isAr.value ? 'finance.accounting.receivables' : 'finance.accounting.payables'), { as_of: v }, { preserveState: true, preserveScroll: true }) }
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ tr('acc.finance') }} · {{ isAr ? tr('acc.receivables') : tr('acc.payables') }}</div>
        <h2 class="text-h5 mb-0">{{ isAr ? tr('acc.accounts_receivable') : tr('acc.accounts_payable') }}</h2>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" prepend-icon="tabler-cash" @click="openPayment()">{{ isAr ? tr('acc.record_receipt') : tr('acc.record_payment') }}</VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="openInvoice">{{ isAr ? tr('acc.new_invoice') : tr('acc.new_bill') }}</VBtn>
      </div>
    </div>

    <VAlert v-if="(invForm.errors as any).invoice" type="error" variant="tonal" density="compact" class="mb-3">{{ (invForm.errors as any).invoice }}</VAlert>
    <VAlert v-if="(payForm.errors as any).payment" type="error" variant="tonal" density="compact" class="mb-3">{{ (payForm.errors as any).payment }}</VAlert>
    <VAlert v-if="(creditForm.errors as any).creditnote" type="error" variant="tonal" density="compact" class="mb-3">{{ (creditForm.errors as any).creditnote }}</VAlert>
    <VAlert v-if="(woForm.errors as any).writeoff" type="error" variant="tonal" density="compact" class="mb-3">{{ (woForm.errors as any).writeoff }}</VAlert>

    <VTabs v-model="tab" class="mb-4">
      <VTab value="docs">{{ isAr ? tr('acc.invoices') : tr('acc.bills') }}</VTab>
      <VTab value="ageing">{{ tr('acc.ageing') }}</VTab>
    </VTabs>

    <!-- Documents -->
    <VCard v-if="tab === 'docs'" border>
      <VCardText class="pb-0">
        <VTextField
          v-model="search"
          :placeholder="tr('acc.search')"
          prepend-inner-icon="tabler-search"
          density="compact" variant="outlined" hide-details clearable single-line
          style="max-width: 340px"
        />
      </VCardText>
      <VTable>
        <thead><tr><th>{{ t.doc }} #</th><th>{{ t.entity }}</th><th>{{ tr('acc.date') }}</th><th>{{ tr('acc.due') }}</th><th class="text-right">{{ tr('acc.total') }}</th><th class="text-right">{{ tr('acc.outstanding') }}</th><th>{{ tr('acc.status') }}</th><th class="text-right"></th></tr></thead>
        <tbody>
          <tr v-for="i in visibleInvoices" :key="i.id">
            <td class="font-weight-medium">{{ i.number }}<span v-if="i.party_ref" class="text-caption text-medium-emphasis"> · {{ i.party_ref }}</span>
              <VChip v-if="i.doc_type !== 'invoice'" size="x-small" color="purple" variant="tonal" class="ms-1">{{ docLabel[i.doc_type] || i.doc_type }}</VChip></td>
              <!-- doc_type chip localised via docLabel computed -->
            <td>{{ i.party }}</td>
            <td class="text-caption">{{ i.date }}</td>
            <td class="text-caption">{{ i.due }}</td>
            <td class="text-right">{{ i.doc_type === 'credit_note' ? '−' : '' }}{{ money(i.total) }}</td>
            <td class="text-right font-weight-medium">{{ money(i.outstanding) }}</td>
            <td><VChip size="x-small" :color="statusColor[i.status]" variant="tonal" class="text-capitalize">{{ i.status.replace('_', ' ') }}</VChip></td>
            <td class="text-right text-no-wrap">
              <!-- One menu, like the quotes list: a row of seven controls is read as decoration
                   and the one that matters is found by hovering each in turn. -->
              <VMenu location="bottom end">
                <template #activator="{ props: menu }">
                  <VBtn
                    v-bind="menu" icon="tabler-dots-vertical" size="small" variant="text"
                    :aria-label="tr('acc.actions_for', { number: i.number })"
                  />
                </template>
                <VList density="compact" min-width="220">
                  <!-- Only while nothing has been settled against it; the service refuses the
                       rest with a reason, so this is a convenience rather than the guard. -->
                  <VListItem
                    v-if="i.editable" prepend-icon="tabler-pencil"
                    :title="tr('acc.edit')" @click="openEditInvoice(i)"
                  />
                  <VListItem
                    prepend-icon="tabler-file-type-pdf" :title="tr('acc.download_pdf')"
                    :href="route('finance.accounting.invoices.pdf', i.id)" target="_blank"
                  />
                  <VListItem prepend-icon="tabler-mail" :title="tr('acc.email')" @click="emailInvoice(i)" />
                  <VListItem prepend-icon="tabler-brand-whatsapp" :title="tr('acc.whatsapp')" @click="whatsappInvoice(i)" />

                  <template v-if="i.doc_type === 'invoice'">
                    <VDivider class="my-1" />

                    <VListItem
                      v-if="i.outstanding > 0.001 && i.status !== 'void'"
                      prepend-icon="tabler-cash" :title="t.pay" @click="openPayment(i)"
                    />
                    <VListItem
                      v-if="i.outstanding > 0.001 && i.status !== 'void'"
                      prepend-icon="tabler-receipt-refund"
                      :title="isAr ? tr('acc.credit') : tr('acc.debit')" @click="openCredit(i)"
                    />
                    <VListItem
                      v-if="isAr && can_writeoff && i.outstanding > 0.001 && i.status !== 'void'"
                      prepend-icon="tabler-eraser" :title="tr('acc.write_off')" @click="openWriteoff(i)"
                    />
                    <VListItem
                      v-if="i.paid === 0 && i.status !== 'void'"
                      prepend-icon="tabler-ban" :title="tr('acc.void')" @click="voidInvoice(i)"
                    />
                  </template>
                </VList>
              </VMenu>
            </td>
          </tr>
          <tr v-if="!visibleInvoices.length"><td colspan="8" class="text-center text-medium-emphasis py-6">{{ isAr ? tr('acc.no_invoices') : tr('acc.no_bills') }}</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <!-- Ageing -->
    <VCard v-else border>
      <VCardText class="d-flex align-center gap-3 flex-wrap">
        <VTextField :model-value="as_of" type="date" :label="tr('acc.as_at')" density="compact" hide-details style="max-width: 180px" @update:model-value="reloadAgeing" />
        <VChip :color="ageing.ties ? 'success' : 'error'" variant="tonal" size="small">
          {{ ageing.ties ? tr('acc.ties_gl') : tr('acc.no_tie_gl') }} · {{ tr('acc.control') }} {{ ageing.gl_control_code }} = {{ money(ageing.gl_balance) }}
        </VChip>
      </VCardText>
      <VTable>
        <thead><tr><th>{{ t.entity }}</th><th v-for="l in ageing.labels" :key="l" class="text-right text-capitalize">{{ l }}</th><th class="text-right">{{ tr('acc.total') }}</th></tr></thead>
        <tbody>
          <tr v-for="r in ageing.rows" :key="r.party_id">
            <td>{{ r.party }}</td>
            <td v-for="l in ageing.labels" :key="l" class="text-right">{{ (r as any)[l] ? money((r as any)[l]) : '' }}</td>
            <td class="text-right font-weight-medium">{{ money(r.total) }}</td>
          </tr>
          <tr v-if="!ageing.rows.length"><td :colspan="ageing.labels.length + 2" class="text-center text-medium-emphasis py-6">{{ tr('acc.nothing_outstanding') }}</td></tr>
        </tbody>
        <tfoot v-if="ageing.rows.length">
          <tr class="font-weight-bold"><td>{{ tr('acc.total') }}</td><td v-for="l in ageing.labels" :key="l" class="text-right">{{ money(ageing.totals[l]) }}</td><td class="text-right">{{ money(ageing.totals.total) }}</td></tr>
        </tfoot>
      </VTable>
    </VCard>

    <!-- Credit note dialog -->
    <VDialog v-model="creditDialog" max-width="480">
      <VCard>
        <div class="d-flex align-center px-5 py-3 text-white" style="background: rgb(var(--v-theme-purple, 128 90 213))">
          <VIcon class="me-2">tabler-file-minus</VIcon>
          <span class="text-h6">{{ isAr ? tr('acc.credit_note') : tr('acc.debit_note') }}</span>
          <VSpacer />
          <span class="text-body-2">{{ creditInvoice?.number }}</span>
        </div>
        <VCardText class="pt-4">
          <div class="d-flex align-center justify-space-between mb-3">
            <div>
              <div class="text-caption text-medium-emphasis">{{ t.entity }}</div>
              <div class="text-body-1 font-weight-medium">{{ creditInvoice?.party }}</div>
            </div>
            <div class="text-right">
              <div class="text-caption text-medium-emphasis">{{ tr('acc.outstanding') }}</div>
              <div class="text-body-1 font-weight-bold" style="font-variant-numeric: tabular-nums">R{{ money(creditInvoice?.outstanding || 0) }}</div>
            </div>
          </div>
          <p class="text-caption text-medium-emphasis mb-3">{{ isAr ? tr('acc.credit_ar_help') : tr('acc.credit_ap_help') }}</p>
          <VTextField v-model.number="creditForm.amount" type="number" :label="isAr ? tr('acc.credit_amount') : tr('acc.debit_amount')" variant="outlined" density="comfortable" reverse prefix="R" :max="creditInvoice?.outstanding" class="mb-3" hide-details>
            <template #append-inner>
              <VBtn size="x-small" variant="text" color="primary" @click="creditForm.amount = creditInvoice?.outstanding ?? null">{{ tr('acc.full') }}</VBtn>
            </template>
          </VTextField>
          <VTextarea v-model="creditForm.reason" :label="tr('acc.reason')" variant="outlined" density="comfortable" rows="2" class="mt-3" :error-messages="creditForm.errors.reason" />
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="creditDialog = false">{{ tr('acc.cancel') }}</VBtn><VBtn color="purple" :loading="creditForm.processing" :disabled="!creditForm.amount || !creditForm.reason" @click="saveCredit">{{ isAr ? tr('acc.post_credit_note') : tr('acc.post_debit_note') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Bad-debt write-off dialog -->
    <VDialog v-model="woDialog" max-width="480">
      <VCard>
        <div class="d-flex align-center px-5 py-3 text-white" style="background: rgb(var(--v-theme-error))">
          <VIcon class="me-2">tabler-receipt-off</VIcon>
          <span class="text-h6">{{ tr('acc.write_off') }}</span>
          <VSpacer />
          <span class="text-body-2">{{ woInvoice?.number }}</span>
        </div>
        <VCardText class="pt-4">
          <div class="d-flex align-center justify-space-between mb-3">
            <div>
              <div class="text-caption text-medium-emphasis">{{ t.entity }}</div>
              <div class="text-body-1 font-weight-medium">{{ woInvoice?.party }}</div>
            </div>
            <div class="text-right">
              <div class="text-caption text-medium-emphasis">{{ tr('acc.outstanding') }}</div>
              <div class="text-body-1 font-weight-bold" style="font-variant-numeric: tabular-nums">R{{ money(woInvoice?.outstanding || 0) }}</div>
            </div>
          </div>
          <p class="text-caption text-medium-emphasis mb-3">{{ tr('acc.writeoff_help') }}</p>
          <VTextField v-model.number="woForm.amount" type="number" :label="tr('acc.writeoff_amount')" variant="outlined" density="comfortable" reverse prefix="R" :max="woInvoice?.outstanding" class="mb-3" hide-details>
            <template #append-inner>
              <VBtn size="x-small" variant="text" color="primary" @click="woForm.amount = woInvoice?.outstanding ?? null">{{ tr('acc.full') }}</VBtn>
            </template>
          </VTextField>
          <VTextarea v-model="woForm.reason" :label="tr('acc.reason')" variant="outlined" density="comfortable" rows="2" class="mt-3" :error-messages="woForm.errors.reason" />
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="woDialog = false">{{ tr('acc.cancel') }}</VBtn><VBtn color="error" :loading="woForm.processing" :disabled="!woForm.amount || !woForm.reason" @click="saveWriteoff">{{ tr('acc.write_off') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- New invoice / bill dialog -->
    <VDialog v-model="invDialog" max-width="1200" persistent>
      <VCard>
        <VCardTitle>{{ isAr ? tr('acc.new_invoice') : tr('acc.new_bill') }}</VCardTitle>
        <VCardText>
          <div class="d-flex gap-3 mb-3 flex-wrap">
            <VSelect v-model="invForm.party_id" :items="parties.map(p => ({ title: p.name + (p.on_hold ? ` ${tr('acc.on_hold')}` : ''), value: p.id }))" :label="t.entity" variant="outlined" density="comfortable" style="min-width: 240px" :error-messages="(invForm.errors as any).party_id" @update:model-value="applyPartyDefaults">
              <template #append>
                <VBtn icon="tabler-user-plus" size="small" variant="tonal" color="primary" :title="isAr ? tr('acc.add_customer') : tr('acc.add_supplier')" @click="custDialog = true" />
              </template>
            </VSelect>
            <VTextField v-model="invForm.invoice_date" type="date" :label="tr('acc.date')" variant="outlined" density="comfortable" style="max-width: 170px" />
            <VTextField v-model="invForm.due_date" type="date" :label="tr('acc.due_auto')" variant="outlined" density="comfortable" style="max-width: 190px" />
            <VTextField v-if="!isAr" v-model="invForm.party_ref" :label="tr('acc.supplier_ref')" variant="outlined" density="comfortable" style="max-width: 170px" />
            <!--
              Receivables only, and filled in from the customer above. It is the supplier account
              *they* file us under, so an invoice missing it can sit unmatched in their accounts
              payable until somebody telephones about it.
            -->
            <VTextField v-if="isAr" v-model="invForm.vendor_number" :label="tr('acc.vendor_no')" :hint="tr('acc.vendor_no_doc_hint')" persistent-hint variant="outlined" density="comfortable" style="max-width: 200px" />
          </div>
          <div class="d-flex gap-3 mb-3 flex-wrap">
            <VTextField v-model="invForm.description" :label="tr('acc.description')" variant="outlined" density="comfortable" style="flex:1 1 300px" />
            <VSelect v-if="templates && templates.length" v-model="invForm.template_id" :items="templates.map(tp => ({ title: tp.name + (tp.is_default ? ` · ${tr('acc.default_label')}` : ''), value: tp.id }))" :label="tr('acc.template')" variant="outlined" density="comfortable" style="max-width: 240px" @update:model-value="onTemplateChange" />
          </div>
          <VTextarea v-model="invForm.terms" :label="tr('acc.terms_conditions')" :placeholder="tr('acc.terms_ph')" variant="outlined" density="comfortable" rows="2" auto-grow class="mb-3" />

          <VTable density="compact">
            <thead><tr><th style="width: 20%">{{ tr('acc.item') }}</th><th style="width: 18%">{{ t.accs }}</th><th>{{ tr('acc.description') }}</th><th class="text-right" style="width: 80px">{{ tr('acc.qty') }}</th><th class="text-right" style="width: 110px">{{ tr('acc.unit_price') }}</th><th class="text-right" style="width: 80px">{{ tr('acc.discount_pct') }}</th><th class="text-right" style="width: 80px">{{ tr('acc.tax_pct') }}</th><th class="text-right">{{ tr('acc.line') }}</th><th></th></tr></thead>
            <tbody>
              <template v-for="(l, i) in invForm.lines" :key="i">
                <tr>
                  <td class="pa-1"><VAutocomplete v-model="l.item_id" :items="items.map(it => ({ title: `${it.code} · ${it.name}`, value: it.id }))" density="compact" variant="outlined" hide-details clearable :placeholder="tr('acc.search_item')" @update:model-value="applyItem(l)">
                    <template #append-item>
                      <VDivider />
                      <VListItem prepend-icon="tabler-plus" :title="tr('acc.add_new_item')" class="text-primary" @click="openNewItem(l)" />
                    </template>
                  </VAutocomplete></td>
                  <td class="pa-1"><VAutocomplete v-model="l.account_id" :items="lineAccounts.map(a => ({ title: a.label, value: a.id }))" density="compact" variant="outlined" hide-details :placeholder="tr('acc.account_ph')" /></td>
                  <td class="pa-1"><VTextField v-model="l.description" density="compact" variant="outlined" hide-details /></td>
                  <td class="pa-1"><VTextField v-model.number="l.quantity" type="number" density="compact" variant="outlined" hide-details reverse /></td>
                  <td class="pa-1"><VTextField v-model.number="l.unit_price" type="number" density="compact" variant="outlined" hide-details reverse /></td>
                  <td class="pa-1"><VTextField v-model.number="l.discount_rate" type="number" min="0" max="100" density="compact" variant="outlined" hide-details reverse /></td>
                  <td class="pa-1"><VTextField v-model.number="l.tax_rate" type="number" density="compact" variant="outlined" hide-details reverse /></td>
                  <td class="text-right pe-2">
                    {{ money(lineNet(l)) }}
                    <div v-if="lineDiscount(l) > 0" class="text-caption text-medium-emphasis">−{{ money(lineDiscount(l)) }}</div>
                  </td>
                  <td class="pa-1 text-no-wrap">
                    <VBtn :icon="l.note || l._showNote ? 'tabler-notes' : 'tabler-note'" size="x-small" variant="text" :color="l.note ? 'primary' : undefined" title="Line note" @click="l._showNote = !l._showNote" />
                    <VBtn icon="tabler-x" size="x-small" variant="text" :disabled="invForm.lines.length <= 1" @click="invForm.lines.splice(i, 1)" />
                  </td>
                </tr>
                <tr v-if="l._showNote || l.note">
                  <td></td>
                  <td colspan="7" class="pa-1"><VTextField v-model="l.note" density="compact" variant="outlined" hide-details :placeholder="tr('acc.line_note_ph')" prepend-inner-icon="tabler-note" /></td>
                </tr>
              </template>
            </tbody>
            <tfoot>
              <tr v-if="invDiscount > 0"><td colspan="7" class="text-right">{{ tr('acc.discount') }}</td><td class="text-right">−{{ money(invDiscount) }}</td><td></td></tr>
              <tr><td colspan="7" class="text-right">{{ tr('acc.subtotal') }}</td><td class="text-right">{{ money(invSubtotal) }}</td><td></td></tr>
              <tr><td colspan="7" class="text-right">{{ tr('acc.vat') }}</td><td class="text-right">{{ money(invTax) }}</td><td></td></tr>
              <tr class="font-weight-bold"><td colspan="7" class="text-right">{{ tr('acc.total') }}</td><td class="text-right">{{ money(invTotal) }}</td><td></td></tr>
            </tfoot>
          </VTable>
          <VBtn size="small" variant="text" prepend-icon="tabler-plus" class="mt-2" @click="invForm.lines.push(blankLine())">{{ tr('acc.add_line') }}</VBtn>
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="invDialog = false">{{ tr('acc.cancel') }}</VBtn><VBtn color="primary" :loading="invForm.processing" :disabled="!invForm.party_id || invTotal <= 0" @click="saveInvoice">{{ isAr ? tr('acc.post_invoice') : tr('acc.post_bill') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Record receipt / payment dialog (Sage-style allocation) -->
    <VDialog v-model="payDialog" max-width="860" persistent>
      <VCard>
        <div class="d-flex align-center px-5 py-3 text-white" style="background: rgb(var(--v-theme-primary))">
          <VIcon class="me-2">{{ isAr ? 'tabler-cash-banknote' : 'tabler-cash' }}</VIcon>
          <span class="text-h6">{{ isAr ? tr('acc.record_receipt') : tr('acc.record_payment') }}</span>
        </div>
        <VCardText class="pt-4">
          <VRow dense>
            <VCol cols="12" sm="6">
              <VSelect v-model="payForm.party_id" :items="parties.map(p => ({ title: p.name, value: p.id }))" :label="t.entity" variant="outlined" density="comfortable" hide-details @update:model-value="syncAllocations" />
            </VCol>
            <VCol cols="6" sm="3">
              <VTextField v-model="payForm.payment_date" type="date" :label="tr('acc.date')" variant="outlined" density="comfortable" hide-details />
            </VCol>
            <VCol cols="6" sm="3">
              <VTextField v-model.number="amountReceived" type="number" :label="isAr ? tr('acc.amount_received') : tr('acc.amount_paid')" variant="outlined" density="comfortable" hide-details reverse prefix="R" />
            </VCol>
            <VCol cols="12" sm="6">
              <VSelect v-model="payForm.bank_account_id" :items="banks.map(b => ({ title: b.label, value: b.code }))" :label="tr('acc.bank_account')" variant="outlined" density="comfortable" hide-details />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField v-model="payForm.reference" :label="tr('acc.reference')" variant="outlined" density="comfortable" hide-details />
            </VCol>
          </VRow>

          <template v-if="payForm.allocations.length">
            <div class="d-flex align-center flex-wrap gap-2 mt-4 mb-1">
              <span class="text-subtitle-2 me-auto">{{ tr('acc.allocation') }}</span>
              <VBtn size="small" variant="tonal" color="primary" prepend-icon="tabler-wand" @click="autoAllocate">{{ tr('acc.auto_allocate') }}</VBtn>
              <VBtn size="small" variant="text" @click="payAllInFull">{{ tr('acc.pay_all') }}</VBtn>
              <VBtn size="small" variant="text" @click="clearAllocations">{{ tr('acc.clear_alloc') }}</VBtn>
            </div>

            <div style="max-height: 340px; overflow-y: auto; border: 1px solid rgba(127,127,127,.2); border-radius: 8px">
              <VTable density="compact" fixed-header height="340">
                <thead>
                  <tr>
                    <th style="width: 110px">{{ tr('acc.date') }}</th>
                    <th>{{ t.doc }}</th>
                    <th class="text-right">{{ tr('acc.outstanding') }}</th>
                    <th class="text-right" style="width: 210px">{{ tr('acc.allocate') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(a, i) in payForm.allocations" :key="i" :style="Number(a.amount) > 0 ? 'background: rgba(var(--v-theme-primary), .06)' : ''">
                    <td class="text-medium-emphasis">{{ a.date }}</td>
                    <td>
                      {{ a.number }}
                      <span v-if="a.party_ref" class="text-caption text-disabled"> · {{ a.party_ref }}</span>
                    </td>
                    <td class="text-right" style="font-variant-numeric: tabular-nums">{{ money(a.outstanding) }}</td>
                    <td class="pa-1">
                      <div class="d-flex align-center gap-1">
                        <VTextField v-model.number="a.amount" type="number" density="compact" variant="outlined" hide-details reverse :max="a.outstanding" style="min-width: 120px" />
                        <VBtn size="x-small" variant="text" color="primary" @click="payRowFull(a)">{{ tr('acc.full') }}</VBtn>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </div>

            <!-- Sage-style running totals -->
            <div class="d-flex flex-wrap justify-end gap-8 mt-3 px-1" style="font-variant-numeric: tabular-nums">
              <div v-if="(Number(amountReceived) || 0) > 0" class="text-right">
                <div class="text-caption text-medium-emphasis">{{ isAr ? tr('acc.amount_received') : tr('acc.amount_paid') }}</div>
                <div class="text-body-1 font-weight-medium">{{ money(Number(amountReceived) || 0) }}</div>
              </div>
              <div class="text-right">
                <div class="text-caption text-medium-emphasis">{{ tr('acc.allocated') }}</div>
                <div class="text-h6 font-weight-bold">{{ money(payTotal) }}</div>
              </div>
              <div v-if="(Number(amountReceived) || 0) > 0" class="text-right">
                <div class="text-caption text-medium-emphasis">{{ tr('acc.remaining') }}</div>
                <div class="text-body-1 font-weight-medium" :class="remainingClass">{{ money(remaining) }}</div>
              </div>
            </div>
          </template>
          <div v-else class="text-center text-medium-emphasis py-6">{{ isAr ? tr('acc.select_party_ar') : tr('acc.select_party_ap') }}</div>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="payDialog = false">{{ tr('acc.cancel') }}</VBtn>
          <VBtn color="primary" :loading="payForm.processing" :disabled="payTotal <= 0" @click="savePayment">{{ isAr ? tr('acc.record_receipt') : tr('acc.record_payment') }} · R{{ money(payTotal) }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Quick add customer / supplier -->
    <VDialog v-model="custDialog" max-width="440">
      <VCard rounded="lg">
        <VCardTitle class="text-body-1 font-weight-semibold">{{ isAr ? tr('acc.add_customer') : tr('acc.add_supplier') }}</VCardTitle>
        <VDivider />
        <VCardText>
          <VTextField v-model="custForm.name" :label="tr('acc.name')" variant="outlined" density="comfortable" class="mb-2" :error-messages="(custForm.errors as any).name" autofocus />
          <VTextField v-model="custForm.email" type="email" :label="tr('acc.email')" variant="outlined" density="comfortable" class="mb-2" :error-messages="(custForm.errors as any).email" />
          <VTextField v-model="custForm.phone" :label="tr('acc.phone')" variant="outlined" density="comfortable" />
        </VCardText>
        <VDivider />
        <VCardActions><VSpacer /><VBtn variant="text" @click="custDialog = false">{{ tr('acc.cancel') }}</VBtn><VBtn color="primary" :loading="custForm.processing" :disabled="!custForm.name" @click="saveParty">{{ tr('acc.add') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Quick add item -->
    <VDialog v-model="itemDialog" max-width="460">
      <VCard rounded="lg">
        <VCardTitle class="text-body-1 font-weight-semibold">{{ tr('acc.add_new_item') }}</VCardTitle>
        <VDivider />
        <VCardText>
          <VTextField v-model="itemForm.name" :label="tr('acc.name')" variant="outlined" density="comfortable" class="mb-2" :error-messages="(itemForm.errors as any).name" autofocus />
          <div class="d-flex gap-2">
            <VSelect v-model="itemForm.type" :items="[{ title: tr('acc.type_service'), value: 'service' }, { title: tr('acc.type_product'), value: 'product' }]" :label="tr('acc.type')" variant="outlined" density="comfortable" style="max-width: 160px" />
            <VTextField v-model.number="itemForm.sales_price" type="number" :label="tr('acc.price')" variant="outlined" density="comfortable" />
            <VTextField v-model="itemForm.unit" :label="tr('acc.unit')" variant="outlined" density="comfortable" style="max-width: 120px" />
          </div>
        </VCardText>
        <VDivider />
        <VCardActions><VSpacer /><VBtn variant="text" @click="itemDialog = false">{{ tr('acc.cancel') }}</VBtn><VBtn color="primary" :loading="itemForm.processing" :disabled="!itemForm.name" @click="saveItem">{{ tr('acc.add') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirm" />
  </div>
</template>
