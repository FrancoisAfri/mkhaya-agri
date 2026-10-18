<script setup lang="ts">
import { computed } from 'vue'
import { useTheme } from 'vuetify'
import { useI18n } from 'vue-i18n'
import VueApexCharts from 'vue3-apexcharts'
import { route as ziggyRoute } from 'ziggy-js'

/**
 * Onboarding analytics.
 *
 * The measures are chosen to be actionable rather than flattering: a single completion percentage
 * always looks respectable, so the page leads with what is overdue and which department is behind.
 */

type Analytics = {
  kpis: { active_plans: number; completed_plans: number; overdue_tasks: number; starting_this_month: number; average_days_to_complete: number | null }
  by_department: { label: string; total: number; completed: number; pct: number }[]
  monthly_volume: { month: string; count: number }[]
  task_ageing: Record<string, number>
  documents: Record<string, number>
  provisioning: { outstanding: number; late_for_started: number }
  probation: Record<string, number>
}

const props = defineProps<{ analytics: Analytics }>()

const { t } = useI18n()
const theme = useTheme()
const route = ziggyRoute

const base = computed(() => {
  const onSurface = theme.current.value.dark ? '#CFD3EC' : '#4B465C'

  return {
    chart: { toolbar: { show: false }, fontFamily: 'inherit', foreColor: onSurface },
    grid: { borderColor: theme.current.value.dark ? '#3A3E5B' : '#E6E6E8', strokeDashArray: 4 },
    dataLabels: { enabled: false },
    legend: { labels: { colors: onSurface }, position: 'bottom' as const },
    tooltip: { theme: theme.current.value.dark ? 'dark' : 'light' },
  }
})

const kpis = computed(() => [
  { key: 'active_plans', value: props.analytics.kpis.active_plans, icon: 'tabler-users', color: 'primary' },
  { key: 'starting_this_month', value: props.analytics.kpis.starting_this_month, icon: 'tabler-calendar-event', color: 'info' },
  { key: 'overdue_tasks', value: props.analytics.kpis.overdue_tasks, icon: 'tabler-alert-triangle', color: 'error' },
  { key: 'completed_plans', value: props.analytics.kpis.completed_plans, icon: 'tabler-checkbox', color: 'success' },
  {
    key: 'average_days_to_complete',
    value: props.analytics.kpis.average_days_to_complete ?? '—',
    icon: 'tabler-clock', color: 'warning',
  },
])

const volumeOptions = computed(() => ({
  ...base.value,
  chart: { ...base.value.chart, type: 'bar' as const },
  plotOptions: { bar: { borderRadius: 4, columnWidth: '55%' } },
  xaxis: { categories: props.analytics.monthly_volume.map(r => r.month) },
}))
const volumeSeries = computed(() => [
  { name: t('onboarding_analytics.new_hires'), data: props.analytics.monthly_volume.map(r => r.count) },
])

const departmentOptions = computed(() => ({
  ...base.value,
  chart: { ...base.value.chart, type: 'bar' as const },
  plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '60%' } },
  xaxis: { categories: props.analytics.by_department.map(r => r.label), max: 100 },
  dataLabels: { enabled: true, formatter: (v: number) => `${v}%` },
}))
const departmentSeries = computed(() => [
  { name: t('onboarding_analytics.completion'), data: props.analytics.by_department.map(r => r.pct) },
])

const ageingOptions = computed(() => ({
  ...base.value,
  labels: [
    t('onboarding_analytics.age_1_7'),
    t('onboarding_analytics.age_8_30'),
    t('onboarding_analytics.age_over_30'),
  ],
  colors: [theme.current.value.colors.warning, theme.current.value.colors.error, theme.current.value.colors['primary-darken-1']],
}))
const ageingSeries = computed(() => [
  props.analytics.task_ageing['1_7'] ?? 0,
  props.analytics.task_ageing['8_30'] ?? 0,
  props.analytics.task_ageing.over_30 ?? 0,
])

const hasAgeing = computed(() => ageingSeries.value.some(v => v > 0))
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('onboarding_analytics.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('onboarding_analytics.subtitle') }}</p>
      </div>
      <VBtn color="primary" variant="tonal" prepend-icon="tabler-download" :href="route('onboarding.analytics.export')">
        {{ t('onboarding_analytics.export') }}
      </VBtn>
    </div>

    <div class="onboarding-kpis mb-4">
      <VCard v-for="kpi in kpis" :key="kpi.key" border>
        <VCardText class="d-flex align-center gap-3">
          <VAvatar :color="kpi.color" variant="tonal" rounded size="42">
            <VIcon :icon="kpi.icon" />
          </VAvatar>
          <div class="min-w-0">
            <div class="text-h5">{{ kpi.value }}</div>
            <div class="text-caption text-medium-emphasis text-truncate">
              {{ t(`onboarding_analytics.kpi_${kpi.key}`) }}
            </div>
          </div>
        </VCardText>
      </VCard>
    </div>

    <VRow>
      <VCol cols="12" md="8">
        <VCard border class="h-100">
          <VCardItem>
            <VCardTitle class="text-body-1">{{ t('onboarding_analytics.volume_title') }}</VCardTitle>
            <VCardSubtitle>{{ t('onboarding_analytics.volume_sub') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts v-if="analytics.monthly_volume.length" type="bar" :height="280" :options="volumeOptions" :series="volumeSeries" />
            <p v-else class="text-body-2 text-medium-emphasis text-center py-8">{{ t('onboarding_analytics.no_data') }}</p>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard border class="h-100">
          <VCardItem>
            <VCardTitle class="text-body-1">{{ t('onboarding_analytics.ageing_title') }}</VCardTitle>
            <VCardSubtitle>{{ t('onboarding_analytics.ageing_sub') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts v-if="hasAgeing" type="donut" :height="280" :options="ageingOptions" :series="ageingSeries" />
            <p v-else class="text-body-2 text-medium-emphasis text-center py-8">{{ t('onboarding_analytics.nothing_overdue') }}</p>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="8">
        <VCard border class="h-100">
          <VCardItem>
            <VCardTitle class="text-body-1">{{ t('onboarding_analytics.department_title') }}</VCardTitle>
            <VCardSubtitle>{{ t('onboarding_analytics.department_sub') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts v-if="analytics.by_department.length" type="bar" :height="280" :options="departmentOptions" :series="departmentSeries" />
            <p v-else class="text-body-2 text-medium-emphasis text-center py-8">{{ t('onboarding_analytics.no_data') }}</p>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard border class="h-100">
          <VCardItem>
            <VCardTitle class="text-body-1">{{ t('onboarding_analytics.provisioning_title') }}</VCardTitle>
            <VCardSubtitle>{{ t('onboarding_analytics.provisioning_sub') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <div class="d-flex justify-space-around text-center py-4">
              <div>
                <div class="text-h3">{{ analytics.provisioning.outstanding }}</div>
                <div class="text-caption text-medium-emphasis">{{ t('onboarding_analytics.outstanding') }}</div>
              </div>
              <div>
                <div class="text-h3" :class="analytics.provisioning.late_for_started ? 'text-error' : ''">
                  {{ analytics.provisioning.late_for_started }}
                </div>
                <div class="text-caption text-medium-emphasis">{{ t('onboarding_analytics.late_for_started') }}</div>
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>

<style scoped lang="scss">
.onboarding-kpis {
  display: grid;
  gap: 1.5rem;
  grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
}

.min-w-0 {
  min-inline-size: 0;
}
</style>
