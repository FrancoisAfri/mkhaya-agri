<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type LeaveConfig = {
  allow_annual_leave_credit: boolean | null
  allow_sick_leave_credit: boolean | null
  show_non_employees_in_leave_module: boolean | null
  require_managers_approval: boolean | null
  all_managers_to_approve: boolean | null
  require_department_head_approval: boolean | null
  require_hr_approval: boolean | null
  require_payroll_approval: boolean | null
  require_delegation_approval: boolean | null
  limit_administration_to_assigned_divisions: boolean | null
  number_of_days_until_escalation: number | null
  document_compulsory_on_study_leave_application: boolean | null
  document_compulsory_when_two_sick_leave_8_weeks: boolean | null
  notify_hr_with_application: boolean | null
  preferred_communication_method: number | null
  notify_employee_about_applications_submitted: boolean | null
  allow_sick_negative_days: number | null
  allow_annual_negative_days: number | null
  number_of_days_to_remind_manager: number | null
  number_of_days_before_automate_application: number | null
  allow_unpaid_leave_when_annual_done: boolean | null
  balance_as_of_enabled: boolean | null
  allow_employee_payout_requests: boolean | null
  birthday_leave_enabled: boolean | null
  birthday_leave_days: number | null
}

const props = defineProps<{ config: LeaveConfig }>()

const form = useForm({
  require_managers_approval:                    props.config.require_managers_approval ?? false,
  all_managers_to_approve:                      props.config.all_managers_to_approve ?? false,
  require_department_head_approval:             props.config.require_department_head_approval ?? false,
  require_hr_approval:                          props.config.require_hr_approval ?? false,
  require_payroll_approval:                     props.config.require_payroll_approval ?? false,
  require_delegation_approval:                  props.config.require_delegation_approval ?? false,
  number_of_days_until_escalation:              props.config.number_of_days_until_escalation ?? 3,
  number_of_days_to_remind_manager:             props.config.number_of_days_to_remind_manager ?? 0,
  number_of_days_before_automate_application:   props.config.number_of_days_before_automate_application ?? 0,
  allow_annual_leave_credit:                    props.config.allow_annual_leave_credit ?? true,
  allow_sick_leave_credit:                      props.config.allow_sick_leave_credit ?? true,
  allow_annual_negative_days:                   props.config.allow_annual_negative_days ?? 0,
  allow_sick_negative_days:                     props.config.allow_sick_negative_days ?? 0,
  allow_unpaid_leave_when_annual_done:          props.config.allow_unpaid_leave_when_annual_done ?? false,
  document_compulsory_on_study_leave_application:  props.config.document_compulsory_on_study_leave_application ?? false,
  document_compulsory_when_two_sick_leave_8_weeks: props.config.document_compulsory_when_two_sick_leave_8_weeks ?? false,
  notify_hr_with_application:                   props.config.notify_hr_with_application ?? true,
  notify_employee_about_applications_submitted: props.config.notify_employee_about_applications_submitted ?? true,
  preferred_communication_method:               props.config.preferred_communication_method ?? 0,
  show_non_employees_in_leave_module:           props.config.show_non_employees_in_leave_module ?? false,
  limit_administration_to_assigned_divisions:   props.config.limit_administration_to_assigned_divisions ?? false,
  balance_as_of_enabled:                        props.config.balance_as_of_enabled ?? false,
  allow_employee_payout_requests:               props.config.allow_employee_payout_requests ?? false,
  birthday_leave_enabled:                       props.config.birthday_leave_enabled ?? false,
  birthday_leave_days:                          props.config.birthday_leave_days ?? 1,
})

const communicationOptions = [
  { title: t('leave_config.communication_email'), value: 0 },
  { title: t('leave_config.communication_sms'), value: 1 },
  { title: t('leave_config.communication_both'), value: 2 },
]

const submit = () => {
  form.patch('/leave-management/setup/configuration')
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow justify="center">
      <VCol cols="12" lg="9">

        <!-- Header -->
        <VCard class="mb-4">
          <VCardText class="py-5 d-flex align-center justify-space-between flex-wrap gap-3">
            <div>
              <div class="text-overline text-medium-emphasis">{{ t('leave.setup_label') }}</div>
              <h4 class="text-h4">{{ t('leave.module_config') }}</h4>
              <p class="text-medium-emphasis mb-0">{{ t('leave_config.subtitle') }}</p>
            </div>
            <VBtn
              color="primary"
              variant="tonal"
              prepend-icon="tabler-calendar-clock"
              href="/leave-management/setup/scheduled-reports"
            >
              {{ t('leave.manage_scheduled_reports') }}
            </VBtn>
          </VCardText>
        </VCard>

        <VForm @submit.prevent="submit">

          <!-- Approval Workflow -->
          <VCard class="mb-4">
            <VCardTitle class="pa-4 pb-2 text-subtitle-1">
              <VIcon icon="ri-flow-chart" class="me-2" />{{ t('leave.approval_workflow') }}
            </VCardTitle>
            <VDivider />
            <VCardText class="pa-4">
              <VRow>
                <VCol cols="12" sm="6">
                  <VSwitch v-model="form.require_managers_approval" :label="t('leave_config.require_manager_approval')" color="primary" density="compact" hide-details class="mb-3" />
                  <VSwitch v-model="form.all_managers_to_approve" :label="t('leave_config.all_managers_approve')" color="primary" density="compact" hide-details :disabled="!form.require_managers_approval" class="mb-3" />
                  <VSwitch v-model="form.require_department_head_approval" :label="t('leave_config.require_dept_head_approval')" color="primary" density="compact" hide-details class="mb-3" />
                  <VSwitch v-model="form.require_hr_approval" :label="t('leave_config.require_hr_approval')" color="primary" density="compact" hide-details class="mb-3" />
                  <VSwitch v-model="form.require_payroll_approval" :label="t('leave_config.require_payroll_approval')" color="primary" density="compact" hide-details class="mb-3" />
                  <VSwitch v-model="form.require_delegation_approval" :label="t('leave_config.require_delegation_approval')" color="warning" density="compact" hide-details />
                  <p v-if="form.require_delegation_approval" class="text-caption text-medium-emphasis mt-1 mb-0" v-html="t('leave_config.delegation_approval_desc')" />
                </VCol>
                <VCol cols="12" sm="6">
                  <VTextField v-model.number="form.number_of_days_until_escalation" :label="t('leave_config.days_until_escalation')" type="number" min="0" :error-messages="form.errors.number_of_days_until_escalation" variant="outlined" density="comfortable" :hint="t('leave_config.escalation_hint')" persistent-hint class="mb-4" />
                  <VTextField v-model.number="form.number_of_days_to_remind_manager" :label="t('leave_config.days_before_reminder')" type="number" min="0" :error-messages="form.errors.number_of_days_to_remind_manager" variant="outlined" density="comfortable" :hint="t('leave_config.reminder_hint')" persistent-hint class="mb-4" />
                  <VTextField v-model.number="form.number_of_days_before_automate_application" :label="t('leave_config.days_before_auto_approve')" type="number" min="0" :error-messages="form.errors.number_of_days_before_automate_application" variant="outlined" density="comfortable" :hint="t('leave_config.auto_approve_hint')" persistent-hint />
                </VCol>
              </VRow>
            </VCardText>
          </VCard>

          <!-- Leave Balance Rules -->
          <VCard class="mb-4">
            <VCardTitle class="pa-4 pb-2 text-subtitle-1">
              <VIcon icon="ri-scales-line" class="me-2" />{{ t('leave.leave_balance_rules') }}
            </VCardTitle>
            <VDivider />
            <VCardText class="pa-4">
              <VRow>
                <VCol cols="12" sm="6">
                  <VSwitch v-model="form.allow_annual_leave_credit" :label="t('leave_config.allow_annual_credit')" color="success" density="compact" hide-details class="mb-3" />
                  <VSwitch v-model="form.allow_sick_leave_credit" :label="t('leave_config.allow_sick_credit')" color="success" density="compact" hide-details class="mb-3" />
                  <VSwitch v-model="form.allow_unpaid_leave_when_annual_done" :label="t('leave_config.allow_unpaid_when_exhausted')" color="warning" density="compact" hide-details />
                </VCol>
                <VCol cols="12" sm="6">
                  <VTextField v-model.number="form.allow_annual_negative_days" :label="t('leave_config.annual_negative_days')" type="number" min="0" :error-messages="form.errors.allow_annual_negative_days" variant="outlined" density="comfortable" :hint="t('leave_config.annual_negative_hint')" persistent-hint class="mb-4" />
                  <VTextField v-model.number="form.allow_sick_negative_days" :label="t('leave_config.sick_negative_days')" type="number" min="0" :error-messages="form.errors.allow_sick_negative_days" variant="outlined" density="comfortable" :hint="t('leave_config.sick_negative_hint')" persistent-hint />
                </VCol>
              </VRow>
            </VCardText>
          </VCard>

          <!-- Document Requirements -->
          <VCard class="mb-4">
            <VCardTitle class="pa-4 pb-2 text-subtitle-1">
              <VIcon icon="ri-file-text-line" class="me-2" />{{ t('leave.document_requirements') }}
            </VCardTitle>
            <VDivider />
            <VCardText class="pa-4">
              <VSwitch v-model="form.document_compulsory_on_study_leave_application" :label="t('leave_config.doc_compulsory_study')" color="warning" density="compact" hide-details class="mb-3" />
              <VSwitch v-model="form.document_compulsory_when_two_sick_leave_8_weeks" :label="t('leave_config.doc_compulsory_two_sick')" color="warning" density="compact" hide-details />
            </VCardText>
          </VCard>

          <!-- Notifications -->
          <VCard class="mb-4">
            <VCardTitle class="pa-4 pb-2 text-subtitle-1">
              <VIcon icon="ri-notification-3-line" class="me-2" />{{ t('leave.notifications_section') }}
            </VCardTitle>
            <VDivider />
            <VCardText class="pa-4">
              <VRow>
                <VCol cols="12" sm="6">
                  <VSwitch v-model="form.notify_hr_with_application" :label="t('leave_config.notify_hr_submission')" color="primary" density="compact" hide-details class="mb-3" />
                  <VSwitch v-model="form.notify_employee_about_applications_submitted" :label="t('leave_config.notify_employee_status')" color="primary" density="compact" hide-details />
                </VCol>
                <VCol cols="12" sm="6">
                  <VSelect v-model="form.preferred_communication_method" :items="communicationOptions" :label="t('leave_config.preferred_communication')" variant="outlined" density="comfortable" :error-messages="form.errors.preferred_communication_method" />
                </VCol>
              </VRow>
            </VCardText>
          </VCard>

          <!-- Access Control -->
          <VCard class="mb-4">
            <VCardTitle class="pa-4 pb-2 text-subtitle-1">
              <VIcon icon="ri-shield-user-line" class="me-2" />{{ t('leave.access_control') }}
            </VCardTitle>
            <VDivider />
            <VCardText class="pa-4">
              <VSwitch v-model="form.show_non_employees_in_leave_module" :label="t('leave_config.show_non_employees')" color="info" density="compact" hide-details class="mb-3" />
              <VSwitch v-model="form.limit_administration_to_assigned_divisions" :label="t('leave_config.limit_admin_divisions')" color="info" density="compact" hide-details />
            </VCardText>
          </VCard>

          <!-- Reports -->
          <VCard class="mb-4">
            <VCardTitle class="pa-4 pb-2 text-subtitle-1">
              <VIcon icon="tabler-chart-bar" class="me-2" />{{ t('leave.leave_reports_section') }}
            </VCardTitle>
            <VDivider />
            <VCardText class="pa-4">
              <VSwitch
                v-model="form.balance_as_of_enabled"
                :label="t('leave_config.enable_balance_as_of')"
                :hint="t('leave_config.balance_as_of_hint')"
                persistent-hint
                color="primary"
                density="compact"
              />
            </VCardText>
          </VCard>

          <!-- Leave Payouts -->
          <VCard class="mb-4">
            <VCardTitle class="pa-4 pb-2 text-subtitle-1">
              <VIcon icon="tabler-cash-plus" class="me-2" color="success" />{{ t('leave.leave_payouts') }}
            </VCardTitle>
            <VDivider />
            <VCardText class="pa-4">
              <VSwitch
                v-model="form.allow_employee_payout_requests"
                :label="t('leave_config.allow_payout_requests')"
                :hint="t('leave_config.payout_hint')"
                persistent-hint
                color="success"
                density="compact"
              />
            </VCardText>
          </VCard>

          <!-- Birthday Leave -->
          <VCard class="mb-4">
            <VCardTitle class="pa-4 pb-2 text-subtitle-1">
              <VIcon icon="tabler-cake-variant" class="me-2" color="pink" />{{ t('leave.birthday_leave_section') }}
            </VCardTitle>
            <VDivider />
            <VCardText class="pa-4">
              <VSwitch
                v-model="form.birthday_leave_enabled"
                :label="t('leave_config.birthday_leave_enabled')"
                :hint="t('leave_config.birthday_leave_hint')"
                persistent-hint
                color="pink"
                density="compact"
                class="mb-4"
              />
              <VTextField
                v-if="form.birthday_leave_enabled"
                v-model.number="form.birthday_leave_days"
                :label="t('leave_config.birthday_leave_days')"
                type="number"
                min="0.5"
                step="0.5"
                max="30"
                :error-messages="form.errors.birthday_leave_days"
                variant="outlined"
                density="comfortable"
                :hint="t('leave_config.birthday_leave_days_hint')"
                persistent-hint
                style="max-width: 320px"
              />
            </VCardText>
          </VCard>

          <!-- Save -->
          <div class="d-flex justify-end">
            <VBtn
              type="submit"
              color="primary"
              size="large"
              :loading="form.processing"
              prepend-icon="ri-save-line"
            >
              {{ t('leave.save_configuration') }}
            </VBtn>
          </div>

        </VForm>
      </VCol>
    </VRow>
  </VContainer>
</template>
