<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Row = {
  employee_id: number; employee_name: string; department: string
  absent_days: number; basic_salary: number; daily_rate: number; absent_cost: number
}
type DeptRow = { department: string; absent_days: number; absent_cost: number; headcount: number }
type Summary = {
  total_absent_days: number; total_absent_cost: number; annualised_cost: number
  affected_employees: number; avg_cost_per_absence: number; months_analysed: number; from: string
}

const props = defineProps<{ rows: Row[]; byDepartment: DeptRow[]; summary: Summary; months: number }>()

const selectedMonths = ref(props.months)

const periodOptions = computed(() => [
  { title: t('attendance_analytics.period_last_1_month'), value: 1 },
  { title: t('attendance_analytics.period_last_3_months'), value: 3 },
  { title: t('attendance_analytics.period_last_6_months'), value: 6 },
  { title: t('attendance_analytics.period_last_12_months'), value: 12 },
])

function onPeriodChange() { router.get('/attendance/analytics/absenteeism-cost', { months: selectedMonths.value }) }
function fmt(val: number): string { return new Intl.NumberFormat('en-ZA', { style: 'currency', currency: 'ZAR', minimumFractionDigits: 0 }).format(val) }

const deptHeaders = computed(() => [
  { title: t('attendance_analytics.table_department'), key: 'department' },
  { title: t('attendance_analytics.table_absent_days'), key: 'absent_days' },
  { title: t('attendance_analytics.table_headcount'), key: 'headcount' },
  { title: t('attendance_analytics.table_cost_zar'), key: 'absent_cost' },
])

const empHeaders = computed(() => [
  { title: t('attendance_analytics.table_employee'), key: 'employee_name' },
  { title: t('attendance_analytics.table_department'), key: 'department' },
  { title: t('attendance_analytics.table_absent_days'), key: 'absent_days' },
  { title: t('attendance_analytics.table_daily_rate'), key: 'daily_rate' },
  { title: t('attendance_analytics.table_cost_zar'), key: 'absent_cost' },
])
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <VCol cols="12">
        <VCard class="mb-2">
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div>
              <div class="text-overline text-medium-emphasis">{{ t('attendance_analytics.absenteeism_breadcrumb') }}</div>
              <h4 class="text-h4 mb-1">{{ t('attendance_analytics.absenteeism_title') }}</h4>
              <p class="mb-0 text-medium-emphasis">{{ t('attendance_analytics.absenteeism_subtitle', { from: summary.from }) }}</p>
            </div>
            <VSelect v-model="selectedMonths" :items="periodOptions" item-title="title" item-value="value" density="compact" style="max-width:200px" @update:model-value="onPeriodChange" />
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="6" md="3">
        <VCard color="error" variant="tonal" class="text-center">
          <VCardText class="py-5">
            <i class="fas fa-money-bill-wave text-h4 mb-2 d-block"></i>
            <div class="text-h5 font-weight-bold">{{ fmt(summary.total_absent_cost) }}</div>
            <div class="text-caption">{{ t('attendance_analytics.total_cost', { months: summary.months_analysed }) }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard color="warning" variant="tonal" class="text-center">
          <VCardText class="py-5">
            <i class="fas fa-chart-line text-h4 mb-2 d-block"></i>
            <div class="text-h5 font-weight-bold">{{ fmt(summary.annualised_cost) }}</div>
            <div class="text-caption">{{ t('attendance_analytics.annualised_projection') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard color="info" variant="tonal" class="text-center">
          <VCardText class="py-5">
            <i class="fas fa-calendar-times text-h4 mb-2 d-block"></i>
            <div class="text-h5 font-weight-bold">{{ summary.total_absent_days }}</div>
            <div class="text-caption">{{ t('attendance_analytics.total_absent_days') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard color="secondary" variant="tonal" class="text-center">
          <VCardText class="py-5">
            <i class="fas fa-calculator text-h4 mb-2 d-block"></i>
            <div class="text-h5 font-weight-bold">{{ fmt(summary.avg_cost_per_absence) }}</div>
            <div class="text-caption">{{ t('attendance_analytics.avg_cost_per_absence') }}</div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="5">
        <VCard>
          <VCardTitle class="py-4 px-5">{{ t('attendance_analytics.cost_by_department') }}</VCardTitle>
          <VDataTable :headers="deptHeaders" :items="byDepartment" density="compact" :items-per-page="10">
            <template #item.absent_cost="{ value }">
              <span class="font-weight-bold text-error">{{ fmt(value) }}</span>
            </template>
          </VDataTable>
        </VCard>
      </VCol>

      <VCol cols="12" md="7">
        <VCard>
          <VCardTitle class="py-4 px-5">
            {{ t('attendance_analytics.top_absenteeism_cost') }}
            <span class="text-caption text-medium-emphasis ms-2">{{ t('attendance_analytics.payslip_note') }}</span>
          </VCardTitle>
          <VDataTable :headers="empHeaders" :items="rows" density="compact" :items-per-page="15" :sort-by="[{ key: 'absent_cost', order: 'desc' }]">
            <template #item.daily_rate="{ value }">{{ fmt(value) }}</template>
            <template #item.absent_cost="{ value }">
              <span class="font-weight-bold text-error">{{ fmt(value) }}</span>
            </template>
          </VDataTable>
        </VCard>
      </VCol>

      <VCol cols="12">
        <VAlert type="info" variant="tonal" class="mt-2">
          <strong>{{ t('attendance_analytics.how_calculated') }}</strong>
          {{ t('attendance_analytics.calc_explanation') }}
        </VAlert>
      </VCol>
    </VRow>
  </VContainer>
</template>
