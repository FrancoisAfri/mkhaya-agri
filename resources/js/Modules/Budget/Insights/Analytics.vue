<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import BudgetCharts from './BudgetCharts.vue'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  charts: Record<string, any>
  summary: Record<string, number>
  financialYears: Array<{ id: number; label: string }>
  filters: { financial_year_id: number | null }
}>()

const year = ref<number | null>(props.filters.financial_year_id)

function applyYear() {
  router.get(route('budgets.insights.analytics'), { financial_year_id: year.value || undefined }, {
    preserveState: true, preserveScroll: true,
  })
}

/** Money for a KPI tile — compact, because a tile is not where anybody reads cents. */
function money(v: number) {
  try {
    return new Intl.NumberFormat(undefined, {
      style: 'currency', currency: 'ZAR', notation: 'compact', maximumFractionDigits: 1,
    }).format(v)
  } catch {
    return String(Math.round(v))
  }
}

const moneyKeys = ['allocated', 'reserved', 'committed', 'actual', 'overrides', 'available']
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('budgets_insights.analytics_title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('budgets_insights.analytics_sub') }}</p>
      </div>
      <div class="d-flex align-center gap-2 flex-wrap">
        <VSelect
          v-model="year"
          :items="[{ title: t('budgets_insights.all_years'), value: null }, ...financialYears.map(y => ({ title: y.label, value: y.id }))]"
          :label="t('budgets_insights.financial_year')"
          variant="outlined" density="compact" hide-details style="min-inline-size: 200px"
          @update:model-value="applyYear"
        />
        <VBtn variant="tonal" prepend-icon="tabler-file-analytics" @click="router.get(route('budgets.insights.reports'))">
          {{ t('budgets_insights.reports_title') }}
        </VBtn>
      </div>
    </div>

    <!-- Headline figures -->
    <VRow class="mb-2">
      <VCol v-for="(value, key) in summary" :key="key" cols="6" md="3" lg="2">
        <VCard border>
          <VCardText class="py-3">
            <div class="text-caption text-medium-emphasis">{{ t(`budgets_insights.kpi_${key}`) }}</div>
            <div class="text-h6" :class="key === 'available' && value < 0 ? 'text-error' : ''">
              {{ moneyKeys.includes(String(key)) ? money(value) : (key === 'utilisation' ? `${value}%` : value) }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <BudgetCharts :charts="charts as any" :height="280" />
  </div>
</template>
