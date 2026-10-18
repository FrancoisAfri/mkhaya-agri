<script setup lang="ts">
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()
const u = (key: string, a?: Record<string, unknown>) => t('bureau.ui.' + key, a ?? {})
const k = (key: string, a?: Record<string, unknown>) => t('bureau.pay.' + key, a ?? {})
const props = defineProps<{
  company: Record<string, any>
  frequencies: string[]
  leave?: { available: boolean; active: boolean }
}>()

// Read through props so the template can stay terse; Leave Bureau is optional on any installation.
const leave = props.leave

const form = useForm({
  name: props.company.name, trading_name: props.company.trading_name, registration_number: props.company.registration_number,
  paye_reference: props.company.paye_reference, uif_reference: props.company.uif_reference, sdl_reference: props.company.sdl_reference,
  contact_name: props.company.contact_name, contact_email: props.company.contact_email, contact_phone: props.company.contact_phone,
  address: props.company.address, pay_frequency: props.company.pay_frequency, tax_year: props.company.tax_year, is_active: props.company.is_active, auto_email_payslips: props.company.auto_email_payslips, country: props.company.country,
  bank: props.company.bank, bank_account_name: props.company.bank_account_name, bank_account_number: props.company.bank_account_number, bank_branch_code: props.company.bank_branch_code, bank_user_code: props.company.bank_user_code,
})
function save() { form.put(route('payroll.bureau.companies.update', props.company.uuid)) }
function downloadIrp5() { window.open(route('payroll.bureau.irp5', props.company.uuid) + '?tax_year=' + (props.company.tax_year || ''), '_blank') }
function downloadEasyfile() { window.open(route('payroll.bureau.easyfile', props.company.uuid) + '?tax_year=' + (props.company.tax_year || ''), '_blank') }
</script>

<template>
  <div class="pa-4" style="max-width:820px;margin:auto">
    <div class="d-flex align-center gap-3 mb-4">
      <VBtn icon="tabler-arrow-left" aria-label="Back to the client list" class="row-action" variant="text" @click="router.get(route('payroll.bureau.dashboard'))" />
      <h4 class="text-h4 mb-0">{{ company.name }}</h4>
    </div>

    <VCard border>
      <VCardTitle>{{ k('company_details') }}</VCardTitle>
      <VCardText class="d-flex flex-column gap-3">
        <VTextField v-model="form.name" :label="k('company_name')" :error-messages="form.errors.name" />
        <VTextField v-model="form.trading_name" :label="k('trading_name')" />
        <div class="d-flex gap-2">
          <VTextField v-model="form.registration_number" :label="k('registration_no')" />
          <VSelect v-model="form.pay_frequency" :items="frequencies" :label="k('pay_frequency')" />
          <VTextField v-model.number="form.tax_year" type="number" :label="u('tax_year')" />
        </div>
        <div class="d-flex gap-2">
          <VTextField v-model="form.paye_reference" :label="k('paye_ref')" />
          <VTextField v-model="form.uif_reference" :label="k('uif_ref')" />
          <VTextField v-model="form.sdl_reference" :label="k('sdl_ref')" />
        </div>
        <div class="field-row">
          <VTextField v-model="form.contact_name" :label="k('contact_name')" />
          <VTextField v-model="form.contact_email" :label="k('contact_email')" :error-messages="form.errors.contact_email" />
          <VTextField v-model="form.contact_phone" :label="k('contact_phone')" />
        </div>
        <VTextarea v-model="form.address" :label="k('address')" rows="2" />
        <div class="d-flex flex-wrap gap-4">
          <VSwitch v-model="form.is_active" :label="u('active')" color="primary" hide-details />
          <VSwitch v-model="form.auto_email_payslips" :label="k('auto_email')" color="primary" hide-details />
        </div>

        <VDivider class="my-2" />
        <div class="text-subtitle-2">{{ k('banking') }}</div>
        <div class="field-row">
          <VSelect v-model="form.bank" :items="company.banks" item-title="title" item-value="value" :label="k('employer_bank')" :error-messages="form.errors.bank" />
          <VTextField v-model="form.bank_user_code" :label="k('acb_code')" />
        </div>
        <div class="d-flex gap-2">
          <VTextField v-model="form.bank_account_name" :label="k('account_name')" />
          <VTextField v-model="form.bank_account_number" :label="u('account_number')" />
          <VTextField v-model="form.bank_branch_code" :label="u('branch_code')" />
        </div>
      </VCardText>
      <VCardActions class="pa-3">
        <VSpacer />
        <VBtn color="primary" :loading="form.processing" @click="save">{{ u('save') }}</VBtn>
      </VCardActions>
    </VCard>

    <div class="d-flex flex-wrap ga-2 mt-4">
      <VBtn variant="tonal" prepend-icon="tabler-users" @click="router.get(route('payroll.bureau.employees.index', company.uuid))">{{ u('employees') }}</VBtn>
      <VBtn variant="tonal" prepend-icon="tabler-cash" @click="router.get(route('payroll.bureau.runs.index', company.uuid))">{{ k('pay_runs') }}</VBtn>
      <VBtn variant="tonal" prepend-icon="tabler-certificate" @click="downloadIrp5">{{ k('irp5') }}</VBtn>
      <VBtn variant="tonal" prepend-icon="tabler-file-download" @click="downloadEasyfile">{{ k('easyfile') }}</VBtn>
      <VBtn variant="tonal" prepend-icon="tabler-bolt" @click="router.get(route('payroll.bureau.payouts.index', company.uuid))">{{ k('realtime_payouts') }}</VBtn>
      <VBtn variant="tonal" prepend-icon="tabler-history" @click="router.get(route('payroll.bureau.audit.index', company.uuid))">{{ t('bureau.audit.link') }}</VBtn>
      <VBtn variant="tonal" prepend-icon="tabler-writing-sign" @click="router.get(route('payroll.bureau.companies.approval-steps', company.uuid))">{{ t('bureau.approval.page_title') }}</VBtn>

      <!-- Leave Bureau is a separate module. The button appears once this installation has it,
           and says plainly when this particular client has not been switched on for it. -->
      <VBtn
        v-if="leave?.available"
        variant="tonal"
        prepend-icon="tabler-beach"
        :color="leave.active ? undefined : 'secondary'"
        @click="router.get(route(leave.active ? 'leave.bureau.client' : 'leave.bureau.setup', company.uuid))"
      >
        {{ leave.active ? 'Leave Bureau' : 'Set up Leave Bureau' }}
      </VBtn>
    </div>
  </div>
</template>

<style scoped>
/*
 * Fields sharing a row keep an even split whatever is written beneath them.
 *
 * Plain flex children size to their content, so a long validation message under one field stretches
 * it and collapses its neighbour to a few characters. `flex: 1 1 12rem` fixes the split and lets the
 * row wrap to one field per line on a narrow screen; `min-width: 0` stops a long message forcing the
 * column open again.
 */
.field-row {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
  align-items: flex-start;
}

.field-row > * {
  flex: 1 1 12rem;
  min-width: 0;
}
</style>
