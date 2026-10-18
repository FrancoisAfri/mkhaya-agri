<script setup lang="ts">
import { ref, computed } from 'vue'

const props = defineProps<{
  label: string
  hint?: string
  accept?: string
  maxMb?: number
  preview: string | null
  hasExisting: boolean
  newFile: boolean
  icon?: string
  recommended?: string
  bg?: string
}>()

const emit = defineEmits<{
  (e: 'file', file: File): void
  (e: 'remove'): void
}>()

const dragging = ref(false)
const accepted = computed(() => props.accept ?? '.jpg,.jpeg,.png,.webp,.svg,.ico')
const maxMb = computed(() => props.maxMb ?? 5)
const FORMATS = ['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml', 'image/x-icon', 'image/vnd.microsoft.icon']

function handleFile(file: File) {
  if (!FORMATS.includes(file.type)) {
    alert('Unsupported format. Use JPG, PNG, WEBP, SVG or ICO.')
    return
  }
  if (file.size > maxMb.value * 1024 * 1024) {
    alert(`File too large — max ${maxMb.value} MB`)
    return
  }
  emit('file', file)
}

function onInput(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (file) handleFile(file)
}

function onDrop(e: DragEvent) {
  dragging.value = false
  const file = e.dataTransfer?.files?.[0]
  if (file) handleFile(file)
}
</script>

<template>
  <div class="logo-upload">
    <!-- Header -->
    <div class="logo-upload__header">
      <span class="logo-upload__label">
        <i :class="`fa-solid ${icon ?? 'fa-image'}`" />
        {{ label }}
      </span>
      <button v-if="preview" type="button" class="logo-upload__remove" @click="$emit('remove')">
        <i class="fa-solid fa-xmark" />
      </button>
    </div>

    <!-- Hint -->
    <p v-if="hint" class="logo-upload__hint">{{ hint }}</p>

    <!-- Preview -->
    <div v-if="preview" class="logo-upload__preview" :style="bg ? `background:${bg}` : ''">
      <img :src="preview" :alt="label" class="logo-upload__img" />
      <div class="logo-upload__overlay">
        <label class="logo-upload__change-btn">
          <i class="fa-solid fa-arrow-rotate-right" />
          Change
          <input type="file" :accept="accepted" class="sr-only" @change="onInput" />
        </label>
      </div>
      <div class="logo-upload__badge" :class="hasExisting && !newFile ? 'badge--saved' : 'badge--new'">
        <i :class="hasExisting && !newFile ? 'fa-solid fa-database' : 'fa-solid fa-sparkles'" />
        {{ hasExisting && !newFile ? 'Saved' : 'New' }}
      </div>
    </div>

    <!-- Drop zone -->
    <label
      v-else
      class="logo-upload__dropzone"
      :class="{ 'is-dragging': dragging }"
      @dragover.prevent="dragging = true"
      @dragleave.prevent="dragging = false"
      @drop.prevent="onDrop"
    >
      <div class="logo-upload__dropzone-inner">
        <div class="logo-upload__upload-icon">
          <i :class="`fa-solid ${icon ?? 'fa-cloud-arrow-up'}`" />
        </div>
        <p class="logo-upload__dropzone-text">
          <strong>Drag</strong> or <span class="logo-upload__browse">browse</span>
        </p>
        <p v-if="recommended" class="logo-upload__recommended">{{ recommended }}</p>
      </div>
      <input type="file" :accept="accepted" class="sr-only" @change="onInput" />
    </label>
  </div>
</template>

<style scoped>
.logo-upload { display: flex; flex-direction: column; gap: .4rem; }

.logo-upload__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.logo-upload__label {
  display: flex;
  align-items: center;
  gap: .4rem;
  font-size: .7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: rgba(var(--v-theme-on-surface), .5);
}

.logo-upload__hint {
  margin: 0;
  font-size: .68rem;
  color: rgba(var(--v-theme-on-surface), .35);
  letter-spacing: .02em;
}

.logo-upload__remove {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
  border: none;
  border-radius: 6px;
  background: rgba(239, 68, 68, .1);
  color: #ef4444;
  cursor: pointer;
  font-size: .75rem;
  transition: background .15s;
}
.logo-upload__remove:hover { background: rgba(239, 68, 68, .2); }

/* Preview */
.logo-upload__preview {
  position: relative;
  height: 100px;
  border-radius: 10px;
  overflow: hidden;
  border: 1px solid rgba(var(--v-border-color), .18);
  background: repeating-conic-gradient(rgba(128,128,128,.06) 0% 25%, transparent 0% 50%) 0 0 / 10px 10px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.logo-upload__img {
  max-width: 78%;
  max-height: 78%;
  object-fit: contain;
}

.logo-upload__overlay {
  position: absolute;
  inset: 0;
  background: rgba(0,0,0,.45);
  display: flex;
  align-items: center;
  justify-content: center;
  opacity: 0;
  transition: opacity .2s;
}
.logo-upload__preview:hover .logo-upload__overlay { opacity: 1; }

.logo-upload__change-btn {
  display: flex;
  align-items: center;
  gap: .35rem;
  padding: .4rem .85rem;
  border-radius: 8px;
  background: #fff;
  color: #111;
  font-size: .75rem;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
}

.logo-upload__badge {
  position: absolute;
  bottom: .4rem;
  left: .4rem;
  display: flex;
  align-items: center;
  gap: .3rem;
  padding: 2px 8px;
  border-radius: 20px;
  font-size: .62rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .05em;
}

.badge--saved { background: rgba(16,185,129,.15); color: #10b981; }
.badge--new   { background: rgba(99,102,241,.15); color: #6366f1; }

/* Drop zone */
.logo-upload__dropzone {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100px;
  border: 2px dashed rgba(var(--v-border-color), .3);
  border-radius: 10px;
  background: rgba(var(--v-theme-surface-variant), .2);
  cursor: pointer;
  transition: all .2s;
}
.logo-upload__dropzone:hover,
.logo-upload__dropzone.is-dragging {
  border-color: rgb(var(--v-theme-primary));
  background: rgba(var(--v-theme-primary), .03);
}

.logo-upload__dropzone-inner {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: .3rem;
  pointer-events: none;
  padding: .75rem;
  text-align: center;
}

.logo-upload__upload-icon {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  background: rgba(var(--v-theme-primary), .08);
  color: rgb(var(--v-theme-primary));
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: .9rem;
  margin-bottom: .2rem;
}

.logo-upload__dropzone-text {
  margin: 0;
  font-size: .78rem;
  color: rgba(var(--v-theme-on-surface), .55);
}

.logo-upload__browse {
  color: rgb(var(--v-theme-primary));
  font-weight: 600;
}

.logo-upload__recommended {
  margin: 0;
  font-size: .65rem;
  color: rgba(var(--v-theme-on-surface), .35);
  letter-spacing: .03em;
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0,0,0,0);
  white-space: nowrap;
}
</style>
