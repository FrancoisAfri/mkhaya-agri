<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import VueApexCharts from 'vue3-apexcharts'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Cohort = { cohort: string; headcount: number; avg_score: number; high_rate?: number; low_rate?: number }
type Promo = { cohort: string; total: number; ready: number; ready_rate: number }

const props = defineProps<{
  cycles: { id: number; name: string }[]
  cycleId: number
  cohorts: { gender: Cohort[]; ethnicity: Cohort[]; disability: Cohort[] }
  promotion: Promo[]
}>()

const selectedCycle = ref(props.cycleId)
function changeCycle() { router.get(route('performance.dei.index'), { cycle_id: selectedCycle.value }, { preserveState: false }) }

function barOptions(labels: string[], title: string): any {
  return {
    chart: { type: 'bar', toolbar: { show: false }, fontFamily: 'inherit' },
    title: { text: title, style: { fontSize: '13px' } },
    colors: ['#6366f1'],
    plotOptions: { bar: { borderRadius: 4, columnWidth: '50%', distributed: false } },
    dataLabels: { enabled: true },
    xaxis: { categories: labels, labels: { style: { fontSize: '11px' } } },
    grid: { borderColor: 'rgba(120,120,120,0.12)' },
    tooltip: { theme: 'dark' },
  }
}

const genderAvg = computed(() => ({
  labels: props.cohorts.gender.map(c => c.cohort),
  series: [{ name: 'Avg score', data: props.cohorts.gender.map(c => c.avg_score) }],
}))

const ethnicityAvg = computed(() => ({
  labels: props.cohorts.ethnicity.map(c => c.cohort),
  series: [{ name: 'Avg score', data: props.cohorts.ethnicity.map(c => c.avg_score) }],
}))

const promoSeries = computed(() => ({
  labels: props.promotion.map(p => p.cohort),
  series: [{ name: 'Promotion-ready %', data: props.promotion.map(p => p.ready_rate) }],
}))
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between gap-3 mb-4">
      <div class="d-flex align-center gap-3">
        <VAvatar color="deep-purple" variant="tonal" size="48" rounded><VIcon icon="tabler-scale" size="26" /></VAvatar>
        <div>
          <h4 class="text-h4 mb-0">{{ t('pa_dei.title') }}</h4>
          <p class="text-caption text-medium-emphasis mb-0">{{ t('pa_dei.subtitle') }}</p>
        </div>
      </div>
      <VSelect v-model="selectedCycle" :items="cycles" item-title="name" item-value="id" density="compact"
        variant="outlined" :label="t('pa_dei.cycle_label')" style="min-width: 220px" hide-details @update:model-value="changeCycle" />
    </div>

    <VAlert type="info" variant="tonal" density="comfortable" class="mb-4">
      {{ t('pa_dei.alert_text') }}
    </VAlert>

    <VRow>
      <VCol cols="12" md="6">
        <VCard border><VCardText>
          <VueApexCharts v-if="genderAvg.labels.length" type="bar" height="280" :options="barOptions(genderAvg.labels, t('pa_dei.charts.avg_score_gender'))" :series="genderAvg.series" />
          <div v-else class="text-center py-10 text-medium-emphasis">{{ t('pa_dei.empty.no_data') }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="12" md="6">
        <VCard border><VCardText>
          <VueApexCharts v-if="ethnicityAvg.labels.length" type="bar" height="280" :options="barOptions(ethnicityAvg.labels, t('pa_dei.charts.avg_score_ethnicity'))" :series="ethnicityAvg.series" />
          <div v-else class="text-center py-10 text-medium-emphasis">{{ t('pa_dei.empty.no_data') }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="12" md="6">
        <VCard border><VCardText>
          <VueApexCharts v-if="promoSeries.labels.length" type="bar" height="280" :options="barOptions(promoSeries.labels, t('pa_dei.charts.promotion_ready_gender'))" :series="promoSeries.series" />
          <div v-else class="text-center py-10 text-medium-emphasis">{{ t('pa_dei.empty.no_promotion') }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="12" md="6">
        <VCard border>
          <VCardTitle class="text-subtitle-1">{{ t('pa_dei.disability.title') }}</VCardTitle>
          <VTable density="comfortable">
            <thead>
              <tr>
                <th>{{ t('pa_dei.disability.cohort') }}</th>
                <th>{{ t('pa_dei.disability.headcount') }}</th>
                <th>{{ t('pa_dei.disability.avg_score') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="d in cohorts.disability" :key="d.cohort">
                <td>{{ d.cohort }}</td>
                <td>{{ d.headcount }}</td>
                <td>{{ d.avg_score }}</td>
              </tr>
              <tr v-if="!cohorts.disability.length">
                <td colspan="3" class="text-center text-medium-emphasis py-6">{{ t('pa_dei.empty.no_disability') }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
