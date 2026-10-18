<template>
  <div class="p-2">
    <!-- Header -->
    <div class="d-flex align-center gap-3 mb-4 flex-wrap">
      <div>
        <div class="text-h6">{{ t('lms_skills_library.page_title') }}</div>
        <div class="text-body-2 text-medium-emphasis">{{ t('lms_skills_library.page_description') }}</div>
      </div>
      <VSpacer />
      <VBtn color="primary" prepend-icon="mdi-plus" @click="openCreate">
        {{ t('lms_skills_library.new_skill') }}
      </VBtn>
    </div>

    <!-- Filters -->
    <div class="d-flex gap-3 mb-4 flex-wrap">
      <VTextField
        v-model="search"
        :label="t('lms_skills_library.search_placeholder')"
        prepend-inner-icon="mdi-magnify"
        density="compact"
        hide-details
        clearable
        style="max-width:260px"
        @update:model-value="applyFilters"
      />
      <VSelect
        v-model="filterCategory"
        :items="[{ value: null, title: t('lms_skills_library.all_categories') }, ...categoryItems]"
        item-title="title"
        item-value="value"
        :label="t('lms_skills_library.category')"
        density="compact"
        hide-details
        style="max-width:200px"
        @update:model-value="applyFilters"
      />
    </div>

    <VCard>
      <VDataTable
        :headers="headers"
        :items="skills.data"
        hide-default-footer
      >
        <template #item.category="{ item }">
          <VChip :color="catColor(item.category)" size="small" label>
            {{ categories[item.category] ?? item.category ?? t('lms_skills_library.no_category') }}
          </VChip>
        </template>

        <template #item.is_active="{ item }">
          <VChip :color="item.is_active ? 'success' : 'grey'" size="x-small">
            {{ item.is_active ? t('lms_skills_library.status.active') : t('lms_skills_library.status.inactive') }}
          </VChip>
        </template>

        <template #item.mappings="{ item }">
          <div class="d-flex gap-1 flex-wrap">
            <VChip size="x-small" color="primary" variant="tonal" prepend-icon="mdi-briefcase">
              {{ t('lms_skills_library.mappings.roles', { count: item.job_title_mappings_count }) }}
            </VChip>
            <VChip size="x-small" color="teal" variant="tonal" prepend-icon="mdi-book-open">
              {{ t('lms_skills_library.mappings.courses', { count: item.course_mappings_count }) }}
            </VChip>
            <VChip size="x-small" color="purple" variant="tonal" prepend-icon="mdi-account-group">
              {{ t('lms_skills_library.mappings.employees', { count: item.employee_skills_count }) }}
            </VChip>
          </div>
        </template>

        <template #item.actions="{ item }">
          <div class="d-flex gap-1">
            <VBtn icon size="x-small" variant="text" color="primary" @click="openMapping(item)">
              <VIcon size="16">mdi-link</VIcon>
              <VTooltip activator="parent">{{ t('lms_skills_library.actions.manage_mappings') }}</VTooltip>
            </VBtn>
            <VBtn icon size="x-small" variant="text" @click="openEdit(item)">
              <VIcon size="16">mdi-pencil</VIcon>
              <VTooltip activator="parent">{{ t('lms_skills_library.actions.edit') }}</VTooltip>
            </VBtn>
            <VBtn icon size="x-small" variant="text" color="error" @click="deleteSkill(item)">
              <VIcon size="16">mdi-delete</VIcon>
              <VTooltip activator="parent">{{ t('lms_skills_library.actions.delete') }}</VTooltip>
            </VBtn>
          </div>
        </template>
      </VDataTable>

      <div v-if="skills.last_page > 1" class="d-flex justify-center pa-4">
        <VPagination
          :model-value="skills.current_page"
          :length="skills.last_page"
          density="compact"
          @update:model-value="goPage"
        />
      </div>
    </VCard>

    <!-- ── Create / Edit skill dialog ───────────────────────────────────── -->
    <VDialog v-model="skillDialog" max-width="540">
      <VCard>
        <VCardTitle class="pa-4">
          {{ editingSkill ? t('lms_skills_library.dialog_skill.title_edit') : t('lms_skills_library.dialog_skill.title_new') }}
        </VCardTitle>
        <VCardText>
          <VTextField
            v-model="skillForm.name"
            :label="t('lms_skills_library.dialog_skill.name_label')"
            density="compact"
            class="mb-3"
          />
          <VTextarea
            v-model="skillForm.description"
            :label="t('lms_skills_library.dialog_skill.description_label')"
            density="compact"
            rows="2"
            class="mb-3"
          />
          <VSelect
            v-model="skillForm.category"
            :items="categoryItems"
            item-title="title"
            item-value="value"
            :label="t('lms_skills_library.dialog_skill.category_label')"
            density="compact"
            class="mb-3"
          />
          <VRow>
            <VCol cols="6">
              <VTextField
                v-model.number="skillForm.max_level"
                :label="t('lms_skills_library.dialog_skill.max_level_label')"
                type="number"
                density="compact"
              />
            </VCol>
            <VCol cols="6">
              <VSwitch
                v-model="skillForm.is_active"
                :label="t('lms_skills_library.dialog_skill.active_label')"
                color="primary"
                density="compact"
              />
            </VCol>
          </VRow>

          <!-- Level labels (dynamic per max_level) -->
          <VExpansionPanels class="mt-2">
            <VExpansionPanel :title="t('lms_skills_library.dialog_skill.level_labels_title')">
              <VExpansionPanelText>
                <VTextField
                  v-for="l in skillForm.max_level"
                  :key="l"
                  v-model="skillForm.level_labels[l]"
                  :label="t('lms_skills_library.dialog_skill.level_label', { level: l })"
                  density="compact"
                  class="mb-2"
                />
              </VExpansionPanelText>
            </VExpansionPanel>
          </VExpansionPanels>
        </VCardText>
        <VCardActions class="px-4 pb-4">
          <VSpacer />
          <VBtn variant="text" @click="skillDialog = false">{{ t('lms_skills_library.dialog_skill.cancel') }}</VBtn>
          <VBtn color="primary" :loading="saving" @click="saveSkill">
            {{ editingSkill ? t('lms_skills_library.dialog_skill.update') : t('lms_skills_library.dialog_skill.create') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Mapping management dialog ────────────────────────────────────── -->
    <VDialog v-model="mappingDialog" max-width="680" scrollable>
      <VCard v-if="mappingSkill">
        <VCardTitle class="pa-4 pb-2">
          {{ t('lms_skills_library.dialog_mappings.title', { name: mappingSkill.name }) }}
        </VCardTitle>
        <VCardText>
          <VTabs v-model="mappingTab">
            <VTab value="jobtitle">{{ t('lms_skills_library.dialog_mappings.tabs.job_titles') }}</VTab>
            <VTab value="courses">{{ t('lms_skills_library.dialog_mappings.tabs.courses') }}</VTab>
            <VTab value="questions">{{ t('lms_skills_library.dialog_mappings.tabs.questions') }}</VTab>
          </VTabs>

          <!-- Job Titles tab -->
          <VWindow v-model="mappingTab">
            <VWindowItem value="jobtitle">
              <div class="d-flex gap-2 mt-3 mb-3">
                <VSelect
                  v-model="mapForm.position_id"
                  :items="positions"
                  item-title="name"
                  item-value="id"
                  :label="t('lms_skills_library.dialog_mappings.job_titles.position_label')"
                  density="compact"
                  hide-details
                  style="max-width:240px"
                />
                <VTextField
                  v-model.number="mapForm.required_level"
                  :label="t('lms_skills_library.dialog_mappings.job_titles.required_level_label')"
                  type="number"
                  density="compact"
                  hide-details
                  style="max-width:130px"
                />
                <VCheckbox
                  v-model="mapForm.is_mandatory"
                  :label="t('lms_skills_library.dialog_mappings.job_titles.mandatory_label')"
                  density="compact"
                  hide-details
                />
                <VBtn color="primary" size="small" :loading="mapping" @click="doMapJobTitle">
                  {{ t('lms_skills_library.dialog_mappings.job_titles.add_button') }}
                </VBtn>
              </div>
              <VList density="compact">
                <VListItem
                  v-for="m in mappingSkill.job_title_mappings ?? []"
                  :key="m.id"
                  rounded="lg"
                  class="mb-1"
                >
                  <VListItemTitle>{{ m.position?.name ?? '—' }}</VListItemTitle>
                  <VListItemSubtitle>
                    {{
                      t('lms_skills_library.dialog_mappings.job_titles.subtitle', {
                        level: m.required_level,
                        mandatory: m.is_mandatory
                          ? t('lms_skills_library.dialog_mappings.job_titles.mandatory')
                          : t('lms_skills_library.dialog_mappings.job_titles.optional')
                      })
                    }}
                  </VListItemSubtitle>
                  <template #append>
                    <VBtn icon size="x-small" variant="text" color="error" @click="doUnmapJobTitle(m.position_id)">
                      <VIcon size="14">mdi-close</VIcon>
                    </VBtn>
                  </template>
                </VListItem>
                <div v-if="!mappingSkill.job_title_mappings?.length" class="text-center text-medium-emphasis py-4 text-body-2">
                  {{ t('lms_skills_library.dialog_mappings.job_titles.no_mappings') }}
                </div>
              </VList>
            </VWindowItem>

            <!-- Courses tab -->
            <VWindowItem value="courses">
              <div class="d-flex gap-2 mt-3 mb-3">
                <VSelect
                  v-model="mapForm.course_id"
                  :items="courses"
                  item-title="title"
                  item-value="id"
                  :label="t('lms_skills_library.dialog_mappings.courses.course_label')"
                  density="compact"
                  hide-details
                  style="max-width:260px"
                />
                <VTextField
                  v-model.number="mapForm.skill_level_gained"
                  :label="t('lms_skills_library.dialog_mappings.courses.level_gained_label')"
                  type="number"
                  density="compact"
                  hide-details
                  style="max-width:130px"
                />
                <VBtn color="primary" size="small" :loading="mapping" @click="doMapCourse">
                  {{ t('lms_skills_library.dialog_mappings.courses.add_button') }}
                </VBtn>
              </div>
              <VList density="compact">
                <VListItem
                  v-for="m in mappingSkill.course_mappings ?? []"
                  :key="m.id"
                  rounded="lg"
                  class="mb-1"
                >
                  <VListItemTitle>{{ m.course?.title ?? '—' }}</VListItemTitle>
                  <VListItemSubtitle>
                    {{ t('lms_skills_library.dialog_mappings.courses.subtitle', { level: m.skill_level_gained }) }}
                  </VListItemSubtitle>
                  <template #append>
                    <VBtn icon size="x-small" variant="text" color="error" @click="doUnmapCourse(m.course_id)">
                      <VIcon size="14">mdi-close</VIcon>
                    </VBtn>
                  </template>
                </VListItem>
                <div v-if="!mappingSkill.course_mappings?.length" class="text-center text-medium-emphasis py-4 text-body-2">
                  {{ t('lms_skills_library.dialog_mappings.courses.no_mappings') }}
                </div>
              </VList>
            </VWindowItem>

            <!-- Questions tab -->
            <VWindowItem value="questions">
              <div class="d-flex gap-2 mt-3 mb-3">
                <VSelect
                  v-model="mapForm.question_id"
                  :items="questions"
                  item-title="question_text"
                  item-value="id"
                  :label="t('lms_skills_library.dialog_mappings.questions.question_label')"
                  density="compact"
                  hide-details
                />
                <VBtn color="primary" size="small" :loading="mapping" @click="doMapQuestion">
                  {{ t('lms_skills_library.dialog_mappings.questions.add_button') }}
                </VBtn>
              </div>
              <VList density="compact">
                <VListItem
                  v-for="m in mappingSkill.question_mappings ?? []"
                  :key="m.id"
                  rounded="lg"
                  class="mb-1"
                >
                  <VListItemTitle class="text-wrap text-body-2">{{ m.question?.question_text ?? '—' }}</VListItemTitle>
                  <template #append>
                    <VBtn icon size="x-small" variant="text" color="error" @click="doUnmapQuestion(m.question_id)">
                      <VIcon size="14">mdi-close</VIcon>
                    </VBtn>
                  </template>
                </VListItem>
                <div v-if="!mappingSkill.question_mappings?.length" class="text-center text-medium-emphasis py-4 text-body-2">
                  {{ t('lms_skills_library.dialog_mappings.questions.no_mappings') }}
                </div>
              </VList>
            </VWindowItem>
          </VWindow>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="mappingDialog = false">{{ t('lms_skills_library.dialog_mappings.close') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VSnackbar v-model="snackbar" :timeout="2500" color="success" location="bottom right">
      {{ snackbarText }}
    </VSnackbar>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  skills: any
  positions: any[]
  courses: any[]
  questions: any[]
  categories: Record<string, string>
  filters: Record<string, any>
}>()

const search         = ref(props.filters.search ?? '')
const filterCategory = ref(props.filters.category ?? null)
const skillDialog    = ref(false)
const mappingDialog  = ref(false)
const mappingTab     = ref('jobtitle')
const saving         = ref(false)
const mapping        = ref(false)
const snackbar       = ref(false)
const snackbarText   = ref('')
const editingSkill   = ref<any>(null)
const mappingSkill   = ref<any>(null)

const defaultSkillForm = () => ({
  name: '', description: '', category: null as string | null,
  max_level: 5, is_active: true, level_labels: {} as Record<number, string>,
})
const skillForm = ref(defaultSkillForm())

const mapForm = ref({
  position_id: null as number | null,
  required_level: 3,
  is_mandatory: true,
  course_id: null as number | null,
  skill_level_gained: 1,
  question_id: null as number | null,
})

const categoryItems = computed(() =>
  Object.entries(props.categories).map(([value, title]) => ({ value, title }))
)

const catColors: Record<string, string> = {
  technical: 'blue', soft: 'teal', leadership: 'purple',
  compliance: 'orange', operational: 'cyan',
}
const catColor = (c: string) => catColors[c] ?? 'grey'

const headers = computed(() => [
  { title: t('lms_skills_library.table.name'),     key: 'name' },
  { title: t('lms_skills_library.table.category'), key: 'category' },
  { title: t('lms_skills_library.table.levels'),   key: 'max_level',   sortable: true },
  { title: t('lms_skills_library.table.status'),   key: 'is_active',   sortable: true },
  { title: t('lms_skills_library.table.mappings'), key: 'mappings',    sortable: false },
  { title: '',                                     key: 'actions',     sortable: false, align: 'end' },
])

function applyFilters() {
  router.get(route('lms.admin.skills'), { search: search.value, category: filterCategory.value }, { preserveState: true, replace: true })
}

function goPage(page: number) {
  router.get(route('lms.admin.skills'), { ...props.filters, page }, { preserveState: true })
}

function openCreate() {
  editingSkill.value = null
  skillForm.value = defaultSkillForm()
  skillDialog.value = true
}

function openEdit(skill: any) {
  editingSkill.value = skill
  skillForm.value = {
    name: skill.name, description: skill.description ?? '', category: skill.category,
    max_level: skill.max_level, is_active: skill.is_active, level_labels: skill.level_labels ?? {},
  }
  skillDialog.value = true
}

function saveSkill() {
  saving.value = true
  const url    = editingSkill.value
    ? route('lms.admin.skills.update', editingSkill.value.id)
    : route('lms.admin.skills.store')
  const method = editingSkill.value ? 'patch' : 'post'

  router[method](url, skillForm.value, {
    onSuccess: () => {
      snackbarText.value = t('lms_skills_library.snackbar.saved')
      snackbar.value = true
      skillDialog.value = false
    },
    onFinish:  () => { saving.value = false },
    preserveScroll: true,
  })
}

function deleteSkill(skill: any) {
  router.delete(route('lms.admin.skills.destroy', skill.id), {
    onSuccess: () => {
      snackbarText.value = t('lms_skills_library.snackbar.removed')
      snackbar.value = true
    },
    preserveScroll: true,
  })
}

async function openMapping(skill: any) {
  const res = await axios.get(route('lms.admin.skills'), { params: { skill_id: skill.id, include_mappings: 1 } })
  mappingSkill.value = {
    ...skill,
    job_title_mappings: skill.job_title_mappings ?? [],
    course_mappings:    skill.course_mappings ?? [],
    question_mappings:  skill.question_mappings ?? [],
  }
  mapForm.value = { position_id: null, required_level: 3, is_mandatory: true, course_id: null, skill_level_gained: 1, question_id: null }
  mappingDialog.value = true
}

function doMapJobTitle() {
  if (!mappingSkill.value || !mapForm.value.position_id) return
  mapping.value = true
  router.post(
    route('lms.admin.skills.job-titles.map', mappingSkill.value.id),
    { position_id: mapForm.value.position_id, required_level: mapForm.value.required_level, is_mandatory: mapForm.value.is_mandatory },
    {
      onSuccess: () => {
        snackbarText.value = t('lms_skills_library.snackbar.mapped')
        snackbar.value = true
      },
      onFinish:  () => { mapping.value = false },
      preserveScroll: true,
    }
  )
}

function doUnmapJobTitle(positionId: number) {
  router.delete(route('lms.admin.skills.job-titles.unmap', { skill: mappingSkill.value.id, positionId }), { preserveScroll: true })
}

function doMapCourse() {
  if (!mappingSkill.value || !mapForm.value.course_id) return
  mapping.value = true
  router.post(
    route('lms.admin.skills.courses.map', mappingSkill.value.id),
    { course_id: mapForm.value.course_id, skill_level_gained: mapForm.value.skill_level_gained },
    {
      onSuccess: () => {
        snackbarText.value = t('lms_skills_library.snackbar.mapped')
        snackbar.value = true
      },
      onFinish:  () => { mapping.value = false },
      preserveScroll: true,
    }
  )
}

function doUnmapCourse(courseId: number) {
  router.delete(route('lms.admin.skills.courses.unmap', { skill: mappingSkill.value.id, courseId }), { preserveScroll: true })
}

function doMapQuestion() {
  if (!mappingSkill.value || !mapForm.value.question_id) return
  mapping.value = true
  router.post(
    route('lms.admin.skills.questions.map', mappingSkill.value.id),
    { question_id: mapForm.value.question_id },
    {
      onSuccess: () => {
        snackbarText.value = t('lms_skills_library.snackbar.mapped')
        snackbar.value = true
      },
      onFinish:  () => { mapping.value = false },
      preserveScroll: true,
    }
  )
}

function doUnmapQuestion(questionId: number) {
  router.delete(route('lms.admin.skills.questions.unmap', { skill: mappingSkill.value.id, questionId }), { preserveScroll: true })
}
</script>
