<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type CostRow = {
  employee_id: number
  employee_name: string
  department: string
  absent_days: number
  basic_salary: number
  daily_rate: number
  absent_cost: number
}

type DeptRow = {
  department: string
  absent_days: number
  absent_cost: number
  headcount: number
}

const props = defineProps<{
  rows: CostRow[]
  byDepartment: DeptRow[]
  summary: {
    total_absent_days: number
    total_absent_cost: number
    annualised_cost: number
    affected_employees: number
    avg_cost_per_absence: number
    months_analysed: number
    from: string
  }
  months: number
}>()

const monthsFilter = ref(props.months)
const activeTab = ref('employees')

function applyFilter() {
  router.get(route('attendance.analytics.absenteeism-cost'), { months: monthsFilter.value }, { preserveState: true, replace: true })
}

const empHeaders = [
  { title: 'Employee',    key: 'employee_name', sortable: false },
  { title: 'Department',  key: 'department',    sortable: false },
  { title: 'Absent Days', key: 'absent_days',   sortable: false, align: 'end' as const },
  { title: 'Daily Rate',  key: 'daily_rate',    sortable: false, align: 'end' as const },
  { title: 'Cost',        key: 'absent_cost',   sortable: false, align: 'end' as const },
]

const deptHeaders = [
  { title: 'Department',  key: 'department',  sortable: false },
  { title: 'Headcount',   key: 'headcount',   sortable: false, align: 'end' as const },
  { title: 'Absent Days', key: 'absent_days', sortable: false, align: 'end' as const },
  { title: 'Total Cost',  key: 'absent_cost', sortable: false, align: 'end' as const },
]

const fmt = (v: number) => `R ${v.toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center gap-2 mb-6">
      <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.analytics'))" />
      <div>
        <h4 class="text-h4">Absenteeism Cost</h4>
        <p class="text-medium-emphasis mb-0">Financial impact of unplanned absences</p>
      </div>
    </div>

    <!-- Summary -->
    <VRow class="mb-5">
      <VCol cols="6" sm="4" lg="2"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-error">{{ fmt(summary.total_absent_cost) }}</h6><p class="text-caption text-medium-emphasis mb-0">Total Cost</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="4" lg="2"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-warning">{{ fmt(summary.annualised_cost) }}</h6><p class="text-caption text-medium-emphasis mb-0">Annualised</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="4" lg="2"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6">{{ summary.total_absent_days }}</h6><p class="text-caption text-medium-emphasis mb-0">Absent Days</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="4" lg="2"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6">{{ summary.affected_employees }}</h6><p class="text-caption text-medium-emphasis mb-0">Employees</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="4" lg="2"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6">{{ fmt(summary.avg_cost_per_absence) }}</h6><p class="text-caption text-medium-emphasis mb-0">Avg/Absence</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="4" lg="2"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6">{{ summary.months_analysed }}m</h6><p class="text-caption text-medium-emphasis mb-0">Period</p></VCardText></VCard></VCol>
    </VRow>

    <!-- Filter -->
    <VCard elevation="0" border class="mb-5">
      <VCardText class="pa-4">
        <VRow dense align="center">
          <VCol cols="12" sm="4">
            <VSelect v-model="monthsFilter" :items="[{title:'1 Month',value:1},{title:'3 Months',value:3},{title:'6 Months',value:6},{title:'12 Months',value:12}]" item-title="title" item-value="value" label="Period" variant="outlined" density="compact" hide-details @update:model-value="applyFilter" />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VTabs v-model="activeTab" class="mb-5">
      <VTab value="employees">By Employee</VTab>
      <VTab value="departments">By Department</VTab>
    </VTabs>

    <VWindow v-model="activeTab">
      <VWindowItem value="employees">
        <VCard elevation="0" border>
          <VDataTable :headers="empHeaders" :items="rows" hover :items-per-page="25">
            <template #item.daily_rate="{ item }"><span class="text-body-2">{{ fmt(item.daily_rate) }}</span></template>
            <template #item.absent_cost="{ item }"><span class="text-body-2 font-weight-semibold text-error">{{ fmt(item.absent_cost) }}</span></template>
            <template #no-data><div class="text-center pa-6 text-medium-emphasis">No data for selected period.</div></template>
          </VDataTable>
        </VCard>
      </VWindowItem>
      <VWindowItem value="departments">
        <VCard elevation="0" border>
          <VDataTable :headers="deptHeaders" :items="byDepartment" hover :items-per-page="25">
            <template #item.absent_cost="{ item }"><span class="text-body-2 font-weight-semibold text-error">{{ fmt(item.absent_cost) }}</span></template>
            <template #no-data><div class="text-center pa-6 text-medium-emphasis">No department data.</div></template>
          </VDataTable>
        </VCard>
      </VWindowItem>
    </VWindow>
  </VContainer>
</template>
