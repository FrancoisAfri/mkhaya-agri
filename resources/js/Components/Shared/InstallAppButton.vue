<script setup lang="ts">
/**
 * "Install app": put the system on the phone's home screen or the laptop's desktop/dock, so people
 * open it with one tap like any other app instead of finding it in the browser.
 *
 * - Chrome / Edge / Samsung Internet (Android, Windows, macOS, ChromeOS): the browser's own install
 *   prompt. The page catches it early (an inline script in app.blade.php stores it on
 *   window.__mkInstallPrompt), because it fires once, often before this component exists.
 * - iPhone / iPad: Safari has no prompt to offer, so the button opens the two steps (Share → Add to
 *   Home Screen).
 * - Safari on a Mac: likewise, File → Add to Dock.
 * - Firefox and anything else that cannot install: no button.
 * - Already installed (opened from the icon): no button.
 *
 * Shared by every Mkhaya app; copied as is.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

const props = withDefaults(defineProps<{ appName?: string }>(), { appName: 'Mkhaya' })

const promptEvent = ref<any>(null)
const installed = ref(false)
const platform = ref<'ios' | 'mac-safari' | null>(null)
const help = ref(false)

const w = window as any

function refresh() {
  promptEvent.value = w.__mkInstallPrompt ?? null
  installed.value = Boolean(w.__mkInstalled)
    || window.matchMedia?.('(display-mode: standalone)').matches
    || window.matchMedia?.('(display-mode: window-controls-overlay)').matches
    || (navigator as any).standalone === true
}

function detectPlatform() {
  const ua = navigator.userAgent
  const iPadOS = navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1
  if (/iphone|ipad|ipod/i.test(ua) || iPadOS) return 'ios'
  const safari = /^((?!chrome|chromium|crios|fxios|edg|opr|android).)*safari/i.test(ua)
  if (safari && /macintosh/i.test(ua)) return 'mac-safari'
  return null
}

const show = computed(() => !installed.value && (promptEvent.value !== null || platform.value !== null))

async function install() {
  if (promptEvent.value) {
    const event = promptEvent.value
    event.prompt()
    try {
      const choice = await event.userChoice
      if (choice?.outcome === 'accepted') installed.value = true
    } catch { /* the browser withdrew it */ }
    // A prompt can be used once.
    w.__mkInstallPrompt = null
    promptEvent.value = null
    return
  }
  help.value = true
}

onMounted(() => {
  platform.value = detectPlatform()
  refresh()
  window.addEventListener('mk-installable', refresh)
  window.addEventListener('mk-installed', refresh)
})
onBeforeUnmount(() => {
  window.removeEventListener('mk-installable', refresh)
  window.removeEventListener('mk-installed', refresh)
})
</script>

<template>
  <VBtn
    v-if="show"
    color="primary"
    variant="tonal"
    size="small"
    class="me-2 install-app-btn"
    :title="`Install ${props.appName} on this device`"
    @click="install"
  >
    <VIcon icon="mdi-cellphone-arrow-down" size="18" />
    <span class="d-none d-sm-inline ms-1">Install app</span>
  </VBtn>

  <VDialog v-model="help" max-width="420">
    <VCard class="pa-2">
      <VCardTitle class="text-h6">Install {{ props.appName }}</VCardTitle>
      <VCardText>
        <template v-if="platform === 'ios'">
          <p class="mb-3">Put {{ props.appName }} on your home screen, then open it with one tap like any other app.</p>
          <ol class="ps-4">
            <li class="mb-2">Tap the <strong>Share</strong> button <VIcon icon="mdi-export-variant" size="18" /> at the bottom (top on iPad) of Safari.</li>
            <li class="mb-2">Scroll down and tap <strong>Add to Home Screen</strong> <VIcon icon="mdi-plus-box-outline" size="18" />.</li>
            <li>Tap <strong>Add</strong>. The {{ props.appName }} icon is now on your home screen.</li>
          </ol>
          <p class="text-caption text-medium-emphasis mt-3 mb-0">On iPhone this works from Safari. In Chrome, use Share → Add to Home Screen too.</p>
        </template>
        <template v-else>
          <p class="mb-3">Put {{ props.appName }} in your Dock, then open it like any other app.</p>
          <ol class="ps-4">
            <li class="mb-2">In Safari's menu bar, open <strong>File</strong>.</li>
            <li class="mb-2">Choose <strong>Add to Dock…</strong></li>
            <li>Click <strong>Add</strong>. {{ props.appName }} now opens from the Dock in its own window.</li>
          </ol>
        </template>
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn color="primary" variant="flat" @click="help = false">Got it</VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
