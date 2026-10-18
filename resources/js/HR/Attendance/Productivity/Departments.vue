<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type Department = {
  id: number; department: string; efficiency_score: number; headcount: number
  avg_attendance_rate: number; avg_punctuality_rate: number
  total_overtime_hours: number; overworked_count: number
  low_productivity_count: number; efficiency_tier: string; tier_color: string
  is_overworked_dept: boolean; ai_summary: string | null
}

const props = defineProps<{ departments: Department[] }>()

const totalDepartments = computed(() => props.departments.length)
const avgEfficiency = computed(() => { if (!props.departments.length) return 0; const sum = props.departments.reduce((acc, d) => acc + d.efficiency_score, 0); return Math.round(sum / props.departments.length) })
const overworkedCount = computed(() => props.departments.filter(d => d.is_overworked_dept).length)
const belowAverageCount = computed(() => props.departments.filter(d => d.efficiency_tier === 'low' || d.efficiency_tier === 'critical').length)

const headers = computed(() => [
  { title: t('attendance_productivity.table_department'), key: 'department' },
  { title: t('attendance_productivity.table_efficiency') + ' Score', key: 'efficiency_score' },
  { title: t('attendance_productivity.table_headcount'), key: 'headcount' },
  { title: t('attendance_productivity.table_avg_attendance'), key: 'avg_attendance_rate' },
  { title: 'Avg Punctuality', key: 'avg_punctuality_rate' },
  { title: t('attendance_productivity.table_ot_hours'), key: 'total_overtime_hours' },
  { title: t('attendance_productivity.table_overworked') + ' Workers', key: 'overworked_count' },
  { title: t('attendance_productivity.table_low_performers'), key: 'low_productivity_count' },
  { title: t('attendance_productivity.table_tier'), key: 'efficiency_tier' },
])

const sortedDepartments = computed(() => [...props.departments].sort((a, b) => a.efficiency_score - b.efficiency_score))
const expandedRows = computed(() =>
  props.departments.filter(d => d.ai_summary).map(d => String(d.id))
)
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow class="mb-4" align="center">
      <VCol><h4 class="text-h4">{{ t('attendance_productivity.dept_title') }}</h4></VCol>
      <VCol cols="auto"><VBtn :href="'/attendance/productivity'" variant="tonal" prepend-icon="tabler-arrow-left">{{ t('attendance_productivity.back') }}</VBtn></VCol>
    </VRow>

    <VRow class="mb-6">
      <VCol cols="12" sm="6" md="3"><VCard elevation="0" border><VCardText><div class="text-overline text-medium-emphasis mb-1">{{ t('attendance_productivity.total_departments') }}</div><div class="text-h4 font-weight-bold">{{ totalDepartments }}</div></VCardText></VCard></VCol>
      <VCol cols="12" sm="6" md="3"><VCard elevation="0" border><VCardText><div class="text-overline text-medium-emphasis mb-1">{{ t('attendance_productivity.avg_efficiency_score') }}</div><div class="text-h4 font-weight-bold">{{ avgEfficiency }}</div></VCardText></VCard></VCol>
      <VCol cols="12" sm="6" md="3"><VCard elevation="0" border><VCardText><div class="text-overline text-medium-emphasis mb-1">{{ t('attendance_productivity.overworked') }}</div><div class="text-h4 font-weight-bold text-warning">{{ overworkedCount }}</div></VCardText></VCard></VCol>
      <VCol cols="12" sm="6" md="3"><VCard elevation="0" border><VCardText><div class="text-overline text-medium-emphasis mb-1">{{ t('attendance_productivity.below_average') }}</div><div class="text-h4 font-weight-bold text-error">{{ belowAverageCount }}</div></VCardText></VCard></VCol>
    </VRow>

    <VCard elevation="0" border>
      <VDataTable :headers="headers" :items="sortedDepartments" item-value="id" :sort-by="[{ key: 'efficiency_score', order: 'asc' }]" :expanded="expandedRows" show-expand expand-on-click>
        <template #item.efficiency_score="{ item }"><div class="d-flex align-center gap-2" style="min-width: 140px"><span class="text-body-2 font-weight-medium" style="min-width: 32px">{{ item.efficiency_score }}</span><VProgressLinear :model-value="item.efficiency_score" :color="item.tier_color" height="6" rounded style="flex: 1" /></div></template>
        <template #item.efficiency_tier="{ item }"><VChip :color="item.tier_color" size="small">{{ item.efficiency_tier }}</VChip></template>
        <template #item.is_overworked_dept="{ item }"><VIcon v-if="item.is_overworked_dept" color="warning" icon="tabler-alert-circle" /></template>
        <template #item.overworked_count="{ item }"><div class="d-flex align-center gap-1"><VIcon v-if="item.is_overworked_dept" color="warning" icon="tabler-alert-circle" size="small" /><span>{{ item.overworked_count }}</span></div></template>
        <template #expanded-row="{ columns, item }">
          <tr v-if="item.ai_summary"><td :colspan="columns.length" class="pa-4 bg-surface-variant"><div class="d-flex align-start gap-2"><VIcon icon="tabler-robot-outline" color="primary" size="small" class="mt-1" /><div><div class="text-caption text-medium-emphasis mb-1">{{ t('attendance_productivity.ai_summary') }}</div><div class="text-body-2">{{ item.ai_summary }}</div></div></div></td></tr>
        </template>
      </VDataTable>
    </VCard>
  </VContainer>
</template>
