<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PageErrors from './Components/PageErrors.vue'
import FarmFilter from './Components/FarmFilter.vue'
import { human, num } from './Components/agri'

/** Phase 2 §19: outcomes, and the change against the farm's baseline. */
const props = defineProps<{
  farm: null | { id: string; name: string }
  farms: Array<{ id: string; name: string }>
  from: string; to: string
  measures: Record<string, any>
  comparison: null | { baseline: any; latest: any; rows: any[] }
  snapshots: any[]
  can: { snapshot: boolean }
}>()
const p = ref({ from: props.from, to: props.to })
const apply = () => router.get(route('agri.outcomes.index'), { farm: props.farm?.id, ...p.value }, { preserveState: false })
const record = (baseline: boolean) => { const note = prompt(baseline ? 'Note for the baseline (e.g. "before Mkhaya Agri")' : 'Note for this period') ?? undefined
  router.post(route('agri.outcomes.store'), { farm_id: props.farm?.id, ...p.value, baseline, note }, { preserveScroll: true }) }
const byArea = computed(() => Object.entries(props.measures).reduce((acc: Record<string, any[]>, [k, v]: any) => { (acc[v.area] ??= []).push({ key: k, ...v }); return acc }, {}))
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div><h4 class="text-h4 mb-1">Outcomes</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">Measured from the farm's records. Record a baseline before a change; later periods are set against it.</p></div>
      <div class="d-flex flex-wrap gap-2 align-center">
        <VTextField v-model="p.from" type="date" label="From" density="compact" hide-details style="max-inline-size: 160px" />
        <VTextField v-model="p.to" type="date" label="To" density="compact" hide-details style="max-inline-size: 160px" />
        <VBtn variant="tonal" @click="apply">Apply</VBtn>
        <template v-if="can.snapshot"><VBtn variant="tonal" @click="record(true)">Record as baseline</VBtn><VBtn color="primary" @click="record(false)">Record period</VBtn></template>
        <FarmFilter :farms="farms" :model-value="farm?.id" :allow-all="false" />
      </div>
    </div>

    <VCard v-if="comparison?.rows?.length" :title="`Against the baseline (${comparison.baseline.from} – ${comparison.baseline.to})`" :subtitle="`Latest recorded: ${comparison.latest.from} – ${comparison.latest.to}`" class="mb-5">
      <VTable density="compact"><thead><tr><th>Measure</th><th class="text-end">Baseline</th><th class="text-end">Latest</th><th class="text-end">Change</th></tr></thead>
        <tbody><tr v-for="r in comparison.rows" :key="r.key"><td>{{ r.label }}<div class="text-caption">{{ human(r.area) }} · {{ r.how }}</div></td>
          <td class="text-end">{{ r.baseline === null ? '—' : num(r.baseline) }}</td><td class="text-end">{{ r.value === null ? '—' : num(r.value) }} {{ r.unit }}</td>
          <td class="text-end">{{ r.change === null ? '—' : (r.change > 0 ? '+' : '') + num(r.change) }}<div v-if="r.change_pct !== null" class="text-caption">{{ r.change_pct }}%</div></td></tr></tbody></VTable>
    </VCard>

    <VRow>
      <VCol v-for="(rows, area) in byArea" :key="area" cols="12" md="6" lg="4"><VCard :title="human(String(area))">
        <VList density="compact" lines="two"><VListItem v-for="r in rows" :key="r.key" :title="r.label" :subtitle="r.how">
          <template #append><span class="text-body-1 font-weight-medium">{{ r.value === null ? 'No data' : `${num(r.value)} ${r.unit}` }}</span></template></VListItem></VList>
      </VCard></VCol>
    </VRow>

    <VCard v-if="snapshots.length" title="Recorded periods" class="mt-5"><VTable density="compact"><tbody>
      <tr v-for="s in snapshots" :key="s.uuid"><td>{{ String(s.period_from).slice(0, 10) }} – {{ String(s.period_to).slice(0, 10) }}</td><td>{{ s.note }}</td>
        <td><VChip v-if="s.is_baseline" size="x-small" color="primary">Baseline</VChip></td></tr></tbody></VTable></VCard>
  </div>
</template>
