<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

// ─── Types ────────────────────────────────────────────────────────────────────
type FunnelConversionItem = {
  from_stage: string
  to_stage: string
  conversion_rate: number
  from_count: number
  to_count: number
}

type DepartmentFillTime = {
  department: string
  avg_days: number
}

type TimeToFill = {
  average_days: number | null
  median_days: number | null
  by_department: DepartmentFillTime[]
}

type SourceEffectivenessItem = {
  source: string
  total: number
  hired: number
  hire_rate: number
}

type MonthlyHireItem = {
  month: string
  count: number
}

// ─── Props ────────────────────────────────────────────────────────────────────
const props = defineProps<{
  funnelConversion: FunnelConversionItem[]
  timeToFill: TimeToFill
  sourceEffectiveness: SourceEffectivenessItem[]
  monthlyHires: MonthlyHireItem[]
}>()

// ─── Time to Fill ─────────────────────────────────────────────────────────────
const deptHeaders = computed(() => [
  { title: t('recruitment_reports.time_to_fill.table.department'), key: 'department', sortable: true },
  { title: t('recruitment_reports.time_to_fill.table.avg_days_to_fill'), key: 'avg_days', sortable: true, width: '180px' },
])

// ─── Source Effectiveness ─────────────────────────────────────────────────────
const sourceHeaders = computed(() => [
  { title: t('recruitment_reports.source_effectiveness.table.source'), key: 'source', sortable: true },
  { title: t('recruitment_reports.source_effectiveness.table.total_applications'), key: 'total', sortable: true, width: '180px' },
  { title: t('recruitment_reports.source_effectiveness.table.hired'), key: 'hired', sortable: true, width: '120px' },
  { title: t('recruitment_reports.source_effectiveness.table.hire_rate'), key: 'hire_rate', sortable: true, width: '200px' },
])

const sortedSources = computed(() =>
  [...props.sourceEffectiveness].sort((a, b) => b.hire_rate - a.hire_rate),
)

// ─── Monthly Hires Bar Chart ───────────────────────────────────────────────────
const monthlyMax = computed(() =>
  Math.max(...props.monthlyHires.map((m) => m.count), 1),
)

function barHeight(count: number): number {
  return Math.round((count / monthlyMax.value) * 80)
}

function formatMonth(month: string): string {
  try {
    const d = new Date(`${month}-01`)
    return d.toLocaleDateString('en-ZA', { month: 'short', year: '2-digit' })
  } catch {
    return month
  }
}

// ─── Funnel Conversion ────────────────────────────────────────────────────────
function conversionColor(rate: number): string {
  if (rate >= 70) return 'success'
  if (rate >= 40) return 'warning'
  return 'error'
}
</script>

<template>
  <div class="pa-6">
    <!-- ── Page Header ────────────────────────────────────────────────────── -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4
          class="font-weight-bold mb-1"
          style="font-size: 1.5rem; font-weight: 700; color: #0f172a;"
        >
          {{ t('recruitment_reports.title') }}
        </h4>
        <p class="text-medium-emphasis mb-0">
          {{ t('recruitment_reports.subtitle') }}
        </p>
      </div>
      <VBtn variant="tonal" color="secondary" prepend-icon="tabler-chart-bar" href="/recruitment/analytics">
        {{ t('recruitment_reports.bi_analytics_button') }}
      </VBtn>
    </div>

    <!-- ── Time to Fill ───────────────────────────────────────────────────── -->
    <VCard elevation="0" border class="mb-6">
      <VCardTitle class="pa-5 pb-3">
        <span class="text-body-1 font-weight-semibold">{{ t('recruitment_reports.time_to_fill.title') }}</span>
      </VCardTitle>

      <VCardText class="pt-0">
        <!-- Summary chips -->
        <div class="d-flex flex-wrap gap-4 mb-5">
          <div class="d-flex align-center gap-2">
            <VIcon icon="tabler-clock" color="primary" size="18" />
            <span class="text-body-2 text-medium-emphasis">{{ t('recruitment_reports.time_to_fill.average') }}</span>
            <VChip
              :color="timeToFill.average_days !== null ? 'primary' : 'default'"
              variant="tonal"
              size="small"
              label
            >
              {{ timeToFill.average_days !== null ? t('recruitment_reports.time_to_fill.days', { count: timeToFill.average_days }) : t('recruitment_reports.time_to_fill.na') }}
            </VChip>
          </div>
          <div class="d-flex align-center gap-2">
            <VIcon icon="tabler-math-equal-greater" color="info" size="18" />
            <span class="text-body-2 text-medium-emphasis">{{ t('recruitment_reports.time_to_fill.median') }}</span>
            <VChip
              :color="timeToFill.median_days !== null ? 'info' : 'default'"
              variant="tonal"
              size="small"
              label
            >
              {{ timeToFill.median_days !== null ? t('recruitment_reports.time_to_fill.days', { count: timeToFill.median_days }) : t('recruitment_reports.time_to_fill.na') }}
            </VChip>
          </div>
        </div>

        <!-- Department breakdown -->
        <VDataTable
          v-if="timeToFill.by_department.length > 0"
          :headers="deptHeaders"
          :items="timeToFill.by_department"
          hide-default-footer
          :items-per-page="15"
        >
          <template #item.avg_days="{ item }">
            <VChip size="small" color="primary" variant="tonal" label>
              {{ t('recruitment_reports.time_to_fill.days', { count: item.avg_days }) }}
            </VChip>
          </template>

          <template #no-data>
            <div class="text-center py-6">
              <p class="text-body-2 text-medium-emphasis mb-0">{{ t('recruitment_reports.time_to_fill.table.no_data') }}</p>
            </div>
          </template>
        </VDataTable>

        <div v-else class="text-center py-6">
          <p class="text-body-2 text-medium-emphasis mb-0">{{ t('recruitment_reports.time_to_fill.table.no_breakdown') }}</p>
        </div>
      </VCardText>
    </VCard>

    <!-- ── Source Effectiveness ───────────────────────────────────────────── -->
    <VCard elevation="0" border class="mb-6">
      <VCardTitle class="pa-5 pb-3">
        <span class="text-body-1 font-weight-semibold">{{ t('recruitment_reports.source_effectiveness.title') }}</span>
      </VCardTitle>

      <VDataTable
        :headers="sourceHeaders"
        :items="sortedSources"
        hide-default-footer
        :items-per-page="20"
      >
        <template #item.source="{ item }">
          <div class="d-flex align-center gap-2">
            <VIcon icon="tabler-git-branch" color="primary" size="16" />
            <span class="text-body-2 font-weight-medium">{{ item.source || t('recruitment_reports.source_effectiveness.table.unknown') }}</span>
          </div>
        </template>

        <template #item.total="{ item }">
          <span class="text-body-2">{{ item.total }}</span>
        </template>

        <template #item.hired="{ item }">
          <span class="text-body-2">{{ item.hired }}</span>
        </template>

        <template #item.hire_rate="{ item }">
          <div class="d-flex align-center gap-3" style="min-width: 180px;">
            <VProgressLinear
              :model-value="item.hire_rate"
              :color="conversionColor(item.hire_rate)"
              height="6"
              rounded
              bg-color="rgba(0,0,0,0.06)"
              style="width: 100px; flex-shrink: 0;"
            />
            <span class="text-body-2 text-medium-emphasis" style="min-width: 44px;">
              {{ item.hire_rate.toFixed(1) }}%
            </span>
          </div>
        </template>

        <template #no-data>
          <div class="text-center py-8">
            <VIcon icon="tabler-git-branch" size="48" color="medium-emphasis" class="mb-2 d-block mx-auto" />
            <p class="text-body-2 text-medium-emphasis mb-0">{{ t('recruitment_reports.source_effectiveness.table.no_data') }}</p>
          </div>
        </template>
      </VDataTable>
    </VCard>

    <!-- ── Monthly Hires Bar Chart ────────────────────────────────────────── -->
    <VCard elevation="0" border class="mb-6">
      <VCardTitle class="pa-5 pb-3">
        <span class="text-body-1 font-weight-semibold">{{ t('recruitment_reports.monthly_hires.title') }}</span>
      </VCardTitle>

      <VCardText class="pt-2 pb-5" style="overflow-x: auto;">
        <div
          v-if="monthlyHires.length > 0"
          class="d-flex align-end gap-3"
          style="min-width: max-content; min-height: 110px;"
        >
          <div
            v-for="item in monthlyHires"
            :key="item.month"
            class="d-flex flex-column align-center"
            style="min-width: 44px;"
          >
            <span
              class="text-caption font-weight-medium mb-1"
              style="color: #0f172a;"
            >
              {{ item.count }}
            </span>
            <div
              :style="{
                width: '28px',
                height: `${barHeight(item.count)}px`,
                minHeight: item.count > 0 ? '4px' : '0px',
                background: 'rgba(115,103,240,1)',
                borderRadius: '4px 4px 0 0',
              }"
            />
            <span
              class="text-caption text-medium-emphasis mt-1"
              style="white-space: nowrap;"
            >
              {{ formatMonth(item.month) }}
            </span>
          </div>
        </div>

        <div v-else class="text-center py-8">
          <VIcon icon="tabler-chart-bar" size="48" color="medium-emphasis" class="mb-2 d-block mx-auto" />
          <p class="text-body-2 text-medium-emphasis mb-0">{{ t('recruitment_reports.monthly_hires.no_data') }}</p>
        </div>
      </VCardText>
    </VCard>

    <!-- ── Funnel Conversion ───────────────────────────────────────────────── -->
    <VCard elevation="0" border>
      <VCardTitle class="pa-5 pb-3">
        <span class="text-body-1 font-weight-semibold">{{ t('recruitment_reports.funnel_conversion.title') }}</span>
      </VCardTitle>

      <VCardText class="pt-0">
        <div v-if="funnelConversion.length > 0">
          <div
            v-for="item in funnelConversion"
            :key="`${item.from_stage}-${item.to_stage}`"
            class="mb-4"
          >
            <div class="d-flex align-center justify-space-between mb-1">
              <span class="text-body-2 font-weight-medium">
                {{ item.from_stage }} &rarr; {{ item.to_stage }}
              </span>
              <VChip
                :color="conversionColor(item.conversion_rate)"
                size="x-small"
                variant="tonal"
                label
              >
                {{ item.conversion_rate.toFixed(1) }}%
              </VChip>
            </div>
            <VProgressLinear
              :model-value="item.conversion_rate"
              :color="conversionColor(item.conversion_rate)"
              height="8"
              rounded
              bg-color="rgba(0,0,0,0.06)"
            />
            <p class="text-caption text-medium-emphasis mt-1 mb-0">
              {{ t('recruitment_reports.funnel_conversion.advanced', { to: item.to_count, from: item.from_count }) }}
            </p>
          </div>
        </div>

        <div v-else class="text-center py-8">
          <VIcon icon="tabler-filter" size="48" color="medium-emphasis" class="mb-2 d-block mx-auto" />
          <p class="text-body-2 text-medium-emphasis mb-0">{{ t('recruitment_reports.funnel_conversion.no_data') }}</p>
        </div>
      </VCardText>
    </VCard>
  </div>
</template>
