<script setup lang="ts">
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { computed } from 'vue'

const { t } = useI18n()
const route = ziggyRoute

type UtilItem = {
  name: string
  asset_tag: string | null
  asset_uuid: string
  utilization_rate: number
}

const props = withDefaults(defineProps<{
  top_utilized?: UtilItem[]
  least_utilized?: UtilItem[]
  summary_stats?: {
    avg_utilization_rate?: number
    median_utilization_rate?: number
    total_operating_hours?: number
    total_downtime_hours?: number
    total_assets?: number
  }
  last_report_at?: string | null
}>(), {
  top_utilized: () => [],
  least_utilized: () => [],
  summary_stats: () => ({
    avg_utilization_rate: 0,
    median_utilization_rate: 0,
    total_operating_hours: 0,
    total_downtime_hours: 0,
    total_assets: 0,
  }),
  last_report_at: null,
})

// Computed sécurisés pour éviter les erreurs
const safeSummary = computed(() => props.summary_stats || {
  avg_utilization_rate: 0,
  median_utilization_rate: 0,
  total_operating_hours: 0,
  total_downtime_hours: 0,
  total_assets: 0,
})

const safeTopUtilized = computed(() => props.top_utilized || [])
const safeLeastUtilized = computed(() => props.least_utilized || [])

function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

function utilizationColor(rate: number) {
  if (rate >= 80) return 'success'
  if (rate >= 50) return 'warning'
  return 'error'
}
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <h4 class="text-h4 font-weight-bold mb-1">{{ t('assets_utilization_dashboard_index.page_title') }}</h4>
        <p class="text-medium-emphasis mb-0">
          {{ t('assets_utilization_dashboard_index.description') }}
          <span v-if="last_report_at"> · {{ formatDate(last_report_at) }}</span>
        </p>
      </div>
      <VBtn :href="route('assets.utilization-reports.index')" variant="tonal" prepend-icon="tabler-list">
        {{ t('assets_utilization_dashboard_index.all_reports') }}
      </VBtn>
    </div>

    <VAlert v-if="!last_report_at" type="info" class="mb-6">
      {{ t('assets_utilization_dashboard_index.no_reports_alert') }} <a :href="route('assets.utilization-reports.create')" class="text-white font-weight-bold">{{ t('assets_utilization_dashboard_index.generate_link') }}</a>.
    </VAlert>

    <template v-if="last_report_at">
      <VRow class="mb-6">
        <VCol cols="6" sm="3">
          <VCard elevation="0" border>
            <VCardText class="pa-4">
              <p class="text-caption text-medium-emphasis mb-1">{{ t('assets_utilization_dashboard_index.stats.total_assets') }}</p>
              <p class="text-h5 font-weight-bold mb-0">{{ safeSummary.total_assets ?? 0 }}</p>
            </VCardText>
          </VCard>
        </VCol>
        <VCol cols="6" sm="3">
          <VCard elevation="0" border>
            <VCardText class="pa-4">
              <p class="text-caption text-medium-emphasis mb-1">{{ t('assets_utilization_dashboard_index.stats.avg_utilization') }}</p>
              <p class="text-h5 font-weight-bold mb-0">{{ (safeSummary.avg_utilization_rate ?? 0).toFixed(1) }}%</p>
            </VCardText>
          </VCard>
        </VCol>
        <VCol cols="6" sm="3">
          <VCard elevation="0" border>
            <VCardText class="pa-4">
              <p class="text-caption text-medium-emphasis mb-1">{{ t('assets_utilization_dashboard_index.stats.total_op_hours') }}</p>
              <p class="text-h5 font-weight-bold mb-0">{{ (safeSummary.total_operating_hours ?? 0).toFixed(0) }}</p>
            </VCardText>
          </VCard>
        </VCol>
        <VCol cols="6" sm="3">
          <VCard elevation="0" border>
            <VCardText class="pa-4">
              <p class="text-caption text-medium-emphasis mb-1">{{ t('assets_utilization_dashboard_index.stats.total_downtime_hrs') }}</p>
              <p class="text-h5 font-weight-bold mb-0">{{ (safeSummary.total_downtime_hours ?? 0).toFixed(0) }}</p>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <VRow>
        <VCol cols="12" md="6">
          <VCard elevation="0" border>
            <VCardText>
              <p class="text-subtitle-1 font-weight-semibold mb-4">{{ t('assets_utilization_dashboard_index.top_utilized') }}</p>
              <div v-for="(a, i) in safeTopUtilized" :key="i" class="d-flex align-center gap-3 mb-3">
                <span class="text-caption text-medium-emphasis" style="min-width:20px">{{ i + 1 }}.</span>
                <div class="flex-1">
                  <p class="mb-0 text-body-2 font-weight-medium">{{ a.name }}</p>
                  <p class="mb-0 text-caption text-medium-emphasis">{{ a.asset_tag }}</p>
                </div>
                <VChip size="small" :color="utilizationColor(a.utilization_rate)">{{ a.utilization_rate.toFixed(1) }}%</VChip>
              </div>
              <p v-if="!safeTopUtilized.length" class="text-center text-medium-emphasis">{{ t('assets_utilization_dashboard_index.no_data') }}</p>
            </VCardText>
          </VCard>
        </VCol>

        <VCol cols="12" md="6">
          <VCard elevation="0" border>
            <VCardText>
              <p class="text-subtitle-1 font-weight-semibold mb-4">{{ t('assets_utilization_dashboard_index.least_utilized') }}</p>
              <div v-for="(a, i) in safeLeastUtilized" :key="i" class="d-flex align-center gap-3 mb-3">
                <span class="text-caption text-medium-emphasis" style="min-width:20px">{{ i + 1 }}.</span>
                <div class="flex-1">
                  <p class="mb-0 text-body-2 font-weight-medium">{{ a.name }}</p>
                  <p class="mb-0 text-caption text-medium-emphasis">{{ a.asset_tag }}</p>
                </div>
                <VChip size="small" :color="utilizationColor(a.utilization_rate)">{{ a.utilization_rate.toFixed(1) }}%</VChip>
              </div>
              <p v-if="!safeLeastUtilized.length" class="text-center text-medium-emphasis">{{ t('assets_utilization_dashboard_index.no_data') }}</p>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </template>
  </div>
</template>
