<script setup lang="ts">
import { ref, computed, reactive } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Row = Record<string, unknown>

interface Category   { id: number; name: string }
interface Department { id: number; name: string }
interface Schedule {
  id: number; name: string; report_type: string; frequency: string; format: string
  recipients: string; is_active: boolean; last_sent_at: string | null
  next_run_at: string | null; created_by: string
}

const props = defineProps<{
  allClaims:    Row[]
  byEmployee:   Row[]
  byCategory:   Row[]
  byDepartment: Row[]
  travelClaims: Row[]
  highRisk:     Row[]
  violations:   Row[]
  categories:   Category[]
  departments:  Department[]
  schedules:    Schedule[]
  filters:      Record<string, string>
}>()

const filters = reactive({
  date_from:   props.filters.date_from ?? new Date().getFullYear() + '-01-01',
  date_to:     props.filters.date_to   ?? new Date().toISOString().slice(0, 10),
  category_id: props.filters.category_id ?? '',
  status:      props.filters.status ?? '',
  search:      '',
})

function applyFilters() {
  router.get(route('claims.reports.index'), {
    date_from:   filters.date_from,
    date_to:     filters.date_to,
    category_id: filters.category_id || undefined,
    status:      filters.status || undefined,
  }, { preserveScroll: true })
}

function resetFilters() {
  filters.date_from   = new Date().getFullYear() + '-01-01'
  filters.date_to     = new Date().toISOString().slice(0, 10)
  filters.category_id = ''
  filters.status      = ''
  filters.search      = ''
  applyFilters()
}

const activeTab = ref('all_claims')

const tabs = computed(()=> [
  { key: 'all_claims',    label: t('claims_reports.tabs.all_claims'),          icon: 'tabler-files' },
  { key: 'by_employee',   label: t('claims_reports.tabs.by_employee'),         icon: 'tabler-users' },
  { key: 'by_category',   label: t('claims_reports.tabs.by_category'),         icon: 'tabler-shapes' },
  { key: 'by_department', label: t('claims_reports.tabs.by_department'),       icon: 'tabler-building' },
  { key: 'travel',        label: t('claims_reports.tabs.travel'),              icon: 'tabler-car' },
  { key: 'high_risk',     label: t('claims_reports.tabs.high_risk'),           icon: 'tabler-alert-triangle' },
  { key: 'violations',    label: t('claims_reports.tabs.policy_violations'),   icon: 'tabler-shield-exclamation' },
  { key: 'schedules',     label: t('claims_reports.tabs.scheduled_reports'),   icon: 'tabler-clock' },
])

function searchRows(rows: Row[], keys: string[]): Row[] {
  if (!filters.search) return rows
  const q = filters.search.toLowerCase()
  return rows.filter(row => keys.some(k => String(row[k] ?? '').toLowerCase().includes(q)))
}

const filteredAll    = computed(() => searchRows(props.allClaims,    ['reference_number', 'employee', 'title', 'category']))
const filteredEmp    = computed(() => searchRows(props.byEmployee,   ['employee_name', 'department']))
const filteredCat    = computed(() => searchRows(props.byCategory,   ['category']))
const filteredDept   = computed(() => searchRows(props.byDepartment, ['department']))
const filteredTravel = computed(() => searchRows(props.travelClaims, ['employee_name', 'origin', 'destination', 'reference_number']))
const filteredRisk   = computed(() => searchRows(props.highRisk,     ['reference_number', 'employee', 'category']))
const filteredViol   = computed(() => searchRows(props.violations,   ['reference_number', 'employee_name', 'category', 'policy_rule']))

function exportCsv() {
  const params = new URLSearchParams({
    type:      activeTab.value,
    date_from: filters.date_from,
    date_to:   filters.date_to,
    ...(filters.category_id ? { category_id: filters.category_id } : {}),
    ...(filters.status      ? { status:      filters.status }      : {}),
  })
  window.open(route('claims.reports.export') + '?' + params, '_blank')
}

const schedDialog   = ref(false)
const editSched     = ref<Schedule | null>(null)
const deleteSchedId = ref<number | null>(null)
const deleteDialog  = ref(false)

const schedForm = useForm({
  name:        '',
  report_type: 'all_claims',
  frequency:   'weekly',
  format:      'csv',
  recipients:  '',
})

const REPORT_TYPES = computed(()=>  [
  { value: 'all_claims',    title: t('claims_reports.tabs.all_claims') },
  { value: 'by_employee',   title: t('claims_reports.tabs.by_employee') },
  { value: 'by_category',   title: t('claims_reports.tabs.by_category') },
  { value: 'by_department', title: t('claims_reports.tabs.by_department') },
  { value: 'high_risk',     title: t('claims_reports.tabs.high_risk') },
  { value: 'violations',    title: t('claims_reports.tabs.policy_violations') },
  { value: 'travel',        title: t('claims_reports.tabs.travel') },
])

function openNewSched() {
  editSched.value = null
  schedForm.reset()
  schedDialog.value = true
}

function openEditSched(schedule: Schedule) {
  editSched.value = schedule
  schedForm.name        = schedule.name
  schedForm.report_type = schedule.report_type
  schedForm.frequency   = schedule.frequency
  schedForm.format      = schedule.format
  schedForm.recipients  = schedule.recipients
  schedDialog.value     = true
}

function saveSched() {
  if (editSched.value) {
    schedForm.patch(route('claims.reports.schedules.update', { schedule: editSched.value.id }), {
      onSuccess: () => { schedDialog.value = false; schedForm.reset() },
    })
  } else {
    schedForm.post(route('claims.reports.schedules.store'), {
      onSuccess: () => { schedDialog.value = false; schedForm.reset() },
    })
  }
}

function confirmDelete(id: number) { deleteSchedId.value = id; deleteDialog.value = true }
function doDelete() {
  if (!deleteSchedId.value) return
  router.delete(route('claims.reports.schedules.destroy', { schedule: deleteSchedId.value }), {
    onSuccess: () => { deleteDialog.value = false; deleteSchedId.value = null },
  })
}

function toggleActive(schedule: Schedule) {
  router.patch(route('claims.reports.schedules.update', { schedule: schedule.id }), { is_active: !schedule.is_active }, { preserveScroll: true })
}

const fmt  = (n: unknown) => 'R ' + Number(n).toLocaleString('en-ZA', { minimumFractionDigits: 2 })
const statusColor = (s: string) => ({ draft:'grey', submitted:'blue', under_review:'warning', approved:'success', rejected:'error', paid:'purple', cancelled:'grey' })[s] ?? 'grey'
const riskColor   = (r: string) => ({ critical:'error', high:'deep-orange', medium:'warning', low:'success' })[r] ?? 'grey'
const sevColor    = (s: string) => ({ block:'error', warn:'warning', info:'info' })[s] ?? 'grey'
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-5 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('claims_reports.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ filters.date_from }} – {{ filters.date_to }}</p>
      </div>
      <div class="d-flex ga-2 flex-wrap">
        <VBtn variant="outlined" prepend-icon="tabler-chart-bar" :href="route('claims.executive')">{{ t('claims_reports.executive_dashboard') }}</VBtn>
        <VBtn variant="outlined" prepend-icon="tabler-layout-dashboard" :href="route('claims.dashboard')">{{ t('claims_reports.dashboard') }}</VBtn>
      </div>
    </div>

    <VCard class="mb-4" variant="outlined">
      <VCardText>
        <VRow dense align="center">
          <VCol cols="12" sm="6" md="2">
            <VTextField v-model="filters.date_from" :label="t('claims_reports.filters.from')" type="date" density="compact" variant="outlined" hide-details />
          </VCol>
          <VCol cols="12" sm="6" md="2">
            <VTextField v-model="filters.date_to" :label="t('claims_reports.filters.to')" type="date" density="compact" variant="outlined" hide-details />
          </VCol>
          <VCol cols="12" sm="6" md="2">
            <VSelect v-model="filters.category_id" :items="[{ id: '', name: t('claims_reports.filters.all_categories') }, ...categories]"
              item-value="id" item-title="name" :label="t('claims_reports.filters.category')" density="compact" variant="outlined" hide-details clearable />
          </VCol>
          <VCol cols="12" sm="6" md="2">
            <VSelect v-model="filters.status"
              :items="['','draft','submitted','under_review','approved','rejected','paid','cancelled'].map(s => ({ value: s, title: s || t('claims_reports.filters.all_statuses') }))"
              item-value="value" item-title="title" :label="t('claims_reports.filters.status')" density="compact" variant="outlined" hide-details clearable />
          </VCol>
          <VCol cols="12" md="2">
            <VTextField v-model="filters.search" :label="t('claims_reports.filters.search')" density="compact" variant="outlined" hide-details
              prepend-inner-icon="tabler-search" clearable />
          </VCol>
          <VCol cols="auto">
            <VBtn color="primary" @click="applyFilters" prepend-icon="tabler-filter">{{ t('claims_reports.filters.apply') }}</VBtn>
            <VBtn variant="text" @click="resetFilters" class="ml-1">{{ t('claims_reports.filters.reset') }}</VBtn>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VTabs v-model="activeTab" color="primary" class="mb-0">
      <VTab v-for="tab in tabs" :key="tab.key" :value="tab.key">
        <VIcon start size="16">{{ tab.icon }}</VIcon>{{ tab.label }}
      </VTab>
    </VTabs>

    <VWindow v-model="activeTab">
      <VWindowItem value="all_claims">
        <VCard rounded="t-0">
          <VCardTitle class="d-flex align-center pa-3 pb-0">
            <span class="text-body-2">{{ t('claims_reports.all_claims.count', { count: filteredAll.length }) }}</span>
            <VSpacer />
            <VBtn size="small" variant="outlined" prepend-icon="tabler-download" @click="exportCsv">{{ t('claims_reports.all_claims.export_csv') }}</VBtn>
          </VCardTitle>
          <VDataTable :items="filteredAll" density="compact" hover
            :headers="[
              { title: t('claims_reports.all_claims.table.reference'),   key: 'reference_number' },
              { title: t('claims_reports.all_claims.table.employee'),    key: 'employee' },
              { title: t('claims_reports.all_claims.table.category'),    key: 'category' },
              { title: t('claims_reports.all_claims.table.title'),       key: 'title' },
              { title: t('claims_reports.all_claims.table.amount'),      key: 'total_amount' },
              { title: t('claims_reports.all_claims.table.status'),      key: 'status' },
              { title: t('claims_reports.all_claims.table.risk'),        key: 'risk_level' },
              { title: t('claims_reports.all_claims.table.claim_date'),  key: 'claim_date' },
              { title: t('claims_reports.all_claims.table.submitted'),   key: 'submitted_at' },
              { title: t('claims_reports.all_claims.table.approved'),    key: 'approved_at' },
              { title: t('claims_reports.all_claims.table.paid'),        key: 'paid_at' },
            ]">
            <template #item.reference_number="{ item }">
              <a :href="route('claims.my.show', { claim: item.id })" class="text-primary text-decoration-none font-weight-medium">{{ item.reference_number }}</a>
            </template>
            <template #item.total_amount="{ item }"><span class="font-weight-bold">{{ fmt(item.total_amount) }}</span></template>
            <template #item.status="{ item }">
              <VChip :color="statusColor(String(item.status))" size="x-small" variant="tonal">{{ item.status }}</VChip>
            </template>
            <template #item.risk_level="{ item }">
              <VChip v-if="item.risk_level" :color="riskColor(String(item.risk_level))" size="x-small" variant="tonal">{{ item.risk_level }}</VChip>
              <span v-else class="text-caption text-medium-emphasis">—</span>
            </template>
          </VDataTable>
        </VCard>
      </VWindowItem>

      <VWindowItem value="by_employee">
        <VCard rounded="t-0">
          <VCardTitle class="d-flex align-center pa-3 pb-0">
            <span class="text-body-2">{{ t('claims_reports.by_employee.count', { count: filteredEmp.length }) }}</span>
            <VSpacer />
            <VBtn size="small" variant="outlined" prepend-icon="tabler-download" @click="exportCsv">{{ t('claims_reports.by_employee.export_csv') }}</VBtn>
          </VCardTitle>
          <VDataTable :items="filteredEmp" density="compact" hover
            :headers="[
              { title: t('claims_reports.by_employee.table.employee'),   key: 'employee_name' },
              { title: t('claims_reports.by_employee.table.department'), key: 'department' },
              { title: t('claims_reports.by_employee.table.claims'),     key: 'claim_count' },
              { title: t('claims_reports.by_employee.table.total'),      key: 'total_amount' },
              { title: t('claims_reports.by_employee.table.average'),    key: 'avg_amount' },
              { title: t('claims_reports.by_employee.table.approved'),   key: 'approved_count' },
              { title: t('claims_reports.by_employee.table.rejected'),   key: 'rejected_count' },
            ]">
            <template #item.total_amount="{ item }"><span class="font-weight-bold">{{ fmt(item.total_amount) }}</span></template>
            <template #item.avg_amount="{ item }">{{ fmt(item.avg_amount) }}</template>
            <template #item.department="{ item }"><span class="text-caption">{{ item.department || '—' }}</span></template>
          </VDataTable>
        </VCard>
      </VWindowItem>

      <VWindowItem value="by_category">
        <VCard rounded="t-0">
          <VDataTable :items="filteredCat" density="compact" hover
            :headers="[
              { title: t('claims_reports.by_category.table.category'), key: 'category' },
              { title: t('claims_reports.by_category.table.claims'),   key: 'claim_count' },
              { title: t('claims_reports.by_category.table.total'),    key: 'total_amount' },
              { title: t('claims_reports.by_category.table.average'),  key: 'avg_amount' },
              { title: t('claims_reports.by_category.table.approved'), key: 'approved_count' },
            ]">
            <template #item.total_amount="{ item }"><span class="font-weight-bold">{{ fmt(item.total_amount) }}</span></template>
            <template #item.avg_amount="{ item }">{{ fmt(item.avg_amount) }}</template>
          </VDataTable>
        </VCard>
      </VWindowItem>

      <VWindowItem value="by_department">
        <VCard rounded="t-0">
          <VDataTable :items="filteredDept" density="compact" hover
            :headers="[
              { title: t('claims_reports.by_department.table.department'), key: 'department' },
              { title: t('claims_reports.by_department.table.employees'),  key: 'employee_count' },
              { title: t('claims_reports.by_department.table.claims'),     key: 'claim_count' },
              { title: t('claims_reports.by_department.table.total'),      key: 'total_amount' },
              { title: t('claims_reports.by_department.table.average'),    key: 'avg_amount' },
            ]">
            <template #item.total_amount="{ item }"><span class="font-weight-bold">{{ fmt(item.total_amount) }}</span></template>
            <template #item.avg_amount="{ item }">{{ fmt(item.avg_amount) }}</template>
          </VDataTable>
        </VCard>
      </VWindowItem>

      <VWindowItem value="travel">
        <VCard rounded="t-0">
          <VCardTitle class="d-flex align-center pa-3 pb-0">
            <span class="text-body-2">{{ t('claims_reports.travel.count', { count: filteredTravel.length }) }}</span>
            <VSpacer />
            <VBtn size="small" variant="outlined" prepend-icon="tabler-download" @click="exportCsv">{{ t('claims_reports.travel.export_csv') }}</VBtn>
          </VCardTitle>
          <VDataTable :items="filteredTravel" density="compact" hover
            :headers="[
              { title: t('claims_reports.travel.table.reference'),    key: 'reference_number' },
              { title: t('claims_reports.travel.table.employee'),     key: 'employee_name' },
              { title: t('claims_reports.travel.table.trip_date'),    key: 'trip_date' },
              { title: t('claims_reports.travel.table.origin'),       key: 'origin' },
              { title: t('claims_reports.travel.table.destination'),  key: 'destination' },
              { title: t('claims_reports.travel.table.distance_km'),  key: 'distance_km' },
              { title: t('claims_reports.travel.table.rate_per_km'),  key: 'km_rate' },
              { title: t('claims_reports.travel.table.base_amount'),  key: 'base_amount' },
              { title: t('claims_reports.travel.table.surcharge'),    key: 'peak_surcharge' },
              { title: t('claims_reports.travel.table.trip_total'),   key: 'trip_amount' },
              { title: t('claims_reports.travel.table.traffic'),      key: 'traffic_condition' },
              { title: t('claims_reports.travel.table.status'),       key: 'status' },
            ]">
            <template #item.distance_km="{ item }">{{ Number(item.distance_km).toFixed(1) }} km</template>
            <template #item.base_amount="{ item }">{{ fmt(item.base_amount) }}</template>
            <template #item.trip_amount="{ item }"><span class="font-weight-bold">{{ fmt(item.trip_amount) }}</span></template>
            <template #item.peak_surcharge="{ item }">{{ Number(item.peak_surcharge) > 0 ? fmt(item.peak_surcharge) : '—' }}</template>
            <template #item.status="{ item }">
              <VChip :color="statusColor(String(item.status))" size="x-small" variant="tonal">{{ item.status }}</VChip>
            </template>
          </VDataTable>
        </VCard>
      </VWindowItem>

      <VWindowItem value="high_risk">
        <VCard rounded="t-0">
          <VCardTitle class="d-flex align-center pa-3 pb-0">
            <span class="text-body-2">{{ t('claims_reports.high_risk.count', { count: filteredRisk.length }) }}</span>
            <VSpacer />
            <VBtn size="small" variant="outlined" prepend-icon="tabler-download" @click="exportCsv">{{ t('claims_reports.high_risk.export_csv') }}</VBtn>
          </VCardTitle>
          <VDataTable :items="filteredRisk" density="compact" hover
            :headers="[
              { title: t('claims_reports.high_risk.table.reference'),   key: 'reference_number' },
              { title: t('claims_reports.high_risk.table.employee'),    key: 'employee' },
              { title: t('claims_reports.high_risk.table.category'),    key: 'category' },
              { title: t('claims_reports.high_risk.table.amount'),      key: 'total_amount' },
              { title: t('claims_reports.high_risk.table.risk'),        key: 'risk_level' },
              { title: t('claims_reports.high_risk.table.score'),       key: 'risk_score' },
              { title: t('claims_reports.high_risk.table.status'),      key: 'status' },
              { title: t('claims_reports.high_risk.table.flags'),       key: 'ai_flags' },
            ]">
            <template #item.total_amount="{ item }"><span class="font-weight-bold">{{ fmt(item.total_amount) }}</span></template>
            <template #item.risk_level="{ item }">
              <VChip :color="riskColor(String(item.risk_level))" size="x-small" variant="tonal">{{ item.risk_level }}</VChip>
            </template>
            <template #item.status="{ item }">
              <VChip :color="statusColor(String(item.status))" size="x-small" variant="tonal">{{ item.status }}</VChip>
            </template>
          </VDataTable>
        </VCard>
      </VWindowItem>

      <VWindowItem value="violations">
        <VCard rounded="t-0">
          <VDataTable :items="filteredViol" density="compact" hover
            :headers="[
              { title: t('claims_reports.violations.table.reference'),    key: 'reference_number' },
              { title: t('claims_reports.violations.table.employee'),     key: 'employee_name' },
              { title: t('claims_reports.violations.table.category'),     key: 'category' },
              { title: t('claims_reports.violations.table.amount'),       key: 'total_amount' },
              { title: t('claims_reports.violations.table.policy_rule'),  key: 'policy_rule' },
              { title: t('claims_reports.violations.table.violation'),    key: 'violation_type' },
              { title: t('claims_reports.violations.table.description'),  key: 'violation_description' },
              { title: t('claims_reports.violations.table.severity'),     key: 'severity' },
              { title: t('claims_reports.violations.table.exception'),    key: 'exception_approved' },
            ]">
            <template #item.total_amount="{ item }"><span class="font-weight-bold">{{ fmt(item.total_amount) }}</span></template>
            <template #item.severity="{ item }">
              <VChip :color="sevColor(String(item.severity))" size="x-small" variant="tonal">{{ item.severity }}</VChip>
            </template>
            <template #item.exception_approved="{ item }">
              <VIcon :color="item.exception_approved ? 'success' : 'grey'" size="16">
                {{ item.exception_approved ? 'tabler-circle-check' : 'tabler-circle-minus' }}
              </VIcon>
            </template>
          </VDataTable>
        </VCard>
      </VWindowItem>

      <VWindowItem value="schedules">
        <VCard rounded="t-0">
          <VCardTitle class="d-flex align-center pa-4 pb-0">
            <span class="text-body-2 font-weight-bold">{{ t('claims_reports.schedules.title') }}</span>
            <VSpacer />
            <VBtn color="primary" size="small" prepend-icon="tabler-plus" @click="openNewSched">{{ t('claims_reports.schedules.new_schedule') }}</VBtn>
          </VCardTitle>
          <VCardText v-if="!schedules.length" class="text-center pa-10 text-medium-emphasis">
            {{ t('claims_reports.schedules.empty') }}
          </VCardText>
          <VDataTable v-else :items="schedules" density="compact" hover
            :headers="[
              { title: t('claims_reports.schedules.table.name'),         key: 'name' },
              { title: t('claims_reports.schedules.table.report_type'),  key: 'report_type' },
              { title: t('claims_reports.schedules.table.frequency'),    key: 'frequency' },
              { title: t('claims_reports.schedules.table.format'),       key: 'format' },
              { title: t('claims_reports.schedules.table.recipients'),   key: 'recipients' },
              { title: t('claims_reports.schedules.table.next_run'),     key: 'next_run_at' },
              { title: t('claims_reports.schedules.table.last_sent'),    key: 'last_sent_at' },
              { title: t('claims_reports.schedules.table.active'),       key: 'is_active', sortable: false },
              { title: '',             key: 'actions',   sortable: false },
            ]">
            <template #item.report_type="{ item }">
              <VChip size="x-small" variant="tonal" color="primary">{{ item.report_type.replace(/_/g, ' ') }}</VChip>
            </template>
            <template #item.frequency="{ item }">
              <VChip size="x-small" variant="tonal" color="indigo">{{ item.frequency }}</VChip>
            </template>
            <template #item.is_active="{ item }">
              <VSwitch :model-value="item.is_active" density="compact" hide-details color="success" @change="toggleActive(item)" />
            </template>
            <template #item.last_sent_at="{ item }">
              <span class="text-caption text-medium-emphasis">{{ item.last_sent_at || t('claims_reports.schedules.never') }}</span>
            </template>
            <template #item.next_run_at="{ item }">
              <span class="text-caption">{{ item.next_run_at || '—' }}</span>
            </template>
            <template #item.actions="{ item }">
              <div class="d-flex ga-1">
                <VBtn icon="tabler-pencil" size="x-small" variant="tonal" @click="openEditSched(item)" />
                <VBtn icon="tabler-trash" size="x-small" color="error" variant="tonal" @click="confirmDelete(item.id)" />
              </div>
            </template>
          </VDataTable>
        </VCard>
      </VWindowItem>
    </VWindow>

    <VDialog v-model="schedDialog" max-width="520" persistent>
      <VCard :title="editSched ? t('claims_reports.schedules.dialog.edit_title') : t('claims_reports.schedules.dialog.new_title')">
        <VCardText>
          <VTextField v-model="schedForm.name" :label="t('claims_reports.schedules.dialog.name')" variant="outlined" density="compact"
            :error-messages="schedForm.errors.name" class="mb-3" />
          <VSelect v-model="schedForm.report_type" :items="REPORT_TYPES" item-value="value" item-title="title"
            :label="t('claims_reports.schedules.dialog.report_type')" variant="outlined" density="compact" :error-messages="schedForm.errors.report_type" class="mb-3" />
          <VRow dense>
            <VCol cols="6">
              <VSelect v-model="schedForm.frequency"
                :items="[{ value:'daily',title:t('claims_scheduled_reports.dialog.frequency_daily')},{value:'weekly',title:t('claims_scheduled_reports.dialog.frequency_weekly')},{value:'monthly',title:t('claims_scheduled_reports.dialog.frequency_monthly')}]"
                item-value="value" item-title="title" :label="t('claims_reports.schedules.dialog.frequency')" variant="outlined" density="compact"
                :error-messages="schedForm.errors.frequency" />
            </VCol>
            <VCol cols="6">
              <VSelect v-model="schedForm.format"
                :items="[{value:'csv',title:t('claims_scheduled_reports.dialog.format_csv')},{value:'pdf',title:t('claims_scheduled_reports.dialog.format_pdf')}]"
                item-value="value" item-title="title" :label="t('claims_reports.schedules.dialog.format')" variant="outlined" density="compact" />
            </VCol>
          </VRow>
          <VTextarea v-model="schedForm.recipients" :label="t('claims_reports.schedules.dialog.recipients')"
            variant="outlined" density="compact" rows="2" :error-messages="schedForm.errors.recipients" class="mt-3" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="outlined" @click="schedDialog = false">{{ t('claims_reports.schedules.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="schedForm.processing" @click="saveSched">
            {{ editSched ? t('claims_reports.schedules.dialog.update') : t('claims_reports.schedules.dialog.create') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="deleteDialog" max-width="380">
      <VCard :title="t('claims_reports.schedules.delete_title')">
        <VCardText>{{ t('claims_reports.schedules.delete_message') }}</VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="outlined" @click="deleteDialog = false">{{ t('claims_reports.schedules.cancel') }}</VBtn>
          <VBtn color="error" @click="doDelete">{{ t('claims_reports.schedules.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
