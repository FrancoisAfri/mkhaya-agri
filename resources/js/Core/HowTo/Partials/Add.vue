<script setup lang="ts">
import type { VForm } from 'vuetify/components/VForm'
import { ref, watch, nextTick } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import type { HowToProperties, RibbonProperties } from '../Store/type'

const { t } = useI18n()

interface Props { isDrawerOpen: boolean; moduleId: number; ribbon: RibbonProperties }
interface Emit { (e: 'update:isDrawerOpen', value: boolean): void; (e: 'created', value: HowToProperties): void }

const props = defineProps<Props>()
const emit = defineEmits<Emit>()

const isFormValid = ref(false)
const refForm = ref<VForm>()

const requiredValidator = (v: unknown) => (v !== null && v !== undefined && v !== '') || t('howto.add.validation_required')

const form = useForm({ module_id: props.moduleId, ribbon_id: props.ribbon.id, question: '', answer: '', active: true as boolean })

watch(() => props.ribbon, (val) => { form.ribbon_id = val.id })
watch(() => props.isDrawerOpen, (val) => { if (!val) { nextTick(() => { form.reset(); form.clearErrors(); refForm.value?.resetValidation() }) } })

const closeDialog = () => emit('update:isDrawerOpen', false)

const onSubmit = async () => {
  if (!refForm.value) return
  const { valid } = await refForm.value.validate()
  if (!valid) return
  form.post('/how-to/store', { preserveScroll: true, onSuccess: (page) => { const howto = (page.props as any)?.flash?.howto as HowToProperties | undefined; emit('created', howto ?? { id: 0, module_id: form.module_id, ribbon_id: form.ribbon_id, question: form.question, answer: form.answer || null, active: form.active }); emit('update:isDrawerOpen', false) } })
}
</script>

<template>
  <VDialog :model-value="props.isDrawerOpen" max-width="560" persistent @update:model-value="emit('update:isDrawerOpen', $event)">
    <VCard rounded="lg" elevation="0">
      <div class="d-flex align-center justify-space-between px-6 py-4" style="border-bottom:1px solid rgba(var(--v-border-color),var(--v-border-opacity))">
        <div class="d-flex align-center gap-3">
          <div class="d-flex align-center justify-center rounded-lg flex-shrink-0" style="width:40px;height:40px;background:#dcfce7">
            <i class="fa-solid fa-circle-question" style="color:#16a34a;font-size:18px" />
          </div>
          <div>
            <p class="text-body-1 font-weight-semibold mb-0">{{ t('howto.add.title') }}</p>
            <p class="text-caption text-medium-emphasis mb-0"><i class="fa-solid fa-ribbon me-1" style="font-size:10px" />{{ props.ribbon.ribbon_name }}</p>
          </div>
        </div>
        <VBtn icon="tabler-x" variant="text" size="small" @click="closeDialog" />
      </div>
      <VCardText class="pa-6">
        <VForm ref="refForm" v-model="isFormValid" @submit.prevent="onSubmit">
          <VRow dense>
            <VCol cols="12"><VTextField v-model="form.question" :rules="[requiredValidator]" :error-messages="form.errors.question" :label="t('howto.add.question_label')" :placeholder="t('howto.add.question_placeholder')" prepend-inner-icon="tabler-help-circle" variant="outlined" density="compact" counter="255" maxlength="255" /></VCol>
            <VCol cols="12"><VTextarea v-model="form.answer" :error-messages="form.errors.answer" :label="t('howto.add.answer_label')" :placeholder="t('howto.add.answer_placeholder')" prepend-inner-icon="tabler-message-2" variant="outlined" density="compact" rows="5" auto-grow /><p class="text-caption text-medium-emphasis mt-1 mb-0"><i class="fa-solid fa-circle-info me-1" />{{ t('howto.add.answer_hint') }}</p></VCol>
            <VCol cols="12"><VSelect v-model="form.active" :label="t('howto.add.status_label')" prepend-inner-icon="tabler-circle-check" variant="outlined" density="compact" :items="[{ title: t('howto.add.status_active'), value: true }, { title: t('howto.add.status_inactive'), value: false }]" item-title="title" item-value="value" /></VCol>
          </VRow>
        </VForm>
      </VCardText>
      <div class="d-flex gap-3 px-6 py-4" style="border-top:1px solid rgba(var(--v-border-color),var(--v-border-opacity))">
        <VBtn variant="tonal" color="error" class="flex-grow-1" rounded="md" :disabled="form.processing" @click="closeDialog"><i class="fa-regular fa-circle-xmark me-2" />{{ t('howto.add.cancel') }}</VBtn>
        <VBtn color="success" class="flex-grow-1" rounded="md" :loading="form.processing" @click="onSubmit">
          <template #loader><VProgressCircular indeterminate size="18" width="2" color="white" class="me-2" /><span>{{ t('howto.add.saving') }}</span></template>
          <i class="fa-regular fa-floppy-disk me-2" />{{ t('howto.add.save') }}
        </VBtn>
      </div>
    </VCard>
  </VDialog>
</template>
