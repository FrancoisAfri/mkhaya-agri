<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { ref, computed } from 'vue'
import AppTextField from '@/@core/components/app-form-elements/AppTextField.vue'
import AppSelect from '@/@core/components/app-form-elements/AppSelect.vue'
import AppTextarea from '@/@core/components/app-form-elements/AppTextarea.vue'

const route = ziggyRoute

// ── Types ──────────────────────────────────────────────────────────────────────

type QuestionType = 'multiple_choice' | 'true_false' | 'short_answer'

type Option = { label: string; value: string }

type Question = {
  id: number
  question_text: string
  type: QuestionType
  options: Option[] | null
  correct_answer: string
  explanation: string | null
  sort_order: number
  points: number
  ai_generated: boolean
}

type Settings = {
  pass_mark: number
  max_attempts: number
  time_limit_minutes: number | null
  randomize_questions: boolean
  show_correct_answers: boolean
}

type Stats = {
  total_attempts: number
  unique_participants: number
  pass_count: number
  fail_count: number
  pass_rate: number
  avg_score: number
}

// ── Props ──────────────────────────────────────────────────────────────────────

const props = defineProps<{
  policy: { id: number; uuid: string; name: string }
  questions: Question[]
  settings: Settings
  stats: Stats
}>()

// ── Settings form ──────────────────────────────────────────────────────────────

const settingsForm = useForm({
  pass_mark:            props.settings.pass_mark,
  max_attempts:         props.settings.max_attempts,
  time_limit_minutes:   props.settings.time_limit_minutes,
  randomize_questions:  props.settings.randomize_questions,
  show_correct_answers: props.settings.show_correct_answers,
})

function saveSettings() {
  settingsForm.patch(route('policies.quiz.settings.update', props.policy.uuid), {
    preserveScroll: true,
  })
}

// ── Add question form ──────────────────────────────────────────────────────────

const showAddDialog = ref(false)
const showViewDialog = ref(false)
const editingQuestion = ref<Question | null>(null)
const viewingQuestion = ref<Question | null>(null)

const questionForm = useForm({
  question_text:  '',
  type:           'multiple_choice' as QuestionType,
  options:        [
    { label: 'A', value: '' },
    { label: 'B', value: '' },
    { label: 'C', value: '' },
    { label: 'D', value: '' },
  ] as Option[],
  correct_answer: '',
  explanation:    '',
  points:         1,
})

function openAddDialog() {
  editingQuestion.value = null
  questionForm.reset()
  questionForm.options = [
    { label: 'A', value: '' },
    { label: 'B', value: '' },
    { label: 'C', value: '' },
    { label: 'D', value: '' },
  ]
  showAddDialog.value = true
}

function openEditDialog(q: Question) {
  editingQuestion.value = q
  questionForm.question_text  = q.question_text
  questionForm.type           = q.type
  questionForm.options        = q.options ?? [
    { label: 'A', value: '' },
    { label: 'B', value: '' },
  ]
  questionForm.correct_answer = q.correct_answer
  questionForm.explanation    = q.explanation ?? ''
  questionForm.points         = q.points
  showAddDialog.value = true
}

function openViewDialog(q: Question) {
  viewingQuestion.value = q
  showViewDialog.value = true
}

function closeViewDialog() {
  showViewDialog.value = false
  viewingQuestion.value = null
}

function editFromViewDialog() {
  const selected = viewingQuestion.value
  if (!selected) return

  closeViewDialog()
  openEditDialog(selected)
}

function closeDialog() {
  showAddDialog.value = false
  editingQuestion.value = null
  questionForm.reset()
}

function saveQuestion() {
  const payload = {
    question_text:  questionForm.question_text,
    type:           questionForm.type,
    options:        questionForm.type === 'multiple_choice' ? questionForm.options : null,
    correct_answer: questionForm.correct_answer,
    explanation:    questionForm.explanation,
    points:         questionForm.points,
  }

  if (editingQuestion.value) {
    router.patch(
      route('policies.quiz.questions.update', { policy: props.policy.uuid, question: editingQuestion.value.id }),
      payload,
      { preserveScroll: true, onSuccess: closeDialog }
    )
  } else {
    router.post(
      route('policies.quiz.questions.store', props.policy.uuid),
      payload,
      { preserveScroll: true, onSuccess: closeDialog }
    )
  }
}

function deleteQuestion(q: Question) {
  questionToDelete.value = q
  showDeleteDialog.value = true
}

// ── AI Generate ───────────────────────────────────────────────────────────────

const generateCount = ref(10)
const generating    = ref(false)
const showGenerateDialog = ref(false)
const questionSearch = ref('')

const showDeleteDialog = ref(false)
const questionToDelete = ref<Question | null>(null)

function openGenerateDialog() {
  showGenerateDialog.value = true
}

function closeGenerateDialog() {
  showGenerateDialog.value = false
}

function confirmGenerateQuestions() {
  generating.value = true
  showGenerateDialog.value = false

  router.post(
    route('policies.quiz.generate', props.policy.uuid),
    { count: generateCount.value },
    {
      preserveScroll: true,
      onFinish: () => { generating.value = false },
    }
  )
}

function closeDeleteDialog() {
  showDeleteDialog.value = false
  questionToDelete.value = null
}

function confirmDeleteQuestion() {
  if (!questionToDelete.value) return

  router.delete(
    route('policies.quiz.questions.destroy', { policy: props.policy.uuid, question: questionToDelete.value.id }),
    {
      preserveScroll: true,
      onFinish: closeDeleteDialog,
    }
  )
}

function generateQuestions() {
  openGenerateDialog()
}

// ── Helpers ───────────────────────────────────────────────────────────────────

const typeLabel: Record<QuestionType, string> = {
  multiple_choice: 'Multiple Choice',
  true_false:      'True / False',
  short_answer:    'Short Answer',
}

const typeColor: Record<QuestionType, string> = {
  multiple_choice: 'primary',
  true_false:      'info',
  short_answer:    'secondary',
}

const questionHeaders = [
  { title: 'Question', key: 'question_text' },
  { title: 'Type', key: 'type' },
  { title: 'Answer', key: 'correct_answer' },
  { title: 'Points', key: 'points' },
  { title: 'Source', key: 'source' },
  { title: 'Actions', key: 'actions', sortable: false, align: 'end', width: 180 },
]

const trueFalseOptions: Option[] = [
  { label: 'True',  value: 'true' },
  { label: 'False', value: 'false' },
]
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">

    <!-- ── Page header ─────────────────────────────────────────────────────── -->
    <div class="d-flex align-center justify-space-between flex-wrap gap-2 mb-6">
      <div>
        <h3 class="text-h5 font-weight-semibold mb-1">Policy Quiz</h3>
        <p class="text-medium-emphasis mb-0">Manage questions, settings, and AI generation for {{ policy.name }}</p>
      </div>
      <VBtn variant="tonal" color="secondary" @click="router.visit(route('policies.show', policy.uuid))">
        <i class="fa-solid fa-arrow-left me-2" /> Back to Policy
      </VBtn>
    </div>

    <!-- ── Stat cards ──────────────────────────────────────────────────────── -->
    <VRow class="mb-6">
      <VCol cols="6" sm="3">
        <VCard color="primary" variant="tonal" elevation="0" border rounded="lg">
          <VCardText class="pa-4">
            <div class="text-caption text-medium-emphasis">Total Attempts</div>
            <div class="text-h5 font-weight-semibold">{{ stats.total_attempts }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard color="success" variant="tonal" elevation="0" border rounded="lg">
          <VCardText class="pa-4">
            <div class="text-caption text-medium-emphasis">Pass Rate</div>
            <div class="text-h5 font-weight-semibold">{{ stats.pass_rate }}%</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard color="info" variant="tonal" elevation="0" border rounded="lg">
          <VCardText class="pa-4">
            <div class="text-caption text-medium-emphasis">Avg Score</div>
            <div class="text-h5 font-weight-semibold">{{ stats.avg_score }}%</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard color="secondary" variant="tonal" elevation="0" border rounded="lg">
          <VCardText class="pa-4">
            <div class="text-caption text-medium-emphasis">Participants</div>
            <div class="text-h5 font-weight-semibold">{{ stats.unique_participants }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Quiz Settings (horizontal compact) ─────────────────────────────── -->
    <VCard elevation="0" border rounded="lg" class="mb-6">
      <VCardItem>
        <VCardTitle class="text-subtitle-1 font-weight-semibold">Quiz Settings</VCardTitle>
      </VCardItem>
      <VDivider />
      <VCardText>
        <VForm @submit.prevent="saveSettings">
          <VRow dense align="center">
            <VCol cols="12" sm="6" md="2">
              <AppTextField
                v-model.number="settingsForm.pass_mark"
                label="Pass Mark (%)"
                type="number"
                min="0"
                max="100"
                :error-messages="settingsForm.errors.pass_mark"
              />
            </VCol>
            <VCol cols="12" sm="6" md="2">
              <AppTextField
                v-model.number="settingsForm.max_attempts"
                label="Max Attempts (0 = unlimited)"
                type="number"
                min="0"
                :error-messages="settingsForm.errors.max_attempts"
              />
            </VCol>
            <VCol cols="12" sm="6" md="2">
              <AppTextField
                v-model.number="settingsForm.time_limit_minutes"
                label="Time Limit (min)"
                type="number"
                min="1"
                :error-messages="settingsForm.errors.time_limit_minutes"
                clearable
              />
            </VCol>
            <VCol cols="12" sm="6" md="4">
              <VCheckbox v-model="settingsForm.randomize_questions" label="Randomise question order" hide-details density="compact" />
              <VCheckbox v-model="settingsForm.show_correct_answers" label="Show correct answers after attempt" hide-details density="compact" />
            </VCol>
            <VCol cols="12" md="2" class="d-flex justify-end">
              <VBtn type="submit" color="primary" :loading="settingsForm.processing">
                <i class="fa-solid fa-floppy-disk me-2" /> Save Settings
              </VBtn>
            </VCol>
          </VRow>
        </VForm>
      </VCardText>
    </VCard>

    <!-- ── Questions table (Sneat Row Editing via Dialog) ─────────────────── -->
    <VCard elevation="0" border rounded="lg">

      <!-- card toolbar -->
      <VCardText class="d-flex align-center flex-wrap gap-3">
        <div class="text-subtitle-1 font-weight-semibold me-auto">
          Questions ({{ questions.length }})
        </div>

        <AppTextField
          v-model="questionSearch"
          placeholder="Search questions…"
          prepend-inner-icon="bx-search"
          density="compact"
          hide-details
          style="max-inline-size: 240px;"
        />

        <div class="d-flex align-center gap-2">
          <span class="text-body-2 text-medium-emphasis">AI count</span>
          <AppTextField
            v-model.number="generateCount"
            type="number"
            min="1"
            max="30"
            hide-details
            density="compact"
            style="max-inline-size: 72px;"
          />
        </div>

        <VBtn variant="tonal" color="warning" :loading="generating" @click="openGenerateDialog">
          <i class="fa-solid fa-robot me-2" /> AI Generate
        </VBtn>

        <VBtn color="primary" @click="openAddDialog">
          <i class="fa-solid fa-plus me-2" /> Add Question
        </VBtn>
      </VCardText>

      <VDivider />

      <VAlert v-if="!questions.length" type="info" variant="tonal" class="ma-4">
        No questions yet. Click <strong>Add Question</strong> to add one manually, or use <strong>AI Generate</strong>.
      </VAlert>

      <VDataTable
        v-else
        :headers="questionHeaders"
        :items="questions"
        :search="questionSearch"
        item-value="id"
        hover
      >
        <!-- question text -->
        <template #item.question_text="{ item }">
          <div class="text-wrap py-2" style="max-inline-size: 30rem; white-space: normal;">
            {{ item.question_text }}
          </div>
        </template>

        <!-- type chip -->
        <template #item.type="{ item }">
          <VChip :color="typeColor[item.type]" variant="tonal" size="small" label>
            {{ typeLabel[item.type] }}
          </VChip>
        </template>

        <!-- answer -->
        <template #item.correct_answer="{ item }">
          <span class="font-weight-medium">{{ item.correct_answer }}</span>
        </template>

        <!-- points -->
        <template #item.points="{ item }">
          <VChip color="primary" variant="outlined" size="small">{{ item.points }} pt</VChip>
        </template>

        <!-- source -->
        <template #item.source="{ item }">
          <VChip v-if="item.ai_generated" color="warning" variant="tonal" size="small" label>
            <i class="fa-solid fa-robot me-1" /> AI
          </VChip>
          <VChip v-else color="success" variant="tonal" size="small" label>
            <i class="fa-solid fa-user me-1" /> Manual
          </VChip>
        </template>

        <!-- actions — Sneat Row Editing via Dialog pattern -->
        <template #item.actions="{ item }">
          <div class="d-flex gap-1 justify-end">
            <VTooltip text="View Question" location="top">
              <template #activator="{ props: tip }">
                <IconBtn v-bind="tip" color="info" @click="openViewDialog(item)">
                  <i class="fa-solid fa-eye" />
                </IconBtn>
              </template>
            </VTooltip>
            <VTooltip text="Edit Question" location="top">
              <template #activator="{ props: tip }">
                <IconBtn v-bind="tip" color="primary" @click="openEditDialog(item)">
                  <i class="fa-solid fa-pen-to-square" />
                </IconBtn>
              </template>
            </VTooltip>
            <VTooltip text="Delete" location="top">
              <template #activator="{ props: tip }">
                <IconBtn v-bind="tip" color="error" @click="deleteQuestion(item)">
                  <i class="fa-solid fa-trash" />
                </IconBtn>
              </template>
            </VTooltip>
          </div>
        </template>
      </VDataTable>
    </VCard>

  <!-- ── View Question Dialog ─────────────────────────────────────────────── -->
  <VDialog v-model="showViewDialog" max-width="720">
    <VCard>
      <VCardItem>
        <VCardTitle>Question Details</VCardTitle>
        <template #append>
          <VBtn icon variant="text" @click="closeViewDialog">
            <i class="fa-solid fa-xmark" />
          </VBtn>
        </template>
      </VCardItem>
      <VDivider />
      <VCardText v-if="viewingQuestion">
        <VRow dense>
          <VCol cols="12">
            <div class="text-caption text-medium-emphasis mb-1">Question</div>
            <div class="text-body-1">{{ viewingQuestion.question_text }}</div>
          </VCol>

          <VCol cols="12" sm="4">
            <div class="text-caption text-medium-emphasis mb-1">Type</div>
            <VChip :color="typeColor[viewingQuestion.type]" variant="tonal" size="small" label>
              {{ typeLabel[viewingQuestion.type] }}
            </VChip>
          </VCol>

          <VCol cols="12" sm="4">
            <div class="text-caption text-medium-emphasis mb-1">Correct Answer</div>
            <div class="text-body-2 font-weight-medium">{{ viewingQuestion.correct_answer }}</div>
          </VCol>

          <VCol cols="12" sm="4">
            <div class="text-caption text-medium-emphasis mb-1">Points</div>
            <VChip color="primary" variant="outlined" size="small">{{ viewingQuestion.points }} pt</VChip>
          </VCol>

          <VCol v-if="viewingQuestion.options?.length" cols="12">
            <div class="text-caption text-medium-emphasis mb-1">Options</div>
            <VList density="compact" border rounded>
              <VListItem
                v-for="(opt, idx) in viewingQuestion.options"
                :key="`view-opt-${idx}`"
                :title="`${opt.label}: ${opt.value}`"
              />
            </VList>
          </VCol>

          <VCol cols="12">
            <div class="text-caption text-medium-emphasis mb-1">Explanation</div>
            <div class="text-body-2">{{ viewingQuestion.explanation || '—' }}</div>
          </VCol>
        </VRow>
      </VCardText>
      <VDivider />
      <VCardText class="d-flex justify-end gap-3 py-3">
        <VBtn variant="outlined" color="secondary" @click="closeViewDialog">Close</VBtn>
        <VBtn color="primary" variant="elevated" @click="editFromViewDialog">
          <i class="fa-solid fa-pen-to-square me-2" /> Edit
        </VBtn>
      </VCardText>
    </VCard>
  </VDialog>

  <!-- ── Add / Edit Question Dialog ─────────────────────────────────────────── -->
  <VDialog v-model="showAddDialog" max-width="720" persistent>
    <VCard :title="editingQuestion ? 'Edit Question' : 'Add Question'">
      <VCardText class="mt-2">
        <VRow dense>
          <VCol cols="12">
            <AppTextarea
              v-model="questionForm.question_text"
              label="Question Text"
              rows="3"
              auto-grow
              :error-messages="questionForm.errors.question_text"
            />
          </VCol>

          <VCol cols="12" sm="6">
            <AppSelect
              v-model="questionForm.type"
              :items="[
                { title: 'Multiple Choice', value: 'multiple_choice' },
                { title: 'True / False',    value: 'true_false' },
                { title: 'Short Answer',    value: 'short_answer' },
              ]"
              label="Question Type"
              :error-messages="questionForm.errors.type"
            />
          </VCol>

          <VCol cols="12" sm="6">
            <AppTextField
              v-model.number="questionForm.points"
              label="Points"
              type="number"
              min="1"
              max="10"
              :error-messages="questionForm.errors.points"
            />
          </VCol>

          <!-- Multiple choice options -->
          <template v-if="questionForm.type === 'multiple_choice'">
            <VCol cols="12">
              <div class="text-body-2 mb-2 font-weight-medium">Answer Options</div>
              <VRow v-for="(opt, idx) in questionForm.options" :key="idx" dense class="mb-1">
                <VCol cols="2">
                  <AppTextField :model-value="opt.label" label="Label" readonly hide-details />
                </VCol>
                <VCol cols="10">
                  <AppTextField v-model="opt.value" :label="`Option ${opt.label}`" hide-details />
                </VCol>
              </VRow>
            </VCol>
          </template>

          <!-- Correct answer -->
          <VCol cols="12" sm="6">
            <AppSelect
              v-if="questionForm.type === 'true_false'"
              v-model="questionForm.correct_answer"
              :items="[{ title: 'True', value: 'true' }, { title: 'False', value: 'false' }]"
              label="Correct Answer"
              :error-messages="questionForm.errors.correct_answer"
            />
            <AppSelect
              v-else-if="questionForm.type === 'multiple_choice'"
              v-model="questionForm.correct_answer"
              :items="questionForm.options.map(o => ({ title: `${o.label}: ${o.value}`, value: o.label }))"
              label="Correct Answer"
              :error-messages="questionForm.errors.correct_answer"
            />
            <AppTextField
              v-else
              v-model="questionForm.correct_answer"
              label="Correct Answer (key phrase)"
              :error-messages="questionForm.errors.correct_answer"
            />
          </VCol>

          <VCol cols="12">
            <AppTextarea
              v-model="questionForm.explanation"
              label="Explanation (shown after attempt)"
              rows="2"
              auto-grow
              :error-messages="questionForm.errors.explanation"
            />
          </VCol>
        </VRow>
      </VCardText>

      <VDivider />
      <VCardText class="d-flex justify-end gap-3 py-3">
        <VBtn variant="outlined" color="secondary" @click="closeDialog">Cancel</VBtn>
        <VBtn color="primary" variant="elevated" @click="saveQuestion">
          <i class="fa-solid fa-floppy-disk me-2" />
          {{ editingQuestion ? 'Update Question' : 'Add Question' }}
        </VBtn>
      </VCardText>
    </VCard>
  </VDialog>

  <!-- ── AI Generate Confirmation Dialog ────────────────────────────────────── -->
  <VDialog v-model="showGenerateDialog" max-width="520" persistent>
    <VCard>
      <VCardText class="d-flex flex-column align-center text-center pa-6">
        <VAvatar color="warning" variant="tonal" size="56" class="mb-4">
          <i class="fa-solid fa-robot fa-lg" />
        </VAvatar>
        <h5 class="text-h5 mb-2">Generate AI Questions</h5>
        <p class="text-medium-emphasis mb-0">
          Generate <strong>{{ generateCount }}</strong> AI questions for
          <strong>{{ policy.name }}</strong>. New questions will be added to the existing list.
        </p>
      </VCardText>
      <VCardText class="d-flex justify-center gap-3 pt-0 pb-6">
        <VBtn variant="outlined" color="secondary" @click="closeGenerateDialog">Cancel</VBtn>
        <VBtn color="warning" variant="elevated" :loading="generating" @click="confirmGenerateQuestions">
          <i class="fa-solid fa-robot me-2" /> Generate
        </VBtn>
      </VCardText>
    </VCard>
  </VDialog>

  <!-- ── Delete Confirmation Dialog ─────────────────────────────────────────── -->
  <VDialog v-model="showDeleteDialog" max-width="500" persistent>
    <VCard>
      <VCardText class="d-flex flex-column align-center text-center pa-6">
        <VAvatar color="error" variant="tonal" size="56" class="mb-4">
          <i class="fa-solid fa-triangle-exclamation fa-lg" />
        </VAvatar>
        <h5 class="text-h5 mb-2">Delete Question?</h5>
        <p class="text-medium-emphasis mb-3">This action cannot be undone.</p>
        <VAlert v-if="questionToDelete" type="warning" variant="tonal" density="compact" class="w-100 text-start">
          {{ questionToDelete.question_text }}
        </VAlert>
      </VCardText>
      <VCardText class="d-flex justify-center gap-3 pt-0 pb-6">
        <VBtn variant="outlined" color="secondary" @click="closeDeleteDialog">Cancel</VBtn>
        <VBtn color="error" variant="elevated" @click="confirmDeleteQuestion">
          <i class="fa-solid fa-trash me-2" /> Delete
        </VBtn>
      </VCardText>
    </VCard>
  </VDialog>

  </VContainer>
</template>
