<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Alert = {
  id: number
  employee_name: string
  type: string
  severity: string
  status: string
  description: string | null
  created_at: string
  ai_explanation: string | null
}

const props = defineProps<{
  alerts: { data: Alert[]; links: any[]; meta: any }
  summary: { open: number; acknowledged: number; resolved: number; high: number }
  filters: { type?: string; severity?: string; status?: string }
  types: string[]
}>()

const typeFilter     = ref(props.filters.type ?? '')
const severityFilter = ref(props.filters.severity ?? '')
const statusFilter   = ref(props.filters.status ?? '')

function applyFilters() {
  router.get(route('attendance.wellness.index'), {
    type:     typeFilter.value || undefined,
    severity: severityFilter.value || undefined,
    status:   statusFilter.value || undefined,
  }, { preserveState: true, replace: true })
}

function acknowledge(id: number) { router.post(route('attendance.wellness.alerts.acknowledge', { attendanceWellnessAlert: id })) }
function resolve(id: number) { router.post(route('attendance.wellness.alerts.resolve', { attendanceWellnessAlert: id })) }
function dismiss(id: number) { router.post(route('attendance.wellness.alerts.dismiss', { attendanceWellnessAlert: id })) }

const severityColor = (s: string) => s === 'high' ? 'error' : s === 'medium' ? 'warning' : 'info'
const statusColor   = (s: string) => s === 'resolved' ? 'success' : s === 'acknowledged' ? 'info' : s === 'dismissed' ? 'secondary' : 'warning'

function formatDate(val: string) {
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}

const headers = [
  { title: 'Employee', key: 'employee_name', sortable: false },
  { title: 'Type',     key: 'type',          sortable: false },
  { title: 'Severity', key: 'severity',      sortable: false },
  { title: 'Status',   key: 'status',        sortable: false },
  { title: 'Detected', key: 'created_at',    sortable: false },
  { title: '',         key: 'actions',       sortable: false, align: 'end' as const },
]
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.dashboard'))" />
        <div>
          <h4 class="text-h4">Attendance Wellness</h4>
          <p class="text-medium-emphasis mb-0">Employee wellness alerts based on attendance patterns</p>
        </div>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" color="info" @click="router.visit(route('attendance.wellness.departments'))">Dept Scores</VBtn>
        <VBtn color="warning" variant="tonal" prepend-icon="tabler-scan" @click="router.post(route('attendance.wellness.scan'))">Run Scan</VBtn>
      </div>
    </div>

    <!-- Summary -->
    <VRow class="mb-5">
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-warning">{{ summary.open }}</h6><p class="text-caption text-medium-emphasis mb-0">Open</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-info">{{ summary.acknowledged }}</h6><p class="text-caption text-medium-emphasis mb-0">Acknowledged</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-success">{{ summary.resolved }}</h6><p class="text-caption text-medium-emphasis mb-0">Resolved</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-error">{{ summary.high }}</h6><p class="text-caption text-medium-emphasis mb-0">High Severity</p></VCardText></VCard></VCol>
    </VRow>

    <!-- Filters -->
    <VCard elevation="0" border class="mb-5">
      <VCardText class="pa-4">
        <VRow dense>
          <VCol cols="12" sm="4">
            <VSelect v-model="typeFilter" :items="[{ title: 'All Types', value: '' }, ...types.map(t => ({ title: t, value: t }))]" item-title="title" item-value="value" label="Type" variant="outlined" density="compact" hide-details @update:model-value="applyFilters" />
          </VCol>
          <VCol cols="12" sm="4">
            <VSelect v-model="severityFilter" :items="[{ title: 'All', value: '' }, { title: 'High', value: 'high' }, { title: 'Medium', value: 'medium' }, { title: 'Low', value: 'low' }]" item-title="title" item-value="value" label="Severity" variant="outlined" density="compact" hide-details @update:model-value="applyFilters" />
          </VCol>
          <VCol cols="12" sm="4">
            <VSelect v-model="statusFilter" :items="[{ title: 'All', value: '' }, { title: 'Open', value: 'open' }, { title: 'Acknowledged', value: 'acknowledged' }, { title: 'Resolved', value: 'resolved' }]" item-title="title" item-value="value" label="Status" variant="outlined" density="compact" hide-details @update:model-value="applyFilters" />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VDataTable :headers="headers" :items="alerts.data" hide-default-footer hover :items-per-page="25">
        <template #item.type="{ item }">
          <span class="text-body-2 text-capitalize">{{ item.type.replace(/_/g, ' ') }}</span>
        </template>
        <template #item.severity="{ item }">
          <VChip :color="severityColor(item.severity)" size="x-small" variant="tonal" class="text-capitalize">{{ item.severity }}</VChip>
        </template>
        <template #item.status="{ item }">
          <VChip :color="statusColor(item.status)" size="x-small" variant="tonal" class="text-capitalize">{{ item.status }}</VChip>
        </template>
        <template #item.created_at="{ item }">
          <span class="text-body-2 text-medium-emphasis">{{ formatDate(item.created_at) }}</span>
        </template>
        <template #item.actions="{ item }">
          <div class="d-flex gap-1">
            <VBtn v-if="item.status === 'open'" icon="tabler-check" size="x-small" variant="text" color="info" @click="acknowledge(item.id)" />
            <VBtn v-if="item.status !== 'resolved'" icon="tabler-circle-check" size="x-small" variant="text" color="success" @click="resolve(item.id)" />
            <VBtn icon="tabler-x" size="x-small" variant="text" color="secondary" @click="dismiss(item.id)" />
          </div>
        </template>
        <template #no-data>
          <div class="text-center pa-10">
            <VIcon icon="tabler-heart-check" size="56" color="success" class="mb-3" />
            <p class="text-body-1 text-medium-emphasis">No wellness alerts.</p>
          </div>
        </template>
      </VDataTable>
    </VCard>
  </VContainer>
</template>
