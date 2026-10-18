<script lang="ts" setup>
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface Member { id: number; name: string; revenue: number; deals_won: number; attainment: number | null; weekly_attainment: number | null; monthly_attainment: number | null }
interface Totals { calls: number; emails: number; meetings: number; leads: number; deals_won: number; revenue: number; open_value: number; reps: number }
interface Org { won_value: number; won_count: number; lost_count: number; win_rate: number; open_value: number; open_count: number; forecast: number; new_leads: number; converted_leads: number; lead_conv_rate: number }
interface LeaderRow { name: string; revenue: number; deals_won: number; attainment: number | null; weekly_attainment: number | null; monthly_attainment: number | null }
interface RepSeries { categories: string[]; reps: { name: string; data: number[] }[] }

const props = defineProps<{ period: string; members: Member[]; totals: Totals; org: Org; leaderboard: LeaderRow[]; repMonthly: RepSeries; repWeekly: RepSeries }>()

const periods = [
  { title: t('crm_executive_dashboard_index.periods.weekly'), value: 'weekly' },
  { title: t('crm_executive_dashboard_index.periods.monthly'), value: 'monthly' },
  { title: t('crm_executive_dashboard_index.periods.quarter'), value: 'quarter' },
  { title: t('crm_executive_dashboard_index.periods.year'), value: 'year' },
]
function changePeriod(p: string) { router.get(route('crm.executive-dashboard'), { period: p }, { preserveState: false }) }
const money = (n: number) => 'R ' + (n ?? 0).toLocaleString('en-ZA', { maximumFractionDigits: 0 })
const attColor = (a: number | null) => a === null ? 'grey' : a >= 100 ? 'success' : a >= 60 ? 'primary' : 'warning'
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3"><div><h5 class="text-h5 font-weight-semibold">{{ t('crm_executive_dashboard_index.page_title') }}</h5><p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_executive_dashboard_index.description') }}</p></div><VSelect :model-value="period" :items="periods" density="compact" hide-details style="max-width: 180px" @update:model-value="changePeriod" /></div>

    <VRow class="mb-1">
      <VCol cols="12" sm="6" md="3"><VCard color="success" variant="tonal"><VCardText><div class="text-overline">{{ t('crm_executive_dashboard_index.kpis.closed_revenue') }}</div><div class="text-h4 font-weight-bold">{{ money(org.won_value) }}</div><div class="text-caption">{{ org.won_count }} {{ t('crm_executive_dashboard_index.kpis.deals_won') }}</div></VCardText></VCard></VCol>
      <VCol cols="12" sm="6" md="3"><VCard variant="tonal"><VCardText><div class="text-overline">{{ t('crm_executive_dashboard_index.kpis.weighted_forecast') }}</div><div class="text-h4 font-weight-bold">{{ money(org.forecast) }}</div><div class="text-caption">{{ t('crm_executive_dashboard_index.kpis.open_deals', { count: org.open_count }) }}</div></VCardText></VCard></VCol>
      <VCol cols="12" sm="6" md="3"><VCard variant="tonal"><VCardText><div class="text-overline">{{ t('crm_executive_dashboard_index.kpis.win_rate') }}</div><div class="text-h4 font-weight-bold">{{ org.win_rate }}%</div><div class="text-caption">{{ t('crm_executive_dashboard_index.kpis.won_lost', { won: org.won_count, lost: org.lost_count }) }}</div></VCardText></VCard></VCol>
      <VCol cols="12" sm="6" md="3"><VCard variant="tonal"><VCardText><div class="text-overline">{{ t('crm_executive_dashboard_index.kpis.lead_conversion') }}</div><div class="text-h4 font-weight-bold">{{ org.lead_conv_rate }}%</div><div class="text-caption">{{ t('crm_executive_dashboard_index.kpis.new_converted', { newLeads: org.new_leads, converted: org.converted_leads }) }}</div></VCardText></VCard></VCol>
    </VRow>

    <VRow class="mb-2">
      <VCol cols="6" md="3"><VCard><VCardText><div class="text-overline text-medium-emphasis">{{ t('crm_executive_dashboard_index.totals.sales_reps') }}</div><div class="text-h5 font-weight-bold">{{ totals.reps }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard><VCardText><div class="text-overline text-medium-emphasis">{{ t('crm_executive_dashboard_index.totals.calls') }}</div><div class="text-h5 font-weight-bold">{{ totals.calls }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard><VCardText><div class="text-overline text-medium-emphasis">{{ t('crm_executive_dashboard_index.totals.emails') }}</div><div class="text-h5 font-weight-bold">{{ totals.emails }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard><VCardText><div class="text-overline text-medium-emphasis">{{ t('crm_executive_dashboard_index.totals.meetings') }}</div><div class="text-h5 font-weight-bold">{{ totals.meetings }}</div></VCardText></VCard></VCol>
    </VRow>

    <RepPerformanceChart :monthly="repMonthly" :weekly="repWeekly" :title="t('crm_executive_dashboard_index.chart_title')" class="mb-4" />

    <VRow>
      <VCol cols="12" md="6">
        <VCard :title="t('crm_executive_dashboard_index.leaderboard.title')">
          <VList v-if="leaderboard.length" lines="one"><template v-for="(r, i) in leaderboard" :key="r.name"><VListItem><template #prepend><VAvatar :color="i === 0 ? 'amber' : 'blue-grey'" variant="tonal" size="34">{{ i + 1 }}</VAvatar></template><VListItemTitle class="font-weight-medium">{{ r.name }}</VListItemTitle><VListItemSubtitle>{{ r.deals_won }} {{ t('crm_executive_dashboard_index.leaderboard.won') }}</VListItemSubtitle><template #append><div class="text-right"><div class="font-weight-semibold">{{ money(r.revenue) }}</div><div class="d-flex ga-1 justify-end mt-1"><VChip :color="attColor(r.weekly_attainment)" size="x-small" label>W {{ r.weekly_attainment === null ? '—' : r.weekly_attainment + '%' }}</VChip><VChip :color="attColor(r.monthly_attainment)" size="x-small" label>M {{ r.monthly_attainment === null ? '—' : r.monthly_attainment + '%' }}</VChip></div></div></template></VListItem><VDivider /></template></VList>
          <VCardText v-else class="text-medium-emphasis">{{ t('crm_executive_dashboard_index.leaderboard.no_data') }}</VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="6">
        <VCard :title="t('crm_executive_dashboard_index.all_reps.title')">
          <VList v-if="members.length" lines="one" max-height="420" style="overflow-y:auto"><template v-for="m in members" :key="m.id"><VListItem><VListItemTitle>{{ m.name }}</VListItemTitle><template #append><span class="me-3">{{ money(m.revenue) }}</span><VChip :color="attColor(m.weekly_attainment)" size="x-small" label class="me-1">W {{ m.weekly_attainment === null ? '—' : m.weekly_attainment + '%' }}</VChip><VChip :color="attColor(m.monthly_attainment)" size="x-small" label>M {{ m.monthly_attainment === null ? '—' : m.monthly_attainment + '%' }}</VChip></template></VListItem><VDivider /></template></VList>
          <VCardText v-else class="text-medium-emphasis">{{ t('crm_executive_dashboard_index.all_reps.no_targets') }}</VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
