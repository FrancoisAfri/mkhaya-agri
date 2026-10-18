<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Cycle = { id: number; name: string; cycle_type: string; status: string }
type ReportRow = {
  employee_id: number
  employee_name: string
  total_kpis: number
  scored_kpis: number
  avg_score_pct: number
  total_weighted_score: number
}

const props = defineProps<{
  cycle: Cycle
  report: ReportRow[]
}>()

const cycleStatusColor = (s: string) => ({
  active: 'success', draft: 'default', closed: 'error', archived: 'secondary',
})[s] ?? 'default'

const scoreColor = (v: number) => {
  if (v >= 80) return 'success'
  if (v >= 60) return 'warning'
  return 'error'
}

const sortedReport = computed(() =>
  [...props.report].sort((a, b) => b.total_weighted_score - a.total_weighted_score)
)

const totalEmployees = computed(() => props.report.length)

const avgScore = computed(() => {
  if (!props.report.length) return 0
  const sum = props.report.reduce((acc, r) => acc + r.avg_score_pct, 0)
  return Math.round(sum / props.report.length)
})

const fullyScored = computed(() =>
  props.report.filter(r => r.scored_kpis === r.total_kpis && r.total_kpis > 0).length
)

const tableHeaders = computed(() => [
  { title: t('pa.kpis.col_rank'),            key: 'rank',                  sortable: false, width: '60px' },
  { title: t('pa.common.employee'),       key: 'employee_name',         sortable: true  },
  { title: t('pa.kpis.total_kpis'),          key: 'total_kpis',            sortable: true  },
  { title: t('pa.kpis.scored'),              key: 'scored_kpis',           sortable: true  },
  { title: t('pa.kpis.completion_pct'),      key: 'completion_pct',        sortable: true  },
  { title: t('pa.kpis.avg_score'),           key: 'avg_score_pct',         sortable: true  },
  { title: t('pa.kpis.weighted_score'),      key: 'total_weighted_score',  sortable: true  },
])

const tableItems = computed(() =>
  sortedReport.value.map((row, index) => ({
    ...row,
    rank: index + 1,
    completion_pct: row.total_kpis > 0
      ? Number((row.scored_kpis / row.total_kpis * 100).toFixed(0))
      : 0,
  }))
)
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center mb-4 flex-wrap gap-3">
      <div class="flex-grow-1">
        <h1 class="text-h5 font-weight-bold">
          {{ t('pa.kpis.completion_report') }} — {{ cycle.name }}
        </h1>
        <div class="d-flex align-center gap-2 mt-1">
          <VChip size="small" :color="cycleStatusColor(cycle.status)" label>
            {{ cycle.status }}
          </VChip>
          <span class="text-body-2 text-medium-emphasis">{{ cycle.cycle_type }}</span>
        </div>
      </div>
      <VBtn variant="text" prepend-icon="tabler-arrow-left" href="/performance/kpis">{{ t('pa.common.back') }}</VBtn>
    </div>

    <VRow class="mb-4">
      <VCol cols="12" sm="4">
        <VCard elevation="0" border>
          <VCardText class="text-center py-5">
            <div class="text-h4 font-weight-bold text-primary">{{ totalEmployees }}</div>
            <div class="text-body-2 text-medium-emphasis mt-1">{{ t('pa.kpis.total_employees') }}</div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" sm="4">
        <VCard elevation="0" border>
          <VCardText class="text-center py-5">
            <div class="text-h4 font-weight-bold" :class="`text-${scoreColor(avgScore)}`">
              {{ avgScore }}%
            </div>
            <div class="text-body-2 text-medium-emphasis mt-1">{{ t('pa.kpis.avg_score') }}</div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" sm="4">
        <VCard elevation="0" border>
          <VCardText class="text-center py-5">
            <div class="text-h4 font-weight-bold text-success">{{ fullyScored }}</div>
            <div class="text-body-2 text-medium-emphasis mt-1">{{ t('pa.kpis.fully_scored') }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard elevation="0" border>
      <VDataTable
        :headers="tableHeaders"
        :items="tableItems"
        :sort-by="[{ key: 'total_weighted_score', order: 'desc' }]"
        density="comfortable"
      >
        <template #item.rank="{ item }">
          <div class="d-flex align-center gap-1">
            <VIcon
              v-if="item.rank === 1"
              icon="tabler-trophy"
              color="warning"
              size="18"
            />
            <VIcon
              v-else-if="item.rank === 2 || item.rank === 3"
              icon="tabler-medal"
              :color="item.rank === 2 ? 'blue-grey' : 'brown-lighten-1'"
              size="18"
            />
            <span class="text-body-2">{{ item.rank }}</span>
          </div>
        </template>

        <template #item.employee_name="{ item }">
          <span class="font-weight-medium">{{ item.employee_name }}</span>
        </template>

        <template #item.completion_pct="{ item }">
          <div style="min-width: 120px">
            <div class="d-flex justify-space-between text-caption mb-1">
              <span>{{ item.scored_kpis }} / {{ item.total_kpis }}</span>
              <span class="font-weight-medium">{{ item.completion_pct }}%</span>
            </div>
            <VProgressLinear
              :model-value="item.completion_pct"
              :color="item.completion_pct >= 80 ? 'success' : item.completion_pct >= 60 ? 'warning' : 'error'"
              height="6"
              rounded
            />
          </div>
        </template>

        <template #item.avg_score_pct="{ item }">
          <VChip
            size="small"
            :color="scoreColor(item.avg_score_pct)"
            label
          >
            {{ item.avg_score_pct }}%
          </VChip>
        </template>

        <template #item.total_weighted_score="{ item }">
          <span class="font-weight-bold">{{ item.total_weighted_score }}</span>
        </template>

        <template #no-data>
          <div class="text-center py-8 text-medium-emphasis text-body-2">
            {{ t('pa.kpis.no_report_data') }}
          </div>
        </template>
      </VDataTable>
    </VCard>
  </div>
</template>
