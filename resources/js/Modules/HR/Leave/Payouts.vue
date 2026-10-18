<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Payout = {
  id: number
  employee_name: string
  leave_type: string
  days_paid_out: number
  amount: number
  payout_date: string | null
  status: string
  created_at: string
}

type LeaveType = { id: number; name: string }
type Employee  = { id: number; name: string }

const props = defineProps<{
  payouts: { data: Payout[]; links: any[]; meta: any }
  leaveTypes: LeaveType[]
  employees: Employee[]
  filters: { status?: string }
  summary: { total_days: number; total_amount: number; pending_count: number }
}>()

const statusFilter = ref(props.filters.status ?? '')
const createDialog = ref(false)

const createForm = useForm({
  employee_id:   null as number | null,
  leave_type_id: null as number | null,
  days_paid_out: 0,
  payout_date:   '',
  reason:        '',
})

function applyFilters() {
  router.get(route('hr.leave.payouts'), { status: statusFilter.value || undefined }, { preserveState: true, replace: true })
}

function submitCreate() {
  createForm.post(route('hr.leave.payouts.store'), {
    onSuccess: () => { createDialog.value = false },
  })
}

function updateStatus(payout: Payout, newStatus: string) {
  router.patch(route('hr.leave.payouts.update-status', { payoutRecord: payout.id }), { status: newStatus })
}

const statusColor = (s: string) => s === 'paid' ? 'success' : s === 'rejected' ? 'error' : 'warning'

function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}

const headers = [
  { title: 'Employee',   key: 'employee_name', sortable: false },
  { title: 'Leave Type', key: 'leave_type',    sortable: false },
  { title: 'Days',       key: 'days_paid_out', sortable: false, align: 'center' as const },
  { title: 'Amount',     key: 'amount',        sortable: false, align: 'end' as const },
  { title: 'Payout Date',key: 'payout_date',   sortable: false },
  { title: 'Status',     key: 'status',        sortable: false },
  { title: '',           key: 'actions',       sortable: false, align: 'end' as const },
]
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">

    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('hr.leave.hr-dashboard'))" />
        <div>
          <h4 class="text-h4">Leave Payouts</h4>
          <p class="text-medium-emphasis mb-0">Manage and process leave encashment requests</p>
        </div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="createDialog = true">New Payout</VBtn>
    </div>

    <!-- Summary Cards -->
    <VRow class="mb-6">
      <VCol cols="12" sm="4">
        <VCard elevation="0" border>
          <VCardText class="d-flex align-center gap-3 pa-4">
            <VAvatar color="primary" variant="tonal" size="46" rounded>
              <VIcon icon="tabler-calendar" size="22" />
            </VAvatar>
            <div>
              <p class="text-body-2 text-medium-emphasis mb-0">Total Days Paid</p>
              <h5 class="text-h5">{{ summary.total_days.toFixed(1) }}</h5>
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="4">
        <VCard elevation="0" border>
          <VCardText class="d-flex align-center gap-3 pa-4">
            <VAvatar color="success" variant="tonal" size="46" rounded>
              <VIcon icon="tabler-coins" size="22" />
            </VAvatar>
            <div>
              <p class="text-body-2 text-medium-emphasis mb-0">Total Paid Out</p>
              <h5 class="text-h5">R{{ summary.total_amount.toLocaleString() }}</h5>
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="4">
        <VCard elevation="0" border>
          <VCardText class="d-flex align-center gap-3 pa-4">
            <VAvatar color="warning" variant="tonal" size="46" rounded>
              <VIcon icon="tabler-clock" size="22" />
            </VAvatar>
            <div>
              <p class="text-body-2 text-medium-emphasis mb-0">Pending</p>
              <h5 class="text-h5">{{ summary.pending_count }}</h5>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Filter -->
    <VCard elevation="0" border class="mb-5">
      <VCardText class="pa-4">
        <VSelect
          v-model="statusFilter"
          :items="[{ title: 'All', value: '' }, { title: 'Pending', value: 'pending' }, { title: 'Paid', value: 'paid' }, { title: 'Rejected', value: 'rejected' }]"
          item-title="title"
          item-value="value"
          label="Filter by Status"
          variant="outlined"
          density="compact"
          hide-details
          style="max-width: 280px;"
          @update:model-value="applyFilters"
        />
      </VCardText>
    </VCard>

    <!-- Table -->
    <VCard elevation="0" border>
      <VDataTable :headers="headers" :items="payouts.data" hide-default-footer hover :items-per-page="25">
        <template #item.days_paid_out="{ item }">
          <VChip color="default" size="x-small" variant="tonal">{{ item.days_paid_out }}d</VChip>
        </template>
        <template #item.amount="{ item }">
          <span class="text-body-2 font-weight-medium">R{{ item.amount.toLocaleString() }}</span>
        </template>
        <template #item.payout_date="{ item }">
          <span class="text-body-2 text-medium-emphasis">{{ formatDate(item.payout_date) }}</span>
        </template>
        <template #item.status="{ item }">
          <VChip :color="statusColor(item.status)" size="x-small" variant="tonal" class="text-capitalize">{{ item.status }}</VChip>
        </template>
        <template #item.actions="{ item }">
          <div v-if="item.status === 'pending'" class="d-flex gap-1">
            <VBtn size="x-small" color="success" variant="tonal" @click="updateStatus(item, 'paid')">Mark Paid</VBtn>
            <VBtn size="x-small" color="error" variant="text" @click="updateStatus(item, 'rejected')">Reject</VBtn>
          </div>
        </template>
        <template #no-data>
          <div class="text-center pa-10">
            <VIcon icon="tabler-coins" size="56" color="secondary" class="mb-3" />
            <p class="text-body-1 text-medium-emphasis">No payout records found.</p>
          </div>
        </template>
      </VDataTable>
    </VCard>

    <!-- Create Dialog -->
    <VDialog v-model="createDialog" max-width="520" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-h6">New Leave Payout</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12">
              <VAutocomplete
                v-model="createForm.employee_id"
                :items="employees"
                item-title="name"
                item-value="id"
                label="Employee *"
                variant="outlined"
                density="comfortable"
                :error-messages="createForm.errors.employee_id"
              />
            </VCol>
            <VCol cols="12">
              <VSelect
                v-model="createForm.leave_type_id"
                :items="leaveTypes"
                item-title="name"
                item-value="id"
                label="Leave Type *"
                variant="outlined"
                density="comfortable"
                :error-messages="createForm.errors.leave_type_id"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="createForm.days_paid_out"
                label="Days to Pay Out *"
                type="number"
                variant="outlined"
                density="comfortable"
                :min="0.5"
                :step="0.5"
                :error-messages="createForm.errors.days_paid_out"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="createForm.payout_date"
                label="Payout Date"
                type="date"
                variant="outlined"
                density="comfortable"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="createForm.reason"
                label="Reason"
                variant="outlined"
                density="comfortable"
                rows="2"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="createDialog = false">Cancel</VBtn>
          <VBtn
            color="primary"
            variant="flat"
            :loading="createForm.processing"
            :disabled="!createForm.employee_id || !createForm.leave_type_id || !createForm.days_paid_out"
            @click="submitCreate"
          >
            Create Payout
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

  </VContainer>
</template>
