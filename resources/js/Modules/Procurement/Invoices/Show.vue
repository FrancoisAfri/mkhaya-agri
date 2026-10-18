<script setup lang="ts">
import { ref, reactive, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

const { t } = useI18n()
const route = ziggyRoute

// ── Types ──────────────────────────────────────────────────────────────────────
type Item = {
  id: number
  name: string
  sku: string | null
  quantity: number
  unit_price: number
  line_total: number
  tax: number
}

type PoItem = {
  name: string
  quantity: number
  received_qty: number
  unit_price: number
}

type Match = {
  type: string
  status: string
  po_amount: number
  grn_value: number
  invoice_amount: number
  qty_ok: boolean
  price_ok: boolean
  amount_ok: boolean
  within_tolerance: boolean
  tolerance_pct: number
  variance_amount: number
  discrepancies: { type: string; message: string }[]
}

type Snapshot = {
  allocated: number
  reserved: number
  committed: number
  actual: number
  available: number
}

// ── Props ──────────────────────────────────────────────────────────────────────
const props = defineProps<{
  invoice: {
    id: number
    invoice_number: string
    supplier: string | null
    po: { id: number; po_number: string; amount: number } | null
    amount: number
    subtotal: number
    tax_amount: number
    currency: string
    status: string
    match_status: string
    payment_status: string
    source: string
    variance_amount: number
    reconciled_at: string | null
    invoice_date: string | null
    due_date: string | null
    ai_status: string | null
    ai_provider: string | null
    ai_model: string | null
    notes: string | null
    has_document: boolean
    file_name: string | null
    items: Item[]
    grn_value: number
    po_items: PoItem[]
  }
  latestMatch: Match | null
  budgetSnapshot: Snapshot | null
}>()

// ── State ──────────────────────────────────────────────────────────────────────
const confirmDialog = ref<InstanceType<typeof ConfirmDialog> | null>(null)
const inv = computed(() => props.invoice)
const tolerance = ref(2)
const pasteText = ref('')
const reconcileAction = ref<'accept' | 'adjust' | 'dispute'>('accept')
const reconcileNote = ref('')
const file = ref<File | null>(null)

// ── Computed ──────────────────────────────────────────────────────────────────
const canMatch = computed(() => !!inv.value.po && !inv.value.reconciled_at)
const canReconcile = computed(() => inv.value.match_status !== 'unmatched' && !inv.value.reconciled_at)
const canPay = computed(() => !!inv.value.reconciled_at && inv.value.payment_status !== 'paid')

// ═══ Status color map ═══
const statusColor: Record<string, string> = {
  pending: 'grey',
  matched: 'info',
  exception: 'error',
  reconciled: 'primary',
  paid: 'success',
  disputed: 'warning',
}

// ═══ Computed helpers pour les traductions ═══
const statusMap = computed(() => ({
  pending: t('invoices_show.statuses.pending'),
  matched: t('invoices_show.statuses.matched'),
  exception: t('invoices_show.statuses.exception'),
  reconciled: t('invoices_show.statuses.reconciled'),
  paid: t('invoices_show.statuses.paid'),
  disputed: t('invoices_show.statuses.disputed'),
}))

const matchMap = computed(() => ({
  unmatched: t('invoices_show.match_statuses.unmatched'),
  matched: t('invoices_show.match_statuses.matched'),
  exception: t('invoices_show.match_statuses.exception'),
}))

const paymentMap = computed(() => ({
  unpaid: t('invoices_show.payment_statuses.unpaid'),
  approved: t('invoices_show.payment_statuses.approved'),
  paid: t('invoices_show.payment_statuses.paid'),
}))

// ── Helpers ────────────────────────────────────────────────────────────────────
function getStatusLabel(status: string): string {
  return statusMap.value[status as keyof typeof statusMap.value] || status
}

function getMatchLabel(status: string): string {
  return matchMap.value[status as keyof typeof matchMap.value] || status
}

function getPaymentLabel(status: string): string {
  return paymentMap.value[status as keyof typeof paymentMap.value] || status
}

function formatCurrency(value: number | null): string {
  if (value == null) return '—'
  try {
    return new Intl.NumberFormat('en-ZA', {
      style: 'currency',
      currency: inv.value.currency || 'ZAR',
      maximumFractionDigits: 0,
    }).format(value)
  } catch {
    return `${inv.value.currency || ''} ${value}`.trim()
  }
}

function formatDate(date: string | null): string {
  if (!date) return '—'
  const d = new Date(date)
  return d.toLocaleDateString('en-ZA', {
    year: 'numeric',
    month: 'short',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  })
}

// ── Methods ────────────────────────────────────────────────────────────────────
function goToIndex(): void {
  router.get(route('invoices.index'))
}

// Addressed by uuid, not the row id.
function goToPO(uuid: string): void {
  router.get(route('purchase-orders.show', uuid))
}

function extract(): void {
  router.post(
    route('invoices.extract', inv.value.id),
    { text: pasteText.value || undefined },
    { preserveScroll: true }
  )
}

function runMatch(): void {
  router.post(
    route('invoices.match', inv.value.id),
    { tolerance_pct: tolerance.value },
    { preserveScroll: true }
  )
}

function reconcile(): void {
  router.post(
    route('invoices.reconcile', inv.value.id),
    { action: reconcileAction.value, note: reconcileNote.value || undefined },
    { preserveScroll: true }
  )
}

function pay(): void {
  confirmDialog.value?.open(
    {
      title: t('invoices_show.confirm.mark_paid.title'),
      message: t('invoices_show.confirm.mark_paid.message'),
      confirmText: t('invoices_show.confirm.mark_paid.confirm'),
      confirmColor: 'warning',
      icon: 'tabler-alert-triangle',
    },
    () => {
      router.post(route('invoices.pay', inv.value.id), {}, { preserveScroll: true })
    }
  )
}

function uploadDoc(): void {
  if (!file.value) return
  router.post(
    route('invoices.document.upload', inv.value.id),
    { file: file.value },
    {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => (file.value = null),
    }
  )
}

function openDoc(): void {
  window.open(route('invoices.document', inv.value.id))
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div class="d-flex align-center gap-3">
        <VBtn icon="tabler-arrow-left" variant="text" @click="goToIndex" />
        <div>
          <h4 class="text-h4 mb-0">{{ inv.invoice_number }}</h4>
          <p class="text-caption text-medium-emphasis mb-0">{{ inv.supplier || '—' }}</p>
        </div>
        <VChip :color="statusColor[inv.status] || 'grey'" variant="tonal">
          {{ getStatusLabel(inv.status) }}
        </VChip>
        <VChip
          v-if="inv.po"
          color="info"
          variant="tonal"
          prepend-icon="tabler-link"
          style="cursor:pointer"
          @click="goToPO(inv.po.uuid)"
        >
          {{ inv.po.po_number }}
        </VChip>
      </div>
      <div class="d-flex gap-2">
        <VBtn
          v-if="inv.has_document"
          variant="tonal"
          prepend-icon="tabler-file-download"
          @click="openDoc"
        >
          {{ t('invoices_show.document') }}
        </VBtn>
        <VBtn
          v-if="canPay"
          color="success"
          prepend-icon="tabler-cash"
          @click="pay"
        >
          {{ t('invoices_show.mark_paid') }}
        </VBtn>
      </div>
    </div>

    <VRow>
      <!-- ── Left Column ────────────────────────────────────────────────── -->
      <VCol cols="12" md="7">
        <!-- AI extraction -->
        <VCard border class="mb-4">
          <VCardTitle class="d-flex align-center justify-space-between">
            <span>{{ t('invoices_show.ai_extraction') }}</span>
            <VChip
              v-if="inv.ai_status"
              size="small"
              :color="inv.ai_status === 'completed' ? 'success' : inv.ai_status === 'failed' ? 'error' : 'warning'"
              variant="tonal"
            >
              {{ inv.ai_status }}
            </VChip>
          </VCardTitle>
          <VCardText>
            <div v-if="!inv.has_document" class="d-flex flex-wrap align-end gap-3 mb-3">
              <VFileInput
                v-model="file"
                :label="t('invoices_show.upload_invoice_document')"
                variant="outlined"
                density="compact"
                hide-details
                prepend-icon="tabler-paperclip"
                style="min-width:260px;flex:1"
                @update:model-value="(f: any) => file = Array.isArray(f) ? f[0] : f"
              />
              <VBtn
                color="primary"
                variant="tonal"
                :disabled="!file"
                @click="uploadDoc"
              >
                {{ t('invoices_show.upload') }}
              </VBtn>
            </div>
            <VTextarea
              v-model="pasteText"
              :label="t('invoices_show.or_paste_text')"
              rows="3"
              variant="outlined"
              density="compact"
              hide-details
              class="mb-3"
            />
            <VBtn color="primary" prepend-icon="tabler-robot" @click="extract">
              {{ t('invoices_show.extract_with_ai') }}
            </VBtn>
            <span v-if="inv.ai_provider" class="text-caption text-medium-emphasis ml-3">
              {{ inv.ai_provider }} · {{ inv.ai_model }}
            </span>
          </VCardText>
        </VCard>

        <!-- Invoice lines -->
        <VCard border class="mb-4">
          <VCardTitle>{{ t('invoices_show.invoice_lines') }}</VCardTitle>
          <VTable>
            <thead>
              <tr>
                <th>{{ t('invoices_show.name') }}</th>
                <th>{{ t('invoices_show.sku') }}</th>
                <th class="text-right">{{ t('invoices_show.quantity') }}</th>
                <th class="text-right">{{ t('invoices_show.unit_price') }}</th>
                <th class="text-right">{{ t('invoices_show.line_total') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!inv.items.length">
                <td colspan="5" class="text-center text-medium-emphasis py-4">
                  {{ t('invoices_show.no_lines') }}
                </td>
              </tr>
              <tr v-for="it in inv.items" :key="it.id">
                <td class="font-weight-medium">{{ it.name }}</td>
                <td>{{ it.sku || '—' }}</td>
                <td class="text-right">{{ it.quantity }}</td>
                <td class="text-right">{{ formatCurrency(it.unit_price) }}</td>
                <td class="text-right">{{ formatCurrency(it.line_total) }}</td>
              </tr>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="4" class="text-right text-medium-emphasis">
                  {{ t('invoices_show.subtotal') }}
                </td>
                <td class="text-right">{{ formatCurrency(inv.subtotal) }}</td>
              </tr>
              <tr>
                <td colspan="4" class="text-right text-medium-emphasis">
                  {{ t('invoices_show.tax') }}
                </td>
                <td class="text-right">{{ formatCurrency(inv.tax_amount) }}</td>
              </tr>
              <tr>
                <td colspan="4" class="text-right font-weight-bold">
                  {{ t('invoices_show.total') }}
                </td>
                <td class="text-right font-weight-bold">{{ formatCurrency(inv.amount) }}</td>
              </tr>
            </tfoot>
          </VTable>
        </VCard>

        <!-- 3-way match -->
        <VCard border class="mb-4">
          <VCardTitle>{{ t('invoices_show.three_way_matching') }}</VCardTitle>
          <VCardText>
            <div class="d-flex flex-wrap align-end gap-3 mb-4">
              <VTextField
                v-model.number="tolerance"
                type="number"
                :label="t('invoices_show.tolerance_percent')"
                min="0"
                max="100"
                density="compact"
                variant="outlined"
                hide-details
                style="max-width:140px"
              />
              <VBtn
                color="primary"
                :disabled="!canMatch"
                prepend-icon="tabler-arrows-shuffle"
                @click="runMatch"
              >
                {{ t('invoices_show.run_match') }}
              </VBtn>
              <span v-if="!inv.po" class="text-caption text-error">
                {{ t('invoices_show.link_po') }}
              </span>
            </div>

            <div v-if="latestMatch">
              <div class="d-flex flex-wrap gap-4 mb-3">
                <div>
                  <div class="text-overline">{{ t('invoices_show.po') }}</div>
                  <div class="text-h6">{{ formatCurrency(latestMatch.po_amount) }}</div>
                </div>
                <div>
                  <div class="text-overline">{{ t('invoices_show.goods_received') }}</div>
                  <div class="text-h6">{{ formatCurrency(latestMatch.grn_value) }}</div>
                </div>
                <div>
                  <div class="text-overline">{{ t('invoices_show.invoice') }}</div>
                  <div class="text-h6">{{ formatCurrency(latestMatch.invoice_amount) }}</div>
                </div>
                <div>
                  <div class="text-overline">{{ t('invoices_show.variance') }}</div>
                  <div class="text-h6">{{ formatCurrency(latestMatch.variance_amount) }}</div>
                </div>
              </div>
              <div class="d-flex flex-wrap gap-2 mb-3">
                <VChip
                  size="small"
                  :color="latestMatch.status === 'matched' ? 'success' : 'error'"
                  variant="tonal"
                >
                  {{ latestMatch.type }} · {{ getMatchLabel(latestMatch.status) }}
                </VChip>
                <VChip
                  size="small"
                  :color="latestMatch.amount_ok ? 'success' : 'error'"
                  variant="tonal"
                >
                  {{ t('invoices_show.amount') }} {{ latestMatch.amount_ok ? 'OK' : 'X' }}
                </VChip>
                <VChip
                  size="small"
                  :color="latestMatch.qty_ok ? 'success' : 'error'"
                  variant="tonal"
                >
                  {{ t('invoices_show.qty') }} {{ latestMatch.qty_ok ? 'OK' : 'X' }}
                </VChip>
                <VChip
                  size="small"
                  :color="latestMatch.price_ok ? 'success' : 'error'"
                  variant="tonal"
                >
                  {{ t('invoices_show.price') }} {{ latestMatch.price_ok ? 'OK' : 'X' }}
                </VChip>
                <VChip
                  size="small"
                  :color="latestMatch.within_tolerance ? 'success' : 'error'"
                  variant="tonal"
                >
                  ±{{ latestMatch.tolerance_pct }}%
                </VChip>
              </div>
              <VAlert
                v-if="latestMatch.discrepancies.length"
                type="warning"
                variant="tonal"
                density="compact"
              >
                <ul class="pl-4 mb-0">
                  <li v-for="(d, i) in latestMatch.discrepancies" :key="i" class="text-body-2">
                    {{ d.message }}
                  </li>
                </ul>
              </VAlert>
              <VAlert v-else type="success" variant="tonal" density="compact">
                {{ t('invoices_show.no_discrepancies') }}
              </VAlert>
            </div>
            <div v-else class="text-medium-emphasis">
              {{ t('invoices_show.not_matched') }}
            </div>
          </VCardText>
        </VCard>

        <!-- Reconcile -->
        <VCard v-if="canReconcile" border class="mb-4">
          <VCardTitle>{{ t('invoices_show.reconcile') }}</VCardTitle>
          <VCardText>
            <VBtnToggle
              v-model="reconcileAction"
              mandatory
              color="primary"
              variant="outlined"
              density="comfortable"
              class="mb-3"
            >
              <VBtn value="accept">{{ t('invoices_show.accept') }}</VBtn>
              <VBtn value="adjust">{{ t('invoices_show.accept_variance') }}</VBtn>
              <VBtn value="dispute">{{ t('invoices_show.dispute') }}</VBtn>
            </VBtnToggle>
            <VTextField
              v-model="reconcileNote"
              :label="t('invoices_show.note')"
              density="compact"
              variant="outlined"
              hide-details
              class="mb-3"
            />
            <VBtn color="primary" prepend-icon="tabler-circle-check" @click="reconcile">
              {{ t('invoices_show.reconcile_invoice') }}
            </VBtn>
            <p class="text-caption text-medium-emphasis mt-2 mb-0">
              {{ t('invoices_show.reconcile_info') }}
            </p>
          </VCardText>
        </VCard>
      </VCol>

      <!-- ── Right Column ────────────────────────────────────────────────── -->
      <VCol cols="12" md="5">
        <!-- Budget -->
        <VCard v-if="budgetSnapshot" border class="mb-4">
          <VCardTitle>{{ t('invoices_show.budget') }}</VCardTitle>
          <VCardText>
            <div class="d-flex justify-space-between py-1">
              <span class="text-medium-emphasis">{{ t('invoices_show.allocated') }}</span>
              <span>{{ formatCurrency(budgetSnapshot.allocated) }}</span>
            </div>
            <div class="d-flex justify-space-between py-1">
              <span class="text-medium-emphasis">{{ t('invoices_show.committed') }}</span>
              <span>{{ formatCurrency(budgetSnapshot.committed) }}</span>
            </div>
            <div class="d-flex justify-space-between py-1">
              <span class="text-medium-emphasis">{{ t('invoices_show.actual_spent') }}</span>
              <span>{{ formatCurrency(budgetSnapshot.actual) }}</span>
            </div>
            <VDivider class="my-2" />
            <div class="d-flex justify-space-between py-1 font-weight-bold">
              <span>{{ t('invoices_show.available') }}</span>
              <span>{{ formatCurrency(budgetSnapshot.available) }}</span>
            </div>
          </VCardText>
        </VCard>

        <!-- PO vs received -->
        <VCard v-if="inv.po_items.length" border class="mb-4">
          <VCardTitle>{{ t('invoices_show.po_vs_received') }}</VCardTitle>
          <VTable density="compact">
            <thead>
              <tr>
                <th>{{ t('invoices_show.item') }}</th>
                <th class="text-right">{{ t('invoices_show.ordered') }}</th>
                <th class="text-right">{{ t('invoices_show.received') }}</th>
                <th class="text-right">{{ t('invoices_show.unit') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(p, i) in inv.po_items" :key="i">
                <td>{{ p.name }}</td>
                <td class="text-right">{{ p.quantity }}</td>
                <td class="text-right">{{ p.received_qty }}</td>
                <td class="text-right">{{ formatCurrency(p.unit_price) }}</td>
              </tr>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="3" class="text-right text-medium-emphasis">
                  {{ t('invoices_show.goods_received_value') }}
                </td>
                <td class="text-right font-weight-medium">{{ formatCurrency(inv.grn_value) }}</td>
              </tr>
            </tfoot>
          </VTable>
        </VCard>

        <!-- Details -->
        <VCard border>
          <VCardTitle>{{ t('invoices_show.details') }}</VCardTitle>
          <VCardText>
            <div class="d-flex justify-space-between py-1">
              <span class="text-medium-emphasis">{{ t('invoices_show.source') }}</span>
              <span>{{ inv.source }}</span>
            </div>
            <div class="d-flex justify-space-between py-1">
              <span class="text-medium-emphasis">{{ t('invoices_show.match') }}</span>
              <span>{{ getMatchLabel(inv.match_status) }}</span>
            </div>
            <div class="d-flex justify-space-between py-1">
              <span class="text-medium-emphasis">{{ t('invoices_show.payment') }}</span>
              <span>{{ getPaymentLabel(inv.payment_status) }}</span>
            </div>
            <div class="d-flex justify-space-between py-1">
              <span class="text-medium-emphasis">{{ t('invoices_show.invoice_date') }}</span>
              <span>{{ formatDate(inv.invoice_date) }}</span>
            </div>
            <div class="d-flex justify-space-between py-1">
              <span class="text-medium-emphasis">{{ t('invoices_show.due_date') }}</span>
              <span>{{ formatDate(inv.due_date) }}</span>
            </div>
            <div v-if="inv.reconciled_at" class="d-flex justify-space-between py-1">
              <span class="text-medium-emphasis">{{ t('invoices_show.reconciled') }}</span>
              <span>{{ formatDate(inv.reconciled_at) }}</span>
            </div>
            <div v-if="inv.notes" class="mt-2 text-caption" style="white-space:pre-wrap">
              {{ inv.notes }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <ConfirmDialog ref="confirmDialog" />
  </div>
</template>
