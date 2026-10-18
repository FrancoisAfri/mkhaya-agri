<script setup lang="ts">
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Category {
  id: number; name: string; code: string
  max_amount: number | null; requires_receipt: boolean
  max_age_days: number; is_travel: boolean; km_rate: number | null
}

interface ClaimItem {
  id: number; item_date: string | null; description: string
  unit_price: number; quantity: number; amount: number
  item_type: string | null; receipt_required: boolean; receipt_uploaded: boolean
  attachments: { id: number; name: string; type: string; download_url: string | null; mime?: string }[]
}

interface TravelTrip {
  id: number; trip_date: string; departure_time: string
  origin: string; destination: string; distance_km: number
  km_rate: number; base_amount: number; peak_surcharge: number; total_amount: number
  is_peak_hour: boolean; traffic_condition: string | null
}

interface Claim {
  id: number; uuid: string; reference_number: string
  title: string; description: string | null; category: string
  category_id: number | null; claim_date: string; currency: string
  total_amount: number; status: string; is_travel: boolean
  items: ClaimItem[]; travel_trips: TravelTrip[]
}

const props = defineProps<{ claim: Claim; categories: Category[] }>()

const confirmDialog = ref()

const headerForm = useForm({
  title:       props.claim.title,
  category_id: props.claim.category_id,
  claim_date:  props.claim.claim_date,
  description: props.claim.description ?? '',
})

function saveHeader() {
  headerForm.patch(route('claims.my.update', { claim: props.claim.uuid }))
}

const selectedCat = () => props.categories.find(cat => cat.id === headerForm.category_id) ?? null
const requiresReceipt = () => selectedCat()?.requires_receipt ?? false

const newItem = ref({ item_date: props.claim.claim_date, description: '', unit_price: 0, quantity: 1, item_type: '', receipt_required: true, _file: null as File | null })
const newItemFileRef = ref<HTMLInputElement | null>(null)
const addingItem = ref(false)

function onNewItemFile(e: Event) {
  newItem.value._file = (e.target as HTMLInputElement).files?.[0] ?? null
}

const canAddItem = () =>
  !!(newItem.value.description.trim() && newItem.value.unit_price > 0) &&
  (!requiresReceipt() || newItem.value._file !== null)

function addItem() {
  if (!canAddItem()) return
  addingItem.value = true
  const fd = new FormData()
  fd.append('description', newItem.value.description)
  fd.append('unit_price', String(newItem.value.unit_price))
  fd.append('quantity', String(newItem.value.quantity || 1))
  if (newItem.value.item_date) fd.append('item_date', newItem.value.item_date)
  if (newItem.value.item_type) fd.append('item_type', newItem.value.item_type)
  fd.append('receipt_required', newItem.value.receipt_required ? '1' : '0')

  router.post(route('claims.my.items.add', { claim: props.claim.uuid }), fd as any, {
    forceFormData: true,
    onSuccess: () => {
      newItem.value = { item_date: props.claim.claim_date, description: '', unit_price: 0, quantity: 1, item_type: '', receipt_required: true, _file: null }
      if (newItemFileRef.value) newItemFileRef.value.value = ''
    },
    onFinish: () => { addingItem.value = false },
  })
}

function removeItem(itemId: number) {
  confirmDialog.value.open(
    { title: t('claims_edit.remove_item_title'), message: t('claims_edit.remove_item_message'), confirmText: t('claims_edit.remove_item_confirm'), confirmColor: 'error', icon: 'tabler-trash' },
    () => { router.delete(route('claims.my.items.remove', { claim: props.claim.uuid, item: itemId })) },
  )
}

const receiptDialog   = ref(false)
const receiptItemId   = ref<number | null>(null)
const receiptFile     = ref<File | null>(null)
const receiptFileRef  = ref<HTMLInputElement | null>(null)
const uploadingReceipt = ref(false)

function openReceiptUpload(itemId: number) {
  receiptItemId.value = itemId
  receiptFile.value = null
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
    onFinish: () => { uploadingReceipt.value = false },
  })
}

const submitting = ref(false)
function submitClaim() {
  submitting.value = true
  router.post(route('claims.my.submit', { claim: props.claim.uuid }), {}, {
    onFinish: () => { submitting.value = false },
  })
}

const hasMissingReceipts = () =>
  props.claim.items.some(item => item.receipt_required && !item.receipt_uploaded)

const fmt = (n: number) => 'R ' + n.toLocaleString('en-ZA', { minimumFractionDigits: 2 })
const mimeIcon  = (m?: string) => m?.includes('pdf') ? 'tabler-file-type-pdf' : 'tabler-file-type-jpg'
const mimeColor = (m?: string) => m?.includes('pdf') ? 'error' : 'primary'
</script>

<template>
  <div class="pa-4" style="max-width:1100px;margin:0 auto">
    <ConfirmDialog ref="confirmDialog" />

    <div class="d-flex align-center ga-2 mb-5 flex-wrap">
      <VBtn icon="tabler-arrow-left" variant="text" :href="route('claims.my.index')" />
      <div class="flex-grow-1">
        <h1 class="text-h5 font-weight-bold">{{ t('claims_edit.page_title') }}</h1>
        <p class="text-body-2 text-medium-emphasis">{{ t('claims_edit.draft_info', { reference: claim.reference_number }) }}</p>
      </div>
    </div>

    <VAlert type="info" variant="tonal" density="compact" class="mb-4" prepend-icon="tabler-pencil">
      {{ t('claims_edit.draft_alert') }}
    </VAlert>

    <!-- Claim Header -->
    <VCard class="mb-4 pa-4">
      <div class="text-body-1 font-weight-bold mb-3 d-flex align-center ga-2">
        <VIcon color="primary">tabler-file-text</VIcon> {{ t('claims_edit.claim_details.title') }}
      </div>
      <VRow dense>
        <VCol cols="12" md="8">
          <VTextField v-model="headerForm.title" :label="t('claims_edit.claim_details.claim_title')" variant="outlined" density="compact"
            :error-messages="headerForm.errors.title" />
        </VCol>
        <VCol cols="12" md="4">
          <VTextField v-model="headerForm.claim_date" :label="t('claims_edit.claim_details.claim_date')" type="date" variant="outlined" density="compact"
            :error-messages="headerForm.errors.claim_date" />
        </VCol>
        <VCol cols="12" md="6">
          <VSelect v-model="headerForm.category_id" :label="t('claims_edit.claim_details.category')" variant="outlined" density="compact"
            :items="categories.map(cat => ({ value: cat.id, title: cat.name + (cat.is_travel ? ' 🚗' : '') }))"
            clearable :error-messages="headerForm.errors.category_id" />
        </VCol>
        <VCol cols="12">
          <VTextarea v-model="headerForm.description" :label="t('claims_edit.claim_details.description_purpose')" variant="outlined" density="compact" rows="2"
            :error-messages="headerForm.errors.description" />
        </VCol>
      </VRow>
      <div class="d-flex justify-end mt-2">
        <VBtn color="primary" variant="tonal" size="small" prepend-icon="tabler-device-floppy"
          :loading="headerForm.processing" @click="saveHeader">
          {{ t('claims_edit.claim_details.save_details') }}
        </VBtn>
      </div>
    </VCard>

    <!-- Travel Trips (read-only) -->
    <VCard v-if="claim.is_travel" class="mb-4 pa-4">
      <div class="text-body-1 font-weight-bold mb-3 d-flex align-center ga-2">
        <VIcon color="info">tabler-route</VIcon> {{ t('claims_edit.travel_trips.title') }}
        <VChip size="x-small" color="info" variant="tonal">{{ t('claims_edit.travel_trips.trips_count', { count: claim.travel_trips.length }) }}</VChip>
      </div>
      <VTable density="compact">
        <thead>
          <tr class="text-caption">
            <th>{{ t('claims_edit.travel_trips.table.date') }}</th>
            <th>{{ t('claims_edit.travel_trips.table.route') }}</th>
            <th class="text-right">{{ t('claims_edit.travel_trips.table.distance') }}</th>
            <th class="text-right">{{ t('claims_edit.travel_trips.table.rate_per_km') }}</th>
            <th class="text-right">{{ t('claims_edit.travel_trips.table.base') }}</th>
            <th class="text-right">{{ t('claims_edit.travel_trips.table.peak') }}</th>
            <th class="text-right">{{ t('claims_edit.travel_trips.table.total') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="trip in claim.travel_trips" :key="trip.id">
            <td class="text-caption text-no-wrap">{{ trip.trip_date }}<br><span class="text-medium-emphasis">{{ trip.departure_time }}</span></td>
            <td>
              <span class="text-body-2">{{ trip.origin }}</span>
              <VIcon size="12" class="mx-1">tabler-arrow-right</VIcon>
              <span class="text-body-2">{{ trip.destination }}</span>
              <div class="d-flex ga-1 mt-1">
                <VChip v-if="trip.is_peak_hour" size="x-small" color="warning" variant="tonal">{{ t('claims_edit.travel_trips.peak') }}</VChip>
                <VChip v-if="trip.traffic_condition" size="x-small"
                  :color="trip.traffic_condition==='heavy'?'error':trip.traffic_condition==='moderate'?'warning':'success'"
                  variant="tonal">{{ trip.traffic_condition }}</VChip>
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
            <td colspan="6" class="text-right font-weight-bold text-caption pa-2">{{ t('claims_edit.travel_trips.total_label') }}</td>
            <td class="text-right font-weight-bold text-primary pa-2">{{ fmt(claim.total_amount) }}</td>
          </tr>
        </tfoot>
      </VTable>
    </VCard>

    <!-- Standard Line Items -->
    <VCard v-if="!claim.is_travel" class="mb-4 pa-4">
      <div class="text-body-1 font-weight-bold mb-3 d-flex align-center ga-2">
        <VIcon color="primary">tabler-list</VIcon> {{ t('claims_edit.line_items.title') }}
        <VChip size="x-small" color="primary" variant="tonal">{{ claim.items.length }}</VChip>
      </div>

      <VTable v-if="claim.items.length" density="compact" class="mb-4">
        <thead>
          <tr class="text-caption">
            <th>{{ t('claims_edit.line_items.table.date') }}</th>
            <th>{{ t('claims_edit.line_items.table.description') }}</th>
            <th class="text-right">{{ t('claims_edit.line_items.table.unit_price') }}</th>
            <th class="text-right">{{ t('claims_edit.line_items.table.qty') }}</th>
            <th class="text-right">{{ t('claims_edit.line_items.table.amount') }}</th>
            <th>{{ t('claims_edit.line_items.table.type') }}</th>
            <th>{{ t('claims_edit.line_items.table.document') }}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in claim.items" :key="item.id"
            :class="{ 'bg-error-subtle': item.receipt_required && !item.receipt_uploaded }">
            <td class="text-caption text-medium-emphasis text-no-wrap">{{ item.item_date || '—' }}</td>
            <td class="text-body-2">{{ item.description }}</td>
            <td class="text-right text-no-wrap">{{ fmt(item.unit_price) }}</td>
            <td class="text-right">{{ item.quantity }}</td>
            <td class="text-right font-weight-bold text-no-wrap">{{ fmt(item.amount) }}</td>
            <td><span class="text-caption text-medium-emphasis">{{ item.item_type || '—' }}</span></td>
            <td style="min-width:150px">
              <div v-if="item.receipt_uploaded && item.attachments.length" class="d-flex ga-1 flex-wrap">
                <VBtn
                  v-for="att in item.attachments.filter(a => a.type === 'receipt')" :key="att.id"
                  :href="att.download_url" target="_blank"
                  size="x-small" variant="tonal"
                  :color="mimeColor(att.mime)" :prepend-icon="mimeIcon(att.mime)"
                >{{ t('claims_edit.line_items.view') }}</VBtn>
              </div>
              <div v-else-if="item.receipt_required" class="d-flex align-center ga-1">
                <VBtn size="x-small" color="warning" variant="tonal" prepend-icon="tabler-upload"
                  @click="openReceiptUpload(item.id)">{{ t('claims_edit.line_items.upload') }}</VBtn>
              </div>
              <span v-else class="text-caption text-medium-emphasis">—</span>
            </td>
            <td>
              <VBtn icon="tabler-trash" size="x-small" variant="text" color="error" @click="removeItem(item.id)" />
            </td>
          </tr>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="4" class="text-right font-weight-bold text-caption pa-2">{{ t('claims_edit.line_items.total') }}</td>
            <td class="text-right font-weight-bold text-primary pa-2">{{ fmt(claim.total_amount) }}</td>
            <td colspan="3"></td>
          </tr>
        </tfoot>
      </VTable>

      <div v-else class="text-center py-4 text-medium-emphasis mb-4">
        <VIcon size="36" class="opacity-30 mb-1">tabler-list</VIcon>
        <div class="text-caption">{{ t('claims_edit.line_items.empty') }}</div>
      </div>

      <VDivider class="mb-3" />
      <div class="text-body-2 font-weight-bold mb-2 d-flex align-center ga-1">
        <VIcon size="16" color="primary">tabler-circle-plus</VIcon> {{ t('claims_edit.line_items.add_item') }}
      </div>
      <VCard variant="outlined" class="pa-3">
        <VRow dense align="center">
          <VCol cols="6" md="2">
            <VTextField v-model="newItem.item_date" :label="t('claims_edit.line_items.date_field')" type="date" variant="outlined" density="compact" hide-details />
          </VCol>
          <VCol cols="12" md="3">
            <VTextField v-model="newItem.description" :label="t('claims_edit.line_items.description_field')" variant="outlined" density="compact" hide-details />
          </VCol>
          <VCol cols="6" md="2">
            <VTextField v-model.number="newItem.unit_price" :label="t('claims_edit.line_items.unit_price_field')" type="number" min="0.01" step="0.01"
              variant="outlined" density="compact" hide-details />
          </VCol>
          <VCol cols="4" md="1">
            <VTextField v-model.number="newItem.quantity" :label="t('claims_edit.line_items.qty_field')" type="number" min="1"
              variant="outlined" density="compact" hide-details />
          </VCol>
          <VCol cols="8" md="2">
            <VTextField v-model="newItem.item_type" :label="t('claims_edit.line_items.type_field')" variant="outlined" density="compact"
              hide-details :placeholder="t('claims_edit.line_items.type_placeholder')" />
          </VCol>
          <VCol cols="12" md="auto">
            <div class="d-flex align-center ga-2 flex-wrap">
              <template v-if="requiresReceipt()">
                <VBtn size="small"
                  :color="newItem._file ? 'success' : 'warning'" variant="tonal"
                  :prepend-icon="newItem._file ? 'tabler-paperclip' : 'tabler-file-upload'"
                  style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                  @click="newItemFileRef?.click()"
                >{{ newItem._file ? newItem._file.name : t('claims_edit.line_items.attach_document') }}</VBtn>
                <input ref="newItemFileRef" type="file" accept=".pdf,.jpg,.jpeg,.png" class="d-none" @change="onNewItemFile" />
              </template>
              <VTooltip v-if="!canAddItem()" location="top"
                :text="requiresReceipt() && !newItem._file ? t('claims_edit.line_items.attach_first') : t('claims_edit.line_items.fill_fields')">
                <template #activator="{ props: tp }">
                  <span v-bind="tp"><VBtn color="primary" prepend-icon="tabler-plus" size="small" disabled>{{ t('claims_edit.line_items.add') }}</VBtn></span>
                </template>
              </VTooltip>
              <VBtn v-else color="primary" prepend-icon="tabler-plus" size="small" :loading="addingItem" @click="addItem">{{ t('claims_edit.line_items.add') }}</VBtn>
            </div>
          </VCol>
        </VRow>
      </VCard>
    </VCard>

    <VAlert
      v-if="!claim.is_travel && hasMissingReceipts()"
      type="error" variant="tonal" class="mb-4"
      prepend-icon="tabler-receipt-off"
    >
      {{ t('claims_edit.missing_receipts_banner') }}
    </VAlert>

    <div class="d-flex ga-3 justify-end flex-wrap">
      <VBtn variant="outlined" :href="route('claims.my.index')">{{ t('claims_edit.cancel') }}</VBtn>
      <VTooltip v-if="!claim.is_travel && hasMissingReceipts()" location="top">
        <template #activator="{ props: tp }">
          <span v-bind="tp">
            <VBtn color="primary" disabled prepend-icon="tabler-send">{{ t('claims_edit.submit_for_approval') }}</VBtn>
          </span>
        </template>
        {{ t('claims_edit.upload_docs_first') }}
      </VTooltip>
      <VBtn v-else color="primary" prepend-icon="tabler-send" :loading="submitting" @click="submitClaim">
        {{ t('claims_edit.submit_for_approval') }}
      </VBtn>
    </div>

    <VDialog v-model="receiptDialog" max-width="460" persistent>
      <VCard>
        <VCardTitle class="d-flex align-center ga-2 pa-4">
          <VIcon color="warning">tabler-receipt-plus</VIcon> {{ t('claims_edit.upload_document_title') }}
        </VCardTitle>
        <VDivider />
        <VCardText class="pt-4">
          <VSheet border class="pa-6 text-center cursor-pointer" @click="receiptFileRef?.click()">
            <VIcon size="36" class="mb-2" :color="receiptFile ? 'success' : 'warning'">
              {{ receiptFile ? 'tabler-file-check' : 'tabler-cloud-upload' }}
            </VIcon>
            <div class="text-body-2">
              <span v-if="receiptFile" class="text-success font-weight-medium">{{ receiptFile.name }}</span>
              <span v-else class="text-medium-emphasis">{{ t('claims_edit.click_to_select') }}</span>
            </div>
            <input ref="receiptFileRef" type="file" accept=".pdf,.jpg,.jpeg,.png" class="d-none" @change="onReceiptFileChange" />
          </VSheet>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-3">
          <VSpacer />
          <VBtn variant="outlined" @click="receiptDialog = false">{{ t('claims_edit.cancel') }}</VBtn>
          <VBtn color="warning" :loading="uploadingReceipt" :disabled="!receiptFile"
            prepend-icon="tabler-upload" @click="uploadReceipt">{{ t('claims_edit.upload') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
