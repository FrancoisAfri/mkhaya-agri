<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type SearchResult = {
  id: number
  request_number: string
  employee_name: string
  leave_type: string
  start_date: string
  end_date: string
  working_days: number
  status: string
  status_label: string
  status_color: string
}

const props = defineProps<{
  query: string
  results: SearchResult[]
}>()

const searchQuery = ref(props.query)
let searchTimer: ReturnType<typeof setTimeout>

function applySearch() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    router.get(route('hr.leave.admin-cancel'), { q: searchQuery.value || undefined }, { preserveState: true, replace: true })
  }, 400)
}

const cancelDialog  = ref(false)
const selectedLeave = ref<SearchResult | null>(null)
const cancelForm    = useForm({ reason: '' })

function openCancel(item: SearchResult) {
  selectedLeave.value = item
  cancelForm.reset()
  cancelDialog.value = true
}

function submitCancel() {
  if (!selectedLeave.value) return
  cancelForm.post(route('hr.leave.admin-cancel.submit', { leaveRequest: selectedLeave.value.id }), {
    onSuccess: () => { cancelDialog.value = false },
  })
}

const headers = [
  { title: 'Reference',   key: 'request_number', sortable: false },
  { title: 'Employee',    key: 'employee_name',   sortable: false },
  { title: 'Leave Type',  key: 'leave_type',      sortable: false },
  { title: 'Dates',       key: 'dates',           sortable: false },
  { title: 'Days',        key: 'working_days',    sortable: false, align: 'center' as const },
  { title: 'Status',      key: 'status_label',    sortable: false },
  { title: '',            key: 'actions',         sortable: false, align: 'end' as const },
]
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">

    <!-- Header -->
    <div class="d-flex align-center gap-2 mb-6">
      <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('hr.leave.hr-dashboard'))" />
      <div>
        <h4 class="text-h4">Admin Leave Cancellation</h4>
        <p class="text-medium-emphasis mb-0">Search and cancel leave requests on behalf of employees</p>
      </div>
    </div>

    <VAlert type="warning" variant="tonal" class="mb-6">
      <template #title>Administrative Action</template>
      Cancelling leave on behalf of an employee is a privileged action and will be fully audited.
      Only use this for corrections or special HR circumstances.
    </VAlert>

    <!-- Search -->
    <VCard elevation="0" border class="mb-5">
      <VCardText class="pa-4">
        <VTextField
          v-model="searchQuery"
          label="Search by employee name, request number, or leave type…"
          prepend-inner-icon="tabler-search"
          variant="outlined"
          density="compact"
          hide-details
          clearable
          @update:model-value="applySearch"
        />
      </VCardText>
    </VCard>

    <!-- Results -->
    <VCard elevation="0" border>
      <VDataTable :headers="headers" :items="results" hide-default-footer hover :items-per-page="20">
        <template #item.dates="{ item }">
          <span class="text-body-2">{{ item.start_date }} → {{ item.end_date }}</span>
        </template>
        <template #item.working_days="{ item }">
          <VChip color="default" size="x-small" variant="tonal">{{ item.working_days }}d</VChip>
        </template>
        <template #item.status_label="{ item }">
          <VChip :color="item.status_color" size="small" variant="tonal">{{ item.status_label }}</VChip>
        </template>
        <template #item.actions="{ item }">
          <VBtn
            v-if="!['cancelled', 'rejected'].includes(item.status)"
            size="small"
            color="error"
            variant="tonal"
            prepend-icon="tabler-x"
            @click="openCancel(item)"
          >
            Cancel
          </VBtn>
          <span v-else class="text-caption text-medium-emphasis">—</span>
        </template>
        <template #no-data>
          <div class="text-center pa-10">
            <VIcon icon="tabler-search" size="56" color="secondary" class="mb-3" />
            <p class="text-body-1 text-medium-emphasis">
              {{ searchQuery ? 'No results found.' : 'Enter a search term to find leave requests.' }}
            </p>
          </div>
        </template>
      </VDataTable>
    </VCard>

    <!-- Cancel Confirmation Dialog -->
    <VDialog v-model="cancelDialog" max-width="480" persistent>
      <VCard v-if="selectedLeave">
        <VCardTitle class="pa-4 pb-2 text-h6">Confirm Administrative Cancellation</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAlert type="error" variant="tonal" density="compact" class="mb-4">
            <strong>{{ selectedLeave.employee_name }}</strong> — {{ selectedLeave.leave_type }}
            ({{ selectedLeave.start_date }} → {{ selectedLeave.end_date }}, {{ selectedLeave.working_days }}d)
          </VAlert>
          <VTextarea
            v-model="cancelForm.reason"
            label="Reason for Cancellation *"
            variant="outlined"
            density="comfortable"
            rows="3"
            placeholder="Provide a clear business reason for this administrative action…"
            :error-messages="cancelForm.errors.reason"
          />
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="cancelDialog = false">Back</VBtn>
          <VBtn
            color="error"
            variant="flat"
            :loading="cancelForm.processing"
            :disabled="!cancelForm.reason"
            @click="submitCancel"
          >
            Confirm Cancellation
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

  </VContainer>
</template>
