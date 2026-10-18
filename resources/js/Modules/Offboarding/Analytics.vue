<script setup lang="ts">
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  kpis: { total_plans: number; completed_plans: number; active_plans: number; avg_satisfaction: number; interview_count: number; voluntary: number; involuntary: number }
  by_type: { label: string; value: number }[]
  by_reason: { label: string; value: number }[]
  satisfaction_trend: { month: string; avg: number; count: number }[]
  monthly_volume: { month: string; total: number }[]
  rehire: { eligible: number; not_eligible: number }
  counteroffer: { accepted: number; declined: number }
  would_recommend: { yes: number; no: number }
}>()

function terminationLabel(val: string) { const map: Record<string, string> = { resignation: t('offboarding.termination_resignation'), retrenchment: t('offboarding.termination_retrenchment'), dismissal: t('offboarding.termination_dismissal'), retirement: t('offboarding.termination_retirement'), contract_end: t('offboarding.termination_contract_end'), death: t('offboarding.termination_death') }; return map[val] ?? val }
function terminationColor(val: string) { if (val === 'dismissal') return '#f44336'; if (val === 'retrenchment') return '#ff9800'; if (val === 'resignation') return '#2196f3'; if (val === 'retirement') return '#4caf50'; if (val === 'contract_end') return '#9c27b0'; if (val === 'death') return '#607d8b'; return '#9e9e9e' }
function reasonLabel(val: string) { const map: Record<string, string> = { better_opportunity: t('offboarding.reason_better_opportunity'), salary: t('offboarding.reason_salary'), work_environment: t('offboarding.reason_work_environment'), management: t('offboarding.reason_management'), relocation: t('offboarding.reason_relocation'), personal: t('offboarding.reason_personal'), retirement: t('offboarding.reason_retirement'), contract_end: t('offboarding.reason_contract_end'), other: t('offboarding.reason_other') }; return map[val] ?? val }
function monthLabel(m: string) { const [year, month] = m.split('-'); const d = new Date(Number(year), Number(month) - 1); return d.toLocaleDateString('en-ZA', { month: 'short', year: '2-digit' }) }

const statCards = computed(() => [
  { label: t('offboarding.total_plans'), value: props.kpis.total_plans, icon: 'tabler-user-share', color: 'primary', bg: 'rgba(115,103,240,0.12)' },
  { label: t('offboarding.active'), value: props.kpis.active_plans, icon: 'tabler-player-play', color: 'info', bg: 'rgba(0,207,232,0.12)' },
  { label: t('offboarding.completed'), value: props.kpis.completed_plans, icon: 'tabler-circle-check', color: 'success', bg: 'rgba(40,199,111,0.12)' },
  { label: t('offboarding.reports_kpi_interviews'), value: props.kpis.interview_count, icon: 'tabler-message-report', color: 'warning', bg: 'rgba(255,159,67,0.12)' },
])

const reasonMax = computed(() => Math.max(...props.by_reason.map(r => r.value), 1))
const volumeMax = computed(() => Math.max(...props.monthly_volume.map(r => r.total), 1))
const satMax = computed(() => 5)
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <div class="d-flex align-center gap-2 mb-1">
          <VBtn icon="tabler-arrow-left" variant="text" size="small" @click="router.visit(route('offboarding.index'))" />
          <h4 class="text-h4 font-weight-bold">{{ t('offboarding.analytics_title') }}</h4>
        </div>
        <p class="text-medium-emphasis mb-0 ms-10">{{ t('offboarding.analytics_subtitle') }}</p>
      </div>
    </div>

    <VRow class="mb-6">
      <VCol v-for="card in statCards" :key="card.label" cols="12" sm="6" lg="3">
        <VCard elevation="0" border><VCardText class="d-flex align-center gap-4 pa-5"><div class="d-flex align-center justify-center rounded-lg flex-shrink-0" :style="{ background: card.bg, width: '52px', height: '52px' }"><VIcon :icon="card.icon" :color="card.color" size="28" /></div><div><p class="text-body-2 text-medium-emphasis mb-1">{{ card.label }}</p><p class="text-h4 font-weight-bold mb-0" :style="{ color: `var(--v-theme-${card.color})` }">{{ card.value }}</p></div></VCardText></VCard>
      </VCol>
    </VRow>

    <VRow class="mb-5">
      <VCol cols="12" sm="6" lg="3"><VCard elevation="0" border height="100%"><VCardTitle class="pa-4 pb-2 text-body-2 font-weight-semibold">{{ t('offboarding.analytics_voluntary') }}</VCardTitle><VCardText class="pa-4 pt-0"><div class="d-flex flex-column gap-3"><div><div class="d-flex justify-space-between text-caption mb-1"><span class="text-success">{{ t('offboarding.analytics_voluntary_label') }}</span><span class="font-weight-medium">{{ kpis.voluntary }}</span></div><VProgressLinear :model-value="kpis.total_plans ? (kpis.voluntary / kpis.total_plans) * 100 : 0" color="success" rounded height="8" /></div><div><div class="d-flex justify-space-between text-caption mb-1"><span class="text-error">{{ t('offboarding.analytics_involuntary_label') }}</span><span class="font-weight-medium">{{ kpis.involuntary }}</span></div><VProgressLinear :model-value="kpis.total_plans ? (kpis.involuntary / kpis.total_plans) * 100 : 0" color="error" rounded height="8" /></div></div></VCardText></VCard></VCol>
      <VCol cols="12" sm="6" lg="3"><VCard elevation="0" border height="100%"><VCardTitle class="pa-4 pb-2 text-body-2 font-weight-semibold">{{ t('offboarding.analytics_avg_satisfaction') }}</VCardTitle><VCardText class="pa-4 pt-0 text-center"><p class="text-h2 font-weight-bold mb-1" style="color: var(--v-theme-warning)">{{ kpis.avg_satisfaction || '—' }}</p><div class="d-flex justify-center gap-1 mb-2"><VIcon v-for="s in 5" :key="s" icon="tabler-star" size="16" :color="s <= Math.round(kpis.avg_satisfaction) ? 'warning' : 'default'" /></div><p class="text-caption text-medium-emphasis mb-0">{{ t('offboarding.analytics_from_interviews', { count: kpis.interview_count }) }}</p></VCardText></VCard></VCol>
      <VCol cols="12" sm="6" lg="3"><VCard elevation="0" border height="100%"><VCardTitle class="pa-4 pb-2 text-body-2 font-weight-semibold">{{ t('offboarding.analytics_rehire') }}</VCardTitle><VCardText class="pa-4 pt-0"><div class="d-flex flex-column gap-3"><div><div class="d-flex justify-space-between text-caption mb-1"><span class="text-success">{{ t('offboarding.analytics_rehire_eligible') }}</span><span class="font-weight-medium">{{ rehire.eligible }}</span></div><VProgressLinear :model-value="(rehire.eligible + rehire.not_eligible) ? (rehire.eligible / (rehire.eligible + rehire.not_eligible)) * 100 : 0" color="success" rounded height="8" /></div><div><div class="d-flex justify-space-between text-caption mb-1"><span class="text-error">{{ t('offboarding.analytics_rehire_not_eligible') }}</span><span class="font-weight-medium">{{ rehire.not_eligible }}</span></div><VProgressLinear :model-value="(rehire.eligible + rehire.not_eligible) ? (rehire.not_eligible / (rehire.eligible + rehire.not_eligible)) * 100 : 0" color="error" rounded height="8" /></div></div></VCardText></VCard></VCol>
      <VCol cols="12" sm="6" lg="3"><VCard elevation="0" border height="100%"><VCardTitle class="pa-4 pb-2 text-body-2 font-weight-semibold">{{ t('offboarding.analytics_would_recommend') }}</VCardTitle><VCardText class="pa-4 pt-0"><div class="d-flex flex-column gap-3"><div><div class="d-flex justify-space-between text-caption mb-1"><span class="text-success">{{ t('offboarding.analytics_yes') }}</span><span class="font-weight-medium">{{ would_recommend.yes }}</span></div><VProgressLinear :model-value="(would_recommend.yes + would_recommend.no) ? (would_recommend.yes / (would_recommend.yes + would_recommend.no)) * 100 : 0" color="success" rounded height="8" /></div><div><div class="d-flex justify-space-between text-caption mb-1"><span class="text-error">{{ t('offboarding.analytics_no') }}</span><span class="font-weight-medium">{{ would_recommend.no }}</span></div><VProgressLinear :model-value="(would_recommend.yes + would_recommend.no) ? (would_recommend.no / (would_recommend.yes + would_recommend.no)) * 100 : 0" color="error" rounded height="8" /></div></div></VCardText></VCard></VCol>
    </VRow>

    <VRow>
      <VCol cols="12" md="6"><VCard elevation="0" border><VCardTitle class="pa-4 pb-2 text-body-2 font-weight-semibold">{{ t('offboarding.analytics_termination_type') }}</VCardTitle><VCardText class="pa-4 pt-0"><div v-if="by_type.length" class="d-flex flex-column gap-3"><div v-for="item in by_type" :key="item.label"><div class="d-flex justify-space-between text-caption mb-1"><span>{{ terminationLabel(item.label) }}</span><span class="font-weight-medium">{{ item.value }}</span></div><VProgressLinear :model-value="kpis.total_plans ? (item.value / kpis.total_plans) * 100 : 0" :color="terminationColor(item.label)" rounded height="10" /></div></div><p v-else class="text-caption text-medium-emphasis text-center py-6 mb-0">{{ t('offboarding.analytics_no_data') }}</p></VCardText></VCard></VCol>
      <VCol cols="12" md="6"><VCard elevation="0" border><VCardTitle class="pa-4 pb-2 text-body-2 font-weight-semibold">{{ t('offboarding.analytics_reasons') }}</VCardTitle><VCardText class="pa-4 pt-0"><div v-if="by_reason.length" class="d-flex flex-column gap-3"><div v-for="item in by_reason" :key="item.label"><div class="d-flex justify-space-between text-caption mb-1"><span>{{ reasonLabel(item.label) }}</span><span class="font-weight-medium">{{ item.value }}</span></div><VProgressLinear :model-value="(item.value / reasonMax) * 100" color="primary" rounded height="10" /></div></div><p v-else class="text-caption text-medium-emphasis text-center py-6 mb-0">{{ t('offboarding.analytics_no_interviews') }}</p></VCardText></VCard></VCol>
      <VCol cols="12" md="6"><VCard elevation="0" border><VCardTitle class="pa-4 pb-2 text-body-2 font-weight-semibold">{{ t('offboarding.analytics_monthly_volume') }}</VCardTitle><VCardText class="pa-4 pt-0"><div v-if="monthly_volume.length" class="d-flex align-end gap-2" style="height: 140px;"><div v-for="item in monthly_volume" :key="item.month" class="d-flex flex-column align-center flex-grow-1" style="min-width: 0;"><span class="text-caption font-weight-medium mb-1" style="font-size: 10px;">{{ item.total }}</span><div class="rounded-t w-100" style="background: rgba(var(--v-theme-primary), 0.7); transition: height 0.3s;" :style="{ height: `${(item.total / volumeMax) * 100}px`, minHeight: '4px' }" /><span class="text-caption text-medium-emphasis mt-1" style="font-size: 9px; white-space: nowrap;">{{ monthLabel(item.month) }}</span></div></div><p v-else class="text-caption text-medium-emphasis text-center py-6 mb-0">{{ t('offboarding.analytics_no_data') }}</p></VCardText></VCard></VCol>
      <VCol cols="12" md="6"><VCard elevation="0" border><VCardTitle class="pa-4 pb-2 text-body-2 font-weight-semibold">{{ t('offboarding.analytics_satisfaction_trend') }}</VCardTitle><VCardText class="pa-4 pt-0"><div v-if="satisfaction_trend.length" class="d-flex align-end gap-2" style="height: 140px;"><div v-for="item in satisfaction_trend" :key="item.month" class="d-flex flex-column align-center flex-grow-1" style="min-width: 0;"><span class="text-caption font-weight-medium mb-1" style="font-size: 10px;">{{ item.avg }}</span><div class="rounded-t w-100" style="background: rgba(var(--v-theme-warning), 0.7); transition: height 0.3s;" :style="{ height: `${(item.avg / satMax) * 100}px`, minHeight: '4px' }" /><span class="text-caption text-medium-emphasis mt-1" style="font-size: 9px; white-space: nowrap;">{{ monthLabel(item.month) }}</span></div></div><p v-else class="text-caption text-medium-emphasis text-center py-6 mb-0">{{ t('offboarding.analytics_no_satisfaction') }}</p></VCardText></VCard></VCol>
      <VCol v-if="counteroffer.accepted + counteroffer.declined > 0" cols="12" md="6"><VCard elevation="0" border><VCardTitle class="pa-4 pb-2 text-body-2 font-weight-semibold">{{ t('offboarding.analytics_counteroffer') }}</VCardTitle><VCardText class="pa-4 pt-0"><div class="d-flex flex-column gap-3"><div><div class="d-flex justify-space-between text-caption mb-1"><span class="text-success">{{ t('offboarding.analytics_counteroffer_accepted') }}</span><span class="font-weight-medium">{{ counteroffer.accepted }}</span></div><VProgressLinear :model-value="(counteroffer.accepted + counteroffer.declined) ? (counteroffer.accepted / (counteroffer.accepted + counteroffer.declined)) * 100 : 0" color="success" rounded height="8" /></div><div><div class="d-flex justify-space-between text-caption mb-1"><span class="text-error">{{ t('offboarding.analytics_counteroffer_declined') }}</span><span class="font-weight-medium">{{ counteroffer.declined }}</span></div><VProgressLinear :model-value="(counteroffer.accepted + counteroffer.declined) ? (counteroffer.declined / (counteroffer.accepted + counteroffer.declined)) * 100 : 0" color="error" rounded height="8" /></div></div></VCardText></VCard></VCol>
    </VRow>
  </div>
</template>
