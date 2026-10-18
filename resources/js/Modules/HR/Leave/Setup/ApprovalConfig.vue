<script setup lang="ts">
import { reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type ApprovalConfig = {
  require_managers_approval: boolean
  all_managers_to_approve: boolean
  require_department_head_approval: boolean
  require_hr_approval: boolean
  require_payroll_approval: boolean
  escalation_days: number
}

const props = defineProps<{ config: ApprovalConfig }>()

const saving = ref(false)
const form = reactive({ ...props.config })

function save() {
  saving.value = true
  router.patch(route('hr.leave.setup.approval-config.update'), form, {
    onFinish: () => { saving.value = false },
  })
}

const approvalLevels = [
  {
    key: 'require_managers_approval',
    label: 'Line Manager Approval',
    description: 'Employee\'s direct line manager must approve.',
    extra: 'all_managers_to_approve',
    extraLabel: 'All managers in chain must approve (not just one)',
  },
  {
    key: 'require_department_head_approval',
    label: 'Department Head Approval',
    description: 'Department head signs off after manager.',
  },
  {
    key: 'require_hr_approval',
    label: 'HR Approval',
    description: 'HR department final approval.',
  },
  {
    key: 'require_payroll_approval',
    label: 'Payroll Approval',
    description: 'Payroll team confirms before leave is processed.',
  },
]
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6" style="max-width: 740px;">

    <!-- Header -->
    <div class="d-flex align-center gap-2 mb-6">
      <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('hr.leave.index'))" />
      <div>
        <h4 class="text-h4">Approval Configuration</h4>
        <p class="text-medium-emphasis mb-0">Define the leave approval chain for your organisation</p>
      </div>
    </div>

    <VAlert type="info" variant="tonal" class="mb-6">
      Approval levels are applied in sequence. Enabling a level adds it to the approval chain for all leave requests.
    </VAlert>

    <!-- Approval Chain -->
    <VCard elevation="0" border class="mb-5">
      <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Approval Chain</VCardTitle>
      <VDivider />
      <VCardText class="pa-5">
        <div class="d-flex flex-column gap-5">
          <div v-for="(level, index) in approvalLevels" :key="level.key" class="d-flex gap-4">
            <div class="d-flex flex-column align-center" style="min-width: 28px;">
              <VAvatar
                :color="(form as any)[level.key] ? 'primary' : 'secondary'"
                variant="tonal"
                size="28"
                rounded
              >
                <span class="text-caption font-weight-bold">{{ index + 1 }}</span>
              </VAvatar>
              <div
                v-if="index < approvalLevels.length - 1"
                class="flex-1-1 mt-1"
                style="width: 2px; min-height: 24px;"
                :style="{ background: (form as any)[level.key] ? 'rgb(var(--v-theme-primary), 0.4)' : 'rgba(0,0,0,0.1)' }"
              />
            </div>
            <div class="pb-4 flex-1-1">
              <VSwitch
                v-model="(form as any)[level.key]"
                :label="level.label"
                :hint="level.description"
                persistent-hint
                density="comfortable"
                class="mb-1"
              />
              <VSwitch
                v-if="level.extra && (form as any)[level.key]"
                v-model="(form as any)[level.extra]"
                :label="level.extraLabel"
                density="compact"
                hide-details
                class="ms-6 mt-1"
                color="primary"
              />
            </div>
          </div>
        </div>
      </VCardText>
    </VCard>

    <!-- Escalation -->
    <VCard elevation="0" border class="mb-6">
      <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Escalation</VCardTitle>
      <VDivider />
      <VCardText class="pa-5">
        <VTextField
          v-model="form.escalation_days"
          label="Escalate After (days)"
          type="number"
          variant="outlined"
          density="comfortable"
          :min="1"
          style="max-width: 280px;"
          hint="If an approver hasn't actioned after N days, escalate to the next level"
          persistent-hint
        />
      </VCardText>
    </VCard>

    <!-- Save -->
    <div class="d-flex justify-end">
      <VBtn color="primary" variant="flat" prepend-icon="tabler-device-floppy" :loading="saving" @click="save">
        Save Approval Config
      </VBtn>
    </div>

  </VContainer>
</template>
