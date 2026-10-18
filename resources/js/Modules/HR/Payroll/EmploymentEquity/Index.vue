<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const r = (key: string, a?: Record<string, unknown>) => t('bureau.equity.' + key, a ?? {})

type Grid = Record<string, Record<string, Record<string, number>>>

const props = defineProps<{
  report: {
    year: number
    period: { from: string; to: string }
    headcount: number
    profile: Grid
    income: Grid
    disabled: Grid
    foreign: number
    differential: {
      average_per_level: Record<string, number | null>
      widest_gap: { level: string; highest: string; lowest: string; gap_percent: number } | null
    }
    data_quality: {
      complete: boolean
      missing_race: string[]
      missing_gender: string[]
      missing_level: string[]
      missing_citizenship: string[]
    }
  }
  year: number
  years: number[]
  levels: string[]
  races: string[]
  genders: string[]
}>()

const chosenYear = ref(props.year)
function load() {
  router.get(route('payroll.employment-equity.index'), { year: chosenYear.value }, { preserveState: true })
}

const rand = (n: number | null) =>
  n === null ? '—' : 'R ' + Number(n).toLocaleString('en-ZA', { maximumFractionDigits: 0 })

/**
 * Only the columns that hold somebody. Every race against every gender is eighteen columns of
 * mostly zeroes, which is a table nobody reads.
 */
const columns = computed(() => {
  const out: { race: string; gender: string }[] = []
  for (const race of props.races) {
    for (const gender of props.genders) {
      const used = props.levels.some(l => (props.report.profile[l]?.[race]?.[gender] ?? 0) > 0)
      if (used) out.push({ race, gender })
    }
  }
  return out
})

const count = (grid: Grid, level: string, race: string, gender: string) => grid[level]?.[race]?.[gender] ?? 0

const average = (level: string, race: string, gender: string) => {
  const heads = count(props.report.profile, level, race, gender)
  return heads === 0 ? null : count(props.report.income, level, race, gender) / heads
}

const rowTotal = (grid: Grid, level: string) =>
  columns.value.reduce((sum, c) => sum + count(grid, level, c.race, c.gender), 0)

const gaps = computed(() => {
  const q = props.report.data_quality
  return [
    { key: 'race', names: q.missing_race },
    { key: 'gender', names: q.missing_gender },
    { key: 'level', names: q.missing_level },
    { key: 'citizenship', names: q.missing_citizenship },
  ].filter(g => g.names.length > 0)
})
</script>

<template>
  <div class="pa-4" style="max-width:1400px;margin:auto">
    <div class="d-flex align-center justify-space-between flex-wrap ga-3 mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ r('page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ r('period', { from: report.period.from, to: report.period.to }) }}
        </p>
      </div>
      <div class="d-flex align-center ga-2">
        <VSelect
          v-model="chosenYear" :items="years" :label="r('year')"
          density="compact" variant="outlined" hide-details style="min-width:9rem"
          @update:model-value="load"
        />
        <VBtn
          :href="route('payroll.employment-equity.download', { year })"
          color="primary" variant="tonal" prepend-icon="tabler-download"
        >{{ r('download') }}</VBtn>
      </div>
    </div>

    <VAlert type="info" variant="tonal" density="compact" class="mb-4">{{ r('what_this_is') }}</VAlert>

    <VRow class="mb-2">
      <VCol cols="6" sm="3">
        <VCard elevation="0" border><VCardText class="pa-3">
          <div class="text-caption text-medium-emphasis">{{ r('headcount') }}</div>
          <div class="text-h6">{{ report.headcount }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border><VCardText class="pa-3">
          <div class="text-caption text-medium-emphasis">{{ r('foreign') }}</div>
          <div class="text-h6">{{ report.foreign }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="12" sm="6">
        <VCard elevation="0" border><VCardText class="pa-3">
          <div class="text-caption text-medium-emphasis">{{ r('widest_gap') }}</div>
          <template v-if="report.differential.widest_gap">
            <div class="text-h6">{{ report.differential.widest_gap.gap_percent }}%</div>
            <div class="text-caption text-medium-emphasis">
              {{ r('gap_detail', {
                level: r('levels.' + report.differential.widest_gap.level),
                high: report.differential.widest_gap.highest,
                low: report.differential.widest_gap.lowest,
              }) }}
            </div>
          </template>
          <div v-else class="text-body-2 text-medium-emphasis">{{ r('no_gap') }}</div>
        </VCardText></VCard>
      </VCol>
    </VRow>

    <!-- The return is only as good as the people record behind it, so the holes are named. -->
    <VAlert
      v-if="gaps.length" type="warning" variant="tonal" class="mb-4"
      :title="r('gaps_title')"
    >
      <p class="text-body-2 mb-2">{{ r('gaps_help') }}</p>
      <div v-for="gap in gaps" :key="gap.key" class="mb-1">
        <strong>{{ r('missing_' + gap.key) }}</strong>
        <span class="text-body-2"> — {{ gap.names.length }}: {{ gap.names.slice(0, 8).join(', ') }}<span v-if="gap.names.length > 8"> …</span></span>
      </div>
    </VAlert>
    <VAlert v-else type="success" variant="tonal" density="compact" class="mb-4">{{ r('gaps_none') }}</VAlert>

    <VCard elevation="0" border class="mb-4">
      <VCardTitle class="text-subtitle-1">{{ r('eea2') }}</VCardTitle>
      <VCardSubtitle class="pb-2">{{ r('eea2_help') }}</VCardSubtitle>
      <div style="overflow-x:auto">
        <VTable density="compact" class="grid">
          <thead>
            <tr>
              <th>{{ r('level') }}</th>
              <th v-for="c in columns" :key="c.race + c.gender" class="text-end">
                {{ r('races.' + c.race) }}<br><span class="text-caption text-medium-emphasis">{{ r('genders.' + c.gender) }}</span>
              </th>
              <th class="text-end">{{ r('total') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="level in levels" :key="level">
              <td>{{ r('levels.' + level) }}</td>
              <td v-for="c in columns" :key="c.race + c.gender" class="text-end">
                {{ count(report.profile, level, c.race, c.gender) || '' }}
              </td>
              <td class="text-end font-weight-medium">{{ rowTotal(report.profile, level) || '' }}</td>
            </tr>
          </tbody>
        </VTable>
      </div>
    </VCard>

    <VCard elevation="0" border>
      <VCardTitle class="text-subtitle-1">{{ r('eea4') }}</VCardTitle>
      <VCardSubtitle class="pb-2">{{ r('eea4_help') }}</VCardSubtitle>
      <div style="overflow-x:auto">
        <VTable density="compact" class="grid">
          <thead>
            <tr>
              <th>{{ r('level') }}</th>
              <th v-for="c in columns" :key="c.race + c.gender" class="text-end">
                {{ r('races.' + c.race) }}<br><span class="text-caption text-medium-emphasis">{{ r('genders.' + c.gender) }}</span>
              </th>
              <th class="text-end">{{ r('average') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="level in levels" :key="level">
              <td>{{ r('levels.' + level) }}</td>
              <td v-for="c in columns" :key="c.race + c.gender" class="text-end">
                {{ average(level, c.race, c.gender) === null ? '' : rand(average(level, c.race, c.gender)) }}
              </td>
              <td class="text-end font-weight-medium">{{ rand(report.differential.average_per_level[level]) }}</td>
            </tr>
          </tbody>
        </VTable>
      </div>
    </VCard>
  </div>
</template>

<style scoped>
/* Figures in a grid are compared down the column, so they line up on the digit. */
.grid :deep(td), .grid :deep(th) { font-variant-numeric: tabular-nums; white-space: nowrap; }
</style>
