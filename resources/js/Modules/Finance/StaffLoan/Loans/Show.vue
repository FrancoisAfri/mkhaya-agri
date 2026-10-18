<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  loan: Record<string, any>
  repayments: any[]
  approvals: any[]
  policyViolations: any[]
  affordabilityCheck: Record<string, any> | null
}>()

const approveDialog   = ref(false)
const rejectDialog    = ref(false)
const disburseDialog  = ref(false)
const repaymentDialog = ref(false)

const approveForm   = useForm({ level: 'finance', comments: '' })
const rejectForm    = useForm({ level: 'finance', reason: '' })
const disburseForm  = useForm({ amount_approved: props.loan.amount_requested })
const repayForm     = useForm({ amount: props.loan.monthly_installment ?? '', method: 'payroll' })

const statusColor  = (s: string) => ({ pending: 'warning', under_review: 'info', approved: 'success', rejected: 'error', active: 'primary', closed: 'grey', defaulted: 'error' }[s] ?? 'grey')
const riskColor    = (r: string) => ({ low: 'success', medium: 'warning', high: 'error', critical: 'error' }[r] ?? 'grey')
const repColor     = (s: string) => ({ pending: 'default', paid: 'success', partial: 'warning', overdue: 'error', waived: 'grey' }[s] ?? 'grey')
const sevColor     = (s: string) => ({ low: 'info', medium: 'warning', high: 'error', critical: 'error' }[s] ?? 'grey')

const approvalLevels = ['line_manager', 'hr', 'finance', 'executive']
const paymentMethods = ['payroll', 'bank_transfer', 'cash', 'eft']
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('staff_loans_show.page_title', { reference: loan.reference_number }) }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('staff_loans_show.employee', { employee: loan.employee, type: loan.loan_type ?? 'General Loan' }) }}</p>
      </div>
      <div class="d-flex ga-2 flex-wrap">
        <VChip :color="statusColor(loan.status)" variant="tonal">{{ loan.status.replace('_', ' ') }}</VChip>
        <VChip v-if="loan.risk_level" :color="riskColor(loan.risk_level)" size="small" variant="outlined">
          {{ t('staff_loans_show.risk', { level: loan.risk_level }) }}
        </VChip>
        <VBtn v-if="['pending','under_review'].includes(loan.status)" color="success" size="small" @click="approveDialog = true">
          {{ t('staff_loans_show.actions.approve') }}
        </VBtn>
        <VBtn v-if="['pending','under_review'].includes(loan.status)" color="error" variant="outlined" size="small" @click="rejectDialog = true">
          {{ t('staff_loans_show.actions.reject') }}
        </VBtn>
        <VBtn v-if="loan.status === 'approved'" color="primary" size="small" @click="disburseDialog = true">
          {{ t('staff_loans_show.actions.disburse') }}
        </VBtn>
        <VBtn v-if="loan.status === 'active'" color="teal" variant="outlined" size="small" @click="repaymentDialog = true">
          {{ t('staff_loans_show.actions.record_repayment') }}
        </VBtn>
      </div>
    </div>

    <VRow>
      <VCol cols="12" md="8">
        <!-- Loan Details -->
        <VCard elevation="0" border class="mb-4">
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">{{ t('staff_loans_show.loan_details.title') }}</VCardTitle>
          <VDivider />
          <VCardText>
            <VRow dense>
              <VCol cols="6" md="3">
                <div class="text-caption text-medium-emphasis">{{ t('staff_loans_show.loan_details.requested') }}</div>
                <div class="font-weight-semibold">R{{ Number(loan.amount_requested).toLocaleString() }}</div>
              </VCol>
              <VCol cols="6" md="3">
                <div class="text-caption text-medium-emphasis">{{ t('staff_loans_show.loan_details.approved') }}</div>
                <div class="font-weight-semibold">{{ loan.amount_approved ? 'R'+Number(loan.amount_approved).toLocaleString() : '—' }}</div>
              </VCol>
              <VCol cols="6" md="3">
                <div class="text-caption text-medium-emphasis">{{ t('staff_loans_show.loan_details.outstanding') }}</div>
                <div class="font-weight-semibold">R{{ Number(loan.outstanding_balance).toLocaleString() }}</div>
              </VCol>
              <VCol cols="6" md="3">
                <div class="text-caption text-medium-emphasis">{{ t('staff_loans_show.loan_details.monthly') }}</div>
                <div class="font-weight-semibold">{{ loan.monthly_installment ? 'R'+Number(loan.monthly_installment).toLocaleString() : '—' }}</div>
              </VCol>
              <VCol cols="6" md="3">
                <div class="text-caption text-medium-emphasis">{{ t('staff_loans_show.loan_details.term') }}</div>
                <div class="font-weight-semibold">{{ loan.term_months }} {{ t('staff_loans_show.loan_details.months') }}</div>
              </VCol>
              <VCol cols="6" md="3">
                <div class="text-caption text-medium-emphasis">{{ t('staff_loans_show.loan_details.interest_rate') }}</div>
                <div class="font-weight-semibold">{{ loan.interest_rate }}%</div>
              </VCol>
              <VCol cols="6" md="3">
                <div class="text-caption text-medium-emphasis">{{ t('staff_loans_show.loan_details.repayment_progress') }}</div>
                <div class="font-weight-semibold">{{ loan.repayment_progress }}%</div>
              </VCol>
              <VCol cols="6" md="3">
                <div class="text-caption text-medium-emphasis">{{ t('staff_loans_show.loan_details.next_payment') }}</div>
                <div class="font-weight-semibold">{{ loan.next_payment_date ?? '—' }}</div>
              </VCol>
              <VCol cols="12">
                <div class="text-caption text-medium-emphasis">{{ t('staff_loans_show.loan_details.purpose') }}</div>
                <div class="text-body-2">{{ loan.purpose }}</div>
              </VCol>
            </VRow>
            <div v-if="loan.overdue_installments > 0" class="mt-2">
              <VAlert type="error" density="compact" variant="tonal">
                {{ t('staff_loans_show.overdue_alert', { count: loan.overdue_installments }) }}
              </VAlert>
            </div>
          </VCardText>
        </VCard>

        <!-- Repayment Schedule -->
        <VCard v-if="repayments.length" elevation="0" border class="mb-4">
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">{{ t('staff_loans_show.repayment_schedule.title') }}</VCardTitle>
          <VDivider />
          <VTable density="compact">
            <thead>
              <tr>
                <th>{{ t('staff_loans_show.repayment_schedule.number') }}</th>
                <th>{{ t('staff_loans_show.repayment_schedule.due_date') }}</th>
                <th>{{ t('staff_loans_show.repayment_schedule.amount_due') }}</th>
                <th>{{ t('staff_loans_show.repayment_schedule.paid') }}</th>
                <th>{{ t('staff_loans_show.repayment_schedule.balance_after') }}</th>
                <th>{{ t('staff_loans_show.repayment_schedule.status') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="repayment in repayments" :key="repayment.id">
                <td>{{ repayment.installment_number }}</td>
                <td>{{ repayment.due_date }}</td>
                <td>R{{ Number(repayment.amount_due).toLocaleString() }}</td>
                <td>R{{ Number(repayment.amount_paid).toLocaleString() }}</td>
                <td>R{{ Number(repayment.balance_after).toLocaleString() }}</td>
                <td><VChip size="x-small" :color="repColor(repayment.status)" variant="tonal">{{ repayment.status }}</VChip></td>
              </tr>
            </tbody>
          </VTable>
        </VCard>

        <!-- Approval Trail -->
        <VCard v-if="approvals.length" elevation="0" border class="mb-4">
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">{{ t('staff_loans_show.approval_trail.title') }}</VCardTitle>
          <VDivider />
          <VTimeline density="compact" align="start" class="pa-4">
            <VTimelineItem v-for="(approval, i) in approvals" :key="i" :dot-color="approval.action === 'approved' ? 'success' : 'error'" size="small">
              <div class="text-body-2 font-weight-medium">{{ approval.approver }} · {{ approval.approval_level.replace('_',' ') }}</div>
              <div class="text-caption text-medium-emphasis">{{ approval.action }} · {{ new Date(approval.acted_at).toLocaleDateString() }}</div>
              <div v-if="approval.comments" class="text-caption mt-1 text-medium-emphasis">{{ approval.comments }}</div>
            </VTimelineItem>
          </VTimeline>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <!-- Affordability -->
        <VCard v-if="affordabilityCheck" elevation="0" border class="mb-4">
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">{{ t('staff_loans_show.affordability_check.title') }}</VCardTitle>
          <VDivider />
          <VCardText>
            <VAlert :type="affordabilityCheck.passed ? 'success' : 'error'" variant="tonal" density="compact" class="mb-3">
              {{ affordabilityCheck.passed ? t('staff_loans_show.affordability_check.passed') : t('staff_loans_show.affordability_check.failed', { reason: affordabilityCheck.failure_reason }) }}
            </VAlert>
            <div class="text-caption text-medium-emphasis">{{ t('staff_loans_show.affordability_check.monthly_net_pay') }}</div>
            <div class="font-weight-semibold mb-1">R{{ Number(affordabilityCheck.monthly_net_pay).toLocaleString() }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('staff_loans_show.affordability_check.existing_deductions') }}</div>
            <div class="font-weight-semibold mb-1">R{{ Number(affordabilityCheck.existing_deductions).toLocaleString() }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('staff_loans_show.affordability_check.proposed_deduction') }}</div>
            <div class="font-weight-semibold mb-1">R{{ Number(affordabilityCheck.proposed_deduction).toLocaleString() }}</div>
            <div class="text-caption text-medium-emphasis">{{ t('staff_loans_show.affordability_check.deduction_ratio') }}</div>
            <div class="font-weight-semibold">{{ (affordabilityCheck.affordability_ratio * 100).toFixed(1) }}%</div>
            <VProgressLinear :model-value="affordabilityCheck.affordability_ratio * 100" :color="affordabilityCheck.passed ? 'success' : 'error'" class="mt-2" rounded height="6" />
          </VCardText>
        </VCard>

        <!-- AI Flags -->
        <VCard v-if="loan.ai_flags?.length" elevation="0" border class="mb-4">
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">{{ t('staff_loans_show.ai_flags.title') }}</VCardTitle>
          <VDivider />
          <VList density="compact">
            <VListItem v-for="(flag, i) in loan.ai_flags" :key="i">
              <template #prepend><VIcon :color="sevColor(flag.severity)" size="16" class="mr-2">tabler-alert-triangle</VIcon></template>
              <VListItemTitle class="text-caption">{{ flag.description }}</VListItemTitle>
            </VListItem>
          </VList>
        </VCard>

        <!-- Policy Violations -->
        <VCard v-if="policyViolations.length" elevation="0" border>
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold text-error">{{ t('staff_loans_show.policy_violations.title') }}</VCardTitle>
          <VDivider />
          <VList density="compact">
            <VListItem v-for="violation in policyViolations" :key="violation.id">
              <template #prepend><VIcon :color="sevColor(violation.severity)" size="16" class="mr-2">tabler-shield-exclamation</VIcon></template>
              <VListItemTitle class="text-caption">{{ violation.description }}</VListItemTitle>
              <template #append><VChip size="x-small" :color="sevColor(violation.severity)" variant="tonal">{{ violation.severity }}</VChip></template>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>

    <!-- Approve Dialog -->
    <VDialog v-model="approveDialog" max-width="440">
      <VCard>
        <VCardTitle class="pa-4">{{ t('staff_loans_show.dialog.approve_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VSelect v-model="approveForm.level" :items="approvalLevels" :label="t('staff_loans_show.dialog.approval_level')" variant="outlined" density="compact" class="mb-3" />
          <VTextarea v-model="approveForm.comments" :label="t('staff_loans_show.dialog.approve_comments')" variant="outlined" density="compact" rows="2" />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="approveDialog = false">{{ t('staff_loans_show.dialog.cancel') }}</VBtn>
          <VBtn color="success" :loading="approveForm.processing" @click="approveForm.post(route('staff-loans.loans.approve', loan.uuid), { onSuccess: () => approveDialog = false })">
            {{ t('staff_loans_show.dialog.approve_btn') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Reject Dialog -->
    <VDialog v-model="rejectDialog" max-width="440">
      <VCard>
        <VCardTitle class="pa-4">{{ t('staff_loans_show.dialog.reject_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VSelect v-model="rejectForm.level" :items="approvalLevels" :label="t('staff_loans_show.dialog.reject_level')" variant="outlined" density="compact" class="mb-3" />
          <VTextarea v-model="rejectForm.reason" :label="t('staff_loans_show.dialog.reject_reason')" variant="outlined" density="compact" rows="3" :error-messages="rejectForm.errors.reason" />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="rejectDialog = false">{{ t('staff_loans_show.dialog.cancel') }}</VBtn>
          <VBtn color="error" :loading="rejectForm.processing" @click="rejectForm.post(route('staff-loans.loans.reject', loan.uuid), { onSuccess: () => rejectDialog = false })">
            {{ t('staff_loans_show.dialog.reject_btn') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Disburse Dialog -->
    <VDialog v-model="disburseDialog" max-width="400">
      <VCard>
        <VCardTitle class="pa-4">{{ t('staff_loans_show.dialog.disburse_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VTextField v-model="disburseForm.amount_approved" :label="t('staff_loans_show.dialog.amount_to_disburse')" type="number" variant="outlined" density="compact" />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="disburseDialog = false">{{ t('staff_loans_show.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="disburseForm.processing" @click="disburseForm.post(route('staff-loans.loans.disburse', loan.uuid), { onSuccess: () => disburseDialog = false })">
            {{ t('staff_loans_show.dialog.disburse_btn') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Repayment Dialog -->
    <VDialog v-model="repaymentDialog" max-width="400">
      <VCard>
        <VCardTitle class="pa-4">{{ t('staff_loans_show.dialog.repayment_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VTextField v-model="repayForm.amount" :label="t('staff_loans_show.dialog.amount_paid')" type="number" variant="outlined" density="compact" class="mb-3" />
          <VSelect v-model="repayForm.method" :items="paymentMethods" :label="t('staff_loans_show.dialog.payment_method')" variant="outlined" density="compact" />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="repaymentDialog = false">{{ t('staff_loans_show.dialog.cancel') }}</VBtn>
          <VBtn color="teal" :loading="repayForm.processing" @click="repayForm.post(route('staff-loans.loans.repayment', loan.uuid), { onSuccess: () => repaymentDialog = false })">
            {{ t('staff_loans_show.dialog.record_btn') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
