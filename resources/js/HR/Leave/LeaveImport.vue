<script setup lang="ts">
import { ref, computed } from 'vue'
import axios from 'axios'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

interface ImportType {
  key: string; label: string; description: string; icon: string; color: string; columns: string[]
}

const props = withDefaults(defineProps<{ importTypes: ImportType[] }>(), {
  importTypes: () => [],
})

const confirmDialog = ref()

type Step = 'select' | 'upload' | 'preview' | 'summary'
const step        = ref<Step>('select')
const selectedType = ref<ImportType | null>(null)
const uploadedFile = ref<File | null>(null)
const dragging    = ref(false)

const previewing  = ref(false)
const tempId      = ref<string | null>(null)
const validRows   = ref<Record<string, string>[]>([])
const errorRows   = ref<Record<string, string>[]>([])
const previewCols = ref<string[]>([])
const previewTab  = ref<'valid' | 'errors'>('valid')

const importing   = ref(false)
const importResult = ref<{ batch_id: string; imported: number; error_count: number; errors: Record<string, any>[] } | null>(null)

const rollingBack = ref(false)
const rollbackMsg = ref<string | null>(null)

const validHeaders = computed(() =>
  previewCols.value.map(c => ({ title: c.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()), key: c }))
)
const errorHeaders = computed(() => [
  ...validHeaders.value,
  { title: 'Row', key: '_row' },
  { title: 'Errors', key: '_errors' },
])

function selectType(t: ImportType) { selectedType.value = t; step.value = 'upload' }
function onFileChange(e: Event) { const input = e.target as HTMLInputElement; if (input.files?.[0]) setFile(input.files[0]) }
function onDrop(e: DragEvent) { dragging.value = false; const file = e.dataTransfer?.files[0]; if (file) setFile(file) }

function setFile(file: File) {
  const ext = file.name.split('.').pop()?.toLowerCase()
  if (!['csv', 'xlsx', 'xls'].includes(ext ?? '')) { alert(t('leave_import.only_csv_xlsx')); return }
  uploadedFile.value = file
}

function removeFile() { uploadedFile.value = null; tempId.value = null; validRows.value = []; errorRows.value = [] }

function reset() {
  step.value = 'select'; selectedType.value = null; uploadedFile.value = null; tempId.value = null
  validRows.value = []; errorRows.value = []; importResult.value = null; rollbackMsg.value = null
}

async function runPreview() {
  if (!uploadedFile.value || !selectedType.value) return
  previewing.value = true
  try {
    const form = new FormData(); form.append('file', uploadedFile.value); form.append('type', selectedType.value.key)
    const { data } = await axios.post('/leave-management/import/preview', form, {
      headers: { 'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '' },
    })
    tempId.value = data.temp_id; validRows.value = data.valid_rows; errorRows.value = data.error_rows
    previewCols.value = data.columns; previewTab.value = data.valid_count > 0 ? 'valid' : 'errors'; step.value = 'preview'
  } catch (e: any) { alert(e.response?.data?.message ?? t('leave_import.preview_failed')) }
  finally { previewing.value = false }
}

async function runImport() {
  if (!tempId.value || !selectedType.value) return
  importing.value = true
  try {
    const csrf = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? ''
    const { data } = await axios.post('/leave-management/import/run', { temp_id: tempId.value, type: selectedType.value.key }, { headers: { 'X-CSRF-TOKEN': csrf } })
    importResult.value = data; step.value = 'summary'
  } catch (e: any) { alert(e.response?.data?.error ?? t('leave_import.import_failed')) }
  finally { importing.value = false }
}

// ── Rollback ─────────────────────────────────────────────────────────────────
function runRollback() {
  if (!importResult.value?.batch_id) return
  confirmDialog.value.open(
    { title: 'Rollback import?', message: 'This will delete all records created in this import. Are you sure?', confirmText: 'Rollback', confirmColor: 'error', icon: 'tabler-trash' },
    async () => {
      rollingBack.value = true

      try {
        const csrf = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? ''
        const { data } = await axios.post('/leave-management/import/rollback', {
          batch_id: importResult.value!.batch_id,
        }, { headers: { 'X-CSRF-TOKEN': csrf } })
        rollbackMsg.value = data.message
        importResult.value = null
      } catch (e: any) {
        alert(e.response?.data?.error ?? 'Rollback failed.')
      } finally {
        rollingBack.value = false
      }
    },
  )
}

function downloadErrors(rows: Record<string, any>[]) {
  const columns = selectedType.value?.columns ?? previewCols.value
  const header  = [...columns, '_row', '_errors'].join(',')
  const lines   = rows.map(row => {
    const vals = columns.map(c => `"${(row[c] ?? '').toString().replace(/"/g, '""')}"`)
    const errs = Array.isArray(row._errors) ? row._errors.join('; ') : (row._errors ?? '')
    return [...vals, row._row ?? '', `"${errs.replace(/"/g, '""')}"`].join(',')
  })
  const csv  = [header, ...lines].join('\n')
  const blob = new Blob([csv], { type: 'text/csv' }); const url = URL.createObjectURL(blob)
  const a = document.createElement('a'); a.href = url; a.download = 'import_errors.csv'; a.click(); URL.revokeObjectURL(url)
}

function templateUrl(key: string) { return `/leave-management/import/template/${key}` }
</script>

<template>
  <VContainer fluid class="pa-6" style="max-width: 1100px;">
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h1 class="text-h5 font-weight-bold">{{ t('leave.data_import_wizard') }}</h1>
        <p class="text-body-2 text-medium-emphasis mt-1">{{ t('leave_import.subtitle') }}</p>
      </div>
      <VBtn v-if="step !== 'select'" variant="text" prepend-icon="ri-arrow-left-line" @click="reset">{{ t('leave_import.start_over') }}</VBtn>
    </div>

    <div class="d-flex align-center gap-2 mb-8">
      <template v-for="(s, i) in [
        { key: 'select', label: t('leave_import.select_type_label') },
        { key: 'upload', label: t('leave_import.upload_file_label') },
        { key: 'preview', label: t('leave_import.preview_validate_label') },
        { key: 'summary', label: t('leave_import.summary_label') },
      ]" :key="s.key">
        <div class="d-flex align-center gap-2">
          <div class="d-flex align-center justify-center rounded-circle text-caption font-weight-bold"
            :style="`width:28px;height:28px;background:${['select','upload','preview','summary'].indexOf(step) >= i ? 'rgb(var(--v-theme-primary))' : 'rgba(0,0,0,0.12)'};color:${['select','upload','preview','summary'].indexOf(step) >= i ? 'white' : 'inherit'}`"
          >{{ i + 1 }}</div>
          <span class="text-caption" :class="step === s.key ? 'font-weight-bold' : 'text-medium-emphasis'">{{ s.label }}</span>
        </div>
        <div v-if="i < 3" class="flex-grow-1 border-b border-dashed" style="height:1px;opacity:0.3;"></div>
      </template>
    </div>

    <template v-if="step === 'select'">
      <VRow>
        <VCol v-for="t in props.importTypes" :key="t.key" cols="12" sm="6">
          <VCard variant="outlined" class="cursor-pointer h-100 transition-all" @click="selectType(t)">
            <VCardText class="pa-5">
              <div class="d-flex align-start gap-4">
                <VAvatar :color="t.color" variant="tonal" size="48" rounded><VIcon size="24">{{ t.icon }}</VIcon></VAvatar>
                <div class="flex-grow-1">
                  <p class="text-body-1 font-weight-semibold">{{ t.label }}</p>
                  <p class="text-body-2 text-medium-emphasis mt-1">{{ t.description }}</p>
                  <div class="d-flex flex-wrap gap-1 mt-3"><VChip v-for="col in t.columns" :key="col" size="x-small" variant="tonal" :color="t.color">{{ col }}</VChip></div>
                </div>
                <VIcon color="medium-emphasis">ri-arrow-right-s-line</VIcon>
              </div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </template>

    <template v-if="step === 'upload' && selectedType">
      <VCard variant="outlined" class="mb-4">
        <VCardText class="pa-5">
          <div class="d-flex align-center gap-3 mb-4">
            <VAvatar :color="selectedType.color" variant="tonal" size="40" rounded><VIcon size="22">{{ selectedType.icon }}</VIcon></VAvatar>
            <div>
              <p class="text-body-1 font-weight-semibold">{{ selectedType.label }}</p>
              <p class="text-caption text-medium-emphasis">{{ t('leave_import.required_columns', { columns: selectedType.columns.join(', ') }) }}</p>
            </div>
            <VSpacer />
            <VBtn variant="tonal" size="small" :color="selectedType.color" prepend-icon="ri-download-line" :href="templateUrl(selectedType.key)" download>{{ t('leave.download_template') }}</VBtn>
          </div>
          <div class="rounded-lg d-flex flex-column align-center justify-center pa-10 cursor-pointer"
            style="border: 2px dashed rgba(0,0,0,0.2); min-height: 200px;"
            :class="{ 'bg-primary-lighten-5': dragging }"
            @dragover.prevent="dragging = true" @dragleave="dragging = false" @drop.prevent="onDrop"
            @click="($refs.fileInput as HTMLInputElement).click()">
            <input ref="fileInput" type="file" accept=".csv,.xlsx,.xls" class="d-none" @change="onFileChange" />
            <VIcon size="48" color="medium-emphasis" class="mb-3">ri-upload-cloud-2-line</VIcon>
            <p class="text-body-1 font-weight-medium">{{ t('leave_import.drop_file_text') }}</p>
            <p class="text-caption text-medium-emphasis mt-1">{{ t('leave_import.file_format_hint') }}</p>
          </div>
          <template v-if="uploadedFile">
            <div class="d-flex align-center gap-3 mt-4 pa-3 rounded-lg bg-surface-variant">
              <VIcon color="primary">ri-file-excel-2-line</VIcon>
              <div class="flex-grow-1">
                <p class="text-body-2 font-weight-medium">{{ uploadedFile.name }}</p>
                <p class="text-caption text-medium-emphasis">{{ t('leave_import.file_size_kb', { size: (uploadedFile.size / 1024).toFixed(1) }) }}</p>
              </div>
              <VBtn icon variant="text" size="small" @click="removeFile"><VIcon size="18">ri-close-line</VIcon></VBtn>
            </div>
            <div class="d-flex justify-end mt-4">
              <VBtn :color="selectedType.color" :loading="previewing" prepend-icon="ri-eye-line" @click="runPreview">{{ t('leave.preview_validate') }}</VBtn>
            </div>
          </template>
        </VCardText>
      </VCard>
    </template>

    <template v-if="step === 'preview' && selectedType">
      <div class="d-flex gap-3 mb-4">
        <VCard variant="tonal" color="success" class="flex-grow-1">
          <VCardText class="pa-4 d-flex align-center gap-3">
            <VIcon color="success" size="28">ri-checkbox-circle-line</VIcon>
            <div><p class="text-h5 font-weight-bold">{{ validRows.length }}</p><p class="text-caption">{{ t('leave_import.valid_rows_ready') }}</p></div>
          </VCardText>
        </VCard>
        <VCard variant="tonal" :color="errorRows.length ? 'error' : 'success'" class="flex-grow-1">
          <VCardText class="pa-4 d-flex align-center gap-3">
            <VIcon :color="errorRows.length ? 'error' : 'success'" size="28">{{ errorRows.length ? 'ri-close-circle-line' : 'ri-checkbox-circle-line' }}</VIcon>
            <div><p class="text-h5 font-weight-bold">{{ errorRows.length }}</p><p class="text-caption">{{ t('leave_import.rows_with_errors') }}</p></div>
          </VCardText>
        </VCard>
      </div>

      <VCard variant="outlined" class="mb-4">
        <div class="d-flex align-center px-4 pt-3 pb-0 gap-4">
          <VBtn :variant="previewTab === 'valid' ? 'tonal' : 'text'" color="success" size="small" @click="previewTab = 'valid'">{{ t('leave_import.valid_rows_tab', { count: validRows.length }) }}</VBtn>
          <VBtn :variant="previewTab === 'errors' ? 'tonal' : 'text'" color="error" size="small" @click="previewTab = 'errors'">{{ t('leave_import.errors_tab', { count: errorRows.length }) }}</VBtn>
          <VSpacer />
          <VBtn v-if="errorRows.length && previewTab === 'errors'" variant="text" size="small" color="error" prepend-icon="ri-download-line" @click="downloadErrors(errorRows)">{{ t('leave.download_errors') }}</VBtn>
        </div>

        <VDataTable v-if="previewTab === 'valid'" :headers="validHeaders" :items="validRows" :items-per-page="20" density="compact" hover>
          <template #no-data><div class="pa-6 text-center text-medium-emphasis">{{ t('leave_import.no_valid_rows') }}</div></template>
        </VDataTable>

        <VDataTable v-else :headers="errorHeaders" :items="errorRows" :items-per-page="20" density="compact" hover>
          <template #item._errors="{ item }"><ul class="ma-0 pa-0" style="list-style:none;"><li v-for="(err, i) in (Array.isArray(item._errors) ? item._errors : [item._errors])" :key="i" class="text-error text-caption">{{ err }}</li></ul></template>
          <template #no-data><div class="pa-6 text-center text-success">{{ t('leave_import.all_rows_valid') }}</div></template>
        </VDataTable>
      </VCard>

      <div class="d-flex justify-end gap-3">
        <VBtn variant="outlined" @click="step = 'upload'">{{ t('leave_import.back') }}</VBtn>
        <VBtn :color="selectedType.color" :disabled="validRows.length === 0" :loading="importing" prepend-icon="ri-upload-2-line" @click="runImport">{{ t('leave_import.import_rows', { count: validRows.length }) }}</VBtn>
      </div>
    </template>

    <template v-if="step === 'summary'">
      <VCard variant="outlined" class="mb-6">
        <VCardText class="pa-6">
          <template v-if="rollbackMsg">
            <div class="d-flex align-center gap-3 pa-4 rounded-lg bg-warning-lighten-5 mb-4"><VIcon color="warning">ri-refresh-line</VIcon><p class="text-body-2">{{ rollbackMsg }}</p></div>
            <VBtn color="primary" @click="reset">{{ t('leave.start_new_import') }}</VBtn>
          </template>
          <template v-else-if="importResult">
            <div class="text-center mb-6">
              <VIcon size="64" color="success" class="mb-3">ri-checkbox-circle-line</VIcon>
              <h2 class="text-h5 font-weight-bold">{{ t('leave.import_complete') }}</h2>
              <p class="text-body-2 text-medium-emphasis mt-1">{{ t('leave_import.batch_id_label') }} <code>{{ importResult.batch_id }}</code></p>
            </div>
            <VRow class="mb-6">
              <VCol cols="6"><VCard variant="tonal" color="success"><VCardText class="pa-4 text-center"><p class="text-h4 font-weight-bold">{{ importResult.imported }}</p><p class="text-caption mt-1">{{ t('leave_import.records_imported') }}</p></VCardText></VCard></VCol>
              <VCol cols="6"><VCard variant="tonal" :color="importResult.error_count ? 'error' : 'success'"><VCardText class="pa-4 text-center"><p class="text-h4 font-weight-bold">{{ importResult.error_count }}</p><p class="text-caption mt-1">{{ t('leave_import.rows_skipped') }}</p></VCardText></VCard></VCol>
            </VRow>
            <template v-if="importResult.errors.length">
              <div class="d-flex align-center justify-space-between mb-2">
                <p class="text-body-2 font-weight-medium">{{ t('leave_import.skipped_rows') }}</p>
                <VBtn variant="text" size="small" color="error" prepend-icon="ri-download-line" @click="downloadErrors(importResult.errors)">{{ t('leave_import.download_errors_csv') }}</VBtn>
              </div>
              <VDataTable :headers="errorHeaders" :items="importResult.errors" :items-per-page="10" density="compact" class="mb-4">
                <template #item._errors="{ item }"><span class="text-error text-caption">{{ Array.isArray(item._errors) ? item._errors.join('; ') : item._errors }}</span></template>
              </VDataTable>
            </template>
            <VDivider class="my-4" />
            <div class="d-flex justify-space-between align-center">
              <VBtn variant="outlined" color="warning" prepend-icon="ri-arrow-go-back-line" :loading="rollingBack" @click="runRollback">{{ t('leave.rollback_import') }}</VBtn>
              <VBtn color="primary" @click="reset">{{ t('leave.import_more') }}</VBtn>
            </div>
          </template>

        </VCardText>
      </VCard>
    </template>
    <ConfirmDialog ref="confirmDialog" />
  </VContainer>
</template>
