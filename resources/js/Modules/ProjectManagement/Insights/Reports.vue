<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Dataset = {
  label: string
  area: string
  /** Which screen the rows are captured on — see project_management.report_sources.*. */
  source: string
  headers: string[]
  rows: any[][]
  truncated: boolean
}

const props = defineProps<{
  summary: Record<string, number>
  reports: Record<string, Dataset>
  projects: Array<{ id: string; label: string }>
  filters: { from: string | null; to: string | null; project: string | null }
  can: { costs: boolean }
}>()

const keys = computed(() => Object.keys(props.reports))
const active = ref(keys.value[0] ?? '')

const current = computed(() => props.reports[active.value])

/** Grouped by the part of the module each belongs to, rather than one flat run of fourteen tabs. */
const areas = computed(() => {
  const grouped: Record<string, string[]> = {}

  for (const key of keys.value) {
    const area = props.reports[key].area
    ;(grouped[area] ??= []).push(key)
  }

  return grouped
})

// ── Filters ──────────────────────────────────────────────────────────────────

const from = ref(props.filters.from ?? '')
const to = ref(props.filters.to ?? '')
const project = ref(props.filters.project ?? '')

function applyFilters() {
  router.get(route('project-management.insights.reports'), {
    from: from.value || undefined,
    to: to.value || undefined,
    project: project.value || undefined,
  }, { preserveState: true, preserveScroll: true })
}

function clearFilters() {
  from.value = ''
  to.value = ''
  project.value = ''
  applyFilters()
}

function exportAs(format: 'csv' | 'xlsx' | 'pdf') {
  // A normal navigation, not XHR: the browser has to receive it as a download.
  window.location.href = route('project-management.insights.export', active.value)
    + `?from=${from.value}&to=${to.value}&project=${project.value}&format=${format}`
}

function cell(value: any) {
  if (value === null || value === undefined || value === '') return '—'
  return value
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('project_management.insights.reports_title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('project_management.insights.reports_sub') }}</p>
      </div>
      <VBtn
        variant="tonal" prepend-icon="tabler-chart-histogram"
        @click="router.get(route('project-management.insights.analytics'))"
      >
        {{ t('project_management.insights.analytics_title') }}
      </VBtn>
    </div>

    <!-- Headline figures -->
    <VRow class="mb-2">
      <VCol v-for="(value, key) in summary" :key="key" cols="6" md="3" lg="2">
        <VCard border>
          <VCardText class="py-3">
            <div class="text-caption text-medium-emphasis">{{ t(`project_management.insights.kpi_${key}`) }}</div>
            <div class="text-h6">{{ value }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Filters -->
    <VCard border class="mb-4">
      <VCardText class="d-flex flex-wrap align-center gap-3">
        <VTextField
          v-model="from" type="date" :label="t('project_management.insights.from')"
          variant="outlined" density="compact" hide-details style="max-width: 190px"
        />
        <VTextField
          v-model="to" type="date" :label="t('project_management.insights.to')"
          variant="outlined" density="compact" hide-details style="max-width: 190px"
        />
        <VAutocomplete
          v-model="project"
          :items="[{ title: t('project_management.insights.all_projects'), value: '' }, ...projects.map(p => ({ title: p.label, value: p.id }))]"
          :label="t('project_management.evidence.filter_project')"
          variant="outlined" density="compact" hide-details style="min-width: 240px"
        />
        <VBtn color="primary" @click="applyFilters">{{ t('project_management.insights.apply') }}</VBtn>
        <VBtn variant="text" @click="clearFilters">{{ t('project_management.insights.clear') }}</VBtn>
      </VCardText>
    </VCard>

    <VRow>
      <!-- Report picker, grouped by area -->
      <VCol cols="12" md="3">
        <VCard border>
          <VList density="compact" nav>
            <template v-for="(group, area) in areas" :key="area">
              <VListSubheader>{{ t(`project_management.insights.area_${area}`) }}</VListSubheader>
              <VListItem
                v-for="key in group" :key="key"
                :active="active === key" color="primary"
                @click="active = key"
              >
                <VListItemTitle class="text-body-2">{{ reports[key].label }}</VListItemTitle>
                <template #append>
                  <span class="text-caption text-medium-emphasis">{{ reports[key].rows.length }}</span>
                </template>
              </VListItem>
            </template>
          </VList>
        </VCard>

        <VAlert v-if="!can.costs" type="info" variant="tonal" density="compact" class="mt-3">
          {{ t('project_management.insights.no_cost_access') }}
        </VAlert>
      </VCol>

      <!-- The dataset -->
      <VCol cols="12" md="9">
        <VCard v-if="current" border>
          <VCardItem>
            <VCardTitle class="text-body-1">{{ current.label }}</VCardTitle>
            <!--
              Where the numbers come from. An empty BI table with no explanation reads as a broken
              feature; nine times out of ten it means nobody has been added to a project yet, and
              saying so is the difference between a support ticket and somebody getting on with it.
            -->
            <VCardSubtitle>{{ t(`project_management.report_sources.${current.source}`) }}</VCardSubtitle>
            <template #append>
              <!-- Three formats for three uses: a spreadsheet to pivot, one to hand around, and
                   a document for a board pack that must not be edited on the way. -->
              <VMenu>
                <template #activator="{ props: menu }">
                  <VBtn v-bind="menu" size="small" variant="tonal" prepend-icon="tabler-download"
                        append-icon="tabler-chevron-down">
                    {{ t('project_management.insights.export') }}
                  </VBtn>
                </template>
                <VList density="compact">
                  <VListItem prepend-icon="tabler-file-type-csv" @click="exportAs('csv')">
                    <VListItemTitle>{{ t('project_management.insights.export_csv') }}</VListItemTitle>
                  </VListItem>
                  <VListItem prepend-icon="tabler-file-spreadsheet" @click="exportAs('xlsx')">
                    <VListItemTitle>{{ t('project_management.insights.export_xlsx') }}</VListItemTitle>
                  </VListItem>
                  <VListItem prepend-icon="tabler-file-type-pdf" @click="exportAs('pdf')">
                    <VListItemTitle>{{ t('project_management.insights.export_pdf') }}</VListItemTitle>
                  </VListItem>
                </VList>
              </VMenu>
            </template>
          </VCardItem>
          <VDivider />

          <VAlert v-if="current.truncated" type="info" variant="tonal" density="compact" class="ma-3">
            {{ t('project_management.insights.truncated') }}
          </VAlert>

          <div v-if="current.rows.length" class="table-scroll">
            <VTable density="compact">
              <thead>
                <tr>
                  <th v-for="header in current.headers" :key="header" class="text-no-wrap">{{ header }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, i) in current.rows" :key="i">
                  <td v-for="(value, j) in row" :key="j" class="text-no-wrap">{{ cell(value) }}</td>
                </tr>
              </tbody>
            </VTable>
          </div>

          <VCardText v-else class="text-center text-medium-emphasis py-8">
            <p class="mb-2">{{ t('project_management.insights.no_rows') }}</p>
            <p class="text-caption mb-0">{{ t(`project_management.report_sources.${current.source}`) }}</p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>

<style scoped>
/* Wide datasets scroll inside the card rather than pushing the page sideways. */
.table-scroll {
  overflow-x: auto;
  max-block-size: 60vh;
  overflow-y: auto;
}
</style>
