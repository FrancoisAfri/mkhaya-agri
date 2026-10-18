<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface HeadcountRow {
  department: string
  headcount: number
}

interface StatusRow {
  status: string
  count: number
}

interface PayrollMonth {
  month: string
  total_gross: number
  total_net: number
  employee_count: number
}

interface LeaveStats {
  total_applications: number
  total_days: number
  approved: number
  pending: number
  rejected: number
}

interface AttendanceStats {
  avg_score: number
  avg_attendance_rate: number
  high_performers: number
  at_risk: number
  total_scored: number
}

interface RecruitmentStats {
  open_jobs: number
  applications: Record<string, number>
  shortlists: number
  active_requisitions: number
}

interface Props {
  headcount: HeadcountRow[]
  statusBreakdown: StatusRow[]
  payrollTrend: PayrollMonth[]
  leaveStats: LeaveStats
  attendanceStats: AttendanceStats
  recruitmentStats: RecruitmentStats
}

const props = defineProps<Props>()

const totalEmployees = computed(() =>
  props.headcount.reduce((sum, row) => sum + row.headcount, 0),
)

const maxHeadcount = computed(() =>
  Math.max(...props.headcount.map(r => r.headcount), 1),
)

const maxNet = computed(() =>
  Math.max(...props.payrollTrend.map(r => r.total_net), 1),
)

function barHeight(value: number): string {
  return `${(value / maxNet.value) * 120 + 8}px`
}

function formatCurrency(value: number): string {
  return new Intl.NumberFormat('en-ZA', {
    style: 'currency',
    currency: 'ZAR',
    maximumFractionDigits: 0,
  }).format(value)
}

function formatShortMonth(month: string): string {
  const d = new Date(month + '-01')
  return d.toLocaleDateString('en-ZA', { month: 'short' })
}

const statusChipColor: Record<string, string> = {
  active: 'success',
  pre_boarding: 'warning',
  onboarding: 'info',
  terminated: 'error',
  resigned: 'default',
}

function statusColor(status: string): string {
  return statusChipColor[status] ?? 'default'
}

function statusLabel(status: string): string {
  return t(`hr_dashboard.workforce_status.statuses.${status}`)
}

const totalApplications = computed(() =>
  Object.values(props.recruitmentStats.applications).reduce((a, b) => a + b, 0),
)
</script>

<template>
  <div class="pa-6">
    <div class="mb-6">
      <h4 class="text-h4 font-weight-bold mb-1">{{ t('hr_dashboard.page_title') }}</h4>
      <p class="text-medium-emphasis mb-0">{{ t('hr_dashboard.subtitle') }}</p>
    </div>

    <VRow class="mb-6">
      <VCol cols="12" sm="6" md="3">
        <VCard elevation="0" border>
          <VCardText class="d-flex align-center gap-x-4">
            <VAvatar size="52" rounded color="primary" variant="tonal">
              <VIcon icon="tabler-account-group" size="28" />
            </VAvatar>
            <div>
              <div class="text-h5 font-weight-bold">{{ totalEmployees }}</div>
              <div class="text-body-2 text-medium-emphasis">{{ t('hr_dashboard.kpi.total_employees') }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" sm="6" md="3">
        <VCard elevation="0" border>
          <VCardText class="d-flex align-center gap-x-4">
            <VAvatar size="52" rounded color="success" variant="tonal">
              <VIcon icon="tabler-clock-check-outline" size="28" />
            </VAvatar>
            <div>
              <div class="text-h5 font-weight-bold">{{ attendanceStats.avg_attendance_rate }}%</div>
              <div class="text-body-2 text-medium-emphasis">{{ t('hr_dashboard.kpi.avg_attendance_rate') }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" sm="6" md="3">
        <VCard elevation="0" border>
          <VCardText class="d-flex align-center gap-x-4">
            <VAvatar size="52" rounded color="info" variant="tonal">
              <VIcon icon="tabler-briefcase-outline" size="28" />
            </VAvatar>
            <div>
              <div class="text-h5 font-weight-bold">{{ recruitmentStats.open_jobs }}</div>
              <div class="text-body-2 text-medium-emphasis">{{ t('hr_dashboard.kpi.open_jobs') }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" sm="6" md="3">
        <VCard elevation="0" border>
          <VCardText class="d-flex align-center gap-x-4">
            <VAvatar size="52" rounded color="warning" variant="tonal">
              <VIcon icon="tabler-calendar-clock" size="28" />
            </VAvatar>
            <div>
              <div class="text-h5 font-weight-bold">{{ leaveStats.pending }}</div>
              <div class="text-body-2 text-medium-emphasis">{{ t('hr_dashboard.kpi.pending_leave') }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow class="mb-6">
      <VCol cols="12" md="6">
        <VCard elevation="0" border height="100%">
          <VCardText>
            <p class="text-subtitle-1 font-weight-semibold mb-4">{{ t('hr_dashboard.headcount.title') }}</p>

            <div v-if="headcount.length" class="d-flex flex-column gap-3">
              <div v-for="row in headcount" :key="row.department" class="d-flex align-center gap-3">
                <span class="text-body-2" style="min-width: 160px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ row.department }}</span>
                <VProgressLinear :model-value="(row.headcount / maxHeadcount) * 100" height="8" rounded color="primary" class="flex-1" />
                <span class="text-body-2 font-weight-medium" style="min-width: 28px; text-align: right;">{{ row.headcount }}</span>
              </div>
            </div>

            <p v-else class="text-medium-emphasis text-body-2 mb-0">{{ t('hr_dashboard.headcount.no_data') }}</p>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="6">
        <VCard elevation="0" border height="100%">
          <VCardText>
            <p class="text-subtitle-1 font-weight-semibold mb-4">{{ t('hr_dashboard.payroll.title') }}</p>

            <div v-if="payrollTrend.length">
              <div class="d-flex align-end gap-x-2" style="height: 136px;">
                <VTooltip v-for="month in payrollTrend" :key="month.month"
                  :text="`${formatShortMonth(month.month)}: ${formatCurrency(month.total_net)}`" location="top">
                  <template #activator="{ props: tp }">
                    <div v-bind="tp" class="d-flex flex-column align-center flex-1">
                      <span class="text-caption text-medium-emphasis mb-1" style="font-size: 10px;">{{ formatCurrency(month.total_net) }}</span>
                      <div class="rounded-t w-100" :style="{ height: barHeight(month.total_net), background: 'rgb(var(--v-theme-primary))', minWidth: '20px' }" />
                      <span class="text-caption text-medium-emphasis mt-1">{{ formatShortMonth(month.month) }}</span>
                    </div>
                  </template>
                </VTooltip>
              </div>
            </div>

            <p v-else class="text-medium-emphasis text-body-2 mb-0">{{ t('hr_dashboard.payroll.no_data') }}</p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow class="mb-6">
      <VCol cols="12" md="4">
        <VCard elevation="0" border height="100%">
          <VCardText>
            <p class="text-subtitle-1 font-weight-semibold mb-4">{{ t('hr_dashboard.workforce_status.title') }}</p>

            <VTable density="compact">
              <thead>
                <tr>
                  <th>{{ t('hr_dashboard.workforce_status.table.status') }}</th>
                  <th class="text-end">{{ t('hr_dashboard.workforce_status.table.count') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in statusBreakdown" :key="row.status">
                  <td><VChip :color="statusColor(row.status)" size="small" variant="tonal">{{ statusLabel(row.status) }}</VChip></td>
                  <td class="text-end font-weight-medium">{{ row.count }}</td>
                </tr>
                <tr v-if="!statusBreakdown.length">
                  <td colspan="2" class="text-center text-medium-emphasis py-4">{{ t('hr_dashboard.workforce_status.no_data') }}</td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard elevation="0" border height="100%">
          <VCardText>
            <p class="text-subtitle-1 font-weight-semibold mb-4">{{ t('hr_dashboard.leave_statistics.title') }}</p>

            <div class="d-flex justify-space-between align-center py-2">
              <span class="text-body-2">{{ t('hr_dashboard.leave_statistics.approved') }}</span>
              <VChip color="success" size="small" variant="tonal">{{ leaveStats.approved }}</VChip>
            </div>
            <VDivider />
            <div class="d-flex justify-space-between align-center py-2">
              <span class="text-body-2">{{ t('hr_dashboard.leave_statistics.pending') }}</span>
              <VChip color="warning" size="small" variant="tonal">{{ leaveStats.pending }}</VChip>
            </div>
            <VDivider />
            <div class="d-flex justify-space-between align-center py-2">
              <span class="text-body-2">{{ t('hr_dashboard.leave_statistics.rejected') }}</span>
              <VChip color="error" size="small" variant="tonal">{{ leaveStats.rejected }}</VChip>
            </div>
            <VDivider class="my-3" />
            <div class="d-flex justify-space-between align-center py-1">
              <span class="text-body-2 text-medium-emphasis">{{ t('hr_dashboard.leave_statistics.total_applications') }}</span>
              <span class="text-body-2 font-weight-bold">{{ leaveStats.total_applications }}</span>
            </div>
            <div class="d-flex justify-space-between align-center py-1">
              <span class="text-body-2 text-medium-emphasis">{{ t('hr_dashboard.leave_statistics.total_days') }}</span>
              <span class="text-body-2 font-weight-bold">{{ leaveStats.total_days }}</span>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard elevation="0" border height="100%">
          <VCardText>
            <p class="text-subtitle-1 font-weight-semibold mb-4">{{ t('hr_dashboard.recruitment.title') }}</p>

            <div class="d-flex align-center gap-x-4 mb-4">
              <div>
                <div class="text-h4 font-weight-bold text-primary">{{ recruitmentStats.open_jobs }}</div>
                <div class="text-body-2 text-medium-emphasis">{{ t('hr_dashboard.recruitment.open_jobs') }}</div>
              </div>
              <VDivider vertical class="mx-2" style="height: 40px;" />
              <div>
                <div class="text-h5 font-weight-bold">{{ recruitmentStats.active_requisitions }}</div>
                <div class="text-body-2 text-medium-emphasis">{{ t('hr_dashboard.recruitment.active_requisitions') }}</div>
              </div>
            </div>

            <VDivider class="mb-3" />
            <p class="text-caption text-medium-emphasis text-uppercase mb-2">{{ t('hr_dashboard.recruitment.applications_by_status') }}</p>

            <div v-if="totalApplications > 0" class="d-flex flex-column gap-2">
              <div v-for="(count, status) in recruitmentStats.applications" :key="status" class="d-flex justify-space-between align-center">
                <span class="text-body-2">{{ statusLabel(String(status)) }}</span>
                <VChip size="small" color="primary" variant="tonal">{{ count }}</VChip>
              </div>
            </div>
            <p v-else class="text-body-2 text-medium-emphasis">{{ t('hr_dashboard.recruitment.no_applications') }}</p>

            <VDivider class="my-3" />
            <div class="d-flex justify-space-between align-center">
              <span class="text-body-2 text-medium-emphasis">{{ t('hr_dashboard.recruitment.shortlists') }}</span>
              <VChip color="info" size="small" variant="tonal">{{ recruitmentStats.shortlists }}</VChip>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow>
      <VCol cols="12">
        <VCard elevation="0" border>
          <VCardText>
            <p class="text-subtitle-1 font-weight-semibold mb-4">{{ t('hr_dashboard.attendance.title') }}</p>

            <VAlert v-if="attendanceStats.total_scored === 0" type="info" variant="tonal" class="mb-4">
              {{ t('hr_dashboard.attendance.no_scores') }}
            </VAlert>

            <template v-else>
              <VRow>
                <VCol cols="12" sm="6" md="3">
                  <div class="text-center pa-3">
                    <div class="text-h4 font-weight-bold mb-1">{{ attendanceStats.avg_score }}</div>
                    <div class="text-body-2 text-medium-emphasis mb-2">{{ t('hr_dashboard.attendance.avg_score') }}</div>
                    <VProgressLinear :model-value="attendanceStats.avg_score" :max="100" height="6" rounded color="primary" />
                  </div>
                </VCol>
                <VCol cols="12" sm="6" md="3">
                  <div class="text-center pa-3">
                    <div class="text-h4 font-weight-bold mb-1">{{ attendanceStats.avg_attendance_rate }}%</div>
                    <div class="text-body-2 text-medium-emphasis mb-2">{{ t('hr_dashboard.attendance.avg_attendance_rate') }}</div>
                    <VProgressLinear :model-value="attendanceStats.avg_attendance_rate" :max="100" height="6" rounded color="success" />
                  </div>
                </VCol>
                <VCol cols="12" sm="6" md="3">
                  <div class="text-center pa-3">
                    <div class="text-h4 font-weight-bold text-success mb-1">{{ attendanceStats.high_performers }}</div>
                    <div class="text-body-2 text-medium-emphasis mb-2">{{ t('hr_dashboard.attendance.high_performers') }}</div>
                    <VProgressLinear :model-value="attendanceStats.total_scored > 0 ? (attendanceStats.high_performers / attendanceStats.total_scored) * 100 : 0" :max="100" height="6" rounded color="success" />
                  </div>
                </VCol>
                <VCol cols="12" sm="6" md="3">
                  <div class="text-center pa-3">
                    <div class="text-h4 font-weight-bold text-error mb-1">{{ attendanceStats.at_risk }}</div>
                    <div class="text-body-2 text-medium-emphasis mb-2">{{ t('hr_dashboard.attendance.at_risk') }}</div>
                    <VProgressLinear :model-value="attendanceStats.total_scored > 0 ? (attendanceStats.at_risk / attendanceStats.total_scored) * 100 : 0" :max="100" height="6" rounded color="error" />
                  </div>
                </VCol>
              </VRow>

              <VDivider class="mt-2 mb-3" />
              <p class="text-caption text-medium-emphasis text-end mb-0">
                {{ t('hr_dashboard.attendance.based_on', { count: attendanceStats.total_scored }) }}
              </p>
            </template>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
