<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Kpi = { key: string; name: string; unit: string; higher_is_better: boolean; definition: string; exclusions: string
  now: { value: number | null; numerator: number | null; denominator: number | null; note: string | null }
  last: number | null; baseline: number | null; history: Array<{ period: string; value: number | null }> }
const props = defineProps<{ kpis: Kpi[]; baseline: string | null; last_period: string; month: string; version: number; periods: string[]; can: { manage: boolean } }>()

const open = ref<string | null>(null)
const pick = ref<string>(props.baseline ?? props.periods[0] ?? '')
const fmt = (v: number | null) => (v === null ? '—' : new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(v))
// Change against the baseline, coloured by whether it is better for this KPI.
const change = (k: Kpi) => {
  if (k.now.value === null || k.baseline === null) return null
  const d = k.now.value - k.baseline
  return { d, good: d === 0 ? null : (d > 0) === k.higher_is_better }
}
const spark = (h: Kpi['history']) => {
  const vals = h.map(p => p.value).filter((v): v is number => v !== null)
  if (vals.length < 2) return ''
  const min = Math.min(...vals); const max = Math.max(...vals); const span = max - min || 1
  return h.filter(p => p.value !== null).map((p, i, a) => `${(i / (a.length - 1)) * 96 + 2},${22 - ((p.value as number - min) / span) * 20}`).join(' ')
}
const act = (action: string, period: string) => router.post(route('warehouse.kpis.act'), { action, period }, { preserveScroll: true })
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.kpis.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.kpis.subtitle', { v: version }) }}</p>
      </div>
      <VSpacer />
      <template v-if="can.manage">
        <VBtn variant="tonal" size="small" @click="act('snapshot', last_period)">{{ t('warehouse_management.kpis.record', { p: last_period }) }}</VBtn>
        <VSelect v-model="pick" :items="periods" density="compact" hide-details style="max-width: 140px" :label="t('warehouse_management.kpis.baseline')" />
        <VBtn size="small" color="primary" :disabled="!pick" @click="act('baseline', pick)">{{ t('warehouse_management.kpis.set_baseline') }}</VBtn>
      </template>
    </div>
    <VAlert v-if="!baseline" type="info" variant="tonal" density="compact" class="mb-4">{{ t('warehouse_management.kpis.no_baseline') }}</VAlert>

    <VCard border>
      <div style="overflow-x: auto">
        <VTable density="comfortable">
          <thead><tr>
            <th>{{ t('warehouse_management.kpis.kpi') }}</th><th class="text-end">{{ t('warehouse_management.kpis.this_month', { m: month }) }}</th>
            <th class="text-end">{{ t('warehouse_management.kpis.last_month') }}</th><th class="text-end">{{ t('warehouse_management.kpis.baseline_col', { p: baseline ?? '—' }) }}</th>
            <th class="text-end">{{ t('warehouse_management.kpis.change') }}</th><th>{{ t('warehouse_management.kpis.trend') }}</th>
          </tr></thead>
          <tbody>
            <template v-for="k in kpis" :key="k.key">
              <tr style="cursor: pointer" tabindex="0" @click="open = open === k.key ? null : k.key" @keydown.enter="open = open === k.key ? null : k.key">
                <td><div class="font-weight-medium">{{ k.name }}</div><div class="text-caption text-medium-emphasis">{{ k.unit }}</div></td>
                <td class="text-end" style="font-variant-numeric: tabular-nums">{{ fmt(k.now.value) }}<div v-if="k.now.value === null && k.now.note" class="text-caption text-medium-emphasis">{{ k.now.note }}</div></td>
                <td class="text-end" style="font-variant-numeric: tabular-nums">{{ fmt(k.last) }}</td>
                <td class="text-end" style="font-variant-numeric: tabular-nums">{{ fmt(k.baseline) }}</td>
                <td class="text-end"><span v-if="change(k)" :class="change(k)!.good === null ? '' : change(k)!.good ? 'text-success' : 'text-error'">{{ change(k)!.d > 0 ? '+' : '' }}{{ fmt(change(k)!.d) }}</span><span v-else>—</span></td>
                <td><svg v-if="spark(k.history)" width="100" height="24" role="img" :aria-label="t('warehouse_management.kpis.trend_of', { k: k.name })"><polyline :points="spark(k.history)" fill="none" stroke="currentColor" stroke-width="1.5" /></svg></td>
              </tr>
              <tr v-if="open === k.key"><td colspan="6" class="text-body-2 py-3">
                <div><strong>{{ t('warehouse_management.kpis.definition') }}</strong> {{ k.definition }}</div>
                <div><strong>{{ t('warehouse_management.kpis.excludes') }}</strong> {{ k.exclusions }}</div>
                <div v-if="k.now.denominator !== null" class="text-caption mt-1">{{ t('warehouse_management.kpis.figures', { n: fmt(k.now.numerator), d: fmt(k.now.denominator) }) }}</div>
              </td></tr>
            </template>
          </tbody>
        </VTable>
      </div>
    </VCard>
  </div>
</template>
