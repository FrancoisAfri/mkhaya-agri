<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import axios from 'axios'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute
type Sim = { id: string; kind: string; inputs: Record<string, any>; results: Record<string, any>; trials: number; seed: string; by: string | null; at: string }
const props = defineProps<{ simulations: Sim[]; standards: Record<string, any>; warehouse: string | null; warehouses: Array<{ id: string; title: string }>; can: { supervise: boolean } }>()

const kind = ref<'STAFFING' | 'REPLENISHMENT'>('STAFFING')
const activities = ['RECEIVE', 'PUTAWAY', 'REPLENISH', 'PICK', 'PACK', 'COUNT', 'DISPATCH']
const form = useForm({ kind: 'STAFFING', warehouse: props.warehouse, staff: Object.fromEntries(activities.map(a => [a, 1])) as Record<string, number>, demand_pct: 0,
  item: null as string | null, min: 10, max: 50, lead_days: 7, trials: 500, seed: null as number | null })
const items = ref<Array<{ id: string; title: string }>>([])
async function searchItems(q: string) { items.value = (await axios.get(route('warehouse.products.search'), { params: { q } })).data }
function run() { form.kind = kind.value; form.post(route('warehouse.work.simulate'), { preserveScroll: true }) }
const rerun = (s: Sim) => { Object.assign(form, { ...form.data(), ...s.inputs, kind: s.kind === 'DEMAND' ? 'STAFFING' : s.kind, seed: Number(s.seed), warehouse: props.warehouse }); kind.value = form.kind as any }
const range = (r: { p10: number; p50: number; p90: number } | undefined) => r ? `${r.p50} (${r.p10}–${r.p90})` : '—'
</script>

<template>
  <div class="pa-4">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('warehouse_management.work.sim_title') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.work.sim_subtitle') }}</p>
    </div>
    <RefusalAlert />
    <VCard v-if="can.supervise" border class="mb-4">
      <VCardText class="d-flex flex-column gap-3">
        <div class="d-flex gap-2 flex-wrap align-center">
          <VBtnToggle v-model="kind" mandatory density="compact" variant="outlined">
            <VBtn value="STAFFING">{{ t('warehouse_management.sim_kind.STAFFING') }}</VBtn><VBtn value="REPLENISHMENT">{{ t('warehouse_management.sim_kind.REPLENISHMENT') }}</VBtn>
          </VBtnToggle>
          <VSelect v-model="form.warehouse" :items="warehouses" item-value="id" density="compact" hide-details style="min-width: 220px" />
          <VTextField v-model.number="form.trials" type="number" density="compact" hide-details style="width: 110px" :label="t('warehouse_management.work.trials')" />
          <VTextField v-model.number="form.seed" type="number" density="compact" hide-details clearable style="width: 160px" :label="t('warehouse_management.work.seed')" />
        </div>
        <div v-if="kind === 'STAFFING'" class="d-flex gap-2 flex-wrap">
          <VTextField v-for="a in activities" :key="a" v-model.number="form.staff[a]" type="number" min="0" density="compact" hide-details style="width: 120px" :label="t(`warehouse_management.task_type.${a}`)" />
          <VTextField v-model.number="form.demand_pct" type="number" density="compact" hide-details style="width: 150px" :label="t('warehouse_management.work.demand_change')" suffix="%" />
        </div>
        <div v-else class="d-flex gap-2 flex-wrap">
          <VAutocomplete v-model="form.item" :items="items" item-value="id" density="compact" hide-details style="min-width: 260px" :label="t('warehouse_management.counts.item')" @update:search="searchItems" />
          <VTextField v-model.number="form.min" type="number" density="compact" hide-details style="width: 100px" label="Min" />
          <VTextField v-model.number="form.max" type="number" density="compact" hide-details style="width: 100px" label="Max" />
          <VTextField v-model.number="form.lead_days" type="number" density="compact" hide-details style="width: 120px" :label="t('warehouse_management.buying.lead')" />
        </div>
        <VBtn color="primary" class="align-self-start" :loading="form.processing" @click="run">{{ t('warehouse_management.work.run_sim') }}</VBtn>
      </VCardText>
    </VCard>
    <VCardText v-if="!simulations.length" class="text-center text-medium-emphasis">{{ t('warehouse_management.work.no_sims') }}</VCardText>
    <VCard v-for="s in simulations" :key="s.id" border class="mb-3">
      <VCardItem>
        <VCardTitle>{{ t(`warehouse_management.sim_kind.${s.kind}`) }} <span class="text-caption">· {{ s.trials }} {{ t('warehouse_management.work.trials') }} · seed {{ s.seed }} · {{ s.by }} · {{ new Date(s.at).toLocaleString() }}</span></VCardTitle>
        <template v-if="can.supervise && s.kind !== 'LAYOUT'" #append><VBtn size="small" variant="text" @click="rerun(s)">{{ t('warehouse_management.work.reuse') }}</VBtn></template>
      </VCardItem>
      <VCardText>
        <VTable v-if="['STAFFING', 'DEMAND'].includes(s.kind)" density="compact">
          <thead><tr><th /><th class="text-end">{{ t('warehouse_management.work.rostered') }}</th><th class="text-end">{{ t('warehouse_management.work.work_hours') }}</th>
            <th class="text-end">{{ t('warehouse_management.work.left_over') }}</th><th class="text-end">{{ t('warehouse_management.work.for_95') }}</th><th /></tr></thead>
          <tbody><template v-for="(r, a) in s.results" :key="a"><tr v-if="a !== 'assumptions'">
            <td>{{ t(`warehouse_management.task_type.${a}`) }}</td><td class="text-end">{{ r.people }}</td><td class="text-end">{{ range(r.work_hours) }}</td>
            <td class="text-end" :class="r.chance_work_left_over > 10 ? 'text-error' : ''">{{ r.chance_work_left_over }}%</td><td class="text-end">{{ r.people_for_95pct }}</td><td class="text-caption">{{ r.basis }}</td></tr></template></tbody>
        </VTable>
        <div v-else-if="s.kind === 'REPLENISHMENT'" class="text-body-2">
          {{ t('warehouse_management.work.repl_result', { item: s.inputs.item, chance: s.results.chance_of_any_stockout, days: range(s.results.stockout_days), stock: range(s.results.average_stock), orders: range(s.results.orders_placed) }) }}
        </div>
        <div v-else class="text-body-2">{{ t('warehouse_management.work.sim_result', { now: s.results.metres_per_order_now?.p50, then: s.results.metres_per_order_proposed?.p50, pct: s.results.saving_pct }) }}</div>
        <div v-for="a in s.results.assumptions ?? []" :key="a" class="text-caption mt-1">· {{ a }}</div>
      </VCardText>
    </VCard>
  </div>
</template>
