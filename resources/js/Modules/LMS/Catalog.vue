<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import axios from 'axios'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

// ── Types ────────────────────────────────────────────────────────────────
type Category = {
  id: number
  name: string
  icon: string
  color: string
}

type Enrollment = {
  id: number
  status: string
  progress_pct: number
  score: number | null
  completed_at: string | null
}

type Course = {
  id: number
  uuid: string
  title: string
  description: string | null
  cover_image: string | null
  type: string
  duration_mins: number
  pass_score: number
  is_mandatory: boolean
  category: { id: number; name: string; color: string; icon: string } | null
  enrollment: Enrollment | null
}

type FeaturedPath = {
  id: number
  title: string
  description: string | null
  is_mandatory: boolean
  path_courses_count: number
}

const props = defineProps<{
  categories: Category[]
  courses: Course[]
  featuredPaths: FeaturedPath[]
  filters: { search?: string; category?: string }
}>()

// ── State ────────────────────────────────────────────────────────────────
const search = ref(props.filters.search ?? '')
const selectedCat = ref<number | null>(props.filters.category ? Number(props.filters.category) : null)
const enrollingId = ref<number | null>(null)

// ── Computed ─────────────────────────────────────────────────────────────
const filteredCourses = computed(() => {
  let list = props.courses
  if (search.value) {
    const s = search.value.toLowerCase()
    list = list.filter(c => c.title.toLowerCase().includes(s))
  }
  if (selectedCat.value) {
    list = list.filter(c => c.category?.id === selectedCat.value)
  }
  return list
})

// ── Methods ──────────────────────────────────────────────────────────────
function filterByCategory(id: number | null) {
  selectedCat.value = id
  router.get(
    route('lms.catalog'),
    { category: id ?? undefined, search: search.value || undefined },
    { preserveState: true, replace: true }
  )
}

function doSearch() {
  router.get(
    route('lms.catalog'),
    { search: search.value || undefined, category: selectedCat.value ?? undefined },
    { preserveState: true, replace: true }
  )
}

async function enroll(course: Course) {
  if (course.enrollment) {
    router.visit(route('lms.courses.show', course.uuid))
    return
  }
  enrollingId.value = course.id
  try {
    await axios.post(route('lms.courses.enroll', course.uuid))
    router.visit(route('lms.courses.show', course.uuid))
  } finally {
    enrollingId.value = null
  }
}

function statusColor(status: string) {
  const map: Record<string, string> = {
    enrolled: 'primary',
    in_progress: 'warning',
    completed: 'success',
    failed: 'error',
  }
  return map[status] ?? 'default'
}

function typeIcon(type: string) {
  const map: Record<string, string> = {
    video: 'tabler-video',
    document: 'tabler-file-text',
    quiz: 'tabler-help-circle',
    scorm: 'tabler-package',
    external: 'tabler-external-link',
  }
  return map[type] ?? 'tabler-book'
}

function typeLabel(type: string): string {
  const labels = {
    video: t('lms_catalog.types.video'),
    document: t('lms_catalog.types.document'),
    quiz: t('lms_catalog.types.quiz'),
    scorm: t('lms_catalog.types.scorm'),
    external: t('lms_catalog.types.external')
  }
  return labels[type as keyof typeof labels] || type
}

function formatDuration(mins: number): string {
  if (!mins) return '—'
  if (mins < 60) return t('lms_catalog.duration.minutes', { m: mins })
  const h = Math.floor(mins / 60)
  const m = mins % 60
  if (m) {
    return t('lms_catalog.duration.hours_minutes', { h, m })
  }
  return t('lms_catalog.duration.hours', { h })
}

function statusLabel(status: string): string {
  const labels = {
    enrolled: t('lms_catalog.statuses.enrolled'),
    in_progress: t('lms_catalog.statuses.in_progress'),
    completed: t('lms_catalog.statuses.completed'),
    failed: t('lms_catalog.statuses.failed')
  }
  return labels[status as keyof typeof labels] || status
}
</script>

<template>
  <VContainer fluid class="pa-6">
    <!-- Page header -->
    <VRow class="mb-4" align="center">
      <VCol>
        <h4 class="text-h4 font-weight-bold">{{ t('lms_catalog.page_title') }}</h4>
        <p class="text-medium-emphasis mt-1">{{ t('lms_catalog.page_description') }}</p>
      </VCol>
      <VCol cols="auto">
        <VBtn
          :href="route('lms.my-learning')"
          variant="tonal"
          color="primary"
          prepend-icon="tabler-books"
        >
          {{ t('lms_catalog.my_learning') }}
        </VBtn>
      </VCol>
    </VRow>

    <!-- Search -->
    <VRow class="mb-4">
      <VCol cols="12" md="6">
        <VTextField
          v-model="search"
          :placeholder="t('lms_catalog.search_placeholder')"
          prepend-inner-icon="tabler-search"
          clearable
          hide-details
          variant="outlined"
          density="compact"
          @keyup.enter="doSearch"
          @click:clear="() => { search = ''; doSearch() }"
        />
      </VCol>
    </VRow>

    <!-- Category filter chips -->
    <div class="d-flex flex-wrap gap-2 mb-6">
      <VChip
        :color="selectedCat === null ? 'primary' : 'default'"
        :variant="selectedCat === null ? 'elevated' : 'tonal'"
        clickable
        @click="filterByCategory(null)"
      >
        {{ t('lms_catalog.all') }}
      </VChip>
      <VChip
        v-for="cat in categories"
        :key="cat.id"
        :color="selectedCat === cat.id ? cat.color : 'default'"
        :variant="selectedCat === cat.id ? 'elevated' : 'tonal'"
        clickable
        @click="filterByCategory(cat.id)"
      >
        <VIcon start size="14">{{ cat.icon }}</VIcon>
        {{ cat.name }}
      </VChip>
    </div>

    <!-- Featured Learning Paths -->
    <template v-if="featuredPaths.length && !selectedCat && !search">
      <h5 class="text-h6 font-weight-semibold mb-3">{{ t('lms_catalog.featured_paths') }}</h5>
      <VRow class="mb-6">
        <VCol
          v-for="path in featuredPaths"
          :key="path.id"
          cols="12"
          sm="6"
          lg="4"
        >
          <VCard variant="outlined" class="h-100">
            <VCardItem>
              <template #prepend>
                <VAvatar color="primary" variant="tonal" size="42">
                  <VIcon>tabler-route</VIcon>
                </VAvatar>
              </template>
              <VCardTitle>{{ path.title }}</VCardTitle>
              <VCardSubtitle>
                {{ t('lms_catalog.path_courses', { count: path.path_courses_count }) }}
              </VCardSubtitle>
            </VCardItem>
            <VCardText>
              <p class="text-medium-emphasis text-body-2 mb-2">{{ path.description }}</p>
              <VChip v-if="path.is_mandatory" color="error" size="small" variant="tonal">
                {{ t('lms_catalog.mandatory') }}
              </VChip>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </template>

    <!-- Courses grid -->
    <h5 class="text-h6 font-weight-semibold mb-3">
      {{ t('lms_catalog.courses_count', { count: filteredCourses.length }) }}
    </h5>

    <VRow v-if="filteredCourses.length">
      <VCol
        v-for="course in filteredCourses"
        :key="course.id"
        cols="12"
        sm="6"
        lg="4"
      >
        <VCard class="h-100 d-flex flex-column" elevation="0" border>
          <!-- Cover image or placeholder -->
          <div
            class="lms-cover d-flex align-center justify-center"
            :style="course.cover_image ? `background-image: url(${course.cover_image}); background-size: cover; background-position: center;` : ''"
          >
            <VIcon v-if="!course.cover_image" size="48" color="white">{{ typeIcon(course.type) }}</VIcon>
          </div>

          <VCardItem class="pb-0">
            <VCardTitle class="text-wrap">{{ course.title }}</VCardTitle>
            <template #append>
              <div class="d-flex flex-column align-end gap-1">
                <VChip v-if="course.category" :color="course.category.color" size="x-small" variant="tonal">
                  {{ course.category.name }}
                </VChip>
                <VChip v-if="course.is_mandatory" color="error" size="x-small" variant="tonal">
                  {{ t('lms_catalog.mandatory') }}
                </VChip>
              </div>
            </template>
          </VCardItem>

          <VCardText class="flex-grow-1 pb-2">
            <p class="text-medium-emphasis text-body-2 mb-3 lms-desc">
              {{ course.description ?? t('lms_catalog.no_description') }}
            </p>

            <div class="d-flex align-center gap-3 text-caption text-medium-emphasis mb-3">
              <span>
                <VIcon size="14">{{ typeIcon(course.type) }}</VIcon>
                {{ typeLabel(course.type) }}
              </span>
              <span>
                <VIcon size="14">tabler-clock</VIcon>
                {{ formatDuration(course.duration_mins) }}
              </span>
            </div>

            <!-- Enrollment progress -->
            <template v-if="course.enrollment">
              <div class="d-flex justify-space-between text-caption mb-1">
                <span>
                  <VChip :color="statusColor(course.enrollment.status)" size="x-small" variant="tonal">
                    {{ statusLabel(course.enrollment.status) }}
                  </VChip>
                </span>
                <span>{{ t('lms_catalog.progress', { progress: course.enrollment.progress_pct }) }}</span>
              </div>
              <VProgressLinear
                :model-value="course.enrollment.progress_pct"
                :color="statusColor(course.enrollment.status)"
                height="6"
                rounded
              />
            </template>
          </VCardText>

          <VCardActions class="pt-0 px-4 pb-4">
            <VBtn
              v-if="!course.enrollment"
              color="primary"
              variant="tonal"
              block
              :loading="enrollingId === course.id"
              @click="enroll(course)"
            >
              {{ t('lms_catalog.buttons.enroll') }}
            </VBtn>
            <VBtn
              v-else-if="course.enrollment.status === 'completed'"
              color="success"
              variant="tonal"
              block
              :href="route('lms.courses.show', course.uuid)"
            >
              <VIcon start>tabler-circle-check</VIcon>
              {{ t('lms_catalog.buttons.completed') }}
            </VBtn>
            <VBtn
              v-else
              color="warning"
              variant="tonal"
              block
              :href="route('lms.courses.show', course.uuid)"
            >
              {{ t('lms_catalog.buttons.continue') }}
            </VBtn>
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>

    <VAlert v-else variant="tonal" color="info" class="mt-4">
      {{ t('lms_catalog.no_courses') }}
    </VAlert>
  </VContainer>
</template>

<style scoped>
.lms-cover {
  height: 140px;
  background-color: rgb(var(--v-theme-primary), 0.12);
}
.lms-desc {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
</style>
