<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Recon {
  id: number; uuid: string; float_name: string | null
  reconciled_by: string | null; approved_by: string | null
  period_start: string; period_end: string
  opening_balance: number; total_replenishments: number; total_issued: number
  total_receipts: number; cash_on_hand: number; closing_balance: number; variance: number
  has_shortage: boolean; status: string; notes: string | null; rejection_reason: string | null
  submitted_at: string | null; approved_at: string | null
}

interface Attachment  { id: number; original_name: string; url: string }
interface Violation   { id: number; type: string; description: string; severity: string; status: string }

const props = defineProps<{
  reconciliation: Recon
  attachments: Attachment[]
  policyViolations: Violation[]
}>()

const approveDialog = ref(false)
const rejectDialog  = ref(false)
const rejectForm    = useForm({ reason: '' })

const approve = () => useForm({}).post(route('petty-cash.reconciliations.approve', props.reconciliation.uuid))
const reject  = () => rejectForm.post(route('petty-cash.reconciliations.reject', props.reconciliation.uuid), { onSuccess: () => { rejectDialog.value = false } })

const fmt = (n: number) => 'R ' + Number(n).toLocaleString('en-ZA', { minimumFractionDigits: 2 })
const statusColor = (s: string) => ({ draft: 'grey', submitted: 'blue', approved: 'success', rejected: 'error' })[s] ?? 'grey'
const severityColor = (s: string) => ({ low: 'success', medium: 'warning', high: 'deep-orange', critical: 'error' })[s] ?? 'grey'
</script>

<template>
  <div class="pa-4">
    <VBtn variant="text" prepend-icon="tabler-arrow-left" :href="route('petty-cash.reconciliations.index')" class="mb-3">
      {{ t('petty_cash_reconciliations_show.back') }}
    </VBtn>

    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('petty_cash_reconciliations_show.page_title', { float: reconciliation.float_name }) }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('petty_cash_reconciliations_show.period', { start: reconciliation.period_start, end: reconciliation.period_end }) }}</p>
      </div>
      <div class="d-flex ga-2">
        <VChip :color="statusColor(reconciliation.status)" variant="flat">{{ reconciliation.status }}</VChip>
        <template v-if="reconciliation.status === 'submitted'">
          <VBtn color="success" @click="approve">{{ t('petty_cash_reconciliations_show.actions.approve') }}</VBtn>
          <VBtn color="error" variant="outlined" @click="rejectDialog = true">{{ t('petty_cash_reconciliations_show.actions.reject') }}</VBtn>
        </template>
      </div>
    </div>

    <VAlert v-if="reconciliation.has_shortage" type="error" variant="tonal" class="mb-4" prominent>
      <strong>{{ t('petty_cash_reconciliations_show.shortage_alert', { variance: fmt(reconciliation.variance) }) }}</strong>
    </VAlert>
    <VAlert v-if="reconciliation.rejection_reason" type="warning" variant="tonal" class="mb-4">
      {{ t('petty_cash_reconciliations_show.rejected_alert', { reason: reconciliation.rejection_reason }) }}
    </VAlert>

    <VRow>
      <VCol cols="12" md="8">
        <VCard elevation="0" border class="mb-4">
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">{{ t('petty_cash_reconciliations_show.summary.title') }}</VCardTitle>
          <VDivider />
          <VCardText class="pa-4">
            <VRow dense>
              <VCol cols="6">
                <span class="text-caption text-medium-emphasis">{{ t('petty_cash_reconciliations_show.summary.opening_balance') }}</span>
                <div class="text-body-1 font-weight-medium">{{ fmt(reconciliation.opening_balance) }}</div>
              </VCol>
              <VCol cols="6">
                <span class="text-caption text-medium-emphasis">{{ t('petty_cash_reconciliations_show.summary.replenishments') }}</span>
                <div class="text-body-1 font-weight-medium text-success">+ {{ fmt(reconciliation.total_replenishments) }}</div>
              </VCol>
              <VCol cols="6">
                <span class="text-caption text-medium-emphasis">{{ t('petty_cash_reconciliations_show.summary.total_issued') }}</span>
                <div class="text-body-1 font-weight-medium text-error">- {{ fmt(reconciliation.total_issued) }}</div>
              </VCol>
              <VCol cols="6">
                <span class="text-caption text-medium-emphasis">{{ t('petty_cash_reconciliations_show.summary.total_receipts') }}</span>
                <div class="text-body-1 font-weight-medium">{{ fmt(reconciliation.total_receipts) }}</div>
              </VCol>
              <VCol cols="6">
                <span class="text-caption text-medium-emphasis">{{ t('petty_cash_reconciliations_show.summary.cash_on_hand') }}</span>
                <div class="text-body-1 font-weight-medium">{{ fmt(reconciliation.cash_on_hand) }}</div>
              </VCol>
              <VCol cols="6">
                <span class="text-caption text-medium-emphasis">{{ t('petty_cash_reconciliations_show.summary.variance') }}</span>
                <div class="text-h6 font-weight-bold" :class="reconciliation.has_shortage ? 'text-error' : reconciliation.variance > 0 ? 'text-warning' : 'text-success'">
                  {{ reconciliation.variance > 0 ? '+' : '' }}{{ fmt(reconciliation.variance) }}
                </div>
              </VCol>
              <VCol cols="12" v-if="reconciliation.notes">
                <span class="text-caption text-medium-emphasis">{{ t('petty_cash_reconciliations_show.summary.notes') }}</span>
                <div class="text-body-2">{{ reconciliation.notes }}</div>
              </VCol>
            </VRow>
          </VCardText>
        </VCard>

        <!-- Policy Violations -->
        <VCard v-if="policyViolations.length" elevation="0" border>
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">
            <VIcon color="error" class="mr-1">tabler-shield-exclamation</VIcon> {{ t('petty_cash_reconciliations_show.policy_violations.title') }}
          </VCardTitle>
          <VDivider />
          <VList density="compact">
            <VListItem v-for="violation in policyViolations" :key="violation.id">
              <template #prepend>
                <VChip :color="severityColor(violation.severity)" size="x-small" variant="flat" class="mr-2">{{ violation.severity }}</VChip>
              </template>
              <VListItemTitle class="text-body-2">{{ violation.description }}</VListItemTitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>

      <VCol cols="12" md="4">
        <VCard elevation="0" border class="mb-4">
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">{{ t('petty_cash_reconciliations_show.details.title') }}</VCardTitle>
          <VDivider />
          <VCardText class="pa-4">
            <div class="mb-2">
              <span class="text-caption text-medium-emphasis">{{ t('petty_cash_reconciliations_show.details.reconciled_by') }}</span>
              <div class="text-body-2">{{ reconciliation.reconciled_by ?? '—' }}</div>
            </div>
            <div class="mb-2">
              <span class="text-caption text-medium-emphasis">{{ t('petty_cash_reconciliations_show.details.approved_by') }}</span>
              <div class="text-body-2">{{ reconciliation.approved_by ?? '—' }}</div>
            </div>
            <div class="mb-2">
              <span class="text-caption text-medium-emphasis">{{ t('petty_cash_reconciliations_show.details.submitted') }}</span>
              <div class="text-body-2">{{ reconciliation.submitted_at ? reconciliation.submitted_at.substring(0, 10) : '—' }}</div>
            </div>
            <div>
              <span class="text-caption text-medium-emphasis">{{ t('petty_cash_reconciliations_show.details.approved') }}</span>
              <div class="text-body-2">{{ reconciliation.approved_at ? reconciliation.approved_at.substring(0, 10) : '—' }}</div>
            </div>
          </VCardText>
        </VCard>

        <VCard elevation="0" border>
          <VCardTitle class="pa-4 pb-2 text-body-1 font-weight-semibold">{{ t('petty_cash_reconciliations_show.attachments.title') }}</VCardTitle>
          <VDivider />
          <VList density="compact">
            <VListItem v-for="attachment in attachments" :key="attachment.id" :href="attachment.url" target="_blank">
              <template #prepend><VIcon size="18" class="mr-2">tabler-file-text</VIcon></template>
              <VListItemTitle class="text-body-2">{{ attachment.original_name }}</VListItemTitle>
              <template #append><VIcon size="16">tabler-external-link</VIcon></template>
            </VListItem>
            <VListItem v-if="!attachments.length">
              <VListItemSubtitle class="text-caption text-medium-emphasis">{{ t('petty_cash_reconciliations_show.attachments.no_documents') }}</VListItemSubtitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>

    <!-- Reject Dialog -->
    <VDialog v-model="rejectDialog" max-width="400">
      <VCard>
        <VCardTitle class="pa-4">{{ t('petty_cash_reconciliations_show.dialog.reject_title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VTextarea
            v-model="rejectForm.reason"
            :label="t('petty_cash_reconciliations_show.dialog.reason')"
            variant="outlined"
            rows="3"
            density="comfortable"
            :error-messages="rejectForm.errors.reason"
          />
        </VCardText>
        <VCardActions class="pa-4 pt-0">
          <VSpacer />
          <VBtn variant="text" @click="rejectDialog = false">{{ t('petty_cash_reconciliations_show.dialog.cancel') }}</VBtn>
          <VBtn color="error" :loading="rejectForm.processing" @click="reject">{{ t('petty_cash_reconciliations_show.dialog.reject_btn') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
