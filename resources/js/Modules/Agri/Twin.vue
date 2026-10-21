<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PageErrors from './Components/PageErrors.vue'
import FormDialog, { type FormField } from './Components/FormDialog.vue'
import FarmFilter from './Components/FarmFilter.vue'
import { human, money, num, today } from './Components/agri'

/** Phase 2 §16: the farm map with live state, crop imagery and its flags, the decision simulator. */
const props = defineProps<{
  tab: 'map' | 'imagery' | 'simulator'
  farm: null | { id: string; name: string }
  farms: Array<{ id: string; name: string }>
  data: any
  options: { fields: any[]; indices: string[]; crop_types: any[]; varieties: any[]; seasons: any[]; changes: string[] }
  can: { record: boolean; plan: boolean }
}>()
const TABS = [{ key: 'map', title: 'Farm map', icon: 'tabler-map-2' }, { key: 'imagery', title: 'Crop imagery', icon: 'tabler-satellite' }, { key: 'simulator', title: 'Decision simulator', icon: 'tabler-arrows-split' }]
const keep = { preserveScroll: true }
const go = (tab: string) => router.get(route('agri.twin', tab), { farm: props.farm?.id }, { preserveState: false })

// ── map: project lng/lat to an SVG box, keeping the shape (cos latitude) ──
const W = 900, H = 560, PAD = 30
const view = computed(() => {
  const b = props.data.bounds
  if (!b) return null
  const [x0, y0, x1, y1] = b
  const k = Math.cos(((y0 + y1) / 2) * Math.PI / 180)
  const dx = Math.max((x1 - x0) * k, 1e-5), dy = Math.max(y1 - y0, 1e-5)
  const s = Math.min((W - 2 * PAD) / dx, (H - 2 * PAD) / dy)
  return { p: (c: number[]) => [PAD + (c[0] - x0) * k * s, H - PAD - (c[1] - y0) * s] as [number, number] }
})
const rings = (g: any): number[][][] => g.type === 'Polygon' ? g.coordinates : g.type === 'MultiPolygon' ? g.coordinates.flat() : []
const path = (g: any) => rings(g).map(r => r.map((c, i) => `${i ? 'L' : 'M'}${view.value!.p(c).join(',')}`).join(' ') + 'Z').join(' ')
const centre = (g: any): [number, number] => {
  if (g.type === 'Point') return view.value!.p(g.coordinates)
  const pts = rings(g).flat(); const sx = pts.reduce((a, c) => a + c[0], 0) / pts.length, sy = pts.reduce((a, c) => a + c[1], 0) / pts.length
  return view.value!.p([sx, sy])
}
const FILL: Record<string, string> = { growing: '#7cb342', fallow: '#d7ccc8', attention: '#ef6c00', ok: '#42a5f5', inactive: '#bdbdbd' }
const picked = ref<any>(null)
const layers = ref({ field: true, location: true, machine: true })

// ── imagery ──
const imageryFields: FormField[] = [
  { key: 'field_id', label: 'Field', type: 'select', required: true, items: props.options.fields },
  { key: 'captured_on', label: 'Captured', type: 'date', required: true, cols: 6 }, { key: 'source', label: 'Source', required: true, cols: 6, hint: 'Sentinel-2, drone survey…' },
  { key: 'index', label: 'Index', type: 'select', required: true, items: props.options.indices.map(i => ({ title: i.toUpperCase(), value: i })), cols: 4 },
  { key: 'mean', label: 'Field mean', type: 'number', required: true, cols: 4 }, { key: 'cloud_pct', label: 'Cloud %', type: 'number', cols: 4 },
  { key: 'image', label: 'Rendered map (image)', type: 'file', cols: 12 },
]
const showImport = ref(false)
const check = (f: any, outcome: string) => { const note = prompt(outcome === 'confirmed' ? 'What was found?' : 'What did the field show?'); router.post(route('agri.twin.flags.validate', f.uuid), { outcome, note }, keep) }

// ── simulator ──
const sim = ref<any>(null)
const newSim = () => {
  sim.value = { name: '', field_id: null, crop_type_id: props.options.crop_types[0]?.value,
    base: { hectares: 10, planting_date: today(), variety_id: null, yield_per_ha: null, yield_unit: 't', price: null, inputs_per_ha: null, labour_hours_per_ha: 0, labour_rate: 0,
      water_required_m3_per_ha: 0, water_available_m3_per_ha: null, water_cost_per_m3: 0, other_per_ha: 0, payment_days: 30, days_to_maturity: 120 },
    scenarios: [{ name: 'Plant a month later', changes: { planting_date: '' } }] }
}
const addChange = (s: any) => { const k = prompt(`Change which? (${props.options.changes.join(', ')})`); if (k && props.options.changes.includes(k)) s.changes[k] = '' }
const runSim = () => router.post(route('agri.twin.simulations.store'), { farm_id: props.farm?.id, ...sim.value,
  scenarios: sim.value.scenarios.map((s: any) => ({ name: s.name, changes: Object.fromEntries(Object.entries(s.changes).filter(([, v]) => v !== '' && v !== null)) })) },
  { ...keep, onSuccess: () => { sim.value = null } })
const selectedSim = computed(() => { const q = new URLSearchParams((usePage().url.split('?')[1] ?? '')).get('sim'); return (props.data.simulations ?? []).find((s: any) => s.uuid === q) ?? props.data.simulations?.[0] })
const adopt = (s: any, scenario: string) => { const season = props.options.seasons[0]?.value; if (!season) return alert('Open a season first.')
  if (confirm(`Draft a crop plan from "${scenario}"?`)) router.post(route('agri.twin.simulations.adopt', s.uuid), { scenario, season_id: season }) }
const ROWS = [['yield_per_ha', 'Yield / ha'], ['revenue', 'Revenue'], ['costs', 'Costs'], ['margin', 'Margin'], ['margin_per_ha', 'Margin / ha'], ['breakeven_yield_per_ha', 'Break-even yield / ha'],
  ['breakeven_price', 'Break-even price'], ['peak_funding', 'Peak funding needed'], ['margin_poor_year', 'Margin, poor year'], ['margin_good_year', 'Margin, good year'], ['harvest', 'Harvest']]
const MONEY = ['revenue', 'costs', 'margin', 'margin_per_ha', 'breakeven_price', 'peak_funding', 'margin_poor_year', 'margin_good_year']
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div><h4 class="text-h4 mb-1">Farm map &amp; planner</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">The farm as it stands, drawn from its own records; and plans tried before they are approved.</p></div>
      <FarmFilter :farms="farms" :model-value="farm?.id" :allow-all="false" />
    </div>
    <div class="d-flex flex-wrap gap-2 mb-5">
      <VChip v-for="t in TABS" :key="t.key" :color="tab === t.key ? 'primary' : undefined" :variant="tab === t.key ? 'flat' : 'tonal'" :prepend-icon="t.icon" @click="go(t.key)">{{ t.title }}</VChip>
    </div>

    <template v-if="tab === 'map'">
      <div class="d-flex flex-wrap gap-2 mb-3 align-center">
        <VCheckbox v-for="(v, k) in layers" :key="k" v-model="layers[k]" :label="human(String(k)) + 's'" density="compact" hide-details />
        <VSpacer /><span v-if="data.weather" class="text-body-2">{{ num(data.weather.temperature) }}°C · wind {{ num(data.weather.wind) }} km/h · {{ data.weather.at }} UTC</span>
        <VChip v-if="data.alerts?.critical" color="error" size="small">{{ data.alerts.critical }} critical alert(s)</VChip>
      </div>
      <VRow>
        <VCol cols="12" md="8"><VCard>
          <svg v-if="view" :viewBox="`0 0 ${W} ${H}`" style="inline-size: 100%; block-size: auto; background: #f4f1ea">
            <template v-for="(f, i) in data.features" :key="i">
              <g v-if="layers[f.properties.layer as keyof typeof layers]" style="cursor: pointer" @click="picked = f.properties">
                <path v-if="f.geometry.type !== 'Point'" :d="path(f.geometry)" :fill="FILL[f.properties.state] ?? '#90a4ae'" fill-opacity="0.55" stroke="#455a64" stroke-width="1.5" />
                <circle v-else :cx="centre(f.geometry)[0]" :cy="centre(f.geometry)[1]" :r="f.properties.layer === 'machine' ? 7 : 6"
                  :fill="FILL[f.properties.state] ?? '#90a4ae'" stroke="#263238" stroke-width="1.5" />
                <text :x="centre(f.geometry)[0]" :y="centre(f.geometry)[1] - 10" text-anchor="middle" font-size="12" fill="#263238">{{ f.properties.label }}</text>
              </g>
            </template>
          </svg>
          <VCardText v-else class="text-medium-emphasis">Nothing on this farm has coordinates yet. Add field boundaries or points, and locations, to see the map.</VCardText>
          <VCardText class="d-flex flex-wrap gap-3 text-caption"><span v-for="(c, s) in FILL" :key="s"><span :style="`display:inline-block;inline-size:10px;block-size:10px;background:${c}`" /> {{ human(String(s)) }}</span></VCardText>
        </VCard></VCol>
        <VCol cols="12" md="4">
          <VCard v-if="picked" :title="picked.label" :subtitle="human(picked.layer)" class="mb-4"><VCardText>
            <div v-for="(v, k) in picked" :key="k" class="text-body-2"><template v-if="!['layer', 'id', 'label', 'link', 'state'].includes(String(k)) && v !== null && !(Array.isArray(v) && !v.length)">
              <span class="text-medium-emphasis">{{ human(String(k)) }}:</span> {{ Array.isArray(v) ? v.join(', ') : v }}</template></div>
            <VBtn v-if="picked.link" size="small" variant="tonal" class="mt-2" :href="picked.link">Open</VBtn></VCardText></VCard>
          <VCard v-if="data.unplaced?.length" title="Not on the map" subtitle="No coordinates recorded"><VList density="compact">
            <VListItem v-for="u in data.unplaced" :key="u.id" :title="u.label" :subtitle="human(u.layer)" @click="picked = u" /></VList></VCard>
          <VCard v-if="data.zones?.length" title="Irrigation zones" class="mt-4"><VList density="compact"><VListItem v-for="z in data.zones" :key="z.code" :title="`${z.code} ${z.name}`" :subtitle="`${human(z.system ?? '')} · ${z.field ?? ''}`" /></VList></VCard>
        </VCol>
      </VRow>
    </template>

    <template v-if="tab === 'imagery'">
      <VAlert type="info" variant="tonal" class="mb-3">Imagery shows where to look, not what is wrong. Every flag opens a scouting check, and is closed as confirmed or a false alarm. {{ data.track?.label }}</VAlert>
      <div class="d-flex gap-3 mb-3 align-center"><VBtn v-if="can.record" color="primary" prepend-icon="tabler-upload" @click="showImport = true">Record a capture</VBtn>
        <span class="text-body-2">{{ data.track?.checked }} of {{ data.track?.flags }} flags checked<template v-if="data.track?.precision_pct !== null"> · {{ data.track?.precision_pct }}% confirmed</template></span></div>
      <VCard title="Flags" class="mb-4"><VTable density="compact"><tbody>
        <tr v-for="f in data.flags" :key="f.id"><td>{{ f.reason }}</td><td><VChip size="x-small" :color="f.validation === 'confirmed' ? 'error' : f.validation === 'false_alarm' ? 'success' : 'warning'">{{ human(f.validation) }}</VChip>
          <div v-if="f.validation_note" class="text-caption">{{ f.validation_note }}</div></td>
          <td class="text-end text-no-wrap"><template v-if="can.record && f.validation === 'pending'"><VBtn size="x-small" color="error" variant="tonal" class="me-1" @click="check(f, 'confirmed')">Confirmed</VBtn>
            <VBtn size="x-small" variant="text" @click="check(f, 'false_alarm')">False alarm</VBtn></template></td></tr>
        <tr v-if="!data.flags?.length"><td class="text-center text-medium-emphasis py-4">No flags.</td></tr></tbody></VTable></VCard>
      <VCard title="Captures"><VTable density="compact"><tbody>
        <tr v-for="c in data.captures" :key="c.id"><td>{{ String(c.captured_on).slice(0, 10) }}</td><td>{{ c.field }}</td><td>{{ c.index.toUpperCase() }} {{ c.mean }}</td>
          <td :class="(c.change_pct ?? 0) <= -15 ? 'text-error' : ''">{{ c.change_pct !== null ? `${c.change_pct}%` : '—' }}</td><td class="text-caption">{{ c.source }}<template v-if="c.cloud_pct"> · cloud {{ c.cloud_pct }}%</template></td></tr>
        <tr v-if="!data.captures?.length"><td class="text-center text-medium-emphasis py-4">No captures.</td></tr></tbody></VTable></VCard>
      <FormDialog v-if="showImport" :model-value="true" title="Imagery capture" :url="route('agri.twin.imagery.store')" :fields="imageryFields"
        :values="{ farm_id: farm?.id, captured_on: today(), index: 'ndvi' }" @update:model-value="(v: boolean) => { if (!v) showImport = false }" />
    </template>

    <template v-if="tab === 'simulator'">
      <div class="d-flex gap-2 mb-3"><VBtn v-if="can.plan" color="primary" prepend-icon="tabler-plus" @click="newSim">New simulation</VBtn>
        <VSelect v-if="data.simulations?.length" :model-value="selectedSim?.uuid" :items="data.simulations.map((s: any) => ({ title: s.name, value: s.uuid }))" density="compact" hide-details
          style="max-inline-size: 320px" @update:model-value="(u: string) => router.get(route('agri.twin', 'simulator'), { farm: farm?.id, sim: u }, { preserveState: false })" /></div>
      <VCard v-if="selectedSim?.results" :title="selectedSim.name" subtitle="Estimates for comparing choices, not a forecast of the season.">
        <VTable density="compact">
          <thead><tr><th /><th v-for="r in selectedSim.results.scenarios" :key="r.name" class="text-end">{{ r.name }}<div class="text-caption">{{ Object.entries(r.changes).map(([k, v]) => `${human(k)} ${v}`).join(', ') }}</div></th></tr></thead>
          <tbody>
            <tr v-for="[k, label] in ROWS" :key="k"><td>{{ label }}</td><td v-for="r in selectedSim.results.scenarios" :key="r.name" class="text-end"
              :class="k.startsWith('margin') && r[k] < 0 ? 'text-error' : ''">{{ r[k] === null ? '—' : MONEY.includes(k) ? money(r[k]) : (typeof r[k] === 'number' ? num(r[k]) : r[k]) }}</td></tr>
            <tr v-if="can.plan && selectedSim.field_id"><td /><td v-for="r in selectedSim.results.scenarios" :key="r.name" class="text-end">
              <VBtn v-if="!selectedSim.crop_plan_id" size="x-small" variant="tonal" @click="adopt(selectedSim, r.name)">Draft a crop plan</VBtn>
              <VChip v-else-if="selectedSim.chosen === r.name" size="x-small" color="success">Chosen</VChip></td></tr>
          </tbody>
        </VTable>
        <VCardText><div v-for="r in selectedSim.results.scenarios" :key="r.name" class="text-caption mb-1"><strong>{{ r.name }}:</strong> {{ r.assumptions.join(' ') }}</div>
          <div class="text-caption text-medium-emphasis">History used: {{ selectedSim.results.history.n }} completed crop(s) of this kind on this farm.</div></VCardText>
      </VCard>
      <VAlert v-else type="info" variant="tonal">No simulations yet.</VAlert>
    </template>

    <VDialog :model-value="!!sim" max-width="980" @update:model-value="v => { if (!v) sim = null }">
      <VCard v-if="sim" title="Decision simulator">
        <VCardText>
          <VRow dense>
            <VCol cols="5"><VTextField v-model="sim.name" label="Name *" /></VCol>
            <VCol cols="4"><VSelect v-model="sim.crop_type_id" :items="options.crop_types" label="Crop *" /></VCol>
            <VCol cols="3"><VSelect v-model="sim.field_id" :items="options.fields" label="Field (to draft a plan)" clearable /></VCol>
          </VRow>
          <div class="text-subtitle-2">Base plan</div>
          <VRow dense>
            <VCol cols="2"><VTextField v-model.number="sim.base.hectares" type="number" label="Hectares" density="compact" /></VCol>
            <VCol cols="3"><VTextField v-model="sim.base.planting_date" type="date" label="Planting" density="compact" /></VCol>
            <VCol cols="3"><VSelect v-model="sim.base.variety_id" :items="options.varieties.filter(v => v.crop_type_id === sim.crop_type_id).map(v => ({ title: `${v.name} (${v.days_to_maturity ?? '?'} d)`, value: v.id }))" label="Variety" clearable density="compact" /></VCol>
            <VCol cols="2"><VTextField v-model.number="sim.base.days_to_maturity" type="number" label="Days to harvest" density="compact" /></VCol>
            <VCol cols="2"><VTextField v-model="sim.base.yield_unit" label="Unit" density="compact" /></VCol>
            <VCol cols="3"><VTextField v-model.number="sim.base.yield_per_ha" type="number" label="Yield / ha *" density="compact" /></VCol>
            <VCol cols="3"><VTextField v-model.number="sim.base.price" type="number" label="Price / unit *" density="compact" /></VCol>
            <VCol cols="3"><VTextField v-model.number="sim.base.inputs_per_ha" type="number" label="Inputs / ha" hint="Blank: approved rates × stock cost" persistent-hint density="compact" /></VCol>
            <VCol cols="3"><VTextField v-model.number="sim.base.payment_days" type="number" label="Paid after (days)" density="compact" /></VCol>
            <VCol cols="3"><VTextField v-model.number="sim.base.labour_hours_per_ha" type="number" label="Labour h / ha" density="compact" /></VCol>
            <VCol cols="3"><VTextField v-model.number="sim.base.labour_rate" type="number" label="Labour rate / h" density="compact" /></VCol>
            <VCol cols="2"><VTextField v-model.number="sim.base.water_required_m3_per_ha" type="number" label="Water needed m³/ha" density="compact" /></VCol>
            <VCol cols="2"><VTextField v-model.number="sim.base.water_available_m3_per_ha" type="number" label="Water available" density="compact" /></VCol>
            <VCol cols="2"><VTextField v-model.number="sim.base.water_cost_per_m3" type="number" label="Water R/m³" density="compact" /></VCol>
          </VRow>
          <div class="text-subtitle-2 mt-2">Alternatives</div>
          <VCard v-for="(s, i) in sim.scenarios" :key="i" variant="outlined" class="mb-2"><VCardText>
            <div class="d-flex gap-2 align-center"><VTextField v-model="s.name" label="Name" density="compact" hide-details />
              <VBtn size="small" variant="text" prepend-icon="tabler-plus" @click="addChange(s)">Change</VBtn><VBtn icon="tabler-x" size="small" variant="text" @click="sim.scenarios.splice(i, 1)" /></div>
            <VRow dense class="mt-1"><VCol v-for="(v, k) in s.changes" :key="k" cols="4">
              <VTextField v-model="s.changes[k]" :type="String(k) === 'planting_date' ? 'date' : 'text'" :label="human(String(k))" density="compact" hide-details /></VCol></VRow>
          </VCardText></VCard>
          <VBtn size="small" variant="text" prepend-icon="tabler-plus" @click="sim.scenarios.push({ name: `Option ${sim.scenarios.length + 1}`, changes: {} })">Alternative</VBtn>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="sim = null">Cancel</VBtn><VBtn color="primary" :disabled="!sim.name || !sim.base.yield_per_ha || !sim.base.price" @click="runSim">Run</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
