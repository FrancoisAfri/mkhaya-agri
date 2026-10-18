<template>
  <VContainer fluid class="pa-4">
      <div class="d-flex align-center justify-space-between mb-3 flex-wrap gap-2">
        <div>
          <h1 class="text-h5 font-weight-bold">{{ t('payroll_analytics.page_title') }}</h1>
          <p class="text-body-2 text-medium-emphasis mb-0">{{ dateFrom }} – {{ dateTo }}</p>
        </div>
        <div class="d-flex gap-2 flex-wrap align-center">
          <VBtnToggle v-model="localPeriod" density="compact" color="primary" mandatory>
            <VBtn value="month">{{ t('payroll_analytics.filters.period_month') }}</VBtn>
            <VBtn value="quarter">{{ t('payroll_analytics.filters.period_quarter') }}</VBtn>
            <VBtn value="halfyear">{{ t('payroll_analytics.filters.period_halfyear') }}</VBtn>
            <VBtn value="year">{{ t('payroll_analytics.filters.period_year') }}</VBtn>
            <VBtn value="lastyear">{{ t('payroll_analytics.filters.period_lastyear') }}</VBtn>
          </VBtnToggle>
          <VSelect v-model="localDept" :items="filterOptions.departments" :label="t('payroll_analytics.filters.department')"
            density="compact" hide-details clearable style="min-width:180px" />
          <VBtn color="primary" size="small" @click="applyFilters">{{ t('payroll_analytics.filters.apply') }}</VBtn>
        </div>
      </div>

      <VRow class="mb-3">
        <VCol v-for="kpi in kpiCards" :key="kpi.label" cols="6" sm="4" md="2">
          <VCard elevation="0" border :color="kpi.color" variant="tonal">
            <VCardText class="pa-3 text-center">
              <VIcon :icon="kpi.icon" size="22" class="mb-1" />
              <div class="text-caption text-medium-emphasis">{{ kpi.label }}</div>
              <div class="text-subtitle-1 font-weight-bold">{{ kpi.value }}</div>
              <VChip v-if="kpi.chip !== undefined" :color="kpi.chipColor" size="x-small" class="mt-1">
                {{ kpi.chip >= 0 ? '+' : '' }}{{ kpi.chip }}%
              </VChip>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <VRow class="mb-3">
        <VCol cols="12" md="8">
          <VCard elevation="0" border height="100%">
            <VCardTitle class="text-body-1 font-weight-medium pa-3">
              <VIcon icon="tabler-chart-line" class="mr-1" size="18" />{{ t('payroll_analytics.charts.monthly_cost') }}
            </VCardTitle>
            <VDivider />
            <VCardText class="pa-0">
              <VTable density="compact">
                <thead>
                  <tr>
                    <th>{{ t('payroll_analytics.table.month') }}</th>
                    <th class="text-right">{{ t('payroll_analytics.table.gross') }}</th>
                    <th class="text-right">{{ t('payroll_analytics.table.net') }}</th>
                    <th class="text-right">{{ t('payroll_analytics.table.deductions') }}</th>
                    <th class="text-right">{{ t('payroll_analytics.table.headcount') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in monthlyCost" :key="row.month">
                    <td class="font-weight-medium">{{ row.month }}</td>
                    <td class="text-right">{{ fmtK(row.gross) }}</td>
                    <td class="text-right text-success font-weight-medium">{{ fmtK(row.net) }}</td>
                    <td class="text-right text-error">{{ fmtK(row.deductions) }}</td>
                    <td class="text-right">{{ row.headcount }}</td>
                  </tr>
                  <tr v-if="!monthlyCost.length">
                    <td colspan="5" class="text-center text-medium-emphasis py-4">{{ t('payroll_analytics.no_released_runs') }}</td>
                  </tr>
                </tbody>
              </VTable>
            </VCardText>
          </VCard>
        </VCol>

        <VCol cols="12" md="4">
          <VCard elevation="0" border height="100%">
            <VCardTitle class="text-body-1 font-weight-medium pa-3">
              <VIcon icon="tabler-layers" class="mr-1" size="18" />{{ t('payroll_analytics.charts.gross_vs_net') }}
            </VCardTitle>
            <VDivider />
            <VCardText class="pa-0">
              <VTable density="compact">
                <thead>
                  <tr>
                    <th>{{ t('payroll_analytics.table.run') }}</th>
                    <th class="text-right">{{ t('payroll_analytics.table.gross') }}</th>
                    <th class="text-right">{{ t('payroll_analytics.table.net') }}</th>
                    <th class="text-right">{{ t('payroll_analytics.table.emp') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="run in grossVsNet" :key="run.run">
                    <td class="text-caption">{{ run.label }}</td>
                    <td class="text-right text-caption">{{ fmtK(run.gross) }}</td>
                    <td class="text-right text-caption font-weight-medium">{{ fmtK(run.net) }}</td>
                    <td class="text-right text-caption">{{ run.employee_count }}</td>
                  </tr>
                  <tr v-if="!grossVsNet.length">
                    <td colspan="4" class="text-center text-medium-emphasis py-3">{{ t('payroll_analytics.no_data') }}</td>
                  </tr>
                </tbody>
              </VTable>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <VRow class="mb-3">
        <VCol cols="12" md="7">
          <VCard elevation="0" border>
            <VCardTitle class="text-body-1 font-weight-medium pa-3">
              <VIcon icon="tabler-building" class="mr-1" size="18" />{{ t('payroll_analytics.charts.cost_by_department') }}
            </VCardTitle>
            <VDivider />
            <VDataTable :headers="deptHeaders" :items="byDepartment" density="compact" :items-per-page="10" hide-default-footer>
              <template #item.total_net="{ item }">
                <span class="font-weight-medium text-success">{{ fmtR(item.total_net) }}</span>
              </template>
              <template #item.total_gross="{ item }">{{ fmtR(item.total_gross) }}</template>
              <template #item.avg_net="{ item }">{{ fmtR(item.avg_net) }}</template>
              <template #item.pct="{ item }">
                <VProgressLinear :model-value="deptPct(item.total_net)" color="primary" rounded height="8" style="width:80px" />
              </template>
            </VDataTable>
          </VCard>
        </VCol>

        <VCol cols="12" md="5">
          <VCard elevation="0" border>
            <VCardTitle class="text-body-1 font-weight-medium pa-3">
              <VIcon icon="tabler-podium-star" class="mr-1" size="18" />{{ t('payroll_analytics.charts.top_earners') }}
            </VCardTitle>
            <VDivider />
            <VList density="compact" class="pa-0">
              <VListItem v-for="(emp, i) in topEarners" :key="i" :subtitle="emp.department">
                <template #prepend>
                  <VAvatar size="30" color="primary" variant="tonal" class="mr-2">
                    <span class="text-caption font-weight-bold">{{ i + 1 }}</span>
                  </VAvatar>
                </template>
                <template #title>
                  <span class="text-body-2 font-weight-medium">{{ emp.employee }}</span>
                </template>
                <template #append>
                  <div class="text-right">
                    <div class="text-body-2 font-weight-bold text-success">{{ fmtR(emp.net_pay) }}</div>
                    <div class="text-caption text-medium-emphasis">PAYE {{ fmtR(emp.paye) }}</div>
                  </div>
                </template>
              </VListItem>
              <VListItem v-if="!topEarners.length" class="text-center text-medium-emphasis">{{ t('payroll_analytics.no_data') }}</VListItem>
            </VList>
          </VCard>
        </VCol>
      </VRow>

      <VRow class="mb-3">
        <VCol cols="12" md="6">
          <VCard elevation="0" border>
            <VCardTitle class="text-body-1 font-weight-medium pa-3">
              <VIcon icon="tabler-alert-circle" class="mr-1" size="18" />{{ t('payroll_analytics.charts.anomaly_distribution') }}
            </VCardTitle>
            <VDivider />
            <VDataTable :headers="anomalyHeaders" :items="anomalyDist" density="compact" :items-per-page="10" hide-default-footer>
              <template #item.severity="{ item }">
                <VChip :color="severityColor(item.severity)" size="x-small">{{ item.severity }}</VChip>
              </template>
              <template #item.count="{ item }">
                <VChip color="error" size="x-small" variant="tonal">{{ item.count }}</VChip>
              </template>
            </VDataTable>
          </VCard>
        </VCol>

        <VCol cols="12" md="6">
          <VCard elevation="0" border>
            <VCardTitle class="text-body-1 font-weight-medium pa-3">
              <VIcon icon="tabler-clock-plus" class="mr-1" size="18" />{{ t('payroll_analytics.charts.overtime_trend') }}
            </VCardTitle>
            <VDivider />
            <VTable density="compact">
              <thead>
                <tr>
                  <th>{{ t('payroll_analytics.table.month') }}</th>
                  <th class="text-right">{{ t('payroll_analytics.table.employees') }}</th>
                  <th class="text-right">{{ t('payroll_analytics.table.hours') }}</th>
                  <th class="text-right">{{ t('payroll_analytics.table.cost') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in overtimeTrend" :key="row.month">
                  <td>{{ row.month }}</td>
                  <td class="text-right">{{ row.employees }}</td>
                  <td class="text-right">{{ row.total_hours }}</td>
                  <td class="text-right font-weight-medium">{{ fmtR(row.total_cost) }}</td>
                </tr>
                <tr v-if="!overtimeTrend.length">
                  <td colspan="4" class="text-center text-medium-emphasis py-3">{{ t('payroll_analytics.no_overtime_data') }}</td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VCol>
      </VRow>

      <VRow>
        <VCol cols="12" md="5">
          <VCard elevation="0" border>
            <VCardTitle class="text-body-1 font-weight-medium pa-3">
              <VIcon icon="tabler-calculator" class="mr-1" size="18" />{{ t('payroll_analytics.charts.tax_summary') }}
            </VCardTitle>
            <VDivider />
            <VCardText>
              <VRow class="mb-2">
                <VCol cols="4" class="text-center">
                  <div class="text-caption text-medium-emphasis">{{ t('payroll_analytics.tax.paye') }}</div>
                  <div class="text-body-1 font-weight-bold text-error">{{ fmtK(taxSummary.paye_total) }}</div>
                </VCol>
                <VCol cols="4" class="text-center">
                  <div class="text-caption text-medium-emphasis">{{ t('payroll_analytics.tax.uif') }}</div>
                  <div class="text-body-1 font-weight-bold text-warning">{{ fmtK(taxSummary.uif_total) }}</div>
                </VCol>
                <VCol cols="4" class="text-center">
                  <div class="text-caption text-medium-emphasis">{{ t('payroll_analytics.tax.pension') }}</div>
                  <div class="text-body-1 font-weight-bold text-info">{{ fmtK(taxSummary.pension_total) }}</div>
                </VCol>
              </VRow>
              <VDivider class="mb-2" />
              <VTable density="compact">
                <thead><tr><th>{{ t('payroll_analytics.table.tax_bracket') }}</th><th class="text-right">{{ t('payroll_analytics.table.employees') }}</th><th class="text-right">{{ t('payroll_analytics.table.paye') }}</th></tr></thead>
                <tbody>
                  <tr v-for="bracket in taxSummary.by_bracket" :key="bracket.bracket">
                    <td class="text-caption">{{ bracket.bracket }}</td>
                    <td class="text-right text-caption">{{ bracket.count }}</td>
                    <td class="text-right text-caption font-weight-medium">{{ fmtR(bracket.paye_total) }}</td>
                  </tr>
                </tbody>
              </VTable>
            </VCardText>
          </VCard>
        </VCol>

        <VCol cols="12" md="7">
          <VCard elevation="0" border>
            <VCardTitle class="text-body-1 font-weight-medium pa-3">
              <VIcon icon="tabler-shield-exclamation" class="mr-1" size="18" />{{ t('payroll_analytics.charts.risk_trend') }}
            </VCardTitle>
            <VDivider />
            <VTable density="compact">
              <thead><tr><th>{{ t('payroll_analytics.table.month') }}</th><th>{{ t('payroll_analytics.table.risk_level') }}</th><th class="text-right">{{ t('payroll_analytics.table.anomalies') }}</th></tr></thead>
              <tbody>
                <tr v-for="risk in riskTrend" :key="risk.month + risk.risk_level">
                  <td>{{ risk.month }}</td>
                  <td><VChip :color="riskColor(risk.risk_level)" size="x-small">{{ risk.risk_level }}</VChip></td>
                  <td class="text-right">{{ risk.anomaly_count }}</td>
                </tr>
                <tr v-if="!riskTrend.length">
                  <td colspan="3" class="text-center text-medium-emphasis py-3">{{ t('payroll_analytics.no_risk_data') }}</td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VCol>
      </VRow>
    </VContainer>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  period:       string
  dateFrom:     string
  dateTo:       string
  filters:      { department?: string; run_status?: string }
  filterOptions: { departments: string[]; statuses: string[] }
  kpis:         Record<string, number>
  monthlyCost:  any[]
  byDepartment: any[]
  runBreakdown: any[]
  anomalyDist:  any[]
  topEarners:   any[]
  riskTrend:    any[]
  grossVsNet:   any[]
  taxSummary:   { paye_total: number; uif_total: number; pension_total: number; by_bracket: any[] }
  overtimeTrend: any[]
}>()

const localPeriod = ref(props.period)
const localDept   = ref(props.filters.department ?? null)

function applyFilters() {
  router.get('/payroll/analytics', {
    period:     localPeriod.value,
    department: localDept.value ?? undefined,
  }, { preserveScroll: true })
}

const kpiCards = computed(() => [
  { label: t('payroll_analytics.kpis.total_payroll_cost'), value: fmtK(props.kpis.total_cost), icon: 'tabler-cash', color: 'primary', chip: props.kpis.mom_variance_pct, chipColor: props.kpis.mom_variance_pct > 5 ? 'error' : 'success' },
  { label: t('payroll_analytics.kpis.avg_net_pay'), value: fmtR(props.kpis.avg_net_pay), icon: 'tabler-user-dollar', color: 'success', chip: undefined },
  { label: t('payroll_analytics.kpis.open_anomalies'), value: props.kpis.open_anomalies, icon: 'tabler-alert-circle', color: 'error', chip: undefined },
  { label: t('payroll_analytics.kpis.critical_anomalies'), value: props.kpis.critical_anomalies, icon: 'tabler-alert-triangle', color: 'error', chip: undefined },
  { label: t('payroll_analytics.kpis.pending_approvals'), value: props.kpis.pending_approvals, icon: 'tabler-clock-check', color: 'warning', chip: undefined },
  { label: t('payroll_analytics.kpis.compliance_alerts'), value: props.kpis.compliance_alerts, icon: 'tabler-shield-exclamation', color: 'warning', chip: undefined },
])

const deptHeaders = [
  { title: t('payroll_analytics.table.department'), key: 'department' },
  { title: t('payroll_analytics.table.headcount'),  key: 'headcount' },
  { title: t('payroll_analytics.table.gross'),      key: 'total_gross' },
  { title: t('payroll_analytics.table.net'),        key: 'total_net' },
  { title: t('payroll_analytics.table.avg_net'),    key: 'avg_net' },
  { title: t('payroll_analytics.table.share'),      key: 'pct', sortable: false },
]

const anomalyHeaders = [
  { title: t('payroll_analytics.table.anomaly_type'), key: 'anomaly_type' },
  { title: t('payroll_analytics.table.severity'),     key: 'severity' },
  { title: t('payroll_analytics.table.count'),        key: 'count' },
]

function fmtR(v: any): string {
  const n = parseFloat(v)
  if (isNaN(n)) return '-'
  return 'R ' + n.toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function fmtK(v: any): string {
  const n = parseFloat(v)
  if (isNaN(n)) return '-'
  if (Math.abs(n) >= 1_000_000) return 'R' + (n / 1_000_000).toFixed(1) + 'M'
  if (Math.abs(n) >= 1_000)     return 'R' + (n / 1_000).toFixed(0) + 'K'
  return fmtR(n)
}

const deptTotal = computed(() => props.byDepartment.reduce((s, r) => s + (parseFloat(r.total_net) || 0), 0))

function deptPct(net: any): number {
  const total = deptTotal.value
  if (!total) return 0
  return Math.round(((parseFloat(net) || 0) / total) * 100)
}

function severityColor(s: string): string {
  return { critical: 'error', high: 'warning', medium: 'orange', low: 'info' }[s] ?? 'default'
}

function riskColor(level: string): string {
  return { critical: 'error', high: 'warning', medium: 'orange', low: 'success', unknown: 'default' }[level] ?? 'default'
}
</script>
