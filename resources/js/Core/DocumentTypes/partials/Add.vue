<script setup lang="ts">
import type { VForm } from 'vuetify/components/VForm'
import { ref, watch, nextTick } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useDocTypeStore } from '../Store/documentTypeStore'
import { useI18n } from 'vue-i18n'

const store = useDocTypeStore()
const { t } = useI18n()

const refForm     = ref<VForm>()
const isFormValid = ref(false)

const required = (v: unknown) =>
  (v !== null && v !== undefined && v !== '') || t('required_field')

const form = useForm({
  name:        '',
  description: '',
  is_active:   true,
})

watch(() => store.isAddOpen, (val) => {
  if (!val) nextTick(() => { form.reset(); form.clearErrors(); refForm.value?.resetValidation() })
})

const onSubmit = async () => {
  const { valid } = await refForm.value!.validate()
  if (!valid) return

  form.post('/settings/document-types', {
    preserveScroll: true,
    onSuccess: () => {
      store.addItem({ id: 0, name: form.name, description: form.description, is_active: form.is_active })
      store.closeAdd()
    },
  })
}
</script>

<template>
  <VDialog :model-value="store.isAddOpen" max-width="500" persistent scrollable @update:model-value="store.closeAdd">
    <VCard rounded="lg" elevation="0">

      <!-- Header -->
      <div class="d-flex align-center justify-space-between px-6 py-4 flex-shrink-0"
        style="border-bottom:1px solid rgba(var(--v-border-color),var(--v-border-opacity))">
        <div class="d-flex align-center gap-3">
          <div class="d-flex align-center justify-center rounded-lg"
            style="width:40px;height:40px;background:#ede9fe">
            <i class="fa-solid fa-file-circle-plus" style="color:#6366f1;font-size:16px" />
          </div>
          <div>
            <p class="text-body-1 font-weight-semibold mb-0">{{ t('document_types_page.add_new_document') }}</p>
            <p class="text-caption text-medium-emphasis mb-0">{{ t('document_types_page.fill_details_below') }}</p>
          </div>
        </div>
        <VBtn icon="tabler-x" variant="text" size="small" @click="store.closeAdd" />
      </div>

      <!-- Body -->
      <VCardText class="pa-6" style="overflow-y:auto;max-height:60vh">
        <VForm ref="refForm" v-model="isFormValid" @submit.prevent="onSubmit">

          <div class="form-block mb-4">
            <p class="block-label">{{ t('name') }}</p>
            <VTextField
              v-model="form.name"
              :rules="[required]"
              :error-messages="form.errors.name"
              :label="t('name') + ' *'"
              :placeholder="t('ex_identity_document')"
              prepend-inner-icon="tabler-file-text"
              variant="outlined"
              density="compact"
            />
          </div>

          <div class="form-block mb-4">
            <p class="block-label">{{ t('description') }}</p>
            <VTextarea
              v-model="form.description"
              :error-messages="form.errors.description"
              :label="t('description')"
              :placeholder="t('optional_description') + '...'"
              variant="outlined"
              density="compact"
              rows="3"
              no-resize
            />
          </div>

          <div class="form-block">
            <p class="block-label">{{ t('status') }}</p>
            <VSwitch
              v-model="form.is_active"
              :label="form.is_active ? t('active') : t('inactive')"
              color="success"
              hide-details
              inset
            />
          </div>

        </VForm>
      </VCardText>

      <!-- Footer -->
      <div class="d-flex gap-3 px-6 py-4 flex-shrink-0"
        style="border-top:1px solid rgba(var(--v-border-color),var(--v-border-opacity))">
        <VBtn variant="tonal" color="error" class="flex-grow-1" rounded="lg"
          :disabled="form.processing" @click="store.closeAdd">
          <i class="fa-regular fa-x me-2" /> {{ t('cancel') }}
        </VBtn>
        <VBtn color="success" class="flex-grow-1" rounded="lg"
          :loading="form.processing" @click="onSubmit">
          <template #loader>
            <VProgressCircular indeterminate size="18" width="2" color="white" class="me-2" />
            <span>{{ t('saving') }}...</span>
          </template>
          <i class="fa-regular fa-floppy-disk me-2" /> {{ t('save') }}
        </VBtn>
      </div>

    </VCard>
  </VDialog>
</template>

<style scoped>
.form-block {
  background: rgba(var(--v-border-color), 0.04);
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 12px; padding: 16px;
}
.block-label {
  font-size: 11px; font-weight: 700; letter-spacing: .6px;
  color: rgba(var(--v-theme-on-surface), 0.5);
  text-transform: uppercase; margin: 0 0 12px;
}
</style>
