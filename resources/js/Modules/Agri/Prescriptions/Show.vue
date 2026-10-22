<script setup lang="ts">
/**
 * One prescription: the map of its zones coloured by rate with a legend, the rate and quantity per
 * zone (editable while it is a draft), totals and cost against a flat rate, the approval (four
 * eyes), its machine files by version, sending it to John Deere Operations Center or CNH FieldOps,
 * planning the field work, and — once the machine has done it — as applied against prescribed.
 *
 * Leaflet is imported when the page opens, as on the field map.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import PageErrors from '../Components/PageErrors.vue'
import { money, num, statusColor } from '../Components/agri'

type Zone = { id: string; label: string; class: number | null; zone_class: string | null; ndvi: number | null; area_ha: number; rate: number; quantity: number; cost: number; boundary: any }
const props = defineProps<{
  prescription: Record<string, any>
  field: { id: string; code: string; name: string; farm: string | null; farm_id: string | null; boundary: any }
  zones: Zone[]
  summary: Record<string, number>
  files: Array<{ id: string; format: string; version: number; name: string; size: number; at: string | null }>
  connections: Array<{ provider: string; name: string; connected: boolean; status: string; format: string }>
  sent: Array<Record<string, any>>
  comparison: { operations: any[]; zones: any[]; applied_total: number | null; prescribed_total: number; difference_pct: number | null }
  stock_items: Array<{ id: number; name: string; uom: string }>
  options: { operations: string[]; units: string[]; methods: string[] }
  can: { plan: boolean; approve: boolean; drafter: boolean }
}>()

const { t } = useI18n()
const k = (key: string, params?: Record<string, unknown>) => t(`agri.prescriptions.${key}`, params ?? {})
const keep = { preserveScroll: true }
const p = computed(() => props.prescription)
const unit = computed(() => k(`unit.${p.value.unit}`))
const base = computed(() => k(`base_unit.${p.value.unit}`))
const editable = computed(() => props.can.plan && ['draft', 'submitted', 'approved'].includes(p.value.status))

// ── colours: light (least) to dark (most), zero grey ───────────────────────────────────────
const RAMP = ['#fff7bc', '#fee391', '#fec44f', '#fe9929', '#ec7014', '#cc4c02', '#8c2d04']
const rates = computed(() => [...new Set(props.zones.map(z => z.rate))].sort((a, b) => a - b))
const colour = (rate: number) => {
  if (rate <= 0) return '#9e9e9e'
  const positive = rates.value.filter(r => r > 0)
  if (positive.length <= 1) return RAMP[3]
  const min = positive[0], max = positive[positive.length - 1]
  return RAMP[Math.min(RAMP.length - 1, Math.round((rate - min) / (max - min) * (RAMP.length - 1)))]
}
const legend = computed(() => rates.value.map(r => ({ rate: r, colour: colour(r), area: props.zones.filter(z => z.rate === r).reduce((s, z) => s + z.area_ha, 0) })))

// ── the map ────────────────────────────────────────────────────────────────────────────────
const el = ref<HTMLElement | null>(null)
const loadFailed = ref(false)
let L: any = null
let lmap: any = null
let layer: any = null
onMounted(async () => {
  if (!el.value) return
  try {
    const mod: any = await import('leaflet')
    L = mod.default ?? mod
    await import('leaflet/dist/leaflet.css')
  }
  catch {
    loadFailed.value = true
    return
  }
  lmap = L.map(el.value, { maxZoom: 21, zoomSnap: 0.5 })
  L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
    maxZoom: 21, maxNativeZoom: 19, attribution: 'Imagery &copy; Esri, Maxar, Earthstar Geographics',
  }).addTo(lmap)
  L.control.scale({ imperial: false }).addTo(lmap)
  draw()
})
onBeforeUnmount(() => { lmap?.remove(); lmap = null })
watch(() => props.zones, () => draw())
const esc = (s: unknown) => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' } as Record<string, string>)[c])
function draw() {
  if (!lmap) return
  layer?.remove()
  layer = L.featureGroup().addTo(lmap)
  for (const z of props.zones) {
    L.geoJSON(z.boundary, { style: { color: '#212121', weight: 1, fillColor: colour(z.rate), fillOpacity: 0.75 } })
      .bindTooltip(`<b>${esc(z.label)}</b><br>${num(z.rate, 2)} ${esc(unit.value)} · ${num(z.area_ha, 2)} ha<br>${num(z.quantity, 1)} ${esc(base.value)}`, { sticky: true })
      .addTo(layer)
  }
  if (props.field.boundary)
    L.geoJSON(props.field.boundary, { interactive: false, style: { color: '#ffffff', weight: 3, fill: false } }).addTo(layer)
  const b = layer.getBounds()
  if (b.isValid()) lmap.fitBounds(b, { padding: [20, 20] })
}

// ── editing rates and settings ─────────────────────────────────────────────────────────────
const rateEdit = ref<Record<string, number>>({})
watch(() => props.zones, zs => { rateEdit.value = Object.fromEntries(zs.map(z => [z.id, z.rate])) }, { immediate: true })
const ratesChanged = computed(() => props.zones.some(z => Number(rateEdit.value[z.id]) !== z.rate))
const saveRates = () => router.patch(route('agri.prescriptions.update', p.value.id), { rates: rateEdit.value }, keep)
const settingsOpen = ref(false)
const s = ref<Record<string, any>>({})
const openSettings = () => {
  s.value = { base_rate: p.value.base_rate, step_pct: p.value.step_pct, flip: p.value.flip, classes: p.value.classes ?? 3, method: p.value.method ?? 'equal_interval',
    source: p.value.source, flat_rate: p.value.flat_rate, per_stock_unit: p.value.per_stock_unit, stock_item_id: p.value.stock_item_id, unit: p.value.unit, name: p.value.name, planned_on: p.value.planned_on }
  settingsOpen.value = true
}
const saveSettings = () => router.patch(route('agri.prescriptions.update', p.value.id), { ...s.value, regenerate: true }, { ...keep, onSuccess: () => { settingsOpen.value = false } })

// ── approval, files, sending, planning ─────────────────────────────────────────────────────
const act = (name: string, data: Record<string, any> = {}) => router.post(route(`agri.prescriptions.${name}`, p.value.id), data, keep)
const rejectNote = ref('')
const rejecting = ref(false)
const reject = () => router.post(route('agri.prescriptions.reject', p.value.id), { note: rejectNote.value }, { ...keep, onSuccess: () => { rejecting.value = false; rejectNote.value = '' } })
const plannedOn = ref<string | null>(p.value.planned_on)
const kb = (n: number) => (n < 1024 ? `${n} B` : `${num(n / 1024, 1)} kB`)
const currentFiles = computed(() => props.files.filter(f => f.version === p.value.version))
const olderFiles = computed(() => props.files.filter(f => f.version !== p.value.version))
const diffColour = (d: number | null) => (d === null ? undefined : Math.abs(d) <= 5 ? 'success' : Math.abs(d) <= 15 ? 'warning' : 'error')
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div>
        <div class="text-body-2 mb-1"><Link :href="route('agri.prescriptions.index')">{{ k('title') }}</Link></div>
        <h4 class="text-h4 mb-1">{{ p.code }} · {{ p.name }} <VChip size="small" :color="statusColor(p.status)" class="ms-2">{{ k(`status.${p.status}`) }}</VChip> <span class="text-body-2">v{{ p.version }}</span></h4>
        <p class="text-body-1 text-medium-emphasis mb-0">{{ field.code }} {{ field.name }} · {{ field.farm }} · {{ k(`operation.${p.operation}`) }} · {{ p.product }}</p>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <VBtn v-if="editable" variant="tonal" @click="openSettings">{{ k('settings') }}</VBtn>
        <VBtn v-if="can.plan && p.status === 'draft'" color="primary" @click="act('submit')">{{ k('submit') }}</VBtn>
        <template v-if="p.status === 'submitted' && can.approve">
          <VBtn color="success" @click="act('approve')">{{ k('approve') }}</VBtn>
          <VBtn color="error" variant="tonal" @click="rejecting = true">{{ k('reject') }}</VBtn>
        </template>
        <VBtn v-if="can.plan && !['applied', 'cancelled'].includes(p.status)" variant="text" color="error" @click="act('cancel')">{{ k('cancel_rx') }}</VBtn>
      </div>
    </div>

    <VAlert v-if="p.rejection_note" type="warning" variant="tonal" class="mb-4">{{ k('sent_back', { note: p.rejection_note }) }}</VAlert>
    <VAlert v-if="p.status === 'submitted' && can.drafter" type="info" variant="tonal" class="mb-4">{{ k('four_eyes') }}</VAlert>
    <VAlert v-if="p.status === 'draft' && p.approved_version === null && p.version > 1" type="info" variant="tonal" class="mb-4">{{ k('new_version') }}</VAlert>

    <VRow>
      <VCol cols="12" md="8">
        <VCard>
          <div v-if="loadFailed" class="pa-6 text-medium-emphasis">{{ k('map_failed') }}</div>
          <div v-else ref="el" style="height: 460px" />
          <VCardText class="d-flex flex-wrap gap-3 align-center">
            <span class="text-caption">{{ k('legend', { unit }) }}</span>
            <span v-for="l in legend" :key="l.rate" class="d-inline-flex align-center gap-1 text-caption">
              <span :style="{ background: l.colour, width: '18px', height: '12px', display: 'inline-block', border: '1px solid #616161' }" />{{ num(l.rate, 2) }} ({{ num(l.area, 2) }} ha)
            </span>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="4">
        <VCard :title="k('totals')">
          <VCardText>
            <VTable density="compact"><tbody>
              <tr><td>{{ k('area') }}</td><td class="text-end">{{ num(summary.area_ha, 2) }} ha</td></tr>
              <tr><td>{{ k('treated') }}</td><td class="text-end">{{ num(summary.treated_ha, 2) }} ha</td></tr>
              <tr><td>{{ k('average_rate') }}</td><td class="text-end">{{ num(summary.average_rate, 2) }} {{ unit }}</td></tr>
              <tr><td>{{ k('quantity') }}</td><td class="text-end">{{ num(summary.quantity, 1) }} {{ base }}<div class="text-caption">{{ num(summary.stock_quantity, 2) }} {{ p.stock_uom }}</div></td></tr>
              <tr><td>{{ k('cost') }}</td><td class="text-end">{{ money(summary.cost) }}</td></tr>
              <tr><td colspan="2" class="text-caption pt-3">{{ k('flat_vs', { rate: num(summary.flat_rate, 2), unit }) }}</td></tr>
              <tr><td>{{ k('flat_quantity') }}</td><td class="text-end">{{ num(summary.flat_quantity, 1) }} {{ base }}</td></tr>
              <tr><td>{{ k('flat_cost') }}</td><td class="text-end">{{ money(summary.flat_cost) }}</td></tr>
              <tr><td><b>{{ summary.saving_cost >= 0 ? k('saving') : k('extra') }}</b></td><td class="text-end"><b>{{ money(Math.abs(summary.saving_cost)) }}</b><div class="text-caption">{{ num(Math.abs(summary.saving_quantity), 1) }} {{ base }}</div></td></tr>
            </tbody></VTable>
            <div class="text-caption mt-2">{{ k('basis', { source: k(`source.${p.source}`), classes: p.classes ?? '—', method: p.method ? k(`method.${p.method}`) : '—', base: num(p.base_rate, 2), step: num(p.step_pct, 1), direction: p.flip ? k('f.flip_on') : k('f.flip_off') }) }}
              <template v-if="p.imagery_on"> {{ k('ndvi_of', { d: p.imagery_on }) }}</template></div>
            <div class="text-caption mt-1">{{ k('approval_line', { by: p.drafted_by ?? '—', approver: p.approved_by ?? '—', at: p.approved_at ?? '—' }) }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard class="mt-4" :title="k('zones')">
      <VTable density="compact">
        <thead><tr><th>{{ k('zone') }}</th><th>{{ k('class') }}</th><th class="text-end">NDVI</th><th class="text-end">{{ k('area') }}</th><th class="text-end" style="width: 160px">{{ k('rate', { unit }) }}</th><th class="text-end">{{ k('quantity') }}</th><th class="text-end">{{ k('cost') }}</th></tr></thead>
        <tbody>
          <tr v-for="z in zones" :key="z.id">
            <td><span :style="{ background: colour(z.rate), width: '12px', height: '12px', display: 'inline-block', border: '1px solid #616161' }" class="me-2" />{{ z.label }}</td>
            <td>{{ z.class === null ? k('excluded') : k('class_n', { n: z.class }) }}<span v-if="z.zone_class" class="text-caption"> · {{ z.zone_class }}</span></td>
            <td class="text-end">{{ z.ndvi === null ? '—' : num(z.ndvi, 3) }}</td>
            <td class="text-end">{{ num(z.area_ha, 2) }}</td>
            <td class="text-end"><VTextField v-if="editable" v-model.number="rateEdit[z.id]" type="number" density="compact" hide-details min="0" /><template v-else>{{ num(z.rate, 2) }}</template></td>
            <td class="text-end">{{ num(z.quantity, 1) }}</td>
            <td class="text-end">{{ money(z.cost) }}</td>
          </tr>
        </tbody>
      </VTable>
      <VCardActions v-if="editable && ratesChanged"><VSpacer /><span class="text-caption me-2">{{ k('rates_note') }}</span><VBtn color="primary" variant="flat" @click="saveRates">{{ k('save_rates') }}</VBtn></VCardActions>
    </VCard>

    <VRow class="mt-1">
      <VCol cols="12" md="6">
        <VCard :title="k('files')" :subtitle="k('files_intro')">
          <VCardText>
            <div class="d-flex flex-wrap gap-2 mb-3">
              <template v-for="fmt in ['shapefile', 'isoxml', 'geojson']" :key="fmt">
                <VBtn v-if="currentFiles.find(f => f.format === fmt)" size="small" prepend-icon="tabler-download" variant="tonal" color="primary"
                  :href="route('agri.prescriptions.files.download', [p.id, currentFiles.find(f => f.format === fmt)!.id])">{{ k(`format.${fmt}`) }}</VBtn>
                <VBtn v-else-if="can.plan" size="small" variant="outlined" @click="act('export', { format: fmt })">{{ k('make', { format: k(`format.${fmt}`) }) }}</VBtn>
              </template>
            </div>
            <div v-for="f in currentFiles" :key="f.id" class="text-caption">{{ f.name }} · {{ kb(f.size) }} · {{ f.at }}</div>
            <div v-if="olderFiles.length" class="text-caption mt-2 text-medium-emphasis">{{ k('older') }}
              <a v-for="f in olderFiles" :key="f.id" :href="route('agri.prescriptions.files.download', [p.id, f.id])" class="ms-2">v{{ f.version }} {{ k(`format.${f.format}`) }}</a></div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" md="6">
        <VCard :title="k('machines')" :subtitle="k('machines_intro')">
          <VCardText>
            <div v-for="c in connections" :key="c.provider" class="d-flex align-center gap-2 mb-2">
              <div class="flex-grow-1">{{ c.name }}<div class="text-caption">{{ c.connected ? k('sends_as', { format: k(`format.${c.format}`) }) : k('not_connected') }}</div></div>
              <VBtn size="small" color="primary" variant="tonal" :disabled="!c.connected || !p.approved || !can.plan" @click="act('send', { provider: c.provider })">{{ k('send') }}</VBtn>
            </div>
            <div v-if="!p.approved" class="text-caption text-warning">{{ k('send_needs_approval') }}</div>
            <VTable v-if="sent.length" density="compact" class="mt-2"><tbody>
              <tr v-for="x in sent" :key="x.id"><td>{{ connections.find(c => c.provider === x.provider)?.name }} v{{ x.version }}</td>
                <td><VChip size="x-small" :color="x.status === 'sent' ? 'success' : x.status === 'failed' ? 'error' : 'warning'">{{ k(`send_status.${x.status}`) }}</VChip></td>
                <td class="text-caption" style="word-break: break-all">{{ x.external_id ?? '—' }}<div v-if="x.remote_status">{{ x.remote_status }}</div><div v-if="x.error" class="text-error">{{ x.error }}</div></td></tr>
            </tbody></VTable>
            <VDivider class="my-3" />
            <div class="text-subtitle-2 mb-1">{{ k('work') }}</div>
            <div v-if="p.activity" class="text-body-2">{{ p.activity.title }} · {{ p.activity.planned_on }} <VChip size="x-small" :color="statusColor(p.activity.status)">{{ p.activity.status }}</VChip></div>
            <div v-else-if="can.plan" class="d-flex gap-2 align-center">
              <VTextField v-model="plannedOn" type="date" :label="k('f.planned_on')" density="compact" hide-details style="max-width: 180px" />
              <VBtn size="small" variant="tonal" :disabled="!p.approved" @click="act('plan', { planned_on: plannedOn })">{{ k('plan_work') }}</VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard class="mt-4" :title="k('compare')" :subtitle="k('compare_intro')">
      <VCardText v-if="!comparison.operations.length" class="text-medium-emphasis">{{ k('compare_none') }}</VCardText>
      <template v-else>
        <VCardText class="text-body-2">{{ k('compare_total', { applied: num(comparison.applied_total, 1), prescribed: num(comparison.prescribed_total, 1), unit: base }) }}
          <VChip v-if="comparison.difference_pct !== null" size="x-small" :color="diffColour(comparison.difference_pct)">{{ comparison.difference_pct > 0 ? '+' : '' }}{{ num(comparison.difference_pct, 1) }}%</VChip></VCardText>
        <VTable density="compact">
          <thead><tr><th>{{ k('zone') }}</th><th class="text-end">{{ k('prescribed') }}</th><th class="text-end">{{ k('applied') }}</th><th class="text-end">{{ k('difference') }}</th><th>{{ k('basis_label') }}</th></tr></thead>
          <tbody><tr v-for="z in comparison.zones" :key="z.id"><td>{{ z.label }}</td><td class="text-end">{{ num(z.prescribed, 2) }}</td><td class="text-end">{{ num(z.applied, 2) }}</td>
            <td class="text-end"><VChip v-if="z.difference_pct !== null" size="x-small" :color="diffColour(z.difference_pct)">{{ z.difference_pct > 0 ? '+' : '' }}{{ num(z.difference_pct, 1) }}%</VChip></td>
            <td class="text-caption">{{ z.basis === 'samples' ? k('basis_samples', { n: z.samples }) : k('basis_average') }}</td></tr></tbody>
        </VTable>
      </template>
    </VCard>

    <VDialog v-model="rejecting" max-width="480">
      <VCard :title="k('reject')"><VCardText><VTextarea v-model="rejectNote" :label="k('reject_note')" rows="3" /></VCardText>
        <VCardActions><VSpacer /><VBtn @click="rejecting = false">{{ k('cancel') }}</VBtn><VBtn color="error" variant="flat" :disabled="!rejectNote.trim()" @click="reject">{{ k('reject') }}</VBtn></VCardActions></VCard>
    </VDialog>

    <VDialog v-model="settingsOpen" max-width="640">
      <VCard :title="k('settings')" :subtitle="k('settings_intro')">
        <VCardText><VRow dense>
          <VCol cols="12" md="6"><VTextField v-model="s.name" :label="k('f.name')" density="compact" /></VCol>
          <VCol cols="12" md="6"><VAutocomplete v-model="s.stock_item_id" :items="stock_items.map(i => ({ value: i.id, title: i.name }))" :label="k('f.product')" density="compact" /></VCol>
          <VCol cols="6" md="4"><VSelect v-model="s.unit" :items="options.units.map(u => ({ value: u, title: k(`unit.${u}`) }))" :label="k('f.unit')" density="compact" /></VCol>
          <VCol cols="6" md="4"><VTextField v-model.number="s.per_stock_unit" type="number" :label="k('f.per_stock_unit', { uom: p.stock_uom ?? '' })" density="compact" /></VCol>
          <VCol cols="12" md="4"><VSelect v-model="s.source" :items="['zones', 'ndvi'].map(x => ({ value: x, title: k(`source.${x}`) }))" :label="k('f.source')" density="compact" /></VCol>
          <template v-if="s.source === 'ndvi'">
            <VCol cols="6"><VSelect v-model="s.classes" :items="[2, 3, 4, 5, 6, 7]" :label="k('f.classes')" density="compact" /></VCol>
            <VCol cols="6"><VSelect v-model="s.method" :items="options.methods.map(m => ({ value: m, title: k(`method.${m}`) }))" :label="k('f.method')" density="compact" /></VCol>
          </template>
          <VCol cols="6" md="4"><VTextField v-model.number="s.base_rate" type="number" :label="k('f.base_rate')" density="compact" /></VCol>
          <VCol cols="6" md="4"><VTextField v-model.number="s.step_pct" type="number" :label="k('f.step_pct')" suffix="%" density="compact" /></VCol>
          <VCol cols="12" md="4"><VTextField v-model.number="s.flat_rate" type="number" :label="k('f.flat_rate')" density="compact" /></VCol>
          <VCol cols="12"><VSwitch v-model="s.flip" :label="s.flip ? k('f.flip_on') : k('f.flip_off')" color="primary" density="compact" hide-details inset /></VCol>
        </VRow></VCardText>
        <VCardActions><VSpacer /><VBtn @click="settingsOpen = false">{{ k('cancel') }}</VBtn><VBtn color="primary" variant="flat" @click="saveSettings">{{ k('rebuild') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
