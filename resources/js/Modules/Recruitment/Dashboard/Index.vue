<script setup lang="ts">
import { computed } from 'vue'
import { router, Link } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type FunnelItem = { status: string; label: string; count: number; color: string }
type SourceItem = { source: string; count: number }
type RecentHire = { candidate: { full_name: string }; job: { title: string }; hired_at: string }

const props = defineProps<{
  stats: { open_applications: number; total_candidates: number; open_jobs: number; interviews_today: number; pending_offers: number }
  funnel: FunnelItem[]
  sourceBreakdown: SourceItem[]
  recentHires: RecentHire[]
}>()

const statCards = computed(() => [
  { label: t('recruitment_dashboard.open_applications'), value: props.stats.open_applications, icon: 'tabler-user-share', color: 'primary', bg: 'rgba(115,103,240,0.12)' },
  { label: t('recruitment_dashboard.total_candidates'), value: props.stats.total_candidates, icon: 'tabler-users', color: 'info', bg: 'rgba(0,207,232,0.12)' },
  { label: t('recruitment_dashboard.open_jobs'), value: props.stats.open_jobs, icon: 'tabler-briefcase', color: 'warning', bg: 'rgba(255,159,67,0.12)' },
  { label: t('recruitment_dashboard.interviews_today'), value: props.stats.interviews_today, icon: 'tabler-calendar-user', color: 'success', bg: 'rgba(40,199,111,0.12)' },
  { label: t('recruitment_dashboard.pending_offers'), value: props.stats.pending_offers, icon: 'tabler-file-signature', color: 'secondary', bg: 'rgba(134,110,246,0.12)' },
])

const activeFunnelItems = computed(() => props.funnel.filter(f => f.count > 0))
const funnelMax = computed(() => Math.max(...props.funnel.map(f => f.count), 1))
function funnelPct(count: number): number { return Math.round((count / funnelMax.value) * 100) }

const hireHeaders = computed(() => [
  { title: t('recruitment_dashboard.table_candidate'), key: 'candidate', sortable: false },
  { title: t('recruitment_dashboard.table_job'), key: 'job', sortable: false },
  { title: t('recruitment_dashboard.table_date'), key: 'hired_at', sortable: false, width: '130px' },
])

function formatDate(val: string | null) { if (!val) return '—'; return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' }) }
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-start justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <h4 class="font-weight-bold mb-1" style="font-size: 1.5rem; font-weight: 700; color: #0f172a;">{{ t('recruitment_dashboard.title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('recruitment_dashboard.subtitle') }}</p>
      </div>
      <Link :href="route('recruitment.requisitions.create')">
        <VBtn color="primary" prepend-icon="tabler-plus" elevation="0">{{ t('recruitment_dashboard.create_requisition') }}</VBtn>
      </Link>
    </div>

    <VRow class="mb-6">
      <VCol v-for="card in statCards" :key="card.label" cols="12" sm="6" md class="flex-grow-1">
        <VCard elevation="0" border>
          <VCardText class="d-flex align-center gap-4 pa-5">
            <div class="d-flex align-center justify-center rounded-lg flex-shrink-0" :style="{ background: card.bg, width: '52px', height: '52px' }"><VIcon :icon="card.icon" :color="card.color" size="26" /></div>
            <div class="flex-grow-1"><p class="text-body-2 text-medium-emphasis mb-1">{{ card.label }}</p><p class="text-h4 font-weight-bold mb-0" :style="{ color: `rgb(var(--v-theme-${card.color}))` }">{{ card.value }}</p></div>
            <VIcon :icon="card.icon" :color="card.color" size="36" style="opacity: 0.08;" />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow class="mb-6">
      <VCol cols="12" md="8">
        <VCard elevation="0" border>
          <VCardTitle class="pa-5 pb-2 d-flex align-center justify-space-between">
            <span class="text-body-1 font-weight-semibold">{{ t('recruitment_dashboard.recent_hires') }}</span>
            <VIcon icon="tabler-user-check" color="success" size="20" />
          </VCardTitle>
          <VDataTable v-if="recentHires.length > 0" :headers="hireHeaders" :items="recentHires" hide-default-footer :items-per-page="10">
            <template #item.candidate="{ item }"><span class="text-body-2 font-weight-medium">{{ item.candidate.full_name }}</span></template>
            <template #item.job="{ item }"><span class="text-body-2 text-medium-emphasis">{{ item.job.title }}</span></template>
            <template #item.hired_at="{ item }"><span class="text-body-2 text-medium-emphasis">{{ formatDate(item.hired_at) }}</span></template>
          </VDataTable>
          <div v-else class="text-center py-10 px-4"><VIcon icon="tabler-user-check" size="52" color="medium-emphasis" class="mb-3 d-block mx-auto" /><p class="text-body-2 text-medium-emphasis mb-0">{{ t('recruitment_dashboard.no_hires') }}</p></div>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard elevation="0" border height="100%">
          <VCardTitle class="pa-5 pb-2"><span class="text-body-1 font-weight-semibold">{{ t('recruitment_dashboard.application_sources') }}</span></VCardTitle>
          <VList v-if="sourceBreakdown.length > 0" lines="one" class="py-0">
            <VListItem v-for="item in sourceBreakdown" :key="item.source" :title="item.source || t('recruitment_dashboard.unknown_source')" prepend-icon="tabler-git-branch" :prepend-icon-color="'primary'">
              <template #append><VChip size="x-small" color="primary" variant="tonal" label>{{ item.count }}</VChip></template>
            </VListItem>
          </VList>
          <div v-else class="text-center py-8 px-4"><p class="text-body-2 text-medium-emphasis mb-0">{{ t('recruitment_dashboard.no_source_data') }}</p></div>
        </VCard>
      </VCol>
    </VRow>

    <VCard elevation="0" border>
      <VCardTitle class="pa-5 pb-3"><span class="text-body-1 font-weight-semibold">{{ t('recruitment_dashboard.hiring_funnel') }}</span></VCardTitle>
      <VCardText class="pt-0">
        <div v-if="activeFunnelItems.length > 0">
          <div v-for="item in activeFunnelItems" :key="item.status" class="d-flex align-center gap-4 mb-3">
            <span class="text-body-2 text-medium-emphasis flex-shrink-0" style="width: 130px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ item.label }}</span>
            <VProgressLinear :model-value="funnelPct(item.count)" :color="item.color" height="8" rounded class="flex-grow-1" bg-color="rgba(0,0,0,0.06)" />
            <VChip size="x-small" :color="item.color" variant="tonal" label>{{ item.count }}</VChip>
          </div>
        </div>
        <div v-else class="text-center py-6"><p class="text-body-2 text-medium-emphasis mb-0">{{ t('recruitment_dashboard.no_pipeline_data') }}</p></div>
      </VCardText>
    </VCard>
  </div>
</template>
