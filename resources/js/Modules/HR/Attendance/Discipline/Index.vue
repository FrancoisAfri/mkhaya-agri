<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type DisciplineCase = {
  id: number
  employee: string
  employee_id: number
  case_number: string
  case_type: string
  case_type_label: string
  status: string
  status_color: string
  severity: string
  severity_color: string
  summary: string | null
  violations: any[]
  ai_recommendation: string | null
  period_start: string
  period_end: string
  has_pack: boolean
  created_at: string
}

const props = defineProps<{
  cases: { data: DisciplineCase[]; links: any[]; meta: any }
  summary: { open: number; pending: number }
  filters: { status: string; case_type: string }
  caseTypes: Record<string, string>
}>()

const statusFilter   = ref(props.filters.status)
const caseTypeFilter = ref(props.filters.case_type)
const scanDialog     = ref(false)
const scanFrom       = ref('')
const scanTo         = ref('')

function applyFilter() {
  router.get(route('attendance.discipline.index'), {
    status:    statusFilter.value || undefined,
    case_type: caseTypeFilter.value || undefined,
  }, { preserveState: true, replace: true })
}

function runScan() {
  router.post(route('attendance.discipline.scan'), { period_start: scanFrom.value, period_end: scanTo.value }, {
    onSuccess: () => { scanDialog.value = false },
  })
}

const caseTypeItems = [{ title: 'All Types', value: '' }, ...Object.entries(props.caseTypes).map(([v, t]) => ({ title: t, value: v }))]

const headers = [
  { title: 'Case #',      key: 'case_number',    sortable: false },
  { title: 'Employee',    key: 'employee',       sortable: false },
  { title: 'Type',        key: 'case_type_label',sortable: false },
  { title: 'Severity',    key: 'severity',       sortable: false },
  { title: 'Status',      key: 'status',         sortable: false },
  { title: 'Period',      key: 'period_start',   sortable: false },
  { title: '',            key: 'actions',        sortable: false, align: 'end' as const },
]
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.dashboard'))" />
        <div>
          <h4 class="text-h4">Discipline Cases</h4>
          <p class="text-medium-emphasis mb-0">Attendance-related disciplinary case management</p>
        </div>
      </div>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" color="default" @click="router.visit(route('attendance.discipline.offenders'))">Repeat Offenders</VBtn>
        <VBtn color="warning" variant="tonal" prepend-icon="tabler-scan" @click="scanDialog = true">Run Scan</VBtn>
      </div>
    </div>

    <!-- Summary -->
    <VRow class="mb-5">
      <VCol cols="6"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-warning">{{ summary.open }}</h6><p class="text-caption text-medium-emphasis mb-0">Open</p></VCardText></VCard></VCol>
      <VCol cols="6"><VCard elevation="0" border><VCardText class="text-center pa-3"><h6 class="text-h6 text-info">{{ summary.pending }}</h6><p class="text-caption text-medium-emphasis mb-0">Pending</p></VCardText></VCard></VCol>
    </VRow>

    <!-- Filters -->
    <VCard elevation="0" border class="mb-5">
      <VCardText class="pa-4">
        <VRow dense align="center">
          <VCol cols="12" sm="4">
            <VSelect v-model="statusFilter" :items="[{title:'All',value:''},{title:'Open',value:'open'},{title:'Pending',value:'pending'},{title:'Resolved',value:'resolved'},{title:'Closed',value:'closed'}]" item-title="title" item-value="value" label="Status" variant="outlined" density="compact" hide-details @update:model-value="applyFilter" />
          </VCol>
          <VCol cols="12" sm="4">
            <VSelect v-model="caseTypeFilter" :items="caseTypeItems" item-title="title" item-value="value" label="Case Type" variant="outlined" density="compact" hide-details @update:model-value="applyFilter" />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VDataTable :headers="headers" :items="cases.data" hide-default-footer hover :items-per-page="15">
        <template #item.severity="{ item }">
          <VChip :color="item.severity_color" size="x-small" variant="tonal" class="text-capitalize">{{ item.severity }}</VChip>
        </template>
        <template #item.status="{ item }">
          <VChip :color="item.status_color" size="x-small" variant="tonal" class="text-capitalize">{{ item.status }}</VChip>
        </template>
        <template #item.period_start="{ item }">
          <span class="text-caption text-medium-emphasis">{{ item.period_start }} – {{ item.period_end }}</span>
        </template>
        <template #item.actions="{ item }">
          <VBtn icon="tabler-eye" size="x-small" variant="text" color="primary" @click="router.visit(route('attendance.discipline.cases.show', { attendanceDisciplineCase: item.id }))" />
        </template>
        <template #no-data>
          <div class="text-center pa-10">
            <VIcon icon="tabler-gavel" size="56" color="secondary" class="mb-3" />
            <p class="text-body-1 text-medium-emphasis">No discipline cases. Run a scan to detect.</p>
          </div>
        </template>
      </VDataTable>
    </VCard>

    <!-- Scan dialog -->
    <VDialog v-model="scanDialog" max-width="440" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-h6">Run Discipline Scan</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12"><VTextField v-model="scanFrom" label="Period Start *" type="date" variant="outlined" density="comfortable" /></VCol>
            <VCol cols="12"><VTextField v-model="scanTo" label="Period End *" type="date" variant="outlined" density="comfortable" :min="scanFrom" /></VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="scanDialog = false">Cancel</VBtn>
          <VBtn color="warning" variant="flat" :disabled="!scanFrom || !scanTo" @click="runScan">Scan</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
