<script lang="ts" setup>
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const { t } = useI18n()

interface Bank { id: number; name: string; type: string; currency: string; bank_name: string | null; branch: string | null; branch_code: string | null; account_number: string | null; is_active: boolean; account_id: number; account: string | null; unmatched: number }
interface Transfer { id: number; date: string | null; from: string | null; to: string | null; amount: number; fee: number; reference: string | null; description: string | null }
const props = defineProps<{ banks: Bank[]; types: string[]; glAccounts: { id: number; label: string }[]; feeAccounts?: { code: string; label: string }[]; transfers?: Transfer[] }>()

const typeLabel = computed<Record<string, string>>(() => ({ bank: t('acc.bank_type_bank'), petty_cash: t('acc.bank_type_petty'), loan: t('acc.bank_type_loan'), investment: t('acc.bank_type_investment'), wallet: t('acc.bank_type_wallet') }))
const money = (n: number) => (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

// Interbank transfer
const xferDialog = ref(false)
const xfer = useForm<any>({ from_bank_id: null, to_bank_id: null, amount: null, fee: 0, fee_account: '', transfer_date: new Date().toISOString().slice(0, 10), reference: '', description: '' })
function openTransfer() { xfer.reset(); xfer.fee = 0; xfer.transfer_date = new Date().toISOString().slice(0, 10); xferDialog.value = true }
function submitTransfer() { xfer.post(route('finance.accounting.banking.transfer'), { preserveScroll: true, onSuccess: () => { xferDialog.value = false } }) }

const dialog = ref(false)
const editing = ref<Bank | null>(null)
const form = useForm<any>({ account_id: null as number | null, name: '', type: 'bank', currency: 'ZAR', bank_name: '', branch: '', branch_code: '', account_number: '', is_active: true })

function openNew() { editing.value = null; form.reset(); form.type = 'bank'; form.currency = 'ZAR'; form.is_active = true; dialog.value = true }
function openEdit(b: Bank) {
  editing.value = b
  form.account_id = b.account_id; form.name = b.name; form.type = b.type; form.currency = b.currency
  form.bank_name = b.bank_name ?? ''; form.branch = b.branch ?? ''; form.branch_code = b.branch_code ?? ''
  form.account_number = b.account_number ?? ''; form.is_active = b.is_active
  dialog.value = true
}
function save() {
  const opts = { preserveScroll: true, onSuccess: () => { dialog.value = false } }
  if (editing.value) form.patch(route('finance.accounting.banking.accounts.update', editing.value.id), opts)
  else form.post(route('finance.accounting.banking.accounts.store'), opts)
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('acc.finance') }} · {{ t('acc.crumb_cash') }}</div>
        <h2 class="text-h5 mb-0">{{ t('acc.banking_word') }}</h2>
        <div class="text-caption text-medium-emphasis">{{ t('acc.banking_sub') }}</div>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" prepend-icon="tabler-arrows-exchange" :disabled="banks.length < 2" @click="openTransfer">{{ t('acc.transfer') }}</VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="openNew">{{ t('acc.new_account') }}</VBtn>
      </div>
    </div>

    <VRow>
      <VCol v-for="b in banks" :key="b.id" cols="12" md="6" lg="4">
        <VCard border class="h-100">
          <VCardText>
            <div class="d-flex align-center justify-space-between mb-1">
              <span class="font-weight-medium text-body-1">{{ b.name }}</span>
              <VChip size="x-small" variant="tonal" :color="b.is_active ? 'success' : 'default'">{{ b.is_active ? t('acc.active') : t('acc.inactive') }}</VChip>
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ typeLabel[b.type] || b.type }} · {{ b.currency }}
              <template v-if="b.bank_name"> · {{ b.bank_name }}</template>
              <template v-if="b.branch"> · {{ b.branch }}</template>
              <template v-if="b.branch_code"> ({{ b.branch_code }})</template>
              <template v-if="b.account_number"> · ••{{ b.account_number.slice(-4) }}</template>
            </div>
            <div class="text-caption text-medium-emphasis mt-1">{{ t('acc.bank_gl') }}: {{ b.account || '—' }}</div>
            <div v-if="b.unmatched" class="mt-2">
              <VChip size="x-small" color="warning" variant="tonal" prepend-icon="tabler-alert-triangle">{{ b.unmatched }} {{ t('acc.unmatched') }}</VChip>
            </div>
          </VCardText>
          <VCardActions class="px-4 pb-3 pt-0">
            <VBtn size="small" color="primary" variant="tonal" prepend-icon="tabler-checkbox"
                  @click="router.visit(route('finance.accounting.banking.reconcile', b.id))">{{ t('acc.reconcile') }}</VBtn>
            <VSpacer />
            <VBtn size="small" variant="text" icon="tabler-edit" @click="openEdit(b)" />
          </VCardActions>
        </VCard>
      </VCol>
      <VCol v-if="!banks.length" cols="12">
        <VCard border><VCardText class="text-center text-medium-emphasis py-8">{{ t('acc.no_bank_accounts') }}</VCardText></VCard>
      </VCol>
    </VRow>

    <!-- Recent transfers -->
    <VCard v-if="transfers && transfers.length" border class="mt-2">
      <VCardTitle class="text-subtitle-1 d-flex align-center"><VIcon icon="tabler-arrows-exchange" size="18" class="me-2" />{{ t('acc.recent_transfers') }}</VCardTitle>
      <VDivider />
      <VTable density="compact">
        <thead><tr><th>{{ t('acc.date') }}</th><th>{{ t('acc.from') }}</th><th>{{ t('acc.to') }}</th><th class="text-right">{{ t('acc.amount') }}</th><th class="text-right">{{ t('acc.fee') }}</th><th>{{ t('acc.reference') }}</th></tr></thead>
        <tbody>
          <tr v-for="t in transfers" :key="t.id">
            <td class="text-caption">{{ t.date }}</td>
            <td>{{ t.from }}</td>
            <td>{{ t.to }}</td>
            <td class="text-right font-weight-medium">{{ money(t.amount) }}</td>
            <td class="text-right text-caption">{{ t.fee ? money(t.fee) : '—' }}</td>
            <td class="text-caption text-medium-emphasis">{{ t.reference || t.description }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <!-- Interbank transfer dialog -->
    <VDialog v-model="xferDialog" max-width="560">
      <VCard>
        <VCardTitle>{{ t('acc.transfer_between') }}</VCardTitle>
        <VCardText>
          <VAlert v-if="(xfer.errors as any).transfer" type="error" variant="tonal" density="compact" class="mb-3">{{ (xfer.errors as any).transfer }}</VAlert>
          <div class="d-flex gap-3 mb-3">
            <VSelect v-model="xfer.from_bank_id" :items="banks.map(b => ({ title: b.name, value: b.id }))" :label="t('acc.from')" variant="outlined" density="comfortable" :error-messages="xfer.errors.from_bank_id" />
            <VIcon icon="tabler-arrow-right" class="align-self-center" />
            <VSelect v-model="xfer.to_bank_id" :items="banks.filter(b => b.id !== xfer.from_bank_id).map(b => ({ title: b.name, value: b.id }))" :label="t('acc.to')" variant="outlined" density="comfortable" :error-messages="xfer.errors.to_bank_id" />
          </div>
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model.number="xfer.amount" type="number" :label="t('acc.amount')" prefix="R" variant="outlined" density="comfortable" :error-messages="xfer.errors.amount" />
            <VTextField v-model="xfer.transfer_date" type="date" :label="t('acc.date')" variant="outlined" density="comfortable" style="max-width: 180px" />
          </div>
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model.number="xfer.fee" type="number" :label="t('acc.bank_charge_optional')" prefix="R" variant="outlined" density="comfortable" style="max-width: 200px" />
            <VSelect v-if="xfer.fee > 0" v-model="xfer.fee_account" :items="(feeAccounts || []).map(a => ({ title: a.label, value: a.code }))" :label="t('acc.charge_to_account')" variant="outlined" density="comfortable" :error-messages="xfer.errors.fee_account" />
          </div>
          <VTextField v-model="xfer.reference" :label="t('acc.reference_optional')" variant="outlined" density="comfortable" class="mb-3" />
          <VTextField v-model="xfer.description" :label="t('acc.description_optional')" variant="outlined" density="comfortable" />
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="xferDialog = false">{{ t('acc.cancel') }}</VBtn>
          <VBtn color="primary" :loading="xfer.processing" :disabled="!xfer.from_bank_id || !xfer.to_bank_id || !xfer.amount" @click="submitTransfer">{{ t('acc.post_transfer') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="dialog" max-width="560" persistent>
      <VCard>
        <VCardTitle>{{ editing ? t('acc.edit_bank_account') : t('acc.new_bank_account') }}</VCardTitle>
        <VCardText>
          <VTextField v-model="form.name" :label="t('acc.account_name')" placeholder="e.g. FNB Cheque" variant="outlined" density="comfortable" class="mb-3" :error-messages="form.errors.name" />
          <VSelect v-model="form.account_id" :items="glAccounts.map(a => ({ title: a.label, value: a.id }))" :label="t('acc.gl_account_optional')" :placeholder="t('acc.leave_blank_auto')" clearable variant="outlined" density="comfortable" class="mb-1" :error-messages="form.errors.account_id" />
          <div class="text-caption text-medium-emphasis mb-3">
            <VIcon icon="tabler-wand" size="14" /> {{ t('acc.gl_auto_hint') }}
          </div>
          <div class="d-flex gap-3 mb-3">
            <VSelect v-model="form.type" :items="types.map(ty => ({ title: typeLabel[ty] || ty, value: ty }))" :label="t('acc.type')" variant="outlined" density="comfortable" />
            <VTextField v-model="form.currency" :label="t('acc.currency')" maxlength="3" variant="outlined" density="comfortable" style="max-width: 120px" />
          </div>
          <VTextField v-model="form.bank_name" :label="t('acc.bank_name_label')" placeholder="e.g. First National Bank" variant="outlined" density="comfortable" class="mb-3" />
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model="form.branch" :label="t('acc.branch')" variant="outlined" density="comfortable" />
            <VTextField v-model="form.branch_code" :label="t('acc.branch_code')" variant="outlined" density="comfortable" style="max-width: 160px" />
          </div>
          <VTextField v-model="form.account_number" :label="t('acc.account_no')" variant="outlined" density="comfortable" class="mb-3" />
          <VSwitch v-model="form.is_active" :label="t('acc.active')" color="primary" hide-details />
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('acc.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" :disabled="!form.name" @click="save">{{ t('acc.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
