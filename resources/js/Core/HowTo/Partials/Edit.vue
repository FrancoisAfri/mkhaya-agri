<script setup lang="ts">
import type { VForm } from 'vuetify/components/VForm'
import { ref, watch, nextTick } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import type { HowToProperties } from '../Store/type'

const { t } = useI18n()

interface Props { isDrawerOpen: boolean; howto: HowToProperties | null }
interface Emit { (e: 'update:isDrawerOpen', value: boolean): void; (e: 'updated', value: HowToProperties): void }

const props = defineProps<Props>()
const emit = defineEmits<Emit>()

const isFormValid = ref(false)
const refForm = ref<VForm>()

const requiredValidator = (v: unknown) => (v !== null && v !== undefined && v !== '') || t('howto.edit.validation_required')

const form = useForm({ question: '', answer: '', active: true as boolean })

watch(() => props.howto, (val) => { if (val) { form.question = val.question; form.answer = val.answer ?? ''; form.active = val.active } }, { immediate: true })
watch(() => props.isDrawerOpen, (val) => { if (!val) { nextTick(() => { form.reset(); form.clearErrors(); refForm.value?.resetValidation() }) } })

const closeDialog = () => emit('update:isDrawerOpen', false)

const onSubmit = async () => {
  if (!refForm.value || !props.howto) return
  const { valid } = await refForm.value.validate()
  if (!valid) return
  form.put(`/how-to/edit/${props.howto.id}`, { preserveScroll: true, onSuccess: () => { emit('updated', { ...props.howto!, question: form.question, answer: form.answer || null, active: form.active }); emit('update:isDrawerOpen', false) } })
}
</script>

<template>
  <VDialog :model-value="props.isDrawerOpen" max-width="560" persistent @update:model-value="emit('update:isDrawerOpen', $event)">
    <VCard rounded="lg" elevation="0">
      <div class="d-flex align-center justify-space-between px-6 py-4" style="border-bottom:1px solid rgba(var(--v-border-color),var(--v-border-opacity))">
        <div class="d-flex align-center gap-3">
          <div class="d-flex align-center justify-center rounded-lg flex-shrink-0" style="width:40px;height:40px;background:#fef3c7">
            <i class="fa-solid fa-pen-to-square" style="color:#d97706;font-size:18px" />
          </div>
          <div>
            <p class="text-body-1 font-weight-semibold mb-0">{{ t('howto.edit.title') }}</p>
            <p class="text-caption text-medium-emphasis mb-0">{{ t('howto.edit.subtitle') }}</p>
          </div>
        </div>
        <VBtn icon="tabler-x" variant="text" size="small" @click="closeDialog" />
      </div>
      <VCardText class="pa-6">
        <VForm ref="refForm" v-model="isFormValid" @submit.prevent="onSubmit">
          <VRow dense>
            <VCol cols="12"><VTextField v-model="form.question" :rules="[requiredValidator]" :error-messages="form.errors.question" :label="t('howto.edit.question_label')" :placeholder="t('howto.edit.question_placeholder')" prepend-inner-icon="tabler-help-circle" variant="outlined" density="compact" counter="255" maxlength="255" /></VCol>
            <VCol cols="12"><VTextarea v-model="form.answer" :error-messages="form.errors.answer" :label="t('howto.edit.answer_label')" :placeholder="t('howto.edit.answer_placeholder')" prepend-inner-icon="tabler-message-2" variant="outlined" density="compact" rows="5" auto-grow /></VCol>
            <VCol cols="12"><VSelect v-model="form.active" :label="t('howto.edit.status_label')" prepend-inner-icon="tabler-circle-check" variant="outlined" density="compact" :items="[{ title: t('howto.edit.status_active'), value: true }, { title: t('howto.edit.status_inactive'), value: false }]" item-title="title" item-value="value" /></VCol>
          </VRow>
        </VForm>
      </VCardText>
      <div class="d-flex gap-3 px-6 py-4" style="border-top:1px solid rgba(var(--v-border-color),var(--v-border-opacity))">
        <VBtn variant="tonal" color="error" class="flex-grow-1" rounded="md" :disabled="form.processing" @click="closeDialog"><i class="fa-regular fa-circle-xmark me-2" />{{ t('howto.edit.cancel') }}</VBtn>
        <VBtn color="warning" class="flex-grow-1" rounded="md" :loading="form.processing" @click="onSubmit">
          <template #loader><VProgressCircular indeterminate size="18" width="2" color="white" class="me-2" /><span>{{ t('howto.edit.updating') }}</span></template>
          <i class="fa-regular fa-floppy-disk me-2" />{{ t('howto.edit.update') }}
        </VBtn>
      </div>
    </VCard>
  </VDialog>
</template>
