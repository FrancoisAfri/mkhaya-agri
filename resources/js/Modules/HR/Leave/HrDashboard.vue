<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Violation = {
  id: number
  employee_name: string
  violation_type: string
  description: string
  severity: string
  detected_at: string
}

type Anomaly = {
  employee_name: string
  type: string
  message: string
  severity: string
}

type Liability = {
  leave_type: string
  total_days: number
  estimated_cost: number | null
  employee_count: number
}

type BurnoutRisk = {
  employee_name: string
  risk_level: string
  days_without_leave: number
  pending_days: number
}

type ComplianceAlert = {
  type: string
  message: string
  count: number
  severity: string
}

defineProps<{
  stats: {
    total_employees: number
    on_leave_today: number
    policy_violations: number
    negative_balances: number
    pending_all: number
    compliance_score: number
  }
  violations: Violation[]
  anomalies: Anomaly[]
  liabilities: Liability[]
  burnoutRisks: BurnoutRisk[]
  complianceAlerts: ComplianceAlert[]
}>()

const severityColor = (s: string) => s === 'high' ? 'error' : s === 'medium' ? 'warning' : 'info'

function formatDate(val: string) {
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">

    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('hr.leave.index'))" />
        <div>
          <h4 class="text-h4">HR Leave Dashboard</h4>
          <p class="text-medium-emphasis mb-0">Organisation-wide leave health and compliance</p>
        </div>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" color="default" prepend-icon="tabler-report" @click="router.visit(route('hr.leave.reports'))">Reports</VBtn>
        <VBtn color="primary" prepend-icon="tabler-layout-list" @click="router.visit(route('hr.leave.policy-violations'))">Policy Violations</VBtn>
      </div>
    </div>

    <!-- Stat Cards -->
    <VRow class="mb-6">
      <VCol cols="6" lg="2">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VAvatar color="primary" variant="tonal" size="40" rounded class="mb-2">
              <VIcon icon="tabler-users" size="20" />
            </VAvatar>
            <h6 class="text-h6">{{ stats.total_employees }}</h6>
            <p class="text-caption text-medium-emphasis mb-0">Employees</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" lg="2">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VAvatar color="warning" variant="tonal" size="40" rounded class="mb-2">
              <VIcon icon="tabler-calendar-minus" size="20" />
            </VAvatar>
            <h6 class="text-h6">{{ stats.on_leave_today }}</h6>
            <p class="text-caption text-medium-emphasis mb-0">On Leave Today</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" lg="2">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VAvatar color="error" variant="tonal" size="40" rounded class="mb-2">
              <VIcon icon="tabler-shield-x" size="20" />
            </VAvatar>
            <h6 class="text-h6">{{ stats.policy_violations }}</h6>
            <p class="text-caption text-medium-emphasis mb-0">Violations</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" lg="2">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VAvatar color="error" variant="tonal" size="40" rounded class="mb-2">
              <VIcon icon="tabler-trending-down" size="20" />
            </VAvatar>
            <h6 class="text-h6">{{ stats.negative_balances }}</h6>
            <p class="text-caption text-medium-emphasis mb-0">Neg. Balances</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" lg="2">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VAvatar color="warning" variant="tonal" size="40" rounded class="mb-2">
              <VIcon icon="tabler-clock" size="20" />
            </VAvatar>
            <h6 class="text-h6">{{ stats.pending_all }}</h6>
            <p class="text-caption text-medium-emphasis mb-0">Pending</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" lg="2">
        <VCard elevation="0" border>
          <VCardText class="pa-3 text-center">
            <VAvatar :color="stats.compliance_score >= 80 ? 'success' : stats.compliance_score >= 60 ? 'warning' : 'error'" variant="tonal" size="40" rounded class="mb-2">
              <VIcon icon="tabler-shield-check" size="20" />
            </VAvatar>
            <h6 class="text-h6">{{ stats.compliance_score }}%</h6>
            <p class="text-caption text-medium-emphasis mb-0">Compliance</p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow>
      <!-- Compliance Alerts -->
      <VCol cols="12" lg="6">
        <VCard elevation="0" border class="mb-5">
          <VCardTitle class="pa-4 pb-2 d-flex align-center gap-2 text-body-1 font-weight-semibold">
            <VIcon icon="tabler-alert-circle" color="error" size="20" />
            Compliance Alerts
          </VCardTitle>
          <VDivider />
          <VList v-if="complianceAlerts.length" density="compact">
            <VListItem
              v-for="(a, i) in complianceAlerts"
              :key="i"
              :subtitle="a.message"
            >
              <template #title>
                <span class="text-body-2 font-weight-medium text-capitalize">{{ a.type.replace('_', ' ') }}</span>
              </template>
              <template #append>
                <div class="d-flex align-center gap-2">
                  <VChip :color="severityColor(a.severity)" size="x-small" variant="tonal">{{ a.count }}</VChip>
                </div>
              </template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-center py-6 text-medium-emphasis">
            <VIcon icon="tabler-shield-check" size="40" color="success" class="mb-2" />
            <p class="text-body-2">No compliance alerts.</p>
          </VCardText>
        </VCard>

        <!-- Policy Violations -->
        <VCard elevation="0" border class="mb-5">
          <VCardTitle class="pa-4 pb-2 d-flex align-center justify-space-between text-body-1 font-weight-semibold">
            Recent Policy Violations
            <VBtn variant="text" size="small" @click="router.visit(route('hr.leave.policy-violations'))">View All</VBtn>
          </VCardTitle>
          <VDivider />
          <VList v-if="violations.length" density="compact">
            <VListItem
              v-for="v in violations.slice(0, 5)"
              :key="v.id"
              :subtitle="v.description"
            >
              <template #title>
                <span class="text-body-2 font-weight-medium">{{ v.employee_name }}</span>
              </template>
              <template #append>
                <VChip :color="severityColor(v.severity)" size="x-small" variant="tonal">{{ v.violation_type }}</VChip>
              </template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-center py-6 text-medium-emphasis">
            <p class="text-body-2">No policy violations.</p>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Right Column -->
      <VCol cols="12" lg="6">

        <!-- Leave Liabilities -->
        <VCard elevation="0" border class="mb-5">
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Leave Liabilities</VCardTitle>
          <VDivider />
          <VList v-if="liabilities.length" density="compact">
            <VListItem
              v-for="l in liabilities"
              :key="l.leave_type"
              :subtitle="`${l.employee_count} employees · ${l.total_days.toFixed(1)} days pending`"
            >
              <template #title>
                <span class="text-body-2 font-weight-medium">{{ l.leave_type }}</span>
              </template>
              <template #append>
                <span v-if="l.estimated_cost" class="text-body-2 font-weight-bold text-error">
                  R{{ l.estimated_cost.toLocaleString() }}
                </span>
              </template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-center py-6 text-medium-emphasis">
            <p class="text-body-2">No liability data.</p>
          </VCardText>
        </VCard>

        <!-- Burnout Risks -->
        <VCard elevation="0" border>
          <VCardTitle class="pa-4 pb-2 d-flex align-center gap-2 text-body-1 font-weight-semibold">
            <VIcon icon="tabler-flame" color="error" size="20" />
            Burnout Risks
          </VCardTitle>
          <VDivider />
          <VList v-if="burnoutRisks.length" density="compact">
            <VListItem
              v-for="r in burnoutRisks"
              :key="r.employee_name"
              :subtitle="`${r.days_without_leave} days without leave · ${r.pending_days}d accrued`"
            >
              <template #title>
                <span class="text-body-2 font-weight-medium">{{ r.employee_name }}</span>
              </template>
              <template #append>
                <VChip :color="severityColor(r.risk_level)" size="x-small" variant="tonal" class="text-capitalize">{{ r.risk_level }}</VChip>
              </template>
            </VListItem>
          </VList>
          <VCardText v-else class="text-center py-6 text-medium-emphasis">
            <VIcon icon="tabler-heart-check" size="40" color="success" class="mb-2" />
            <p class="text-body-2">No burnout risks detected.</p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

  </VContainer>
</template>
