<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Offender = {
  id: number; employee: string; employee_id: number
  total_violations: number; late_count: number; absent_count: number
  no_show_count: number; early_departure_count: number; prior_warnings: number
  offender_level: string; level_label: string; offender_color: string
  first_offence_date: string | null; last_offence_date: string | null
  period_start: string; period_end: string
  violation_breakdown: Record<string, number> | null
  ai_risk_assessment: string | null; assessed_at: string
}
type Paginated<T> = { data: T[]; current_page: number; last_page: number; total: number }

const props = defineProps<{
  offenders: Paginated<Offender>
  filters:   { level: string }
  summary:   { repeat: number; serious: number; moderate: number; minor: number }
}>()

const subNav       = ref('offenders')
const filterLevel  = ref(props.filters.level)
const expandedId   = ref<number | null>(null)

function applyFilter() { router.get('/attendance/discipline/offenders', { level: filterLevel.value }, { preserveState: true }) }
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <VCol cols="12" class="pb-0">
        <VCard variant="outlined">
          <VTabs v-model="subNav" color="primary" density="compact">
            <VTab value="cases" href="/attendance/discipline"><VIcon start size="16" icon="tabler-gavel" />{{ t('attendance_discipline.tab_cases') }}</VTab>
            <VTab value="offenders" href="/attendance/discipline/offenders"><VIcon start size="16" icon="tabler-account-alert" />{{ t('attendance_discipline.tab_offenders') }}</VTab>
          </VTabs>
        </VCard>
      </VCol>
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div>
              <div class="text-overline text-medium-emphasis">{{ t('attendance_discipline.breadcrumb_module') }}</div>
              <h4 class="text-h4 mb-1">{{ t('attendance_discipline.offenders_title') }}</h4>
              <p class="mb-0 text-medium-emphasis">{{ t('attendance_discipline.offenders_subtitle') }}</p>
            </div>
            <VBtn variant="text" color="primary" href="/attendance/discipline"><i class="fas fa-arrow-left me-2"></i>{{ t('attendance_discipline.discipline_cases_back') }}</VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <VCol v-for="(count, level) in summary" :key="level" cols="6" md="3">
        <VCard :color="level === 'repeat' ? 'deep-purple' : level === 'serious' ? 'error' : level === 'moderate' ? 'warning' : 'info'" variant="tonal" class="text-center">
          <VCardText class="py-4">
            <div class="text-h4 font-weight-bold">{{ count }}</div>
            <div class="text-caption text-capitalize">{{ level === 'repeat' ? t('attendance_discipline.repeat_offender') : t(`attendance_discipline.${level}`) }}</div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex gap-3 py-3">
            <VSelect v-model="filterLevel" :items="['all','repeat','serious','moderate','minor'].map(v=>({title:v,value:v}))" :label="t('attendance_discipline.filter_level')" density="compact" hide-details style="max-width:180px" />
            <VBtn color="primary" @click="applyFilter">{{ t('attendance_discipline.filter_btn') }}</VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12">
        <VCard>
          <VTable hover density="comfortable">
            <thead>
              <tr>
                <th>{{ t('attendance_discipline.table_employee') }}</th>
                <th>{{ t('attendance_discipline.table_level') }}</th>
                <th>{{ t('attendance_discipline.table_total_violations') }}</th>
                <th>{{ t('attendance_discipline.table_late') }}</th>
                <th>{{ t('attendance_discipline.table_absent') }}</th>
                <th>{{ t('attendance_discipline.table_no_shows') }}</th>
                <th>{{ t('attendance_discipline.table_prior_warnings') }}</th>
                <th>{{ t('attendance_discipline.table_first_offence') }}</th>
                <th>{{ t('attendance_discipline.table_last_offence') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!offenders.data.length">
                <td colspan="9" class="text-center py-8 text-medium-emphasis">{{ t('attendance_discipline.no_offenders') }}</td>
              </tr>
              <template v-for="o in offenders.data" :key="o.id">
                <tr @click="expandedId = expandedId === o.id ? null : o.id" style="cursor:pointer">
                  <td class="font-weight-medium">{{ o.employee }}</td>
                  <td><VChip :color="o.offender_color" size="small" variant="tonal">{{ o.level_label }}</VChip></td>
                  <td><VChip :color="o.total_violations >= 15 ? 'deep-purple' : o.total_violations >= 10 ? 'error' : 'warning'" size="small" variant="tonal">{{ o.total_violations }}</VChip></td>
                  <td><VChip :color="o.late_count >= 5 ? 'error' : 'secondary'" size="x-small" variant="tonal">{{ o.late_count }}</VChip></td>
                  <td><VChip :color="o.absent_count >= 5 ? 'error' : 'secondary'" size="x-small" variant="tonal">{{ o.absent_count }}</VChip></td>
                  <td><VChip :color="o.no_show_count >= 3 ? 'deep-purple' : o.no_show_count > 0 ? 'error' : 'secondary'" size="x-small" variant="tonal">{{ o.no_show_count }}</VChip></td>
                  <td><VChip :color="o.prior_warnings >= 2 ? 'error' : o.prior_warnings > 0 ? 'warning' : 'success'" size="x-small" variant="tonal">{{ o.prior_warnings }}</VChip></td>
                  <td class="text-caption">{{ o.first_offence_date ?? '—' }}</td>
                  <td class="text-caption">{{ o.last_offence_date ?? '—' }}</td>
                </tr>
                <tr v-if="expandedId === o.id">
                  <td colspan="9" class="py-3 px-6" style="background:rgba(0,0,0,0.02)">
                    <VRow dense>
                      <VCol cols="12" md="4" v-if="o.violation_breakdown">
                        <div class="text-caption font-weight-medium mb-2">{{ t('attendance_discipline.violation_breakdown') }}</div>
                        <div v-for="(count, type) in o.violation_breakdown" :key="type" class="d-flex justify-space-between text-caption mb-1">
                          <span class="text-medium-emphasis text-capitalize">{{ String(type).replace(/_/g,' ') }}</span>
                          <VChip size="x-small" :color="count > 3 ? 'error' : 'secondary'" variant="tonal">{{ count }}</VChip>
                        </div>
                        <div class="text-caption text-medium-emphasis mt-1">{{ t('attendance_discipline.period_label') }} {{ o.period_start }} – {{ o.period_end }}</div>
                      </VCol>
                      <VCol cols="12" md="8" v-if="o.ai_risk_assessment">
                        <VAlert type="warning" variant="tonal" density="compact" style="font-size:0.82rem"><i class="fas fa-robot me-2"></i><strong>{{ t('attendance_discipline.ai_risk_assessment') }}</strong> {{ o.ai_risk_assessment }}</VAlert>
                      </VCol>
                    </VRow>
                  </td>
                </tr>
              </template>
            </tbody>
          </VTable>
          <VCardText v-if="offenders.last_page > 1" class="d-flex justify-center py-4">
            <VPagination :model-value="offenders.current_page" :length="offenders.last_page" @update:model-value="page => router.get('/attendance/discipline/offenders', { page }, { preserveState: true })" />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </VContainer>
</template>
