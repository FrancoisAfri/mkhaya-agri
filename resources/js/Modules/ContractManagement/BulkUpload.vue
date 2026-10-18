<script setup lang="ts">
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { route as ziggyRoute } from 'ziggy-js'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const route = ziggyRoute

defineProps<{
  types: { id: number; name: string }[]
  categories: { id: number; name: string }[]
  suppliers: { id: number; name: string }[]
}>()

const files = ref<File[]>([])
const submitting = ref(false)
const form = ref({
  contract_type_id: null as number | null,
  contract_category_id: null as number | null,
  supplier_id: null as number | null,
})

function onFiles(f: any) {
  files.value = Array.isArray(f) ? f : (f ? [f] : [])
}
function fmtSize(b: number) {
  return b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.round(b / 1024) + ' KB'
}
function submit() {
  if (!files.value.length) return
  submitting.value = true
  router.post(route('contracts.bulk-upload.store'), { ...form.value, files: files.value }, {
    forceFormData: true,
    onFinish: () => (submitting.value = false),
  })
}
</script>

<template>
  <div class="pa-4">
    <div class="d-flex align-center gap-3 mb-4">
      <VBtn icon="tabler-arrow-left" variant="text" @click="router.get(route('contracts.index'))" />
      <div>
        <h4 class="text-h4 mb-0">{{ t('contracts_bulk_upload.page_title') }}</h4>
        <p class="text-caption text-medium-emphasis mb-0">{{ t('contracts_bulk_upload.description') }}</p>
      </div>
    </div>

    <VAlert type="info" variant="tonal" class="mb-4" icon="tabler-clock">
      {{ t('contracts_bulk_upload.alert.text') }}
    </VAlert>

    <VCard border>
      <VCardText>
        <VFileInput
          :label="t('contracts_bulk_upload.form.contract_files')"
          variant="outlined"
          multiple
          chips
          counter
          show-size
          prepend-icon="tabler-files"
          accept=".pdf,.doc,.docx"
          class="mb-3"
          @update:model-value="onFiles"
        />

        <VRow>
          <VCol cols="12" md="4">
            <VSelect
              v-model="form.contract_type_id"
              :items="types"
              item-title="name"
              item-value="id"
              :label="t('contracts_bulk_upload.form.type')"
              variant="outlined"
              clearable
            />
          </VCol>
          <VCol cols="12" md="4">
            <VSelect
              v-model="form.contract_category_id"
              :items="categories"
              item-title="name"
              item-value="id"
              :label="t('contracts_bulk_upload.form.category')"
              variant="outlined"
              clearable
            />
          </VCol>
          <VCol cols="12" md="4">
            <VSelect
              v-model="form.supplier_id"
              :items="suppliers"
              item-title="name"
              item-value="id"
              :label="t('contracts_bulk_upload.form.supplier')"
              variant="outlined"
              clearable
            />
          </VCol>
        </VRow>

        <VTable v-if="files.length" class="mt-2">
          <thead>
            <tr>
              <th>{{ t('contracts_bulk_upload.table.file') }}</th>
              <th class="text-right">{{ t('contracts_bulk_upload.table.size') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(file, i) in files" :key="i">
              <td>{{ file.name }}</td>
              <td class="text-right">{{ fmtSize(file.size) }}</td>
            </tr>
          </tbody>
        </VTable>
      </VCardText>
      <VCardActions class="pa-4">
        <span class="text-caption text-medium-emphasis">{{ t('contracts_bulk_upload.files_selected', { count: files.length }) }}</span>
        <VSpacer />
        <VBtn variant="text" @click="router.get(route('contracts.index'))">
          {{ t('contracts_bulk_upload.cancel') }}
        </VBtn>
        <VBtn
          color="primary"
          :loading="submitting"
          :disabled="!files.length"
          prepend-icon="tabler-upload"
          @click="submit"
        >
          {{ t('contracts_bulk_upload.upload_process') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </div>
</template>
