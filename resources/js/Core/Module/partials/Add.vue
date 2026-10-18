<script setup lang="ts">
import type { VForm } from 'vuetify/components/VForm'
import { ref, nextTick, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import type { ModuleProperties } from '../types/type'
import { faIcons as _faIcons } from '@/utils/icon'

const { t } = useI18n()
const faIcons = _faIcons ?? []

interface Props { isDrawerOpen: boolean }
interface Emit { (e: 'update:isDrawerOpen', value: boolean): void; (e: 'userData', value: ModuleProperties): void }

const props = defineProps<Props>()
const emit = defineEmits<Emit>()

const categoryId = parseInt(window.location.pathname.split('/')[2]) || 0
const isFormValid = ref(false)
const refForm = ref<VForm>()

const requiredValidator = (v: unknown) => (v !== null && v !== undefined && v !== '') || t('modules.add.validation_required')

const form = useForm({ name: '', font_awesome: '', code_name: '', path: '', category_id: categoryId, active: null as boolean | null })

watch(() => props.isDrawerOpen, (val) => { if (!val) { nextTick(() => { form.reset(); form.clearErrors(); refForm.value?.resetValidation(); form.category_id = categoryId }) } })

const closeDialog = () => emit('update:isDrawerOpen', false)

const onSubmit = async () => {
  if (!refForm.value) return
  const { valid } = await refForm.value.validate()
  if (!valid) return
  form.category_id = categoryId
  form.post('/module/store', { preserveScroll: true, onSuccess: () => { emit('userData', { id: 0, category_id: categoryId, name: form.name, font_awesome: form.font_awesome, path: form.path, code_name: form.code_name, active: form.active ?? false }); emit('update:isDrawerOpen', false) } })
}
</script>

<template>
  <VDialog :model-value="props.isDrawerOpen" max-width="520" persistent @update:model-value="emit('update:isDrawerOpen', $event)">
    <VCard rounded="md" elevation="0">
      <div class="d-flex align-center justify-space-between px-6 py-4" style="border-bottom:1px solid rgba(var(--v-border-color),var(--v-border-opacity))">
        <div class="d-flex align-center gap-3">
          <div class="d-flex align-center justify-center rounded-lg flex-shrink-0" style="width:38px;height:38px;background:#ede9fe"><i class="fa-solid fa-puzzle-piece"></i></div>
          <div><p class="text-body-1 font-weight-semibold mb-0"><i class="fa-solid fa-plus"></i> {{ t('modules.add.title') }}</p><p class="text-caption text-medium-emphasis mb-0">{{ t('modules.add.subtitle') }}</p></div>
        </div>
        <VBtn icon="tabler-x" variant="text" size="small" @click="closeDialog" />
      </div>
      <VCardText class="pa-6">
        <VForm ref="refForm" v-model="isFormValid" @submit.prevent="onSubmit">
          <VRow dense>
            <VCol cols="12"><VTextField v-model="form.name" :rules="[requiredValidator]" :error-messages="form.errors.name" :label="t('modules.add.name_label')" :placeholder="t('modules.add.name_placeholder')" prepend-inner-icon="tabler-tag" variant="outlined" density="compact" /></VCol>
            <VCol cols="12">
              <VAutocomplete v-model="form.font_awesome" :items="faIcons" :rules="[requiredValidator]" :error-messages="form.errors.font_awesome" :label="t('modules.add.icon_label')" :placeholder="t('modules.add.icon_placeholder')" variant="outlined" density="compact" clearable :no-data-text="t('modules.add.icon_no_data')">
                <template #prepend-inner><span style="width:22px;text-align:center;font-size:15px;line-height:1.5"><i v-if="form.font_awesome" :class="`fa-solid fa-${form.font_awesome}`" /><VIcon v-else icon="tabler-mood-smile" size="18" /></span></template>
                <template #selection="{ item }"><div class="d-flex align-center gap-2"><i :class="`fa-solid fa-${item.value}`" style="font-size:14px;width:18px;text-align:center" /><span class="text-body-2">{{ item.value }}</span></div></template>
                <template #item="{ props: itemProps, item }"><VListItem v-bind="itemProps" :title="item.value"><template #prepend><div style="width:32px;text-align:center;font-size:16px"><i :class="`fa-solid fa-${item.value}`" /></div></template></VListItem></template>
              </VAutocomplete>
            </VCol>
            <VCol cols="12"><VTextField v-model="form.code_name" :rules="[requiredValidator]" :error-messages="form.errors.code_name" :label="t('modules.add.code_name_label')" :placeholder="t('modules.add.code_name_placeholder')" prepend-inner-icon="tabler-code" variant="outlined" density="compact" /></VCol>
            <VCol cols="12"><VTextField v-model="form.path" :rules="[requiredValidator]" :error-messages="form.errors.path" :label="t('modules.add.path_label')" :placeholder="t('modules.add.path_placeholder')" prepend-inner-icon="tabler-link" variant="outlined" density="compact" /></VCol>
            <VCol cols="12"><VSelect v-model="form.active" :rules="[requiredValidator]" :error-messages="form.errors.active" :label="t('modules.add.status_label')" prepend-inner-icon="tabler-circle-check" variant="outlined" density="compact" :items="[{ title: t('modules.add.status_active'), value: true }, { title: t('modules.add.status_inactive'), value: false }]" item-title="title" item-value="value" /></VCol>
          </VRow>
        </VForm>
      </VCardText>
      <div class="d-flex gap-3 px-6 py-4" style="border-top:1px solid rgba(var(--v-border-color),var(--v-border-opacity))">
        <VBtn variant="tonal" color="error" class="flex-grow-1" rounded="md" :disabled="form.processing" @click="closeDialog"><i class="fa-regular fa-x me-2"></i>{{ t('modules.add.cancel') }}</VBtn>
        <VBtn color="success" class="flex-grow-1" rounded="md" :loading="form.processing" @click="onSubmit">
          <template #loader><VProgressCircular indeterminate size="18" width="2" color="white" class="me-2" /><span>{{ t('modules.add.saving') }}</span></template>
          <i class="fa-regular fa-floppy-disk me-2"></i>{{ t('modules.add.save') }}
        </VBtn>
      </div>
    </VCard>
  </VDialog>
</template>
