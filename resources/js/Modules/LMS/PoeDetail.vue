<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import axios from 'axios'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Evidence = {
  id: number; title: string; description: string | null
  evidence_type: string; file_name: string | null; url: string | null
  size_mb: number | null; is_verified: boolean; uploaded_by: number
}

type Section = {
  id: number; title: string; description: string | null
  outcome_number: number | null; status: string; sort_order: number
  assessor_notes: string | null; assessment_decision: string | null
  learner_declaration: boolean; evidence: Evidence[]
}

type Portfolio = {
  id: number; uuid: string; title: string; status: string
  course: { title: string } | null
  assessor: { first_name: string; last_name: string } | null
  moderator: { first_name: string; last_name: string } | null
  sections: Section[]
  sections_count: number
  submitted_at: string | null; assessed_at: string | null
  assessment_decision: string | null; moderation_decision: string | null
  assessor_feedback: string | null; moderator_feedback: string | null
  learner_declared: boolean; declared_at: string | null
}

const props = defineProps<{ portfolio: Portfolio }>()

const confirmDialog = ref()

// ── Section dialog ────────────────────────────────────────────────────────────
const sectionDialog = ref(false)
const editSection   = ref<Section | null>(null)
const sectionForm   = useForm({ title: '', description: '', outcome_number: null as number | null })

function openAddSection() {
  editSection.value = null
  sectionForm.reset()
  sectionDialog.value = true
}
function openEditSection(s: Section) {
  editSection.value = s
  sectionForm.title          = s.title
  sectionForm.description    = s.description ?? ''
  sectionForm.outcome_number = s.outcome_number
  sectionDialog.value = true
}
function saveSection() {
  if (editSection.value) {
    sectionForm.patch(
      route('lms.poe.sections.update', { portfolio: props.portfolio.id, section: editSection.value.id }),
      { onSuccess: () => { sectionDialog.value = false } }
    )
  } else {
    sectionForm.post(
      route('lms.poe.sections.store', { portfolio: props.portfolio.id }),
      { onSuccess: () => { sectionDialog.value = false } }
    )
  }
}
function deleteSection(s: Section) {
  confirmDialog.value.open(
    {
      title: t('lms_portfolio_detail.sections.delete'),
      message: t('lms_portfolio_detail.sections.confirm_delete', { title: s.title }),
      confirmText: t('lms_portfolio_detail.sections.delete'),
      confirmColor: 'error',
      icon: 'tabler-trash'
    },
    () => { router.delete(route('lms.poe.sections.destroy', { portfolio: props.portfolio.id, section: s.id })) },
  )
}

// ── Evidence upload ───────────────────────────────────────────────────────────
const uploadDialog  = ref(false)
const uploadSection = ref<Section | null>(null)
const uploadFile    = ref<File | null>(null)
const uploadTitle   = ref('')
const uploadType    = ref('document')
const uploadUrl     = ref('')
const uploading     = ref(false)
const uploadProgress = ref(0)
const fileInputRef = ref<HTMLInputElement | null>(null)

const evidenceTypeOptions = computed(() => [
  { title: t('lms_portfolio_detail.evidence.types.document'),    value: 'document' },
  { title: t('lms_portfolio_detail.evidence.types.image'),       value: 'image' },
  { title: t('lms_portfolio_detail.evidence.types.video'),       value: 'video' },
  { title: t('lms_portfolio_detail.evidence.types.link'),        value: 'link' },
  { title: t('lms_portfolio_detail.evidence.types.observation'), value: 'observation' },
  { title: t('lms_portfolio_detail.evidence.types.testimonial'), value: 'testimonial' },
  { title: t('lms_portfolio_detail.evidence.types.product'),     value: 'product' },
  { title: t('lms_portfolio_detail.evidence.types.report'),      value: 'report' },
])

const evidenceTypeIcon: Record<string, string> = {
  document: 'tabler-file-text', image: 'tabler-photo', video: 'tabler-video',
  link: 'tabler-link', observation: 'tabler-eye', testimonial: 'tabler-message-quote',
  product: 'tabler-box', report: 'tabler-report',
}

function openUpload(s: Section) {
  uploadSection.value  = s
  uploadFile.value     = null
  uploadTitle.value    = ''
  uploadType.value     = 'document'
  uploadUrl.value      = ''
  uploadProgress.value = 0
  uploadDialog.value   = true
}

function onFileSelected(e: Event) {
  const f = (e.target as HTMLInputElement).files?.[0]
  if (f) { uploadFile.value = f; if (!uploadTitle.value) uploadTitle.value = f.name }
}

async function submitUpload() {
  if (!uploadSection.value) return
  uploading.value = true
  const fd = new FormData()
  fd.append('title', uploadTitle.value)
  fd.append('evidence_type', uploadType.value)
  if (uploadFile.value) fd.append('file', uploadFile.value)
  if (uploadUrl.value)  fd.append('url', uploadUrl.value)
  fd.append('_token', (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '')
  try {
    await axios.post(
      route('lms.poe.evidence.upload', { portfolio: props.portfolio.id, section: uploadSection.value.id }),
      fd,
      {
        headers: { 'Content-Type': 'multipart/form-data' },
        onUploadProgress: (e) => { if (e.total) uploadProgress.value = Math.round((e.loaded / e.total) * 100) },
      }
    )
    uploadDialog.value = false
    router.reload({ only: ['portfolio'] })
  } finally {
    uploading.value = false
  }
}

function deleteEvidence(s: Section, ev: Evidence) {
  confirmDialog.value.open(
    {
      title: t('lms_portfolio_detail.evidence.delete'),
      message: t('lms_portfolio_detail.evidence.delete_confirm', { title: ev.title }),
      confirmText: t('lms_portfolio_detail.evidence.delete'),
      confirmColor: 'error',
      icon: 'tabler-trash'
    },
    () => { router.delete(route('lms.poe.evidence.destroy', { portfolio: props.portfolio.id, section: s.id, evidence: ev.id })) },
  )
}

// ── Declaration & Submit ──────────────────────────────────────────────────────
const declaring  = ref(false)
const submitting = ref(false)

function declare() {
  declaring.value = true
  router.post(route('lms.poe.declare', { portfolio: props.portfolio.id }), {}, {
    onFinish: () => { declaring.value = false },
  })
}

function submit() {
  confirmDialog.value.open(
    {
      title: t('lms_portfolio_detail.declaration.submit'),
      message: t('lms_portfolio_detail.declaration.submit'),
      confirmText: t('lms_portfolio_detail.declaration.submit'),
      confirmColor: 'warning',
      icon: 'tabler-alert-triangle'
    },
    () => {
      submitting.value = true
      router.post(route('lms.poe.submit', { portfolio: props.portfolio.id }), {}, {
        onFinish: () => { submitting.value = false },
      })
    },
  )
}

// ── Status helpers ────────────────────────────────────────────────────────────
const statusColor: Record<string, string> = {
  draft: 'default', submitted: 'info', under_review: 'warning',
  returned: 'error', approved: 'success', rejected: 'error', certified: 'success',
}

const sectionStatusColor: Record<string, string> = {
  draft: 'default', ready: 'success', needs_revision: 'error',
}

const canEdit    = ['draft', 'returned'].includes(props.portfolio.status)
const canDeclare = canEdit && !props.portfolio.learner_declared
const canSubmit  = canEdit && props.portfolio.learner_declared && props.portfolio.sections.length > 0

function formatDate(date: string | null): string {
  if (!date) return ''
  return new Date(date).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}
</script>

<template>
  <div class="pa-5">
    <ConfirmDialog ref="confirmDialog" />

    <!-- Header -->
    <div class="d-flex align-center gap-3 mb-1 flex-wrap">
      <VBtn
        variant="text" size="small" prepend-icon="tabler-arrow-left"
        @click="router.visit(route('lms.poe.index'))"
      >{{ t('lms_portfolio_detail.back_to_portfolios') }}</VBtn>
    </div>

    <div class="d-flex align-center gap-3 mb-5 flex-wrap">
      <div class="flex-1-1">
        <h4 class="text-h4 mb-0">{{ portfolio.title }}</h4>
        <p v-if="portfolio.course" class="text-caption text-medium-emphasis mb-0">{{ portfolio.course.title }}</p>
      </div>
      <VChip :color="statusColor[portfolio.status]" variant="tonal">
        {{ t(`lms_portfolio_detail.statuses.${portfolio.status}`) }}
      </VChip>
    </div>

    <!-- Assessor feedback banner -->
    <VAlert v-if="portfolio.status === 'returned' && portfolio.assessor_feedback"
      type="warning" variant="tonal" class="mb-4">
      <strong>{{ t('lms_portfolio_detail.assessor_feedback') }}:</strong> {{ portfolio.assessor_feedback }}
    </VAlert>

    <!-- Success banner -->
    <VAlert v-if="portfolio.assessment_decision === 'competent'"
      type="success" variant="tonal" class="mb-4">
      <span v-html="t('lms_portfolio_detail.competent')"></span>
      <span v-if="portfolio.moderation_decision === 'agreed'"> {{ t('lms_portfolio_detail.moderator_confirmed') }}</span>
    </VAlert>

    <VRow>
      <!-- Sections column -->
      <VCol cols="12" lg="8">

        <!-- Declaration / Submit bar -->
        <VCard v-if="canEdit || canDeclare || canSubmit" rounded="lg" elevation="0" border class="mb-4">
          <VCardText class="pa-4">
            <div class="d-flex align-center gap-3 flex-wrap">
              <div class="flex-1-1">
                <p class="text-body-2 font-weight-semibold mb-0">{{ t('lms_portfolio_detail.declaration.title') }}</p>
                <p class="text-caption text-medium-emphasis mb-0">
                  {{ t('lms_portfolio_detail.declaration.description') }}
                </p>
              </div>
              <VChip v-if="portfolio.learner_declared" color="success" variant="tonal" prepend-icon="tabler-check">
                {{ t('lms_portfolio_detail.declaration.declared_on', { date: formatDate(portfolio.declared_at) }) }}
              </VChip>
              <VBtn v-else-if="canDeclare" color="info" variant="tonal" size="small"
                :loading="declaring" @click="declare">
                {{ t('lms_portfolio_detail.declaration.sign') }}
              </VBtn>
              <VBtn v-if="canSubmit" color="primary" size="small"
                :loading="submitting" @click="submit">
                {{ t('lms_portfolio_detail.declaration.submit') }}
              </VBtn>
            </div>
          </VCardText>
        </VCard>

        <!-- Sections -->
        <div class="d-flex align-center justify-space-between mb-3">
          <p class="text-body-1 font-weight-semibold mb-0">{{ t('lms_portfolio_detail.sections.title') }}</p>
          <VBtn v-if="canEdit" color="primary" size="small" prepend-icon="tabler-plus" @click="openAddSection">
            {{ t('lms_portfolio_detail.sections.add') }}
          </VBtn>
        </div>

        <VCard v-if="!portfolio.sections.length" rounded="lg" elevation="0" border>
          <VCardText class="text-center pa-10">
            <VAvatar color="primary" variant="tonal" size="52" class="mb-3">
              <VIcon icon="tabler-folder-plus" size="26" />
            </VAvatar>
            <p class="text-body-2 font-weight-semibold mb-1">{{ t('lms_portfolio_detail.sections.no_sections') }}</p>
            <p class="text-caption text-medium-emphasis mb-3">
              {{ t('lms_portfolio_detail.sections.no_sections_description') }}
            </p>
            <VBtn v-if="canEdit" color="primary" size="small" @click="openAddSection">
              {{ t('lms_portfolio_detail.sections.add_first') }}
            </VBtn>
          </VCardText>
        </VCard>

        <div v-else class="d-flex flex-column gap-3">
          <VCard v-for="s in portfolio.sections" :key="s.id" rounded="lg" elevation="0" border>
            <VCardText class="pa-4">

              <!-- Section header -->
              <div class="d-flex align-start gap-3 mb-3">
                <VAvatar color="primary" variant="tonal" size="36" rounded>
                  <span class="text-caption font-weight-bold">{{ s.outcome_number ?? '#' }}</span>
                </VAvatar>
                <div class="flex-1-1 min-width-0">
                  <p class="text-body-2 font-weight-semibold mb-0">{{ s.title }}</p>
                  <p v-if="s.description" class="text-caption text-medium-emphasis mb-0">{{ s.description }}</p>
                </div>
                <div class="d-flex gap-1 flex-shrink-0">
                  <VChip :color="sectionStatusColor[s.status] ?? 'default'" size="x-small" variant="tonal" class="text-capitalize">
                    {{ t(`lms_portfolio_detail.sections.statuses.${s.status}`) }}
                  </VChip>
                  <template v-if="canEdit">
                    <VBtn icon="tabler-edit" size="x-small" variant="text" @click="openEditSection(s)" />
                    <VBtn icon="tabler-trash" size="x-small" variant="text" color="error" @click="deleteSection(s)" />
                  </template>
                </div>
              </div>

              <!-- Assessor notes -->
              <VAlert v-if="s.assessor_notes" type="info" variant="tonal" density="compact" class="mb-3" style="font-size:0.75rem">
                <strong>{{ t('lms_portfolio_detail.assessor_feedback') }}:</strong> {{ s.assessor_notes }}
              </VAlert>

              <!-- Evidence list -->
              <div v-if="s.evidence.length" class="mb-2">
                <div v-for="ev in s.evidence" :key="ev.id"
                  class="d-flex align-center gap-2 py-1 border-b">
                  <VIcon :icon="evidenceTypeIcon[ev.evidence_type] ?? 'tabler-file'" size="16" color="primary" />
                  <div class="flex-1-1 min-width-0">
                    <p class="text-body-2 mb-0 text-truncate">{{ ev.title }}</p>
                    <p class="text-caption text-medium-emphasis mb-0 text-capitalize">
                      {{ t(`lms_portfolio_detail.evidence.types.${ev.evidence_type}`) }}
                      <span v-if="ev.size_mb"> · {{ ev.size_mb }} MB</span>
                    </p>
                  </div>
                  <VChip v-if="ev.is_verified" color="success" size="x-small" variant="tonal">{{ t('lms_portfolio_detail.evidence.verified') }}</VChip>
                  <VBtn v-if="ev.url" icon="tabler-external-link" size="x-small" variant="text" :href="ev.url" target="_blank" />
                  <VBtn v-if="canEdit" icon="tabler-trash" size="x-small" variant="text" color="error" @click="deleteEvidence(s, ev)" />
                </div>
              </div>

              <VBtn v-if="canEdit" size="x-small" variant="tonal" color="primary" prepend-icon="tabler-upload"
                @click="openUpload(s)">
                {{ t('lms_portfolio_detail.evidence.upload') }}
              </VBtn>
            </VCardText>
          </VCard>
        </div>
      </VCol>

      <!-- Sidebar -->
      <VCol cols="12" lg="4">
        <VCard rounded="lg" elevation="0" border class="mb-3">
          <VCardText class="pa-4">
            <p class="text-body-2 font-weight-semibold mb-3">{{ t('lms_portfolio_detail.sidebar.portfolio_info') }}</p>
            <div class="d-flex flex-column gap-2">
              <div class="d-flex justify-space-between">
                <span class="text-caption text-medium-emphasis">{{ t('lms_portfolio_detail.sidebar.sections') }}</span>
                <span class="text-caption font-weight-medium">{{ portfolio.sections.length }}</span>
              </div>
              <div class="d-flex justify-space-between">
                <span class="text-caption text-medium-emphasis">{{ t('lms_portfolio_detail.sidebar.submitted') }}</span>
                <span class="text-caption font-weight-medium">
                  {{ formatDate(portfolio.submitted_at) || '—' }}
                </span>
              </div>
              <div class="d-flex justify-space-between">
                <span class="text-caption text-medium-emphasis">{{ t('lms_portfolio_detail.sidebar.assessed') }}</span>
                <span class="text-caption font-weight-medium">
                  {{ formatDate(portfolio.assessed_at) || '—' }}
                </span>
              </div>
              <div v-if="portfolio.assessor" class="d-flex justify-space-between">
                <span class="text-caption text-medium-emphasis">{{ t('lms_portfolio_detail.sidebar.assessor') }}</span>
                <span class="text-caption font-weight-medium">
                  {{ portfolio.assessor.first_name }} {{ portfolio.assessor.last_name }}
                </span>
              </div>
              <div v-if="portfolio.moderator" class="d-flex justify-space-between">
                <span class="text-caption text-medium-emphasis">{{ t('lms_portfolio_detail.sidebar.moderator') }}</span>
                <span class="text-caption font-weight-medium">
                  {{ portfolio.moderator.first_name }} {{ portfolio.moderator.last_name }}
                </span>
              </div>
            </div>
          </VCardText>
        </VCard>

        <VCard v-if="portfolio.assessment_decision" rounded="lg" elevation="0" border>
          <VCardText class="pa-4">
            <p class="text-body-2 font-weight-semibold mb-3">{{ t('lms_portfolio_detail.sidebar.assessment_result') }}</p>
            <VChip
              :color="portfolio.assessment_decision === 'competent' ? 'success' : 'error'"
              variant="tonal" class="text-capitalize mb-2">
              {{ t(`lms_portfolio_detail.assessment_decision.${portfolio.assessment_decision}`) }}
            </VChip>
            <p v-if="portfolio.assessor_feedback" class="text-caption mt-2 mb-0">
              {{ portfolio.assessor_feedback }}
            </p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Section dialog -->
    <VDialog v-model="sectionDialog" max-width="480">
      <VCard rounded="lg">
        <VCardTitle class="pa-5 pb-0">
          {{ editSection ? t('lms_portfolio_detail.dialog.section.title_edit') : t('lms_portfolio_detail.dialog.section.title_new') }}
        </VCardTitle>
        <VCardText class="pa-5">
          <VTextField
            v-model="sectionForm.title"
            :label="t('lms_portfolio_detail.dialog.section.title_label')"
            :error-messages="sectionForm.errors.title"
            class="mb-3"
          />
          <VTextField
            v-model.number="sectionForm.outcome_number"
            :label="t('lms_portfolio_detail.dialog.section.outcome_label')"
            type="number"
            min="1"
            class="mb-3"
            :hint="t('lms_portfolio_detail.dialog.section.outcome_hint')"
            persistent-hint
          />
          <VTextarea
            v-model="sectionForm.description"
            :label="t('lms_portfolio_detail.dialog.section.description_label')"
            rows="2"
            auto-grow
          />
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2 justify-end">
          <VBtn variant="outlined" @click="sectionDialog = false">{{ t('lms_portfolio_detail.dialog.section.cancel') }}</VBtn>
          <VBtn color="primary" :loading="sectionForm.processing" @click="saveSection">
            {{ t('lms_portfolio_detail.dialog.section.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Upload evidence dialog -->
    <VDialog v-model="uploadDialog" max-width="520">
      <VCard rounded="lg">
        <VCardTitle class="pa-5 pb-0">{{ t('lms_portfolio_detail.dialog.evidence.title') }}</VCardTitle>
        <VCardText class="pa-5">
          <VTextField
            v-model="uploadTitle"
            :label="t('lms_portfolio_detail.evidence.title')"
            class="mb-3"
          />
          <VSelect
            v-model="uploadType"
            :label="t('lms_portfolio_detail.evidence.type_label')"
            :items="evidenceTypeOptions"
            class="mb-3"
          />
          <template v-if="uploadType === 'link'">
            <VTextField
              v-model="uploadUrl"
              :label="t('lms_portfolio_detail.evidence.url_label')"
              type="url"
              class="mb-3"
            />
          </template>
          <template v-else>
            <VCard rounded="lg" elevation="0" border class="pa-4 text-center mb-3">
              <input ref="fileInputRef" type="file" style="display:none" @change="onFileSelected" />
              <VBtn variant="tonal" prepend-icon="tabler-upload"
                @click="fileInputRef?.click()">
                {{ uploadFile ? uploadFile.name : t('lms_portfolio_detail.evidence.file_label') }}
              </VBtn>
            </VCard>
            <VProgressLinear v-if="uploading" v-model="uploadProgress" height="6" rounded color="primary" />
          </template>
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2 justify-end">
          <VBtn variant="outlined" @click="uploadDialog = false">{{ t('lms_portfolio_detail.evidence.cancel') }}</VBtn>
          <VBtn color="primary" :loading="uploading" @click="submitUpload">
            {{ t('lms_portfolio_detail.evidence.upload_button') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

  </div>
</template>
