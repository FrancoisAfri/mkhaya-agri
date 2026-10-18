<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useManagerUserStore } from '../Store/ManagerUser'
import { router } from '@inertiajs/vue3'

const { t } = useI18n()
const store    = useManagerUserStore()
const fileRef  = ref<HTMLInputElement | null>(null)
const file     = ref<File | null>(null)
const dragOver = ref(false)
const nonLogin = ref(false) // import as non-login employees (no email/user account)

const onFileChange = (e: Event) => {
  const f = (e.target as HTMLInputElement).files?.[0]
  if (f) file.value = f
}

const onDrop = (e: DragEvent) => {
  dragOver.value = false
  const f = e.dataTransfer?.files?.[0]
  if (f && isValidFile(f)) file.value = f
}

const isValidFile = (f: File) =>
  ['application/vnd.ms-excel',
   'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
   'text/csv'].includes(f.type) ||
  f.name.match(/\.(xlsx|xls|csv)$/i) !== null

const removeFile = () => {
  file.value = null
  if (fileRef.value) fileRef.value.value = ''
}

const onSubmit = async () => {
  if (!file.value) return

  store.importLoading = true
  store.importError   = ''
  store.importSuccess = false

  const formData = new FormData()
  formData.append('import_file', file.value)
  if (nonLogin.value) formData.append('non_login', '1')

  const csrf = (document.querySelector('meta[name=csrf-token]') as HTMLMetaElement)?.content

  try {
    const res = await fetch('/settings/users-import', {
      method:  'POST',
      headers: {
        'X-CSRF-TOKEN':     csrf,
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: formData,
    })

    if (res.ok) {
      store.importSuccess = true
      store.importLoading = false
      file.value          = null
      store.showSnack('Users imported successfully', 'success')
      setTimeout(() => {
        router.reload({ only: ['users'] })
        store.closeImport()
      }, 1500)
    } else {
      const data = await res.json().catch(() => ({}))
      store.importError   = data?.message ?? 'Import failed. Please check your file.'
      store.importLoading = false
    }
  } catch {
    store.importError   = 'Network error. Please try again.'
    store.importLoading = false
  }
}
</script>

<template>
  <VDialog
    :model-value="store.isImportOpen"
    max-width="520"
    scrollable
    @update:model-value="store.closeImport"
  >
    <VCard rounded="lg" elevation="0">

      <div
        class="d-flex align-center justify-space-between px-6 py-4 flex-shrink-0"
        style="border-bottom:1px solid rgba(var(--v-border-color),var(--v-border-opacity))"
      >
        <div class="d-flex align-center gap-3 min-w-0 flex-grow-1">
          <div
            class="d-flex align-center justify-center rounded-lg flex-shrink-0"
            style="width:40px;height:40px;background:#dcfce7"
          >
            <i class="fa-solid fa-file-import" style="color:#16a34a;font-size:16px" />
          </div>
          <div class="min-w-0">
            <p class="text-body-1 font-weight-semibold mb-0">{{ t('manager_user_page.bulk_import_users') }}</p>
            <p class="text-caption text-medium-emphasis mb-0">{{ t('manager_user_page.bulk_import_sub') }}</p>
          </div>
        </div>
        <VBtn icon="tabler-x" variant="text" size="small" class="flex-shrink-0" @click="store.closeImport" />
      </div>

      <VCardText class="pa-6" style="overflow-y:auto;max-height:62vh">

        <div v-if="store.importSuccess" class="success-state">
          <div class="success-icon">
            <i class="fa-solid fa-circle-check" style="color:#16a34a;font-size:36px" />
          </div>
          <p class="text-body-1 font-weight-semibold text-center mb-1">{{ t('manager_user_page.import_success') }}</p>
          <p class="text-body-2 text-medium-emphasis text-center">
            {{ t('manager_user_page.import_success_sub') }}
          </p>
        </div>

        <div v-else>

          <div class="form-block mb-4">
            <p class="block-label">{{ t('manager_user_page.how_it_works') }}</p>
            <ol class="instructions-list">
              <li>
                {{ t('manager_user_page.instruction_1') }}
                <a href="/templates/user-import-template.xlsx" download class="dl-link">
                  <i class="fa-solid fa-download me-1" /> {{ t('manager_user_page.instruction_1_link') }}
                </a>
              </li>
              <li>{{ t('manager_user_page.instruction_2') }}</li>
              <li>{{ t('manager_user_page.instruction_3') }}</li>
              <li>{{ t('manager_user_page.instruction_4') }}</li>
              <li>{{ t('manager_user_page.instruction_5') }}</li>
            </ol>
          </div>

          <div class="form-block">
            <p class="block-label">{{ t('manager_user_page.select_file_label') }}</p>

            <div
              class="dropzone"
              :class="{ 'dropzone--over': dragOver, 'dropzone--filled': !!file }"
              @click="fileRef?.click()"
              @dragover.prevent="dragOver = true"
              @dragleave.prevent="dragOver = false"
              @drop.prevent="onDrop"
            >
              <template v-if="!file">
                <i class="fa-solid fa-cloud-arrow-up dropzone-icon" />
                <p class="dropzone-text">{{ t('manager_user_page.drag_drop_text') }} <span class="text-primary">{{ t('manager_user_page.drag_drop_browse') }}</span></p>
                <p class="dropzone-hint">{{ t('manager_user_page.supported_formats') }}</p>
              </template>

              <template v-else>
                <div class="file-preview">
                  <i class="fa-solid fa-file-excel" style="color:#16a34a;font-size:28px" />
                  <div class="file-info">
                    <p class="file-name">{{ file.name }}</p>
                    <p class="file-size">{{ (file.size / 1024).toFixed(1) }} KB</p>
                  </div>
                  <button type="button" class="file-remove" @click.stop="removeFile">
                    <i class="fa-solid fa-xmark" />
                  </button>
                </div>
              </template>
            </div>

            <input
              ref="fileRef"
              type="file"
              accept=".xlsx,.xls,.csv"
              style="display:none"
              @change="onFileChange"
            />

            <div v-if="store.importError" class="error-banner mt-3">
              <i class="fa-solid fa-circle-exclamation me-2" />
              {{ store.importError }}
            </div>

          </div>

        </div>
        <VCheckbox
          v-model="nonLogin"
          density="compact"
          hide-details
          class="mt-2"
          label="Import as non-login employees (no email / login — e.g. cleaners)"
        />
      </VCardText>

      <div
        v-if="!store.importSuccess"
        class="d-flex gap-3 px-6 py-4 flex-shrink-0"
        style="border-top:1px solid rgba(var(--v-border-color),var(--v-border-opacity))"
      >
        <VBtn
          variant="tonal"
          color="error"
          class="flex-grow-1"
          rounded="lg"
          :disabled="store.importLoading"
          @click="store.closeImport"
        >
          <i class="fa-regular fa-x me-2" /> {{ t('cancel') }}
        </VBtn>
        <VBtn
          color="success"
          class="flex-grow-1"
          rounded="lg"
          :loading="store.importLoading"
          :disabled="!file || store.importLoading"
          @click="onSubmit"
        >
          <template #loader>
            <VProgressCircular indeterminate size="18" width="2" color="white" class="me-2" />
            <span>{{ t('manager_user_page.importing') }}</span>
          </template>
          <i class="fa-solid fa-file-import me-2" /> {{ t('manager_user_page.import_btn') }}
        </VBtn>
      </div>

      <div v-else class="d-flex px-6 py-4 flex-shrink-0"
        style="border-top:1px solid rgba(var(--v-border-color),var(--v-border-opacity))">
        <VBtn variant="tonal" class="flex-grow-1" rounded="lg" @click="store.closeImport">
          {{ t('manager_user_page.close') }}
        </VBtn>
      </div>

    </VCard>
  </VDialog>
</template>

<style scoped>
.form-block {
  background: rgba(var(--v-border-color), 0.04);
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 12px; padding: 16px;
}
.block-label {
  font-size: 11px; font-weight: 700; letter-spacing: .6px;
  color: rgba(var(--v-theme-on-surface), 0.5);
  text-transform: uppercase; margin: 0 0 12px;
}

.instructions-list {
  margin: 0; padding-left: 18px;
  font-size: 13px; color: rgba(var(--v-theme-on-surface), .7);
  line-height: 2;
}
.dl-link {
  color: #6366f1; font-weight: 600; text-decoration: none;
}
.dl-link:hover { text-decoration: underline; }

.dropzone {
  border: 2px dashed #cbd5e1;
  border-radius: 12px;
  background: repeating-linear-gradient(-45deg, #f8fafc, #f8fafc 10px, #f1f5f9 10px, #f1f5f9 20px);
  min-height: 130px;
  cursor: pointer;
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  gap: 6px; padding: 20px;
  transition: border-color .2s, background .2s;
}
.dropzone:hover, .dropzone--over {
  border-color: #6366f1;
  background: #eef2ff;
}
.dropzone--filled {
  border-style: solid; border-color: #16a34a;
  background: #f0fdf4; min-height: 80px;
}
.dropzone-icon { font-size: 32px; color: #cbd5e1; }
.dropzone-text { font-size: 13px; color: #64748b; margin: 0; }
.dropzone-hint { font-size: 11px; color: #94a3b8; margin: 0; }

.file-preview {
  display: flex; align-items: center; gap: 12px; width: 100%;
}
.file-info { flex: 1; min-width: 0; }
.file-name  { font-size: 13px; font-weight: 600; color: #1e293b; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.file-size  { font-size: 11px; color: #64748b; margin: 0; }
.file-remove {
  width: 28px; height: 28px; border-radius: 7px;
  border: none; background: #fee2e2; color: #ef4444;
  cursor: pointer; display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; transition: background .15s;
}
.file-remove:hover { background: #fecaca; }

.error-banner {
  display: flex; align-items: flex-start; gap: 8px;
  padding: 10px 14px; background: #fef2f2;
  border: 1px solid #fecaca; border-radius: 8px;
  font-size: 12px; color: #dc2626;
}

.success-state {
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  padding: 40px 20px; gap: 12px;
}
.success-icon { animation: pop .3s ease; }
@keyframes pop {
  0%   { transform: scale(0.5); opacity: 0; }
  100% { transform: scale(1);   opacity: 1; }
}
</style>
