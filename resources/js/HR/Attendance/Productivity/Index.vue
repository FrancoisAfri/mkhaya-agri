<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Score = {
  id: number; employee_id: number; period_start: string; period_end: string
  overall_score: number; punctuality_score: number; attendance_rate_score: number
  overtime_balance_score: number; consistency_score: number
  attendance_rate: number; days_present: number; days_absent: number
  late_arrivals: number; early_departures: number; overtime_hours: number
  productivity_tier: string; tier_color: string
}
type Department = {
  id: number; department: string; efficiency_score: number; headcount: number
  avg_attendance_rate: number; avg_punctuality_rate: number
  total_overtime_hours: number; overworked_count: number
  low_productivity_count: number; efficiency_tier: string; tier_color: string
  is_overworked_dept: boolean; ai_summary: string | null
}
type Summary = {
  avg_score: number; high_performers: number; low_performers: number
  avg_attendance: number; overworked_depts: number
}

const props = defineProps<{
  scores: Score[]; departments: Department[]; trends: any[]; summary: Summary
}>()

const tab             = ref('overview')
const scanDialog      = ref(false)
const insightsLoading = ref(false)
const insights        = ref('')
const scanFrom        = ref(new Date().toISOString().slice(0,7) + '-01')
const scanTo          = ref(new Date().toISOString().slice(0,10))
const scanning        = ref(false)

const tierColors: Record<string, string> = { high: 'success', average: 'info', low: 'warning', at_risk: 'error', critical: 'error' }

function runScan() {
  scanning.value = true
  router.post('/attendance/productivity/scan', { from: scanFrom.value, to: scanTo.value }, { preserveScroll: true, onFinish: () => { scanning.value = false; scanDialog.value = false } })
}

async function generateInsights() {
  insightsLoading.value = true
  try { const { data } = await axios.post('/attendance/productivity/insights'); insights.value = data.insights }
  finally { insightsLoading.value = false }
}

const scoreHeaders = computed(() => [
  { title: t('attendance_productivity.table_employee_id'), key: 'employee_id' },
  { title: t('attendance_productivity.table_overall'), key: 'overall_score' },
  { title: t('attendance_productivity.table_punctuality'), key: 'punctuality_score' },
  { title: t('attendance_productivity.table_attendance_rate'), key: 'attendance_rate_score' },
  { title: t('attendance_productivity.table_ot_balance'), key: 'overtime_balance_score' },
  { title: t('attendance_productivity.table_consistency'), key: 'consistency_score' },
  { title: t('attendance_productivity.table_days_present'), key: 'days_present' },
  { title: t('attendance_productivity.table_absences'), key: 'days_absent' },
  { title: t('attendance_productivity.table_late'), key: 'late_arrivals' },
  { title: t('attendance_productivity.table_ot_hours'), key: 'overtime_hours' },
  { title: t('attendance_productivity.table_tier'), key: 'productivity_tier' },
])

const deptHeaders = computed(() => [
  { title: t('attendance_productivity.table_department'), key: 'department' },
  { title: t('attendance_productivity.table_efficiency'), key: 'efficiency_score' },
  { title: t('attendance_productivity.table_headcount'), key: 'headcount' },
  { title: t('attendance_productivity.table_avg_attendance'), key: 'avg_attendance_rate' },
  { title: t('attendance_productivity.table_total_ot_hours'), key: 'total_overtime_hours' },
  { title: t('attendance_productivity.table_low_performers'), key: 'low_productivity_count' },
  { title: t('attendance_productivity.table_overworked'), key: 'overworked_count' },
  { title: t('attendance_productivity.table_tier'), key: 'efficiency_tier' },
])
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div>
              <div class="text-overline text-medium-emphasis">{{ t('attendance_productivity.breadcrumb_module') }}</div>
              <h4 class="text-h4 mb-1">{{ t('attendance_productivity.title') }}</h4>
              <p class="mb-0 text-medium-emphasis">{{ t('attendance_productivity.subtitle') }}</p>
            </div>
            <div class="d-flex gap-2">
              <VBtn variant="tonal" color="info" :loading="insightsLoading" @click="generateInsights"><i class="fas fa-robot me-2"></i>{{ t('attendance_productivity.ai_insights_btn') }}</VBtn>
              <VBtn color="primary" @click="scanDialog = true"><i class="fas fa-bolt me-2"></i>{{ t('attendance_productivity.run_scan_btn') }}</VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="6" md="3"><VCard><VCardText class="text-center"><div class="text-h3 font-weight-bold text-primary">{{ summary.avg_score }}</div><div class="text-caption text-medium-emphasis">{{ t('attendance_productivity.avg_productivity_score') }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard><VCardText class="text-center"><div class="text-h3 font-weight-bold text-success">{{ summary.high_performers }}</div><div class="text-caption text-medium-emphasis">{{ t('attendance_productivity.high_performers') }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard><VCardText class="text-center"><div class="text-h3 font-weight-bold text-warning">{{ summary.low_performers }}</div><div class="text-caption text-medium-emphasis">{{ t('attendance_productivity.low_at_risk') }}</div></VCardText></VCard></VCol>
      <VCol cols="6" md="3"><VCard><VCardText class="text-center"><div class="text-h3 font-weight-bold text-error">{{ summary.overworked_depts }}</div><div class="text-caption text-medium-emphasis">{{ t('attendance_productivity.overworked_departments') }}</div></VCardText></VCard></VCol>

      <VCol cols="12" v-if="insights">
        <VAlert type="info" variant="tonal" class="mb-0">
          <div class="font-weight-medium mb-1"><i class="fas fa-robot me-2"></i>{{ t('attendance_productivity.ai_workforce_insights') }}</div>
          <div style="white-space:pre-line">{{ insights }}</div>
        </VAlert>
      </VCol>

      <VCol cols="12">
        <VCard>
          <VTabs v-model="tab" color="primary" class="px-4">
            <VTab value="overview"><i class="fas fa-users me-2"></i>{{ t('attendance_productivity.tab_employee_scores') }}</VTab>
            <VTab value="departments"><i class="fas fa-building me-2"></i>{{ t('attendance_productivity.tab_department_efficiency') }}</VTab>
          </VTabs>
          <VDivider />
          <VWindow v-model="tab">
            <VWindowItem value="overview">
              <VDataTable :headers="scoreHeaders" :items="scores" density="comfortable" hover class="text-sm">
                <template #item.overall_score="{ item }"><div class="d-flex align-center gap-2"><VProgressLinear :model-value="item.overall_score" :color="tierColors[item.productivity_tier]" height="8" rounded style="min-width:60px" /><span class="font-weight-medium">{{ item.overall_score }}</span></div></template>
                <template #item.productivity_tier="{ item }"><VChip :color="tierColors[item.productivity_tier]" size="small" variant="tonal">{{ item.productivity_tier.replace('_', ' ') }}</VChip></template>
                <template #item.attendance_rate="{ item }">{{ item.attendance_rate }}%</template>
                <template #item.overtime_hours="{ item }">{{ item.overtime_hours }}h</template>
              </VDataTable>
            </VWindowItem>
            <VWindowItem value="departments">
              <VDataTable :headers="deptHeaders" :items="departments" density="comfortable" hover>
                <template #item.efficiency_score="{ item }"><div class="d-flex align-center gap-2"><VProgressLinear :model-value="item.efficiency_score" :color="tierColors[item.efficiency_tier]" height="8" rounded style="min-width:60px" /><span class="font-weight-medium">{{ item.efficiency_score }}</span></div></template>
                <template #item.efficiency_tier="{ item }"><VChip :color="tierColors[item.efficiency_tier]" size="small" variant="tonal">{{ item.efficiency_tier }}</VChip></template>
                <template #item.is_overworked_dept="{ item }"><VChip v-if="item.is_overworked_dept" color="error" size="small" variant="tonal">{{ t('attendance_productivity.overworked_chip') }}</VChip><span v-else class="text-medium-emphasis">—</span></template>
                <template #item.avg_attendance_rate="{ item }">{{ item.avg_attendance_rate }}%</template>
                <template #item.total_overtime_hours="{ item }">{{ item.total_overtime_hours }}h</template>
              </VDataTable>
            </VWindowItem>
          </VWindow>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="scanDialog" max-width="420">
      <VCard>
        <VCardTitle class="pa-4">{{ t('attendance_productivity.scan_dialog_title') }}</VCardTitle>
        <VCardText>
          <VRow dense>
            <VCol cols="6"><VTextField v-model="scanFrom" :label="t('attendance_productivity.field_from')" type="date" density="compact" /></VCol>
            <VCol cols="6"><VTextField v-model="scanTo" :label="t('attendance_productivity.field_to')" type="date" density="compact" /></VCol>
          </VRow>
          <p class="text-caption text-medium-emphasis mt-2">{{ t('attendance_productivity.scan_dialog_info') }}</p>
        </VCardText>
        <VCardActions class="pa-4 pt-0 justify-end">
          <VBtn variant="text" @click="scanDialog = false">{{ t('attendance_productivity.cancel') }}</VBtn>
          <VBtn color="primary" :loading="scanning" @click="runScan"><i class="fas fa-bolt me-2"></i>{{ t('attendance_productivity.run') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
