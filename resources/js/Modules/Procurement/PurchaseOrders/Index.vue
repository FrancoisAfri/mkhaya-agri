<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

// ── Types ──────────────────────────────────────────────────────────────────────
type Order = {
  id: number
  po_number: string
  title: string
  supplier: string | null
  amount: number
  currency: string | null
  status: string
  received_status: string
  source: string
  order_date: string | null
}

// ── Props ──────────────────────────────────────────────────────────────────────
const props = defineProps<{
  orders: { data: Order[]; links: any[]; meta?: any }
  filters: { search?: string; status?: string; received?: string }
  statuses: string[]
}>()

// ── State ──────────────────────────────────────────────────────────────────────
const search = ref(props.filters.search ?? '')
const status = ref(props.filters.status ?? '')
const received = ref(props.filters.received ?? '')

let timeoutId: ReturnType<typeof setTimeout>

// ── Constants ──────────────────────────────────────────────────────────────────
const statusColor: Record<string, string> = {
  draft: 'grey',
  approved: 'info',
  sent: 'primary',
  cancelled: 'error',
}

const recvColor: Record<string, string> = {
  pending: 'grey',
  partial: 'warning',
  received: 'success',
}

// ═══ Computed helpers ═══
const statusMap = computed(() => ({
  draft: t('purchase_orders_index.statuses.draft'),
  approved: t('purchase_orders_index.statuses.approved'),
  sent: t('purchase_orders_index.statuses.sent'),
  cancelled: t('purchase_orders_index.statuses.cancelled'),
}))

const receiptMap = computed(() => ({
  pending: t('purchase_orders_index.receipt_options.pending'),
  partial: t('purchase_orders_index.receipt_options.partial'),
  received: t('purchase_orders_index.receipt_options.received'),
}))

const sourceMap = computed(() => ({
  requisition: t('purchase_orders_index.sources.requisition'),
  manual: t('purchase_orders_index.sources.manual'),
}))

// ── Helpers ────────────────────────────────────────────────────────────────────
function getStatusLabel(status: string): string {
  return statusMap.value[status as keyof typeof statusMap.value] || status
}

function getReceiptLabel(received: string): string {
  return receiptMap.value[received as keyof typeof receiptMap.value] || received
}

function getSourceLabel(source: string): string {
  return sourceMap.value[source as keyof typeof sourceMap.value] || source
}

function formatCurrency(value: number, currency: string | null): string {
  try {
    return new Intl.NumberFormat('en-ZA', {
      style: 'currency',
      currency: currency || 'ZAR',
      maximumFractionDigits: 0,
    }).format(value)
  } catch {
    return `${currency || ''} ${value}`.trim()
  }
}

function formatDate(date: string | null): string {
  if (!date) return '—'
  const d = new Date(date)
  return d.toLocaleDateString('en-ZA', {
    year: 'numeric',
    month: 'short',
    day: '2-digit',
  })
}

// ── Watch ──────────────────────────────────────────────────────────────────────
watch([search, status, received], () => {
  clearTimeout(timeoutId)
  timeoutId = setTimeout(() => {
    router.get(
      route('purchase-orders.index'),
      {
        search: search.value || undefined,
        status: status.value || undefined,
        received: received.value || undefined,
      },
      { preserveState: true, replace: true }
    )
  }, 300)
})

// ── Methods ────────────────────────────────────────────────────────────────────
// Addressed by uuid, not the row id.
function goToShow(uuid: string): void {
  router.get(route('purchase-orders.show', uuid))
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div class="d-flex align-center gap-3">
        <VAvatar color="primary" variant="tonal" size="48" rounded>
          <VIcon icon="tabler-file-invoice" size="26" />
        </VAvatar>
        <h4 class="text-h4 mb-0">{{ t('purchase_orders_index.page_title') }}</h4>
      </div>
    </div>

    <!-- Filters -->
    <VCard border class="mb-4">
      <VCardText class="d-flex flex-wrap gap-3">
        <VTextField
          v-model="search"
          :label="t('purchase_orders_index.search_placeholder')"
          density="compact"
          variant="outlined"
          hide-details
          prepend-inner-icon="tabler-search"
          style="max-width:280px"
          clearable
        />
        <VSelect
          v-model="status"
          :items="statuses"
          :label="t('purchase_orders_index.status')"
          density="compact"
          variant="outlined"
          hide-details
          clearable
          style="max-width:200px"
        />
        <VSelect
          v-model="received"
          :items="['pending', 'partial', 'received']"
          :label="t('purchase_orders_index.receipt')"
          density="compact"
          variant="outlined"
          hide-details
          clearable
          style="max-width:200px"
        />
      </VCardText>
    </VCard>

    <!-- Table -->
    <VCard border>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('purchase_orders_index.table.po') }}</th>
            <th>{{ t('purchase_orders_index.table.title') }}</th>
            <th>{{ t('purchase_orders_index.table.supplier') }}</th>
            <th class="text-right">{{ t('purchase_orders_index.table.amount') }}</th>
            <th>{{ t('purchase_orders_index.table.source') }}</th>
            <th>{{ t('purchase_orders_index.table.status') }}</th>
            <th>{{ t('purchase_orders_index.table.receipt') }}</th>
            <th>{{ t('purchase_orders_index.table.date') }}</th>
            <th class="text-center">{{ t('actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!orders.data.length">
            <td colspan="9" class="text-center text-medium-emphasis py-6">
              {{ t('purchase_orders_index.no_orders') }}
            </td>
          </tr>
          <tr v-for="o in orders.data" :key="o.id">
            <td class="font-weight-medium">{{ o.po_number }}</td>
            <td>{{ o.title }}</td>
            <td>{{ o.supplier || '—' }}</td>
            <td class="text-right">{{ formatCurrency(o.amount, o.currency) }}</td>
            <td>
              <VChip size="small" variant="tonal">
                {{ getSourceLabel(o.source) }}
              </VChip>
            </td>
            <td>
              <VChip
                size="small"
                :color="statusColor[o.status] || 'grey'"
                variant="tonal"
              >
                {{ getStatusLabel(o.status) }}
              </VChip>
            </td>
            <td>
              <VChip
                size="small"
                :color="recvColor[o.received_status] || 'grey'"
                variant="tonal"
              >
                {{ getReceiptLabel(o.received_status) }}
              </VChip>
            </td>
            <td>{{ formatDate(o.order_date) }}</td>
            <td class="text-center">
              <VBtn
                icon="tabler-eye"
                size="x-small"
                variant="text"
                color="primary"
                @click="goToShow(o.uuid)"
              />
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
