<script setup lang="ts">
import { computed } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Widget {
  submitted: number; pending_approval: number; approved: number
  rejected: number; paid: number; high_risk: number
  duplicates: number; policy_violations: number
}

interface DeptCost { department: string; total: number; count: number }

interface RecentClaim {
  id: number; reference_number: string; employee: string; title: string
  category: string; total_amount: number; currency: string
  status: string; status_color: string; risk_level: string | null
  claim_date: string; submitted_at: string | null
}

const props = defineProps<{
  widgets: Widget
  costByDepartment: DeptCost[]
  recentClaims: RecentClaim[]
  isAdmin: boolean
}>()

const statusColor = (s: string) => ({
  draft: 'grey', submitted: 'blue', under_review: 'warning',
  approved: 'success', rejected: 'error', paid: 'deep-purple', cancelled: 'grey',
})[s] ?? 'grey'

const riskColor = (r: string | null) => ({
  critical: 'error', high: 'deep-orange', medium: 'warning', low: 'success',
})[r ?? ''] ?? 'grey'

const widgetCards = computed(() => [
  { label: t('claims_dashboard.widgets.submitted'),        value: props.widgets.submitted,        icon: 'tabler-send',          color: 'blue' },
  { label: t('claims_dashboard.widgets.pending_approval'), value: props.widgets.pending_approval,  icon: 'tabler-clock',         color: 'warning' },
  { label: t('claims_dashboard.widgets.approved'),         value: props.widgets.approved,         icon: 'tabler-circle-check',  color: 'success' },
  { label: t('claims_dashboard.widgets.rejected'),         value: props.widgets.rejected,         icon: 'tabler-circle-x',  color: 'error' },
  { label: t('claims_dashboard.widgets.paid'),             value: props.widgets.paid,             icon: 'tabler-cash-banknote',            color: 'deep-purple' },
  { label: t('claims_dashboard.widgets.high_risk'),        value: props.widgets.high_risk,        icon: 'tabler-alert-circle',  color: 'deep-orange', adminOnly: true },
  { label: t('claims_dashboard.widgets.duplicates'),       value: props.widgets.duplicates,       icon: 'tabler-copy',          color: 'orange', adminOnly: true },
  { label: t('claims_dashboard.widgets.policy_violations'),value: props.widgets.policy_violations, icon: 'tabler-shield-exclamation',  color: 'red', adminOnly: true },
])

const fmt = (n: number) => 'R ' + n.toLocaleString('en-ZA', { minimumFractionDigits: 2 })
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-5 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('claims_dashboard.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('claims_dashboard.description') }}</p>
      </div>
      <div class="d-flex ga-2 flex-wrap">
        <VBtn v-if="isAdmin" variant="outlined" prepend-icon="tabler-chart-bar" :href="route('claims.executive')">
          {{ t('claims_dashboard.executive') }}
        </VBtn>
        <VBtn v-if="isAdmin" variant="outlined" prepend-icon="tabler-chart-bar" :href="route('claims.reports.index')">
          {{ t('claims_dashboard.reports') }}
        </VBtn>
        <VBtn v-if="isAdmin" variant="outlined" prepend-icon="tabler-robot" :href="route('claims.ai.index')">
          {{ t('claims_dashboard.ai_intelligence') }}
        </VBtn>
        <VBtn v-if="isAdmin" variant="outlined" prepend-icon="tabler-rosette-discount-check" :href="route('claims.approvals.index')">
          {{ t('claims_dashboard.approvals') }}
        </VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" :href="route('claims.my.create')">
          {{ t('claims_dashboard.new_claim') }}
        </VBtn>
      </div>
    </div>

    <!-- Widgets -->
    <VRow dense class="mb-4">
      <VCol v-for="widget in widgetCards.filter(card => !card.adminOnly || isAdmin)" :key="widget.label" cols="6" sm="4" md="3" lg="2">
        <VCard variant="tonal" :color="widget.color" class="pa-3 text-center" elevation="0">
          <VIcon :color="widget.color" size="28" class="mb-1">{{ widget.icon }}</VIcon>
          <div class="text-h5 font-weight-bold">{{ widget.value }}</div>
          <div class="text-caption text-medium-emphasis">{{ widget.label }}</div>
        </VCard>
      </VCol>
    </VRow>

    <VRow>
      <!-- Cost by Department -->
      <VCol v-if="isAdmin && costByDepartment.length" cols="12" md="5">
        <VCard class="mb-4">
          <VCardTitle class="pa-4 text-body-1 font-weight-bold d-flex align-center ga-2">
            <VIcon color="primary">tabler-building</VIcon> {{ t('claims_dashboard.cost_by_department.title') }}
          </VCardTitle>
          <VDivider />
          <VList density="compact">
            <VListItem v-for="dept in costByDepartment" :key="dept.department">
              <template #prepend>
                <VIcon color="primary" size="18">tabler-building</VIcon>
              </template>
              <VListItemTitle class="text-body-2">{{ dept.department }}</VListItemTitle>
              <template #append>
                <div class="text-right">
                  <div class="text-body-2 font-weight-bold">{{ fmt(dept.total) }}</div>
                  <div class="text-caption text-medium-emphasis">{{ t('claims_dashboard.cost_by_department.claims', { count: dept.count }) }}</div>
                </div>
              </template>
            </VListItem>
          </VList>
        </VCard>
      </VCol>

      <!-- Recent Claims -->
      <VCol cols="12" :md="isAdmin && costByDepartment.length ? 7 : 12">
        <VCard>
          <VCardTitle class="pa-4 text-body-1 font-weight-bold d-flex align-center justify-space-between">
            <div class="d-flex align-center ga-2">
              <VIcon color="primary">tabler-receipt</VIcon> {{ t('claims_dashboard.recent_claims.title') }}
            </div>
            <VBtn variant="text" size="small" :href="route('claims.my.index')">{{ t('claims_dashboard.recent_claims.view_all') }}</VBtn>
          </VCardTitle>
          <VDivider />
          <VDataTable
            :items="recentClaims"
            :headers="[
              { title: t('claims_dashboard.recent_claims.table.ref'), key: 'reference_number' },
              { title: t('claims_dashboard.recent_claims.table.employee'), key: 'employee' },
              { title: t('claims_dashboard.recent_claims.table.title'), key: 'title' },
              { title: t('claims_dashboard.recent_claims.table.amount'), key: 'total_amount' },
              { title: t('claims_dashboard.recent_claims.table.status'), key: 'status' },
              { title: t('claims_dashboard.recent_claims.table.risk'), key: 'risk_level' },
              { title: t('claims_dashboard.recent_claims.table.date'), key: 'claim_date' },
            ]"
            density="compact"
            :items-per-page="10"
            hide-default-footer
          >
            <template #item.total_amount="{ item }">
              <span class="font-weight-medium">{{ fmt(item.total_amount) }}</span>
            </template>
            <template #item.status="{ item }">
              <VChip :color="statusColor(item.status)" size="x-small" variant="tonal">{{ item.status }}</VChip>
            </template>
            <template #item.risk_level="{ item }">
              <VChip v-if="item.risk_level" :color="riskColor(item.risk_level)" size="x-small" variant="tonal">{{ item.risk_level }}</VChip>
              <span v-else class="text-medium-emphasis">—</span>
            </template>
            <template #item.reference_number="{ item }">
              <a :href="route('claims.my.show', { claim: item.id })" class="text-primary text-decoration-none font-weight-medium">{{ item.reference_number }}</a>
            </template>
          </VDataTable>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
