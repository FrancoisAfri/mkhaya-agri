<script setup lang="ts">
import { computed } from 'vue'
import { route as ziggyRoute } from 'ziggy-js'
import { useTheme } from 'vuetify'
import { hexToRgb } from '@core/utils/colorConverter'
import type { ApexOptions } from 'apexcharts'
import VueApexCharts from 'vue3-apexcharts'

const route = ziggyRoute
const vuetifyTheme = useTheme()

type Stats = {
  total: number
  completed: number
  overdue: number
  pending: number
  compliancePct: number
}

type DeptRow = {
  department: string
  total: number
  completed: number
  compliance_pct: number
}

type UnackRow = {
  id: number
  user_name: string
  email: string
  policy_name: string
  deadline: string | null
  status: string
  viewed_at: string | null
}

type RecentRow = {
  user_name: string
  policy_name: string
  signed_at: string
}

const props = defineProps<{
  stats: Stats
  byDepartment: DeptRow[]
  unacknowledged: UnackRow[]
  recentCompleted: RecentRow[]
}>()

const kpiCards = computed(() => [
  {
    title: 'Total Assigned',
    value: props.stats.total,
    subtitle: 'All active attestations',
    icon: 'tabler-clipboard-list',
    color: 'primary',
  },
  {
    title: 'Completed',
    value: props.stats.completed,
    subtitle: 'Signed and acknowledged',
    icon: 'tabler-circle-check',
    color: 'success',
  },
  {
    title: 'Pending',
    value: props.stats.pending,
    subtitle: 'Awaiting acknowledgement',
    icon: 'tabler-clock',
    color: 'warning',
  },
  {
    title: 'Overdue',
    value: props.stats.overdue,
    subtitle: 'Past submission deadline',
    icon: 'tabler-alert-triangle',
    color: 'error',
  },
])

const departmentRows = computed(() => props.byDepartment.slice(0, 8))

const deptLabels = computed(() => departmentRows.value.map(d => d.department))
const deptCompletedSeries = computed(() => departmentRows.value.map(d => d.completed))
const deptRemainingSeries = computed(() => departmentRows.value.map(d => Math.max(d.total - d.completed, 0)))

const statusSeries = computed(() => [props.stats.completed, props.stats.pending, props.stats.overdue])

const topPolicies = computed(() => {
  const counts = new Map<string, number>()
  props.recentCompleted.forEach(row => {
    const key = row.policy_name || 'Unknown Policy'
    counts.set(key, (counts.get(key) ?? 0) + 1)
  })

  return Array.from(counts.entries())
    .map(([policy, count]) => ({ policy, count }))
    .sort((a, b) => b.count - a.count)
    .slice(0, 6)
})

const topPolicyLabels = computed(() => topPolicies.value.map(p => p.policy))
const topPolicySeries = computed(() => topPolicies.value.map(p => p.count))

const complianceColor = computed(() => {
  if (props.stats.compliancePct >= 90) return 'success'
  if (props.stats.compliancePct >= 60) return 'warning'
  return 'error'
})

function deptColor(pct: number): string {
  if (pct >= 90) return 'success'
  if (pct >= 60) return 'warning'
  return 'error'
}

const departmentCompletionChartOptions = computed<ApexOptions>(() => {
  const currentTheme = vuetifyTheme.current.value.colors
  const variableTheme = vuetifyTheme.current.value.variables

  const muted = `rgba(${hexToRgb(String(currentTheme['on-surface']))},${variableTheme['medium-emphasis-opacity']})`
  const borderColor = `rgba(${hexToRgb(String(variableTheme['border-color']))},${variableTheme['border-opacity']})`

  return {
    chart: {
      type: 'bar',
      stacked: true,
      parentHeightOffset: 0,
      toolbar: { show: false },
    },
    plotOptions: {
      bar: {
        borderRadius: 6,
        columnWidth: '45%',
        borderRadiusApplication: 'around',
        borderRadiusWhenStacked: 'all',
      },
    },
    dataLabels: { enabled: false },
    stroke: {
      width: 2,
      colors: [currentTheme.surface],
    },
    grid: {
      borderColor,
      strokeDashArray: 6,
      padding: { left: 0, right: 8 },
    },
    legend: {
      position: 'top',
      horizontalAlign: 'left',
      fontFamily: 'Public Sans',
      labels: { colors: muted },
    },
    colors: [currentTheme.success, currentTheme.warning],
    xaxis: {
      categories: deptLabels.value,
      axisBorder: { show: false },
      axisTicks: { show: false },
      labels: {
        style: {
          fontSize: '12px',
          colors: muted,
          fontFamily: 'Public Sans',
        },
      },
    },
    yaxis: {
      labels: {
        style: {
          fontSize: '12px',
          colors: muted,
          fontFamily: 'Public Sans',
        },
      },
    },
    tooltip: {
      shared: true,
      intersect: false,
    },
  }
})

const statusDonutOptions = computed<ApexOptions>(() => {
  const currentTheme = vuetifyTheme.current.value.colors
  const variableTheme = vuetifyTheme.current.value.variables
  const muted = `rgba(${hexToRgb(String(currentTheme['on-surface']))},${variableTheme['medium-emphasis-opacity']})`

  return {
    chart: {
      type: 'donut',
      parentHeightOffset: 0,
    },
    labels: ['Completed', 'Pending', 'Overdue'],
    colors: [currentTheme.success, currentTheme.warning, currentTheme.error],
    stroke: { width: 0 },
    legend: {
      position: 'bottom',
      fontFamily: 'Public Sans',
      labels: { colors: muted },
    },
    dataLabels: {
      enabled: true,
      formatter: val => `${Math.round(val)}%`,
    },
    plotOptions: {
      pie: {
        donut: {
          size: '72%',
          labels: {
            show: true,
            name: {
              show: true,
              fontFamily: 'Public Sans',
            },
            value: {
              show: true,
              fontFamily: 'Public Sans',
              formatter: value => `${Number(value).toFixed(0)}`,
            },
            total: {
              show: true,
              label: 'Total',
              formatter: () => `${props.stats.total}`,
            },
          },
        },
      },
    },
    responsive: [
      {
        breakpoint: 600,
        options: {
          chart: { height: 280 },
          legend: { position: 'bottom' },
        },
      },
    ],
  }
})

const topPolicyChartOptions = computed<ApexOptions>(() => {
  const currentTheme = vuetifyTheme.current.value.colors
  const variableTheme = vuetifyTheme.current.value.variables
  const muted = `rgba(${hexToRgb(String(currentTheme['on-surface']))},${variableTheme['medium-emphasis-opacity']})`
  const borderColor = `rgba(${hexToRgb(String(variableTheme['border-color']))},${variableTheme['border-opacity']})`

  return {
    chart: {
      type: 'bar',
      parentHeightOffset: 0,
      toolbar: { show: false },
    },
    plotOptions: {
      bar: {
        horizontal: true,
        borderRadius: 6,
        barHeight: '58%',
      },
    },
    dataLabels: { enabled: false },
    grid: {
      borderColor,
      strokeDashArray: 6,
      padding: { left: 0, right: 8 },
    },
    colors: [currentTheme.primary],
    xaxis: {
      categories: topPolicyLabels.value,
      labels: {
        style: {
          fontSize: '12px',
          colors: muted,
          fontFamily: 'Public Sans',
        },
      },
    },
    yaxis: {
      labels: {
        style: {
          fontSize: '12px',
          colors: muted,
          fontFamily: 'Public Sans',
        },
      },
    },
    tooltip: {
      y: {
        formatter: value => `${value} completions`,
      },
    },
  }
})
</script>

<template>
  <VRow class="match-height">
    <VCol cols="12">
      <VCard class="attestation-hero">
        <VCardText class="d-flex flex-wrap align-center justify-space-between gap-4 py-6">
          <div>
            <div class="text-overline text-white opacity-90">Attestation Intelligence</div>
            <h4 class="text-h4 text-white mb-1">Policy Attestation Dashboard</h4>
            <p class="mb-0 text-white opacity-90">
              Track compliance performance, completion bottlenecks, and overdue risk across departments.
            </p>
          </div>
          <VBtn
            color="white"
            class="text-primary"
            variant="flat"
            prepend-icon="tabler-file-check"
            :href="route('policies.attestations.index')"
          >
            Open My Attestations
          </VBtn>
        </VCardText>
      </VCard>
    </VCol>

    <VCol
      v-for="card in kpiCards"
      :key="card.title"
      cols="12"
      sm="6"
      md="3"
    >
      <VCard>
        <VCardText>
          <div class="d-flex align-center justify-space-between mb-3">
            <div class="text-body-2 text-medium-emphasis">{{ card.title }}</div>
            <VAvatar :color="card.color" variant="tonal" size="34" rounded>
              <VIcon :icon="card.icon" size="20" />
            </VAvatar>
          </div>
          <h4 class="text-h4 mb-1">{{ card.value }}</h4>
          <div class="text-caption text-medium-emphasis">{{ card.subtitle }}</div>
        </VCardText>
      </VCard>
    </VCol>

    <VCol cols="12" md="8">
      <VCard>
        <VCardItem>
          <VCardTitle>Department Completion Breakdown</VCardTitle>
          <VCardSubtitle>Completed vs remaining attestations per department</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VueApexCharts
            v-if="departmentRows.length"
            type="bar"
            :height="320"
            :options="departmentCompletionChartOptions"
            :series="[
              { name: 'Completed', data: deptCompletedSeries },
              { name: 'Remaining', data: deptRemainingSeries },
            ]"
          />
          <VAlert
            v-else
            type="info"
            variant="tonal"
          >
            No department breakdown available yet.
          </VAlert>
        </VCardText>
      </VCard>
    </VCol>

    <VCol cols="12" md="4">
      <VCard>
        <VCardItem>
          <VCardTitle>Status Distribution</VCardTitle>
          <VCardSubtitle>Current compliance mix</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VueApexCharts
            type="donut"
            :height="320"
            :options="statusDonutOptions"
            :series="statusSeries"
          />

          <div class="mt-3">
            <div class="d-flex justify-space-between text-caption mb-2">
              <span class="text-medium-emphasis">Overall Compliance</span>
              <span class="font-weight-bold" :class="`text-${complianceColor}`">{{ stats.compliancePct }}%</span>
            </div>
            <VProgressLinear
              :model-value="stats.compliancePct"
              :color="complianceColor"
              height="10"
              rounded
            />
          </div>
        </VCardText>
      </VCard>
    </VCol>

    <VCol cols="12" md="5">
      <VCard>
        <VCardItem>
          <VCardTitle>Top Policies by Recent Completion</VCardTitle>
          <VCardSubtitle>Most frequently acknowledged policies</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VueApexCharts
            v-if="topPolicies.length"
            type="bar"
            :height="300"
            :options="topPolicyChartOptions"
            :series="[{ name: 'Completions', data: topPolicySeries }]"
          />
          <VAlert
            v-else
            type="info"
            variant="tonal"
          >
            No recent completion data available yet.
          </VAlert>
        </VCardText>
      </VCard>
    </VCol>

    <VCol cols="12" md="7">
      <VCard>
        <VCardItem>
          <VCardTitle>Compliance by Department</VCardTitle>
          <VCardSubtitle>Completion rates and performance ranking</VCardSubtitle>
        </VCardItem>
        <VTable density="compact">
          <thead>
            <tr>
              <th>Department</th>
              <th class="text-right">Completed</th>
              <th class="text-right">Total</th>
              <th class="text-right">Compliance</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!byDepartment.length">
              <td colspan="4" class="text-center py-6 text-medium-emphasis">No data available.</td>
            </tr>
            <tr v-for="row in byDepartment" :key="row.department">
              <td>{{ row.department }}</td>
              <td class="text-right">{{ row.completed }}</td>
              <td class="text-right">{{ row.total }}</td>
              <td class="text-right">
                <VChip :color="deptColor(row.compliance_pct)" size="small" label>
                  {{ row.compliance_pct }}%
                </VChip>
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCard>
    </VCol>

    <VCol cols="12" md="7">
      <VCard>
        <VCardItem>
          <VCardTitle>Employees Pending / Overdue</VCardTitle>
          <VCardSubtitle>Showing up to 50 users requiring follow-up</VCardSubtitle>
        </VCardItem>
        <VTable density="comfortable">
          <thead>
            <tr>
              <th>Employee</th>
              <th>Policy</th>
              <th>Deadline</th>
              <th>Status</th>
              <th>Viewed</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!unacknowledged.length">
              <td colspan="5" class="text-center py-6 text-medium-emphasis">
                All employees are up to date.
              </td>
            </tr>
            <tr v-for="row in unacknowledged" :key="row.id">
              <td>
                <div class="font-weight-medium">{{ row.user_name ?? '—' }}</div>
                <div class="text-caption text-medium-emphasis">{{ row.email }}</div>
              </td>
              <td>{{ row.policy_name }}</td>
              <td :class="row.status === 'overdue' ? 'text-error font-weight-bold' : ''">
                {{ row.deadline ?? '—' }}
              </td>
              <td>
                <VChip
                  :color="row.status === 'overdue' ? 'error' : 'warning'"
                  size="small"
                  label
                  variant="tonal"
                >
                  {{ row.status }}
                </VChip>
              </td>
              <td>
                <VIcon
                  :icon="row.viewed_at ? 'tabler-eye-check' : 'tabler-eye-off'"
                  :color="row.viewed_at ? 'success' : 'secondary'"
                  size="18"
                />
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCard>
    </VCol>

    <VCol cols="12" md="5">
      <VCard>
        <VCardItem>
          <VCardTitle>Recent Completions</VCardTitle>
          <VCardSubtitle>Latest signatures submitted by employees</VCardSubtitle>
        </VCardItem>
        <VTable density="compact">
          <thead>
            <tr>
              <th>Employee</th>
              <th>Policy</th>
              <th>Signed At</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!recentCompleted.length">
              <td colspan="3" class="text-center py-6 text-medium-emphasis">No completions yet.</td>
            </tr>
            <tr v-for="(row, i) in recentCompleted.slice(0, 12)" :key="i">
              <td>{{ row.user_name ?? '—' }}</td>
              <td>{{ row.policy_name }}</td>
              <td>{{ row.signed_at }}</td>
            </tr>
          </tbody>
        </VTable>
      </VCard>
    </VCol>
  </VRow>
</template>

<style lang="scss" scoped>
@use "@core/scss/template/libs/apex-chart.scss";

.attestation-hero {
  background: linear-gradient(135deg, rgb(var(--v-theme-primary)) 0%, #5a4fd6 100%);
}
</style>
