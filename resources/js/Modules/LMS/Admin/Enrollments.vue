<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Enrollment = {
  id: number
  status: string
  progress_pct: number
  score: number | null
  enrolled_at: string | null
  completed_at: string | null
  due_date: string | null
  user: { id: number; name: string; email: string }
  course: { id: number; title: string }
}

type Course = { id: number; title: string }

const props = defineProps<{
  enrollments: { data: Enrollment[]; links: any[]; meta: any }
  courses: Course[]
  filters: Record<string, string>
}>()

const statusFilter = ref(props.filters.status ?? '')
const courseFilter = ref(props.filters.course ?? '')

function applyFilters() {
  router.get(route('lms.admin.enrollments'), {
    status: statusFilter.value || undefined,
    course: courseFilter.value || undefined,
  }, { preserveState: true, replace: true })
}

function goToPage(url: string | null) {
  if (url) router.visit(url, { preserveState: true })
}

const rows  = computed(() => props.enrollments.data)
const links = computed(() => props.enrollments.links ?? [])
const meta  = computed(() => props.enrollments.meta ?? {})

const headers = computed(() => [
  { title: t('lms_enrollments.table.employee'), key: 'user', sortable: false },
  { title: t('lms_enrollments.table.course'), key: 'course', sortable: false },
  { title: t('lms_enrollments.table.status'), key: 'status', sortable: false },
  { title: t('lms_enrollments.table.progress'), key: 'progress_pct', sortable: false },
  { title: t('lms_enrollments.table.score'), key: 'score', sortable: false, align: 'center' as const },
  { title: t('lms_enrollments.table.enrolled'), key: 'enrolled_at', sortable: false },
  { title: t('lms_enrollments.table.completed'), key: 'completed_at', sortable: false },
])

const statusOptions = computed(() => [
  { title: t('lms_enrollments.all_statuses'), value: '' },
  { title: t('lms_enrollments.statuses.enrolled'), value: 'enrolled' },
  { title: t('lms_enrollments.statuses.in_progress'), value: 'in_progress' },
  { title: t('lms_enrollments.statuses.completed'), value: 'completed' },
  { title: t('lms_enrollments.statuses.failed'), value: 'failed' },
])

const statusColor: Record<string, string> = {
  enrolled: 'default', in_progress: 'info', completed: 'success', failed: 'error',
}

function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <h4 class="text-h4">{{ t('lms_enrollments.title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('lms_enrollments.subtitle') }}</p>
      </div>
    </div>

    <!-- Filters -->
    <VCard class="mb-5">
      <VCardText class="pa-4">
        <VRow dense align="center">
          <VCol cols="12" md="4">
            <VSelect
              v-model="courseFilter"
              :items="[{ id: '', title: t('lms_enrollments.all_courses') }, ...courses]"
              item-title="title"
              item-value="id"
              :label="t('lms_enrollments.course_label')"
              variant="outlined"
              density="compact"
              hide-details
              @update:model-value="applyFilters"
            />
          </VCol>
          <VCol cols="12" md="3">
            <VSelect
              v-model="statusFilter"
              :items="statusOptions"
              item-title="title"
              item-value="value"
              :label="t('lms_enrollments.status_label')"
              variant="outlined"
              density="compact"
              hide-details
              @update:model-value="applyFilters"
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Table -->
    <VCard>
      <VDataTable :headers="headers" :items="rows" hide-default-footer :items-per-page="25" hover>
        <template #item.user="{ item }">
          <div>
            <p class="text-body-2 font-weight-medium mb-0">{{ item.user.name }}</p>
            <p class="text-caption text-medium-emphasis mb-0">{{ item.user.email }}</p>
          </div>
        </template>
        <template #item.course="{ item }">
          <span class="text-body-2">{{ item.course.title }}</span>
        </template>
        <template #item.status="{ item }">
          <VChip :color="statusColor[item.status] ?? 'default'" size="small" variant="tonal" class="text-capitalize">
            {{ item.status.replace('_', ' ') }}
          </VChip>
        </template>
        <template #item.progress_pct="{ item }">
          <div class="d-flex align-center gap-2" style="min-width: 100px;">
            <VProgressLinear
              :model-value="item.progress_pct"
              :color="item.status === 'completed' ? 'success' : 'primary'"
              rounded
              height="6"
              style="min-width: 60px;"
            />
            <span class="text-caption">{{ item.progress_pct }}%</span>
          </div>
        </template>
        <template #item.score="{ item }">
          <span v-if="item.score !== null" class="text-body-2 font-weight-medium">{{ item.score }}%</span>
          <span v-else class="text-caption text-medium-emphasis">—</span>
        </template>
        <template #item.enrolled_at="{ item }">
          <span class="text-body-2">{{ formatDate(item.enrolled_at) }}</span>
        </template>
        <template #item.completed_at="{ item }">
          <span class="text-body-2" :class="item.completed_at ? 'text-success' : 'text-medium-emphasis'">
            {{ formatDate(item.completed_at) }}
          </span>
        </template>
        <template #no-data>
          <div class="text-center pa-10">
            <VIcon icon="tabler-user-check" size="56" color="secondary" class="mb-3" />
            <p class="text-body-1 text-medium-emphasis">{{ t('lms_enrollments.table.no_enrollments') }}</p>
          </div>
        </template>
      </VDataTable>

      <!-- Pagination -->
      <VDivider v-if="meta.last_page > 1" />
      <div v-if="meta.last_page > 1" class="d-flex align-center justify-space-between pa-4">
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('lms_enrollments.table.pagination', { from: meta.from, to: meta.to, total: meta.total }) }}</p>
        <div class="d-flex gap-1">
          <VBtn
            v-for="link in links"
            :key="link.label"
            :disabled="!link.url"
            :variant="link.active ? 'flat' : 'text'"
            :color="link.active ? 'primary' : 'default'"
            size="small"
            @click="goToPage(link.url)"
            v-html="link.label"
          />
        </div>
      </div>
    </VCard>

  </VContainer>
</template>
