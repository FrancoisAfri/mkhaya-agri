<script setup lang="ts">
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Run = {
  id: number
  compliance_rate: number
  pass_count: number
  fail_count: number
  overdue_count: number
  run_at: string
}

const props = defineProps<{
  latest_run: Run | null
  trend: Run[]
}>()

function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}

function rateColor(rate: number) {
  if (rate >= 90) return 'success'
  if (rate >= 70) return 'warning'
  return 'error'
}
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <h4 class="text-h4 font-weight-bold mb-1">{{ t('assets_compliance_dashboard_index.page_title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('assets_compliance_dashboard_index.description') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VBtn :href="route('assets.compliance-rules.index')" variant="tonal" prepend-icon="tabler-settings">{{ t('assets_compliance_dashboard_index.rules') }}</VBtn>
        <VBtn :href="route('assets.compliance-runs.index')" variant="tonal" prepend-icon="tabler-list">{{ t('assets_compliance_dashboard_index.runs') }}</VBtn>
        <VBtn :href="route('assets.compliance-runs.index')" color="primary" prepend-icon="tabler-player-play">{{ t('assets_compliance_dashboard_index.run_check') }}</VBtn>
      </div>
    </div>

    <VAlert v-if="!latest_run" type="info" class="mb-6">
      {{ t('assets_compliance_dashboard_index.no_runs_alert') }}
    </VAlert>

    <template v-if="latest_run">
      <VRow class="mb-6">
        <VCol cols="12" sm="3">
          <VCard elevation="0" border :color="rateColor(latest_run.compliance_rate)">
            <VCardText class="pa-4 text-center">
              <p class="text-caption mb-1">{{ t('assets_compliance_dashboard_index.stats.compliance_rate') }}</p>
              <p class="text-h3 font-weight-bold mb-0">{{ latest_run.compliance_rate.toFixed(1) }}%</p>
              <p class="text-caption mt-1 mb-0">{{ formatDate(latest_run.run_at) }}</p>
            </VCardText>
          </VCard>
        </VCol>
        <VCol cols="4" sm="3">
          <VCard elevation="0" border>
            <VCardText class="pa-4 text-center">
              <p class="text-caption text-medium-emphasis mb-1">{{ t('assets_compliance_dashboard_index.stats.passing') }}</p>
              <p class="text-h4 font-weight-bold text-success mb-0">{{ latest_run.pass_count }}</p>
            </VCardText>
          </VCard>
        </VCol>
        <VCol cols="4" sm="3">
          <VCard elevation="0" border>
            <VCardText class="pa-4 text-center">
              <p class="text-caption text-medium-emphasis mb-1">{{ t('assets_compliance_dashboard_index.stats.failing') }}</p>
              <p class="text-h4 font-weight-bold text-error mb-0">{{ latest_run.fail_count }}</p>
            </VCardText>
          </VCard>
        </VCol>
        <VCol cols="4" sm="3">
          <VCard elevation="0" border>
            <VCardText class="pa-4 text-center">
              <p class="text-caption text-medium-emphasis mb-1">{{ t('assets_compliance_dashboard_index.stats.overdue') }}</p>
              <p class="text-h4 font-weight-bold text-warning mb-0">{{ latest_run.overdue_count }}</p>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <VCard elevation="0" border>
        <VCardText>
          <p class="text-subtitle-1 font-weight-semibold mb-4">{{ t('assets_compliance_dashboard_index.trend.title') }}</p>
          <div class="d-flex align-end gap-2" style="height:100px">
            <template v-for="r in trend" :key="r.id">
              <VTooltip :text="`${formatDate(r.run_at)}: ${r.compliance_rate.toFixed(1)}%`">
                <template #activator="{ props: tp }">
                  <div
                    v-bind="tp"
                    class="flex-1 rounded-t"
                    :class="`bg-${rateColor(r.compliance_rate)}`"
                    :style="{ height: Math.max(4, r.compliance_rate) + 'px' }"
                  />
                </template>
              </VTooltip>
            </template>
          </div>
          <div class="d-flex justify-space-between text-caption text-medium-emphasis mt-1">
            <span>{{ formatDate(trend[0]?.run_at ?? null) }}</span>
            <span>{{ formatDate(trend[trend.length - 1]?.run_at ?? null) }}</span>
          </div>
        </VCardText>
      </VCard>
    </template>
  </div>
</template>
