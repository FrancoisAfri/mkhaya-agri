<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type AuditLog = {
  id: number
  action: string
  employee_id: number | null
  old_values: any
  new_values: any
  notes: string | null
  actor_id: number | null
  created_at: string
}

const props = defineProps<{
  logs: { data: AuditLog[]; links: any[]; meta: any }
  actionCounts: Record<string, number>
  actions: Record<string, string>
}>()

const actionFilter = ref('')
const fromFilter   = ref('')
const toFilter     = ref('')

function applyFilter() {
  router.get(route('attendance.audit.index'), {
    action: actionFilter.value || undefined,
    from:   fromFilter.value || undefined,
    to:     toFilter.value || undefined,
  }, { preserveState: true, replace: true })
}

const actionItems = [{ title: 'All Actions', value: '' }, ...Object.entries(props.actions).map(([v, t]) => ({ title: t, value: v }))]

const headers = [
  { title: 'Action',     key: 'action',     sortable: false },
  { title: 'Employee',   key: 'employee_id',sortable: false },
  { title: 'Notes',      key: 'notes',      sortable: false },
  { title: 'Timestamp',  key: 'created_at', sortable: false },
]

const topActions = Object.entries(props.actionCounts).sort(([,a],[,b]) => b - a).slice(0, 6)
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center gap-2 mb-6">
      <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.dashboard'))" />
      <div>
        <h4 class="text-h4">Attendance Audit Log</h4>
        <p class="text-medium-emphasis mb-0">Complete audit trail of all attendance system actions</p>
      </div>
    </div>

    <!-- Top actions summary -->
    <VCard elevation="0" border class="mb-5">
      <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Activity Summary (Last 30 Days)</VCardTitle>
      <VDivider />
      <VCardText class="pa-4">
        <VRow dense>
          <VCol v-for="[action, count] in topActions" :key="action" cols="6" sm="4" lg="2">
            <div class="text-center">
              <h6 class="text-h6">{{ count }}</h6>
              <p class="text-caption text-medium-emphasis mb-0 text-capitalize">{{ action.replace(/_/g, ' ') }}</p>
            </div>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Filters -->
    <VCard elevation="0" border class="mb-5">
      <VCardText class="pa-4">
        <VRow dense align="center">
          <VCol cols="12" sm="4">
            <VSelect v-model="actionFilter" :items="actionItems" item-title="title" item-value="value" label="Action" variant="outlined" density="compact" hide-details @update:model-value="applyFilter" />
          </VCol>
          <VCol cols="12" sm="3">
            <VTextField v-model="fromFilter" label="From" type="date" variant="outlined" density="compact" hide-details @change="applyFilter" />
          </VCol>
          <VCol cols="12" sm="3">
            <VTextField v-model="toFilter" label="To" type="date" variant="outlined" density="compact" hide-details :min="fromFilter" @change="applyFilter" />
          </VCol>
          <VCol cols="auto">
            <VBtn color="primary" variant="tonal" @click="applyFilter">Apply</VBtn>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VDataTable :headers="headers" :items="logs.data" hide-default-footer hover :items-per-page="50">
        <template #item.action="{ item }">
          <VChip color="primary" size="x-small" variant="tonal">{{ item.action.replace(/_/g, ' ') }}</VChip>
        </template>
        <template #item.employee_id="{ item }">
          <span class="text-body-2 text-medium-emphasis">{{ item.employee_id ?? '—' }}</span>
        </template>
        <template #item.notes="{ item }">
          <span class="text-body-2 text-medium-emphasis" style="max-width:300px;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" :title="item.notes ?? ''">{{ item.notes ?? '—' }}</span>
        </template>
        <template #no-data><div class="text-center pa-10 text-medium-emphasis">No audit logs found.</div></template>
      </VDataTable>
    </VCard>
  </VContainer>
</template>
