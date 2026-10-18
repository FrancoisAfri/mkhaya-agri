<script setup lang="ts">
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Category {
  id: number; name: string; code: string; description: string | null
  requires_receipt: boolean; max_amount: number | null; approval_threshold: number | null
  max_age_days: number; icon: string | null; active: boolean; is_travel: boolean
  km_rate: number | null; claims_count: number
}

interface PolicyLink {
  id: number
  action_pattern: string
  active: boolean
  rule_id: number
  rule_name: string | null
  severity: string | null
  action_on_breach: string | null
  policy_name: string | null
  description: string | null
}

interface AvailableRule {
  id: number
  name: string
  severity: string | null
  action_on_breach: string | null
  policy_name: string | null
  description: string | null
}

interface ApprovalStep {
  id: number
  step_number: number
  step_label: string
  approver_type: string
  role_name: string | null
  employee_id: number | null
  employee_name: string | null
  amount_min: number | null
  amount_max: number | null
  applies_to_travel: boolean
  applies_to_standard: boolean
  is_active: boolean
}

interface RoleItem { id: number; name: string }
interface UserItem  { id: number; name: string; email: string }

interface PaymentProcessor {
  id: number
  processor_type: 'role' | 'user'
  role_name: string | null
  user_id: number | null
  user_name: string | null
  user_email: string | null
  is_active: boolean
  notes: string | null
}

const props = defineProps<{
  categories: Category[]
  policies: PolicyLink[]
  availableRules: AvailableRule[]
  approvalSteps: ApprovalStep[]
  paymentProcessors: PaymentProcessor[]
  roles: RoleItem[]
  users: UserItem[]
}>()

const tab = ref('categories')

// ── Category form ─────────────────────────────────────────────────────────────
const catDrawer = ref(false)
const editCatId = ref<number | null>(null)
const catForm   = useForm({
  name: '', code: '', description: '', requires_receipt: true,
  max_amount: null as number | null, approval_threshold: null as number | null,
  max_age_days: 30, icon: '', active: true, is_travel: false,
  km_rate: null as number | null,
})
const deleteCatDialog = ref(false)
const deleteCatTarget = ref<Category | null>(null)

function openNewCat() { editCatId.value = null; catForm.reset(); catForm.requires_receipt = true; catForm.max_age_days = 30; catForm.active = true; catForm.is_travel = false; catForm.km_rate = null; catDrawer.value = true }
function openEditCat(category: Category) {
  editCatId.value = category.id
  catForm.name = category.name; catForm.code = category.code; catForm.description = category.description || ''
  catForm.requires_receipt = category.requires_receipt; catForm.max_amount = category.max_amount
  catForm.approval_threshold = category.approval_threshold; catForm.max_age_days = category.max_age_days
  catForm.icon = category.icon || ''; catForm.active = category.active
  catForm.is_travel = category.is_travel; catForm.km_rate = category.km_rate
  catDrawer.value = true
}
function saveCat() {
  if (editCatId.value) {
    catForm.patch(route('claims.setup.categories.update', { category: editCatId.value }), { onSuccess: () => { catDrawer.value = false } })
  } else {
    catForm.post(route('claims.setup.categories.store'), { onSuccess: () => { catDrawer.value = false } })
  }
}
function confirmDeleteCat(category: Category) { deleteCatTarget.value = category; deleteCatDialog.value = true }
function doDeleteCat() {
  if (!deleteCatTarget.value) return
  router.delete(route('claims.setup.categories.destroy', { category: deleteCatTarget.value.id }), {
    onSuccess: () => { deleteCatDialog.value = false }
  })
}

// ── Policy link form ──────────────────────────────────────────────────────────
const linkDialog      = ref(false)
const linkForm        = useForm({ rule_id: null as number | null, action_pattern: '' })
const unlinkDialog    = ref(false)
const unlinkTarget    = ref<PolicyLink | null>(null)

const ACTION_PATTERNS = [
  { value: 'claims.*',                title: 'All Claims Events (claims.*)' },
  { value: 'claims.submitted',        title: 'Claim Submitted' },
  { value: 'claims.approved',         title: 'Claim Approved' },
  { value: 'claims.rejected',         title: 'Claim Rejected' },
  { value: 'claims.travel.submitted', title: 'Travel Claim Submitted' },
  { value: 'claims.paid',             title: 'Claim Paid' },
]

function openLinkDialog() {
  linkForm.reset()
  linkDialog.value = true
}
function saveLink() {
  linkForm.post(route('claims.setup.policy-links.store'), {
    onSuccess: () => { linkDialog.value = false },
  })
}
function confirmUnlink(policy: PolicyLink) { unlinkTarget.value = policy; unlinkDialog.value = true }
function doUnlink() {
  if (!unlinkTarget.value) return
  router.delete(route('claims.setup.policy-links.destroy', { id: unlinkTarget.value.id }), {
    onSuccess: () => { unlinkDialog.value = false },
  })
}

// ── Approval step form ────────────────────────────────────────────────────────
const stepDialog     = ref(false)
const editingStepId  = ref<number | null>(null)
const stepForm       = useForm({
  step_number: 1,
  step_label: '',
  approver_type: 'line_manager' as string,
  role_name: null as string | null,
  employee_id: null as number | null,
  amount_min: null as number | null,
  amount_max: null as number | null,
  applies_to_travel: true,
  applies_to_standard: true,
  is_active: true,
})
const deleteStepDialog = ref(false)
const deleteStepTarget = ref<ApprovalStep | null>(null)

function openNewStep() {
  editingStepId.value = null
  stepForm.reset()
  const nextNum = props.approvalSteps.length
    ? Math.max(...props.approvalSteps.map(step => step.step_number)) + 1
    : 1
  stepForm.step_number       = nextNum
  stepForm.approver_type     = 'line_manager'
  stepForm.applies_to_travel   = true
  stepForm.applies_to_standard = true
  stepForm.is_active           = true
  stepDialog.value = true
}
function openEditStep(step: ApprovalStep) {
  editingStepId.value          = step.id
  stepForm.step_number         = step.step_number
  stepForm.step_label          = step.step_label
  stepForm.approver_type       = step.approver_type
  stepForm.role_name           = step.role_name
  stepForm.employee_id         = step.employee_id
  stepForm.amount_min          = step.amount_min
  stepForm.amount_max          = step.amount_max
  stepForm.applies_to_travel   = step.applies_to_travel
  stepForm.applies_to_standard = step.applies_to_standard
  stepForm.is_active           = step.is_active
  stepDialog.value = true
}
function saveStep() {
  if (editingStepId.value) {
    stepForm.patch(route('claims.setup.approval-steps.update', { step: editingStepId.value }), {
      onSuccess: () => { stepDialog.value = false },
    })
  } else {
    stepForm.post(route('claims.setup.approval-steps.store'), {
      onSuccess: () => { stepDialog.value = false },
    })
  }
}
function confirmDeleteStep(step: ApprovalStep) { deleteStepTarget.value = step; deleteStepDialog.value = true }
function doDeleteStep() {
  if (!deleteStepTarget.value) return
  router.delete(route('claims.setup.approval-steps.destroy', { step: deleteStepTarget.value.id }), {
    onSuccess: () => { deleteStepDialog.value = false },
  })
}

function approverLabel(step: ApprovalStep): string {
  if (step.approver_type === 'line_manager') return t('claims_setup.approvals.dialog.approver_types.line_manager')
  if (step.approver_type === 'role')         return step.role_name ?? '(no role set)'
  if (step.approver_type === 'employee')     return step.employee_name ?? '(no employee set)'
  return '—'
}
function thresholdLabel(step: ApprovalStep): string {
  if (step.amount_min === null && step.amount_max === null) return t('claims_setup.approvals.all_amounts')
  if (step.amount_min !== null && step.amount_max !== null)
    return t('claims_setup.approvals.amount_range', { min: step.amount_min.toLocaleString('en-ZA'), max: step.amount_max.toLocaleString('en-ZA') })
  if (step.amount_min !== null) return t('claims_setup.approvals.amount_above', { min: step.amount_min.toLocaleString('en-ZA') })
  return t('claims_setup.approvals.amount_up_to', { max: step.amount_max!.toLocaleString('en-ZA') })
}

const severityColor    = (s: string | null) => ({ low: 'success', medium: 'warning', high: 'error', critical: 'deep-purple' })[s ?? ''] ?? 'grey'
const breachColor      = (a: string | null) => ({ warn: 'warning', block: 'error', escalate: 'info', log_only: 'grey' })[a ?? ''] ?? 'grey'
const fmt = (n: number | null) => n ? 'R ' + Number(n).toLocaleString('en-ZA', { minimumFractionDigits: 2 }) : '—'

// ── Payment Processors ────────────────────────────────────────────────────────
const ppDialog  = ref(false)
const deleteProcessorDialog = ref(false)
const deleteProcessorTarget = ref<PaymentProcessor | null>(null)

const ppForm = useForm({
  processor_type: 'role' as 'role' | 'user',
  role_name:      null as string | null,
  user_id:        null as number | null,
  notes:          '',
})

function openNewProcessor() { ppForm.reset(); ppForm.processor_type = 'role'; ppDialog.value = true }
function saveProcessor() {
  ppForm.post(route('claims.setup.payment-processors.store'), {
    onSuccess: () => { ppDialog.value = false },
  })
}
function confirmDeleteProcessor(processor: PaymentProcessor) {
  deleteProcessorTarget.value = processor
  deleteProcessorDialog.value = true
}
function doDeleteProcessor() {
  if (!deleteProcessorTarget.value) return
  router.delete(route('claims.setup.payment-processors.destroy', { processor: deleteProcessorTarget.value.id }), {
    onSuccess: () => { deleteProcessorDialog.value = false },
  })
}

function processorLabel(processor: PaymentProcessor): string {
  if (processor.processor_type === 'role') return processor.role_name ?? '(no role)'
  return processor.user_name || processor.user_email || `User #${processor.user_id}`
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-5 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('claims_setup.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('claims_setup.description') }}</p>
      </div>
    </div>

    <VTabs v-model="tab" density="compact" class="mb-4">
      <VTab value="categories">{{ t('claims_setup.tabs.categories') }} <VChip size="x-small" class="ml-1">{{ categories.length }}</VChip></VTab>
      <VTab value="approvals">{{ t('claims_setup.tabs.approvals') }} <VChip size="x-small" class="ml-1">{{ approvalSteps.length }}</VChip></VTab>
      <VTab value="payment">{{ t('claims_setup.tabs.payment') }} <VChip size="x-small" class="ml-1">{{ paymentProcessors.length }}</VChip></VTab>
      <VTab value="policies">{{ t('claims_setup.tabs.policies') }} <VChip size="x-small" class="ml-1">{{ policies.length }}</VChip></VTab>
    </VTabs>


    <VWindow v-model="tab">
      <!-- Categories -->
      <VWindowItem value="categories">
        <div class="d-flex justify-end mb-3">
          <VBtn color="primary" prepend-icon="tabler-plus" @click="openNewCat">{{ t('claims_setup.categories.new_category') }}</VBtn>
        </div>
        <VCard>
          <VDataTable
            :items="categories"
            :headers="[
              { title: t('claims_setup.categories.table.name'),       key: 'name' },
              { title: t('claims_setup.categories.table.code'),       key: 'code' },
              { title: t('claims_setup.categories.table.max_amount'), key: 'max_amount' },
              { title: t('claims_setup.categories.table.max_age'),    key: 'max_age_days' },
              { title: t('claims_setup.categories.table.receipt'),    key: 'requires_receipt' },
              { title: t('claims_setup.categories.table.claims'),     key: 'claims_count' },
              { title: t('claims_setup.categories.table.active'),     key: 'active' },
              { title: '',           key: 'actions', sortable: false },
            ]"
            density="comfortable"
          >
            <template #item.max_amount="{ item }">{{ fmt(item.max_amount) }}</template>
            <template #item.max_age_days="{ item }">{{ item.max_age_days }}d</template>
            <template #item.requires_receipt="{ item }">
              <VChip :color="item.requires_receipt ? 'warning' : 'grey'" size="x-small" variant="tonal">{{ item.requires_receipt ? t('claims_setup.categories.required') : t('claims_setup.categories.optional') }}</VChip>
            </template>
            <template #item.active="{ item }">
              <VChip :color="item.active ? 'success' : 'grey'" size="x-small" variant="tonal">{{ item.active ? t('claims_setup.categories.active_status') : t('claims_setup.categories.inactive') }}</VChip>
            </template>
            <template #item.actions="{ item }">
              <div class="d-flex ga-1">
                <VBtn icon="tabler-pencil" size="x-small" variant="text" @click="openEditCat(item)" />
                <VBtn icon="tabler-trash" size="x-small" variant="text" color="error" @click="confirmDeleteCat(item)" />
              </div>
            </template>
          </VDataTable>
        </VCard>
      </VWindowItem>

      <!-- Approval Steps -->
      <VWindowItem value="approvals">
        <VAlert type="info" variant="tonal" density="compact" class="mb-3" icon="tabler-info-circle">
          {{ t('claims_setup.approvals.info') }}
        </VAlert>
        <div class="d-flex justify-end mb-3">
          <VBtn color="primary" prepend-icon="tabler-plus" @click="openNewStep">{{ t('claims_setup.approvals.add_step') }}</VBtn>
        </div>

        <div v-if="approvalSteps.length" class="d-flex flex-column ga-2">
          <VCard
            v-for="(step, idx) in approvalSteps"
            :key="step.id"
            variant="outlined"
            :color="step.is_active ? undefined : 'grey'"
            class="pa-0"
          >
            <div class="d-flex align-center pa-3 ga-3">
              <VAvatar
                :color="step.is_active ? 'primary' : 'grey'"
                size="36"
                class="flex-shrink-0 text-body-2 font-weight-bold"
              >{{ step.step_number }}</VAvatar>

              <div class="flex-grow-1">
                <div class="d-flex align-center flex-wrap ga-1 mb-1">
                  <span class="text-body-1 font-weight-semibold">{{ step.step_label }}</span>
                  <VChip size="x-small" color="secondary" variant="tonal">{{ approverLabel(step) }}</VChip>
                  <VChip v-if="!step.is_active" size="x-small" color="grey" variant="tonal">{{ t('claims_setup.approvals.inactive') }}</VChip>
                </div>
                <div class="d-flex flex-wrap ga-1">
                  <VChip size="x-small" color="warning" variant="tonal" prepend-icon="tabler-currency-dollar">
                    {{ thresholdLabel(step) }}
                  </VChip>
                  <VChip v-if="step.applies_to_travel && step.applies_to_standard" size="x-small" variant="tonal" color="info">{{ t('claims_setup.approvals.all_claim_types') }}</VChip>
                  <VChip v-else-if="step.applies_to_travel" size="x-small" variant="tonal" color="blue">{{ t('claims_setup.approvals.travel_only') }}</VChip>
                  <VChip v-else-if="step.applies_to_standard" size="x-small" variant="tonal" color="teal">{{ t('claims_setup.approvals.standard_only') }}</VChip>
                </div>
              </div>

              <div class="d-flex ga-1 flex-shrink-0">
                <VBtn icon="tabler-pencil" size="small" variant="text" @click="openEditStep(step)" />
                <VBtn icon="tabler-trash" size="small" variant="text" color="error" @click="confirmDeleteStep(step)" />
              </div>
            </div>

            <div v-if="idx < approvalSteps.length - 1" class="d-flex justify-center py-0">
              <VIcon color="grey-lighten-1" size="18">tabler-chevron-down</VIcon>
            </div>
          </VCard>
        </div>

        <VCard v-else variant="tonal" color="grey" class="text-center pa-8">
          <VIcon size="48" color="grey-lighten-1" class="mb-3">tabler-git-merge</VIcon>
          <div class="text-body-1 mb-1">{{ t('claims_setup.approvals.empty.title') }}</div>
          <div class="text-body-2 text-medium-emphasis mb-4">
            {{ t('claims_setup.approvals.empty.description') }}
          </div>
          <VBtn color="primary" prepend-icon="tabler-plus" @click="openNewStep">{{ t('claims_setup.approvals.add_first_step') }}</VBtn>
        </VCard>
      </VWindowItem>

      <!-- Payment Processors -->
      <VWindowItem value="payment">
        <VAlert type="info" variant="tonal" density="compact" class="mb-3" icon="tabler-cash-banknote">
          {{ t('claims_setup.payment.info') }}
        </VAlert>

        <div class="d-flex justify-end mb-3">
          <VBtn color="primary" prepend-icon="tabler-plus" @click="openNewProcessor">{{ t('claims_setup.payment.add_processor') }}</VBtn>
        </div>

        <VRow v-if="paymentProcessors.length" dense>
          <VCol v-for="processor in paymentProcessors" :key="processor.id" cols="12" sm="6" md="4">
            <VCard variant="outlined" height="100%">
              <VCardText class="pa-4">
                <div class="d-flex align-start justify-space-between">
                  <div class="d-flex align-center ga-2">
                    <VAvatar
                      :color="processor.processor_type === 'role' ? 'primary' : 'secondary'"
                      variant="tonal" size="38"
                    >
                      <VIcon size="20">{{ processor.processor_type === 'role' ? 'tabler-shield-lock' : 'tabler-user' }}</VIcon>
                    </VAvatar>
                    <div>
                      <div class="text-body-2 font-weight-bold">{{ processorLabel(processor) }}</div>
                      <VChip size="x-small" :color="processor.processor_type === 'role' ? 'primary' : 'secondary'" variant="tonal">
                        {{ processor.processor_type === 'role' ? t('claims_setup.payment.role_type') : t('claims_setup.payment.user_type') }}
                      </VChip>
                    </div>
                  </div>
                  <VBtn icon="tabler-trash" size="x-small" variant="text" color="error"
                    @click="confirmDeleteProcessor(processor)" />
                </div>
                <div v-if="processor.notes" class="text-caption text-medium-emphasis mt-2">{{ processor.notes }}</div>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>

        <VCard v-else variant="tonal" color="grey" class="text-center pa-8">
          <VIcon size="48" color="grey-lighten-1" class="mb-3">tabler-cash-banknote</VIcon>
          <div class="text-body-1 mb-1">{{ t('claims_setup.payment.empty.title') }}</div>
          <div class="text-body-2 text-medium-emphasis mb-4">
            {{ t('claims_setup.payment.empty.description') }}
          </div>
          <VBtn color="primary" prepend-icon="tabler-plus" @click="openNewProcessor">{{ t('claims_setup.payment.add_first_processor') }}</VBtn>
        </VCard>
      </VWindowItem>

      <!-- Policies -->
      <VWindowItem value="policies">
        <VAlert type="info" variant="tonal" density="compact" class="mb-3" icon="tabler-shield-check">
          {{ t('claims_setup.policies.info') }}
        </VAlert>
        <div class="d-flex justify-end mb-3">
          <VBtn color="primary" prepend-icon="tabler-link" :disabled="availableRules.length === 0" @click="openLinkDialog">
            {{ t('claims_setup.policies.link_rule') }}
          </VBtn>
        </div>
        <VCard>
          <VDataTable
            :items="policies"
            :headers="[
              { title: t('claims_setup.policies.table.action_pattern'),   key: 'action_pattern' },
              { title: t('claims_setup.policies.table.rule'),             key: 'rule_name' },
              { title: t('claims_setup.policies.table.policy'),           key: 'policy_name' },
              { title: t('claims_setup.policies.table.severity'),         key: 'severity' },
              { title: t('claims_setup.policies.table.on_breach'),        key: 'action_on_breach' },
              { title: t('claims_setup.policies.table.active'),           key: 'active' },
              { title: '',                 key: 'actions', sortable: false },
            ]"
            density="comfortable"
          >
            <template #no-data>
              {{ t('claims_setup.policies.no_data') }}
            </template>
            <template #item.action_pattern="{ item }">
              <VChip color="primary" size="x-small" variant="tonal" class="font-weight-medium">{{ item.action_pattern }}</VChip>
            </template>
            <template #item.severity="{ item }">
              <VChip v-if="item.severity" :color="severityColor(item.severity)" size="x-small" variant="tonal">{{ item.severity }}</VChip>
              <span v-else class="text-medium-emphasis">—</span>
            </template>
            <template #item.action_on_breach="{ item }">
              <VChip v-if="item.action_on_breach" :color="breachColor(item.action_on_breach)" size="x-small" variant="tonal">{{ item.action_on_breach }}</VChip>
              <span v-else class="text-medium-emphasis">—</span>
            </template>
            <template #item.active="{ item }">
              <VChip :color="item.active ? 'success' : 'grey'" size="x-small" variant="tonal">{{ item.active ? t('claims_setup.policies.active_status') : t('claims_setup.policies.off') }}</VChip>
            </template>
            <template #item.actions="{ item }">
              <VBtn icon="tabler-unlink" size="x-small" variant="text" color="error" :title="t('claims_setup.policies.unlink')" @click="confirmUnlink(item)" />
            </template>
          </VDataTable>
        </VCard>
      </VWindowItem>
    </VWindow>

    <!-- Category Drawer -->
    <VNavigationDrawer v-model="catDrawer" location="right" :width="440" temporary>
      <div class="pa-4">
        <div class="d-flex align-center justify-space-between mb-4">
          <span class="text-body-1 font-weight-bold">{{ editCatId ? t('claims_setup.categories.drawer.edit_title') : t('claims_setup.categories.drawer.new_title') }}</span>
          <VBtn icon="tabler-x" variant="text" size="small" @click="catDrawer = false" />
        </div>
        <VTextField v-model="catForm.name" :label="t('claims_setup.categories.drawer.name')" variant="outlined" density="compact" class="mb-3" :error-messages="catForm.errors.name" />
        <VTextField v-model="catForm.code" :label="t('claims_setup.categories.drawer.code')" variant="outlined" density="compact" class="mb-3" :error-messages="catForm.errors.code" />
        <VTextarea v-model="catForm.description" :label="t('claims_setup.categories.drawer.description')" variant="outlined" density="compact" rows="2" class="mb-3" />
        <VRow dense>
          <VCol cols="6">
            <VTextField v-model.number="catForm.max_amount" :label="t('claims_setup.categories.drawer.max_amount')" type="number" variant="outlined" density="compact" class="mb-3" />
          </VCol>
          <VCol cols="6">
            <VTextField v-model.number="catForm.approval_threshold" :label="t('claims_setup.categories.drawer.approval_threshold')" type="number" variant="outlined" density="compact" class="mb-3" />
          </VCol>
          <VCol cols="6">
            <VTextField v-model.number="catForm.max_age_days" :label="t('claims_setup.categories.drawer.max_age_days')" type="number" variant="outlined" density="compact" class="mb-3" />
          </VCol>
          <VCol cols="6">
            <VTextField v-model="catForm.icon" :label="t('claims_setup.categories.drawer.icon')" variant="outlined" density="compact" class="mb-3" />
          </VCol>
        </VRow>
        <VSwitch v-model="catForm.requires_receipt" :label="t('claims_setup.categories.drawer.receipt_required')" color="warning" class="mb-2" />
        <VSwitch v-model="catForm.is_travel" :label="t('claims_setup.categories.drawer.travel_category')" color="info" class="mb-2" />
        <VTextField v-if="catForm.is_travel"
          v-model.number="catForm.km_rate" :label="t('claims_setup.categories.drawer.km_rate')" type="number" step="0.01" min="0.01"
          variant="outlined" density="compact" class="mb-3"
          :error-messages="catForm.errors.km_rate"
          :hint="t('claims_setup.categories.drawer.km_rate_hint')" persistent-hint />
        <VSwitch v-model="catForm.active" :label="t('claims_setup.categories.drawer.active')" color="success" class="mb-4" />
        <div class="d-flex ga-2 justify-end">
          <VBtn variant="outlined" @click="catDrawer = false">{{ t('claims_setup.categories.drawer.cancel') }}</VBtn>
          <VBtn color="primary" :loading="catForm.processing" @click="saveCat">{{ editCatId ? t('claims_setup.categories.drawer.update') : t('claims_setup.categories.drawer.create') }}</VBtn>
        </div>
      </div>
    </VNavigationDrawer>

    <!-- Add / Edit Approval Step Dialog -->
    <VDialog v-model="stepDialog" max-width="540" persistent>
      <VCard>
        <VCardTitle class="pa-4 pb-2">
          {{ editingStepId ? t('claims_setup.approvals.dialog.edit_title') : t('claims_setup.approvals.dialog.new_title') }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VRow dense>
            <VCol cols="4">
              <VTextField
                v-model.number="stepForm.step_number"
                :label="t('claims_setup.approvals.dialog.step_number')"
                type="number" min="1"
                variant="outlined" density="compact"
                :error-messages="stepForm.errors.step_number"
              />
            </VCol>
            <VCol cols="8">
              <VTextField
                v-model="stepForm.step_label"
                :label="t('claims_setup.approvals.dialog.step_label')"
                :placeholder="t('claims_setup.approvals.dialog.step_label_placeholder')"
                variant="outlined" density="compact"
                :error-messages="stepForm.errors.step_label"
              />
            </VCol>

            <VCol cols="12">
              <VSelect
                v-model="stepForm.approver_type"
                :label="t('claims_setup.approvals.dialog.approver_type')"
                variant="outlined" density="compact"
                :items="[
                  { value: 'line_manager', title: t('claims_setup.approvals.dialog.approver_types.line_manager') },
                  { value: 'role',         title: t('claims_setup.approvals.dialog.approver_types.role') },
                  { value: 'employee',     title: t('claims_setup.approvals.dialog.approver_types.employee') },
                ]"
              />
            </VCol>

            <VCol cols="12" v-if="stepForm.approver_type === 'role'">
              <VSelect
                v-model="stepForm.role_name"
                :label="t('claims_setup.approvals.dialog.role')"
                variant="outlined" density="compact"
                :items="roles"
                item-title="name" item-value="name"
                clearable
                :error-messages="stepForm.errors.role_name"
              />
            </VCol>

            <VCol cols="12" v-if="stepForm.approver_type === 'employee'">
              <VAutocomplete
                v-model="stepForm.employee_id"
                :label="t('claims_setup.approvals.dialog.employee')"
                variant="outlined" density="compact"
                :items="users"
                item-title="name" item-value="id"
                clearable
                :error-messages="stepForm.errors.employee_id"
              />
            </VCol>

            <VCol cols="12">
              <div class="text-body-2 font-weight-medium mb-2 mt-1">{{ t('claims_setup.approvals.dialog.amount_threshold') }}</div>
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model.number="stepForm.amount_min"
                :label="t('claims_setup.approvals.dialog.min_amount')"
                type="number" min="0" step="100"
                variant="outlined" density="compact"
                :hint="t('claims_setup.approvals.dialog.min_amount_hint')"
                persistent-hint
                :error-messages="stepForm.errors.amount_min"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model.number="stepForm.amount_max"
                :label="t('claims_setup.approvals.dialog.max_amount')"
                type="number" min="0" step="100"
                variant="outlined" density="compact"
                :hint="t('claims_setup.approvals.dialog.max_amount_hint')"
                persistent-hint
                :error-messages="stepForm.errors.amount_max"
              />
            </VCol>

            <VCol cols="12">
              <div class="text-body-2 font-weight-medium mb-1 mt-2">{{ t('claims_setup.approvals.dialog.applies_to') }}</div>
              <div class="d-flex ga-4">
                <VCheckbox
                  v-model="stepForm.applies_to_standard"
                  :label="t('claims_setup.approvals.dialog.standard_claims')"
                  density="compact" hide-details color="teal"
                />
                <VCheckbox
                  v-model="stepForm.applies_to_travel"
                  :label="t('claims_setup.approvals.dialog.travel_claims')"
                  density="compact" hide-details color="blue"
                />
              </div>
            </VCol>

            <VCol cols="12">
              <VSwitch
                v-model="stepForm.is_active"
                :label="t('claims_setup.approvals.dialog.active')"
                color="success" density="compact" hide-details
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="outlined" @click="stepDialog = false">{{ t('claims_setup.approvals.dialog.cancel') }}</VBtn>
          <VBtn color="primary" variant="flat" :loading="stepForm.processing" @click="saveStep">
            {{ editingStepId ? t('claims_setup.approvals.dialog.save_changes') : t('claims_setup.approvals.dialog.add_step_btn') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Delete Approval Step Confirm -->
    <VDialog v-model="deleteStepDialog" max-width="400">
      <VCard :title="t('claims_setup.approvals.delete_title')">
        <VCardText>
          {{ t('claims_setup.approvals.delete_message', { step: deleteStepTarget?.step_number, label: deleteStepTarget?.step_label }) }}
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="outlined" @click="deleteStepDialog = false">{{ t('claims_setup.approvals.cancel') }}</VBtn>
          <VBtn color="error" @click="doDeleteStep">{{ t('claims_setup.approvals.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Link Policy Rule Dialog -->
    <VDialog v-model="linkDialog" max-width="520">
      <VCard :title="t('claims_setup.policies.dialog.title')">
        <VCardText class="pt-2">
          <p class="text-body-2 text-medium-emphasis mb-4">
            {{ t('claims_setup.policies.dialog.description') }}
          </p>
          <VSelect
            v-model="linkForm.rule_id"
            :label="t('claims_setup.policies.dialog.policy_rule')"
            :items="availableRules.map(rule => ({
              value: rule.id,
              title: rule.name,
              subtitle: rule.policy_name ?? '',
            }))"
            item-props
            variant="outlined"
            density="compact"
            class="mb-3"
            :error-messages="linkForm.errors.rule_id"
          >
            <template #item="{ props: itemProps, item }">
              <VListItem v-bind="itemProps">
                <template #append>
                  <VChip v-if="item.raw.action_on_breach" :color="breachColor(item.raw.action_on_breach)" size="x-small" variant="tonal">
                    {{ item.raw.action_on_breach }}
                  </VChip>
                </template>
              </VListItem>
            </template>
          </VSelect>
          <VSelect
            v-model="linkForm.action_pattern"
            :label="t('claims_setup.policies.dialog.claims_event')"
            :items="ACTION_PATTERNS"
            variant="outlined"
            density="compact"
            :error-messages="linkForm.errors.action_pattern"
          />
          <VAlert v-if="linkForm.action_pattern" type="info" variant="tonal" density="compact" class="mt-3" icon="tabler-info-circle">
            {{ t('claims_setup.policies.dialog.info', { event: linkForm.action_pattern }) }}
          </VAlert>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="outlined" @click="linkDialog = false">{{ t('claims_setup.policies.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="linkForm.processing" :disabled="!linkForm.rule_id || !linkForm.action_pattern" @click="saveLink">{{ t('claims_setup.policies.dialog.link_rule_btn') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Delete Category Confirm -->
    <VDialog v-model="deleteCatDialog" max-width="400">
      <VCard :title="t('claims_setup.categories.delete_title')">
        <VCardText>{{ t('claims_setup.categories.delete_message', { name: deleteCatTarget?.name }) }}</VCardText>
        <VCardActions><VSpacer /><VBtn variant="outlined" @click="deleteCatDialog = false">{{ t('claims_setup.categories.cancel') }}</VBtn><VBtn color="error" @click="doDeleteCat">{{ t('claims_setup.categories.delete') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Unlink Policy Rule Confirm -->
    <VDialog v-model="unlinkDialog" max-width="420">
      <VCard :title="t('claims_setup.policies.unlink_title')">
        <VCardText>
          {{ t('claims_setup.policies.unlink_message', { rule: unlinkTarget?.rule_name, pattern: unlinkTarget?.action_pattern }) }}
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="outlined" @click="unlinkDialog = false">{{ t('claims_setup.policies.cancel') }}</VBtn><VBtn color="error" @click="doUnlink">{{ t('claims_setup.policies.unlink') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>

    <!-- Add Payment Processor Dialog -->
    <VDialog v-model="ppDialog" max-width="480" persistent>
      <VCard :title="t('claims_setup.payment.dialog.title')">
        <VDivider />
        <VCardText class="pt-4">
          <VAlert type="info" variant="tonal" density="compact" class="mb-4" icon="tabler-info-circle">
            {{ t('claims_setup.payment.dialog.info') }}
          </VAlert>
          <VSelect
            v-model="ppForm.processor_type"
            :label="t('claims_setup.payment.dialog.processor_type')"
            :items="[{ value: 'role', title: t('claims_setup.payment.dialog.role_type_label') }, { value: 'user', title: t('claims_setup.payment.dialog.user_type_label') }]"
            variant="outlined" density="compact" class="mb-3"
          />
          <VSelect
            v-if="ppForm.processor_type === 'role'"
            v-model="ppForm.role_name"
            :label="t('claims_setup.payment.dialog.role')"
            :items="roles" item-title="name" item-value="name"
            variant="outlined" density="compact" class="mb-3"
            :error-messages="ppForm.errors.role_name"
            clearable
          />
          <VAutocomplete
            v-if="ppForm.processor_type === 'user'"
            v-model="ppForm.user_id"
            :label="t('claims_setup.payment.dialog.user')"
            :items="users" item-title="name" item-value="id"
            variant="outlined" density="compact" class="mb-3"
            :error-messages="ppForm.errors.user_id"
            clearable
          >
            <template #item="{ props: itemProps, item }">
              <VListItem v-bind="itemProps" :subtitle="item.raw.email" />
            </template>
          </VAutocomplete>
          <VTextarea
            v-model="ppForm.notes"
            :label="t('claims_setup.payment.dialog.notes')"
            variant="outlined" density="compact" rows="2"
            :placeholder="t('claims_setup.payment.dialog.notes_placeholder')"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="outlined" @click="ppDialog = false">{{ t('claims_setup.payment.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="ppForm.processing" @click="saveProcessor">{{ t('claims_setup.payment.dialog.add_processor_btn') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Delete Processor Confirm -->
    <VDialog v-model="deleteProcessorDialog" max-width="400">
      <VCard :title="t('claims_setup.payment.delete_title')">
        <VCardText>
          {{ t('claims_setup.payment.delete_message', { name: processorLabel(deleteProcessorTarget!) }) }}
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="outlined" @click="deleteProcessorDialog = false">{{ t('claims_setup.payment.cancel') }}</VBtn>
          <VBtn color="error" @click="doDeleteProcessor">{{ t('claims_setup.payment.remove') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
