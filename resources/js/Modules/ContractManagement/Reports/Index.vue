<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type ReportData = {
  headers: string[]
  rows: (string | number)[][]
  total: number
  truncated: boolean
}

const props = defineProps<{
  reportKeys: Record<string, string>
  reports: Record<string, ReportData>
}>()

const keyList = computed(() => Object.entries(props.reportKeys).map(([value, title]) => ({ value, title })))
const selected = ref<string>(keyList.value[0]?.value ?? 'all')

const current = computed<ReportData>(() => props.reports[selected.value] ?? { headers: [], rows: [], total: 0, truncated: false })

const tableHeaders = computed(() =>
  current.value.headers.map((header, i) => ({ title: header, key: `c${i}`, sortable: true })),
)
const tableItems = computed(() =>
  current.value.rows.map(row => {
    const obj: Record<string, string | number> = {}
    row.forEach((cell, i) => { obj[`c${i}`] = cell })
    return obj
  }),
)

function exportReport(format: 'pdf' | 'xlsx') {
  window.open(route('contracts.reports.export', { key: selected.value, format }), '_blank')
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-3">
        <VAvatar color="primary" variant="tonal" size="48" rounded>
          <VIcon icon="tabler-file-analytics" size="26" />
        </VAvatar>
        <div>
          <h4 class="text-h4 mb-0">{{ t('contracts_reports.page_title') }}</h4>
          <p class="text-caption text-medium-emphasis mb-0">{{ t('contracts_reports.description') }}</p>
        </div>
      </div>
      <div class="d-flex align-center gap-2">
        <VBtn variant="tonal" color="primary" prepend-icon="tabler-chart-pie" @click="router.visit(route('contracts.analytics'))">
          {{ t('contracts_reports.analytics') }}
        </VBtn>
        <VBtn variant="tonal" color="secondary" prepend-icon="tabler-calendar-repeat" @click="router.visit(route('contracts.scheduled-reports.index'))">
          {{ t('contracts_reports.scheduled_reports') }}
        </VBtn>
      </div>
    </div>

    <VCard border>
      <VCardText class="d-flex align-center justify-space-between flex-wrap gap-3">
        <VSelect
          v-model="selected"
          :items="keyList"
          item-title="title"
          item-value="value"
          :label="t('contracts_reports.report_label')"
          variant="outlined"
          density="compact"
          hide-details
          style="max-width: 320px"
        />
        <div class="d-flex align-center gap-2">
          <VChip size="small" variant="tonal" color="primary">{{ t('contracts_reports.records', { total: current.total }) }}</VChip>
          <VBtn color="error" variant="tonal" prepend-icon="tabler-file-type-pdf" @click="exportReport('pdf')">
            {{ t('contracts_reports.export_pdf') }}
          </VBtn>
          <VBtn color="success" variant="tonal" prepend-icon="tabler-file-type-xls" @click="exportReport('xlsx')">
            {{ t('contracts_reports.export_excel') }}
          </VBtn>
        </div>
      </VCardText>

      <VAlert
        v-if="current.truncated"
        type="info"
        variant="tonal"
        density="compact"
        class="mx-4 mb-2"
      >
        {{ t('contracts_reports.truncated_alert', { total: current.total }) }}
      </VAlert>

      <VDivider />

      <VDataTable
        :headers="tableHeaders"
        :items="tableItems"
        :items-per-page="25"
        density="comfortable"
        class="text-no-wrap"
      >
        <template #no-data>
          <div class="text-center text-medium-emphasis py-8">{{ t('contracts_reports.no_data') }}</div>
        </template>
      </VDataTable>
    </VCard>
  </VContainer>
</template>
