<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

const { t } = useI18n()
const route = ziggyRoute
const confirmDialog = ref<InstanceType<typeof ConfirmDialog> | null>(null)

// ── Types ──────────────────────────────────────────────────────────────────────
type Item = {
  name: string
  quantity: number
  unit_price: number
  line_total: number
}

type Attachment = {
  id: number
  file_name: string
}

type History = {
  action: string
  from_status: string | null
  to_status: string | null
  note: string | null
  created_at: string | null
}

type Officer = {
  id: number
  name: string
}

type Supplier = {
  id: number
  company_name: string
  is_preferred: boolean
}

// ── Props ──────────────────────────────────────────────────────────────────────
const props = defineProps<{
  requisition: {
    id: number
    requisition_number: string
    title: string
    procurement_method: string | null
    status: string
    estimated_total: number | null
    currency: string | null
    officer_id: number | null
    supplier_id: number | null
    rfx_event_id: number | null
    reservation_confirmed: boolean
    budget: { code: string } | null
    purchaseRequest: { request_number: string } | null
    items: Item[]
    attachments: Attachment[]
    history: History[]
  }
  officers: Officer[]
  suppliers: Supplier[]
  methods: string[]
}>()

// ── State ──────────────────────────────────────────────────────────────────────
const TERMINAL = ['closed', 'cancelled']
const canCancel = computed(() => !TERMINAL.includes(props.requisition.status))
const canGeneratePo = computed(() =>
  props.requisition.status === 'awarded' && !!props.requisition.supplier_id
)

const selectedMethod = ref(props.requisition.procurement_method ?? '')
const selectedOfficer = ref<number | null>(props.requisition.officer_id)
const rfqSupplierIds = ref<number[]>([])
const awardSupplierId = ref<number | null>(props.requisition.supplier_id)
const file = ref<File | null>(null)
const uploading = ref(false)

// ── Constants ──────────────────────────────────────────────────────────────────
const statusColor: Record<string, string> = {
  open: 'grey',
  sourcing: 'info',
  quoting: 'warning',
  awarded: 'success',
  po_created: 'primary',
  closed: 'grey',
  cancelled: 'error',
}

// ═══ CORRECTION: Computed helpers ═══
const statusMap = computed(() => ({
  open: t('requisition_show.statuses.open'),
  sourcing: t('requisition_show.statuses.sourcing'),
  quoting: t('requisition_show.statuses.quoting'),
  awarded: t('requisition_show.statuses.awarded'),
  po_created: t('requisition_show.statuses.po_created'),
  closed: t('requisition_show.statuses.closed'),
  cancelled: t('requisition_show.statuses.cancelled'),
}))

const methodMap = computed(() => ({
  rfq: t('requisition_show.methods.rfq'),
  direct: t('requisition_show.methods.direct'),
  marketplace: t('requisition_show.methods.marketplace'),
  contract: t('requisition_show.methods.contract'),
}))

// ── Computed ──────────────────────────────────────────────────────────────────
const sortedSuppliers = computed(() =>
  [...props.suppliers].sort((a, b) => Number(b.is_preferred) - Number(a.is_preferred))
)

const supplierItems = computed(() =>
  sortedSuppliers.value.map(s => ({
    title: `${s.is_preferred ? '⭐ ' : ''}${s.company_name}`,
    value: s.id,
  }))
)

const grandTotal = computed(() =>
  props.requisition.items.reduce((sum, it) => sum + (Number(it.line_total) || 0), 0)
)

// ── Helpers ────────────────────────────────────────────────────────────────────
function getStatusLabel(status: string): string {
  return statusMap.value[status as keyof typeof statusMap.value] || status
}

function getMethodLabel(method: string): string {
  return methodMap.value[method as keyof typeof methodMap.value] || method
}

function formatCurrency(value: number | null): string {
  if (value == null) return '—'
  try {
    return new Intl.NumberFormat('en-ZA', {
      style: 'currency',
      currency: props.requisition.currency || 'ZAR',
      maximumFractionDigits: 0,
    }).format(value)
  } catch {
    return `${props.requisition.currency || ''} ${value}`.trim()
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
  router.get(route('requisitions.index'))
}

function generatePo(): void {
  confirmDialog.value?.open(
    {
      title: t('requisition_show.confirm.generate_po.title'),
      message: t('requisition_show.confirm.generate_po.message'),
      confirmText: t('requisition_show.confirm.generate_po.confirm'),
      confirmColor: 'warning',
      icon: 'tabler-alert-triangle',
    },
    () => {
      router.post(route('requisitions.generate-po', props.requisition.uuid), {}, { preserveScroll: true })
    }
  )
}

function setMethod(): void {
  router.post(
    route('requisitions.method', props.requisition.uuid),
    { method: selectedMethod.value },
    { preserveScroll: true }
  )
}

function assignOfficer(): void {
  router.post(
    route('requisitions.assign-officer', props.requisition.uuid),
    { officer_id: selectedOfficer.value },
    { preserveScroll: true }
  )
}

function confirmReservation(): void {
  router.post(
    route('requisitions.confirm-reservation', props.requisition.uuid),
    {},
    { preserveScroll: true }
  )
}

function issueRfq(): void {
  router.post(
    route('requisitions.rfq', props.requisition.uuid),
    { supplier_ids: rfqSupplierIds.value },
    { preserveScroll: true }
  )
}

function chooseSupplier(): void {
  router.post(
    route('requisitions.choose-supplier', props.requisition.uuid),
    { supplier_id: awardSupplierId.value },
    { preserveScroll: true }
  )
}

function cancelRequisition(): void {
  confirmDialog.value?.open(
    {
      title: t('requisition_show.confirm.cancel.title'),
      message: t('requisition_show.confirm.cancel.message'),
      confirmText: t('requisition_show.confirm.cancel.confirm'),
      confirmColor: 'warning',
      icon: 'tabler-alert-triangle',
    },
    () => {
      router.post(route('requisitions.cancel', props.requisition.uuid), {}, { preserveScroll: true })
    }
  )
}

function downloadAttachment(a: Attachment): void {
  window.open(route('requisitions.attachments.download', a.id))
}

function uploadAttachment(): void {
  if (!file.value) return
  uploading.value = true
  router.post(
    route('requisitions.attachments.store', props.requisition.uuid),
    { file: file.value },
    {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => (file.value = null),
      onFinish: () => (uploading.value = false),
    }
  )
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div class="d-flex align-center gap-3">
        <VBtn icon="tabler-arrow-left" variant="text" @click="goToIndex" />
        <div>
          <h4 class="text-h4 mb-0">{{ requisition.requisition_number }}</h4>
          <p class="text-caption text-medium-emphasis mb-0">{{ requisition.title }}</p>
        </div>
        <VChip :color="statusColor[requisition.status] || 'grey'" variant="tonal">
          {{ getStatusLabel(requisition.status) }}
        </VChip>
        <VChip
          v-if="requisition.purchaseRequest"
          color="info"
          variant="tonal"
          prepend-icon="tabler-link"
        >
          {{ requisition.purchaseRequest.request_number }}
        </VChip>
      </div>
      <div class="d-flex gap-2">
        <VBtn
          v-if="canGeneratePo"
          color="primary"
          prepend-icon="tabler-file-invoice"
          @click="generatePo"
        >
          {{ t('requisition_show.generate_po') }}
        </VBtn>
        <VBtn
          v-if="canCancel"
          color="error"
          variant="tonal"
          prepend-icon="tabler-x"
          @click="cancelRequisition"
        >
          {{ t('requisition_show.cancel') }}
        </VBtn>
      </div>
    </div>

    <VRow>
      <!-- ── Left Column ────────────────────────────────────────────────── -->
      <VCol cols="12" md="7">
        <!-- Procurement setup -->
        <VCard border class="mb-4">
          <VCardTitle>{{ t('requisition_show.procurement_setup') }}</VCardTitle>
          <VCardText>
            <div class="d-flex flex-wrap align-end gap-3 mb-4">
              <VSelect
                v-model="selectedMethod"
                :items="methods"
                :label="t('requisition_show.procurement_method')"
                density="compact"
                variant="outlined"
                hide-details
                style="max-width:260px"
              />
              <VBtn color="primary" variant="tonal" @click="setMethod">
                {{ t('requisition_show.set_method') }}
              </VBtn>
            </div>

            <div class="d-flex flex-wrap align-end gap-3 mb-4">
              <VSelect
                v-model="selectedOfficer"
                :items="officers"
                item-title="name"
                item-value="id"
                :label="t('requisition_show.procurement_officer')"
                density="compact"
                variant="outlined"
                hide-details
                style="max-width:260px"
              />
              <VBtn color="primary" variant="tonal" @click="assignOfficer">
                {{ t('requisition_show.assign') }}
              </VBtn>
            </div>

            <div class="d-flex flex-wrap align-center gap-3">
              <VBtn
                color="success"
                variant="tonal"
                prepend-icon="tabler-lock-check"
                :disabled="requisition.reservation_confirmed"
                @click="confirmReservation"
              >
                {{ t('requisition_show.confirm_budget_reservation') }}
              </VBtn>
              <VChip
                v-if="requisition.reservation_confirmed"
                color="success"
                variant="tonal"
                prepend-icon="tabler-check"
              >
                {{ t('requisition_show.budget_reserved') }}
              </VChip>
            </div>
          </VCardText>
        </VCard>

        <!-- Sourcing -->
        <VCard border class="mb-4">
          <VCardTitle class="d-flex align-center justify-space-between">
            <span>{{ t('requisition_show.sourcing') }}</span>
            <VChip
              v-if="requisition.rfx_event_id"
              color="info"
              variant="tonal"
              prepend-icon="tabler-mail-forward"
            >
              {{ t('requisition_show.rfq_issued') }}
            </VChip>
          </VCardTitle>
          <VCardText>
            <div class="d-flex flex-wrap align-end gap-3 mb-4">
              <VSelect
                v-model="rfqSupplierIds"
                :items="supplierItems"
                :label="t('requisition_show.suppliers_for_rfq')"
                multiple
                chips
                closable-chips
                density="compact"
                variant="outlined"
                hide-details
                style="min-width:320px;flex:1"
              />
              <VBtn
                color="primary"
                variant="tonal"
                :disabled="!rfqSupplierIds.length"
                @click="issueRfq"
              >
                {{ t('requisition_show.issue_rfq') }}
              </VBtn>
            </div>

            <div class="d-flex flex-wrap align-end gap-3">
              <VSelect
                v-model="awardSupplierId"
                :items="supplierItems"
                :label="t('requisition_show.award_to_supplier')"
                density="compact"
                variant="outlined"
                hide-details
                style="min-width:320px;flex:1"
              />
              <VBtn
                color="success"
                variant="tonal"
                :disabled="!awardSupplierId"
                @click="chooseSupplier"
              >
                {{ t('requisition_show.award_supplier') }}
              </VBtn>
            </div>
          </VCardText>
        </VCard>

        <!-- Items -->
        <VCard border class="mb-4">
          <VCardTitle>{{ t('requisition_show.items') }}</VCardTitle>
          <VTable>
            <thead>
              <tr>
                <th>{{ t('requisition_show.name') }}</th>
                <th class="text-right">{{ t('requisition_show.quantity') }}</th>
                <th class="text-right">{{ t('requisition_show.unit_price') }}</th>
                <th class="text-right">{{ t('requisition_show.line_total') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!requisition.items.length">
                <td colspan="4" class="text-center text-medium-emphasis py-4">
                  {{ t('requisition_show.no_items') }}
                </td>
              </tr>
              <tr v-for="(it, i) in requisition.items" :key="i">
                <td class="font-weight-medium">{{ it.name }}</td>
                <td class="text-right">{{ it.quantity }}</td>
                <td class="text-right">{{ formatCurrency(it.unit_price) }}</td>
                <td class="text-right">{{ formatCurrency(it.line_total) }}</td>
              </tr>
            </tbody>
            <tfoot v-if="requisition.items.length">
              <tr>
                <td colspan="3" class="text-right font-weight-medium">
                  {{ t('requisition_show.grand_total') }}
                </td>
                <td class="text-right font-weight-bold">{{ formatCurrency(grandTotal) }}</td>
              </tr>
            </tfoot>
          </VTable>
        </VCard>
      </VCol>

      <!-- ── Right Column ────────────────────────────────────────────────── -->
      <VCol cols="12" md="5">
        <!-- Attachments -->
        <VCard border class="mb-4">
          <VCardTitle>{{ t('requisition_show.attachments') }}</VCardTitle>
          <VCardText>
            <VList v-if="requisition.attachments.length" density="compact">
              <VListItem
                v-for="a in requisition.attachments"
                :key="a.id"
                :title="a.file_name"
                prepend-icon="tabler-paperclip"
              >
                <template #append>
                  <VBtn
                    icon="tabler-download"
                    size="small"
                    variant="text"
                    @click="downloadAttachment(a)"
                  />
                </template>
              </VListItem>
            </VList>
            <div v-else class="text-medium-emphasis mb-3">
              {{ t('requisition_show.no_attachments') }}
            </div>
            <VFileInput
              v-model="file"
              :label="t('requisition_show.upload_attachment')"
              variant="outlined"
              density="compact"
              hide-details
              prepend-icon="tabler-paperclip"
              class="mb-2"
              @update:model-value="(f: any) => file = Array.isArray(f) ? f[0] : f"
            />
            <VBtn
              color="primary"
              block
              :loading="uploading"
              :disabled="!file"
              @click="uploadAttachment"
            >
              {{ t('requisition_show.upload') }}
            </VBtn>
          </VCardText>
        </VCard>

        <!-- History -->
        <VCard border>
          <VCardTitle>{{ t('requisition_show.history') }}</VCardTitle>
          <VCardText>
            <div v-if="!requisition.history.length" class="text-medium-emphasis">
              {{ t('requisition_show.no_history') }}
            </div>
            <VTimeline v-else side="end" density="compact" truncate-line="both">
              <VTimelineItem
                v-for="(h, i) in requisition.history"
                :key="i"
                size="x-small"
                :dot-color="statusColor[h.to_status ?? ''] || 'primary'"
              >
                <div class="font-weight-medium">{{ h.action }}</div>
                <div v-if="h.from_status || h.to_status" class="text-caption">
                  {{ h.from_status ? h.from_status + ' → ' : '' }}{{ h.to_status }}
                </div>
                <div v-if="h.note" class="text-caption">{{ h.note }}</div>
                <div class="text-caption text-medium-emphasis">
                  {{ formatDate(h.created_at) }}
                </div>
              </VTimelineItem>
            </VTimeline>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <ConfirmDialog ref="confirmDialog" />
  </div>
</template>
