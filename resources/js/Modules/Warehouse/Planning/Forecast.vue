<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute

type Period = { id: number; start: string; qty: number | null; lower: number | null; upper: number | null; override: number | null; reason: string | null }
type Row = { item: string; name: string; uom: string; method: string; basis: Record<string, any> | null; periods: Period[] }
const props = defineProps<{
  run: { id: string; bucket: string; horizon: number; history: number; items: number; insufficient: number; at: string } | null
  rows: Row[]
  accuracy: { overall: { periods: number; wape: number | null; bias: number | null }; items: Array<{ item: string; periods: number; forecast: number; actual: number; wape: number | null; bias: number | null }> }
  warehouses: Array<{ id: number; title: string }>
  can: { plan: boolean }
}>()

const runForm = useForm({ bucket: props.run?.bucket ?? 'MONTH', horizon: props.run?.horizon ?? 6, history: props.run?.history ?? 24, warehouse_id: null as number | null })
const editing = ref<{ p: Period; item: string } | null>(null)
const ov = useForm({ qty: 0, reason: '' })
const expanded = ref<string | null>(null)
const n = (v: number | null) => v === null ? '—' : Number(v.toFixed(1)).toString()
const label = (s: string) => props.run?.bucket === 'WEEK' ? s : new Date(s).toLocaleDateString(undefined, { month: 'short', year: '2-digit' })
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.forecast.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.forecast.subtitle') }}</p>
      </div>
      <div v-if="can.plan" class="d-flex gap-2 align-center flex-wrap">
        <VSelect v-model="runForm.bucket" :items="[{ value: 'MONTH', title: t('warehouse_management.forecast.monthly') }, { value: 'WEEK', title: t('warehouse_management.forecast.weekly') }]" density="compact" hide-details style="width: 140px" />
        <VTextField v-model.number="runForm.horizon" type="number" density="compact" hide-details style="width: 110px" :label="t('warehouse_management.forecast.horizon')" />
        <VTextField v-model.number="runForm.history" type="number" density="compact" hide-details style="width: 110px" :label="t('warehouse_management.forecast.history')" />
        <VSelect v-model="runForm.warehouse_id" :items="warehouses" item-value="id" clearable density="compact" hide-details style="width: 150px" :label="t('warehouse_management.common.warehouse')" />
        <VBtn color="primary" :loading="runForm.processing" @click="runForm.post(route('warehouse.planning.forecast.run'), { preserveScroll: true })">{{ t('warehouse_management.forecast.run') }}</VBtn>
      </div>
    </div>
    <RefusalAlert />
    <div class="d-flex gap-3 mb-4 flex-wrap">
      <VCard border class="pa-3" min-width="170"><div class="text-caption">{{ t('warehouse_management.forecast.last_run') }}</div><div class="text-h6">{{ run ? new Date(run.at).toLocaleString() : '—' }}</div></VCard>
      <VCard border class="pa-3" min-width="170"><div class="text-caption">{{ t('warehouse_management.forecast.items') }}</div><div class="text-h6">{{ run?.items ?? 0 }}</div></VCard>
      <VCard border class="pa-3" min-width="170"><div class="text-caption">{{ t('warehouse_management.forecast.insufficient') }}</div><div class="text-h6 text-warning">{{ run?.insufficient ?? 0 }}</div></VCard>
      <VCard border class="pa-3" min-width="170"><div class="text-caption">{{ t('warehouse_management.forecast.wape') }}</div><div class="text-h6">{{ accuracy.overall.wape === null ? '—' : `${accuracy.overall.wape}%` }}</div>
        <div class="text-caption">{{ accuracy.overall.bias === null ? t('warehouse_management.forecast.not_yet') : t('warehouse_management.forecast.bias', { b: accuracy.overall.bias }) }}</div></VCard>
    </div>

    <VCard border>
      <VCardText v-if="!rows.length" class="text-center text-medium-emphasis py-10">{{ t('warehouse_management.forecast.none') }}</VCardText>
      <div v-else class="overflow-x-auto">
        <VTable density="compact">
          <thead><tr><th>{{ t('warehouse_management.counts.item') }}</th><th>{{ t('warehouse_management.forecast.method') }}</th>
            <th v-for="p in rows[0].periods" :key="p.start" class="text-end">{{ label(p.start) }}</th></tr></thead>
          <tbody>
            <template v-for="r in rows" :key="r.item">
              <tr style="cursor: pointer" @click="expanded = expanded === r.item ? null : r.item">
                <td class="font-weight-medium">{{ r.item }} <span class="text-caption text-medium-emphasis">{{ r.name }}</span></td>
                <td><VChip size="x-small" :color="r.method === 'INSUFFICIENT' ? 'warning' : undefined">{{ t(`warehouse_management.forecast_method.${r.method}`) }}</VChip></td>
                <td v-for="p in r.periods" :key="p.id" class="text-end" @click.stop="can.plan && r.method !== 'INSUFFICIENT' && (editing = { p, item: r.item }, ov.qty = p.override ?? p.qty ?? 0, ov.reason = '')">
                  <template v-if="p.override !== null"><strong>{{ n(p.override) }}</strong><VIcon icon="tabler-pencil" size="10" :title="p.reason ?? ''" /></template>
                  <template v-else>{{ n(p.qty) }}</template>
                  <div v-if="p.lower !== null && p.upper !== null" class="text-caption text-medium-emphasis">{{ n(p.lower) }}–{{ n(p.upper) }}</div>
                </td>
              </tr>
              <tr v-if="expanded === r.item"><td :colspan="2 + r.periods.length" class="text-caption bg-surface-variant">
                <div v-if="r.method === 'INSUFFICIENT'">{{ r.basis?.reason }}</div>
                <template v-else>
                  <div>{{ t('warehouse_management.forecast.basis', { pattern: r.basis?.pattern, periods: r.basis?.periods, demand: r.basis?.demand_periods }) }}</div>
                  <div v-for="o in r.basis?.one_offs ?? []" :key="o.period">{{ t('warehouse_management.forecast.one_off', { period: o.period, was: o.was, used: o.used }) }}</div>
                </template>
              </td></tr>
            </template>
          </tbody>
        </VTable>
      </div>
    </VCard>

    <VCard v-if="accuracy.items.length" border class="mt-4" :title="t('warehouse_management.forecast.accuracy')">
      <VTable density="compact"><tbody>
        <tr v-for="a in accuracy.items.slice(0, 20)" :key="a.item"><td>{{ a.item }}</td><td class="text-end">{{ a.forecast }} / {{ a.actual }}</td>
          <td class="text-end">{{ a.wape === null ? '—' : `${a.wape}%` }}</td><td class="text-end text-caption">{{ a.bias === null ? '' : t('warehouse_management.forecast.bias', { b: a.bias }) }}</td></tr>
      </tbody></VTable>
    </VCard>

    <VDialog :model-value="editing !== null" max-width="420" @update:model-value="v => !v && (editing = null)">
      <VCard v-if="editing" :title="t('warehouse_management.forecast.override_title', { item: editing.item, period: label(editing.p.start) })">
        <VCardText class="d-flex flex-column gap-3">
          <p class="text-body-2 mb-0">{{ t('warehouse_management.forecast.was', { qty: n(editing.p.qty) }) }}</p>
          <VTextField v-model.number="ov.qty" type="number" min="0" :label="t('warehouse_management.quality.qty')" />
          <VTextField v-model="ov.reason" :label="t('warehouse_management.common.reason')" :hint="t('warehouse_management.forecast.reason_hint')" persistent-hint :error-messages="ov.errors.reason" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="editing = null">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="primary" @click="ov.post(route('warehouse.planning.forecast.override', editing.p.id), { preserveScroll: true, onSuccess: () => editing = null })">{{ t('warehouse_management.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
