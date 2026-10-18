<script setup lang="ts">
import { reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type LeaveConfig = {
  id: number
  allow_advance_leave: boolean
  advance_leave_max_days: number | null
  require_managers_approval: boolean
  all_managers_to_approve: boolean
  require_department_head_approval: boolean
  require_hr_approval: boolean
  require_payroll_approval: boolean
  escalation_days: number
  notify_employee_on_status_change: boolean
  notify_manager_on_application: boolean
  notify_hr_on_final_decision: boolean
  allow_employee_leave_payouts: boolean
  birthday_leave_enabled: boolean
  max_consecutive_days_without_approval: number | null
  min_notice_days: number | null
}

const props = defineProps<{ config: LeaveConfig }>()

const saving = ref(false)
const form = reactive({ ...props.config })

function save() {
  saving.value = true
  router.patch(route('hr.leave.setup.configuration.update'), form, {
    onFinish: () => { saving.value = false },
  })
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6" style="max-width: 860px;">

    <!-- Header -->
    <div class="d-flex align-center gap-2 mb-6">
      <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('hr.leave.index'))" />
      <div>
        <h4 class="text-h4">Leave Configuration</h4>
        <p class="text-medium-emphasis mb-0">Company-wide leave management settings</p>
      </div>
    </div>

    <!-- General Settings -->
    <VCard elevation="0" border class="mb-5">
      <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">General Settings</VCardTitle>
      <VDivider />
      <VCardText class="pa-5">
        <VRow dense>
          <VCol cols="12" sm="6">
            <VSwitch
              v-model="form.allow_advance_leave"
              label="Allow Advance Leave"
              hint="Employees may apply for leave before they've accrued it"
              persistent-hint
              density="comfortable"
            />
          </VCol>
          <VCol v-if="form.allow_advance_leave" cols="12" sm="6">
            <VTextField
              v-model="form.advance_leave_max_days"
              label="Max Advance Days"
              type="number"
              variant="outlined"
              density="comfortable"
              :min="1"
            />
          </VCol>
          <VCol cols="12" sm="6">
            <VTextField
              v-model="form.min_notice_days"
              label="Minimum Notice Days"
              type="number"
              variant="outlined"
              density="comfortable"
              :min="0"
              hint="Days in advance employee must apply"
              persistent-hint
            />
          </VCol>
          <VCol cols="12" sm="6">
            <VTextField
              v-model="form.max_consecutive_days_without_approval"
              label="Max Consecutive Days (unapproved)"
              type="number"
              variant="outlined"
              density="comfortable"
              :min="1"
              hint="Trigger escalation after this many days"
              persistent-hint
            />
          </VCol>
          <VCol cols="12" sm="6">
            <VTextField
              v-model="form.escalation_days"
              label="Escalation After (days)"
              type="number"
              variant="outlined"
              density="comfortable"
              :min="1"
              hint="Auto-escalate pending approvals after N days"
              persistent-hint
            />
          </VCol>
          <VCol cols="12" sm="6">
            <VSwitch
              v-model="form.birthday_leave_enabled"
              label="Birthday Leave"
              hint="Automatically grant employees their birthday off"
              persistent-hint
              density="comfortable"
            />
          </VCol>
          <VCol cols="12" sm="6">
            <VSwitch
              v-model="form.allow_employee_leave_payouts"
              label="Allow Employee Leave Payouts"
              hint="Employees can request encashment of accrued leave"
              persistent-hint
              density="comfortable"
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Notifications -->
    <VCard elevation="0" border class="mb-5">
      <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Notifications</VCardTitle>
      <VDivider />
      <VCardText class="pa-5">
        <VRow dense>
          <VCol cols="12" sm="6">
            <VSwitch
              v-model="form.notify_employee_on_status_change"
              label="Notify Employee on Status Change"
              density="comfortable"
              hide-details
            />
          </VCol>
          <VCol cols="12" sm="6">
            <VSwitch
              v-model="form.notify_manager_on_application"
              label="Notify Manager on New Application"
              density="comfortable"
              hide-details
            />
          </VCol>
          <VCol cols="12" sm="6">
            <VSwitch
              v-model="form.notify_hr_on_final_decision"
              label="Notify HR on Final Decision"
              density="comfortable"
              hide-details
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Save Button -->
    <div class="d-flex justify-end">
      <VBtn color="primary" variant="flat" prepend-icon="tabler-device-floppy" :loading="saving" @click="save">
        Save Configuration
      </VBtn>
    </div>

  </VContainer>
</template>
