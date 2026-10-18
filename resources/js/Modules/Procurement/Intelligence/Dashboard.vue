<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import VueApexCharts from 'vue3-apexcharts'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Insight = {
  id: number; type: string; category: string; severity: string; score: number
  title: string; description: string | null; amount_impact: number; status: string
  entity_type: string | null; entity_id: number | null
}
type Score = { scope_type: string; scope_id: number | null; label: string | null; score: number; band: string }

const props = defineProps<{
  filters: Record<string, string>
  insights: Insight[]
  kpis: { open: number; risk: number; critical: number; high: number; risk_exposure: number; savings: number; forecast_overrun: number }
  byType: Record<string, number>
  bySeverity: { critical: number; high: number; medium: number; low: number }
  riskScores: { overall: Score | null; suppliers: Score[]; budgets: Score[] }
  narrative: { text: string; provider: string | null; model: string | null; generated: string } | null
  types: string[]
}>()

const sev = ref(props.filters.severity ?? '')
const cat = ref(props.filters.category ?? '')
const running = ref(false)
const generating = ref(false)

function money(v: number) {
  try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'ZAR', maximumFractionDigits: 0 }).format(v) }
  catch { return `R ${v}` }
}
function applyFilters() {
  router.get(route('procurement.intelligence.index'),
    { severity: sev.value || undefined, category: cat.value || undefined },
    { preserveState: true, replace: true })
}
function runAnalysis() {
  running.value = true
  router.post(route('procurement.intelligence.run'), {}, { preserveScroll: true, onFinish: () => (running.value = false) })
}
function generateBriefing() {
  generating.value = true
  router.post(route('procurement.intelligence.narrative'), {}, { preserveScroll: true, onFinish: () => (generating.value = false) })
}
function setStatus(i: Insight, status: string) {
  router.post(route('procurement.intelligence.insights.update', i.id), { status }, { preserveScroll: true })
}

const severityColor: Record<string, string> = { critical: 'error', high: 'deep-orange', medium: 'warning', low: 'grey' }
const catColor: Record<string, string> = { risk: 'error', saving: 'success', forecast: 'info' }

const severityChart = computed(() => ({
  series: [props.bySeverity.critical, props.bySeverity.high, props.bySeverity.medium, props.bySeverity.low],
  options: {
    labels: [t('spend_intelligence.severities.critical'), t('spend_intelligence.severities.high'), t('spend_intelligence.severities.medium'), t('spend_intelligence.severities.low')],
    colors: ['#d32f2f', '#f4511e', '#fb8c00', '#9e9e9e'],
    legend: { position: 'bottom' }, chart: { type: 'donut' },
  },
}))
const typeChart = computed(() => {
  const keys = Object.keys(props.byType)
  return {
    series: [{ name: t('spend_intelligence.findings.title'), data: keys.map(k => props.byType[k]) }],
    options: {
      chart: { type: 'bar', toolbar: { show: false } },
      plotOptions: { bar: { horizontal: true, borderRadius: 4 } },
      xaxis: { categories: keys.map(k => k.replace('_', ' ')) },
      colors: ['#5e35b1'],
    },
  }
})

const narrativeHtml = computed(() => {
  if (!props.narrative?.text) return ''
  return props.narrative.text
    .replace(/^### (.*)$/gm, '<h6 class="text-h6 mt-3 mb-1">$1</h6>')
    .replace(/^## (.*)$/gm, '<h5 class="text-h6 mt-4 mb-2">$1</h5>')
    .replace(/^\d+\. (.*)$/gm, '<li>$1</li>')
    .replace(/^- (.*)$/gm, '<li>$1</li>')
    .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
    .replace(/\n{2,}/g, '<br>')
})

const catItems = computed(() => [
  { title: t('spend_intelligence.categories.risk'), value: 'risk' },
  { title: t('spend_intelligence.categories.saving'), value: 'saving' },
  { title: t('spend_intelligence.categories.forecast'), value: 'forecast' },
])

const sevItems = computed(() => [
  { title: t('spend_intelligence.severities.critical'), value: 'critical' },
  { title: t('spend_intelligence.severities.high'), value: 'high' },
  { title: t('spend_intelligence.severities.medium'), value: 'medium' },
  { title: t('spend_intelligence.severities.low'), value: 'low' },
])
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div>
        <h4 class="text-h4 mb-0">{{ t('spend_intelligence.page_title') }}</h4>
        <p class="text-caption text-medium-emphasis mb-0">{{ t('spend_intelligence.description') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VBtn color="primary" :loading="running" prepend-icon="tabler-radar" @click="runAnalysis">{{ t('spend_intelligence.run_analysis') }}</VBtn>
        <VBtn color="secondary" variant="tonal" :loading="generating" prepend-icon="tabler-robot" @click="generateBriefing">{{ t('spend_intelligence.ai_briefing') }}</VBtn>
      </div>
    </div>

    <!-- KPIs -->
    <VRow class="mb-2">
      <VCol cols="6" md="3">
        <VCard border>
          <VCardText>
            <div class="text-overline">{{ t('spend_intelligence.kpis.open_findings') }}</div>
            <div class="text-h4">{{ kpis.open }}</div>
            <div class="text-caption text-error">{{ t('spend_intelligence.kpis.critical_high', { critical: kpis.critical, high: kpis.high }) }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard border>
          <VCardText>
            <div class="text-overline">{{ t('spend_intelligence.kpis.risk_exposure') }}</div>
            <div class="text-h4">{{ money(kpis.risk_exposure) }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard border>
          <VCardText>
            <div class="text-overline">{{ t('spend_intelligence.kpis.savings_found') }}</div>
            <div class="text-h4 text-success">{{ money(kpis.savings) }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard border>
          <VCardText>
            <div class="text-overline">{{ t('spend_intelligence.kpis.overall_risk') }}</div>
            <div class="text-h4">{{ riskScores.overall ? Math.round(Number(riskScores.overall.score)) : '—' }}</div>
            <VChip v-if="riskScores.overall" size="x-small" :color="severityColor[riskScores.overall.band] || 'grey'" variant="tonal">{{ t(`spend_intelligence.severities.${riskScores.overall.band}`) }}</VChip>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- AI briefing -->
    <VCard v-if="narrative" border class="mb-4">
      <VCardTitle class="d-flex align-center justify-space-between">
        <span>{{ t('spend_intelligence.executive_briefing') }}</span>
        <span class="text-caption text-medium-emphasis">{{ narrative.provider ? t('spend_intelligence.provider_model', { provider: narrative.provider, generated: narrative.generated }) : narrative.generated }}</span>
      </VCardTitle>
      <VCardText><div class="text-body-2" v-html="narrativeHtml" /></VCardText>
    </VCard>

    <VRow>
      <VCol cols="12" md="8">
        <!-- Findings -->
        <VCard border class="mb-4">
          <VCardTitle class="d-flex flex-wrap align-center justify-space-between gap-2">
            <span>{{ t('spend_intelligence.findings.title') }}</span>
            <div class="d-flex gap-2">
              <VSelect v-model="cat" :items="catItems" :label="t('spend_intelligence.filters.category')" density="compact" variant="outlined" hide-details clearable style="max-width:150px" @update:model-value="applyFilters" />
              <VSelect v-model="sev" :items="sevItems" :label="t('spend_intelligence.filters.severity')" density="compact" variant="outlined" hide-details clearable style="max-width:150px" @update:model-value="applyFilters" />
            </div>
          </VCardTitle>
          <VCardText>
            <div v-if="!insights.length" class="text-center text-medium-emphasis py-8">{{ t('spend_intelligence.findings.no_findings') }}</div>
            <VCard v-for="i in insights" :key="i.id" variant="tonal" class="mb-2" :color="i.status !== 'open' ? 'surface' : undefined">
              <VCardText class="d-flex align-start gap-3 py-3">
                <VChip size="small" :color="severityColor[i.severity] || 'grey'" variant="flat" class="mt-1">{{ t(`spend_intelligence.severities.${i.severity}`) }}</VChip>
                <div class="flex-grow-1">
                  <div class="d-flex align-center gap-2">
                    <span class="font-weight-medium">{{ i.title }}</span>
                    <VChip size="x-small" :color="catColor[i.category] || 'grey'" variant="tonal">{{ i.type.replace('_',' ') }}</VChip>
                    <VChip v-if="i.status !== 'open'" size="x-small" variant="outlined">{{ t(`spend_intelligence.statuses.${i.status}`) }}</VChip>
                  </div>
                  <div class="text-caption text-medium-emphasis">{{ i.description }}</div>
                </div>
                <div class="text-right">
                  <div class="font-weight-bold">{{ money(i.amount_impact) }}</div>
                  <VMenu>
                    <template #activator="{ props: p }"><VBtn v-bind="p" size="x-small" variant="text" icon="tabler-dots-vertical" /></template>
                    <VList density="compact">
                      <VListItem :title="t('spend_intelligence.actions.mark_reviewed')" @click="setStatus(i,'reviewed')" />
                      <VListItem :title="t('spend_intelligence.actions.mark_actioned')" @click="setStatus(i,'actioned')" />
                      <VListItem :title="t('spend_intelligence.actions.dismiss')" @click="setStatus(i,'dismissed')" />
                      <VListItem :title="t('spend_intelligence.actions.reopen')" @click="setStatus(i,'open')" />
                    </VList>
                  </VMenu>
                </div>
              </VCardText>
            </VCard>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard border class="mb-4">
          <VCardTitle>{{ t('spend_intelligence.charts.severity_mix') }}</VCardTitle>
          <VCardText>
            <VueApexCharts v-if="kpis.open" type="donut" height="240" :options="severityChart.options" :series="severityChart.series" />
            <div v-else class="text-medium-emphasis text-center py-6">{{ t('spend_intelligence.charts.no_data') }}</div>
          </VCardText>
        </VCard>

        <VCard border class="mb-4">
          <VCardTitle>{{ t('spend_intelligence.charts.by_type') }}</VCardTitle>
          <VCardText>
            <VueApexCharts v-if="kpis.open" type="bar" height="260" :options="typeChart.options" :series="typeChart.series" />
            <div v-else class="text-medium-emphasis text-center py-6">{{ t('spend_intelligence.charts.no_data') }}</div>
          </VCardText>
        </VCard>

        <VCard border>
          <VCardTitle>{{ t('spend_intelligence.risk_scores.title') }}</VCardTitle>
          <VCardText>
            <div class="text-overline">{{ t('spend_intelligence.risk_scores.suppliers') }}</div>
            <div v-if="!riskScores.suppliers.length" class="text-caption text-medium-emphasis mb-2">{{ t('spend_intelligence.risk_scores.none') }}</div>
            <div v-for="s in riskScores.suppliers" :key="'s'+s.scope_id" class="d-flex align-center justify-space-between py-1">
              <span class="text-body-2 text-truncate" style="max-width:180px">{{ s.label }}</span>
              <VChip size="x-small" :color="severityColor[s.band] || 'grey'" variant="tonal">{{ Math.round(Number(s.score)) }}</VChip>
            </div>
            <VDivider class="my-2" />
            <div class="text-overline">{{ t('spend_intelligence.risk_scores.budgets') }}</div>
            <div v-if="!riskScores.budgets.length" class="text-caption text-medium-emphasis">{{ t('spend_intelligence.risk_scores.none') }}</div>
            <div v-for="b in riskScores.budgets" :key="'b'+b.scope_id" class="d-flex align-center justify-space-between py-1">
              <span class="text-body-2 text-truncate" style="max-width:180px">{{ b.label }}</span>
              <VChip size="x-small" :color="severityColor[b.band] || 'grey'" variant="tonal">{{ Math.round(Number(b.score)) }}</VChip>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
