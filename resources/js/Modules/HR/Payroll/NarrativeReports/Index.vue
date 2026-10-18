<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { ref } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Run {
  id?: number; uuid: string; ref_number: string; name: string
  period_start: string; period_end: string; status: string
}

interface ExecSummary {
  narrative: string; total_gross: number; total_net: number
  total_deductions: number; employee_count: number
  gross_variance: number | null; net_variance: number | null
  open_anomalies: number; critical_count: number
  prev_run: { ref_number: string; period_start: string } | null
}

interface VarianceFactor { factor: string; change: number; narrative: string }
interface Variance {
  narrative: string; items: VarianceFactor[]
  net_diff: number; gross_diff: number; has_prev: boolean
  prev_run?: { ref_number: string; period_start: string }
}

interface DeptSection {
  department: string; headcount: number; total_gross: number
  total_net: number; avg_gross: number; share_pct: number; narrative: string
}
interface Departments { narrative: string; sections: DeptSection[]; dept_count: number }

interface RiskItem { anomaly_type: string; severity: string; status: string; cnt: number }
interface Risk { narrative: string; total_anomalies: number; critical_items: number; open_items: number; total_risks: number; anomaly_summary: RiskItem[] }

interface ComplianceItem { type: string; severity: string; status: string; employee_id: number | null; description: string }
interface Compliance { narrative: string; total: number; open: number; critical: number; violations: ComplianceItem[] }

interface LeakageItem { type: string; severity: string; status: string; employee_id: number | null; description: string }
interface Leakage { narrative: string; total: number; open: number; estimated_loss: number; items: LeakageItem[] }

interface Report {
  executive: ExecSummary
  variance: Variance
  departments: Departments
  risk: Risk
  compliance: Compliance
  leakage: Leakage
  generated_at: string
  run: Record<string, any>
}

const props = defineProps<{
  runs:        Run[]
  selectedRun: Run | null
  report:      Report | null
}>()

const selectedUuid = ref(props.selectedRun?.uuid ?? '')

function changeRun() {
  router.get(route('payroll.narrative-reports.index'), { run: selectedUuid.value }, { preserveState: false })
}

const downloading = ref(false)
function downloadPdf() {
  if (!props.selectedRun) return
  downloading.value = true
  window.open(route('payroll.narrative-reports.pdf', props.selectedRun.uuid), '_blank')
  setTimeout(() => { downloading.value = false }, 2000)
}

const fmt = (n: number) =>
  'R ' + new Intl.NumberFormat('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n)

function formatDate(date: string | null): string {
  if (!date) return '—'
  const d = new Date(date)
  return d.toLocaleDateString('en-ZA', {
    year: 'numeric',
    month: 'short',
    day: '2-digit',
  })
}

const varColor = (v: number | null) => v == null ? 'grey' : v > 5 ? 'error' : v < -5 ? 'info' : 'success'
const sevColor = (s: string) => ({ critical: 'purple', high: 'error', medium: 'warning', low: 'info' }[s] ?? 'grey')
const statusColor = (s: string) => ({ open: 'error', resolved: 'success', dismissed: 'grey', investigating: 'warning' }[s] ?? 'grey')

const activeTab = ref('executive')
const tabs = [
  { key: 'executive',   label: t('payroll_narrative_reports.tabs.executive'),   icon: 'tabler-briefcase' },
  { key: 'variance',    label: t('payroll_narrative_reports.tabs.variance'),    icon: 'tabler-trending-up' },
  { key: 'departments', label: t('payroll_narrative_reports.tabs.departments'), icon: 'tabler-building' },
  { key: 'risk',        label: t('payroll_narrative_reports.tabs.risk'),        icon: 'tabler-shield-exclamation' },
  { key: 'compliance',  label: t('payroll_narrative_reports.tabs.compliance'),  icon: 'tabler-gavel' },
  { key: 'leakage',     label: t('payroll_narrative_reports.tabs.leakage'),     icon: 'tabler-droplet' },
]
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('payroll_narrative_reports.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('payroll_narrative_reports.description') }}</p>
      </div>
      <div class="d-flex ga-2">
        <VBtn v-if="report" color="error" variant="outlined" prepend-icon="tabler-file-type-pdf"
          :loading="downloading" @click="downloadPdf">
          {{ t('payroll_narrative_reports.export_pdf') }}
        </VBtn>
      </div>
    </div>

    <VCard elevation="0" border class="mb-4">
      <VCardText class="pa-3">
        <VRow dense align="center">
          <VCol cols="12" sm="5">
            <VSelect v-model="selectedUuid" :label="t('payroll_narrative_reports.select_run')"
              :items="runs" item-title="ref_number" item-value="uuid"
              density="compact" variant="outlined" hide-details
              @update:model-value="changeRun">
              <template #item="{ item, props: p }">
                <VListItem v-bind="p">
                  <template #subtitle>{{ formatDate(item.raw.period_start) }} · {{ item.raw.status }}</template>
                </VListItem>
              </template>
            </VSelect>
          </VCol>
          <VCol v-if="selectedRun" cols="12" sm="7">
            <div class="d-flex ga-2 flex-wrap">
              <VChip size="small" variant="tonal" color="primary">{{ selectedRun.ref_number }}</VChip>
              <VChip size="small" variant="tonal">{{ formatDate(selectedRun.period_start) }} → {{ formatDate(selectedRun.period_end) }}</VChip>
              <VChip size="small" variant="tonal" :color="selectedRun.status === 'released' ? 'success' : 'warning'">
                {{ selectedRun.status }}
              </VChip>
              <span v-if="report" class="text-caption text-medium-emphasis align-self-center">
                {{ t('payroll_narrative_reports.generated', { date: formatDate(report.generated_at) }) }}
              </span>
            </div>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <div v-if="!report" class="text-center py-12 text-medium-emphasis">
      <VIcon size="48" class="mb-3">tabler-file-analytics</VIcon>
      <div>{{ t('payroll_narrative_reports.no_report') }}</div>
    </div>

    <template v-else>
      <VTabs v-model="activeTab" density="compact" class="mb-4">
        <VTab v-for="tab in tabs" :key="tab.key" :value="tab.key">
          <VIcon start size="16">{{ tab.icon }}</VIcon>{{ tab.label }}
        </VTab>
      </VTabs>

      <VWindow v-model="activeTab">
        <VWindowItem value="executive">
          <VCard elevation="0" border class="mb-4">
            <VCardTitle class="pa-4 text-body-1 font-weight-semibold d-flex align-center ga-2">
              <VIcon color="primary" size="20">tabler-briefcase</VIcon>{{ t('payroll_narrative_reports.executive.title') }}
            </VCardTitle>
            <VDivider />
            <VCardText class="pa-4">
              <VAlert variant="tonal" color="primary" density="compact" class="mb-4 text-body-2" border="start">
                {{ report.executive.narrative }}
              </VAlert>
              <VRow dense>
                <VCol cols="6" sm="3">
                  <VCard elevation="0" border>
                    <VCardText class="pa-3 text-center">
                      <div class="text-h6 font-weight-bold text-primary">{{ fmt(report.executive.total_gross) }}</div>
                      <div class="text-caption text-medium-emphasis">{{ t('payroll_narrative_reports.executive.total_gross') }}</div>
                      <VChip v-if="report.executive.gross_variance != null" class="mt-1" size="x-small" :color="varColor(report.executive.gross_variance)" variant="tonal">
                        {{ report.executive.gross_variance >= 0 ? '+' : '' }}{{ report.executive.gross_variance }}%
                      </VChip>
                    </VCardText>
                  </VCard>
                </VCol>
                <VCol cols="6" sm="3">
                  <VCard elevation="0" border>
                    <VCardText class="pa-3 text-center">
                      <div class="text-h6 font-weight-bold text-success">{{ fmt(report.executive.total_net) }}</div>
                      <div class="text-caption text-medium-emphasis">{{ t('payroll_narrative_reports.executive.total_net') }}</div>
                      <VChip v-if="report.executive.net_variance != null" class="mt-1" size="x-small" :color="varColor(report.executive.net_variance)" variant="tonal">
                        {{ report.executive.net_variance >= 0 ? '+' : '' }}{{ report.executive.net_variance }}%
                      </VChip>
                    </VCardText>
                  </VCard>
                </VCol>
                <VCol cols="6" sm="3">
                  <VCard elevation="0" border>
                    <VCardText class="pa-3 text-center">
                      <div class="text-h6 font-weight-bold text-warning">{{ fmt(report.executive.total_deductions) }}</div>
                      <div class="text-caption text-medium-emphasis">{{ t('payroll_narrative_reports.executive.total_deductions') }}</div>
                    </VCardText>
                  </VCard>
                </VCol>
                <VCol cols="6" sm="3">
                  <VCard elevation="0" border>
                    <VCardText class="pa-3 text-center">
                      <div class="text-h6 font-weight-bold" :class="report.executive.open_anomalies > 0 ? 'text-error' : 'text-success'">
                        {{ report.executive.open_anomalies }}
                      </div>
                      <div class="text-caption text-medium-emphasis">{{ t('payroll_narrative_reports.executive.open_anomalies') }}</div>
                      <VChip v-if="report.executive.critical_count > 0" class="mt-1" size="x-small" color="purple" variant="tonal">
                        {{ t('payroll_narrative_reports.executive.critical_count', { count: report.executive.critical_count }) }}
                      </VChip>
                    </VCardText>
                  </VCard>
                </VCol>
              </VRow>
              <div v-if="report.executive.prev_run" class="text-caption text-medium-emphasis mt-3">
                {{ t('payroll_narrative_reports.executive.compared_to', { ref: report.executive.prev_run.ref_number, date: formatDate(report.executive.prev_run.period_start) }) }}
              </div>
            </VCardText>
          </VCard>
        </VWindowItem>

        <VWindowItem value="variance">
          <VCard elevation="0" border class="mb-4">
            <VCardTitle class="pa-4 text-body-1 font-weight-semibold d-flex align-center ga-2">
              <VIcon color="warning" size="20">tabler-trending-up</VIcon>{{ t('payroll_narrative_reports.variance.title') }}
            </VCardTitle>
            <VDivider />
            <VCardText class="pa-4">
              <VAlert variant="tonal" color="warning" density="compact" class="mb-4 text-body-2" border="start">
                {{ report.variance.narrative }}
              </VAlert>
              <div v-if="!report.variance.has_prev" class="text-center py-4 text-medium-emphasis text-caption">
                {{ t('payroll_narrative_reports.variance.no_prior') }}
              </div>
              <VDataTable v-else-if="report.variance.items.length" :items="report.variance.items"
                :headers="[
                  { title: t('payroll_narrative_reports.variance.table.factor'), key: 'factor' },
                  { title: t('payroll_narrative_reports.variance.table.change'), key: 'change' },
                  { title: t('payroll_narrative_reports.variance.table.detail'), key: 'narrative' },
                ]"
                density="comfortable" hide-default-footer items-per-page="-1">
                <template #item.change="{ item }">
                  <VChip size="x-small" :color="item.change >= 0 ? 'error' : 'info'" variant="tonal">
                    {{ item.change >= 0 ? '+' : '' }}{{ typeof item.change === 'number' ? item.change.toFixed(2) : item.change }}
                  </VChip>
                </template>
              </VDataTable>
              <div v-else class="text-center py-4 text-medium-emphasis text-caption">
                {{ t('payroll_narrative_reports.variance.no_material') }}
              </div>
            </VCardText>
          </VCard>
        </VWindowItem>

        <VWindowItem value="departments">
          <VCard elevation="0" border class="mb-4">
            <VCardTitle class="pa-4 text-body-1 font-weight-semibold d-flex align-center ga-2">
              <VIcon color="info" size="20">tabler-building</VIcon>{{ t('payroll_narrative_reports.departments.title') }}
            </VCardTitle>
            <VDivider />
            <VCardText class="pa-4">
              <VAlert variant="tonal" color="info" density="compact" class="mb-4 text-body-2" border="start">
                {{ report.departments.narrative }}
              </VAlert>
              <div v-if="!report.departments.sections.length" class="text-center py-4 text-medium-emphasis text-caption">
                {{ t('payroll_narrative_reports.departments.no_data') }}
              </div>
              <VDataTable v-else :items="report.departments.sections"
                :headers="[
                  { title: t('payroll_narrative_reports.departments.table.department'), key: 'department' },
                  { title: t('payroll_narrative_reports.departments.table.headcount'),  key: 'headcount' },
                  { title: t('payroll_narrative_reports.departments.table.total_gross'),key: 'total_gross' },
                  { title: t('payroll_narrative_reports.departments.table.total_net'),  key: 'total_net' },
                  { title: t('payroll_narrative_reports.departments.table.avg_gross'),  key: 'avg_gross' },
                  { title: t('payroll_narrative_reports.departments.table.share'),      key: 'share_pct' },
                ]"
                density="comfortable" hide-default-footer items-per-page="-1">
                <template #item.total_gross="{ item }"><span class="font-weight-medium">{{ fmt(item.total_gross) }}</span></template>
                <template #item.total_net="{ item }"><span class="text-success">{{ fmt(item.total_net) }}</span></template>
                <template #item.avg_gross="{ item }"><span class="text-caption text-medium-emphasis">{{ fmt(item.avg_gross) }}</span></template>
                <template #item.share_pct="{ item }">
                  <VChip size="x-small" color="primary" variant="tonal">{{ item.share_pct }}%</VChip>
                </template>
              </VDataTable>
            </VCardText>
          </VCard>
        </VWindowItem>

        <VWindowItem value="risk">
          <VCard elevation="0" border class="mb-4">
            <VCardTitle class="pa-4 text-body-1 font-weight-semibold d-flex align-center ga-2">
              <VIcon color="error" size="20">tabler-shield-exclamation</VIcon>{{ t('payroll_narrative_reports.risk.title') }}
            </VCardTitle>
            <VDivider />
            <VCardText class="pa-4">
              <VAlert variant="tonal" :color="report.risk.critical_items > 0 ? 'error' : 'success'" density="compact" class="mb-4 text-body-2" border="start">
                {{ report.risk.narrative }}
              </VAlert>
              <VRow dense class="mb-4">
                <VCol cols="4">
                  <VCard elevation="0" border>
                    <VCardText class="pa-3 text-center">
                      <div class="text-h6 font-weight-bold text-error">{{ report.risk.total_anomalies }}</div>
                      <div class="text-caption text-medium-emphasis">{{ t('payroll_narrative_reports.risk.total_anomalies') }}</div>
                    </VCardText>
                  </VCard>
                </VCol>
                <VCol cols="4">
                  <VCard elevation="0" border>
                    <VCardText class="pa-3 text-center">
                      <div class="text-h6 font-weight-bold text-purple">{{ report.risk.critical_items }}</div>
                      <div class="text-caption text-medium-emphasis">{{ t('payroll_narrative_reports.risk.critical') }}</div>
                    </VCardText>
                  </VCard>
                </VCol>
                <VCol cols="4">
                  <VCard elevation="0" border>
                    <VCardText class="pa-3 text-center">
                      <div class="text-h6 font-weight-bold text-warning">{{ report.risk.open_items }}</div>
                      <div class="text-caption text-medium-emphasis">{{ t('payroll_narrative_reports.risk.open') }}</div>
                    </VCardText>
                  </VCard>
                </VCol>
              </VRow>
              <VDataTable v-if="report.risk.anomaly_summary.length" :items="report.risk.anomaly_summary"
                :headers="[
                  { title: t('payroll_narrative_reports.risk.table.type'), key: 'anomaly_type' },
                  { title: t('payroll_narrative_reports.risk.table.severity'), key: 'severity' },
                  { title: t('payroll_narrative_reports.risk.table.status'), key: 'status' },
                  { title: t('payroll_narrative_reports.risk.table.count'), key: 'cnt' },
                ]"
                density="comfortable" hide-default-footer items-per-page="-1">
                <template #item.anomaly_type="{ item }">
                  <span class="text-body-2">{{ item.anomaly_type.replace(/_/g, ' ') }}</span>
                </template>
                <template #item.severity="{ item }">
                  <VChip size="x-small" :color="sevColor(item.severity)" variant="tonal">{{ item.severity }}</VChip>
                </template>
                <template #item.status="{ item }">
                  <VChip size="x-small" :color="statusColor(item.status)" variant="tonal">{{ item.status }}</VChip>
                </template>
              </VDataTable>
            </VCardText>
          </VCard>
        </VWindowItem>

        <VWindowItem value="compliance">
          <VCard elevation="0" border class="mb-4">
            <VCardTitle class="pa-4 text-body-1 font-weight-semibold d-flex align-center ga-2">
              <VIcon color="purple" size="20">tabler-gavel</VIcon>{{ t('payroll_narrative_reports.compliance.title') }}
            </VCardTitle>
            <VDivider />
            <VCardText class="pa-4">
              <VAlert variant="tonal" :color="report.compliance.critical > 0 ? 'purple' : (report.compliance.total > 0 ? 'warning' : 'success')" density="compact" class="mb-4 text-body-2" border="start">
                {{ report.compliance.narrative }}
              </VAlert>
              <div v-if="!report.compliance.violations.length" class="text-center py-4 text-medium-emphasis text-caption">
                {{ t('payroll_narrative_reports.compliance.no_violations') }}
              </div>
              <VDataTable v-else :items="report.compliance.violations"
                :headers="[
                  { title: t('payroll_narrative_reports.compliance.table.violation'), key: 'type' },
                  { title: t('payroll_narrative_reports.compliance.table.employee'), key: 'employee_id' },
                  { title: t('payroll_narrative_reports.compliance.table.severity'), key: 'severity' },
                  { title: t('payroll_narrative_reports.compliance.table.status'), key: 'status' },
                  { title: t('payroll_narrative_reports.compliance.table.description'), key: 'description' },
                ]"
                density="comfortable" hide-default-footer items-per-page="-1">
                <template #item.type="{ item }">
                  <span class="text-body-2">{{ item.type.replace(/_/g, ' ') }}</span>
                </template>
                <template #item.severity="{ item }">
                  <VChip size="x-small" :color="sevColor(item.severity)" variant="tonal">{{ item.severity }}</VChip>
                </template>
                <template #item.status="{ item }">
                  <VChip size="x-small" :color="statusColor(item.status)" variant="tonal">{{ item.status }}</VChip>
                </template>
              </VDataTable>
            </VCardText>
          </VCard>
        </VWindowItem>

        <VWindowItem value="leakage">
          <VCard elevation="0" border class="mb-4">
            <VCardTitle class="pa-4 text-body-1 font-weight-semibold d-flex align-center ga-2">
              <VIcon color="orange" size="20">tabler-droplet</VIcon>{{ t('payroll_narrative_reports.leakage.title') }}
              <VChip v-if="report.leakage.estimated_loss > 0" size="small" color="orange" variant="tonal" class="ml-auto">
                {{ t('payroll_narrative_reports.leakage.exposure', { amount: new Intl.NumberFormat('en-ZA', { minimumFractionDigits: 2 }).format(report.leakage.estimated_loss) }) }}
              </VChip>
            </VCardTitle>
            <VDivider />
            <VCardText class="pa-4">
              <VAlert variant="tonal" :color="report.leakage.total > 0 ? 'orange' : 'success'" density="compact" class="mb-4 text-body-2" border="start">
                {{ report.leakage.narrative }}
              </VAlert>
              <div v-if="!report.leakage.items.length" class="text-center py-4 text-medium-emphasis text-caption">
                {{ t('payroll_narrative_reports.leakage.no_items') }}
              </div>
              <VDataTable v-else :items="report.leakage.items"
                :headers="[
                  { title: t('payroll_narrative_reports.leakage.table.type'), key: 'type' },
                  { title: t('payroll_narrative_reports.leakage.table.employee'), key: 'employee_id' },
                  { title: t('payroll_narrative_reports.leakage.table.severity'), key: 'severity' },
                  { title: t('payroll_narrative_reports.leakage.table.status'), key: 'status' },
                  { title: t('payroll_narrative_reports.leakage.table.description'), key: 'description' },
                ]"
                density="comfortable" hide-default-footer items-per-page="-1">
                <template #item.type="{ item }">
                  <span class="text-body-2">{{ item.type.replace(/_/g, ' ') }}</span>
                </template>
                <template #item.severity="{ item }">
                  <VChip size="x-small" :color="sevColor(item.severity)" variant="tonal">{{ item.severity }}</VChip>
                </template>
                <template #item.status="{ item }">
                  <VChip size="x-small" :color="statusColor(item.status)" variant="tonal">{{ item.status }}</VChip>
                </template>
              </VDataTable>
            </VCardText>
          </VCard>
        </VWindowItem>
      </VWindow>
    </template>
  </div>
</template>
