<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type EnrollmentItem = {
  id: number
  uuid: string
  status: string
  progress_pct: number
  score: number | null
  enrolled_at: string | null
  completed_at: string | null
  due_date: string | null
  certificate: { id: number; certificate_number: string; issued_at: string | null } | null
  course: {
    id: number
    uuid: string
    title: string
    type: string
    duration_mins: number | null
    is_mandatory: boolean
    category: { name: string; color: string } | null
  }
}

type Certificate = {
  id: number
  certificate_number: string
  issued_at: string | null
  expires_at: string | null
  course: { id: number; title: string }
}

type MandatoryDue = {
  id: number
  uuid: string
  title: string
  duration_mins: number | null
  category: { name: string; color: string } | null
  enrollment: EnrollmentItem | null
}

const props = defineProps<{
  stats: { enrolled: number; completed: number; certificates: number; mandatory_due: number }
  inProgress: EnrollmentItem[]
  completed: EnrollmentItem[]
  certificates: Certificate[]
  mandatoryDue: MandatoryDue[]
}>()

const tab = ref('in-progress')

const statCards = computed(() => [
  { title: t('lms_my_learning_simple.stats.in_progress'), value: props.stats.enrolled, icon: 'tabler-player-play', color: 'primary' },
  { title: t('lms_my_learning_simple.stats.completed'), value: props.stats.completed, icon: 'tabler-circle-check', color: 'success' },
  { title: t('lms_my_learning_simple.stats.certificates'), value: props.stats.certificates, icon: 'tabler-certificate', color: 'info' },
  { title: t('lms_my_learning_simple.stats.mandatory_due'), value: props.stats.mandatory_due, icon: 'tabler-alert-triangle', color: 'error' },
])

const typeColor: Record<string, string> = {
  video: 'primary', document: 'secondary', quiz: 'success', scorm: 'warning', external: 'info',
}

function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}

function durationLabel(mins: number | null) {
  if (!mins) return ''
  if (mins < 60) return `${mins}m`
  const h = Math.floor(mins / 60)
  const m = mins % 60
  return m ? `${h}h ${m}m` : `${h}h`
}

function enrollInCourse(courseUuid: string) {
  router.post(route('lms.courses.enroll', { course: courseUuid }), {}, {
    onSuccess: () => router.reload(),
  })
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4">{{ t('lms_my_learning_simple.page_title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('lms_my_learning_simple.page_description') }}</p>
      </div>
      <VBtn color="primary" variant="tonal" prepend-icon="tabler-books" @click="router.visit(route('lms.catalog'))">
        {{ t('lms_my_learning_simple.browse_catalog') }}
      </VBtn>
    </div>

    <!-- Stat Cards -->
    <VRow class="mb-6">
      <VCol v-for="card in statCards" :key="card.title" cols="6" lg="3">
        <VCard>
          <VCardText class="d-flex align-center gap-4 pa-4">
            <VAvatar :color="card.color" variant="tonal" size="48" rounded>
              <VIcon :icon="card.icon" size="24" />
            </VAvatar>
            <div>
              <p class="text-body-2 text-medium-emphasis mb-1">{{ card.title }}</p>
              <h5 class="text-h5">{{ card.value }}</h5>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Tabs -->
    <VTabs v-model="tab" class="mb-4">
      <VTab value="in-progress">
        {{ t('lms_my_learning_simple.tabs.in_progress') }}
        <VChip v-if="inProgress.length" size="x-small" color="primary" variant="tonal" label class="ms-2">{{ inProgress.length }}</VChip>
      </VTab>
      <VTab value="mandatory">
        {{ t('lms_my_learning_simple.tabs.mandatory') }}
        <VChip v-if="mandatoryDue.length" size="x-small" color="error" variant="tonal" label class="ms-2">{{ mandatoryDue.length }}</VChip>
      </VTab>
      <VTab value="completed">{{ t('lms_my_learning_simple.tabs.completed') }}</VTab>
      <VTab value="certificates">{{ t('lms_my_learning_simple.tabs.certificates') }}</VTab>
    </VTabs>

    <VWindow v-model="tab">

      <!-- In Progress -->
      <VWindowItem value="in-progress">
        <VRow v-if="inProgress.length">
          <VCol v-for="item in inProgress" :key="item.id" cols="12" sm="6" lg="4">
            <VCard rounded="lg" elevation="0" border hover class="cursor-pointer"
              @click="router.visit(route('lms.courses.show', { course: item.course.uuid }))">
              <VCardText class="pa-4">
                <div class="d-flex align-center gap-2 mb-3">
                  <VChip v-if="item.course.category" :color="item.course.category.color" size="x-small" variant="tonal">
                    {{ item.course.category.name }}
                  </VChip>
                  <VChip v-if="item.course.is_mandatory" color="error" size="x-small" variant="tonal">
                    {{ t('lms_my_learning_simple.required') }}
                  </VChip>
                </div>
                <h6 class="text-h6 mb-1">{{ item.course.title }}</h6>
                <p v-if="item.course.duration_mins" class="text-caption text-medium-emphasis mb-3">
                  {{ durationLabel(item.course.duration_mins) }}
                </p>
                <div class="d-flex align-center gap-2">
                  <VProgressLinear :model-value="item.progress_pct" color="primary" rounded height="8" class="flex-1-1" />
                  <span class="text-body-2 font-weight-medium">{{ item.progress_pct }}%</span>
                </div>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>
        <VCard v-else rounded="lg" elevation="0" border>
          <VCardText class="text-center py-10">
            <VIcon icon="tabler-player-play" size="56" color="secondary" class="mb-3" />
            <p class="text-body-1 text-medium-emphasis">{{ t('lms_my_learning_simple.in_progress.no_courses') }}</p>
          </VCardText>
        </VCard>
      </VWindowItem>

      <!-- Mandatory Due -->
      <VWindowItem value="mandatory">
        <VRow v-if="mandatoryDue.length">
          <VCol v-for="course in mandatoryDue" :key="course.id" cols="12" sm="6" lg="4">
            <VCard rounded="lg" elevation="0" border :class="{ 'border-error': !course.enrollment }">
              <VCardText class="pa-4">
                <div class="d-flex align-center gap-2 mb-3">
                  <VChip v-if="course.category" :color="course.category.color" size="x-small" variant="tonal">
                    {{ course.category.name }}
                  </VChip>
                  <VChip color="error" size="x-small" variant="tonal">{{ t('lms_my_learning_simple.required') }}</VChip>
                </div>
                <h6 class="text-h6 mb-1">{{ course.title }}</h6>
                <p v-if="course.duration_mins" class="text-caption text-medium-emphasis mb-3">
                  {{ durationLabel(course.duration_mins) }}
                </p>
                <div v-if="course.enrollment" class="d-flex align-center gap-2 mb-3">
                  <VProgressLinear :model-value="course.enrollment.progress_pct" color="warning" rounded height="8" class="flex-1-1" />
                  <span class="text-body-2 font-weight-medium">{{ course.enrollment.progress_pct }}%</span>
                </div>
                <VBtn
                  v-if="!course.enrollment"
                  color="primary"
                  size="small"
                  variant="flat"
                  block
                  @click="enrollInCourse(course.uuid)"
                >
                  {{ t('lms_my_learning_simple.mandatory.enrol_now') }}
                </VBtn>
                <VBtn
                  v-else
                  color="warning"
                  size="small"
                  variant="tonal"
                  block
                  @click="router.visit(route('lms.courses.show', { course: course.uuid }))"
                >
                  {{ t('lms_my_learning_simple.mandatory.continue') }}
                </VBtn>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>
        <VCard v-else rounded="lg" elevation="0" border>
          <VCardText class="text-center py-10">
            <VIcon icon="tabler-circle-check" size="56" color="success" class="mb-3" />
            <p class="text-body-1 text-medium-emphasis">{{ t('lms_my_learning_simple.mandatory.no_courses') }}</p>
          </VCardText>
        </VCard>
      </VWindowItem>

      <!-- Completed -->
      <VWindowItem value="completed">
        <VRow v-if="completed.length">
          <VCol v-for="item in completed" :key="item.id" cols="12" sm="6" lg="4">
            <VCard rounded="lg" elevation="0" border>
              <VCardText class="pa-4">
                <div class="d-flex align-center gap-2 mb-2">
                  <VChip v-if="item.course.category" :color="item.course.category.color" size="x-small" variant="tonal">
                    {{ item.course.category.name }}
                  </VChip>
                  <VChip color="success" size="x-small" variant="tonal">{{ t('lms_my_learning_simple.statuses.completed') }}</VChip>
                </div>
                <h6 class="text-h6 mb-1">{{ item.course.title }}</h6>
                <p class="text-caption text-medium-emphasis mb-0">
                  {{ t('lms_my_learning_simple.completed.completed_on', { date: formatDate(item.completed_at) }) }}
                  <span v-if="item.score !== null"> · {{ t('lms_my_learning_simple.completed.score', { score: item.score }) }}</span>
                </p>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>
        <VCard v-else rounded="lg" elevation="0" border>
          <VCardText class="text-center py-10">
            <VIcon icon="tabler-circle-check" size="56" color="secondary" class="mb-3" />
            <p class="text-body-1 text-medium-emphasis">{{ t('lms_my_learning_simple.completed.no_courses') }}</p>
          </VCardText>
        </VCard>
      </VWindowItem>

      <!-- Certificates -->
      <VWindowItem value="certificates">
        <VRow v-if="certificates.length">
          <VCol v-for="cert in certificates" :key="cert.id" cols="12" sm="6" lg="4">
            <VCard rounded="lg" elevation="0" border>
              <VCardText class="pa-4">
                <div class="d-flex align-center gap-3 mb-3">
                  <VAvatar color="success" variant="tonal" size="48" rounded>
                    <VIcon icon="tabler-certificate" size="26" />
                  </VAvatar>
                  <div>
                    <p class="text-body-2 font-weight-semibold mb-0">{{ cert.course.title }}</p>
                    <p class="text-caption text-medium-emphasis mb-0">{{ cert.certificate_number }}</p>
                  </div>
                </div>
                <p class="text-caption text-medium-emphasis mb-0">
                  {{ t('lms_my_learning_simple.certificates.issued', { date: formatDate(cert.issued_at) }) }}
                  <span v-if="cert.expires_at"> · {{ t('lms_my_learning_simple.certificates.expires', { date: formatDate(cert.expires_at) }) }}</span>
                </p>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>
        <VCard v-else rounded="lg" elevation="0" border>
          <VCardText class="text-center py-10">
            <VIcon icon="tabler-certificate" size="56" color="secondary" class="mb-3" />
            <p class="text-body-1 text-medium-emphasis">{{ t('lms_my_learning_simple.certificates.no_certificates') }}</p>
          </VCardText>
        </VCard>
      </VWindowItem>

    </VWindow>
  </VContainer>
</template>
