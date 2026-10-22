<script setup lang="ts">
/**
 * The field map editor: the farm boundary, fields, field parts, management zones, locations and
 * soil samples on an aerial basemap. People who may manage fields draw and edit boundaries, place
 * and move locations, draw zones and import boundary files; everybody else reads the map.
 *
 * Leaflet and its drawing plugin are imported when the page opens, so they never weigh on the
 * rest of the app. The basemap is Esri World Imagery (no access token needed), with OpenStreetMap
 * as the alternative.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Link, router, useForm, usePage } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import axios from 'axios'
import { useI18n } from 'vue-i18n'
import PageErrors from '../Components/PageErrors.vue'
import { human, num } from '../Components/agri'
import NdviChart, { type NdviSeries } from '../Satellite/NdviChart.vue'

type Geometry = { type: string; coordinates: any }
type Version = { id: string; version: number; source: string; source_name: string | null; area_ha: number | null; previous_area_ha: number | null; recorded_area_ha: number | null; reason: string | null; by: string | null; at: string | null; boundary: Geometry | null }
type Zone = { id: string; name: string; zone_class: string; soil_type: string | null; notes: string | null; area_ha: number; boundary: Geometry }
type Field = {
  id: string; code: string; name: string; land_use_type: string; status: string; soil_type: string | null; parent: string | null
  recorded_ha: number | null; mapped_ha: number | null; area_differs: boolean; outside_farm: boolean; overlaps: string[]
  latitude: number | null; longitude: number | null; boundary: Geometry | null; zones: Zone[]; history: Version[]
}
type Place = { id: string; name: string; location_type: string; status: string; notes: string | null; latitude: number | null; longitude: number | null }
type Soil = { id: string; field: string | null; sampled_on: string | null; latitude: number; longitude: number; placed_at_field: boolean; ph: number | null; organic_carbon_pct: number | null; p_mg_kg: number | null; k_mg_kg: number | null; ca_mg_kg: number | null; mg_mg_kg: number | null; laboratory: string | null; sample_ref: string | null; recommendation: string | null }
type MapData = {
  farm: { id: string; code: string; name: string; status: string; latitude: number | null; longitude: number | null; boundary: Geometry | null; mapped_ha: number | null; recorded_ha: number | null; history: Version[] }
  fields: Field[]; locations: Place[]; soil: Soil[]; limits: { min_ha: number; area_tolerance: number }
}
type Feature = { index: number; name: string | null; code: string | null; kind: string | null; geometry: Geometry; area_ha: number | null; problems: string[]; outside_farm: boolean; match: string | null; match_code: string | null; match_by: string | null; suggested: string }

const props = defineProps<{
  farm: null | { id: string; name: string }
  farms: Array<{ id: string; name: string }>
  map: MapData | null
  import: null | { id: string; file_name: string; format: string; crs: string | null; features: Feature[] }
  focus: string | null
  options: { land_use_types: string[]; location_types: string[]; zone_classes: string[]; area_units: string[] }
  can: { edit: boolean; edit_farm: boolean; zones: boolean }
}>()

const { t } = useI18n()
const k = (key: string, params?: Record<string, unknown>) => t(`agri.field_map.${key}`, params ?? {})
const page = usePage()
const warning = computed(() => (page.props as any).flash?.warning as string | null)
const keep = { preserveScroll: true, preserveState: true }
const ha = (v: number | null | undefined) => (v === null || v === undefined ? '—' : `${num(v, 2)} ha`)
const esc = (s: unknown) => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' } as Record<string, string>)[c])

const ZONE_COLOURS: Record<string, string> = { high: '#2e7d32', medium: '#f9a825', low: '#e65100', exclude: '#616161', other: '#5e35b1' }
const PH_BANDS = [
  { max: 5.0, colour: '#c62828', key: 'ph_strongly_acid' },
  { max: 5.5, colour: '#ef6c00', key: 'ph_acid' },
  { max: 6.0, colour: '#fdd835', key: 'ph_slightly_acid' },
  { max: 7.0, colour: '#43a047', key: 'ph_good' },
  { max: 7.5, colour: '#00897b', key: 'ph_neutral' },
  { max: 99, colour: '#1e88e5', key: 'ph_alkaline' },
]
const phColour = (ph: number | null) => (ph === null ? '#9e9e9e' : PH_BANDS.find(b => ph < b.max)!.colour)

// ── selection ───────────────────────────────────────────────────────────────
type Sel = { kind: 'field' | 'farm' | 'location'; id: string | null }
const sel = ref<Sel | null>(null)
const selField = computed(() => (sel.value?.kind === 'field' ? props.map?.fields.find(f => f.id === sel.value!.id) ?? null : null))
const selPlace = computed(() => (sel.value?.kind === 'location' ? props.map?.locations.find(l => l.id === sel.value!.id) ?? null : null))
const operationalFields = computed(() => (props.map?.fields ?? []).filter(f => f.status !== 'archived'))
const unmapped = computed(() => operationalFields.value.filter(f => !f.boundary))
const flagged = computed(() => operationalFields.value.filter(f => f.area_differs || f.outside_farm || f.overlaps.length))

// ── the map ─────────────────────────────────────────────────────────────────
const el = ref<HTMLElement | null>(null)
const loadFailed = ref(false)
let L: any = null
let lmap: any = null
const groups: Record<string, any> = {}
const fieldLayers: Record<string, any> = {}
const placeLayers: Record<string, any> = {}

onMounted(async () => {
  if (!props.map || !el.value) return
  try {
    const mod: any = await import('leaflet')
    L = mod.default ?? mod
    // The drawing plugin extends the global Leaflet object.
    ;(window as any).L = L
    await import('leaflet/dist/leaflet.css')
    await import('@geoman-io/leaflet-geoman-free')
    await import('@geoman-io/leaflet-geoman-free/dist/leaflet-geoman.css')
  }
  catch {
    loadFailed.value = true
    return
  }
  lmap = L.map(el.value, { maxZoom: 21, zoomSnap: 0.5 })
  const satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
    maxZoom: 21, maxNativeZoom: 19, attribution: 'Imagery &copy; Esri, Maxar, Earthstar Geographics',
  }).addTo(lmap)
  const streets = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 21, maxNativeZoom: 19, attribution: '&copy; OpenStreetMap contributors',
  })
  // NDVI pictures sit under the field outlines, so the outlines stay clickable.
  lmap.createPane('ndvi').style.zIndex = '350'
  for (const g of ['farm', 'fields', 'zones', 'locations', 'soil', 'history', 'preview', 'ndvi'])
    groups[g] = L.featureGroup().addTo(lmap)
  L.control.layers(
    { [k('basemap_satellite')]: satellite, [k('basemap_streets')]: streets },
    { [k('layer_ndvi')]: groups.ndvi, [k('layer_farm')]: groups.farm, [k('layer_fields')]: groups.fields, [k('layer_zones')]: groups.zones, [k('layer_locations')]: groups.locations, [k('layer_soil')]: groups.soil },
    { collapsed: true },
  ).addTo(lmap)
  L.control.scale({ imperial: false }).addTo(lmap)
  lmap.pm.setGlobalOptions({ allowSelfIntersection: false, snappable: true, snapDistance: 12 })
  lmap.on('pm:create', onCreated)
  lmap.on('click', onMapClick)
  draw()
  fitAll()
  drawPreview(true)
  if (props.focus)
    pick('field', props.focus, true)
  loadNdvi()
})
onBeforeUnmount(() => { lmap?.remove(); lmap = null })
watch(() => props.map, () => draw(), { deep: false })
watch(() => props.import, () => drawPreview(true))

function draw() {
  if (!lmap || !props.map) return
  for (const g of ['farm', 'fields', 'zones', 'locations', 'soil']) groups[g].clearLayers()
  const m = props.map
  // While drawing or placing, the map's own shapes let clicks through to the map.
  const interactive = !editing.value && !placing.value
  if (m.farm.boundary && editing.value?.kind !== 'farm') {
    L.geoJSON(m.farm.boundary, { interactive, style: { color: '#ffb300', weight: 3, fill: false, dashArray: '8 6' } })
      .on('click', (e: any) => { L.DomEvent.stopPropagation(e); pick('farm', null) })
      .addTo(groups.farm)
  }
  for (const f of m.fields) {
    if (!f.boundary || (editing.value?.kind === 'field' && editing.value.id === f.id)) continue
    const flaggedField = f.overlaps.length > 0 || f.outside_farm
    const colour = f.status !== 'active' ? '#bdbdbd' : f.parent ? '#4dd0e1' : flaggedField ? '#ff7043' : '#9ccc65'
    const chosen = sel.value?.kind === 'field' && sel.value.id === f.id
    const layer = L.geoJSON(f.boundary, { interactive, style: { color: chosen ? '#ffffff' : colour, weight: chosen ? 4 : 2, fillColor: colour, fillOpacity: f.parent ? 0.12 : 0.22, dashArray: f.parent ? '4 4' : undefined } })
    layer.bindTooltip(esc(f.code), { permanent: true, direction: 'center', className: 'agri-map-label' })
    layer.on('click', (e: any) => { L.DomEvent.stopPropagation(e); pick('field', f.id) })
    layer.addTo(groups.fields)
    fieldLayers[f.id] = layer
    for (const z of f.zones) {
      if (editing.value?.kind === 'zone' && editing.value.id === z.id) continue
      L.geoJSON(z.boundary, { interactive, style: { color: ZONE_COLOURS[z.zone_class] ?? '#5e35b1', weight: 1.5, fillOpacity: 0.35, dashArray: '2 3' } })
        .bindTooltip(`${esc(z.name)} · ${esc(k(`class_${z.zone_class}`))} · ${num(z.area_ha, 2)} ha`)
        .on('click', (e: any) => { L.DomEvent.stopPropagation(e); pick('field', f.id) })
        .addTo(groups.zones)
    }
  }
  for (const p of m.locations) {
    if (p.latitude === null || p.longitude === null) continue
    const marker = L.marker([p.latitude, p.longitude], {
      interactive,
      draggable: props.can.edit && moving.value === p.id,
      icon: L.divIcon({ className: 'agri-map-pin', html: `<span class="dot${moving.value === p.id ? ' moving' : ''}"></span><span class="lbl">${esc(p.name)}</span>`, iconSize: [14, 14], iconAnchor: [7, 7] }),
    })
    marker.on('click', (e: any) => { L.DomEvent.stopPropagation(e); pick('location', p.id) })
    marker.on('dragend', () => { const ll = marker.getLatLng(); moveTo(p, ll.lat, ll.lng) })
    marker.addTo(groups.locations)
    placeLayers[p.id] = marker
  }
  for (const s of m.soil) {
    L.circleMarker([s.latitude, s.longitude], { interactive, radius: 7, color: '#212121', weight: 1, fillColor: phColour(s.ph), fillOpacity: 0.95 })
      .bindPopup(soilPopup(s))
      .addTo(groups.soil)
  }
  drawPreview()
}

function soilPopup(s: Soil) {
  const row = (label: string, v: number | null, unit = '') => (v === null ? '' : `<tr><td>${esc(label)}</td><td style="text-align:right"><b>${num(v, 2)}</b> ${unit}</td></tr>`)
  return `<div style="min-width:200px"><div><b>${esc(k('soil_sample'))} ${esc(s.sample_ref ?? '')}</b></div>
    <div style="font-size:12px;color:#666">${esc(s.field ?? '')} · ${esc(s.sampled_on ?? '')}${s.laboratory ? ` · ${esc(s.laboratory)}` : ''}</div>
    <table style="width:100%;font-size:12px;margin-top:4px">${row('pH', s.ph)}${row(k('soil_oc'), s.organic_carbon_pct, '%')}${row('P', s.p_mg_kg, 'mg/kg')}${row('K', s.k_mg_kg, 'mg/kg')}${row('Ca', s.ca_mg_kg, 'mg/kg')}${row('Mg', s.mg_mg_kg, 'mg/kg')}</table>
    ${s.placed_at_field ? `<div style="font-size:11px;color:#888">${esc(k('soil_at_field_point'))}</div>` : ''}
    ${s.recommendation ? `<div style="font-size:12px;margin-top:4px">${esc(s.recommendation)}</div>` : ''}</div>`
}

function drawPreview(fit = false) {
  if (!lmap) return
  groups.preview.clearLayers()
  for (const f of props.import?.features ?? []) {
    L.geoJSON(f.geometry, { style: { color: f.problems.length ? '#e53935' : '#d500f9', weight: 2, fillOpacity: 0.1, dashArray: '6 3' } })
      .bindTooltip(esc(f.name ?? f.code ?? `#${f.index + 1}`))
      .addTo(groups.preview)
  }
  if (fit && groups.preview.getLayers().length)
    lmap.fitBounds(groups.preview.getBounds(), { padding: [30, 30], maxZoom: 17 })
}

function fitAll() {
  if (!lmap || !props.map) return
  const b = L.latLngBounds([])
  for (const g of ['farm', 'fields', 'locations', 'soil']) {
    const gb = groups[g].getBounds?.()
    if (gb?.isValid()) b.extend(gb)
  }
  if (b.isValid()) lmap.fitBounds(b, { padding: [30, 30], maxZoom: 17 })
  else if (props.map.farm.latitude !== null) lmap.setView([props.map.farm.latitude, props.map.farm.longitude], 15)
  else lmap.setView([-28.5, 24.7], 6)   // South Africa
}

function pick(kind: Sel['kind'], id: string | null, zoom = false) {
  sel.value = { kind, id }
  if (!lmap) return
  draw()
  if (zoom && kind === 'field' && id) {
    const layer = fieldLayers[id]
    if (layer) lmap.fitBounds(layer.getBounds(), { padding: [40, 40], maxZoom: 18 })
    else {
      const f = props.map?.fields.find(x => x.id === id)
      if (f?.latitude !== null && f?.latitude !== undefined) lmap.setView([f.latitude, f.longitude], 16)
    }
  }
  if (zoom && kind === 'location' && id && placeLayers[id]) lmap.setView(placeLayers[id].getLatLng(), 17)
}
function clearSelection() { sel.value = null; shownVersion.value = null; groups.history?.clearLayers(); draw() }

// ── satellite NDVI ──────────────────────────────────────────────────────────
type NdviLayer = { id: string; date: string; field: string; code: string; mean: number; cloud_pct: number | null; bounds: [number, number, number, number]; url: string }
const ndvi = ref<null | { layers: NdviLayer[]; series: Record<string, NdviSeries>; ramp: Array<[number, string]> }>(null)
const ndviDate = ref<string | null>(null)
const ndviDates = computed(() => [...new Set((ndvi.value?.layers ?? []).map(l => l.date))].sort().reverse())
const selSeries = computed(() => (selField.value && ndvi.value ? ndvi.value.series[selField.value.id] ?? null : null))
async function loadNdvi() {
  if (!props.farm) return
  try {
    const { data } = await axios.get(route('agri.farms.ndvi', props.farm.id))
    ndvi.value = data
    ndviDate.value = ndviDates.value[0] ?? null
    drawNdvi()
  }
  catch { ndvi.value = null }   // no crop view permission, or nothing yet: the map works without it
}
function drawNdvi() {
  if (!lmap || !groups.ndvi) return
  groups.ndvi.clearLayers()
  for (const l of (ndvi.value?.layers ?? []).filter(x => x.date === ndviDate.value)) {
    const [w, s, e, n] = l.bounds
    L.imageOverlay(l.url, [[s, w], [n, e]], { pane: 'ndvi', opacity: 0.85, alt: `NDVI ${l.code} ${l.date}` }).addTo(groups.ndvi)
  }
}
watch(ndviDate, () => drawNdvi())
const rampCss = computed(() => `linear-gradient(to right, ${(ndvi.value?.ramp ?? []).map(r => r[1]).join(', ')})`)

// ── drawing and editing shapes ──────────────────────────────────────────────
type Editing = { kind: 'new-field' | 'field' | 'farm' | 'new-zone' | 'zone'; id?: string; fieldId?: string }
const editing = ref<Editing | null>(null)
const live = ref<null | { area_ha: number | null; problems: string[]; warnings: string[] }>(null)
const checking = ref(false)
let working: any = null
let timer: any = null
const reason = ref('')

function begin(e: Editing, geometry: Geometry | null) {
  cancel()
  editing.value = e
  draw()
  if (geometry) {
    working = L.geoJSON(geometry).getLayers()[0]
    working.setStyle({ color: '#ffffff', weight: 3, fillColor: '#29b6f6', fillOpacity: 0.25 })
    working.addTo(lmap)
    working.pm.enable({ allowSelfIntersection: false, snappable: true })
    working.on('pm:edit pm:vertexadded pm:vertexremoved pm:markerdragend', checkSoon)
    lmap.fitBounds(working.getBounds(), { padding: [40, 40], maxZoom: 18 })
    checkSoon()
  }
  else {
    lmap.pm.enableDraw('Polygon', { allowSelfIntersection: false, snappable: true, pathOptions: { color: '#ffffff', fillColor: '#29b6f6', fillOpacity: 0.25 } })
  }
}

function onCreated(e: any) {
  if (!editing.value) { e.layer.remove(); return }
  if (e.shape !== 'Polygon') { e.layer.remove(); return }
  working = e.layer
  lmap.pm.disableDraw()
  working.pm.enable({ allowSelfIntersection: false, snappable: true })
  working.on('pm:edit pm:vertexadded pm:vertexremoved pm:markerdragend', checkSoon)
  checkSoon()
}

function shape(): Geometry | null {
  return working ? working.toGeoJSON(7).geometry : null
}

function checkSoon() {
  clearTimeout(timer)
  timer = setTimeout(async () => {
    const g = shape()
    if (!g || !props.farm || !editing.value) return
    checking.value = true
    try {
      const kind = editing.value.kind === 'farm' ? 'farm' : editing.value.kind.includes('zone') ? 'zone' : 'field'
      const fieldId = kind === 'zone' ? editing.value.fieldId : editing.value.id
      const { data } = await axios.post(route('agri.farms.map.check', props.farm.id), { boundary: g, kind, field_id: fieldId ?? null })
      live.value = data
    }
    catch { live.value = null }
    finally { checking.value = false }
  }, 350)
}

function cancel() {
  clearTimeout(timer)
  lmap?.pm.disableDraw()
  if (working) { working.pm?.disable(); working.remove() }
  working = null
  editing.value = null
  live.value = null
  reason.value = ''
  draw()
}

function save() {
  const g = shape()
  const e = editing.value
  if (!g || !e || !props.farm) return
  const done = { ...keep, onSuccess: () => cancel() }
  if (e.kind === 'new-field') return openFieldDialog(g)
  if (e.kind === 'new-zone') return openZoneDialog(e.fieldId!, null, g)
  if (e.kind === 'field') router.put(route('agri.farms.map.fields.boundary', [props.farm.id, e.id!]), { boundary: g, reason: reason.value || null }, done)
  if (e.kind === 'farm') router.put(route('agri.farms.map.boundary', props.farm.id), { boundary: g }, done)
  if (e.kind === 'zone') {
    const z = selField.value?.zones.find(x => x.id === e.id)
    if (z) router.patch(route('agri.farms.map.zones.update', [props.farm.id, e.fieldId!, z.id]), { name: z.name, zone_class: z.zone_class, soil_type: z.soil_type, notes: z.notes, boundary: g }, done)
  }
}

function removeFieldBoundary(f: Field) {
  if (props.farm && confirm(k('confirm_remove_boundary', { code: f.code })))
    router.put(route('agri.farms.map.fields.boundary', [props.farm.id, f.id]), { boundary: null }, keep)
}
function removeFarmBoundary() {
  if (props.farm && confirm(k('confirm_remove_farm_boundary')))
    router.put(route('agri.farms.map.boundary', props.farm.id), { boundary: null }, keep)
}

// ── new field / zone details ────────────────────────────────────────────────
const fieldDialog = ref(false)
const fieldForm = useForm({ code: '', name: '', land_use_type: 'field', area: null as number | null, area_unit: 'ha', soil_type: '', boundary: null as Geometry | null })
function openFieldDialog(g: Geometry) {
  fieldForm.reset()
  fieldForm.clearErrors()
  fieldForm.boundary = g
  fieldDialog.value = true
}
function saveField() {
  if (!props.farm) return
  fieldForm.transform(d => ({ ...d, area: d.area || null, soil_type: d.soil_type || null }))
    .post(route('agri.farms.map.fields.store', props.farm.id), { ...keep, onSuccess: () => { fieldDialog.value = false; cancel() } })
}

const zoneDialog = ref(false)
const zoneEditing = ref<{ fieldId: string; zoneId: string | null } | null>(null)
const zoneForm = useForm({ name: '', zone_class: 'medium', soil_type: '', notes: '', boundary: null as Geometry | null })
function openZoneDialog(fieldId: string, z: Zone | null, g: Geometry | null) {
  zoneForm.reset()
  zoneForm.clearErrors()
  zoneEditing.value = { fieldId, zoneId: z?.id ?? null }
  Object.assign(zoneForm, { name: z?.name ?? '', zone_class: z?.zone_class ?? 'medium', soil_type: z?.soil_type ?? '', notes: z?.notes ?? '', boundary: g ?? z?.boundary ?? null })
  zoneDialog.value = true
}
function saveZone() {
  if (!props.farm || !zoneEditing.value) return
  const { fieldId, zoneId } = zoneEditing.value
  const opts = { ...keep, onSuccess: () => { zoneDialog.value = false; cancel() } }
  zoneForm.transform(d => ({ ...d, soil_type: d.soil_type || null, notes: d.notes || null }))
  zoneId
    ? zoneForm.patch(route('agri.farms.map.zones.update', [props.farm.id, fieldId, zoneId]), opts)
    : zoneForm.post(route('agri.farms.map.zones.store', [props.farm.id, fieldId]), opts)
}
function removeZone(f: Field, z: Zone) {
  if (props.farm && confirm(k('confirm_remove_zone', { name: z.name })))
    router.delete(route('agri.farms.map.zones.destroy', [props.farm.id, f.id, z.id]), keep)
}

// ── locations ───────────────────────────────────────────────────────────────
const placing = ref(false)
const moving = ref<string | null>(null)
const placeDialog = ref(false)
const placeForm = useForm({ name: '', location_type: 'other', latitude: null as number | null, longitude: null as number | null, notes: '' })
function startPlacing() { cancel(); moving.value = null; placing.value = true; draw() }
function onMapClick(e: any) {
  if (!placing.value) return
  placing.value = false
  draw()
  placeForm.reset()
  placeForm.clearErrors()
  placeForm.latitude = Math.round(e.latlng.lat * 1e7) / 1e7
  placeForm.longitude = Math.round(e.latlng.lng * 1e7) / 1e7
  placeDialog.value = true
}
function savePlace() {
  if (!props.farm) return
  placeForm.transform(d => ({ ...d, notes: d.notes || null }))
    .post(route('agri.farms.map.locations.store', props.farm.id), { ...keep, onSuccess: () => { placeDialog.value = false } })
}
function startMoving(p: Place) { moving.value = p.id; draw() }
function moveTo(p: Place, lat: number, lng: number) {
  if (!props.farm) return
  moving.value = null
  router.patch(route('agri.farms.map.locations.move', [props.farm.id, p.id]), { latitude: Math.round(lat * 1e7) / 1e7, longitude: Math.round(lng * 1e7) / 1e7 }, keep)
}

// ── history ─────────────────────────────────────────────────────────────────
const shownVersion = ref<string | null>(null)
function showVersion(v: Version) {
  groups.history.clearLayers()
  if (shownVersion.value === v.id) { shownVersion.value = null; return }
  shownVersion.value = v.id
  if (v.boundary) L.geoJSON(v.boundary, { style: { color: '#ff1744', weight: 2, fill: false, dashArray: '2 4' } }).addTo(groups.history)
}

// ── import and export ───────────────────────────────────────────────────────
const uploadForm = useForm({ file: null as File | null })
const fileInput = ref<HTMLInputElement | null>(null)
function chooseFile() { fileInput.value?.click() }
function upload(ev: Event) {
  const f = (ev.target as HTMLInputElement).files?.[0]
  if (!f || !props.farm) return
  uploadForm.file = f
  uploadForm.post(route('agri.farms.map.imports.store', props.farm.id), { forceFormData: true, preserveScroll: true, onFinish: () => { if (fileInput.value) fileInput.value.value = '' } })
}
type Choice = { index: number; action: string; field_id: string | null; code: string; name: string; land_use_type: string }
const choices = ref<Choice[]>([])
watch(() => props.import, imp => {
  choices.value = (imp?.features ?? []).map(f => ({ index: f.index, action: f.suggested, field_id: f.match, code: f.code ?? '', name: f.name ?? '', land_use_type: 'field' }))
}, { immediate: true })
const actionItems = computed(() => [
  { title: k('import_match'), value: 'match' }, { title: k('import_create'), value: 'create' },
  ...(props.can.edit_farm ? [{ title: k('import_farm'), value: 'farm' }] : []), { title: k('import_skip'), value: 'skip' },
])
const fieldItems = computed(() => operationalFields.value.map(f => ({ title: `${f.code} · ${f.name}`, value: f.id })))
const applying = ref(false)
function applyImport() {
  if (!props.farm || !props.import) return
  applying.value = true
  router.post(route('agri.farms.map.imports.apply', [props.farm.id, props.import.id]), {
    rows: choices.value.map(c => ({ index: c.index, action: c.action, field_id: c.action === 'match' ? c.field_id : null, code: c.action === 'create' ? c.code : null, name: c.action === 'create' ? c.name : null, land_use_type: c.action === 'create' ? c.land_use_type : null })),
  }, { preserveScroll: true, onFinish: () => { applying.value = false } })
}
function discardImport() {
  if (props.farm && props.import) router.delete(route('agri.farms.map.imports.discard', [props.farm.id, props.import.id]), { preserveScroll: true })
}
const exportUrl = (format: string) => (props.farm ? route('agri.farms.map.export', [props.farm.id, format]) : '#')
const sourceLabel = (s: string) => k(`source_${s}`)
</script>

<template>
  <div>
    <PageErrors />
    <div class="mb-2" v-if="farm">
      <Link :href="route('agri.farms.show', farm.id)" class="text-body-2"><VIcon icon="tabler-arrow-left" size="16" /> {{ farm.name }}</Link>
    </div>
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div>
        <h4 class="text-h4 mb-1">{{ k('title') }}</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">{{ k('subtitle') }}</p>
      </div>
      <div class="d-flex flex-wrap gap-2 align-center">
        <VSelect v-if="farms.length > 1" :model-value="farm?.id" :items="farms.map(f => ({ title: f.name, value: f.id }))" :label="k('farm')"
          density="compact" hide-details prepend-inner-icon="tabler-map-2" style="min-inline-size: 220px; max-inline-size: 280px"
          @update:model-value="(v: string) => router.get(route('agri.farms.map', v))" />
        <template v-if="map">
          <VBtn v-if="can.edit" variant="tonal" prepend-icon="tabler-upload" :loading="uploadForm.processing" @click="chooseFile">{{ k('import') }}</VBtn>
          <input ref="fileInput" type="file" accept=".kml,.kmz,.geojson,.json,.zip" class="d-none" @change="upload">
          <VMenu>
            <template #activator="{ props: p }"><VBtn v-bind="p" variant="tonal" prepend-icon="tabler-download">{{ k('export') }}</VBtn></template>
            <VList density="compact">
              <VListItem :href="exportUrl('geojson')" prepend-icon="tabler-file-code" :title="k('export_geojson')" />
              <VListItem :href="exportUrl('kml')" prepend-icon="tabler-world" :title="k('export_kml')" />
            </VList>
          </VMenu>
        </template>
      </div>
    </div>

    <VAlert v-if="!map" type="info" variant="tonal">{{ k('no_farm') }}</VAlert>

    <template v-else>
      <VAlert v-if="warning" type="warning" variant="tonal" class="mb-3" closable>{{ warning }}</VAlert>
      <VAlert v-if="uploadForm.errors.file" type="error" variant="tonal" class="mb-3">{{ uploadForm.errors.file }}</VAlert>
      <VAlert v-if="!can.edit && !can.zones" type="info" variant="tonal" density="compact" class="mb-3">{{ k('read_only') }}</VAlert>

      <!-- Import preview -->
      <VCard v-if="props.import" class="mb-4" :title="k('import_title', { file: props.import.file_name })" :subtitle="props.import.crs ?? ''">
        <VTable density="compact">
          <thead><tr><th>{{ k('import_shape') }}</th><th class="text-end">{{ k('mapped') }}</th><th>{{ k('import_choice') }}</th><th /></tr></thead>
          <tbody>
            <tr v-for="(f, i) in props.import.features" :key="f.index">
              <td>
                <div class="font-weight-medium">{{ f.name ?? f.code ?? `#${f.index + 1}` }}</div>
                <div class="text-caption text-medium-emphasis">
                  <template v-if="f.code && f.code !== f.name">{{ f.code }} · </template>
                  <template v-if="f.kind">{{ human(f.kind) }} · </template>
                  <template v-if="f.match_code">{{ k('import_matched', { code: f.match_code, by: f.match_by }) }}</template>
                </div>
                <div v-for="p in f.problems" :key="p" class="text-caption text-error">{{ p }}</div>
                <div v-if="f.outside_farm" class="text-caption text-warning">{{ k('outside_farm') }}</div>
              </td>
              <td class="text-end text-no-wrap">{{ ha(f.area_ha) }}</td>
              <td style="min-inline-size: 180px"><VSelect v-model="choices[i].action" :items="actionItems" density="compact" hide-details :disabled="f.problems.length > 0" /></td>
              <td style="min-inline-size: 260px">
                <VAutocomplete v-if="choices[i].action === 'match'" v-model="choices[i].field_id" :items="fieldItems" :label="k('field')" density="compact" hide-details />
                <div v-else-if="choices[i].action === 'create'" class="d-flex gap-2">
                  <VTextField v-model="choices[i].code" :label="k('code')" density="compact" hide-details style="max-inline-size: 110px" />
                  <VTextField v-model="choices[i].name" :label="k('name')" density="compact" hide-details />
                </div>
              </td>
            </tr>
          </tbody>
        </VTable>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="discardImport">{{ k('discard') }}</VBtn>
          <VBtn color="primary" :loading="applying" @click="applyImport">{{ k('apply') }}</VBtn>
        </VCardActions>
      </VCard>

      <VRow>
        <VCol cols="12" md="8">
          <VCard>
            <!-- Toolbar -->
            <VCardText v-if="(can.edit || can.edit_farm) && !editing" class="d-flex flex-wrap gap-2 py-3">
              <VBtn v-if="can.edit" size="small" color="primary" prepend-icon="tabler-polygon" @click="begin({ kind: 'new-field' }, null)">{{ k('draw_field') }}</VBtn>
              <VBtn v-if="can.edit" size="small" variant="tonal" prepend-icon="tabler-map-pin-plus" :color="placing ? 'primary' : undefined" @click="startPlacing">{{ placing ? k('click_map') : k('place_location') }}</VBtn>
              <VBtn v-if="can.edit_farm" size="small" variant="tonal" prepend-icon="tabler-border-outer" @click="begin({ kind: 'farm' }, map.farm.boundary)">{{ map.farm.boundary ? k('edit_farm_boundary') : k('draw_farm_boundary') }}</VBtn>
              <VSpacer />
              <VBtn size="small" variant="text" prepend-icon="tabler-zoom-reset" @click="fitAll">{{ k('fit') }}</VBtn>
            </VCardText>
            <VCardText v-else-if="editing" class="d-flex flex-wrap gap-2 align-center py-3 bg-light-primary">
              <VIcon icon="tabler-pencil" />
              <span class="text-body-2">{{ k(`editing_${editing.kind.replace('-', '_')}`) }}</span>
              <VChip v-if="live?.area_ha !== null && live?.area_ha !== undefined" size="small" color="primary">{{ ha(live.area_ha) }}</VChip>
              <VProgressCircular v-if="checking" indeterminate size="16" width="2" />
              <VSpacer />
              <VTextField v-if="editing.kind === 'field'" v-model="reason" :label="k('reason')" density="compact" hide-details style="max-inline-size: 220px" />
              <VBtn size="small" variant="text" @click="cancel">{{ k('cancel') }}</VBtn>
              <VBtn size="small" color="primary" :disabled="!!live?.problems.length" @click="save">{{ k('save_shape') }}</VBtn>
            </VCardText>
            <div v-if="editing && live && (live.problems.length || live.warnings.length)" class="px-4 pb-2">
              <div v-for="p in live.problems" :key="p" class="text-body-2 text-error">{{ p }}</div>
              <div v-for="w in live.warnings" :key="w" class="text-body-2 text-warning">{{ w }}</div>
            </div>
            <VAlert v-if="loadFailed" type="error" variant="tonal" class="ma-4">{{ k('load_failed') }}</VAlert>
            <div ref="el" class="agri-map" />
            <VCardText class="d-flex flex-wrap gap-3 text-caption">
              <span><span class="agri-swatch" style="background:#9ccc65" /> {{ k('legend_field') }}</span>
              <span><span class="agri-swatch" style="background:#4dd0e1" /> {{ k('legend_part') }}</span>
              <span><span class="agri-swatch" style="background:#ff7043" /> {{ k('legend_flagged') }}</span>
              <span><span class="agri-swatch" style="border:2px dashed #ffb300" /> {{ k('legend_farm') }}</span>
              <span v-for="(c, cls) in ZONE_COLOURS" :key="cls"><span class="agri-swatch" :style="`background:${c}`" /> {{ k(`class_${cls}`) }}</span>
            </VCardText>
            <VCardText v-if="ndviDates.length" class="d-flex flex-wrap gap-3 align-center text-caption pt-0">
              <VSelect v-model="ndviDate" :items="ndviDates" :label="k('ndvi_date')" clearable density="compact" hide-details prepend-inner-icon="tabler-satellite" style="max-inline-size: 220px" />
              <template v-if="ndviDate">
                <strong>NDVI</strong>
                <span>{{ ndvi!.ramp[0][0] }}</span>
                <span class="agri-ndvi-ramp" :style="`background:${rampCss}`" />
                <span>{{ ndvi!.ramp[ndvi!.ramp.length - 1][0] }}</span>
                <span class="text-medium-emphasis">{{ k('ndvi_fields', { n: ndvi!.layers.filter(l => l.date === ndviDate).length }) }}</span>
              </template>
            </VCardText>
            <VCardText v-if="map.soil.length" class="d-flex flex-wrap gap-3 text-caption pt-0">
              <strong>{{ k('soil_ph') }}:</strong>
              <span v-for="b in PH_BANDS" :key="b.key"><span class="agri-swatch agri-swatch-round" :style="`background:${b.colour}`" /> {{ k(b.key) }}</span>
            </VCardText>
          </VCard>
        </VCol>

        <VCol cols="12" md="4">
          <!-- A field -->
          <VCard v-if="selField" class="mb-4">
            <VCardItem>
              <template #append><VBtn icon="tabler-x" variant="text" size="small" :aria-label="k('close')" @click="clearSelection" /></template>
              <VCardTitle>{{ selField.code }} · {{ selField.name }}</VCardTitle>
              <VCardSubtitle>{{ human(selField.land_use_type) }}<template v-if="selField.parent"> · {{ k('part_of', { code: selField.parent }) }}</template></VCardSubtitle>
            </VCardItem>
            <VCardText>
              <VTable density="compact" class="mb-2">
                <tbody>
                  <tr><td>{{ k('recorded') }}</td><td class="text-end">{{ ha(selField.recorded_ha) }}</td></tr>
                  <tr><td>{{ k('mapped') }}</td><td class="text-end">{{ ha(selField.mapped_ha) }}
                    <VChip v-if="selField.area_differs" size="x-small" color="warning" class="ms-1">{{ k('differs') }}</VChip></td></tr>
                </tbody>
              </VTable>
              <VAlert v-if="selField.outside_farm" type="warning" variant="tonal" density="compact" class="mb-2">{{ k('outside_farm') }}</VAlert>
              <VAlert v-if="selField.overlaps.length" type="warning" variant="tonal" density="compact" class="mb-2">{{ k('overlaps', { codes: selField.overlaps.join(', ') }) }}</VAlert>
              <div v-if="can.edit && !editing" class="d-flex flex-wrap gap-2">
                <VBtn v-if="!selField.boundary" size="small" color="primary" prepend-icon="tabler-polygon" @click="begin({ kind: 'field', id: selField.id }, null)">{{ k('draw_boundary') }}</VBtn>
                <template v-else>
                  <VBtn size="small" variant="tonal" prepend-icon="tabler-vector" @click="begin({ kind: 'field', id: selField.id }, selField.boundary)">{{ k('edit_boundary') }}</VBtn>
                  <VBtn size="small" variant="text" color="error" prepend-icon="tabler-trash" @click="removeFieldBoundary(selField)">{{ k('remove_boundary') }}</VBtn>
                </template>
              </div>
            </VCardText>

            <VDivider />
            <VCardText>
              <div class="d-flex align-center mb-2">
                <span class="text-subtitle-2">{{ k('zones') }} ({{ selField.zones.length }})</span>
                <VSpacer />
                <VBtn v-if="can.zones && selField.boundary && !editing" size="x-small" variant="tonal" prepend-icon="tabler-plus" @click="begin({ kind: 'new-zone', fieldId: selField.id }, null)">{{ k('add_zone') }}</VBtn>
              </div>
              <div v-if="!selField.boundary" class="text-caption text-medium-emphasis">{{ k('zones_need_boundary') }}</div>
              <VList v-else density="compact" class="py-0">
                <VListItem v-for="z in selField.zones" :key="z.id" class="px-0">
                  <template #prepend><span class="agri-swatch me-2" :style="`background:${ZONE_COLOURS[z.zone_class] ?? '#5e35b1'}`" /></template>
                  <VListItemTitle>{{ z.name }}</VListItemTitle>
                  <VListItemSubtitle>{{ k(`class_${z.zone_class}`) }} · {{ ha(z.area_ha) }}<template v-if="z.soil_type"> · {{ z.soil_type }}</template></VListItemSubtitle>
                  <template v-if="can.zones && !editing" #append>
                    <VMenu>
                      <template #activator="{ props: p }"><VBtn v-bind="p" icon="tabler-dots-vertical" variant="text" size="x-small" :aria-label="k('zone_actions')" /></template>
                      <VList density="compact">
                        <VListItem prepend-icon="tabler-vector" :title="k('edit_shape')" @click="begin({ kind: 'zone', id: z.id, fieldId: selField.id }, z.boundary)" />
                        <VListItem prepend-icon="tabler-edit" :title="k('edit_details')" @click="openZoneDialog(selField.id, z, null)" />
                        <VListItem prepend-icon="tabler-trash" base-color="error" :title="k('remove')" @click="removeZone(selField, z)" />
                      </VList>
                    </VMenu>
                  </template>
                </VListItem>
                <div v-if="!selField.zones.length" class="text-caption text-medium-emphasis">{{ k('no_zones') }}</div>
              </VList>
            </VCardText>

            <template v-if="selSeries && selSeries.points.length">
              <VDivider />
              <VCardText>
                <div class="d-flex align-center mb-1">
                  <span class="text-subtitle-2">{{ k('ndvi_series') }}</span>
                  <VSpacer />
                  <Link :href="`${route('agri.satellite.index')}?farm=${farm?.id}&field=${selField.id}`" class="text-caption">{{ k('crop_health') }}</Link>
                </div>
                <NdviChart :series="selSeries" :height="220" />
              </VCardText>
            </template>

            <VDivider />
            <VCardText>
              <div class="text-subtitle-2 mb-2">{{ k('history') }}</div>
              <div v-if="!selField.history.length" class="text-caption text-medium-emphasis">{{ k('no_history') }}</div>
              <VTimeline v-else density="compact" side="end" truncate-line="both">
                <VTimelineItem v-for="v in selField.history" :key="v.id" size="x-small" :dot-color="v.source === 'removed' ? 'error' : 'primary'">
                  <div class="text-body-2"><strong>v{{ v.version }}</strong> · {{ sourceLabel(v.source) }}<template v-if="v.source_name"> ({{ v.source_name }})</template></div>
                  <div class="text-caption">{{ ha(v.previous_area_ha) }} → {{ ha(v.area_ha) }}<template v-if="v.recorded_area_ha !== null"> · {{ k('recorded') }} {{ ha(v.recorded_area_ha) }}</template></div>
                  <div class="text-caption text-medium-emphasis">{{ v.at }}<template v-if="v.by"> · {{ v.by }}</template></div>
                  <div v-if="v.reason" class="text-caption">{{ v.reason }}</div>
                  <VBtn v-if="v.boundary" size="x-small" variant="text" class="px-0" @click="showVersion(v)">{{ shownVersion === v.id ? k('hide_on_map') : k('show_on_map') }}</VBtn>
                </VTimelineItem>
              </VTimeline>
            </VCardText>
          </VCard>

          <!-- The farm -->
          <VCard v-else-if="sel?.kind === 'farm'" class="mb-4">
            <VCardItem>
              <template #append><VBtn icon="tabler-x" variant="text" size="small" :aria-label="k('close')" @click="clearSelection" /></template>
              <VCardTitle>{{ map.farm.name }}</VCardTitle>
              <VCardSubtitle>{{ k('farm_boundary') }}</VCardSubtitle>
            </VCardItem>
            <VCardText>
              <VTable density="compact" class="mb-2"><tbody>
                <tr><td>{{ k('recorded') }}</td><td class="text-end">{{ ha(map.farm.recorded_ha) }}</td></tr>
                <tr><td>{{ k('mapped') }}</td><td class="text-end">{{ ha(map.farm.mapped_ha) }}</td></tr>
              </tbody></VTable>
              <div v-if="can.edit_farm && !editing && map.farm.boundary" class="d-flex gap-2 mb-3">
                <VBtn size="small" variant="tonal" prepend-icon="tabler-vector" @click="begin({ kind: 'farm' }, map.farm.boundary)">{{ k('edit_boundary') }}</VBtn>
                <VBtn size="small" variant="text" color="error" prepend-icon="tabler-trash" @click="removeFarmBoundary">{{ k('remove_boundary') }}</VBtn>
              </div>
              <div class="text-subtitle-2 mb-1">{{ k('history') }}</div>
              <div v-for="v in map.farm.history" :key="v.id" class="text-caption mb-1">
                <strong>v{{ v.version }}</strong> · {{ sourceLabel(v.source) }} · {{ ha(v.previous_area_ha) }} → {{ ha(v.area_ha) }} · {{ v.at }}<template v-if="v.by"> · {{ v.by }}</template>
              </div>
              <div v-if="!map.farm.history.length" class="text-caption text-medium-emphasis">{{ k('no_history') }}</div>
            </VCardText>
          </VCard>

          <!-- A location -->
          <VCard v-else-if="selPlace" class="mb-4">
            <VCardItem>
              <template #append><VBtn icon="tabler-x" variant="text" size="small" :aria-label="k('close')" @click="clearSelection" /></template>
              <VCardTitle>{{ selPlace.name }}</VCardTitle>
              <VCardSubtitle>{{ human(selPlace.location_type) }}</VCardSubtitle>
            </VCardItem>
            <VCardText>
              <div class="text-body-2 mb-2">{{ selPlace.latitude }}, {{ selPlace.longitude }}</div>
              <div v-if="selPlace.notes" class="text-body-2 mb-2">{{ selPlace.notes }}</div>
              <template v-if="can.edit">
                <VBtn v-if="moving !== selPlace.id" size="small" variant="tonal" prepend-icon="tabler-arrows-move" @click="startMoving(selPlace)">{{ k('move') }}</VBtn>
                <div v-else class="text-body-2 text-primary">{{ k('drag_to_move') }}</div>
              </template>
            </VCardText>
          </VCard>

          <!-- Every field -->
          <VCard :title="k('fields')" :subtitle="k('fields_summary', { mapped: operationalFields.length - unmapped.length, total: operationalFields.length, flagged: flagged.length })">
            <VTable density="compact" class="agri-map-fields">
              <thead><tr><th>{{ k('code') }}</th><th class="text-end">{{ k('recorded') }}</th><th class="text-end">{{ k('mapped') }}</th><th /></tr></thead>
              <tbody>
                <tr v-for="f in operationalFields" :key="f.id" :class="{ 'bg-light-primary': selField?.id === f.id }" style="cursor: pointer" @click="pick('field', f.id, true)">
                  <td><span class="font-weight-medium">{{ f.code }}</span><span v-if="f.parent" class="text-caption text-medium-emphasis"> ⊂ {{ f.parent }}</span></td>
                  <td class="text-end text-no-wrap">{{ ha(f.recorded_ha) }}</td>
                  <td class="text-end text-no-wrap" :class="{ 'text-warning font-weight-medium': f.area_differs }">{{ f.boundary ? ha(f.mapped_ha) : '—' }}</td>
                  <td class="text-end text-no-wrap">
                    <VIcon v-if="!f.boundary" icon="tabler-map-off" size="16" color="secondary" :title="k('not_mapped')" />
                    <VIcon v-if="f.outside_farm" icon="tabler-border-outer" size="16" color="warning" :title="k('outside_farm')" />
                    <VIcon v-if="f.overlaps.length" icon="tabler-stack-2" size="16" color="warning" :title="k('overlaps', { codes: f.overlaps.join(', ') })" />
                    <VIcon v-if="f.zones.length" icon="tabler-layout-grid" size="16" color="primary" :title="k('zones')" />
                  </td>
                </tr>
                <tr v-if="!operationalFields.length"><td colspan="4" class="text-center text-medium-emphasis py-4">{{ k('no_fields') }}</td></tr>
              </tbody>
            </VTable>
            <VCardText v-if="map.locations.length" class="pt-2">
              <div class="text-subtitle-2 mb-1">{{ k('locations') }}</div>
              <VChip v-for="p in map.locations" :key="p.id" size="small" class="me-1 mb-1" :variant="selPlace?.id === p.id ? 'flat' : 'tonal'"
                :color="p.latitude === null ? 'secondary' : 'primary'" @click="pick('location', p.id, true)">{{ p.name }}</VChip>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </template>

    <!-- New field details -->
    <VDialog v-model="fieldDialog" max-width="620">
      <VCard :title="k('new_field')">
        <VCardText>
          <p class="text-body-2 mb-4">{{ k('new_field_intro', { area: ha(live?.area_ha ?? null) }) }}</p>
          <VRow>
            <VCol cols="12" sm="4"><VTextField v-model="fieldForm.code" :label="`${k('code')} *`" :error-messages="fieldForm.errors.code" /></VCol>
            <VCol cols="12" sm="8"><VTextField v-model="fieldForm.name" :label="`${k('name')} *`" :error-messages="fieldForm.errors.name" /></VCol>
            <VCol cols="12" sm="6"><VSelect v-model="fieldForm.land_use_type" :label="k('land_use')" :items="options.land_use_types.map(x => ({ title: human(x), value: x }))" /></VCol>
            <VCol cols="12" sm="6"><VTextField v-model="fieldForm.soil_type" :label="k('soil_type')" /></VCol>
            <VCol cols="8"><VTextField v-model.number="fieldForm.area" type="number" :label="k('recorded_area')" :hint="k('recorded_area_hint')" persistent-hint :error-messages="fieldForm.errors.area" /></VCol>
            <VCol cols="4"><VSelect v-model="fieldForm.area_unit" :label="k('unit')" :items="options.area_units" /></VCol>
          </VRow>
          <VAlert v-if="fieldForm.errors.boundary || (fieldForm.errors as any).farm" type="error" variant="tonal" class="mt-3">{{ fieldForm.errors.boundary ?? (fieldForm.errors as any).farm }}</VAlert>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="fieldDialog = false">{{ k('cancel') }}</VBtn><VBtn color="primary" :loading="fieldForm.processing" @click="saveField">{{ k('save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Zone details -->
    <VDialog v-model="zoneDialog" max-width="560">
      <VCard :title="zoneEditing?.zoneId ? k('edit_zone') : k('new_zone')">
        <VCardText>
          <VRow>
            <VCol cols="12" sm="7"><VTextField v-model="zoneForm.name" :label="`${k('name')} *`" :error-messages="zoneForm.errors.name" /></VCol>
            <VCol cols="12" sm="5"><VSelect v-model="zoneForm.zone_class" :label="k('zone_class')" :items="options.zone_classes.map(x => ({ title: k(`class_${x}`), value: x }))" /></VCol>
            <VCol cols="12"><VTextField v-model="zoneForm.soil_type" :label="k('soil_type')" /></VCol>
            <VCol cols="12"><VTextarea v-model="zoneForm.notes" :label="k('notes')" rows="2" /></VCol>
          </VRow>
          <VAlert v-if="zoneForm.errors.boundary" type="error" variant="tonal" class="mt-2">{{ zoneForm.errors.boundary }}</VAlert>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="zoneDialog = false">{{ k('cancel') }}</VBtn><VBtn color="primary" :loading="zoneForm.processing" @click="saveZone">{{ k('save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- New location -->
    <VDialog v-model="placeDialog" max-width="520">
      <VCard :title="k('new_location')">
        <VCardText>
          <VRow>
            <VCol cols="12" sm="7"><VTextField v-model="placeForm.name" :label="`${k('name')} *`" :error-messages="placeForm.errors.name" /></VCol>
            <VCol cols="12" sm="5"><VSelect v-model="placeForm.location_type" :label="k('location_type')" :items="options.location_types.map(x => ({ title: human(x), value: x }))" /></VCol>
            <VCol cols="6"><VTextField v-model.number="placeForm.latitude" type="number" :label="k('latitude')" :error-messages="placeForm.errors.latitude" /></VCol>
            <VCol cols="6"><VTextField v-model.number="placeForm.longitude" type="number" :label="k('longitude')" :error-messages="placeForm.errors.longitude" /></VCol>
            <VCol cols="12"><VTextarea v-model="placeForm.notes" :label="k('notes')" rows="2" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="placeDialog = false">{{ k('cancel') }}</VBtn><VBtn color="primary" :loading="placeForm.processing" @click="savePlace">{{ k('save') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style>
/* Leaflet draws these outside the component, so they are not scoped. */
.agri-map { block-size: 68vh; min-block-size: 460px; inline-size: 100%; z-index: 0; }
.agri-map-label { background: transparent; border: 0; box-shadow: none; color: #fff; font-weight: 700; text-shadow: 0 0 3px #000, 0 0 2px #000; }
.agri-map-label::before { display: none; }
.agri-map-pin .dot { display: block; inline-size: 14px; block-size: 14px; border-radius: 50%; background: #1e88e5; border: 2px solid #fff; box-shadow: 0 0 3px #000; }
.agri-map-pin .dot.moving { background: #ff1744; }
.agri-map-pin .lbl { position: absolute; inset-inline-start: 16px; inset-block-start: -2px; white-space: nowrap; color: #fff; font-size: 12px; font-weight: 600; text-shadow: 0 0 3px #000; }
.agri-swatch { display: inline-block; inline-size: 12px; block-size: 12px; vertical-align: middle; border-radius: 2px; }
.agri-ndvi-ramp { display: inline-block; inline-size: 160px; block-size: 10px; border-radius: 2px; vertical-align: middle; }
.agri-swatch-round { border-radius: 50%; border: 1px solid #212121; }
.agri-map-fields tbody tr:hover { background: rgba(var(--v-theme-primary), 0.06); }
</style>
