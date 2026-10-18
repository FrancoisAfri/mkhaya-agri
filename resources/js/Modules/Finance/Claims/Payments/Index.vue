<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
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

interface ApprovedClaim {
  id: number; uuid: string; reference_number: string; employee: string; title: string
  category: string; is_travel: boolean; total_amount: number; currency: string
  approved_at: string | null; age_days: number; notes: string | null
  receipts_required: boolean; all_receipts_uploaded: boolean
  missing_receipts: number; line_items: LineItem[]
}

interface PaidClaim {
  id: number; uuid: string; reference_number: string; employee: string; title: string
  category: string; total_amount: number; currency: string
  paid_at: string | null; paid_by: string; has_proof: boolean
}

const props = defineProps<{
  approvedClaims: ApprovedClaim[]
  recentlyPaid: PaidClaim[]
  isAdmin: boolean
}>()

const tab = ref<'queue' | 'history'>('queue')

const processDialog = ref(false)
const selectedClaim = ref<ApprovedClaim | null>(null)
const proofFile     = ref<File | null>(null)
const proofFileRef  = ref<HTMLInputElement | null>(null)

const form = useForm({ notes: '', proof_file: null as File | null })

const canProcessSelected = computed(() =>
  !selectedClaim.value?.receipts_required || selectedClaim.value?.all_receipts_uploaded
)

function openProcess(claim: ApprovedClaim) {
  selectedClaim.value = claim
  proofFile.value     = null
  form.reset()
  processDialog.value = true
}

function onFileChange(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0] ?? null
  proofFile.value = file
  form.proof_file = file
}

function submitPayment() {
  if (!selectedClaim.value || !canProcessSelected.value) return
  const fd = new FormData()
  fd.append('notes', form.notes ?? '')
  if (proofFile.value) fd.append('proof_file', proofFile.value)
  router.post(route('claims.payments.process', { claim: selectedClaim.value.uuid }), fd as any, {
    forceFormData: true,
    onSuccess: () => { processDialog.value = false; form.reset(); proofFile.value = null },
  })
}

const fmt = (n: number, cur = 'ZAR') => cur + ' ' + n.toLocaleString('en-ZA', { minimumFractionDigits: 2 })
const mimeIcon  = (mime: string | null) => mime?.includes('pdf') ? 'tabler-file-type-pdf' : 'tabler-file-type-jpg'
const mimeColor = (mime: string | null) => mime?.includes('pdf') ? 'error' : 'primary'
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-5 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('claims_payments.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('claims_payments.description') }}</p>
      </div>
    </div>

    <VRow dense class="mb-4">
      <VCol cols="6" sm="3">
        <VCard variant="tonal" color="warning" class="pa-3 text-center" elevation="0">
          <div class="text-h5 font-weight-bold">{{ approvedClaims.length }}</div>
          <div class="text-caption">{{ t('claims_payments.stats.awaiting_payment') }}</div>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard variant="tonal" color="success" class="pa-3 text-center" elevation="0">
          <div class="text-h5 font-weight-bold">{{ recentlyPaid.length }}</div>
          <div class="text-caption">{{ t('claims_payments.stats.recently_paid') }}</div>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard variant="tonal" color="primary" class="pa-3 text-center" elevation="0">
          <div class="text-h5 font-weight-bold">
            {{ fmt(approvedClaims.reduce((s, claim) => s + claim.total_amount, 0)) }}
          </div>
          <div class="text-caption">{{ t('claims_payments.stats.pending_amount') }}</div>
        </VCard>
      </VCol>
      <VCol cols="6" sm="3">
        <VCard variant="tonal" color="deep-purple" class="pa-3 text-center" elevation="0">
          <div class="text-h5 font-weight-bold">
            {{ fmt(recentlyPaid.reduce((s, claim) => s + claim.total_amount, 0)) }}
          </div>
          <div class="text-caption">{{ t('claims_payments.stats.recently_paid_amount') }}</div>
        </VCard>
      </VCol>
    </VRow>

    <VTabs v-model="tab" class="mb-4">
      <VTab value="queue">
        <VBadge :content="approvedClaims.length" color="warning" inline>
          {{ t('claims_payments.tabs.queue') }}
        </VBadge>
      </VTab>
      <VTab value="history">{{ t('claims_payments.tabs.history') }}</VTab>
    </VTabs>

    <VWindow v-model="tab">
      <VWindowItem value="queue">
        <VCard v-if="!approvedClaims.length" variant="outlined" class="text-center pa-12">
          <VIcon size="56" class="mb-3 opacity-30">tabler-checks</VIcon>
          <div class="text-h6 mb-2">{{ t('claims_payments.queue.empty_title') }}</div>
          <p class="text-body-2 text-medium-emphasis">{{ t('claims_payments.queue.empty_description') }}</p>
        </VCard>

        <VRow v-else dense>
          <VCol v-for="claim in approvedClaims" :key="claim.id" cols="12" md="6" lg="4">
            <VCard height="100%" border>
              <VCardText class="pa-4">
                <div class="d-flex align-start justify-space-between mb-2">
                  <div>
                    <div class="text-body-1 font-weight-bold">{{ claim.reference_number }}</div>
                    <div class="text-caption text-medium-emphasis">{{ claim.employee }}</div>
                  </div>
                  <VChip size="x-small" color="warning" variant="tonal">{{ t('claims_payments.queue.approved') }}</VChip>
                </div>

                <div class="text-body-2 mb-3 text-truncate" :title="claim.title">{{ claim.title }}</div>

                <div class="d-flex flex-wrap ga-1 mb-3">
                  <VChip size="x-small" variant="outlined">{{ claim.category }}</VChip>
                  <VChip
                    size="x-small"
                    :color="claim.age_days > 14 ? 'error' : claim.age_days > 7 ? 'warning' : 'success'"
                    variant="tonal"
                  >
                    {{ t('claims_payments.queue.days_old', { days: claim.age_days }) }}
                  </VChip>
                </div>

                <div class="text-h6 font-weight-bold text-success mb-1">
                  {{ fmt(claim.total_amount, claim.currency) }}
                </div>
                <div class="text-caption text-medium-emphasis">
                  <VIcon size="12" class="mr-1">tabler-calendar-check</VIcon>
                  {{ t('claims_payments.queue.approved_label', { date: claim.approved_at ?? '—' }) }}
                </div>
                <div v-if="claim.notes" class="text-caption text-medium-emphasis mt-1">
                  <VIcon size="12" class="mr-1">tabler-note</VIcon>
                  {{ claim.notes }}
                </div>

                <VDivider class="my-2" />
                <div class="text-caption font-weight-medium mb-1 d-flex align-center ga-1">
                  <VIcon size="14">tabler-receipt</VIcon> {{ t('claims_payments.queue.receipts') }}
                  <VChip
                    v-if="claim.receipts_required && claim.missing_receipts > 0"
                    size="x-small" color="error" variant="tonal" class="ml-1"
                  >{{ t('claims_payments.queue.missing_receipts', { count: claim.missing_receipts }) }}</VChip>
                  <VChip
                    v-else-if="claim.receipts_required"
                    size="x-small" color="success" variant="tonal" class="ml-1"
                  >{{ t('claims_payments.queue.all_uploaded') }}</VChip>
                  <span v-else class="text-medium-emphasis ml-1">{{ t('claims_payments.queue.not_required') }}</span>
                </div>
                <div v-if="!claim.is_travel && claim.line_items.length" class="d-flex flex-column ga-1">
                  <div v-for="li in claim.line_items" :key="li.id" class="d-flex align-center ga-1 flex-wrap">
                    <span class="text-caption text-medium-emphasis" style="min-width:120px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" :title="li.description">
                      {{ li.description }}
                    </span>
                    <template v-if="li.receipts.length">
                      <VBtn
                        v-for="receipt in li.receipts" :key="receipt.id"
                        :href="receipt.download_url" target="_blank"
                        size="x-small" variant="tonal"
                        :color="mimeColor(receipt.mime)" :prepend-icon="mimeIcon(receipt.mime)"
                        :title="receipt.name"
                      >{{ t('claims_payments.queue.view') }}</VBtn>
                    </template>
                    <VChip v-else-if="li.receipt_required" size="x-small" color="error" variant="tonal" prepend-icon="tabler-alert-circle">{{ t('claims_payments.queue.missing') }}</VChip>
                    <span v-else class="text-caption text-medium-emphasis">—</span>
                  </div>
                </div>
              </VCardText>

              <VDivider />
              <VCardActions class="pa-2">
                <VBtn size="small" variant="text" prepend-icon="tabler-eye" :href="`/claims/my/${claim.uuid}`">{{ t('claims_payments.queue.view_claim') }}</VBtn>
                <VSpacer />
                <VTooltip v-if="claim.receipts_required && !claim.all_receipts_uploaded" location="top">
                  <template #activator="{ props: tp }">
                    <span v-bind="tp">
                      <VBtn size="small" color="success" variant="tonal" prepend-icon="tabler-cash-banknote" disabled>
                        {{ t('claims_payments.queue.process_payment') }}
                      </VBtn>
                    </span>
                  </template>
                  {{ t('claims_payments.queue.cannot_process_tooltip', { count: claim.missing_receipts }) }}
                </VTooltip>
                <VBtn
                  v-else
                  size="small"
                  color="success"
                  variant="tonal"
                  prepend-icon="tabler-cash-banknote"
                  @click="openProcess(claim)"
                >{{ t('claims_payments.queue.process_payment') }}</VBtn>
              </VCardActions>
            </VCard>
          </VCol>
        </VRow>
      </VWindowItem>

      <VWindowItem value="history">
        <VCard>
          <VDataTable
            :items="recentlyPaid"
            :headers="[
              { title: t('claims_payments.history.table.reference'),  key: 'reference_number' },
              { title: t('claims_payments.history.table.employee'),   key: 'employee' },
              { title: t('claims_payments.history.table.title'),      key: 'title' },
              { title: t('claims_payments.history.table.amount'),     key: 'total_amount' },
              { title: t('claims_payments.history.table.paid_by'),    key: 'paid_by' },
              { title: t('claims_payments.history.table.paid_at'),    key: 'paid_at' },
              { title: t('claims_payments.history.table.proof'),      key: 'has_proof', sortable: false },
            ]"
            density="compact"
            :items-per-page="15"
          >
            <template #item.total_amount="{ item }">
              <span class="font-weight-medium text-success">{{ fmt(item.total_amount, item.currency) }}</span>
            </template>
            <template #item.reference_number="{ item }">
              <a :href="`/claims/my/${item.uuid}`" class="text-primary text-decoration-none font-weight-medium">
                {{ item.reference_number }}
              </a>
            </template>
            <template #item.has_proof="{ item }">
              <VChip v-if="item.has_proof" size="x-small" color="success" variant="tonal" prepend-icon="tabler-file-check">
                {{ t('claims_payments.history.uploaded') }}
              </VChip>
              <span v-else class="text-caption text-medium-emphasis">—</span>
            </template>
          </VDataTable>
        </VCard>
      </VWindowItem>
    </VWindow>

    <VDialog v-model="processDialog" max-width="520" persistent>
      <VCard v-if="selectedClaim">
        <VCardTitle class="d-flex align-center ga-2 pa-4">
          <VIcon color="success">tabler-cash-banknote</VIcon>
          {{ t('claims_payments.dialog.title') }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pt-4">
          <VAlert variant="tonal" color="primary" class="mb-4" density="compact">
            <div class="d-flex justify-space-between align-center">
              <div>
                <div class="font-weight-bold">{{ selectedClaim.reference_number }}</div>
                <div class="text-caption">{{ selectedClaim.employee }} — {{ selectedClaim.title }}</div>
              </div>
              <div class="text-h6 font-weight-bold">{{ fmt(selectedClaim.total_amount, selectedClaim.currency) }}</div>
            </div>
          </VAlert>

          <VAlert
            v-if="selectedClaim.receipts_required && !selectedClaim.all_receipts_uploaded"
            type="error" variant="tonal" density="compact" class="mb-4"
            prepend-icon="tabler-receipt-off"
          >
            {{ t('claims_payments.dialog.cannot_process_alert', { count: selectedClaim.missing_receipts }) }}
          </VAlert>

          <div v-if="!selectedClaim.is_travel && selectedClaim.line_items.length" class="mb-4">
            <div class="text-body-2 font-weight-medium mb-2">
              <VIcon size="16" class="mr-1">tabler-receipt</VIcon>{{ t('claims_payments.dialog.line_item_receipts') }}
            </div>
            <VTable density="compact">
              <thead>
                <tr class="text-caption">
                  <th>{{ t('claims_payments.dialog.item') }}</th>
                  <th class="text-right">{{ t('claims_payments.dialog.amount') }}</th>
                  <th>{{ t('claims_payments.dialog.receipt') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="li in selectedClaim.line_items" :key="li.id">
                  <td class="text-body-2">{{ li.description }}</td>
                  <td class="text-right text-no-wrap">{{ fmt(li.amount) }}</td>
                  <td>
                    <div v-if="li.receipts.length" class="d-flex ga-1 flex-wrap">
                      <VBtn
                        v-for="receipt in li.receipts" :key="receipt.id"
                        :href="receipt.download_url" target="_blank"
                        size="x-small" variant="tonal"
                        :color="mimeColor(receipt.mime)" :prepend-icon="mimeIcon(receipt.mime)"
                      >{{ receipt.name }}</VBtn>
                    </div>
                    <VChip v-else-if="li.receipt_required" color="error" size="x-small" variant="tonal" prepend-icon="tabler-alert-circle">{{ t('claims_payments.dialog.missing') }}</VChip>
                    <span v-else class="text-caption text-medium-emphasis">—</span>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </div>

          <div class="mb-4">
            <div class="text-body-2 font-weight-medium mb-2">
              <VIcon size="16" class="mr-1">tabler-file-upload</VIcon>
              {{ t('claims_payments.dialog.proof_of_payment') }}
            </div>
            <VSheet
              border
              class="pa-4 text-center cursor-pointer"
              color="surface-variant"
              @click="proofFileRef?.click()"
            >
              <VIcon size="32" class="mb-2" :color="proofFile ? 'success' : 'medium-emphasis'">
                {{ proofFile ? 'tabler-file-check' : 'tabler-cloud-upload' }}
              </VIcon>
              <div class="text-body-2">
                <span v-if="proofFile" class="text-success font-weight-medium">{{ proofFile.name }}</span>
                <span v-else class="text-medium-emphasis">{{ t('claims_payments.dialog.click_to_upload') }}</span>
              </div>
              <input
                ref="proofFileRef"
                type="file"
                accept=".pdf,.jpg,.jpeg,.png"
                class="d-none"
                @change="onFileChange"
              />
            </VSheet>
          </div>

          <VTextarea
            v-model="form.notes"
            :label="t('claims_payments.dialog.payment_notes')"
            variant="outlined"
            density="compact"
            rows="3"
            :placeholder="t('claims_payments.dialog.notes_placeholder')"
            :error-messages="form.errors.notes"
          />
        </VCardText>
        <VDivider />
        <VCardActions class="pa-3">
          <VSpacer />
          <VBtn variant="outlined" @click="processDialog = false">{{ t('claims_payments.dialog.cancel') }}</VBtn>
          <VBtn
            color="success"
            :loading="form.processing"
            :disabled="!canProcessSelected"
            prepend-icon="tabler-cash-banknote"
            @click="submitPayment"
          >
            {{ t('claims_payments.dialog.mark_as_paid') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
