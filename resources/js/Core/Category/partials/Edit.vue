<script setup lang="ts">
import type { VForm } from 'vuetify/components/VForm'
import { ref, nextTick, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import type { CategoriesProperties } from '../types/type'
import { faIcons } from '@/utils/icon'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface Props {
  isDrawerOpen: boolean
  category: CategoriesProperties | null
}
interface Emit {
  (e: 'update:isDrawerOpen', value: boolean): void
  (e: 'updated', value: CategoriesProperties): void
}

const props = defineProps<Props>()
const emit  = defineEmits<Emit>()

const isFormValid = ref(false)
const refForm     = ref<VForm>()

const requiredValidator = (v: unknown) =>
  (v !== null && v !== undefined && v !== '') || t('categories.validation.required')

const form = useForm({
  name:        '',
  icon:        '',
  description: '',
  active:      null as boolean | null,
})

watch(() => props.category, (val) => {
  if (val) {
    form.name        = val.name
    form.icon        = val.icon
    form.description = val.description
    form.active      = val.active
  }
}, { immediate: true })

watch(() => props.isDrawerOpen, (val) => {
  if (!val) {
    nextTick(() => {
      form.reset()
      form.clearErrors()
      refForm.value?.resetValidation()
    })
  }
})

const closeDialog = () => emit('update:isDrawerOpen', false)

const onSubmit = async () => {
  if (!refForm.value) return
  const { valid } = await refForm.value.validate()
  if (!valid) return

  form.put(`/category/edit/${props.category?.id}`, {
    preserveScroll: true,
    onSuccess: () => {
      emit('updated', {
        id:          props.category?.id ?? 0,
        name:        form.name,
        icon:        form.icon,
        description: form.description,
        active:      form.active ?? false,
      })
      emit('update:isDrawerOpen', false)
    },
  })
}
</script>

<template>
  <VDialog
    :model-value="props.isDrawerOpen"
    max-width="520"
    persistent
    @update:model-value="emit('update:isDrawerOpen', $event)"
  >
    <VCard rounded="md" elevation="0">

      <div
        class="d-flex align-center justify-space-between px-6 py-4"
        style="border-bottom:1px solid rgba(var(--v-border-color),var(--v-border-opacity))"
      >
        <div class="d-flex align-center gap-3">
          <div class="d-flex align-center justify-center rounded-lg flex-shrink-0"
            style="width:38px;height:38px;background:#fef3c7">
            <i class="fa-solid fa-pen-to-square" style="color:#d97706"></i>
          </div>
          <div>
            <p class="text-body-1 font-weight-semibold mb-0">
              <i class="fa-solid fa-pen-to-square"></i> {{ t('categories.edit.title') }}
            </p>
            <p class="text-caption text-medium-emphasis mb-0">{{ t('categories.edit.subtitle') }}</p>
          </div>
        </div>
        <VBtn icon="tabler-x" variant="text" size="small" @click="closeDialog" />
      </div>

      <VCardText class="pa-6">
        <VForm ref="refForm" v-model="isFormValid" @submit.prevent="onSubmit">
          <VRow dense>

            <VCol cols="12">
              <VTextField v-model="form.name" :rules="[requiredValidator]" :error-messages="form.errors.name"
                :label="t('categories.edit.name_label')" :placeholder="t('categories.edit.name_placeholder')"
                prepend-inner-icon="tabler-tag" variant="outlined" density="compact" />
            </VCol>

            <VCol cols="12">
              <VAutocomplete v-model="form.icon" :items="faIcons" :rules="[requiredValidator]"
                :error-messages="form.errors.icon" :label="t('categories.edit.icon_label')"
                :placeholder="t('categories.edit.icon_placeholder')" variant="outlined" density="compact"
                clearable :no-data-text="t('categories.edit.icon_no_data')">
                <template #prepend-inner>
                  <span style="width:22px;text-align:center;font-size:15px;line-height:1.5">
                    <i v-if="form.icon" :class="`fa-solid fa-${form.icon}`" />
                    <VIcon v-else icon="tabler-mood-smile" size="18" />
                  </span>
                </template>
                <template #selection="{ item }">
                  <div class="d-flex align-center gap-2">
                    <i :class="`fa-solid fa-${item.value}`" style="font-size:14px;width:18px;text-align:center" />
                    <span class="text-body-2">{{ item.value }}</span>
                  </div>
                </template>
                <template #item="{ props: itemProps, item }">
                  <VListItem v-bind="itemProps" :title="item.value">
                    <template #prepend>
                      <div style="width:32px;text-align:center;font-size:16px">
                        <i :class="`fa-solid fa-${item.value}`" />
                      </div>
                    </template>
                  </VListItem>
                </template>
              </VAutocomplete>
            </VCol>

            <VCol cols="12">
              <VTextarea v-model="form.description" :rules="[requiredValidator]"
                :error-messages="form.errors.description" :label="t('categories.edit.description_label')"
                :placeholder="t('categories.edit.description_placeholder')" prepend-inner-icon="tabler-file-text"
                variant="outlined" density="compact" rows="3" auto-grow />
            </VCol>

            <VCol cols="12">
              <VSelect v-model="form.active" :rules="[requiredValidator]"
                :error-messages="form.errors.active" :label="t('categories.edit.status_label')"
                prepend-inner-icon="tabler-circle-check" variant="outlined" density="compact"
                :items="[
                  { title: t('categories.edit.status_active'), value: true },
                  { title: t('categories.edit.status_inactive'), value: false },
                ]" item-title="title" item-value="value" />
            </VCol>

          </VRow>
        </VForm>
      </VCardText>

      <div class="d-flex gap-3 px-6 py-4"
        style="border-top:1px solid rgba(var(--v-border-color),var(--v-border-opacity))">
        <VBtn variant="tonal" color="error" class="flex-grow-1" rounded="md"
          :disabled="form.processing" @click="closeDialog">
          <i class="fa-regular fa-x me-2"></i> {{ t('categories.edit.cancel') }}
        </VBtn>
        <VBtn color="warning" class="flex-grow-1" rounded="md"
          :loading="form.processing" @click="onSubmit">
          <template #loader>
            <VProgressCircular indeterminate size="18" width="2" color="white" class="me-2" />
            <span>{{ t('categories.edit.updating') }}</span>
          </template>
          <i class="fa-regular fa-floppy-disk me-2"></i> {{ t('categories.edit.update') }}
        </VBtn>
      </div>

    </VCard>
  </VDialog>
</template>
