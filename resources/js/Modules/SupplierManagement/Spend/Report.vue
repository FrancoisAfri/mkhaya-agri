<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

// ── Types ──────────────────────────────────────────────────────────────────────
interface Kpis {
  total_spend: number
  off_contract_value: number
  off_contract_invoice_value: number
  off_contract_po_value: number
  off_contract_count: number
  off_contract_pct: number
  suppliers_count: number
}

interface SpendRow {
  supplier_id: number
  company_name: string
  total: number
  invoices: number
}

interface LeakInvoice {
  id: number
  invoice_number: string
  company_name: string
  amount: number
  currency: string
  status: string
  invoice_date: string | null
  type: string
}

interface LeakPo {
  id: number
  po_number: string
  company_name: string
  amount: number
  currency: string
  status: string
  order_date: string | null
  type: string
}

// ── Props ──────────────────────────────────────────────────────────────────────
const props = defineProps<{
  kpis: Kpis
  spendBySupplier: SpendRow[]
  offContractInvoices: LeakInvoice[]
  offContractPurchaseOrders: LeakPo[]
}>()

// ── Helpers ────────────────────────────────────────────────────────────────────
function formatCurrency(amount: number, currency = 'ZAR'): string {
  return `${currency} ${Number(amount).toLocaleString(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`
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

function getStatusLabel(status: string): string {
  const map: Record<string, string> = {
    pending: t('supplier_spend_analysis.statuses.pending'),
    matched: t('supplier_spend_analysis.statuses.matched'),
    paid: t('supplier_spend_analysis.statuses.paid'),
    disputed: t('supplier_spend_analysis.statuses.disputed'),
    draft: t('supplier_spend_analysis.statuses.draft'),
    approved: t('supplier_spend_analysis.statuses.approved'),
    received: t('supplier_spend_analysis.statuses.received'),
    closed: t('supplier_spend_analysis.statuses.closed'),
    cancelled: t('supplier_spend_analysis.statuses.cancelled'),
  }
  return map[status] || status
}

// ── Computed ──────────────────────────────────────────────────────────────────
const maxSupplierSpend = computed(() =>
  props.spendBySupplier.reduce((m, r) => Math.max(m, r.total), 0) || 1
)

function barWidth(total: number): string {
  return `${Math.max(2, Math.round((total / maxSupplierSpend.value) * 100))}%`
}
</script>

<template>
  <div class="pa-4">
    <h1 class="text-h4 mb-4">{{ t('supplier_spend_analysis.page_title') }}</h1>

    <!-- KPI cards -->
    <VRow class="mb-2">
      <VCol cols="12" sm="6" md="3">
        <VCard border>
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('supplier_spend_analysis.kpis.total_spend') }}</div>
            <div class="text-h5 font-weight-bold">{{ formatCurrency(kpis.total_spend) }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard border :color="kpis.off_contract_pct > 0 ? 'warning' : undefined" variant="tonal">
          <VCardText>
            <div class="text-caption">{{ t('supplier_spend_analysis.kpis.off_contract_spend') }}</div>
            <div class="text-h5 font-weight-bold">{{ formatCurrency(kpis.off_contract_invoice_value) }}</div>
            <div class="text-caption">{{ kpis.off_contract_pct }}% {{ t('supplier_spend_analysis.kpis.total_spend') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard border>
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('supplier_spend_analysis.kpis.off_contract_items') }}</div>
            <div class="text-h5 font-weight-bold">{{ kpis.off_contract_count }}</div>
            <div class="text-caption text-medium-emphasis">
              {{ t('supplier_spend_analysis.kpis.off_contract_invoices_po', { amount: formatCurrency(kpis.off_contract_po_value) }) }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard border>
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('supplier_spend_analysis.kpis.suppliers') }}</div>
            <div class="text-h5 font-weight-bold">{{ kpis.suppliers_count }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow>
      <!-- Top suppliers by spend -->
      <VCol cols="12" md="5">
        <VCard border>
          <VCardTitle class="text-subtitle-1">{{ t('supplier_spend_analysis.top_suppliers.title') }}</VCardTitle>
          <VCardText>
            <div v-if="!spendBySupplier.length" class="text-medium-emphasis text-center py-6">
              {{ t('supplier_spend_analysis.top_suppliers.no_spend') }}
            </div>
            <div v-for="row in spendBySupplier" :key="row.supplier_id" class="mb-3">
              <div class="d-flex justify-space-between text-body-2 mb-1">
                <span class="font-weight-medium">{{ row.company_name }}</span>
                <span>{{ formatCurrency(row.total) }}</span>
              </div>
              <div class="bg-grey-lighten-3 rounded" style="height: 8px; width: 100%">
                <div class="bg-primary rounded" :style="{ width: barWidth(row.total), height: '8px' }" />
              </div>
              <div class="text-caption text-medium-emphasis">
                {{ t('supplier_spend_analysis.top_suppliers.invoices', { count: row.invoices }) }}
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Off-contract leakage table -->
      <VCol cols="12" md="7">
        <VCard border>
          <VCardTitle class="text-subtitle-1 d-flex align-center gap-2">
            <VIcon color="warning" icon="mdi-alert" size="20" />
            {{ t('supplier_spend_analysis.off_contract.invoices_title') }}
          </VCardTitle>
          <VTable density="compact">
            <thead>
              <tr>
                <th>{{ t('supplier_spend_analysis.off_contract.table.invoice') }}</th>
                <th>{{ t('supplier_spend_analysis.off_contract.table.supplier') }}</th>
                <th class="text-right">{{ t('supplier_spend_analysis.off_contract.table.amount') }}</th>
                <th>{{ t('supplier_spend_analysis.off_contract.table.status') }}</th>
                <th>{{ t('supplier_spend_analysis.off_contract.table.date') }}</th>
                <th>{{ t('supplier_spend_analysis.off_contract.table.flag') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!offContractInvoices.length">
                <td colspan="6" class="text-center text-medium-emphasis py-6">
                  {{ t('supplier_spend_analysis.off_contract.no_invoices') }}
                </td>
              </tr>
              <tr v-for="inv in offContractInvoices" :key="inv.id">
                <td class="font-weight-medium">{{ inv.invoice_number }}</td>
                <td>{{ inv.company_name }}</td>
                <td class="text-right">{{ formatCurrency(inv.amount, inv.currency) }}</td>
                <td>{{ getStatusLabel(inv.status) }}</td>
                <td>{{ formatDate(inv.invoice_date) }}</td>
                <td>
                  <VChip size="x-small" color="warning" variant="tonal" prepend-icon="mdi-alert">
                    {{ t('supplier_spend_analysis.off_contract.flag') }}
                  </VChip>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>

        <VCard border class="mt-4" v-if="offContractPurchaseOrders.length">
          <VCardTitle class="text-subtitle-1 d-flex align-center gap-2">
            <VIcon color="warning" icon="mdi-alert" size="20" />
            {{ t('supplier_spend_analysis.off_contract.pos_title') }}
          </VCardTitle>
          <VTable density="compact">
            <thead>
              <tr>
                <th>{{ t('supplier_spend_analysis.off_contract.table.po') }}</th>
                <th>{{ t('supplier_spend_analysis.off_contract.table.supplier') }}</th>
                <th class="text-right">{{ t('supplier_spend_analysis.off_contract.table.amount') }}</th>
                <th>{{ t('supplier_spend_analysis.off_contract.table.status') }}</th>
                <th>{{ t('supplier_spend_analysis.off_contract.table.date') }}</th>
                <th>{{ t('supplier_spend_analysis.off_contract.table.flag') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="po in offContractPurchaseOrders" :key="po.id">
                <td class="font-weight-medium">{{ po.po_number }}</td>
                <td>{{ po.company_name }}</td>
                <td class="text-right">{{ formatCurrency(po.amount, po.currency) }}</td>
                <td>{{ getStatusLabel(po.status) }}</td>
                <td>{{ formatDate(po.order_date) }}</td>
                <td>
                  <VChip size="x-small" color="warning" variant="tonal" prepend-icon="mdi-alert">
                    {{ t('supplier_spend_analysis.off_contract.flag') }}
                  </VChip>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
