<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router, Link } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import { pageLinks } from '@/Modules/Recruitment/pagination'

const { t } = useI18n()
const route = ziggyRoute

type VacancyRow = { id: number; uuid: string; vacancy_number: string; title: string; department: string | null; visibility: { value: string; label: string; color: string }; status: { value: string; label: string; color: string }; job_type: string; headcount: number; created_at: string }
const props = defineProps<{ vacancies: { data: VacancyRow[]; meta: any; links: any }; filters: { status: string | null; visibility: string | null; search: string | null }; stats: { total: number; draft: number; internal: number; external: number; filled: number }; visibilityOptions: Array<{ value: string; label: string; color: string }>; statusOptions: Array<{ value: string; label: string; color: string }> }>()

const search = ref<string>(props.filters?.search ?? ''); const visibility = ref<string>(props.filters?.visibility ?? ''); const status = ref<string>(props.filters?.status ?? '')
let searchTimer: ReturnType<typeof setTimeout>
function applyFilters() { router.get(route('recruitment.vacancies.index'), { search: search.value || undefined, visibility: visibility.value || undefined, status: status.value || undefined }, { preserveState: true, replace: true }) }
watch(search, () => { clearTimeout(searchTimer); searchTimer = setTimeout(applyFilters, 400) }); watch(visibility, applyFilters); watch(status, applyFilters)
function clearFilters() { search.value = ''; visibility.value = ''; status.value = ''; router.get(route('recruitment.vacancies.index'), {}, { replace: true }) }

const statCards = computed(() => [
  { label: t('recruitment_vacancies.stat_total'), value: props.stats.total, icon: 'tabler-briefcase', color: 'primary', bg: 'rgba(115,103,240,0.12)' },
  { label: t('recruitment_vacancies.stat_draft'), value: props.stats.draft, icon: 'tabler-file-pencil', color: 'default', bg: 'rgba(168,170,174,0.12)' },
  { label: t('recruitment_vacancies.stat_internal'), value: props.stats.internal, icon: 'tabler-users', color: 'info', bg: 'rgba(23,162,184,0.12)' },
  { label: t('recruitment_vacancies.stat_external'), value: props.stats.external, icon: 'tabler-world', color: 'success', bg: 'rgba(40,199,111,0.12)' },
  { label: t('recruitment_vacancies.stat_filled'), value: props.stats.filled, icon: 'tabler-circle-check', color: 'warning', bg: 'rgba(255,159,67,0.12)' },
])

const visibilityItems = computed(() => [{ title: t('recruitment_vacancies.filter_all_visibility'), value: '' }, ...props.visibilityOptions.map(o => ({ title: o.label, value: o.value }))])
const statusItems = computed(() => [{ title: t('recruitment_vacancies.filter_all_status'), value: '' }, ...props.statusOptions.map(o => ({ title: o.label, value: o.value }))])

const headers = computed(() => [
  { title: t('recruitment_vacancies.table_vac_number'), key: 'vacancy_number', sortable: false, width: '140px' },
  { title: t('recruitment_vacancies.table_title'), key: 'title', sortable: false },
  { title: t('recruitment_vacancies.table_department'), key: 'department', sortable: false, width: '160px' },
  { title: t('recruitment_vacancies.table_visibility'), key: 'visibility', sortable: false, width: '160px' },
  { title: t('recruitment_vacancies.table_status'), key: 'status', sortable: false, width: '140px' },
  { title: t('recruitment_vacancies.table_type'), key: 'job_type', sortable: false, width: '130px' },
  { title: t('recruitment_vacancies.table_headcount'), key: 'headcount', sortable: false, width: '110px' },
  { title: t('recruitment_vacancies.table_date'), key: 'created_at', sortable: false, width: '130px' },
  { title: '', key: 'actions', sortable: false, width: '80px' },
])

const rows = computed(() => props.vacancies?.data ?? []); const meta = computed(() => props.vacancies?.meta ?? {}); const links = computed(() => pageLinks(props.vacancies))
function formatDate(val: string | null) { if (!val) return '—'; return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' }) }
function goToPage(url: string | null) { if (url) router.visit(url, { preserveState: true }) }
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-start justify-space-between mb-6 flex-wrap gap-3">
      <div><h4 class="font-weight-bold mb-1" style="font-size: 1.5rem; font-weight: 700; color: #0f172a;">{{ t('recruitment_vacancies.title_index') }}</h4><p class="text-medium-emphasis mb-0">{{ t('recruitment_vacancies.subtitle_index') }}</p></div>
      <Link :href="route('recruitment.vacancies.create')"><VBtn color="primary" prepend-icon="tabler-plus" elevation="0">{{ t('recruitment_vacancies.new_vacancy') }}</VBtn></Link>
    </div>
    <VRow class="mb-6"><VCol v-for="card in statCards" :key="card.label" cols="12" sm="6" lg="auto" style="flex: 1;"><VCard elevation="0" border style="border-radius: 12px;"><VCardText class="d-flex align-center gap-4 pa-5"><div class="d-flex align-center justify-center rounded-lg flex-shrink-0" :style="{ background: card.bg, width: '52px', height: '52px' }"><VIcon :icon="card.icon" :color="card.color" size="26" /></div><div><p class="text-body-2 text-medium-emphasis mb-1">{{ card.label }}</p><p class="text-h4 font-weight-bold mb-0" :style="card.color !== 'default' ? { color: `var(--v-theme-${card.color})` } : {}">{{ card.value }}</p></div></VCardText></VCard></VCol></VRow>
    <VCard elevation="0" border class="mb-5" style="border-radius: 12px;"><VCardText class="pa-4"><VRow dense align="center"><VCol cols="12" sm="6" md="4"><VTextField v-model="search" :label="t('recruitment_vacancies.filter_search')" prepend-inner-icon="tabler-search" variant="outlined" density="comfortable" clearable hide-details :placeholder="t('recruitment_vacancies.filter_search_placeholder')" @click:clear="() => { search = ''; applyFilters() }" /></VCol><VCol cols="12" sm="6" md="3"><VSelect v-model="visibility" :items="visibilityItems" :label="t('recruitment_vacancies.filter_visibility')" variant="outlined" density="comfortable" hide-details clearable /></VCol><VCol cols="12" sm="6" md="3"><VSelect v-model="status" :items="statusItems" :label="t('recruitment_vacancies.filter_status')" variant="outlined" density="comfortable" hide-details clearable /></VCol><VCol cols="12" md="auto"><VBtn variant="text" color="default" :disabled="!search && !visibility && !status" @click="clearFilters">{{ t('recruitment_vacancies.clear') }}</VBtn></VCol></VRow></VCardText></VCard>
    <VCard elevation="0" border style="border-radius: 12px;">
      <VDataTable :headers="headers" :items="rows" item-value="id" hide-default-footer>
        <template #item.vacancy_number="{ item }"><span class="text-body-2 font-weight-medium text-primary">{{ item.vacancy_number }}</span></template>
        <template #item.title="{ item }"><span class="text-body-2 font-weight-medium">{{ item.title }}</span></template>
        <template #item.department="{ item }"><span class="text-body-2 text-medium-emphasis">{{ item.department ?? '—' }}</span></template>
        <template #item.visibility="{ item }"><VChip :color="item.visibility.color" size="small" variant="tonal">{{ item.visibility.label }}</VChip></template>
        <template #item.status="{ item }"><VChip :color="item.status.color" :text="item.status.label" size="small" variant="tonal" /></template>
        <template #item.job_type="{ item }"><span class="text-body-2 text-capitalize">{{ item.job_type }}</span></template>
        <template #item.headcount="{ item }"><div class="d-flex align-center gap-1"><VIcon icon="tabler-users" size="14" class="text-medium-emphasis" /><span class="text-body-2">{{ item.headcount }}</span></div></template>
        <template #item.created_at="{ item }"><span class="text-body-2 text-medium-emphasis">{{ formatDate(item.created_at) }}</span></template>
        <template #item.actions="{ item }"><VBtn icon="tabler-eye" variant="text" size="small" color="default" @click="router.visit(route('recruitment.vacancies.show', item.uuid))" /></template>
        <template #no-data><div class="text-center py-12"><p class="text-body-2 text-medium-emphasis mb-0">{{ t('recruitment_vacancies.no_vacancies') }}</p></div></template>
      </VDataTable>
      <VDivider />
      <div class="d-flex align-center justify-space-between pa-4 flex-wrap gap-2"><span class="text-body-2 text-medium-emphasis">{{ t('recruitment_vacancies.total_vacancies', { count: meta.total ?? 0 }) }}</span><div class="d-flex align-center gap-1"><VBtn v-for="link in links" :key="link.label" :disabled="!link.url" size="small" :variant="link.active ? 'tonal' : 'text'" :color="link.active ? 'primary' : 'default'" class="px-2" v-html="link.label" @click="goToPage(link.url)" /></div></div>
    </VCard>
  </div>
</template>
