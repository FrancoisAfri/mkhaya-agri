<script setup lang="ts">
import type { VForm } from 'vuetify/components/VForm'
import { ref, nextTick, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Props { isDrawerOpen: boolean }
interface Emit { (e: 'update:isDrawerOpen', value: boolean): void; (e: 'created'): void }

const props = defineProps<Props>()
const emit = defineEmits<Emit>()

const isFormValid = ref(false)
const refForm = ref<VForm>()

const requiredValidator = (v: unknown) => (v !== null && v !== undefined && v !== '') || t('permissions.add.validation_required')

const prefixes = ['view', 'create', 'edit', 'delete', 'publish', 'export']

const form = useForm({ name: '', guard_name: 'web' })
const resource = ref('')
const applyTemplate = (prefix: string) => { if (resource.value) form.name = `${prefix} ${resource.value}`; else form.name = prefix + ' ' }

watch(() => props.isDrawerOpen, (val) => { if (!val) { nextTick(() => { form.reset(); form.clearErrors(); refForm.value?.resetValidation(); resource.value = '' }) } })
const closeDialog = () => emit('update:isDrawerOpen', false)

const onSubmit = async () => {
  if (!refForm.value) return
  const { valid } = await refForm.value.validate()
  if (!valid) return
  form.post('/settings/permissions/store', { preserveScroll: true, onSuccess: () => { emit('created'); emit('update:isDrawerOpen', false) } })
}
</script>

<template>
  <VDialog :model-value="props.isDrawerOpen" max-width="480" persistent scrollable @update:model-value="emit('update:isDrawerOpen', $event)">
    <VCard rounded="lg" elevation="0">
      <div class="d-flex align-center justify-space-between px-6 py-4 flex-shrink-0" style="border-bottom:1px solid rgba(var(--v-border-color),var(--v-border-opacity))">
        <div class="d-flex align-center gap-3 min-w-0 flex-grow-1">
          <div class="d-flex align-center justify-center rounded-lg flex-shrink-0" style="width:40px;height:40px;background:#dcfce7"><i class="fa-solid fa-key" style="color:#16a34a;font-size:16px" /></div>
          <div class="min-w-0"><p class="text-body-1 font-weight-semibold mb-0">{{ t('permissions.add.title') }}</p><p class="text-caption text-medium-emphasis mb-0">{{ t('permissions.add.subtitle') }}</p></div>
        </div>
        <VBtn icon="tabler-x" variant="text" size="small" class="flex-shrink-0" @click="closeDialog" />
      </div>
      <VCardText class="pa-6" style="overflow-y:auto;max-height:60vh">
        <VForm ref="refForm" v-model="isFormValid" @submit.prevent="onSubmit">
          <div class="form-block mb-4">
            <p class="block-label">{{ t('permissions.add.quick_template') }}</p>
            <VTextField v-model="resource" :label="t('permissions.add.resource_label')" :placeholder="t('permissions.add.resource_placeholder')" prepend-inner-icon="tabler-cube" variant="outlined" density="compact" class="mb-3" hide-details />
            <div class="d-flex flex-wrap gap-2">
              <button v-for="prefix in prefixes" :key="prefix" type="button" class="prefix-btn" @click="applyTemplate(prefix)"><i class="fa-solid fa-bolt" style="font-size:10px" />{{ prefix }}</button>
            </div>
            <p class="text-caption text-medium-emphasis mt-2">{{ t('permissions.add.prefix_hint') }}</p>
          </div>
          <div class="form-block mb-4">
            <p class="block-label">{{ t('permissions.add.name_section') }}</p>
            <VTextField v-model="form.name" :rules="[requiredValidator]" :error-messages="form.errors.name" :label="t('permissions.add.name_label')" :placeholder="t('permissions.add.name_placeholder')" prepend-inner-icon="tabler-key" variant="outlined" density="compact" />
            <p class="text-caption text-medium-emphasis mt-1">{{ t('permissions.add.name_convention', { action: '<strong>action</strong>', resource: '<strong>resource</strong>' }) }}</p>
          </div>
          <div class="form-block">
            <p class="block-label">{{ t('permissions.add.guard_section') }}</p>
            <VSelect v-model="form.guard_name" :items="['web', 'api']" :label="t('permissions.add.guard_label')" prepend-inner-icon="tabler-shield" variant="outlined" density="compact" />
          </div>
        </VForm>
      </VCardText>
      <div class="d-flex gap-3 px-6 py-4 flex-shrink-0" style="border-top:1px solid rgba(var(--v-border-color),var(--v-border-opacity))">
        <VBtn variant="tonal" color="error" class="flex-grow-1" rounded="lg" :disabled="form.processing" @click="closeDialog"><i class="fa-regular fa-x me-2" />{{ t('permissions.add.cancel') }}</VBtn>
        <VBtn color="success" class="flex-grow-1" rounded="lg" :loading="form.processing" @click="onSubmit">
          <template #loader><VProgressCircular indeterminate size="18" width="2" color="white" class="me-2" /><span>{{ t('permissions.add.saving') }}</span></template>
          <i class="fa-regular fa-floppy-disk me-2" />{{ t('permissions.add.save') }}
        </VBtn>
      </div>
    </VCard>
  </VDialog>
</template>

<style scoped>
.form-block { background: rgba(var(--v-border-color), 0.04); border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 12px; padding: 16px; }
.block-label { font-size: 11px; font-weight: 700; letter-spacing: .6px; color: rgba(var(--v-theme-on-surface), 0.5); text-transform: uppercase; margin: 0 0 12px; }
.prefix-btn { display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; border-radius: 8px; border: 1.5px solid rgba(var(--v-border-color), var(--v-border-opacity)); background: transparent; font-size: 12px; font-weight: 600; color: rgba(var(--v-theme-on-surface), 0.6); cursor: pointer; transition: all .15s; }
.prefix-btn:hover { border-color: #16a34a; color: #16a34a; background: #f0fdf4; }
</style>
