<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type ReportRow = Record<string, unknown>

const props = withDefaults(defineProps<{
  register:    ReportRow[]
  assignments: ReportRow[]
  maintenance: ReportRow[]
  depreciation:ReportRow[]
  incidents:   ReportRow[]
  movements:   ReportRow[]
  clearances:  ReportRow[]
  categories:  string[]
  locations:   string[]
  statuses:    string[]
}>(), {
  register:    () => [],
  assignments: () => [],
  maintenance: () => [],
  depreciation:() => [],
  incidents:   () => [],
  movements:   () => [],
  clearances:  () => [],
  categories:  () => [],
  locations:   () => [],
  statuses:    () => [],
})

const activeTab = ref('register')
const toast     = reactive({ show: false, text: '', color: 'success' })

const filters = reactive({
  category: null  as string | null,
  location: null  as string | null,
  status:   null  as string | null,
  dateFrom: null  as string | null,
  dateTo:   null  as string | null,
})

const reportCards = computed(() => [
  { key: 'register',    icon: 'tabler-box',         title: t('assets_reports_engine.cards.register.title'),     description: t('assets_reports_engine.cards.register.description') },
  { key: 'assignments', icon: 'tabler-user-check',   title: t('assets_reports_engine.cards.assignments.title'), description: t('assets_reports_engine.cards.assignments.description') },
  { key: 'maintenance', icon: 'tabler-tool',          title: t('assets_reports_engine.cards.maintenance.title'),description: t('assets_reports_engine.cards.maintenance.description') },
  { key: 'depreciation',icon: 'tabler-trending-down', title: t('assets_reports_engine.cards.depreciation.title'),description: t('assets_reports_engine.cards.depreciation.description') },
  { key: 'incidents',   icon: 'tabler-alert-triangle',title: t('assets_reports_engine.cards.incidents.title'),  description: t('assets_reports_engine.cards.incidents.description') },
  { key: 'movements',   icon: 'tabler-arrows-move',   title: t('assets_reports_engine.cards.movements.title'),  description: t('assets_reports_engine.cards.movements.description') },
  { key: 'clearances',  icon: 'tabler-door-exit',     title: t('assets_reports_engine.cards.clearances.title'), description: t('assets_reports_engine.cards.clearances.description') },
])

const scheduledReports = computed(() => [
  { cadence: t('assets_reports_engine.scheduled_items.daily.cadence'),     title: t('assets_reports_engine.scheduled_items.daily.title'),     recipients: t('assets_reports_engine.scheduled_items.daily.recipients'),    nextRun: t('assets_reports_engine.scheduled_items.daily.next_run') },
  { cadence: t('assets_reports_engine.scheduled_items.weekly.cadence'),    title: t('assets_reports_engine.scheduled_items.weekly.title'),    recipients: t('assets_reports_engine.scheduled_items.weekly.recipients'),   nextRun: t('assets_reports_engine.scheduled_items.weekly.next_run') },
  { cadence: t('assets_reports_engine.scheduled_items.monthly_dep.cadence'), title: t('assets_reports_engine.scheduled_items.monthly_dep.title'), recipients: t('assets_reports_engine.scheduled_items.monthly_dep.recipients'), nextRun: t('assets_reports_engine.scheduled_items.monthly_dep.next_run') },
  { cadence: t('assets_reports_engine.scheduled_items.monthly_export.cadence'), title: t('assets_reports_engine.scheduled_items.monthly_export.title'), recipients: t('assets_reports_engine.scheduled_items.monthly_export.recipients'), nextRun: t('assets_reports_engine.scheduled_items.monthly_export.next_run') },
])

const headersByReport = computed<Record<string, { title: string; key: string }[]>>(() => ({
  register: [
    { title: t('assets_reports_engine.headers.register.asset_number'), key: 'asset_number' },
    { title: t('assets_reports_engine.headers.register.name'), key: 'name' },
    { title: t('assets_reports_engine.headers.register.category'), key: 'category' },
    { title: t('assets_reports_engine.headers.register.type'), key: 'type' },
    { title: t('assets_reports_engine.headers.register.location'), key: 'location' },
    { title: t('assets_reports_engine.headers.register.condition'), key: 'condition' },
    { title: t('assets_reports_engine.headers.register.status'), key: 'status' },
    { title: t('assets_reports_engine.headers.register.purchase_cost'), key: 'purchase_cost' },
    { title: t('assets_reports_engine.headers.register.purchase_date'), key: 'purchase_date' },
    { title: t('assets_reports_engine.headers.register.warranty_expiry'), key: 'warranty_expiry' },
  ],
  assignments: [
    { title: t('assets_reports_engine.headers.assignments.asset_number'), key: 'asset_number' },
    { title: t('assets_reports_engine.headers.assignments.asset'), key: 'asset' },
    { title: t('assets_reports_engine.headers.assignments.assigned_to'), key: 'assigned_to' },
    { title: t('assets_reports_engine.headers.assignments.assigned_by'), key: 'assigned_by' },
    { title: t('assets_reports_engine.headers.assignments.location'), key: 'location' },
    { title: t('assets_reports_engine.headers.assignments.type'), key: 'type' },
    { title: t('assets_reports_engine.headers.assignments.effective_date'), key: 'effective_date' },
    { title: t('assets_reports_engine.headers.assignments.due_date'), key: 'due_date' },
    { title: t('assets_reports_engine.headers.assignments.returned_at'), key: 'returned_at' },
    { title: t('assets_reports_engine.headers.assignments.status'), key: 'status' },
  ],
  maintenance: [
    { title: t('assets_reports_engine.headers.maintenance.asset_number'), key: 'asset_number' },
    { title: t('assets_reports_engine.headers.maintenance.asset'), key: 'asset' },
    { title: t('assets_reports_engine.headers.maintenance.title'), key: 'title' },
    { title: t('assets_reports_engine.headers.maintenance.type'), key: 'type' },
    { title: t('assets_reports_engine.headers.maintenance.status'), key: 'status' },
    { title: t('assets_reports_engine.headers.maintenance.due_date'), key: 'due_date' },
    { title: t('assets_reports_engine.headers.maintenance.completed'), key: 'completed_at' },
    { title: t('assets_reports_engine.headers.maintenance.assigned_to'), key: 'assigned_to' },
    { title: t('assets_reports_engine.headers.maintenance.est_cost'), key: 'est_cost' },
    { title: t('assets_reports_engine.headers.maintenance.actual_cost'), key: 'actual_cost' },
  ],
  depreciation: [
    { title: t('assets_reports_engine.headers.depreciation.asset_number'), key: 'asset_number' },
    { title: t('assets_reports_engine.headers.depreciation.asset'), key: 'asset' },
    { title: t('assets_reports_engine.headers.depreciation.category'), key: 'category' },
    { title: t('assets_reports_engine.headers.depreciation.useful_life'), key: 'useful_life' },
    { title: t('assets_reports_engine.headers.depreciation.initial_value'), key: 'initial_value' },
    { title: t('assets_reports_engine.headers.depreciation.monthly_charge'), key: 'monthly_charge' },
    { title: t('assets_reports_engine.headers.depreciation.book_value'), key: 'book_value' },
    { title: t('assets_reports_engine.headers.depreciation.created_at'), key: 'created_at' },
  ],
  incidents: [
    { title: t('assets_reports_engine.headers.incidents.asset_number'), key: 'asset_number' },
    { title: t('assets_reports_engine.headers.incidents.asset'), key: 'asset' },
    { title: t('assets_reports_engine.headers.incidents.title'), key: 'title' },
    { title: t('assets_reports_engine.headers.incidents.reported_by'), key: 'reported_by' },
    { title: t('assets_reports_engine.headers.incidents.severity'), key: 'severity' },
    { title: t('assets_reports_engine.headers.incidents.status'), key: 'status' },
    { title: t('assets_reports_engine.headers.incidents.repair_cost'), key: 'repair_cost' },
    { title: t('assets_reports_engine.headers.incidents.occurred_at'), key: 'occurred_at' },
    { title: t('assets_reports_engine.headers.incidents.resolved_at'), key: 'resolved_at' },
    { title: t('assets_reports_engine.headers.incidents.resolution_notes'), key: 'resolution_notes' },
  ],
  movements: [
    { title: t('assets_reports_engine.headers.movements.asset_number'), key: 'asset_number' },
    { title: t('assets_reports_engine.headers.movements.asset'), key: 'asset' },
    { title: t('assets_reports_engine.headers.movements.from_location'), key: 'from_location' },
    { title: t('assets_reports_engine.headers.movements.to_location'), key: 'to_location' },
    { title: t('assets_reports_engine.headers.movements.moved_by'), key: 'moved_by' },
    { title: t('assets_reports_engine.headers.movements.reason'), key: 'reason' },
    { title: t('assets_reports_engine.headers.movements.moved_at'), key: 'moved_at' },
  ],
  clearances: [
    { title: t('assets_reports_engine.headers.clearances.employee'), key: 'employee' },
    { title: t('assets_reports_engine.headers.clearances.initiated_by'), key: 'initiated_by' },
    { title: t('assets_reports_engine.headers.clearances.status'), key: 'status' },
    { title: t('assets_reports_engine.headers.clearances.target_exit_date'), key: 'target_exit_date' },
    { title: t('assets_reports_engine.headers.clearances.cleared_at'), key: 'cleared_at' },
    { title: t('assets_reports_engine.headers.clearances.notes'), key: 'notes' },
  ],
}))

const reportDataMap = computed<Record<string, ReportRow[]>>(() => ({
  register:    props.register,
  assignments: props.assignments,
  maintenance: props.maintenance,
  depreciation:props.depreciation,
  incidents:   props.incidents,
  movements:   props.movements,
  clearances:  props.clearances,
}))

function normalizeDate(v: unknown): number | null {
  if (!v) return null
  const p = Date.parse(String(v))
  return Number.isNaN(p) ? null : p
}

function rowDate(row: ReportRow): number | null {
  const keys = ['purchase_date','effective_date','due_date','occurred_at','moved_at','created_at','target_exit_date']
  for (const k of keys) {
    const p = normalizeDate(row[k])
    if (p !== null) return p
  }
  return null
}

function applyFilters(rows: ReportRow[]): ReportRow[] {
  let next = [...rows]
  if (filters.category) next = next.filter(r => !r.category || r.category === filters.category)
  if (filters.location)  next = next.filter(r => !r.location  || r.location  === filters.location)
  if (filters.status) {
    const w = filters.status.toLowerCase()
    next = next.filter(r => [r.status, r.asset_status, r.condition].some(v => String(v ?? '').toLowerCase() === w))
  }
  const fromTs = normalizeDate(filters.dateFrom)
  const toTs   = normalizeDate(filters.dateTo)
  if (fromTs !== null || toTs !== null) {
    next = next.filter(r => {
      const dt = rowDate(r)
      if (dt === null) return true
      if (fromTs !== null && dt < fromTs) return false
      if (toTs   !== null && dt > toTs)   return false
      return true
    })
  }
  return next
}

const activeRows    = computed(() => applyFilters(reportDataMap.value[activeTab.value] ?? []))
const activeHeaders = computed(() => headersByReport.value[activeTab.value] ?? [])

function setToast(text: string, color = 'success') {
  toast.text = text; toast.color = color; toast.show = true
}

function buildQs() {
  const p = new URLSearchParams()
  if (filters.category) p.set('category', filters.category)
  if (filters.location)  p.set('location',  filters.location)
  if (filters.status)    p.set('status',    filters.status)
  if (filters.dateFrom)  p.set('dateFrom',  filters.dateFrom)
  if (filters.dateTo)    p.set('dateTo',    filters.dateTo)
  return p.toString()
}

async function generateReport(key: string) {
  activeTab.value = key
  const qs  = buildQs()
  const url = `/assets/reports/generate/${key}${qs ? `?${qs}` : ''}`
  try {
    const res = await fetch(url, { headers: { Accept: 'application/json' } })
    if (!res.ok) throw new Error()
    const data = await res.json()
    setToast(t('assets_reports_engine.toasts.generated', { count: data.count, key }))
  } catch {
    setToast(t('assets_reports_engine.toasts.generated_error', { key }), 'error')
  }
}

function exportReport(key: string, format: 'excel' | 'pdf') {
  const qs  = buildQs()
  const fmt = format === 'excel' ? 'xlsx' : 'pdf'
  window.open(`/assets/reports/export/${key}/${fmt}${qs ? `?${qs}` : ''}`, '_blank')
  setToast(t('assets_reports_engine.toasts.export_started', { format: format.toUpperCase(), key }))
}

function clearFilters() {
  filters.category = null
  filters.location  = null
  filters.status    = null
  filters.dateFrom  = null
  filters.dateTo    = null
}

const severityColor = (v: unknown) =>
  v === 'critical' ? 'error' : v === 'high' ? 'warning' : v === 'medium' ? 'info' : 'default'

const statusColor = (v: unknown) => {
  const s = String(v ?? '').toLowerCase()
  if (['resolved','returned','active','cleared','completed'].includes(s)) return 'success'
  if (['open','overdue','pending'].includes(s)) return 'warning'
  if (['cancelled','inactive','disposed'].includes(s)) return 'error'
  return 'default'
}
</script>

<template>
  <VRow>
    <VCol cols="12">
      <VCard class="reports-hero">
        <VCardText class="d-flex flex-wrap align-center justify-space-between gap-4 py-6">
          <div>
            <div class="text-overline text-white opacity-90">{{ t('assets_reports_engine.hero.subtitle') }}</div>
            <h4 class="text-h4 text-white mb-1">{{ t('assets_reports_engine.hero.title') }}</h4>
            <p class="mb-0 text-white opacity-90">{{ t('assets_reports_engine.hero.description') }}</p>
          </div>
          <div class="d-flex flex-column align-end gap-2">
            <VChip color="white" class="text-secondary font-weight-semibold" label>
              {{ activeRows.length }} {{ t('assets_reports_engine.hero.active_rows') }}
            </VChip>
            <VBtn variant="outlined" color="white" size="small" prepend-icon="tabler-chart-bar" href="/assets/analytics">
              {{ t('assets_reports_engine.hero.bi_analytics') }}
            </VBtn>
          </div>
        </VCardText>
      </VCard>
    </VCol>

    <VCol cols="12">
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('assets_reports_engine.filters.title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow>
            <VCol cols="12" sm="6" md="2">
              <VSelect v-model="filters.category" :items="categories" :label="t('assets_reports_engine.filters.category')" clearable density="compact" />
            </VCol>
            <VCol cols="12" sm="6" md="2">
              <VSelect v-model="filters.location" :items="locations" :label="t('assets_reports_engine.filters.location')" clearable density="compact" />
            </VCol>
            <VCol cols="12" sm="6" md="2">
              <VSelect v-model="filters.status" :items="statuses" :label="t('assets_reports_engine.filters.status')" clearable density="compact" />
            </VCol>
            <VCol cols="12" sm="6" md="3">
              <VTextField v-model="filters.dateFrom" type="date" :label="t('assets_reports_engine.filters.from')" density="compact" />
            </VCol>
            <VCol cols="12" sm="6" md="3">
              <VTextField v-model="filters.dateTo" type="date" :label="t('assets_reports_engine.filters.to')" density="compact" />
            </VCol>
          </VRow>
          <div class="d-flex justify-end mt-2">
            <VBtn variant="tonal" color="secondary" prepend-icon="tabler-filter-off" size="small" @click="clearFilters">
              {{ t('assets_reports_engine.filters.clear') }}
            </VBtn>
          </div>
        </VCardText>
      </VCard>
    </VCol>

    <VCol cols="12" lg="8">
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('assets_reports_engine.cards.title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow>
            <VCol v-for="r in reportCards" :key="r.key" cols="12" md="6">
              <VCard variant="outlined" class="h-100">
                <VCardText>
                  <div class="d-flex align-center gap-2 mb-2">
                    <VIcon :icon="r.icon" size="20" color="primary" />
                    <span class="text-subtitle-2 font-weight-semibold">{{ r.title }}</span>
                  </div>
                  <p class="text-body-2 text-medium-emphasis mb-4">{{ r.description }}</p>
                  <div class="d-flex flex-wrap gap-2">
                    <VBtn size="small" color="primary" prepend-icon="tabler-player-play" @click="generateReport(r.key)">
                      {{ t('assets_reports_engine.actions.generate') }}
                    </VBtn>
                    <VBtn size="small" variant="tonal" prepend-icon="tabler-table" @click="activeTab = r.key">
                      {{ t('assets_reports_engine.actions.open_table') }}
                    </VBtn>
                    <VMenu>
                      <template #activator="{ props: mp }">
                        <VBtn v-bind="mp" size="small" color="secondary" variant="tonal" prepend-icon="tabler-upload">
                          {{ t('assets_reports_engine.actions.export') }}
                        </VBtn>
                      </template>
                      <VList>
                        <VListItem :title="t('assets_reports_engine.actions.export_excel')" prepend-icon="tabler-file-type-xls" @click="exportReport(r.key, 'excel')" />
                        <VListItem :title="t('assets_reports_engine.actions.export_pdf')" prepend-icon="tabler-file-type-pdf" @click="exportReport(r.key, 'pdf')" />
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

    <VCol cols="12" lg="4">
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('assets_reports_engine.scheduled.title') }}</VCardTitle>
        <VDivider />
        <VDataTable
          :headers="[
            { title: t('assets_reports_engine.scheduled.cadence'), key: 'cadence' },
            { title: t('assets_reports_engine.scheduled.report'), key: 'title' },
            { title: t('assets_reports_engine.scheduled.recipients'), key: 'recipients' },
            { title: t('assets_reports_engine.scheduled.next_run'), key: 'nextRun' },
          ]"
          :items="scheduledReports"
          :items-per-page="5"
          density="compact"
          hide-default-footer
        />
      </VCard>
    </VCol>

    <VCol cols="12">
      <VCard>
        <VTabs v-model="activeTab" show-arrows>
          <VTab v-for="r in reportCards" :key="r.key" :value="r.key">{{ r.title }}</VTab>
        </VTabs>
        <VDivider />

        <VCardText class="d-flex gap-2 pa-4 pb-0">
          <VBtn color="primary" size="small" prepend-icon="tabler-player-play" @click="generateReport(activeTab)">
            {{ t('assets_reports_engine.table.generate') }}
          </VBtn>
          <VBtn color="success" variant="tonal" size="small" prepend-icon="tabler-file-type-csv" @click="exportReport(activeTab, 'excel')">
            {{ t('assets_reports_engine.table.export_excel') }}
          </VBtn>
          <VBtn color="error" variant="tonal" size="small" prepend-icon="tabler-file-type-pdf" @click="exportReport(activeTab, 'pdf')">
            {{ t('assets_reports_engine.table.export_pdf') }}
          </VBtn>
        </VCardText>

        <VDataTable :headers="activeHeaders" :items="activeRows" :items-per-page="15" height="600" fixed-header class="mt-2">
          <template #item.status="{ item }">
            <VChip size="small" variant="tonal" :color="statusColor(item.status)" class="font-weight-medium">{{ item.status ?? '—' }}</VChip>
          </template>
          <template #item.asset_status="{ item }">
            <VChip size="small" variant="tonal" :color="statusColor(item.asset_status)" class="font-weight-medium">{{ item.asset_status ?? '—' }}</VChip>
          </template>
          <template #item.severity="{ item }">
            <VChip size="small" variant="tonal" :color="severityColor(item.severity)" class="font-weight-medium">{{ item.severity ?? '—' }}</VChip>
          </template>
          <template #item.condition="{ item }">
            <VChip size="small" variant="tonal" :color="item.condition === 'new' ? 'success' : item.condition === 'poor' || item.condition === 'damaged' ? 'error' : 'default'" class="font-weight-medium">{{ item.condition ?? '—' }}</VChip>
          </template>
          <template #item.asset_number="{ item }">
            <span class="text-body-2 font-weight-semibold">{{ item.asset_number }}</span>
          </template>
          <template #item.returned_at="{ item }">
            <span :class="item.returned_at ? '' : 'text-medium-emphasis'">{{ item.returned_at ?? '—' }}</span>
          </template>
          <template #item.resolved_at="{ item }">
            <span :class="item.resolved_at ? '' : 'text-medium-emphasis'">{{ item.resolved_at ?? '—' }}</span>
          </template>
          <template #item.cleared_at="{ item }">
            <span :class="item.cleared_at ? '' : 'text-medium-emphasis'">{{ item.cleared_at ?? '—' }}</span>
          </template>
        </VDataTable>
      </VCard>
    </VCol>

    <VSnackbar v-model="toast.show" :color="toast.color" timeout="2500" location="top right">
      {{ toast.text }}
    </VSnackbar>
  </VRow>
</template>

<style scoped lang="scss">
.reports-hero {
  border: 0;
  overflow: hidden;
  background: linear-gradient(135deg, #1a3a5c 0%, #2d6a9f 100%);
}
:deep(.v-data-table-header__content) { font-weight: 700; }
</style>
