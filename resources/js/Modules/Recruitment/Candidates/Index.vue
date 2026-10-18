<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import AppTextField from '@/@core/components/app-form-elements/AppTextField.vue'

const { t } = useI18n()
const route = ziggyRoute

type Candidate = { id: number; uuid: string; full_name: string; email: string; phone: string | null; is_duplicate: boolean; has_ai_consent: boolean; applications_count: number; created_at: string }
const props = defineProps<{ candidates: { data: Candidate[]; next_cursor: string | null; has_more: boolean }; filters: Record<string, any> }>()

const search = ref<string>(props.filters?.search ?? ''); const showDuplicates = ref<boolean>(props.filters?.duplicates === '1')
let searchTimer: ReturnType<typeof setTimeout>
function applyFilters() { router.get(route('recruitment.candidates.index'), { search: search.value || undefined, duplicates: showDuplicates.value ? '1' : undefined }, { preserveState: true, replace: true }) }
watch(search, () => { clearTimeout(searchTimer); searchTimer = setTimeout(applyFilters, 400) }); watch(showDuplicates, applyFilters)

const rows = computed(() => props.candidates?.data ?? [])
function formatDate(val: string | null) { if (!val) return '—'; return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' }) }
function loadMore() { if (!props.candidates.has_more) return; router.get(route('recruitment.candidates.index'), { ...props.filters, cursor: props.candidates.next_cursor }, { preserveState: true }) }

const headers = computed(() => [
  { title: t('recruitment_candidates.table_candidate'), key: 'full_name', sortable: false },
  { title: t('recruitment_candidates.table_email'), key: 'email', sortable: false },
  { title: t('recruitment_candidates.table_phone'), key: 'phone', sortable: false, width: '150px' },
  { title: t('recruitment_candidates.table_applications'), key: 'applications_count', sortable: false, width: '130px' },
  { title: t('recruitment_candidates.table_flags'), key: 'flags', sortable: false, width: '130px' },
  { title: t('recruitment_candidates.table_registered'), key: 'created_at', sortable: false, width: '140px' },
])
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6"><div><h4 class="text-h4 font-weight-bold mb-1">{{ t('recruitment_candidates.talent_pool_title') }}</h4><p class="text-medium-emphasis mb-0">{{ t('recruitment_candidates.talent_pool_subtitle') }}</p></div></div>
    <VCard elevation="0" border class="mb-5"><VCardText class="pa-4"><VRow dense align="center"><VCol cols="12" md="5"><AppTextField v-model="search" :label="t('recruitment_candidates.search_placeholder')" prepend-inner-icon="tabler-search" clearable hide-details /></VCol><VCol cols="12" md="4"><VSwitch v-model="showDuplicates" :label="t('recruitment_candidates.duplicates_only')" color="warning" hide-details density="compact" /></VCol></VRow></VCardText></VCard>
    <VCard elevation="0" border>
      <VDataTable :headers="headers" :items="rows" item-value="id" hide-default-footer hover @click:row="(_evt: unknown, row: any) => router.visit(route('recruitment.candidates.show', row.item.id))">
        <template #item.full_name="{ item }"><div class="d-flex align-center gap-2"><VAvatar size="32" color="primary" variant="tonal"><span class="text-caption">{{ (item.full_name ?? '?')[0].toUpperCase() }}</span></VAvatar><div><p class="text-body-2 font-weight-medium text-primary mb-0">{{ item.full_name }}</p></div></div></template>
        <template #item.email="{ item }"><span class="text-body-2">{{ item.email }}</span></template>
        <template #item.phone="{ item }"><span class="text-body-2 text-medium-emphasis">{{ item.phone ?? '—' }}</span></template>
        <template #item.applications_count="{ item }"><div class="d-flex align-center gap-1"><VIcon icon="tabler-briefcase" size="14" class="text-medium-emphasis" /><span class="text-body-2">{{ item.applications_count }}</span></div></template>
        <template #item.flags="{ item }"><div class="d-flex gap-1 flex-wrap"><VChip v-if="item.is_duplicate" size="small" color="warning" label class="font-weight-medium">{{ t('recruitment_candidates.duplicate_flag') }}</VChip><VChip v-if="item.has_ai_consent" size="small" color="info" label class="font-weight-medium">{{ t('recruitment_candidates.ai_flag') }}</VChip></div></template>
        <template #item.created_at="{ item }"><span class="text-body-2 text-medium-emphasis">{{ formatDate(item.created_at) }}</span></template>
        <template #no-data><div class="text-center py-12"><VIcon icon="tabler-user-search" size="64" color="medium-emphasis" class="mb-4" /><p class="text-body-1 font-weight-medium mb-1">{{ t('recruitment_candidates.no_candidates') }}</p><p class="text-body-2 text-medium-emphasis mb-0">{{ t('recruitment_candidates.no_candidates_sub') }}</p></div></template>
      </VDataTable>
      <VDivider v-if="candidates.has_more" />
      <div v-if="candidates.has_more" class="d-flex justify-center pa-4"><VBtn variant="tonal" color="primary" prepend-icon="tabler-chevron-down" @click="loadMore">{{ t('recruitment_candidates.load_more') }}</VBtn></div>
    </VCard>
  </div>
</template>
