<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import { route } from 'ziggy-js'

const props = defineProps<{
  questionnaire: any
  allCourses: any[]
  bankCategories: any
  typeLabels: Record<string, string>
  questionTypes: Record<string, string>
  assignmentTypes: Record<string, string>
}>()

const tab = ref('questions')
const selectedQ = ref<any>(null)
const questionDialog   = ref(false)
const bankImportDialog = ref(false)
const assignDialog     = ref(false)
const settingsDialog   = ref(false)
const editingQId       = ref<number | null>(null)
const savingQ          = ref(false)
const savingAssign     = ref(false)
const savingSettings   = ref(false)
const importing        = ref(false)
const importBankId     = ref<number | null>(null)
const importSelection  = ref<number[]>([])
const bankLoadedQuestions = ref<any[]>([])

const optionTypes = ['mcq', 'multi_select', 'true_false', 'yes_no', 'rating_scale']
const qTypeHasOptions = computed(() => optionTypes.includes(qForm.value.type))

const blankQ = () => ({
  type: 'mcq',
  question_text: '',
  context_text: '',
  explanation: '',
  points: 1,
  options: [
    { option_text: '', is_correct: false },
    { option_text: '', is_correct: false },
  ],
})
const qForm = ref(blankQ())

const aForm = ref({
  assignment_type: 'post_course',
  course_id: null as number | null,
  is_required: true,
  due_days_after_enrol: null as number | null,
})

const sForm = ref({ ...props.questionnaire })

const questionTypeOptions = computed(() =>
  Object.entries(props.questionTypes).map(([value, label]) => ({ value, label }))
)
const assignmentTypeOptions = computed(() =>
  Object.entries(props.assignmentTypes).map(([value, label]) => ({ value, label }))
)

const bankOptions = computed(() => {
  const result: any[] = []
  for (const [cat, banks] of Object.entries(props.bankCategories as Record<string, any[]>)) {
    for (const b of banks) {
      result.push({ value: b.id, label: `${b.title} (${cat || 'Uncategorized'})` })
    }
  }
  return result
})

const totalPoints = computed(() => props.questionnaire.questions.reduce((s: number, q: any) => s + (q.points ?? 0), 0))
const autoGradedCount = computed(() => props.questionnaire.questions.filter((q: any) => q.is_auto_graded).length)

const typeColorMap: Record<string, string> = {
  questionnaire: 'blue-grey', pre_assessment: 'orange', post_assessment: 'green',
  knowledge_check: 'cyan', final_test: 'deep-purple', practical_assessment: 'teal',
  self_assessment: 'indigo', manager_observation: 'brown', feedback: 'pink',
  mcq: 'primary', multi_select: 'blue', true_false: 'orange', short_answer: 'teal',
  long_answer: 'green', file_upload: 'purple', practical: 'brown', rating_scale: 'cyan',
  yes_no: 'amber', scenario: 'deep-orange', policy_ack: 'red',
}
const typeColor = (type: string) => typeColorMap[type] ?? 'grey'

function openAddQuestion() {
  editingQId.value = null
  qForm.value = blankQ()
  questionDialog.value = true
}

function openEditQuestion(q: any) {
  editingQId.value = q.id
  qForm.value = {
    type: q.type,
    question_text: q.question_text,
    context_text: q.context_text ?? '',
    explanation: q.explanation ?? '',
    points: q.points,
    options: q.options?.map((o: any) => ({
      option_text: o.option_text,
      is_correct: o.is_correct,
      rating_value: o.rating_value,
    })) ?? [],
  }
  questionDialog.value = true
}

function closeQDialog() {
  questionDialog.value = false
  editingQId.value = null
}

function addQOption() { qForm.value.options.push({ option_text: '', is_correct: false }) }
function removeQOption(i: number) { qForm.value.options.splice(i, 1) }

function saveQuestion() {
  savingQ.value = true
  const payload: any = { ...qForm.value }
  if (!qTypeHasOptions.value) delete payload.options

  const url = editingQId.value
    ? route('lms.admin.questionnaires.questions.update', { questionnaire: props.questionnaire.id, question: editingQId.value })
    : route('lms.admin.questionnaires.questions.store', props.questionnaire.id)
  const method = editingQId.value ? 'patch' : 'post'

  router[method](url, payload, {
    onSuccess: closeQDialog,
    onFinish: () => { savingQ.value = false },
  })
}

function deleteQuestion(q: any) {
  router.delete(route('lms.admin.questionnaires.questions.destroy', { questionnaire: props.questionnaire.id, question: q.id }))
}

async function loadBankQuestions(bankId: number | null) {
  if (!bankId) { bankLoadedQuestions.value = []; return }
  importSelection.value = []
  try {
    const { data } = await axios.get(route('lms.admin.question-banks.questions', bankId))
    bankLoadedQuestions.value = data.questions ?? []
  } catch {
    bankLoadedQuestions.value = []
  }
}

function doImport() {
  if (importSelection.value.length === 0) return
  importing.value = true
  router.post(
    route('lms.admin.questionnaires.import-from-bank', props.questionnaire.id),
    { question_ids: importSelection.value },
    {
      onSuccess: () => { bankImportDialog.value = false; importSelection.value = [] },
      onFinish: () => { importing.value = false },
    }
  )
}

function saveAssignment() {
  savingAssign.value = true
  router.post(
    route('lms.admin.questionnaires.assignments.store', props.questionnaire.id),
    aForm.value,
    {
      onSuccess: () => { assignDialog.value = false },
      onFinish: () => { savingAssign.value = false },
    }
  )
}

function deleteAssignment(a: any) {
  router.delete(route('lms.admin.questionnaires.assignments.destroy', { questionnaire: props.questionnaire.id, assignment: a.id }))
}

function saveSettings() {
  savingSettings.value = true
  router.patch(
    route('lms.admin.questionnaires.update', props.questionnaire.id),
    sForm.value,
    {
      onSuccess: () => { settingsDialog.value = false },
      onFinish: () => { savingSettings.value = false },
    }
  )
}
</script>
<template>
  <div>
    <!-- Header -->
    <div class="d-flex align-center mb-4 gap-3">
      <VBtn icon variant="text" :href="route('lms.admin.questionnaires')">
        <VIcon>mdi-arrow-left</VIcon>
      </VBtn>
      <div>
        <div class="text-h6">{{ questionnaire.title }}</div>
        <VChip size="small" :color="typeColor(questionnaire.type)" label class="mt-1">
          {{ questionnaire.type_label }}
        </VChip>
      </div>
      <VSpacer />
      <VBtn variant="tonal" prepend-icon="mdi-cog" @click="settingsDialog = true">
        {{ $t('lms_questionnaire_builder.settings_button') }}
      </VBtn>
    </div>

    <VTabs v-model="tab" color="primary">
      <VTab value="questions">{{ $t('lms_questionnaire_builder.tabs.questions') }} ({{ questionnaire.questions.length }})</VTab>
      <VTab value="assignments">{{ $t('lms_questionnaire_builder.tabs.assignments') }} ({{ questionnaire.assignments.length }})</VTab>
    </VTabs>

    <VWindow v-model="tab" class="mt-4">
      <!-- ── Questions tab ────────────────────────────────────────────── -->
      <VWindowItem value="questions">
        <VRow>
          <VCol cols="12" md="8">
            <!-- Question list -->
            <div class="d-flex gap-2 mb-3">
              <VBtn color="primary" prepend-icon="mdi-plus" @click="openAddQuestion">
                {{ $t('lms_questionnaire_builder.questions.add_question') }}
              </VBtn>
              <VBtn variant="tonal" prepend-icon="mdi-bank" @click="bankImportDialog = true">
                {{ $t('lms_questionnaire_builder.questions.import_from_bank') }}
              </VBtn>
            </div>

            <div v-if="questionnaire.questions.length === 0" class="text-center text-medium-emphasis py-8">
              {{ $t('lms_questionnaire_builder.questions.no_questions') }}
            </div>

            <VCard
              v-for="(q, idx) in questionnaire.questions"
              :key="q.id"
              class="mb-3"
              :class="{ 'border-primary': selectedQ?.id === q.id }"
              @click="selectedQ = q"
            >
              <VCardText class="d-flex align-center gap-3 py-3">
                <VAvatar color="primary" variant="tonal" size="34">{{ idx + 1 }}</VAvatar>
                <div class="flex-grow-1 min-width-0">
                  <div class="text-subtitle-2 text-truncate">{{ q.question_text }}</div>
                  <div class="d-flex gap-1 mt-1">
                    <VChip size="x-small" :color="typeColor(q.type)">{{ q.type_label }}</VChip>
                    <VChip size="x-small" variant="tonal">
                      {{ $tc('lms_questionnaire_builder.questions.points', q.points, { count: q.points }) }}
                    </VChip>
                    <VChip v-if="q.is_auto_graded" size="x-small" color="success" variant="tonal">
                      {{ $t('lms_questionnaire_builder.questions.auto_graded') }}
                    </VChip>
                    <VChip v-else size="x-small" color="orange" variant="tonal">
                      {{ $t('lms_questionnaire_builder.questions.manual_review') }}
                    </VChip>
                  </div>
                </div>
                <div class="d-flex gap-1">
                  <VBtn icon size="x-small" variant="text" @click.stop="openEditQuestion(q)">
                    <VIcon size="16">mdi-pencil</VIcon>
                  </VBtn>
                  <VBtn icon size="x-small" variant="text" color="error" @click.stop="deleteQuestion(q)">
                    <VIcon size="16">mdi-delete</VIcon>
                  </VBtn>
                </div>
              </VCardText>
            </VCard>
          </VCol>

          <!-- Side panel: question summary -->
          <VCol cols="12" md="4">
            <VCard>
              <VCardTitle class="pa-3 text-subtitle-1">{{ $t('lms_questionnaire_builder.summary.title') }}</VCardTitle>
              <VDivider />
              <VCardText>
                <div class="d-flex justify-space-between mb-2">
                  <span class="text-medium-emphasis">{{ $t('lms_questionnaire_builder.summary.total_questions') }}</span>
                  <strong>{{ questionnaire.questions.length }}</strong>
                </div>
                <div class="d-flex justify-space-between mb-2">
                  <span class="text-medium-emphasis">{{ $t('lms_questionnaire_builder.summary.total_points') }}</span>
                  <strong>{{ totalPoints }}</strong>
                </div>
                <div class="d-flex justify-space-between mb-2">
                  <span class="text-medium-emphasis">{{ $t('lms_questionnaire_builder.summary.pass_score') }}</span>
                  <strong>{{ questionnaire.pass_score !== null ? questionnaire.pass_score + '%' : '—' }}</strong>
                </div>
                <div class="d-flex justify-space-between mb-2">
                  <span class="text-medium-emphasis">{{ $t('lms_questionnaire_builder.summary.time_limit') }}</span>
                  <strong>{{ questionnaire.time_limit_mins ? questionnaire.time_limit_mins + ' min' : $t('lms_questionnaire_builder.summary.none') }}</strong>
                </div>
                <div class="d-flex justify-space-between mb-2">
                  <span class="text-medium-emphasis">{{ $t('lms_questionnaire_builder.summary.auto_graded_count') }}</span>
                  <strong>{{ autoGradedCount }} / {{ questionnaire.questions.length }}</strong>
                </div>
                <div class="d-flex justify-space-between">
                  <span class="text-medium-emphasis">{{ $t('lms_questionnaire_builder.summary.needs_review') }}</span>
                  <strong>{{ questionnaire.questions.length - autoGradedCount }}</strong>
                </div>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>
      </VWindowItem>

      <!-- ── Assignments tab ─────────────────────────────────────────── -->
      <VWindowItem value="assignments">
        <VBtn color="primary" prepend-icon="mdi-plus" class="mb-4" @click="assignDialog = true">
          {{ $t('lms_questionnaire_builder.assignments.add_assignment') }}
        </VBtn>

        <div v-if="questionnaire.assignments.length === 0" class="text-center text-medium-emphasis py-8">
          {{ $t('lms_questionnaire_builder.assignments.no_assignments') }}
        </div>

        <VCard
          v-for="a in questionnaire.assignments"
          :key="a.id"
          class="mb-3"
        >
          <VCardText class="d-flex align-center gap-3 py-3">
            <div class="flex-grow-1">
              <div class="text-subtitle-2">
                <VChip size="small" color="blue" class="mr-2">{{ a.type_label }}</VChip>
                <span v-if="a.course">{{ a.course.title }}</span>
                <span v-if="a.lesson"> › {{ a.lesson.title }}</span>
              </div>
              <div class="text-caption text-medium-emphasis mt-1">
                <span>{{ a.is_required ? $t('lms_questionnaire_builder.assignments.required') : $t('lms_questionnaire_builder.assignments.optional') }}</span>
                <span v-if="a.due_days_after_enrol">
                  {{ $t('lms_questionnaire_builder.assignments.due_days', { days: a.due_days_after_enrol }) }}
                </span>
              </div>
            </div>
            <VBtn icon size="x-small" variant="text" color="error" @click="deleteAssignment(a)">
              <VIcon size="16">mdi-delete</VIcon>
            </VBtn>
          </VCardText>
        </VCard>
      </VWindowItem>
    </VWindow>

    <!-- ── Add/Edit question dialog ──────────────────────────────────── -->
    <VDialog v-model="questionDialog" max-width="700" persistent scrollable>
      <VCard>
        <VCardTitle class="pa-4">
          {{ editingQId ? $t('lms_questionnaire_builder.question_dialog.title_edit') : $t('lms_questionnaire_builder.question_dialog.title_new') }}
        </VCardTitle>
        <VDivider />
        <VCardText style="max-height: 70vh; overflow-y: auto;">
          <VRow dense>
            <VCol cols="12" md="6">
              <VSelect
                v-model="qForm.type"
                :items="questionTypeOptions"
                item-title="label"
                item-value="value"
                :label="$t('lms_questionnaire_builder.question_dialog.type_label')"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model.number="qForm.points"
                :label="$t('lms_questionnaire_builder.question_dialog.points_label')"
                type="number"
                min="0"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="qForm.question_text"
                :label="$t('lms_questionnaire_builder.question_dialog.question_text_label')"
                rows="3"
                auto-grow
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="qForm.context_text"
                :label="$t('lms_questionnaire_builder.question_dialog.context_label')"
                rows="2"
                auto-grow
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="qForm.explanation"
                :label="$t('lms_questionnaire_builder.question_dialog.explanation_label')"
                rows="2"
                auto-grow
              />
            </VCol>

            <!-- Options -->
            <template v-if="qTypeHasOptions">
              <VCol cols="12">
                <div class="d-flex align-center mb-2">
                  <span class="text-subtitle-2">{{ $t('lms_questionnaire_builder.question_dialog.options_label') }}</span>
                  <VSpacer />
                  <VBtn size="x-small" variant="tonal" @click="addQOption">
                    {{ $t('lms_questionnaire_builder.question_dialog.add_option') }}
                  </VBtn>
                </div>
                <div v-for="(opt, i) in qForm.options" :key="i" class="d-flex align-center gap-2 mb-2">
                  <VCheckbox
                    v-model="opt.is_correct"
                    density="compact"
                    hide-details
                    color="success"
                    title="Mark as correct"
                  />
                  <VTextField
                    v-model="opt.option_text"
                    :label="`Option ${i + 1}`"
                    density="compact"
                    hide-details
                    class="flex-grow-1"
                  />
                  <VBtn icon size="x-small" variant="text" color="error" @click="removeQOption(i)">
                    <VIcon>mdi-close</VIcon>
                  </VBtn>
                </div>
              </VCol>
            </template>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="closeQDialog">{{ $t('lms_questionnaire_builder.question_dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="savingQ" @click="saveQuestion">
            {{ editingQId ? $t('lms_questionnaire_builder.question_dialog.save') : $t('lms_questionnaire_builder.question_dialog.add') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Import from bank ──────────────────────────────────────────── -->
    <VDialog v-model="bankImportDialog" max-width="700" scrollable>
      <VCard>
        <VCardTitle class="pa-4">{{ $t('lms_questionnaire_builder.import_dialog.title') }}</VCardTitle>
        <VDivider />
        <VCardText style="max-height: 60vh; overflow-y: auto;">
          <VSelect
            v-model="importBankId"
            :items="bankOptions"
            item-title="label"
            item-value="value"
            :label="$t('lms_questionnaire_builder.import_dialog.select_bank')"
            class="mb-3"
            @update:model-value="loadBankQuestions"
          />

          <div v-if="bankLoadedQuestions.length > 0">
            <div class="text-subtitle-2 mb-2">{{ $t('lms_questionnaire_builder.import_dialog.select_questions') }}</div>
            <VCheckboxBtn
              v-for="q in bankLoadedQuestions"
              :key="q.id"
              v-model="importSelection"
              :value="q.id"
              :label="`[${q.type}] ${q.question_text} (${q.points} pt)`"
              class="mb-1"
            />
          </div>
          <div v-else-if="importBankId" class="text-medium-emphasis text-center py-4">
            {{ $t('lms_questionnaire_builder.import_dialog.no_questions') }}
          </div>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="bankImportDialog = false">{{ $t('lms_questionnaire_builder.import_dialog.cancel') }}</VBtn>
          <VBtn
            color="primary"
            :disabled="importSelection.length === 0"
            :loading="importing"
            @click="doImport"
          >
            {{ $t('lms_questionnaire_builder.import_dialog.import') }}
            {{ importSelection.length > 0 ? `(${importSelection.length})` : '' }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Assign dialog ─────────────────────────────────────────────── -->
    <VDialog v-model="assignDialog" max-width="520" persistent>
      <VCard>
        <VCardTitle class="pa-4">{{ $t('lms_questionnaire_builder.assign_dialog.title') }}</VCardTitle>
        <VDivider />
        <VCardText>
          <VSelect
            v-model="aForm.assignment_type"
            :items="assignmentTypeOptions"
            item-title="label"
            item-value="value"
            :label="$t('lms_questionnaire_builder.assign_dialog.type_label')"
            class="mb-3"
          />
          <VAutocomplete
            v-model="aForm.course_id"
            :items="allCourses"
            item-title="title"
            item-value="id"
            :label="$t('lms_questionnaire_builder.assign_dialog.course_label')"
            clearable
            class="mb-3"
          />
          <VTextField
            v-model.number="aForm.due_days_after_enrol"
            :label="$t('lms_questionnaire_builder.assign_dialog.due_days_label')"
            type="number"
            min="1"
            class="mb-3"
          />
          <VSwitch
            v-model="aForm.is_required"
            :label="$t('lms_questionnaire_builder.assign_dialog.required_label')"
            color="primary"
            hide-details
          />
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="assignDialog = false">{{ $t('lms_questionnaire_builder.assign_dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="savingAssign" @click="saveAssignment">
            {{ $t('lms_questionnaire_builder.assign_dialog.add') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Settings dialog ───────────────────────────────────────────── -->
    <VDialog v-model="settingsDialog" max-width="600" persistent>
      <VCard>
        <VCardTitle class="pa-4">{{ $t('lms_questionnaire_builder.settings_dialog.title') }}</VCardTitle>
        <VDivider />
        <VCardText>
          <VRow dense>
            <VCol cols="12">
              <VTextField v-model="sForm.title" :label="$t('lms_questionnaire_builder.settings_dialog.title_label')" />
            </VCol>
            <VCol cols="12" md="6">
              <VSelect
                v-model="sForm.type"
                :items="Object.entries(typeLabels).map(([v,l]) => ({ value: v, label: l }))"
                item-title="label"
                item-value="value"
                :label="$t('lms_questionnaire_builder.settings_dialog.type_label')"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model.number="sForm.pass_score"
                :label="$t('lms_questionnaire_builder.settings_dialog.pass_score_label')"
                type="number"
                min="0"
                max="100"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="sForm.description"
                :label="$t('lms_questionnaire_builder.settings_dialog.description_label')"
                rows="2"
                auto-grow
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="sForm.instructions"
                :label="$t('lms_questionnaire_builder.settings_dialog.instructions_label')"
                rows="2"
                auto-grow
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model.number="sForm.time_limit_mins"
                :label="$t('lms_questionnaire_builder.settings_dialog.time_limit_label')"
                type="number"
                min="1"
              />
            </VCol>
            <VCol cols="12" md="6">
              <VTextField
                v-model.number="sForm.max_retakes"
                :label="$t('lms_questionnaire_builder.settings_dialog.max_retakes_label')"
                type="number"
                min="1"
                :disabled="!sForm.allow_retake"
              />
            </VCol>
            <VCol cols="12" md="4">
              <VSwitch
                v-model="sForm.allow_retake"
                :label="$t('lms_questionnaire_builder.settings_dialog.allow_retake_label')"
                color="primary"
                hide-details
              />
            </VCol>
            <VCol cols="12" md="4">
              <VSwitch
                v-model="sForm.randomise_questions"
                :label="$t('lms_questionnaire_builder.settings_dialog.randomise_label')"
                color="primary"
                hide-details
              />
            </VCol>
            <VCol cols="12" md="4">
              <VSwitch
                v-model="sForm.show_answers_after"
                :label="$t('lms_questionnaire_builder.settings_dialog.show_answers_label')"
                color="primary"
                hide-details
              />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="settingsDialog = false">{{ $t('lms_questionnaire_builder.settings_dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="savingSettings" @click="saveSettings">
            {{ $t('lms_questionnaire_builder.settings_dialog.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>


