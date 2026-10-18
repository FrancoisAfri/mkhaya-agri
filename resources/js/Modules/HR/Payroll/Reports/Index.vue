<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Run = {
  id: number; uuid: string; ref_number: string; name: string; status: string
  period_start: string; period_end: string; total_net: number; employee_count: number
}
type CurrentRun = Run & { total_gross: number }
type MonthlyCost  = { month: string; total: number }

const props = defineProps<{
  currentRun:     CurrentRun | null
  runs:           Run[]
  stats:          { total_runs: number; total_released: number; avg_monthly_cost: number; open_anomalies: number }
  monthlyCosts:   MonthlyCost[]
  payslips:       Record<string, any>[]
  grossBreakdown: Record<string, any>[]
  deductions:     Record<string, any>[]
  netPay:         Record<string, any>[]
  overtime:       Record<string, any>[]
  headcount:      Record<string, any>[]
  variance:       Record<string, any>[]
  tax:            Record<string, any>[]
  anomalies:      Record<string, any>[]
  corrections:    Record<string, any>[]
  leakage:        Record<string, any>[]
  reportKeys:     Record<string, string>
}>()

function formatDate(date: string | null): string {
  if (!date) return '—'
  const d = new Date(date)
  return d.toLocaleDateString('en-ZA', { year: 'numeric', month: 'short', day: '2-digit' })
}

const fmtR = (v: number | string) =>
  `R ${Number(v).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

const statusColor = (s: string) =>
  ({ released: 'success', draft: 'secondary', processing: 'info' }[s] ?? 'warning')

const severityColor = (s: string) =>
  ({ critical: 'error', high: 'warning', medium: 'info', low: 'success' }[s] ?? 'grey')

const selectedRunId = ref<number | null>(props.currentRun?.id ?? null)
const switchRun = (id: number | null) => {
  router.get(route('payroll.reports.index'), id ? { run_id: id } : {}, { preserveState: false })
}

const activeTab = ref<string>(Object.keys(props.reportKeys)[0] ?? 'payslips')
const search    = ref('')

const reportIconMap: Record<string, string> = {
  payslips: 'mdi-file-account-outline', gross_breakdown: 'mdi-cash-multiple',
  deductions: 'mdi-minus-circle-outline', net_pay: 'mdi-bank-transfer',
  overtime: 'mdi-clock-plus-outline', headcount: 'mdi-account-group-outline',
  variance: 'mdi-trending-up', tax: 'mdi-calculator-variant-outline',
  anomalies: 'mdi-alert-circle-outline', corrections: 'mdi-auto-fix', leakage: 'mdi-shield-alert-outline',
}
const reportIcon = (key: string) => reportIconMap[key] ?? 'mdi-file-chart-outline'

const reportDataMap = computed<Record<string, Record<string, any>[]>>(() => ({
  payslips: props.payslips, gross_breakdown: props.grossBreakdown,
  deductions: props.deductions, net_pay: props.netPay, overtime: props.overtime,
  headcount: props.headcount, variance: props.variance, tax: props.tax,
  anomalies: props.anomalies, corrections: props.corrections, leakage: props.leakage,
}))

const rowCount    = (key: string) => reportDataMap.value[key]?.length ?? 0
const activeRows  = computed(() => reportDataMap.value[activeTab.value] ?? [])
const filteredRows = computed(() => {
  if (!search.value) return activeRows.value
  const q = search.value.toLowerCase()
  return activeRows.value.filter(row =>
    Object.values(row).some(v => String(v ?? '').toLowerCase().includes(q))
  )
})

type ColDef = { title: string; key: string; align?: 'end' | 'start' }
const headersMap = computed<Record<string, ColDef[]>>(() => ({
  payslips: [
    { title: t('payroll_reports.table.employee'), key: 'employee' },
    { title: t('payroll_reports.table.dept'), key: 'department' },
    { title: t('payroll_reports.table.run'), key: 'run' },
    { title: t('payroll_reports.table.period'), key: 'period' },
    { title: t('payroll_reports.table.basic'), key: 'basic_salary', align: 'end' },
    { title: t('payroll_reports.table.gross'), key: 'gross_pay', align: 'end' },
    { title: t('payroll_reports.table.deductions'), key: 'deductions', align: 'end' },
    { title: t('payroll_reports.table.paye'), key: 'paye', align: 'end' },
    { title: t('payroll_reports.table.uif'), key: 'uif', align: 'end' },
    { title: t('payroll_reports.table.pension'), key: 'pension', align: 'end' },
    { title: t('payroll_reports.table.net_pay'), key: 'net_pay', align: 'end' },
    { title: t('payroll_reports.table.status'), key: 'status' },
  ],
  gross_breakdown: [
    { title: t('payroll_reports.table.employee'), key: 'employee' },
    { title: t('payroll_reports.table.dept'), key: 'department' },
    { title: t('payroll_reports.table.run'), key: 'run' },
    { title: t('payroll_reports.table.type'), key: 'line_type' },
    { title: t('payroll_reports.table.description'), key: 'description' },
    { title: t('payroll_reports.table.qty'), key: 'qty', align: 'end' },
    { title: t('payroll_reports.table.amount'), key: 'amount', align: 'end' },
  ],
  deductions: [
    { title: t('payroll_reports.table.employee'), key: 'employee' },
    { title: t('payroll_reports.table.dept'), key: 'department' },
    { title: t('payroll_reports.table.run'), key: 'run' },
    { title: t('payroll_reports.table.paye'), key: 'paye', align: 'end' },
    { title: t('payroll_reports.table.uif'), key: 'uif', align: 'end' },
    { title: t('payroll_reports.table.pension'), key: 'pension', align: 'end' },
    { title: t('payroll_reports.table.total'), key: 'total_deductions', align: 'end' },
  ],
  net_pay: [
    { title: t('payroll_reports.table.employee'), key: 'employee' },
    { title: t('payroll_reports.table.dept'), key: 'department' },
    { title: t('payroll_reports.table.run'), key: 'run' },
    { title: t('payroll_reports.table.period'), key: 'period_start' },
    { title: t('payroll_reports.table.gross'), key: 'gross_pay', align: 'end' },
    { title: t('payroll_reports.table.deductions'), key: 'deductions', align: 'end' },
    { title: t('payroll_reports.table.net_pay'), key: 'net_pay', align: 'end' },
  ],
  overtime: [
    { title: t('payroll_reports.table.employee'), key: 'employee' },
    { title: t('payroll_reports.table.dept'), key: 'department' },
    { title: t('payroll_reports.table.run'), key: 'run' },
    { title: t('payroll_reports.table.description'), key: 'description' },
    { title: t('payroll_reports.table.hours'), key: 'hours', align: 'end' },
    { title: t('payroll_reports.table.amount'), key: 'amount', align: 'end' },
  ],
  headcount: [
    { title: t('payroll_reports.table.run'), key: 'run' },
    { title: t('payroll_reports.table.period'), key: 'period' },
    { title: t('payroll_reports.table.dept'), key: 'department' },
    { title: t('payroll_reports.table.headcount'), key: 'headcount', align: 'end' },
    { title: t('payroll_reports.table.total_net'), key: 'total_net', align: 'end' },
    { title: t('payroll_reports.table.avg_net'), key: 'avg_net', align: 'end' },
  ],
  variance: [
    { title: t('payroll_reports.table.employee'), key: 'employee' },
    { title: t('payroll_reports.table.dept'), key: 'department' },
    { title: t('payroll_reports.table.previous_net'), key: 'previous_net', align: 'end' },
    { title: t('payroll_reports.table.current_net'), key: 'current_net', align: 'end' },
    { title: t('payroll_reports.table.variance_r'), key: 'variance_r', align: 'end' },
    { title: t('payroll_reports.table.variance_pct'), key: 'variance_pct', align: 'end' },
  ],
  tax: [
    { title: t('payroll_reports.table.employee'), key: 'employee' },
    { title: t('payroll_reports.table.dept'), key: 'department' },
    { title: t('payroll_reports.table.run'), key: 'run' },
    { title: t('payroll_reports.table.gross'), key: 'gross_pay', align: 'end' },
    { title: t('payroll_reports.table.taxable_income'), key: 'taxable_income', align: 'end' },
    { title: t('payroll_reports.table.paye'), key: 'paye', align: 'end' },
    { title: t('payroll_reports.table.uif'), key: 'uif', align: 'end' },
    { title: t('payroll_reports.table.pension'), key: 'pension', align: 'end' },
    { title: t('payroll_reports.table.total_tax'), key: 'total_tax', align: 'end' },
  ],
  anomalies: [
    { title: t('payroll_reports.table.employee'), key: 'employee' },
    { title: t('payroll_reports.table.dept'), key: 'department' },
    { title: t('payroll_reports.table.run'), key: 'run' },
    { title: t('payroll_reports.table.anomaly_type'), key: 'anomaly_type' },
    { title: t('payroll_reports.table.severity'), key: 'severity' },
    { title: t('payroll_reports.table.description'), key: 'description' },
    { title: t('payroll_reports.table.status'), key: 'status' },
    { title: t('payroll_reports.table.detected'), key: 'detected_at' },
  ],
  corrections: [
    { title: t('payroll_reports.table.employee'), key: 'employee' },
    { title: t('payroll_reports.table.run'), key: 'run' },
    { title: t('payroll_reports.table.correction_type'), key: 'correction_type' },
    { title: t('payroll_reports.table.field'), key: 'field_affected' },
    { title: t('payroll_reports.table.original'), key: 'original_value', align: 'end' },
    { title: t('payroll_reports.table.corrected'), key: 'corrected_value', align: 'end' },
    { title: t('payroll_reports.table.status'), key: 'status' },
    { title: t('payroll_reports.table.applied'), key: 'applied_at' },
  ],
  leakage: [
    { title: t('payroll_reports.table.employee'), key: 'employee' },
    { title: t('payroll_reports.table.dept'), key: 'department' },
    { title: t('payroll_reports.table.run'), key: 'run' },
    { title: t('payroll_reports.table.anomaly_type'), key: 'anomaly_type' },
    { title: t('payroll_reports.table.net_at_risk'), key: 'net_pay_at_risk', align: 'end' },
    { title: t('payroll_reports.table.severity'), key: 'severity' },
    { title: t('payroll_reports.table.status'), key: 'status' },
    { title: t('payroll_reports.table.description'), key: 'description' },
  ],
}))
const activeHeaders = computed(() => headersMap.value[activeTab.value] ?? [])

const numericKeysMap = computed<Record<string, string[]>>(() => ({
  payslips: ['basic_salary', 'gross_pay', 'deductions', 'paye', 'uif', 'pension', 'net_pay'],
  gross_breakdown: ['amount'], deductions: ['paye', 'uif', 'pension', 'total_deductions'],
  net_pay: ['gross_pay', 'deductions', 'net_pay'], overtime: ['hours', 'amount'],
  headcount: ['headcount', 'total_net'], variance: ['variance_r'],
  tax: ['gross_pay', 'taxable_income', 'paye', 'uif', 'pension', 'total_tax'],
  anomalies: [], corrections: ['original_value', 'corrected_value'], leakage: ['net_pay_at_risk'],
}))
const numericTotals = computed(() =>
  (numericKeysMap.value[activeTab.value] ?? []).map(k => ({
    key: k, label: activeHeaders.value.find(h => h.key === k)?.title ?? k,
    value: activeRows.value.reduce((s, r) => s + (Number(r[k]) || 0), 0),
  }))
)

const exportUrl = (key: string, format: string) => {
  const qs = selectedRunId.value ? `?run_id=${selectedRunId.value}` : ''
  return `/payroll/reports/export/${key}/${format}${qs}`
}

const queueDialog  = ref(false)
const queueKey     = ref('')
const queueEmail   = ref('')
const queueLoading = ref(false)
const snackbar     = ref({ show: false, text: '', color: 'success' })

function openQueue(key: string) { queueKey.value = key; queueEmail.value = ''; queueDialog.value = true }

async function submitQueue() {
  queueLoading.value = true
  try {
    const token = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? ''
    const qs = selectedRunId.value ? `?run_id=${selectedRunId.value}` : ''
    const res = await fetch(`/payroll/reports/queue/${queueKey.value}${qs}`, {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
      body: JSON.stringify({ email: queueEmail.value }),
    })
    const data = await res.json()
    snackbar.value = { show: true, text: data.message ?? t('payroll_reports.snackbar.queued'), color: 'success' }
    queueDialog.value = false
  } catch {
    snackbar.value = { show: true, text: t('payroll_reports.snackbar.failed'), color: 'error' }
  } finally { queueLoading.value = false }
}
</script>

<template>
  <v-container fluid class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-3">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('payroll_reports.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ currentRun
            ? t('payroll_reports.run_label', { ref: currentRun.ref_number, start: formatDate(currentRun.period_start), end: formatDate(currentRun.period_end) })
            : t('payroll_reports.all_runs') }}
        </p>
      </div>
      <v-select v-model="selectedRunId" :items="runs" item-title="ref_number" item-value="id"
        :label="t('payroll_reports.select_run')" density="compact" hide-details clearable
        style="min-width:220px; max-width:320px" @update:modelValue="switchRun" />
    </div>

    <v-row class="mb-4">
      <v-col cols="6" sm="3">
        <v-card rounded="lg" elevation="1">
          <v-card-text class="text-center pa-3">
            <div class="text-caption text-medium-emphasis">{{ t('payroll_reports.kpis.total_released_runs') }}</div>
            <div class="text-h5 font-weight-bold text-primary">{{ stats.total_released }}</div>
          </v-card-text>
        </v-card>
      </v-col>
      <v-col cols="6" sm="3">
        <v-card rounded="lg" elevation="1">
          <v-card-text class="text-center pa-3">
            <div class="text-caption text-medium-emphasis">{{ t('payroll_reports.kpis.avg_monthly_cost') }}</div>
            <div class="text-h5 font-weight-bold text-success">{{ fmtR(stats.avg_monthly_cost) }}</div>
          </v-card-text>
        </v-card>
      </v-col>
      <v-col cols="6" sm="3">
        <v-card rounded="lg" elevation="1">
          <v-card-text class="text-center pa-3">
            <div class="text-caption text-medium-emphasis">{{ t('payroll_reports.kpis.total_runs') }}</div>
            <div class="text-h5 font-weight-bold">{{ stats.total_runs }}</div>
          </v-card-text>
        </v-card>
      </v-col>
      <v-col cols="6" sm="3">
        <v-card rounded="lg" elevation="1">
          <v-card-text class="text-center pa-3">
            <div class="text-caption text-medium-emphasis">{{ t('payroll_reports.kpis.open_anomalies') }}</div>
            <div class="text-h5 font-weight-bold text-error">{{ stats.open_anomalies }}</div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <v-row class="mb-4">
      <v-col v-for="(label, key) in reportKeys" :key="key" cols="12" sm="6" md="4" lg="3">
        <v-card rounded="lg" elevation="1" :variant="activeTab === key ? 'tonal' : 'elevated'"
          :color="activeTab === key ? 'primary' : undefined" class="cursor-pointer" @click="activeTab = key as string">
          <v-card-text class="pa-3">
            <div class="d-flex align-center justify-space-between mb-2">
              <div class="d-flex align-center gap-2">
                <v-icon :icon="reportIcon(key as string)" size="18" />
                <span class="text-body-2 font-weight-medium">{{ label }}</span>
              </div>
              <v-chip size="x-small" color="secondary" variant="tonal">{{ rowCount(key as string) }}</v-chip>
            </div>
            <div class="d-flex gap-1">
              <v-btn size="x-small" variant="tonal" color="primary" @click.stop="activeTab = key as string">{{ t('payroll_reports.actions.view') }}</v-btn>
              <v-btn size="x-small" variant="tonal" color="success" :href="exportUrl(key as string, 'pdf')" target="_blank" @click.stop>{{ t('payroll_reports.actions.pdf') }}</v-btn>
              <v-btn size="x-small" variant="tonal" color="info" :href="exportUrl(key as string, 'csv')" @click.stop>{{ t('payroll_reports.actions.csv') }}</v-btn>
              <v-btn size="x-small" variant="tonal" color="warning" @click.stop="openQueue(key as string)">
                <v-icon icon="mdi-email-fast" size="14" />{{ t('payroll_reports.actions.queue') }}
              </v-btn>
            </div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <v-card rounded="lg" elevation="1">
      <v-card-title class="d-flex align-center justify-space-between pa-4 flex-wrap ga-2">
        <div class="d-flex align-center gap-2">
          <v-icon :icon="reportIcon(activeTab)" />
          <span>{{ reportKeys[activeTab] }}</span>
          <v-chip size="small" color="primary" variant="tonal">{{ t('payroll_reports.table.rows', { count: activeRows.length }) }}</v-chip>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <v-text-field v-model="search" prepend-inner-icon="mdi-magnify" :placeholder="t('payroll_reports.table.search')"
            density="compact" hide-details clearable style="max-width:260px" />
          <v-btn variant="tonal" color="success" size="small" :href="exportUrl(activeTab, 'pdf')" target="_blank">
            <v-icon start icon="mdi-file-pdf-box" />{{ t('payroll_reports.actions.pdf') }}
          </v-btn>
          <v-btn variant="tonal" color="info" size="small" :href="exportUrl(activeTab, 'csv')">
            <v-icon start icon="mdi-microsoft-excel" />{{ t('payroll_reports.actions.csv') }}
          </v-btn>
          <v-btn variant="tonal" color="warning" size="small" @click="openQueue(activeTab)">
            <v-icon start icon="mdi-email-fast" />{{ t('payroll_reports.actions.queue') }}
          </v-btn>
        </div>
      </v-card-title>

      <v-divider />

      <v-data-table :headers="activeHeaders" :items="filteredRows" :search="search" :items-per-page="25"
        density="compact" hover fixed-header height="520" class="text-body-2">
        <template #item.variance_r="{ item }">
          <span :class="item.variance_r > 0 ? 'text-success' : item.variance_r < 0 ? 'text-error' : ''">{{ fmtR(item.variance_r) }}</span>
        </template>
        <template #item.variance_pct="{ item }">
          <v-chip :color="item.variance_pct > 5 ? 'error' : item.variance_pct < -5 ? 'warning' : 'success'" size="x-small">{{ item.variance_pct }}%</v-chip>
        </template>
        <template #item.severity="{ item }">
          <v-chip :color="severityColor(item.severity)" size="x-small">{{ item.severity }}</v-chip>
        </template>
        <template #item.status="{ item }">
          <v-chip :color="statusColor(item.status)" size="x-small" variant="tonal">{{ item.status }}</v-chip>
        </template>
        <template #item.net_pay="{ item }"><span class="font-weight-medium">{{ fmtR(item.net_pay) }}</span></template>
        <template #item.net_pay_at_risk="{ item }"><span class="text-error font-weight-medium">{{ fmtR(item.net_pay_at_risk) }}</span></template>
        <template #item.gross_pay="{ item }">{{ fmtR(item.gross_pay) }}</template>
        <template #item.deductions="{ item }">{{ fmtR(item.deductions) }}</template>
        <template #item.total_deductions="{ item }">{{ fmtR(item.total_deductions) }}</template>
        <template #item.paye="{ item }">{{ fmtR(item.paye) }}</template>
        <template #item.uif="{ item }">{{ fmtR(item.uif) }}</template>
        <template #item.pension="{ item }">{{ fmtR(item.pension) }}</template>
        <template #item.amount="{ item }">{{ fmtR(item.amount) }}</template>
        <template #item.total_net="{ item }">{{ fmtR(item.total_net) }}</template>
        <template #item.basic_salary="{ item }">{{ fmtR(item.basic_salary) }}</template>
        <template #item.taxable_income="{ item }">{{ fmtR(item.taxable_income) }}</template>
        <template #item.total_tax="{ item }">{{ fmtR(item.total_tax) }}</template>
        <template #item.previous_net="{ item }">{{ fmtR(item.previous_net) }}</template>
        <template #item.current_net="{ item }">{{ fmtR(item.current_net) }}</template>
        <template #item.original_value="{ item }">{{ fmtR(item.original_value) }}</template>
        <template #item.corrected_value="{ item }">{{ fmtR(item.corrected_value) }}</template>
      </v-data-table>

      <v-card-text v-if="numericTotals.length" class="pt-0 pb-3">
        <v-divider class="mb-2" />
        <div class="d-flex gap-4 text-body-2 text-medium-emphasis flex-wrap">
          <span class="font-weight-medium">{{ t('payroll_reports.table.totals') }}</span>
          <span v-for="total in numericTotals" :key="total.key">
            {{ total.label }}: <strong>{{ fmtR(total.value) }}</strong>
          </span>
        </div>
      </v-card-text>
    </v-card>

    <v-card rounded="lg" elevation="1" class="mt-4">
      <v-card-title class="pa-4 text-body-1 font-weight-medium">
        <v-icon icon="mdi-chart-line" class="mr-2" />{{ t('payroll_reports.monthly_cost_trend') }}
      </v-card-title>
      <v-divider />
      <v-table density="compact">
        <thead>
          <tr>
            <th class="text-left text-caption text-medium-emphasis pa-2" style="border-bottom: 1px solid rgba(0,0,0,.08);">{{ t('payroll_reports.month') }}</th>
            <th class="text-right text-caption text-medium-emphasis pa-2" style="border-bottom: 1px solid rgba(0,0,0,.08);">{{ t('payroll_reports.net_total') }}</th>
            <th class="pa-2" style="border-bottom: 1px solid rgba(0,0,0,.08); width: 40%;" />
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in monthlyCosts" :key="row.month" style="border-bottom: 1px solid rgba(0,0,0,.04);">
            <td class="pa-2 text-body-2 text-medium-emphasis">{{ row.month }}</td>
            <td class="pa-2 text-body-2 text-right font-weight-semibold">{{ fmtR(row.total) }}</td>
            <td class="pa-2">
              <v-progress-linear :model-value="monthlyCosts.length ? (row.total / Math.max(...monthlyCosts.map(r => r.total))) * 100 : 0"
                color="primary" height="6" rounded />
            </td>
          </tr>
        </tbody>
      </v-table>
    </v-card>
  </v-container>

  <v-dialog v-model="queueDialog" max-width="440">
    <v-card rounded="lg">
      <v-card-title class="pa-4">{{ t('payroll_reports.dialog.queue_title') }}</v-card-title>
      <v-divider />
      <v-card-text class="pa-4">
        <p class="text-body-2 mb-3">{{ t('payroll_reports.dialog.queue_description', { report: reportKeys[queueKey] }) }}</p>
        <v-text-field v-model="queueEmail" :label="t('payroll_reports.dialog.recipient_email')" type="email" density="compact" />
      </v-card-text>
      <v-card-actions class="pa-4 pt-0">
        <v-spacer />
        <v-btn variant="text" @click="queueDialog = false">{{ t('payroll_reports.dialog.cancel') }}</v-btn>
        <v-btn color="warning" variant="flat" :loading="queueLoading" @click="submitQueue">{{ t('payroll_reports.dialog.send') }}</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <v-snackbar v-model="snackbar.show" :color="snackbar.color" timeout="4000" location="bottom right">
    {{ snackbar.text }}
  </v-snackbar>
</template>
