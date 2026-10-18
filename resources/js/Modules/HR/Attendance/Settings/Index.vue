<script setup lang="ts">
import { reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Settings = {
  work_start_time: string
  work_end_time: string
  grace_period_minutes: number
  overtime_threshold_minutes: number
  early_departure_threshold_minutes: number
  late_policy_enabled: boolean
  overtime_enabled: boolean
  auto_clock_out_enabled: boolean
  auto_clock_out_time: string | null
  require_shift_assignment: boolean
  allow_manual_entry: boolean
}

const props = defineProps<{ settings: Settings; notifications: any[] }>()

const saving = ref(false)
const form = reactive({ ...props.settings })

function save() {
  saving.value = true
  router.patch(route('attendance.settings.update'), form, {
    onFinish: () => { saving.value = false },
  })
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6" style="max-width: 860px;">
    <div class="d-flex align-center gap-2 mb-6">
      <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.dashboard'))" />
      <div>
        <h4 class="text-h4">Attendance Settings</h4>
        <p class="text-medium-emphasis mb-0">Configure time and attendance rules</p>
      </div>
    </div>

    <div class="d-flex gap-2 flex-wrap mb-5">
      <VBtn variant="tonal" color="default" @click="router.visit(route('attendance.shifts.index'))">Manage Shifts</VBtn>
      <VBtn variant="tonal" color="default" @click="router.visit(route('attendance.statuses.index'))">Manage Statuses</VBtn>
      <VBtn variant="tonal" color="default" @click="router.visit(route('attendance.calendars.index'))">Work Calendars</VBtn>
    </div>

    <!-- Work Hours -->
    <VCard elevation="0" border class="mb-5">
      <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Standard Work Hours</VCardTitle>
      <VDivider />
      <VCardText class="pa-5">
        <VRow dense>
          <VCol cols="12" sm="6">
            <VTextField v-model="form.work_start_time" label="Work Start Time" type="time" variant="outlined" density="comfortable" />
          </VCol>
          <VCol cols="12" sm="6">
            <VTextField v-model="form.work_end_time" label="Work End Time" type="time" variant="outlined" density="comfortable" />
          </VCol>
          <VCol cols="12" sm="4">
            <VTextField v-model="form.grace_period_minutes" label="Grace Period (min)" type="number" variant="outlined" density="comfortable" :min="0" :max="60" />
          </VCol>
          <VCol cols="12" sm="4">
            <VTextField v-model="form.overtime_threshold_minutes" label="Overtime Threshold (min)" type="number" variant="outlined" density="comfortable" :min="0" />
          </VCol>
          <VCol cols="12" sm="4">
            <VTextField v-model="form.early_departure_threshold_minutes" label="Early Departure (min)" type="number" variant="outlined" density="comfortable" :min="0" />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Policies -->
    <VCard elevation="0" border class="mb-5">
      <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Policies</VCardTitle>
      <VDivider />
      <VCardText class="pa-5">
        <VRow dense>
          <VCol cols="12" sm="6">
            <VSwitch v-model="form.late_policy_enabled" label="Late Arrival Policy" hint="Flag employees who arrive after grace period" persistent-hint density="comfortable" />
          </VCol>
          <VCol cols="12" sm="6">
            <VSwitch v-model="form.overtime_enabled" label="Overtime Tracking" hint="Track hours worked beyond standard work hours" persistent-hint density="comfortable" />
          </VCol>
          <VCol cols="12" sm="6">
            <VSwitch v-model="form.require_shift_assignment" label="Require Shift Assignment" hint="Employees must have an assigned shift" persistent-hint density="comfortable" />
          </VCol>
          <VCol cols="12" sm="6">
            <VSwitch v-model="form.allow_manual_entry" label="Allow Manual Entry" hint="HR can manually add attendance records" persistent-hint density="comfortable" />
          </VCol>
          <VCol cols="12" sm="6">
            <VSwitch v-model="form.auto_clock_out_enabled" label="Auto Clock-Out" hint="Automatically clock out at specified time" persistent-hint density="comfortable" />
          </VCol>
          <VCol v-if="form.auto_clock_out_enabled" cols="12" sm="6">
            <VTextField v-model="form.auto_clock_out_time" label="Auto Clock-Out Time" type="time" variant="outlined" density="comfortable" />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <div class="d-flex justify-end">
      <VBtn color="primary" variant="flat" prepend-icon="tabler-device-floppy" :loading="saving" @click="save">
        Save Settings
      </VBtn>
    </div>
  </VContainer>
</template>
