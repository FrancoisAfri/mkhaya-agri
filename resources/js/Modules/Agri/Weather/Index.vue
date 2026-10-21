<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import PageErrors from '../Components/PageErrors.vue'
import FormDialog, { type FormField } from '../Components/FormDialog.vue'
import FarmFilter from '../Components/FarmFilter.vue'
import { human, num, today } from '../Components/agri'

/** Phase 2 §11: weather with its source, approved weather rules, environmental records, sustainability. */
const props = defineProps<{
  tab: 'weather' | 'rules' | 'environment' | 'sustainability'
  from: string
  to: string
  farm: null | { id: string; name: string; latitude: number | null; longitude: number | null }
  farms: Array<{ id: string; name: string }>
  provider: null | { key: string; name: string }
  data: any
  options: { activities: string[]; metrics: string[]; kinds: string[]; crop_types: any[]; species: any[]; chemicals: any[] }
  can: { rules: boolean; record: boolean; refresh: boolean }
}>()
const TABS = [
  { key: 'weather', title: 'Weather', icon: 'tabler-cloud' }, { key: 'rules', title: 'Weather rules', icon: 'tabler-adjustments-alt' },
  { key: 'environment', title: 'Environmental records', icon: 'tabler-leaf' }, { key: 'sustainability', title: 'Sustainability', icon: 'tabler-plant-2' },
]
const keep = { preserveScroll: true }
const period = ref({ from: props.from, to: props.to })
const go = (tab: string) => router.get(route('agri.weather', tab), { farm: props.farm?.id, ...period.value }, { preserveState: false })
const refresh = (history = false) => router.post(route('agri.weather.refresh'), { farm_id: props.farm?.id, history }, keep)
const dialog = ref<null | { kind: string; row?: any }>(null)
const close = (v: boolean) => { if (!v) dialog.value = null }
const METRIC: Record<string, string> = { wind_speed: 'Wind (km/h)', wind_gust: 'Gusts (km/h)', rain_mm: 'Rain in an hour (mm)', rain_total: 'Rain over the window (mm)',
  rain_probability: 'Chance of rain (%)', temperature: 'Temperature (°C)', temp_min: 'Lowest temperature (°C)', temp_max: 'Highest temperature (°C)', humidity: 'Humidity (%)' }

// rules
const rule = ref<any>(null)
const newRule = (r: any = null) => {
  rule.value = r ? { ...JSON.parse(JSON.stringify(r)), farm_only: !!r.farm_id } : { name: '', activity: 'spraying', crop_type_id: null, stock_item_id: null, species_id: null, farm_only: false,
    conditions: [{ metric: 'wind_gust', op: '>', value: null }], lookahead_hours: 48, advice: '', basis: '', severity: 'warning' }
}
const saveRule = () => router.post(rule.value.uuid ? route('agri.weather.rules.update', rule.value.uuid) : route('agri.weather.rules.store'),
  { ...rule.value, farm_id: rule.value.farm_only ? props.farm?.id : null }, { ...keep, onSuccess: () => { rule.value = null } })
const approve = (r: any) => router.post(route('agri.weather.rules.approve', r.uuid), {}, keep)
const retire = (r: any) => { if (confirm(`Retire "${r.name}"?`)) router.post(route('agri.weather.rules.retire', r.uuid), {}, keep) }
const forWhat = (r: any) => [r.crop_type_id && props.options.crop_types.find(c => c.value === r.crop_type_id)?.title,
  r.stock_item_id && props.options.chemicals.find(c => c.value === r.stock_item_id)?.title, r.species_id && props.options.species.find(s => s.value === r.species_id)?.title].filter(Boolean).join(', ') || 'All'

// environment
const recordFields: FormField[] = [
  { key: 'kind', label: 'Kind', type: 'select', required: true, items: props.options.kinds.map(k => ({ title: human(k), value: k })), cols: 6 },
  { key: 'recorded_on', label: 'Date', type: 'date', required: true, cols: 6 },
  { key: 'quantity', label: 'Quantity', type: 'number', cols: 4 }, { key: 'unit', label: 'Unit', cols: 4, hint: 'm3, kWh, l, ha, containers…' }, { key: 'method', label: 'How measured', cols: 4 },
  { key: 'description', label: 'Description', type: 'textarea', required: true, cols: 12 },
  { key: 'verification', label: 'Verification', type: 'select', items: [{ title: 'Unverified', value: 'unverified' }, { title: 'Verified (methodology, verifier and evidence)', value: 'verified' }],
    cols: 12, show: (f: any) => f.kind === 'carbon_project' },
  { key: 'methodology', label: 'Methodology', cols: 6, show: (f: any) => f.kind === 'carbon_project' }, { key: 'verifier', label: 'Verifier', cols: 6, show: (f: any) => f.kind === 'carbon_project' },
  { key: 'evidence', label: 'Evidence', type: 'file', cols: 12 },
]
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div>
        <div class="text-body-2 mb-1"><Link :href="route('agri.hub.production')">Farms &amp; Production</Link> / {{ farm?.name }}</div>
        <h4 class="text-h4 mb-1">Weather &amp; environment</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">Weather from {{ provider?.name ?? 'no provider' }}; alerts only from thresholds the farm has approved.</p>
      </div>
      <div class="d-flex flex-wrap gap-2 align-center">
        <template v-if="['environment', 'sustainability'].includes(tab)">
          <VTextField v-model="period.from" type="date" label="From" density="compact" hide-details style="max-inline-size: 160px" />
          <VTextField v-model="period.to" type="date" label="To" density="compact" hide-details style="max-inline-size: 160px" />
          <VBtn variant="tonal" @click="go(tab)">Apply</VBtn>
        </template>
        <FarmFilter :farms="farms" :model-value="farm?.id" :allow-all="false" />
      </div>
    </div>
    <div class="d-flex flex-wrap gap-2 mb-5">
      <VChip v-for="t in TABS" :key="t.key" :color="tab === t.key ? 'primary' : undefined" :variant="tab === t.key ? 'flat' : 'tonal'" :prepend-icon="t.icon" @click="go(t.key)">{{ t.title }}</VChip>
    </div>

    <template v-if="tab === 'weather'">
      <VAlert v-if="!data.located" type="info" variant="tonal" class="mb-4">Set {{ farm?.name }}'s latitude and longitude on the farm record to fetch its weather.</VAlert>
      <div v-else class="d-flex flex-wrap gap-2 align-center mb-3">
        <span class="text-body-2 text-medium-emphasis">Source: {{ data.source ?? '—' }} · fetched {{ data.fetched_at ?? 'never' }}<template v-if="data.period"> · covers {{ data.period[0] }} – {{ data.period[1] }} (UTC)</template></span>
        <VSpacer /><VBtn v-if="can.refresh && provider" size="small" variant="tonal" prepend-icon="tabler-refresh" @click="refresh(false)">Fetch now</VBtn>
        <VBtn v-if="can.refresh && provider" size="small" variant="text" @click="refresh(true)">With last 30 days observed</VBtn>
      </div>
      <VAlert v-for="(w, i) in data.warnings ?? []" :key="i" :type="w.severity === 'warning' ? 'error' : 'warning'" variant="tonal" class="mb-2">
        {{ human(w.kind) }} {{ w.severity }}: {{ w.message }} <span class="text-caption">({{ w.provider }}, {{ w.starts_at }}{{ w.ends_at ? ' – ' + w.ends_at : '' }})</span></VAlert>
      <VCard v-if="data.alerts?.length" title="Weather alerts" class="mb-4">
        <VList density="compact" lines="two"><VListItem v-for="a in data.alerts" :key="a.uuid" :title="a.title" :subtitle="a.message" :prepend-icon="a.severity === 'critical' ? 'tabler-alert-octagon' : 'tabler-alert-triangle'">
          <template #append><a v-if="a.link" :href="a.link" class="text-caption">Open</a></template></VListItem></VList>
      </VCard>
      <VRow v-if="data.current" class="mb-1">
        <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">Now ({{ data.current.at }} UTC)</div><div class="text-h5">{{ num(data.current.temperature) }}°C</div></VCardText></VCard></VCol>
        <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">Humidity</div><div class="text-h5">{{ num(data.current.humidity) }}%</div></VCardText></VCard></VCol>
        <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">Wind / gusts</div><div class="text-h5">{{ num(data.current.wind_speed) }} / {{ num(data.current.wind_gust) }} km/h</div></VCardText></VCard></VCol>
        <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">Rain (last interval)</div><div class="text-h5">{{ num(data.current.rain_mm) }} mm</div></VCardText></VCard></VCol>
      </VRow>
      <VCard title="Next days" class="mb-4"><VTable density="compact">
        <thead><tr><th>Day</th><th class="text-end">Min °C</th><th class="text-end">Max °C</th><th class="text-end">Rain mm</th><th class="text-end">Chance %</th><th class="text-end">Wind max</th><th class="text-end">Gusts max</th></tr></thead>
        <tbody><tr v-for="d in data.days ?? []" :key="d.day"><td>{{ d.day }}</td><td class="text-end">{{ num(d.temp_min) }}</td><td class="text-end">{{ num(d.temp_max) }}</td><td class="text-end">{{ num(d.rain_mm) }}</td>
          <td class="text-end">{{ d.rain_probability ?? '—' }}</td><td class="text-end">{{ num(d.wind_max) }}</td><td class="text-end">{{ num(d.gust_max) }}</td></tr>
          <tr v-if="!data.days?.length"><td colspan="7" class="text-center text-medium-emphasis py-4">No forecast fetched yet.</td></tr></tbody>
      </VTable></VCard>
      <VCard v-if="data.observed?.length" title="Observed (daily)"><VTable density="compact"><tbody><tr v-for="o in data.observed" :key="o.day"><td>{{ o.day }}</td><td class="text-end">{{ num(o.temp_min) }}–{{ num(o.temp_max) }}°C</td><td class="text-end">{{ num(o.rain_mm) }} mm</td></tr></tbody></VTable></VCard>
    </template>

    <template v-if="tab === 'rules'">
      <VAlert type="info" variant="tonal" class="mb-3">There are no built-in thresholds. Each rule names the crop, chemical or species it is for and where its numbers come from, and counts only once someone else approves it.</VAlert>
      <div class="d-flex gap-2 mb-3"><VBtn v-if="can.rules" color="primary" prepend-icon="tabler-plus" @click="newRule()">Rule</VBtn></div>
      <VCard><VTable density="compact">
        <thead><tr><th>Rule</th><th>For</th><th>When</th><th>Basis</th><th>Status</th><th /></tr></thead>
        <tbody><tr v-for="r in data.rules" :key="r.id"><td>{{ r.name }}<div class="text-caption">{{ human(r.activity) }} · {{ r.farm_id ? 'this farm' : 'every farm' }} · {{ r.lookahead_hours }} h ahead</div></td>
          <td class="text-caption">{{ forWhat(r) }}</td>
          <td class="text-caption">{{ r.conditions.map((c: any) => `${METRIC[c.metric] ?? c.metric} ${c.op} ${c.value}`).join(' or ') }}<div>{{ r.advice }}</div></td>
          <td class="text-caption">{{ r.basis }}</td><td><VChip size="x-small" :color="r.status === 'approved' ? 'success' : undefined">{{ human(r.status) }}</VChip></td>
          <td class="text-end text-no-wrap"><template v-if="can.rules"><VBtn v-if="r.status === 'draft'" size="x-small" color="success" variant="tonal" @click="approve(r)">Approve</VBtn>
            <VBtn v-if="r.status !== 'retired'" size="x-small" variant="text" @click="newRule(r)">Edit</VBtn><VBtn v-if="r.status !== 'retired'" size="x-small" variant="text" color="error" @click="retire(r)">Retire</VBtn></template></td></tr>
          <tr v-if="!data.rules?.length"><td colspan="6" class="text-center text-medium-emphasis py-4">No rules: no weather alerts are raised.</td></tr></tbody>
      </VTable></VCard>
    </template>

    <template v-if="tab === 'environment'">
      <div class="d-flex gap-2 mb-3"><VBtn v-if="can.record" color="primary" prepend-icon="tabler-plus" @click="dialog = { kind: 'record' }">Record</VBtn></div>
      <VCard><VTable density="compact">
        <thead><tr><th>Date</th><th>Kind</th><th>What</th><th class="text-end">Quantity</th><th>Verification</th><th /></tr></thead>
        <tbody><tr v-for="r in data.records" :key="r.id"><td>{{ String(r.recorded_on).slice(0, 10) }}</td><td>{{ human(r.kind) }}</td><td>{{ r.description }}<div class="text-caption">{{ r.method }}</div></td>
          <td class="text-end">{{ r.quantity !== null ? num(r.quantity) + ' ' + (r.unit ?? '') : '' }}</td>
          <td><VChip v-if="r.kind === 'carbon_project'" size="x-small" :color="r.verification === 'verified' ? 'success' : 'warning'">{{ human(r.verification) }}</VChip>
            <div v-if="r.methodology" class="text-caption">{{ r.methodology }} · {{ r.verifier }}</div></td>
          <td class="text-end"><a v-if="r.evidence_name" :href="route('agri.environment.evidence', r.uuid)" class="text-caption">{{ r.evidence_name }}</a></td></tr>
          <tr v-if="!data.records?.length"><td colspan="6" class="text-center text-medium-emphasis py-4">Nothing recorded in this period.</td></tr></tbody>
      </VTable></VCard>
    </template>

    <template v-if="tab === 'sustainability'">
      <VRow class="mb-1">
        <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">Water</div><div class="text-h5">{{ num(data.water_m3) }} m³</div><div class="text-caption">{{ data.water_basis }}</div></VCardText></VCard></VCol>
        <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">Electricity</div><div class="text-h5">{{ num(data.energy_kwh) }} kWh</div></VCardText></VCard></VCol>
        <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">Fuel</div><div class="text-h5">{{ num(data.diesel_l) }} l diesel</div><div v-if="data.petrol_l" class="text-caption">{{ num(data.petrol_l) }} l petrol</div><div class="text-caption">{{ data.fuel_basis }}</div></VCardText></VCard></VCol>
        <VCol cols="6" md="3"><VCard><VCardText><div class="text-caption">Land rehabilitated</div><div class="text-h5">{{ num(data.rehabilitated_ha) }} ha</div></VCardText></VCard></VCol>
      </VRow>
      <VCard title="Estimated emissions" :subtitle="data.emissions?.label" class="mb-4"><VTable density="compact">
        <tbody><tr v-for="(l, i) in data.emissions?.lines ?? []" :key="i"><td>{{ l.what }}</td><td class="text-end">{{ l.kg_co2e !== null ? num(l.kg_co2e) + ' kg CO2e' : '—' }}</td><td class="text-caption">{{ l.factor }}</td><td class="text-caption">{{ l.source }}</td></tr>
          <tr><td class="font-weight-medium">Total (estimate)</td><td class="text-end font-weight-medium">{{ num(data.emissions?.total_kg_co2e) }} kg CO2e</td><td colspan="2" /></tr></tbody>
      </VTable></VCard>
      <VRow>
        <VCol cols="12" md="7"><VCard title="Fertiliser and pesticide applications"><VTable density="compact">
          <tbody><tr v-for="a in data.applications ?? []" :key="a.item"><td>{{ a.item }}<div class="text-caption">{{ human(a.category) }}<template v-if="a.active_ingredient"> · {{ a.active_ingredient }}</template></div></td>
            <td class="text-end">{{ num(a.quantity) }} {{ a.unit }}</td><td class="text-end">{{ a.applications }} application(s)</td></tr>
            <tr v-if="!data.applications?.length"><td class="text-center text-medium-emphasis py-4">No inputs issued to fields in this period.</td></tr></tbody></VTable></VCard></VCol>
        <VCol cols="12" md="5">
          <VCard title="Containers" class="mb-4"><VCardText>{{ data.containers?.count }} disposed, {{ data.containers?.triple_rinsed }} triple-rinsed ({{ data.containers?.records }} record(s)).</VCardText></VCard>
          <VCard title="Carbon" :subtitle="data.carbon?.label"><VCardText>
            <div v-for="(p, i) in data.carbon?.verified ?? []" :key="i" class="text-body-2 mb-1"><VIcon icon="tabler-rosette-discount-check" color="success" size="16" /> {{ p.description }}: {{ num(p.quantity) }} {{ p.unit }}
              <div class="text-caption">{{ p.methodology }} · {{ p.verifier }} · {{ p.evidence }}</div></div>
            <div v-if="!data.carbon?.verified?.length" class="text-body-2 text-medium-emphasis">No verified reductions.</div>
            <div v-if="data.carbon?.unverified" class="text-caption text-warning mt-1">{{ data.carbon.unverified }} unverified project(s), not counted.</div>
          </VCardText></VCard></VCol>
      </VRow>
    </template>

    <VDialog :model-value="!!rule" max-width="820" @update:model-value="v => { if (!v) rule = null }">
      <VCard v-if="rule" title="Weather rule">
        <VCardText><VRow dense>
          <VCol cols="8"><VTextField v-model="rule.name" label="Name *" /></VCol>
          <VCol cols="4"><VSelect v-model="rule.activity" :items="options.activities.map(a => ({ title: human(a), value: a }))" label="Protects" /></VCol>
          <VCol v-if="['frost', 'harvest_access'].includes(rule.activity)" cols="6"><VAutocomplete v-model="rule.crop_type_id" :items="options.crop_types" label="Crop (blank: any)" clearable /></VCol>
          <VCol v-if="rule.activity === 'spraying'" cols="6"><VAutocomplete v-model="rule.stock_item_id" :items="options.chemicals" label="Chemical (blank: any spraying)" clearable /></VCol>
          <VCol v-if="rule.activity === 'livestock_heat'" cols="6"><VSelect v-model="rule.species_id" :items="options.species" label="Species (blank: any)" clearable /></VCol>
          <VCol cols="3"><VTextField v-model.number="rule.lookahead_hours" type="number" label="Look ahead (h)" /></VCol>
          <VCol cols="3"><VSelect v-model="rule.severity" :items="['info', 'warning', 'critical'].map(x => ({ title: human(x), value: x }))" label="Severity" /></VCol>
        </VRow>
          <div class="text-subtitle-2">Raise the alert when any of these is forecast</div>
          <VRow v-for="(c, k) in rule.conditions" :key="k" dense class="align-center">
            <VCol cols="6"><VSelect v-model="c.metric" :items="options.metrics.map(m => ({ title: METRIC[m] ?? m, value: m }))" density="compact" hide-details /></VCol>
            <VCol cols="2"><VSelect v-model="c.op" :items="['>', '>=', '<', '<=']" density="compact" hide-details /></VCol>
            <VCol cols="3"><VTextField v-model.number="c.value" type="number" density="compact" hide-details /></VCol>
            <VCol cols="1"><VBtn icon="tabler-x" size="small" variant="text" @click="rule.conditions.splice(k, 1)" /></VCol>
          </VRow>
          <VBtn size="small" variant="text" prepend-icon="tabler-plus" @click="rule.conditions.push({ metric: 'temperature', op: '<', value: null })">Condition</VBtn>
          <VRow dense class="mt-2">
            <VCol cols="12"><VTextField v-model="rule.advice" label="What to do *" /></VCol>
            <VCol cols="12"><VTextField v-model="rule.basis" label="Where the threshold comes from *" hint="Product label, agronomist, farm procedure, regulation" persistent-hint /></VCol>
            <VCol cols="12"><VSwitch v-model="rule.farm_only" :label="`Only for ${farm?.name}`" density="compact" hide-details /></VCol>
          </VRow>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="rule = null">Cancel</VBtn><VBtn color="primary" @click="saveRule">Save as draft</VBtn></VCardActions>
      </VCard>
    </VDialog>
    <FormDialog v-if="dialog?.kind === 'record'" :model-value="true" title="Environmental record" :url="route('agri.environment.store')" :fields="recordFields"
      :values="{ farm_id: farm?.id, recorded_on: today(), kind: 'water', verification: 'unverified' }" @update:model-value="close" />
  </div>
</template>
