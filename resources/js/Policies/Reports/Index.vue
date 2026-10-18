<script setup lang="ts">
import { computed, reactive, ref } from 'vue'

type ReportRow = Record<string, unknown>

interface OrgUnit {
  id: number | string
  name: string
  parent_id?: number | string | null
}

interface Props {
  compliance: ReportRow[]
  attestations: ReportRow[]
  policies: ReportRow[]
  questionResults: ReportRow[]
  policyEvents: ReportRow[]
  policyCases: ReportRow[]
  investigations: ReportRow[]
  exceptions: ReportRow[]
  divisions: OrgUnit[]
  departments: OrgUnit[]
  sections: OrgUnit[]
}

const props = withDefaults(defineProps<Props>(), {
  compliance: () => [],
  attestations: () => [],
  policies: () => [],
  questionResults: () => [],
  policyEvents: () => [],
  policyCases: () => [],
  investigations: () => [],
  exceptions: () => [],
  divisions: () => [],
  departments: () => [],
  sections: () => [],
})

const activeTab = ref('violation')
const toast = reactive({ show: false, text: '', color: 'success' })

const filters = reactive({
  division: null as number | string | null,
  department: null as number | string | null,
  section: null as number | string | null,
  status: null as string | null,
  dateFrom: null as string | null,
  dateTo: null as string | null,
})

const reportCards = [
  { key: 'violation', title: 'Violation Reports', description: 'Policy cases, outcomes, and investigation status by unit.' },
  { key: 'compliance', title: 'Compliance Reports', description: 'Department-level compliance and adoption scorecards.' },
  { key: 'exception', title: 'Exception Reports', description: 'Exception requests, decisions, and validity windows.' },
  { key: 'attestations', title: 'Attestation Reports', description: 'Acknowledgement completion and overdue attestations.' },
  { key: 'policies', title: 'Policy Inventory Reports', description: 'Policy lifecycle status and attestation requirements.' },
  { key: 'questionResults', title: 'Question Results Reports', description: 'Quiz scores, pass rates, and completion timestamps.' },
  { key: 'policyEvents', title: 'Policy Events Reports', description: 'Auditable event timeline by module and actor.' },
  { key: 'investigations', title: 'Investigation Reports', description: 'Investigation workflow, severity, and resolutions.' },
]

const headersByReport: Record<string, { title: string; key: string }[]> = {
  violation: [
    { title: 'Policy', key: 'policy' },
    { title: 'User', key: 'user' },
    { title: 'Department', key: 'department' },
    { title: 'Severity', key: 'severity' },
    { title: 'Case Status', key: 'case_status' },
    { title: 'Investigation Status', key: 'investigation_status' },
    { title: 'Investigation Result', key: 'investigation_result' },
    { title: 'Created', key: 'created_at' },
  ],
  compliance: [
    { title: 'Department', key: 'department' },
    { title: 'Compliance Score', key: 'compliance_score' },
    { title: 'Policy Adoption %', key: 'policy_adoption_rate' },
    { title: 'Attestation Completion %', key: 'attestation_completion' },
  ],
  exception: [
    { title: 'Policy', key: 'policy' },
    { title: 'User', key: 'user' },
    { title: 'Department', key: 'department' },
    { title: 'Reason', key: 'reason' },
    { title: 'Status', key: 'status' },
    { title: 'Duration', key: 'duration' },
    { title: 'Start Date', key: 'start_date' },
    { title: 'End Date', key: 'end_date' },
    { title: 'Decided At', key: 'approved_at' },
  ],
  attestations: [
    { title: 'Policy', key: 'policy' },
    { title: 'User', key: 'user' },
    { title: 'Department', key: 'department' },
    { title: 'Status', key: 'status' },
    { title: 'Acknowledged', key: 'acknowledged_at' },
    { title: 'Deadline', key: 'deadline' },
    { title: 'Overdue', key: 'overdue' },
  ],
  policies: [
    { title: 'Policy Name', key: 'name' },
    { title: 'Status', key: 'status' },
    { title: 'Department', key: 'department' },
    { title: 'Requires Attestation', key: 'requires_attestation' },
    { title: 'Created Date', key: 'created_date' },
    { title: 'Review Date', key: 'review_date' },
  ],
  questionResults: [
    { title: 'Policy', key: 'policy' },
    { title: 'User', key: 'user' },
    { title: 'Department', key: 'department' },
    { title: 'Score', key: 'score' },
    { title: 'Percentage', key: 'percentage' },
    { title: 'Status', key: 'status' },
    { title: 'Attempted', key: 'attempted_at' },
  ],
  policyEvents: [
    { title: 'Module', key: 'module' },
    { title: 'Action', key: 'action' },
    { title: 'Actor', key: 'actor' },
    { title: 'Department', key: 'department' },
    { title: 'Subject', key: 'subject' },
    { title: 'Timestamp', key: 'timestamp' },
  ],
  investigations: [
    { title: 'Policy', key: 'policy' },
    { title: 'User', key: 'user' },
    { title: 'Department', key: 'department' },
    { title: 'Severity', key: 'severity' },
    { title: 'Status', key: 'status' },
    { title: 'Resolution', key: 'resolution' },
    { title: 'Created', key: 'created_at' },
    { title: 'Resolved', key: 'resolved_at' },
  ],
}

const reportDataMap = computed<Record<string, ReportRow[]>>(() => ({
  violation: props.policyCases,
  compliance: props.compliance,
  exception: props.exceptions,
  attestations: props.attestations,
  policies: props.policies,
  questionResults: props.questionResults,
  policyEvents: props.policyEvents,
  investigations: props.investigations,
}))

const availableDepartments = computed(() => {
  if (!filters.division)
    return props.departments

  return props.departments.filter(d => String(d.parent_id ?? '') === String(filters.division))
})

const availableSections = computed(() => {
  if (!filters.department)
    return props.sections

  return props.sections.filter(s => String(s.parent_id ?? '') === String(filters.department))
})

const selectedDepartmentNames = computed(() => {
  if (filters.department) {
    const selected = props.departments.find(d => String(d.id) === String(filters.department))
    return selected ? [selected.name] : []
  }

  if (filters.division) {
    return availableDepartments.value.map(d => d.name)
  }

  return [] as string[]
})

function normalizeDate(value: unknown): number | null {
  if (!value)
    return null

  const parsed = Date.parse(String(value))

  return Number.isNaN(parsed) ? null : parsed
}

function rowDateValue(row: ReportRow): number | null {
  const dateKeys = ['created_at', 'timestamp', 'attempted_at', 'deadline', 'acknowledged_at', 'start_date', 'end_date', 'created_date', 'review_date', 'approved_at', 'resolved_at']
  for (const key of dateKeys) {
    const parsed = normalizeDate(row[key])
    if (parsed !== null)
      return parsed
  }

  return null
}

function applyFilters(rows: ReportRow[]): ReportRow[] {
  let next = [...rows]

  if (selectedDepartmentNames.value.length > 0) {
    const allowed = new Set(selectedDepartmentNames.value)
    next = next.filter(r => {
      if (typeof r.department !== 'string')
        return true

      return allowed.has(r.department)
    })
  }

  if (filters.section) {
    const selectedSection = props.sections.find(s => String(s.id) === String(filters.section))
    if (selectedSection) {
      const parentDeptName = props.departments.find(d => String(d.id) === String(selectedSection.parent_id ?? ''))?.name
      next = next.filter(r => {
        const rowSection = r.section
        if (typeof rowSection === 'string')
          return rowSection === selectedSection.name

        if (typeof r.department === 'string' && parentDeptName)
          return r.department === parentDeptName

        return true
      })
    }
  }

  if (filters.status) {
    const wanted = filters.status.toLowerCase()
    next = next.filter((r) => {
      const candidates = [r.status, r.case_status, r.investigation_status]
      return candidates.some(v => String(v ?? '').toLowerCase() === wanted)
    })
  }

  const fromTs = normalizeDate(filters.dateFrom)
  const toTs = normalizeDate(filters.dateTo)

  if (fromTs !== null || toTs !== null) {
    next = next.filter((r) => {
      const dt = rowDateValue(r)
      if (dt === null)
        return true

      if (fromTs !== null && dt < fromTs)
        return false

      if (toTs !== null && dt > toTs)
        return false

      return true
    })
  }

  return next
}

function reportRows(reportKey: string): ReportRow[] {
  return applyFilters(reportDataMap.value[reportKey] ?? [])
}

const activeRows = computed(() => reportRows(activeTab.value))
const activeHeaders = computed(() => headersByReport[activeTab.value] ?? [])

function setToast(text: string, color = 'success') {
  toast.text = text
  toast.color = color
  toast.show = true
}

function switchToReport(reportKey: string) {
  activeTab.value = reportKey
}

function buildFilterQueryString() {
  const params = new URLSearchParams()
  if (filters.division)
    params.set('division', String(filters.division))
  if (filters.department)
    params.set('department', String(filters.department))
  if (filters.section)
    params.set('section', String(filters.section))
  if (filters.status)
    params.set('status', filters.status)
  if (filters.dateFrom)
    params.set('dateFrom', filters.dateFrom)
  if (filters.dateTo)
    params.set('dateTo', filters.dateTo)

  return params.toString()
}

async function generateReport(reportKey: string) {
  activeTab.value = reportKey
  const qs = buildFilterQueryString()
  const url = `/policy-reports/generate/${reportKey}${qs ? `?${qs}` : ''}`

  try {
    const res = await fetch(url, { headers: { Accept: 'application/json' } })
    if (!res.ok)
      throw new Error('Generate failed')

    const payload = await res.json()
    setToast(`Generated ${payload.count} rows for ${reportKey} report.`)
  }
  catch {
    setToast(`Could not generate ${reportKey} report.`, 'error')
  }
}

function exportReport(reportKey: string, format: 'excel' | 'pdf') {
  const qs = buildFilterQueryString()
  const backendFormat = format === 'excel' ? 'xlsx' : 'pdf'
  const url = `/policy-reports/export/${reportKey}/${backendFormat}${qs ? `?${qs}` : ''}`
  window.open(url, '_blank')
  setToast(`Started ${format.toUpperCase()} export for ${reportKey}.`)
}

function clearFilters() {
  filters.division = null
  filters.department = null
  filters.section = null
  filters.status = null
  filters.dateFrom = null
  filters.dateTo = null
}
</script>

<template>
  <VRow>
    <VCol cols="12">
      <VCard class="policy-ribbon reports-ribbon">
        <VCardText class="d-flex flex-wrap align-center justify-space-between gap-4 py-6">
          <div>
            <div class="text-overline text-white opacity-90">
              Policy Intelligence Workspace
            </div>
            <h4 class="text-h4 text-white mb-1">
              Policy Reports Engine
            </h4>
            <p class="mb-0 text-white opacity-90">
              Fixed-header data tables for violations, compliance, attestations, policy questions, events, cases, and investigations.
            </p>
          </div>
          <div class="d-flex gap-3 align-center flex-wrap">
            <VChip color="white" class="text-secondary" label>
              {{ activeRows.length }} Active Rows
            </VChip>
            <VBtn color="white" variant="outlined" prepend-icon="tabler-chart-bar" href="/policies/analytics">
              BI Analytics
            </VBtn>
            <VBtn
              color="white"
              variant="tonal"
              prepend-icon="tabler-calendar-clock"
              href="/policy-reports/scheduled"
            >
              Scheduled Reports
            </VBtn>
          </div>
        </VCardText>
      </VCard>
    </VCol>

    <VCol cols="12">
      <VCard title="Report Filters">
        <VCardText>
          <VRow>
            <VCol cols="12" sm="6" md="2">
              <VSelect
                v-model="filters.division"
                :items="divisions"
                item-title="name"
                item-value="id"
                label="Division"
                clearable
              />
            </VCol>
            <VCol cols="12" sm="6" md="2">
              <VSelect
                v-model="filters.department"
                :items="availableDepartments"
                item-title="name"
                item-value="id"
                label="Department"
                clearable
              />
            </VCol>
            <VCol cols="12" sm="6" md="2">
              <VSelect
                v-model="filters.section"
                :items="availableSections"
                item-title="name"
                item-value="id"
                label="Section"
                clearable
              />
            </VCol>
            <VCol cols="12" sm="6" md="2">
              <VSelect
                v-model="filters.status"
                :items="['pending', 'approved', 'rejected', 'resolved', 'failed', 'passed', 'acknowledged']"
                label="Status"
                clearable
              />
            </VCol>
            <VCol cols="12" sm="6" md="2">
              <VTextField
                v-model="filters.dateFrom"
                type="date"
                label="From"
              />
            </VCol>
            <VCol cols="12" sm="6" md="2">
              <VTextField
                v-model="filters.dateTo"
                type="date"
                label="To"
              />
            </VCol>
          </VRow>

          <div class="d-flex justify-end">
            <VBtn
              variant="tonal"
              color="secondary"
              prepend-icon="tabler-filter-off"
              @click="clearFilters"
            >
              Clear Filters
            </VBtn>
          </div>
        </VCardText>
      </VCard>
    </VCol>

    <VCol cols="12">
      <VCard title="Reports Engine">
        <VCardText>
          <VRow>
            <VCol
              v-for="report in reportCards"
              :key="report.key"
              cols="12"
              md="6"
            >
              <VCard
                variant="outlined"
                class="h-100"
              >
                <VCardText>
                  <div class="text-subtitle-1 font-weight-medium mb-2">
                    {{ report.title }}
                  </div>
                  <div class="text-body-2 mb-4">
                    {{ report.description }}
                  </div>
                  <div class="d-flex flex-wrap gap-2">
                    <VBtn
                      size="small"
                      color="primary"
                      prepend-icon="tabler-player-play"
                      @click="generateReport(report.key)"
                    >
                      Generate
                    </VBtn>
                    <VBtn
                      size="small"
                      variant="tonal"
                      prepend-icon="tabler-table"
                      @click="switchToReport(report.key)"
                    >
                      Open Table
                    </VBtn>
                    <VMenu>
                      <template #activator="{ props: menuProps }">
                        <VBtn
                          v-bind="menuProps"
                          size="small"
                          color="secondary"
                          variant="tonal"
                          prepend-icon="tabler-file-export"
                        >
                          Export
                        </VBtn>
                      </template>
                      <VList>
                        <VListItem
                          title="Export Excel (XLSX)"
                          @click="exportReport(report.key, 'excel')"
                        />
                        <VListItem
                          title="Export PDF"
                          @click="exportReport(report.key, 'pdf')"
                        />
                      </VList>
                    </VMenu>
                  </div>
                </VCardText>
              </VCard>
            </VCol>
          </VRow>
        </VCardText>
      </VCard>
    </VCol>

    <VCol cols="12">
      <VCard>
        <VTabs v-model="activeTab">
          <VTab value="violation">Violation Cases</VTab>
          <VTab value="compliance">Compliance</VTab>
          <VTab value="exception">Exceptions</VTab>
          <VTab value="attestations">Attestations</VTab>
          <VTab value="policies">Policies</VTab>
          <VTab value="questionResults">Question Results</VTab>
          <VTab value="policyEvents">Policy Events</VTab>
          <VTab value="investigations">Investigations</VTab>
        </VTabs>

        <VDivider />

        <VCardText class="d-flex gap-2">
          <VBtn
            color="primary"
            prepend-icon="tabler-player-play"
            @click="generateReport(activeTab)"
          >
            Generate
          </VBtn>
          <VBtn
            color="success"
            variant="tonal"
            prepend-icon="tabler-file-text"
            @click="exportReport(activeTab, 'excel')"
          >
            Export Excel
          </VBtn>
          <VBtn
            color="error"
            variant="tonal"
            prepend-icon="tabler-file-type-pdf"
            @click="exportReport(activeTab, 'pdf')"
          >
            Export PDF
          </VBtn>
        </VCardText>

        <VDataTable
          :headers="activeHeaders"
          :items="activeRows"
          :items-per-page="15"
          height="640"
          fixed-header
        >
          <template #item.status="{ item }">
            <VChip
              size="small"
              variant="tonal"
              :color="item.status === 'approved' || item.status === 'resolved' || item.status === 'acknowledged' || item.status === 'Passed' ? 'success' : item.status === 'rejected' || item.status === 'Failed' ? 'error' : 'warning'"
            >
              {{ item.status }}
            </VChip>
          </template>

          <template #item.case_status="{ item }">
            <VChip
              size="small"
              variant="tonal"
              :color="item.case_status === 'resolved' ? 'success' : 'warning'"
            >
              {{ item.case_status }}
            </VChip>
          </template>

          <template #item.investigation_status="{ item }">
            <VChip
              size="small"
              variant="tonal"
              :color="item.investigation_status === 'resolved' ? 'success' : item.investigation_status === 'Not started' ? 'default' : 'info'"
            >
              {{ item.investigation_status }}
            </VChip>
          </template>

          <template #item.severity="{ item }">
            <VChip
              size="small"
              variant="tonal"
              :color="item.severity === 'critical' ? 'error' : item.severity === 'high' ? 'warning' : 'info'"
            >
              {{ item.severity }}
            </VChip>
          </template>

          <template #item.overdue="{ item }">
            <VChip
              size="small"
              variant="tonal"
              :color="item.overdue ? 'error' : 'success'"
            >
              {{ item.overdue ? 'Yes' : 'No' }}
            </VChip>
          </template>

          <template #item.requires_attestation="{ item }">
            <VChip
              size="small"
              variant="tonal"
              :color="item.requires_attestation === 'Yes' ? 'primary' : 'default'"
            >
              {{ item.requires_attestation }}
            </VChip>
          </template>
        </VDataTable>
      </VCard>
    </VCol>

    <VSnackbar
      v-model="toast.show"
      :color="toast.color"
      timeout="2500"
      location="top right"
    >
      {{ toast.text }}
    </VSnackbar>
  </VRow>
</template>

<style scoped lang="scss">
.policy-ribbon {
  border: 0;
  overflow: hidden;
}

.reports-ribbon {
  background: linear-gradient(135deg, #005f56 0%, #0b8b78 100%);
}

:deep(.v-data-table-header__content) {
  font-weight: 700;
}
</style>
