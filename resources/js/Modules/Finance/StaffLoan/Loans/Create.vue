<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { computed, watch } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface LoanType {
  id: number; name: string; min_amount: number; max_amount: number
  min_term_months: number; max_term_months: number; interest_rate: number; interest_type: string
  executive_approval_threshold: number
}
interface Employee { id: number; name: string }

const props = defineProps<{ loanTypes: LoanType[]; employees: Employee[] }>()

const form = useForm({
  loan_type_id: null as number | null,
  amount_requested: '',
  term_months: 12,
  purpose: '',
  employment_status: '',
  is_permanent: false,
  probation_completed: false,
  is_suspended: false,
  monthly_net_pay: '',
  notes: '',
})

const selectedType = computed(() => props.loanTypes.find(loanType => loanType.id === form.loan_type_id))

const monthlyEstimate = computed(() => {
  if (!form.amount_requested || !form.term_months) return 0
  const principal = Number(form.amount_requested)
  const months    = Number(form.term_months)
  if (!selectedType.value || selectedType.value.interest_type === 'none') {
    return (principal / months).toFixed(2)
  }
  const r = (selectedType.value.interest_rate / 100) / 12
  return (principal * (r * Math.pow(1 + r, months)) / (Math.pow(1 + r, months) - 1)).toFixed(2)
})

const requiresExecutive = computed(() =>
  selectedType.value?.executive_approval_threshold && Number(form.amount_requested) > selectedType.value.executive_approval_threshold
)

const affordabilityPct = computed(() => {
  if (!form.monthly_net_pay || !monthlyEstimate.value) return null
  return ((Number(monthlyEstimate.value) / Number(form.monthly_net_pay)) * 100).toFixed(1)
})

function submit() {
  form.post(route('staff-loans.loans.store'))
}
</script>

<template>
  <div class="pa-4" style="max-width:760px">
    <!-- Loading overlay -->
    <VOverlay v-if="form.processing" contained class="align-center justify-center" style="position: fixed; z-index: 9999;">
      <VProgressCircular indeterminate color="primary" size="64" />
    </VOverlay>

    <div class="mb-4">
      <h1 class="text-h5 font-weight-bold">{{ t('staff_loans_create.page_title') }}</h1>
      <p class="text-body-2 text-medium-emphasis">{{ t('staff_loans_create.description') }}</p>
    </div>

    <VForm @submit.prevent="submit">
      <VCard elevation="0" border class="mb-4">
        <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">{{ t('staff_loans_create.loan_details.title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow>
            <VCol cols="12" md="6">
              <VSelect
                v-model="form.loan_type_id"
                :items="loanTypes"
                item-title="name"
                item-value="id"
                :label="t('staff_loans_create.loan_details.loan_type')"
                variant="outlined"
                density="compact"
                clearable
                :error-messages="form.errors.loan_type_id"
                :disabled="form.processing"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="form.amount_requested"
                :label="t('staff_loans_create.loan_details.amount_requested')"
                type="number"
                variant="outlined"
                density="compact"
                :error-messages="form.errors.amount_requested"
                :hint="selectedType ? t('staff_loans_create.loan_details.max_amount', { amount: Number(selectedType.max_amount).toLocaleString() }) : ''"
                persistent-hint
                :disabled="form.processing"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="form.term_months"
                :label="t('staff_loans_create.loan_details.term_months')"
                type="number"
                variant="outlined"
                density="compact"
                :error-messages="form.errors.term_months"
                :hint="selectedType ? t('staff_loans_create.loan_details.term_range', { min: selectedType.min_term_months, max: selectedType.max_term_months }) : ''"
                persistent-hint
                :disabled="form.processing"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="form.monthly_net_pay"
                :label="t('staff_loans_create.loan_details.monthly_net_pay')"
                type="number"
                variant="outlined"
                density="compact"
                :error-messages="form.errors.monthly_net_pay"
                :hint="t('staff_loans_create.loan_details.net_pay_hint')"
                persistent-hint
                :disabled="form.processing"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="form.purpose"
                :label="t('staff_loans_create.loan_details.purpose')"
                variant="outlined"
                density="compact"
                rows="3"
                :error-messages="form.errors.purpose"
                :disabled="form.processing"
              />
            </VCol>
          </VRow>

          <!-- Estimates -->
          <VAlert v-if="monthlyEstimate && Number(monthlyEstimate) > 0" type="info" variant="tonal" density="compact" class="mt-2">
            {{ t('staff_loans_create.estimates.monthly_installment', { amount: Number(monthlyEstimate).toLocaleString() }) }}
            <span v-if="affordabilityPct">{{ t('staff_loans_create.estimates.percentage_of_net', { pct: affordabilityPct }) }}</span>
          </VAlert>
          <VAlert v-if="affordabilityPct && Number(affordabilityPct) > 25" type="warning" variant="tonal" density="compact" class="mt-2">
            {{ t('staff_loans_create.estimates.affordability_warning') }}
          </VAlert>
          <VAlert v-if="requiresExecutive" type="warning" variant="tonal" density="compact" class="mt-2">
            {{ t('staff_loans_create.estimates.executive_approval') }}
          </VAlert>
        </VCardText>
      </VCard>

      <VCard elevation="0" border class="mb-4">
        <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">{{ t('staff_loans_create.employment_status.title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow>
            <VCol cols="12" md="6">
              <VSelect
                v-model="form.employment_status"
                :items="['permanent','contract','casual','probation']"
                :label="t('staff_loans_create.employment_status.status_label')"
                variant="outlined"
                density="compact"
                :disabled="form.processing"
              />
            </VCol>
            <VCol cols="12" md="6" class="d-flex align-center ga-4">
              <VCheckbox v-model="form.is_permanent" :label="t('staff_loans_create.employment_status.permanent_employee')" density="compact" hide-details :disabled="form.processing" />
              <VCheckbox v-model="form.probation_completed" :label="t('staff_loans_create.employment_status.probation_completed')" density="compact" hide-details :disabled="form.processing" />
            </VCol>
          </VRow>
          <VAlert v-if="!form.is_permanent" type="warning" variant="tonal" density="compact" class="mt-2">
            {{ t('staff_loans_create.employment_status.not_permanent_warning') }}
          </VAlert>
          <VAlert v-if="!form.probation_completed" type="warning" variant="tonal" density="compact" class="mt-2">
            {{ t('staff_loans_create.employment_status.not_completed_probation_warning') }}
          </VAlert>
        </VCardText>
      </VCard>

      <VCard elevation="0" border class="mb-4">
        <VCardText class="pa-4">
          <VTextarea
            v-model="form.notes"
            :label="t('staff_loans_create.additional_notes')"
            variant="outlined"
            density="compact"
            rows="2"
            :disabled="form.processing"
          />
        </VCardText>
      </VCard>

      <div class="d-flex justify-end ga-3">
        <VBtn variant="outlined" :href="route('staff-loans.loans.index')" :disabled="form.processing">
          {{ t('staff_loans_create.cancel') }}
        </VBtn>
        <VBtn type="submit" color="primary" :loading="form.processing" :disabled="form.processing">
          {{ t('staff_loans_create.submit') }}
        </VBtn>
      </div>
    </VForm>
  </div>
</template>
