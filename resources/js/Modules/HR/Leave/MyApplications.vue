<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type LeaveRequest = {
  id: number
  request_number: string
  leave_type: string
  start_date: string
  end_date: string
  working_days: number
  status: string
  status_label: string
  status_color: string
  submitted_at: string | null
  notes: string | null
}

const props = defineProps<{
  requests: { data: LeaveRequest[]; links: any[]; meta: any }
  filters: { status?: string }
}>()

const statusFilter = ref(props.filters.status ?? '')

const statusOptions = [
  { title: 'All', value: '' },
  { title: 'Pending', value: 'pending' },
  { title: 'Approved', value: 'approved' },
  { title: 'Rejected', value: 'rejected' },
  { title: 'Cancelled', value: 'cancelled' },
]

function applyFilters() {
  router.get(route('hr.leave.my-applications'), { status: statusFilter.value || undefined }, { preserveState: true, replace: true })
}

function goToPage(url: string | null) {
  if (url) router.visit(url, { preserveState: true })
}

const headers = [
  { title: 'Reference',   key: 'request_number', sortable: false },
  { title: 'Leave Type',  key: 'leave_type',      sortable: false },
  { title: 'Dates',       key: 'dates',           sortable: false },
  { title: 'Days',        key: 'working_days',    sortable: false, align: 'center' as const },
  { title: 'Status',      key: 'status_label',    sortable: false },
  { title: 'Submitted',   key: 'submitted_at',    sortable: false },
  { title: '',            key: 'actions',         sortable: false, align: 'end' as const },
]

function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">

    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('hr.leave.index'))" />
        <div>
          <h4 class="text-h4">My Leave Applications</h4>
          <p class="text-medium-emphasis mb-0">View and manage your leave requests</p>
        </div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="router.visit(route('hr.leave.apply'))">Apply for Leave</VBtn>
    </div>

    <!-- Filters -->
    <VCard class="mb-5" elevation="0" border>
      <VCardText class="pa-4">
        <VRow dense align="center">
          <VCol cols="12" sm="4">
            <VSelect
              v-model="statusFilter"
              :items="statusOptions"
              item-title="title"
              item-value="value"
              label="Filter by Status"
              variant="outlined"
              density="compact"
              hide-details
              @update:model-value="applyFilters"
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Table -->
    <VCard elevation="0" border>
      <VDataTable
        :headers="headers"
        :items="requests.data"
        hide-default-footer
        hover
        :items-per-page="20"
      >
        <template #item.request_number="{ item }">
          <span class="text-body-2 font-weight-medium text-primary cursor-pointer" @click="router.visit(route('hr.leave.show', { leaveRequest: item.id }))">
            {{ item.request_number }}
          </span>
        </template>
        <template #item.dates="{ item }">
          <span class="text-body-2">{{ item.start_date }} → {{ item.end_date }}</span>
        </template>
        <template #item.working_days="{ item }">
          <VChip color="default" size="x-small" variant="tonal">{{ item.working_days }}d</VChip>
        </template>
        <template #item.status_label="{ item }">
          <VChip :color="item.status_color" size="small" variant="tonal">{{ item.status_label }}</VChip>
        </template>
        <template #item.submitted_at="{ item }">
          <span class="text-body-2 text-medium-emphasis">{{ formatDate(item.submitted_at) }}</span>
        </template>
        <template #item.actions="{ item }">
          <VBtn
            icon="tabler-eye"
            size="small"
            variant="text"
            color="primary"
            @click="router.visit(route('hr.leave.show', { leaveRequest: item.id }))"
          />
        </template>
        <template #no-data>
          <div class="text-center pa-10">
            <VIcon icon="tabler-calendar-off" size="56" color="secondary" class="mb-3" />
            <p class="text-body-1 text-medium-emphasis mb-4">No leave applications found.</p>
            <VBtn color="primary" variant="tonal" @click="router.visit(route('hr.leave.apply'))">Apply Now</VBtn>
          </div>
        </template>
      </VDataTable>

      <VDivider v-if="requests.meta?.last_page > 1" />
      <div v-if="requests.meta?.last_page > 1" class="d-flex align-center justify-space-between pa-4">
        <p class="text-body-2 text-medium-emphasis mb-0">{{ requests.meta.from }}–{{ requests.meta.to }} of {{ requests.meta.total }}</p>
        <div class="d-flex gap-1">
          <VBtn
            v-for="link in requests.links"
            :key="link.label"
            :disabled="!link.url"
            :variant="link.active ? 'flat' : 'text'"
            :color="link.active ? 'primary' : 'default'"
            size="small"
            @click="goToPage(link.url)"
            v-html="link.label"
          />
        </div>
      </div>
    </VCard>

  </VContainer>
</template>
