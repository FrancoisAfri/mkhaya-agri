<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

/**
 * One dropzone for every file this module takes.
 *
 * Each upload point had grown its own file field, or none at all: a `VFileInput` in one dialog, a
 * bare input in another, and in the contractor dialog nothing whatever — so a "document" could be
 * recorded with no file behind it. Somebody who had learned to drag a safety file onto one screen
 * found the next one would not take it.
 *
 * Deliberately dumb: it holds no upload logic and knows nothing about where the files go. The
 * screen owns the form and the posting; this owns the dragging, the listing and the removing.
 */
const props = withDefaults(defineProps<{
  modelValue: File[]
  /** A single file still comes back as a list, so the caller has one shape to handle. */
  multiple?: boolean
  accept?: string
  /** Shown under the prompt — what this particular screen takes. */
  hint?: string
  disabled?: boolean
  errorMessages?: string | string[]
}>(), { multiple: true })

const emit = defineEmits<{ 'update:modelValue': [File[]] }>()

const dragging = ref(false)
const input = ref<HTMLInputElement | null>(null)

const files = computed(() => props.modelValue ?? [])

function add(incoming: FileList | File[] | null) {
  const list = Array.from(incoming ?? [])
  if (!list.length) return
  emit('update:modelValue', props.multiple ? [...files.value, ...list] : [list[0]])
}

function onDrop(event: DragEvent) {
  dragging.value = false
  if (props.disabled) return
  add(event.dataTransfer?.files ?? null)
}

function onPick(event: Event) {
  add((event.target as HTMLInputElement).files)
  // Cleared so picking the same file twice still fires a change.
  if (input.value) input.value.value = ''
}

function remove(index: number) {
  emit('update:modelValue', files.value.filter((_, i) => i !== index))
}

const prettySize = (bytes: number) =>
  bytes < 1024 ? `${bytes} B`
    : bytes < 1048576 ? `${Math.round(bytes / 1024)} KB`
    : `${(bytes / 1048576).toFixed(1)} MB`

const isArchive = (file: File) => /\.zip$/i.test(file.name)
</script>

<template>
  <div>
    <div
      class="filedrop"
      :class="[dragging ? 'filedrop--over' : '', disabled ? 'filedrop--disabled' : '']"
      role="button"
      tabindex="0"
      @click="!disabled && input?.click()"
      @keydown.enter.prevent="!disabled && input?.click()"
      @keydown.space.prevent="!disabled && input?.click()"
      @dragover.prevent="!disabled && (dragging = true)"
      @dragleave.prevent="dragging = false"
      @drop.prevent="onDrop"
    >
      <VIcon icon="mdi-tray-arrow-up" size="26" class="mb-1" />
      <div class="text-body-2">{{ t('sheq.files.drop_here') }}</div>
      <div class="text-caption text-medium-emphasis">
        {{ hint ?? t('sheq.files.drop_hint') }}
      </div>

      <!-- The real input stays in the DOM so keyboard and screen-reader users reach it the
           ordinary way; the styled area above is only a bigger target. -->
      <input
        ref="input"
        type="file"
        class="filedrop__input"
        :multiple="multiple"
        :accept="accept"
        :disabled="disabled"
        @change="onPick"
      >
    </div>

    <VList v-if="files.length" density="compact" class="mt-2">
      <VListItem v-for="(file, index) in files" :key="index"
                 :title="file.name" :subtitle="prettySize(file.size)">
        <template #prepend>
          <VIcon :icon="isArchive(file) ? 'mdi-folder-zip-outline' : 'mdi-file-outline'" size="18" />
        </template>
        <template #append>
          <!-- Said per file rather than once: somebody dropping a mixed selection should see
               which of them is going to be unpacked. -->
          <VChip v-if="isArchive(file)" size="x-small" variant="tonal" class="me-2">
            {{ t('sheq.files.will_unpack') }}
          </VChip>
          <VBtn icon="mdi-close" size="x-small" variant="text" @click.stop="remove(index)" />
        </template>
      </VListItem>
    </VList>

    <div v-if="errorMessages && errorMessages.length" class="text-error text-caption mt-1">
      {{ Array.isArray(errorMessages) ? errorMessages[0] : errorMessages }}
    </div>
  </div>
</template>

<style scoped>
.filedrop {
  position: relative;
  border: 1px dashed rgba(var(--v-theme-on-surface), 0.28);
  border-radius: 8px;
  padding: 18px 16px;
  text-align: center;
  cursor: pointer;
  transition: background-color 120ms, border-color 120ms;
}
.filedrop--over {
  border-color: rgb(var(--v-theme-primary));
  background: rgba(var(--v-theme-primary), 0.06);
}
.filedrop--disabled { opacity: 0.5; cursor: not-allowed; }
.filedrop:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: 2px;
}
/* Covers the zone so a click anywhere opens the picker, without hiding it from assistive tech. */
.filedrop__input {
  position: absolute;
  inset: 0;
  opacity: 0;
  cursor: pointer;
}
.filedrop--disabled .filedrop__input { cursor: not-allowed; }
</style>
