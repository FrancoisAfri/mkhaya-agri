<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Category = {
  id: number
  name: string
  description: string | null
  icon: string | null
  color: string | null
  sort_order: number
  courses_count: number
}

const props = defineProps<{
  categories: Category[]
}>()

const dialog      = ref(false)
const deleting    = ref(false)
const deleteTarget = ref<Category | null>(null)
const deleteDialog = ref(false)
const editTarget  = ref<Category | null>(null)
const submitting  = ref(false)

const form = ref({
  name:        '',
  description: '',
  icon:        '',
  color:       'primary',
  sort_order:  0,
})

const ALL_ICONS = [
  'book', 'book-2', 'books', 'book-open', 'book-open-2',
  'school', 'certificate', 'award', 'medal',
  'brain', 'bulb', 'microscope', 'flask', 'atom',
  'code', 'terminal', 'cpu', 'device-laptop',
  'chart-bar', 'presentation', 'chart-line', 'report-analytics',
  'users', 'user', 'user-check', 'user-star',
  'briefcase', 'building', 'building-skyscraper',
  'heart', 'star', 'flag', 'rocket',
  'music', 'camera', 'palette', 'pencil',
  'language', 'world', 'map',
  'settings', 'tool', 'wrench', 'adjustments',
  'shield', 'lock', 'eye',
  'calculator', 'math-function',
  'clock', 'calendar',
  'shopping-cart', 'coin', 'currency-dollar',
  'truck', 'car', 'plane',
  'leaf', 'plant', 'sun',
  'message', 'phone', 'mail',
  'puzzle', 'dice', 'target',
  'run', 'ball-football', 'swimming',
  'chef-hat', 'pizza',
  'first-aid-kit', 'heartbeat',
  'photo', 'video', 'microphone',
  'git-branch', 'database', 'cloud',
  'layout-dashboard', 'list', 'table',
  'tags', 'tag', 'category',
]

function openCreate() {
  editTarget.value = null
  const nextOrder = props.categories.length
    ? Math.max(...props.categories.map(c => c.sort_order)) + 1
    : 0
  form.value = { name: '', description: '', icon: '', color: 'primary', sort_order: nextOrder }
  dialog.value = true
}

function openEdit(cat: Category) {
  editTarget.value = cat
  form.value = {
    name:        cat.name,
    description: cat.description ?? '',
    icon:        cat.icon ?? '',
    color:       cat.color ?? 'primary',
    sort_order:  cat.sort_order,
  }
  dialog.value = true
}

function submitForm() {
  submitting.value = true
  const url = editTarget.value
    ? route('lms.admin.categories.update', { category: editTarget.value.id })
    : route('lms.admin.categories.store')
  const method = editTarget.value ? 'patch' : 'post'

  router[method](url, form.value, {
    onFinish: () => { submitting.value = false },
    onSuccess: () => { dialog.value = false },
  })
}

function confirmDelete(cat: Category) {
  deleteTarget.value = cat
  deleteDialog.value = true
}

function executeDelete() {
  if (!deleteTarget.value) return
  deleting.value = true
  router.delete(route('lms.admin.categories.destroy', { category: deleteTarget.value.id }), {
    onFinish: () => {
      deleting.value = false
      deleteDialog.value = false
      deleteTarget.value = null
    },
  })
}

const colorOptions = [
  { title: t('lms_categories.colors.primary'), value: 'primary' },
  { title: t('lms_categories.colors.secondary'), value: 'secondary' },
  { title: t('lms_categories.colors.success'), value: 'success' },
  { title: t('lms_categories.colors.info'), value: 'info' },
  { title: t('lms_categories.colors.warning'), value: 'warning' },
  { title: t('lms_categories.colors.error'), value: 'error' },
]
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4">{{ t('lms_categories.title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('lms_categories.subtitle') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">{{ t('lms_categories.new_category') }}</VBtn>
    </div>

    <!-- Grid -->
    <VRow v-if="categories.length">
      <VCol v-for="cat in categories" :key="cat.id" cols="12" sm="6" md="4" lg="3">
        <VCard elevation="0" border hover>
          <VCardText class="pa-4">
            <div class="d-flex align-center gap-3 mb-3">
              <VAvatar :color="cat.color ?? 'primary'" variant="tonal" size="44" rounded>
                <VIcon :icon="`tabler-${cat.icon || 'tag'}`" size="22" />
              </VAvatar>
              <div class="flex-1-1">
                <p class="text-body-1 font-weight-semibold mb-0">{{ cat.name }}</p>
                <p class="text-caption text-medium-emphasis mb-0">{{ cat.courses_count === 1 ? t('lms_categories.course_singular', { count: cat.courses_count }) : t('lms_categories.course_plural', { count: cat.courses_count }) }}</p>
              </div>
            </div>
            <p v-if="cat.description" class="text-body-2 text-medium-emphasis mb-0 text-truncate">{{ cat.description }}</p>
          </VCardText>
          <VDivider />
          <VCardActions class="pa-2">
            <VBtn size="small" variant="text" color="primary" @click="openEdit(cat)">{{ t('lms_categories.edit') }}</VBtn>
            <VSpacer />
            <VBtn size="small" variant="text" color="error" @click="confirmDelete(cat)">{{ t('lms_categories.delete') }}</VBtn>
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>

    <VCard v-else elevation="0" border>
      <VCardText class="text-center py-12">
        <VIcon icon="tabler-tags" size="56" color="secondary" class="mb-3" />
        <p class="text-body-1 text-medium-emphasis">{{ t('lms_categories.empty') }}</p>
      </VCardText>
    </VCard>

    <!-- Create / Edit Dialog -->
    <VDialog v-model="dialog" max-width="500" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-h6">{{ editTarget ? t('lms_categories.dialog.edit_title') : t('lms_categories.dialog.new_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="12">
              <VTextField v-model="form.name" :label="t('lms_categories.dialog.name_label') + ' *'" variant="outlined" density="compact" />
            </VCol>
            <VCol cols="12">
              <VTextarea v-model="form.description" :label="t('lms_categories.dialog.description_label')" variant="outlined" density="compact" rows="2" />
            </VCol>
            <VCol cols="6">
              <VAutocomplete
                v-model="form.icon"
                :items="ALL_ICONS"
                :label="t('lms_categories.dialog.icon_label')"
                variant="outlined"
                density="compact"
                clearable
                hide-details
                :placeholder="t('lms_categories.dialog.icon_placeholder')"
              >
                <template #prepend-inner>
                  <VIcon
                    :icon="form.icon ? `tabler-${form.icon}` : 'tabler-tag'"
                    size="18"
                    :color="form.icon ? 'primary' : undefined"
                  />
                </template>
                <template #item="{ item, props: itemProps }">
                  <VListItem v-bind="itemProps" :title="item.value">
                    <template #prepend>
                      <VIcon :icon="`tabler-${item.value}`" size="18" class="mr-2" />
                    </template>
                  </VListItem>
                </template>
              </VAutocomplete>
            </VCol>
            <VCol cols="6">
              <VSelect
                v-model="form.color"
                :items="colorOptions"
                item-title="title"
                item-value="value"
                :label="t('lms_categories.dialog.color_label')"
                variant="outlined"
                density="compact"
              />
            </VCol>
            <VCol cols="12">
              <VTextField v-model="form.sort_order" :label="t('lms_categories.dialog.sort_order_label')" type="number" variant="outlined" density="compact" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">{{ t('lms_categories.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="submitting" @click="submitForm">
            {{ editTarget ? t('lms_categories.dialog.save') : t('lms_categories.dialog.create') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Delete Confirmation Dialog -->
    <VDialog v-model="deleteDialog" max-width="420" persistent>
      <VCard rounded="lg">
        <VCardText class="pa-6 text-center">
          <VAvatar color="error" variant="tonal" size="56" class="mb-4">
            <VIcon icon="tabler-trash" size="28" />
          </VAvatar>
          <h6 class="text-h6 font-weight-semibold mb-2">{{ t('lms_categories.delete_dialog.title') }}</h6>
          <p class="text-body-2 text-medium-emphasis mb-0">
            {{ t('lms_categories.delete_dialog.message', { name: deleteTarget?.name }) }}
          </p>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4 gap-2 justify-end">
          <VBtn variant="tonal" color="secondary" @click="deleteDialog = false">{{ t('lms_categories.delete_dialog.cancel') }}</VBtn>
          <VBtn color="error" variant="tonal" :loading="deleting" @click="executeDelete">
            {{ t('lms_categories.delete_dialog.delete_button') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

  </VContainer>
</template>
