<script setup lang="ts">
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  overview: {
    total: number; fleet_value: number; under_maintenance: number
    overdue_maintenance: number; expired_warranties: number
  }
  maintenance: {
    scheduled: number; in_progress: number; overdue: number
    completed_last_90_days: number; avg_actual_cost: number | null
  }
  incidents: {
    total_unresolved: number; total_last_30: number
    avg_repair_cost: number | null; monthly: { month: string; count: number }[]
  }
  top_risk_assets: {
    asset_uuid: string; asset_name: string; asset_tag: string
    risk_score: number; risk_level: string
  }[]
  utilization: {
    top_utilized: { name: string; asset_tag: string; utilization_rate: number }[]
    least_utilized: { name: string; asset_tag: string; utilization_rate: number }[]
    summary_stats: Record<string, unknown>; last_report_at: string | null
  }
  recent_activity: {
    type: string; asset_uuid: string; asset_name: string
    description: string; notes: string | null; occurred_at: string
  }[]
  assets_by_status: { asset_status: string; count: number }[]
  assets_by_category: { asset_category_id: number; count: number; category: { name: string } | null }[]
}>()

function formatCurrency(val: number | null) {
  if (val == null) return '—'
  return new Intl.NumberFormat('en-ZA', { style: 'currency', currency: 'ZAR', maximumFractionDigits: 0 }).format(val)
}

function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleString('en-ZA', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
}

function riskColor(level: string) {
  const map: Record<string, string> = { low: 'success', medium: 'warning', high: 'error', critical: 'error' }
  return map[level] ?? 'default'
}
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <h4 class="text-h4 font-weight-bold mb-1">{{ t('assets_dashboard.page_title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('assets_dashboard.description') }}</p>
      </div>
      <VBtn :href="route('assets.index')" variant="tonal" prepend-icon="tabler-boxes">
        {{ t('assets_dashboard.view_all_assets') }}
      </VBtn>
    </div>

    <VRow class="mb-6">
      <VCol cols="6" sm="4" md="2">
        <VCard elevation="0" border>
          <VCardText class="pa-4">
            <p class="text-caption text-medium-emphasis mb-1">{{ t('assets_dashboard.overview.total_assets') }}</p>
            <p class="text-h5 font-weight-bold mb-0">{{ overview.total }}</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="4" md="2">
        <VCard elevation="0" border>
          <VCardText class="pa-4">
            <p class="text-caption text-medium-emphasis mb-1">{{ t('assets_dashboard.overview.under_maintenance') }}</p>
            <p class="text-h5 font-weight-bold mb-0">{{ overview.under_maintenance }}</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="4" md="2">
        <VCard elevation="0" border>
          <VCardText class="pa-4">
            <p class="text-caption text-medium-emphasis mb-1">{{ t('assets_dashboard.overview.overdue_maintenance') }}</p>
            <p class="text-h5 font-weight-bold mb-0">{{ overview.overdue_maintenance }}</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="4" md="2">
        <VCard elevation="0" border>
          <VCardText class="pa-4">
            <p class="text-caption text-medium-emphasis mb-1">{{ t('assets_dashboard.overview.expired_warranties') }}</p>
            <p class="text-h5 font-weight-bold mb-0">{{ overview.expired_warranties }}</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="4">
        <VCard elevation="0" border color="primary">
          <VCardText class="pa-4">
            <p class="text-caption mb-1">{{ t('assets_dashboard.overview.fleet_value') }}</p>
            <p class="text-h5 font-weight-bold mb-0">{{ formatCurrency(overview.fleet_value) }}</p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow class="mb-6">
      <VCol cols="12" md="8">
        <VCard elevation="0" border height="100%">
          <VCardText>
            <div class="d-flex align-center justify-space-between mb-4">
              <p class="text-subtitle-1 font-weight-semibold mb-0">{{ t('assets_dashboard.incidents.title') }}</p>
              <div class="d-flex gap-3">
                <VChip size="small" color="error">{{ t('assets_dashboard.incidents.unresolved', { count: incidents.total_unresolved }) }}</VChip>
                <VChip size="small" color="warning">{{ t('assets_dashboard.incidents.last_30_days', { count: incidents.total_last_30 }) }}</VChip>
              </div>
            </div>
            <div class="d-flex align-end gap-1" style="height:80px;">
              <template v-for="month in incidents.monthly" :key="month.month">
                <VTooltip :text="`${month.month}: ${month.count}`">
                  <template #activator="{ props: tp }">
                    <div v-bind="tp" class="flex-1 rounded-t bg-error"
                      :style="{ height: month.count > 0 ? Math.max(8, (month.count / Math.max(...incidents.monthly.map(x => x.count), 1)) * 80) + 'px' : '2px' }" />
                  </template>
                </VTooltip>
              </template>
            </div>
            <div class="d-flex justify-space-between mt-1 text-caption text-medium-emphasis">
              <span>{{ incidents.monthly[0]?.month }}</span>
              <span>{{ incidents.monthly[incidents.monthly.length - 1]?.month }}</span>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard elevation="0" border height="100%">
          <VCardText>
            <p class="text-subtitle-1 font-weight-semibold mb-4">{{ t('assets_dashboard.maintenance.title') }}</p>
            <div class="d-flex flex-column gap-3">
              <div class="d-flex justify-space-between">
                <span class="text-body-2">{{ t('assets_dashboard.maintenance.scheduled') }}</span>
                <VChip size="small" color="info">{{ maintenance.scheduled }}</VChip>
              </div>
              <div class="d-flex justify-space-between">
                <span class="text-body-2">{{ t('assets_dashboard.maintenance.in_progress') }}</span>
                <VChip size="small" color="warning">{{ maintenance.in_progress }}</VChip>
              </div>
              <div class="d-flex justify-space-between">
                <span class="text-body-2">{{ t('assets_dashboard.maintenance.overdue') }}</span>
                <VChip size="small" color="error">{{ maintenance.overdue }}</VChip>
              </div>
              <div class="d-flex justify-space-between">
                <span class="text-body-2">{{ t('assets_dashboard.maintenance.completed_90_days') }}</span>
                <VChip size="small" color="success">{{ maintenance.completed_last_90_days }}</VChip>
              </div>
              <VDivider />
              <div class="d-flex justify-space-between">
                <span class="text-body-2">{{ t('assets_dashboard.maintenance.avg_cost_90_days') }}</span>
                <span class="text-body-2 font-weight-medium">{{ formatCurrency(maintenance.avg_actual_cost) }}</span>
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow class="mb-6">
      <VCol cols="12" md="6">
        <VCard elevation="0" border>
          <VCardText>
            <p class="text-subtitle-1 font-weight-semibold mb-4">{{ t('assets_dashboard.top_risk_assets.title') }}</p>
            <VTable density="compact">
              <thead>
                <tr>
                  <th>{{ t('assets_dashboard.top_risk_assets.table.asset') }}</th>
                  <th class="text-center">{{ t('assets_dashboard.top_risk_assets.table.score') }}</th>
                  <th class="text-center">{{ t('assets_dashboard.top_risk_assets.table.level') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="risk in top_risk_assets" :key="risk.asset_uuid" class="cursor-pointer" @click="$inertia.visit(route('assets.show', risk.asset_uuid))">
                  <td>
                    <p class="mb-0 font-weight-medium">{{ risk.asset_name }}</p>
                    <p class="text-caption text-medium-emphasis mb-0">{{ risk.asset_tag }}</p>
                  </td>
                  <td class="text-center">{{ risk.risk_score }}</td>
                  <td class="text-center"><VChip size="x-small" :color="riskColor(risk.risk_level)">{{ risk.risk_level }}</VChip></td>
                </tr>
                <tr v-if="!top_risk_assets.length"><td colspan="3" class="text-center text-medium-emphasis">{{ t('assets_dashboard.top_risk_assets.no_data') }}</td></tr>
              </tbody>
            </VTable>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="6">
        <VCard elevation="0" border>
          <VCardText>
            <p class="text-subtitle-1 font-weight-semibold mb-4">{{ t('assets_dashboard.assets_by_status.title') }}</p>
            <div class="d-flex flex-column gap-2">
              <div v-for="status in assets_by_status" :key="status.asset_status" class="d-flex align-center gap-3">
                <span class="text-body-2 text-capitalize" style="min-width:140px">{{ status.asset_status.replace('_', ' ') }}</span>
                <VProgressLinear :model-value="(status.count / overview.total) * 100" height="8" rounded color="primary" class="flex-1" />
                <span class="text-body-2 font-weight-medium" style="min-width:32px;text-align:right">{{ status.count }}</span>
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard elevation="0" border>
      <VCardText>
        <p class="text-subtitle-1 font-weight-semibold mb-4">{{ t('assets_dashboard.recent_activity.title') }}</p>
        <VTimeline density="compact" side="end" truncate-line="both">
          <VTimelineItem v-for="(activity, i) in recent_activity" :key="i" dot-color="primary" size="x-small">
            <div class="d-flex justify-space-between">
              <div>
                <p class="mb-0 font-weight-medium">{{ activity.description }}</p>
                <p class="text-caption text-medium-emphasis mb-0">{{ activity.asset_name }}</p>
              </div>
              <span class="text-caption text-medium-emphasis">{{ formatDate(activity.occurred_at) }}</span>
            </div>
          </VTimelineItem>
          <p v-if="!recent_activity.length" class="text-center text-medium-emphasis">{{ t('assets_dashboard.recent_activity.no_activity') }}</p>
        </VTimeline>
      </VCardText>
    </VCard>
  </div>
</template>
