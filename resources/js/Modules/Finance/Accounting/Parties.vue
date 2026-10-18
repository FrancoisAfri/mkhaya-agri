<script lang="ts" setup>
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const { t } = useI18n()

interface Party { id: number; kind: string; category: string | null; price_list_id: number | null; source: string; code: string; name: string; contact_name: string | null; email: string | null; website: string | null; phone: string | null; fax: string | null; vat_number: string | null; tax_exempt: boolean; registration_number: string | null; vendor_number: string | null; bank_name: string | null; bank_account: string | null; credit_terms_days: number; credit_limit: number | null; sales_rep: string | null; default_discount_percent: number | null; on_hold: boolean; is_active: boolean; synced: boolean; locked?: string[] }
const props = defineProps<{ kind: 'customer' | 'supplier'; parties: Party[]; synced: boolean; source_name: string; categories: string[]; priceLists?: { id: number; name: string }[]; people?: { id: number; name: string }[] }>()

const isCustomer = computed(() => props.kind === 'customer')
const title = computed(() => (isCustomer.value ? t('acc.customers') : t('acc.suppliers')))
const search = ref('')
const categoryFilter = ref<string | null>(null)
const dialog = ref(false)
const editing = ref<Party | null>(null)

/**
 * Fields the source system rewrites on every sync.
 *
 * Shown, but not editable: typing into one would appear to save and then quietly revert the next
 * time the sync runs, which is worse than never offering it. Everything Accounting owns — the VAT
 * number, the credit terms and limit, the sales rep, the postal address — stays editable on a
 * synced record, because CRM has none of those to send down and refusing them meant they could
 * never be set at all.
 */
const lockedFields = computed(() => new Set(editing.value?.locked ?? []))
const isLocked = (field: string) => lockedFields.value.has(field)
const form = useForm({ kind: props.kind, category: '', price_list_id: null as number | null, code: '', name: '', contact_name: '', email: '', website: '', phone: '', fax: '', address: '', city: '', country: '', postal_address: '', vat_number: '', tax_exempt: false, registration_number: '', vendor_number: '', bank_name: '', bank_account: '', credit_terms_days: 30, credit_limit: null as number | null, sales_rep: '', default_discount_percent: null as number | null, on_hold: false, is_active: true })
const syncForm = useForm({ kind: props.kind })
const confirm = ref()

// Merge duplicates
const mergeDialog = ref(false)
const mergeForm = useForm({ survivor_id: null as number | null, duplicate_id: null as number | null })
const partyOptions = computed(() => props.parties.map(p => ({ title: `${p.name} (${p.code})`, value: p.id })))
function doMerge() {
  if (!mergeForm.survivor_id || !mergeForm.duplicate_id) return
  const dup = props.parties.find(p => p.id === mergeForm.duplicate_id)
  const surv = props.parties.find(p => p.id === mergeForm.survivor_id)
  confirm.value.open(
    { title: t('acc.merge_parties_title'), message: t('acc.merge_confirm', { dup: dup?.name, surv: surv?.name }), confirmText: t('acc.merge'), confirmColor: 'error', icon: 'tabler-arrows-join' },
    () => mergeForm.post(route('finance.accounting.parties.merge'), { preserveScroll: true, onSuccess: () => { mergeDialog.value = false; mergeForm.reset() } }),
  )
}

const filtered = computed(() => {
  let r = props.parties
  if (categoryFilter.value) r = r.filter(p => p.category === categoryFilter.value)
  if (search.value) { const q = search.value.toLowerCase(); r = r.filter(p => p.name.toLowerCase().includes(q) || p.code.toLowerCase().includes(q)) }
  return r
})

function openNew() { editing.value = null; form.reset(); form.kind = props.kind; dialog.value = true }
function openEdit(p: Party) {
  editing.value = p
  Object.assign(form, { kind: p.kind, category: p.category ?? '', price_list_id: p.price_list_id, code: p.code, name: p.name, contact_name: p.contact_name ?? '', email: p.email ?? '', website: p.website ?? '', phone: p.phone ?? '', fax: p.fax ?? '', address: (p as any).address ?? '', city: (p as any).city ?? '', country: (p as any).country ?? '', postal_address: (p as any).postal_address ?? '', vat_number: p.vat_number ?? '', tax_exempt: p.tax_exempt, registration_number: p.registration_number ?? '', vendor_number: p.vendor_number ?? '', bank_name: p.bank_name ?? '', bank_account: p.bank_account ?? '', credit_terms_days: p.credit_terms_days, credit_limit: p.credit_limit, sales_rep: p.sales_rep ?? '', default_discount_percent: p.default_discount_percent, on_hold: p.on_hold, is_active: p.is_active })
  dialog.value = true
}
function save() {
  const opts = { preserveScroll: true, onSuccess: () => { dialog.value = false } }
  editing.value ? form.patch(route('finance.accounting.parties.update', editing.value.id), opts) : form.post(route('finance.accounting.parties.store'), opts)
}
function syncNow() { syncForm.post(route('finance.accounting.parties.sync'), { preserveScroll: true }) }
function statement(p: Party) { router.get(route('finance.accounting.parties.statement', p.id)) }
// Jump to the invoice/bill or quote form with this party preselected (the target page
// reads ?party and opens its create dialog).
function createInvoice(p: Party) {
  router.get(route(isCustomer.value ? 'finance.accounting.receivables' : 'finance.accounting.payables'), { party: p.id })
}
function createQuote(p: Party) {
  router.get(route('finance.accounting.quotes'), { party: p.id })
}
const sourceLabel = (p: Party) => p.source === 'crm' ? 'CRM' : (p.source === 'supplier_mgmt' ? 'Supplier Mgmt' : 'Manual')
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('acc.finance') }} · {{ t('acc.rec_pay') }}</div>
        <h2 class="text-h5 mb-0">{{ title }}</h2>
        <div v-if="synced" class="text-caption text-medium-emphasis">{{ t('acc.sourced_from', { source: source_name }) }}</div>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="text" prepend-icon="tabler-arrows-join" @click="mergeDialog = true">{{ t('acc.merge_duplicates') }}</VBtn>
        <VBtn v-if="synced" variant="tonal" color="primary" prepend-icon="tabler-refresh" :loading="syncForm.processing" @click="syncNow">{{ t('acc.sync_from', { source: source_name }) }}</VBtn>
        <VBtn v-else color="primary" prepend-icon="tabler-plus" @click="openNew">{{ isCustomer ? t('acc.new_customer') : t('acc.new_supplier') }}</VBtn>
      </div>
    </div>

    <VCard border>
      <VCardText class="d-flex gap-3 flex-wrap">
        <VTextField v-model="search" :label="t('acc.search')" density="compact" style="max-width: 280px" prepend-inner-icon="tabler-search" hide-details />
        <VSelect v-if="categories.length" v-model="categoryFilter" :items="categories" :label="t('acc.category')" density="compact" hide-details clearable style="max-width: 220px" />
      </VCardText>
      <VTable>
        <thead><tr><th>{{ t('acc.code') }}</th><th>{{ t('acc.name') }}</th><th>{{ t('acc.category') }}</th><th>{{ t('acc.contact') }}</th><th class="text-right">{{ t('acc.terms') }}</th><th class="text-right">{{ t('acc.credit_limit') }}</th><th>{{ t('acc.source') }}</th><th>{{ t('acc.status') }}</th><th class="text-right"></th></tr></thead>
        <tbody>
          <tr v-for="p in filtered" :key="p.id">
            <td class="font-weight-medium">{{ p.code }}</td>
            <td><a class="cursor-pointer text-high-emphasis" @click="openEdit(p)">{{ p.name }}</a></td>
            <td><VChip v-if="p.category" size="x-small" variant="tonal">{{ p.category }}</VChip><span v-else class="text-medium-emphasis">—</span></td>
            <td class="text-caption">{{ p.email || p.phone || '—' }}</td>
            <td class="text-right text-caption">{{ p.credit_terms_days }}d</td>
            <td class="text-right text-caption">{{ p.credit_limit !== null ? p.credit_limit.toLocaleString() : '—' }}</td>
            <td><VChip size="x-small" :color="p.synced ? 'info' : 'default'" variant="tonal">{{ sourceLabel(p) }}</VChip></td>
            <td>
              <VChip v-if="p.on_hold" size="x-small" color="error" variant="tonal">{{ t('acc.on_hold_chip') }}</VChip>
              <VChip v-else size="x-small" :color="p.is_active ? 'success' : 'default'" variant="tonal">{{ p.is_active ? t('acc.active') : t('acc.inactive') }}</VChip>
            </td>
            <td class="text-right">
              <VBtn icon="tabler-pencil" size="small" variant="text" :title="t('acc.view_edit')" @click="openEdit(p)" />
              <VBtn icon="tabler-file-text" size="small" variant="text" :title="t('acc.statement')" @click="statement(p)" />
              <VMenu location="bottom end">
                <template #activator="{ props: mp }">
                  <VBtn icon="tabler-dots-vertical" size="small" variant="text" v-bind="mp" :title="t('acc.actions')" />
                </template>
                <VList density="compact">
                  <VListItem prepend-icon="tabler-file-invoice" :title="isCustomer ? t('acc.create_invoice') : t('acc.create_bill')" @click="createInvoice(p)" />
                  <VListItem v-if="isCustomer" prepend-icon="tabler-file-description" :title="t('acc.create_quotation')" @click="createQuote(p)" />
                  <VListItem prepend-icon="tabler-file-text" :title="t('acc.statement')" @click="statement(p)" />
                  <VListItem prepend-icon="tabler-pencil" :title="t('acc.view_edit')" @click="openEdit(p)" />
                </VList>
              </VMenu>
            </td>
          </tr>
          <tr v-if="!filtered.length"><td colspan="9" class="text-center text-medium-emphasis py-6">{{ synced ? t('acc.no_synced', { source: source_name }) : (isCustomer ? t('acc.no_customers') : t('acc.no_suppliers')) }}</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <!--
      Wide enough to hold a row without wrapping, and scrollable rather than tall.

      At 720px the fields wrapped two-per-row, which pushed the form past the height of a laptop
      screen; the dialog then scrolled as one piece, so the title went off the top and Save off the
      bottom, and you could not see what you were editing while you edited it. `scrollable` keeps
      the title and the actions pinned and moves only the fields between them.
    -->
    <VDialog v-model="dialog" max-width="1040" scrollable>
      <VCard>
        <VCardTitle>{{ editing ? `${t('acc.edit')} ${isCustomer ? t('acc.customer') : t('acc.supplier')}` : (isCustomer ? t('acc.new_customer') : t('acc.new_supplier')) }}</VCardTitle>
        <VCardText>
          <!--
            Said once, plainly. Some fields belong to the system this record came from and are
            rewritten on every sync; everything else is Accounting's own and is editable here even
            on a synced record — which is the only way a CRM customer ever gets a VAT number.
          -->
          <VAlert
            v-if="editing?.synced" type="info" variant="tonal" density="compact" class="mb-4"
          >{{ t('acc.synced_notice', { source: source_name }) }}</VAlert>

          <div class="d-flex gap-3 mb-3">
            <VTextField v-model="form.code" :label="t('acc.code')" variant="outlined" density="comfortable" style="max-width: 180px" :error-messages="form.errors.code" />
            <VTextField v-model="form.name" :disabled="isLocked('name')" :hint="isLocked('name') ? t('acc.managed_by_source', { source: source_name }) : ''" :persistent-hint="isLocked('name')" :label="t('acc.name')" variant="outlined" density="comfortable" :error-messages="form.errors.name" />
            <VCombobox v-model="form.category" :items="categories" :label="t('acc.category')" variant="outlined" density="comfortable" style="max-width: 220px" clearable />
          </div>
          <div v-if="isCustomer && priceLists?.length" class="mb-3">
            <VSelect v-model="form.price_list_id" :items="priceLists.map(p => ({ title: p.name, value: p.id }))" :label="t('acc.price_list_optional')" variant="outlined" density="comfortable" clearable style="max-width: 340px" />
          </div>
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model="form.contact_name" :label="t('acc.contact_person')" variant="outlined" density="comfortable" />
            <VTextField v-model="form.email" :disabled="isLocked('email')" :hint="isLocked('email') ? t('acc.managed_by_source', { source: source_name }) : ''" :persistent-hint="isLocked('email')" :label="t('acc.email_label')" variant="outlined" density="comfortable" :error-messages="form.errors.email" />
            <VTextField v-model="form.website" :label="t('acc.website')" variant="outlined" density="comfortable" />
          </div>
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model="form.phone" :disabled="isLocked('phone')" :hint="isLocked('phone') ? t('acc.managed_by_source', { source: source_name }) : ''" :persistent-hint="isLocked('phone')" :label="t('acc.phone')" variant="outlined" density="comfortable" style="max-width: 200px" />
            <VTextField v-model="form.fax" :label="t('acc.fax')" variant="outlined" density="comfortable" style="max-width: 200px" />
            <VTextField v-model="form.vat_number" :disabled="isLocked('vat_number')" :hint="isLocked('vat_number') ? t('acc.managed_by_source', { source: source_name }) : ''" :persistent-hint="isLocked('vat_number')" :label="t('acc.vat_no')" variant="outlined" density="comfortable" style="max-width: 200px" />
            <VTextField v-model="form.registration_number" :label="t('acc.reg_no')" variant="outlined" density="comfortable" style="max-width: 200px" />
            <!--
              The supplier account this customer opened for us on their own purchasing system. Not
              the VAT or registration number: those identify us to the state and are the same on
              every document, this identifies us to one buyer and differs for each. Held here so it
              is typed once and carried onto every invoice and quote for them.
            -->
            <VTextField v-model="form.vendor_number" :label="t('acc.vendor_no')" :hint="t('acc.vendor_no_hint')" persistent-hint variant="outlined" density="comfortable" style="max-width: 220px" />
            <VSwitch v-model="form.tax_exempt" :label="t('acc.tax_exempt')" color="warning" hide-details />
          </div>
          <div class="d-flex gap-3 mb-3">
            <VTextarea v-model="form.address" :disabled="isLocked('address')" :hint="isLocked('address') ? t('acc.managed_by_source', { source: source_name }) : ''" :persistent-hint="isLocked('address')" :label="t('acc.physical_address')" variant="outlined" density="comfortable" rows="2" auto-grow />
            <VTextarea v-model="form.postal_address" :disabled="isLocked('postal_address')" :hint="isLocked('postal_address') ? t('acc.managed_by_source', { source: source_name }) : ''" :persistent-hint="isLocked('postal_address')" :label="t('acc.postal_address')" variant="outlined" density="comfortable" rows="2" auto-grow />
          </div>
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model="form.city" :disabled="isLocked('city')" :hint="isLocked('city') ? t('acc.managed_by_source', { source: source_name }) : ''" :persistent-hint="isLocked('city')" :label="t('acc.city')" variant="outlined" density="comfortable" style="max-width: 220px" />
            <VTextField v-model="form.country" :disabled="isLocked('country')" :hint="isLocked('country') ? t('acc.managed_by_source', { source: source_name }) : ''" :persistent-hint="isLocked('country')" :label="t('acc.country')" variant="outlined" density="comfortable" style="max-width: 220px" />
          </div>
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model="form.bank_name" :disabled="isLocked('bank_name')" :hint="isLocked('bank_name') ? t('acc.managed_by_source', { source: source_name }) : ''" :persistent-hint="isLocked('bank_name')" :label="t('acc.bank')" variant="outlined" density="comfortable" />
            <VTextField v-model="form.bank_account" :disabled="isLocked('bank_account')" :hint="isLocked('bank_account') ? t('acc.managed_by_source', { source: source_name }) : ''" :persistent-hint="isLocked('bank_account')" :label="t('acc.account_no')" variant="outlined" density="comfortable" />
          </div>
          <div class="d-flex gap-3 mb-3">
            <VCombobox
              v-model="form.sales_rep" :items="(people ?? []).map(p => p.name)" :label="t('acc.sales_rep')"
              variant="outlined" density="comfortable" style="max-width: 320px" clearable
            />
            <VTextField v-model.number="form.default_discount_percent" type="number" :label="t('acc.discount')" suffix="%" variant="outlined" density="comfortable" clearable style="max-width: 200px" />
          </div>
          <div class="d-flex gap-3 align-center">
            <VTextField v-model.number="form.credit_terms_days" :disabled="isLocked('credit_terms_days')" type="number" :label="t('acc.credit_terms_days')" variant="outlined" density="comfortable" style="max-width: 200px" />
            <VTextField v-model.number="form.credit_limit" type="number" :label="t('acc.credit_limit')" variant="outlined" density="comfortable" clearable style="max-width: 200px" />
            <VSwitch v-model="form.on_hold" :label="t('acc.on_credit_hold')" color="error" hide-details />
            <VSwitch v-model="form.is_active" :label="t('acc.active')" color="success" hide-details />
          </div>
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('acc.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" :disabled="!form.name" @click="save">{{ editing ? t('acc.save') : t('acc.add') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Merge duplicates -->
    <VDialog v-model="mergeDialog" max-width="560">
      <VCard>
        <VCardTitle>{{ t('acc.merge_duplicates') }}</VCardTitle>
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-3">{{ t('acc.merge_help') }}</p>
          <VAlert v-if="(mergeForm.errors as any).merge" type="error" variant="tonal" density="compact" class="mb-3">{{ (mergeForm.errors as any).merge }}</VAlert>
          <VSelect v-model="mergeForm.survivor_id" :items="partyOptions" :label="t('acc.keep_this')" variant="outlined" density="comfortable" class="mb-3" />
          <VSelect v-model="mergeForm.duplicate_id" :items="partyOptions.filter(o => o.value !== mergeForm.survivor_id)" :label="t('acc.merge_delete_dup')" variant="outlined" density="comfortable" />
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="mergeDialog = false">{{ t('acc.cancel') }}</VBtn><VBtn color="error" :loading="mergeForm.processing" :disabled="!mergeForm.survivor_id || !mergeForm.duplicate_id" @click="doMerge">{{ t('acc.merge') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirm" />
  </div>
</template>
