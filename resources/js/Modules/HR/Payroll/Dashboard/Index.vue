<script setup lang="ts">
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Stats {
  cost_this_month: number; cost_last_month: number
  mom_variance: number; mom_variance_pct: number | null
  risk_score: string; leakage_estimate: number
  overtime_curr: number; overtime_prev: number; overtime_diff: number
  leave_liability: number; high_risk_employees: number; high_risk_depts: number
  compliance_alerts: number; pending_approvals: number; ai_summary: string | null
  employees_paid: number; open_anomalies: number; avg_net_pay: number
}
interface Run {
  id: number; uuid: string; ref_number: string; name: string
  period_start: string; period_end: string; total_net: number
  employee_count: number; status: string; ai_risk_level: string | null
}
interface Anomaly {
  id: number; anomaly_type: string; severity: string; status: string; detected_at: string
}
interface CostMonth { month: string; label: string; total_net: number; total_gross: number }
interface ComplianceDash {
  total_open: number; critical: number
  by_type: { type: string; count: number; critical: number }[]
}
interface RiskDash {
  risk_score: string; total_open: number
  breakdown: { critical: number; high: number; medium: number; low: number }
  high_risk_depts: { department: string; employee_count: number; anomaly_count: number }[]
}
interface VarianceDash {
  has_data: boolean; net_diff: number
  curr_run?: { ref_number: string; period_start: string }
  prev_run?: { ref_number: string; period_start: string }
  by_dept: { department: string; curr: number; prev: number; diff: number; pct: number | null }[]
}

defineProps<{
  stats:       Stats
  recentRuns:  Run[]
  recentAnomalies: Anomaly[]
  costTrend:   CostMonth[]
  ytdTotal:    number
  compliance:  ComplianceDash
  risk:        RiskDash
  variance:    VarianceDash
}>()

const fmt  = (n: number) => 'R ' + new Intl.NumberFormat('en-ZA', { minimumFractionDigits: 2 }).format(n)
const fmtK = (n: number) => {
  if (n >= 1_000_000) return 'R ' + (n / 1_000_000).toFixed(1) + 'M'
  if (n >= 1_000)     return 'R ' + (n / 1_000).toFixed(1) + 'K'
  return 'R ' + Math.abs(n).toFixed(0)
}
const riskColor    = (r: string) => ({ low: 'success', medium: 'warning', high: 'error', critical: 'purple' }[r] ?? 'grey')
const sevColor     = (s: string) => ({ low: 'info', medium: 'warning', high: 'error', critical: 'purple' }[s] ?? 'grey')
const statusColor  = (s: string) => ({ draft: 'grey', processing: 'info', review: 'warning', released: 'success', cancelled: 'error' }[s] ?? 'grey')
const varColor     = (v: number | null) => v == null ? 'grey' : v > 5 ? 'error' : v < -5 ? 'info' : 'success'
const compLabel    = (type: string) => ({
  overtime_law_violation: 'Overtime', minimum_wage_violation: 'Min. Wage',
  leave_compliance_issue: 'Leave',    contract_payroll_mismatch: 'Contract',
  tax_compliance_issue: 'PAYE',       overtime_rate_violation: 'Overtime rate',
}[type] ?? type.replace(/_/g, ' '))
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-5 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('payroll_executive.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('payroll_executive.description') }}</p>
      </div>
      <div class="d-flex ga-2 flex-wrap">
        <VBtn color="primary" prepend-icon="tabler-plus" :href="route('payroll.runs.create')">{{ t('payroll_executive.new_run') }}</VBtn>
        <VBtn variant="outlined" prepend-icon="tabler-shield-check" :href="route('payroll.compliance.index')">{{ t('payroll_executive.compliance') }}</VBtn>
        <VBtn variant="outlined" prepend-icon="tabler-robot" :href="route('payroll.ai-assistant.index')">{{ t('payroll_executive.ai_assistant') }}</VBtn>
      </div>
    </div>

    <VRow class="mb-3">
      <VCol cols="6" sm="4" md="2">
        <VCard elevation="0" border height="90">
          <VCardText class="pa-3 d-flex flex-column justify-space-between h-100">
            <div class="d-flex align-center justify-space-between">
              <VIcon color="primary" size="18">tabler-cash</VIcon>
              <VChip v-if="stats.mom_variance_pct != null" size="x-small" :color="varColor(stats.mom_variance_pct)" variant="tonal">
                {{ stats.mom_variance_pct >= 0 ? '+' : '' }}{{ stats.mom_variance_pct }}%
              </VChip>
            </div>
            <div>
              <div class="text-body-1 font-weight-bold text-primary">{{ fmtK(stats.cost_this_month) }}</div>
              <div class="text-caption text-medium-emphasis">{{ t('payroll_executive.kpis.payroll_cost_mtd') }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="6" sm="4" md="2">
        <VCard elevation="0" border height="90">
          <VCardText class="pa-3 d-flex flex-column justify-space-between h-100">
            <VIcon :color="riskColor(stats.risk_score)" size="18">tabler-shield-exclamation</VIcon>
            <div>
              <div class="text-body-1 font-weight-bold" :class="`text-${riskColor(stats.risk_score)}`" style="text-transform:capitalize">{{ stats.risk_score }}</div>
              <div class="text-caption text-medium-emphasis">{{ t('payroll_executive.kpis.risk_score') }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="6" sm="4" md="2">
        <VCard elevation="0" border height="90">
          <VCardText class="pa-3 d-flex flex-column justify-space-between h-100">
            <VIcon color="warning" size="18">tabler-droplet</VIcon>
            <div>
              <div class="text-body-1 font-weight-bold text-warning">{{ fmtK(stats.leakage_estimate) }}</div>
              <div class="text-caption text-medium-emphasis">{{ t('payroll_executive.kpis.est_leakage') }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="6" sm="4" md="2">
        <VCard elevation="0" border height="90">
          <VCardText class="pa-3 d-flex flex-column justify-space-between h-100">
            <VIcon :color="stats.pending_approvals > 0 ? 'warning' : 'success'" size="18">tabler-rosette-discount-check</VIcon>
            <div>
              <div class="text-body-1 font-weight-bold" :class="stats.pending_approvals > 0 ? 'text-warning' : 'text-success'">{{ stats.pending_approvals }}</div>
              <div class="text-caption text-medium-emphasis">{{ t('payroll_executive.kpis.pending_approvals') }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="6" sm="4" md="2">
        <VCard elevation="0" border height="90">
          <VCardText class="pa-3 d-flex flex-column justify-space-between h-100">
            <VIcon :color="stats.compliance_alerts > 0 ? 'error' : 'success'" size="18">tabler-gavel</VIcon>
            <div>
              <div class="text-body-1 font-weight-bold" :class="stats.compliance_alerts > 0 ? 'text-error' : 'text-success'">{{ stats.compliance_alerts }}</div>
              <div class="text-caption text-medium-emphasis">{{ t('payroll_executive.kpis.compliance_alerts') }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="6" sm="4" md="2">
        <VCard elevation="0" border height="90">
          <VCardText class="pa-3 d-flex flex-column justify-space-between h-100">
            <VIcon :color="stats.open_anomalies > 0 ? 'error' : 'success'" size="18">tabler-alert-circle</VIcon>
            <div>
              <div class="text-body-1 font-weight-bold" :class="stats.open_anomalies > 0 ? 'text-error' : 'text-success'">{{ stats.open_anomalies }}</div>
              <div class="text-caption text-medium-emphasis">{{ t('payroll_executive.kpis.open_anomalies') }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow class="mb-4">
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="info" size="18" class="mb-1">tabler-clock-exclamation</VIcon>
            <div class="text-body-1 font-weight-bold">{{ fmtK(stats.overtime_curr) }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_executive.kpis.overtime_mtd') }}</div>
            <VChip v-if="stats.overtime_diff !== 0" size="x-small" class="mt-1" :color="stats.overtime_diff > 0 ? 'error' : 'success'" variant="tonal">
              {{ stats.overtime_diff > 0 ? '+' : '' }}{{ fmtK(stats.overtime_diff) }} MoM
            </VChip>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="warning" size="18" class="mb-1">tabler-umbrella-beach</VIcon>
            <div class="text-body-1 font-weight-bold">{{ fmtK(stats.leave_liability) }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_executive.kpis.leave_liability') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon :color="stats.mom_variance >= 0 ? 'error' : 'success'" size="18" class="mb-1">tabler-chart-line</VIcon>
            <div class="text-body-1 font-weight-bold" :class="stats.mom_variance >= 0 ? 'text-error' : 'text-success'">
              {{ stats.mom_variance >= 0 ? '+' : '' }}{{ fmtK(stats.mom_variance) }}
            </div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_executive.kpis.mom_variance') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VIcon color="error" size="18" class="mb-1">tabler-user-exclamation</VIcon>
            <div class="text-body-1 font-weight-bold text-error">{{ stats.high_risk_employees }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('payroll_executive.kpis.high_risk_employees') }}</div>
            <div v-if="stats.high_risk_depts > 0" class="text-caption text-medium-emphasis">{{ t('payroll_executive.kpis.depts', { count: stats.high_risk_depts }) }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow>
      <VCol cols="12" md="8">
        <VCard elevation="0" border class="mb-4">
          <VCardTitle class="pa-3 text-body-1 font-weight-semibold d-flex align-center ga-2">
            <VIcon color="primary" size="20">tabler-robot</VIcon>{{ t('payroll_executive.ai_summary.title') }}
            <VBtn size="x-small" variant="text" :href="route('payroll.ai-assistant.index')" class="ml-auto">{{ t('payroll_executive.ai_summary.full_chat') }}</VBtn>
          </VCardTitle>
          <VDivider />
          <VCardText class="pa-3">
            <p class="text-body-2 text-medium-emphasis" style="line-height:1.6" v-if="stats.ai_summary">{{ stats.ai_summary }}</p>
            <p v-else class="text-body-2 text-medium-emphasis font-italic">
              {{ t('payroll_executive.ai_summary.no_summary') }}
            </p>
          </VCardText>
        </VCard>

        <VCard elevation="0" border class="mb-4">
          <VCardTitle class="pa-3 text-body-1 font-weight-semibold d-flex align-center ga-2">
            <VIcon color="primary" size="20">tabler-chart-line</VIcon>{{ t('payroll_executive.monthly_cost_trend.title') }}
            <VChip size="x-small" color="success" variant="tonal" class="ml-auto">{{ t('payroll_executive.monthly_cost_trend.ytd', { amount: fmtK(ytdTotal) }) }}</VChip>
          </VCardTitle>
          <VDivider />
          <div v-if="!costTrend.length" class="pa-6 text-center text-medium-emphasis text-caption">{{ t('payroll_executive.monthly_cost_trend.no_data') }}</div>
          <VDataTable v-else :items="costTrend"
            :headers="[{ title: t('payroll_executive.monthly_cost_trend.table.month'), key: 'label' }, { title: t('payroll_executive.monthly_cost_trend.table.gross'), key: 'total_gross' }, { title: t('payroll_executive.monthly_cost_trend.table.net'), key: 'total_net' }]"
            density="compact" hide-default-footer items-per-page="-1">
            <template #item.total_gross="{ item }">
              <span class="text-caption text-medium-emphasis">{{ fmtK(item.total_gross) }}</span>
            </template>
            <template #item.total_net="{ item }">
              <span class="font-weight-medium">{{ fmtK(item.total_net) }}</span>
            </template>
          </VDataTable>
        </VCard>

        <VCard elevation="0" border>
          <VCardTitle class="pa-3 text-body-1 font-weight-semibold d-flex align-center justify-space-between">
            <span class="d-flex align-center ga-2"><VIcon size="20">tabler-cash</VIcon>{{ t('payroll_executive.recent_runs.title') }}</span>
            <VBtn size="x-small" variant="text" :href="route('payroll.runs.index')">{{ t('payroll_executive.recent_runs.view_all') }}</VBtn>
          </VCardTitle>
          <VDivider />
          <VDataTable :items="recentRuns"
            :headers="[
              { title: t('payroll_executive.recent_runs.table.ref'), key: 'ref_number' },
              { title: t('payroll_executive.recent_runs.table.period'), key: 'period_start', sortable: false },
              { title: t('payroll_executive.recent_runs.table.headcount'), key: 'employee_count' },
              { title: t('payroll_executive.recent_runs.table.net_pay'), key: 'total_net' },
              { title: t('payroll_executive.recent_runs.table.risk'), key: 'ai_risk_level', sortable: false },
              { title: t('payroll_executive.recent_runs.table.status'), key: 'status', sortable: false },
            ]"
            density="compact" hide-default-footer items-per-page="-1">
            <template #item.ref_number="{ item }">
              <a :href="route('payroll.runs.show', item.uuid)" class="text-body-2 font-weight-medium text-decoration-none">
                {{ item.ref_number }}
              </a>
            </template>
            <template #item.period_start="{ item }">
              <span class="text-caption">{{ item.period_start }}</span>
            </template>
            <template #item.total_net="{ item }">
              <span class="font-weight-medium">{{ fmtK(item.total_net) }}</span>
            </template>
            <template #item.ai_risk_level="{ item }">
              <VChip v-if="item.ai_risk_level" size="x-small" :color="riskColor(item.ai_risk_level)" variant="tonal">{{ item.ai_risk_level }}</VChip>
              <span v-else class="text-caption text-medium-emphasis">—</span>
            </template>
            <template #item.status="{ item }">
              <VChip size="x-small" :color="statusColor(item.status)" variant="tonal">{{ item.status }}</VChip>
            </template>
          </VDataTable>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard elevation="0" border class="mb-4">
          <VCardTitle class="pa-3 text-body-1 font-weight-semibold d-flex align-center justify-space-between">
            <span class="d-flex align-center ga-2"><VIcon color="purple" size="20">tabler-gavel</VIcon>{{ t('payroll_executive.compliance_section.title') }}</span>
            <VBtn size="x-small" variant="text" :href="route('payroll.compliance.index')">{{ t('payroll_executive.compliance_section.view_all') }}</VBtn>
          </VCardTitle>
          <VDivider />
          <VCardText class="pa-3">
            <div v-if="!compliance.total_open" class="text-center py-2 text-medium-emphasis text-caption">
              <VIcon size="22" color="success">tabler-shield-check</VIcon>
              <div class="mt-1">{{ t('payroll_executive.compliance_section.no_violations') }}</div>
            </div>
            <div v-else>
              <div class="d-flex justify-space-between align-center mb-3">
                <span class="text-body-2 font-weight-medium">{{ t('payroll_executive.compliance_section.open', { count: compliance.total_open }) }}</span>
                <VChip v-if="compliance.critical > 0" size="x-small" color="purple" variant="tonal">{{ t('payroll_executive.compliance_section.critical', { count: compliance.critical }) }}</VChip>
              </div>
              <div v-for="item in compliance.by_type" :key="item.type" class="d-flex align-center justify-space-between mb-2">
                <span class="text-caption">{{ compLabel(item.type) }}</span>
                <VChip size="x-small" :color="item.critical > 0 ? 'purple' : 'warning'" variant="tonal">{{ item.count }}</VChip>
              </div>
            </div>
          </VCardText>
        </VCard>

        <VCard elevation="0" border class="mb-4">
          <VCardTitle class="pa-3 text-body-1 font-weight-semibold d-flex align-center ga-2">
            <VIcon :color="riskColor(risk.risk_score)" size="20">tabler-shield-exclamation</VIcon>
            {{ t('payroll_executive.risk_section.title') }}
            <VChip size="x-small" :color="riskColor(risk.risk_score)" variant="tonal" class="ml-1" style="text-transform:capitalize">{{ risk.risk_score }}</VChip>
          </VCardTitle>
          <VDivider />
          <VCardText class="pa-3">
            <div class="d-flex ga-2 flex-wrap mb-3">
              <VChip size="x-small" color="purple" variant="tonal">{{ t('payroll_executive.risk_section.critical', { count: risk.breakdown.critical }) }}</VChip>
              <VChip size="x-small" color="error" variant="tonal">{{ t('payroll_executive.risk_section.high', { count: risk.breakdown.high }) }}</VChip>
              <VChip size="x-small" color="warning" variant="tonal">{{ t('payroll_executive.risk_section.medium', { count: risk.breakdown.medium }) }}</VChip>
            </div>
            <div v-if="risk.high_risk_depts.length">
              <div class="text-caption font-weight-medium text-medium-emphasis mb-2">{{ t('payroll_executive.risk_section.high_risk_depts') }}</div>
              <div v-for="dept in risk.high_risk_depts.slice(0,4)" :key="dept.department"
                class="d-flex align-center justify-space-between mb-1">
                <span class="text-caption text-truncate" style="max-width:140px">{{ dept.department }}</span>
                <VChip size="x-small" color="error" variant="tonal">{{ dept.anomaly_count }}</VChip>
              </div>
            </div>
          </VCardText>
        </VCard>

        <VCard elevation="0" border class="mb-4">
          <VCardTitle class="pa-3 text-body-1 font-weight-semibold d-flex align-center justify-space-between">
            <span class="d-flex align-center ga-2"><VIcon color="info" size="20">tabler-trending-up</VIcon>{{ t('payroll_executive.dept_variance.title') }}</span>
            <VBtn size="x-small" variant="text" :href="route('payroll.narrative-reports.index')">{{ t('payroll_executive.dept_variance.full_report') }}</VBtn>
          </VCardTitle>
          <VDivider />
          <div v-if="!variance.has_data" class="pa-4 text-center text-caption text-medium-emphasis">
            {{ t('payroll_executive.dept_variance.not_enough') }}
          </div>
          <template v-else>
            <div class="pa-2 text-caption text-medium-emphasis d-flex justify-space-between align-center">
              <span>{{ t('payroll_executive.dept_variance.vs', { curr: variance.curr_run?.ref_number, prev: variance.prev_run?.ref_number }) }}</span>
              <VChip size="x-small" :color="(variance.net_diff ?? 0) >= 0 ? 'error' : 'success'" variant="tonal">
                {{ (variance.net_diff ?? 0) >= 0 ? '+' : '' }}{{ fmtK(variance.net_diff ?? 0) }}
              </VChip>
            </div>
            <VDivider />
            <div v-for="dept in variance.by_dept.slice(0,5)" :key="dept.department"
              class="d-flex align-center justify-space-between pa-2 text-caption" style="border-bottom:1px solid rgba(0,0,0,.06)">
              <span class="text-truncate" style="max-width:110px">{{ dept.department }}</span>
              <VChip size="x-small" :color="dept.diff >= 0 ? 'error' : 'success'" variant="tonal">
                {{ dept.diff >= 0 ? '+' : '' }}{{ fmtK(dept.diff) }}
                <span v-if="dept.pct != null"> ({{ dept.diff >= 0 ? '+' : '' }}{{ dept.pct }}%)</span>
              </VChip>
            </div>
          </template>
        </VCard>

        <VCard elevation="0" border>
          <VCardTitle class="pa-3 text-body-1 font-weight-semibold d-flex align-center justify-space-between">
            <span class="d-flex align-center ga-2"><VIcon color="warning" size="20">tabler-alert-triangle</VIcon>{{ t('payroll_executive.recent_anomalies.title') }}</span>
            <VBtn size="x-small" variant="text" :href="route('payroll.anomalies.index')">{{ t('payroll_executive.recent_anomalies.view_all') }}</VBtn>
          </VCardTitle>
          <VDivider />
          <VList density="compact" class="pa-0">
            <template v-if="recentAnomalies.length">
              <VListItem v-for="anomaly in recentAnomalies.slice(0,7)" :key="anomaly.id" class="px-3">
                <template #prepend>
                  <VIcon :color="sevColor(anomaly.severity)" size="14">tabler-alert-circle</VIcon>
                </template>
                <VListItemTitle class="text-caption">{{ anomaly.anomaly_type.replace(/_/g,' ') }}</VListItemTitle>
                <template #append>
                  <VChip size="x-small" :color="sevColor(anomaly.severity)" variant="tonal">{{ anomaly.severity }}</VChip>
                </template>
              </VListItem>
            </template>
            <div v-else class="pa-4 text-center text-caption text-medium-emphasis">{{ t('payroll_executive.recent_anomalies.no_anomalies') }}</div>
          </VList>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
