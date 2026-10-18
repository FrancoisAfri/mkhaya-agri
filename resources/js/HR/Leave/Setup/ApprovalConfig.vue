<script setup lang="ts">
import { useForm, router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Config = {
  require_managers_approval:        boolean
  require_department_head_approval: boolean
  require_hr_approval:              boolean
  require_payroll_approval:         boolean
  manager_escalation_days:                    number | null
  department_escalation_days:                 number | null
  hr_escalation_days:                         number | null
  number_of_days_before_automate_application: number | null
  allow_delegation: boolean
  notify_hr_with_application:                   boolean
  notify_employee_about_applications_submitted: boolean
  notify_on_reject:                             boolean
  notify_on_escalate:                           boolean
}

const props = defineProps<{ config: Config }>()

const form = useForm({
  manager_escalation_days:                    props.config.manager_escalation_days ?? null,
  department_escalation_days:                 props.config.department_escalation_days ?? null,
  hr_escalation_days:                         props.config.hr_escalation_days ?? null,
  number_of_days_before_automate_application: props.config.number_of_days_before_automate_application ?? 0,
  allow_delegation:                           props.config.allow_delegation ?? true,
  notify_hr_with_application:                   props.config.notify_hr_with_application ?? true,
  notify_employee_about_applications_submitted: props.config.notify_employee_about_applications_submitted ?? true,
  notify_on_reject:   props.config.notify_on_reject   ?? true,
  notify_on_escalate: props.config.notify_on_escalate ?? true,
})

const activeApprovalLevels = [
  { label: 'Manager',    active: props.config.require_managers_approval,        color: 'primary' },
  { label: 'Department', active: props.config.require_department_head_approval,  color: 'info'    },
  { label: 'HR',         active: props.config.require_hr_approval,               color: 'warning' },
  { label: 'Payroll',    active: props.config.require_payroll_approval,          color: 'success' },
]

const submit = () => {
  form.patch('/leave-management/setup/approval-config', {
    onSuccess: () => {},
  })
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow justify="center">
      <VCol cols="12" lg="9">

        <!-- Header -->
        <VCard class="mb-4">
          <VCardText class="py-5">
            <div class="text-overline text-medium-emphasis">{{ t('leave.setup_label') }}</div>
            <h4 class="text-h4">{{ t('leave.approval_config') }}</h4>
            <p class="text-medium-emphasis mb-0">{{ t('approval_config_page.subtitle') }}</p>
          </VCardText>
        </VCard>

        <VForm @submit.prevent="submit">

          <!-- Active Approval Levels (read-only) -->
          <VCard class="mb-4">
            <VCardTitle class="pa-4 pb-2 text-subtitle-1">
              <VIcon icon="ri-flow-chart" class="me-2" />{{ t('leave.active_approval_levels') }}
            </VCardTitle>
            <VDivider />
            <VCardText class="pa-4">
              <VAlert type="info" variant="tonal" density="compact" class="mb-4">
                {{ t('approval_config_page.approval_levels_info') }}
                <VBtn
                  variant="text"
                  color="primary"
                  size="small"
                  density="compact"
                  class="px-1"
                  @click="router.visit('/leave-management/setup/configuration')"
                >{{ t('approval_config_page.approval_levels_info_link') }}</VBtn>
                {{ t('approval_config_page.approval_levels_info_suffix') }}
              </VAlert>
              <div class="d-flex flex-wrap gap-2">
                <VChip
                  v-for="lvl in activeApprovalLevels"
                  :key="lvl.label"
                  :color="lvl.active ? lvl.color : 'default'"
                  :variant="lvl.active ? 'tonal' : 'outlined'"
                  :prepend-icon="lvl.active ? 'tabler-check-circle' : 'tabler-circle-outline'"
                  label
                >
                  {{ lvl.label }}
                  <span class="ms-1 text-caption">{{ lvl.active ? t('approval_config_page.enabled') : t('approval_config_page.disabled') }}</span>
                </VChip>
              </div>
            </VCardText>
          </VCard>

          <!-- Escalation Rules -->
          <VCard class="mb-4">
            <VCardTitle class="pa-4 pb-2 text-subtitle-1">
              <VIcon icon="ri-timer-line" class="me-2" />{{ t('leave.escalation_rules') }}
            </VCardTitle>
            <VDivider />
            <VCardText class="pa-4">
              <VAlert type="warning" variant="tonal" density="compact" class="mb-4">
                {{ t('approval_config_page.escalation_alert') }}
              </VAlert>
              <VRow>
                <VCol cols="12" sm="6" md="3">
                  <VTextField
                    v-model.number="form.manager_escalation_days"
                    :label="t('approval_config_page.manager_escalation_label')"
                    type="number"
                    min="1"
                    clearable
                    :disabled="!config.require_managers_approval"
                    :error-messages="form.errors.manager_escalation_days"
                    variant="outlined"
                    density="comfortable"
                    :hint="t('approval_config_page.escalation_hint_blank')"
                    persistent-hint
                  />
                </VCol>
                <VCol cols="12" sm="6" md="3">
                  <VTextField
                    v-model.number="form.department_escalation_days"
                    :label="t('approval_config_page.department_escalation_label')"
                    type="number"
                    min="1"
                    clearable
                    :disabled="!config.require_department_head_approval"
                    :error-messages="form.errors.department_escalation_days"
                    variant="outlined"
                    density="comfortable"
                    :hint="t('approval_config_page.escalation_hint_blank')"
                    persistent-hint
                  />
                </VCol>
                <VCol cols="12" sm="6" md="3">
                  <VTextField
                    v-model.number="form.hr_escalation_days"
                    :label="t('approval_config_page.hr_escalation_label')"
                    type="number"
                    min="1"
                    clearable
                    :disabled="!config.require_hr_approval"
                    :error-messages="form.errors.hr_escalation_days"
                    variant="outlined"
                    density="comfortable"
                    :hint="t('approval_config_page.escalation_hint_blank')"
                    persistent-hint
                  />
                </VCol>
                <VCol cols="12" sm="6" md="3">
                  <VTextField
                    v-model.number="form.number_of_days_before_automate_application"
                    :label="t('approval_config_page.auto_approve_label')"
                    type="number"
                    min="0"
                    :error-messages="form.errors.number_of_days_before_automate_application"
                    variant="outlined"
                    density="comfortable"
                    :hint="t('approval_config_page.auto_approve_hint')"
                    persistent-hint
                  />
                </VCol>
              </VRow>
            </VCardText>
          </VCard>

          <!-- Delegation & Notifications -->
          <VCard class="mb-6">
            <VCardTitle class="pa-4 pb-2 text-subtitle-1">
              <VIcon icon="ri-notification-3-line" class="me-2" />{{ t('leave.delegation_notifications') }}
            </VCardTitle>
            <VDivider />
            <VCardText class="pa-4">
              <VRow>
                <VCol cols="12" sm="6">
                  <VSwitch
                    v-model="form.allow_delegation"
                    :label="t('approval_config_page.allow_delegation')"
                    color="primary"
                    density="compact"
                    hide-details
                    class="mb-1"
                  />
                  <p class="text-caption text-medium-emphasis ms-12 mb-0">
                    {{ t('approval_config_page.allow_delegation_desc') }}
                  </p>
                </VCol>
                <VCol cols="12" sm="6">
                  <VSwitch
                    v-model="form.notify_hr_with_application"
                    :label="t('approval_config_page.notify_approver_submission')"
                    color="primary"
                    density="compact"
                    hide-details
                    class="mb-2"
                  />
                  <VSwitch
                    v-model="form.notify_employee_about_applications_submitted"
                    :label="t('approval_config_page.notify_employee_approval')"
                    color="success"
                    density="compact"
                    hide-details
                    class="mb-2"
                  />
                  <VSwitch
                    v-model="form.notify_on_reject"
                    :label="t('approval_config_page.notify_employee_rejection')"
                    color="error"
                    density="compact"
                    hide-details
                    class="mb-2"
                  />
                  <VSwitch
                    v-model="form.notify_on_escalate"
                    :label="t('approval_config_page.notify_on_escalation')"
                    color="warning"
                    density="compact"
                    hide-details
                  />
                </VCol>
              </VRow>
            </VCardText>
          </VCard>

          <!-- Save -->
          <div class="d-flex justify-end">
            <VBtn type="submit" color="primary" size="large" :loading="form.processing" prepend-icon="ri-save-line">
              {{ t('leave.save_configuration') }}
            </VBtn>
          </div>

        </VForm>
      </VCol>
    </VRow>
  </VContainer>
</template>
