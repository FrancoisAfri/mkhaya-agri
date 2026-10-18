<script setup lang="ts">
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Profile {
  id: number; employee_id: number; employee: string
  period_start: string; period_end: string
  overall_risk_score: number; risk_level: string
  attendance_score: number; performance_score: number
  disciplinary_score: number; compliance_score: number
  active_warnings: number; total_cases_ytd: number
  repeated_offences: number; late_arrivals: number
  unexcused_absences: number; risk_factors: Array<{factor: string; count: number}>
  ai_narrative: string|null; is_ai_generated: boolean; generated_at: string
}
interface Stats { high_risk: number; total: number; critical: number; medium: number }
interface Paginated<T> { data: T[]; current_page: number; last_page: number; total: number }
interface Employee { id: number; name: string; email: string }

const props = defineProps<{
  profiles: Paginated<Profile>
  stats: Stats
  period_start: string; period_end: string
  filters: Record<string, string>
  employees: Employee[]
}>()

const generateDialog = ref(false)
const generateForm   = useForm<{ employee_id: number|null; period_start: string; period_end: string }>({ employee_id: null, period_start: props.period_start, period_end: props.period_end })

function submitGenerate() {
  generateForm.post(route('disciplinary.workforce-intelligence.generate'), {
    onSuccess: () => { generateDialog.value = false; generateForm.reset() },
  })
}

const levelColor = (l: string) => ({ low: 'success', medium: 'warning', high: 'orange', critical: 'error' })[l] ?? 'grey'
const riskScore  = (s: number) => Math.round(s)

const statsLabels: Record<string, string> = {
  high_risk: t('disciplinary_workforce-intelligence.stats.high_risk'),
  total: t('disciplinary_workforce-intelligence.stats.total'),
  critical: t('disciplinary_workforce-intelligence.stats.critical'),
  medium: t('disciplinary_workforce-intelligence.stats.medium'),
}
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('disciplinary_workforce-intelligence.index.title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('disciplinary_workforce-intelligence.index.subtitle') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-brain" @click="generateDialog = true">{{ t('disciplinary_workforce-intelligence.index.generate_profile') }}</VBtn>
    </div>

    <VRow class="mb-4">
      <VCol v-for="(val, key) in stats" :key="key" cols="12" sm="3">
        <VCard variant="tonal" :color="key === 'critical' ? 'error' : key === 'high_risk' ? 'orange' : 'primary'">
          <VCardText class="text-center">
            <div class="text-h4 font-weight-bold">{{ val }}</div>
            <div class="text-caption text-uppercase mt-1">{{ statsLabels[key] ?? key }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VCard>
      <VDataTable
        :items="profiles.data"
        :headers="[
          { title: t('disciplinary_workforce-intelligence.index.table.employee'), key: 'employee' },
          { title: t('disciplinary_workforce-intelligence.index.table.risk_level'), key: 'risk_level' },
          { title: t('disciplinary_workforce-intelligence.index.table.risk_score'), key: 'overall_risk_score' },
          { title: t('disciplinary_workforce-intelligence.index.table.attendance'), key: 'attendance_score' },
          { title: t('disciplinary_workforce-intelligence.index.table.performance'), key: 'performance_score' },
          { title: t('disciplinary_workforce-intelligence.index.table.disciplinary'), key: 'disciplinary_score' },
          { title: t('disciplinary_workforce-intelligence.index.table.active_warnings'), key: 'active_warnings' },
          { title: t('disciplinary_workforce-intelligence.index.table.period_end'), key: 'period_end' },
          { title: t('disciplinary_workforce-intelligence.index.table.actions'), key: '_actions', sortable: false },
        ]"
      >
        <template #item.risk_level="{ item }">
          <VChip :color="levelColor(item.risk_level)" size="small" variant="tonal">{{ item.risk_level }}</VChip>
        </template>
        <template #item.overall_risk_score="{ item }">
          <VProgressLinear :model-value="item.overall_risk_score" :color="levelColor(item.risk_level)" height="16" rounded>
            <template #default>{{ riskScore(item.overall_risk_score) }}%</template>
          </VProgressLinear>
        </template>
        <template #item.attendance_score="{ item }">{{ Math.round(item.attendance_score) }}%</template>
        <template #item.performance_score="{ item }">{{ Math.round(item.performance_score) }}%</template>
        <template #item.disciplinary_score="{ item }">{{ Math.round(item.disciplinary_score) }}%</template>
        <template #item._actions="{ item }">
          <VBtn size="small" variant="text" color="primary" :href="route('disciplinary.workforce-intelligence.employee', item.employee_id)">
            {{ t('disciplinary_workforce-intelligence.index.table.view') }}
          </VBtn>
        </template>
      </VDataTable>
    </VCard>

    <VDialog v-model="generateDialog" max-width="480">
      <VCard>
        <VCardTitle class="pa-4">{{ t('disciplinary_workforce-intelligence.index.dialog.title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAutocomplete
            v-model="generateForm.employee_id"
            :items="employees"
            item-title="name"
            item-value="id"
            :label="t('disciplinary_workforce-intelligence.index.dialog.employee_label')"
            variant="outlined"
            density="compact"
            :error-messages="generateForm.errors.employee_id"
            class="mb-3"
            clearable
          >
            <template #item="{ props: itemProps, item }"><VListItem v-bind="itemProps" :subtitle="item.raw.email" /></template>
          </VAutocomplete>
          <VRow dense>
            <VCol cols="6"><VTextField v-model="generateForm.period_start" :label="t('disciplinary_workforce-intelligence.index.dialog.period_start_label')" type="date" :error-messages="generateForm.errors.period_start" /></VCol>
            <VCol cols="6"><VTextField v-model="generateForm.period_end" :label="t('disciplinary_workforce-intelligence.index.dialog.period_end_label')" type="date" :error-messages="generateForm.errors.period_end" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="generateDialog = false">{{ t('disciplinary_workforce-intelligence.index.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="generateForm.processing" @click="submitGenerate">{{ t('disciplinary_workforce-intelligence.index.dialog.generate') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
