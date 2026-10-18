<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import axios from 'axios'

const { t } = useI18n()

interface CaseDetail {
  id: number; uuid: string; case_number: string; title: string; description: string
  employee_id: number; employee_name: string
  investigator_id: number|null; investigator_name: string
  chairperson_id: number|null; chairperson_name: string
  category_id: number|null; category: string|null; severity: string|null
  status_id: number|null; status: string|null; status_color: string|null
  outcome_id: number|null; outcome: string|null
  sanction_id: number|null; sanction: string|null
  incident_date: string; notice_issued_date: string|null; hearing_date: string|null; closed_at: string|null
  policy_reference: string|null; outcome_notes: string|null; internal_notes: string|null
  policy_engine_checked: boolean; policy_violations: string[]|null
  is_closed: boolean; created_at: string
}
interface ActivityEntry {
  id: number; event: string|null; description: string; properties: Record<string,unknown>|null; user: string; created_at: string
}
interface StatusOption   { id: number; name: string; color: string; is_closed: boolean }
interface OutcomeOption  { id: number; name: string; requires_sanction: boolean }
interface SanctionOption { id: number; name: string; severity_level: string; duration_days: number|null }
interface Employee       { id: number; name: string; email: string }

const props = defineProps<{
  disciplinaryCase: CaseDetail
  activityLogs: ActivityEntry[]
  statuses:     StatusOption[]
  outcomes:     OutcomeOption[]
  sanctions:    SanctionOption[]
  employees:    Employee[]
  viewerRole:   string  // 'admin_hr' | 'investigator' | 'chairperson' | 'employee' | 'manager'
}>()

const canEdit     = computed(() => props.viewerRole === 'admin_hr')
const canAddNotes = computed(() => ['admin_hr', 'investigator', 'chairperson'].includes(props.viewerRole))

const tab = ref('details')

// ── Status update ──────────────────────────────────────────────────────────────
const statusDialog = ref(false)
const statusForm = useForm({ status_id: props.disciplinaryCase.status_id as number|null, notes: '' })
function submitStatus() {
  statusForm.post(route('disciplinary.cases.update-status', { case: props.disciplinaryCase.id }), {
    onSuccess: () => { statusDialog.value = false },
  })
}

// ── Assign Investigator ────────────────────────────────────────────────────────
const investigatorDialog = ref(false)
const investigatorForm = useForm({ investigator_id: props.disciplinaryCase.investigator_id as number|null })
function submitInvestigator() {
  investigatorForm.post(route('disciplinary.cases.assign-investigator', { case: props.disciplinaryCase.id }), {
    onSuccess: () => { investigatorDialog.value = false },
  })
}

// ── Assign Chairperson ─────────────────────────────────────────────────────────
const chairpersonDialog = ref(false)
const chairpersonForm = useForm({ chairperson_id: props.disciplinaryCase.chairperson_id as number|null })
function submitChairperson() {
  chairpersonForm.post(route('disciplinary.cases.assign-chairperson', { case: props.disciplinaryCase.id }), {
    onSuccess: () => { chairpersonDialog.value = false },
  })
}

// ── Close Case ─────────────────────────────────────────────────────────────────
const closeDialog = ref(false)
const closeForm = useForm({ outcome_id: null as number|null, sanction_id: null as number|null, outcome_notes: '' })
const selectedOutcomeRequiresSanction = computed(() =>
  props.outcomes.find(o => o.id === closeForm.outcome_id)?.requires_sanction ?? false
)
function submitClose() {
  closeForm.post(route('disciplinary.cases.close', { case: props.disciplinaryCase.id }), {
    onSuccess: () => { closeDialog.value = false },
  })
}

// ── Edit details ───────────────────────────────────────────────────────────────
const editDrawer = ref(false)
const editForm = useForm({
  title:              props.disciplinaryCase.title,
  description:        props.disciplinaryCase.description,
  incident_date:      props.disciplinaryCase.incident_date,
  notice_issued_date: props.disciplinaryCase.notice_issued_date ?? '',
  hearing_date:       props.disciplinaryCase.hearing_date ? props.disciplinaryCase.hearing_date.split('T')[0] : '',
  policy_reference:   props.disciplinaryCase.policy_reference ?? '',
  outcome_notes:      props.disciplinaryCase.outcome_notes ?? '',
  internal_notes:     props.disciplinaryCase.internal_notes ?? '',
  category_id:        props.disciplinaryCase.category_id as number|null,
})
function submitEdit() {
  editForm.put(route('disciplinary.cases.update', { case: props.disciplinaryCase.id }), {
    onSuccess: () => { editDrawer.value = false },
  })
}

// ── Notes (investigator / chairperson) ────────────────────────────────────────
const notesDialog = ref(false)
const notesForm = useForm({ internal_notes: props.disciplinaryCase.internal_notes ?? '' })
function submitNotes() {
  notesForm.post(route('disciplinary.my-dashboard.save-notes', { case: props.disciplinaryCase.id }), {
    onSuccess: () => { notesDialog.value = false },
  })
}

// ── Timeline & AI Intelligence (Module 6) ─────────────────────────────────────
interface TimelineEvent {
  id: number; event_type: string; event_label: string; event_detail: string|null
  event_date: string; actor_name: string|null; is_ai_inferred: boolean; icon: string; color: string
}
interface AiReport {
  id: number; report_type: string; title: string; executive_summary: string
  sections: {heading: string; content: string}[]; recommendations: string|null
  ai_model: string|null; created_at: string
}
interface AiAnalysis {
  id: number; analysis_type: string; analysis_result: string
  key_findings: {finding: string; confidence: string}[]
  consistency_score: string|null
  conflicts_detected: {description: string; source_a: string; source_b: string}[]
  ai_model: string|null; created_at: string
}

const timeline   = ref<TimelineEvent[]>([])
const aiReports  = ref<AiReport[]>([])
const aiAnalysis = ref<AiAnalysis[]>([])
const aiLoading  = ref(false)
const timelineLoading = ref(false)

async function loadTimeline() {
  timelineLoading.value = true
  try {
    const res = await axios.get(route('disciplinary.intelligence.case-timeline', props.disciplinaryCase.id))
    timeline.value = res.data
  } finally {
    timelineLoading.value = false
  }
}

async function loadAiReports() {
  aiLoading.value = true
  try {
    const res = await axios.get(route('disciplinary.intelligence.case-reports', props.disciplinaryCase.id))
    aiReports.value  = res.data.reports ?? []
    aiAnalysis.value = res.data.analysis ?? []
  } finally {
    aiLoading.value = false
  }
}

function generateHearingPack() {
  router.post(route('disciplinary.intelligence.generate-hearing-pack', props.disciplinaryCase.id))
}

function analyzeWitnesses() {
  router.post(route('disciplinary.intelligence.analyze-witnesses', props.disciplinaryCase.id))
}

function generateEvidenceSummary() {
  router.post(route('disciplinary.intelligence.case-evidence-summary', props.disciplinaryCase.id))
}

onMounted(() => {
  loadTimeline()
  loadAiReports()
})

const severityColor = (s: string|null) => ({ minor: 'success', serious: 'warning', gross: 'error' })[s ?? ''] ?? 'grey'
const cap = (s: string | null | undefined) => s ? s.charAt(0).toUpperCase() + s.slice(1) : '—'

const actionIcon = (log: ActivityEntry) => {
  const action = (log.properties?.action as string) ?? log.event ?? ''
  return ({
    case_created:         'tabler-circle-plus',
    status_changed:       'tabler-arrows-left-right',
    investigator_assigned:'tabler-user-search',
    chairperson_assigned: 'tabler-user-tie',
    case_closed:          'tabler-lock-check',
    case_updated:         'tabler-pencil',
    created:              'tabler-circle-plus',
    updated:              'tabler-pencil',
    deleted:              'tabler-trash',
  })[action] ?? 'tabler-info-circle'
}
</script>

<template>
  <div class="pa-4">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <p class="text-overline text-medium-emphasis mb-0">{{ t('disciplinary.module_label') }}</p>
        <h1 class="text-h5 font-weight-bold">{{ disciplinaryCase.case_number }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-0">{{ disciplinaryCase.title }}</p>
      </div>
      <div class="d-flex ga-2 flex-wrap">
        <VBtn variant="outlined" size="small" prepend-icon="tabler-arrow-left" :href="route('disciplinary.cases.index')">{{ t('disciplinary.cases') }}</VBtn>
        <VBtn v-if="canEdit && !disciplinaryCase.is_closed" variant="outlined" size="small" prepend-icon="tabler-pencil" @click="editDrawer = true">{{ t('disciplinary.edit') }}</VBtn>
        <VBtn v-if="canEdit && !disciplinaryCase.is_closed" color="error" size="small" prepend-icon="tabler-lock" @click="closeDialog = true">{{ t('disciplinary.close_case') }}</VBtn>
      </div>
    </div>

    <!-- Status strip -->
    <VRow dense class="mb-4">
      <VCol cols="12" sm="auto">
        <VChip :color="disciplinaryCase.status_color ?? 'grey'" size="large" prepend-icon="tabler-point">
          {{ disciplinaryCase.status ?? t('disciplinary.no_status') }}
        </VChip>
      </VCol>
      <VCol v-if="disciplinaryCase.severity" cols="12" sm="auto">
        <VChip :color="severityColor(disciplinaryCase.severity)" size="large">{{ cap(disciplinaryCase.severity) }}</VChip>
      </VCol>
      <VCol v-if="disciplinaryCase.is_closed" cols="12" sm="auto">
        <VChip color="grey" size="large" prepend-icon="tabler-lock">{{ t('disciplinary.closed') }}</VChip>
      </VCol>
      <VSpacer />
      <VCol v-if="canEdit && !disciplinaryCase.is_closed" cols="12" sm="auto" class="d-flex ga-2">
        <VBtn size="small" variant="tonal" prepend-icon="tabler-arrows-left-right" @click="statusDialog = true">{{ t('disciplinary.update_status') }}</VBtn>
        <VBtn size="small" variant="tonal" prepend-icon="tabler-user-search" @click="investigatorDialog = true">{{ t('disciplinary.assign_investigator') }}</VBtn>
        <VBtn size="small" variant="tonal" prepend-icon="tabler-user-tie" @click="chairpersonDialog = true">{{ t('disciplinary.assign_chairperson') }}</VBtn>
      </VCol>
    </VRow>

    <!-- Tabs -->
    <VCard>
      <VTabs v-model="tab" color="primary">
        <VTab value="details">{{ t('disciplinary.details') }}</VTab>
        <VTab value="timeline">{{ t('disciplinary.timeline') }}</VTab>
        <VTab value="ai">{{ t('disciplinary.ai_intelligence') }}</VTab>
        <VTab value="activity">{{ t('disciplinary.activity_log') }} <VChip class="ml-2" size="x-small">{{ activityLogs.length }}</VChip></VTab>
      </VTabs>
      <VDivider />

      <VTabsWindow v-model="tab">

        <!-- Details tab -->
        <VTabsWindowItem value="details">
          <VCardText>
            <VRow>
              <VCol cols="12" md="8">
                <!-- Main info -->
                <VRow dense>
                  <VCol cols="12" sm="6">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.employee') }}</div>
                    <div class="font-weight-medium">{{ disciplinaryCase.employee_name }}</div>
                  </VCol>
                  <VCol cols="12" sm="6">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.category') }}</div>
                    <div>{{ disciplinaryCase.category ?? '—' }}</div>
                  </VCol>
                  <VCol cols="12" sm="6">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.incident_date') }}</div>
                    <div>{{ disciplinaryCase.incident_date }}</div>
                  </VCol>
                  <VCol cols="12" sm="6">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.hearing_date') }}</div>
                    <div>{{ disciplinaryCase.hearing_date ?? '—' }}</div>
                  </VCol>
                  <VCol cols="12" sm="6">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.notice_issued_date') }}</div>
                    <div>{{ disciplinaryCase.notice_issued_date ?? '—' }}</div>
                  </VCol>
                  <VCol cols="12" sm="6">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.policy_reference') }}</div>
                    <div>{{ disciplinaryCase.policy_reference ?? '—' }}</div>
                  </VCol>
                  <VCol cols="12">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.description') }}</div>
                    <div class="text-body-2">{{ disciplinaryCase.description }}</div>
                  </VCol>
                  <VCol v-if="disciplinaryCase.outcome_notes" cols="12">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.outcome_notes') }}</div>
                    <div class="text-body-2">{{ disciplinaryCase.outcome_notes }}</div>
                  </VCol>
                  <VCol v-if="disciplinaryCase.internal_notes" cols="12">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.internal_notes') }}</div>
                    <div class="text-body-2">{{ disciplinaryCase.internal_notes }}</div>
                  </VCol>
                  <VCol v-if="disciplinaryCase.policy_violations?.length" cols="12">
                    <div class="text-caption text-medium-emphasis mb-1">{{ t('disciplinary.policy_violations') }}</div>
                    <VChip v-for="v in disciplinaryCase.policy_violations" :key="v" size="small" color="error" class="mr-1 mb-1">{{ v }}</VChip>
                  </VCol>
                </VRow>
              </VCol>

              <!-- Sidebar -->
              <VCol cols="12" md="4">
                <VCard variant="tonal" color="grey" class="mb-3">
                  <VCardText class="pb-2">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.investigator') }}</div>
                    <div class="font-weight-medium">{{ disciplinaryCase.investigator_name !== 'Unknown' ? disciplinaryCase.investigator_name : '—' }}</div>
                  </VCardText>
                </VCard>
                <VCard variant="tonal" color="grey" class="mb-3">
                  <VCardText class="pb-2">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.chairperson') }}</div>
                    <div class="font-weight-medium">{{ disciplinaryCase.chairperson_name !== 'Unknown' ? disciplinaryCase.chairperson_name : '—' }}</div>
                  </VCardText>
                </VCard>
                <VCard v-if="disciplinaryCase.outcome" variant="tonal" color="primary" class="mb-3">
                  <VCardText class="pb-2">
                    <div class="text-caption">{{ t('disciplinary.outcome') }}</div>
                    <div class="font-weight-medium">{{ disciplinaryCase.outcome }}</div>
                    <div v-if="disciplinaryCase.sanction" class="text-caption mt-1">
                      {{ t('disciplinary.sanction') }}: {{ disciplinaryCase.sanction }}
                    </div>
                  </VCardText>
                </VCard>
                <VCard v-if="disciplinaryCase.closed_at" variant="tonal" color="grey" class="mb-3">
                  <VCardText class="pb-2">
                    <div class="text-caption text-medium-emphasis">{{ t('disciplinary.closed_at') }}</div>
                    <div>{{ disciplinaryCase.closed_at }}</div>
                  </VCardText>
                </VCard>
                <VCard v-if="canAddNotes" variant="outlined">
                  <VCardText class="pb-1">
                    <div class="d-flex align-center justify-space-between mb-1">
                      <div class="text-caption text-medium-emphasis">{{ t('disciplinary.internal_notes') }}</div>
                      <VBtn size="x-small" variant="text" prepend-icon="tabler-pencil" @click="notesDialog = true">{{ t('disciplinary.edit_notes') }}</VBtn>
                    </div>
                    <div class="text-body-2">{{ disciplinaryCase.internal_notes || t('disciplinary.no_notes_yet') }}</div>
                  </VCardText>
                </VCard>
              </VCol>
            </VRow>
          </VCardText>
        </VTabsWindowItem>

        <!-- Timeline tab -->
        <VTabsWindowItem value="timeline">
          <VCardText>
            <div v-if="timelineLoading" class="d-flex justify-center py-8">
              <VProgressCircular indeterminate color="primary" />
            </div>
            <VTimeline v-else side="end" density="compact" class="pa-2">
              <VTimelineItem
                v-for="ev in timeline" :key="ev.id"
                :dot-color="ev.color"
                size="small"
              >
                <template #icon><VIcon size="14">{{ ev.icon }}</VIcon></template>
                <VCard variant="outlined" class="mb-2">
                  <VCardText class="py-2 px-3">
                    <div class="d-flex justify-space-between align-start">
                      <div>
                        <span class="text-body-2 font-weight-medium">{{ ev.event_label }}</span>
                        <VChip v-if="ev.is_ai_inferred" size="x-small" color="purple" class="ml-2">AI</VChip>
                      </div>
                      <span class="text-caption text-medium-emphasis ml-2 text-no-wrap">{{ ev.event_date }}</span>
                    </div>
                    <div v-if="ev.event_detail" class="text-caption text-medium-emphasis">{{ ev.event_detail }}</div>
                    <div v-if="ev.actor_name" class="text-caption text-medium-emphasis">{{ ev.actor_name }}</div>
                  </VCardText>
                </VCard>
              </VTimelineItem>
              <VTimelineItem v-if="!timeline.length && !timelineLoading" dot-color="grey" size="small">
                <span class="text-body-2 text-medium-emphasis">{{ t('disciplinary.no_timeline_events') }}</span>
              </VTimelineItem>
            </VTimeline>
          </VCardText>
        </VTabsWindowItem>

        <!-- AI Intelligence tab -->
        <VTabsWindowItem value="ai">
          <VCardText>
            <div v-if="canEdit" class="d-flex ga-2 flex-wrap mb-4">
              <VBtn size="small" variant="tonal" color="purple" prepend-icon="tabler-brain" @click="generateEvidenceSummary">
                {{ t('disciplinary.generate_evidence_summary') }}
              </VBtn>
              <VBtn size="small" variant="tonal" color="purple" prepend-icon="tabler-microphone" @click="analyzeWitnesses">
                {{ t('disciplinary.analyze_witnesses') }}
              </VBtn>
              <VBtn size="small" variant="tonal" color="purple" prepend-icon="tabler-file-text" @click="generateHearingPack">
                {{ t('disciplinary.generate_hearing_pack') }}
              </VBtn>
              <VBtn size="small" variant="outlined" prepend-icon="tabler-refresh" @click="loadAiReports">
                {{ t('disciplinary.refresh') }}
              </VBtn>
            </div>

            <div v-if="aiLoading" class="d-flex justify-center py-8">
              <VProgressCircular indeterminate color="purple" />
            </div>

            <div v-else-if="!aiReports.length && !aiAnalysis.length" class="text-center py-8 text-medium-emphasis">
              <VIcon size="48" class="mb-2" color="purple">tabler-brain</VIcon>
              <p>{{ t('disciplinary.no_ai_reports') }}</p>
              <p class="text-caption">{{ t('disciplinary.no_ai_reports_hint') }}</p>
            </div>

            <VExpansionPanels v-else>
              <VExpansionPanel v-for="r in aiReports" :key="`r-${r.id}`">
                <VExpansionPanelTitle>
                  <div class="d-flex align-center ga-2">
                    <VAvatar color="purple" size="28" rounded>
                      <VIcon size="14" color="white">tabler-brain</VIcon>
                    </VAvatar>
                    <div>
                      <div class="font-weight-medium text-body-2">{{ r.report_type }}</div>
                      <div class="text-caption text-medium-emphasis">{{ r.created_at }} · {{ r.ai_model }}</div>
                    </div>
                  </div>
                </VExpansionPanelTitle>
                <VExpansionPanelText>
                  <div class="mb-3">
                    <div class="text-caption text-medium-emphasis mb-1">{{ t('disciplinary.executive_summary') }}</div>
                    <div class="text-body-2 pa-2 rounded bg-grey-lighten-5">{{ r.executive_summary }}</div>
                  </div>
                  <div v-for="sec in r.sections" :key="sec.heading" class="mb-3">
                    <div class="text-subtitle-2 mb-1">{{ sec.heading }}</div>
                    <div class="text-body-2">{{ sec.content }}</div>
                  </div>
                  <div v-if="r.recommendations" class="pa-2 rounded bg-purple-lighten-5">
                    <div class="text-caption font-weight-medium mb-1">{{ t('disciplinary.recommendations') }}</div>
                    <div class="text-body-2">{{ r.recommendations }}</div>
                  </div>
                </VExpansionPanelText>
              </VExpansionPanel>

              <VExpansionPanel v-for="a in aiAnalysis" :key="`a-${a.id}`">
                <VExpansionPanelTitle>
                  <div class="d-flex align-center ga-2">
                    <VAvatar color="primary" size="28" rounded>
                      <VIcon size="14" color="white">tabler-search</VIcon>
                    </VAvatar>
                    <div>
                      <div class="font-weight-medium text-body-2">{{ a.analysis_type }}</div>
                      <div class="text-caption text-medium-emphasis">{{ a.created_at }}</div>
                    </div>
                  </div>
                </VExpansionPanelTitle>
                <VExpansionPanelText>
                  <div class="text-body-2 mb-3">{{ a.analysis_result }}</div>
                  <div v-if="a.consistency_score" class="mb-2">
                    <span class="text-caption text-medium-emphasis">{{ t('disciplinary.consistency_score') }}:</span>
                    <VChip size="small" color="primary" class="ml-2">{{ a.consistency_score }}</VChip>
                  </div>
                  <div v-if="a.key_findings?.length">
                    <div class="text-caption text-medium-emphasis mb-1">{{ t('disciplinary.key_findings') }}</div>
                    <VChip v-for="f in a.key_findings" :key="f.finding" size="small" class="mr-1 mb-1" :color="f.confidence === 'high' ? 'error' : f.confidence === 'medium' ? 'warning' : 'grey'">
                      {{ f.finding }}
                    </VChip>
                  </div>
                  <div v-if="a.conflicts_detected?.length" class="mt-2">
                    <div class="text-caption text-medium-emphasis mb-1">{{ t('disciplinary.conflicts_detected') }}</div>
                    <VAlert v-for="c in a.conflicts_detected" :key="c.description" type="warning" density="compact" variant="tonal" class="mb-1 text-body-2">
                      {{ c.description }}
                    </VAlert>
                  </div>
                </VExpansionPanelText>
              </VExpansionPanel>
            </VExpansionPanels>
          </VCardText>
        </VTabsWindowItem>

        <!-- Activity Log tab -->
        <VTabsWindowItem value="activity">
          <VTimeline side="end" density="compact" class="pa-4">
            <VTimelineItem
              v-for="log in activityLogs" :key="log.id"
              :dot-color="(log.properties?.action ?? log.event) === 'case_closed' ? 'error' : (log.properties?.action ?? log.event) === 'case_created' || log.event === 'created' ? 'success' : 'primary'"
              size="small"
            >
              <template #icon>
                <VIcon size="16">{{ actionIcon(log) }}</VIcon>
              </template>
              <VCard variant="outlined" class="mb-2">
                <VCardText class="py-2 px-3">
                  <div class="d-flex justify-space-between align-start">
                    <span class="text-body-2 font-weight-medium">{{ log.description }}</span>
                    <span class="text-caption text-medium-emphasis ml-2 text-no-wrap">{{ log.created_at }}</span>
                  </div>
                  <div class="text-caption text-medium-emphasis">{{ log.user }}</div>
                </VCardText>
              </VCard>
            </VTimelineItem>
            <VTimelineItem v-if="!activityLogs.length" dot-color="grey" size="small">
              <span class="text-body-2 text-medium-emphasis">{{ t('disciplinary.no_activity') }}</span>
            </VTimelineItem>
          </VTimeline>
        </VTabsWindowItem>

      </VTabsWindow>
    </VCard>

    <!-- Notes Dialog (investigator / chairperson) -->
    <VDialog v-if="canAddNotes" v-model="notesDialog" max-width="480">
      <VCard :title="t('disciplinary.edit_notes')">
        <VCardText>
          <VTextarea v-model="notesForm.internal_notes" :label="t('disciplinary.internal_notes')" variant="outlined" density="compact" rows="6" :hint="t('disciplinary.notes_hint')" persistent-hint />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="outlined" @click="notesDialog = false">{{ t('disciplinary.cancel') }}</VBtn>
          <VBtn color="primary" :loading="notesForm.processing" @click="submitNotes">{{ t('disciplinary.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Status Dialog -->
    <VDialog v-if="canEdit" v-model="statusDialog" max-width="400">
      <VCard :title="t('disciplinary.update_status')">
        <VCardText class="d-flex flex-column ga-3">
          <VSelect v-model="statusForm.status_id" :items="statuses" item-title="name" item-value="id" :label="t('disciplinary.status')" variant="outlined" density="compact" />
          <VTextarea v-model="statusForm.notes" :label="t('disciplinary.notes')" variant="outlined" density="compact" rows="2" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="outlined" @click="statusDialog = false">{{ t('disciplinary.cancel') }}</VBtn>
          <VBtn color="primary" :loading="statusForm.processing" @click="submitStatus">{{ t('disciplinary.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Investigator Dialog -->
    <VDialog v-if="canEdit" v-model="investigatorDialog" max-width="400">
      <VCard :title="t('disciplinary.assign_investigator')">
        <VCardText>
          <VAutocomplete v-model="investigatorForm.investigator_id" :items="employees" item-title="name" item-value="id" :label="t('disciplinary.investigator')" variant="outlined" density="compact" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="outlined" @click="investigatorDialog = false">{{ t('disciplinary.cancel') }}</VBtn>
          <VBtn color="primary" :loading="investigatorForm.processing" @click="submitInvestigator">{{ t('disciplinary.assign') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Chairperson Dialog -->
    <VDialog v-if="canEdit" v-model="chairpersonDialog" max-width="400">
      <VCard :title="t('disciplinary.assign_chairperson')">
        <VCardText>
          <VAutocomplete v-model="chairpersonForm.chairperson_id" :items="employees" item-title="name" item-value="id" :label="t('disciplinary.chairperson')" variant="outlined" density="compact" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="outlined" @click="chairpersonDialog = false">{{ t('disciplinary.cancel') }}</VBtn>
          <VBtn color="primary" :loading="chairpersonForm.processing" @click="submitChairperson">{{ t('disciplinary.assign') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Close Case Dialog -->
    <VDialog v-if="canEdit" v-model="closeDialog" max-width="480">
      <VCard :title="t('disciplinary.close_case')">
        <VCardText class="d-flex flex-column ga-3">
          <VSelect v-model="closeForm.outcome_id" :items="outcomes" item-title="name" item-value="id" :label="t('disciplinary.outcome')" variant="outlined" density="compact" :error-messages="closeForm.errors.outcome_id" />
          <VSelect v-if="selectedOutcomeRequiresSanction" v-model="closeForm.sanction_id" :items="sanctions" item-title="name" item-value="id" :label="t('disciplinary.sanction')" variant="outlined" density="compact" />
          <VTextarea v-model="closeForm.outcome_notes" :label="t('disciplinary.outcome_notes')" variant="outlined" density="compact" rows="3" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="outlined" @click="closeDialog = false">{{ t('disciplinary.cancel') }}</VBtn>
          <VBtn color="error" :loading="closeForm.processing" @click="submitClose">{{ t('disciplinary.close_case') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Edit Drawer -->
    <VNavigationDrawer v-if="canEdit" v-model="editDrawer" location="right" width="480" temporary>
      <VToolbar density="compact" color="primary" :title="t('disciplinary.edit_case')" />
      <div class="pa-4 d-flex flex-column ga-3">
        <VTextField v-model="editForm.title" :label="t('disciplinary.title')" variant="outlined" density="compact" :error-messages="editForm.errors.title" />
        <VTextarea v-model="editForm.description" :label="t('disciplinary.description')" variant="outlined" density="compact" rows="4" />
        <VTextField v-model="editForm.incident_date" :label="t('disciplinary.incident_date')" variant="outlined" density="compact" type="date" />
        <VTextField v-model="editForm.notice_issued_date" :label="t('disciplinary.notice_issued_date')" variant="outlined" density="compact" type="date" />
        <VTextField v-model="editForm.hearing_date" :label="t('disciplinary.hearing_date')" variant="outlined" density="compact" type="date" />
        <VTextField v-model="editForm.policy_reference" :label="t('disciplinary.policy_reference')" variant="outlined" density="compact" />
        <VTextarea v-model="editForm.outcome_notes" :label="t('disciplinary.outcome_notes')" variant="outlined" density="compact" rows="2" />
        <VTextarea v-model="editForm.internal_notes" :label="t('disciplinary.internal_notes')" variant="outlined" density="compact" rows="2" />
        <div class="d-flex ga-2 justify-end mt-2">
          <VBtn variant="outlined" @click="editDrawer = false">{{ t('disciplinary.cancel') }}</VBtn>
          <VBtn color="primary" :loading="editForm.processing" @click="submitEdit">{{ t('disciplinary.save') }}</VBtn>
        </div>
      </div>
    </VNavigationDrawer>
  </div>
</template>
