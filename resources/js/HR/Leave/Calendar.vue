<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import FullCalendar from '@fullcalendar/vue3'
import dayGridPlugin from '@fullcalendar/daygrid'
import listPlugin from '@fullcalendar/list'
import interactionPlugin from '@fullcalendar/interaction'
import type { CalendarOptions, EventClickArg } from '@fullcalendar/core'
import { useResponsiveLeftSidebar } from '@/@core/composable/useResponsiveSidebar'

const { t } = useI18n()

type CalendarEvent = {
  id: number; employee_id: number; employee_name: string; leave_type: string
  leave_type_id: number; start: string; end: string; color: string
  working_days: number; status: string; is_pending: boolean
}
type LeaveType = { id: number; name: string; color: string }
type Division = { id: number; name: string; headcount: number }
type DayCoverage = { coverage_pct: number; on_leave: number; total: number; risk: 'low' | 'medium' | 'high' | 'critical' }
type DivisionCoverage = Division & { by_date: Record<string, DayCoverage> }
type Heatmap = { dates: string[]; divisions: DivisionCoverage[]; overall_by_date: Record<string, DayCoverage> }
type ClashEmployee = { name: string; leave_type: string; start_date: string; end_date: string; status: string }
type Clash = { division_id: number; division_name: string; concurrent: number; headcount: number; coverage_pct: number; risk: string; employees: ClashEmployee[]; period: { start: string; end: string } }
type CriticalWarning = { id: number; employee_name: string; position: string; division_name: string; leave_type: string; start_date: string; end_date: string; working_days: number; status: string; severity: 'critical' | 'high' | 'medium' }
type AlternativeSuggestion = { start_date: string; end_date: string; avg_coverage: number; working_days: number }

const props = defineProps<{
  events: CalendarEvent[]; leaveTypes: LeaveType[]; heatmap: Heatmap
  clashes: Clash[]; criticalWarnings: CriticalWarning[]; divisions: Division[]
  selectedDivisions: number[]; teamView: boolean
  calendarRole: 'all' | 'senior_manager' | 'manager'
  dateRange: { start: string; end: string }
}>()

const { isLeftSidebarOpen } = useResponsiveLeftSidebar()

const activeTab         = ref<'calendar' | 'heatmap' | 'clashes' | 'critical'>('calendar')
const refCalendar       = ref()
const selectedEvent     = ref<CalendarEvent | null>(null)
const detailDialog      = ref(false)
const filterTypeId      = ref<number | null>(null)
const divisionFilter    = ref<number[]>([...props.selectedDivisions])
const teamViewFilter    = ref<boolean>(props.teamView)

const altDialog         = ref(false)
const altLoading        = ref(false)
const altHasSearched    = ref(false)
const altSuggestions    = ref<AlternativeSuggestion[]>([])
const altTargetEmployee = ref<number | null>(null)
const altPreferredStart = ref('')
const altPreferredEnd   = ref('')
const altWorkingDays    = ref(0)

const riskColor = (risk: string) => ({ low: '#4caf50', medium: '#ff9800', high: '#f44336', critical: '#9c27b0' }[risk] ?? '#9e9e9e')
const riskChipColor = (risk: string) => ({ low: 'success', medium: 'warning', high: 'error', critical: 'purple' }[risk] ?? 'default')
const severityColor = (s: string) => ({ critical: 'error', high: 'warning', medium: 'info' }[s] ?? 'default')

const coverageGradient = (pct: number): string => {
  if (pct >= 80) return '#c8e6c9'
  if (pct >= 65) return '#fff9c4'
  if (pct >= 50) return '#ffcdd2'
  return '#e1bee7'
}

const shortDate = (iso: string) => iso.slice(5)

function applyFilters() {
  const params: Record<string, string | number | boolean | string[]> = {}
  if (divisionFilter.value.length) params.division_ids = divisionFilter.value.map(String)
  if (teamViewFilter.value) params.team_view = 1
  router.get('/leave-management/calendar', params, { preserveState: false, replace: true })
}

const filteredEvents = computed(() =>
  filterTypeId.value ? props.events.filter(e => e.leave_type_id === filterTypeId.value) : props.events
)

const calendarEvents = computed(() =>
  filteredEvents.value.map(e => ({
    id: String(e.id), title: e.employee_name, start: e.start, end: e.end,
    backgroundColor: e.is_pending ? '#9e9e9e' : e.color, borderColor: e.color,
    textColor: '#ffffff', extendedProps: { raw: e },
  }))
)

const onEventClick = (info: EventClickArg) => {
  info.jsEvent.preventDefault()
  selectedEvent.value = info.event.extendedProps.raw as CalendarEvent
  detailDialog.value  = true
}

const calendarOptions = computed<CalendarOptions>(() => ({
  plugins: [dayGridPlugin, listPlugin, interactionPlugin],
  initialView: 'dayGridMonth',
  headerToolbar: { start: 'drawerToggler,prev,next,today', center: 'title', end: 'dayGridMonth,listMonth' },
  events: calendarEvents.value, editable: false, selectable: false,
  dayMaxEvents: 3, navLinks: true, eventClick: onEventClick,
  customButtons: { drawerToggler: { text: '☰', click: () => { isLeftSidebarOpen.value = !isLeftSidebarOpen.value } } },
  eventDidMount(info) {
    const raw = info.event.extendedProps.raw as CalendarEvent
    info.el.title = `${raw.employee_name} — ${raw.leave_type} (${raw.working_days} days) [${raw.status}]`
  },
}))

const heatmapDates   = computed(() => props.heatmap.dates ?? [])
const heatmapDivs    = computed(() => props.heatmap.divisions ?? [])
const overallByDate  = computed(() => props.heatmap.overall_by_date ?? {})

function openAltDialog(event: CalendarEvent) {
  altTargetEmployee.value = event.employee_id
  altPreferredStart.value = event.start
  altPreferredEnd.value   = event.end
  altWorkingDays.value    = event.working_days
  altSuggestions.value    = []
  altHasSearched.value    = false
  altDialog.value         = true
  fetchAlternatives()
}

async function fetchAlternatives() {
  if (!altTargetEmployee.value) return
  altLoading.value = true
  altHasSearched.value = false
  try {
    const res = await fetch('/leave-management/alternative-dates', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
      },
      body: JSON.stringify({
        employee_id: altTargetEmployee.value,
        preferred_start: altPreferredStart.value,
        preferred_end: altPreferredEnd.value,
        working_days: altWorkingDays.value,
      }),
    })
    const data = await res.json()
    altSuggestions.value = data.suggestions ?? []
  } finally {
    altLoading.value    = false
    altHasSearched.value = true
  }
}
</script>

<template>
  <div>
    <!-- Header -->
    <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4 px-1">
      <div>
        <h3 class="text-h5 font-weight-semibold mb-1">{{ t('leave.calendar_title') }}</h3>
        <p class="text-medium-emphasis mb-0 text-body-2">{{ t('team_leave_overview_subtitle') }}</p>
      </div>
      <VBreadcrumbs
        :items="[
          { title: t('leave_calendar.home_breadcrumb'), href: '/', disabled: false },
          { title: t('leave_calendar.leave_management_breadcrumb'), href: '/leave-management', disabled: false },
          { title: t('leave_calendar.calendar_breadcrumb'), disabled: true },
        ]"
        density="compact" class="pa-0"
      >
        <template #divider><i class="ri-arrow-right-s-line" style="font-size:11px" /></template>
      </VBreadcrumbs>
    </div>

    <!-- Tabs -->
    <VTabs v-model="activeTab" class="mb-4" density="comfortable">
      <VTab value="calendar"><i class="ri-calendar-line me-2" />{{ t('leave.calendar_tab') }}</VTab>
      <VTab value="heatmap"><i class="ri-fire-line me-2" />{{ t('leave.coverage_heatmap') }}</VTab>
      <VTab value="clashes"><i class="ri-error-warning-line me-2" />{{ t('leave.clash_alerts') }}
        <VBadge v-if="clashes.length" :content="clashes.length" color="error" inline class="ms-1" />
      </VTab>
      <VTab value="critical"><i class="ri-user-star-line me-2" />{{ t('leave.critical_roles') }}
        <VBadge v-if="criticalWarnings.length" :content="criticalWarnings.length" color="warning" inline class="ms-1" />
      </VTab>
    </VTabs>

    <VWindow v-model="activeTab">
      <VWindowItem value="calendar">
        <VCard>
          <VLayout style="z-index: 0">
            <VNavigationDrawer
              v-model="isLeftSidebarOpen" width="260" absolute touchless location="start"
              class="calendar-add-event-drawer" :temporary="$vuetify.display.mdAndDown"
            >
              <div class="pa-5">
                <p class="text-overline text-medium-emphasis mb-2">{{ t('leave_calendar.view') }}</p>
                <VSwitch
                  v-if="calendarRole === 'all'"
                  v-model="teamViewFilter"
                  :label="t('leave.my_team_only')"
                  color="primary" density="compact" hide-details class="mb-4"
                  @update:model-value="applyFilters"
                />
                <VChip
                  v-else
                  prepend-icon="ri-group-line" color="primary" variant="tonal" size="small" class="mb-4"
                >
                  {{ calendarRole === 'senior_manager' ? t('leave_calendar.your_team_2_levels') : t('leave_calendar.your_direct_reports') }}
                </VChip>

                <p class="text-overline text-medium-emphasis mb-3">{{ t('leave.filter_by_dept') }}</p>
                <VSelect
                  v-model="divisionFilter"
                  :items="divisions"
                  item-title="name" item-value="id"
                  multiple chips closable-chips
                  density="compact" variant="outlined" hide-details clearable
                  :placeholder="t('leave_calendar.all_departments')"
                  class="mb-3"
                  @update:model-value="applyFilters"
                />

                <p class="text-overline text-medium-emphasis mb-3">{{ t('leave.filter_by_type') }}</p>
                <VSelect
                  v-model="filterTypeId"
                  :items="[{ id: null, name: t('leave_calendar.all_types') }, ...leaveTypes]"
                  item-title="name" item-value="id"
                  density="compact" variant="outlined" hide-details clearable
                  :placeholder="t('leave_calendar.all_types')"
                />
              </div>

              <VDivider />

              <div class="pa-5">
                <p class="text-overline text-medium-emphasis mb-3">{{ t('leave_calendar.leave_types_label') }}</p>
                <div class="d-flex flex-column gap-2">
                  <div v-for="lt in leaveTypes" :key="lt.id" class="d-flex align-center gap-2 cursor-pointer"
                    @click="filterTypeId = filterTypeId === lt.id ? null : lt.id">
                    <span class="rounded-circle flex-shrink-0" :style="{ width: '10px', height: '10px', backgroundColor: lt.color }" />
                    <span class="text-body-2" :class="filterTypeId === lt.id ? 'font-weight-bold' : ''">{{ lt.name }}</span>
                  </div>
                  <div v-if="!leaveTypes.length" class="text-caption text-medium-emphasis">{{ t('leave_calendar.no_leave_types') }}</div>
                </div>
              </div>

              <VDivider />

              <div class="pa-5">
                <p class="text-overline text-medium-emphasis mb-2">{{ t('leave_calendar.legend') }}</p>
                <div class="d-flex align-center gap-2 mb-1">
                  <span class="rounded-circle" style="width:10px;height:10px;background:#4caf50;display:inline-block" />
                  <span class="text-caption">{{ t('leave_calendar.approved_legend') }}</span>
                </div>
                <div class="d-flex align-center gap-2">
                  <span class="rounded-circle" style="width:10px;height:10px;background:#9e9e9e;display:inline-block" />
                  <span class="text-caption">{{ t('leave_calendar.pending_legend') }}</span>
                </div>
              </div>
            </VNavigationDrawer>

            <VMain>
              <VCard flat>
                <FullCalendar ref="refCalendar" :options="calendarOptions" />
              </VCard>
            </VMain>
          </VLayout>
        </VCard>
      </VWindowItem>

      <VWindowItem value="heatmap">
        <VCard>
          <VCardTitle class="pa-4">{{ t('leave_calendar.coverage_heatmap_title') }}
            <span class="text-body-2 text-medium-emphasis ms-2">{{ t('leave_calendar.date_range_label', { start: dateRange.start, end: dateRange.end }) }}</span>
          </VCardTitle>
          <VDivider />
          <VCardText class="pa-3">
            <div class="d-flex align-center gap-4 mb-4 flex-wrap">
              <span class="text-caption text-medium-emphasis">{{ t('leave_calendar.risk_level') }}</span>
              <div v-for="(label, color) in {
                '#c8e6c9': t('leave_calendar.risk_low'),
                '#fff9c4': t('leave_calendar.risk_medium'),
                '#ffcdd2': t('leave_calendar.risk_high'),
                '#e1bee7': t('leave_calendar.risk_critical')
              }" :key="color" class="d-flex align-center gap-1">
                <span class="rounded" :style="{ width: '14px', height: '14px', backgroundColor: color, display: 'inline-block', border: '1px solid #ccc' }" />
                <span class="text-caption">{{ label }}</span>
              </div>
            </div>

            <div style="overflow-x: auto">
              <table class="heatmap-table" style="border-collapse: collapse; min-width: 100%">
                <thead>
                  <tr>
                    <th class="heatmap-label text-left pa-2 text-caption text-medium-emphasis" style="min-width:140px">{{ t('leave_calendar.department_col') }}</th>
                    <th v-for="date in heatmapDates" :key="date" class="heatmap-cell pa-1 text-center text-caption"
                      style="min-width: 48px; writing-mode: vertical-rl; transform: rotate(180deg)">{{ shortDate(date) }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td class="pa-2 text-caption font-weight-bold">{{ t('leave_calendar.overall') }}</td>
                    <td v-for="date in heatmapDates" :key="date" class="pa-1 text-center text-caption"
                      :style="{ backgroundColor: coverageGradient(overallByDate[date]?.coverage_pct ?? 100), border: '1px solid #e0e0e0' }"
                      :title="`${date}: ${overallByDate[date]?.coverage_pct ?? 100}% ${t('leave_calendar.coverage_label')}, ${overallByDate[date]?.on_leave ?? 0} ${t('leave.on_leave_today').toLowerCase()}`">
                      {{ overallByDate[date]?.coverage_pct ?? 100 }}%
                    </td>
                  </tr>
                  <tr v-for="div in heatmapDivs" :key="div.id">
                    <td class="pa-2 text-caption">{{ div.name }} <span class="text-medium-emphasis">({{ div.headcount }})</span></td>
                    <td v-for="date in heatmapDates" :key="date" class="pa-1 text-center text-caption"
                      :style="{ backgroundColor: coverageGradient(div.by_date[date]?.coverage_pct ?? 100), border: '1px solid #e0e0e0' }"
                      :title="`${div.name} ${date}: ${div.by_date[date]?.coverage_pct ?? 100}%`">
                      {{ div.by_date[date]?.coverage_pct ?? 100 }}%
                    </td>
                  </tr>
                  <tr v-if="!heatmapDivs.length">
                    <td :colspan="heatmapDates.length + 1" class="pa-8 text-center text-medium-emphasis">{{ t('leave_calendar.no_department_data') }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </VCardText>
        </VCard>
      </VWindowItem>

      <VWindowItem value="clashes">
        <VCard>
          <VCardTitle class="pa-4">{{ t('leave_calendar.clash_alerts_title') }}
            <span class="text-body-2 text-medium-emphasis ms-2">{{ t('leave_calendar.clash_subtitle') }}</span>
          </VCardTitle>
          <VDivider />
          <VCardText class="pa-0">
            <div v-if="!clashes.length" class="pa-8 text-center text-medium-emphasis">
              <i class="ri-checkbox-circle-line" style="font-size:2rem;color:#4caf50" />
              <p class="mt-2 mb-0">{{ t('leave_calendar.no_clash_alerts') }}</p>
            </div>

            <VExpansionPanels v-else variant="accordion" class="pa-3">
              <VExpansionPanel v-for="clash in clashes" :key="clash.division_id">
                <VExpansionPanelTitle>
                  <div class="d-flex align-center gap-3 w-100">
                    <VChip :color="riskChipColor(clash.risk)" size="small" label>{{ clash.risk.toUpperCase() }}</VChip>
                    <span class="font-weight-medium">{{ clash.division_name }}</span>
                    <VSpacer />
                    <span class="text-body-2 text-medium-emphasis me-2">{{ clash.concurrent }} {{ t('leave_calendar.employees_label') }} — {{ clash.coverage_pct }}% {{ t('leave_calendar.coverage_label') }}</span>
                    <span class="text-caption text-medium-emphasis">{{ clash.period.start }} → {{ clash.period.end }}</span>
                  </div>
                </VExpansionPanelTitle>
                <VExpansionPanelText>
                  <VTable density="compact">
                    <thead>
                      <tr>
                        <th>{{ t('leave_calendar.employee_col') }}</th>
                        <th>{{ t('leave_calendar.leave_type_col') }}</th>
                        <th>{{ t('leave_calendar.from_col') }}</th>
                        <th>{{ t('leave_calendar.to_col') }}</th>
                        <th>{{ t('leave_calendar.status_col') }}</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="emp in clash.employees" :key="emp.name + emp.start_date">
                        <td>{{ emp.name }}</td>
                        <td>{{ emp.leave_type }}</td>
                        <td>{{ emp.start_date }}</td>
                        <td>{{ emp.end_date }}</td>
                        <td><VChip size="x-small" :color="emp.status === 'approved' ? 'success' : 'warning'" label>{{ emp.status }}</VChip></td>
                      </tr>
                    </tbody>
                  </VTable>
                </VExpansionPanelText>
              </VExpansionPanel>
            </VExpansionPanels>
          </VCardText>
        </VCard>
      </VWindowItem>

      <VWindowItem value="critical">
        <VCard>
          <VCardTitle class="pa-4">{{ t('leave_calendar.critical_roles_title') }}
            <span class="text-body-2 text-medium-emphasis ms-2">{{ t('leave_calendar.critical_roles_subtitle') }}</span>
          </VCardTitle>
          <VDivider />
          <VCardText class="pa-0">
            <div v-if="!criticalWarnings.length" class="pa-8 text-center text-medium-emphasis">
              <i class="ri-checkbox-circle-line" style="font-size:2rem;color:#4caf50" />
              <p class="mt-2 mb-0">{{ t('leave_calendar.no_critical_warnings') }}</p>
            </div>

            <VTable v-else density="comfortable" hover>
              <thead>
                <tr>
                  <th>{{ t('leave_calendar.severity_col') }}</th>
                  <th>{{ t('leave_calendar.employee_col') }}</th>
                  <th>{{ t('leave_calendar.position_col') }}</th>
                  <th>{{ t('leave_calendar.department_col_short') }}</th>
                  <th>{{ t('leave_calendar.leave_type_col') }}</th>
                  <th>{{ t('leave_calendar.from_col') }}</th>
                  <th>{{ t('leave_calendar.to_col') }}</th>
                  <th>{{ t('leave_calendar.days_col') }}</th>
                  <th>{{ t('leave_calendar.status_col') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="w in criticalWarnings" :key="w.id">
                  <td><VChip :color="severityColor(w.severity)" size="x-small" label>{{ w.severity.toUpperCase() }}</VChip></td>
                  <td class="font-weight-medium">{{ w.employee_name }}</td>
                  <td class="text-caption">{{ w.position }}</td>
                  <td class="text-caption">{{ w.division_name }}</td>
                  <td>{{ w.leave_type }}</td>
                  <td>{{ w.start_date }}</td>
                  <td>{{ w.end_date }}</td>
                  <td>{{ w.working_days }}</td>
                  <td><VChip size="x-small" :color="w.status === 'approved' ? 'success' : 'warning'" label>{{ w.status }}</VChip></td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
        </VCard>
      </VWindowItem>
    </VWindow>

    <!-- Event detail dialog -->
    <VDialog v-model="detailDialog" max-width="460">
      <VCard v-if="selectedEvent">
        <VCardTitle class="pa-4 d-flex align-center gap-2">
          <span class="rounded-circle" :style="{ width: '12px', height: '12px', backgroundColor: selectedEvent.color, display: 'inline-block' }" />
          {{ selectedEvent.employee_name }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VTable density="compact">
            <tbody>
              <tr><td class="text-medium-emphasis w-40">{{ t('leave_calendar.event_detail_title') }}</td><td class="font-weight-medium">{{ selectedEvent.leave_type }}</td></tr>
              <tr><td class="text-medium-emphasis">{{ t('leave_calendar.event_from') }}</td><td>{{ selectedEvent.start }}</td></tr>
              <tr><td class="text-medium-emphasis">{{ t('leave_calendar.event_to') }}</td><td>{{ selectedEvent.end }}</td></tr>
              <tr><td class="text-medium-emphasis">{{ t('leave_calendar.event_working_days') }}</td><td>{{ selectedEvent.working_days }}</td></tr>
              <tr><td class="text-medium-emphasis">{{ t('leave_calendar.event_status') }}</td><td><VChip size="x-small" :color="selectedEvent.is_pending ? 'warning' : 'success'" label>{{ selectedEvent.status }}</VChip></td></tr>
            </tbody>
          </VTable>
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VBtn variant="tonal" color="primary" size="small" @click="openAltDialog(selectedEvent!)">
            <i class="ri-calendar-check-line me-1" />{{ t('leave_calendar.suggest_alternative') }}
          </VBtn>
          <VSpacer />
          <VBtn variant="tonal" @click="detailDialog = false">{{ t('leave_calendar.close') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Alternative dates dialog -->
    <VDialog v-model="altDialog" max-width="520">
      <VCard>
        <VCardTitle class="pa-4">{{ t('leave_calendar.alt_dates_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <p class="text-body-2 text-medium-emphasis mb-4">
            {{ t('leave_calendar.alt_dates_intro', { days: altWorkingDays, date: altPreferredStart }) }}
          </p>

          <VProgressLinear v-if="altLoading" indeterminate color="primary" class="mb-3" />

          <div v-if="altSuggestions.length" class="d-flex flex-column gap-3">
            <VCard v-for="(s, idx) in altSuggestions" :key="idx" variant="outlined" class="pa-3">
              <div class="d-flex align-center gap-3">
                <div class="flex-grow-1">
                  <p class="mb-1 font-weight-medium">{{ s.start_date }} → {{ s.end_date }}</p>
                  <p class="text-caption text-medium-emphasis mb-0">{{ s.working_days }} {{ t('leave_calendar.working_days_label') }}</p>
                </div>
                <div class="text-right">
                  <VChip :color="s.avg_coverage >= 80 ? 'success' : s.avg_coverage >= 65 ? 'warning' : 'error'" size="small" label>{{ s.avg_coverage }}% {{ t('leave_calendar.coverage_label') }}</VChip>
                </div>
              </div>
            </VCard>
            <p class="text-caption text-medium-emphasis mb-0">{{ t('leave_calendar.alt_dates_footer') }}</p>
          </div>

          <div v-if="altHasSearched && !altSuggestions.length && !altLoading" class="text-center text-medium-emphasis pa-4">
            <i class="ri-calendar-close-line" style="font-size:2rem" />
            <p class="mt-2 mb-3">{{ t('leave_calendar.no_alt_windows') }}</p>
            <VBtn size="small" variant="tonal" color="primary" @click="fetchAlternatives">
              <i class="ri-refresh-line me-1" />{{ t('leave_calendar.retry') }}
            </VBtn>
          </div>
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="tonal" @click="altDialog = false">{{ t('leave_calendar.close') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style lang="scss">
@use "@core/scss/template/libs/full-calendar";

.calendar-add-event-drawer {
  &.v-navigation-drawer:not(.v-navigation-drawer--temporary) {
    border-end-start-radius: 0.375rem;
    border-start-start-radius: 0.375rem;
  }
  &.v-navigation-drawer--temporary:not(.v-navigation-drawer--active) {
    transform: translateX(-110%) !important;
  }
}
</style>

<style lang="scss" scoped>
.v-layout {
  overflow: visible !important;
  .v-card { overflow: visible; }
}

.heatmap-table {
  font-size: 0.75rem;
  th, td {
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    white-space: nowrap;
  }
}
</style>
