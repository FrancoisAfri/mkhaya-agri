<script lang="ts" setup>
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
const route = ziggyRoute
const { t } = useI18n()

interface ReportDef { label: string; headers: string[]; rows: (string | number)[][] }

const props = defineProps<{ summary: { open_count: number; open_value: number; weighted_pipeline: number; won_count: number; won_value: number; win_rate: number; avg_deal_size: number }; reports: Record<string, ReportDef>; filters: { from: string | null; to: string | null } }>()

const keys = Object.keys(props.reports)
const tab = ref(keys[0] ?? '')
const from = ref(props.filters.from ?? '')
const to = ref(props.filters.to ?? '')

const money = (n: number) => 'R ' + (n ?? 0).toLocaleString('en-ZA', { maximumFractionDigits: 0 })

const cards = computed(() => [
  { label: t('crm_reports_index.cards.open_deals'), value: props.summary.open_count, sub: money(props.summary.open_value) },
  { label: t('crm_reports_index.cards.weighted_forecast'), value: money(props.summary.weighted_pipeline), sub: t('crm_reports_index.cards.probability_adjusted') },
  { label: t('crm_reports_index.cards.won'), value: props.summary.won_count, sub: money(props.summary.won_value) },
  { label: t('crm_reports_index.cards.win_rate'), value: props.summary.win_rate + '%', sub: t('crm_reports_index.cards.avg') + ' ' + money(props.summary.avg_deal_size) },
])

const current = computed<ReportDef | undefined>(() => props.reports[tab.value])

function apply() { router.get(route('crm.reports'), { from: from.value || undefined, to: to.value || undefined }, { preserveState: false }) }
function exportCsv() { const qs = (from.value || to.value) ? `?from=${from.value}&to=${to.value}` : ''; window.location.href = route('crm.reports.export', tab.value) + qs }
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3"><div><h5 class="text-h5 font-weight-semibold">{{ t('crm_reports_index.page_title') }}</h5><p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_reports_index.description') }}</p></div><div class="d-flex gap-2 align-center"><VTextField v-model="from" type="date" :label="t('crm_reports_index.from')" density="compact" hide-details style="max-width: 160px" /><VTextField v-model="to" type="date" :label="t('crm_reports_index.to')" density="compact" hide-details style="max-width: 160px" /><VBtn variant="tonal" @click="apply">{{ t('crm_reports_index.apply') }}</VBtn></div></div>

    <VRow class="mb-2"><VCol v-for="c in cards" :key="c.label" cols="12" sm="6" md="3"><VCard><VCardText><div class="text-h5 font-weight-bold">{{ c.value }}</div><div class="text-body-2 text-medium-emphasis">{{ c.label }}</div><div class="text-caption text-disabled">{{ c.sub }}</div></VCardText></VCard></VCol></VRow>

    <VCard><VTabs v-model="tab" show-arrows><VTab v-for="(def, key) in reports" :key="key" :value="key">{{ def.label }}</VTab></VTabs><VDivider /><VCardText class="d-flex justify-end"><VBtn size="small" variant="tonal" prepend-icon="tabler-download" @click="exportCsv">{{ t('crm_reports_index.export_csv') }}</VBtn></VCardText>
      <VTable v-if="current" density="comfortable" class="text-no-wrap"><thead><tr><th v-for="h in current.headers" :key="h" class="text-capitalize">{{ String(h).replace(/_/g, ' ') }}</th></tr></thead><tbody><tr v-for="(row, i) in current.rows" :key="i"><td v-for="(cell, j) in row" :key="j">{{ cell }}</td></tr><tr v-if="!current.rows.length"><td :colspan="current.headers.length" class="text-medium-emphasis">{{ t('crm_reports_index.no_data') }}</td></tr></tbody></VTable>
    </VCard>
  </div>
</template>