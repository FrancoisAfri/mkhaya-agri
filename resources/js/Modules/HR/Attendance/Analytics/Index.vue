<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type KPI = { label: string; value: string | number; unit?: string; trend?: number; color: string }
type TrendRow = { label: string; value: number }

const props = defineProps<{
  period: string
  dateFrom: string | null
  dateTo: string | null
  filters: Record<string, any>
  filterOptions: Record<string, any>
  kpis: KPI[]
  attendanceTrend: TrendRow[]
  latenessTrend: TrendRow[]
  hoursTrend: TrendRow[]
  byDepartment: any[]
  byShift: any[]
  byStatus: any[]
  overtimeTrend: TrendRow[]
  topLate: any[]
  topOvertime: any[]
}>()

const period  = ref(props.period)
const dateFrom = ref(props.dateFrom ?? '')
const dateTo   = ref(props.dateTo ?? '')

function applyFilters() {
  router.get(route('attendance.analytics'), { period: period.value, date_from: dateFrom.value || undefined, date_to: dateTo.value || undefined }, { preserveState: true, replace: true })
}

const deptHeaders = [
  { title: 'Department', key: 'department', sortable: false },
  { title: 'Rate',       key: 'rate',       sortable: false, align: 'end' as const },
  { title: 'Late%',      key: 'late_pct',   sortable: false, align: 'end' as const },
  { title: 'Avg Hours',  key: 'avg_hours',  sortable: false, align: 'end' as const },
]
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.dashboard'))" />
        <div>
          <h4 class="text-h4">Attendance Analytics</h4>
          <p class="text-medium-emphasis mb-0">Trends, performance metrics, and department breakdowns</p>
        </div>
      </div>
    </div>

    <!-- Filters -->
    <VCard elevation="0" border class="mb-5">
      <VCardText class="pa-4">
        <VRow dense align="center">
          <VCol cols="12" sm="3">
            <VSelect
              v-model="period"
              :items="[{ title: 'This Week', value: 'week' }, { title: 'This Month', value: 'month' }, { title: 'This Quarter', value: 'quarter' }, { title: 'Custom', value: 'custom' }]"
              item-title="title"
              item-value="value"
              label="Period"
              variant="outlined"
              density="compact"
              hide-details
              @update:model-value="applyFilters"
            />
          </VCol>
          <template v-if="period === 'custom'">
            <VCol cols="12" sm="3">
              <VTextField v-model="dateFrom" type="date" label="From" variant="outlined" density="compact" hide-details />
            </VCol>
            <VCol cols="12" sm="3">
              <VTextField v-model="dateTo" type="date" label="To" variant="outlined" density="compact" hide-details />
            </VCol>
            <VCol cols="auto">
              <VBtn color="primary" variant="tonal" @click="applyFilters">Apply</VBtn>
            </VCol>
          </template>
        </VRow>
      </VCardText>
    </VCard>

    <!-- KPI Cards -->
    <VRow class="mb-6">
      <VCol v-for="kpi in kpis" :key="kpi.label" cols="6" lg="3">
        <VCard elevation="0" border>
          <VCardText class="pa-4 text-center">
            <h5 class="text-h5 font-weight-bold" :class="`text-${kpi.color}`">
              {{ kpi.value }}{{ kpi.unit ?? '' }}
            </h5>
            <p class="text-body-2 text-medium-emphasis mb-0">{{ kpi.label }}</p>
            <p v-if="kpi.trend !== undefined" class="text-caption mb-0" :class="kpi.trend >= 0 ? 'text-success' : 'text-error'">
              {{ kpi.trend >= 0 ? '↑' : '↓' }} {{ Math.abs(kpi.trend) }}%
            </p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow>
      <!-- Attendance Trend -->
      <VCol cols="12" lg="6">
        <VCard elevation="0" border class="mb-5">
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Attendance Rate Trend</VCardTitle>
          <VDivider />
          <VCardText class="pa-4">
            <div v-for="row in attendanceTrend" :key="row.label" class="d-flex align-center gap-3 mb-2">
              <span class="text-caption text-medium-emphasis" style="width: 60px;">{{ row.label }}</span>
              <VProgressLinear :model-value="row.value" max="100" color="success" rounded height="8" class="flex-1-1" />
              <span class="text-caption font-weight-bold" style="width: 40px; text-align: right;">{{ row.value }}%</span>
            </div>
          </VCardText>
        </VCard>

        <!-- Top Late -->
        <VCard elevation="0" border>
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Top Late Arrivals</VCardTitle>
          <VDivider />
          <VList v-if="topLate.length" density="compact">
            <VListItem v-for="r in topLate" :key="r.employee" :title="r.employee" :subtitle="`${r.count} occurrences · Avg ${r.avg_minutes}min late`">
              <template #append>
                <VChip color="warning" size="x-small" variant="tonal">{{ r.count }}</VChip>
              </template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-center py-6 text-medium-emphasis text-body-2">No data.</VCardText>
        </VCard>
      </VCol>

      <!-- By Department -->
      <VCol cols="12" lg="6">
        <VCard elevation="0" border class="mb-5">
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">By Department</VCardTitle>
          <VDivider />
          <VDataTable :headers="deptHeaders" :items="byDepartment" hide-default-footer :items-per-page="10" density="compact">
            <template #item.rate="{ item }">
              <span :class="item.rate >= 95 ? 'text-success' : item.rate >= 85 ? 'text-warning' : 'text-error'">{{ item.rate }}%</span>
            </template>
            <template #no-data>
              <p class="text-body-2 text-medium-emphasis pa-4">No department data.</p>
            </template>
          </VDataTable>
        </VCard>

        <!-- Top Overtime -->
        <VCard elevation="0" border>
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Top Overtime Employees</VCardTitle>
          <VDivider />
          <VList v-if="topOvertime.length" density="compact">
            <VListItem v-for="r in topOvertime" :key="r.employee" :title="r.employee" :subtitle="`${r.total_hours}h overtime`">
              <template #append>
                <VChip color="warning" size="x-small" variant="tonal">{{ r.total_hours }}h</VChip>
              </template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-center py-6 text-medium-emphasis text-body-2">No data.</VCardText>
        </VCard>
      </VCol>
    </VRow>

  </VContainer>
</template>
