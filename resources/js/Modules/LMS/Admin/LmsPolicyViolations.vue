<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  violations: any
  exceptions: any
  enforcementLogs: any[]
  violationTypes: Record<string, string>
  filters: Record<string, any>
}>()

const activeTab          = ref('violations')
const resolveDialog      = ref(false)
const exceptionDialog    = ref(false)
const resolving          = ref(false)
const submittingEx       = ref(false)
const snackbar           = ref(false)
const snackbarText       = ref('')
const resolvingItem      = ref<any>(null)
const resolutionNote     = ref('')

const filterViolationType = ref(props.filters.violation_type ?? null)
const filterActionTaken   = ref(props.filters.action_taken ?? null)
const filterResolved      = ref(props.filters.resolved ?? 'false')

const exForm = ref({ user_id: null as number | null, context_type: 'enrollment', context_id: null as number | null, reason: '', valid_until: '' })

const pendingExceptionsCount = computed(() => props.exceptions.data?.filter((e: any) => e.status === 'pending').length ?? 0)

const violationTypeItems = computed(() => Object.entries(props.violationTypes).map(([id, name]) => ({ id, name })))

const actionItems = computed(() =>  [
  { id: null, name: t('lms_policy_enforcement.violations.all_actions') },
  { id: 'blocked', name: t('lms_policy_enforcement.violations.actions.blocked') },
  { id: 'warned', name: t('lms_policy_enforcement.violations.actions.warned') },
  { id: 'escalated', name: t('lms_policy_enforcement.violations.actions.escalated') },
  { id: 'logged', name: t('lms_policy_enforcement.violations.actions.logged') },
])

const contextTypeItems = computed(() => [
  { value: 'enrollment', label: t('lms_policy_enforcement.exceptions.dialog.context_types.enrollment') },
  { value: 'assignment', label: t('lms_policy_enforcement.exceptions.dialog.context_types.assignment') },
  { value: 'certificate', label: t('lms_policy_enforcement.exceptions.dialog.context_types.certificate') },
  { value: 'mandatory_training', label: t('lms_policy_enforcement.exceptions.dialog.context_types.mandatory_training') },
])

const violationHeaders = computed(() => [
  { title: t('lms_policy_enforcement.violations.table.rule'),         key: 'rule_name',      sortable: false },
  { title: t('lms_policy_enforcement.violations.table.type'),         key: 'violation_type', sortable: false },
  { title: t('lms_policy_enforcement.violations.table.severity'),     key: 'severity',       sortable: false },
  { title: t('lms_policy_enforcement.violations.table.action_taken'), key: 'action_taken',   sortable: false },
  { title: t('lms_policy_enforcement.violations.table.actor'),        key: 'actor',          sortable: false },
  { title: t('lms_policy_enforcement.violations.table.status'),       key: 'is_resolved',    sortable: false },
  { title: t('lms_policy_enforcement.violations.table.date'),         key: 'created_at',     sortable: true },
  { title: '', key: 'actions', sortable: false, align: 'end' },
])

const exceptionHeaders = computed(() =>  [
  { title: t('lms_policy_enforcement.exceptions.table.employee'),    key: 'user',        sortable: false },
  { title: t('lms_policy_enforcement.exceptions.table.context'),     key: 'context_type',sortable: false },
  { title: t('lms_policy_enforcement.exceptions.table.reason'),      key: 'reason',      sortable: false },
  { title: t('lms_policy_enforcement.exceptions.table.status'),      key: 'status',      sortable: false },
  { title: t('lms_policy_enforcement.exceptions.table.valid_until'), key: 'valid_until', sortable: false },
  { title: '', key: 'actions', sortable: false, align: 'end' },
])

function personName(user: any) { const p = user?.person; return p ? `${p.first_name} ${p.surname}` : user?.email ?? '—' }
function actorName(actor: any) { return personName(actor) }
function severityColor(severity: string) { const map: Record<string, string> = { critical: 'error', high: 'deep-orange', medium: 'warning', low: 'info' }; return map[severity] ?? 'grey' }
function actionColor(action: string) { const map: Record<string, string> = { blocked: 'error', escalated: 'warning', warned: 'orange', logged: 'grey' }; return map[action] ?? 'grey' }
function exceptionStatusColor(status: string) { const map: Record<string, string> = { approved: 'success', pending: 'warning', rejected: 'error', expired: 'grey', revoked: 'deep-orange' }; return map[status] ?? 'grey' }

function applyFilters() { router.get(route('lms.admin.policy-violations'), { violation_type: filterViolationType.value, action_taken: filterActionTaken.value, resolved: filterResolved.value }, { preserveState: true, replace: true }) }
function goViolationPage(page: number) { router.get(route('lms.admin.policy-violations'), { ...props.filters, page }, { preserveState: true }) }
function goExceptionPage(page: number) { router.get(route('lms.admin.policy-violations'), { ...props.filters, exception_page: page }, { preserveState: true }) }

function openResolve(item: any) { resolvingItem.value = item; resolutionNote.value = ''; resolveDialog.value = true }
function submitResolve() {
  if (!resolvingItem.value || !resolutionNote.value) return; resolving.value = true
  router.patch(route('lms.admin.policy-violations.resolve', resolvingItem.value.id), { resolution_note: resolutionNote.value }, { onSuccess: () => { snackbarText.value = t('lms_policy_enforcement.toast.violation_resolved'); snackbar.value = true; resolveDialog.value = false }, onFinish: () => { resolving.value = false }, preserveScroll: true })
}

function decideException(item: any, decision: string) { router.patch(route('lms.admin.policy-exceptions.decide', item.id), { decision }, { onSuccess: () => { snackbarText.value = decision === 'approved' ? t('lms_policy_enforcement.toast.exception_approved') : t('lms_policy_enforcement.toast.exception_rejected'); snackbar.value = true }, preserveScroll: true }) }

function submitException() {
  if (!exForm.value.reason || !exForm.value.user_id) return; submittingEx.value = true
  router.post(route('lms.admin.policy-exceptions.request'), exForm.value, { onSuccess: () => { snackbarText.value = t('lms_policy_enforcement.toast.exception_requested'); snackbar.value = true; exceptionDialog.value = false }, onFinish: () => { submittingEx.value = false }, preserveScroll: true })
}
</script>

<template>
  <div class="p-4">
    <div class="d-flex align-center gap-3 mb-4 flex-wrap">
      <div><div class="text-h6">{{ t('lms_policy_enforcement.title') }}</div><div class="text-body-2 text-medium-emphasis">{{ t('lms_policy_enforcement.subtitle') }}</div></div>
    </div>

    <VTabs v-model="activeTab" class="mb-4">
      <VTab value="violations">{{ t('lms_policy_enforcement.tabs.violations') }}<VChip v-if="violations.total" size="x-small" color="error" class="ml-2">{{ violations.total }}</VChip></VTab>
      <VTab value="exceptions">{{ t('lms_policy_enforcement.tabs.exceptions') }}<VChip v-if="pendingExceptionsCount" size="x-small" color="warning" class="ml-2">{{ pendingExceptionsCount }}</VChip></VTab>
      <VTab value="logs">{{ t('lms_policy_enforcement.tabs.logs') }}</VTab>
    </VTabs>

    <VWindow v-model="activeTab">
      <VWindowItem value="violations">
        <div class="d-flex gap-3 mb-4 flex-wrap">
          <VSelect v-model="filterViolationType" :items="[{ id: null, name: t('lms_policy_enforcement.violations.all_types') }, ...violationTypeItems]" item-title="name" item-value="id" density="compact" hide-details style="max-width:220px" :label="t('lms_policy_enforcement.violations.filter_type')" @update:model-value="applyFilters" />
          <VSelect v-model="filterActionTaken" :items="actionItems" item-title="name" item-value="id" density="compact" hide-details style="max-width:160px" :label="t('lms_policy_enforcement.violations.filter_action')" @update:model-value="applyFilters" />
          <VBtnToggle v-model="filterResolved" density="compact" @update:model-value="applyFilters"><VBtn value="false" size="small">{{ t('lms_policy_enforcement.violations.open') }}</VBtn><VBtn value="true" size="small">{{ t('lms_policy_enforcement.violations.resolved') }}</VBtn></VBtnToggle>
        </div>
        <VCard>
          <VDataTable :headers="violationHeaders" :items="violations.data" hide-default-footer density="compact">
            <template #item.violation_type="{ item }"><span class="text-caption">{{ violationTypes[item.violation_type] ?? item.violation_type }}</span></template>
            <template #item.severity="{ item }"><VChip :color="severityColor(item.severity)" size="x-small">{{ item.severity }}</VChip></template>
            <template #item.action_taken="{ item }"><VChip :color="actionColor(item.action_taken)" size="x-small">{{ item.action_taken }}</VChip></template>
            <template #item.actor="{ item }"><span class="text-caption">{{ actorName(item.actor) }}</span></template>
            <template #item.is_resolved="{ item }"><VChip :color="item.is_resolved ? 'success' : 'error'" size="x-small">{{ item.is_resolved ? t('lms_policy_enforcement.violations.resolved') : t('lms_policy_enforcement.violations.open') }}</VChip></template>
            <template #item.actions="{ item }"><VBtn v-if="!item.is_resolved" size="x-small" variant="tonal" color="success" @click="openResolve(item)">{{ t('lms_policy_enforcement.violations.table.resolve') }}</VBtn></template>
          </VDataTable>
          <div v-if="violations.last_page > 1" class="d-flex justify-center pa-4"><VPagination :model-value="violations.current_page" :length="violations.last_page" density="compact" @update:model-value="goViolationPage" /></div>
        </VCard>
      </VWindowItem>

      <VWindowItem value="exceptions">
        <div class="d-flex justify-end mb-4"><VBtn color="primary" prepend-icon="mdi-plus" @click="exceptionDialog = true">{{ t('lms_policy_enforcement.exceptions.request_button') }}</VBtn></div>
        <VCard>
          <VDataTable :headers="exceptionHeaders" :items="exceptions.data" hide-default-footer density="compact">
            <template #item.user="{ item }"><span class="font-weight-medium">{{ personName(item.user) }}</span></template>
            <template #item.status="{ item }"><VChip :color="exceptionStatusColor(item.status)" size="x-small">{{ item.status }}</VChip></template>
            <template #item.valid_until="{ item }"><span class="text-caption">{{ item.valid_until ?? '—' }}</span></template>
            <template #item.actions="{ item }"><div v-if="item.status === 'pending'" class="d-flex gap-1"><VBtn size="x-small" color="success" variant="tonal" @click="decideException(item, 'approved')">{{ t('lms_policy_enforcement.exceptions.table.approve') }}</VBtn><VBtn size="x-small" color="error" variant="tonal" @click="decideException(item, 'rejected')">{{ t('lms_policy_enforcement.exceptions.table.reject') }}</VBtn></div></template>
          </VDataTable>
          <div v-if="exceptions.last_page > 1" class="d-flex justify-center pa-4"><VPagination :model-value="exceptions.current_page" :length="exceptions.last_page" density="compact" @update:model-value="goExceptionPage" /></div>
        </VCard>
      </VWindowItem>

      <VWindowItem value="logs">
        <VCard><VCardText class="pa-0">
          <VList density="compact">
            <VListItem v-for="log in enforcementLogs" :key="log.id" rounded="lg" class="mx-2 mb-1">
              <template #prepend><VChip :color="log.status_color" size="x-small" class="mr-2">{{ log.status_label }}</VChip></template>
              <VListItemTitle class="text-body-2"><span class="font-weight-medium">{{ log.action_checked }}</span><span class="text-caption text-medium-emphasis ml-2">{{ log.rules_evaluated }} rules · {{ log.violations_found }} violations</span></VListItemTitle>
              <VListItemSubtitle class="text-caption">{{ log.actor_name }} · {{ log.checked_at }}</VListItemSubtitle>
            </VListItem>
            <div v-if="enforcementLogs.length === 0" class="text-center text-medium-emphasis py-6 text-body-2">{{ t('lms_policy_enforcement.logs.empty') }}</div>
          </VList>
        </VCardText></VCard>
      </VWindowItem>
    </VWindow>

    <VDialog v-model="resolveDialog" max-width="440">
      <VCard>
        <VCardTitle class="pa-4">{{ t('lms_policy_enforcement.violations.resolve_dialog.title') }}</VCardTitle>
        <VCardText>
          <div class="text-body-2 mb-3"><strong>{{ resolvingItem?.rule_name }}</strong> — {{ violationTypes[resolvingItem?.violation_type] ?? resolvingItem?.violation_type }}</div>
          <VTextarea v-model="resolutionNote" :label="t('lms_policy_enforcement.violations.resolve_dialog.note_label')" density="compact" rows="3" />
        </VCardText>
        <VCardActions class="px-4 pb-4"><VSpacer /><VBtn variant="text" @click="resolveDialog = false">{{ t('lms_policy_enforcement.violations.resolve_dialog.cancel') }}</VBtn><VBtn color="success" :loading="resolving" :disabled="!resolutionNote" @click="submitResolve">{{ t('lms_policy_enforcement.violations.resolve_dialog.resolve') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="exceptionDialog" max-width="520">
      <VCard>
        <VCardTitle class="pa-4">{{ t('lms_policy_enforcement.exceptions.dialog.title') }}</VCardTitle>
        <VCardText>
          <VRow>
            <VCol cols="12"><VTextField v-model.number="exForm.user_id" :label="t('lms_policy_enforcement.exceptions.dialog.user_id_label')" type="number" density="compact" /></VCol>
            <VCol cols="6"><VSelect v-model="exForm.context_type" :items="contextTypeItems" item-title="label" item-value="value" :label="t('lms_policy_enforcement.exceptions.dialog.context_type_label')" density="compact" /></VCol>
            <VCol cols="6"><VTextField v-model.number="exForm.context_id" :label="t('lms_policy_enforcement.exceptions.dialog.context_id_label')" type="number" density="compact" /></VCol>
            <VCol cols="12"><VTextarea v-model="exForm.reason" :label="t('lms_policy_enforcement.exceptions.dialog.reason_label')" density="compact" rows="3" /></VCol>
            <VCol cols="6"><VTextField v-model="exForm.valid_until" :label="t('lms_policy_enforcement.exceptions.dialog.valid_until_label')" type="date" density="compact" /></VCol>
          </VRow>
        </VCardText>
        <VCardActions class="px-4 pb-4"><VSpacer /><VBtn variant="text" @click="exceptionDialog = false">{{ t('lms_policy_enforcement.exceptions.dialog.cancel') }}</VBtn><VBtn color="primary" :loading="submittingEx" :disabled="!exForm.reason || !exForm.user_id" @click="submitException">{{ t('lms_policy_enforcement.exceptions.dialog.submit') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <VSnackbar v-model="snackbar" :timeout="3000" color="success" location="bottom right">{{ snackbarText }}</VSnackbar>
  </div>
</template>
