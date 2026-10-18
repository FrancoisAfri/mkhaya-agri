<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { computed } from 'vue'

const { t } = useI18n()
const route = ziggyRoute

type Report = {
  uuid: string
  report_name: string
  scope: { value: string; label: string }
  period_start: string
  period_end: string
  total_assets_in_scope: number
  generated_at: string
  generatedBy: { name: string } | null
}

type PaginatedReports = {
  data: Report[]
  current_page: number
  last_page: number
  total: number
  prev_page_url: string | null
  next_page_url: string | null
}

const props = defineProps<{
  reports: PaginatedReports
}>()

const safeReports = computed((): PaginatedReports => props.reports ?? {
  data: [],
  current_page: 1,
  last_page: 1,
  total: 0,
  prev_page_url: null,
  next_page_url: null,
})

function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <h4 class="text-h4 font-weight-bold mb-1">{{ t('assets_utilization_reports_index.page_title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('assets_utilization_reports_index.description') }}</p>
      </div>
      <div class="d-flex gap-2">
        <VBtn :href="route('assets.utilization-dashboard.index')" variant="tonal" prepend-icon="tabler-chart-bar">
          {{ t('assets_utilization_reports_index.dashboard') }}
        </VBtn>
        <VBtn :href="route('assets.utilization-reports.create')" color="primary" prepend-icon="tabler-plus">
          {{ t('assets_utilization_reports_index.new_report') }}
        </VBtn>
      </div>
    </div>

    <VCard elevation="0" border>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('assets_utilization_reports_index.table.report_name') }}</th>
            <th>{{ t('assets_utilization_reports_index.table.scope') }}</th>
            <th>{{ t('assets_utilization_reports_index.table.period') }}</th>
            <th class="text-center">{{ t('assets_utilization_reports_index.table.assets') }}</th>
            <th>{{ t('assets_utilization_reports_index.table.generated_by') }}</th>
            <th>{{ t('assets_utilization_reports_index.table.generated_at') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="r in safeReports.data"
            :key="r.uuid"
            class="cursor-pointer"
            @click="router.visit(route('assets.utilization-reports.show', r.uuid))"
          >
            <td class="font-weight-medium">{{ r.report_name }}</td>
            <td class="text-capitalize">{{ r.scope.label }}</td>
            <td>{{ formatDate(r.period_start) }} → {{ formatDate(r.period_end) }}</td>
            <td class="text-center">{{ r.total_assets_in_scope }}</td>
            <td>{{ r.generatedBy?.name ?? '—' }}</td>
            <td>{{ formatDate(r.generated_at) }}</td>
          </tr>
          <tr v-if="!safeReports.data.length">
            <td colspan="6" class="text-center text-medium-emphasis py-8">
              {{ t('assets_utilization_reports_index.empty') }}
            </td>
          </tr>
        </tbody>
      </VTable>

      <VCardText
        v-if="safeReports.last_page > 1"
        class="d-flex justify-center gap-2 pt-2"
      >
        <VBtn
          size="small"
          variant="tonal"
          :disabled="!safeReports.prev_page_url"
          @click="router.visit(safeReports.prev_page_url!)"
        >
          {{ t('assets_utilization_reports_index.pagination.prev') }}
        </VBtn>
        <span class="text-body-2 align-self-center">
          {{ t('assets_utilization_reports_index.pagination.page') }}
          {{ safeReports.current_page }} / {{ safeReports.last_page }}
        </span>
        <VBtn
          size="small"
          variant="tonal"
          :disabled="!safeReports.next_page_url"
          @click="router.visit(safeReports.next_page_url!)"
        >
          {{ t('assets_utilization_reports_index.pagination.next') }}
        </VBtn>
      </VCardText>
    </VCard>
  </div>
</template>
