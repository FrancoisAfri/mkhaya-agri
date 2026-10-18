<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

const generating = ref<string | null>(null)

const reports = [
  {
    key: 'leave_summary',
    title: 'Leave Summary Report',
    description: 'Overview of leave taken per employee and leave type for the selected period.',
    icon: 'tabler-report',
    color: 'primary',
  },
  {
    key: 'balance_report',
    title: 'Balance Report',
    description: 'Current leave balances for all employees, including accrued and pending days.',
    icon: 'tabler-chart-bar',
    color: 'success',
  },
  {
    key: 'approval_audit',
    title: 'Approval Audit Trail',
    description: 'Full audit log of all leave approvals, rejections, and policy overrides.',
    icon: 'tabler-shield-check',
    color: 'info',
  },
  {
    key: 'bcea_compliance',
    title: 'BCEA Compliance Report',
    description: 'Check statutory leave compliance against BCEA minimums per leave type.',
    icon: 'tabler-certificate',
    color: 'warning',
  },
  {
    key: 'negative_balances',
    title: 'Negative Balance Report',
    description: 'List of employees with negative leave balances requiring HR intervention.',
    icon: 'tabler-trending-down',
    color: 'error',
  },
  {
    key: 'leave_liability',
    title: 'Leave Liability Report',
    description: 'Financial exposure from accrued leave balances, estimated per leave type.',
    icon: 'tabler-coins',
    color: 'secondary',
  },
  {
    key: 'absence_trends',
    title: 'Absence Trends',
    description: 'Frequency and patterns of unplanned absences over time.',
    icon: 'tabler-chart-line',
    color: 'purple',
  },
  {
    key: 'parental_leave',
    title: 'Parental Leave Report',
    description: 'Summary of parental leave pools and Van Wyk compliance status.',
    icon: 'tabler-heart',
    color: 'pink',
  },
]

const dateFrom = ref('')
const dateTo   = ref('')
const format   = ref<'pdf' | 'xlsx' | 'csv'>('pdf')

function queueReport(key: string) {
  generating.value = key
  router.post(route('hr.leave.reports.queue', { key }), {
    date_from: dateFrom.value || undefined,
    date_to:   dateTo.value || undefined,
    format:    format.value,
  }, {
    onFinish: () => { generating.value = null },
  })
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">

    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('hr.leave.hr-dashboard'))" />
        <div>
          <h4 class="text-h4">Leave Reports</h4>
          <p class="text-medium-emphasis mb-0">Generate and export leave analytics</p>
        </div>
      </div>
    </div>

    <!-- Date Range + Format Filters -->
    <VCard elevation="0" border class="mb-6">
      <VCardText class="pa-4">
        <VRow dense align="center">
          <VCol cols="12" sm="4">
            <VTextField
              v-model="dateFrom"
              label="From Date"
              type="date"
              variant="outlined"
              density="compact"
              hide-details
            />
          </VCol>
          <VCol cols="12" sm="4">
            <VTextField
              v-model="dateTo"
              label="To Date"
              type="date"
              variant="outlined"
              density="compact"
              hide-details
              :min="dateFrom"
            />
          </VCol>
          <VCol cols="12" sm="4">
            <VSelect
              v-model="format"
              :items="[
                { title: 'PDF', value: 'pdf' },
                { title: 'Excel (XLSX)', value: 'xlsx' },
                { title: 'CSV', value: 'csv' },
              ]"
              item-title="title"
              item-value="value"
              label="Export Format"
              variant="outlined"
              density="compact"
              hide-details
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Report Cards -->
    <VRow>
      <VCol
        v-for="report in reports"
        :key="report.key"
        cols="12"
        sm="6"
        lg="4"
      >
        <VCard elevation="0" border height="100%">
          <VCardText class="pa-5 d-flex flex-column h-100">
            <div class="d-flex align-center gap-3 mb-3">
              <VAvatar :color="report.color" variant="tonal" size="44" rounded>
                <VIcon :icon="report.icon" size="22" />
              </VAvatar>
              <div>
                <p class="text-body-2 font-weight-semibold mb-0">{{ report.title }}</p>
              </div>
            </div>
            <p class="text-body-2 text-medium-emphasis flex-1-1 mb-4">{{ report.description }}</p>
            <VBtn
              :color="report.color"
              variant="tonal"
              size="small"
              prepend-icon="tabler-download"
              :loading="generating === report.key"
              @click="queueReport(report.key)"
            >
              Generate Report
            </VBtn>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

  </VContainer>
</template>
