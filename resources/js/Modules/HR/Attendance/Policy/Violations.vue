<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Violation = {
  id: number
  employee: string
  employee_id: number
  policy_rule: string | null
  category: string
  category_label: string
  severity: string
  severity_color: string
  status: string
  status_color: string
  blocked: boolean
  ai_enforcement_decision: string | null
  period_start: string
  period_end: string
  escalated_at: string | null
  resolved_at: string | null
  resolution_notes: string | null
  created_at: string
}

const props = defineProps<{
  violations: { data: Violation[]; links: any[]; meta: any }
  filters: { status: string; category: string }
  categories: Record<string, string>
}>()

const statusFilter   = ref(props.filters.status)
const categoryFilter = ref(props.filters.category)
const resolveDialog  = ref(false)
const resolveTarget  = ref<Violation | null>(null)
const resolveForm    = useForm({ resolution_notes: '' })

function applyFilter() {
  router.get(route('attendance.policy.violations'), {
    status:   statusFilter.value || undefined,
    category: categoryFilter.value || undefined,
  }, { preserveState: true, replace: true })
}

function openResolve(v: Violation) { resolveTarget.value = v; resolveForm.resolution_notes = ''; resolveDialog.value = true }

function submitResolve() {
  if (!resolveTarget.value) return
  resolveForm.post(route('attendance.policy.violations.resolve', { attendancePolicyViolation: resolveTarget.value.id }), {
    onSuccess: () => { resolveDialog.value = false },
  })
}

function escalate(id: number) { router.post(route('attendance.policy.violations.escalate', { attendancePolicyViolation: id })) }

const catItems = [{ title: 'All', value: '' }, ...Object.entries(props.categories).map(([v, t]) => ({ title: t, value: v }))]

const headers = [
  { title: 'Employee',  key: 'employee',       sortable: false },
  { title: 'Rule',      key: 'policy_rule',    sortable: false },
  { title: 'Category',  key: 'category_label', sortable: false },
  { title: 'Severity',  key: 'severity',       sortable: false },
  { title: 'Status',    key: 'status',         sortable: false },
  { title: 'Period',    key: 'period_start',   sortable: false },
  { title: '',          key: 'actions',        sortable: false, align: 'end' as const },
]
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center gap-2 mb-6">
      <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.policy.index'))" />
      <div>
        <h4 class="text-h4">Policy Violations</h4>
        <p class="text-medium-emphasis mb-0">All detected attendance policy violations</p>
      </div>
    </div>

    <!-- Filters -->
    <VCard elevation="0" border class="mb-5">
      <VCardText class="pa-4">
        <VRow dense align="center">
          <VCol cols="12" sm="4">
            <VSelect v-model="statusFilter" :items="[{title:'All',value:''},{title:'Open',value:'open'},{title:'Escalated',value:'escalated'},{title:'Resolved',value:'resolved'},{title:'Excepted',value:'excepted'}]" item-title="title" item-value="value" label="Status" variant="outlined" density="compact" hide-details @update:model-value="applyFilter" />
          </VCol>
          <VCol cols="12" sm="4">
            <VSelect v-model="categoryFilter" :items="catItems" item-title="title" item-value="value" label="Category" variant="outlined" density="compact" hide-details @update:model-value="applyFilter" />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VDataTable :headers="headers" :items="violations.data" hide-default-footer hover :items-per-page="20">
        <template #item.severity="{ item }">
          <VChip :color="item.severity_color" size="x-small" variant="tonal" class="text-capitalize">{{ item.severity }}</VChip>
        </template>
        <template #item.status="{ item }">
          <div class="d-flex align-center gap-1">
            <VChip :color="item.status_color" size="x-small" variant="tonal" class="text-capitalize">{{ item.status }}</VChip>
            <VIcon v-if="item.blocked" icon="tabler-lock" size="14" color="error" title="Blocked" />
          </div>
        </template>
        <template #item.period_start="{ item }">
          <span class="text-caption text-medium-emphasis">{{ item.period_start }} – {{ item.period_end }}</span>
        </template>
        <template #item.actions="{ item }">
          <div class="d-flex gap-1">
            <VBtn v-if="item.status === 'open'" icon="tabler-arrow-up" size="x-small" variant="text" color="warning" title="Escalate" @click="escalate(item.id)" />
            <VBtn v-if="!['resolved','excepted'].includes(item.status)" icon="tabler-circle-check" size="x-small" variant="text" color="success" title="Resolve" @click="openResolve(item)" />
          </div>
        </template>
        <template #no-data><div class="text-center pa-6 text-medium-emphasis">No violations found.</div></template>
      </VDataTable>
    </VCard>

    <!-- Resolve dialog -->
    <VDialog v-model="resolveDialog" max-width="440" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-h6">Resolve Violation</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <p class="text-body-2 text-medium-emphasis mb-4">Employee: <strong>{{ resolveTarget?.employee }}</strong></p>
          <VTextarea v-model="resolveForm.resolution_notes" label="Resolution Notes *" variant="outlined" rows="3" :error-messages="resolveForm.errors.resolution_notes" />
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="resolveDialog = false">Cancel</VBtn>
          <VBtn color="success" variant="flat" :loading="resolveForm.processing" :disabled="!resolveForm.resolution_notes" @click="submitResolve">Resolve</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
