<script setup lang="ts">
type AttendanceRow = {
  id: number
  employee_name: string
  employee_ref: number
  date: string | null
  clock_in: string | null
  clock_out: string | null
  hours_worked: number | null
  late: boolean
  absent: boolean
  on_leave: boolean
  leave_type: string | null
  overtime_hours?: number
}

defineProps<{
  records: AttendanceRow[]
  summary: {
    source: string
    total: number
    present: number
    late: number
    absent: number
  }
}>()
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-6">
            <div>
              <div class="text-overline text-medium-emphasis">HR Module</div>
              <h4 class="text-h4 mb-1">Time And Attendance</h4>
              <p class="mb-0 text-medium-emphasis">
                Unified view over existing attendance tables without schema changes.
              </p>
            </div>
            <VChip color="info" variant="tonal" label>Source: {{ summary.source }}</VChip>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="6" md="3">
        <VCard color="primary" variant="tonal">
          <VCardText class="text-center">
            <div class="text-h4">{{ summary.total }}</div>
            <div class="text-body-2">Records</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard color="success" variant="tonal">
          <VCardText class="text-center">
            <div class="text-h4">{{ summary.present }}</div>
            <div class="text-body-2">Present</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard color="warning" variant="tonal">
          <VCardText class="text-center">
            <div class="text-h4">{{ summary.late }}</div>
            <div class="text-body-2">Late</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" md="3">
        <VCard color="error" variant="tonal">
          <VCardText class="text-center">
            <div class="text-h4">{{ summary.absent }}</div>
            <div class="text-body-2">Absent</div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12">
        <VCard>
          <VCardTitle>Recent Attendance</VCardTitle>
          <VTable hover density="comfortable">
            <thead>
              <tr>
                <th>ID</th>
                <th>Employee</th>
                <th>Date</th>
                <th>Clock In</th>
                <th>Clock Out</th>
                <th>Hours</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!records.length">
                <td colspan="7" class="text-center py-8 text-medium-emphasis">No attendance records found.</td>
              </tr>
              <tr v-for="row in records" :key="row.id">
                <td>#{{ row.id }}</td>
                <td>
                  <div class="font-weight-medium">{{ row.employee_name }}</div>
                  <div class="text-caption text-medium-emphasis">Ref: {{ row.employee_ref }}</div>
                </td>
                <td>{{ row.date ?? '—' }}</td>
                <td>{{ row.clock_in ?? '—' }}</td>
                <td>{{ row.clock_out ?? '—' }}</td>
                <td>{{ row.hours_worked ?? row.overtime_hours ?? 0 }}</td>
                <td>
                  <VChip v-if="row.absent" size="small" color="error" label>Absent</VChip>
                  <VChip v-else-if="row.on_leave" size="small" color="info" label>On Leave</VChip>
                  <VChip v-else-if="row.late" size="small" color="warning" label>Late</VChip>
                  <VChip v-else size="small" color="success" label>Present</VChip>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>
  </VContainer>
</template>
