<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Pool = {
  id: number
  employee_name: string
  expected_due_date: string
  leave_type: string
  allocated_days: number
  used_days: number
  status: string
  notes: string | null
}

const props = defineProps<{
  pools: Pool[]
  summary: { active: number; pending: number; total_days_used: number }
}>()

const createDialog = ref(false)
const createForm = useForm({
  employee_id:       null as number | null,
  leave_type:        'maternity',
  expected_due_date: '',
  allocated_days:    120,
  notes:             '',
})

function submitCreate() {
  createForm.post(route('hr.leave.parental-leave.store'), {
    onSuccess: () => { createDialog.value = false },
  })
}

function approvePool(pool: Pool) {
  router.post(route('hr.leave.parental-leave.approve', { pool: pool.id }))
}

const statusColor = (s: string) => s === 'active' ? 'success' : s === 'pending' ? 'warning' : s === 'completed' ? 'info' : 'default'

function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}

const leaveTypeOptions = [
  { title: 'Maternity (4 months)', value: 'maternity' },
  { title: 'Paternity (10 days)', value: 'paternity' },
  { title: 'Parental (10 days)', value: 'parental' },
  { title: 'Adoption Leave', value: 'adoption' },
  { title: 'Commissioning Parent', value: 'commissioning' },
]
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">

    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('hr.leave.hr-dashboard'))" />
        <div>
          <h4 class="text-h4">Parental Leave Management</h4>
          <p class="text-medium-emphasis mb-0">BCEA-compliant maternity, paternity, and parental leave pools</p>
        </div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="createDialog = true">New Pool</VBtn>
    </div>

    <!-- Summary Cards -->
    <VRow class="mb-6">
      <VCol cols="12" sm="4">
        <VCard elevation="0" border>
          <VCardText class="d-flex align-center gap-3 pa-4">
            <VAvatar color="success" variant="tonal" size="46" rounded>
              <VIcon icon="tabler-heart" size="22" />
            </VAvatar>
            <div>
              <p class="text-body-2 text-medium-emphasis mb-0">Active Pools</p>
              <h5 class="text-h5">{{ summary.active }}</h5>
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
              <p class="text-body-2 text-medium-emphasis mb-0">Pending Approval</p>
              <h5 class="text-h5">{{ summary.pending }}</h5>
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="4">
        <VCard elevation="0" border>
          <VCardText class="d-flex align-center gap-3 pa-4">
            <VAvatar color="primary" variant="tonal" size="46" rounded>
              <VIcon icon="tabler-calendar" size="22" />
            </VAvatar>
            <div>
              <p class="text-body-2 text-medium-emphasis mb-0">Total Days Used</p>
              <h5 class="text-h5">{{ summary.total_days_used }}</h5>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Pools List -->
    <VCard elevation="0" border>
      <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Parental Leave Pools</VCardTitle>
      <VDivider />
      <VList v-if="pools.length">
        <VListItem
          v-for="pool in pools"
          :key="pool.id"
          :subtitle="`Due: ${formatDate(pool.expected_due_date)} · Used: ${pool.used_days}/${pool.allocated_days} days`"
        >
          <template #title>
            <span class="text-body-2 font-weight-semibold">{{ pool.employee_name }}</span>
            <span class="text-caption text-medium-emphasis ms-2">{{ pool.leave_type }}</span>
          </template>
          <template #prepend>
            <VAvatar color="pink" variant="tonal" size="40" rounded class="me-3">
              <VIcon icon="tabler-heart" size="20" />
            </VAvatar>
          </template>
          <template #append>
            <div class="d-flex align-center gap-2">
              <VChip :color="statusColor(pool.status)" size="x-small" variant="tonal" class="text-capitalize">{{ pool.status }}</VChip>
              <VBtn
                v-if="pool.status === 'pending'"
                size="small"
                color="success"
                variant="tonal"
                @click="approvePool(pool)"
              >
                Approve
              </VBtn>
            </div>
          </template>
        </VListItem>
      </VList>
      <VCardText v-else class="text-center py-12">
        <VIcon icon="tabler-heart" size="56" color="pink" class="mb-3" />
        <p class="text-body-1 text-medium-emphasis">No parental leave pools. Create one to get started.</p>
      </VCardText>
    </VCard>

    <!-- Create Dialog -->
    <VDialog v-model="createDialog" max-width="520" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-h6">New Parental Leave Pool</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12">
              <VSelect
                v-model="createForm.leave_type"
                :items="leaveTypeOptions"
                item-title="title"
                item-value="value"
                label="Leave Type *"
                variant="outlined"
                density="comfortable"
                :error-messages="createForm.errors.leave_type"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="createForm.expected_due_date"
                label="Expected Due / Start Date"
                type="date"
                variant="outlined"
                density="comfortable"
                :error-messages="createForm.errors.expected_due_date"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="createForm.allocated_days"
                label="Allocated Days"
                type="number"
                variant="outlined"
                density="comfortable"
                :min="1"
                :error-messages="createForm.errors.allocated_days"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="createForm.notes"
                label="Notes"
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
          <VBtn color="primary" variant="flat" :loading="createForm.processing" @click="submitCreate">Create Pool</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

  </VContainer>
</template>
