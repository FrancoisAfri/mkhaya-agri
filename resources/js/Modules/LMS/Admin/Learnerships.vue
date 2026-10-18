<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Learnership = {
  id: number; uuid: string; title: string; saqa_id: string | null
  seta: { acronym: string } | null; seta_id: number | null
  aqp: { acronym: string } | null; aqp_id: number | null
  nqf_level: number | null; total_credits: number; duration_months: number | null
  learnership_type: string; ofo_code: string | null; seta_programme_code: string | null
  k_course_id: number | null; p_course_id: number | null
  knowledge_course: { title: string } | null; practical_course: { title: string } | null
  we_hours_required: number; stipend_per_month: number | null
  is_active: boolean; description: string | null
  enrolments_count: number
}

const props = defineProps<{
  learnerships: Learnership[]
  setas: { id: number; name: string; acronym: string }[]
  courses: { id: number; title: string; nqf_level: number | null; credits: number | null; is_published: boolean }[]
  aqps: { id: number; name: string; acronym: string }[]
}>()

const confirmDialog = ref()
const dialog  = ref(false)
const editing = ref<Learnership | null>(null)

const form = useForm({
  title: '', saqa_id: '', seta_id: null as number | null,
  aqp_id: null as number | null,
  nqf_level: null as number | null, total_credits: 0, duration_months: null as number | null,
  learnership_type: 'learnership', ofo_code: '', seta_programme_code: '',
  k_course_id: null as number | null, p_course_id: null as number | null,
  we_hours_required: 0, stipend_per_month: null as number | null,
  description: '', is_active: true,
})

const typeItems = computed(() =>  [
  { title: t('lms_learnerships.dialog.type_learnership'), value: 'learnership' },
  { title: t('lms_learnerships.dialog.type_apprenticeship'), value: 'apprenticeship' },
  { title: t('lms_learnerships.dialog.type_skills_programme'), value: 'skills_programme' },
  { title: t('lms_learnerships.dialog.type_internship'), value: 'internship' },
])

const nqfItems = [1,2,3,4,5,6,7,8,9,10].map(n => ({ title: `NQF ${n}`, value: n }))

const typeColor: Record<string, string> = {
  learnership: 'primary', apprenticeship: 'warning', skills_programme: 'success', internship: 'info',
}

function openNew() { editing.value = null; form.reset(); form.learnership_type = 'learnership'; form.is_active = true; dialog.value = true }
function openEdit(l: Learnership) {
  editing.value = l
  Object.assign(form, {
    title: l.title, saqa_id: l.saqa_id ?? '', seta_id: l.seta_id,
    aqp_id: l.aqp_id,
    nqf_level: l.nqf_level, total_credits: l.total_credits, duration_months: l.duration_months,
    learnership_type: l.learnership_type, ofo_code: l.ofo_code ?? '',
    seta_programme_code: l.seta_programme_code ?? '',
    k_course_id: l.k_course_id, p_course_id: l.p_course_id,
    we_hours_required: l.we_hours_required, stipend_per_month: l.stipend_per_month,
    description: l.description ?? '', is_active: l.is_active,
  })
  dialog.value = true
}

function save() {
  if (editing.value) { form.patch(route('lms.admin.qcto.learnerships.update', { learnership: editing.value.id }), { onSuccess: () => { dialog.value = false } }) }
  else { form.post(route('lms.admin.qcto.learnerships.store'), { onSuccess: () => { dialog.value = false } }) }
}

function destroy(l: Learnership) {
  confirmDialog.value.open(
    { title: t('lms_learnerships.dialog.delete_title'), message: t('lms_learnerships.dialog.delete_message', { title: l.title }), confirmText: t('lms_learnerships.dialog.delete_confirm'), confirmColor: 'error', icon: 'tabler-trash' },
    () => { useForm({}).delete(route('lms.admin.qcto.learnerships.destroy', { learnership: l.id })) },
  )
}
</script>

<template>
  <div class="pa-5">
    <ConfirmDialog ref="confirmDialog" />
    <div class="d-flex align-center gap-3 mb-5">
      <div class="flex-1-1">
        <h4 class="text-h4 mb-0">{{ t('lms_learnerships.title') }}</h4>
        <p class="text-caption text-medium-emphasis mb-0">{{ t('lms_learnerships.subtitle') }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" @click="openNew">{{ t('lms_learnerships.add_programme') }}</VBtn>
    </div>

    <VRow>
      <VCol v-for="l in learnerships" :key="l.id" cols="12" md="6" lg="4">
        <VCard rounded="lg" elevation="0" border>
          <VCardText class="pa-4">
            <div class="d-flex align-start gap-3 mb-3">
              <VAvatar :color="typeColor[l.learnership_type]" variant="tonal" size="42" rounded><VIcon icon="tabler-school" size="22" /></VAvatar>
              <div class="flex-1-1 min-width-0">
                <p class="text-body-1 font-weight-semibold mb-0 text-truncate">{{ l.title }}</p>
                <p class="text-caption text-medium-emphasis mb-0">
                  {{ l.seta?.acronym ?? t('lms_learnerships.card.no_seta') }}
                  <span v-if="l.aqp"> · {{ t('lms_learnerships.card.aqp_label') }} {{ l.aqp.acronym }}</span>
                  <span v-if="l.saqa_id"> · {{ t('lms_learnerships.card.saqa_label') }} {{ l.saqa_id }}</span>
                  <span v-if="l.ofo_code"> · {{ t('lms_learnerships.card.ofo_label') }} {{ l.ofo_code }}</span>
                </p>
              </div>
              <VChip :color="l.is_active ? 'success' : 'default'" size="x-small" variant="tonal">
                {{ l.is_active ? t('lms_learnerships.card.active') : t('lms_learnerships.card.inactive') }}
              </VChip>
            </div>
            <div class="d-flex flex-wrap gap-2 mb-3">
              <VChip :color="typeColor[l.learnership_type]" size="x-small" variant="tonal" class="text-capitalize">{{ l.learnership_type.replace('_', ' ') }}</VChip>
              <VChip v-if="l.nqf_level" size="x-small" variant="outlined">{{ t('lms_learnerships.card.nqf_level', { level: l.nqf_level }) }}</VChip>
              <VChip v-if="l.total_credits" size="x-small" variant="outlined">{{ t('lms_learnerships.card.credits', { count: l.total_credits }) }}</VChip>
              <VChip v-if="l.duration_months" size="x-small" variant="outlined">{{ t('lms_learnerships.card.months', { count: l.duration_months }) }}</VChip>
            </div>
            <div class="d-flex flex-column gap-1 mb-3">
              <div class="d-flex align-center gap-2"><VChip color="info" size="x-small" variant="tonal">{{ t('lms_learnerships.card.k_label') }}</VChip><span class="text-caption">{{ l.knowledge_course?.title ?? t('lms_learnerships.card.not_assigned') }}</span></div>
              <div class="d-flex align-center gap-2"><VChip color="success" size="x-small" variant="tonal">{{ t('lms_learnerships.card.p_label') }}</VChip><span class="text-caption">{{ l.practical_course?.title ?? t('lms_learnerships.card.not_assigned') }}</span></div>
              <div class="d-flex align-center gap-2"><VChip color="warning" size="x-small" variant="tonal">{{ t('lms_learnerships.card.we_label') }}</VChip><span class="text-caption">{{ t('lms_learnerships.card.hours_required', { hours: l.we_hours_required }) }}</span></div>
            </div>
            <div class="d-flex align-center justify-space-between">
              <span class="text-caption text-medium-emphasis">{{ t('lms_learnerships.card.enrolments_count', { count: l.enrolments_count }) }}</span>
              <div class="d-flex gap-1">
                <VBtn size="x-small" variant="tonal" color="primary" @click="router.visit(route('lms.admin.qcto.learnerships.enrolments', { learnership: l.id }))">{{ t('lms_learnerships.card.enrolments_button') }}</VBtn>
                <VBtn icon="tabler-edit" size="x-small" variant="text" @click="openEdit(l)" />
                <VBtn icon="tabler-trash" size="x-small" variant="text" color="error" @click="destroy(l)" />
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol v-if="!learnerships.length" cols="12">
        <VCard rounded="lg" elevation="0" border><VCardText class="text-center pa-8 text-medium-emphasis">{{ t('lms_learnerships.card.empty') }}</VCardText></VCard>
      </VCol>
    </VRow>

    <VDialog v-model="dialog" max-width="680">
      <VCard rounded="lg">
        <VCardTitle class="pa-5 pb-0">{{ editing ? t('lms_learnerships.dialog.title_edit') : t('lms_learnerships.dialog.title_new') }}</VCardTitle>
        <VCardText class="pa-5">
          <VRow dense>
            <VCol cols="12"><VTextField v-model="form.title" :label="t('lms_learnerships.dialog.programme_title')" :error-messages="form.errors.title" /></VCol>
            <VCol cols="6"><VSelect v-model="form.learnership_type" :label="t('lms_learnerships.dialog.programme_type')" :items="typeItems" /></VCol>
            <VCol cols="6"><VSelect v-model="form.seta_id" :label="t('lms_learnerships.dialog.seta_label')" :items="setas.map(s => ({title: s.acronym, value: s.id}))" clearable /></VCol>
            <VCol cols="6"><VSelect v-model="form.aqp_id" :label="t('lms_learnerships.dialog.aqp_label')" :items="aqps.map(a => ({title: a.acronym + ' — ' + a.name, value: a.id}))" clearable :hint="aqps.length === 0 ? t('lms_learnerships.dialog.aqp_hint') : ''" persistent-hint /></VCol>
            <VCol cols="4"><VTextField v-model="form.saqa_id" :label="t('lms_learnerships.dialog.saqa_id_label')" /></VCol>
            <VCol cols="4"><VTextField v-model="form.ofo_code" :label="t('lms_learnerships.dialog.ofo_code_label')" :hint="t('lms_learnerships.dialog.ofo_hint')" persistent-hint /></VCol>
            <VCol cols="4"><VTextField v-model="form.seta_programme_code" :label="t('lms_learnerships.dialog.seta_programme_code_label')" /></VCol>
            <VCol cols="4"><VSelect v-model="form.nqf_level" :label="t('lms_learnerships.dialog.nqf_level_label')" :items="nqfItems" clearable /></VCol>
            <VCol cols="4"><VTextField v-model="form.total_credits" :label="t('lms_learnerships.dialog.total_credits_label')" type="number" min="0" /></VCol>
            <VCol cols="4"><VTextField v-model="form.duration_months" :label="t('lms_learnerships.dialog.duration_label')" type="number" min="1" /></VCol>
            <VCol cols="12" class="text-caption font-weight-semibold text-medium-emphasis mt-2">{{ t('lms_learnerships.dialog.components_title') }}</VCol>
            <VCol cols="6"><VSelect v-model="form.k_course_id" :label="t('lms_learnerships.dialog.k_course_label')" :items="courses.map(c => ({ title: c.title + (c.is_published ? '' : ' ' + t('lms_learnerships.dialog.draft_suffix')), value: c.id }))" clearable :hint="courses.length === 0 ? t('lms_learnerships.dialog.course_hint') : ''" persistent-hint /></VCol>
            <VCol cols="6"><VSelect v-model="form.p_course_id" :label="t('lms_learnerships.dialog.p_course_label')" :items="courses.map(c => ({ title: c.title + (c.is_published ? '' : ' ' + t('lms_learnerships.dialog.draft_suffix')), value: c.id }))" clearable :hint="courses.length === 0 ? t('lms_learnerships.dialog.course_hint') : ''" persistent-hint /></VCol>
            <VCol cols="6"><VTextField v-model="form.we_hours_required" :label="t('lms_learnerships.dialog.we_hours_label')" type="number" min="0" :hint="t('lms_learnerships.dialog.we_hours_hint')" persistent-hint /></VCol>
            <VCol cols="6"><VTextField v-model="form.stipend_per_month" :label="t('lms_learnerships.dialog.stipend_label')" type="number" min="0" /></VCol>
            <VCol cols="12"><VTextarea v-model="form.description" :label="t('lms_learnerships.dialog.description_label')" rows="2" auto-grow /></VCol>
            <VCol cols="12"><VSwitch v-model="form.is_active" :label="t('lms_learnerships.dialog.active_label')" color="success" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2 justify-end">
          <VBtn variant="outlined" @click="dialog = false">{{ t('lms_learnerships.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="form.processing" @click="save">{{ t('lms_learnerships.dialog.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
