<script setup lang="ts">
import { ref, reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type AttendanceRecord = {
  id: number
  employee_id: number
  employee: string
  status: string
  statusColor: string
  shift: string | null
  date: string
  clock_in: string | null
  clock_out: string | null
  hours_worked: number | null
  overtime_hours: number | null
  is_late: boolean
  source: string
  notes: string | null
}

type Status   = { id: number; name: string; color: string; code: string }
type Shift    = { id: number; name: string; code: string }
type Employee = { id: number; first_name: string; surname: string }

const props = defineProps<{
  records: AttendanceRecord[]
  statuses: Status[]
  shifts: Shift[]
  employees: Employee[]
  selectedDate: string
}>()

const selectedDate  = ref(props.selectedDate)
const createDialog  = ref(false)
const submitting    = ref(false)
const confirmDialog = ref()

const form = reactive({
  employee_id: null as number | null,
  status_id:   null as number | null,
  shift_id:    null as number | null,
  date:        selectedDate.value,
  clock_in:    '',
  clock_out:   '',
  source:      'manual',
  notes:       '',
})

function applyDate() {
  router.get(route('attendance.records.index'), { date: selectedDate.value }, { preserveState: true, replace: true })
}

function openCreate() {
  Object.assign(form, { employee_id: null, status_id: null, shift_id: null, date: selectedDate.value, clock_in: '', clock_out: '', source: 'manual', notes: '' })
  createDialog.value = true
}

function submitCreate() {
  submitting.value = true
  router.post(route('attendance.records.store'), form, {
    onFinish: () => { submitting.value = false },
    onSuccess: () => { createDialog.value = false },
  })
}

function approveRecord(id: number) {
  router.post(route('attendance.records.approve', { attendanceRecord: id }))
}

function deleteRecord(id: number) {
  confirmDialog.value.open(
    { title: 'Delete Record', message: 'Delete this attendance record?', confirmText: 'Delete', confirmColor: 'error', icon: 'tabler-trash' },
    () => {
      router.delete(route('attendance.records.destroy', { attendanceRecord: id }))
    },
  )
}

const headers = [
  { title: 'Employee',   key: 'employee',      sortable: false },
  { title: 'Status',     key: 'status',        sortable: false },
  { title: 'Shift',      key: 'shift',         sortable: false },
  { title: 'Clock In',   key: 'clock_in',      sortable: false },
  { title: 'Clock Out',  key: 'clock_out',     sortable: false },
  { title: 'Hours',      key: 'hours_worked',  sortable: false, align: 'center' as const },
  { title: 'OT',         key: 'overtime_hours',sortable: false, align: 'center' as const },
  { title: 'Source',     key: 'source',        sortable: false },
  { title: '',           key: 'actions',       sortable: false, align: 'end' as const },
]

const sourceColor = (s: string) => s === 'manual' ? 'warning' : s === 'biometric' ? 'success' : 'info'
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">

    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.dashboard'))" />
        <div>
          <h4 class="text-h4">Attendance Records</h4>
          <p class="text-medium-emphasis mb-0">Daily attendance log</p>
        </div>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">Add Record</VBtn>
    </div>

    <!-- Date Filter -->
    <VCard elevation="0" border class="mb-5">
      <VCardText class="pa-4 d-flex align-center gap-3">
        <VTextField
          v-model="selectedDate"
          type="date"
          label="Date"
          variant="outlined"
          density="compact"
          hide-details
          style="max-width: 200px;"
          @change="applyDate"
        />
        <VBtn variant="tonal" color="primary" @click="applyDate">Apply</VBtn>
      </VCardText>
    </VCard>

    <!-- Table -->
    <VCard elevation="0" border>
      <VDataTable :headers="headers" :items="records" hover :items-per-page="50" hide-default-footer>
        <template #item.status="{ item }">
          <VChip :color="item.statusColor" size="x-small" variant="tonal">{{ item.status }}</VChip>
          <VChip v-if="item.is_late" color="warning" size="x-small" variant="tonal" class="ms-1">Late</VChip>
        </template>
        <template #item.hours_worked="{ item }">
          <span class="text-body-2">{{ item.hours_worked ? item.hours_worked.toFixed(1) + 'h' : '—' }}</span>
        </template>
        <template #item.overtime_hours="{ item }">
          <span v-if="item.overtime_hours" class="text-body-2 text-warning">{{ item.overtime_hours.toFixed(1) }}h</span>
          <span v-else class="text-body-2 text-medium-emphasis">—</span>
        </template>
        <template #item.source="{ item }">
          <VChip :color="sourceColor(item.source)" size="x-small" variant="tonal" class="text-capitalize">{{ item.source }}</VChip>
        </template>
        <template #item.actions="{ item }">
          <div class="d-flex gap-1">
            <VBtn icon="tabler-check" size="x-small" variant="text" color="success" title="Approve" @click="approveRecord(item.id)" />
            <VBtn icon="tabler-trash" size="x-small" variant="text" color="error" @click="deleteRecord(item.id)" />
          </div>
        </template>
        <template #no-data>
          <div class="text-center pa-10">
            <VIcon icon="tabler-calendar-off" size="56" color="secondary" class="mb-3" />
            <p class="text-body-1 text-medium-emphasis">No records for {{ selectedDate }}.</p>
          </div>
        </template>
      </VDataTable>
    </VCard>

    <!-- Add Record Dialog -->
    <VDialog v-model="createDialog" max-width="560" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-h6">Add Attendance Record</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12">
              <VAutocomplete
                v-model="form.employee_id"
                :items="employees"
                :item-title="(e: Employee) => `${e.first_name} ${e.surname}`"
                item-value="id"
                label="Employee *"
                variant="outlined"
                density="compact"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VSelect
                v-model="form.status_id"
                :items="statuses"
                item-title="name"
                item-value="id"
                label="Status"
                variant="outlined"
                density="compact"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VSelect
                v-model="form.shift_id"
                :items="shifts"
                item-title="name"
                item-value="id"
                label="Shift"
                variant="outlined"
                density="compact"
                clearable
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField v-model="form.date" label="Date *" type="date" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" sm="3">
              <VTextField v-model="form.clock_in" label="Clock In" type="time" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12" sm="3">
              <VTextField v-model="form.clock_out" label="Clock Out" type="time" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="form.notes" label="Notes" variant="outlined" density="compact" rows="2" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="createDialog = false">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="submitting" :disabled="!form.employee_id" @click="submitCreate">Add Record</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <ConfirmDialog ref="confirmDialog" />

  </VContainer>
</template>
