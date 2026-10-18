<script setup lang="ts">
import { watch, nextTick, computed, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useI18n } from 'vue-i18n'
import { useResourceForm, getUnitLevelConfig } from './Useresourceform'
import { getResourceTypeConfig, type CompanyUnit, type Resource } from '../Store/type'

const { t } = useI18n()

const props = withDefaults(defineProps<{
  companyUnits?: CompanyUnit[]
  mode:          'add' | 'edit'
  resourceType:  number
  editing?:      Resource | null
  isLoading?:    boolean
  headerTitle:   string
  headerSubtitle:string
}>(), {
  companyUnits: () => [],
  editing:      null,
  isLoading:    false,
})

const emit  = defineEmits<{ (e: 'submit', form: FormData): void }>()
const model = defineModel<boolean>()
const page  = usePage()

const errors = computed(() => page.props.errors as Record<string, string>)
const cfg    = computed(() => getResourceTypeConfig(props.resourceType))

const fileInputNew     = ref<HTMLInputElement | null>(null)
const fileInputReplace = ref<HTMLInputElement | null>(null)

const {
  title, description, type, internal_only, is_active, published_at, expired_at,
  docFile, docName, levels, selectedUnitPath,
  onLevelSelect, handleFile, removeFile, reset, fillFromResource, buildFormData,
} = useResourceForm(() => props.companyUnits)

watch(() => props.resourceType, (t) => { type.value = t }, { immediate: true })

watch(() => props.editing, (val) => {
  if (val && props.mode === 'edit') fillFromResource(val)
}, { immediate: true })

watch(model, (open) => { if (!open && !props.isLoading) nextTick(() => reset()) })

function submit() {
  type.value = props.resourceType
  emit('submit', buildFormData(props.mode))
}

function openFilePicker() {
  fileInputNew.value?.click()
}

function openReplacePicker() {
  fileInputReplace.value?.click()
}

function onFileChange(e: Event) {
  handleFile(e)
  ;(e.target as HTMLInputElement).value = ''
}
</script>

<template>
  <VDialog v-model="model" max-width="680" persistent scrollable>
    <VCard elevation="0">

      <div
        class="d-flex align-center justify-space-between px-6 py-4 flex-shrink-0"
        style="border-bottom:1px solid rgba(var(--v-border-color),var(--v-border-opacity))"
      >
        <div class="d-flex align-center gap-3">
          <div
            class="d-flex align-center justify-center rounded-lg flex-shrink-0"
            :style="{ width:'40px', height:'40px', background: cfg.bg }"
          >
            <VIcon :icon="cfg.icon" :color="cfg.color" size="20" />
          </div>
          <div>
            <p class="text-body-1 font-weight-semibold mb-0">{{ headerTitle }}</p>
            <p class="text-caption text-medium-emphasis mb-0">{{ headerSubtitle }}</p>
          </div>
        </div>
        <VBtn icon="tabler-x" variant="text" size="small" :disabled="isLoading" @click="model = false" />
      </div>

      <VCardText class="pa-6" style="overflow-y:auto;max-height:65vh">
        <VRow>

          <VCol cols="12">
            <VTextField
              v-model="title"
              :label="t('resource_library.form.title_label')"
              variant="outlined"
              density="compact"
              prepend-inner-icon="tabler-text-size"
              :error-messages="errors.title"
              :disabled="isLoading"
              :placeholder="t('resource_library.form.title_placeholder')"
            />
          </VCol>

          <VCol cols="12">
            <VTextarea
              v-model="description"
              :label="t('resource_library.form.description_label')"
              variant="outlined"
              density="compact"
              rows="3"
              auto-grow
              :placeholder="t('resource_library.form.description_placeholder')"
              :disabled="isLoading"
              :error-messages="errors.description"
            />
          </VCol>

          <VCol cols="12">
            <p class="text-caption font-weight-semibold text-medium-emphasis text-uppercase mb-2"
              style="letter-spacing:.5px">
              {{ t('resource_library.form.file_section') }}
            </p>

            <template v-if="!docName">
              <input
                ref="fileInputNew"
                type="file"
                accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.rar,.csv,.txt"
                class="sr-only"
                :disabled="isLoading"
                @change="onFileChange"
              />

              <div
                class="res-drop d-flex flex-column align-center justify-center gap-2 rounded-lg pa-6"
                :style="{ opacity: isLoading ? .5 : 1, cursor: isLoading ? 'not-allowed' : 'pointer' }"
                @click="openFilePicker"
              >
                <VIcon icon="tabler-file-upload" size="40" style="opacity:.25" />
                <p class="text-caption text-medium-emphasis mb-0 text-center">
                  {{ t('resource_library.form.file_formats') }}
                </p>
                <VBtn
                  size="small"
                  variant="tonal"
                  :color="cfg.color"
                  :disabled="isLoading"
                  @click.stop="openFilePicker"
                >
                  {{ t('resource_library.form.browse_file') }}
                </VBtn>
              </div>
            </template>

            <template v-else>
              <input
                ref="fileInputReplace"
                type="file"
                accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.rar,.csv,.txt"
                class="sr-only"
                :disabled="isLoading"
                @change="onFileChange"
              />

              <VCard variant="outlined" class="pa-4">
                <div class="d-flex align-center gap-3">
                  <div
                    class="d-flex align-center justify-center rounded-lg flex-shrink-0"
                    :style="{ width:'40px', height:'40px', background: cfg.bg }"
                  >
                    <VIcon icon="tabler-file-check" :color="cfg.color" size="20" />
                  </div>

                  <div style="flex:1;min-width:0">
                    <p class="text-body-2 font-weight-semibold mb-0 text-truncate">{{ docName }}</p>
                    <p class="text-caption text-success mb-0">
                      <VIcon icon="tabler-circle-check" size="12" class="me-1" />
                      {{ t('resource_library.form.ready_to_upload') }}
                    </p>
                  </div>

                  <div class="d-flex gap-1">
                    <VBtn size="small" variant="tonal" color="primary" icon
                      :disabled="isLoading" @click="openReplacePicker">
                      <VIcon icon="tabler-pencil" size="14" />
                    </VBtn>
                    <VBtn size="small" variant="tonal" color="error" icon
                      :disabled="isLoading" @click="removeFile">
                      <VIcon icon="tabler-trash" size="14" />
                    </VBtn>
                  </div>
                </div>
              </VCard>
            </template>
          </VCol>

          <VCol cols="12">
            <p class="text-caption font-weight-semibold text-medium-emphasis text-uppercase mb-2"
              style="letter-spacing:.5px">
              {{ t('resource_library.form.unit_section') }}
              <span class="text-lowercase font-weight-regular">{{ t('resource_library.form.unit_optional') }}</span>
            </p>

            <template v-if="levels.length > 0">
              <div v-for="(level, idx) in levels" :key="idx" class="mb-2">
                <div class="d-flex align-center gap-2 mb-1">
                  <VIcon v-if="idx > 0" icon="tabler-corner-down-right" size="12" color="medium-emphasis" />
                  <span class="text-caption font-weight-medium"
                    :style="{ color: getUnitLevelConfig(level.children[0]?.type).color }">
                    {{ getUnitLevelConfig(level.children[0]?.type).label }}
                  </span>
                </div>
                <VSelect
                  :model-value="level.selectedId"
                  :items="level.children"
                  item-title="name"
                  item-value="id"
                  :label="t('resource_library.form.select_unit', { level: getUnitLevelConfig(level.children[0]?.type).label })"
                  :prepend-inner-icon="getUnitLevelConfig(level.children[0]?.type).icon"
                  variant="outlined"
                  density="compact"
                  clearable
                  :disabled="isLoading"
                  @update:model-value="onLevelSelect(idx, $event)"
                />
              </div>
            </template>

            <p v-if="selectedUnitPath" class="text-caption text-primary mt-1">
              <VIcon icon="tabler-sitemap" size="12" class="me-1" />{{ selectedUnitPath }}
            </p>
          </VCol>

          <VCol cols="12" md="6">
            <VTextField
              v-model="published_at"
              :label="t('resource_library.form.published_at')"
              type="datetime-local"
              variant="outlined"
              density="compact"
              prepend-inner-icon="tabler-calendar-check"
              :error-messages="errors.published_at"
              :disabled="isLoading"
            />
          </VCol>
          <VCol cols="12" md="6">
            <VTextField
              v-model="expired_at"
              :label="t('resource_library.form.expiration_date')"
              type="datetime-local"
              variant="outlined"
              density="compact"
              prepend-inner-icon="tabler-calendar-clock"
              :error-messages="errors.expired_at"
              :disabled="isLoading"
            />
          </VCol>

          <VCol cols="12" class="d-flex gap-6 flex-wrap">
            <VSwitch
              v-model="internal_only"
              color="warning"
              density="compact"
              hide-details
              inset
              :disabled="isLoading"
            >
              <template #label>
                <span class="text-body-2 d-flex align-center gap-1">
                  <VIcon icon="tabler-lock" size="14" color="warning" />{{ t('resource_library.form.internal_only') }}
                </span>
              </template>
            </VSwitch>

            <VSwitch
              v-model="is_active"
              color="success"
              density="compact"
              hide-details
              inset
              :disabled="isLoading"
            >
              <template #label>
                <span class="text-body-2 d-flex align-center gap-1">
                  <VIcon icon="tabler-circle-check" size="14" color="success" />{{ t('resource_library.form.active') }}
                </span>
              </template>
            </VSwitch>
          </VCol>

        </VRow>
      </VCardText>

      <div
        class="d-flex align-center gap-3 pa-6 flex-shrink-0"
        style="border-top:1px solid rgba(var(--v-border-color),var(--v-border-opacity))"
      >
        <VBtn variant="tonal" color="secondary" :disabled="isLoading" @click="model = false">
          {{ t('resource_library.form.cancel') }}
        </VBtn>
        <VSpacer />
        <VBtn
          :style="{ background: cfg.gradient, color: '#fff' }"
          :loading="isLoading"
          :disabled="!title.trim()"
          elevation="0"
          @click="submit"
        >
          <template #loader>
            <VProgressCircular indeterminate size="16" width="2" color="white" class="me-2" />
            <span>{{ t('resource_library.form.saving') }}</span>
          </template>
          <VIcon
            :icon="mode === 'edit' ? 'tabler-device-floppy' : 'tabler-upload'"
            size="16"
            class="me-2"
          />
          {{ mode === 'edit' ? t('resource_library.form.save_changes') : t('resource_library.form.publish_resource') }}
        </VBtn>
      </div>

    </VCard>
  </VDialog>
</template>

<style scoped>
.res-drop {
  border: 2px dashed rgba(var(--v-border-color), 0.4);
  border-radius: 12px;
  transition: border-color .2s, background .15s;
  min-height: 120px;
}
.res-drop:hover {
  border-color: rgb(var(--v-theme-primary));
  background: rgba(var(--v-theme-primary), .02);
}
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
}
</style>
