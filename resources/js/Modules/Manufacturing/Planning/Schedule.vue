<script setup lang="ts">
import { computed, ref } from 'vue'
import axios from 'axios'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Bar = { order_id: string; order: string; operation: string; status: string; centre_id: number | null; start: string; end: string
  done: boolean; alternate: boolean; subcontract: boolean; late: boolean }
type Order = { id: string; number: string; status: string; priority: number; product: string; due_date: string | null; earliest_start: string | null
  start: string; finish: string; previous_finish: string | null; material_ready: string; late: boolean }
type Schedule = { from: string; days: number; orders: Order[]; bars: Bar[]; bottleneck: string | null
  utilisation: Array<{ centre_id: number; code: string | null; name: string | null; percent: number | null; days: Array<{ date: string; capacity: number; used: number; percent: number | null }> }>
  exceptions: Array<{ kind: string; order: string | null; message: string }> }

const props = defineProps<{
  schedule: Schedule
  adherence: { operations: number; on_time: number; percent: number | null; average_slip_days: number }
  last_run: { rule: string; summary: Record<string, any>; created_at: string } | null
  rule: string
  rules: string[]
  days: number
  can: { manage: boolean }
}>()

const view = ref<Schedule>(props.schedule)
const shownDays = computed(() => Math.min(view.value.days, 28))
const dayIndex = (d: string) => Math.round((new Date(d).getTime() - new Date(view.value.from).getTime()) / 86400000)
const dates = computed(() => Array.from({ length: shownDays.value }, (_, i) => {
  const d = new Date(view.value.from); d.setDate(d.getDate() + i); return d.toISOString().slice(0, 10)
}))
const barsFor = (centreId: number | null) => view.value.bars.filter(b => b.centre_id === centreId && !b.done && dayIndex(b.start) < shownDays.value)
const subcontracted = computed(() => view.value.bars.filter(b => b.subcontract && !b.done))
const palette = ['#5c6bc0', '#26a69a', '#ef6c00', '#8e24aa', '#00897b', '#3949ab', '#c0ca33', '#6d4c41', '#d81b60', '#00acc1']
const orderColour = (id: string) => palette[Math.abs([...id].reduce((h, c) => h * 31 + c.charCodeAt(0), 7)) % palette.length]
const barStyle = (b: Bar) => ({
  gridColumn: `${Math.max(0, dayIndex(b.start)) + 1} / ${Math.min(shownDays.value, dayIndex(b.end) + 1) + 1}`,
  background: b.late ? '#e53935' : orderColour(b.order_id),
})

const setRule = (r: string) => router.get(route('manufacturing.planning.schedule'), { rule: r, days: props.days }, { preserveState: true })

// ── What if ─────────────────────────────────────────────────────────────────
const changes = ref<Record<string, { earliest_start?: string | null; priority?: number }>>({})
const preview = ref<Schedule | null>(null)
const busy = ref(false)
function change(o: Order, field: 'earliest_start' | 'priority', value: any) {
  changes.value[o.id] = { ...(changes.value[o.id] ?? {}), [field]: value || null }
}
async function previewImpact() {
  busy.value = true
  try {
    const { data } = await axios.post(route('manufacturing.planning.schedule.preview'), { rule: props.rule, days: props.days, changes: changes.value })
    preview.value = data
    view.value = data
  } finally { busy.value = false }
}
function apply() {
  router.post(route('manufacturing.planning.schedule.apply'), { rule: props.rule, days: props.days, changes: changes.value }, {
    preserveScroll: true, onSuccess: () => { changes.value = {}; preview.value = null },
  })
}
const impact = computed(() => preview.value ? preview.value.orders.map(o => {
  const before = props.schedule.orders.find(x => x.id === o.id)
  return { ...o, before: before?.finish ?? null, wasLate: before?.late ?? false }
}).filter(o => o.before !== o.finish || o.wasLate !== o.late) : [])
const pctColour = (p: number | null) => p === null ? 'grey' : p > 95 ? 'error' : p > 80 ? 'warning' : 'success'
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('manufacturing.plan.schedule_title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('manufacturing.plan.schedule_subtitle') }}</p>
      </div>
      <div class="d-flex gap-2 align-center flex-wrap">
        <VBtnToggle :model-value="rule" mandatory divided variant="outlined" density="comfortable" @update:model-value="setRule">
          <VBtn v-for="r in rules" :key="r" :value="r">{{ t(`manufacturing.plan.rule.${r}`) }}</VBtn>
        </VBtnToggle>
        <VBtn v-if="can.manage" color="primary" @click="apply">{{ t('manufacturing.plan.save_schedule') }}</VBtn>
      </div>
    </div>

    <VRow class="mb-1">
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('manufacturing.plan.orders_scheduled') }}</div><div class="text-h5">{{ view.orders.length }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('manufacturing.plan.late_orders') }}</div><div class="text-h5 text-error">{{ view.orders.filter(o => o.late).length }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('manufacturing.plan.bottleneck') }}</div><div class="text-h5">{{ view.bottleneck ?? '—' }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard border><VCardText><div class="text-caption text-medium-emphasis">{{ t('manufacturing.plan.adherence') }}</div>
        <div class="text-h5">{{ adherence.percent === null ? '—' : `${adherence.percent}%` }}</div>
        <div class="text-caption">{{ t('manufacturing.plan.adherence_detail', { n: adherence.operations, slip: adherence.average_slip_days }) }}</div></VCardText></VCard></VCol>
    </VRow>

    <!-- Gantt: one row per work centre, one column per day -->
    <VCard border class="mb-4">
      <VCardItem><VCardTitle>{{ t('manufacturing.plan.gantt') }}</VCardTitle><VCardSubtitle>{{ t('manufacturing.plan.gantt_hint') }}</VCardSubtitle></VCardItem>
      <div class="overflow-x-auto pa-3">
        <div :style="{ minWidth: `${180 + shownDays * 34}px` }">
          <div class="d-flex">
            <div style="width: 180px; flex-shrink: 0" />
            <div class="gantt-grid flex-grow-1" :style="{ gridTemplateColumns: `repeat(${shownDays}, 1fr)` }">
              <div v-for="d in dates" :key="d" class="text-caption text-center text-medium-emphasis">{{ d.slice(8) }}</div>
            </div>
          </div>
          <div v-for="u in view.utilisation" :key="u.centre_id" class="d-flex align-center border-t py-1">
            <div style="width: 180px; flex-shrink: 0" class="pe-2">
              <div class="font-weight-medium">{{ u.code }}</div>
              <VChip size="x-small" label :color="pctColour(u.percent)">{{ u.percent === null ? '—' : `${u.percent}%` }}</VChip>
            </div>
            <div class="gantt-grid flex-grow-1" :style="{ gridTemplateColumns: `repeat(${shownDays}, 1fr)` }">
              <div v-for="(b, i) in barsFor(u.centre_id)" :key="i" class="gantt-bar" :style="barStyle(b)" :title="`${b.order} · ${b.operation} · ${b.start} → ${b.end}${b.alternate ? ' (alternate)' : ''}`"
                   @click="router.visit(route('manufacturing.production.orders.show', b.order_id))">
                {{ b.order }}<span v-if="b.alternate"> ⇄</span>
              </div>
            </div>
          </div>
          <div v-if="subcontracted.length" class="d-flex align-center border-t py-1">
            <div style="width: 180px; flex-shrink: 0" class="pe-2 font-weight-medium">{{ t('manufacturing.routing.subcontracted') }}</div>
            <div class="gantt-grid flex-grow-1" :style="{ gridTemplateColumns: `repeat(${shownDays}, 1fr)` }">
              <div v-for="(b, i) in subcontracted" :key="i" class="gantt-bar" :style="barStyle(b)" :title="`${b.order} · ${b.operation}`">{{ b.order }}</div>
            </div>
          </div>
        </div>
      </div>
    </VCard>

    <VRow>
      <VCol cols="12" lg="7">
        <VCard border>
          <VCardItem>
            <VCardTitle>{{ t('manufacturing.plan.orders_title') }}</VCardTitle>
            <VCardSubtitle>{{ t('manufacturing.plan.what_if_hint') }}</VCardSubtitle>
            <template v-if="can.manage" #append>
              <VBtn size="small" variant="tonal" :loading="busy" :disabled="!Object.keys(changes).length" @click="previewImpact">{{ t('manufacturing.plan.preview') }}</VBtn>
            </template>
          </VCardItem>
          <VTable density="compact">
            <thead><tr><th>{{ t('manufacturing.orders.number') }}</th><th>{{ t('manufacturing.orders.due') }}</th><th>{{ t('manufacturing.plan.finish') }}</th>
              <th>{{ t('manufacturing.plan.material_ready') }}</th><th>{{ t('manufacturing.plan.not_before') }}</th><th>{{ t('manufacturing.orders.priority') }}</th></tr></thead>
            <tbody>
              <tr v-for="o in view.orders" :key="o.id">
                <td><a :href="route('manufacturing.production.orders.show', o.id)">{{ o.number }}</a><div class="text-caption text-medium-emphasis">{{ o.product }}</div></td>
                <td>{{ o.due_date }}</td>
                <td :class="{ 'text-error font-weight-medium': o.late }">{{ o.finish }}</td>
                <td>{{ o.material_ready }}</td>
                <td><VTextField v-if="can.manage" type="date" density="compact" hide-details :model-value="changes[o.id]?.earliest_start ?? o.earliest_start ?? ''"
                                @update:model-value="(v: string) => change(o, 'earliest_start', v)" style="max-width: 160px" /><span v-else>{{ o.earliest_start ?? '—' }}</span></td>
                <td><VSelect v-if="can.manage" density="compact" hide-details :items="[1, 2, 3, 4, 5]" :model-value="changes[o.id]?.priority ?? o.priority"
                             @update:model-value="(v: number) => change(o, 'priority', v)" style="max-width: 80px" /><span v-else>{{ o.priority }}</span></td>
              </tr>
              <tr v-if="!view.orders.length"><td colspan="6" class="text-center text-medium-emphasis py-6">{{ t('manufacturing.plan.nothing_to_schedule') }}</td></tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
      <VCol cols="12" lg="5">
        <VCard v-if="preview" border class="mb-4">
          <VCardItem><VCardTitle>{{ t('manufacturing.plan.impact') }}</VCardTitle>
            <template #append><VBtn size="small" color="primary" @click="apply">{{ t('manufacturing.plan.apply') }}</VBtn></template></VCardItem>
          <VList density="compact">
            <VListItem v-for="o in impact" :key="o.id" :title="o.number" :subtitle="`${o.before ?? '—'} → ${o.finish}${o.late ? ' · ' + t('manufacturing.plan.late') : ''}`" />
            <VListItem v-if="!impact.length" :subtitle="t('manufacturing.plan.no_impact')" />
          </VList>
        </VCard>
        <VCard border>
          <VCardItem><VCardTitle>{{ t('manufacturing.plan.exceptions') }}</VCardTitle></VCardItem>
          <VList density="compact">
            <VListItem v-for="(e, i) in view.exceptions" :key="i" :title="t(`manufacturing.plan.exception_kind.${e.kind}`)" :subtitle="e.message" />
            <VListItem v-if="!view.exceptions.length" :subtitle="t('manufacturing.plan.no_exceptions')" />
          </VList>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>

<style scoped>
.gantt-grid { display: grid; gap: 2px; min-height: 26px; }
.gantt-bar { color: #fff; font-size: 11px; border-radius: 4px; padding: 2px 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; cursor: pointer; }
</style>
