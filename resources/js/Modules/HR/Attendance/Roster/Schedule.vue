<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type Assignment = {
  id: number
  employee: string
  employee_id: number
  shift: string | null
  assigned_date: string
  status: string
  status_color: string
  is_ai_suggested: boolean
  is_rest_day: boolean
  notes: string | null
}

type Shift = { id: number; name: string; code: string }

type Schedule = {
  id: number
  name: string
  department: string | null
  period_start: string
  period_end: string
  status: string
  status_color: string
  generated_by: string | null
  coverage_score: number | null
  balance_score: number | null
  ai_optimization_notes: string | null
}

type OptLog = {
  id: number
  optimization_type: string
  coverage_before: number | null
  coverage_after: number | null
  balance_before: number | null
  balance_after: number | null
  changes_count: number
  ai_report: string | null
  recommendations: any
  created_at: string
}

const props = defineProps<{
  schedule: Schedule
  assignments: Assignment[]
  logs: OptLog[]
  imbalances: any[]
  shifts: Shift[]
}>()

const editDialog = ref(false)
const editTarget = ref<Assignment | null>(null)
const scanDialog = ref(false)
const resolveDialog = ref(false)
const resolveTarget = ref<string | null>(null)

const editForm = useForm({ shift_id: '', status: '', is_rest_day: false, notes: '' })

function openEdit(a: Assignment) {
  editTarget.value = a
  editForm.shift_id   = a.shift ? String(props.shifts.find(s => s.name === a.shift)?.id ?? '') : ''
  editForm.status     = a.status
  editForm.is_rest_day= a.is_rest_day
  editForm.notes      = a.notes ?? ''
  editDialog.value    = true
}

function saveEdit() {
  if (!editTarget.value) return
  editForm.patch(route('attendance.roster.assignments.update', { rosterAssignment: editTarget.value.id }), {
    onSuccess: () => { editDialog.value = false },
  })
}

function generateAi()    { router.post(route('attendance.roster.generate-ai', { rosterSchedule: props.schedule.id })) }
function optimizeStaff() { router.post(route('attendance.roster.optimize-staffing', { rosterSchedule: props.schedule.id })) }
function optimizeDist()  { router.post(route('attendance.roster.optimize-distribution', { rosterSchedule: props.schedule.id })) }
function aiReport()      { router.post(route('attendance.roster.ai-report', { rosterSchedule: props.schedule.id })) }
function publish()       { router.post(route('attendance.roster.publish', { rosterSchedule: props.schedule.id })) }

const statusColor = (s: string) => s === 'confirmed' ? 'success' : s === 'scheduled' ? 'info' : s === 'swapped' ? 'warning' : 'error'

const groupedByDate = () => {
  const map: Record<string, Assignment[]> = {}
  for (const a of props.assignments) {
    if (!map[a.assigned_date]) map[a.assigned_date] = []
    map[a.assigned_date].push(a)
  }
  return Object.entries(map).sort(([a], [b]) => a.localeCompare(b))
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.roster.index'))" />
        <div>
          <h4 class="text-h4">{{ schedule.name }}</h4>
          <p class="text-medium-emphasis mb-0">{{ schedule.period_start }} → {{ schedule.period_end }}
            <span v-if="schedule.department"> · {{ schedule.department }}</span>
          </p>
        </div>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <VBtn size="small" color="primary" variant="tonal" prepend-icon="tabler-robot" @click="generateAi">AI Generate</VBtn>
        <VBtn size="small" color="info" variant="tonal" prepend-icon="tabler-arrows-sort" @click="optimizeStaff">Optimise Staff</VBtn>
        <VBtn size="small" color="secondary" variant="tonal" prepend-icon="tabler-refresh" @click="optimizeDist">Optimise Dist.</VBtn>
        <VBtn size="small" color="warning" variant="tonal" prepend-icon="tabler-report" @click="aiReport">AI Report</VBtn>
        <VBtn v-if="schedule.status === 'draft'" size="small" color="success" variant="flat" @click="publish">Publish</VBtn>
      </div>
    </div>

    <!-- KPIs -->
    <VRow class="mb-5">
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3">
        <h6 class="text-h6"><VChip :color="schedule.status_color" size="small" variant="tonal" class="text-capitalize">{{ schedule.status }}</VChip></h6>
        <p class="text-caption text-medium-emphasis mb-0">Status</p>
      </VCardText></VCard></VCol>
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3">
        <h6 class="text-h6 text-success">{{ schedule.coverage_score ?? '—' }}%</h6>
        <p class="text-caption text-medium-emphasis mb-0">Coverage Score</p>
      </VCardText></VCard></VCol>
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3">
        <h6 class="text-h6 text-info">{{ schedule.balance_score ?? '—' }}%</h6>
        <p class="text-caption text-medium-emphasis mb-0">Balance Score</p>
      </VCardText></VCard></VCol>
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="text-center pa-3">
        <h6 class="text-h6">{{ assignments.length }}</h6>
        <p class="text-caption text-medium-emphasis mb-0">Assignments</p>
      </VCardText></VCard></VCol>
    </VRow>

    <!-- AI Notes -->
    <VAlert v-if="schedule.ai_optimization_notes" type="info" variant="tonal" class="mb-5" icon="tabler-robot">
      {{ schedule.ai_optimization_notes }}
    </VAlert>

    <!-- Imbalances -->
    <VAlert v-if="imbalances.length" type="warning" variant="tonal" class="mb-5" icon="tabler-alert-triangle">
      {{ imbalances.length }} shift imbalance(s) detected. Consider running optimisation.
    </VAlert>

    <!-- Assignments -->
    <VCard elevation="0" border class="mb-5">
      <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Assignments</VCardTitle>
      <VDivider />
      <VCardText class="pa-0">
        <div v-if="assignments.length === 0" class="text-center py-12">
          <VIcon icon="tabler-calendar-plus" size="56" color="secondary" class="mb-3" />
          <p class="text-body-1 text-medium-emphasis">No assignments yet. Use AI Generate to populate.</p>
        </div>
        <div v-for="[date, items] in groupedByDate()" :key="date">
          <div class="pa-3 bg-surface-variant text-caption font-weight-semibold text-medium-emphasis">{{ date }}</div>
          <VDivider />
          <VList density="compact">
            <VListItem v-for="a in items" :key="a.id">
              <template #title>
                <span class="text-body-2">{{ a.employee }}</span>
                <VChip v-if="a.is_rest_day" size="x-small" color="secondary" variant="tonal" class="ms-2">Rest</VChip>
                <VChip v-if="a.is_ai_suggested" size="x-small" color="primary" variant="tonal" class="ms-1">AI</VChip>
              </template>
              <template #subtitle>
                <span class="text-caption text-medium-emphasis">{{ a.shift ?? 'No Shift' }}</span>
              </template>
              <template #append>
                <div class="d-flex align-center gap-2">
                  <VChip :color="statusColor(a.status)" size="x-small" variant="tonal" class="text-capitalize">{{ a.status }}</VChip>
                  <VBtn icon="tabler-pencil" size="x-small" variant="text" @click="openEdit(a)" />
                </div>
              </template>
            </VListItem>
          </VList>
          <VDivider />
        </div>
      </VCardText>
    </VCard>

    <!-- Optimisation logs -->
    <VCard v-if="logs.length" elevation="0" border>
      <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Optimisation History</VCardTitle>
      <VDivider />
      <VList>
        <VListItem v-for="l in logs" :key="l.id">
          <template #title>
            <span class="text-body-2 font-weight-semibold">{{ l.optimization_type }}</span>
          </template>
          <template #subtitle>
            <span class="text-caption text-medium-emphasis">
              Coverage: {{ l.coverage_before }}% → {{ l.coverage_after }}% ·
              Balance: {{ l.balance_before }}% → {{ l.balance_after }}% ·
              {{ l.changes_count }} change(s) · {{ l.created_at }}
            </span>
          </template>
        </VListItem>
      </VList>
    </VCard>

    <!-- Edit dialog -->
    <VDialog v-model="editDialog" max-width="440" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-h6">Edit Assignment</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12">
              <VSelect v-model="editForm.shift_id" :items="shifts.map(s => ({ title: s.name, value: String(s.id) }))" item-title="title" item-value="value" label="Shift" variant="outlined" density="comfortable" clearable />
            </VCol>
            <VCol cols="12">
              <VSelect v-model="editForm.status" :items="['scheduled','confirmed','swapped','cancelled','absent']" label="Status" variant="outlined" density="comfortable" />
            </VCol>
            <VCol cols="12">
              <VSwitch v-model="editForm.is_rest_day" label="Rest Day" density="comfortable" />
            </VCol>
            <VCol cols="12">
              <VTextField v-model="editForm.notes" label="Notes" variant="outlined" density="comfortable" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="editDialog = false">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :loading="editForm.processing" @click="saveEdit">Save</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
