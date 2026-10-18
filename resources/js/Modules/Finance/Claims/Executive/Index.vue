<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import Chart from 'chart.js/auto'

const { t } = useI18n()

interface Kpi {
  total_spend_ytd:   number
  total_claims_ytd:  number
  avg_claim_value:   number
  approval_rate:     number
  avg_approval_days: number
  policy_compliance: number
  pending_count:     number
  high_risk_count:   number
}
interface MonthlyRow { month: string; total: number; count: number }
interface CategoryRow { category: string; total: number; count: number }
interface DeptRow     { department: string; total: number; count: number }
interface Claimant    { employee_name: string; claim_count: number; total_amount: number; avg_amount: number }
interface AiInsights  { summary: string; flags: string[]; recommendations: string[]; generated_at: string }

const props = defineProps<{
  kpis:         Kpi
  monthlySpend: MonthlyRow[]
  byCategory:   CategoryRow[]
  byDepartment: DeptRow[]
  statusDist:   Record<string, number>
  riskDist:     Record<string, number>
  topClaimants: Claimant[]
  aiInsights:   AiInsights
  year:         number
}>()

const spendRef   = ref<HTMLCanvasElement | null>(null)
const catRef     = ref<HTMLCanvasElement | null>(null)
const deptRef    = ref<HTMLCanvasElement | null>(null)
const statusRef  = ref<HTMLCanvasElement | null>(null)
const riskRef    = ref<HTMLCanvasElement | null>(null)

const charts: Chart[] = []

const PALETTE = [
  '#6366f1','#8b5cf6','#ec4899','#f97316','#eab308',
  '#22c55e','#14b8a6','#06b6d4','#3b82f6','#a78bfa',
]

function buildCharts() {
  if (spendRef.value) {
    charts.push(new Chart(spendRef.value, {
      type: 'bar',
      data: {
        labels: props.monthlySpend.map(r => r.month),
        datasets: [{
          label: t('claims_executive.charts.approved_spend'),
          data:  props.monthlySpend.map(r => r.total),
          backgroundColor: 'rgba(99,102,241,0.75)',
          borderColor: '#6366f1',
          borderWidth: 1,
          borderRadius: 6,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => 'R ' + Number(ctx.raw).toLocaleString('en-ZA', { minimumFractionDigits: 0 }) } } },
        scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { callback: v => 'R ' + Number(v).toLocaleString('en-ZA', { notation: 'compact' }) } } },
      },
    }))
  }

  if (catRef.value && props.byCategory.length) {
    charts.push(new Chart(catRef.value, {
      type: 'bar',
      data: {
        labels: props.byCategory.map(r => r.category),
        datasets: [{
          label: t('claims_executive.charts.approved_spend'),
          data: props.byCategory.map(r => r.total),
          backgroundColor: PALETTE.slice(0, props.byCategory.length),
          borderRadius: 4,
        }],
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => 'R ' + Number(ctx.raw).toLocaleString('en-ZA', { minimumFractionDigits: 0 }) } } },
        scales: { x: { beginAtZero: true, ticks: { callback: v => 'R ' + Number(v).toLocaleString('en-ZA', { notation: 'compact' }) } }, y: { grid: { display: false } } },
      },
    }))
  }

  if (deptRef.value && props.byDepartment.length) {
    charts.push(new Chart(deptRef.value, {
      type: 'bar',
      data: {
        labels: props.byDepartment.map(r => r.department),
        datasets: [{
          label: t('claims_executive.charts.approved_spend'),
          data: props.byDepartment.map(r => r.total),
          backgroundColor: PALETTE.map(c => c + 'cc'),
          borderRadius: 4,
        }],
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => 'R ' + Number(ctx.raw).toLocaleString('en-ZA', { minimumFractionDigits: 0 }) } } },
        scales: { x: { beginAtZero: true, ticks: { callback: v => 'R ' + Number(v).toLocaleString('en-ZA', { notation: 'compact' }) } }, y: { grid: { display: false } } },
      },
    }))
  }

  const statusLabels = Object.keys(props.statusDist)
  if (statusRef.value && statusLabels.length) {
    const statusColors: Record<string, string> = { draft:'#94a3b8', submitted:'#3b82f6', under_review:'#f59e0b', approved:'#22c55e', rejected:'#ef4444', paid:'#8b5cf6', cancelled:'#64748b' }
    charts.push(new Chart(statusRef.value, {
      type: 'doughnut',
      data: {
        labels: statusLabels.map(s => s.replace('_', ' ')),
        datasets: [{ data: Object.values(props.statusDist), backgroundColor: statusLabels.map(s => statusColors[s] ?? '#94a3b8'), borderWidth: 2 }],
      },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { boxWidth: 12 } } } },
    }))
  }

  const riskLabels = Object.keys(props.riskDist)
  if (riskRef.value && riskLabels.length) {
    const riskColors: Record<string, string> = { low:'#22c55e', medium:'#f59e0b', high:'#f97316', critical:'#ef4444' }
    charts.push(new Chart(riskRef.value, {
      type: 'doughnut',
      data: {
        labels: riskLabels,
        datasets: [{ data: Object.values(props.riskDist), backgroundColor: riskLabels.map(r => riskColors[r] ?? '#94a3b8'), borderWidth: 2 }],
      },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { boxWidth: 12 } } } },
    }))
  }
}

onMounted(() => buildCharts())
onUnmounted(() => charts.forEach(c => c.destroy()))

const fmt     = (n: number)  => 'R ' + n.toLocaleString('en-ZA', { minimumFractionDigits: 0 })
const fmtFull = (n: number)  => 'R ' + n.toLocaleString('en-ZA', { minimumFractionDigits: 2 })

const kpiCards = computed(()=> [
  { label: t('claims_executive.kpis.total_spend_ytd'),   key: 'total_spend_ytd',   icon: 'tabler-cash',        color: 'indigo',  format: 'currency' },
  { label: t('claims_executive.kpis.total_claims_ytd'),  key: 'total_claims_ytd',  icon: 'tabler-files',color: 'blue',    format: 'number'   },
  { label: t('claims_executive.kpis.avg_claim_value'),   key: 'avg_claim_value',   icon: 'tabler-calculator',   color: 'cyan',    format: 'currency' },
  { label: t('claims_executive.kpis.approval_rate'),     key: 'approval_rate',     icon: 'tabler-circle-check',         color: 'success', format: 'percent'  },
  { label: t('claims_executive.kpis.avg_approval_days'), key: 'avg_approval_days', icon: 'tabler-clock-bolt',           color: 'warning', format: 'days'     },
  { label: t('claims_executive.kpis.policy_compliance'), key: 'policy_compliance', icon: 'tabler-shield-check',         color: 'teal',    format: 'percent'  },
  { label: t('claims_executive.kpis.pending_approval'),  key: 'pending_count',     icon: 'tabler-hourglass',           color: 'amber',   format: 'number'   },
  { label: t('claims_executive.kpis.high_critical_risk'),key: 'high_risk_count',   icon: 'tabler-alert-circle',         color: 'error',   format: 'number'   },
])

function kpiValue(key: string, format: string): string {
  const v = props.kpis[key as keyof Kpi] ?? 0
  if (format === 'currency') return fmtFull(v)
  if (format === 'percent')  return v + '%'
  if (format === 'days')     return v + ' days'
  return String(v)
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-5 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('claims_executive.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('claims_executive.year_performance', { year }) }}</p>
      </div>
      <div class="d-flex ga-2 flex-wrap">
        <VBtn variant="outlined" prepend-icon="tabler-chart-bar" :href="route('claims.reports.index')">{{ t('claims_executive.reports') }}</VBtn>
        <VBtn variant="outlined" prepend-icon="tabler-robot" :href="route('claims.ai.index')">{{ t('claims_executive.ai_intelligence') }}</VBtn>
        <VBtn variant="outlined" prepend-icon="tabler-layout-dashboard" :href="route('claims.dashboard')">{{ t('claims_executive.dashboard') }}</VBtn>
      </div>
    </div>

    <VRow dense class="mb-5">
      <VCol v-for="kpi in kpiCards" :key="kpi.key" cols="6" sm="4" md="3">
        <VCard variant="tonal" :color="kpi.color" height="100%">
          <VCardText class="pa-4">
            <div class="d-flex align-center justify-space-between mb-2">
              <VIcon :color="kpi.color" size="28">{{ kpi.icon }}</VIcon>
            </div>
            <div class="text-h6 font-weight-bold">{{ kpiValue(kpi.key, kpi.format) }}</div>
            <div class="text-caption text-medium-emphasis mt-1">{{ kpi.label }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow dense class="mb-4">
      <VCol cols="12" md="8">
        <VCard height="300">
          <VCardTitle class="text-body-2 font-weight-bold pa-4 pb-2">
            <VIcon class="mr-1" size="18" color="indigo">tabler-chart-bar</VIcon>
            {{ t('claims_executive.charts.monthly_spend') }}
          </VCardTitle>
          <VCardText class="pa-3 pt-0">
            <div style="height:230px">
              <canvas ref="spendRef" />
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="4">
        <VCard height="300">
          <VCardTitle class="text-body-2 font-weight-bold pa-4 pb-2">
            <VIcon class="mr-1" size="18" color="blue">tabler-chart-donut</VIcon>
            {{ t('claims_executive.charts.status_distribution') }}
          </VCardTitle>
          <VCardText class="pa-3 pt-0">
            <div style="height:230px">
              <canvas v-if="Object.keys(statusDist).length" ref="statusRef" />
              <div v-else class="d-flex align-center justify-center h-100 text-medium-emphasis text-caption">{{ t('claims_executive.charts.no_data') }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow dense class="mb-4">
      <VCol cols="12" md="6">
        <VCard>
          <VCardTitle class="text-body-2 font-weight-bold pa-4 pb-2">
            <VIcon class="mr-1" size="18" color="purple">tabler-shapes</VIcon>
            {{ t('claims_executive.charts.spend_by_category') }}
          </VCardTitle>
          <VCardText class="pa-3 pt-0">
            <div :style="{ height: byCategory.length > 5 ? '260px' : '180px' }">
              <canvas v-if="byCategory.length" ref="catRef" />
              <div v-else class="d-flex align-center justify-center h-100 text-medium-emphasis text-caption">{{ t('claims_executive.charts.no_data') }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="6">
        <VCard>
          <VCardTitle class="text-body-2 font-weight-bold pa-4 pb-2">
            <VIcon class="mr-1" size="18" color="teal">tabler-building</VIcon>
            {{ t('claims_executive.charts.spend_by_department') }}
          </VCardTitle>
          <VCardText class="pa-3 pt-0">
            <div :style="{ height: byDepartment.length > 5 ? '260px' : '180px' }">
              <canvas v-if="byDepartment.length" ref="deptRef" />
              <div v-else class="d-flex align-center justify-center h-100 text-medium-emphasis text-caption">{{ t('claims_executive.charts.no_data') }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow dense class="mb-4">
      <VCol cols="12" md="4">
        <VCard height="300">
          <VCardTitle class="text-body-2 font-weight-bold pa-4 pb-2">
            <VIcon class="mr-1" size="18" color="orange">tabler-alert-circle</VIcon>
            {{ t('claims_executive.charts.risk_distribution') }}
          </VCardTitle>
          <VCardText class="pa-3 pt-0">
            <div style="height:230px">
              <canvas v-if="Object.keys(riskDist).length" ref="riskRef" />
              <div v-else class="d-flex align-center justify-center h-100 text-medium-emphasis text-caption">{{ t('claims_executive.charts.no_risk_data') }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="8">
        <VCard height="300">
          <VCardTitle class="text-body-2 font-weight-bold pa-4 pb-2">
            <VIcon class="mr-1" size="18" color="deep-purple">tabler-user-star</VIcon>
            {{ t('claims_executive.charts.top_claimants') }}
          </VCardTitle>
          <VCardText class="pa-0" style="overflow-y:auto;max-height:240px">
            <VTable density="compact">
              <thead>
                <tr>
                  <th>{{ t('claims_executive.charts.table.rank') }}</th>
                  <th>{{ t('claims_executive.charts.table.employee') }}</th>
                  <th class="text-right">{{ t('claims_executive.charts.table.claims') }}</th>
                  <th class="text-right">{{ t('claims_executive.charts.table.total_spend') }}</th>
                  <th class="text-right">{{ t('claims_executive.charts.table.avg_claim') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(claimant, i) in topClaimants" :key="i">
                  <td class="text-caption text-medium-emphasis">{{ i + 1 }}</td>
                  <td class="font-weight-medium text-body-2">{{ claimant.employee_name }}</td>
                  <td class="text-right text-caption">{{ claimant.claim_count }}</td>
                  <td class="text-right font-weight-bold text-body-2">{{ fmt(claimant.total_amount) }}</td>
                  <td class="text-right text-caption text-medium-emphasis">{{ fmt(claimant.avg_amount) }}</td>
                </tr>
                <tr v-if="!topClaimants.length">
                  <td colspan="5" class="text-center text-caption text-medium-emphasis pa-4">{{ t('claims_executive.charts.no_data') }}</td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard class="mb-4" border>
      <VCardTitle class="d-flex align-center pa-4 pb-2">
        <VIcon color="deep-purple" class="mr-2">tabler-robot</VIcon>
        <span class="text-body-1 font-weight-bold">{{ t('claims_executive.ai_adviser.title') }}</span>
        <VChip class="ml-3" size="x-small" color="deep-purple" variant="tonal">{{ t('claims_executive.ai_adviser.generated', { date: aiInsights.generated_at }) }}</VChip>
        <VSpacer />
        <VBtn size="small" variant="outlined" :href="route('claims.ai.index')" prepend-icon="tabler-brain">{{ t('claims_executive.ai_adviser.full_analysis') }}</VBtn>
      </VCardTitle>
      <VDivider />
      <VCardText class="pa-4">
        <VAlert type="info" variant="tonal" class="mb-4" icon="tabler-info-circle">
          {{ aiInsights.summary }}
        </VAlert>

        <VRow dense>
          <VCol v-if="aiInsights.flags.length" cols="12" md="6">
            <p class="text-body-2 font-weight-bold mb-2">
              <VIcon color="warning" size="18" class="mr-1">tabler-alert-triangle</VIcon>
              {{ t('claims_executive.ai_adviser.areas_of_concern') }}
            </p>
            <VList density="compact" class="pa-0">
              <VListItem v-for="(flag, i) in aiInsights.flags" :key="i"
                :prepend-icon="'tabler-alert-circle'"
                class="px-0"
              >
                <VListItemTitle class="text-body-2 text-wrap">{{ flag }}</VListItemTitle>
              </VListItem>
            </VList>
          </VCol>

          <VCol v-if="aiInsights.recommendations.length" cols="12" md="6">
            <p class="text-body-2 font-weight-bold mb-2">
              <VIcon color="success" size="18" class="mr-1">tabler-bulb</VIcon>
              {{ t('claims_executive.ai_adviser.recommendations') }}
            </p>
            <VList density="compact" class="pa-0">
              <VListItem v-for="(rec, i) in aiInsights.recommendations" :key="i"
                :prepend-icon="'tabler-circle-check'"
                class="px-0"
              >
                <VListItemTitle class="text-body-2 text-wrap">{{ rec }}</VListItemTitle>
              </VListItem>
            </VList>
          </VCol>

          <VCol v-if="!aiInsights.flags.length && !aiInsights.recommendations.length" cols="12">
            <VAlert type="success" variant="tonal" icon="tabler-circle-check">
              {{ t('claims_executive.ai_adviser.no_concerns') }}
            </VAlert>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>
  </div>
</template>
