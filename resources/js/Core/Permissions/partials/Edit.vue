<script setup lang="ts">
import type { VForm } from 'vuetify/components/VForm'
import { ref, nextTick, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import type { PermissionProperties } from '../types/type'

const { t } = useI18n()

interface Props { isDrawerOpen: boolean; permission: PermissionProperties | null }
interface Emit { (e: 'update:isDrawerOpen', value: boolean): void; (e: 'updated', p: PermissionProperties): void }

const props = defineProps<Props>()
const emit = defineEmits<Emit>()

const isFormValid = ref(false)
const refForm = ref<VForm>()

const requiredValidator = (v: unknown) => (v !== null && v !== undefined && v !== '') || t('permissions.edit.validation_required')

const form = useForm({ name: '', guard_name: 'web' })

watch(() => props.permission, (val) => { if (val) { form.name = val.name; form.guard_name = val.guard_name ?? 'web' } }, { immediate: true })
watch(() => props.isDrawerOpen, (val) => { if (!val) { nextTick(() => { form.clearErrors(); refForm.value?.resetValidation() }) } })
const closeDialog = () => emit('update:isDrawerOpen', false)

const onSubmit = async () => {
  if (!refForm.value) return
  const { valid } = await refForm.value.validate()
  if (!valid) return
  form.put(`/settings/permissions/update/${props.permission?.id}`, { preserveScroll: true, onSuccess: () => { emit('updated', { id: props.permission!.id, name: form.name, guard_name: form.guard_name }); emit('update:isDrawerOpen', false) } })
}
</script>

<template>
  <VDialog :model-value="props.isDrawerOpen" max-width="480" persistent scrollable @update:model-value="emit('update:isDrawerOpen', $event)">
    <VCard rounded="lg" elevation="0">
      <div class="d-flex align-center justify-space-between px-6 py-4 flex-shrink-0" style="border-bottom:1px solid rgba(var(--v-border-color),var(--v-border-opacity))">
        <div class="d-flex align-center gap-3 min-w-0 flex-grow-1">
          <div class="d-flex align-center justify-center rounded-lg flex-shrink-0" style="width:40px;height:40px;background:#fef3c7"><i class="fa-solid fa-pen-to-square" style="color:#d97706;font-size:16px" /></div>
          <div class="min-w-0"><p class="text-body-1 font-weight-semibold mb-0">{{ t('permissions.edit.title') }}</p><p class="text-caption text-medium-emphasis mb-0">{{ permission?.name }}</p></div>
        </div>
        <VBtn icon="tabler-x" variant="text" size="small" class="flex-shrink-0" @click="closeDialog" />
      </div>
      <VCardText class="pa-6" style="overflow-y:auto;max-height:60vh">
        <VForm ref="refForm" v-model="isFormValid" @submit.prevent="onSubmit">
          <div class="form-block mb-4">
            <p class="block-label">{{ t('permissions.edit.name_section') }}</p>
            <VTextField v-model="form.name" :rules="[requiredValidator]" :error-messages="form.errors.name" :label="t('permissions.edit.name_label')" prepend-inner-icon="tabler-key" variant="outlined" density="compact" />
            <p class="text-caption text-medium-emphasis mt-1">{{ t('permissions.edit.name_convention', { action: '<strong>action</strong>', resource: '<strong>resource</strong>' }) }}</p>
          </div>
          <div class="form-block">
            <p class="block-label">{{ t('permissions.edit.guard_section') }}</p>
            <VSelect v-model="form.guard_name" :items="['web', 'api']" :label="t('permissions.edit.guard_label')" prepend-inner-icon="tabler-shield" variant="outlined" density="compact" />
          </div>
        </VForm>
      </VCardText>
      <div class="d-flex gap-3 px-6 py-4 flex-shrink-0" style="border-top:1px solid rgba(var(--v-border-color),var(--v-border-opacity))">
        <VBtn variant="tonal" color="error" class="flex-grow-1" rounded="lg" :disabled="form.processing" @click="closeDialog"><i class="fa-regular fa-x me-2" />{{ t('permissions.edit.cancel') }}</VBtn>
        <VBtn color="warning" class="flex-grow-1" rounded="lg" :loading="form.processing" @click="onSubmit">
          <template #loader><VProgressCircular indeterminate size="18" width="2" color="white" class="me-2" /><span>{{ t('permissions.edit.updating') }}</span></template>
          <i class="fa-regular fa-floppy-disk me-2" />{{ t('permissions.edit.update') }}
        </VBtn>
      </div>
    </VCard>
  </VDialog>
</template>

<style scoped>
.form-block { background: rgba(var(--v-border-color), 0.04); border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 12px; padding: 16px; }
.block-label { font-size: 11px; font-weight: 700; letter-spacing: .6px; color: rgba(var(--v-theme-on-surface), 0.5); text-transform: uppercase; margin: 0 0 12px; }
</style>
