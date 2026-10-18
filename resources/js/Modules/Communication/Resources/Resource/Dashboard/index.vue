<script setup lang="ts">
import { computed } from 'vue'
import VueApexCharts from 'vue3-apexcharts'
import { useI18n } from 'vue-i18n'
import { RESOURCE_TYPE_CONFIG, getResourceTypeConfig } from '../Store/type'

const { t } = useI18n()

interface TypeStat  { type: number; label: string; total: number; active: number; this_month: number }
interface Trend     { month: string; total: number; internal: number; public: number }
interface Uploader  { name: string; count: number }

const props = defineProps<{
  typeStats:      TypeStat[]
  monthlyTrends:  Trend[]
  topUploaders:   Uploader[]
  brandingCount:  number
  totalResources: number
  totalActive:    number
}>()

const kpiCards = computed(() => [
  {
    title: t('resource_dashboard.kpi.total_resources'),
    value: props.totalResources,
    sub:   t('resource_dashboard.kpi.total_active', { count: props.totalActive }),
    icon:  'tabler-folders',
    color: '#7367F0',
    bg:    '#f3f1ff',
  },
  {
    title: t('resource_dashboard.kpi.added_this_month'),
    value: props.typeStats.reduce((s, t) => s + t.this_month, 0),
    sub:   t('resource_dashboard.kpi.across_all_types'),
    icon:  'tabler-calendar-plus',
    color: '#28C76F',
    bg:    '#edfaf1',
  },
  {
    title: t('resource_dashboard.kpi.corporate_brandings'),
    value: props.brandingCount,
    sub:   t('resource_dashboard.kpi.brand_identities'),
    icon:  'tabler-palette',
    color: '#FF9F43',
    bg:    '#fff8ee',
  },
  {
    title: t('resource_dashboard.kpi.active_rate'),
    value: props.totalResources
      ? Math.round((props.totalActive / props.totalResources) * 100) + '%'
      : '0%',
    sub:   t('resource_dashboard.kpi.of_total_resources'),
    icon:  'tabler-chart-donut',
    color: '#00CFE8',
    bg:    '#e8fafd',
  },
])

const trendOptions = computed(() => ({
  chart:   { type: 'area', toolbar: { show: false } },
  stroke:  { curve: 'smooth', width: 2 },
  fill:    { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.25, opacityTo: 0.03 } },
  colors:  ['#7367F0', '#28C76F', '#EA5455'],
  legend:  { position: 'top', horizontalAlign: 'left' },
  xaxis:   { categories: props.monthlyTrends.map(t => t.month) },
  tooltip: { y: { formatter: (v: number) => `${v} ${t('resource_dashboard.trend.resources')}` } },
  grid:    { borderColor: 'rgba(0,0,0,0.05)' },
  dataLabels: { enabled: false },
}))

const trendSeries = computed(() => [
  { name: t('resource_dashboard.trend.series.total'),    data: props.monthlyTrends.map(t => t.total)    },
  { name: t('resource_dashboard.trend.series.public'),   data: props.monthlyTrends.map(t => t.public)   },
  { name: t('resource_dashboard.trend.series.internal'), data: props.monthlyTrends.map(t => t.internal) },
])

const donutOptions = computed(() => ({
  chart:   { type: 'donut', toolbar: { show: false } },
  colors:  Object.values(RESOURCE_TYPE_CONFIG).map(c => c.color),
  labels:  props.typeStats.map(t => t.label),
  legend:  { position: 'bottom', fontSize: '12px' },
  plotOptions: {
    pie: {
      donut: {
        size: '68%',
        labels: {
          show:  true,
          total: { show: true, label: t('resource_dashboard.type_cards.total'), fontSize: '13px', fontWeight: 600 },
          value: { fontSize: '18px', fontWeight: 700 },
        },
      },
    },
  },
  dataLabels: { enabled: false },
}))

const donutSeries = computed(() => props.typeStats.map(t => t.total))

const barOptions = computed(() => ({
  chart:   { type: 'bar', toolbar: { show: false } },
  colors:  ['#28C76F', '#EA5455'],
  plotOptions: { bar: { horizontal: false, columnWidth: '50%', borderRadius: 4 } },
  dataLabels: { enabled: false },
  legend:  { position: 'top' },
  xaxis:   { categories: props.typeStats.map(t => t.label.split(' ')[0]) },
  states:  { hover: { filter: { type: 'none' } } },
  tooltip: { y: { formatter: (v: number) => `${v}` } },
}))

const barSeries = computed(() => [
  { name: t('resource_dashboard.active_bar.active'),   data: props.typeStats.map(t => t.active)             },
  { name: t('resource_dashboard.active_bar.inactive'), data: props.typeStats.map(t => t.total - t.active)   },
])
</script>

<template>
  <div>

    <VCard flat class="mb-6">
      <div class="res-hero pa-8">
        <h2 class="text-h4 font-weight-bold text-white mb-1">{{ t('resource_dashboard.hero.title') }}</h2>
        <p class="text-white mb-0" style="opacity:.8">{{ t('resource_dashboard.hero.subtitle') }}</p>
      </div>
    </VCard>

    <VRow class="mb-6">
      <VCol v-for="card in kpiCards" :key="card.title" cols="12" sm="6" lg="3">
        <VCard elevation="0" border class="h-100">
          <VCardText class="pa-5">
            <div class="d-flex align-center justify-space-between mb-4">
              <p class="text-caption font-weight-semibold text-medium-emphasis text-uppercase mb-0"
                style="letter-spacing:.5px">{{ card.title }}</p>
              <div class="d-flex align-center justify-center rounded-lg"
                :style="{ width:'40px', height:'40px', background: card.bg }">
                <VIcon :icon="card.icon" :color="card.color" size="20" />
              </div>
            </div>
            <p class="text-h4 font-weight-bold mb-1" :style="{ color: card.color }">{{ card.value }}</p>
            <p class="text-caption text-medium-emphasis mb-0">{{ card.sub }}</p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow class="mb-6">
      <VCol cols="12">
        <VCard elevation="0" border>
          <VCardItem>
            <VCardTitle>{{ t('resource_dashboard.trend.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('resource_dashboard.trend.subtitle') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts v-if="monthlyTrends.length" type="area"
              :series="trendSeries" :options="trendOptions" height="260" />
            <div v-else class="d-flex align-center justify-center py-10 text-medium-emphasis">
              {{ t('resource_dashboard.trend.no_data') }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow class="mb-6">
      <VCol cols="12" lg="5">
        <VCard elevation="0" border class="h-100">
          <VCardItem>
            <VCardTitle>{{ t('resource_dashboard.breakdown.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('resource_dashboard.breakdown.subtitle') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts v-if="donutSeries.some(v => v > 0)" type="donut"
              :series="donutSeries" :options="donutOptions" height="280" />
            <div v-else class="d-flex align-center justify-center py-10 text-medium-emphasis">
              {{ t('resource_dashboard.breakdown.no_data') }}
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" lg="7">
        <VCard elevation="0" border class="h-100">
          <VCardItem>
            <VCardTitle>{{ t('resource_dashboard.active_bar.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('resource_dashboard.active_bar.subtitle') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts v-if="typeStats.length" type="bar"
              :series="barSeries" :options="barOptions" height="280" />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow class="mb-6">
      <VCol cols="12" lg="8">
        <VRow>
          <VCol v-for="stat in typeStats" :key="stat.type" cols="12" sm="6" md="3">
            <VCard elevation="0" border
              :style="{ borderLeft: `4px solid ${getResourceTypeConfig(stat.type).color}` }">
              <VCardText class="pa-3">
                <div class="d-flex align-center gap-2 mb-2">
                  <VIcon :icon="getResourceTypeConfig(stat.type).icon"
                    :color="getResourceTypeConfig(stat.type).color" size="18" />
                  <span class="text-caption font-weight-semibold">{{ stat.label.split(' ')[0] }}</span>
                </div>
                <div class="d-flex justify-space-between">
                  <div class="text-center">
                    <p class="text-h6 font-weight-bold mb-0"
                      :style="{ color: getResourceTypeConfig(stat.type).color }">{{ stat.total }}</p>
                    <p class="text-caption text-medium-emphasis mb-0" style="font-size:9px">{{ t('resource_dashboard.type_cards.total') }}</p>
                  </div>
                  <VDivider vertical />
                  <div class="text-center">
                    <p class="text-h6 font-weight-bold text-success mb-0">{{ stat.active }}</p>
                    <p class="text-caption text-medium-emphasis mb-0" style="font-size:9px">{{ t('resource_dashboard.type_cards.active') }}</p>
                  </div>
                  <VDivider vertical />
                  <div class="text-center">
                    <p class="text-h6 font-weight-bold mb-0" style="color:#FF9F43">{{ stat.this_month }}</p>
                    <p class="text-caption text-medium-emphasis mb-0" style="font-size:9px">{{ t('resource_dashboard.type_cards.month') }}</p>
                  </div>
                </div>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>
      </VCol>

      <VCol cols="12" lg="4">
        <VCard elevation="0" border class="h-100">
          <VCardItem>
            <VCardTitle>{{ t('resource_dashboard.top_uploaders.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('resource_dashboard.top_uploaders.subtitle') }}</VCardSubtitle>
          </VCardItem>
          <VDivider />
          <VList density="compact">
            <VListItem v-for="(u, i) in topUploaders" :key="u.name">
              <template #prepend>
                <VAvatar size="30" color="primary" variant="tonal" class="me-3">
                  <span class="text-caption font-weight-bold">{{ i + 1 }}</span>
                </VAvatar>
              </template>
              <VListItemTitle class="text-body-2 font-weight-semibold">{{ u.name }}</VListItemTitle>
              <template #append>
                <VChip size="x-small" color="primary" variant="tonal">{{ u.count }}</VChip>
              </template>
            </VListItem>
            <VListItem v-if="!topUploaders.length">
              <VListItemTitle class="text-center text-medium-emphasis py-6">{{ t('resource_dashboard.top_uploaders.no_data') }}</VListItemTitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>

  </div>
</template>

<style scoped lang="scss">
@use "@core/scss/template/libs/apex-chart.scss";

.res-hero {
  background: linear-gradient(135deg, #7367F0 0%, #4e42c8 100%);
  border-radius: 12px;
  position: relative;
  overflow: hidden;

  &::after {
    content: '';
    position: absolute;
    top: -60px; right: -60px;
    width: 220px; height: 220px;
    background: radial-gradient(circle, rgba(255,255,255,.1) 0%, transparent 70%);
    border-radius: 50%;
    pointer-events: none;
  }
}
</style>
