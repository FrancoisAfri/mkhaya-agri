<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { computed } from 'vue'

const { t } = useI18n()
const route = ziggyRoute

type Snapshot = {
  id: number
  utilization_rate: number
  total_operating_hours: number
  total_downtime_minutes: number
  log_entry_count: number
  asset: { uuid: string; name: string; asset_tag: string | null } | null
}

type Report = {
  uuid: string
  report_name: string
  scope: { value: string; label: string }
  period_start: string
  period_end: string
  total_assets_in_scope: number
  summary_stats: {
    avg_utilization_rate?: number
    median_utilization_rate?: number
    total_operating_hours?: number
    total_downtime_hours?: number
  }
  generated_at: string
  generatedBy: { name: string } | null
}

type PaginatedSnapshots = {
  data: Snapshot[]
  current_page: number
  last_page: number
  total: number
  prev_page_url: string | null
  next_page_url: string | null
}

const props = defineProps<{
  report: Report
  snapshots: PaginatedSnapshots
}>()

const safeSnapshots = computed((): PaginatedSnapshots => props.snapshots ?? {
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

function utilizationColor(rate: number) {
  if (rate >= 80) return 'success'
  if (rate >= 50) return 'warning'
  return 'error'
}
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <VBreadcrumbs :items="[
          { title: t('assets_utilization_reports_show.breadcrumbs.utilization_reports'), href: route('assets.utilization-reports.index') },
          { title: report.report_name },
        ]" class="pa-0 mb-1" />
        <h4 class="text-h4 font-weight-bold">{{ report.report_name }}</h4>
        <p class="text-medium-emphasis mb-0">
          {{ formatDate(report.period_start) }} → {{ formatDate(report.period_end) }} ·
          {{ report.scope.label }} · {{ report.total_assets_in_scope }} {{ t('assets_utilization_reports_show.assets_count') }}
        </p>
      </div>
    </div>

    <VRow class="mb-6">
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-4">
            <p class="text-caption text-medium-emphasis mb-1">{{ t('assets_utilization_reports_show.stats.avg_utilization') }}</p>
            <p class="text-h5 font-weight-bold mb-0">{{ (report.summary_stats?.avg_utilization_rate ?? 0).toFixed(1) }}%</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-4">
            <p class="text-caption text-medium-emphasis mb-1">{{ t('assets_utilization_reports_show.stats.median_utilization') }}</p>
            <p class="text-h5 font-weight-bold mb-0">{{ (report.summary_stats?.median_utilization_rate ?? 0).toFixed(1) }}%</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-4">
            <p class="text-caption text-medium-emphasis mb-1">{{ t('assets_utilization_reports_show.stats.total_operating_hrs') }}</p>
            <p class="text-h5 font-weight-bold mb-0">{{ (report.summary_stats?.total_operating_hours ?? 0).toFixed(0) }}</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-4">
            <p class="text-caption text-medium-emphasis mb-1">{{ t('assets_utilization_reports_show.stats.total_downtime_hrs') }}</p>
            <p class="text-h5 font-weight-bold mb-0">{{ (report.summary_stats?.total_downtime_hours ?? 0).toFixed(0) }}</p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard elevation="0" border>
      <VCardText class="pa-4 pb-0">
        <p class="text-subtitle-1 font-weight-semibold">{{ t('assets_utilization_reports_show.snapshots.title') }}</p>
      </VCardText>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('assets_utilization_reports_show.snapshots.asset') }}</th>
            <th class="text-center">{{ t('assets_utilization_reports_show.snapshots.utilization') }}</th>
            <th class="text-right">{{ t('assets_utilization_reports_show.snapshots.operating_hrs') }}</th>
            <th class="text-right">{{ t('assets_utilization_reports_show.snapshots.downtime_min') }}</th>
            <th class="text-right">{{ t('assets_utilization_reports_show.snapshots.log_entries') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="s in safeSnapshots.data"
            :key="s.id"
            class="cursor-pointer"
            @click="router.visit(route('assets.show', s.asset?.uuid ?? ''))"
          >
            <td>
              <p class="mb-0 font-weight-medium">{{ s.asset?.name ?? '—' }}</p>
              <p class="text-caption text-medium-emphasis mb-0">{{ s.asset?.asset_tag }}</p>
            </td>
            <td class="text-center">
              <VChip size="small" :color="utilizationColor(s.utilization_rate)">
                {{ s.utilization_rate.toFixed(1) }}%
              </VChip>
            </td>
            <td class="text-right">{{ Number(s.total_operating_hours).toFixed(1) }}</td>
            <td class="text-right">{{ s.total_downtime_minutes }}</td>
            <td class="text-right">{{ s.log_entry_count }}</td>
          </tr>
          <tr v-if="!safeSnapshots.data.length">
            <td colspan="5" class="text-center text-medium-emphasis py-8">
              {{ t('assets_utilization_reports_show.empty') }}
            </td>
          </tr>
        </tbody>
      </VTable>

      <VCardText
        v-if="safeSnapshots.last_page > 1"
        class="d-flex justify-center gap-2 pt-2"
      >
        <VBtn
          size="small"
          variant="tonal"
          :disabled="!safeSnapshots.prev_page_url"
          @click="router.visit(safeSnapshots.prev_page_url!)"
        >
          {{ t('assets_utilization_reports_show.pagination.prev') }}
        </VBtn>
        <span class="text-body-2 align-self-center">
          {{ t('assets_utilization_reports_show.pagination.page') }}
          {{ safeSnapshots.current_page }} / {{ safeSnapshots.last_page }}
        </span>
        <VBtn
          size="small"
          variant="tonal"
          :disabled="!safeSnapshots.next_page_url"
          @click="router.visit(safeSnapshots.next_page_url!)"
        >
          {{ t('assets_utilization_reports_show.pagination.next') }}
        </VBtn>
      </VCardText>
    </VCard>
  </div>
</template>
