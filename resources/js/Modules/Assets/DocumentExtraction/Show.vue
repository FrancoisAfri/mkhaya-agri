<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import AppTextField    from '@/@core/components/app-form-elements/AppTextField.vue'
import AppAutocomplete from '@/@core/components/app-form-elements/AppAutocomplete.vue'
import AppTextarea     from '@/@core/components/app-form-elements/AppTextarea.vue'

const { t } = useI18n()
const route = ziggyRoute

type Extraction = {
  uuid: string
  file_name: string
  file_size: number
  extraction_status: string
  extracted_fields: Record<string, unknown> | null
  confirmed_fields: Record<string, unknown> | null
  extraction_error: string | null
  asset: { id: number; uuid: string; name: string; asset_tag: string | null } | null
  uploader: { name: string } | null
  confirmedBy: { name: string } | null
  rejectedBy: { name: string } | null
  created_at: string
}

const statusMap: Record<string, { label: string; color: string }> = {
  pending:    { label: 'Pending',    color: 'default' },
  processing: { label: 'Processing', color: 'info' },
  extracted:  { label: 'Extracted',  color: 'warning' },
  confirmed:  { label: 'Confirmed',  color: 'success' },
  rejected:   { label: 'Rejected',   color: 'error' },
  failed:     { label: 'Failed',     color: 'error' },
}

const props = defineProps<{
  extraction: Extraction
  assets: { id: number; uuid: string; name: string; asset_tag: string | null }[]
}>()

const showConfirm = ref(false)
const showReject  = ref(false)

const extracted = props.extraction.extracted_fields ?? {}

const confirmForm = useForm({
  confirmed_fields: {
    asset_name:      extracted.asset_name      ?? null,
    serial_number:   extracted.serial_number   ?? null,
    purchase_cost:   extracted.purchase_cost   ?? null,
    purchase_date:   extracted.purchase_date   ?? null,
    vendor_name:     extracted.vendor_name     ?? null,
    model_number:    extracted.model_number    ?? null,
    warranty_expiry: extracted.warranty_expiry ?? null,
  } as Record<string, unknown>,
  asset_id: props.extraction.asset?.id ?? null,
})

const rejectForm = useForm({
  rejection_reason: '',
})

function submitConfirm() {
  confirmForm.post(route('assets.document-extractions.confirm', props.extraction.uuid), {
    onSuccess: () => { showConfirm.value = false },
  })
}

function submitReject() {
  rejectForm.post(route('assets.document-extractions.reject', props.extraction.uuid), {
    onSuccess: () => { showReject.value = false },
  })
}

const fieldLabels: Record<string, string> = {
  asset_name:      t('assets_document_extractions_show.extracted_fields.asset_name'),
  serial_number:   t('assets_document_extractions_show.extracted_fields.serial_number'),
  purchase_cost:   t('assets_document_extractions_show.extracted_fields.purchase_cost'),
  purchase_date:   t('assets_document_extractions_show.extracted_fields.purchase_date'),
  vendor_name:     t('assets_document_extractions_show.extracted_fields.vendor_name'),
  model_number:    t('assets_document_extractions_show.extracted_fields.model_number'),
  warranty_expiry: t('assets_document_extractions_show.extracted_fields.warranty_expiry'),
}

function fieldLabel(key: string) {
  return fieldLabels[key] ?? key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
}

function formatFileSize(bytes: number) {
  return (bytes / 1024).toFixed(1) + ' KB'
}

function formatDate(val: string | null) {
  if (!val) return '—'
  return new Date(val).toLocaleDateString('en-ZA', { day: '2-digit', month: 'short', year: 'numeric' })
}

const extractedKeys = Object.keys(extracted)
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-start justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-3">
        <VBtn icon variant="text" @click="router.visit(route('assets.document-extractions.index'))">
          <VIcon icon="tabler-arrow-left" />
        </VBtn>
        <div>
          <div class="d-flex align-center gap-2 flex-wrap">
            <h4 class="text-h4">{{ extraction.file_name }}</h4>
            <VChip
              :color="statusMap[extraction.extraction_status]?.color ?? 'default'"
              size="small"
              label
              variant="tonal"
              class="font-weight-medium"
            >
              {{ statusMap[extraction.extraction_status]?.label ?? extraction.extraction_status }}
            </VChip>
          </div>
          <p class="text-medium-emphasis mb-0">
            {{ formatFileSize(extraction.file_size) }}
            <span v-if="extraction.asset"> · {{ t('assets_document_extractions_show.linked_to') }} {{ extraction.asset.name }}</span>
          </p>
        </div>
      </div>

      <div v-if="extraction.extraction_status === 'extracted'" class="d-flex gap-2">
        <VBtn color="success" variant="tonal" prepend-icon="tabler-check" @click="showConfirm = true">{{ t('assets_document_extractions_show.actions.confirm') }}</VBtn>
        <VBtn color="error"   variant="tonal" prepend-icon="tabler-x" @click="showReject = true">{{ t('assets_document_extractions_show.actions.reject') }}</VBtn>
      </div>
    </div>

    <VRow>
      <VCol cols="12" md="4">
        <VCard class="mb-4">
          <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('assets_document_extractions_show.upload_info.title') }}</VCardTitle>
          <VDivider />
          <VCardText class="pa-4">
            <div class="d-flex flex-column gap-3">
              <div>
                <p class="text-caption text-medium-emphasis mb-1">{{ t('assets_document_extractions_show.upload_info.uploaded_by') }}</p>
                <p class="text-body-2 font-weight-medium mb-0">{{ extraction.uploader?.name ?? '—' }}</p>
              </div>
              <div>
                <p class="text-caption text-medium-emphasis mb-1">{{ t('assets_document_extractions_show.upload_info.file_size') }}</p>
                <p class="text-body-2 font-weight-medium mb-0">{{ formatFileSize(extraction.file_size) }}</p>
              </div>
              <div>
                <p class="text-caption text-medium-emphasis mb-1">{{ t('assets_document_extractions_show.upload_info.date_uploaded') }}</p>
                <p class="text-body-2 font-weight-medium mb-0">{{ formatDate(extraction.created_at) }}</p>
              </div>
              <div v-if="extraction.asset">
                <p class="text-caption text-medium-emphasis mb-1">{{ t('assets_document_extractions_show.upload_info.linked_asset') }}</p>
                <a :href="route('assets.show', extraction.asset.uuid)" class="text-body-2 font-weight-medium text-primary text-decoration-none">
                  {{ extraction.asset.name }}
                  <span v-if="extraction.asset.asset_tag" class="text-medium-emphasis font-weight-regular"> · {{ extraction.asset.asset_tag }}</span>
                </a>
              </div>
              <div v-if="extraction.confirmedBy">
                <p class="text-caption text-medium-emphasis mb-1">{{ t('assets_document_extractions_show.upload_info.confirmed_by') }}</p>
                <p class="text-body-2 font-weight-medium mb-0">{{ extraction.confirmedBy.name }}</p>
              </div>
              <div v-if="extraction.rejectedBy">
                <p class="text-caption text-medium-emphasis mb-1">{{ t('assets_document_extractions_show.upload_info.rejected_by') }}</p>
                <p class="text-body-2 font-weight-medium mb-0">{{ extraction.rejectedBy.name }}</p>
              </div>
            </div>
          </VCardText>
        </VCard>

        <VAlert
          v-if="extraction.extraction_error"
          type="error"
          variant="tonal"
          density="compact"
          icon="tabler-alert-circle"
          class="mb-4"
        >
          <p class="text-body-2 font-weight-medium mb-1">{{ t('assets_document_extractions_show.extraction_error') }}</p>
          <p class="text-body-2 mb-0">{{ extraction.extraction_error }}</p>
        </VAlert>
      </VCol>

      <VCol cols="12" md="8">
        <VCard>
          <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('assets_document_extractions_show.extracted_fields.title') }}</VCardTitle>
          <VDivider />

          <template v-if="extractedKeys.length">
            <VTable density="comfortable">
              <thead>
                <tr>
                  <th>{{ t('assets_document_extractions_show.extracted_fields.field') }}</th>
                  <th>{{ t('assets_document_extractions_show.extracted_fields.extracted_value') }}</th>
                  <th v-if="extraction.confirmed_fields">{{ t('assets_document_extractions_show.extracted_fields.confirmed_value') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="key in extractedKeys" :key="key">
                  <td class="text-body-2 font-weight-medium" style="width:200px">{{ fieldLabel(key) }}</td>
                  <td class="text-body-2">{{ extracted[key] ?? '—' }}</td>
                  <td v-if="extraction.confirmed_fields" class="text-body-2">
                    <span :class="extraction.confirmed_fields[key] !== extracted[key] ? 'text-success font-weight-medium' : ''">
                      {{ extraction.confirmed_fields[key] ?? '—' }}
                    </span>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </template>

          <VCardText v-else class="text-center py-12">
            <VIcon icon="tabler-file-search" size="64" color="medium-emphasis" class="mb-4" />
            <p class="text-body-1 font-weight-medium mb-1">{{ t('assets_document_extractions_show.no_fields.title') }}</p>
            <p class="text-body-2 text-medium-emphasis mb-0">
              {{ extraction.extraction_status === 'processing'
                  ? t('assets_document_extractions_show.no_fields.processing')
                  : t('assets_document_extractions_show.no_fields.other') }}
            </p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Confirm Dialog -->
    <VDialog v-model="showConfirm" max-width="600">
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('assets_document_extractions_show.confirm_dialog.title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAlert type="info" variant="tonal" density="compact" class="mb-4">
            {{ t('assets_document_extractions_show.confirm_dialog.alert') }}
          </VAlert>
          <VForm @submit.prevent="submitConfirm">
            <VRow>
              <VCol cols="12" sm="6">
                <AppTextField
                  v-model="confirmForm.confirmed_fields.asset_name"
                  :label="t('assets_document_extractions_show.confirm_dialog.asset_name')"
                  :error-messages="confirmForm.errors['confirmed_fields.asset_name']"
                />
              </VCol>
              <VCol cols="12" sm="6">
                <AppTextField
                  v-model="confirmForm.confirmed_fields.serial_number"
                  :label="t('assets_document_extractions_show.confirm_dialog.serial_number')"
                  :error-messages="confirmForm.errors['confirmed_fields.serial_number']"
                />
              </VCol>
              <VCol cols="12" sm="6">
                <AppTextField
                  v-model="confirmForm.confirmed_fields.purchase_cost"
                  :label="t('assets_document_extractions_show.confirm_dialog.purchase_cost')"
                  type="number"
                  :error-messages="confirmForm.errors['confirmed_fields.purchase_cost']"
                />
              </VCol>
              <VCol cols="12" sm="6">
                <AppTextField
                  v-model="confirmForm.confirmed_fields.purchase_date"
                  :label="t('assets_document_extractions_show.confirm_dialog.purchase_date')"
                  type="date"
                  :error-messages="confirmForm.errors['confirmed_fields.purchase_date']"
                />
              </VCol>
              <VCol cols="12" sm="6">
                <AppTextField
                  v-model="confirmForm.confirmed_fields.vendor_name"
                  :label="t('assets_document_extractions_show.confirm_dialog.vendor_name')"
                  :error-messages="confirmForm.errors['confirmed_fields.vendor_name']"
                />
              </VCol>
              <VCol cols="12" sm="6">
                <AppTextField
                  v-model="confirmForm.confirmed_fields.model_number"
                  :label="t('assets_document_extractions_show.confirm_dialog.model_number')"
                  :error-messages="confirmForm.errors['confirmed_fields.model_number']"
                />
              </VCol>
              <VCol cols="12" sm="6">
                <AppTextField
                  v-model="confirmForm.confirmed_fields.warranty_expiry"
                  :label="t('assets_document_extractions_show.confirm_dialog.warranty_expiry')"
                  type="date"
                  :error-messages="confirmForm.errors['confirmed_fields.warranty_expiry']"
                />
              </VCol>
              <VCol cols="12" sm="6">
                <AppAutocomplete
                  v-model="confirmForm.asset_id"
                  :items="assets"
                  item-title="name"
                  item-value="id"
                  :label="t('assets_document_extractions_show.confirm_dialog.apply_to_asset')"
                  clearable
                  :error-messages="confirmForm.errors.asset_id"
                >
                  <template #item="{ item, props: p }">
                    <VListItem v-bind="p" :subtitle="item.raw.asset_tag ?? ''" />
                  </template>
                </AppAutocomplete>
              </VCol>
            </VRow>
          </VForm>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4 gap-2">
          <VSpacer />
          <VBtn variant="text" @click="showConfirm = false">{{ t('assets_document_extractions_show.confirm_dialog.cancel') }}</VBtn>
          <VBtn color="success" :loading="confirmForm.processing" @click="submitConfirm">{{ t('assets_document_extractions_show.confirm_dialog.confirm') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Reject Dialog -->
    <VDialog v-model="showReject" max-width="500">
      <VCard>
        <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('assets_document_extractions_show.reject_dialog.title') }}</VCardTitle>
        <VDivider />
        <VCardText class="pa-4">
          <VAlert type="warning" variant="tonal" density="compact" class="mb-4">
            {{ t('assets_document_extractions_show.reject_dialog.alert') }}
          </VAlert>
          <VForm @submit.prevent="submitReject">
            <AppTextarea
              v-model="rejectForm.rejection_reason"
              :label="t('assets_document_extractions_show.reject_dialog.rejection_reason')"
              rows="4"
              :error-messages="rejectForm.errors.rejection_reason"
            />
          </VForm>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4 gap-2">
          <VSpacer />
          <VBtn variant="text" @click="showReject = false">{{ t('assets_document_extractions_show.reject_dialog.cancel') }}</VBtn>
          <VBtn color="error" :loading="rejectForm.processing" @click="submitReject">{{ t('assets_document_extractions_show.reject_dialog.reject') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VContainer>
</template>
