<script setup lang="ts">
/**
 * One production cycle: its metrics by the formulas of the type version it started on, what was
 * recorded in it, the stages and houses it went through, what stops it being harvested or released,
 * feed and water reconciled against the meter, daily mortality, and its forecasts.
 */
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import VueApexCharts from 'vue3-apexcharts'
import PageErrors from '@/Modules/Agri/Components/PageErrors.vue'
import ReadingChart from '../Command/ReadingChart.vue'
import FormDialog, { type FormField } from '@/Modules/Agri/Components/FormDialog.vue'
import { localDateTime } from '@/Modules/Agri/Components/agri'
import { at, CATEGORY_ICON, human, money, num, statusColor } from './prd'

const props = defineProps<{
  cycle: Record<string, any>
  definition: { stages: Array<{ code: string; name: string }>; custom_fields: Array<{ code: string; name: string }>; measurements: any[]; count_unit: string | null; output_unit: string | null; category: string | null }
  metrics: Array<{ code: string; name: string; value: number | null; unit: string | null; better: string | null; formula: string }>
  records: Array<Record<string, any>>
  stages: Array<{ from: string | null; to: string; at: string; note: string | null }>
  units: Array<{ unit: string; from: string; to: string | null; count: number | null; reason: string | null }>
  blocks: string[]
  restrictions: Array<{ id: string; kind: string; reason: string; starts_on: string; until_on: string | null; active: boolean; lift_note: string | null }>
  reconcile: Array<{ date: string; feed_kg: number; water_recorded_l: number; water_metered_l: number | null; ratio: number | null; flags: string[]; ok: boolean }>
  mortality: Array<{ date: string; deaths: number; pct: number | null }>
  forecasts: Array<Record<string, any>>
  moveTo: Array<{ value: string; title: string }>
  kinds: string[]
  panel: { tiles: Array<{ label: string; value: number | null; unit: string | null; hint?: string | null }>; series: Array<{ name: string; unit: string; points: Array<{ x: string; y: number }> }>; sensors: Array<{ metric: string; title: string }> }
  unitId: string | null
  feedRations: Array<{ value: number; title: string }>
  feedItems: Array<{ value: number; title: string }>
  can: { manage: boolean; record: boolean }
}>()

const c = props.cycle
const open = computed(() => !['closed', 'cancelled'].includes(c.status))
const recording = ref<string | null>(null)
const now = () => localDateTime(new Date())
const fieldsFor = (kind: string): FormField[] => {
  const when: FormField = { key: 'recorded_at', label: 'When', type: 'datetime', default: now(), cols: 6 }
  const common: Record<string, FormField[]> = {
    mortality: [{ key: 'count', label: 'How many died', type: 'number', required: true, cols: 6 }, { key: 'reason', label: 'Cause (if known)', cols: 12 }],
    cull: [{ key: 'count', label: 'How many culled', type: 'number', required: true, cols: 6 }, { key: 'reason', label: 'Why', cols: 12 }],
    feed: [{ key: 'quantity', label: 'Feed (kg)', type: 'number', required: true, cols: 6 },
      { key: 'ration_id', label: 'Ration (drawn from the store)', type: 'select', items: props.feedRations, cols: 6 },
      { key: 'stock_item_id', label: 'Or one feed item (drawn from the store)', type: 'select', items: props.feedItems, cols: 6, show: f => !f.ration_id },
      { key: 'cost', label: 'Cost (only if not drawn from the store)', type: 'number', cols: 6, show: f => !f.ration_id && !f.stock_item_id }],
    water: [{ key: 'quantity', label: 'Water (litres)', type: 'number', required: true, cols: 6 }],
    weight: [{ key: 'quantity', label: 'Average weight (kg)', type: 'number', required: true, cols: 6 }, { key: 'sample_size', label: 'Weighed', type: 'number', cols: 3 },
      { key: 'uniformity_pct', label: 'Uniformity %', type: 'number', cols: 3 }],
    treatment: [{ key: 'product_name', label: 'Product', required: true, cols: 6 }, { key: 'dose', label: 'Dose', cols: 3 },
      { key: 'withdrawal_days', label: 'Withdrawal (days)', type: 'number', cols: 3 }, { key: 'reason', label: 'For', cols: 6 }, { key: 'cost', label: 'Cost', type: 'number', cols: 6 }],
    harvest: [{ key: 'quantity', label: `Harvested (${props.definition.output_unit ?? 'kg'})`, type: 'number', required: true, cols: 6 },
      ...(c.crop_plan ? [] : [{ key: 'count', label: `${props.definition.count_unit ?? 'Head'} taken out`, type: 'number', cols: 6 } as FormField]),
      { key: 'grade', label: 'Grade', cols: 6 }],
    eggs: [{ key: 'quantity', label: 'Eggs collected', type: 'number', required: true, cols: 6 }],
    milk: [{ key: 'quantity', label: 'Milk (litres)', type: 'number', required: true, cols: 6 }],
    cost: [{ key: 'cost', label: 'Amount', type: 'number', required: true, cols: 6 }, { key: 'reason', label: 'For', cols: 6 }],
    observation: [{ key: 'note', label: 'What was seen', type: 'textarea', required: true, cols: 12 }],
  }
  return [when, ...(common[kind] ?? [{ key: 'quantity', label: 'Quantity', type: 'number', cols: 6 }, { key: 'note', label: 'Note', cols: 12 }]),
    { key: 'kind', label: '', default: kind, show: () => false }]
}
const quick = computed(() => props.definition.category === 'crop'
  ? ['harvest', 'treatment', 'cost', 'observation']
  : props.definition.category === 'processing' ? ['cost', 'observation'] : ['mortality', 'feed', 'water', 'weight', 'treatment', 'harvest', 'eggs', 'observation'])
const stageTo = ref('')
const advance = () => router.post(`/manufacturing/agriculture/cycles/${c.id}/stage`, { stage: stageTo.value }, { preserveScroll: true })
const moving = ref(false)
const closing = ref(false)
const lift = ref<string | null>(null)
const forecast = () => router.post(`/manufacturing/agriculture/cycles/${c.id}/forecast`, {}, { preserveScroll: true })
const panelSeries = (sr: { name: string; points: Array<{ x: string; y: number }> }) => [{ name: sr.name, data: sr.points.map(p => ({ x: p.x, y: p.y })) }]
const panelOptions = (unit: string) => ({ chart: { toolbar: { show: false }, animations: { enabled: false } }, dataLabels: { enabled: false }, stroke: { width: 2 },
  xaxis: { type: 'category' }, yaxis: { title: { text: unit }, decimalsInFloat: 1 } })
const mortalitySeries = computed(() => [{ name: 'Deaths', data: props.mortality.map(m => ({ x: m.date, y: m.deaths })) }])
const mortalityOptions = { chart: { toolbar: { show: false } }, dataLabels: { enabled: false }, plotOptions: { bar: { columnWidth: '60%' } }, xaxis: { type: 'category' }, colors: ['#ea5455'] }
</script>

<template>
  <div class="pa-4">
    <PageErrors />
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <div class="d-flex align-center gap-2 mb-1">
          <VChip label :color="statusColor(c.status)">{{ human(c.status) }}</VChip>
          <span class="text-caption text-medium-emphasis"><VIcon :icon="CATEGORY_ICON[c.category] ?? 'tabler-leaf'" size="14" /> {{ c.type }} · version {{ c.type_version }}</span>
        </div>
        <h4 class="text-h4">{{ c.code }} <span class="text-medium-emphasis text-h6">{{ c.name }}</span></h4>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ c.farm }}<template v-if="c.unit"> · {{ c.unit }}</template> · started {{ c.started_on ?? '—' }}<template v-if="c.age_days !== null"> (day {{ c.age_days }})</template>
          <template v-if="c.livestock_group"> · farm group {{ c.livestock_group }}</template><template v-if="c.crop_plan"> · crop plan {{ c.crop_plan }}</template>
        </p>
      </div>
      <div v-if="open" class="d-flex gap-2 flex-wrap">
        <VBtn v-if="can.manage && moveTo.length" variant="tonal" prepend-icon="tabler-arrows-right-left" @click="moving = true">Move</VBtn>
        <VBtn v-if="can.manage" variant="tonal" color="secondary" prepend-icon="tabler-lock" @click="closing = true">Close cycle</VBtn>
      </div>
    </div>

    <VAlert v-if="blocks.length" type="warning" variant="tonal" class="mb-4" icon="tabler-ban" title="Not to be harvested, sold or released">
      <div v-for="(b, k) in blocks" :key="k">{{ b }}</div>
    </VAlert>

    <VRow class="mb-1">
      <VCol v-if="c.current_count !== null" cols="6" md="3"><VCard border><VCardText>
        <div class="text-caption text-medium-emphasis">Now in the cycle</div><div class="text-h5">{{ num(c.current_count, 0) }}</div>
        <div class="text-caption">of {{ num(c.opening_count, 0) }} {{ definition.count_unit }} placed</div>
      </VCardText></VCard></VCol>
      <VCol v-for="m in metrics" :key="m.code" cols="6" md="3">
        <VCard border><VCardText>
          <div class="text-caption text-medium-emphasis">{{ m.name }}</div>
          <div class="text-h5">{{ m.value === null ? '—' : num(m.value, 2) }} <span class="text-body-2">{{ m.value === null ? '' : m.unit }}</span></div>
          <div class="text-caption text-disabled" :title="m.formula">{{ m.value === null ? 'not measured yet' : m.formula }}</div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="6" md="3"><VCard border><VCardText>
        <div class="text-caption text-medium-emphasis">Cost recorded</div><div class="text-h5">{{ money(c.cost_to_date || records.reduce((s, r) => s + (r.cost ?? 0), 0)) }}</div>
        <div v-if="c.output_quantity" class="text-caption">output {{ num(c.output_quantity) }} {{ definition.output_unit }}</div>
      </VCardText></VCard></VCol>
    </VRow>

    <VCard v-if="panel.tiles.length || panel.series.length || panel.sensors.length" border class="mb-4">
      <VCardTitle><VIcon :icon="CATEGORY_ICON[c.category] ?? 'tabler-leaf'" /> {{ human(c.category) }} panel</VCardTitle>
      <VCardText>
        <VRow v-if="panel.tiles.length" class="mb-2">
          <VCol v-for="t in panel.tiles" :key="t.label" cols="6" md="3">
            <div class="text-caption text-medium-emphasis">{{ t.label }}</div>
            <div class="text-h6">{{ t.value === null ? '—' : num(t.value, 2) }} <span class="text-body-2">{{ t.value === null ? '' : t.unit }}</span></div>
            <div v-if="t.hint" class="text-caption text-disabled">{{ t.hint }}</div>
          </VCol>
        </VRow>
        <VRow>
          <VCol v-for="sr in panel.series" :key="sr.name" cols="12" md="6">
            <div class="text-subtitle-2">{{ sr.name }} <span class="text-medium-emphasis">({{ sr.unit }})</span></div>
            <VueApexCharts type="line" height="180" :options="panelOptions(sr.unit)" :series="panelSeries(sr)" />
          </VCol>
          <VCol v-for="sn in panel.sensors" :key="sn.metric" cols="12" md="6">
            <ReadingChart :unit="unitId" :metric="sn.metric" :title="sn.title" :height="180" />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VRow>
      <VCol cols="12" lg="8">
        <VCard v-if="open && can.record" border class="mb-4">
          <VCardText class="d-flex flex-wrap gap-2 align-center">
            <span class="text-subtitle-2 me-2">Record</span>
            <VBtn v-for="k in quick" :key="k" size="small" variant="tonal" @click="recording = k">{{ human(k) }}</VBtn>
            <VSpacer />
            <template v-if="definition.stages.length">
              <VSelect v-model="stageTo" :items="definition.stages.map(s => ({ value: s.code, title: s.name }))" label="Stage" density="compact" hide-details style="max-width: 200px" />
              <VBtn size="small" :disabled="!stageTo || stageTo === c.stage" @click="advance">Set stage</VBtn>
            </template>
          </VCardText>
        </VCard>

        <VCard border class="mb-4">
          <VCardTitle>Records</VCardTitle>
          <VTable density="compact">
            <thead><tr><th>When</th><th>What</th><th class="text-end">Quantity</th><th>Detail</th><th>From</th></tr></thead>
            <tbody>
              <tr v-for="r in records" :key="r.id">
                <td>{{ at(r.recorded_at) }}</td>
                <td>{{ human(r.kind) }}</td>
                <td class="text-end">
                  <template v-if="r.count_delta">{{ num(r.count_delta, 0) }} {{ definition.count_unit }}</template>
                  <template v-if="r.quantity !== null"> {{ num(r.quantity, 3) }} {{ r.unit }}</template>
                  <template v-if="r.cost"> · {{ money(r.cost) }}</template>
                </td>
                <td class="text-caption">{{ [r.reason, r.grade, r.details?.product_name, r.details?.withdrawal_days ? `${r.details.withdrawal_days} d withdrawal` : null,
                  r.details?.sample_size ? `${r.details.sample_size} weighed` : null, r.details?.uniformity_pct ? `${r.details.uniformity_pct}% uniform` : null, r.details?.note, r.details?.reference].filter(Boolean).join(' · ') }}</td>
                <td><VChip size="x-small" label :color="r.source === 'offline' ? 'info' : r.source === 'device' ? 'primary' : undefined">{{ r.source }}</VChip></td>
              </tr>
              <tr v-if="!records.length"><td colspan="5" class="text-center text-medium-emphasis py-4">Nothing recorded yet.</td></tr>
            </tbody>
          </VTable>
        </VCard>

        <VCard v-if="reconcile.length" border class="mb-4">
          <VCardTitle>Feed and water, day by day</VCardTitle>
          <VTable density="compact">
            <thead><tr><th>Day</th><th class="text-end">Feed kg</th><th class="text-end">Water recorded</th><th class="text-end">Water metered</th><th class="text-end">Water : feed</th><th>Check</th></tr></thead>
            <tbody>
              <tr v-for="d in reconcile" :key="d.date">
                <td>{{ d.date }}</td><td class="text-end">{{ num(d.feed_kg, 0) }}</td><td class="text-end">{{ num(d.water_recorded_l, 0) }} l</td>
                <td class="text-end">{{ d.water_metered_l === null ? '—' : `${num(d.water_metered_l, 0)} l` }}</td><td class="text-end">{{ d.ratio ?? '—' }}</td>
                <td><VChip v-if="d.ok" size="x-small" color="success" label>agrees</VChip><span v-else class="text-caption text-warning">{{ d.flags.join(' · ') }}</span></td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>

      <VCol cols="12" lg="4">
        <VCard v-if="mortality.length" border class="mb-4">
          <VCardTitle>Mortality, last 14 days</VCardTitle>
          <VCardText><VueApexCharts type="bar" height="180" :options="mortalityOptions" :series="mortalitySeries" /></VCardText>
        </VCard>
        <VCard border class="mb-4">
          <VCardTitle class="d-flex align-center">Forecasts<VSpacer /><VBtn v-if="open && can.record" size="small" variant="tonal" @click="forecast">Update forecasts</VBtn></VCardTitle>
          <VList density="compact">
            <VListItem v-for="(f, k) in forecasts" :key="k">
              <VListItemTitle v-if="f.metric === 'disease_risk'">Disease risk score: {{ num(f.predicted, 0) }} / 100 <span class="text-caption">(screening, not a diagnosis)</span></VListItemTitle>
              <VListItemTitle v-else>{{ human(f.metric) }} on {{ f.target_on }}: {{ num(f.predicted, 3) }} {{ f.unit }} <span v-if="f.lower !== null" class="text-caption">({{ num(f.lower, 3) }}–{{ num(f.upper, 3) }})</span></VListItemTitle>
              <VListItemSubtitle>Forecast · {{ f.model }}<template v-if="f.actual !== null"> · actual {{ num(f.actual, 3) }}, off by {{ num(f.abs_pct_error, 1) }}%</template></VListItemSubtitle>
            </VListItem>
            <VListItem v-if="!forecasts.length"><VListItemSubtitle>No forecasts yet: three weighings, three days of eggs or milk, or a crop plan are needed.</VListItemSubtitle></VListItem>
          </VList>
        </VCard>
        <VCard border class="mb-4">
          <VCardTitle>Holds &amp; withdrawals</VCardTitle>
          <VList density="compact">
            <VListItem v-for="r in restrictions" :key="r.id">
              <VListItemTitle>{{ human(r.kind) }}: {{ r.reason }}</VListItemTitle>
              <VListItemSubtitle>{{ r.starts_on }} → {{ r.until_on ?? 'until lifted' }}<template v-if="r.lift_note"> · lifted: {{ r.lift_note }}</template></VListItemSubtitle>
              <template #append><VBtn v-if="r.active && can.manage && r.kind !== 'withdrawal'" size="x-small" variant="tonal" @click="lift = r.id">Lift</VBtn>
                <VChip v-else size="x-small" label :color="r.active ? 'warning' : undefined">{{ r.active ? 'active' : 'ended' }}</VChip></template>
            </VListItem>
            <VListItem v-if="!restrictions.length"><VListItemSubtitle>None on the cycle itself{{ c.livestock_group ? '; withdrawals on the farm group show above when they apply' : '' }}.</VListItemSubtitle></VListItem>
          </VList>
        </VCard>
        <VCard border class="mb-4">
          <VCardTitle>Stages</VCardTitle>
          <VTimeline density="compact" side="end" class="pa-3">
            <VTimelineItem v-for="(s, k) in stages" :key="k" size="x-small"><div class="font-weight-medium">{{ human(s.to) }}</div>
              <div class="text-caption text-medium-emphasis">{{ at(s.at) }}<template v-if="s.note"> · {{ s.note }}</template></div></VTimelineItem>
          </VTimeline>
        </VCard>
        <VCard v-if="units.length" border>
          <VCardTitle>Houses and places</VCardTitle>
          <VList density="compact">
            <VListItem v-for="(u, k) in units" :key="k"><VListItemTitle>{{ u.unit }}</VListItemTitle>
              <VListItemSubtitle>{{ at(u.from) }} → {{ u.to ? at(u.to) : 'now' }}<template v-if="u.reason"> · {{ u.reason }}</template></VListItemSubtitle></VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>

    <FormDialog v-if="recording" :model-value="!!recording" :title="`Record ${human(recording)}`" :fields="fieldsFor(recording)"
      :url="`/manufacturing/agriculture/cycles/${c.id}/records`" @update:model-value="v => !v && (recording = null)" />
    <FormDialog v-model="moving" title="Move to another unit" :fields="[{ key: 'unit_id', label: 'Move to', type: 'select', items: moveTo, required: true }, { key: 'reason', label: 'Why' }]"
      :url="`/manufacturing/agriculture/cycles/${c.id}/move`" submit-label="Move" />
    <FormDialog v-model="closing" title="Close the cycle" :fields="[{ key: 'notes', label: 'Closing notes (needed if anything is still in the cycle)', type: 'textarea' }]"
      :url="`/manufacturing/agriculture/cycles/${c.id}/close`" submit-label="Close" intro="The metrics are frozen as they stand, and the unit goes to cleaning." />
    <FormDialog v-if="lift" :model-value="!!lift" title="Lift the hold" :fields="[{ key: 'note', label: 'Why', required: true }]"
      :url="`/manufacturing/agriculture/restrictions/${lift}/lift`" submit-label="Lift" @update:model-value="v => !v && (lift = null)" />
  </div>
</template>
