<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

type Pending = {
  id: number; contract_number: string; title: string; contract_value: number | null
  contract_status: string; current_step_id: number | null; type: string | null
}
type Step = {
  id: number; step_number: number | null; step_name: string | null; max_amount: number | null
  approval_type: number | null; role_id: number | null; parent_step_id: number | null
  status: number; user_ids: number[]
}

const props = defineProps<{ pending: Pending[]; steps: Step[] }>()

const tab = ref('queue')
const confirmDialog = ref()
const decisionDialog = ref(false)
const decisionMode = ref<'approve' | 'decline'>('approve')
const activeContract = ref<Pending | null>(null)
const comment = ref('')
const submitting = ref(false)

function openDecision(contract: Pending, mode: 'approve' | 'decline') {
  activeContract.value = contract
  decisionMode.value = mode
  comment.value = ''
  decisionDialog.value = true
}
function submitDecision() {
  if (!activeContract.value) return
  submitting.value = true
  const name = decisionMode.value === 'approve' ? 'contracts.approvals.approve' : 'contracts.approvals.decline'
  router.post(route(name, activeContract.value.id), {
    step_id: activeContract.value.current_step_id, comment: comment.value,
  }, {
    preserveScroll: true,
    onSuccess: () => (decisionDialog.value = false),
    onFinish: () => (submitting.value = false),
  })
}

// Steps config
const stepDialog = ref(false)
const editingStep = ref<Step | null>(null)
const stepForm = ref<Partial<Step>>({})
const savingStep = ref(false)

function newStep() { editingStep.value = null; stepForm.value = { status: 1 }; stepDialog.value = true }
function editStep(step: Step) { editingStep.value = step; stepForm.value = { ...step }; stepDialog.value = true }
function saveStep() {
  savingStep.value = true
  if (editingStep.value) {
    router.patch(route('contracts.approvals.steps.update', editingStep.value.id), stepForm.value as any, {
      preserveScroll: true,
      onSuccess: () => (stepDialog.value = false),
      onFinish: () => (savingStep.value = false),
    })
  } else {
    router.post(route('contracts.approvals.steps.store'), stepForm.value as any, {
      preserveScroll: true,
      onSuccess: () => (stepDialog.value = false),
      onFinish: () => (savingStep.value = false),
    })
  }
}
function deleteStep(step: Step) {
  confirmDialog.value.open(
    {
      title: t('contracts_approvals.confirm.delete_title'),
      message: t('contracts_approvals.confirm.delete_message', { name: step.step_name }),
      confirmText: t('contracts_approvals.confirm.delete_text'),
      confirmColor: 'error',
      icon: 'tabler-trash'
    },
    () => { router.delete(route('contracts.approvals.steps.destroy', step.id), { preserveScroll: true }) },
  )
}
function money(v: number | null) {
  return v == null ? '—' : new Intl.NumberFormat(undefined, { style: 'currency', currency: 'ZAR' }).format(v)
}

function getApprovalTypeLabel(type: number | null): string {
  if (type === 1) return t('contracts_approvals.steps.approval_type.line_manager')
  if (type === 2) return t('contracts_approvals.steps.approval_type.multi_user')
  return '—'
}

const approvalTypeItems = [
  { title: t('contracts_approvals.steps.approval_type.line_manager'), value: 1 },
  { title: t('contracts_approvals.steps.approval_type.multi_user'), value: 2 },
]
</script>

<template>
  <div class="pa-4">
    <!-- Loading overlay -->
    <VOverlay v-if="submitting || savingStep" contained class="align-center justify-center" style="position: fixed; z-index: 9999;">
      <VProgressCircular indeterminate color="primary" size="64" />
    </VOverlay>

    <ConfirmDialog ref="confirmDialog" />
    <div class="d-flex align-center gap-3 mb-4">
      <VAvatar color="warning" variant="tonal" size="48" rounded>
        <VIcon icon="tabler-checkbox" size="26" />
      </VAvatar>
      <div>
        <h4 class="text-h4 mb-0">{{ t('contracts_approvals.page_title') }}</h4>
        <p class="text-caption text-medium-emphasis mb-0">{{ t('contracts_approvals.description') }}</p>
      </div>
    </div>

    <VTabs v-model="tab" class="mb-4">
      <VTab value="queue">{{ t('contracts_approvals.tabs.pending_queue') }}</VTab>
      <VTab value="steps">{{ t('contracts_approvals.tabs.workflow_steps') }}</VTab>
    </VTabs>

    <VWindow v-model="tab">
      <VWindowItem value="queue">
        <VCard border>
          <VTable>
            <thead>
              <tr>
                <th>{{ t('contracts_approvals.pending.number') }}</th>
                <th>{{ t('contracts_approvals.pending.title') }}</th>
                <th>{{ t('contracts_approvals.pending.type') }}</th>
                <th class="text-right">{{ t('contracts_approvals.pending.value') }}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!pending.length">
                <td colspan="5" class="text-center text-medium-emphasis py-8">{{ t('contracts_approvals.pending.empty') }}</td>
              </tr>
              <tr v-for="contract in pending" :key="contract.id">
                <td class="font-weight-medium">{{ contract.contract_number }}</td>
                <td>{{ contract.title }}</td>
                <td>{{ contract.type ?? '—' }}</td>
                <td class="text-right">{{ money(contract.contract_value) }}</td>
                <td class="text-right">
                  <VBtn size="small" variant="tonal" color="success" class="me-2" :disabled="submitting" @click="openDecision(contract, 'approve')">
                    {{ t('contracts_approvals.pending.approve') }}
                  </VBtn>
                  <VBtn size="small" variant="tonal" color="error" :disabled="submitting" @click="openDecision(contract, 'decline')">
                    {{ t('contracts_approvals.pending.decline') }}
                  </VBtn>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VWindowItem>

      <VWindowItem value="steps">
        <div class="d-flex justify-end mb-3">
          <VBtn color="primary" prepend-icon="tabler-plus" :disabled="savingStep" @click="newStep">
            {{ t('contracts_approvals.steps.new_step') }}
          </VBtn>
        </div>
        <VCard border>
          <VTable>
            <thead>
              <tr>
                <th>{{ t('contracts_approvals.steps.number') }}</th>
                <th>{{ t('contracts_approvals.steps.name') }}</th>
                <th class="text-right">{{ t('contracts_approvals.steps.max_amount') }}</th>
                <th>{{ t('contracts_approvals.steps.type') }}</th>
                <th>{{ t('contracts_approvals.steps.status') }}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!steps.length">
                <td colspan="6" class="text-center text-medium-emphasis py-8">{{ t('contracts_approvals.steps.empty') }}</td>
              </tr>
              <tr v-for="step in steps" :key="step.id">
                <td>{{ step.step_number ?? '—' }}</td>
                <td>{{ step.step_name ?? '—' }}</td>
                <td class="text-right">{{ money(step.max_amount) }}</td>
                <td>{{ getApprovalTypeLabel(step.approval_type) }}</td>
                <td>
                  <VChip size="small" :color="step.status === 1 ? 'success' : 'grey'" variant="tonal">
                    {{ step.status === 1 ? t('contracts_approvals.steps.active') : t('contracts_approvals.steps.inactive') }}
                  </VChip>
                </td>
                <td class="text-right">
                  <VBtn icon="tabler-edit" size="small" variant="text" :disabled="savingStep" @click="editStep(step)" />
                  <VBtn icon="tabler-trash" size="small" variant="text" color="error" :disabled="savingStep" @click="deleteStep(step)" />
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VWindowItem>
    </VWindow>

    <VDialog v-model="decisionDialog" max-width="480" persistent>
      <VCard :loading="submitting" :disabled="submitting">
        <VCardTitle>
          {{ decisionMode === 'approve' ? t('contracts_approvals.dialog.approve_title') : t('contracts_approvals.dialog.decline_title') }}
        </VCardTitle>
        <VCardText>
          <VTextarea v-model="comment" :label="t('contracts_approvals.dialog.comment')" variant="outlined" rows="3" :disabled="submitting" />
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" :disabled="submitting" @click="decisionDialog = false">{{ t('contracts_approvals.dialog.cancel') }}</VBtn>
          <VBtn :color="decisionMode === 'approve' ? 'success' : 'error'" :loading="submitting" :disabled="submitting" @click="submitDecision">
            {{ decisionMode === 'approve' ? t('contracts_approvals.dialog.approve_btn') : t('contracts_approvals.dialog.decline_btn') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="stepDialog" max-width="520" persistent>
      <VCard :loading="savingStep" :disabled="savingStep">
        <VCardTitle>
          {{ editingStep ? t('contracts_approvals.dialog.step_title_edit') : t('contracts_approvals.dialog.step_title_new') }}
        </VCardTitle>
        <VCardText>
          <VRow>
            <VCol cols="4">
              <VTextField v-model.number="stepForm.step_number" type="number" :label="t('contracts_approvals.dialog.step_number')" variant="outlined" :disabled="savingStep" />
            </VCol>
            <VCol cols="8">
              <VTextField v-model="stepForm.step_name" :label="t('contracts_approvals.dialog.step_name')" variant="outlined" :disabled="savingStep" />
            </VCol>
          </VRow>
          <VRow>
            <VCol cols="6">
              <VTextField v-model.number="stepForm.max_amount" type="number" :label="t('contracts_approvals.dialog.max_amount')" variant="outlined" :disabled="savingStep" />
            </VCol>
            <VCol cols="6">
              <VSelect
                v-model.number="stepForm.approval_type"
                :items="approvalTypeItems"
                :label="t('contracts_approvals.dialog.approval_type')"
                variant="outlined"
                clearable
                :disabled="savingStep"
              />
            </VCol>
          </VRow>
          <VRow>
            <VCol cols="6">
              <VTextField v-model.number="stepForm.role_id" type="number" :label="t('contracts_approvals.dialog.role_id')" variant="outlined" :disabled="savingStep" />
            </VCol>
            <VCol cols="6">
              <VTextField v-model.number="stepForm.parent_step_id" type="number" :label="t('contracts_approvals.dialog.parent_step_id')" variant="outlined" :disabled="savingStep" />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" :disabled="savingStep" @click="stepDialog = false">{{ t('contracts_approvals.dialog.cancel') }}</VBtn>
          <VBtn color="primary" :loading="savingStep" :disabled="savingStep" @click="saveStep">{{ t('contracts_approvals.dialog.save') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
