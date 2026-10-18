<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import axios from 'axios'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type ComplianceScore = {
  id: number; employee: string
  period_start: string; period_end: string
  total_score: number; score_color: string; score_label: string
  lateness_score: number; overtime_score: number
  attendance_score: number; adherence_score: number
  violations_count: number; exceptions_count: number
  ai_summary: string | null; computed_at: string
}
type Paginated<T> = { data: T[]; current_page: number; last_page: number; total: number }

const props = defineProps<{ scores: Paginated<ComplianceScore>; averageScore: number }>()

const subNav       = ref('compliance')
const expandedId   = ref<number | null>(null)
const aiLoadingId  = ref<number | null>(null)
const genDialog    = ref(false)

const genForm = useForm({
  period_start: new Date(Date.now() - 30 * 86400000).toISOString().split('T')[0],
  period_end:   new Date().toISOString().split('T')[0],
})

function generate() { genForm.post('/attendance/policy/generate-compliance', { onSuccess: () => { genDialog.value = false } }) }

async function generateAiSummary(s: ComplianceScore) {
  aiLoadingId.value = s.id
  try { const res = await axios.post(`/attendance/policy/compliance/${s.id}/ai-summary`); s.ai_summary = res.data.summary; expandedId.value = s.id }
  finally { aiLoadingId.value = null }
}

const avgColor = props.averageScore >= 75 ? 'success' : props.averageScore >= 60 ? 'warning' : 'error'
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <VCol cols="12" class="pb-0">
        <VCard variant="outlined">
          <VTabs v-model="subNav" color="primary" density="compact">
            <VTab value="engine" href="/attendance/policy"><VIcon start size="16" icon="tabler-cog-outline" />{{ t('attendance_policy_pages.tab_policy_engine') }}</VTab>
            <VTab value="violations" href="/attendance/policy/violations"><VIcon start size="16" icon="tabler-alert" />{{ t('attendance_policy_pages.tab_violations') }}</VTab>
            <VTab value="compliance" href="/attendance/policy/compliance"><VIcon start size="16" icon="tabler-shield-check" />{{ t('attendance_policy_pages.tab_compliance') }}</VTab>
            <VTab value="exceptions" href="/attendance/policy/exceptions"><VIcon start size="16" icon="tabler-shield-off-outline" />{{ t('attendance_policy_pages.tab_exceptions') }}</VTab>
          </VTabs>
        </VCard>
      </VCol>
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3 py-5">
            <div>
              <div class="text-overline text-medium-emphasis">{{ t('attendance_policy_pages.compliance_header') }}</div>
              <h4 class="text-h4 mb-1">{{ t('attendance_policy_pages.compliance_title') }}</h4>
              <p class="mb-0 text-medium-emphasis">{{ t('attendance_policy_pages.compliance_subtitle') }}</p>
            </div>
            <div class="d-flex gap-2">
              <VBtn variant="text" color="primary" href="/attendance/policy"><i class="fas fa-arrow-left me-2"></i>{{ t('attendance_policy_pages.policy_engine_back') }}</VBtn>
              <VBtn color="success" @click="genDialog = true"><i class="fas fa-sync me-2"></i>{{ t('attendance_policy_pages.generate_scores') }}</VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard :color="avgColor" variant="tonal" class="text-center">
          <VCardText class="py-6">
            <div class="text-h3 font-weight-bold mb-1">{{ averageScore }}<span class="text-h6">/100</span></div>
            <div class="text-caption">{{ t('attendance_policy_pages.org_avg_compliance') }}</div>
            <VProgressLinear :model-value="averageScore" :color="avgColor" class="mt-3" rounded height="6" />
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="8">
        <VCard>
          <VCardTitle class="py-3 px-5">{{ t('attendance_policy_pages.score_components_title') }}</VCardTitle>
          <VDivider />
          <VCardText class="py-3">
            <VRow dense>
              <VCol cols="6">
                <div class="d-flex align-center gap-2 mb-3"><VIcon color="error" size="16"><i class="fas fa-clock"></i></VIcon><div><div class="text-caption font-weight-medium">{{ t('attendance_policy_pages.lateness_score') }}</div><div class="text-caption text-medium-emphasis">{{ t('attendance_policy_pages.lateness_desc') }}</div></div></div>
                <div class="d-flex align-center gap-2 mb-3"><VIcon color="warning" size="16"><i class="fas fa-hourglass-half"></i></VIcon><div><div class="text-caption font-weight-medium">{{ t('attendance_policy_pages.overtime_score') }}</div><div class="text-caption text-medium-emphasis">{{ t('attendance_policy_pages.overtime_desc') }}</div></div></div>
              </VCol>
              <VCol cols="6">
                <div class="d-flex align-center gap-2 mb-3"><VIcon color="info" size="16"><i class="fas fa-user-check"></i></VIcon><div><div class="text-caption font-weight-medium">{{ t('attendance_policy_pages.attendance_score') }}</div><div class="text-caption text-medium-emphasis">{{ t('attendance_policy_pages.attendance_desc') }}</div></div></div>
                <div class="d-flex align-center gap-2 mb-3"><VIcon color="deep-purple" size="16"><i class="fas fa-gavel"></i></VIcon><div><div class="text-caption font-weight-medium">{{ t('attendance_policy_pages.adherence_score') }}</div><div class="text-caption text-medium-emphasis">{{ t('attendance_policy_pages.adherence_desc') }}</div></div></div>
              </VCol>
            </VRow>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12">
        <VCard>
          <VTable hover density="comfortable">
            <thead>
              <tr>
                <th>{{ t('attendance_policy_pages.table_employee') }}</th>
                <th>Total Score</th>
                <th>{{ t('attendance_policy_pages.lateness_score') }}</th>
                <th>{{ t('attendance_policy_pages.overtime_score') }}</th>
                <th>{{ t('attendance_policy_pages.attendance_score') }}</th>
                <th>{{ t('attendance_policy_pages.adherence_score') }}</th>
                <th>{{ t('attendance_policy_pages.tab_violations') }}</th>
                <th>{{ t('attendance_policy_pages.table_period') }}</th>
                <th class="text-center">AI</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!scores.data.length">
                <td colspan="9" class="text-center py-8 text-medium-emphasis">{{ t('attendance_policy_pages.no_compliance_scores') }}</td>
              </tr>
              <template v-for="s in scores.data" :key="s.id">
                <tr @click="expandedId = expandedId === s.id ? null : s.id" style="cursor:pointer">
                  <td class="font-weight-medium">{{ s.employee }}</td>
                  <td><VChip :color="s.score_color" size="small" variant="tonal">{{ s.total_score }}/100 · {{ s.score_label }}</VChip></td>
                  <td><div class="d-flex align-center gap-1"><VProgressLinear :model-value="s.lateness_score / 25 * 100" color="error" rounded height="4" style="width:40px" /><span class="text-caption">{{ s.lateness_score }}</span></div></td>
                  <td><div class="d-flex align-center gap-1"><VProgressLinear :model-value="s.overtime_score / 25 * 100" color="warning" rounded height="4" style="width:40px" /><span class="text-caption">{{ s.overtime_score }}</span></div></td>
                  <td><div class="d-flex align-center gap-1"><VProgressLinear :model-value="s.attendance_score / 25 * 100" color="info" rounded height="4" style="width:40px" /><span class="text-caption">{{ s.attendance_score }}</span></div></td>
                  <td><div class="d-flex align-center gap-1"><VProgressLinear :model-value="s.adherence_score / 25 * 100" color="deep-purple" rounded height="4" style="width:40px" /><span class="text-caption">{{ s.adherence_score }}</span></div></td>
                  <td><VChip :color="s.violations_count > 0 ? 'error' : 'success'" size="x-small" variant="tonal">{{ s.violations_count }}</VChip></td>
                  <td class="text-caption text-medium-emphasis">{{ s.period_start }} – {{ s.period_end }}</td>
                  <td class="text-center" @click.stop><VBtn icon size="small" variant="text" color="info" title="Generate AI Summary" :loading="aiLoadingId === s.id" @click="generateAiSummary(s)"><i class="fas fa-robot"></i></VBtn></td>
                </tr>
                <tr v-if="expandedId === s.id && s.ai_summary">
                  <td colspan="9" class="py-3 px-6" style="background:rgba(0,0,0,0.02)">
                    <VAlert type="info" variant="tonal" density="compact" style="font-size:0.82rem"><i class="fas fa-robot me-2"></i><strong>{{ t('attendance_policy_pages.ai_compliance_summary') }}</strong> {{ s.ai_summary }}</VAlert>
                  </td>
                </tr>
              </template>
            </tbody>
          </VTable>
          <VCardText v-if="scores.last_page > 1" class="d-flex justify-center py-4">
            <VPagination :model-value="scores.current_page" :length="scores.last_page" @update:model-value="page => router.get('/attendance/policy/compliance', { page }, { preserveState: true })" />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="genDialog" max-width="420">
      <VCard>
        <VCardTitle class="py-4 px-5">{{ t('attendance_policy_pages.generate_dialog_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="py-4">
          <VRow dense>
            <VCol cols="6"><VTextField v-model="genForm.period_start" :label="t('attendance_policy_pages.field_period_start')" type="date" density="compact" /></VCol>
            <VCol cols="6"><VTextField v-model="genForm.period_end" :label="t('attendance_policy_pages.field_period_end')" type="date" density="compact" /></VCol>
          </VRow>
        </VCardText>
        <VDivider />
        <VCardActions class="px-5 py-3"><VSpacer /><VBtn variant="text" @click="genDialog = false">{{ t('attendance_policy_pages.cancel') }}</VBtn><VBtn color="success" :loading="genForm.processing" @click="generate"><i class="fas fa-sync me-2"></i>{{ t('attendance_policy_pages.generate') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
