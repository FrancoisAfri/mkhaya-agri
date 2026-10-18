<script setup lang="ts">
/**
 * Supplier performance — what has been appraised.
 *
 * This page used to be four jobs stacked down one screen: every appraisal ever done, a box to start
 * a new one, a box to create a template, and a box to add a question to a template. None of it could
 * be edited afterwards. Designing a scorecard and filling one in are different tasks done by
 * different people at different times, so they are different pages now, and this one only answers
 * the question it is named after.
 */
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

interface Row {
  uuid: string; supplier: string | null; supplier_uuid: string | null
  template: string | null; industry: string | null
  date: string | null; score: number | null; status: string | null
}
interface Option { id: number; uuid?: string; name?: string; company_name?: string; industry_type_name?: string }

const props = defineProps<{
  appraisals: { data: Row[]; links: any[]; total: number; from: number | null; to: number | null }
  filters: Record<string, string | number | null>
  stats: { total: number; last_90: number; average: number | null; below_50: number; templates: number }
  trend: { month: string; average: number; count: number }[]
  templates: Option[]
  industries: Option[]
  suppliers: Option[]
}>()

const filters = { ...props.filters }

function apply(patch: Record<string, unknown>) {
  router.get(route('suppliers.appraisals.index'), { ...filters, ...patch }, {
    preserveState: true, preserveScroll: true, replace: true,
  })
}
function clear() { router.get(route('suppliers.appraisals.index')) }

const hasFilters = computed(() => Object.values(props.filters).some(v => v !== null && v !== ''))

/** A band, not a colour alone: a reader must be able to tell "poor" from "not scored". */
function band(score: number | null) {
  if (score === null) return { colour: 'default', label: t('supplier_management.appraisals.not_scored') }
  if (score >= 80) return { colour: 'success', label: t('supplier_management.appraisals.bands.strong') }
  if (score >= 60) return { colour: 'info', label: t('supplier_management.appraisals.bands.fair') }
  if (score >= 40) return { colour: 'warning', label: t('supplier_management.appraisals.bands.weak') }
  return { colour: 'error', label: t('supplier_management.appraisals.bands.poor') }
}

/*
 * A sparkline rather than a charting library: twelve points with one line and one axis is a shape,
 * and a dependency that renders it is a dependency to keep current for no more information. Months
 * with nothing appraised are absent from the data on purpose, so the line simply does not visit
 * them — a zero there would read as a collapse in performance rather than a quiet quarter.
 */
const spark = computed(() => {
  const points = props.trend
  if (points.length < 2) return null
  const w = 320, h = 56, pad = 4
  const values = points.map(p => p.average)
  const min = Math.min(...values, 0)
  const max = Math.max(...values, 100)
  const span = max - min || 1
  const step = (w - pad * 2) / (points.length - 1)
  const d = points.map((p, i) => {
    const x = pad + i * step
    const y = h - pad - ((p.average - min) / span) * (h - pad * 2)
    return `${i === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`
  }).join(' ')
  return { d, w, h, last: points[points.length - 1], first: points[0] }
})
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between flex-wrap ga-2 mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('supplier_management.appraisals.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('supplier_management.appraisals.subtitle') }}</p>
      </div>
      <VBtn :href="route('suppliers.appraisals.templates')" color="primary" variant="tonal" prepend-icon="tabler-clipboard-list">
        {{ t('supplier_management.appraisals.manage_templates') }}
      </VBtn>
    </div>

    <!-- ── What the numbers are ─────────────────────────────────────────────── -->
    <VRow class="mb-1">
      <VCol cols="6" md="3">
        <VCard elevation="0" border rounded="lg"><VCardText>
          <div class="text-caption text-medium-emphasis">{{ t('supplier_management.appraisals.stats.average') }}</div>
          <!-- Null, not zero: "nothing appraised" and "everybody scored nothing" are the same
               number on a dashboard and opposite facts. -->
          <div class="text-h5">{{ stats.average === null ? '—' : stats.average }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard elevation="0" border rounded="lg"><VCardText>
          <div class="text-caption text-medium-emphasis">{{ t('supplier_management.appraisals.stats.last_90') }}</div>
          <div class="text-h5">{{ stats.last_90 }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard elevation="0" border rounded="lg"><VCardText>
          <div class="text-caption text-medium-emphasis">{{ t('supplier_management.appraisals.stats.below_50') }}</div>
          <div class="text-h5" :class="stats.below_50 > 0 ? 'text-error' : ''">{{ stats.below_50 }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard elevation="0" border rounded="lg"><VCardText>
          <div class="text-caption text-medium-emphasis">{{ t('supplier_management.appraisals.stats.templates') }}</div>
          <div class="text-h5">{{ stats.templates }}</div>
        </VCardText></VCard>
      </VCol>
    </VRow>

    <VCard v-if="spark" elevation="0" border rounded="lg" class="mb-4">
      <VCardText>
        <div class="d-flex align-center justify-space-between flex-wrap ga-2">
          <div>
            <div class="text-caption text-medium-emphasis">{{ t('supplier_management.appraisals.trend_title') }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('supplier_management.appraisals.trend_help') }}</div>
          </div>
          <svg :width="spark.w" :height="spark.h" role="img" :aria-label="t('supplier_management.appraisals.trend_title')">
            <path :d="spark.d" fill="none" stroke="currentColor" stroke-width="2" class="text-primary" opacity="0.85" />
          </svg>
          <div class="text-end">
            <div class="text-caption text-medium-emphasis">{{ spark.first.month }} → {{ spark.last.month }}</div>
            <div class="text-h6">{{ spark.last.average }}</div>
          </div>
        </div>
      </VCardText>
    </VCard>

    <!-- ── Filters ──────────────────────────────────────────────────────────── -->
    <VCard elevation="0" border rounded="lg" class="mb-4">
      <VCardText>
        <VRow dense>
          <VCol cols="12" md="3">
            <VSelect :model-value="filters.supplier_id" :items="suppliers" item-title="company_name" item-value="id"
                     :label="t('supplier_management.appraisals.filter_supplier')" density="compact" variant="outlined"
                     clearable hide-details @update:model-value="v => apply({ supplier_id: v })" />
          </VCol>
          <VCol cols="12" md="3">
            <VSelect :model-value="filters.template_id" :items="templates" item-title="name" item-value="id"
                     :label="t('supplier_management.appraisals.filter_template')" density="compact" variant="outlined"
                     clearable hide-details @update:model-value="v => apply({ template_id: v })" />
          </VCol>
          <VCol cols="6" md="2">
            <VTextField :model-value="filters.from" type="date" :label="t('supplier_management.appraisals.filter_from')"
                        density="compact" variant="outlined" hide-details @update:model-value="v => apply({ from: v })" />
          </VCol>
          <VCol cols="6" md="2">
            <VTextField :model-value="filters.to" type="date" :label="t('supplier_management.appraisals.filter_to')"
                        density="compact" variant="outlined" hide-details @update:model-value="v => apply({ to: v })" />
          </VCol>
          <VCol cols="12" md="2" class="d-flex align-center">
            <VBtn v-if="hasFilters" variant="text" size="small" @click="clear">{{ t('supplier_management.appraisals.clear') }}</VBtn>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- ── The appraisals ───────────────────────────────────────────────────── -->
    <VCard elevation="0" border rounded="lg">
      <VCardText v-if="!appraisals.data.length" class="text-center py-10 text-medium-emphasis">
        {{ hasFilters ? t('supplier_management.appraisals.none_match') : t('supplier_management.appraisals.none_yet') }}
      </VCardText>
      <VTable v-else density="comfortable">
        <thead>
          <tr>
            <th>{{ t('supplier_management.appraisals.supplier') }}</th>
            <th>{{ t('supplier_management.appraisals.template') }}</th>
            <th>{{ t('supplier_management.appraisals.industry') }}</th>
            <th>{{ t('supplier_management.appraisals.date') }}</th>
            <th class="text-right">{{ t('supplier_management.appraisals.score') }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="a in appraisals.data" :key="a.uuid">
            <td class="font-weight-medium">{{ a.supplier ?? '—' }}</td>
            <td class="text-caption">{{ a.template ?? '—' }}</td>
            <td class="text-caption text-medium-emphasis">{{ a.industry ?? '—' }}</td>
            <td class="text-caption text-medium-emphasis">{{ a.date ?? '—' }}</td>
            <td class="text-right">
              <VChip size="small" variant="tonal" :color="band(a.score).colour">
                {{ a.score === null ? '—' : a.score }}
              </VChip>
            </td>
            <td class="text-right">
              <VBtn size="x-small" variant="text" color="primary" :href="route('suppliers.appraisals.show', a.uuid)">
                {{ t('supplier_management.appraisals.open') }}
              </VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>
      <VCardActions v-if="appraisals.data.length" class="justify-space-between">
        <span class="text-caption text-medium-emphasis ms-2">
          {{ t('supplier_management.appraisals.showing', { from: appraisals.from, to: appraisals.to, total: appraisals.total }) }}
        </span>
        <div class="d-flex ga-1">
          <VBtn v-for="(l, i) in appraisals.links" :key="i" size="x-small" variant="text"
                :disabled="!l.url" :active="l.active" @click="l.url && router.get(l.url, {}, { preserveScroll: true })">
            <span v-html="l.label" />
          </VBtn>
        </div>
      </VCardActions>
    </VCard>
  </div>
</template>
