<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Course = { id: number; title: string; type: string; duration_mins: number | null; is_mandatory: boolean }
type LearningPath = { id: number; uuid: string; title: string; description: string | null; is_mandatory: boolean; is_published: boolean; path_courses_count: number }

const props = defineProps<{ paths: LearningPath[]; courses: Course[] }>()

const dialog       = ref(false)
const editTarget   = ref<LearningPath | null>(null)
const submitting   = ref(false)
const deleteDialog = ref(false)
const deleteTarget = ref<LearningPath | null>(null)
const deleting     = ref(false)

const form = ref({ title: '', description: '', is_mandatory: false, is_published: false })

function openCreate() { editTarget.value = null; form.value = { title: '', description: '', is_mandatory: false, is_published: false }; dialog.value = true }
function openEdit(path: LearningPath) { editTarget.value = path; form.value = { title: path.title, description: path.description ?? '', is_mandatory: path.is_mandatory, is_published: path.is_published }; dialog.value = true }
function submitForm() { submitting.value = true; const url = editTarget.value ? route('lms.admin.learning-paths.update', { path: editTarget.value.uuid }) : route('lms.admin.learning-paths.store'); const method = editTarget.value ? 'patch' : 'post'; router[method](url, form.value, { onFinish: () => { submitting.value = false }, onSuccess: () => { dialog.value = false } }) }
function togglePublish(path: LearningPath) { router.patch(route('lms.admin.learning-paths.publish', { path: path.uuid })) }
function confirmDeletePath(path: LearningPath) { deleteTarget.value = path; deleteDialog.value = true }
function executeDeletePath() { if (!deleteTarget.value) return; deleting.value = true; router.delete(route('lms.admin.learning-paths.destroy', { path: deleteTarget.value.uuid }), { onFinish: () => { deleting.value = false; deleteDialog.value = false; deleteTarget.value = null } }) }

const drawer        = ref(false)
const activePath    = ref<LearningPath | null>(null)
const addingCourse  = ref(false)
const selectedCourse = ref<number | null>(null)

function openCourseDrawer(path: LearningPath) { activePath.value = path; selectedCourse.value = null; drawer.value = true }
function addCourse() { if (!activePath.value || !selectedCourse.value) return; addingCourse.value = true; router.post(route('lms.admin.learning-paths.courses.add', { path: activePath.value.uuid }), { course_id: selectedCourse.value, is_required: true }, { onFinish: () => { addingCourse.value = false }, onSuccess: () => { selectedCourse.value = null } }) }

const typeColor: Record<string, string> = { video: 'primary', document: 'secondary', quiz: 'success', scorm: 'warning', external: 'info' }
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div><h4 class="text-h4">{{ t('lms_learning_paths.title') }}</h4><p class="text-medium-emphasis mb-0">{{ t('lms_learning_paths.subtitle') }}</p></div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">{{ t('lms_learning_paths.new_path') }}</VBtn>
    </div>

    <VRow v-if="paths.length">
      <VCol v-for="path in paths" :key="path.id" cols="12" sm="6" lg="4">
        <VCard rounded="lg" elevation="0" border hover class="h-100 d-flex flex-column">
          <VCardText class="pa-4 flex-grow-1">
            <div class="d-flex align-center gap-2 mb-3">
              <VAvatar color="primary" variant="tonal" size="44" rounded><VIcon icon="tabler-route" size="22" /></VAvatar>
              <div class="flex-1-1"><p class="text-body-1 font-weight-semibold mb-0 text-truncate">{{ path.title }}</p><p class="text-caption text-medium-emphasis mb-0">{{ path.path_courses_count === 1 ? t('lms_learning_paths.card.courses_count', { count: 1 }) : t('lms_learning_paths.card.courses_count', { count: path.path_courses_count }) }}</p></div>
            </div>
            <p v-if="path.description" class="text-body-2 text-medium-emphasis mb-3 lp-desc">{{ path.description }}</p>
            <div class="d-flex gap-1 flex-wrap">
              <VChip v-if="path.is_mandatory" color="error" size="x-small" variant="tonal">{{ t('lms_learning_paths.card.required') }}</VChip>
              <VChip :color="path.is_published ? 'success' : 'default'" size="x-small" variant="tonal">{{ path.is_published ? t('lms_learning_paths.card.published') : t('lms_learning_paths.card.draft') }}</VChip>
            </div>
          </VCardText>
          <VDivider />
          <VCardActions class="pa-2">
            <VBtn size="small" variant="text" color="primary" prepend-icon="tabler-list" @click="openCourseDrawer(path)">{{ t('lms_learning_paths.card.courses_button') }}</VBtn>
            <VSpacer />
            <VBtn :icon="path.is_published ? 'tabler-eye-off' : 'tabler-eye'" size="small" variant="text" :color="path.is_published ? 'warning' : 'success'" :title="path.is_published ? 'Unpublish' : 'Publish'" @click="togglePublish(path)" />
            <VBtn icon="tabler-edit" size="small" variant="text" color="primary" @click="openEdit(path)" />
            <VBtn icon="tabler-trash" size="small" variant="text" color="error" @click="confirmDeletePath(path)" />
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>

    <VCard v-else rounded="lg" elevation="0" border>
      <VCardText class="text-center py-12">
        <VIcon icon="tabler-route" size="56" color="secondary" class="mb-3" />
        <p class="text-body-1 text-medium-emphasis mb-4">{{ t('lms_learning_paths.empty.text') }}</p>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">{{ t('lms_learning_paths.empty.button') }}</VBtn>
      </VCardText>
    </VCard>

    <VDialog v-model="dialog" max-width="520" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-h6">{{ editTarget ? t('lms_learning_paths.dialog.title_edit') : t('lms_learning_paths.dialog.title_new') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12"><VTextField v-model="form.title" :label="t('lms_learning_paths.dialog.title_label')" variant="outlined" density="compact" /></VCol>
            <VCol cols="12"><VTextarea v-model="form.description" :label="t('lms_learning_paths.dialog.description_label')" variant="outlined" density="compact" rows="3" /></VCol>
            <VCol cols="6"><VSwitch v-model="form.is_mandatory" :label="t('lms_learning_paths.dialog.mandatory_label')" color="error" hide-details /></VCol>
            <VCol cols="6"><VSwitch v-model="form.is_published" :label="t('lms_learning_paths.dialog.published_label')" color="success" hide-details /></VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('lms_learning_paths.dialog.cancel') }}</VBtn><VBtn color="primary" :loading="submitting" @click="submitForm">{{ editTarget ? t('lms_learning_paths.dialog.save_changes') : t('lms_learning_paths.dialog.create_path') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="deleteDialog" max-width="420" persistent>
      <VCard rounded="lg">
        <VCardText class="pa-6 text-center"><VAvatar color="error" variant="tonal" size="56" class="mb-4"><VIcon icon="tabler-trash" size="28" /></VAvatar><h6 class="text-h6 font-weight-semibold mb-2">{{ t('lms_learning_paths.delete_dialog.title') }}</h6><p class="text-body-2 text-medium-emphasis mb-0">{{ t('lms_learning_paths.delete_dialog.body', { title: deleteTarget?.title }) }}</p></VCardText>
        <VDivider />
        <VCardActions class="pa-4 gap-2 justify-end"><VBtn variant="tonal" color="secondary" @click="deleteDialog = false">{{ t('lms_learning_paths.delete_dialog.cancel') }}</VBtn><VBtn color="error" variant="tonal" :loading="deleting" @click="executeDeletePath">{{ t('lms_learning_paths.delete_dialog.delete') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VNavigationDrawer v-model="drawer" location="right" width="420" temporary>
      <div class="pa-4 d-flex align-center justify-space-between">
        <div><p class="text-body-1 font-weight-semibold mb-0">{{ activePath?.title }}</p><p class="text-caption text-medium-emphasis mb-0">{{ t('lms_learning_paths.drawer.title') }}</p></div>
        <VBtn icon="tabler-x" size="small" variant="text" @click="drawer = false" />
      </div>
      <VDivider />
      <div class="pa-4">
        <p class="text-body-2 font-weight-medium mb-3">{{ t('lms_learning_paths.drawer.add_title') }}</p>
        <VAutocomplete v-model="selectedCourse" :items="props.courses" item-title="title" item-value="id" :label="t('lms_learning_paths.drawer.select_course')" variant="outlined" density="compact" clearable class="mb-3">
          <template #item="{ item, props: itemProps }"><VListItem v-bind="itemProps"><template #append><VChip :color="typeColor[item.raw.type] ?? 'default'" size="x-small" variant="tonal">{{ item.raw.type }}</VChip></template></VListItem></template>
        </VAutocomplete>
        <VBtn color="primary" block :loading="addingCourse" :disabled="!selectedCourse" prepend-icon="tabler-plus" @click="addCourse">{{ t('lms_learning_paths.drawer.add_button') }}</VBtn>
      </div>
      <VDivider />
      <div class="pa-4">
        <p class="text-body-2 font-weight-medium mb-2">{{ t('lms_learning_paths.drawer.current_count') }}</p>
        <p class="text-medium-emphasis text-body-2">{{ t('lms_learning_paths.drawer.count_body', { count: activePath?.path_courses_count ?? 0 }) }}</p>
      </div>
    </VNavigationDrawer>
  </VContainer>
</template>

<style scoped>
.lp-desc { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
</style>
