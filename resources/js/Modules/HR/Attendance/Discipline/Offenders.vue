<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Offender = {
  id: number
  employee: string
  employee_id: number
  total_violations: number
  late_count: number
  absent_count: number
  no_show_count: number
  early_departure_count: number
  prior_warnings: number
  offender_level: string
  level_label: string
  offender_color: string
  first_offence_date: string | null
  last_offence_date: string | null
  period_start: string
  period_end: string
  violation_breakdown: any
  ai_risk_assessment: string | null
  assessed_at: string
}

const props = defineProps<{
  offenders: { data: Offender[]; links: any[]; meta: any }
  filters: { level: string }
  summary: { repeat: number; serious: number; moderate: number; minor: number }
}>()

const levelFilter = ref(props.filters.level)

function applyFilter() {
  router.get(route('attendance.discipline.offenders'), { level: levelFilter.value || undefined }, { preserveState: true, replace: true })
}

const headers = [
  { title: 'Employee',       key: 'employee',         sortable: false },
  { title: 'Level',          key: 'offender_level',   sortable: false },
  { title: 'Total Viol.',    key: 'total_violations', sortable: false, align: 'end' as const },
  { title: 'Late',           key: 'late_count',       sortable: false, align: 'end' as const },
  { title: 'Absent',         key: 'absent_count',     sortable: false, align: 'end' as const },
  { title: 'No Show',        key: 'no_show_count',    sortable: false, align: 'end' as const },
  { title: 'Warnings',       key: 'prior_warnings',   sortable: false, align: 'end' as const },
  { title: 'Last Offence',   key: 'last_offence_date',sortable: false },
]
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center gap-2 mb-6">
      <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.discipline.index'))" />
      <div>
        <h4 class="text-h4">Repeat Offenders</h4>
        <p class="text-medium-emphasis mb-0">Employees with recurring attendance violations</p>
      </div>
    </div>

    <!-- Summary -->
    <VRow class="mb-5">
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-error">{{ summary.repeat }}</h6><p class="text-caption text-medium-emphasis mb-0">Repeat</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-warning">{{ summary.serious }}</h6><p class="text-caption text-medium-emphasis mb-0">Serious</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-info">{{ summary.moderate }}</h6><p class="text-caption text-medium-emphasis mb-0">Moderate</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-secondary">{{ summary.minor }}</h6><p class="text-caption text-medium-emphasis mb-0">Minor</p></VCardText></VCard></VCol>
    </VRow>

    <!-- Filter -->
    <VCard elevation="0" border class="mb-5">
      <VCardText class="pa-4">
        <VSelect v-model="levelFilter" :items="[{title:'All',value:''},{title:'Repeat',value:'repeat'},{title:'Serious',value:'serious'},{title:'Moderate',value:'moderate'},{title:'Minor',value:'minor'}]" item-title="title" item-value="value" label="Level" variant="outlined" density="compact" hide-details style="max-width:240px;" @update:model-value="applyFilter" />
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VDataTable :headers="headers" :items="offenders.data" hide-default-footer hover :items-per-page="20">
        <template #item.offender_level="{ item }">
          <VChip :color="item.offender_color" size="x-small" variant="tonal" class="text-capitalize">{{ item.level_label }}</VChip>
        </template>
        <template #item.last_offence_date="{ item }">
          <span class="text-body-2 text-medium-emphasis">{{ item.last_offence_date ?? '—' }}</span>
        </template>
        <template #no-data>
          <div class="text-center pa-10">
            <VIcon icon="tabler-shield-check" size="56" color="success" class="mb-3" />
            <p class="text-body-1 text-medium-emphasis">No repeat offenders found.</p>
          </div>
        </template>
      </VDataTable>
    </VCard>
  </VContainer>
</template>
