<script lang="ts" setup>
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
const route = ziggyRoute
const { t } = useI18n()

interface Bill { id: number; number: string; party: string; due: string; outstanding: number; has_bank: boolean }
interface BatchLine { party: string; invoice_id: number; amount: number }
interface Batch { id: number; reference: string; status: string; total: number; payment_date: string | null; bank_code: string; notes: string | null; maker: string | null; checker: string | null; approved_at: string | null; paid_at: string | null; lines: BatchLine[] }
const props = defineProps<{ batches: Batch[]; openBills: Bill[]; banks: { code: string; label: string }[]; can_approve: boolean }>()

const money = (n: number) => (n ?? 0).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const statusColor: Record<string, string> = { draft: 'warning', approved: 'info', paid: 'success', rejected: 'error' }
const confirm = ref()

// New batch
const dialog = ref(false)
const selected = ref<number[]>([])
const form = useForm<any>({ bill_ids: [] as number[], bank_code: props.banks[0]?.code ?? '', payment_date: new Date().toISOString().slice(0, 10), notes: '' })
const selectedTotal = computed(() => props.openBills.filter(b => selected.value.includes(b.id)).reduce((s, b) => s + b.outstanding, 0))
function openNew() { selected.value = []; form.reset(); form.bank_code = props.banks[0]?.code ?? ''; form.payment_date = new Date().toISOString().slice(0, 10); dialog.value = true }
function createBatch() { form.bill_ids = [...selected.value]; form.post(route('finance.accounting.payment-batches.store'), { preserveScroll: true, onSuccess: () => { dialog.value = false } }) }

function approve(b: Batch) {
  confirm.value.open(
    { title: t('acc.approve_batch'), message: t('acc.approve_batch_confirm', { reference: b.reference, amount: money(b.total) }), confirmText: t('acc.approve'), confirmColor: 'info', icon: 'tabler-checks' },
    () => useForm({}).post(route('finance.accounting.payment-batches.approve', b.id), { preserveScroll: true }),
  )
}
function pay(b: Batch) {
  confirm.value.open(
    { title: t('acc.release_payments'), message: t('acc.release_payments_confirm', { reference: b.reference, amount: money(b.total) }), confirmText: t('acc.release'), confirmColor: 'success', icon: 'tabler-cash' },
    () => useForm({}).post(route('finance.accounting.payment-batches.pay', b.id), { preserveScroll: true }),
  )
}
const rejectDialog = ref(false)
const rejectBatch = ref<Batch | null>(null)
const rejectForm = useForm({ reason: '' })
function openReject(b: Batch) { rejectBatch.value = b; rejectForm.reset(); rejectDialog.value = true }
function doReject() { rejectForm.post(route('finance.accounting.payment-batches.reject', rejectBatch.value!.id), { preserveScroll: true, onSuccess: () => { rejectDialog.value = false } }) }
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('acc.finance') }} · {{ t('acc.payables') }}</div>
        <h2 class="text-h5 mb-0">{{ t('acc.payment_batches') }}</h2>
        <div class="text-caption text-medium-emphasis">{{ t('acc.payment_batches_sub') }}</div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" :disabled="!openBills.length" @click="openNew">{{ t('acc.new_batch') }}</VBtn>
    </div>

    <VAlert v-if="(form.errors as any).batch" type="error" variant="tonal" density="compact" class="mb-3">{{ (form.errors as any).batch }}</VAlert>

    <VCard border>
      <VExpansionPanels variant="accordion">
        <VExpansionPanel v-for="b in batches" :key="b.id">
          <VExpansionPanelTitle>
            <div class="d-flex align-center justify-space-between" style="width: 100%">
              <div>
                <span class="font-weight-medium">{{ b.reference }}</span>
                <VChip size="x-small" :color="statusColor[b.status]" variant="tonal" class="ms-2 text-capitalize">{{ b.status }}</VChip>
                <span class="text-caption text-medium-emphasis ms-2">{{ t('acc.payments_count', { n: b.lines.length }) }} · {{ b.payment_date }}</span>
              </div>
              <div class="d-flex align-center gap-3 me-3">
                <span class="font-weight-medium">{{ money(b.total) }}</span>
              </div>
            </div>
          </VExpansionPanelTitle>
          <VExpansionPanelText>
            <div class="text-caption text-medium-emphasis mb-2">
              {{ t('acc.created_by') }} {{ b.maker || '—' }}<span v-if="b.checker"> · {{ t('acc.approved_by_x') }} {{ b.checker }} ({{ b.approved_at }})</span><span v-if="b.paid_at"> · {{ t('acc.paid_prefix') }} {{ b.paid_at }}</span>
            </div>
            <VTable density="compact" class="mb-3">
              <thead><tr><th>{{ t('acc.supplier') }}</th><th class="text-right">{{ t('acc.amount') }}</th></tr></thead>
              <tbody>
                <tr v-for="(l, i) in b.lines" :key="i"><td>{{ l.party }}</td><td class="text-right">{{ money(l.amount) }}</td></tr>
              </tbody>
            </VTable>
            <div class="d-flex gap-2 flex-wrap">
              <VBtn v-if="b.status === 'draft' && can_approve" size="small" color="info" variant="tonal" @click="approve(b)">{{ t('acc.approve') }}</VBtn>
              <VBtn v-if="b.status === 'approved'" size="small" color="success" variant="tonal" @click="pay(b)">{{ t('acc.release_post') }}</VBtn>
              <VBtn v-if="['approved', 'paid'].includes(b.status)" size="small" variant="tonal" prepend-icon="tabler-file-code" :href="route('finance.accounting.payment-batches.pain001', b.id)" target="_blank">PAIN.001</VBtn>
              <VBtn v-if="['approved', 'paid'].includes(b.status)" size="small" variant="tonal" prepend-icon="tabler-file-spreadsheet" :href="route('finance.accounting.payment-batches.csv', b.id)" target="_blank">CSV</VBtn>
              <VBtn v-if="['draft', 'approved'].includes(b.status)" size="small" variant="text" color="error" @click="openReject(b)">{{ t('acc.reject') }}</VBtn>
            </div>
          </VExpansionPanelText>
        </VExpansionPanel>
      </VExpansionPanels>
      <VCardText v-if="!batches.length" class="text-center text-medium-emphasis py-6">{{ t('acc.no_batches') }}</VCardText>
    </VCard>

    <!-- New batch dialog -->
    <VDialog v-model="dialog" max-width="820" persistent>
      <VCard>
        <VCardTitle>{{ t('acc.new_payment_batch') }}</VCardTitle>
        <VCardText>
          <div class="d-flex gap-3 mb-3 flex-wrap">
            <VSelect v-model="form.bank_code" :items="banks.map(b => ({ title: b.label, value: b.code }))" :label="t('acc.pay_from_bank')" variant="outlined" density="comfortable" style="min-width: 220px" />
            <VTextField v-model="form.payment_date" type="date" :label="t('acc.payment_date')" variant="outlined" density="comfortable" style="max-width: 180px" />
            <VTextField v-model="form.notes" :label="t('acc.notes')" variant="outlined" density="comfortable" />
          </div>
          <VTable density="compact">
            <thead><tr><th style="width: 48px"></th><th>{{ t('acc.bill') }}</th><th>{{ t('acc.supplier') }}</th><th>{{ t('acc.due') }}</th><th class="text-right">{{ t('acc.outstanding') }}</th></tr></thead>
            <tbody>
              <tr v-for="b in openBills" :key="b.id">
                <td><VCheckbox v-model="selected" :value="b.id" hide-details density="compact" /></td>
                <td>{{ b.number }}</td>
                <td>{{ b.party }} <VChip v-if="!b.has_bank" size="x-small" color="warning" variant="tonal">{{ t('acc.no_bank_details') }}</VChip></td>
                <td class="text-caption">{{ b.due }}</td>
                <td class="text-right">{{ money(b.outstanding) }}</td>
              </tr>
              <tr v-if="!openBills.length"><td colspan="5" class="text-center text-medium-emphasis py-4">{{ t('acc.no_open_bills') }}</td></tr>
            </tbody>
          </VTable>
        </VCardText>
        <VCardActions class="pa-4">
          <VChip color="primary" variant="tonal">{{ t('acc.selected_count', { n: selected.length, amount: money(selectedTotal) }) }}</VChip>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('acc.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" :disabled="!selected.length" @click="createBatch">{{ t('acc.create_batch') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="rejectDialog" max-width="440">
      <VCard>
        <VCardTitle>{{ t('acc.reject_batch') }}</VCardTitle>
        <VCardText><VTextarea v-model="rejectForm.reason" :label="t('acc.reason')" variant="outlined" rows="2" :error-messages="rejectForm.errors.reason" /></VCardText>
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="rejectDialog = false">{{ t('acc.cancel') }}</VBtn><VBtn color="error" :loading="rejectForm.processing" :disabled="!rejectForm.reason" @click="doReject">{{ t('acc.reject') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirm" />
  </div>
</template>
