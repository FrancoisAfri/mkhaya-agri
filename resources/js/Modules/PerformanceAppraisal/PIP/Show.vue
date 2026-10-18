<template>
  <VContainer fluid class="pa-6">
    <!-- Breadcrumb -->
    <VBtn variant="text" prepend-icon="tabler-arrow-left" class="mb-4" @click="router.visit(route('performance.pip.index'))">
      {{ t('pa.pip.all_pips') }}
    </VBtn>

    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-5">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ pip.title }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">
          {{ pip.employee_name }} · {{ t('pa.development.manager') }}: {{ pip.manager_name }}
        </p>
      </div>
      <div class="d-flex ga-2">
        <VChip :color="statusColor(pip.status)" variant="tonal">{{ statusLabel(pip.status) }}</VChip>
        <VBtn v-if="['active','extended'].includes(pip.status)" variant="tonal" @click="closeOpen = true">
          {{ t('pa.pip.close_out') }}
        </VBtn>
      </div>
    </div>

    <!-- KPI strip -->
    <VRow dense class="mb-5">
      <VCol cols="6" sm="3">
        <VCard border flat class="pa-3 text-center">
          <p class="text-h5 font-weight-bold text-blue">{{ pip.overall_progress }}%</p>
          <p class="text-caption text-medium-emphasis">{{ t('pa.development.overall_progress') }}</p>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard border flat class="pa-3 text-center">
          <p class="text-h5 font-weight-bold" :class="pip.days_remaining > 0 ? 'text-orange' : 'text-error'">
            {{ pip.days_remaining }}
          </p>
          <p class="text-caption text-medium-emphasis">{{ t('pa.pip.days_remaining') }}</p>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard border flat class="pa-3 text-center">
          <p class="text-h5 font-weight-bold text-green">
            {{ pip.objectives.filter((o: any) => o.status === 'met').length }}/{{ pip.objectives.length }}
          </p>
          <p class="text-caption text-medium-emphasis">{{ t('pa.pip.objectives_met') }}</p>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard border flat class="pa-3 text-center">
          <p class="text-h5 font-weight-bold text-purple">{{ pip.reviews.length }}</p>
          <p class="text-caption text-medium-emphasis">{{ t('pa.pip.reviews_scheduled') }}</p>
        </VCard>
      </VCol>
    </VRow>

    <!-- Tabs -->
    <VTabs v-model="tab" class="mb-4">
      <VTab value="objectives"><VIcon start>tabler-target</VIcon>{{ t('pa.pip.tab_objectives') }} ({{ pip.objectives.length }})</VTab>
      <VTab value="progress"><VIcon start>tabler-chart-line</VIcon>{{ t('pa.pip.tab_progress') }} ({{ pip.progress_logs.length }})</VTab>
      <VTab value="reviews"><VIcon start>tabler-calendar-check</VIcon>{{ t('pa.pip.tab_reviews') }} ({{ pip.reviews.length }})</VTab>
      <VTab value="ai"><VIcon start>tabler-brain</VIcon>{{ t('pa.pip.tab_ai') }}</VTab>
    </VTabs>

    <VTabsWindow v-model="tab">

      <!-- ── Objectives ───────────────────────────────────────────────────── -->
      <VTabsWindowItem value="objectives">
        <div class="d-flex justify-end mb-3">
          <VBtn size="small" variant="tonal" prepend-icon="tabler-plus" @click="objOpen = true">
            {{ t('pa.pip.add_objective') }}
          </VBtn>
        </div>
        <div v-if="pip.objectives.length">
          <VCard
            v-for="obj in pip.objectives"
            :key="obj.id"
            border
            flat
            class="mb-3"
          >
            <VCardItem>
              <template #prepend>
                <VAvatar :color="objStatusColor(obj.status)" variant="tonal" size="36">
                  <VIcon size="16">{{ objStatusIcon(obj.status) }}</VIcon>
                </VAvatar>
              </template>
              <VCardTitle class="text-body-2 font-weight-medium">{{ obj.title }}</VCardTitle>
              <VCardSubtitle v-if="obj.metric">{{ obj.metric }}: {{ obj.target_value }}</VCardSubtitle>
              <template #append>
                <div class="d-flex align-center ga-2">
                  <VSelect
                    :model-value="obj.status"
                    :items="objStatuses"
                    item-title="label"
                    item-value="value"
                    density="compact"
                    variant="outlined"
                    hide-details
                    style="min-width: 140px"
                    @update:model-value="updateObjStatus(obj, $event)"
                  />
                  <VBtn size="x-small" icon variant="text" color="error" @click="deleteObj(obj.id)">
                    <VIcon>tabler-trash</VIcon>
                  </VBtn>
                </div>
              </template>
            </VCardItem>
            <VCardText v-if="obj.description" class="pt-0 pb-3 text-caption text-medium-emphasis">
              {{ obj.description }}
              <span v-if="obj.due_date"> · {{ t('pa.development.due') }}: {{ obj.due_date }}</span>
            </VCardText>
          </VCard>
        </div>
        <VAlert v-else type="info" variant="tonal">{{ t('pa.pip.no_objectives') }}</VAlert>
      </VTabsWindowItem>

      <!-- ── Progress Logs ────────────────────────────────────────────────── -->
      <VTabsWindowItem value="progress">
        <div class="d-flex justify-end mb-3">
          <VBtn size="small" variant="tonal" prepend-icon="tabler-plus" @click="logOpen = true">
            {{ t('pa.pip.log_progress') }}
          </VBtn>
        </div>
        <VTimeline v-if="pip.progress_logs.length" density="compact" side="end">
          <VTimelineItem
            v-for="log in pip.progress_logs"
            :key="log.id"
            :dot-color="log.progress_pct >= 75 ? 'green' : log.progress_pct >= 40 ? 'blue' : 'orange'"
            size="small"
          >
            <template #opposite>
              <span class="text-caption text-medium-emphasis">{{ log.logged_at }}</span>
            </template>
            <VCard border flat class="pa-3">
              <div class="d-flex justify-space-between align-center mb-1">
                <span class="text-body-2 font-weight-medium">{{ log.progress_pct }}% {{ t('pa.development.progress') }}</span>
                <span class="text-caption text-medium-emphasis">{{ log.logged_by_name }}</span>
              </div>
              <p v-if="log.notes" class="text-caption text-medium-emphasis mb-0">{{ log.notes }}</p>
            </VCard>
          </VTimelineItem>
        </VTimeline>
        <VAlert v-else type="info" variant="tonal">{{ t('pa.pip.no_progress') }}</VAlert>
      </VTabsWindowItem>

      <!-- ── Reviews ──────────────────────────────────────────────────────── -->
      <VTabsWindowItem value="reviews">
        <div class="d-flex justify-end mb-3">
          <VBtn size="small" variant="tonal" prepend-icon="tabler-calendar-plus" @click="reviewOpen = true">
            {{ t('pa.pip.schedule_review') }}
          </VBtn>
        </div>
        <VList v-if="pip.reviews.length" class="pa-0">
          <VListItem
            v-for="r in pip.reviews"
            :key="r.id"
            class="mb-2 border"
          >
            <template #prepend>
              <VAvatar :color="reviewOutcomeColor(r.outcome)" variant="tonal" size="36">
                <VIcon size="16">tabler-calendar-check</VIcon>
              </VAvatar>
            </template>
            <template #title>
              <span class="text-body-2 font-weight-medium">{{ r.scheduled_date }}</span>
            </template>
            <template #subtitle>
              <span class="text-caption">{{ t('pa.kpis.by') }} {{ r.conducted_by_name }} · {{ r.status }}</span>
            </template>
            <template #append>
              <div class="d-flex align-center ga-2">
                <VChip v-if="r.outcome" size="x-small" :color="reviewOutcomeColor(r.outcome)" variant="flat">
                  {{ r.outcome?.replace('_', ' ') }}
                </VChip>
                <VBtn size="x-small" icon variant="text" @click="openEditReview(r)">
                  <VIcon>tabler-pencil</VIcon>
                </VBtn>
                <VBtn size="x-small" icon variant="text" color="error" @click="deleteReview(r.id)">
                  <VIcon>tabler-trash</VIcon>
                </VBtn>
              </div>
            </template>
          </VListItem>
        </VList>
        <VAlert v-else type="info" variant="tonal">{{ t('pa.pip.no_reviews') }}</VAlert>
      </VTabsWindowItem>

      <!-- ── AI Coaching ───────────────────────────────────────────────────── -->
      <VTabsWindowItem value="ai">
        <VRow dense>
          <VCol cols="12" md="6">
            <VCard border flat class="pa-4 mb-3">
              <div class="d-flex justify-space-between align-center mb-3">
                <p class="text-body-2 font-weight-bold">{{ t('pa.pip.coaching_guide') }}</p>
                <VBtn size="small" variant="tonal" color="primary" :loading="generatingCoaching" @click="generateCoaching">
                  {{ pip.ai_coaching ? t('pa.pip.regenerate') : t('pa.pip.generate') }}
                </VBtn>
              </div>
              <div v-if="pip.ai_coaching">
                <p class="text-body-2" style="white-space: pre-wrap">{{ pip.ai_coaching }}</p>
              </div>
              <VAlert v-else type="info" variant="tonal" density="compact">{{ t('pa.pip.no_coaching') }}</VAlert>
            </VCard>
          </VCol>
          <VCol cols="12" md="6">
            <VCard border flat class="pa-4">
              <div class="d-flex justify-space-between align-center mb-3">
                <p class="text-body-2 font-weight-bold">{{ t('pa.pip.outcome_recommendation') }}</p>
                <VBtn size="small" variant="tonal" color="secondary" :loading="generatingOutcome" @click="generateOutcome">
                  {{ pip.ai_outcome_recommendation ? t('pa.pip.refresh') : t('pa.pip.recommend') }}
                </VBtn>
              </div>
              <div v-if="pip.ai_outcome_recommendation">
                <p class="text-body-2" style="white-space: pre-wrap">{{ pip.ai_outcome_recommendation }}</p>
              </div>
              <VAlert v-else type="info" variant="tonal" density="compact">{{ t('pa.pip.no_outcome') }}</VAlert>
            </VCard>
          </VCol>
        </VRow>
      </VTabsWindowItem>

    </VTabsWindow>

    <!-- Add Objective dialog -->
    <VDialog v-model="objOpen" max-width="480">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold pa-4">{{ t('pa.pip.add_objective') }}</VCardTitle>
        <VCardText>
          <VTextField v-model="objForm.title" :label="t('pa.pip.objective_title')" density="compact" variant="outlined" class="mb-3" />
          <VTextarea v-model="objForm.description" :label="t('pa.cycles.description')" density="compact" variant="outlined" rows="2" class="mb-3" />
          <VRow dense>
            <VCol cols="6">
              <VTextField v-model="objForm.metric" :label="t('pa.pip.success_metric')" density="compact" variant="outlined" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="objForm.target_value" :label="t('pa.goals.target_value')" density="compact" variant="outlined" />
            </VCol>
          </VRow>
          <VTextField v-model="objForm.due_date" :label="t('pa.development.target_date')" type="date" density="compact" variant="outlined" class="mt-3 mb-3" />
          <VTextField
            v-model.number="objForm.weight"
            :label="t('pa.probation.weight_pct')"
            type="number" min="0" max="100"
            density="compact" variant="outlined"
            :hint="`${t('pa.common.remaining')}: ${remainingObjWeight}%`"
            persistent-hint
            :rules="[v => (v ?? 0) <= remainingObjWeight || `Cannot exceed remaining ${remainingObjWeight}%`]"
          />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="objOpen = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="savingObj" :disabled="!objForm.title" @click="submitObj">{{ t('pa.development.add_skill') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Log Progress dialog -->
    <VDialog v-model="logOpen" max-width="420">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold pa-4">{{ t('pa.pip.log_progress') }}</VCardTitle>
        <VCardText>
          <VSelect
            v-model="logForm.objective_id"
            :items="[{id: null, title: t('pa.pip.overall_pip')}, ...pip.objectives]"
            item-title="title"
            item-value="id"
            :label="t('pa.pip.tab_objectives')"
            density="compact"
            variant="outlined"
            class="mb-3"
          />
          <p class="text-caption text-medium-emphasis mb-1">{{ t('pa.development.progress') }}: {{ logForm.progress_pct }}%</p>
          <VSlider v-model="logForm.progress_pct" :min="0" :max="100" :step="5" thumb-label color="primary" class="mb-3" />
          <VTextField v-model="logForm.logged_at" :label="t('pa.cycles.start_date')" type="date" density="compact" variant="outlined" class="mb-3" />
          <VTextarea v-model="logForm.notes" :label="t('pa.kpis.notes_optional')" density="compact" variant="outlined" rows="2" />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="logOpen = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="savingLog" @click="submitLog">{{ t('pa.kpis.log') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Schedule Review dialog -->
    <VDialog v-model="reviewOpen" max-width="420">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold pa-4">
          {{ editingReview ? t('pa.pip.update_review') : t('pa.pip.schedule_review') }}
        </VCardTitle>
        <VCardText>
          <VSelect v-model="reviewForm.conducted_by" :items="employees" item-title="name" item-value="id" :label="t('pa.pip.conducted_by')" density="compact" variant="outlined" class="mb-3" />
          <VTextField v-model="reviewForm.scheduled_date" :label="t('pa.cycles.start_date')" type="date" density="compact" variant="outlined" class="mb-3" />
          <VSelect
            v-if="editingReview"
            v-model="reviewForm.status"
            :items="['scheduled','completed','cancelled']"
            :label="t('pa.common.status')"
            density="compact"
            variant="outlined"
            class="mb-3"
          />
          <VSelect
            v-if="editingReview"
            v-model="reviewForm.outcome"
            :items="[{title:'On Track',value:'on_track'},{title:'Needs Attention',value:'needs_attention'},{title:'At Risk',value:'at_risk'},{title:'Not Improving',value:'not_improving'}]"
            item-title="title"
            item-value="value"
            :label="t('pa.pip.outcome')"
            density="compact"
            variant="outlined"
            clearable
            class="mb-3"
          />
          <VTextarea v-model="reviewForm.notes" :label="t('pa.kpis.notes_optional')" density="compact" variant="outlined" rows="2" />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="reviewOpen = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="savingReview" @click="submitReview">
            {{ editingReview ? t('pa.cycles.save_changes') : t('pa.pip.schedule_review') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Close Out dialog -->
    <VDialog v-model="closeOpen" max-width="440">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold pa-4">{{ t('pa.pip.close_out') }}</VCardTitle>
        <VCardText>
          <VSelect
            v-model="closeForm.status"
            :items="[
              {title:'Closed — Improved',value:'closed_improved'},
              {title:'Closed — Not Improved',value:'closed_not_improved'},
              {title:'Extended',value:'extended'},
              {title:'Escalated',value:'escalated'},
            ]"
            item-title="title"
            item-value="value"
            :label="t('pa.pip.outcome')"
            density="compact"
            variant="outlined"
            class="mb-3"
          />
          <VTextField v-model="closeForm.actual_end_date" :label="t('pa.pip.actual_end_date')" type="date" density="compact" variant="outlined" class="mb-3" />
          <VTextarea v-model="closeForm.outcome_notes" :label="t('pa.pip.outcome_notes')" density="compact" variant="outlined" rows="3" />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="closeOpen = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="closingOut" :disabled="!closeForm.status" @click="submitClose">
            {{ t('pa.pip.close_pip') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

  </VContainer>
</template>

<script setup lang="ts">
import { ref, reactive, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'

const { t } = useI18n()

interface Objective {
  id: number; title: string; description: string | null; metric: string | null
  target_value: string | null; weight: number; status: string; due_date: string | null
}
interface ProgressLog {
  id: number; progress_pct: number; notes: string | null; logged_at: string; logged_by_name: string
}
interface Review {
  id: number; scheduled_date: string; conducted_by_name: string; status: string
  outcome: string | null; notes: string | null
}
interface PipPlan {
  id: number; title: string; employee_name: string; manager_name: string
  status: string; overall_progress: number; days_remaining: number
  start_date: string; target_end_date: string
  reason: string; ai_coaching: string | null; ai_outcome_recommendation: string | null
  objectives: Objective[]; progress_logs: ProgressLog[]; reviews: Review[]
}
interface Employee { id: number; name: string }

const props = defineProps<{ pip: PipPlan; employees: Employee[] }>()

const tab = ref('objectives')

// ── Objectives ────────────────────────────────────────────────────────────────
const usedObjWeight    = computed(() => props.pip.objectives.reduce((s, o) => s + (o.weight ?? 0), 0))
const remainingObjWeight = computed(() => 100 - usedObjWeight.value)

const objOpen    = ref(false)
const savingObj  = ref(false)
const objForm    = reactive({ title: '', description: '', metric: '', target_value: '', weight: 0, due_date: '' })
const objStatuses = [
  { label: 'Not Started', value: 'not_started' },
  { label: 'In Progress', value: 'in_progress' },
  { label: 'Met',         value: 'met' },
  { label: 'Not Met',     value: 'not_met' },
]

function submitObj() {
  savingObj.value = true
  router.post(route('performance.pip.objectives.store', props.pip.id), objForm, {
    onSuccess: () => { objOpen.value = false },
    onFinish:  () => { savingObj.value = false },
  })
}

function updateObjStatus(obj: Objective, status: string) {
  router.patch(route('performance.pip.objectives.update', obj.id), { status })
}

function deleteObj(id: number) {
  router.delete(route('performance.pip.objectives.destroy', id))
}

// ── Progress Logs ─────────────────────────────────────────────────────────────
const logOpen    = ref(false)
const savingLog  = ref(false)
const logForm    = reactive({ objective_id: null as number | null, progress_pct: 50, notes: '', logged_at: new Date().toISOString().slice(0, 10) })

function submitLog() {
  savingLog.value = true
  router.post(route('performance.pip.progress.log', props.pip.id), logForm as any, {
    onSuccess: () => { logOpen.value = false },
    onFinish:  () => { savingLog.value = false },
  })
}

// ── Reviews ───────────────────────────────────────────────────────────────────
const reviewOpen    = ref(false)
const savingReview  = ref(false)
const editingReview = ref<Review | null>(null)
const reviewForm    = reactive<{ conducted_by: number | null; scheduled_date: string; status: string; outcome: string | null; notes: string }>({
  conducted_by: null, scheduled_date: '', status: 'scheduled', outcome: null, notes: '',
})

function openEditReview(r: Review) {
  editingReview.value = r
  Object.assign(reviewForm, {
    conducted_by: null, scheduled_date: r.scheduled_date,
    status: r.status, outcome: r.outcome, notes: r.notes ?? '',
  })
  reviewOpen.value = true
}

function submitReview() {
  savingReview.value = true
  if (editingReview.value) {
    router.patch(route('performance.pip.reviews.update', editingReview.value.id), reviewForm as any, {
      onSuccess: () => { reviewOpen.value = false; editingReview.value = null },
      onFinish:  () => { savingReview.value = false },
    })
  } else {
    router.post(route('performance.pip.reviews.store', props.pip.id), reviewForm as any, {
      onSuccess: () => { reviewOpen.value = false },
      onFinish:  () => { savingReview.value = false },
    })
  }
}

function deleteReview(id: number) {
  router.delete(route('performance.pip.reviews.destroy', id))
}

// ── AI ────────────────────────────────────────────────────────────────────────
const generatingCoaching = ref(false)
const generatingOutcome  = ref(false)

function generateCoaching() {
  generatingCoaching.value = true
  router.post(route('performance.pip.coaching', props.pip.id), {}, {
    onFinish: () => { generatingCoaching.value = false },
  })
}

function generateOutcome() {
  generatingOutcome.value = true
  router.post(route('performance.pip.outcome', props.pip.id), {}, {
    onFinish: () => { generatingOutcome.value = false },
  })
}

// ── Close Out ─────────────────────────────────────────────────────────────────
const closeOpen   = ref(false)
const closingOut  = ref(false)
const closeForm   = reactive({ status: '', actual_end_date: '', outcome_notes: '' })

function submitClose() {
  closingOut.value = true
  router.patch(route('performance.pip.close', props.pip.id), closeForm, {
    onSuccess: () => { closeOpen.value = false },
    onFinish:  () => { closingOut.value = false },
  })
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function statusColor(s: string): string {
  return ({ draft: 'grey', active: 'blue', extended: 'orange', closed_improved: 'green', closed_not_improved: 'error', escalated: 'deep-purple' } as Record<string,string>)[s] ?? 'grey'
}
function statusLabel(s: string): string {
  return ({ draft: 'Draft', active: 'Active', extended: 'Extended', closed_improved: 'Improved', closed_not_improved: 'Not Improved', escalated: 'Escalated' } as Record<string,string>)[s] ?? s
}
function objStatusColor(s: string): string {
  return ({ not_started: 'grey', in_progress: 'blue', met: 'green', not_met: 'error' } as Record<string,string>)[s] ?? 'grey'
}
function objStatusIcon(s: string): string {
  return ({ not_started: 'tabler-circle', in_progress: 'tabler-progress-check', met: 'tabler-circle-check', not_met: 'tabler-circle-x' } as Record<string,string>)[s] ?? 'tabler-circle-filled'
}
function reviewOutcomeColor(o: string | null): string {
  if (!o) return 'grey'
  return ({ on_track: 'green', needs_attention: 'orange', at_risk: 'deep-orange', not_improving: 'error' } as Record<string,string>)[o] ?? 'grey'
}
</script>
