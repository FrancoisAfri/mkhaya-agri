<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type LeaveType = {
  id: number
  name: string
  statutory_type: string | null
  is_paid: boolean
  doc_required: number
  notes_required: number
  requires_medical_certificate: boolean
}

type Balance = {
  leave_type_id: number
  leave_type_name: string
  balance_hours: number | null
  balance_days: number | null
}

type Employee = { id: number; first_name: string; surname: string }

const props = defineProps<{
  leaveTypes: LeaveType[]
  balances: Balance[]
  employee: Employee | null
}>()

const form = useForm({
  employee_id:   props.employee?.id ?? null as number | null,
  leave_type_id: null as number | null,
  start_date:    '',
  end_date:      '',
  request_mode:  'daily',
  start_time:    '',
  end_time:      '',
  notes:         '',
  is_overtime:   false,
  is_bulk:       false,
})

const workingDaysPreview = ref<number | null>(null)
const previewLoading     = ref(false)

const selectedType = computed(() =>
  props.leaveTypes.find(t => t.id === form.leave_type_id) ?? null
)

const selectedBalance = computed(() =>
  props.balances.find(b => b.leave_type_id === form.leave_type_id) ?? null
)

const needsDoc = computed(() => selectedType.value?.doc_required === 1 || selectedType.value?.requires_medical_certificate)
const needsHours = computed(() => form.request_mode === 'hourly')

async function fetchWorkingDays() {
  if (!form.start_date || !form.end_date) return
  previewLoading.value = true
  try {
    const res = await fetch(route('hr.leave.working-days-preview') + `?start_date=${form.start_date}&end_date=${form.end_date}`)
    const data = await res.json()
    workingDaysPreview.value = data.working_days ?? null
  } catch {
    workingDaysPreview.value = null
  } finally {
    previewLoading.value = false
  }
}

watch([() => form.start_date, () => form.end_date], fetchWorkingDays)

function submit() {
  form.post(route('hr.leave.store'), {
    onSuccess: () => router.visit(route('hr.leave.my-applications')),
  })
}

const statutoryOptions = [
  { title: 'Annual Leave', value: 'annual' },
  { title: 'Sick Leave', value: 'sick' },
  { title: 'Maternity', value: 'maternity' },
]
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6" style="max-width: 760px;">

    <!-- Header -->
    <div class="d-flex align-center gap-3 mb-6">
      <VBtn icon="tabler-arrow-left" variant="text" size="small" @click="router.visit(route('hr.leave.index'))" />
      <div>
        <h4 class="text-h4">Apply for Leave</h4>
        <p class="text-medium-emphasis mb-0">Submit a new leave request</p>
      </div>
    </div>

    <VCard elevation="0" border>
      <VCardText class="pa-5">
        <VRow dense>

          <!-- Leave Type -->
          <VCol cols="12">
            <VSelect
              v-model="form.leave_type_id"
              :items="leaveTypes"
              item-title="name"
              item-value="id"
              label="Leave Type *"
              variant="outlined"
              density="comfortable"
              :error-messages="form.errors.leave_type_id"
            />
          </VCol>

          <!-- Balance indicator -->
          <VCol v-if="selectedBalance" cols="12" class="pb-0">
            <VAlert
              :type="(selectedBalance.balance_days ?? 0) < 0 ? 'error' : (selectedBalance.balance_days ?? 0) < 3 ? 'warning' : 'info'"
              variant="tonal"
              density="compact"
            >
              Current balance: <strong>{{ ((selectedBalance.balance_hours ?? 0) / 8).toFixed(1) }} days</strong>
              {{ (selectedBalance.balance_days ?? 0) < 0 ? ' (negative balance)' : '' }}
            </VAlert>
          </VCol>

          <!-- Request Mode -->
          <VCol cols="12">
            <VBtnToggle
              v-model="form.request_mode"
              density="compact"
              variant="outlined"
              color="primary"
              divided
            >
              <VBtn value="daily">Full Days</VBtn>
              <VBtn value="hourly">Hours</VBtn>
            </VBtnToggle>
          </VCol>

          <!-- Dates -->
          <VCol cols="12" sm="6">
            <VTextField
              v-model="form.start_date"
              label="Start Date *"
              type="date"
              variant="outlined"
              density="comfortable"
              :error-messages="form.errors.start_date"
            />
          </VCol>
          <VCol cols="12" sm="6">
            <VTextField
              v-model="form.end_date"
              label="End Date *"
              type="date"
              variant="outlined"
              density="comfortable"
              :min="form.start_date"
              :error-messages="form.errors.end_date"
            />
          </VCol>

          <!-- Hourly time fields -->
          <template v-if="needsHours">
            <VCol cols="12" sm="6">
              <VTextField
                v-model="form.start_time"
                label="Start Time *"
                type="time"
                variant="outlined"
                density="comfortable"
                :error-messages="form.errors.start_time"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="form.end_time"
                label="End Time *"
                type="time"
                variant="outlined"
                density="comfortable"
                :error-messages="form.errors.end_time"
              />
            </VCol>
          </template>

          <!-- Working days preview -->
          <VCol v-if="workingDaysPreview !== null && form.start_date && form.end_date" cols="12">
            <VAlert type="info" variant="tonal" density="compact">
              <span v-if="previewLoading">Calculating…</span>
              <span v-else>
                This request covers <strong>{{ workingDaysPreview }} working day(s)</strong>
                (excluding weekends and public holidays)
              </span>
            </VAlert>
          </VCol>

          <!-- Notes -->
          <VCol cols="12">
            <VTextarea
              v-model="form.notes"
              label="Reason / Notes"
              variant="outlined"
              density="comfortable"
              rows="3"
              :required="selectedType?.notes_required === 1"
              :error-messages="form.errors.notes"
            />
          </VCol>

          <!-- Document requirement notice -->
          <VCol v-if="needsDoc" cols="12">
            <VAlert type="warning" variant="tonal" density="compact">
              A supporting document (e.g. medical certificate) will be required for this leave type.
              You can upload it after submission.
            </VAlert>
          </VCol>

          <!-- BCEA errors -->
          <VCol v-if="form.errors.bcea || form.errors.policy" cols="12">
            <VAlert type="error" variant="tonal">
              <template #title>Leave Validation Error</template>
              {{ form.errors.bcea ?? form.errors.policy }}
            </VAlert>
          </VCol>

        </VRow>
      </VCardText>
      <VDivider />
      <VCardActions class="pa-5">
        <VBtn variant="text" color="default" @click="router.visit(route('hr.leave.index'))">Cancel</VBtn>
        <VSpacer />
        <VBtn
          color="primary"
          variant="flat"
          :loading="form.processing"
          :disabled="!form.leave_type_id || !form.start_date || !form.end_date"
          @click="submit"
        >
          Submit Application
        </VBtn>
      </VCardActions>
    </VCard>

  </VContainer>
</template>
