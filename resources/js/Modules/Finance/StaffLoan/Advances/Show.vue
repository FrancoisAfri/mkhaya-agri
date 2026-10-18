<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  advance: Record<string, any>
  repayments: any[]
  policyViolations: any[]
  affordabilityCheck: Record<string, any> | null
}>()

const approveDialog  = ref(false)
const rejectDialog   = ref(false)
const approveForm    = useForm({ comments: '' })
const rejectForm     = useForm({ reason: '' })

const statusColor  = (s: string) => ({ pending: 'warning', approved: 'success', rejected: 'error', disbursed: 'primary', settled: 'grey' }[s] ?? 'grey')
const riskColor    = (r: string) => ({ low: 'success', medium: 'warning', high: 'error', critical: 'error' }[r] ?? 'grey')
const sevColor     = (s: string) => ({ low: 'info', medium: 'warning', high: 'error', critical: 'error' }[s] ?? 'grey')
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ advance.reference_number }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('staff_advances_show.employee_info', { employee: advance.employee, month: advance.repayment_month }) }}</p>
      </div>
      <div class="d-flex ga-2 flex-wrap">
        <VChip :color="statusColor(advance.status)" variant="tonal">{{ advance.status }}</VChip>
        <VChip v-if="advance.risk_level" :color="riskColor(advance.risk_level)" size="small" variant="outlined">
          {{ t('staff_advances_show.risk', { level: advance.risk_level }) }}
        </VChip>
        <VBtn v-if="advance.status === 'pending'" color="success" size="small" @click="approveDialog = true">
          {{ t('staff_advances_show.actions.approve') }}
        </VBtn>
        <VBtn v-if="advance.status === 'pending'" color="error" variant="outlined" size="small" @click="rejectDialog = true">
          {{ t('staff_advances_show.actions.reject') }}
        </VBtn>
        <VBtn v-if="advance.status === 'approved'" color="primary" size="small"
          @click="$inertia.post(route('staff-loans.advances.disburse', advance.uuid))">
          {{ t('staff_advances_show.actions.disburse') }}
        </VBtn>
        <VBtn v-if="advance.status === 'disbursed'" color="teal" variant="outlined" size="small"
          @click="$inertia.post(route('staff-loans.advances.settle', advance.uuid))">
          {{ t('staff_advances_show.actions.settle') }}
        </VBtn>
      </div>
    </div>

    <VRow>
      <VCol cols="12" md="8">
        <VCard elevation="0" border class="mb-4">
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">{{ t('staff_advances_show.advance_details.title') }}</VCardTitle>
          <VDivider />
          <VCardText>
            <VRow dense>
              <VCol cols="6" md="4">
                <div class="text-caption text-medium-emphasis">{{ t('staff_advances_show.advance_details.requested') }}</div>
                <div class="font-weight-semibold">R{{ Number(advance.amount_requested).toLocaleString() }}</div>
              </VCol>
              <VCol cols="6" md="4">
                <div class="text-caption text-medium-emphasis">{{ t('staff_advances_show.advance_details.approved') }}</div>
                <div class="font-weight-semibold">{{ advance.amount_approved ? 'R'+Number(advance.amount_approved).toLocaleString() : '—' }}</div>
              </VCol>
              <VCol cols="6" md="4">
                <div class="text-caption text-medium-emphasis">{{ t('staff_advances_show.advance_details.percentage_of_net') }}</div>
                <div class="font-weight-semibold">{{ advance.percentage_of_net ?? '—' }}%</div>
              </VCol>
              <VCol cols="12">
                <div class="text-caption text-medium-emphasis">{{ t('staff_advances_show.advance_details.reason') }}</div>
                <div class="text-body-2">{{ advance.reason }}</div>
              </VCol>
            </VRow>
          </VCardText>
        </VCard>

        <VCard v-if="repayments.length" elevation="0" border class="mb-4">
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">{{ t('staff_advances_show.repayment.title') }}</VCardTitle>
          <VDivider />
          <VList density="compact">
            <VListItem v-for="repayment in repayments" :key="repayment.id">
              <VListItemTitle>{{ t('staff_advances_show.repayment.due', { date: repayment.due_date, amount: Number(repayment.amount_due).toLocaleString() }) }}</VListItemTitle>
              <template #append>
                <VChip size="x-small" :color="repayment.status === 'paid' ? 'success' : 'warning'" variant="tonal">
                  {{ repayment.status === 'paid' ? t('staff_advances_show.repayment.paid') : t('staff_advances_show.repayment.pending') }}
                </VChip>
              </template>
            </VListItem>
          </VList>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard v-if="affordabilityCheck" elevation="0" border class="mb-4">
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">{{ t('staff_advances_show.affordability.title') }}</VCardTitle>
          <VDivider />
          <VCardText>
            <VAlert :type="affordabilityCheck.passed ? 'success' : 'error'" variant="tonal" density="compact" class="mb-2">
              {{ affordabilityCheck.passed ? t('staff_advances_show.affordability.passed') : t('staff_advances_show.affordability.failed', { reason: affordabilityCheck.failure_reason }) }}
            </VAlert>
            <div class="text-caption text-medium-emphasis">{{ t('staff_advances_show.affordability.percentage_of_net') }}</div>
            <div class="font-weight-semibold mb-1">{{ (affordabilityCheck.affordability_ratio * 100).toFixed(1) }}%</div>
            <VProgressLinear :model-value="affordabilityCheck.affordability_ratio * 100" :color="affordabilityCheck.passed ? 'success' : 'error'" rounded height="6" />
          </VCardText>
        </VCard>

        <VCard v-if="policyViolations.length" elevation="0" border>
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold text-error">{{ t('staff_advances_show.policy_violations.title') }}</VCardTitle>
          <VDivider />
          <VList density="compact">
            <VListItem v-for="violation in policyViolations" :key="violation.id">
              <VListItemTitle class="text-caption">{{ violation.description }}</VListItemTitle>
              <template #append><VChip size="x-small" :color="sevColor(violation.severity)" variant="tonal">{{ violation.severity }}</VChip></template>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="approveDialog" max-width="420">
      <VCard>
        <VCardTitle class="pa-4">{{ t('staff_advances_show.dialog.approve_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VTextarea v-model="approveForm.comments" :label="t('staff_advances_show.dialog.approve_comments')" variant="outlined" density="compact" rows="2" />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="approveDialog = false">{{ t('staff_advances_show.dialog.cancel') }}</VBtn>
          <VBtn color="success" :loading="approveForm.processing" @click="approveForm.post(route('staff-loans.advances.approve', advance.uuid), { onSuccess: () => approveDialog = false })">
            {{ t('staff_advances_show.dialog.approve_btn') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="rejectDialog" max-width="420">
      <VCard>
        <VCardTitle class="pa-4">{{ t('staff_advances_show.dialog.reject_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VTextarea v-model="rejectForm.reason" :label="t('staff_advances_show.dialog.reject_reason')" variant="outlined" density="compact" rows="3" :error-messages="rejectForm.errors.reason" />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="rejectDialog = false">{{ t('staff_advances_show.dialog.cancel') }}</VBtn>
          <VBtn color="error" :loading="rejectForm.processing" @click="rejectForm.post(route('staff-loans.advances.reject', advance.uuid), { onSuccess: () => rejectDialog = false })">
            {{ t('staff_advances_show.dialog.reject_btn') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
