<script setup lang="ts">
import { computed } from 'vue'
import VueApexCharts from 'vue3-apexcharts'
import { useTheme } from 'vuetify'
import { hexToRgb } from '@/@core/utils/colorConverter'

type ExceptionTrend = {
  month: string
  total: number
  approved: number
  approval_rate: number
}

type ComplianceRow = {
  department: string
  compliance_score: number
  policy_adoption_rate: number
  attestation_completion: number
}

type NamedValue = {
  name: string
  value?: number
  rate?: number
  label?: string
}

type RiskPattern = {
  pattern: string
  count: number
}

type EffectivenessFriction = {
  policy: string
  friction_score: number
  details: string
}

type EffectivenessIgnored = {
  policy: string
  adoption_rate: number
  details: string
}

type EffectivenessAi = {
  policy: string
  questions: number
}

const props = defineProps<{
  exceptionTrends: ExceptionTrend[]
  complianceDashboard: ComplianceRow[]
  behaviorAnalytics: {
    repeat_offenders: NamedValue[]
    manager_override_rates: NamedValue[]
    high_risk_patterns: RiskPattern[]
  }
  policyEffectiveness: {
    policies_causing_friction: EffectivenessFriction[]
    policies_ignored: EffectivenessIgnored[]
    policies_frequently_questioned_ai: EffectivenessAi[]
  }
}>()

const theme = useTheme()

// KPI Cards Data
const kpiCards = [
  {
    title: 'Total Exceptions',
    value: computed(() => props.exceptionTrends.reduce((sum, t) => sum + t.total, 0)),
    icon: 'tabler-alert-circle',
    color: '#FF9F43'
  },
  {
    title: 'Avg Compliance',
    value: computed(() => {
      if (!props.complianceDashboard.length) return 0
      const avg = props.complianceDashboard.reduce((sum, c) => sum + c.compliance_score, 0) / props.complianceDashboard.length
      return Math.round(avg)
    }),
    icon: 'tabler-shield-check',
    color: '#28C76F'
  },
  {
    title: 'High Risk Patterns',
    value: computed(() => props.behaviorAnalytics.high_risk_patterns.reduce((sum, p) => sum + p.count, 0)),
    icon: 'tabler-flame',
    color: '#EA5455'
  },
  {
    title: 'Policy Adoption',
    value: computed(() => {
      if (!props.complianceDashboard.length) return 0
      const avg = props.complianceDashboard.reduce((sum, c) => sum + c.policy_adoption_rate, 0) / props.complianceDashboard.length
      return Math.round(avg)
    }),
    icon: 'tabler-trending-up',
    color: '#7367F0'
  }
]

// Department Compliance Chart (Stacked Bars)
const departmentComplianceChartOptions = computed(() => ({
  chart: { type: 'bar', toolbar: { show: false } },
  colors: [hexToRgb(`${theme.current.value.colors.primary}`) + ', 0.7)', hexToRgb(`${theme.current.value.colors.success}`) + ', 0.7)'],
  plotOptions: {
    bar: { horizontal: false, columnWidth: '55%', dataLabels: { position: 'top' } }
  },
  dataLabels: { enabled: false },
  stroke: { show: true, width: 0, colors: ['transparent'] },
  legend: { position: 'top', horizontalAlign: 'left' },
  states: { hover: { filter: { type: 'none' } }, active: { filter: { type: 'none' } } },
  xaxis: { categories: props.complianceDashboard.map(d => d.department) },
  tooltip: {
    y: {
      formatter: (val: number) => Math.round(val) + '%'
    }
  }
}))

const departmentComplianceChartSeries = computed(() => [
  {
    name: 'Compliance Score',
    data: props.complianceDashboard.map(d => d.compliance_score)
  },
  {
    name: 'Policy Adoption',
    data: props.complianceDashboard.map(d => d.policy_adoption_rate)
  }
])

// Exception Approval Rate (Donut Chart)
const exceptionApprovalChartOptions = computed(() => {
  const totalExceptions = props.exceptionTrends.reduce((sum, t) => sum + t.total, 0)
  const totalApproved = props.exceptionTrends.reduce((sum, t) => sum + t.approved, 0)
  const totalRejected = totalExceptions - totalApproved

  return {
    chart: { type: 'donut', toolbar: { show: false } },
    colors: [hexToRgb(`${theme.current.value.colors.success}`) + ', 1)', hexToRgb(`${theme.current.value.colors.warning}`) + ', 1)'],
    labels: ['Approved', 'Pending/Rejected'],
    legend: { position: 'bottom' },
    plotOptions: {
      pie: {
        donut: {
          size: '65%',
          labels: {
            show: true,
            name: { show: true, fontSize: '14px' },
            value: { show: true, fontSize: '18px', fontWeight: 600 }
          }
        }
      }
    },
    dataLabels: { enabled: false }
  }
})

const exceptionApprovalChartSeries = computed(() => {
  const totalExceptions = props.exceptionTrends.reduce((sum, t) => sum + t.total, 0)
  const totalApproved = props.exceptionTrends.reduce((sum, t) => sum + t.approved, 0)
  const totalRejected = totalExceptions - totalApproved
  return [totalApproved, totalRejected]
})

// Policies Causing Friction (Horizontal Bar)
const frictionPoliciesChartOptions = computed(() => ({
  chart: { type: 'bar', toolbar: { show: false } },
  colors: [hexToRgb(`${theme.current.value.colors.error}`) + ', 0.85)'],
  plotOptions: { bar: { horizontal: true, dataLabels: { position: 'top' } } },
  dataLabels: { enabled: false },
  xaxis: {
    categories: props.policyEffectiveness.policies_causing_friction.slice(0, 6).map(p => p.policy.slice(0, 20))
  },
  states: { hover: { filter: { type: 'none' } } },
  tooltip: {
    x: { formatter: (val: string) => val },
    y: { formatter: (val: number) => Math.round(val * 100) + '%' }
  }
}))

const frictionPoliciesChartSeries = computed(() => [
  {
    name: 'Friction Score',
    data: props.policyEffectiveness.policies_causing_friction.slice(0, 6).map(p => p.friction_score)
  }
])
</script>

<template>
  <div>
    <!-- Hero Section -->
    <VCard class="mb-6" flat>
      <div class="hero-background pa-8 bg-gradient-to-r" style="background: linear-gradient(135deg, #7367F0 0%, #6F5FE8 100%); border-radius: 12px; color: white;">
        <h2 class="text-h3 mb-2 font-weight-bold text-white">Policy Dashboard</h2>
        <p class="text-white/80 mb-0">Live policy intelligence, compliance tracking, and behavioral insights</p>
      </div>
    </VCard>

    <!-- KPI Cards -->
    <VRow class="mb-6">
      <VCol v-for="(card, index) in kpiCards" :key="index" cols="12" md="6" lg="3">
        <VCard class="d-flex flex-column h-100">
          <VCardText class="flex-grow-1 d-flex flex-column justify-space-between pa-6">
            <div class="d-flex align-center justify-space-between mb-4">
              <h5 class="text-subtitle-2 text-medium-emphasis">{{ card.title }}</h5>
              <VIcon :style="{ color: card.color }" :icon="card.icon" size="x-large"></VIcon>
            </div>
            <div>
              <div class="text-h4 font-weight-bold mb-1" :style="{ color: card.color }">
                {{ card.value.value }}{{ card.title.includes('Adoption') || card.title.includes('Compliance') ? '%' : '' }}
              </div>
              <p class="text-xs text-medium-emphasis mb-0">• Updated in real-time</p>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Charts Row -->
    <VRow class="mb-6">
      <!-- Department Compliance Chart -->
      <VCol cols="12" lg="6">
        <VCard title="Department Compliance & Adoption">
          <VCardText>
            <VueApexCharts
              v-if="departmentComplianceChartSeries.length > 0"
              type="bar"
              :series="departmentComplianceChartSeries"
              :options="departmentComplianceChartOptions"
              height="320"
            />
            <div v-else class="text-center py-8 text-medium-emphasis">
              No compliance data available
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Exception Approval Status -->
      <VCol cols="12" lg="6">
        <VCard title="Exception Approval Status">
          <VCardText>
            <VueApexCharts
              v-if="exceptionApprovalChartSeries.length > 0 && exceptionApprovalChartSeries[0] > 0"
              type="donut"
              :series="exceptionApprovalChartSeries"
              :options="exceptionApprovalChartOptions"
              height="320"
            />
            <div v-else class="text-center py-8 text-medium-emphasis">
              No exception data available
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Friction Policies Chart -->
    <VRow class="mb-6">
      <VCol cols="12">
        <VCard title="Policies Causing Most Friction">
          <VCardText>
            <VueApexCharts
              v-if="frictionPoliciesChartSeries.length > 0 && policyEffectiveness.policies_causing_friction.length > 0"
              type="bar"
              :series="frictionPoliciesChartSeries"
              :options="frictionPoliciesChartOptions"
              height="300"
            />
            <div v-else class="text-center py-8 text-medium-emphasis">
              No friction data available
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Data Tables Section -->
    <VRow class="mb-6">
      <VCol cols="12" lg="4">
        <VCard title="Repeat Offenders" subtitle="Top 5 users with policy violations">
          <VDivider class="my-2"></VDivider>
          <VList density="compact">
            <VListItem v-for="item in behaviorAnalytics.repeat_offenders.slice(0, 5)" :key="item.name">
              <VListItemTitle class="text-body-2">{{ item.name }}</VListItemTitle>
              <template #append>
                <VBadge :content="`${item.value}x`" color="error"></VBadge>
              </template>
            </VListItem>
            <VListItem v-if="!behaviorAnalytics.repeat_offenders.length">
              <VListItemTitle class="text-center text-medium-emphasis py-4">
                No violation data
              </VListItemTitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>

      <VCol cols="12" lg="4">
        <VCard title="Manager Overrides" subtitle="Top 5 approvers">
          <VDivider class="my-2"></VDivider>
          <VList density="compact">
            <VListItem v-for="item in behaviorAnalytics.manager_override_rates.slice(0, 5)" :key="item.name">
              <VListItemTitle class="text-body-2">{{ item.name }}</VListItemTitle>
              <template #append>
                <VBadge :content="`${item.rate}`" color="primary"></VBadge>
              </template>
            </VListItem>
            <VListItem v-if="!behaviorAnalytics.manager_override_rates.length">
              <VListItemTitle class="text-center text-medium-emphasis py-4">
                No override data
              </VListItemTitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>

      <VCol cols="12" lg="4">
        <VCard title="High Risk Patterns" subtitle="Top risk types">
          <VDivider class="my-2"></VDivider>
          <VList density="compact">
            <VListItem v-for="item in behaviorAnalytics.high_risk_patterns.slice(0, 5)" :key="item.pattern">
              <VListItemTitle class="text-body-2 text-capitalize">{{ item.pattern }}</VListItemTitle>
              <template #append>
                <VBadge :content="`${item.count}`" color="warning"></VBadge>
              </template>
            </VListItem>
            <VListItem v-if="!behaviorAnalytics.high_risk_patterns.length">
              <VListItemTitle class="text-center text-medium-emphasis py-4">
                No risk data
              </VListItemTitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>

    <!-- Detailed Analytics Tables -->
    <VRow>
      <VCol cols="12" md="6">
        <VCard title="Compliance by Department">
          <VTable density="compact">
            <thead>
              <tr>
                <th>Department</th>
                <th class="text-center">Compliance</th>
                <th class="text-center">Adoption</th>
                <th class="text-center">Attestation</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in complianceDashboard.slice(0, 8)" :key="item.department">
                <td class="text-body-2">{{ item.department }}</td>
                <td class="text-center text-body-2">
                  <VProgressLinear :model-value="item.compliance_score" height="4"></VProgressLinear>
                </td>
                <td class="text-center text-body-2">{{ Math.round(item.policy_adoption_rate) }}%</td>
                <td class="text-center text-body-2">{{ Math.round(item.attestation_completion) }}%</td>
              </tr>
              <tr v-if="!complianceDashboard.length">
                <td colspan="4" class="text-center text-medium-emphasis py-4">No compliance data</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>

      <VCol cols="12" md="6">
        <VCard title="Exception Trends">
          <VTable density="compact">
            <thead>
              <tr>
                <th>Month</th>
                <th class="text-center">Total</th>
                <th class="text-center">Approved</th>
                <th class="text-center">Rate</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in exceptionTrends.slice(0, 8)" :key="item.month">
                <td class="text-body-2">{{ item.month }}</td>
                <td class="text-center text-body-2">{{ item.total }}</td>
                <td class="text-center text-body-2">{{ item.approved }}</td>
                <td class="text-center text-body-2">
                  <VChip :color="item.approval_rate > 75 ? 'success' : item.approval_rate > 50 ? 'warning' : 'error'" size="small">
                    {{ Math.round(item.approval_rate) }}%
                  </VChip>
                </td>
              </tr>
              <tr v-if="!exceptionTrends.length">
                <td colspan="4" class="text-center text-medium-emphasis py-4">No exception data</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>

    <!-- Policy Effectiveness Section -->
    <VRow class="mt-6">
      <VCol cols="12" md="6">
        <VCard title="Policies Ignored" subtitle="Low adoption rates">
          <VList density="compact">
            <VListItem v-for="item in policyEffectiveness.policies_ignored.slice(0, 5)" :key="item.policy">
              <VListItemTitle class="text-body-2">{{ item.policy }}</VListItemTitle>
              <VListItemSubtitle class="text-xs">{{ item.details }}</VListItemSubtitle>
              <template #append>
                <VBadge :content="`${Math.round(item.adoption_rate)}%`" color="warning"></VBadge>
              </template>
            </VListItem>
            <VListItem v-if="!policyEffectiveness.policies_ignored.length">
              <VListItemTitle class="text-center text-medium-emphasis py-4">
                No ignored policies
              </VListItemTitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>

      <VCol cols="12" md="6">
        <VCard title="Policies Frequently Questioned" subtitle="Via AI assistance">
          <VList density="compact">
            <VListItem v-for="item in policyEffectiveness.policies_frequently_questioned_ai.slice(0, 5)" :key="item.policy">
              <VListItemTitle class="text-body-2">{{ item.policy }}</VListItemTitle>
              <template #append>
                <VBadge :content="`${item.questions}`" color="info"></VBadge>
              </template>
            </VListItem>
            <VListItem v-if="!policyEffectiveness.policies_frequently_questioned_ai.length">
              <VListItemTitle class="text-center text-medium-emphasis py-4">
                No AI reference data
              </VListItemTitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>

<style scoped lang="scss">
@use "@core/scss/template/libs/apex-chart.scss";

.hero-background {
  border-radius: 12px;
  position: relative;
  overflow: hidden;

  &::after {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 300px;
    height: 300px;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, rgba(255, 255, 255, 0) 70%);
    border-radius: 50%;
    pointer-events: none;
  }
}

:deep(.apexcharts-legend) {
  margin-top: 1rem;
}
</style>
