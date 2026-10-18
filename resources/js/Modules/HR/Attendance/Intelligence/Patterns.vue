<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Pattern = {
  id: number
  employee: string
  pattern_type: string
  period_type: string
  period: string
  data: Record<string, any> | null
  insight: string | null
  confidence: number
}

type Employee = { id: number; first_name: string; surname: string }

const props = defineProps<{
  patterns: { data: Pattern[]; links: any[]; meta: any }
  employees: Employee[]
  filters: { employee_id?: string | null }
}>()

const employeeFilter = ref(props.filters.employee_id ?? '')

function applyFilter() {
  router.get(route('attendance.intelligence.patterns'), { employee_id: employeeFilter.value || undefined }, { preserveState: true, replace: true })
}

const headers = [
  { title: 'Employee',     key: 'employee',     sortable: false },
  { title: 'Pattern',      key: 'pattern_type', sortable: false },
  { title: 'Period Type',  key: 'period_type',  sortable: false },
  { title: 'Period',       key: 'period',       sortable: false },
  { title: 'Confidence',   key: 'confidence',   sortable: false, align: 'end' as const },
  { title: 'Insight',      key: 'insight',      sortable: false },
]
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center gap-2 mb-6">
      <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.intelligence.index'))" />
      <div>
        <h4 class="text-h4">Behaviour Patterns</h4>
        <p class="text-medium-emphasis mb-0">Detected attendance behaviour trends per employee</p>
      </div>
    </div>

    <!-- Filter -->
    <VCard elevation="0" border class="mb-5">
      <VCardText class="pa-4">
        <VRow dense align="center">
          <VCol cols="12" sm="5">
            <VAutocomplete
              v-model="employeeFilter"
              :items="employees.map(e => ({ title: `${e.first_name} ${e.surname}`, value: String(e.id) }))"
              item-title="title"
              item-value="value"
              label="Employee"
              variant="outlined"
              density="compact"
              hide-details
              clearable
              @update:model-value="applyFilter"
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VDataTable :headers="headers" :items="patterns.data" hide-default-footer hover :items-per-page="30">
        <template #item.pattern_type="{ item }">
          <span class="text-body-2 text-capitalize">{{ item.pattern_type.replace(/_/g, ' ') }}</span>
        </template>
        <template #item.confidence="{ item }">
          <VChip :color="item.confidence >= 80 ? 'success' : item.confidence >= 50 ? 'warning' : 'secondary'" size="x-small" variant="tonal">{{ item.confidence }}%</VChip>
        </template>
        <template #item.insight="{ item }">
          <span class="text-body-2 text-medium-emphasis" style="max-width:300px;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" :title="item.insight ?? ''">
            {{ item.insight ?? '—' }}
          </span>
        </template>
        <template #no-data>
          <div class="text-center pa-10">
            <VIcon icon="tabler-activity" size="56" color="secondary" class="mb-3" />
            <p class="text-body-1 text-medium-emphasis">No behaviour patterns detected.</p>
          </div>
        </template>
      </VDataTable>
    </VCard>
  </VContainer>
</template>
