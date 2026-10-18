<template>
  <VContainer fluid class="pa-6">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('pa.review360.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">{{ t('pa.review360.subtitle') }}</p>
      </div>
      <div class="d-flex ga-2">
        <VBtn variant="tonal" prepend-icon="tabler-help-circle" @click="questionTab = true">
          {{ t('pa.review360.select_questions') }}
        </VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" @click="openCreate">
          {{ t('pa.review360.new') }}
        </VBtn>
      </div>
    </div>

    <!-- Reviews grid -->
    <VRow v-if="reviews.length" dense>
      <VCol v-for="r in reviews" :key="r.id" cols="12" sm="6" xl="4">
        <VCard border flat>
          <VCardItem>
            <template #prepend>
              <VAvatar :color="statusColor(r.status)" variant="tonal" size="38">
                <VIcon size="18">tabler-users</VIcon>
              </VAvatar>
            </template>
            <VCardTitle class="text-body-1 font-weight-medium">{{ r.title }}</VCardTitle>
            <VCardSubtitle>{{ r.employee_name }}</VCardSubtitle>
            <template #append>
              <VChip size="x-small" :color="statusColor(r.status)" variant="tonal">
                {{ r.status }}
              </VChip>
            </template>
          </VCardItem>

          <VCardText class="pb-0">
            <VProgressLinear
              :model-value="r.completion_rate"
              color="primary"
              height="6"
              rounded
              class="mb-1"
            />
            <div class="d-flex justify-space-between text-caption text-medium-emphasis">
              <span>{{ r.submitted_count }}/{{ r.raters_count }} raters submitted</span>
              <span>{{ r.completion_rate }}%</span>
            </div>
          </VCardText>

          <VCardActions class="px-4 pb-3 pt-2">
            <span class="text-caption text-medium-emphasis">
              {{ r.due_date ? `Due: ${r.due_date}` : '' }}
              {{ r.cycle_name ? ` · ${r.cycle_name}` : '' }}
            </span>
            <VSpacer />
            <VBtn
              size="x-small"
              variant="tonal"
              color="primary"
              :href="route('performance.360.show', r.id)"
              @click.prevent="router.visit(route('performance.360.show', r.id))"
            >
              Manage
            </VBtn>
            <VBtn
              size="x-small"
              icon
              variant="text"
              color="error"
              class="ml-1"
              @click="openDelete(r)"
            >
              <VIcon>tabler-trash</VIcon>
            </VBtn>
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>

    <VAlert v-else type="info" variant="tonal" class="mt-2">
      {{ t('pa.common.no_data') }}
    </VAlert>

    <!-- ── Create drawer ─────────────────────────────────────────────────── -->
    <VNavigationDrawer v-model="createOpen" location="right" width="520" temporary>
      <VToolbar color="primary" density="compact">
        <VToolbarTitle class="text-body-1">{{ t('pa.review360.new') }}</VToolbarTitle>
        <template #append>
          <VBtn icon @click="createOpen = false"><VIcon>tabler-x</VIcon></VBtn>
        </template>
      </VToolbar>

      <VForm ref="createForm" @submit.prevent="submitCreate" class="pa-4">
        <VTextField
          v-model="form.title"
          label="Title"
          density="compact"
          variant="outlined"
          :rules="[v => !!v || 'Required']"
          class="mb-3"
        />
        <VSelect
          v-model="form.employee_id"
          :items="employees"
          item-title="name"
          item-value="id"
          :label="t('pa.review360.subject_employee')"
          density="compact"
          variant="outlined"
          :rules="[v => !!v || 'Required']"
          class="mb-3"
        />
        <VSelect
          v-model="form.cycle_id"
          :items="cycles"
          item-title="name"
          item-value="id"
          label="Cycle (optional)"
          density="compact"
          variant="outlined"
          clearable
          class="mb-3"
        />
        <VTextField
          v-model="form.due_date"
          label="Due Date"
          type="date"
          density="compact"
          variant="outlined"
          class="mb-3"
        />
        <VTextarea
          v-model="form.instructions"
          :label="t('pa.review360.instructions')"
          density="compact"
          variant="outlined"
          rows="3"
          class="mb-3"
        />
        <VRow dense class="mb-3">
          <VCol cols="6">
            <VTextField
              v-model.number="form.min_raters"
              :label="t('pa.review360.min_raters')"
              type="number"
              density="compact"
              variant="outlined"
            />
          </VCol>
          <VCol cols="6">
            <VTextField
              v-model.number="form.max_raters"
              :label="t('pa.review360.max_raters')"
              type="number"
              density="compact"
              variant="outlined"
            />
          </VCol>
        </VRow>

        <p class="text-caption text-medium-emphasis mb-2">Questions (leave empty to use all active questions)</p>
        <VSelect
          v-model="form.question_ids"
          :items="questions"
          item-title="question"
          item-value="id"
          :label="t('pa.review360.select_questions')"
          density="compact"
          variant="outlined"
          multiple
          chips
          closable-chips
          class="mb-3"
        >
          <template #item="{ item, props: iProps }">
            <VListItem v-bind="iProps">
              <template #append>
                <VChip size="x-small" color="secondary" variant="tonal">{{ item.raw.category }}</VChip>
              </template>
            </VListItem>
          </template>
        </VSelect>

        <VSwitch
          v-model="form.require_anonymity"
          :label="t('pa.review360.require_anonymity')"
          density="compact"
          color="primary"
          class="mb-4"
        />

        <VBtn type="submit" color="primary" block :loading="saving">
          {{ t('pa.review360.new') }}
        </VBtn>
      </VForm>
    </VNavigationDrawer>

    <!-- ── Question Bank dialog ──────────────────────────────────────────── -->
    <VDialog v-model="questionTab" max-width="800">
      <VCard>
        <VToolbar color="secondary" density="compact">
          <VToolbarTitle class="text-body-1">{{ t('pa.review360.select_questions') }}</VToolbarTitle>
          <template #append>
            <VBtn icon @click="questionTab = false"><VIcon>tabler-x</VIcon></VBtn>
          </template>
        </VToolbar>

        <VCardText class="pa-4">
          <!-- Add question form -->
          <VForm ref="qForm" @submit.prevent="submitQuestion" class="mb-4">
            <VRow dense>
              <VCol cols="12">
                <VTextarea
                  v-model="qFormData.question"
                  label="Question"
                  density="compact"
                  variant="outlined"
                  rows="2"
                  :rules="[v => !!v || 'Required']"
                />
              </VCol>
              <VCol cols="12" sm="4">
                <VSelect
                  v-model="qFormData.category"
                  :items="questionCategories"
                  label="Category"
                  density="compact"
                  variant="outlined"
                  :rules="[v => !!v || 'Required']"
                />
              </VCol>
              <VCol cols="12" sm="4">
                <VSelect
                  v-model="qFormData.question_type"
                  :items="['rating', 'text', 'both']"
                  label="Type"
                  density="compact"
                  variant="outlined"
                  :rules="[v => !!v || 'Required']"
                />
              </VCol>
              <VCol cols="12" sm="4" class="d-flex align-center">
                <VBtn type="submit" color="primary" :loading="savingQ" block>
                  {{ editQuestion ? 'Update Question' : 'Add Question' }}
                </VBtn>
              </VCol>
            </VRow>
          </VForm>

          <VDivider class="mb-3" />

          <!-- Question list by category -->
          <div v-for="(qs, cat) in groupedQuestions" :key="cat" class="mb-3">
            <p class="text-caption font-weight-bold text-uppercase text-medium-emphasis mb-1">{{ cat }}</p>
            <VList density="compact" class="pa-0">
              <VListItem
                v-for="q in qs"
                :key="q.id"
                :subtitle="q.question_type"
                class="mb-1 border"
              >
                <template #title>
                  <span class="text-body-2">{{ q.question }}</span>
                </template>
                <template #append>
                  <div class="d-flex align-center ga-1">
                    <VIcon
                      :color="q.is_active ? 'success' : 'error'"
                      size="14"
                    >
                      {{ q.is_active ? 'tabler-circle-check' : 'tabler-circle-x' }}
                    </VIcon>
                    <VBtn icon size="x-small" variant="text" @click="openEditQuestion(q)">
                      <VIcon>tabler-pencil</VIcon>
                    </VBtn>
                    <VBtn icon size="x-small" variant="text" color="error" @click="openDelQuestion(q)">
                      <VIcon>tabler-trash</VIcon>
                    </VBtn>
                  </div>
                </template>
              </VListItem>
            </VList>
          </div>
        </VCardText>
      </VCard>
    </VDialog>

    <!-- Delete review -->
    <VDialog v-model="delOpen" max-width="400">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold pa-4">{{ t('pa.common.delete') }}</VCardTitle>
        <VCardText>{{ t('pa.common.cannot_undo') }}</VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="delOpen = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="error" variant="flat" :loading="deleting" @click="confirmDelete">{{ t('pa.common.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Delete question -->
    <VDialog v-model="delQOpen" max-width="400">
      <VCard>
        <VCardTitle class="text-body-1 font-weight-bold pa-4">{{ t('pa.common.delete') }}</VCardTitle>
        <VCardText>{{ t('pa.common.cannot_undo') }}</VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="delQOpen = false">{{ t('pa.common.cancel') }}</VBtn>
          <VBtn color="error" variant="flat" :loading="deletingQ" @click="confirmDelQuestion">{{ t('pa.common.delete') }}</VBtn>
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

interface Review {
  id: number; title: string; employee_id: number; employee_name: string
  cycle_name: string | null; due_date: string | null; status: string
  raters_count: number; submitted_count: number; completion_rate: number
  require_anonymity: boolean; has_ai_summary: boolean; cycle_id: number | null
}

interface Question {
  id: number; question: string; category: string; question_type: string
  is_active: boolean; sort_order: number
}

interface Employee { id: number; name: string }
interface Cycle    { id: number; name: string }

const props = defineProps<{
  reviews:   Review[]
  cycles:    Cycle[]
  employees: Employee[]
  questions: Question[]
}>()

// ── Reviews ──────────────────────────────────────────────────────────────────
const createOpen = ref(false)
const saving     = ref(false)
const createForm = ref()

const form = reactive({
  title: '', employee_id: null as number | null, cycle_id: null as number | null,
  due_date: '', instructions: '', require_anonymity: false,
  min_raters: 3, max_raters: 10,
  question_ids: [] as number[],
})

function openCreate() {
  Object.assign(form, {
    title: '', employee_id: null, cycle_id: null, due_date: '', instructions: '',
    require_anonymity: false, min_raters: 3, max_raters: 10, question_ids: [],
  })
  createOpen.value = true
}

async function submitCreate() {
  const { valid } = await createForm.value.validate()
  if (!valid) return
  saving.value = true
  router.post(route('performance.360.store'), form as any, {
    onSuccess: () => { createOpen.value = false },
    onFinish:  () => { saving.value = false },
  })
}

const delOpen  = ref(false)
const delItem  = ref<Review | null>(null)
const deleting = ref(false)

function openDelete(r: Review) { delItem.value = r; delOpen.value = true }
function confirmDelete() {
  if (!delItem.value) return
  deleting.value = true
  router.delete(route('performance.360.destroy', delItem.value.id), {
    onSuccess: () => { delOpen.value = false },
    onFinish:  () => { deleting.value = false },
  })
}

// ── Question Bank ─────────────────────────────────────────────────────────────
const questionTab   = ref(false)
const savingQ       = ref(false)
const qForm         = ref()
const editQuestion  = ref<Question | null>(null)
const delQOpen      = ref(false)
const delQItem      = ref<Question | null>(null)
const deletingQ     = ref(false)

const qFormData = reactive({
  question: '', category: '', question_type: 'rating', is_active: true, sort_order: 0,
})

const questionCategories = ['leadership', 'teamwork', 'communication', 'technical', 'culture', 'general']

const groupedQuestions = computed(() => {
  const groups: Record<string, Question[]> = {}
  for (const q of props.questions) {
    if (!groups[q.category]) groups[q.category] = []
    groups[q.category].push(q)
  }
  return groups
})

function openEditQuestion(q: Question) {
  editQuestion.value = q
  Object.assign(qFormData, {
    question: q.question, category: q.category, question_type: q.question_type,
    is_active: q.is_active, sort_order: q.sort_order,
  })
}

async function submitQuestion() {
  const { valid } = await qForm.value.validate()
  if (!valid) return
  savingQ.value = true

  if (editQuestion.value) {
    router.patch(route('performance.360.questions.update', editQuestion.value.id), qFormData, {
      onSuccess: () => { editQuestion.value = null; Object.assign(qFormData, { question: '', category: '', question_type: 'rating' }) },
      onFinish:  () => { savingQ.value = false },
    })
  } else {
    router.post(route('performance.360.questions.store'), qFormData, {
      onSuccess: () => { Object.assign(qFormData, { question: '', category: '', question_type: 'rating' }) },
      onFinish:  () => { savingQ.value = false },
    })
  }
}

function openDelQuestion(q: Question) { delQItem.value = q; delQOpen.value = true }
function confirmDelQuestion() {
  if (!delQItem.value) return
  deletingQ.value = true
  router.delete(route('performance.360.questions.destroy', delQItem.value.id), {
    onSuccess: () => { delQOpen.value = false },
    onFinish:  () => { deletingQ.value = false },
  })
}

// Helper
function statusColor(status: string) {
  return { draft: 'grey', collecting: 'blue', closed: 'orange', reported: 'green' }[status] ?? 'grey'
}
</script>
