<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Division = { id: number; name: string; headcount: number }
type DayCoverage = { coverage_pct: number; on_leave: number; total: number; risk: string }
type DivisionCoverage = Division & { by_date: Record<string, DayCoverage> }
type Heatmap = { dates: string[]; divisions: DivisionCoverage[]; overall_by_date: Record<string, DayCoverage> }
type ClashEmployee = { name: string; leave_type: string; start_date: string; end_date: string; status: string }
type Clash = { division_id: number; division_name: string; concurrent: number; headcount: number; coverage_pct: number; risk: string; employees: ClashEmployee[]; period: { start: string; end: string } }
type CriticalWarning = { id: number; employee_name: string; position: string; division_name: string; leave_type: string; start_date: string; end_date: string; working_days: number; status: string; severity: string }
type Stats = { headcount: number; on_leave: number; pending: number; coverage_pct: number }

const props = defineProps<{
  heatmap: Heatmap; clashes: Clash[]; criticalWarnings: CriticalWarning[]
  divisions: Division[]; pressure: { ai_insight: string } | null
  stats: Stats; dateRange: { start: string; end: string }
}>()

const coverageGradient = (pct: number): string => {
  if (pct >= 80) return '#c8e6c9'
  if (pct >= 65) return '#fff9c4'
  if (pct >= 50) return '#ffcdd2'
  return '#e1bee7'
}

const riskChipColor = (risk: string) => ({ low: 'success', medium: 'warning', high: 'error', critical: 'purple' }[risk] ?? 'default')
const severityColor = (s: string) => ({ critical: 'error', high: 'warning', medium: 'info' }[s] ?? 'default')
const shortDate = (iso: string) => iso.slice(5)

const coveragePctColor = computed(() => {
  const pct = props.stats.coverage_pct
  if (pct >= 80) return 'success'
  if (pct >= 65) return 'warning'
  return 'error'
})

const heatmapDates  = computed(() => props.heatmap.dates ?? [])
const heatmapDivs   = computed(() => props.heatmap.divisions ?? [])
const overallByDate = computed(() => props.heatmap.overall_by_date ?? {})

const aiLoading  = ref(false)
const aiInsight  = ref<string | null>(props.pressure?.ai_insight ?? null)
const aiError    = ref(false)

async function generateAiInsight() {
  aiLoading.value = true
  aiError.value   = false
  try {
    const res = await fetch('/leave-management/ai/workforce', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
      },
      body: JSON.stringify({
        type: 'department_pressure',
        data: { stats: props.stats, clashes: props.clashes.length, critical: props.criticalWarnings.length, dateRange: props.dateRange },
      }),
    })
    const data = await res.json()
    aiInsight.value = data.result?.insight ?? data.result ?? 'No insight generated.'
  } catch {
    aiError.value   = true
    aiInsight.value = null
  } finally {
    aiLoading.value = false
  }
}
</script>

<template>
  <div>
    <!-- Header -->
    <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-4 px-1">
      <div>
        <h3 class="text-h5 font-weight-semibold mb-1">{{ t('leave.workforce_dashboard') }}</h3>
        <p class="text-medium-emphasis mb-0 text-body-2">
          {{ t('leave_workforce_dashboard.subtitle') }}
          <span class="ms-2 text-caption">({{ dateRange.start }} → {{ dateRange.end }})</span>
        </p>
      </div>
      <VBreadcrumbs
        :items="[
          { title: t('leave_workforce_dashboard.home_breadcrumb'), href: '/', disabled: false },
          { title: t('leave_workforce_dashboard.leave_management_breadcrumb'), href: '/leave-management', disabled: false },
          { title: t('leave_workforce_dashboard.workforce_dashboard_breadcrumb'), disabled: true },
        ]"
        density="compact" class="pa-0"
      >
        <template #divider><i class="ri-arrow-right-s-line" style="font-size:11px" /></template>
      </VBreadcrumbs>
    </div>

    <!-- Stat Cards -->
    <VRow class="mb-4">
      <VCol cols="12" sm="6" md="3">
        <VCard>
          <VCardText class="pa-4">
            <div class="d-flex align-center justify-space-between mb-2">
              <span class="text-caption text-medium-emphasis text-uppercase">{{ t('leave.headcount') }}</span>
              <i class="ri-team-line text-h5 text-medium-emphasis" />
            </div>
            <p class="text-h4 font-weight-bold mb-0">{{ stats.headcount.toLocaleString() }}</p>
            <p class="text-caption text-medium-emphasis mb-0">{{ t('leave_workforce_dashboard.total_active_employees') }}</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard>
          <VCardText class="pa-4">
            <div class="d-flex align-center justify-space-between mb-2">
              <span class="text-caption text-medium-emphasis text-uppercase">{{ t('leave.on_leave_today') }}</span>
              <i class="ri-user-unfollow-line text-h5 text-warning" />
            </div>
            <p class="text-h4 font-weight-bold mb-0">{{ stats.on_leave }}</p>
            <p class="text-caption text-medium-emphasis mb-0">{{ t('leave_workforce_dashboard.approved_absences_today') }}</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard>
          <VCardText class="pa-4">
            <div class="d-flex align-center justify-space-between mb-2">
              <span class="text-caption text-medium-emphasis text-uppercase">{{ t('leave.pending_requests') }}</span>
              <i class="ri-time-line text-h5 text-info" />
            </div>
            <p class="text-h4 font-weight-bold mb-0">{{ stats.pending }}</p>
            <p class="text-caption text-medium-emphasis mb-0">{{ t('leave_workforce_dashboard.awaiting_approval') }}</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard>
          <VCardText class="pa-4">
            <div class="d-flex align-center justify-space-between mb-2">
              <span class="text-caption text-medium-emphasis text-uppercase">{{ t('leave.coverage_today') }}</span>
              <i class="ri-shield-check-line text-h5" :class="`text-${coveragePctColor}`" />
            </div>
            <p class="text-h4 font-weight-bold mb-0"><span :class="`text-${coveragePctColor}`">{{ stats.coverage_pct }}%</span></p>
            <VProgressLinear :model-value="stats.coverage_pct" :color="coveragePctColor" height="4" class="mt-1 mb-1 rounded" />
            <p class="text-caption text-medium-emphasis mb-0">{{ t('leave_workforce_dashboard.present_count', { present: stats.headcount - stats.on_leave, total: stats.headcount }) }}</p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- AI Insight + Clash Summary -->
    <VRow class="mb-4">
      <VCol cols="12" md="7">
        <VCard height="100%">
          <VCardTitle class="pa-4 d-flex align-center gap-2">
            <i class="ri-robot-line text-primary" />{{ t('leave.ai_workforce_insight') }}
          </VCardTitle>
          <VDivider />
          <VCardText class="pa-4">
            <div v-if="aiInsight" class="text-body-2" style="white-space: pre-wrap">{{ aiInsight }}</div>
            <div v-else-if="!aiLoading" class="text-center pa-4">
              <p class="text-medium-emphasis text-body-2 mb-3">{{ t('leave_workforce_dashboard.generate_ai_prompt') }}</p>
              <VBtn color="primary" :loading="aiLoading" @click="generateAiInsight">
                <i class="ri-sparkling-line me-2" />{{ t('leave.generate_insight') }}
              </VBtn>
              <p v-if="aiError" class="text-error text-caption mt-2 mb-0">{{ t('leave_workforce_dashboard.ai_unavailable') }}</p>
            </div>
            <div v-else class="d-flex align-center gap-2 pa-4">
              <VProgressCircular indeterminate size="20" width="2" color="primary" />
              <span class="text-body-2 text-medium-emphasis">{{ t('leave.analysing_data') }}</span>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="5">
        <VCard height="100%">
          <VCardTitle class="pa-4">{{ t('leave.risk_summary') }}</VCardTitle>
          <VDivider />
          <VCardText class="pa-4">
            <div class="d-flex align-center justify-space-between mb-3">
              <span class="text-body-2">{{ t('leave.clash_alerts') }}</span>
              <VChip :color="clashes.length ? 'error' : 'success'" size="small" label>
                {{ clashes.length }} {{ clashes.length === 1 ? t('leave_workforce_dashboard.divisions_label').split(' | ')[0] : t('leave_workforce_dashboard.divisions_label').split(' | ')[1] }}
              </VChip>
            </div>
            <div class="d-flex align-center justify-space-between mb-3">
              <span class="text-body-2">{{ t('leave.critical_roles_on_leave') }}</span>
              <VChip :color="criticalWarnings.length ? 'warning' : 'success'" size="small" label>
                {{ criticalWarnings.length }} {{ criticalWarnings.length === 1 ? t('leave_workforce_dashboard.people_label').split(' | ')[0] : t('leave_workforce_dashboard.people_label').split(' | ')[1] }}
              </VChip>
            </div>
            <VDivider class="mb-3" />
            <p class="text-overline text-medium-emphasis mb-2">{{ t('leave.highest_risk_clashes') }}</p>
            <div v-if="!clashes.length" class="text-caption text-medium-emphasis">{{ t('leave.none_detected') }}</div>
            <div v-for="clash in clashes.slice(0, 3)" :key="clash.division_id" class="mb-2">
              <div class="d-flex align-center gap-2">
                <VChip :color="riskChipColor(clash.risk)" size="x-small" label>{{ clash.risk }}</VChip>
                <span class="text-body-2">{{ clash.division_name }}</span>
                <VSpacer />
                <span class="text-caption text-medium-emphasis">{{ clash.coverage_pct }}% {{ t('leave_workforce_dashboard.coverage_label') }}</span>
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Coverage Heatmap -->
    <VCard class="mb-4">
      <VCardTitle class="pa-4">{{ t('leave.coverage_heatmap_30d') }}</VCardTitle>
      <VDivider />
      <VCardText class="pa-3">
        <div class="d-flex align-center gap-4 mb-4 flex-wrap">
          <span class="text-caption text-medium-emphasis">{{ t('leave_workforce_dashboard.coverage_legend_label') }}</span>
          <div v-for="(label, bg) in {
            '#c8e6c9': t('leave_workforce_dashboard.coverage_legend_low'),
            '#fff9c4': t('leave_workforce_dashboard.coverage_legend_medium'),
            '#ffcdd2': t('leave_workforce_dashboard.coverage_legend_high'),
            '#e1bee7': t('leave_workforce_dashboard.coverage_legend_critical')
          }" :key="bg" class="d-flex align-center gap-1">
            <span class="rounded" :style="{ width: '14px', height: '14px', backgroundColor: bg, display: 'inline-block', border: '1px solid #ccc' }" />
            <span class="text-caption">{{ label }}</span>
          </div>
        </div>

        <div style="overflow-x: auto">
          <table class="heatmap-table" style="border-collapse: collapse; min-width: 100%">
            <thead>
              <tr>
                <th class="text-left pa-2 text-caption text-medium-emphasis" style="min-width:140px">{{ t('leave_workforce_dashboard.department_col') }}</th>
                <th v-for="date in heatmapDates" :key="date" class="pa-1 text-center text-caption" style="min-width: 44px; writing-mode: vertical-rl; transform: rotate(180deg)">{{ shortDate(date) }}</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td class="pa-2 text-caption font-weight-bold">{{ t('leave.overall') }}</td>
                <td v-for="date in heatmapDates" :key="date" class="pa-1 text-center text-caption"
                  :style="{ backgroundColor: coverageGradient(overallByDate[date]?.coverage_pct ?? 100), border: '1px solid #e0e0e0' }"
                  :title="`${date}: ${overallByDate[date]?.coverage_pct ?? 100}% coverage`">
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
                <td :colspan="heatmapDates.length + 1" class="pa-6 text-center text-medium-emphasis text-caption">{{ t('leave_workforce_dashboard.no_department_data') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </VCardText>
    </VCard>

    <!-- Critical Role Warnings -->
    <VCard v-if="criticalWarnings.length" class="mb-4">
      <VCardTitle class="pa-4">{{ t('leave.critical_role_warnings') }}</VCardTitle>
      <VDivider />
      <VCardText class="pa-0">
        <VTable density="comfortable" hover>
          <thead>
            <tr>
              <th>{{ t('leave_workforce_dashboard.severity_col') }}</th>
              <th>{{ t('leave_workforce_dashboard.employee_col') }}</th>
              <th>{{ t('leave_workforce_dashboard.position_col') }}</th>
              <th>{{ t('leave_workforce_dashboard.department_col_short') }}</th>
              <th>{{ t('leave_workforce_dashboard.leave_type_col') }}</th>
              <th>{{ t('leave_workforce_dashboard.from_col') }}</th>
              <th>{{ t('leave_workforce_dashboard.to_col') }}</th>
              <th>{{ t('leave_workforce_dashboard.days_col') }}</th>
              <th>{{ t('leave_workforce_dashboard.status_col') }}</th>
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

    <!-- Clash Detail -->
    <VCard v-if="clashes.length">
      <VCardTitle class="pa-4">{{ t('leave.clash_detail') }}</VCardTitle>
      <VDivider />
      <VCardText class="pa-0">
        <VExpansionPanels variant="accordion">
          <VExpansionPanel v-for="clash in clashes" :key="clash.division_id">
            <VExpansionPanelTitle>
              <div class="d-flex align-center gap-3 w-100">
                <VChip :color="riskChipColor(clash.risk)" size="small" label>{{ clash.risk.toUpperCase() }}</VChip>
                <span class="font-weight-medium">{{ clash.division_name }}</span>
                <VSpacer />
                <span class="text-caption text-medium-emphasis me-2">{{ clash.concurrent }} {{ t('leave_workforce_dashboard.employees_label') }} — {{ clash.coverage_pct }}% {{ t('leave_workforce_dashboard.coverage_label') }}</span>
                <span class="text-caption text-medium-emphasis">{{ clash.period.start }} → {{ clash.period.end }}</span>
              </div>
            </VExpansionPanelTitle>
            <VExpansionPanelText>
              <VTable density="compact">
                <thead>
                  <tr>
                    <th>{{ t('leave_workforce_dashboard.employee_col') }}</th>
                    <th>{{ t('leave_workforce_dashboard.leave_type_col') }}</th>
                    <th>{{ t('leave_workforce_dashboard.from_col') }}</th>
                    <th>{{ t('leave_workforce_dashboard.to_col') }}</th>
                    <th>{{ t('leave_workforce_dashboard.status_col') }}</th>
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

    <div v-if="!clashes.length && !criticalWarnings.length" class="mt-4 text-center text-medium-emphasis">
      <i class="ri-checkbox-circle-line" style="font-size:2rem;color:#4caf50" />
      <p class="mt-2">{{ t('leave.no_clashes_warnings') }}</p>
    </div>
  </div>
</template>

<style lang="scss" scoped>
.heatmap-table {
  font-size: 0.72rem;
  th, td {
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    white-space: nowrap;
  }
}
</style>
