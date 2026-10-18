<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type WeeklyViolation = {
  employee_id: number; employee_name: string; week_start: string
  total_hours: number; total_overtime: number
  violation_type: 'overtime_exceeded' | 'ordinary_exceeded'
}
type RestViolation = {
  employee_id: number; employee_name: string; first_day: string
  first_clock_out: string; second_day: string; second_clock_in: string; rest_hours: number
}
type Stats = { weekly_violations: number; rest_violations: number; affected_employees: number; weeks_analysed: number }

const props = defineProps<{ weeklyViolations: WeeklyViolation[]; restViolations: RestViolation[]; stats: Stats; weeks: number }>()

const tab           = ref('weekly')
const selectedWeeks = ref(props.weeks)

const weekOptions = computed(() => [
  { title: t('attendance_bcea.period_4_weeks'), value: 4 },
  { title: t('attendance_bcea.period_8_weeks'), value: 8 },
  { title: t('attendance_bcea.period_12_weeks'), value: 12 },
  { title: t('attendance_bcea.period_26_weeks'), value: 26 },
  { title: t('attendance_bcea.period_52_weeks'), value: 52 },
])

function onWeeksChange() { router.get('/attendance/bcea', { weeks: selectedWeeks.value }) }

const weeklyHeaders = computed(() => [
  { title: t('attendance_bcea.table_employee'), key: 'employee_name' },
  { title: t('attendance_bcea.table_week_starting'), key: 'week_start' },
  { title: t('attendance_bcea.table_total_hours'), key: 'total_hours' },
  { title: t('attendance_bcea.table_overtime_hours'), key: 'total_overtime' },
  { title: t('attendance_bcea.table_violation'), key: 'violation_type' },
])

const restHeaders = computed(() => [
  { title: t('attendance_bcea.table_employee'), key: 'employee_name' },
  { title: t('attendance_bcea.table_day1_clockout'), key: 'first_clock_out' },
  { title: t('attendance_bcea.table_day2_clockin'), key: 'second_clock_in' },
  { title: t('attendance_bcea.table_rest_hours'), key: 'rest_hours' },
])

const sortedRestViolations = computed(() => [...props.restViolations].sort((a, b) => a.rest_hours - b.rest_hours))

function restChipColor(hours: number): string { if (hours < 8) return 'error'; if (hours < 12) return 'warning'; return 'success' }
function totalHoursColor(hours: number): string { return hours > 45 ? 'text-error' : '' }
function overtimeColor(hours: number): string { return hours > 10 ? 'text-warning' : '' }
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <VCol cols="12">
        <VCard elevation="0" border>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div>
              <div class="text-overline text-medium-emphasis">{{ t('attendance_bcea.breadcrumb_module') }}</div>
              <h4 class="text-h4 mb-1">{{ t('attendance_bcea.title') }}</h4>
              <p class="mb-0 text-medium-emphasis">{{ t('attendance_bcea.subtitle') }}</p>
            </div>
            <VSelect v-model="selectedWeeks" :items="weekOptions" :label="t('attendance_bcea.filter_period')" density="compact" hide-details style="max-width: 200px" @update:model-value="onWeeksChange" />
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" sm="4">
        <VCard elevation="0" border>
          <VCardText class="text-center pa-4">
            <VIcon icon="tabler-clock-alert-outline" color="error" size="32" class="mb-2" />
            <div class="text-h4 font-weight-bold text-error">{{ stats.weekly_violations }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('attendance_bcea.weekly_violations') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="4">
        <VCard elevation="0" border>
          <VCardText class="text-center pa-4">
            <VIcon icon="tabler-sleep-off" color="warning" size="32" class="mb-2" />
            <div class="text-h4 font-weight-bold text-warning">{{ stats.rest_violations }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('attendance_bcea.rest_violations') }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="4">
        <VCard elevation="0" border>
          <VCardText class="text-center pa-4">
            <VIcon icon="tabler-account-alert-outline" color="deep-orange" size="32" class="mb-2" />
            <div class="text-h4 font-weight-bold" style="color:#e64a19">{{ stats.affected_employees }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('attendance_bcea.affected_employees') }}</div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12">
        <VAlert color="info" variant="tonal">
          <template #prepend><VIcon icon="tabler-scale-balance" /></template>
          <div class="text-body-2 font-weight-medium mb-1">{{ t('attendance_bcea.rules_checked') }}</div>
          <ul class="text-body-2 ps-4 mb-0">
            <li>{{ t('attendance_bcea.rule_weekly') }}</li>
            <li>{{ t('attendance_bcea.rule_rest') }}</li>
          </ul>
        </VAlert>
      </VCol>

      <VCol cols="12">
        <VCard elevation="0" border>
          <VTabs v-model="tab" color="primary">
            <VTab value="weekly"><VIcon start icon="tabler-calendar-week" />{{ t('attendance_bcea.tab_weekly') }}<VChip v-if="weeklyViolations.length" size="x-small" color="error" variant="tonal" class="ms-2">{{ weeklyViolations.length }}</VChip></VTab>
            <VTab value="rest"><VIcon start icon="tabler-sleep" />{{ t('attendance_bcea.tab_rest') }}<VChip v-if="restViolations.length" size="x-small" color="warning" variant="tonal" class="ms-2">{{ restViolations.length }}</VChip></VTab>
          </VTabs>
          <VDivider />

          <VWindow v-model="tab">
            <VWindowItem value="weekly">
              <div v-if="weeklyViolations.length === 0" class="text-center py-12 text-medium-emphasis">
                <VIcon icon="tabler-check-circle-outline" color="success" size="52" class="mb-3 d-block mx-auto" />
                <div class="text-body-1 font-weight-medium">{{ t('attendance_bcea.no_weekly_violations') }}</div>
                <div class="text-caption mt-1">{{ t('attendance_bcea.no_weekly_sub') }}</div>
              </div>
              <VDataTable v-else :headers="weeklyHeaders" :items="weeklyViolations" :items-per-page="15" density="comfortable">
                <template #item.total_hours="{ item }"><span :class="totalHoursColor(item.total_hours)" class="font-weight-medium">{{ item.total_hours.toFixed(1) }} {{ t('attendance_bcea.hours_suffix') }}</span></template>
                <template #item.total_overtime="{ item }"><span :class="overtimeColor(item.total_overtime)" class="font-weight-medium">{{ item.total_overtime.toFixed(1) }} {{ t('attendance_bcea.hours_suffix') }}</span></template>
                <template #item.violation_type="{ item }"><VChip size="small" :color="item.violation_type === 'overtime_exceeded' ? 'warning' : 'error'" variant="tonal">{{ item.violation_type === 'overtime_exceeded' ? t('attendance_bcea.ot_exceeded') : t('attendance_bcea.hours_exceeded') }}</VChip></template>
              </VDataTable>
            </VWindowItem>

            <VWindowItem value="rest">
              <div v-if="restViolations.length === 0" class="text-center py-12 text-medium-emphasis">
                <VIcon icon="tabler-check-circle-outline" color="success" size="52" class="mb-3 d-block mx-auto" />
                <div class="text-body-1 font-weight-medium">{{ t('attendance_bcea.no_rest_violations') }}</div>
                <div class="text-caption mt-1">{{ t('attendance_bcea.no_rest_sub') }}</div>
              </div>
              <VDataTable v-else :headers="restHeaders" :items="sortedRestViolations" :items-per-page="15" density="comfortable">
                <template #item.first_clock_out="{ item }"><div class="text-body-2">{{ item.first_day }}</div><div class="text-caption text-medium-emphasis">{{ item.first_clock_out }}</div></template>
                <template #item.second_clock_in="{ item }"><div class="text-body-2">{{ item.second_day }}</div><div class="text-caption text-medium-emphasis">{{ item.second_clock_in }}</div></template>
                <template #item.rest_hours="{ item }"><VChip size="small" :color="restChipColor(item.rest_hours)" variant="tonal">{{ item.rest_hours.toFixed(1) }} {{ t('attendance_bcea.hours_suffix') }}</VChip></template>
              </VDataTable>
            </VWindowItem>
          </VWindow>
        </VCard>
      </VCol>
    </VRow>
  </VContainer>
</template>
