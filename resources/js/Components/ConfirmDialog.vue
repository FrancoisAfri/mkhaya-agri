<script lang="ts" setup>
import { ref } from 'vue'

/**
 * App-wide confirmation modal. Use it imperatively via a template ref:
 *
 *   const confirm = ref()
 *   confirm.value.open(
 *     { title: 'Delete lead', message: 'Delete "Jane"? This cannot be undone.', confirmText: 'Delete', confirmColor: 'error' },
 *     () => router.delete(...)
 *   )
 *
 * The onConfirm callback runs when the user confirms; the dialog closes itself.
 */
interface ConfirmOptions {
  title?: string
  message?: string
  confirmText?: string
  cancelText?: string
  confirmColor?: string
  icon?: string
}

const open = ref(false)
const loading = ref(false)
const opts = ref<Required<ConfirmOptions>>({
  title: 'Are you sure?',
  message: 'This action cannot be undone.',
  confirmText: 'Confirm',
  cancelText: 'Cancel',
  confirmColor: 'error',
  icon: 'tabler-alert-triangle',
})
let onConfirm: (() => void | Promise<void>) | null = null

function show(options: ConfirmOptions, cb: () => void | Promise<void>) {
  opts.value = {
    title: options.title ?? 'Are you sure?',
    message: options.message ?? 'This action cannot be undone.',
    confirmText: options.confirmText ?? 'Confirm',
    cancelText: options.cancelText ?? 'Cancel',
    confirmColor: options.confirmColor ?? 'error',
    icon: options.icon ?? 'tabler-alert-triangle',
  }
  onConfirm = cb
  open.value = true
}

async function confirm() {
  if (!onConfirm) { open.value = false; return }
  try {
    loading.value = true
    await onConfirm()
  } finally {
    loading.value = false
    open.value = false
  }
}

function cancel() {
  open.value = false
  onConfirm = null
}

defineExpose({ open: show })
</script>

<template>
  <VDialog v-model="open" max-width="440" persistent>
    <VCard>
      <VCardText class="pa-5 d-flex ga-4">
        <VAvatar :color="opts.confirmColor" variant="tonal" size="44">
          <VIcon :icon="opts.icon" />
        </VAvatar>
        <div>
          <h6 class="text-h6 mb-1">{{ opts.title }}</h6>
          <p class="text-body-2 text-medium-emphasis mb-0">{{ opts.message }}</p>
        </div>
      </VCardText>
      <VDivider />
      <VCardActions class="pa-3">
        <VSpacer />
        <VBtn variant="tonal" :disabled="loading" @click="cancel">{{ opts.cancelText }}</VBtn>
        <VBtn :color="opts.confirmColor" :loading="loading" @click="confirm">{{ opts.confirmText }}</VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
