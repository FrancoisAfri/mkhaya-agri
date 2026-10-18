<script setup lang="ts">
import { ref, watch } from 'vue'
import { useFitToViewport } from '@/composables/useFitToViewport'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

/**
 * "My Monthly Project Reports" — a project manager's own rows across every month.
 *
 * The same data as the consolidated report, narrowed to the projects this person is responsible
 * for and to the columns they act on. A director reads the whole report; a project manager reads
 * their own six lines, and asking them to find those in a forty-row grid is how the report stops
 * being used.
 */
const props = defineProps<{
  rows: Array<{
    id: string; month: string | null; period: string | null
    project: string | null; project_code: string | null
    stage: string | null; progress_percentage: string | null
    project_status: string | null; challenges: string | null
    outstanding: string | null
  }>
  filters: { period: string | null; project: string | null; stage: string | null; status: string | null; all: boolean }
  periods: Array<{ id: string; label: string }>
  projects: Array<{ id: string; label: string }>
  stages: string[]
  statuses: string[]
  costs: boolean
}>()

const period = ref(props.filters.period)
const project = ref(props.filters.project)
const stage = ref(props.filters.stage)
const status = ref(props.filters.status)
const all = ref(props.filters.all)

let debounce: ReturnType<typeof setTimeout>
watch([period, project, stage, status, all], () => {
  clearTimeout(debounce)
  debounce = setTimeout(() => {
    router.get(route('project-management.reporting.projects'), {
      period: period.value || undefined,
      project: project.value || undefined,
      stage: stage.value || undefined,
      status: status.value || undefined,
      all: all.value || undefined,
    }, { preserveState: true, replace: true })
  }, 300)
})

function money(amount: string | null) {
  if (amount === null || amount === '') return '—'
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'ZAR' }).format(Number(amount))
  } catch {
    return amount
  }
}

const { el: pageEl, height: pageHeight } = useFitToViewport()
</script>

<template>
  <!--
    The page is the height of the screen; the table scrolls inside it.

    A month of reports across every project runs to hundreds of rows, and letting the page grow
    with them takes the filters and the column headings off the top — so you scroll back up to
    remember which column you are reading, then back down to find your place again.
  -->
  <div ref="pageEl" class="pa-4 d-flex flex-column reports-page" :style="{ blockSize: pageHeight }">
    <div class="mb-4">
      <h4 class="text-h4">{{ t('project_management.monthly_reporting.my_reports') }}</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">
        {{ t('project_management.monthly_reporting.my_reports_sub') }}
      </p>
    </div>

    <VCard border class="mb-4">
      <VCardText class="d-flex flex-wrap gap-3 align-center">
        <VAutocomplete
          v-model="period" :items="props.periods.map(p => ({ title: p.label, value: p.id }))"
          :label="t('project_management.monthly_reporting.filter_month')"
          density="compact" variant="outlined" hide-details clearable style="max-width: 220px"
        />
        <VAutocomplete
          v-model="project" :items="props.projects.map(p => ({ title: p.label, value: p.id }))"
          :label="t('project_management.monthly_reporting.filter_project')"
          density="compact" variant="outlined" hide-details clearable style="max-width: 300px"
        />
        <VSelect
          v-model="stage" :items="props.stages"
          :label="t('project_management.monthly_reporting.filter_stage')"
          density="compact" variant="outlined" hide-details clearable style="max-width: 260px"
        />
        <VSelect
          v-model="status" :items="props.statuses"
          :label="t('project_management.monthly_reporting.filter_status')"
          density="compact" variant="outlined" hide-details clearable style="max-width: 200px"
        />
        <VSwitch
          v-model="all" color="primary" density="compact" hide-details
          :label="t('project_management.monthly_reporting.all_projects')"
        />
      </VCardText>
    </VCard>

    <VCard border class="d-flex flex-column reports-card">
      <div class="table-scroll">
        <VTable v-if="props.rows.length" density="comfortable">
          <thead>
            <tr>
              <th class="text-no-wrap">{{ t('project_management.monthly_reporting.col_month') }}</th>
              <th>{{ t('project_management.monthly_reporting.col_project') }}</th>
              <th class="text-no-wrap">{{ t('project_management.monthly_reporting.col_stage') }}</th>
              <th class="text-no-wrap">{{ t('project_management.monthly_reporting.col_progress') }}</th>
              <th class="text-no-wrap">{{ t('project_management.monthly_reporting.col_status') }}</th>
              <th style="min-inline-size: 24rem">{{ t('project_management.monthly_reporting.col_challenges') }}</th>
              <th v-if="props.costs" class="text-end text-no-wrap">
                {{ t('project_management.monthly_reporting.col_outstanding') }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in props.rows" :key="row.id"
              style="cursor: pointer"
              @click="row.period && router.get(route('project-management.reporting.show', row.period))"
            >
              <td class="text-no-wrap">{{ row.month }}</td>
              <td>
                <div>{{ row.project }}</div>
                <div class="text-caption text-medium-emphasis">{{ row.project_code }}</div>
              </td>
              <td class="text-no-wrap">{{ row.stage ?? '—' }}</td>
              <td class="text-no-wrap">{{ row.progress_percentage ?? '—' }}</td>
              <td class="text-no-wrap">{{ row.project_status ?? '—' }}</td>
              <!-- Bullets and line breaks read as typed. -->
              <td style="white-space: pre-wrap">{{ row.challenges ?? '—' }}</td>
              <td v-if="props.costs" class="text-end text-no-wrap">{{ money(row.outstanding) }}</td>
            </tr>
          </tbody>
        </VTable>
        <VCardText v-else class="text-center text-medium-emphasis py-8">
          {{ t('project_management.monthly_reporting.no_reports') }}
        </VCardText>
      </div>
    </VCard>
  </div>
</template>

<style scoped>
/* Height comes from useFitToViewport — measured, because the shell's navbar and footer are not a
   number this file can know. */
.reports-page { overflow: hidden; }

.reports-card {
  flex: 1 1 auto;
  min-block-size: 0;
}

/*
 * Both directions, inside the card. `min-block-size: 0` is what lets a flex child shrink under its
 * content — without it the table sets the card's height and the page scrolls again.
 */
.table-scroll {
  overflow: auto;
  flex: 1 1 auto;
  min-block-size: 0;
}

/* The headings stay while the rows move, which is the point of scrolling the table and not the page. */
.table-scroll :deep(thead th) {
  position: sticky;
  inset-block-start: 0;
  z-index: 1;
  background: rgb(var(--v-theme-surface));
}
</style>
