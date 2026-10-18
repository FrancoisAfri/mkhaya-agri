<script setup lang="ts">
import { ref, reactive, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{ assets: { uuid: string; name: string; asset_number: string }[] }>()

const form = reactive({
  file:          null as File | null,
  document_type: 'invoice',
  asset_uuid:    null as string | null,
})

const documentTypes = computed(() => [
  { v: 'invoice',     t: t('assets_documents_upload.types.invoice') },
  { v: 'warranty',    t: t('assets_documents_upload.types.warranty') },
  { v: 'manual',      t: t('assets_documents_upload.types.manual') },
  { v: 'receipt',     t: t('assets_documents_upload.types.receipt') },
  { v: 'certificate', t: t('assets_documents_upload.types.certificate') },
])

function submit() {
  if (!form.file) return
  router.post(route('assets.documents.upload'), {
    file:          form.file,
    document_type: form.document_type,
    asset_uuid:    form.asset_uuid,
  }, { forceFormData: true })
}
</script>

<template>
  <div class="pa-4">
    <h1 class="text-h4 font-weight-bold mb-4">{{ t('assets_documents_upload.page_title') }}</h1>

    <VCard>
      <VCardItem>
        <VFileInput
          v-model="form.file"
          :label="t('assets_documents_upload.form.file')"
          accept="application/pdf,image/*"
          prepend-icon="tabler-file-upload"
          class="mb-3"
        />
        <VSelect
          v-model="form.document_type"
          :items="documentTypes"
          item-title="t"
          item-value="v"
          :label="t('assets_documents_upload.form.document_type')"
          class="mb-3"
        />
        <VAutocomplete
          v-model="form.asset_uuid"
          :items="props.assets.map(a => ({ value: a.uuid, title: `${a.name} (${a.asset_number})` }))"
          :label="t('assets_documents_upload.form.link_asset')"
          clearable
          class="mb-3"
        />

        <div class="d-flex justify-end gap-2">
          <VBtn variant="text" :to="route('assets.documents.index')">{{ t('assets_documents_upload.form.cancel') }}</VBtn>
          <VBtn color="primary" @click="submit" :disabled="!form.file">{{ t('assets_documents_upload.form.submit') }}</VBtn>
        </div>
      </VCardItem>
    </VCard>
  </div>
</template>
