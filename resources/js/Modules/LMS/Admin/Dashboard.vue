<script setup lang="ts">
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type PopularCourse = {
  id: number
  uuid: string
  title: string
  type: string
  is_mandatory: boolean
  enrollments_count: number
  completion_rate: number
}

type OverdueEnrollment = {
  id: number
  user: { id: number; name: string }
  course: { id: number; title: string }
  status: string
  due_date: string | null
}

const props = defineProps<{
  stats: {
    total_courses: number
    total_enrollments: number
    completion_rate: number
    mandatory_compliance: number
  }
  popularCourses: PopularCourse[]
  overdueEnrollments: OverdueEnrollment[]
}>()

const statCards = computed(() => [
  { title: t('lms_dashboard.stats.total_courses'),         value: props.stats.total_courses,         icon: 'tabler-books',          color: 'primary',   suffix: '' },
  { title: t('lms_dashboard.stats.total_enrollments'),     value: props.stats.total_enrollments,     icon: 'tabler-users',          color: 'info',      suffix: '' },
  { title: t('lms_dashboard.stats.completion_rate'),       value: props.stats.completion_rate,       icon: 'tabler-circle-check',   color: 'success',   suffix: '%' },
  { title: t('lms_dashboard.stats.mandatory_compliance'),  value: props.stats.mandatory_compliance,  icon: 'tabler-shield-check',   color: 'warning',   suffix: '%' },
])

const courseHeaders = computed(() => [
  { title: t('lms_dashboard.table.course'), key: 'title', sortable: true },
  { title: t('lms_dashboard.table.type'), key: 'type', sortable: true },
  { title: t('lms_dashboard.table.enrollments'), key: 'enrollments_count', sortable: true },
  { title: t('lms_dashboard.table.completion'), key: 'completion_rate', sortable: true },
  { title: t('lms_dashboard.table.mandatory'), key: 'is_mandatory', sortable: false },
])

const overdueHeaders = computed(() => [
  { title: t('lms_dashboard.table.employee'), key: 'user', sortable: false },
  { title: t('lms_dashboard.table.course'), key: 'course', sortable: false },
  { title: t('lms_dashboard.table.due_date'), key: 'due_date', sortable: false },
  { title: t('lms_dashboard.table.status'), key: 'status', sortable: false },
])

function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}

const typeColor: Record<string, string> = {
  video: 'primary', document: 'secondary', quiz: 'success', scorm: 'warning', external: 'info',
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">

    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <h4 class="text-h4">{{ t('lms_dashboard.title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('lms_dashboard.subtitle') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="router.visit(route('lms.admin.courses'))">
        {{ t('lms_dashboard.manage_courses') }}
      </VBtn>
    </div>

    <!-- Stat Cards -->
    <VRow class="mb-6">
      <VCol v-for="card in statCards" :key="card.title" cols="12" sm="6" lg="3">
        <VCard>
          <VCardText class="d-flex align-center gap-4 pa-4">
            <VAvatar :color="card.color" variant="tonal" size="52" rounded>
              <VIcon :icon="card.icon" size="28" />
            </VAvatar>
            <div>
              <p class="text-medium-emphasis text-body-2 mb-1">{{ card.title }}</p>
              <h5 class="text-h5">{{ card.value }}{{ card.suffix }}</h5>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow>
      <!-- Popular Courses -->
      <VCol cols="12" lg="7">
        <VCard>
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">{{ t('lms_dashboard.popular_courses') }}</VCardTitle>
          <VDivider />
          <VDataTable
            :headers="courseHeaders"
            :items="popularCourses"
            :items-per-page="10"
            hide-default-footer
          >
            <template #item.title="{ item }">
              <a
                class="text-primary font-weight-medium text-decoration-none"
                @click.prevent="router.visit(route('lms.courses.show', { course: item.uuid }))"
                href="#"
              >{{ item.title }}</a>
            </template>
            <template #item.type="{ item }">
              <VChip :color="typeColor[item.type] ?? 'default'" size="small" variant="tonal">
                {{ item.type.toUpperCase() }}
              </VChip>
            </template>
            <template #item.completion_rate="{ item }">
              <div class="d-flex align-center gap-2" style="min-width: 100px;">
                <VProgressLinear
                  :model-value="item.completion_rate"
                  color="success"
                  rounded
                  height="6"
                  style="min-width: 60px;"
                />
                <span class="text-caption">{{ item.completion_rate }}%</span>
              </div>
            </template>
            <template #item.is_mandatory="{ item }">
              <VChip v-if="item.is_mandatory" color="error" size="x-small" variant="tonal">{{ t('lms_dashboard.table.required') }}</VChip>
              <span v-else class="text-caption text-medium-emphasis">{{ t('lms_dashboard.table.optional') }}</span>
            </template>
            <template #no-data>
              <div class="text-center pa-6 text-medium-emphasis">{{ t('lms_dashboard.table.no_courses') }}</div>
            </template>
          </VDataTable>
        </VCard>
      </VCol>

      <!-- Overdue Enrollments -->
      <VCol cols="12" lg="5">
        <VCard>
          <VCardTitle class="pa-4 pb-2 d-flex align-center justify-space-between">
            <span class="text-body-1 font-weight-semibold">{{ t('lms_dashboard.overdue_mandatory') }}</span>
            <VChip color="error" size="small" variant="tonal">{{ overdueEnrollments.length }}</VChip>
          </VCardTitle>
          <VDivider />
          <VDataTable
            :headers="overdueHeaders"
            :items="overdueEnrollments"
            :items-per-page="10"
            hide-default-footer
          >
            <template #item.user="{ item }">
              <span class="text-body-2 font-weight-medium">{{ item.user.name }}</span>
            </template>
            <template #item.course="{ item }">
              <span class="text-body-2 text-medium-emphasis">{{ item.course.title }}</span>
            </template>
            <template #item.due_date="{ item }">
              <span class="text-caption text-error">{{ formatDate(item.due_date) }}</span>
            </template>
            <template #item.status="{ item }">
              <VChip color="warning" size="x-small" variant="tonal" class="text-capitalize">{{ item.status }}</VChip>
            </template>
            <template #no-data>
              <div class="text-center pa-6 text-medium-emphasis">{{ t('lms_dashboard.table.no_overdue') }}</div>
            </template>
          </VDataTable>
        </VCard>
      </VCol>
    </VRow>

  </VContainer>
</template>
