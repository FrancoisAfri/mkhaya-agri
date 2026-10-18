<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type MissedClocking = {
  id: number
  employee: string
  type: string
  clocked_at: string
  notes: string | null
  is_corrected: boolean
}

const props = defineProps<{
  missed: MissedClocking[]
  selectedDate: string
}>()

const selectedDate = ref(props.selectedDate)
const detecting = ref(false)

function applyDate() {
  router.get(route('attendance.clocking.missed'), { date: selectedDate.value }, { preserveState: true, replace: true })
}

function detectMissed() {
  detecting.value = true
  router.post(route('attendance.clocking.detect-missed'), { date: selectedDate.value }, {
    onFinish: () => { detecting.value = false },
  })
}

const headers = [
  { title: 'Employee',   key: 'employee',    sortable: false },
  { title: 'Type',       key: 'type',        sortable: false },
  { title: 'Time',       key: 'clocked_at',  sortable: false },
  { title: 'Notes',      key: 'notes',       sortable: false },
  { title: 'Corrected',  key: 'is_corrected',sortable: false, align: 'center' as const },
]
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.dashboard'))" />
        <div>
          <h4 class="text-h4">Missed Clockings</h4>
          <p class="text-medium-emphasis mb-0">Employees with missed or incomplete clock events</p>
        </div>
      </div>
      <VBtn color="warning" variant="tonal" prepend-icon="tabler-scan" :loading="detecting" @click="detectMissed">Detect Missed</VBtn>
    </div>

    <VCard elevation="0" border class="mb-5">
      <VCardText class="pa-4">
        <VRow dense align="center">
          <VCol cols="12" sm="4">
            <VTextField v-model="selectedDate" label="Date" type="date" variant="outlined" density="compact" hide-details @change="applyDate" />
          </VCol>
          <VCol cols="auto">
            <VBtn color="primary" variant="tonal" @click="applyDate">Apply</VBtn>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VDataTable :headers="headers" :items="missed" hover :items-per-page="25">
        <template #item.type="{ item }">
          <VChip :color="item.type === 'clock_in' ? 'success' : 'error'" size="x-small" variant="tonal" class="text-capitalize">
            {{ item.type.replace('_', ' ') }}
          </VChip>
        </template>
        <template #item.is_corrected="{ item }">
          <VIcon :icon="item.is_corrected ? 'tabler-circle-check' : 'tabler-circle-x'" :color="item.is_corrected ? 'success' : 'error'" size="18" />
        </template>
        <template #no-data>
          <div class="text-center pa-10">
            <VIcon icon="tabler-circle-check" size="56" color="success" class="mb-3" />
            <p class="text-body-1 text-medium-emphasis">No missed clockings for {{ selectedDate }}.</p>
          </div>
        </template>
      </VDataTable>
    </VCard>
  </VContainer>
</template>
