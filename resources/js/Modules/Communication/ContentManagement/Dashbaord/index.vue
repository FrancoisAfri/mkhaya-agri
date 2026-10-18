<script setup lang="ts">
import { computed, watch } from 'vue'
import VueApexCharts       from 'vue3-apexcharts'
import { useTheme }        from 'vuetify'
import { hexToRgb } from '@/@core/utils/colorConverter'
import { useI18n } from 'vue-i18n'

import {
  useCommunicationDashboardStore,
  type ContentStat,
  type SpotlightStat,
  type MonthlyTrend,
  type TopAuthor,
  type CeoMessageStat,
} from './Store/Store'

const { t } = useI18n()

const props = defineProps<{
  contentStats:   ContentStat[]
  spotlightStats: SpotlightStat[]
  monthlyTrends:  MonthlyTrend[]
  topAuthors:     TopAuthor[]
  ceoMessageStat: CeoMessageStat
}>()

const store = useCommunicationDashboardStore()
watch(() => props, (p) => store.hydrate(p), { immediate: true, deep: true })

const theme = useTheme()

const p = (hex: string, alpha = 1) =>
  `rgba(${hexToRgb(hex)}, ${alpha})`

const contentTypeConfig: Record<number, { icon: string; color: string; bg: string }> = {
  1: { icon: 'tabler-speakerphone',     color: '#FF9F43', bg: '#fff8ee' },
  2: { icon: 'tabler-news', color: '#7367F0', bg: '#f3f1ff' },
  3: { icon: 'tabler-calendar-star',        color: '#00CFE8', bg: '#e8fafd' },
  4: { icon: 'tabler-shield-lock',  color: '#EA5455', bg: '#ffeaea' },
}

const spotlightTypeConfig: Record<number, { icon: string; color: string; bg: string }> = {
  1: { icon: 'tabler-trophy',        color: '#FF9F43', bg: '#fff8ee' },
  2: { icon: 'tabler-cake',  color: '#00CFE8', bg: '#e8fafd' },
  3: { icon: 'tabler-medal',         color: '#28C76F', bg: '#edfaf1' },
}

function cfg(type: number) {
  return contentTypeConfig[type] ?? { icon: 'tabler-file', color: '#aaa', bg: '#f5f5f5' }
}

function spotCfg(type: number) {
  return spotlightTypeConfig[type] ?? { icon: 'tabler-star', color: '#aaa', bg: '#f5f5f5' }
}

function fmtDate(d: string | null) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
}

const kpiCards = computed(() => [
  {
    title: t('communication_dashboard.kpi.total_content'),
    value: store.totalContent,
    sub:   t('communication_dashboard.kpi.total_active', { count: store.totalActive }),
    icon:  'tabler-files',
    color: '#7367F0',
    bg:    '#f3f1ff',
  },
  {
    title: t('communication_dashboard.kpi.published_this_month'),
    value: store.totalThisMonth,
    sub:   t('communication_dashboard.kpi.across_all_types'),
    icon:  'tabler-calendar',
    color: '#28C76F',
    bg:    '#edfaf1',
  },
  {
    title: t('communication_dashboard.kpi.total_spotlights'),
    value: store.totalSpotlights,
    sub:   t('communication_dashboard.kpi.this_month', { count: props.spotlightStats.reduce((s, c) => s + c.this_month, 0) }),
    icon:  'tabler-star',
    color: '#FF9F43',
    bg:    '#fff8ee',
  },
  {
    title: t('communication_dashboard.kpi.ceo_message'),
    value: props.ceoMessageStat.total,
    sub:   props.ceoMessageStat.last_updated
      ? t('communication_dashboard.kpi.updated', { date: fmtDate(props.ceoMessageStat.last_updated) })
      : t('communication_dashboard.kpi.no_message'),
    icon:  'tabler-user-tie',
    color: '#00CFE8',
    bg:    '#e8fafd',
  },
])

const trendOptions = computed(() => ({
  chart:   { type: 'area', toolbar: { show: false }, sparkline: { enabled: false } },
  stroke:  { curve: 'smooth', width: 2 },
  fill:    { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.3, opacityTo: 0.05 } },
  colors:  ['#7367F0', '#00CFE8', '#FF9F43', '#EA5455', '#28C76F'],
  legend:  { position: 'top', horizontalAlign: 'left' },
  xaxis:   { categories: props.monthlyTrends.map(t => t.month) },
  tooltip: { y: { formatter: (v: number) => `${v} ${t('communication_dashboard.items')}` } },
  grid:    { borderColor: 'rgba(0,0,0,0.05)' },
  dataLabels: { enabled: false },
}))

const trendSeries = computed(() => [
  { name: t('communication_dashboard.trend.series.news'),             data: props.monthlyTrends.map(t => t.news)             },
  { name: t('communication_dashboard.trend.series.events'),           data: props.monthlyTrends.map(t => t.events)           },
  { name: t('communication_dashboard.trend.series.announcements'),    data: props.monthlyTrends.map(t => t.announcements)    },
  { name: t('communication_dashboard.trend.series.internal_notices'), data: props.monthlyTrends.map(t => t.internal_notices) },
  { name: t('communication_dashboard.trend.series.spotlights'),       data: props.monthlyTrends.map(t => t.spotlights)       },
])

const donutOptions = computed(() => ({
  chart:   { type: 'donut', toolbar: { show: false } },
  colors:  ['#FF9F43', '#7367F0', '#00CFE8', '#EA5455'],
  labels:  props.contentStats.map(c => c.label),
  legend:  { position: 'bottom' },
  plotOptions: {
    pie: {
      donut: {
        size: '68%',
        labels: {
          show:  true,
          total: { show: true, label: t('communication_dashboard.content_cards.total'), fontSize: '13px', fontWeight: 600 },
          value: { fontSize: '18px', fontWeight: 700 },
        },
      },
    },
  },
  dataLabels: { enabled: false },
}))

const donutSeries = computed(() =>
  props.contentStats.map(c => c.total)
)

const activeBarOptions = computed(() => ({
  chart:   { type: 'bar', toolbar: { show: false } },
  colors:  ['#28C76F', '#EA5455'],
  plotOptions: { bar: { horizontal: false, columnWidth: '50%', borderRadius: 4 } },
  dataLabels: { enabled: false },
  legend:  { position: 'top' },
  xaxis:   { categories: props.contentStats.map(c => c.label) },
  states:  { hover: { filter: { type: 'none' } } },
  tooltip: { y: { formatter: (v: number) => `${v}` } },
}))

const activeBarSeries = computed(() => [
  { name: t('communication_dashboard.active_inactive.active'),   data: props.contentStats.map(c => c.active)   },
  { name: t('communication_dashboard.active_inactive.inactive'), data: props.contentStats.map(c => c.inactive) },
])

const spotDonutOptions = computed(() => ({
  chart:   { type: 'donut', toolbar: { show: false } },
  colors:  ['#FF9F43', '#00CFE8', '#28C76F'],
  labels:  props.spotlightStats.map(s => s.label),
  legend:  { position: 'bottom' },
  plotOptions: {
    pie: {
      donut: {
        size: '68%',
        labels: {
          show:  true,
          total: { show: true, label: t('communication_dashboard.content_cards.total'), fontSize: '13px', fontWeight: 600 },
          value: { fontSize: '18px', fontWeight: 700 },
        },
      },
    },
  },
  dataLabels: { enabled: false },
}))

const spotDonutSeries = computed(() =>
  props.spotlightStats.map(s => s.total)
)
</script>

<template>
  <div>

    <VCard flat class="mb-6">
      <div class="hero-bg pa-8">
        <h2 class="text-h4 font-weight-bold text-white mb-1">{{ t('communication_dashboard.page_title') }}</h2>
        <p class="text-white mb-0" style="opacity:.8">{{ t('communication_dashboard.subtitle') }}</p>
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
            <p class="text-h4 font-weight-bold mb-1" :style="{ color: card.color }">
              {{ card.value }}
            </p>
            <p class="text-caption text-medium-emphasis mb-0">{{ card.sub }}</p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow class="mb-6">
      <VCol cols="12">
        <VCard elevation="0" border>
          <VCardItem>
            <VCardTitle>{{ t('communication_dashboard.trend.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('communication_dashboard.trend.subtitle') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts
              v-if="monthlyTrends.length"
              type="area"
              :series="trendSeries"
              :options="trendOptions"
              height="280"
            />
            <div v-else class="d-flex align-center justify-center py-10 text-medium-emphasis">
              <VIcon icon="tabler-chart-line" size="40" class="me-3" style="opacity:.3" />
              {{ t('communication_dashboard.trend.no_data') }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow class="mb-6">
      <VCol cols="12" lg="5">
        <VCard elevation="0" border class="h-100">
          <VCardItem>
            <VCardTitle>{{ t('communication_dashboard.content_breakdown.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('communication_dashboard.content_breakdown.subtitle') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts
              v-if="donutSeries.some(v => v > 0)"
              type="donut"
              :series="donutSeries"
              :options="donutOptions"
              height="280"
            />
            <div v-else class="d-flex align-center justify-center py-10 text-medium-emphasis">
              {{ t('communication_dashboard.content_breakdown.no_data') }}
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" lg="7">
        <VCard elevation="0" border class="h-100">
          <VCardItem>
            <VCardTitle>{{ t('communication_dashboard.active_inactive.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('communication_dashboard.active_inactive.subtitle') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts
              v-if="contentStats.length"
              type="bar"
              :series="activeBarSeries"
              :options="activeBarOptions"
              height="280"
            />
            <div v-else class="d-flex align-center justify-center py-10 text-medium-emphasis">
              {{ t('communication_dashboard.active_inactive.no_data') }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow class="mb-6">
      <VCol v-for="stat in contentStats" :key="stat.type" cols="12" sm="6" lg="3">
        <VCard elevation="0" border
          :style="{ borderLeft: `4px solid ${cfg(stat.type).color}` }">
          <VCardText class="pa-4">
            <div class="d-flex align-center gap-3 mb-3">
              <div class="d-flex align-center justify-center rounded-lg flex-shrink-0"
                :style="{ width:'40px', height:'40px', background: cfg(stat.type).bg }">
                <VIcon :icon="cfg(stat.type).icon" :color="cfg(stat.type).color" size="20" />
              </div>
              <p class="text-body-2 font-weight-semibold mb-0">{{ stat.label }}</p>
            </div>
            <div class="d-flex justify-space-between">
              <div class="text-center">
                <p class="text-h6 font-weight-bold mb-0" :style="{ color: cfg(stat.type).color }">
                  {{ stat.total }}
                </p>
                <p class="text-caption text-medium-emphasis mb-0">{{ t('communication_dashboard.content_cards.total') }}</p>
              </div>
              <VDivider vertical />
              <div class="text-center">
                <p class="text-h6 font-weight-bold mb-0 text-success">{{ stat.active }}</p>
                <p class="text-caption text-medium-emphasis mb-0">{{ t('communication_dashboard.content_cards.active') }}</p>
              </div>
              <VDivider vertical />
              <div class="text-center">
                <p class="text-h6 font-weight-bold mb-0" style="color:#FF9F43">{{ stat.this_month }}</p>
                <p class="text-caption text-medium-emphasis mb-0">{{ t('communication_dashboard.content_cards.this_month') }}</p>
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow class="mb-6">
      <VCol cols="12" lg="4">
        <VCard elevation="0" border class="h-100">
          <VCardItem>
            <VCardTitle>{{ t('communication_dashboard.spotlight_distribution.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('communication_dashboard.spotlight_distribution.subtitle') }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <VueApexCharts
              v-if="spotDonutSeries.some(v => v > 0)"
              type="donut"
              :series="spotDonutSeries"
              :options="spotDonutOptions"
              height="240"
            />
            <div v-else class="d-flex align-center justify-center py-8 text-medium-emphasis">
              {{ t('communication_dashboard.spotlight_distribution.no_data') }}
            </div>
          </VCardText>
          <VDivider />
          <VList density="compact">
            <VListItem v-for="s in spotlightStats" :key="s.type">
              <template #prepend>
                <div class="d-flex align-center justify-center rounded-lg me-3"
                  :style="{ width:'32px', height:'32px', background: spotCfg(s.type).bg }">
                  <VIcon :icon="spotCfg(s.type).icon" :color="spotCfg(s.type).color" size="16" />
                </div>
              </template>
              <VListItemTitle class="text-body-2 font-weight-medium">{{ s.label }}</VListItemTitle>
              <VListItemSubtitle class="text-caption">{{ t('communication_dashboard.spotlight_distribution.this_month', { count: s.this_month }) }}</VListItemSubtitle>
              <template #append>
                <VChip size="x-small" :color="spotCfg(s.type).color" variant="tonal">
                  {{ s.total }}
                </VChip>
              </template>
            </VListItem>
          </VList>
        </VCard>
      </VCol>

      <VCol cols="12" lg="4">
        <VCard elevation="0" border class="h-100">
          <VCardItem>
            <VCardTitle>{{ t('communication_dashboard.top_authors.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('communication_dashboard.top_authors.subtitle') }}</VCardSubtitle>
          </VCardItem>
          <VDivider />
          <VList density="compact">
            <VListItem v-for="(author, i) in topAuthors" :key="author.name">
              <template #prepend>
                <VAvatar size="32" color="primary" variant="tonal" class="me-3">
                  <span class="text-caption font-weight-bold">{{ i + 1 }}</span>
                </VAvatar>
              </template>
              <VListItemTitle class="text-body-2 font-weight-semibold">{{ author.name }}</VListItemTitle>
              <template #append>
                <VChip size="x-small" color="primary" variant="tonal">{{ author.count }}</VChip>
              </template>
            </VListItem>
            <VListItem v-if="!topAuthors.length">
              <VListItemTitle class="text-center text-medium-emphasis py-6">{{ t('communication_dashboard.top_authors.no_data') }}</VListItemTitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>

      <VCol cols="12" lg="4">
        <VCard elevation="0" border class="h-100">
          <VCardItem>
            <VCardTitle>{{ t('communication_dashboard.ceo_message_card.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('communication_dashboard.ceo_message_card.subtitle') }}</VCardSubtitle>
          </VCardItem>
          <VDivider />
          <VCardText class="pa-5">
            <div class="d-flex align-center justify-center mb-5">
              <div class="d-flex align-center justify-center rounded-circle"
                style="width:72px;height:72px;background:#e8fafd">
                <VIcon icon="tabler-user-tie" color="#00CFE8" size="36" />
              </div>
            </div>
            <div class="d-flex justify-space-between mb-3">
              <div class="text-center flex-1">
                <p class="text-h5 font-weight-bold mb-0" style="color:#00CFE8">
                  {{ ceoMessageStat.total }}
                </p>
                <p class="text-caption text-medium-emphasis mb-0">{{ t('communication_dashboard.ceo_message_card.total') }}</p>
              </div>
              <VDivider vertical class="mx-4" />
              <div class="text-center flex-1">
                <p class="text-h5 font-weight-bold mb-0 text-success">
                  {{ ceoMessageStat.active }}
                </p>
                <p class="text-caption text-medium-emphasis mb-0">{{ t('communication_dashboard.ceo_message_card.active') }}</p>
              </div>
            </div>
            <VDivider class="my-4" />
            <div class="d-flex align-center gap-2">
              <VIcon icon="tabler-clock" size="16" color="medium-emphasis" />
              <p class="text-caption text-medium-emphasis mb-0">
                {{ t('communication_dashboard.ceo_message_card.last_updated', { date: fmtDate(ceoMessageStat.last_updated) }) }}
              </p>
            </div>
            <VChip
              class="mt-3 w-100 justify-center"
              :color="ceoMessageStat.active > 0 ? 'success' : 'default'"
              variant="tonal"
              size="small"
            >
              {{ ceoMessageStat.active > 0 ? t('communication_dashboard.ceo_message_card.message_active') : t('communication_dashboard.ceo_message_card.no_active_message') }}
            </VChip>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

  </div>
</template>

<style scoped lang="scss">
@use "@core/scss/template/libs/apex-chart.scss";

.hero-bg {
  background: linear-gradient(135deg, #7367F0 0%, #4e42c8 100%);
  border-radius: 12px;
  position: relative;
  overflow: hidden;

  &::after {
    content: '';
    position: absolute;
    top: -60px;
    right: -60px;
    width: 240px;
    height: 240px;
    background: radial-gradient(circle, rgba(255,255,255,0.12) 0%, rgba(255,255,255,0) 70%);
    border-radius: 50%;
    pointer-events: none;
  }
}
</style>
