<script setup lang="ts">
// A BI dashboard (copied from Mkhaya Sales and made generic): a template opened as it is, or a
// saved dashboard. Designers (BI level 4+) copy templates, add visuals and arrange the grid.
import { router, usePage } from '@inertiajs/vue3'
import axios from 'axios'
import { GridItem, GridLayout } from 'grid-layout-plus'
import { computed, reactive, ref, watch } from 'vue'
import Visual from '@/Components/BI/Visual.vue'
import { GROUPS, VISUALS, visual } from '@/Components/BI/visuals'

type Dataset = { label: string; module: string; measures: Record<string, { label: string; format: string }>; dimensions: Record<string, string> }
const props = defineProps<{
  dashboard: any; editing: boolean; canEdit: boolean; canDesign: boolean
  catalogue: { datasets: Record<string, Dataset>; grains: string[] }
  bookmarks: { id: number; name: string; state: any }[]; roles: string[]
  schedules: any[]; scheduleOptions: { periods: Record<string, string>; frequencies: Record<string, string> }
}>()
const page = usePage<any>()
const isTemplate = computed(() => !props.dashboard.uuid)

// ── Filters: a date range for the whole page, plus whatever was clicked on a chart ──
const f = reactive<{ from: string | null; to: string | null; override: boolean; dims: Record<string, any> }>({ from: null, to: null, override: false, dims: {} })
const dimLabels = reactive<Record<string, string>>({})
const appliedFilters = computed(() => ({ from: f.from, to: f.to, override: f.override && !!f.from && !!f.to, dims: { ...f.dims } }))
function onCrossFilter(e: { dimension: string; key: any; label: string }) { f.dims[e.dimension] = e.key; dimLabels[e.dimension] = e.label }
function clearDim(k: string) { delete f.dims[k]; delete dimLabels[k] }
function clearDates() { f.from = null; f.to = null; f.override = false }
const iso = (d: Date) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10)
const presets: [string, () => [string, string]][] = [
  ['MTD', () => { const d = new Date(); return [iso(new Date(d.getFullYear(), d.getMonth(), 1)), iso(d)] }],
  ['QTD', () => { const d = new Date(); return [iso(new Date(d.getFullYear(), Math.floor(d.getMonth() / 3) * 3, 1)), iso(d)] }],
  ['YTD', () => { const d = new Date(); return [iso(new Date(d.getFullYear(), 0, 1)), iso(d)] }],
  ['12M', () => { const d = new Date(); return [iso(new Date(d.getFullYear() - 1, d.getMonth(), d.getDate() + 1)), iso(d)] }],
  ['Last year', () => { const y = new Date().getFullYear() - 1; return [`${y}-01-01`, `${y}-12-31`] }],
]
function preset(fn: () => [string, string]) { [f.from, f.to] = fn(); f.override = true }
const dimName = (k: string) => Object.values(props.catalogue.datasets).map(d => d.dimensions[k]).find(Boolean) ?? k

// ── Layout & editing ──
const edit = ref(props.editing)
const widgets = ref<any[]>(JSON.parse(JSON.stringify(props.dashboard.widgets ?? [])).map((w: any) => ({ ...w, i: w.id })))
const name = ref(props.dashboard.name)
const dirty = ref(false)
const saving = ref(false)
async function save() {
  saving.value = true
  try {
    await axios.put(`/bi/${props.dashboard.uuid}`, { name: name.value, widgets: widgets.value.map(({ i, ...w }) => ({ ...w, id: String(i) })) }, { headers: { Accept: 'application/json' } })
    dirty.value = false; edit.value = false
  } finally { saving.value = false }
}
const copying = ref(false)
const copyName = ref(`${props.dashboard.name} (my copy)`)
function copyTemplate() { router.post('/bi', { name: copyName.value, template: props.dashboard.template_key }, { onSuccess: () => { copying.value = false } }) }

// Inertia reuses this component when moving between dashboards (template → your copy), so reset
// everything that was read from the previous dashboard's props.
watch(() => props.dashboard.key, () => {
  edit.value = props.editing
  widgets.value = JSON.parse(JSON.stringify(props.dashboard.widgets ?? [])).map((w: any) => ({ ...w, i: w.id }))
  name.value = props.dashboard.name
  dirty.value = false
  copying.value = false
  copyName.value = `${props.dashboard.name} (my copy)`
})

// ── Visual library & settings ──
const library = ref(false)
const libSearch = ref('')
const libGroup = ref<string | null>(null)
const libItems = computed(() => VISUALS.filter(v => (!libGroup.value || v.group === libGroup.value) && (!libSearch.value || v.name.toLowerCase().includes(libSearch.value.toLowerCase()))))
const firstDataset = computed(() => Object.keys(props.catalogue.datasets)[0])
function addVisual(key: string) {
  const vis = visual(key)
  const id = 'w' + Date.now().toString(36)
  const kpi = vis.kind === 'kpi'
  const ds = props.catalogue.datasets[firstDataset.value]
  const dims = Object.keys(ds?.dimensions ?? {})
  const defaults = { ...(vis.defaults ?? {}) }
  if (defaults.series === 'auto') defaults.series = dims[1] ?? null
  widgets.value.push({ i: id, id, type: key, title: vis.name, x: 0, y: 999, w: kpi ? 3 : 6, h: kpi ? 4 : 8,
    config: { dataset: firstDataset.value, measures: [Object.keys(ds?.measures ?? {})[0]].filter(Boolean), dimension: dims[0] ?? null, period: kpi ? 'ytd' : 'last_12_months', ...defaults } })
  library.value = false; dirty.value = true
}
const configuring = ref<any>(null)
const cfgVisual = computed(() => configuring.value ? visual(configuring.value.type) : null)
const cfgDataset = computed<Dataset | null>(() => configuring.value ? props.catalogue.datasets[configuring.value.config.dataset] ?? null : null)
const datasetItems = computed(() => Object.entries(props.catalogue.datasets).map(([value, d]) => ({ value, title: d.label, subtitle: d.module.replaceAll('_', ' ') })))
const measureItems = computed(() => Object.entries(cfgDataset.value?.measures ?? {}).map(([value, m]) => ({ value, title: m.label })))
const dimItems = computed(() => Object.entries(cfgDataset.value?.dimensions ?? {}).map(([value, title]) => ({ value, title })))
function onDatasetChange() {
  const ds = cfgDataset.value
  if (!ds) return
  configuring.value.config.measures = [Object.keys(ds.measures)[0]]
  configuring.value.config.dimension = Object.keys(ds.dimensions)[0] ?? null
  configuring.value.config.series = null
  dirty.value = true
}
const periodItems = [{ value: 'dashboard', title: 'Dashboard date filter' }, { value: 'mtd', title: 'Month to date' }, { value: 'last_month', title: 'Last month' }, { value: 'qtd', title: 'Quarter to date' }, { value: 'ytd', title: 'Year to date' }, { value: 'last_year', title: 'Last calendar year' }, { value: 'last_12_months', title: 'Last 12 months' }, { value: 'last_24_months', title: 'Last 24 months' }, { value: 'all', title: 'All data (balances)' }]
function duplicate(w: any) { const id = 'w' + Date.now().toString(36); widgets.value.push({ ...JSON.parse(JSON.stringify(w)), i: id, id, y: w.y + w.h }); dirty.value = true }
function remove(w: any) { widgets.value = widgets.value.filter(x => x.i !== w.i); dirty.value = true }

// ── Share & saved views ──
const share = ref(false)
const shareForm = reactive({ visibility: props.dashboard.visibility, shared_roles: props.dashboard.shared_roles ?? [] })
async function saveShare() { await axios.put(`/bi/${props.dashboard.uuid}`, shareForm, { headers: { Accept: 'application/json' } }); share.value = false }
const bmName = ref('')
function saveBookmark() { router.post('/bi/bookmarks', { dashboard: props.dashboard.key, name: bmName.value, state: { ...appliedFilters.value, labels: { ...dimLabels } } }, { preserveScroll: true, onSuccess: () => { bmName.value = '' } }) }
function applyBookmark(b: any) { f.from = b.state.from; f.to = b.state.to; f.override = !!b.state.override; f.dims = { ...(b.state.dims ?? {}) }; Object.assign(dimLabels, b.state.labels ?? {}) }
// ── Export & email schedules ──
function exportDashboard() { window.location.href = `/bi/export/${props.dashboard.key}?filters=${encodeURIComponent(JSON.stringify(appliedFilters.value))}` }
const scheduling = ref(false)
const weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'].map((title, i) => ({ value: i + 1, title }))
const blankSchedule = () => ({ name: props.dashboard.name, format: 'xlsx', period: 'last_month', frequency: 'weekly', weekday: 1, month_day: 1, send_time: '07:00', recipients: [] as string[] })
const sched = reactive(blankSchedule())
const schedErrors = ref<Record<string, string>>({})
const optionItems = (o: Record<string, string>) => Object.entries(o).map(([value, title]) => ({ value, title }))
function when(s: any) {
  const f = props.scheduleOptions.frequencies[s.frequency] ?? s.frequency
  const day = s.frequency === 'weekly' ? ` on ${weekdays[(s.weekday ?? 1) - 1]?.title}` : s.frequency === 'monthly' ? ` on day ${s.month_day}` : ''
  return `${f}${day} at ${s.send_time}`
}
function saveSchedule() {
  router.post('/bi/schedules', { ...sched, dashboard: props.dashboard.key }, {
    preserveScroll: true,
    onSuccess: () => { Object.assign(sched, blankSchedule()); schedErrors.value = {} },
    onError: e => { schedErrors.value = e },
  })
}
const schedAction = (s: any, action: 'toggle' | 'send') => router.post(`/bi/schedules/${s.id}/${action}`, {}, { preserveScroll: true })
const deleteSchedule = (s: any) => router.delete(`/bi/schedules/${s.id}`, { preserveScroll: true })
const userName = computed(() => (page.props.auth?.user?.name ?? '').split(' ')[0])
</script>

<template>
  <div class="mkbi-page">
    <header class="mkbi-header">
      <div>
        <p class="mkbi-eyebrow">{{ isTemplate ? 'Template' : 'Business Intelligence' }}<template v-if="userName"> · {{ userName }}</template></p>
        <VTextField v-if="edit" v-model="name" variant="plain" density="compact" hide-details class="mkbi-title-edit" @update:model-value="dirty = true" />
        <h1 v-else class="mkbi-title">{{ name }}</h1>
        <p class="mkbi-sub">{{ dashboard.description || 'Figures in rand, excluding VAT unless stated.' }}</p>
      </div>
      <div class="mkbi-header__actions no-print">
        <template v-if="edit">
          <VBtn variant="tonal" prepend-icon="tabler-layout-grid-add" @click="library = true">Add visual</VBtn>
          <VBtn variant="text" @click="router.reload()">Cancel</VBtn>
          <VBtn color="primary" :loading="saving" prepend-icon="tabler-device-floppy" @click="save">Save</VBtn>
        </template>
        <template v-else>
          <VMenu location="bottom end">
            <template #activator="{ props: p }"><VBtn v-bind="p" variant="text" icon="tabler-bookmark" title="Saved views" /></template>
            <VCard min-width="260" class="pa-2">
              <VList density="compact"><VListItem v-for="b in bookmarks" :key="b.id" :title="b.name" prepend-icon="tabler-bookmark" @click="applyBookmark(b)" /></VList>
              <div class="d-flex gap-2 pa-2"><VTextField v-model="bmName" placeholder="Save current view as…" density="compact" variant="outlined" hide-details /><VBtn icon="tabler-check" size="small" color="primary" :disabled="!bmName" @click="saveBookmark" /></div>
            </VCard>
          </VMenu>
          <VBtn variant="text" icon="tabler-file-spreadsheet" title="Export every visual to Excel" @click="exportDashboard" />
          <VBtn v-if="canDesign" variant="text" icon="tabler-mail" title="Email schedules" @click="scheduling = true" />
          <VBtn v-if="canEdit && !isTemplate" variant="text" icon="tabler-share" title="Share" @click="share = true" />
          <VBtn variant="tonal" prepend-icon="tabler-layout-dashboard" href="/bi">All dashboards</VBtn>
          <VBtn v-if="isTemplate && canDesign" color="primary" prepend-icon="tabler-copy" @click="copying = true">Copy &amp; customise</VBtn>
          <VBtn v-else-if="canEdit" color="primary" prepend-icon="tabler-edit" @click="edit = true">Edit</VBtn>
        </template>
      </div>
    </header>

    <div class="mkbi-filters no-print">
      <div class="mkbi-seg"><button v-for="[l, fn] in presets" :key="l" @click="preset(fn)">{{ l }}</button></div>
      <input v-model="f.from" type="date" class="mkbi-date" @change="f.override = true"> <span class="mkbi-muted">→</span> <input v-model="f.to" type="date" class="mkbi-date" @change="f.override = true">
      <VBtn v-if="f.override" size="small" variant="text" prepend-icon="tabler-x" @click="clearDates">Each visual's own period</VBtn>
      <span v-else class="mkbi-muted">Each visual shows its own period — pick dates to set one for the whole page.</span>
    </div>
    <div v-if="Object.keys(f.dims).length" class="mkbi-chips no-print">
      <span class="mkbi-muted me-1">Filtered by</span>
      <VChip v-for="(val, k) in f.dims" :key="k" closable size="small" color="primary" variant="tonal" @click:close="clearDim(k as string)">{{ dimName(k as string) }}: {{ dimLabels[k as string] ?? val }}</VChip>
      <VBtn size="x-small" variant="text" @click="Object.keys(f.dims).forEach(clearDim)">Clear all</VBtn>
    </div>

    <GridLayout v-model:layout="widgets" :col-num="12" :row-height="30" :margin="[16, 16]" :is-draggable="edit" :is-resizable="edit" :responsive="true" :breakpoints="{ lg: 1200, md: 996, sm: 768, xs: 480, xxs: 0 }" :cols="{ lg: 12, md: 12, sm: 6, xs: 2, xxs: 1 }" use-css-transforms @layout-updated="dirty = edit">
      <GridItem v-for="w in widgets" :key="w.i" :i="w.i" :x="w.x" :y="w.y" :w="w.w" :h="w.h" :min-w="2" :min-h="3" drag-ignore-from=".mkbi-card__body, .v-btn">
        <Visual :widget="w" :filters="appliedFilters" :editing="edit" :dashboard-name="name" @filter="onCrossFilter" @edit="configuring = w" @remove="remove(w)" @duplicate="duplicate(w)" />
      </GridItem>
    </GridLayout>
    <div v-if="!widgets.length" class="mkbi-blank"><VIcon icon="tabler-layout-dashboard" size="40" /><p>This dashboard is empty.</p><VBtn v-if="canEdit" color="primary" @click="edit = true; library = true">Add your first visual</VBtn></div>

    <!-- Copy a template -->
    <VDialog v-model="copying" max-width="460">
      <VCard title="Copy this template">
        <VCardText><p class="text-body-2 text-medium-emphasis mb-3">Your copy is yours to change: add, remove and rearrange visuals, then share it.</p>
          <VTextField v-model="copyName" label="Dashboard name" density="compact" variant="outlined" /></VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="copying = false">Cancel</VBtn><VBtn color="primary" :disabled="!copyName" @click="copyTemplate">Copy</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Visual library -->
    <VDialog v-model="library" max-width="1000" scrollable>
      <VCard class="mkbi-lib">
        <div class="mkbi-lib__head"><h3>Visual library</h3><VTextField v-model="libSearch" placeholder="Search visuals" prepend-inner-icon="tabler-search" density="compact" variant="outlined" hide-details style="max-width: 280px" /></div>
        <div class="mkbi-lib__body">
          <nav><button :class="{ on: !libGroup }" @click="libGroup = null">All</button><button v-for="g in GROUPS" :key="g" :class="{ on: libGroup === g }" @click="libGroup = g">{{ g }}</button></nav>
          <div class="mkbi-lib__grid">
            <button v-for="vis in libItems" :key="vis.key" class="mkbi-lib__item" @click="addVisual(vis.key)"><VIcon :icon="vis.icon" size="22" /><span>{{ vis.name }}</span><small>{{ vis.group }}</small></button>
          </div>
        </div>
      </VCard>
    </VDialog>

    <!-- Visual settings -->
    <VNavigationDrawer :model-value="!!configuring" location="right" temporary width="380" @update:model-value="v => !v && (configuring = null)">
      <div v-if="configuring" class="pa-5 d-flex flex-column gap-4">
        <div class="d-flex align-center justify-space-between"><h3 class="text-h6">Visual settings</h3><VBtn icon="tabler-x" variant="text" size="small" @click="configuring = null" /></div>
        <VTextField v-model="configuring.title" label="Title" density="compact" variant="outlined" hide-details @update:model-value="dirty = true" />
        <VSelect v-model="configuring.type" :items="VISUALS.filter(x => x.kind === cfgVisual?.kind).map(x => ({ value: x.key, title: x.name }))" label="Visual type" density="compact" variant="outlined" hide-details @update:model-value="dirty = true" />
        <VTextarea v-if="cfgVisual?.special === 'text'" v-model="configuring.config.text" label="Text" rows="5" density="compact" variant="outlined" hide-details @update:model-value="dirty = true" />
        <template v-else>
          <div class="mkbi-cfg-label">Data</div>
          <VAutocomplete v-model="configuring.config.dataset" :items="datasetItems" label="Data from" density="compact" variant="outlined" hide-details @update:model-value="onDatasetChange">
            <template #item="{ props: p, item }"><VListItem v-bind="p" :subtitle="item.raw.subtitle" /></template>
          </VAutocomplete>
          <VSelect v-model="configuring.config.measures" :items="[...measureItems, { value: 'last_year', title: 'Same period last year' }, { value: 'growth_pct', title: 'Growth % vs last year' }]" label="Measures" multiple chips closable-chips density="compact" variant="outlined" hide-details @update:model-value="dirty = true" />
          <VSelect v-if="['category', 'pareto', 'table', 'matrix', 'treemap', 'waterfall', 'narrative'].includes(cfgVisual!.kind)" v-model="configuring.config.dimension" :items="dimItems" label="Category / dimension" density="compact" variant="outlined" hide-details @update:model-value="dirty = true" />
          <VSelect v-if="['time', 'matrix', 'treemap'].includes(cfgVisual!.kind)" v-model="configuring.config.series" :items="[{ value: null, title: 'None' }, ...(cfgVisual!.kind === 'matrix' ? [{ value: 'month', title: 'Month' }] : []), ...dimItems]" label="Split by / second level" density="compact" variant="outlined" hide-details @update:model-value="dirty = true" />
          <VSelect v-if="cfgVisual!.kind === 'time'" v-model="configuring.config.grain" :items="catalogue.grains" label="Time interval" density="compact" variant="outlined" hide-details @update:model-value="dirty = true" />
          <VSelect v-model="configuring.config.period" :items="periodItems" label="Period" density="compact" variant="outlined" hide-details @update:model-value="dirty = true" />
          <div class="mkbi-cfg-label">Display</div>
          <div class="d-flex gap-3">
            <VTextField v-model.number="configuring.config.top" type="number" label="Top N" density="compact" variant="outlined" hide-details @update:model-value="dirty = true" />
            <VSelect v-model="configuring.config.sort" :items="[{ value: 'desc', title: 'Largest first' }, { value: 'asc', title: 'Smallest first' }, { value: 'chrono', title: 'A–Z' }]" label="Sort" density="compact" variant="outlined" hide-details @update:model-value="dirty = true" />
          </div>
          <VSwitch v-model="configuring.config.other" label="Group the rest as “Other”" density="compact" hide-details inset @update:model-value="dirty = true" />
        </template>
        <p class="text-caption text-medium-emphasis">Only data from modules your company subscribes to, and that you may see, is offered here.</p>
      </div>
    </VNavigationDrawer>

    <!-- Email schedules -->
    <VDialog v-model="scheduling" max-width="720" scrollable>
      <VCard title="Email this dashboard">
        <VCardText class="d-flex flex-column gap-4">
          <p class="text-body-2 text-medium-emphasis mb-0">Recipients get the headline figures in the email and an Excel file with the numbers behind every visual. It is built as you, so it only ever holds what you may see.</p>
          <div v-if="schedules.length" class="mkbi-sched">
            <div v-for="s in schedules" :key="s.id" class="mkbi-sched__row">
              <div class="flex-grow-1" style="min-width: 0">
                <div class="font-weight-bold">{{ s.name }} <VChip v-if="!s.is_active" size="x-small" class="ms-1">Paused</VChip></div>
                <div class="text-caption text-medium-emphasis">{{ when(s) }} · {{ scheduleOptions.periods[s.period] }} · {{ s.format.toUpperCase() }} · {{ s.recipients.join(', ') }}</div>
                <div v-if="s.last_status" class="text-caption" :class="s.last_status === 'failed' ? 'text-error' : 'text-success'">Last {{ s.last_status }} {{ new Date(s.last_sent_at).toLocaleString('en-ZA') }}<template v-if="s.last_error">: {{ s.last_error }}</template></div>
              </div>
              <VBtn size="small" variant="text" icon="tabler-send" title="Send now" @click="schedAction(s, 'send')" />
              <VBtn size="small" variant="text" :icon="s.is_active ? 'tabler-player-pause' : 'tabler-player-play'" :title="s.is_active ? 'Pause' : 'Resume'" @click="schedAction(s, 'toggle')" />
              <VBtn size="small" variant="text" color="error" icon="tabler-trash" title="Delete" @click="deleteSchedule(s)" />
            </div>
          </div>
          <div class="mkbi-cfg-label">New schedule</div>
          <div class="mkbi-sched__form">
            <VTextField v-model="sched.name" label="Email subject" density="compact" variant="outlined" :error-messages="schedErrors.name" />
            <VSelect v-model="sched.period" :items="optionItems(scheduleOptions.periods)" label="Figures for" density="compact" variant="outlined" />
            <VSelect v-model="sched.frequency" :items="optionItems(scheduleOptions.frequencies)" label="How often" density="compact" variant="outlined" />
            <VSelect v-if="sched.frequency === 'weekly'" v-model="sched.weekday" :items="weekdays" label="Day" density="compact" variant="outlined" />
            <VTextField v-if="sched.frequency === 'monthly'" v-model.number="sched.month_day" type="number" min="1" max="31" label="Day of the month" density="compact" variant="outlined" :error-messages="schedErrors.month_day" />
            <VTextField v-model="sched.send_time" type="time" label="Send at" density="compact" variant="outlined" :error-messages="schedErrors.send_time" />
            <VSelect v-model="sched.format" :items="[{ value: 'xlsx', title: 'Excel (every visual)' }, { value: 'csv', title: 'CSV (first visual)' }]" label="Attachment" density="compact" variant="outlined" />
          </div>
          <VCombobox v-model="sched.recipients" label="Send to (type an email and press Enter)" multiple chips closable-chips density="compact" variant="outlined"
            :error-messages="schedErrors.recipients || Object.entries(schedErrors).find(([k]) => k.startsWith('recipients.'))?.[1]" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="scheduling = false">Close</VBtn><VBtn color="primary" prepend-icon="tabler-calendar-plus" :disabled="!sched.recipients.length" @click="saveSchedule">Add schedule</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="share" max-width="480">
      <VCard title="Share dashboard">
        <VCardText class="d-flex flex-column gap-3">
          <VRadioGroup v-model="shareForm.visibility" hide-details><VRadio value="private" label="Only me" /><VRadio value="shared" label="Selected roles" /><VRadio value="organisation" label="Everyone in the organisation" /></VRadioGroup>
          <VSelect v-if="shareForm.visibility === 'shared'" v-model="shareForm.shared_roles" :items="roles" label="Roles" multiple chips density="compact" variant="outlined" />
          <p class="text-caption text-medium-emphasis mb-0">People only see the visuals whose modules they have access to.</p>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="share = false">Cancel</VBtn><VBtn color="primary" @click="saveShare">Save</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.mkbi-page { padding: 8px 8px 48px; min-height: calc(100vh - 140px); }
.mkbi-header { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; margin: 0 8px 20px; }
.mkbi-eyebrow { margin: 0 0 4px; font-size: 12px; color: #6A0DAD; font-weight: 700; letter-spacing: .4px; text-transform: uppercase; }
.mkbi-title { margin: 0; font-size: 28px; font-weight: 700; letter-spacing: -.6px; color: #16161F; }
.mkbi-title-edit :deep(input) { font-size: 26px; font-weight: 700; }
.mkbi-sub { margin: 4px 0 0; font-size: 13px; color: #8A8A99; }
.mkbi-header__actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.mkbi-filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; background: #fff; border: 1px solid #EEEEF3; border-radius: 16px; padding: 10px 12px; margin: 0 8px 12px; }
.mkbi-seg { display: inline-flex; background: #F3F1F8; border-radius: 10px; padding: 3px; }
.mkbi-seg button { border: 0; background: transparent; padding: 5px 12px; font-size: 12px; font-weight: 600; color: #5B5B6E; border-radius: 8px; cursor: pointer; }
.mkbi-seg button:hover { background: #fff; color: #6A0DAD; }
.mkbi-date { border: 1px solid #E4E4EC; border-radius: 10px; padding: 7px 10px; font-size: 13px; color: #2B2B38; }
.mkbi-chips { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; margin: 0 8px 8px; font-size: 12px; }
.mkbi-muted { color: #8A8A99; font-size: 12px; }
.mkbi-blank { text-align: center; padding: 80px 0; color: #8A8A99; }
.mkbi-lib { border-radius: 20px !important; overflow: hidden; }
.mkbi-lib__head { display: flex; justify-content: space-between; align-items: center; padding: 20px 24px; border-bottom: 1px solid #EEEEF3; }
.mkbi-lib__head h3 { margin: 0; font-size: 18px; }
.mkbi-lib__body { display: grid; grid-template-columns: 190px 1fr; min-height: 480px; }
.mkbi-lib__body nav { border-right: 1px solid #EEEEF3; padding: 12px; display: flex; flex-direction: column; gap: 2px; }
.mkbi-lib__body nav button { text-align: left; border: 0; background: transparent; padding: 8px 12px; border-radius: 10px; font-size: 13px; color: #45455A; cursor: pointer; }
.mkbi-lib__body nav button.on, .mkbi-lib__body nav button:hover { background: #F3EEFA; color: #6A0DAD; font-weight: 600; }
.mkbi-lib__grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; padding: 16px; align-content: start; overflow: auto; max-height: 64vh; }
.mkbi-lib__item { display: flex; flex-direction: column; align-items: flex-start; gap: 6px; padding: 14px; border: 1px solid #EEEEF3; border-radius: 14px; background: #fff; cursor: pointer; text-align: left; transition: all .15s; color: #6A0DAD; }
.mkbi-lib__item:hover { border-color: #C9B3E6; box-shadow: 0 8px 22px rgba(106,13,173,.08); transform: translateY(-1px); }
.mkbi-lib__item span { font-size: 13px; font-weight: 600; color: #22222E; } .mkbi-lib__item small { font-size: 11px; color: #8A8A99; }
.mkbi-cfg-label { font-size: 11px; font-weight: 700; letter-spacing: .8px; text-transform: uppercase; color: #8A8A99; margin-top: 6px; }
.mkbi-sched { display: flex; flex-direction: column; border: 1px solid #EEEEF3; border-radius: 14px; }
.mkbi-sched__row { display: flex; align-items: center; gap: 4px; padding: 10px 12px; border-bottom: 1px solid #EEEEF3; }
.mkbi-sched__row:last-child { border-bottom: 0; }
.mkbi-sched__form { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
:deep(.vgl-item--placeholder) { background: #E9DDF7 !important; border-radius: 18px; opacity: .6; }
@media print { .no-print { display: none !important; } }
</style>
