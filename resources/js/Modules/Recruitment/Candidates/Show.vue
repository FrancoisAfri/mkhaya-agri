<script setup lang="ts">
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import AppStatusChip from '../partials/AppStatusChip.vue'

const { t } = useI18n()
const route = ziggyRoute

type ApplicationRow = { id: number; job: { id: number; title: string }; status: { value: string; label: string }; created_at: string }
type Skill = { id: number; skill: string; proficiency_level: string | null; years_experience: number | null; ai_extracted: boolean }
type Experience = { id: number; company_name: string; job_title: string; start_date: string | null; end_date: string | null; is_current: boolean; description: string | null; ai_extracted: boolean }
type Qualification = { id: number; institution: string; qualification_type: string; field_of_study: string | null; year_completed: number | null; ai_extracted: boolean }
type Document = { id: number; type: string; original_name: string; mime_type: string; file_size: number | null; parse_status: string | null; parsed_at: string | null; is_clean: boolean; uploaded_at: string }
type AiProfile = { summary: string | null; parse_status: string | null; confidence_score: number | null; strengths: string[]; weaknesses: string[]; flags: Record<string, any>; keywords: string[]; generated_at: string | null; ai_model_used: string | null }

const props = defineProps<{ candidate: { id: number; uuid: string; full_name: string; first_name: string; last_name: string; email: string; phone: string | null; linkedin_url: string | null; is_duplicate: boolean; has_ai_consent: boolean; ai_consent_at: string | null; ai_profile: AiProfile | null; skills: Skill[]; experience: Experience[]; qualifications: Qualification[]; documents: Document[]; created_at: string }; applications: ApplicationRow[] }>()

const tab = ref('applications'); const isDragging = ref(false); const cvFileInput = ref<HTMLInputElement | null>(null)
const consentForm = useForm({}); const cvForm = useForm({ cv: null as File | null }); const reparseForm = useForm({})

const cvDocument = computed(() => props.candidate.documents.find(d => d.type === 'cv') ?? null)

const skillsByProficiency = computed(() => {
  const levels = ['expert', 'advanced', 'intermediate', 'beginner'] as const
  return levels.map(level => ({ level, skills: props.candidate.skills.filter(s => s.proficiency_level === level || (!s.proficiency_level && level === 'beginner')) })).filter(g => g.skills.length > 0)
})

function formatDate(val: string | null): string { if (!val) return '—'; return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' }) }
function formatDateRange(start: string | null, end: string | null, isCurrent: boolean): string { const s = start ? formatDate(start) : '?'; const e = isCurrent ? t('recruitment_candidates.current_chip') : end ? formatDate(end) : '?'; return `${s} – ${e}` }
function formatFileSize(bytes: number | null): string { if (!bytes) return '—'; if (bytes < 1024) return bytes + ' B'; if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'; return (bytes / (1024 * 1024)).toFixed(1) + ' MB' }
function parseStatusColor(status: string | null): string { const map: Record<string, string> = { pending: 'warning', processing: 'info', completed: 'success', failed: 'error' }; return map[status ?? ''] ?? 'default' }
function proficiencyColor(level: string | null): string { const map: Record<string, string> = { expert: 'deep-purple', advanced: 'primary', intermediate: 'success', beginner: 'default' }; return map[level ?? ''] ?? 'default' }
function proficiencyLabel(level: string | null): string { const map: Record<string, string> = { expert: t('recruitment_candidates.proficiency_expert'), advanced: t('recruitment_candidates.proficiency_advanced'), intermediate: t('recruitment_candidates.proficiency_intermediate'), beginner: t('recruitment_candidates.proficiency_beginner') }; return map[level ?? ''] ?? level ?? '' }

function grantConsent() { consentForm.post(route('recruitment.candidates.consent', props.candidate.id)) }
function submitCv(file: File) { cvForm.cv = file; cvForm.post(route('recruitment.candidates.cv.upload', props.candidate.id), { forceFormData: true }) }
function reparse() { reparseForm.post(route('recruitment.candidates.reparse', props.candidate.id)) }
function onDrop(e: DragEvent) { isDragging.value = false; const file = e.dataTransfer?.files[0]; if (file) submitCv(file) }
function onFileChange(e: Event) { const file = (e.target as HTMLInputElement).files?.[0]; if (file) submitCv(file) }

const appHeaders = computed(() => [
  { title: t('recruitment_jobs.table_position'), key: 'job', sortable: false },
  { title: t('recruitment_jobs.table_status'), key: 'status', sortable: false, width: '160px' },
  { title: t('recruitment_jobs.table_applied'), key: 'created_at', sortable: false, width: '140px' },
])

const docHeaders = computed(() => [
  { title: t('recruitment_jobs.table_name'), key: 'original_name', sortable: false },
  { title: t('recruitment_jobs.table_type'), key: 'type', sortable: false, width: '100px' },
  { title: 'Size', key: 'file_size', sortable: false, width: '100px' },
  { title: 'Virus Scan', key: 'is_clean', sortable: false, width: '120px' },
  { title: 'Parse Status', key: 'parse_status', sortable: false, width: '140px' },
  { title: t('recruitment_jobs.table_posted'), key: 'uploaded_at', sortable: false, width: '140px' },
])
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center gap-2 mb-4">
      <VBtn icon="tabler-arrow-left" variant="text" size="small" @click="router.visit(route('recruitment.candidates.index'))" />
      <span class="text-medium-emphasis text-body-2 cursor-pointer" @click="router.visit(route('recruitment.candidates.index'))">{{ t('recruitment_candidates.breadcrumb_recruitment') }}</span>
      <VIcon icon="tabler-chevron-right" size="16" class="text-medium-emphasis" />
      <span class="text-medium-emphasis text-body-2 cursor-pointer" @click="router.visit(route('recruitment.candidates.index'))">{{ t('recruitment_candidates.breadcrumb_candidates') }}</span>
      <VIcon icon="tabler-chevron-right" size="16" class="text-medium-emphasis" />
      <span class="text-body-2 font-weight-medium">{{ candidate.full_name }}</span>
    </div>

    <VAlert v-if="candidate.is_duplicate" type="warning" variant="tonal" border="start" class="mb-5" prepend-icon="tabler-user-exclamation">{{ t('recruitment_candidates.duplicate_warning') }}</VAlert>

    <VRow>
      <VCol cols="12" lg="3">
        <VCard elevation="0" border class="mb-4">
          <VCardText class="pa-5">
            <div class="d-flex flex-column align-center mb-4"><VAvatar size="80" color="primary" variant="tonal" class="mb-3"><span class="text-h4 font-weight-bold">{{ (candidate.full_name ?? '?')[0].toUpperCase() }}</span></VAvatar><p class="text-body-1 font-weight-semibold text-center mb-0">{{ candidate.full_name }}</p><p class="text-body-2 text-medium-emphasis text-center mb-0">{{ candidate.email }}</p></div>
            <VDivider class="mb-3" />
            <div class="d-flex flex-column gap-3">
              <div class="d-flex align-center gap-2"><VIcon icon="tabler-phone" size="16" class="text-medium-emphasis flex-shrink-0" /><span class="text-body-2">{{ candidate.phone ?? '—' }}</span></div>
              <div v-if="candidate.linkedin_url" class="d-flex align-center gap-2"><VIcon icon="tabler-brand-linkedin" size="16" class="text-medium-emphasis flex-shrink-0" /><a :href="candidate.linkedin_url" target="_blank" rel="noopener noreferrer" class="text-body-2 text-primary">{{ t('recruitment_candidates.linkedin_profile') }}</a></div>
              <div class="d-flex align-center gap-2"><VIcon icon="tabler-calendar" size="16" class="text-medium-emphasis flex-shrink-0" /><span class="text-body-2 text-medium-emphasis">{{ t('recruitment_candidates.registered') }} {{ formatDate(candidate.created_at) }}</span></div>
            </div>
          </VCardText>
        </VCard>

        <VCard elevation="0" border class="mb-4">
          <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">{{ t('recruitment_candidates.ai_processing') }}</VCardTitle>
          <VCardText class="pa-5 pt-0">
            <div v-if="candidate.has_ai_consent"><div class="d-flex align-center gap-2 mb-2"><VIcon icon="tabler-robot" size="20" color="info" /><span class="text-body-2 font-weight-medium text-success">{{ t('recruitment_candidates.consent_granted') }}</span></div><p class="text-body-2 text-medium-emphasis mb-0">{{ t('recruitment_candidates.granted_on', { date: formatDate(candidate.ai_consent_at) }) }}</p></div>
            <div v-else><div class="d-flex align-center gap-2 mb-2"><VIcon icon="tabler-robot-off" size="20" color="medium-emphasis" /><span class="text-body-2 font-weight-medium">{{ t('recruitment_candidates.no_consent') }}</span></div><p class="text-body-2 text-medium-emphasis mb-3">{{ t('recruitment_candidates.no_consent_desc') }}</p><VBtn size="small" color="info" variant="tonal" block :loading="consentForm.processing" prepend-icon="tabler-robot" @click="grantConsent">{{ t('recruitment_candidates.grant_consent') }}</VBtn></div>
            <VDivider class="my-3" />
            <VBtn size="small" color="primary" variant="tonal" block prepend-icon="tabler-shield-search" @click="router.visit(route('recruitment.candidates.screening', candidate.id))">View AI Screening</VBtn>
            <p class="text-caption text-medium-emphasis mt-2 mb-0">Public-reputation analysis for human review — not an automated hiring decision.</p>
          </VCardText>
        </VCard>

        <VCard elevation="0" border>
          <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">{{ t('recruitment_candidates.cv_resume') }}</VCardTitle>
          <VCardText class="pa-5 pt-0">
            <div v-if="cvDocument" class="mb-4"><div class="d-flex align-center gap-2 mb-1"><VIcon icon="tabler-file-text" size="18" color="primary" class="flex-shrink-0" /><span class="text-body-2 font-weight-medium text-truncate">{{ cvDocument.original_name }}</span></div><div class="d-flex align-center gap-2 mb-3"><VChip :color="parseStatusColor(cvDocument.parse_status)" variant="tonal" size="x-small" label>{{ cvDocument.parse_status ?? t('recruitment_candidates.not_parsed') }}</VChip><span class="text-caption text-medium-emphasis">{{ formatDate(cvDocument.uploaded_at) }}</span></div><VBtn size="small" color="primary" variant="tonal" block :loading="reparseForm.processing" prepend-icon="tabler-refresh" @click="reparse">{{ t('recruitment_candidates.reparse_cv') }}</VBtn><VDivider class="my-4" /></div>
            <p class="text-caption text-medium-emphasis mb-2">{{ cvDocument ? t('recruitment_candidates.upload_new_cv') : t('recruitment_candidates.no_cv_uploaded') }}</p>
            <div class="upload-dropzone pa-4 text-center rounded-lg" :class="{ dragover: isDragging }" style="border: 2px dashed rgba(var(--v-theme-primary), 0.4); cursor: pointer; min-height: 100px; display: flex; align-items: center; justify-content: center; flex-direction: column;" @dragover.prevent="isDragging = true" @dragleave="isDragging = false" @drop.prevent="onDrop" @click="cvFileInput?.click()"><VIcon icon="tabler-upload" size="28" color="primary" class="mb-2" /><span class="text-body-2 text-medium-emphasis">{{ t('recruitment_candidates.drop_cv_here') }}</span><span class="text-caption text-medium-emphasis">{{ t('recruitment_candidates.cv_formats') }}</span></div>
            <input ref="cvFileInput" type="file" accept=".pdf,.doc,.docx" style="display: none;" @change="onFileChange" />
            <VProgressLinear v-if="cvForm.processing" indeterminate color="primary" class="mt-3 rounded" />
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" lg="9">
        <VTabs v-model="tab" class="mb-4">
          <VTab value="applications"><VIcon icon="tabler-briefcase" size="18" class="mr-1" />{{ t('recruitment_candidates.tab_applications', { count: applications.length }) }}</VTab>
          <VTab value="ai_profile"><VIcon icon="tabler-robot" size="18" class="mr-1" />{{ t('recruitment_candidates.tab_ai_profile') }}</VTab>
          <VTab value="experience"><VIcon icon="tabler-briefcase" size="18" class="mr-1" />{{ t('recruitment_candidates.tab_experience', { count: candidate.experience.length }) }}</VTab>
          <VTab value="skills"><VIcon icon="tabler-star" size="18" class="mr-1" />{{ t('recruitment_candidates.tab_skills', { count: candidate.skills.length }) }}</VTab>
          <VTab value="qualifications"><VIcon icon="tabler-school" size="18" class="mr-1" />{{ t('recruitment_candidates.tab_qualifications', { count: candidate.qualifications.length }) }}</VTab>
          <VTab value="documents"><VIcon icon="tabler-files" size="18" class="mr-1" />{{ t('recruitment_candidates.tab_documents', { count: candidate.documents.length }) }}</VTab>
        </VTabs>

        <VWindow v-model="tab">
          <VWindowItem value="applications">
            <VCard elevation="0" border>
              <VDataTable :headers="appHeaders" :items="applications" item-value="id" hide-default-footer hover @click:row="(_evt: unknown, row: any) => router.visit(route('recruitment.jobs.applications.show', [row.item.job.id, row.item.id]))">
                <template #item.job="{ item }"><span class="text-body-2 font-weight-medium text-primary">{{ item.job.title }}</span></template>
                <template #item.status="{ item }"><AppStatusChip :status="item.status" /></template>
                <template #item.created_at="{ item }"><span class="text-body-2 text-medium-emphasis">{{ formatDate(item.created_at) }}</span></template>
                <template #no-data><div class="text-center py-10"><VIcon icon="tabler-briefcase" size="56" color="medium-emphasis" class="mb-3" /><p class="text-body-2 text-medium-emphasis mb-0">{{ t('recruitment_candidates.no_applications') }}</p></div></template>
              </VDataTable>
            </VCard>
          </VWindowItem>

          <VWindowItem value="ai_profile">
            <VCard elevation="0" border><VCardText class="pa-5">
              <div v-if="!candidate.ai_profile || !candidate.ai_profile.summary" class="text-center py-10"><VIcon icon="tabler-robot" size="64" color="medium-emphasis" class="mb-4" /><p class="text-body-1 font-weight-medium mb-1">{{ t('recruitment_candidates.no_ai_analysis') }}</p><p class="text-body-2 text-medium-emphasis mb-0">{{ t('recruitment_candidates.no_ai_analysis_sub') }}</p></div>
              <template v-else>
                <div class="d-flex align-center gap-3 mb-4 flex-wrap"><VChip :color="parseStatusColor(candidate.ai_profile.parse_status)" variant="tonal" size="small" label prepend-icon="tabler-robot">{{ candidate.ai_profile.parse_status ?? t('recruitment_candidates.unknown') }}</VChip><span v-if="candidate.ai_profile.ai_model_used" class="text-caption text-medium-emphasis">{{ t('recruitment_candidates.model_label') }} {{ candidate.ai_profile.ai_model_used }}</span><span v-if="candidate.ai_profile.generated_at" class="text-caption text-medium-emphasis">{{ t('recruitment_candidates.generated_label') }} {{ formatDate(candidate.ai_profile.generated_at) }}</span></div>
                <div v-if="candidate.ai_profile.confidence_score !== null" class="mb-4"><div class="d-flex justify-space-between mb-1"><span class="text-body-2 font-weight-medium">{{ t('recruitment_candidates.parse_confidence') }}</span><span class="text-body-2 text-medium-emphasis">{{ candidate.ai_profile.confidence_score }}%</span></div><VProgressLinear :model-value="candidate.ai_profile.confidence_score" :color="candidate.ai_profile.confidence_score >= 70 ? 'success' : candidate.ai_profile.confidence_score >= 40 ? 'warning' : 'error'" height="8" rounded bg-color="surface-variant" /></div>
                <VAlert type="info" variant="tonal" class="mb-4" icon="tabler-file-text"><p class="text-body-2 mb-0">{{ candidate.ai_profile.summary }}</p></VAlert>
                <VRow class="mb-4">
                  <VCol cols="12" md="6"><p class="text-body-2 font-weight-semibold mb-2 text-success"><VIcon icon="tabler-thumb-up" size="16" class="mr-1" />{{ t('recruitment_candidates.strengths') }}</p><div v-if="candidate.ai_profile.strengths?.length" class="d-flex flex-wrap gap-2"><VChip v-for="(s, i) in candidate.ai_profile.strengths" :key="i" color="success" variant="tonal" size="small" prepend-icon="tabler-check">{{ s }}</VChip></div><p v-else class="text-body-2 text-medium-emphasis mb-0">{{ t('recruitment_candidates.none_identified') }}</p></VCol>
                  <VCol cols="12" md="6"><p class="text-body-2 font-weight-semibold mb-2 text-warning"><VIcon icon="tabler-alert-circle" size="16" class="mr-1" />{{ t('recruitment_candidates.development_areas') }}</p><div v-if="candidate.ai_profile.weaknesses?.length" class="d-flex flex-wrap gap-2"><VChip v-for="(w, i) in candidate.ai_profile.weaknesses" :key="i" color="warning" variant="tonal" size="small" prepend-icon="tabler-alert-circle">{{ w }}</VChip></div><p v-else class="text-body-2 text-medium-emphasis mb-0">{{ t('recruitment_candidates.none_identified') }}</p></VCol>
                </VRow>
                <div v-if="candidate.ai_profile.keywords?.length"><p class="text-body-2 font-weight-semibold mb-2">{{ t('recruitment_candidates.keywords') }}</p><div class="d-flex flex-wrap gap-2"><VChip v-for="(kw, i) in candidate.ai_profile.keywords" :key="i" color="default" variant="tonal" size="x-small">{{ kw }}</VChip></div></div>
              </template>
            </VCardText></VCard>
          </VWindowItem>

          <VWindowItem value="experience">
            <VCard elevation="0" border><VCardText class="pa-5">
              <div v-if="!candidate.experience.length" class="text-center py-10"><VIcon icon="tabler-briefcase" size="64" color="medium-emphasis" class="mb-4" /><p class="text-body-1 font-weight-medium mb-1">{{ t('recruitment_candidates.no_experience') }}</p><p class="text-body-2 text-medium-emphasis mb-0">{{ t('recruitment_candidates.no_experience_sub') }}</p></div>
              <VTimeline v-else side="end" line-color="primary" density="compact" truncate-line="both">
                <VTimelineItem v-for="exp in candidate.experience" :key="exp.id" dot-color="primary" size="small">
                  <template #opposite><div class="text-right"><p class="text-body-2 font-weight-semibold mb-0">{{ exp.company_name }}</p><p class="text-caption text-medium-emphasis mb-0">{{ formatDateRange(exp.start_date, exp.end_date, exp.is_current) }}</p><VChip v-if="exp.is_current" color="success" variant="tonal" size="x-small" label class="mt-1">{{ t('recruitment_candidates.current_chip') }}</VChip></div></template>
                  <VCard elevation="0" border class="mb-2"><VCardText class="pa-3"><div class="d-flex align-center gap-2 mb-1 flex-wrap"><span class="text-body-2 font-weight-semibold">{{ exp.job_title }}</span><VChip v-if="exp.ai_extracted" color="info" variant="tonal" size="x-small" prepend-icon="tabler-robot">{{ t('recruitment_candidates.ai_extracted') }}</VChip></div><p v-if="exp.description" class="text-body-2 text-medium-emphasis mb-0">{{ exp.description }}</p></VCardText></VCard>
                </VTimelineItem>
              </VTimeline>
            </VCardText></VCard>
          </VWindowItem>

          <VWindowItem value="skills">
            <VCard elevation="0" border><VCardText class="pa-5">
              <div v-if="!candidate.skills.length" class="text-center py-10"><VIcon icon="tabler-star" size="64" color="medium-emphasis" class="mb-4" /><p class="text-body-1 font-weight-medium mb-1">{{ t('recruitment_candidates.no_skills') }}</p><p class="text-body-2 text-medium-emphasis mb-0">{{ t('recruitment_candidates.no_skills_sub') }}</p></div>
              <div v-else class="d-flex flex-column gap-5"><div v-for="group in skillsByProficiency" :key="group.level"><div class="d-flex align-center gap-2 mb-2"><VChip :color="proficiencyColor(group.level)" variant="tonal" size="small" label>{{ proficiencyLabel(group.level) }}</VChip><span class="text-caption text-medium-emphasis">{{ t('recruitment_candidates.skills_count', { count: group.skills.length }) }}</span></div><div class="d-flex flex-wrap gap-2"><VChip v-for="skill in group.skills" :key="skill.id" :color="proficiencyColor(skill.proficiency_level)" variant="tonal" size="small"><template v-if="skill.ai_extracted" #prepend><VIcon icon="tabler-robot" size="14" class="mr-1" /></template>{{ skill.skill }}<span v-if="skill.years_experience" class="text-caption ml-1 opacity-70">({{ skill.years_experience }}{{ t('recruitment_candidates.years_suffix') }})</span></VChip></div></div></div>
            </VCardText></VCard>
          </VWindowItem>

          <VWindowItem value="qualifications">
            <VCard elevation="0" border><VCardText class="pa-5">
              <div v-if="!candidate.qualifications.length" class="text-center py-10"><VIcon icon="tabler-school" size="64" color="medium-emphasis" class="mb-4" /><p class="text-body-1 font-weight-medium mb-1">{{ t('recruitment_candidates.no_qualifications') }}</p><p class="text-body-2 text-medium-emphasis mb-0">{{ t('recruitment_candidates.no_qualifications_sub') }}</p></div>
              <VList v-else lines="two" class="pa-0"><template v-for="(qual, idx) in candidate.qualifications" :key="qual.id"><VListItem class="px-0"><template #prepend><VAvatar color="primary" variant="tonal" size="40"><VIcon icon="tabler-school" size="20" /></VAvatar></template><VListItemTitle class="font-weight-semibold text-body-2">{{ qual.institution }}</VListItemTitle><VListItemSubtitle><div class="d-flex align-center gap-2 flex-wrap mt-1"><VChip color="primary" variant="tonal" size="x-small" label>{{ qual.qualification_type }}</VChip><span v-if="qual.field_of_study" class="text-caption text-medium-emphasis">{{ qual.field_of_study }}</span><span v-if="qual.year_completed" class="text-caption text-medium-emphasis">· {{ qual.year_completed }}</span><VChip v-if="qual.ai_extracted" color="info" variant="tonal" size="x-small" prepend-icon="tabler-robot">{{ t('recruitment_candidates.ai_extracted') }}</VChip></div></VListItemSubtitle></VListItem><VDivider v-if="idx < candidate.qualifications.length - 1" class="my-1" /></template></VList>
            </VCardText></VCard>
          </VWindowItem>

          <VWindowItem value="documents">
            <VCard elevation="0" border>
              <VDataTable :headers="docHeaders" :items="candidate.documents" item-value="id" hide-default-footer hover>
                <template #item.original_name="{ item }"><div class="d-flex align-center gap-2"><VIcon icon="tabler-file-text" size="18" color="primary" /><span class="text-body-2 font-weight-medium">{{ item.original_name }}</span></div></template>
                <template #item.type="{ item }"><VChip color="default" variant="tonal" size="x-small" label>{{ item.type.toUpperCase() }}</VChip></template>
                <template #item.file_size="{ item }"><span class="text-body-2 text-medium-emphasis">{{ formatFileSize(item.file_size) }}</span></template>
                <template #item.is_clean="{ item }"><div class="d-flex align-center gap-1"><VIcon :icon="item.is_clean ? 'tabler-shield-check' : 'tabler-shield-exclamation'" :color="item.is_clean ? 'success' : 'error'" size="18" /><span class="text-caption" :class="item.is_clean ? 'text-success' : 'text-error'">{{ item.is_clean ? t('recruitment_candidates.clean') : t('recruitment_candidates.flagged') }}</span></div></template>
                <template #item.parse_status="{ item }"><VChip v-if="item.parse_status" :color="parseStatusColor(item.parse_status)" variant="tonal" size="x-small" label>{{ item.parse_status }}</VChip><span v-else class="text-caption text-medium-emphasis">—</span></template>
                <template #item.uploaded_at="{ item }"><span class="text-body-2 text-medium-emphasis">{{ formatDate(item.uploaded_at) }}</span></template>
                <template #no-data><div class="text-center py-10"><VIcon icon="tabler-files" size="56" color="medium-emphasis" class="mb-3" /><p class="text-body-2 text-medium-emphasis mb-0">{{ t('recruitment_candidates.no_documents') }}</p></div></template>
              </VDataTable>
            </VCard>
          </VWindowItem>
        </VWindow>
      </VCol>
    </VRow>
  </div>
</template>
