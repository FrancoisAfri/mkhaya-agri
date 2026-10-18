<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { computed } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Employee { id: number; name: string }
defineProps<{ employees: Employee[] }>()

const form = useForm({
  amount_requested: '',
  monthly_net_pay: '',
  repayment_month: '',
  reason: '',
  employment_status: '',
  is_permanent: false,
  probation_completed: false,
  is_suspended: false,
  notes: '',
})

const pctOfNet = computed(() => {
  if (!form.amount_requested || !form.monthly_net_pay) return null
  return ((Number(form.amount_requested) / Number(form.monthly_net_pay)) * 100).toFixed(1)
})

function submit() {
  form.post(route('staff-loans.advances.store'))
}
</script>

<template>
  <div class="pa-4" style="max-width:680px">
    <!-- Loading overlay -->
    <VOverlay v-if="form.processing" contained class="align-center justify-center" style="position: fixed; z-index: 9999;">
      <VProgressCircular indeterminate color="primary" size="64" />
    </VOverlay>

    <div class="mb-4">
      <h1 class="text-h5 font-weight-bold">{{ t('staff_advances_create.page_title') }}</h1>
      <p class="text-body-2 text-medium-emphasis">{{ t('staff_advances_create.description') }}</p>
    </div>

    <VForm @submit.prevent="submit">
      <VCard elevation="0" border class="mb-4">
        <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">{{ t('staff_advances_create.advance_details.title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow>
            <VCol cols="12" md="6">
              <VTextField
                v-model="form.amount_requested"
                :label="t('staff_advances_create.advance_details.amount_requested')"
                type="number"
                variant="outlined"
                density="compact"
                :error-messages="form.errors.amount_requested"
                :disabled="form.processing"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="form.monthly_net_pay"
                :label="t('staff_advances_create.advance_details.monthly_net_pay')"
                type="number"
                variant="outlined"
                density="compact"
                :error-messages="form.errors.monthly_net_pay"
                :hint="t('staff_advances_create.advance_details.net_pay_hint')"
                persistent-hint
                :disabled="form.processing"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model="form.repayment_month"
                :label="t('staff_advances_create.advance_details.repayment_month')"
                type="month"
                variant="outlined"
                density="compact"
                :error-messages="form.errors.repayment_month"
                :disabled="form.processing"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="form.reason"
                :label="t('staff_advances_create.advance_details.reason')"
                variant="outlined"
                density="compact"
                rows="3"
                :error-messages="form.errors.reason"
                :disabled="form.processing"
              />
            </VCol>
          </VRow>

          <VAlert v-if="pctOfNet" :type="Number(pctOfNet) > 30 ? 'error' : 'info'" variant="tonal" density="compact" class="mt-2">
            {{ t('staff_advances_create.percentage_alert', { pct: pctOfNet }) }}
            <span v-if="Number(pctOfNet) > 30">{{ t('staff_advances_create.exceeds_limit') }}</span>
          </VAlert>
        </VCardText>
      </VCard>

      <VCard elevation="0" border class="mb-4">
        <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">{{ t('staff_advances_create.employment_status.title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow>
            <VCol cols="12" md="6">
              <VSelect
                v-model="form.employment_status"
                :items="['permanent','contract','casual','probation']"
                :label="t('staff_advances_create.employment_status.status_label')"
                variant="outlined"
                density="compact"
                :disabled="form.processing"
              />
            </VCol>
            <VCol cols="12" md="6" class="d-flex align-center ga-4">
              <VCheckbox v-model="form.is_permanent" :label="t('staff_advances_create.employment_status.permanent')" density="compact" hide-details :disabled="form.processing" />
              <VCheckbox v-model="form.probation_completed" :label="t('staff_advances_create.employment_status.probation_completed')" density="compact" hide-details :disabled="form.processing" />
            </VCol>
          </VRow>
        </VCardText>
      </VCard>

      <div class="d-flex justify-end ga-3">
        <VBtn variant="outlined" :href="route('staff-loans.advances.index')" :disabled="form.processing">
          {{ t('staff_advances_create.cancel') }}
        </VBtn>
        <VBtn type="submit" color="info" :loading="form.processing" :disabled="form.processing">
          {{ t('staff_advances_create.submit') }}
        </VBtn>
      </div>
    </VForm>
  </div>
</template>
