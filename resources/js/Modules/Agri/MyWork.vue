<script setup lang="ts">
import PageErrors from './Components/PageErrors.vue'
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { human, statusColor, today } from './Components/agri'
import { enqueue, flush, uuid, isOffline, readQueue, registerAgriServiceWorker, type QueuedOp, type SyncResult } from './Components/offlineQueue'

/**
 * Built for a phone in the field: big targets, one column, the day's work first — and it keeps
 * working without signal. Offline, every action is queued on the phone and shown as "waiting";
 * when the phone is back online the queue is sent and the screen refreshes from the server.
 */
const props = defineProps<{
  tasks: any[]; activities: any[]
  farms: Array<{ id: string; name: string; fields: Array<{ id: string; code: string; name: string }> }>
  me: number
  can: { observe: boolean }
  worker: null | { name: string; clocked_in_at: string | null; clocked_in_time: string | null; gps_allowed: boolean; farm_id: string | null; farm: string | null }
  shifts: Array<{ title: string; when: string }>
  jobs: any[]
  rates: Array<{ activity: string; unit: string; rate: number }>
}>()

const online = ref(!isOffline())
const queue = ref<QueuedOp[]>([])
const results = ref<SyncResult[]>([])
const syncing = ref(false)
const pending = computed(() => new Map(queue.value.map(q => [q.payload.id, q.op])))
const refreshQueue = () => { queue.value = readQueue(props.me) }

async function sync() {
  refreshQueue()
  if (!queue.value.length || syncing.value) return
  syncing.value = true
  const r = await flush(props.me)
  syncing.value = false
  refreshQueue()
  // No answer at all means no connection, whatever the browser believes: phones with bars but no
  // data look "online" to it. Keep the queue and say so.
  online.value = r !== null
  if (r && r.length) {
    results.value = r
    router.reload({ preserveScroll: true })
  }
}
const goOnline = () => { online.value = true; sync() }
const goOffline = () => { online.value = false }
let retry: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  registerAgriServiceWorker()
  window.addEventListener('online', goOnline)
  window.addEventListener('offline', goOffline)
  // Try again every 30 seconds while something is waiting, so a phone that walks back into
  // signal sends its work without anybody pressing anything.
  retry = setInterval(() => { if (readQueue(props.me).length) sync() }, 30000)
  sync()
})
onBeforeUnmount(() => {
  window.removeEventListener('online', goOnline)
  window.removeEventListener('offline', goOffline)
  clearInterval(retry)
})

/**
 * Every action on this screen is put in the phone's queue first and sent straight away. With a
 * connection it is applied within a second; without one it waits, visibly, and goes when the phone
 * is back in signal. One path for both, so nothing depends on guessing whether the phone is online.
 * The server applies queued changes under exactly the same rules as the full screens.
 */
function act(op: string, payload: Record<string, any>, label: string) {
  enqueue(props.me, op, payload, label)
  refreshQueue()
  sync()
}

// ── Clock, jobs and piecework (for people registered as farm workers) ─────────────────────────
// A clock tap queued offline keeps the moment it was tapped: the server places it by captured_at.
const lastClock = computed(() => [...queue.value].reverse().find(q => q.op === 'clock.in' || q.op === 'clock.out')?.op)
const clockedIn = computed(() => lastClock.value ? lastClock.value === 'clock.in' : !!props.worker?.clocked_in_at)
const locating = ref(false)
function position(): Promise<{ latitude?: number; longitude?: number }> {
  if (!props.worker?.gps_allowed || !navigator.geolocation) return Promise.resolve({})
  locating.value = true
  return new Promise(resolve => navigator.geolocation.getCurrentPosition(
    p => { locating.value = false; resolve({ latitude: p.coords.latitude, longitude: p.coords.longitude }) },
    () => { locating.value = false; resolve({}) }, // no fix: clock anyway, the server flags it unverified
    { timeout: 8000, maximumAge: 60000, enableHighAccuracy: true }))
}
async function clock() {
  const op = clockedIn.value ? 'clock.out' : 'clock.in'
  const where = await position()
  act(op, { event_id: uuid(), method: 'mobile', offline: !online.value, ...where }, `${op === 'clock.in' ? 'Clock in' : 'Clock out'} ${new Date().toLocaleTimeString('en-ZA', { hour: '2-digit', minute: '2-digit' })}`)
}
const jobPending = (j: any) => pending.value.get(j.id)
const startJob = (j: any) => act('wo.start', { id: j.id }, `Start job ${j.reference}`)

const mapUrl = (j: any) => j.map ? `https://www.google.com/maps/dir/?api=1&destination=${j.map.lat},${j.map.lng}` : null
const ISSUES = [{ title: 'Incident (someone hurt, near miss)', value: 'incident' }, { title: 'Hazard', value: 'hazard' }, { title: 'Crop disease', value: 'disease' },
  { title: 'Pest', value: 'pest' }, { title: 'Equipment failure', value: 'equipment_failure' }, { title: 'Other problem', value: 'other' }]

/** A PDF goes as it is (up to 8 MB); pictures are shrunk first. */
function readFile(file: File): Promise<{ photo_name: string; photo_mime: string; photo_data: string }> {
  if (file.type.startsWith('image/')) return shrink(file)
  if (file.type !== 'application/pdf' || file.size > 8 * 1024 * 1024) return Promise.reject(new Error('type'))
  return new Promise((resolve, reject) => {
    const r = new FileReader()
    r.onload = () => resolve({ photo_name: file.name, photo_mime: 'application/pdf', photo_data: String(r.result).split(',')[1] })
    r.onerror = reject
    r.readAsDataURL(file)
  })
}

/** Phone photos are shrunk before they are queued: the queue lives in the phone's storage. */
function shrink(file: File, max = 1280): Promise<{ photo_name: string; photo_mime: string; photo_data: string }> {
  return new Promise((resolve, reject) => {
    const img = new Image()
    img.onload = () => {
      const k = Math.min(1, max / Math.max(img.width, img.height))
      const c = document.createElement('canvas')
      c.width = Math.round(img.width * k); c.height = Math.round(img.height * k)
      c.getContext('2d')!.drawImage(img, 0, 0, c.width, c.height)
      URL.revokeObjectURL(img.src)
      resolve({ photo_name: file.name.replace(/\.\w+$/, '') + '.jpg', photo_mime: 'image/jpeg', photo_data: c.toDataURL('image/jpeg', 0.75).split(',')[1] })
    }
    img.onerror = reject
    img.src = URL.createObjectURL(file)
  })
}

const startTask = (t: any) => act('task.start', { id: t.id }, `Start: ${t.title}`)
const startActivity = (a: any) => act('activity.start', { id: a.id }, `Start: ${a.title}`)

// ── Dialogs ──────────────────────────────────────────────────────────────────────────────────
type Dialog = 'submit' | 'complete' | 'observe' | 'job-log' | 'job-pause' | 'job-submit' | 'piece'
const dialog = ref<null | Dialog>(null)
const target = ref<any>(null)
const form = reactive<Record<string, any>>({})
const errors = ref<Record<string, string>>({})
function open(kind: Dialog, t: any = null) {
  target.value = t
  errors.value = {}
  Object.keys(form).forEach(k => delete form[k])
  if (kind === 'observe') Object.assign(form, { farm_id: props.farms[0]?.id ?? null, field_id: null, kind: 'pest', severity: 'medium', description: '' })
  if (kind === 'job-log') Object.assign(form, { kind: 'progress' })
  if (kind === 'piece') Object.assign(form, { rate: props.rates[0] ? 0 : null })
  dialog.value = kind
}
const photoFile = ref<File[] | File | null>(null)
async function save() {
  const t = target.value
  if (dialog.value === 'job-log') {
    const file = Array.isArray(photoFile.value) ? photoFile.value[0] : photoFile.value
    let photo = {}
    try { if (file) photo = await readFile(file) } catch { errors.value = { photo: 'Attach a picture, or a PDF of up to 8 MB.' }; return }
    const where = ['issue', 'progress'].includes(form.kind) ? await position() : {}
    act('wo.log', { id: t.id, log_id: uuid(), kind: form.kind, minutes: form.minutes ?? null, quantity: form.quantity ?? null,
      material_id: form.material_id ?? null, machine_id: form.machine_id ?? null, machine_hours: form.machine_hours ?? null,
      note: form.note ?? null, issue_type: form.kind === 'issue' ? (form.issue_type ?? 'other') : null, severity: form.kind === 'issue' ? (form.severity ?? null) : null,
      ...photo, ...where }, `${form.kind === 'issue' ? human(form.issue_type ?? 'problem') : human(form.kind)} on ${t.reference}`)
    photoFile.value = null
  } else if (dialog.value === 'job-pause') {
    if (!form.note) { errors.value = { note: 'Say why the job stopped.' }; return }
    act('wo.pause', { id: t.id, note: form.note }, `Pause job ${t.reference}`)
  } else if (dialog.value === 'job-submit') {
    act('wo.submit', { id: t.id, actual_output: form.actual_output ?? null, note: form.note ?? null }, `Finished job ${t.reference}`)
  } else if (dialog.value === 'piece') {
    const r = props.rates[form.rate]
    if (!r || !(form.quantity > 0)) { errors.value = { quantity: 'Choose the work and enter how much.' }; return }
    act('piecework.submit', { entry_id: uuid(), farm_id: props.worker?.farm_id, activity: r.activity, unit: r.unit, quantity: form.quantity },
      `${form.quantity} ${r.unit} ${human(r.activity)}`)
  } else if (dialog.value === 'submit') {
    act('task.submit', { id: t.id, labour_hours: form.labour_hours ?? null, completion_notes: form.completion_notes ?? null }, `Done: ${t.title}`)
  } else if (dialog.value === 'complete') {
    act('activity.complete', { id: t.id, ...form }, `Complete: ${t.title}`)
  } else if (dialog.value === 'observe') {
    if (!form.description || !form.farm_id) { errors.value = { description: 'Choose the farm and describe what you saw.' }; return }
    act('observation.create', { ...form }, `Report: ${human(form.kind)} (${form.severity})`)
  }
  dialog.value = null
}
const fields = computed(() => props.farms.find(f => f.id === form.farm_id)?.fields ?? [])
const jobTitle = computed(() => ({ 'job-log': 'Record on', 'job-pause': 'Pause', 'job-submit': 'Finished' } as Record<string, string>)[dialog.value ?? ''])
const dialogTitle = computed(() => dialog.value === 'observe' ? 'Report what you saw' : dialog.value === 'piece' ? 'Record piecework'
  : jobTitle.value ? `${jobTitle.value}: ${target.value?.reference}` : `${dialog.value === 'submit' ? 'Done' : 'Complete'}: ${target.value?.title}`)
const opts = (xs: string[]) => xs.map(x => ({ title: human(x), value: x }))
</script>

<template>
  <div class="mx-auto" style="max-width: 640px">
    <PageErrors />
    <VAlert v-if="!online" type="warning" variant="tonal" class="mb-3" icon="tabler-wifi-off">
      You are offline. Keep working — changes are saved on this phone and sent when you have signal.
    </VAlert>
    <VAlert v-if="queue.length && !syncing" :type="online ? 'info' : 'warning'" variant="tonal" class="mb-3" icon="tabler-cloud-upload">
      <div class="d-flex align-center justify-space-between flex-wrap gap-2">
        <span>{{ queue.length }} change{{ queue.length === 1 ? '' : 's' }} waiting to send</span>
        <VBtn v-if="online" size="small" variant="flat" color="primary" :loading="syncing" @click="sync">Send now</VBtn>
      </div>
      <div v-for="q in queue" :key="q.client_id" class="text-caption">• {{ q.label }}</div>
    </VAlert>
    <VAlert v-if="results.length" type="success" variant="tonal" class="mb-3" closable @click:close="results = []">
      <div class="font-weight-medium">Saved:</div>
      <div v-for="r in results" :key="r.client_id" class="text-caption" :class="r.status === 'rejected' ? 'text-error' : ''">
        {{ r.status === 'applied' ? '✓' : '✗ Not accepted:' }} {{ r.message }}
      </div>
    </VAlert>

    <div class="d-flex align-center justify-space-between mb-4">
      <div>
        <h4 class="text-h4 mb-0">My Work</h4>
        <div class="text-body-2 text-medium-emphasis">{{ new Date().toLocaleDateString('en-ZA', { weekday: 'long', day: 'numeric', month: 'long' }) }}</div>
      </div>
      <VBtn v-if="can.observe" color="warning" size="large" prepend-icon="tabler-bug" @click="open('observe')">Report</VBtn>
    </div>

    <template v-if="worker">
      <VCard class="mb-4" :color="clockedIn ? 'success' : undefined" variant="tonal">
        <VCardText class="d-flex align-center justify-space-between flex-wrap gap-3">
          <div>
            <div class="text-h6">{{ clockedIn ? 'On the clock' : 'Not clocked in' }}</div>
            <div class="text-body-2">{{ worker.name }}<template v-if="worker.farm"> · {{ worker.farm }}</template>
              <template v-if="clockedIn && worker.clocked_in_at && !lastClock"> · since {{ worker.clocked_in_time }}</template></div>
            <div v-if="shifts.length" class="text-caption mt-1">Shift: {{ shifts[0].when }} · {{ shifts[0].title }}</div>
          </div>
          <VBtn size="x-large" :color="clockedIn ? 'error' : 'success'" variant="flat" :loading="locating"
            :prepend-icon="clockedIn ? 'tabler-logout' : 'tabler-login'" @click="clock">{{ clockedIn ? 'Clock out' : 'Clock in' }}</VBtn>
        </VCardText>
      </VCard>
      <VExpansionPanels v-if="shifts.length > 1" class="mb-4"><VExpansionPanel :title="`My shifts this week (${shifts.length})`">
        <VExpansionPanelText><div v-for="(x, i) in shifts" :key="i" class="text-body-2">{{ x.when }} · {{ x.title }}</div></VExpansionPanelText>
      </VExpansionPanel></VExpansionPanels>

      <h6 class="text-h6 mb-2">Jobs <VChip size="x-small">{{ jobs.length }}</VChip></h6>
      <VCard v-for="j in jobs" :key="j.id" class="mb-3">
        <VCardItem>
          <VCardTitle class="text-wrap">{{ j.reference }} · {{ j.title }}</VCardTitle>
          <VCardSubtitle>{{ j.farm }} · {{ j.when }}</VCardSubtitle>
          <template #append>
            <VChip v-if="jobPending(j)" color="warning" size="small" variant="tonal" prepend-icon="tabler-clock">Waiting to send</VChip>
            <VChip v-else :color="statusColor(j.status)" size="small" variant="tonal">{{ human(j.status) }}</VChip>
          </template>
        </VCardItem>
        <VCardText v-if="j.instructions || j.materials.length || j.machines.length">
          <div v-if="j.instructions" class="mb-1">{{ j.instructions }}</div>
          <div v-for="m in j.materials" :key="'m' + m.id" class="text-body-2"><VIcon icon="tabler-package" size="14" /> {{ m.label }}</div>
          <div v-for="m in j.machines" :key="'x' + m.id" class="text-body-2"><VIcon icon="tabler-tractor" size="14" /> {{ m.label }}</div>
        </VCardText>
        <VCardText v-if="mapUrl(j)" class="pt-0"><a :href="mapUrl(j)!" target="_blank" rel="noopener"><VBtn size="small" variant="tonal" prepend-icon="tabler-navigation">Navigate to the field</VBtn></a></VCardText>
        <VCardActions v-if="jobPending(j) !== 'wo.submit'" class="flex-wrap">
          <VBtn v-if="['approved', 'paused', 'rejected'].includes(j.status) && jobPending(j) !== 'wo.start'" size="large" variant="tonal" prepend-icon="tabler-player-play" @click="startJob(j)">{{ j.status === 'approved' ? 'Start' : 'Resume' }}</VBtn>
          <template v-if="j.status === 'in_progress' || jobPending(j) === 'wo.start'">
            <VBtn size="large" variant="tonal" prepend-icon="tabler-notes" @click="open('job-log', j)">Record</VBtn>
            <VBtn size="large" variant="text" prepend-icon="tabler-player-pause" @click="open('job-pause', j)">Pause</VBtn>
            <VSpacer />
            <VBtn size="large" color="success" variant="flat" prepend-icon="tabler-check" @click="open('job-submit', j)">Finished</VBtn>
          </template>
        </VCardActions>
        <VCardText v-else class="text-caption">Sent to the supervisor to check.</VCardText>
      </VCard>
      <VCard v-if="!jobs.length" class="mb-3"><VCardText class="text-center text-medium-emphasis">No jobs assigned to you.</VCardText></VCard>

      <VBtn v-if="rates.length && worker.farm_id" block size="large" variant="tonal" color="primary" class="mb-6" prepend-icon="tabler-scale" @click="open('piece')">Record piecework</VBtn>
    </template>

    <h6 class="text-h6 mb-2">Tasks <VChip size="x-small">{{ tasks.length }}</VChip></h6>
    <VCard v-for="t in tasks" :key="t.id" class="mb-3" :color="t.overdue ? 'error' : undefined" :variant="t.overdue ? 'tonal' : 'elevated'">
      <VCardItem>
        <VCardTitle class="text-wrap">{{ t.title }}</VCardTitle>
        <VCardSubtitle>{{ t.farm }}<template v-if="t.field"> · {{ t.field }}</template><template v-if="t.due_on"> · due {{ t.due_on }}</template></VCardSubtitle>
        <template #append>
          <VChip v-if="pending.has(t.id)" color="warning" size="small" variant="tonal" prepend-icon="tabler-clock">Waiting to send</VChip>
          <VChip v-else :color="statusColor(t.status)" size="small" variant="tonal">{{ human(t.status) }}</VChip>
        </template>
      </VCardItem>
      <VCardText v-if="t.description || t.review_note">
        <div v-if="t.description">{{ t.description }}</div>
        <VAlert v-if="t.status === 'rejected'" type="error" variant="tonal" density="compact" class="mt-2">Redo: {{ t.review_note }}</VAlert>
      </VCardText>
      <VCardActions v-if="t.status !== 'submitted' && pending.get(t.id) !== 'task.submit'">
        <VBtn v-if="['open', 'rejected'].includes(t.status) && !pending.has(t.id)" size="large" variant="tonal" prepend-icon="tabler-player-play" @click="startTask(t)">Start</VBtn>
        <VSpacer />
        <VBtn size="large" color="success" variant="flat" prepend-icon="tabler-check" @click="open('submit', t)">Done</VBtn>
      </VCardActions>
      <VCardText v-else class="text-caption">Submitted — waiting for sign-off.</VCardText>
    </VCard>
    <VCard v-if="!tasks.length" class="mb-3"><VCardText class="text-center text-medium-emphasis">No tasks assigned to you.</VCardText></VCard>

    <h6 class="text-h6 mt-6 mb-2">Field activities <VChip size="x-small">{{ activities.length }}</VChip></h6>
    <VCard v-for="a in activities" :key="a.id" class="mb-3">
      <VCardItem>
        <VCardTitle class="text-wrap">{{ a.title }}</VCardTitle>
        <VCardSubtitle>{{ human(a.type) }} · {{ a.farm }}<template v-if="a.field"> · {{ a.field }}</template> · {{ a.planned_on }}</VCardSubtitle>
        <template #append><VChip v-if="pending.has(a.id)" color="warning" size="small" variant="tonal" prepend-icon="tabler-clock">Waiting to send</VChip></template>
      </VCardItem>
      <VCardText v-if="a.inputs.length"><div v-for="(i, k) in a.inputs" :key="k" class="text-body-2"><VIcon icon="tabler-package" size="14" /> {{ i }}</div></VCardText>
      <VCardActions v-if="pending.get(a.id) !== 'activity.complete'">
        <VBtn v-if="a.status === 'planned' && !pending.has(a.id)" size="large" variant="tonal" prepend-icon="tabler-player-play" @click="startActivity(a)">Start</VBtn>
        <VSpacer />
        <VBtn size="large" color="success" variant="flat" prepend-icon="tabler-check" @click="open('complete', a)">Complete</VBtn>
      </VCardActions>
    </VCard>
    <VCard v-if="!activities.length"><VCardText class="text-center text-medium-emphasis">No field activities assigned to you.</VCardText></VCard>

    <VDialog :model-value="!!dialog" max-width="560" @update:model-value="v => !v && (dialog = null)">
      <VCard :title="dialogTitle">
        <VCardText>
          <p v-if="!online" class="text-body-2 text-warning">Offline — this is saved on the phone and sent later.</p>
          <template v-if="dialog === 'submit'">
            <VTextField v-model.number="form.labour_hours" type="number" label="Hours spent" class="mb-3" :error-messages="errors.labour_hours" />
            <VTextarea v-model="form.completion_notes" label="What was done" rows="2" :error-messages="errors.completion_notes" />
          </template>
          <template v-else-if="dialog === 'complete'">
            <VRow dense>
              <VCol cols="6"><VTextField v-model.number="form.area_covered" type="number" label="Area covered (ha)" /></VCol>
              <VCol cols="6"><VTextField v-model.number="form.labour_hours" type="number" label="Hours" /></VCol>
              <VCol cols="6"><VTextField v-model.number="form.machine_hours" type="number" label="Machine hours" /></VCol>
              <VCol cols="6"><VTextField v-model="form.weather" label="Weather" /></VCol>
              <VCol cols="12"><VTextarea v-model="form.notes" label="Notes" rows="2" /></VCol>
            </VRow>
            <VAlert v-if="errors.inputs" type="error" variant="tonal" density="compact">{{ errors.inputs }}</VAlert>
          </template>
          <template v-else-if="dialog === 'job-log'">
            <VSelect v-model="form.kind" label="What" :items="[{ title: 'Progress', value: 'progress' }, { title: 'Material used', value: 'material' }, { title: 'Machine hours', value: 'machine' }, { title: 'Problem / incident', value: 'issue' }, { title: 'Ask for help or materials', value: 'request' }]" class="mb-2" />
            <VRow dense>
              <VCol v-if="form.kind === 'issue'" cols="8"><VSelect v-model="form.issue_type" :items="ISSUES" label="Kind of problem" /></VCol>
              <VCol v-if="form.kind === 'issue'" cols="4"><VSelect v-model="form.severity" :items="[{ title: 'Low', value: 'low' }, { title: 'Medium', value: 'medium' }, { title: 'High', value: 'high' }]" label="How serious" /></VCol>
              <VCol v-if="form.kind === 'progress'" cols="6"><VTextField v-model.number="form.minutes" type="number" label="Minutes worked" /></VCol>
              <VCol v-if="['progress', 'material'].includes(form.kind)" cols="6"><VTextField v-model.number="form.quantity" type="number" label="Quantity" /></VCol>
              <VCol v-if="form.kind === 'material'" cols="12"><VSelect v-model="form.material_id" label="Material" :items="target.materials.map((m: any) => ({ title: m.label, value: m.id }))" /></VCol>
              <VCol v-if="form.kind === 'machine' || form.issue_type === 'equipment_failure'" cols="8"><VSelect v-model="form.machine_id" label="Machine" :items="target.machines.map((m: any) => ({ title: m.label, value: m.id }))" /></VCol>
              <VCol v-if="form.kind === 'machine'" cols="4"><VTextField v-model.number="form.machine_hours" type="number" label="Hours" /></VCol>
              <VCol cols="12"><VTextarea v-model="form.note" label="Note" rows="2" /></VCol>
              <VCol cols="12"><VFileInput v-model="photoFile" label="Photo or PDF" accept="image/*,application/pdf" prepend-icon="tabler-camera" :error-messages="errors.photo" /></VCol>
            </VRow>
          </template>
          <template v-else-if="dialog === 'job-pause'">
            <VTextarea v-model="form.note" label="Why did the job stop? *" rows="2" :error-messages="errors.note" />
          </template>
          <template v-else-if="dialog === 'job-submit'">
            <VTextField v-model.number="form.actual_output" type="number" label="How much was done (ha, kg, rows…)" class="mb-2" />
            <VTextarea v-model="form.note" label="Notes for the supervisor" rows="2" />
          </template>
          <template v-else-if="dialog === 'piece'">
            <VSelect v-model="form.rate" label="Work *" :items="rates.map((r, i) => ({ title: `${human(r.activity)} · per ${r.unit}`, value: i }))" class="mb-2" />
            <VTextField v-model.number="form.quantity" type="number" :label="`How many ${rates[form.rate]?.unit ?? ''} *`" :error-messages="errors.quantity" />
            <p class="text-caption mb-0">A supervisor checks the count before it is paid.</p>
          </template>
          <template v-else-if="dialog === 'observe'">
            <VSelect v-model="form.farm_id" label="Farm *" :items="farms.map(f => ({ title: f.name, value: f.id }))" class="mb-2" />
            <VSelect v-model="form.field_id" label="Field / camp" :items="fields.map(x => ({ title: `${x.code} · ${x.name}`, value: x.id }))" clearable class="mb-2" />
            <VSelect v-model="form.kind" label="What *" :items="opts(['pest', 'disease', 'weed', 'growth_stage', 'nutrient', 'water_stress', 'weather_damage', 'other'])" class="mb-2" />
            <VSelect v-model="form.severity" label="How bad *" :items="opts(['low', 'medium', 'high', 'critical'])" class="mb-2" />
            <VTextarea v-model="form.description" label="Describe it *" rows="3" :error-messages="errors.description" />
          </template>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = null">Cancel</VBtn>
          <VBtn color="primary" variant="flat" @click="save">{{ online ? 'Save' : 'Save on phone' }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
