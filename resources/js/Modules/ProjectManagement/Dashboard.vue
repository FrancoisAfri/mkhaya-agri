<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import PmCharts from './Insights/PmCharts.vue'

const { t } = useI18n()
const route = ziggyRoute

type ProjectSummary = {
  id: string; code: string; name: string; status: string; stage: string
  priority: string; client: string | null; currency: string
  budget_amount: string | null; planned_start: string | null; planned_end: string | null
}

type EvidenceItem = {
  id: string; event_type: string; subject_type: string; source_module: string
  actor_type: string; occurred_at: string | null; occurred_tz: string
  quantity: string | null; unit: string | null; amount: string | null; currency: string | null
  confidence: string; superseded_by: string | null
}

const props = defineProps<{
  kpis: {
    projects_total: number; projects_active: number; projects_on_hold: number
    projects_complete: number; tasks_open: number; tasks_overdue: number; evidence_7d: number
  }
  byStatus: Record<string, number>
  recentProjects: ProjectSummary[]
  recentEvidence: EvidenceItem[]
  charts: Record<string, any>
  canViewCosts: boolean
  capabilities: Record<string, boolean>
}>()

/**
 * The five charts, and widths that fill every row.
 *
 * Two layouts rather than one, because Budget vs actual is absent entirely for anyone without the
 * cost grant. Leaving the rest at their five-chart widths would strand Progress by project alone
 * at half width — the same hole this layout exists to close, just for a different reason.
 */
const chartKeys = computed(() => {
  const keys = ['schedule_health', 'task_status_mix', 'evidence_trend', 'progress_by_project']

  return props.canViewCosts ? [...keys, 'budget_vs_actual'] : keys
})

const chartSpans = computed(() => props.canViewCosts
  // 6+6 / 12 / 6+6 on md, 3+3+6 / 6+6 on lg.
  ? {
      schedule_health: { md: 6, lg: 3 },
      task_status_mix: { md: 6, lg: 3 },
      evidence_trend: { md: 12, lg: 6 },
      progress_by_project: { md: 6, lg: 6 },
      budget_vs_actual: { md: 6, lg: 6 },
    }
  // 6+6 / 12 / 12 on md, 4+4+4 / 12 on lg.
  : {
      schedule_health: { md: 6, lg: 4 },
      task_status_mix: { md: 6, lg: 4 },
      evidence_trend: { md: 12, lg: 4 },
      progress_by_project: { md: 12, lg: 12 },
    })

const statusColor: Record<string, string> = {
  draft: 'grey', active: 'success', on_hold: 'warning',
  completed: 'info', cancelled: 'error', archived: 'grey',
}

/**
 * 'progress.reported' is the ledger's storage value, not something to show a person. Same mapping
 * the evidence timeline uses; the dot becomes an underscore because i18n keys nest on dots.
 */
function eventLabel(value: string): string {
  return t(`project_management.evidence.types.${value.replace(/\./g, '_')}`)
}

/** Money arrives as a decimal string and is formatted for display only — never parsed for maths. */
function money(amount: string | null | undefined, currency: string | null | undefined) {
  // Redacted for external roles: the key is absent, not null (PM-1021).
  if (amount === null || amount === undefined) return '—'
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency || 'ZAR' })
      .format(Number(amount))
  } catch {
    return `${currency ?? ''} ${amount}`.trim()
  }
}

/** Shown with its originating zone, because "08:00" alone is not an answer to "when". */
function when(iso: string | null, tz: string) {
  if (!iso) return '—'
  return `${new Date(iso).toLocaleString()} (${tz})`
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="text-h4">{{ t('project_management.dashboard.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ t('project_management.dashboard.subtitle') }}
        </p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="router.get(route('project-management.projects.index'))">
        {{ t('project_management.dashboard.go_to_projects') }}
      </VBtn>
    </div>

    <!--
      A grid rather than a VRow: there are five tiles and twelve columns, so no combination of
      VCol widths divides evenly — the row always ended with a gap, or broke 3-and-2. auto-fit
      gives five equal tiles that share the width and wrap as a whole when the screen narrows.
    -->
    <div class="pm-kpi-strip mb-2">
      <VCard
        v-for="kpi in [
          { key: 'projects_active', value: kpis.projects_active, icon: 'tabler-folders', color: 'success' },
          { key: 'projects_on_hold', value: kpis.projects_on_hold, icon: 'tabler-player-pause', color: 'warning' },
          { key: 'tasks_open', value: kpis.tasks_open, icon: 'tabler-checkbox', color: 'primary' },
          { key: 'tasks_overdue', value: kpis.tasks_overdue, icon: 'tabler-alert-triangle', color: 'error' },
          { key: 'evidence_7d', value: kpis.evidence_7d, icon: 'tabler-clipboard-check', color: 'info' },
        ]"
        :key="kpi.key"
        border
      >
        <VCardText class="d-flex align-center gap-3">
          <VAvatar :color="kpi.color" variant="tonal" rounded size="42">
            <VIcon :icon="kpi.icon" />
          </VAvatar>
          <div class="min-w-0">
            <div class="text-h5">{{ kpi.value }}</div>
            <div class="text-caption text-medium-emphasis text-truncate">
              {{ t(`project_management.dashboard.kpi_${kpi.key}`) }}
            </div>
          </div>
        </VCardText>
      </VCard>
    </div>

    <!-- Charts. The full set, with drill-through, lives on the Insights pages; these are the
         four a portfolio manager wants without clicking anywhere. -->
    <div class="d-flex align-center justify-space-between mb-2 mt-4 flex-wrap gap-2">
      <h5 class="text-h5 mb-0">{{ t('project_management.insights.at_a_glance') }}</h5>
      <VBtn
        variant="text" size="small" append-icon="tabler-arrow-right"
        @click="router.get(route('project-management.insights.analytics'))"
      >
        {{ t('project_management.insights.see_all_charts') }}
      </VBtn>
    </div>

    <!--
      Widths are given explicitly rather than inherited. PmCharts' defaults are sized for the
      Analytics page, which draws every chart; this page draws a handful, and their default widths
      (4+4+8+6+6) leave two rows a third empty. See chartSpans for the arithmetic.

      `keep-empty` holds Budget vs actual's place when no active project carries a budget yet: it
      is the one chart here that can legitimately have no rows, and without this it disappeared and
      left its neighbour stranded at half width.
    -->
    <PmCharts
      :charts="charts as any"
      :only="chartKeys"
      :span="chartSpans"
      :keep-empty="['budget_vs_actual']"
      class="mb-4"
    />

    <VRow>
      <VCol cols="12" md="6">
        <VCard border class="h-100">
          <VCardItem>
            <VCardTitle>{{ t('project_management.dashboard.recent_projects') }}</VCardTitle>
          </VCardItem>
          <VDivider />
          <VTable v-if="recentProjects.length" density="comfortable">
            <thead>
              <tr>
                <th>{{ t('project_management.projects.col_code') }}</th>
                <th>{{ t('project_management.projects.col_name') }}</th>
                <th>{{ t('project_management.projects.col_status') }}</th>
                <th class="text-right">{{ t('project_management.projects.col_budget') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="p in recentProjects" :key="p.id" style="cursor:pointer"
                @click="router.get(route('project-management.projects.show', p.id))"
              >
                <td>{{ p.code }}</td>
                <td>{{ p.name }}</td>
                <td>
                  <VChip size="small" :color="statusColor[p.status] ?? 'grey'" label>
                    {{ t(`project_management.status.${p.status}`) }}
                  </VChip>
                </td>
                <td class="text-right">{{ money(p.budget_amount, p.currency) }}</td>
              </tr>
            </tbody>
          </VTable>
          <VCardText v-else class="text-medium-emphasis">
            {{ t('project_management.dashboard.no_projects') }}
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="6">
        <VCard border class="h-100">
          <VCardItem>
            <VCardTitle>{{ t('project_management.dashboard.recent_evidence') }}</VCardTitle>
            <template #append>
              <VBtn variant="text" size="small" @click="router.get(route('project-management.evidence.timeline'))">
                {{ t('project_management.dashboard.view_timeline') }}
              </VBtn>
            </template>
          </VCardItem>
          <VDivider />
          <VList v-if="recentEvidence.length" lines="two">
            <VListItem v-for="e in recentEvidence" :key="e.id">
              <template #prepend>
                <VAvatar variant="tonal" color="info" size="36">
                  <VIcon icon="tabler-clipboard-check" size="20" />
                </VAvatar>
              </template>
              <VListItemTitle>{{ eventLabel(e.event_type) }}</VListItemTitle>
              <VListItemSubtitle>
                {{ when(e.occurred_at, e.occurred_tz) }} · {{ e.source_module }}
                <span v-if="e.quantity"> · {{ e.quantity }} {{ e.unit }}</span>
                <span v-if="e.amount"> · {{ money(e.amount, e.currency) }}</span>
              </VListItemSubtitle>
            </VListItem>
          </VList>
          <VCardText v-else class="text-medium-emphasis">
            {{ t('project_management.dashboard.no_evidence') }}
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>

<style scoped lang="scss">
.pm-kpi-strip {
  display: grid;
  // Matches the Vuetify row gutter, so the tiles line up with the cards below them.
  gap: 1.5rem;
  // 11rem is the narrowest a tile stays readable at: avatar, a figure and its label on one line.
  // Below that the whole strip drops to fewer columns rather than squashing the text.
  grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
}

// The label truncates instead of wrapping to a second line, which would make one tile taller
// than the rest of the row. The title attribute is not needed — the figure is the point, and
// the labels are short in every locale.
.min-w-0 {
  min-inline-size: 0;
}
</style>
