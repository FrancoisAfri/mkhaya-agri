<script lang="ts" setup>
import { ref, computed } from 'vue'
import { useForm, router, usePage } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ReassignDialog from './components/ReassignDialog.vue'
const route = ziggyRoute
const { t } = useI18n()

interface Stage { id: number; name: string; sort_order: number; probability: number; is_won: boolean; is_lost: boolean; color: string | null }
interface Pipeline { id: number; name: string; is_default: boolean; stages: Stage[] }
interface Deal {
  id: number; uuid: string; title: string; stage_id: number; amount: number; currency: string
  probability: number; account: string | null; account_id: number | null; contact: string | null; contact_id: number | null
  source: string | null; owner: string | null; product_id: number | null; product: string | null
  risk_level: string | null; risk_reason: string | null; expected_close_date: string | null; days_in_stage: number | null
  ai_win_probability: number | null; ai_win_reason: string | null
  status: string; hold_reason: string | null; hold_until: string | null; hold_sequence: string | null
}

const props = defineProps<{
  pipelines: Pipeline[]
  currentPipeline: number | null
  deals: Deal[]
  products: { id: number; name: string; type: string }[]
  accounts: { id: number; name: string }[]
  contacts: { id: number; first_name: string; last_name: string | null }[]
  aiEnabled: boolean
  sequences: { id: number; name: string; active?: boolean }[]
  showOwner: boolean
  assignableOwners: { id: number; name: string }[]
  canSeeOthers: boolean
  scope: string
}>()

/*
 * Whose deals the board is showing. A rep only ever has their own, so the switch is not drawn for
 * them; a manager lands on their own work and asks for the team's deliberately.
 */
function setScope(next: string) {
  router.get(route('crm.deals'), { pipeline: props.currentPipeline, scope: next }, {
    preserveState: true,
    preserveScroll: true,
    only: ['deals', 'scope'],
  })
}

// The board has no row selection, so a deal is handed over from its own card.
const reassignIds = ref<number[]>([])
const reassignDialog = ref(false)
const canReassign = computed(() => props.showOwner && props.assignableOwners.length > 0)
function openReassign(ids: number[]) { reassignIds.value = ids; reassignDialog.value = true }

const page = usePage()
const selectedPipeline = ref<number | null>(props.currentPipeline)
const createDialog = ref(false)
const dragId = ref<number | null>(null)
const confirm = ref()
const search = ref('')

const pipeline = computed(() => props.pipelines.find(p => p.id === selectedPipeline.value) ?? props.pipelines[0])
const money = (n: number, c: string) => `${c} ${(n ?? 0).toLocaleString('en-ZA', { maximumFractionDigits: 0 })}`

const visibleDeals = computed(() => {
  const q = search.value.trim().toLowerCase()
  if (!q) return props.deals
  return props.deals.filter(d => d.title.toLowerCase().includes(q) || (d.account ?? '').toLowerCase().includes(q) || (d.owner ?? '').toLowerCase().includes(q))
})
// A held deal keeps its stage_id so it can go back to the same column, which means it would
// otherwise appear twice — once in its old stage and once in On Hold. The stage columns show
// working deals only.
const dealsByStage = (stageId: number) => visibleDeals.value.filter(d => d.stage_id === stageId && d.status === 'open')
const stageTotal = (stageId: number) => dealsByStage(stageId).reduce((s, d) => s + d.amount, 0)
const heldDeals = computed(() => visibleDeals.value.filter(d => d.status === 'on_hold'))
const heldTotal = computed(() => heldDeals.value.reduce((s, d) => s + d.amount, 0))
const totalCount = computed(() => visibleDeals.value.filter(d => d.status === 'open').length)

// ── On hold ────────────────────────────────────────────────────────────────────
// Parking a deal needs two things a drag cannot supply: what the client said, and the date they
// asked us to come back. So dropping on the column opens the dialog rather than filing it silently.
const holdDialog = ref(false)
const holdDealId = ref<string | null>(null)
const holdForm = useForm({ hold_reason: '', hold_until: '', hold_sequence_id: null as number | null, nurture_topic: '' })
// draft = write a cadence about what they were buying; existing = pick one already built; none = silence.
const nurtureMode = ref<'draft' | 'existing' | 'none'>('draft')

function openHold(d: Deal) {
  holdDealId.value = d.uuid
  holdForm.reset()
  // The product on the deal is the closest thing to "what they were buying, in their words".
  holdForm.nurture_topic = d.product ?? d.title
  nurtureMode.value = 'draft'
  holdDialog.value = true
}
function submitHold() {
  if (!holdDealId.value) return
  // Only one of the two ever goes up: sending both would draft a cadence and then enrol into the
  // other one instead.
  holdForm.transform(data => ({
    ...data,
    nurture_topic: nurtureMode.value === 'draft' ? data.nurture_topic : null,
    hold_sequence_id: nurtureMode.value === 'existing' ? data.hold_sequence_id : null,
  })).post(route('crm.deals.hold', holdDealId.value), { preserveScroll: true, onSuccess: () => { holdDialog.value = false } })
}
function resumeDeal(d: Deal) { useForm({}).post(route('crm.deals.resume', d.uuid), { preserveScroll: true }) }
function onDropHold() {
  if (dragId.value === null) return
  const deal = props.deals.find(d => d.id === dragId.value)
  if (deal && deal.status === 'open') openHold(deal)
  dragId.value = null
}

const aiNext = computed(() => (page.props.flash as any)?.aiNextAction ?? null)
const aiFollow = computed(() => (page.props.flash as any)?.aiFollowUp ?? null)

const form = useForm({
  id: null as number | null, uuid: '', title: '', pipeline_id: props.currentPipeline, stage_id: null as number | null,
  account_id: null as number | null, contact_id: null as number | null, amount: 0, currency: 'ZAR', expected_close_date: '', source: '', product_id: null as number | null,
})

function changePipeline() { router.get(route('crm.deals'), { pipeline: selectedPipeline.value }, { preserveState: false }) }
function openCreate() { form.reset(); form.id = null; form.pipeline_id = pipeline.value?.id ?? null; form.stage_id = pipeline.value?.stages[0]?.id ?? null; createDialog.value = true }
function openEdit(d: Deal) { form.reset(); form.id = d.id; form.uuid = d.uuid; form.title = d.title; form.pipeline_id = pipeline.value?.id ?? null; form.stage_id = d.stage_id; form.account_id = d.account_id; form.contact_id = d.contact_id; form.amount = d.amount; form.currency = d.currency || 'ZAR'; form.expected_close_date = d.expected_close_date ?? ''; form.source = d.source ?? ''; form.product_id = d.product_id ?? null; createDialog.value = true }
function save() { if (form.id) {
  form.patch(route('crm.deals.update', form.uuid), {
    preserveScroll: true,
    onSuccess: () => { createDialog.value = false }
  })
} else {
  form.post(route('crm.deals.store'), {
    preserveScroll: true,
    onSuccess: () => { createDialog.value = false }
  })
}}

const lostDialog = ref(false)
const lostForm = useForm({ lost_reason: '' })
const lostDealId = ref<string | null>(null)

function onDrop(stageId: number) {
  if (dragId.value === null) return
  const deal = props.deals.find(d => d.id === dragId.value)
  const stage = (pipeline.value?.stages ?? []).find(s => s.id === stageId)
  if (deal && deal.stage_id !== stageId) {
    if (stage?.is_lost) { lostDealId.value = deal.uuid; lostForm.reset(); lostDialog.value = true }
    else { useForm({ stage_id: stageId }).post(route('crm.deals.move', deal.uuid), { preserveScroll: true }) }
  }
  dragId.value = null
}
function submitLost() { if (!lostDealId.value) return; lostForm.post(route('crm.deals.lost', lostDealId.value), { preserveScroll: true, onSuccess: () => { lostDialog.value = false } }) }

const coach = ref<{ summary: string; steps: string[]; risks: string[] } | null>(null)
const coachLoading = ref(false)
async function runCoach() { if (!drawerUuid.value) return; coachLoading.value = true; coach.value = null; try { const res = await fetch(route('crm.deals.advise', drawerUuid.value), { headers: { Accept: 'application/json' } }); const data = await res.json(); coach.value = res.ok && data.available ? { summary: data.summary, steps: data.steps ?? [], risks: data.risks ?? [] } : null } finally { coachLoading.value = false } }
function aiRisk(d: Deal) { useForm({}).post(route('crm.deals.ai.risk', d.uuid), { preserveScroll: true }) }
function aiNextAction(d: Deal) { useForm({}).post(route('crm.deals.ai.next-action', d.uuid), { preserveScroll: true }) }
function aiFollowUp(d: Deal) { useForm({ channel: 'email' }).post(route('crm.deals.ai.follow-up', d.uuid), { preserveScroll: true }) }
function remove(d: Deal) { confirm.value.open({ title: t('crm_deals_index.delete.title'), message: t('crm_deals_index.delete.message', { title: d.title }), confirmText: t('crm_deals_index.delete.confirm'), confirmColor: 'error' }, () => useForm({}).delete(route('crm.deals.destroy', d.uuid), { preserveScroll: true })) }
function aiClose(d: Deal) { useForm({}).post(route('crm.deals.ai.close-probability', d.uuid), { preserveScroll: true, onSuccess: () => { if (drawerId.value === d.id) loadDetail(d.uuid) } }) }

interface DealNote { id: number; body: string; author: string; at: string | null; mine: boolean }
interface DealReminder { id: number; subject: string; type: string; due_at: string | null; status: string; overdue: boolean }
interface DealDetail { id: number; title: string; amount: number; currency: string; stage: string | null; account: string | null; contact: string | null; owner: string | null; status: string; risk_level: string | null; risk_reason: string | null; ai_win_probability: number | null; ai_win_reason: string | null; lost_reason: string | null; lost_category: string | null; lost_ai_advice: string | null; notes: DealNote[]; reminders: DealReminder[] }

const drawer = ref(false); const drawerId = ref<number | null>(null); const drawerUuid = ref<string | null>(null)
const detail = ref<DealDetail | null>(null); const detailLoading = ref(false)
const noteForm = useForm({ entity_type: 'deal', entity_id: 0, entity_label: '', body: '' })
const reminderForm = useForm({ type: 'task', subject: '', related_type: 'deal', related_id: 0, due_at: '' })

async function loadDetail(uuid: string) { detailLoading.value = true; try { const res = await fetch(route('crm.deals.detail', uuid), { headers: { Accept: 'application/json' } }); detail.value = res.ok ? await res.json() : null } finally { detailLoading.value = false } }
function openDeal(d: Deal) { drawerId.value = d.id; drawerUuid.value = d.uuid; drawer.value = true; detail.value = null; coach.value = null; noteForm.entity_id = d.id; noteForm.entity_label = d.title; noteForm.body = ''; reminderForm.related_id = d.id; reminderForm.subject = ''; reminderForm.due_at = ''; loadDetail(d.uuid) }
function addNote() { if (!drawerUuid.value) return; noteForm.post(route('crm.discussions.store'), { preserveScroll: true, onSuccess: () => { noteForm.reset('body'); loadDetail(drawerUuid.value!) } }) }
function addReminder() { if (!drawerUuid.value) return; reminderForm.post(route('crm.activities.store'), { preserveScroll: true, onSuccess: () => { reminderForm.subject = ''; reminderForm.due_at = ''; loadDetail(drawerUuid.value!) } }) }
function completeReminder(r: DealReminder) { useForm({}).post(route('crm.activities.complete', r.id), { preserveScroll: true, onSuccess: () => { if (drawerUuid.value) loadDetail(drawerUuid.value) } }) }
function drawerAiClose() { if (!drawerUuid.value) return; useForm({}).post(route('crm.deals.ai.close-probability', drawerUuid.value), { preserveScroll: true, onSuccess: () => loadDetail(drawerUuid.value!) }) }
const winColor = (p: number | null) => p === null ? 'grey' : p >= 70 ? 'success' : p >= 40 ? 'warning' : 'error'
const riskColor = (r: string | null) => r === 'high' ? 'error' : r === 'medium' ? 'warning' : r === 'low' ? 'success' : 'blue-grey'
</script>

<template>
  <div class="pa-4">
    <div class="d-flex flex-wrap align-center justify-space-between mb-4 gap-3">
      <div><h5 class="text-h5 font-weight-semibold">{{ t('crm_deals_index.page_title') }}</h5><p class="text-body-2 text-medium-emphasis mb-0">{{ t('crm_deals_index.description') }} <span class="text-disabled">{{ t('crm_deals_index.deal_count', { count: totalCount }) }}</span></p></div>
      <div class="d-flex gap-2 align-center flex-wrap">
        <VTextField v-model="search" density="compact" hide-details :placeholder="t('crm_deals_index.search_placeholder')" prepend-inner-icon="tabler-search" clearable style="min-width: 240px" />

        <!-- Only drawn for somebody with a team to look at; a rep has nothing to switch between. -->
        <VBtnToggle
          v-if="canSeeOthers" :model-value="scope" density="compact" variant="outlined" mandatory
          @update:model-value="setScope"
        >
          <VBtn value="mine" size="small">{{ t('crm_deals_index.scope_mine') }}</VBtn>
          <VBtn value="team" size="small">{{ t('crm_deals_index.scope_team') }}</VBtn>
        </VBtnToggle>
        <VSelect v-model="selectedPipeline" density="compact" hide-details style="min-width: 200px" :items="pipelines.map(p => ({ title: p.name, value: p.id }))" @update:model-value="changePipeline" />
        <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">{{ t('crm_deals_index.new_deal') }}</VBtn>
      </div>
    </div>

    <VAlert v-if="aiNext" type="info" variant="tonal" class="mb-3" closable><strong>{{ t('crm_deals_index.ai_next_action') }}:</strong> {{ aiNext.action }} <span class="text-medium-emphasis">— {{ aiNext.rationale }}</span></VAlert>
    <VAlert v-if="aiFollow" type="success" variant="tonal" class="mb-3" closable><strong>{{ t('crm_deals_index.drafted_follow_up') }}{{ aiFollow.subject ? ` — ${aiFollow.subject}` : '' }}:</strong><div class="text-body-2 mt-1" style="white-space: pre-wrap">{{ aiFollow.body }}</div></VAlert>

    <div class="crm-board">
      <div v-for="stage in pipeline?.stages ?? []" :key="stage.id" class="crm-board-col" @dragover.prevent @drop="onDrop(stage.id)">
        <VCard rounded="lg" border elevation="0" class="h-100">
          <VCardText class="pb-2"><div class="d-flex align-center justify-space-between"><span class="font-weight-semibold">{{ stage.name }}</span><VChip size="x-small" label>{{ dealsByStage(stage.id).length }}</VChip></div><div class="text-caption text-medium-emphasis">{{ t('crm_deals_index.stage_total', { amount: money(stageTotal(stage.id), 'R'), probability: stage.probability }) }}</div></VCardText>
          <VDivider />
          <div class="pa-2 crm-stage-body">
            <VCard v-for="deal in dealsByStage(stage.id)" :key="deal.id" class="mb-2 cursor-grab" border elevation="0" draggable="true" @dragstart="dragId = deal.id">
              <VCardText class="pa-3">
                <div class="d-flex justify-space-between align-start"><span class="font-weight-medium text-body-2 text-primary cursor-pointer" @click="openDeal(deal)">{{ deal.title }}</span><VChip v-if="deal.risk_level" :color="riskColor(deal.risk_level)" size="x-small" label>{{ deal.risk_level }}</VChip></div>
                <div class="text-caption text-medium-emphasis mt-1">{{ deal.account ?? '—' }}</div>
                <div v-if="showOwner" class="d-flex align-center ga-1 mt-1"><VIcon icon="tabler-user" size="13" class="text-disabled" /><span class="text-caption text-medium-emphasis">{{ deal.owner ?? t('crm_deals_index.unassigned') }}</span></div>
                <div class="text-body-2 font-weight-semibold mt-1">{{ money(deal.amount, deal.currency) }}</div>
                <div class="d-flex align-center justify-space-between mt-1"><span v-if="deal.days_in_stage !== null" class="text-caption text-disabled">{{ t('crm_deals_index.days_in_stage', { days: deal.days_in_stage }) }}</span><VChip v-if="deal.ai_win_probability !== null && deal.ai_win_probability !== undefined" :color="winColor(deal.ai_win_probability)" size="x-small" label><VIcon start size="11" icon="tabler-percentage" />{{ t('crm_deals_index.ai_win_pct', { pct: deal.ai_win_probability }) }}</VChip></div>
                <div class="d-flex gap-1 mt-2">
                  <VBtn v-if="aiEnabled" size="x-small" variant="tonal" icon="tabler-alert-triangle" :title="t('crm_deals_index.tips.ai_risk')" @click="aiRisk(deal)" />
                  <VBtn v-if="aiEnabled" size="x-small" variant="tonal" icon="tabler-percentage" :title="t('crm_deals_index.tips.ai_win')" @click="aiClose(deal)" />
                  <VBtn v-if="aiEnabled" size="x-small" variant="tonal" icon="tabler-bulb" :title="t('crm_deals_index.tips.ai_next')" @click="aiNextAction(deal)" />
                  <VBtn v-if="aiEnabled" size="x-small" variant="tonal" icon="tabler-mail" :title="t('crm_deals_index.tips.ai_follow')" @click="aiFollowUp(deal)" />
                  <VBtn size="x-small" variant="tonal" icon="tabler-notes" :title="t('crm_deals_index.tips.notes_reminders')" @click="openDeal(deal)" />
                  <VBtn size="x-small" variant="tonal" icon="tabler-player-pause" :title="t('crm_deals_index.hold.tip')" @click="openHold(deal)" />
                  <VSpacer />
                  <VBtn v-if="canReassign" size="x-small" variant="text" icon="tabler-user-share" :title="t('crm_reassign.action')" @click="openReassign([deal.id])" />
                  <VBtn size="x-small" variant="text" icon="tabler-edit" :title="t('crm_deals_index.tips.edit_deal')" @click="openEdit(deal)" />
                  <VBtn size="x-small" variant="text" icon="tabler-trash" color="error" :title="t('crm_deals_index.tips.delete_deal')" @click="remove(deal)" />
                </div>
              </VCardText>
            </VCard>
          </div>
        </VCard>
      </div>

      <!--
        On Hold sits at the end of the board, after the closing stages: it is where the pipeline
        goes to wait, not a step towards a sale. Deals here are out of the forecast, so the column
        total is shown separately and never added to the stage totals above.
      -->
      <div class="crm-board-col" @dragover.prevent @drop="onDropHold">
        <VCard rounded="lg" border elevation="0" class="h-100">
          <VCardText class="pb-2">
            <div class="d-flex align-center justify-space-between">
              <span class="font-weight-semibold"><VIcon icon="tabler-player-pause" size="16" class="me-1" />{{ t('crm_deals_index.hold.column') }}</span>
              <VChip size="x-small" label color="warning">{{ heldDeals.length }}</VChip>
            </div>
            <div class="text-caption text-medium-emphasis">{{ t('crm_deals_index.hold.column_total', { amount: money(heldTotal, 'R') }) }}</div>
          </VCardText>
          <VDivider />
          <div class="pa-2 crm-stage-body">
            <VCard v-for="deal in heldDeals" :key="deal.id" class="mb-2 cursor-grab" border elevation="0" draggable="true" @dragstart="dragId = deal.id">
              <VCardText class="pa-3">
                <div class="d-flex justify-space-between align-start">
                  <span class="font-weight-medium text-body-2 text-primary cursor-pointer" @click="openDeal(deal)">{{ deal.title }}</span>
                </div>
                <div class="text-caption text-medium-emphasis mt-1">{{ deal.account ?? '—' }}</div>
                <div class="text-body-2 font-weight-semibold mt-1">{{ money(deal.amount, deal.currency) }}</div>
                <VAlert v-if="deal.hold_reason" type="warning" variant="tonal" density="compact" class="mt-2 text-caption">{{ deal.hold_reason }}</VAlert>
                <div v-if="deal.hold_until" class="d-flex align-center ga-1 mt-2">
                  <VIcon icon="tabler-calendar-repeat" size="13" class="text-disabled" />
                  <span class="text-caption text-medium-emphasis">{{ t('crm_deals_index.hold.returns_on', { date: deal.hold_until }) }}</span>
                </div>
                <div v-if="deal.hold_sequence" class="d-flex align-center ga-1 mt-1">
                  <VIcon icon="tabler-mail-forward" size="13" class="text-disabled" />
                  <span class="text-caption text-medium-emphasis">{{ deal.hold_sequence }}</span>
                </div>
                <div class="d-flex gap-1 mt-2">
                  <VBtn size="x-small" variant="tonal" color="primary" prepend-icon="tabler-player-play" @click="resumeDeal(deal)">{{ t('crm_deals_index.hold.resume') }}</VBtn>
                  <VSpacer />
                  <VBtn size="x-small" variant="text" icon="tabler-notes" :title="t('crm_deals_index.tips.notes_reminders')" @click="openDeal(deal)" />
                </div>
              </VCardText>
            </VCard>
            <p v-if="!heldDeals.length" class="text-caption text-disabled text-center py-4 mb-0">{{ t('crm_deals_index.hold.empty') }}</p>
          </div>
        </VCard>
      </div>
    </div>

    <VDialog v-model="holdDialog" max-width="520" persistent>
      <VCard>
        <VCardTitle>{{ t('crm_deals_index.hold.dialog_title') }}</VCardTitle>
        <VCardText>
          <p class="text-body-2 text-medium-emphasis">{{ t('crm_deals_index.hold.dialog_hint') }}</p>
          <VTextarea v-model="holdForm.hold_reason" :label="t('crm_deals_index.hold.reason')" :placeholder="t('crm_deals_index.hold.reason_placeholder')" rows="3" class="mb-3" :error-messages="holdForm.errors.hold_reason" />
          <VTextField v-model="holdForm.hold_until" type="date" :label="t('crm_deals_index.hold.until')" :hint="t('crm_deals_index.hold.until_hint')" persistent-hint class="mb-3" :error-messages="holdForm.errors.hold_until" />
          <VSelect
            v-model="nurtureMode"
            :label="t('crm_deals_index.hold.nurture')"
            class="mb-3"
            :items="[
              { title: t('crm_deals_index.hold.mode_draft'), value: 'draft' },
              { title: t('crm_deals_index.hold.mode_existing'), value: 'existing' },
              { title: t('crm_deals_index.hold.mode_none'), value: 'none' },
            ]"
          />
          <VTextField
            v-if="nurtureMode === 'draft'"
            v-model="holdForm.nurture_topic"
            :label="t('crm_deals_index.hold.topic')"
            :placeholder="t('crm_deals_index.hold.topic_placeholder')"
            :hint="t('crm_deals_index.hold.topic_hint')"
            persistent-hint
            :error-messages="holdForm.errors.nurture_topic"
          />
          <VSelect
            v-if="nurtureMode === 'existing'"
            v-model="holdForm.hold_sequence_id"
            :label="t('crm_deals_index.hold.pick_sequence')"
            :hint="t('crm_deals_index.hold.nurture_hint')"
            persistent-hint
            clearable
            :items="sequences.map(sq => ({ title: sq.active === false ? `${sq.name} — ${t('crm_deals_index.hold.paused')}` : sq.name, value: sq.id }))"
            :no-data-text="t('crm_deals_index.hold.no_sequences')"
          />
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="tonal" @click="holdDialog = false">{{ t('crm_deals_index.dialog_create.cancel') }}</VBtn>
          <VBtn color="warning" :loading="holdForm.processing" :disabled="!holdForm.hold_reason || !holdForm.hold_until" @click="submitHold">{{ t('crm_deals_index.hold.confirm') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ReassignDialog v-model="reassignDialog" type="deal" :owners="assignableOwners" :ids="reassignIds" @done="() => router.reload({ only: ['deals'] })" />

    <VDialog v-model="createDialog" max-width="520" persistent>
      <VCard>
        <VCardTitle>{{ form.id ? t('crm_deals_index.dialog_create.title_edit') : t('crm_deals_index.dialog_create.title_create') }}</VCardTitle>
        <VCardText>
          <VTextField v-model="form.title" :label="t('crm_deals_index.dialog_create.title_label')" class="mb-3" />
          <VRow>
            <VCol cols="6"><VSelect v-model="form.stage_id" :label="t('crm_deals_index.dialog_create.stage')" :items="(pipeline?.stages ?? []).map(s => ({ title: s.name, value: s.id }))" /></VCol>
            <VCol cols="6"><VTextField v-model.number="form.amount" type="number" :label="t('crm_deals_index.dialog_create.amount')" /></VCol>
            <VCol cols="12"><VSelect v-model="form.account_id" :label="t('crm_deals_index.dialog_create.account')" clearable :items="accounts.map(a => ({ title: a.name, value: a.id }))" /></VCol>
            <VCol cols="12"><VSelect v-model="form.contact_id" :label="t('crm_deals_index.dialog_create.contact')" clearable :items="contacts.map(c => ({ title: `${c.first_name} ${c.last_name ?? ''}`.trim(), value: c.id }))" /></VCol>
            <VCol cols="6"><VTextField v-model="form.expected_close_date" type="date" :label="t('crm_deals_index.dialog_create.expected_close')" /></VCol>
            <VCol cols="6"><VTextField v-model="form.source" :label="t('crm_deals_index.dialog_create.source')" /></VCol>
            <VCol v-if="products.length" cols="12"><VSelect v-model="form.product_id" label="Product / Service" :items="products.map(p => ({ title: p.name, value: p.id }))" clearable placeholder="— none —" /></VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="tonal" @click="createDialog = false">{{ t('crm_deals_index.dialog_create.cancel') }}</VBtn><VBtn color="primary" :loading="form.processing" @click="save">{{ form.id ? t('crm_deals_index.dialog_create.save') : t('crm_deals_index.dialog_create.create') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VNavigationDrawer v-model="drawer" temporary location="right" width="460">
      <div v-if="detail" class="d-flex flex-column h-100">
        <div class="pa-4 border-b"><div class="d-flex align-center justify-space-between"><h6 class="text-h6 mb-0">{{ detail.title }}</h6><VBtn icon="tabler-x" variant="text" size="small" :title="t('crm_deals_index.drawer.close')" @click="drawer = false" /></div><div class="text-body-2 text-medium-emphasis">{{ money(detail.amount, detail.currency) }} · {{ detail.stage || '—' }}<span v-if="detail.account"> · {{ detail.account }}</span></div></div>
        <div class="flex-grow-1 overflow-y-auto pa-4">
          <VCard variant="tonal" :color="winColor(detail.ai_win_probability)" class="mb-4"><VCardText class="d-flex align-center ga-3"><div class="text-h4 font-weight-bold">{{ detail.ai_win_probability !== null ? detail.ai_win_probability + '%' : '—' }}</div><div class="flex-grow-1"><div class="text-body-2 font-weight-medium">{{ t('crm_deals_index.drawer.ai_win_likelihood') }}</div><div class="text-caption">{{ detail.ai_win_reason || t('crm_deals_index.drawer.run_forecast') }}</div></div><VBtn v-if="aiEnabled" size="small" variant="flat" icon="tabler-refresh" :title="t('crm_deals_index.drawer.recalculate')" @click="drawerAiClose" /></VCardText></VCard>
          <VAlert v-if="detail.risk_level" :type="detail.risk_level === 'high' ? 'error' : detail.risk_level === 'medium' ? 'warning' : 'success'" variant="tonal" density="compact" class="mb-4"><strong class="text-capitalize">{{ detail.risk_level }} {{ t('crm_deals_index.drawer.risk_level') }}</strong> — {{ detail.risk_reason }}</VAlert>
          <VAlert v-if="detail.status === 'lost'" type="error" variant="tonal" class="mb-4"><div class="font-weight-medium">{{ t('crm_deals_index.drawer.lost') }}<span v-if="detail.lost_category"> · {{ detail.lost_category }}</span></div><div class="text-body-2" v-if="detail.lost_reason">{{ t('crm_deals_index.drawer.reason') }}: {{ detail.lost_reason }}</div><div class="text-body-2 mt-1" v-if="detail.lost_ai_advice" style="white-space: pre-wrap"><strong>{{ t('crm_deals_index.drawer.ai_lost_advice') }}:</strong> {{ detail.lost_ai_advice }}</div></VAlert>
          <VCard v-if="aiEnabled && detail.status !== 'lost'" variant="tonal" class="mb-4"><VCardText><div class="d-flex align-center justify-space-between mb-1"><span class="font-weight-medium"><VIcon icon="tabler-sparkles" size="18" class="me-1" />{{ t('crm_deals_index.drawer.ai_coach') }}</span><VBtn size="small" variant="flat" color="primary" :loading="coachLoading" prepend-icon="tabler-wand" @click="runCoach()">{{ t('crm_deals_index.drawer.how_to_close') }}</VBtn></div><template v-if="coach"><div class="text-body-2 mb-2">{{ coach.summary }}</div><div v-if="coach.steps.length" class="text-overline text-medium-emphasis">{{ t('crm_deals_index.drawer.next_steps') }}</div><ol class="text-body-2 ps-4 mb-2"><li v-for="(s, i) in coach.steps" :key="i">{{ s }}</li></ol><div v-if="coach.risks.length" class="text-overline text-medium-emphasis">{{ t('crm_deals_index.drawer.watch_out') }}</div><ul class="text-body-2 ps-4"><li v-for="(r, i) in coach.risks" :key="i">{{ r }}</li></ul></template><p v-else class="text-caption text-medium-emphasis mb-0">{{ t('crm_deals_index.drawer.coach_hint') }}</p></VCardText></VCard>
          <div class="text-overline text-medium-emphasis">{{ t('crm_deals_index.drawer.reminders') }}</div>
          <div class="d-flex ga-2 mb-2"><VTextField v-model="reminderForm.subject" :placeholder="t('crm_deals_index.drawer.reminder_placeholder')" density="compact" hide-details /><VTextField v-model="reminderForm.due_at" type="datetime-local" density="compact" hide-details style="max-width: 190px" /><VBtn icon="tabler-plus" size="small" :title="t('crm_deals_index.drawer.add_reminder')" :disabled="!reminderForm.subject || !reminderForm.due_at" :loading="reminderForm.processing" @click="addReminder" /></div>
          <VList v-if="detail.reminders.length" density="compact" class="mb-3"><VListItem v-for="r in detail.reminders" :key="r.id" :class="r.overdue ? 'text-error' : ''"><template #prepend><VIcon :icon="r.status === 'done' ? 'tabler-circle-check' : 'tabler-bell'" :color="r.status === 'done' ? 'success' : r.overdue ? 'error' : 'primary'" size="18" /></template><VListItemTitle :class="r.status === 'done' ? 'text-decoration-line-through text-disabled' : ''">{{ r.subject }}</VListItemTitle><VListItemSubtitle>{{ t('crm_deals_index.drawer.due') }} {{ r.due_at }}</VListItemSubtitle><template #append><VBtn v-if="r.status !== 'done'" size="x-small" variant="text" color="success" icon="tabler-check" :title="t('crm_deals_index.drawer.mark_done')" @click="completeReminder(r)" /></template></VListItem></VList>
          <p v-else class="text-caption text-medium-emphasis mb-3">{{ t('crm_deals_index.drawer.no_reminders') }}</p>
          <div class="text-overline text-medium-emphasis">{{ t('crm_deals_index.drawer.notes') }}</div>
          <VTextarea v-model="noteForm.body" :placeholder="t('crm_deals_index.drawer.add_note_placeholder')" rows="2" auto-grow density="compact" hide-details class="mb-2" />
          <div class="d-flex justify-end mb-3"><VBtn size="small" color="primary" :disabled="!noteForm.body" :loading="noteForm.processing" @click="addNote">{{ t('crm_deals_index.drawer.add_note') }}</VBtn></div>
          <div v-for="n in detail.notes" :key="n.id" class="mb-2"><div class="text-body-2" style="white-space: pre-wrap">{{ n.body }}</div><div class="text-caption text-medium-emphasis">{{ n.author }} · {{ n.at }}</div><VDivider class="mt-2" /></div>
          <p v-if="!detail.notes.length" class="text-caption text-medium-emphasis">{{ t('crm_deals_index.drawer.no_notes') }}</p>
        </div>
      </div>
      <div v-else class="d-flex align-center justify-center h-100"><VProgressCircular v-if="detailLoading" indeterminate color="primary" /></div>
    </VNavigationDrawer>

    <VDialog v-model="lostDialog" max-width="480" persistent>
      <VCard>
        <VCardTitle>{{ t('crm_deals_index.lost_dialog.title') }}</VCardTitle>
        <VCardText><p class="text-body-2 text-medium-emphasis mb-3">{{ t('crm_deals_index.lost_dialog.description') }}</p><VTextarea v-model="lostForm.lost_reason" :label="t('crm_deals_index.lost_dialog.reason_label')" rows="3" autofocus :error-messages="lostForm.errors.lost_reason" /></VCardText>
        <VDivider />
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="tonal" @click="lostDialog = false">{{ t('crm_deals_index.lost_dialog.cancel') }}</VBtn><VBtn color="error" :loading="lostForm.processing" :disabled="!lostForm.lost_reason" @click="submitLost">{{ t('crm_deals_index.lost_dialog.mark_lost') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirm" />
  </div>
</template>

<style scoped>
/*
 * The board fills what is left of the window instead of sitting in a short box with dead space
 * under it. An empty stage used to collapse to 120px, so a pipeline with nothing in it drew four
 * stubs against a page of white — which reads as a broken screen rather than an empty one.
 *
 * min-height keeps the columns reaching the fold. There is deliberately no max-height: see the
 * note on .crm-stage-body below for what that trades away and why.
 */
.crm-board {
  display: flex;
  gap: 12px;
  overflow-x: auto;
  padding-bottom: 8px;
  align-items: flex-start;
  min-height: calc(100vh - 330px);
}
.crm-board-col { min-width: 280px; max-width: 280px; display: flex; }
.crm-board-col > .v-card { display: flex; flex-direction: column; width: 100%; }

/*
 * One continuous column per stage: the cards run on and the PAGE scrolls, rather than each column
 * being capped and scrolling inside itself. A capped column showed two or three deals at a time
 * however tall the screen was, which is no use to somebody working a stage with twenty in it — the
 * board stopped being a board and became a set of little windows onto one.
 *
 * The cap did protect something real, and this gives it up deliberately: a long column now scrolls
 * the other columns' headers off the top with it. Keeping them via `position: sticky` does not
 * work here and is worth writing down so nobody spends an afternoon on it — `.crm-board` sets
 * `overflow-x: auto`, and when one axis is not `visible` the other computes to `auto` too, so the
 * board is itself a scroll container. A sticky header inside it anchors to the board's scrollport,
 * which no longer scrolls, and simply never engages. Holding the headers would mean giving the
 * board back a height of its own, which is the thing being removed.
 *
 * align-items: flex-start rather than stretch — with no cap, stretch makes every column as tall as
 * the longest one, leaving the short ones as long empty boxes.
 */
.crm-stage-body { flex: 1 1 auto; }
</style>
