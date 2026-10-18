<script setup lang="ts">
import { reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Row = { key: string | number; label: string; lines: number; matched: number; accuracy: number | null; variance_value: number; net_value: number }
const props = defineProps<{
  report: { summary: { lines: number; matched: number; accuracy: number | null; gains: number; losses: number }; by_item: Row[]; by_location: Row[]; by_counter: Row[]; by_shift: Row[]; by_cause: Array<{ cause: string; lines: number; value: number }> }
  filters: { from: string; to: string; warehouse: string | null }
  warehouses: Array<{ id: string; title: string }>
}>()

const f = reactive({ ...props.filters })
const apply = () => router.get(route('warehouse.counts.accuracy'), f, { preserveState: true })
const money = (v: number) => v.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const tone = (a: number | null) => a === null ? '' : a >= 98 ? 'text-success' : a >= 90 ? 'text-warning' : 'text-error'
const sections: Array<{ key: 'by_location' | 'by_item' | 'by_counter' | 'by_shift'; label: string }> = [
  { key: 'by_location', label: 'warehouse_management.accuracy.by_location' },
  { key: 'by_item', label: 'warehouse_management.accuracy.by_item' },
  { key: 'by_counter', label: 'warehouse_management.accuracy.by_counter' },
  { key: 'by_shift', label: 'warehouse_management.accuracy.by_shift' },
]
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <div class="text-caption"><a :href="route('warehouse.counts.index')">{{ t('warehouse_management.counts.title') }}</a></div>
      <h4 class="text-h4">{{ t('warehouse_management.accuracy.title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.accuracy.subtitle') }}</p>
    </div>

    <div class="d-flex gap-2 mb-4 flex-wrap align-center">
      <VSelect v-model="f.warehouse" :items="warehouses" item-value="id" clearable density="compact" hide-details style="max-width: 260px" :label="t('warehouse_management.common.warehouse')" />
      <VTextField v-model="f.from" type="date" density="compact" hide-details style="max-width: 170px" :label="t('warehouse_management.accuracy.from')" />
      <VTextField v-model="f.to" type="date" density="compact" hide-details style="max-width: 170px" :label="t('warehouse_management.accuracy.to')" />
      <VBtn color="primary" @click="apply">{{ t('warehouse_management.accuracy.apply') }}</VBtn>
    </div>

    <div class="d-flex gap-3 mb-4 flex-wrap">
      <VCard border class="pa-3" min-width="170"><div class="text-caption">{{ t('warehouse_management.accuracy.first_count') }}</div>
        <div class="text-h4" :class="tone(report.summary.accuracy)">{{ report.summary.accuracy === null ? '—' : `${report.summary.accuracy}%` }}</div>
        <div class="text-caption">{{ t('warehouse_management.accuracy.matched_of', { m: report.summary.matched, n: report.summary.lines }) }}</div></VCard>
      <VCard border class="pa-3" min-width="170"><div class="text-caption">{{ t('warehouse_management.accuracy.losses') }}</div><div class="text-h4 text-error">{{ money(report.summary.losses) }}</div></VCard>
      <VCard border class="pa-3" min-width="170"><div class="text-caption">{{ t('warehouse_management.accuracy.gains') }}</div><div class="text-h4 text-success">{{ money(report.summary.gains) }}</div></VCard>
    </div>

    <VCardText v-if="!report.summary.lines" class="text-center text-medium-emphasis py-10">{{ t('warehouse_management.accuracy.none') }}</VCardText>
    <VRow v-else>
      <VCol v-for="s in sections" :key="s.key" cols="12" md="6">
        <VCard border :title="t(s.label)">
          <div class="overflow-x-auto">
            <VTable density="compact">
              <thead><tr><th /><th class="text-end">{{ t('warehouse_management.counts.lines') }}</th><th class="text-end">{{ t('warehouse_management.accuracy.accuracy') }}</th><th class="text-end">{{ t('warehouse_management.accuracy.net') }}</th></tr></thead>
              <tbody>
                <tr v-for="r in report[s.key]" :key="String(r.key)">
                  <td>{{ r.label }}</td><td class="text-end">{{ r.lines }}</td>
                  <td class="text-end" :class="tone(r.accuracy)">{{ r.accuracy === null ? '—' : `${r.accuracy}%` }}</td>
                  <td class="text-end" :class="r.net_value < 0 ? 'text-error' : ''">{{ money(r.net_value) }}</td>
                </tr>
              </tbody>
            </VTable>
          </div>
        </VCard>
      </VCol>
      <VCol cols="12" md="6">
        <VCard border :title="t('warehouse_management.accuracy.by_cause')">
          <VCardText v-if="!report.by_cause.length" class="text-medium-emphasis">{{ t('warehouse_management.accuracy.no_causes') }}</VCardText>
          <VTable v-else density="compact">
            <tbody>
              <tr v-for="c in report.by_cause" :key="c.cause"><td>{{ t(`warehouse_management.count_cause.${c.cause}`) }}</td><td class="text-end">{{ c.lines }}</td><td class="text-end">{{ money(c.value) }}</td></tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
