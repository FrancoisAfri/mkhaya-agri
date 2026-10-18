<script setup lang="ts">
import type { VForm } from 'vuetify/components/VForm'
import { ref, watch, nextTick } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { usePositionStore } from '../Store/store'

const { t } = useI18n()
const store    = usePositionStore()
const refForm  = ref<VForm>()
const required = (v: unknown) => (v !== null && v !== undefined && v !== '') || t('job_positions_page.required')
const form     = useForm({ name: '', description: '', status: 1 })

watch(() => store.selectedCat, (cat) => {
  if (cat) { form.name = cat.name; form.description = cat.description ?? ''; form.status = cat.status }
}, { immediate: true })

watch(() => store.isCatEditOpen, (val) => {
  if (!val) nextTick(() => { form.clearErrors(); refForm.value?.resetValidation() })
})

const onSubmit = async () => {
  const { valid } = await refForm.value!.validate()
  if (!valid) return
  form.put(`/settings/positions/categories/${store.selectedCat?.id}`, {
    preserveScroll: true,
    onSuccess: () => {
      store.updateCategory({ ...store.selectedCat!, name: form.name, description: form.description, status: form.status })
      store.closeCatEdit()
    },
  })
}
</script>

<template>
  <VDialog :model-value="store.isCatEditOpen" max-width="460" scrollable @update:model-value="store.closeCatEdit">
    <VCard rounded="lg" elevation="0">
      <div class="modal-header">
        <div class="modal-icon" style="background:rgba(var(--v-theme-warning),0.12)">
          <VIcon icon="tabler-folder-edit" color="warning" size="18" />
        </div>
        <div class="min-w-0">
          <p class="text-body-1 font-weight-semibold mb-0">{{ t('job_positions_page.edit_category_title') }}</p>
          <p class="text-caption text-medium-emphasis mb-0 text-truncate" style="max-width:220px">{{ store.selectedCat?.name }}</p>
        </div>
        <VBtn icon="tabler-x" variant="text" size="small" class="ms-auto" @click="store.closeCatEdit" />
      </div>

      <VCardText class="pa-6" style="overflow-y:auto;max-height:55vh">
        <VForm ref="refForm" @submit.prevent="onSubmit">
          <div class="form-block mb-4">
            <p class="block-label">{{ t('job_positions_page.field_name') }}</p>
            <VTextField v-model="form.name" :rules="[required]" :error-messages="form.errors.name"
              :label="t('job_positions_page.field_category_name')" prepend-inner-icon="tabler-folder" variant="outlined" density="compact" autofocus />
          </div>
          <div class="form-block mb-4">
            <p class="block-label">{{ t('job_positions_page.field_description') }}</p>
            <VTextarea v-model="form.description" :label="t('job_positions_page.field_description')" variant="outlined" density="compact" rows="3" no-resize />
          </div>
          <div class="form-block">
            <p class="block-label">{{ t('job_positions_page.field_status') }}</p>
            <VSwitch v-model="form.status" :true-value="1" :false-value="0"
              :label="form.status ? t('job_positions_page.active') : t('job_positions_page.inactive')" color="success" hide-details inset />
          </div>
        </VForm>
      </VCardText>

      <div class="modal-footer">
        <VBtn variant="tonal" class="flex-grow-1" rounded="lg" :disabled="form.processing" @click="store.closeCatEdit">
          {{ t('job_positions_page.cancel') }}
        </VBtn>
        <VBtn color="warning" class="flex-grow-1" rounded="lg" :loading="form.processing" @click="onSubmit">
          <template #loader><VProgressCircular indeterminate size="18" width="2" color="white" class="me-2" /><span>{{ t('job_positions_page.saving') }}</span></template>
          <VIcon icon="tabler-device-floppy" size="16" class="me-2" />
          {{ t('job_positions_page.update') }}
        </VBtn>
      </div>
    </VCard>
  </VDialog>
</template>

<style scoped>
.modal-header { display:flex;align-items:center;gap:12px;padding:16px 24px;border-bottom:1px solid rgba(var(--v-border-color),var(--v-border-opacity)); }
.modal-icon   { width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0; }
.modal-footer { display:flex;gap:12px;padding:16px 24px;border-top:1px solid rgba(var(--v-border-color),var(--v-border-opacity)); }
.form-block   { background:rgba(var(--v-border-color),.04);border:1px solid rgba(var(--v-border-color),var(--v-border-opacity));border-radius:12px;padding:16px; }
.block-label  { font-size:11px;font-weight:700;letter-spacing:.6px;color:rgba(var(--v-theme-on-surface),.5);text-transform:uppercase;margin:0 0 12px; }
</style>
