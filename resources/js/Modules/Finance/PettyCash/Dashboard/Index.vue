<script setup lang="ts">
import { computed } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Stats {
  active_floats: number
  total_cash_on_hand: number
  total_float_limit: number
  cash_issued_today: number
  cash_spent_this_month: number
  pending_requests: number
  pending_reconciliations: number
  float_shortages: number
  missing_receipts: number
  open_violations: number
  high_risk_transactions: number
  replenishment_requests: number
}

interface RecentRequest {
  id: number; uuid: string; reference_number: string
  requestor: string; float_name: string; purpose: string
  amount: number; status: string; requested_at: string | null
}

interface FloatSummary {
  id: number; uuid: string; name: string; custodian: string
  current_balance: number; float_limit: number; utilization_pct: number
  needs_replenishment: boolean; status: string
}

interface Violation {
  id: number; type: string; description: string; severity: string; detected_at: string
}

const props = defineProps<{
  stats: Stats
  recentRequests: RecentRequest[]
  floatSummary: FloatSummary[]
  violations: Violation[]
}>()

const fmt = (n: number) => 'R ' + Number(n).toLocaleString('en-ZA', { minimumFractionDigits: 2 })

const statusColor = (s: string) => ({
  draft: 'grey', pending: 'blue', approved: 'teal', rejected: 'error',
  issued: 'orange', settled: 'success', cancelled: 'grey',
})[s] ?? 'grey'

const severityColor = (s: string) => ({
  low: 'success', medium: 'warning', high: 'deep-orange', critical: 'error',
})[s] ?? 'grey'

const utilizationColor = (pct: number) => pct >= 90 ? 'error' : pct >= 70 ? 'warning' : 'success'

const widgetCards = computed(() => [
  { label: t('petty_cash_dashboard.widgets.active_floats'),     value: props.stats.active_floats,         icon: 'tabler-cash',          color: 'primary' },
  { label: t('petty_cash_dashboard.widgets.cash_on_hand'),      value: fmt(props.stats.total_cash_on_hand),icon: 'tabler-safe',    color: 'success' },
  { label: t('petty_cash_dashboard.widgets.issued_today'),      value: fmt(props.stats.cash_issued_today), icon: 'tabler-cash',              color: 'blue' },
  { label: t('petty_cash_dashboard.widgets.spent_this_month'),  value: fmt(props.stats.cash_spent_this_month), icon: 'tabler-receipt', color: 'indigo' },
  { label: t('petty_cash_dashboard.widgets.pending_requests'),  value: props.stats.pending_requests,       icon: 'tabler-clock',          color: 'warning' },
  { label: t('petty_cash_dashboard.widgets.pending_recons'),    value: props.stats.pending_reconciliations,icon: 'tabler-clipboard-check',color: 'orange' },
  { label: t('petty_cash_dashboard.widgets.float_shortages'),   value: props.stats.float_shortages,        icon: 'tabler-battery',            color: 'error' },
  { label: t('petty_cash_dashboard.widgets.missing_receipts'),  value: props.stats.missing_receipts,       icon: 'tabler-file-exclamation',    color: 'deep-orange' },
  { label: t('petty_cash_dashboard.widgets.open_violations'),   value: props.stats.open_violations,        icon: 'tabler-shield-exclamation',   color: 'red' },
  { label: t('petty_cash_dashboard.widgets.high_risk_txns'),    value: props.stats.high_risk_transactions, icon: 'tabler-alert-circle',   color: 'red-darken-3' },
])
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-5 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('petty_cash_dashboard.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('petty_cash_dashboard.description') }}</p>
      </div>
      <div class="d-flex ga-2 flex-wrap">
        <VBtn variant="outlined" prepend-icon="tabler-cash" :href="route('petty-cash.floats.index')">
          {{ t('petty_cash_dashboard.floats') }}
        </VBtn>
        <VBtn variant="outlined" prepend-icon="tabler-file-plus" :href="route('petty-cash.requests.create')">
          {{ t('petty_cash_dashboard.new_request') }}
        </VBtn>
        <VBtn variant="outlined" prepend-icon="tabler-chart-bar" :href="route('petty-cash.reports.index')">
          {{ t('petty_cash_dashboard.reports') }}
        </VBtn>
      </div>
    </div>

    <!-- Stat Widgets -->
    <VRow class="mb-4">
      <VCol v-for="card in widgetCards" :key="card.label" cols="12" sm="6" md="4" lg="3" xl="2">
        <VCard elevation="0" border>
          <VCardText class="pa-4">
            <div class="d-flex align-center justify-space-between mb-2">
              <VIcon :color="card.color" size="28">{{ card.icon }}</VIcon>
            </div>
            <div class="text-h6 font-weight-bold">{{ card.value }}</div>
            <div class="text-caption text-medium-emphasis mt-1">{{ card.label }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow>
      <!-- Float Summary -->
      <VCol cols="12" md="6">
        <VCard elevation="0" border>
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">
            <VIcon class="mr-2">tabler-cash</VIcon> {{ t('petty_cash_dashboard.float_status.title') }}
          </VCardTitle>
          <VDivider />
          <VList lines="two" density="compact">
            <VListItem
              v-for="float in floatSummary"
              :key="float.id"
              :href="route('petty-cash.floats.show', float.uuid)"
            >
              <template #prepend>
                <VIcon :color="float.needs_replenishment ? 'error' : 'success'" class="mr-2">
                  {{ float.needs_replenishment ? 'tabler-battery' : 'tabler-battery-2' }}
                </VIcon>
              </template>
              <VListItemTitle>{{ float.name }}</VListItemTitle>
              <VListItemSubtitle>
                {{ t('petty_cash_dashboard.float_status.custodian', { custodian: float.custodian }) }} · {{ t('petty_cash_dashboard.float_status.balance', { balance: fmt(float.current_balance) }) }}
              </VListItemSubtitle>
              <template #append>
                <div class="text-right">
                  <VChip :color="utilizationColor(float.utilization_pct)" size="small" variant="tonal">
                    {{ t('petty_cash_dashboard.float_status.used', { pct: float.utilization_pct }) }}
                  </VChip>
                </div>
              </template>
            </VListItem>
            <VListItem v-if="!floatSummary.length">
              <VListItemTitle class="text-medium-emphasis text-body-2">{{ t('petty_cash_dashboard.float_status.no_active_floats') }}</VListItemTitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>

      <!-- Recent Requests -->
      <VCol cols="12" md="6">
        <VCard elevation="0" border>
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold d-flex justify-space-between align-center">
            <span><VIcon class="mr-2">tabler-clock</VIcon>{{ t('petty_cash_dashboard.pending_requests.title') }}</span>
            <VBtn size="small" variant="text" :href="route('petty-cash.requests.index')">{{ t('petty_cash_dashboard.pending_requests.view_all') }}</VBtn>
          </VCardTitle>
          <VDivider />
          <VList lines="two" density="compact">
            <VListItem
              v-for="request in recentRequests"
              :key="request.id"
              :href="route('petty-cash.requests.show', request.uuid)"
            >
              <VListItemTitle>{{ request.reference_number }} — {{ request.purpose }}</VListItemTitle>
              <VListItemSubtitle>{{ request.requestor }} · {{ request.float_name }}</VListItemSubtitle>
              <template #append>
                <div class="text-right">
                  <div class="text-body-2 font-weight-semibold">{{ fmt(request.amount) }}</div>
                  <VChip :color="statusColor(request.status)" size="x-small" variant="tonal">{{ request.status }}</VChip>
                </div>
              </template>
            </VListItem>
            <VListItem v-if="!recentRequests.length">
              <VListItemTitle class="text-medium-emphasis text-body-2">{{ t('petty_cash_dashboard.pending_requests.no_pending') }}</VListItemTitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>

      <!-- Policy Violations -->
      <VCol cols="12" v-if="violations.length">
        <VCard elevation="0" border>
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold d-flex justify-space-between align-center">
            <span><VIcon class="mr-2" color="error">tabler-shield-exclamation</VIcon>{{ t('petty_cash_dashboard.open_violations.title') }}</span>
            <VBtn size="small" variant="text" :href="route('petty-cash.settings.violations')">{{ t('petty_cash_dashboard.open_violations.view_all') }}</VBtn>
          </VCardTitle>
          <VDivider />
          <VList density="compact">
            <VListItem v-for="violation in violations" :key="violation.id">
              <template #prepend>
                <VChip :color="severityColor(violation.severity)" size="x-small" variant="flat" class="mr-2">{{ violation.severity }}</VChip>
              </template>
              <VListItemTitle class="text-body-2">{{ violation.description }}</VListItemTitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
