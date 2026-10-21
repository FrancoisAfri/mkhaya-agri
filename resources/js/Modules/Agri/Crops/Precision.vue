<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PageErrors from '../Components/PageErrors.vue'
import FormDialog, { type FormField } from '../Components/FormDialog.vue'
import FarmFilter from '../Components/FarmFilter.vue'
import { hoursFromNow, human, money, num, statusColor, today } from '../Components/agri'

/**
 * Phase 2 §3 in one place: scouting, irrigation, harvest, input planning and crop profitability.
 * Each tab loads only its own data.
 */
const props = defineProps<{
  tab: 'scouting' | 'irrigation' | 'harvest' | 'inputs' | 'profitability'
  filters: { farm: string | null; season: string | null; by: string }
  data: any
  options: { farms: any[]; seasons: Array<{ id: string; name: string }>; plans: any[]; crop_types: any[]; kinds: string[]; severities: string[]; people: Array<{ id: number; name: string }> }
  can: { record: boolean; plan: boolean; approve: boolean; review: boolean; harvest: boolean; finance: boolean; assistant: boolean }
}>()
const TABS = [
  { key: 'scouting', title: 'Scouting', icon: 'tabler-bug' }, { key: 'irrigation', title: 'Irrigation', icon: 'tabler-droplet' },
  { key: 'harvest', title: 'Harvest', icon: 'tabler-wheat' }, { key: 'inputs', title: 'Input planning', icon: 'tabler-package' },
  { key: 'profitability', title: 'Profitability', icon: 'tabler-chart-bar' },
]
const keep = { preserveScroll: true }
const go = (tab: string, extra: Record<string, any> = {}) => router.get(route('agri.crops.precision', tab), { farm: props.filters.farm || undefined, season: props.filters.season || undefined, ...extra }, { preserveState: false })
const dialog = ref<null | { kind: string; row?: any }>(null)
const open = (kind: string, row: any = null) => { dialog.value = { kind, row } }
const close = (v: boolean) => { if (!v) dialog.value = null }
const farmId = computed(() => props.filters.farm ?? props.options.farms[0]?.id)
const fieldsOf = (f: any) => (props.options.farms.find((x: any) => x.id === (f.farm_id ?? farmId.value))?.fields ?? []).map((x: any) => ({ title: `${x.code} · ${x.name}`, value: x.id }))
const plansOf = (f: any) => props.options.plans.filter((p: any) => !p.farm_id || p.farm_id === (f.farm_id ?? farmId.value)).map((p: any) => ({ title: `${p.code} · ${p.crop}`, value: p.id }))
const opts = (xs: string[]) => xs.map(x => ({ title: human(x), value: x }))
const sevColor = (s: string) => ({ low: 'info', medium: 'warning', high: 'error', critical: 'error' } as Record<string, string>)[s] ?? undefined
const confColor = (c: string) => ({ high: 'success', medium: 'warning', low: undefined } as Record<string, any>)[c]
const post = (name: string, params: any, body: Record<string, any> = {}) => router.post(route(name, params), body, keep)
const ask = (q: string) => prompt(q)

// ── Scouting ────────────────────────────────────────────────────────────────────────────────
const observeFields: FormField[] = [
  { key: 'farm_id', label: 'Farm', type: 'select', required: true, items: props.options.farms.map((f: any) => ({ title: f.name, value: f.id })) },
  { key: 'crop_plan_id', label: 'Crop', type: 'select', items: plansOf }, { key: 'field_id', label: 'Field', type: 'select', items: fieldsOf, show: (f: any) => !f.crop_plan_id },
  { key: 'observed_on', label: 'Seen on', type: 'date', required: true },
  { key: 'kind', label: 'What', type: 'select', required: true, items: opts(props.options.kinds) },
  { key: 'severity', label: 'How bad', type: 'select', required: true, items: opts(props.options.severities) },
  { key: 'incidence_pct', label: 'Plants affected (%)', type: 'number' }, { key: 'affected_area_ha', label: 'Area affected (ha)', type: 'number' },
  { key: 'growth_stage', label: 'Growth stage' }, { key: 'description', label: 'What you saw', type: 'textarea', required: true, cols: 12 },
  { key: 'recommended_action', label: 'Recommended action', cols: 12 },
  { key: 'responsible_user_id', label: 'Who follows it up', type: 'autocomplete', items: props.options.people.map(p => ({ title: p.name, value: p.id })) },
  { key: 'follow_up_on', label: 'Follow up by', type: 'date' },
  { key: 'latitude', label: 'Latitude', type: 'number', cols: 3 }, { key: 'longitude', label: 'Longitude', type: 'number', cols: 3 },
  { key: 'photos', label: 'Photos (up to 6)', type: 'file', multiple: true, accept: 'image/*', cols: 6 },
]
const thresholdFields: FormField[] = [
  { key: 'crop_type_id', label: 'Crop (blank = all)', type: 'select', items: props.options.crop_types.map((c: any) => ({ title: c.name, value: c.id })) },
  { key: 'kind', label: 'What', type: 'select', required: true, items: opts(props.options.kinds) },
  { key: 'min_severity', label: 'Counted from', type: 'select', required: true, items: opts(props.options.severities) },
  { key: 'min_incidence_pct', label: 'And at least % of plants', type: 'number' },
  { key: 'count', label: 'Observations', type: 'number', required: true, cols: 4 }, { key: 'within_days', label: 'Within days', type: 'number', required: true, cols: 4 },
  { key: 'escalate_after_hours', label: 'Escalate after (h)', type: 'number', required: true, cols: 4 }, { key: 'is_active', label: 'Active', type: 'switch' },
]
const treatFor = ref<any>(null)
const treatPick = ref<string | null>(null)
const geoUrl = (g: any) => `https://www.google.com/maps/search/?api=1&query=${g.lat},${g.lng}`

// ── Irrigation ──────────────────────────────────────────────────────────────────────────────
const zoneFields: FormField[] = [
  { key: 'farm_id', label: 'Farm', type: 'select', required: true, items: props.options.farms.map((f: any) => ({ title: f.name, value: f.id })) },
  { key: 'field_id', label: 'Field', type: 'select', required: true, items: fieldsOf },
  { key: 'code', label: 'Code', required: true, cols: 4 }, { key: 'name', label: 'Name', required: true, cols: 8 },
  { key: 'system', label: 'System', type: 'select', items: opts(['centre_pivot', 'drip', 'sprinkler', 'flood', 'micro', 'other']) },
  { key: 'area_ha', label: 'Area (ha)', type: 'number' },
  { key: 'pump_machine_id', label: 'Pump', type: 'select', items: () => (props.data.pumps ?? []).map((p: any) => ({ title: p.name, value: p.id })) },
  { key: 'flow_m3_per_hour', label: 'Flow (m³/h)', type: 'number' }, { key: 'water_cost_per_m3', label: 'Water cost per m³', type: 'number' },
  { key: 'energy_kwh_per_hour', label: 'Energy (kWh/h)', type: 'number' }, { key: 'energy_cost_per_kwh', label: 'Energy cost per kWh', type: 'number' },
  { key: 'meter_ref', label: 'Flow meter reference' },
]
const zonesOf = (f: any) => (props.data.zones ?? []).filter((z: any) => z.field_id === f.field_id).map((z: any) => ({ title: `${z.code} · ${z.name}`, value: z.id }))
const irrigationFields: FormField[] = [
  { key: 'farm_id', label: 'Farm', type: 'select', required: true, items: props.options.farms.map((f: any) => ({ title: f.name, value: f.id })) },
  { key: 'field_id', label: 'Field', type: 'select', required: true, items: fieldsOf }, { key: 'zone_id', label: 'Zone', type: 'select', items: zonesOf },
  { key: 'planned_start', label: 'Start', type: 'datetime', required: true }, { key: 'planned_minutes', label: 'Minutes', type: 'number' },
  { key: 'planned_m3', label: 'Or volume (m³)', type: 'number' }, { key: 'notes', label: 'Notes', cols: 12 },
]
const moistureMax = 60

// ── Harvest ─────────────────────────────────────────────────────────────────────────────────
const commitFields: FormField[] = [
  { key: 'farm_id', label: 'Farm', type: 'select', required: true, items: props.options.farms.map((f: any) => ({ title: f.name, value: f.id })) },
  { key: 'crop_plan_id', label: 'From crop', type: 'select', items: plansOf },
  { key: 'crop_type_id', label: 'Or any crop of', type: 'select', items: props.options.crop_types.map((c: any) => ({ title: c.name, value: c.id })), show: (f: any) => !f.crop_plan_id },
  { key: 'buyer', label: 'Buyer', required: true }, { key: 'channel', label: 'Channel', type: 'select', items: opts(['contract', 'market', 'packhouse', 'export', 'retail', 'other']) },
  { key: 'quantity', label: 'Quantity', type: 'number', required: true, cols: 4 }, { key: 'unit', label: 'Unit', required: true, cols: 4 }, { key: 'grade', label: 'Grade', cols: 4 },
  { key: 'deliver_on', label: 'Deliver by', type: 'date', required: true }, { key: 'price_per_unit', label: 'Price per unit', type: 'number' },
]
const deliverFor = ref<any>(null)
const deliverPick = ref<string | null>(null)

// ── Inputs ──────────────────────────────────────────────────────────────────────────────────
const templateFields = computed<FormField[]>(() => [
  { key: 'crop_type_id', label: 'Crop', type: 'select', required: true, items: props.options.crop_types.map((c: any) => ({ title: c.name, value: c.id })) },
  { key: 'production_method', label: 'Production method (blank = any)' },
  { key: 'category', label: 'Kind', type: 'select', required: true, items: opts(props.data.categories ?? []) },
  { key: 'stock_item_id', label: 'Stock item', type: 'autocomplete', items: props.data.stock_items ?? [], show: (f: any) => !['water', 'labour', 'machinery'].includes(f.category) },
  { key: 'description', label: 'Description', required: true, cols: 12 },
  { key: 'rate', label: 'Rate', type: 'number', required: true, cols: 4 }, { key: 'unit', label: 'Unit', required: true, cols: 4, hint: 'kg, l, bag, mm, h…' },
  { key: 'basis', label: 'Per', type: 'select', required: true, items: [{ title: 'hectare', value: 'per_ha' }, { title: 'tonne of expected yield', value: 'per_t_yield' }], cols: 4 },
  { key: 'timing_days', label: 'Days after planting', type: 'number' }, { key: 'guidance_ref', label: 'Agronomic source', hint: 'The recommendation the rate comes from' },
])
const LINE: Record<string, { label: string; color?: string }> = { covered: { label: 'In store', color: 'success' }, on_order: { label: 'On order', color: 'info' },
  arrives_late: { label: 'Order arrives late', color: 'warning' }, to_order: { label: 'To order', color: 'warning' }, too_late: { label: 'Order now — late', color: 'error' },
  not_stocked: { label: 'Farm rate' }, unknown: { label: '—' } }

// ── Profitability ───────────────────────────────────────────────────────────────────────────
const overheadFields: FormField[] = [
  { key: 'farm_id', label: 'Farm (blank = every farm)', type: 'select', items: props.options.farms.map((f: any) => ({ title: f.name, value: f.id })) },
  { key: 'name', label: 'Overhead', required: true }, { key: 'basis', label: 'Basis', type: 'select', required: true, items: [{ title: 'Per hectare, per crop cycle', value: 'per_ha' }, { title: '% of direct costs', value: 'pct_direct' }] },
  { key: 'rate', label: 'Rate', type: 'number', required: true }, { key: 'valid_from', label: 'From', type: 'date', required: true }, { key: 'valid_to', label: 'To', type: 'date' },
  { key: 'is_active', label: 'Active', type: 'switch', default: true },
]
const COST_LABELS: Record<string, string> = { seed: 'Seed', fertiliser: 'Fertiliser', chemical: 'Chemicals', other_inputs: 'Other inputs', packaging: 'Packaging',
  transport: 'Transport', storage: 'Storage', water_energy: 'Water & energy', piecework: 'Piecework', labour: 'Labour', machinery: 'Machinery', overheads_posted: 'Posted overheads' }
const expanded = ref<string | null>(null)
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div>
        <h4 class="text-h4 mb-1">Crop intelligence</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">Scouting, water, harvest, inputs and margins for every crop — measured figures and estimates kept apart.</p>
      </div>
      <div class="d-flex gap-2 align-center flex-wrap">
        <FarmFilter :farms="options.farms" :model-value="filters.farm" />
        <VSelect :model-value="filters.season" :items="options.seasons.map(s => ({ title: s.name, value: s.id }))" label="Season" clearable density="compact" hide-details style="min-width: 180px"
          @update:model-value="(v: any) => go(tab, { season: v || undefined })" />
      </div>
    </div>
    <div class="d-flex flex-wrap gap-2 mb-5">
      <VChip v-for="t in TABS" :key="t.key" :color="tab === t.key ? 'primary' : undefined" :variant="tab === t.key ? 'flat' : 'tonal'" :prepend-icon="t.icon" @click="go(t.key)">{{ t.title }}</VChip>
      <VSpacer /><Link :href="route('agri.crops.index')" class="text-body-2 align-self-center">Crop plans</Link>
    </div>

    <!-- ── Scouting ─────────────────────────────────────────────────────────────────────── -->
    <template v-if="tab === 'scouting'">
      <div class="d-flex gap-2 mb-3">
        <VBtn v-if="can.record" color="primary" prepend-icon="tabler-plus" @click="open('observe')">Record observation</VBtn>
        <VBtn v-if="can.plan" variant="tonal" prepend-icon="tabler-adjustments" @click="open('threshold')">Threshold</VBtn>
        <VChip v-for="t in data.thresholds" :key="t.id" size="small" variant="outlined" class="align-self-center" @click="can.plan && open('threshold', t)">
          {{ human(t.kind) }}: {{ t.count }}× {{ t.min_severity }}+ in {{ t.within_days }}d → escalate {{ t.escalate_after_hours }}h
        </VChip>
      </div>
      <VCard v-for="o in data.observations" :key="o.id" class="mb-3" :variant="o.status === 'closed' ? 'outlined' : 'elevated'">
        <VCardItem>
          <VCardTitle class="text-wrap">{{ human(o.kind) }}<template v-if="o.plan"> · {{ o.plan }}</template><template v-else-if="o.field"> · {{ o.field }}</template>
            <VChip size="x-small" :color="sevColor(o.severity)" class="ms-2">{{ o.severity }}</VChip>
            <VChip v-if="o.action_required && o.status === 'open'" size="x-small" color="error" variant="tonal" class="ms-1">Action needed</VChip>
            <VChip v-if="o.escalated" size="x-small" color="error" class="ms-1">Escalated</VChip>
            <VChip v-if="o.overdue" size="x-small" color="warning" class="ms-1">Follow-up overdue</VChip>
          </VCardTitle>
          <VCardSubtitle>{{ o.observed_on }}<template v-if="o.incidence_pct !== null"> · {{ o.incidence_pct }}% of plants</template><template v-if="o.affected_area_ha"> · {{ o.affected_area_ha }} ha</template>
            <template v-if="o.growth_stage"> · {{ o.growth_stage }}</template> · {{ human(o.status) }}</VCardSubtitle>
          <template #append><a v-if="o.gps" :href="geoUrl(o.gps)" target="_blank" rel="noopener"><VIcon icon="tabler-map-pin" /></a></template>
        </VCardItem>
        <VCardText>
          <div>{{ o.description }}</div>
          <div v-if="o.recommended_action || o.responsible" class="text-body-2 mt-1"><strong>Next:</strong> {{ o.recommended_action ?? '—' }}<template v-if="o.responsible"> · {{ o.responsible }}</template><template v-if="o.follow_up_on"> by {{ o.follow_up_on }}</template></div>
          <div v-if="o.photos.length" class="d-flex gap-2 mt-2"><a v-for="p in o.photos" :key="p" :href="route('agri.crops.scouting.photo', p)" target="_blank"><VImg :src="route('agri.crops.scouting.photo', p)" width="72" height="72" cover class="rounded" /></a></div>
          <VAlert v-if="o.ai" type="info" variant="tonal" density="compact" class="mt-3" icon="tabler-sparkles">
            <div class="font-weight-medium">Possible causes (AI suggestion — not a diagnosis)</div>
            <div v-for="d in o.ai.diagnoses" :key="d.name" class="text-body-2">
              <VChip size="x-small" :color="confColor(d.confidence)" class="me-1">{{ d.confidence }}</VChip><strong>{{ d.name }}</strong> — {{ d.why }}<span v-if="d.look_for" class="text-medium-emphasis"> Look for: {{ d.look_for }}</span>
            </div>
            <div class="text-caption mt-1">{{ o.ai.caveats }} {{ o.ai.basis }}</div>
            <div class="text-caption mt-1" :class="o.reviewed_by ? 'text-success' : 'text-warning'">{{ o.reviewed_by ? `Reviewed by ${o.reviewed_by}: ${o.review_note}` : 'Not yet reviewed by a qualified person — no treatment can be recorded against it until it is.' }}</div>
          </VAlert>
        </VCardText>
        <VCardActions v-if="o.status !== 'closed'" class="flex-wrap">
          <VBtn v-if="can.assistant" size="small" variant="text" prepend-icon="tabler-sparkles" @click="post('agri.crops.scouting.suggest', o.id)">{{ o.ai ? 'Ask again' : 'Suggest causes' }}</VBtn>
          <VBtn v-if="can.review && o.ai && !o.reviewed_by" size="small" variant="tonal" color="success" @click="(() => { const n = ask('Your review (accepted, corrected or rejected, and why):'); if (n) post('agri.crops.scouting.review', o.id, { note: n }) })()">Review</VBtn>
          <VBtn v-if="can.record && !o.treated" size="small" variant="text" prepend-icon="tabler-spray" @click="treatFor = o; treatPick = null">Link treatment</VBtn>
          <VSpacer />
          <VBtn v-if="can.record" size="small" variant="text" @click="post('agri.crops.scouting.close', o.id)">Close</VBtn>
        </VCardActions>
      </VCard>
      <VCard v-if="!data.observations.length"><VCardText class="text-center text-medium-emphasis">No observations yet.</VCardText></VCard>
      <VDialog :model-value="!!treatFor" max-width="480" @update:model-value="v => !v && (treatFor = null)">
        <VCard v-if="treatFor" title="Which activity treated it?">
          <VCardText><VSelect v-model="treatPick" :items="treatFor.treatable.map((a: any) => ({ title: a.title, value: a.id }))" label="Field activity" no-data-text="Plan the treatment as a field activity first" /></VCardText>
          <VCardActions><VSpacer /><VBtn variant="text" @click="treatFor = null">Cancel</VBtn><VBtn color="primary" :disabled="!treatPick" @click="post('agri.crops.scouting.treat', treatFor.id, { activity_id: treatPick }); treatFor = null">Link</VBtn></VCardActions>
        </VCard>
      </VDialog>
    </template>

    <!-- ── Irrigation ───────────────────────────────────────────────────────────────────── -->
    <template v-if="tab === 'irrigation'">
      <div class="d-flex flex-wrap gap-2 mb-3">
        <VBtn v-if="can.record" color="primary" prepend-icon="tabler-droplet-plus" @click="open('irrigate')">Plan irrigation</VBtn>
        <VBtn v-if="can.record" variant="tonal" prepend-icon="tabler-gauge" @click="open('moisture')">Soil moisture</VBtn>
        <VBtn v-if="can.record" variant="tonal" color="warning" prepend-icon="tabler-alert-triangle" @click="open('problem')">Report a problem</VBtn>
        <VBtn v-if="can.plan" variant="tonal" prepend-icon="tabler-map-2" @click="open('zone')">Zone</VBtn>
      </div>
      <VAlert v-for="x in data.exceptions" :key="x.id" type="warning" variant="tonal" density="compact" class="mb-2">
        <div class="d-flex align-center gap-2 flex-wrap"><strong>{{ human(x.kind) }}</strong><span v-if="x.field">on {{ x.field }}</span> · {{ x.at }} · {{ x.note }}<VSpacer />
          <VBtn v-if="can.record" size="x-small" variant="tonal" @click="(() => { const r = ask('What was done?'); if (r) post('agri.crops.irrigation-problems.resolve', x.id, { resolution: r }) })()">Resolve</VBtn></div>
      </VAlert>
      <VRow>
        <VCol cols="12" md="8">
          <VCard title="Irrigation, planned and given">
            <VTable density="compact">
              <thead><tr><th>Field / zone</th><th>Planned</th><th>Given</th><th class="text-end">Cost</th><th /></tr></thead>
              <tbody>
                <tr v-for="e in data.events" :key="e.id">
                  <td>{{ e.field }}<span v-if="e.zone" class="text-caption"> · {{ e.zone }}</span></td>
                  <td class="text-caption">{{ e.planned_start }}<br>{{ e.planned_minutes ? e.planned_minutes + ' min' : '' }}{{ e.planned_m3 ? ' · ' + num(e.planned_m3, 0) + ' m³' : '' }}</td>
                  <td class="text-caption"><template v-if="e.status === 'done'">{{ e.actual_start }}<br>{{ e.actual_minutes }} min · {{ num(e.actual_m3, 0) }} m³ <span v-if="e.source === 'meter'">(meter)</span></template>
                    <VChip v-else size="x-small" :color="statusColor(e.status)">{{ human(e.status) }}</VChip></td>
                  <td class="text-end">{{ e.cost ? money(e.cost) : '' }}</td>
                  <td class="text-end text-no-wrap"><template v-if="e.status === 'planned' && can.record">
                    <VBtn size="x-small" variant="tonal" color="primary" @click="open('given', e)">Given</VBtn>
                    <VBtn size="x-small" variant="text" @click="(() => { const r = ask('Why skipped?'); if (r) post('agri.crops.irrigation.record', e.id, { skip: true, reason: r }) })()">Skip</VBtn></template></td>
                </tr>
                <tr v-if="!data.events.length"><td colspan="5" class="text-center text-medium-emphasis py-4">Nothing planned or given in the last 30 days.</td></tr>
              </tbody>
            </VTable>
          </VCard>
        </VCol>
        <VCol cols="12" md="4">
          <VCard title="Soil moisture (30 days)" class="mb-4">
            <VCardText>
              <div v-for="m in data.moisture" :key="m.field" class="mb-3">
                <div class="d-flex justify-space-between text-body-2"><strong>{{ m.field }}</strong><span>{{ m.latest }}% · {{ m.at }}</span></div>
                <div class="d-flex align-end gap-1" style="height: 40px"><div v-for="(p, i) in m.series" :key="i" :title="`${p.at}: ${p.vwc}%`" class="flex-grow-1 rounded-t"
                  :style="{ height: Math.max(4, p.vwc / moistureMax * 40) + 'px', background: 'rgb(var(--v-theme-info))', opacity: 0.4 + 0.6 * (i + 1) / m.series.length }" /></div>
              </div>
              <div v-if="!data.moisture.length" class="text-medium-emphasis">No readings yet.</div>
            </VCardText>
          </VCard>
          <VCard title="Zones">
            <VList density="compact" lines="two"><VListItem v-for="z in data.zones" :key="z.id" :title="`${z.code} · ${z.name} (${z.field})`"
              :subtitle="[z.system && human(z.system), z.flow_m3_per_hour && `${z.flow_m3_per_hour} m³/h`, z.water_cost_per_m3 !== null && `${money(z.water_cost_per_m3)}/m³`, z.pump && `pump ${z.pump}`].filter(Boolean).join(' · ')"
              @click="can.plan && open('zone', z)" /><VListItem v-if="!data.zones.length" title="No zones yet" subtitle="A zone holds the flow rate and costs used to work out water and cost." /></VList>
          </VCard>
          <p class="text-caption text-medium-emphasis mt-3">Recording and advice only: Mkhaya Agri does not switch pumps or valves. Flow meters and soil probes can post readings through a registered device.</p>
        </VCol>
      </VRow>
    </template>

    <!-- ── Harvest ──────────────────────────────────────────────────────────────────────── -->
    <template v-if="tab === 'harvest'">
      <div class="d-flex gap-2 mb-3"><VBtn v-if="can.plan || can.finance" color="primary" prepend-icon="tabler-handshake" @click="open('commit')">Buyer commitment</VBtn></div>
      <VRow>
        <VCol v-for="p in data.plans" :key="p.id" cols="12" md="6">
          <VCard>
            <VCardItem>
              <VCardTitle><Link :href="route('agri.crops.plans.show', p.id)">{{ p.code }}</Link> · {{ p.crop }}<span v-if="p.variety" class="text-body-2"> {{ p.variety }}</span></VCardTitle>
              <VCardSubtitle>{{ p.field }} · {{ p.hectares }} ha · {{ human(p.status) }}<template v-if="p.stage"> · {{ human(p.stage) }}</template></VCardSubtitle>
              <template #append><VBtn v-if="can.plan || can.harvest" size="small" variant="tonal" @click="post('agri.crops.forecasts.store', p.id, { method: 'model' })">Forecast</VBtn></template>
            </VCardItem>
            <VCardText>
              <VRow dense>
                <VCol cols="6">
                  <div class="text-caption">Estimate <VChip size="x-small" variant="outlined">{{ p.forecast ? (p.forecast.method === 'manual' ? 'person' : 'model') : 'none' }}</VChip></div>
                  <template v-if="p.forecast"><div class="text-h6">{{ num(p.forecast.quantity, 1) }} {{ p.unit }}</div>
                    <div class="text-caption">{{ p.forecast.low !== null ? `${num(p.forecast.low, 1)}–${num(p.forecast.high, 1)} · ` : '' }}ready {{ p.forecast.ready_on ?? '—' }}</div></template>
                  <div v-else class="text-body-2 text-medium-emphasis">No forecast yet</div>
                </VCol>
                <VCol cols="6">
                  <div class="text-caption">Measured</div>
                  <div class="text-h6">{{ num(p.measured.harvested, 1) }} {{ p.unit }}</div>
                  <div class="text-caption">{{ num(p.measured.saleable, 1) }} saleable<template v-if="p.measured.rejected || p.measured.spoiled || p.measured.field_loss"> · lost {{ num(p.measured.rejected + p.measured.spoiled + p.measured.field_loss, 1) }}</template></div>
                </VCol>
              </VRow>
              <div v-if="p.forecast?.basis" class="text-caption text-medium-emphasis mt-1">Based on: {{ p.forecast.basis.join('; ') }}</div>
              <div v-if="p.logistics" class="text-body-2 mt-2"><VIcon icon="tabler-truck" size="16" /> Still to bring in {{ num(p.logistics.remaining, 1) }} {{ p.unit }}<template v-if="p.logistics.containers"> · {{ p.logistics.containers }} {{ p.logistics.container }}s</template><template v-if="p.logistics.loads"> · {{ p.logistics.loads }} loads</template></div>
              <div v-if="p.jobs.length" class="text-body-2 mt-1"><VIcon icon="tabler-clipboard-list" size="16" /> <Link v-for="j in p.jobs" :key="j.id" :href="route('agri.work-orders.show', j.id)" class="me-2">{{ j.reference }} ({{ human(j.status) }})</Link></div>
              <div v-if="p.commitments.length" class="text-body-2 mt-1"><VIcon icon="tabler-handshake" size="16" /> Committed {{ num(p.committed, 1) }} {{ p.unit }} to {{ p.commitments.map((c: any) => c.buyer).join(', ') }}</div>
              <div v-if="p.measured.lots.length" class="text-caption mt-1">Lots: {{ p.measured.lots.map((l: any) => l.lot).join(', ') }}</div>
            </VCardText>
          </VCard>
        </VCol>
        <VCol v-if="!data.plans.length" cols="12"><VCard><VCardText class="text-center text-medium-emphasis">No crops in the ground.</VCardText></VCard></VCol>
      </VRow>
      <VCard title="Buyer commitments" class="mt-4">
        <VTable density="compact">
          <thead><tr><th>Buyer</th><th>Grade</th><th class="text-end">Quantity</th><th class="text-end">Delivered</th><th>By</th><th class="text-end">Price</th><th>Status</th><th /></tr></thead>
          <tbody>
            <tr v-for="c in data.commitments" :key="c.id"><td>{{ c.buyer }}<span v-if="c.channel" class="text-caption"> · {{ human(c.channel) }}</span></td><td>{{ c.grade ?? '—' }}</td>
              <td class="text-end">{{ num(c.quantity, 1) }} {{ c.unit }}</td><td class="text-end">{{ num(c.delivered, 1) }}</td><td>{{ c.deliver_on }}</td>
              <td class="text-end">{{ c.price !== null ? money(c.price) : '—' }}</td><td><VChip size="x-small" :color="c.status === 'short' ? 'error' : statusColor(c.status)">{{ human(c.status) }}</VChip></td>
              <td class="text-end"><VBtn v-if="can.harvest" size="x-small" variant="tonal" @click="deliverFor = c; deliverPick = null">Deliver</VBtn></td></tr>
            <tr v-if="!data.commitments.length"><td colspan="8" class="text-center text-medium-emphasis py-4">No open commitments.</td></tr>
          </tbody>
        </VTable>
      </VCard>
      <VDialog :model-value="!!deliverFor" max-width="480" @update:model-value="v => !v && (deliverFor = null)">
        <VCard v-if="deliverFor" :title="`Deliver to ${deliverFor.buyer}`">
          <VCardText><VSelect v-model="deliverPick" :items="data.harvests.map((h: any) => ({ title: h.label, value: h.id }))" label="Harvest lot" no-data-text="Record the harvest on its crop plan first" /></VCardText>
          <VCardActions><VSpacer /><VBtn variant="text" @click="deliverFor = null">Cancel</VBtn><VBtn color="primary" :disabled="!deliverPick" @click="post('agri.crops.commitments.deliver', deliverFor.id, { harvest_id: deliverPick }); deliverFor = null">Deliver</VBtn></VCardActions>
        </VCard>
      </VDialog>
    </template>

    <!-- ── Input planning ───────────────────────────────────────────────────────────────── -->
    <template v-if="tab === 'inputs'">
      <VRow>
        <VCol cols="12" lg="7">
          <VCard title="Buying list for the live crops" class="mb-4">
            <VCardSubtitle class="pb-2">Everything the approved rates call for, against the farm store and orders already placed.</VCardSubtitle>
            <VTable density="compact">
              <thead><tr><th>Item</th><th class="text-end">Needed</th><th class="text-end">In store</th><th class="text-end">On order</th><th class="text-end">To buy</th><th>Order by</th><th class="text-end">Cost</th></tr></thead>
              <tbody>
                <tr v-for="b in data.buying" :key="b.item + b.unit"><td>{{ b.item }}<div class="text-caption">{{ b.plans.join(', ') }}</div></td>
                  <td class="text-end">{{ num(b.quantity, 1) }} {{ b.unit }}</td><td class="text-end">{{ num(b.in_store, 1) }}</td><td class="text-end">{{ num(b.on_order, 1) }}</td>
                  <td class="text-end" :class="b.to_buy > 0 ? 'text-error font-weight-medium' : ''">{{ b.to_buy > 0 ? num(b.to_buy, 1) : '—' }}</td>
                  <td :class="b.order_by && b.order_by < today() && b.to_buy > 0 ? 'text-error' : ''">{{ b.order_by ?? '—' }}<span v-if="b.lead_time_days !== null" class="text-caption"> ({{ b.lead_time_days }}d lead)</span></td>
                  <td class="text-end">{{ money(b.cost) }}</td></tr>
                <tr v-if="!data.buying.length"><td colspan="7" class="text-center text-medium-emphasis py-4">No approved input rates apply to the live crops yet.</td></tr>
              </tbody>
            </VTable>
          </VCard>
          <VCard title="Plans against budget">
            <VTable density="compact"><thead><tr><th>Plan</th><th class="text-end">Hectares</th><th class="text-end">Planned inputs</th><th class="text-end">Budget</th><th>Problems</th></tr></thead>
              <tbody><tr v-for="p in data.plans" :key="p.id"><td><Link :href="route('agri.crops.plans.show', p.id)">{{ p.code }}</Link></td><td class="text-end">{{ num(p.hectares, 1) }}</td>
                <td class="text-end" :class="p.over_budget ? 'text-error' : ''">{{ money(p.planned_cost) }}</td><td class="text-end">{{ p.budget !== null ? money(p.budget) : '—' }}</td>
                <td><VChip v-if="p.problems" size="x-small" color="warning">{{ p.problems }} supply</VChip><VChip v-if="p.over_budget" size="x-small" color="error" class="ms-1">over budget</VChip></td></tr></tbody></VTable>
          </VCard>
        </VCol>
        <VCol cols="12" lg="5">
          <VCard title="Input rates (agronomic guidance)">
            <template #append><VBtn v-if="can.plan" size="small" variant="tonal" prepend-icon="tabler-plus" @click="open('template')">Rate</VBtn></template>
            <VList density="compact" lines="three">
              <VListItem v-for="t in data.templates" :key="t.id" :title="`${t.crop}: ${t.description}`"
                :subtitle="`${num(t.rate, 2)} ${t.unit} per ${t.basis === 'per_ha' ? 'ha' : 't yield'} · ${human(t.category)}${t.item ? ' · ' + t.item : ''}${t.timing_days !== null ? ' · day ' + t.timing_days : ''}${t.guidance_ref ? ' · ' + t.guidance_ref : ''}`">
                <template #append>
                  <VChip size="x-small" :color="t.status === 'approved' ? 'success' : t.status === 'retired' ? undefined : 'warning'" class="me-1">{{ human(t.status) }}</VChip>
                  <VBtn v-if="can.approve && t.status === 'draft' && !t.mine" size="x-small" color="success" variant="tonal" @click="post('agri.crops.templates.approve', t.uuid)">Approve</VBtn>
                  <VBtn v-if="can.plan && t.status !== 'retired'" size="x-small" variant="text" icon="tabler-edit" @click="open('template', t)" />
                </template>
              </VListItem>
              <VListItem v-if="!data.templates.length" title="No rates yet" subtitle="Add the seed, fertiliser, chemical, water, labour and packaging rates from the agronomist's recommendation." />
            </VList>
          </VCard>
        </VCol>
      </VRow>
    </template>

    <!-- ── Profitability ────────────────────────────────────────────────────────────────── -->
    <template v-if="tab === 'profitability'">
      <VAlert type="info" variant="tonal" density="compact" class="mb-4">
        Same rules for every crop: <strong>contribution</strong> = revenue − variable costs (seed, fertiliser, chemicals, packaging, transport, storage, water and energy, piecework);
        <strong>gross</strong> = contribution − time-paid labour and machinery; <strong>net</strong> = gross − allocated overheads. Costs posted to the books and operating costs recorded on the farm are both counted and shown apart.
      </VAlert>
      <VCard class="mb-4">
        <VCardItem><VCardTitle>Compare</VCardTitle>
          <template #append><VBtnToggle :model-value="filters.by" density="compact" variant="outlined" divided @update:model-value="(v: any) => go('profitability', { by: v })">
            <VBtn value="variety">Variety</VBtn><VBtn value="field">Field</VBtn><VBtn value="season">Season</VBtn><VBtn value="method">Method</VBtn></VBtnToggle></template></VCardItem>
        <VTable density="compact">
          <thead><tr><th>{{ human(filters.by) }}</th><th class="text-end">ha</th><th class="text-end">Yield / ha</th><th class="text-end">Revenue</th><th class="text-end">Costs</th><th class="text-end">Contribution</th><th class="text-end">Net margin</th><th class="text-end">Net / ha</th></tr></thead>
          <tbody><tr v-for="c in data.compare" :key="c.group"><td>{{ c.group }}<div class="text-caption">{{ c.plans.join(', ') }}</div></td><td class="text-end">{{ c.hectares }}</td>
            <td class="text-end">{{ c.yield_per_ha ?? '—' }}</td><td class="text-end">{{ money(c.revenue) }}</td><td class="text-end">{{ money(c.total_costs) }}</td>
            <td class="text-end">{{ money(c.contribution_margin) }}</td><td class="text-end" :class="c.net_margin < 0 ? 'text-error' : 'text-success'">{{ money(c.net_margin) }}</td>
            <td class="text-end font-weight-medium">{{ c.net_margin_per_ha !== null ? money(c.net_margin_per_ha) : '—' }}</td></tr>
            <tr v-if="!data.compare.length"><td colspan="8" class="text-center text-medium-emphasis py-4">No planted or completed crops in this selection.</td></tr></tbody>
        </VTable>
      </VCard>
      <VCard title="Per crop" class="mb-4">
        <VTable density="compact">
          <thead><tr><th>Crop</th><th class="text-end">Revenue</th><th class="text-end">Variable</th><th class="text-end">Direct</th><th class="text-end">Overheads</th><th class="text-end">Net</th><th class="text-end">Per ha</th><th class="text-end">Per kg</th><th class="text-end">Per saleable unit</th></tr></thead>
          <tbody>
            <template v-for="r in data.plans" :key="r.plan.id">
              <tr style="cursor: pointer" @click="expanded = expanded === r.plan.id ? null : r.plan.id">
                <td><Link :href="route('agri.crops.plans.show', r.plan.id)" @click.stop>{{ r.plan.code }}</Link> <span class="text-caption">{{ r.plan.crop }} {{ r.plan.variety }} · {{ r.plan.field }}</span></td>
                <td class="text-end">{{ money(r.revenue) }}</td><td class="text-end">{{ money(r.variable_costs) }}</td><td class="text-end">{{ money(r.direct_costs) }}</td>
                <td class="text-end">{{ money(r.overheads.total) }}</td><td class="text-end" :class="r.net_margin < 0 ? 'text-error' : 'text-success'">{{ money(r.net_margin) }}</td>
                <td class="text-end">{{ r.per_ha ? money(r.per_ha.cost) : '—' }}</td><td class="text-end">{{ r.per_kg !== null ? money(r.per_kg) : '—' }}</td>
                <td class="text-end">{{ r.per_saleable_unit !== null ? `${money(r.per_saleable_unit)}/${r.plan.unit}` : '—' }}</td>
              </tr>
              <tr v-if="expanded === r.plan.id"><td colspan="9" class="bg-surface-light">
                <div class="d-flex flex-wrap gap-2 py-2"><VChip v-for="(v, k) in r.costs" v-show="v" :key="k" size="small" variant="outlined">{{ COST_LABELS[k] ?? k }} {{ money(v) }}</VChip></div>
                <div class="text-caption">In the books {{ money(r.booked_costs) }} · operating {{ money(r.operating_costs) }} · contribution {{ money(r.contribution_margin) }} · gross {{ money(r.gross_margin) }}
                  <template v-for="(v, g) in r.revenue_by_grade" :key="g"> · grade {{ g }} {{ money(v) }}</template><template v-for="(v, b) in r.revenue_by_buyer" :key="b"> · {{ b }} {{ money(v) }}</template></div>
              </td></tr>
            </template>
          </tbody>
        </VTable>
      </VCard>
      <VCard title="Overhead allocation">
        <template #append><VBtn v-if="can.finance" size="small" variant="tonal" prepend-icon="tabler-plus" @click="open('overhead')">Rule</VBtn></template>
        <VList density="compact"><VListItem v-for="o in data.overheads" :key="o.id" :title="o.name" :subtitle="`${o.basis === 'per_ha' ? money(o.rate) + ' per ha per crop cycle' : o.rate + '% of direct costs'} · from ${o.valid_from?.slice(0, 10)}${o.valid_to ? ' to ' + o.valid_to.slice(0, 10) : ''}${o.is_active ? '' : ' · inactive'}`"
          @click="can.finance && open('overhead', o)" /><VListItem v-if="!data.overheads.length" title="No overheads allocated" subtitle="Without rules, net margin equals gross margin." /></VList>
      </VCard>
    </template>

    <!-- ── Dialogs ──────────────────────────────────────────────────────────────────────── -->
    <FormDialog v-if="dialog?.kind === 'observe'" :model-value="true" title="Record a scouting observation" :url="route('agri.crops.scouting.store')" :fields="observeFields"
      :values="{ farm_id: farmId, observed_on: today(), kind: 'pest', severity: 'medium' }" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'threshold'" :model-value="true" :title="dialog.row ? 'Edit threshold' : 'New threshold'" :method="dialog.row ? 'patch' : 'post'"
      :url="dialog.row ? route('agri.crops.thresholds.update', dialog.row.uuid) : route('agri.crops.thresholds.store')" :fields="thresholdFields"
      :values="dialog.row ?? { kind: 'pest', min_severity: 'medium', count: 2, within_days: 7, escalate_after_hours: 48, is_active: true }"
      intro="When this many observations of this kind and severity are recorded on one field, the observation needs action and the farm's leads are told. If nobody acts in time it is escalated." @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'zone'" :model-value="true" :title="dialog.row ? `Edit ${dialog.row.code}` : 'New irrigation zone'" :method="dialog.row ? 'patch' : 'post'"
      :url="dialog.row ? route('agri.crops.zones.update', dialog.row.id) : route('agri.crops.zones.store')" :fields="zoneFields" :values="dialog.row ? { ...dialog.row } : { farm_id: farmId }" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'irrigate'" :model-value="true" title="Plan irrigation" :url="route('agri.crops.irrigation.store')" :fields="irrigationFields"
      :values="{ farm_id: farmId, planned_start: hoursFromNow(1) }" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'given'" :model-value="true" :title="`Given: ${dialog.row.field}`" :url="route('agri.crops.irrigation.record', dialog.row.id)" :fields="[
      { key: 'actual_start', label: 'Started', type: 'datetime' }, { key: 'actual_minutes', label: 'Minutes', type: 'number' },
      { key: 'actual_m3', label: 'Volume (m³) — blank works it out from the zone', type: 'number' }, { key: 'source', label: 'From', type: 'select', items: [{ title: 'Typed in', value: 'manual' }, { title: 'Meter reading', value: 'meter' }] },
      { key: 'notes', label: 'Notes', cols: 12 },
    ]" :values="{ actual_start: dialog.row.planned_start?.replace(' ', 'T'), actual_minutes: dialog.row.planned_minutes, source: 'manual' }" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'moisture'" :model-value="true" title="Soil moisture reading" :url="route('agri.crops.moisture.store')" :fields="[
      { key: 'farm_id', label: 'Farm', type: 'select', required: true, items: options.farms.map((f: any) => ({ title: f.name, value: f.id })) },
      { key: 'field_id', label: 'Field', type: 'select', required: true, items: fieldsOf }, { key: 'vwc_pct', label: 'Volumetric water content (%)', type: 'number', required: true },
      { key: 'depth_cm', label: 'Depth (cm)', type: 'number' },
    ]" :values="{ farm_id: farmId }" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'problem'" :model-value="true" title="Irrigation problem" :url="route('agri.crops.irrigation-problems.store')" :fields="[
      { key: 'farm_id', label: 'Farm', type: 'select', required: true, items: options.farms.map((f: any) => ({ title: f.name, value: f.id })) },
      { key: 'field_id', label: 'Field', type: 'select', items: fieldsOf }, { key: 'zone_id', label: 'Zone', type: 'select', items: zonesOf },
      { key: 'kind', label: 'What', type: 'select', required: true, items: opts(['leak', 'fault', 'over_applied', 'under_applied', 'no_water', 'other']) },
      { key: 'note', label: 'Details', type: 'textarea', cols: 12 },
    ]" :values="{ farm_id: farmId, kind: 'leak' }" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'commit'" :model-value="true" title="Buyer commitment" :url="route('agri.crops.commitments.store')" :fields="commitFields"
      :values="{ farm_id: farmId, unit: 't' }" @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'template'" :model-value="true" :title="dialog.row ? 'Edit input rate' : 'New input rate'" :method="dialog.row ? 'patch' : 'post'"
      :url="dialog.row ? route('agri.crops.templates.update', dialog.row.uuid) : route('agri.crops.templates.store')" :fields="templateFields"
      :values="dialog.row ?? { basis: 'per_ha', category: 'fertiliser' }" intro="A rate counts in planning once somebody other than its author approves it. Changing it sends it back for approval." @update:model-value="close" />
    <FormDialog v-if="dialog?.kind === 'overhead'" :model-value="true" :title="dialog.row ? 'Edit overhead rule' : 'New overhead rule'" :method="dialog.row ? 'patch' : 'post'"
      :url="dialog.row ? route('agri.crops.overheads.update', dialog.row.uuid) : route('agri.crops.overheads.store')" :fields="overheadFields"
      :values="dialog.row ? { ...dialog.row, valid_from: dialog.row.valid_from?.slice(0, 10), valid_to: dialog.row.valid_to?.slice(0, 10) } : { basis: 'per_ha', valid_from: today(), is_active: true }" @update:model-value="close" />
  </div>
</template>
