<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import axios from 'axios'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

// ── Types ─────────────────────────────────────────────────────────────────────

type Material = {
  id: number; title: string; type: string; file_name: string; size_mb: number; url: string
}

type Lesson = {
  id: number; title: string; type: string; content_body: string | null
  external_url: string | null; duration_mins: number; is_preview: boolean
  is_completed: boolean; materials: Material[]
}

type Module = {
  id: number; title: string; description: string | null; sort_order: number; lessons: Lesson[]
}

type LegacyContent = {
  id: number; type: string; title: string; body: string | null
  url: string | null; file_path: string | null; sort_order: number; duration_mins: number | null
}

type QuizOption   = { id: number; option_text: string; sort_order: number }
type QuizQuestion = { id: number; question: string; type: string; options: QuizOption[] }

type Course = {
  id: number; uuid: string; title: string; description: string | null
  cover_image: string | null; type: string; duration_mins: number | null
  pass_score: number | null; expires_after_days: number | null
  is_mandatory: boolean
  category: { id: number; name: string; color: string } | null
  contents: LegacyContent[]
  modules: Module[]
  has_quiz: boolean
  quiz_questions: QuizQuestion[]
}

type Enrollment = {
  id: number; uuid: string; status: string; progress_pct: number; score: number | null
  enrolled_at: string | null; completed_at: string | null
} | null

type Certificate = {
  id: number; certificate_number: string; issued_at: string | null; expires_at: string | null
} | null

const props = defineProps<{
  course: Course
  enrollment: Enrollment
  certificate: Certificate
}>()

// ── Whether we have Phase-2 module structure ──────────────────────────────────
const hasModules = computed(() => props.course.modules.length > 0)

// ── Expanded modules state ────────────────────────────────────────────────────
const expanded = ref<Set<number>>(new Set(props.course.modules.map(m => m.id)))
function toggle(id: number) {
  if (expanded.value.has(id)) expanded.value.delete(id)
  else expanded.value.add(id)
}

// ── Progress calc ─────────────────────────────────────────────────────────────
const totalLessons     = computed(() => props.course.modules.reduce((s, m) => s + m.lessons.length, 0))
const completedLessons = computed(() => props.course.modules.reduce((s, m) => s + m.lessons.filter(l => l.is_completed).length, 0))
const lessonProgress   = computed(() =>
  totalLessons.value > 0 ? Math.round((completedLessons.value / totalLessons.value) * 100) : 0
)

// ── Navigate to learn page ────────────────────────────────────────────────────
function startLearning() {
  for (const mod of props.course.modules) {
    for (const lesson of mod.lessons) {
      if (!lesson.is_completed) {
        router.visit(route('lms.courses.learn', { course: props.course.uuid, lesson: lesson.id }))
        return
      }
    }
  }
  const first = props.course.modules[0]?.lessons[0]
  if (first) router.visit(route('lms.courses.learn', { course: props.course.uuid, lesson: first.id }))
}

function openLesson(lesson: Lesson) {
  if (!props.enrollment && !lesson.is_preview) return
  router.visit(route('lms.courses.learn', { course: props.course.uuid, lesson: lesson.id }))
}

// ── Enroll ────────────────────────────────────────────────────────────────────
const enrolling     = ref(false)
const prereqError   = ref<string | null>(null)

async function enroll() {
  enrolling.value  = true
  prereqError.value = null
  try {
    await axios.post(
      route('lms.courses.enroll', { course: props.course.uuid }),
      {},
      { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
    )
    router.reload()
  } catch (err: any) {
    const data = err.response?.data
    if (data?.error === 'prerequisite_not_met') {
      prereqError.value = data.message
    } else if (err.response?.status === 404) {
      prereqError.value = t('lms_course_detail.prerequisite_error')
    } else {
      prereqError.value = data?.message ?? t('lms_course_detail.enroll_failed')
    }
  } finally {
    enrolling.value = false
  }
}

// ── Legacy progress update ─────────────────────────────────────────────────
function updateLegacyProgress(pct: number) {
  if (!props.enrollment) return
  fetch(route('lms.enrollments.progress', { enrollment: props.enrollment.uuid }), {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    body: JSON.stringify({ progress_pct: pct }),
  })
}

// ── Quiz ──────────────────────────────────────────────────────────────────────
const quizMode   = ref(false)
const submitting = ref(false)
const quizResult = ref<{ score: number; passed: boolean; pass_score: number } | null>(null)
const answers    = ref<Record<number, number[]>>({})

function startQuiz() { quizMode.value = true; answers.value = {}; quizResult.value = null }

onMounted(() => {
  const params = new URLSearchParams(window.location.search)
  if (params.get('quiz') === '1' && props.course.has_quiz && props.enrollment) startQuiz()
})

function toggleOption(questionId: number, optionId: number, type: string) {
  if (!answers.value[questionId]) answers.value[questionId] = []
  const idx = answers.value[questionId].indexOf(optionId)
  if (type === 'mcq' || type === 'true_false') {
    answers.value[questionId] = [optionId]
  } else {
    if (idx === -1) answers.value[questionId].push(optionId)
    else answers.value[questionId].splice(idx, 1)
  }
}

async function submitQuiz() {
  if (!props.enrollment) return
  submitting.value = true
  const payload = props.course.quiz_questions.map(q => ({
    question_id: q.id,
    option_ids:  answers.value[q.id] ?? [],
  }))
  try {
    const res = await fetch(route('lms.enrollments.quiz', { enrollment: props.enrollment.uuid }), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ answers: payload }),
    })
    const data = await res.json()
    quizResult.value = { score: data.score, passed: data.passed, pass_score: data.pass_score }
    quizMode.value = false
    if (data.passed) router.reload()
  } finally {
    submitting.value = false
  }
}

const answeredCount = computed(() =>
  props.course.quiz_questions.filter(q => (answers.value[q.id]?.length ?? 0) > 0).length
)

// ── Helpers ───────────────────────────────────────────────────────────────────
function durationLabel(mins: number | null) {
  if (!mins) return ''
  if (mins < 60) return t('lms_course_detail.duration.minutes', { m: mins })
  const h = Math.floor(mins / 60)
  const m = mins % 60
  if (m) return t('lms_course_detail.duration.hours_minutes', { h, m })
  return t('lms_course_detail.duration.hours', { h })
}

function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}

const typeIcon: Record<string, string> = {
  video: 'tabler-video', pdf: 'tabler-file-type-pdf', document: 'tabler-file-text',
  presentation: 'tabler-presentation', text: 'tabler-align-left', link: 'tabler-external-link',
  quiz: 'tabler-help-circle',
}

const typeColor: Record<string, string> = {
  video: 'primary', pdf: 'error', document: 'secondary',
  presentation: 'warning', text: 'success', link: 'info',
}

const contentTypeIcon: Record<string, string> = {
  video: 'tabler-video', document: 'tabler-file-description', text: 'tabler-align-left',
  link: 'tabler-external-link', quiz: 'tabler-help-circle',
}

function typeLabel(type: string): string {
  const labels = {
    video: t('lms_course_detail.types.video'),
    pdf: t('lms_course_detail.types.pdf'),
    document: t('lms_course_detail.types.document'),
    presentation: t('lms_course_detail.types.presentation'),
    text: t('lms_course_detail.types.text'),
    link: t('lms_course_detail.types.link'),
    quiz: t('lms_course_detail.types.quiz')
  }
  return labels[type as keyof typeof labels] || type
}

function statusLabel(status: string): string {
  const labels = {
    enrolled: t('lms_course_detail.statuses.enrolled'),
    in_progress: t('lms_course_detail.statuses.in_progress'),
    completed: t('lms_course_detail.statuses.completed'),
    failed: t('lms_course_detail.statuses.failed')
  }
  return labels[status as keyof typeof labels] || status
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">

    <!-- Breadcrumb -->
    <div class="d-flex align-center gap-2 mb-5">
      <a class="text-medium-emphasis text-decoration-none text-body-2" href="#"
        @click.prevent="router.visit(route('lms.catalog'))">
        {{ t('lms_course_detail.breadcrumb') }}
      </a>
      <VIcon icon="tabler-chevron-right" size="14" class="text-medium-emphasis" />
      <span class="text-body-2">{{ course.title }}</span>
    </div>

    <!-- Prerequisite error -->
    <VAlert v-if="prereqError" type="warning" variant="tonal" rounded="lg" class="mb-5" closable @click:close="prereqError = null">
      {{ prereqError }}
    </VAlert>

    <VRow>
      <!-- ── Main content ──────────────────────────────────────────────────── -->
      <VCol cols="12" lg="8">

        <!-- Course header card -->
        <VCard class="mb-5" rounded="lg" elevation="0" border>
          <VCardText class="pa-5">
            <div class="d-flex align-center gap-2 mb-3">
              <VChip v-if="course.category" :color="course.category.color" size="small" variant="tonal">
                {{ course.category.name }}
              </VChip>
              <VChip v-if="course.is_mandatory" color="error" size="small" variant="tonal">
                {{ t('lms_course_detail.required') }}
              </VChip>
            </div>
            <h3 class="text-h3 mb-2">{{ course.title }}</h3>
            <p v-if="course.description" class="text-body-1 text-medium-emphasis mb-4">{{ course.description }}</p>
            <div class="d-flex gap-4 flex-wrap">
              <span v-if="course.duration_mins" class="text-body-2 d-flex align-center gap-1">
                <VIcon icon="tabler-clock" size="16" />
                {{ durationLabel(course.duration_mins) }}
              </span>
              <span v-if="course.pass_score" class="text-body-2 d-flex align-center gap-1">
                <VIcon icon="tabler-target" size="16" />
                {{ t('lms_course_detail.pass', { score: course.pass_score }) }}
              </span>
              <span v-if="hasModules" class="text-body-2 d-flex align-center gap-1">
                <VIcon icon="tabler-book" size="16" />
                {{ t('lms_course_detail.lessons_count', { count: totalLessons }) }}
              </span>
              <span v-if="course.expires_after_days" class="text-body-2 d-flex align-center gap-1">
                <VIcon icon="tabler-calendar-off" size="16" />
                {{ t('lms_course_detail.certificate_expires', { days: course.expires_after_days }) }}
              </span>
            </div>
          </VCardText>
        </VCard>

        <!-- ── Phase-2: Modules & Lessons ──────────────────────────────────── -->
        <template v-if="hasModules">

          <!-- Module accordion list -->
          <div class="d-flex flex-column gap-3">
            <VCard
              v-for="m in course.modules"
              :key="m.id"
              rounded="lg"
              elevation="0"
              border
            >
              <div
                class="d-flex align-center gap-3 pa-4 cursor-pointer"
                @click="toggle(m.id)"
              >
                <VIcon
                  :icon="expanded.has(m.id) ? 'tabler-chevron-down' : 'tabler-chevron-right'"
                  size="16"
                  class="text-medium-emphasis flex-shrink-0"
                />
                <div class="flex-1-1">
                  <p class="text-body-1 font-weight-semibold mb-0">{{ m.title }}</p>
                  <p v-if="m.description" class="text-caption text-medium-emphasis mb-0">{{ m.description }}</p>
                </div>
                <p class="text-caption text-medium-emphasis mb-0 flex-shrink-0">
                  {{ t('lms_course_detail.module_progress', { completed: m.lessons.filter(l => l.is_completed).length, total: m.lessons.length }) }}
                </p>
              </div>

              <VExpandTransition>
                <div v-if="expanded.has(m.id)">
                  <VDivider />
                  <VList density="compact" class="py-0">
                    <VListItem
                      v-for="lesson in m.lessons"
                      :key="lesson.id"
                      :class="(enrollment || lesson.is_preview) ? 'cursor-pointer' : ''"
                      @click="openLesson(lesson)"
                    >
                      <template #prepend>
                        <VAvatar :color="lesson.is_completed ? 'success' : (typeColor[lesson.type] ?? 'default')" variant="tonal" size="30" rounded>
                          <VIcon
                            :icon="lesson.is_completed ? 'tabler-circle-check' : (typeIcon[lesson.type] ?? 'tabler-file')"
                            size="16"
                          />
                        </VAvatar>
                      </template>
                      <VListItemTitle class="text-body-2">{{ lesson.title }}</VListItemTitle>
                      <VListItemSubtitle class="text-caption text-capitalize">
                        {{ typeLabel(lesson.type) }}
                        <span v-if="lesson.duration_mins"> · {{ durationLabel(lesson.duration_mins) }}</span>
                        <VChip v-if="lesson.is_preview && !enrollment" color="info" size="x-small" variant="tonal" class="ms-1">
                          {{ t('lms_course_detail.free') }}
                        </VChip>
                        <VChip v-if="!enrollment && !lesson.is_preview" color="default" size="x-small" variant="tonal" class="ms-1">
                          <VIcon icon="tabler-lock" size="10" />
                        </VChip>
                      </VListItemSubtitle>
                      <template #append>
                        <VIcon
                          v-if="lesson.is_completed"
                          icon="tabler-circle-check"
                          size="16"
                          color="success"
                        />
                        <VIcon
                          v-else-if="lesson.materials.length"
                          icon="tabler-paperclip"
                          size="14"
                          color="medium-emphasis"
                        />
                      </template>
                    </VListItem>
                  </VList>
                </div>
              </VExpandTransition>
            </VCard>
          </div>
        </template>

        <!-- ── Legacy: flat course contents ─────────────────────────────────── -->
        <template v-else-if="course.contents.length">
          <VCard class="mb-5" rounded="lg" elevation="0" border>
            <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">
              {{ t('lms_course_detail.course_content') }}
            </VCardTitle>
            <VDivider />
            <VList>
              <VListItem
                v-for="(item, idx) in course.contents"
                :key="item.id"
                :prepend-icon="contentTypeIcon[item.type] ?? 'tabler-file'"
                :subtitle="item.duration_mins ? durationLabel(item.duration_mins) : undefined"
              >
                <template #title>
                  <span class="text-body-2 font-weight-medium">{{ idx + 1 }}. {{ item.title }}</span>
                </template>
                <template #append>
                  <VBtn
                    v-if="item.url || item.file_path"
                    :href="item.url ?? item.file_path ?? '#'"
                    target="_blank"
                    icon="tabler-external-link"
                    size="small"
                    variant="text"
                    color="primary"
                    @click="enrollment && updateLegacyProgress(Math.min(Math.round(((idx + 1) / course.contents.length) * 100), 100))"
                  />
                </template>
              </VListItem>
            </VList>
          </VCard>
        </template>

        <!-- quiz result inline alert -->
        <VAlert
          v-if="quizResult"
          :type="quizResult.passed ? 'success' : 'error'"
          variant="tonal"
          class="mt-4"
          rounded="lg"
          closable
          @click:close="quizResult = null"
        >
          <template #title>{{ quizResult.passed ? t('lms_course_detail.passed') : t('lms_course_detail.not_quite') }}</template>
          {{ t('lms_course_detail.you_scored', { score: quizResult.score, pass_score: quizResult.pass_score }) }}
        </VAlert>

      </VCol>

      <!-- ── Sidebar ───────────────────────────────────────────────────────── -->
      <VCol cols="12" lg="4">
        <VCard rounded="lg" elevation="0" border class="sticky-sidebar">
          <VCardText class="pa-5">

            <!-- Not enrolled -->
            <template v-if="!enrollment">
              <p class="text-body-2 text-medium-emphasis mb-4">
                {{ t('lms_course_detail.enroll_now') }}
              </p>
              <VBtn color="primary" block :loading="enrolling" @click="enroll">
                {{ t('lms_course_detail.enroll_now') }}
              </VBtn>
            </template>

            <!-- Enrolled -->
            <template v-else>
              <!-- Lesson progress (Phase 2) -->
              <template v-if="hasModules && totalLessons > 0">
                <p class="text-body-2 font-weight-medium mb-1">{{ t('lms_course_detail.lesson_progress') }}</p>
                <div class="d-flex justify-space-between mb-1">
                  <span class="text-caption text-medium-emphasis">{{ t('lms_course_detail.completed_of', { completed: completedLessons, total: totalLessons }) }}</span>
                  <span class="text-body-2 font-weight-bold">{{ lessonProgress }}%</span>
                </div>
                <VProgressLinear
                  :model-value="lessonProgress"
                  :color="lessonProgress === 100 ? 'success' : 'primary'"
                  rounded
                  height="8"
                  class="mb-3"
                />
                <VBtn
                  color="primary"
                  block
                  :prepend-icon="lessonProgress > 0 ? 'tabler-player-play' : 'tabler-player-play'"
                  class="mb-4"
                  @click="startLearning"
                >
                  {{ lessonProgress > 0 ? t('lms_course_detail.continue_learning') : t('lms_course_detail.start_course') }}
                </VBtn>
              </template>

              <!-- Enrollment progress -->
              <div class="mb-4">
                <div class="d-flex justify-space-between mb-1">
                  <span class="text-body-2 font-weight-medium">{{ t('lms_course_detail.overall_progress') }}</span>
                  <span class="text-body-2 font-weight-bold">{{ enrollment.progress_pct }}%</span>
                </div>
                <VProgressLinear
                  :model-value="enrollment.progress_pct"
                  :color="enrollment.status === 'completed' ? 'success' : 'primary'"
                  rounded
                  height="10"
                />
              </div>

              <VChip
                :color="enrollment.status === 'completed' ? 'success' : enrollment.status === 'failed' ? 'error' : 'primary'"
                variant="tonal"
                class="mb-4"
                block
              >
                {{ statusLabel(enrollment.status).toUpperCase() }}
              </VChip>

              <p v-if="enrollment.score !== null" class="text-body-2 mb-3">
                {{ t('lms_course_detail.last_score', { score: enrollment.score }) }}
              </p>

              <!-- Quiz actions -->
              <template v-if="enrollment.status !== 'completed'">
                <VBtn                  v-if="course.has_quiz && enrollment.progress_pct >= 100"
                  color="success"
                  block
                  class="mb-3"
                  @click="startQuiz"
                >
                  {{ t('lms_course_detail.take_quiz') }}
                </VBtn>
                <VBtn
                  v-if="course.has_quiz && enrollment.status === 'failed'"
                  color="warning"
                  variant="tonal"
                  block
                  @click="startQuiz"
                >
                  {{ t('lms_course_detail.retry_quiz') }}
                </VBtn>
              </template>

              <!-- Certificate -->
              <VCard v-if="certificate" color="success" variant="tonal" rounded="lg" class="mt-4">
                <VCardText class="pa-3">
                  <div class="d-flex align-center gap-2">
                    <VIcon icon="tabler-certificate" size="22" color="success" />
                    <div>
                      <p class="text-body-2 font-weight-semibold mb-0">{{ t('lms_course_detail.certificate_earned') }}</p>
                      <p class="text-caption mb-0">{{ certificate.certificate_number }}</p>
                      <p class="text-caption mb-0">{{ t('lms_course_detail.issued', { date: formatDate(certificate.issued_at) }) }}</p>
                      <p v-if="certificate.expires_at" class="text-caption mb-0 text-error">
                        {{ t('lms_course_detail.expires', { date: formatDate(certificate.expires_at) }) }}
                      </p>
                    </div>
                  </div>
                </VCardText>
              </VCard>
            </template>

          </VCardText>
        </VCard>
      </VCol>
    </VRow>

  </VContainer>

  <!-- ── Quiz fullscreen dialog ────────────────────────────────────────────── -->
  <VDialog v-model="quizMode" fullscreen persistent transition="dialog-bottom-transition">
    <VCard rounded="0">
      <!-- Header -->
      <div class="d-flex align-center gap-3 pa-4 border-b"
           style="border-bottom:1px solid rgba(var(--v-border-color),var(--v-border-opacity));">
        <VBtn icon="tabler-x" variant="text" size="small" @click="quizMode = false" />
        <div class="flex-1-1">
          <p class="text-body-1 font-weight-semibold mb-0">{{ t('lms_course_detail.quiz_title', { title: course.title }) }}</p>
          <p class="text-caption text-medium-emphasis mb-0">
            {{ t('lms_course_detail.answered_count', { answered: answeredCount, total: course.quiz_questions.length }) }}
          </p>
        </div>
        <VBtn
          color="primary"
          :loading="submitting"
          :disabled="answeredCount < course.quiz_questions.length"
          prepend-icon="tabler-send"
          @click="submitQuiz"
        >
          {{ t('lms_course_detail.submit') }}
        </VBtn>
      </div>

      <!-- Questions -->
      <VCardText class="pa-5" style="max-width:760px; margin:0 auto;">
        <div v-for="(q, qi) in course.quiz_questions" :key="q.id" class="mb-8">
          <p class="text-body-1 font-weight-medium mb-3">{{ qi + 1 }}. {{ q.question }}</p>
          <div class="d-flex flex-column gap-2">
            <VCard
              v-for="opt in q.options"
              :key="opt.id"
              :color="answers[q.id]?.includes(opt.id) ? 'primary' : undefined"
              :variant="answers[q.id]?.includes(opt.id) ? 'tonal' : 'outlined'"
              class="cursor-pointer pa-4"
              rounded="lg"
              @click="toggleOption(q.id, opt.id, q.type)"
            >
              <div class="d-flex align-center gap-3">
                <VIcon
                  :icon="answers[q.id]?.includes(opt.id) ? 'tabler-circle-check' : 'tabler-circle'"
                  :color="answers[q.id]?.includes(opt.id) ? 'primary' : 'medium-emphasis'"
                  size="18"
                />
                <span class="text-body-2">{{ opt.option_text }}</span>
              </div>
            </VCard>
          </div>
        </div>

        <!-- Bottom submit -->
        <div class="d-flex justify-end mt-4 pb-6">
          <VBtn
            color="primary"
            size="large"
            :loading="submitting"
            :disabled="answeredCount < course.quiz_questions.length"
            prepend-icon="tabler-send"
            @click="submitQuiz"
          >
            {{ t('lms_course_detail.submit_quiz', { answered: answeredCount, total: course.quiz_questions.length }) }}
          </VBtn>
        </div>
      </VCardText>
    </VCard>
  </VDialog>
</template>

<style scoped>
.lesson-content {
  white-space: pre-wrap;
  line-height: 1.7;
}
.sticky-sidebar {
  position: sticky;
  top: 80px;
}
.cursor-pointer {
  cursor: pointer;
}
</style>
