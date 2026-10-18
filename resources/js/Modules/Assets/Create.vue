<script setup lang="ts">
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import { useDropZone, useFileDialog } from '@vueuse/core'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'
import AppTextField    from '@/@core/components/app-form-elements/AppTextField.vue'
import AppSelect       from '@/@core/components/app-form-elements/AppSelect.vue'
import AppTextarea     from '@/@core/components/app-form-elements/AppTextarea.vue'

const { t } = useI18n()
const route = ziggyRoute

const props = defineProps<{
  assetTypes:            { id: number; name: string }[]
  categories:            { id: number; name: string }[]
  locations:             { id: number; name: string; code: string | null }[]
  sarsAllowanceSections: { value: string; label: string }[]
  makes:                 { id: number; name: string }[]
  models:                { id: number; make_id: number; name: string }[]
}>()

const form = useForm({
  name: '', description: '', serial_number: '', asset_tag: '',
  model_number: '', make_number: '', barcode: '',
  asset_type_id: null as number | null, asset_category_id: null as number | null,
  location_id: null as number | null, purchase_cost: '', purchase_date: '',
  warranty_expiry: '', condition: 'new', sars_allowance_section: '',
  asset_make_id: null as number | null, asset_model_id: null as number | null,
})

const availableModels = computed(() =>
  form.asset_make_id ? props.models.filter(m => m.make_id === form.asset_make_id) : []
)

function onMakeChange() { form.asset_model_id = null }

const conditionItems = [
  { title: t('assets_create.conditions.new'), value: 'new' },
  { title: t('assets_create.conditions.good'), value: 'good' },
  { title: t('assets_create.conditions.fair'), value: 'fair' },
  { title: t('assets_create.conditions.poor'), value: 'poor' },
  { title: t('assets_create.conditions.damaged'), value: 'damaged' },
  { title: t('assets_create.conditions.irreparable'), value: 'irreparable' },
]

const dropZoneRef  = ref<HTMLDivElement>()
const imageFiles   = ref<File[]>([])
const imagePreviews = ref<string[]>([])
const imageError   = ref('')
const MAX_COUNT    = 10
const MAX_SIZE     = 5 * 1024 * 1024
const ALLOWED      = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif']

function addFiles(files: File[]) {
  imageError.value = ''
  for (const file of files) {
    if (imageFiles.value.length >= MAX_COUNT) { imageError.value = t('assets_create.images.max_error', { max: MAX_COUNT }); break }
    if (!ALLOWED.includes(file.type)) { imageError.value = t('assets_create.images.type_error', { name: file.name }); continue }
    if (file.size > MAX_SIZE) { imageError.value = t('assets_create.images.size_error', { name: file.name }); continue }
    imageFiles.value.push(file)
    imagePreviews.value.push(URL.createObjectURL(file))
  }
}

function onDrop(dropped: File[] | null) { if (dropped) addFiles(dropped.filter(f => f.type.startsWith('image/'))) }
const { open, onChange } = useFileDialog({ accept: 'image/*', multiple: true })
onChange(files => { if (files) addFiles(Array.from(files)) })
useDropZone(dropZoneRef, onDrop)

function removeImage(index: number) { URL.revokeObjectURL(imagePreviews.value[index]); imageFiles.value.splice(index, 1); imagePreviews.value.splice(index, 1) }

function submit() { form.transform(data => ({ ...data, images: imageFiles.value })).post(route('assets.store')) }
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">
    <div class="d-flex align-center gap-3 mb-6">
      <VBtn icon variant="text" @click="router.visit(route('assets.index'))"><VIcon icon="tabler-arrow-left" /></VBtn>
      <div>
        <h4 class="text-h4">{{ t('assets_create.page_title') }}</h4>
        <p class="text-medium-emphasis mb-0">{{ t('assets_create.description') }}</p>
      </div>
    </div>

    <VForm @submit.prevent="submit">
      <VRow>
        <VCol cols="12" lg="8">
          <VCard class="mb-4">
            <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('assets_create.basic_information.title') }}</VCardTitle>
            <VDivider />
            <VCardText class="pa-4">
              <VRow>
                <VCol cols="12"><AppTextField v-model="form.name" :label="t('assets_create.basic_information.name')" :error-messages="form.errors.name" autofocus /></VCol>
                <VCol cols="12"><AppTextarea v-model="form.description" :label="t('assets_create.basic_information.description')" rows="2" :error-messages="form.errors.description" /></VCol>
                <VCol cols="12" md="6"><AppSelect v-model="form.asset_type_id" :items="assetTypes" item-title="name" item-value="id" :label="t('assets_create.basic_information.asset_type')" :error-messages="form.errors.asset_type_id" clearable /></VCol>
                <VCol cols="12" md="6"><AppSelect v-model="form.asset_category_id" :items="categories" item-title="name" item-value="id" :label="t('assets_create.basic_information.category')" :error-messages="form.errors.asset_category_id" clearable /></VCol>
                <VCol cols="12" md="6"><AppSelect v-model="form.condition" :items="conditionItems" item-title="title" item-value="value" :label="t('assets_create.basic_information.condition')" :error-messages="form.errors.condition" /></VCol>
                <VCol cols="12" md="6"><AppSelect v-model="form.location_id" :items="locations" :item-title="(l: any) => l.code ? `${l.name} (${l.code})` : l.name" item-value="id" :label="t('assets_create.basic_information.location')" :error-messages="form.errors.location_id" clearable /></VCol>
              </VRow>
            </VCardText>
          </VCard>

          <VCard class="mb-4">
            <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('assets_create.identification.title') }}</VCardTitle>
            <VDivider />
            <VCardText class="pa-4">
              <VRow>
                <VCol cols="12" md="6"><AppTextField v-model="form.serial_number" :label="t('assets_create.identification.serial_number')" :error-messages="form.errors.serial_number" /></VCol>
                <VCol cols="12" md="6"><AppTextField v-model="form.asset_tag" :label="t('assets_create.identification.asset_tag')" :error-messages="form.errors.asset_tag" /></VCol>
                <VCol cols="12" md="6"><AppSelect v-model="form.asset_make_id" :items="makes" item-title="name" item-value="id" :label="t('assets_create.identification.make')" clearable :error-messages="form.errors.asset_make_id" @update:model-value="onMakeChange" /></VCol>
                <VCol cols="12" md="6"><AppSelect v-model="form.asset_model_id" :items="availableModels" item-title="name" item-value="id" :label="t('assets_create.identification.model')" clearable :disabled="!form.asset_make_id" :hint="!form.asset_make_id ? t('assets_create.identification.select_make_first') : ''" :persistent-hint="!form.asset_make_id" :error-messages="form.errors.asset_model_id" /></VCol>
                <VCol cols="12" md="6"><AppTextField v-model="form.barcode" :label="t('assets_create.identification.barcode')" prepend-inner-icon="tabler-barcode" :error-messages="form.errors.barcode" /></VCol>
              </VRow>
            </VCardText>
          </VCard>

          <VCard class="mb-4">
            <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('assets_create.financial.title') }}</VCardTitle>
            <VDivider />
            <VCardText class="pa-4">
              <VRow>
                <VCol cols="12" md="6"><AppTextField v-model="form.purchase_cost" :label="t('assets_create.financial.purchase_cost')" type="number" min="0" prepend-inner-icon="tabler-currency-dollar" :error-messages="form.errors.purchase_cost" /></VCol>
                <VCol cols="12" md="6"><AppTextField v-model="form.purchase_date" :label="t('assets_create.financial.purchase_date')" type="date" :error-messages="form.errors.purchase_date" /></VCol>
                <VCol cols="12" md="6"><AppTextField v-model="form.warranty_expiry" :label="t('assets_create.financial.warranty_expiry')" type="date" :error-messages="form.errors.warranty_expiry" /></VCol>
                <VCol cols="12" md="6"><AppSelect v-model="form.sars_allowance_section" :label="t('assets_create.financial.sars_section')" :items="sarsAllowanceSections" item-title="label" item-value="value" clearable :error-messages="form.errors.sars_allowance_section" /></VCol>
              </VRow>
            </VCardText>
          </VCard>

          <VCard class="mb-6">
            <VCardTitle class="pa-4 pb-2 text-subtitle-1">
              {{ t('assets_create.images.title') }}
              <span class="text-body-2 text-medium-emphasis font-weight-regular ms-1">{{ t('assets_create.images.optional', { max: MAX_COUNT }) }}</span>
            </VCardTitle>
            <VDivider />
            <VCardText class="pa-4">
              <div ref="dropZoneRef" class="cursor-pointer" @click="open()">
                <div v-if="imagePreviews.length === 0" class="d-flex flex-column justify-center align-center gap-y-2 pa-12 drop-zone rounded">
                  <IconBtn variant="tonal" color="secondary" class="rounded" size="40"><VIcon size="24" icon="bx-upload" /></IconBtn>
                  <h4 class="text-h4">{{ t('assets_create.images.drop_title') }}</h4>
                  <span class="text-disabled">{{ t('assets_create.images.or') }}</span>
                  <VBtn variant="tonal" size="small">{{ t('assets_create.images.browse') }}</VBtn>
                  <p class="text-caption text-medium-emphasis mt-1 mb-0">{{ t('assets_create.images.formats') }}</p>
                </div>
                <div v-else class="d-flex justify-center align-center gap-3 pa-8 drop-zone flex-wrap">
                  <VRow class="match-height w-100">
                    <VCol v-for="(src, i) in imagePreviews" :key="i" cols="12" sm="4">
                      <VCard :ripple="false">
                        <VCardText class="d-flex flex-column" @click.stop>
                          <VImg :src="src" height="150px" class="w-100 mx-auto" cover />
                          <div class="mt-2"><p class="text-body-2 clamp-text mb-1">{{ imageFiles[i].name }}</p><p class="text-caption text-medium-emphasis mb-0">{{ (imageFiles[i].size / 1024).toFixed(1) }} KB</p></div>
                        </VCardText>
                        <VCardActions><VBtn variant="text" block color="error" @click.stop="removeImage(i)">{{ t('assets_create.images.remove') }}</VBtn></VCardActions>
                      </VCard>
                    </VCol>
                    <VCol v-if="imagePreviews.length < MAX_COUNT" cols="12" sm="4">
                      <div class="d-flex align-center justify-center rounded cursor-pointer" style="height:234px; border:2px dashed rgba(var(--v-theme-on-surface), var(--v-border-opacity));" @click.stop="open()">
                        <VIcon icon="tabler-plus" size="48" color="medium-emphasis" />
                      </div>
                    </VCol>
                  </VRow>
                </div>
              </div>
              <VAlert v-if="imageError" type="error" variant="tonal" density="compact" class="mt-3">{{ imageError }}</VAlert>
              <VAlert v-if="form.errors.images" type="error" variant="tonal" density="compact" class="mt-3">{{ form.errors.images }}</VAlert>
            </VCardText>
          </VCard>
        </VCol>

        <VCol cols="12" lg="4">
          <VCard class="mb-4">
            <VCardTitle class="pa-4 pb-2 text-subtitle-1">{{ t('assets_create.actions.title') }}</VCardTitle>
            <VDivider />
            <VCardText class="pa-4 d-flex flex-column gap-3">
              <VBtn color="primary" type="submit" :loading="form.processing" prepend-icon="tabler-device-floppy" block>{{ t('assets_create.actions.register') }}</VBtn>
              <VBtn variant="tonal" color="secondary" block @click="router.visit(route('assets.index'))">{{ t('assets_create.actions.cancel') }}</VBtn>
            </VCardText>
          </VCard>
          <VCard>
            <VCardText class="pa-4">
              <VAlert type="info" variant="tonal" density="compact" icon="tabler-info-circle">{{ t('assets_create.info_alert') }}</VAlert>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </VForm>
  </VContainer>
</template>

<style scoped>
.drop-zone { border: 1px dashed rgba(var(--v-theme-on-surface), var(--v-border-opacity)); }
.clamp-text { display: -webkit-box; -webkit-line-clamp: 1; -webkit-box-orient: vertical; overflow: hidden; }
</style>
