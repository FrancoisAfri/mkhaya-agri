<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Schedule = {
  id: number; uuid: string; title: string; maintenance_type: string
  status: { value: string; label: string; color: string }
  due_date: string | null; estimated_cost: string | null; actual_cost: string | null
  assigned_to: { id: number; name: string } | null
  asset: { uuid: string; name: string; asset_tag: string | null } | null; created_at: string
}
type PaginatedSchedules = {
  data: Schedule[]; meta: { current_page: number; last_page: number; total: number; per_page: number }
  links: { first: string | null; last: string | null; prev: string | null; next: string | null }
}

const props = defineProps<{
  schedules: PaginatedSchedules
  filters: { search?: string; status?: string }
  stats: { scheduled: number; in_progress: number; overdue: number; completed: number }
}>()

const searchFilter = ref(props.filters.search ?? '')
const statusFilter = ref(props.filters.status ?? '')

const statusOptions = computed(() => [
  { title: t('assets_maintenance_index.statuses.all'), value: '' },
  { title: t('assets_maintenance_index.statuses.scheduled'), value: 'scheduled' },
  { title: t('assets_maintenance_index.statuses.in_progress'), value: 'in_progress' },
  { title: t('assets_maintenance_index.statuses.overdue'), value: 'overdue' },
  { title: t('assets_maintenance_index.statuses.completed'), value: 'completed' },
  { title: t('assets_maintenance_index.statuses.cancelled'), value: 'cancelled' },
])

function applyFilters() {
  router.get(route('assets.maintenance.index'), {
    search: searchFilter.value || undefined,
    status: statusFilter.value || undefined,
  }, { preserveState: true, replace: true })
}

function formatDate(val: string | null) { if (!val) return '—'; return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' }) }
function formatCurrency(val: string | null) { if (val == null) return '—'; return new Intl.NumberFormat('en-ZA', { style: 'currency', currency: 'ZAR', maximumFractionDigits: 0 }).format(Number(val)) }
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <h4 class="text-h4 font-weight-bold mb-1">{{ t('assets_maintenance_index.page_title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('assets_maintenance_index.description') }}</p>
      </div>
    </div>

    <VRow class="mb-6">
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="pa-4"><p class="text-caption text-medium-emphasis mb-1">{{ t('assets_maintenance_index.stats.scheduled') }}</p><p class="text-h5 font-weight-bold mb-0">{{ stats.scheduled }}</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="pa-4"><p class="text-caption text-medium-emphasis mb-1">{{ t('assets_maintenance_index.stats.overdue') }}</p><p class="text-h5 font-weight-bold text-error mb-0">{{ stats.overdue }}</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="pa-4"><p class="text-caption text-medium-emphasis mb-1">{{ t('assets_maintenance_index.stats.in_progress') }}</p><p class="text-h5 font-weight-bold text-warning mb-0">{{ stats.in_progress }}</p></VCardText></VCard></VCol>
      <VCol cols="6" sm="3"><VCard elevation="0" border><VCardText class="pa-4"><p class="text-caption text-medium-emphasis mb-1">{{ t('assets_maintenance_index.stats.completed') }}</p><p class="text-h5 font-weight-bold text-success mb-0">{{ stats.completed }}</p></VCardText></VCard></VCol>
    </VRow>

    <VCard elevation="0" border class="mb-4">
      <VCardText class="pa-4">
        <VRow align="center">
          <VCol cols="12" sm="6" md="5"><VTextField v-model="searchFilter" :placeholder="t('assets_maintenance_index.filters.search_placeholder')" prepend-inner-icon="tabler-search" density="comfortable" variant="outlined" hide-details clearable @keyup.enter="applyFilters" @click:clear="() => { searchFilter = ''; applyFilters() }" /></VCol>
          <VCol cols="12" sm="4" md="3"><VSelect v-model="statusFilter" :items="statusOptions" item-title="title" item-value="value" :label="t('assets_maintenance_index.filters.status')" density="comfortable" variant="outlined" hide-details @update:model-value="applyFilters" /></VCol>
          <VCol cols="auto"><VBtn color="primary" @click="applyFilters">{{ t('assets_maintenance_index.filters.filter') }}</VBtn></VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VCard elevation="0" border>
      <VCardText v-if="schedules.data.length" class="pa-0">
        <VTable>
          <thead><tr><th>{{ t('assets_maintenance_index.table.asset') }}</th><th>{{ t('assets_maintenance_index.table.title') }}</th><th>{{ t('assets_maintenance_index.table.type') }}</th><th>{{ t('assets_maintenance_index.table.status') }}</th><th>{{ t('assets_maintenance_index.table.due_date') }}</th><th>{{ t('assets_maintenance_index.table.est_cost') }}</th><th>{{ t('assets_maintenance_index.table.assigned_to') }}</th><th></th></tr></thead>
          <tbody>
            <tr v-for="schedule in schedules.data" :key="schedule.id" class="cursor-pointer" @click="router.visit(route('assets.maintenance.show', [schedule.asset?.uuid, schedule.uuid]))">
              <td><div><p class="text-body-2 font-weight-medium mb-0">{{ schedule.asset?.name ?? '—' }}</p><p class="text-caption text-medium-emphasis mb-0">{{ schedule.asset?.asset_tag ?? '' }}</p></div></td>
              <td><span class="text-body-2">{{ schedule.title }}</span></td>
              <td><span class="text-body-2 text-capitalize">{{ schedule.maintenance_type?.replace('_', ' ') }}</span></td>
              <td><VChip :color="schedule.status.color" size="x-small" label variant="tonal">{{ schedule.status.label }}</VChip></td>
              <td><span class="text-body-2" :class="{ 'text-error': schedule.status.value === 'scheduled' && schedule.due_date && new Date(schedule.due_date) < new Date() }">{{ formatDate(schedule.due_date) }}</span></td>
              <td><span class="text-body-2">{{ formatCurrency(schedule.estimated_cost) }}</span></td>
              <td><span class="text-body-2">{{ schedule.assigned_to?.name ?? '—' }}</span></td>
              <td><VIcon icon="tabler-chevron-right" size="18" color="medium-emphasis" /></td>
            </tr>
          </tbody>
        </VTable>
      </VCardText>
      <VCardText v-else class="text-center py-16">
        <VIcon icon="tabler-tool" size="64" color="medium-emphasis" class="mb-4" />
        <p class="text-body-1 font-weight-medium mb-1">{{ t('assets_maintenance_index.empty.title') }}</p>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('assets_maintenance_index.empty.description') }}</p>
      </VCardText>
      <VDivider v-if="schedules.meta.last_page > 1" />
      <VCardText v-if="schedules.meta.last_page > 1" class="pa-4 d-flex justify-center">
        <VPagination :model-value="schedules.meta.current_page" :length="schedules.meta.last_page" :total-visible="7"
          @update:model-value="(page) => router.get(route('assets.maintenance.index'), { ...filters, page }, { preserveState: true })" />
      </VCardText>
    </VCard>
  </div>
</template>
