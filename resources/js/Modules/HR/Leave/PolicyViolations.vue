<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Violation = {
  id: number
  employee_name: string
  leave_type: string
  violation_type: string
  description: string
  severity: string
  status: string
  detected_at: string
  resolved_at: string | null
}

const props = defineProps<{
  violations: { data: Violation[]; links: any[]; meta: any }
  filters: { status?: string; severity?: string }
}>()

const statusFilter   = ref(props.filters.status ?? '')
const severityFilter = ref(props.filters.severity ?? '')

const resolveDialog = ref(false)
const selectedViolation = ref<Violation | null>(null)
const resolveForm = useForm({ resolution_note: '' })

function applyFilters() {
  router.get(route('hr.leave.policy-violations'), {
    status:   statusFilter.value || undefined,
    severity: severityFilter.value || undefined,
  }, { preserveState: true, replace: true })
}

function openResolve(v: Violation) {
  selectedViolation.value = v
  resolveForm.reset()
  resolveDialog.value = true
}

function submitResolve() {
  if (!selectedViolation.value) return
  resolveForm.post(route('hr.leave.policy-violations.resolve', { violation: selectedViolation.value.id }), {
    onSuccess: () => { resolveDialog.value = false },
  })
}

const severityColor = (s: string) => s === 'high' ? 'error' : s === 'medium' ? 'warning' : 'info'
const statusColor   = (s: string) => s === 'resolved' ? 'success' : s === 'open' ? 'error' : 'warning'

function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}

const headers = [
  { title: 'Employee',   key: 'employee_name',  sortable: false },
  { title: 'Leave Type', key: 'leave_type',      sortable: false },
  { title: 'Violation',  key: 'violation_type',  sortable: false },
  { title: 'Severity',   key: 'severity',        sortable: false },
  { title: 'Status',     key: 'status',          sortable: false },
  { title: 'Detected',   key: 'detected_at',     sortable: false },
  { title: '',           key: 'actions',         sortable: false, align: 'end' as const },
]
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">

    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('hr.leave.hr-dashboard'))" />
        <div>
          <h4 class="text-h4">Policy Violations</h4>
          <p class="text-medium-emphasis mb-0">Leave policy breaches requiring HR attention</p>
        </div>
      </div>
    </div>

    <!-- Filters -->
    <VCard elevation="0" border class="mb-5">
      <VCardText class="pa-4">
        <VRow dense>
          <VCol cols="12" sm="4">
            <VSelect
              v-model="statusFilter"
              :items="[{ title: 'All', value: '' }, { title: 'Open', value: 'open' }, { title: 'Resolved', value: 'resolved' }]"
              item-title="title"
              item-value="value"
              label="Status"
              variant="outlined"
              density="compact"
              hide-details
              @update:model-value="applyFilters"
            />
          </VCol>
          <VCol cols="12" sm="4">
            <VSelect
              v-model="severityFilter"
              :items="[{ title: 'All', value: '' }, { title: 'High', value: 'high' }, { title: 'Medium', value: 'medium' }, { title: 'Low', value: 'low' }]"
              item-title="title"
              item-value="value"
              label="Severity"
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
      <VDataTable :headers="headers" :items="violations.data" hide-default-footer hover :items-per-page="25">
        <template #item.violation_type="{ item }">
          <span class="text-body-2 text-capitalize">{{ item.violation_type.replace('_', ' ') }}</span>
        </template>
        <template #item.severity="{ item }">
          <VChip :color="severityColor(item.severity)" size="x-small" variant="tonal" class="text-capitalize">{{ item.severity }}</VChip>
        </template>
        <template #item.status="{ item }">
          <VChip :color="statusColor(item.status)" size="x-small" variant="tonal" class="text-capitalize">{{ item.status }}</VChip>
        </template>
        <template #item.detected_at="{ item }">
          <span class="text-body-2 text-medium-emphasis">{{ formatDate(item.detected_at) }}</span>
        </template>
        <template #item.actions="{ item }">
          <VBtn
            v-if="item.status === 'open'"
            size="small"
            color="success"
            variant="tonal"
            @click="openResolve(item)"
          >
            Resolve
          </VBtn>
        </template>
        <template #no-data>
          <div class="text-center pa-10">
            <VIcon icon="tabler-shield-check" size="56" color="success" class="mb-3" />
            <p class="text-body-1 text-medium-emphasis">No policy violations found.</p>
          </div>
        </template>
      </VDataTable>
    </VCard>

    <!-- Resolve Dialog -->
    <VDialog v-model="resolveDialog" max-width="480" persistent>
      <VCard v-if="selectedViolation">
        <VCardTitle class="pa-4 pb-2 text-h6">Resolve Violation</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAlert type="warning" variant="tonal" density="compact" class="mb-4">
            <strong>{{ selectedViolation.employee_name }}</strong> — {{ selectedViolation.violation_type }}
          </VAlert>
          <VTextarea
            v-model="resolveForm.resolution_note"
            label="Resolution Note *"
            variant="outlined"
            density="comfortable"
            rows="3"
            :error-messages="resolveForm.errors.resolution_note"
          />
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="resolveDialog = false">Cancel</VBtn>
          <VBtn color="success" variant="flat" :loading="resolveForm.processing" :disabled="!resolveForm.resolution_note" @click="submitResolve">Mark Resolved</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

  </VContainer>
</template>
