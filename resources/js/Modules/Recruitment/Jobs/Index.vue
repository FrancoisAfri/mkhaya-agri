<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import AppTextField from '@/@core/components/app-form-elements/AppTextField.vue'
import JobStatusChip from '../partials/JobStatusChip.vue'
import { pageLinks } from '@/Modules/Recruitment/pagination'

const { t } = useI18n()
const route = ziggyRoute

type Job = { id: number; title: string; department: string | null; location: string | null; contract_type: string | null; status: string; applications_count: number; created_at: string }
const props = defineProps<{ jobs: { data: Job[]; links: any[]; meta: any }; filters: Record<string, any>; stats: { total: number; active: number; filled: number } }>()

const search = ref<string>(props.filters?.search ?? '')
const status = ref<string>(props.filters?.status ?? '')
let searchTimer: ReturnType<typeof setTimeout>
function applyFilters() { router.get(route('recruitment.jobs.index'), { search: search.value || undefined, status: status.value || undefined }, { preserveState: true, replace: true }) }
watch(search, () => { clearTimeout(searchTimer); searchTimer = setTimeout(applyFilters, 400) })
watch(status, applyFilters)

const draftCount = computed(() => Math.max(0, props.stats.total - props.stats.active - props.stats.filled))
const statCards = computed(() => [
  { label: t('recruitment_jobs.stat_total'), value: props.stats.total, icon: 'tabler-briefcase', color: 'primary', bg: 'rgba(115,103,240,0.12)' },
  { label: t('recruitment_jobs.stat_active'), value: props.stats.active, icon: 'tabler-briefcase-check', color: 'success', bg: 'rgba(40,199,111,0.12)' },
  { label: t('recruitment_jobs.stat_filled'), value: props.stats.filled, icon: 'tabler-user-check', color: 'info', bg: 'rgba(0,207,232,0.12)' },
  { label: t('recruitment_jobs.stat_draft'), value: draftCount.value, icon: 'tabler-file-pencil', color: 'warning', bg: 'rgba(255,159,67,0.12)' },
])

const headers = computed(() => [
  { title: t('recruitment_jobs.table_position'), key: 'title', sortable: false },
  { title: t('recruitment_jobs.table_department'), key: 'department', sortable: false, width: '160px' },
  { title: t('recruitment_jobs.table_location'), key: 'location', sortable: false, width: '140px' },
  { title: t('recruitment_jobs.table_status'), key: 'status', sortable: false, width: '120px' },
  { title: t('recruitment_jobs.table_applications'), key: 'applications_count', sortable: false, width: '130px' },
  { title: t('recruitment_jobs.table_posted'), key: 'created_at', sortable: false, width: '130px' },
  { title: '', key: 'actions', sortable: false, width: '64px' },
])

const rows = computed(() => props.jobs?.data ?? [])
const links = computed(() => pageLinks(props.jobs))
const meta = computed(() => props.jobs?.meta ?? {})
function formatDate(val: string | null) { if (!val) return '—'; return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' }) }
function goToPage(url: string | null) { if (url) router.visit(url, { preserveState: true }) }

const statusItems = computed(() => [
  { title: t('recruitment_jobs.filter_all_statuses'), value: '' },
  { title: t('recruitment_jobs.filter_active'), value: 'active' },
  { title: t('recruitment_jobs.filter_draft'), value: 'draft' },
  { title: t('recruitment_jobs.filter_closed'), value: 'closed' },
  { title: t('recruitment_jobs.filter_filled'), value: 'filled' },
])
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6">
      <div><h4 class="text-h4 font-weight-bold mb-1">{{ t('recruitment_jobs.title_index') }}</h4><p class="text-medium-emphasis mb-0">{{ t('recruitment_jobs.subtitle_index') }}</p></div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="router.visit(route('recruitment.jobs.create'))">{{ t('recruitment_jobs.create_position') }}</VBtn>
    </div>
    <VRow class="mb-6"><VCol v-for="card in statCards" :key="card.label" cols="12" sm="6" lg="3"><VCard elevation="0" border><VCardText class="d-flex align-center gap-4 pa-5"><div class="d-flex align-center justify-center rounded-lg flex-shrink-0" :style="{ background: card.bg, width: '52px', height: '52px' }"><VIcon :icon="card.icon" :color="card.color" size="28" /></div><div><p class="text-body-2 text-medium-emphasis mb-1">{{ card.label }}</p><p class="text-h4 font-weight-bold mb-0" :style="{ color: `var(--v-theme-${card.color})` }">{{ card.value }}</p></div></VCardText></VCard></VCol></VRow>
    <div class="d-flex flex-wrap gap-2 mb-5">
      <VChip size="default" variant="tonal" :color="status === '' ? 'primary' : 'default'" class="cursor-pointer font-weight-medium" @click="status = ''">{{ t('recruitment_jobs.filter_all') }}</VChip>
      <VChip size="default" variant="tonal" :color="status === 'active' ? 'success' : 'default'" class="cursor-pointer font-weight-medium" @click="status = 'active'"><VIcon icon="tabler-circle-filled" start size="10" />{{ t('recruitment_jobs.filter_active') }}</VChip>
      <VChip size="default" variant="tonal" :color="status === 'draft' ? 'warning' : 'default'" class="cursor-pointer font-weight-medium" @click="status = 'draft'">{{ t('recruitment_jobs.filter_draft') }}</VChip>
      <VChip size="default" variant="tonal" :color="status === 'filled' ? 'info' : 'default'" class="cursor-pointer font-weight-medium" @click="status = 'filled'">{{ t('recruitment_jobs.filter_filled') }}</VChip>
      <VChip size="default" variant="tonal" :color="status === 'closed' ? 'error' : 'default'" class="cursor-pointer font-weight-medium" @click="status = 'closed'">{{ t('recruitment_jobs.filter_closed') }}</VChip>
    </div>
    <VCard elevation="0" border class="mb-5"><VCardText class="pa-4"><VRow dense><VCol cols="12" md="6"><AppTextField v-model="search" :label="t('recruitment_jobs.search_placeholder')" prepend-inner-icon="tabler-search" clearable hide-details /></VCol><VCol cols="12" md="3"><VSelect v-model="status" :items="statusItems" item-title="title" item-value="value" :label="t('recruitment_jobs.filter_status')" hide-details density="comfortable" variant="outlined" /></VCol></VRow></VCardText></VCard>
    <VCard elevation="0" border>
      <VDataTable :headers="headers" :items="rows" item-value="id" hide-default-footer :items-per-page="20" hover @click:row="(_evt: unknown, row: any) => router.visit(route('recruitment.jobs.show', row.item.id))">
        <template #item.title="{ item }"><div><p class="text-body-2 font-weight-semibold text-primary mb-0">{{ item.title }}</p><p v-if="item.contract_type" class="text-caption text-medium-emphasis mb-0 text-capitalize">{{ item.contract_type.replace('_', ' ') }}</p></div></template>
        <template #item.department="{ item }"><span class="text-body-2">{{ item.department ?? '—' }}</span></template>
        <template #item.location="{ item }"><span class="text-body-2">{{ item.location ?? '—' }}</span></template>
        <template #item.status="{ item }"><JobStatusChip :status="item.status" /></template>
        <template #item.applications_count="{ item }"><div class="d-flex align-center gap-1"><VIcon icon="tabler-users" size="14" class="text-medium-emphasis" /><span class="text-body-2">{{ item.applications_count }}</span></div></template>
        <template #item.created_at="{ item }"><span class="text-body-2 text-medium-emphasis">{{ formatDate(item.created_at) }}</span></template>
        <template #item.actions="{ item }"><VBtn icon size="small" variant="text" color="default" @click.stop="router.visit(route('recruitment.jobs.pipeline', item.id))"><VIcon icon="tabler-layout-columns" size="18" /><VTooltip activator="parent" location="top">{{ t('recruitment_jobs.pipeline_view') }}</VTooltip></VBtn></template>
        <template #no-data><div class="text-center py-12"><VIcon icon="tabler-briefcase-2" size="64" color="medium-emphasis" class="mb-4" /><p class="text-body-1 font-weight-medium mb-1">{{ t('recruitment_jobs.no_positions') }}</p><p class="text-body-2 text-medium-emphasis mb-4">{{ t('recruitment_jobs.no_positions_sub') }}</p><VBtn color="primary" variant="tonal" @click="router.visit(route('recruitment.jobs.create'))">{{ t('recruitment_jobs.create_position') }}</VBtn></div></template>
      </VDataTable>
      <VDivider v-if="meta.last_page > 1" />
      <div v-if="meta.last_page > 1" class="d-flex align-center justify-space-between pa-4"><p class="text-body-2 text-medium-emphasis mb-0">{{ t('recruitment_jobs.showing', { from: meta.from ?? 0, to: meta.to ?? 0, total: meta.total }) }}</p><div class="d-flex gap-1"><VBtn v-for="link in links" :key="link.label" :disabled="!link.url" :variant="link.active ? 'flat' : 'text'" :color="link.active ? 'primary' : 'default'" size="small" @click="goToPage(link.url)" v-html="link.label" /></div></div>
    </VCard>
  </div>
</template>
