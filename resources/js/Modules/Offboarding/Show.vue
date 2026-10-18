<script setup lang="ts">
import { computed, ref, reactive, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Task = { id: number; title: string; description: string | null; category: string; due_date: string | null; completed_at: string | null; notes: string | null; sort_order: number; status: { value: string; label: string; color: string } }
type ExitInterview = { id: number; uuid: string; interview_date: string | null; reason_for_leaving: string; satisfaction_score: number | null; would_recommend: boolean | null; strengths: string | null; improvements: string | null; additional_feedback: string | null; is_anonymous: boolean; conducted_by: string | null } | null
type Clearance = { id: number; department: string; status: string; signed_off_by: string | null; signed_off_at: string | null; notes: string | null }
type CurrentAsset = { id: number; name: string; tag: string | null; category: string | null; location: string | null }

const props = defineProps<{
  plan: { id: number; uuid: string; status: { value: string; label: string; color: string }; termination_type: string; resignation_date: string | null; last_working_day: string | null; notes: string | null; completion: number; template: { id: number; title: string } | null; rehire_eligible: boolean | null; rehire_notes: string | null; counteroffer_made: boolean | null; counteroffer_outcome: string | null; hr_person: { id: number; candidate: { full_name: string; email: string } | null } }
  tasks_by_category: Record<string, Task[]>; exit_interview: ExitInterview; clearances: Clearance[]; current_assets: CurrentAsset[]
}>()

function formatDate(val: string | null) { if (!val) return '—'; return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' }) }
function terminationLabel(val: string) { const map: Record<string, string> = { resignation: t('offboarding.termination_resignation'), retrenchment: t('offboarding.termination_retrenchment'), dismissal: t('offboarding.termination_dismissal'), retirement: t('offboarding.termination_retirement'), contract_end: t('offboarding.termination_contract_end'), death: t('offboarding.termination_death') }; return map[val] ?? val }
function categoryLabel(val: string) { const map: Record<string, string> = { hr: t('offboarding.hr'), it: t('offboarding.it'), finance: t('offboarding.finance'), facilities: t('offboarding.facilities'), manager: t('offboarding.manager') }; return map[val] ?? val }
function categoryColor(val: string) { if (val === 'hr') return 'primary'; if (val === 'it') return 'info'; if (val === 'finance') return 'success'; if (val === 'facilities') return 'warning'; if (val === 'manager') return 'error'; return 'default' }
function categoryIcon(val: string) { if (val === 'hr') return 'tabler-users'; if (val === 'it') return 'tabler-laptop'; if (val === 'finance') return 'tabler-cash'; if (val === 'facilities') return 'tabler-building'; if (val === 'manager') return 'tabler-user-tie'; return 'tabler-clipboard-list' }
function reasonLabel(val: string) { const map: Record<string, string> = { better_opportunity: t('offboarding.reason_better_opportunity'), salary: t('offboarding.reason_salary'), work_environment: t('offboarding.reason_work_environment'), management: t('offboarding.reason_management'), relocation: t('offboarding.reason_relocation'), personal: t('offboarding.reason_personal'), retirement: t('offboarding.reason_retirement'), contract_end: t('offboarding.reason_contract_end'), other: t('offboarding.reason_other') }; return map[val] ?? val }

const allTasks = computed(() => Object.values(props.tasks_by_category).flat())
const completedCount = computed(() => allTasks.value.filter(t => t.status.value === 'completed' || t.status.value === 'skipped').length)
const pendingCount = computed(() => allTasks.value.filter(t => t.status.value === 'pending' || t.status.value === 'in_progress').length)

const completingTask = ref<number | null>(null)
function completeTask(task: Task, skip = false) { completingTask.value = task.id; router.post(route('offboarding.persons.plans.tasks.complete', [props.plan.hr_person.id, props.plan.id, task.id]), { skip: skip ? 1 : 0 }, { preserveScroll: true, onFinish: () => { completingTask.value = null } }) }

const showExitForm = ref(!props.exit_interview); const submittingInterview = ref(false)
const interviewForm = reactive({ interview_date: props.exit_interview?.interview_date ?? '', reason_for_leaving: props.exit_interview?.reason_for_leaving ?? '', satisfaction_score: props.exit_interview?.satisfaction_score ?? null as number | null, would_recommend: props.exit_interview?.would_recommend ?? null as boolean | null, strengths: props.exit_interview?.strengths ?? '', improvements: props.exit_interview?.improvements ?? '', additional_feedback: props.exit_interview?.additional_feedback ?? '', is_anonymous: props.exit_interview?.is_anonymous ?? false })
function submitExitInterview() { submittingInterview.value = true; router.post(route('offboarding.persons.plans.exit-interview.store', [props.plan.hr_person.id, props.plan.id]), { ...interviewForm }, { preserveScroll: true, onFinish: () => { submittingInterview.value = false; showExitForm.value = false } }) }

const reasonOptions = computed(() => [
  { title: t('offboarding.reason_better_opportunity'), value: 'better_opportunity' }, { title: t('offboarding.reason_salary'), value: 'salary' },
  { title: t('offboarding.reason_work_environment'), value: 'work_environment' }, { title: t('offboarding.reason_management'), value: 'management' },
  { title: t('offboarding.reason_relocation'), value: 'relocation' }, { title: t('offboarding.reason_personal'), value: 'personal' },
  { title: t('offboarding.reason_retirement'), value: 'retirement' }, { title: t('offboarding.reason_contract_end'), value: 'contract_end' }, { title: t('offboarding.reason_other'), value: 'other' },
])

const showDonePerCategory = ref<Record<string, boolean>>({})
function toggleDone(cat: string) { showDonePerCategory.value[cat] = !showDonePerCategory.value[cat] }
function pendingTasksFor(tasks: Task[]) { return tasks.filter(t => t.status.value === 'pending' || t.status.value === 'in_progress') }
function doneTasksFor(tasks: Task[]) { return tasks.filter(t => t.status.value === 'completed' || t.status.value === 'skipped') }

const signingOff = ref<number | null>(null); const clearanceNote = ref('')
const clearanceDialog = ref(false); const selectedClearance = ref<Clearance | null>(null)
const allCleared = computed(() => props.clearances.length > 0 && props.clearances.every(c => c.status === 'cleared'))
function openSignOff(c: Clearance) { selectedClearance.value = c; clearanceNote.value = ''; clearanceDialog.value = true }
function submitSignOff() { if (!selectedClearance.value) return; signingOff.value = selectedClearance.value.id; router.post(route('offboarding.persons.plans.clearances.sign-off', [props.plan.hr_person.id, props.plan.id, selectedClearance.value.id]), { notes: clearanceNote.value || undefined }, { preserveScroll: true, onFinish: () => { signingOff.value = null; clearanceDialog.value = false } }) }

const showExtraDetails = ref(false); const savingDetails = ref(false)
const detailsForm = reactive({ rehire_eligible: props.plan.rehire_eligible, rehire_notes: props.plan.rehire_notes ?? '', counteroffer_made: props.plan.counteroffer_made, counteroffer_outcome: props.plan.counteroffer_outcome ?? '' })
function saveDetails() { savingDetails.value = true; router.put(route('offboarding.persons.plans.details.update', [props.plan.hr_person.id, props.plan.id]), { ...detailsForm }, { preserveScroll: true, onFinish: () => { savingDetails.value = false; showExtraDetails.value = false } }) }
</script>

<template>
  <div class="pa-6">
    <div class="d-flex align-center gap-2 mb-4">
      <VBtn icon="tabler-arrow-left" variant="text" size="small" @click="router.visit(route('offboarding.index'))" />
      <span class="text-medium-emphasis text-body-2 cursor-pointer" @click="router.visit(route('offboarding.index'))">{{ t('offboarding.plan_breadcrumb_offboarding') }}</span>
      <VIcon icon="tabler-chevron-right" size="16" class="text-medium-emphasis" />
      <span class="text-body-2 font-weight-medium">{{ plan.hr_person.candidate?.full_name ?? t('offboarding.unknown') }}</span>
    </div>

    <div class="d-flex align-start justify-space-between mb-5">
      <div>
        <div class="d-flex align-center gap-2 mb-1"><h4 class="text-h4 font-weight-bold">{{ t('offboarding.plan_title') }}</h4><VChip :color="plan.status.color" size="small" label variant="tonal" class="font-weight-medium">{{ plan.status.label }}</VChip></div>
        <p class="text-medium-emphasis mb-0">{{ plan.hr_person.candidate?.full_name ?? t('offboarding.unknown') }} · {{ terminationLabel(plan.termination_type) }}<template v-if="plan.last_working_day"> · {{ t('offboarding.plan_last_day') }} {{ formatDate(plan.last_working_day) }}</template></p>
      </div>
      <VBtn variant="tonal" color="default" size="small" prepend-icon="tabler-chart-bar" @click="router.visit(route('offboarding.analytics'))">{{ t('offboarding.plan_analytics_btn') }}</VBtn>
    </div>

    <VCard elevation="0" border class="mb-5">
      <VCardText class="pa-4">
        <div class="d-flex align-center justify-space-between mb-2"><span class="text-body-2 font-weight-medium">{{ t('offboarding.plan_overall_progress') }}</span><span class="text-body-2 font-weight-bold" :style="{ color: plan.status.value === 'completed' ? 'var(--v-theme-success)' : 'var(--v-theme-primary)' }">{{ plan.completion }}%</span></div>
        <VProgressLinear :model-value="plan.completion" :color="plan.status.value === 'completed' ? 'success' : 'primary'" rounded height="10" />
        <div class="d-flex gap-4 mt-2 text-caption text-medium-emphasis"><span>{{ completedCount }} {{ t('offboarding.plan_completed') }}</span><span>{{ pendingCount }} {{ t('offboarding.plan_remaining') }}</span><span>{{ allTasks.length }} {{ t('offboarding.plan_total') }}</span></div>
      </VCardText>
    </VCard>

    <VRow>
      <VCol cols="12" lg="8">
        <div class="d-flex flex-column gap-4">
          <div v-for="(tasks, category) in tasks_by_category" :key="category">
            <VCard elevation="0" border>
              <VCardTitle class="pa-4 pb-2 d-flex align-center gap-2">
                <VAvatar size="32" :color="categoryColor(String(category))" variant="tonal"><VIcon :icon="categoryIcon(String(category))" size="18" /></VAvatar>
                <span class="text-body-1 font-weight-semibold">{{ categoryLabel(String(category)) }}</span>
                <VChip size="x-small" :color="categoryColor(String(category))" variant="tonal" label class="ms-1">{{ pendingTasksFor(tasks).length }} / {{ tasks.length }}</VChip>
              </VCardTitle>
              <VCardText class="pa-4 pt-0">
                <div v-if="pendingTasksFor(tasks).length" class="d-flex flex-column gap-3 mb-2">
                  <div v-for="task in pendingTasksFor(tasks)" :key="task.id" class="d-flex align-start gap-3 pa-3 rounded-lg" style="border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));">
                    <div class="flex-grow-1"><p class="text-body-2 font-weight-medium mb-1">{{ task.title }}</p><p v-if="task.description" class="text-caption text-medium-emphasis mb-1">{{ task.description }}</p><div class="d-flex align-center gap-2 flex-wrap"><VChip :color="task.status.color" size="x-small" label variant="tonal">{{ task.status.label }}</VChip><span v-if="task.due_date" class="text-caption text-medium-emphasis">{{ t('offboarding.plan_due') }} {{ formatDate(task.due_date) }}</span></div></div>
                    <div class="d-flex gap-1 flex-shrink-0"><VBtn size="x-small" color="success" variant="tonal" :loading="completingTask === task.id" @click="completeTask(task, false)">{{ t('offboarding.plan_complete') }}</VBtn><VBtn size="x-small" color="default" variant="text" :loading="completingTask === task.id" @click="completeTask(task, true)">{{ t('offboarding.plan_skip') }}</VBtn></div>
                  </div>
                </div>
                <div v-if="doneTasksFor(tasks).length">
                  <VDivider v-if="pendingTasksFor(tasks).length" class="mb-2" />
                  <VBtn variant="text" color="default" size="small" :prepend-icon="showDonePerCategory[String(category)] ? 'tabler-chevron-up' : 'tabler-chevron-down'" @click="toggleDone(String(category))">{{ showDonePerCategory[String(category)] ? t('offboarding.plan_hide_done') : t('offboarding.plan_show_done') }} ({{ doneTasksFor(tasks).length }})</VBtn>
                  <div v-if="showDonePerCategory[String(category)]" class="d-flex flex-column gap-2 mt-2">
                    <div v-for="task in doneTasksFor(tasks)" :key="task.id" class="d-flex align-center gap-3 pa-3 rounded-lg opacity-60" style="border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));">
                      <VIcon :icon="task.status.value === 'completed' ? 'tabler-circle-check' : 'tabler-circle-minus'" :color="task.status.color" size="18" class="flex-shrink-0" />
                      <p class="text-body-2 font-weight-medium mb-0 flex-grow-1 text-decoration-line-through">{{ task.title }}</p>
                      <VChip :color="task.status.color" size="x-small" label variant="tonal">{{ task.status.label }}</VChip>
                    </div>
                  </div>
                </div>
                <div v-if="!pendingTasksFor(tasks).length && !doneTasksFor(tasks).length" class="text-center py-4"><p class="text-caption text-medium-emphasis mb-0">{{ t('offboarding.plan_no_tasks_category') }}</p></div>
              </VCardText>
            </VCard>
          </div>
          <VCard v-if="!Object.keys(tasks_by_category).length" elevation="0" border><VCardText class="text-center py-10"><VIcon icon="tabler-clipboard-list" size="52" color="medium-emphasis" class="mb-3 d-block mx-auto" /><p class="text-body-2 font-weight-medium mb-1">{{ t('offboarding.plan_no_tasks') }}</p><p class="text-caption text-medium-emphasis mb-0">{{ t('offboarding.plan_no_tasks_sub') }}</p></VCardText></VCard>

          <VCard elevation="0" border>
            <VCardTitle class="pa-4 pb-2 d-flex align-center gap-2"><VAvatar size="32" color="secondary" variant="tonal"><VIcon icon="tabler-clipboard-check" size="18" /></VAvatar><span class="text-body-1 font-weight-semibold">{{ t('offboarding.plan_clearances') }}</span><VChip v-if="allCleared" size="x-small" color="success" variant="tonal" label class="ms-1">{{ t('offboarding.plan_all_cleared') }}</VChip><VChip v-else size="x-small" color="warning" variant="tonal" label class="ms-1">{{ clearances.filter(c => c.status === 'cleared').length }} / {{ clearances.length }}</VChip></VCardTitle>
            <VCardText class="pa-4 pt-0">
              <div v-if="clearances.length" class="d-flex flex-column gap-2">
                <div v-for="c in clearances" :key="c.id" class="d-flex align-center gap-3 pa-3 rounded-lg" style="border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));">
                  <VIcon :icon="c.status === 'cleared' ? 'tabler-circle-check' : 'tabler-circle-dashed'" :color="c.status === 'cleared' ? 'success' : 'default'" size="20" class="flex-shrink-0" />
                  <div class="flex-grow-1"><p class="text-body-2 font-weight-medium mb-0">{{ categoryLabel(c.department) }}</p><p v-if="c.signed_off_by" class="text-caption text-medium-emphasis mb-0">{{ t('offboarding.plan_cleared_by_on', { name: c.signed_off_by, date: formatDate(c.signed_off_at) }) }}</p><p v-if="c.notes" class="text-caption text-medium-emphasis mb-0">{{ c.notes }}</p></div>
                  <VChip :color="c.status === 'cleared' ? 'success' : 'warning'" size="x-small" label variant="tonal">{{ c.status === 'cleared' ? t('offboarding.plan_cleared') : t('offboarding.plan_pending_clearance') }}</VChip>
                  <VBtn v-if="c.status !== 'cleared'" size="x-small" color="success" variant="tonal" @click="openSignOff(c)">{{ t('offboarding.plan_sign_off') }}</VBtn>
                </div>
              </div>
              <p v-else class="text-caption text-medium-emphasis text-center py-4 mb-0">{{ t('offboarding.plan_no_clearances') }}</p>
            </VCardText>
          </VCard>

          <VCard elevation="0" border>
            <VCardTitle class="pa-4 pb-2 d-flex align-center gap-2"><VAvatar size="32" color="info" variant="tonal"><VIcon icon="tabler-devices" size="18" /></VAvatar><span class="text-body-1 font-weight-semibold">{{ t('offboarding.plan_assets') }}</span><VChip size="x-small" :color="current_assets.length ? 'warning' : 'default'" variant="tonal" label class="ms-1">{{ current_assets.length }} {{ t('offboarding.plan_assets_active') }}</VChip></VCardTitle>
            <VCardText class="pa-4 pt-0">
              <div v-if="current_assets.length" class="d-flex flex-column gap-2">
                <div v-for="a in current_assets" :key="a.id" class="d-flex align-center gap-3 pa-3 rounded-lg" style="border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));"><VIcon icon="tabler-device-laptop" color="info" size="20" class="flex-shrink-0" /><div class="flex-grow-1"><p class="text-body-2 font-weight-medium mb-0">{{ a.name }}</p><p class="text-caption text-medium-emphasis mb-0"><template v-if="a.tag">{{ a.tag }}</template><template v-if="a.category"> · {{ a.category }}</template><template v-if="a.location"> · {{ a.location }}</template></p></div></div>
                <div class="mt-2 pa-3 rounded-lg bg-info-subtle"><p class="text-caption text-info mb-0"><VIcon icon="tabler-info-circle" size="14" class="me-1" />{{ t('offboarding.plan_assets_info') }}</p></div>
              </div>
              <div v-else class="text-center py-6"><VIcon icon="tabler-device-laptop-off" size="36" color="medium-emphasis" class="mb-2 d-block mx-auto" /><p class="text-caption text-medium-emphasis mb-0">{{ t('offboarding.plan_assets_no') }}</p></div>
            </VCardText>
          </VCard>
        </div>
      </VCol>

      <VCol cols="12" lg="4">
        <div style="position: sticky; top: 80px;" class="d-flex flex-column gap-4">
          <VCard elevation="0" border>
            <VCardTitle class="pa-5 pb-2 d-flex align-center justify-space-between"><span class="text-body-1 font-weight-semibold">{{ t('offboarding.plan_details') }}</span><VBtn size="small" variant="text" color="default" prepend-icon="tabler-pencil" @click="showExtraDetails = !showExtraDetails">{{ t('offboarding.plan_edit') }}</VBtn></VCardTitle>
            <VCardText class="pa-5 pt-0">
              <div class="d-flex flex-column gap-3">
                <div class="d-flex align-center justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('offboarding.plan_employee_label') }}</span><span class="text-body-2 font-weight-medium">{{ plan.hr_person.candidate?.full_name ?? '—' }}</span></div><VDivider />
                <div class="d-flex align-center justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('offboarding.table_status') }}</span><VChip :color="plan.status.color" size="x-small" label variant="tonal">{{ plan.status.label }}</VChip></div><VDivider />
                <div class="d-flex align-center justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('offboarding.table_termination_type') }}</span><span class="text-body-2">{{ terminationLabel(plan.termination_type) }}</span></div>
                <div v-if="plan.resignation_date" class="d-flex align-center justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('offboarding.plan_notice_date') }}</span><span class="text-body-2">{{ formatDate(plan.resignation_date) }}</span></div>
                <div v-if="plan.last_working_day" class="d-flex align-center justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('offboarding.plan_last_day') }}</span><span class="text-body-2 font-weight-medium text-error">{{ formatDate(plan.last_working_day) }}</span></div>
                <div v-if="plan.template" class="d-flex align-center justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('offboarding.plan_template') }}</span><span class="text-body-2">{{ plan.template.title }}</span></div>
                <template v-if="plan.rehire_eligible !== null || plan.counteroffer_made !== null"><VDivider />
                  <div v-if="plan.rehire_eligible !== null" class="d-flex align-center justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('offboarding.plan_rehire_eligible') }}</span><VChip :color="plan.rehire_eligible ? 'success' : 'error'" size="x-small" label variant="tonal">{{ plan.rehire_eligible ? t('offboarding.plan_exit_yes') : t('offboarding.plan_exit_no') }}</VChip></div>
                  <div v-if="plan.rehire_notes" class="d-flex flex-column gap-1"><span class="text-caption text-medium-emphasis">{{ t('offboarding.plan_rehire_notes') }}</span><span class="text-body-2">{{ plan.rehire_notes }}</span></div>
                  <div v-if="plan.counteroffer_made !== null" class="d-flex align-center justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('offboarding.plan_counteroffer_made') }}</span><VChip :color="plan.counteroffer_made ? 'info' : 'default'" size="x-small" label variant="tonal">{{ plan.counteroffer_made ? t('offboarding.plan_exit_yes') : t('offboarding.plan_exit_no') }}</VChip></div>
                  <div v-if="plan.counteroffer_outcome" class="d-flex align-center justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('offboarding.plan_counteroffer_outcome') }}</span><VChip :color="plan.counteroffer_outcome === 'accepted' ? 'success' : 'error'" size="x-small" label variant="tonal">{{ plan.counteroffer_outcome === 'accepted' ? t('offboarding.plan_counteroffer_accepted') : t('offboarding.plan_counteroffer_declined') }}</VChip></div>
                </template>
                <div v-if="plan.notes"><VDivider class="mb-3" /><p class="text-caption text-medium-emphasis mb-1">{{ t('offboarding.plan_notes') }}</p><p class="text-body-2 mb-0">{{ plan.notes }}</p></div>
              </div>
              <div v-if="showExtraDetails" class="mt-4 d-flex flex-column gap-3">
                <VDivider /><p class="text-caption font-weight-semibold text-medium-emphasis mb-0">{{ t('offboarding.plan_rehire_counteroffer') }}</p>
                <VSelect v-model="detailsForm.rehire_eligible" :items="[{ title: t('offboarding.plan_exit_yes'), value: true }, { title: t('offboarding.plan_exit_no'), value: false }]" :label="t('offboarding.plan_field_rehire_eligible')" variant="outlined" density="comfortable" clearable hide-details="auto" />
                <VTextarea v-model="detailsForm.rehire_notes" :label="t('offboarding.plan_field_rehire_notes')" variant="outlined" density="comfortable" rows="2" hide-details="auto" />
                <template v-if="plan.termination_type === 'resignation'"><VSelect v-model="detailsForm.counteroffer_made" :items="[{ title: t('offboarding.plan_exit_yes'), value: true }, { title: t('offboarding.plan_exit_no'), value: false }]" :label="t('offboarding.plan_field_counteroffer_made')" variant="outlined" density="comfortable" clearable hide-details="auto" /><VSelect v-if="detailsForm.counteroffer_made" v-model="detailsForm.counteroffer_outcome" :items="[{ title: t('offboarding.plan_counteroffer_accepted'), value: 'accepted' }, { title: t('offboarding.plan_counteroffer_declined'), value: 'declined' }]" :label="t('offboarding.plan_field_counteroffer_outcome')" variant="outlined" density="comfortable" hide-details="auto" /></template>
                <div class="d-flex gap-2 justify-end"><VBtn variant="text" color="default" size="small" @click="showExtraDetails = false">{{ t('offboarding.cancel') }}</VBtn><VBtn color="primary" variant="flat" size="small" :loading="savingDetails" @click="saveDetails">{{ t('offboarding.plan_save') }}</VBtn></div>
              </div>
            </VCardText>
          </VCard>

          <VCard elevation="0" border>
            <VCardTitle class="pa-5 pb-2 d-flex align-center justify-space-between"><span class="text-body-1 font-weight-semibold">{{ t('offboarding.plan_exit_interview') }}</span><VBtn v-if="exit_interview && !showExitForm" size="small" color="default" variant="text" prepend-icon="tabler-pencil" @click="showExitForm = true">{{ t('offboarding.plan_exit_edit') }}</VBtn></VCardTitle>
            <VCardText class="pa-5 pt-0">
              <div v-if="exit_interview && !showExitForm" class="d-flex flex-column gap-3">
                <div class="d-flex align-center justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('offboarding.plan_exit_reason') }}</span><span class="text-body-2 font-weight-medium">{{ reasonLabel(exit_interview.reason_for_leaving) }}</span></div><VDivider />
                <div v-if="exit_interview.interview_date" class="d-flex align-center justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('offboarding.plan_exit_date') }}</span><span class="text-body-2">{{ formatDate(exit_interview.interview_date) }}</span></div>
                <div v-if="exit_interview.satisfaction_score !== null" class="d-flex align-center justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('offboarding.plan_exit_satisfaction') }}</span><div class="d-flex gap-1"><VIcon v-for="star in 5" :key="star" icon="tabler-star" size="14" :color="star <= (exit_interview.satisfaction_score ?? 0) ? 'warning' : 'default'" /></div></div>
                <div v-if="exit_interview.would_recommend !== null" class="d-flex align-center justify-space-between"><span class="text-caption text-medium-emphasis">{{ t('offboarding.plan_exit_would_recommend') }}</span><VChip :color="exit_interview.would_recommend ? 'success' : 'error'" size="x-small" label variant="tonal">{{ exit_interview.would_recommend ? t('offboarding.plan_exit_yes') : t('offboarding.plan_exit_no') }}</VChip></div>
                <div v-if="exit_interview.strengths"><VDivider class="mb-2" /><p class="text-caption text-medium-emphasis mb-1">{{ t('offboarding.plan_exit_strengths') }}</p><p class="text-body-2 mb-0">{{ exit_interview.strengths }}</p></div>
                <div v-if="exit_interview.improvements"><p class="text-caption text-medium-emphasis mb-1 mt-2">{{ t('offboarding.plan_exit_improvements') }}</p><p class="text-body-2 mb-0">{{ exit_interview.improvements }}</p></div>
                <VChip v-if="exit_interview.is_anonymous" size="x-small" color="default" variant="tonal" label class="mt-1">{{ t('offboarding.plan_exit_anonymous_chip') }}</VChip>
              </div>
              <div v-else class="d-flex flex-column gap-3">
                <VSelect v-model="interviewForm.reason_for_leaving" :items="reasonOptions" :label="t('offboarding.plan_exit_reason')" variant="outlined" density="comfortable" hide-details="auto" />
                <VTextField v-model="interviewForm.interview_date" :label="t('offboarding.plan_exit_date')" type="date" variant="outlined" density="comfortable" hide-details="auto" />
                <div><p class="text-caption text-medium-emphasis mb-2">{{ t('offboarding.plan_exit_satisfaction') }}</p><div class="d-flex gap-1"><VBtn v-for="star in 5" :key="star" variant="text" size="small" :color="star <= (interviewForm.satisfaction_score ?? 0) ? 'warning' : 'default'" icon="tabler-star" @click="interviewForm.satisfaction_score = star" /></div></div>
                <VSelect v-model="interviewForm.would_recommend" :items="[{ title: t('offboarding.plan_exit_yes'), value: true }, { title: t('offboarding.plan_exit_no'), value: false }]" :label="t('offboarding.plan_exit_would_recommend')" variant="outlined" density="comfortable" clearable hide-details="auto" />
                <VTextarea v-model="interviewForm.strengths" :label="t('offboarding.plan_exit_strengths')" variant="outlined" density="comfortable" rows="2" hide-details="auto" />
                <VTextarea v-model="interviewForm.improvements" :label="t('offboarding.plan_exit_improvements')" variant="outlined" density="comfortable" rows="2" hide-details="auto" />
                <VTextarea v-model="interviewForm.additional_feedback" :label="t('offboarding.plan_exit_additional')" variant="outlined" density="comfortable" rows="2" hide-details="auto" />
                <VSwitch v-model="interviewForm.is_anonymous" :label="t('offboarding.plan_exit_anonymous')" color="primary" hide-details density="comfortable" />
                <div class="d-flex gap-2 justify-end"><VBtn v-if="exit_interview" variant="text" color="default" :disabled="submittingInterview" @click="showExitForm = false">{{ t('offboarding.cancel') }}</VBtn><VBtn color="primary" variant="flat" :loading="submittingInterview" :disabled="!interviewForm.reason_for_leaving" @click="submitExitInterview">{{ exit_interview ? t('offboarding.plan_exit_update') : t('offboarding.plan_exit_submit') }}</VBtn></div>
              </div>
            </VCardText>
          </VCard>
        </div>
      </VCol>
    </VRow>

    <VDialog v-model="clearanceDialog" max-width="440">
      <VCard>
        <VCardTitle class="pa-5 pb-2 text-body-1 font-weight-semibold">{{ t('offboarding.plan_dialog_signoff_title', { department: selectedClearance ? categoryLabel(selectedClearance.department) : '' }) }}</VCardTitle>
        <VCardText class="pa-5 pt-2"><VTextarea v-model="clearanceNote" :label="t('offboarding.plan_dialog_signoff_notes')" variant="outlined" density="comfortable" rows="3" hide-details="auto" /></VCardText>
        <VCardActions class="pa-5 pt-0 gap-2"><VSpacer /><VBtn variant="text" color="default" @click="clearanceDialog = false">{{ t('offboarding.cancel') }}</VBtn><VBtn color="success" variant="flat" :loading="signingOff !== null" @click="submitSignOff">{{ t('offboarding.plan_dialog_signoff_confirm') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
