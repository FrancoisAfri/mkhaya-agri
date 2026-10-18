<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  vacancy: { id: number; uuid: string; vacancy_number: string; title: string; department: string | null; visibility: { value: string; label: string; color: string; description: string }; status: { value: string; label: string; color: string }; job_type: { value: string; label: string }; headcount: number; job_description: string; required_qualifications: string | null; required_skills: string[] | null; salary_band_min: number | null; salary_band_max: number | null; internal_deadline: string | null; expires_at: string | null; public_application_url: string | null; published_internally_at: string | null; published_externally_at: string | null; transitioned_to_external_at: string | null; closed_at: string | null; created_at: string; can_publish: boolean; can_close: boolean; can_transition: boolean; requisition: { requisition_number: string; title: string } | null }
}>()

const showPublishDialog = ref(false); const showCloseConfirm = ref(false); const showTransitionConfirm = ref(false); const linkCopied = ref(false)
const publishForm = useForm({ internal_deadline: props.vacancy.internal_deadline ?? '' })

function formatDate(val: string | null) { if (!val) return null; return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' }) }
function formatSalary(val: number | null) { if (val == null) return null; return `R ${val.toLocaleString('en-ZA')}` }
const salaryRange = computed(() => { const { salary_band_min: min, salary_band_max: max } = props.vacancy; if (min && max) return `${formatSalary(min)} – ${formatSalary(max)}`; if (min) return `From ${formatSalary(min)}`; if (max) return `Up to ${formatSalary(max)}`; return null })
const visibilityIcon: Record<string, string> = { internal_only: 'tabler-users', external_only: 'tabler-world', internal_first_then_external: 'tabler-user-share' }

const timeline = computed(() => [
  { label: t('recruitment_vacancies.detail_timeline_created'), date: formatDate(props.vacancy.created_at), icon: 'tabler-circle-plus', color: 'default', show: true },
  { label: t('recruitment_vacancies.detail_timeline_published_internal'), date: formatDate(props.vacancy.published_internally_at), icon: 'tabler-users', color: 'info', show: !!props.vacancy.published_internally_at },
  { label: t('recruitment_vacancies.detail_timeline_published_external'), date: formatDate(props.vacancy.published_externally_at), icon: 'tabler-world', color: 'primary', show: !!props.vacancy.published_externally_at },
  { label: t('recruitment_vacancies.detail_timeline_transitioned'), date: formatDate(props.vacancy.transitioned_to_external_at), icon: 'tabler-user-share', color: 'warning', show: !!props.vacancy.transitioned_to_external_at },
  { label: t('recruitment_vacancies.detail_timeline_closed'), date: formatDate(props.vacancy.closed_at), icon: 'tabler-lock', color: 'error', show: !!props.vacancy.closed_at },
])

function publish() { publishForm.post(route('recruitment.vacancies.publish', props.vacancy.uuid), { onSuccess: () => { showPublishDialog.value = false } }) }
function closeVacancy() { router.post(route('recruitment.vacancies.close', props.vacancy.uuid), {}, { onSuccess: () => { showCloseConfirm.value = false } }) }
function transitionToExternal() { router.post(route('recruitment.vacancies.transition', props.vacancy.uuid), {}, { onSuccess: () => { showTransitionConfirm.value = false } }) }
function copyLink() { if (props.vacancy.public_application_url) { navigator.clipboard.writeText(props.vacancy.public_application_url); linkCopied.value = true; setTimeout(() => { linkCopied.value = false }, 2000) } }
const needsInternalDeadline = computed(() => props.vacancy.visibility.value === 'internal_first_then_external' && !props.vacancy.internal_deadline)
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center gap-2 mb-2">
      <VBtn icon="tabler-arrow-left" variant="text" size="small" @click="router.visit(route('recruitment.vacancies.index'))" />
      <div>
        <span class="text-medium-emphasis text-body-2 cursor-pointer" @click="router.visit(route('recruitment.vacancies.index'))">{{ t('recruitment_vacancies.breadcrumb_vacancies') }}</span>
        <VIcon icon="tabler-chevron-right" size="16" class="text-medium-emphasis mx-1" />
        <span class="text-body-2 font-weight-medium">{{ vacancy.vacancy_number }}</span>
      </div>
    </div>
    <div class="d-flex align-start justify-space-between flex-wrap gap-4 mb-6">
      <div><div class="d-flex align-center gap-2 flex-wrap mb-1"><h4 style="font-size: 1.5rem; font-weight: 700; color: #0f172a;">{{ vacancy.title }}</h4><VChip :color="vacancy.status.color" :text="vacancy.status.label" size="small" variant="tonal" /><VChip :color="vacancy.visibility.color" size="small" variant="tonal"><VIcon :icon="visibilityIcon[vacancy.visibility.value]" size="14" class="me-1" />{{ vacancy.visibility.label }}</VChip></div><p class="text-medium-emphasis mb-0 text-body-2">{{ vacancy.vacancy_number }}<span v-if="vacancy.department"> · {{ vacancy.department }}</span></p></div>
      <div class="d-flex align-center gap-2 flex-wrap">
        <VBtn v-if="vacancy.can_publish" color="primary" prepend-icon="tabler-send" @click="showPublishDialog = true">{{ t('recruitment_vacancies.publish') }}</VBtn>
        <VBtn v-if="vacancy.can_transition" color="warning" variant="tonal" prepend-icon="tabler-user-share" @click="showTransitionConfirm = true">{{ t('recruitment_vacancies.open_to_external') }}</VBtn>
        <VBtn v-if="vacancy.can_close" color="error" variant="tonal" prepend-icon="tabler-lock" @click="showCloseConfirm = true">{{ t('recruitment_vacancies.close_vacancy') }}</VBtn>
      </div>
    </div>

    <VAlert v-if="vacancy.public_application_url" type="success" variant="tonal" class="mb-5" icon="tabler-link">
      <div class="d-flex align-center justify-space-between flex-wrap gap-2"><div><p class="font-weight-semibold mb-1">{{ t('recruitment_vacancies.public_link_title') }}</p><p class="text-body-2 mb-0" style="word-break: break-all;">{{ vacancy.public_application_url }}</p></div><VBtn :color="linkCopied ? 'success' : 'default'" variant="tonal" size="small" :prepend-icon="linkCopied ? 'tabler-check' : 'tabler-copy'" @click="copyLink">{{ linkCopied ? t('recruitment_vacancies.copied') : t('recruitment_vacancies.copy_link') }}</VBtn></div>
    </VAlert>

    <VRow>
      <VCol cols="12" lg="8">
        <VCard elevation="0" border class="mb-5" style="border-radius: 12px;"><VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold"><VIcon icon="tabler-briefcase" size="18" color="primary" class="me-2" />{{ t('recruitment_vacancies.detail_job_info') }}</VCardTitle><VCardText class="pa-5 pt-2"><VRow>
          <VCol cols="12" sm="6"><div class="mb-4"><p class="text-caption text-medium-emphasis mb-1">{{ t('recruitment_vacancies.detail_job_type') }}</p><p class="text-body-2 font-weight-medium mb-0">{{ vacancy.job_type.label }}</p></div></VCol>
          <VCol cols="12" sm="6"><div class="mb-4"><p class="text-caption text-medium-emphasis mb-1">{{ t('recruitment_vacancies.detail_headcount') }}</p><p class="text-body-2 font-weight-medium mb-0"><VIcon icon="tabler-users" size="16" class="me-1" />{{ vacancy.headcount }}</p></div></VCol>
          <VCol cols="12" sm="6"><div class="mb-4"><p class="text-caption text-medium-emphasis mb-1">{{ t('recruitment_vacancies.detail_visibility') }}</p><p class="text-body-2 font-weight-medium mb-0">{{ vacancy.visibility.label }}</p><p class="text-caption text-medium-emphasis mb-0">{{ vacancy.visibility.description }}</p></div></VCol>
          <VCol v-if="salaryRange" cols="12" sm="6"><div class="mb-4"><p class="text-caption text-medium-emphasis mb-1">{{ t('recruitment_vacancies.detail_salary_range') }}</p><p class="text-body-2 font-weight-medium mb-0">{{ salaryRange }}</p></div></VCol>
          <VCol v-if="vacancy.expires_at" cols="12" sm="6"><div class="mb-4"><p class="text-caption text-medium-emphasis mb-1">{{ t('recruitment_vacancies.detail_closing_date') }}</p><p class="text-body-2 font-weight-medium mb-0">{{ formatDate(vacancy.expires_at) }}</p></div></VCol>
          <VCol v-if="vacancy.internal_deadline" cols="12" sm="6"><div class="mb-4"><p class="text-caption text-medium-emphasis mb-1">{{ t('recruitment_vacancies.detail_internal_deadline') }}</p><p class="text-body-2 font-weight-medium mb-0">{{ formatDate(vacancy.internal_deadline) }}</p></div></VCol>
        </VRow></VCardText></VCard>
        <VCard elevation="0" border class="mb-5" style="border-radius: 12px;"><VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold"><VIcon icon="tabler-file-text" size="18" color="primary" class="me-2" />{{ t('recruitment_vacancies.detail_description') }}</VCardTitle><VCardText class="pa-5 pt-2"><div class="text-body-2" style="white-space: pre-wrap; line-height: 1.8;">{{ vacancy.job_description }}</div></VCardText></VCard>
        <VCard v-if="vacancy.required_qualifications || (vacancy.required_skills && vacancy.required_skills.length)" elevation="0" border class="mb-5" style="border-radius: 12px;"><VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold"><VIcon icon="tabler-school" size="18" color="primary" class="me-2" />{{ t('recruitment_vacancies.detail_qualifications') }}</VCardTitle><VCardText class="pa-5 pt-2">
          <div v-if="vacancy.required_qualifications" class="mb-4"><p class="text-caption text-medium-emphasis font-weight-medium mb-2 text-uppercase" style="letter-spacing: 0.08em;">{{ t('recruitment_vacancies.detail_qualifications_label') }}</p><div class="text-body-2" style="white-space: pre-wrap; line-height: 1.7;">{{ vacancy.required_qualifications }}</div></div>
          <div v-if="vacancy.required_skills && vacancy.required_skills.length"><p class="text-caption text-medium-emphasis font-weight-medium mb-2 text-uppercase" style="letter-spacing: 0.08em;">{{ t('recruitment_vacancies.detail_skills_label') }}</p><div class="d-flex flex-wrap gap-2"><VChip v-for="skill in vacancy.required_skills" :key="skill" size="small" variant="tonal" color="primary">{{ skill }}</VChip></div></div>
        </VCardText></VCard>
      </VCol>
      <VCol cols="12" lg="4">
        <div style="position: sticky; top: 80px;" class="d-flex flex-column gap-4">
          <VCard elevation="0" border style="border-radius: 12px;"><VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold"><VIcon icon="tabler-timeline" size="18" color="primary" class="me-2" />{{ t('recruitment_vacancies.detail_timeline') }}</VCardTitle><VCardText class="pa-5 pt-2">
            <div v-for="(event, idx) in timeline.filter(e => e.show)" :key="idx" class="d-flex gap-3 mb-4"><div class="d-flex flex-column align-center"><div class="d-flex align-center justify-center rounded-circle flex-shrink-0" :style="{ width: '32px', height: '32px', background: event.color !== 'default' ? `rgba(var(--v-theme-${event.color}), 0.12)` : 'rgba(0,0,0,0.06)' }"><VIcon :icon="event.icon" :color="event.color !== 'default' ? event.color : undefined" size="16" /></div><div v-if="idx < timeline.filter(e => e.show).length - 1" style="width: 2px; flex: 1; min-height: 16px; background: rgba(0,0,0,0.08); margin-top: 4px;" /></div><div class="pb-3"><p class="text-body-2 font-weight-medium mb-0">{{ event.label }}</p><p class="text-caption text-medium-emphasis mb-0">{{ event.date }}</p></div></div>
          </VCardText></VCard>
          <VCard v-if="vacancy.requisition" elevation="0" border style="border-radius: 12px;"><VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold"><VIcon icon="tabler-file-text" size="18" color="primary" class="me-2" />{{ t('recruitment_vacancies.detail_requisition') }}</VCardTitle><VCardText class="pa-5 pt-2"><p class="text-caption text-medium-emphasis mb-1">{{ vacancy.requisition.requisition_number }}</p><p class="text-body-2 font-weight-medium mb-3">{{ vacancy.requisition.title }}</p><VBtn variant="tonal" color="primary" size="small" prepend-icon="tabler-arrow-right" @click="router.visit(route('recruitment.requisitions.index'))">{{ t('recruitment_vacancies.detail_view_requisitions') }}</VBtn></VCardText></VCard>
        </div>
      </VCol>
    </VRow>

    <VDialog v-model="showPublishDialog" max-width="480" persistent><VCard style="border-radius: 12px;"><VCardTitle class="pa-6 pb-2 text-h6 font-weight-semibold"><VIcon icon="tabler-send" color="primary" class="me-2" />{{ t('recruitment_vacancies.dialog_publish_title') }}</VCardTitle><VCardText class="pa-6 pt-2">
      <VAlert :type="vacancy.visibility.color as any" variant="tonal" density="compact" class="mb-4" :icon="visibilityIcon[vacancy.visibility.value]"><strong>{{ vacancy.visibility.label }}</strong> — {{ vacancy.visibility.description }}</VAlert>
      <div v-if="vacancy.visibility.value === 'internal_only'"><p class="text-body-2 mb-0">{{ t('recruitment_vacancies.dialog_publish_internal_only') }}</p></div>
      <div v-else-if="vacancy.visibility.value === 'external_only'"><p class="text-body-2 mb-0">{{ t('recruitment_vacancies.dialog_publish_external_only') }}</p></div>
      <div v-else-if="vacancy.visibility.value === 'internal_first_then_external'"><p class="text-body-2 mb-3">{{ t('recruitment_vacancies.dialog_publish_internal_first') }}</p><VTextField v-if="needsInternalDeadline" v-model="publishForm.internal_deadline" :label="t('recruitment_vacancies.field_internal_deadline')" type="date" variant="outlined" density="comfortable" hint="Applications from employees close on this date." persistent-hint :error-messages="publishForm.errors.internal_deadline" /><div v-else><p class="text-caption text-medium-emphasis mb-0">{{ t('recruitment_vacancies.dialog_publish_deadline_set', { date: formatDate(vacancy.internal_deadline) }) }}</p></div></div>
    </VCardText><VCardActions class="pa-6 pt-2 gap-2 justify-end"><VBtn variant="text" color="default" @click="showPublishDialog = false">{{ t('recruitment_vacancies.cancel') }}</VBtn><VBtn color="primary" :loading="publishForm.processing" :disabled="needsInternalDeadline && !publishForm.internal_deadline" @click="publish"><VIcon icon="tabler-send" size="16" class="me-1" />{{ t('recruitment_vacancies.confirm_publish') }}</VBtn></VCardActions></VCard></VDialog>

    <VDialog v-model="showTransitionConfirm" max-width="420"><VCard style="border-radius: 12px;"><VCardTitle class="pa-6 pb-2 text-h6 font-weight-semibold">{{ t('recruitment_vacancies.dialog_transition_title') }}</VCardTitle><VCardText class="pa-6 pt-2"><p class="text-body-2 mb-0">{{ t('recruitment_vacancies.dialog_transition_text') }}</p></VCardText><VCardActions class="pa-6 pt-2 gap-2 justify-end"><VBtn variant="text" color="default" @click="showTransitionConfirm = false">{{ t('recruitment_vacancies.cancel') }}</VBtn><VBtn color="warning" @click="transitionToExternal"><VIcon icon="tabler-user-share" size="16" class="me-1" />{{ t('recruitment_vacancies.open_to_external') }}</VBtn></VCardActions></VCard></VDialog>

    <VDialog v-model="showCloseConfirm" max-width="420"><VCard style="border-radius: 12px;"><VCardTitle class="pa-6 pb-2 text-h6 font-weight-semibold">{{ t('recruitment_vacancies.dialog_close_title') }}</VCardTitle><VCardText class="pa-6 pt-2"><p class="text-body-2 mb-0">{{ t('recruitment_vacancies.dialog_close_text') }}</p></VCardText><VCardActions class="pa-6 pt-2 gap-2 justify-end"><VBtn variant="text" color="default" @click="showCloseConfirm = false">{{ t('recruitment_vacancies.cancel') }}</VBtn><VBtn color="error" @click="closeVacancy"><VIcon icon="tabler-lock" size="16" class="me-1" />{{ t('recruitment_vacancies.close_vacancy') }}</VBtn></VCardActions></VCard></VDialog>
  </div>
</template>
