<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t, te } = useI18n()
const route = ziggyRoute

type Kpi = { key: string; value: number | null; money?: boolean; suffix?: string; extra?: number | null; bad_when?: 'positive' }
type Option = { id: number; name: string }

const props = defineProps<{
  dashboard: string
  data: { kpis: Kpi[]; tables: Record<string, Array<Record<string, unknown>>>; notes?: Record<string, string>; currency?: string }
  filters: { from: string; to: string; factory_id: number | null; item_id: number | null; line_id: number | null; status: string | null }
  options: { factories: Option[]; products: Option[]; lines: Option[]; statuses: string[] }
  available: string[]
  currency: string
}>()

const f = ref({ ...props.filters })
const clean = (v: Record<string, unknown>) => Object.fromEntries(Object.entries(v).filter(([, x]) => x !== null && x !== ''))
watch(f, v => router.get(route(`manufacturing.reports.${props.dashboard}`), clean(v), { preserveState: true, replace: true }), { deep: true })
const go = (d: string) => router.get(route(`manufacturing.reports.${d}`), clean(f.value))
const exportUrl = computed(() => route(`manufacturing.reports.${props.dashboard}`, { ...clean(f.value), export: 'csv' }))

const fmt = (k: Kpi) => {
  if (k.value === null || k.value === undefined) return '—'
  const n = Number(k.value).toLocaleString(undefined, { minimumFractionDigits: k.money ? 2 : 0, maximumFractionDigits: k.money ? 2 : 1 })
  return k.money ? `${props.currency} ${n}` : `${n}${k.suffix ?? ''}`
}
const tone = (k: Kpi) => k.bad_when === 'positive' && Number(k.value) > 0 ? 'text-error' : ''
const label = (prefix: string, key: string) => te(`manufacturing.reports.${prefix}.${key}`) ? t(`manufacturing.reports.${prefix}.${key}`) : key
const cell = (v: unknown) => typeof v === 'number' ? v.toLocaleString(undefined, { maximumFractionDigits: 2 }) : (v === null || v === undefined || v === '' ? '—' : String(v))
const tables = computed(() => Object.entries(props.data.tables).filter(([, rows]) => Array.isArray(rows)))
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t(`manufacturing.reports.dashboard.${dashboard}`) }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t(`manufacturing.reports.about.${dashboard}`) }}</p>
      </div>
      <VBtn variant="tonal" prepend-icon="tabler-download" :href="exportUrl">{{ t('manufacturing.reports.export') }}</VBtn>
    </div>

    <VTabs :model-value="dashboard" class="mb-4" @update:model-value="d => go(String(d))">
      <VTab v-for="d in available" :key="d" :value="d">{{ t(`manufacturing.reports.dashboard.${d}`) }}</VTab>
    </VTabs>

    <VCard border class="mb-4">
      <VCardText class="d-flex gap-3 flex-wrap">
        <VTextField v-model="f.from" type="date" hide-details style="max-width: 170px" :label="t('manufacturing.reports.from')" />
        <VTextField v-model="f.to" type="date" hide-details style="max-width: 170px" :label="t('manufacturing.reports.to')" />
        <VSelect v-model="f.factory_id" :items="options.factories" item-title="name" item-value="id" clearable hide-details style="min-width: 200px" :label="t('manufacturing.reports.factory')" />
        <VAutocomplete v-model="f.item_id" :items="options.products" item-title="name" item-value="id" clearable hide-details style="min-width: 220px" :label="t('manufacturing.reports.product')" />
        <VSelect v-model="f.line_id" :items="options.lines" item-title="name" item-value="id" clearable hide-details style="min-width: 180px" :label="t('manufacturing.reports.line')" />
        <VSelect v-model="f.status" :items="options.statuses" clearable hide-details style="min-width: 170px" :label="t('manufacturing.reports.status')" />
      </VCardText>
    </VCard>

    <VRow class="mb-2">
      <VCol v-for="k in data.kpis" :key="k.key" cols="6" md="4" lg="3">
        <VCard border class="h-100">
          <VCardText>
            <div class="text-caption text-medium-emphasis">{{ label('kpi', k.key) }}</div>
            <div class="text-h5" :class="tone(k)">{{ fmt(k) }}</div>
            <div v-if="k.extra !== undefined && k.extra !== null" class="text-caption">{{ k.extra }}%</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol v-if="!data.kpis.length" cols="12"><VAlert type="info" variant="tonal">{{ t('manufacturing.reports.nothing_visible') }}</VAlert></VCol>
    </VRow>

    <div v-if="data.notes" class="mb-3">
      <div v-for="(n, k) in data.notes" :key="k" class="text-caption text-medium-emphasis">{{ n }}</div>
    </div>

    <VRow>
      <VCol v-for="[name, rows] in tables" :key="name" cols="12" lg="6">
        <VCard border class="h-100">
          <VCardItem><VCardTitle>{{ label('table', name) }}</VCardTitle></VCardItem>
          <VTable v-if="rows.length" density="compact">
            <thead><tr><th v-for="c in Object.keys(rows[0])" :key="c">{{ label('col', c) }}</th></tr></thead>
            <tbody><tr v-for="(r, i) in rows" :key="i"><td v-for="c in Object.keys(rows[0])" :key="c">{{ cell(r[c]) }}</td></tr></tbody>
          </VTable>
          <VCardText v-else class="text-medium-emphasis">{{ t('manufacturing.reports.empty') }}</VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
