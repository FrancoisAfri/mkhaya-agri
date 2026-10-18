<script setup lang="ts">
import { computed } from 'vue'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const route = ziggyRoute
const { t } = useI18n()

const props = defineProps<{ report: string; rows: { data: any[] }; filters: Record<string, any> }>()

/** Column keys come straight from the query, so the table adapts per report. */
const columns = computed(() => (props.rows.data.length ? Object.keys(props.rows.data[0]) : []))
const label = (c: string) => c.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())
</script>

<template>
  <VContainer fluid class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6">
      <h1 class="text-h5 font-weight-bold">{{ t(`fleet_job_cards.report.${report}`) }}</h1>
      <div class="d-flex gap-2">
        <VBtn variant="tonal" prepend-icon="tabler-download" :href="route('fleet.job-cards.reports.export', report)">
          {{ t('fleet_job_cards.export_csv') }}
        </VBtn>
        <VBtn variant="text" :href="route('fleet.job-cards.reports.index')">{{ t('fleet_job_cards.back') }}</VBtn>
      </div>
    </div>

    <VCard>
      <VTable density="compact">
        <thead>
          <tr><th v-for="c in columns" :key="c">{{ label(c) }}</th></tr>
        </thead>
        <tbody>
          <tr v-for="(row, i) in rows.data" :key="i">
            <td v-for="c in columns" :key="c">{{ row[c] ?? '—' }}</td>
          </tr>
          <tr v-if="!rows.data.length">
            <td :colspan="columns.length || 1" class="text-center text-medium-emphasis py-6">
              {{ t('fleet_job_cards.no_results') }}
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </VContainer>
</template>
