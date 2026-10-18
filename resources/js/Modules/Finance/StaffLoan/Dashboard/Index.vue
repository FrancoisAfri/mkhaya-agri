<script setup lang="ts">
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Stat { label: string; value: string | number; icon: string; color: string }
interface LoanRow { id: number; uuid: string; reference_number: string; employee: string; amount_requested: number; term_months: number; status: string; risk_level: string; created_at: string }
interface AdvanceRow { id: number; uuid: string; reference_number: string; employee: string; amount_requested: number; percentage_of_net: number; status: string; created_at: string }
interface Violation { id: number; type: string; description: string; severity: string; employee: string; detected_at: string }

defineProps<{
  stats: Stat[]
  recentLoans: LoanRow[]
  recentAdvances: AdvanceRow[]
  violations: Violation[]
  totalOutstanding: number
}>()

const statusColor = (s: string) => ({ pending: 'warning', approved: 'success', rejected: 'error', active: 'primary', closed: 'grey', defaulted: 'error', under_review: 'info', disbursed: 'teal', settled: 'grey' }[s] ?? 'grey')
const riskColor   = (r: string) => ({ low: 'success', medium: 'warning', high: 'error', critical: 'error' }[r] ?? 'grey')
const sevColor    = (s: string) => ({ low: 'info', medium: 'warning', high: 'error', critical: 'error' }[s] ?? 'grey')
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('staff_loans_dashboard.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">
          {{ t('staff_loans_dashboard.total_outstanding', { amount: totalOutstanding.toLocaleString() }) }}
        </p>
      </div>
      <div class="d-flex ga-2">
        <VBtn color="primary" prepend-icon="tabler-building-bank" :href="route('staff-loans.loans.create')">
          {{ t('staff_loans_dashboard.new_loan') }}
        </VBtn>
        <VBtn color="info" variant="outlined" prepend-icon="tabler-cash" :href="route('staff-loans.advances.create')">
          {{ t('staff_loans_dashboard.new_advance') }}
        </VBtn>
      </div>
    </div>

    <!-- Stats -->
    <VRow class="mb-4">
      <VCol v-for="stat in stats" :key="stat.label" cols="12" sm="6" md="4" lg="2">
        <VCard elevation="0" border>
          <VCardText class="pa-3">
            <div class="d-flex align-center justify-space-between mb-1">
              <VIcon :color="stat.color" size="22">{{ stat.icon }}</VIcon>
              <span class="text-h6 font-weight-bold">{{ stat.value }}</span>
            </div>
            <p class="text-caption text-medium-emphasis mb-0">{{ stat.label }}</p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow>
      <!-- Recent Loans -->
      <VCol cols="12" md="6">
        <VCard elevation="0" border>
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold d-flex align-center justify-space-between">
            <span><VIcon class="mr-1">tabler-building-bank</VIcon> {{ t('staff_loans_dashboard.recent_loans.title') }}</span>
            <VBtn size="x-small" variant="text" :href="route('staff-loans.loans.index')">{{ t('staff_loans_dashboard.recent_loans.view_all') }}</VBtn>
          </VCardTitle>
          <VDivider />
          <VList density="compact">
            <VListItem v-for="loan in recentLoans" :key="loan.id" :href="route('staff-loans.loans.show', loan.uuid)">
              <template #prepend>
                <VIcon size="18" color="primary" class="mr-2">tabler-building-bank</VIcon>
              </template>
              <VListItemTitle class="text-body-2 font-weight-medium">{{ loan.reference_number }}</VListItemTitle>
              <VListItemSubtitle>{{ loan.employee }} · R{{ Number(loan.amount_requested).toLocaleString() }} · {{ loan.term_months }}m</VListItemSubtitle>
              <template #append>
                <div class="d-flex align-center ga-1">
                  <VChip v-if="loan.risk_level" size="x-small" :color="riskColor(loan.risk_level)" variant="tonal">{{ loan.risk_level }}</VChip>
                  <VChip size="x-small" :color="statusColor(loan.status)" variant="tonal">{{ loan.status }}</VChip>
                </div>
              </template>
            </VListItem>
          </VList>
        </VCard>
      </VCol>

      <!-- Recent Advances -->
      <VCol cols="12" md="6">
        <VCard elevation="0" border>
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold d-flex align-center justify-space-between">
            <span><VIcon class="mr-1">tabler-cash</VIcon> {{ t('staff_loans_dashboard.recent_advances.title') }}</span>
            <VBtn size="x-small" variant="text" :href="route('staff-loans.advances.index')">{{ t('staff_loans_dashboard.recent_advances.view_all') }}</VBtn>
          </VCardTitle>
          <VDivider />
          <VList density="compact">
            <VListItem v-for="advance in recentAdvances" :key="advance.id" :href="route('staff-loans.advances.show', advance.uuid)">
              <template #prepend><VIcon size="18" color="info" class="mr-2">tabler-cash</VIcon></template>
              <VListItemTitle class="text-body-2 font-weight-medium">{{ advance.reference_number }}</VListItemTitle>
              <VListItemSubtitle>
                {{ advance.employee }} · R{{ Number(advance.amount_requested).toLocaleString() }}
                <span v-if="advance.percentage_of_net"> ({{ t('staff_loans_dashboard.recent_advances.percentage_of_net', { pct: advance.percentage_of_net }) }})</span>
              </VListItemSubtitle>
              <template #append>
                <VChip size="x-small" :color="statusColor(advance.status)" variant="tonal">{{ advance.status }}</VChip>
              </template>
            </VListItem>
          </VList>
        </VCard>
      </VCol>

      <!-- Open Violations -->
      <VCol v-if="violations.length" cols="12">
        <VCard elevation="0" border>
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold text-error">
            <VIcon class="mr-1">tabler-shield-exclamation</VIcon> {{ t('staff_loans_dashboard.open_violations.title') }}
          </VCardTitle>
          <VDivider />
          <VList density="compact">
            <VListItem v-for="violation in violations" :key="violation.id">
              <template #prepend>
                <VIcon :color="sevColor(violation.severity)" size="18" class="mr-2">tabler-alert-circle</VIcon>
              </template>
              <VListItemTitle class="text-body-2">{{ violation.description }}</VListItemTitle>
              <VListItemSubtitle>{{ violation.employee }}</VListItemSubtitle>
              <template #append>
                <VChip size="x-small" :color="sevColor(violation.severity)" variant="tonal">{{ violation.severity }}</VChip>
              </template>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
