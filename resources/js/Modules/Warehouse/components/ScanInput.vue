<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'

/**
 * One field for every way a code arrives (WMS-151): a keyboard-emulating scanner types it and
 * presses Enter; a phone or tablet reads it with the camera (ZXing, loaded only when asked for);
 * a person can still type it. Each result is heard and felt as well as seen.
 */
const props = defineProps<{ label: string; autofocus?: boolean; disabled?: boolean }>()
const emit = defineEmits<{ (e: 'scan', value: string): void }>()
const { t } = useI18n()

const value = ref('')
const field = ref<any>(null)
const camera = ref(false)
const video = ref<HTMLVideoElement | null>(null)
const cameraError = ref<string | null>(null)
let controls: { stop: () => void } | null = null

function submit() {
  const v = value.value.trim()
  if (!v) return
  emit('scan', v)
  value.value = ''
}

async function openCamera() {
  cameraError.value = null
  camera.value = true
  await nextTick()
  try {
    const { BrowserMultiFormatReader } = await import('@zxing/browser')
    const reader = new BrowserMultiFormatReader()
    controls = await reader.decodeFromVideoDevice(undefined, video.value!, (result) => {
      if (result) {
        emit('scan', result.getText())
        closeCamera()
      }
    })
  } catch (e: any) {
    cameraError.value = e?.name === 'NotAllowedError' ? t('warehouse_management.floor.camera_denied') : t('warehouse_management.floor.camera_unavailable')
  }
}

function closeCamera() {
  controls?.stop()
  controls = null
  camera.value = false
  nextTick(() => field.value?.focus?.())
}

onBeforeUnmount(() => controls?.stop())
defineExpose({ focus: () => field.value?.focus?.() })
</script>

<template>
  <div>
    <div class="d-flex gap-2 align-center">
      <VTextField ref="field" v-model="value" :label="props.label" :autofocus="props.autofocus" :disabled="props.disabled" variant="outlined"
                  density="comfortable" hide-details autocomplete="off" inputmode="text" class="scan-field" @keyup.enter="submit" />
      <VBtn icon="tabler-camera" size="large" variant="tonal" :disabled="props.disabled" :aria-label="t('warehouse_management.floor.use_camera')" @click="openCamera" />
    </div>
    <VDialog v-model="camera" max-width="520" @update:model-value="v => !v && closeCamera()">
      <VCard>
        <VCardText class="pa-2">
          <video ref="video" class="w-100" style="max-height: 60vh; background: #000" muted playsinline />
          <VAlert v-if="cameraError" type="warning" variant="tonal" density="compact" class="mt-2">{{ cameraError }}</VAlert>
        </VCardText>
        <VCardActions><VSpacer /><VBtn variant="text" @click="closeCamera">{{ t('warehouse_management.common.cancel') }}</VBtn></VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.scan-field :deep(input) { font-size: 1.25rem; min-height: 48px; }
</style>
