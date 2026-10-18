<template>
  <VCard>
    <VCardTitle class="d-flex align-center pa-4 flex-wrap gap-3">
      <span class="text-h6">{{ t('lms_understanding_scores.page_title') }}</span>
      <VSpacer />

      <!-- Filters -->
      <VSelect
        v-model="filterCourse"
        :items="[{ id: null, title: t('lms_understanding_scores.all_courses') }, ...courses]"
        item-title="title"
        item-value="id"
        density="compact"
        hide-details
        style="max-width: 220px;"
        @update:model-value="applyFilters"
      />
      <VSelect
        v-model="filterGrade"
        :items="gradeOptions"
        item-title="label"
        item-value="value"
        density="compact"
        hide-details
        style="max-width: 140px;"
        @update:model-value="applyFilters"
      />
      <VSelect
        v-model="filterFlag"
        :items="flagOptions"
        item-title="label"
        item-value="value"
        density="compact"
        hide-details
        style="max-width: 180px;"
        @update:model-value="applyFilters"
      />
    </VCardTitle>

    <!-- Weight legend -->
    <VCardText class="pt-0 pb-2">
      <div class="d-flex gap-3 flex-wrap text-caption text-medium-emphasis">
        <span>{{ t('lms_understanding_scores.score_weights') }}:</span>
        <span v-for="(w, key) in weights" :key="key">
          {{ t(`lms_understanding_scores.weights.${key}`) }}: <strong>{{ (w * 100).toFixed(0) }}%</strong>
        </span>
      </div>
    </VCardText>

    <VDataTable
      :headers="headers"
      :items="scores.data"
      :loading="loading"
      hide-default-footer
    >
      <template #item.learner_name="{ item }">
        <span class="font-weight-medium">{{ item.learner_name }}</span>
      </template>

      <template #item.total_score="{ item }">
        <div class="d-flex align-center gap-2" style="min-width: 140px;">
          <VProgressLinear
            :model-value="item.total_score"
            :color="item.grade_color"
            rounded
            height="6"
            class="flex-grow-1"
          />
          <span class="text-body-2 font-weight-medium">{{ item.total_score }}%</span>
        </div>
      </template>

      <template #item.grade="{ item }">
        <VChip :color="item.grade_color" size="small" label class="font-weight-bold">
          {{ item.grade }}
        </VChip>
      </template>

      <template #item.flags="{ item }">
        <div class="d-flex gap-1 flex-wrap">
          <VChip v-if="item.guessing_detected" size="x-small" color="error" prepend-icon="mdi-dice-multiple">
            {{ t('lms_understanding_scores.flags.guessing') }}
          </VChip>
          <VChip v-if="item.rushed_detected" size="x-small" color="warning" prepend-icon="mdi-run-fast">
            {{ t('lms_understanding_scores.flags.rushed') }}
          </VChip>
          <VChip v-if="item.low_engagement_detected" size="x-small" color="orange" prepend-icon="mdi-sleep">
            {{ t('lms_understanding_scores.flags.low_engage') }}
          </VChip>
          <VChip v-if="item.is_certificate_blocked" size="x-small" color="error" prepend-icon="mdi-certificate-outline">
            {{ t('lms_understanding_scores.flags.cert_blocked') }}
          </VChip>
          <VChip v-if="item.refresher_recommended" size="x-small" color="deep-purple" prepend-icon="mdi-refresh">
            {{ t('lms_understanding_scores.flags.refresher') }}
          </VChip>
          <VChip v-if="item.manager_confirmed" size="x-small" color="success" prepend-icon="mdi-account-check">
            {{ t('lms_understanding_scores.flags.confirmed') }}
          </VChip>
        </div>
      </template>

      <template #item.calculated_at="{ item }">
        <span class="text-caption">{{ item.calculated_at ?? '—' }}</span>
      </template>

      <template #item.actions="{ item }">
        <div class="d-flex gap-1">
          <VBtn
            icon
            size="x-small"
            variant="text"
            color="primary"
            :href="route('lms.admin.understanding.show', item.id)"
          >
            <VIcon size="18">mdi-chart-bar</VIcon>
            <VTooltip activator="parent">{{ t('lms_understanding_scores.actions.gap_report') }}</VTooltip>
          </VBtn>
          <VBtn
            icon
            size="x-small"
            variant="text"
            :loading="recalculating[item.enrollment_id]"
            @click="recalculate(item)"
          >
            <VIcon size="18">mdi-refresh</VIcon>
            <VTooltip activator="parent">{{ t('lms_understanding_scores.actions.recalculate') }}</VTooltip>
          </VBtn>
        </div>
      </template>

      <template #no-data>
        <div class="text-center text-medium-emphasis pa-8">
          {{ t('lms_understanding_scores.no_data') }}
        </div>
      </template>
    </VDataTable>

    <!-- Pagination -->
    <div v-if="scores.last_page > 1" class="d-flex justify-center pa-4">
      <VPagination
        :model-value="scores.current_page"
        :length="scores.last_page"
        density="compact"
        @update:model-value="goPage"
      />
    </div>

    <VSnackbar v-model="snackbar" :timeout="2500" color="success" location="bottom right">
      {{ snackbarText }}
    </VSnackbar>
  </VCard>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

// ── Types ────────────────────────────────────────────────────────────────
interface ScoreItem {
  id: number
  enrollment_id: number
  learner_name: string
  course_title: string
  total_score: number
  grade: string
  grade_color: string
  guessing_detected: boolean
  rushed_detected: boolean
  low_engagement_detected: boolean
  is_certificate_blocked: boolean
  refresher_recommended: boolean
  manager_confirmed: boolean
  calculated_at: string | null
}

interface ScoresData {
  data: ScoreItem[]
  current_page: number
  last_page: number
  total: number
}

interface Course {
  id: number
  title: string
}

interface FilterOption {
  value: string | null
  label: string
}

const props = defineProps<{
  scores: ScoresData
  courses: Course[]
  filters: Record<string, any>
  gradeColors: Record<string, string>
  weights: Record<string, number>
}>()

// ── State ────────────────────────────────────────────────────────────────
const loading = ref(false)
const recalculating = ref<Record<number, boolean>>({})
const snackbar = ref(false)
const snackbarText = ref('')

const filterCourse = ref<number | null>(props.filters.course_id ?? null)
const filterGrade = ref<string | null>(props.filters.grade ?? null)
const filterFlag = ref<string | null>(props.filters.flag ?? null)

// ── Options ──────────────────────────────────────────────────────────────
const gradeOptions = computed<FilterOption[]>(() => [
  { value: null, label: t('lms_understanding_scores.all_grades') },
  { value: 'A',  label: t('lms_understanding_scores.grade_a') },
  { value: 'B',  label: t('lms_understanding_scores.grade_b') },
  { value: 'C',  label: t('lms_understanding_scores.grade_c') },
  { value: 'D',  label: t('lms_understanding_scores.grade_d') },
  { value: 'F',  label: t('lms_understanding_scores.grade_f') },
])

const flagOptions = computed<FilterOption[]>(() => [
  { value: null,         label: t('lms_understanding_scores.all_flags') },
  { value: 'guessing',   label: t('lms_understanding_scores.flag_guessing') },
  { value: 'rushed',     label: t('lms_understanding_scores.flag_rushed') },
  { value: 'low_engage', label: t('lms_understanding_scores.flag_low_engage') },
  { value: 'cert_blocked', label: t('lms_understanding_scores.flag_cert_blocked') },
  { value: 'refresher',  label: t('lms_understanding_scores.flag_refresher') },
])

// ── Headers ──────────────────────────────────────────────────────────────
const headers = computed(() => [
  { title: t('lms_understanding_scores.table.learner'), key: 'learner_name' },
  { title: t('lms_understanding_scores.table.course'), key: 'course_title' },
  { title: t('lms_understanding_scores.table.score'), key: 'total_score', sortable: true },
  { title: t('lms_understanding_scores.table.grade'), key: 'grade', sortable: true },
  { title: t('lms_understanding_scores.table.flags'), key: 'flags', sortable: false },
  { title: t('lms_understanding_scores.table.calculated'), key: 'calculated_at' },
  { title: '', key: 'actions', sortable: false, align: 'end' as const },
])

// ── Methods ──────────────────────────────────────────────────────────────
function applyFilters() {
  loading.value = true
  router.get(
    route('lms.admin.understanding'),
    {
      course_id: filterCourse.value,
      grade: filterGrade.value,
      flag: filterFlag.value
    },
    {
      preserveState: true,
      replace: true,
      onFinish: () => { loading.value = false }
    }
  )
}

function goPage(page: number) {
  loading.value = true
  router.get(
    route('lms.admin.understanding'),
    { ...props.filters, page },
    {
      preserveState: true,
      onFinish: () => { loading.value = false }
    }
  )
}

function recalculate(item: ScoreItem) {
  recalculating.value[item.enrollment_id] = true
  router.post(
    route('lms.admin.understanding.calculate', item.enrollment_id),
    {},
    {
      onSuccess: () => {
        snackbarText.value = t('lms_understanding_scores.snackbar.recalculated')
        snackbar.value = true
      },
      onFinish: () => {
        recalculating.value[item.enrollment_id] = false
      },
      preserveScroll: true,
    }
  )
}
</script>
