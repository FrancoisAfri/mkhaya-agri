<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  vacancy: { uuid: string; title: string; department: string | null; job_description: string; required_qualifications: string | null; required_skills: string[] | null; salary_band_min: number | null; salary_band_max: number | null; job_type: string | null; internal_deadline: string | null }
  employee: { first_name: string | null; last_name: string | null; email: string | null; phone: string | null; employee_number: string | null }
}>()

const form = useForm({ expected_salary: null as number | null, location: '', cover_note: '', cv: null as File | null })

const isDragging = ref(false); const cvFileName = ref<string | null>(null); const cvFileInput = ref<HTMLInputElement | null>(null)

function submit() { form.post(route('recruitment.internal.apply.store', props.vacancy.uuid), { forceFormData: true }) }
function onFileChange(event: Event) { const input = event.target as HTMLInputElement; if (input.files && input.files[0]) { form.cv = input.files[0]; cvFileName.value = input.files[0].name } }
function onDrop(event: DragEvent) { isDragging.value = false; const file = event.dataTransfer?.files?.[0]; if (file) { form.cv = file; cvFileName.value = file.name } }
function formatSalaryRange(min: number | null, max: number | null): string | null { const fmt = (n: number) => `R ${n.toLocaleString('en-ZA')}`; if (min && max) return `${fmt(min)} – ${fmt(max)}`; if (min) return `From ${fmt(min)}`; if (max) return `Up to ${fmt(max)}`; return null }
const salaryRange = computed(() => formatSalaryRange(props.vacancy.salary_band_min, props.vacancy.salary_band_max))
const employeeFullName = computed(() => { const parts = [props.employee.first_name, props.employee.last_name].filter(Boolean); return parts.join(' ') || 'Employee' })
</script>

<template>
  <div class="pa-6">
    <VRow>
      <VCol cols="12" md="5">
        <VCard border elevation="0">
          <VCardText class="pa-6">
            <div class="d-flex flex-wrap gap-2 mb-4">
              <VChip v-if="vacancy.department" size="small" color="primary" variant="tonal" prepend-icon="tabler-building">{{ vacancy.department }}</VChip>
              <VChip v-if="vacancy.job_type" size="small" variant="tonal" prepend-icon="tabler-briefcase">{{ vacancy.job_type }}</VChip>
              <VChip size="small" color="info" variant="tonal" prepend-icon="tabler-shield-lock">{{ t('recruitment_internal.internal_only_chip') }}</VChip>
            </div>
            <h4 class="font-weight-bold mb-3">{{ vacancy.title }}</h4>
            <div v-if="salaryRange" class="d-flex align-center gap-2 mb-3"><VIcon icon="tabler-currency-dollar" color="success" size="18" /><span class="text-body-2 font-weight-medium text-success">{{ salaryRange }}</span></div>
            <div v-if="vacancy.internal_deadline" class="d-flex align-center gap-2 mb-4"><VIcon icon="tabler-clock-exclamation" color="warning" size="18" /><span class="text-body-2">{{ t('recruitment_internal.internal_only_until', { date: vacancy.internal_deadline }) }}</span></div>
            <VDivider class="mb-4" />
            <p class="text-caption text-medium-emphasis text-uppercase mb-2">{{ t('recruitment_internal.about_this_role') }}</p>
            <p class="text-body-2" style="white-space: pre-wrap; line-height: 1.7;">{{ vacancy.job_description }}</p>
            <div v-if="vacancy.required_qualifications" class="mt-4"><p class="text-caption text-medium-emphasis text-uppercase mb-2">{{ t('recruitment_internal.requirements') }}</p><p class="text-body-2" style="white-space: pre-wrap; line-height: 1.7;">{{ vacancy.required_qualifications }}</p></div>
            <div v-if="vacancy.required_skills && vacancy.required_skills.length" class="mt-4"><p class="text-caption text-medium-emphasis text-uppercase mb-2">{{ t('recruitment_internal.skills') }}</p><div class="d-flex flex-wrap gap-2"><VChip v-for="skill in vacancy.required_skills" :key="skill" size="small" variant="tonal" color="primary">{{ skill }}</VChip></div></div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" md="7">
        <VCard border elevation="0">
          <VCardTitle class="pa-6 pb-2 text-h6 font-weight-bold"><VIcon icon="tabler-user-edit" color="primary" class="me-2" />{{ t('recruitment_internal.apply_as', { name: employeeFullName }) }}</VCardTitle>
          <VCardText class="pa-6 pt-3">
            <VAlert v-if="employee.employee_number" type="info" variant="tonal" density="compact" class="mb-4">{{ t('recruitment_internal.submitting_as', { number: employee.employee_number, email: employee.email }) }}</VAlert>
            <VRow>
              <VCol cols="12" sm="6"><VTextField v-model.number="form.expected_salary" :label="t('recruitment_internal.field_expected_salary')" type="number" variant="outlined" density="comfortable" prefix="R" :min="0" :error-messages="form.errors.expected_salary" /></VCol>
              <VCol cols="12" sm="6"><VTextField v-model="form.location" :label="t('recruitment_internal.field_preferred_location')" variant="outlined" density="comfortable" :error-messages="form.errors.location" /></VCol>
              <VCol cols="12"><VTextarea v-model="form.cover_note" :label="t('recruitment_internal.field_motivation')" variant="outlined" rows="5" :error-messages="form.errors.cover_note" /></VCol>
              <VCol cols="12">
                <p class="text-body-2 font-weight-medium mb-2">{{ t('recruitment_internal.field_cv') }}</p>
                <div class="pa-5 rounded-lg text-center" style="border: 2px dashed; cursor: pointer;" :style="{ borderColor: isDragging ? 'rgb(var(--v-theme-primary))' : 'rgba(0,0,0,0.2)', background: isDragging ? 'rgba(var(--v-theme-primary), 0.05)' : 'rgba(0,0,0,0.02)' }" @click="cvFileInput?.click()" @dragover.prevent="isDragging = true" @dragleave.prevent="isDragging = false" @drop.prevent="onDrop">
                  <VIcon :icon="cvFileName ? 'tabler-file-check' : 'tabler-file-upload'" :color="cvFileName ? 'success' : 'medium-emphasis'" size="36" class="mb-2" />
                  <div v-if="cvFileName"><p class="text-body-2 font-weight-medium text-success mb-0">{{ cvFileName }}</p><p class="text-caption text-medium-emphasis mb-0">{{ t('recruitment_internal.click_to_replace') }}</p></div>
                  <div v-else><p class="text-body-2 font-weight-medium mb-0">{{ t('recruitment_internal.drag_drop_cv') }}</p><p class="text-caption text-medium-emphasis mb-0">{{ t('recruitment_internal.cv_formats') }}</p></div>
                </div>
                <input ref="cvFileInput" type="file" accept=".pdf,.doc,.docx" style="display: none;" @change="onFileChange" />
                <p v-if="form.errors.cv" class="text-caption text-error mt-1 mb-0">{{ form.errors.cv }}</p>
              </VCol>
              <VCol cols="12"><VDivider class="mb-5" /><VBtn color="primary" size="large" block :loading="form.processing" :disabled="!form.cv" @click="submit"><VIcon icon="tabler-send" size="18" class="me-2" />{{ t('recruitment_internal.submit_application') }}</VBtn></VCol>
            </VRow>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
