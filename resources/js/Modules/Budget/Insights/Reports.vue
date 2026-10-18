<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Dataset = {
  label: string
  area: string
  /** Which screen the rows are captured on — see budgets_insights.report_sources.*. */
  source: string
  headers: string[]
  rows: any[][]
  truncated: boolean
}

const props = defineProps<{
  summary: Record<string, number>
  reports: Record<string, Dataset>
  financialYears: Array<{ id: number; label: string }>
  budgets: Array<{ id: number; label: string }>
  filters: { from: string | null; to: string | null; financial_year_id: number | null; budget_id: number | null }
}>()

const keys = computed(() => Object.keys(props.reports))
const active = ref(keys.value[0] ?? '')

const current = computed(() => props.reports[active.value])

/** Grouped by the part of the module each belongs to, rather than one flat run of twelve tabs. */
const areas = computed(() => {
  const grouped: Record<string, string[]> = {}

  for (const key of keys.value) {
    const area = props.reports[key].area
    ;(grouped[area] ??= []).push(key)
  }

  return grouped
})

// ── Filters ──────────────────────────────────────────────────────────────────

const from = ref(props.filters.from ?? '')
const to = ref(props.filters.to ?? '')
const year = ref<number | null>(props.filters.financial_year_id ?? null)
const budget = ref<number | null>(props.filters.budget_id ?? null)

function applyFilters() {
  router.get(route('budgets.insights.reports'), {
    from: from.value || undefined,
    to: to.value || undefined,
    financial_year_id: year.value || undefined,
    budget_id: budget.value || undefined,
  }, { preserveState: true, preserveScroll: true })
}

function clearFilters() {
  from.value = ''
  to.value = ''
  year.value = null
  budget.value = null
  applyFilters()
}

function exportCsv() {
  // A normal navigation, not XHR: the browser has to receive it as a download.
  const params = new URLSearchParams()
  if (from.value) params.set('from', from.value)
  if (to.value) params.set('to', to.value)
  if (year.value) params.set('financial_year_id', String(year.value))
  if (budget.value) params.set('budget_id', String(budget.value))

  window.location.href = `${route('budgets.insights.export', active.value)}?${params.toString()}`
}

function cell(value: any) {
  if (value === null || value === undefined || value === '') return '—'
  return value
}

function money(v: number) {
  try {
    return new Intl.NumberFormat(undefined, {
      style: 'currency', currency: 'ZAR', notation: 'compact', maximumFractionDigits: 1,
    }).format(v)
  } catch {
    return String(Math.round(v))
  }
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('budgets_insights.reports_title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('budgets_insights.reports_sub') }}</p>
      </div>
      <VBtn
        variant="tonal" prepend-icon="tabler-chart-histogram"
        @click="router.get(route('budgets.insights.analytics'))"
      >
        {{ t('budgets_insights.analytics_title') }}
      </VBtn>
    </div>

    <!-- Headline figures -->
    <VRow class="mb-2">
      <VCol v-for="(value, key) in summary" :key="key" cols="6" md="3" lg="2">
        <VCard border>
          <VCardText class="py-3">
            <div class="text-caption text-medium-emphasis">{{ t(`budgets_insights.kpi_${key}`) }}</div>
            <div class="text-h6" :class="key === 'available' && value < 0 ? 'text-error' : ''">
              {{ key === 'budgets' ? value : money(value) }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Filters -->
    <VCard border class="mb-4">
      <VCardText class="d-flex flex-wrap align-center gap-3">
        <VTextField
          v-model="from" type="date" :label="t('budgets_insights.from')"
          variant="outlined" density="compact" hide-details style="max-inline-size: 190px"
        />
        <VTextField
          v-model="to" type="date" :label="t('budgets_insights.to')"
          variant="outlined" density="compact" hide-details style="max-inline-size: 190px"
        />
        <VSelect
          v-model="year"
          :items="[{ title: t('budgets_insights.all_years'), value: null }, ...financialYears.map(y => ({ title: y.label, value: y.id }))]"
          :label="t('budgets_insights.financial_year')"
          variant="outlined" density="compact" hide-details style="min-inline-size: 200px"
        />
        <VAutocomplete
          v-model="budget"
          :items="[{ title: t('budgets_insights.all_budgets'), value: null }, ...budgets.map(b => ({ title: b.label, value: b.id }))]"
          :label="t('budgets_insights.budget')"
          variant="outlined" density="compact" hide-details style="min-inline-size: 260px"
        />
        <VBtn color="primary" @click="applyFilters">{{ t('budgets_insights.apply') }}</VBtn>
        <VBtn variant="text" @click="clearFilters">{{ t('budgets_insights.clear') }}</VBtn>
      </VCardText>
    </VCard>

    <VRow>
      <!-- Report picker, grouped by area -->
      <VCol cols="12" md="3">
        <VCard border>
          <VList density="compact" nav>
            <template v-for="(group, area) in areas" :key="area">
              <VListSubheader>{{ t(`budgets_insights.area_${area}`) }}</VListSubheader>
              <VListItem
                v-for="key in group" :key="key"
                :active="active === key" color="primary"
                @click="active = key"
              >
                <VListItemTitle class="text-body-2">{{ reports[key].label }}</VListItemTitle>
                <template #append>
                  <span class="text-caption text-medium-emphasis">{{ reports[key].rows.length }}</span>
                </template>
              </VListItem>
            </template>
          </VList>
        </VCard>
      </VCol>

      <!-- The dataset -->
      <VCol cols="12" md="9">
        <VCard v-if="current" border>
          <VCardItem>
            <VCardTitle class="text-body-1">{{ current.label }}</VCardTitle>
            <!--
              Where the numbers come from. An empty BI table with no explanation reads as a broken
              feature; nine times out of ten it means nobody has entered this yet, and saying so is
              the difference between a support ticket and somebody getting on with it.
            -->
            <VCardSubtitle>{{ t(`budgets_insights.report_sources.${current.source}`) }}</VCardSubtitle>
            <template #append>
              <VBtn size="small" variant="tonal" prepend-icon="tabler-download" @click="exportCsv">
                {{ t('budgets_insights.export_csv') }}
              </VBtn>
            </template>
          </VCardItem>
          <VDivider />

          <VAlert v-if="current.truncated" type="info" variant="tonal" density="compact" class="ma-3">
            {{ t('budgets_insights.truncated') }}
          </VAlert>

          <div v-if="current.rows.length" class="table-scroll">
            <VTable density="compact">
              <thead>
                <tr>
                  <th v-for="header in current.headers" :key="header" class="text-no-wrap">{{ header }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, i) in current.rows" :key="i">
                  <td v-for="(value, j) in row" :key="j" class="text-no-wrap">{{ cell(value) }}</td>
                </tr>
              </tbody>
            </VTable>
          </div>

          <VCardText v-else class="text-center text-medium-emphasis py-8">
            <p class="mb-2">{{ t('budgets_insights.no_rows') }}</p>
            <p class="text-caption mb-0">{{ t(`budgets_insights.report_sources.${current.source}`) }}</p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>

<style scoped>
/* Wide datasets scroll inside the card rather than pushing the page sideways. */
.table-scroll {
  overflow-x: auto;
  max-block-size: 60vh;
  overflow-y: auto;
}
</style>
