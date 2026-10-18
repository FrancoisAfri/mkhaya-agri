<script setup lang="ts">
import { router, useForm, Link } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Requisition = { id: number; uuid: string; title: string; department: string | null; job_type: { value: string; label: string }; headcount: number; reason: string; job_description: string | null; required_qualifications: string | null; required_skills: string[]; salary_band_min: number | null; salary_band_max: number | null; target_start_date: string | null }
const props = defineProps<{ requisition: Requisition; jobTypes: Array<{ value: string; label: string }> }>()

const form = useForm({
  title: props.requisition.title, department: props.requisition.department ?? '', job_type: props.requisition.job_type.value,
  headcount: props.requisition.headcount, reason: props.requisition.reason, job_description: props.requisition.job_description ?? '',
  required_qualifications: props.requisition.required_qualifications ?? '', required_skills: props.requisition.required_skills ?? [],
  salary_band_min: props.requisition.salary_band_min, salary_band_max: props.requisition.salary_band_max, target_start_date: props.requisition.target_start_date ?? '',
})

function submit() { form.put(route('recruitment.requisitions.update', props.requisition.uuid)) }
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center gap-2 mb-2">
      <VBtn icon="tabler-arrow-left" variant="text" size="small" @click="router.visit(route('recruitment.requisitions.show', requisition.uuid))" />
      <div>
        <span class="text-medium-emphasis text-body-2 cursor-pointer" @click="router.visit(route('recruitment.requisitions.index'))">{{ t('recruitment_requisition_pages.breadcrumb_requisitions') }}</span>
        <VIcon icon="tabler-chevron-right" size="16" class="text-medium-emphasis mx-1" />
        <span class="text-medium-emphasis text-body-2 cursor-pointer" @click="router.visit(route('recruitment.requisitions.show', requisition.uuid))">{{ requisition.title }}</span>
        <VIcon icon="tabler-chevron-right" size="16" class="text-medium-emphasis mx-1" />
        <span class="text-body-2 font-weight-medium">{{ t('recruitment_requisition_pages.breadcrumb_edit') }}</span>
      </div>
    </div>
    <div class="mb-6"><h4 class="font-weight-bold mb-1" style="font-size: 1.5rem; font-weight: 700; color: #0f172a;">{{ t('recruitment_requisition_pages.title_edit') }}</h4><p class="text-medium-emphasis mb-0">{{ t('recruitment_requisition_pages.subtitle_edit') }}</p></div>
    <VRow>
      <VCol cols="12" lg="8">
        <VCard elevation="0" border class="mb-5" style="border-radius: 12px;"><VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold"><VIcon icon="tabler-info-circle" size="18" color="primary" class="me-2" />{{ t('recruitment_requisition_pages.section_basic_info') }}</VCardTitle><VCardText class="pa-5 pt-2"><VRow><VCol cols="12"><VTextField v-model="form.title" :label="t('recruitment_requisition_pages.field_title')" variant="outlined" density="comfortable" :error-messages="form.errors.title" hide-details="auto" /></VCol><VCol cols="12" sm="6"><VTextField v-model="form.department" :label="t('recruitment_requisition_pages.field_department')" variant="outlined" density="comfortable" :error-messages="form.errors.department" hide-details="auto" /></VCol><VCol cols="12" sm="6"><VSelect v-model="form.job_type" :items="jobTypes" item-title="label" item-value="value" :label="t('recruitment_requisition_pages.field_job_type')" variant="outlined" density="comfortable" :error-messages="form.errors.job_type" hide-details="auto" /></VCol><VCol cols="12" sm="6"><VTextField v-model.number="form.headcount" :label="t('recruitment_requisition_pages.field_headcount')" type="number" variant="outlined" density="comfortable" :min="1" :max="50" :error-messages="form.errors.headcount" hide-details="auto" /></VCol></VRow></VCardText></VCard>
        <VCard elevation="0" border class="mb-5" style="border-radius: 12px;"><VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold"><VIcon icon="tabler-file-text" size="18" color="primary" class="me-2" />{{ t('recruitment_requisition_pages.section_position_details') }}</VCardTitle><VCardText class="pa-5 pt-2"><VRow><VCol cols="12"><VTextarea v-model="form.reason" :label="t('recruitment_requisition_pages.field_reason')" variant="outlined" density="comfortable" rows="3" :error-messages="form.errors.reason" hide-details="auto" /></VCol><VCol cols="12"><VTextarea v-model="form.job_description" :label="t('recruitment_requisition_pages.field_job_description')" variant="outlined" density="comfortable" rows="4" :error-messages="form.errors.job_description" hide-details="auto" /></VCol><VCol cols="12"><VTextarea v-model="form.required_qualifications" :label="t('recruitment_requisition_pages.field_qualifications')" variant="outlined" density="comfortable" rows="3" :error-messages="form.errors.required_qualifications" hide-details="auto" /></VCol></VRow></VCardText></VCard>
        <VCard elevation="0" border class="mb-5" style="border-radius: 12px;"><VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold"><VIcon icon="tabler-tags" size="18" color="primary" class="me-2" />{{ t('recruitment_requisition_pages.section_skills') }}</VCardTitle><VCardText class="pa-5 pt-2"><VCombobox v-model="form.required_skills" :label="t('recruitment_requisition_pages.section_skills')" variant="outlined" density="comfortable" multiple chips closable-chips hide-selected :placeholder="t('recruitment_requisition_pages.field_skills_placeholder')" :error-messages="form.errors.required_skills" hide-details="auto" /></VCardText></VCard>
        <VCard elevation="0" border class="mb-6" style="border-radius: 12px;"><VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold"><VIcon icon="tabler-currency-dollar" size="18" color="primary" class="me-2" />{{ t('recruitment_requisition_pages.section_compensation') }}</VCardTitle><VCardText class="pa-5 pt-2"><VRow><VCol cols="12" sm="6"><VTextField v-model.number="form.salary_band_min" :label="t('recruitment_requisition_pages.field_salary_min')" type="number" variant="outlined" density="comfortable" prefix="R" :min="0" :error-messages="form.errors.salary_band_min" hide-details="auto" /></VCol><VCol cols="12" sm="6"><VTextField v-model.number="form.salary_band_max" :label="t('recruitment_requisition_pages.field_salary_max')" type="number" variant="outlined" density="comfortable" prefix="R" :min="0" :error-messages="form.errors.salary_band_max" hide-details="auto" /></VCol><VCol cols="12" sm="6"><VTextField v-model="form.target_start_date" :label="t('recruitment_requisition_pages.field_target_start')" type="date" variant="outlined" density="comfortable" :error-messages="form.errors.target_start_date" hide-details="auto" /></VCol></VRow></VCardText></VCard>
        <div class="d-flex align-center justify-end gap-3"><Link :href="route('recruitment.requisitions.show', requisition.uuid)"><VBtn variant="text" color="default" :disabled="form.processing">{{ t('recruitment_requisition_pages.cancel') }}</VBtn></Link><VBtn color="primary" :loading="form.processing" :disabled="!form.title || !form.reason" @click="submit"><VIcon icon="tabler-device-floppy" size="18" class="me-2" />{{ t('recruitment_requisition_pages.save_changes') }}</VBtn></div>
      </VCol>
      <VCol cols="12" lg="4">
        <div style="position: sticky; top: 80px;">
          <VCard elevation="0" border style="border-radius: 12px;"><VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">{{ t('recruitment_requisition_pages.sidebar_summary') }}</VCardTitle><VCardText class="pa-5 pt-0"><div class="d-flex flex-column gap-3"><div class="d-flex align-center justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('recruitment_requisition_pages.sidebar_position') }}</span><span class="text-body-2 font-weight-medium text-end" style="max-width: 180px;">{{ form.title || '—' }}</span></div><VDivider /><div class="d-flex align-center justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('recruitment_requisition_pages.sidebar_department') }}</span><span class="text-body-2">{{ form.department || '—' }}</span></div><VDivider /><div class="d-flex align-center justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('recruitment_requisition_pages.sidebar_job_type') }}</span><span class="text-body-2">{{ form.job_type ? jobTypes.find(t => t.value === form.job_type)?.label ?? form.job_type : '—' }}</span></div><VDivider /><div class="d-flex align-center justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('recruitment_requisition_pages.sidebar_headcount') }}</span><div class="d-flex align-center gap-1"><VIcon icon="tabler-users" size="14" class="text-medium-emphasis" /><span class="text-body-2 font-weight-medium">{{ form.headcount }}</span></div></div></div></VCardText></VCard>
        </div>
      </VCol>
    </VRow>
  </div>
</template>
