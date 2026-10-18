<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import VueApexCharts from 'vue3-apexcharts'

const { t } = useI18n()
const route = ziggyRoute

type ByType = { expenditure_type: string; n: number; allocated: number }
type RecentRow = {
  id: number; code: string; name: string; type: string | null
  allocated: number; status: string; financial_year: string | null
}

const props = defineProps<{
  stats: {
    budgets: number; allocated: number; reserved: number; committed: number
    actual: number; available: number; utilisation: number
    overruns: number; blocked: number; my_approvals: number; open_alerts: number; rules: number
  }
  byType: ByType[]
  recent: RecentRow[]
}>()

function money(v: number | null) {
  return v == null ? '—' : new Intl.NumberFormat(undefined, { style: 'currency', currency: 'ZAR' }).format(v)
}
const statusColor: Record<string, string> = {
  draft: 'grey', pending: 'warning', approved: 'success', active: 'success',
  closed: 'grey', rejected: 'error',
}

// Optional donut of allocation by expenditure type.
const donutSeries = props.byType.map(type => Number(type.allocated) || 0)
const donutLabels = props.byType.map(type => type.expenditure_type ?? 'unknown')
const donutOptions = {
  labels: donutLabels,
  legend: { position: 'bottom' },
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div class="d-flex align-center gap-3">
        <VAvatar color="primary" variant="tonal" size="48" rounded>
          <VIcon icon="tabler-wallet" size="26" />
        </VAvatar>
        <div>
          <h4 class="text-h4 mb-0">{{ t('budgets_dashboard.page_title') }}</h4>
          <p class="text-caption text-medium-emphasis mb-0">{{ t('budgets_dashboard.description') }}</p>
        </div>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <VBtn variant="tonal" prepend-icon="tabler-list" @click="router.get(route('budgets.index'))">
          {{ t('budgets_dashboard.budgets') }}
        </VBtn>
        <VBtn variant="tonal" prepend-icon="tabler-chart-pie" @click="router.get(route('budgets.insights.analytics'))">
          {{ t('budgets_dashboard.analytics') }}
        </VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="router.get(route('budgets.create'))">
          {{ t('budgets_dashboard.new_budget') }}
        </VBtn>
      </div>
    </div>

    <!--
      Each chip goes somewhere. A dashboard that reports "3 waiting" and offers no way to reach
      them is one people read once and stop opening.
    -->
    <div v-if="stats.my_approvals || stats.open_alerts || stats.blocked" class="d-flex flex-wrap gap-2 mb-4">
      <VChip
        v-if="stats.my_approvals" color="warning" variant="tonal" prepend-icon="tabler-checkbox" link
        @click="router.get(route('budgets.approvals.index'))"
      >
        {{ t('budgets_dashboard.my_approvals', { count: stats.my_approvals }) }}
      </VChip>
      <VChip
        v-if="stats.blocked" color="error" variant="tonal" prepend-icon="tabler-hand-stop" link
        @click="router.get(route('budgets.tracking.index'))"
      >
        {{ t('budgets_dashboard.blocked', { count: stats.blocked }) }}
      </VChip>
      <VChip
        v-if="stats.open_alerts" color="warning" variant="tonal" prepend-icon="tabler-alert-triangle" link
        @click="router.get(route('budgets.tracking.index'))"
      >
        {{ t('budgets_dashboard.open_alerts', { count: stats.open_alerts }) }}
      </VChip>
    </div>

    <!-- Said once, on the page most likely to be open when somebody wonders why nothing warns them. -->
    <VAlert
      v-if="!stats.rules" type="info" variant="tonal" density="compact" class="mb-4"
      :text="t('budgets_dashboard.no_rules_notice')"
    >
      <template #append>
        <VBtn size="small" variant="text" @click="router.get(route('budgets.tracking.index'))">
          {{ t('budgets_dashboard.set_up_alerts') }}
        </VBtn>
      </template>
    </VAlert>

    <VRow class="mb-2">
      <VCol cols="12" sm="6" md="3">
        <VCard border>
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('budgets_dashboard.kpis.budgets') }}</div>
            <div class="text-h4">{{ stats.budgets }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard border>
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('budgets_dashboard.kpis.allocated') }}</div>
            <div class="text-h5">{{ money(stats.allocated) }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard border>
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('budgets_dashboard.kpis.available') }}</div>
            <div class="text-h5">{{ money(stats.available) }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard border>
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('budgets_dashboard.kpis.reserved') }}</div>
            <div class="text-h5">{{ money(stats.reserved) }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
    <VRow class="mb-2">
      <VCol cols="12" sm="6" md="3">
        <VCard border>
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('budgets_dashboard.kpis.committed') }}</div>
            <div class="text-h5">{{ money(stats.committed) }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard border>
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('budgets_dashboard.kpis.actual') }}</div>
            <div class="text-h5">{{ money(stats.actual) }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard border>
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('budgets_dashboard.kpis.utilisation') }}</div>
            <div class="text-h4">{{ Number(stats.utilisation).toFixed(1) }}%</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard border>
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ t('budgets_dashboard.kpis.overruns') }}</div>
            <div class="text-h4" :class="stats.overruns > 0 ? 'text-error' : ''">{{ stats.overruns }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow>
      <VCol cols="12" md="5">
        <VCard border>
          <VCardTitle>{{ t('budgets_dashboard.allocation_by_type.title') }}</VCardTitle>
          <VCardText>
            <VueApexCharts v-if="donutSeries.length" type="donut" height="280" :options="donutOptions" :series="donutSeries" />
            <div v-else class="text-center text-medium-emphasis py-6">{{ t('budgets_dashboard.allocation_by_type.no_data') }}</div>
            <VTable class="mt-2">
              <thead>
                <tr>
                  <th>{{ t('budgets_dashboard.allocation_by_type.type') }}</th>
                  <th class="text-right">{{ t('budgets_dashboard.allocation_by_type.count') }}</th>
                  <th class="text-right">{{ t('budgets_dashboard.allocation_by_type.allocated') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="!byType.length">
                  <td colspan="3" class="text-center text-medium-emphasis py-4">{{ t('budgets_dashboard.allocation_by_type.no_data') }}</td>
                </tr>
                <tr v-for="type in byType" :key="type.expenditure_type">
                  <td><VChip size="small" variant="tonal">{{ type.expenditure_type }}</VChip></td>
                  <td class="text-right">{{ type.n }}</td>
                  <td class="text-right">{{ money(type.allocated) }}</td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="7">
        <VCard border>
          <VCardTitle>{{ t('budgets_dashboard.recent_budgets.title') }}</VCardTitle>
          <VTable>
            <thead>
              <tr>
                <th>{{ t('budgets_dashboard.recent_budgets.code') }}</th>
                <th>{{ t('budgets_dashboard.recent_budgets.name') }}</th>
                <th>{{ t('budgets_dashboard.recent_budgets.fy') }}</th>
                <th>{{ t('budgets_dashboard.recent_budgets.type') }}</th>
                <th class="text-right">{{ t('budgets_dashboard.recent_budgets.allocated') }}</th>
                <th>{{ t('budgets_dashboard.recent_budgets.status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!recent.length">
                <td colspan="6" class="text-center text-medium-emphasis py-6">{{ t('budgets_dashboard.recent_budgets.empty') }}</td>
              </tr>
              <tr v-for="budget in recent" :key="budget.id" style="cursor:pointer" @click="router.get(route('budgets.show', budget.uuid))">
                <td class="font-weight-medium">{{ budget.code }}</td>
                <td>{{ budget.name }}</td>
                <td>{{ budget.financial_year ?? '—' }}</td>
                <td>
                  <VChip v-if="budget.type" size="small" variant="tonal">{{ budget.type }}</VChip>
                  <span v-else>—</span>
                </td>
                <td class="text-right">{{ money(budget.allocated) }}</td>
                <td>
                  <VChip size="small" :color="statusColor[budget.status]" variant="tonal">{{ budget.status }}</VChip>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
