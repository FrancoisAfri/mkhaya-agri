<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'

type Anomaly = {
  id: number; employee: string; employee_id: number; type: string; severity: string
  severity_color: string; period: string; description: string; status: string
  ai_explanation: string | null; detected_at: string
}
type Paginated<T> = { data: T[]; current_page: number; last_page: number; total: number }

const props = defineProps<{
  anomalies: Paginated<Anomaly>
  summary:   { open: number; critical: number; high: number }
  filters:   { status: string; severity: string | null; type: string | null }
  types:     string[]
  severities: string[]
}>()

const subNav = ref('anomalies')

const scanDialog    = ref(false)
const resolveDialog = ref(false)
const resolveTarget = ref<Anomaly | null>(null)
const explainLoading = ref<number | null>(null)

const scanForm = useForm({
  period_start: new Date(Date.now() - 30 * 86400000).toISOString().split('T')[0],
  period_end:   new Date().toISOString().split('T')[0],
  employee_id:  null as number | null,
})

const resolveForm = useForm({ status: 'resolved' as string, resolution_notes: '' })

const filterStatus   = ref(props.filters.status)
const filterSeverity = ref(props.filters.severity ?? '')
const filterType     = ref(props.filters.type ?? '')

function applyFilters() {
  router.get('/attendance/intelligence/anomalies', {
    status: filterStatus.value,
    severity: filterSeverity.value || undefined,
    type: filterType.value || undefined,
  }, { preserveState: true })
}

function runScan() {
  scanForm.post('/attendance/intelligence/anomalies/scan', { onSuccess: () => { scanDialog.value = false } })
}

function acknowledge(id: number) {
  useForm({}).post(`/attendance/intelligence/anomalies/${id}/acknowledge`, {})
}

function openResolve(a: Anomaly) {
  resolveTarget.value = a
  resolveForm.reset()
  resolveDialog.value = true
}

function submitResolve() {
  if (!resolveTarget.value) return
  resolveForm.post(`/attendance/intelligence/anomalies/${resolveTarget.value.id}/resolve`, {
    onSuccess: () => { resolveDialog.value = false },
  })
}

function explain(id: number) {
  explainLoading.value = id
  useForm({}).post(`/attendance/intelligence/anomalies/${id}/explain`, {
    onFinish: () => { explainLoading.value = null },
  })
}

const typeLabel: Record<string, string> = {
  repeated_lateness: 'Repeated Lateness', unusual_absenteeism: 'Unusual Absenteeism',
  excessive_overtime: 'Excessive Overtime', manipulation: 'Possible Manipulation',
  buddy_clocking: 'Buddy Clocking', missing_clockout: 'Missing Clock-outs',
  suspicious_pattern: 'Suspicious Pattern', shift_abuse: 'Shift Abuse',
}
const statusColor: Record<string, string> = { open: 'warning', acknowledged: 'info', resolved: 'success', false_positive: 'secondary' }
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <VCol cols="12" class="pb-0">
        <VCard variant="outlined">
          <VTabs v-model="subNav" color="primary" density="compact">
            <VTab value="hub" href="/attendance/intelligence"><VIcon start size="16" icon="tabler-robot" />AI Hub</VTab>
            <VTab value="anomalies" href="/attendance/intelligence/anomalies"><VIcon start size="16" icon="tabler-bug" />Anomaly Detection</VTab>
            <VTab value="risk" href="/attendance/intelligence/risk-scores"><VIcon start size="16" icon="tabler-chart-bar" />Risk Scores</VTab>
            <VTab value="patterns" href="/attendance/intelligence/patterns"><VIcon start size="16" icon="tabler-chart-timeline-variant" />Behaviour Patterns</VTab>
          </VTabs>
        </VCard>
      </VCol>
      <!-- Header -->
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div>
              <div class="text-overline text-medium-emphasis">Attendance Intelligence</div>
              <h4 class="text-h4 mb-1">Anomaly Detection</h4>
              <p class="mb-0 text-medium-emphasis">Automated detection of suspicious attendance patterns and behaviours.</p>
            </div>
            <VBtn color="primary" prepend-icon="fas fa-search" @click="scanDialog = true">Run Detection Scan</VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Summary chips -->
      <VCol cols="12">
        <div class="d-flex flex-wrap gap-3">
          <VCard color="warning" variant="tonal" class="px-5 py-3 d-flex align-center gap-3">
            <i class="fas fa-exclamation-triangle" style="font-size:1.5rem"></i>
            <div><div class="text-h5 font-weight-bold">{{ summary.open }}</div><div class="text-caption">Open Anomalies</div></div>
          </VCard>
          <VCard color="deep-purple" variant="tonal" class="px-5 py-3 d-flex align-center gap-3">
            <i class="fas fa-skull" style="font-size:1.5rem"></i>
            <div><div class="text-h5 font-weight-bold">{{ summary.critical }}</div><div class="text-caption">Critical</div></div>
          </VCard>
          <VCard color="error" variant="tonal" class="px-5 py-3 d-flex align-center gap-3">
            <i class="fas fa-radiation" style="font-size:1.5rem"></i>
            <div><div class="text-h5 font-weight-bold">{{ summary.high }}</div><div class="text-caption">High Severity</div></div>
          </VCard>
          <VBtn class="align-self-center" variant="text" color="primary" href="/attendance/intelligence/risk-scores">
            <i class="fas fa-chart-bar me-2"></i>Risk Scores
          </VBtn>
          <VBtn class="align-self-center" variant="text" color="secondary" href="/attendance/intelligence/patterns">
            <i class="fas fa-project-diagram me-2"></i>Patterns
          </VBtn>
          <VBtn class="align-self-center" variant="text" color="info" href="/attendance/intelligence">
            <i class="fas fa-robot me-2"></i>AI Intelligence
          </VBtn>
        </div>
      </VCol>

      <!-- Filters -->
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap gap-3 py-3">
            <VSelect v-model="filterStatus" :items="['all','open','acknowledged','resolved','false_positive'].map(v=>({title:v,value:v}))" label="Status" density="compact" hide-details style="max-width:160px" />
            <VSelect v-model="filterSeverity" :items="[{title:'All Severities',value:''},...severities.map(s=>({title:s,value:s}))]" label="Severity" density="compact" hide-details style="max-width:160px" />
            <VSelect v-model="filterType" :items="[{title:'All Types',value:''},...types.map(t=>({title:typeLabel[t]??t,value:t}))]" label="Type" density="compact" hide-details style="max-width:220px" />
            <VBtn color="primary" @click="applyFilters">Filter</VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Anomaly table -->
      <VCol cols="12">
        <VCard>
          <VTable hover density="comfortable">
            <thead>
              <tr>
                <th>Employee</th>
                <th>Type</th>
                <th>Severity</th>
                <th>Period</th>
                <th>Status</th>
                <th>Detected</th>
                <th class="text-center">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!anomalies.data.length">
                <td colspan="7" class="text-center py-8 text-medium-emphasis">No anomalies found with the current filters.</td>
              </tr>
              <template v-for="a in anomalies.data" :key="a.id">
                <tr>
                  <td class="font-weight-medium">{{ a.employee }}</td>
                  <td><VChip size="small" variant="outlined">{{ typeLabel[a.type] ?? a.type }}</VChip></td>
                  <td><VChip :color="a.severity_color" size="small" variant="tonal" class="text-capitalize">{{ a.severity }}</VChip></td>
                  <td class="text-caption">{{ a.period }}</td>
                  <td><VChip :color="statusColor[a.status] ?? 'grey'" size="small" variant="tonal">{{ a.status }}</VChip></td>
                  <td class="text-caption">{{ a.detected_at }}</td>
                  <td class="text-center">
                    <VBtn v-if="a.status === 'open'" icon size="small" variant="text" color="info" title="Acknowledge" @click="acknowledge(a.id)">
                      <i class="fas fa-check"></i>
                    </VBtn>
                    <VBtn icon size="small" variant="text" color="success" title="Resolve" @click="openResolve(a)">
                      <i class="fas fa-check-double"></i>
                    </VBtn>
                    <VBtn icon size="small" variant="text" color="secondary" title="AI Explain" :loading="explainLoading === a.id" @click="explain(a.id)">
                      <i class="fas fa-robot"></i>
                    </VBtn>
                  </td>
                </tr>
                <!-- Description + AI explanation row -->
                <tr v-if="a.description || a.ai_explanation">
                  <td colspan="7" class="py-2 px-6" style="background:rgba(0,0,0,0.02)">
                    <div class="text-caption text-medium-emphasis mb-1">{{ a.description }}</div>
                    <VAlert v-if="a.ai_explanation" type="info" variant="tonal" density="compact" class="mt-2" style="font-size:0.82rem">
                      <i class="fas fa-robot me-2"></i><strong>AI Context:</strong> {{ a.ai_explanation }}
                    </VAlert>
                  </td>
                </tr>
              </template>
            </tbody>
          </VTable>
          <VCardText v-if="anomalies.last_page > 1" class="d-flex justify-center py-4">
            <VPagination :model-value="anomalies.current_page" :length="anomalies.last_page" @update:model-value="page => router.get('/attendance/intelligence/anomalies', { page }, { preserveState: true })" />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Scan Dialog -->
    <VDialog v-model="scanDialog" max-width="480">
      <VCard>
        <VCardTitle class="py-4 px-5">Run Anomaly Detection Scan</VCardTitle>
        <VDivider />
        <VCardText class="py-4">
          <VRow dense>
            <VCol cols="6"><VTextField v-model="scanForm.period_start" label="Period Start" type="date" density="compact" /></VCol>
            <VCol cols="6"><VTextField v-model="scanForm.period_end" label="Period End" type="date" density="compact" /></VCol>
            <VCol cols="12">
              <VTextField v-model.number="scanForm.employee_id" label="Employee ID (leave blank for all)" type="number" density="compact" clearable />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="px-5 py-3">
          <VSpacer />
          <VBtn variant="text" @click="scanDialog = false">Cancel</VBtn>
          <VBtn color="primary" :loading="scanForm.processing" @click="runScan">
            <i class="fas fa-search me-2"></i>Run Scan
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Resolve Dialog -->
    <VDialog v-model="resolveDialog" max-width="440">
      <VCard>
        <VCardTitle class="py-4 px-5">Update Anomaly Status</VCardTitle>
        <VDivider />
        <VCardText class="py-4">
          <VBtnToggle v-model="resolveForm.status" mandatory class="mb-4 w-100" divided>
            <VBtn value="resolved" class="flex-grow-1"><i class="fas fa-check me-2"></i>Resolved</VBtn>
            <VBtn value="false_positive" class="flex-grow-1"><i class="fas fa-times me-2"></i>False Positive</VBtn>
          </VBtnToggle>
          <VTextarea v-model="resolveForm.resolution_notes" label="Resolution Notes" rows="3" density="compact" />
        </VCardText>
        <VDivider />
        <VCardActions class="px-5 py-3">
          <VSpacer />
          <VBtn variant="text" @click="resolveDialog = false">Cancel</VBtn>
          <VBtn color="success" :loading="resolveForm.processing" @click="submitResolve">Save</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
