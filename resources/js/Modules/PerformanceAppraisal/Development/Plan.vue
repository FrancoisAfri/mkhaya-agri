<template>
  <VContainer fluid class="pa-6">
    <!-- Header -->
    <div class="d-flex align-center mb-2">
      <VBtn icon variant="text" class="mr-2" @click="router.visit(route('performance.development.index'))">
        <VIcon>tabler-arrow-left</VIcon>
      </VBtn>
      <div class="flex-grow-1">
        <h1 class="text-h5 font-weight-bold">{{ plan.title }}</h1>
        <p class="text-body-2 text-medium-emphasis">
          {{ plan.employee_name }} · {{ t('pa.development.manager') }}: {{ plan.manager_name }}
          <span v-if="plan.cycle_name"> · {{ plan.cycle_name }}</span>
        </p>
      </div>
      <div class="d-flex ga-2 align-center">
        <VChip :color="statusColor(plan.status)" variant="tonal">{{ plan.status }}</VChip>
        <VBtn
          color="secondary"
          variant="tonal"
          prepend-icon="tabler-robot"
          :loading="generatingAi"
          @click="generateAi"
        >
          {{ t('pa.development.ai_recommendations') }}
        </VBtn>
      </div>
    </div>

    <VDivider class="mb-5" />

    <!-- Progress -->
    <VCard border flat class="mb-5 pa-4">
      <div class="d-flex align-center justify-space-between mb-2">
        <span class="text-body-2 font-weight-medium">{{ t('pa.development.overall_progress') }}</span>
        <span class="text-h6 font-weight-bold">{{ plan.overall_progress }}%</span>
      </div>
      <VProgressLinear :model-value="plan.overall_progress" color="primary" height="10" rounded />
      <div class="d-flex ga-4 mt-3 text-caption text-medium-emphasis">
        <span v-if="plan.career_goal"><VIcon size="12">tabler-flag</VIcon> {{ plan.career_goal }}</span>
        <span v-if="plan.target_completion_date"><VIcon size="12">tabler-calendar</VIcon> {{ t('pa.development.due') }} {{ plan.target_completion_date }}</span>
      </div>
    </VCard>

    <VTabs v-model="tab" class="mb-4">
      <VTab value="activities"><VIcon start>tabler-checks</VIcon>{{ t('pa.development.tab_activities') }} ({{ activities.length }})</VTab>
      <VTab value="skills"><VIcon start>tabler-star</VIcon>{{ t('pa.development.tab_skills') }}</VTab>
      <VTab value="ai"><VIcon start>tabler-robot</VIcon>{{ t('pa.development.ai_recommendations') }}</VTab>
    </VTabs>

    <VTabsWindow v-model="tab">

      <!-- Activities tab -->
      <VTabsWindowItem value="activities">
        <div class="d-flex justify-end mb-3">
          <VBtn color="primary" size="small" prepend-icon="tabler-plus" @click="openAddActivity">{{ t('pa.development.add_activity') }}</VBtn>
        </div>

        <VList v-if="activities.length" class="pa-0">
          <VListItem
            v-for="act in activities"
            :key="act.id"
            class="mb-2 border"
          >
            <template #prepend>
              <VAvatar :color="activityTypeColor(act.activity_type)" variant="tonal" size="36">
                <VIcon size="16">{{ activityTypeIcon(act.activity_type) }}</VIcon>
              </VAvatar>
            </template>
            <template #title>
              <span class="text-body-2 font-weight-medium">{{ act.title }}</span>
            </template>
            <template #subtitle>
              <span class="text-caption">
                {{ act.activity_type }}
                <span v-if="act.provider"> · {{ act.provider }}</span>
                <span v-if="act.target_date"> · {{ t('pa.development.due') }} {{ act.target_date }}</span>
                <span v-if="act.cost !== null"> · R{{ act.cost }}</span>
              </span>
            </template>
            <template #append>
              <div class="d-flex align-center ga-1">
                <VChip v-if="act.lms_course_id" size="x-small" color="success" variant="tonal" prepend-icon="tabler-school">LMS</VChip>
                <VBtn v-else icon size="x-small" variant="text" color="primary" title="Link to LMS course" @click="openLmsLink(act)"><VIcon>tabler-school</VIcon></VBtn>
                <VChip size="x-small" :color="actStatusColor(act.status)" variant="tonal">{{ act.status }}</VChip>
                <VBtn icon size="x-small" variant="text" @click="openEditActivity(act)"><VIcon>tabler-pencil</VIcon></VBtn>
                <VBtn icon size="x-small" variant="text" color="error" @click="openDelActivity(act)"><VIcon>tabler-trash</VIcon></VBtn>
              </div>
            </template>
          </VListItem>
        </VList>
        <VAlert v-else type="info" variant="tonal">{{ t('pa.development.no_activities') }}</VAlert>
      </VTabsWindowItem>

      <!-- Skills tab -->
      <VTabsWindowItem value="skills">
        <div class="d-flex justify-end mb-3">
          <VBtn color="primary" size="small" prepend-icon="tabler-plus" @click="skillFormOpen = true">{{ t('pa.development.add_skill') }}</VBtn>
        </div>

        <!-- Gap matrix -->
        <VRow v-if="employeeSkills.length" dense>
          <VCol v-for="es in employeeSkills" :key="es.id" cols="12" sm="6">
            <VCard border flat class="pa-3">
              <div class="d-flex align-center justify-space-between mb-2">
                <div>
                  <p class="text-body-2 font-weight-medium">{{ es.skill_name }}</p>
                  <VChip size="x-small" color="secondary" variant="tonal">{{ es.category }}</VChip>
                  <VChip v-if="es.verified" size="x-small" color="success" variant="tonal" class="ml-1">{{ t('pa.development.verified') }}</VChip>
                </div>
                <VBtn icon size="x-small" variant="text" color="error" @click="removeSkill(es.id)">
                  <VIcon>tabler-x</VIcon>
                </VBtn>
              </div>

              <div class="d-flex align-center ga-2 text-caption mb-1">
                <span style="min-width: 60px">{{ t('pa.development.current') }}: <strong>{{ es.current_level }}/5</strong></span>
                <VProgressLinear :model-value="(es.current_level / 5) * 100" color="primary" height="6" rounded class="flex-grow-1" />
              </div>
              <div class="d-flex align-center ga-2 text-caption">
                <span style="min-width: 60px">{{ t('pa.development.target') }}: <strong>{{ es.target_level }}/5</strong></span>
                <VProgressLinear :model-value="(es.target_level / 5) * 100" color="secondary" height="6" rounded class="flex-grow-1" />
              </div>

              <div v-if="es.gap > 0" class="mt-2">
                <VChip size="x-small" color="warning" variant="tonal">{{ t('pa.development.gap') }}: {{ es.gap }} {{ t('pa.development.levels') }}</VChip>
              </div>
              <div v-else class="mt-2">
                <VChip size="x-small" color="success" variant="tonal">{{ t('pa.development.target_met') }}</VChip>
              </div>
            </VCard>
          </VCol>
        </VRow>
        <VAlert v-else type="info" variant="tonal">{{ t('pa.development.no_skills') }}</VAlert>
      </VTabsWindowItem>

      <!-- AI tab -->
      <VTabsWindowItem value="ai">
        <VCard v-if="plan.ai_recommendations" border flat>
          <VCardItem>
            <template #prepend>
              <VAvatar color="secondary" variant="tonal" size="32">
                <VIcon size="16">tabler-robot</VIcon>
              </VAvatar>
            </template>
            <VCardTitle class="text-body-2 font-weight-bold">{{ t('pa.development.ai_recommendations') }}</VCardTitle>
          </VCardItem>
          <VCardText class="text-body-2" style="white-space: pre-wrap">{{ plan.ai_recommendations }}</VCardText>
        </VCard>
        <VAlert v-else type="info" variant="tonal">{{ t('pa.development.no_ai') }}</VAlert>
      </VTabsWindowItem>
    </VTabsWindow>

    <!-- Activity drawer -->
    <VNavigationDrawer v-model="actOpen" location="right" width="440" temporary>
      <VToolbar color="primary" density="compact">
        <VToolbarTitle class="text-body-1">{{ editAct ? t('pa.development.edit_activity') : t('pa.development.add_activity') }}</VToolbarTitle>
        <template #append><VBtn icon @click="actOpen = false"><VIcon>tabler-x</VIcon></VBtn></template>
      </VToolbar>
      <VForm ref="actForm" @submit.prevent="submitActivity" class="pa-4">
        <VTextField v-model="aForm.title" :label="t('pa.common.name')" density="compact" variant="outlined" :rules="[v => !!v || 'Required']" class="mb-3" />
        <VSelect v-model="aForm.activity_type" :items="activityTypes" item-title="label" item-value="value" :label="t('pa.cycles.col_type')" density="compact" variant="outlined" :rules="[v => !!v || 'Required']" class="mb-3" />
        <VTextarea v-model="aForm.description" :label="t('pa.cycles.description')" density="compact" variant="outlined" rows="3" class="mb-3" />
        <VTextField v-model="aForm.provider" :label="t('pa.development.provider')" density="compact" variant="outlined" class="mb-3" />
        <VTextField v-model="aForm.target_date" :label="t('pa.development.target_date')" type="date" density="compact" variant="outlined" class="mb-3" />
        <VTextField v-model.number="aForm.cost" :label="t('pa.development.cost_optional')" type="number" density="compact" variant="outlined" class="mb-3" />
        <template v-if="editAct">
          <VSelect v-model="aForm.status" :items="['pending','in_progress','completed','cancelled']" :label="t('pa.common.status')" density="compact" variant="outlined" class="mb-3" />
          <VTextField v-model="aForm.completed_date" :label="t('pa.development.completed_date')" type="date" density="compact" variant="outlined" class="mb-3" />
          <VTextarea v-model="aForm.evidence_notes" :label="t('pa.development.evidence_notes')" density="compact" variant="outlined" rows="2" class="mb-3" />
        </template>
        <VBtn type="submit" color="primary" block :loading="savingAct">
          {{ editAct ? t('pa.cycles.save_changes') : t('pa.development.add_activity') }}
        </VBtn>
      </VForm>
    </VNavigationDrawer>

    <!-- Add skill dialog -->
    <VDialog v-model="skillFormOpen" max-width="400">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold pa-4">{{ t('pa.development.add_skill_profile') }}</VCardTitle>
        <VCardText>
          <VSelect v-model="skForm.skill_id" :items="skills" item-title="name" item-value="id" :label="t('pa.development.skill_name')" density="compact" variant="outlined" class="mb-3">
            <template #item="{ item, props: iProps }">
              <VListItem v-bind="iProps">
                <template #append>
                  <VChip size="x-small" variant="tonal">{{ item.raw.category }}</VChip>
                </template>
              </VListItem>
            </template>
          </VSelect>
          <VSlider v-model="skForm.current_level" :label="t('pa.development.current_level')" min="1" max="5" step="1" thumb-label show-ticks="always" color="primary" class="mb-3" />
          <VSlider v-model="skForm.target_level" :label="t('pa.development.target_level')" min="1" max="5" step="1" thumb-label show-ticks="always" color="secondary" class="mb-3" />
          <VTextarea v-model="skForm.evidence" :label="t('pa.development.evidence_optional')" density="compact" variant="outlined" rows="2" />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="skillFormOpen = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="savingSkill" @click="submitSkill">{{ t('pa.development.add_skill') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Delete activity dialog -->
    <VDialog v-model="delActOpen" max-width="400">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold pa-4">{{ t('pa.development.delete_activity') }}</VCardTitle>
        <VCardText>{{ t('pa.cycles.cannot_undo') }}</VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="delActOpen = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="error" variant="flat" :loading="deletingAct" @click="confirmDelActivity">{{ t('pa.common.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Link activity to LMS course -->
    <VDialog v-model="lmsOpen" max-width="520">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold pa-4">Link to LMS Course</VCardTitle>
        <VCardText>
          <VTextField v-model="lmsSearch" label="Search published courses" variant="outlined" density="compact"
            prepend-inner-icon="tabler-search" class="mb-3" @update:model-value="searchCourses" />
          <VList v-if="lmsCourses.length" class="pa-0" max-height="320" style="overflow-y:auto">
            <VListItem v-for="c in lmsCourses" :key="c.id" class="border mb-1"
              :active="lmsSelected === c.id" @click="lmsSelected = c.id">
              <template #prepend><VRadio :model-value="lmsSelected === c.id" /></template>
              <VListItemTitle class="text-body-2">{{ c.title }}</VListItemTitle>
              <VListItemSubtitle class="text-caption">
                <span v-if="c.duration">{{ c.duration }} min</span>
                <span v-if="c.nqf"> · NQF {{ c.nqf }}</span>
                <span v-if="c.credits"> · {{ c.credits }} credits</span>
              </VListItemSubtitle>
            </VListItem>
          </VList>
          <VAlert v-else type="info" variant="tonal" density="compact">No published courses found.</VAlert>
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="lmsOpen = false">Cancel</VBtn>
          <VBtn color="primary" variant="flat" :disabled="!lmsSelected" :loading="lmsLinking" @click="confirmLmsLink">
            Link &amp; Enrol
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>

<script setup lang="ts">
import { ref, reactive } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'
import axios from 'axios'

const { t } = useI18n()

// ── Development → LMS course linking ──
const lmsOpen = ref(false)
const lmsSearch = ref('')
const lmsCourses = ref<{ id: number; title: string; duration: number | null; nqf: number | null; credits: number | null }[]>([])
const lmsSelected = ref<number | null>(null)
const lmsLinking = ref(false)
const lmsActivityId = ref<number | null>(null)
let lmsTimer: ReturnType<typeof setTimeout> | null = null

function openLmsLink(act: { id: number }) {
  lmsActivityId.value = act.id
  lmsSelected.value = null
  lmsSearch.value = ''
  lmsOpen.value = true
  searchCourses()
}
async function searchCourses() {
  if (lmsTimer) clearTimeout(lmsTimer)
  lmsTimer = setTimeout(async () => {
    const { data } = await axios.get(route('performance.dev-lms.courses'), { params: { q: lmsSearch.value } })
    lmsCourses.value = data.courses ?? []
  }, 250)
}
function confirmLmsLink() {
  if (!lmsActivityId.value || !lmsSelected.value) return
  lmsLinking.value = true
  router.post(route('performance.dev-lms.link-activity', lmsActivityId.value), { lms_course_id: lmsSelected.value }, {
    preserveScroll: true,
    onSuccess: () => (lmsOpen.value = false),
    onFinish: () => (lmsLinking.value = false),
  })
}

interface PlanData {
  id: number; title: string; employee_name: string; manager_name: string; cycle_name: string | null
  career_goal: string | null; strengths: string | null; development_areas: string | null
  status: string; overall_progress: number; ai_recommendations: string | null
  manager_notes: string | null; target_completion_date: string | null
}

interface Activity {
  id: number; title: string; activity_type: string; description: string | null
  target_date: string | null; completed_date: string | null; status: string
  provider: string | null; cost: number | null; evidence_notes: string | null
  lms_course_id?: number | null
}

interface EmployeeSkill {
  id: number; skill_id: number; skill_name: string; category: string
  current_level: number; target_level: number; gap: number; self_rated: boolean; verified: boolean
  evidence: string | null
}

interface Skill { id: number; name: string; category: string }

const props = defineProps<{ plan: PlanData; activities: Activity[]; employeeSkills: EmployeeSkill[]; skillGapMatrix: any[]; skills: Skill[] }>()

const tab           = ref('activities')
const generatingAi  = ref(false)

function generateAi() {
  generatingAi.value = true
  router.post(route('performance.development.ai', props.plan.id), {}, {
    onFinish: () => { generatingAi.value = false },
  })
}

// Activities
const actOpen   = ref(false)
const savingAct = ref(false)
const actForm   = ref()
const editAct   = ref<Activity | null>(null)
const delActOpen  = ref(false)
const delActItem  = ref<Activity | null>(null)
const deletingAct = ref(false)

const aForm = reactive({
  title: '', activity_type: '', description: '', provider: '', target_date: '',
  completed_date: '', cost: null as number | null, status: 'pending', evidence_notes: '',
})

const activityTypes = [
  { value: 'training',     label: 'Training' },
  { value: 'mentoring',    label: 'Mentoring' },
  { value: 'project',      label: 'Project Assignment' },
  { value: 'certification',label: 'Certification' },
  { value: 'reading',      label: 'Reading / Self-Study' },
  { value: 'shadowing',    label: 'Job Shadowing' },
  { value: 'coaching',     label: 'Coaching' },
]

function openAddActivity() {
  editAct.value = null
  Object.assign(aForm, { title: '', activity_type: '', description: '', provider: '', target_date: '', completed_date: '', cost: null, status: 'pending', evidence_notes: '' })
  actOpen.value = true
}

function openEditActivity(act: Activity) {
  editAct.value = act
  Object.assign(aForm, {
    title: act.title, activity_type: act.activity_type, description: act.description ?? '',
    provider: act.provider ?? '', target_date: act.target_date ?? '', completed_date: act.completed_date ?? '',
    cost: act.cost, status: act.status, evidence_notes: act.evidence_notes ?? '',
  })
  actOpen.value = true
}

async function submitActivity() {
  const { valid } = await actForm.value.validate()
  if (!valid) return
  savingAct.value = true
  if (editAct.value) {
    router.patch(route('performance.development.activities.update', { plan: props.plan.id, activity: editAct.value.id }), aForm, {
      onSuccess: () => { actOpen.value = false },
      onFinish:  () => { savingAct.value = false },
    })
  } else {
    router.post(route('performance.development.activities.store', props.plan.id), aForm, {
      onSuccess: () => { actOpen.value = false },
      onFinish:  () => { savingAct.value = false },
    })
  }
}

function openDelActivity(act: Activity) { delActItem.value = act; delActOpen.value = true }
function confirmDelActivity() {
  if (!delActItem.value) return
  deletingAct.value = true
  router.delete(route('performance.development.activities.destroy', { plan: props.plan.id, activity: delActItem.value.id }), {
    onSuccess: () => { delActOpen.value = false },
    onFinish:  () => { deletingAct.value = false },
  })
}

// Skills
const skillFormOpen = ref(false)
const savingSkill   = ref(false)
const skForm = reactive({ skill_id: null as number | null, current_level: 1, target_level: 3, evidence: '' })

function submitSkill() {
  savingSkill.value = true
  router.post(route('performance.development.skills.store', props.plan.id), skForm as any, {
    onSuccess: () => { skillFormOpen.value = false },
    onFinish:  () => { savingSkill.value = false },
  })
}

function removeSkill(id: number) {
  router.delete(route('performance.development.skills.destroy', { plan: props.plan.id, skill: id }))
}

// Helpers
function statusColor(s: string) { return { draft: 'grey', active: 'blue', completed: 'green', paused: 'orange' }[s] ?? 'grey' }
function actStatusColor(s: string) { return { pending: 'grey', in_progress: 'blue', completed: 'green', cancelled: 'error' }[s] ?? 'grey' }
function activityTypeColor(t: string) { return { training: 'blue', mentoring: 'purple', project: 'orange', certification: 'green', reading: 'teal', shadowing: 'cyan', coaching: 'deep-purple' }[t] ?? 'grey' }
function activityTypeIcon(t: string) { return { training: 'tabler-school', mentoring: 'tabler-users', project: 'tabler-briefcase', certification: 'tabler-certificate', reading: 'tabler-book', shadowing: 'tabler-eye', coaching: 'tabler-microphone' }[t] ?? 'tabler-star' }
</script>
