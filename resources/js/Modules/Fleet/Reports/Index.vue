<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface KpiData {
  total_vehicles: number
  utilisation_rate: number
  cost_per_km: number
  avg_booking_days: number
  avg_fuel_efficiency: number
  total_fuel_cost: number
  total_maint_cost: number
}
interface ExpiryRadar {
  docs_30: number; docs_60: number; docs_90: number
  lic_30: number;  lic_60: number;  lic_90: number
  maint_due: number
}
interface MonthlyFuel { month: string; cost: number; litres: number }
interface TopVehicle  { registration_number: string; make: string; model: string; total_cost: number }
interface TopDriver   { driver_name: string; total_cost: number }
interface AnomalyRow  { severity: string; count: number; unreviewed: number }
interface SelectOption { value: number; title: string }
interface Filters {
  vehicle_id?: number | null
  project_id?: number | null
  driver_id?: number | null
  date_from?: string | null
  date_to?: string | null
}

const props = defineProps<{
  kpis:            KpiData
  expiryRadar:     ExpiryRadar
  monthlyFuel:     MonthlyFuel[]
  topCostVehicles: TopVehicle[]
  topCostDrivers:  TopDriver[]
  anomalySummary:  AnomalyRow[]
  reportKeys:      Record<string, string>
  vehicles:        SelectOption[]
  projects:        SelectOption[]
  drivers:         SelectOption[]
  filters:         Filters
  projectExpenses: Record<string, any>[]
  generalExpenses: Record<string, any>[]
}>()

// ── State ─────────────────────────────────────────────────────────────────────
const activeTab       = ref('dashboard')
const activeExpTab    = ref('project_expenses')
const exportLoading   = ref<string | null>(null)
const snack           = ref({ show: false, text: '', color: 'success' })
const filterOpen      = ref(false)

const fVehicle  = ref<number | null>(props.filters.vehicle_id ?? null)
const fProject  = ref<number | null>(props.filters.project_id ?? null)
const fDriver   = ref<number | null>(props.filters.driver_id ?? null)
const fDateFrom = ref<string>(props.filters.date_from ?? '')
const fDateTo   = ref<string>(props.filters.date_to ?? '')

// ── Report catalogue ──────────────────────────────────────────────────────────
const reportCatalogue = [
  { key: 'vehicle_details',     icon: 'tabler-car',          color: 'blue',   desc: 'Registration, make, model, VIN, odometer and purchase price for all active vehicles.' },
  { key: 'vehicle_expenditure', icon: 'tabler-cash',     color: 'orange', desc: 'Total fuel and maintenance spend per vehicle.' },
  { key: 'booking_utilisation', icon: 'tabler-chart-bar',         color: 'teal',   desc: 'Trip count, total KM and average duration per vehicle.' },
  { key: 'collection_return',   icon: 'tabler-car-suv',   color: 'indigo', desc: 'Collection and return records with odometer readings.' },
  { key: 'carbon_emissions',    icon: 'tabler-leaf',              color: 'green',  desc: 'Estimated CO₂e emissions based on fuel consumed per vehicle.' },
  { key: 'driver_scorecard',    icon: 'tabler-user-tie',       color: 'green',  desc: 'Driver trips, total KM, incidents and performance rating.' },
  { key: 'licence_expiry',      icon: 'tabler-id-badge-2', color: 'deep-orange', desc: 'Driver licences and PDP expiring within 90 days.' },
  { key: 'fuel_consumption',    icon: 'tabler-gas-station',       color: 'amber',  desc: 'Fuel fill-ups with litres, cost per litre and L/100km.' },
  { key: 'oil_consumption',     icon: 'tabler-droplet',               color: 'brown',  desc: 'Oil change records from maintenance and fleet card transactions.' },
  { key: 'fleet_card',          icon: 'tabler-credit-card',       color: 'blue-grey', desc: 'All fleet card transactions including flagged entries.' },
  { key: 'tollgates',           icon: 'tabler-road',              color: 'cyan',   desc: 'Tollgate charges per booking with plaza name and date.' },
  { key: 'fines',               icon: 'tabler-alert-octagon',     color: 'red',    desc: 'Flagged transactions and traffic fines linked to vehicles.' },
  { key: 'incident_report',     icon: 'tabler-ambulance',     color: 'deep-orange', desc: 'All incidents by vehicle with severity, location and case numbers.' },
  { key: 'service_records',     icon: 'tabler-tool',            color: 'purple', desc: 'Maintenance history including cost, provider and service type.' },
  { key: 'document_expiry',     icon: 'tabler-file-exclamation',        color: 'warning', desc: 'Vehicle documents expiring within 90 days.' },
  { key: 'insurance',           icon: 'tabler-shield-check',      color: 'purple', desc: 'Insurance policy numbers, insurers and expiry dates.' },
  { key: 'warranty',            icon: 'tabler-certificate',       color: 'teal',   desc: 'Estimated warranty expiry dates based on purchase date.' },
  { key: 'fire_extinguisher',   icon: 'tabler-fire-extinguisher', color: 'red',    desc: 'Fire extinguisher inspection results from booking checks.' },
]

// ── Expense report columns ─────────────────────────────────────────────────────
const projExpHeaders = computed(() => {
  if (!props.projectExpenses.length) return []
  return Object.keys(props.projectExpenses[0])
})
const genExpHeaders = computed(() => {
  if (!props.generalExpenses.length) return []
  return Object.keys(props.generalExpenses[0])
})

const projExpTotals = computed(() => {
  const numericCols = ['Fuel Cost', 'Fine Cost', 'General Cost', 'Toll Cost', 'Total Cost']
  const totals: Record<string, any> = {}
  if (!projExpHeaders.value.length) return totals
  for (const h of projExpHeaders.value) {
    if (numericCols.includes(h)) {
      totals[h] = 'R ' + props.projectExpenses.reduce((s, r) => s + (parseFloat(r[h]) || 0), 0)
        .toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    } else {
      totals[h] = h === 'Booking Ref' ? 'TOTAL' : ''
    }
  }
  return totals
})

const genExpTotals = computed(() => {
  const totals: Record<string, any> = {}
  if (!genExpHeaders.value.length) return totals
  for (const h of genExpHeaders.value) {
    if (h === 'Amount') {
      totals[h] = 'R ' + props.generalExpenses.reduce((s, r) => s + (parseFloat(r[h]) || 0), 0)
        .toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    } else {
      totals[h] = h === 'Category' ? 'TOTAL' : ''
    }
  }
  return totals
})

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmt(n: number | null | undefined, dec = 0): string {
  if (n == null) return '—'
  return n.toLocaleString('en-ZA', { minimumFractionDigits: dec, maximumFractionDigits: dec })
}
function fmtR(n: number | null | undefined): string {
  if (n == null) return '—'
  return 'R ' + n.toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
function fmtCell(v: any): string {
  if (v == null || v === '') return '—'
  return String(v)
}

const maxMonthlyFuel = computed(() => Math.max(...props.monthlyFuel.map(m => m.cost), 1))
const severityColor = (s: string) => ({ low: 'info', medium: 'warning', high: 'error', critical: 'deep-orange' }[s] ?? 'default')

const hasFilters = computed(() => !!(fVehicle.value || fProject.value || fDriver.value || fDateFrom.value || fDateTo.value))

// ── Filter apply ──────────────────────────────────────────────────────────────
function applyFilters() {
  router.get('/fleet/reports', {
    vehicle_id: fVehicle.value || undefined,
    project_id: fProject.value || undefined,
    driver_id:  fDriver.value  || undefined,
    date_from:  fDateFrom.value || undefined,
    date_to:    fDateTo.value   || undefined,
  }, { preserveState: false, replace: true })
}

function clearFilters() {
  fVehicle.value  = null
  fProject.value  = null
  fDriver.value   = null
  fDateFrom.value = ''
  fDateTo.value   = ''
  router.get('/fleet/reports', {}, { preserveState: false, replace: true })
}

// ── Export ────────────────────────────────────────────────────────────────────
async function doExport(key: string, format: 'pdf' | 'excel' | 'csv') {
  exportLoading.value = `${key}-${format}`
  try {
    const params = new URLSearchParams()
    if (fVehicle.value)  params.set('vehicle_id', String(fVehicle.value))
    if (fProject.value)  params.set('project_id', String(fProject.value))
    if (fDriver.value)   params.set('driver_id',  String(fDriver.value))
    if (fDateFrom.value) params.set('date_from',  fDateFrom.value)
    if (fDateTo.value)   params.set('date_to',    fDateTo.value)
    const qs  = params.toString() ? '?' + params.toString() : ''
    const url = `/fleet/reports/export/${key}/${format}${qs}`
    const res = await fetch(url)
    if (!res.ok) throw new Error('Export failed')
    const blob = await res.blob()
    const a    = document.createElement('a')
    a.href     = URL.createObjectURL(blob)
    a.download = `fleet-${key}-${new Date().toISOString().slice(0, 10)}.${format === 'excel' ? 'xlsx' : format}`
    a.click()
    URL.revokeObjectURL(a.href)
  } catch {
    snack.value = { show: true, text: 'Export failed.', color: 'error' }
  } finally {
    exportLoading.value = null
  }
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow justify="center">
      <VCol cols="12" xl="11">

        <!-- Ribbon header -->
        <VCard class="mb-5 bg-primary">
          <VCardText class="d-flex align-center justify-space-between flex-wrap gap-3 pa-5">
            <div>
              <div class="text-overline text-white opacity-75">{{ t('fleet.title') }}</div>
              <h4 class="text-h4 text-white font-weight-bold">{{ t('fleet.reports_title') }}</h4>
              <p class="text-white opacity-75 mb-0 mt-1">{{ t('fleet.reports_subtitle') }}</p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
              <VBtn variant="outlined" color="white" prepend-icon="tabler-calendar-clock" href="/fleet/reports/scheduled" size="small">
                {{ t('fleet.scheduled_reports') }}
              </VBtn>
              <VBtn variant="outlined" color="white" prepend-icon="tabler-chart-bar" href="/fleet/analytics" size="small">
                {{ t('fleet.bi_analytics') }}
              </VBtn>
            </div>
          </VCardText>

          <!-- Summary chips -->
          <VDivider class="opacity-25" />
          <VCardText class="d-flex gap-4 flex-wrap pa-4 pt-3">
            <div class="text-center">
              <div class="text-h6 text-white font-weight-bold">{{ fmt(kpis.total_vehicles) }}</div>
              <div class="text-caption text-white opacity-75">{{ t('fleet.vehicles') }}</div>
            </div>
            <VDivider vertical class="opacity-25" />
            <div class="text-center">
              <div class="text-h6 text-white font-weight-bold">{{ fmt(kpis.utilisation_rate, 1) }}%</div>
              <div class="text-caption text-white opacity-75">{{ t('fleet.utilisation') }}</div>
            </div>
            <VDivider vertical class="opacity-25" />
            <div class="text-center">
              <div class="text-h6 text-white font-weight-bold">{{ fmtR(kpis.total_fuel_cost) }}</div>
              <div class="text-caption text-white opacity-75">{{ t('fleet.total_fuel') }}</div>
            </div>
            <VDivider vertical class="opacity-25" />
            <div class="text-center">
              <div class="text-h6 text-white font-weight-bold">{{ fmtR(kpis.total_maint_cost) }}</div>
              <div class="text-caption text-white opacity-75">{{ t('fleet.maintenance') }}</div>
            </div>
            <VDivider vertical class="opacity-25" />
            <div class="text-center">
              <div class="text-h6 text-white font-weight-bold">{{ fmtR(kpis.cost_per_km) }}</div>
              <div class="text-caption text-white opacity-75">{{ t('fleet.cost_per_km') }}</div>
            </div>
          </VCardText>
        </VCard>

        <!-- Filter panel -->
        <VCard class="mb-4" variant="outlined">
          <VCardText class="pa-3">
            <div class="d-flex align-center gap-2 mb-2 cursor-pointer" @click="filterOpen = !filterOpen">
              <VIcon :icon="filterOpen ? 'tabler-filter-off' : 'tabler-filter'" size="20" />
              <span class="text-body-2 font-weight-medium">{{ t('fleet.filters') }}</span>
              <VChip v-if="hasFilters" size="x-small" color="primary" class="ms-1">Active</VChip>
              <VSpacer />
              <VIcon :icon="filterOpen ? 'tabler-chevron-up' : 'tabler-chevron-down'" size="18" />
            </div>
            <VExpandTransition>
              <div v-show="filterOpen">
                <VRow dense class="mt-1">
                  <VCol cols="12" sm="6" md="3">
                    <VAutocomplete
                      v-model="fVehicle"
                      :items="vehicles"
                      item-value="value"
                      item-title="title"
                      label="Vehicle"
                      clearable
                      density="compact"
                      variant="outlined"
                      hide-details
                    />
                  </VCol>
                  <VCol cols="12" sm="6" md="3">
                    <VAutocomplete
                      v-model="fProject"
                      :items="projects"
                      item-value="value"
                      item-title="title"
                      label="Project"
                      clearable
                      density="compact"
                      variant="outlined"
                      hide-details
                    />
                  </VCol>
                  <VCol cols="12" sm="6" md="2">
                    <VAutocomplete
                      v-model="fDriver"
                      :items="drivers"
                      item-value="value"
                      item-title="title"
                      label="Driver"
                      clearable
                      density="compact"
                      variant="outlined"
                      hide-details
                    />
                  </VCol>
                  <VCol cols="12" sm="6" md="2">
                    <VTextField
                      v-model="fDateFrom"
                      label="Date From"
                      type="date"
                      density="compact"
                      variant="outlined"
                      hide-details
                    />
                  </VCol>
                  <VCol cols="12" sm="6" md="2">
                    <VTextField
                      v-model="fDateTo"
                      label="Date To"
                      type="date"
                      density="compact"
                      variant="outlined"
                      hide-details
                    />
                  </VCol>
                </VRow>
                <div class="d-flex gap-2 mt-3">
                  <VBtn size="small" color="primary" prepend-icon="tabler-search" @click="applyFilters">{{ t('fleet.apply_filters') }}</VBtn>
                  <VBtn size="small" variant="tonal" prepend-icon="tabler-x" @click="clearFilters">{{ t('fleet.clear') }}</VBtn>
                </div>
              </div>
            </VExpandTransition>
          </VCardText>
        </VCard>

        <!-- Tabs -->
        <VTabs v-model="activeTab" class="mb-4">
          <VTab value="dashboard">
            <VIcon start icon="tabler-layout-dashboard" />
            {{ t('fleet.dashboard') }}
          </VTab>
          <VTab value="expenses">
            <VIcon start icon="tabler-cash-register" />
            {{ t('fleet.expense_reports') }}
          </VTab>
          <VTab value="reports">
            <VIcon start icon="tabler-file-analytics" />
            {{ t('fleet.standard_reports') }}
          </VTab>
        </VTabs>

        <VTabsWindow v-model="activeTab">

          <!-- ── DASHBOARD TAB ─────────────────────────────────────────────── -->
          <VTabsWindowItem value="dashboard">
            <VRow class="mb-4">
              <VCol cols="6" sm="4" md="3" lg="2">
                <VCard class="text-center pa-3" color="blue-lighten-5">
                  <VIcon icon="tabler-cars" color="blue" size="28" class="mb-1" />
                  <div class="text-h5 font-weight-bold">{{ fmt(kpis.total_vehicles) }}</div>
                  <div class="text-caption text-medium-emphasis">Vehicles</div>
                </VCard>
              </VCol>
              <VCol cols="6" sm="4" md="3" lg="2">
                <VCard class="text-center pa-3" color="green-lighten-5">
                  <VIcon icon="tabler-chart-pie" color="green" size="28" class="mb-1" />
                  <div class="text-h5 font-weight-bold">{{ fmt(kpis.utilisation_rate, 1) }}%</div>
                  <div class="text-caption text-medium-emphasis">Utilisation</div>
                </VCard>
              </VCol>
              <VCol cols="6" sm="4" md="3" lg="2">
                <VCard class="text-center pa-3" color="orange-lighten-5">
                  <VIcon icon="tabler-currency-dollar" color="orange" size="28" class="mb-1" />
                  <div class="text-h5 font-weight-bold">{{ fmtR(kpis.cost_per_km) }}</div>
                  <div class="text-caption text-medium-emphasis">Cost / KM</div>
                </VCard>
              </VCol>
              <VCol cols="6" sm="4" md="3" lg="2">
                <VCard class="text-center pa-3" color="purple-lighten-5">
                  <VIcon icon="tabler-calendar-stats" color="purple" size="28" class="mb-1" />
                  <div class="text-h5 font-weight-bold">{{ fmt(kpis.avg_booking_days, 1) }}</div>
                  <div class="text-caption text-medium-emphasis">Avg Trip (days)</div>
                </VCard>
              </VCol>
              <VCol cols="6" sm="4" md="3" lg="2">
                <VCard class="text-center pa-3" color="teal-lighten-5">
                  <VIcon icon="tabler-gas-station" color="teal" size="28" class="mb-1" />
                  <div class="text-h5 font-weight-bold">{{ fmt(kpis.avg_fuel_efficiency, 1) }}</div>
                  <div class="text-caption text-medium-emphasis">Avg L/100km</div>
                </VCard>
              </VCol>
              <VCol cols="6" sm="4" md="3" lg="2">
                <VCard class="text-center pa-3" color="red-lighten-5">
                  <VIcon icon="tabler-tool" color="red" size="28" class="mb-1" />
                  <div class="text-h5 font-weight-bold">{{ fmtR(kpis.total_maint_cost) }}</div>
                  <div class="text-caption text-medium-emphasis">Maintenance</div>
                </VCard>
              </VCol>
            </VRow>

            <VRow>
              <VCol cols="12" md="7">
                <VCard>
                  <VCardTitle class="pa-4 pb-2">{{ t('fleet.monthly_fuel_spend') }}</VCardTitle>
                  <VCardText>
                    <div v-if="!monthlyFuel.length" class="text-center text-medium-emphasis py-6">No fuel data yet.</div>
                    <div v-else class="d-flex align-end gap-1" style="height:140px;overflow-x:auto;">
                      <div
                        v-for="m in monthlyFuel"
                        :key="m.month"
                        class="d-flex flex-column align-center flex-shrink-0"
                        style="min-width:48px;"
                      >
                        <div class="text-caption text-medium-emphasis mb-1">R{{ fmt(m.cost / 1000, 1) }}k</div>
                        <div
                          class="rounded-t bg-blue"
                          style="width:32px;transition:height .3s"
                          :style="{ height: Math.max(4, (m.cost / maxMonthlyFuel) * 100) + 'px' }"
                          :title="`${m.month}: R${fmt(m.cost, 2)}`"
                        />
                        <div class="text-caption mt-1" style="font-size:9px;white-space:nowrap;">{{ m.month }}</div>
                      </div>
                    </div>
                  </VCardText>
                </VCard>
              </VCol>

              <VCol cols="12" md="5">
                <VCard>
                  <VCardTitle class="pa-4 pb-2">{{ t('fleet.expiry_radar') }}</VCardTitle>
                  <VCardText>
                    <VRow dense>
                      <VCol cols="12">
                        <div class="text-overline text-medium-emphasis mb-1">{{ t('fleet.documents') }}</div>
                      </VCol>
                      <VCol cols="4" class="text-center">
                        <div class="text-h6 font-weight-bold text-error">{{ expiryRadar.docs_30 }}</div>
                        <div class="text-caption">≤30 days</div>
                      </VCol>
                      <VCol cols="4" class="text-center">
                        <div class="text-h6 font-weight-bold text-warning">{{ expiryRadar.docs_60 }}</div>
                        <div class="text-caption">≤60 days</div>
                      </VCol>
                      <VCol cols="4" class="text-center">
                        <div class="text-h6 font-weight-bold text-info">{{ expiryRadar.docs_90 }}</div>
                        <div class="text-caption">≤90 days</div>
                      </VCol>
                      <VCol cols="12" class="mt-2">
                        <div class="text-overline text-medium-emphasis mb-1">{{ t('fleet.driver_licences') }}</div>
                      </VCol>
                      <VCol cols="4" class="text-center">
                        <div class="text-h6 font-weight-bold text-error">{{ expiryRadar.lic_30 }}</div>
                        <div class="text-caption">≤30 days</div>
                      </VCol>
                      <VCol cols="4" class="text-center">
                        <div class="text-h6 font-weight-bold text-warning">{{ expiryRadar.lic_60 }}</div>
                        <div class="text-caption">≤60 days</div>
                      </VCol>
                      <VCol cols="4" class="text-center">
                        <div class="text-h6 font-weight-bold text-info">{{ expiryRadar.lic_90 }}</div>
                        <div class="text-caption">≤90 days</div>
                      </VCol>
                      <VCol cols="12" class="mt-3">
                        <VAlert
                          v-if="expiryRadar.maint_due > 0"
                          type="warning"
                          density="compact"
                          variant="tonal"
                          :text="`${expiryRadar.maint_due} maintenance job(s) due within 30 days.`"
                        />
                      </VCol>
                    </VRow>
                  </VCardText>
                </VCard>
              </VCol>

              <VCol cols="12" md="6">
                <VCard>
                  <VCardTitle class="pa-4 pb-2">{{ t('fleet.top_cost_vehicles') }}</VCardTitle>
                  <VCardText class="pa-0">
                    <VList lines="one" density="compact">
                      <VListItem v-for="(v, i) in topCostVehicles" :key="v.registration_number" :subtitle="v.make + ' ' + v.model">
                        <template #prepend>
                          <VAvatar color="blue-lighten-4" size="32" class="mr-2 text-caption font-weight-bold">{{ i + 1 }}</VAvatar>
                        </template>
                        <template #title>{{ v.registration_number }}</template>
                        <template #append>
                          <span class="text-caption font-weight-medium">{{ fmtR(v.total_cost) }}</span>
                        </template>
                      </VListItem>
                      <VListItem v-if="!topCostVehicles.length">
                        <VListItemTitle class="text-medium-emphasis">No data yet.</VListItemTitle>
                      </VListItem>
                    </VList>
                  </VCardText>
                </VCard>
              </VCol>

              <VCol cols="12" md="6">
                <VCard>
                  <VCardTitle class="pa-4 pb-2">{{ t('fleet.top_fuel_cost_drivers') }}</VCardTitle>
                  <VCardText class="pa-0">
                    <VList lines="one" density="compact">
                      <VListItem v-for="(d, i) in topCostDrivers" :key="d.driver_name">
                        <template #prepend>
                          <VAvatar color="green-lighten-4" size="32" class="mr-2 text-caption font-weight-bold">{{ i + 1 }}</VAvatar>
                        </template>
                        <template #title>{{ d.driver_name || 'Unknown' }}</template>
                        <template #append>
                          <span class="text-caption font-weight-medium">{{ fmtR(d.total_cost) }}</span>
                        </template>
                      </VListItem>
                      <VListItem v-if="!topCostDrivers.length">
                        <VListItemTitle class="text-medium-emphasis">No data yet.</VListItemTitle>
                      </VListItem>
                    </VList>
                  </VCardText>
                </VCard>
              </VCol>

              <VCol v-if="anomalySummary.length" cols="12">
                <VCard>
                  <VCardTitle class="pa-4 pb-2">{{ t('fleet.fuel_anomaly_summary') }}</VCardTitle>
                  <VCardText>
                    <div class="d-flex gap-3 flex-wrap">
                      <VChip v-for="a in anomalySummary" :key="a.severity" :color="severityColor(a.severity)" label>
                        {{ a.severity }}: {{ a.count }} ({{ a.unreviewed }} unreviewed)
                      </VChip>
                    </div>
                  </VCardText>
                </VCard>
              </VCol>
            </VRow>
          </VTabsWindowItem>

          <!-- ── EXPENSE REPORTS TAB ────────────────────────────────────────── -->
          <VTabsWindowItem value="expenses">
            <VTabs v-model="activeExpTab" density="compact" class="mb-4">
              <VTab value="project_expenses">
                <VIcon start icon="tabler-folders" />
                {{ t('fleet.project_expenses') }}
              </VTab>
              <VTab value="general_expenses">
                <VIcon start icon="tabler-receipt" />
                {{ t('fleet.general_expenses') }}
              </VTab>
            </VTabs>

            <VTabsWindow v-model="activeExpTab">

              <!-- Project Expenses -->
              <VTabsWindowItem value="project_expenses">
                <VCard>
                  <VCardTitle class="d-flex align-center justify-space-between pa-4 pb-3 flex-wrap gap-2">
                    <div>
                      <div class="text-h6">{{ t('fleet.project_expenses_report') }}</div>
                      <div class="text-caption text-medium-emphasis">Expenses captured during vehicle return, grouped by project. Includes fuel, fines, general costs, incidents and tollgates.</div>
                    </div>
                    <div class="d-flex gap-2">
                      <VBtn size="small" color="red-darken-1" variant="tonal" prepend-icon="tabler-file-type-pdf"
                        :loading="exportLoading === 'project_expenses-pdf'"
                        @click="doExport('project_expenses', 'pdf')">PDF</VBtn>
                      <VBtn size="small" color="green-darken-1" variant="tonal" prepend-icon="tabler-file-type-xls"
                        :loading="exportLoading === 'project_expenses-excel'"
                        @click="doExport('project_expenses', 'excel')">Excel</VBtn>
                      <VBtn size="small" variant="tonal" prepend-icon="tabler-file-type-csv"
                        :loading="exportLoading === 'project_expenses-csv'"
                        @click="doExport('project_expenses', 'csv')">CSV</VBtn>
                    </div>
                  </VCardTitle>
                  <VDivider />
                  <div v-if="!projectExpenses.length" class="text-center text-medium-emphasis py-10">
                    <VIcon icon="tabler-folder-open" size="48" class="mb-2 d-block" />
                    No project expense data found.
                    <div class="text-caption mt-1">Expenses are recorded when a vehicle is returned from a booking.</div>
                  </div>
                  <div v-else class="overflow-x-auto">
                    <table class="expense-table">
                      <thead>
                        <tr>
                          <th v-for="h in projExpHeaders" :key="h">{{ h }}</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-for="(row, i) in projectExpenses" :key="i">
                          <td v-for="h in projExpHeaders" :key="h">{{ fmtCell(row[h]) }}</td>
                        </tr>
                      </tbody>
                      <tfoot>
                        <tr class="totals-row">
                          <td v-for="h in projExpHeaders" :key="h">{{ projExpTotals[h] }}</td>
                        </tr>
                      </tfoot>
                    </table>
                  </div>
                </VCard>
              </VTabsWindowItem>

              <!-- General Expenses -->
              <VTabsWindowItem value="general_expenses">
                <VCard>
                  <VCardTitle class="d-flex align-center justify-space-between pa-4 pb-3 flex-wrap gap-2">
                    <div>
                      <div class="text-h6">{{ t('fleet.general_expenses_report') }}</div>
                      <div class="text-caption text-medium-emphasis">All fleet expenses across all vehicles — fuel, fines, general costs, tollgates and maintenance. Includes both project and non-project transactions.</div>
                    </div>
                    <div class="d-flex gap-2">
                      <VBtn size="small" color="red-darken-1" variant="tonal" prepend-icon="tabler-file-type-pdf"
                        :loading="exportLoading === 'general_expenses-pdf'"
                        @click="doExport('general_expenses', 'pdf')">PDF</VBtn>
                      <VBtn size="small" color="green-darken-1" variant="tonal" prepend-icon="tabler-file-type-xls"
                        :loading="exportLoading === 'general_expenses-excel'"
                        @click="doExport('general_expenses', 'excel')">Excel</VBtn>
                      <VBtn size="small" variant="tonal" prepend-icon="tabler-file-type-csv"
                        :loading="exportLoading === 'general_expenses-csv'"
                        @click="doExport('general_expenses', 'csv')">CSV</VBtn>
                    </div>
                  </VCardTitle>
                  <VDivider />
                  <div v-if="!generalExpenses.length" class="text-center text-medium-emphasis py-10">
                    <VIcon icon="tabler-receipt" size="48" class="mb-2 d-block" />
                    No expense data found for the selected filters.
                  </div>
                  <div v-else class="overflow-x-auto">
                    <table class="expense-table">
                      <thead>
                        <tr>
                          <th v-for="h in genExpHeaders" :key="h">{{ h }}</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-for="(row, i) in generalExpenses" :key="i">
                          <td v-for="h in genExpHeaders" :key="h">{{ fmtCell(row[h]) }}</td>
                        </tr>
                      </tbody>
                      <tfoot>
                        <tr class="totals-row">
                          <td v-for="h in genExpHeaders" :key="h">{{ genExpTotals[h] }}</td>
                        </tr>
                      </tfoot>
                    </table>
                  </div>
                </VCard>
              </VTabsWindowItem>

            </VTabsWindow>
          </VTabsWindowItem>

          <!-- ── STANDARD REPORTS TAB ────────────────────────────────────────── -->
          <VTabsWindowItem value="reports">
            <VRow>
              <VCol
                v-for="report in reportCatalogue"
                :key="report.key"
                cols="12"
                sm="6"
                md="4"
                lg="3"
              >
                <VCard class="h-100 d-flex flex-column">
                  <VCardText class="flex-grow-1 pa-4">
                    <div class="d-flex align-start gap-3 mb-3">
                      <VAvatar :color="`${report.color}-lighten-4`" size="40" rounded>
                        <VIcon :icon="report.icon" :color="report.color" size="22" />
                      </VAvatar>
                      <div>
                        <div class="text-body-2 font-weight-semibold">{{ reportKeys[report.key] }}</div>
                        <div class="text-caption text-medium-emphasis mt-1">{{ report.desc }}</div>
                      </div>
                    </div>
                  </VCardText>
                  <VDivider />
                  <VCardActions class="pa-3 pt-2">
                    <VBtn
                      size="x-small"
                      color="red-darken-1"
                      variant="tonal"
                      :loading="exportLoading === `${report.key}-pdf`"
                      @click="doExport(report.key, 'pdf')"
                    >PDF</VBtn>
                    <VBtn
                      size="x-small"
                      color="green-darken-1"
                      variant="tonal"
                      :loading="exportLoading === `${report.key}-excel`"
                      @click="doExport(report.key, 'excel')"
                    >Excel</VBtn>
                    <VBtn
                      size="x-small"
                      variant="tonal"
                      :loading="exportLoading === `${report.key}-csv`"
                      @click="doExport(report.key, 'csv')"
                    >CSV</VBtn>
                  </VCardActions>
                </VCard>
              </VCol>
            </VRow>
          </VTabsWindowItem>

        </VTabsWindow>

      </VCol>
    </VRow>
  </VContainer>

  <VSnackbar v-model="snack.show" :color="snack.color" location="bottom right" timeout="3000">
    {{ snack.text }}
  </VSnackbar>
</template>

<style scoped>
.expense-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.8rem;
}
.expense-table th,
.expense-table td {
  padding: 8px 12px;
  text-align: left;
  border-bottom: 1px solid rgba(0, 0, 0, 0.08);
  white-space: nowrap;
}
.expense-table th {
  background: rgba(var(--v-theme-primary), 0.06);
  font-weight: 600;
  color: rgb(var(--v-theme-primary));
  position: sticky;
  top: 0;
}
.expense-table tbody tr:hover {
  background: rgba(0, 0, 0, 0.03);
}
.totals-row td {
  font-weight: 700;
  background: rgba(var(--v-theme-primary), 0.08);
  border-top: 2px solid rgba(var(--v-theme-primary), 0.3);
}
</style>
