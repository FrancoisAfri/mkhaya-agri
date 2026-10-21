<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PageErrors from '../Components/PageErrors.vue'
import FormDialog from '../Components/FormDialog.vue'
import RowMenu from '../Components/RowMenu.vue'
import { human, money, num, statusColor, today } from '../Components/agri'
import { activityFields, completeFields, harvestFields, inputFields, observationFields, planFields, type CropOptions } from './fields'

const props = defineProps<{
  plan: any; activities: any[]; harvests: any[]; observations: any[]
  yields: { harvested: number; unit: string; hectares: number; per_ha: number | null; expected_per_ha: number | null; expected_total: number | null; variance_pct: number | null }
  finance: { spent: number; income: number; budget: number | null; by_category: Record<string, number> }
  withholding_until: string | null
  precision: any
  options: CropOptions
  can: { plan: boolean; approve: boolean; record: boolean; harvest: boolean }
}>()

const dialog = ref<null | string>(null)
const target = ref<any>(null)
const keep = { preserveScroll: true }
const open = (k: string, row: any = null) => { target.value = row; dialog.value = k }
const close = (v: boolean) => { if (!v) dialog.value = null }

const workable = computed(() => ['approved', 'planted', 'harvesting'].includes(props.plan.status))
const budgetUsed = computed(() => props.finance.budget ? Math.min(100, (props.finance.spent / props.finance.budget) * 100) : null)

function transition(action: string) {
  const words: Record<string, string> = {
    approve: 'Approve this plan so work can start on it?', complete: 'Complete this plan? No more harvests can be recorded afterwards.',
    reopen: 'Send this plan back to draft?',
  }
  if (action === 'plant') { open('plant'); return }
  if (action === 'cancel') {
    const reason = prompt('Why is this plan being cancelled?')
    if (reason) router.post(route('agri.crops.plans.transition', props.plan.id), { action, reason }, keep)
    return
  }
  if (confirm(words[action] ?? 'Continue?')) router.post(route('agri.crops.plans.transition', props.plan.id), { action }, keep)
}
const activityAction = (a: any, action: string) => router.post(route('agri.crops.activities.status', a.id), { action }, keep)
const removeInput = (a: any, i: any) => confirm('Remove this input?') && router.delete(route('agri.crops.inputs.destroy', [a.id, i.id]), keep)
// ── §3: stages, inputs, water, harvest outlook, margins ─────────────────────────────────────
const p = computed(() => props.precision)
const harvestOptions = computed(() => ({ ...props.options, farm_id: props.plan.farm_id, commitments: (p.value.harvest.commitments ?? []).filter((c: any) => ['open', 'short'].includes(c.status))
  .map((c: any) => ({ title: `${c.buyer} · ${c.quantity - c.delivered} ${c.unit} by ${c.deliver_on}`, value: c.id })) }))
const LINE: Record<string, { label: string; color?: string }> = { covered: { label: 'In store', color: 'success' }, on_order: { label: 'On order', color: 'info' },
  arrives_late: { label: 'Order late', color: 'warning' }, to_order: { label: 'To order', color: 'warning' }, too_late: { label: 'Order now', color: 'error' }, not_stocked: { label: 'Farm rate' }, unknown: { label: '—' } }
function correct(i: any) {
  const q = prompt(`How much ${i.item} was actually used (${i.uom})? Recorded: ${i.quantity}`)
  if (q === null || q === '') return
  const reason = prompt('Why is it being corrected?')
  if (reason) router.post(route('agri.crops.inputs.correct', i.id), { quantity: Number(q), reason }, keep)
}
const forecast = () => router.post(route('agri.crops.forecasts.store', props.plan.id), { method: 'model' }, keep)
const editValues = computed(() => ({ ...props.plan, field_id: props.plan.field_id }))
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div>
        <div class="text-body-2 mb-1"><Link :href="route('agri.crops.index')">Crops</Link> / {{ plan.farm }}</div>
        <h4 class="text-h4 mb-1">{{ plan.code }} · {{ plan.crop }}<span v-if="plan.variety" class="text-medium-emphasis"> ({{ plan.variety }})</span></h4>
        <p class="text-body-1 text-medium-emphasis mb-0">Field {{ plan.field }} · {{ plan.field_name }} · {{ plan.season }}</p>
      </div>
      <div class="d-flex align-center gap-2">
        <VChip :color="statusColor(plan.status)" variant="tonal">{{ human(plan.status) }}</VChip>
        <VBtn v-if="can.approve && plan.transitions.includes('approve')" color="success" prepend-icon="tabler-check" @click="transition('approve')">Approve</VBtn>
        <VBtn v-if="can.plan && plan.transitions.includes('plant')" color="primary" prepend-icon="tabler-seeding" @click="transition('plant')">Record planting</VBtn>
        <VBtn v-if="can.harvest && ['planted', 'harvesting'].includes(plan.status)" color="warning" prepend-icon="tabler-wheat" @click="open('harvest')">Record harvest</VBtn>
        <RowMenu size="default" :actions="[
          { title: 'Edit plan', icon: 'tabler-edit', show: can.plan && ['draft', 'approved'].includes(plan.status), onClick: () => open('edit') },
          { title: 'Plan an activity', icon: 'tabler-shovel', show: can.record && workable, onClick: () => open('activity') },
          { title: 'Scouting observation', icon: 'tabler-bug', show: can.record, onClick: () => open('observation') },
          { title: 'Complete plan', icon: 'tabler-flag', show: can.plan && plan.transitions.includes('complete'), onClick: () => transition('complete') },
          { title: 'Back to draft', icon: 'tabler-arrow-back-up', show: can.plan && plan.transitions.includes('reopen'), onClick: () => transition('reopen') },
          { title: 'Cancel plan', icon: 'tabler-x', color: 'error', show: can.plan && plan.transitions.includes('cancel'), onClick: () => transition('cancel') },
        ]" />
      </div>
    </div>

    <VAlert v-if="withholding_until && new Date(withholding_until) > new Date()" type="warning" variant="tonal" class="mb-4" icon="tabler-alert-triangle">
      Chemical withholding period: this crop may not be harvested before <strong>{{ withholding_until }}</strong>.
    </VAlert>
    <VAlert v-if="plan.status === 'draft'" type="info" variant="tonal" class="mb-4">Draft — waiting for approval. Somebody other than the person who drafted it approves it.</VAlert>
    <VAlert v-if="plan.cancel_reason" type="error" variant="tonal" class="mb-4">Cancelled: {{ plan.cancel_reason }}</VAlert>

    <VRow class="mb-2">
      <VCol cols="6" md="3"><VCard><VCardText>
        <div class="text-caption">Area</div><div class="text-h5">{{ num(plan.hectares) }} ha</div>
        <div class="text-caption">of {{ plan.field_hectares ? num(plan.field_hectares) + ' ha field' : 'field (area not set)' }}</div>
      </VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard><VCardText>
        <div class="text-caption">Dates</div>
        <div class="text-body-1">Plant {{ plan.planted_on ?? plan.planned_planting_on }}<span v-if="!plan.planted_on" class="text-caption"> (planned)</span></div>
        <div class="text-body-1">Harvest {{ plan.harvest_completed_on ?? plan.planned_harvest_on }}</div>
      </VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard><VCardText>
        <div class="text-caption">Yield</div>
        <div class="text-h5">{{ yields.per_ha !== null ? num(yields.per_ha) : '—' }} <span class="text-body-2">{{ yields.unit }}/ha</span></div>
        <div class="text-caption">Plan {{ yields.expected_per_ha ?? '—' }} {{ yields.unit }}/ha
          <span v-if="yields.variance_pct !== null" :class="yields.variance_pct < 0 ? 'text-error' : 'text-success'"> · {{ yields.variance_pct > 0 ? '+' : '' }}{{ yields.variance_pct }}%</span></div>
      </VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard><VCardText>
        <div class="text-caption">Spent (ledger)</div><div class="text-h5">{{ money(finance.spent) }}</div>
        <div class="text-caption">Budget {{ money(finance.budget) }}</div>
        <VProgressLinear v-if="budgetUsed !== null" :model-value="budgetUsed" :color="finance.spent > (finance.budget ?? 0) ? 'error' : 'success'" height="6" rounded class="mt-1" />
      </VCardText></VCard></VCol>
    </VRow>

    <VCard title="Field activities" class="mb-4">
      <template #append><VBtn v-if="can.record && workable" size="small" variant="tonal" prepend-icon="tabler-plus" @click="open('activity')">Activity</VBtn></template>
      <VTable density="comfortable">
        <thead><tr><th>Date</th><th>Activity</th><th>Inputs</th><th class="text-end">Labour / machine</th><th>Status</th><th /></tr></thead>
        <tbody>
          <tr v-for="a in activities" :key="a.id">
            <td>{{ a.completed_at ?? a.planned_on }}</td>
            <td><strong>{{ a.title }}</strong><div class="text-caption">{{ human(a.activity_type) }}<template v-if="a.machine"> · {{ a.machine }}</template></div></td>
            <td>
              <div v-for="i in a.inputs" :key="i.id" class="text-body-2 d-flex align-center gap-1">
                <VIcon :icon="i.status === 'issued' ? 'tabler-circle-check' : 'tabler-clock'" size="14" :color="i.status === 'issued' ? 'success' : undefined" />
                {{ num(i.quantity) }} {{ i.uom }} {{ i.item }}<span v-if="i.total_cost" class="text-caption">· {{ money(i.total_cost) }}</span>
                <span v-if="i.withholding_days" class="text-caption text-warning">· WHP {{ i.withholding_days }}d</span>
                <span v-if="i.reentry_hours" class="text-caption text-warning">· REI {{ i.reentry_hours }}h</span>
                <span v-if="i.status === 'reversed'" class="text-caption text-medium-emphasis text-decoration-line-through">reversed</span>
                <span v-if="i.corrects" class="text-caption">· corrected: {{ i.correction_reason }}</span>
                <VBtn v-if="i.status === 'issued' && can.record" size="x-small" variant="text" @click="correct(i)">Correct</VBtn>
                <VBtn v-if="i.status === 'planned' && can.record" icon size="x-small" variant="text" @click="removeInput(a, i)"><VIcon icon="tabler-x" size="14" /></VBtn>
              </div>
              <span v-if="!a.inputs.length" class="text-disabled">—</span>
            </td>
            <td class="text-end">{{ a.labour_hours ?? '—' }} h<template v-if="a.machine_hours"> · {{ a.machine_hours }} mh</template></td>
            <td><VChip :color="statusColor(a.status)" size="small" variant="tonal">{{ human(a.status) }}</VChip></td>
            <td class="text-end"><RowMenu :actions="[
              { title: 'Add input from store', icon: 'tabler-package', show: can.record && ['planned', 'in_progress'].includes(a.status), onClick: () => open('input', a) },
              { title: 'Start', icon: 'tabler-player-play', show: can.record && a.status === 'planned', onClick: () => activityAction(a, 'start') },
              { title: 'Complete', icon: 'tabler-check', show: can.record && ['planned', 'in_progress'].includes(a.status), onClick: () => open('complete', a) },
              { title: 'Cancel', icon: 'tabler-x', color: 'error', show: can.record && ['planned', 'in_progress'].includes(a.status), onClick: () => activityAction(a, 'cancel') },
            ]" /></td>
          </tr>
          <tr v-if="!activities.length"><td colspan="6" class="text-center text-medium-emphasis py-6">No activities yet.</td></tr>
        </tbody>
      </VTable>
    </VCard>

    <VCard class="mb-4">
      <VCardItem><VCardTitle>Crop intelligence</VCardTitle>
        <template #append><Link :href="route('agri.crops.precision', 'scouting')" class="text-body-2">All crops</Link></template></VCardItem>
      <VCardText>
        <VRow>
          <VCol cols="12" md="4">
            <div class="text-subtitle-2 mb-1">Growth stage <VBtn v-if="can.record && ['planted', 'harvesting'].includes(plan.status)" size="x-small" variant="text" @click="open('stage')">Record</VBtn></div>
            <div class="text-h6">{{ plan.current_stage ? human(plan.current_stage) : '—' }}</div>
            <div v-for="st in p.stages.slice(0, 4)" :key="st.on + st.stage" class="text-caption">{{ st.on }} · {{ human(st.stage) }}</div>
            <div class="text-subtitle-2 mt-3 mb-1">Rotation on {{ plan.field }}</div>
            <VAlert v-for="w in p.rotation?.warnings ?? []" :key="w" type="warning" variant="tonal" density="compact" class="mb-1">{{ w }}</VAlert>
            <div v-for="h in (p.rotation?.history ?? []).filter((x: any) => x.code !== plan.code).slice(0, 4)" :key="h.code" class="text-caption">{{ h.planted }} · {{ h.crop }} {{ h.variety ?? '' }} ({{ h.code }})</div>
            <div v-if="!(p.rotation?.history ?? []).filter((x: any) => x.code !== plan.code).length" class="text-caption text-medium-emphasis">No earlier crops recorded.</div>
            <div class="text-subtitle-2 mt-3 mb-1">Soil tests <VBtn v-if="can.record" size="x-small" variant="text" @click="open('soil')">Record</VBtn></div>
            <div v-for="t in p.soil_tests" :key="t.id" class="text-caption">{{ t.on }}<template v-if="t.lab"> · {{ t.lab }}</template> · pH {{ t.ph ?? '—' }} · P {{ t.p ?? '—' }} · K {{ t.k ?? '—' }}
              <a v-if="t.report" :href="route('agri.soil-tests.report', t.id)" target="_blank">report</a></div>
            <div v-if="!p.soil_tests.length" class="text-caption text-medium-emphasis">None yet.</div>
          </VCol>
          <VCol cols="12" md="8">
            <div class="text-subtitle-2 mb-1">Inputs needed · {{ money(p.inputs.totals.planned_cost) }}<span v-if="p.inputs.totals.budget !== null" :class="p.inputs.totals.over_budget ? 'text-error' : ''"> of {{ money(p.inputs.totals.budget) }} budget</span></div>
            <VTable density="compact" class="mb-3">
              <tbody>
                <tr v-for="(l, i) in p.inputs.lines" :key="i"><td>{{ l.description }}<div class="text-caption">{{ human(l.category) }}<template v-if="l.need_by"> · by {{ l.need_by }}</template></div></td>
                  <td class="text-end">{{ l.quantity !== null ? num(l.quantity, 1) + ' ' + l.unit : '—' }}</td>
                  <td><VChip size="x-small" :color="LINE[l.status]?.color">{{ LINE[l.status]?.label }}</VChip><span v-if="l.short" class="text-caption"> {{ num(l.short, 1) }} short</span><span v-if="l.order_by" class="text-caption"> · order by {{ l.order_by }}</span></td>
                  <td class="text-end">{{ l.cost !== null ? money(l.cost) : '' }}</td></tr>
                <tr v-if="!p.inputs.lines.length"><td class="text-medium-emphasis">No approved input rates for this crop yet — add them under Crop intelligence → Input planning.</td></tr>
              </tbody>
            </VTable>
            <VRow dense>
              <VCol cols="6" md="4"><div class="text-caption">Water given</div><div class="text-h6">{{ num(p.water.m3, 0) }} m³</div>
                <div class="text-caption">{{ p.water.mm !== null ? p.water.mm + ' mm · ' : '' }}{{ money(p.water.water_cost + p.water.energy_cost) }}<template v-if="p.water.per_m3 !== null"> · {{ p.water.per_m3 }} {{ plan.yield_unit }}/m³</template></div></VCol>
              <VCol cols="6" md="4"><div class="text-caption">Harvest estimate <VBtn v-if="can.plan || can.harvest" size="x-small" variant="text" @click="forecast">Forecast</VBtn></div>
                <template v-if="p.harvest.forecast"><div class="text-h6">{{ num(p.harvest.forecast.quantity, 1) }} {{ plan.yield_unit }}</div><div class="text-caption">estimate · ready {{ p.harvest.forecast.ready_on ?? '—' }}</div></template>
                <div v-else class="text-body-2 text-medium-emphasis">None yet</div></VCol>
              <VCol cols="6" md="4"><div class="text-caption">Measured</div><div class="text-h6">{{ num(p.harvest.measured.harvested, 1) }} {{ plan.yield_unit }}</div>
                <div class="text-caption">{{ num(p.harvest.measured.saleable, 1) }} saleable<template v-if="p.harvest.committed"> · {{ num(p.harvest.committed, 1) }} committed</template></div></VCol>
            </VRow>
            <VAlert v-if="p.profit" :type="p.profit.net_margin < 0 ? 'warning' : 'success'" variant="tonal" density="compact" class="mt-3">
              Net margin {{ money(p.profit.net_margin) }}<template v-if="p.profit.per_ha"> ({{ money(p.profit.per_ha.net_margin) }}/ha)</template> · contribution {{ money(p.profit.contribution_margin) }}
              · costs {{ money(p.profit.total_costs) }}<template v-if="p.profit.per_kg !== null"> · {{ money(p.profit.per_kg) }}/kg</template>
              · <Link :href="route('agri.crops.precision', 'profitability')">compare</Link>
            </VAlert>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VRow>
      <VCol cols="12" md="6">
        <VCard title="Harvests">
          <VTable density="compact">
            <thead><tr><th>Date</th><th class="text-end">Quantity</th><th>Grade</th><th>To</th></tr></thead>
            <tbody>
              <tr v-for="h in harvests" :key="h.id">
                <td>{{ h.harvested_on }}<VIcon v-if="h.override_reason" icon="tabler-alert-triangle" color="warning" size="16" class="ms-1" :title="'Withholding override: ' + h.override_reason" /></td>
                <td class="text-end">{{ num(h.quantity, 3) }} {{ h.unit }}</td><td>{{ h.grade ?? '—' }}<span v-if="h.moisture_pct" class="text-caption"> · {{ h.moisture_pct }}%</span></td>
                <td>{{ human(h.destination) }}</td>
              </tr>
              <tr v-if="harvests.length"><td><strong>Total</strong></td><td class="text-end"><strong>{{ num(yields.harvested, 3) }} {{ yields.unit }}</strong></td><td colspan="2" class="text-caption">Plan {{ yields.expected_total ?? '—' }} {{ yields.unit }}</td></tr>
              <tr v-else><td colspan="4" class="text-center text-medium-emphasis py-4">Nothing harvested yet.</td></tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
      <VCol cols="12" md="6">
        <VCard title="Scouting">
          <VList v-if="observations.length" lines="two" density="compact">
            <VListItem v-for="o in observations" :key="o.id" :title="`${o.observed_on} · ${human(o.kind)}`" :subtitle="o.description">
              <template #append><VChip :color="statusColor(o.severity)" size="x-small" variant="tonal">{{ o.severity }}</VChip></template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-medium-emphasis">No scouting reports on this crop.</VCardText>
        </VCard>
        <VCard v-if="Object.keys(finance.by_category).length" title="Costs by category" class="mt-4">
          <VList density="compact"><VListItem v-for="(v, k) in finance.by_category" :key="k" :title="human(String(k))"><template #append>{{ money(v) }}</template></VListItem></VList>
        </VCard>
      </VCol>
    </VRow>

    <FormDialog :model-value="dialog === 'edit'" title="Edit crop plan" :fields="planFields(options, false)" :values="{ ...editValues, farm_id: plan.farm_id }"
      :url="route('agri.crops.plans.update', plan.id)" method="patch" :intro="plan.status === 'approved' ? 'Changing an approved plan sends it back for approval.' : undefined" @update:model-value="close" />
    <FormDialog :model-value="dialog === 'plant'" title="Record planting" :fields="[{ key: 'date', label: 'Planted on', type: 'date', required: true, cols: 12 }]"
      :values="{ action: 'plant', date: today() }" :url="route('agri.crops.plans.transition', plan.id)" submit-label="Record planting" max-width="420" @update:model-value="close" />
    <FormDialog :model-value="dialog === 'stage'" title="Growth stage reached" :url="route('agri.crops.stages.store', plan.id)" :values="{ observed_on: today() }" :fields="[
      { key: 'stage', label: 'Stage', type: 'select', required: true, items: p.stage_options.map((x: string) => ({ title: human(x), value: x })) },
      { key: 'observed_on', label: 'Seen on', type: 'date', required: true }, { key: 'notes', label: 'Notes', cols: 12 },
    ]" @update:model-value="close" />
    <FormDialog v-if="plan.field_id" :model-value="dialog === 'soil'" :title="`Soil test: ${plan.field}`" :url="route('agri.fields.soil-tests.store', plan.field_id)" :values="{ sampled_on: today() }" :fields="[
      { key: 'sampled_on', label: 'Sampled on', type: 'date', required: true }, { key: 'laboratory', label: 'Laboratory' }, { key: 'sample_ref', label: 'Sample ref', cols: 4 },
      { key: 'depth_cm', label: 'Depth (cm)', type: 'number', cols: 4 }, { key: 'ph', label: 'pH', type: 'number', cols: 4 },
      { key: 'organic_carbon_pct', label: 'Organic C (%)', type: 'number', cols: 4 }, { key: 'p_mg_kg', label: 'P (mg/kg)', type: 'number', cols: 4 }, { key: 'k_mg_kg', label: 'K (mg/kg)', type: 'number', cols: 4 },
      { key: 'ca_mg_kg', label: 'Ca (mg/kg)', type: 'number', cols: 4 }, { key: 'mg_mg_kg', label: 'Mg (mg/kg)', type: 'number', cols: 4 }, { key: 'ec_ms_m', label: 'EC (mS/m)', type: 'number', cols: 4 },
      { key: 'latitude', label: 'Sample latitude', type: 'number' }, { key: 'longitude', label: 'Sample longitude', type: 'number' },
      { key: 'recommendation', label: 'Recommendation', type: 'textarea', cols: 12 }, { key: 'report', label: 'Laboratory report', type: 'file', cols: 12 },
    ]" @update:model-value="close" />
    <FormDialog :model-value="dialog === 'harvest'" title="Record harvest" :fields="harvestFields(harvestOptions, plan.yield_unit)" :values="{ harvested_on: today() }"
      :url="route('agri.crops.harvests.store', plan.id)" @update:model-value="close" />
    <FormDialog :model-value="dialog === 'activity'" title="Plan an activity" :fields="activityFields(options, { farm_id: plan.farm_id, crop_plan_id: plan.id })"
      :values="{ farm_id: plan.farm_id, crop_plan_id: plan.id, planned_on: today() }" :url="route('agri.crops.activities.store')" @update:model-value="close" />
    <FormDialog :model-value="dialog === 'observation'" title="Scouting observation" :fields="observationFields(options, { farm_id: plan.farm_id, crop_plan_id: plan.id })"
      :values="{ farm_id: plan.farm_id, crop_plan_id: plan.id, observed_on: today() }" :url="route('agri.crops.observations.store')" @update:model-value="close" />
    <FormDialog v-if="target" :model-value="dialog === 'input'" :title="`Input for ${target.title}`" :fields="inputFields(options)"
      :url="route('agri.crops.inputs.store', target.id)" intro="Issued from the farm store when the activity is completed." @update:model-value="close" />
    <FormDialog v-if="target" :model-value="dialog === 'complete'" :title="`Complete: ${target.title}`" :fields="completeFields"
      :url="route('agri.crops.activities.status', target.id)" :values="{ action: 'complete', completed_at: today() }" submit-label="Complete"
      intro="Every planned input is issued from the farm store now, at cost, against this field and crop." @update:model-value="close" />
  </div>
</template>
