<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Category = { id: number; name: string; color: string | null }

type Course = {
  id: number; uuid: string; title: string; description: string | null
  type: string; duration_mins: number | null; pass_score: number | null
  is_mandatory: boolean; is_published: boolean; is_qcto_registered: boolean
  seta_accreditation_number: string | null; seta_accreditation_expires_at: string | null
  enrollments_count: number; completion_rate: number; category: Category | null
}

type Paginated<T> = { data: T[]; links: any[]; meta: any }

const props = defineProps<{
  courses: Paginated<Course>
  categories: Category[]
  filters: Record<string, string>
}>()

const typeColor: Record<string, string> = {
  video: 'primary', document: 'secondary', quiz: 'success', scorm: 'warning', external: 'info',
}

function accreditationChip(expires: string | null): { color: string; label: string } | null {
  if (!expires) return null
  const diff = (new Date(expires).getTime() - Date.now()) / 86400000
  if (diff < 0)   return { color: 'error',   label: t('lms_courses_index.table.accreditation_expired') }
  if (diff <= 30) return { color: 'error',   label: t('lms_courses_index.table.accreditation_days_left', { days: Math.round(diff) }) }
  if (diff <= 90) return { color: 'warning', label: t('lms_courses_index.table.accreditation_days_left', { days: Math.round(diff) }) }
  return null
}

const typeOptions = computed(() => [
  { title: t('lms_courses_index.types.video'), value: 'video' },
  { title: t('lms_courses_index.types.document'), value: 'document' },
  { title: t('lms_courses_index.types.quiz'), value: 'quiz' },
  { title: t('lms_courses_index.types.scorm'), value: 'scorm' },
  { title: t('lms_courses_index.types.external'), value: 'external' },
])

const statusOptions = computed(() => [
  { title: t('lms_courses_index.status_all'), value: '' },
  { title: t('lms_courses_index.status_published'), value: 'true' },
  { title: t('lms_courses_index.status_draft'), value: 'false' },
])

const mandatoryOptions = computed(() => [
  { title: t('lms_courses_index.mandatory_all'), value: '' },
  { title: t('lms_courses_index.mandatory_yes'), value: 'true' },
  { title: t('lms_courses_index.mandatory_no'), value: 'false' },
])
const search    = ref(props.filters.search ?? '')
const category  = ref(props.filters.category ?? '')
const published = ref(props.filters.published ?? '')
const mandatory = ref(props.filters.mandatory ?? '')

function applyFilters() {
  router.get(route('lms.admin.courses'), {
    search: search.value || undefined, category: category.value || undefined,
    published: published.value || undefined, mandatory: mandatory.value || undefined,
  }, { preserveState: true, replace: true })
}

const dialog      = ref(false)
const editTarget  = ref<Course | null>(null)
const submitting  = ref(false)

const deleteDialog = ref(false)
const deleteTarget = ref<Course | null>(null)
const deleteLoading = ref(false)

const form = ref({
  title: '', description: '', category_id: null as number | null, type: 'video',
  duration_mins: null as number | null, pass_score: null as number | null,
  is_mandatory: false, is_published: false,
})

function openCreate() {
  editTarget.value = null
  form.value = { title: '', description: '', category_id: null, type: 'video', duration_mins: null, pass_score: null, is_mandatory: false, is_published: false }
  dialog.value = true
}

function openEdit(course: Course) {
  editTarget.value = course
  form.value = { title: course.title, description: course.description ?? '', category_id: course.category?.id ?? null, type: course.type, duration_mins: course.duration_mins, pass_score: course.pass_score, is_mandatory: course.is_mandatory, is_published: course.is_published }
  dialog.value = true
}

function submitForm() {
  submitting.value = true
  const url = editTarget.value ? route('lms.admin.courses.update', { course: editTarget.value.uuid }) : route('lms.admin.courses.store')
  const method = editTarget.value ? 'patch' : 'post'
  router[method](url, form.value, { onFinish: () => { submitting.value = false }, onSuccess: () => { dialog.value = false } })
}

function togglePublish(course: Course) { router.patch(route('lms.admin.courses.publish', { course: course.uuid })) }

function confirmDelete(course: Course) { deleteTarget.value = course; deleteDialog.value = true }

function executeDelete() {
  if (!deleteTarget.value) return; deleteLoading.value = true
  router.delete(route('lms.admin.courses.destroy', { course: deleteTarget.value.uuid }), {
    onFinish: () => { deleteLoading.value = false; deleteDialog.value = false; deleteTarget.value = null },
  })
}

function goToPage(url: string | null) { if (url) router.visit(url, { preserveState: true }) }

const rows  = computed(() => props.courses.data)
const links = computed(() => props.courses.links ?? [])
const meta  = computed(() => props.courses.meta ?? {})

const headers = computed(() => [
  { title: t('lms_courses_index.table.course'), key: 'title', sortable: false },
  { title: t('lms_courses_index.table.category'), key: 'category', sortable: false },
  { title: t('lms_courses_index.table.type'), key: 'type', sortable: false },
  { title: t('lms_courses_index.table.enrollments'), key: 'enrollments_count', sortable: false, align: 'center' as const },
  { title: t('lms_courses_index.table.completion'), key: 'completion_rate', sortable: false },
  { title: t('lms_courses_index.table.flags'), key: 'flags', sortable: false },
  { title: t('lms_courses_index.table.actions'), key: 'actions', sortable: false, align: 'end' as const },
])
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div><h4 class="text-h4">{{ t('lms_courses_index.title') }}</h4><p class="text-medium-emphasis mb-0">{{ t('lms_courses_index.subtitle') }}</p></div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">{{ t('lms_courses_index.new_course') }}</VBtn>
    </div>

    <VCard class="mb-5">
      <VCardText class="pa-4">
        <VRow dense align="center">
          <VCol cols="12" md="4"><VTextField v-model="search" :label="t('lms_courses_index.search')" prepend-inner-icon="tabler-search" variant="outlined" density="compact" hide-details clearable @update:model-value="applyFilters" /></VCol>
          <VCol cols="6" md="2"><VSelect v-model="category" :items="[{ id: '', name: t('lms_courses_index.all_categories') }, ...categories]" item-title="name" item-value="id" :label="t('lms_courses_index.category_label')" variant="outlined" density="compact" hide-details @update:model-value="applyFilters" /></VCol>
          <VCol cols="6" md="2"><VSelect v-model="published" :items="statusOptions" item-title="title" item-value="value" :label="t('lms_courses_index.status_label')" variant="outlined" density="compact" hide-details @update:model-value="applyFilters" /></VCol>
          <VCol cols="6" md="2"><VSelect v-model="mandatory" :items="mandatoryOptions" item-title="title" item-value="value" :label="t('lms_courses_index.mandatory_label')" variant="outlined" density="compact" hide-details @update:model-value="applyFilters" /></VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard>
      <VDataTable :headers="headers" :items="rows" hide-default-footer :items-per-page="25" hover>
        <template #item.title="{ item }"><div><p class="text-body-2 font-weight-medium mb-0">{{ item.title }}</p><p v-if="item.description" class="text-caption text-medium-emphasis mb-0 text-truncate" style="max-width: 260px;">{{ item.description }}</p></div></template>
        <template #item.category="{ item }"><VChip v-if="item.category" :color="item.category.color ?? 'default'" size="small" variant="tonal">{{ item.category.name }}</VChip><span v-else class="text-caption text-medium-emphasis">—</span></template>
        <template #item.type="{ item }"><VChip :color="typeColor[item.type] ?? 'default'" size="small" variant="tonal">{{ item.type.toUpperCase() }}</VChip></template>
        <template #item.enrollments_count="{ item }"><span class="text-body-2">{{ item.enrollments_count }}</span></template>
        <template #item.completion_rate="{ item }"><div class="d-flex align-center gap-2" style="min-width: 100px;"><VProgressLinear :model-value="item.completion_rate" color="success" rounded height="6" style="min-width:60px;" /><span class="text-caption">{{ item.completion_rate }}%</span></div></template>
        <template #item.flags="{ item }">
          <div class="d-flex gap-1 flex-wrap">
            <VChip v-if="item.is_mandatory" color="error" size="x-small" variant="tonal">{{ t('lms_courses_index.table.required') }}</VChip>
            <VChip :color="item.is_published ? 'success' : 'default'" size="x-small" variant="tonal">{{ item.is_published ? t('lms_courses_index.table.published') : t('lms_courses_index.table.draft') }}</VChip>
            <VChip v-if="item.is_qcto_registered" color="primary" size="x-small" variant="outlined">{{ t('lms_courses_index.table.qcto') }}</VChip>
            <VChip v-if="accreditationChip(item.seta_accreditation_expires_at)" :color="accreditationChip(item.seta_accreditation_expires_at)!.color" size="x-small" variant="tonal" prepend-icon="tabler-certificate">{{ accreditationChip(item.seta_accreditation_expires_at)!.label }}</VChip>
          </div>
        </template>
        <template #item.actions="{ item }">
          <div class="d-flex justify-end align-center">
            <VMenu location="bottom end">
              <template #activator="{ props: menuProps }">
                <VBtn v-bind="menuProps" icon variant="text" size="small" color="default" :aria-label="t('lms_courses_index.table.actions')">
                  <VIcon icon="tabler-dots-vertical" />
                </VBtn>
              </template>
              <VList density="compact" min-width="180">
                <VListItem prepend-icon="tabler-edit" :title="t('lms_courses_index.table.edit')" @click="openEdit(item)" />
                <VListItem prepend-icon="tabler-layout-board" :title="t('lms_courses_index.table.builder')" :href="route('lms.admin.courses.builder', { course: item.uuid })" />
                <VListItem
                  :prepend-icon="item.is_published ? 'tabler-eye-off' : 'tabler-eye'"
                  :title="item.is_published ? t('lms_courses_index.table.unpublish') : t('lms_courses_index.table.publish')"
                  @click="togglePublish(item)"
                />
                <VDivider class="my-1" />
                <VListItem prepend-icon="tabler-trash" base-color="error" :title="t('lms_courses_index.table.delete')" @click="confirmDelete(item)" />
              </VList>
            </VMenu>
          </div>
        </template>
        <template #no-data><div class="text-center pa-10"><VIcon icon="tabler-books" size="56" color="secondary" class="mb-3" /><p class="text-body-1 text-medium-emphasis">{{ t('lms_courses_index.table.no_courses') }}</p></div></template>
      </VDataTable>

      <VDivider v-if="meta.last_page > 1" />
      <div v-if="meta.last_page > 1" class="d-flex align-center justify-space-between pa-4">
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('lms_courses_index.table.pagination', { from: meta.from, to: meta.to, total: meta.total }) }}</p>
        <div class="d-flex gap-1"><VBtn v-for="link in links" :key="link.label" :disabled="!link.url" :variant="link.active ? 'flat' : 'text'" :color="link.active ? 'primary' : 'default'" size="small" @click="goToPage(link.url)" v-html="link.label" /></div>
      </div>
    </VCard>

    <VDialog v-model="deleteDialog" max-width="420" persistent>
      <VCard rounded="lg">
        <VCardText class="pa-6 text-center"><VAvatar color="error" variant="tonal" size="56" class="mb-4"><VIcon icon="tabler-trash" size="28" /></VAvatar><h6 class="text-h6 font-weight-semibold mb-2">{{ t('lms_courses_index.delete_dialog.title') }}</h6><p class="text-body-2 text-medium-emphasis mb-0">{{ t('lms_courses_index.delete_dialog.body', { title: deleteTarget?.title }) }}</p></VCardText>
        <VDivider />
        <VCardActions class="pa-4 gap-2 justify-end"><VBtn variant="tonal" color="secondary" @click="deleteDialog = false">{{ t('lms_courses_index.delete_dialog.cancel') }}</VBtn><VBtn color="error" variant="tonal" :loading="deleteLoading" @click="executeDelete">{{ t('lms_courses_index.delete_dialog.delete') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="dialog" max-width="640" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-h6">{{ editTarget ? t('lms_courses_index.dialog.title_edit') : t('lms_courses_index.dialog.title_new') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12"><VTextField v-model="form.title" :label="t('lms_courses_index.dialog.title_label')" variant="outlined" density="compact" /></VCol>
            <VCol cols="12"><VTextarea v-model="form.description" :label="t('lms_courses_index.dialog.description_label')" variant="outlined" density="compact" rows="3" /></VCol>
            <VCol cols="12" sm="6"><VSelect v-model="form.type" :items="typeOptions" item-title="title" item-value="value" :label="t('lms_courses_index.dialog.type_label')" variant="outlined" density="compact" /></VCol>
            <VCol cols="12" sm="6"><VSelect v-model="form.category_id" :items="categories" item-title="name" item-value="id" :label="t('lms_courses_index.dialog.category_label')" variant="outlined" density="compact" clearable /></VCol>
            <VCol cols="6"><VTextField v-model="form.duration_mins" :label="t('lms_courses_index.dialog.duration_label')" type="number" variant="outlined" density="compact" /></VCol>
            <VCol cols="6"><VTextField v-model="form.pass_score" :label="t('lms_courses_index.dialog.pass_score_label')" type="number" variant="outlined" density="compact" /></VCol>
            <VCol cols="6"><VSwitch v-model="form.is_mandatory" :label="t('lms_courses_index.dialog.mandatory_label')" color="error" hide-details /></VCol>
            <VCol cols="6"><VSwitch v-model="form.is_published" :label="t('lms_courses_index.dialog.published_label')" color="success" hide-details /></VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4"><VSpacer /><VBtn variant="text" @click="dialog = false">{{ t('lms_courses_index.dialog.cancel') }}</VBtn><VBtn color="primary" :loading="submitting" @click="submitForm">{{ editTarget ? t('lms_courses_index.dialog.save_changes') : t('lms_courses_index.dialog.create_course') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
