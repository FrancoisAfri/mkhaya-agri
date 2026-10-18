<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import ConfirmDialog from '@/Components/ConfirmDialog.vue'

const { t } = useI18n()
const route = ziggyRoute

// ── Types ──────────────────────────────────────────────────────────────────────
interface Invitation {
  id: number
  supplier_id: number
  supplier: string
  status: string
  invited_at: string | null
}

interface Response {
  id: number
  supplier_id: number
  supplier: string
  amount: number | null
  currency: string
  proposal: string | null
  ai_score: number | null
  ai_summary: string | null
  submitted_at: string | null
}

interface InvitedSupplier {
  id: number
  name: string
}

interface Rfx {
  id: number
  title: string
  rfx_number: string
  type: string
  status: string
  closing_date: string | null
  description: string | null
  awarded_supplier: { company_name: string } | null
}

// ── Props ──────────────────────────────────────────────────────────────────────
const props = defineProps<{
  rfx: Rfx
  invitations: Invitation[]
  responses: Response[]
  invitedSuppliers: InvitedSupplier[]
}>()

// ── State ──────────────────────────────────────────────────────────────────────
const confirmDialog = ref<InstanceType<typeof ConfirmDialog> | null>(null)
const evaluating = ref<boolean>(false)
const recording = ref<boolean>(false)

// ── Constants ──────────────────────────────────────────────────────────────────
const typeLabel: Record<string, string> = {
  rfq: t('supplier_sourcing_rfx_show.types.rfq'),
  rfp: t('supplier_sourcing_rfx_show.types.rfp'),
  rfi: t('supplier_sourcing_rfx_show.types.rfi'),
}

const statusColor: Record<string, string> = {
  draft: 'secondary',
  published: 'info',
  closed: 'warning',
  awarded: 'success',
  cancelled: 'error',
}

const invStatusColor: Record<string, string> = {
  invited: 'info',
  responded: 'success',
  declined: 'error',
}

function getStatusLabel(status: string): string {
  const map: Record<string, string> = {
    draft: t('supplier_sourcing_rfx_show.statuses.draft'),
    published: t('supplier_sourcing_rfx_show.statuses.published'),
    closed: t('supplier_sourcing_rfx_show.statuses.closed'),
    awarded: t('supplier_sourcing_rfx_show.statuses.awarded'),
    cancelled: t('supplier_sourcing_rfx_show.statuses.cancelled'),
  }
  return map[status] || status
}

function getInvStatusLabel(status: string): string {
  const map: Record<string, string> = {
    invited: t('supplier_sourcing_rfx_show.invitation_statuses.invited'),
    responded: t('supplier_sourcing_rfx_show.invitation_statuses.responded'),
    declined: t('supplier_sourcing_rfx_show.invitation_statuses.declined'),
  }
  return map[status] || status
}

function scoreColor(score: number | null): string {
  if (score === null) return 'secondary'
  if (score >= 75) return 'success'
  if (score >= 50) return 'warning'
  return 'error'
}

// ── Form ──────────────────────────────────────────────────────────────────────
const respForm = ref({
  supplier_id: null as number | null,
  amount: null as number | null,
  currency: 'ZAR',
  proposal: '',
})

// ── Methods ────────────────────────────────────────────────────────────────────
function goToIndex(): void {
  router.get(route('suppliers.sourcing.index'))
}

function publish(): void {
  router.post(route('suppliers.sourcing.publish', props.rfx.id), {}, { preserveScroll: true })
}

function close(): void {
  router.post(route('suppliers.sourcing.close', props.rfx.id), {}, { preserveScroll: true })
}

function evaluate(): void {
  evaluating.value = true
  router.post(
    route('suppliers.sourcing.evaluate', props.rfx.id),
    {},
    {
      preserveScroll: true,
      onFinish: () => (evaluating.value = false),
    }
  )
}

/**
 * The cheapest bid on the table, or null when no bid carries an amount.
 *
 * Awarding above this is the decision an auditor asks about, so it is the one the screen stops to
 * collect a reason for. Comparing here as well as on the server is not duplication for its own sake
 * — it is what lets the dialog ask the question before the request rather than after it fails.
 */
const lowestBid = computed<number | null>(() => {
  const amounts = props.responses
    .map(r => (r.amount === null || r.amount === undefined ? null : Number(r.amount)))
    .filter((a): a is number => a !== null)

  return amounts.length ? Math.min(...amounts) : null
})

function isLowestBid(supplierId: number): boolean {
  const bid = props.responses.find(r => r.supplier_id === supplierId)

  if (!bid || bid.amount === null || bid.amount === undefined) return true

  return lowestBid.value === null || Number(bid.amount) <= lowestBid.value
}

const awardReason = ref<string>('')
const awardTarget = ref<number | null>(null)
const awardDialog = ref(false)

function award(supplierId: number): void {
  // The lowest bid needs no justification, so it keeps the plain confirmation.
  if (isLowestBid(supplierId)) {
    confirmDialog.value?.open(
      {
        title: t('supplier_sourcing_rfx_show.confirm.award_title'),
        message: t('supplier_sourcing_rfx_show.confirm.award_message'),
        confirmText: t('supplier_sourcing_rfx_show.confirm.award_confirm'),
        confirmColor: 'warning',
        icon: 'tabler-alert-triangle',
      },
      () => submitAward(supplierId)
    )

    return
  }

  awardTarget.value = supplierId
  awardReason.value = ''
  awardDialog.value = true
}

function submitAward(supplierId: number, reason?: string): void {
  router.post(
    route('suppliers.sourcing.award', props.rfx.id),
    { supplier_id: supplierId, award_reason: reason ?? null },
    {
      preserveScroll: true,
      onSuccess: () => {
        awardDialog.value = false
      },
    }
  )
}

function recordResponse(): void {
  recording.value = true
  router.post(
    route('suppliers.sourcing.responses', props.rfx.id),
    respForm.value,
    {
      preserveScroll: true,
      onSuccess: () => {
        respForm.value = { supplier_id: null, amount: null, currency: 'ZAR', proposal: '' }
      },
      onFinish: () => (recording.value = false),
    }
  )
}

function formatDate(date: string | null): string {
  if (!date) return '—'
  const d = new Date(date)
  return d.toLocaleDateString('en-ZA', {
    year: 'numeric',
    month: 'short',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function formatCurrency(amount: number | null, currency: string): string {
  if (amount === null) return '—'
  return `${currency} ${Number(amount).toLocaleString()}`
}
</script>

<template>
  <div class="pa-4">
    <!-- Back Button -->
    <VBtn variant="text" prepend-icon="tabler-arrow-left" class="mb-3" @click="goToIndex">
      {{ t('supplier_sourcing_rfx_show.back') }}
    </VBtn>

    <!-- Header Card -->
    <VCard border class="mb-4">
      <VCardText class="d-flex flex-wrap align-center justify-space-between gap-3">
        <div>
          <div class="d-flex align-center gap-2 mb-1">
            <h1 class="text-h4 mb-0">{{ rfx.title }}</h1>
            <VChip size="small" :color="statusColor[rfx.status]" variant="tonal">
              {{ getStatusLabel(rfx.status) }}
            </VChip>
          </div>
          <div class="text-medium-emphasis">
            {{ rfx.rfx_number }} · {{ typeLabel[rfx.type] ?? rfx.type }}
            <span v-if="rfx.closing_date">
              · {{ t('supplier_sourcing_rfx_show.closes', { date: formatDate(rfx.closing_date) }) }}
            </span>
            <span v-if="rfx.awarded_supplier">
              · {{ t('supplier_sourcing_rfx_show.awarded_to', { name: rfx.awarded_supplier.company_name }) }}
            </span>
          </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <VBtn
            v-if="rfx.status === 'draft'"
            color="info"
            variant="tonal"
            @click="publish"
          >
            {{ t('supplier_sourcing_rfx_show.actions.publish') }}
          </VBtn>
          <VBtn
            v-if="rfx.status === 'published'"
            color="warning"
            variant="tonal"
            @click="close"
          >
            {{ t('supplier_sourcing_rfx_show.actions.close') }}
          </VBtn>
          <VBtn
            color="primary"
            variant="tonal"
            :loading="evaluating"
            :disabled="!responses.length"
            @click="evaluate"
          >
            {{ t('supplier_sourcing_rfx_show.actions.ai_evaluate') }}
          </VBtn>
        </div>
      </VCardText>
      <VCardText v-if="rfx.description" class="pt-0 text-body-2">
        {{ rfx.description }}
      </VCardText>
    </VCard>

    <!-- Invited Suppliers -->
    <VCard border class="mb-4">
      <VCardTitle>{{ t('supplier_sourcing_rfx_show.invited_suppliers') }}</VCardTitle>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('supplier_sourcing_rfx_show.table.supplier') }}</th>
            <th>{{ t('supplier_sourcing_rfx_show.table.status') }}</th>
            <th>{{ t('supplier_sourcing_rfx_show.table.invited') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!invitations.length">
            <td colspan="3" class="text-center text-medium-emphasis py-6">
              {{ t('supplier_sourcing_rfx_show.no_invitations') }}
            </td>
          </tr>
          <tr v-for="i in invitations" :key="i.id">
            <td class="font-weight-medium">{{ i.supplier ?? '—' }}</td>
            <td>
              <VChip size="small" :color="invStatusColor[i.status]" variant="tonal">
                {{ getInvStatusLabel(i.status) }}
              </VChip>
            </td>
            <td>{{ formatDate(i.invited_at) }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <!-- Responses -->
    <VCard border class="mb-4">
      <VCardTitle>{{ t('supplier_sourcing_rfx_show.responses') }}</VCardTitle>
      <VTable>
        <thead>
          <tr>
            <th>{{ t('supplier_sourcing_rfx_show.table.supplier') }}</th>
            <th>{{ t('supplier_sourcing_rfx_show.table.amount') }}</th>
            <th>{{ t('supplier_sourcing_rfx_show.table.ai_score') }}</th>
            <th>{{ t('supplier_sourcing_rfx_show.table.ai_summary') }}</th>
            <th class="text-right">{{ t('supplier_sourcing_rfx_show.table.action') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!responses.length">
            <td colspan="5" class="text-center text-medium-emphasis py-6">
              {{ t('supplier_sourcing_rfx_show.no_responses') }}
            </td>
          </tr>
          <tr v-for="r in responses" :key="r.id">
            <td class="font-weight-medium">{{ r.supplier ?? '—' }}</td>
            <td>{{ formatCurrency(r.amount, r.currency) }}</td>
            <td>
              <VChip
                v-if="r.ai_score !== null"
                size="small"
                :color="scoreColor(r.ai_score)"
                variant="tonal"
              >
                {{ r.ai_score }}
              </VChip>
              <span v-else class="text-medium-emphasis">—</span>
            </td>
            <td style="max-width:340px; white-space:pre-line;" class="text-body-2">
              {{ r.ai_summary ?? '—' }}
            </td>
            <td class="text-right">
              <VBtn
                size="x-small"
                color="success"
                variant="tonal"
                :disabled="rfx.status !== 'closed'"
                :title="rfx.status !== 'closed' ? t('supplier_sourcing_rfx_show.award_needs_close') : undefined"
                @click="award(r.supplier_id)"
              >
                {{ t('supplier_sourcing_rfx_show.actions_table.award') }}
              </VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <!-- Record Response -->
    <VCard border>
      <VCardTitle>{{ t('supplier_sourcing_rfx_show.record_response') }}</VCardTitle>
      <VCardText>
        <VRow>
          <VCol cols="12" md="4">
            <VSelect
              v-model="respForm.supplier_id"
              :items="invitedSuppliers"
              item-title="name"
              item-value="id"
              :label="t('supplier_sourcing_rfx_show.fields.supplier')"
              variant="outlined"
              density="compact"
            />
          </VCol>
          <VCol cols="6" md="3">
            <VTextField
              v-model.number="respForm.amount"
              :label="t('supplier_sourcing_rfx_show.fields.amount')"
              type="number"
              variant="outlined"
              density="compact"
            />
          </VCol>
          <VCol cols="6" md="2">
            <VTextField
              v-model="respForm.currency"
              :label="t('supplier_sourcing_rfx_show.fields.currency')"
              variant="outlined"
              density="compact"
            />
          </VCol>
          <VCol cols="12">
            <VTextarea
              v-model="respForm.proposal"
              :label="t('supplier_sourcing_rfx_show.fields.proposal')"
              rows="3"
              variant="outlined"
            />
          </VCol>
        </VRow>
      </VCardText>
      <VCardActions class="pa-4">
        <VSpacer />
        <VBtn
          color="primary"
          :loading="recording"
          :disabled="!respForm.supplier_id"
          @click="recordResponse"
        >
          {{ t('supplier_sourcing_rfx_show.record_response') }}
        </VBtn>
      </VCardActions>
    </VCard>

    <ConfirmDialog ref="confirmDialog" />
  </div>

    <!--
      Awarding away from the cheapest bid needs a reason on record, and the moment of the decision is
      the only time anyone still remembers it. Asking here rather than rejecting on submit means the
      buyer types it once.
    -->
    <VDialog v-model="awardDialog" max-width="520">
      <VCard>
        <VCardTitle>{{ t('supplier_sourcing_rfx_show.award_reason.title') }}</VCardTitle>
        <VCardText>
          <p class="text-body-2 mb-4">
            {{ t('supplier_sourcing_rfx_show.award_reason.explanation') }}
          </p>
          <VTextarea
            v-model="awardReason"
            :label="t('supplier_sourcing_rfx_show.award_reason.label')"
            :placeholder="t('supplier_sourcing_rfx_show.award_reason.placeholder')"
            variant="outlined"
            rows="3"
            autofocus
          />
        </VCardText>
        <VCardActions class="justify-end">
          <VBtn variant="text" @click="awardDialog = false">
            {{ t('supplier_sourcing_rfx_show.award_reason.cancel') }}
          </VBtn>
          <VBtn
            color="warning"
            variant="flat"
            :disabled="!awardReason.trim()"
            @click="submitAward(awardTarget!, awardReason)"
          >
            {{ t('supplier_sourcing_rfx_show.award_reason.confirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
</template>
