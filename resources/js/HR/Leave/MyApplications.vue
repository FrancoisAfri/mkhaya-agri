<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

type LeaveRow = {
  id: number; request_number: string; employee_name: string; leave_type: string
  start_date: string; end_date: string; working_days: number; working_hours: number
  request_mode: 'daily' | 'hourly'; start_time: string | null; end_time: string | null
  status: string; status_label: string; status_color: string
  submitted_at: string | null; notes: string | null
}

const props = defineProps<{ requests: LeaveRow[]; filters: { status?: string } }>()

const statusOptions = computed(() => [
  { title: t('leave_status_options.all'), value: '' },
  { title: t('leave_status_options.pending'), value: 'pending' },
  { title: t('leave_status_options.awaiting_manager'), value: 'pending_manager' },
  { title: t('leave_status_options.awaiting_hr'), value: 'pending_hr' },
  { title: t('leave_status_options.approved'), value: 'approved' },
  { title: t('leave_status_options.rejected'), value: 'rejected' },
  { title: t('leave_status_options.cancelled'), value: 'cancelled' },
])

const selectedStatus = ref(props.filters.status ?? '')

const applyFilter = () => {
  router.get('/leave-management/my-applications', { status: selectedStatus.value || undefined }, { preserveState: true, replace: true })
}

// Cancel dialog
const cancelDialog = ref(false)
const cancelTarget = ref<LeaveRow | null>(null)
const cancelForm = useForm({ reason: '' })

const openCancel = (row: LeaveRow) => {
  cancelTarget.value = row
  cancelDialog.value = true
}
const submitCancel = () => {
  if (!cancelTarget.value) return
  cancelForm.post(`/leave-management/my-applications/${cancelTarget.value.id}/cancel`, {
    onSuccess: () => { cancelDialog.value = false; cancelForm.reset() },
  })
}

const canCancel = (status: string) => ['pending', 'pending_manager', 'pending_department', 'pending_hr', 'pending_payroll'].includes(status)

const { t } = useI18n()
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <!-- Header -->
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div>
              <div class="text-overline text-medium-emphasis">{{ t('leave.title') }}</div>
              <h4 class="text-h4">{{ t('leave.my_applications') }}</h4>
            </div>
            <VBtn color="primary" prepend-icon="ri-add-line" @click="router.visit('/leave-management/apply')">
              {{ t('leave.apply_for_leave') }}
            </VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Filters -->
      <VCol cols="12" sm="4" md="3">
        <VSelect
          v-model="selectedStatus"
          :items="statusOptions"
          :label="t('leave.filter_by_status')"
          variant="outlined"
          density="comfortable"
          clearable
          @update:model-value="applyFilter"
        />
      </VCol>

      <!-- Table -->
      <VCol cols="12">
        <VCard>
          <VTable hover density="comfortable">
            <thead>
              <tr>
                <th>{{ t('leave.ref') }}</th>
                <th>{{ t('leave.leave_type') }}</th>
                <th>{{ t('leave.period') }}</th>
                <th>{{ t('leave.days') }}</th>
                <th>{{ t('leave.status') }}</th>
                <th>{{ t('leave.submitted') }}</th>
                <th>{{ t('leave.actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!requests.length">
                <td colspan="7" class="text-center py-10 text-medium-emphasis">
                  {{ t('leave.no_applications') }}
                  <VBtn variant="text" color="primary" @click="router.visit('/leave-management/apply')">{{ t('leave.apply_for_leave') }}</VBtn>
                </td>
              </tr>
              <tr v-for="row in requests" :key="row.id">
                <td>
                  <div class="font-weight-medium">{{ row.request_number }}</div>
                </td>
                <td>{{ row.leave_type }}</td>
                <td class="text-no-wrap">
                  {{ row.start_date }} – {{ row.end_date }}
                  <div v-if="row.request_mode === 'hourly'" class="text-caption text-medium-emphasis">
                    {{ row.start_time }} – {{ row.end_time }}
                  </div>
                </td>
                <td class="text-no-wrap">
                  <span v-if="row.request_mode === 'hourly'">{{ row.working_hours }}h</span>
                  <span v-else>{{ row.working_days }}d</span>
                </td>
                <td>
                  <VChip size="small" :color="row.status_color" label>{{ row.status_label }}</VChip>
                </td>
                <td class="text-caption text-medium-emphasis">{{ row.submitted_at ?? '—' }}</td>
                <td>
                  <div class="d-flex gap-1">
                    <VBtn size="x-small" variant="tonal" @click="router.visit(`/leave-management/my-applications/${row.id}`)">
                      {{ t('leave.view') }}
                    </VBtn>
                    <VBtn
                      v-if="canCancel(row.status)"
                      size="x-small"
                      color="error"
                      variant="tonal"
                      @click="openCancel(row)"
                    >
                      {{ t('leave.cancel') }}
                    </VBtn>
                  </div>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>

    <!-- Cancel Dialog -->
    <VDialog v-model="cancelDialog" max-width="480">
      <VCard>
        <VCardTitle class="pa-4">{{ t('leave.cancel_request') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <p class="mb-4">
            You are about to cancel <strong>{{ cancelTarget?.request_number }}</strong>.
            This will release the reserved balance back to your account.
          </p>
          <VTextarea
            v-model="cancelForm.reason"
            :label="t('leave.cancellation_reason')"
            :error-messages="cancelForm.errors.reason"
            variant="outlined"
            rows="3"
            density="comfortable"
          />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="tonal" @click="cancelDialog = false">{{ t('leave.back') }}</VBtn>
          <VBtn color="error" :loading="cancelForm.processing" :disabled="!cancelForm.reason" @click="submitCancel">
            {{ t('leave.confirm_cancel') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
