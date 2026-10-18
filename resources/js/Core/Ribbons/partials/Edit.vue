<script setup lang="ts">
import type { VForm } from 'vuetify/components/VForm'
import { ref, nextTick, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import type { RibbonProperties } from '../types/type'
import { faIcons as _faIcons } from '@/utils/icon'

const { t } = useI18n()
const faIcons = _faIcons ?? []

interface Props { isDrawerOpen: boolean; ribbon: RibbonProperties | null }
interface Emit {
  (e: 'update:isDrawerOpen', value: boolean): void
  (e: 'updated', value: RibbonProperties): void
}

const props = defineProps<Props>()
const emit  = defineEmits<Emit>()

const isFormValid = ref(false)
const refForm     = ref<VForm>()

const requiredValidator = (v: unknown) =>
  (v !== null && v !== undefined && v !== '') || t('ribbons.edit.validation_required')

const accessLevels = [
  { title: t('ribbons.access_levels.0'), value: 0 },
  { title: t('ribbons.access_levels.1'), value: 1 },
  { title: t('ribbons.access_levels.2'), value: 2 },
  { title: t('ribbons.access_levels.3'), value: 3 },
  { title: t('ribbons.access_levels.4'), value: 4 },
  { title: t('ribbons.access_levels.5'), value: 5 },
]

const form = useForm({
  ribbon_name:  '', font_awesome: '', ribbon_path: '', description: '',
  sort_order:   1, access_level: null as number | null, active: null as boolean | null,
})

watch(() => props.ribbon, (val) => {
  if (val) { form.ribbon_name = val.ribbon_name; form.font_awesome = val.font_awesome; form.ribbon_path = val.ribbon_path; form.description = val.description; form.sort_order = val.sort_order; form.access_level = val.access_level; form.active = val.active }
}, { immediate: true })

watch(() => props.isDrawerOpen, (val) => { if (!val) { nextTick(() => { form.reset(); form.clearErrors(); refForm.value?.resetValidation() }) } })

const closeDialog = () => emit('update:isDrawerOpen', false)

const onSubmit = async () => {
  if (!refForm.value) return
  const { valid } = await refForm.value.validate()
  if (!valid) return
  form.put(`/ribbon/edit/${props.ribbon?.id}`, {
    preserveScroll: true,
    onSuccess: () => {
      emit('updated', { id: props.ribbon?.id ?? 0, module_id: props.ribbon?.module_id ?? 0, ribbon_name: form.ribbon_name, font_awesome: form.font_awesome, ribbon_path: form.ribbon_path, description: form.description, sort_order: form.sort_order, access_level: form.access_level ?? 0, active: form.active ?? false })
      emit('update:isDrawerOpen', false)
    },
  })
}
</script>

<template>
  <VDialog :model-value="props.isDrawerOpen" max-width="520" persistent @update:model-value="emit('update:isDrawerOpen', $event)">
    <VCard rounded="md" elevation="0">
      <div class="d-flex align-center justify-space-between px-6 py-4" style="border-bottom:1px solid rgba(var(--v-border-color),var(--v-border-opacity))">
        <div class="d-flex align-center gap-3">
          <div class="d-flex align-center justify-center rounded-lg flex-shrink-0" style="width:38px;height:38px;background:#fef3c7"><i class="fa-solid fa-pen-to-square" style="color:#d97706"></i></div>
          <div><p class="text-body-1 font-weight-semibold mb-0"><i class="fa-solid fa-pen-to-square"></i> {{ t('ribbons.edit.title') }}</p><p class="text-caption text-medium-emphasis mb-0">{{ t('ribbons.edit.subtitle') }}</p></div>
        </div>
        <VBtn icon="tabler-x" variant="text" size="small" @click="closeDialog" />
      </div>

      <VCardText class="pa-6">
        <VForm ref="refForm" v-model="isFormValid" @submit.prevent="onSubmit">
          <VRow dense>
            <VCol cols="12"><VTextField v-model="form.ribbon_name" :rules="[requiredValidator]" :error-messages="form.errors.ribbon_name" :label="t('ribbons.edit.name_label')" :placeholder="t('ribbons.edit.name_placeholder')" prepend-inner-icon="tabler-tag" variant="outlined" density="compact" /></VCol>
            <VCol cols="12">
              <VAutocomplete v-model="form.font_awesome" :items="faIcons" :rules="[requiredValidator]" :error-messages="form.errors.font_awesome" :label="t('ribbons.edit.icon_label')" :placeholder="t('ribbons.edit.icon_placeholder')" variant="outlined" density="compact" clearable :no-data-text="t('ribbons.edit.icon_no_data')">
                <template #prepend-inner><span style="width:22px;text-align:center;font-size:15px;line-height:1.5"><i v-if="form.font_awesome" :class="`fa-solid fa-${form.font_awesome}`" /><VIcon v-else icon="tabler-mood-smile" size="18" /></span></template>
                <template #selection="{ item }"><div class="d-flex align-center gap-2"><i :class="`fa-solid fa-${item.value}`" style="font-size:14px;width:18px;text-align:center" /><span class="text-body-2">{{ item.value }}</span></div></template>
                <template #item="{ props: itemProps, item }"><VListItem v-bind="itemProps" :title="item.value"><template #prepend><div style="width:32px;text-align:center;font-size:16px"><i :class="`fa-solid fa-${item.value}`" /></div></template></VListItem></template>
              </VAutocomplete>
            </VCol>
            <VCol cols="12"><VTextField v-model="form.ribbon_path" :rules="[requiredValidator]" :error-messages="form.errors.ribbon_path" :label="t('ribbons.edit.path_label')" :placeholder="t('ribbons.edit.path_placeholder')" prepend-inner-icon="tabler-link" variant="outlined" density="compact" /></VCol>
            <VCol cols="12"><VTextarea v-model="form.description" :rules="[requiredValidator]" :error-messages="form.errors.description" :label="t('ribbons.edit.description_label')" :placeholder="t('ribbons.edit.description_placeholder')" prepend-inner-icon="tabler-file-text" variant="outlined" density="compact" rows="2" auto-grow /></VCol>
            <VCol cols="6"><VTextField v-model="form.sort_order" :rules="[requiredValidator]" :error-messages="form.errors.sort_order" :label="t('ribbons.edit.sort_order_label')" type="number" prepend-inner-icon="tabler-arrows-sort" variant="outlined" density="compact" /></VCol>
            <VCol cols="6"><VSelect v-model="form.access_level" :rules="[requiredValidator]" :error-messages="form.errors.access_level" :label="t('ribbons.edit.access_level_label')" prepend-inner-icon="tabler-shield" variant="outlined" density="compact" :items="accessLevels" item-title="title" item-value="value" /></VCol>
            <VCol cols="12"><VSelect v-model="form.active" :rules="[requiredValidator]" :error-messages="form.errors.active" :label="t('ribbons.edit.status_label')" prepend-inner-icon="tabler-circle-check" variant="outlined" density="compact" :items="[{ title: t('ribbons.edit.status_active'), value: true }, { title: t('ribbons.edit.status_inactive'), value: false }]" item-title="title" item-value="value" /></VCol>
          </VRow>
        </VForm>
      </VCardText>

      <div class="d-flex gap-3 px-6 py-4" style="border-top:1px solid rgba(var(--v-border-color),var(--v-border-opacity))">
        <VBtn variant="tonal" color="error" class="flex-grow-1" rounded="md" :disabled="form.processing" @click="closeDialog"><i class="fa-regular fa-x me-2"></i>{{ t('ribbons.edit.cancel') }}</VBtn>
        <VBtn color="warning" class="flex-grow-1" rounded="md" :loading="form.processing" @click="onSubmit">
          <template #loader><VProgressCircular indeterminate size="18" width="2" color="white" class="me-2" /><span>{{ t('ribbons.edit.updating') }}</span></template>
          <i class="fa-regular fa-floppy-disk me-2"></i>{{ t('ribbons.edit.update') }}
        </VBtn>
      </div>
    </VCard>
  </VDialog>
</template>
