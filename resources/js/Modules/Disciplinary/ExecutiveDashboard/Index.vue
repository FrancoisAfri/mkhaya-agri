<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Employee { employee_id: number; employee_name: string; risk_score: number; risk_level: string; risk_color: string; total_cases: number; last_case_date: string|null; is_repeat?: boolean }
interface Suspension { id: number; employee: string; suspension_type: string; start_date: string; end_date: string|null; days_remaining: number|null; case_number: string|null }
interface InvestigationDelay { id: number; case_number: string|null; employee: string|null; investigator: string; days_open: number; status: string }
interface HearingItem { hearing_number: string; case_number: string|null; employee: string|null; scheduled_at: string }
interface AppealMonth { month: string; total: number }

const props = defineProps<{
  openCasesCount: number
  openCasesTrend: { month: string; total: number }[]
  casesByCategory: { category: string; severity_level: string|null; total: number }[]
  repeatOffenders: Employee[]
  policyViolations: { total: number; unresolved: number; critical: number; by_type: { violation_type: string; total: number }[] }
  suspendedEmployees: Suspension[]
  dismissalRisk: Employee[]
  departmentRisk: { department: string; total_cases: number; affected_employees: number }[]
  hearingBacklog: { scheduled: number; postponed: number; overdue: number; this_week: number; upcoming_list: HearingItem[] }
  investigationDelays: InvestigationDelay[]
  appealTrends: { total: number; pending: number; decided: number; by_month: AppealMonth[] }
  behavioralHeatmap: { employee_id: number; employee_name: string; risk_score: number; risk_level: string; month: string }[]
}>()

const riskColor = (level: string) =>
  level === 'critical' ? 'error' : level === 'high' ? 'deep-orange' : level === 'medium' ? 'warning' : 'success'

const severityColor = (s: string|null) =>
  s === 'gross' ? 'error' : s === 'serious' ? 'warning' : 'info'

const legendItems = [
  [t('disciplinary_executive-dashboard.legend.critical'), '#D32F2F'],
  [t('disciplinary_executive-dashboard.legend.high'), '#F57C00'],
  [t('disciplinary_executive-dashboard.legend.medium'), '#FBC02D'],
  [t('disciplinary_executive-dashboard.legend.low'), '#4CAF50'],
]
</script>

<template>
  <div class="pa-6">
    <div class="mb-6">
      <h1 class="text-h5 font-weight-bold">{{ t('disciplinary_executive-dashboard.title') }}</h1>
      <p class="text-body-2 text-medium-emphasis">{{ t('disciplinary_executive-dashboard.subtitle') }}</p>
    </div>

    <VRow dense>
      <!-- Open Cases -->
      <VCol cols="12" sm="6" md="3">
        <VCard variant="tonal" color="primary" class="text-center pa-4 h-100">
          <VIcon size="32" class="mb-2">tabler-folder-open</VIcon>
          <div class="text-h3 font-weight-bold">{{ openCasesCount }}</div>
          <div class="text-body-2">{{ t('disciplinary_executive-dashboard.widgets.open_cases') }}</div>
          <div class="text-caption text-medium-emphasis mt-1">{{ t('disciplinary_executive-dashboard.widgets.open_cases_sub') }}</div>
        </VCard>
      </VCol>

      <!-- Unresolved Violations -->
      <VCol cols="12" sm="6" md="3">
        <VCard variant="tonal" color="warning" class="text-center pa-4 h-100">
          <VIcon size="32" class="mb-2">tabler-shield-exclamation</VIcon>
          <div class="text-h3 font-weight-bold">{{ policyViolations.unresolved }}</div>
          <div class="text-body-2">{{ t('disciplinary_executive-dashboard.widgets.unresolved_violations') }}</div>
          <div class="text-caption text-medium-emphasis mt-1">{{ t('disciplinary_executive-dashboard.widgets.unresolved_violations_sub', { critical: policyViolations.critical }) }}</div>
        </VCard>
      </VCol>

      <!-- Active Suspensions -->
      <VCol cols="12" sm="6" md="3">
        <VCard variant="tonal" color="error" class="text-center pa-4 h-100">
          <VIcon size="32" class="mb-2">tabler-user-off</VIcon>
          <div class="text-h3 font-weight-bold">{{ suspendedEmployees.length }}</div>
          <div class="text-body-2">{{ t('disciplinary_executive-dashboard.widgets.active_suspensions') }}</div>
          <div class="text-caption text-medium-emphasis mt-1">{{ t('disciplinary_executive-dashboard.widgets.active_suspensions_sub') }}</div>
        </VCard>
      </VCol>

      <!-- Overdue Hearings -->
      <VCol cols="12" sm="6" md="3">
        <VCard variant="tonal" color="deep-orange" class="text-center pa-4 h-100">
          <VIcon size="32" class="mb-2">tabler-gavel</VIcon>
          <div class="text-h3 font-weight-bold">{{ hearingBacklog.overdue }}</div>
          <div class="text-body-2">{{ t('disciplinary_executive-dashboard.widgets.overdue_hearings') }}</div>
          <div class="text-caption text-medium-emphasis mt-1">{{ t('disciplinary_executive-dashboard.widgets.overdue_hearings_sub', { scheduled: hearingBacklog.scheduled, this_week: hearingBacklog.this_week }) }}</div>
        </VCard>
      </VCol>

      <!-- Cases by Category -->
      <VCol cols="12" md="6">
        <VCard class="h-100">
          <VCardTitle class="pa-4 text-body-1 font-weight-bold">
            <VIcon start>tabler-tags</VIcon> {{ t('disciplinary_executive-dashboard.widgets.cases_by_category') }}
          </VCardTitle>
          <VDivider />
          <VCardText class="pa-0">
            <VList density="compact">
              <VListItem v-for="cat in casesByCategory.slice(0, 8)" :key="cat.category">
                <template #prepend>
                  <VChip :color="severityColor(cat.severity_level)" size="x-small" variant="tonal" class="mr-2">{{ cat.severity_level ?? '—' }}</VChip>
                </template>
                <VListItemTitle class="text-body-2">{{ cat.category }}</VListItemTitle>
                <template #append><span class="text-body-2 font-weight-bold">{{ cat.total }}</span></template>
              </VListItem>
              <VListItem v-if="casesByCategory.length === 0" class="text-medium-emphasis">{{ t('disciplinary_executive-dashboard.empty.no_data') }}</VListItem>
            </VList>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Repeat Offenders -->
      <VCol cols="12" md="6">
        <VCard class="h-100">
          <VCardTitle class="pa-4 text-body-1 font-weight-bold">
            <VIcon start color="error">tabler-user-exclamation</VIcon> {{ t('disciplinary_executive-dashboard.widgets.repeat_offenders') }}
          </VCardTitle>
          <VDivider />
          <VCardText class="pa-0">
            <VList density="compact">
              <VListItem v-for="r in repeatOffenders" :key="r.employee_id">
                <VListItemTitle class="text-body-2">{{ r.employee_name }}</VListItemTitle>
                <VListItemSubtitle class="text-caption">{{ t('disciplinary_executive-dashboard.cases_label', { count: r.total_cases }) }} · {{ t('disciplinary_executive-dashboard.cases_last', { date: r.last_case_date ?? '—' }) }}</VListItemSubtitle>
                <template #append>
                  <VChip :color="riskColor(r.risk_level)" size="x-small" variant="tonal">{{ r.risk_score }}</VChip>
                </template>
              </VListItem>
              <VListItem v-if="repeatOffenders.length === 0" class="text-medium-emphasis">{{ t('disciplinary_executive-dashboard.empty.no_repeat') }}</VListItem>
            </VList>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Active Suspensions List -->
      <VCol cols="12" md="8">
        <VCard>
          <VCardTitle class="pa-4 text-body-1 font-weight-bold">
            <VIcon start color="error">tabler-user-off</VIcon> {{ t('disciplinary_executive-dashboard.widgets.suspensions_list') }}
          </VCardTitle>
          <VDivider />
          <VDataTable
            :items="suspendedEmployees"
            :headers="[
              { title: t('disciplinary_executive-dashboard.table.employee'), key: 'employee' },
              { title: t('disciplinary_executive-dashboard.table.type'), key: 'suspension_type' },
              { title: t('disciplinary_executive-dashboard.table.start'), key: 'start_date' },
              { title: t('disciplinary_executive-dashboard.table.end'), key: 'end_date' },
              { title: t('disciplinary_executive-dashboard.table.days_left'), key: 'days_remaining' },
              { title: t('disciplinary_executive-dashboard.table.case'), key: 'case_number' },
            ]"
            density="compact"
            :items-per-page="5"
          >
            <template #item.suspension_type="{ item }">
              <VChip size="x-small" color="blue-grey" variant="tonal">{{ item.suspension_type }}</VChip>
            </template>
            <template #item.end_date="{ item }">{{ item.end_date ?? t('disciplinary_executive-dashboard.indefinite') }}</template>
            <template #item.days_remaining="{ item }">
              <VChip v-if="item.days_remaining !== null" size="x-small"
                :color="(item.days_remaining ?? 0) <= 2 ? 'error' : (item.days_remaining ?? 0) <= 5 ? 'warning' : 'success'"
                variant="tonal">{{ item.days_remaining }}d</VChip>
              <span v-else>—</span>
            </template>
            <template #item.case_number="{ item }">{{ item.case_number ?? '—' }}</template>
          </VDataTable>
        </VCard>
      </VCol>

      <!-- Dismissal Risk -->
      <VCol cols="12" md="4">
        <VCard class="h-100">
          <VCardTitle class="pa-4 text-body-1 font-weight-bold">
            <VIcon start color="error">tabler-user-minus</VIcon> {{ t('disciplinary_executive-dashboard.widgets.dismissal_risk') }}
          </VCardTitle>
          <VDivider />
          <VCardText class="pa-0">
            <VList density="compact">
              <VListItem v-for="r in dismissalRisk" :key="r.employee_id">
                <VListItemTitle class="text-body-2">{{ r.employee_name }}</VListItemTitle>
                <VListItemSubtitle class="text-caption">{{ t('disciplinary_executive-dashboard.cases_label', { count: r.total_cases }) }}</VListItemSubtitle>
                <template #append>
                  <VChip :color="riskColor(r.risk_level)" size="x-small" variant="text">{{ r.risk_level }}</VChip>
                </template>
              </VListItem>
              <VListItem v-if="dismissalRisk.length === 0" class="text-medium-emphasis">{{ t('disciplinary_executive-dashboard.empty.no_dismissal') }}</VListItem>
            </VList>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Department Risk -->
      <VCol cols="12" md="6">
        <VCard>
          <VCardTitle class="pa-4 text-body-1 font-weight-bold">
            <VIcon start color="warning">tabler-building</VIcon> {{ t('disciplinary_executive-dashboard.widgets.department_risk') }}
          </VCardTitle>
          <VDivider />
          <VCardText class="pa-0">
            <VList density="compact">
              <VListItem v-for="dept in departmentRisk" :key="dept.department">
                <VListItemTitle class="text-body-2">{{ dept.department }}</VListItemTitle>
                <VListItemSubtitle class="text-caption">{{ t('disciplinary_executive-dashboard.employees_affected', { count: dept.affected_employees }) }}</VListItemSubtitle>
                <template #append>
                  <VChip size="x-small" color="warning" variant="tonal">{{ t('disciplinary_executive-dashboard.cases_count', { count: dept.total_cases }) }}</VChip>
                </template>
              </VListItem>
            </VList>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Investigation Delays -->
      <VCol cols="12" md="6">
        <VCard>
          <VCardTitle class="pa-4 text-body-1 font-weight-bold">
            <VIcon start color="deep-orange">tabler-search</VIcon> {{ t('disciplinary_executive-dashboard.widgets.investigation_delays') }}
          </VCardTitle>
          <VDivider />
          <VDataTable
            :items="investigationDelays"
            :headers="[
              { title: t('disciplinary_executive-dashboard.table.case'), key: 'case_number' },
              { title: t('disciplinary_executive-dashboard.table.employee'), key: 'employee' },
              { title: t('disciplinary_executive-dashboard.table.days_open'), key: 'days_open' },
              { title: t('disciplinary_executive-dashboard.table.investigator'), key: 'investigator' },
            ]"
            density="compact"
            :items-per-page="5"
          >
            <template #item.days_open="{ item }">
              <VChip size="x-small" :color="item.days_open > 30 ? 'error' : item.days_open > 14 ? 'warning' : 'grey'" variant="tonal">{{ item.days_open }}d</VChip>
            </template>
            <template #item.case_number="{ item }">{{ item.case_number ?? '—' }}</template>
          </VDataTable>
        </VCard>
      </VCol>

      <!-- Appeal Trends -->
      <VCol cols="12" md="6">
        <VCard>
          <VCardTitle class="pa-4 text-body-1 font-weight-bold">
            <VIcon start color="deep-purple">tabler-scale</VIcon> {{ t('disciplinary_executive-dashboard.widgets.appeal_trends') }}
          </VCardTitle>
          <VDivider />
          <VCardText>
            <div class="d-flex ga-4 mb-4">
              <VChip color="primary" variant="tonal">{{ t('disciplinary_executive-dashboard.total_label', { count: appealTrends.total }) }}</VChip>
              <VChip color="warning" variant="tonal">{{ t('disciplinary_executive-dashboard.pending_label', { count: appealTrends.pending }) }}</VChip>
              <VChip color="success" variant="tonal">{{ t('disciplinary_executive-dashboard.decided_label', { count: appealTrends.decided }) }}</VChip>
            </div>
            <div class="d-flex align-end ga-2" style="height:80px">
              <div v-for="m in appealTrends.by_month" :key="m.month ?? m.month" class="d-flex flex-column align-center" style="flex:1; min-width:0">
                <div class="bg-deep-purple-lighten-3 rounded-t w-100" :style="{ height: Math.max(4, (m.total / Math.max(...appealTrends.by_month.map(x => x.total), 1)) * 60) + 'px' }" />
                <div class="text-caption" style="font-size:9px; white-space:nowrap; overflow:hidden">{{ m.month }}</div>
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Behavioral Risk Heatmap -->
      <VCol cols="12" md="6">
        <VCard>
          <VCardTitle class="pa-4 text-body-1 font-weight-bold">
            <VIcon start color="red">tabler-temperature</VIcon> {{ t('disciplinary_executive-dashboard.widgets.behavioral_heatmap') }}
          </VCardTitle>
          <VDivider />
          <VCardText class="pa-2">
            <div class="d-flex flex-wrap ga-1">
              <VTooltip v-for="h in behavioralHeatmap" :key="h.employee_id" :text="`${h.employee_name} — Score: ${h.risk_score}`">
                <template #activator="{ props: tp }">
                  <div v-bind="tp" class="rounded" style="width:28px; height:28px; cursor:pointer"
                    :style="{ background: h.risk_level === 'critical' ? '#D32F2F' : h.risk_level === 'high' ? '#F57C00' : h.risk_level === 'medium' ? '#FBC02D' : '#4CAF50' }" />
                </template>
              </VTooltip>
              <div v-if="behavioralHeatmap.length === 0" class="text-medium-emphasis text-body-2 pa-2">{{ t('disciplinary_executive-dashboard.empty.no_risk') }}</div>
            </div>
            <div class="d-flex ga-3 mt-3 flex-wrap">
              <div v-for="l in legendItems" :key="l[0]" class="d-flex align-center ga-1">
                <div class="rounded" style="width:12px;height:12px" :style="{background: l[1]}" />
                <span class="text-caption">{{ l[0] }}</span>
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
