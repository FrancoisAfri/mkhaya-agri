<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface CaseSummary {
  id: number; case_number: string; title: string; employee_name: string
  category: string|null; severity: string|null; status: string; status_color: string
  is_closed: boolean; incident_date: string|null; internal_notes: string|null; created_at: string
}
interface InvestigationSummary {
  id: number; investigation_number: string; case_id: number
  case_number: string|null; case_title: string|null; employee_name: string
  category: string|null; severity: string|null; status: string; status_color: string
  due_date: string|null; progress: { total: number; done: number; pct: number }
  internal_notes: string|null; created_at: string
}
interface HearingSummary {
  id: number; hearing_number: string; case_id: number
  case_number: string|null; case_title: string|null; employee_name: string
  category: string|null; severity: string|null; hearing_type: string
  status: string; status_color: string; scheduled_at: string|null
  venue: string|null; venue_type: string; notice_issued: boolean
  my_role: string; created_at: string
}
interface IncidentSummary {
  id: number; incident_number: string; title: string; category: string|null
  severity_level: string; status: string; status_color: string
  incident_date: string|null; investigator: string; auto_assigned: boolean
  is_anonymous: boolean; created_at: string
}
interface WarningSummary {
  id: number; warning_type: string; case_number: string|null; case_id: number|null
  issued_date: string|null; expiry_date: string|null; reason: string|null; is_expired: boolean
}
interface CorrectiveActionSummary {
  id: number; action_number: string; action_type: string; title: string
  due_date: string|null; status: string; progress_percent: number
  case_number: string|null; case_id: number|null; assigned_by: string|null; is_mandatory: boolean
}
interface AppealSummary {
  id: number; appeal_number: string; case_number: string|null; case_id: number|null
  grounds: string; status: string; submitted_at: string|null; verdict: string|null
}
interface CoachingSessionSummary {
  id: number; session_type: string; scheduled_at: string|null; scheduled_at_iso: string|null
  duration_minutes: number|null; venue: string|null; status: string
  my_role: string; with_person: string; case_number: string|null
  corrective_action_id: number|null; agenda: string|null
}
interface CalendarEvent {
  type: string; title: string; subtitle: string
  date_iso: string|null; date_label: string|null; venue: string|null
  color: string; icon: string; ref_id: number; ref_type: string
}

const props = defineProps<{
  myCases:                CaseSummary[]
  myInvestigations:       InvestigationSummary[]
  myHearings:             HearingSummary[]
  myIncidents:            IncidentSummary[]
  investigatingIncidents: IncidentSummary[]
  teamCases:              CaseSummary[]
  hasReports:             boolean
  canSeeDashboard:        boolean
  myWarnings:             WarningSummary[]
  myCorrectiveActions:    CorrectiveActionSummary[]
  myAppeals:              AppealSummary[]
  myCoachingSessions:     CoachingSessionSummary[]
  calendarEvents:         CalendarEvent[]
}>()

const tab = ref('overview')
const teamView = ref(false)

const severityColor = (s: string|null) => ({ minor: 'success', serious: 'warning', gross: 'error' })[s ?? ''] ?? 'grey'
const cap = (s: string|null|undefined) => s ? s.charAt(0).toUpperCase() + s.slice(1) : '—'

// ── Notes dialog ──────────────────────────────────────────────────────────────
const notesDialog = ref(false)
const notesCaseId = ref<number|null>(null)
const notesCaseLabel = ref('')
const notesForm = useForm({ internal_notes: '' })

function openNotes(caseId: number, label: string, current: string|null) {
  notesCaseId.value = caseId
  notesCaseLabel.value = label
  notesForm.internal_notes = current ?? ''
  notesDialog.value = true
}
function submitNotes() {
  if (!notesCaseId.value) return
  notesForm.post(route('disciplinary.my-dashboard.save-notes', { case: notesCaseId.value }), {
    onSuccess: () => { notesDialog.value = false },
  })
}

const warningTypeColor = (t: string) => ({ verbal: 'warning', written: 'orange', final_written: 'error' })[t] ?? 'grey'
const appealStatusColor = (s: string) => ({ submitted: 'primary', under_review: 'warning', hearing_scheduled: 'deep-purple', decided: 'success', withdrawn: 'grey', lapsed: 'grey' })[s] ?? 'grey'
const actionStatusColor = (s: string) => ({ pending: 'warning', in_progress: 'primary', completed: 'success', cancelled: 'grey' })[s] ?? 'grey'

// ── Calendar ──────────────────────────────────────────────────────────────────
const calendarDate = ref(new Date())
const selectedDay = ref<string|null>(null)

const calendarYear  = computed(() => calendarDate.value.getFullYear())
const calendarMonth = computed(() => calendarDate.value.getMonth())
const calendarMonthLabel = computed(() =>
  calendarDate.value.toLocaleString('default', { month: 'long', year: 'numeric' })
)

function prevMonth() {
  const d = new Date(calendarDate.value)
  d.setMonth(d.getMonth() - 1)
  calendarDate.value = d
  selectedDay.value = null
}
function nextMonth() {
  const d = new Date(calendarDate.value)
  d.setMonth(d.getMonth() + 1)
  calendarDate.value = d
  selectedDay.value = null
}

const calendarDays = computed(() => {
  const year  = calendarYear.value
  const month = calendarMonth.value
  const firstDay = new Date(year, month, 1).getDay()
  const daysInMonth = new Date(year, month + 1, 0).getDate()
  const days: { date: string; day: number; isToday: boolean; events: CalendarEvent[] }[] = []

  // leading blanks
  for (let i = 0; i < firstDay; i++) days.push({ date: '', day: 0, isToday: false, events: [] })

  const todayStr = new Date().toISOString().slice(0, 10)
  for (let d = 1; d <= daysInMonth; d++) {
    const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`
    const dayEvents = props.calendarEvents.filter(e => e.date_iso?.slice(0, 10) === dateStr)
    days.push({ date: dateStr, day: d, isToday: dateStr === todayStr, events: dayEvents })
  }
  return days
})

const selectedDayEvents = computed(() =>
  selectedDay.value
    ? props.calendarEvents.filter(e => e.date_iso?.slice(0, 10) === selectedDay.value)
    : []
)

const totalCalendarEvents = computed(() => props.calendarEvents.length)

// ── Stat counts ───────────────────────────────────────────────────────────────
const stats = computed(() => [
  { label: t('disciplinary.my_cases'),               value: props.myCases.length,                icon: 'tabler-briefcase-2',    color: 'primary' },
  { label: t('disciplinary.investigating'),           value: props.myInvestigations.length,       icon: 'tabler-user-search',       color: 'warning' },
  { label: t('disciplinary.my_hearings'),             value: props.myHearings.length,             icon: 'tabler-gavel',                color: 'deep-purple' },
  { label: t('disciplinary.my_incidents'),            value: props.myIncidents.length,            icon: 'tabler-alert-circle', color: 'error' },
  { label: t('disciplinary.investigating_incidents'), value: props.investigatingIncidents.length,  icon: 'tabler-search',              color: 'teal' },
  { label: 'My Warnings',                             value: props.myWarnings.length,              icon: 'tabler-alert-triangle',                color: 'orange' },
  { label: 'Corrective Actions',                      value: props.myCorrectiveActions.length,     icon: 'tabler-clipboard-check',      color: 'indigo' },
  { label: 'My Appeals',                              value: props.myAppeals.length,               icon: 'tabler-scale',        color: 'blue-grey' },
  { label: 'Upcoming Events',                         value: totalCalendarEvents.value,            icon: 'tabler-calendar-clock',       color: 'cyan-darken-1' },
])

const activeList = computed<CaseSummary[]>(() =>
  teamView.value ? props.teamCases : props.myCases
)
</script>

<template>
  <div class="pa-4">

    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-5 flex-wrap ga-2">
      <div>
        <p class="text-overline text-medium-emphasis mb-0">{{ t('disciplinary.module_label') }}</p>
        <h1 class="text-h5 font-weight-bold">{{ t('disciplinary.my_dashboard') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-0">{{ t('disciplinary.my_dashboard_subtitle') }}</p>
      </div>
      <div class="d-flex ga-2 flex-wrap">
        <VBtn v-if="canSeeDashboard" :variant="teamView ? 'flat' : 'outlined'" color="primary"
          prepend-icon="tabler-users" @click="teamView = !teamView">
          {{ teamView ? t('disciplinary.my_view') : t('disciplinary.team_view') }}
        </VBtn>
        <VBtn variant="outlined" prepend-icon="tabler-plus" :href="route('disciplinary.incidents.report')">{{ t('disciplinary.report_incident') }}</VBtn>
        <VBtn v-if="canSeeDashboard" variant="outlined" prepend-icon="tabler-layout-dashboard" :href="route('disciplinary.dashboard')">{{ t('disciplinary.dashboard') }}</VBtn>
      </div>
    </div>

    <!-- Stat cards -->
    <VRow dense class="mb-4">
      <VCol v-for="s in stats" :key="s.label" cols="6" sm="4" md="auto" class="flex-grow-1">
        <VCard variant="tonal" :color="s.color" class="pa-3 text-center">
          <VIcon :color="s.color" size="28" class="mb-1">{{ s.icon }}</VIcon>
          <div class="text-h5 font-weight-bold">{{ s.value }}</div>
          <div class="text-caption text-medium-emphasis">{{ s.label }}</div>
        </VCard>
      </VCol>
    </VRow>

    <!-- Team view banner -->
    <VAlert v-if="teamView" type="info" variant="tonal" density="compact" class="mb-4" prepend-icon="tabler-users">
      {{ t('disciplinary.showing_team_cases') }}
    </VAlert>

    <!-- Main tabs -->
    <VCard>
      <VTabs v-model="tab" color="primary">
        <VTab value="overview">
          {{ teamView ? t('disciplinary.team_cases') : t('disciplinary.my_cases') }}
          <VChip class="ml-2" size="x-small">{{ activeList.length }}</VChip>
        </VTab>
        <VTab v-if="!teamView" value="investigating">
          {{ t('disciplinary.investigating') }}
          <VChip class="ml-2" size="x-small">{{ myInvestigations.length }}</VChip>
        </VTab>
        <VTab v-if="!teamView" value="hearings">
          {{ t('disciplinary.my_hearings') }}
          <VChip class="ml-2" size="x-small">{{ myHearings.length }}</VChip>
        </VTab>
        <VTab v-if="!teamView" value="incidents">
          {{ t('disciplinary.my_incidents') }}
          <VChip class="ml-2" size="x-small">{{ myIncidents.length }}</VChip>
        </VTab>
        <VTab v-if="!teamView && investigatingIncidents.length > 0" value="inv_incidents">
          {{ t('disciplinary.investigating_incidents') }}
          <VChip class="ml-2" size="x-small">{{ investigatingIncidents.length }}</VChip>
        </VTab>
        <VTab v-if="!teamView && myWarnings.length > 0" value="warnings">
          My Warnings
          <VChip class="ml-2" size="x-small" color="orange">{{ myWarnings.length }}</VChip>
        </VTab>
        <VTab v-if="!teamView && myCorrectiveActions.length > 0" value="corrective_actions">
          Corrective Actions
          <VChip class="ml-2" size="x-small" color="indigo">{{ myCorrectiveActions.length }}</VChip>
        </VTab>
        <VTab v-if="!teamView && myAppeals.length > 0" value="appeals">
          My Appeals
          <VChip class="ml-2" size="x-small" color="blue-grey">{{ myAppeals.length }}</VChip>
        </VTab>
        <VTab v-if="!teamView" value="calendar">
          <VIcon start>tabler-calendar-clock</VIcon>
          My Calendar
          <VChip v-if="totalCalendarEvents" class="ml-2" size="x-small" color="cyan-darken-1">{{ totalCalendarEvents }}</VChip>
        </VTab>
      </VTabs>
      <VDivider />

      <VTabsWindow v-model="tab">

        <!-- ── My Cases / Team Cases ─────────────────────────────────────────── -->
        <VTabsWindowItem value="overview">
          <VCardText class="pa-0">
            <template v-if="!activeList.length">
              <div class="text-center py-10 text-medium-emphasis">
                <VIcon size="48" class="mb-2">tabler-briefcase</VIcon>
                <p>{{ t('disciplinary.no_cases') }}</p>
              </div>
            </template>
            <VExpansionPanels v-else flat>
              <VExpansionPanel v-for="c in activeList" :key="c.id" rounded="0">
                <VExpansionPanelTitle>
                  <div class="d-flex align-center ga-3 flex-wrap">
                    <VChip :color="c.status_color" size="small">{{ c.status }}</VChip>
                    <span class="font-weight-medium">{{ c.case_number }}</span>
                    <span class="text-body-2 text-medium-emphasis">{{ c.title }}</span>
                    <VChip v-if="c.severity" :color="severityColor(c.severity)" size="x-small">{{ cap(c.severity) }}</VChip>
                  </div>
                </VExpansionPanelTitle>
                <VExpansionPanelText>
                  <VRow dense class="mb-2">
                    <VCol cols="12" sm="6" md="4">
                      <div class="text-caption text-medium-emphasis">{{ t('disciplinary.category') }}</div>
                      <div>{{ c.category ?? '—' }}</div>
                    </VCol>
                    <VCol cols="12" sm="6" md="4">
                      <div class="text-caption text-medium-emphasis">{{ t('disciplinary.incident_date') }}</div>
                      <div>{{ c.incident_date ?? '—' }}</div>
                    </VCol>
                    <VCol cols="12" sm="6" md="4">
                      <div class="text-caption text-medium-emphasis">{{ t('disciplinary.employee') }}</div>
                      <div>{{ c.employee_name }}</div>
                    </VCol>
                  </VRow>
                  <div class="d-flex ga-2 justify-end">
                    <VBtn size="small" variant="outlined" prepend-icon="tabler-eye" :href="route('disciplinary.cases.show', { case: c.id })">{{ t('disciplinary.view_case') }}</VBtn>
                  </div>
                </VExpansionPanelText>
              </VExpansionPanel>
            </VExpansionPanels>
          </VCardText>
        </VTabsWindowItem>

        <!-- ── My Investigations ──────────────────────────────────────────────── -->
        <VTabsWindowItem value="investigating">
          <VCardText class="pa-0">
            <template v-if="!myInvestigations.length">
              <div class="text-center py-10 text-medium-emphasis">
                <VIcon size="48" class="mb-2">tabler-user-search</VIcon>
                <p>{{ t('disciplinary.no_cases') }}</p>
              </div>
            </template>
            <VExpansionPanels v-else flat>
              <VExpansionPanel v-for="inv in myInvestigations" :key="inv.id" rounded="0">
                <VExpansionPanelTitle>
                  <div class="d-flex align-center ga-3 flex-wrap">
                    <VChip :color="inv.status_color" size="small">{{ cap(inv.status.replace('_',' ')) }}</VChip>
                    <span class="font-weight-medium">{{ inv.investigation_number }}</span>
                    <span class="text-body-2 text-medium-emphasis">{{ inv.employee_name }} — {{ inv.case_title }}</span>
                    <VChip v-if="inv.severity" :color="severityColor(inv.severity)" size="x-small">{{ cap(inv.severity) }}</VChip>
                  </div>
                </VExpansionPanelTitle>
                <VExpansionPanelText>
                  <VRow dense class="mb-3">
                    <VCol cols="12" sm="6" md="3">
                      <div class="text-caption text-medium-emphasis">{{ t('disciplinary.case') }}</div>
                      <div class="font-weight-medium">{{ inv.case_number ?? '—' }}</div>
                    </VCol>
                    <VCol cols="12" sm="6" md="3">
                      <div class="text-caption text-medium-emphasis">{{ t('disciplinary.employee') }}</div>
                      <div>{{ inv.employee_name }}</div>
                    </VCol>
                    <VCol cols="12" sm="6" md="3">
                      <div class="text-caption text-medium-emphasis">{{ t('disciplinary.due_date') }}</div>
                      <div>{{ inv.due_date ?? '—' }}</div>
                    </VCol>
                    <VCol cols="12" sm="6" md="3">
                      <div class="text-caption text-medium-emphasis">{{ t('disciplinary.progress') }}</div>
                      <div v-if="inv.progress.total > 0" class="d-flex align-center ga-1">
                        <VProgressCircular :model-value="inv.progress.pct" :color="inv.status_color" size="24" width="3">
                          <span style="font-size:7px">{{ inv.progress.pct }}%</span>
                        </VProgressCircular>
                        <span class="text-caption">{{ inv.progress.done }}/{{ inv.progress.total }}</span>
                      </div>
                      <div v-else class="text-caption text-medium-emphasis">—</div>
                    </VCol>
                  </VRow>
                  <!-- Notes card -->
                  <VCard variant="tonal" color="warning" class="pa-3 mb-3">
                    <div class="text-caption font-weight-medium mb-1">{{ t('disciplinary.my_notes') }}</div>
                    <div v-if="inv.internal_notes" class="text-body-2 mb-2">{{ inv.internal_notes }}</div>
                    <div v-else class="text-body-2 text-medium-emphasis mb-2">{{ t('disciplinary.no_notes_yet') }}</div>
                    <VBtn size="x-small" variant="outlined" prepend-icon="tabler-pencil"
                      @click="openNotes(inv.case_id, inv.investigation_number, inv.internal_notes)">
                      {{ t('disciplinary.edit_notes') }}
                    </VBtn>
                  </VCard>
                  <div class="d-flex ga-2 justify-end">
                    <VBtn size="small" variant="outlined" prepend-icon="tabler-eye"
                      :href="route('disciplinary.investigations.show', inv.id)">
                      {{ t('disciplinary.view') }}
                    </VBtn>
                    <VBtn size="small" variant="tonal" color="primary" prepend-icon="tabler-briefcase"
                      :href="route('disciplinary.cases.show', { case: inv.case_id })">
                      {{ t('disciplinary.view_case') }}
                    </VBtn>
                  </div>
                </VExpansionPanelText>
              </VExpansionPanel>
            </VExpansionPanels>
          </VCardText>
        </VTabsWindowItem>

        <!-- ── My Hearings (as Chairperson) ──────────────────────────────────── -->
        <VTabsWindowItem value="hearings">
          <VCardText class="pa-0">
            <template v-if="!myHearings.length">
              <div class="text-center py-10 text-medium-emphasis">
                <VIcon size="48" class="mb-2">tabler-gavel</VIcon>
                <p>{{ t('disciplinary.no_cases') }}</p>
              </div>
            </template>
            <VExpansionPanels v-else flat>
              <VExpansionPanel v-for="h in myHearings" :key="h.id" rounded="0">
                <VExpansionPanelTitle>
                  <div class="d-flex align-center ga-3 flex-wrap">
                    <VChip :color="h.status_color" size="small">{{ cap(h.status.replace('_',' ')) }}</VChip>
                    <span class="font-weight-medium">{{ h.hearing_number }}</span>
                    <span class="text-body-2 text-medium-emphasis">{{ h.employee_name }} — {{ h.case_title }}</span>
                    <VChip size="x-small" color="grey">{{ h.hearing_type }}</VChip>
                    <VChip size="x-small" color="deep-purple" variant="outlined">{{ h.my_role }}</VChip>
                  </div>
                </VExpansionPanelTitle>
                <VExpansionPanelText>
                  <VRow dense class="mb-3">
                    <VCol cols="12" sm="6" md="3">
                      <div class="text-caption text-medium-emphasis">{{ t('disciplinary.case') }}</div>
                      <div class="font-weight-medium">{{ h.case_number ?? '—' }}</div>
                    </VCol>
                    <VCol cols="12" sm="6" md="3">
                      <div class="text-caption text-medium-emphasis">{{ t('disciplinary.employee') }}</div>
                      <div>{{ h.employee_name }}</div>
                    </VCol>
                    <VCol cols="12" sm="6" md="3">
                      <div class="text-caption text-medium-emphasis">{{ t('disciplinary.scheduled_at') }}</div>
                      <div>{{ h.scheduled_at ?? '—' }}</div>
                    </VCol>
                    <VCol cols="12" sm="6" md="3">
                      <div class="text-caption text-medium-emphasis">{{ t('disciplinary.venue') }}</div>
                      <div>{{ h.venue ?? cap(h.venue_type) }}</div>
                    </VCol>
                  </VRow>
                  <div class="d-flex align-center ga-2 mb-3">
                    <VIcon :color="h.notice_issued ? 'success' : 'grey'" size="18">
                      {{ h.notice_issued ? 'tabler-mail-check' : 'tabler-mail' }}
                    </VIcon>
                    <span class="text-caption">{{ t('disciplinary.notice_issued') }}</span>
                  </div>
                  <div class="d-flex ga-2 justify-end">
                    <VBtn size="small" variant="outlined" prepend-icon="tabler-gavel"
                      :href="route('disciplinary.hearings.show', h.id)">
                      {{ t('disciplinary.view') }}
                    </VBtn>
                    <VBtn size="small" variant="tonal" color="primary" prepend-icon="tabler-briefcase"
                      :href="route('disciplinary.cases.show', { case: h.case_id })">
                      {{ t('disciplinary.view_case') }}
                    </VBtn>
                  </div>
                </VExpansionPanelText>
              </VExpansionPanel>
            </VExpansionPanels>
          </VCardText>
        </VTabsWindowItem>

        <!-- ── My Incidents ───────────────────────────────────────────────────── -->
        <VTabsWindowItem value="incidents">
          <VCardText>
            <div class="d-flex justify-end mb-3">
              <VBtn size="small" color="primary" prepend-icon="tabler-plus" :href="route('disciplinary.incidents.report')">{{ t('disciplinary.report_incident') }}</VBtn>
            </div>
            <div v-if="!myIncidents.length" class="text-center py-8 text-medium-emphasis">
              <VIcon size="48" class="mb-2">tabler-alert-circle</VIcon>
              <p>{{ t('disciplinary.no_incidents') }}</p>
            </div>
            <VRow dense>
              <VCol v-for="i in myIncidents" :key="i.id" cols="12" md="6">
                <VCard variant="outlined" class="pa-3">
                  <div class="d-flex align-center justify-space-between mb-2">
                    <div class="d-flex align-center ga-2">
                      <VChip :color="i.status_color" size="x-small">{{ cap(i.status.replace('_',' ')) }}</VChip>
                      <span class="font-weight-medium text-body-2">{{ i.incident_number }}</span>
                    </div>
                    <VChip :color="severityColor(i.severity_level)" size="x-small">{{ cap(i.severity_level) }}</VChip>
                  </div>
                  <div class="font-weight-medium mb-1">{{ i.title }}</div>
                  <div class="text-caption text-medium-emphasis mb-2">
                    {{ i.incident_date ?? '—' }} · {{ i.category ?? t('disciplinary.no_category') }}
                  </div>
                  <div class="text-caption d-flex align-center ga-1">
                    <VIcon size="12">tabler-user-search</VIcon>
                    {{ i.investigator }}
                    <VIcon v-if="i.auto_assigned" size="12" color="primary">tabler-tool</VIcon>
                  </div>
                  <div class="d-flex justify-end mt-2">
                    <VBtn size="x-small" variant="text" color="primary" prepend-icon="tabler-eye"
                      :href="route('disciplinary.incidents.show', { incident: i.id })">{{ t('disciplinary.view') }}</VBtn>
                  </div>
                </VCard>
              </VCol>
            </VRow>
          </VCardText>
        </VTabsWindowItem>

        <!-- ── Investigating Incidents ─────────────────────────────────────────── -->
        <VTabsWindowItem value="inv_incidents">
          <VCardText>
            <div v-if="!investigatingIncidents.length" class="text-center py-8 text-medium-emphasis">
              <VIcon size="48" class="mb-2">tabler-search</VIcon>
              <p>{{ t('disciplinary.no_incidents') }}</p>
            </div>
            <VRow dense>
              <VCol v-for="i in investigatingIncidents" :key="i.id" cols="12" md="6">
                <VCard variant="outlined" class="pa-3">
                  <div class="d-flex align-center justify-space-between mb-2">
                    <div class="d-flex align-center ga-2">
                      <VChip :color="i.status_color" size="x-small">{{ cap(i.status.replace('_',' ')) }}</VChip>
                      <span class="font-weight-medium text-body-2">{{ i.incident_number }}</span>
                    </div>
                    <VChip :color="severityColor(i.severity_level)" size="x-small">{{ cap(i.severity_level) }}</VChip>
                  </div>
                  <div class="font-weight-medium mb-1">{{ i.title }}</div>
                  <div class="text-caption text-medium-emphasis mb-2">
                    {{ i.incident_date ?? '—' }} · {{ i.category ?? t('disciplinary.no_category') }}
                  </div>
                  <div class="d-flex justify-end mt-2">
                    <VBtn size="x-small" variant="text" color="primary" prepend-icon="tabler-eye"
                      :href="route('disciplinary.incidents.show', { incident: i.id })">{{ t('disciplinary.view') }}</VBtn>
                  </div>
                </VCard>
              </VCol>
            </VRow>
          </VCardText>
        </VTabsWindowItem>

        <!-- ── My Warnings ───────────────────────────────────────────────────── -->
        <VTabsWindowItem value="warnings">
          <VCardText>
            <div v-if="!myWarnings.length" class="text-center py-8 text-medium-emphasis">
              <VIcon size="48" class="mb-2">tabler-alert-triangle</VIcon>
              <p>No active warnings on record.</p>
            </div>
            <VRow dense>
              <VCol v-for="w in myWarnings" :key="w.id" cols="12" md="6">
                <VCard variant="outlined" class="pa-3">
                  <div class="d-flex align-center justify-space-between mb-2">
                    <VChip :color="warningTypeColor(w.warning_type)" size="small">
                      {{ cap(w.warning_type.replace('_', ' ')) }}
                    </VChip>
                    <span class="text-caption text-medium-emphasis">{{ w.issued_date }}</span>
                  </div>
                  <div class="text-body-2 mb-1">{{ w.reason ?? '—' }}</div>
                  <div class="text-caption text-medium-emphasis d-flex align-center ga-2">
                    <VIcon size="12">tabler-calendar-x</VIcon>
                    Expires: {{ w.expiry_date ?? 'N/A' }}
                    <span v-if="w.case_number">· Case: {{ w.case_number }}</span>
                  </div>
                  <div v-if="w.case_id" class="d-flex justify-end mt-2">
                    <VBtn size="x-small" variant="text" color="primary" prepend-icon="tabler-briefcase"
                      :href="route('disciplinary.cases.show', { case: w.case_id })">View Case</VBtn>
                  </div>
                </VCard>
              </VCol>
            </VRow>
          </VCardText>
        </VTabsWindowItem>

        <!-- ── My Corrective Actions ──────────────────────────────────────────── -->
        <VTabsWindowItem value="corrective_actions">
          <VCardText>
            <div v-if="!myCorrectiveActions.length" class="text-center py-8 text-medium-emphasis">
              <VIcon size="48" class="mb-2">tabler-clipboard-check</VIcon>
              <p>No corrective actions assigned to you.</p>
            </div>
            <VRow dense>
              <VCol v-for="a in myCorrectiveActions" :key="a.id" cols="12" md="6">
                <VCard variant="outlined" class="pa-3">
                  <div class="d-flex align-center justify-space-between mb-2">
                    <div class="d-flex align-center ga-2">
                      <VChip :color="actionStatusColor(a.status)" size="x-small">{{ cap(a.status.replace('_', ' ')) }}</VChip>
                      <span class="font-weight-medium text-body-2">{{ a.action_number }}</span>
                    </div>
                    <VChip v-if="a.is_mandatory" color="error" size="x-small">Mandatory</VChip>
                  </div>
                  <div class="font-weight-medium mb-1">{{ a.title }}</div>
                  <div class="text-caption text-medium-emphasis mb-2">
                    {{ cap(a.action_type) }} · Due: {{ a.due_date ?? '—' }}
                  </div>
                  <VProgressLinear
                    :model-value="a.progress_percent" :color="actionStatusColor(a.status)"
                    rounded height="4" class="mb-2"
                  />
                  <div class="text-caption text-medium-emphasis d-flex align-center ga-1">
                    <VIcon size="12">tabler-user</VIcon>{{ a.assigned_by ?? '—' }}
                    <span v-if="a.case_number">· {{ a.case_number }}</span>
                  </div>
                  <div v-if="a.case_id" class="d-flex justify-end mt-2">
                    <VBtn size="x-small" variant="text" color="primary" prepend-icon="tabler-briefcase"
                      :href="route('disciplinary.cases.show', { case: a.case_id })">View Case</VBtn>
                  </div>
                </VCard>
              </VCol>
            </VRow>
          </VCardText>
        </VTabsWindowItem>

        <!-- ── My Appeals ─────────────────────────────────────────────────────── -->
        <VTabsWindowItem value="appeals">
          <VCardText>
            <div v-if="!myAppeals.length" class="text-center py-8 text-medium-emphasis">
              <VIcon size="48" class="mb-2">tabler-scale</VIcon>
              <p>No appeals submitted.</p>
            </div>
            <VRow dense>
              <VCol v-for="a in myAppeals" :key="a.id" cols="12" md="6">
                <VCard variant="outlined" class="pa-3">
                  <div class="d-flex align-center justify-space-between mb-2">
                    <div class="d-flex align-center ga-2">
                      <VChip :color="appealStatusColor(a.status)" size="x-small">{{ cap(a.status.replace('_', ' ')) }}</VChip>
                      <span class="font-weight-medium text-body-2">{{ a.appeal_number }}</span>
                    </div>
                    <VChip v-if="a.verdict" :color="a.verdict === 'upheld' ? 'success' : a.verdict === 'dismissed' ? 'error' : 'warning'" size="x-small">
                      {{ cap(a.verdict.replace('_', ' ')) }}
                    </VChip>
                  </div>
                  <div class="text-body-2 mb-1 text-medium-emphasis">{{ a.grounds?.slice(0, 120) }}{{ (a.grounds?.length ?? 0) > 120 ? '…' : '' }}</div>
                  <div class="text-caption text-medium-emphasis">
                    Submitted: {{ a.submitted_at ?? '—' }}
                    <span v-if="a.case_number">· Case: {{ a.case_number }}</span>
                  </div>
                  <div class="d-flex ga-2 justify-end mt-2">
                    <VBtn size="x-small" variant="text" color="primary" prepend-icon="tabler-eye"
                      :href="route('disciplinary.appeals.show', { appeal: a.id })">View Appeal</VBtn>
                    <VBtn v-if="a.case_id" size="x-small" variant="text" color="grey" prepend-icon="tabler-briefcase"
                      :href="route('disciplinary.cases.show', { case: a.case_id })">View Case</VBtn>
                  </div>
                </VCard>
              </VCol>
            </VRow>
          </VCardText>
        </VTabsWindowItem>

        <!-- ── Calendar ─────────────────────────────────────────────────────── -->
        <VTabsWindowItem value="calendar">
          <VCardText class="pa-4">

            <!-- Month navigation -->
            <div class="d-flex align-center justify-space-between mb-4">
              <VBtn icon="tabler-chevron-left" variant="text" @click="prevMonth" />
              <span class="text-body-1 font-weight-bold">{{ calendarMonthLabel }}</span>
              <VBtn icon="tabler-chevron-right" variant="text" @click="nextMonth" />
            </div>

            <!-- Day-of-week headers -->
            <div class="d-grid calendar-grid mb-1">
              <div v-for="d in ['Sun','Mon','Tue','Wed','Thu','Fri','Sat']" :key="d"
                class="text-caption text-medium-emphasis text-center font-weight-bold pa-1">{{ d }}</div>
            </div>

            <!-- Day cells -->
            <div class="d-grid calendar-grid">
              <div
                v-for="(cell, idx) in calendarDays" :key="idx"
                class="calendar-cell rounded pa-1"
                :class="{
                  'calendar-cell--today': cell.isToday,
                  'calendar-cell--selected': selectedDay === cell.date && cell.date,
                  'calendar-cell--has-events': cell.events.length > 0,
                  'calendar-cell--empty': !cell.date,
                }"
                @click="cell.date ? (selectedDay = selectedDay === cell.date ? null : cell.date) : null"
              >
                <template v-if="cell.date">
                  <div class="text-caption font-weight-medium mb-1"
                    :class="cell.isToday ? 'text-primary font-weight-bold' : ''">
                    {{ cell.day }}
                  </div>
                  <div class="d-flex flex-column ga-1">
                    <VTooltip v-for="(ev, ei) in cell.events.slice(0, 3)" :key="ei"
                      :text="ev.title + (ev.venue ? ' @ ' + ev.venue : '')">
                      <template #activator="{ props: tp }">
                        <div v-bind="tp"
                          class="rounded px-1 text-white text-caption event-pill"
                          :style="{ background: `rgb(var(--v-theme-${ev.color}))` }"
                        >{{ ev.title.length > 14 ? ev.title.slice(0, 13) + '…' : ev.title }}</div>
                      </template>
                    </VTooltip>
                    <div v-if="cell.events.length > 3" class="text-caption text-medium-emphasis">
                      +{{ cell.events.length - 3 }} more
                    </div>
                  </div>
                </template>
              </div>
            </div>

            <!-- Selected day detail panel -->
            <VExpandTransition>
              <div v-if="selectedDay && selectedDayEvents.length" class="mt-4">
                <VDivider class="mb-3" />
                <div class="text-body-2 font-weight-bold mb-2">
                  Events on {{ new Date(selectedDay + 'T00:00:00').toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) }}
                </div>
                <VList density="compact" class="pa-0">
                  <VListItem
                    v-for="ev in selectedDayEvents" :key="ev.ref_type + ev.ref_id"
                    class="rounded mb-2"
                    style="border: 1px solid rgba(0,0,0,0.08)"
                  >
                    <template #prepend>
                      <VIcon :color="ev.color" size="20" class="mr-2">{{ ev.icon }}</VIcon>
                    </template>
                    <VListItemTitle class="text-body-2 font-weight-medium">{{ ev.title }}</VListItemTitle>
                    <VListItemSubtitle class="text-caption">
                      {{ ev.subtitle }}
                      <span v-if="ev.date_label"> · {{ ev.date_label.includes(':') ? ev.date_label.split(' ').slice(-1)[0] : '' }}</span>
                      <span v-if="ev.venue"> · {{ ev.venue }}</span>
                    </VListItemSubtitle>
                    <template #append>
                      <VChip :color="ev.color" size="x-small" variant="tonal">{{ ev.type.replace('_', ' ') }}</VChip>
                    </template>
                  </VListItem>
                </VList>
              </div>
            </VExpandTransition>

            <!-- Upcoming list (all future events) -->
            <VDivider class="my-4" />
            <div class="text-body-2 font-weight-bold mb-2">All Upcoming Events</div>
            <template v-if="!calendarEvents.length">
              <div class="text-center text-medium-emphasis pa-6">
                <VIcon size="40" class="mb-2 opacity-40">tabler-calendar</VIcon>
                <div>No scheduled events</div>
              </div>
            </template>
            <VList v-else density="compact" class="pa-0">
              <VListItem
                v-for="ev in calendarEvents" :key="ev.ref_type + ev.ref_id"
                class="rounded mb-2"
                style="border: 1px solid rgba(0,0,0,0.08)"
              >
                <template #prepend>
                  <VIcon :color="ev.color" size="20">{{ ev.icon }}</VIcon>
                </template>
                <VListItemTitle class="text-body-2 font-weight-medium">{{ ev.title }}</VListItemTitle>
                <VListItemSubtitle class="text-caption">
                  {{ ev.subtitle }}
                  <span v-if="ev.venue"> · {{ ev.venue }}</span>
                </VListItemSubtitle>
                <template #append>
                  <div class="text-caption text-medium-emphasis text-right" style="min-width:90px">{{ ev.date_label }}</div>
                </template>
              </VListItem>
            </VList>

          </VCardText>
        </VTabsWindowItem>

      </VTabsWindow>
    </VCard>

    <!-- Notes Dialog -->
    <VDialog v-model="notesDialog" max-width="520">
      <VCard :title="t('disciplinary.my_notes')">
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-3">{{ notesCaseLabel }}</p>
          <VTextarea
            v-model="notesForm.internal_notes" :label="t('disciplinary.internal_notes')"
            variant="outlined" density="compact" rows="6"
            :hint="t('disciplinary.notes_hint')"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="outlined" @click="notesDialog = false">{{ t('disciplinary.cancel') }}</VBtn>
          <VBtn color="primary" :loading="notesForm.processing" prepend-icon="tabler-device-floppy" @click="submitNotes">{{ t('disciplinary.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

  </div>
</template>

<style scoped>
.calendar-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 2px;
}
.calendar-cell {
  min-height: 64px;
  cursor: pointer;
  border: 1px solid rgba(0,0,0,0.06);
  transition: background 0.15s;
}
.calendar-cell:hover:not(.calendar-cell--empty) {
  background: rgba(0,0,0,0.04);
}
.calendar-cell--today {
  border-color: rgb(var(--v-theme-primary));
  background: rgba(var(--v-theme-primary), 0.05);
}
.calendar-cell--selected {
  background: rgba(var(--v-theme-primary), 0.12) !important;
  border-color: rgb(var(--v-theme-primary));
}
.calendar-cell--empty {
  cursor: default;
  background: transparent;
  border-color: transparent;
}
.event-pill {
  font-size: 10px;
  line-height: 16px;
  white-space: nowrap;
  overflow: hidden;
}
</style>
