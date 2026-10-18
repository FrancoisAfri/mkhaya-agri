<script lang="ts" setup>
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface Member { id: number; name: string; calls: number; emails: number; meetings: number; leads: number; deals_won: number; revenue: number; open_value: number; attainment: number | null; weekly_attainment: number | null; monthly_attainment: number | null }
interface Totals { calls: number; emails: number; meetings: number; leads: number; deals_won: number; revenue: number; open_value: number; reps: number }
interface RepSeries { categories: string[]; reps: { name: string; data: number[] }[] }

const props = defineProps<{ period: string; members: Member[]; totals: Totals; repMonthly: RepSeries; repWeekly: RepSeries }>()

const periods = computed(() => [
  { title: t('crm_team_dashboard_index.periods.weekly'), value: 'weekly' },
  { title: t('crm_team_dashboard_index.periods.monthly'), value: 'monthly' },
])

function changePeriod(p: string) { router.get(route('crm.team-dashboard'), { period: p }, { preserveState: false }) }

const money = (n: number) => 'R ' + (n ?? 0).toLocaleString('en-ZA', { maximumFractionDigits: 0 })
const attColor = (a: number | null) => a === null ? 'grey' : a >= 100 ? 'success' : a >= 60 ? 'primary' : 'warning'

const headers = computed(() => [
  { title: t('crm_team_dashboard_index.table.rep'), key: 'name' },
  { title: t('crm_team_dashboard_index.table.calls'), key: 'calls', align: 'end' as const },
  { title: t('crm_team_dashboard_index.table.emails'), key: 'emails', align: 'end' as const },
  { title: t('crm_team_dashboard_index.table.meetings'), key: 'meetings', align: 'end' as const },
  { title: t('crm_team_dashboard_index.table.leads'), key: 'leads', align: 'end' as const },
  { title: t('crm_team_dashboard_index.table.won'), key: 'deals_won', align: 'end' as const },
  { title: t('crm_team_dashboard_index.table.revenue'), key: 'revenue', align: 'end' as const },
  { title: t('crm_team_dashboard_index.table.weekly_quota'), key: 'weekly_attainment', align: 'end' as const },
  { title: t('crm_team_dashboard_index.table.monthly_quota'), key: 'monthly_attainment', align: 'end' as const },
])
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3">
      <div>
        <h5 class="text-h5 font-weight-semibold">{{ t('crm_team_dashboard_index.page_title') }}</h5>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_team_dashboard_index.description', { count: totals.reps }) }}</p>
      </div>
      <VSelect :model-value="period" :items="periods" density="compact" hide-details style="max-width: 180px" @update:model-value="changePeriod" />
    </div>

    <VAlert v-if="!members.length" type="info" variant="tonal" class="mb-4">{{ t('crm_team_dashboard_index.no_reports') }}</VAlert>

    <VRow class="mb-2">
      <VCol cols="6" md="3">
        <VCard><VCardText><div class="text-overline text-medium-emphasis">{{ t('crm_team_dashboard_index.totals.team_revenue') }}</div><div class="text-h5 font-weight-bold text-success">{{ money(totals.revenue) }}</div></VCardText></VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard><VCardText><div class="text-overline text-medium-emphasis">{{ t('crm_team_dashboard_index.totals.open_pipeline') }}</div><div class="text-h5 font-weight-bold">{{ money(totals.open_value) }}</div></VCardText></VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard><VCardText><div class="text-overline text-medium-emphasis">{{ t('crm_team_dashboard_index.totals.deals_won') }}</div><div class="text-h5 font-weight-bold">{{ totals.deals_won }}</div></VCardText></VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard><VCardText><div class="text-overline text-medium-emphasis">{{ t('crm_team_dashboard_index.totals.calls_emails_meetings') }}</div><div class="text-h6 font-weight-bold">{{ totals.calls }} · {{ totals.emails }} · {{ totals.meetings }}</div></VCardText></VCard>
      </VCol>
    </VRow>

    <RepPerformanceChart v-if="members.length" :monthly="repMonthly" :weekly="repWeekly" :title="t('crm_team_dashboard_index.chart_title')" class="mb-4" />

    <VCard v-if="members.length">
      <VDataTable :headers="headers" :items="members" :items-per-page="25" density="comfortable">
        <template #item.revenue="{ item }">{{ money(item.revenue) }}</template>
        <template #item.weekly_attainment="{ item }">
          <VChip :color="attColor(item.weekly_attainment)" size="small" label>{{ item.weekly_attainment === null ? '—' : item.weekly_attainment + '%' }}</VChip>
        </template>
        <template #item.monthly_attainment="{ item }">
          <VChip :color="attColor(item.monthly_attainment)" size="small" label>{{ item.monthly_attainment === null ? '—' : item.monthly_attainment + '%' }}</VChip>
        </template>
      </VDataTable>
    </VCard>
  </div>
</template>