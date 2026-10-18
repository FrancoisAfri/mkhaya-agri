<script setup lang="ts">
import { computed, ref, reactive, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Task = {
  id: number; type: { value: string; label: string; icon: string }; title: string
  description: string | null; status: { value: string; label: string }
  due_date: string | null; assigned_to: number | null; completed_at: string | null; sort_order: number
}
type Document = {
  id: number; uuid: string; document_type: { value: string; label: string; icon: string }
  label: string; is_required: boolean; status: { value: string; label: string; color: string }
  file_name: string | null; rejection_reason: string | null; reviewed_at: string | null; created_at: string
}
type ProvisioningTask = {
  id: number; uuid: string; type: { value: string; label: string; icon: string }
  title: string; notes: string | null; status: { value: string; label: string; color: string }
  assigned_department: string | null; due_date: string | null; completed_at: string | null; created_at: string
}
type ProbationReview = {
  id: number; review_date: string; review_type: string; performance_score: number | null
  manager_feedback: string | null; employee_feedback: string | null
  areas_of_improvement: string | null; recommendation: string | null
  completed_at: string | null; created_at: string
}
type ProbationPeriod = {
  id: number; uuid: string; start_date: string; end_date: string; extended_end_date: string | null
  duration_months: number; status: { value: string; label: string; color: string }
  outcome: string | null; outcome_notes: string | null; next_review_date: string | null; reviews: ProbationReview[]
} | null

const props = defineProps<{
  plan: {
    id: number; uuid: string; status: { value: string; label: string; color: string }
    start_date: string; end_date: string; completed_at: string | null; completion: number
    hr_person: { id: number; uuid: string; candidate: { full_name: string; email: string } | null }
    tasks: Task[]
  }
  documents: Document[]; provisioningTasks: ProvisioningTask[]; probation: ProbationPeriod
}>()

function formatDate(val: string | null) { if (!val) return '—'; return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' }) }

function taskStatusColor(status: string) { if (status === 'completed') return 'success'; if (status === 'skipped') return 'default'; if (status === 'in_progress') return 'info'; return 'warning' }
function taskStatusIcon(status: string) { if (status === 'completed') return 'tabler-circle-check'; if (status === 'skipped') return 'tabler-circle-minus'; if (status === 'in_progress') return 'tabler-progress-check'; return 'tabler-circle' }

const activeTab = ref('tasks')
const completingTask = ref<number | null>(null)
const showDone = ref(false)

const completedCount = computed(() => props.plan.tasks.filter(t => t.status.value !== 'pending' && t.status.value !== 'in_progress').length)
const pendingTasks = computed(() => props.plan.tasks.filter(t => t.status.value === 'pending' || t.status.value === 'in_progress'))
const doneTasks = computed(() => props.plan.tasks.filter(t => t.status.value === 'completed' || t.status.value === 'skipped'))

function completeTask(task: Task, skip = false) {
  completingTask.value = task.id
  router.post(route('onboarding.tasks.complete', [props.plan.hr_person.id, props.plan.id, task.id]), { skip: skip ? 1 : 0 }, { preserveScroll: true, onFinish: () => { completingTask.value = null } })
}

const docDialog = ref(false); const rejectDialog = ref(false)
const rejectingDoc = ref<Document | null>(null)
const submittingDoc = ref(false); const submittingReject = ref(false)

const docForm = reactive({ label: '', document_type: '', is_required: true })
const rejectForm = reactive({ rejection_reason: '' })

function resetDocForm() { docForm.label = ''; docForm.document_type = ''; docForm.is_required = true }
function resetRejectForm() { rejectForm.rejection_reason = ''; rejectingDoc.value = null }

function submitDoc() { submittingDoc.value = true; router.post(route('onboarding.documents.store', [props.plan.hr_person.id]), { ...docForm }, { preserveScroll: true, onFinish: () => { submittingDoc.value = false; docDialog.value = false; resetDocForm() } }) }
function approveDoc(doc: Document) { router.post(route('onboarding.documents.approve', [props.plan.hr_person.id, doc.id]), {}, { preserveScroll: true }) }
function openRejectDialog(doc: Document) { rejectingDoc.value = doc; rejectDialog.value = true }
function submitReject() { if (!rejectingDoc.value) return; submittingReject.value = true; router.post(route('onboarding.documents.reject', [props.plan.hr_person.id, rejectingDoc.value.id]), { rejection_reason: rejectForm.rejection_reason }, { preserveScroll: true, onFinish: () => { submittingReject.value = false; rejectDialog.value = false; resetRejectForm() } }) }
function deleteDoc(doc: Document) { router.delete(route('onboarding.documents.destroy', [props.plan.hr_person.id, doc.id]), { preserveScroll: true }) }

const docTypes = computed(() => [
  { title: t('onboarding.doc_type_id'), value: 'id_document' },
  { title: t('onboarding.doc_type_passport'), value: 'passport' },
  { title: t('onboarding.doc_type_proof_address'), value: 'proof_of_address' },
  { title: t('onboarding.doc_type_banking'), value: 'banking_details' },
  { title: t('onboarding.doc_type_tax'), value: 'tax_number' },
  { title: t('onboarding.doc_type_emergency'), value: 'emergency_contact' },
  { title: t('onboarding.doc_type_qualification'), value: 'qualification' },
  { title: t('onboarding.doc_type_other'), value: 'other' },
])

const approvedDocCount = computed(() => props.documents.filter(d => d.status.value === 'approved').length)
watch(docDialog, open => { if (!open) resetDocForm() })
watch(rejectDialog, open => { if (!open) resetRejectForm() })

const provDialog = ref(false); const submittingProv = ref(false)

const provisioningTypes = computed(() => [
  { title: t('onboarding.prov_type_laptop'), value: 'laptop', icon: 'tabler-laptop' },
  { title: t('onboarding.prov_type_email'), value: 'email_account', icon: 'tabler-mail' },
  { title: t('onboarding.prov_type_system_access'), value: 'system_access', icon: 'tabler-shield-lock' },
  { title: t('onboarding.prov_type_building_access'), value: 'building_access', icon: 'tabler-door-enter' },
  { title: t('onboarding.prov_type_payroll'), value: 'payroll_profile', icon: 'tabler-cash' },
  { title: t('onboarding.prov_type_phone'), value: 'phone', icon: 'tabler-phone' },
  { title: t('onboarding.prov_type_other'), value: 'other', icon: 'tabler-tool' },
])

const provTitleDefaults: Record<string, string> = {
  laptop: t('onboarding.prov_default_laptop'), email_account: t('onboarding.prov_default_email'),
  system_access: t('onboarding.prov_default_system_access'), building_access: t('onboarding.prov_default_building_access'),
  payroll_profile: t('onboarding.prov_default_payroll'), phone: t('onboarding.prov_default_phone'), other: '',
}

const provForm = reactive({ type: '', title: '', notes: '', assigned_department: '', due_date: '' })
function resetProvForm() { provForm.type = ''; provForm.title = ''; provForm.notes = ''; provForm.assigned_department = ''; provForm.due_date = '' }
watch(() => provForm.type, (val) => { if (val && provTitleDefaults[val]) provForm.title = provTitleDefaults[val] })

function submitProv() { submittingProv.value = true; router.post(route('onboarding.provisioning.store', [props.plan.hr_person.id]), { ...provForm }, { preserveScroll: true, onFinish: () => { submittingProv.value = false; provDialog.value = false; resetProvForm() } }) }
function updateProvStatus(task: ProvisioningTask, newStatus: string) { router.post(route('onboarding.provisioning.status', [props.plan.hr_person.id, task.id]), { status: newStatus }, { preserveScroll: true }) }
function deleteProv(task: ProvisioningTask) { router.delete(route('onboarding.provisioning.destroy', [props.plan.hr_person.id, task.id]), { preserveScroll: true }) }

const provPending = computed(() => props.provisioningTasks.filter(t => t.status.value === 'pending').length)
const provInProgress = computed(() => props.provisioningTasks.filter(t => t.status.value === 'in_progress').length)
const provCompleted = computed(() => props.provisioningTasks.filter(t => t.status.value === 'completed').length)
watch(provDialog, open => { if (!open) resetProvForm() })

const probationDialog = ref(false); const reviewDialog = ref(false); const closeProbDialog = ref(false)
const submittingProbation = ref(false); const submittingReview = ref(false); const submittingClose = ref(false)

const probationForm = reactive({ start_date: '', duration_months: 3, notes: '' })
const reviewForm = reactive({ review_date: '', review_type: '', performance_score: null as number | null, manager_feedback: '', employee_feedback: '', areas_of_improvement: '', recommendation: '' })
const closeForm = reactive({ outcome: '', outcome_notes: '' })

function resetProbationForm() { probationForm.start_date = ''; probationForm.duration_months = 3; probationForm.notes = '' }
function resetReviewForm() { reviewForm.review_date = ''; reviewForm.review_type = ''; reviewForm.performance_score = null; reviewForm.manager_feedback = ''; reviewForm.employee_feedback = ''; reviewForm.areas_of_improvement = ''; reviewForm.recommendation = '' }
function resetCloseForm() { closeForm.outcome = ''; closeForm.outcome_notes = '' }

function submitProbation() { submittingProbation.value = true; router.post(route('onboarding.probation.store', [props.plan.hr_person.id]), { ...probationForm }, { preserveScroll: true, onFinish: () => { submittingProbation.value = false; probationDialog.value = false; resetProbationForm() } }) }
function submitReview() { if (!props.probation) return; submittingReview.value = true; router.post(route('onboarding.probation.review', [props.plan.hr_person.id, props.probation.id]), { ...reviewForm }, { preserveScroll: true, onFinish: () => { submittingReview.value = false; reviewDialog.value = false; resetReviewForm() } }) }
function submitClose() { if (!props.probation) return; submittingClose.value = true; router.post(route('onboarding.probation.close', [props.plan.hr_person.id, props.probation.id]), { ...closeForm }, { preserveScroll: true, onFinish: () => { submittingClose.value = false; closeProbDialog.value = false; resetCloseForm() } }) }

const probationProgress = computed(() => {
  if (!props.probation) return 0
  const start = new Date(props.probation.start_date).getTime()
  const end = new Date(props.probation.extended_end_date ?? props.probation.end_date).getTime()
  const now = Date.now()
  if (now >= end) return 100
  if (now <= start) return 0
  return Math.round(((now - start) / (end - start)) * 100)
})

function reviewTypeLabel(val: string) { if (val === 'mid') return t('onboarding.review_mid'); if (val === 'final') return t('onboarding.review_final'); if (val === 'extension') return t('onboarding.review_extension'); return val }
function recommendationColor(val: string | null) { if (val === 'confirm') return 'success'; if (val === 'extend') return 'warning'; if (val === 'dismiss') return 'error'; return 'default' }

watch(probationDialog, open => { if (!open) resetProbationForm() })
watch(reviewDialog, open => { if (!open) resetReviewForm() })
watch(closeProbDialog, open => { if (!open) resetCloseForm() })
</script>

<template>
  <div class="pa-6">
    <!-- Breadcrumb -->
    <div class="d-flex align-center gap-2 mb-4">
      <VBtn icon="tabler-arrow-left" variant="text" size="small" @click="router.visit(route('pre-boarding.show', plan.hr_person.id))" />
      <span class="text-medium-emphasis text-body-2 cursor-pointer" @click="router.visit(route('onboarding.index'))">{{ t('onboarding.plan_breadcrumb_onboarding') }}</span>
      <VIcon icon="tabler-chevron-right" size="16" class="text-medium-emphasis" />
      <span class="text-medium-emphasis text-body-2 cursor-pointer" @click="router.visit(route('pre-boarding.show', plan.hr_person.id))">{{ plan.hr_person.candidate?.full_name ?? t('onboarding.unknown') }}</span>
      <VIcon icon="tabler-chevron-right" size="16" class="text-medium-emphasis" />
      <span class="text-body-2 font-weight-medium">{{ t('onboarding.plan_breadcrumb_plan') }}</span>
    </div>

    <!-- Header -->
    <div class="d-flex align-start justify-space-between mb-5">
      <div>
        <div class="d-flex align-center gap-2 mb-1">
          <h4 class="text-h4 font-weight-bold">{{ t('onboarding.plan_breadcrumb_plan') }}</h4>
          <VChip :color="plan.status.color" size="small" label variant="tonal" class="font-weight-medium">{{ plan.status.label }}</VChip>
        </div>
        <p class="text-medium-emphasis mb-0">{{ plan.hr_person.candidate?.full_name ?? t('onboarding.unknown') }} · {{ formatDate(plan.start_date) }} – {{ formatDate(plan.end_date) }}</p>
      </div>
    </div>

    <VRow>
      <VCol cols="12" lg="8">
        <VTabs v-model="activeTab" class="mb-4">
          <VTab value="tasks"><VIcon icon="tabler-clipboard-list" size="18" class="me-2" />{{ t('onboarding.tab_tasks') }}<VBadge v-if="pendingTasks.length" :content="pendingTasks.length" color="warning" inline class="ms-2" /></VTab>
          <VTab value="documents"><VIcon icon="tabler-file-text" size="18" class="me-2" />{{ t('onboarding.tab_documents') }}<VBadge v-if="documents.length" :content="documents.length" color="info" inline class="ms-2" /></VTab>
          <VTab value="provisioning"><VIcon icon="tabler-laptop" size="18" class="me-2" />{{ t('onboarding.tab_provisioning') }}</VTab>
          <VTab value="probation"><VIcon icon="tabler-calendar-clock" size="18" class="me-2" />{{ t('onboarding.tab_probation') }}</VTab>
        </VTabs>

        <VTabsWindow v-model="activeTab">
          <!-- Tasks Tab -->
          <VTabsWindowItem value="tasks">
            <VCard elevation="0" border>
              <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">{{ t('onboarding.onboarding_tasks') }}<VBadge v-if="pendingTasks.length" :content="pendingTasks.length" color="warning" inline class="ms-2" /></VCardTitle>
              <VCardText class="pa-5 pt-0">
                <div v-if="pendingTasks.length" class="d-flex flex-column gap-3 mb-4">
                  <div v-for="task in pendingTasks" :key="task.id" class="d-flex align-start gap-3 pa-3 rounded-lg" style="border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));">
                    <div class="mt-1 flex-shrink-0"><VIcon :icon="taskStatusIcon(task.status.value)" :color="taskStatusColor(task.status.value)" size="20" /></div>
                    <VIcon :icon="task.type.icon" size="18" color="primary" class="mt-1 flex-shrink-0" />
                    <div class="flex-grow-1"><p class="text-body-2 font-weight-medium mb-1">{{ task.title }}</p><p v-if="task.description" class="text-caption text-medium-emphasis mb-1">{{ task.description }}</p><div class="d-flex align-center gap-3 flex-wrap"><VChip size="x-small" variant="text" color="default">{{ task.type.label }}</VChip><span v-if="task.due_date" class="text-caption text-medium-emphasis">{{ t('onboarding.due_label') }} {{ formatDate(task.due_date) }}</span></div></div>
                    <div class="d-flex flex-column align-end gap-2 flex-shrink-0">
                      <VChip :color="taskStatusColor(task.status.value)" size="x-small" label variant="tonal" class="font-weight-medium">{{ task.status.label }}</VChip>
                      <div class="d-flex gap-1"><VBtn size="x-small" color="success" variant="tonal" :loading="completingTask === task.id" @click="completeTask(task, false)">{{ t('onboarding.complete') }}</VBtn><VBtn size="x-small" color="default" variant="text" :loading="completingTask === task.id" @click="completeTask(task, true)">{{ t('onboarding.skip') }}</VBtn></div>
                    </div>
                  </div>
                </div>
                <div v-if="doneTasks.length">
                  <VDivider v-if="pendingTasks.length" class="mb-3" />
                  <VBtn variant="text" color="default" size="small" :prepend-icon="showDone ? 'tabler-chevron-up' : 'tabler-chevron-down'" @click="showDone = !showDone">{{ showDone ? t('onboarding.hide_completed') : t('onboarding.show_completed') }} ({{ doneTasks.length }})</VBtn>
                  <div v-if="showDone" class="d-flex flex-column gap-2 mt-3">
                    <div v-for="task in doneTasks" :key="task.id" class="d-flex align-center gap-3 pa-3 rounded-lg opacity-60" style="border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));">
                      <VIcon :icon="taskStatusIcon(task.status.value)" :color="taskStatusColor(task.status.value)" size="18" class="flex-shrink-0" />
                      <VIcon :icon="task.type.icon" size="16" color="primary" class="flex-shrink-0" />
                      <p class="text-body-2 font-weight-medium mb-0 flex-grow-1 text-decoration-line-through">{{ task.title }}</p>
                      <VChip :color="taskStatusColor(task.status.value)" size="x-small" label variant="tonal" class="font-weight-medium">{{ task.status.label }}</VChip>
                      <span v-if="task.completed_at" class="text-caption text-medium-emphasis">{{ formatDate(task.completed_at) }}</span>
                    </div>
                  </div>
                </div>
                <div v-if="!plan.tasks.length" class="text-center py-8"><VIcon icon="tabler-clipboard-text" size="48" color="medium-emphasis" class="mb-3 d-block mx-auto" /><p class="text-body-2 text-medium-emphasis mb-0">{{ t('onboarding.no_tasks') }}</p></div>
              </VCardText>
            </VCard>
          </VTabsWindowItem>

          <!-- Documents Tab -->
          <VTabsWindowItem value="documents">
            <VCard elevation="0" border>
              <VCardTitle class="pa-5 pb-2 d-flex align-center justify-space-between">
                <span class="text-body-1 font-weight-semibold">{{ t('onboarding.documents_title') }}<span class="text-caption text-medium-emphasis ms-2">{{ approvedDocCount }} / {{ documents.length }} {{ t('onboarding.documents_approved') }}</span></span>
                <VBtn size="small" color="primary" variant="tonal" prepend-icon="tabler-plus" @click="docDialog = true">{{ t('onboarding.add_requirement') }}</VBtn>
              </VCardTitle>
              <VCardText class="pa-5 pt-0">
                <div v-if="documents.length" class="d-flex flex-column gap-3">
                  <div v-for="doc in documents" :key="doc.id" class="d-flex align-start gap-3 pa-3 rounded-lg" style="border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));">
                    <VIcon :icon="doc.document_type.icon" color="primary" size="20" class="mt-1 flex-shrink-0" />
                    <div class="flex-grow-1">
                      <div class="d-flex align-center gap-2 mb-1 flex-wrap"><span class="text-body-2 font-weight-medium">{{ doc.label }}</span><VChip v-if="doc.is_required" size="x-small" color="error" variant="tonal" label>{{ t('onboarding.required_chip') }}</VChip><VChip :color="doc.status.color" size="x-small" label variant="tonal" class="font-weight-medium">{{ doc.status.label }}</VChip></div>
                      <p v-if="doc.file_name" class="text-caption text-medium-emphasis mb-1"><VIcon icon="tabler-paperclip" size="12" class="me-1" />{{ doc.file_name }}</p>
                      <p v-if="doc.rejection_reason" class="text-caption text-error mb-0"><VIcon icon="tabler-alert-circle" size="12" class="me-1" />{{ doc.rejection_reason }}</p>
                    </div>
                    <div class="d-flex gap-1 align-center flex-shrink-0">
                      <VBtn v-if="doc.status.value === 'submitted'" size="x-small" color="success" variant="tonal" @click="approveDoc(doc)">{{ t('onboarding.approve') }}</VBtn>
                      <VBtn v-if="doc.status.value === 'submitted'" size="x-small" color="error" variant="tonal" @click="openRejectDialog(doc)">{{ t('onboarding.reject') }}</VBtn>
                      <VBtn v-if="doc.status.value === 'pending'" size="x-small" color="error" variant="text" icon="tabler-trash" @click="deleteDoc(doc)" />
                    </div>
                  </div>
                </div>
                <div v-else class="text-center py-10"><VIcon icon="tabler-file-text" size="52" color="medium-emphasis" class="mb-3 d-block mx-auto" /><p class="text-body-2 font-weight-medium mb-1">{{ t('onboarding.no_documents') }}</p><p class="text-caption text-medium-emphasis mb-4">{{ t('onboarding.no_documents_sub') }}</p><VBtn size="small" color="primary" variant="tonal" prepend-icon="tabler-plus" @click="docDialog = true">{{ t('onboarding.add_requirement') }}</VBtn></div>
              </VCardText>
            </VCard>
          </VTabsWindowItem>

          <!-- Provisioning Tab -->
          <VTabsWindowItem value="provisioning">
            <VCard elevation="0" border>
              <VCardTitle class="pa-5 pb-2 d-flex align-center justify-space-between"><span class="text-body-1 font-weight-semibold">{{ t('onboarding.provisioning_title') }}</span><VBtn size="small" color="primary" variant="tonal" prepend-icon="tabler-plus" @click="provDialog = true">{{ t('onboarding.add_task') }}</VBtn></VCardTitle>
              <div class="px-5 pb-3 d-flex gap-4 flex-wrap">
                <div class="d-flex align-center gap-1"><VIcon icon="tabler-circle" size="14" color="warning" /><span class="text-caption text-medium-emphasis">{{ provPending }} {{ t('onboarding.prov_pending') }}</span></div>
                <div class="d-flex align-center gap-1"><VIcon icon="tabler-progress-check" size="14" color="info" /><span class="text-caption text-medium-emphasis">{{ provInProgress }} {{ t('onboarding.prov_in_progress') }}</span></div>
                <div class="d-flex align-center gap-1"><VIcon icon="tabler-circle-check" size="14" color="success" /><span class="text-caption text-medium-emphasis">{{ provCompleted }} {{ t('onboarding.prov_completed') }}</span></div>
              </div>
              <VCardText class="pa-5 pt-0">
                <div v-if="provisioningTasks.length" class="d-flex flex-column gap-3">
                  <div v-for="task in provisioningTasks" :key="task.id" class="d-flex align-start gap-3 pa-3 rounded-lg" style="border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));">
                    <VIcon :icon="task.type.icon" color="primary" size="20" class="mt-1 flex-shrink-0" />
                    <div class="flex-grow-1">
                      <div class="d-flex align-center gap-2 mb-1 flex-wrap"><span class="text-body-2 font-weight-medium">{{ task.title }}</span><VChip v-if="task.assigned_department" size="x-small" color="default" variant="tonal" label>{{ task.assigned_department }}</VChip><VChip :color="task.status.color" size="x-small" label variant="tonal" class="font-weight-medium">{{ task.status.label }}</VChip></div>
                      <p v-if="task.notes" class="text-caption text-medium-emphasis mb-1">{{ task.notes }}</p>
                      <p v-if="task.due_date" class="text-caption text-medium-emphasis mb-0">{{ t('onboarding.due_label') }} {{ formatDate(task.due_date) }}</p>
                    </div>
                    <div class="d-flex gap-1 align-start flex-shrink-0 flex-wrap justify-end">
                      <VBtn v-if="task.status.value === 'pending'" size="x-small" color="info" variant="tonal" @click="updateProvStatus(task, 'requested')">{{ t('onboarding.mark_requested') }}</VBtn>
                      <VBtn v-if="task.status.value === 'requested'" size="x-small" color="info" variant="tonal" @click="updateProvStatus(task, 'in_progress')">{{ t('onboarding.mark_in_progress') }}</VBtn>
                      <VBtn v-if="task.status.value === 'in_progress' || task.status.value === 'requested'" size="x-small" color="success" variant="tonal" @click="updateProvStatus(task, 'completed')">{{ t('onboarding.mark_complete') }}</VBtn>
                      <VBtn v-if="task.status.value !== 'completed' && task.status.value !== 'cancelled'" size="x-small" color="default" variant="text" @click="updateProvStatus(task, 'cancelled')">{{ t('onboarding.cancel') }}</VBtn>
                      <VBtn v-if="task.status.value === 'pending' || task.status.value === 'cancelled'" size="x-small" color="error" variant="text" icon="tabler-trash" @click="deleteProv(task)" />
                    </div>
                  </div>
                </div>
                <div v-else class="text-center py-10"><VIcon icon="tabler-laptop" size="52" color="medium-emphasis" class="mb-3 d-block mx-auto" /><p class="text-body-2 font-weight-medium mb-1">{{ t('onboarding.no_provisioning') }}</p><p class="text-caption text-medium-emphasis mb-4">{{ t('onboarding.no_provisioning_sub') }}</p><VBtn size="small" color="primary" variant="tonal" prepend-icon="tabler-plus" @click="provDialog = true">{{ t('onboarding.add_task') }}</VBtn></div>
              </VCardText>
            </VCard>
          </VTabsWindowItem>

          <!-- Probation Tab -->
          <VTabsWindowItem value="probation">
            <VCard v-if="!probation" elevation="0" border>
              <VCardText class="text-center py-12"><VIcon icon="tabler-calendar-clock" size="64" color="medium-emphasis" class="mb-4 d-block mx-auto" /><p class="text-body-1 font-weight-medium mb-1">{{ t('onboarding.no_probation') }}</p><p class="text-body-2 text-medium-emphasis mb-6">{{ t('onboarding.no_probation_sub') }}</p><VBtn color="primary" variant="tonal" prepend-icon="tabler-player-play" @click="probationDialog = true">{{ t('onboarding.start_probation') }}</VBtn></VCardText>
            </VCard>
            <div v-else class="d-flex flex-column gap-4">
              <VCard elevation="0" border>
                <VCardTitle class="pa-5 pb-2 d-flex align-center justify-space-between"><span class="text-body-1 font-weight-semibold">{{ t('onboarding.probation_period') }}</span>
                  <div class="d-flex gap-2"><VBtn v-if="probation.status.value === 'active' || probation.status.value === 'extended'" size="small" color="warning" variant="tonal" prepend-icon="tabler-flag-2" @click="closeProbDialog = true">{{ t('onboarding.close_probation') }}</VBtn><VBtn size="small" color="primary" variant="tonal" prepend-icon="tabler-plus" @click="reviewDialog = true">{{ t('onboarding.add_review') }}</VBtn></div>
                </VCardTitle>
                <VCardText class="pa-5 pt-0">
                  <div class="d-flex flex-wrap gap-4 mb-4">
                    <div><p class="text-caption text-medium-emphasis mb-0">{{ t('onboarding.status_label') }}</p><VChip :color="probation.status.color" size="small" label variant="tonal" class="font-weight-medium mt-1">{{ probation.status.label }}</VChip></div>
                    <div><p class="text-caption text-medium-emphasis mb-1">{{ t('onboarding.start_date') }}</p><p class="text-body-2 font-weight-medium mb-0">{{ formatDate(probation.start_date) }}</p></div>
                    <div><p class="text-caption text-medium-emphasis mb-1">{{ t('onboarding.end_date') }}</p><p class="text-body-2 font-weight-medium mb-0">{{ formatDate(probation.extended_end_date ?? probation.end_date) }}<span v-if="probation.extended_end_date" class="text-caption text-warning">({{ t('onboarding.extended') }})</span></p></div>
                    <div><p class="text-caption text-medium-emphasis mb-1">{{ t('onboarding.duration') }}</p><p class="text-body-2 font-weight-medium mb-0">{{ probation.duration_months }} {{ t('onboarding.months') }}</p></div>
                    <div v-if="probation.next_review_date"><p class="text-caption text-medium-emphasis mb-1">{{ t('onboarding.next_review') }}</p><p class="text-body-2 font-weight-medium mb-0">{{ formatDate(probation.next_review_date) }}</p></div>
                  </div>
                  <div class="mb-2"><div class="d-flex align-center justify-space-between mb-2"><span class="text-caption text-medium-emphasis">{{ t('onboarding.time_elapsed') }}</span><span class="text-caption font-weight-medium text-primary">{{ probationProgress }}%</span></div><VProgressLinear :model-value="probationProgress" :color="probation.status.value === 'completed' ? 'success' : 'primary'" rounded height="8" /></div>
                  <div v-if="probation.outcome" class="mt-3 pa-3 rounded-lg" style="background: rgba(var(--v-border-color), 0.05); border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));"><p class="text-caption text-medium-emphasis mb-1">{{ t('onboarding.outcome_label') }}</p><VChip :color="recommendationColor(probation.outcome)" size="small" label variant="tonal" class="font-weight-medium mb-2">{{ probation.outcome }}</VChip><p v-if="probation.outcome_notes" class="text-body-2 mb-0">{{ probation.outcome_notes }}</p></div>
                </VCardText>
              </VCard>
              <VCard elevation="0" border>
                <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">{{ t('onboarding.reviews') }}<VBadge v-if="probation.reviews.length" :content="probation.reviews.length" color="primary" inline class="ms-2" /></VCardTitle>
                <VCardText class="pa-5 pt-0">
                  <div v-if="probation.reviews.length">
                    <VTimeline side="end" density="compact" align="start">
                      <VTimelineItem v-for="review in probation.reviews" :key="review.id" dot-color="primary" size="small">
                        <template #opposite><span class="text-caption text-medium-emphasis">{{ formatDate(review.review_date) }}</span></template>
                        <VCard elevation="0" border class="pa-4">
                          <div class="d-flex align-center justify-space-between mb-2 flex-wrap gap-2"><span class="text-body-2 font-weight-semibold">{{ reviewTypeLabel(review.review_type) }}</span>
                            <div class="d-flex align-center gap-2">
                              <div v-if="review.performance_score" class="d-flex align-center gap-1"><VIcon v-for="star in 5" :key="star" :icon="star <= (review.performance_score ?? 0) ? 'tabler-star' : 'tabler-star'" size="14" :color="star <= (review.performance_score ?? 0) ? 'warning' : 'default'" /></div>
                              <VChip v-if="review.recommendation" :color="recommendationColor(review.recommendation)" size="x-small" label variant="tonal" class="font-weight-medium">{{ review.recommendation }}</VChip>
                            </div>
                          </div>
                          <div v-if="review.manager_feedback" class="mb-2"><p class="text-caption text-medium-emphasis mb-1">{{ t('onboarding.field_manager_feedback') }}</p><p class="text-body-2 mb-0">{{ review.manager_feedback }}</p></div>
                          <div v-if="review.employee_feedback" class="mb-2"><p class="text-caption text-medium-emphasis mb-1">{{ t('onboarding.field_employee_feedback') }}</p><p class="text-body-2 mb-0">{{ review.employee_feedback }}</p></div>
                          <div v-if="review.areas_of_improvement"><p class="text-caption text-medium-emphasis mb-1">{{ t('onboarding.field_areas_of_improvement') }}</p><p class="text-body-2 mb-0">{{ review.areas_of_improvement }}</p></div>
                        </VCard>
                      </VTimelineItem>
                    </VTimeline>
                  </div>
                  <div v-else class="text-center py-8"><VIcon icon="tabler-clipboard-check" size="48" color="medium-emphasis" class="mb-3 d-block mx-auto" /><p class="text-body-2 text-medium-emphasis mb-4">{{ t('onboarding.no_reviews') }}</p><VBtn size="small" color="primary" variant="tonal" prepend-icon="tabler-plus" @click="reviewDialog = true">{{ t('onboarding.add_first_review') }}</VBtn></div>
                </VCardText>
              </VCard>
            </div>
          </VTabsWindowItem>
        </VTabsWindow>
      </VCol>

      <!-- Sidebar -->
      <VCol cols="12" lg="4">
        <div style="position: sticky; top: 80px;">
          <VCard elevation="0" border class="mb-4">
            <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">{{ t('onboarding.progress') }}</VCardTitle>
            <VCardText class="pa-5 pt-0">
              <div class="text-center mb-4"><p class="text-h3 font-weight-bold mb-0" :style="{ color: plan.status.value === 'completed' ? 'var(--v-theme-success)' : 'var(--v-theme-primary)' }">{{ plan.completion }}%</p><p class="text-caption text-medium-emphasis mb-0">{{ t('onboarding.overall_completion') }}</p></div>
              <VProgressLinear :model-value="plan.completion" :color="plan.status.value === 'completed' ? 'success' : 'primary'" rounded height="10" class="mb-4" />
              <div class="d-flex justify-space-between text-body-2"><span class="text-medium-emphasis">{{ completedCount }} {{ t('onboarding.completed_tasks') }}</span><span class="text-medium-emphasis">{{ plan.tasks.length }} total</span></div>
            </VCardText>
          </VCard>

          <VCard elevation="0" border class="mb-4">
            <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">{{ t('onboarding.plan_details') }}</VCardTitle>
            <VCardText class="pa-5 pt-0">
              <div class="d-flex flex-column gap-3">
                <div class="d-flex align-center justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('onboarding.new_hire_label') }}</span><span class="text-body-2 font-weight-medium">{{ plan.hr_person.candidate?.full_name ?? '—' }}</span></div>
                <VDivider />
                <div class="d-flex align-center justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('onboarding.status_label') }}</span><VChip :color="plan.status.color" size="x-small" label variant="tonal" class="font-weight-medium">{{ plan.status.label }}</VChip></div>
                <VDivider />
                <div class="d-flex align-center justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('onboarding.start_date') }}</span><span class="text-body-2">{{ formatDate(plan.start_date) }}</span></div>
                <div class="d-flex align-center justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('onboarding.end_date') }}</span><span class="text-body-2">{{ formatDate(plan.end_date) }}</span></div>
                <div v-if="plan.completed_at" class="d-flex align-center justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('onboarding.completed') }}</span><span class="text-body-2 text-success">{{ formatDate(plan.completed_at) }}</span></div>
              </div>
            </VCardText>
          </VCard>

          <VCard elevation="0" border class="mb-4">
            <VCardText class="pa-4">
              <div class="d-flex align-center justify-space-between"><div class="d-flex align-center gap-2"><VIcon icon="tabler-file-text" size="18" color="info" /><span class="text-body-2 font-weight-medium">{{ t('onboarding.documents_title') }}</span></div><span class="text-body-2 font-weight-bold">{{ approvedDocCount }} / {{ documents.length }}<span class="text-caption text-medium-emphasis font-weight-regular"> {{ t('onboarding.documents_approved') }}</span></span></div>
              <VProgressLinear v-if="documents.length" :model-value="documents.length ? Math.round((approvedDocCount / documents.length) * 100) : 0" color="info" rounded height="4" class="mt-2" />
            </VCardText>
          </VCard>

          <VCard elevation="0" border class="mb-4">
            <VCardText class="pa-4">
              <div class="d-flex align-center justify-space-between"><div class="d-flex align-center gap-2"><VIcon icon="tabler-laptop" size="18" color="success" /><span class="text-body-2 font-weight-medium">{{ t('onboarding.provisioning_title') }}</span></div><span class="text-body-2 font-weight-bold">{{ provCompleted }} / {{ provisioningTasks.length }}<span class="text-caption text-medium-emphasis font-weight-regular"> {{ t('onboarding.prov_summary_complete') }}</span></span></div>
              <VProgressLinear v-if="provisioningTasks.length" :model-value="provisioningTasks.length ? Math.round((provCompleted / provisioningTasks.length) * 100) : 0" color="success" rounded height="4" class="mt-2" />
            </VCardText>
          </VCard>

          <VCard v-if="probation" elevation="0" border>
            <VCardText class="pa-4">
              <div class="d-flex align-center justify-space-between"><div class="d-flex align-center gap-2"><VIcon icon="tabler-calendar-clock" size="18" color="warning" /><span class="text-body-2 font-weight-medium">{{ t('onboarding.probation_summary') }}</span></div><VChip :color="probation.status.color" size="x-small" label variant="tonal" class="font-weight-medium">{{ probation.status.label }}</VChip></div>
            </VCardText>
          </VCard>
        </div>
      </VCol>
    </VRow>

    <!-- Add Document Dialog -->
    <VDialog v-model="docDialog" max-width="480">
      <VCard>
        <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">{{ t('onboarding.dialog_add_doc_title') }}</VCardTitle>
        <VCardText class="pa-5 pt-2">
          <VTextField v-model="docForm.label" :label="t('onboarding.field_label')" :placeholder="t('onboarding.field_label_placeholder')" variant="outlined" density="comfortable" class="mb-4" hide-details="auto" />
          <VSelect v-model="docForm.document_type" :items="docTypes" :label="t('onboarding.field_document_type')" variant="outlined" density="comfortable" class="mb-4" hide-details="auto" />
          <VSwitch v-model="docForm.is_required" :label="t('onboarding.field_required_document')" color="primary" hide-details />
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2"><VSpacer /><VBtn variant="text" color="default" :disabled="submittingDoc" @click="docDialog = false">{{ t('onboarding.cancel') }}</VBtn><VBtn color="primary" variant="flat" :loading="submittingDoc" :disabled="!docForm.label || !docForm.document_type" @click="submitDoc">{{ t('onboarding.add') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Reject Document Dialog -->
    <VDialog v-model="rejectDialog" max-width="440">
      <VCard>
        <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">{{ t('onboarding.dialog_reject_doc_title') }}</VCardTitle>
        <VCardText class="pa-5 pt-2">
          <p class="text-body-2 text-medium-emphasis mb-3">{{ t('onboarding.dialog_reject_doc_desc', { label: rejectingDoc?.label }) }}</p>
          <VTextarea v-model="rejectForm.rejection_reason" :label="t('onboarding.field_rejection_reason')" variant="outlined" density="comfortable" rows="3" hide-details="auto" />
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2"><VSpacer /><VBtn variant="text" color="default" :disabled="submittingReject" @click="rejectDialog = false">{{ t('onboarding.cancel') }}</VBtn><VBtn color="error" variant="flat" :loading="submittingReject" :disabled="!rejectForm.rejection_reason" @click="submitReject">{{ t('onboarding.reject') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Add Provisioning Task Dialog -->
    <VDialog v-model="provDialog" max-width="520">
      <VCard>
        <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">{{ t('onboarding.dialog_prov_title') }}</VCardTitle>
        <VCardText class="pa-5 pt-2 d-flex flex-column gap-4">
          <VSelect v-model="provForm.type" :items="provisioningTypes" item-title="title" item-value="value" :label="t('onboarding.field_task_type')" variant="outlined" density="comfortable" hide-details="auto" />
          <VTextField v-model="provForm.title" :label="t('onboarding.field_title')" variant="outlined" density="comfortable" hide-details="auto" />
          <VTextarea v-model="provForm.notes" :label="t('onboarding.field_notes_optional')" variant="outlined" density="comfortable" rows="2" hide-details="auto" />
          <VTextField v-model="provForm.assigned_department" :label="t('onboarding.field_assigned_dept_optional')" variant="outlined" density="comfortable" hide-details="auto" />
          <VTextField v-model="provForm.due_date" :label="t('onboarding.field_due_date_optional')" type="date" variant="outlined" density="comfortable" hide-details="auto" />
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2"><VSpacer /><VBtn variant="text" color="default" :disabled="submittingProv" @click="provDialog = false">{{ t('onboarding.cancel') }}</VBtn><VBtn color="primary" variant="flat" :loading="submittingProv" :disabled="!provForm.type || !provForm.title" @click="submitProv">{{ t('onboarding.add_task_btn') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Start Probation Dialog -->
    <VDialog v-model="probationDialog" max-width="440">
      <VCard>
        <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">{{ t('onboarding.dialog_probation_title') }}</VCardTitle>
        <VCardText class="pa-5 pt-2 d-flex flex-column gap-4">
          <VTextField v-model="probationForm.start_date" :label="t('onboarding.start_date')" type="date" variant="outlined" density="comfortable" hide-details="auto" />
          <VSelect v-model="probationForm.duration_months" :items="[{ title: '1 '+t('onboarding.months'), value: 1 }, { title: '2 '+t('onboarding.months'), value: 2 }, { title: '3 '+t('onboarding.months'), value: 3 }, { title: '6 '+t('onboarding.months'), value: 6 }]" :label="t('onboarding.field_duration')" variant="outlined" density="comfortable" hide-details="auto" />
          <VTextarea v-model="probationForm.notes" :label="t('onboarding.field_notes_optional')" variant="outlined" density="comfortable" rows="2" hide-details="auto" />
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2"><VSpacer /><VBtn variant="text" color="default" :disabled="submittingProbation" @click="probationDialog = false">{{ t('onboarding.cancel') }}</VBtn><VBtn color="primary" variant="flat" :loading="submittingProbation" :disabled="!probationForm.start_date" @click="submitProbation">{{ t('onboarding.start') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Add Review Dialog -->
    <VDialog v-model="reviewDialog" max-width="560">
      <VCard>
        <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">{{ t('onboarding.dialog_review_title') }}</VCardTitle>
        <VCardText class="pa-5 pt-2 d-flex flex-column gap-4">
          <VRow dense>
            <VCol cols="12" sm="6"><VTextField v-model="reviewForm.review_date" :label="t('onboarding.field_review_date')" type="date" variant="outlined" density="comfortable" hide-details="auto" /></VCol>
            <VCol cols="12" sm="6"><VSelect v-model="reviewForm.review_type" :items="[{ title: t('onboarding.review_mid'), value: 'mid' }, { title: t('onboarding.review_final'), value: 'final' }, { title: t('onboarding.review_extension'), value: 'extension' }]" :label="t('onboarding.field_review_type')" variant="outlined" density="comfortable" hide-details="auto" /></VCol>
          </VRow>
          <div><p class="text-caption text-medium-emphasis mb-2">{{ t('onboarding.performance_score') }}</p><div class="d-flex gap-1"><VBtn v-for="star in 5" :key="star" variant="text" size="small" :color="star <= (reviewForm.performance_score ?? 0) ? 'warning' : 'default'" :icon="star <= (reviewForm.performance_score ?? 0) ? 'tabler-star' : 'tabler-star'" @click="reviewForm.performance_score = star" /></div></div>
          <VTextarea v-model="reviewForm.manager_feedback" :label="t('onboarding.field_manager_feedback')" variant="outlined" density="comfortable" rows="2" hide-details="auto" />
          <VTextarea v-model="reviewForm.employee_feedback" :label="t('onboarding.field_employee_feedback')" variant="outlined" density="comfortable" rows="2" hide-details="auto" />
          <VTextarea v-model="reviewForm.areas_of_improvement" :label="t('onboarding.field_areas_of_improvement')" variant="outlined" density="comfortable" rows="2" hide-details="auto" />
          <VSelect v-model="reviewForm.recommendation" :items="[{ title: t('onboarding.recommend_confirm'), value: 'confirm' }, { title: t('onboarding.recommend_extend'), value: 'extend' }, { title: t('onboarding.recommend_dismiss'), value: 'dismiss' }]" :label="t('onboarding.field_recommendation_optional')" variant="outlined" density="comfortable" clearable hide-details="auto" />
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2"><VSpacer /><VBtn variant="text" color="default" :disabled="submittingReview" @click="reviewDialog = false">{{ t('onboarding.cancel') }}</VBtn><VBtn color="primary" variant="flat" :loading="submittingReview" :disabled="!reviewForm.review_date || !reviewForm.review_type" @click="submitReview">{{ t('onboarding.save_review') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Close Probation Dialog -->
    <VDialog v-model="closeProbDialog" max-width="440">
      <VCard>
        <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">{{ t('onboarding.dialog_close_probation_title') }}</VCardTitle>
        <VCardText class="pa-5 pt-2 d-flex flex-column gap-4">
          <VSelect v-model="closeForm.outcome" :items="[{ title: t('onboarding.outcome_confirmed'), value: 'confirmed' }, { title: t('onboarding.outcome_extended'), value: 'extended' }, { title: t('onboarding.outcome_dismissed'), value: 'dismissed' }]" :label="t('onboarding.field_outcome')" variant="outlined" density="comfortable" hide-details="auto" />
          <VTextarea v-model="closeForm.outcome_notes" :label="t('onboarding.field_outcome_notes_optional')" variant="outlined" density="comfortable" rows="3" hide-details="auto" />
        </VCardText>
        <VCardActions class="pa-5 pt-0 gap-2"><VSpacer /><VBtn variant="text" color="default" :disabled="submittingClose" @click="closeProbDialog = false">{{ t('onboarding.cancel') }}</VBtn><VBtn color="warning" variant="flat" :loading="submittingClose" :disabled="!closeForm.outcome" @click="submitClose">{{ t('onboarding.close_probation_btn') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
