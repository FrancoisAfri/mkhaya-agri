<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import axios from 'axios'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { useOfflineQueue } from '@/composables/useOfflineQueue'

/**
 * The workstation terminal (MFG-007). Built for a tablet at a machine: big targets, a scan box a
 * keyboard-wedge scanner can type into, and taps that are queued with their key when the network
 * drops, then replayed -- the server applies each one once.
 */
const { t } = useI18n()
const route = ziggyRoute
const { isOnline, pendingCount, enqueue } = useOfflineQueue()

type Job = {
  id: string; sequence: number; name: string; status: string
  order: { id: string; number: string; priority: number; due_date: string | null; is_overdue: boolean; product: string; planned_qty: number; qty_completed: number }
  work_centre: string | null; assignee: string | null; assigned_to: number | null; is_gate: boolean; gate_status: string | null
  planned_minutes: number; started_at: string | null; blocked_by: string[]; job_code: string
}
type Detail = Job & {
  instructions: string | null
  documents: Array<{ kind: string; title: string; reference: string | null; url: string | null }>
  components: Array<{ id: string; code: string | null; name: string | null; uom: string | null; required: number; issued: number; outstanding: number }>
  logged_minutes: number
}
type Stoppage = { id: string; reason: string | null; is_planned: boolean; started_at: string | null; minutes: number; notes: string | null; is_open: boolean }

const props = defineProps<{
  work_centres: Array<{ id: number; code: string; name: string; factory: string | null; machines: Array<{ id: number; name: string }> }>
  reasons: Array<{ id: number; name: string; is_planned: boolean }>
  people: Array<{ id: number; name: string }>
  escalation_kinds: string[]
  me: number
  can: { execute: boolean; issue: boolean; supervise: boolean; override: boolean }
}>()

// ── Where am I ──────────────────────────────────────────────────────────────
const STORE = 'mfg.floor.centre'
const read = (k: string) => { try { return localStorage.getItem(k) } catch { return null } }
const write = (k: string, v: string) => { try { localStorage.setItem(k, v) } catch { /* private mode */ } }
const saved = Number(read(STORE)) || null
const centre = ref<number | null>(props.work_centres.some(c => c.id === saved) ? saved : (props.work_centres[0]?.id ?? null))
const mine = ref(false)
watch(centre, v => { if (v) write(STORE, String(v)); load() })
watch(mine, () => load())
const machines = computed(() => props.work_centres.find(c => c.id === centre.value)?.machines ?? [])

// ── Queue ───────────────────────────────────────────────────────────────────
const jobs = ref<Job[]>([])
const stoppages = ref<Stoppage[]>([])
const loading = ref(false)
const orderFilter = ref<string | null>(null)
const shown = computed(() => orderFilter.value ? jobs.value.filter(j => j.order.id === orderFilter.value) : jobs.value)

async function load() {
  if (!isOnline.value) return
  loading.value = true
  try {
    const { data } = await axios.get(route('manufacturing.floor.queue'), { params: { work_centre: mine.value ? undefined : centre.value, mine: mine.value ? 1 : undefined } })
    jobs.value = data.jobs
    stoppages.value = data.downtime
  } finally { loading.value = false }
}

let timer: ReturnType<typeof setInterval> | undefined
onMounted(() => { load(); timer = setInterval(load, 30000) })
onBeforeUnmount(() => clearInterval(timer))

// ── Acting: once per key, queued when offline ───────────────────────────────
const toast = ref<{ text: string; color: string } | null>(null)
const busy = ref(false)
const newKey = () => globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random()}`

async function act(url: string, data: Record<string, unknown>, label: string): Promise<boolean> {
  const key = newKey()
  busy.value = true
  try {
    if (!isOnline.value) throw new Error('offline')
    await axios.post(url, data, { headers: { 'Idempotency-Key': key } })
    toast.value = { text: t('manufacturing.floor.done'), color: 'success' }
    await load()
    return true
  } catch (e: any) {
    if (e?.response?.status === 422) {
      toast.value = { text: e.response.data?.message ?? t('manufacturing.floor.refused'), color: 'error' }
      return false
    }
    if (!e?.response) {
      // No answer from the server: keep the tap, with the same key, for when the network returns.
      await enqueue('post', url, data, label, props.me, { 'Idempotency-Key': key })
      toast.value = { text: t('manufacturing.floor.queued'), color: 'warning' }
      return true
    }
    toast.value = { text: e.response.data?.message ?? t('manufacturing.floor.refused'), color: 'error' }
    return false
  } finally { busy.value = false }
}

const start = (j: Job) => act(route('manufacturing.floor.operations.start', j.id), {}, `Start ${j.order.number} ${j.sequence}`)

const pausing = ref<Job | null>(null)
const pauseForm = ref({ reason_id: null as number | null, notes: '' })
async function submitPause() {
  if (await act(route('manufacturing.floor.operations.pause', pausing.value!.id), { ...pauseForm.value, notes: pauseForm.value.notes || null }, `Pause ${pausing.value!.order.number}`)) {
    pausing.value = null; pauseForm.value = { reason_id: null, notes: '' }
  }
}

const completing = ref<Job | null>(null)
const completeForm = ref({ completed: 0, rejected: 0, scrapped: 0, reworked: 0, override_reason: '' })
function openComplete(j: Job) {
  completing.value = j
  completeForm.value = { completed: Math.max(0, j.order.planned_qty - 0), rejected: 0, scrapped: 0, reworked: 0, override_reason: '' }
}
async function submitComplete() {
  if (await act(route('manufacturing.floor.operations.complete', completing.value!.id),
    { ...completeForm.value, override_reason: completeForm.value.override_reason || null }, `Complete ${completing.value!.order.number}`)) completing.value = null
}

// ── Job detail ──────────────────────────────────────────────────────────────
const detail = ref<Detail | null>(null)
const drawer = ref(false)
async function openJob(id: string) {
  const { data } = await axios.get(route('manufacturing.floor.operations.show', id))
  detail.value = data
  drawer.value = true
}
const noteText = ref('')
async function addNote() {
  if (detail.value && noteText.value.trim() && await act(route('manufacturing.floor.operations.note', detail.value.id), { text: noteText.value }, 'Note')) noteText.value = ''
}
const assignTo = ref<number | null>(null)
async function assign() {
  if (detail.value && await act(route('manufacturing.floor.operations.assign', detail.value.id), { user_id: assignTo.value }, 'Assign')) await openJob(detail.value.id)
}

const issuing = ref<Detail['components'][number] | null>(null)
const issueForm = ref({ qty: null as number | null, lot_number: '', override_reason: '' })
function openIssue(c: Detail['components'][number]) { issuing.value = c; issueForm.value = { qty: c.outstanding > 0 ? c.outstanding : null, lot_number: '', override_reason: '' } }
async function submitIssue() {
  if (!detail.value || !issuing.value) return
  if (await act(route('manufacturing.floor.operations.issue', { uuid: detail.value.id, componentUuid: issuing.value.id }),
    { qty: issueForm.value.qty, lot_number: issueForm.value.lot_number || null, override_reason: issueForm.value.override_reason || null }, 'Issue')) {
    issuing.value = null
    await openJob(detail.value.id)
  }
}

// ── Help and stoppages ──────────────────────────────────────────────────────
const escalating = ref(false)
const escalateForm = ref({ kind: 'MATERIAL', message: '' })
async function submitEscalation() {
  if (await act(route('manufacturing.floor.escalations.store'), {
    ...escalateForm.value, operation: detail.value?.id ?? null, work_centre: detail.value ? null : centre.value,
  }, 'Escalation')) { escalating.value = false; escalateForm.value = { kind: 'MATERIAL', message: '' } }
}

const stopping = ref(false)
const stopForm = ref({ reason_id: null as number | null, asset_id: null as number | null, notes: '' })
async function submitStop() {
  if (await act(route('manufacturing.floor.downtime.start'), { ...stopForm.value, work_centre: centre.value, notes: stopForm.value.notes || null }, 'Stoppage')) {
    stopping.value = false; stopForm.value = { reason_id: null, asset_id: null, notes: '' }
  }
}
const endStop = (s: Stoppage) => act(route('manufacturing.floor.downtime.end', s.id), {}, 'End stoppage')

// ── Scanning ────────────────────────────────────────────────────────────────
const scanCode = ref('')
async function scan() {
  const code = scanCode.value.trim()
  scanCode.value = ''
  if (!code) return
  try {
    const { data } = await axios.post(route('manufacturing.floor.scan'), { code })
    if (data.type === 'operation') await openJob(data.id)
    else if (data.type === 'order') orderFilter.value = data.id
    else if (data.type === 'lot' && issuing.value) issueForm.value.lot_number = code
    else toast.value = { text: data.label, color: 'info' }
  } catch {
    toast.value = { text: t('manufacturing.floor.not_found'), color: 'error' }
  }
}

const statusColor = (s: string) => ({ IN_PROGRESS: 'warning', PAUSED: 'orange', READY: 'success', PENDING: 'grey' } as Record<string, string>)[s] ?? 'default'
const centreItems = computed(() => props.work_centres.map(c => ({ title: `${c.code} — ${c.name}${c.factory ? ` (${c.factory})` : ''}`, value: c.id })))
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center flex-wrap gap-3 mb-4">
      <h4 class="text-h4 me-auto">{{ t('manufacturing.floor.title') }}</h4>
      <VChip :color="isOnline ? 'success' : 'warning'" label>
        {{ isOnline ? t('manufacturing.floor.online') : t('manufacturing.floor.offline') }}
        <template v-if="pendingCount"> · {{ t('manufacturing.floor.pending_n', { n: pendingCount }) }}</template>
      </VChip>
      <VBtn variant="tonal" color="error" prepend-icon="tabler-alert-triangle" size="large" @click="detail = null; escalating = true">{{ t('manufacturing.floor.call_for_help') }}</VBtn>
    </div>

    <VCard border class="mb-4">
      <VCardText class="d-flex gap-3 flex-wrap align-center">
        <VSelect v-model="centre" :items="centreItems" :disabled="mine" hide-details style="min-width: 260px; max-width: 360px" :label="t('manufacturing.floor.work_centre')" />
        <VSwitch v-model="mine" color="primary" hide-details :label="t('manufacturing.floor.my_jobs')" />
        <VTextField v-model="scanCode" autofocus hide-details clearable style="min-width: 240px" prepend-inner-icon="tabler-scan"
                    :label="t('manufacturing.floor.scan')" @keyup.enter="scan" />
        <VChip v-if="orderFilter" closable @click:close="orderFilter = null">{{ t('manufacturing.floor.one_order') }}</VChip>
        <VBtn icon variant="text" :loading="loading" @click="load"><VIcon icon="tabler-refresh" /></VBtn>
        <VBtn v-if="can.execute && centre && !mine" variant="tonal" color="orange" prepend-icon="tabler-player-pause" @click="stopping = true">{{ t('manufacturing.floor.report_stoppage') }}</VBtn>
      </VCardText>
    </VCard>

    <VAlert v-for="s in stoppages" :key="s.id" type="warning" variant="tonal" class="mb-3" prominent>
      <div class="d-flex align-center flex-wrap gap-2">
        <span class="me-auto">{{ t('manufacturing.floor.stopped', { reason: s.reason, min: s.minutes }) }}<span v-if="s.notes"> — {{ s.notes }}</span></span>
        <VBtn v-if="can.execute" color="success" :loading="busy" @click="endStop(s)">{{ t('manufacturing.floor.running_again') }}</VBtn>
      </div>
    </VAlert>

    <VRow>
      <VCol v-for="j in shown" :key="j.id" cols="12" sm="6" lg="4">
        <VCard border :class="{ 'border-warning': j.status === 'IN_PROGRESS' }" class="h-100 d-flex flex-column">
          <VCardItem>
            <template #prepend><VAvatar :color="statusColor(j.status)" variant="tonal" size="44" class="text-h6">{{ j.sequence }}</VAvatar></template>
            <VCardTitle class="text-wrap">{{ j.name }}</VCardTitle>
            <VCardSubtitle class="text-wrap">{{ j.order.number }} · {{ j.order.product }}</VCardSubtitle>
            <template #append><VChip size="small" label :color="statusColor(j.status)">{{ t(`manufacturing.op_status.${j.status}`) }}</VChip></template>
          </VCardItem>
          <VCardText class="flex-grow-1">
            <VProgressLinear :model-value="j.order.planned_qty ? (j.order.qty_completed / j.order.planned_qty) * 100 : 0" height="8" rounded color="success" class="mb-2" />
            <div class="text-body-2">{{ t('manufacturing.floor.made_of', { made: j.order.qty_completed, planned: j.order.planned_qty }) }}</div>
            <div class="text-body-2" :class="{ 'text-error font-weight-bold': j.order.is_overdue }">{{ t('manufacturing.orders.due') }}: {{ j.order.due_date }}</div>
            <div v-if="j.assignee" class="text-body-2">{{ t('manufacturing.floor.assigned_to', { name: j.assignee }) }}</div>
            <div v-if="j.blocked_by.length" class="text-body-2 text-warning mt-1">{{ t('manufacturing.orders.waiting_for', { ops: j.blocked_by.join(', ') }) }}</div>
          </VCardText>
          <VCardActions class="flex-wrap gap-2 pa-3">
            <template v-if="can.execute">
              <VBtn v-if="['READY', 'PENDING', 'PAUSED'].includes(j.status)" size="large" color="success" variant="flat" :loading="busy" :disabled="j.status === 'PENDING'" @click="start(j)">
                {{ j.status === 'PAUSED' ? t('manufacturing.floor.resume') : t('manufacturing.orders.op_start') }}
              </VBtn>
              <VBtn v-if="j.status === 'IN_PROGRESS'" size="large" color="orange" variant="tonal" @click="pausing = j">{{ t('manufacturing.orders.op_pause') }}</VBtn>
              <VBtn v-if="['IN_PROGRESS', 'PAUSED', 'READY'].includes(j.status)" size="large" color="primary" variant="tonal" @click="openComplete(j)">{{ t('manufacturing.orders.op_complete') }}</VBtn>
            </template>
            <VSpacer />
            <VBtn size="large" variant="text" @click="openJob(j.id)">{{ t('manufacturing.floor.details') }}</VBtn>
          </VCardActions>
        </VCard>
      </VCol>
      <VCol v-if="!shown.length && !loading" cols="12">
        <VCard border><VCardText class="text-center py-10 text-medium-emphasis">{{ t('manufacturing.floor.no_jobs') }}</VCardText></VCard>
      </VCol>
    </VRow>

    <!-- Job detail -->
    <VNavigationDrawer v-model="drawer" location="end" temporary width="520">
      <template v-if="detail">
        <div class="pa-4">
          <div class="text-h6">{{ detail.sequence }} {{ detail.name }}</div>
          <div class="text-body-2 text-medium-emphasis mb-3">{{ detail.order.number }} · {{ detail.order.product }}</div>
          <div class="text-body-2 mb-3">{{ t('manufacturing.floor.logged', { min: detail.logged_minutes, planned: detail.planned_minutes }) }}</div>

          <div class="text-subtitle-1 font-weight-medium">{{ t('manufacturing.routing.instructions') }}</div>
          <p class="text-body-2" style="white-space: pre-line">{{ detail.instructions || t('manufacturing.floor.no_instructions') }}</p>

          <div class="text-subtitle-1 font-weight-medium mt-3">{{ t('manufacturing.floor.drawings') }}</div>
          <VList density="compact">
            <VListItem v-for="(d, i) in detail.documents" :key="i" :href="d.url ?? undefined" :target="d.url ? '_blank' : undefined"
                       :title="d.title" :subtitle="d.reference ?? t(`manufacturing.doc_kind.${d.kind}`)" prepend-icon="tabler-file" />
            <VListItem v-if="!detail.documents.length" :subtitle="t('manufacturing.floor.no_drawings')" />
          </VList>

          <div class="text-subtitle-1 font-weight-medium mt-3">{{ t('manufacturing.orders.tab.materials') }}</div>
          <VList density="compact">
            <VListItem v-for="c in detail.components" :key="c.id" :title="`${c.code} — ${c.name}`"
                       :subtitle="t('manufacturing.floor.material_line', { issued: c.issued, required: c.required, uom: c.uom ?? '' })">
              <template v-if="can.issue" #append><VBtn size="small" color="primary" variant="tonal" @click="openIssue(c)">{{ t('manufacturing.orders.issue') }}</VBtn></template>
            </VListItem>
          </VList>

          <template v-if="can.supervise">
            <div class="text-subtitle-1 font-weight-medium mt-3">{{ t('manufacturing.floor.assign') }}</div>
            <div class="d-flex gap-2">
              <VAutocomplete v-model="assignTo" :items="people" item-value="id" item-title="name" clearable hide-details density="compact" :label="detail.assignee ?? t('manufacturing.floor.unassigned')" />
              <VBtn color="primary" :loading="busy" @click="assign">{{ t('manufacturing.common.save') }}</VBtn>
            </div>
          </template>

          <template v-if="can.execute">
            <div class="text-subtitle-1 font-weight-medium mt-3">{{ t('manufacturing.floor.note') }}</div>
            <VTextarea v-model="noteText" rows="2" auto-grow hide-details />
            <div class="d-flex gap-2 mt-2">
              <VBtn variant="tonal" :disabled="!noteText.trim()" :loading="busy" @click="addNote">{{ t('manufacturing.floor.add_note') }}</VBtn>
              <VBtn variant="tonal" color="error" @click="escalating = true">{{ t('manufacturing.floor.call_for_help') }}</VBtn>
            </div>
          </template>
        </div>
      </template>
    </VNavigationDrawer>

    <!-- Pause -->
    <VDialog :model-value="pausing !== null" max-width="520" @update:model-value="v => { if (!v) pausing = null }">
      <VCard v-if="pausing" :title="t('manufacturing.orders.op_pause')" :subtitle="t('manufacturing.floor.pause_hint')">
        <VCardText>
          <VSelect v-model="pauseForm.reason_id" :items="reasons" item-value="id" item-title="name" clearable :label="t('manufacturing.floor.stoppage_reason')" />
          <VTextField v-model="pauseForm.notes" :label="t('manufacturing.orders.notes')" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="pausing = null">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="orange" size="large" :loading="busy" @click="submitPause">{{ t('manufacturing.orders.op_pause') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Complete -->
    <VDialog :model-value="completing !== null" max-width="560" @update:model-value="v => { if (!v) completing = null }">
      <VCard v-if="completing" :title="t('manufacturing.orders.complete_op', { n: completing.sequence, name: completing.name })">
        <VCardText>
          <VAlert v-if="completing.blocked_by.length" type="warning" variant="tonal" density="compact" class="mb-3">{{ t('manufacturing.orders.waiting_for', { ops: completing.blocked_by.join(', ') }) }}</VAlert>
          <VRow dense>
            <VCol cols="6"><VTextField v-model.number="completeForm.completed" type="number" min="0" step="any" class="text-h6" :label="t('manufacturing.orders.good')" /></VCol>
            <VCol cols="6"><VTextField v-model.number="completeForm.rejected" type="number" min="0" step="any" :label="t('manufacturing.orders.rejected')" /></VCol>
            <VCol cols="6"><VTextField v-model.number="completeForm.scrapped" type="number" min="0" step="any" :label="t('manufacturing.orders.scrapped')" /></VCol>
            <VCol cols="6"><VTextField v-model.number="completeForm.reworked" type="number" min="0" step="any" :label="t('manufacturing.orders.reworked')" /></VCol>
            <VCol v-if="completing.blocked_by.length && can.override" cols="12"><VTextarea v-model="completeForm.override_reason" rows="2" auto-grow :label="t('manufacturing.orders.override_reason')" /></VCol>
          </VRow>
          <p class="text-caption text-medium-emphasis mb-0">{{ t('manufacturing.floor.time_measured') }}</p>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="completing = null">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" size="large" :loading="busy" @click="submitComplete">{{ t('manufacturing.orders.op_complete') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Issue -->
    <VDialog :model-value="issuing !== null" max-width="480" @update:model-value="v => { if (!v) issuing = null }">
      <VCard v-if="issuing" :title="t('manufacturing.orders.issue_title', { item: issuing.code })">
        <VCardText>
          <VTextField v-model.number="issueForm.qty" type="number" min="0" step="any" :suffix="issuing.uom ?? ''" :label="t('manufacturing.orders.quantity')" class="mb-2" />
          <VTextField v-model="issueForm.lot_number" prepend-inner-icon="tabler-scan" :label="t('manufacturing.floor.lot_scan')" :hint="t('manufacturing.orders.lot_hint')" persistent-hint class="mb-2" />
          <VTextarea v-if="(issueForm.qty ?? 0) > issuing.outstanding && can.override" v-model="issueForm.override_reason" rows="2" auto-grow :label="t('manufacturing.orders.override_reason')" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="issuing = null">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="primary" size="large" :loading="busy" :disabled="!issueForm.qty" @click="submitIssue">{{ t('manufacturing.orders.issue') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Escalate -->
    <VDialog v-model="escalating" max-width="520">
      <VCard :title="t('manufacturing.floor.call_for_help')" :subtitle="detail ? `${detail.order.number} · ${detail.sequence} ${detail.name}` : t('manufacturing.floor.at_centre')">
        <VCardText>
          <VBtnToggle v-model="escalateForm.kind" mandatory divided variant="outlined" class="mb-3 flex-wrap" style="height: auto">
            <VBtn v-for="k in escalation_kinds" :key="k" :value="k">{{ t(`manufacturing.floor.kind.${k}`) }}</VBtn>
          </VBtnToggle>
          <VTextarea v-model="escalateForm.message" rows="3" auto-grow :label="t('manufacturing.floor.what_is_wrong')" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="escalating = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="error" size="large" :loading="busy" :disabled="!escalateForm.message.trim()" @click="submitEscalation">{{ t('manufacturing.floor.send') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Stoppage -->
    <VDialog v-model="stopping" max-width="520">
      <VCard :title="t('manufacturing.floor.report_stoppage')">
        <VCardText>
          <VSelect v-model="stopForm.reason_id" :items="reasons" item-value="id" item-title="name" :label="t('manufacturing.floor.stoppage_reason')" />
          <VSelect v-if="machines.length" v-model="stopForm.asset_id" :items="machines" item-value="id" item-title="name" clearable :label="t('manufacturing.floor.machine')" />
          <VTextField v-model="stopForm.notes" :label="t('manufacturing.orders.notes')" />
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="stopping = false">{{ t('manufacturing.common.cancel') }}</VBtn>
          <VBtn color="orange" size="large" :loading="busy" :disabled="!stopForm.reason_id" @click="submitStop">{{ t('manufacturing.floor.stop') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VSnackbar :model-value="toast !== null" :color="toast?.color" location="top" timeout="3500" @update:model-value="v => { if (!v) toast = null }">{{ toast?.text }}</VSnackbar>
  </div>
</template>
