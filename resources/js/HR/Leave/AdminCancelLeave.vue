<script setup lang="ts">
import { ref, reactive } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

type LeaveRow = {
  id: number
  request_number: string
  employee_name: string
  leave_type: string
  start_date: string
  end_date: string
  working_days: number
  status: string
}

const props = defineProps<{
  query:   string
  results: LeaveRow[]
}>()

const search = ref(props.query)
const snack  = reactive({ show: false, text: '', color: 'success' })

const runSearch = () => {
  router.get('/leave-management/admin-cancel', { q: search.value }, { preserveState: true })
}

const toast = (text: string, color = 'success') => { snack.text = text; snack.color = color; snack.show = true }

// Cancel dialog
const cancelDialog  = ref(false)
const cancelTarget  = ref<LeaveRow | null>(null)
const cancelForm    = useForm({ reason: '' })

const openCancel = (row: LeaveRow) => {
  cancelTarget.value = row
  cancelForm.reset()
  cancelDialog.value = true
}

const submitCancel = () => {
  if (!cancelTarget.value) return
  cancelForm.post(`/leave-management/admin-cancel/${cancelTarget.value.id}`, {
    onSuccess: () => {
      cancelDialog.value = false
      toast(`Leave request ${cancelTarget.value!.request_number} cancelled successfully.`)
      router.get('/leave-management/admin-cancel', { q: search.value }, { preserveState: true })
    },
    onError: (errors) => {
      const msg = Object.values(errors).flat().join(' ') || 'Could not cancel this leave request.'
      toast(msg, 'error')
    },
  })
}

const statusColor = (s: string) => ({
  approved: 'success', pending: 'warning', escalated: 'orange',
}[s] ?? 'secondary')

const { t } = useI18n()
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow justify="center">
      <VCol cols="12" lg="10">

        <!-- Header -->
        <VCard class="mb-4">
          <VCardText class="d-flex align-center gap-3 py-5">
            <VBtn icon variant="text" @click="router.visit('/leave-management')">
              <VIcon icon="tabler-arrow-left" />
            </VBtn>
            <div>
              <div class="text-overline text-medium-emphasis">{{ t('leave.title') }}</div>
              <h4 class="text-h4">{{ t('leave.cancel_on_behalf') }}</h4>
              <p class="mb-0 text-medium-emphasis">Search for any approved or pending leave and cancel it. A cancellation reason is required and is recorded in the audit trail.</p>
            </div>
          </VCardText>
        </VCard>

        <!-- Search bar -->
        <VCard class="mb-4">
          <VCardText class="pa-4">
            <VAlert type="warning" variant="tonal" density="compact" class="mb-4" icon="tabler-shield-alert">
              This action is restricted to HR, Admin, and Administrator roles. Cancellations are logged and the employee's balance is released.
            </VAlert>
            <VRow align="center">
              <VCol cols="12" sm="9">
                <VTextField
                  v-model="search"
                  :label="t('leave.search_placeholder')"
                  variant="outlined"
                  density="comfortable"
                  prepend-inner-icon="tabler-magnify"
                  clearable
                  hide-details
                  @keyup.enter="runSearch"
                  @click:clear="() => { search = ''; runSearch() }"
                />
              </VCol>
              <VCol cols="12" sm="3">
                <VBtn color="primary" block size="large" prepend-icon="tabler-magnify" @click="runSearch">
                  {{ t('leave.search') }}
                </VBtn>
              </VCol>
            </VRow>
          </VCardText>
        </VCard>

        <!-- Results -->
        <VCard v-if="query">
          <VCardTitle class="pa-4 pb-2 d-flex align-center gap-2">
            <VIcon icon="tabler-clipboard-list" />
            {{ t('leave.search_results') }}
            <VChip size="x-small" class="ms-1">{{ results.length }}</VChip>
          </VCardTitle>
          <VDivider />

          <div v-if="!results.length" class="text-center py-10 text-medium-emphasis">
            <VIcon icon="tabler-file-search" size="48" class="mb-3" />
            <div>No approved or pending leave requests found matching "{{ query }}"</div>
          </div>

          <VTable v-else density="comfortable" hover>
            <thead>
              <tr>
                <th>{{ t('leave.ref') }}</th>
                <th>{{ t('leave.employee_label') }}</th>
                <th>{{ t('leave.leave_type') }}</th>
                <th>{{ t('leave.period') }}</th>
                <th>{{ t('leave.days') }}</th>
                <th>{{ t('leave.status') }}</th>
                <th>{{ t('leave.actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in results" :key="row.id">
                <td class="text-caption font-weight-medium">{{ row.request_number }}</td>
                <td class="font-weight-medium">{{ row.employee_name }}</td>
                <td>{{ row.leave_type }}</td>
                <td class="text-caption">{{ row.start_date }} – {{ row.end_date }}</td>
                <td>{{ row.working_days }}</td>
                <td>
                  <VChip :color="statusColor(row.status)" size="x-small" label>
                    {{ row.status }}
                  </VChip>
                </td>
                <td>
                  <VBtn
                    size="x-small"
                    color="error"
                    variant="tonal"
                    prepend-icon="tabler-cancel"
                    @click="openCancel(row)"
                  >
                    {{ t('leave.cancel') }}
                  </VBtn>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>

        <VCard v-else class="text-center py-10">
          <VIcon icon="tabler-magnify" size="48" class="mb-3 text-medium-emphasis" />
          <p class="text-medium-emphasis">Enter an employee name, reference number, or leave type above to search.</p>
        </VCard>

      </VCol>
    </VRow>

    <!-- Cancel confirmation dialog -->
    <VDialog v-model="cancelDialog" max-width="500" persistent>
      <VCard v-if="cancelTarget">
        <VCardTitle class="pa-4 d-flex align-center gap-2">
          <VIcon icon="tabler-cancel" color="error" />
          {{ t('leave.cancel_request') }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAlert type="error" variant="tonal" density="compact" class="mb-4">
            You are about to cancel <strong>{{ cancelTarget.request_number }}</strong> for
            <strong>{{ cancelTarget.employee_name }}</strong>
            ({{ cancelTarget.leave_type }}, {{ cancelTarget.start_date }} – {{ cancelTarget.end_date }},
            {{ cancelTarget.working_days }} day{{ cancelTarget.working_days !== 1 ? 's' : '' }}).
            The employee's balance will be restored. This cannot be undone.
          </VAlert>
          <VTextarea
            v-model="cancelForm.reason"
            :label="t('leave.cancellation_reason') + ' *'"
            variant="outlined"
            rows="3"
            counter="1000"
            :error-messages="cancelForm.errors.reason"
            placeholder="e.g. Employee applied for the wrong date. Approved in error. Corrected and resubmitted."
          />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="tonal" @click="cancelDialog = false">{{ t('leave.go_back') }}</VBtn>
          <VBtn
            color="error"
            :loading="cancelForm.processing"
            :disabled="cancelForm.reason.trim().length < 5"
            prepend-icon="tabler-cancel"
            @click="submitCancel"
          >
            {{ t('leave.confirm_cancellation') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

  </VContainer>

  <VSnackbar v-model="snack.show" :color="snack.color" location="top right" timeout="4000">
    {{ snack.text }}
  </VSnackbar>
</template>
