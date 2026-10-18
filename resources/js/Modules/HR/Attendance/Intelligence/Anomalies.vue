<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Anomaly = {
  id: number
  employee_name: string
  anomaly_type: string
  description: string
  severity: string
  status: string
  detected_at: string
  ai_explanation: string | null
}

const props = defineProps<{
  anomalies: { data: Anomaly[]; links: any[]; meta: any }
  filters: { severity?: string; status?: string }
}>()

const severityFilter = ref(props.filters.severity ?? '')
const statusFilter   = ref(props.filters.status ?? '')
const explaining     = ref<number | null>(null)

function applyFilters() {
  router.get(route('attendance.intelligence.anomalies'), { severity: severityFilter.value || undefined, status: statusFilter.value || undefined }, { preserveState: true, replace: true })
}

async function explain(a: Anomaly) {
  explaining.value = a.id
  await fetch(route('attendance.intelligence.anomalies.explain', { attendanceAnomaly: a.id }), {
    method: 'POST',
    headers: { 'X-CSRF-TOKEN': (document.querySelector('meta[name=csrf-token]') as any)?.content ?? '' },
  })
  router.reload({ only: ['anomalies'] })
  explaining.value = null
}

function acknowledge(id: number) {
  router.post(route('attendance.intelligence.anomalies.acknowledge', { attendanceAnomaly: id }))
}

function resolve(id: number) {
  router.post(route('attendance.intelligence.anomalies.resolve', { attendanceAnomaly: id }))
}

const severityColor = (s: string) => s === 'high' ? 'error' : s === 'medium' ? 'warning' : 'info'
const statusColor   = (s: string) => s === 'resolved' ? 'success' : s === 'acknowledged' ? 'info' : 'warning'

function formatDate(val: string) {
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}

const headers = [
  { title: 'Employee',   key: 'employee_name', sortable: false },
  { title: 'Type',       key: 'anomaly_type',  sortable: false },
  { title: 'Severity',   key: 'severity',      sortable: false },
  { title: 'Status',     key: 'status',        sortable: false },
  { title: 'Detected',   key: 'detected_at',   sortable: false },
  { title: '',           key: 'actions',       sortable: false, align: 'end' as const },
]
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center gap-2 mb-6">
      <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.intelligence.index'))" />
      <div>
        <h4 class="text-h4">Attendance Anomalies</h4>
        <p class="text-medium-emphasis mb-0">Detected attendance irregularities requiring review</p>
      </div>
    </div>

    <!-- Filters -->
    <VCard elevation="0" border class="mb-5">
      <VCardText class="pa-4">
        <VRow dense>
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
          <VCol cols="12" sm="4">
            <VSelect
              v-model="statusFilter"
              :items="[{ title: 'All', value: '' }, { title: 'Open', value: 'open' }, { title: 'Acknowledged', value: 'acknowledged' }, { title: 'Resolved', value: 'resolved' }]"
              item-title="title"
              item-value="value"
              label="Status"
              variant="outlined"
              density="compact"
              hide-details
              @update:model-value="applyFilters"
            />
          </VCol>
          <VCol cols="auto">
            <VBtn color="warning" variant="tonal" prepend-icon="tabler-scan" @click="router.post(route('attendance.intelligence.anomalies.scan'))">Run Scan</VBtn>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VDataTable :headers="headers" :items="anomalies.data" hide-default-footer hover :items-per-page="25">
        <template #item.anomaly_type="{ item }">
          <span class="text-body-2 text-capitalize">{{ item.anomaly_type.replace(/_/g, ' ') }}</span>
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
          <div class="d-flex gap-1">
            <VBtn v-if="item.status === 'open'" icon="tabler-check" size="x-small" variant="text" color="info" title="Acknowledge" @click="acknowledge(item.id)" />
            <VBtn v-if="item.status !== 'resolved'" icon="tabler-circle-check" size="x-small" variant="text" color="success" title="Resolve" @click="resolve(item.id)" />
            <VBtn icon="tabler-robot" size="x-small" variant="text" color="primary" title="Explain" :loading="explaining === item.id" @click="explain(item)" />
          </div>
        </template>
        <template #no-data>
          <div class="text-center pa-10">
            <VIcon icon="tabler-circle-check" size="56" color="success" class="mb-3" />
            <p class="text-body-1 text-medium-emphasis">No anomalies detected.</p>
          </div>
        </template>
      </VDataTable>
    </VCard>
  </VContainer>
</template>
