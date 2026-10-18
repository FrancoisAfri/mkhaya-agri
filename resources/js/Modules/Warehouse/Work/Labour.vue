<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import RefusalAlert from '../components/RefusalAlert.vue'

const { t } = useI18n()
const route = ziggyRoute

type Act = { activity: string; open_tasks: number; open_hours: number; expected_new: number; hours: number; people_needed: number; people_rostered: number; gap: number;
  standard: { minutes_per_task: number; minutes_per_unit: number; source: string; sample_size: number } }
const props = defineProps<{
  plan: { activities: Act[]; shift_hours: number; utilisation: number; assumptions: string[] } | null
  picking: { waves: Array<{ strategy: string; orders: Array<{ id: string; number: string }>; why: string; stops: number; travel: number; sequence: Array<{ bin: string; item: string; qty: number; order: string; heavy: boolean; hazardous: boolean }> }>; travel_now: number; travel_advised: number; constraints: string[]; saving_pct?: number | null } | null
  warehouse: string | null; warehouses: Array<{ id: string; title: string }>; can: { supervise: boolean; pick: boolean }
}>()
const std = ref<Act | null>(null)
const form = useForm({ warehouse: props.warehouse, action: 'set', activity: '', minutes_per_task: 0, minutes_per_unit: 0 })
const release = (w: { strategy: string; orders: Array<{ id: string }> }) => router.post(route('warehouse.outbound.waves.store'), { orders: w.orders.map(o => o.id), strategy: w.strategy }, { preserveScroll: true })
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('warehouse_management.work.labour_title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('warehouse_management.work.labour_subtitle') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VSelect :model-value="warehouse" :items="warehouses" item-value="id" density="compact" hide-details style="min-width: 220px" @update:model-value="v => router.get(route('warehouse.work.labour'), { warehouse: v })" />
        <VBtn v-if="can.supervise" variant="tonal" @click="router.post(route('warehouse.work.standards'), { warehouse, action: 'measure' }, { preserveScroll: true })">{{ t('warehouse_management.work.remeasure') }}</VBtn>
      </div>
    </div>
    <RefusalAlert />
    <VCard v-if="plan" border class="mb-4" :title="t('warehouse_management.work.workload')">
      <div class="overflow-x-auto">
        <VTable density="comfortable">
          <thead><tr><th>{{ t('warehouse_management.work.activity') }}</th><th class="text-end">{{ t('warehouse_management.work.open') }}</th><th class="text-end">{{ t('warehouse_management.work.new_per_day') }}</th>
            <th class="text-end">{{ t('warehouse_management.work.hours') }}</th><th class="text-end">{{ t('warehouse_management.work.needed') }}</th><th class="text-end">{{ t('warehouse_management.work.rostered') }}</th><th>{{ t('warehouse_management.work.standard') }}</th></tr></thead>
          <tbody>
            <tr v-for="a in plan.activities" :key="a.activity">
              <td>{{ t(`warehouse_management.task_type.${a.activity}`) }}</td><td class="text-end">{{ a.open_tasks }}</td><td class="text-end">{{ a.expected_new }}</td>
              <td class="text-end">{{ a.hours }}</td><td class="text-end">{{ a.people_needed }}</td>
              <td class="text-end" :class="a.gap < 0 ? 'text-error font-weight-bold' : ''">{{ a.people_rostered }}</td>
              <td class="text-caption">{{ t('warehouse_management.work.per_task', { m: a.standard.minutes_per_task }) }} · {{ t(`warehouse_management.standard_source.${a.standard.source}`, { n: a.standard.sample_size }) }}
                <VBtn v-if="can.supervise" size="x-small" variant="text" @click="std = a; Object.assign(form, { warehouse, action: 'set', activity: a.activity, minutes_per_task: a.standard.minutes_per_task, minutes_per_unit: a.standard.minutes_per_unit })">{{ t('warehouse_management.common.edit') }}</VBtn></td>
            </tr>
          </tbody>
        </VTable>
      </div>
      <VCardText><div v-for="x in plan.assumptions" :key="x" class="text-caption">· {{ x }}</div></VCardText>
    </VCard>

    <VCard v-if="picking" border :title="t('warehouse_management.work.pick_advice')">
      <VCardText v-if="!picking.waves.length" class="text-medium-emphasis">{{ t('warehouse_management.work.nothing_to_pick') }}</VCardText>
      <template v-else>
        <VCardText class="text-body-2">{{ t('warehouse_management.work.travel', { now: picking.travel_now, advised: picking.travel_advised, pct: picking.saving_pct ?? 0 }) }}
          <div v-for="c in picking.constraints" :key="c" class="text-caption">· {{ c }}</div></VCardText>
        <VList lines="two">
          <VListItem v-for="(w, i) in picking.waves" :key="i">
            <VListItemTitle>{{ t(`warehouse_management.strategy.${w.strategy}`) }} · {{ w.orders.map(o => o.number).join(', ') }}</VListItemTitle>
            <VListItemSubtitle>{{ w.why }} {{ t('warehouse_management.work.stops', { n: w.stops }) }}
              <span v-for="s in w.sequence.filter(x => x.hazardous)" :key="s.bin + s.item" class="text-warning"> · {{ t('warehouse_management.work.hazard_tote', { item: s.item }) }}</span></VListItemSubtitle>
            <template v-if="can.pick" #append><VBtn size="small" color="primary" variant="tonal" @click="release(w)">{{ t('warehouse_management.work.release') }}</VBtn></template>
          </VListItem>
        </VList>
      </template>
    </VCard>
    <VDialog :model-value="std !== null" max-width="420" @update:model-value="v => !v && (std = null)">
      <VCard v-if="std" :title="t(`warehouse_management.task_type.${std.activity}`)">
        <VCardText class="d-flex flex-column gap-3">
          <VTextField v-model.number="form.minutes_per_task" type="number" min="0" :label="t('warehouse_management.work.minutes_task')" />
          <VTextField v-model.number="form.minutes_per_unit" type="number" min="0" :label="t('warehouse_management.work.minutes_unit')" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="std = null">{{ t('warehouse_management.common.cancel') }}</VBtn>
          <VBtn color="primary" @click="form.post(route('warehouse.work.standards'), { preserveScroll: true, onSuccess: () => std = null })">{{ t('warehouse_management.common.save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
