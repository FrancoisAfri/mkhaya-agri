<script setup lang="ts">
import { ref, watch, computed, onMounted, onUnmounted } from 'vue'
import type { RawCeoMessage } from './Store/type'
import { useCeoMessageStore } from './Store/store'
import ProductDescriptionEditor from '@/@core/components/ProductDescriptionEditor.vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  ceo: RawCeoMessage | RawCeoMessage[] | null
}>()

const store = useCeoMessageStore()

watch(() => props.ceo, val => store.setCeoMessage(val), { immediate: true, deep: true })

onMounted(() => store.startPolling(30))
onUnmounted(() => store.stopPolling())

const message      = ref('Welcome to our company. We are committed to excellence, innovation, and the well-being of every team member.')
const imageFile    = ref<File | null>(null)
const videoFile    = ref<File | null>(null)
const imagePreview = ref<string | null>(null)
const videoPreview = ref<string | null>(null)
const imageDragging = ref(false)
const videoDragging = ref(false)

watch(
  () => store.ceoMessage,
  val => {
    if (!val) return
    message.value      = val.message   ?? message.value
    imagePreview.value = val.image_url ?? null
    videoPreview.value = val.video_url ?? null
  },
  { immediate: true, deep: true },
)

const isEditing    = computed(() => !!store.ceoMessage?.id)
const hasExistingImg = computed(() => !!store.ceoMessage?.image_url)
const hasExistingVid = computed(() => !!store.ceoMessage?.video_url)
const isLoading    = computed(() => store.isLoading)

const IMAGE_FORMATS = ['image/jpeg', 'image/png', 'image/webp']
const IMAGE_ACCEPT  = '.jpg,.jpeg,.png,.webp'
const IMAGE_MAX_MB  = 5
const VIDEO_MAX_MB  = 100

function handleImageFile(file: File) {
  if (!IMAGE_FORMATS.includes(file.type)) {
    store.showSnack(t('ceo_message.snackbar.invalid_image_format'), 'error'); return
  }
  if (file.size > IMAGE_MAX_MB * 1024 * 1024) {
    store.showSnack(t('ceo_message.snackbar.image_too_large', { size: IMAGE_MAX_MB }), 'error'); return
  }
  imageFile.value    = file
  videoFile.value    = null
  videoPreview.value = null
  imagePreview.value = URL.createObjectURL(file)
}

function onImageInput(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (file) handleImageFile(file)
}
function onImageDrop(e: DragEvent) {
  imageDragging.value = false
  const file = e.dataTransfer?.files?.[0]
  if (file) handleImageFile(file)
}
function removeImage() { imageFile.value = null; imagePreview.value = null }

function handleVideoFile(file: File) {
  if (!file.type.startsWith('video/')) {
    store.showSnack(t('ceo_message.snackbar.invalid_video'), 'error'); return
  }
  if (file.size > VIDEO_MAX_MB * 1024 * 1024) {
    store.showSnack(t('ceo_message.snackbar.video_too_large', { size: VIDEO_MAX_MB }), 'error'); return
  }
  videoFile.value    = file
  imageFile.value    = null
  imagePreview.value = null
  videoPreview.value = URL.createObjectURL(file)
}

function onVideoInput(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (file) handleVideoFile(file)
}
function onVideoDrop(e: DragEvent) {
  videoDragging.value = false
  const file = e.dataTransfer?.files?.[0]
  if (file) handleVideoFile(file)
}
function removeVideo() { videoFile.value = null; videoPreview.value = null }

function handleSubmit() {
  if (!imagePreview.value && !videoPreview.value && !imageFile.value && !videoFile.value) {
    store.showSnack(t('ceo_message.snackbar.no_media'), 'error')
    return
  }
  store.submit({ message: message.value, image: imageFile.value, video: videoFile.value })
}
</script>

<template>
  <VContainer fluid class="pa-4 pa-sm-6">

    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <div class="text-overline text-medium-emphasis">{{ t('ceo_message.page_header.overline') }}</div>
        <h4 class="text-h4">{{ t('ceo_message.page_header.title') }}</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">{{ t('ceo_message.page_header.subtitle') }}</p>
      </div>

      <div class="d-flex align-center gap-2">
        <VChip
          v-if="isEditing"
          color="primary"
          variant="tonal"
          prepend-icon="tabler-pencil"
          size="small"
        >
          {{ t('ceo_message.editing') }}
        </VChip>
        <VBtn
          color="primary"
          prepend-icon="tabler-device-floppy"
          :loading="isLoading"
          :disabled="isLoading"
          @click="handleSubmit"
        >
          {{ isEditing ? t('ceo_message.update_button') : t('ceo_message.save_button') }}
        </VBtn>
      </div>
    </div>

    <VRow>

      <VCol cols="12" lg="7">
        <VCard>
          <VCardItem class="pb-2">
            <VCardTitle>{{ t('ceo_message.message_content.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('ceo_message.message_content.subtitle') }}</VCardSubtitle>
          </VCardItem>
          <VDivider />
          <VCardText class="pt-4">
            <div class="editor-wrap">
              <ProductDescriptionEditor
                v-model="message"
                :placeholder="t('ceo_message.message_content.placeholder')"
              />
            </div>
            <p class="text-caption text-medium-emphasis mt-2 mb-0">
              {{ t('ceo_message.message_content.hint') }}
            </p>
          </VCardText>
        </VCard>
      </VCol>

      <VCol cols="12" lg="5">
        <VCard class="h-100">
          <VCardItem class="pb-2">
            <VCardTitle>{{ t('ceo_message.media.title') }}</VCardTitle>
            <VCardSubtitle>{{ t('ceo_message.media.subtitle') }}</VCardSubtitle>
          </VCardItem>
          <VDivider />
          <VCardText class="pt-4 d-flex flex-column gap-5">

            <div>
              <div class="d-flex align-center justify-space-between mb-3">
                <div class="d-flex align-center gap-2">
                  <VIcon icon="tabler-photo" size="18" color="primary" />
                  <span class="text-body-2 font-weight-medium">{{ t('ceo_message.photo.label') }}</span>
                  <span class="text-caption text-disabled">{{ t('ceo_message.photo.formats', { size: IMAGE_MAX_MB }) }}</span>
                </div>
                <VBtn
                  v-if="imagePreview"
                  size="x-small"
                  variant="text"
                  color="error"
                  prepend-icon="tabler-trash"
                  @click="removeImage"
                >
                  {{ t('ceo_message.photo.remove') }}
                </VBtn>
              </div>

              <div v-if="imagePreview" class="media-preview">
                <img :src="imagePreview" alt="CEO image" class="media-preview__asset" />
                <div class="media-preview__overlay">
                  <VChip
                    size="x-small"
                    :color="hasExistingImg && !imageFile ? 'success' : 'primary'"
                    variant="elevated"
                    class="media-preview__badge"
                  >
                    <VIcon :icon="hasExistingImg && !imageFile ? 'tabler-database' : 'tabler-check'" size="12" class="me-1" />
                    {{ hasExistingImg && !imageFile ? t('ceo_message.photo.saved') : t('ceo_message.photo.new') }}
                  </VChip>
                  <label class="media-preview__change-btn">
                    <VBtn size="x-small" variant="elevated" color="white" style="color:#333">
                      <VIcon icon="tabler-refresh" size="14" class="me-1" />
                      {{ t('ceo_message.photo.change') }}
                    </VBtn>
                    <input type="file" :accept="IMAGE_ACCEPT" class="sr-only" @change="onImageInput" />
                  </label>
                </div>
              </div>

              <label
                v-else
                class="drop-zone"
                :class="{ 'drop-zone--active': imageDragging }"
                @dragover.prevent="imageDragging = true"
                @dragleave.prevent="imageDragging = false"
                @drop.prevent="onImageDrop"
              >
                <VIcon icon="tabler-cloud-upload" size="40" color="primary" class="mb-3 opacity-50" />
                <p class="text-body-2 text-medium-emphasis mb-1">
                  {{ t('ceo_message.photo.drop_text') }}
                  <span class="text-primary font-weight-semibold">{{ t('ceo_message.photo.browse') }}</span>
                </p>
                <p class="text-caption text-disabled mb-0">{{ t('ceo_message.photo.ratio_hint') }}</p>
                <input type="file" :accept="IMAGE_ACCEPT" class="sr-only" @change="onImageInput" />
              </label>
            </div>

            <VDivider>
              <span class="text-caption text-medium-emphasis px-2">{{ t('ceo_message.or_divider') }}</span>
            </VDivider>

            <div>
              <div class="d-flex align-center justify-space-between mb-3">
                <div class="d-flex align-center gap-2">
                  <VIcon icon="tabler-video" size="18" color="secondary" />
                  <span class="text-body-2 font-weight-medium">{{ t('ceo_message.video.label') }}</span>
                  <span class="text-caption text-disabled">{{ t('ceo_message.video.formats', { size: VIDEO_MAX_MB }) }}</span>
                </div>
                <VBtn
                  v-if="videoPreview"
                  size="x-small"
                  variant="text"
                  color="error"
                  prepend-icon="tabler-trash"
                  @click="removeVideo"
                >
                  {{ t('ceo_message.video.remove') }}
                </VBtn>
              </div>

              <div v-if="videoPreview" class="media-preview">
                <video :src="videoPreview" class="media-preview__asset media-preview__asset--contain" controls playsinline />
                <div class="media-preview__overlay media-preview__overlay--top">
                  <VChip
                    size="x-small"
                    :color="hasExistingVid && !videoFile ? 'success' : 'secondary'"
                    variant="elevated"
                    class="media-preview__badge"
                  >
                    <VIcon :icon="hasExistingVid && !videoFile ? 'tabler-database' : 'tabler-check'" size="12" class="me-1" />
                    {{ hasExistingVid && !videoFile ? t('ceo_message.video.saved') : t('ceo_message.video.new') }}
                  </VChip>
                  <label class="media-preview__change-btn">
                    <VBtn size="x-small" variant="elevated" color="white" style="color:#333">
                      <VIcon icon="tabler-refresh" size="14" class="me-1" />
                      {{ t('ceo_message.video.change') }}
                    </VBtn>
                    <input type="file" accept="video/*" class="sr-only" @change="onVideoInput" />
                  </label>
                </div>
              </div>

              <label
                v-else
                class="drop-zone"
                :class="{ 'drop-zone--active': videoDragging }"
                @dragover.prevent="videoDragging = true"
                @dragleave.prevent="videoDragging = false"
                @drop.prevent="onVideoDrop"
              >
                <VIcon icon="tabler-movie" size="40" color="secondary" class="mb-3 opacity-50" />
                <p class="text-body-2 text-medium-emphasis mb-1">
                  {{ t('ceo_message.video.drop_text') }}
                  <span class="text-secondary font-weight-semibold">{{ t('ceo_message.video.browse') }}</span>
                </p>
                <p class="text-caption text-disabled mb-0">{{ t('ceo_message.video.formats_only') }}</p>
                <input type="file" accept="video/*" class="sr-only" @change="onVideoInput" />
              </label>
            </div>

          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VSnackbar
      v-model="store.snackbar"
      :timeout="3500"
      :color="store.snackColor"
      location="bottom right"
    >
      <div class="d-flex align-center gap-2">
        <VIcon
          :icon="store.snackColor === 'success' ? 'tabler-circle-check' : 'tabler-circle-x'"
          size="18"
        />
        {{ store.snackMsg }}
      </div>
    </VSnackbar>

  </VContainer>
</template>

<style scoped>
.editor-wrap {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 8px;
  transition: border-color 0.2s;
}

.editor-wrap:focus-within {
  border-color: rgb(var(--v-theme-primary));
}

:deep(.ProseMirror) {
  min-block-size: 260px;
  padding: 0.75rem 1rem;
  outline: none;
  font-size: 0.95rem;
  overflow-y: auto;
}

.drop-zone {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  width: 100%;
  aspect-ratio: 16 / 9;
  border: 2px dashed rgba(var(--v-border-color), 0.4);
  border-radius: 10px;
  background: rgba(var(--v-theme-on-surface), 0.02);
  cursor: pointer;
  overflow: hidden;
  transition: border-color 0.2s, background 0.2s;
  text-align: center;
  padding: 1.5rem;
}

.drop-zone:hover,
.drop-zone--active {
  border-color: rgb(var(--v-theme-primary));
  background: rgba(var(--v-theme-primary), 0.04);
}

.media-preview {
  position: relative;
  width: 100%;
  aspect-ratio: 16 / 9;
  border-radius: 10px;
  overflow: hidden;
  background: #000;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.media-preview__asset {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.media-preview__asset--contain {
  object-fit: contain;
}

.media-preview__overlay {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  padding: 0.5rem 0.625rem;
  background: linear-gradient(transparent 50%, rgba(0, 0, 0, 0.45) 100%);
}

.media-preview__overlay--top {
  align-items: flex-start;
  background: linear-gradient(rgba(0, 0, 0, 0.35) 0%, transparent 40%);
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
