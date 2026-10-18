<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Stats {
  policy_violations: number
  anomalies: number
  liabilities: number
  burnout_risks: number
  compliance_alerts: number
}
interface Violation {
  id: number; policy_name: string; rule_name: string; severity: string
  status: string; employee: string; leave_type: string; detected_at: string; resolved_at: string | null
}
interface Anomaly {
  id: number; employee_name: string; anomaly_type: string; severity: string
  confidence: number | null; detected_at: string; status: string
}
interface Liability {
  employee_name: string; leave_type: string; balance_hours: number; balance_days: number
}
interface BurnoutRisk {
  id: number; employee_name: string; risk_score: number | null; leave_frequency: string | number
  days_used: string | number; sick_days: string | number; flagged_at: string; reviewed: boolean
}
interface ComplianceAlert {
  id: number; rule_code: string; detail: string; request_number: string | null
  employee_name: string; ai_explanation: string | null; created_at: string
}

const props = withDefaults(defineProps<{
  stats: Stats; violations: Violation[]; anomalies: Anomaly[]
  liabilities: Liability[]; burnoutRisks: BurnoutRisk[]; complianceAlerts: ComplianceAlert[]
}>(), {
  stats: () => ({ policy_violations: 0, anomalies: 0, liabilities: 0, burnout_risks: 0, compliance_alerts: 0 }),
  violations: () => [], anomalies: () => [], liabilities: () => [], burnoutRisks: () => [], complianceAlerts: () => [],
})

type TabKey = 'violations' | 'anomalies' | 'liabilities' | 'burnout' | 'compliance'
const activeTab = ref<TabKey>('violations')

const tabs = computed(() => [
  { key: 'violations' as TabKey,  label: t('leave.policy'),              statKey: 'policy_violations' as keyof Stats, color: 'error',       icon: 'ri-shield-cross-line' },
  { key: 'anomalies' as TabKey,   label: t('leave.anomalies_tab'),       statKey: 'anomalies'        as keyof Stats, color: 'warning',     icon: 'ri-alert-line' },
  { key: 'liabilities' as TabKey, label: t('leave.liabilities'),         statKey: 'liabilities'      as keyof Stats, color: 'info',        icon: 'ri-money-dollar-circle-line' },
  { key: 'burnout' as TabKey,     label: t('leave.burnout_indicators'),  statKey: 'burnout_risks'    as keyof Stats, color: 'deep-orange', icon: 'ri-fire-line' },
  { key: 'compliance' as TabKey,  label: t('leave.compliance_alerts_tab'), statKey: 'compliance_alerts' as keyof Stats, color: 'purple',      icon: 'ri-file-warning-line' },
])

const severityColor = (s: string) => ({ critical: 'error', high: 'error', medium: 'warning', low: 'success' }[s] ?? 'default')
const statusColor   = (s: string) => s === 'open' ? 'error' : s === 'resolved' ? 'success' : 'default'

const violationHeaders = computed(() => [
  { title: t('leave.employee_label'), key: 'employee' },
  { title: t('leave.policy'),         key: 'policy_name' },
  { title: t('leave.rule'),           key: 'rule_name' },
  { title: t('leave.leave_type'),     key: 'leave_type' },
  { title: t('leave.severity'),       key: 'severity' },
  { title: t('leave.status'),         key: 'status' },
  { title: t('leave.detected'),       key: 'detected_at' },
])
const anomalyHeaders = computed(() => [
  { title: t('leave.employee_label'), key: 'employee_name' },
  { title: t('leave.type'),           key: 'anomaly_type' },
  { title: t('leave.severity'),       key: 'severity' },
  { title: t('leave.confidence'),     key: 'confidence' },
  { title: t('leave.detected'),       key: 'detected_at' },
  { title: t('leave.status'),         key: 'status' },
])
const liabilityHeaders = computed(() => [
  { title: t('leave.employee_label'), key: 'employee_name' },
  { title: t('leave.leave_type'),     key: 'leave_type' },
  { title: t('leave.balance_days'),   key: 'balance_days' },
])
const burnoutHeaders = computed(() => [
  { title: t('leave.employee_label'), key: 'employee_name' },
  { title: t('leave.risk_score'),     key: 'risk_score' },
  { title: t('leave.leave_freq'),     key: 'leave_frequency' },
  { title: t('leave.days_used'),      key: 'days_used' },
  { title: t('leave.sick_days'),      key: 'sick_days' },
  { title: t('leave.flagged'),        key: 'flagged_at' },
  { title: t('leave.reviewed'),       key: 'reviewed' },
])
const complianceHeaders = computed(() => [
  { title: t('leave.rule_code'),      key: 'rule_code' },
  { title: t('leave.employee_label'), key: 'employee_name' },
  { title: t('leave.ref'),            key: 'request_number' },
  { title: t('leave.detail'),         key: 'detail' },
  { title: t('leave.detected'),       key: 'created_at' },
])
</script>

<template>
  <VContainer fluid class="pa-6">

    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6 gap-3 flex-wrap">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('leave.hr_dashboard') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">{{ t('leave_hr_dashboard.subtitle') }}</p>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <VBtn variant="tonal" color="error" prepend-icon="tabler-shield-alert" @click="router.visit('/leave-management/policy-violations')">
          {{ t('leave.policy') }}
        </VBtn>
        <VBtn variant="tonal" prepend-icon="tabler-account-arrow-right" @click="router.visit('/leave-management/delegations')">
          {{ t('leave.delegations') }}
        </VBtn>
        <VBtn variant="tonal" color="success" prepend-icon="tabler-cash-plus" href="/leave-management/payouts">
          {{ t('leave.leave_payouts') }}
        </VBtn>
      </div>
    </div>

    <!-- Stat cards -->
    <VRow class="mb-6">
      <VCol v-for="tab in tabs" :key="tab.key" cols="12" sm="6" md="4" lg="" class="flex-grow-1">
        <VCard
          :color="activeTab === tab.key ? tab.color : undefined"
          :variant="activeTab === tab.key ? 'tonal' : 'outlined'"
          class="cursor-pointer h-100"
          @click="activeTab = tab.key"
        >
          <VCardText class="pa-4">
            <div class="d-flex align-center justify-space-between">
              <div>
                <p class="text-caption text-medium-emphasis mb-1">{{ tab.label }}</p>
                <p class="text-h4 font-weight-bold">{{ props.stats[tab.statKey] }}</p>
              </div>
              <VIcon :color="tab.color" size="36">{{ tab.icon }}</VIcon>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Policy Violations -->
    <VCard v-if="activeTab === 'violations'" variant="outlined">
      <VCardTitle class="pa-4 pb-2 d-flex align-center gap-2">
        <VIcon color="error">ri-shield-cross-line</VIcon>
        {{ t('leave.policy') }}
        <VChip size="x-small" color="error" class="ms-1">{{ props.violations.length }}</VChip>
      </VCardTitle>
      <VCardText class="pa-0">
        <VDataTable :headers="violationHeaders" :items="props.violations" :items-per-page="15" density="compact" hover>
          <template #item.severity="{ item }">
            <VChip :color="severityColor(item.severity)" size="x-small" variant="tonal">{{ item.severity }}</VChip>
          </template>
          <template #item.status="{ item }">
            <VChip :color="statusColor(item.status)" size="x-small" variant="tonal">{{ item.status }}</VChip>
          </template>
          <template #no-data>
            <div class="pa-6 text-center text-medium-emphasis">{{ t('leave_hr_dashboard.no_violations') }}</div>
          </template>
        </VDataTable>
      </VCardText>
    </VCard>

    <!-- Anomalies -->
    <VCard v-if="activeTab === 'anomalies'" variant="outlined">
      <VCardTitle class="pa-4 pb-2 d-flex align-center gap-2">
        <VIcon color="warning">ri-alert-line</VIcon>
        {{ t('leave.anomalies_tab') }}
        <VChip size="x-small" color="warning" class="ms-1">{{ props.anomalies.length }}</VChip>
      </VCardTitle>
      <VCardText class="pa-0">
        <VDataTable :headers="anomalyHeaders" :items="props.anomalies" :items-per-page="15" density="compact" hover>
          <template #item.severity="{ item }">
            <VChip :color="severityColor(item.severity)" size="x-small" variant="tonal">{{ item.severity }}</VChip>
          </template>
          <template #item.confidence="{ item }">
            {{ item.confidence != null ? t('leave_hr_dashboard.confidence_format', { pct: Math.round(item.confidence * 100) }) : '—' }}
          </template>
          <template #item.status="{ item }">
            <VChip :color="statusColor(item.status)" size="x-small" variant="tonal">{{ item.status }}</VChip>
          </template>
          <template #no-data>
            <div class="pa-6 text-center text-medium-emphasis">{{ t('leave_hr_dashboard.no_anomalies') }}</div>
          </template>
        </VDataTable>
      </VCardText>
    </VCard>

    <!-- Liabilities -->
    <VCard v-if="activeTab === 'liabilities'" variant="outlined">
      <VCardTitle class="pa-4 pb-2 d-flex align-center gap-2">
        <VIcon color="info">ri-money-dollar-circle-line</VIcon>
        {{ t('leave.liabilities') }}
        <VChip size="x-small" color="info" class="ms-1">{{ props.liabilities.length }}</VChip>
      </VCardTitle>
      <VCardText class="pa-0">
        <VDataTable :headers="liabilityHeaders" :items="props.liabilities" :items-per-page="15" density="compact" hover>
          <template #item.balance_days="{ item }">
            {{ item.balance_days }} {{ t('leave_hr_dashboard.days_suffix') }}
          </template>
          <template #no-data>
            <div class="pa-6 text-center text-medium-emphasis">{{ t('leave_hr_dashboard.no_liabilities') }}</div>
          </template>
        </VDataTable>
      </VCardText>
    </VCard>

    <!-- Burnout Indicators -->
    <VCard v-if="activeTab === 'burnout'" variant="outlined">
      <VCardTitle class="pa-4 pb-2 d-flex align-center gap-2">
        <VIcon color="deep-orange">ri-fire-line</VIcon>
        {{ t('leave.burnout_indicators') }}
        <VChip size="x-small" color="deep-orange" class="ms-1">{{ props.burnoutRisks.length }}</VChip>
      </VCardTitle>
      <VCardText class="pa-0">
        <VDataTable :headers="burnoutHeaders" :items="props.burnoutRisks" :items-per-page="15" density="compact" hover>
          <template #item.risk_score="{ item }">
            <template v-if="item.risk_score != null">
              <VChip
                :color="item.risk_score >= 0.7 ? 'error' : item.risk_score >= 0.4 ? 'warning' : 'success'"
                size="x-small" variant="tonal"
              >{{ t('leave_hr_dashboard.risk_score_format', { pct: Math.round(item.risk_score * 100) }) }}</VChip>
            </template>
            <template v-else>—</template>
          </template>
          <template #item.reviewed="{ item }">
            <VIcon :color="item.reviewed ? 'success' : 'error'" size="18">
              {{ item.reviewed ? 'ri-checkbox-circle-line' : 'ri-close-circle-line' }}
            </VIcon>
          </template>
          <template #no-data>
            <div class="pa-6 text-center text-medium-emphasis">{{ t('leave_hr_dashboard.no_burnout') }}</div>
          </template>
        </VDataTable>
      </VCardText>
    </VCard>

    <!-- Compliance Alerts -->
    <VCard v-if="activeTab === 'compliance'" variant="outlined">
      <VCardTitle class="pa-4 pb-2 d-flex align-center gap-2">
        <VIcon color="purple">ri-file-warning-line</VIcon>
        {{ t('leave.compliance_alerts_tab') }}
        <VChip size="x-small" color="purple" class="ms-1">{{ props.complianceAlerts.length }}</VChip>
      </VCardTitle>
      <VCardText class="pa-0">
        <VDataTable :headers="complianceHeaders" :items="props.complianceAlerts" :items-per-page="15" density="compact" hover>
          <template #item.detail="{ item }">
            <span class="text-caption">{{ item.detail }}</span>
          </template>
          <template #item.request_number="{ item }">
            {{ item.request_number ?? '—' }}
          </template>
          <template #no-data>
            <div class="pa-6 text-center text-medium-emphasis">{{ t('leave_hr_dashboard.no_compliance') }}</div>
          </template>
        </VDataTable>
      </VCardText>
    </VCard>

  </VContainer>
</template>
