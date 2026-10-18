<script setup lang="ts">
import { computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import AppTextField from '@/@core/components/app-form-elements/AppTextField.vue'
import AppTextarea from '@/@core/components/app-form-elements/AppTextarea.vue'

const { t } = useI18n()
const route = ziggyRoute

const form = useForm({
  title: '', location: '', employment_type: 'full_time' as string,
  description: '', requirements: '', responsibilities: '',
  currency: 'ZAR', salary_min: null as number | null, salary_max: null as number | null,
})

const employmentTypes = computed(() => [
  { title: t('recruitment_jobs.employment_full_time'), value: 'full_time' },
  { title: t('recruitment_jobs.employment_part_time'), value: 'part_time' },
  { title: t('recruitment_jobs.employment_contract'), value: 'contract' },
  { title: t('recruitment_jobs.employment_internship'), value: 'internship' },
])

const selectedType = computed(() => employmentTypes.value.find(et => et.value === form.employment_type)?.title ?? '—')

function submit() { form.post(route('recruitment.jobs.store')) }
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center gap-2 mb-6">
      <VBtn icon="tabler-arrow-left" variant="text" size="small" @click="router.visit(route('recruitment.jobs.index'))" />
      <div>
        <h4 class="text-h4 font-weight-bold mb-0">{{ t('recruitment_jobs.title_new') }}</h4>
        <p class="text-medium-emphasis text-body-2 mb-0">{{ t('recruitment_jobs.subtitle_new') }}</p>
      </div>
    </div>
    <VForm @submit.prevent="submit">
      <VRow>
        <VCol cols="12" lg="8">
          <VCard elevation="0" border class="mb-4">
            <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">{{ t('recruitment_jobs.section_position_details') }}</VCardTitle>
            <VCardText class="pa-5 pt-0">
              <AppTextField v-model="form.title" :label="t('recruitment_jobs.field_job_title')" :placeholder="t('recruitment_jobs.field_job_title_placeholder')" :error-messages="form.errors.title" class="mb-4" />
              <VRow>
                <VCol cols="12" sm="6"><AppTextField v-model="form.location" :label="t('recruitment_jobs.field_location')" :placeholder="t('recruitment_jobs.field_location_placeholder')" :error-messages="form.errors.location" /></VCol>
                <VCol cols="12" sm="6"><VSelect v-model="form.employment_type" :items="employmentTypes" item-title="title" item-value="value" :label="t('recruitment_jobs.field_employment_type')" variant="outlined" density="comfortable" :error-messages="form.errors.employment_type" hide-details="auto" /></VCol>
              </VRow>
            </VCardText>
          </VCard>
          <VCard elevation="0" border class="mb-4">
            <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">{{ t('recruitment_jobs.section_job_content') }}</VCardTitle>
            <VCardText class="pa-5 pt-0">
              <AppTextarea v-model="form.description" :label="t('recruitment_jobs.field_description')" :placeholder="t('recruitment_jobs.field_description_placeholder')" :rows="6" :error-messages="form.errors.description" class="mb-4" />
              <AppTextarea v-model="form.requirements" :label="t('recruitment_jobs.field_requirements')" :placeholder="t('recruitment_jobs.field_requirements_placeholder')" :rows="4" :error-messages="form.errors.requirements" class="mb-4" />
              <AppTextarea v-model="form.responsibilities" :label="t('recruitment_jobs.field_responsibilities')" :placeholder="t('recruitment_jobs.field_responsibilities_placeholder')" :rows="4" :error-messages="form.errors.responsibilities" />
            </VCardText>
          </VCard>
          <VCard elevation="0" border>
            <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">{{ t('recruitment_jobs.section_salary') }}</VCardTitle>
            <VCardText class="pa-5 pt-0">
              <VRow>
                <VCol cols="12" sm="4"><AppTextField v-model="form.currency" :label="t('recruitment_jobs.field_currency')" :placeholder="t('recruitment_jobs.field_currency_placeholder')" :error-messages="form.errors.currency" /></VCol>
                <VCol cols="12" sm="4"><AppTextField v-model.number="form.salary_min" :label="t('recruitment_jobs.field_salary_min')" type="number" placeholder="0" :error-messages="form.errors.salary_min" /></VCol>
                <VCol cols="12" sm="4"><AppTextField v-model.number="form.salary_max" :label="t('recruitment_jobs.field_salary_max')" type="number" placeholder="0" :error-messages="form.errors.salary_max" /></VCol>
              </VRow>
            </VCardText>
          </VCard>
        </VCol>
        <VCol cols="12" lg="4">
          <VCard elevation="0" border class="mb-4">
            <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">{{ t('recruitment_jobs.sidebar_create_title') }}</VCardTitle>
            <VCardText class="pa-5 pt-0 d-flex flex-column gap-3">
              <p class="text-body-2 text-medium-emphasis mb-0">{{ t('recruitment_jobs.sidebar_create_desc') }}</p>
              <VBtn block color="primary" size="large" :loading="form.processing" :disabled="!form.title.trim() || !form.description.trim()" prepend-icon="tabler-briefcase-2" type="submit">{{ t('recruitment_jobs.create_position_btn') }}</VBtn>
              <VBtn block variant="text" :disabled="form.processing" @click="router.visit(route('recruitment.jobs.index'))">{{ t('recruitment_jobs.cancel') }}</VBtn>
            </VCardText>
          </VCard>
          <VCard elevation="0" border>
            <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">{{ t('recruitment_jobs.sidebar_summary') }}</VCardTitle>
            <VCardText class="pa-5 pt-0">
              <div class="d-flex flex-column gap-3">
                <div class="d-flex justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('recruitment_jobs.summary_title') }}</span><span class="text-body-2 text-end" style="max-width: 65%;">{{ form.title || '—' }}</span></div>
                <div class="d-flex justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('recruitment_jobs.summary_location') }}</span><span class="text-body-2">{{ form.location || '—' }}</span></div>
                <div class="d-flex justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('recruitment_jobs.summary_type') }}</span><span class="text-body-2">{{ selectedType }}</span></div>
                <div class="d-flex justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('recruitment_jobs.summary_currency') }}</span><span class="text-body-2">{{ form.currency || '—' }}</span></div>
                <div class="d-flex justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('recruitment_jobs.summary_salary') }}</span><span class="text-body-2">{{ form.salary_min || form.salary_max ? `${form.salary_min ?? 0} – ${form.salary_max ?? '?'}` : '—' }}</span></div>
              </div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </VForm>
  </div>
</template>
