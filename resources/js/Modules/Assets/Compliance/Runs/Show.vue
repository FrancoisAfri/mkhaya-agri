<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Check = {
  id: number
  check_result: { value: string; label: string; color: string }
  last_compliant_at: string | null
  next_due_at: string | null
  days_overdue: number | null
  asset: { uuid: string; name: string; asset_tag: string | null } | null
  rule: { name: string; interval_days: number } | null
}

type Run = {
  uuid: string
  scope: string
  total_checked: number
  pass_count: number
  fail_count: number
  overdue_count: number
  not_applicable_count: number
  compliance_rate: number
  run_at: string
  runBy: { name: string } | null
}

const props = defineProps<{
  run: Run
  checks: {
    data: Check[]
    meta: { current_page: number; last_page: number; total: number }
    links: { prev: string | null; next: string | null }
  }
  filters: { result?: string }
}>()

const resultFilter = ref(props.filters.result ?? '')

const resultOptions = computed(() => [
  { title: t('assets_compliance_runs_show.filter.all'), value: '' },
  { title: t('assets_compliance_runs_show.filter.pass'), value: 'pass' },
  { title: t('assets_compliance_runs_show.filter.fail'), value: 'fail' },
  { title: t('assets_compliance_runs_show.filter.overdue'), value: 'overdue' },
  { title: t('assets_compliance_runs_show.filter.not_applicable'), value: 'not_applicable' },
])

function applyFilter() {
  router.get(route('assets.compliance-runs.show', props.run.uuid), { result: resultFilter.value || undefined }, { preserveState: true, replace: true })
}

function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}

function rateColor(rate: number) {
  if (rate >= 90) return 'success'
  if (rate >= 70) return 'warning'
  return 'error'
}
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <VBreadcrumbs :items="[
          { title: t('assets_compliance_runs_show.breadcrumbs.compliance'), href: route('assets.compliance-dashboard.index') },
          { title: t('assets_compliance_runs_show.breadcrumbs.runs'), href: route('assets.compliance-runs.index') },
          { title: formatDate(run.run_at) },
        ]" class="pa-0 mb-1" />
        <h4 class="text-h4 font-weight-bold">{{ t('assets_compliance_runs_show.title_prefix') }} {{ formatDate(run.run_at) }}</h4>
      </div>
      <VChip size="large" :color="rateColor(run.compliance_rate)">{{ run.compliance_rate.toFixed(1) }}%</VChip>
    </div>

    <VRow class="mb-4">
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-4 text-center">
            <p class="text-caption text-medium-emphasis mb-1">{{ t('assets_compliance_runs_show.stats.checked') }}</p>
            <p class="text-h5 font-weight-bold mb-0">{{ run.total_checked }}</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-4 text-center">
            <p class="text-caption text-medium-emphasis mb-1">{{ t('assets_compliance_runs_show.stats.passing') }}</p>
            <p class="text-h5 font-weight-bold mb-0">{{ run.pass_count }}</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-4 text-center">
            <p class="text-caption text-medium-emphasis mb-1">{{ t('assets_compliance_runs_show.stats.failing') }}</p>
            <p class="text-h5 font-weight-bold mb-0">{{ run.fail_count }}</p>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard elevation="0" border>
          <VCardText class="pa-4 text-center">
            <p class="text-caption text-medium-emphasis mb-1">{{ t('assets_compliance_runs_show.stats.overdue') }}</p>
            <p class="text-h5 font-weight-bold mb-0">{{ run.overdue_count }}</p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard elevation="0" border class="mb-4">
      <VCardText class="pa-4">
        <VSelect
          v-model="resultFilter"
          :items="resultOptions"
          item-title="title"
          item-value="value"
          :label="t('assets_compliance_runs_show.filter.label')"
          density="compact"
          hide-details
          style="max-width:200px"
          @update:model-value="applyFilter"
        />
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('assets_compliance_runs_show.table.asset') }}</th>
            <th>{{ t('assets_compliance_runs_show.table.rule') }}</th>
            <th>{{ t('assets_compliance_runs_show.table.result') }}</th>
            <th>{{ t('assets_compliance_runs_show.table.last_compliant') }}</th>
            <th>{{ t('assets_compliance_runs_show.table.next_due') }}</th>
            <th class="text-right">{{ t('assets_compliance_runs_show.table.days_overdue') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="c in checks.data" :key="c.id">
            <td>
              <p class="mb-0 font-weight-medium">{{ c.asset?.name ?? '—' }}</p>
              <p class="text-caption text-medium-emphasis mb-0">{{ c.asset?.asset_tag }}</p>
            </td>
            <td>
              <p class="mb-0">{{ c.rule?.name ?? '—' }}</p>
              <p v-if="c.rule" class="text-caption text-medium-emphasis mb-0">{{ t('assets_compliance_runs_show.table.every') }} {{ c.rule.interval_days }}{{ t('assets_compliance_runs_show.days_abbr') }}</p>
            </td>
            <td><VChip size="small" :color="c.check_result.color">{{ c.check_result.label }}</VChip></td>
            <td>{{ formatDate(c.last_compliant_at) }}</td>
            <td>{{ formatDate(c.next_due_at) }}</td>
            <td class="text-right">
              <span v-if="c.days_overdue" class="text-error">{{ c.days_overdue }}</span>
              <span v-else>—</span>
            </td>
          </tr>
          <tr v-if="!checks.data.length">
            <td colspan="6" class="text-center text-medium-emphasis py-8">{{ t('assets_compliance_runs_show.empty') }}</td>
          </tr>
        </tbody>
      </VTable>
      <VCardText v-if="checks.meta.last_page > 1" class="d-flex justify-center gap-2 pt-2">
        <VBtn size="small" variant="tonal" :disabled="!checks.links.prev" @click="router.visit(checks.links.prev!)">{{ t('assets_compliance_runs_show.pagination.prev') }}</VBtn>
        <span class="text-body-2 align-self-center">{{ t('assets_compliance_runs_show.pagination.page') }} {{ checks.meta.current_page }} / {{ checks.meta.last_page }}</span>
        <VBtn size="small" variant="tonal" :disabled="!checks.links.next" @click="router.visit(checks.links.next!)">{{ t('assets_compliance_runs_show.pagination.next') }}</VBtn>
      </VCardText>
    </VCard>
  </div>
</template>
