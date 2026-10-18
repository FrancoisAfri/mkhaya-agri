<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

/**
 * Form 1's header — the value of the works and the fee derived from it.
 *
 * Its own component because it is the one part of an account that is *stated* rather than
 * calculated, and because it has to be filled in before any stage can earn anything. An account
 * whose works value is zero produces a fee of zero, which reads like a broken page rather than an
 * unfinished one.
 */
const props = defineProps<{
  invoiceId: string
  feeAccount: {
    external_works: string | null
    structural_works: string | null
    other_works: string | null
    total_value_of_works: string | null
    contingency_allowance: string | null
    construction_completed_to_date: string | null
    payment_certificate: string | null
    payment_certificate_dated: string | null
    fee_percentage: string | null
    approved_fee: string | null
    adjusted_fee: string | null
  }
  currency: string
  editable: boolean
}>()

const form = useForm({
  external_works: props.feeAccount.external_works ?? '',
  structural_works: props.feeAccount.structural_works ?? '',
  other_works: props.feeAccount.other_works ?? '',
  contingency_allowance: props.feeAccount.contingency_allowance ?? '',
  construction_completed_to_date: props.feeAccount.construction_completed_to_date ?? '',
  payment_certificate: props.feeAccount.payment_certificate ?? '',
  payment_certificate_dated: props.feeAccount.payment_certificate_dated ?? '',
  // Held as a percentage on screen and sent as the proportion the engine works in.
  fee_percentage: props.feeAccount.fee_percentage ? String(Number(props.feeAccount.fee_percentage) * 100) : '',
  approved_fee: props.feeAccount.approved_fee ?? '',
  adjusted_fee: props.feeAccount.adjusted_fee ?? '',
})

function save() {
  form
    .transform(data => {
      // Blank means "not stated", which the server takes as null rather than zero.
      const out: Record<string, unknown> = Object.fromEntries(
        Object.entries(data).map(([key, value]) => [key, value === '' ? null : value]),
      )

      // The form asks for 8; the engine works in 0.08.
      out.fee_percentage = data.fee_percentage === '' ? null : Number(data.fee_percentage) / 100

      return out
    })
    .put(route('project-management.invoicing.fee_account.update', props.invoiceId), { preserveScroll: true })
}
</script>

<template>
  <VCard border>
    <VCardItem>
      <VCardTitle class="text-body-1">{{ t('project_management.invoicing.setup_title') }}</VCardTitle>
      <VCardSubtitle>{{ t('project_management.invoicing.setup_sub') }}</VCardSubtitle>
    </VCardItem>
    <VCardText>
      <VRow dense>
        <VCol cols="12" md="4">
          <VTextField
            v-model="form.external_works" inputmode="decimal" :disabled="!props.editable"
            :label="t('project_management.invoicing.external_works')" :prefix="props.currency"
            :error-messages="form.errors.external_works" variant="outlined" density="compact"
          />
        </VCol>
        <VCol cols="12" md="4">
          <VTextField
            v-model="form.structural_works" inputmode="decimal" :disabled="!props.editable"
            :label="t('project_management.invoicing.structural_works')" :prefix="props.currency"
            variant="outlined" density="compact"
          />
        </VCol>
        <VCol cols="12" md="4">
          <VTextField
            v-model="form.other_works" inputmode="decimal" :disabled="!props.editable"
            :label="t('project_management.invoicing.other_works')" :prefix="props.currency"
            variant="outlined" density="compact"
          />
        </VCol>

        <VCol cols="12" md="4">
          <VTextField
            :model-value="props.feeAccount.total_value_of_works ?? '0.00'"
            readonly disabled
            :label="t('project_management.invoicing.total_value_of_works')" :prefix="props.currency"
            :hint="t('project_management.invoicing.total_is_the_sum')" persistent-hint
            variant="outlined" density="compact"
          />
        </VCol>
        <VCol cols="12" md="4">
          <VTextField
            v-model="form.contingency_allowance" inputmode="decimal" :disabled="!props.editable"
            :label="t('project_management.invoicing.contingency_allowance')" :prefix="props.currency"
            variant="outlined" density="compact"
          />
        </VCol>
        <VCol cols="12" md="4">
          <VTextField
            v-model="form.construction_completed_to_date" inputmode="decimal" :disabled="!props.editable"
            :label="t('project_management.invoicing.completed_to_date')" :prefix="props.currency"
            :hint="t('project_management.invoicing.completed_hint')" persistent-hint
            variant="outlined" density="compact"
          />
        </VCol>

        <VCol cols="12" md="3">
          <VTextField
            v-model="form.fee_percentage" inputmode="decimal" suffix="%" :disabled="!props.editable"
            :label="t('project_management.invoicing.fee_percentage')"
            :error-messages="form.errors.fee_percentage" variant="outlined" density="compact"
          />
        </VCol>
        <VCol cols="12" md="3">
          <VTextField
            v-model="form.approved_fee" inputmode="decimal" :disabled="!props.editable"
            :label="t('project_management.invoicing.approved_fee')" :prefix="props.currency"
            :hint="t('project_management.invoicing.approved_fee_hint')" persistent-hint
            variant="outlined" density="compact"
          />
        </VCol>
        <VCol cols="12" md="3">
          <VTextField
            v-model="form.payment_certificate" :disabled="!props.editable"
            :label="t('project_management.invoicing.payment_certificate')"
            variant="outlined" density="compact"
          />
        </VCol>
        <VCol cols="12" md="3">
          <VTextField
            v-model="form.payment_certificate_dated" type="date" :disabled="!props.editable"
            :label="t('project_management.invoicing.certificate_dated')"
            variant="outlined" density="compact"
          />
        </VCol>

        <VCol cols="12" md="4">
          <VTextField
            v-model="form.adjusted_fee" inputmode="decimal" :disabled="!props.editable"
            :label="t('project_management.invoicing.adjusted_fee')" :prefix="props.currency"
            :hint="t('project_management.invoicing.adjusted_fee_hint')" persistent-hint
            variant="outlined" density="compact"
          />
        </VCol>
      </VRow>
    </VCardText>
    <VCardActions v-if="props.editable">
      <VSpacer />
      <VBtn color="primary" :loading="form.processing" @click="save">
        {{ t('project_management.actions.save') }}
      </VBtn>
    </VCardActions>
  </VCard>
</template>
