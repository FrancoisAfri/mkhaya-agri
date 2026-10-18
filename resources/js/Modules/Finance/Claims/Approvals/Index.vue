<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface ReceiptFile {
  id: number; name: string; mime: string | null; download_url: string | null
}

interface LineItem {
  id: number; description: string; amount: number
  receipt_required: boolean; receipt_uploaded: boolean
  receipts: ReceiptFile[]
}

interface PendingClaim {
  id: number; uuid: string; reference_number: string; employee: string; title: string
  category: string; is_travel: boolean; total_amount: number; currency: string
  status: string; risk_level: string | null; risk_score: number | null
  violations: number; has_blocks: boolean; claim_date: string
  submitted_at: string | null; age_days: number
  receipts_required: boolean; all_receipts_uploaded: boolean
  missing_receipts: number; line_items: LineItem[]
}

interface ActiveDelegation {
  id: number; delegate: string; start_date: string; end_date: string
}

const props = defineProps<{
  pendingClaims:   PendingClaim[]
  isAdmin:         boolean
  canSeeDashboard: boolean
  myDelegation:    ActiveDelegation | null
}>()

const selected      = ref<PendingClaim | null>(null)
const approveDialog = ref(false)
const rejectDialog  = ref(false)

const approveForm = useForm({ notes: '' })
const rejectForm  = useForm({ reason: '' })

function openApprove(claim: PendingClaim) { selected.value = claim; approveDialog.value = true }
function openReject(claim: PendingClaim)  { selected.value = claim; rejectDialog.value = true }

const canApproveSelected = computed(() =>
  !selected.value?.receipts_required || selected.value?.all_receipts_uploaded
)

function doApprove() {
  if (!selected.value || !canApproveSelected.value) return
  approveForm.post(route('claims.approvals.approve', { claim: selected.value.uuid }), {
    onSuccess: () => { approveDialog.value = false; approveForm.reset() }
  })
}
function doReject() {
  if (!selected.value) return
  rejectForm.post(route('claims.approvals.reject', { claim: selected.value.uuid }), {
    onSuccess: () => { rejectDialog.value = false; rejectForm.reset() }
  })
}

const statusColor = (s: string) => ({ submitted: 'blue', under_review: 'warning' })[s] ?? 'grey'
const riskColor   = (r: string | null) => ({ critical: 'error', high: 'deep-orange', medium: 'warning', low: 'success' })[r ?? ''] ?? 'grey'
const fmt = (n: number) => 'R ' + n.toLocaleString('en-ZA', { minimumFractionDigits: 2 })
const mimeIcon = (mime: string | null) => mime?.includes('pdf') ? 'tabler-file-type-pdf' : 'tabler-file-type-jpg'
const mimeColor = (mime: string | null) => mime?.includes('pdf') ? 'error' : 'primary'
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-5 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('claims_approvals.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('claims_approvals.claims_awaiting', { count: pendingClaims.length }) }}</p>
      </div>
      <div class="d-flex ga-2 flex-wrap">
        <VBtn variant="outlined" prepend-icon="tabler-user-share" :href="route('claims.approvals.delegations.index')">
          {{ t('claims_approvals.manage_delegations') }}
        </VBtn>
        <VBtn v-if="canSeeDashboard" variant="outlined" prepend-icon="tabler-layout-dashboard" :href="route('claims.dashboard')">
          {{ t('claims_approvals.dashboard') }}
        </VBtn>
      </div>
    </div>

    <!-- Delegation active banner -->
    <VAlert v-if="myDelegation" type="warning" variant="tonal" class="mb-4" icon="tabler-user-share">
      {{ t('claims_approvals.delegation_active', { delegate: myDelegation.delegate, start: myDelegation.start_date, end: myDelegation.end_date }) }}
      <a :href="route('claims.approvals.delegations.index')" class="ml-1">{{ t('claims_approvals.manage') }}</a>
    </VAlert>

    <template v-if="!pendingClaims.length">
      <VCard class="text-center pa-12" variant="outlined">
        <VIcon size="56" class="mb-3 opacity-30">tabler-checks</VIcon>
        <div class="text-h6 mb-2">{{ t('claims_approvals.all_caught_up') }}</div>
        <p class="text-body-2 text-medium-emphasis">{{ t('claims_approvals.no_claims_pending') }}</p>
      </VCard>
    </template>

    <VCard v-else>
      <VDataTable
        :items="pendingClaims"
        :headers="[
          { title: t('claims_approvals.table.reference'),   key: 'reference_number' },
          { title: t('claims_approvals.table.employee'),    key: 'employee' },
          { title: t('claims_approvals.table.title'),       key: 'title' },
          { title: t('claims_approvals.table.category'),    key: 'category' },
          { title: t('claims_approvals.table.amount'),      key: 'total_amount' },
          { title: t('claims_approvals.table.status'),      key: 'status' },
          { title: t('claims_approvals.table.risk'),        key: 'risk_level' },
          { title: t('claims_approvals.table.violations'),  key: 'violations' },
          { title: t('claims_approvals.table.receipts'),    key: 'receipts', sortable: false },
          { title: t('claims_approvals.table.age'),         key: 'age_days' },
          { title: t('claims_approvals.table.submitted'),   key: 'submitted_at' },
          { title: '',            key: 'actions', sortable: false },
        ]"
        density="comfortable"
        hover
      >
        <template #item.reference_number="{ item }">
          <a :href="route('claims.my.show', { claim: item.uuid })" class="text-primary text-decoration-none font-weight-medium">
            {{ item.reference_number }}
          </a>
        </template>
        <template #item.total_amount="{ item }">
          <span class="font-weight-bold">{{ fmt(item.total_amount) }}</span>
        </template>
        <template #item.status="{ item }">
          <VChip :color="statusColor(item.status)" size="x-small" variant="tonal">{{ item.status.replace('_', ' ') }}</VChip>
        </template>
        <template #item.risk_level="{ item }">
          <VChip v-if="item.risk_level" :color="riskColor(item.risk_level)" size="x-small" variant="tonal">{{ item.risk_level }}</VChip>
          <span v-else class="text-caption text-medium-emphasis">—</span>
        </template>
        <template #item.violations="{ item }">
          <VChip :color="item.has_blocks ? 'error' : item.violations > 0 ? 'warning' : 'grey'" size="x-small" variant="tonal">
            {{ item.violations }}{{ item.has_blocks ? t('claims_approvals.blocked') : '' }}
          </VChip>
        </template>

        <template #item.receipts="{ item }">
          <div v-if="item.is_travel" class="text-caption text-medium-emphasis">{{ t('claims_approvals.n_a') }}</div>
          <div v-else-if="!item.receipts_required" class="text-caption text-medium-emphasis">—</div>
          <div v-else-if="item.missing_receipts > 0" class="d-flex align-center ga-1">
            <VChip color="error" size="x-small" variant="tonal" prepend-icon="tabler-receipt-off">
              {{ t('claims_approvals.missing_receipts', { count: item.missing_receipts }) }}
            </VChip>
          </div>
          <div v-else class="d-flex align-center ga-1 flex-wrap">
            <template v-for="li in item.line_items.filter(l => l.receipts.length)" :key="li.id">
              <VBtn
                v-for="receipt in li.receipts" :key="receipt.id"
                :href="receipt.download_url"
                target="_blank"
                size="x-small"
                variant="tonal"
                :color="mimeColor(receipt.mime)"
                :prepend-icon="mimeIcon(receipt.mime)"
                :title="receipt.name"
              >{{ t('claims_approvals.view') }}</VBtn>
            </template>
            <VChip v-if="item.all_receipts_uploaded" color="success" size="x-small" variant="tonal" prepend-icon="tabler-circle-check">
              {{ t('claims_approvals.ok') }}
            </VChip>
          </div>
        </template>

        <template #item.age_days="{ item }">
          <VChip :color="item.age_days > 30 ? 'error' : item.age_days > 14 ? 'warning' : 'grey'" size="x-small" variant="tonal">{{ item.age_days }}d</VChip>
        </template>
        <template #item.actions="{ item }">
          <div class="d-flex ga-1">
            <VTooltip location="top" :text="item.receipts_required && !item.all_receipts_uploaded ? t('claims_approvals.cannot_approve_tooltip') : t('claims_approvals.approve')">
              <template #activator="{ props: tp }">
                <span v-bind="tp">
                  <VBtn
                    icon="tabler-check"
                    size="x-small"
                    color="success"
                    variant="tonal"
                    :disabled="item.receipts_required && !item.all_receipts_uploaded"
                    @click="openApprove(item)"
                  />
                </span>
              </template>
            </VTooltip>
            <VBtn icon="tabler-x" size="x-small" color="error" variant="tonal" @click="openReject(item)" :title="t('claims_approvals.reject')" />
          </div>
        </template>
      </VDataTable>
    </VCard>

    <!-- ── Approve Dialog ─────────────────────────────────────────────────────── -->
    <VDialog v-model="approveDialog" max-width="560" persistent>
      <VCard v-if="selected">
        <VCardTitle class="d-flex align-center ga-2 pa-4">
          <VIcon color="success">tabler-circle-check</VIcon>
          {{ t('claims_approvals.dialog.approve_title', { reference: selected.reference_number }) }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pt-4">
          <VAlert
            v-if="selected.receipts_required && !selected.all_receipts_uploaded"
            type="error"
            variant="tonal"
            density="compact"
            class="mb-4"
            prepend-icon="tabler-receipt-off"
          >
            {{ t('claims_approvals.dialog.cannot_approve_alert', { count: selected.missing_receipts }) }}
          </VAlert>

          <VAlert variant="tonal" color="primary" density="compact" class="mb-4">
            <div class="d-flex justify-space-between align-center">
              <div>
                <div class="font-weight-bold">{{ selected.employee }}</div>
                <div class="text-caption">{{ selected.title }} — {{ selected.category }}</div>
              </div>
              <div class="text-h6 font-weight-bold">{{ fmt(selected.total_amount) }}</div>
            </div>
          </VAlert>

          <div v-if="selected.line_items.length" class="mb-4">
            <div class="text-body-2 font-weight-medium mb-2">{{ t('claims_approvals.dialog.line_items_receipts') }}</div>
            <VTable density="compact">
              <thead>
                <tr class="text-caption">
                  <th>{{ t('claims_approvals.dialog.item') }}</th>
                  <th class="text-right">{{ t('claims_approvals.dialog.amount') }}</th>
                  <th>{{ t('claims_approvals.dialog.receipt') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="li in selected.line_items" :key="li.id">
                  <td class="text-body-2">{{ li.description }}</td>
                  <td class="text-right text-no-wrap">{{ fmt(li.amount) }}</td>
                  <td>
                    <div v-if="li.receipt_uploaded && li.receipts.length" class="d-flex ga-1 flex-wrap">
                      <VBtn
                        v-for="receipt in li.receipts" :key="receipt.id"
                        :href="receipt.download_url"
                        target="_blank"
                        size="x-small"
                        variant="tonal"
                        :color="mimeColor(receipt.mime)"
                        :prepend-icon="mimeIcon(receipt.mime)"
                      >{{ receipt.name }}</VBtn>
                    </div>
                    <VChip v-else-if="li.receipt_required" color="error" size="x-small" variant="tonal" prepend-icon="tabler-alert-circle">
                      {{ t('claims_approvals.dialog.missing') }}
                    </VChip>
                    <span v-else class="text-caption text-medium-emphasis">—</span>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </div>

          <VTextarea v-model="approveForm.notes" :label="t('claims_approvals.dialog.approval_notes')" variant="outlined" density="compact" rows="2" />
        </VCardText>
        <VDivider />
        <VCardActions class="pa-3">
          <VSpacer />
          <VBtn variant="outlined" @click="approveDialog = false">{{ t('claims_approvals.dialog.cancel') }}</VBtn>
          <VBtn
            color="success"
            :loading="approveForm.processing"
            :disabled="!canApproveSelected"
            prepend-icon="tabler-check"
            @click="doApprove"
          >{{ t('claims_approvals.dialog.approve_btn') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ── Reject Dialog ──────────────────────────────────────────────────────── -->
    <VDialog v-model="rejectDialog" max-width="500" persistent>
      <VCard v-if="selected">
        <VCardTitle class="d-flex align-center ga-2 pa-4">
          <VIcon color="error">tabler-circle-x</VIcon>
          {{ t('claims_approvals.dialog.reject_title', { reference: selected.reference_number }) }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pt-4">
          <div v-if="selected.line_items.length && selected.receipts_required" class="mb-4">
            <div class="text-body-2 font-weight-medium mb-2">{{ t('claims_approvals.dialog.line_items_receipts') }}</div>
            <VTable density="compact">
              <thead>
                <tr class="text-caption"><th>{{ t('claims_approvals.dialog.item') }}</th><th class="text-right">{{ t('claims_approvals.dialog.amount') }}</th><th>{{ t('claims_approvals.dialog.receipt') }}</th></tr>
              </thead>
              <tbody>
                <tr v-for="li in selected.line_items" :key="li.id">
                  <td class="text-body-2">{{ li.description }}</td>
                  <td class="text-right text-no-wrap">{{ fmt(li.amount) }}</td>
                  <td>
                    <div v-if="li.receipt_uploaded && li.receipts.length" class="d-flex ga-1 flex-wrap">
                      <VBtn
                        v-for="receipt in li.receipts" :key="receipt.id"
                        :href="receipt.download_url" target="_blank"
                        size="x-small" variant="tonal"
                        :color="mimeColor(receipt.mime)" :prepend-icon="mimeIcon(receipt.mime)"
                      >{{ receipt.name }}</VBtn>
                    </div>
                    <VChip v-else-if="li.receipt_required" color="error" size="x-small" variant="tonal" prepend-icon="tabler-alert-circle">{{ t('claims_approvals.dialog.missing') }}</VChip>
                    <span v-else class="text-caption text-medium-emphasis">—</span>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </div>
          <VTextarea v-model="rejectForm.reason" :label="t('claims_approvals.dialog.rejection_reason')" variant="outlined" density="compact" rows="3"
            :error-messages="rejectForm.errors.reason" />
        </VCardText>
        <VDivider />
        <VCardActions class="pa-3">
          <VSpacer />
          <VBtn variant="outlined" @click="rejectDialog = false">{{ t('claims_approvals.dialog.cancel') }}</VBtn>
          <VBtn color="error" :loading="rejectForm.processing" prepend-icon="tabler-x" @click="doReject">{{ t('claims_approvals.dialog.reject_btn') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
