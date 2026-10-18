<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type DeviceLog = {
  id: number
  device_id: string
  device_type: string
  device_name: string
  employee: string
  status: string
  captured_at: string
  error: string | null
}

defineProps<{
  logs: { data: DeviceLog[]; links: any[]; meta: any }
}>()

const headers = [
  { title: 'Device',      key: 'device_name',  sortable: false },
  { title: 'Type',        key: 'device_type',  sortable: false },
  { title: 'Employee',    key: 'employee',     sortable: false },
  { title: 'Status',      key: 'status',       sortable: false },
  { title: 'Captured At', key: 'captured_at',  sortable: false },
  { title: 'Error',       key: 'error',        sortable: false },
]

const statusColor = (s: string) => s === 'success' ? 'success' : s === 'pending' ? 'warning' : 'error'
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center gap-2 mb-6">
      <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.dashboard'))" />
      <div>
        <h4 class="text-h4">Device Logs</h4>
        <p class="text-medium-emphasis mb-0">Biometric and clocking device event log</p>
      </div>
    </div>

    <VCard elevation="0" border>
      <VDataTable :headers="headers" :items="logs.data" hide-default-footer hover :items-per-page="50">
        <template #item.device_type="{ item }">
          <span class="text-caption text-medium-emphasis text-capitalize">{{ item.device_type }}</span>
        </template>
        <template #item.status="{ item }">
          <VChip :color="statusColor(item.status)" size="x-small" variant="tonal" class="text-capitalize">{{ item.status }}</VChip>
        </template>
        <template #item.error="{ item }">
          <span v-if="item.error" class="text-caption text-error">{{ item.error }}</span>
          <span v-else class="text-caption text-medium-emphasis">—</span>
        </template>
        <template #no-data>
          <div class="text-center pa-10">
            <VIcon icon="tabler-device-desktop" size="56" color="secondary" class="mb-3" />
            <p class="text-body-1 text-medium-emphasis">No device logs found.</p>
          </div>
        </template>
      </VDataTable>
    </VCard>
  </VContainer>
</template>
