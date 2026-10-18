<script setup lang="ts">
import { reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Score = Record<string, any>

const props = defineProps<{
  scores: Score[]
  filters: { from: string; to: string }
  thresholds: { on_time: number; defects: number; min: number }
  flags: Record<string, string>
}>()

const f = reactive({ ...props.filters })
const pct = (v: number | null) => v === null ? '—' : `${Math.round(v * 1000) / 10}%`
const bad = (s: Score, k: string) => (k === 'on_time_rate' && s[k] !== null && s[k] < props.thresholds.on_time)
  || (['damage_rate', 'rejection_rate'].includes(k) && s[k] !== null && s[k] > props.thresholds.defects)
const cols = ['on_time_rate', 'quantity_accuracy', 'damage_rate', 'rejection_rate', 'inspection_pass_rate', 'price_discrepancy_rate'] as const
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.scorecards.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.scorecards.subtitle', { on_time: Math.round(thresholds.on_time * 100), defects: Math.round(thresholds.defects * 100), min: thresholds.min }) }}</p>
      </div>
      <div class="d-flex gap-2">
        <VTextField v-model="f.from" type="date" density="compact" hide-details style="max-width: 170px" :label="t('warehouse_management.movements.from')" />
        <VTextField v-model="f.to" type="date" density="compact" hide-details style="max-width: 170px" :label="t('warehouse_management.movements.to')" />
        <VBtn color="primary" @click="router.get(route('warehouse.inbound.scorecards'), f)">{{ t('warehouse_management.movements.apply') }}</VBtn>
      </div>
    </div>
    <VCard border>
      <VCardText v-if="!scores.length" class="text-center text-medium-emphasis py-10">{{ t('warehouse_management.scorecards.none') }}</VCardText>
      <div v-else style="overflow-x: auto">
        <VTable density="comfortable">
          <thead>
            <tr><th>{{ t('warehouse_management.products.supplier') }}</th><th class="text-end">{{ t('warehouse_management.scorecards.deliveries') }}</th>
              <th v-for="c in cols" :key="c" class="text-end">{{ t(`warehouse_management.scorecards.col.${c}`) }}</th>
              <th class="text-end">{{ t('warehouse_management.scorecards.col.avg_lead_time_days') }}</th><th class="text-end">{{ t('warehouse_management.scorecards.col.avg_resolution_hours') }}</th>
              <th class="text-end">{{ t('warehouse_management.scorecards.col.returns') }}</th></tr>
          </thead>
          <tbody>
            <tr v-for="s in scores" :key="s.supplier_id">
              <td>{{ s.supplier }}<div v-if="flags[s.supplier_id]" class="text-caption text-error">{{ t('warehouse_management.scorecards.flagged') }}: {{ flags[s.supplier_id] }}</div></td>
              <td class="text-end">{{ s.deliveries }}</td>
              <td v-for="c in cols" :key="c" class="text-end" :class="{ 'text-error font-weight-bold': bad(s, c) }">{{ pct(s[c]) }}</td>
              <td class="text-end">{{ s.avg_lead_time_days ?? '—' }}</td>
              <td class="text-end">{{ s.avg_resolution_hours ?? '—' }}</td>
              <td class="text-end">{{ s.returns }}</td>
            </tr>
          </tbody>
        </VTable>
      </div>
    </VCard>
  </div>
</template>
