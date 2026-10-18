<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router, Link } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { pageLinks } from '@/Modules/Recruitment/pagination'

const { t } = useI18n()
const route = ziggyRoute

type RequisitionRow = { id: number; uuid: string; requisition_number: string; title: string; department: string | null; job_type: string; headcount: number; status: { value: string; label: string; color: string }; requested_by: string; created_at: string }
const props = defineProps<{ requisitions: { data: RequisitionRow[]; meta: any; links: any }; filters: { status: string | null; search: string | null }; stats: { total: number; draft: number; pending: number; approved: number } }>()

const search = ref<string>(props.filters?.search ?? '')
const status = ref<string>(props.filters?.status ?? '')
let searchTimer: ReturnType<typeof setTimeout>
function applyFilters() { router.get(route('recruitment.requisitions.index'), { search: search.value || undefined, status: status.value || undefined }, { preserveState: true, replace: true }) }
watch(search, () => { clearTimeout(searchTimer); searchTimer = setTimeout(applyFilters, 400) })
watch(status, applyFilters)
function clearFilters() { search.value = ''; status.value = ''; router.get(route('recruitment.requisitions.index'), {}, { replace: true }) }

const statusOptions = computed(() => [
  { title: t('recruitment_requisition_pages.filter_all'), value: '' },
  { title: t('recruitment_requisition_pages.filter_draft'), value: 'draft' },
  { title: t('recruitment_requisition_pages.filter_pending'), value: 'pending_approval' },
  { title: t('recruitment_requisition_pages.filter_approved'), value: 'approved' },
  { title: t('recruitment_requisition_pages.filter_rejected'), value: 'rejected' },
  { title: t('recruitment_requisition_pages.filter_cancelled'), value: 'cancelled' },
  { title: t('recruitment_requisition_pages.filter_converted'), value: 'converted' },
])

const statCards = computed(() => [
  { label: t('recruitment_requisition_pages.stat_total'), value: props.stats.total, icon: 'tabler-files', color: 'primary', bg: 'rgba(115,103,240,0.12)' },
  { label: t('recruitment_requisition_pages.stat_draft'), value: props.stats.draft, icon: 'tabler-file-pencil', color: 'default', bg: 'rgba(168,170,174,0.12)' },
  { label: t('recruitment_requisition_pages.stat_pending'), value: props.stats.pending, icon: 'tabler-clock-exclamation', color: 'warning', bg: 'rgba(255,159,67,0.12)' },
  { label: t('recruitment_requisition_pages.stat_approved'), value: props.stats.approved, icon: 'tabler-circle-check', color: 'success', bg: 'rgba(40,199,111,0.12)' },
])

const headers = computed(() => [
  { title: t('recruitment_requisition_pages.table_req_number'), key: 'requisition_number', sortable: false, width: '160px' },
  { title: t('recruitment_requisition_pages.table_title'), key: 'title', sortable: false },
  { title: t('recruitment_requisition_pages.table_department'), key: 'department', sortable: false, width: '160px' },
  { title: t('recruitment_requisition_pages.table_type'), key: 'job_type', sortable: false, width: '130px' },
  { title: t('recruitment_requisition_pages.table_headcount'), key: 'headcount', sortable: false, width: '110px' },
  { title: t('recruitment_requisition_pages.table_status'), key: 'status', sortable: false, width: '160px' },
  { title: t('recruitment_requisition_pages.table_requested_by'), key: 'requested_by', sortable: false, width: '160px' },
  { title: t('recruitment_requisition_pages.table_date'), key: 'created_at', sortable: false, width: '130px' },
  { title: '', key: 'actions', sortable: false, width: '80px' },
])

const rows = computed(() => props.requisitions?.data ?? [])
const meta = computed(() => props.requisitions?.meta ?? {})
const links = computed(() => pageLinks(props.requisitions))
function formatDate(val: string | null) { if (!val) return '—'; return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' }) }
function goToPage(url: string | null) { if (url) router.visit(url, { preserveState: true }) }
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-start justify-space-between mb-6 flex-wrap gap-3">
      <div><h4 class="font-weight-bold mb-1" style="font-size: 1.5rem; font-weight: 700; color: #0f172a;">{{ t('recruitment_requisition_pages.title_index') }}</h4><p class="text-medium-emphasis mb-0">{{ t('recruitment_requisition_pages.subtitle_index') }}</p></div>
      <Link :href="route('recruitment.requisitions.create')"><VBtn color="primary" prepend-icon="tabler-plus" elevation="0">{{ t('recruitment_requisition_pages.new_requisition') }}</VBtn></Link>
    </div>
    <VRow class="mb-6"><VCol v-for="card in statCards" :key="card.label" cols="12" sm="6" lg="3"><VCard elevation="0" border style="border-radius: 12px;"><VCardText class="d-flex align-center gap-4 pa-5"><div class="d-flex align-center justify-center rounded-lg flex-shrink-0" :style="{ background: card.bg, width: '52px', height: '52px' }"><VIcon :icon="card.icon" :color="card.color" size="26" /></div><div><p class="text-body-2 text-medium-emphasis mb-1">{{ card.label }}</p><p class="text-h4 font-weight-bold mb-0" :style="card.color !== 'default' ? { color: `var(--v-theme-${card.color})` } : {}">{{ card.value }}</p></div></VCardText></VCard></VCol></VRow>
    <VCard elevation="0" border class="mb-5" style="border-radius: 12px;"><VCardText class="pa-4"><VRow dense align="center"><VCol cols="12" sm="6" md="5"><VTextField v-model="search" :label="t('recruitment_requisition_pages.search_placeholder')" prepend-inner-icon="tabler-search" variant="outlined" density="comfortable" clearable hide-details @click:clear="() => { search = ''; applyFilters() }" /></VCol><VCol cols="12" sm="6" md="3"><VSelect v-model="status" :items="statusOptions" :label="t('recruitment_requisition_pages.filter_status')" variant="outlined" density="comfortable" hide-details clearable /></VCol><VCol cols="12" md="auto"><VBtn variant="text" color="default" :disabled="!search && !status" @click="clearFilters">{{ t('recruitment_requisition_pages.clear') }}</VBtn></VCol></VRow></VCardText></VCard>
    <VCard elevation="0" border style="border-radius: 12px;">
      <VDataTable :headers="headers" :items="rows" item-value="id" hide-default-footer :items-per-page="meta.per_page ?? 25" hover @click:row="(_evt: unknown, row: any) => router.visit(route('recruitment.requisitions.show', row.item.uuid))">
        <template #item.requisition_number="{ item }"><span class="text-body-2 font-weight-semibold text-primary">{{ item.requisition_number }}</span></template>
        <template #item.title="{ item }"><span class="text-body-2 font-weight-medium">{{ item.title }}</span></template>
        <template #item.department="{ item }"><span class="text-body-2 text-medium-emphasis">{{ item.department ?? '—' }}</span></template>
        <template #item.job_type="{ item }"><span class="text-body-2 text-capitalize">{{ item.job_type.replace('_', ' ') }}</span></template>
        <template #item.headcount="{ item }"><div class="d-flex align-center gap-1"><VIcon icon="tabler-users" size="14" class="text-medium-emphasis" /><span class="text-body-2 font-weight-medium">{{ item.headcount }}</span></div></template>
        <template #item.status="{ item }"><VChip :color="item.status.color" size="small" label variant="tonal" class="font-weight-medium">{{ item.status.label }}</VChip></template>
        <template #item.requested_by="{ item }"><span class="text-body-2">{{ item.requested_by ?? '—' }}</span></template>
        <template #item.created_at="{ item }"><span class="text-body-2 text-medium-emphasis">{{ formatDate(item.created_at) }}</span></template>
        <template #item.actions="{ item }"><VBtn size="x-small" color="primary" variant="tonal" @click.stop="router.visit(route('recruitment.requisitions.show', item.uuid))">{{ t('recruitment_requisition_pages.view') }}</VBtn></template>
        <template #no-data><div class="text-center py-12"><VIcon icon="tabler-file-text" size="64" color="medium-emphasis" class="mb-4 d-block mx-auto" /><p class="text-body-1 font-weight-medium mb-1">{{ t('recruitment_requisition_pages.no_requisitions') }}</p><p class="text-body-2 text-medium-emphasis mb-0">{{ t('recruitment_requisition_pages.no_requisitions_sub') }}</p></div></template>
      </VDataTable>
      <VDivider v-if="meta.last_page > 1" />
      <div v-if="meta.last_page > 1" class="d-flex align-center justify-space-between pa-4 flex-wrap gap-2"><p class="text-body-2 text-medium-emphasis mb-0">{{ t('recruitment_requisition_pages.page_of', { current: meta.current_page, last: meta.last_page }) }} · {{ meta.total }} {{ t('recruitment_requisition_pages.total_label') }}</p><div class="d-flex gap-1"><VBtn v-for="link in links" :key="link.label" :disabled="!link.url" :variant="link.active ? 'flat' : 'text'" :color="link.active ? 'primary' : 'default'" size="small" @click="goToPage(link.url)" v-html="link.label" /></div></div>
    </VCard>
  </div>
</template>
