<script setup lang="ts">
import { computed } from 'vue'
import { router, useForm, Link } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  jobTypes: Array<{ value: string; label: string }>
  visibilityOptions: Array<{ value: string; label: string; description: string; color: string }>
  requisitions: Array<{ id: number; requisition_number: string; title: string }>
}>()

const form = useForm({
  title: '', department: '', visibility: 'external_only', job_type: 'permanent',
  headcount: 1, job_description: '', required_qualifications: '', required_skills: [] as string[],
  salary_band_min: null as number | null, salary_band_max: null as number | null,
  expires_at: '', internal_deadline: '', requisition_id: null as number | null,
})

const visibilityIcons: Record<string, string> = {
  internal_only: 'tabler-users', external_only: 'tabler-world', internal_first_then_external: 'tabler-user-share',
}
const selectedVisibility = computed(() => props.visibilityOptions.find(o => o.value === form.visibility))
const requisitionItems = computed(() => props.requisitions.map(r => ({ value: r.id, title: `${r.requisition_number} — ${r.title}` })))
function submit() { form.post(route('recruitment.vacancies.store')) }
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center gap-2 mb-2">
      <VBtn icon="tabler-arrow-left" variant="text" size="small" @click="router.visit(route('recruitment.vacancies.index'))" />
      <div>
        <span class="text-medium-emphasis text-body-2 cursor-pointer" @click="router.visit(route('recruitment.vacancies.index'))">{{ t('recruitment_vacancies.breadcrumb_vacancies') }}</span>
        <VIcon icon="tabler-chevron-right" size="16" class="text-medium-emphasis mx-1" />
        <span class="text-body-2 font-weight-medium">{{ t('recruitment_vacancies.breadcrumb_new') }}</span>
      </div>
    </div>
    <div class="mb-6">
      <h4 class="font-weight-bold mb-1" style="font-size: 1.5rem; font-weight: 700; color: #0f172a;">{{ t('recruitment_vacancies.title_new') }}</h4>
      <p class="text-medium-emphasis mb-0">{{ t('recruitment_vacancies.subtitle_new') }}</p>
    </div>
    <VRow>
      <VCol cols="12" lg="8">
        <VCard elevation="0" border class="mb-5" style="border-radius: 12px;">
          <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold"><VIcon icon="tabler-briefcase" size="18" color="primary" class="me-2" />{{ t('recruitment_vacancies.section_job_details') }}</VCardTitle>
          <VCardText class="pa-5 pt-2">
            <VRow>
              <VCol cols="12"><VTextField v-model="form.title" :label="t('recruitment_vacancies.field_title')" variant="outlined" density="comfortable" :placeholder="t('recruitment_vacancies.field_title_placeholder')" :error-messages="form.errors.title" hide-details="auto" /></VCol>
              <VCol cols="12" sm="6"><VTextField v-model="form.department" :label="t('recruitment_vacancies.field_department')" variant="outlined" density="comfortable" :placeholder="t('recruitment_vacancies.field_department_placeholder')" :error-messages="form.errors.department" hide-details="auto" /></VCol>
              <VCol cols="12" sm="6"><VSelect v-model="form.job_type" :items="jobTypes" item-title="label" item-value="value" :label="t('recruitment_vacancies.field_job_type')" variant="outlined" density="comfortable" :error-messages="form.errors.job_type" hide-details="auto" /></VCol>
              <VCol cols="12" sm="6"><VTextField v-model.number="form.headcount" :label="t('recruitment_vacancies.field_headcount')" type="number" variant="outlined" density="comfortable" :min="1" :max="50" :error-messages="form.errors.headcount" hide-details="auto" /></VCol>
              <VCol cols="12" sm="6"><VAutocomplete v-model="form.requisition_id" :items="requisitionItems" item-title="title" item-value="value" :label="t('recruitment_vacancies.field_requisition')" variant="outlined" density="comfortable" clearable :error-messages="form.errors.requisition_id" hide-details="auto" /></VCol>
              <VCol cols="12">
                <p class="text-body-2 font-weight-medium mb-3">{{ t('recruitment_vacancies.field_visibility') }}</p>
                <VRow dense>
                  <VCol v-for="opt in visibilityOptions" :key="opt.value" cols="12" sm="4">
                    <div class="pa-4 rounded-lg cursor-pointer" style="border: 2px solid; transition: border-color 0.2s, box-shadow 0.2s;" :style="{ borderColor: form.visibility === opt.value ? `rgb(var(--v-theme-${opt.color}))` : 'rgba(0,0,0,0.12)', boxShadow: form.visibility === opt.value ? `0 0 0 3px rgba(var(--v-theme-${opt.color}), 0.15)` : 'none', background: form.visibility === opt.value ? `rgba(var(--v-theme-${opt.color}), 0.06)` : 'transparent' }" @click="form.visibility = opt.value">
                      <div class="d-flex align-center gap-2 mb-1"><VIcon :icon="visibilityIcons[opt.value]" :color="form.visibility === opt.value ? opt.color : 'default'" size="20" /><span class="text-body-2 font-weight-semibold">{{ opt.label }}</span></div>
                      <p class="text-caption text-medium-emphasis mb-0">{{ opt.description }}</p>
                    </div>
                  </VCol>
                </VRow>
                <VAlert v-if="form.visibility === 'internal_only'" type="info" variant="tonal" density="compact" class="mt-3 text-caption" icon="tabler-users">{{ t('recruitment_vacancies.visibility_internal_alert') }}</VAlert>
                <VAlert v-else-if="form.visibility === 'external_only'" type="info" variant="tonal" density="compact" class="mt-3 text-caption" icon="tabler-world">{{ t('recruitment_vacancies.visibility_external_alert') }}</VAlert>
                <VAlert v-else-if="form.visibility === 'internal_first_then_external'" type="warning" variant="tonal" density="compact" class="mt-3 text-caption" icon="tabler-user-share">{{ t('recruitment_vacancies.visibility_internal_first_alert') }}</VAlert>
                <div v-if="form.visibility === 'internal_first_then_external'" class="mt-4"><VTextField v-model="form.internal_deadline" :label="t('recruitment_vacancies.field_internal_deadline')" type="date" variant="outlined" density="comfortable" :hint="t('recruitment_vacancies.field_internal_deadline_hint')" persistent-hint :error-messages="form.errors.internal_deadline" /></div>
              </VCol>
            </VRow>
          </VCardText>
        </VCard>
        <VCard elevation="0" border class="mb-5" style="border-radius: 12px;">
          <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold"><VIcon icon="tabler-file-text" size="18" color="primary" class="me-2" />{{ t('recruitment_vacancies.section_description') }}</VCardTitle>
          <VCardText class="pa-5 pt-2">
            <VRow>
              <VCol cols="12"><VTextarea v-model="form.job_description" :label="t('recruitment_vacancies.field_job_description')" variant="outlined" density="comfortable" rows="8" :placeholder="t('recruitment_vacancies.field_job_description_placeholder')" :error-messages="form.errors.job_description" hide-details="auto" /></VCol>
              <VCol cols="12"><VTextarea v-model="form.required_qualifications" :label="t('recruitment_vacancies.field_qualifications')" variant="outlined" density="comfortable" rows="5" :placeholder="t('recruitment_vacancies.field_qualifications_placeholder')" :error-messages="form.errors.required_qualifications" hide-details="auto" /></VCol>
            </VRow>
          </VCardText>
        </VCard>
        <VCard elevation="0" border class="mb-5" style="border-radius: 12px;">
          <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold"><VIcon icon="tabler-tags" size="18" color="primary" class="me-2" />{{ t('recruitment_vacancies.section_skills') }}</VCardTitle>
          <VCardText class="pa-5 pt-2">
            <VCombobox v-model="form.required_skills" :label="t('recruitment_vacancies.section_skills')" variant="outlined" density="comfortable" multiple chips closable-chips hide-selected :placeholder="t('recruitment_vacancies.field_skills_placeholder')" :error-messages="form.errors.required_skills" hide-details="auto" />
            <p class="text-caption text-medium-emphasis mt-2 mb-0">{{ t('recruitment_vacancies.field_skills_hint') }}</p>
          </VCardText>
        </VCard>
        <VCard elevation="0" border class="mb-6" style="border-radius: 12px;">
          <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold"><VIcon icon="tabler-currency-dollar" size="18" color="primary" class="me-2" />{{ t('recruitment_vacancies.section_compensation') }}</VCardTitle>
          <VCardText class="pa-5 pt-2">
            <VRow>
              <VCol cols="12" sm="6"><VTextField v-model.number="form.salary_band_min" :label="t('recruitment_vacancies.field_salary_min')" type="number" variant="outlined" density="comfortable" prefix="R" :min="0" :error-messages="form.errors.salary_band_min" hide-details="auto" placeholder="0" /></VCol>
              <VCol cols="12" sm="6"><VTextField v-model.number="form.salary_band_max" :label="t('recruitment_vacancies.field_salary_max')" type="number" variant="outlined" density="comfortable" prefix="R" :min="0" :error-messages="form.errors.salary_band_max" hide-details="auto" placeholder="0" /></VCol>
              <VCol cols="12" sm="6"><VTextField v-model="form.expires_at" :label="t('recruitment_vacancies.field_expires_at')" type="date" variant="outlined" density="comfortable" :error-messages="form.errors.expires_at" hide-details="auto" /></VCol>
            </VRow>
          </VCardText>
        </VCard>
        <div class="d-flex align-center justify-end gap-3">
          <Link :href="route('recruitment.vacancies.index')"><VBtn variant="text" color="default" :disabled="form.processing">{{ t('recruitment_vacancies.cancel') }}</VBtn></Link>
          <VBtn color="primary" :loading="form.processing" :disabled="!form.title || !form.job_description" @click="submit"><VIcon icon="tabler-device-floppy" size="18" class="me-2" />{{ t('recruitment_vacancies.save_draft') }}</VBtn>
        </div>
      </VCol>
      <VCol cols="12" lg="4">
        <div style="position: sticky; top: 80px;">
          <VCard elevation="0" border style="border-radius: 12px;">
            <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">{{ t('recruitment_vacancies.sidebar_summary') }}</VCardTitle>
            <VCardText class="pa-5 pt-0">
              <div class="d-flex flex-column gap-3">
                <div class="d-flex align-center justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('recruitment_vacancies.sidebar_title') }}</span><span class="text-body-2 font-weight-medium text-end" style="max-width: 180px;">{{ form.title || '—' }}</span></div><VDivider />
                <div class="d-flex align-center justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('recruitment_vacancies.sidebar_department') }}</span><span class="text-body-2">{{ form.department || '—' }}</span></div><VDivider />
                <div class="d-flex align-center justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('recruitment_vacancies.sidebar_visibility') }}</span><VChip v-if="selectedVisibility" :color="selectedVisibility.color" size="small" variant="tonal">{{ selectedVisibility.label }}</VChip><span v-else class="text-body-2">—</span></div><VDivider />
                <div class="d-flex align-center justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('recruitment_vacancies.sidebar_headcount') }}</span><div class="d-flex align-center gap-1"><VIcon icon="tabler-users" size="14" class="text-medium-emphasis" /><span class="text-body-2 font-weight-medium">{{ form.headcount }}</span></div></div><VDivider />
                <div class="d-flex align-center justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('recruitment_vacancies.section_skills') }}</span><span class="text-body-2">{{ form.required_skills.length }} {{ t('recruitment_vacancies.sidebar_skills_added') }}</span></div>
                <div v-if="form.salary_band_min || form.salary_band_max"><VDivider class="mb-3" /><div class="d-flex align-center justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('recruitment_vacancies.sidebar_salary_band') }}</span><span class="text-body-2 font-weight-medium">R{{ form.salary_band_min?.toLocaleString() ?? '0' }} – R{{ form.salary_band_max?.toLocaleString() ?? '0' }}</span></div></div>
              </div>
              <VDivider class="my-4" />
              <VAlert type="info" variant="tonal" density="compact" class="text-caption">{{ t('recruitment_vacancies.sidebar_draft_info') }}</VAlert>
            </VCardText>
          </VCard>
        </div>
      </VCol>
    </VRow>
  </div>
</template>
