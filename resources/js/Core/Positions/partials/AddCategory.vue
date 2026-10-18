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

watch(() => store.isCatAddOpen, (val) => {
  if (val) nextTick(() => {
    form.name = ''; form.description = ''; form.status = 1
    form.clearErrors(); refForm.value?.resetValidation()
  })
})

const onSubmit = async () => {
  const { valid } = await refForm.value!.validate()
  if (!valid) return
  form.post('/settings/positions/categories', {
    preserveScroll: true,
    onSuccess: (page) => {
      const cat = (page.props.flash as any)?.newCategory
      if (cat) store.addCategory(cat)
      store.closeCatAdd()
    },
  })
}
</script>

<template>
  <VDialog :model-value="store.isCatAddOpen" max-width="460" scrollable @update:model-value="store.closeCatAdd">
    <VCard rounded="lg" elevation="0">
      <div class="modal-header">
        <div class="modal-icon" style="background:rgba(var(--v-theme-primary),0.12)">
          <VIcon icon="tabler-folder-plus" color="primary" size="18" />
        </div>
        <div>
          <p class="text-body-1 font-weight-semibold mb-0">{{ t('job_positions_page.add_category_title') }}</p>
          <p class="text-caption text-medium-emphasis mb-0">{{ t('job_positions_page.add_category_sub') }}</p>
        </div>
        <VBtn icon="tabler-x" variant="text" size="small" class="ms-auto" @click="store.closeCatAdd" />
      </div>

      <VCardText class="pa-6" style="overflow-y:auto;max-height:55vh">
        <VForm ref="refForm" @submit.prevent="onSubmit">
          <div class="form-block mb-4">
            <p class="block-label">{{ t('job_positions_page.field_name') }}</p>
            <VTextField v-model="form.name" :rules="[required]" :error-messages="form.errors.name"
              :label="t('job_positions_page.field_category_name')" prepend-inner-icon="tabler-folder"
              variant="outlined" density="compact" autofocus />
          </div>
          <div class="form-block mb-4">
            <p class="block-label">{{ t('job_positions_page.field_description') }}</p>
            <VTextarea v-model="form.description" :error-messages="form.errors.description"
              :label="t('job_positions_page.field_description')" variant="outlined" density="compact" rows="3" no-resize />
          </div>
          <div class="form-block">
            <p class="block-label">{{ t('job_positions_page.field_status') }}</p>
            <VSwitch v-model="form.status" :true-value="1" :false-value="0"
              :label="form.status ? t('job_positions_page.active') : t('job_positions_page.inactive')" color="success" hide-details inset />
          </div>
        </VForm>
      </VCardText>

      <div class="modal-footer">
        <VBtn variant="tonal" class="flex-grow-1" rounded="lg" :disabled="form.processing" @click="store.closeCatAdd">
          {{ t('job_positions_page.cancel') }}
        </VBtn>
        <VBtn color="primary" class="flex-grow-1" rounded="lg" :loading="form.processing" @click="onSubmit">
          <template #loader><VProgressCircular indeterminate size="18" width="2" color="white" class="me-2" /><span>{{ t('job_positions_page.saving') }}</span></template>
          <VIcon icon="tabler-device-floppy" size="16" class="me-2" />
          {{ t('job_positions_page.save') }}
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
