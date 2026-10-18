<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const {t}= useI18n()
const props = defineProps<{
  questionnaires: any[]
  typeLabels: Record<string, string>
}>()

const search     = ref('')
const filterType = ref<string | null>(null)
const saving     = ref(false)
const deleting   = ref(false)
const createDialog = ref(false)
const deleteDialog = ref(false)
const editingId    = ref<number | null>(null)
const deletingItem = ref<any>(null)
const errors       = ref<Record<string, string>>({})

const blankForm = () => ({
  title: '',
  type: 'knowledge_check',
  description: '',
  instructions: '',
  time_limit_mins: null as number | null,
  pass_score: null as number | null,
  allow_retake: false,
  max_retakes: null as number | null,
  randomise_questions: false,
  show_answers_after: false,
})
const form = ref(blankForm())

const typeOptions = computed(() =>
  Object.entries(props.typeLabels).map(([value, label]) => ({ value, label }))
)

const typeColorMap: Record<string, string> = {
  questionnaire: 'blue-grey',
  pre_assessment: 'orange',
  post_assessment: 'green',
  knowledge_check: 'cyan',
  final_test: 'deep-purple',
  practical_assessment: 'teal',
  self_assessment: 'indigo',
  manager_observation: 'brown',
  feedback: 'pink',
}
const typeColor = (type: string) => typeColorMap[type] ?? 'grey'

const filtered = computed(() => {
  let list = props.questionnaires
  if (filterType.value) list = list.filter(q => q.type === filterType.value)
  return list
})

// Les en-têtes de tableau utilisent les clés de traduction
const headers = computed(()=> [
  { title: 'Title', key: 'title' },
  { title: 'Type', key: 'type_label' },
  { title: 'Questions', key: 'questions_count' },
  { title: 'Pass Score', key: 'pass_score' },
  { title: 'Retakes', key: 'allow_retake' },
  { title: 'Created', key: 'created_at' },
  { title: '', key: 'actions', sortable: false, align: 'end' as const },
])

function openEdit(item: any) {
  editingId.value = item.id
  form.value = {
    title: item.title,
    type: item.type,
    description: item.description ?? '',
    instructions: item.instructions ?? '',
    time_limit_mins: item.time_limit_mins,
    pass_score: item.pass_score,
    allow_retake: item.allow_retake,
    max_retakes: item.max_retakes,
    randomise_questions: item.randomise_questions,
    show_answers_after: item.show_answers_after,
  }
  createDialog.value = true
}

function closeDialog() {
  createDialog.value = false
  editingId.value = null
  form.value = blankForm()
  errors.value = {}
}

function save() {
  saving.value = true
  errors.value = {}
  const url = editingId.value
    ? route('lms.admin.questionnaires.update', editingId.value)
    : route('lms.admin.questionnaires.store')
  const method = editingId.value ? 'patch' : 'post'

  router[method](url, form.value, {
    onSuccess: closeDialog,
    onError: (e) => { errors.value = e },
    onFinish: () => { saving.value = false },
  })
}

function confirmDelete(item: any) {
  deletingItem.value = item
  deleteDialog.value = true
}

function doDelete() {
  if (!deletingItem.value) return
  deleting.value = true
  router.delete(route('lms.admin.questionnaires.destroy', deletingItem.value.id), {
    onSuccess: () => { deleteDialog.value = false },
    onFinish: () => { deleting.value = false },
  })
}
</script>
<template>
  <VCard>
    <VCardTitle class="d-flex align-center pa-4">
      <span class="text-h6">{{ t('questionnaire.page_title') }}</span>
      <VSpacer />
      <VBtn color="primary" prepend-icon="mdi-plus" @click="createDialog = true">
        {{ t('questionnaire.new_questionnaire') }}
      </VBtn>
    </VCardTitle>

    <!-- Filter bar -->
    <VCardText class="pb-0">
      <VRow dense>
        <VCol cols="12" md="4">
          <VTextField
            v-model="search"
            :placeholder="t('questionnaire.search_placeholder')"
            prepend-inner-icon="mdi-magnify"
            density="compact"
            hide-details
            clearable
          />
        </VCol>
        <VCol cols="12" md="3">
          <VSelect
            v-model="filterType"
            :items="typeOptions"
            item-title="label"
            item-value="value"
            :placeholder="t('questionnaire.all_types')"
            density="compact"
            hide-details
            clearable
          />
        </VCol>
      </VRow>
    </VCardText>

    <!-- List -->
    <VDataTable
      :headers="headers"
      :items="filtered"
      :search="search"
      class="mt-2"
    >
      <template #item.type_label="{ item }">
        <VChip :color="typeColor(item.type)" size="small" label>{{ item.type_label }}</VChip>
      </template>
      <template #item.pass_score="{ item }">
        <span v-if="item.pass_score !== null">{{ item.pass_score }}%</span>
        <span v-else class="text-medium-emphasis">—</span>
      </template>
      <template #item.allow_retake="{ item }">
        <VIcon v-if="item.allow_retake" color="success">mdi-check-circle</VIcon>
        <VIcon v-else color="error">mdi-close-circle</VIcon>
      </template>
      <template #item.actions="{ item }">
        <VBtn
          icon
          size="small"
          variant="text"
          :href="route('lms.admin.questionnaires.builder', item.id)"
        >
          <VIcon>mdi-pencil-ruler</VIcon>
          <VTooltip activator="parent">{{ t('questionnaire.tooltips.builder') }}</VTooltip>
        </VBtn>
        <VBtn icon size="small" variant="text" color="primary" @click="openEdit(item)">
          <VIcon>mdi-cog</VIcon>
          <VTooltip activator="parent">{{ t('questionnaire.tooltips.settings') }}</VTooltip>
        </VBtn>
        <VBtn icon size="small" variant="text" color="error" @click="confirmDelete(item)">
          <VIcon>mdi-delete</VIcon>
          <VTooltip activator="parent">{{ t('questionnaire.tooltips.delete') }}</VTooltip>
        </VBtn>
      </template>
    </VDataTable>

    <!-- Create/Edit dialog -->
    <VDialog v-model="createDialog" max-width="640" persistent>
      <VCard>
        <VCardTitle class="pa-4">
          {{ editingId ? t('questionnaire.create_dialog.title_edit') : t('questionnaire.create_dialog.title_new') }}
        </VCardTitle>
        <VDivider />
        <VCardText>
          <VRow dense>
            <VCol cols="12">
              <VTextField
                v-model="form.title"
                :label="t('questionnaire.create_dialog.title_label')"
                :error-messages="errors.title"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect
                v-model="form.type"
                :items="typeOptions"
                item-title="label"
                item-value="value"
                :label="t('questionnaire.create_dialog.type_label')"
                :error-messages="errors.type"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model.number="form.pass_score"
                :label="t('questionnaire.create_dialog.pass_score_label')"
                type="number"
                min="0"
                max="100"
                :hint="t('questionnaire.create_dialog.pass_score_hint')"
                persistent-hint
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="form.description"
                :label="t('questionnaire.create_dialog.description_label')"
                rows="2"
                auto-grow
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="form.instructions"
                :label="t('questionnaire.create_dialog.instructions_label')"
                rows="2"
                auto-grow
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model.number="form.time_limit_mins"
                :label="t('questionnaire.create_dialog.time_limit_label')"
                type="number"
                min="1"
                :hint="t('questionnaire.create_dialog.time_limit_hint')"
                persistent-hint
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model.number="form.max_retakes"
                :label="t('questionnaire.create_dialog.max_retakes_label')"
                type="number"
                min="1"
                :hint="t('questionnaire.create_dialog.max_retakes_hint')"
                persistent-hint
                :disabled="!form.allow_retake"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VSwitch
                v-model="form.allow_retake"
                :label="t('questionnaire.create_dialog.allow_retake_label')"
                color="primary"
                hide-details
              />
            </VCol>
            <VCol cols="12" md="4">
              <VSwitch
                v-model="form.randomise_questions"
                :label="t('questionnaire.create_dialog.randomise_label')"
                color="primary"
                hide-details
              />
            </VCol>
            <VCol cols="12" md="4">
              <VSwitch
                v-model="form.show_answers_after"
                :label="t('questionnaire.create_dialog.show_answers_label')"
                color="primary"
                hide-details
              />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="closeDialog">{{ t('questionnaire.create_dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="saving" @click="save">
            {{ editingId ? t('questionnaire.create_dialog.save_changes') : t('questionnaire.create_dialog.create') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Confirm delete -->
    <VDialog v-model="deleteDialog" max-width="420">
      <VCard>
        <VCardTitle class="pa-4">{{ t('questionnaire.delete_dialog.title') }}</VCardTitle>
        <VCardText>
          <span v-html="t('questionnaire.delete_dialog.confirm_message', { title: deletingItem?.title || '' })"></span>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="deleteDialog = false">{{ t('questionnaire.delete_dialog.cancel') }}</VBtn>
          <VBtn color="error" :loading="deleting" @click="doDelete">{{ t('questionnaire.delete_dialog.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VCard>
</template>


