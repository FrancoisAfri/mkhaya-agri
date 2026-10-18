<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

const props = defineProps<{
  lateArrivals: any[]
  overtimeRows: any[]
  earlyDepartures: any[]
  clockingLog: any[]
  attendanceRate: any
  deptSummary: any[]
  wellnessReport: any[]
  productivityReport: any[]
  policyViolations: any[]
  disciplineCases: any[]
  employees: any[]
  departments: any[]
  filters: { date_from: string; date_to: string }
}>()

const dateFrom = ref(props.filters.date_from)
const dateTo   = ref(props.filters.date_to)
const generating = ref<string | null>(null)

function applyFilters() {
  router.get(route('attendance.reports.index'), { date_from: dateFrom.value, date_to: dateTo.value }, { preserveState: true, replace: true })
}

async function generate(key: string) {
  generating.value = key
  try {
    window.location.href = route('attendance.reports.generate', { key }) + `?date_from=${dateFrom.value}&date_to=${dateTo.value}`
  } finally {
    generating.value = null
  }
}

const activeTab = ref('summary')

const lateHeaders = [
  { title: 'Employee',    key: 'employee', sortable: false },
  { title: 'Date',        key: 'date', sortable: false },
  { title: 'Clock In',    key: 'clock_in', sortable: false },
  { title: 'Minutes Late',key: 'minutes_late', sortable: false, align: 'end' as const },
]

const otHeaders = [
  { title: 'Employee',  key: 'employee',      sortable: false },
  { title: 'Date',      key: 'date',          sortable: false },
  { title: 'OT Hours',  key: 'overtime_hours',sortable: false, align: 'end' as const },
]

const deptHeaders = [
  { title: 'Department',  key: 'department',    sortable: false },
  { title: 'Employees',   key: 'employee_count',sortable: false },
  { title: 'Rate',        key: 'attendance_rate',sortable: false, align: 'end' as const },
  { title: 'Late Count',  key: 'late_count',    sortable: false, align: 'end' as const },
]
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.dashboard'))" />
        <div>
          <h4 class="text-h4">Attendance Reports</h4>
          <p class="text-medium-emphasis mb-0">Generate and review attendance analytics</p>
        </div>
      </div>
      <VBtn variant="tonal" color="default" @click="router.visit(route('attendance.reports.scheduled'))">Scheduled Reports</VBtn>
    </div>

    <!-- Date Range Filter -->
    <VCard elevation="0" border class="mb-5">
      <VCardText class="pa-4">
        <VRow dense align="center">
          <VCol cols="12" sm="4"><VTextField v-model="dateFrom" label="From" type="date" variant="outlined" density="compact" hide-details /></VCol>
          <VCol cols="12" sm="4"><VTextField v-model="dateTo" label="To" type="date" variant="outlined" density="compact" hide-details :min="dateFrom" /></VCol>
          <VCol cols="auto"><VBtn color="primary" variant="tonal" @click="applyFilters">Apply</VBtn></VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Tabs -->
    <VTabs v-model="activeTab" class="mb-5">
      <VTab value="summary">Summary</VTab>
      <VTab value="late">Late Arrivals</VTab>
      <VTab value="overtime">Overtime</VTab>
      <VTab value="departments">By Department</VTab>
    </VTabs>

    <VWindow v-model="activeTab">
      <VWindowItem value="summary">
        <VRow>
          <VCol cols="12" sm="6" lg="3">
            <VCard elevation="0" border><VCardText class="text-center pa-4">
              <h5 class="text-h5">{{ attendanceRate?.overall ?? '—' }}%</h5>
              <p class="text-body-2 text-medium-emphasis mb-0">Overall Attendance Rate</p>
            </VCardText></VCard>
          </VCol>
          <VCol cols="12" sm="6" lg="3">
            <VCard elevation="0" border><VCardText class="text-center pa-4">
              <h5 class="text-h5">{{ lateArrivals.length }}</h5>
              <p class="text-body-2 text-medium-emphasis mb-0">Late Arrivals</p>
            </VCardText></VCard>
          </VCol>
          <VCol cols="12" sm="6" lg="3">
            <VCard elevation="0" border><VCardText class="text-center pa-4">
              <h5 class="text-h5">{{ overtimeRows.length }}</h5>
              <p class="text-body-2 text-medium-emphasis mb-0">Overtime Events</p>
            </VCardText></VCard>
          </VCol>
          <VCol cols="12" sm="6" lg="3">
            <VCard elevation="0" border><VCardText class="text-center pa-4">
              <h5 class="text-h5">{{ policyViolations.length }}</h5>
              <p class="text-body-2 text-medium-emphasis mb-0">Policy Violations</p>
            </VCardText></VCard>
          </VCol>
          <VCol cols="12">
            <VCard elevation="0" border>
              <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Download Reports</VCardTitle>
              <VDivider />
              <VCardText class="pa-4">
                <VRow>
                  <VCol v-for="key in ['late_arrivals', 'overtime', 'attendance_rate', 'dept_summary', 'policy_violations', 'discipline_cases']" :key="key" cols="6" sm="4" lg="3">
                    <VBtn block variant="tonal" color="primary" size="small" prepend-icon="tabler-download" :loading="generating === key" @click="generate(key)">
                      {{ key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) }}
                    </VBtn>
                  </VCol>
                </VRow>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>
      </VWindowItem>

      <VWindowItem value="late">
        <VCard elevation="0" border>
          <VDataTable :headers="lateHeaders" :items="lateArrivals" hover :items-per-page="25">
            <template #no-data><div class="text-center pa-6 text-medium-emphasis">No late arrivals.</div></template>
          </VDataTable>
        </VCard>
      </VWindowItem>

      <VWindowItem value="overtime">
        <VCard elevation="0" border>
          <VDataTable :headers="otHeaders" :items="overtimeRows" hover :items-per-page="25">
            <template #no-data><div class="text-center pa-6 text-medium-emphasis">No overtime records.</div></template>
          </VDataTable>
        </VCard>
      </VWindowItem>

      <VWindowItem value="departments">
        <VCard elevation="0" border>
          <VDataTable :headers="deptHeaders" :items="deptSummary" hover :items-per-page="25">
            <template #item.attendance_rate="{ item }">
              <span :class="item.attendance_rate >= 95 ? 'text-success' : item.attendance_rate >= 85 ? 'text-warning' : 'text-error'">{{ item.attendance_rate }}%</span>
            </template>
            <template #no-data><div class="text-center pa-6 text-medium-emphasis">No department data.</div></template>
          </VDataTable>
        </VCard>
      </VWindowItem>
    </VWindow>
  </VContainer>
</template>
