<script setup lang="ts">
import { ref, computed } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  employees:         { id: number; name: string; email: string }[]
  categories:        { id: number; name: string; severity_level: string }[]
  openCases:         Record<string, any>[]
  caseResolution:    Record<string, any>[]
  hearings:          Record<string, any>[]
  investigations:    Record<string, any>[]
  warnings:          Record<string, any>[]
  suspensions:       Record<string, any>[]
  correctiveActions: Record<string, any>[]
  appeals:           Record<string, any>[]
  incidents:         Record<string, any>[]
  policyViolations:  Record<string, any>[]
  riskScores:        Record<string, any>[]
}>()

const activeReport = ref('open_cases')
const search       = ref('')
const filterEmployee  = ref<number|null>(null)
const filterCategory  = ref<string|null>(null)
const filterSeverity  = ref<string|null>(null)
const filterStatus    = ref<string|null>(null)

// ✅ reportCards en computed
const reportCards = computed(() => [
  { key: 'open_cases',         label: t('disciplinary_reports.cards.open_cases'),         icon: 'tabler-folder-open',      color: 'primary' },
  { key: 'case_resolution',    label: t('disciplinary_reports.cards.case_resolution'),    icon: 'tabler-circle-check',      color: 'success' },
  { key: 'hearings',           label: t('disciplinary_reports.cards.hearings'),           icon: 'tabler-gavel',                     color: 'deep-purple' },
  { key: 'investigations',     label: t('disciplinary_reports.cards.investigations'),     icon: 'tabler-search',                   color: 'warning' },
  { key: 'warnings',           label: t('disciplinary_reports.cards.warnings'),           icon: 'tabler-alert-triangle',             color: 'orange' },
  { key: 'suspensions',        label: t('disciplinary_reports.cards.suspensions'),        icon: 'tabler-user-off',       color: 'error' },
  { key: 'corrective_actions', label: t('disciplinary_reports.cards.corrective_actions'), icon: 'tabler-clipboard-check',   color: 'teal' },
  { key: 'appeals',            label: t('disciplinary_reports.cards.appeals'),            icon: 'tabler-scale',             color: 'indigo' },
  { key: 'incidents',          label: t('disciplinary_reports.cards.incidents'),          icon: 'tabler-alert-circle',      color: 'deep-orange' },
  { key: 'policy_violations',  label: t('disciplinary_reports.cards.policy_violations'),  icon: 'tabler-shield-exclamation',      color: 'red' },
  { key: 'risk_scores',        label: t('disciplinary_reports.cards.risk_scores'),        icon: 'tabler-user-exclamation',     color: 'blue-grey' },
])

// ✅ headersByReport en computed
const headersByReport = computed<Record<string, { title: string; key: string; sortable?: boolean }[]>>(() => ({
  open_cases: [
    { title: t('disciplinary_reports.headers.case_number'), key: 'case_number' },
    { title: t('disciplinary_reports.headers.employee'), key: 'employee' },
    { title: t('disciplinary_reports.headers.category'), key: 'category' },
    { title: t('disciplinary_reports.headers.severity'), key: 'severity' },
    { title: t('disciplinary_reports.headers.status'), key: 'status' },
    { title: t('disciplinary_reports.headers.incident_date'), key: 'incident_date' },
    { title: t('disciplinary_reports.headers.days_open'), key: 'days_open' },
    { title: t('disciplinary_reports.headers.hearing_date'), key: 'hearing_date' },
  ],
  case_resolution: [
    { title: t('disciplinary_reports.headers.case_number'), key: 'case_number' },
    { title: t('disciplinary_reports.headers.employee'), key: 'employee' },
    { title: t('disciplinary_reports.headers.category'), key: 'category' },
    { title: t('disciplinary_reports.headers.outcome'), key: 'outcome' },
    { title: t('disciplinary_reports.headers.sanction'), key: 'sanction' },
    { title: t('disciplinary_reports.headers.incident_date'), key: 'incident_date' },
    { title: t('disciplinary_reports.headers.closed_at'), key: 'closed_at' },
    { title: t('disciplinary_reports.headers.days_to_close'), key: 'days_to_close' },
  ],
  hearings: [
    { title: t('disciplinary_reports.headers.hearing_number'), key: 'hearing_number' },
    { title: t('disciplinary_reports.headers.case_number'), key: 'case_number' },
    { title: t('disciplinary_reports.headers.employee'), key: 'employee' },
    { title: t('disciplinary_reports.headers.hearing_type'), key: 'hearing_type' },
    { title: t('disciplinary_reports.headers.status'), key: 'status' },
    { title: t('disciplinary_reports.headers.scheduled_at'), key: 'scheduled_at' },
    { title: t('disciplinary_reports.headers.venue'), key: 'venue' },
    { title: t('disciplinary_reports.headers.notice_issued'), key: 'notice_issued' },
  ],
  investigations: [
    { title: t('disciplinary_reports.headers.investigation_number'), key: 'investigation_number' },
    { title: t('disciplinary_reports.headers.case_number'), key: 'case_number' },
    { title: t('disciplinary_reports.headers.employee'), key: 'employee' },
    { title: t('disciplinary_reports.headers.investigator'), key: 'investigator' },
    { title: t('disciplinary_reports.headers.status'), key: 'status' },
    { title: t('disciplinary_reports.headers.due_date'), key: 'due_date' },
    { title: t('disciplinary_reports.headers.days_open'), key: 'days_open' },
    { title: t('disciplinary_reports.headers.progress'), key: 'progress' },
  ],
  warnings: [
    { title: t('disciplinary_reports.headers.employee'), key: 'employee' },
    { title: t('disciplinary_reports.headers.warning_type'), key: 'warning_type' },
    { title: t('disciplinary_reports.headers.case_number'), key: 'case_number' },
    { title: t('disciplinary_reports.headers.issued_date'), key: 'issued_date' },
    { title: t('disciplinary_reports.headers.expiry_date'), key: 'expiry_date' },
    { title: t('disciplinary_reports.headers.issued_by'), key: 'issued_by' },
    { title: t('disciplinary_reports.headers.status'), key: 'status' },
  ],
  suspensions: [
    { title: t('disciplinary_reports.headers.employee'), key: 'employee' },
    { title: t('disciplinary_reports.headers.suspension_type'), key: 'suspension_type' },
    { title: t('disciplinary_reports.headers.case_number'), key: 'case_number' },
    { title: t('disciplinary_reports.headers.start_date'), key: 'start_date' },
    { title: t('disciplinary_reports.headers.end_date'), key: 'end_date' },
    { title: t('disciplinary_reports.headers.status'), key: 'status' },
    { title: t('disciplinary_reports.headers.days'), key: 'days' },
    { title: t('disciplinary_reports.headers.issued_by'), key: 'issued_by' },
  ],
  corrective_actions: [
    { title: t('disciplinary_reports.headers.action_number'), key: 'action_number' },
    { title: t('disciplinary_reports.headers.employee'), key: 'employee' },
    { title: t('disciplinary_reports.headers.action_type'), key: 'action_type' },
    { title: t('disciplinary_reports.headers.title'), key: 'title' },
    { title: t('disciplinary_reports.headers.status'), key: 'status' },
    { title: t('disciplinary_reports.headers.progress'), key: 'progress' },
    { title: t('disciplinary_reports.headers.due_date'), key: 'due_date' },
    { title: t('disciplinary_reports.headers.is_mandatory'), key: 'is_mandatory' },
  ],
  appeals: [
    { title: t('disciplinary_reports.headers.appeal_number'), key: 'appeal_number' },
    { title: t('disciplinary_reports.headers.employee'), key: 'employee' },
    { title: t('disciplinary_reports.headers.case_number'), key: 'case_number' },
    { title: t('disciplinary_reports.headers.grounds'), key: 'grounds' },
    { title: t('disciplinary_reports.headers.status'), key: 'status' },
    { title: t('disciplinary_reports.headers.submitted_at'), key: 'submitted_at' },
    { title: t('disciplinary_reports.headers.verdict'), key: 'verdict' },
    { title: t('disciplinary_reports.headers.decided_at'), key: 'decided_at' },
  ],
  incidents: [
    { title: t('disciplinary_reports.headers.incident_number'), key: 'incident_number' },
    { title: t('disciplinary_reports.headers.title'), key: 'title' },
    { title: t('disciplinary_reports.headers.category'), key: 'category' },
    { title: t('disciplinary_reports.headers.severity_level'), key: 'severity_level' },
    { title: t('disciplinary_reports.headers.status'), key: 'status' },
    { title: t('disciplinary_reports.headers.incident_date'), key: 'incident_date' },
    { title: t('disciplinary_reports.headers.reporter'), key: 'reporter' },
    { title: t('disciplinary_reports.headers.investigator'), key: 'investigator' },
  ],
  policy_violations: [
    { title: t('disciplinary_reports.headers.rule_name'), key: 'rule_name' },
    { title: t('disciplinary_reports.headers.violation_type'), key: 'violation_type' },
    { title: t('disciplinary_reports.headers.severity'), key: 'severity' },
    { title: t('disciplinary_reports.headers.employee'), key: 'employee' },
    { title: t('disciplinary_reports.headers.case_number'), key: 'case_number' },
    { title: t('disciplinary_reports.headers.action_taken'), key: 'action_taken' },
    { title: t('disciplinary_reports.headers.is_resolved'), key: 'is_resolved' },
    { title: t('disciplinary_reports.headers.created_at'), key: 'created_at' },
  ],
  risk_scores: [
    { title: t('disciplinary_reports.headers.employee'), key: 'employee' },
    { title: t('disciplinary_reports.headers.risk_score'), key: 'risk_score' },
    { title: t('disciplinary_reports.headers.risk_level'), key: 'risk_level' },
    { title: t('disciplinary_reports.headers.total_cases'), key: 'total_cases' },
    { title: t('disciplinary_reports.headers.is_repeat_offender'), key: 'is_repeat_offender' },
    { title: t('disciplinary_reports.headers.last_case_date'), key: 'last_case_date' },
    { title: t('disciplinary_reports.headers.updated_at'), key: 'updated_at' },
  ],
}))

const dataByReport = computed<Record<string, any[]>>(() => ({
  open_cases:         props.openCases,
  case_resolution:    props.caseResolution,
  hearings:           props.hearings,
  investigations:     props.investigations,
  warnings:           props.warnings,
  suspensions:        props.suspensions,
  corrective_actions: props.correctiveActions,
  appeals:            props.appeals,
  incidents:          props.incidents,
  policy_violations:  props.policyViolations,
  risk_scores:        props.riskScores,
}))

const activeData = computed(() => {
  const rows = dataByReport.value[activeReport.value] ?? []
  const s = search.value.toLowerCase()
  if (!s && !filterEmployee.value && !filterCategory.value && !filterSeverity.value && !filterStatus.value) return rows
  return rows.filter(r => {
    if (s && !Object.values(r).some(v => String(v).toLowerCase().includes(s))) return false
    if (filterEmployee.value) {
      const emp = props.employees.find(e => e.id === filterEmployee.value)
      if (emp && !String(r.employee ?? '').toLowerCase().includes(emp.name.toLowerCase())) return false
    }
    if (filterCategory.value && r.category !== filterCategory.value) return false
    if (filterSeverity.value && r.severity !== filterSeverity.value) return false
    if (filterStatus.value && r.status !== filterStatus.value) return false
    return true
  })
})

const activeCard = computed(() => reportCards.value.find(c => c.key === activeReport.value))

function exportCsv() {
  const rows  = activeData.value
  const heads = headersByReport[activeReport.value] ?? []
  if (!rows.length) return
  const csv = [
    heads.map(h => h.title).join(','),
    ...rows.map(r => heads.map(h => `"${String(r[h.key] ?? '').replace(/"/g, '""')}"`).join(',')),
  ].join('\n')
  const a   = document.createElement('a')
  a.href    = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }))
  a.download = `disciplinary-${activeReport.value}-${new Date().toISOString().slice(0, 10)}.csv`
  a.click()
}
</script>

<template>
  <div class="pa-4">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-5 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('disciplinary_reports.index.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('disciplinary_reports.index.subtitle') }}</p>
      </div>
      <div class="d-flex ga-2 flex-wrap">
        <VBtn variant="outlined" prepend-icon="tabler-calendar-clock" :href="route('disciplinary.reports.scheduled')">
          {{ t('disciplinary_reports.index.scheduled_reports') }}
        </VBtn>
        <VBtn variant="outlined" prepend-icon="tabler-file-spreadsheet" color="success"
          :href="route('disciplinary.reports.export', { key: activeReport, format: 'excel' })" target="_blank">
          {{ t('disciplinary_reports.index.excel') }}
        </VBtn>
        <VBtn variant="outlined" prepend-icon="tabler-file-type-pdf" color="error"
          :href="route('disciplinary.reports.export', { key: activeReport, format: 'pdf' })" target="_blank">
          {{ t('disciplinary_reports.index.pdf') }}
        </VBtn>
        <VBtn variant="outlined" prepend-icon="tabler-download" color="grey" @click="exportCsv">
          {{ t('disciplinary_reports.index.csv') }}
        </VBtn>
      </div>
    </div>

    <!-- Report selector cards -->
    <VRow dense class="mb-4">
      <VCol v-for="card in reportCards" :key="card.key" cols="6" sm="4" md="3" lg="2">
        <VCard variant="tonal" :color="activeReport === card.key ? card.color : 'grey'"
          class="pa-3 text-center cursor-pointer"
          :class="activeReport === card.key ? 'elevation-4' : 'elevation-0'"
          @click="activeReport = card.key; search = ''"
        >
          <VIcon :color="activeReport === card.key ? card.color : 'grey'" size="26" class="mb-1">{{ card.icon }}</VIcon>
          <div class="text-caption font-weight-medium">{{ card.label }}</div>
          <div class="text-h6 font-weight-bold">{{ (dataByReport[card.key] ?? []).length }}</div>
        </VCard>
      </VCol>
    </VRow>

    <!-- Filters -->
    <VCard class="mb-4 pa-3" variant="outlined">
      <VRow dense align="center">
        <VCol cols="12" sm="4" md="3">
          <VTextField v-model="search" :label="t('disciplinary_reports.index.search')" prepend-inner-icon="tabler-search"
            density="compact" variant="outlined" clearable hide-details />
        </VCol>
        <VCol cols="12" sm="4" md="2">
          <VSelect v-model="filterSeverity" :label="t('disciplinary_reports.index.severity')"
            :items="['minor','serious','gross']" density="compact" variant="outlined" clearable hide-details />
        </VCol>
        <VCol cols="12" sm="4" md="2">
          <VSelect v-model="filterStatus" :label="t('disciplinary_reports.index.status')"
            :items="['active','pending','completed','cancelled','closed','resolved']"
            density="compact" variant="outlined" clearable hide-details />
        </VCol>
        <VCol cols="12" md="2" class="text-right">
          <VChip color="primary" variant="tonal" size="small">
            {{ activeData.length }} {{ activeData.length === 1 ? t('disciplinary_reports.index.records') : t('disciplinary_reports.index.records_plural') }}
          </VChip>
        </VCol>
      </VRow>
    </VCard>

    <!-- Data table -->
    <VCard>
      <VCardTitle class="pa-4 d-flex align-center ga-2">
        <VIcon :color="activeCard?.color">{{ activeCard?.icon }}</VIcon>
        <span class="text-body-1 font-weight-bold">{{ activeCard?.label }}</span>
      </VCardTitle>
      <VDivider />
      <VDataTable
        :items="activeData"
        :headers="headersByReport[activeReport] ?? []"
        :search="search"
        :items-per-page="25"
        density="compact"
        hover
      >
        <!-- Dynamic severity chips -->
        <template #item.severity="{ item }">
          <VChip v-if="item.severity && item.severity !== '—'"
            :color="item.severity === 'gross' ? 'error' : item.severity === 'serious' ? 'warning' : 'success'"
            size="x-small" variant="tonal">{{ item.severity }}</VChip>
          <span v-else class="text-medium-emphasis">—</span>
        </template>
        <template #item.severity_level="{ item }">
          <VChip v-if="item.severity_level && item.severity_level !== '—'"
            :color="item.severity_level === 'gross' ? 'error' : item.severity_level === 'serious' ? 'warning' : 'success'"
            size="x-small" variant="tonal">{{ item.severity_level }}</VChip>
          <span v-else class="text-medium-emphasis">—</span>
        </template>
        <!-- Risk level chip -->
        <template #item.risk_level="{ item }">
          <VChip v-if="item.risk_level"
            :color="item.risk_level === 'critical' ? 'error' : item.risk_level === 'high' ? 'deep-orange' : item.risk_level === 'medium' ? 'warning' : 'success'"
            size="x-small" variant="tonal">{{ item.risk_level }}</VChip>
        </template>
        <!-- Days open — color coded -->
        <template #item.days_open="{ item }">
          <VChip :color="item.days_open > 30 ? 'error' : item.days_open > 14 ? 'warning' : 'grey'"
            size="x-small" variant="tonal">{{ item.days_open }}d</VChip>
        </template>
        <template #item.days_to_close="{ item }">
          <VChip :color="item.days_to_close > 60 ? 'error' : item.days_to_close > 30 ? 'warning' : 'success'"
            size="x-small" variant="tonal">{{ item.days_to_close }}d</VChip>
        </template>
        <!-- Progress bar -->
        <template #item.progress="{ item }">
          <div class="d-flex align-center ga-1" style="min-width:80px">
            <VProgressLinear :model-value="parseInt(item.progress)" height="6" rounded color="primary" />
            <span class="text-caption">{{ item.progress }}</span>
          </div>
        </template>
        <!-- Empty state -->
        <template #no-data>
          <div class="text-center py-10 text-medium-emphasis">
            <VIcon size="48" class="mb-2 opacity-40">tabler-table-off</VIcon>
            <div>{{ t('disciplinary_reports.index.no_records') }}</div>
          </div>
        </template>
      </VDataTable>
    </VCard>
  </div>
</template>
