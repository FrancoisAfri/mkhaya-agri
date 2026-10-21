<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import { route } from 'ziggy-js'
import PageErrors from '@/Modules/Agri/Components/PageErrors.vue'
import FormDialog from '@/Modules/Agri/Components/FormDialog.vue'
import FarmFilter from '@/Modules/Agri/Components/FarmFilter.vue'
import WorkforceTabs from '@/Modules/Agri/Components/WorkforceTabs.vue'
import { human, statusColor } from '@/Modules/Agri/Components/agri'

type Shift = { id: number; uuid: string; title: string; status: string; revision: number; starts_at: string; ends_at: string; date: string; label: string;
  min_staff: number; required_certifications: string[]; template: string | null; assignments: Array<{ id: number; uuid: string; worker_id: number; name: string; status: string; warnings: string | null }> }
const props = defineProps<{
  farm: null | { id: string; name: string }
  farms: Array<{ id: string; name: string }>
  period: { from: string; days: number }
  shifts: Shift[]; gaps: Array<{ shift_id: string; problem: string }>
  workers: Array<{ id: number; name: string; category: string; crew: string | null }>
  swaps: any[]
  demand: Array<{ date: string; rostered_hours: number; job_hours: number; gap_hours: number; jobs: number; activities: number; harvests: string[]; short: number }>
  crews: Array<{ id: string; code: string; name: string; workers: number }>
  patterns: Array<{ id: string; name: string; season: string | null; cycle: string[]; crew: string | null; people: number | null }>
  options: { templates: Array<{ id: number; code: string; name: string; starts: string; ends: string }>; competencies: any[]; farms: any[] }
  can: { manage: boolean }
}>()

const keep = { preserveScroll: true }
// Dates are built and printed in local time: toISOString() would turn local midnight into the
// previous day for anyone east of Greenwich, shifting the whole grid by a day.
const ymd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
const addDays = (s: string, n: number) => { const d = new Date(s + 'T00:00:00'); d.setDate(d.getDate() + n); return ymd(d) }
const days = computed(() => Array.from({ length: props.period.days }, (_, i) => addDays(props.period.from, i)))
const demandOn = (d: string) => props.demand.find(x => x.date === d)
const dayLabel = (d: string) => new Date(d + 'T00:00:00').toLocaleDateString('en-ZA', { weekday: 'short', day: 'numeric', month: 'short' })
const cell = (workerId: number, d: string) => props.shifts.filter(s => s.date === d && s.status !== 'cancelled' && s.assignments.some(a => a.worker_id === workerId && a.status === 'assigned'))
const unfilled = computed(() => props.shifts.filter(s => s.status !== 'cancelled' && s.assignments.filter(a => a.status === 'assigned').length < s.min_staff))
const gapFor = (s: Shift) => props.gaps.filter(g => g.shift_id === s.uuid).map(g => g.problem)
const drafts = computed(() => props.shifts.filter(s => s.status === 'draft').length)
const move = (deltaDays: number) => {
  router.get(route('attendance.roster.index'), { farm: props.farm?.id, from: addDays(props.period.from, deltaDays), days: props.period.days }, { preserveState: false })
}
const publish = () => confirm(`Publish ${drafts.value} draft shift(s)? The people on them are told, and every later change is versioned.`)
  && router.post(route('attendance.roster.publish'), { farm_id: props.farm?.id, from: props.period.from, days: props.period.days }, keep)

// ── A shift ───────────────────────────────────────────────────────────────────────
const selected = ref<Shift | null>(null)
const picks = ref<number[]>([])
const problems = ref<Record<number, Array<{ level: string; message: string }>>>({})
const history = ref<any[]>([])
async function openShift(s: Shift) {
  selected.value = s; picks.value = []; problems.value = {}; history.value = []
  if (s.revision > 0) history.value = (await axios.get(route('attendance.roster.shifts.history', s.uuid))).data
}
async function checkPicks() {
  if (!selected.value || !picks.value.length) { problems.value = {}; return }
  problems.value = (await axios.get(route('attendance.roster.shifts.check', selected.value.uuid), { params: { worker_ids: picks.value } })).data
}
const hasWarnings = computed(() => Object.values(problems.value).some(p => p.some(x => x.level === 'warn')))
const hasBlocks = computed(() => Object.values(problems.value).some(p => p.some(x => x.level === 'block')))
function assign() {
  if (!selected.value) return
  router.post(route('attendance.roster.shifts.assign', selected.value.uuid), { worker_ids: picks.value, accept_warnings: hasWarnings.value }, { ...keep, onSuccess: () => { selected.value = null } })
}
const crewPick = ref<string | null>(null)
function assignCrew() {
  if (!selected.value || !crewPick.value) return
  router.post(route('attendance.roster.shifts.assign', selected.value.uuid), { crew_id: crewPick.value, accept_warnings: true }, { ...keep, onSuccess: () => { selected.value = null; crewPick.value = null } })
}
function remove(a: any) {
  const reason = selected.value?.status === 'published' ? prompt('Why is this person coming off the shift? They are told.') : 'Removed in draft'
  if (reason) router.post(route('attendance.roster.assignments.remove', a.uuid), { reason }, { ...keep, onSuccess: () => { selected.value = null } })
}
function cancelShift() {
  const reason = prompt('Why is this shift cancelled?')
  if (reason && selected.value) router.post(route('attendance.roster.shifts.cancel', selected.value.uuid), { reason }, { ...keep, onSuccess: () => { selected.value = null } })
}
const editOpen = ref(false)
const reviewSwap = (s: any, approve: boolean) => router.post(route('attendance.roster.swaps.review', s.uuid), { approve, accept_warnings: true }, keep)

// ── Rotating roster ───────────────────────────────────────────────────────────────
const rotateOpen = ref(false)
const cycle = ref<string[]>(['DAY', 'DAY', 'DAY', 'DAY', 'DAY', 'OFF', 'OFF'])
const rotateDays = ref(14)
const rotateFrom = ref(props.period.from)
const rotateWorkers = ref<Array<{ id: number; offset: number }>>([])
function toggleRotate(id: number) {
  const i = rotateWorkers.value.findIndex(w => w.id === id)
  i >= 0 ? rotateWorkers.value.splice(i, 1) : rotateWorkers.value.push({ id, offset: 0 })
}
const addShiftOpen = ref(false)
const rotateCrew = ref<string | null>(null)
const keepAs = ref('')
const keepSeason = ref('')
function savePattern() {
  if (!keepAs.value) return
  router.post(route('attendance.roster.patterns.store'), { farm_id: props.farm?.id, name: keepAs.value, season: keepSeason.value || null, cycle: cycle.value,
    crew_id: rotateCrew.value, workers: rotateCrew.value ? undefined : rotateWorkers.value }, { ...keep, onSuccess: () => { keepAs.value = ''; keepSeason.value = '' } })
}
const patternsOpen = ref(false)
const applyFrom = ref(props.period.from)
const applyDays = ref(14)
const applyPattern = (p: any) => router.post(route('attendance.roster.patterns.apply', p.id), { from: applyFrom.value, days: applyDays.value }, { ...keep, onSuccess: () => { patternsOpen.value = false } })
const retirePattern = (p: any) => confirm(`Retire the pattern "${p.name}"? Shifts already made from it stay.`) && router.post(route('attendance.roster.patterns.retire', p.id), {}, keep)
const rotateWithCrew = () => router.post(route('attendance.roster.rotate'), { farm_id: props.farm?.id, cycle: cycle.value, from: rotateFrom.value, days: rotateDays.value,
  workers: rotateCrew.value ? props.workers.filter(w => w.crew === props.crews.find(c => c.id === rotateCrew.value)?.code).map(w => ({ id: w.id, offset: 0 })) : rotateWorkers.value },
  { ...keep, onSuccess: () => { rotateOpen.value = false } })
</script>

<template>
  <div>
    <PageErrors />
    <div class="d-flex flex-wrap align-center justify-space-between gap-4 mb-4">
      <div>
        <h4 class="text-h4 mb-1">Roster</h4>
        <p class="text-body-1 text-medium-emphasis mb-0">Plan who works when and where. Overlaps, rest, leave and missing competencies are checked as you assign; published changes keep the original.</p>
      </div>
      <div class="d-flex flex-wrap gap-2 align-center">
        <FarmFilter :farms="farms" :model-value="farm?.id ?? 'organisation'" :allow-all="false" organisation />
        <VBtnGroup density="compact" variant="outlined" divided>
          <VBtn icon="tabler-chevron-left" @click="move(-period.days)" />
          <VBtn @click="move(0)">{{ dayLabel(period.from) }} · {{ period.days }} days</VBtn>
          <VBtn icon="tabler-chevron-right" @click="move(period.days)" />
        </VBtnGroup>
        <template v-if="can.manage">
          <VBtn variant="tonal" prepend-icon="tabler-plus" @click="addShiftOpen = true">Shift</VBtn>
          <VBtn variant="tonal" prepend-icon="tabler-repeat" @click="rotateOpen = true">Rotating roster</VBtn>
          <VBtn variant="tonal" prepend-icon="tabler-template" @click="patternsOpen = true">Patterns {{ patterns.length || '' }}</VBtn>
          <VBtn color="primary" prepend-icon="tabler-send" :disabled="!drafts" @click="publish">Publish {{ drafts || '' }}</VBtn>
        </template>
      </div>
    </div>
    <WorkforceTabs current="roster" />

    <VAlert v-if="unfilled.length" type="warning" variant="tonal" class="mb-4" icon="tabler-users-minus">
      {{ unfilled.length }} shift(s) short of people.
      <span v-for="g in gaps.slice(0, 4)" :key="g.shift_id + g.problem" class="text-caption"> · {{ shifts.find(s => s.uuid === g.shift_id)?.title }} {{ shifts.find(s => s.uuid === g.shift_id)?.date }}: {{ g.problem }}</span>
    </VAlert>
    <VAlert v-if="swaps.length" type="info" variant="tonal" class="mb-4">
      <div v-for="s in swaps" :key="s.uuid" class="d-flex align-center gap-2">Swap to {{ s.to }}: {{ s.reason }}
        <VBtn v-if="can.manage" size="x-small" color="success" variant="tonal" @click="reviewSwap(s, true)">Approve</VBtn>
        <VBtn v-if="can.manage" size="x-small" variant="text" @click="reviewSwap(s, false)">Reject</VBtn></div>
    </VAlert>

    <VCard>
      <div style="overflow-x: auto">
        <VTable density="compact" class="roster">
          <thead><tr><th style="min-width: 180px">Worker</th><th v-for="d in days" :key="d" class="text-center" style="min-width: 120px">{{ dayLabel(d) }}</th></tr></thead>
          <tbody>
            <tr v-for="w in workers" :key="w.id">
              <td><strong>{{ w.name }}</strong><div class="text-caption">{{ human(w.category) }}<template v-if="w.crew"> · {{ w.crew }}</template></div></td>
              <td v-for="d in days" :key="d" class="text-center">
                <VChip v-for="s in cell(w.id, d)" :key="s.id" size="small" :color="s.status === 'published' ? 'primary' : undefined" :variant="s.status === 'published' ? 'tonal' : 'outlined'" class="ma-1" @click="openShift(s)">
                  {{ s.template ?? s.title }} {{ s.label }}
                </VChip>
              </td>
            </tr>
            <tr>
              <td class="text-medium-emphasis">Shifts</td>
              <td v-for="d in days" :key="d" class="text-center">
                <VChip v-for="s in shifts.filter(x => x.date === d)" :key="s.id" size="x-small" class="ma-1" :color="gapFor(s).length ? 'warning' : statusColor(s.status)" variant="tonal" @click="openShift(s)">
                  {{ s.template ?? s.title }} {{ s.assignments.filter(a => a.status === 'assigned').length }}/{{ s.min_staff }}
                </VChip>
              </td>
            </tr>
            <tr>
              <td class="text-medium-emphasis">Planned work<div class="text-caption">job hours / rostered hours</div></td>
              <td v-for="d in days" :key="d" class="text-center text-caption">
                <template v-if="demandOn(d)">
                  <div :class="demandOn(d)!.gap_hours > 0 ? 'text-error font-weight-medium' : ''">{{ demandOn(d)!.job_hours }} / {{ demandOn(d)!.rostered_hours }} h</div>
                  <div v-if="demandOn(d)!.jobs || demandOn(d)!.activities">{{ demandOn(d)!.jobs }} job(s)<template v-if="demandOn(d)!.activities"> · {{ demandOn(d)!.activities }} field</template></div>
                  <VChip v-for="h in demandOn(d)!.harvests" :key="h" size="x-small" color="success" class="ma-1">Harvest {{ h }}</VChip>
                </template>
              </td>
            </tr>
            <tr v-if="!workers.length"><td :colspan="days.length + 1" class="text-center text-medium-emphasis py-6">No active people here yet.</td></tr>
          </tbody>
        </VTable>
      </div>
    </VCard>

    <VDialog :model-value="!!selected" max-width="640" @update:model-value="v => !v && (selected = null)">
      <VCard v-if="selected">
        <VCardItem>
          <VCardTitle>{{ selected.title }} · {{ dayLabel(selected.date) }} {{ selected.label }}</VCardTitle>
          <VCardSubtitle><VChip :color="statusColor(selected.status)" size="x-small">{{ human(selected.status) }}</VChip><template v-if="selected.revision"> · revision {{ selected.revision }}</template>
            <template v-if="selected.required_certifications.length"> · needs {{ selected.required_certifications.join(', ') }}</template></VCardSubtitle>
        </VCardItem>
        <VCardText>
          <VAlert v-for="g in gapFor(selected)" :key="g" type="warning" variant="tonal" density="compact" class="mb-2">{{ g }}</VAlert>
          <VList density="compact">
            <VListItem v-for="a in selected.assignments.filter(x => x.status === 'assigned')" :key="a.id" :title="a.name" :subtitle="a.warnings ?? undefined">
              <template #append><VBtn v-if="can.manage" icon size="x-small" variant="text" @click="remove(a)"><VIcon icon="tabler-x" /></VBtn></template>
            </VListItem>
          </VList>
          <template v-if="can.manage && selected.status !== 'cancelled'">
            <div v-if="crews.length" class="d-flex gap-2 align-center mt-3">
              <VSelect v-model="crewPick" :items="crews.map(c => ({ title: `${c.code} · ${c.name} (${c.workers})`, value: c.id }))" label="Add a whole crew" density="compact" hide-details clearable />
              <VBtn :disabled="!crewPick" variant="tonal" @click="assignCrew">Add crew</VBtn>
            </div>
            <VAutocomplete v-model="picks" :items="workers.map(w => ({ title: w.name, value: w.id }))" label="Add people" multiple chips closable-chips class="mt-3" @update:model-value="checkPicks" />
            <div v-for="(p, id) in problems" :key="id">
              <VAlert v-for="(x, i) in p" :key="i" :type="x.level === 'block' ? 'error' : 'warning'" variant="tonal" density="compact" class="mb-1">
                {{ workers.find(w => w.id === Number(id))?.name }}: {{ x.message }}
              </VAlert>
            </div>
          </template>
          <div v-if="history.length" class="mt-4">
            <div class="text-caption font-weight-medium">Earlier versions</div>
            <div v-for="h in history" :key="h.revision" class="text-caption">r{{ h.revision }} {{ h.change }}: was {{ h.before.starts_at?.slice(11, 16) }}–{{ h.before.ends_at?.slice(11, 16) }}, {{ h.before.workers.length }} people<template v-if="h.reason"> — {{ h.reason }}</template></div>
          </div>
        </VCardText>
        <VCardActions>
          <VBtn v-if="can.manage && selected.status !== 'cancelled'" variant="text" color="error" @click="cancelShift">Cancel shift</VBtn>
          <VBtn v-if="can.manage && selected.status !== 'cancelled'" variant="text" @click="editOpen = true">Change times</VBtn>
          <VSpacer />
          <VBtn variant="text" @click="selected = null">Close</VBtn>
          <VBtn v-if="can.manage && picks.length" color="primary" :disabled="hasBlocks" @click="assign">{{ hasWarnings ? 'Assign anyway' : 'Assign' }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <FormDialog v-if="selected" :model-value="editOpen" :title="`Change ${selected.title}`" method="patch" :url="route('attendance.roster.shifts.update', selected.uuid)"
      :values="{ starts_at: selected.starts_at.slice(0, 16), ends_at: selected.ends_at.slice(0, 16) }" :fields="[
        { key: 'starts_at', label: 'Starts', type: 'datetime', required: true }, { key: 'ends_at', label: 'Ends', type: 'datetime', required: true },
        { key: 'reason', label: 'Reason (told to the people on it)', cols: 12, show: () => selected?.status === 'published' },
      ]" @update:model-value="v => { editOpen = v; if (!v) selected = null }" />

    <FormDialog v-model="addShiftOpen" title="Add a shift" :url="route('attendance.roster.shifts.store')" :values="{ farm_id: farm?.id ?? null, date: period.from }" :fields="[
      { key: 'template_id', label: 'From a template', type: 'select', items: options.templates.map(t => ({ title: `${t.code} · ${t.name} ${t.starts.slice(0, 5)}–${t.ends.slice(0, 5)}`, value: t.id })), cols: 8 },
      { key: 'date', label: 'Date', type: 'date', cols: 4, show: (f: any) => !!f.template_id },
      { key: 'title', label: 'Title', show: (f: any) => !f.template_id, cols: 12 },
      { key: 'starts_at', label: 'Starts', type: 'datetime', show: (f: any) => !f.template_id }, { key: 'ends_at', label: 'Ends', type: 'datetime', show: (f: any) => !f.template_id },
      { key: 'break_minutes', label: 'Unpaid break (min)', type: 'number', show: (f: any) => !f.template_id },
      { key: 'min_staff', label: 'People needed', type: 'number', show: (f: any) => !f.template_id },
      { key: 'required_certifications', label: 'Required competencies', type: 'multiselect', items: options.competencies, show: (f: any) => !f.template_id, cols: 12 },
      { key: 'field_id', label: 'Field / camp', type: 'select', items: (options.farms.find((x: any) => x.id === farm?.id)?.fields ?? []).map((x: any) => ({ title: x.code + ' · ' + x.name, value: x.id })) },
    ]" />

    <VDialog v-model="rotateOpen" max-width="760">
      <VCard title="Rotating roster">
        <VCardText>
          <p class="text-body-2 text-medium-emphasis">Build the cycle, choose who rotates and where each starts in the cycle. Shifts are drafted for checking, then published.</p>
          <div class="d-flex flex-wrap gap-2 mb-3 align-center">
            <VChip v-for="(c, i) in cycle" :key="i" closable :color="c === 'OFF' ? undefined : 'primary'" variant="tonal" @click:close="cycle.splice(i, 1)">{{ i + 1 }}: {{ c }}</VChip>
            <VMenu><template #activator="{ props: p }"><VBtn v-bind="p" size="small" variant="text" prepend-icon="tabler-plus">Day</VBtn></template>
              <VList density="compact"><VListItem title="OFF (rest day)" @click="cycle.push('OFF')" /><VListItem v-for="t in options.templates" :key="t.id" :title="`${t.code} · ${t.name}`" @click="cycle.push(t.code)" /></VList>
            </VMenu>
          </div>
          <VRow><VCol cols="6"><VTextField v-model="rotateFrom" type="date" label="From" /></VCol><VCol cols="6"><VTextField v-model.number="rotateDays" type="number" label="For how many days" /></VCol></VRow>
          <VSelect v-if="crews.length" v-model="rotateCrew" :items="crews.map(c => ({ title: `${c.code} · ${c.name} (${c.workers})`, value: c.id }))" label="Whole crew (instead of choosing people)" clearable class="mb-2" />
          <VTable v-if="!rotateCrew" density="compact">
            <thead><tr><th /><th>Worker</th><th>Starts at cycle day</th></tr></thead>
            <tbody><tr v-for="w in workers" :key="w.id">
              <td><VCheckbox :model-value="rotateWorkers.some(x => x.id === w.id)" hide-details density="compact" @update:model-value="toggleRotate(w.id)" /></td>
              <td>{{ w.name }}</td>
              <td><VTextField v-if="rotateWorkers.some(x => x.id === w.id)" :model-value="(rotateWorkers.find(x => x.id === w.id)?.offset ?? 0) + 1" type="number" min="1" :max="cycle.length" density="compact" hide-details style="max-width: 90px"
                @update:model-value="(v: any) => { const r = rotateWorkers.find(x => x.id === w.id); if (r) r.offset = Math.max(0, Number(v) - 1) }" /></td>
            </tr></tbody>
          </VTable>
        </VCardText>
        <VCardText class="pt-0">
          <div class="text-caption font-weight-medium mb-1">Keep this cycle for a season or production cycle</div>
          <div class="d-flex gap-2 align-center">
            <VTextField v-model="keepAs" label="Pattern name" density="compact" hide-details placeholder="Citrus harvest crew A" />
            <VTextField v-model="keepSeason" label="Season" density="compact" hide-details placeholder="Harvest" style="max-width: 160px" />
            <VBtn variant="tonal" :disabled="!keepAs || !cycle.length || (!rotateCrew && !rotateWorkers.length)" @click="savePattern">Keep</VBtn>
          </div>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="rotateOpen = false">Cancel</VBtn><VBtn color="primary" :disabled="!cycle.length || (!rotateCrew && !rotateWorkers.length)" @click="rotateWithCrew">Draft roster</VBtn></VCardActions>
      </VCard>
    </VDialog>
    <VDialog v-model="patternsOpen" max-width="680">
      <VCard title="Roster patterns">
        <VCardText>
          <p class="text-body-2 text-medium-emphasis">Cycles kept for seasons and production cycles. Applying one drafts its shifts for the period; check and publish as usual.</p>
          <VRow><VCol cols="6"><VTextField v-model="applyFrom" type="date" label="From" /></VCol><VCol cols="6"><VTextField v-model.number="applyDays" type="number" label="For how many days" /></VCol></VRow>
          <VList lines="two">
            <VListItem v-for="p in patterns" :key="p.id" :title="`${p.name}${p.season ? ' · ' + p.season : ''}`" :subtitle="`${p.cycle.join(' → ')} · ${p.crew ? 'crew ' + p.crew : p.people + ' people'}`">
              <template #append>
                <VBtn size="small" color="primary" variant="tonal" class="me-1" @click="applyPattern(p)">Apply</VBtn>
                <VBtn size="small" variant="text" icon="tabler-archive" @click="retirePattern(p)" />
              </template>
            </VListItem>
            <VListItem v-if="!patterns.length" title="No patterns yet" subtitle="Build a rotating roster and keep it under a name." />
          </VList>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="patternsOpen = false">Close</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
