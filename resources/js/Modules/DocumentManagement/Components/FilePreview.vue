<script setup lang="ts">
/**
 * The document, on the page.
 *
 * A reseller pack of images is a thing you look at, and the page offered only a button that opened
 * it in another tab — so deciding whether this was the right file meant leaving the page that
 * tells you anything about it.
 *
 * Images are shown directly. A PDF goes in an iframe, which is the only way a browser will render
 * one without a library, and the server already serves it under a sandboxing Content-Security-
 * Policy. Text is fetched and shown as text rather than handed to the browser to interpret.
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const d = (key: string, a?: Record<string, unknown>) => t('document_management.' + key, a ?? {})

const props = defineProps<{
  src: string
  mime: string | null
  name?: string | null
}>()

const kind = computed(() => {
  const m = (props.mime ?? '').toLowerCase()

  if (m.startsWith('image/')) return 'image'
  if (m === 'application/pdf') return 'pdf'
  if (m.startsWith('text/')) return 'text'

  return 'none'
})

const failed = ref(false)
const text = ref<string | null>(null)
const loadingText = ref(false)

/** Enough to see what it is; the whole file is a download, not a preview. */
const TEXT_LIMIT = 20000

watch(() => [props.src, kind.value], async () => {
  failed.value = false
  text.value = null

  if (kind.value !== 'text') return

  loadingText.value = true

  try {
    const res = await fetch(props.src, { credentials: 'same-origin' })

    if (!res.ok) throw new Error(String(res.status))

    const body = await res.text()

    text.value = body.length > TEXT_LIMIT ? body.slice(0, TEXT_LIMIT) : body
  } catch {
    failed.value = true
  } finally {
    loadingText.value = false
  }
}, { immediate: true })
</script>

<template>
  <div v-if="kind !== 'none'">
    <VAlert v-if="failed" type="warning" variant="tonal" density="compact">
      {{ d('preview_failed') }}
    </VAlert>

    <template v-else-if="kind === 'image'">
      <!-- Sized to the panel rather than the image: a 6000px photograph must not decide how wide
           the page is. -->
      <img
        :src="src" :alt="name ?? ''" loading="lazy"
        style="max-width:100%; max-height:520px; display:block; margin:0 auto; border-radius:6px"
        @error="failed = true"
      >
    </template>

    <iframe
      v-else-if="kind === 'pdf'"
      :src="src" :title="name ?? 'PDF'"
      style="width:100%; height:520px; border:0; border-radius:6px"
    />

    <template v-else-if="kind === 'text'">
      <VProgressLinear v-if="loadingText" indeterminate />
      <pre
        v-else-if="text !== null"
        class="text-caption pa-3"
        style="max-height:420px; overflow:auto; white-space:pre-wrap; word-break:break-word; background:rgba(var(--v-theme-on-surface),0.04); border-radius:6px"
      >{{ text }}</pre>
    </template>
  </div>
</template>
