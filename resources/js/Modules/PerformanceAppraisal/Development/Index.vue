<template>
  <VContainer fluid class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('pa.development.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">{{ t('pa.development.subtitle') }}</p>
      </div>
      <div class="d-flex ga-2">
        <VBtn variant="tonal" prepend-icon="tabler-book" @click="skillLibOpen = true">
          {{ t('pa.development.skill_library') }}
        </VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">
          {{ t('pa.development.new') }}
        </VBtn>
      </div>
    </div>

    <!-- Plans grid -->
    <VRow v-if="plans.length" dense>
      <VCol v-for="p in plans" :key="p.id" cols="12" sm="6" xl="4">
        <VCard border flat>
          <VCardItem>
            <template #prepend>
              <VAvatar :color="statusColor(p.status)" variant="tonal" size="38">
                <VIcon size="18">tabler-address-book</VIcon>
              </VAvatar>
            </template>
            <VCardTitle class="text-body-2 font-weight-medium">{{ p.title }}</VCardTitle>
            <VCardSubtitle>{{ p.employee_name }}</VCardSubtitle>
            <template #append>
              <VChip size="x-small" :color="statusColor(p.status)" variant="tonal">{{ p.status }}</VChip>
            </template>
          </VCardItem>

          <VCardText class="pt-0 pb-1">
            <div class="d-flex justify-space-between text-caption mb-1">
              <span>{{ t('pa.development.progress') }}</span>
              <span>{{ p.overall_progress }}%</span>
            </div>
            <VProgressLinear
              :model-value="p.overall_progress"
              :color="progressColor(p.overall_progress)"
              height="6"
              rounded
              class="mb-2"
            />
            <div class="text-caption text-medium-emphasis">
              {{ t('pa.development.manager') }}: {{ p.manager_name }}
              <span v-if="p.cycle_name"> · {{ p.cycle_name }}</span>
            </div>
            <div v-if="p.career_goal" class="text-caption text-medium-emphasis mt-1 text-truncate">
              {{ t('pa.development.career_goal') }}: {{ p.career_goal }}
            </div>
          </VCardText>

          <VCardActions class="px-4 pb-3 pt-1">
            <span class="text-caption text-medium-emphasis">
              {{ p.activities_count }} {{ t('pa.development.activities') }}
              <span v-if="p.target_completion_date"> · {{ t('pa.development.due') }} {{ p.target_completion_date }}</span>
            </span>
            <VSpacer />
            <VBtn size="x-small" variant="tonal" color="primary" @click="router.visit(route('performance.development.show', p.id))">
              {{ t('pa.development.open_idp') }}
            </VBtn>
            <VBtn size="x-small" icon variant="text" color="error" class="ml-1" @click="openDelete(p)">
              <VIcon>tabler-trash</VIcon>
            </VBtn>
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>
    <VAlert v-else type="info" variant="tonal">{{ t('pa.development.empty') }}</VAlert>

    <!-- Create drawer -->
    <VNavigationDrawer v-model="createOpen" location="right" width="480" temporary>
      <VToolbar color="primary" density="compact">
        <VToolbarTitle class="text-body-1">{{ t('pa.development.new_plan') }}</VToolbarTitle>
        <template #append><VBtn icon @click="createOpen = false"><VIcon>tabler-x</VIcon></VBtn></template>
      </VToolbar>
      <VForm ref="createForm" @submit.prevent="submitCreate" class="pa-4">
        <VTextField v-model="form.title" :label="t('pa.development.plan_title')" density="compact" variant="outlined" :rules="[v => !!v || 'Required']" class="mb-3" />
        <VSelect v-model="form.employee_id" :items="employees" item-title="name" item-value="id" :label="t('pa.development.employee')" density="compact" variant="outlined" :rules="[v => !!v || 'Required']" class="mb-3" />
        <VSelect v-model="form.manager_id" :items="employees" item-title="name" item-value="id" :label="t('pa.development.manager')" density="compact" variant="outlined" :rules="[v => !!v || 'Required']" class="mb-3" />
        <VSelect v-model="form.cycle_id" :items="cycles" item-title="name" item-value="id" :label="t('pa.development.cycle_optional')" density="compact" variant="outlined" clearable class="mb-3" />
        <VTextarea v-model="form.career_goal" :label="t('pa.development.career_goal')" density="compact" variant="outlined" rows="2" class="mb-3" />
        <VTextarea v-model="form.strengths" :label="t('pa.development.strengths')" density="compact" variant="outlined" rows="2" class="mb-3" />
        <VTextarea v-model="form.development_areas" :label="t('pa.development.development_areas')" density="compact" variant="outlined" rows="2" class="mb-3" />
        <VTextField v-model="form.target_completion_date" :label="t('pa.development.target_completion')" type="date" density="compact" variant="outlined" class="mb-4" />
        <VBtn type="submit" color="primary" block :loading="saving">{{ t('pa.development.create_plan') }}</VBtn>
      </VForm>
    </VNavigationDrawer>

    <!-- Skill Library dialog -->
    <VDialog v-model="skillLibOpen" max-width="700">
      <VCard>
        <VToolbar color="secondary" density="compact">
          <VToolbarTitle class="text-body-1">{{ t('pa.development.skill_library') }}</VToolbarTitle>
          <template #append><VBtn icon @click="skillLibOpen = false"><VIcon>tabler-x</VIcon></VBtn></template>
        </VToolbar>
        <VCardText class="pa-4">
          <VForm @submit.prevent="submitSkill" class="mb-4">
            <VRow dense>
              <VCol cols="12" sm="5">
                <VTextField v-model="sForm.name" :label="t('pa.development.skill_name')" density="compact" variant="outlined" :rules="[v => !!v || 'Required']" />
              </VCol>
              <VCol cols="12" sm="4">
                <VSelect v-model="sForm.category" :items="skillCategories" :label="t('pa.development.skill_category')" density="compact" variant="outlined" :rules="[v => !!v || 'Required']" />
              </VCol>
              <VCol cols="12" sm="3" class="d-flex align-center">
                <VBtn type="submit" color="primary" :loading="savingSkill" block>{{ t('pa.development.add_skill') }}</VBtn>
              </VCol>
            </VRow>
          </VForm>
          <VDivider class="mb-3" />
          <div v-for="(sList, cat) in groupedSkills" :key="cat" class="mb-3">
            <p class="text-caption font-weight-bold text-uppercase text-medium-emphasis mb-1">{{ cat }}</p>
            <VChipGroup column>
              <VChip
                v-for="sk in sList"
                :key="sk.id"
                size="small"
                :variant="sk.is_active ? 'tonal' : 'outlined'"
                closable
                @click:close="deleteSkill(sk.id)"
              >
                {{ sk.name }}
              </VChip>
            </VChipGroup>
          </div>
        </VCardText>
      </VCard>
    </VDialog>

    <!-- Delete IDP dialog -->
    <VDialog v-model="delOpen" max-width="400">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold pa-4">{{ t('pa.development.delete_title') }}</VCardTitle>
        <VCardText>{{ t('pa.cycles.cannot_undo') }}</VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="delOpen = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="error" variant="flat" :loading="deleting" @click="confirmDelete">{{ t('pa.common.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>

<script setup lang="ts">
import { ref, reactive, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { route } from 'ziggy-js'

const { t } = useI18n()

interface Plan {
  id: number; title: string; employee_name: string; manager_name: string
  cycle_name: string | null; status: string; overall_progress: number
  activities_count: number; target_completion_date: string | null; career_goal: string | null
}

interface Skill { id: number; name: string; category: string; is_active: boolean }
interface Employee { id: number; name: string }
interface Cycle    { id: number; name: string }

const props = defineProps<{ plans: Plan[]; skills: Skill[]; cycles: Cycle[]; employees: Employee[] }>()

const createOpen = ref(false)
const saving     = ref(false)
const createForm = ref()
const delOpen    = ref(false)
const delItem    = ref<Plan | null>(null)
const deleting   = ref(false)

const form = reactive({
  title: '', employee_id: null as number | null, manager_id: null as number | null,
  cycle_id: null as number | null, career_goal: '', strengths: '', development_areas: '',
  target_completion_date: '',
})

function openCreate() {
  Object.assign(form, { title: '', employee_id: null, manager_id: null, cycle_id: null, career_goal: '', strengths: '', development_areas: '', target_completion_date: '' })
  createOpen.value = true
}

async function submitCreate() {
  const { valid } = await createForm.value.validate()
  if (!valid) return
  saving.value = true
  router.post(route('performance.development.store'), form as any, {
    onSuccess: () => { createOpen.value = false },
    onFinish:  () => { saving.value = false },
  })
}

function openDelete(p: Plan) { delItem.value = p; delOpen.value = true }
function confirmDelete() {
  if (!delItem.value) return
  deleting.value = true
  router.delete(route('performance.development.destroy', delItem.value.id), {
    onSuccess: () => { delOpen.value = false },
    onFinish:  () => { deleting.value = false },
  })
}

// Skill library
const skillLibOpen  = ref(false)
const savingSkill   = ref(false)
const sForm         = reactive({ name: '', category: '', description: '' })
const skillCategories = ['technical', 'soft', 'leadership', 'domain', 'compliance']

const groupedSkills = computed(() => {
  const g: Record<string, Skill[]> = {}
  for (const s of props.skills) {
    if (!g[s.category]) g[s.category] = []
    g[s.category].push(s)
  }
  return g
})

function submitSkill() {
  savingSkill.value = true
  router.post(route('performance.development.library.store'), sForm, {
    onSuccess: () => { sForm.name = ''; sForm.category = '' },
    onFinish:  () => { savingSkill.value = false },
  })
}

function deleteSkill(id: number) {
  router.delete(route('performance.development.library.destroy', id))
}

// Helpers
function statusColor(status: string) {
  return { draft: 'grey', active: 'blue', completed: 'green', paused: 'orange' }[status] ?? 'grey'
}

function progressColor(pct: number) {
  if (pct >= 75) return 'green'
  if (pct >= 40) return 'blue'
  return 'orange'
}
</script>
