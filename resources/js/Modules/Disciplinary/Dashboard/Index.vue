<script setup lang="ts">
import { computed } from 'vue'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface StatusCount {
  id: number; name: string; color: string; is_closed: boolean; cases_count: number
}
interface CaseSummary {
  id: number; uuid: string; case_number: string; title: string
  employee_name: string; category: string | null; status: string | null
  status_color: string | null; incident_date: string; hearing_date: string | null
}
interface CategoryCount {
  name: string; severity_level: string; total: number
}
interface ModuleLink {
  label: string; route: string; icon: string; color: string; stat_key: string | null
}

const props = defineProps<{
  stats: {
    total_open: number; total_closed: number; hearings_this_week: number; overdue_notices: number
    active_warnings: number; active_suspensions: number; pending_appeals: number
    pending_actions: number; high_risk_employees: number
  }
  statusCounts: StatusCount[]
  openCases: CaseSummary[]
  upcomingHearings: CaseSummary[]
  categoryCounts: CategoryCount[]
  moduleLinks: ModuleLink[]
}>()

const severityColor = (s: string) => ({ minor: 'success', serious: 'warning', gross: 'error' })[s] ?? 'grey'
const cap = (s: string | null | undefined) => s ? s.charAt(0).toUpperCase() + s.slice(1) : '—'

const statMap: Record<string, number> = computed(() => ({
  active_warnings:     props.stats.active_warnings,
  active_suspensions:  props.stats.active_suspensions,
  pending_appeals:     props.stats.pending_appeals,
  pending_actions:     props.stats.pending_actions,
  high_risk_employees: props.stats.high_risk_employees,
})).value

const kpiCards = computed(() => [
  { label: t('disciplinary.open_cases'),          value: props.stats.total_open,           icon: 'tabler-briefcase',   color: 'warning' },
  { label: t('disciplinary.closed_cases'),         value: props.stats.total_closed,         icon: 'tabler-briefcase-check',   color: 'success' },
  { label: t('disciplinary.hearings_this_week'),   value: props.stats.hearings_this_week,   icon: 'tabler-gavel',                     color: 'primary' },
  { label: t('disciplinary.overdue_notices'),      value: props.stats.overdue_notices,      icon: 'tabler-alert-circle',      color: 'error' },
  { label: 'Active Warnings',                      value: props.stats.active_warnings,      icon: 'tabler-alert-triangle',                     color: 'orange' },
  { label: 'Active Suspensions',                   value: props.stats.active_suspensions,   icon: 'tabler-user-off',       color: 'red' },
  { label: 'Pending Appeals',                      value: props.stats.pending_appeals,      icon: 'tabler-scale',             color: 'deep-purple' },
  { label: 'Pending Actions',                      value: props.stats.pending_actions,      icon: 'tabler-clipboard-check',           color: 'teal' },
])
</script>

<template>
  <div class="pa-4">
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-5 flex-wrap ga-2">
      <div>
        <p class="text-overline text-medium-emphasis mb-0">{{ t('disciplinary.module_label') }}</p>
        <h1 class="text-h5 font-weight-bold">{{ t('disciplinary.dashboard') }}</h1>
      </div>
      <div class="d-flex ga-2 flex-wrap">
        <VBtn variant="outlined" prepend-icon="tabler-settings" :href="route('disciplinary.setup.index')">{{ t('disciplinary.setup') }}</VBtn>
        <VBtn color="primary" prepend-icon="tabler-plus" :href="route('disciplinary.cases.index')">{{ t('disciplinary.new_case') }}</VBtn>
      </div>
    </div>

    <!-- KPI Strip -->
    <VRow dense class="mb-4">
      <VCol v-for="card in kpiCards" :key="card.label" cols="6" sm="3">
        <VCard variant="tonal" :color="card.color">
          <VCardText class="d-flex align-center ga-3 py-3">
            <VIcon :color="card.color" size="32">{{ card.icon }}</VIcon>
            <div>
              <div class="text-h5 font-weight-bold">{{ card.value }}</div>
              <div class="text-caption text-medium-emphasis">{{ card.label }}</div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Module Quick-Links -->
    <VCard class="mb-4">
      <VCardTitle class="text-body-1 font-weight-semibold pa-4 pb-2">
        <VIcon start>tabler-grid-dots</VIcon>Module Navigation
      </VCardTitle>
      <VCardText class="pt-0">
        <VRow dense>
          <VCol v-for="link in moduleLinks" :key="link.label" cols="6" sm="4" md="3">
            <VCard
              :color="link.color" variant="tonal"
              class="pa-3 text-center cursor-pointer" :href="route(link.route)"
            >
              <VIcon :color="link.color" size="28" class="mb-1">{{ link.icon }}</VIcon>
              <div class="text-caption font-weight-medium">{{ link.label }}</div>
              <div v-if="link.stat_key && statMap[link.stat_key] !== undefined" class="text-h6 font-weight-bold mt-1">
                {{ statMap[link.stat_key] }}
              </div>
            </VCard>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VRow>
      <!-- Status breakdown -->
      <VCol cols="12" md="4">
        <VCard>
          <VCardTitle class="text-body-1 font-weight-semibold pa-4 pb-2">
            <VIcon start>tabler-chart-donut</VIcon>{{ t('disciplinary.cases_by_status') }}
          </VCardTitle>
          <VList density="compact" class="px-2 pb-2">
            <VListItem v-for="s in statusCounts" :key="s.id">
              <template #prepend>
                <VChip :color="s.color" size="small" class="mr-2">{{ s.cases_count }}</VChip>
              </template>
              <VListItemTitle>{{ s.name }}</VListItemTitle>
              <template #append>
                <VChip v-if="s.is_closed" size="x-small" color="grey">{{ t('disciplinary.closed') }}</VChip>
              </template>
            </VListItem>
            <VListItem v-if="!statusCounts.length" class="text-medium-emphasis text-body-2">
              {{ t('disciplinary.no_statuses') }}
            </VListItem>
          </VList>
        </VCard>

        <!-- Categories -->
        <VCard class="mt-4">
          <VCardTitle class="text-body-1 font-weight-semibold pa-4 pb-2">
            <VIcon start>tabler-tags</VIcon>{{ t('disciplinary.cases_by_category') }}
          </VCardTitle>
          <VList density="compact" class="px-2 pb-2">
            <VListItem v-for="c in categoryCounts" :key="c.name">
              <template #prepend>
                <VChip :color="severityColor(c.severity_level)" size="small" class="mr-2">{{ c.total }}</VChip>
              </template>
              <VListItemTitle>{{ c.name }}</VListItemTitle>
              <template #append>
                <VChip :color="severityColor(c.severity_level)" size="x-small">{{ cap(c.severity_level) }}</VChip>
              </template>
            </VListItem>
            <VListItem v-if="!categoryCounts.length" class="text-medium-emphasis text-body-2">
              {{ t('disciplinary.no_data') }}
            </VListItem>
          </VList>
        </VCard>
      </VCol>

      <!-- Open Cases -->
      <VCol cols="12" md="4">
        <VCard>
          <VCardTitle class="text-body-1 font-weight-semibold pa-4 pb-2 d-flex justify-space-between align-center">
            <span><VIcon start>tabler-briefcase</VIcon>{{ t('disciplinary.open_cases') }}</span>
            <VBtn size="small" variant="text" :href="route('disciplinary.cases.index')">{{ t('disciplinary.view_all') }}</VBtn>
          </VCardTitle>
          <VList density="compact" class="px-2 pb-2">
            <VListItem
              v-for="c in openCases" :key="c.id"
              :href="route('disciplinary.cases.show', { case: c.id })" class="mb-1"
            >
              <VListItemTitle class="text-body-2 font-weight-medium">
                {{ c.case_number }} — {{ c.employee_name }}
              </VListItemTitle>
              <VListItemSubtitle class="text-caption">{{ c.title }}</VListItemSubtitle>
              <template #append>
                <VChip :color="c.status_color ?? 'grey'" size="x-small">{{ c.status }}</VChip>
              </template>
            </VListItem>
            <VListItem v-if="!openCases.length" class="text-medium-emphasis text-body-2">
              {{ t('disciplinary.no_open_cases') }}
            </VListItem>
          </VList>
        </VCard>
      </VCol>

      <!-- Upcoming Hearings -->
      <VCol cols="12" md="4">
        <VCard>
          <VCardTitle class="text-body-1 font-weight-semibold pa-4 pb-2">
            <VIcon start>tabler-gavel</VIcon>{{ t('disciplinary.upcoming_hearings') }}
          </VCardTitle>
          <VList density="compact" class="px-2 pb-2">
            <VListItem
              v-for="h in upcomingHearings" :key="h.id"
              :href="route('disciplinary.cases.show', { case: h.id })" class="mb-1"
            >
              <VListItemTitle class="text-body-2 font-weight-medium">{{ h.case_number }} — {{ h.employee_name }}</VListItemTitle>
              <VListItemSubtitle class="text-caption d-flex align-center ga-1">
                <VIcon size="12">tabler-calendar-clock</VIcon>{{ h.hearing_date }}
              </VListItemSubtitle>
              <template #append>
                <VChip :color="h.status_color ?? 'grey'" size="x-small">{{ h.status }}</VChip>
              </template>
            </VListItem>
            <VListItem v-if="!upcomingHearings.length" class="text-medium-emphasis text-body-2">
              {{ t('disciplinary.no_upcoming_hearings') }}
            </VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
