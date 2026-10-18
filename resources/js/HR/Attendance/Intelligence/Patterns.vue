<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'

type Pattern = {
  id: number; employee: string; pattern_type: string; period_type: string
  period: string; data: Record<string, any> | null; insight: string | null; confidence: number | null
}
type Employee = { id: number; first_name: string; surname: string }
type Paginated<T> = { data: T[]; current_page: number; last_page: number; total: number }

const props = defineProps<{
  patterns:  Paginated<Pattern>
  employees: Employee[]
  filters:   { employee_id: number | null }
}>()

const subNav           = ref('patterns')
const selectedEmployee = ref(props.filters.employee_id ?? null)

function filter() {
  router.get('/attendance/intelligence/patterns', { employee_id: selectedEmployee.value || undefined }, { preserveState: true })
}

const patternLabel: Record<string, string> = {
  late_days:            'Lateness by Day',
  absence_days:         'Absence Pattern',
  overtime_days:        'Overtime Behaviour',
  early_departure_days: 'Early Departures',
  work_type_preference: 'Work Type Preference',
  buddy_pair:           'Buddy Clocking Pair',
  shift_deviation:      'Shift Deviation',
}
const patternIcon: Record<string, string> = {
  late_days:            'fas fa-clock',
  absence_days:         'fas fa-user-slash',
  overtime_days:        'fas fa-hourglass-half',
  early_departure_days: 'fas fa-running',
  work_type_preference: 'fas fa-home',
  buddy_pair:           'fas fa-user-friends',
  shift_deviation:      'fas fa-layer-group',
}
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
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div>
              <div class="text-overline text-medium-emphasis">Attendance Intelligence</div>
              <h4 class="text-h4 mb-1">Behaviour Patterns</h4>
              <p class="mb-0 text-medium-emphasis">Detected recurring patterns with AI-generated insights.</p>
            </div>
            <div class="d-flex gap-3 align-center">
              <VSelect
                v-model="selectedEmployee"
                :items="[{title:'All Employees',value:null},...employees.map(e=>({title:`${e.first_name} ${e.surname}`,value:e.id}))]"
                label="Filter by Employee"
                density="compact"
                hide-details
                style="min-width:220px"
                @update:model-value="filter"
              />
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" v-if="!patterns.data.length">
        <VAlert type="info" variant="tonal">
          No behaviour patterns detected yet. Run an anomaly scan to build pattern data.
        </VAlert>
      </VCol>

      <VCol cols="12" md="6" lg="4" v-for="p in patterns.data" :key="p.id">
        <VCard height="100%">
          <VCardTitle class="py-4 px-5 d-flex align-center gap-2">
            <VAvatar color="primary" variant="tonal" size="32">
              <i :class="patternIcon[p.pattern_type] ?? 'fas fa-chart-line'" style="font-size:13px"></i>
            </VAvatar>
            {{ patternLabel[p.pattern_type] ?? p.pattern_type }}
          </VCardTitle>
          <VDivider />
          <VCardText class="py-3">
            <div class="d-flex justify-space-between mb-2">
              <span class="text-caption text-medium-emphasis">Employee</span>
              <span class="font-weight-medium text-caption">{{ p.employee }}</span>
            </div>
            <div class="d-flex justify-space-between mb-2">
              <span class="text-caption text-medium-emphasis">Period</span>
              <span class="text-caption">{{ p.period }}</span>
            </div>
            <div class="d-flex justify-space-between mb-3">
              <span class="text-caption text-medium-emphasis">Confidence</span>
              <VChip :color="(p.confidence ?? 0) >= 80 ? 'success' : 'warning'" size="x-small" variant="tonal">
                {{ p.confidence ?? '—' }}%
              </VChip>
            </div>

            <!-- Pattern data summary -->
            <div v-if="p.data" class="mb-3 pa-2 rounded" style="background:rgba(0,0,0,0.04)">
              <template v-if="p.pattern_type === 'late_days' && p.data.by_weekday">
                <div class="text-caption mb-1 font-weight-medium">Late by day:</div>
                <div class="d-flex flex-wrap gap-1">
                  <VChip v-for="(count, day) in p.data.by_weekday" :key="day" size="x-small" color="warning" variant="tonal">
                    {{ day }}: {{ count }}×
                  </VChip>
                </div>
              </template>
              <template v-else-if="p.pattern_type === 'work_type_preference'">
                <div class="d-flex flex-wrap gap-1">
                  <VChip v-for="(count, type) in p.data" :key="type" size="x-small" color="info" variant="tonal">
                    {{ type }}: {{ count }}×
                  </VChip>
                </div>
              </template>
              <template v-else>
                <div class="text-caption" v-for="(val, key) in p.data" :key="key">
                  <span class="text-medium-emphasis">{{ key }}:</span> {{ val }}
                </div>
              </template>
            </div>

            <!-- AI Insight -->
            <VAlert v-if="p.insight" type="info" variant="tonal" density="compact" style="font-size:0.8rem">
              <i class="fas fa-robot me-1"></i>{{ p.insight }}
            </VAlert>
            <div v-else class="text-caption text-medium-emphasis font-italic">AI insight not yet generated.</div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" v-if="patterns.last_page > 1">
        <div class="d-flex justify-center">
          <VPagination :model-value="patterns.current_page" :length="patterns.last_page" @update:model-value="page => router.get('/attendance/intelligence/patterns', { page }, { preserveState: true })" />
        </div>
      </VCol>
    </VRow>
  </VContainer>
</template>
