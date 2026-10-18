<script setup lang="ts">
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import AppFileInput    from '@/@core/components/app-form-elements/AppFileInput.vue'
import AppAutocomplete from '@/@core/components/app-form-elements/AppAutocomplete.vue'

const { t } = useI18n()
const route = ziggyRoute

defineProps<{
  assets: { id: number; uuid: string; name: string; asset_tag: string | null }[]
}>()

const fileInput = ref<File | null>(null)
const assetId = ref<number | null>(null)

const form = useForm({
  file: null as File | null,
  asset_id: null as number | null,
})

function submit() {
  if (!fileInput.value) {
    form.setError('file', t('assets_document_extractions_create.validation.select_file'))
    return
  }

  form.file = fileInput.value
  // Corrigé : Convertir en nombre ou null pour éviter d'envoyer une chaîne vide
  form.asset_id = assetId.value ? Number(assetId.value) : null
  form.clearErrors()

  form.post(route('assets.document-extractions.store'), {
    onSuccess: () => {
      // Réinitialiser les champs après succès si nécessaire
      fileInput.value = null
      assetId.value = null
    },
  })
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center gap-3 mb-6">
      <VBtn icon variant="text" @click="router.visit(route('assets.document-extractions.index'))">
        <VIcon icon="tabler-arrow-left" />
      </VBtn>
      <div>
        <h4 class="text-h4">{{ t('assets_document_extractions_create.page_title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('assets_document_extractions_create.description') }}</p>
      </div>
    </div>

    <VRow justify="center">
      <VCol cols="12" md="8" lg="6">
        <VForm @submit.prevent="submit">
          <VCard class="mb-4">
            <VCardTitle class="pa-4 pb-2 text-subtitle-1">
              {{ t('assets_document_extractions_create.document_details') }}
            </VCardTitle>
            <VDivider />
            <VCardText class="pa-4">
              <VRow>
                <VCol cols="12">
                  <AppFileInput
                    v-model="fileInput"
                    :label="t('assets_document_extractions_create.form.pdf_document')"
                    accept=".pdf"
                    prepend-inner-icon="tabler-file-type-pdf"
                    :error-messages="form.errors.file ? [form.errors.file] : []"
                    :hint="t('assets_document_extractions_create.form.pdf_hint')"
                    persistent-hint
                  />
                </VCol>
                <VCol cols="12">
                  <AppAutocomplete
                    v-model="assetId"
                    :items="assets"
                    item-title="name"
                    item-value="id"
                    :label="t('assets_document_extractions_create.form.link_asset')"
                    clearable
                    :hint="t('assets_document_extractions_create.form.link_hint')"
                    persistent-hint
                    :error-messages="form.errors.asset_id ? [form.errors.asset_id] : []"
                  >
                    <template #item="{ item, props: p }">
                      <VListItem v-bind="p" :subtitle="item.raw.asset_tag ?? ''" />
                    </template>
                  </AppAutocomplete>
                </VCol>
              </VRow>

              <VAlert type="info" variant="tonal" density="compact" icon="tabler-info-circle" class="mt-4">
                {{ t('assets_document_extractions_create.alert') }}
              </VAlert>
            </VCardText>
          </VCard>

          <div class="d-flex gap-3">
            <VBtn
              color="primary"
              type="submit"
              :loading="form.processing"
              prepend-icon="tabler-upload"
            >
              {{ t('assets_document_extractions_create.form.upload_extract') }}
            </VBtn>
            <VBtn
              variant="tonal"
              color="secondary"
              @click="router.visit(route('assets.document-extractions.index'))"
            >
              {{ t('assets_document_extractions_create.form.cancel') }}
            </VBtn>
          </div>
        </VForm>
      </VCol>
    </VRow>
  </VContainer>
</template>
