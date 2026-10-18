<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type VacancyItem = { id: number; uuid: string; vacancy_number: string; title: string; department: string | null; job_type: { value: string; label: string }; headcount: number; job_description: string; salary_band_min: number | null; salary_band_max: number | null; expires_at: string | null; published_internally_at: string | null }
const props = defineProps<{ vacancies: { data: VacancyItem[]; meta: any }; filters: { search: string | null; department: string | null } }>()

const search = ref<string>(props.filters?.search ?? ''); const department = ref<string>(props.filters?.department ?? '')
let searchTimer: ReturnType<typeof setTimeout>
function applyFilters() { router.get(route('recruitment.internal.job-board'), { search: search.value || undefined, department: department.value || undefined }, { preserveState: true, replace: true }) }
watch(search, () => { clearTimeout(searchTimer); searchTimer = setTimeout(applyFilters, 400) }); watch(department, applyFilters)
function clearFilters() { search.value = ''; department.value = ''; router.get(route('recruitment.internal.job-board'), {}, { replace: true }) }

function excerpt(text: string, length = 150) { if (!text) return ''; if (text.length <= length) return text; return text.slice(0, length).trimEnd() + '…' }
function formatSalaryRange(min: number | null, max: number | null): string | null { const fmt = (n: number) => `R ${n.toLocaleString('en-ZA')}`; if (min && max) return `${fmt(min)} – ${fmt(max)} ${t('recruitment_internal.salary_suffix')}`; if (min) return `From ${fmt(min)} ${t('recruitment_internal.salary_suffix')}`; if (max) return `Up to ${fmt(max)} ${t('recruitment_internal.salary_suffix')}`; return null }
function formatDate(val: string | null) { if (!val) return null; return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' }) }

const departmentItems = computed(() => { const depts = [...new Set(props.vacancies.data.map(v => v.department).filter((d): d is string => !!d))]; return [{ title: t('recruitment_internal.filter_all_departments'), value: '' }, ...depts.map(d => ({ title: d, value: d }))] })
const hasFilters = computed(() => !!(search.value || department.value))
</script>

<template>
  <div class="pa-6">
    <div class="text-center mb-8"><VIcon icon="tabler-briefcase" size="48" color="primary" class="mb-3" /><h3 style="font-size: 2rem; font-weight: 700; color: #0f172a;" class="mb-2">{{ t('recruitment_internal.job_board_title') }}</h3><p class="text-medium-emphasis text-body-1 mb-0">{{ t('recruitment_internal.job_board_subtitle') }}</p></div>
    <VRow justify="center" class="mb-6"><VCol cols="12" md="8"><VCard elevation="0" border style="border-radius: 12px;"><VCardText class="pa-4"><VRow dense align="center"><VCol cols="12" sm="7"><VTextField v-model="search" :label="t('recruitment_internal.search_placeholder')" prepend-inner-icon="tabler-search" variant="outlined" density="comfortable" clearable hide-details @click:clear="() => { search = ''; applyFilters() }" /></VCol><VCol cols="12" sm="4"><VSelect v-model="department" :items="departmentItems" :label="t('recruitment_internal.filter_department')" variant="outlined" density="comfortable" hide-details clearable /></VCol><VCol cols="auto"><VBtn variant="text" color="default" :disabled="!hasFilters" @click="clearFilters">{{ t('recruitment_internal.clear') }}</VBtn></VCol></VRow></VCardText></VCard></VCol></VRow>

    <div v-if="!vacancies.data.length" class="text-center py-16"><VIcon icon="tabler-briefcase-off" size="64" color="medium-emphasis" class="mb-4" /><h5 class="text-h6 font-weight-semibold mb-2">{{ t('recruitment_internal.no_vacancies') }}</h5><p class="text-medium-emphasis mb-0">{{ hasFilters ? t('recruitment_internal.no_vacancies_filters') : t('recruitment_internal.no_vacancies_default') }}</p></div>

    <VRow v-else>
      <VCol v-for="vacancy in vacancies.data" :key="vacancy.id" cols="12" sm="6" lg="4">
        <VCard elevation="0" border style="border-radius: 12px; height: 100%; display: flex; flex-direction: column; transition: box-shadow 0.2s;" class="vacancy-card">
          <VCardText class="pa-5" style="flex: 1;">
            <div class="d-flex align-center gap-2 flex-wrap mb-3"><VChip v-if="vacancy.department" size="small" color="primary" variant="tonal" prepend-icon="tabler-building">{{ vacancy.department }}</VChip><VChip size="small" color="default" variant="tonal" prepend-icon="tabler-briefcase">{{ vacancy.job_type.label }}</VChip></div>
            <h5 class="font-weight-bold mb-2" style="font-size: 1.1rem; color: #0f172a; line-height: 1.4;">{{ vacancy.title }}</h5>
            <div v-if="formatSalaryRange(vacancy.salary_band_min, vacancy.salary_band_max)" class="d-flex align-center gap-1 mb-2"><VIcon icon="tabler-currency-dollar" size="16" color="success" /><span class="text-body-2 font-weight-medium text-success">{{ formatSalaryRange(vacancy.salary_band_min, vacancy.salary_band_max) }}</span></div>
            <p class="text-body-2 text-medium-emphasis mb-3" style="line-height: 1.6;">{{ excerpt(vacancy.job_description) }}</p>
            <div class="d-flex align-center gap-1"><VIcon :icon="vacancy.expires_at ? 'tabler-calendar-clock' : 'tabler-calendar'" size="14" class="text-medium-emphasis" /><span class="text-caption text-medium-emphasis">{{ vacancy.expires_at ? t('recruitment_internal.closes', { date: formatDate(vacancy.expires_at) }) : t('recruitment_internal.no_closing_date') }}</span></div>
          </VCardText>
          <VDivider />
          <VCardActions class="pa-4"><VSpacer /><VBtn color="primary" variant="flat" size="small" append-icon="tabler-arrow-right" :href="route('recruitment.internal.apply', vacancy.uuid)">{{ t('recruitment_internal.apply_now') }}</VBtn></VCardActions>
        </VCard>
      </VCol>
    </VRow>

    <div v-if="vacancies.meta?.last_page > 1" class="d-flex justify-center mt-6"><VPagination :model-value="vacancies.meta.current_page" :length="vacancies.meta.last_page" :total-visible="7" @update:model-value="(page: number) => router.get(route('recruitment.internal.job-board'), { page }, { preserveState: true })" /></div>
  </div>
</template>

<style scoped>
.vacancy-card:hover { box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08) !important; }
</style>
