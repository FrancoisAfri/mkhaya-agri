<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

// ── Types ──────────────────────────────────────────────────────────────────────
interface Request {
  id: number
  request_number: string
  status: string
  estimated_total: number
  currency: string
}

interface PendingApproval {
  approval_id: number
  step: string
  reference: string
  since: string
}

interface ProcurementQueue {
  requisitions_open: number
  requisitions_awarded: number
  po_in_flight: number
  deliveries_late: number
  recent_pos: any[]
}

interface FinanceQueue {
  unmatched: number
  exceptions: number
  to_reconcile: number
  to_pay: number
  recent: any[]
}

interface Executive {
  budget_totals: {
    allocated: number
    committed: number
    actual: number
    available: number
  }
  overall_risk: { score: number; band: string } | null
  open_findings: number
  critical: number
  savings: number
  forecast_overrun: number
  top_suppliers: { company_name: string; spend: number }[]
}

interface Escalation {
  id: number
  reason: string
  at: string
}

interface Narrative {
  text: string
  provider: string | null
  generated: string
}

// ── Props ──────────────────────────────────────────────────────────────────────
const props = defineProps<{
  view: string
  availableViews: string[]
  myRequests: Request[]
  pendingOnMe: PendingApproval[]
  procurementQueue: ProcurementQueue
  financeQueue: FinanceQueue
  executive: Executive
  escalations: Escalation[]
  narrative: Narrative | null
}>()

// ── State ──────────────────────────────────────────────────────────────────────
const view = ref(props.view)

// ── Constants ──────────────────────────────────────────────────────────────────
const statusColor: Record<string, string> = {
  draft: 'grey',
  submitted: 'info',
  pending_approval: 'warning',
  approved: 'success',
  rejected: 'error',
  matched: 'info',
  exception: 'error',
  reconciled: 'primary',
  paid: 'success',
  received: 'success',
  partial: 'warning',
  pending: 'grey',
}

const bandColor: Record<string, string> = {
  critical: 'error',
  high: 'deep-orange',
  medium: 'warning',
  low: 'success',
}

// ── Helpers ────────────────────────────────────────────────────────────────────
function getStatusLabel(status: string): string {
  const map: Record<string, string> = {
    draft: t('procurement_dashboard.statuses.draft'),
    submitted: t('procurement_dashboard.statuses.submitted'),
    pending_approval: t('procurement_dashboard.statuses.pending_approval'),
    approved: t('procurement_dashboard.statuses.approved'),
    rejected: t('procurement_dashboard.statuses.rejected'),
    matched: t('procurement_dashboard.statuses.matched'),
    exception: t('procurement_dashboard.statuses.exception'),
    reconciled: t('procurement_dashboard.statuses.reconciled'),
    paid: t('procurement_dashboard.statuses.paid'),
    received: t('procurement_dashboard.statuses.received'),
    partial: t('procurement_dashboard.statuses.partial'),
    pending: t('procurement_dashboard.statuses.pending'),
  }
  return map[status] || status
}

function getBandLabel(band: string): string {
  const map: Record<string, string> = {
    critical: t('procurement_dashboard.risk_bands.critical'),
    high: t('procurement_dashboard.risk_bands.high'),
    medium: t('procurement_dashboard.risk_bands.medium'),
    low: t('procurement_dashboard.risk_bands.low'),
  }
  return map[band] || band
}

function formatCurrency(value: number): string {
  try {
    return new Intl.NumberFormat('en-ZA', {
      style: 'currency',
      currency: 'ZAR',
      maximumFractionDigits: 0,
    }).format(value)
  } catch {
    return `R ${value}`
  }
}

// ── Methods ────────────────────────────────────────────────────────────────────
function switchView(v: string): void {
  view.value = v
  router.get(
    route('procurement.dashboard'),
    { view: v },
    { preserveState: true, replace: true }
  )
}

function show(section: string): boolean {
  const map: Record<string, string[]> = {
    employee: ['mine', 'pending'],
    manager: ['pending', 'mine', 'escalations'],
    procurement: ['procurement', 'escalations', 'pending'],
    finance: ['finance', 'budget', 'escalations'],
    executive: ['exec', 'narrative', 'escalations'],
  }
  return (map[view.value] || ['mine']).includes(section)
}

function getViewLabel(view: string): string {
  const map: Record<string, string> = {
    employee: t('procurement_dashboard.view_employee'),
    manager: t('procurement_dashboard.view_manager'),
    procurement: t('procurement_dashboard.view_procurement'),
    finance: t('procurement_dashboard.view_finance'),
    executive: t('procurement_dashboard.view_executive'),
  }
  return map[view] || view
}

// ── Computed ──────────────────────────────────────────────────────────────────
const narrativeHtml = computed(() =>
  (props.narrative?.text || '')
    .replace(/^## (.*)$/gm, '<h6 class="text-h6 mt-3 mb-1">$1</h6>')
    .replace(/^- (.*)$/gm, '<li>$1</li>')
    .replace(/^\d+\. (.*)$/gm, '<li>$1</li>')
    .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
    .replace(/\n{2,}/g, '<br>')
)

const viewOptions = computed(() =>
  props.availableViews.map(v => ({
    value: v,
    label: getViewLabel(v)
  }))
)
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <h4 class="text-h4 mb-0">{{ t('procurement_dashboard.page_title') }}</h4>
      <VBtnToggle
        :model-value="view"
        mandatory
        color="primary"
        variant="outlined"
        density="comfortable"
        @update:model-value="switchView"
      >
        <VBtn
          v-for="v in viewOptions"
          :key="v.value"
          :value="v.value"
          size="small"
        >
          {{ v.label }}
        </VBtn>
      </VBtnToggle>
    </div>

    <!-- ── Executive View ────────────────────────────────────────────────── -->
    <template v-if="show('exec')">
      <VRow class="mb-2">
        <VCol cols="6" md="3">
          <VCard border>
            <VCardText>
              <div class="text-overline">{{ t('procurement_dashboard.executive.allocated') }}</div>
              <div class="text-h5">{{ formatCurrency(executive.budget_totals.allocated) }}</div>
            </VCardText>
          </VCard>
        </VCol>
        <VCol cols="6" md="3">
          <VCard border>
            <VCardText>
              <div class="text-overline">{{ t('procurement_dashboard.executive.committed') }}</div>
              <div class="text-h5">{{ formatCurrency(executive.budget_totals.committed) }}</div>
            </VCardText>
          </VCard>
        </VCol>
        <VCol cols="6" md="3">
          <VCard border>
            <VCardText>
              <div class="text-overline">{{ t('procurement_dashboard.executive.actual_spent') }}</div>
              <div class="text-h5">{{ formatCurrency(executive.budget_totals.actual) }}</div>
            </VCardText>
          </VCard>
        </VCol>
        <VCol cols="6" md="3">
          <VCard border>
            <VCardText>
              <div class="text-overline">{{ t('procurement_dashboard.executive.available') }}</div>
              <div class="text-h5">{{ formatCurrency(executive.budget_totals.available) }}</div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <VRow class="mb-2">
        <VCol cols="6" md="3">
          <VCard border>
            <VCardText>
              <div class="text-overline">{{ t('procurement_dashboard.executive.overall_risk') }}</div>
              <div class="text-h4">
                {{ executive.overall_risk ? Math.round(Number(executive.overall_risk.score)) : '—' }}
              </div>
              <VChip
                v-if="executive.overall_risk"
                size="x-small"
                :color="bandColor[executive.overall_risk.band]"
                variant="tonal"
              >
                {{ getBandLabel(executive.overall_risk.band) }}
              </VChip>
            </VCardText>
          </VCard>
        </VCol>
        <VCol cols="6" md="3">
          <VCard border>
            <VCardText>
              <div class="text-overline">{{ t('procurement_dashboard.executive.open_findings') }}</div>
              <div class="text-h4">{{ executive.open_findings }}</div>
              <div class="text-caption text-error">{{ executive.critical }} {{ t('procurement_dashboard.executive.critical') }}</div>
            </VCardText>
          </VCard>
        </VCol>
        <VCol cols="6" md="3">
          <VCard border>
            <VCardText>
              <div class="text-overline">{{ t('procurement_dashboard.executive.savings_found') }}</div>
              <div class="text-h4 text-success">{{ formatCurrency(executive.savings) }}</div>
            </VCardText>
          </VCard>
        </VCol>
        <VCol cols="6" md="3">
          <VCard border>
            <VCardText>
              <div class="text-overline">{{ t('procurement_dashboard.executive.forecast_overrun') }}</div>
              <div class="text-h4">{{ formatCurrency(executive.forecast_overrun) }}</div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <VCard border class="mb-4">
        <VCardTitle class="d-flex align-center justify-space-between">
          <span>{{ t('procurement_dashboard.executive.top_suppliers') }}</span>
          <VBtn
            size="small"
            variant="text"
            prepend-icon="tabler-brain"
            @click="router.get(route('procurement.intelligence.index'))"
          >
            {{ t('procurement_dashboard.executive.spend_intelligence') }}
          </VBtn>
        </VCardTitle>
        <VTable density="compact">
          <thead>
            <tr>
              <th>{{ t('procurement_dashboard.table.supplier') }}</th>
              <th class="text-right">{{ t('procurement_dashboard.table.spend') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!executive.top_suppliers.length">
              <td colspan="2" class="text-center text-medium-emphasis py-3">
                {{ t('procurement_dashboard.my_requests.no_requests') }}
              </td>
            </tr>
            <tr v-for="(s, i) in executive.top_suppliers" :key="i">
              <td>{{ s.company_name }}</td>
              <td class="text-right">{{ formatCurrency(Number(s.spend)) }}</td>
            </tr>
          </tbody>
        </VTable>
      </VCard>
    </template>

    <!-- ── Executive AI Briefing ────────────────────────────────────────── -->
    <VCard v-if="show('narrative') && narrative" border class="mb-4">
      <VCardTitle class="d-flex align-center justify-space-between">
        <span>{{ t('procurement_dashboard.narrative.executive_briefing') }}</span>
        <span class="text-caption text-medium-emphasis">{{ narrative.generated }}</span>
      </VCardTitle>
      <VCardText>
        <div class="text-body-2" v-html="narrativeHtml" />
      </VCardText>
    </VCard>

    <!-- ── Main Row ────────────────────────────────────────────────────────── -->
    <VRow>
      <!-- Approvals pending on me -->
      <VCol v-if="show('pending')" cols="12" md="6">
        <VCard border class="mb-4">
          <VCardTitle>{{ t('procurement_dashboard.pending.title') }}</VCardTitle>
          <VCardText>
            <div v-if="!pendingOnMe.length" class="text-medium-emphasis text-center py-4">
              {{ t('procurement_dashboard.pending.nothing') }}
            </div>
            <VList v-else density="compact">
              <VListItem
                v-for="p in pendingOnMe"
                :key="p.approval_id"
                :title="p.reference"
                :subtitle="`${p.step} · ${t('procurement_dashboard.statuses.pending')} ${p.since}`"
                prepend-icon="tabler-clock"
                @click="router.get(route('procurement.approvals.index'))"
                style="cursor:pointer"
              />
            </VList>
          </VCardText>
        </VCard>
      </VCol>

      <!-- My requests -->
      <VCol v-if="show('mine')" cols="12" md="6">
        <VCard border class="mb-4">
          <VCardTitle class="d-flex align-center justify-space-between">
            <span>{{ t('procurement_dashboard.my_requests.title') }}</span>
            <VBtn
              size="small"
              variant="tonal"
              prepend-icon="tabler-plus"
              @click="router.get(route('purchase-requests.create'))"
            >
              {{ t('procurement_dashboard.my_requests.new') }}
            </VBtn>
          </VCardTitle>
          <VCardText>
            <div v-if="!myRequests.length" class="text-medium-emphasis text-center py-4">
              {{ t('procurement_dashboard.my_requests.no_requests') }}
            </div>
            <VList v-else density="compact">
              <VListItem
                v-for="r in myRequests"
                :key="r.id"
                :title="r.request_number"
                @click="router.get(route('purchase-requests.show', r.uuid))"
                style="cursor:pointer"
              >
                <template #append>
                  <VChip
                    size="x-small"
                    :color="statusColor[r.status] || 'grey'"
                    variant="tonal"
                  >
                    {{ getStatusLabel(r.status) }}
                  </VChip>
                </template>
              </VListItem>
            </VList>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Procurement queue -->
      <VCol v-if="show('procurement')" cols="12">
        <VRow class="mb-2">
          <VCol cols="6" md="3">
            <VCard border>
              <VCardText>
                <div class="text-overline">{{ t('procurement_dashboard.procurement.requisitions_open') }}</div>
                <div class="text-h4">{{ procurementQueue.requisitions_open }}</div>
              </VCardText>
            </VCard>
          </VCol>
          <VCol cols="6" md="3">
            <VCard border>
              <VCardText>
                <div class="text-overline">{{ t('procurement_dashboard.procurement.awarded_to_po') }}</div>
                <div class="text-h4">{{ procurementQueue.requisitions_awarded }}</div>
              </VCardText>
            </VCard>
          </VCol>
          <VCol cols="6" md="3">
            <VCard border>
              <VCardText>
                <div class="text-overline">{{ t('procurement_dashboard.procurement.pos_in_flight') }}</div>
                <div class="text-h4">{{ procurementQueue.po_in_flight }}</div>
              </VCardText>
            </VCard>
          </VCol>
          <VCol cols="6" md="3">
            <VCard
              border
              :color="procurementQueue.deliveries_late ? 'error' : undefined"
              :variant="procurementQueue.deliveries_late ? 'tonal' : undefined"
            >
              <VCardText>
                <div class="text-overline">{{ t('procurement_dashboard.procurement.deliveries_late') }}</div>
                <div class="text-h4">{{ procurementQueue.deliveries_late }}</div>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>

        <VCard border class="mb-4">
          <VCardTitle>{{ t('procurement_dashboard.procurement.recent_pos') }}</VCardTitle>
          <VTable density="compact">
            <thead>
              <tr>
                <th>{{ t('procurement_dashboard.table.po') }}</th>
                <th>{{ t('procurement_dashboard.table.supplier') }}</th>
                <th class="text-right">{{ t('procurement_dashboard.table.amount') }}</th>
                <th>{{ t('procurement_dashboard.table.status') }}</th>
                <th>{{ t('procurement_dashboard.table.receipt') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!procurementQueue.recent_pos.length">
                <td colspan="5" class="text-center text-medium-emphasis py-3">
                  {{ t('procurement_dashboard.my_requests.no_requests') }}
                </td>
              </tr>
              <tr
                v-for="p in procurementQueue.recent_pos"
                :key="p.id"
                style="cursor:pointer"
                @click="router.get(route('purchase-orders.show', p.uuid))"
              >
                <td class="font-weight-medium">{{ p.po_number }}</td>
                <td>{{ p.supplier || '—' }}</td>
                <td class="text-right">{{ formatCurrency(Number(p.amount)) }}</td>
                <td>
                  <VChip
                    size="x-small"
                    :color="statusColor[p.status] || 'grey'"
                    variant="tonal"
                  >
                    {{ getStatusLabel(p.status) }}
                  </VChip>
                </td>
                <td>
                  <VChip
                    size="x-small"
                    :color="statusColor[p.received_status] || 'grey'"
                    variant="tonal"
                  >
                    {{ getStatusLabel(p.received_status) }}
                  </VChip>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>

      <!-- Finance queue -->
      <VCol v-if="show('finance')" cols="12">
        <VRow class="mb-2">
          <VCol cols="6" md="3">
            <VCard border>
              <VCardText>
                <div class="text-overline">{{ t('procurement_dashboard.finance.unmatched') }}</div>
                <div class="text-h4">{{ financeQueue.unmatched }}</div>
              </VCardText>
            </VCard>
          </VCol>
          <VCol cols="6" md="3">
            <VCard
              border
              :color="financeQueue.exceptions ? 'error' : undefined"
              :variant="financeQueue.exceptions ? 'tonal' : undefined"
            >
              <VCardText>
                <div class="text-overline">{{ t('procurement_dashboard.finance.exceptions') }}</div>
                <div class="text-h4">{{ financeQueue.exceptions }}</div>
              </VCardText>
            </VCard>
          </VCol>
          <VCol cols="6" md="3">
            <VCard border>
              <VCardText>
                <div class="text-overline">{{ t('procurement_dashboard.finance.to_reconcile') }}</div>
                <div class="text-h4">{{ financeQueue.to_reconcile }}</div>
              </VCardText>
            </VCard>
          </VCol>
          <VCol cols="6" md="3">
            <VCard border>
              <VCardText>
                <div class="text-overline">{{ t('procurement_dashboard.finance.to_pay') }}</div>
                <div class="text-h4">{{ financeQueue.to_pay }}</div>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>

        <VCard border class="mb-4">
          <VCardTitle>{{ t('procurement_dashboard.finance.recent_invoices') }}</VCardTitle>
          <VTable density="compact">
            <thead>
              <tr>
                <th>{{ t('procurement_dashboard.table.invoice') }}</th>
                <th>{{ t('procurement_dashboard.table.supplier') }}</th>
                <th class="text-right">{{ t('procurement_dashboard.table.amount') }}</th>
                <th>{{ t('procurement_dashboard.table.match') }}</th>
                <th>{{ t('procurement_dashboard.table.payment') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!financeQueue.recent.length">
                <td colspan="5" class="text-center text-medium-emphasis py-3">
                  {{ t('procurement_dashboard.my_requests.no_requests') }}
                </td>
              </tr>
              <tr
                v-for="i in financeQueue.recent"
                :key="i.id"
                style="cursor:pointer"
                @click="router.get(route('invoices.show', i.id))"
              >
                <td class="font-weight-medium">{{ i.invoice_number }}</td>
                <td>{{ i.supplier || '—' }}</td>
                <td class="text-right">{{ formatCurrency(Number(i.amount)) }}</td>
                <td>
                  <VChip
                    size="x-small"
                    :color="statusColor[i.match_status] || 'grey'"
                    variant="tonal"
                  >
                    {{ getStatusLabel(i.match_status) }}
                  </VChip>
                </td>
                <td>
                  <VChip
                    size="x-small"
                    :color="statusColor[i.payment_status] || 'grey'"
                    variant="tonal"
                  >
                    {{ getStatusLabel(i.payment_status) }}
                  </VChip>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>

      <!-- Escalations -->
      <VCol v-if="show('escalations')" cols="12" md="6">
        <VCard border class="mb-4">
          <VCardTitle>{{ t('procurement_dashboard.escalations.title') }}</VCardTitle>
          <VCardText>
            <div v-if="!escalations.length" class="text-medium-emphasis text-center py-4">
              {{ t('procurement_dashboard.escalations.no_escalations') }}
            </div>
            <VList v-else density="compact">
              <VListItem
                v-for="e in escalations"
                :key="e.id"
                :title="e.reason"
                :subtitle="e.at"
                prepend-icon="tabler-alert-triangle"
              />
            </VList>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
