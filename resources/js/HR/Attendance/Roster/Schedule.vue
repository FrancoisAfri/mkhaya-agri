<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'

type Schedule = {
  id: number; name: string; department: string | null
  period_start: string; period_end: string
  status: string; status_color: string; generated_by: string
  coverage_score: number | null; balance_score: number | null
  ai_optimization_notes: string | null
}
type Assignment = {
  id: number; employee: string; employee_id: number
  shift: string | null; assigned_date: string
  status: string; status_color: string
  is_ai_suggested: boolean; is_rest_day: boolean; notes: string | null
}
type OptLog = {
  id: number; optimization_type: string
  coverage_before: number | null; coverage_after: number | null
  balance_before: number | null; balance_after: number | null
  changes_count: number; ai_report: string | null; recommendations: string[]
  created_at: string
}
type Imbalance = { employee: string; issue: string; work_pct: number }
type Shift     = { id: number; name: string; code: string }

const props = defineProps<{
  schedule:    Schedule
  assignments: Assignment[]
  logs:        OptLog[]
  imbalances:  Imbalance[]
  shifts:      Shift[]
}>()

const tab           = ref('roster')
const editDialog    = ref(false)
const editTarget    = ref<Assignment | null>(null)
const aiLoading     = ref<string | null>(null)

const editForm = useForm({
  shift_id:    null as number | null,
  status:      'scheduled' as string,
  is_rest_day: false,
  notes:       '',
})

// Group assignments by date for calendar view
const byDate = computed(() => {
  const map: Record<string, Assignment[]> = {}
  for (const a of props.assignments) {
    if (!map[a.assigned_date]) map[a.assigned_date] = []
    map[a.assigned_date].push(a)
  }
  return map
})

const sortedDates = computed(() => Object.keys(byDate.value).sort())

function openEdit(a: Assignment) {
  editTarget.value = a
  editForm.shift_id   = null
  editForm.status      = a.status
  editForm.is_rest_day = a.is_rest_day
  editForm.notes       = a.notes ?? ''
  editDialog.value    = true
}

function saveEdit() {
  if (!editTarget.value) return
  editForm.put(`/attendance/roster/assignments/${editTarget.value.id}`, {
    onSuccess: () => { editDialog.value = false },
  })
}

function runAction(action: string) {
  aiLoading.value = action
  useForm({}).post(`/attendance/roster/${props.schedule.id}/${action}`, {
    onFinish: () => { aiLoading.value = null },
  })
}

function scoreColor(score: number | null): string {
  if (score === null) return 'grey'
  if (score >= 80) return 'success'
  if (score >= 60) return 'warning'
  return 'error'
}

function weekday(dateStr: string): string {
  return new Date(dateStr + 'T00:00:00').toLocaleDateString('en-ZA', { weekday: 'short', day: '2-digit', month: 'short' })
}

const isPublished = computed(() => props.schedule.status === 'published')
const isDraft     = computed(() => props.schedule.status === 'draft')
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <!-- Header card -->
      <VCol cols="12">
        <VCard>
          <VCardText class="py-5">
            <div class="d-flex flex-wrap align-start justify-space-between gap-4">
              <div>
                <div class="text-overline text-medium-emphasis">Attendance Intelligence</div>
                <h4 class="text-h4 mb-1">{{ schedule.name }}</h4>
                <div class="d-flex flex-wrap gap-2 align-center">
                  <VChip :color="schedule.status_color" size="small" variant="tonal" class="text-capitalize">{{ schedule.status }}</VChip>
                  <VChip :color="schedule.generated_by === 'ai' ? 'info' : 'secondary'" size="x-small" variant="tonal">
                    <i :class="schedule.generated_by === 'ai' ? 'fas fa-robot me-1' : 'fas fa-user me-1'" style="font-size:10px"></i>
                    {{ schedule.generated_by }}
                  </VChip>
                  <span class="text-caption text-medium-emphasis">{{ schedule.period_start }} – {{ schedule.period_end }}</span>
                  <span v-if="schedule.department" class="text-caption text-medium-emphasis">| {{ schedule.department }}</span>
                </div>
              </div>

              <!-- Score gauges -->
              <div class="d-flex gap-4">
                <div class="text-center">
                  <VProgressCircular
                    :model-value="schedule.coverage_score ?? 0"
                    :color="scoreColor(schedule.coverage_score)"
                    size="56"
                    width="5"
                  >
                    <span class="text-caption font-weight-bold">{{ schedule.coverage_score ?? '—' }}</span>
                  </VProgressCircular>
                  <div class="text-caption text-medium-emphasis mt-1">Coverage</div>
                </div>
                <div class="text-center">
                  <VProgressCircular
                    :model-value="schedule.balance_score ?? 0"
                    :color="scoreColor(schedule.balance_score)"
                    size="56"
                    width="5"
                  >
                    <span class="text-caption font-weight-bold">{{ schedule.balance_score ?? '—' }}</span>
                  </VProgressCircular>
                  <div class="text-caption text-medium-emphasis mt-1">Balance</div>
                </div>
              </div>
            </div>

            <!-- Action buttons -->
            <div class="d-flex flex-wrap gap-2 mt-4">
              <template v-if="isDraft">
                <VBtn color="info" size="small" :loading="aiLoading === 'generate-ai'" @click="runAction('generate-ai')">
                  <i class="fas fa-robot me-2"></i>AI Generate Roster
                </VBtn>
                <VBtn color="warning" size="small" :loading="aiLoading === 'optimize-staffing'" @click="runAction('optimize-staffing')">
                  <i class="fas fa-users me-2"></i>Optimise Staffing
                </VBtn>
                <VBtn color="secondary" size="small" :loading="aiLoading === 'optimize-distribution'" @click="runAction('optimize-distribution')">
                  <i class="fas fa-layer-group me-2"></i>Optimise Shifts
                </VBtn>
                <VBtn color="deep-purple" size="small" :loading="aiLoading === 'ai-report'" @click="runAction('ai-report')">
                  <i class="fas fa-file-alt me-2"></i>AI Report
                </VBtn>
                <VBtn color="success" size="small" :loading="aiLoading === 'publish'" @click="runAction('publish')">
                  <i class="fas fa-paper-plane me-2"></i>Publish
                </VBtn>
              </template>
              <template v-else-if="isPublished">
                <VBtn color="secondary" size="small" :loading="aiLoading === 'archive'" @click="runAction('archive')">
                  <i class="fas fa-archive me-2"></i>Archive
                </VBtn>
              </template>
              <VBtn variant="text" color="primary" href="/attendance/roster">
                <i class="fas fa-arrow-left me-2"></i>Back to Rosters
              </VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Imbalance alerts -->
      <VCol cols="12" v-if="imbalances.length">
        <VAlert type="warning" variant="tonal" density="compact">
          <strong>{{ imbalances.length }} workload imbalance(s) detected:</strong>
          <span v-for="(b, i) in imbalances" :key="i" class="ms-2">
            {{ b.employee }} ({{ b.issue }}, {{ b.work_pct }}% load){{ i < imbalances.length - 1 ? ' •' : '' }}
          </span>
        </VAlert>
      </VCol>

      <!-- AI Notes -->
      <VCol cols="12" v-if="schedule.ai_optimization_notes">
        <VCard variant="outlined" color="info">
          <VCardText class="py-3">
            <div class="d-flex align-center gap-2 mb-2">
              <VAvatar color="info" variant="tonal" size="28"><i class="fas fa-robot" style="font-size:12px"></i></VAvatar>
              <span class="font-weight-medium">AI Optimisation Report</span>
            </div>
            <div style="white-space:pre-wrap;font-size:0.88rem">{{ schedule.ai_optimization_notes }}</div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Tabs -->
      <VCol cols="12">
        <VTabs v-model="tab" color="primary">
          <VTab value="roster"><i class="fas fa-calendar-alt me-2"></i>Roster View</VTab>
          <VTab value="list"><i class="fas fa-list me-2"></i>List View</VTab>
          <VTab value="logs"><i class="fas fa-history me-2"></i>Optimisation Log</VTab>
        </VTabs>
        <VDivider />

        <VWindow v-model="tab">
          <!-- Roster calendar-style view -->
          <VWindowItem value="roster">
            <VCard>
              <VCardText class="py-4">
                <div v-if="!sortedDates.length" class="text-center text-medium-emphasis py-10">
                  No assignments yet. Use "AI Generate Roster" or add assignments manually.
                </div>
                <div v-else class="d-flex flex-column gap-3">
                  <div v-for="date in sortedDates" :key="date">
                    <div class="text-caption font-weight-bold text-medium-emphasis mb-1 text-uppercase">
                      {{ weekday(date) }}
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                      <VChip
                        v-for="a in byDate[date]"
                        :key="a.id"
                        :color="a.is_rest_day ? 'secondary' : a.status_color"
                        variant="tonal"
                        size="small"
                        :prepend-icon="a.is_ai_suggested ? 'fas fa-robot' : undefined"
                        @click="openEdit(a)"
                        style="cursor:pointer"
                      >
                        {{ a.employee }}
                        <span v-if="a.shift" class="ms-1 text-caption opacity-80">· {{ a.shift }}</span>
                        <span v-if="a.is_rest_day" class="ms-1 text-caption opacity-80">· REST</span>
                      </VChip>
                    </div>
                  </div>
                </div>
              </VCardText>
            </VCard>
          </VWindowItem>

          <!-- List view -->
          <VWindowItem value="list">
            <VCard>
              <VTable hover density="comfortable">
                <thead>
                  <tr>
                    <th>Employee</th>
                    <th>Date</th>
                    <th>Shift</th>
                    <th>Status</th>
                    <th>Rest Day</th>
                    <th>AI Suggested</th>
                    <th>Notes</th>
                    <th class="text-center">Edit</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-if="!assignments.length">
                    <td colspan="8" class="text-center py-8 text-medium-emphasis">No assignments.</td>
                  </tr>
                  <tr v-for="a in assignments" :key="a.id">
                    <td class="font-weight-medium">{{ a.employee }}</td>
                    <td class="text-caption">{{ a.assigned_date }}</td>
                    <td class="text-caption">{{ a.shift ?? '—' }}</td>
                    <td><VChip :color="a.status_color" size="x-small" variant="tonal" class="text-capitalize">{{ a.status }}</VChip></td>
                    <td>
                      <VChip v-if="a.is_rest_day" size="x-small" color="secondary" variant="tonal">Rest</VChip>
                      <span v-else class="text-caption text-medium-emphasis">—</span>
                    </td>
                    <td>
                      <i v-if="a.is_ai_suggested" class="fas fa-robot text-info" title="AI Suggested"></i>
                    </td>
                    <td class="text-caption">{{ a.notes ?? '—' }}</td>
                    <td class="text-center">
                      <VBtn icon size="x-small" variant="text" color="primary" @click="openEdit(a)">
                        <i class="fas fa-edit"></i>
                      </VBtn>
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </VCard>
          </VWindowItem>

          <!-- Optimisation log -->
          <VWindowItem value="logs">
            <VCard>
              <VCardText class="py-4">
                <div v-if="!logs.length" class="text-center text-medium-emphasis py-8">
                  No optimisation runs yet.
                </div>
                <VTimeline density="compact" side="end" v-else>
                  <VTimelineItem
                    v-for="log in logs"
                    :key="log.id"
                    dot-color="primary"
                    size="small"
                  >
                    <template #opposite>
                      <div class="text-caption text-medium-emphasis">{{ log.created_at.substring(0,16) }}</div>
                    </template>
                    <VCard variant="outlined" class="pa-3">
                      <div class="font-weight-medium text-body-2 mb-2">{{ log.optimization_type }}</div>
                      <div class="d-flex gap-4 text-caption mb-2">
                        <div>
                          Coverage: <strong>{{ log.coverage_before ?? '—' }}%</strong>
                          <i class="fas fa-arrow-right mx-1"></i>
                          <strong :class="(log.coverage_after ?? 0) >= (log.coverage_before ?? 0) ? 'text-success' : 'text-error'">{{ log.coverage_after ?? '—' }}%</strong>
                        </div>
                        <div>
                          Balance: <strong>{{ log.balance_before ?? '—' }}%</strong>
                          <i class="fas fa-arrow-right mx-1"></i>
                          <strong :class="(log.balance_after ?? 0) >= (log.balance_before ?? 0) ? 'text-success' : 'text-error'">{{ log.balance_after ?? '—' }}%</strong>
                        </div>
                        <div class="text-medium-emphasis">{{ log.changes_count }} change(s)</div>
                      </div>
                      <div v-if="log.recommendations?.length" class="mb-2">
                        <div v-for="r in log.recommendations" :key="r" class="text-caption">
                          <i class="fas fa-check-circle text-success me-1"></i>{{ r }}
                        </div>
                      </div>
                      <div v-if="log.ai_report" class="text-caption text-medium-emphasis mt-2" style="white-space:pre-wrap;font-size:0.8rem">
                        {{ log.ai_report }}
                      </div>
                    </VCard>
                  </VTimelineItem>
                </VTimeline>
              </VCardText>
            </VCard>
          </VWindowItem>
        </VWindow>
      </VCol>
    </VRow>

    <!-- Edit Assignment Dialog -->
    <VDialog v-model="editDialog" max-width="400">
      <VCard v-if="editTarget">
        <VCardTitle class="py-4 px-5">
          Edit Assignment — {{ editTarget.employee }}<br>
          <span class="text-caption text-medium-emphasis font-weight-regular">{{ editTarget.assigned_date }}</span>
        </VCardTitle>
        <VDivider />
        <VCardText class="py-4">
          <VSelect
            v-model="editForm.shift_id"
            :items="[{title:'No Shift',value:null},...shifts.map(s=>({title:s.name,value:s.id}))]"
            label="Shift"
            density="compact"
            class="mb-3"
          />
          <VSelect
            v-model="editForm.status"
            :items="['scheduled','confirmed','swapped','cancelled','absent'].map(v=>({title:v,value:v}))"
            label="Status"
            density="compact"
            class="mb-3"
          />
          <VSwitch v-model="editForm.is_rest_day" label="Mark as Rest Day" density="compact" class="mb-2" />
          <VTextarea v-model="editForm.notes" label="Notes" rows="2" density="compact" />
        </VCardText>
        <VDivider />
        <VCardActions class="px-5 py-3">
          <VSpacer />
          <VBtn variant="text" @click="editDialog = false">Cancel</VBtn>
          <VBtn color="primary" :loading="editForm.processing" @click="saveEdit">Save</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
