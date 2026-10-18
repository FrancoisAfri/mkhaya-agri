<script setup lang="ts">
import { computed, ref } from 'vue'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

// ── Types ──────────────────────────────────────────────────────────────────────
interface ReportData {
  headers: string[]
  rows: (string | number)[][]
}

// ── Props ──────────────────────────────────────────────────────────────────────
const props = defineProps<{
  reportKeys: Record<string, string>
  reports: Record<string, ReportData>
}>()

// ── State ──────────────────────────────────────────────────────────────────────
const keyOptions = computed(() =>
  Object.entries(props.reportKeys).map(([value, title]) => ({ value, title })),
)

const selectedKey = ref<string>(keyOptions.value[0]?.value ?? 'all')

const current = computed<ReportData>(() =>
  props.reports[selectedKey.value] ?? { headers: [], rows: [] }
)

const tableHeaders = computed(() =>
  current.value.headers.map((h, i) => ({
    title: h,
    key: String(i),
    sortable: true
  })),
)

const tableItems = computed(() =>
  current.value.rows.map(row => {
    const obj: Record<string, string | number> = {}
    row.forEach((cell, i) => { obj[String(i)] = cell })
    return obj
  }),
)

// ── Methods ────────────────────────────────────────────────────────────────────
function exportReport(format: 'pdf' | 'xlsx'): void {
  window.open(route('suppliers.reports.export', { key: selectedKey.value, format }), '_blank')
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <h1 class="text-h4">{{ t('supplier_reports.page_title') }}</h1>
        <p class="text-medium-emphasis mb-0">{{ t('supplier_reports.page_description') }}</p>
      </div>
      <div class="d-flex align-center gap-2">
        <VBtn
          color="error"
          variant="tonal"
          prepend-icon="tabler-file-type-pdf"
          @click="exportReport('pdf')"
        >
          {{ t('supplier_reports.export_pdf') }}
        </VBtn>
        <VBtn
          color="success"
          variant="tonal"
          prepend-icon="tabler-file-spreadsheet"
          @click="exportReport('xlsx')"
        >
          {{ t('supplier_reports.export_excel') }}
        </VBtn>
      </div>
    </div>

    <VCard elevation="0" border>
      <VCardText>
        <VSelect
          v-model="selectedKey"
          :items="keyOptions"
          item-title="title"
          item-value="value"
          :label="t('supplier_reports.report_label')"
          variant="outlined"
          density="compact"
          style="max-width: 360px"
        />
      </VCardText>
      <VDivider />
      <VDataTable
        :headers="tableHeaders"
        :items="tableItems"
        :items-per-page="25"
        density="comfortable"
        class="text-no-wrap"
      >
        <template #no-data>
          <div class="text-center py-8 text-medium-emphasis">
            {{ t('supplier_reports.no_data') }}
          </div>
        </template>
      </VDataTable>
    </VCard>
  </VContainer>
</template>
