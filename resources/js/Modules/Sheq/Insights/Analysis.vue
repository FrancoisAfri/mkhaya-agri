<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import NoCompanyNotice from '@/Modules/Sheq/Partials/NoCompanyNotice.vue'

const { t } = useI18n()

type Rate = { value: number | null; label: string; numerator: number; denominator: number }

type Period = {
  period: string; label: string
  rates: Record<string, Rate> | null
  counts: Record<string, number> | null
  leading: Record<string, any> | null
  hours: number | null
  computed_at: string | null
}

const props = defineProps<{
  periods: Period[]
  metrics: string[]
  sites: { id: number; name: string }[]
  siteId: number | null
  hasAnySnapshot?: boolean
  noCompany?: boolean
}>()

const selectedMetric = ref(props.metrics[0] ?? null)
const site = ref<number | null>(props.siteId)

const series = computed(() =>
  props.periods.map(p => ({
    label: p.label,
    // Null is carried through, never coerced. A month nobody computed is a gap in the line, not
    // a zero — drawing 0.0 LTIFR for it would be the safest possible lie to tell an executive.
    value: selectedMetric.value ? (p.rates?.[selectedMetric.value]?.value ?? null) : null,
  })))

const known = computed(() => series.value.filter(p => p.value !== null).map(p => p.value as number))
const peak = computed(() => known.value.length ? Math.max(...known.value, 0.0001) : 1)
const missing = computed(() => series.value.filter(p => p.value === null).length)

const applySite = () => {
  router.get('/sheq/insights/analysis', site.value ? { site_id: site.value } : {},
    { preserveState: true, preserveScroll: true })
}
</script>

<template>
  <div class="pa-4">
    <div class="mb-5">
      <h1 class="text-h5 font-weight-bold">{{ t('sheq.insights.analysis.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('sheq.insights.analysis.subtitle') }}</p>
    </div>

    <NoCompanyNotice :show="!!noCompany" />

    <template v-if="!noCompany">
      <VAlert v-if="!hasAnySnapshot" type="info" variant="tonal" class="mb-4">
        {{ t('sheq.insights.analysis.no_snapshots') }}
      </VAlert>

      <VRow class="mb-2">
        <VCol cols="12" sm="6" md="4">
          <VSelect
            v-model="selectedMetric" :items="metrics" :label="t('sheq.insights.analysis.metric')"
            density="compact" variant="outlined" hide-details
          />
        </VCol>
        <VCol cols="12" sm="6" md="4">
          <VSelect
            v-model="site" :items="sites" item-title="name" item-value="id" clearable
            :label="t('sheq.insights.analysis.site')" density="compact" variant="outlined"
            hide-details @update:model-value="applySite"
          />
        </VCol>
      </VRow>

      <VCard elevation="0" border rounded="lg" class="mb-4">
        <VCardTitle class="text-body-1 font-weight-semibold">
          {{ selectedMetric ?? t('sheq.insights.analysis.metric') }}
          <span class="text-caption text-medium-emphasis ms-2">
            {{ t('sheq.insights.analysis.twelve_months') }}
          </span>
        </VCardTitle>
        <VCardText>
          <!-- A bar per month. Bars, not a line: a line implies a value between two points, and
               half these months may have no snapshot at all. -->
          <div class="d-flex align-end justify-space-between gap-1" style="block-size: 180px">
            <div
              v-for="point in series" :key="point.label"
              class="d-flex flex-column align-center flex-grow-1"
              style="min-inline-size: 0"
            >
              <div class="text-caption mb-1 text-truncate" style="max-inline-size: 100%">
                {{ point.value === null ? '—' : point.value }}
              </div>
              <div
                :class="['w-100 rounded-t', point.value === null ? 'bg-grey-lighten-2' : 'bg-primary']"
                :style="{ blockSize: point.value === null ? '4px' : `${Math.max(4, (point.value / peak) * 130)}px` }"
                :aria-label="`${point.label}: ${point.value ?? t('sheq.insights.analysis.not_computed')}`"
                role="img"
              />
              <div class="text-caption text-medium-emphasis mt-1 text-truncate" style="max-inline-size: 100%">
                {{ point.label }}
              </div>
            </div>
          </div>

          <p v-if="missing" class="text-caption text-medium-emphasis mt-3 mb-0">
            {{ t('sheq.insights.analysis.gaps', { count: missing }) }}
          </p>
        </VCardText>
      </VCard>

      <!-- The table alternative WCAG asks for alongside a chart (SHEQ-A11Y-1). -->
      <VCard elevation="0" border rounded="lg">
        <VCardTitle class="text-body-1 font-weight-semibold">
          {{ t('sheq.insights.analysis.table') }}
        </VCardTitle>
        <VTable density="comfortable">
          <thead>
            <tr>
              <th scope="col">{{ t('sheq.insights.analysis.period') }}</th>
              <th v-for="m in metrics" :key="m" scope="col">{{ m }}</th>
              <th scope="col">{{ t('sheq.dashboard.hours_worked') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in periods" :key="p.period">
              <td class="text-body-2">{{ p.label }}</td>
              <td v-for="m in metrics" :key="m" class="text-body-2">
                {{ p.rates?.[m]?.value ?? '—' }}
              </td>
              <td class="text-body-2 text-medium-emphasis">{{ p.hours ?? '—' }}</td>
            </tr>
          </tbody>
        </VTable>
      </VCard>
    </template>
  </div>
</template>
