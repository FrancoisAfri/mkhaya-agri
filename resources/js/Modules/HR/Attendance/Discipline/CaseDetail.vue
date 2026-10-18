<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'

const route = ziggyRoute

type DisciplineCase = {
  id: number
  employee: string
  case_number: string
  case_type: string
  case_type_label: string
  status: string
  status_color: string
  severity: string
  severity_color: string
  summary: string | null
  violations: any[]
  ai_recommendation: string | null
  resolution_notes: string | null
  period_start: string
  period_end: string
  created_at: string
}

type Pack = {
  id: number
  attendance_summary: string | null
  violation_details: any
  ai_narrative: string | null
  warning_letter_content: string | null
  recommended_sanction: string | null
  supporting_references: any
  generated_at: string
}

const props = defineProps<{
  disciplineCase: DisciplineCase
  pack: Pack | null
}>()

const resolveDialog = ref(false)
const resolveForm   = useForm({ status: 'resolved', resolution_notes: '' })

function generatePack() { router.post(route('attendance.discipline.cases.generate-pack', { attendanceDisciplineCase: props.disciplineCase.id })) }
function generateRec()  { router.post(route('attendance.discipline.cases.generate-recommendation', { attendanceDisciplineCase: props.disciplineCase.id })) }

function submitResolve() {
  resolveForm.post(route('attendance.discipline.cases.resolve', { attendanceDisciplineCase: props.disciplineCase.id }), {
    onSuccess: () => { resolveDialog.value = false },
  })
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6" style="max-width: 900px;">
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-2">
        <VBtn icon="tabler-arrow-left" size="small" variant="text" @click="router.visit(route('attendance.discipline.index'))" />
        <div>
          <h4 class="text-h4">Case {{ disciplineCase.case_number }}</h4>
          <p class="text-medium-emphasis mb-0">{{ disciplineCase.employee }} · {{ disciplineCase.case_type_label }}</p>
        </div>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <VBtn size="small" color="primary" variant="tonal" prepend-icon="tabler-robot" @click="generateRec">AI Recommendation</VBtn>
        <VBtn size="small" color="info" variant="tonal" prepend-icon="tabler-file-text" @click="generatePack">Generate Pack</VBtn>
        <VBtn v-if="!['resolved','closed','dismissed'].includes(disciplineCase.status)" size="small" color="success" variant="flat" @click="resolveDialog = true">Resolve / Close</VBtn>
      </div>
    </div>

    <!-- Status badges -->
    <div class="d-flex gap-2 mb-5">
      <VChip :color="disciplineCase.status_color" variant="tonal" class="text-capitalize">{{ disciplineCase.status }}</VChip>
      <VChip :color="disciplineCase.severity_color" variant="tonal" class="text-capitalize">{{ disciplineCase.severity }}</VChip>
      <span class="text-body-2 text-medium-emphasis align-self-center">Period: {{ disciplineCase.period_start }} – {{ disciplineCase.period_end }}</span>
    </div>

    <!-- Summary -->
    <VCard elevation="0" border class="mb-5">
      <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Case Summary</VCardTitle>
      <VDivider />
      <VCardText class="pa-5">
        <p v-if="disciplineCase.summary" class="text-body-2">{{ disciplineCase.summary }}</p>
        <p v-else class="text-body-2 text-medium-emphasis">No summary available.</p>
      </VCardText>
    </VCard>

    <!-- AI Recommendation -->
    <VCard v-if="disciplineCase.ai_recommendation" elevation="0" border class="mb-5">
      <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold d-flex align-center gap-2">
        <VIcon icon="tabler-robot" color="primary" size="18" />
        AI Recommendation
      </VCardTitle>
      <VDivider />
      <VCardText class="pa-5">
        <p class="text-body-2" style="white-space: pre-wrap;">{{ disciplineCase.ai_recommendation }}</p>
      </VCardText>
    </VCard>

    <!-- Disciplinary Pack -->
    <VCard v-if="pack" elevation="0" border class="mb-5">
      <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Disciplinary Pack <span class="text-caption text-medium-emphasis font-weight-regular ms-2">Generated {{ pack.generated_at }}</span></VCardTitle>
      <VDivider />
      <VCardText class="pa-5">
        <VRow>
          <VCol v-if="pack.attendance_summary" cols="12">
            <p class="text-caption font-weight-semibold text-medium-emphasis mb-1">Attendance Summary</p>
            <p class="text-body-2">{{ pack.attendance_summary }}</p>
          </VCol>
          <VCol v-if="pack.recommended_sanction" cols="12">
            <p class="text-caption font-weight-semibold text-medium-emphasis mb-1">Recommended Sanction</p>
            <VChip color="error" variant="tonal" size="small">{{ pack.recommended_sanction }}</VChip>
          </VCol>
          <VCol v-if="pack.ai_narrative" cols="12">
            <p class="text-caption font-weight-semibold text-medium-emphasis mb-1">AI Narrative</p>
            <p class="text-body-2" style="white-space: pre-wrap;">{{ pack.ai_narrative }}</p>
          </VCol>
          <VCol v-if="pack.warning_letter_content" cols="12">
            <p class="text-caption font-weight-semibold text-medium-emphasis mb-1">Warning Letter</p>
            <VCard variant="outlined" class="pa-4"><p class="text-body-2" style="white-space: pre-wrap; font-family: monospace;">{{ pack.warning_letter_content }}</p></VCard>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Resolution Notes -->
    <VCard v-if="disciplineCase.resolution_notes" elevation="0" border>
      <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">Resolution Notes</VCardTitle>
      <VDivider />
      <VCardText class="pa-5">
        <p class="text-body-2">{{ disciplineCase.resolution_notes }}</p>
      </VCardText>
    </VCard>

    <!-- Resolve dialog -->
    <VDialog v-model="resolveDialog" max-width="440" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-h6">Resolve / Close Case</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VSelect v-model="resolveForm.status" :items="[{title:'Resolved',value:'resolved'},{title:'Closed',value:'closed'},{title:'Dismissed',value:'dismissed'}]" item-title="title" item-value="value" label="Outcome" variant="outlined" density="comfortable" class="mb-4" />
          <VTextarea v-model="resolveForm.resolution_notes" label="Resolution Notes" variant="outlined" rows="4" :error-messages="resolveForm.errors.resolution_notes" />
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="resolveDialog = false">Cancel</VBtn>
          <VBtn color="success" variant="flat" :loading="resolveForm.processing" @click="submitResolve">Submit</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
