<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

// ── Types ──────────────────────────────────────────────────────────────────────
type Invoice = {
  id: number
  invoice_number: string
  amount: number
  currency: string
  status: string
  match_status: string
  payment_status: string
  source: string
  supplier: string | null
  po_number: string | null
  invoice_date: string | null
}

// ── Props ──────────────────────────────────────────────────────────────────────
const props = defineProps<{
  invoices: { data: Invoice[]; links: any[] }
  filters: { search?: string; status?: string; match?: string }
  statuses: string[]
}>()

// ── State ──────────────────────────────────────────────────────────────────────
const search = ref(props.filters.search ?? '')
const status = ref(props.filters.status ?? '')
const match = ref(props.filters.match ?? '')

let timeoutId: ReturnType<typeof setTimeout>

// ── Constants ──────────────────────────────────────────────────────────────────
const statusColor: Record<string, string> = {
  pending: 'grey',
  matched: 'info',
  exception: 'error',
  reconciled: 'primary',
  paid: 'success',
  disputed: 'warning',
}

const matchColor: Record<string, string> = {
  unmatched: 'grey',
  matched: 'success',
  exception: 'error',
}

const payColor: Record<string, string> = {
  unpaid: 'grey',
  approved: 'info',
  paid: 'success',
}

// ═══ Computed helpers ═══
const statusMap = computed(() => ({
  pending: t('invoices_index.statuses.pending'),
  matched: t('invoices_index.statuses.matched'),
  exception: t('invoices_index.statuses.exception'),
  reconciled: t('invoices_index.statuses.reconciled'),
  paid: t('invoices_index.statuses.paid'),
  disputed: t('invoices_index.statuses.disputed'),
}))

const matchMap = computed(() => ({
  unmatched: t('invoices_index.match_options.unmatched'),
  matched: t('invoices_index.match_options.matched'),
  exception: t('invoices_index.match_options.exception'),
}))

const paymentMap = computed(() => ({
  unpaid: t('invoices_index.payment_statuses.unpaid'),
  approved: t('invoices_index.payment_statuses.approved'),
  paid: t('invoices_index.payment_statuses.paid'),
}))

const sourceMap = computed(() => ({
  po: t('invoices_index.sources.po'),
  manual: t('invoices_index.sources.manual'),
  upload: t('invoices_index.sources.upload'),
}))

// ── Helpers ────────────────────────────────────────────────────────────────────
function getStatusLabel(status: string): string {
  return statusMap.value[status as keyof typeof statusMap.value] || status
}

function getMatchLabel(match: string): string {
  return matchMap.value[match as keyof typeof matchMap.value] || match
}

function getPaymentLabel(payment: string): string {
  return paymentMap.value[payment as keyof typeof paymentMap.value] || payment
}

function getSourceLabel(source: string): string {
  return sourceMap.value[source as keyof typeof sourceMap.value] || source
}

function formatCurrency(value: number, currency: string): string {
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
watch([search, status, match], () => {
  clearTimeout(timeoutId)
  timeoutId = setTimeout(() => {
    router.get(
      route('invoices.index'),
      {
        search: search.value || undefined,
        status: status.value || undefined,
        match: match.value || undefined,
      },
      { preserveState: true, replace: true }
    )
  }, 300)
})

// ── Methods ────────────────────────────────────────────────────────────────────
function goToCreate(): void {
  router.get(route('invoices.create'))
}

function goToShow(id: number): void {
  router.get(route('invoices.show', id))
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div class="d-flex align-center gap-3">
        <VAvatar color="primary" variant="tonal" size="48" rounded>
          <VIcon icon="tabler-file-invoice" size="26" />
        </VAvatar>
        <h4 class="text-h4 mb-0">{{ t('invoices_index.page_title') }}</h4>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="goToCreate">
        {{ t('invoices_index.capture_invoice') }}
      </VBtn>
    </div>

    <!-- Filters -->
    <VCard border class="mb-4">
      <VCardText class="d-flex flex-wrap gap-3">
        <VTextField
          v-model="search"
          :label="t('invoices_index.search_placeholder')"
          density="compact"
          variant="outlined"
          hide-details
          prepend-inner-icon="tabler-search"
          style="max-width:260px"
          clearable
        />
        <VSelect
          v-model="status"
          :items="statuses"
          :label="t('invoices_index.status')"
          density="compact"
          variant="outlined"
          hide-details
          clearable
          style="max-width:190px"
        />
        <VSelect
          v-model="match"
          :items="['unmatched', 'matched', 'exception']"
          :label="t('invoices_index.match')"
          density="compact"
          variant="outlined"
          hide-details
          clearable
          style="max-width:190px"
        />
      </VCardText>
    </VCard>

    <!-- Table -->
    <VCard border>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('invoices_index.table.invoice') }}</th>
            <th>{{ t('invoices_index.table.supplier') }}</th>
            <th>{{ t('invoices_index.table.po') }}</th>
            <th class="text-right">{{ t('invoices_index.table.amount') }}</th>
            <th>{{ t('invoices_index.table.source') }}</th>
            <th>{{ t('invoices_index.table.match') }}</th>
            <th>{{ t('invoices_index.table.status') }}</th>
            <th>{{ t('invoices_index.table.payment') }}</th>
            <th>{{ t('invoices_index.table.date') }}</th>
            <th class="text-center">{{ t('invoices_index.table.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!invoices.data.length">
            <td colspan="10" class="text-center text-medium-emphasis py-6">
              {{ t('invoices_index.no_invoices') }}
            </td>
          </tr>
          <tr v-for="i in invoices.data" :key="i.id">
            <td class="font-weight-medium">{{ i.invoice_number }}</td>
            <td>{{ i.supplier || '—' }}</td>
            <td>{{ i.po_number || '—' }}</td>
            <td class="text-right">{{ formatCurrency(i.amount, i.currency) }}</td>
            <td>
              <VChip size="x-small" variant="tonal">
                {{ getSourceLabel(i.source) }}
              </VChip>
            </td>
            <td>
              <VChip
                size="small"
                :color="matchColor[i.match_status] || 'grey'"
                variant="tonal"
              >
                {{ getMatchLabel(i.match_status) }}
              </VChip>
            </td>
            <td>
              <VChip
                size="small"
                :color="statusColor[i.status] || 'grey'"
                variant="tonal"
              >
                {{ getStatusLabel(i.status) }}
              </VChip>
            </td>
            <td>
              <VChip
                size="small"
                :color="payColor[i.payment_status] || 'grey'"
                variant="tonal"
              >
                {{ getPaymentLabel(i.payment_status) }}
              </VChip>
            </td>
            <td>{{ formatDate(i.invoice_date) }}</td>
            <td class="text-center">
              <VBtn
                icon="tabler-eye"
                size="x-small"
                variant="text"
                color="primary"
                @click="goToShow(i.id)"
              />
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
