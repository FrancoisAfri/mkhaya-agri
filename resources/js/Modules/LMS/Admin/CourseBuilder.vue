<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import axios from 'axios'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Material = {
  id: number; title: string; type: string; file_name: string
  mime_type: string | null; size_mb: number; url: string; sort_order: number
}

type Lesson = {
  id: number; title: string; type: string; content_body: string | null
  external_url: string | null; duration_mins: number; sort_order: number
  is_preview: boolean; materials: Material[]
}

type Module = {
  id: number; title: string; description: string | null
  sort_order: number; lessons: Lesson[]
}

type Prerequisite = {
  id: number; course: { id: number; title: string; type: string }
}

type AvailableCourse = { id: number; title: string; type: string }

type Course = {
  id: number; uuid: string; title: string; description: string | null
  type: string; duration_mins: number | null; pass_score: number | null
  expires_after_days: number | null; is_mandatory: boolean; is_published: boolean
  category: { id: number; name: string } | null
  modules: Module[]
  prerequisites: Prerequisite[]
  nqf_level: number | null; credits: number | null; saqa_id: string | null
  unit_standard_code: string | null; ofo_code: string | null
  seta_id: number | null; seta_accreditation_number: string | null
  seta_accreditation_expires_at: string | null; qualification_component: string | null
  is_qcto_registered: boolean; is_accredited: boolean
}

const props = defineProps<{
  course: Course
  availableCourses: AvailableCourse[]
  setas?: { id: number; name: string; acronym: string }[]
}>()

const tab = ref('builder')
const expandedModules = ref<Set<number>>(new Set(props.course.modules.map(m => m.id)))
function toggleModule(id: number) {
  if (expandedModules.value.has(id)) expandedModules.value.delete(id)
  else expandedModules.value.add(id)
}

const selectedLesson = ref<Lesson | null>(null)
const selectedModule = ref<Module | null>(null)

function selectLesson(module: Module, lesson: Lesson) {
  selectedModule.value = module
  selectedLesson.value = lesson
}

const typeIcon: Record<string, string> = {
  video: 'tabler-video', pdf: 'tabler-file-type-pdf',
  document: 'tabler-file-text', presentation: 'tabler-presentation',
  text: 'tabler-align-left', link: 'tabler-external-link',
}
const typeColor: Record<string, string> = {
  video: 'primary', pdf: 'error', document: 'secondary',
  presentation: 'warning', text: 'success', link: 'info',
}
const lessonTypeOptions = computed(() => [
  { title: t('lms_course_builder.lesson_types.video'), value: 'video' },
  { title: t('lms_course_builder.lesson_types.pdf'), value: 'pdf' },
  { title: t('lms_course_builder.lesson_types.document'), value: 'document' },
  { title: t('lms_course_builder.lesson_types.presentation'), value: 'presentation' },
  { title: t('lms_course_builder.lesson_types.text'), value: 'text' },
  { title: t('lms_course_builder.lesson_types.link'), value: 'link' },
])

const deleteModuleDialog   = ref(false)
const deleteModuleTarget   = ref<Module | null>(null)
const deleteLessonDialog   = ref(false)
const deleteLessonTarget   = ref<{ lesson: Lesson; module: Module } | null>(null)
const deleteMaterialDialog = ref(false)
const deleteMaterialTarget = ref<{ lesson: Lesson; mat: Material } | null>(null)

const moduleDialog  = ref(false)
const editModule    = ref<Module | null>(null)
const savingModule  = ref(false)
const moduleForm    = ref({ title: '', description: '' })

function openCreateModule() {
  editModule.value = null
  moduleForm.value = { title: '', description: '' }
  moduleDialog.value = true
}
function openEditModule(m: Module) {
  editModule.value = m
  moduleForm.value = { title: m.title, description: m.description ?? '' }
  moduleDialog.value = true
}
function submitModule() {
  savingModule.value = true
  const url = editModule.value
    ? route('lms.admin.courses.modules.update', { course: props.course.uuid, module: editModule.value.id })
    : route('lms.admin.courses.modules.store', { course: props.course.uuid })
  const method = editModule.value ? 'patch' : 'post'
  router[method](url, moduleForm.value, {
    onFinish: () => { savingModule.value = false },
    onSuccess: () => { moduleDialog.value = false },
  })
}
function deleteModule(m: Module) { deleteModuleTarget.value = m; deleteModuleDialog.value = true }
function executeDeleteModule() {
  if (!deleteModuleTarget.value) return
  router.delete(route('lms.admin.courses.modules.destroy', { course: props.course.uuid, module: deleteModuleTarget.value.id }), {
    onSuccess: () => { deleteModuleDialog.value = false; deleteModuleTarget.value = null },
  })
}

const lessonDialog    = ref(false)
const editLesson      = ref<Lesson | null>(null)
const lessonModuleId  = ref<number | null>(null)
const savingLesson    = ref(false)
const lessonForm      = ref({
  title: '', type: 'text', content_body: '', external_url: '',
  duration_mins: 0, is_preview: false,
})

function openCreateLesson(m: Module) {
  editLesson.value = null; lessonModuleId.value = m.id
  lessonForm.value = { title: '', type: 'text', content_body: '', external_url: '', duration_mins: 0, is_preview: false }
  lessonDialog.value = true
}
function openEditLesson(lesson: Lesson) {
  editLesson.value = lesson; lessonModuleId.value = null
  lessonForm.value = { title: lesson.title, type: lesson.type, content_body: lesson.content_body ?? '', external_url: lesson.external_url ?? '', duration_mins: lesson.duration_mins, is_preview: lesson.is_preview }
  lessonDialog.value = true
}
function submitLesson() {
  savingLesson.value = true
  let url: string; let method: 'post' | 'patch'
  if (editLesson.value) { url = route('lms.admin.courses.lessons.update', { course: props.course.uuid, lesson: editLesson.value.id }); method = 'patch' }
  else { url = route('lms.admin.courses.modules.lessons.store', { course: props.course.uuid, module: lessonModuleId.value }); method = 'post' }
  router[method](url, lessonForm.value, { onFinish: () => { savingLesson.value = false }, onSuccess: () => { lessonDialog.value = false } })
}
function deleteLesson(lesson: Lesson) { deleteLessonTarget.value = { lesson, module: selectedModule.value! }; deleteLessonDialog.value = true }
function executeDeleteLesson() {
  if (!deleteLessonTarget.value) return
  router.delete(route('lms.admin.courses.lessons.destroy', { course: props.course.uuid, lesson: deleteLessonTarget.value.lesson.id }), {
    onSuccess: () => { if (selectedLesson.value?.id === deleteLessonTarget.value?.lesson.id) selectedLesson.value = null; deleteLessonDialog.value = false; deleteLessonTarget.value = null },
  })
}

const uploadDialog   = ref(false)
const uploadLesson   = ref<Lesson | null>(null)
const uploadFile     = ref<File | null>(null)
const uploadTitle    = ref('')
const uploading      = ref(false)
const uploadProgress = ref(0)
const fileInput      = ref<HTMLInputElement | null>(null)

function openUpload(lesson: Lesson) { uploadLesson.value = lesson; uploadFile.value = null; uploadTitle.value = ''; uploadProgress.value = 0; uploadDialog.value = true }
function onFileSelected(e: Event) { const f = (e.target as HTMLInputElement).files?.[0]; if (f) { uploadFile.value = f; if (!uploadTitle.value) uploadTitle.value = f.name } }
async function submitUpload() {
  if (!uploadFile.value || !uploadLesson.value) return; uploading.value = true
  const fd = new FormData(); fd.append('file', uploadFile.value); fd.append('title', uploadTitle.value || uploadFile.value.name); fd.append('_token', (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '')
  try { await axios.post(route('lms.admin.courses.lessons.materials.upload', { course: props.course.uuid, lesson: uploadLesson.value.id }), fd, { headers: { 'Content-Type': 'multipart/form-data' }, onUploadProgress: (e) => { if (e.total) uploadProgress.value = Math.round((e.loaded / e.total) * 100) } }); uploadDialog.value = false; router.reload({ only: ['course'] }) }
  catch {} finally { uploading.value = false }
}
function deleteMaterial(lesson: Lesson, mat: Material) { deleteMaterialTarget.value = { lesson, mat }; deleteMaterialDialog.value = true }
function executeDeleteMaterial() {
  if (!deleteMaterialTarget.value) return
  router.delete(route('lms.admin.courses.lessons.materials.destroy', { course: props.course.uuid, lesson: deleteMaterialTarget.value.lesson.id, material: deleteMaterialTarget.value.mat.id }), {
    onSuccess: () => { deleteMaterialDialog.value = false; deleteMaterialTarget.value = null },
  })
}

function formatBytes(mb: number) { if (mb < 1) return `${Math.round(mb * 1024)} KB`; return `${mb} MB` }

const addingPrereq  = ref(false)
const prereqCourseId = ref<number | null>(null)
const existingPrereqIds = computed(() => props.course.prerequisites.map(p => p.course.id))
const prereqOptions = computed(() => props.availableCourses.filter(c => !existingPrereqIds.value.includes(c.id)))

function addPrerequisite() {
  if (!prereqCourseId.value) return; addingPrereq.value = true
  router.post(route('lms.admin.courses.prerequisites.store', { course: props.course.uuid }), { prerequisite_course_id: prereqCourseId.value }, { onFinish: () => { addingPrereq.value = false }, onSuccess: () => { prereqCourseId.value = null } })
}
function removePrerequisite(prereq: Prerequisite) { router.delete(route('lms.admin.courses.prerequisites.destroy', { course: props.course.uuid, prerequisite: prereq.id })) }

const settingsForm = ref({
  title: props.course.title, description: props.course.description ?? '', type: props.course.type,
  duration_mins: props.course.duration_mins ?? 0, pass_score: props.course.pass_score ?? 70,
  expires_after_days: props.course.expires_after_days ?? null as number | null,
  is_mandatory: props.course.is_mandatory, is_published: props.course.is_published,
})
const savingSettings = ref(false)

function saveSettings() { savingSettings.value = true; router.patch(route('lms.admin.courses.settings', { course: props.course.uuid }), settingsForm.value, { onFinish: () => { savingSettings.value = false }, preserveScroll: true }) }
watch(() => props.course, (c) => {
  settingsForm.value.title = c.title; settingsForm.value.description = c.description ?? ''; settingsForm.value.type = c.type
  settingsForm.value.duration_mins = c.duration_mins ?? 0; settingsForm.value.pass_score = c.pass_score ?? 70
  settingsForm.value.expires_after_days = c.expires_after_days ?? null
  settingsForm.value.is_mandatory = c.is_mandatory; settingsForm.value.is_published = c.is_published
}, { deep: true })

const qctoForm = ref({
  nqf_level: props.course.nqf_level ?? null as number | null, credits: props.course.credits ?? null as number | null,
  saqa_id: props.course.saqa_id ?? '', unit_standard_code: props.course.unit_standard_code ?? '',
  ofo_code: props.course.ofo_code ?? '', seta_id: props.course.seta_id ?? null as number | null,
  seta_accreditation_number: props.course.seta_accreditation_number ?? '',
  seta_accreditation_expires_at: props.course.seta_accreditation_expires_at ?? '',
  qualification_component: props.course.qualification_component ?? null as string | null,
  is_qcto_registered: props.course.is_qcto_registered, is_accredited: props.course.is_accredited,
})
const savingQcto = ref(false)
function saveQcto() { savingQcto.value = true; router.patch(route('lms.admin.courses.settings', { course: props.course.uuid }), qctoForm.value, { onFinish: () => { savingQcto.value = false }, preserveScroll: true }) }

const nqfLevels = [1,2,3,4,5,6,7,8,9,10].map(n => ({ title: `NQF ${n}`, value: n }))
const qualificationComponents = [
  { title: t('lms_course_builder.qcto.qualification_component_k'), value: 'K' },
  { title: t('lms_course_builder.qcto.qualification_component_p'), value: 'P' },
  { title: t('lms_course_builder.qcto.qualification_component_we'), value: 'WE' },
  { title: t('lms_course_builder.qcto.qualification_component_sp'), value: 'SP' },
]

const totalLessons = computed(() => props.course.modules.reduce((s, m) => s + m.lessons.length, 0))
const totalMaterials = computed(() => props.course.modules.reduce((s, m) => s + m.lessons.reduce((ls, l) => ls + l.materials.length, 0), 0))

const courseTypeOptions = [
  { title: 'Video', value: 'video' }, { title: 'Document', value: 'document' },
  { title: 'Quiz', value: 'quiz' }, { title: 'SCORM', value: 'scorm' }, { title: 'External Link', value: 'external' },
]

const aiBuilderDialog = ref(false)
const aiStep = ref<'configure' | 'dispatching' | 'dispatched'>('configure')
const aiError = ref<string | null>(null)

const aiForm = ref({
  level: 'intermediate' as string, industry_context: '', tone: 'professional' as string,
  num_modules: 4, lessons_per_module: 3, include_quiz: true, quiz_count: 10,
  case_studies: false, language: 'English' as string, extra_objectives: '',
})

const aiLevelOptions = [
  { title: t('lms_course_builder.ai_dialog.levels.intern'), value: 'intern', icon: 'tabler-school', desc: t('lms_course_builder.ai_dialog.levels.intern_desc') },
  { title: t('lms_course_builder.ai_dialog.levels.junior'), value: 'junior', icon: 'tabler-user', desc: t('lms_course_builder.ai_dialog.levels.junior_desc') },
  { title: t('lms_course_builder.ai_dialog.levels.intermediate'), value: 'intermediate', icon: 'tabler-user-check', desc: t('lms_course_builder.ai_dialog.levels.intermediate_desc') },
  { title: t('lms_course_builder.ai_dialog.levels.senior'), value: 'senior', icon: 'tabler-star', desc: t('lms_course_builder.ai_dialog.levels.senior_desc') },
  { title: t('lms_course_builder.ai_dialog.levels.executive'), value: 'executive', icon: 'tabler-crown', desc: t('lms_course_builder.ai_dialog.levels.executive_desc') },
]
const aiToneOptions = [
  { title: t('lms_course_builder.ai_dialog.tones.professional'), value: 'professional', desc: t('lms_course_builder.ai_dialog.tones.professional_desc') },
  { title: t('lms_course_builder.ai_dialog.tones.conversational'), value: 'conversational', desc: t('lms_course_builder.ai_dialog.tones.conversational_desc') },
  { title: t('lms_course_builder.ai_dialog.tones.technical'), value: 'technical', desc: t('lms_course_builder.ai_dialog.tones.technical_desc') },
]
const aiLanguageOptions = ['English', 'Afrikaans', 'Zulu', 'Xhosa', 'Sesotho']
const aiIndustryOptions = ['Banking & Financial Services', 'Insurance', 'Investment Management', 'Accounting & Auditing', 'Healthcare & Pharmaceuticals', 'Mining & Resources', 'Oil & Gas', 'Manufacturing', 'Retail & Consumer Goods', 'Logistics & Supply Chain', 'Information Technology', 'Telecommunications', 'Construction & Engineering', 'Agriculture & Agribusiness', 'Education & Training', 'Legal & Compliance', 'Human Resources', 'Marketing & Advertising', 'Hospitality & Tourism', 'Public Sector & Government', 'Non-profit & NGO', 'Real Estate & Property', 'Energy & Utilities', 'Media & Entertainment', 'General Corporate']

function openAiBuilder() { aiStep.value = 'configure'; aiError.value = null; aiBuilderDialog.value = true }
async function runAiGenerate() {
  aiStep.value = 'dispatching'; aiError.value = null
  try { const token = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? ''; await axios.post(route('lms.admin.courses.ai.generate', { course: props.course.uuid }), { ...aiForm.value }, { headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' } }); aiStep.value = 'dispatched' }
  catch (err: any) { const data = err?.response?.data; aiError.value = data?.error ?? data?.message ?? (data?.errors ? Object.values(data.errors as Record<string, string[]>).flat().join(' ') : null) ?? `Request failed (${err?.response?.status ?? 'network error'})`; aiStep.value = 'configure' }
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">

    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-5 flex-wrap gap-3">
      <div>
        <div class="d-flex align-center gap-2 mb-1">
          <a class="text-medium-emphasis text-decoration-none text-body-2" href="#" @click.prevent="router.visit(route('lms.admin.courses'))">{{ t('lms_course_builder.breadcrumb_courses') }}</a>
          <VIcon icon="tabler-chevron-right" size="14" class="text-medium-emphasis" />
          <span class="text-body-2 font-weight-medium">{{ course.title }}</span>
        </div>
        <h4 class="text-h4">{{ t('lms_course_builder.title') }}</h4>
      </div>
      <div class="d-flex align-center gap-2 flex-wrap">
        <VChip :color="course.is_published ? 'success' : 'default'" size="small" variant="tonal">{{ course.is_published ? t('lms_course_builder.published') : t('lms_course_builder.draft') }}</VChip>
        <VChip v-if="course.is_mandatory" color="error" size="small" variant="tonal">{{ t('lms_course_builder.mandatory') }}</VChip>
        <VBtn color="secondary" variant="tonal" prepend-icon="tabler-sparkles" size="small" @click="openAiBuilder">{{ t('lms_course_builder.ai_course_builder') }}</VBtn>
        <VBtn color="primary" variant="tonal" prepend-icon="tabler-eye" size="small" @click="router.visit(route('lms.courses.show', { course: course.uuid }))">{{ t('lms_course_builder.preview') }}</VBtn>
      </div>
    </div>

    <!-- Stat chips -->
    <div class="d-flex gap-3 mb-5 flex-wrap">
      <VCard rounded="lg" elevation="0" border class="px-4 py-2"><div class="d-flex align-center gap-2"><VIcon icon="tabler-layout-list" size="18" color="primary" /><span class="text-body-2">{{ t('lms_course_builder.stat_modules', { count: course.modules.length }) }}</span></div></VCard>
      <VCard rounded="lg" elevation="0" border class="px-4 py-2"><div class="d-flex align-center gap-2"><VIcon icon="tabler-book" size="18" color="info" /><span class="text-body-2">{{ t('lms_course_builder.stat_lessons', { count: totalLessons }) }}</span></div></VCard>
      <VCard rounded="lg" elevation="0" border class="px-4 py-2"><div class="d-flex align-center gap-2"><VIcon icon="tabler-paperclip" size="18" color="success" /><span class="text-body-2">{{ t('lms_course_builder.stat_materials', { count: totalMaterials }) }}</span></div></VCard>
      <VCard rounded="lg" elevation="0" border class="px-4 py-2"><div class="d-flex align-center gap-2"><VIcon icon="tabler-lock" size="18" color="warning" /><span class="text-body-2">{{ t('lms_course_builder.stat_prerequisites', { count: course.prerequisites.length }) }}</span></div></VCard>
    </div>

    <!-- Tabs -->
    <VTabs v-model="tab" class="mb-5">
      <VTab value="builder" prepend-icon="tabler-layout-list">{{ t('lms_course_builder.tabs.builder') }}</VTab>
      <VTab value="prerequisites" prepend-icon="tabler-lock">{{ t('lms_course_builder.tabs.prerequisites') }}</VTab>
      <VTab value="settings" prepend-icon="tabler-settings">{{ t('lms_course_builder.tabs.settings') }}</VTab>
      <VTab value="qcto" prepend-icon="tabler-certificate">{{ t('lms_course_builder.tabs.qcto') }}</VTab>
    </VTabs>

    <VWindow v-model="tab">

      <!-- ── TAB 1: Module & Lesson Builder ───────────────────────────────── -->
      <VWindowItem value="builder">
        <VRow>
          <VCol cols="12" lg="5">
            <div class="d-flex align-center justify-space-between mb-3">
              <p class="text-body-1 font-weight-semibold mb-0">{{ t('lms_course_builder.builder.modules_title') }}</p>
              <VBtn size="small" color="primary" prepend-icon="tabler-plus" @click="openCreateModule">{{ t('lms_course_builder.builder.add_module') }}</VBtn>
            </div>
            <VCard v-if="!course.modules.length" rounded="lg" elevation="0" border>
              <VCardText class="text-center py-10">
                <VIcon icon="tabler-layout-list" size="48" color="secondary" class="mb-3" />
                <p class="text-body-2 text-medium-emphasis mb-3">{{ t('lms_course_builder.builder.no_modules') }}</p>
                <VBtn size="small" color="primary" @click="openCreateModule">{{ t('lms_course_builder.builder.add_first_module') }}</VBtn>
              </VCardText>
            </VCard>
            <div v-else class="d-flex flex-column gap-3">
              <VCard v-for="m in course.modules" :key="m.id" rounded="lg" elevation="0" border>
                <div class="d-flex align-center gap-2 pa-3 cursor-pointer" @click="toggleModule(m.id)">
                  <VIcon :icon="expandedModules.has(m.id) ? 'tabler-chevron-down' : 'tabler-chevron-right'" size="16" class="text-medium-emphasis flex-shrink-0" />
                  <VAvatar color="primary" variant="tonal" size="34" rounded><VIcon icon="tabler-layout-list" size="18" /></VAvatar>
                  <div class="flex-1-1 min-width-0">
                    <p class="text-body-2 font-weight-semibold mb-0 text-truncate">{{ m.title }}</p>
                    <p class="text-caption text-medium-emphasis mb-0">{{ m.lessons.length === 1 ? t('lms_course_builder.builder.lessons_count', { count: 1 }) : t('lms_course_builder.builder.lessons_count', { count: m.lessons.length }) }}</p>
                  </div>
                  <div class="d-flex gap-1 flex-shrink-0" @click.stop>
                    <VBtn icon="tabler-plus" size="x-small" variant="text" color="primary" :title="t('lms_course_builder.builder.add_one')" @click="openCreateLesson(m)" />
                    <VBtn icon="tabler-edit" size="x-small" variant="text" color="primary" @click="openEditModule(m)" />
                    <VBtn icon="tabler-trash" size="x-small" variant="text" color="error" @click="deleteModule(m)" />
                  </div>
                </div>
                <VExpandTransition>
                  <div v-if="expandedModules.has(m.id)">
                    <VDivider />
                    <div v-if="!m.lessons.length" class="pa-4 text-center text-caption text-medium-emphasis">
                      {{ t('lms_course_builder.builder.no_lessons') }}
                      <a href="#" class="text-primary" @click.prevent="openCreateLesson(m)">{{ t('lms_course_builder.builder.add_one') }}</a>
                    </div>
                    <VList density="compact" class="py-0">
                      <VListItem v-for="lesson in m.lessons" :key="lesson.id" :active="selectedLesson?.id === lesson.id" active-color="primary" rounded="0" class="border-b cursor-pointer" @click="selectLesson(m, lesson)">
                        <template #prepend><VAvatar :color="typeColor[lesson.type] ?? 'default'" variant="tonal" size="28" rounded><VIcon :icon="typeIcon[lesson.type] ?? 'tabler-file'" size="14" /></VAvatar></template>
                        <VListItemTitle class="text-body-2">{{ lesson.title }}</VListItemTitle>
                        <VListItemSubtitle class="text-caption">{{ lesson.type }}<span v-if="lesson.duration_mins"> · {{ lesson.duration_mins }}m</span><VChip v-if="lesson.is_preview" color="info" size="x-small" variant="tonal" class="ms-1">{{ t('lms_course_builder.builder.preview_badge') }}</VChip></VListItemSubtitle>
                        <template #append><div class="d-flex gap-1" @click.stop><VIcon v-if="lesson.materials.length" icon="tabler-paperclip" size="14" color="success" class="me-1" /><VBtn icon="tabler-upload" size="x-small" variant="text" color="success" :title="t('lms_course_builder.builder.upload_file')" @click="openUpload(lesson)" /><VBtn icon="tabler-edit" size="x-small" variant="text" color="primary" @click="openEditLesson(lesson)" /><VBtn icon="tabler-trash" size="x-small" variant="text" color="error" @click="deleteLesson(lesson)" /></div></template>
                      </VListItem>
                    </VList>
                  </div>
                </VExpandTransition>
              </VCard>
            </div>
          </VCol>

          <VCol cols="12" lg="7">
            <VCard v-if="!selectedLesson" rounded="lg" elevation="0" border>
              <VCardText class="text-center py-16">
                <VIcon icon="tabler-hand-click" size="48" color="secondary" class="mb-3" />
                <p class="text-body-1 text-medium-emphasis">{{ t('lms_course_builder.builder.select_lesson') }}</p>
              </VCardText>
            </VCard>
            <template v-else>
              <VCard rounded="lg" elevation="0" border class="mb-4">
                <VCardText class="pa-4">
                  <div class="d-flex align-center gap-3 mb-3">
                    <VAvatar :color="typeColor[selectedLesson.type] ?? 'default'" variant="tonal" size="44" rounded><VIcon :icon="typeIcon[selectedLesson.type] ?? 'tabler-file'" size="24" /></VAvatar>
                    <div class="flex-1-1"><p class="text-h6 mb-0">{{ selectedLesson.title }}</p><p class="text-caption text-medium-emphasis mb-0 text-capitalize">{{ selectedLesson.type }}<span v-if="selectedLesson.duration_mins"> · {{ selectedLesson.duration_mins }} min</span><VChip v-if="selectedLesson.is_preview" color="info" size="x-small" variant="tonal" class="ms-1">{{ t('lms_course_builder.builder.preview_badge') }}</VChip></p></div>
                    <VBtn size="small" variant="tonal" color="primary" prepend-icon="tabler-edit" @click="openEditLesson(selectedLesson)">{{ t('lms_course_builder.builder.edit') }}</VBtn>
                  </div>
                  <div v-if="selectedLesson.external_url" class="mb-3"><p class="text-caption text-medium-emphasis mb-1">{{ t('lms_course_builder.builder.external_url_label') }}</p><a :href="selectedLesson.external_url" target="_blank" class="text-body-2 text-primary">{{ selectedLesson.external_url }}</a></div>
                  <div v-if="selectedLesson.content_body" class="mb-0"><p class="text-caption text-medium-emphasis mb-1">{{ t('lms_course_builder.builder.content_label') }}</p><VCard elevation="0" variant="outlined" rounded="lg" class="pa-4"><!-- Rendered as the learner sees it; the same HTML the lesson player shows with v-html. --><div class="text-body-2 lesson-html-preview" v-html="selectedLesson.content_body" /></VCard></div>
                </VCardText>
              </VCard>
              <VCard rounded="lg" elevation="0" border>
                <div class="d-flex align-center justify-space-between pa-4 pb-2">
                  <p class="text-body-1 font-weight-semibold mb-0">{{ t('lms_course_builder.builder.materials_title') }}<VChip v-if="selectedLesson.materials.length" size="x-small" color="primary" variant="tonal" label class="ms-1">{{ selectedLesson.materials.length }}</VChip></p>
                  <VBtn size="small" color="success" prepend-icon="tabler-upload" @click="openUpload(selectedLesson)">{{ t('lms_course_builder.builder.upload_file') }}</VBtn>
                </div>
                <VDivider />
                <div v-if="!selectedLesson.materials.length" class="pa-6 text-center"><VIcon icon="tabler-paperclip" size="40" color="secondary" class="mb-2" /><p class="text-body-2 text-medium-emphasis mb-2">{{ t('lms_course_builder.builder.no_materials') }}</p><VBtn size="small" color="success" prepend-icon="tabler-upload" @click="openUpload(selectedLesson)">{{ t('lms_course_builder.builder.upload_first_file') }}</VBtn></div>
                <VList v-else density="compact">
                  <VListItem v-for="mat in selectedLesson.materials" :key="mat.id" :href="mat.url" target="_blank">
                    <template #prepend><VAvatar :color="typeColor[mat.type] ?? 'default'" variant="tonal" size="36" rounded><VIcon :icon="typeIcon[mat.type] ?? 'tabler-file'" size="18" /></VAvatar></template>
                    <VListItemTitle class="text-body-2 font-weight-medium">{{ mat.title }}</VListItemTitle>
                    <VListItemSubtitle class="text-caption">{{ mat.file_name }} · {{ formatBytes(mat.size_mb) }}</VListItemSubtitle>
                    <template #append><VBtn icon="tabler-trash" size="x-small" variant="text" color="error" @click.prevent="deleteMaterial(selectedLesson, mat)" /></template>
                  </VListItem>
                </VList>
              </VCard>
            </template>
          </VCol>
        </VRow>
      </VWindowItem>

      <!-- ── TAB 2: Prerequisites ──────────────────────────────────────────── -->
      <VWindowItem value="prerequisites">
        <VRow justify="center"><VCol cols="12" lg="7">
          <VCard rounded="lg" elevation="0" border class="mb-4"><VCardText class="pa-4">
            <p class="text-body-1 font-weight-semibold mb-1">{{ t('lms_course_builder.prerequisites.add_title') }}</p>
            <p class="text-body-2 text-medium-emphasis mb-3">{{ t('lms_course_builder.prerequisites.add_body') }}</p>
            <div class="d-flex gap-2 align-center">
              <VAutocomplete v-model="prereqCourseId" :items="prereqOptions" item-title="title" item-value="id" :label="t('lms_course_builder.prerequisites.select_course')" variant="outlined" density="compact" hide-details clearable class="flex-1-1" />
              <VBtn color="primary" :loading="addingPrereq" :disabled="!prereqCourseId" prepend-icon="tabler-plus" @click="addPrerequisite">{{ t('lms_course_builder.prerequisites.add') }}</VBtn>
            </div>
          </VCardText></VCard>
          <VCard v-if="!course.prerequisites.length" rounded="lg" elevation="0" border><VCardText class="text-center py-10"><VIcon icon="tabler-lock-open" size="48" color="secondary" class="mb-3" /><p class="text-body-1 text-medium-emphasis">{{ t('lms_course_builder.prerequisites.no_prerequisites') }}</p></VCardText></VCard>
          <VCard v-else rounded="lg" elevation="0" border>
            <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">{{ t('lms_course_builder.prerequisites.required_before', { count: course.prerequisites.length }) }}</VCardTitle>
            <VDivider />
            <VList><VListItem v-for="prereq in course.prerequisites" :key="prereq.id">
              <template #prepend><VAvatar color="warning" variant="tonal" size="36" rounded><VIcon icon="tabler-lock" size="18" /></VAvatar></template>
              <VListItemTitle class="text-body-2 font-weight-medium">{{ prereq.course.title }}</VListItemTitle>
              <VListItemSubtitle class="text-caption text-capitalize">{{ prereq.course.type }}</VListItemSubtitle>
              <template #append><VBtn size="small" variant="tonal" color="error" @click="removePrerequisite(prereq)"><VIcon icon="tabler-trash" size="16" /></VBtn></template>
            </VListItem></VList>
          </VCard>
        </VCol></VRow>
      </VWindowItem>

      <!-- ── TAB 3: Settings ───────────────────────────────────────────────── -->
      <VWindowItem value="settings">
        <VRow justify="center"><VCol cols="12" lg="7">
          <VCard rounded="lg" elevation="0" border><VCardText class="pa-5">
            <p class="text-body-1 font-weight-semibold mb-4">{{ t('lms_course_builder.settings.title') }}</p>
            <VRow dense>
              <VCol cols="12"><VTextField v-model="settingsForm.title" :label="t('lms_course_builder.settings.course_title_label')" variant="outlined" density="compact" /></VCol>
              <VCol cols="12"><VTextarea v-model="settingsForm.description" :label="t('lms_course_builder.settings.description_label')" variant="outlined" density="compact" rows="3" /></VCol>
              <VCol cols="12" sm="6"><VSelect v-model="settingsForm.type" :items="courseTypeOptions" item-title="title" item-value="value" :label="t('lms_course_builder.settings.course_type_label')" variant="outlined" density="compact" /></VCol>
              <VCol cols="12" sm="6"><VTextField v-model.number="settingsForm.duration_mins" :label="t('lms_course_builder.settings.duration_label')" type="number" variant="outlined" density="compact" :hint="t('lms_course_builder.settings.duration_hint')" persistent-hint /></VCol>
              <VCol cols="12"><p class="text-body-2 font-weight-medium mb-2">{{ t('lms_course_builder.settings.pass_mark_label') }}</p><div class="d-flex align-center gap-3"><VSlider v-model="settingsForm.pass_score" :min="0" :max="100" :step="5" color="success" thumb-label class="flex-1-1" /><div class="text-h6 font-weight-bold" style="min-width: 48px;">{{ settingsForm.pass_score }}%</div></div></VCol>
              <VCol cols="12" sm="6"><VTextField v-model.number="settingsForm.expires_after_days" :label="t('lms_course_builder.settings.expiry_label')" type="number" variant="outlined" density="compact" clearable :hint="t('lms_course_builder.settings.expiry_hint')" persistent-hint :min="1" /></VCol>
              <VCol cols="12" sm="6" class="d-flex align-center gap-4"><VSwitch v-model="settingsForm.is_mandatory" :label="t('lms_course_builder.settings.mandatory_label')" color="error" hide-details /><VSwitch v-model="settingsForm.is_published" :label="t('lms_course_builder.settings.published_label')" color="success" hide-details /></VCol>
            </VRow>
          </VCardText><VDivider /><VCardActions class="pa-4"><VSpacer /><VBtn color="primary" :loading="savingSettings" prepend-icon="ri-save-line" @click="saveSettings">{{ t('lms_course_builder.settings.save_settings') }}</VBtn></VCardActions></VCard>
        </VCol></VRow>
      </VWindowItem>

      <!-- ── TAB 4: QCTO / SETA ──────────────────────────────────────────────── -->
      <VWindowItem value="qcto">
        <VRow justify="center"><VCol cols="12" lg="7">
          <VCard rounded="lg" elevation="0" border class="mb-4"><VCardText class="pa-5">
            <div class="d-flex align-center gap-2 mb-4"><VAvatar color="primary" variant="tonal" size="36" rounded><VIcon icon="tabler-certificate" size="20" /></VAvatar><div><p class="text-body-1 font-weight-semibold mb-0">{{ t('lms_course_builder.qcto.title') }}</p><p class="text-caption text-medium-emphasis mb-0">{{ t('lms_course_builder.qcto.subtitle') }}</p></div></div>
            <VRow dense>
              <VCol cols="12" class="d-flex gap-4 align-center mb-2"><VSwitch v-model="qctoForm.is_qcto_registered" :label="t('lms_course_builder.qcto.qcto_registered')" color="primary" hide-details density="compact" /><VSwitch v-model="qctoForm.is_accredited" :label="t('lms_course_builder.qcto.seta_accredited')" color="success" hide-details density="compact" /></VCol>
              <VCol cols="6"><VSelect v-model="qctoForm.nqf_level" :label="t('lms_course_builder.qcto.nqf_level')" :items="nqfLevels" clearable density="compact" variant="outlined" :hint="t('lms_course_builder.qcto.nqf_hint')" persistent-hint /></VCol>
              <VCol cols="6"><VTextField v-model.number="qctoForm.credits" :label="t('lms_course_builder.qcto.credits')" type="number" min="1" density="compact" variant="outlined" :hint="t('lms_course_builder.qcto.credits_hint')" persistent-hint /></VCol>
              <VCol cols="6"><VTextField v-model="qctoForm.saqa_id" :label="t('lms_course_builder.qcto.saqa_id')" density="compact" variant="outlined" :hint="t('lms_course_builder.qcto.saqa_hint')" persistent-hint /></VCol>
              <VCol cols="6"><VTextField v-model="qctoForm.unit_standard_code" :label="t('lms_course_builder.qcto.unit_standard_code')" density="compact" variant="outlined" /></VCol>
              <VCol cols="6"><VTextField v-model="qctoForm.ofo_code" :label="t('lms_course_builder.qcto.ofo_code')" density="compact" variant="outlined" :hint="t('lms_course_builder.qcto.ofo_hint')" persistent-hint /></VCol>
              <VCol cols="6"><VSelect v-model="qctoForm.qualification_component" :label="t('lms_course_builder.qcto.qualification_component')" :items="qualificationComponents" clearable density="compact" variant="outlined" :hint="t('lms_course_builder.qcto.qualification_hint')" persistent-hint /></VCol>
              <VCol cols="12"><VDivider class="my-3" /><p class="text-body-2 font-weight-semibold mb-3">{{ t('lms_course_builder.qcto.seta_accreditation_title') }}</p></VCol>
              <VCol cols="12"><VSelect v-model="qctoForm.seta_id" :label="t('lms_course_builder.qcto.seta_label')" :items="(setas ?? []).map(s => ({ title: `${s.acronym} — ${s.name}`, value: s.id }))" clearable density="compact" variant="outlined" :hint="t('lms_course_builder.qcto.seta_hint')" persistent-hint /></VCol>
              <VCol cols="6"><VTextField v-model="qctoForm.seta_accreditation_number" :label="t('lms_course_builder.qcto.accreditation_number')" density="compact" variant="outlined" /></VCol>
              <VCol cols="6"><VTextField v-model="qctoForm.seta_accreditation_expires_at" :label="t('lms_course_builder.qcto.accreditation_expiry')" type="date" density="compact" variant="outlined" /></VCol>
            </VRow>
          </VCardText><VDivider /><VCardActions class="pa-4"><VSpacer /><VBtn color="primary" :loading="savingQcto" prepend-icon="ri-save-line" @click="saveQcto">{{ t('lms_course_builder.qcto.save_qcto') }}</VBtn></VCardActions></VCard>
          <VAlert type="info" variant="tonal" density="compact">{{ t('lms_course_builder.qcto.bbbee_tip') }}</VAlert>
        </VCol></VRow>
      </VWindowItem>

    </VWindow>

    <!-- ── Delete Module Dialog ──────────────────────────────────────────── -->
    <VDialog v-model="deleteModuleDialog" max-width="420" persistent>
      <VCard rounded="lg">
        <VCardText class="pa-6 text-center"><VAvatar color="error" variant="tonal" size="56" class="mb-4"><VIcon icon="tabler-trash" size="28" /></VAvatar><h6 class="text-h6 font-weight-semibold mb-2">{{ t('lms_course_builder.builder.delete_module_title') }}</h6><p class="text-body-2 text-medium-emphasis mb-0">{{ t('lms_course_builder.builder.delete_module_body', { title: deleteModuleTarget?.title }) }}</p></VCardText>
        <VDivider />
        <VCardActions class="pa-4 gap-2 justify-end"><VBtn variant="tonal" color="secondary" @click="deleteModuleDialog = false">{{ t('lms_course_builder.builder.cancel') }}</VBtn><VBtn color="error" variant="tonal" @click="executeDeleteModule">{{ t('lms_course_builder.builder.delete') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Delete Lesson Dialog ───────────────────────────────────────────── -->
    <VDialog v-model="deleteLessonDialog" max-width="420" persistent>
      <VCard rounded="lg">
        <VCardText class="pa-6 text-center"><VAvatar color="error" variant="tonal" size="56" class="mb-4"><VIcon icon="tabler-trash" size="28" /></VAvatar><h6 class="text-h6 font-weight-semibold mb-2">{{ t('lms_course_builder.builder.delete_lesson_title') }}</h6><p class="text-body-2 text-medium-emphasis mb-0">{{ t('lms_course_builder.builder.delete_lesson_body', { title: deleteLessonTarget?.lesson.title }) }}</p></VCardText>
        <VDivider />
        <VCardActions class="pa-4 gap-2 justify-end"><VBtn variant="tonal" color="secondary" @click="deleteLessonDialog = false">{{ t('lms_course_builder.builder.cancel') }}</VBtn><VBtn color="error" variant="tonal" @click="executeDeleteLesson">{{ t('lms_course_builder.builder.delete') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Delete Material Dialog ─────────────────────────────────────────── -->
    <VDialog v-model="deleteMaterialDialog" max-width="420" persistent>
      <VCard rounded="lg">
        <VCardText class="pa-6 text-center"><VAvatar color="error" variant="tonal" size="56" class="mb-4"><VIcon icon="tabler-trash" size="28" /></VAvatar><h6 class="text-h6 font-weight-semibold mb-2">{{ t('lms_course_builder.builder.delete_file_title') }}</h6><p class="text-body-2 text-medium-emphasis mb-0">{{ t('lms_course_builder.builder.delete_file_body', { name: deleteMaterialTarget?.mat.file_name }) }}</p></VCardText>
        <VDivider />
        <VCardActions class="pa-4 gap-2 justify-end"><VBtn variant="tonal" color="secondary" @click="deleteMaterialDialog = false">{{ t('lms_course_builder.builder.cancel') }}</VBtn><VBtn color="error" variant="tonal" @click="executeDeleteMaterial">{{ t('lms_course_builder.builder.delete') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Module Dialog ─────────────────────────────────────────────────── -->
    <VDialog v-model="moduleDialog" max-width="480" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-h6">{{ editModule ? t('lms_course_builder.module_dialog.edit_title') : t('lms_course_builder.module_dialog.new_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12"><VTextField v-model="moduleForm.title" :label="t('lms_course_builder.module_dialog.title_label')" variant="outlined" density="compact" autofocus /></VCol>
            <VCol cols="12"><VTextarea v-model="moduleForm.description" :label="t('lms_course_builder.module_dialog.description_label')" variant="outlined" density="compact" rows="2" /></VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="moduleDialog = false">{{ t('lms_course_builder.module_dialog.cancel') }}</VBtn><VBtn color="primary" :loading="savingModule" @click="submitModule">{{ editModule ? t('lms_course_builder.module_dialog.save') : t('lms_course_builder.module_dialog.create') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Lesson Dialog ─────────────────────────────────────────────────── -->
    <VDialog v-model="lessonDialog" max-width="600" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-h6">{{ editLesson ? t('lms_course_builder.lesson_dialog.edit_title') : t('lms_course_builder.lesson_dialog.new_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12" sm="8"><VTextField v-model="lessonForm.title" :label="t('lms_course_builder.lesson_dialog.title_label')" variant="outlined" density="compact" /></VCol>
            <VCol cols="12" sm="4"><VSelect v-model="lessonForm.type" :items="lessonTypeOptions" item-title="title" item-value="value" :label="t('lms_course_builder.lesson_dialog.type_label')" variant="outlined" density="compact" /></VCol>
            <VCol v-if="lessonForm.type === 'text'" cols="12"><VTextarea v-model="lessonForm.content_body" :label="t('lms_course_builder.lesson_dialog.content_label')" variant="outlined" density="compact" rows="5" :hint="t('lms_course_builder.lesson_dialog.content_hint')" persistent-hint /></VCol>
            <VCol v-if="['link', 'video', 'pdf', 'document', 'presentation'].includes(lessonForm.type)" cols="12"><VTextField v-model="lessonForm.external_url" :label="t('lms_course_builder.lesson_dialog.url_label')" variant="outlined" density="compact" placeholder="https://" :hint="t('lms_course_builder.lesson_dialog.url_hint')" persistent-hint /></VCol>
            <VCol cols="6"><VTextField v-model.number="lessonForm.duration_mins" :label="t('lms_course_builder.lesson_dialog.duration_label')" type="number" variant="outlined" density="compact" :min="0" /></VCol>
            <VCol cols="6" class="d-flex align-center"><VSwitch v-model="lessonForm.is_preview" :label="t('lms_course_builder.lesson_dialog.preview_label')" color="info" hide-details density="compact" /></VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="lessonDialog = false">{{ t('lms_course_builder.lesson_dialog.cancel') }}</VBtn><VBtn color="primary" :loading="savingLesson" @click="submitLesson">{{ editLesson ? t('lms_course_builder.lesson_dialog.save_changes') : t('lms_course_builder.lesson_dialog.create_lesson') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- ── AI Course Builder Dialog ─────────────────────────────────────── -->
    <VDialog v-model="aiBuilderDialog" max-width="680" persistent scrollable>
      <VCard rounded="lg">
        <VCardTitle class="pa-5 pb-3 d-flex align-center gap-3">
          <VAvatar color="secondary" variant="tonal" size="40" rounded><VIcon icon="tabler-sparkles" size="22" /></VAvatar>
          <div class="flex-1-1"><p class="text-h6 mb-0">{{ t('lms_course_builder.ai_dialog.title') }}</p><p class="text-caption text-medium-emphasis mb-0"><span v-if="aiStep === 'configure'">{{ t('lms_course_builder.ai_dialog.step_configure') }}</span><span v-else-if="aiStep === 'dispatching'">{{ t('lms_course_builder.ai_dialog.step_dispatching') }}</span><span v-else>{{ t('lms_course_builder.ai_dialog.step_dispatched') }}</span></p></div>
          <VBtn v-if="aiStep !== 'dispatching'" icon="tabler-x" variant="text" size="small" @click="aiBuilderDialog = false" />
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-5" style="min-height: 320px;">
          <template v-if="aiStep === 'configure'">
            <VAlert v-if="aiError" type="error" variant="tonal" class="mb-4" closable @click:close="aiError = null">{{ aiError }}</VAlert>
            <VRow dense>
              <VCol cols="12"><p class="text-body-2 font-weight-semibold mb-2">{{ t('lms_course_builder.ai_dialog.target_level') }}</p><div class="d-flex flex-wrap gap-2"><VChip v-for="lvl in aiLevelOptions" :key="lvl.value" :color="aiForm.level === lvl.value ? 'primary' : 'default'" :variant="aiForm.level === lvl.value ? 'elevated' : 'tonal'" :prepend-icon="lvl.icon" size="small" label class="cursor-pointer" @click="aiForm.level = lvl.value">{{ lvl.title }}</VChip></div><p class="text-caption text-medium-emphasis mt-1">{{ aiLevelOptions.find(l => l.value === aiForm.level)?.desc }}</p></VCol>
              <VCol cols="12"><VAutocomplete v-model="aiForm.industry_context" :items="aiIndustryOptions" :label="t('lms_course_builder.ai_dialog.industry_context')" variant="outlined" density="compact" :placeholder="t('lms_course_builder.ai_dialog.industry_context')" :hint="t('lms_course_builder.ai_dialog.industry_hint')" persistent-hint clearable /></VCol>
              <VCol cols="12" sm="6"><VSelect v-model="aiForm.tone" :items="aiToneOptions" item-title="title" item-value="value" :label="t('lms_course_builder.ai_dialog.content_tone')" variant="outlined" density="compact" /></VCol>
              <VCol cols="12" sm="6"><VSelect v-model="aiForm.language" :items="aiLanguageOptions" :label="t('lms_course_builder.ai_dialog.language')" variant="outlined" density="compact" /></VCol>
              <VCol cols="12" sm="6"><p class="text-body-2 font-weight-medium mb-1">{{ t('lms_course_builder.ai_dialog.modules') }} <strong>{{ aiForm.num_modules }}</strong></p><VSlider v-model="aiForm.num_modules" :min="2" :max="8" :step="1" color="primary" thumb-label density="compact" /></VCol>
              <VCol cols="12" sm="6"><p class="text-body-2 font-weight-medium mb-1">{{ t('lms_course_builder.ai_dialog.lessons_per_module') }} <strong>{{ aiForm.lessons_per_module }}</strong></p><VSlider v-model="aiForm.lessons_per_module" :min="2" :max="6" :step="1" color="info" thumb-label density="compact" /></VCol>
              <VCol cols="12"><div class="d-flex align-center gap-4 flex-wrap"><VSwitch v-model="aiForm.include_quiz" :label="t('lms_course_builder.ai_dialog.include_quiz')" color="success" hide-details density="compact" /><div v-if="aiForm.include_quiz" class="d-flex align-center gap-2 flex-1-1" style="min-width: 200px;"><p class="text-body-2 mb-0 text-no-wrap">{{ t('lms_course_builder.ai_dialog.questions') }} <strong>{{ aiForm.quiz_count }}</strong></p><VSlider v-model="aiForm.quiz_count" :min="3" :max="30" :step="1" color="success" thumb-label class="flex-1-1" density="compact" hide-details /></div></div></VCol>
              <VCol cols="12"><VSwitch v-model="aiForm.case_studies" :label="t('lms_course_builder.ai_dialog.case_studies')" color="warning" hide-details density="compact" /></VCol>
              <VCol cols="12"><VTextarea v-model="aiForm.extra_objectives" :label="t('lms_course_builder.ai_dialog.extra_objectives')" variant="outlined" density="compact" rows="2" :placeholder="t('lms_course_builder.ai_dialog.extra_hint')" :hint="t('lms_course_builder.ai_dialog.extra_hint')" persistent-hint /></VCol>
              <VCol cols="12"><VCard elevation="0" color="surface-variant" rounded="lg" class="pa-3"><div class="d-flex align-center gap-3 flex-wrap"><div class="d-flex align-center gap-1"><VIcon icon="tabler-layout-list" size="16" color="primary" /><span class="text-caption font-weight-semibold">{{ t('lms_course_builder.ai_dialog.estimate_modules', { count: aiForm.num_modules }) }}</span></div><div class="d-flex align-center gap-1"><VIcon icon="tabler-book" size="16" color="info" /><span class="text-caption font-weight-semibold">{{ t('lms_course_builder.ai_dialog.estimate_lessons', { count: aiForm.num_modules * (aiForm.lessons_per_module + (aiForm.case_studies ? 1 : 0)) }) }}</span></div><div v-if="aiForm.include_quiz" class="d-flex align-center gap-1"><VIcon icon="tabler-checklist" size="16" color="success" /><span class="text-caption font-weight-semibold">{{ t('lms_course_builder.ai_dialog.estimate_questions', { count: aiForm.quiz_count }) }}</span></div><VChip size="x-small" color="secondary" variant="tonal" class="ms-auto">{{ aiForm.level }} · {{ aiForm.language }}</VChip></div></VCard></VCol>
            </VRow>
          </template>
          <template v-else-if="aiStep === 'dispatching'"><div class="d-flex flex-column align-center justify-center" style="min-height: 260px;"><VProgressCircular indeterminate color="secondary" size="56" class="mb-4" /><p class="text-body-2 text-medium-emphasis">{{ t('lms_course_builder.ai_dialog.submitting') }}</p></div></template>
          <template v-else-if="aiStep === 'dispatched'"><div class="d-flex flex-column align-center justify-center text-center" style="min-height: 260px;"><VAvatar color="success" variant="tonal" size="72" class="mb-5"><VIcon icon="tabler-circle-check" size="38" /></VAvatar><h6 class="text-h6 font-weight-semibold mb-2">{{ t('lms_course_builder.ai_dialog.dispatched_title') }}</h6><p class="text-body-2 text-medium-emphasis mb-5" style="max-width: 380px;">{{ t('lms_course_builder.ai_dialog.dispatched_body') }}</p></div></template>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4 gap-2">
          <VSpacer />
          <VBtn v-if="aiStep === 'configure'" variant="text" @click="aiBuilderDialog = false">{{ t('lms_course_builder.ai_dialog.cancel') }}</VBtn>
          <VBtn v-if="aiStep === 'configure'" color="secondary" prepend-icon="tabler-sparkles" :loading="aiStep === 'dispatching'" @click="runAiGenerate">{{ t('lms_course_builder.ai_dialog.generate') }}</VBtn>
          <VBtn v-if="aiStep === 'dispatched'" color="primary" @click="aiBuilderDialog = false">{{ t('lms_course_builder.ai_dialog.close') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Upload Dialog ─────────────────────────────────────────────────── -->
    <VDialog v-model="uploadDialog" max-width="520" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-h6">{{ t('lms_course_builder.upload_dialog.title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <p class="text-body-2 text-medium-emphasis mb-4">{{ t('lms_course_builder.upload_dialog.supported') }}</p>
          <VCard elevation="0" rounded="lg" :color="uploadFile ? 'success' : 'surface-variant'" class="mb-4 cursor-pointer upload-zone d-flex flex-column align-center justify-center pa-8" @click="fileInput?.click()">
            <VIcon :icon="uploadFile ? 'tabler-circle-check' : 'tabler-cloud-upload'" :color="uploadFile ? 'success' : 'medium-emphasis'" size="40" class="mb-2" />
            <p class="text-body-2 text-center mb-0"><span v-if="!uploadFile" class="text-medium-emphasis">{{ t('lms_course_builder.upload_dialog.drop_browse') }}</span><span v-else class="font-weight-medium text-success">{{ uploadFile.name }}</span></p>
            <p v-if="uploadFile" class="text-caption text-medium-emphasis mb-0">{{ (uploadFile.size / 1024 / 1024).toFixed(2) }} MB</p>
          </VCard>
          <input ref="fileInput" type="file" class="d-none" accept="video/*,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-powerpoint,application/vnd.openxmlformats-officedocument.presentationml.presentation,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" @change="onFileSelected" />
          <VTextField v-model="uploadTitle" :label="t('lms_course_builder.upload_dialog.title_label')" variant="outlined" density="compact" class="mb-3" :hint="t('lms_course_builder.upload_dialog.title_hint')" persistent-hint />
          <VProgressLinear v-if="uploading" :model-value="uploadProgress" color="primary" rounded height="6" class="mt-2" />
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" :disabled="uploading" @click="uploadDialog = false">{{ t('lms_course_builder.upload_dialog.cancel') }}</VBtn><VBtn color="primary" :loading="uploading" :disabled="!uploadFile" prepend-icon="tabler-upload" @click="submitUpload">{{ t('lms_course_builder.upload_dialog.upload') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

  </VContainer>
</template>

<style scoped>
.lesson-html-preview { max-height: 420px; overflow-y: auto; }
.lesson-html-preview :deep(h3) { font-size: 1.05rem; font-weight: 600; margin: 0 0 .5rem; }
.lesson-html-preview :deep(h4) { font-size: .95rem; font-weight: 600; margin: .75rem 0 .35rem; }
.lesson-html-preview :deep(p), .lesson-html-preview :deep(ul), .lesson-html-preview :deep(ol) { margin: 0 0 .6rem; }
.lesson-html-preview :deep(ul), .lesson-html-preview :deep(ol) { padding-left: 1.25rem; }
.lesson-body { white-space: pre-wrap; max-height: 180px; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 6; -webkit-box-orient: vertical; }
.upload-zone { border: 2px dashed rgba(var(--v-border-color), var(--v-border-opacity)); min-height: 140px; transition: border-color 0.2s; }
.upload-zone:hover { border-color: rgb(var(--v-theme-primary)); }
@keyframes ai-pulse { 0%, 100% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.08); opacity: 0.85; } }
.ai-pulse { animation: ai-pulse 1.8s ease-in-out infinite; }
</style>
