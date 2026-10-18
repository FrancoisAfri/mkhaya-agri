<script lang="ts" setup>
import { ref, computed, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const { t } = useI18n()

interface Expense { id: number; category: string | null; description: string | null; amount: number; tax_amount: number; total: number; payment_method: string; status: string; expense_date: string | null; is_mileage: boolean; km: number | null; employee_id: number | null }
const props = defineProps<{
  expenses: Expense[]; methods: Record<string, string>
  summary: { pending: number; pending_value: number; posted_value: number }
  accounts: { id: number; label: string }[]; employees: { id: number; name: string }[]; mileageRate: number
}>()

const money = (n: number) => (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const statusColor: Record<string, string> = { draft: 'warning', posted: 'success', rejected: 'error' }
const confirm = ref()

const dialog = ref(false)
const form = useForm<any>({ employee_id: null, category: '', description: '', expense_account_id: null, amount: null, tax_amount: 0, payment_method: 'reimbursable', expense_date: new Date().toISOString().slice(0, 10), is_mileage: false, km: null, rate: props.mileageRate })
function openNew() { form.reset(); form.payment_method = 'reimbursable'; form.expense_date = new Date().toISOString().slice(0, 10); form.tax_amount = 0; form.rate = props.mileageRate; dialog.value = true }
const mileageAmount = computed(() => form.is_mileage && form.km ? Number(form.km) * Number(form.rate || 0) : 0)
watch(() => form.is_mileage, (v) => { if (v) { form.tax_amount = 0 } })
function save() { form.post(route('finance.accounting.expenses.store'), { preserveScroll: true, onSuccess: () => { dialog.value = false } }) }

function approve(e: Expense) {
  confirm.value.open(
    { title: t('acc.approve_post_expense'), message: t('acc.approve_post_confirm', { amount: money(e.total) }), confirmText: t('acc.approve'), confirmColor: 'success', icon: 'tabler-check' },
    () => useForm({}).post(route('finance.accounting.expenses.approve', e.id), { preserveScroll: true }),
  )
}
function reject(e: Expense) {
  confirm.value.open(
    { title: t('acc.reject_expense'), message: t('acc.reject_expense_confirm'), confirmText: t('acc.reject'), confirmColor: 'error', icon: 'tabler-x' },
    () => useForm({}).post(route('finance.accounting.expenses.reject', e.id), { preserveScroll: true }),
  )
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('acc.finance') }} · {{ t('acc.crumb_expenses') }}</div>
        <h2 class="text-h5 mb-0">{{ t('acc.expense_claims') }}</h2>
        <div class="text-caption text-medium-emphasis">{{ t('acc.expenses_sub') }}</div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openNew">{{ t('acc.new_expense') }}</VBtn>
    </div>

    <VRow class="mb-2">
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('acc.pending') }}</div><div class="text-h6">{{ summary.pending }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('acc.pending_value') }}</div><div class="text-h6 text-warning">{{ money(summary.pending_value) }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('acc.posted_value') }}</div><div class="text-h6 text-success">{{ money(summary.posted_value) }}</div></VCardText></VCard></VCol>
    </VRow>

    <VCard border>
      <VTable density="compact">
        <thead><tr><th>{{ t('acc.date') }}</th><th>{{ t('acc.description') }}</th><th>{{ t('acc.method') }}</th><th class="text-right">{{ t('acc.net') }}</th><th class="text-right">{{ t('acc.vat') }}</th><th class="text-right">{{ t('acc.total') }}</th><th>{{ t('acc.status') }}</th><th></th></tr></thead>
        <tbody>
          <tr v-if="!expenses.length"><td colspan="8" class="text-center text-medium-emphasis py-8">{{ t('acc.no_expenses_captured') }}</td></tr>
          <tr v-for="e in expenses" :key="e.id">
            <td class="text-caption">{{ e.expense_date }}</td>
            <td class="font-weight-medium">{{ e.description || e.category }} <VChip v-if="e.is_mileage" size="x-small" variant="tonal" color="info">{{ e.km }} {{ t('acc.km') }}</VChip></td>
            <td class="text-caption">{{ methods[e.payment_method] ?? e.payment_method }}</td>
            <td class="text-right">{{ money(e.amount) }}</td>
            <td class="text-right text-caption">{{ e.tax_amount ? money(e.tax_amount) : '—' }}</td>
            <td class="text-right font-weight-medium">{{ money(e.total) }}</td>
            <td><VChip size="x-small" :color="statusColor[e.status]" variant="tonal" class="text-capitalize">{{ e.status }}</VChip></td>
            <td class="text-right" style="white-space: nowrap">
              <template v-if="e.status === 'draft'">
                <VBtn size="x-small" color="success" variant="tonal" class="me-1" @click="approve(e)">{{ t('acc.approve') }}</VBtn>
                <VBtn size="x-small" color="error" variant="text" @click="reject(e)">{{ t('acc.reject') }}</VBtn>
              </template>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="dialog" max-width="600">
      <VCard>
        <VCardTitle>{{ t('acc.new_expense') }}</VCardTitle>
        <VCardText>
          <div class="d-flex gap-3 mb-3">
            <VSelect v-model="form.employee_id" :items="employees.map(e => ({ title: e.name, value: e.id }))" :label="t('acc.employee_optional')" clearable variant="outlined" density="comfortable" />
            <VTextField v-model="form.expense_date" type="date" :label="t('acc.date')" variant="outlined" density="comfortable" style="max-width: 180px" />
          </div>
          <div class="d-flex gap-3 mb-3">
            <VTextField v-model="form.category" :label="t('acc.category')" variant="outlined" density="comfortable" style="max-width: 180px" />
            <VTextField v-model="form.description" :label="t('acc.description')" variant="outlined" density="comfortable" />
          </div>
          <VSelect v-model="form.expense_account_id" :items="accounts.map(a => ({ title: a.label, value: a.id }))" :label="t('acc.expense_account')" variant="outlined" density="comfortable" class="mb-3" :error-messages="form.errors.expense_account_id" />
          <VSwitch v-model="form.is_mileage" :label="t('acc.mileage_claim')" color="primary" density="compact" hide-details class="mb-2" />
          <div v-if="form.is_mileage" class="d-flex gap-3 mb-3 align-center">
            <VTextField v-model.number="form.km" type="number" :label="t('acc.distance_km')" variant="outlined" density="comfortable" style="max-width: 160px" />
            <VTextField v-model.number="form.rate" type="number" :label="t('acc.rate_per_km')" prefix="R" variant="outlined" density="comfortable" style="max-width: 140px" />
            <VChip color="primary" variant="tonal">= {{ money(mileageAmount) }}</VChip>
          </div>
          <div v-else class="d-flex gap-3 mb-3">
            <VTextField v-model.number="form.amount" type="number" :label="t('acc.amount_excl_vat')" prefix="R" variant="outlined" density="comfortable" :error-messages="form.errors.amount" />
            <VTextField v-model.number="form.tax_amount" type="number" :label="t('acc.vat')" prefix="R" variant="outlined" density="comfortable" style="max-width: 140px" />
          </div>
          <VSelect v-model="form.payment_method" :items="Object.entries(methods).map(([v, m]) => ({ title: m, value: v }))" :label="t('acc.fund_via')" variant="outlined" density="comfortable" />
        </VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('acc.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" :disabled="!form.expense_account_id || (form.is_mileage ? !form.km : !form.amount)" @click="save">{{ t('acc.capture') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirm" />
  </div>
</template>
