<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import JobStatusChip from '../partials/JobStatusChip.vue'
import AppStatusChip from '../partials/AppStatusChip.vue'

const { t } = useI18n()
const route = ziggyRoute

type PipelineStage = { id: number; name: string; type: string; order_index: number }
type Application = { id: number; status: { value: string; label: string }; candidate: { id: number; full_name: string; email: string }; current_stage: { name: string } | null; applied_at: string; created_at: string }
type Requisition = { id: number; status: { value: string; label: string; color: string }; headcount: number; created_at: string }
type ShortlistSummary = { id: number; status: string; status_label: string; items_count: number; generated_by: { id: number; name: string } | null; approved_at: string | null; created_at: string }

const props = defineProps<{ job: { id: number; title: string; description: string | null; department: string | null; location: string | null; contract_type: string | null; headcount: number; status: string; posted_at: string | null; closes_at: string | null; created_at: string; pipeline_stages: PipelineStage[] }; applications: Application[]; requisitions: Requisition[]; shortlists: ShortlistSummary[]; stats: { total_applications: number; shortlisted: number; interviewed: number; offered: number } }>()

const generatingShortlist = ref(false); const tab = ref('overview')
function generateShortlist() { generatingShortlist.value = true; router.post(route('recruitment.jobs.shortlists.generate', props.job.id), {}, { onFinish: () => { generatingShortlist.value = false } }) }
function shortlistStatusColor(s: string) { const map: Record<string, string> = { draft: 'secondary', pending_approval: 'warning', approved: 'success', rejected: 'error' }; return map[s] ?? 'default' }
const stageTypeColor: Record<string, string> = { interview: 'warning', assessment: 'secondary', offer: 'success', screening: 'info', review: 'primary' }
function formatDate(val: string | null) { if (!val) return '—'; return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' }) }

const appHeaders = computed(() => [
  { title: t('recruitment_jobs.table_candidate'), key: 'candidate', sortable: false },
  { title: t('recruitment_jobs.table_stage'), key: 'current_stage', sortable: false, width: '160px' },
  { title: t('recruitment_jobs.table_status'), key: 'status', sortable: false, width: '140px' },
  { title: t('recruitment_jobs.table_applied'), key: 'applied_at', sortable: false, width: '130px' },
])
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center gap-2 mb-4">
      <VBtn icon="tabler-arrow-left" variant="text" size="small" @click="router.visit(route('recruitment.jobs.index'))" />
      <span class="text-medium-emphasis text-body-2 cursor-pointer" @click="router.visit(route('recruitment.jobs.index'))">{{ t('recruitment_jobs.breadcrumb_recruitment') }}</span>
      <VIcon icon="tabler-chevron-right" size="16" class="text-medium-emphasis" />
      <span class="text-medium-emphasis text-body-2 cursor-pointer" @click="router.visit(route('recruitment.jobs.index'))">{{ t('recruitment_jobs.breadcrumb_jobs') }}</span>
      <VIcon icon="tabler-chevron-right" size="16" class="text-medium-emphasis" />
      <span class="text-body-2 font-weight-medium">{{ job.title }}</span>
    </div>

    <div class="d-flex align-start justify-space-between mb-5">
      <div><div class="d-flex align-center gap-2 mb-1"><h4 class="text-h4 font-weight-bold">{{ job.title }}</h4><JobStatusChip :status="job.status" /></div><p class="text-medium-emphasis mb-0">{{ [job.department, job.location].filter(Boolean).join(' · ') || t('recruitment_jobs.detail_no_location') }}</p></div>
      <div class="d-flex gap-2"><VBtn variant="tonal" color="primary" prepend-icon="tabler-layout-columns" @click="router.visit(route('recruitment.jobs.pipeline', job.id))">{{ t('recruitment_jobs.detail_pipeline_view') }}</VBtn><VBtn color="primary" prepend-icon="tabler-users" @click="router.visit(route('recruitment.applications.index', job.id))">{{ t('recruitment_jobs.detail_applications') }}</VBtn></div>
    </div>

    <VRow class="mb-5" dense>
      <VCol cols="6" md="3"><VCard elevation="0" border><VCardText class="text-center pa-4"><p class="text-h4 font-weight-bold text-primary mb-1">{{ stats.total_applications }}</p><p class="text-body-2 text-medium-emphasis mb-0">{{ t('recruitment_jobs.stat_applied') }}</p></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard elevation="0" border><VCardText class="text-center pa-4"><p class="text-h4 font-weight-bold text-primary mb-1">{{ stats.shortlisted }}</p><p class="text-body-2 text-medium-emphasis mb-0">{{ t('recruitment_jobs.stat_shortlisted') }}</p></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard elevation="0" border><VCardText class="text-center pa-4"><p class="text-h4 font-weight-bold text-primary mb-1">{{ stats.interviewed }}</p><p class="text-body-2 text-medium-emphasis mb-0">{{ t('recruitment_jobs.stat_interviewed') }}</p></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard elevation="0" border><VCardText class="text-center pa-4"><p class="text-h4 font-weight-bold text-primary mb-1">{{ stats.offered }}</p><p class="text-body-2 text-medium-emphasis mb-0">{{ t('recruitment_jobs.stat_offered') }}</p></VCardText></VCard></VCol>
    </VRow>

    <VTabs v-model="tab" class="mb-4">
      <VTab value="overview">{{ t('recruitment_jobs.tab_overview') }}</VTab>
      <VTab value="applications">{{ t('recruitment_jobs.tab_applications', { count: stats.total_applications }) }}</VTab>
      <VTab value="pipeline">{{ t('recruitment_jobs.tab_pipeline') }}</VTab>
      <VTab value="requisitions">{{ t('recruitment_jobs.tab_requisitions') }}</VTab>
      <VTab value="shortlists">{{ t('recruitment_jobs.tab_shortlists') }}<VChip v-if="shortlists.length" size="x-small" color="primary" variant="tonal" label class="ms-2">{{ shortlists.length }}</VChip></VTab>
    </VTabs>

    <VWindow v-model="tab">
      <VWindowItem value="overview">
        <VRow><VCol cols="12" lg="8"><VCard elevation="0" border><VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">{{ t('recruitment_jobs.detail_description') }}</VCardTitle><VCardText class="pa-5 pt-0"><div class="text-body-2" style="white-space: pre-wrap; line-height: 1.7;">{{ job.description ?? t('recruitment_jobs.detail_no_description') }}</div></VCardText></VCard></VCol>
        <VCol cols="12" lg="4"><VCard elevation="0" border><VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">{{ t('recruitment_jobs.detail_details') }}</VCardTitle><VCardText class="pa-5 pt-0"><div class="d-flex flex-column gap-3">
          <div class="d-flex align-center justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('recruitment_jobs.detail_headcount') }}</span><span class="text-body-2 font-weight-medium">{{ job.headcount }}</span></div><VDivider />
          <div class="d-flex align-center justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('recruitment_jobs.detail_contract_type') }}</span><span class="text-body-2 text-capitalize">{{ job.contract_type?.replace('_', ' ') ?? '—' }}</span></div><VDivider />
          <div class="d-flex align-center justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('recruitment_jobs.detail_location') }}</span><span class="text-body-2">{{ job.location ?? '—' }}</span></div><VDivider />
          <div class="d-flex align-center justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('recruitment_jobs.detail_posted') }}</span><span class="text-body-2">{{ formatDate(job.posted_at ?? job.created_at) }}</span></div>
          <div v-if="job.closes_at" class="d-flex align-center justify-space-between"><VDivider /><span class="text-caption text-medium-emphasis">{{ t('recruitment_jobs.detail_closes') }}</span><span class="text-body-2">{{ formatDate(job.closes_at) }}</span></div>
        </div></VCardText></VCard></VCol></VRow>
      </VWindowItem>

      <VWindowItem value="applications">
        <VCard elevation="0" border>
          <VDataTable :headers="appHeaders" :items="applications" item-value="id" hide-default-footer hover @click:row="(_evt: unknown, row: any) => router.visit(route('recruitment.jobs.applications.show', [job.id, row.item.id]))">
            <template #item.candidate="{ item }"><div class="d-flex align-center gap-2"><VAvatar size="32" color="primary" variant="tonal"><span class="text-caption">{{ (item.candidate?.full_name ?? '?')[0].toUpperCase() }}</span></VAvatar><div><p class="text-body-2 font-weight-medium mb-0">{{ item.candidate?.full_name ?? '—' }}</p><p class="text-caption text-medium-emphasis mb-0">{{ item.candidate?.email ?? '' }}</p></div></div></template>
            <template #item.current_stage="{ item }"><span class="text-body-2">{{ item.current_stage?.name ?? '—' }}</span></template>
            <template #item.status="{ item }"><AppStatusChip :status="item.status" /></template>
            <template #item.applied_at="{ item }"><span class="text-body-2 text-medium-emphasis">{{ formatDate(item.applied_at) }}</span></template>
            <template #no-data><div class="text-center py-10"><VIcon icon="tabler-user-search" size="56" color="medium-emphasis" class="mb-3" /><p class="text-body-2 text-medium-emphasis mb-0">{{ t('recruitment_jobs.no_applications') }}</p></div></template>
          </VDataTable>
        </VCard>
      </VWindowItem>

      <VWindowItem value="pipeline">
        <VCard elevation="0" border><VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">{{ t('recruitment_jobs.tab_pipeline') }}</VCardTitle><VCardText class="pa-5 pt-0">
          <div v-if="job.pipeline_stages.length" class="d-flex flex-column gap-2"><div v-for="stage in job.pipeline_stages" :key="stage.id" class="d-flex align-center gap-3 pa-3 rounded-lg" style="border: 1px solid rgba(0,0,0,0.08);"><VChip size="small" variant="tonal" color="primary" label>{{ stage.order_index }}</VChip><span class="text-body-2 font-weight-medium flex-grow-1">{{ stage.name }}</span><VChip size="small" label :color="stageTypeColor[stage.type] ?? 'default'" variant="tonal" class="text-capitalize">{{ stage.type }}</VChip></div></div>
          <div v-else class="text-center py-8 text-medium-emphasis"><VIcon icon="tabler-layout-columns" size="48" class="mb-2 d-block mx-auto" /><p class="text-body-2 mb-0">{{ t('recruitment_jobs.no_pipeline') }}</p></div>
        </VCardText></VCard>
      </VWindowItem>

      <VWindowItem value="requisitions">
        <VCard elevation="0" border>
          <VDataTable :headers="[{ title: t('recruitment_jobs.table_headcount'), key: 'headcount', sortable: false, width: '130px' }, { title: t('recruitment_jobs.table_status'), key: 'status', sortable: false }, { title: t('recruitment_jobs.table_requested'), key: 'created_at', sortable: false, width: '140px' }]" :items="requisitions" item-value="id" hide-default-footer>
            <template #item.headcount="{ item }"><span class="text-body-2 font-weight-medium">{{ item.headcount }}</span></template>
            <template #item.status="{ item }"><AppStatusChip :status="item.status" /></template>
            <template #item.created_at="{ item }"><span class="text-body-2 text-medium-emphasis">{{ formatDate(item.created_at) }}</span></template>
            <template #no-data><div class="text-center py-10"><p class="text-body-2 text-medium-emphasis mb-0">{{ t('recruitment_jobs.no_requisitions') }}</p></div></template>
          </VDataTable>
        </VCard>
      </VWindowItem>

      <VWindowItem value="shortlists">
        <div class="d-flex justify-end mb-4"><VBtn color="primary" prepend-icon="tabler-robot" :loading="generatingShortlist" @click="generateShortlist">{{ t('recruitment_jobs.generate_shortlist') }}</VBtn></div>
        <div v-if="shortlists.length" class="d-flex flex-column gap-3">
          <VCard v-for="sl in shortlists" :key="sl.id" elevation="0" border class="cursor-pointer" @click="router.visit(route('recruitment.jobs.shortlists.show', [job.id, sl.id]))">
            <VCardText class="pa-4"><div class="d-flex align-center justify-space-between"><div class="d-flex align-center gap-3"><VChip :color="shortlistStatusColor(sl.status)" variant="tonal" label size="small" class="font-weight-medium text-capitalize">{{ sl.status_label }}</VChip><div><p class="text-body-2 font-weight-medium mb-0">{{ t('recruitment_jobs.candidates_count', { count: sl.items_count }) }}</p><p class="text-caption text-medium-emphasis mb-0">{{ t('recruitment_jobs.generated_by', { by: sl.generated_by ? `by ${sl.generated_by.name}` : '', date: formatDate(sl.created_at) }) }}</p></div></div><div class="d-flex align-center gap-2"><span v-if="sl.approved_at" class="text-caption text-success">{{ t('recruitment_jobs.approved_at', { date: formatDate(sl.approved_at) }) }}</span><VIcon icon="tabler-chevron-right" size="18" class="text-medium-emphasis" /></div></div></VCardText>
          </VCard>
        </div>
        <VCard v-else elevation="0" border><VCardText class="text-center py-12"><VIcon icon="tabler-users-group" size="56" color="medium-emphasis" class="mb-3 d-block mx-auto" /><p class="text-body-2 text-medium-emphasis mb-4">{{ t('recruitment_jobs.no_shortlists') }}</p></VCardText></VCard>
      </VWindowItem>
    </VWindow>
  </div>
</template>
