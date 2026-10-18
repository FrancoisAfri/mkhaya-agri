<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Schedule = {
  id: number; name: string; department: string | null
  period_start: string; period_end: string
  status: string; status_color: string; generated_by: string
  coverage_score: number | null; balance_score: number | null
  assignments_count: number; published_at: string | null
}
type Paginated<T> = { data: T[]; current_page: number; last_page: number; total: number }

const props = defineProps<{
  schedules:   Paginated<Schedule>
  departments: string[]
  filters:     { status: string }
  summary:     { draft: number; published: number; archived: number }
}>()

const createDialog = ref(false)
const filterStatus = ref(props.filters.status)

const filterStatusOptions = computed(() => [
  { title: t('attendance_roster.status_all'), value: 'all' },
  { title: t('attendance_roster.status_draft'), value: 'draft' },
  { title: t('attendance_roster.status_published'), value: 'published' },
  { title: t('attendance_roster.status_archived'), value: 'archived' },
])

const summaryItems = computed(() => [
  { key: 'draft', count: props.summary.draft, color: 'warning', label: t('attendance_roster.draft_rosters') },
  { key: 'published', count: props.summary.published, color: 'success', label: t('attendance_roster.published_rosters') },
  { key: 'archived', count: props.summary.archived, color: 'secondary', label: t('attendance_roster.archived_rosters') },
])

const createForm = useForm({
  name: '', department: '' as string | null,
  period_start: new Date().toISOString().split('T')[0],
  period_end: new Date(Date.now() + 14 * 86400000).toISOString().split('T')[0],
})

function applyFilter() { router.get('/attendance/roster', { status: filterStatus.value }, { preserveState: true }) }
function store() { createForm.post('/attendance/roster', { onSuccess: () => { createDialog.value = false } }) }
function scoreColor(score: number | null): string { if (score === null) return 'grey'; if (score >= 80) return 'success'; if (score >= 60) return 'warning'; return 'error' }
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <!-- Header -->
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div>
              <div class="text-overline text-medium-emphasis">{{ t('attendance_roster.breadcrumb_module') }}</div>
              <h4 class="text-h4 mb-1">{{ t('attendance_roster.title') }}</h4>
              <p class="mb-0 text-medium-emphasis">{{ t('attendance_roster.subtitle') }}</p>
            </div>
            <VBtn color="primary" @click="createDialog = true">
              <i class="fas fa-plus me-2"></i>{{ t('attendance_roster.new_roster') }}
            </VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Stats -->
      <VCol cols="12" md="4" v-for="item in summaryItems" :key="item.key">
        <VCard :color="item.color" variant="tonal" class="text-center">
          <VCardText class="py-4">
            <div class="text-h4 font-weight-bold">{{ item.count }}</div>
            <div class="text-caption text-capitalize">{{ item.label }}</div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Filter -->
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex gap-3 py-3">
            <VSelect
              v-model="filterStatus"
              :items="filterStatusOptions"
              :label="t('attendance_roster.filter_status')"
              density="compact"
              hide-details
              style="max-width:160px"
            />
            <VBtn color="primary" @click="applyFilter">{{ t('attendance_roster.filter_btn') }}</VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Roster table -->
      <VCol cols="12">
        <VCard>
          <VTable hover density="comfortable">
            <thead>
              <tr>
                <th>{{ t('attendance_roster.table_name') }}</th>
                <th>{{ t('attendance_roster.table_department') }}</th>
                <th>{{ t('attendance_roster.table_period') }}</th>
                <th>{{ t('attendance_roster.table_status') }}</th>
                <th>{{ t('attendance_roster.table_generated_by') }}</th>
                <th>{{ t('attendance_roster.table_coverage') }}</th>
                <th>{{ t('attendance_roster.table_balance') }}</th>
                <th>{{ t('attendance_roster.table_assignments') }}</th>
                <th>{{ t('attendance_roster.table_published') }}</th>
                <th class="text-center">{{ t('attendance_roster.table_actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!schedules.data.length">
                <td colspan="10" class="text-center py-8 text-medium-emphasis">{{ t('attendance_roster.no_rosters') }}</td>
              </tr>
              <tr v-for="s in schedules.data" :key="s.id">
                <td class="font-weight-medium">{{ s.name }}</td>
                <td class="text-caption">{{ s.department ?? '—' }}</td>
                <td class="text-caption">{{ s.period_start }} – {{ s.period_end }}</td>
                <td><VChip :color="s.status_color" size="small" variant="tonal" class="text-capitalize">{{ s.status }}</VChip></td>
                <td>
                  <VChip :color="s.generated_by === 'ai' ? 'info' : 'secondary'" size="x-small" variant="tonal">
                    <i :class="s.generated_by === 'ai' ? 'fas fa-robot me-1' : 'fas fa-user me-1'" style="font-size:10px"></i>
                    {{ s.generated_by === 'ai' ? t('attendance_roster.ai_generated') : t('attendance_roster.manual') }}
                  </VChip>
                </td>
                <td>
                  <VChip v-if="s.coverage_score !== null" :color="scoreColor(s.coverage_score)" size="small" variant="tonal">{{ s.coverage_score }}%</VChip>
                  <span v-else class="text-medium-emphasis text-caption">—</span>
                </td>
                <td>
                  <VChip v-if="s.balance_score !== null" :color="scoreColor(s.balance_score)" size="small" variant="tonal">{{ s.balance_score }}%</VChip>
                  <span v-else class="text-medium-emphasis text-caption">—</span>
                </td>
                <td><VChip size="small" variant="tonal" color="secondary">{{ s.assignments_count }}</VChip></td>
                <td class="text-caption">{{ s.published_at ? s.published_at.substring(0,10) : '—' }}</td>
                <td class="text-center">
                  <VBtn icon size="small" variant="text" color="primary" :href="`/attendance/roster/${s.id}`" title="View / Edit">
                    <i class="fas fa-edit"></i>
                  </VBtn>
                </td>
              </tr>
            </tbody>
          </VTable>
          <VCardText v-if="schedules.last_page > 1" class="d-flex justify-center py-4">
            <VPagination
              :model-value="schedules.current_page"
              :length="schedules.last_page"
              @update:model-value="page => router.get('/attendance/roster', { page }, { preserveState: true })"
            />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Create Dialog -->
    <VDialog v-model="createDialog" max-width="480">
      <VCard>
        <VCardTitle class="py-4 px-5">{{ t('attendance_roster.dialog_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="py-4">
          <VRow dense>
            <VCol cols="12">
              <VTextField v-model="createForm.name" :label="t('attendance_roster.field_name')" density="compact" :error-messages="createForm.errors.name" />
            </VCol>
            <VCol cols="12">
              <VSelect
                v-model="createForm.department"
                :items="[{title: t('attendance_roster.all_departments'), value: null}, ...departments.map(d => ({title: d, value: d}))]"
                :label="t('attendance_roster.field_department')"
                density="compact"
                clearable
              />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="createForm.period_start" :label="t('attendance_roster.field_start_date')" type="date" density="compact" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model="createForm.period_end" :label="t('attendance_roster.field_end_date')" type="date" density="compact" />
            </VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="px-5 py-3">
          <VSpacer />
          <VBtn variant="text" @click="createDialog = false">{{ t('attendance_roster.cancel') }}</VBtn>
          <VBtn color="primary" :loading="createForm.processing" @click="store">
            <i class="fas fa-plus me-2"></i>{{ t('attendance_roster.create_roster') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
