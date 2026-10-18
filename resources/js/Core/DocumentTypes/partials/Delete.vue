<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { useDocTypeStore } from '../Store/documentTypeStore'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const store = useDocTypeStore()

const doDelete = () => {
  if (!store.selected) return

  const deletedId = store.selected.id

  router.delete(`/settings/document-types/${store.selected.id}`, {
    preserveScroll: true,

    onSuccess: () => {
      store.removeItem(deletedId)
      store.closeDelete()
    },

    onError: (errors) => {
      store.showSnack('Failed to delete document type', 'error')
    }
  })
}
</script>

<template>
  <VDialog :model-value="store.isDeleteOpen" max-width="420" persistent @update:model-value="store.closeDelete">
    <VCard rounded="lg" elevation="0">
      <div class="px-6 py-5">
        <div class="d-flex align-center gap-3 mb-3">
          <div class="d-flex align-center justify-center rounded-lg flex-shrink-0"
            style="width:44px;height:44px;background:#fef2f2">
            <i class="fa-solid fa-triangle-exclamation" style="color:#ef4444;font-size:18px" />
          </div>
          <div>
            <p class="text-body-1 font-weight-semibold mb-0">{{ t('document_types_page.delete_document') }}</p>
            <p class="text-caption text-medium-emphasis mb-0">{{ t('document_types_page.action_cannot_be_undone') }}</p>
          </div>
        </div>
        <p class="text-body-2 text-medium-emphasis">
          {{ t('document_types_page.are_you_sure_you_want_to_delete') }}
          <strong>{{ store.selected?.name }}</strong> ?
        </p>
      </div>
      <div class="d-flex gap-3 px-6 pb-5">
        <VBtn variant="tonal" class="flex-grow-1" rounded="lg" @click="store.closeDelete">
          {{ t('cancel') }}
        </VBtn>
        <VBtn color="error" class="flex-grow-1" rounded="lg" @click="doDelete">
          <i class="fa-solid fa-trash me-2" /> {{ t('delete') }}
        </VBtn>
      </div>
    </VCard>
  </VDialog>
</template>
