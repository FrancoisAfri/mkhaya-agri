<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()

type Cycle    = { id: number; name: string; status: string }
type Goal     = { id: number; title: string; cycle_id: number | null }
type Employee = { id: number; name: string }

type Kpi = {
  id: number
  cycle_id: number
  employee_id: number
  employee_name: string
  goal_id: number | null
  goal_title: string | null
  title: string
  description: string | null
  target_value: number | null
  unit: string | null
  weighting: number
  self_update_allowed: boolean
  manager_update_allowed: boolean
  status: string
  latest_value: number | null
  score_pct: number | null
  weighted_score: number | null
  progress_logs_count: number
}

const props = defineProps<{
  kpis: Kpi[]
  cycles: Cycle[]
  goals: Goal[]
  employees: Employee[]
  selectedCycleId: number | null
}>()

const drawer  = ref(false)
const delOpen = ref(false)
const delItem = ref<Kpi | null>(null)
const editing = ref<Partial<Kpi> | null>(null)
const progressOpen  = ref(false)
const progressKpi   = ref<Kpi | null>(null)
const progressValue = ref<number>(0)
const progressNotes = ref<string>('')
const progressDate  = ref<string>(new Date().toISOString().substring(0, 10))

// Score All dialog
const scoreAllOpen       = ref(false)
const scoreAllEmployeeId = ref<number | null>(null)
const scoreAllCycleId    = ref<number | null>(null)

const selectedCycle = ref<number | null>(props.selectedCycleId)

const filteredGoals = computed(() =>
  editing.value?.cycle_id
    ? props.goals.filter(g => g.cycle_id === null || g.cycle_id === editing.value?.cycle_id)
    : props.goals
)

// Goals already linked to this employee+cycle (excluding current KPI when editing)
const takenGoalIds = computed(() => {
  if (!editing.value?.employee_id || !editing.value?.cycle_id) return new Set<number>()
  return new Set(
    props.kpis
      .filter(k => k.employee_id === editing.value!.employee_id && k.cycle_id === editing.value!.cycle_id && k.id !== editing.value!.id && k.goal_id != null)
      .map(k => k.goal_id as number)
  )
})

const availableGoals = computed(() =>
  filteredGoals.value.map(g => ({
    ...g,
    disabled: takenGoalIds.value.has(g.id),
    title: takenGoalIds.value.has(g.id) ? g.title + ' (already assigned)' : g.title,
  }))
)

const usedWeighting = computed(() => {
  if (!editing.value?.employee_id || !editing.value?.cycle_id) return 0
  return props.kpis
    .filter(k => k.employee_id === editing.value!.employee_id && k.cycle_id === editing.value!.cycle_id && k.id !== editing.value!.id)
    .reduce((sum, k) => sum + (k.weighting ?? 0), 0)
})
const remainingWeighting = computed(() => 100 - usedWeighting.value)

function openDrawer(kpi?: Kpi) {
  editing.value = kpi ? { ...kpi } : {
    title: '', description: null, target_value: null, unit: '%', weighting: 0,
    self_update_allowed: true, manager_update_allowed: true, status: 'pending',
    cycle_id: selectedCycle.value ?? undefined, employee_id: undefined, goal_id: null,
  }
  drawer.value = true
}

function save() {
  if (!editing.value) return
  const k = editing.value
  if (k.id) {
    router.patch(route('performance.kpis.update', k.id), k, { onSuccess: () => { drawer.value = false } })
  } else {
    router.post(route('performance.kpis.store'), k, { onSuccess: () => { drawer.value = false } })
  }
}

function filterByCycle() {
  router.get(route('performance.kpis.index'), { cycle_id: selectedCycle.value }, { preserveState: true, replace: true })
}

function openProgress(kpi: Kpi) {
  progressKpi.value = kpi
  progressValue.value = kpi.latest_value ?? 0
  progressNotes.value = ''
  progressDate.value = new Date().toISOString().substring(0, 10)
  progressOpen.value = true
}

function saveProgress() {
  if (!progressKpi.value) return
  router.post(route('performance.kpis.progress.log', progressKpi.value.id), {
    value: progressValue.value, notes: progressNotes.value, log_date: progressDate.value,
  }, { onSuccess: () => { progressOpen.value = false } })
}

function scoreKpi(kpi: Kpi) {
  router.post(route('performance.kpis.score', kpi.id), {}, { preserveScroll: true })
}

function openScoreAll() {
  scoreAllEmployeeId.value = null
  scoreAllCycleId.value = props.selectedCycleId
  scoreAllOpen.value = true
}

function submitScoreAll() {
  if (!scoreAllEmployeeId.value || !scoreAllCycleId.value) return
  router.post(
    route('performance.kpis.score.employee', scoreAllCycleId.value),
    { employee_id: scoreAllEmployeeId.value },
    { onSuccess: () => { scoreAllOpen.value = false } }
  )
}

const statusColor = (s: string) => ({
  pending: 'default', in_progress: 'primary', completed: 'success', cancelled: 'error',
})[s] ?? 'default'

const statuses = ['pending', 'in_progress', 'completed', 'cancelled']
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('pa.kpis.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">{{ t('pa.kpis.subtitle') }}</p>
      </div>
      <VSpacer />
      <VSelect
        v-model="selectedCycle"
        :items="[{ id: null, name: 'All Cycles', status: '' }, ...props.cycles]"
        item-title="name" item-value="id"
        density="compact" style="max-width:220px" class="mr-3"
        @update:model-value="filterByCycle"
      />
      <VBtn variant="outlined" prepend-icon="ri-medal-line" class="mr-2" @click="openScoreAll()">Score All KPIs</VBtn>
      <VBtn color="primary" prepend-icon="ri-add-line" @click="openDrawer()">{{ t('pa.kpis.assign') }}</VBtn>
    </div>

    <VCard>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('pa.common.employee') }}</th>
            <th>{{ t('pa.kpis.col_kpi') }}</th>
            <th>{{ t('pa.kpis.col_target') }}</th>
            <th>{{ t('pa.kpis.col_progress') }}</th>
            <th>{{ t('pa.kpis.col_score') }}</th>
            <th>{{ t('pa.kpis.col_weight') }}</th>
            <th>{{ t('pa.common.status') }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="props.kpis.length === 0">
            <td colspan="8" class="text-center py-8 text-medium-emphasis">{{ t('pa.kpis.empty') }}</td>
          </tr>
          <tr v-for="kpi in props.kpis" :key="kpi.id">
            <td class="font-weight-medium">{{ kpi.employee_name }}</td>
            <td>
              <div class="font-weight-medium text-truncate" style="max-width:200px">{{ kpi.title }}</div>
              <div v-if="kpi.goal_title" class="text-caption text-medium-emphasis">→ {{ kpi.goal_title }}</div>
            </td>
            <td class="text-caption">{{ kpi.target_value !== null ? `${kpi.target_value} ${kpi.unit ?? ''}` : '—' }}</td>
            <td>
              <div v-if="kpi.latest_value !== null" class="d-flex align-center gap-1">
                <span class="text-caption">{{ kpi.latest_value }}</span>
                <VIcon icon="ri-history-line" size="12" color="medium-emphasis" />
                <span class="text-caption text-medium-emphasis">{{ kpi.progress_logs_count }}</span>
              </div>
              <span v-else class="text-caption text-medium-emphasis">{{ t('pa.kpis.no_entries') }}</span>
            </td>
            <td>
              <VChip v-if="kpi.score_pct !== null" size="small"
                :color="kpi.score_pct >= 90 ? 'success' : kpi.score_pct >= 60 ? 'primary' : 'warning'"
                label>{{ kpi.score_pct }}%</VChip>
              <span v-else class="text-caption text-medium-emphasis">—</span>
            </td>
            <td class="text-caption">{{ kpi.weighting }}%</td>
            <td><VChip size="small" :color="statusColor(kpi.status)" label>{{ t('pa.kpis.statuses.' + kpi.status) }}</VChip></td>
            <td>
              <div class="d-flex gap-1 justify-end">
                <VBtn size="small" variant="text" icon="ri-eye-line" title="View progress & evidence" :href="route('performance.kpis.progress.history', kpi.id)" />
                <VBtn size="small" variant="text" icon="ri-line-chart-line" title="Log progress" @click="openProgress(kpi)" />
                <VBtn size="small" variant="text" icon="ri-medal-line" title="Calculate score" @click="scoreKpi(kpi)" />
                <VBtn size="small" variant="text" icon="ri-edit-line" @click="openDrawer(kpi)" />
                <VBtn size="small" variant="text" icon="ri-delete-bin-line" color="error" @click="delItem = kpi; delOpen = true" />
              </div>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <!-- Create / Edit Drawer -->
    <VNavigationDrawer v-model="drawer" location="right" temporary width="500">
      <div class="pa-4 d-flex align-center border-b">
        <span class="text-h6">{{ editing?.id ? t('pa.kpis.edit') : t('pa.kpis.assign') }}</span>
        <VSpacer />
        <VBtn icon="ri-close-line" variant="text" size="small" @click="drawer = false" />
      </div>
      <div class="pa-4" v-if="editing">
        <VSelect v-model="editing.cycle_id" :items="props.cycles" item-title="name" item-value="id" :label="t('pa.kpis.appraisal_cycle')" class="mb-3" />
        <VSelect v-model="editing.employee_id" :items="props.employees" item-title="name" item-value="id" :label="t('pa.common.employee')" class="mb-3" />
        <VSelect
          v-model="editing.goal_id"
          :items="availableGoals"
          item-title="title"
          item-value="id"
          item-disabled="disabled"
          :label="t('pa.kpis.linked_goal_optional')"
          clearable class="mb-3"
          :hint="editing.goal_id && takenGoalIds.has(editing.goal_id) ? t('pa.kpis.goal_already_assigned') : ''"
          :persistent-hint="editing.goal_id && takenGoalIds.has(editing.goal_id)"
          :rules="[(v: number|null) => !v || !takenGoalIds.has(v) || t('pa.kpis.goal_already_assigned')]"
        />

        <VDivider class="my-3" />

        <VTextField v-model="editing.title" :label="t('pa.kpis.kpi_title')" class="mb-3" />
        <VTextField v-model="editing.description" :label="t('pa.cycles.description')" class="mb-3" />
        <VRow>
          <VCol cols="7"><VTextField v-model.number="editing.target_value" :label="t('pa.goals.target_value')" type="number" /></VCol>
          <VCol cols="5"><VTextField v-model="editing.unit" :label="t('pa.goals.unit_label')" /></VCol>
        </VRow>
        <VTextField
          v-model.number="editing.weighting"
          :label="t('pa.kpis.weight_pct')"
          type="number" min="0" max="100" class="mb-3"
          :hint="`${t('pa.common.remaining')}: ${remainingWeighting}%`"
          persistent-hint
          :rules="[(v: number) => (v ?? 0) <= remainingWeighting || `Cannot exceed remaining ${remainingWeighting}%`]"
        />
        <VSelect v-model="editing.status" :items="statuses" :label="t('pa.common.status')" class="mb-3" />

        <VDivider class="my-3" />

        <VSwitch v-model="editing.self_update_allowed" :label="t('pa.kpis.self_update')" inset color="primary" class="mb-1" />
        <VSwitch v-model="editing.manager_update_allowed" :label="t('pa.kpis.manager_update')" inset color="primary" class="mb-4" />

        <div class="d-flex justify-end gap-2">
          <VBtn variant="text" @click="drawer = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="primary" @click="save">{{ editing?.id ? t('pa.common.update') : t('pa.kpis.assign') }}</VBtn>
        </div>
      </div>
    </VNavigationDrawer>

    <!-- Progress log dialog -->
    <VDialog v-model="progressOpen" max-width="440">
      <VCard v-if="progressKpi">
        <VCardTitle>{{ t('pa.kpis.log_progress') }} — {{ progressKpi.title }}</VCardTitle>
        <VCardText>
          <VTextField v-model.number="progressValue" :label="t('pa.goals.current_value')" type="number" class="mb-3" />
          <VTextField v-model="progressDate" :label="t('pa.cycles.start_date')" type="date" class="mb-3" />
          <VTextField v-model="progressNotes" :label="t('pa.kpis.notes_optional')" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="progressOpen = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="primary" @click="saveProgress">{{ t('pa.kpis.log') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Delete confirm -->
    <VDialog v-model="delOpen" max-width="400">
      <VCard v-if="delItem">
        <VCardTitle>{{ t('pa.kpis.delete_title') }}</VCardTitle>
        <VCardText>{{ t('pa.cycles.delete_confirm') }} <strong>{{ delItem.title }}</strong>?</VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="delOpen = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="error" @click="router.delete(route('performance.kpis.destroy', delItem.id), { onSuccess: () => { delOpen = false; delItem = null } })">{{ t('pa.common.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Score All KPIs dialog -->
    <VDialog v-model="scoreAllOpen" max-width="480">
      <VCard>
        <VCardTitle class="d-flex align-center pa-4 pb-2">
          <VIcon icon="ri-medal-line" class="mr-2" color="primary" />
          Score All KPIs for Employee
        </VCardTitle>
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-4">
            Automatically calculates scores for all KPIs belonging to the selected employee in the chosen cycle, based on their latest logged progress values.
          </p>
          <VSelect
            v-model="scoreAllEmployeeId"
            :items="props.employees"
            item-title="name"
            item-value="id"
            label="Employee"
            class="mb-3"
          />
          <VSelect
            v-model="scoreAllCycleId"
            :items="props.cycles"
            item-title="name"
            item-value="id"
            label="Appraisal Cycle"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="scoreAllOpen = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="primary" :disabled="!scoreAllEmployeeId || !scoreAllCycleId" @click="submitScoreAll">
            Calculate Scores
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
