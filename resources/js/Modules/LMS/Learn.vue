<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import axios from 'axios'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

defineOptions({ layout: null })

// ── Types ─────────────────────────────────────────────────────────────────────

type Material = { id: number; title: string; type: string; file_name: string; size_mb: number; url: string }

type LessonOutline = {
  id: number; title: string; type: string; duration_mins: number | null; is_completed: boolean
}
type ModuleOutline = { id: number; title: string; lessons: LessonOutline[] }

type Lesson = {
  id: number; title: string; type: string
  content_body: string | null; external_url: string | null
  duration_mins: number | null; is_preview: boolean; is_completed: boolean
  materials: Material[]
}

type Enrollment = { uuid: string; status: string; progress_pct: number } | null

const props = defineProps<{
  course:       { id: number; uuid: string; title: string; has_quiz: boolean; modules: ModuleOutline[] }
  enrollment:   Enrollment
  lesson:       Lesson
  prevLessonId: number | null
  nextLessonId: number | null
  lessonIndex:  number
  totalLessons: number
}>()

// ── Sidebar ───────────────────────────────────────────────────────────────────
const sidebarOpen   = ref(true)
const expandedMods  = ref<Set<number>>(new Set(props.course.modules.map(m => m.id)))

function toggleMod(id: number) {
  expandedMods.value.has(id) ? expandedMods.value.delete(id) : expandedMods.value.add(id)
}

// ── Completion ────────────────────────────────────────────────────────────────
const completing  = ref(false)
const isCompleted = ref(props.lesson.is_completed)

async function markComplete() {
  if (!props.enrollment || isCompleted.value || completing.value) return
  completing.value = true
  try {
    await axios.post(
      route('lms.enrollments.lessons.complete', { enrollment: props.enrollment.uuid, lesson: props.lesson.id }),
      {},
      { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
    )
    isCompleted.value = true
  } finally {
    completing.value = false
  }
}

// ── Navigation ────────────────────────────────────────────────────────────────
async function goNext() {
  if (!isCompleted.value) await markComplete()
  if (props.nextLessonId) {
    router.visit(route('lms.courses.learn', { course: props.course.uuid, lesson: props.nextLessonId }))
  }
}

function goPrev() {
  if (props.prevLessonId) {
    router.visit(route('lms.courses.learn', { course: props.course.uuid, lesson: props.prevLessonId }))
  }
}

function goToLesson(lessonId: number) {
  router.visit(route('lms.courses.learn', { course: props.course.uuid, lesson: lessonId }))
}

function backToCourse(openQuiz = false) {
  const url = route('lms.courses.show', { course: props.course.uuid })
  router.visit(openQuiz ? `${url}?quiz=1` : url)
}

// ── Helpers ───────────────────────────────────────────────────────────────────
const completedCount = computed(() =>
  props.course.modules.reduce((sum, m) => sum + m.lessons.filter(l => l.is_completed).length, 0)
    + (isCompleted.value && !props.lesson.is_completed ? 1 : 0)
)

function durationLabel(mins: number | null) {
  if (!mins) return ''
  if (mins < 60) return t('lms_learn.duration.minutes', { m: mins })
  const h = Math.floor(mins / 60)
  const m = mins % 60
  if (m) return t('lms_learn.duration.hours_minutes', { h, m })
  return t('lms_learn.duration.hours', { h })
}

function typeLabel(type: string): string {
  const labels = {
    video: t('lms_learn.types.video'),
    pdf: t('lms_learn.types.pdf'),
    document: t('lms_learn.types.document'),
    presentation: t('lms_learn.types.presentation'),
    text: t('lms_learn.types.text'),
    link: t('lms_learn.types.link')
  }
  return labels[type as keyof typeof labels] || type
}

const typeIcon: Record<string, string> = {
  video: 'tabler-video', pdf: 'tabler-file-type-pdf', document: 'tabler-file-text',
  presentation: 'tabler-presentation', text: 'tabler-align-left', link: 'tabler-external-link',
}
const typeColor: Record<string, string> = {
  video: 'primary', pdf: 'error', document: 'secondary',
  presentation: 'warning', text: 'success', link: 'info',
}
</script>

<template>
  <div class="learn-shell d-flex flex-column" style="height:100vh; overflow:hidden;">

    <!-- ── Top bar ─────────────────────────────────────────────────────────── -->
    <div class="learn-topbar d-flex align-center gap-3 px-4 py-2 border-b flex-shrink-0"
         style="background:rgb(var(--v-theme-surface)); border-bottom:1px solid rgba(var(--v-border-color),var(--v-border-opacity));">
      <VBtn icon="tabler-arrow-left" variant="text" size="small" @click="backToCourse" />
      <div class="flex-1-1 min-width-0">
        <p class="text-body-2 font-weight-semibold mb-0 text-truncate">{{ course.title }}</p>
        <p class="text-caption text-medium-emphasis mb-0">
          {{ t('lms_learn.lesson_of', { index: lessonIndex, total: totalLessons }) }}
        </p>
      </div>
      <div class="d-flex align-center gap-3" style="min-width:180px;">
        <VProgressLinear
          :model-value="enrollment ? enrollment.progress_pct : 0"
          :color="enrollment?.status === 'completed' ? 'success' : 'primary'"
          rounded height="6"
          style="width:120px;"
        />
        <span class="text-caption text-medium-emphasis">{{ enrollment?.progress_pct ?? 0 }}%</span>
      </div>
      <VBtn
        :icon="sidebarOpen ? 'tabler-layout-sidebar-right-collapse' : 'tabler-layout-sidebar-right'"
        variant="text"
        size="small"
        @click="sidebarOpen = !sidebarOpen"
        :title="sidebarOpen ? t('lms_learn.sidebar.toggle_close') : t('lms_learn.sidebar.toggle_open')"
      />
    </div>

    <!-- ── Body (content + sidebar) ─────────────────────────────────────────── -->
    <div class="d-flex flex-1-1" style="overflow:hidden; min-height:0;">

      <!-- Lesson content area -->
      <div class="flex-1-1 d-flex flex-column" style="overflow-y:auto; min-width:0;">
        <div class="pa-5 pa-md-8 flex-1-1">

          <!-- Lesson header -->
          <div class="d-flex align-center gap-3 mb-6">
            <VAvatar :color="typeColor[lesson.type] ?? 'default'" variant="tonal" size="48" rounded>
              <VIcon :icon="typeIcon[lesson.type] ?? 'tabler-file'" size="26" />
            </VAvatar>
            <div>
              <h4 class="text-h4 mb-0">{{ lesson.title }}</h4>
              <p class="text-caption text-medium-emphasis mb-0 text-capitalize">
                {{ typeLabel(lesson.type) }}
                <span v-if="lesson.duration_mins"> · {{ durationLabel(lesson.duration_mins) }}</span>
                <VChip v-if="lesson.is_preview && !enrollment" color="info" size="x-small" variant="tonal" class="ms-2">
                  {{ t('lms_learn.free_preview') }}
                </VChip>
              </p>
            </div>
            <VSpacer />
            <VChip v-if="isCompleted" color="success" variant="tonal" prepend-icon="tabler-circle-check">
              {{ t('lms_learn.completed') }}
            </VChip>
          </div>

          <!-- External link -->
          <VCard v-if="lesson.external_url" rounded="lg" elevation="0" border class="mb-5">
            <VCardText class="pa-4 d-flex align-center gap-3">
              <VIcon icon="tabler-external-link" color="primary" />
              <div class="flex-1-1">
                <p class="text-body-2 font-weight-medium mb-0">{{ t('lms_learn.external_resource') }}</p>
                <a :href="lesson.external_url" target="_blank" class="text-caption text-primary">{{ lesson.external_url }}</a>
              </div>
              <VBtn :href="lesson.external_url" target="_blank" color="primary" variant="tonal" size="small" append-icon="tabler-external-link">
                {{ t('lms_learn.open') }}
              </VBtn>
            </VCardText>
          </VCard>

          <!-- Rich HTML content -->
          <div v-if="lesson.content_body" class="lesson-html-body mb-5" v-html="lesson.content_body" />

          <!-- Locked -->
          <VAlert v-if="!enrollment && !lesson.is_preview" type="info" variant="tonal" rounded="lg" class="mb-5">
            {{ t('lms_learn.enroll_to_access') }}
          </VAlert>

          <!-- Materials -->
          <template v-if="lesson.materials.length">
            <h6 class="text-h6 mb-3">{{ t('lms_learn.attachments') }}</h6>
            <div class="d-flex flex-column gap-2 mb-5">
              <VCard
                v-for="mat in lesson.materials" :key="mat.id"
                :href="mat.url" target="_blank"
                rounded="lg" elevation="0" border hover class="pa-3"
              >
                <div class="d-flex align-center gap-3">
                  <VAvatar :color="typeColor[mat.type] ?? 'default'" variant="tonal" size="36" rounded>
                    <VIcon :icon="typeIcon[mat.type] ?? 'tabler-file'" size="18" />
                  </VAvatar>
                  <div class="flex-1-1">
                    <p class="text-body-2 font-weight-medium mb-0">{{ mat.title }}</p>
                    <p class="text-caption text-medium-emphasis mb-0">{{ mat.file_name }} · {{ mat.size_mb }} MB</p>
                  </div>
                  <VIcon icon="tabler-download" size="18" color="primary" />
                </div>
              </VCard>
            </div>
          </template>

        </div>

        <!-- ── Bottom navigation bar ─────────────────────────────────────────── -->
        <div class="learn-nav-bar d-flex align-center gap-3 px-5 py-3 flex-shrink-0"
             style="border-top:1px solid rgba(var(--v-border-color),var(--v-border-opacity)); background:rgb(var(--v-theme-surface));">
          <VBtn
            variant="outlined"
            :disabled="!prevLessonId"
            prepend-icon="tabler-arrow-left"
            @click="goPrev"
          >
            {{ t('lms_learn.previous') }}
          </VBtn>

          <VSpacer />

          <!-- Mark complete (only when enrolled and not yet done) -->
          <VBtn
            v-if="enrollment && !isCompleted"
            color="success"
            variant="tonal"
            :loading="completing"
            prepend-icon="tabler-circle-check"
            @click="markComplete"
          >
            {{ t('lms_learn.mark_complete') }}
          </VBtn>

          <VBtn
            v-if="nextLessonId"
            color="primary"
            :loading="completing"
            append-icon="tabler-arrow-right"
            @click="goNext"
          >
            {{ t('lms_learn.next_lesson') }}
          </VBtn>

          <VBtn
            v-else-if="enrollment && isCompleted && course.has_quiz"
            color="warning"
            append-icon="tabler-help-circle"
            @click="backToCourse(true)"
          >
            {{ t('lms_learn.take_quiz') }}
          </VBtn>

          <VBtn
            v-else-if="enrollment && isCompleted"
            color="success"
            prepend-icon="tabler-trophy"
            @click="backToCourse(false)"
          >
            {{ t('lms_learn.finish_course') }}
          </VBtn>
        </div>
      </div>

      <!-- ── Course outline sidebar ──────────────────────────────────────────── -->
      <Transition name="sidebar">
        <div
          v-if="sidebarOpen"
          class="learn-sidebar flex-shrink-0 border-s d-flex flex-column"
          style="width:300px; overflow-y:auto; background:rgb(var(--v-theme-surface)); border-left:1px solid rgba(var(--v-border-color),var(--v-border-opacity));"
        >
          <div class="pa-4 border-b flex-shrink-0">
            <p class="text-body-2 font-weight-semibold mb-1">{{ t('lms_learn.course_content') }}</p>
            <p class="text-caption text-medium-emphasis mb-0">
              {{ t('lms_learn.lessons_completed', { completed: completedCount, total: totalLessons }) }}
            </p>
          </div>

          <div v-for="mod in course.modules" :key="mod.id">
            <!-- Module header -->
            <div
              class="d-flex align-center gap-2 pa-3 cursor-pointer"
              style="background:rgba(var(--v-theme-on-surface),0.04);"
              @click="toggleMod(mod.id)"
            >
              <VIcon
                :icon="expandedMods.has(mod.id) ? 'tabler-chevron-down' : 'tabler-chevron-right'"
                size="14" class="text-medium-emphasis flex-shrink-0"
              />
              <p class="text-body-2 font-weight-semibold mb-0 flex-1-1">{{ mod.title }}</p>
              <span class="text-caption text-medium-emphasis flex-shrink-0">
                {{ mod.lessons.filter(l => l.is_completed).length }}/{{ mod.lessons.length }}
              </span>
            </div>

            <!-- Lessons in module -->
            <VExpandTransition>
              <div v-if="expandedMods.has(mod.id)">
                <div
                  v-for="l in mod.lessons" :key="l.id"
                  class="d-flex align-center gap-2 px-4 py-2 cursor-pointer lesson-row"
                  :class="{ 'lesson-row--active': l.id === lesson.id }"
                  @click="goToLesson(l.id)"
                >
                  <VIcon
                    :icon="l.is_completed ? 'tabler-circle-check' : (typeIcon[l.type] ?? 'tabler-file')"
                    :color="l.is_completed ? 'success' : (l.id === lesson.id ? 'primary' : 'medium-emphasis')"
                    size="16"
                    class="flex-shrink-0"
                  />
                  <div class="flex-1-1 min-width-0">
                    <p class="text-body-2 mb-0 text-truncate"
                       :class="l.id === lesson.id ? 'font-weight-semibold text-primary' : ''">
                      {{ l.title }}
                    </p>
                    <p v-if="l.duration_mins" class="text-caption text-medium-emphasis mb-0">
                      {{ durationLabel(l.duration_mins) }}
                    </p>
                  </div>
                </div>
              </div>
            </VExpandTransition>
            <VDivider />
          </div>
        </div>
      </Transition>

    </div>
  </div>
</template>

<style scoped>
.lesson-html-body :deep(h2) { font-size: 1.25rem; font-weight: 600; margin: 1.25rem 0 0.5rem; }
.lesson-html-body :deep(h3) { font-size: 1.1rem;  font-weight: 600; margin: 1rem 0 0.4rem; }
.lesson-html-body :deep(p)  { margin-bottom: 0.85rem; line-height: 1.7; }
.lesson-html-body :deep(ul), .lesson-html-body :deep(ol) { padding-left: 1.5rem; margin-bottom: 0.85rem; }
.lesson-html-body :deep(li) { margin-bottom: 0.3rem; line-height: 1.6; }
.lesson-html-body :deep(strong) { font-weight: 600; }

.lesson-row { transition: background 0.15s; border-radius: 0; }
.lesson-row:hover { background: rgba(var(--v-theme-primary), 0.06); }
.lesson-row--active { background: rgba(var(--v-theme-primary), 0.1); }

.sidebar-enter-active, .sidebar-leave-active { transition: width 0.2s ease, opacity 0.2s ease; }
.sidebar-enter-from, .sidebar-leave-to { width: 0 !important; opacity: 0; }
</style>
