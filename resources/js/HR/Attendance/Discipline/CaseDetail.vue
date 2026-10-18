<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

type DisciplineCase = {
  id: number; employee: string; case_number: string
  case_type: string; case_type_label: string
  status: string; status_color: string
  severity: string; severity_color: string
  summary: string; violations: string[]
  ai_recommendation: string | null; resolution_notes: string | null
  period_start: string; period_end: string; created_at: string
}
type Pack = {
  id: number; attendance_summary: Record<string, any>
  violation_details: { date: string; type: string; detail: string }[]
  ai_narrative: string | null; warning_letter_content: string | null
  recommended_sanction: string | null; supporting_references: string[]
  generated_at: string
}

const props = defineProps<{ disciplineCase: DisciplineCase; pack: Pack | null }>()
const tab = defineModel<string>('tab', { default: 'overview' })

function generatePack() { useForm({}).post(`/attendance/discipline/cases/${props.disciplineCase.id}/generate-pack`, {}) }
function generateRecommendation() { useForm({}).post(`/attendance/discipline/cases/${props.disciplineCase.id}/generate-recommendation`, {}) }
function copyLetter() { if (props.pack?.warning_letter_content) { navigator.clipboard.writeText(props.pack.warning_letter_content) } }
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <VRow>
      <VCol cols="12">
        <VCard>
          <VCardText class="py-5">
            <div class="d-flex flex-wrap align-start justify-space-between gap-4">
              <div>
                <div class="text-overline text-medium-emphasis">{{ t('attendance_discipline.case_detail_breadcrumb') }}</div>
                <h4 class="text-h4 mb-1">{{ disciplineCase.case_number }}</h4>
                <p class="mb-0 text-medium-emphasis">{{ disciplineCase.employee }} · {{ disciplineCase.case_type_label }}</p>
                <div class="d-flex gap-2 mt-2">
                  <VChip :color="disciplineCase.status_color" size="small" variant="tonal" class="text-capitalize">{{ disciplineCase.status }}</VChip>
                  <VChip :color="disciplineCase.severity_color" size="small" variant="tonal" class="text-capitalize">{{ disciplineCase.severity }}</VChip>
                  <span class="text-caption text-medium-emphasis">{{ disciplineCase.period_start }} – {{ disciplineCase.period_end }}</span>
                </div>
              </div>
              <div class="d-flex gap-2 flex-wrap">
                <VBtn size="small" color="info" variant="tonal" @click="generateRecommendation"><i class="fas fa-robot me-2"></i>{{ t('attendance_discipline.ai_recommendation_btn') }}</VBtn>
                <VBtn size="small" color="warning" variant="tonal" @click="generatePack"><i class="fas fa-file-alt me-2"></i>{{ t('attendance_discipline.generate_pack') }}</VBtn>
                <VBtn variant="text" size="small" color="primary" href="/attendance/discipline"><i class="fas fa-arrow-left me-1"></i>{{ t('attendance_discipline.back') }}</VBtn>
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12">
        <VTabs v-model="tab" color="primary">
          <VTab value="overview"><i class="fas fa-info-circle me-2"></i>{{ t('attendance_discipline.tab_overview') }}</VTab>
          <VTab value="pack" :disabled="!pack"><i class="fas fa-file-alt me-2"></i>{{ t('attendance_discipline.tab_evidence_pack') }}</VTab>
          <VTab value="letter" :disabled="!pack?.warning_letter_content"><i class="fas fa-envelope me-2"></i>{{ t('attendance_discipline.tab_warning_letter') }}</VTab>
        </VTabs>
        <VDivider />

        <VWindow v-model="tab">
          <VWindowItem value="overview">
            <VRow class="mt-0">
              <VCol cols="12" md="6">
                <VCard class="mt-4">
                  <VCardTitle class="py-3 px-5">{{ t('attendance_discipline.case_summary') }}</VCardTitle>
                  <VDivider />
                  <VCardText class="py-3">
                    <div class="text-body-2 mb-3">{{ disciplineCase.summary }}</div>
                    <div class="text-caption font-weight-medium mb-2">{{ t('attendance_discipline.violations') }}</div>
                    <div v-for="v in disciplineCase.violations" :key="v" class="d-flex align-center gap-2 mb-1"><i class="fas fa-exclamation-circle text-error" style="font-size:11px"></i><span class="text-caption">{{ v }}</span></div>
                    <div v-if="disciplineCase.resolution_notes" class="mt-3">
                      <div class="text-caption font-weight-medium mb-1">{{ t('attendance_discipline.resolution_notes') }}</div>
                      <div class="text-caption text-medium-emphasis">{{ disciplineCase.resolution_notes }}</div>
                    </div>
                  </VCardText>
                </VCard>
              </VCol>
              <VCol cols="12" md="6">
                <VCard class="mt-4">
                  <VCardTitle class="py-3 px-5"><i class="fas fa-robot me-2 text-info"></i>{{ t('attendance_discipline.ai_recommendation_title') }}</VCardTitle>
                  <VDivider />
                  <VCardText class="py-3">
                    <div v-if="disciplineCase.ai_recommendation" style="white-space:pre-wrap;font-size:0.88rem">{{ disciplineCase.ai_recommendation }}</div>
                    <div v-else class="text-caption text-medium-emphasis font-italic">{{ t('attendance_discipline.no_ai_recommendation') }}</div>
                  </VCardText>
                </VCard>
              </VCol>
            </VRow>
          </VWindowItem>

          <VWindowItem value="pack" v-if="pack">
            <VRow class="mt-0">
              <VCol cols="12" md="5">
                <VCard class="mt-4">
                  <VCardTitle class="py-3 px-5">{{ t('attendance_discipline.attendance_summary') }}</VCardTitle>
                  <VDivider />
                  <VCardText class="py-3">
                    <div v-for="(val, key) in pack.attendance_summary" :key="key" class="d-flex justify-space-between mb-2 text-caption"><span class="text-medium-emphasis text-capitalize">{{ String(key).replace(/_/g,' ') }}</span><span class="font-weight-medium">{{ val }}</span></div>
                  </VCardText>
                </VCard>
                <VCard class="mt-4">
                  <VCardTitle class="py-3 px-5">{{ t('attendance_discipline.violation_log') }}</VCardTitle>
                  <VDivider />
                  <VTable density="compact">
                    <thead><tr><th>{{ t('attendance_discipline.table_date') }}</th><th>{{ t('attendance_discipline.table_type') }}</th><th>{{ t('attendance_discipline.table_detail') }}</th></tr></thead>
                    <tbody>
                      <tr v-for="v in pack.violation_details" :key="v.date + v.type">
                        <td class="text-caption">{{ v.date }}</td>
                        <td><VChip :color="v.type === 'absence' ? 'error' : 'warning'" size="x-small" variant="tonal" class="text-capitalize">{{ v.type.replace(/_/g,' ') }}</VChip></td>
                        <td class="text-caption">{{ v.detail }}</td>
                      </tr>
                    </tbody>
                  </VTable>
                </VCard>
              </VCol>
              <VCol cols="12" md="7">
                <VCard class="mt-4">
                  <VCardTitle class="py-3 px-5">{{ t('attendance_discipline.ai_narrative') }}</VCardTitle>
                  <VDivider />
                  <VCardText class="py-3">
                    <div style="white-space:pre-wrap;font-size:0.88rem;line-height:1.7">{{ pack.ai_narrative ?? t('attendance_discipline.narrative_not_generated') }}</div>
                  </VCardText>
                </VCard>
                <VCard class="mt-4" v-if="pack.supporting_references?.length">
                  <VCardTitle class="py-3 px-5">{{ t('attendance_discipline.supporting_references') }}</VCardTitle>
                  <VDivider />
                  <VCardText class="py-3">
                    <div v-for="ref in pack.supporting_references" :key="ref" class="d-flex align-center gap-2 mb-1 text-caption"><i class="fas fa-book text-secondary" style="font-size:11px"></i>{{ ref }}</div>
                  </VCardText>
                </VCard>
              </VCol>
            </VRow>
          </VWindowItem>

          <VWindowItem value="letter" v-if="pack?.warning_letter_content">
            <VCard class="mt-4">
              <VCardTitle class="py-3 px-5 d-flex align-center justify-space-between">
                <span>{{ t('attendance_discipline.warning_letter_title', { type: disciplineCase.case_type_label }) }}</span>
                <VBtn size="small" variant="tonal" color="primary" @click="copyLetter"><i class="fas fa-copy me-2"></i>{{ t('attendance_discipline.copy') }}</VBtn>
              </VCardTitle>
              <VDivider />
              <VCardText class="py-4">
                <div class="pa-5 rounded" style="background:#f9f9f9;font-family:monospace;white-space:pre-wrap;font-size:0.85rem;line-height:1.8;border:1px solid rgba(0,0,0,0.08)">{{ pack.warning_letter_content }}</div>
              </VCardText>
            </VCard>
          </VWindowItem>
        </VWindow>
      </VCol>
    </VRow>
  </VContainer>
</template>
