<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface TravelTrip {
  id: number; trip_date: string; departure_time: string
  origin: string; destination: string
  distance_km: number; km_rate: number
  base_amount: number; peak_surcharge: number; total_amount: number
  is_peak_hour: boolean; traffic_condition: string | null; notes: string | null
}

interface ClaimItem {
  id: number; item_date: string | null; description: string
  unit_price: number; quantity: number; amount: number
  item_type: string | null; receipt_required: boolean; receipt_uploaded: boolean
  attachments: { id: number; name: string; type: string; download_url: string | null }[]
}

interface Claim {
  id: number; uuid: string; reference_number: string; employee: string; title: string
  category: string; is_travel: boolean; requires_receipt: boolean; total_amount: number; currency: string
  status: string; status_color: string; risk_level: string | null; risk_score: number | null
  claim_date: string; submitted_at: string | null; approved_at: string | null
  rejected_at: string | null; paid_at: string | null; rejection_reason: string | null
  description: string | null; notes: string | null; age_days: number
  items: ClaimItem[]; travel_trips: TravelTrip[]
  attachments: any[]; approvals: any[]; violations: any[]; risk: any | null
}

const props = defineProps<{ claim: Claim; isAdmin: boolean; canApprove: boolean }>()

const itemsMissingReceipts = computed(() =>
  props.claim.requires_receipt
    ? props.claim.items.filter(item => !item.receipt_uploaded)
    : props.claim.items.filter(item => item.receipt_required && !item.receipt_uploaded)
)

const tab = ref('details')
const approveDialog = ref(false)
const rejectDialog  = ref(false)
const payDialog     = ref(false)

const receiptDialog  = ref(false)
const receiptItemId  = ref<number | null>(null)
const receiptFile    = ref<File | null>(null)
const receiptFileRef = ref<HTMLInputElement | null>(null)
const uploadingReceipt = ref(false)

function openReceiptUpload(itemId: number) {
  receiptItemId.value = itemId
  receiptFile.value   = null
  receiptDialog.value = true
}

function onReceiptFileChange(e: Event) {
  receiptFile.value = (e.target as HTMLInputElement).files?.[0] ?? null
}

function uploadReceipt() {
  if (!receiptFile.value) return
  uploadingReceipt.value = true
  const fd = new FormData()
  fd.append('file', receiptFile.value)
  fd.append('attachment_type', 'receipt')
  if (receiptItemId.value) fd.append('item_id', String(receiptItemId.value))

  router.post(route('claims.my.receipts.upload', { claim: props.claim.uuid }), fd as any, {
    forceFormData: true,
    onSuccess: () => { receiptDialog.value = false; receiptFile.value = null },
    onFinish:  () => { uploadingReceipt.value = false },
  })
}

const approveForm = useForm({ notes: '' })
const rejectForm  = useForm({ reason: '' })
const payForm     = useForm({ notes: '' })

function submitClaim() {
  router.post(route('claims.my.submit', { claim: props.claim.uuid }))
}

function doApprove() {
  approveForm.post(route('claims.approvals.approve', { claim: props.claim.uuid }), {
    onSuccess: () => { approveDialog.value = false }
  })
}

function doReject() {
  rejectForm.post(route('claims.approvals.reject', { claim: props.claim.uuid }), {
    onSuccess: () => { rejectDialog.value = false }
  })
}

function doPay() {
  payForm.post(route('claims.approvals.pay', { claim: props.claim.uuid }), {
    onSuccess: () => { payDialog.value = false }
  })
}

const statusColor = (s: string) => ({
  draft: 'grey', submitted: 'blue', under_review: 'warning',
  approved: 'success', rejected: 'error', paid: 'deep-purple', cancelled: 'grey',
})[s] ?? 'grey'

const riskColor = (r: string | null) => ({
  critical: 'error', high: 'deep-orange', medium: 'warning', low: 'success',
})[r ?? ''] ?? 'grey'

const fmt = (n: number) => 'R ' + n.toLocaleString('en-ZA', { minimumFractionDigits: 2 })
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center ga-2 mb-4 flex-wrap">
      <VBtn icon="tabler-arrow-left" variant="text" :href="route('claims.my.index')" />
      <div class="flex-grow-1">
        <div class="d-flex align-center ga-2 flex-wrap">
          <h1 class="text-h5 font-weight-bold">{{ claim.reference_number }}</h1>
          <VChip :color="statusColor(claim.status)" variant="tonal" size="small">{{ claim.status.replace('_', ' ') }}</VChip>
          <VChip v-if="claim.risk_level" :color="riskColor(claim.risk_level)" variant="tonal" size="small">
            <VIcon start size="12">tabler-shield-exclamation</VIcon>{{ claim.risk_level }} risk
          </VChip>
        </div>
        <p class="text-body-2 text-medium-emphasis">{{ claim.title }} — {{ claim.employee }}</p>
      </div>
      <div class="d-flex ga-2">
        <VTooltip v-if="claim.status === 'draft' && itemsMissingReceipts.length" location="bottom">
          <template #activator="{ props: tp }">
            <span v-bind="tp">
              <VBtn color="primary" prepend-icon="tabler-send" disabled>{{ t('claims_show.submit_claim') }}</VBtn>
            </span>
          </template>
          {{ t('claims_show.items_require_receipt', { count: itemsMissingReceipts.length }) }}
        </VTooltip>
        <VBtn v-else-if="claim.status === 'draft'" color="primary" prepend-icon="tabler-send" @click="submitClaim">{{ t('claims_show.submit_claim') }}</VBtn>
        <VBtn v-if="canApprove && ['submitted','under_review'].includes(claim.status)" color="success" prepend-icon="tabler-check" @click="approveDialog = true">{{ t('claims_show.approve') }}</VBtn>
        <VBtn v-if="canApprove && ['submitted','under_review'].includes(claim.status)" color="error" variant="outlined" prepend-icon="tabler-x" @click="rejectDialog = true">{{ t('claims_show.reject') }}</VBtn>
        <VBtn v-if="isAdmin && claim.status === 'approved'" color="deep-purple" prepend-icon="tabler-cash-banknote" @click="payDialog = true">{{ t('claims_show.mark_paid') }}</VBtn>
      </div>
    </div>

    <VCard class="mb-4 pa-3" variant="tonal" color="primary">
      <VRow dense>
        <VCol cols="6" sm="3"><div class="text-caption">{{ t('claims_show.summary.category') }}</div><div class="text-body-2 font-weight-bold">{{ claim.category }}</div></VCol>
        <VCol cols="6" sm="3"><div class="text-caption">{{ t('claims_show.summary.claim_date') }}</div><div class="text-body-2 font-weight-bold">{{ claim.claim_date }}</div></VCol>
        <VCol cols="6" sm="3"><div class="text-caption">{{ t('claims_show.summary.total_amount') }}</div><div class="text-h6 font-weight-bold">{{ fmt(claim.total_amount) }}</div></VCol>
        <VCol cols="6" sm="3"><div class="text-caption">{{ t('claims_show.summary.age') }}</div><div class="text-body-2 font-weight-bold" :class="claim.age_days > 30 ? 'text-error' : ''">{{ t('claims_show.summary.days', { count: claim.age_days }) }}</div></VCol>
      </VRow>
    </VCard>

    <VTabs v-model="tab" density="compact" class="mb-4">
      <VTab value="details">{{ t('claims_show.tabs.details') }}</VTab>
      <VTab value="approvals">{{ t('claims_show.tabs.approvals') }} <VChip v-if="claim.approvals.length" size="x-small" class="ml-1">{{ claim.approvals.length }}</VChip></VTab>
      <VTab value="violations">{{ t('claims_show.tabs.policy') }} <VChip v-if="claim.violations.length" color="warning" size="x-small" class="ml-1">{{ claim.violations.length }}</VChip></VTab>
      <VTab value="risk">{{ t('claims_show.tabs.ai_risk') }}</VTab>
      <VTab value="attachments">{{ t('claims_show.tabs.attachments') }} <VChip v-if="claim.attachments.length" size="x-small" class="ml-1">{{ claim.attachments.length }}</VChip></VTab>
    </VTabs>

    <VWindow v-model="tab">
      <VWindowItem value="details">
        <VCard class="pa-4 mb-3">
          <p v-if="claim.description" class="text-body-2 mb-4">{{ claim.description }}</p>
          <VAlert v-if="claim.rejection_reason" type="error" density="compact" class="mb-3">
            {{ t('claims_show.rejection_label', { reason: claim.rejection_reason }) }}
          </VAlert>

          <template v-if="claim.is_travel || claim.travel_trips?.length">
            <div class="d-flex align-center ga-2 mb-3">
              <VIcon color="primary" size="18">tabler-car</VIcon>
              <span class="text-body-2 font-weight-bold">{{ t('claims_show.travel_items.title') }}</span>
              <VChip size="x-small" color="primary" variant="tonal">{{ t('claims_show.travel_items.trips_count', { count: claim.travel_trips?.length ?? 0 }) }}</VChip>
            </div>

            <div v-if="!claim.travel_trips?.length" class="text-center text-medium-emphasis pa-4">
              {{ t('claims_show.travel_items.empty') }}
            </div>

            <VTable v-else density="compact" class="mb-2">
              <thead>
                <tr class="text-caption">
                  <th>{{ t('claims_show.travel_items.table.date') }}</th>
                  <th>{{ t('claims_show.travel_items.table.route') }}</th>
                  <th class="text-right">{{ t('claims_show.travel_items.table.distance') }}</th>
                  <th class="text-right">{{ t('claims_show.travel_items.table.rate_per_km') }}</th>
                  <th class="text-right">{{ t('claims_show.travel_items.table.base_amount') }}</th>
                  <th class="text-right">{{ t('claims_show.travel_items.table.peak_surcharge') }}</th>
                  <th class="text-right">{{ t('claims_show.travel_items.table.trip_total') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="trip in claim.travel_trips" :key="trip.id">
                  <td class="text-no-wrap">
                    <div class="text-body-2">{{ trip.trip_date }}</div>
                    <div class="text-caption text-medium-emphasis">{{ trip.departure_time }}</div>
                  </td>
                  <td>
                    <div class="d-flex align-center ga-1 flex-wrap">
                      <span class="text-body-2">{{ trip.origin }}</span>
                      <VIcon size="14" color="medium-emphasis">tabler-arrow-right</VIcon>
                      <span class="text-body-2">{{ trip.destination }}</span>
                    </div>
                    <div class="d-flex ga-1 mt-1">
                      <VChip v-if="trip.is_peak_hour" size="x-small" color="warning" variant="tonal" prepend-icon="tabler-clock-exclamation">
                        {{ t('claims_show.travel_items.peak_hour') }}
                      </VChip>
                      <VChip v-if="trip.traffic_condition" size="x-small"
                        :color="trip.traffic_condition === 'heavy' ? 'error' : trip.traffic_condition === 'moderate' ? 'warning' : 'success'"
                        variant="tonal">
                        {{ t('claims_show.travel_items.traffic', { condition: trip.traffic_condition }) }}
                      </VChip>
                    </div>
                  </td>
                  <td class="text-right text-no-wrap">{{ trip.distance_km }} km</td>
                  <td class="text-right text-no-wrap text-medium-emphasis">R {{ Number(trip.km_rate).toFixed(2) }}</td>
                  <td class="text-right text-no-wrap">{{ fmt(trip.base_amount) }}</td>
                  <td class="text-right text-no-wrap">
                    <span v-if="trip.peak_surcharge > 0" class="text-warning font-weight-medium">+ {{ fmt(trip.peak_surcharge) }}</span>
                    <span v-else class="text-medium-emphasis">—</span>
                  </td>
                  <td class="text-right text-no-wrap font-weight-bold">{{ fmt(trip.total_amount) }}</td>
                </tr>
              </tbody>
              <tfoot>
                <tr class="bg-surface-variant">
                  <td colspan="2" class="font-weight-bold text-right text-caption">{{ t('claims_show.travel_items.total_label', { count: claim.travel_trips.length }) }}</td>
                  <td class="text-right font-weight-bold text-caption">{{ claim.travel_trips.reduce((s, trip) => s + trip.distance_km, 0).toFixed(1) }} km</td>
                  <td></td>
                  <td class="text-right font-weight-bold text-caption">{{ fmt(claim.travel_trips.reduce((s, trip) => s + trip.base_amount, 0)) }}</td>
                  <td class="text-right font-weight-bold text-caption text-warning">
                    <span v-if="claim.travel_trips.some(trip => trip.peak_surcharge > 0)">+ {{ fmt(claim.travel_trips.reduce((s, trip) => s + trip.peak_surcharge, 0)) }}</span>
                  </td>
                  <td class="text-right font-weight-bold text-primary text-body-1">{{ fmt(claim.total_amount) }}</td>
                </tr>
              </tfoot>
            </VTable>
          </template>

          <template v-else>
            <VAlert
              v-if="claim.status === 'draft' && itemsMissingReceipts.length"
              type="warning"
              variant="tonal"
              density="compact"
              class="mb-3"
              prepend-icon="tabler-receipt-off"
            >
              {{ t('claims_show.line_items.missing_receipts_warning', { count: itemsMissingReceipts.length, items: itemsMissingReceipts.map(item => item.description).join(', ') }) }}
            </VAlert>
            <div class="d-flex align-center ga-2 mb-3">
              <VIcon color="primary" size="18">tabler-receipt</VIcon>
              <span class="text-body-2 font-weight-bold">{{ t('claims_show.line_items.title') }}</span>
              <VChip size="x-small" color="primary" variant="tonal">{{ t('claims_show.line_items.item_count', { count: claim.items.length }) }}</VChip>
            </div>

            <div v-if="!claim.items.length" class="text-center text-medium-emphasis pa-4">
              {{ t('claims_show.line_items.empty') }}
            </div>

            <VTable v-else density="compact" class="mb-2">
              <thead>
                <tr class="text-caption">
                  <th>{{ t('claims_show.line_items.table.date') }}</th>
                  <th>{{ t('claims_show.line_items.table.description') }}</th>
                  <th class="text-right">{{ t('claims_show.line_items.table.unit_price') }}</th>
                  <th class="text-right">{{ t('claims_show.line_items.table.qty') }}</th>
                  <th class="text-right">{{ t('claims_show.line_items.table.amount') }}</th>
                  <th>{{ t('claims_show.line_items.table.type') }}</th>
                  <th class="text-center">{{ t('claims_show.line_items.table.receipt') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in claim.items" :key="item.id" :class="{ 'bg-warning-subtle': item.receipt_required && !item.receipt_uploaded && claim.status === 'draft' }">
                  <td class="text-no-wrap text-caption text-medium-emphasis">{{ item.item_date || '—' }}</td>
                  <td>{{ item.description }}</td>
                  <td class="text-right text-no-wrap">{{ fmt(item.unit_price) }}</td>
                  <td class="text-right">{{ item.quantity }}</td>
                  <td class="text-right font-weight-bold text-no-wrap">{{ fmt(item.amount) }}</td>
                  <td><span class="text-caption text-medium-emphasis">{{ item.item_type || '—' }}</span></td>
                  <td class="text-center" style="min-width:130px">
                    <div v-if="item.receipt_uploaded" class="d-flex align-center justify-center ga-1">
                      <VIcon color="success" size="16">tabler-circle-check</VIcon>
                      <VBtn v-for="att in item.attachments.filter(a => a.type === 'receipt')" :key="att.id"
                        :href="att.download_url" size="x-small" variant="text" color="primary"
                        icon="tabler-download" target="_blank"
                      />
                    </div>
                    <div v-else-if="item.receipt_required">
                      <VBtn v-if="claim.status === 'draft'" size="x-small" color="warning" variant="tonal" prepend-icon="tabler-upload"
                        @click="openReceiptUpload(item.id)">{{ t('claims_show.line_items.upload') }}</VBtn>
                      <VIcon v-else color="warning" size="16">tabler-alert-circle</VIcon>
                    </div>
                    <VIcon v-else color="grey" size="16">tabler-circle-minus</VIcon>
                  </td>
                </tr>
              </tbody>
              <tfoot>
                <tr class="bg-surface-variant">
                  <td colspan="4" class="text-right font-weight-bold text-caption">{{ t('claims_show.line_items.total') }}</td>
                  <td class="text-right font-weight-bold text-primary text-body-1">{{ fmt(claim.total_amount) }}</td>
                  <td colspan="2"></td>
                </tr>
              </tfoot>
            </VTable>
          </template>
        </VCard>
      </VWindowItem>

      <VWindowItem value="approvals">
        <VCard class="pa-4">
          <VTimeline density="compact" side="end">
            <VTimelineItem v-for="a in claim.approvals" :key="a.id" :dot-color="a.color" size="small">
              <div class="text-body-2 font-weight-bold">Level {{ a.level }}: {{ a.approver }}</div>
              <VChip :color="a.color" size="x-small" variant="tonal" class="mr-1">{{ a.action }}</VChip>
              <span v-if="a.actioned_at" class="text-caption text-medium-emphasis">{{ a.actioned_at }}</span>
              <p v-if="a.notes" class="text-caption mt-1">{{ a.notes }}</p>
              <p v-if="a.reason" class="text-caption text-error mt-1">Reason: {{ a.reason }}</p>
            </VTimelineItem>
          </VTimeline>
          <p v-if="!claim.approvals.length" class="text-medium-emphasis text-center pa-4">{{ t('claims_show.approvals_empty') }}</p>
        </VCard>
      </VWindowItem>

      <VWindowItem value="violations">
        <VCard class="pa-4">
          <VAlert v-for="v in claim.violations" :key="v.id" :type="v.severity === 'block' ? 'error' : 'warning'" density="compact" class="mb-2" variant="tonal">
            <strong>{{ v.type.replace(/_/g, ' ') }}</strong> — {{ v.description }}
            <VChip v-if="v.exception_approved" color="success" size="x-small" class="ml-2">Exception Approved</VChip>
            <VChip v-else-if="v.exception_requested" color="info" size="x-small" class="ml-2">Exception Requested</VChip>
          </VAlert>
          <p v-if="!claim.violations.length" class="text-medium-emphasis text-center pa-4">{{ t('claims_show.violations_empty') }}</p>
        </VCard>
      </VWindowItem>

      <VWindowItem value="risk">
        <VCard class="pa-4">
          <template v-if="claim.risk">
            <div class="d-flex align-center ga-3 mb-4">
              <VProgressCircular :model-value="claim.risk.score" :color="riskColor(claim.risk.level)" size="70" width="8">
                <span class="text-body-1 font-weight-bold">{{ claim.risk.score }}</span>
              </VProgressCircular>
              <div>
                <div class="text-h6 font-weight-bold" :class="`text-${riskColor(claim.risk.level)}`">{{ t('claims_show.ai_risk.risk_level', { level: claim.risk.level?.toUpperCase() }) }}</div>
                <div class="text-caption text-medium-emphasis">{{ t('claims_show.ai_risk.score', { score: claim.risk.score }) }}</div>
              </div>
            </div>
            <div class="d-flex ga-2 flex-wrap mb-4">
              <VChip v-if="claim.risk.is_duplicate"    color="error"  size="small" variant="tonal" prepend-icon="tabler-copy">{{ t('claims_show.ai_risk.duplicate') }}</VChip>
              <VChip v-if="claim.risk.is_inflated"     color="orange" size="small" variant="tonal" prepend-icon="tabler-trending-up">{{ t('claims_show.ai_risk.inflated') }}</VChip>
              <VChip v-if="claim.risk.is_splitting"    color="deep-orange" size="small" variant="tonal" prepend-icon="tabler-git-branch">{{ t('claims_show.ai_risk.splitting') }}</VChip>
              <VChip v-if="claim.risk.missing_receipt" color="warning" size="small" variant="tonal" prepend-icon="tabler-receipt-off">{{ t('claims_show.ai_risk.missing_receipt') }}</VChip>
              <VChip v-if="claim.risk.out_of_policy"   color="error"  size="small" variant="tonal" prepend-icon="tabler-shield-exclamation">{{ t('claims_show.ai_risk.out_of_policy') }}</VChip>
            </div>
            <VCard variant="outlined" class="pa-3">
              <div class="text-caption font-weight-bold mb-1">{{ t('claims_show.ai_risk.explanation') }}</div>
              <pre class="text-caption" style="white-space:pre-wrap">{{ claim.risk.explanation }}</pre>
            </VCard>
          </template>
          <div v-else class="text-center pa-6 text-medium-emphasis">
            <VIcon size="48" class="opacity-30 mb-2">tabler-robot</VIcon>
            <div>{{ t('claims_show.ai_risk_empty') }}</div>
          </div>
        </VCard>
      </VWindowItem>

      <VWindowItem value="attachments">
        <VCard class="pa-4">
          <VList v-if="claim.attachments.length" density="compact">
            <VListItem v-for="a in claim.attachments" :key="a.id" :subtitle="a.attachment_type.replace(/_/g,' ') + ' · ' + (a.size_formatted ?? '')">
              <template #prepend>
                <VIcon :color="a.mime_type?.includes('pdf') ? 'error' : 'primary'">
                  {{ a.mime_type?.includes('pdf') ? 'tabler-file-type-pdf' : 'tabler-file-type-jpg' }}
                </VIcon>
              </template>
              <template #title>{{ a.original_name }}</template>
              <template #append>
                <VBtn v-if="a.download_url" :href="a.download_url" size="x-small" variant="tonal" color="primary"
                  prepend-icon="tabler-download" target="_blank">{{ t('claims_show.download') }}</VBtn>
              </template>
            </VListItem>
          </VList>
          <p v-else class="text-medium-emphasis text-center pa-4">{{ t('claims_show.attachments_empty') }}</p>
        </VCard>
      </VWindowItem>
    </VWindow>

    <VDialog v-model="approveDialog" max-width="440">
      <VCard :title="t('claims_show.dialog.approve_title')">
        <VCardText>
          <VTextarea v-model="approveForm.notes" :label="t('claims_show.dialog.approve_notes')" variant="outlined" density="compact" rows="3" />
        </VCardText>
        <VCardActions>
          <VSpacer /><VBtn variant="outlined" @click="approveDialog = false">{{ t('claims_show.dialog.cancel') }}</VBtn>
          <VBtn color="success" :loading="approveForm.processing" @click="doApprove">{{ t('claims_show.dialog.approve_btn') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="rejectDialog" max-width="440">
      <VCard :title="t('claims_show.dialog.reject_title')">
        <VCardText>
          <VTextarea v-model="rejectForm.reason" :label="t('claims_show.dialog.reject_reason')" variant="outlined" density="compact" rows="3"
            :error-messages="rejectForm.errors.reason" />
        </VCardText>
        <VCardActions>
          <VSpacer /><VBtn variant="outlined" @click="rejectDialog = false">{{ t('claims_show.dialog.cancel') }}</VBtn>
          <VBtn color="error" :loading="rejectForm.processing" @click="doReject">{{ t('claims_show.dialog.reject_btn') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="payDialog" max-width="440">
      <VCard :title="t('claims_show.dialog.pay_title')">
        <VCardText>
          <p class="mb-3">{{ t('claims_show.dialog.pay_confirm', { amount: fmt(claim.total_amount), reference: claim.reference_number }) }}</p>
          <VTextarea v-model="payForm.notes" :label="t('claims_show.dialog.payment_notes')" variant="outlined" density="compact" rows="2" />
        </VCardText>
        <VCardActions>
          <VSpacer /><VBtn variant="outlined" @click="payDialog = false">{{ t('claims_show.dialog.cancel') }}</VBtn>
          <VBtn color="deep-purple" :loading="payForm.processing" @click="doPay">{{ t('claims_show.dialog.confirm_payment') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="receiptDialog" max-width="480" persistent>
      <VCard>
        <VCardTitle class="d-flex align-center ga-2 pa-4">
          <VIcon color="warning">tabler-receipt-plus</VIcon> {{ t('claims_show.dialog.upload_receipt_title') }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pt-4">
          <VAlert type="info" variant="tonal" density="compact" class="mb-4">
            {{ t('claims_show.dialog.upload_receipt_info') }}
          </VAlert>
          <VSheet border class="pa-6 text-center cursor-pointer" color="surface-variant" @click="receiptFileRef?.click()">
            <VIcon size="36" class="mb-2" :color="receiptFile ? 'success' : 'warning'">
              {{ receiptFile ? 'tabler-file-check' : 'tabler-cloud-upload' }}
            </VIcon>
            <div class="text-body-2">
              <span v-if="receiptFile" class="text-success font-weight-medium">{{ receiptFile.name }}</span>
              <span v-else class="text-medium-emphasis">{{ t('claims_show.dialog.upload_receipt_click') }}</span>
            </div>
            <input ref="receiptFileRef" type="file" accept=".pdf,.jpg,.jpeg,.png" class="d-none" @change="onReceiptFileChange" />
          </VSheet>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-3">
          <VSpacer />
          <VBtn variant="outlined" @click="receiptDialog = false">{{ t('claims_show.dialog.cancel') }}</VBtn>
          <VBtn color="warning" :loading="uploadingReceipt" :disabled="!receiptFile" prepend-icon="tabler-upload" @click="uploadReceipt">{{ t('claims_show.dialog.upload_receipt_btn') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
