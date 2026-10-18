<script setup lang="ts">
import { ref, reactive, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'

const { t } = useI18n()

// ── Types ─────────────────────────────────────────────────────────────────────
interface Candidate {
  id: number; employee_id: number; employee_name: string
  readiness_level: string; potential_rating: number
  risk_of_loss: string; retention_priority: string
  ai_readiness_score: number | null; ai_recommendation: string | null
  notes: string | null
}

interface Plan {
  id: number; position_title: string; department: string | null
  incumbent_name: string; cycle_name: string | null
  is_critical_role: boolean; status: string
  bench_strength: number | null; candidates_count: number; ready_now_count: number
  ai_analysis: string | null; notes: string | null
  candidates: Candidate[]
}

interface HiPo {
  id: number; employee_name: string; cycle_name: string | null; composite_score: number
  potential_band: string; factors: any; is_ai_generated: boolean
}

interface Employee { id: number; name: string }
interface Cycle    { id: number; name: string }

const props = defineProps<{ plans: Plan[]; highPotentials: HiPo[]; cycles: Cycle[]; employees: Employee[] }>()

const tab = ref('plans')

// ── Manage dialog ─────────────────────────────────────────────────────────────
const manageOpen   = ref(false)
const activePlan   = ref<Plan | null>(null)
const generatingAnalysis = ref(false)
const manageTab    = ref('candidates')

function openManage(p: Plan) {
  activePlan.value = { ...p, candidates: [...p.candidates] }
  manageTab.value  = 'candidates'
  manageOpen.value = true
}

// Sync activePlan from props when Inertia refreshes after a mutation
watch(() => props.plans, (updated) => {
  if (!activePlan.value) return
  const fresh = updated.find(p => p.id === activePlan.value!.id)
  if (fresh) activePlan.value = { ...fresh, candidates: [...fresh.candidates] }
}, { deep: true })

function generateAnalysis() {
  if (!activePlan.value) return
  generatingAnalysis.value = true
  router.post(route('performance.succession.analysis', activePlan.value.id), {}, {
    preserveScroll: true,
    onFinish: () => { generatingAnalysis.value = false },
  })
}

// ── Add candidate ─────────────────────────────────────────────────────────────
const candFormRef    = ref()
const addingCandidate = ref(false)
const showAddForm     = ref(false)

const cForm = reactive({
  employee_id:        null as number | null,
  readiness_level:    '' as string,
  risk_of_loss:       'medium' as string,
  retention_priority: 'normal' as string,
  potential_rating:   3 as number,
  notes:              '' as string,
})

async function submitCandidate() {
  const { valid } = await candFormRef.value.validate()
  if (!valid || !activePlan.value) return
  addingCandidate.value = true
  router.post(route('performance.succession.candidates.store', activePlan.value.id), { ...cForm }, {
    preserveScroll: true,
    onSuccess: () => {
      Object.assign(cForm, { employee_id: null, readiness_level: '', risk_of_loss: 'medium', retention_priority: 'normal', potential_rating: 3, notes: '' })
      showAddForm.value = false
      candFormRef.value?.resetValidation()
    },
    onFinish: () => { addingCandidate.value = false },
  })
}

// ── Edit candidate ────────────────────────────────────────────────────────────
const editingCandidate  = ref<Candidate | null>(null)
const savingEdit        = ref(false)
const editForm          = reactive({
  readiness_level: '', potential_rating: 3, risk_of_loss: 'medium',
  retention_priority: 'normal', notes: '',
})

function openEdit(c: Candidate) {
  editingCandidate.value = c
  Object.assign(editForm, {
    readiness_level:    c.readiness_level,
    potential_rating:   c.potential_rating,
    risk_of_loss:       c.risk_of_loss,
    retention_priority: c.retention_priority,
    notes:              c.notes ?? '',
  })
}

function saveEdit() {
  if (!activePlan.value || !editingCandidate.value) return
  savingEdit.value = true
  router.patch(
    route('performance.succession.candidates.update', { plan: activePlan.value.id, candidate: editingCandidate.value.id }),
    { ...editForm },
    {
      preserveScroll: true,
      onSuccess: () => { editingCandidate.value = null },
      onFinish:  () => { savingEdit.value = false },
    }
  )
}

// ── Score / Remove ────────────────────────────────────────────────────────────
const scoringId   = ref<number | null>(null)
const removingId  = ref<number | null>(null)

function scoreCandidate(c: Candidate) {
  if (!activePlan.value) return
  scoringId.value = c.id
  router.post(
    route('performance.succession.candidates.score', { plan: activePlan.value.id, candidate: c.id }),
    {},
    { preserveScroll: true, onFinish: () => { scoringId.value = null } }
  )
}

function removeCandidate(id: number) {
  if (!activePlan.value) return
  removingId.value = id
  router.delete(
    route('performance.succession.candidates.destroy', { plan: activePlan.value.id, candidate: id }),
    { preserveScroll: true, onFinish: () => { removingId.value = null } }
  )
}

// ── Create plan ───────────────────────────────────────────────────────────────
const createOpen = ref(false)
const saving     = ref(false)
const createForm = ref()
const pForm = reactive({
  position_title: '', department: '',
  incumbent_id: null as number | null, cycle_id: null as number | null,
  is_critical_role: false, notes: '',
})

async function submitCreate() {
  const { valid } = await createForm.value.validate()
  if (!valid) return
  saving.value = true
  router.post(route('performance.succession.store'), { ...pForm }, {
    onSuccess: () => { createOpen.value = false; Object.assign(pForm, { position_title: '', department: '', incumbent_id: null, cycle_id: null, is_critical_role: false, notes: '' }) },
    onFinish:  () => { saving.value = false },
  })
}

// ── Delete plan ───────────────────────────────────────────────────────────────
const delPlanOpen  = ref(false)
const delPlanItem  = ref<Plan | null>(null)
const deletingPlan = ref(false)

function openDeletePlan(p: Plan) { delPlanItem.value = p; delPlanOpen.value = true }
function confirmDeletePlan() {
  if (!delPlanItem.value) return
  deletingPlan.value = true
  router.delete(route('performance.succession.destroy', delPlanItem.value.id), {
    onSuccess: () => { delPlanOpen.value = false; manageOpen.value = false },
    onFinish:  () => { deletingPlan.value = false },
  })
}

// ── High-potentials ───────────────────────────────────────────────────────────
const identifyHiPoOpen = ref(false)
const identifying      = ref(false)
const hiPoForm         = reactive({ cycle_id: null as number | null })

function runIdentify() {
  identifying.value = true
  router.post(route('performance.succession.high-potentials'), { ...hiPoForm }, {
    onSuccess: () => { identifyHiPoOpen.value = false },
    onFinish:  () => { identifying.value = false },
  })
}

// ── Helpers ───────────────────────────────────────────────────────────────────
const readinessLevels = [
  { value: 'ready_now', label: 'Ready Now' },
  { value: '1_year',    label: 'Ready in 1 Year' },
  { value: '2_years',   label: 'Ready in 2 Years' },
  { value: 'not_ready', label: 'Not Ready' },
]
const retentionLevels = [
  { value: 'normal',   label: 'Normal' },
  { value: 'high',     label: 'High Priority' },
  { value: 'critical', label: 'Critical' },
]

function readinessLabel(v: string) { return readinessLevels.find(r => r.value === v)?.label ?? v }

// Employees not yet added as candidates for the active plan
const availableEmployees = computed(() => {
  if (!activePlan.value) return props.employees
  const taken = new Set(activePlan.value.candidates.map(c => c.employee_id))
  return props.employees.filter(e => !taken.has(e.id))
})

function benchColor(score: number | null) {
  if (score === null) return 'grey'
  if (score >= 60) return 'success'
  if (score >= 30) return 'warning'
  return 'error'
}
function planStatusColor(s: string) { return { active: 'primary', review: 'warning', closed: 'default' }[s] ?? 'default' }
function readinessColor(v: string)  { return { ready_now: 'success', '1_year': 'primary', '2_years': 'warning', not_ready: 'error' }[v] ?? 'default' }
function riskColor(v: string)       { return { low: 'success', medium: 'warning', high: 'error' }[v] ?? 'default' }
function retentionColor(v: string)  { return { normal: 'default', high: 'warning', critical: 'error' }[v] ?? 'default' }
function bandColor(band: string)    { return { exceptional: 'deep-purple', high: 'blue', emerging: 'teal' }[band] ?? 'grey' }
function starColor(rating: number, star: number) { return star <= rating ? 'warning' : 'grey-lighten-2' }
</script>

<template>
  <VContainer fluid class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('pa.succession.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">{{ t('pa.succession.subtitle') }}</p>
      </div>
      <div class="d-flex ga-2">
        <VBtn variant="tonal" prepend-icon="tabler-shooting-star" @click="identifyHiPoOpen = true">
          {{ t('pa.succession.identify_hipo') }}
        </VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="createOpen = true">
          {{ t('pa.succession.new') }}
        </VBtn>
      </div>
    </div>

    <VTabs v-model="tab" class="mb-5">
      <VTab value="plans">
        <VIcon start>tabler-sitemap</VIcon>{{ t('pa.succession.tab_plans') }} ({{ plans.length }})
      </VTab>
      <VTab value="hipo">
        <VIcon start>tabler-star</VIcon>{{ t('pa.succession.tab_hipo') }} ({{ highPotentials.length }})
      </VTab>
    </VTabs>

    <VTabsWindow v-model="tab">

      <!-- ── Succession plans ── -->
      <VTabsWindowItem value="plans">
        <VRow v-if="plans.length" dense>
          <VCol v-for="p in plans" :key="p.id" cols="12" sm="6" xl="4">
            <VCard border flat>
              <VCardItem>
                <template #prepend>
                  <VAvatar :color="benchColor(p.bench_strength)" variant="tonal" size="44">
                    <span class="text-body-2 font-weight-bold">{{ p.bench_strength ?? '?' }}</span>
                  </VAvatar>
                </template>
                <VCardTitle class="text-body-2 font-weight-semibold">{{ p.position_title }}</VCardTitle>
                <VCardSubtitle>
                  {{ p.department ?? 'No department' }}
                  <span v-if="p.incumbent_name && p.incumbent_name !== '-'"> · {{ p.incumbent_name }}</span>
                </VCardSubtitle>
                <template #append>
                  <div class="d-flex flex-column align-end ga-1">
                    <VChip v-if="p.is_critical_role" size="x-small" color="error" variant="flat">Critical Role</VChip>
                    <VChip size="x-small" :color="planStatusColor(p.status)" variant="tonal">{{ p.status }}</VChip>
                  </div>
                </template>
              </VCardItem>

              <VCardText class="pt-0 pb-2">
                <VProgressLinear
                  :model-value="p.bench_strength ?? 0"
                  :color="benchColor(p.bench_strength)"
                  height="6" rounded class="mb-2"
                />
                <div class="d-flex justify-space-between text-caption text-medium-emphasis">
                  <span><VIcon size="12">tabler-users</VIcon> {{ p.candidates_count }} candidates</span>
                  <span><VIcon size="12" color="success">tabler-circle-check</VIcon> {{ p.ready_now_count }} ready now</span>
                  <span>Bench: {{ p.bench_strength ?? 'N/A' }}/100</span>
                </div>
              </VCardText>

              <VCardActions class="px-4 pb-3 pt-1">
                <VSpacer />
                <VBtn size="x-small" variant="tonal" color="primary" prepend-icon="tabler-user-edit" @click="openManage(p)">
                  {{ t('pa.succession.manage') }}
                </VBtn>
                <VBtn size="x-small" icon variant="text" color="error" class="ml-1" @click="openDeletePlan(p)">
                  <VIcon>tabler-trash</VIcon>
                </VBtn>
              </VCardActions>
            </VCard>
          </VCol>
        </VRow>
        <VAlert v-else type="info" variant="tonal">{{ t('pa.succession.empty') }}</VAlert>
      </VTabsWindowItem>

      <!-- ── High potentials ── -->
      <VTabsWindowItem value="hipo">
        <VRow v-if="highPotentials.length" dense>
          <VCol v-for="hp in highPotentials" :key="hp.id" cols="12" sm="6" xl="4">
            <VCard border flat>
              <VCardItem>
                <template #prepend>
                  <VAvatar :color="bandColor(hp.potential_band)" variant="flat" size="40">
                    <VIcon size="18" color="white">tabler-star</VIcon>
                  </VAvatar>
                </template>
                <VCardTitle class="text-body-2 font-weight-medium">{{ hp.employee_name }}</VCardTitle>
                <VCardSubtitle>Score: {{ hp.composite_score }}/100</VCardSubtitle>
                <template #append>
                  <VChip size="x-small" :color="bandColor(hp.potential_band)" variant="flat" class="text-white">
                    {{ hp.potential_band }}
                  </VChip>
                </template>
              </VCardItem>
              <VCardText class="pt-0">
                <VProgressLinear :model-value="hp.composite_score" :color="bandColor(hp.potential_band)" height="6" rounded class="mb-2" />
                <div v-if="hp.factors" class="d-flex justify-space-between text-caption text-medium-emphasis">
                  <span v-if="hp.factors.kpi_avg">KPI {{ hp.factors.kpi_avg }}%</span>
                  <span v-if="hp.factors.skills_avg">Skills {{ hp.factors.skills_avg }}/5</span>
                  <span v-if="hp.factors.feedback_avg">FB {{ hp.factors.feedback_avg }}/5</span>
                </div>
                <VChip v-if="hp.is_ai_generated" size="x-small" color="secondary" variant="tonal" class="mt-2">AI</VChip>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>
        <VAlert v-else type="info" variant="tonal">{{ t('pa.succession.empty_hipo') }}</VAlert>
      </VTabsWindowItem>

    </VTabsWindow>

    <!-- ═══════════════════════════════════════════════════════════════════════
         Manage Plan dialog
    ════════════════════════════════════════════════════════════════════════ -->
    <VDialog v-model="manageOpen" max-width="900">
      <VCard v-if="activePlan" style="max-height:90vh; display:flex; flex-direction:column;">

        <!-- Toolbar -->
        <VToolbar color="primary" density="compact" flat style="flex-shrink:0">
          <VToolbarTitle class="text-body-1 font-weight-semibold">
            {{ activePlan.position_title }}
            <VChip v-if="activePlan.is_critical_role" size="x-small" color="error" variant="flat" class="ml-2">Critical Role</VChip>
          </VToolbarTitle>
          <template #append>
            <VBtn
              size="small" variant="tonal" prepend-icon="tabler-robot"
              :loading="generatingAnalysis" class="mr-2"
              @click="generateAnalysis"
            >AI Analysis</VBtn>
            <VBtn icon @click="manageOpen = false"><VIcon>tabler-x</VIcon></VBtn>
          </template>
        </VToolbar>

        <!-- Plan summary strip -->
        <div class="d-flex ga-4 px-4 py-3 bg-surface-variant" style="flex-shrink:0">
          <div class="text-center">
            <p class="text-h6 font-weight-bold" :class="`text-${benchColor(activePlan.bench_strength)}`">
              {{ activePlan.bench_strength ?? '—' }}
            </p>
            <p class="text-caption text-medium-emphasis">Bench Strength</p>
          </div>
          <VDivider vertical />
          <div class="text-center">
            <p class="text-h6 font-weight-bold">{{ activePlan.candidates.length }}</p>
            <p class="text-caption text-medium-emphasis">Candidates</p>
          </div>
          <VDivider vertical />
          <div class="text-center">
            <p class="text-h6 font-weight-bold text-success">
              {{ activePlan.candidates.filter(c => c.readiness_level === 'ready_now').length }}
            </p>
            <p class="text-caption text-medium-emphasis">Ready Now</p>
          </div>
          <VDivider vertical />
          <div class="text-center">
            <p class="text-h6 font-weight-bold" :class="activePlan.candidates.filter(c => c.risk_of_loss === 'high').length > 0 ? 'text-error' : ''">
              {{ activePlan.candidates.filter(c => c.risk_of_loss === 'high').length }}
            </p>
            <p class="text-caption text-medium-emphasis">High Risk of Loss</p>
          </div>
        </div>

        <VTabs v-model="manageTab" density="compact" class="px-2" style="flex-shrink:0; border-bottom:1px solid rgba(0,0,0,0.12)">
          <VTab value="candidates" prepend-icon="tabler-users">
            Candidates <VBadge :content="activePlan.candidates.length" color="primary" inline class="ml-1" />
          </VTab>
          <VTab value="analysis" prepend-icon="tabler-robot">
            AI Analysis
            <VChip v-if="activePlan.ai_analysis" size="x-small" color="secondary" class="ml-1">Ready</VChip>
          </VTab>
        </VTabs>

        <div style="flex:1 1 auto; overflow-y:auto; padding:16px">
          <VTabsWindow v-model="manageTab">

            <!-- ── Candidates tab ── -->
            <VTabsWindowItem value="candidates">

              <!-- Add candidate toggle -->
              <div class="d-flex justify-end mb-3">
                <VBtn
                  size="small" :color="showAddForm ? 'default' : 'primary'"
                  :variant="showAddForm ? 'tonal' : 'flat'"
                  :prepend-icon="showAddForm ? 'tabler-x' : 'tabler-plus'"
                  @click="showAddForm = !showAddForm"
                >
                  {{ showAddForm ? 'Cancel' : 'Add Candidate' }}
                </VBtn>
              </div>

              <!-- Add candidate form -->
              <VExpandTransition>
                <VCard v-if="showAddForm" border flat class="mb-4 pa-4">
                  <p class="text-body-2 font-weight-semibold mb-3">New Candidate</p>
                  <VForm ref="candFormRef" @submit.prevent="submitCandidate">
                    <VRow dense>
                      <VCol cols="12" sm="6">
                        <VSelect
                          v-model="cForm.employee_id"
                          :items="availableEmployees"
                          item-title="name" item-value="id"
                          label="Employee" density="compact" variant="outlined"
                          :rules="[v => !!v || 'Required']"
                        />
                      </VCol>
                      <VCol cols="12" sm="6">
                        <VSelect
                          v-model="cForm.readiness_level"
                          :items="readinessLevels" item-title="label" item-value="value"
                          label="Readiness Level" density="compact" variant="outlined"
                          :rules="[v => !!v || 'Required']"
                        />
                      </VCol>
                      <VCol cols="12" sm="4">
                        <VSelect
                          v-model="cForm.risk_of_loss"
                          :items="['low','medium','high']"
                          label="Risk of Loss" density="compact" variant="outlined"
                        />
                      </VCol>
                      <VCol cols="12" sm="4">
                        <VSelect
                          v-model="cForm.retention_priority"
                          :items="retentionLevels" item-title="label" item-value="value"
                          label="Retention Priority" density="compact" variant="outlined"
                        />
                      </VCol>
                      <VCol cols="12" sm="4">
                        <VTextField
                          v-model.number="cForm.potential_rating"
                          label="Potential (1–5)" type="number" min="1" max="5"
                          density="compact" variant="outlined"
                        />
                      </VCol>
                      <VCol cols="12">
                        <VTextarea
                          v-model="cForm.notes"
                          label="Notes (optional)" density="compact" variant="outlined" rows="2"
                        />
                      </VCol>
                      <VCol cols="12" class="d-flex justify-end">
                        <VBtn type="submit" color="primary" :loading="addingCandidate">Add Candidate</VBtn>
                      </VCol>
                    </VRow>
                  </VForm>
                </VCard>
              </VExpandTransition>

              <!-- Empty state -->
              <VAlert
                v-if="!activePlan.candidates.length"
                type="info" variant="tonal" density="compact"
              >
                No candidates yet. Click "Add Candidate" to get started.
              </VAlert>

              <!-- Candidate table -->
              <VTable v-else density="comfortable">
                <thead>
                  <tr>
                    <th>Employee</th>
                    <th>Readiness</th>
                    <th>Potential</th>
                    <th>Risk of Loss</th>
                    <th>Retention</th>
                    <th>AI Score</th>
                    <th class="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <template v-for="c in activePlan.candidates" :key="c.id">
                    <!-- View row -->
                    <tr v-if="editingCandidate?.id !== c.id">
                      <td>
                        <div class="d-flex align-center gap-2">
                          <VAvatar size="28" color="primary" variant="tonal">
                            <span class="text-caption">{{ c.employee_name.charAt(0) }}</span>
                          </VAvatar>
                          <div>
                            <div class="text-body-2 font-weight-medium">{{ c.employee_name }}</div>
                            <div v-if="c.notes" class="text-caption text-medium-emphasis">{{ c.notes }}</div>
                          </div>
                        </div>
                      </td>
                      <td>
                        <VChip size="x-small" :color="readinessColor(c.readiness_level)" variant="tonal" label>
                          {{ readinessLabel(c.readiness_level) }}
                        </VChip>
                      </td>
                      <td>
                        <div class="d-flex">
                          <VIcon
                            v-for="star in 5" :key="star"
                            size="14"
                            :color="starColor(c.potential_rating, star)"
                          >tabler-star</VIcon>
                        </div>
                      </td>
                      <td>
                        <VChip size="x-small" :color="riskColor(c.risk_of_loss)" variant="tonal" label>{{ c.risk_of_loss }}</VChip>
                      </td>
                      <td>
                        <VChip size="x-small" :color="retentionColor(c.retention_priority)" variant="tonal" label>{{ c.retention_priority }}</VChip>
                      </td>
                      <td>
                        <span v-if="c.ai_readiness_score !== null" class="text-body-2 font-weight-medium">
                          {{ c.ai_readiness_score }}
                        </span>
                        <span v-else class="text-caption text-medium-emphasis">—</span>
                      </td>
                      <td class="text-end">
                        <div class="d-flex justify-end ga-1">
                          <VBtn size="x-small" icon variant="text" @click="openEdit(c)">
                            <VIcon size="16">tabler-pencil</VIcon>
                            <VTooltip activator="parent">Edit</VTooltip>
                          </VBtn>
                          <VBtn
                            size="x-small" icon variant="text" color="secondary"
                            :loading="scoringId === c.id"
                            @click="scoreCandidate(c)"
                          >
                            <VIcon size="16">tabler-robot</VIcon>
                            <VTooltip activator="parent">AI Score</VTooltip>
                          </VBtn>
                          <VBtn
                            size="x-small" icon variant="text" color="error"
                            :loading="removingId === c.id"
                            @click="removeCandidate(c.id)"
                          >
                            <VIcon size="16">tabler-trash</VIcon>
                            <VTooltip activator="parent">Remove</VTooltip>
                          </VBtn>
                        </div>
                      </td>
                    </tr>

                    <!-- Inline edit row -->
                    <tr v-else class="bg-surface-variant">
                      <td colspan="7" class="pa-3">
                        <p class="text-caption font-weight-semibold mb-2">Editing: {{ c.employee_name }}</p>
                        <VRow dense>
                          <VCol cols="6" sm="3">
                            <VSelect v-model="editForm.readiness_level" :items="readinessLevels" item-title="label" item-value="value" label="Readiness" density="compact" variant="outlined" />
                          </VCol>
                          <VCol cols="6" sm="2">
                            <VTextField v-model.number="editForm.potential_rating" label="Potential" type="number" min="1" max="5" density="compact" variant="outlined" />
                          </VCol>
                          <VCol cols="6" sm="2">
                            <VSelect v-model="editForm.risk_of_loss" :items="['low','medium','high']" label="Risk" density="compact" variant="outlined" />
                          </VCol>
                          <VCol cols="6" sm="3">
                            <VSelect v-model="editForm.retention_priority" :items="retentionLevels" item-title="label" item-value="value" label="Retention" density="compact" variant="outlined" />
                          </VCol>
                          <VCol cols="12">
                            <VTextarea v-model="editForm.notes" label="Notes" density="compact" variant="outlined" rows="2" />
                          </VCol>
                          <VCol cols="12" class="d-flex gap-2 justify-end">
                            <VBtn size="small" variant="text" @click="editingCandidate = null">Cancel</VBtn>
                            <VBtn size="small" color="primary" :loading="savingEdit" @click="saveEdit">Save</VBtn>
                          </VCol>
                        </VRow>
                      </td>
                    </tr>

                    <!-- AI recommendation row -->
                    <tr v-if="c.ai_recommendation && editingCandidate?.id !== c.id">
                      <td colspan="7" class="py-1 px-4">
                        <div class="d-flex align-start gap-2 pa-2 rounded bg-secondary-lighten-5">
                          <VIcon size="14" color="secondary" class="mt-0.5">tabler-robot</VIcon>
                          <p class="text-caption text-medium-emphasis" style="white-space:pre-wrap">{{ c.ai_recommendation }}</p>
                        </div>
                      </td>
                    </tr>
                  </template>
                </tbody>
              </VTable>

            </VTabsWindowItem>

            <!-- ── AI Analysis tab ── -->
            <VTabsWindowItem value="analysis">
              <div v-if="activePlan.ai_analysis">
                <VCard border flat class="pa-4">
                  <p class="text-body-2 font-weight-semibold mb-2">
                    <VIcon size="16" color="secondary" class="mr-1">tabler-robot</VIcon>
                    Succession Analysis
                  </p>
                  <p class="text-body-2" style="white-space: pre-wrap">{{ activePlan.ai_analysis }}</p>
                </VCard>
              </div>
              <div v-else class="text-center py-10">
                <VIcon size="48" color="medium-emphasis">tabler-robot</VIcon>
                <p class="text-body-2 text-medium-emphasis mt-3 mb-4">No AI analysis generated yet.</p>
                <VBtn color="secondary" prepend-icon="tabler-robot" :loading="generatingAnalysis" @click="generateAnalysis">
                  Generate AI Analysis
                </VBtn>
              </div>
            </VTabsWindowItem>

          </VTabsWindow>
        </div>
      </VCard>
    </VDialog>

    <!-- Create plan drawer -->
    <VNavigationDrawer v-model="createOpen" location="right" width="440" temporary>
      <VToolbar color="primary" density="compact">
        <VToolbarTitle class="text-body-1">{{ t('pa.succession.new') }}</VToolbarTitle>
        <template #append><VBtn icon @click="createOpen = false"><VIcon>tabler-x</VIcon></VBtn></template>
      </VToolbar>
      <VForm ref="createForm" @submit.prevent="submitCreate" class="pa-4">
        <VTextField v-model="pForm.position_title" label="Position Title" density="compact" variant="outlined" :rules="[v => !!v || 'Required']" class="mb-3" />
        <VTextField v-model="pForm.department" label="Department" density="compact" variant="outlined" class="mb-3" />
        <VSelect v-model="pForm.incumbent_id" :items="employees" item-title="name" item-value="id" label="Current Incumbent" density="compact" variant="outlined" clearable class="mb-3" />
        <VSelect v-model="pForm.cycle_id" :items="cycles" item-title="name" item-value="id" label="Linked Cycle (optional)" density="compact" variant="outlined" clearable class="mb-3" />
        <VSwitch v-model="pForm.is_critical_role" label="Critical Role" color="error" density="compact" class="mb-3" />
        <VTextarea v-model="pForm.notes" label="Notes" density="compact" variant="outlined" rows="3" class="mb-4" />
        <VBtn type="submit" color="primary" block :loading="saving">{{ t('pa.succession.create_plan') }}</VBtn>
      </VForm>
    </VNavigationDrawer>

    <!-- Identify HiPo dialog -->
    <VDialog v-model="identifyHiPoOpen" max-width="380">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold pa-4">{{ t('pa.succession.identify_hipo') }}</VCardTitle>
        <VCardText>
          <VSelect v-model="hiPoForm.cycle_id" :items="cycles" item-title="name" item-value="id" label="Appraisal Cycle" density="compact" variant="outlined" :rules="[v => !!v || 'Required']" />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="identifyHiPoOpen = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="identifying" @click="runIdentify">{{ t('pa.succession.run_analysis') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Delete plan -->
    <VDialog v-model="delPlanOpen" max-width="400">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold pa-4">{{ t('pa.succession.delete_title') }}</VCardTitle>
        <VCardText>{{ t('pa.succession.delete_confirm') }}</VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="delPlanOpen = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="error" variant="flat" :loading="deletingPlan" @click="confirmDeletePlan">{{ t('pa.common.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

  </VContainer>
</template>
