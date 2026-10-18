<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Prediction = {
  id: number
  type: string
  type_label: string
  scope: string
  department: string | null
  employee: string | null
  confidence: number
  risk_level: string
  risk_color: string
  predicted_for: string
  evidence: any
  ai_explanation: string | null
  status: string
}

const props = defineProps<{
  predictions: { data: Prediction[]; links: any[]; meta: any }
  filters: { type: string; status: string }
  types: Record<string, string>
}>()

const typeFilter   = ref(props.filters.type)
const statusFilter = ref(props.filters.status)

function applyFilter() {
  router.get(route('attendance.workforce.predictions'), {
    type:   typeFilter.value || undefined,
    status: statusFilter.value || undefined,
  }, { preserveState: true, replace: true })
}

function dismiss(id: number) { router.post(route('attendance.workforce.predictions.dismiss', { workforcePressurePrediction: id })) }

const typeItems = [{ title: 'All Types', value: '' }, ...Object.entries(props.types).map(([v, t]) => ({ title: t, value: v }))]

const headers = [
  { title: 'Type',       key: 'type_label',   sortable: false },
  { title: 'Scope',      key: 'scope',        sortable: false },
  { title: 'Target',     key: 'target',       sortable: false },
  { title: 'Risk',       key: 'risk_level',   sortable: false },
  { title: 'Confidence', key: 'confidence',   sortable: false, align: 'end' as const },
  { title: 'For Date',   key: 'predicted_for',sortable: false },
  { title: 'Status',     key: 'status',       sortable: false },
  { title: '',           key: 'actions',      sortable: false, align: 'end' as const },
]
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center gap-2 mb-6">
      <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.workforce.index'))" />
      <div>
        <h4 class="text-h4">Workforce Predictions</h4>
        <p class="text-medium-emphasis mb-0">AI-powered workforce pressure forecasts</p>
      </div>
    </div>

    <!-- Filter -->
    <VCard elevation="0" border class="mb-5">
      <VCardText class="pa-4">
        <VRow dense align="center">
          <VCol cols="12" sm="4">
            <VSelect v-model="typeFilter" :items="typeItems" item-title="title" item-value="value" label="Type" variant="outlined" density="compact" hide-details @update:model-value="applyFilter" />
          </VCol>
          <VCol cols="12" sm="4">
            <VSelect v-model="statusFilter" :items="[{title:'All',value:''},{title:'Active',value:'active'},{title:'Resolved',value:'resolved'},{title:'Dismissed',value:'dismissed'}]" item-title="title" item-value="value" label="Status" variant="outlined" density="compact" hide-details @update:model-value="applyFilter" />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VDataTable :headers="headers" :items="predictions.data" hide-default-footer hover :items-per-page="20">
        <template #item.target="{ item }">
          <span class="text-body-2">{{ item.department ?? item.employee ?? item.scope }}</span>
        </template>
        <template #item.risk_level="{ item }">
          <VChip :color="item.risk_color" size="x-small" variant="tonal" class="text-capitalize">{{ item.risk_level }}</VChip>
        </template>
        <template #item.confidence="{ item }">
          <span class="text-body-2">{{ item.confidence }}%</span>
        </template>
        <template #item.status="{ item }">
          <VChip :color="item.status === 'active' ? 'warning' : item.status === 'resolved' ? 'success' : 'secondary'" size="x-small" variant="tonal" class="text-capitalize">{{ item.status }}</VChip>
        </template>
        <template #item.actions="{ item }">
          <VBtn v-if="item.status === 'active'" icon="tabler-x" size="x-small" variant="text" color="secondary" title="Dismiss" @click="dismiss(item.id)" />
        </template>
        <template #no-data>
          <div class="text-center pa-10">
            <VIcon icon="tabler-crystal-ball" size="56" color="secondary" class="mb-3" />
            <p class="text-body-1 text-medium-emphasis">No predictions found.</p>
          </div>
        </template>
      </VDataTable>
    </VCard>
  </VContainer>
</template>
