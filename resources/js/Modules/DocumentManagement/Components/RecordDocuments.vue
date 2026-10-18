<script setup lang="ts">
/**
 * The documents filed against one record, shown on that record's own page.
 *
 * This is the piece the rest of the platform embeds — an employee, a vehicle, a supplier. It takes
 * what the host controller already worked out and renders nothing at all when the module is not
 * available, because a panel that appears and then refuses every action reads as a broken page
 * rather than as a module the company has not bought.
 *
 * Uploading here files and links in one step. A document uploaded from an employee's page and then
 * not attached to them is one nobody will ever find again.
 */
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { route } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const d = (key: string, a?: Record<string, unknown>) => t('document_management.' + key, a ?? {})

export interface RecordDocument {
  uuid: string
  title: string
  category: string | null
  expiry_date: string | null
  is_expired: boolean
  uploaded_at: string | null
}

const props = withDefaults(defineProps<{
  /** Straight from DocumentModule::panelFor() on the host controller. */
  available: boolean
  relation: string | null
  record: string | number | null
  canUpload: boolean
  count: number
  documents: RecordDocument[]
  /** What this panel is called on the host page — "Employee documents", "Vehicle documents". */
  title?: string
}>(), { title: undefined })

const uploading = ref(false)

const form = useForm({
  file: null as File | null,
  title: '',
  expiry_date: '',
  description: '',
})

function submit() {
  if (!props.relation || !props.record) return

  form.post(route('document-management.records.upload', [props.relation, props.record]), {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => { uploading.value = false; form.reset() },
  })
}

function detach(document: RecordDocument) {
  if (!props.relation || !props.record) return

  router.post(route('document-management.records.detach', [props.relation, props.record]),
    { document: document.uuid }, { preserveScroll: true })
}
</script>

<template>
  <!-- Nothing at all where the module is not available. Not an empty card, not a message: a company
       without Document Management should not be told about it on every employee page. -->
  <VCard v-if="available" elevation="0" border>
    <VCardText class="d-flex align-center ga-2 pb-2">
      <VIcon size="20" icon="mdi-folder-account-outline" />
      <!-- "Documents (14)" — the count is the thing somebody scans a record's page for. -->
      <span class="text-body-2 font-weight-medium">
        {{ title ?? d('record_documents') }}<span v-if="count"> ({{ count }})</span>
      </span>
      <VSpacer />
      <VBtn
        v-if="canUpload" size="small" variant="tonal" color="primary"
        prepend-icon="mdi-upload" @click="uploading = true"
      >{{ d('record_upload') }}</VBtn>
    </VCardText>

    <VDivider />

    <VList v-if="documents.length" density="compact">
      <VListItem v-for="document in documents" :key="document.uuid">
        <template #prepend>
          <VIcon size="18" icon="mdi-file-document-outline" />
        </template>

        <VListItemTitle class="text-body-2">
          <a :href="route('document-management.documents.show', document.uuid)">{{ document.title }}</a>
          <!-- An expired certificate is the thing somebody opened this record to check. -->
          <VChip v-if="document.is_expired" size="x-small" color="error" variant="tonal" class="ms-1">
            {{ d('record_expired') }}
          </VChip>
        </VListItemTitle>

        <VListItemSubtitle class="text-caption">
          <span v-if="document.category">{{ document.category }}</span>
          <template v-if="document.expiry_date"> · {{ d('record_expires', { date: document.expiry_date }) }}</template>
          <template v-else-if="document.uploaded_at"> · {{ document.uploaded_at }}</template>
        </VListItemSubtitle>

        <template #append>
          <VBtn
            v-if="canUpload" size="small" variant="text"
            :aria-label="d('record_detach')" icon="mdi-link-off"
            @click="detach(document)"
          />
        </template>
      </VListItem>
    </VList>

    <VCardText v-else class="text-body-2 text-medium-emphasis">
      {{ d('record_none') }}
    </VCardText>
  </VCard>

  <VDialog v-model="uploading" max-width="520">
    <VCard>
      <VCardTitle class="text-body-1 font-weight-medium">{{ d('record_upload') }}</VCardTitle>
      <VCardText class="d-flex flex-column ga-3">
        <!-- Said once here rather than discovered afterwards: this files it in the library too. -->
        <div class="text-caption text-medium-emphasis">{{ d('record_upload_hint') }}</div>

        <VFileInput
          v-model="form.file" :label="d('record_file')" density="comfortable"
          prepend-icon="" prepend-inner-icon="mdi-paperclip"
          :error-messages="form.errors.file"
        />

        <VTextField
          v-model="form.title" :label="d('record_title')" density="comfortable"
          :hint="d('record_title_hint')" persistent-hint
        />

        <VTextField
          v-model="form.expiry_date" type="date" :label="d('record_expiry')"
          density="comfortable" :hint="d('record_expiry_hint')" persistent-hint
        />
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn variant="text" @click="uploading = false">{{ d('cancel') }}</VBtn>
        <VBtn color="primary" :loading="form.processing" :disabled="!form.file" @click="submit">
          {{ d('record_upload') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
