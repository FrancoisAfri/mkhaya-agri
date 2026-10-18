<script lang="ts" setup>
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface Rep { name: string; won: number; weighted: number; target: number; gap: number; attainment: number | null }
interface CountValue { count: number; value: number }
interface Insights { headline: string; commit_confidence: string; risks: string[]; plays: string[] }
interface Flag { deal_id: number; title: string; type: string; detail: string; severity: string }

const props = defineProps<{
  rollup: { open_value: number; weighted: number; won_value: number; commit: number; quota: number; gap: number; attainment: number | null; open_count: number }
  reps: Rep[]; changed: { created: CountValue; won: CountValue; lost: CountValue; moved: number }
  insights: Insights | null; anomalies: Flag[] | null; aiEnabled: boolean
}>()

const money = (n: number) => 'R ' + (n ?? 0).toLocaleString('en-ZA', { maximumFractionDigits: 0 })
const attColor = (a: number | null) => a == null ? 'grey' : a >= 100 ? 'success' : a >= 60 ? 'primary' : 'warning'
const confColor = (c: string) => c === 'high' ? 'success' : c === 'low' ? 'error' : 'warning'
const sevColor: Record<string, string> = { high: 'error', medium: 'warning', low: 'blue-grey' }
function analyze() { router.post(route('crm.forecast.analyze'), {}, { preserveScroll: true }) }
function scanAnomalies() { router.post(route('crm.forecast.anomalies'), {}, { preserveScroll: true }) }

const headers = [
  { title: t('crm_forecast_index.table.rep'), key: 'name' },
  { title: t('crm_forecast_index.table.won'), key: 'won', align: 'end' as const },
  { title: t('crm_forecast_index.table.weighted'), key: 'weighted', align: 'end' as const },
  { title: t('crm_forecast_index.table.quota'), key: 'target', align: 'end' as const },
  { title: t('crm_forecast_index.table.gap'), key: 'gap', align: 'end' as const },
  { title: t('crm_forecast_index.table.attainment'), key: 'attainment', align: 'end' as const },
]
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3"><div><h5 class="text-h5 font-weight-semibold">{{ t('crm_forecast_index.page_title') }}</h5><p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_forecast_index.description') }}</p></div><div class="d-flex ga-2"><VBtn variant="tonal" prepend-icon="tabler-alert-triangle" :disabled="!aiEnabled" @click="scanAnomalies">{{ t('crm_forecast_index.scan_anomalies') }}</VBtn><VBtn color="primary" prepend-icon="tabler-sparkles" :disabled="!aiEnabled" @click="analyze">{{ t('crm_forecast_index.ai_forecast') }}</VBtn></div></div>

    <VCard v-if="anomalies && anomalies.length" class="mb-3" :title="t('crm_forecast_index.anomalies_title')"><VList lines="two"><template v-for="(f, i) in anomalies" :key="i"><VListItem :href="route('crm.deals')"><template #prepend><VIcon icon="tabler-alert-triangle" :color="sevColor[f.severity] ?? 'grey'" /></template><VListItemTitle>{{ f.title }} <VChip size="x-small" variant="tonal" class="ms-1">{{ f.type }}</VChip></VListItemTitle><VListItemSubtitle>{{ f.detail }}</VListItemSubtitle><template #append><VChip :color="sevColor[f.severity] ?? 'grey'" size="x-small" label class="text-capitalize">{{ f.severity }}</VChip></template></VListItem><VDivider /></template></VList></VCard>
    <VAlert v-else-if="anomalies" type="success" variant="tonal" class="mb-3">{{ t('crm_forecast_index.no_anomalies') }}</VAlert>

    <VRow class="mb-1">
      <VCol cols="12" sm="6" md="3"><VCard color="success" variant="tonal"><VCardText><div class="text-overline">{{ t('crm_forecast_index.kpis.won_mtd') }}</div><div class="text-h4 font-weight-bold">{{ money(rollup.won_value) }}</div></VCardText></VCard></VCol>
      <VCol cols="12" sm="6" md="3"><VCard variant="tonal"><VCardText><div class="text-overline">{{ t('crm_forecast_index.kpis.weighted_forecast') }}</div><div class="text-h4 font-weight-bold">{{ money(rollup.weighted) }}</div><div class="text-caption">{{ rollup.open_count }} {{ t('crm_forecast_index.kpis.open_deals') }}</div></VCardText></VCard></VCol>
      <VCol cols="12" sm="6" md="3"><VCard variant="tonal"><VCardText><div class="text-overline">{{ t('crm_forecast_index.kpis.commit') }}</div><div class="text-h4 font-weight-bold">{{ money(rollup.commit) }}</div></VCardText></VCard></VCol>
      <VCol cols="12" sm="6" md="3"><VCard variant="tonal"><VCardText><div class="text-overline">{{ t('crm_forecast_index.kpis.gap_to_quota') }}</div><div class="text-h4 font-weight-bold" :class="rollup.gap > 0 ? 'text-error' : 'text-success'">{{ money(rollup.gap) }}</div><div class="text-caption">{{ t('crm_forecast_index.kpis.attainment', { pct: rollup.attainment ?? '—', quota: money(rollup.quota) }) }}</div></VCardText></VCard></VCol>
    </VRow>

    <VCard v-if="insights" variant="tonal" class="my-3"><VCardText><div class="d-flex align-center ga-2 mb-2"><VIcon icon="tabler-sparkles" color="primary" /><span class="text-h6">{{ t('crm_forecast_index.ai_card.title') }}</span><VChip :color="confColor(insights.commit_confidence)" size="small" label class="text-capitalize">{{ insights.commit_confidence }} {{ t('crm_forecast_index.ai_card.confidence') }}</VChip></div><p class="text-body-1 mb-3">{{ insights.headline }}</p><VRow><VCol cols="12" md="6"><div class="text-overline text-medium-emphasis">{{ t('crm_forecast_index.ai_card.risks') }}</div><ul class="text-body-2 ps-4"><li v-for="(r, i) in insights.risks" :key="i">{{ r }}</li></ul></VCol><VCol cols="12" md="6"><div class="text-overline text-medium-emphasis">{{ t('crm_forecast_index.ai_card.plays') }}</div><ol class="text-body-2 ps-4"><li v-for="(p, i) in insights.plays" :key="i">{{ p }}</li></ol></VCol></VRow></VCardText></VCard>

    <VRow>
      <VCol cols="12" md="8"><VCard :title="t('crm_forecast_index.table.rep') + ' ' + t('crm_forecast_index.table.gap').toLowerCase() + ' ' + t('crm_forecast_index.table.quota').toLowerCase()"><VDataTable :headers="headers" :items="reps" :items-per-page="25" density="comfortable"><template #item.won="{ item }">{{ money(item.won) }}</template><template #item.weighted="{ item }">{{ money(item.weighted) }}</template><template #item.target="{ item }">{{ money(item.target) }}</template><template #item.gap="{ item }"><span :class="item.gap > 0 ? 'text-error' : 'text-success'">{{ money(item.gap) }}</span></template><template #item.attainment="{ item }"><VChip :color="attColor(item.attainment)" size="small" label>{{ item.attainment == null ? '—' : item.attainment + '%' }}</VChip></template></VDataTable></VCard></VCol>
      <VCol cols="12" md="4"><VCard :title="t('crm_forecast_index.changes.title')"><VCardText><div class="d-flex justify-space-between py-2"><span><VIcon icon="tabler-plus" size="16" class="me-1" />{{ t('crm_forecast_index.changes.new_deals') }}</span><strong>{{ changed.created.count }} · {{ money(changed.created.value) }}</strong></div><VDivider /><div class="d-flex justify-space-between py-2 text-success"><span><VIcon icon="tabler-trophy" size="16" class="me-1" />{{ t('crm_forecast_index.changes.won') }}</span><strong>{{ changed.won.count }} · {{ money(changed.won.value) }}</strong></div><VDivider /><div class="d-flex justify-space-between py-2 text-error"><span><VIcon icon="tabler-x" size="16" class="me-1" />{{ t('crm_forecast_index.changes.lost') }}</span><strong>{{ changed.lost.count }} · {{ money(changed.lost.value) }}</strong></div><VDivider /><div class="d-flex justify-space-between py-2"><span><VIcon icon="tabler-arrows-exchange" size="16" class="me-1" />{{ t('crm_forecast_index.changes.stage_moves') }}</span><strong>{{ changed.moved }}</strong></div></VCardText></VCard></VCol>
    </VRow>
  </div>
</template>