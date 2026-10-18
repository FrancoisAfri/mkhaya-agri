<script setup lang="ts">
import { ref, computed } from 'vue'
import { route } from 'ziggy-js'
import { router } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import axios from 'axios'

const { t } = useI18n()

interface ClaimRow {
  id: number; uuid: string; reference_number: string; employee: string; title: string
  category: string; requires_receipt: boolean; total_amount: number; currency: string
  status: string; status_color: string; risk_level: string | null
  risk_score: number | null; violations: number
  claim_date: string; submitted_at: string | null; age_days: number
}

const props = defineProps<{
  claims: { data: ClaimRow[]; current_page: number; last_page: number }
  categories: { id: number; name: string }[]
  canCreate: boolean
  isAdmin: boolean
}>()

const search      = ref('')
const filterStatus = ref<string | null>(null)
const filterCat   = ref<number | null>(null)
const expanded    = ref<string[]>([])

const detailCache = ref<Record<string, any>>({})
const loadingUuid = ref<string | null>(null)

async function onExpand(uuid: string) {
  if (detailCache.value[uuid]) return
  loadingUuid.value = uuid
  try {
    const res = await axios.get(route('claims.my.detail', { claim: uuid }))
    detailCache.value[uuid] = res.data
  } finally {
    loadingUuid.value = null
  }
}

function toggleExpand(uuid: string) {
  const idx = expanded.value.indexOf(uuid)
  if (idx === -1) {
    expanded.value.push(uuid)
    onExpand(uuid)
  } else {
    expanded.value.splice(idx, 1)
  }
}

const statusColor = (s: string) => ({
  draft: 'grey', submitted: 'blue', under_review: 'warning',
  approved: 'success', rejected: 'error', paid: 'deep-purple', cancelled: 'grey',
})[s] ?? 'grey'

const riskColor = (r: string | null) => ({
  critical: 'error', high: 'deep-orange', medium: 'warning', low: 'success',
})[r ?? ''] ?? 'grey'

const fmt = (n: number) => 'R ' + n.toLocaleString('en-ZA', { minimumFractionDigits: 2 })
const mimeIcon  = (m: string | null) => m?.includes('pdf') ? 'tabler-file-type-pdf' : 'tabler-file-type-jpg'
const mimeColor = (m: string | null) => m?.includes('pdf') ? 'error' : 'primary'

function submitClaim(uuid: string) {
  router.post(route('claims.my.submit', { claim: uuid }), {}, {
    onSuccess: () => { delete detailCache.value[uuid] }
  })
}

function doApprove(uuid: string, notes: string) {
  router.post(route('claims.approvals.approve', { claim: uuid }), { notes }, {
    onSuccess: () => { delete detailCache.value[uuid] }
  })
}

function doReject(uuid: string, reason: string) {
  router.post(route('claims.approvals.reject', { claim: uuid }), { reason }, {
    onSuccess: () => { delete detailCache.value[uuid] }
  })
}

const approveNotes  = ref<Record<string, string>>({})
const rejectReason  = ref<Record<string, string>>({})
const approveOpen   = ref<Record<string, boolean>>({})
const rejectOpen    = ref<Record<string, boolean>>({})

const receiptFile    = ref<File | null>(null)
const receiptItemId  = ref<number | null>(null)
const receiptClaimUuid = ref<string | null>(null)
const receiptDialog  = ref(false)
const uploadingReceipt = ref(false)
const receiptFileRef = ref<HTMLInputElement | null>(null)

function openReceiptUpload(claimUuid: string, itemId: number) {
  receiptClaimUuid.value = claimUuid
  receiptItemId.value    = itemId
  receiptFile.value      = null
  receiptDialog.value    = true
}

function onReceiptFileChange(e: Event) {
  receiptFile.value = (e.target as HTMLInputElement).files?.[0] ?? null
}

function uploadReceipt() {
  if (!receiptFile.value || !receiptClaimUuid.value) return
  uploadingReceipt.value = true
  const fd = new FormData()
  fd.append('file', receiptFile.value)
  fd.append('attachment_type', 'receipt')
  if (receiptItemId.value) fd.append('item_id', String(receiptItemId.value))

  router.post(
    route('claims.my.receipts.upload', { claim: receiptClaimUuid.value }),
    fd as any,
    {
      forceFormData: true,
      onSuccess: () => {
        receiptDialog.value = false
        receiptFile.value   = null
        if (receiptClaimUuid.value) delete detailCache.value[receiptClaimUuid.value]
      },
      onFinish: () => { uploadingReceipt.value = false },
    }
  )
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center justify-space-between mb-5 flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ isAdmin ? t('claims_index.page_title') : t('claims_index.my_claims') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('claims_index.records_shown', { count: claims.data.length }) }}</p>
      </div>
      <VBtn color="primary" prepend-icon="tabler-plus" :href="route('claims.my.create')">{{ t('claims_index.new_claim') }}</VBtn>
    </div>

    <VCard class="mb-4 pa-3" variant="outlined">
      <VRow dense align="center">
        <VCol cols="12" sm="4" md="3">
          <VTextField v-model="search" :label="t('claims_index.filters.search')" prepend-inner-icon="tabler-search" density="compact" variant="outlined" clearable hide-details />
        </VCol>
        <VCol cols="12" sm="3" md="2">
          <VSelect v-model="filterStatus" :label="t('claims_index.filters.status')"
            :items="['draft','submitted','under_review','approved','rejected','paid','cancelled']"
            density="compact" variant="outlined" clearable hide-details />
        </VCol>
        <VCol cols="12" sm="3" md="2">
          <VSelect v-model="filterCat" :label="t('claims_index.filters.category')"
            :items="categories.map(cat => ({ value: cat.id, title: cat.name }))"
            density="compact" variant="outlined" clearable hide-details />
        </VCol>
      </VRow>
    </VCard>

    <VCard>
      <VDataTable
        :items="claims.data"
        :search="search"
        item-value="uuid"
        v-model:expanded="expanded"
        :headers="[
          { title: '',           key: 'data-table-expand', sortable: false, width: '40px' },
          { title: t('claims_index.table.reference'),  key: 'reference_number' },
          ...(isAdmin ? [{ title: t('claims_index.table.employee'), key: 'employee' }] : []),
          { title: t('claims_index.table.title'),      key: 'title' },
          { title: t('claims_index.table.category'),   key: 'category' },
          { title: t('claims_index.table.amount'),     key: 'total_amount' },
          { title: t('claims_index.table.status'),     key: 'status' },
          { title: t('claims_index.table.risk'),       key: 'risk_level' },
          { title: t('claims_index.table.violations'), key: 'violations' },
          { title: t('claims_index.table.claim_date'), key: 'claim_date' },
        ]"
        density="comfortable"
        :items-per-page="20"
        hover
      >
        <template #item.data-table-expand="{ item }">
          <VBtn
            :icon="expanded.includes(item.uuid) ? 'tabler-chevron-up' : 'tabler-chevron-down'"
            size="x-small" variant="text"
            :loading="loadingUuid === item.uuid"
            @click="toggleExpand(item.uuid)"
          />
        </template>

        <template #item.reference_number="{ item }">
          <span class="text-primary font-weight-medium cursor-pointer" @click="toggleExpand(item.uuid)">{{ item.reference_number }}</span>
        </template>
        <template #item.total_amount="{ item }">
          <span class="font-weight-medium">{{ fmt(item.total_amount) }}</span>
        </template>
        <template #item.status="{ item }">
          <VChip :color="statusColor(item.status)" size="x-small" variant="tonal">{{ item.status.replace('_', ' ') }}</VChip>
        </template>
        <template #item.risk_level="{ item }">
          <VChip v-if="item.risk_level" :color="riskColor(item.risk_level)" size="x-small" variant="tonal">{{ item.risk_level }}</VChip>
          <span v-else class="text-medium-emphasis text-caption">—</span>
        </template>
        <template #item.violations="{ item }">
          <VChip v-if="item.violations > 0" color="warning" size="x-small" variant="tonal">{{ item.violations }}</VChip>
          <span v-else class="text-medium-emphasis text-caption">0</span>
        </template>

        <template #expanded-row="{ columns, item }">
          <tr>
            <td :colspan="columns.length" class="pa-0">
              <div v-if="loadingUuid === item.uuid || !detailCache[item.uuid]" class="pa-6 text-center">
                <VProgressCircular indeterminate size="28" />
              </div>

              <template v-else>
                <div class="pa-4">
                  <VRow dense>
                    <VCol cols="12" lg="8">
                      <p v-if="detailCache[item.uuid].claim.description" class="text-body-2 mb-3">
                        {{ detailCache[item.uuid].claim.description }}
                      </p>

                      <VAlert v-if="detailCache[item.uuid].claim.rejection_reason" type="error" density="compact" variant="tonal" class="mb-3">
                        Rejected: {{ detailCache[item.uuid].claim.rejection_reason }}
                      </VAlert>

                      <template v-if="detailCache[item.uuid].claim.is_travel || detailCache[item.uuid].claim.travel_trips?.length">
                        <div class="d-flex align-center ga-2 mb-2">
                          <VIcon color="primary" size="16">tabler-car</VIcon>
                          <span class="text-body-2 font-weight-bold">{{ t('claims_index.line_items') }}</span>
                          <VChip size="x-small" color="primary" variant="tonal">{{ detailCache[item.uuid].claim.travel_trips?.length }} trip(s)</VChip>
                        </div>
                        <VTable density="compact" class="mb-3 rounded">
                          <thead>
                            <tr class="text-caption">
                              <th>Date</th><th>Route</th><th class="text-right">Distance</th>
                              <th class="text-right">Rate/km</th><th class="text-right">Base</th>
                              <th class="text-right">Peak</th><th class="text-right">Total</th>
                            </tr>
                          </thead>
                          <tbody>
                            <tr v-for="trip in detailCache[item.uuid].claim.travel_trips" :key="trip.id">
                              <td class="text-caption text-no-wrap">{{ trip.trip_date }}<br><span class="text-medium-emphasis">{{ trip.departure_time }}</span></td>
                              <td>
                                <span class="text-body-2">{{ trip.origin }}</span>
                                <VIcon size="12" class="mx-1">tabler-arrow-right</VIcon>
                                <span class="text-body-2">{{ trip.destination }}</span>
                                <div class="d-flex ga-1 mt-1">
                                  <VChip v-if="trip.is_peak_hour" size="x-small" color="warning" variant="tonal">Peak</VChip>
                                  <VChip v-if="trip.traffic_condition" size="x-small" :color="trip.traffic_condition==='heavy'?'error':trip.traffic_condition==='moderate'?'warning':'success'" variant="tonal">{{ trip.traffic_condition }}</VChip>
                                </div>
                              </td>
                              <td class="text-right text-no-wrap">{{ trip.distance_km }} km</td>
                              <td class="text-right text-no-wrap text-medium-emphasis">R {{ Number(trip.km_rate).toFixed(2) }}</td>
                              <td class="text-right text-no-wrap">{{ fmt(trip.base_amount) }}</td>
                              <td class="text-right text-no-wrap">
                                <span v-if="trip.peak_surcharge > 0" class="text-warning">+{{ fmt(trip.peak_surcharge) }}</span>
                                <span v-else class="text-medium-emphasis">—</span>
                              </td>
                              <td class="text-right font-weight-bold text-no-wrap">{{ fmt(trip.total_amount) }}</td>
                            </tr>
                          </tbody>
                          <tfoot>
                            <tr>
                              <td colspan="6" class="text-right font-weight-bold text-caption">{{ t('claims_index.total') }}</td>
                              <td class="text-right font-weight-bold text-primary">{{ fmt(detailCache[item.uuid].claim.total_amount) }}</td>
                            </tr>
                          </tfoot>
                        </VTable>
                      </template>

                      <template v-else>
                        <VAlert
                          v-if="detailCache[item.uuid].claim.status === 'draft' && (
                            detailCache[item.uuid].claim.requires_receipt
                              ? detailCache[item.uuid].claim.items.some((i: any) => !i.receipt_uploaded)
                              : detailCache[item.uuid].claim.items.some((i: any) => i.receipt_required && !i.receipt_uploaded)
                          )"
                          type="warning" variant="tonal" density="compact" class="mb-2"
                        >
                          {{ t('claims_index.missing_receipts_warning') }}
                        </VAlert>
                        <div class="d-flex align-center ga-2 mb-2">
                          <VIcon color="primary" size="16">tabler-receipt</VIcon>
                          <span class="text-body-2 font-weight-bold">{{ t('claims_index.line_items') }}</span>
                          <VChip size="x-small" color="primary" variant="tonal">{{ t('claims_index.item_count', { count: detailCache[item.uuid].claim.items.length }) }}</VChip>
                        </div>
                        <VTable v-if="detailCache[item.uuid].claim.items.length" density="compact" class="mb-3 rounded">
                          <thead>
                            <tr class="text-caption">
                              <th>{{ t('claims_index.table_items.date') }}</th>
                              <th>{{ t('claims_index.table_items.description') }}</th>
                              <th class="text-right">{{ t('claims_index.table_items.unit_price') }}</th>
                              <th class="text-right">{{ t('claims_index.table_items.qty') }}</th>
                              <th class="text-right">{{ t('claims_index.table_items.amount') }}</th>
                              <th>{{ t('claims_index.table_items.receipt') }}</th>
                            </tr>
                          </thead>
                          <tbody>
                            <tr v-for="li in detailCache[item.uuid].claim.items" :key="li.id">
                              <td class="text-caption text-medium-emphasis text-no-wrap">{{ li.item_date || '—' }}</td>
                              <td class="text-body-2">{{ li.description }}</td>
                              <td class="text-right text-no-wrap">{{ fmt(li.unit_price) }}</td>
                              <td class="text-right">{{ li.quantity }}</td>
                              <td class="text-right font-weight-bold text-no-wrap">{{ fmt(li.amount) }}</td>
                              <td>
                                <div v-if="li.receipt_uploaded && li.attachments?.length" class="d-flex ga-1 flex-wrap">
                                  <VBtn v-for="a in li.attachments.filter((att: any) => att.type === 'receipt')" :key="a.id"
                                    :href="a.download_url" target="_blank" size="x-small" variant="tonal"
                                    :color="mimeColor(a.mime ?? null)" :prepend-icon="mimeIcon(a.mime ?? null)"
                                  >{{ t('claims_index.view') }}</VBtn>
                                </div>
                                <VBtn
                                  v-else-if="(li.receipt_required || detailCache[item.uuid].claim.requires_receipt) && detailCache[item.uuid].claim.status === 'draft'"
                                  size="x-small" color="warning" variant="tonal" prepend-icon="tabler-upload"
                                  @click="openReceiptUpload(item.uuid, li.id)"
                                >{{ t('claims_index.upload') }}</VBtn>
                                <VIcon v-else-if="li.receipt_required || detailCache[item.uuid].claim.requires_receipt" color="warning" size="16">tabler-alert-circle</VIcon>
                                <VIcon v-else color="grey" size="16">tabler-circle-minus</VIcon>
                              </td>
                            </tr>
                          </tbody>
                          <tfoot>
                            <tr>
                              <td colspan="4" class="text-right font-weight-bold text-caption">{{ t('claims_index.total') }}</td>
                              <td class="text-right font-weight-bold text-primary">{{ fmt(detailCache[item.uuid].claim.total_amount) }}</td>
                              <td></td>
                            </tr>
                          </tfoot>
                        </VTable>
                      </template>

                      <div v-if="detailCache[item.uuid].claim.approvals?.length" class="mb-2">
                        <div class="text-body-2 font-weight-bold mb-2 d-flex align-center ga-1">
                          <VIcon size="16">tabler-rosette-discount-check</VIcon> {{ t('claims_index.approval_history') }}
                        </div>
                        <VTimeline density="compact" side="end" class="ml-2">
                          <VTimelineItem v-for="a in detailCache[item.uuid].claim.approvals" :key="a.id" :dot-color="a.color" size="x-small">
                            <div class="text-body-2">Level {{ a.level }}: <strong>{{ a.approver }}</strong></div>
                            <VChip :color="a.color" size="x-small" variant="tonal">{{ a.action }}</VChip>
                            <span v-if="a.actioned_at" class="text-caption text-medium-emphasis ml-1">{{ a.actioned_at }}</span>
                            <p v-if="a.notes" class="text-caption mt-1">{{ a.notes }}</p>
                            <p v-if="a.reason" class="text-caption text-error mt-1">Reason: {{ a.reason }}</p>
                          </VTimelineItem>
                        </VTimeline>
                      </div>
                    </VCol>

                    <VCol cols="12" lg="4">
                      <VCard variant="outlined" class="mb-3 pa-3">
                        <div class="text-caption text-medium-emphasis mb-1">{{ t('claims_index.claim_details') }}</div>
                        <div class="d-flex justify-space-between text-body-2 mb-1">
                          <span class="text-medium-emphasis">{{ t('claims_index.date') }}</span>
                          <strong>{{ detailCache[item.uuid].claim.claim_date }}</strong>
                        </div>
                        <div v-if="detailCache[item.uuid].claim.submitted_at" class="d-flex justify-space-between text-body-2 mb-1">
                          <span class="text-medium-emphasis">{{ t('claims_index.submitted') }}</span>
                          <strong>{{ detailCache[item.uuid].claim.submitted_at }}</strong>
                        </div>
                        <div v-if="detailCache[item.uuid].claim.approved_at" class="d-flex justify-space-between text-body-2 mb-1">
                          <span class="text-medium-emphasis">{{ t('claims_index.approved') }}</span>
                          <strong>{{ detailCache[item.uuid].claim.approved_at }}</strong>
                        </div>
                        <div v-if="detailCache[item.uuid].claim.paid_at" class="d-flex justify-space-between text-body-2 mb-1">
                          <span class="text-medium-emphasis">{{ t('claims_index.paid') }}</span>
                          <strong>{{ detailCache[item.uuid].claim.paid_at }}</strong>
                        </div>
                        <div class="d-flex justify-space-between text-body-2">
                          <span class="text-medium-emphasis">Total</span>
                          <strong class="text-primary">{{ fmt(detailCache[item.uuid].claim.total_amount) }}</strong>
                        </div>
                      </VCard>

                      <VCard variant="outlined" class="mb-3 pa-3">
                        <div class="text-caption text-medium-emphasis mb-2 d-flex align-center ga-1">
                          <VIcon size="14">tabler-paperclip</VIcon> {{ t('claims_index.documents') }}
                          <VChip size="x-small" class="ml-1">{{ detailCache[item.uuid].claim.attachments?.length ?? 0 }}</VChip>
                        </div>
                        <div v-if="!detailCache[item.uuid].claim.attachments?.length" class="text-caption text-medium-emphasis">{{ t('claims_index.no_documents') }}</div>
                        <div v-for="a in detailCache[item.uuid].claim.attachments" :key="a.id" class="d-flex align-center ga-2 mb-1">
                          <VIcon size="16" :color="mimeColor(a.mime_type)">{{ mimeIcon(a.mime_type) }}</VIcon>
                          <span class="text-caption flex-grow-1 text-truncate" :title="a.original_name">{{ a.original_name }}</span>
                          <VBtn v-if="a.download_url" :href="a.download_url" target="_blank"
                            size="x-small" variant="text" icon="tabler-download" color="primary"
                          />
                        </div>
                      </VCard>

                      <div class="d-flex flex-column ga-2">
                        <template v-if="detailCache[item.uuid].claim.status === 'draft'">
                          <VBtn block variant="outlined" color="primary" prepend-icon="tabler-pencil" size="small"
                            :href="route('claims.my.edit', { claim: item.uuid })">
                            {{ t('claims_index.edit_claim') }}
                          </VBtn>
                          <VTooltip v-if="
                            detailCache[item.uuid].claim.requires_receipt
                              ? detailCache[item.uuid].claim.items?.some((i: any) => !i.receipt_uploaded)
                              : detailCache[item.uuid].claim.items?.some((i: any) => i.receipt_required && !i.receipt_uploaded)
                          " location="left">
                            <template #activator="{ props: tp }">
                              <span v-bind="tp"><VBtn block disabled prepend-icon="tabler-send" size="small">{{ t('claims_index.submit_claim') }}</VBtn></span>
                            </template>{{ t('claims_index.upload_all_docs_first') }}
                          </VTooltip>
                          <VBtn v-else block color="primary" prepend-icon="tabler-send" size="small" @click="submitClaim(item.uuid)">
                            {{ t('claims_index.submit_claim') }}
                          </VBtn>
                        </template>

                        <template v-if="detailCache[item.uuid].canApprove && ['submitted','under_review'].includes(detailCache[item.uuid].claim.status)">
                          <VBtn block color="success" prepend-icon="tabler-check" size="small" @click="approveOpen[item.uuid] = true">{{ t('claims_index.approve') }}</VBtn>
                          <VBtn block color="error" variant="outlined" prepend-icon="tabler-x" size="small" @click="rejectOpen[item.uuid] = true">{{ t('claims_index.reject') }}</VBtn>
                        </template>
                      </div>
                    </VCol>
                  </VRow>
                </div>

                <VDialog v-model="approveOpen[item.uuid]" max-width="420" persistent>
                  <VCard :title="t('claims_index.approve_title')">
                    <VCardText>
                      <VTextarea v-model="approveNotes[item.uuid]" :label="t('claims_index.approve_notes')" variant="outlined" density="compact" rows="2" />
                    </VCardText>
                    <VCardActions>
                      <VSpacer />
                      <VBtn variant="outlined" @click="approveOpen[item.uuid] = false">{{ t('claims_index.cancel') }}</VBtn>
                      <VBtn color="success" @click="doApprove(item.uuid, approveNotes[item.uuid] ?? '')">{{ t('claims_index.approve_btn') }}</VBtn>
                    </VCardActions>
                  </VCard>
                </VDialog>

                <VDialog v-model="rejectOpen[item.uuid]" max-width="420" persistent>
                  <VCard :title="t('claims_index.reject_title')">
                    <VCardText>
                      <VTextarea v-model="rejectReason[item.uuid]" :label="t('claims_index.reject_reason')" variant="outlined" density="compact" rows="3" />
                    </VCardText>
                    <VCardActions>
                      <VSpacer />
                      <VBtn variant="outlined" @click="rejectOpen[item.uuid] = false">{{ t('claims_index.cancel') }}</VBtn>
                      <VBtn color="error" @click="doReject(item.uuid, rejectReason[item.uuid] ?? '')">{{ t('claims_index.reject_btn') }}</VBtn>
                    </VCardActions>
                  </VCard>
                </VDialog>
              </template>
            </td>
          </tr>
        </template>

        <template #no-data>
          <div class="text-center py-10 text-medium-emphasis">
            <VIcon size="48" class="mb-2 opacity-40">tabler-receipt</VIcon>
            <div>{{ t('claims_index.no_claims_found') }}</div>
          </div>
        </template>
      </VDataTable>
    </VCard>

    <VDialog v-model="receiptDialog" max-width="460" persistent>
      <VCard>
        <VCardTitle class="d-flex align-center ga-2 pa-4">
          <VIcon color="warning">tabler-receipt-plus</VIcon> {{ t('claims_index.upload_receipt_title') }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pt-4">
          <VSheet border class="pa-6 text-center cursor-pointer" color="surface-variant" @click="receiptFileRef?.click()">
            <VIcon size="36" class="mb-2" :color="receiptFile ? 'success' : 'warning'">
              {{ receiptFile ? 'tabler-file-check' : 'tabler-cloud-upload' }}
            </VIcon>
            <div class="text-body-2">
              <span v-if="receiptFile" class="text-success font-weight-medium">{{ receiptFile.name }}</span>
              <span v-else class="text-medium-emphasis">{{ t('claims_index.upload_receipt_click') }}</span>
            </div>
            <input ref="receiptFileRef" type="file" accept=".pdf,.jpg,.jpeg,.png" class="d-none" @change="onReceiptFileChange" />
          </VSheet>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-3">
          <VSpacer />
          <VBtn variant="outlined" @click="receiptDialog = false">{{ t('claims_index.cancel') }}</VBtn>
          <VBtn color="warning" :loading="uploadingReceipt" :disabled="!receiptFile" prepend-icon="tabler-upload" @click="uploadReceipt">{{ t('claims_index.upload_receipt_btn') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
